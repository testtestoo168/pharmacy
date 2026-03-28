<?php
require_once 'includes/config.php';
$tid = getTenantId();
$bid = getBranchId();
requireLogin();
requirePermission('reports_view');

$company = getCompanySettings($pdo);

// Get branch info
// تحقق: هل الفرع الحالي هو الفرع الرئيسي؟
$_isMain = false;
try { $_bChk = $pdo->prepare("SELECT is_main FROM branches WHERE id=? AND tenant_id=?"); $_bChk->execute([$bid,$tid]); $_isMain = (bool)$_bChk->fetchColumn(); } catch(Exception $e) {}
if ($_isMain) {
    $branchId = intval($_GET['branch_id'] ?? 0);
} else {
    $branchId = $bid;
}
$branchFilter = $branchId ? " AND branch_id = $branchId " : "";
$branchName = '';
try {
    $brStmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? AND tenant_id = ?");
    $brStmt->execute([$branchId, $tid]);
    $brRow = $brStmt->fetch();
    $branchName = $brRow['name'] ?? '';
} catch(Exception $e) {}

$period = $_GET['period'] ?? 'monthly';
$customFrom = $_GET['from'] ?? date('Y-m-01');
$customTo = $_GET['to'] ?? date('Y-m-d');

if ($period == 'weekly') {
    $fromDate = date('Y-m-d', strtotime('monday this week'));
    $toDate = date('Y-m-d', strtotime('sunday this week'));
    $periodLabel = t('this_week') . ' (' . $fromDate . ' — ' . $toDate . ')';
} elseif ($period == 'monthly') {
    $fromDate = date('Y-m-01');
    $toDate = date('Y-m-t');
    $periodLabel = t('this_month') . ' (' . $fromDate . ' — ' . $toDate . ')';
} elseif ($period == 'yearly') {
    $fromDate = date('Y-01-01');
    $toDate = date('Y-12-31');
    $periodLabel = t('this_year') . ' ' . date('Y');
} elseif ($period == 'all') {
    $fromDate = '2000-01-01';
    $toDate = '2099-12-31';
    $periodLabel = t('since_start');
} else {
    $fromDate = $customFrom;
    $toDate = $customTo;
    $periodLabel = t('from') . " $fromDate " . t('to') . " $toDate";
}

// المبيعات
$sales = $pdo->prepare("SELECT COALESCE(SUM(net_total),0) as net, COALESCE(SUM(vat_amount),0) as vat, COUNT(*) as cnt FROM sales_invoices WHERE tenant_id = $tid AND invoice_date BETWEEN ? AND ? $branchFilter");
$sales->execute([$fromDate, $toDate]);
$salesData = $sales->fetch();

// المشتريات
$purchases = $pdo->prepare("SELECT COALESCE(SUM(net_total),0) as net, COALESCE(SUM(vat_amount),0) as vat FROM purchase_invoices WHERE tenant_id = $tid AND invoice_date BETWEEN ? AND ? $branchFilter");
$purchases->execute([$fromDate, $toDate]);
$purchasesData = $purchases->fetch();

// التصنيع
$mfgRevenueTotal = 0; $mfgExpensesTotal = 0; $mfgCountTotal = 0;
try { $mfgRevenue = $pdo->prepare("SELECT COALESCE(SUM(selling_price),0) FROM manufacturing_orders WHERE tenant_id = $tid $branchFilter AND order_date BETWEEN ? AND ?"); $mfgRevenue->execute([$fromDate, $toDate]); $mfgRevenueTotal = floatval($mfgRevenue->fetchColumn()); } catch(Exception $e) {}
try { $mfgExpenses = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM manufacturing_expenses WHERE tenant_id = $tid $branchFilter AND expense_date BETWEEN ? AND ?"); $mfgExpenses->execute([$fromDate, $toDate]); $mfgExpensesTotal = floatval($mfgExpenses->fetchColumn()); } catch(Exception $e) {}
try { $mfgCount = $pdo->prepare("SELECT COUNT(*) FROM manufacturing_orders WHERE tenant_id = $tid $branchFilter AND order_date BETWEEN ? AND ?"); $mfgCount->execute([$fromDate, $toDate]); $mfgCountTotal = intval($mfgCount->fetchColumn()); } catch(Exception $e) {}

// سندات
$receipts = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM receipt_vouchers WHERE tenant_id = $tid $branchFilter AND voucher_date BETWEEN ? AND ?");
$receipts->execute([$fromDate, $toDate]);
$receiptsTotal = $receipts->fetchColumn();

$paymentsQ = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE tenant_id = $tid $branchFilter AND voucher_date BETWEEN ? AND ?");
$paymentsQ->execute([$fromDate, $toDate]);
$paymentsTotal = $paymentsQ->fetchColumn();

// الرواتب
$payrollTotal = 0;
try { $payrollQ = $pdo->prepare("SELECT COALESCE(SUM(net_salary),0) FROM payroll WHERE tenant_id = $tid AND month BETWEEN ? AND ? AND status='paid'"); $payrollQ->execute([substr($fromDate, 0, 7), substr($toDate, 0, 7)]); $payrollTotal = floatval($payrollQ->fetchColumn()); } catch(Exception $e) {}

// أعمال المالك
$ownerWorksTotal = 0; $ownerWorksDetailsList = []; $ownerDepositsTotal = 0; $ownerWithdrawalsTotal = 0; $depList = []; $wdList = [];
try {
    $ownerWorks = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(total_cost,cost,0)),0) FROM owner_personal_works WHERE tenant_id = $tid AND work_date BETWEEN ? AND ?");
    $ownerWorks->execute([$fromDate, $toDate]); $ownerWorksTotal = floatval($ownerWorks->fetchColumn());
    $ownerWorksDetails = $pdo->prepare("SELECT * FROM owner_personal_works WHERE tenant_id = $tid AND work_date BETWEEN ? AND ? ORDER BY work_date DESC");
    $ownerWorksDetails->execute([$fromDate, $toDate]); $ownerWorksDetailsList = $ownerWorksDetails->fetchAll();
} catch(Exception $e) {}

try {
    $ownerDeposits = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM owner_transfers WHERE tenant_id = $tid AND type='deposit' AND transfer_date BETWEEN ? AND ?");
    $ownerDeposits->execute([$fromDate, $toDate]); $ownerDepositsTotal = floatval($ownerDeposits->fetchColumn());
    $ownerWithdrawals = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM owner_transfers WHERE tenant_id = $tid AND type='withdrawal' AND transfer_date BETWEEN ? AND ?");
    $ownerWithdrawals->execute([$fromDate, $toDate]); $ownerWithdrawalsTotal = floatval($ownerWithdrawals->fetchColumn());
    $depDetails = $pdo->prepare("SELECT * FROM owner_transfers WHERE tenant_id = $tid AND type='deposit' AND transfer_date BETWEEN ? AND ? ORDER BY transfer_date DESC");
    $depDetails->execute([$fromDate, $toDate]); $depList = $depDetails->fetchAll();
    $wdDetails = $pdo->prepare("SELECT * FROM owner_transfers WHERE tenant_id = $tid AND type='withdrawal' AND transfer_date BETWEEN ? AND ? ORDER BY transfer_date DESC");
    $wdDetails->execute([$fromDate, $toDate]); $wdList = $wdDetails->fetchAll();
} catch(Exception $e) {}

// حسابات
$totalRevenue = $salesData['net'] + $mfgRevenueTotal;
$totalCosts = $purchasesData['net'] + $mfgExpensesTotal;
$grossProfit = $totalRevenue - $totalCosts;
$totalOpex = $paymentsTotal + $payrollTotal;
$netOpProfit = $grossProfit - $totalOpex;

$totalOwnerUsed = $ownerWithdrawalsTotal + $ownerWorksTotal;
$remainingProfitAfterOwner = $netOpProfit - $totalOwnerUsed;

if ($remainingProfitAfterOwner >= $ownerDepositsTotal) {
    $depositFromProfit = $ownerDepositsTotal;
    $depositAsDebt = 0;
} elseif ($remainingProfitAfterOwner > 0) {
    $depositFromProfit = $remainingProfitAfterOwner;
    $depositAsDebt = $ownerDepositsTotal - $remainingProfitAfterOwner;
} else {
    $depositFromProfit = 0;
    $depositAsDebt = $ownerDepositsTotal;
}

$finalProfit = $remainingProfitAfterOwner - $ownerDepositsTotal;
$vatDiff = $salesData['vat'] - $purchasesData['vat'];
$cashFlow = $receiptsTotal - $paymentsTotal;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= t('accounting.profit_loss') ?> - <?= $periodLabel ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Tajawal',sans-serif; direction:rtl; background:#eee; padding:15px; color:#1d1d2e; }

        .no-print { text-align:center; margin-bottom:12px; }
        .no-print button, .no-print a { padding:8px 24px; border:none; border-radius:4px; font-size:13px; cursor:pointer; font-family:'Tajawal',sans-serif; text-decoration:none; display:inline-block; }
        .no-print button { background:#1a2744; color:#fff; }
        .no-print a { background:#6b7280; color:#fff; margin-right:8px; }

        .inv { width:210mm; min-height:297mm; margin:0 auto; background:#fff; border:1px solid #d1d5db; display:flex; flex-direction:column; }

        /* ===== Classic Header ===== */
        .inv-header { display:flex; justify-content:space-between; align-items:flex-start; padding:16px 24px 12px; border-bottom:2px solid #1a2744; }
        .hdr-right { text-align:right; flex:1; }
        .hdr-right h2 { font-size:16px; font-weight:800; color:#1a2744; margin-bottom:2px; }
        .hdr-right p { font-size:10px; color:#555; line-height:1.7; }
        .hdr-center { text-align:center; padding:0 12px; flex-shrink:0; }
        .hdr-center img { width:72px; height:auto; }
        .hdr-left { text-align:left; direction:ltr; flex:1; }
        .hdr-left h2 { font-size:13px; font-weight:700; color:#1a2744; margin-bottom:2px; }
        .hdr-left p { font-size:10px; color:#555; line-height:1.7; }

        /* ===== Title Bar — Classic ===== */
        .title-bar { display:flex; justify-content:space-between; align-items:center; padding:8px 24px; border-bottom:1px solid #d1d5db; background:#f8f9fa; }
        .title-main { font-size:16px; font-weight:800; color:#1a2744; }
        .title-badge { background:#1a2744; color:#fff; padding:3px 14px; font-size:11px; font-weight:700; letter-spacing:0.5px; }
        .title-meta { font-size:10px; color:#6b7280; }

        /* ===== Branch & Period ===== */
        .branch-bar { text-align:center; padding:5px 24px; border-bottom:1px solid #e5e7eb; font-size:12px; font-weight:600; color:#374151; background:#fafbfc; }
        .period-bar { text-align:center; padding:5px 24px; border-bottom:1px solid #e5e7eb; font-size:12px; font-weight:700; color:#1a2744; background:#f8f9fa; }

        .report-body { flex:1; padding:12px 20px; }

        /* ===== Classic Table ===== */
        .rtable { width:100%; border-collapse:collapse; margin-bottom:10px; }
        .rtable th { background:#f8f9fa; color:#374151; padding:7px 12px; font-size:11px; text-align:right; border-bottom:2px solid #d1d5db; font-weight:700; }
        .rtable th.center { text-align:center; }
        .rtable td { padding:5px 12px; font-size:11px; border-bottom:1px solid #f0f0f0; }
        .rtable td.amount { text-align:center; font-weight:600; font-variant-numeric:tabular-nums; }
        .rtable td.note { text-align:center; font-size:10px; color:#6b7280; }

        .section-head td { font-weight:700; font-size:12px; padding:7px 12px; background:#f8f9fa; color:#1a2744; border-bottom:2px solid #d1d5db; }
        .sec-green td { background:#f0fdf4; color:#15803d; }
        .sec-red td { background:#fef2f2; color:#b91c1c; }
        .sec-gray td { background:#f3f4f6; color:#374151; }
        .sec-blue td { background:#eff6ff; color:#1e40af; }
        .sec-owner td { background:#1a2744; color:#fff; font-size:12px; }
        .sec-cash td { background:#f0f9ff; color:#075985; }

        .total-row td { font-weight:700; background:#f8f9fa; }
        .total-green td { background:#f0fdf4; }
        .total-red td { background:#fef2f2; }
        .total-gray td { background:#f3f4f6; }
        .total-yellow td { background:#fefce8; color:#854d0e; font-size:12px; }
        .total-profit td { font-size:13px; font-weight:800; }

        .green { color:#15803d; }
        .red { color:#b91c1c; }

        .final-box { margin:14px auto; max-width:420px; border:2px solid #1a2744; }
        .final-box-head { background:#1a2744; color:#fff; text-align:center; padding:8px; font-size:13px; font-weight:800; }
        .final-box-body { padding:10px 16px; }
        .final-box-body table { width:100%; border-collapse:collapse; }
        .final-box-body td { padding:6px 4px; font-size:12px; border-bottom:1px solid #f0f0f0; }
        .final-result td { font-weight:800; font-size:18px; border:none; padding:10px 4px; }

        .dep-row td { padding:4px 24px; font-size:10px; }
        .dep-row td:first-child { padding-right:30px; }

        .inv-footer { border-top:2px solid #1a2744; padding:10px 24px; display:flex; justify-content:space-between; align-items:center; font-size:10px; color:#6b7280; margin-top:auto; }
        .stamp-area { text-align:center; padding:16px 0; }
        .stamp-circle { display:inline-block; border:2px dashed #d1d5db; border-radius:50%; width:80px; height:80px; line-height:80px; color:#d1d5db; font-size:10px; }

        @media print {
            body { background:#fff; padding:0; margin:0; }
            .no-print { display:none !important; }
            .inv { border:none; width:100%; min-height:100vh; }
            .inv-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .section-head td, .total-row td, .rtable th { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .sec-owner td, .title-badge, .final-box-head { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            thead { display:table-header-group !important; }
            tfoot { display:table-footer-group !important; }
            tbody tr { break-inside:avoid; page-break-inside:avoid; }
            .final-box { break-inside:avoid; page-break-inside:avoid; }
            .stamp-area { break-inside:avoid; page-break-inside:avoid; }
            @page { size:A4; margin:0; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()"><?= t('g.print_report') ?></button>
    <a href="reports.php?period=<?= $period ?>&from=<?= $customFrom ?>&to=<?= $customTo ?>"><?= t('back') ?></a>
</div>

<div class="inv">

    <!-- HEADER -->
    <div class="inv-header">
        <div class="hdr-right">
            <h2><?= $company['company_name'] ?? t('app_name') ?></h2>
            <p><?= $company['description_ar'] ?? '' ?></p>
            <p><?= $company['description_ar2'] ?? '' ?></p>
            <p><?= t('prt.cr') ?> <?= $company['cr_number'] ?? '' ?></p>
            <p><?= t('prt.contact') ?> <?= $company['phone'] ?? '' ?></p>
            <p><?= t('settings.tax_number') ?>: <?= $company['tax_number'] ?? '' ?></p>
        </div>
        <div class="hdr-center">
            <img src="<?= !empty($company['logo']) && file_exists($company['logo']) ? htmlspecialchars($company['logo']) : 'assets/logo.png' ?>" alt="Logo">
        </div>
        <div class="hdr-left">
            <h2><?= $company['company_name_en'] ?? 'URS Pharmacy' ?></h2>
            <p><?= $company['description_en'] ?? '' ?></p>
            <p><?= $company['description_en2'] ?? '' ?></p>
            <p>C.R: <?= $company['cr_number'] ?? '' ?></p>
            <p>Contact: <?= $company['phone'] ?? '' ?></p>
            <p>Tax .N: <?= $company['tax_number'] ?? '' ?></p>
        </div>
    </div>

    <!-- TITLE -->
    <div class="title-bar">
        <span class="title-meta"><?= t('prt.generated_at') ?>: <?= date('Y-m-d H:i') ?></span>
        <span class="title-main"><?= t('accounting.profit_loss') ?></span>
        <div class="title-badge">Profit & Loss</div>
    </div>

    <!-- BRANCH -->
    <?php if ($branchName): ?>
    <div class="branch-bar"><?= t('prt.branch_label2') ?> <?= htmlspecialchars($branchName) ?></div>
    <?php endif; ?>

    <!-- PERIOD -->
    <div class="period-bar"><?= t('prt.period') ?> <?= $periodLabel ?></div>

    <!-- REPORT BODY -->
    <div class="report-body">
        <table class="rtable">
            <thead><tr><th style="width:55%;"><?= t('g.statement') ?></th><th class="center" style="width:25%;"><?= t('amount') ?> (<?= t('sar') ?>)</th><th class="center" style="width:20%;"><?= t('notes') ?></th></tr></thead>
            <tbody>
                <tr class="section-head sec-green"><td colspan="3"><?= t('g.first_revenue') ?></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.net_sales') ?></td><td class="amount green"><?= formatMoney($salesData['net']) ?></td><td class="note"><?= $salesData['cnt'] ?><?= t('invoice') ?></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.manufacturing_revenue') ?></td><td class="amount green"><?= formatMoney($mfgRevenueTotal) ?></td><td class="note"><?= $mfgCountTotal ?> أمر</td></tr>
                <tr class="total-row total-green"><td style="padding-right:20px;"><?= t('g.total_revenue') ?></td><td class="amount green" style="font-size:13px;"><?= formatMoney($totalRevenue) ?></td><td></td></tr>

                <tr class="section-head sec-red"><td colspan="3"><?= t('g.second_costs') ?></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.net_purchases') ?></td><td class="amount red">(<?= formatMoney($purchasesData['net']) ?>)</td><td></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.manufacturing_costs') ?></td><td class="amount red">(<?= formatMoney($mfgExpensesTotal) ?>)</td><td></td></tr>
                <tr class="total-row total-red"><td style="padding-right:20px;"><?= t('g.total_costs') ?></td><td class="amount red">(<?= formatMoney($totalCosts) ?>)</td><td></td></tr>

                <tr class="total-row total-yellow"><td style="padding-right:20px;"><?= t('g.gross_profit') ?></td><td class="amount <?= $grossProfit >= 0 ? 'green' : 'red' ?>" style="font-size:13px;"><?= formatMoney($grossProfit) ?></td><td></td></tr>

                <tr class="section-head sec-gray"><td colspan="3"><?= t('g.third_opex') ?></td></tr>
                <tr><td style="padding-right:20px;"><?= t('payments.title') ?></td><td class="amount red">(<?= formatMoney($paymentsTotal) ?>)</td><td></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.salaries_paid') ?></td><td class="amount red">(<?= formatMoney($payrollTotal) ?>)</td><td></td></tr>
                <tr class="total-row total-gray"><td style="padding-right:20px;"><?= t('g.total_opex') ?></td><td class="amount red">(<?= formatMoney($totalOpex) ?>)</td><td></td></tr>

                <tr class="total-row total-profit"><td style="padding-right:20px;background:<?= $netOpProfit >= 0 ? '#f0fdf4' : '#fef2f2' ?>;"><?= t('g.operating_profit') ?></td><td class="amount <?= $netOpProfit >= 0 ? 'green' : 'red' ?>" style="background:<?= $netOpProfit >= 0 ? '#f0fdf4' : '#fef2f2' ?>;font-size:15px;"><?= formatMoney($netOpProfit) ?></td><td style="background:<?= $netOpProfit >= 0 ? '#f0fdf4' : '#fef2f2' ?>;"></td></tr>

                <tr class="section-head sec-owner"><td colspan="3"><?= t('g.fourth_owner') ?></td></tr>

                <tr style="background:#f8f9fa;"><td colspan="3" style="padding:4px 10px;font-size:10px;font-weight:700;color:#374151;"><?= t('g.owner_withdrawals') ?></td></tr>
                <?php if (empty($wdList)): ?>
                <tr><td colspan="3" style="padding:3px 20px;font-size:10px;color:#9ca3af;"><?= t('no_results') ?></td></tr>
                <?php else: foreach ($wdList as $w): ?>
                <tr class="dep-row"><td><?= htmlspecialchars($w['description'] ?: t('expenses.owner_withdrawal')) ?> <span style="color:#9ca3af;">(<?= $w['transfer_date'] ?>)</span></td><td class="amount red">(<?= formatMoney($w['amount']) ?>)</td><td></td></tr>
                <?php endforeach; endif; ?>
                <tr class="total-row" style="background:#f3f4f6;"><td style="padding-right:20px;"><?= t('g.total_owner_withdrawals') ?></td><td class="amount red">(<?= formatMoney($ownerWithdrawalsTotal) ?>)</td><td></td></tr>

                <tr style="background:#f8f9fa;"><td colspan="3" style="padding:4px 10px;font-size:10px;font-weight:700;color:#374151;"><?= t('g.owner_personal') ?></td></tr>
                <?php if (empty($ownerWorksDetailsList)): ?>
                <tr><td colspan="3" style="padding:3px 20px;font-size:10px;color:#9ca3af;"><?= t('no_results') ?></td></tr>
                <?php else: foreach ($ownerWorksDetailsList as $ow):
                    $owCost = floatval($ow['total_cost'] ?? $ow['cost'] ?? 0);
                    if ($owCost == 0) $owCost = floatval($ow['cost'] ?? 0);
                ?>
                <tr class="dep-row"><td><?= htmlspecialchars($ow['description']) ?> <span style="color:#9ca3af;">(<?= $ow['work_date'] ?>)</span></td><td class="amount red">(<?= formatMoney($owCost) ?>)</td><td></td></tr>
                <?php endforeach; endif; ?>
                <tr class="total-row" style="background:#f3f4f6;"><td style="padding-right:20px;"><?= t('g.total_owner_personal') ?></td><td class="amount red">(<?= formatMoney($ownerWorksTotal) ?>)</td><td></td></tr>

                <tr style="background:<?= $remainingProfitAfterOwner >= 0 ? '#f0fdf4' : '#fef2f2' ?>;font-weight:700;"><td style="padding:6px 20px;"><?= t('g.remaining_profit') ?></td><td class="amount <?= $remainingProfitAfterOwner >= 0 ? 'green' : 'red' ?>"><?= formatMoney($remainingProfitAfterOwner) ?></td><td></td></tr>

                <tr style="background:#f8f9fa;"><td colspan="3" style="padding:4px 10px;font-size:10px;font-weight:700;color:#374151;"><?= t('g.owner_support') ?></td></tr>
                <?php if (empty($depList)): ?>
                <tr><td colspan="3" style="padding:3px 20px;font-size:10px;color:#9ca3af;"><?= t('no_results') ?></td></tr>
                <?php else: foreach ($depList as $d): ?>
                <tr class="dep-row"><td><?= htmlspecialchars($d['description'] ?: t('inventory.transfer_support')) ?> <span style="color:#9ca3af;">(<?= $d['transfer_date'] ?>)</span></td><td class="amount red">(<?= formatMoney($d['amount']) ?>)</td><td></td></tr>
                <?php endforeach; endif; ?>
                <tr class="total-row" style="background:#f3f4f6;"><td style="padding-right:20px;"><?= t('g.total_owner_support') ?></td><td class="amount red">(<?= formatMoney($ownerDepositsTotal) ?>)</td><td class="note"><?php if ($depositAsDebt > 0) echo 'رصيد للمالك: ' . formatMoney($depositAsDebt); ?></td></tr>

                <tr style="background:#1a2744;"><td style="padding:10px 20px;color:#fff;font-weight:800;font-size:14px;"><?= t('g.final_net_profit') ?></td><td style="text-align:center;color:<?= $finalProfit >= 0 ? '#86efac' : '#fca5a5' ?>;font-weight:800;font-size:18px;"><?= formatMoney(abs($finalProfit)) ?> <span style="font-size:11px;color:#cbd5e1;"><?= t('sar') ?></span></td><td style="text-align:center;color:<?= $finalProfit >= 0 ? '#86efac' : '#fca5a5' ?>;font-weight:700;"><?= $finalProfit >= 0 ? 'ربح' : 'خسارة' ?></td></tr>

                <tr class="section-head sec-blue"><td colspan="3"><?= t('g.fifth_tax') ?></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.vat_collected') ?></td><td class="amount"><?= formatMoney($salesData['vat']) ?></td><td></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.vat_paid') ?></td><td class="amount"><?= formatMoney($purchasesData['vat']) ?></td><td></td></tr>
                <tr class="total-row"><td style="padding-right:20px;background:#f8f9fa;"><?= t('g.vat_difference') ?></td><td class="amount <?= $vatDiff >= 0 ? 'red' : 'green' ?>" style="background:#f8f9fa;"><?= formatMoney(abs($vatDiff)) ?></td><td class="note" style="background:#f8f9fa;"><?= $vatDiff >= 0 ? 'مستحقة عليك' : 'لصالحك' ?></td></tr>

                <tr class="section-head sec-cash"><td colspan="3"><?= t('g.sixth_cashflow') ?></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.receipts_collected') ?></td><td class="amount green"><?= formatMoney($receiptsTotal) ?></td><td></td></tr>
                <tr><td style="padding-right:20px;"><?= t('g.payments_paid') ?></td><td class="amount red">(<?= formatMoney($paymentsTotal) ?>)</td><td></td></tr>
                <tr class="total-row"><td style="padding-right:20px;background:#f8f9fa;"><?= t('g.net_cashflow') ?></td><td class="amount <?= $cashFlow >= 0 ? 'green' : 'red' ?>" style="background:#f8f9fa;"><?= formatMoney($cashFlow) ?></td><td style="background:#f8f9fa;"></td></tr>
            </tbody>
        </table>

        <!-- Final Box -->
        <div class="final-box">
            <div class="final-box-head"><?= t('g.final_net_profit') ?></div>
            <div class="final-box-body">
                <table>
                    <tr><td><?= t('g.operating_profit') ?></td><td style="text-align:left;direction:ltr;" class="<?= $netOpProfit >= 0 ? 'green' : 'red' ?>"><?= formatMoney($netOpProfit) ?> <span style="font-size:11px;color:#9ca3af;"><?= t('sar') ?></span></td></tr>
                    <tr><td class="red">(-) <?= t('g.owner_withdrawals') ?></td><td style="text-align:left;direction:ltr;" class="red">- <?= formatMoney($ownerWithdrawalsTotal) ?> <span style="font-size:11px;color:#9ca3af;"><?= t('sar') ?></span></td></tr>
                    <tr><td class="red">(-) <?= t('g.owner_personal') ?></td><td style="text-align:left;direction:ltr;" class="red">- <?= formatMoney($ownerWorksTotal) ?> <span style="font-size:11px;color:#9ca3af;"><?= t('sar') ?></span></td></tr>
                    <tr style="font-weight:700;"><td>= <?= t('g.remaining_profit') ?></td><td style="text-align:left;direction:ltr;" class="<?= $remainingProfitAfterOwner >= 0 ? 'green' : 'red' ?>"><?= formatMoney($remainingProfitAfterOwner) ?> <span style="font-size:11px;color:#9ca3af;"><?= t('sar') ?></span></td></tr>
                    <tr><td class="red">(-) <?= t('g.owner_support') ?></td><td style="text-align:left;direction:ltr;" class="red">- <?= formatMoney($ownerDepositsTotal) ?> <span style="font-size:11px;color:#9ca3af;"><?= t('sar') ?></span></td></tr>
                    <?php if ($depositAsDebt > 0): ?>
                    <tr><td style="color:#1e40af;font-size:10px;padding-right:16px;">↳ رصيد للمالك (لم تغطيه أرباح)</td><td style="text-align:left;direction:ltr;color:#1e40af;font-size:10px;"><?= formatMoney($depositAsDebt) ?> <span style="font-size:11px;color:#9ca3af;"><?= t('sar') ?></span></td></tr>
                    <?php endif; ?>
                    <tr class="final-result"><td style="color:<?= $finalProfit >= 0 ? '#15803d' : '#b91c1c' ?>;"><?= $finalProfit >= 0 ? '= صافي الربح الفعلي' : '= الخسارة' ?></td><td style="text-align:left;direction:ltr;color:<?= $finalProfit >= 0 ? '#15803d' : '#b91c1c' ?>;"><?= formatMoney(abs($finalProfit)) ?> <span style="font-size:11px;color:#9ca3af;"><?= t('sar') ?></span></td></tr>
                </table>
            </div>
        </div>

        <div class="stamp-area">
            <div class="stamp-circle"><?= t('prt.stamp') ?></div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="inv-footer">
        <div><?= t('prt.address_prefix') ?> <?= $company['address'] ?? '' ?></div>
        <div style="font-size:9px;"><?= $branchName ? t('prt.branch_label2') . ' ' . htmlspecialchars($branchName) . ' — ' : '' ?><?= t('prt.generated_at') ?>: <?= date('Y-m-d H:i') ?></div>
        <div style="font-weight:600;color:#1a2744;">URS Pharmacy System</div>
    </div>

</div>
</body>
</html>
