<?php
require_once 'includes/config.php';
$pageTitle = t('branches.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('branches_manage');

// فحص حد الفروع
$_maxBr = $pdo->prepare("SELECT max_branches FROM tenants WHERE id = ?"); $_maxBr->execute([$tid]); $maxBranches = intval($_maxBr->fetchColumn()) ?: 1;
$_curBr = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE tenant_id = ? AND is_active = 1"); $_curBr->execute([$tid]); $currentBranches = intval($_curBr->fetchColumn());
$canAddBranch = $currentBranches < $maxBranches;

// إضافة فرع
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_branch'])) {
    verifyCsrfToken();
    if (!$canAddBranch) {
        echo '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . t('g.limit_reached') . '</div>';
    } else {
        try {
            // إضافة الفرع
            $pdo->prepare("INSERT INTO branches (tenant_id, name, address, phone, city, is_main, is_active) VALUES (?,?,?,?,?,?,1)")
                ->execute([$tid, $_POST['name'], $_POST['address'] ?? '', $_POST['phone'] ?? '', $_POST['city'] ?? '', 0]);
            $newBranchId = $pdo->lastInsertId();
            
            // إنشاء يوزر للفرع
            $branchUsername = trim($_POST['branch_username'] ?? '');
            $branchPassword = trim($_POST['branch_password'] ?? '');
            $branchFullName = trim($_POST['branch_manager_name'] ?? $_POST['name']);
            
            if ($branchUsername && $branchPassword) {
                // تحقق من عدم تكرار اسم المستخدم
                $exists = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=? AND username=?");
                $exists->execute([$tid, $branchUsername]);
                if ($exists->fetchColumn() > 0) {
                    throw new Exception(t('users.username_exists'));
                }
                
                $hashedPass = password_hash($branchPassword, PASSWORD_DEFAULT);
                // الصلاحيات اللي اختارها المدير
                $selectedPerms = $_POST['branch_permissions'] ?? [];
                $branchPermissions = json_encode(array_values($selectedPerms));
                
                $pdo->prepare("INSERT INTO users (tenant_id, branch_id, username, password, full_name, role, permissions, is_active) VALUES (?,?,?,?,?,'branch_admin',?,1)")
                    ->execute([$tid, $newBranchId, $branchUsername, $hashedPass, $branchFullName, $branchPermissions]);
            }
            
            logActivity($pdo, 'activity.add_branch', $_POST['name'], 'branches');
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
            $currentBranches++; $canAddBranch = $currentBranches < $maxBranches;
        } catch (Exception $e) {
            echo '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// تعديل فرع
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_branch'])) {
    verifyCsrfToken();
    $pdo->prepare("UPDATE branches SET name=?, address=?, phone=?, city=?, is_active=? WHERE id=? AND tenant_id = $tid")
        ->execute([$_POST['name'], $_POST['address'], $_POST['phone'], $_POST['city'], $_POST['is_active'] ?? 1, $_POST['branch_id']]);
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
}

// تبديل الفرع الحالي (فقط للمدير - admin)
if (isset($_GET['switch']) && intval($_GET['switch'])) {
    if ($_SESSION['role'] !== 'admin') {
        echo '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . t('g.cant_switch_branch') . '</div>';
    } else {
        $bid = intval($_GET['switch']);
        $b = $pdo->prepare("SELECT * FROM branches WHERE tenant_id = $tid AND id = ? AND is_active = 1"); $b->execute([$bid]);
        if ($b->fetch()) {
            $_SESSION['branch_id'] = $bid;
            $pdo->prepare("UPDATE users SET branch_id = ? WHERE id = ? AND tenant_id = $tid")->execute([$bid, $_SESSION['user_id']]);
            echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
        }
    }
}

$branches = $pdo->query("SELECT b.*, 
    (SELECT COUNT(*) FROM users WHERE tenant_id = $tid AND branch_id = b.id AND is_active = 1) as user_count,
    (SELECT COUNT(*) FROM products p JOIN inventory_batches ib ON ib.product_id = p.id WHERE p.tenant_id = $tid AND ib.branch_id = b.id AND ib.available_qty > 0) as product_count,
    (SELECT COALESCE(SUM(grand_total),0) FROM sales_invoices WHERE tenant_id = $tid AND branch_id = b.id AND MONTH(invoice_date)=MONTH(CURDATE()) AND YEAR(invoice_date)=YEAR(CURDATE())) as month_sales
    FROM branches b WHERE b.tenant_id = $tid ORDER BY b.is_main DESC, b.name")->fetchAll();

$currentBranch = getCurrentBranch();
?>

<!-- الفرع الحالي -->
<div style="background:linear-gradient(135deg,#0f3460,#1a2744);color:#fff;padding:16px 20px;border-radius:12px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:12px;opacity:0.7;"><?= t('g.current') ?></div>
        <div style="font-size:20px;font-weight:800;">
            <i class="fas fa-store"></i> 
            <?php 
            $cb = $pdo->prepare("SELECT name FROM branches WHERE tenant_id = $tid AND id = ?"); $cb->execute([$currentBranch]); $cbName = $cb->fetchColumn();
            echo $cbName ?: t('branches.main_branch'); 
            ?>
        </div>
    </div>
    <div style="display:flex;gap:6px;">
        <?php foreach ($branches as $b): if ($b['is_active']): ?>
        <a href="?switch=<?= $b['id'] ?>" class="btn btn-sm" style="background:<?= $b['id'] == $currentBranch ? 'rgba(255,255,255,0.3)' : 'rgba(255,255,255,0.1)' ?>;color:#fff;border:1px solid rgba(255,255,255,0.2);<?= $b['id'] == $currentBranch ? 'font-weight:700;' : '' ?>">
            <?= $b['id'] == $currentBranch ? '<i class="fas fa-check-circle"></i> ' : '' ?><?= $b['name'] ?>
        </a>
        <?php endif; endforeach; ?>
    </div>
</div>

<!-- حد الفروع -->
<div style="display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:12px;">
    <div style="font-size:14px;"><i class="fas fa-store" style="color:#1d4ed8;"></i> <?= t('branches.title') ?>: <strong><?= $currentBranches ?></strong> / <strong><?= $maxBranches ?></strong></div>
    <?php if (!$canAddBranch): ?>
    <span style="background:#fef2f2;color:#dc2626;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;"><?= t('g.limit_reached') ?></span>
    <?php else: ?>
    <span style="background:#f0fdf4;color:#16a34a;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;">متاح إضافة <?= $maxBranches - $currentBranches ?><?= t('g.branch') ?></span>
    <?php endif; ?>
</div>

<!-- إضافة فرع -->
<?php if ($canAddBranch): 
$allPermissions = getAllPermissions();
?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus-circle"></i><?= t('g.add_branch') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="add_branch" value="1">
            <h4 style="font-size:14px;font-weight:600;margin-bottom:10px;"><i class="fas fa-store"></i><?= t('g.branch_info') ?></h4>
            <div class="form-row">
                <div class="form-group"><label><?= t('branches.branch_name') ?> *</label><input type="text" name="name" class="form-control" required placeholder="<?= t('placeholder.example_branch') ?>"></div>
                <div class="form-group"><label><?= t('city') ?></label><input type="text" name="city" class="form-control" placeholder="المدينة"></div>
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="phone" class="form-control" placeholder="رقم الهاتف"></div>
                <div class="form-group"><label><?= t('address') ?></label><input type="text" name="address" class="form-control" placeholder="العنوان التفصيلي"></div>
            </div>
            <hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0;">
            <h4 style="font-size:14px;font-weight:600;margin-bottom:10px;"><i class="fas fa-user-shield"></i><?= t('g.branch_admin_account') ?></h4>
            <div class="form-row">
                <div class="form-group"><label><?= t('users.branch_admin') ?></label><input type="text" name="branch_manager_name" class="form-control" placeholder="اسم مدير الفرع"></div>
                <div class="form-group"><label><?= t('login.username') ?> *</label><input type="text" name="branch_username" class="form-control" required placeholder="<?= t('placeholder.example_username') ?>"></div>
                <div class="form-group"><label><?= t('login.password') ?> *</label><input type="text" name="branch_password" class="form-control" required placeholder="كلمة مرور الفرع" value="Branch@123"></div>
            </div>
            
            <hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h4 style="font-size:14px;font-weight:600;margin:0;"><i class="fas fa-key"></i><?= t('g.branch_permissions') ?></h4>
                <div style="display:flex;gap:6px;">
                    <button type="button" class="btn btn-sm" style="background:#dcfce7;color:#166534;font-size:11px;" onclick="toggleAllPerms(true)"><i class="fas fa-check-double"></i><?= t('g.select_all') ?></button>
                    <button type="button" class="btn btn-sm" style="background:#fef2f2;color:#dc2626;font-size:11px;" onclick="toggleAllPerms(false)"><i class="fas fa-times"></i><?= t('g.cancel_all') ?></button>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
            <?php foreach ($allPermissions as $groupName => $perms): ?>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                    <div style="font-weight:700;font-size:13px;color:#1d4ed8;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                        <input type="checkbox" class="group-check" onchange="toggleGroup(this)" checked>
                        <?= $groupName ?>
                    </div>
                    <?php foreach ($perms as $permKey => $permLabel): ?>
                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#374151;margin-bottom:4px;cursor:pointer;">
                        <input type="checkbox" name="branch_permissions[]" value="<?= $permKey ?>" class="perm-check" checked>
                        <?= $permLabel ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
            
            <div style="margin-top:16px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('g.add_branch') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function toggleAllPerms(state) {
    document.querySelectorAll('.perm-check,.group-check').forEach(c => c.checked = state);
}
function toggleGroup(el) {
    el.closest('div[style*="background"]').querySelectorAll('.perm-check').forEach(c => c.checked = el.checked);
}
</script>
<?php endif; ?>

<!-- قائمة الفروع -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-store"></i> <?= t('branches.title') ?> (<?= count($branches) ?>)</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:15px;">
        <?php foreach ($branches as $b): 
            // جلب يوزر الفرع
            $branchUser = $pdo->prepare("SELECT username, full_name FROM users WHERE tenant_id=? AND branch_id=? AND role IN('branch_admin','admin') ORDER BY id ASC LIMIT 1");
            $branchUser->execute([$tid, $b['id']]);
            $bUser = $branchUser->fetch();
        ?>
        <div style="border:2px solid <?= $b['id'] == $currentBranch ? '#3b82f6' : '#e2e8f0' ?>;border-radius:12px;padding:16px;background:<?= $b['is_active'] ? '#fff' : '#f8fafc' ?>;<?= !$b['is_active'] ? 'opacity:0.6;' : '' ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <h3 style="margin:0;font-size:16px;">
                    <i class="fas fa-store" style="color:#3b82f6;margin-left:5px;"></i>
                    <?= htmlspecialchars($b['name']) ?>
                    <?php if ($b['is_main']): ?><span style="background:#fef3c7;color:#92400e;font-size:9px;padding:1px 6px;border-radius:8px;"><?= t('main') ?></span><?php endif; ?>
                    <?php if ($b['id'] == $currentBranch): ?><span style="background:#dcfce7;color:#166534;font-size:9px;padding:1px 6px;border-radius:8px;"><?= t('g.current') ?></span><?php endif; ?>
                </h3>
                <span class="badge <?= $b['is_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $b['is_active'] ? 'فعال' : 'معطل' ?></span>
            </div>
            <?php if ($bUser): ?>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:8px 12px;border-radius:8px;margin-bottom:10px;font-size:12px;">
                <i class="fas fa-user" style="color:#1d4ed8;"></i> <strong><?= htmlspecialchars($bUser['full_name'] ?? $bUser['username']) ?></strong>
                <span style="color:#64748b;margin-right:8px;">|</span> يوزر: <strong style="color:#1d4ed8;"><?= htmlspecialchars($bUser['username']) ?></strong>
            </div>
            <?php endif; ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;font-size:12px;">
                <div><i class="fas fa-users" style="color:#6b7280;margin-left:3px;"></i> <?= $b['user_count'] ?><?= t('users.user') ?></div>
                <div><i class="fas fa-pills" style="color:#6b7280;margin-left:3px;"></i> <?= $b['product_count'] ?><?= t('g.stock_batch') ?></div>
                <div><i class="fas fa-phone" style="color:#6b7280;margin-left:3px;"></i> <?= $b['phone'] ?: '-' ?></div>
                <div><i class="fas fa-map-marker-alt" style="color:#6b7280;margin-left:3px;"></i> <?= $b['city'] ?: '-' ?></div>
            </div>
            <div style="background:#f8fafc;padding:8px;border-radius:6px;text-align:center;margin-bottom:10px;">
                <div style="font-size:11px;color:#6b7280;"><?= t('dash.month_sales') ?></div>
                <div style="font-size:18px;font-weight:800;color:#1d4ed8;"><?= formatMoney($b['month_sales']) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></div>
            </div>
            <div style="display:flex;gap:4px;">
                <button onclick="editBranch(<?= htmlspecialchars(json_encode($b)) ?>)" class="btn btn-sm btn-info" style="flex:1;"><i class="fas fa-edit"></i><?= t('edit') ?></button>
                <?php if ($b['id'] != $currentBranch && $b['is_active']): ?>
                <a href="?switch=<?= $b['id'] ?>" class="btn btn-sm btn-primary" style="flex:1;"><i class="fas fa-exchange-alt"></i><?= t('g.switch') ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- مودال التعديل -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:24px;max-width:500px;width:90%;">
        <h3 style="margin-bottom:15px;"><i class="fas fa-edit"></i><?= t('g.edit_branch') ?></h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="edit_branch" value="1">
            <input type="hidden" name="branch_id" id="eBid">
            <div class="form-group"><label><?= t('name') ?></label><input type="text" name="name" id="eName" class="form-control" required></div>
            <div class="form-group"><label><?= t('city') ?></label><input type="text" name="city" id="eCity" class="form-control"></div>
            <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="phone" id="ePhone" class="form-control"></div>
            <div class="form-group"><label><?= t('address') ?></label><input type="text" name="address" id="eAddress" class="form-control"></div>
            <div class="form-group"><label><input type="checkbox" name="is_active" value="1" id="eActive"><?= t('active') ?></label></div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('save') ?></button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('editModal').style.display='none'"><?= t('cancel') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function editBranch(b) {
    document.getElementById('eBid').value = b.id;
    document.getElementById('eName').value = b.name;
    document.getElementById('eCity').value = b.city || '';
    document.getElementById('ePhone').value = b.phone || '';
    document.getElementById('eAddress').value = b.address || '';
    document.getElementById('eActive').checked = b.is_active == 1;
    document.getElementById('editModal').style.display = 'flex';
}
</script>

<?php require_once 'includes/footer.php'; ?>
