<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.aging_suppliers');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('reports_financial');

$suppliers = $pdo->prepare("SELECT s.id, s.name, s.phone, s.balance,
    (SELECT COALESCE(SUM(remaining_amount),0) FROM purchase_invoices WHERE tenant_id=? AND branch_id=? AND supplier_id=s.id AND remaining_amount>0 AND DATEDIFF(CURDATE(),invoice_date)<=30) as d30,
    (SELECT COALESCE(SUM(remaining_amount),0) FROM purchase_invoices WHERE tenant_id=? AND branch_id=? AND supplier_id=s.id AND remaining_amount>0 AND DATEDIFF(CURDATE(),invoice_date) BETWEEN 31 AND 60) as d60,
    (SELECT COALESCE(SUM(remaining_amount),0) FROM purchase_invoices WHERE tenant_id=? AND branch_id=? AND supplier_id=s.id AND remaining_amount>0 AND DATEDIFF(CURDATE(),invoice_date) BETWEEN 61 AND 90) as d90,
    (SELECT COALESCE(SUM(remaining_amount),0) FROM purchase_invoices WHERE tenant_id=? AND branch_id=? AND supplier_id=s.id AND remaining_amount>0 AND DATEDIFF(CURDATE(),invoice_date)>90) as d90plus
    FROM suppliers s WHERE s.tenant_id=? AND s.balance>0 ORDER BY s.balance DESC");
$suppliers->execute([$tid,$bid,$tid,$bid,$tid,$bid,$tid,$bid,$tid]);
$data = $suppliers->fetchAll();
$t30=0;$t60=0;$t90=0;$t90p=0;$tAll=0;
foreach($data as $r){$t30+=$r['d30'];$t60+=$r['d60'];$t90+=$r['d90'];$t90p+=$r['d90plus'];$tAll+=$r['balance'];}
?>
<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card"><div class="stat-icon" style="background:#dcfce7;"><i class="fas fa-clock" style="color:#16a34a;"></i></div><div class="stat-info"><p class="stat-label">0-30 <?= t('days') ?></p><p class="stat-value" style="font-size:20px;color:#16a34a;"><?=formatMoney($t30)?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#fef9c3;"><i class="fas fa-clock" style="color:#ca8a04;"></i></div><div class="stat-info"><p class="stat-label">31-60 <?= t('days') ?></p><p class="stat-value" style="font-size:20px;color:#ca8a04;"><?=formatMoney($t60)?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#fed7aa;"><i class="fas fa-clock" style="color:#ea580c;"></i></div><div class="stat-info"><p class="stat-label">61-90 <?= t('days') ?></p><p class="stat-value" style="font-size:20px;color:#ea580c;"><?=formatMoney($t90)?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#fecaca;"><i class="fas fa-exclamation-circle" style="color:#dc2626;"></i></div><div class="stat-info"><p class="stat-label">90+ <?= t('days') ?></p><p class="stat-value" style="font-size:20px;color:#dc2626;"><?=formatMoney($t90p)?></p></div></div>
</div>
<div class="card"><div class="card-header"><h3><i class="fas fa-truck"></i> <?= t('accounting.aging_suppliers') ?> (<?=count($data)?>)</h3></div>
    <div class="table-responsive"><table>
        <thead><tr><th><?= t('purchases.supplier') ?></th><th><?= t('phone') ?></th><th>0-30 <?= t('days') ?></th><th>31-60 <?= t('days') ?></th><th>61-90 <?= t('days') ?></th><th>90+ <?= t('days') ?></th><th><?= t('total') ?></th></tr></thead>
        <tbody>
        <?php if(empty($data)): ?><tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;"><?= t('no_results') ?></td></tr>
        <?php else: foreach($data as $r): ?>
        <tr>
            <td style="font-weight:600;"><?=htmlspecialchars($r['name'])?></td>
            <td style="font-size:12px;color:#64748b;"><?=$r['phone']??'-'?></td>
            <td style="color:#16a34a;"><?=formatMoney($r['d30'])?></td>
            <td style="color:#ca8a04;"><?=formatMoney($r['d60'])?></td>
            <td style="color:#ea580c;"><?=formatMoney($r['d90'])?></td>
            <td style="color:#dc2626;font-weight:600;"><?=formatMoney($r['d90plus'])?></td>
            <td style="font-weight:700;"><?=formatMoney($r['balance'])?></td>
        </tr>
        <?php endforeach; endif; ?>
        <tr style="background:#f8fafc;font-weight:700;"><td colspan="2"><?= t('total') ?></td><td><?=formatMoney($t30)?></td><td><?=formatMoney($t60)?></td><td><?=formatMoney($t90)?></td><td><?=formatMoney($t90p)?></td><td><?=formatMoney($tAll)?></td></tr>
        </tbody>
    </table></div></div>
<?php require_once 'includes/footer.php'; ?>
