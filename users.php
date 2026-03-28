<?php
// معالجة البيانات قبل أي HTML output
require_once 'includes/config.php';
requireLogin();
$tid = getTenantId();

$successMsg = '';
$errorMsg = '';

// ========== حذف مستخدم ==========
if (isset($_GET['delete'])) {
    if (hasPermission('users_manage')) {
        $delId = intval($_GET['delete']);
        if ($delId == $_SESSION['user_id']) {
            $errorMsg = t('accounting.cannot_delete_own');
        } else {
            $pdo->prepare("DELETE FROM users WHERE tenant_id = $tid AND id = ?")->execute([$delId]);
            header('Location: users?msg=deleted');
            exit;
        }
    }
}

// ========== إضافة / تعديل مستخدم ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hasPermission('users_manage')) {
        if (isset($_POST['save_user'])) {
            try { verifyCsrfToken(); } catch(Exception $e) { $errorMsg = $e->getMessage(); }
            $username = trim($_POST['username']);
            $fullName = trim($_POST['full_name']);
            $role = $_POST['role'];
            $editId = intval($_POST['edit_id'] ?? 0);
            
            // تجميع الصلاحيات
            $permissions = $_POST['permissions'] ?? [];
            $permissionsJson = json_encode(array_values($permissions));
            
            try {
                if ($editId > 0) {
                    // تعديل
                    $pdo->prepare("UPDATE users SET username=?, full_name=?, role=?, permissions=? WHERE id=? AND tenant_id = $tid")
                        ->execute([$username, $fullName, $role, $permissionsJson, $editId]);
                    
                    // لو غير كلمة المرور
                    if (!empty($_POST['password'])) {
                        $plainPass = $_POST['password'];
                        $hash = password_hash($plainPass, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password=? WHERE id=? AND tenant_id = $tid")->execute([$hash, $editId]);
                    }
                    
                    if ($editId == $_SESSION['user_id']) {
                        loadUserPermissions($pdo, $_SESSION['user_id']);
                    }
                    
                    logActivity($pdo, 'activity.edit_user', "$username — $role", 'users');
                    header('Location: users?msg=updated');
                    exit;
                } else {
                    // إضافة جديد
                    if (empty($_POST['password'])) {
                        $errorMsg = t('users.password_required');
                    } else {
                        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id = $tid AND username = ?");
                        $check->execute([$username]);
                        if ($check->fetchColumn() > 0) {
                            $errorMsg = t('users.username_exists');
                        } else {
                            $plainPass = $_POST['password'];
                            $hash = password_hash($plainPass, PASSWORD_DEFAULT);
                            $pdo->prepare("INSERT INTO users (tenant_id, branch_id, username, password, full_name, role, permissions) VALUES (?,?,?,?,?,?,?)")
                                ->execute([$tid, getBranchId(), $username, $hash, $fullName, $role, $permissionsJson]);
                            logActivity($pdo, 'activity.add_user', "$username — $fullName", 'users');
                            header('Location: users?msg=added');
                            exit;
                        }
                    }
                }
            } catch (Exception $e) {
                $errorMsg = t('error') . ': ' . $e->getMessage();
            }
        }
    }
}

// رسائل النجاح
$msgs = ['added' => t('saved_success'), 'updated' => t('saved_success'), 'deleted' => t('saved_success')];
if (isset($_GET['msg']) && isset($msgs[$_GET['msg']])) {
    $successMsg = $msgs[$_GET['msg']];
}

// ========== HTML ==========
$pageTitle = t('users.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('users_manage');

$users = $pdo->query("SELECT * FROM users WHERE tenant_id = $tid ORDER BY id")->fetchAll();
$allPermissions = getAllPermissions();

// تعديل؟
$editUser = null;
$editPerms = [];
$allPermKeys = [];
foreach (getAllPermissions() as $group => $perms) { foreach ($perms as $k => $v) { $allPermKeys[] = $k; } }
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE tenant_id = $tid AND id = ?");
    $stmt->execute([$_GET['edit']]);
    $editUser = $stmt->fetch();
    if ($editUser) {
        $editPerms = json_decode($editUser['permissions'] ?? '[]', true) ?: [];
    }
} else {
    // مستخدم جديد — كل الصلاحيات مفتوحة افتراضياً
    $editPerms = $allPermKeys;
}

// ========== القائمة الجانبية كصلاحيات ==========
$menuPermissions = [
    [
        'section' => t('nav.home'),
        'items' => [
            ['icon' => 'fas fa-home', 'label' => t('nav.dashboard'), 'key' => 'dashboard', 'sub' => [
                'dashboard_stats_sales' => t('perms.sales_stats'),
                'dashboard_stats_profit' => t('perms.profit_stats'),
                'dashboard_stats_expenses' => t('perms.expense_stats'),
                'dashboard_stats_cashflow' => t('perms.cashflow_stats'),
                'dashboard_stats_weekly' => t('perms.weekly_sales'),
                'dashboard_stats_monthly' => t('perms.monthly_sales'),
                'dashboard_stats_manufacturing' => t('perm_labels.manufacturing_stats'),
                'dashboard_stats_employees' => t('perm_labels.employee_stats'),
                'dashboard_charts' => t('perms.charts'),
                'dashboard_recent_sales' => t('perms.recent_sales'),
                'dashboard_recent_manufacturing' => t('perm_labels.recent_manufacturing'),
                'dashboard_stats_inventory' => t('perms.inventory_stats'),
                'dashboard_alerts' => t('perms.alerts'),
            ]],
        ]
    ],
    [
        'section' => t('perms.pos'),
        'items' => [
            ['icon' => 'fas fa-cash-register', 'label' => t('nav.pos_screen'), 'key' => 'pos_access', 'sub' => [
                'pos_discount' => t('perms.pos_discount'),
                'pos_return' => t('perms.pos_return'),
                'pos_hold' => t('perms.pos_hold'),
                'pos_reprint' => t('perms.pos_reprint'),
            ]],
        ]
    ],
    [
        'section' => t('perms.medicines_inventory'),
        'items' => [
            ['icon' => 'fas fa-pills', 'label' => t('nav.medicines_items'), 'key' => 'products_view', 'sub' => [
                'products_add' => t('perms.add_product'),
                'products_edit' => t('perms.edit_product'),
                'products_delete' => t('perms.delete_product'),
                'price_edit' => t('perms.edit_price'),
                'cost_edit' => t('perms.edit_cost'),
            ]],
            ['icon' => 'fas fa-boxes', 'label' => t('nav.inventory_batches'), 'key' => 'inventory_view', 'sub' => [
                'inventory_adjust' => t('perms.adjust_inventory'),
                'batches_view' => t('perms.view_batches'),
            ]],
            ['icon' => 'fas fa-clipboard-check', 'label' => t('nav.count_adjustments'), 'key' => 'inventory_count', 'sub' => [
                'inventory_count_approve' => t('perms.approve_count'),
            ]],
            ['icon' => 'fas fa-exchange-alt', 'label' => t('nav.branch_transfer'), 'key' => 'inventory_transfer', 'sub' => [
                'inventory_transfer_approve' => t('perms.approve_transfer'),
            ]],
        ]
    ],
    [
        'section' => t('nav.finance'),
        'items' => [
            ['icon' => 'fas fa-file-invoice-dollar', 'label' => t('perms.sales'), 'key' => 'sales_view', 'sub' => [
                'sales_add' => t('perms.add_sale'),
                'sales_print' => t('perms.print_sale'),
                'sales_delete' => t('perms.delete_sale'),
                'sales_return' => t('perms.sale_return'),
            ]],
            ['icon' => 'fas fa-shopping-cart', 'label' => t('perms.purchases'), 'key' => 'purchases_view', 'sub' => [
                'purchases_add' => t('perms.add_purchase'),
                'purchases_delete' => t('perms.delete_purchase'),
                'purchases_return' => t('perms.purchase_return'),
                'purchase_orders_view' => t('perms.view_purchase_orders'),
                'purchase_orders_add' => t('perms.add_purchase_order'),
            ]],
            ['icon' => 'fas fa-hand-holding-usd', 'label' => t('perms.receipt_vouchers'), 'key' => 'receipts_view', 'sub' => [
                'receipts_add' => t('perms.add_receipt'),
                'receipts_print' => t('perms.print_receipt'),
                'receipts_delete' => t('perms.delete_receipt'),
            ]],
            ['icon' => 'fas fa-money-check-alt', 'label' => t('perms.payment_vouchers'), 'key' => 'payments_view', 'sub' => [
                'payments_add' => t('perms.add_payment'),
                'payments_print' => t('perms.print_payment'),
                'payments_delete' => t('perms.delete_payment'),
            ]],
            ['icon' => 'fas fa-receipt', 'label' => t('perms.expenses'), 'key' => 'expenses_view', 'sub' => [
                'expenses_add' => t('perms.add_expense'),
                'expenses_delete' => t('perms.delete_expense'),
            ]],
        ]
    ],
    [
        'section' => t('nav.accounting'),
        'items' => [
            ['icon' => 'fas fa-sitemap', 'label' => t('nav.chart_of_accounts'), 'key' => 'accounting_view', 'sub' => [
                'accounting_edit' => t('perms.edit_journal'),
            ]],
            ['icon' => 'fas fa-chart-bar', 'label' => t('perms.financial_reports'), 'key' => 'reports_financial', 'sub' => [
                'reports_export' => t('perms.export_reports'),
            ]],
        ]
    ],
    [
        'section' => t('perms.prescriptions'),
        'items' => [
            ['icon' => 'fas fa-prescription', 'label' => t('nav.prescriptions'), 'key' => 'prescriptions_view', 'sub' => [
                'prescriptions_add' => t('perms.add_prescription'),
                'prescriptions_edit' => t('perms.edit_prescription'),
                'prescriptions_approve' => t('perms.approve_prescription'),
            ]],
        ]
    ],
    [
        'section' => t('nav.manufacturing'),
        'items' => [
            ['icon' => 'fas fa-industry', 'label' => t('manufacturing.title'), 'key' => 'manufacturing_view', 'sub' => [
                'manufacturing_add' => t('perm_labels.add_manufacturing'),
                'manufacturing_edit' => t('perm_labels.edit_manufacturing'),
                'manufacturing_print' => t('g.print_report'),
                'manufacturing_delete' => t('perm_labels.delete_manufacturing'),
            ]],
        ]
    ],
    [
        'section' => t('perm_sections.employees'),
        'items' => [
            ['icon' => 'fas fa-users', 'label' => t('perm_labels.employees_data'), 'key' => 'employees_view', 'sub' => [
                'employees_add' => t('perm_labels.add_employee'),
                'employees_edit' => t('perm_labels.edit_employee'),
                'employees_delete' => t('perm_labels.delete_employee'),
            ]],
            ['icon' => 'fas fa-coins', 'label' => t('perm_labels.payroll'), 'key' => 'payroll_view', 'sub' => [
                'payroll_add' => t('perm_labels.add_payroll'),
                'payroll_delete' => t('perm_labels.delete_payroll'),
            ]],
            ['icon' => 'fas fa-calendar-minus', 'label' => t('perm_labels.leaves'), 'key' => 'leaves_view', 'sub' => [
                'leaves_add' => t('perm_labels.add_leave'),
                'leaves_edit' => t('perm_labels.edit_leave'),
                'leaves_delete' => t('perm_labels.delete_leave'),
            ]],
        ]
    ],
    [
        'section' => t('nav.contacts'),
        'items' => [
            ['icon' => 'fas fa-user-tie', 'label' => t('perms.customers'), 'key' => 'customers_view', 'sub' => [
                'customers_add' => t('perms.add_customer'),
                'customers_edit' => t('perms.edit_customer'),
                'customers_delete' => t('perms.delete_customer'),
            ]],
            ['icon' => 'fas fa-truck', 'label' => t('perms.suppliers'), 'key' => 'suppliers_view', 'sub' => [
                'suppliers_add' => t('perms.add_supplier'),
                'suppliers_edit' => t('perms.edit_supplier'),
                'suppliers_delete' => t('perms.delete_supplier'),
            ]],
        ]
    ],
    [
        'section' => t('perm_sections.owner'),
        'items' => [
            ['icon' => 'fas fa-exchange-alt', 'label' => t('perm_labels.owner_transfers'), 'key' => 'owner_transfers_view', 'sub' => [
                'owner_transfers_add' => t('perm_labels.add_owner_transfer'),
                'owner_transfers_delete' => t('perm_labels.delete_owner_transfer'),
            ]],
            ['icon' => 'fas fa-briefcase', 'label' => t('perm_labels.owner_works'), 'key' => 'owner_works_view', 'sub' => [
                'owner_works_add' => t('perm_labels.add_owner_work'),
                'owner_works_delete' => t('perm_labels.delete_owner_work'),
            ]],
        ]
    ],
    [
        'section' => t('nav.system'),
        'items' => [
            ['icon' => 'fas fa-chart-bar', 'label' => t('nav.reports'), 'key' => 'reports_view', 'sub' => []],
            ['icon' => 'fas fa-code-branch', 'label' => t('nav.branch_management'), 'key' => 'branches_manage', 'sub' => []],
            ['icon' => 'fas fa-cog', 'label' => t('nav.settings'), 'key' => 'settings_view', 'sub' => [
                'settings_edit' => t('perms.edit_settings'),
            ]],
            ['icon' => 'fas fa-user-shield', 'label' => t('nav.users_permissions'), 'key' => 'users_manage', 'sub' => []],
            ['icon' => 'fas fa-history', 'label' => t('nav.activity_log'), 'key' => 'activity_log', 'sub' => []],
            ['icon' => 'fas fa-headset', 'label' => t('nav.support'), 'key' => 'support_tickets', 'sub' => []],
            ['icon' => 'fas fa-bell', 'label' => t('perms.manage_notifications'), 'key' => 'notifications_manage', 'sub' => []],
        ]
    ],
];
?>

<style>
.perm-sidebar {
    background: #1a2744;
    border-radius: 12px;
    overflow: hidden;
    max-width: 460px;
}
.perm-sidebar .perm-section-title {
    color: rgba(255,255,255,0.4);
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 14px 18px 6px;
}
.perm-menu-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 18px;
    cursor: pointer;
    transition: background 0.15s;
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.perm-menu-item:hover { background: rgba(255,255,255,0.06); }
.perm-menu-item .perm-label {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #fff;
    font-size: 13.5px;
    font-weight: 500;
}
.perm-menu-item .perm-label i.menu-icon {
    width: 20px;
    text-align: center;
    color: rgba(255,255,255,0.5);
    font-size: 14px;
}
.perm-toggle {
    position: relative;
    width: 40px;
    height: 22px;
    flex-shrink: 0;
}
.perm-toggle input { opacity: 0; width: 0; height: 0; }
.perm-toggle .slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255,255,255,0.15);
    border-radius: 22px;
    transition: 0.25s;
}
.perm-toggle .slider:before {
    content: '';
    position: absolute;
    height: 16px; width: 16px;
    left: 3px; bottom: 3px;
    background: #fff;
    border-radius: 50%;
    transition: 0.25s;
}
.perm-toggle input:checked + .slider { background: #16a34a; }
.perm-toggle input:checked + .slider:before { transform: translateX(18px); }
.perm-sub-list {
    background: rgba(0,0,0,0.2);
    padding: 0;
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.3s ease;
}
.perm-sub-list.open { max-height: 500px; }
.perm-sub-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 18px 7px 48px;
    border-bottom: 1px solid rgba(255,255,255,0.03);
}
.perm-sub-item .sub-label {
    color: rgba(255,255,255,0.6);
    font-size: 12px;
}
.perm-sub-toggle {
    position: relative;
    width: 34px;
    height: 18px;
    flex-shrink: 0;
}
.perm-sub-toggle input { opacity: 0; width: 0; height: 0; }
.perm-sub-toggle .slider {
    position: absolute; cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255,255,255,0.12);
    border-radius: 18px;
    transition: 0.25s;
}
.perm-sub-toggle .slider:before {
    content: '';
    position: absolute;
    height: 12px; width: 12px;
    left: 3px; bottom: 3px;
    background: rgba(255,255,255,0.7);
    border-radius: 50%;
    transition: 0.25s;
}
.perm-sub-toggle input:checked + .slider { background: #16a34a; }
.perm-sub-toggle input:checked + .slider:before { transform: translateX(16px); background: #fff; }
.perm-expand-btn {
    color: rgba(255,255,255,0.3);
    font-size: 11px;
    cursor: pointer;
    margin-right: 4px;
    transition: transform 0.2s;
}
.perm-expand-btn.open { transform: rotate(-90deg); }
.password-wrapper {
    position: relative;
}
.password-wrapper input { padding-left: 44px !important; }
.password-eye {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #999;
    cursor: pointer;
    font-size: 16px;
    padding: 4px;
    z-index: 2;
}
.password-eye:hover { color: var(--primary); }
.user-card-pass {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f1f5f9;
    border-radius: 6px;
    padding: 3px 10px;
    font-family: monospace;
    font-size: 13px;
    direction: ltr;
}
.user-card-pass .eye-btn {
    background: none; border: none; color: #999;
    cursor: pointer; font-size: 13px; padding: 2px;
}
.user-card-pass .eye-btn:hover { color: var(--primary); }
</style>

<?php if ($successMsg): ?>
<div class="alert alert-success" id="successAlert" style="display:flex;align-items:center;gap:10px;">
    <i class="fas fa-check-circle" style="font-size:20px;"></i>
    <span><?= htmlspecialchars($successMsg) ?></span>
</div>
<script>setTimeout(function(){ var el = document.getElementById('successAlert'); if(el) el.style.display='none'; }, 4000);</script>
<?php endif; ?>

<?php if ($errorMsg): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<!-- ========== فورم إضافة / تعديل مستخدم ========== -->
<div class="card">
    <div class="card-header" style="background:<?= $editUser ? '#fff7ed' : '#eef7ee' ?>;">
        <h3 style="color:<?= $editUser ? '#ea580c' : '#16a34a' ?>;">
            <i class="fas fa-<?= $editUser ? 'user-edit' : 'user-plus' ?>"></i>
            <?= $editUser ? t('users.edit_user') . ': ' . htmlspecialchars($editUser['full_name']) : t('users.add_user') ?>
        </h3>
        <?php if ($editUser): ?>
        <a href="users" class="btn btn-sm btn-outline"><i class="fas fa-times"></i><?= t('g.cancel_edit') ?></a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" id="userForm">
            <?= csrfField() ?>
            <input type="hidden" name="edit_id" value="<?= $editUser['id'] ?? 0 ?>">
            
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> <?= t('users.username') ?></label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($editUser['username'] ?? '') ?>" required placeholder="<?= t('users.username') ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> <?= t('users.full_name') ?></label>
                    <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($editUser['full_name'] ?? '') ?>" required placeholder="<?= t('users.full_name') ?>">
                </div>
                <div class="form-group">
                    <label>
                        <i class="fas fa-key"></i> <?= t('login.password') ?>
                        <?php if ($editUser): ?>
                        <small class="text-muted"></small>
                        <?php endif; ?>
                    </label>
                    <div class="password-wrapper">
                        <input type="text" name="password" id="passwordField" class="form-control" 
                            value=""
                            <?= $editUser ? '' : 'required' ?> 
                            placeholder="<?= t('login.enter_password') ?>"
                            autocomplete="new-password" placeholder="<?= $editUser ? t('users.password_optional') : t('login.enter_password') ?>">
                        <button type="button" class="password-eye" onclick="togglePasswordField()">
                            <i class="fas fa-eye-slash" id="passwordEyeIcon"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-shield-alt"></i> <?= t('users.role') ?></label>
                    <select name="role" class="form-control" id="roleSelect" onchange="togglePermissions()">
                        <option value="admin" <?= ($editUser['role'] ?? '') == 'admin' ? 'selected' : '' ?>><?= t('users.admin') ?></option>
                        <option value="user" <?= ($editUser['role'] ?? 'user') == 'user' ? 'selected' : '' ?>><?= t('users.user') ?></option>
                    </select>
                </div>
            </div>

            <!-- ========== الصلاحيات — شكل القائمة الجانبية ========== -->
            <div id="permissionsSection" style="margin-top:24px;<?= ($editUser && $editUser['role'] == 'admin') || !$editUser ? 'display:none;' : '' ?>">
                
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 style="margin:0;font-size:16px;"><i class="fas fa-user-shield"></i> <?= t('g.select_permissions') ?></h3>
                    <div style="display:flex;gap:8px;">
                        <button type="button" onclick="selectAllPerms()" class="btn btn-sm btn-success"><i class="fas fa-check-double"></i><?= t('g.enable_all') ?></button>
                        <button type="button" onclick="deselectAllPerms()" class="btn btn-sm btn-danger"><i class="fas fa-times"></i><?= t('g.cancel_all') ?></button>
                    </div>
                </div>

                <div class="perm-sidebar">
                    <?php foreach ($menuPermissions as $section): ?>
                    <div class="perm-section-title"><?= $section['section'] ?></div>
                    
                    <?php foreach ($section['items'] as $item): 
                        $mainKey = $item['key'];
                        $hasSub = !empty($item['sub']);
                        $mainChecked = in_array($mainKey, $editPerms);
                    ?>
                    <div class="perm-menu-item" <?php if ($hasSub): ?>onclick="toggleSubList('sub_<?= $mainKey ?>', '<?= $mainKey ?>')"<?php endif; ?>>
                        <div class="perm-label">
                            <i class="<?= $item['icon'] ?> menu-icon"></i>
                            <span><?= $item['label'] ?></span>
                            <?php if ($hasSub): ?>
                            <i class="fas fa-chevron-left perm-expand-btn <?= $mainChecked ? 'open' : '' ?>" id="arrow_<?= $mainKey ?>"></i>
                            <?php endif; ?>
                        </div>
                        <label class="perm-toggle" onclick="event.stopPropagation()">
                            <input type="checkbox" name="permissions[]" value="<?= $mainKey ?>" class="main-perm" data-key="<?= $mainKey ?>"
                                <?= $mainChecked ? 'checked' : '' ?>
                                onchange="onMainToggle(this)">
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <?php if ($hasSub): ?>
                    <div class="perm-sub-list <?= $mainChecked ? 'open' : '' ?>" id="sub_<?= $mainKey ?>">
                        <?php foreach ($item['sub'] as $subKey => $subLabel): ?>
                        <div class="perm-sub-item">
                            <span class="sub-label"><?= $subLabel ?></span>
                            <label class="perm-sub-toggle" onclick="event.stopPropagation()">
                                <input type="checkbox" name="permissions[]" value="<?= $subKey ?>" class="sub-perm" data-parent="<?= $mainKey ?>"
                                    <?= in_array($subKey, $editPerms) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                
                <p class="text-muted" style="margin-top:10px;font-size:12px;">
                    <i class="fas fa-info-circle"></i> <?= t('users.permissions_hint') ?>
                </p>
            </div>

            <div style="margin-top:24px;display:flex;gap:10px;">
                <button type="submit" name="save_user" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> <?= $editUser ? t('save') : t('users.add_user') ?>
                </button>
                <?php if ($editUser): ?>
                <a href="users" class="btn btn-secondary btn-lg"><?= t('cancel') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ========== جدول المستخدمين ========== -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-users-cog"></i> <?= t('users.title') ?> (<?= count($users) ?>)</h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= t('login.username') ?></th>
                        <th><?= t('users.full_name') ?></th>
                        <th><?= t('login.password') ?></th>
                        <th><?= t('type') ?></th>
                        <th><?= t('users.permissions') ?></th>
                        <th><?= t('actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $i => $u):
                        $userPerms = json_decode($u['permissions'] ?? '[]', true) ?: [];
                        $permCount = count($userPerms);
                        $totalPerms = 0;
                        foreach ($allPermissions as $grp) $totalPerms += count($grp);
                        $storedPass = ''; // plain_pass removed
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                        <td dir="auto"><?= htmlspecialchars($u['full_name']) ?></td>
                        <td>
<span style="color:#94a3b8;font-size:12px;">••••••••</span>
                        </td>
                        <td>
                            <?php if ($u['role'] == 'admin'): ?>
                            <span class="badge badge-primary"><i class="fas fa-crown"></i><?= t('users.manager') ?></span>
                            <?php else: ?>
                            <span class="badge badge-info"><i class="fas fa-user"></i><?= t('users.user') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['role'] == 'admin'): ?>
                            <span style="color:#16a34a;font-weight:600;font-size:12px;"><i class="fas fa-check-double"></i><?= t('g.all_permissions') ?></span>
                            <?php else: ?>
                            <span style="font-size:12px;color:var(--muted-foreground);">
                                <?= $permCount ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;">
                                <a href="?edit=<?= $u['id'] ?>" class="btn btn-sm btn-warning" title="<?= t('edit') ?>"><i class="fas fa-edit"></i></a>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <a href="?delete=<?= $u['id'] ?>" class="btn btn-sm btn-danger" title="<?= t('delete') ?>" onclick="return confirm('<?= t('users.confirm_delete') ?>')"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ========== باسورد — إظهار/إخفاء في الفورم ==========
let passVisible = true;
function togglePasswordField() {
    const field = document.getElementById('passwordField');
    const icon = document.getElementById('passwordEyeIcon');
    if (passVisible) {
        field.type = 'password';
        icon.className = 'fas fa-eye';
        passVisible = false;
    } else {
        field.type = 'text';
        icon.className = 'fas fa-eye-slash';
        passVisible = true;
    }
}

// باسورد — إظهار/إخفاء في الجدول
function toggleTablePass(userId, realPass) {
    const span = document.getElementById('pass_' + userId);
    const icon = document.getElementById('eye_' + userId);
    if (span.textContent === '••••••') {
        span.textContent = realPass;
        icon.className = 'fas fa-eye-slash';
    } else {
        span.textContent = '••••••';
        icon.className = 'fas fa-eye';
    }
}

// ========== الصلاحيات ==========
function togglePermissions() {
    const role = document.getElementById('roleSelect').value;
    document.getElementById('permissionsSection').style.display = role === 'admin' ? 'none' : 'block';
}

function toggleSubList(subId, mainKey) {
    const sub = document.getElementById(subId);
    const arrow = document.getElementById('arrow_' + mainKey);
    if (sub) {
        sub.classList.toggle('open');
        if (arrow) arrow.classList.toggle('open');
    }
}

function onMainToggle(cb) {
    const key = cb.dataset.key;
    const sub = document.getElementById('sub_' + key);
    const arrow = document.getElementById('arrow_' + key);
    if (cb.checked) {
        if (sub) {
            sub.classList.add('open');
            if (arrow) arrow.classList.add('open');
            sub.querySelectorAll('.sub-perm').forEach(s => s.checked = true);
        }
    } else {
        if (sub) {
            sub.querySelectorAll('.sub-perm').forEach(s => s.checked = false);
        }
    }
}

function selectAllPerms() {
    document.querySelectorAll('.main-perm, .sub-perm').forEach(cb => cb.checked = true);
    document.querySelectorAll('.perm-sub-list').forEach(s => s.classList.add('open'));
    document.querySelectorAll('.perm-expand-btn').forEach(a => a.classList.add('open'));
}

function deselectAllPerms() {
    document.querySelectorAll('.main-perm, .sub-perm').forEach(cb => cb.checked = false);
    document.querySelectorAll('.perm-sub-list').forEach(s => s.classList.remove('open'));
    document.querySelectorAll('.perm-expand-btn').forEach(a => a.classList.remove('open'));
}

togglePermissions();
</script>

<?php require_once 'includes/footer.php'; ?>
