<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.aging_debts');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
$type = $_GET['type'] ?? 'customers';

if ($type === 'customers') {
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.phone, c.balance,
        (SELECT COALESCE(SUM(remaining_amount),0) FROM sales_invoices WHERE tenant_id = ? AND branch_id = ? AND customer_id = c.id AND remaining_amount > 0 AND payment_type = 'credit' AND DATEDIFF(CURDATE(), invoice_date) <= 30) as d30,
        (SELECT COALESCE(SUM(remaining_amount),0) FROM sales_invoices WHERE tenant_id = ? AND branch_id = ? AND customer_id = c.id AND remaining_amount > 0 AND payment_type = 'credit' AND DATEDIFF(CURDATE(), invoice_date) BETWEEN 31 AND 60) as d60,
        (SELECT COALESCE(SUM(remaining_amount),0) FROM sales_invoices WHERE tenant_id = ? AND branch_id = ? AND customer_id = c.id AND remaining_amount > 0 AND payment_type = 'credit' AND DATEDIFF(CURDATE(), invoice_date) BETWEEN 61 AND 90) as d90,
        (SELECT COALESCE(SUM(remaining_amount),0) FROM sales_invoices WHERE tenant_id = ? AND branch_id = ? AND customer_id = c.id AND remaining_amount > 0 AND payment_type = 'credit' AND DATEDIFF(CURDATE(), invoice_date) > 90) as d90plus
        FROM customers c WHERE c.tenant_id = ? AND c.balance > 0 ORDER BY c.balance DESC");
    $stmt->execute([$tid, $bid, $tid, $bid, $tid, $bid, $tid, $bid, $tid]);
    $items = $stmt->fetchAll();
    $title = t('accounting.aging_customers');
    $nameLabel = t('sales.customer');
} else {
    $stmt = $pdo->prepare("SELECT s.id, s.name, s.phone, s.balance,
        (SELECT COALESCE(SUM(remaining_amount),0) FROM purchase_invoices WHERE tenant_id = ? AND branch_id = ? AND supplier_id = s.id AND remaining_amount > 0 AND payment_type = 'credit' AND DATEDIFF(CURDATE(), invoice_date) <= 30) as d30,
        (SELECT COALESCE(SUM(remaining_amount),0) FROM purchase_invoices WHERE tenant_id = ? AND branch_id = ? AND supplier_id = s.id AND remaining_amount > 0 AND payment_type = 'credit' AND DATEDIFF(CURDATE(), invoice_date) BETWEEN 31 AND 60) as d60,
        (SELECT COALESCE(SUM(remaining_amount),0) FROM purchase_invoices WHERE tenant_id = ? AND branch_id = ? AND supplier_id = s.id AND remaining_amount > 0 AND payment_type = 'credit' AND DATEDIFF(CURDATE(), invoice_date) BETWEEN 61 AND 90) as d90,
        (SELECT COALESCE(SUM(remaining_amount),0) FROM purchase_invoices WHERE tenant_id = ? AND branch_id = ? AND supplier_id = s.id AND remaining_amount > 0 AND payment_type = 'credit' AND DATEDIFF(CURDATE(), invoice_date) > 90) as d90plus
        FROM suppliers s WHERE s.tenant_id = ? AND s.balance > 0 ORDER BY s.balance DESC");
    $stmt->execute([$tid, $bid, $tid, $bid, $tid, $bid, $tid, $bid, $tid]);
    $items = $stmt->fetchAll();
    $title = t('accounting.aging_suppliers');
    $nameLabel = t('purchases.supplier');
}

$totalD30 = array_sum(array_column($items, 'd30'));
$totalD60 = array_sum(array_column($items, 'd60'));
$totalD90 = array_sum(array_column($items, 'd90'));
$totalD90plus = array_sum(array_column($items, 'd90plus'));
$totalAll = array_sum(array_column($items, 'balance'));
?>

<div class="card no-print">
    <div class="card-body" style="padding:12px;display:flex;gap:8px;align-items:center;">
        <a href="?type=customers" class="btn btn-sm <?= $type==='customers'?'btn-primary':'' ?>" style="text-decoration:none;padding:6px 16px;"><?= t('nav.customers') ?></a>
        <a href="?type=suppliers" class="btn btn-sm <?= $type==='suppliers'?'btn-primary':'' ?>" style="text-decoration:none;padding:6px 16px;"><?= t('nav.suppliers') ?></a>
        <button class="btn btn-sm btn-info" onclick="window.print()" style="margin-right:auto;"><i class="fas fa-print"></i><?= t('print') ?></button>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-clock"></i> <?= $title ?></h3></div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th><?= $nameLabel ?></th><th><?= t('phone') ?></th>
                    <th style="background:#dcfce7;color:#166534;">0-30 <?= t('days') ?></th>
                    <th style="background:#fef9c3;color:#854d0e;">31-60 <?= t('days') ?></th>
                    <th style="background:#fed7aa;color:#9a3412;">61-90 <?= t('days') ?></th>
                    <th style="background:#fecaca;color:#991b1b;">90+</th>
                    <th style="font-weight:700;"><?= t('total') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;"><?= t('no_results') ?></td></tr>
            <?php else: foreach ($items as $item): ?>
            <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($item['name']) ?></td>
                <td style="font-size:12px;color:#64748b;"><?= htmlspecialchars($item['phone'] ?? '-') ?></td>
                <td style="color:#16a34a;"><?= formatMoney($item['d30']) ?></td>
                <td style="color:#ca8a04;"><?= formatMoney($item['d60']) ?></td>
                <td style="color:#ea580c;"><?= formatMoney($item['d90']) ?></td>
                <td style="color:#dc2626;font-weight:700;"><?= formatMoney($item['d90plus']) ?></td>
                <td style="font-weight:700;"><?= formatMoney($item['balance']) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($items)): ?>
            <tfoot>
                <tr style="background:var(--secondary);font-weight:700;">
                    <td colspan="2"><?= t('total') ?></td>
                    <td style="color:#16a34a;"><?= formatMoney($totalD30) ?></td>
                    <td style="color:#ca8a04;"><?= formatMoney($totalD60) ?></td>
                    <td style="color:#ea580c;"><?= formatMoney($totalD90) ?></td>
                    <td style="color:#dc2626;"><?= formatMoney($totalD90plus) ?></td>
                    <td><?= formatMoney($totalAll) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
