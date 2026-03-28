<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.financial_close');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('accounting_edit');
$company = getCompanySettings($pdo);

// إقفال فترة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_period'])) {
    try {
        verifyCsrfToken();
        $name = $_POST['period_name'];
        $start = $_POST['start_date'];
        $end = $_POST['end_date'];
        
        // تحقق من عدم التداخل
        $overlap = $pdo->prepare("SELECT COUNT(*) FROM financial_periods WHERE tenant_id=? AND status='closed' AND ((start_date<=? AND end_date>=?) OR (start_date<=? AND end_date>=?))");
        $overlap->execute([$tid,$start,$start,$end,$end]);
        if ($overlap->fetchColumn() > 0) throw new Exception(t('financial.period_overlap'));
        
        $pdo->prepare("INSERT INTO financial_periods (tenant_id, period_name, start_date, end_date, status, closed_by, closed_at) VALUES (?,?,?,?,'closed',?,NOW())")
            ->execute([$tid, $name, $start, $end, $_SESSION['user_id']]);
        
        logActivity($pdo, 'activity.close_period', "$name ($start - $end)", 'accounting');
        echo '<div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-check-circle"></i> ' . t('financial.period_closed') . '</div>';
    } catch (Exception $e) {
        echo '<div class="alert alert-danger" style="background:#fef2f2;color:#dc2626;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-times-circle"></i> '.htmlspecialchars($e->getMessage()).'</div>';
    }
}

// فتح فترة (إلغاء الإقفال)
if (isset($_GET['reopen']) && hasPermission('accounting_edit')) {
    $pid = intval($_GET['reopen']);
    $pdo->prepare("UPDATE financial_periods SET status='open', closed_by=NULL, closed_at=NULL WHERE id=? AND tenant_id=?")->execute([$pid,$tid]);
    header('Location: financial_close?msg=reopened'); exit;
}

$periods = $pdo->prepare("SELECT fp.*, u.full_name as closed_by_name FROM financial_periods fp LEFT JOIN users u ON u.id=fp.closed_by WHERE fp.tenant_id=? ORDER BY fp.start_date DESC");
$periods->execute([$tid]);
$periodsList = $periods->fetchAll();
?>
<?php if(isset($_GET['msg'])): ?><div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?= t('saved_success') ?></div><?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h3><i class="fas fa-lock"></i><?= t('g.new_period_close') ?></h3></div>
    <div class="card-body" style="padding:20px;">
        <form method="POST" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
            <?=csrfField()?>
            <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('g.period_name') ?></label><input type="text" name="period_name" class="form-control" required placeholder="<?= t('g.period_name') ?>" style="width:200px;"></div>
            <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('from') ?></label><input type="date" name="start_date" class="form-control" required style="width:160px;"></div>
            <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('to') ?></label><input type="date" name="end_date" class="form-control" required style="width:160px;"></div>
            <button type="submit" name="close_period" class="btn btn-primary" onclick="return confirm('<?= t('financial.confirm_close') ?>')"><i class="fas fa-lock"></i><?= t('g.close_period') ?></button>
        </form>
    </div>
</div>

<div class="card"><div class="card-header"><h3><i class="fas fa-calendar-check"></i><?= t('accounting.financial_close') ?></h3></div>
    <div class="table-responsive"><table>
        <thead><tr><th><?= t('g.period_name') ?></th><th><?= t('from') ?></th><th><?= t('to') ?></th><th><?= t('status') ?></th><th><?= t('g.closed_by') ?></th><th><?= t('g.close_date') ?></th><th><?= t('actions') ?></th></tr></thead>
        <tbody>
        <?php if(empty($periodsList)): ?><tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;"><?= t('no_results') ?></td></tr>
        <?php else: foreach($periodsList as $p): ?>
        <tr>
            <td style="font-weight:600;"><?=htmlspecialchars($p['period_name'])?></td>
            <td><?=$p['start_date']?></td>
            <td><?=$p['end_date']?></td>
            <td><?php if($p['status']==='closed'): ?><span class="badge badge-danger"><i class="fas fa-lock"></i> <?= t('g.closed') ?></span><?php else: ?><span class="badge badge-success"><i class="fas fa-lock-open"></i> <?= t('g.open') ?></span><?php endif; ?></td>
            <td style="font-size:12px;"><?=$p['closed_by_name']??'-'?></td>
            <td style="font-size:12px;"><?=$p['closed_at']??'-'?></td>
            <td><?php if($p['status']==='closed'): ?><a href="?reopen=<?=$p['id']?>" style="color:#1d4ed8;font-size:12px;text-decoration:none;" onclick="return confirm('<?= t('accounting.reopen_period') ?>')"><i class="fas fa-lock-open"></i> <?= t('g.open') ?></a><?php endif; ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div></div>
<?php require_once 'includes/footer.php'; ?>
