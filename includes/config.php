<?php
/**
 * =============================================
 * URS Pharmacy ERP SaaS - Main Configuration
 * =============================================
 */

// Output buffering — يسمح بالـ redirect حتى بعد طباعة HTML
ob_start();

// عرض الأخطاء
// Production: errors logged, not displayed
ini_set("display_errors", 0);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/../.error.log");
error_reporting(E_ALL);

// Global exception handler - يمسك أي خطأ ويوريك الرسالة بدل صفحة بيضا
set_exception_handler(function($e) {
    echo '<div style="background:#fef2f2;color:#dc2626;padding:16px 24px;border-radius:8px;margin:20px;font-family:Tajawal,sans-serif;direction:rtl;border:1px solid #fecaca;">';
    echo '<strong>خطأ:</strong> ' . htmlspecialchars($e->getMessage());
    // Don't expose file paths in production
    echo '</div>';
});

// ========== تحميل البيئة والخدمات ==========
require_once __DIR__ . '/helpers/env.php';
require_once __DIR__ . '/helpers/functions.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/services/Database.php';
require_once __DIR__ . '/services/SecurityService.php';
require_once __DIR__ . '/services/TenantService.php';
require_once __DIR__ . '/services/AccountingService.php';
require_once __DIR__ . '/services/StockService.php';
require_once __DIR__ . '/services/ZATCAService.php';
require_once __DIR__ . '/middleware.php';

// ========== الاتصال بقاعدة البيانات ==========
$pdo = Database::getInstance()->getPdo();

// ========== الجلسة الآمنة ==========
SecurityService::initSecureSession();

// ========== i18n — AFTER session start ==========
initLanguage();
loadTranslations();

// ========== CSRF (Backward compatible) ==========
function generateCsrfToken() { return SecurityService::generateCsrfToken(); }
function csrfField() { return SecurityService::csrfField(); }
function verifyCsrfToken() { SecurityService::verifyCsrfToken(); }
function verifyCsrfAjax() { SecurityService::verifyCsrfAjax(); }

// ========== Tenant Context ==========
function getTenantId() { if(!isset($_SESSION['tenant_id'])){header('Location: login.php');exit;} return intval($_SESSION['tenant_id']); }
function getBranchId() { if(!isset($_SESSION['branch_id'])){header('Location: login.php');exit;} return intval($_SESSION['branch_id']); }
function getCurrentBranch() { return getBranchId(); }
function tenantWhere($alias = '') { return TenantService::tenantCondition($alias, getTenantId()); }

// ========== الصلاحيات ==========
function getAllPermissions() {
    return [
        t('perms.dashboard') => ['dashboard'=>t('perms.view_dashboard'),'dashboard_stats_sales'=>t('perms.sales_stats'),'dashboard_stats_profit'=>t('perms.profit_stats'),'dashboard_stats_expenses'=>t('perms.expense_stats'),'dashboard_stats_cashflow'=>t('perms.cashflow_stats'),'dashboard_stats_weekly'=>t('perms.weekly_sales'),'dashboard_stats_monthly'=>t('perms.monthly_sales'),'dashboard_stats_inventory'=>t('perms.inventory_stats'),'dashboard_charts'=>t('perms.charts'),'dashboard_recent_sales'=>t('perms.recent_sales'),'dashboard_alerts'=>t('perms.alerts')],
        t('perms.pos') => ['pos_access'=>t('perms.pos_access'),'pos_discount'=>t('perms.pos_discount'),'pos_return'=>t('perms.pos_return'),'pos_hold'=>t('perms.pos_hold'),'pos_reprint'=>t('perms.pos_reprint')],
        t('perms.sales') => ['sales_view'=>t('perms.view_sales'),'sales_add'=>t('perms.add_sale'),'sales_print'=>t('perms.print_sale'),'sales_delete'=>t('perms.delete_sale'),'sales_return'=>t('perms.sale_return')],
        t('perms.medicines_inventory') => ['products_view'=>t('perms.view_products'),'products_add'=>t('perms.add_product'),'products_edit'=>t('perms.edit_product'),'products_delete'=>t('perms.delete_product'),'inventory_view'=>t('perms.view_inventory'),'inventory_adjust'=>t('perms.adjust_inventory'),'inventory_count'=>t('perms.count_inventory'),'inventory_transfer'=>t('perms.transfer_inventory'),'inventory_transfer_approve'=>t('perms.approve_transfer'),'inventory_count_approve'=>t('perms.approve_count'),'price_edit'=>t('perms.edit_price'),'cost_edit'=>t('perms.edit_cost'),'purchases_return'=>t('perms.purchase_return'),'batches_view'=>t('perms.view_batches')],
        t('perms.purchases') => ['purchases_view'=>t('perms.view_purchases'),'purchases_add'=>t('perms.add_purchase'),'purchases_delete'=>t('perms.delete_purchase'),'purchase_orders_view'=>t('perms.view_purchase_orders'),'purchase_orders_add'=>t('perms.add_purchase_order')],
        t('perms.receipt_vouchers') => ['receipts_view'=>t('perms.view_receipts'),'receipts_add'=>t('perms.add_receipt'),'receipts_print'=>t('perms.print_receipt'),'receipts_delete'=>t('perms.delete_receipt')],
        t('perms.payment_vouchers') => ['payments_view'=>t('perms.view_payments'),'payments_add'=>t('perms.add_payment'),'payments_print'=>t('perms.print_payment'),'payments_delete'=>t('perms.delete_payment')],
        t('perms.expenses') => ['expenses_view'=>t('perms.view_expenses'),'expenses_add'=>t('perms.add_expense'),'expenses_delete'=>t('perms.delete_expense')],
        t('perms.customers') => ['customers_view'=>t('perms.view_customers'),'customers_add'=>t('perms.add_customer'),'customers_edit'=>t('perms.edit_customer'),'customers_delete'=>t('perms.delete_customer')],
        t('perms.suppliers') => ['suppliers_view'=>t('perms.view_suppliers'),'suppliers_add'=>t('perms.add_supplier'),'suppliers_edit'=>t('perms.edit_supplier'),'suppliers_delete'=>t('perms.delete_supplier')],
        t('perms.prescriptions') => ['prescriptions_view'=>t('perms.view_prescriptions'),'prescriptions_add'=>t('perms.add_prescription'),'prescriptions_edit'=>t('perms.edit_prescription'),'prescriptions_approve'=>t('perms.approve_prescription')],
        t('perms.system') => ['reports_view'=>t('perms.view_reports'),'reports_financial'=>t('perms.financial_reports'),'reports_export'=>t('perms.export_reports'),'accounting_view'=>t('perms.view_accounting'),'accounting_edit'=>t('perms.edit_journal'),'settings_view'=>t('perms.view_settings'),'settings_edit'=>t('perms.edit_settings'),'users_manage'=>t('perms.manage_users'),'branches_manage'=>t('perms.manage_branches'),'activity_log'=>t('perms.activity_log'),'support_tickets'=>t('perms.support_tickets'),'notifications_manage'=>t('perms.manage_notifications')],
    ];
}
function hasPermission($p) { if (!isset($_SESSION['user_id'])) return false; if (($_SESSION['role']??'')==='admin') return true; return in_array($p,$_SESSION['permissions']??[]); }
function hasAnyPermission($ps) { foreach($ps as $p) if(hasPermission($p)) return true; return false; }
function requirePermission($p) { if(!hasPermission($p)){echo '<div style="text-align:center;padding:80px 20px;font-family:Tajawal;"><i class="fas fa-lock" style="font-size:60px;color:#dc2626;margin-bottom:20px;display:block;"></i><h2 style="color:#dc2626;">'.t('unauthorized').'</h2><p style="color:#666;">'.t('no_permission').'</p><a href="index.php" style="display:inline-block;margin-top:20px;padding:10px 24px;background:#0f3460;color:#fff;border-radius:8px;text-decoration:none;">'.t('back_home').'</a></div>';require_once __DIR__.'/footer.php';exit;}}
function loadUserPermissions($pdo,$uid){$s=$pdo->prepare("SELECT permissions,role,tenant_id,branch_id FROM users WHERE id=?");$s->execute([$uid]);$u=$s->fetch();if($u){$_SESSION['role']=$u['role'];$_SESSION['tenant_id']=intval($u['tenant_id']);$_SESSION['branch_id']=intval($u['branch_id']);$_SESSION['permissions']=$u['role']==='admin'?['all']:(json_decode($u['permissions']??'[]',true)?:[]);}}
function isLoggedIn(){return isset($_SESSION['user_id']);}
function requireLogin(){global $pdo; Middleware::requireAuth($pdo);}

// فحص حالة الاشتراك — يمنع الدخول لو الاشتراك منتهي
function checkSubscriptionStatus() {
    global $pdo;
    if (!isset($_SESSION['tenant_id']) || ($_SESSION['role'] ?? '') === 'super_admin') return;
    if (isset($_SESSION['sub_checked']) && $_SESSION['sub_checked'] > time() - 300) return;
    try {
        $s = $pdo->prepare("SELECT status, subscription_end, grace_period_days FROM tenants WHERE id = ?");
        $s->execute([$_SESSION['tenant_id']]);
        $t = $s->fetch();
        if ($t) {
            $_SESSION['sub_checked'] = time();
            if ($t['status'] === 'suspended') {
                session_destroy();
                header('Location: login.php?error=' . urlencode('تم تعليق حساب الصيدلية. تواصل مع إدارة النظام.'));
                exit;
            }
            if ($t['subscription_end']) {
                $grace = intval($t['grace_period_days'] ?? 0);
                $deadline = strtotime($t['subscription_end'] . " +$grace days");
                if (time() > $deadline) {
                    $pdo->prepare("UPDATE tenants SET status='suspended' WHERE id=?")->execute([$_SESSION['tenant_id']]);
                    session_destroy();
                    header('Location: login.php?error=' . urlencode('انتهى اشتراكك. تواصل مع إدارة النظام لتجديد الاشتراك.'));
                    exit;
                }
            }
        }
    } catch(Exception $e) {}
}
