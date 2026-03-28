<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.profit_loss');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('reports_view');
$company = getCompanySettings($pdo);

$period = $_GET['period'] ?? 'monthly';
$customFrom = $_GET['from'] ?? date('Y-m-01');
$customTo = $_GET['to'] ?? date('Y-m-d');

if ($period == 'weekly') { $fromDate = date('Y-m-d', strtotime('monday this week')); $toDate = date('Y-m-d', strtotime('sunday this week')); $periodLabel = t('this_week'); }
elseif ($period == 'monthly') { $fromDate = date('Y-m-01'); $toDate = date('Y-m-t'); $periodLabel = t('this_month') . ' (' . date('Y-m') . ')'; }
elseif ($period == 'yearly') { $fromDate = date('Y-01-01'); $toDate = date('Y-12-31'); $periodLabel = t('this_year') . ' ' . date('Y'); }
elseif ($period == 'all') { $fromDate = '2000-01-01'; $toDate = '2099-12-31'; $periodLabel = t('since_start'); }
else { $fromDate = $customFrom; $toDate = $customTo; $periodLabel = t('from') . " $fromDate " . t('to') . " $toDate"; }

// المبيعات
$sales = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) as total, COALESCE(SUM(vat_amount),0) as vat, COALESCE(SUM(net_total),0) as net, COUNT(*) as cnt FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND status IN ('completed','partial_return') AND invoice_date BETWEEN ? AND ?");
$sales->execute([$fromDate, $toDate]); $salesData = $sales->fetch();

// تكلفة البضاعة المباعة
$cogs = $pdo->prepare("SELECT COALESCE(SUM(si.cost_price * si.quantity),0) as total FROM sales_invoice_items si JOIN sales_invoices s ON s.id = si.invoice_id WHERE s.tenant_id = $tid AND s.branch_id = $bid AND s.status IN ('completed','partial_return') AND s.invoice_date BETWEEN ? AND ?");
$cogs->execute([$fromDate, $toDate]); $cogsTotal = floatval($cogs->fetch()['total']);

// مرتجعات المبيعات
$salesReturns = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_returns WHERE tenant_id = $tid AND branch_id = $bid AND return_date BETWEEN ? AND ?");
$salesReturns->execute([$fromDate, $toDate]); $salesReturnsTotal = floatval($salesReturns->fetchColumn());

// المشتريات
$purchases = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) as total, COALESCE(SUM(vat_amount),0) as vat, COALESCE(SUM(net_total),0) as net FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND invoice_date BETWEEN ? AND ?");
$purchases->execute([$fromDate, $toDate]); $purchasesData = $purchases->fetch();

// المصروفات حسب التصنيف
$expenses = $pdo->prepare("SELECT category, COALESCE(SUM(total),0) as total FROM expenses WHERE tenant_id = $tid AND branch_id = $bid AND expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$expenses->execute([$fromDate, $toDate]); $expensesList = $expenses->fetchAll();
$totalExpenses = array_sum(array_column($expensesList, 'total'));

// سندات الصرف (المصروفات التشغيلية)
$payVouchers = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE tenant_id = $tid AND branch_id = $bid AND voucher_date BETWEEN ? AND ?");
$payVouchers->execute([$fromDate, $toDate]); $payVouchersTotal = floatval($payVouchers->fetchColumn());

// سندات القبض
$recVouchers = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM receipt_vouchers WHERE tenant_id = $tid AND branch_id = $bid AND voucher_date BETWEEN ? AND ?");
$recVouchers->execute([$fromDate, $toDate]); $recVouchersTotal = floatval($recVouchers->fetchColumn());

// حسابات
// مرتجعات المشتريات
$purchaseReturns = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_returns WHERE tenant_id = $tid AND branch_id = $bid AND return_date BETWEEN ? AND ?");
$purchaseReturns->execute([$fromDate, $toDate]); $purchaseReturnsTotal = floatval($purchaseReturns->fetchColumn());

// إيرادات وتكاليف التصنيع
$mfgOrders = $pdo->prepare("SELECT COALESCE(SUM(selling_price * quantity),0) as revenue, COALESCE(SUM(total_cost),0) as costs FROM manufacturing_orders WHERE tenant_id = $tid AND branch_id = $bid AND status = 'completed' AND completed_at BETWEEN ? AND ?");
$mfgOrders->execute([$fromDate, $toDate . ' 23:59:59']); $mfgData = $mfgOrders->fetch();
$mfgRevenue = floatval($mfgData['revenue']);
$mfgCosts = floatval($mfgData['costs']);

$netSales = floatval($salesData['net']) - $salesReturnsTotal;
$totalRevenue = $netSales + $mfgRevenue;
$totalCOGS = $cogsTotal + $mfgCosts - $purchaseReturnsTotal;
$grossProfit = $totalRevenue - $totalCOGS;
$operatingExpenses = $totalExpenses + $payVouchersTotal;
$netProfit = $grossProfit - $operatingExpenses;

// الضريبة
$vatCollected = floatval($salesData['vat']);
$vatPaid = floatval($purchasesData['vat']);
$vatDue = $vatCollected - $vatPaid;
?>

<div class="card no-print">
    <div class="card-body" style="padding:12px;">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <a href="?period=weekly" class="btn <?= $period=='weekly'?'btn-primary':'btn-secondary' ?>"><i class="fas fa-calendar-week"></i> <?= t('g.weekly') ?></a>
            <a href="?period=monthly" class="btn <?= $period=='monthly'?'btn-primary':'btn-secondary' ?>"><i class="fas fa-calendar-alt"></i> <?= t('g.monthly') ?></a>
            <a href="?period=yearly" class="btn <?= $period=='yearly'?'btn-primary':'btn-secondary' ?>"><i class="fas fa-calendar"></i> <?= t('g.yearly') ?></a>
            <a href="?period=all" class="btn <?= $period=='all'?'btn-primary':'btn-secondary' ?>"><i class="fas fa-globe"></i> <?= t('all') ?></a>
            <form method="GET" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="period" value="custom">
                <input type="date" name="from" class="form-control" value="<?= $customFrom ?>" style="width:140px;">
                <input type="date" name="to" class="form-control" value="<?= $customTo ?>" style="width:140px;">
                <button type="submit" class="btn btn-info btn-sm"><?= t('view') ?></button>
            </form>
            <button class="btn btn-sm btn-info" onclick="window.print()"><i class="fas fa-print"></i><?= t('print') ?></button>
        </div>
    </div>
</div>

<!-- رأس الطباعة -->
<div class="print-only" style="display:none;">
    <style>@media print { .print-only{display:block!important;} .no-print{display:none!important;} body{font-size:11px;} .card{break-inside:avoid;} thead{display:table-header-group!important;} tbody tr{break-inside:avoid;page-break-inside:avoid;} .stats-grid{break-inside:avoid;} }</style>
    <div style="text-align:center;border-bottom:2px solid #1a2744;padding-bottom:10px;margin-bottom:12px;">
        <h2 style="margin:0;"><?= htmlspecialchars($company['company_name'] ?? t('app_name')) ?></h2>
        <h3 style="margin:5px 0;color:#1a2744;"><?= t('accounting.profit_loss') ?></h3>
        <p style="color:#666;font-size:12px;"><?= $periodLabel ?></p>
    </div>
</div>

<!-- مؤشرات رئيسية -->
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon" style="color:#16a34a;"><i class="fas fa-cash-register"></i></div><div class="stat-info"><p class="stat-value" style="color:#16a34a;"><?= formatMoney($netSales) ?></p><p><?= t('g.net_sales') ?></p><p class="stat-change neutral"><?= $salesData['cnt'] ?> <?= t('invoice') ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="color:#2563eb;"><i class="fas fa-chart-line"></i></div><div class="stat-info"><p class="stat-value" style="color:#2563eb;"><?= formatMoney($grossProfit) ?></p><p><?= t('g.gross_profit') ?></p><p class="stat-change neutral"><?= $netSales > 0 ? round($grossProfit/$netSales*100,1) . '%' : '0%' ?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="color:#ea580c;"><i class="fas fa-coins"></i></div><div class="stat-info"><p class="stat-value" style="color:#ea580c;"><?= formatMoney($operatingExpenses) ?></p><p><?= t('expenses.title') ?></p></div></div>
    <div class="stat-card" style="border:2px solid <?= $netProfit >= 0 ? '#16a34a' : '#dc2626' ?>;">
        <div class="stat-icon" style="color:<?= $netProfit >= 0 ? '#16a34a' : '#dc2626' ?>;"><i class="fas fa-star"></i></div>
        <div class="stat-info"><p class="stat-value" style="font-size:22px;color:<?= $netProfit >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= formatMoney(abs($netProfit)) ?></p><p><strong><?= $netProfit >= 0 ? t('g.net_profit') : t('g.net_profit') ?></strong></p></div>
    </div>
</div>

<!-- التقرير التفصيلي -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-chart-bar"></i> <?= t('accounting.profit_loss') ?> — <?= $periodLabel ?></h3></div>
    <div class="card-body">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#1a2744;color:#fff;"><th style="padding:10px;text-align:right;width:55%;"><?= t('g.statement') ?></th><th style="padding:10px;text-align:center;"><?= t('amount') ?> (<?= t('sar') ?>)</th><th style="padding:10px;text-align:center;"><?= t('notes') ?></th></tr></thead>
            <tbody>
                <!-- الإيرادات -->
                <tr style="background:#d4edda;"><td colspan="3" style="padding:10px;"><strong style="color:#155724;font-size:14px;"><i class="fas fa-arrow-circle-up"></i> <?= t('g.first_revenue') ?></strong></td></tr>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:8px 20px;"><?= t('g.total_sales') ?></td><td style="text-align:center;color:#16a34a;font-weight:700;"><?= formatMoney($salesData['net']) ?></td><td style="text-align:center;color:#888;font-size:12px;"><?= $salesData['cnt'] ?> <?= t('invoice') ?></td></tr>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:8px 20px;">(-) <?= t('sales.returns') ?></td><td style="text-align:center;color:#dc2626;">(<?= formatMoney($salesReturnsTotal) ?>)</td><td></td></tr>
                <tr style="background:#c3e6cb;font-weight:700;"><td style="padding:8px 20px;"><?= t('g.net_sales') ?></td><td style="text-align:center;color:#155724;font-size:15px;"><?= formatMoney($netSales) ?></td><td></td></tr>
                <?php if ($mfgRevenue > 0): ?>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:8px 20px;">(+) <?= t('g.manufacturing_revenue') ?></td><td style="text-align:center;color:#16a34a;"><?= formatMoney($mfgRevenue) ?></td><td></td></tr>
                <tr style="background:#c3e6cb;font-weight:700;"><td style="padding:8px 20px;"><?= t('g.total_revenue') ?></td><td style="text-align:center;color:#155724;font-size:15px;"><?= formatMoney($totalRevenue) ?></td><td></td></tr>
                <?php endif; ?>

                <!-- تكلفة المبيعات -->
                <tr style="background:#f8d7da;"><td colspan="3" style="padding:10px;"><strong style="color:#721c24;font-size:14px;"><i class="fas fa-arrow-circle-down"></i> <?= t('g.second_cogs') ?></strong></td></tr>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:8px 20px;"><?= t('g.cogs') ?></td><td style="text-align:center;color:#dc2626;">(<?= formatMoney($cogsTotal) ?>)</td><td></td></tr>
                <?php if ($purchaseReturnsTotal > 0): ?>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:8px 20px;">(+) <?= t('g.purchase_returns_log') ?></td><td style="text-align:center;color:#16a34a;"><?= formatMoney($purchaseReturnsTotal) ?></td><td style="text-align:center;color:#888;font-size:12px;">تخفيض التكلفة</td></tr>
                <?php endif; ?>
                <?php if ($mfgCosts > 0): ?>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:8px 20px;"><?= t('g.manufacturing_costs') ?></td><td style="text-align:center;color:#dc2626;">(<?= formatMoney($mfgCosts) ?>)</td><td></td></tr>
                <?php endif; ?>
                
                <!-- مجمل الربح -->
                <tr style="background:#fff3cd;font-weight:800;font-size:15px;"><td style="padding:10px 20px;color:#856404;"><i class="fas fa-equals"></i> <?= t('g.gross_profit') ?></td><td style="text-align:center;color:<?= $grossProfit >= 0 ? '#16a34a' : '#dc2626' ?>;font-size:16px;"><?= formatMoney($grossProfit) ?></td><td style="text-align:center;color:#888;font-size:12px;"><?= $totalRevenue > 0 ? t('g.percentage') . ': ' . round($grossProfit/$totalRevenue*100,1) . '%' : '' ?></td></tr>

                <!-- المصروفات -->
                <tr style="background:#e2e3e5;"><td colspan="3" style="padding:10px;"><strong style="color:#383d41;font-size:14px;"><i class="fas fa-cogs"></i> <?= t('g.third_opex') ?></strong></td></tr>
                <?php foreach ($expensesList as $exp): ?>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:6px 20px;"><?= htmlspecialchars($exp['category']) ?></td><td style="text-align:center;color:#dc2626;">(<?= formatMoney($exp['total']) ?>)</td><td></td></tr>
                <?php endforeach; ?>
                <?php if ($payVouchersTotal > 0): ?>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:6px 20px;"><?= t('g.other_payments') ?></td><td style="text-align:center;color:#dc2626;">(<?= formatMoney($payVouchersTotal) ?>)</td><td></td></tr>
                <?php endif; ?>
                <tr style="background:#d6d8db;font-weight:700;"><td style="padding:8px 20px;"><?= t('g.total_opex') ?></td><td style="text-align:center;color:#721c24;">(<?= formatMoney($operatingExpenses) ?>)</td><td></td></tr>

                <!-- صافي الربح -->
                <tr><td colspan="3" style="height:8px;"></td></tr>
                <tr style="background:<?= $netProfit >= 0 ? '#d4edda' : '#f8d7da' ?>;font-weight:800;font-size:16px;">
                    <td style="padding:12px 20px;"><i class="fas fa-star"></i> <?= t('g.net_profit') ?></td>
                    <td style="text-align:center;color:<?= $netProfit >= 0 ? '#16a34a' : '#dc2626' ?>;font-size:20px;"><?= formatMoney(abs($netProfit)) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td>
                    <td style="text-align:center;color:<?= $netProfit >= 0 ? '#16a34a' : '#dc2626' ?>;font-weight:700;"><?= $netProfit >= 0 ? t('g.net_profit') : t('g.net_profit') ?> — <?= $netProfit >= 0 ? t('g.surplus') : t('g.shortage') ?></td>
                </tr>

                <!-- الضريبة -->
                <tr><td colspan="3" style="height:8px;"></td></tr>
                <tr style="background:#d1ecf1;"><td colspan="3" style="padding:10px;"><strong style="color:#0c5460;font-size:14px;"><i class="fas fa-percent"></i> <?= t('g.fifth_tax') ?></strong></td></tr>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:6px 20px;"><?= t('g.vat_collected') ?></td><td style="text-align:center;"><?= formatMoney($vatCollected) ?></td><td></td></tr>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:6px 20px;"><?= t('g.vat_paid') ?></td><td style="text-align:center;"><?= formatMoney($vatPaid) ?></td><td></td></tr>
                <tr style="background:#f8f9fa;font-weight:700;"><td style="padding:8px 20px;"><?= t('g.net_vat_due') ?></td><td style="text-align:center;color:<?= $vatDue >= 0 ? '#dc2626' : '#16a34a' ?>;"><?= formatMoney(abs($vatDue)) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td><td style="text-align:center;color:#888;font-size:12px;"><?= $vatDue >= 0 ? t('g.vat_collected') : t('g.vat_paid') ?></td></tr>

                <!-- التدفق النقدي -->
                <tr><td colspan="3" style="height:8px;"></td></tr>
                <tr style="background:#e8f4fd;"><td colspan="3" style="padding:10px;"><strong style="color:#004085;font-size:14px;"><i class="fas fa-money-bill-wave"></i> <?= t('g.sixth_cashflow') ?></strong></td></tr>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:6px 20px;"><?= t('g.total_collected') ?></td><td style="text-align:center;color:#16a34a;font-weight:600;"><?= formatMoney($salesData['total'] + $recVouchersTotal) ?></td><td></td></tr>
                <tr style="border-bottom:1px solid #eee;"><td style="padding:6px 20px;"><?= t('g.total_paid_out') ?></td><td style="text-align:center;color:#dc2626;font-weight:600;">(<?= formatMoney($purchasesData['total'] + $operatingExpenses) ?>)</td><td></td></tr>
                <?php $cashFlow = ($salesData['total'] + $recVouchersTotal) - ($purchasesData['total'] + $operatingExpenses); ?>
                <tr style="background:#f8f9fa;font-weight:700;"><td style="padding:8px 20px;"><?= t('g.net_cashflow') ?></td><td style="text-align:center;color:<?= $cashFlow >= 0 ? '#16a34a' : '#dc2626' ?>;font-size:15px;"><?= formatMoney($cashFlow) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td><td></td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
