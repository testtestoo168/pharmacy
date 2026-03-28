<?php
require_once 'includes/config.php';
$pageTitle = t('purchases.new_invoice');
require_once 'includes/config.php';
requireLogin();
$tid = getTenantId();
$bid = getBranchId();

// API: بحث منتج
if (isset($_GET['api']) && $_GET['api'] === 'search_product') {
    header('Content-Type: application/json; charset=utf-8');
    $q = $_GET['q'] ?? '';
    $stmt = $pdo->prepare("SELECT id, barcode, name, generic_name, unit_price, cost_price, unit, stock_qty FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active=1 AND (barcode=? OR name LIKE ? OR generic_name LIKE ?) ORDER BY name LIMIT 20");
    $stmt->execute([$q, "%$q%", "%$q%"]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE); exit;
}

require_once 'includes/header.php';
requirePermission('purchases_add');

$suppliers = $pdo->query("SELECT * FROM suppliers WHERE tenant_id = $tid AND is_active=1 ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT id,barcode,name,generic_name,unit_price,cost_price,unit FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active=1 ORDER BY name")->fetchAll();

// تحميل أمر شراء لتحويله لفاتورة
$loadPO = null; $poItems = [];
if (isset($_GET['from_po']) && intval($_GET['from_po'])) {
    $poId = intval($_GET['from_po']);
    $loadPO = $pdo->prepare("SELECT po.*, s.name as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id WHERE po.tenant_id = $tid AND po.id = ?");
    $loadPO->execute([$poId]); $loadPO = $loadPO->fetch();
    if ($loadPO) {
        $poItemsStmt = $pdo->prepare("SELECT poi.*, p.name as product_name, p.barcode, p.unit_price as selling_price FROM purchase_order_items poi LEFT JOIN products p ON p.id = poi.product_id WHERE poi.order_id = ?");
        $poItemsStmt->execute([$poId]); $poItems = $poItemsStmt->fetchAll();
    }
}

// API: بحث منتج (تكرار للتأكد)
if (isset($_GET['api']) && $_GET['api'] === 'search_product') {
    header('Content-Type: application/json; charset=utf-8');
    $q = $_GET['q'] ?? '';
    $stmt = $pdo->prepare("SELECT id, barcode, name, generic_name, unit_price, cost_price, unit, stock_qty FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active=1 AND (barcode=? OR name LIKE ? OR generic_name LIKE ?) ORDER BY name LIMIT 20");
    $stmt->execute([$q, "%$q%", "%$q%"]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken();
        $pdo->beginTransaction();
        
        $invoiceNumber = generateNumber($pdo, 'purchase_invoices', 'invoice_number', 'P');
        $supplierId = $_POST['supplier_id'] ?: null;
        $branchId = getCurrentBranch();
        
        if (!$supplierId && !empty($_POST['new_supplier_name'])) {
            $stmt = $pdo->prepare("INSERT INTO suppliers (tenant_id,name, tax_number, address, phone) VALUES (?,?, ?, ?, ?)");
            $stmt->execute([$tid,$_POST['new_supplier_name'], $_POST['new_supplier_tax'] ?? '', $_POST['new_supplier_address'] ?? '', $_POST['new_supplier_phone'] ?? '']);
            $supplierId = $pdo->lastInsertId();
        }
        
        $subtotal = 0; $totalVat = 0; $items = [];
        $productIds = $_POST['product_id'] ?? [];
        $productNames = $_POST['product_name'] ?? [];
        $qtys = $_POST['quantity'] ?? [];
        $prices = $_POST['unit_price'] ?? [];
        $sellingPrices = $_POST['selling_price'] ?? [];
        $batchNumbers = $_POST['batch_number'] ?? [];
        $expiryDates = $_POST['expiry_date'] ?? [];
        $mfgDates = $_POST['manufacturing_date'] ?? [];
        
        for ($i = 0; $i < count($productNames); $i++) {
            if (empty($productNames[$i])) continue;
            $qty = intval($qtys[$i]); $price = floatval($prices[$i]);
            $net = $qty * $price; $vat = $net * 0.15; $total = $net + $vat;
            $subtotal += $net; $totalVat += $vat;
            $items[] = [
                'product_id' => intval($productIds[$i] ?? 0),
                'name' => $productNames[$i], 'qty' => $qty, 'price' => $price,
                'selling_price' => floatval($sellingPrices[$i] ?? 0),
                'net' => $net, 'vat' => $vat, 'total' => $total,
                'batch_number' => $batchNumbers[$i] ?? '',
                'expiry_date' => $expiryDates[$i] ?? null,
                'mfg_date' => $mfgDates[$i] ?? null,
            ];
        }
        
        $discount = floatval($_POST['discount'] ?? 0);
        $netTotal = $subtotal - $discount;
        $vatAmount = $netTotal * 0.15;
        $grandTotal = $netTotal + $vatAmount;
        $paymentType = $_POST['payment_type'];
        $paidAmount = ($paymentType === 'credit') ? floatval($_POST['paid_amount'] ?? 0) : $grandTotal;
        $remainingAmount = max(0, $grandTotal - $paidAmount);
        $paymentStatus = ($remainingAmount <= 0) ? 'paid' : (($paidAmount > 0) ? 'partial' : 'unpaid');
        
        $stmt = $pdo->prepare("INSERT INTO purchase_invoices (tenant_id,invoice_number, supplier_id, supplier_invoice_no, branch_id, invoice_date, due_date, subtotal, discount, net_total, vat_amount, grand_total, payment_type, paid_amount, remaining_amount, payment_status, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$tid,$invoiceNumber, $supplierId, $_POST['supplier_invoice_no'] ?? '', $branchId, $_POST['invoice_date'], $_POST['due_date'] ?? null, $subtotal, $discount, $netTotal, $vatAmount, $grandTotal, $paymentType, $paidAmount, $remainingAmount, $paymentStatus, 'approved', $_POST['notes'] ?? '', $_SESSION['user_id']]);
        $invoiceId = $pdo->lastInsertId();
        
        $stmtItem = $pdo->prepare("INSERT INTO purchase_invoice_items (invoice_id, product_id, product_name, quantity, unit_price, net_amount, vat_amount, total_amount, batch_number, expiry_date, manufacturing_date, selling_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        
        foreach ($items as $item) {
            $stmtItem->execute([$invoiceId, $item['product_id'] ?: null, $item['name'], $item['qty'], $item['price'], $item['net'], $item['vat'], $item['total'], $item['batch_number'], $item['expiry_date'] ?: null, $item['mfg_date'] ?: null, $item['selling_price']]);
            
            if ($item['product_id']) {
                // تحديث متوسط التكلفة
                updateAvgCost($pdo, $item['product_id'], $item['qty'], $item['price']);
                
                // إنشاء batch
                $batchStmt = $pdo->prepare("INSERT INTO inventory_batches (tenant_id,product_id, branch_id, batch_number, quantity, available_qty, purchase_price, selling_price, manufacturing_date, expiry_date, received_date, purchase_invoice_id) VALUES (?,?,?,?,?,?,?,?,?,?,CURDATE(),?)");
                $batchStmt->execute([$tid,$item['product_id'], $branchId, $item['batch_number'], $item['qty'], $item['qty'], $item['price'], $item['selling_price'], $item['mfg_date'] ?: null, $item['expiry_date'] ?: null, $invoiceId]);
                $batchId = $pdo->lastInsertId();
                
                // تحديث المخزون
                addStock($pdo, $item['product_id'], $item['qty']);
                
                // تحديث products.sell_price إذا تم إدخاله
                if ($item['selling_price'] > 0) {
                    $pdo->prepare("UPDATE products SET unit_price=? WHERE id=? AND tenant_id = $tid")->execute([$item['selling_price'], $item['product_id']]);
                }
                
                // حركة مخزون
                $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id, batch_id, branch_id, movement_type, quantity, reference_type, reference_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$tid,$item['product_id'], $batchId, $branchId, 'purchase', $item['qty'], 'purchase_invoice', $invoiceId, $_SESSION['user_id']]);
            }
        }
        
        // تحديث رصيد المورد
        if ($supplierId && $remainingAmount > 0) {
            $pdo->prepare("UPDATE suppliers SET balance=balance+?, total_purchases=total_purchases+? WHERE id=? AND tenant_id = $tid")->execute([$remainingAmount, $grandTotal, $supplierId]);
        } elseif ($supplierId) {
            $pdo->prepare("UPDATE suppliers SET total_purchases=total_purchases+? WHERE id=? AND tenant_id = $tid")->execute([$grandTotal, $supplierId]);
        }
        
        // قيد محاسبي
        $invData = ['id'=>$invoiceId,'invoice_number'=>$invoiceNumber,'invoice_date'=>$_POST['invoice_date'],'net_total'=>$netTotal+$vatAmount,'vat_amount'=>$vatAmount,'grand_total'=>$grandTotal,'payment_type'=>$paymentType,'branch_id'=>$branchId];
        createPurchaseJournalEntry($pdo, $invData);
        
        $pdo->commit();
        logActivity($pdo, 'activity.add_purchase', "$invoiceNumber — $grandTotal", 'purchases');
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . ' — ' . $invoiceNumber . '</div>';
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger">' . t('error') . ': ' . $e->getMessage() . '</div>';
    }
}
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-cart-plus"></i><?= t('purchases.new_invoice') ?></h3>
    </div>
    <div class="card-body">
        <form method="POST" id="purchaseForm">
        <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group"><label><?= t('g.invoice_date') ?></label><input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group"><label><?= t('g.expected') ?></label><input type="date" name="due_date" class="form-control"></div>
                <div class="form-group"><label><?= t('g.supplier_invoice') ?></label><input type="text" name="supplier_invoice_no" class="form-control" placeholder="<?= t('sales.invoice_number') ?> من المورد"></div>
                <div class="form-group">
                    <label><?= t('g.payment_type') ?></label>
                    <select name="payment_type" class="form-control" id="paymentType" onchange="toggleCredit()">
                        <option value="cash"><?= t('sales.cash') ?></option><option value="transfer"><?= t('sales.bank_transfer') ?></option><option value="credit"><?= t('sales.credit') ?></option>
                    </select>
                </div>
                <div class="form-group"><label><?= t('discount') ?></label><input type="number" name="discount" class="form-control" value="0" step="0.01" id="discountInput" onchange="calcTotals()"></div>
            </div>
            
            <div id="creditFields" style="display:none;" class="form-row" style="background:#fff8e1;padding:12px;border-radius:8px;border:1px solid #ffe082;margin-bottom:15px;">
                <div class="form-group"><label style="color:#e65100;font-weight:700;"><?= t('sales.paid_amount') ?></label><input type="number" name="paid_amount" class="form-control" value="0" step="0.01" id="paidInput" onchange="calcRemaining()"></div>
                <div class="form-group"><label style="color:#c62828;font-weight:700;"><?= t('remaining') ?></label><input type="text" class="form-control" id="remainingDisplay" readonly style="background:#ffebee;color:#c62828;font-weight:700;"></div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label><?= t('purchases.supplier') ?></label>
                    <select name="supplier_id" class="form-control" id="supplierSelect">
                        <option value=""><?= t('g.select_supplier') ?></option>
                        <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>" <?= ($loadPO && $loadPO['supplier_id'] == $s['id']) ? 'selected' : '' ?>><?= $s['name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label><?= t('purchases.new_supplier') ?></label><input type="text" name="new_supplier_name" class="form-control" placeholder="<?= t('name') ?>"></div>
                <div class="form-group"><label><?= t('settings.tax_number') ?></label><input type="text" name="new_supplier_tax" class="form-control"></div>
            </div>
            
            <div class="inner-card">
                <div class="card-header"><h3><i class="fas fa-list"></i><?= t('g.purchase_items') ?></h3><button type="button" class="btn btn-sm btn-success" onclick="addRow()"><i class="fas fa-plus"></i><?= t('add') ?></button></div>
                <div class="card-body">
                    <div class="table-responsive">
                    <table class="items-table" id="itemsTable">
                        <thead><tr><th>م</th><th style="width:200px;"><?= t('g.product') ?></th><th><?= t('inventory.batch_number') ?></th><th><?= t('inventory.expiry_date') ?></th><th><?= t('quantity') ?></th><th><?= t('g.purchase_price') ?></th><th><?= t('products.sell_price') ?></th><th><?= t('net') ?></th><th><?= t('tax') ?></th><th><?= t('total') ?></th><th></th></tr></thead>
                        <tbody id="itemsBody">
                            <tr>
                                <td>1</td>
                                <td><input type="hidden" name="product_id[]" value="0"><input type="text" name="product_name[]" class="product-search" placeholder="<?= t('products.search_products') ?>" autocomplete="off" required></td>
                                <td><input type="text" name="batch_number[]" placeholder="<?= t('inventory.batch_number') ?>"></td>
                                <td><input type="date" name="expiry_date[]"><input type="hidden" name="manufacturing_date[]"></td>
                                <td><input type="number" name="quantity[]" value="1" min="1" onchange="calcRow(this)" onkeyup="calcRow(this)"></td>
                                <td><input type="number" name="unit_price[]" value="0" step="0.01" onchange="calcRow(this)" onkeyup="calcRow(this)"></td>
                                <td><input type="number" name="selling_price[]" value="0" step="0.01"></td>
                                <td class="row-net">0.00</td><td class="row-vat">0.00</td><td class="row-total">0.00</td>
                                <td><button type="button" class="remove-row" onclick="removeRow(this)">×</button></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            
            <div style="display:flex;justify-content:flex-end;margin-top:15px;">
                <table style="width:300px;border:1px solid var(--border);border-collapse:collapse;">
                    <tr><td style="padding:8px;font-weight:700;background:var(--light);"><?= t('total') ?></td><td style="padding:8px;" id="tSub">0.00</td></tr>
                    <tr><td style="padding:8px;font-weight:700;background:var(--light);"><?= t('discount') ?></td><td style="padding:8px;" id="tDisc">0.00</td></tr>
                    <tr><td style="padding:8px;font-weight:700;background:var(--light);"><?= t('net') ?></td><td style="padding:8px;" id="tNet">0.00</td></tr>
                    <tr><td style="padding:8px;font-weight:700;background:var(--light);"><?= t('tax') ?> 15%</td><td style="padding:8px;" id="tVat">0.00</td></tr>
                    <tr style="background:var(--primary);"><td style="padding:10px;font-weight:800;color:#fff;"><?= t('g.total_with_vat') ?></td><td style="padding:10px;font-weight:800;color:#fff;" id="tGrand">0.00</td></tr>
                </table>
            </div>
            
            <div class="form-group mt-20"><label><?= t('notes') ?></label><textarea name="notes" class="form-control"></textarea></div>
            <button type="submit" class="btn btn-primary btn-lg mt-10"><i class="fas fa-save"></i><?= t('g.save_approve') ?></button>
            <a href="purchases" class="btn btn-secondary btn-lg mt-10"><?= t('cancel') ?></a>
        </form>
    </div>
</div>

<script>
let rc=1;
function addRow(){
    rc++;
    const tr=document.createElement('tr');
    tr.innerHTML=`<td>${rc}</td><td><input type="hidden" name="product_id[]" value="0"><input type="text" name="product_name[]" class="product-search" placeholder="<?= t('search_placeholder') ?>" autocomplete="off" required></td><td><input type="text" name="batch_number[]"></td><td><input type="date" name="expiry_date[]"><input type="hidden" name="manufacturing_date[]"></td><td><input type="number" name="quantity[]" value="1" min="1" onchange="calcRow(this)" onkeyup="calcRow(this)"></td><td><input type="number" name="unit_price[]" value="0" step="0.01" onchange="calcRow(this)" onkeyup="calcRow(this)"></td><td><input type="number" name="selling_price[]" value="0" step="0.01"></td><td class="row-net">0.00</td><td class="row-vat">0.00</td><td class="row-total">0.00</td><td><button type="button" class="remove-row" onclick="removeRow(this)">×</button></td>`;
    document.getElementById('itemsBody').appendChild(tr);
    initSearch(tr.querySelector('.product-search'));
}
function removeRow(b){if(document.getElementById('itemsBody').rows.length>1){b.closest('tr').remove();calcTotals();}}
function calcRow(el){
    const tr=el.closest('tr');
    const q=parseFloat(tr.querySelector('[name="quantity[]"]').value)||0;
    const p=parseFloat(tr.querySelector('[name="unit_price[]"]').value)||0;
    const n=q*p; tr.querySelector('.row-net').textContent=n.toFixed(2);
    tr.querySelector('.row-vat').textContent=(n*0.15).toFixed(2);
    tr.querySelector('.row-total').textContent=(n*1.15).toFixed(2);
    calcTotals();
}
function calcTotals(){
    let sub=0; document.querySelectorAll('.row-net').forEach(e=>sub+=parseFloat(e.textContent)||0);
    const disc=parseFloat(document.getElementById('discountInput').value)||0;
    const net=sub-disc; const vat=net*0.15;
    document.getElementById('tSub').textContent=sub.toFixed(2);
    document.getElementById('tDisc').textContent=disc.toFixed(2);
    document.getElementById('tNet').textContent=net.toFixed(2);
    document.getElementById('tVat').textContent=vat.toFixed(2);
    document.getElementById('tGrand').textContent=(net+vat).toFixed(2);
    if(document.getElementById('paymentType').value==='credit') calcRemaining();
}
function toggleCredit(){
    document.getElementById('creditFields').style.display=document.getElementById('paymentType').value==='credit'?'flex':'none';
}
function calcRemaining(){
    const g=parseFloat(document.getElementById('tGrand').textContent)||0;
    const p=parseFloat(document.getElementById('paidInput').value)||0;
    document.getElementById('remainingDisplay').value=Math.max(0,g-p).toFixed(2)+' ';
}

// بحث المنتجات
function initSearch(input){
    let timer;
    input.addEventListener('input', function(){
        clearTimeout(timer);
        const q=this.value.trim(); if(q.length<1) return;
        timer=setTimeout(()=>{
            fetch('purchases_new.php?api=search_product&q='+encodeURIComponent(q))
                .then(r=>r.json())
                .then(data=>{
                    // إزالة أي قائمة سابقة
                    document.querySelectorAll('.search-dropdown').forEach(d=>d.remove());
                    if(data.length===0) return;
                    const dd=document.createElement('div');
                    dd.className='search-dropdown';
                    dd.style.cssText='position:absolute;background:#fff;border:1px solid #ddd;border-radius:8px;max-height:200px;overflow-y:auto;z-index:100;width:250px;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
                    data.forEach(p=>{
                        const item=document.createElement('div');
                        item.style.cssText='padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:13px;';
                        item.innerHTML=`<strong>${p.name}</strong><br><small style="color:#666;">${p.barcode||''} — مخزون: ${p.stock_qty} — ${parseFloat(p.cost_price).toFixed(2)}</small>`;
                        item.onmouseover=()=>item.style.background='#eff6ff';
                        item.onmouseout=()=>item.style.background='';
                        item.onclick=()=>{
                            const tr=input.closest('tr');
                            tr.querySelector('[name="product_id[]"]').value=p.id;
                            input.value=p.name;
                            tr.querySelector('[name="unit_price[]"]').value=parseFloat(p.cost_price).toFixed(2);
                            tr.querySelector('[name="selling_price[]"]').value=parseFloat(p.unit_price).toFixed(2);
                            dd.remove();
                            calcRow(input);
                        };
                        dd.appendChild(item);
                    });
                    input.parentElement.style.position='relative';
                    input.parentElement.appendChild(dd);
                });
        },300);
    });
}
document.querySelectorAll('.product-search').forEach(initSearch);
document.addEventListener('click',e=>{if(!e.target.closest('.product-search'))document.querySelectorAll('.search-dropdown').forEach(d=>d.remove());});

<?php if ($loadPO && !empty($poItems)): ?>
// تحميل بنود أمر الشراء تلقائياً
window.addEventListener('DOMContentLoaded', function() {
    // مسح الصف الأول الفارغ
    document.getElementById('itemsBody').innerHTML = '';
    rc = 0;
    <?php foreach ($poItems as $i => $poi): ?>
    rc++;
    (function(){
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${rc}</td><td><input type="hidden" name="product_id[]" value="<?= $poi['product_id'] ?>"><input type="text" name="product_name[]" class="product-search" value="<?= addslashes($poi['product_name'] ?? $poi['product_id']) ?>" autocomplete="off" required></td><td><input type="text" name="batch_number[]" value="<?= addslashes($poi['batch_number'] ?? '') ?>"></td><td><input type="date" name="expiry_date[]" value="<?= $poi['expiry_date'] ?? '' ?>"><input type="hidden" name="manufacturing_date[]"></td><td><input type="number" name="quantity[]" value="<?= $poi['quantity'] ?>" min="1" onchange="calcRow(this)" onkeyup="calcRow(this)"></td><td><input type="number" name="unit_price[]" value="<?= number_format($poi['unit_price'],2,'.','') ?>" step="0.01" onchange="calcRow(this)" onkeyup="calcRow(this)"></td><td><input type="number" name="selling_price[]" value="<?= number_format($poi['selling_price'] ?? 0,2,'.','') ?>" step="0.01"></td><td class="row-net">0.00</td><td class="row-vat">0.00</td><td class="row-total">0.00</td><td><button type="button" class="remove-row" onclick="removeRow(this)">×</button></td>`;
        document.getElementById('itemsBody').appendChild(tr);
        initSearch(tr.querySelector('.product-search'));
        calcRow(tr.querySelector('[name="quantity[]"]'));
    })();
    <?php endforeach; ?>
    calcTotals();
});
<?php endif; ?>
</script>
<?php require_once 'includes/footer.php'; ?>
