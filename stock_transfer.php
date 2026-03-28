<?php
require_once 'includes/config.php';
$pageTitle = t('inventory.branch_transfer');
requireLogin();
$tid = getTenantId();
$bid = getBranchId();
$branchId = getCurrentBranch();

// === API calls — قبل header.php ===
if (isset($_GET['api']) && $_GET['api'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $fromBranch = intval($_GET['from_branch'] ?? $branchId);
    $stmt = $pdo->prepare("SELECT p.id, p.barcode, p.name, p.unit,
        (SELECT COALESCE(SUM(ib.available_qty),0) FROM inventory_batches ib WHERE ib.tenant_id = $tid AND ib.product_id = p.id AND ib.branch_id = ? AND ib.available_qty > 0) as available_qty
        FROM products p WHERE p.tenant_id = $tid AND p.branch_id = ? AND p.is_active = 1 AND (p.name LIKE ? OR p.barcode LIKE ?) HAVING available_qty > 0 ORDER BY p.name LIMIT 15");
    $stmt->execute([$fromBranch, $fromBranch, $q, $q]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE); exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'all_products') {
    header('Content-Type: application/json; charset=utf-8');
    $fromBranch = intval($_GET['from_branch'] ?? $branchId);
    $stmt = $pdo->prepare("SELECT p.id, p.barcode, p.name, p.unit,
        (SELECT COALESCE(SUM(ib.available_qty),0) FROM inventory_batches ib WHERE ib.tenant_id = ? AND ib.product_id = p.id AND ib.branch_id = ? AND ib.available_qty > 0) as available_qty
        FROM products p WHERE p.tenant_id = ? AND p.branch_id = ? AND p.is_active = 1 HAVING available_qty > 0 ORDER BY p.name");
    $stmt->execute([$tid, $fromBranch, $tid, $fromBranch]);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE); exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'batches') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = intval($_GET['product_id'] ?? 0);
    $fromBranch = intval($_GET['from_branch'] ?? $branchId);
    $batches = $pdo->prepare("SELECT id, batch_number, available_qty, expiry_date FROM inventory_batches WHERE tenant_id = $tid AND product_id = ? AND branch_id = ? AND available_qty > 0 ORDER BY expiry_date ASC");
    $batches->execute([$pid, $fromBranch]);
    echo json_encode($batches->fetchAll(), JSON_UNESCAPED_UNICODE); exit;
}

// === Header ===
require_once 'includes/header.php';
requirePermission('inventory_transfer');

$branches = $pdo->query("SELECT * FROM branches WHERE tenant_id = $tid AND is_active = 1 ORDER BY name")->fetchAll();
syncStockToBatches($pdo, $tid, $branchId);

$isViewMode = isset($_GET['view']) && intval($_GET['view']);
$statusLabels = ['pending'=>t('pending'),'approved'=>t('approved'),'completed'=>t('completed'),'cancelled'=>t('cancelled')];
$statusBadge = ['pending'=>'badge-warning','approved'=>'badge-info','completed'=>'badge-success','cancelled'=>'badge-danger'];

// ========== ACTIONS ==========

// إنشاء تحويل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_transfer'])) {
    try {
        verifyCsrfToken();
        $pdo->beginTransaction();
        $fromBranch = intval($_POST['from_branch']);
        $toBranch = intval($_POST['to_branch']);
        if ($fromBranch === $toBranch) throw new Exception(t('transfer.same_branch'));
        if (!$toBranch) throw new Exception(t('transfer.select_dest'));

        $transferNumber = 'TR-' . str_pad($pdo->query("SELECT COALESCE(MAX(CAST(REPLACE(transfer_number,'TR-','') AS UNSIGNED)),0)+1 FROM stock_transfers WHERE tenant_id = $tid")->fetchColumn(), 6, '0', STR_PAD_LEFT);
        
        $pdo->prepare("INSERT INTO stock_transfers (tenant_id,transfer_number, from_branch_id, to_branch_id, transfer_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$tid,$transferNumber, $fromBranch, $toBranch, $_POST['transfer_date'], 'pending', $_POST['notes'] ?? '', $_SESSION['user_id']]);
        $transferId = $pdo->lastInsertId();

        $productIds = $_POST['product_id'] ?? [];
        $batchIds = $_POST['batch_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if (empty($productIds)) throw new Exception(t('validation.add_one_product'));

        $stmtItem = $pdo->prepare("INSERT INTO stock_transfer_items (transfer_id, product_id, batch_id, quantity) VALUES (?,?,?,?)");
        for ($i = 0; $i < count($productIds); $i++) {
            $pid = intval($productIds[$i]);
            $bidx = intval($batchIds[$i]) ?: null;
            $qty = intval($quantities[$i]);
            if (!$pid || $qty <= 0) continue;
            $stmtItem->execute([$transferId, $pid, $bidx, $qty]);
        }

        $pdo->commit();
        logActivity($pdo, 'activity.save_transfer', $transferNumber, 'inventory');
        header("Location: stock_transfer?msg=created&num=$transferNumber"); exit;
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        $errorMsg = $e->getMessage();
    }
}

// اعتماد تحويل
if (isset($_GET['approve']) && intval($_GET['approve'])) {
    if ($_SESSION['role'] !== 'admin') {
        $errorMsg = t('inventory.approve_from_main');
    } elseif (hasPermission('inventory_transfer_approve')) {
    try {
        $pdo->beginTransaction();
        $approveId = intval($_GET['approve']);
        $transfer = $pdo->prepare("SELECT * FROM stock_transfers WHERE tenant_id = ? AND id = ? AND status = 'pending'");
        $transfer->execute([$tid, $approveId]); $transfer = $transfer->fetch();
        if (!$transfer) throw new Exception(t('transfer.not_found'));

        $items = $pdo->prepare("SELECT sti.*, p.name FROM stock_transfer_items sti JOIN products p ON p.id = sti.product_id WHERE sti.transfer_id = ?");
        $items->execute([$approveId]);

        foreach ($items->fetchAll() as $item) {
            // === 1. خصم من الفرع المرسل (الباتشات) ===
            $usedBatchId = $item['batch_id'];
            $batchInfo = null;
            
            if ($usedBatchId) {
                // باتش محدد — خصم منه مباشرة
                $pdo->prepare("UPDATE inventory_batches SET available_qty = GREATEST(0, available_qty - ?) WHERE id = ? AND tenant_id = ? AND branch_id = ?")
                    ->execute([$item['quantity'], $usedBatchId, $tid, $transfer['from_branch_id']]);
                $bi = $pdo->prepare("SELECT * FROM inventory_batches WHERE tenant_id = ? AND id = ?"); 
                $bi->execute([$tid, $usedBatchId]); 
                $batchInfo = $bi->fetch();
            } else {
                // FEFO تلقائي — خصم من أقدم الباتشات
                $fefo = $pdo->prepare("SELECT id, available_qty, batch_number, purchase_price, selling_price, expiry_date, manufacturing_date FROM inventory_batches WHERE tenant_id = ? AND product_id = ? AND branch_id = ? AND available_qty > 0 ORDER BY expiry_date ASC, id ASC");
                $fefo->execute([$tid, $item['product_id'], $transfer['from_branch_id']]);
                $remaining = $item['quantity'];
                while ($remaining > 0 && ($batch = $fefo->fetch())) {
                    $deduct = min($remaining, $batch['available_qty']);
                    $pdo->prepare("UPDATE inventory_batches SET available_qty = available_qty - ? WHERE id = ? AND tenant_id = ?")
                        ->execute([$deduct, $batch['id'], $tid]);
                    $remaining -= $deduct;
                    if (!$batchInfo) {
                        $batchInfo = $batch; // أول باتش — نستخدم بياناته للفرع المستقبل
                        $usedBatchId = $batch['id'];
                    }
                }
            }
            
            // === 4. البحث عن المنتج في الفرع المستقبل أو إنشاؤه ===
            $srcProduct = $pdo->prepare("SELECT * FROM products WHERE id = ? AND tenant_id = ?");
            $srcProduct->execute([$item['product_id'], $tid]);
            $srcProd = $srcProduct->fetch();
            
            // هل الصنف موجود في الفرع المستقبل؟
            $destProduct = $pdo->prepare("SELECT id FROM products WHERE tenant_id = ? AND branch_id = ? AND barcode = ? AND is_active = 1");
            $destProduct->execute([$tid, $transfer['to_branch_id'], $srcProd['barcode'] ?? '']);
            $destProdId = $destProduct->fetchColumn();
            
            if (!$destProdId && $srcProd) {
                // إنشاء نسخة من المنتج في الفرع المستقبل
                $pdo->prepare("INSERT INTO products (tenant_id, branch_id, barcode, sku, name, name_en, generic_name, category_id, manufacturer, concentration, dosage_form, unit, description, unit_price, cost_price, vat_rate, requires_prescription, is_narcotic, min_stock, max_stock, reorder_point, is_active, stock_qty, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,0,?)")
                    ->execute([$tid, $transfer['to_branch_id'], 
                        $srcProd['barcode'], $srcProd['sku'] ?? '', $srcProd['name'], $srcProd['name_en'] ?? '',
                        $srcProd['generic_name'] ?? '', $srcProd['category_id'], $srcProd['manufacturer'] ?? '',
                        $srcProd['concentration'] ?? '', $srcProd['dosage_form'] ?? '', $srcProd['unit'] ?? t('pieces'),
                        $srcProd['description'] ?? '', $srcProd['unit_price'], $srcProd['cost_price'],
                        $srcProd['vat_rate'] ?? 15, $srcProd['requires_prescription'] ?? 0, $srcProd['is_narcotic'] ?? 0,
                        $srcProd['min_stock'] ?? 10, $srcProd['max_stock'] ?? 1000, $srcProd['reorder_point'] ?? 20,
                        t('inventory.transfer') . ' ' . $transfer['transfer_number']
                    ]);
                $destProdId = $pdo->lastInsertId();
            }
            
            if (!$destProdId) $destProdId = $item['product_id']; // fallback
            
            // === 5. إنشاء باتش في الفرع المستقبل ===
            $pdo->prepare("INSERT INTO inventory_batches (tenant_id, product_id, branch_id, batch_number, quantity, available_qty, purchase_price, selling_price, expiry_date, manufacturing_date, received_date, notes) VALUES (?,?,?,?,?,?,?,?,?,?,CURDATE(),?)")
                ->execute([$tid,
                    $destProdId, $transfer['to_branch_id'],
                    $batchInfo['batch_number'] ?? 'TR-' . $transfer['transfer_number'],
                    $item['quantity'], $item['quantity'],
                    $batchInfo['purchase_price'] ?? $srcProd['cost_price'] ?? 0, 
                    $batchInfo['selling_price'] ?? $srcProd['unit_price'] ?? 0,
                    $batchInfo['expiry_date'] ?? null, $batchInfo['manufacturing_date'] ?? null,
                    t('inventory.transfer') . ' ' . $transfer['transfer_number']
                ]);
            $newBatchId = $pdo->lastInsertId();
            
            // === 6. تحديث stock_qty في منتج الفرع المستقبل ===
            $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ? AND tenant_id = ? AND branch_id = ?")
                ->execute([$item['quantity'], $destProdId, $tid, $transfer['to_branch_id']]);

            // === 7. inventory.movements ===
            $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id, batch_id, branch_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?,?,?,?,'transfer_out',?,'stock_transfer',?,?,?)")
                ->execute([$tid, $item['product_id'], $usedBatchId, $transfer['from_branch_id'], $item['quantity'], $approveId, t('inventory.outbound_transfer') . ' ' . $transfer['transfer_number'], $_SESSION['user_id']]);
            $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id, batch_id, branch_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?,?,?,?,'transfer_in',?,'stock_transfer',?,?,?)")
                ->execute([$tid, $destProdId, $newBatchId, $transfer['to_branch_id'], $item['quantity'], $approveId, t('inventory.inbound_transfer') . ' ' . $transfer['transfer_number'], $_SESSION['user_id']]);
        }

        $pdo->prepare("UPDATE stock_transfers SET status = 'completed', approved_by = ? WHERE id = ? AND tenant_id = ?")->execute([$_SESSION['user_id'], $approveId, $tid]);
        $pdo->commit();
        // مسح كاش المزامنة عشان الكميات تتحدث فوراً
        foreach ($_SESSION as $k => $v) { if (strpos($k, 'stock_synced_') === 0) unset($_SESSION[$k]); }
        logActivity($pdo, 'activity.approve_transfer', $transfer['transfer_number'], 'inventory');
        header("Location: stock_transfer?msg=approved"); exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMsg = $e->getMessage();
    }
    }
}

// إلغاء تحويل
if (isset($_GET['cancel']) && intval($_GET['cancel'])) {
    $cancelId = intval($_GET['cancel']);
    $cancelCheck = $pdo->prepare("SELECT created_by FROM stock_transfers WHERE id=? AND tenant_id=? AND status='pending'");
    $cancelCheck->execute([$cancelId, $tid]);
    $cancelData = $cancelCheck->fetch();
    if ($cancelData && ($_SESSION['role'] === 'admin' || $cancelData['created_by'] == $_SESSION['user_id'])) {
        $pdo->prepare("UPDATE stock_transfers SET status='cancelled' WHERE id=? AND tenant_id=? AND status='pending'")->execute([$cancelId, $tid]);
        header("Location: stock_transfer?msg=cancelled"); exit;
    } else {
        $errorMsg = t('transfer.cannot_cancel');
    }
}

// حذف تحويل (pending أو cancelled فقط)
if (isset($_GET['delete']) && intval($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    $delCheck = $pdo->prepare("SELECT status, created_by FROM stock_transfers WHERE id=? AND tenant_id=?");
    $delCheck->execute([$delId, $tid]);
    $delData = $delCheck->fetch();
    if ($delData && in_array($delData['status'], ['pending','cancelled']) && ($_SESSION['role'] === 'admin' || $delData['created_by'] == $_SESSION['user_id'])) {
        $pdo->prepare("DELETE FROM stock_transfer_items WHERE transfer_id = ?")->execute([$delId]);
        $pdo->prepare("DELETE FROM stock_transfers WHERE id = ? AND tenant_id = ?")->execute([$delId, $tid]);
        logActivity($pdo, 'activity.delete_transfer' . '', $delId, 'inventory');
        header("Location: stock_transfer?msg=deleted"); exit;
    } else {
        $errorMsg = t('transfer.cannot_delete');
    }
}

// ========== MESSAGES ==========
$msg = $_GET['msg'] ?? '';
?>

<?php if ($msg === 'created'): ?>
<div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?= t('saved_success') ?> — <strong><?= htmlspecialchars($_GET['num'] ?? '') ?></strong></div>
<?php elseif ($msg === 'approved'): ?>
<div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?= t('saved_success') ?> — المخزون اتنقل بنجاح</div>
<?php elseif ($msg === 'cancelled'): ?>
<div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?= t('saved_success') ?></div>
<?php elseif ($msg === 'deleted'): ?>
<div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?= t('saved_success') ?> التحويل نهائياً</div>
<?php endif; ?>

<?php if (!empty($errorMsg)): ?>
<div class="alert alert-danger" style="background:#fef2f2;color:#b91c1c;padding:12px 20px;border-radius:8px;margin-bottom:16px;border:1px solid #fecaca;"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<?php
// =====================================================
// VIEW MODE — عرض تفاصيل تحويل (يخفي فورم الإنشاء)
// =====================================================
if ($isViewMode):
    $vid = intval($_GET['view']);
    $vt = $pdo->prepare("SELECT st.*, bf.name as from_name, bt.name as to_name, u.full_name as creator_name
        FROM stock_transfers st 
        JOIN branches bf ON bf.id = st.from_branch_id 
        JOIN branches bt ON bt.id = st.to_branch_id 
        LEFT JOIN users u ON u.id = st.created_by
        WHERE st.tenant_id = $tid AND st.id = ?");
    $vt->execute([$vid]); $vTransfer = $vt->fetch();
    
    if ($vTransfer):
    $vItems = $pdo->prepare("SELECT sti.*, p.name as product_name, p.barcode, p.unit,
        ib.batch_number, ib.expiry_date, ib.purchase_price
        FROM stock_transfer_items sti 
        JOIN products p ON p.id = sti.product_id 
        LEFT JOIN inventory_batches ib ON ib.id = sti.batch_id
        WHERE sti.transfer_id = ?");
    $vItems->execute([$vid]);
    $vItemsList = $vItems->fetchAll();
    $vTotalQty = array_sum(array_column($vItemsList, 'quantity'));
?>

<div style="margin-bottom:12px;">
    <a href="stock_transfer" class="btn btn-sm" style="background:#f3f4f6;color:#374151;text-decoration:none;"><i class="fas fa-arrow-right"></i><?= t('g.back_to_transfers') ?></a>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-eye"></i> <?= t('details') ?>: <?= $vTransfer['transfer_number'] ?></h3>
        <div style="display:flex;gap:6px;">
            <?php if ($vTransfer['status'] === 'pending' && $_SESSION['role'] === 'admin'): ?>
            <a href="?approve=<?= $vid ?>" class="btn btn-sm" style="background:#dcfce7;color:#16a34a;" onclick="return confirm('<?= t('inventory.approve_transfer') ?>')"><i class="fas fa-check"></i><?= t('approved') ?></a>
            <?php endif; ?>
            <?php if ($vTransfer['status'] === 'pending'): ?>
            <a href="?cancel=<?= $vid ?>" class="btn btn-sm" style="background:#fef3c7;color:#92400e;" onclick="return confirm('<?= t('inventory.cancel_transfer') ?>')"><i class="fas fa-ban"></i><?= t('cancel') ?></a>
            <?php endif; ?>
            <?php if (in_array($vTransfer['status'], ['pending','cancelled']) && ($_SESSION['role'] === 'admin' || $vTransfer['created_by'] == $_SESSION['user_id'])): ?>
            <a href="?delete=<?= $vid ?>" class="btn btn-sm" style="background:#fef2f2;color:#dc2626;" onclick="return confirm('<?= t('inventory.delete_transfer') ?>')"><i class="fas fa-trash"></i><?= t('delete') ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px;font-size:13px;">
            <div style="background:#fef2f2;padding:10px 14px;border-radius:8px;border:1px solid #fecaca;">
                <div style="font-size:11px;color:#6b7280;margin-bottom:2px;"><?= t('g.from_branch') ?></div>
                <div style="font-weight:700;color:#b91c1c;"><i class="fas fa-arrow-up"></i> <?= $vTransfer['from_name'] ?></div>
            </div>
            <div style="background:#f0fdf4;padding:10px 14px;border-radius:8px;border:1px solid #bbf7d0;">
                <div style="font-size:11px;color:#6b7280;margin-bottom:2px;"><?= t('g.to_branch') ?></div>
                <div style="font-weight:700;color:#15803d;"><i class="fas fa-arrow-down"></i> <?= $vTransfer['to_name'] ?></div>
            </div>
            <div style="background:#f8f9fa;padding:10px 14px;border-radius:8px;border:1px solid #e5e7eb;">
                <div style="font-size:11px;color:#6b7280;margin-bottom:2px;"><?= t('date') ?></div>
                <div style="font-weight:700;"><?= $vTransfer['transfer_date'] ?></div>
            </div>
            <div style="background:#f8f9fa;padding:10px 14px;border-radius:8px;border:1px solid #e5e7eb;">
                <div style="font-size:11px;color:#6b7280;margin-bottom:2px;"><?= t('g.total_qty') ?></div>
                <div style="font-weight:700;color:#1d4ed8;font-size:18px;"><?= $vTotalQty ?></div>
            </div>
            <div style="background:#f8f9fa;padding:10px 14px;border-radius:8px;border:1px solid #e5e7eb;">
                <div style="font-size:11px;color:#6b7280;margin-bottom:2px;"><?= t('status') ?></div>
                <div><span class="badge <?= $statusBadge[$vTransfer['status']] ?? 'badge-secondary' ?>"><?= $statusLabels[$vTransfer['status']] ?? $vTransfer['status'] ?></span></div>
            </div>
            <div style="background:#f8f9fa;padding:10px 14px;border-radius:8px;border:1px solid #e5e7eb;">
                <div style="font-size:11px;color:#6b7280;margin-bottom:2px;"><?= t('g.by') ?></div>
                <div style="font-weight:600;font-size:12px;"><?= $vTransfer['creator_name'] ?? '—' ?></div>
            </div>
        </div>
        <?php if (!empty($vTransfer['notes'])): ?>
        <div style="background:#fefce8;padding:8px 14px;border-radius:6px;margin-bottom:12px;font-size:12px;border:1px solid #fef08a;">
            <i class="fas fa-sticky-note" style="color:#a16207;"></i> <strong>ملاحظات:</strong> <?= htmlspecialchars($vTransfer['notes']) ?>
        </div>
        <?php endif; ?>
        <div class="table-responsive"><table>
        <thead><tr><th>#</th><th><?= t('products.barcode') ?></th><th><?= t('item') ?></th><th><?= t('products.unit') ?></th><th><?= t('inventory.batch_number') ?></th><th><?= t('inventory.expiry_date') ?></th><th style="text-align:center;"><?= t('g.purchase_price') ?></th><th style="text-align:center;"><?= t('g.transferred_qty') ?></th></tr></thead>
        <tbody>
        <?php foreach ($vItemsList as $idx => $vi): ?>
        <tr>
            <td style="color:#6b7280;"><?= $idx + 1 ?></td>
            <td><code style="font-size:11px;"><?= $vi['barcode'] ?: '—' ?></code></td>
            <td dir="auto"><strong><?= $vi['product_name'] ?></strong></td>
            <td style="color:#6b7280;font-size:12px;"><?= $vi['unit'] ?? t('pieces') ?></td>
            <td style="font-size:12px;"><?= $vi['batch_number'] ?? '—' ?></td>
            <td style="font-size:12px;"><?= $vi['expiry_date'] ?? '—' ?></td>
            <td style="text-align:center;font-size:12px;"><?= $vi['purchase_price'] ? formatMoney($vi['purchase_price']) : '—' ?></td>
            <td style="text-align:center;font-weight:700;font-size:15px;color:#1d4ed8;"><?= $vi['quantity'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr style="background:#1a2744;color:#fff;font-weight:700;">
            <td colspan="7"><?= t('total') ?> (<?= count($vItemsList) ?>)</td>
            <td style="text-align:center;font-size:15px;"><?= $vTotalQty ?></td>
        </tr></tfoot>
        </table></div>
    </div>
</div>

<?php else: ?>
<div class="alert alert-danger" style="background:#fef2f2;color:#b91c1c;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-exclamation-circle"></i> التحويل غير موجود</div>
<a href="stock_transfer" class="btn btn-sm" style="background:#f3f4f6;color:#374151;text-decoration:none;"><i class="fas fa-arrow-right"></i><?= t('back') ?></a>
<?php endif; ?>

<?php
// =====================================================
// NORMAL MODE — فورم الإنشاء + القائمة
// =====================================================
else: ?>

<!-- تحويل جديد -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-exchange-alt"></i><?= t('g.new_transfer') ?></h3>
    </div>
    <div class="card-body">
        <form method="POST" id="transferForm">
            <?= csrfField() ?>
            <input type="hidden" name="create_transfer" value="1">
            <div class="form-row">
                <div class="form-group">
                    <label><?= t('g.from_branch') ?> *</label>
                    <select name="from_branch" id="fromBranch" class="form-control" required>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $b['id'] == $branchId ? 'selected' : '' ?>><?= $b['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><?= t('g.to_branch') ?> *</label>
                    <select name="to_branch" id="toBranch" class="form-control" required>
                        <option value=""><?= t('g.select_branch') ?></option>
                        <?php foreach ($branches as $b): if ($b['id'] != $branchId): ?>
                        <option value="<?= $b['id'] ?>"><?= $b['name'] ?></option>
                        <?php endif; endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><?= t('date') ?></label>
                    <input type="date" name="transfer_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            
            <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-bottom:12px;">
                <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block;"><i class="fas fa-barcode"></i> <?= t('g.search_barcode_name') ?></label>
                <input type="text" id="prodFilter" class="form-control" placeholder="<?= t('pos.scan_or_search') ?>" autocomplete="off" autofocus style="max-width:500px;">
            </div>

            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:16px;">
                <div style="padding:10px 16px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;border-radius:8px 8px 0 0;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-weight:600;font-size:13px;color:#334155;"><i class="fas fa-box"></i> <?= t('products.title') ?> — <span style="background:#dcfce7;color:#166534;padding:1px 8px;border-radius:6px;">+</span></span>
                    <span id="productCount" style="font-size:12px;color:#64748b;"></span>
                </div>
                <div style="max-height:350px;overflow-y:auto;" id="allProductsContainer">
                    <table style="width:100%;">
                        <thead style="position:sticky;top:0;background:#f8fafc;z-index:2;"><tr>
                            <th style="padding:8px 12px;font-size:12px;text-align:right;"><?= t('products.barcode') ?></th>
                            <th style="padding:8px 12px;font-size:12px;text-align:right;"><?= t('products.product_name') ?></th>
                            <th style="padding:8px 12px;font-size:12px;text-align:center;"><?= t('available') ?></th>
                            <th style="padding:8px 12px;font-size:12px;text-align:center;width:60px;"></th>
                        </tr></thead>
                        <tbody id="allProductsBody">
                            <tr><td colspan="4" style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> <?= t('loading') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="background:#f0fdf4;border:2px solid #bbf7d0;border-radius:8px;padding:0;margin-bottom:12px;">
                <div style="padding:10px 16px;background:#dcfce7;border-bottom:1px solid #bbf7d0;border-radius:6px 6px 0 0;">
                    <span style="font-weight:600;font-size:13px;color:#166534;"><i class="fas fa-exchange-alt"></i> <?= t('g.transfer_items') ?> (<span id="selectedCount">0</span>)</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th><?= t('item') ?></th><th><?= t('inventory.batch_number') ?></th><th style="text-align:center;"><?= t('available') ?></th><th style="text-align:center;"><?= t('g.qty_to_transfer') ?></th><th></th></tr></thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                    <div id="emptyMsg" style="text-align:center;padding:20px;color:#94a3b8;font-size:13px;"><?= t('no_results') ?></div>
                </div>
            </div>

            <div class="form-group" style="margin-top:10px;"><label><?= t('notes') ?></label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            <button type="submit" class="btn btn-primary" id="submitTransfer" disabled><i class="fas fa-paper-plane"></i><?= t('g.create_transfer') ?></button>
        </form>
    </div>
</div>

<?php endif; // end view/normal mode ?>

<!-- ========== قائمة التحويلات (دايماً ظاهرة) ========== -->
<?php
$transfers = $pdo->query("SELECT st.*, bf.name as from_name, bt.name as to_name, u.full_name as creator,
    (SELECT COUNT(*) FROM stock_transfer_items WHERE transfer_id = st.id) as item_count,
    (SELECT COALESCE(SUM(quantity),0) FROM stock_transfer_items WHERE transfer_id = st.id) as total_qty
    FROM stock_transfers st 
    JOIN branches bf ON bf.id = st.from_branch_id 
    JOIN branches bt ON bt.id = st.to_branch_id 
    LEFT JOIN users u ON u.id = st.created_by 
    WHERE st.tenant_id = $tid
    ORDER BY st.id DESC LIMIT 50")->fetchAll();
?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-list"></i><?= t('g.stock_transfers') ?></h3></div>
    <div class="card-body">
        <?php if (empty($transfers)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-exchange-alt" style="font-size:40px;display:block;margin-bottom:8px;"></i><?= t('no_results') ?></div>
        <?php else: ?>
        <div class="table-responsive"><table>
        <thead><tr><th><?= t('g.number') ?></th><th><?= t('date') ?></th><th><?= t('from') ?></th><th><?= t('to') ?></th><th style="text-align:center;"><?= t('items') ?></th><th style="text-align:center;"><?= t('g.total_qty') ?></th><th><?= t('status') ?></th><th><?= t('g.by') ?></th><th><?= t('actions') ?></th></tr></thead>
        <tbody>
        <?php foreach ($transfers as $t): ?>
        <tr>
            <td><strong><?= $t['transfer_number'] ?></strong></td>
            <td><?= $t['transfer_date'] ?></td>
            <td><span style="background:#fef2f2;color:#dc2626;padding:2px 8px;border-radius:6px;font-size:11px;"><i class="fas fa-arrow-up"></i> <?= $t['from_name'] ?></span></td>
            <td><span style="background:#f0fdf4;color:#16a34a;padding:2px 8px;border-radius:6px;font-size:11px;"><i class="fas fa-arrow-down"></i> <?= $t['to_name'] ?></span></td>
            <td style="text-align:center;"><?= $t['item_count'] ?></td>
            <td style="text-align:center;font-weight:700;color:#1d4ed8;"><?= $t['total_qty'] ?></td>
            <td><span class="badge <?= $statusBadge[$t['status']] ?? 'badge-secondary' ?>"><?= $statusLabels[$t['status']] ?? $t['status'] ?></span></td>
            <td style="font-size:12px;"><?= $t['creator'] ?></td>
            <td style="white-space:nowrap;">
                <a href="?view=<?= $t['id'] ?>" class="btn btn-sm btn-info" style="font-size:11px;" title="<?= t('details') ?>"><i class="fas fa-eye"></i></a>
                <?php if ($t['status'] === 'pending'): ?>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="?approve=<?= $t['id'] ?>" class="btn btn-sm" style="background:#dcfce7;color:#16a34a;font-size:11px;" onclick="return confirm('<?= t('inventory.approve_transfer') ?>')" title="<?= t('approved') ?>"><i class="fas fa-check"></i></a>
                    <?php endif; ?>
                    <a href="?cancel=<?= $t['id'] ?>" class="btn btn-sm" style="background:#fef3c7;color:#92400e;font-size:11px;" onclick="return confirm('<?= t('inventory.cancel_transfer') ?>')" title="<?= t('cancel') ?>"><i class="fas fa-ban"></i></a>
                <?php endif; ?>
                <?php if (in_array($t['status'], ['pending','cancelled']) && ($_SESSION['role'] === 'admin' || $t['created_by'] == $_SESSION['user_id'])): ?>
                <a href="?delete=<?= $t['id'] ?>" class="btn btn-sm" style="background:#fef2f2;color:#dc2626;font-size:11px;" onclick="return confirm('<?= t('inventory.delete_transfer') ?>')" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
                <?php if ($t['status'] === 'completed'): ?>
                <span style="color:#16a34a;font-size:11px;"><i class="fas fa-check-double"></i></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isViewMode): // JS only needed in form mode ?>
<script>
let rc = 0;
let allProducts = [];
const filterInput = document.getElementById('prodFilter');

function loadAllProducts() {
    const fromB = document.getElementById('fromBranch').value;
    fetch(`stock_transfer.php?api=all_products&from_branch=${fromB}`)
        .then(r => r.json())
        .then(ps => { allProducts = ps; renderProducts(ps); })
        .catch(() => {
            document.getElementById('allProductsBody').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:#ef4444;"><?= t('error') ?></td></tr>';
        });
}

function renderProducts(ps) {
    const body = document.getElementById('allProductsBody');
    document.getElementById('productCount').textContent = ps.length;
    if (!ps.length) {
        body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-box-open" style="font-size:20px;display:block;margin-bottom:6px;"></i><?= t('no_results') ?> متاحة في هذا الفرع</td></tr>';
        return;
    }
    body.innerHTML = ps.map(p => `<tr id="ap_${p.id}" style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:8px 12px;font-size:12px;color:#64748b;direction:ltr;text-align:right;"><code>${p.barcode || '—'}</code></td>
        <td style="padding:8px 12px;font-size:13px;font-weight:600;">${p.name}</td>
        <td style="padding:8px 12px;text-align:center;"><span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700;">${p.available_qty} ${p.unit||''}</span></td>
        <td style="padding:8px 12px;text-align:center;"><button type="button" onclick='addProduct(${JSON.stringify(p).replace(/'/g,"&#39;")})' style="background:#3b82f6;color:#fff;border:none;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:16px;font-weight:700;line-height:1;">+</button></td>
    </tr>`).join('');
}

filterInput?.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    if (!q) { renderProducts(allProducts); return; }
    const filtered = allProducts.filter(p => (p.name && p.name.toLowerCase().includes(q)) || (p.barcode && p.barcode.toLowerCase().includes(q)));
    renderProducts(filtered);
    if (filtered.length === 1 && filtered[0].barcode && filtered[0].barcode.toLowerCase() === q) {
        addProduct(filtered[0]); this.value = ''; renderProducts(allProducts);
    }
});

filterInput?.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
        e.preventDefault();
        const q = filterInput.value.trim().toLowerCase();
        const match = allProducts.find(p => (p.barcode && p.barcode.toLowerCase() === q));
        if (match) { addProduct(match); filterInput.value = ''; renderProducts(allProducts); }
    }
});

document.getElementById('fromBranch')?.addEventListener('change', loadAllProducts);

function addProduct(p) {
    if (document.querySelector(`input[name="product_id[]"][value="${p.id}"]`)) { alert('الصنف ده مضاف بالفعل!'); return; }
    document.getElementById('emptyMsg').style.display = 'none';
    document.getElementById('submitTransfer').disabled = false;
    rc++;
    const tr = document.createElement('tr'); tr.id = 'row_' + rc;
    tr.innerHTML = `<td><strong>${p.name}</strong><br><small style="color:#94a3b8;"><i class="fas fa-barcode"></i> ${p.barcode || '—'}</small><input type="hidden" name="product_id[]" value="${p.id}"></td><td><select name="batch_id[]" class="form-control" style="width:160px;font-size:12px;" id="batch_${rc}"><option value=""><?= t('g.fefo_auto') ?></option></select></td><td style="text-align:center;"><span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-weight:700;">${p.available_qty}</span></td><td style="text-align:center;"><input type="number" name="quantity[]" value="1" min="1" max="${p.available_qty}" class="form-control" style="width:90px;text-align:center;font-weight:700;"></td><td><button type="button" class="btn btn-sm" style="background:#fef2f2;color:#dc2626;" onclick="removeRow(${rc})"><i class="fas fa-trash"></i></button></td>`;
    document.getElementById('itemsBody').appendChild(tr);
    document.getElementById('selectedCount').textContent = document.getElementById('itemsBody').children.length;
    const fromB = document.getElementById('fromBranch').value;
    fetch(`stock_transfer.php?api=batches&product_id=${p.id}&from_branch=${fromB}`)
        .then(r => r.json())
        .then(batches => {
            const sel = document.getElementById('batch_' + rc);
            batches.forEach(b => { sel.innerHTML += `<option value="${b.id}">${b.batch_number || '-'} (${b.available_qty}) ${b.expiry_date ? '— ' + b.expiry_date : ''}</option>`; });
        });
}

function removeRow(id) {
    document.getElementById('row_' + id)?.remove();
    const count = document.getElementById('itemsBody').children.length;
    document.getElementById('selectedCount').textContent = count;
    if (!count) { document.getElementById('emptyMsg').style.display = 'block'; document.getElementById('submitTransfer').disabled = true; }
}

document.addEventListener('DOMContentLoaded', loadAllProducts);
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
