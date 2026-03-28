<?php
// Suppress ALL output
@ob_end_clean();
@ob_end_clean();
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

// MUST set same session name used by SecurityService
session_name('URS_SESSION');
if (session_status() === PHP_SESSION_NONE) @session_start();

// Log activity (silent)
try {
    require_once __DIR__ . '/includes/helpers/env.php';
    require_once __DIR__ . '/includes/services/Database.php';
    $pdo = Database::getInstance()->getPdo();
    if (!empty($_SESSION['user_id'])) {
        $pdo->prepare("INSERT INTO activity_log (tenant_id,user_id,username,action,module,ip_address) VALUES(?,?,?,'nav.logout','auth',?)")
            ->execute([$_SESSION['tenant_id']??1, $_SESSION['user_id'], $_SESSION['username']??'', $_SERVER['REMOTE_ADDR']??'']);
    }
} catch(Exception $e) {}

// Destroy session completely
$_SESSION = [];

// Delete the URS_SESSION cookie
$p = session_get_cookie_params();
setcookie('URS_SESSION', '', time()-42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
// Also delete PHPSESSID just in case
setcookie('PHPSESSID', '', time()-42000, '/');

@session_destroy();

// Clean ALL output buffers
while (ob_get_level()) ob_end_clean();

// Redirect
if (!headers_sent()) {
    header('Location: login.php');
    exit;
}
?><!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=login.php"></head><body><script>window.location.replace("login.php");</script></body></html><?php exit;
