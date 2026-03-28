<?php
require_once 'includes/config.php';
$pageTitle = t('reports.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('reports_view');
$company = getCompanySettings($pdo);

$report = $_GET['report'] ?? '';
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

$branches = $pdo->query("SELECT * FROM branches WHERE tenant_id = $tid AND is_active = 1 ORDER BY name")->fetchAll();

// تحقق: هل الفرع الحالي هو الفرع الرئيسي؟
$isMainBranch = false;
foreach ($branches as $b) {
    if ($b['id'] == $bid && $b['is_main'] == 1) { $isMainBranch = true; break; }
}

// الفرع الرئيسي يشوف كل الفروع — الفرعي يشوف فرعه بس
if ($isMainBranch) {
    $branchId = intval($_GET['branch_id'] ?? 0);
} else {
    $branchId = $bid; // إجبار الفرع الفرعي على فرعه فقط
}

$branchFilter = $branchId ? " AND branch_id = $branchId " : "";
$siBranchFilter = $branchId ? " AND si.branch_id = $branchId " : "";
$piBranchFilter = $branchId ? " AND pi.branch_id = $branchId " : "";
$srBranchFilter = $branchId ? " AND sr.branch_id = $branchId " : "";
$imBranchFilter = $branchId ? " AND im.branch_id = $branchId " : "";

// Get current branch name
$currentBranchName = t('g.all_branches');
if ($branchId) {
    foreach ($branches as $b) {
        if ($b['id'] == $branchId) { $currentBranchName = $b['name']; break; }
    }
} elseif (count($branches) == 1) {
    $currentBranchName = $branches[0]['name'];
}

$reportTitles = [
    'daily_sales' => t('dash.daily_sales'),
    'sales_summary' => t('dash.sales_summary'),
    'profits' => t('reports.profit_report'),
    'inventory' => t('products.current_stock'),
    'low_stock' => t('inventory.shortages'),
    'expiring' => t('dash.expiring_soon'),
    'expired' => t('dash.expired'),
    'product_movement' => t('inventory.item_movement'),
    'top_selling' => t('dash.top_selling'),
    'customers' => t('reports.customer_report'),
    'suppliers' => t('reports.supplier_report'),
    'purchases' => t('reports.purchase_report'),
    'returns' => t('sales.returns'),
    'tax' => t('reports.tax_report'),
];
$currentReportTitle = $reportTitles[$report] ?? '';
?>

<style>
/* ========== Classic Report Design ========== */
.rpt-filters { padding:14px 18px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; border-bottom:1px solid #e5e7eb; }
.rpt-filters label { font-size:12px; font-weight:600; color:#374151; }
.rpt-filters .form-control { width:140px; font-size:12px; padding:6px 10px; }
.rpt-filters select.form-control { width:160px; }

.rpt-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:6px; padding:14px 18px; }
.rpt-btn { display:flex; align-items:center; gap:8px; padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; background:#fafafa; color:#1f2937; font-size:12px; font-weight:500; text-decoration:none; cursor:pointer; transition:all 0.15s; text-align:right; }
.rpt-btn:hover { background:#f3f4f6; border-color:#9ca3af; }
.rpt-btn.active { background:#1a2744; color:#fff; border-color:#1a2744; font-weight:700; }
.rpt-btn i { font-size:14px; opacity:0.7; width:18px; text-align:center; }
.rpt-btn.active i { opacity:1; }

/* Print Header */
.rpt-print-header { display:none; text-align:center; padding:20px 20px 14px; border-bottom:2px solid #1a2744; }
.rpt-print-header .co { font-size:18px; font-weight:800; color:#1a2744; margin-bottom:2px; }
.rpt-print-header .br { font-size:13px; font-weight:600; color:#4b5563; margin-bottom:4px; }
.rpt-print-header .ti { font-size:16px; font-weight:700; color:#1a2744; margin:6px 0 4px; }
.rpt-print-header .pe { font-size:11px; color:#6b7280; }
.rpt-print-header .dt { font-size:10px; color:#9ca3af; margin-top:4px; }
.rpt-print-footer { display:none; text-align:center; padding:10px 20px; border-top:1px solid #d1d5db; font-size:9px; color:#9ca3af; margin-top:20px; }

.rpt-actions { display:flex; gap:6px; align-items:center; margin-right:auto; }
.rpt-actions .btn { font-size:11px; padding:5px 12px; border-radius:4px; display:inline-flex; align-items:center; gap:4px; }

/* Classic Table */
.rpt-table { width:100%; border-collapse:collapse; }
.rpt-table thead th { background:#f8f9fa; color:#374151; padding:8px 10px; font-size:11px; font-weight:700; border-bottom:2px solid #d1d5db; text-align:right; }
.rpt-table thead th.center { text-align:center; }
.rpt-table tbody td { padding:7px 10px; font-size:11.5px; border-bottom:1px solid #f0f0f0; color:#374151; }
.rpt-table tbody tr:hover { background:#fafbfc; }
.rpt-table tfoot tr { background:#1a2744; color:#fff; font-weight:700; }
.rpt-table tfoot td { padding:8px 10px; font-size:12px; }

/* Stat cards — monochrome */
.rpt-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:10px; margin-bottom:16px; }
.rpt-stat { border:1px solid #e5e7eb; border-radius:6px; padding:14px 16px; background:#fff; display:flex; justify-content:space-between; align-items:center; }
.rpt-stat .sl { font-size:12px; color:#6b7280; font-weight:500; }
.rpt-stat .sv { font-size:20px; font-weight:700; color:#1a2744; }
.rpt-stat.pos .sv { color:#15803d; }
.rpt-stat.neg .sv { color:#b91c1c; }
.rpt-stat.hl { border-color:#1a2744; border-width:2px; }

.rpt-tax-table { width:100%; border-collapse:collapse; margin-top:12px; }
.rpt-tax-table td { padding:10px 14px; font-size:12px; border-bottom:1px solid #e5e7eb; }
.rpt-tax-table tr:last-child { font-weight:800; font-size:13px; background:#f8f9fa; }

@media print {
    .no-print, .sidebar, .top-bar, .rpt-filters, .rpt-grid { display:none !important; }
    .rpt-print-header, .rpt-print-footer { display:block !important; }
    .card { border:none !important; box-shadow:none !important; margin:0 !important; }
    .card-body { padding:8px 0 !important; }
    body { background:#fff !important; }
    .main-content { margin:0 !important; padding:0 !important; }
    .rpt-table thead th { background:#f0f0f0 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .rpt-table tfoot tr { background:#1a2744 !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .rpt-table thead { display:table-header-group !important; }
    .rpt-table tfoot { display:table-footer-group !important; }
    .rpt-table tbody tr { break-inside:avoid; page-break-inside:avoid; }
    .rpt-stats { break-inside:avoid; page-break-inside:avoid; }
    .rpt-actions { display:none !important; }
    @page { size:A4; margin:12mm 10mm; }
}
</style>

<div class="card no-print">
    <div class="rpt-filters">
        <label><?= t('from') ?>:</label><input type="date" id="fFrom" value="<?= $fromDate ?>" class="form-control">
        <label><?= t('to') ?>:</label><input type="date" id="fTo" value="<?= $toDate ?>" class="form-control">
        <?php if ($isMainBranch && count($branches) > 1): ?>
        <label><?= t('g.branch') ?>:</label>
        <select id="fBranch" class="form-control">
            <option value=""><?= t('g.all_branches') ?></option>
            <?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>" <?= $branchId == $b['id'] ? 'selected' : '' ?>><?= $b['name'] ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($report): ?>
        <div class="rpt-actions">
            <button class="btn btn-sm" style="background:#1a2744;color:#fff;" onclick="window.print()"><i class="fas fa-print"></i><?= t('print') ?></button>
            <?php
            $exportTypes = [
                'daily_sales'=>'sales','sales_summary'=>'sales','profits'=>'profits',
                'inventory'=>'products','low_stock'=>'low_stock','expiring'=>'expiring',
                'top_selling'=>'top_selling','customers'=>'customers','suppliers'=>'suppliers',
                'purchases'=>'purchases','returns'=>'returns','expenses'=>'expenses'
            ];
            if (isset($exportTypes[$report])): $etype = $exportTypes[$report]; ?>
            <a class="btn btn-sm" style="background:#16a34a;color:#fff;font-weight:600;" href="export.php?type=<?= $etype ?>&format=xlsx&from=<?= $fromDate ?>&to=<?= $toDate ?>&branch_id=<?= $branchId ?>"><i class="fas fa-file-excel"></i> Excel</a>
            <a class="btn btn-sm" style="background:#dc2626;color:#fff;font-weight:600;" href="export.php?type=<?= $etype ?>&format=print&from=<?= $fromDate ?>&to=<?= $toDate ?>&branch_id=<?= $branchId ?>"><i class="fas fa-file-pdf"></i> PDF</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="rpt-grid">
        <?php
        $reports = [
            'daily_sales' => [t('dash.daily_sales'), 'fas fa-calendar-day'],
            'sales_summary' => [t('dash.sales_summary'), 'fas fa-file-invoice-dollar'],
            'profits' => [t('reports.profit_report'), 'fas fa-chart-line'],
            'inventory' => [t('products.current_stock'), 'fas fa-boxes'],
            'low_stock' => [t('inventory.shortages'), 'fas fa-exclamation-triangle'],
            'expiring' => [t('dash.expiring_soon'), 'fas fa-calendar-times'],
            'expired' => [t('dash.expired'), 'fas fa-ban'],
            'product_movement' => [t('inventory.item_movement'), 'fas fa-exchange-alt'],
            'top_selling' => [t('dash.top_selling'), 'fas fa-fire'],
            'customers' => [t('reports.customer_report'), 'fas fa-user-tie'],
            'suppliers' => [t('reports.supplier_report'), 'fas fa-truck'],
            'purchases' => [t('reports.purchase_report'), 'fas fa-shopping-cart'],
            'returns' => [t('sales.returns'), 'fas fa-undo'],
            'tax' => [t('reports.tax_report'), 'fas fa-percent'],
        ];
        foreach ($reports as $key => $info): ?>
        <a href="javascript:void(0)" onclick="loadReport('<?= $key ?>')" class="rpt-btn <?= $report === $key ? 'active' : '' ?>">
            <i class="<?= $info[1] ?>"></i> <?= $info[0] ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<script>
function loadReport(r) {
    const f = document.getElementById('fFrom').value;
    const t = document.getElementById('fTo').value;
    const b = document.getElementById('fBranch')?.value || '';
    window.location.href = `reports.php?report=${r}&from=${f}&to=${t}&branch_id=${b}`;
}
</script>

<?php if ($report): ?>
<div class="rpt-print-header">
    <div class="co"><?= htmlspecialchars($company['company_name'] ?? t('app_name')) ?></div>
    <div class="br"><?= htmlspecialchars($currentBranchName) ?></div>
    <div class="ti"><?= $currentReportTitle ?></div>
    <div class="pe"><?= t('prt.period') ?> <?= $fromDate ?> — <?= $toDate ?></div>
    <div class="dt"><?= t('prt.generated_at') ?>: <?= date('Y-m-d H:i') ?></div>
</div>

<div class="card">
<?php
switch ($report):

case 'daily_sales': ?>
<div class="card-header no-print"><h3><i class="fas fa-calendar-day"></i><?= t('dash.daily_sales') ?></h3></div>
<div class="card-body">
<?php
$dailySales = $pdo->prepare("SELECT invoice_date, COUNT(*) as cnt, SUM(grand_total) as total, SUM(vat_amount) as vat, SUM(discount) as discount FROM sales_invoices WHERE tenant_id = $tid AND status IN ('completed','partial_return') AND invoice_date BETWEEN ? AND ? $branchFilter GROUP BY invoice_date ORDER BY invoice_date DESC");
$dailySales->execute([$fromDate, $toDate]); $days = $dailySales->fetchAll();
?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('date') ?></th><th class="center"><?= t('sales.invoice_count') ?></th><th class="center"><?= t('discount') ?></th><th class="center"><?= t('tax') ?></th><th class="center"><?= t('total') ?></th></tr></thead>
<tbody>
<?php foreach ($days as $d): ?>
<tr><td><?= $d['invoice_date'] ?></td><td style="text-align:center;"><?= $d['cnt'] ?></td><td style="text-align:center;color:#b91c1c;"><?= formatMoney($d['discount']) ?></td><td style="text-align:center;"><?= formatMoney($d['vat']) ?></td><td style="text-align:center;font-weight:700;"><?= formatMoney($d['total']) ?></td></tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td><?= t('total') ?></td><td style="text-align:center;"><?= array_sum(array_column($days,'cnt')) ?></td><td style="text-align:center;"><?= formatMoney(array_sum(array_column($days,'discount'))) ?></td><td style="text-align:center;"><?= formatMoney(array_sum(array_column($days,'vat'))) ?></td><td style="text-align:center;font-size:13px;"><?= formatMoney(array_sum(array_column($days,'total'))) ?><?= t('sar') ?></td></tr></tfoot>
</table></div></div>
<?php break;

case 'sales_summary': ?>
<div class="card-header no-print"><h3><i class="fas fa-file-invoice-dollar"></i><?= t('dash.sales_summary') ?></h3></div>
<div class="card-body">
<?php
$salesSummary = $pdo->prepare("SELECT si.*, c.name as customer_name, u.full_name as cashier FROM sales_invoices si LEFT JOIN customers c ON c.id = si.customer_id LEFT JOIN users u ON u.id = si.created_by WHERE si.tenant_id = $tid AND si.invoice_date BETWEEN ? AND ? $siBranchFilter ORDER BY si.id DESC");
$salesSummary->execute([$fromDate, $toDate]); $invoices = $salesSummary->fetchAll();
?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('sales.invoice_number') ?></th><th><?= t('date') ?></th><th><?= t('sales.customer') ?></th><th><?= t('sales.payment_method') ?></th><th><?= t('status') ?></th><th class="center"><?= t('total') ?></th><th><?= t('users.cashier') ?></th></tr></thead>
<tbody>
<?php foreach ($invoices as $inv): ?>
<tr><td><strong><?= $inv['invoice_number'] ?></strong></td><td><?= $inv['invoice_date'] ?></td><td dir="auto"><?= $inv['customer_name'] ?? t('sales.cash_customer') ?></td><td><?= paymentTypeBadge($inv['payment_type']) ?></td><td><span class="badge <?= $inv['status']==='completed'?'badge-success':($inv['status']==='returned'?'badge-danger':'badge-warning') ?>"><?= ['completed'=>'مكتملة','returned'=>'مرتجعة','partial_return'=>'مرتجع جزئي','held'=>'معلقة'][$inv['status']] ?? $inv['status'] ?></span></td><td style="text-align:center;font-weight:700;"><?= formatMoney($inv['grand_total']) ?></td><td dir="auto" style="color:#6b7280;font-size:12px;"><?= $inv['cashier'] ?? '-' ?></td></tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="5"><?= t('total') ?> (<?= count($invoices) ?> )</td><td style="text-align:center;font-size:13px;"><?= formatMoney(array_sum(array_column($invoices,'grand_total'))) ?></td><td></td></tr></tfoot>
</table></div></div>
<?php break;

case 'profits': ?>
<div class="card-header no-print"><h3><i class="fas fa-chart-line"></i><?= t('accounting.profit_report') ?></h3></div>
<div class="card-body">
<?php
$profitData = $pdo->prepare("SELECT sii.product_name, p2.name_en as product_name_en, SUM(sii.quantity) as qty, SUM(sii.quantity * sii.unit_price) as revenue, SUM(sii.quantity * sii.cost_price) as cost FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id LEFT JOIN products p2 ON p2.id = sii.product_id WHERE si.tenant_id = $tid AND si.status IN ('completed','partial_return') AND si.invoice_date BETWEEN ? AND ? $siBranchFilter GROUP BY sii.product_name, p2.name_en ORDER BY (SUM(sii.quantity * sii.unit_price) - SUM(sii.quantity * sii.cost_price)) DESC");
$profitData->execute([$fromDate, $toDate]); $items = $profitData->fetchAll();
$tRev = array_sum(array_column($items,'revenue')); $tCost = array_sum(array_column($items,'cost')); $tProfit = $tRev - $tCost;
?>
<div class="rpt-stats">
    <div class="rpt-stat pos"><div><div class="sl"><?= t('accounting.revenue') ?></div><div class="sv"><?= formatMoney($tRev) ?></div></div></div>
    <div class="rpt-stat neg"><div><div class="sl"><?= t('sales.cost') ?></div><div class="sv"><?= formatMoney($tCost) ?></div></div></div>
    <div class="rpt-stat hl <?= $tProfit>=0?'pos':'neg' ?>"><div><div class="sl"><?= t('sales.profit') ?> (<?= $tRev > 0 ? round($tProfit/$tRev*100,1) : 0 ?>%)</div><div class="sv"><?= formatMoney($tProfit) ?></div></div></div>
</div>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('item') ?></th><th class="center"><?= t('quantity') ?></th><th class="center"><?= t('sales.revenue') ?></th><th class="center"><?= t('sales.cost') ?></th><th class="center"><?= t('sales.profit') ?></th><th class="center"><?= t('products.profit_margin') ?></th></tr></thead>
<tbody>
<?php foreach ($items as $it): $profit = $it['revenue'] - $it['cost']; ?>
<tr><td dir="auto"><?= displayName($it, 'product_name', 'product_name_en') ?></td><td style="text-align:center;"><?= $it['qty'] ?></td><td style="text-align:center;"><?= formatMoney($it['revenue']) ?></td><td style="text-align:center;"><?= formatMoney($it['cost']) ?></td><td style="text-align:center;font-weight:700;color:<?= $profit>=0?'#15803d':'#b91c1c' ?>;"><?= formatMoney($profit) ?></td><td style="text-align:center;"><?= $it['revenue'] > 0 ? round($profit/$it['revenue']*100,1) : 0 ?>%</td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php break;

case 'inventory': ?>
<div class="card-header no-print"><h3><i class="fas fa-boxes"></i> <?= t('products.current_stock') ?></h3></div>
<div class="card-body">
<?php $inv = $pdo->query("SELECT p.*, c.name as cat_name, (p.stock_qty * p.cost_price) as stock_value FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.tenant_id = $tid AND p.branch_id = $bid AND p.is_active = 1 ORDER BY p.name")->fetchAll(); $totalValue = array_sum(array_column($inv, 'stock_value')); ?>
<div class="rpt-stats" style="margin-bottom:14px;">
    <div class="rpt-stat hl"><div><div class="sl"><?= t('g.inventory_value') ?></div><div class="sv"><?= formatMoney($totalValue) ?> <span style="font-size:12px;color:#6b7280;font-weight:500;"><?= t('sar') ?></span></div></div><div style="font-size:11px;color:#6b7280;"><?= count($inv) ?><?= t('item') ?></div></div>
</div>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('products.barcode') ?></th><th><?= t('item') ?></th><th><?= t('products.category') ?></th><th class="center"><?= t('quantity') ?></th><th class="center"><?= t('sales.cost') ?></th><th class="center"><?= t('products.sell_price') ?></th><th class="center"><?= t('amount') ?></th><th><?= t('status') ?></th></tr></thead>
<tbody>
<?php foreach ($inv as $p): ?>
<tr><td><code style="font-size:11px;"><?= $p['barcode'] ?></code></td><td dir="auto"><strong><?= displayName($p, 'name', 'name_en') ?></strong></td><td style="color:#6b7280;font-size:12px;"><?= $p['cat_name'] ?? '-' ?></td><td style="text-align:center;font-weight:700;"><?= $p['stock_qty'] ?></td><td style="text-align:center;"><?= formatMoney($p['cost_price']) ?></td><td style="text-align:center;"><?= formatMoney($p['unit_price']) ?></td><td style="text-align:center;font-weight:600;"><?= formatMoney($p['stock_value']) ?></td><td><?= $p['stock_qty'] == 0 ? '<span class="badge badge-danger">' . t('dash.out_of_stock') . '</span>' : ($p['stock_qty'] <= $p['min_stock'] ? '<span class="badge badge-warning">' . t('dash.low_stock') . '</span>' : '<span class="badge badge-success">' . t('available') . '</span>') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php break;

case 'low_stock': ?>
<div class="card-header no-print"><h3><i class="fas fa-exclamation-triangle"></i> <?= t('inventory.shortages') ?></h3></div>
<div class="card-body">
<?php $lowItems = $pdo->query("SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.tenant_id = $tid AND p.branch_id = $bid AND p.is_active = 1 AND p.stock_qty <= p.min_stock ORDER BY p.stock_qty")->fetchAll(); ?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('item') ?></th><th><?= t('products.category') ?></th><th class="center"><?= t('available') ?></th><th class="center"><?= t('products.min_stock') ?></th><th class="center"><?= t('products.reorder_point') ?></th><th><?= t('status') ?></th></tr></thead>
<tbody><?php foreach ($lowItems as $p): ?>
<tr><td dir="auto"><strong><?= displayName($p, 'name', 'name_en') ?></strong></td><td style="color:#6b7280;font-size:12px;"><?= $p['cat_name'] ?? '-' ?></td><td style="text-align:center;font-weight:700;color:<?= $p['stock_qty']==0?'#b91c1c':'#d97706' ?>;"><?= $p['stock_qty'] ?></td><td style="text-align:center;"><?= $p['min_stock'] ?></td><td style="text-align:center;"><?= $p['reorder_point'] ?></td><td><?= $p['stock_qty'] == 0 ? '<span class="badge badge-danger">' . t('dash.out_of_stock') . '</span>' : '<span class="badge badge-warning">' . t('dash.low_stock') . '</span>' ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php break;

case 'expiring': ?>
<div class="card-header no-print"><h3><i class="fas fa-calendar-times"></i> <?= t('dash.expiring_soon') ?></h3></div>
<div class="card-body">
<?php $expiring = $pdo->query("SELECT ib.*, p.name as product_name, p.name_en as product_name_en, p.barcode FROM inventory_batches ib JOIN products p ON p.id = ib.product_id WHERE ib.tenant_id = $tid AND ib.branch_id = $bid AND ib.available_qty > 0 AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY ib.expiry_date")->fetchAll(); ?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('item') ?></th><th><?= t('inventory.batch_number') ?></th><th class="center"><?= t('quantity') ?></th><th><?= t('inventory.expiry_date') ?></th><th class="center"><?= t('remaining') ?></th></tr></thead>
<tbody><?php foreach ($expiring as $b): $dl = max(0, round((strtotime($b['expiry_date']) - time()) / 86400)); ?>
<tr><td dir="auto"><strong><?= displayName($b, 'product_name', 'product_name_en') ?></strong></td><td><?= $b['batch_number'] ?></td><td style="text-align:center;font-weight:700;"><?= $b['available_qty'] ?></td><td><?= $b['expiry_date'] ?></td><td style="text-align:center;font-weight:700;color:<?= $dl < 30 ? '#b91c1c' : '#d97706' ?>;"><?= $dl ?><?= t('day') ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php break;

case 'expired': ?>
<div class="card-header no-print"><h3><i class="fas fa-ban"></i> <?= t('dash.expired') ?></h3></div>
<div class="card-body">
<?php $expired = $pdo->query("SELECT ib.*, p.name as product_name, p.name_en as product_name_en FROM inventory_batches ib JOIN products p ON p.id = ib.product_id WHERE ib.tenant_id = $tid AND ib.branch_id = $bid AND ib.available_qty > 0 AND ib.expiry_date < CURDATE() ORDER BY ib.expiry_date")->fetchAll(); ?>
<?php if (empty($expired)): ?>
<div style="text-align:center;padding:40px;color:#6b7280;"><i class="fas fa-check-circle" style="font-size:36px;display:block;margin-bottom:8px;color:#15803d;"></i><?= t('no_results') ?></div>
<?php else: ?>
<div style="background:#fef2f2;padding:8px 14px;border-radius:6px;margin-bottom:12px;color:#b91c1c;font-weight:700;font-size:12px;border:1px solid #fecaca;"><?= count($expired) ?> <?= t('inventory.expired_stock') ?></div>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('item') ?></th><th><?= t('inventory.batch_number') ?></th><th class="center"><?= t('quantity') ?></th><th><?= t('inventory.expiry_date') ?></th><th><?= t('reports.since_label') ?></th></tr></thead>
<tbody><?php foreach ($expired as $b): ?>
<tr><td dir="auto"><strong><?= displayName($b, 'product_name', 'product_name_en') ?></strong></td><td><?= $b['batch_number'] ?></td><td style="text-align:center;font-weight:700;"><?= $b['available_qty'] ?></td><td><?= $b['expiry_date'] ?></td><td style="color:#b91c1c;font-weight:700;"><?= round((time() - strtotime($b['expiry_date'])) / 86400) ?><?= t('day') ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?></div>
<?php break;

case 'product_movement': ?>
<div class="card-header no-print"><h3><i class="fas fa-exchange-alt"></i> <?= t('inventory.item_movement') ?></h3></div>
<div class="card-body">
<?php $productId = intval($_GET['product_id'] ?? 0); $allProducts = $pdo->query("SELECT id, name, name_en, barcode FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active = 1 ORDER BY name")->fetchAll(); ?>
<form method="GET" class="no-print" style="display:flex;gap:8px;align-items:center;margin-bottom:15px;">
    <input type="hidden" name="report" value="product_movement"><input type="hidden" name="from" value="<?= $fromDate ?>"><input type="hidden" name="to" value="<?= $toDate ?>">
    <select name="product_id" class="form-control" style="width:300px;" required><option value=""><?= t('g.select_item') ?></option><?php foreach ($allProducts as $p): ?><option value="<?= $p['id'] ?>" <?= $productId == $p['id'] ? 'selected' : '' ?>><?= displayName($p, 'name', 'name_en') ?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-sm" style="background:#1a2744;color:#fff;"><i class="fas fa-search"></i></button>
</form>
<?php if ($productId):
    $movements = $pdo->prepare("SELECT im.*, u.full_name as user_name FROM inventory_movements im LEFT JOIN users u ON u.id = im.created_by WHERE im.tenant_id = $tid $imBranchFilter AND im.product_id = ? AND im.created_at BETWEEN ? AND ? ORDER BY im.created_at DESC");
    $movements->execute([$productId, "$fromDate 00:00:00", "$toDate 23:59:59"]); $movList = $movements->fetchAll();
    $ml = ['sale'=>t('tx.sale'),'purchase'=>t('tx.purchase'),'return_sale'=>'مرتجع بيع','return_purchase'=>'مرتجع شراء','adjustment'=>t('tx.adjustment'),'transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر','damage'=>t('tx.damage')];
?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('date') ?></th><th><?= t('type') ?></th><th class="center"><?= t('quantity') ?></th><th><?= t('users.user') ?></th><th><?= t('notes') ?></th></tr></thead>
<tbody><?php foreach ($movList as $m): $isIn = in_array($m['movement_type'],['purchase','return_sale','transfer_in']); ?>
<tr><td><?= date('Y-m-d H:i', strtotime($m['created_at'])) ?></td><td><span style="background:<?= $isIn?'#f0fdf4':'#fef2f2' ?>;color:<?= $isIn?'#15803d':'#b91c1c' ?>;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;"><?= $ml[$m['movement_type']] ?? $m['movement_type'] ?></span></td><td style="text-align:center;font-weight:700;color:<?= $isIn?'#15803d':'#b91c1c' ?>;"><?= $isIn?'+':'-' ?><?= $m['quantity'] ?></td><td dir="auto" style="font-size:12px;"><?= $m['user_name'] ?? '-' ?></td><td style="font-size:12px;color:#6b7280;"><?= $m['notes'] ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?></div>
<?php break;

case 'top_selling': ?>
<div class="card-header no-print"><h3><i class="fas fa-fire"></i> <?= t('dash.top_selling') ?></h3></div>
<div class="card-body">
<?php $topSelling = $pdo->prepare("SELECT sii.product_name, p2.name_en as product_name_en, SUM(sii.quantity) as total_qty, SUM(sii.total_amount) as total_amount, COUNT(DISTINCT sii.invoice_id) as inv_cnt FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id LEFT JOIN products p2 ON p2.id = sii.product_id WHERE si.tenant_id = $tid AND si.status IN ('completed','partial_return') AND si.invoice_date BETWEEN ? AND ? $siBranchFilter GROUP BY sii.product_name, p2.name_en ORDER BY total_qty DESC LIMIT 30"); $topSelling->execute([$fromDate, $toDate]); $topItems = $topSelling->fetchAll(); ?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th>#</th><th><?= t('item') ?></th><th class="center"><?= t('quantity') ?></th><th class="center"><?= t('invoices') ?></th><th class="center"><?= t('sales.revenue') ?></th></tr></thead>
<tbody><?php foreach ($topItems as $i => $t): ?>
<tr><td style="font-weight:700;color:#6b7280;"><?= $i+1 ?></td><td dir="auto"><strong><?= displayName($t, 'product_name', 'product_name_en') ?></strong></td><td style="text-align:center;font-weight:700;"><?= $t['total_qty'] ?></td><td style="text-align:center;"><?= $t['inv_cnt'] ?></td><td style="text-align:center;font-weight:700;"><?= formatMoney($t['total_amount']) ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php break;

case 'customers': ?>
<div class="card-header no-print"><h3><i class="fas fa-user-tie"></i><?= t('reports.customer_report') ?></h3></div>
<div class="card-body">
<?php $customers = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM sales_invoices WHERE tenant_id = $tid $branchFilter AND customer_id = c.id) as inv_cnt, (SELECT COALESCE(SUM(grand_total),0) FROM sales_invoices WHERE tenant_id = $tid $branchFilter AND customer_id = c.id) as total_spent FROM customers c ORDER BY total_spent DESC")->fetchAll(); ?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('sales.customer') ?></th><th><?= t('phone') ?></th><th class="center"><?= t('invoices') ?></th><th class="center"><?= t('purchases.title') ?></th><th class="center"><?= t('balance') ?></th><th class="center"><?= t('customers.points') ?></th></tr></thead>
<tbody><?php foreach ($customers as $c): ?>
<tr><td><strong><?= htmlspecialchars($c['name']) ?></strong></td><td><?= $c['phone'] ?></td><td style="text-align:center;"><?= $c['inv_cnt'] ?></td><td style="text-align:center;font-weight:700;"><?= formatMoney($c['total_spent']) ?></td><td style="text-align:center;color:<?= $c['balance']>0?'#b91c1c':'#15803d' ?>;"><?= formatMoney($c['balance']) ?></td><td style="text-align:center;font-weight:600;"><?= $c['loyalty_points'] ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php break;

case 'suppliers': ?>
<div class="card-header no-print"><h3><i class="fas fa-truck"></i><?= t('reports.supplier_report') ?></h3></div>
<div class="card-body">
<?php $suppliers = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM purchase_invoices WHERE tenant_id = $tid $branchFilter AND supplier_id = s.id) as inv_cnt, (SELECT COALESCE(SUM(grand_total),0) FROM purchase_invoices WHERE tenant_id = $tid $branchFilter AND supplier_id = s.id) as total_purch FROM suppliers s ORDER BY total_purch DESC")->fetchAll(); ?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('purchases.supplier') ?></th><th><?= t('phone') ?></th><th><?= t('customers.contact_person') ?></th><th class="center"><?= t('invoices') ?></th><th class="center"><?= t('purchases.title') ?></th><th class="center"><?= t('g.receivables') ?></th></tr></thead>
<tbody><?php foreach ($suppliers as $s): ?>
<tr><td><strong><?= htmlspecialchars($s['name']) ?></strong></td><td><?= $s['phone'] ?></td><td style="color:#6b7280;"><?= $s['contact_person'] ?></td><td style="text-align:center;"><?= $s['inv_cnt'] ?></td><td style="text-align:center;font-weight:700;"><?= formatMoney($s['total_purch']) ?></td><td style="text-align:center;color:<?= $s['balance']>0?'#b91c1c':'#15803d' ?>;font-weight:700;"><?= formatMoney($s['balance']) ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php break;

case 'purchases': ?>
<div class="card-header no-print"><h3><i class="fas fa-shopping-cart"></i><?= t('reports.purchase_report') ?></h3></div>
<div class="card-body">
<?php $pList = $pdo->prepare("SELECT pi.*, s.name as supplier_name FROM purchase_invoices pi LEFT JOIN suppliers s ON s.id = pi.supplier_id WHERE pi.tenant_id = $tid AND pi.invoice_date BETWEEN ? AND ? $piBranchFilter ORDER BY pi.id DESC"); $pList->execute([$fromDate, $toDate]); $pList = $pList->fetchAll(); ?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('invoice') ?></th><th><?= t('date') ?></th><th><?= t('purchases.supplier') ?></th><th><?= t('sales.payment_method') ?></th><th class="center"><?= t('total') ?></th><th class="center"><?= t('sales.paid_amount') ?></th><th class="center"><?= t('remaining') ?></th></tr></thead>
<tbody><?php foreach ($pList as $p): ?>
<tr><td><strong><?= $p['invoice_number'] ?></strong></td><td><?= $p['invoice_date'] ?></td><td dir="auto"><?= $p['supplier_name'] ?? '-' ?></td><td><?= paymentTypeBadge($p['payment_type']) ?></td><td style="text-align:center;font-weight:700;"><?= formatMoney($p['grand_total']) ?></td><td style="text-align:center;color:#15803d;"><?= formatMoney($p['paid_amount']) ?></td><td style="text-align:center;color:<?= $p['remaining_amount']>0?'#b91c1c':'#15803d' ?>;"><?= formatMoney($p['remaining_amount']) ?></td></tr>
<?php endforeach; ?></tbody>
<tfoot><tr><td colspan="4"><?= count($pList) ?><?= t('invoice') ?></td><td style="text-align:center;"><?= formatMoney(array_sum(array_column($pList,'grand_total'))) ?></td><td style="text-align:center;"><?= formatMoney(array_sum(array_column($pList,'paid_amount'))) ?></td><td style="text-align:center;"><?= formatMoney(array_sum(array_column($pList,'remaining_amount'))) ?></td></tr></tfoot>
</table></div></div>
<?php break;

case 'returns': ?>
<div class="card-header no-print"><h3><i class="fas fa-undo"></i> <?= t('sales.returns') ?></h3></div>
<div class="card-body">
<?php $retList = $pdo->prepare("SELECT sr.*, si.invoice_number, u.full_name as user_name FROM sales_returns sr JOIN sales_invoices si ON si.id = sr.invoice_id LEFT JOIN users u ON u.id = sr.created_by WHERE sr.tenant_id = $tid $srBranchFilter AND sr.return_date BETWEEN ? AND ? ORDER BY sr.id DESC"); $retList->execute([$fromDate, $toDate]); $retList = $retList->fetchAll(); ?>
<div class="table-responsive"><table class="rpt-table">
<thead><tr><th><?= t('g.return_number') ?></th><th><?= t('invoice') ?></th><th><?= t('date') ?></th><th><?= t('g.reason') ?></th><th class="center"><?= t('amount') ?></th><th><?= t('users.user') ?></th></tr></thead>
<tbody><?php foreach ($retList as $r): ?>
<tr><td><strong><?= $r['return_number'] ?></strong></td><td><?= $r['invoice_number'] ?></td><td><?= $r['return_date'] ?></td><td style="color:#6b7280;font-size:12px;"><?= $r['reason'] ?? '-' ?></td><td style="text-align:center;font-weight:700;color:#b91c1c;"><?= formatMoney($r['total_amount']) ?></td><td dir="auto" style="font-size:12px;"><?= $r['user_name'] ?? '-' ?></td></tr>
<?php endforeach; ?></tbody>
<tfoot><tr><td colspan="4"><?= count($retList) ?><?= t('sales.returns') ?></td><td style="text-align:center;"><?= formatMoney(array_sum(array_column($retList,'total_amount'))) ?></td><td></td></tr></tfoot>
</table></div></div>
<?php break;

case 'tax': ?>
<div class="card-header no-print"><h3><i class="fas fa-percent"></i><?= t('accounting.tax_report') ?></h3></div>
<div class="card-body">
<?php
$vOut = $pdo->prepare("SELECT COALESCE(SUM(vat_amount),0) FROM sales_invoices WHERE tenant_id = $tid $branchFilter AND status IN ('completed','partial_return') AND invoice_date BETWEEN ? AND ?"); $vOut->execute([$fromDate,$toDate]); $vatOut = floatval($vOut->fetchColumn());
$vIn = $pdo->prepare("SELECT COALESCE(SUM(vat_amount),0) FROM purchase_invoices WHERE tenant_id = $tid $branchFilter AND invoice_date BETWEEN ? AND ?"); $vIn->execute([$fromDate,$toDate]); $vatIn = floatval($vIn->fetchColumn());
$vExp = $pdo->prepare("SELECT COALESCE(SUM(vat_amount),0) FROM expenses WHERE tenant_id = $tid $branchFilter AND expense_date BETWEEN ? AND ?"); $vExp->execute([$fromDate,$toDate]); $vatInExp = floatval($vExp->fetchColumn());
$totalVatIn = $vatIn + $vatInExp; $vatDue = $vatOut - $totalVatIn;
?>
<div class="rpt-stats" style="margin-bottom:16px;">
    <div class="rpt-stat neg"><div><div class="sl"><?= t('g.vat_collected') ?></div><div class="sv"><?= formatMoney($vatOut) ?></div></div></div>
    <div class="rpt-stat pos"><div><div class="sl"><?= t('g.vat_paid') ?></div><div class="sv"><?= formatMoney($totalVatIn) ?></div></div></div>
    <div class="rpt-stat hl <?= $vatDue>=0?'neg':'pos' ?>"><div><div class="sl"><strong><?= $vatDue>=0 ? t('accounting.net_due') : t('accounting.in_favor') ?></strong></div><div class="sv"><?= formatMoney(abs($vatDue)) ?></div></div></div>
</div>
<table class="rpt-tax-table">
<tr><td style="font-weight:700;"><?= t('g.vat_collected') ?></td><td style="text-align:center;width:35%;"><?= formatMoney($vatOut) ?></td></tr>
<tr><td>(-) <?= t('g.vat_paid') ?></td><td style="text-align:center;"><?= formatMoney($vatIn) ?></td></tr>
<tr><td>(-) <?= t('g.vat_paid') ?></td><td style="text-align:center;"><?= formatMoney($vatInExp) ?></td></tr>
<tr><td><?= $vatDue>=0 ? t('accounting.net_due') : t('accounting.in_favor') ?></td><td style="text-align:center;color:<?= $vatDue>=0?'#b91c1c':'#15803d' ?>;font-weight:800;"><?= formatMoney(abs($vatDue)) ?><?= t('sar') ?></td></tr>
</table></div>
<?php break;

default: ?>
<div class="card-body" style="text-align:center;padding:40px;color:#9ca3af;"><i class="fas fa-chart-pie" style="font-size:50px;margin-bottom:10px;display:block;"></i><h3><?= t('g.choose_report') ?></h3></div>
<?php break;
endswitch; ?>
</div>

<div class="rpt-print-footer"><?= htmlspecialchars($company['company_name'] ?? t('app_name')) ?> — <?= $currentBranchName ?> — <?= date('Y-m-d H:i') ?></div>

<?php else: ?>
<div class="card"><div class="card-body" style="text-align:center;padding:50px;color:#9ca3af;">
    <i class="fas fa-chart-pie" style="font-size:50px;margin-bottom:15px;display:block;opacity:0.3;"></i>
    <h3 style="color:#6b7280;"><?= t('g.reports_center') ?></h3>
    <p style="font-size:13px;"><?= t('g.choose_report_desc') ?></p>
</div></div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
