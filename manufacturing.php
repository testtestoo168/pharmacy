<?php
require_once 'includes/config.php';
$pageTitle = t('manufacturing.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('inventory_view');

// إضافة أمر تصنيع
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    try {
        verifyCsrfToken();
        $orderNum = generateNumber($pdo, 'manufacturing_orders', 'order_number', 'MFG-');
        $productName = trim($_POST['product_name']);
        $productNameEn = trim($_POST['product_name_en'] ?? '');
        $qty = intval($_POST['quantity']);
        $sellingPrice = floatval($_POST['selling_price']);
        $notes = trim($_POST['notes'] ?? '');
        
        // حساب تكلفة المواد الخام
        $materials = json_decode($_POST['materials_json'], true);
        if (!$materials || empty($materials)) throw new Exception(t('validation.add_raw_materials'));
        
        $totalCost = 0;
        foreach ($materials as $mat) {
            $totalCost += floatval($mat['qty']) * floatval($mat['cost']);
        }
        
        // إنشاء أمر التصنيع
        $pdo->prepare("INSERT INTO manufacturing_orders (tenant_id, branch_id, order_number, product_name, product_name_en, quantity, unit_cost, total_cost, selling_price, status, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$tid, $bid, $orderNum, $productName, $productNameEn, $qty, $totalCost/$qty, $totalCost, $sellingPrice, 'draft', $notes, $_SESSION['user_id']]);
        $orderId = $pdo->lastInsertId();
        
        // حفظ المواد الخام
        foreach ($materials as $mat) {
            $pdo->prepare("INSERT INTO manufacturing_materials (order_id, product_id, product_name, quantity_used, unit_cost, total_cost) VALUES (?,?,?,?,?,?)")
                ->execute([$orderId, intval($mat['product_id']), $mat['name'], floatval($mat['qty']), floatval($mat['cost']), floatval($mat['qty']) * floatval($mat['cost'])]);
        }
        
        logActivity($pdo, 'activity.manufacturing_order', "$orderNum — $productName", 'manufacturing');
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . ' — ' . $orderNum . '</div>';
    } catch (Exception $e) {
        echo '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// تنفيذ أمر تصنيع (خصم المواد من المخزون + إضافة المنتج)
if (isset($_GET['execute']) && hasPermission('inventory_adjust')) {
    $oid = intval($_GET['execute']);
    try {
        $order = $pdo->prepare("SELECT * FROM manufacturing_orders WHERE id=? AND tenant_id=? AND branch_id=? AND status='draft'");
        $order->execute([$oid, $tid, $bid]);
        $order = $order->fetch();
        if (!$order) throw new Exception(t('g.not_found'));
        
        $mats = $pdo->prepare("SELECT * FROM manufacturing_materials WHERE order_id=?");
        $mats->execute([$oid]);
        $matsList = $mats->fetchAll();
        
        $pdo->beginTransaction();
        
        // خصم المواد الخام من المخزون
        foreach ($matsList as $mat) {
            if ($mat['product_id'] > 0) {
                // تحقق من توفر الكمية
                $avail = $pdo->prepare("SELECT COALESCE(SUM(available_qty),0) FROM inventory_batches WHERE tenant_id=? AND product_id=? AND branch_id=? AND available_qty>0");
                $avail->execute([$tid, $mat['product_id'], $bid]);
                $availQty = floatval($avail->fetchColumn());
                
                if ($availQty < $mat['quantity_used']) {
                    throw new Exception($mat['product_name'] . ': ' . t('inventory.insufficient_qty') . ' (' . $availQty . ')');
                }
                
                // خصم من المخزون
                deductStock($pdo, $mat['product_id'], $mat['quantity_used'], $bid);
                
                // تسجيل حركة مخزون
                $pdo->prepare("INSERT INTO inventory_movements (tenant_id, product_id, branch_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?,?,?,'manufacturing_out',?,'manufacturing',?,?,?)")
                    ->execute([$tid, $mat['product_id'], $bid, $mat['quantity_used'], $oid, t('manufacturing.title') . ': ' . $order['product_name'], $_SESSION['user_id']]);
            }
        }
        
        // إضافة المنتج المصنّع للمخزون (كمنتج جديد أو دفعة جديدة)
        $existingProduct = $pdo->prepare("SELECT id FROM products WHERE tenant_id=? AND branch_id=? AND name=? AND is_active=1");
        $existingProduct->execute([$tid, $bid, $order['product_name']]);
        $prodId = $existingProduct->fetchColumn();
        
        if (!$prodId) {
            // إنشاء منتج جديد
            $pdo->prepare("INSERT INTO products (tenant_id, branch_id, name, name_en, unit_price, cost_price, stock_qty, is_active) VALUES (?,?,?,?,?,?,?,1)")
                ->execute([$tid, $bid, $order['product_name'], $order['product_name_en'] ?? '', $order['selling_price'], $order['unit_cost'], $order['quantity']]);
            $prodId = $pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id=? AND tenant_id=? AND branch_id=?")
                ->execute([$order['quantity'], $prodId, $tid, $bid]);
        }
        
        // إنشاء دفعة مخزون
        $pdo->prepare("INSERT INTO inventory_batches (tenant_id, product_id, branch_id, batch_number, quantity, available_qty, purchase_price, received_date) VALUES (?,?,?,?,?,?,?,CURDATE())")
            ->execute([$tid, $prodId, $bid, 'MFG-' . $order['order_number'], $order['quantity'], $order['quantity'], $order['unit_cost']]);
        $batchId = $pdo->lastInsertId();
        
        // حركة مخزون للمنتج المصنّع
        $pdo->prepare("INSERT INTO inventory_movements (tenant_id, product_id, batch_id, branch_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?,?,?,?,'manufacturing_in',?,'manufacturing',?,?,?)")
            ->execute([$tid, $prodId, $batchId, $bid, $order['quantity'], $oid, t('manufacturing.production') . ': ' . $order['product_name'], $_SESSION['user_id']]);
        
        // تحديث حالة الأمر
        $pdo->prepare("UPDATE manufacturing_orders SET status='completed', completed_at=NOW() WHERE id=? AND tenant_id=?")->execute([$oid, $tid]);
        
        $pdo->commit();
        logActivity($pdo, 'activity.execute_manufacturing', $order['order_number'], 'manufacturing');
        header("Location: manufacturing?msg=executed"); exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// إلغاء أمر
if (isset($_GET['cancel'])) {
    $cid = intval($_GET['cancel']);
    $pdo->prepare("UPDATE manufacturing_orders SET status='cancelled' WHERE id=? AND tenant_id=? AND status='draft'")->execute([$cid, $tid]);
    header("Location: manufacturing?msg=cancelled"); exit;
}

// جلب الأوامر
$orders = $pdo->prepare("SELECT mo.*, u.full_name as creator_name FROM manufacturing_orders mo LEFT JOIN users u ON u.id = mo.created_by WHERE mo.tenant_id=? AND mo.branch_id=? ORDER BY mo.id DESC LIMIT 50");
$orders->execute([$tid, $bid]);
$ordersList = $orders->fetchAll();

// جلب المنتجات للقائمة المنسدلة
$rawMaterials = $pdo->prepare("SELECT p.id, p.name, p.name_en, p.cost_price, COALESCE((SELECT SUM(ib.available_qty) FROM inventory_batches ib WHERE ib.product_id=p.id AND ib.branch_id=?),0) as stock FROM products p WHERE p.tenant_id=? AND p.branch_id=? AND p.is_active=1 ORDER BY p.name");
$rawMaterials->execute([$bid, $tid, $bid]);
$materialsList = $rawMaterials->fetchAll();

$statusLabels = ['draft'=>[t('draft'),'#f59e0b'], 'completed'=>[t('executed'),'#16a34a'], 'cancelled'=>[t('cancelled'),'#dc2626']];
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['msg']??'') === 'executed' ? t('saved_success') . '' : t('saved_success') ?>
</div>
<?php endif; ?>

<!-- إنشاء أمر تصنيع -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-header"><h3><i class="fas fa-flask"></i><?= t('manufacturing.create_order') ?></h3></div>
    <div class="card-body" style="padding:20px;">
        <form method="POST" id="mfgForm">
            <?= csrfField() ?>
            <input type="hidden" name="create_order" value="1">
            <input type="hidden" name="materials_json" id="materialsJson" value="[]">
            
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-bottom:16px;">
                <div class="form-group"><label><?= t('g.manufactured_product') ?> (<?= t('g.arabic') ?>) *</label><input type="text" name="product_name" class="form-control" required placeholder="<?= currentLang() === 'ar' ? 'مثال: كريم ترطيب 100مل' : 'e.g. Moisturizing Cream 100ml' ?>"></div>
                <div class="form-group"><label><?= t('g.manufactured_product') ?> (<?= t('g.english') ?>) *</label><input type="text" name="product_name_en" class="form-control" required placeholder="e.g. Moisturizing Cream 100ml"></div>
                <div class="form-group"><label><?= t('g.produced_qty') ?> *</label><input type="number" name="quantity" class="form-control" required min="1" value="1"></div>
                <div class="form-group"><label><?= t('products.sell_price') ?> *</label><input type="number" step="0.01" name="selling_price" class="form-control" required min="0"></div>
            </div>
            
            <h4 style="font-size:14px;font-weight:600;margin-bottom:10px;color:var(--primary);"><i class="fas fa-boxes"></i><?= t('g.raw_materials') ?></h4>
            <div style="display:flex;gap:8px;margin-bottom:10px;">
                <select id="matProduct" class="form-control" style="flex:2;">
                    <option value=""><?= t('g.select_material') ?></option>
                    <?php foreach ($materialsList as $m): $mName = displayName($m, 'name', 'name_en'); ?>
                    <option value="<?= $m['id'] ?>" data-name="<?= htmlspecialchars($mName) ?>" data-cost="<?= $m['cost_price'] ?>" data-stock="<?= $m['stock'] ?>"><?= htmlspecialchars($mName) ?> (<?= t('available') ?>: <?= $m['stock'] ?> — <?= t('products.cost_price') ?>: <?= formatMoney($m['cost_price']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <input type="number" id="matQty" class="form-control" placeholder="<?= t('quantity') ?>" min="0.01" step="0.01" style="flex:0.5;">
                <button type="button" class="btn btn-primary btn-sm" onclick="addMaterial()"><i class="fas fa-plus"></i><?= t('add') ?></button>
            </div>
            
            <table id="matTable" style="width:100%;margin-bottom:12px;font-size:13px;">
                <thead><tr style="background:var(--secondary);"><th><?= t('g.raw_materials') ?></th><th><?= t('quantity') ?></th><th><?= t('g.unit_price') ?></th><th><?= t('total') ?></th><th></th></tr></thead>
                <tbody id="matBody"></tbody>
                <tfoot><tr style="font-weight:700;"><td colspan="3"><?= t('g.total_material_cost') ?></td><td id="matTotal">0.00</td><td></td></tr></tfoot>
            </table>
            
            <div class="form-group" style="margin-bottom:14px;"><label><?= t('notes') ?></label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-industry"></i><?= t('g.create_mfg') ?></button>
        </form>
    </div>
</div>

<!-- قائمة أوامر التصنيع -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-list"></i> <?= t('manufacturing.title') ?> (<?= count($ordersList) ?>)</h3></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('g.number') ?></th><th><?= t('g.product') ?></th><th><?= t('quantity') ?></th><th><?= t('g.material_cost') ?></th><th><?= t('products.sell_price') ?></th><th>الربح المتوقع</th><th><?= t('status') ?></th><th><?= t('date') ?></th><th><?= t('actions') ?></th></tr></thead>
            <tbody>
            <?php if (empty($ordersList)): ?>
            <tr><td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;"><?= t('no_results') ?></td></tr>
            <?php else: foreach ($ordersList as $o): 
                $profit = ($o['selling_price'] * $o['quantity']) - $o['total_cost'];
                $sl = $statusLabels[$o['status']] ?? [t('unknown'),'#666'];
            ?>
            <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($o['order_number']) ?></td>
                <td dir="auto"><?= htmlspecialchars(displayName($o, 'product_name', 'product_name_en')) ?></td>
                <td><?= $o['quantity'] ?></td>
                <td><?= formatMoney($o['total_cost']) ?></td>
                <td style="color:#1d4ed8;font-weight:600;"><?= formatMoney($o['selling_price']) ?></td>
                <td style="color:<?= $profit >= 0 ? '#16a34a' : '#dc2626' ?>;font-weight:600;"><?= formatMoney($profit) ?></td>
                <td><span style="background:<?= $sl[1] ?>20;color:<?= $sl[1] ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;"><?= $sl[0] ?></span></td>
                <td style="font-size:12px;color:#64748b;"><?= date('Y-m-d', strtotime($o['created_at'])) ?></td>
                <td>
                    <?php if ($o['status'] === 'draft'): ?>
                    <a href="?execute=<?= $o['id'] ?>" style="background:#f0fdf4;color:#16a34a;padding:4px 10px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:600;" onclick="return confirm('<?= t('manufacturing.execute_order') ?>')"><i class="fas fa-play"></i><?= t('executed') ?></a>
                    <a href="?cancel=<?= $o['id'] ?>" style="color:#dc2626;font-size:12px;text-decoration:none;margin-right:8px;" onclick="return confirm('<?= t('manufacturing.cancel_order') ?>')"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var materials = [];
function addMaterial() {
    var sel = document.getElementById('matProduct');
    var opt = sel.options[sel.selectedIndex];
    var qty = parseFloat(document.getElementById('matQty').value);
    if (!opt.value || !qty || qty <= 0) { alert('اختر مادة وحدد الكمية'); return; }
    var stock = parseFloat(opt.dataset.stock);
    if (qty > stock) { alert('الكمية المطلوبة (' + qty + ') أكبر من المتاح (' + stock + ')'); return; }
    materials.push({product_id: opt.value, name: opt.dataset.name, qty: qty, cost: parseFloat(opt.dataset.cost)});
    renderMaterials();
    sel.selectedIndex = 0;
    document.getElementById('matQty').value = '';
}
function removeMaterial(i) { materials.splice(i, 1); renderMaterials(); }
function renderMaterials() {
    var html = '', total = 0;
    materials.forEach(function(m, i) {
        var lineTotal = m.qty * m.cost;
        total += lineTotal;
        html += '<tr><td>' + m.name + '</td><td>' + m.qty + '</td><td>' + m.cost.toFixed(2) + '</td><td>' + lineTotal.toFixed(2) + '</td><td><a href="javascript:void(0)" onclick="removeMaterial(' + i + ')" style="color:#dc2626;"><i class="fas fa-trash"></i></a></td></tr>';
    });
    document.getElementById('matBody').innerHTML = html;
    document.getElementById('matTotal').textContent = total.toFixed(2);
    document.getElementById('materialsJson').value = JSON.stringify(materials);
}
</script>

<?php require_once 'includes/footer.php'; ?>
