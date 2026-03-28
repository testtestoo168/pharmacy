<?php
require_once 'includes/config.php';
$pageTitle = t('users.change_password');
require_once 'includes/header.php';
$tid = getTenantId();

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken();
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        $user = $pdo->prepare("SELECT password FROM users WHERE id = ? AND tenant_id = ?");
        $user->execute([$_SESSION['user_id'], $tid]);
        $userData = $user->fetch();
        
        if (!$userData || !password_verify($current, $userData['password'])) {
            $err = t('pwd.wrong_current');
        } elseif (strlen($new) < 6) {
            $err = t('pwd.too_short');
        } elseif ($new !== $confirm) {
            $err = t('pwd.mismatch');
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND tenant_id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id'], $tid]);
            logActivity($pdo, 'activity.change_password', '', 'users');
            $msg = t('pwd.changed');
        }
    } catch (Exception $e) { $err = $e->getMessage(); }
}
?>
<?php if ($msg): ?><div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger" style="background:#fef2f2;color:#dc2626;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card" style="max-width:500px;">
    <div class="card-header"><h3><i class="fas fa-key"></i><?= t('users.change_password') ?></h3></div>
    <div class="card-body" style="padding:20px;">
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group" style="margin-bottom:14px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('pwd.current') ?></label><input type="password" name="current_password" class="form-control" required></div>
            <div class="form-group" style="margin-bottom:14px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('pwd.new') ?></label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
            <div class="form-group" style="margin-bottom:14px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('pwd.confirm_new') ?></label><input type="password" name="confirm_password" class="form-control" required></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('users.change_password') ?></button>
        </form>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>
