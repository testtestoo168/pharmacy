<?php
require_once 'includes/helpers/env.php';
require_once 'includes/i18n.php';
initLanguage();
require_once 'includes/services/Database.php';
require_once 'includes/services/SecurityService.php';
$pdo = Database::getInstance()->getPdo();
SecurityService::initSecureSession();

if (isset($_GET['logout'])) { unset($_SESSION['super_admin_id'],$_SESSION['super_admin_name']); }
if (isset($_SESSION['super_admin_id'])) { header('Location: super_admin/dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['super_admin_id'] = $admin['id'];
            $_SESSION['super_admin_name'] = $admin['full_name'];
            $pdo->prepare("UPDATE super_admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
            header('Location: super_admin/dashboard.php'); exit;
        } else { $error = t('super.wrong_credentials'); }
    } else { $error = t('login.enter_both'); }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('g.platform_admin') ?> | URS</title>
<link rel="icon" type="image/png" href="assets/logo-small.png">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-main,#f0f2f5);margin:0;font-family:'Tajawal',sans-serif;}
.login-box{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.08);padding:40px;width:100%;max-width:400px;text-align:center;}
.login-box img{height:50px;margin-bottom:12px;}
.login-box h1{font-size:20px;color:#1a2744;margin-bottom:4px;}
.login-box p{color:#64748b;font-size:13px;margin-bottom:24px;}
.form-group{text-align:right;margin-bottom:14px;}
.form-group label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px;}
.form-group input{width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:'Tajawal';box-sizing:border-box;}
.form-group input:focus{outline:none;border-color:#1a2744;}
.btn-login{width:100%;padding:12px;background:#1a2744;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;font-family:'Tajawal';cursor:pointer;}
.btn-login:hover{background:#0f1b30;}
.error-msg{background:#fef2f2;color:#dc2626;padding:10px;border-radius:8px;margin-bottom:14px;font-size:13px;border:1px solid #fecaca;}
</style>
</head>
<body>
<div class="login-box">
    <img src="assets/logo-small.png" alt="URS">
    <h1><?= t('g.platform_admin') ?></h1>
    <p>URS Pharmacy - Super Admin</p>
    <?php if ($error): ?><div class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <div class="form-group"><label><?= t('login.username') ?></label><input type="text" name="username" required autofocus></div>
        <div class="form-group"><label><?= t('login.password') ?></label><input type="password" name="password" required></div>
        <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt"></i> <?= t('login.login_btn') ?></button>
    </form>
</div>
</body>
</html>
