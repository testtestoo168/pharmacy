<?php
require_once 'includes/config.php';
$pageTitle = t('inventory.stock_count');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('inventory_count');

$branchId = getCurrentBranch();

// إنشاء جرد جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_count'])) {
    try {
        verifyCsrfToken();
        $pdo->beginTransaction();
        $countNumber = 'SC-' . str_pad($pdo->query("SELECT COALESCE(MAX(CAST(REPLACE(count_number,'SC-','') AS UNSIGNED)),0)+1 FROM stock_counts WHERE tenant_id = $tid")->fetchColumn(), 6, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO stock_counts (tenant_id,count_number, branch_id, count_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$tid,$countNumber, $branchId, $_POST['count_date'], 'draft', $_POST['notes'] ?? '', $_SESSION['user_id']]);
        $countId = $pdo->lastInsertId();

        // إضافة كل المنتجات الفعالة
        $products = $pdo->query("SELECT id, stock_qty FROM products WHERE tenant_id = $tid AND branch_id = $bid AND is_active = 1 ORDER BY name")->fetchAll();
        $stmt = $pdo->prepare("INSERT INTO stock_count_items (count_id, product_id, system_qty, actual_qty, difference) VALUES (?,?,?,?,0)");
        foreach ($products as $p) {
            $stmt->execute([$countId, $p['id'], $p['stock_qty'], $p['stock_qty']]);
        }
        $pdo->commit();
        logActivity($pdo, 'activity.save_count', $countNumber, 'inventory');
        header("Location: stock_count?id=$countId");
        exit;
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger">' . t('error') . ': ' . $e->getMessage() . '</div>';
    }
}

// اعتماد الجرد
if (isset($_GET['approve']) && intval($_GET['approve']) && hasPermission('inventory_count_approve')) {
    $countId = intval($_GET['approve']);
    // تحقق هل الجرد مسودة أصلاً
    $checkStatus = $pdo->prepare("SELECT status FROM stock_counts WHERE tenant_id = $tid AND branch_id = $bid AND id = ?");
    $checkStatus->execute([$countId]);
    $currentStatus = $checkStatus->fetchColumn();
    if ($currentStatus === 'completed') {
        // تم الاعتماد مسبقاً — تجاهل (يحصل عند تبديل اللغة)
        header("Location: stock_count?id=$countId&msg=approved"); exit;
    }
    try {
        $pdo->beginTransaction();
        $count = $pdo->prepare("SELECT * FROM stock_counts WHERE tenant_id = $tid AND branch_id = $bid AND id = ? AND status = 'draft'");
        $count->execute([$countId]);
        $count = $count->fetch();
        if (!$count) throw new Exception(t('g.not_found'));

        $items = $pdo->prepare("SELECT sci.*, p.name FROM stock_count_items sci JOIN products p ON p.id = sci.product_id WHERE sci.count_id = ? AND sci.difference != 0");
        $items->execute([$countId]);
        $diffItems = $items->fetchAll();

        foreach ($diffItems as $item) {
            $diff = $item['actual_qty'] - $item['system_qty'];
            // تحديث المخزون في products
            $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ? AND tenant_id = $tid AND branch_id = $bid")->execute([$item['actual_qty'], $item['product_id']]);
            // تعديل الباتشات أيضاً
            if ($diff != 0) {
                $bList = $pdo->prepare("SELECT id, available_qty FROM inventory_batches WHERE tenant_id = $tid AND product_id=? AND branch_id=? AND (expiry_date>CURDATE() OR expiry_date IS NULL) ORDER BY expiry_date ASC");
                $bList->execute([$item['product_id'], $branchId]); $bAll = $bList->fetchAll();
                if ($diff > 0) {
                    if (!empty($bAll)) { $last = end($bAll); $pdo->prepare("UPDATE inventory_batches SET available_qty=available_qty+?,quantity=quantity+? WHERE id=? AND tenant_id = $tid")->execute([$diff,$diff,$last['id']]); }
                    else { $pc=$pdo->prepare("SELECT cost_price,unit_price FROM products WHERE tenant_id = $tid AND branch_id = $bid AND id=?"); $pc->execute([$item['product_id']]); $pd=$pc->fetch();
                        $pdo->prepare("INSERT INTO inventory_batches (tenant_id,product_id,branch_id,batch_number,quantity,available_qty,purchase_price,selling_price,received_date,notes) VALUES(?,?,?,?,?,?,?,?,CURDATE(),?)")
                            ->execute([$tid,$item['product_id'],$branchId,'ADJ-'.$count['count_number'],$diff,$diff,$pd['cost_price']??0,$pd['unit_price']??0,t('inventory.adjustment')]); }
                } else {
                    $rem = abs($diff);
                    foreach ($bAll as $b) { if ($rem<=0) break; $ded=min($rem,$b['available_qty']); $pdo->prepare("UPDATE inventory_batches SET available_qty=available_qty-? WHERE id=? AND tenant_id = $tid")->execute([$ded,$b['id']]); $rem-=$ded; }
                }
                // قيد محاسبي
                try {
                    $invAcc=getOrCreateAccount($pdo,'1200',t('accounting.inventory_acc'),'asset'); $adjAcc=getOrCreateAccount($pdo,'5150',t('accounting.count_diff'),'expense');
                    $cp=$pdo->prepare("SELECT cost_price FROM products WHERE tenant_id = $tid AND branch_id = $bid AND id=?"); $cp->execute([$item['product_id']]); $cost=floatval($cp->fetchColumn());
                    $val=abs($diff)*$cost;
                    if ($val>0) { $lines=[]; if($diff>0){$lines[]=['account_id'=>$invAcc,'debit'=>$val,'credit'=>0,'description'=>t('g.surplus')];$lines[]=['account_id'=>$adjAcc,'debit'=>0,'credit'=>$val,'description'=>t('g.differences')];}else{$lines[]=['account_id'=>$adjAcc,'debit'=>$val,'credit'=>0,'description'=>t('g.shortage')];$lines[]=['account_id'=>$invAcc,'debit'=>0,'credit'=>$val,'description'=>t('g.shortage')];}
                    createJournalEntry($pdo,date('Y-m-d'),t('inventory.adjustment') . ' - ' . $item['name'],'stock_count',$countId,$lines); }
                } catch(Exception $je) { error_log("Count JE: ".$je->getMessage()); }
            }
            // حركة مخزون
            $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id, branch_id, movement_type, quantity, reference_type, reference_id, notes, created_by) VALUES (?,?,?,'adjustment',?,?,?,?,?)")
                ->execute([$tid,$item['product_id'], $branchId, abs($diff), 'stock_count', $countId, t('inventory.adjustment') . ' ' . $count['count_number'] . ($diff > 0 ? ' (+)' : ' (-)'), $_SESSION['user_id']]);
        }
        $pdo->prepare("UPDATE stock_counts SET status = 'completed' WHERE id = ? AND tenant_id = $tid")->execute([$countId]);
        $pdo->commit();
        logActivity($pdo, 'activity.approve_count', $count['count_number'], 'inventory');
        header("Location: stock_count?id=$countId&msg=approved");
        exit;
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger">' . t('error') . ': ' . $e->getMessage() . '</div>';
    }
}

// عرض جرد محدد
$viewId = intval($_GET['id'] ?? 0);
if (isset($_GET['msg']) && $_GET['msg'] === 'approved') {
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
}
if ($viewId):
    $count = $pdo->prepare("SELECT sc.*, u.full_name as creator FROM stock_counts sc LEFT JOIN users u ON u.id = sc.created_by WHERE sc.tenant_id = $tid AND sc.id = ?");
    $count->execute([$viewId]);
    $count = $count->fetch();
    
    // حفظ الكميات الفعلية
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quantities'])) {
        $itemIds = $_POST['item_id'] ?? [];
        $actualQtys = $_POST['actual_qty'] ?? [];
        for ($i = 0; $i < count($itemIds); $i++) {
            $actual = intval($actualQtys[$i]);
            $pdo->prepare("UPDATE stock_count_items SET actual_qty = ?, difference = actual_qty - system_qty WHERE id = ?")
                ->execute([$actual, intval($itemIds[$i])]);
            // recalculate difference
            $pdo->prepare("UPDATE stock_count_items SET difference = ? - system_qty WHERE id = ?")
                ->execute([$actual, intval($itemIds[$i])]);
        }
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
        // reload
        $count = $pdo->prepare("SELECT sc.*, u.full_name as creator FROM stock_counts sc LEFT JOIN users u ON u.id = sc.created_by WHERE sc.tenant_id = $tid AND sc.id = ?");
        $count->execute([$viewId]); $count = $count->fetch();
    }
    
    if ($count):
    $items = $pdo->prepare("SELECT sci.*, p.name, p.barcode FROM stock_count_items sci JOIN products p ON p.id = sci.product_id WHERE sci.count_id = ? ORDER BY p.name");
    $items->execute([$viewId]);
    $countItems = $items->fetchAll();
    $totalDiffs = array_sum(array_column($countItems, 'difference'));
?>
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-check"></i> <?= t('inventory.stock_count') ?>: <?= $count['count_number'] ?> — <?= $count['count_date'] ?>
        <span class="badge <?= $count['status'] === 'completed' ? 'badge-success' : 'badge-warning' ?>"><?= $count['status'] === 'completed' ? 'معتمد' : 'مسودة' ?></span></h3>
        <div style="display:flex;gap:6px;">
            <?php if ($count['status'] === 'draft'): ?>
            <a href="?approve=<?= $viewId ?>" class="btn btn-success btn-sm" onclick="return confirm('<?= t('inventory.approve_count') ?>')"><i class="fas fa-check"></i><?= t('approved') ?></a>
            <?php endif; ?>
            <a href="stock_count" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i><?= t('back') ?></a>
        </div>
    </div>
    <div class="card-body">
        <div style="display:flex;gap:15px;margin-bottom:12px;">
            <span><?= t('g.by') ?>: <strong><?= $count['creator'] ?></strong></span>
            <span><?= t('g.differences') ?>: <strong style="color:<?= $totalDiffs == 0 ? '#16a34a' : '#dc2626' ?>;"><?= $totalDiffs ?></strong></span>
        </div>
        
        <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="save_quantities" value="1">
        <div class="table-responsive">
            <table>
                <thead><tr><th><?= t('products.barcode') ?></th><th><?= t('item') ?></th><th style="text-align:center;"><?= t('g.book_balance') ?></th><th style="text-align:center;"><?= t('g.actual_balance') ?></th><th style="text-align:center;"><?= t('g.difference') ?></th><th><?= t('status') ?></th></tr></thead>
                <tbody>
                <?php foreach ($countItems as $ci): ?>
                <tr style="<?= $ci['difference'] != 0 ? 'background:#fef2f2;' : '' ?>">
                    <td><code style="font-size:11px;"><?= $ci['barcode'] ?></code></td>
                    <td dir="auto"><strong><?= $ci['name'] ?></strong></td>
                    <td style="text-align:center;font-weight:600;"><?= $ci['system_qty'] ?></td>
                    <td style="text-align:center;">
                        <input type="hidden" name="item_id[]" value="<?= $ci['id'] ?>">
                        <?php if ($count['status'] === 'draft'): ?>
                        <input type="number" name="actual_qty[]" value="<?= $ci['actual_qty'] ?>" min="0" class="form-control" style="width:80px;text-align:center;margin:0 auto;" onchange="showDiff(this)">
                        <?php else: ?>
                        <strong><?= $ci['actual_qty'] ?></strong>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;color:<?= $ci['difference'] > 0 ? '#16a34a' : ($ci['difference'] < 0 ? '#dc2626' : '#94a3b8') ?>;">
                        <?= $ci['difference'] > 0 ? '+' : '' ?><?= $ci['difference'] ?>
                    </td>
                    <td>
                        <?php if ($ci['difference'] > 0): ?><span class="badge badge-success"><?= t('g.surplus') ?></span>
                        <?php elseif ($ci['difference'] < 0): ?><span class="badge badge-danger"><?= t('g.shortage') ?></span>
                        <?php else: ?><span class="badge badge-info"><?= t('g.matched') ?></span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($count['status'] === 'draft'): ?>
        <button type="submit" class="btn btn-primary mt-10"><i class="fas fa-save"></i><?= t('g.save_quantities') ?></button>
        <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; endif; ?>

<?php if (!$viewId): ?>
<!-- إنشاء جرد جديد -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus-circle"></i><?= t('g.new_count') ?></h3></div>
    <div class="card-body">
        <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="create_count" value="1">
            <div class="form-group"><label><?= t('g.count_date') ?></label><input type="date" name="count_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="form-group"><label><?= t('notes') ?></label><input type="text" name="notes" class="form-control" placeholder="ملاحظات اختيارية"></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-clipboard-list"></i><?= t('g.start_count') ?></button>
        </form>
    </div>
</div>

<!-- قائمة الجرد السابقة -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-clipboard-check"></i><?= t('g.count_operations') ?></h3></div>
    <div class="card-body">
        <?php $counts = $pdo->query("SELECT sc.*, u.full_name as creator, (SELECT COUNT(*) FROM stock_count_items WHERE count_id = sc.id AND difference != 0) as diff_count FROM stock_counts sc LEFT JOIN users u ON u.id = sc.created_by WHERE sc.tenant_id = $tid AND sc.branch_id = $bid ORDER BY sc.id DESC")->fetchAll(); ?>
        <?php if (empty($counts)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-clipboard-check" style="font-size:40px;display:block;margin-bottom:8px;"></i><?= t('no_results') ?></div>
        <?php else: ?>
        <div class="table-responsive"><table>
            <thead><tr><th><?= t('g.number') ?></th><th><?= t('date') ?></th><th><?= t('status') ?></th><th style="text-align:center;"><?= t('g.differences') ?></th><th><?= t('g.by') ?></th><th><?= t('notes') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($counts as $c): ?>
            <tr>
                <td><strong><?= $c['count_number'] ?></strong></td>
                <td><?= $c['count_date'] ?></td>
                <td><span class="badge <?= $c['status']==='completed'?'badge-success':'badge-warning' ?>"><?= $c['status']==='completed'?t('approved'):t('draft') ?></span></td>
                <td style="text-align:center;font-weight:700;color:<?= $c['diff_count']>0?'#dc2626':'#16a34a' ?>;"><?= $c['diff_count'] ?><?= t('item') ?></td>
                <td style="font-size:12px;"><?= $c['creator'] ?></td>
                <td style="font-size:12px;color:#6b7280;"><?= $c['notes'] ?></td>
                <td><a href="?id=<?= $c['id'] ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
function showDiff(input) {
    const tr = input.closest('tr');
    const sys = parseInt(tr.children[2].textContent);
    const actual = parseInt(input.value) || 0;
    const diff = actual - sys;
    const diffCell = tr.children[4];
    diffCell.textContent = (diff > 0 ? '+' : '') + diff;
    diffCell.style.color = diff > 0 ? '#16a34a' : (diff < 0 ? '#dc2626' : '#94a3b8');
}
</script>

<?php require_once 'includes/footer.php'; ?>
