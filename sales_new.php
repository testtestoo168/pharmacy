<?php
require_once 'includes/config.php';
$pageTitle = t('sales.new_invoice');
require_once 'includes/config.php';
requireLogin();
$tid = getTenantId();
$bid = getBranchId();
$branchId = getBranchId();

// AJAX: بحث المنتجات
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $pdo->prepare("SELECT p.id, p.barcode, p.name, p.generic_name, p.unit, p.unit_price, p.cost_price, p.vat_rate, p.stock_qty, p.requires_prescription,
                           (SELECT COALESCE(SUM(ib.available_qty),0) FROM inventory_batches ib WHERE ib.tenant_id = $tid AND ib.product_id = p.id AND ib.branch_id = ? AND ib.available_qty > 0 AND (ib.expiry_date > CURDATE() OR ib.expiry_date IS NULL)) as available_qty
                           FROM products p WHERE p.tenant_id = $tid AND p.branch_id = $branchId AND p.is_active = 1 AND (p.name LIKE ? OR p.barcode LIKE ? OR p.generic_name LIKE ?) ORDER BY p.name LIMIT 20");
    $stmt->execute([$branchId, $q, $q, $q]);
    echo json_encode($stmt->fetchAll());
    exit;
}

require_once 'includes/header.php';
requirePermission('sales_add');

$company = getCompanySettings($pdo);
$customers = $pdo->query("SELECT * FROM customers WHERE tenant_id = $tid ORDER BY name")->fetchAll();

// معالجة حفظ الفاتورة
$successMsg = ''; $errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sale'])) {
    try {
        verifyCsrfToken();
        $pdo->beginTransaction();
        $invoiceNumber = generateNumber($pdo, 'sales_invoices', 'invoice_number', 'INV-');
        $invoiceDate = $_POST['invoice_date'] ?? date('Y-m-d');
        $hijriDate = gregorianToHijri($invoiceDate);
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $paymentType = $_POST['payment_type'] ?? 'cash';
        $notes = trim($_POST['notes'] ?? '');

        // عميل جديد
        if (!$customerId && !empty($_POST['new_customer_name'])) {
            $pdo->prepare("INSERT INTO customers (tenant_id,name, tax_number, phone, address, type) VALUES (?,?,?,?,?,?)")
                ->execute([$tid,trim($_POST['new_customer_name']), $_POST['new_customer_tax']??'', $_POST['new_customer_phone']??'', $_POST['new_customer_address']??'', $_POST['new_customer_type']??'individual']);
            $customerId = $pdo->lastInsertId();
        }

        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];
        $itemDiscounts = $_POST['item_discount'] ?? [];
        $vatRates = $_POST['vat_rate'] ?? [];

        $subtotal = 0; $totalVat = 0; $totalCost = 0; $totalDiscount = 0;
        $itemsData = [];

        for ($i = 0; $i < count($productIds); $i++) {
            $pid = intval($productIds[$i] ?? 0); if (!$pid) continue;
            $qty = intval($quantities[$i] ?? 0); if ($qty <= 0) continue;
            $price = floatval($unitPrices[$i] ?? 0);
            $disc = floatval($itemDiscounts[$i] ?? 0);
            $vatRate = floatval($vatRates[$i] ?? 15);

            $product = $pdo->prepare("SELECT * FROM products WHERE tenant_id = $tid AND branch_id = $branchId AND id = ?"); $product->execute([$pid]); $product = $product->fetch();
            if (!$product) continue;

            // التحقق من المتاح (باتشات غير منتهية)
            $availQty = $pdo->prepare("SELECT COALESCE(SUM(available_qty),0) FROM inventory_batches WHERE tenant_id = $tid AND product_id = ? AND branch_id = ? AND available_qty > 0 AND (expiry_date > CURDATE() OR expiry_date IS NULL)");
            $availQty->execute([$pid, $branchId]);
            $available = intval($availQty->fetchColumn());
            if ($available < $qty) throw new Exception(t('pos.qty_unavailable') . " {$product['name']} ($available)");

            $lineGross = $qty * $price;
            $lineNet = $lineGross - $disc;
            $lineVat = round($lineNet * $vatRate / (100 + $vatRate), 2);
            $subtotal += $lineNet;
            $totalVat += $lineVat;
            $totalDiscount += $disc;

            // FEFO خصم
            $batches = $pdo->prepare("SELECT id, available_qty, purchase_price FROM inventory_batches WHERE tenant_id = $tid AND product_id = ? AND branch_id = ? AND available_qty > 0 AND (expiry_date > CURDATE() OR expiry_date IS NULL) ORDER BY expiry_date ASC");
            $batches->execute([$pid, $branchId]);
            $remaining = $qty; $deducted = [];
            while ($remaining > 0 && ($batch = $batches->fetch())) {
                $deduct = min($remaining, $batch['available_qty']);
                $pdo->prepare("UPDATE inventory_batches SET available_qty = available_qty - ? WHERE id = ? AND tenant_id = $tid")->execute([$deduct, $batch['id']]);
                $deducted[] = ['batch_id' => $batch['id'], 'qty' => $deduct, 'cost' => $batch['purchase_price']];
                $remaining -= $deduct;
            }
            $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND tenant_id = $tid AND branch_id = $branchId")->execute([$qty, $pid]);

            $batchId = $deducted[0]['batch_id'] ?? null;
            $costPrice = !empty($deducted) ? array_sum(array_map(fn($d) => $d['cost'] * $d['qty'], $deducted)) / $qty : $product['cost_price'];
            $totalCost += $costPrice * $qty;

            $itemsData[] = ['product_id'=>$pid, 'batch_id'=>$batchId, 'product_name'=>$product['name'], 'barcode'=>$product['barcode']??'',
                           'quantity'=>$qty, 'unit_price'=>$price, 'cost_price'=>round($costPrice,2), 'discount'=>$disc,
                           'net_amount'=>$lineNet, 'vat_amount'=>$lineVat, 'total_amount'=>$lineNet];

            // حركة مخزون
            foreach ($deducted as $d) {
                $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id, batch_id, branch_id, movement_type, quantity, reference_type, notes, created_by) VALUES (?,?,?,?,'sale',?,?,?,?)")
                    ->execute([$tid,$pid, $d['batch_id'], $branchId, $d['qty'], 'sale_invoice', t('perms.sales'), $_SESSION['user_id']]);
            }
        }

        if (empty($itemsData)) throw new Exception(t('validation.add_one_item'));

        $invoiceDiscount = floatval($_POST['invoice_discount'] ?? 0);
        $totalDiscount += $invoiceDiscount;
        $netTotal = $subtotal - $invoiceDiscount;
        $grandTotal = $netTotal;
        $paidAmount = floatval($_POST['paid_amount'] ?? 0);
        if ($paymentType !== 'credit') $paidAmount = $grandTotal;
        $remaining = max(0, $grandTotal - $paidAmount);

        $pdo->prepare("INSERT INTO sales_invoices (tenant_id, invoice_number, customer_id, branch_id, invoice_date, hijri_date, subtotal, discount, net_total, vat_amount, grand_total, payment_type, paid_amount, remaining_amount, notes, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'completed',?)")
            ->execute([$tid, $invoiceNumber, $customerId, $branchId, $invoiceDate, $hijriDate, $subtotal, $totalDiscount, $netTotal, $totalVat, $grandTotal, $paymentType, $paidAmount, $remaining, $notes, $_SESSION['user_id']]);
        $invoiceId = $pdo->lastInsertId();

        foreach ($itemsData as $d) {
            $pdo->prepare("INSERT INTO sales_invoice_items (invoice_id, product_id, batch_id, product_name, barcode, quantity, unit_price, cost_price, discount_amount, net_amount, vat_amount, total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$invoiceId, $d['product_id'], $d['batch_id'], $d['product_name'], $d['barcode'], $d['quantity'], $d['unit_price'], $d['cost_price'], $d['discount'], $d['net_amount'], $d['vat_amount'], $d['total_amount']]);
        }

        // نقاط ولاء
        if ($customerId) {
            $points = floor($grandTotal);
            $pdo->prepare("UPDATE customers SET total_purchases = total_purchases + ?, loyalty_points = loyalty_points + ? WHERE id = ? AND tenant_id = $tid")->execute([$grandTotal, $points, $customerId]);
        }

        // رصيد العميل آجل
        if ($remaining > 0 && $customerId) {
            $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ? AND tenant_id = $tid")->execute([$remaining, $customerId]);
        }

        // القيد المحاسبي
        createSaleJournalEntry($pdo, [
            'id'=>$invoiceId, 'invoice_number'=>$invoiceNumber, 'invoice_date'=>$invoiceDate,
            'grand_total'=>$grandTotal, 'vat_amount'=>$totalVat, 'total_cost'=>$totalCost, 'payment_type'=>$paymentType
        ]);
        // E-invoice metadata
        $meta = getInvoiceMetadata(['invoice_number'=>$invoiceNumber,'invoice_date'=>$invoiceDate,'grand_total'=>$grandTotal,'vat_amount'=>$totalVat], $company);
        $pdo->prepare("UPDATE sales_invoices SET uuid=?,invoice_hash=?,qr_code=?,total_cost=? WHERE id=? AND tenant_id = $tid")->execute([$meta['uuid'],$meta['hash'],$meta['qr'],$totalCost,$invoiceId]);
        
        $pdo->commit();
        logActivity($pdo, 'activity.add_sale', $invoiceNumber, 'sales');

        // ===== ZATCA Phase 2: إرسال تلقائي لهيئة الزكاة =====
        $zatcaMsg = '';
        try {
            $zatca = new ZATCAIntegrationService($pdo, $tid, $branchId);
            if ($zatca->isEnabled()) {
                $zr = $zatca->submitSalesInvoice($invoiceId);
                if ($zr['success']) {
                    $zatcaMsg = ' <span style="color:#16a34a"><i class="fas fa-check-circle"></i> ' . t('zatca.sent_success') . '</span>';
                } else {
                    $zatcaMsg = ' <span style="color:#f59e0b"><i class="fas fa-exclamation-triangle"></i> ZATCA: ' . htmlspecialchars($zr['error'] ?? t('error')) . '</span>';
                }
            }
        } catch (Exception $ze) {
            $zatcaMsg = ' <span style="color:#dc2626"><i class="fas fa-times-circle"></i> ZATCA: ' . htmlspecialchars($ze->getMessage()) . '</span>';
        }

        $successMsg = "تم حفظ الفاتورة بنجاح - رقم: <strong>$invoiceNumber</strong>{$zatcaMsg} <a href='sales_print?id=$invoiceId' target='_blank' class='btn btn-sm btn-info' style='margin-right:10px;'><i class='fas fa-print'></i>' . t('print') . '</a>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) if($pdo->inTransaction())$pdo->rollBack();
        $errorMsg = $e->getMessage();
    }
}
?>

<?php if ($successMsg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $successMsg ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $errorMsg ?></div><?php endif; ?>

<form method="POST" id="saleForm">
<?= csrfField() ?>
<input type="hidden" name="save_sale" value="1">

<div class="card">
    <div class="card-header"><h3><i class="fas fa-file-invoice-dollar"></i><?= t('sales.new_invoice') ?></h3></div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group"><label><?= t('g.invoice_date') ?></label><input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group">
                <label><?= t('sales.payment_method') ?></label>
                <select name="payment_type" id="payType" class="form-control" onchange="toggleCredit()">
                    <option value="cash"><?= t('sales.cash') ?></option><option value="card"><?= t('sales.card') ?></option><option value="transfer"><?= t('sales.bank_transfer') ?></option><option value="credit"><?= t('sales.credit') ?></option>
                </select>
            </div>
            <div class="form-group" style="flex:2;">
                <label><?= t('sales.customer') ?></label>
                <select name="customer_id" id="custSelect" class="form-control">
                    <option value=""><?= t('sales.cash_customer') ?></option>
                    <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= $c['name'] ?></option><?php endforeach; ?>
                    <option value="new">+ <?= t('customers.add_customer') ?></option>
                </select>
            </div>
        </div>
        <div id="newCustFields" style="display:none;">
            <div class="form-row" style="background:#f0fdf4;padding:10px;border-radius:8px;border:1px solid #86efac;margin-bottom:10px;">
                <div class="form-group"><label><?= t('name') ?></label><input type="text" name="new_customer_name" class="form-control"></div>
                <div class="form-group"><label><?= t('settings.tax_number') ?></label><input type="text" name="new_customer_tax" class="form-control"></div>
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="new_customer_phone" class="form-control"></div>
            </div>
        </div>
        <div id="creditFields" style="display:none;">
            <div class="form-row" style="background:#fff8e1;padding:10px;border-radius:8px;border:1px solid #ffe082;margin-bottom:10px;">
                <div class="form-group"><label style="color:#e65100;font-weight:700;"><?= t('sales.paid_amount') ?></label><input type="number" name="paid_amount" id="paidAmt" class="form-control" value="0" step="0.01" oninput="recalc()"></div>
                <div class="form-group"><label style="color:#c62828;font-weight:700;"><?= t('remaining') ?></label><input type="text" id="remDisp" class="form-control" readonly style="background:#ffebee;color:#c62828;font-weight:700;"></div>
            </div>
        </div>
    </div>
</div>

<!-- البنود -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-pills"></i><?= t('g.invoice_items') ?></h3></div>
    <div class="card-body">
        <div style="position:relative;margin-bottom:12px;">
            <input type="text" id="prodSearch" class="form-control" placeholder="<?= t('products.search_products') ?>" autocomplete="off" onkeyup="searchProds(this.value)" style="padding-right:40px;font-size:15px;">
            <div id="searchRes" style="display:none;position:absolute;top:100%;right:0;left:0;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,0.15);z-index:100;max-height:280px;overflow-y:auto;"></div>
        </div>
        <div class="table-responsive">
            <table><thead><tr><th>م</th><th><?= t('g.product') ?></th><th><?= t('quantity') ?></th><th><?= t('price') ?></th><th><?= t('discount') ?></th><th><?= t('tax') ?>%</th><th><?= t('total') ?></th><th></th></tr></thead>
            <tbody id="itemsBody"></tbody></table>
            <div id="emptyMsg" style="text-align:center;padding:35px;color:#94a3b8;"><i class="fas fa-box-open" style="font-size:35px;margin-bottom:8px;display:block;"></i><?= t('g.search_product') ?></div>
        </div>
    </div>
</div>

<!-- الإجماليات -->
<div class="card">
    <div class="card-body" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:15px;">
        <div style="flex:1;min-width:200px;">
            <div class="form-group"><label><?= t('g.total_discount') ?></label><input type="number" name="invoice_discount" id="invDisc" class="form-control" value="0" step="0.01" oninput="recalc()"></div>
            <div class="form-group"><label><?= t('notes') ?></label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <div style="min-width:280px;">
            <table style="width:100%;border:2px solid #e2e8f0;border-collapse:collapse;border-radius:8px;overflow:hidden;">
                <tr><td style="padding:8px;font-weight:700;background:#f8fafc;"><?= t('subtotal') ?></td><td style="padding:8px;" id="dSub">0.00</td></tr>
                <tr><td style="padding:8px;font-weight:700;background:#f8fafc;"><?= t('discount') ?></td><td style="padding:8px;color:#dc2626;" id="dDisc">0.00</td></tr>
                <tr><td style="padding:8px;font-weight:700;background:#f8fafc;"><?= t('tax') ?></td><td style="padding:8px;" id="dVat">0.00</td></tr>
                <tr style="background:#0f3460;color:#fff;"><td style="padding:10px;font-weight:800;"><?= t('total') ?></td><td style="padding:10px;font-weight:800;font-size:17px;" id="dGrand">0.00 <span style="font-size:12px;font-weight:600;"><?= t('sar') ?></span></td></tr>
            </table>
        </div>
    </div>
</div>

<div style="display:flex;gap:8px;margin-bottom:25px;">
    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i><?= t('g.save_invoice') ?></button>
    <a href="sales" class="btn btn-secondary btn-lg"><i class="fas fa-times"></i><?= t('cancel') ?></a>
</div>
</form>

<script>
let ic=0,sTimeout;
function searchProds(q){clearTimeout(sTimeout);const d=document.getElementById('searchRes');if(q.length<1){d.style.display='none';return;}
sTimeout=setTimeout(()=>{fetch('sales_new.php?ajax=search&q='+encodeURIComponent(q)).then(r=>r.json()).then(ps=>{if(!ps.length){d.innerHTML='<div style="padding:12px;text-align:center;color:#94a3b8;"><?= t('no_results') ?></div>';}else{d.innerHTML=ps.map(p=>`<div onclick='addProd(${JSON.stringify(p)})' style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;" onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''"><div><strong>${p.name}</strong>${p.barcode?' <small style="color:#94a3b8;">'+p.barcode+'</small>':''}</div><div style="text-align:left;font-size:12px;"><strong>${parseFloat(p.unit_price).toFixed(2)}</strong> | <span style="color:${p.available_qty>0?'#16a34a':'#dc2626'}">متاح: ${p.available_qty}</span></div></div>`).join('');}d.style.display='block';});},250);}

function addProd(p){document.getElementById('searchRes').style.display='none';document.getElementById('prodSearch').value='';document.getElementById('emptyMsg').style.display='none';
ic++;const tr=document.createElement('tr');tr.id='r_'+ic;
tr.innerHTML=`<td>${ic}</td><td><strong>${p.name}</strong>${p.barcode?'<br><small style="color:#94a3b8;">'+p.barcode+'</small>':''}<input type="hidden" name="product_id[]" value="${p.id}"></td><td><input type="number" name="quantity[]" value="1" min="1" max="${p.available_qty}" class="form-control" style="width:70px;" oninput="cR(this)"></td><td><input type="number" name="unit_price[]" value="${parseFloat(p.unit_price).toFixed(2)}" step="0.01" class="form-control" style="width:90px;" oninput="cR(this)"></td><td><input type="number" name="item_discount[]" value="0" step="0.01" class="form-control" style="width:70px;" oninput="cR(this)"></td><td><input type="number" name="vat_rate[]" value="${p.vat_rate||15}" step="0.01" class="form-control" style="width:55px;" oninput="cR(this)"></td><td class="rt" style="font-weight:700;">0.00</td><td><button type="button" class="btn btn-sm btn-danger" onclick="document.getElementById('r_${ic}').remove();recalc();"><i class="fas fa-trash"></i></button></td>`;
document.getElementById('itemsBody').appendChild(tr);cR(tr.querySelector('[name="quantity[]"]'));}

function cR(el){const tr=el.closest('tr');const q=parseFloat(tr.querySelector('[name="quantity[]"]').value)||0;const p=parseFloat(tr.querySelector('[name="unit_price[]"]').value)||0;const d=parseFloat(tr.querySelector('[name="item_discount[]"]').value)||0;const v=parseFloat(tr.querySelector('[name="vat_rate[]"]').value)||0;const net=(q*p)-d;tr.querySelector('.rt').textContent=net.toFixed(2);recalc();}

function recalc(){let sub=0,disc=0,vat=0;document.querySelectorAll('#itemsBody tr').forEach(tr=>{const q=parseFloat(tr.querySelector('[name="quantity[]"]')?.value)||0;const p=parseFloat(tr.querySelector('[name="unit_price[]"]')?.value)||0;const d=parseFloat(tr.querySelector('[name="item_discount[]"]')?.value)||0;const v=parseFloat(tr.querySelector('[name="vat_rate[]"]')?.value)||0;const net=(q*p)-d;const lv=net*v/(100+v);sub+=net;disc+=d;vat+=lv;});
const id=parseFloat(document.getElementById('invDisc').value)||0;disc+=id;const net=sub-id;document.getElementById('dSub').textContent=sub.toFixed(2);document.getElementById('dDisc').textContent=disc.toFixed(2);document.getElementById('dVat').textContent=vat.toFixed(2);document.getElementById('dGrand').textContent=net.toFixed(2)+' ' + ' <?= t("sar") ?>';
const paid=parseFloat(document.getElementById('paidAmt')?.value)||0;document.getElementById('remDisp').value=Math.max(0,net-paid).toFixed(2)+' ' + ' <?= t("sar") ?>';}

function toggleCredit(){document.getElementById('creditFields').style.display=document.getElementById('payType').value==='credit'?'block':'none';}
document.getElementById('custSelect').addEventListener('change',function(){document.getElementById('newCustFields').style.display=this.value==='new'?'block':'none';if(this.value==='new')this.value='';});
document.addEventListener('click',e=>{if(!e.target.closest('#searchRes')&&!e.target.closest('#prodSearch'))document.getElementById('searchRes').style.display='none';});
document.getElementById('saleForm').addEventListener('submit',function(e){if(!document.getElementById('itemsBody').rows.length){e.preventDefault();alert('أضف بند واحد على الأقل');}});
</script>
<?php require_once 'includes/footer.php'; ?>
