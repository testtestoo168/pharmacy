<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.cash_flow');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('reports_financial');
$company = getCompanySettings($pdo);
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

// المقبوضات النقدية
$cashSales = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM sales_invoices WHERE tenant_id=? AND branch_id=? AND payment_type IN('cash','card') AND invoice_date BETWEEN ? AND ?");
$cashSales->execute([$tid,$bid,$fromDate,$toDate]); $cashSalesTotal = floatval($cashSales->fetchColumn());

$receiptVouchers = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM receipt_vouchers WHERE tenant_id=? AND branch_id=? AND voucher_date BETWEEN ? AND ?");
$receiptVouchers->execute([$tid,$bid,$fromDate,$toDate]); $receiptsTotal = floatval($receiptVouchers->fetchColumn());

// المدفوعات النقدية
$cashPurchases = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM purchase_invoices WHERE tenant_id=? AND branch_id=? AND payment_type IN('cash','transfer') AND invoice_date BETWEEN ? AND ?");
$cashPurchases->execute([$tid,$bid,$fromDate,$toDate]); $cashPurchasesTotal = floatval($cashPurchases->fetchColumn());

$paymentVouchers = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE tenant_id=? AND branch_id=? AND voucher_date BETWEEN ? AND ?");
$paymentVouchers->execute([$tid,$bid,$fromDate,$toDate]); $paymentsTotal = floatval($paymentVouchers->fetchColumn());

$expenses = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM expenses WHERE tenant_id=? AND branch_id=? AND expense_date BETWEEN ? AND ?");
$expenses->execute([$tid,$bid,$fromDate,$toDate]); $expensesTotal = floatval($expenses->fetchColumn());

$totalIn = $cashSalesTotal + $receiptsTotal;
$totalOut = $cashPurchasesTotal + $paymentsTotal + $expensesTotal;
$netCashFlow = $totalIn - $totalOut;
?>
<div class="card no-print" style="margin-bottom:16px;"><div class="card-body" style="padding:12px;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <label><?= t('from') ?>:</label><input type="date" name="from" class="form-control" value="<?=$fromDate?>" style="width:150px;">
        <label><?= t('to') ?>:</label><input type="date" name="to" class="form-control" value="<?=$toDate?>" style="width:150px;">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i><?= t('view') ?></button>
        <button type="button" class="btn btn-sm btn-info" onclick="window.print()"><i class="fas fa-print"></i><?= t('print') ?></button>
    </form>
</div></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card"><div class="card-header" style="background:#f0fdf4;"><h3 style="color:#16a34a;margin:0;font-size:15px;"><i class="fas fa-arrow-down"></i> <?= t('g.inflows') ?></h3></div>
        <div class="table-responsive"><table>
            <tr><td><?= t('g.cash_sales') ?></td><td style="font-weight:600;text-align:left;"><?=formatMoney($cashSalesTotal)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td></tr>
            <tr><td><?= t('receipts.title') ?></td><td style="font-weight:600;text-align:left;"><?=formatMoney($receiptsTotal)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td></tr>
            <tr style="background:#f0fdf4;font-weight:700;"><td><?= t('total') ?></td><td style="text-align:left;color:#16a34a;"><?=formatMoney($totalIn)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td></tr>
        </table></div></div>
    <div class="card"><div class="card-header" style="background:#fef2f2;"><h3 style="color:#dc2626;margin:0;font-size:15px;"><i class="fas fa-arrow-up"></i> <?= t('g.outflows') ?></h3></div>
        <div class="table-responsive"><table>
            <tr><td><?= t('g.cash_purchases') ?></td><td style="font-weight:600;text-align:left;"><?=formatMoney($cashPurchasesTotal)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td></tr>
            <tr><td><?= t('payments.title') ?></td><td style="font-weight:600;text-align:left;"><?=formatMoney($paymentsTotal)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td></tr>
            <tr><td><?= t('accounting.expenses_type') ?></td><td style="font-weight:600;text-align:left;"><?=formatMoney($expensesTotal)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td></tr>
            <tr style="background:#fef2f2;font-weight:700;"><td><?= t('total') ?></td><td style="text-align:left;color:#dc2626;"><?=formatMoney($totalOut)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td></tr>
        </table></div></div>
</div>
<div class="card"><div class="card-body" style="padding:20px;text-align:center;">
    <p style="font-size:14px;color:#64748b;margin-bottom:8px;"><?= t('g.net_cashflow') ?></p>
    <p style="font-size:36px;font-weight:700;color:<?=$netCashFlow>=0?'#16a34a':'#dc2626'?>;margin:0;"><?=formatMoney($netCashFlow)?> <small style="font-size:16px;"><span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></small></p>
</div></div>
<?php require_once 'includes/footer.php'; ?>
