<?php
require_once 'includes/config.php';
$pageTitle = t('activity.title');
require_once 'includes/config.php';
requireLogin();
requirePermission('activity_log');
$tid = getTenantId();
$bid = getBranchId();

// هذه الصفحة خاصة بالمدير فقط
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}

// تحقق: هل الفرع الحالي هو الفرع الرئيسي؟
$_isMainBranch = false;
try { $_bChk = $pdo->prepare("SELECT is_main FROM branches WHERE id=? AND tenant_id=?"); $_bChk->execute([$bid,$tid]); $_isMainBranch = (bool)$_bChk->fetchColumn(); } catch(Exception $e) {}

// حذف السجل — للفرع الرئيسي فقط
if (isset($_GET['clear_all']) && ($_GET['confirm'] ?? '') === 'yes' && $_isMainBranch) {
    $pdo->prepare("DELETE FROM activity_log WHERE tenant_id = ?")->execute([$tid]);
    logActivity($pdo, 'activity.clear_log', '', 'system');
    header("Location: activity_log?msg=cleared");
    exit;
}

// فلتر الفرع — الفرعي يشوف مستخدمين فرعه بس
$branchUserFilter = "";
if (!$_isMainBranch) {
    $branchUsers = $pdo->prepare("SELECT id FROM users WHERE tenant_id = ? AND branch_id = ?");
    $branchUsers->execute([$tid, $bid]);
    $branchUserIds = $branchUsers->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($branchUserIds)) {
        $branchUserFilter = " AND user_id IN (" . implode(',', array_map('intval', $branchUserIds)) . ")";
    } else {
        $branchUserFilter = " AND user_id = 0"; // مفيش مستخدمين — مش هيرجع حاجة
    }
}

require_once 'includes/header.php';

// إنشاء الجدول لو مش موجود
$pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(100),
    action VARCHAR(100) NOT NULL,
    details TEXT,
    module VARCHAR(50),
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_module (module),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (isset($_GET['msg']) && $_GET['msg'] === 'cleared') {
    echo '<div class="alert alert-success"><i class="fas fa-check"></i> ' . t('saved_success') . '</div>';
}

// فلاتر
$filterUser = $_GET['user'] ?? '';
$filterModule = $_GET['module'] ?? '';
$filterFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$filterTo = $_GET['to'] ?? date('Y-m-d');
$filterAction = $_GET['action'] ?? '';

$where = "WHERE tenant_id = $tid $branchUserFilter AND DATE(created_at) BETWEEN ? AND ?";
$params = [$filterFrom, $filterTo];

if ($filterUser) {
    $where .= " AND username = ?";
    $params[] = $filterUser;
}
if ($filterModule) {
    $where .= " AND module = ?";
    $params[] = $filterModule;
}
if ($filterAction) {
    $where .= " AND action LIKE ?";
    $params[] = "%$filterAction%";
}

// إحصائيات
$statsQ = $pdo->prepare("SELECT COUNT(*) as total, COUNT(DISTINCT username) as users, COUNT(DISTINCT module) as modules FROM activity_log $where");
$statsQ->execute($params);
$stats = $statsQ->fetch();

// جلب السجلات
$page_num = max(1, intval($_GET['p'] ?? 1));
$per_page = 50;
$offset = ($page_num - 1) * $per_page;

$countQ = $pdo->prepare("SELECT COUNT(*) FROM activity_log $where");
$countQ->execute($params);
$totalRecords = $countQ->fetchColumn();
$totalPages = ceil($totalRecords / $per_page);

$logsQ = $pdo->prepare("SELECT * FROM activity_log $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$logsQ->execute($params);
$logs = $logsQ->fetchAll();

// جلب قائمة المستخدمين والأقسام للفلاتر
$usersList = $pdo->query("SELECT DISTINCT username FROM activity_log WHERE tenant_id = $tid $branchUserFilter ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
$modulesList = $pdo->query("SELECT DISTINCT module FROM activity_log WHERE tenant_id = $tid $branchUserFilter AND module != '' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

$moduleNames = [
    'auth' => t('activity.login'),
    'sales' => t('perms.sales'),
    'purchases' => t('perms.purchases'),
    'receipts' => t('perms.receipt_vouchers'),
    'payments' => t('perms.payment_vouchers'),
    'payroll' => t('perm_labels.payroll'),
    'manufacturing' => t('nav.manufacturing'),
    'manufacturing_view' => t('manufacturing.title'),
    'employees' => t('g.employees'),
    'customers' => t('perms.customers'),
    'suppliers' => t('perms.suppliers'),
    'owner_transfers' => t('perm_labels.owner_transfers'),
    'owner_works' => t('perm_labels.owner_works'),
    'leaves' => t('perm_labels.leaves'),
    'settings' => t('nav.settings'),
    'users' => t('nav.users_permissions'),
    'system' => t('nav.system'),
];

$_iconKeywords = ['login'=>'sign-in-alt','logout'=>'sign-out-alt','delete'=>'trash','add'=>'plus','edit'=>'edit','save'=>'save','approve'=>'check-circle','execute'=>'cogs','import'=>'file-import','expense'=>'receipt','receipt'=>'hand-holding-usd','payment'=>'money-check-alt','voucher'=>'money-check-alt','transfer'=>'exchange-alt','count'=>'clipboard-check','manufacturing'=>'industry','prescription'=>'file-medical','close'=>'lock','change'=>'key','adjust'=>'sliders-h','journal'=>'book','clear'=>'eraser'];

// Reverse map: old Arabic action text → translation key (for backward compatibility)
$_arActionMap = [
    'تسجيل دخول'=>'activity.login_action','تسجيل خروج'=>'nav.logout',
    'حذف فاتورة مبيعات'=>'activity.delete_sale','حذف دواء'=>'activity.delete_product',
    'حذف مصروف'=>'activity.delete_expense','حذف مستخدم'=>'activity.delete_user',
    'حذف تحويل مخزني'=>'activity.delete_transfer','حذف فرع'=>'activity.delete_branch',
    'إضافة عميل'=>'activity.add_customer','تعديل عميل'=>'activity.edit_customer',
    'إضافة مورد'=>'activity.add_supplier','تعديل مورد'=>'activity.edit_supplier',
    'إضافة دواء'=>'activity.add_product','تعديل دواء'=>'activity.edit_product',
    'إضافة مستخدم'=>'activity.add_user','تعديل مستخدم'=>'activity.edit_user',
    'فاتورة مبيعات'=>'activity.add_sale','فاتورة مشتريات'=>'activity.add_purchase',
    'إضافة فرع'=>'activity.add_branch','تعديل فرع'=>'activity.edit_branch',
    'سند قبض'=>'receipts.receipt_voucher','سند صرف'=>'payments.payment_voucher',
    'مصروف'=>'expenses.expense','إضافة مصروف'=>'activity.add_expense',
    'إضافة وصفة'=>'activity.add_prescription','تحديث حالة وصفة'=>'activity.update_prescription',
    'بيع POS'=>'pos.sale_pos','مرتجع POS'=>'pos.return_pos',
    'مرتجع مشتريات'=>'activity.purchase_return','تغيير كلمة المرور'=>'activity.change_password',
    'حفظ الإعدادات'=>'activity.save_settings','حفظ تحويل مخزني'=>'activity.save_transfer',
    'اعتماد تحويل مخزني'=>'activity.approve_transfer','اعتماد جرد'=>'activity.approve_count',
    'إنشاء جرد'=>'activity.save_count','قيد يومية يدوي'=>'accounting.manual_journal',
    'أمر تصنيع'=>'activity.manufacturing_order','تنفيذ أمر تصنيع'=>'activity.execute_manufacturing',
    'إقفال فترة مالية'=>'activity.close_period','إضافة حساب'=>'activity.add_account',
    'استيراد أصناف'=>'activity.excel_import','مسح سجل العمليات'=>'activity.clear_log',
    'إنشاء تحويل مخزني'=>'activity.save_transfer','أمر شراء'=>'po.saved',
    'تذكرة دعم'=>'g.new_ticket',
];

function translateAction($action) {
    global $_arActionMap;
    // 1. Try t() directly (for new entries that store translation keys)
    $translated = t($action);
    if ($translated !== $action) return $translated;
    // 2. Reverse lookup for old Arabic entries
    if (isset($_arActionMap[$action])) return t($_arActionMap[$action]);
    // 3. Return as-is
    return $action;
}

function getActionIcon($action) {
    global $_iconKeywords;
    $a = strtolower($action);
    foreach ($_iconKeywords as $kw => $icon) {
        if (strpos($a, $kw) !== false) return $icon;
    }
    return 'circle';
}

$_colorKeywords = ['login'=>'#3b82f6','logout'=>'#6b7280','delete'=>'#dc2626','add'=>'#16a34a','save'=>'#16a34a','edit'=>'#ea580c','approve'=>'#059669','execute'=>'#7c3aed','import'=>'#0891b2','expense'=>'#f59e0b','receipt'=>'#16a34a','payment'=>'#7c3aed','voucher'=>'#7c3aed','transfer'=>'#0891b2','count'=>'#3b82f6','manufacturing'=>'#8b5cf6','prescription'=>'#0ea5e9','close'=>'#dc2626','change'=>'#ea580c','adjust'=>'#ea580c','journal'=>'#3b82f6','clear'=>'#dc2626'];
function getActionColor($action) {
    global $_colorKeywords;
    $a = strtolower($action);
    foreach ($_colorKeywords as $kw => $color) {
        if (strpos($a, $kw) !== false) return $color;
    }
    return '#64748b';
}
?>

<style>
.log-filters { display:flex;gap:10px;align-items:center;flex-wrap:wrap; }
.log-filters .form-control, .log-filters select { font-size:12px;padding:6px 10px; }
.log-table { width:100%;border-collapse:collapse; }
.log-table th { background:#1a2744;color:#fff;padding:10px 12px;font-size:12px;text-align:right;position:sticky;top:0; }
.log-table td { padding:8px 12px;font-size:12px;border-bottom:1px solid #f1f5f9; }
.log-table tr:hover { background:#f8fafc; }
.log-action-badge { display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600; }
.log-module-badge { display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;background:#e2e8f0;color:#475569; }
.log-details { max-width:300px;font-size:11px;color:#64748b;word-break:break-word; }
.log-time { font-size:11px;color:#94a3b8;direction:ltr;text-align:left; }
.log-ip { font-size:10px;color:#cbd5e1;font-family:monospace; }
.log-stats { display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:15px; }
.log-stat { background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;text-align:center; }
.log-stat-val { font-size:22px;font-weight:800;color:#1a2744; }
.log-stat-label { font-size:11px;color:#94a3b8; }
.pagination-bar { display:flex;justify-content:center;gap:5px;margin-top:15px; }
.pagination-bar a { padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;text-decoration:none;color:#475569; }
.pagination-bar a.active { background:#1a2744;color:#fff;border-color:#1a2744; }
</style>

<!-- إحصائيات -->
<div class="log-stats">
    <div class="log-stat"><div class="log-stat-val"><?= number_format($stats['total']) ?></div><div class="log-stat-label"><?= t('activity.total_ops') ?></div></div>
    <div class="log-stat"><div class="log-stat-val"><?= $stats['users'] ?></div><div class="log-stat-label"><?= t('activity.active_users') ?></div></div>
    <div class="log-stat"><div class="log-stat-val"><?= $stats['modules'] ?></div><div class="log-stat-label"><?= t('activity.used_sections') ?></div></div>
</div>

<!-- فلاتر -->
<div class="card">
    <div class="card-body" style="padding:12px;">
        <form method="GET" class="log-filters">
            <div>
                <label style="font-size:11px;color:#64748b;"><?= t('users.user') ?></label>
                <select name="user" class="form-control" style="width:140px;">
                    <option value=""><?= t('all') ?></option>
                    <?php foreach ($usersList as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:11px;color:#64748b;"><?= t('activity.section') ?></label>
                <select name="module" class="form-control" style="width:150px;">
                    <option value=""><?= t('all') ?></option>
                    <?php foreach ($modulesList as $m): ?>
                    <option value="<?= htmlspecialchars($m) ?>" <?= $filterModule === $m ? 'selected' : '' ?>><?= $moduleNames[$m] ?? $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:11px;color:#64748b;"><?= t('activity.action') ?></label>
                <input type="text" name="action" class="form-control" value="<?= htmlspecialchars($filterAction) ?>" placeholder="<?= t('search_placeholder') ?>" style="width:130px;">
            </div>
            <div>
                <label style="font-size:11px;color:#64748b;"><?= t('from') ?></label>
                <input type="date" name="from" class="form-control" value="<?= $filterFrom ?>" style="width:150px;">
            </div>
            <div>
                <label style="font-size:11px;color:#64748b;"><?= t('to') ?></label>
                <input type="date" name="to" class="form-control" value="<?= $filterTo ?>" style="width:150px;">
            </div>
            <div style="padding-top:16px;">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter"></i><?= t('filter') ?></button>
                <a href="activity_log" class="btn btn-sm btn-secondary"><i class="fas fa-redo"></i></a>
            </div>
            <div style="padding-top:16px;margin-right:auto;">
                <?php if ($_isMainBranch): ?>
                <a href="?clear_all=1&confirm=yes" class="btn btn-sm btn-danger" onclick="return confirm('<?= t('clear_all') ?>')"><i class="fas fa-trash"></i><?= t('clear_all') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- جدول السجلات -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-history"></i> <?= t('activity.title') ?> (<?= number_format($totalRecords) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($logs)): ?>
        <p style="text-align:center;padding:30px;color:#94a3b8;"><?= t('no_results') ?></p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="log-table">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th style="width:12%;"><?= t('users.user') ?></th>
                        <th style="width:18%;"><?= t('activity.action') ?></th>
                        <th style="width:10%;"><?= t('activity.section') ?></th>
                        <th style="width:25%;"><?= t('activity.details') ?></th>
                        <th style="width:15%;"><?= t('activity.time') ?></th>
                        <th style="width:15%;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="color:#94a3b8;"><?= $log['id'] ?></td>
                        <td>
                            <strong style="color:#1a2744;"><?= htmlspecialchars($log['username']) ?></strong>
                        </td>
                        <td>
                            <span class="log-action-badge" style="background:<?= getActionColor($log['action']) ?>15;color:<?= getActionColor($log['action']) ?>;">
                                <i class="fas fa-<?= getActionIcon($log['action']) ?>"></i>
                                <?= htmlspecialchars(translateAction($log['action'])) ?>
                            </span>
                        </td>
                        <td><span class="log-module-badge"><?= $moduleNames[$log['module']] ?? $log['module'] ?></span></td>
                        <td class="log-details"><?= htmlspecialchars($log['details']) ?></td>
                        <td class="log-time">
                            <?= date('Y-m-d', strtotime($log['created_at'])) ?><br>
                            <strong><?= date('h:i:s A', strtotime($log['created_at'])) ?></strong>
                        </td>
                        <td>
                            <div class="log-ip"><?= htmlspecialchars($log['ip_address']) ?></div>
                            <?php
                            $ua = $log['user_agent'] ?? '';
                            $device = t('unknown');
                            if (strpos($ua, 'Windows') !== false) $device = 'Windows';
                            elseif (strpos($ua, 'Mac') !== false) $device = 'Mac';
                            elseif (strpos($ua, 'Linux') !== false) $device = 'Linux';
                            elseif (strpos($ua, 'Android') !== false) $device = 'Android';
                            elseif (strpos($ua, 'iPhone') !== false) $device = 'iPhone';
                            
                            $browser = '';
                            if (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
                            elseif (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
                            elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
                            elseif (strpos($ua, 'Edge') !== false) $browser = 'Edge';
                            ?>
                            <div style="font-size:10px;color:#94a3b8;"><?= $device ?> <?= $browser ? "• $browser" : '' ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination-bar">
            <?php
            $queryParams = $_GET;
            unset($queryParams['p']);
            $baseUrl = '?' . http_build_query($queryParams);
            ?>
            <?php if ($page_num > 1): ?>
            <a href="<?= $baseUrl ?>&p=<?= $page_num - 1 ?>"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
            <?php for ($i = max(1, $page_num - 3); $i <= min($totalPages, $page_num + 3); $i++): ?>
            <a href="<?= $baseUrl ?>&p=<?= $i ?>" class="<?= $i === $page_num ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page_num < $totalPages): ?>
            <a href="<?= $baseUrl ?>&p=<?= $page_num + 1 ?>"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
