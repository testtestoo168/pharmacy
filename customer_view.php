<?php
require_once 'includes/config.php';
$pageTitle = t('customers.customer_details');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('customers_view');

$customerId = intval($_GET['id'] ?? 0);
if (!$customerId) { header('Location: customers'); exit; }

$customer = $pdo->prepare("SELECT * FROM customers WHERE tenant_id = $tid AND id = ?"); $customer->execute([$customerId]); $customer = $customer->fetch();
if (!$customer) { echo '<div class="alert alert-danger">' . t('g.not_found') . '</div>'; require_once 'includes/footer.php'; exit; }

// تحديث بيانات العميل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
    verifyCsrfToken();
    $pdo->prepare("UPDATE customers SET name=?, phone=?, email=?, national_id=?, address=?, city=?, insurance_company=?, insurance_number=?, type=?, tax_number=?, notes=? WHERE id=? AND tenant_id = $tid")
        ->execute([$_POST['name'], $_POST['phone'], $_POST['email'], $_POST['national_id'], $_POST['address'], $_POST['city'], $_POST['insurance_company'], $_POST['insurance_number'], $_POST['type'], $_POST['tax_number'], $_POST['notes'], $customerId]);
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
    $customer = $pdo->prepare("SELECT * FROM customers WHERE tenant_id = $tid AND id = ?"); $customer->execute([$customerId]); $customer = $customer->fetch();
}

// استبدال نقاط
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_points'])) {
    verifyCsrfToken();
    $points = intval($_POST['redeem_amount']);
    if ($points > 0 && $points <= $customer['loyalty_points']) {
        $pdo->prepare("UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ? AND tenant_id = $tid")->execute([$points, $customerId]);
        $pdo->prepare("INSERT INTO loyalty_transactions (customer_id, points, type, description) VALUES (?,?,'redeem',?)")->execute([$customerId, $points, $_POST['redeem_desc'] ?? 'استبدال نقاط']);
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> تم استبدال ' . $points . ' نقطة</div>';
        $customer['loyalty_points'] -= $points;
    }
}

// الفواتير
$invoices = $pdo->prepare("SELECT * FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = ? ORDER BY id DESC LIMIT 20"); $invoices->execute([$customerId]); $invoices = $invoices->fetchAll();
$totalInvoices = $pdo->prepare("SELECT COUNT(*) FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = ?"); $totalInvoices->execute([$customerId]); $totalInvoices = $totalInvoices->fetchColumn();
$totalSpent = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = ?"); $totalSpent->execute([$customerId]); $totalSpent = floatval($totalSpent->fetchColumn());

// حسابات الآجل
$creditInvoices = $pdo->prepare("SELECT id, invoice_number, invoice_date, grand_total, paid_amount, remaining_amount, payment_type FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = ? AND payment_type = 'credit' ORDER BY id DESC"); $creditInvoices->execute([$customerId]); $creditInvoices = $creditInvoices->fetchAll();
$totalCreditAmount = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = ? AND payment_type = 'credit'"); $totalCreditAmount->execute([$customerId]); $totalCreditAmount = floatval($totalCreditAmount->fetchColumn());
$totalCreditPaid = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = ? AND payment_type = 'credit'"); $totalCreditPaid->execute([$customerId]); $totalCreditPaid = floatval($totalCreditPaid->fetchColumn());
$totalCreditRemaining = $totalCreditAmount - $totalCreditPaid;
// سندات القبض
$receipts = $pdo->prepare("SELECT * FROM receipt_vouchers WHERE tenant_id = $tid AND branch_id = $bid AND party_type = 'customer' AND party_id = ? ORDER BY id DESC"); $receipts->execute([$customerId]); $receipts = $receipts->fetchAll();
$totalReceipts = array_sum(array_column($receipts, 'amount'));

// نقاط الولاء
$loyaltyLog = $pdo->prepare("SELECT * FROM loyalty_transactions WHERE tenant_id = $tid AND customer_id = ? ORDER BY id DESC LIMIT 20"); $loyaltyLog->execute([$customerId]); $loyaltyLog = $loyaltyLog->fetchAll();

$tab = $_GET['tab'] ?? 'info';
?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="color:#1d4ed8;"><i class="fas fa-user"></i></div><div class="stat-info"><p class="stat-value" style="font-size:18px;"><?= htmlspecialchars($customer['name']) ?></p><p><?= $customer['phone'] ?: '-' ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="color:#16a34a;"><i class="fas fa-file-invoice-dollar"></i></div><div class="stat-info"><p class="stat-value"><?= $totalInvoices ?></p><p><?= t('invoice') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="color:#7c3aed;"><i class="fas fa-wallet"></i></div><div class="stat-info"><p class="stat-value" style="color:#7c3aed;"><?= formatMoney($totalSpent) ?></p><p><?= t('g.total_spending') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="color:#f59e0b;"><i class="fas fa-star"></i></div><div class="stat-info"><p class="stat-value" style="color:#f59e0b;"><?= $customer['loyalty_points'] ?></p><p><?= t('customers.loyalty_points') ?></p></div></div>
    <div class="stat-card" style="border:2px solid <?= $totalCreditRemaining > 0 ? '#dc2626' : '#16a34a' ?>;"><div class="stat-icon" style="color:<?= $totalCreditRemaining > 0 ? '#dc2626' : '#16a34a' ?>;"><i class="fas fa-hand-holding-usd"></i></div><div class="stat-info"><p class="stat-value" style="color:<?= $totalCreditRemaining > 0 ? '#dc2626' : '#16a34a' ?>;"><?= formatMoney($totalCreditRemaining) ?></p><p><?= t('g.remaining_balance') ?></p></div></div>
</div>

<!-- تبويبات -->
<div style="display:flex;gap:4px;margin-bottom:15px;flex-wrap:wrap;">
    <a href="?id=<?= $customerId ?>&tab=info" class="btn <?= $tab==='info'?'btn-primary':'btn-secondary' ?> btn-sm"><i class="fas fa-info-circle"></i><?= t('g.data') ?></a>
    <a href="?id=<?= $customerId ?>&tab=invoices" class="btn <?= $tab==='invoices'?'btn-primary':'btn-secondary' ?> btn-sm"><i class="fas fa-file-invoice"></i><?= t('invoices') ?></a>
    <a href="?id=<?= $customerId ?>&tab=credit" class="btn <?= $tab==='credit'?'btn-primary':'btn-secondary' ?> btn-sm"><i class="fas fa-hand-holding-usd"></i><?= t('g.credit_details') ?></a>
    <a href="?id=<?= $customerId ?>&tab=loyalty" class="btn <?= $tab==='loyalty'?'btn-primary':'btn-secondary' ?> btn-sm"><i class="fas fa-star"></i><?= t('customers.loyalty_points') ?></a>
</div>

<?php if ($tab === 'info'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-user-edit"></i><?= t('customers.customer_details') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="update_customer" value="1">
            <div class="form-row">
                <div class="form-group"><label><?= t('name') ?></label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($customer['name']) ?>" required></div>
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="phone" class="form-control" value="<?= $customer['phone'] ?>"></div>
                <div class="form-group"><label><?= t('email') ?></label><input type="email" name="email" class="form-control" value="<?= $customer['email'] ?>"></div>
                <div class="form-group"><label><?= t('g.id_number') ?></label><input type="text" name="national_id" class="form-control" value="<?= $customer['national_id'] ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('address') ?></label><input type="text" name="address" class="form-control" value="<?= $customer['address'] ?>"></div>
                <div class="form-group"><label><?= t('city') ?></label><input type="text" name="city" class="form-control" value="<?= $customer['city'] ?>"></div>
                <div class="form-group"><label><?= t('type') ?></label><select name="type" class="form-control"><option value="individual" <?= $customer['type']==='individual'?'selected':'' ?>><?= t('g.individual') ?></option><option value="company" <?= $customer['type']==='company'?'selected':'' ?>><?= t('g.company') ?></option></select></div>
                <div class="form-group"><label><?= t('settings.tax_number') ?></label><input type="text" name="tax_number" class="form-control" value="<?= $customer['tax_number'] ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('g.insurance_company') ?></label><input type="text" name="insurance_company" class="form-control" value="<?= $customer['insurance_company'] ?>"></div>
                <div class="form-group"><label><?= t('g.insurance_number') ?></label><input type="text" name="insurance_number" class="form-control" value="<?= $customer['insurance_number'] ?>"></div>
            </div>
            <div class="form-group"><label><?= t('notes') ?></label><textarea name="notes" class="form-control"><?= $customer['notes'] ?></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('save') ?></button>
        </form>
    </div>
</div>

<?php elseif ($tab === 'invoices'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-file-invoice"></i> <?= t('sales.title') ?> (<?= count($invoices ?? []) ?>)</h3></div>
    <div class="card-body">
        <div class="table-responsive"><table>
        <thead><tr><th><?= t('sales.invoice_number') ?></th><th><?= t('date') ?></th><th><?= t('sales.payment_method') ?></th><th><?= t('status') ?></th><th style="text-align:center;"><?= t('total') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
        <tr><td><strong><?= $inv['invoice_number'] ?></strong></td><td><?= $inv['invoice_date'] ?></td><td><?= paymentTypeBadge($inv['payment_type']) ?></td><td><span class="badge <?= $inv['status']==='completed'?'badge-success':'badge-warning' ?>"><?= ['completed'=>t('st.completed_f'),'returned'=>t('st.returned'),'partial_return'=>t('st.partial_return')][$inv['status']] ?? $inv['status'] ?></span></td><td style="text-align:center;font-weight:700;"><?= formatMoney($inv['grand_total']) ?></td><td><a href="sales_print?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-print"></i></a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>

<?php elseif ($tab === 'credit'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-hand-holding-usd"></i> <?= t('g.credit_details') ?></h3></div>
    <div class="card-body">
        <div class="stats-grid" style="margin-bottom:16px;">
            <div class="stat-card"><div class="stat-info"><p class="stat-value" style="color:#2563eb;"><?= formatMoney($totalCreditAmount) ?></p><p><?= t('g.invoice_amount') ?></p></div></div>
            <div class="stat-card"><div class="stat-info"><p class="stat-value" style="color:#16a34a;"><?= formatMoney($totalCreditPaid) ?></p><p><?= t('g.paid_so_far') ?></p></div></div>
            <div class="stat-card" style="border:2px solid <?= $totalCreditRemaining > 0 ? '#dc2626' : '#16a34a' ?>;"><div class="stat-info"><p class="stat-value" style="color:<?= $totalCreditRemaining > 0 ? '#dc2626' : '#16a34a' ?>;"><?= formatMoney($totalCreditRemaining) ?></p><p><strong><?= t('g.remaining_balance') ?></strong></p></div></div>
        </div>
        <h4 style="margin:12px 0 8px;color:var(--primary);"><i class="fas fa-file-invoice-dollar"></i> <?= t('g.credit_details') ?></h4>
        <?php if (empty($creditInvoices)): ?>
        <div style="text-align:center;padding:20px;color:#94a3b8;"><?= t('no_results') ?></div>
        <?php else: ?>
        <div class="table-responsive"><table>
            <thead><tr><th><?= t('sales.invoice_number') ?></th><th><?= t('date') ?></th><th style="text-align:center;"><?= t('g.invoice_amount') ?></th><th style="text-align:center;"><?= t('g.paid_so_far') ?></th><th style="text-align:center;"><?= t('g.remaining_balance') ?></th><th><?= t('status') ?></th></tr></thead>
            <tbody>
            <?php foreach ($creditInvoices as $ci): $rem = floatval($ci['remaining_amount'] ?? ($ci['grand_total'] - $ci['paid_amount'])); ?>
            <tr>
                <td><strong><?= $ci['invoice_number'] ?></strong></td>
                <td><?= $ci['invoice_date'] ?></td>
                <td style="text-align:center;font-weight:600;"><?= formatMoney($ci['grand_total']) ?></td>
                <td style="text-align:center;color:#16a34a;"><?= formatMoney($ci['paid_amount'] ?? 0) ?></td>
                <td style="text-align:center;color:<?= $rem > 0 ? '#dc2626' : '#16a34a' ?>;font-weight:700;"><?= formatMoney($rem) ?></td>
                <td><span class="badge <?= $rem <= 0 ? 'badge-success' : ($ci['paid_amount'] > 0 ? 'badge-warning' : 'badge-danger') ?>"><?= $rem <= 0 ? t('paid') : ($ci['paid_amount'] > 0 ? t('partial') : t('unpaid')) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>

        <?php if (!empty($receipts)): ?>
        <h4 style="margin:16px 0 8px;color:var(--primary);"><i class="fas fa-money-bill-wave"></i> <?= t('g.payment_history') ?></h4>
        <div class="table-responsive"><table>
            <thead><tr><th><?= t('g.voucher_number') ?></th><th><?= t('date') ?></th><th style="text-align:center;"><?= t('amount') ?></th><th><?= t('notes') ?></th></tr></thead>
            <tbody>
            <?php foreach ($receipts as $rv): ?>
            <tr>
                <td><strong><?= $rv['voucher_number'] ?></strong></td>
                <td><?= $rv['voucher_date'] ?></td>
                <td style="text-align:center;color:#16a34a;font-weight:700;"><?= formatMoney($rv['amount']) ?></td>
                <td style="color:#6b7280;font-size:12px;"><?= htmlspecialchars($rv['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'loyalty'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-star"></i> برنامج الولاء — الرصيد: <span style="color:#f59e0b;"><?= $customer['loyalty_points'] ?><?= t('customers.points') ?></span></h3></div>
    <div class="card-body">
        <!-- استبدال نقاط -->
        <div style="background:#fff7ed;padding:12px;border-radius:8px;margin-bottom:15px;border:1px solid #fed7aa;">
            <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="redeem_points" value="1">
                <div class="form-group" style="margin:0;"><label style="font-size:12px;"><?= t('customers.points') ?></label><input type="number" name="redeem_amount" min="1" max="<?= $customer['loyalty_points'] ?>" class="form-control" style="width:100px;" required></div>
                <div class="form-group" style="margin:0;flex:1;"><label style="font-size:12px;"><?= t('g.reason') ?></label><input type="text" name="redeem_desc" class="form-control" placeholder="سبب الاستبدال"></div>
                <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-gift"></i> استبدال</button>
            </form>
        </div>
        <!-- سجل النقاط -->
        <div class="table-responsive"><table>
        <thead><tr><th><?= t('date') ?></th><th><?= t('type') ?></th><th style="text-align:center;"><?= t('customers.points') ?></th><th><?= t('description') ?></th></tr></thead>
        <tbody>
        <?php foreach ($loyaltyLog as $lt): ?>
        <tr><td><?= date('Y-m-d', strtotime($lt['created_at'])) ?></td><td><span class="badge <?= $lt['type']==='earn'?'badge-success':'badge-warning' ?>"><?= $lt['type']==='earn'?'إضافة':'استبدال' ?></span></td><td style="text-align:center;font-weight:700;color:<?= $lt['type']==='earn'?'#16a34a':'#dc2626' ?>;"><?= $lt['type']==='earn'?'+':'-' ?><?= $lt['points'] ?></td><td style="color:#6b7280;font-size:12px;"><?= $lt['description'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
