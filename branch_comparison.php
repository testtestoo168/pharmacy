<?php
require_once 'includes/config.php';
$pageTitle = t('branches.branch_comparison');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('reports_view');

// مقارنة الفروع متاحة للفرع الرئيسي فقط
$_isMainBranch = false;
try { $_bCheck = $pdo->prepare("SELECT is_main FROM branches WHERE id=? AND tenant_id=?"); $_bCheck->execute([$bid,$tid]); $_isMainBranch = (bool)$_bCheck->fetchColumn(); } catch(Exception $e) {}
if (!$_isMainBranch) {
    echo '<div style="text-align:center;padding:80px 20px;direction:rtl;font-family:Tajawal;"><i class="fas fa-lock" style="font-size:60px;color:#dc2626;margin-bottom:20px;display:block;"></i><h2 style="color:#dc2626;">' . t('unavailable') . '</h2><p style="color:#666;">' . t('g.comparison_main_only') . '</p><a href="reports" style="display:inline-block;margin-top:20px;padding:10px 24px;background:#0f3460;color:#fff;border-radius:8px;text-decoration:none;">' . t('back') . '</a></div>';
    require_once 'includes/footer.php'; exit;
}

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

$branches = $pdo->prepare("SELECT b.id, b.name, b.is_main,
    (SELECT COALESCE(SUM(si.grand_total),0) FROM sales_invoices si WHERE si.tenant_id=? AND si.branch_id=b.id AND si.status IN('completed','partial_return') AND si.invoice_date BETWEEN ? AND ?) as sales,
    (SELECT COUNT(*) FROM sales_invoices si WHERE si.tenant_id=? AND si.branch_id=b.id AND si.status IN('completed','partial_return') AND si.invoice_date BETWEEN ? AND ?) as invoice_count,
    (SELECT COALESCE(SUM(pi.grand_total),0) FROM purchase_invoices pi WHERE pi.tenant_id=? AND pi.branch_id=b.id AND pi.invoice_date BETWEEN ? AND ?) as purchases,
    (SELECT COALESCE(SUM(e.total),0) FROM expenses e WHERE e.tenant_id=? AND e.branch_id=b.id AND e.expense_date BETWEEN ? AND ?) as expenses,
    (SELECT COUNT(DISTINCT p.id) FROM products p JOIN inventory_batches ib ON ib.product_id=p.id WHERE p.tenant_id=? AND ib.branch_id=b.id AND ib.available_qty>0) as products,
    (SELECT COUNT(*) FROM users u WHERE u.tenant_id=? AND u.branch_id=b.id AND u.is_active=1) as users
    FROM branches b WHERE b.tenant_id=? AND b.is_active=1 ORDER BY sales DESC");
$branches->execute([$tid,$fromDate,$toDate,$tid,$fromDate,$toDate,$tid,$fromDate,$toDate,$tid,$fromDate,$toDate,$tid,$tid,$tid]);
$data = $branches->fetchAll();
$totalSales = array_sum(array_column($data,'sales'));
$company = getCompanySettings($pdo);
?>

<!-- Print rules — inline to bypass any CSS caching -->
<style>
@media print {
    #chartSection, #chartSection *, canvas, .chart-hide-print { display:none !important; height:0 !important; overflow:hidden !important; visibility:hidden !important; }
    .bc-table thead { display:table-header-group !important; }
    .bc-table tfoot { display:table-footer-group !important; }
    .bc-table tbody tr { break-inside:avoid; page-break-inside:avoid; }
    .bc-print-header { display:block !important; }
}
</style>

<!-- Print Header -->
<div class="bc-print-header print-only" style="display:none;text-align:center;padding:14px 0 10px;border-bottom:2px solid #1a2744;margin-bottom:8px;">
    <div style="font-size:16px;font-weight:800;color:#1a2744;"><?= htmlspecialchars($company['company_name'] ?? t('app_name')) ?></div>
    <div style="font-size:14px;font-weight:700;color:#1a2744;margin:4px 0;"><?= t('branches.branch_comparison') ?></div>
    <div style="font-size:11px;color:#6b7280;"><?= t('prt.period') ?> <?= $fromDate ?> — <?= $toDate ?> | <?= t('prt.generated_at') ?>: <?= date('Y-m-d H:i') ?></div>
</div>

<div class="card no-print" style="margin-bottom:16px;"><div class="card-body" style="padding:12px;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <label><?= t('from') ?>:</label><input type="date" name="from" class="form-control" value="<?=$fromDate?>" style="width:150px;">
        <label><?= t('to') ?>:</label><input type="date" name="to" class="form-control" value="<?=$toDate?>" style="width:150px;">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i><?= t('view') ?></button>
        <button type="button" class="btn btn-sm btn-info" onclick="window.print()"><i class="fas fa-print"></i><?= t('print') ?></button>
    </form>
</div></div>

<div class="card"><div class="card-header"><h3><i class="fas fa-store"></i><?= t('branches.branch_comparison') ?></h3></div>
    <div class="table-responsive"><table class="bc-table">
        <thead><tr><th><?= t('g.branch') ?></th><th><?= t('sales.total_sales') ?></th><th><?= t('invoices') ?></th><th><?= t('purchases.title') ?></th><th><?= t('expenses.title') ?></th><th><?= t('g.net_profit') ?></th><th><?= t('items') ?></th><th><?= t('g.employees') ?></th><th><?= t('g.percentage') ?></th></tr></thead>
        <tbody>
        <?php foreach($data as $b): $profit=$b['sales']-$b['purchases']-$b['expenses']; $pct=$totalSales>0?round($b['sales']/$totalSales*100,1):0; ?>
        <tr>
            <td style="font-weight:600;"><?=htmlspecialchars($b['name'])?> <?php if($b['is_main']): ?><span class="badge badge-primary" style="font-size:10px;"><?= t('main') ?></span><?php endif; ?></td>
            <td style="font-weight:700;color:#1d4ed8;"><?=formatMoney($b['sales'])?></td>
            <td><?=$b['invoice_count']?></td>
            <td><?=formatMoney($b['purchases'])?></td>
            <td><?=formatMoney($b['expenses'])?></td>
            <td style="font-weight:700;color:<?=$profit>=0?'#16a34a':'#dc2626'?>;"><?=formatMoney($profit)?></td>
            <td><?=$b['products']?></td>
            <td><?=$b['users']?></td>
            <td><div style="background:#e2e8f0;border-radius:4px;height:20px;width:100px;overflow:hidden;"><div style="background:#1d4ed8;height:100%;width:<?=$pct?>%;border-radius:4px;"></div></div><span style="font-size:11px;color:#64748b;"><?=$pct?>%</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>

<?php if(count($data)>1): ?>
<div id="chartSection" class="card chart-hide-print" style="margin-top:16px;"><div class="card-header"><h3><i class="fas fa-chart-bar"></i> <?= t('reports.chart') ?></h3></div>
    <div class="card-body"><canvas id="branchChart" height="300"></canvas></div></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('branchChart'),{type:'bar',data:{labels:<?=json_encode(array_column($data,'name'))?>,datasets:[
    {label:'<?= t('perms.sales') ?>',data:<?=json_encode(array_map('floatval',array_column($data,'sales')))?>,backgroundColor:'#1d4ed8',borderRadius:4},
    {label:'<?= t('perms.purchases') ?>',data:<?=json_encode(array_map('floatval',array_column($data,'purchases')))?>,backgroundColor:'#f59e0b',borderRadius:4},
    {label:'<?= t('perms.expenses') ?>',data:<?=json_encode(array_map('floatval',array_column($data,'expenses')))?>,backgroundColor:'#ef4444',borderRadius:4}
]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true}}}});
</script>
<?php endif; ?>
<?php require_once 'includes/footer.php'; ?>
