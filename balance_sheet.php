<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.balance_sheet');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('reports_financial');
$company = getCompanySettings($pdo);
$asOfDate = $_GET['as_of'] ?? date('Y-m-d');

$accounts = $pdo->prepare("SELECT a.id, a.code, a.name, a.account_type,
    COALESCE((SELECT SUM(jl.debit) FROM journal_entry_lines jl JOIN journal_entries je ON je.id=jl.entry_id WHERE jl.account_id=a.id AND je.tenant_id=? AND je.entry_date<=?),0) as td,
    COALESCE((SELECT SUM(jl.credit) FROM journal_entry_lines jl JOIN journal_entries je ON je.id=jl.entry_id WHERE jl.account_id=a.id AND je.tenant_id=? AND je.entry_date<=?),0) as tc
    FROM accounts a WHERE a.tenant_id=? AND a.is_active=1 ORDER BY a.code");
$accounts->execute([$tid,$asOfDate,$tid,$asOfDate,$tid]);

$assets=[]; $liabilities=[]; $equity=[]; $tA=0; $tL=0; $tE=0;
foreach($accounts->fetchAll() as $a){
    $bal=$a['td']-$a['tc']; if($bal==0 && $a['td']==0) continue;
    $a['balance']=abs($bal);
    if($a['account_type']==='asset'){$assets[]=$a;$tA+=$bal;}
    elseif($a['account_type']==='liability'){$liabilities[]=$a;$tL+=abs($bal);}
    elseif($a['account_type']==='equity'){$equity[]=$a;$tE+=abs($bal);}
}
$rev=$pdo->prepare("SELECT COALESCE(SUM(jl.credit)-SUM(jl.debit),0) FROM journal_entry_lines jl JOIN journal_entries je ON je.id=jl.entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.tenant_id=? AND a.account_type='revenue' AND je.entry_date<=?");
$rev->execute([$tid,$asOfDate]); $totalRev=floatval($rev->fetchColumn());
$exp=$pdo->prepare("SELECT COALESCE(SUM(jl.debit)-SUM(jl.credit),0) FROM journal_entry_lines jl JOIN journal_entries je ON je.id=jl.entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.tenant_id=? AND a.account_type='expense' AND je.entry_date<=?");
$exp->execute([$tid,$asOfDate]); $totalExp=floatval($exp->fetchColumn());
$netIncome=$totalRev-$totalExp; $tE+=$netIncome;
?>
<div class="card no-print" style="margin-bottom:16px;"><div class="card-body" style="padding:12px;">
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <label><?= t('g.until_date') ?>:</label><input type="date" name="as_of" class="form-control" value="<?=$asOfDate?>" style="width:170px;">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i><?= t('view') ?></button>
        <button type="button" class="btn btn-sm btn-info" onclick="window.print()"><i class="fas fa-print"></i><?= t('print') ?></button>
    </form>
</div></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="card"><div class="card-header" style="background:#eff6ff;"><h3 style="color:#1d4ed8;margin:0;font-size:15px;"><i class="fas fa-arrow-up"></i><?= t('accounting.assets') ?></h3></div>
        <div class="table-responsive"><table><thead><tr><th><?= t('accounting.code') ?></th><th><?= t('accounting.account') ?></th><th><?= t('balance') ?></th></tr></thead><tbody>
        <?php foreach($assets as $a): ?><tr><td style="font-size:12px;color:#64748b;"><?=$a['code']?></td><td><?=htmlspecialchars($a['name'])?></td><td style="font-weight:600;"><?=formatMoney($a['balance'])?></td></tr><?php endforeach; ?>
        <tr style="background:#eff6ff;font-weight:700;"><td colspan="2"><?= t('g.total_assets') ?></td><td style="color:#1d4ed8;"><?=formatMoney($tA)?></td></tr>
        </tbody></table></div></div>
    <div>
        <div class="card" style="margin-bottom:16px;"><div class="card-header" style="background:#fef2f2;"><h3 style="color:#dc2626;margin:0;font-size:15px;"><i class="fas fa-arrow-down"></i><?= t('accounting.liabilities') ?></h3></div>
            <div class="table-responsive"><table><thead><tr><th><?= t('accounting.code') ?></th><th><?= t('accounting.account') ?></th><th><?= t('balance') ?></th></tr></thead><tbody>
            <?php foreach($liabilities as $l): ?><tr><td style="font-size:12px;color:#64748b;"><?=$l['code']?></td><td><?=htmlspecialchars($l['name'])?></td><td style="font-weight:600;"><?=formatMoney($l['balance'])?></td></tr><?php endforeach; ?>
            <tr style="background:#fef2f2;font-weight:700;"><td colspan="2"><?= t('g.total_liabilities') ?></td><td style="color:#dc2626;"><?=formatMoney($tL)?></td></tr>
            </tbody></table></div></div>
        <div class="card"><div class="card-header" style="background:#f0fdf4;"><h3 style="color:#16a34a;margin:0;font-size:15px;"><i class="fas fa-landmark"></i><?= t('accounting.equity') ?></h3></div>
            <div class="table-responsive"><table><thead><tr><th><?= t('accounting.code') ?></th><th><?= t('accounting.account') ?></th><th><?= t('balance') ?></th></tr></thead><tbody>
            <?php foreach($equity as $e): ?><tr><td style="font-size:12px;color:#64748b;"><?=$e['code']?></td><td><?=htmlspecialchars($e['name'])?></td><td style="font-weight:600;"><?=formatMoney($e['balance'])?></td></tr><?php endforeach; ?>
            <tr><td></td><td style="font-weight:600;"><?= t('g.net_profit') ?></td><td style="font-weight:700;color:<?=$netIncome>=0?'#16a34a':'#dc2626'?>;"><?=formatMoney($netIncome)?></td></tr>
            <tr style="background:#f0fdf4;font-weight:700;"><td colspan="2"><?= t('g.total_equity') ?></td><td style="color:#16a34a;"><?=formatMoney($tE)?></td></tr>
            </tbody></table></div></div>
    </div>
</div>
<div class="card" style="margin-top:16px;"><div class="card-body" style="padding:14px;display:flex;justify-content:space-between;align-items:center;">
    <div><strong><?= t('g.total_assets') ?>:</strong> <?=formatMoney($tA)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></div>
    <div><strong><?= t('g.liabilities_equity') ?>:</strong> <?=formatMoney($tL+$tE)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></div>
    <div><?php $diff=abs($tA-($tL+$tE)); if($diff<0.01): ?><span class="badge badge-success"><i class="fas fa-check"></i> <?= t('g.balanced') ?></span><?php else: ?><span class="badge badge-danger"><i class="fas fa-times"></i> <?= t('g.difference') ?>: <?=formatMoney($diff)?></span><?php endif; ?></div>
</div></div>
<?php require_once 'includes/footer.php'; ?>
