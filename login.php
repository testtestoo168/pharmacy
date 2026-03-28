<?php
require_once 'includes/config.php';
if (isLoggedIn()) { header('Location: index.php'); exit; }

$error = $_GET['error'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Rate limiting - معطّل مؤقتاً (لن يتم قفل الحساب عند إدخال كلمة مرور خاطئة)
    $security = new SecurityService($pdo);
    // try {
    //     $check = $security->checkLoginAttempts($username, $ip);
    //     if ($check['locked']) { $error = $check['message']; $username = ''; $password = ''; }
    // } catch(Exception $e) {}
    
    if (!$error && $username && $password) {
        $stmt = $pdo->prepare("SELECT u.*, t.status as tenant_status FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id WHERE u.username = ? AND u.is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // تحقق من حالة الصيدلية
            $tStatus = $user['tenant_status'] ?? 'active';
            if ($tStatus === 'suspended') {
                // تحقق هل بسبب انتهاء الاشتراك
                try {
                    $tInfo = $pdo->prepare("SELECT subscription_end, grace_period_days FROM tenants WHERE id=?");
                    $tInfo->execute([$user['tenant_id']]);
                    $tData = $tInfo->fetch();
                    if ($tData && $tData['subscription_end']) {
                        $error = t('login.expired');
                    } else {
                        $error = t('login.suspended');
                    }
                } catch(Exception $e) {
                    $error = t('login.suspended');
                }
            } elseif ($tStatus === 'cancelled') {
                $error = t('login.cancelled');
            } else {
                // تسجيل الدخول
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['tenant_id'] = intval($user['tenant_id']);
                $_SESSION['branch_id'] = intval($user['branch_id']);
                loadUserPermissions($pdo, $user['id']);
                try { $security->recordLoginAttempt($username, $ip, true); } catch(Exception $e) {}
                logActivity($pdo, 'activity.login_action', $user["username"], 'auth');
                header('Location: index.php');
                exit;
            }
        } else {
            try { $security->recordLoginAttempt($username, $ip, false); } catch(Exception $e) {}
            $error = t('login.wrong_credentials');
        }
    } else {
        $error = t('login.enter_both');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= langCode() ?>" dir="<?= langDir() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= t('login.title') ?> - URS Pharmacy</title>
    <link rel="icon" type="image/png" href="assets/logo-small.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        
        body {
            font-family:'Tajawal',sans-serif;
            direction:rtl;
            min-height:100vh;
            background:#f0f2f5;
            display:flex;
            overflow:hidden;
        }

        /* ===== الجانب الأيمن - فورم تسجيل الدخول ===== */
        .login-side {
            width:45%;
            min-height:100vh;
            background:#fff;
            display:flex;
            flex-direction:column;
            justify-content:center;
            align-items:center;
            padding:40px 60px;
            position:relative;
            z-index:2;
        }

        .login-container {
            width:100%;
            max-width:400px;
        }

        /* لوجو في النص */
        .login-logo-top {
            display:flex;
            align-items:center;
            justify-content:center;
            gap:12px;
            margin-bottom:32px;
        }
        .login-logo-top img {
            width:52px;
            height:52px;
            object-fit:contain;
            border-radius:50%;
            border:2px solid #d1d5db;
            padding:4px;
            background:#fff;
            box-shadow:0 2px 8px rgba(0,0,0,0.08);
        }
        .login-logo-top .brand-text {
            font-size:11px;
            color:#6b7280;
            line-height:1.5;
            text-align:right;
        }
        .login-logo-top .brand-text strong {
            display:block;
            font-size:20px;
            font-weight:800;
            color:#0c2d57;
            letter-spacing:-0.5px;
        }

        /* عنوان تسجيل الدخول */
        .login-title {
            font-size:28px;
            font-weight:800;
            color:#0c2d57;
            margin-bottom:8px;
            text-align:center;
        }
        .login-subtitle {
            font-size:14px;
            color:#6b7280;
            margin-bottom:28px;
            text-align:center;
        }

        /* الفورم */
        .form-group {
            margin-bottom:22px;
        }
        .form-label {
            display:block;
            font-size:13px;
            font-weight:600;
            color:#374151;
            margin-bottom:8px;
        }
        .input-wrapper {
            position:relative;
        }
        .form-input {
            width:100%;
            padding:14px 44px 14px 16px;
            border:1.5px solid #d1d5db;
            border-radius:10px;
            font-family:'Tajawal',sans-serif;
            font-size:14px;
            color:#1f2937;
            background:#fafbfc;
            transition:all 0.25s ease;
            direction:rtl;
        }
        .form-input:focus {
            outline:none;
            border-color:#0c2d57;
            background:#fff;
            box-shadow:0 0 0 3px rgba(12,45,87,0.08);
        }
        .form-input::placeholder { color:#9ca3af; }
        
        .input-icon {
            position:absolute;
            right:14px;
            top:50%;
            transform:translateY(-50%);
            color:#9ca3af;
            font-size:14px;
            pointer-events:none;
            transition:color 0.25s;
        }
        .form-input:focus ~ .input-icon { color:#0c2d57; }

        .toggle-password {
            position:absolute;
            left:14px;
            top:50%;
            transform:translateY(-50%);
            color:#9ca3af;
            font-size:14px;
            cursor:pointer;
            border:none;
            background:none;
            padding:4px;
            transition:color 0.2s;
        }
        .toggle-password:hover { color:#0c2d57; }

        /* زر تسجيل الدخول */
        .login-btn {
            width:100%;
            padding:14px;
            background:#0c2d57;
            color:#fff;
            border:none;
            border-radius:10px;
            font-family:'Tajawal',sans-serif;
            font-size:15px;
            font-weight:700;
            cursor:pointer;
            transition:all 0.25s ease;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            margin-top:8px;
        }
        .login-btn:hover { background:#0a2445; box-shadow:0 4px 14px rgba(12,45,87,0.3); }
        .login-btn:active { transform:scale(0.98); }

        /* رسالة خطأ */
        .error-msg {
            background:#fef2f2;
            color:#991b1b;
            border:1px solid #fecaca;
            border-radius:10px;
            padding:12px 16px;
            font-size:13px;
            margin-bottom:22px;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .error-msg i { font-size:16px; color:#dc2626; }

        /* فوتر */
        .login-footer {
            margin-top:48px;
            text-align:center;
            font-size:11px;
            color:#9ca3af;
            line-height:1.8;
        }
        .login-footer .dev {
            margin-top:4px;
            opacity:0.7;
        }

        /* ===== الجانب الأيسر - الصيدلية ===== */
        .brand-side {
            width:55%;
            min-height:100vh;
            position:relative;
            overflow:hidden;
            display:flex;
            flex-direction:column;
            justify-content:center;
            align-items:center;
        }

        /* صورة الخلفية - صورة صيدلية حقيقية */
        .brand-bg {
            position:absolute;
            top:0; left:0; right:0; bottom:0;
            background-image:url('assets/pharmacy-bg.jpg');
            background-size:cover;
            background-position:center;
            background-repeat:no-repeat;
            z-index:0;
        }

        /* overlay كحلي فوق الصورة */
        .brand-bg::after {
            content:'';
            position:absolute;
            top:0; left:0; right:0; bottom:0;
            background:linear-gradient(135deg, rgba(12,45,87,0.88) 0%, rgba(10,36,69,0.82) 40%, rgba(15,52,96,0.78) 100%);
            z-index:1;
        }

        /* SVG Pattern - أيقونات صيدلانية فوق الصورة */
        .brand-bg::before {
            content:'';
            position:absolute;
            top:0; left:0; right:0; bottom:0;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='800' viewBox='0 0 800 800'%3E%3Cg fill='none' stroke='rgba(255,255,255,0.06)' stroke-width='1.5'%3E%3C!-- Pill capsule --%3E%3Crect x='60' y='50' width='60' height='28' rx='14'/%3E%3Cline x1='90' y1='50' x2='90' y2='78'/%3E%3C!-- Mortar pestle --%3E%3Cpath d='M240 45 Q260 80 280 45'/%3E%3Cline x1='260' y1='35' x2='260' y2='80'/%3E%3Cellipse cx='260' cy='82' rx='28' ry='8'/%3E%3C!-- Medicine bottle --%3E%3Crect x='440' y='40' width='40' height='55' rx='4'/%3E%3Crect x='448' y='32' width='24' height='12' rx='2'/%3E%3Cline x1='450' y1='62' x2='470' y2='62'/%3E%3Cline x1='450' y1='72' x2='465' y2='72'/%3E%3C!-- Cross/Plus pharmacy --%3E%3Crect x='640' y='45' width='30' height='10' rx='2'/%3E%3Crect x='650' y='35' width='10' height='30' rx='2'/%3E%3C!-- Stethoscope --%3E%3Ccircle cx='100' cy='220' r='20'/%3E%3Ccircle cx='100' cy='220' r='8'/%3E%3Cpath d='M85 205 Q70 170 90 155 Q110 140 120 170'/%3E%3C!-- Syringe --%3E%3Crect x='260' y='200' width='60' height='14' rx='3'/%3E%3Cline x1='320' y1='207' x2='340' y2='207'/%3E%3Cline x1='270' y1='200' x2='270' y2='214'/%3E%3Cline x1='285' y1='200' x2='285' y2='214'/%3E%3Cline x1='300' y1='200' x2='300' y2='214'/%3E%3C!-- Heart rate --%3E%3Cpolyline points='420,220 440,220 450,200 460,240 470,210 480,220 520,220'/%3E%3C!-- DNA helix --%3E%3Cpath d='M640 180 Q660 200 640 220 Q620 240 640 260'/%3E%3Cpath d='M660 180 Q640 200 660 220 Q680 240 660 260'/%3E%3Cline x1='640' y1='200' x2='660' y2='200'/%3E%3Cline x1='640' y1='220' x2='660' y2='220'/%3E%3Cline x1='640' y1='240' x2='660' y2='240'/%3E%3C!-- Pill tablet round --%3E%3Ccircle cx='80' cy='400' r='18'/%3E%3Cline x1='62' y1='400' x2='98' y2='400'/%3E%3C!-- Dropper --%3E%3Crect x='250' y='380' width='12' height='40' rx='2'/%3E%3Cellipse cx='256' cy='376' rx='10' ry='8'/%3E%3Cpath d='M252 420 L256 430 L260 420'/%3E%3C!-- Rx symbol --%3E%3Ctext x='440' y='415' font-size='40' fill='rgba(255,255,255,0.04)' font-family='serif'%3ERx%3C/text%3E%3C!-- Thermometer --%3E%3Crect x='640' y='370' width='10' height='50' rx='5'/%3E%3Ccircle cx='645' cy='425' r='10'/%3E%3C!-- Capsules --%3E%3Crect x='50' y='560' width='50' height='22' rx='11' transform='rotate(-15 75 571)'/%3E%3Cline x1='75' y1='560' x2='75' y2='582' transform='rotate(-15 75 571)'/%3E%3C!-- Molecule --%3E%3Ccircle cx='460' cy='560' r='10'/%3E%3Ccircle cx='490' cy='545' r='8'/%3E%3Ccircle cx='490' cy='575' r='8'/%3E%3Cline x1='468' y1='555' x2='484' y2='548'/%3E%3Cline x1='468' y1='565' x2='484' y2='572'/%3E%3C!-- Shield --%3E%3Cpath d='M645 545 Q645 530 660 525 Q675 530 675 545 Q675 570 660 580 Q645 570 645 545'/%3E%3Crect x='655' y='542' width='10' height='4' rx='1'/%3E%3Crect x='658' y='539' width='4' height='10' rx='1'/%3E%3C/g%3E%3C/svg%3E");
            background-size:800px 800px;
            z-index:2;
        }

        .brand-content {
            position:relative;
            z-index:3;
            text-align:center;
            padding:40px;
            max-width:560px;
        }

        /* لوجو URS الكبير */
        .brand-logo {
            width:120px;
            height:120px;
            background:#fff;
            border-radius:24px;
            padding:12px;
            margin:0 auto 32px;
            box-shadow:0 8px 32px rgba(0,0,0,0.2);
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .brand-logo img {
            width:100%;
            height:100%;
            object-fit:contain;
        }

        .brand-title {
            font-size:34px;
            font-weight:800;
            color:#fff;
            margin-bottom:8px;
            letter-spacing:-0.5px;
        }
        .brand-tagline {
            font-size:16px;
            color:rgba(255,255,255,0.7);
            margin-bottom:48px;
            font-weight:400;
        }

        /* الميزات */
        .features-list {
            display:flex;
            flex-direction:column;
            gap:20px;
            text-align:right;
        }
        .feature-item {
            display:flex;
            align-items:center;
            gap:16px;
            direction:rtl;
        }
        .feature-icon {
            width:48px;
            height:48px;
            min-width:48px;
            background:rgba(255,255,255,0.1);
            border:1px solid rgba(255,255,255,0.15);
            border-radius:12px;
            display:flex;
            align-items:center;
            justify-content:center;
            color:rgba(255,255,255,0.8);
            font-size:18px;
            backdrop-filter:blur(4px);
        }
        .feature-text {
            color:rgba(255,255,255,0.85);
            font-size:15px;
            font-weight:500;
            line-height:1.6;
        }

        /* ===== ريسبونسف ===== */
        @media (max-width:1100px) {
            .login-side { width:50%; padding:40px 40px; }
            .brand-side { width:50%; }
        }
        @media (max-width:900px) {
            body { flex-direction:column; overflow:auto; }
            .brand-side { 
                width:100%; 
                min-height:300px; 
                order:-1;
            }
            .login-side { 
                width:100%; 
                min-height:auto; 
                padding:30px 24px;
            }
            .brand-content { padding:30px 20px; }
            .brand-logo { width:80px; height:80px; border-radius:16px; margin-bottom:20px; }
            .brand-title { font-size:24px; }
            .brand-tagline { font-size:14px; margin-bottom:24px; }
            .features-list { gap:14px; }
            .feature-icon { width:40px; height:40px; min-width:40px; font-size:15px; }
            .feature-text { font-size:13px; }
            .login-title { font-size:22px; }
        }
        @media (max-width:480px) {
            .login-side { padding:24px 20px; }
            .login-logo-top { margin-bottom:32px; }
            .login-logo-top img { width:42px; height:42px; }
            .login-logo-top .brand-text strong { font-size:17px; }
            .login-title { font-size:20px; }
            .brand-content { padding:24px 16px; }
            .features-list { display:none; }
        }

        /* أنيميشن دخول */
        @keyframes fadeInUp {
            from { opacity:0; transform:translateY(20px); }
            to { opacity:1; transform:translateY(0); }
        }
        .login-container { animation: fadeInUp 0.6s ease-out; }
        .brand-content { animation: fadeInUp 0.8s ease-out 0.2s both; }
    </style>
</head>
<body>

<!-- Language Switcher -->
<a href="<?= langSwitchUrl() ?>" style="position:fixed;top:20px;<?= isRTL() ? 'left' : 'right' ?>:20px;z-index:100;display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#0f3460,#1a2744);color:#fff;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 4px 15px rgba(15,52,96,0.3);border:none;transition:all 0.3s;letter-spacing:0.5px;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(15,52,96,0.4)'" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 15px rgba(15,52,96,0.3)'">
    <i class="fas fa-globe" style="font-size:15px;"></i> <?= t('header.switch_lang') ?>
</a>

<!-- ====== Login Form ====== -->
<div class="login-side">
    <div class="login-container">

        <!-- عنوان -->
        <h1 class="login-title"><?= t('login.title') ?></h1>
        <p class="login-subtitle"><?= t('login.subtitle') ?></p>

        <!-- لوجو في النص تحت العنوان -->
        <div class="login-logo-top">
            <img src="assets/logo.png" alt="URS">
            <div class="brand-text">
                <strong>URS Pharmacy</strong>
                <?= t('pharmacy_system') ?>
            </div>
        </div>

        <!-- رسالة خطأ -->
        <?php if ($error): ?>
        <div class="error-msg">
            <i class="fas fa-exclamation-circle"></i>
            <?= $error ?>
        </div>
        <?php endif; ?>

        <!-- فورم -->
        <form method="POST">
            <?= csrfField() ?>
            
            <div class="form-group">
                <label class="form-label"><?= t('login.username') ?></label>
                <div class="input-wrapper">
                    <input type="text" name="username" class="form-input" placeholder="<?= t('login.username') ?>" required autofocus>
                    <i class="fas fa-user input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label"><?= t('login.password') ?></label>
                <div class="input-wrapper">
                    <input type="password" name="password" id="passwordField" class="form-input" placeholder="<?= t('login.enter_password') ?>" required>
                    <i class="fas fa-lock input-icon"></i>
                    <button type="button" class="toggle-password" onclick="togglePass()">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="login-btn">
                <i class="fas fa-sign-in-alt"></i>
                <?= t('login.login_btn') ?>
            </button>
        </form>

        <!-- فوتر -->
        <div class="login-footer">
            URS Pharmacy &copy; <?= date('Y') ?>
            <div class="dev"><?= t('footer.developed_by') ?></div>
        </div>
    </div>
</div>

<!-- ====== الجانب الأيسر - البراند ====== -->
<div class="brand-side">
    <div class="brand-bg"></div>
    <div class="brand-content">
        
        <div class="brand-logo">
            <img src="assets/logo.png" alt="URS Pharmacy">
        </div>

        <h2 class="brand-title"><?= t('pharmacy_system') ?></h2>
        <p class="brand-tagline"><?= t('login.tagline') ?></p>

        <div class="features-list">
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-pills"></i></div>
                <div class="feature-text"><?= t('login.feature1') ?></div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="feature-text"><?= t('login.feature2') ?></div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <div class="feature-text"><?= t('login.feature3') ?></div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                <div class="feature-text"><?= t('login.feature4') ?></div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePass() {
    var f = document.getElementById('passwordField');
    var e = document.getElementById('eyeIcon');
    if (f.type === 'password') {
        f.type = 'text';
        e.classList.remove('fa-eye');
        e.classList.add('fa-eye-slash');
    } else {
        f.type = 'password';
        e.classList.remove('fa-eye-slash');
        e.classList.add('fa-eye');
    }
}
</script>

</body>
</html>
