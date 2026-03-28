<?php
require_once 'includes/config.php';
$pageTitle = t('purchases.purchase_orders');
require_once 'includes/config.php';
requireLogin();
$tid = getTenantId();
$bid = getBranchId();

// === AJAX: بحث المنتجات ===
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_products') {
    header('Content-Type: application/json; charset=utf-8');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $pdo->prepare("SELECT id, barcode, name, unit, cost_price, unit_price, vat_rate, stock_qty, min_stock, reorder_point
                           FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active = 1 AND (name LIKE ? OR barcode LIKE ?) ORDER BY name LIMIT 20");
    $stmt->execute([$q, $q]);
    echo json_encode($stmt->fetchAll());
    exit;
}

require_once 'includes/header.php';
requirePermission('purchases_view');

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE tenant_id = $tid AND is_active = 1 ORDER BY name")->fetchAll();

// === حفظ أمر شراء ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_po'])) {
    try { verifyCsrfToken();
        $pdo->beginTransaction();
        
        $orderNumber = generateNumber($pdo, 'purchase_orders', 'order_number', 'PO-');
        $supplierId = intval($_POST['supplier_id']);
        $branchId = getBranchId();
        $orderDate = $_POST['order_date'] ?? date('Y-m-d');
        $expectedDate = $_POST['expected_date'] ?: null;
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$supplierId) throw new Exception(t('validation.select_supplier'));
        
        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $prices = $_POST['unit_price'] ?? [];
        
        $subtotal = 0;
        $items = [];
        
        for ($i = 0; $i < count($productIds); $i++) {
            $pid = intval($productIds[$i] ?? 0);
            if (!$pid) continue;
            $qty = intval($quantities[$i] ?? 0);
            if ($qty <= 0) continue;
            $price = floatval($prices[$i] ?? 0);
            $total = $qty * $price;
            $subtotal += $total;
            $items[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price, 'total' => $total];
        }
        
        if (empty($items)) throw new Exception(t('validation.add_one_product'));
        
        $vatAmount = $subtotal * 0.15;
        $grandTotal = $subtotal + $vatAmount;
        
        $stmt = $pdo->prepare("INSERT INTO purchase_orders (tenant_id,order_number, supplier_id, branch_id, order_date, expected_date, status, subtotal, vat_amount, grand_total, notes, created_by) VALUES (?,?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)");
        $stmt->execute([$tid,$orderNumber, $supplierId, $branchId, $orderDate, $expectedDate, $subtotal, $vatAmount, $grandTotal, $notes, $_SESSION['user_id']]);
        $poId = $pdo->lastInsertId();
        
        $stmtItem = $pdo->prepare("INSERT INTO purchase_order_items (order_id, product_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)");
        foreach ($items as $item) {
            $stmtItem->execute([$poId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total']]);
        }
        
        logActivity($pdo, 'po.saved', $orderNumber, 'purchases');
        $pdo->commit();
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . ' - رقم: <strong>' . $orderNumber . '</strong></div>';
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
    }
}

// === تغيير حالة أمر شراء ===
if (isset($_GET['action'])) {
    $poId = intval($_GET['id'] ?? 0);
    if ($_GET['action'] === 'send' && $poId) {
        $pdo->prepare("UPDATE purchase_orders SET status = 'sent' WHERE id = ? AND tenant_id = $tid AND status = 'draft'")->execute([$poId]);
        echo '<div class="alert alert-success"><i class="fas fa-check"></i> ' . t('saved_success') . '</div>';
    } elseif ($_GET['action'] === 'cancel' && $poId) {
        $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled' WHERE id = ? AND tenant_id = $tid")->execute([$poId]);
        echo '<div class="alert alert-success"><i class="fas fa-check"></i> ' . t('saved_success') . '</div>';
    } elseif ($_GET['action'] === 'delete' && $poId) {
        $pdo->prepare("DELETE FROM purchase_order_items WHERE order_id = ?")->execute([$poId]);
        $pdo->prepare("DELETE FROM purchase_orders WHERE tenant_id = $tid AND id = ? AND status = 'draft'")->execute([$poId]);
        echo '<div class="alert alert-success"><i class="fas fa-check"></i> ' . t('saved_success') . '</div>';
    }
}

// === المنتجات التي تحتاج إعادة طلب ===
$lowStock = $pdo->query("SELECT id, name, barcode, stock_qty, min_stock, reorder_point, cost_price 
                         FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active = 1 AND stock_qty <= reorder_point ORDER BY stock_qty ASC LIMIT 20")->fetchAll();

// أوامر الشراء
$orders = $pdo->query("SELECT po.*, s.name as supplier_name, 
                       (SELECT COUNT(*) FROM purchase_order_items WHERE order_id = po.id) as items_count
                       FROM purchase_orders po 
                       LEFT JOIN suppliers s ON po.supplier_id = s.id 
                       ORDER BY po.id DESC")->fetchAll();
?>

<!-- تنبيه المنتجات الناقصة -->
<?php if (!empty($lowStock)): ?>
<div class="card" style="border-right:4px solid #f59e0b;">
    <div class="card-header">
        <h3><i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i> <?= t('g.reorder_needed') ?> (<?= count($lowStock) ?>)</h3>
        <button type="button" class="btn btn-sm btn-warning" onclick="document.getElementById('lowStockSection').style.display = document.getElementById('lowStockSection').style.display === 'none' ? 'block' : 'none'">
            <i class="fas fa-eye"></i> <?= t('g.toggle') ?>
        </button>
    </div>
    <div id="lowStockSection" style="display:none;">
        <div class="card-body">
            <div class="table-responsive">
                <table>
                    <thead><tr><th><?= t('g.product') ?></th><th><?= t('products.barcode') ?></th><th><?= t('products.current_stock') ?></th><th><?= t('products.min_stock') ?></th><th><?= t('products.reorder_point') ?></th><th><?= t('g.suggested_qty') ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($lowStock as $ls): ?>
                        <tr style="background:<?= $ls['stock_qty'] <= $ls['min_stock'] ? '#fef2f2' : '#fffbeb' ?>;">
                            <td dir="auto"><strong><?= $ls['name'] ?></strong></td>
                            <td><?= $ls['barcode'] ?: '-' ?></td>
                            <td><strong style="color:<?= $ls['stock_qty'] <= $ls['min_stock'] ? '#dc2626' : '#f59e0b' ?>;"><?= $ls['stock_qty'] ?></strong></td>
                            <td><?= $ls['min_stock'] ?></td>
                            <td><?= $ls['reorder_point'] ?></td>
                            <td><strong><?= max(0, $ls['reorder_point'] * 2 - $ls['stock_qty']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- إنشاء أمر شراء جديد -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-list"></i><?= t('g.new_purchase_order') ?></h3>
        <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('newPOForm').style.display = document.getElementById('newPOForm').style.display === 'none' ? 'block' : 'none'">
            <i class="fas fa-plus"></i> <?= t('po.new') ?>
        </button>
    </div>
    <div id="newPOForm" style="display:none;">
        <div class="card-body">
            <form method="POST">
            <?= csrfField() ?>
                <input type="hidden" name="save_po" value="1">
                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label><?= t('purchases.supplier') ?> *</label>
                        <select name="supplier_id" class="form-control" required>
                            <option value=""><?= t('g.select_supplier') ?></option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= $s['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?= t('g.invoice_date') ?></label>
                        <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?= t('g.expected') ?></label>
                        <input type="date" name="expected_date" class="form-control">
                    </div>
                </div>

                <!-- بحث وإضافة منتجات -->
                <div style="position:relative;margin-bottom:15px;">
                    <input type="text" id="poProductSearch" class="form-control" placeholder="<?= t('search_placeholder') ?>" autocomplete="off" onkeyup="searchPOProducts(this.value)">
                    <div id="poSearchResults" style="display:none;position:absolute;top:100%;right:0;left:0;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:100;max-height:250px;overflow-y:auto;"></div>
                </div>

                <table id="poItemsTable">
                    <thead><tr><th><?= t('g.product') ?></th><th><?= t('quantity') ?></th><th><?= t('price') ?></th><th><?= t('total') ?></th><th></th></tr></thead>
                    <tbody id="poItemsBody"></tbody>
                </table>

                <div class="form-group" style="margin-top:10px;">
                    <label><?= t('notes') ?></label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top:10px;"><i class="fas fa-save"></i><?= t('g.save_po') ?></button>
            </form>
        </div>
    </div>
</div>

<!-- قائمة أوامر الشراء -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i><?= t('purchases.purchase_orders') ?></h3>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th><?= t('g.number') ?></th><th><?= t('purchases.supplier') ?></th><th><?= t('date') ?></th><th><?= t('g.invoice_items') ?></th><th><?= t('total') ?></th><th><?= t('status') ?></th><th><?= t('actions') ?></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:20px;"><?= t('no_results') ?></td></tr>
                    <?php else: foreach ($orders as $o): ?>
                    <tr>
                        <td><strong><?= $o['order_number'] ?></strong></td>
                        <td dir="auto"><?= $o['supplier_name'] ?? '-' ?></td>
                        <td><?= $o['order_date'] ?></td>
                        <td><span class="badge badge-info"><?= $o['items_count'] ?></span></td>
                        <td><strong><?= formatMoney($o['grand_total']) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></strong></td>
                        <td>
                            <?php
                            $statusMap = [
                                'draft' => [t('draft'), 'badge-secondary'],
                                'sent' => [t('inventory.sent'), 'badge-primary'],
                                'partial' => [t('inventory.partial_receive'), 'badge-warning'],
                                'received' => [t('inventory.received'), 'badge-success'],
                                'cancelled' => [t('cancelled'), 'badge-danger'],
                            ];
                            $st = $statusMap[$o['status']] ?? [t('unknown'), 'badge-secondary'];
                            ?>
                            <span class="badge <?= $st[1] ?>"><?= $st[0] ?></span>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($o['status'] === 'draft'): ?>
                            <a href="?action=send&id=<?= $o['id'] ?>" class="btn btn-sm btn-primary" title="<?= t('g.send') ?>"><i class="fas fa-paper-plane"></i></a>
                            <a href="?action=delete&id=<?= $o['id'] ?>" class="btn btn-sm btn-danger" title="<?= t('delete') ?>" onclick="return confirm(t('confirm_delete'))"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                            <?php if (in_array($o['status'], ['draft','sent','partial'])): ?>
                            <a href="purchases_new?from_po=<?= $o['id'] ?>" class="btn btn-sm btn-success" title="<?= t('po.convert_confirm') ?>"><i class="fas fa-file-invoice"></i></a>
                            <a href="?action=cancel&id=<?= $o['id'] ?>" class="btn btn-sm btn-warning" title="<?= t('cancel') ?>" onclick="return confirm('<?= t('po.confirm_delete') ?>')"><i class="fas fa-ban"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let poItemCount = 0;
let poSearchTimeout = null;

function searchPOProducts(q) {
    clearTimeout(poSearchTimeout);
    const div = document.getElementById('poSearchResults');
    if (q.length < 1) { div.style.display = 'none'; return; }
    poSearchTimeout = setTimeout(() => {
        fetch('purchase_orders.php?ajax=search_products&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(products => {
            if (products.length === 0) {
                div.innerHTML = '<div style="padding:12px;text-align:center;color:#94a3b8;"><?= t('no_results') ?></div>';
            } else {
                div.innerHTML = products.map(p => `
                    <div onclick='addPOProduct(${JSON.stringify(p)})' 
                         style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;"
                         onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background='#fff'">
                        <span><strong>${p.name}</strong> ${p.barcode ? '('+p.barcode+')' : ''}</span>
                        <span style="color:${p.stock_qty <= p.min_stock ? '#dc2626' : '#64748b'}"><?= t('products.current_stock') ?>: ${p.stock_qty}</span>
                    </div>
                `).join('');
            }
            div.style.display = 'block';
        });
    }, 250);
}

function addPOProduct(p) {
    document.getElementById('poSearchResults').style.display = 'none';
    document.getElementById('poProductSearch').value = '';
    poItemCount++;
    const suggestedQty = Math.max(1, (p.reorder_point || 20) * 2 - p.stock_qty);
    document.getElementById('poItemsBody').innerHTML += `
        <tr id="po_row_${poItemCount}">
            <td><strong>${p.name}</strong><input type="hidden" name="product_id[]" value="${p.id}"></td>
            <td><input type="number" name="quantity[]" value="${suggestedQty}" min="1" class="form-control" style="width:80px;"></td>
            <td><input type="number" name="unit_price[]" value="${parseFloat(p.cost_price).toFixed(2)}" step="0.01" class="form-control" style="width:100px;"></td>
            <td class="po-line-total">${(suggestedQty * p.cost_price).toFixed(2)}</td>
            <td><button type="button" class="btn btn-sm btn-danger" onclick="document.getElementById('po_row_${poItemCount}').remove()"><i class="fas fa-trash"></i></button></td>
        </tr>
    `;
}

document.addEventListener('click', e => {
    if (!e.target.closest('#poSearchResults') && !e.target.closest('#poProductSearch'))
        document.getElementById('poSearchResults').style.display = 'none';
});
</script>

<?php require_once 'includes/footer.php'; ?>
