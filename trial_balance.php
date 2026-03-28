<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.trial_balance');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('accounting_view');

$asOfDate = $_GET['as_of'] ?? date('Y-m-d');
$company = getCompanySettings($pdo);

// حساب أرصدة جميع الحسابات
$accounts = $pdo->query("SELECT a.id, a.code, a.name, a.account_type, a.parent_id,
    COALESCE((SELECT SUM(jl.debit) FROM journal_entry_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE je.tenant_id = $tid AND jl.account_id = a.id AND je.entry_date <= '$asOfDate'), 0) as total_debit,
    COALESCE((SELECT SUM(jl.credit) FROM journal_entry_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE je.tenant_id = $tid AND jl.account_id = a.id AND je.entry_date <= '$asOfDate'), 0) as total_credit
    FROM accounts a WHERE a.tenant_id = $tid AND a.is_active = 1 ORDER BY a.code")->fetchAll();

$typeLabels = ['asset'=>t('accounting.assets'),'liability'=>t('accounting.liabilities'),'equity'=>t('accounting.equity'),'revenue'=>t('accounting.revenue'),'expense'=>t('accounting.expenses_type')];
$typeColors = ['asset'=>'#2563eb','liability'=>'#dc2626','equity'=>'#7c3aed','revenue'=>'#16a34a','expense'=>'#ea580c'];

$grandDebit = 0; $grandCredit = 0;
$grouped = [];
foreach ($accounts as $acc) {
    $balance = $acc['total_debit'] - $acc['total_credit'];
    if ($acc['total_debit'] == 0 && $acc['total_credit'] == 0) continue; // تخطي حسابات بدون حركة
    $acc['balance'] = $balance;
    $acc['debit_balance'] = $balance > 0 ? $balance : 0;
    $acc['credit_balance'] = $balance < 0 ? abs($balance) : 0;
    $grandDebit += $acc['debit_balance'];
    $grandCredit += $acc['credit_balance'];
    $grouped[$acc['account_type']][] = $acc;
}
?>

<div class="card no-print">
    <div class="card-body" style="padding:12px;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <label><?= t('g.until_date') ?>:</label>
            <input type="date" name="as_of" class="form-control" value="<?= $asOfDate ?>" style="width:180px;">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i><?= t('view') ?></button>
            <button type="button" class="btn btn-info btn-sm" onclick="window.print()"><i class="fas fa-print"></i><?= t('print') ?></button>
        </form>
    </div>
</div>

<!-- رأس الطباعة -->
<div class="print-only" style="display:none;">
    <style>@media print { .print-only{display:block!important;} .no-print{display:none!important;} body{font-size:11px;} thead{display:table-header-group!important;} tfoot{display:table-footer-group!important;} tbody tr{break-inside:avoid;page-break-inside:avoid;} }</style>
    <div style="text-align:center;border-bottom:2px solid #1a2744;padding-bottom:12px;margin-bottom:15px;">
        <h2 style="margin:0;"><?= htmlspecialchars($company['company_name'] ?? t('app_name')) ?></h2>
        <p style="margin:3px 0;font-size:13px;color:#666;"><?= htmlspecialchars($company['company_name_en'] ?? '') ?></p>
        <h3 style="margin:8px 0 0;color:#1a2744;"><?= t('accounting.trial_balance') ?> <?= t('g.until_date') ?> <?= $asOfDate ?></h3>
    </div>
</div>

<!-- ملخص سريع -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="color:#2563eb;"><i class="fas fa-arrow-up"></i></div>
        <div class="stat-info"><p class="stat-value" style="color:#2563eb;"><?= formatMoney($grandDebit) ?></p><p><?= t('g.total_debit') ?></p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:#dc2626;"><i class="fas fa-arrow-down"></i></div>
        <div class="stat-info"><p class="stat-value" style="color:#dc2626;"><?= formatMoney($grandCredit) ?></p><p><?= t('g.total_credit') ?></p></div>
    </div>
    <div class="stat-card" style="border:2px solid <?= abs($grandDebit - $grandCredit) < 0.01 ? '#16a34a' : '#dc2626' ?>;">
        <div class="stat-icon" style="color:<?= abs($grandDebit - $grandCredit) < 0.01 ? '#16a34a' : '#dc2626' ?>;"><i class="fas fa-<?= abs($grandDebit - $grandCredit) < 0.01 ? 'check-circle' : 'exclamation-triangle' ?>"></i></div>
        <div class="stat-info"><p class="stat-value" style="color:<?= abs($grandDebit - $grandCredit) < 0.01 ? '#16a34a' : '#dc2626' ?>;"><?= formatMoney(abs($grandDebit - $grandCredit)) ?></p><p><?= abs($grandDebit - $grandCredit) < 0.01 ? t('g.balanced') . ' ✓' : t('g.difference') . ' ✗' ?></p></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-balance-scale"></i> <?= t('accounting.trial_balance') ?> <?= t('g.until_date') ?> <?= $asOfDate ?></h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr style="background:#1a2744;color:#fff;">
                        <th style="padding:10px;width:80px;"><?= t('accounting.code') ?></th>
                        <th style="padding:10px;"><?= t('accounting.account_name') ?></th>
                        <th style="padding:10px;text-align:center;"><?= t('g.total_debit') ?></th>
                        <th style="padding:10px;text-align:center;"><?= t('g.total_credit') ?></th>
                        <th style="padding:10px;text-align:center;"><?= t('g.debit_balance') ?></th>
                        <th style="padding:10px;text-align:center;"><?= t('g.credit_balance') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (['asset','liability','equity','revenue','expense'] as $type):
                    if (empty($grouped[$type])) continue;
                ?>
                <tr style="background:<?= $typeColors[$type] ?>10;">
                    <td colspan="6" style="padding:8px 12px;font-weight:800;color:<?= $typeColors[$type] ?>;font-size:14px;">
                        <i class="fas fa-folder" style="margin-left:5px;"></i> <?= $typeLabels[$type] ?>
                    </td>
                </tr>
                <?php foreach ($grouped[$type] as $acc): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:6px 12px;"><code style="font-size:12px;"><?= $acc['code'] ?></code></td>
                    <td style="padding:6px 12px;">
                        <?php $indent = $acc['parent_id'] ? 15 : 0; ?>
                        <span style="margin-right:<?= $indent ?>px;" dir="auto"><?= htmlspecialchars($acc['name']) ?></span>
                    </td>
                    <td style="padding:6px 12px;text-align:center;"><?= $acc['total_debit'] > 0 ? formatMoney($acc['total_debit']) : '-' ?></td>
                    <td style="padding:6px 12px;text-align:center;"><?= $acc['total_credit'] > 0 ? formatMoney($acc['total_credit']) : '-' ?></td>
                    <td style="padding:6px 12px;text-align:center;font-weight:700;color:#1d4ed8;"><?= $acc['debit_balance'] > 0 ? formatMoney($acc['debit_balance']) : '-' ?></td>
                    <td style="padding:6px 12px;text-align:center;font-weight:700;color:#dc2626;"><?= $acc['credit_balance'] > 0 ? formatMoney($acc['credit_balance']) : '-' ?></td>
                </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#1a2744;color:#fff;font-weight:800;font-size:14px;">
                        <td colspan="2" style="padding:12px;"><?= t('total') ?></td>
                        <td style="text-align:center;padding:12px;"></td>
                        <td style="text-align:center;padding:12px;"></td>
                        <td style="text-align:center;padding:12px;"><?= formatMoney($grandDebit) ?></td>
                        <td style="text-align:center;padding:12px;"><?= formatMoney($grandCredit) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
