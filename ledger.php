<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.general_ledger');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('accounting_view');

$accounts = $pdo->query("SELECT id, code, name, account_type, balance FROM accounts WHERE tenant_id = $tid AND is_active = 1 ORDER BY code")->fetchAll();
$selectedAccount = intval($_GET['account_id'] ?? 0);
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

$typeLabels = ['asset'=>t('accounting.assets'),'liability'=>t('accounting.liabilities'),'equity'=>t('accounting.equity'),'revenue'=>t('accounting.revenue'),'expense'=>t('accounting.expenses_type')];
?>

<div class="card no-print">
    <div class="card-body" style="padding:12px;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <label><?= t('accounting.account') ?>:</label>
            <select name="account_id" class="form-control" style="width:280px;" required>
                <option value=""><?= t('g.select_account') ?></option>
                <?php foreach ($accounts as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $selectedAccount == $a['id'] ? 'selected' : '' ?>><?= $a['code'] ?> - <?= $a['name'] ?></option>
                <?php endforeach; ?>
            </select>
            <label><?= t('from') ?>:</label><input type="date" name="from" class="form-control" value="<?= $fromDate ?>" style="width:150px;">
            <label><?= t('to') ?>:</label><input type="date" name="to" class="form-control" value="<?= $toDate ?>" style="width:150px;">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i><?= t('view') ?></button>
        </form>
    </div>
</div>

<?php if ($selectedAccount): 
    $account = $pdo->prepare("SELECT * FROM accounts WHERE tenant_id = $tid AND id = ?"); $account->execute([$selectedAccount]); $account = $account->fetch();
    if ($account):
    
    // الرصيد الافتتاحي (مجموع الحركات قبل الفترة)
    $openBal = $pdo->prepare("SELECT COALESCE(SUM(jl.debit),0) - COALESCE(SUM(jl.credit),0) as balance FROM journal_entry_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE je.tenant_id = ? AND jl.account_id = ? AND je.entry_date < ?");
    $openBal->execute([$tid, $selectedAccount, $fromDate]);
    $openingBalance = floatval($openBal->fetch()['balance']);
    
    // حركات الفترة
    $movements = $pdo->prepare("SELECT jl.*, je.entry_number, je.entry_date, je.description as entry_desc, je.reference_type, je.reference_id FROM journal_entry_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE je.tenant_id = ? AND jl.account_id = ? AND je.entry_date BETWEEN ? AND ? ORDER BY je.entry_date, je.id");
    $movements->execute([$tid, $selectedAccount, $fromDate, $toDate]);
    $movements = $movements->fetchAll();
    
    $refTypeLabels = ['sale_invoice'=>t('perms.sales'),'purchase_invoice'=>t('perms.purchases'),'receipt_voucher'=>t('receipts.receipt_voucher'),'payment_voucher'=>t('payments.payment_voucher'),'expense'=>t('expenses.expense'),'manual'=>t('manual')];
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-book-open"></i> 
            <?= t('accounting.general_ledger') ?>: <code style="background:#1d4ed8;color:#fff;padding:2px 10px;border-radius:4px;"><?= $account['code'] ?></code>
            <?= htmlspecialchars($account['name']) ?>
            <span style="background:<?= ['asset'=>'#2563eb','liability'=>'#dc2626','equity'=>'#7c3aed','revenue'=>'#16a34a','expense'=>'#ea580c'][$account['account_type']] ?? '#666' ?>15;color:<?= ['asset'=>'#2563eb','liability'=>'#dc2626','equity'=>'#7c3aed','revenue'=>'#16a34a','expense'=>'#ea580c'][$account['account_type']] ?? '#666' ?>;padding:2px 8px;border-radius:8px;font-size:11px;"><?= $typeLabels[$account['account_type']] ?? '' ?></span>
        </h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><?= t('date') ?></th>
                        <th><?= t('g.entry_number') ?></th>
                        <th><?= t('g.statement') ?></th>
                        <th><?= t('g.reference') ?></th>
                        <th style="text-align:center;"><?= t('accounting.debit') ?></th>
                        <th style="text-align:center;"><?= t('accounting.credit') ?></th>
                        <th style="text-align:center;"><?= t('balance') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:#f0f9ff;font-weight:700;">
                        <td><?= $fromDate ?></td>
                        <td></td>
                        <td><?= t('g.carried_balance') ?></td>
                        <td></td>
                        <td style="text-align:center;"><?= $openingBalance > 0 ? formatMoney($openingBalance) : '-' ?></td>
                        <td style="text-align:center;"><?= $openingBalance < 0 ? formatMoney(abs($openingBalance)) : '-' ?></td>
                        <td style="text-align:center;font-weight:700;"><?= formatMoney($openingBalance) ?></td>
                    </tr>
                    <?php 
                    $runningBalance = $openingBalance;
                    $totalDebit = 0; $totalCredit = 0;
                    foreach ($movements as $m):
                        $runningBalance += $m['debit'] - $m['credit'];
                        $totalDebit += $m['debit'];
                        $totalCredit += $m['credit'];
                    ?>
                    <tr>
                        <td><?= $m['entry_date'] ?></td>
                        <td><code style="font-size:11px;"><?= $m['entry_number'] ?></code></td>
                        <td><?= htmlspecialchars($m['description'] ?: $m['entry_desc']) ?></td>
                        <td><span style="font-size:10px;background:#f1f5f9;padding:1px 5px;border-radius:4px;"><?= $refTypeLabels[$m['reference_type']] ?? $m['reference_type'] ?></span></td>
                        <td style="text-align:center;<?= $m['debit'] > 0 ? 'color:#1d4ed8;font-weight:600;' : 'color:#d1d5db;' ?>"><?= $m['debit'] > 0 ? formatMoney($m['debit']) : '-' ?></td>
                        <td style="text-align:center;<?= $m['credit'] > 0 ? 'color:#dc2626;font-weight:600;' : 'color:#d1d5db;' ?>"><?= $m['credit'] > 0 ? formatMoney($m['credit']) : '-' ?></td>
                        <td style="text-align:center;font-weight:700;color:<?= $runningBalance >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= formatMoney($runningBalance) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#1a2744;color:#fff;font-weight:700;">
                        <td colspan="4" style="padding:10px;"><?= t('g.period_total') ?></td>
                        <td style="text-align:center;padding:10px;"><?= formatMoney($totalDebit) ?></td>
                        <td style="text-align:center;padding:10px;"><?= formatMoney($totalCredit) ?></td>
                        <td style="text-align:center;padding:10px;font-size:15px;"><?= formatMoney($runningBalance) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php endif; endif; ?>

<?php require_once 'includes/footer.php'; ?>
