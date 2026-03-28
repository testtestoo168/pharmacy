<?php
require_once 'includes/config.php';
$pageTitle = t('suppliers.supplier_details');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('suppliers_view');

$supplierId = intval($_GET['id'] ?? 0);
if (!$supplierId) { header('Location: suppliers'); exit; }

$supplier = $pdo->prepare("SELECT * FROM suppliers WHERE tenant_id = $tid AND id = ?"); $supplier->execute([$supplierId]); $supplier = $supplier->fetch();
if (!$supplier) { echo '<div class="alert alert-danger">' . t('g.not_found') . '</div>'; require_once 'includes/footer.php'; exit; }

// تحديث
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_supplier'])) {
    verifyCsrfToken();
    $pdo->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, mobile=?, email=?, tax_number=?, address=?, city=?, payment_terms=?, credit_limit=?, notes=? WHERE id=? AND tenant_id = $tid")
        ->execute([$_POST['name'], $_POST['contact_person'], $_POST['phone'], $_POST['mobile'], $_POST['email'], $_POST['tax_number'], $_POST['address'], $_POST['city'], $_POST['payment_terms'], $_POST['credit_limit'], $_POST['notes'], $supplierId]);
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
    $supplier = $pdo->prepare("SELECT * FROM suppliers WHERE tenant_id = $tid AND id = ?"); $supplier->execute([$supplierId]); $supplier = $supplier->fetch();
}

$totalPurchases = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND supplier_id = ?"); $totalPurchases->execute([$supplierId]); $totalPurchases = floatval($totalPurchases->fetchColumn());
$totalPaid = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND supplier_id = ?"); $totalPaid->execute([$supplierId]); $totalPaid = floatval($totalPaid->fetchColumn());
$invoiceCount = $pdo->prepare("SELECT COUNT(*) FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND supplier_id = ?"); $invoiceCount->execute([$supplierId]); $invoiceCount = $invoiceCount->fetchColumn();
$orderCount = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE tenant_id = $tid AND supplier_id = ?"); $orderCount->execute([$supplierId]); $orderCount = $orderCount->fetchColumn();

$tab = $_GET['tab'] ?? 'info';
?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="color:#4f46e5;"><i class="fas fa-truck"></i></div><div class="stat-info"><p class="stat-value" style="font-size:18px;"><?= htmlspecialchars($supplier['name']) ?></p><p><?= $supplier['phone'] ?: $supplier['contact_person'] ?: '-' ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="color:#1d4ed8;"><i class="fas fa-file-invoice"></i></div><div class="stat-info"><p class="stat-value"><?= $invoiceCount ?></p><p><?= t('purchases.title') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="color:#16a34a;"><i class="fas fa-coins"></i></div><div class="stat-info"><p class="stat-value" style="color:#16a34a;"><?= formatMoney($totalPurchases) ?></p><p><?= t('purchases.total_purchases') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="color:<?= $supplier['balance'] > 0 ? '#dc2626' : '#16a34a' ?>;"><i class="fas fa-balance-scale"></i></div><div class="stat-info"><p class="stat-value" style="color:<?= $supplier['balance'] > 0 ? '#dc2626' : '#16a34a' ?>;"><?= formatMoney($supplier['balance']) ?></p><p>الرصيد المستحق</p></div></div>
</div>

<div style="display:flex;gap:4px;margin-bottom:15px;">
    <a href="?id=<?= $supplierId ?>&tab=info" class="btn <?= $tab==='info'?'btn-primary':'btn-secondary' ?> btn-sm"><i class="fas fa-info-circle"></i><?= t('g.data') ?></a>
    <a href="?id=<?= $supplierId ?>&tab=invoices" class="btn <?= $tab==='invoices'?'btn-primary':'btn-secondary' ?> btn-sm"><i class="fas fa-file-invoice"></i><?= t('invoices') ?></a>
    <a href="?id=<?= $supplierId ?>&tab=orders" class="btn <?= $tab==='orders'?'btn-primary':'btn-secondary' ?> btn-sm"><i class="fas fa-clipboard-list"></i><?= t('purchases.purchase_orders') ?></a>
    <a href="?id=<?= $supplierId ?>&tab=payments" class="btn <?= $tab==='payments'?'btn-primary':'btn-secondary' ?> btn-sm"><i class="fas fa-money-check"></i><?= t('g.payments_total') ?></a>
</div>

<?php if ($tab === 'info'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-edit"></i><?= t('suppliers.supplier_details') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="update_supplier" value="1">
            <div class="form-row">
                <div class="form-group"><label><?= t('purchases.supplier') ?></label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($supplier['name']) ?>" required></div>
                <div class="form-group"><label><?= t('customers.contact_person') ?></label><input type="text" name="contact_person" class="form-control" value="<?= $supplier['contact_person'] ?>"></div>
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="phone" class="form-control" value="<?= $supplier['phone'] ?>"></div>
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="mobile" class="form-control" value="<?= $supplier['mobile'] ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('email') ?></label><input type="email" name="email" class="form-control" value="<?= $supplier['email'] ?>"></div>
                <div class="form-group"><label><?= t('settings.tax_number') ?></label><input type="text" name="tax_number" class="form-control" value="<?= $supplier['tax_number'] ?>"></div>
                <div class="form-group"><label><?= t('g.payment_terms') ?></label><input type="number" name="payment_terms" class="form-control" value="<?= $supplier['payment_terms'] ?>"></div>
                <div class="form-group"><label><?= t('g.credit_limit') ?></label><input type="number" name="credit_limit" class="form-control" value="<?= $supplier['credit_limit'] ?>" step="0.01"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('address') ?></label><input type="text" name="address" class="form-control" value="<?= $supplier['address'] ?>"></div>
                <div class="form-group"><label><?= t('city') ?></label><input type="text" name="city" class="form-control" value="<?= $supplier['city'] ?>"></div>
            </div>
            <div class="form-group"><label><?= t('notes') ?></label><textarea name="notes" class="form-control"><?= $supplier['notes'] ?></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('save') ?></button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'invoices'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-file-invoice"></i><?= t('purchases.title') ?></h3></div>
    <div class="card-body">
        <?php $invoices = $pdo->prepare("SELECT * FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND supplier_id = ? ORDER BY id DESC"); $invoices->execute([$supplierId]); $invList = $invoices->fetchAll(); ?>
        <div class="table-responsive"><table>
        <thead><tr><th><?= t('invoice') ?></th><th><?= t('date') ?></th><th><?= t('sales.payment_method') ?></th><th style="text-align:center;"><?= t('total') ?></th><th style="text-align:center;"><?= t('sales.paid_amount') ?></th><th style="text-align:center;"><?= t('remaining') ?></th><th><?= t('status') ?></th></tr></thead>
        <tbody>
        <?php foreach ($invList as $inv): ?>
        <tr><td><strong><?= $inv['invoice_number'] ?></strong></td><td><?= $inv['invoice_date'] ?></td><td><?= paymentTypeBadge($inv['payment_type']) ?></td><td style="text-align:center;font-weight:700;"><?= formatMoney($inv['grand_total']) ?></td><td style="text-align:center;color:#16a34a;"><?= formatMoney($inv['paid_amount']) ?></td><td style="text-align:center;color:#dc2626;font-weight:600;"><?= formatMoney($inv['remaining_amount']) ?></td><td><span class="badge <?= $inv['payment_status']==='paid'?'badge-success':($inv['payment_status']==='partial'?'badge-warning':'badge-danger') ?>"><?= ['paid'=>t('paid'),'partial'=>t('partial'),'unpaid'=>t('unpaid')][$inv['payment_status']] ?? '' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>

<?php elseif ($tab === 'orders'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-clipboard-list"></i><?= t('purchases.purchase_orders') ?></h3></div>
    <div class="card-body">
        <?php $orders = $pdo->prepare("SELECT * FROM purchase_orders WHERE tenant_id = $tid AND supplier_id = ? ORDER BY id DESC"); $orders->execute([$supplierId]); $ordList = $orders->fetchAll(); ?>
        <div class="table-responsive"><table>
        <thead><tr><th><?= t('g.number') ?></th><th><?= t('date') ?></th><th><?= t('g.expected') ?></th><th><?= t('status') ?></th><th style="text-align:center;"><?= t('total') ?></th></tr></thead>
        <tbody>
        <?php foreach ($ordList as $o): ?>
        <tr><td><strong><?= $o['order_number'] ?></strong></td><td><?= $o['order_date'] ?></td><td><?= $o['expected_date'] ?></td><td><span class="badge <?= ['draft'=>'badge-secondary','sent'=>'badge-info','partial'=>'badge-warning','received'=>'badge-success','cancelled'=>'badge-danger'][$o['status']] ?? 'badge-secondary' ?>"><?= ['draft'=>t('draft'),'sent'=>t('inventory.sent'),'partial'=>t('inventory.partial_receive'),'received'=>t('inventory.received'),'cancelled'=>t('cancelled')][$o['status']] ?? $o['status'] ?></span></td><td style="text-align:center;font-weight:700;"><?= formatMoney($o['grand_total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>

<?php elseif ($tab === 'payments'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-money-check"></i> <?= t('g.payments_total') ?></h3></div>
    <div class="card-body">
        <?php $payments = $pdo->prepare("SELECT * FROM payment_vouchers WHERE tenant_id = $tid AND branch_id = $bid AND party_type = 'supplier' AND party_id = ? ORDER BY id DESC"); $payments->execute([$supplierId]); $payList = $payments->fetchAll(); ?>
        <?php if (empty($payList)): ?><div style="text-align:center;padding:30px;color:#94a3b8;"><?= t('no_results') ?> صرف لهذا المورد</div>
        <?php else: ?>
        <div class="table-responsive"><table>
        <thead><tr><th><?= t('g.voucher_number') ?></th><th><?= t('date') ?></th><th><?= t('sales.payment_method') ?></th><th style="text-align:center;"><?= t('amount') ?></th><th><?= t('description') ?></th></tr></thead>
        <tbody>
        <?php foreach ($payList as $p): ?>
        <tr><td><strong><?= $p['voucher_number'] ?></strong></td><td><?= $p['voucher_date'] ?></td><td><?= paymentTypeBadge($p['payment_method']) ?></td><td style="text-align:center;font-weight:700;"><?= formatMoney($p['amount']) ?></td><td style="color:#6b7280;font-size:12px;"><?= $p['description'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
