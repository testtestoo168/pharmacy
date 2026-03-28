<?php
require_once 'includes/config.php';
$pageTitle = t('prescriptions.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('prescriptions_view');

// إضافة وصفة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prescription'])) {
    try {
        verifyCsrfToken();
        $prescNum = generateNumber($pdo, 'prescriptions', 'prescription_number', 'RX-');
        
        // رفع صورة الوصفة
        $imagePath = null;
        if (!empty($_FILES['prescription_image']['name'])) {
            $upload = SecurityService::secureUpload($_FILES['prescription_image'], 'assets/uploads/prescriptions', ['image/jpeg','image/png','image/webp','application/pdf']);
            if ($upload['success']) $imagePath = 'assets/uploads/prescriptions/' . $upload['filename'];
            else throw new Exception(implode(', ', $upload['errors']));
        }
        
        $pdo->prepare("INSERT INTO prescriptions (tenant_id, prescription_number, patient_name, doctor_name, hospital_clinic, prescription_date, notes, image_path, status, customer_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid, $prescNum, $_POST['patient_name'], $_POST['doctor_name'], $_POST['hospital_clinic'] ?? '', $_POST['prescription_date'], $_POST['notes'] ?? '', $imagePath, 'active', intval($_POST['customer_id']) ?: null, $_SESSION['user_id']]);
        
        logActivity($pdo, 'activity.add_prescription', "$prescNum — {$_POST['patient_name']}", 'prescriptions');
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . ' — ' . $prescNum . '</div>';
    } catch (Exception $e) {
        echo '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// تغيير حالة الوصفة
if (isset($_GET['action']) && $_GET['action'] === 'update_status') {
    $pid = intval($_GET['id']);
    $newStatus = $_GET['status'] ?? '';
    $allowed = ['active', 'used', 'expired', 'cancelled'];
    if (in_array($newStatus, $allowed)) {
        $pdo->prepare("UPDATE prescriptions SET status = ? WHERE id = ? AND tenant_id = ?")->execute([$newStatus, $pid, $tid]);
        logActivity($pdo, 'activity.update_prescription', "$pid → $newStatus", 'prescriptions');
    }
    header("Location: prescriptions?msg=updated"); exit;
}

// فلاتر
$statusFilter = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';
$where = "WHERE p.tenant_id = $tid";
$params = [];
if ($statusFilter) { $where .= " AND p.status = ?"; $params[] = $statusFilter; }
if ($search) { $where .= " AND (p.patient_name LIKE ? OR p.doctor_name LIKE ? OR p.prescription_number LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

$stmt = $pdo->prepare("SELECT p.*, c.name as customer_name, u.full_name as created_by_name FROM prescriptions p LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN users u ON u.id = p.created_by $where ORDER BY p.id DESC LIMIT 50");
$stmt->execute($params);
$prescriptions = $stmt->fetchAll();

$customers = $pdo->prepare("SELECT id, name, phone FROM customers WHERE tenant_id = ? ORDER BY name");
$customers->execute([$tid]);
$customersList = $customers->fetchAll();

$statusLabels = ['active'=>[t('active'),'#22c55e'], 'used'=>[t('prescriptions.dispensed'),'#3b82f6'], 'expired'=>[t('dash.expired'),'#f59e0b'], 'cancelled'=>[t('cancelled'),'#ef4444']];
?>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= t('saved_success') ?></div><?php endif; ?>

<!-- فلاتر + زر الإضافة -->
<div class="card no-print">
    <div class="card-body" style="padding:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between;">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <form method="GET" style="display:flex;gap:6px;align-items:center;">
                <input type="text" name="q" class="form-control" placeholder="<?= t('products.search_products') ?>" value="<?= htmlspecialchars($search) ?>" style="width:200px;">
                <select name="status" class="form-control" style="width:130px;" onchange="this.form.submit()">
                    <option value=""><?= t('g.all_statuses') ?></option>
                    <?php foreach ($statusLabels as $k => $v): ?><option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= $v[0] ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
            </form>
        </div>
        <button onclick="document.getElementById('addModal').style.display='flex'" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i><?= t('g.new_prescription') ?></button>
    </div>
</div>

<!-- الجدول -->
<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr><th><?= t('g.number') ?></th><th><?= t('g.patient_name') ?></th><th><?= t('g.doctor_name') ?></th><th><?= t('g.hospital') ?></th><th><?= t('sales.customer') ?></th><th><?= t('date') ?></th><th><?= t('status') ?></th><th><?= t('g.prescription_image') ?></th><th><?= t('actions') ?></th></tr>
            </thead>
            <tbody>
            <?php if (empty($prescriptions)): ?>
            <tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;"><?= t('no_results') ?></td></tr>
            <?php else: foreach ($prescriptions as $rx): ?>
            <tr>
                <td style="font-weight:700;"><?= htmlspecialchars($rx['prescription_number']) ?></td>
                <td><?= htmlspecialchars($rx['patient_name']) ?></td>
                <td><?= htmlspecialchars($rx['doctor_name']) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($rx['hospital_clinic'] ?? '-') ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($rx['customer_name'] ?? '-') ?></td>
                <td style="font-size:12px;color:#64748b;"><?= $rx['prescription_date'] ?></td>
                <td>
                    <?php $sl = $statusLabels[$rx['status']] ?? [t('unknown'),'#666']; ?>
                    <span style="background:<?= $sl[1] ?>22;color:<?= $sl[1] ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;"><?= $sl[0] ?></span>
                </td>
                <td>
                    <?php if ($rx['image_path']): ?>
                    <a href="<?= htmlspecialchars($rx['image_path']) ?>" target="_blank" style="color:#3b82f6;"><i class="fas fa-image"></i></a>
                    <?php else: ?>-<?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <?php if ($rx['status'] === 'active'): ?>
                        <a href="?action=update_status&id=<?= $rx['id'] ?>&status=used" class="btn btn-sm" style="background:#3b82f620;color:#3b82f6;padding:4px 8px;border-radius:4px;text-decoration:none;font-size:11px;" title="<?= t('prescriptions.dispensed') ?>"><i class="fas fa-check"></i></a>
                        <a href="?action=update_status&id=<?= $rx['id'] ?>&status=cancelled" class="btn btn-sm" style="background:#ef444420;color:#ef4444;padding:4px 8px;border-radius:4px;text-decoration:none;font-size:11px;" title="<?= t('cancel') ?>" onclick="return confirm('<?= t('prescriptions.confirm_cancel') ?>')"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- نافذة إضافة وصفة -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;">
        <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
            <h3 style="font-size:16px;"><i class="fas fa-file-prescription"></i><?= t('prescriptions.add_prescription') ?></h3>
            <button onclick="document.getElementById('addModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--foreground);">&times;</button>
        </div>
        <div style="padding:20px;">
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="add_prescription" value="1">
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div><label style="display:block;font-size:13px;font-weight:600;color:var(--foreground);margin-bottom:4px;"><?= t('g.patient_name') ?> *</label><input type="text" name="patient_name" class="form-control" required></div>
                    <div><label style="display:block;font-size:13px;font-weight:600;color:var(--foreground);margin-bottom:4px;"><?= t('g.doctor_name') ?> *</label><input type="text" name="doctor_name" class="form-control" required></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div><label style="display:block;font-size:13px;font-weight:600;color:var(--foreground);margin-bottom:4px;"><?= t('g.hospital') ?></label><input type="text" name="hospital_clinic" class="form-control"></div>
                    <div><label style="display:block;font-size:13px;font-weight:600;color:var(--foreground);margin-bottom:4px;"><?= t('g.prescription_date') ?> *</label><input type="date" name="prescription_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:var(--foreground);margin-bottom:4px;"><?= t('g.link_customer') ?></label>
                    <select name="customer_id" class="form-control">
                        <option value=""><?= t('g.no_link') ?></option>
                        <?php foreach ($customersList as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> <?= $c['phone'] ? "({$c['phone']})" : '' ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:var(--foreground);margin-bottom:4px;"><?= t('g.prescription_image') ?></label>
                    <input type="file" name="prescription_image" class="form-control" accept="image/*,application/pdf">
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:var(--foreground);margin-bottom:4px;"><?= t('notes') ?></label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
                <div style="text-align:left;">
                    <button type="button" onclick="document.getElementById('addModal').style.display='none'" class="btn btn-sm" style="background:var(--secondary);color:var(--foreground);padding:8px 16px;"><?= t('cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 20px;"><i class="fas fa-save"></i><?= t('g.save_prescription') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
