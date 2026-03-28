<?php
/**
 * URS Pharmacy - FULL DIAGNOSTIC (Hostinger Safe - no exec())
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>URS Diagnostic</title></head><body style='font-family:monospace;padding:20px;direction:ltr;'>";
echo "<h1>🔍 URS Full System Diagnostic</h1>";

$errors = [];

// Step 1
echo "<h2>Step 1: PHP Environment</h2>";
echo "PHP: " . PHP_VERSION . "<br>";
echo "gettext: " . (extension_loaded('gettext') ? '⚠️ YES' : '✅ NO') . "<br>";
echo "Disabled functions: " . ini_get('disable_functions') . "<br>";

// Step 2: Files
echo "<h2>Step 2: Critical Files</h2>";
$files = ['includes/i18n.php','includes/lang/ar.php','includes/lang/en.php','includes/config.php',
    'includes/header.php','includes/footer.php','includes/helpers/env.php','includes/helpers/functions.php',
    'includes/services/Database.php','includes/services/SecurityService.php','includes/services/TenantService.php',
    'includes/services/AccountingService.php','includes/services/StockService.php','includes/services/ZATCAService.php',
    'includes/middleware.php','index.php','login.php','.env'];
foreach ($files as $f) {
    $exists = file_exists(__DIR__.'/'.$f);
    echo ($exists ? '✅' : '❌') . " $f" . ($exists ? ' ('.filesize(__DIR__.'/'.$f).' bytes)' : ' MISSING!') . "<br>";
    if (!$exists) $errors[] = "Missing: $f";
}

// Step 3: Load config step by step
echo "<h2>Step 3: Loading Config Step-by-Step</h2>";

$steps = [
    ['helpers/env.php', 'includes/helpers/env.php'],
    ['helpers/functions.php', 'includes/helpers/functions.php'],
    ['i18n.php', 'includes/i18n.php'],
    ['Database.php', 'includes/services/Database.php'],
    ['SecurityService.php', 'includes/services/SecurityService.php'],
    ['TenantService.php', 'includes/services/TenantService.php'],
    ['AccountingService.php', 'includes/services/AccountingService.php'],
    ['StockService.php', 'includes/services/StockService.php'],
    ['ZATCAService.php', 'includes/services/ZATCAService.php'],
    ['middleware.php', 'includes/middleware.php'],
];

foreach ($steps as $s) {
    echo "- Loading {$s[0]}... ";
    try {
        require_once __DIR__.'/'.$s[1];
        echo "✅<br>";
    } catch (Throwable $e) {
        echo "❌ ".$e->getMessage()." at ".$e->getFile().":".$e->getLine()."<br>";
        $errors[] = $s[0].": ".$e->getMessage();
    }
}

// Step 4: Database
echo "<h2>Step 4: Database</h2>";
try {
    $pdo = Database::getInstance()->getPdo();
    echo "✅ Connected<br>";
} catch (Throwable $e) {
    echo "❌ ".$e->getMessage()."<br>";
    $errors[] = "DB: ".$e->getMessage();
}

// Step 5: Session + i18n
echo "<h2>Step 5: Session & i18n</h2>";
try {
    SecurityService::initSecureSession();
    echo "✅ Session started<br>";
    initLanguage();
    loadTranslations();
    echo "✅ Language: ".currentLang()." / Dir: ".langDir()."<br>";
    echo "✅ t('nav.dashboard') = ".t('nav.dashboard')."<br>";
    echo "✅ t('save') = ".t('save')."<br>";
} catch (Throwable $e) {
    echo "❌ ".$e->getMessage()." at ".$e->getFile().":".$e->getLine()."<br>";
    $errors[] = "Session/i18n: ".$e->getMessage();
}

// Step 6: Simulate logged-in user and test header
echo "<h2>Step 6: Test Functions (logged-in simulation)</h2>";
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'test';
$_SESSION['full_name'] = 'Test User';
$_SESSION['role'] = 'admin';
$_SESSION['tenant_id'] = 1;
$_SESSION['branch_id'] = 1;
$_SESSION['permissions'] = ['all'];

try {
    echo "- getAllPermissions(): ";
    $perms = getAllPermissions();
    echo "✅ (".count($perms)." sections)<br>";
    
    echo "- getCompanySettings(): ";
    $company = getCompanySettings($pdo);
    echo "✅<br>";

    echo "- getLowStockCount(): ";
    $lsc = getLowStockCount($pdo);
    echo "✅ ($lsc)<br>";

    echo "- getExpiringCount(): ";
    $exc = getExpiringCount($pdo);
    echo "✅ ($exc)<br>";

} catch (Throwable $e) {
    echo "❌ ".$e->getMessage()." at ".$e->getFile().":".$e->getLine()."<br>";
    $errors[] = "Functions: ".$e->getMessage();
}

// Step 7: Actually render index.php
echo "<h2>Step 7: Render index.php</h2>";
ob_start();
try {
    include __DIR__.'/index.php';
    $html = ob_get_clean();
    echo "✅ Rendered! (".strlen($html)." bytes)<br>";
    echo "<details><summary>Preview first 500 chars</summary><pre>".htmlspecialchars(substr($html,0,500))."</pre></details>";
} catch (Throwable $e) {
    ob_end_clean();
    echo "❌ <strong style='color:red;font-size:16px'>CRASH: ".htmlspecialchars($e->getMessage())."</strong><br>";
    echo "📍 File: ".$e->getFile()."<br>";
    echo "📍 Line: ".$e->getLine()."<br>";
    echo "<pre>".htmlspecialchars($e->getTraceAsString())."</pre>";
    $errors[] = "index.php: ".$e->getMessage()." at ".$e->getFile().":".$e->getLine();
}

// Step 8: Error log
echo "<h2>Step 8: Error Log (last entries)</h2>";
$log = __DIR__.'/.error.log';
if (file_exists($log) && filesize($log) > 0) {
    $fp = fopen($log,'r');
    fseek($fp, max(0, filesize($log)-3000));
    echo "<pre style='background:#fee;padding:10px;max-height:300px;overflow:auto;'>".htmlspecialchars(fread($fp,3000))."</pre>";
    fclose($fp);
} else {
    echo "No error log<br>";
}

// Summary
echo "<h2>📋 Summary</h2>";
if (empty($errors)) {
    echo "<p style='color:green;font-size:20px;font-weight:bold'>✅ ALL TESTS PASSED!</p>";
} else {
    echo "<p style='color:red;font-size:20px;font-weight:bold'>❌ ".count($errors)." ERROR(S):</p><ol style='color:red'>";
    foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>";
    echo "</ol>";
}
echo "</body></html>";
