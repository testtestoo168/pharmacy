<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.chart_of_accounts');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('accounting_view');

// إضافة حساب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    try { verifyCsrfToken();
        $pdo->prepare("INSERT INTO accounts (tenant_id,code, name, name_ar, parent_id, account_type, is_system) VALUES (?,?,?,?,?,?,0)")
            ->execute([$tid,$_POST['code'], $_POST['name'], $_POST['name'], $_POST['parent_id'] ?: null, $_POST['account_type']]);
        logActivity($pdo, 'activity.add_account', $_POST['code'] . ' - ' . $_POST['name'], 'accounting');
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">' . t('error') . ': ' . $e->getMessage() . '</div>';
    }
}

// حذف حساب
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $acc = $pdo->prepare("SELECT is_system FROM accounts WHERE tenant_id = $tid AND id = ?"); $acc->execute([$id]); $a = $acc->fetch();
    if ($a && !$a['is_system']) {
        $hasLines = $pdo->prepare("SELECT COUNT(*) FROM journal_entry_lines jl JOIN journal_entries je ON je.id = jl.entry_id WHERE je.tenant_id = ? AND jl.account_id = ?"); $hasLines->execute([$tid, $id]);
        if ($hasLines->fetchColumn() == 0) {
            $pdo->prepare("DELETE FROM accounts WHERE tenant_id = $tid AND id = ? AND is_system = 0")->execute([$id]);
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
        } else {
            echo '<div class="alert alert-danger">' . t('g.cant_delete_has_entries') . '</div>';
        }
    } else {
        echo '<div class="alert alert-danger">' . t('g.cant_delete_system') . '</div>';
    }
}

$accounts = $pdo->query("SELECT a.*, p.name as parent_name, p.name_ar as parent_name_ar FROM accounts a LEFT JOIN accounts p ON a.parent_id = p.id WHERE a.tenant_id = $tid ORDER BY a.code")->fetchAll();
$parentAccounts = $pdo->query("SELECT id, code, name, name_ar FROM accounts WHERE tenant_id = $tid ORDER BY code")->fetchAll();
// Helper: show Arabic name when available and language is Arabic
function accName($acc) { return (currentLang() === 'ar' && !empty($acc['name_ar'])) ? $acc['name_ar'] : $acc['name']; }
$typeLabels = ['asset'=>t('accounting.assets'),'liability'=>t('accounting.liabilities'),'equity'=>t('accounting.equity'),'revenue'=>t('accounting.revenue'),'expense'=>t('accounting.expenses_type')];
$typeColors = ['asset'=>'#2563eb','liability'=>'#dc2626','equity'=>'#7c3aed','revenue'=>'#16a34a','expense'=>'#ea580c'];
?>

<div class="card no-print">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i><?= t('accounting.add_account') ?></h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="add_account" value="1">
            <div class="form-row">
                <div class="form-group">
                    <label><?= t('accounting.account_code') ?></label>
                    <input type="text" name="code" class="form-control" required placeholder="<?= t('placeholder.example_number') ?>">
                </div>
                <div class="form-group">
                    <label><?= t('accounting.account_name') ?></label>
                    <input type="text" name="name" class="form-control" required placeholder="<?= t('accounting.account_name') ?>">
                </div>
                <div class="form-group">
                    <label><?= t('accounting.account_type') ?></label>
                    <select name="account_type" class="form-control" required>
                        <option value="asset"><?= t('accounting.assets') ?></option>
                        <option value="liability"><?= t('accounting.liabilities') ?></option>
                        <option value="equity"><?= t('accounting.equity') ?></option>
                        <option value="revenue"><?= t('accounting.revenue') ?></option>
                        <option value="expense"><?= t('accounting.expenses_type') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label><?= t('accounting.parent_account') ?></label>
                    <select name="parent_id" class="form-control">
                        <option value="">-- <?= t('accounting.main_account') ?> --</option>
                        <?php foreach ($parentAccounts as $pa): ?>
                        <option value="<?= $pa['id'] ?>"><?= $pa['code'] ?> - <?= htmlspecialchars((currentLang() === 'ar' && !empty($pa['name_ar'])) ? $pa['name_ar'] : $pa['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('save') ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-sitemap"></i> <?= t('accounting.chart_of_accounts') ?></h3>
        <span class="badge badge-info"><?= count($accounts) ?><?= t('accounting.account') ?></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width:100px;"><?= t('accounting.code') ?></th>
                        <th><?= t('accounting.account_name') ?></th>
                        <th><?= t('accounting.parent_account') ?></th>
                        <th><?= t('type') ?></th>
                        <th style="text-align:center;"><?= t('balance') ?></th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                    <tr>
                        <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-weight:700;"><?= $acc['code'] ?></code></td>
                        <td>
                            <?php
                            $indent = 0;
                            if ($acc['parent_id']) {
                                $indent = 1;
                                $checkParent = $pdo->prepare("SELECT parent_id FROM accounts WHERE tenant_id = $tid AND id = ?");
                                $checkParent->execute([$acc['parent_id']]);
                                $pp = $checkParent->fetch();
                                if ($pp && $pp['parent_id']) $indent = 2;
                            }
                            ?>
                            <span style="margin-right:<?= $indent * 20 ?>px;">
                                <?php if ($indent > 0): ?><i class="fas fa-level-up-alt fa-rotate-90" style="color:#d1d5db;font-size:11px;margin-left:4px;"></i><?php endif; ?>
                                <strong><?= htmlspecialchars(accName($acc)) ?></strong>
                                <?php if ($acc['is_system']): ?><span style="background:#fef3c7;color:#92400e;font-size:9px;padding:1px 5px;border-radius:8px;"><?= t('nav.system') ?></span><?php endif; ?>
                            </span>
                        </td>
                        <td style="color:#6b7280;font-size:12px;"><?= $acc['parent_name'] ? htmlspecialchars((currentLang() === 'ar' && !empty($acc['parent_name_ar'])) ? $acc['parent_name_ar'] : $acc['parent_name']) : '-' ?></td>
                        <td><span style="background:<?= $typeColors[$acc['account_type']] ?? '#666' ?>15;color:<?= $typeColors[$acc['account_type']] ?? '#666' ?>;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:600;"><?= $typeLabels[$acc['account_type']] ?? $acc['account_type'] ?></span></td>
                        <td style="text-align:center;font-weight:700;color:<?= $acc['balance'] >= 0 ? '#16a34a' : '#dc2626' ?>;"><?= formatMoney(abs($acc['balance'])) ?></td>
                        <td>
                            <?php if (!$acc['is_system']): ?>
                            <a href="?delete=<?= $acc['id'] ?>" onclick="return confirm('<?= t('accounting.delete_account') ?>')" class="btn btn-sm btn-danger" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
