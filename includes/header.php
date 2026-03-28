<?php
require_once __DIR__ . '/config.php';
requireLogin();

// تحميل الصلاحيات لو مش محملة
if (!isset($_SESSION['permissions'])) {
    loadUserPermissions($pdo, $_SESSION['user_id']);
}

$company = getCompanySettings($pdo);
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// ========== الصلاحيات المطلوبة لكل صفحة ==========
$pagePermissions = [
    'index' => 'dashboard',
    'sales' => 'sales_view',
    'sales_new' => 'sales_add',
    'sales_return' => 'sales_view',
    'sales_print' => 'sales_print',
    'purchases' => 'purchases_view',
    'purchases_new' => 'purchases_add',
    'purchase_orders' => 'purchases_view',
    'purchase_return' => 'purchases_view',
    'purchase_view' => 'purchases_view',
    'receipts' => 'receipts_view',
    'payments' => 'payments_view',
    'manufacturing' => 'manufacturing_view',
    'manufacturing_new' => 'manufacturing_add',
    'manufacturing_view' => 'manufacturing_view',
    'manufacturing_print' => 'manufacturing_print',
    'employees' => 'employees_view',
    'payroll' => 'payroll_view',
    'leaves' => 'leaves_view',
    'customers' => 'customers_view',
    'suppliers' => 'suppliers_view',
    'products' => 'products_view',
    'inventory' => 'inventory_view',
    'pos' => 'pos_access',
    'expenses' => 'expenses_view',
    'reports' => 'reports_view',
    'settings' => 'settings_view',
    'users' => 'users_manage',
    'activity_log' => 'activity_log',
];

// بناء القائمة حسب الصلاحيات
$navSections = [];

// الرئيسية
if (hasPermission('dashboard')) {
    $navSections[t('nav.home')] = ['index' => [t('nav.dashboard'), 'fas fa-tachometer-alt']];
}

// نقاط البيع
if (hasPermission('pos_access')) {
    $navSections[t('nav.pos')] = ['pos' => [t('nav.pos_screen'), 'fas fa-cash-register']];
}

// الأدوية والمخزون
$invItems = [];
if (hasPermission('products_view')) $invItems['products'] = [t('nav.medicines_items'), 'fas fa-pills'];
if (hasPermission('inventory_view')) $invItems['inventory'] = [t('nav.inventory_batches'), 'fas fa-boxes'];
if (hasPermission('inventory_count')) $invItems['stock_count'] = [t('nav.count_adjustments'), 'fas fa-clipboard-check'];
if (hasPermission('inventory_transfer')) $invItems['stock_transfer'] = [t('nav.branch_transfer'), 'fas fa-exchange-alt'];
$invItems['manufacturing'] = [t('nav.manufacturing'), 'fas fa-flask'];
if (!empty($invItems)) $navSections[t('nav.medicines_inventory')] = $invItems;

// المبيعات والمشتريات
$tradeItems = [];
if (hasPermission('sales_view')) $tradeItems['sales'] = [t('nav.sales_invoices'), 'fas fa-file-invoice-dollar'];
if (hasPermission('purchases_view')) $tradeItems['purchases'] = [t('nav.purchase_invoices'), 'fas fa-shopping-cart'];
if (!empty($tradeItems)) $navSections[t('nav.sales_purchases')] = $tradeItems;

// المالية
$financeItems = [];
if (hasPermission('receipts_view')) $financeItems['receipts'] = [t('nav.receipt_vouchers'), 'fas fa-hand-holding-usd'];
if (hasPermission('payments_view')) $financeItems['payments'] = [t('nav.payment_vouchers'), 'fas fa-money-check-alt'];
if (hasPermission('expenses_view')) $financeItems['expenses'] = [t('nav.expenses'), 'fas fa-receipt'];
if (!empty($financeItems)) $navSections[t('nav.finance')] = $financeItems;

// المحاسبة
$accItems = [];
if (hasAnyPermission(['accounting_view','reports_view'])) {
    $accItems['accounts'] = [t('nav.chart_of_accounts'), 'fas fa-sitemap'];
    $accItems['journal'] = [t('nav.journal_entries'), 'fas fa-book'];
    $accItems['ledger'] = [t('nav.general_ledger'), 'fas fa-book-open'];
    $accItems['trial_balance'] = [t('nav.trial_balance'), 'fas fa-balance-scale'];
    $accItems['profit_loss'] = [t('nav.profit_loss'), 'fas fa-chart-line'];
    $accItems['balance_sheet'] = [t('nav.balance_sheet'), 'fas fa-file-invoice'];
    $accItems['cash_flow'] = [t('nav.cash_flow'), 'fas fa-money-bill-wave'];
    $accItems['aging_customers'] = [t('nav.aging_customers'), 'fas fa-user-clock'];
    $accItems['aging_suppliers'] = [t('nav.aging_suppliers'), 'fas fa-truck-loading'];
    $accItems['financial_close'] = [t('nav.financial_close'), 'fas fa-lock'];
}
if (!empty($accItems)) $navSections[t('nav.accounting')] = $accItems;

// العملاء والموردين
$contactItems = [];
if (hasPermission('customers_view')) $contactItems['customers'] = [t('nav.customers'), 'fas fa-user-tie'];
if (hasPermission('suppliers_view')) $contactItems['suppliers'] = [t('nav.suppliers'), 'fas fa-truck'];
if (!empty($contactItems)) $navSections[t('nav.contacts')] = $contactItems;

// الوصفات الطبية
if (hasAnyPermission(['prescriptions_view','prescriptions_add'])) {
    $navSections[t('nav.prescriptions')] = ['prescriptions' => [t('nav.prescriptions'), 'fas fa-file-prescription']];
}

// النظام
$systemItems = [];
if (hasPermission('reports_view')) $systemItems['reports'] = [t('nav.reports'), 'fas fa-chart-bar'];
// مقارنة الفروع للفرع الرئيسي فقط
$_isMainB = false; try { $_mb = $pdo->prepare("SELECT is_main FROM branches WHERE id=? AND tenant_id=?"); $_mb->execute([getCurrentBranch(), getTenantId()]); $_isMainB = (bool)$_mb->fetchColumn(); } catch(Exception $e) {}
if (hasPermission('reports_view') && $_isMainB) $systemItems['branch_comparison'] = [t('nav.branch_comparison'), 'fas fa-code-branch'];
if (hasPermission('inventory_view')) $systemItems['inventory_aging'] = [t('nav.stale_inventory'), 'fas fa-box-archive'];
if (hasPermission('branches_manage')) $systemItems['branches'] = [t('nav.branch_management'), 'fas fa-store'];
if (hasPermission('settings_view')) $systemItems['settings'] = [t('nav.settings'), 'fas fa-cog'];
if (hasPermission('users_manage')) $systemItems['users'] = [t('nav.users_permissions'), 'fas fa-user-shield'];
$systemItems['activity_log'] = [t('nav.activity_log'), 'fas fa-history'];
$systemItems['support_tickets'] = [t('nav.support'), 'fas fa-headset'];
$systemItems['change_password'] = [t('nav.change_password'), 'fas fa-key'];
if (!empty($systemItems)) $navSections[t('nav.system')] = $systemItems;

$activeNav = $currentPage;
if (in_array($currentPage, ['sales_new', 'sales_print', 'sales_return'])) $activeNav = 'sales';
if (in_array($currentPage, ['purchases_new', 'purchase_receive', 'purchase_orders', 'purchase_return', 'purchase_view'])) $activeNav = 'purchases';
if (in_array($currentPage, ['product_edit'])) $activeNav = 'products';
if (in_array($currentPage, ['accounts','journal','ledger','trial_balance','profit_loss'])) $activeNav = $currentPage;
if (in_array($currentPage, ['owner_work_view'])) $activeNav = 'owner_works';
?>
<!DOCTYPE html>
<html lang="<?= langCode() ?>" dir="<?= langDir() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title><?= ($pageTitle ?? 'URS Pharmacy') . ' | URS' ?></title>
    <link rel="icon" type="image/png" href="assets/logo-small.png">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="assets/logo-small.png" alt="Logo" class="sidebar-logo">
        <div class="sidebar-brand-text">
            <h1>URS System</h1>
            <p dir="auto"><?= htmlspecialchars($company['company_name'] ?? $company['company_name_en'] ?? t('pharmacy_system')) ?></p>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($navSections as $sectionTitle => $items): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-title"><?= $sectionTitle ?></div>
            <?php foreach ($items as $page => $info): ?>
            <a href="<?= $page ?>" class="sidebar-link <?= $activeNav == $page ? 'active' : '' ?>">
                <i class="<?= $info[1] ?>"></i>
                <span><?= $info[0] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="sidebar-link logout-link">
            <i class="fas fa-sign-out-alt"></i>
            <span><?= t('nav.logout') ?></span>
        </a>
        <div class="sidebar-user">
            <img src="assets/logo-small.png" alt="Logo" class="sidebar-logo">
            <div class="sidebar-user-info">
                <p dir="auto"><?= $_SESSION['full_name'] ?? t('users.user') ?></p>
                <small><?= ($_SESSION['role'] ?? '') == 'admin' ? t('users.admin') : t('users.user') ?></small>
            </div>
        </div>
    </div>
</aside>

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Mobile menu toggle (floating) -->
<button class="mob-menu-toggle" id="mobMenuToggle" type="button" aria-label="<?= t('nav.home') ?>">
    <i class="fas fa-bars"></i>
</button>

<!-- Main Wrapper -->
<div class="main-wrapper">
    <!-- Top Bar -->
    <header class="top-bar">
        <div class="top-bar-right">
            <button class="sidebar-toggle" type="button" id="topBarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h2 class="page-title"><?= $pageTitle ?? '' ?></h2>
        </div>
        <div class="top-bar-left">
            <?php
            // عدد الإشعارات غير المقروءة
            $notifCount = 0;
            try {
                $_nc = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE tenant_id = ? AND (user_id = ? OR user_id IS NULL) AND is_read = 0");
                $_nc->execute([getTenantId(), $_SESSION['user_id'] ?? 0]);
                $notifCount = $_nc->fetchColumn();
            } catch (Exception $e) {}
            
            // عداد التنبيهات (مخزون + انتهاء)
            $alertCount = getLowStockCount($pdo) + getExpiringCount($pdo);
            ?>
            
            <!-- Notification Bell -->
            <div style="position:relative;margin-left:12px;">
                <a href="javascript:void(0)" onclick="toggleNotifPanel()" style="color:var(--foreground);font-size:18px;position:relative;text-decoration:none;" title="<?= t('notifications.title') ?>">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount + $alertCount > 0): ?>
                    <span style="position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;"><?= min($notifCount + $alertCount, 99) ?></span>
                    <?php endif; ?>
                </a>
                <div id="notifPanel" style="display:none;position:absolute;top:36px;left:0;background:var(--card);border:1px solid var(--border);border-radius:10px;width:320px;max-height:400px;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,0.2);z-index:1000;">
                    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-weight:700;font-size:14px;display:flex;justify-content:space-between;">
                        <span><i class="fas fa-bell"></i> <?= t('notifications.title') ?></span>
                        <span style="background:#ef444422;color:#ef4444;padding:1px 8px;border-radius:10px;font-size:11px;"><?= $notifCount + $alertCount ?></span>
                    </div>
                    <?php if ($alertCount > 0): ?>
                    <a href="inventory?tab=low" style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--foreground);font-size:13px;">
                        <i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>
                        <div><div style="font-weight:600;"><?= t('notifications.stock_alerts') ?></div><div style="font-size:11px;color:#64748b;"><?= getLowStockCount($pdo) ?> <?= t('notifications.shortage') ?> — <?= getExpiringCount($pdo) ?> <?= t('notifications.near_expiry') ?></div></div>
                    </a>
                    <?php endif; ?>
                    <div style="padding:16px;text-align:center;color:#64748b;font-size:12px;">
                        <a href="support_tickets" style="color:var(--primary);text-decoration:none;"><?= t('nav.support') ?></a>
                    </div>
                </div>
            </div>
            <script>function toggleNotifPanel(){var p=document.getElementById('notifPanel');p.style.display=p.style.display==='none'?'block':'none';} document.addEventListener('click',function(e){if(!e.target.closest('#notifPanel')&&!e.target.closest('.fa-bell')){document.getElementById('notifPanel').style.display='none';}});</script>
            
            <?php
            $_bc = $pdo->prepare("SELECT * FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY is_main DESC, name");
            $_bc->execute([getTenantId()]);
            $allBranches = $_bc->fetchAll();
            $branchCount = count($allBranches);
            $currentBranchName = t('branches.main_branch');
            foreach ($allBranches as $_b) { if ($_b['id'] == getCurrentBranch()) $currentBranchName = ($_b['is_main'] ?? false) ? t('branches.main_branch') : $_b['name']; }
            ?>
            
            <!-- Language Switcher -->
            <div style="position:relative;margin-left:12px;">
                <a href="<?= langSwitchUrl() ?>" style="display:flex;align-items:center;gap:6px;background:#e8eef6;color:#3d5a80;padding:7px 16px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none;cursor:pointer;border:1px solid #d0daea;transition:all 0.2s;" onmouseover="this.style.background='#dce4f0'" onmouseout="this.style.background='#e8eef6'" title="<?= t('header.switch_lang') ?>">
                    <i class="fas fa-globe"></i> <?= t('header.switch_lang') ?>
                </a>
            </div>
            
            <!-- Branch Display -->
            <div style="position:relative;margin-left:12px;">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="javascript:void(0)" onclick="toggleBranchPanel()" style="display:flex;align-items:center;gap:6px;background:#e8eef6;color:#3d5a80;padding:7px 16px;border-radius:10px;font-size:12px;font-weight:600;text-decoration:none;cursor:pointer;border:1px solid #d0daea;transition:all 0.2s;" onmouseover="this.style.background='#dce4f0'" onmouseout="this.style.background='#e8eef6'" title="<?= t('branches.switch_branch') ?>">
                    <i class="fas fa-store"></i> <?= htmlspecialchars($currentBranchName) ?> <i class="fas fa-chevron-down" style="font-size:9px;opacity:0.6;"></i>
                </a>
                <div id="branchPanel" style="display:none;position:absolute;top:44px;<?= isRTL() ? 'right' : 'left' ?>:0;background:#fff;border:none;border-radius:14px;width:250px;box-shadow:0 12px 48px rgba(15,52,96,0.15),0 2px 8px rgba(0,0,0,0.06);z-index:1000;overflow:hidden;animation:branchSlide 0.15s ease-out;">
                    <div style="padding:14px 18px;background:linear-gradient(135deg,#0f3460,#1a2744);color:#fff;">
                        <div style="font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;"><i class="fas fa-store" style="opacity:0.8;"></i> <?= t('branches.switch_branch') ?></div>
                        <div style="font-size:10px;opacity:0.6;margin-top:3px;"><?= count($allBranches) ?> <?= t('g.branch') ?></div>
                    </div>
                    <div style="padding:6px;">
                    <?php foreach ($allBranches as $_b): $isActive = $_b['id'] == getCurrentBranch(); ?>
                    <a href="branches?switch=<?= $_b['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:10px 14px;text-decoration:none;color:<?= $isActive ? '#0f3460' : '#4b5563' ?>;font-size:13px;border-radius:10px;margin:2px 0;transition:all 0.15s;<?= $isActive ? 'background:#e8eef6;font-weight:700;' : '' ?>" onmouseover="<?= $isActive ? '' : "this.style.background='#f5f7fa'" ?>" onmouseout="<?= $isActive ? '' : "this.style.background=''" ?>">
                        <div style="width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;<?= $isActive ? 'background:#0f3460;color:#fff;' : 'background:#f1f5f9;color:#94a3b8;' ?>">
                            <i class="fas fa-<?= $isActive ? 'check' : 'store' ?>"></i>
                        </div>
                        <div style="flex:1;">
                            <div><?= htmlspecialchars(($_b['is_main'] ?? false) ? t('branches.main_branch') : $_b['name']) ?></div>
                            <?php if ($_b['is_main']): ?><div style="font-size:10px;color:#94a3b8;font-weight:400;"><?= t('main') ?></div><?php endif; ?>
                        </div>
                        <?php if ($isActive): ?><span style="width:6px;height:6px;border-radius:50%;background:#16a34a;"></span><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                    </div>
                </div>
                <style>@keyframes branchSlide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}</style>
                <script>function toggleBranchPanel(){var p=document.getElementById('branchPanel');p.style.display=p.style.display==='none'?'block':'none';}document.addEventListener('click',function(e){if(!e.target.closest('#branchPanel')&&!e.target.closest('[onclick*=toggleBranchPanel]')){document.getElementById('branchPanel').style.display='none';}});</script>
                <?php else: ?>
                <span style="display:flex;align-items:center;gap:6px;background:#e8eef6;color:#3d5a80;padding:7px 16px;border-radius:10px;font-size:12px;font-weight:600;border:1px solid #d0daea;">
                    <i class="fas fa-store"></i> <?= htmlspecialchars($currentBranchName) ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="<?= t('search_placeholder') ?>">
            </div>
        </div>
    </header>
    <main class="content">

<!-- Print-Only Page Header — shows on every printed page -->
<div class="print-page-header" style="display:none;">
    <div class="pph-right">
        <h2><?= htmlspecialchars($company['company_name'] ?? t('app_name')) ?></h2>
        <p><?= htmlspecialchars($currentBranchName ?? '') ?></p>
        <p><?= t('prt.cr') ?> <?= $company['cr_number'] ?? '' ?> | <?= t('settings.tax_number') ?>: <?= $company['tax_number'] ?? '' ?></p>
    </div>
    <div class="pph-center"><?= htmlspecialchars($pageTitle ?? '') ?></div>
    <div class="pph-left">
        <h2><?= htmlspecialchars($company['company_name_en'] ?? 'URS Pharmacy') ?></h2>
        <p><?= date('Y-m-d H:i') ?></p>
    </div>
</div>