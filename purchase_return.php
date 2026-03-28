<?php
$pageTitle = t('purchases.purchase_return');
require_once 'includes/config.php';
requireLogin();
$tid = getTenantId();
$bid = getBranchId();

// === AJAX: جلب فواتير مورد ===
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $suppId = intval($_GET['supplier_id'] ?? 0);
    $invoices = $pdo->prepare("SELECT id, invoice_number, invoice_date, grand_total FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND supplier_id = ? ORDER BY id DESC");
    $invoices->execute([$suppId]);
    echo json_encode($invoices->fetchAll());
    exit;
}

// === AJAX: جلب بنود فاتورة ===
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_items') {
    header('Content-Type: application/json; charset=utf-8');
    $invId = intval($_GET['invoice_id'] ?? 0);
    $items = $pdo->prepare("SELECT pii.*, p.name as product_name, p.barcode,
                            (SELECT SUM(ib.available_qty) FROM inventory_batches ib WHERE ib.tenant_id = $tid AND ib.purchase_invoice_id = pii.invoice_id AND ib.product_id = pii.product_id) as available_qty
                            FROM purchase_invoice_items pii 
                            LEFT JOIN products p ON pii.product_id = p.id 
                            WHERE pii.invoice_id = ?");
    $items->execute([$invId]);
    echo json_encode($items->fetchAll());
    exit;
}

require_once 'includes/header.php';
requirePermission('purchases_return');

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE tenant_id = $tid AND is_active = 1 ORDER BY name")->fetchAll();

// === معالجة المرتجع ===
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_return'])) {
    try {
        verifyCsrfToken();
        $pdo->beginTransaction();

        $invoiceId = intval($_POST['invoice_id'] ?? 0);
        $supplierId = intval($_POST['supplier_id'] ?? 0);
        $returnDate = $_POST['return_date'] ?? date('Y-m-d');
        $reason = trim($_POST['reason'] ?? '');
        $branchId = getBranchId();

        if (!$invoiceId) throw new Exception(t('purchases.select_invoice'));

        // جلب بيانات الفاتورة
        $invData = $pdo->prepare("SELECT * FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND id = ?");
        $invData->execute([$invoiceId]);
        $invoice = $invData->fetch();
        if (!$invoice) throw new Exception(t('prt.invoice_not_found'));

        $returnNumber = generateNumber($pdo, 'purchase_returns', 'return_number', 'PR-');

        $itemIds = $_POST['item_id'] ?? [];
        $returnQtys = $_POST['return_qty'] ?? [];
        $returnPrices = $_POST['return_price'] ?? [];

        $totalReturnAmount = 0;
        $returnItems = [];

        for ($i = 0; $i < count($itemIds); $i++) {
            $returnQty = intval($returnQtys[$i] ?? 0);
            if ($returnQty <= 0) continue;

            $itemId = intval($itemIds[$i]);
            $returnPrice = floatval($returnPrices[$i] ?? 0);

            // جلب بيانات البند الأصلي
            $origItem = $pdo->prepare("SELECT * FROM purchase_invoice_items WHERE id = ?");
            $origItem->execute([$itemId]);
            $orig = $origItem->fetch();
            if (!$orig) continue;

            if ($returnQty > $orig['quantity']) throw new Exception(t('pos.return_qty_exceeds') . ': ' . $orig['product_name']);

            $lineTotal = $returnQty * $returnPrice;
            $totalReturnAmount += $lineTotal;

            $returnItems[] = [
                'item_id' => $itemId,
                'product_id' => $orig['product_id'],
                'product_name' => $orig['product_name'],
                'quantity' => $returnQty,
                'unit_price' => $returnPrice,
                'total' => $lineTotal,
            ];
        }

        if (empty($returnItems)) throw new Exception(t('validation.return_qty_error'));

        // حفظ المرتجع
        $stmt = $pdo->prepare("INSERT INTO purchase_returns (tenant_id,return_number, supplier_id, invoice_id, branch_id, return_date, total_amount, reason, created_by) VALUES (?,?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tid,$returnNumber, $supplierId, $invoiceId, $branchId, $returnDate, $totalReturnAmount, $reason, $_SESSION['user_id']]);
        $returnId = $pdo->lastInsertId();

        // معالجة كل بند مرتجع
        foreach ($returnItems as $ri) {
            if (!$ri['product_id']) continue;

            // إنقاص المخزون من المنتج
            $pdo->prepare("UPDATE products SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ? AND tenant_id = $tid AND branch_id = $bid")
                ->execute([$ri['quantity'], $ri['product_id']]);

            // إنقاص من الدفعات (FEFO عكسي - نبدأ بالأحدث)
            $batches = $pdo->prepare("SELECT id, available_qty FROM inventory_batches WHERE tenant_id = $tid AND product_id = ? AND purchase_invoice_id = ? AND available_qty > 0 ORDER BY expiry_date DESC");
            $batches->execute([$ri['product_id'], $invoiceId]);
            $remaining = $ri['quantity'];
            while ($remaining > 0 && ($batch = $batches->fetch())) {
                $deduct = min($remaining, $batch['available_qty']);
                $pdo->prepare("UPDATE inventory_batches SET available_qty = available_qty - ? WHERE id = ? AND tenant_id = $tid")->execute([$deduct, $batch['id']]);
                $remaining -= $deduct;
            }

            // حركة مخزون
            $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id, branch_id, movement_type, quantity, reference_type, reference_id, notes, created_by) 
                           VALUES (?,?, ?, 'return_purchase', ?, 'purchase_return', ?, ?, ?)")
                ->execute([$tid,$ri['product_id'], $branchId, $ri['quantity'], $returnId, t('purchases.purchase_return') . ' - ' . $returnNumber, $_SESSION['user_id']]);
        }

        // تحديث رصيد المورد
        if ($supplierId) {
            $pdo->prepare("UPDATE suppliers SET balance = GREATEST(0, balance - ?) WHERE id = ? AND tenant_id = $tid")
                ->execute([$totalReturnAmount, $supplierId]);
        }

        // القيد المحاسبي
        createPurchaseReturnJournalEntry($pdo, [
            'id'=>$returnId, 'return_number'=>$returnNumber, 'return_date'=>$returnDate,
            'total_amount'=>$totalReturnAmount, 'vat_amount'=>0, 'original_payment_type'=>$invoice['payment_type']
        ]);

        logActivity($pdo, 'activity.purchase_return', $returnNumber, 'purchases');

        $pdo->commit();
        $successMsg = t('saved_success') . ' — <strong>' . $returnNumber . '</strong>';
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        $errorMsg = t('error') . ': ' . $e->getMessage();
    }
}

// تحميل تلقائي من فاتورة محددة
$preloadInvoiceId = intval($_GET['invoice_id'] ?? 0);
$preloadInvoice = null;
if ($preloadInvoiceId) {
    $stmt = $pdo->prepare("SELECT pi.*, s.name as supplier_name FROM purchase_invoices pi LEFT JOIN suppliers s ON pi.supplier_id = s.id WHERE pi.tenant_id = $tid AND pi.branch_id = $bid AND pi.id = ?");
    $stmt->execute([$preloadInvoiceId]);
    $preloadInvoice = $stmt->fetch();
}

// سجل المرتجعات
$returns = $pdo->query("SELECT pr.*, s.name as supplier_name, pi.invoice_number 
                        FROM purchase_returns pr 
                        LEFT JOIN suppliers s ON pr.supplier_id = s.id 
                        LEFT JOIN purchase_invoices pi ON pr.invoice_id = pi.id 
                        ORDER BY pr.id DESC LIMIT 50")->fetchAll();
?>

<?php if ($successMsg): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $successMsg ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $errorMsg ?></div>
<?php endif; ?>

<!-- فورم المرتجع -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-undo"></i><?= t('g.new_purchase_return') ?></h3>
    </div>
    <div class="card-body">
        <form method="POST" id="returnForm">
            <?= csrfField() ?>
            <input type="hidden" name="save_return" value="1">
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label><i class="fas fa-truck"></i> <?= t('purchases.supplier') ?></label>
                    <select name="supplier_id" id="supplierSelect" class="form-control" onchange="loadSupplierInvoices(this.value)" required>
                        <option value=""><?= t('g.select_supplier') ?></option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($preloadInvoice && $preloadInvoice['supplier_id'] == $s['id']) ? 'selected' : '' ?>><?= $s['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex:2;">
                    <label><i class="fas fa-file-invoice"></i> <?= t('purchases.title') ?></label>
                    <select name="invoice_id" id="invoiceSelect" class="form-control" onchange="loadInvoiceItems(this.value)" required>
                        <option value=""><?= t('g.select_invoice') ?></option>
                        <?php if ($preloadInvoice): ?>
                        <option value="<?= $preloadInvoice['id'] ?>" selected><?= $preloadInvoice['invoice_number'] ?> (<?= formatMoney($preloadInvoice['grand_total']) ?>)</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar"></i> <?= t('g.return_date') ?></label>
                    <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <!-- بنود المرتجع -->
            <div id="returnItemsSection" style="display:none;">
                <h4 style="margin:15px 0 10px;color:#0f3460;"><i class="fas fa-list"></i> <?= t('g.invoice_items') ?> — <?= t('g.return_qty') ?></h4>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th><?= t('g.product') ?></th>
                                <th><?= t('g.purchased_qty') ?></th>
                                <th><?= t('available') ?></th>
                                <th><?= t('g.unit_price') ?></th>
                                <th><?= t('g.return_qty') ?></th>
                                <th><?= t('total') ?></th>
                            </tr>
                        </thead>
                        <tbody id="returnItemsBody"></tbody>
                        <tfoot>
                            <tr style="background:#0f3460;color:#fff;">
                                <td colspan="5" style="padding:12px;font-weight:800;font-size:16px;"><?= t('g.total_returns') ?></td>
                                <td style="padding:12px;font-weight:800;font-size:18px;" id="totalReturn">0.00 <span style="font-size:12px;font-weight:600;"><?= t('sar') ?></span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="form-group" style="margin-top:15px;">
                <label><i class="fas fa-comment"></i> <?= t('g.reason') ?></label>
                <textarea name="reason" class="form-control" rows="2" placeholder="<?= t('g.reason') ?>"></textarea>
            </div>

            <button type="submit" class="btn btn-warning btn-lg" style="margin-top:10px;" id="saveReturnBtn">
                <i class="fas fa-undo"></i> <?= t('g.save_return') ?>
            </button>
        </form>
    </div>
</div>

<!-- سجل المرتجعات -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> <?= t('g.purchase_returns_log') ?></h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th><?= t('g.return_number') ?></th><th><?= t('invoice') ?></th><th><?= t('purchases.supplier') ?></th><th><?= t('date') ?></th><th><?= t('amount') ?></th><th><?= t('g.reason') ?></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($returns)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:20px;"><?= t('no_results') ?></td></tr>
                    <?php else: foreach ($returns as $ret): ?>
                    <tr>
                        <td><strong><?= $ret['return_number'] ?></strong></td>
                        <td><?= $ret['invoice_number'] ?? '-' ?></td>
                        <td dir="auto"><?= $ret['supplier_name'] ?? '-' ?></td>
                        <td><?= $ret['return_date'] ?></td>
                        <td><strong style="color:#dc2626;"><?= formatMoney($ret['total_amount']) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></strong></td>
                        <td><?= htmlspecialchars($ret['reason'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// تحميل فواتير المورد
function loadSupplierInvoices(supplierId) {
    if (!supplierId) return;
    fetch('purchase_return.php?ajax=get_invoices&supplier_id=' + supplierId)
    .then(r => r.json())
    .then(invoices => {
        const sel = document.getElementById('invoiceSelect');
        sel.innerHTML = '<option value=""><?= t('g.select_invoice') ?></option>';
        invoices.forEach(inv => {
            sel.innerHTML += `<option value="${inv.id}">${inv.invoice_number} - ${inv.invoice_date} (${parseFloat(inv.grand_total).toFixed(2)} )</option>`;
        });
    });
}

// تحميل بنود الفاتورة
function loadInvoiceItems(invoiceId) {
    if (!invoiceId) {
        document.getElementById('returnItemsSection').style.display = 'none';
        return;
    }
    fetch('purchase_return.php?ajax=get_items&invoice_id=' + invoiceId)
    .then(r => r.json())
    .then(items => {
        const tbody = document.getElementById('returnItemsBody');
        tbody.innerHTML = '';
        items.forEach(item => {
            const avail = item.available_qty || item.quantity;
            tbody.innerHTML += `
                <tr>
                    <td><strong>${item.product_name}</strong>${item.barcode ? '<br><small style="color:#94a3b8;">'+item.barcode+'</small>' : ''}</td>
                    <td>${item.quantity}</td>
                    <td>${avail}</td>
                    <td>${parseFloat(item.unit_price).toFixed(2)}</td>
                    <td>
                        <input type="hidden" name="item_id[]" value="${item.id}">
                        <input type="hidden" name="return_price[]" value="${item.unit_price}">
                        <input type="number" name="return_qty[]" value="0" min="0" max="${avail}" class="form-control" style="width:80px;" oninput="calcReturnTotals()">
                    </td>
                    <td class="return-line-total">0.00</td>
                </tr>
            `;
        });
        document.getElementById('returnItemsSection').style.display = 'block';
    });
}

function calcReturnTotals() {
    let total = 0;
    document.querySelectorAll('#returnItemsBody tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('[name="return_qty[]"]').value) || 0;
        const price = parseFloat(tr.querySelector('[name="return_price[]"]').value) || 0;
        const lineTotal = qty * price;
        tr.querySelector('.return-line-total').textContent = lineTotal.toFixed(2);
        total += lineTotal;
    });
    document.getElementById('totalReturn').textContent = total.toFixed(2) + ' ';
}

// تحميل تلقائي
<?php if ($preloadInvoice): ?>
document.addEventListener('DOMContentLoaded', function() {
    loadInvoiceItems(<?= $preloadInvoiceId ?>);
});
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
