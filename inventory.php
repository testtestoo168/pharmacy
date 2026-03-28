<?php
require_once 'includes/config.php';
$pageTitle = t('inventory.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('inventory_view');

$tab = $_GET['tab'] ?? 'stock';
$branchId = getBranchId();

// تسوية مخزون
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust']) && hasPermission('inventory_adjust')) {
    verifyCsrfToken();
    $batchId = intval($_POST['batch_id']);
    $newQty = intval($_POST['new_qty']);
    $reason = $_POST['reason'] ?? '';
    $batch = $pdo->prepare("SELECT * FROM inventory_batches WHERE tenant_id = $tid AND branch_id = $bid AND id = ?"); $batch->execute([$batchId]); $batch = $batch->fetch();
    if ($batch) {
        $diff = $newQty - $batch['available_qty'];
        $pdo->prepare("UPDATE inventory_batches SET available_qty = ? WHERE id = ? AND tenant_id = $tid")->execute([$newQty, $batchId]);
        $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ? AND tenant_id = $tid AND branch_id = $bid")->execute([$diff, $batch['product_id']]);
        $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id, batch_id, branch_id, movement_type, quantity, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$tid,$batch['product_id'], $batchId, $branchId, 'adjustment', $diff, $reason, $_SESSION['user_id']]);
        logActivity($pdo, 'perms.adjust_inventory', "$batchId | $diff | $reason", 'inventory');
    }
    header("Location: inventory?tab=stock&msg=adjusted"); exit;
}
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?= t('saved_success') ?> بنجاح
</div>
<?php endif; ?>

<!-- التبويبات -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach ([
        'stock' => [t('dash.stock_status'), 'fas fa-boxes'],
        'batches' => [t('inventory.batches'), 'fas fa-layer-group'],
        'expiring' => [t('inventory.near_expiry'), 'fas fa-exclamation-triangle'],
        'expired' => [t('inventory.expired_stock'), 'fas fa-times-circle'],
        'low' => [t('inventory.low_stock'), 'fas fa-arrow-down'],
        'movements' => [t('inventory.movements'), 'fas fa-exchange-alt'],
    ] as $key => $info): ?>
    <a href="inventory?tab=<?= $key ?>" class="btn btn-sm <?= $tab === $key ? 'btn-primary' : '' ?>" style="<?= $tab !== $key ? 'background:#e5e7eb;color:#374151;' : '' ?>">
        <i class="<?= $info[1] ?>"></i> <?= $info[0] ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'stock'): ?>
<!-- حالة المخزون -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-boxes"></i> حالة <?= t('products.current_stock') ?></h3></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('products.barcode') ?></th><th><?= t('products.product_name') ?></th><th><?= t('products.category') ?></th><th><?= t('products.sell_price') ?></th><th><?= t('sales.cost') ?></th><th><?= t('balance') ?></th><th><?= t('products.min_stock') ?></th><th><?= t('status') ?></th></tr></thead>
            <tbody>
            <?php
            $stocks = $pdo->query("SELECT p.*, c.name as cat_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.tenant_id = $tid AND p.branch_id = $bid AND p.is_active = 1 ORDER BY p.name")->fetchAll();
            foreach ($stocks as $s):
                $cls = $s['stock_qty'] <= 0 ? 'badge-danger' : ($s['stock_qty'] <= $s['min_stock'] ? 'badge-warning' : 'badge-success');
                $lbl = $s['stock_qty'] <= 0 ? t('dash.out_of_stock') : ($s['stock_qty'] <= $s['min_stock'] ? t('dash.low_stock') : t('available'));
            ?>
            <tr>
                <td style="font-family:monospace;font-size:12px;"><?= $s['barcode'] ?: '-' ?></td>
                <td dir="auto" style="font-weight:600;"><?= displayName($s, 'name', 'name_en') ?></td>
                <td dir="auto"><?= $s['cat_name'] ?: '-' ?></td>
                <td><?= formatMoney($s['unit_price']) ?></td>
                <td style="color:#6b7280;"><?= formatMoney($s['cost_price']) ?></td>
                <td style="font-weight:700;font-size:16px;"><?= $s['stock_qty'] ?></td>
                <td><?= $s['min_stock'] ?></td>
                <td><span class="badge <?= $cls ?>"><?= $lbl ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'batches'): ?>
<!-- الدفعات -->
<?php $productId = $_GET['product_id'] ?? ''; ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:16px;">
        <form method="GET" style="display:flex;gap:12px;align-items:center;">
            <input type="hidden" name="tab" value="batches">
            <select name="product_id" class="form-control" style="max-width:400px;">
                <option value=""><?= t('g.select_medicine') ?></option>
                <?php foreach ($pdo->query("SELECT id, name, name_en, barcode FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active=1 ORDER BY name")->fetchAll() as $pr): ?>
                <option value="<?= $pr['id'] ?>" <?= $productId == $pr['id'] ? 'selected' : '' ?>><?= displayName($pr, 'name', 'name_en') ?> <?= $pr['barcode'] ? "({$pr['barcode']})" : '' ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i><?= t('view') ?></button>
        </form>
    </div>
</div>

<?php if ($productId):
$batches = $pdo->prepare("SELECT ib.*, p.name as product_name FROM inventory_batches ib JOIN products p ON p.id = ib.product_id WHERE ib.tenant_id = $tid AND ib.branch_id = $bid AND ib.product_id = ? AND ib.available_qty > 0 ORDER BY ib.expiry_date ASC");
$batches->execute([$productId]); $batches = $batches->fetchAll();
?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-layer-group"></i><?= t('g.medicine_batches') ?></h3><small><?= count($batches) ?><?= t('batch') ?></small></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('inventory.batch_number') ?></th><th><?= t('inventory.available_qty') ?></th><th><?= t('g.purchase_price') ?></th><th><?= t('inventory.manufacturing_date') ?></th><th><?= t('inventory.expiry_date') ?></th><th><?= t('status') ?></th><th><?= t('inventory.adjustment') ?></th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b):
                $daysLeft = $b['expiry_date'] ? (int)((strtotime($b['expiry_date']) - time()) / 86400) : 999;
                $expCls = $daysLeft < 0 ? 'badge-danger' : ($daysLeft <= 90 ? 'badge-warning' : 'badge-success');
                $expLbl = $daysLeft < 0 ? t('dash.expired') : ($daysLeft <= 90 ? "$daysLeft " . t('day') : t('active'));
            ?>
            <tr>
                <td style="font-weight:600;"><?= $b['batch_number'] ?: '-' ?></td>
                <td style="font-weight:700;font-size:16px;"><?= $b['available_qty'] ?></td>
                <td><?= formatMoney($b['purchase_price']) ?></td>
                <td><?= $b['manufacturing_date'] ?: '-' ?></td>
                <td><?= $b['expiry_date'] ?: '-' ?></td>
                <td><span class="badge <?= $expCls ?>"><?= $expLbl ?></span></td>
                <td>
                    <?php if (hasPermission('inventory_adjust')): ?>
                    <form method="POST" style="display:flex;gap:6px;align-items:center;" onsubmit="return confirm('<?= t("inventory.confirm_adjustment") ?>')">
                    <?= csrfField() ?>
                        <input type="hidden" name="adjust" value="1">
                        <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                        <input type="number" name="new_qty" value="<?= $b['available_qty'] ?>" class="form-control" style="width:80px;" min="0">
                        <input type="text" name="reason" placeholder="<?= t('g.reason') ?>" class="form-control" style="width:120px;">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'expiring'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i> أدوية <?= t('inventory.near_expiry') ?> (90 يوم)</h3></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('products.product_name') ?></th><th><?= t('inventory.batch_number') ?></th><th><?= t('quantity') ?></th><th><?= t('inventory.expiry_date') ?></th><th><?= t('inventory.remaining_days') ?></th></tr></thead>
            <tbody>
            <?php
            $exp = $pdo->query("SELECT ib.*, p.name as product_name, p.name_en as product_name_en, p.barcode, DATEDIFF(ib.expiry_date, CURDATE()) as days_left FROM inventory_batches ib JOIN products p ON p.id = ib.product_id WHERE ib.tenant_id = $tid AND ib.branch_id = $bid AND ib.available_qty > 0 AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY ib.expiry_date ASC")->fetchAll();
            if (empty($exp)): ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding:40px;"><?= t('no_results') ?> <?= t('inventory.near_expiry') ?></td></tr>
            <?php else: foreach ($exp as $e): ?>
            <tr>
                <td dir="auto" style="font-weight:600;"><?= displayName($e, 'product_name', 'product_name_en') ?></td>
                <td><?= $e['batch_number'] ?: '-' ?></td>
                <td style="font-weight:700;"><?= $e['available_qty'] ?></td>
                <td style="color:#dc2626;"><?= $e['expiry_date'] ?></td>
                <td><span class="badge <?= $e['days_left'] <= 30 ? 'badge-danger' : 'badge-warning' ?>"><?= $e['days_left'] ?><?= t('day') ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'expired'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-times-circle" style="color:#dc2626;"></i> <?= t('g.expired_medicines') ?></h3></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('products.product_name') ?></th><th><?= t('inventory.batch_number') ?></th><th><?= t('quantity') ?></th><th><?= t('inventory.expiry_date') ?></th><th><?= t('g.expired_since') ?></th></tr></thead>
            <tbody>
            <?php
            $expired = $pdo->query("SELECT ib.*, p.name as product_name, p.name_en as product_name_en, DATEDIFF(CURDATE(), ib.expiry_date) as days_expired FROM inventory_batches ib JOIN products p ON p.id = ib.product_id WHERE ib.tenant_id = $tid AND ib.branch_id = $bid AND ib.available_qty > 0 AND ib.expiry_date < CURDATE() ORDER BY ib.expiry_date ASC")->fetchAll();
            if (empty($expired)): ?>
            <tr><td colspan="5" class="text-center text-muted" style="padding:40px;"><?= t('no_results') ?> منتهية</td></tr>
            <?php else: foreach ($expired as $e): ?>
            <tr style="background:#fef2f2;">
                <td dir="auto" style="font-weight:600;"><?= displayName($e, 'product_name', 'product_name_en') ?></td>
                <td><?= $e['batch_number'] ?: '-' ?></td>
                <td style="font-weight:700;"><?= $e['available_qty'] ?></td>
                <td style="color:#dc2626;"><?= $e['expiry_date'] ?></td>
                <td><span class="badge badge-danger"><?= $e['days_expired'] ?><?= t('day') ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'low'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-arrow-down" style="color:#f59e0b;"></i> <?= t('g.reorder_needed') ?></h3></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('products.product_name') ?></th><th><?= t('products.barcode') ?></th><th><?= t('products.current_stock') ?></th><th><?= t('products.min_stock') ?></th><th><?= t('products.reorder_point') ?></th><th><?= t('g.shortage') ?></th></tr></thead>
            <tbody>
            <?php
            $low = $pdo->query("SELECT * FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active = 1 AND stock_qty <= reorder_point ORDER BY stock_qty ASC")->fetchAll();
            if (empty($low)): ?>
            <tr><td colspan="6" class="text-center text-muted" style="padding:40px;"><?= t('no_results') ?> تحتاج إعادة طلب</td></tr>
            <?php else: foreach ($low as $l): ?>
            <tr style="<?= $l['stock_qty'] <= 0 ? 'background:#fef2f2;' : '' ?>">
                <td dir="auto" style="font-weight:600;"><?= displayName($l, 'name', 'name_en') ?></td>
                <td style="font-family:monospace;"><?= $l['barcode'] ?: '-' ?></td>
                <td style="font-weight:700;color:<?= $l['stock_qty'] <= 0 ? '#dc2626' : '#f59e0b' ?>;"><?= $l['stock_qty'] ?></td>
                <td><?= $l['min_stock'] ?></td>
                <td><?= $l['reorder_point'] ?></td>
                <td><span class="badge badge-danger"><?= max(0, $l['reorder_point'] - $l['stock_qty']) ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'movements'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-exchange-alt"></i> <?= t('inventory.movements') ?></h3></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('date') ?></th><th><?= t('products.product_name') ?></th><th><?= t('type') ?></th><th><?= t('quantity') ?></th><th><?= t('notes') ?></th><th><?= t('users.user') ?></th></tr></thead>
            <tbody>
            <?php
            $typeLabels = ['sale'=>t('tx.sale'),'purchase'=>t('tx.purchase'),'return_sale'=>'مرتجع مبيعات','return_purchase'=>'مرتجع مشتريات','adjustment'=>t('tx.adjustment'),'transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر','damage'=>t('tx.damage')];
            $typeColors = ['sale'=>'badge-primary','purchase'=>'badge-success','return_sale'=>'badge-warning','return_purchase'=>'badge-info','adjustment'=>'badge-secondary','damage'=>'badge-danger'];
            $moves = $pdo->query("SELECT im.*, p.name as product_name, p.name_en as product_name_en, u.full_name as user_name FROM inventory_movements im JOIN products p ON p.id = im.product_id LEFT JOIN users u ON u.id = im.created_by WHERE im.tenant_id = $tid AND im.branch_id = $bid ORDER BY im.created_at DESC LIMIT 100")->fetchAll();
            foreach ($moves as $m): ?>
            <tr>
                <td style="font-size:12px;"><?= date('Y-m-d H:i', strtotime($m['created_at'])) ?></td>
                <td dir="auto" style="font-weight:600;"><?= displayName($m, 'product_name', 'product_name_en') ?></td>
                <td><span class="badge <?= $typeColors[$m['movement_type']] ?? 'badge-secondary' ?>"><?= $typeLabels[$m['movement_type']] ?? $m['movement_type'] ?></span></td>
                <td style="font-weight:700;color:<?= $m['quantity'] > 0 ? '#16a34a' : '#dc2626' ?>;"><?= $m['quantity'] > 0 ? '+'.$m['quantity'] : $m['quantity'] ?></td>
                <td style="font-size:13px;color:#6b7280;"><?= $m['notes'] ?: '-' ?></td>
                <td dir="auto"><?= $m['user_name'] ?? '-' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
