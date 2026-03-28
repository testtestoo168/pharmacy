<?php
require_once 'includes/config.php';
$pageTitle = t('receipts.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('receipts_view');

$customers = $pdo->query("SELECT id, name FROM customers WHERE tenant_id = $tid ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE tenant_id = $tid ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasPermission('receipts_add')) {
    try {
        verifyCsrfToken();
        $pdo->beginTransaction();
        $vNum = generateNumber($pdo, 'receipt_vouchers', 'voucher_number', 'RV');
        $partyType = $_POST['party_type'] ?? 'other';
        $partyId = intval($_POST['party_id'] ?? 0) ?: null;
        $amount = floatval($_POST['amount']);
        
        $pdo->prepare("INSERT INTO receipt_vouchers (tenant_id,voucher_number, received_from, amount, description, payment_method, voucher_date, party_type, party_id, branch_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$vNum, $_POST['received_from'], $amount, $_POST['description'], $_POST['payment_method'], $_POST['voucher_date'], $partyType, $partyId, getCurrentBranch(), $_SESSION['user_id']]);
        $voucherId = $pdo->lastInsertId();
        
        // تحديث رصيد العميل
        if ($partyType === 'customer' && $partyId) {
            $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id = ? AND tenant_id = $tid")->execute([$amount, $partyId]);
        }
        
        // قيد محاسبي
        createReceiptJournalEntry($pdo, ['id'=>$voucherId, 'voucher_number'=>$vNum, 'voucher_date'=>$_POST['voucher_date'], 'amount'=>$amount, 'payment_method'=>$_POST['payment_method'], 'party_type'=>$partyType, 'description'=>$_POST['description']]);
        
        $pdo->commit();
        logActivity($pdo, 'receipts.receipt_voucher', "$vNum — {$_POST['received_from']} — $amount "  . 'SAR', 'receipts');
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . ': <strong>' . $vNum . '</strong> <a href="receipt_print?id=' . $voucherId . '" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-print"></i>' . t('print') . '</a></div>';
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger">' . t('error') . ': ' . $e->getMessage() . '</div>';
    }
}

if (isset($_GET['delete']) && hasPermission('receipts_delete')) {
    $pdo->prepare("DELETE FROM receipt_vouchers WHERE tenant_id = $tid AND branch_id = $bid AND id = ?")->execute([$_GET['delete']]);
}

$vouchers = $pdo->query("SELECT rv.*, u.full_name as creator FROM receipt_vouchers rv LEFT JOIN users u ON u.id = rv.created_by WHERE rv.tenant_id = $tid AND rv.branch_id = $bid ORDER BY rv.id DESC LIMIT 50")->fetchAll();
$partyLabels = ['customer'=>t('sales.customer'),'supplier'=>t('purchases.supplier'),'other'=>t('other')];
?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-hand-holding-usd"></i><?= t('g.new_receipt') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label><?= t('g.party_type') ?></label>
                    <select name="party_type" id="partyType" class="form-control" onchange="updateParty()">
                        <option value="customer"><?= t('sales.customer') ?></option>
                        <option value="supplier"><?= t('purchases.supplier') ?></option>
                        <option value="other"><?= t('other') ?></option>
                    </select>
                </div>
                <div class="form-group" id="partySelect">
                    <label><?= t('g.party_type') ?></label>
                    <select name="party_id" id="partyId" class="form-control" onchange="fillName()">
                        <option value=""><?= t('select') ?></option>
                        <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" data-type="customer" data-name="<?= htmlspecialchars($c['name']) ?>"><?= $c['name'] ?></option><?php endforeach; ?>
                        <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>" data-type="supplier" data-name="<?= htmlspecialchars($s['name']) ?>"><?= $s['name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label><?= t('g.received_from') ?></label><input type="text" name="received_from" id="receivedFrom" class="form-control" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('amount') ?> (<?= t('sar') ?>)</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
                <div class="form-group"><label><?= t('sales.payment_method') ?></label><select name="payment_method" class="form-control"><option value="cash"><?= t('sales.cash') ?></option><option value="transfer"><?= t('sales.bank_transfer') ?></option><option value="card"><?= t('sales.card') ?></option><option value="check"><?= t('g.cheque') ?></option></select></div>
                <div class="form-group"><label><?= t('date') ?></label><input type="date" name="voucher_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group" style="flex:2;"><label><?= t('g.statement') ?></label><input type="text" name="description" class="form-control" placeholder="<?= t('g.for_reason') ?>" required></div>
            </div>
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i><?= t('g.save_receipt') ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><?= t('receipts.title') ?> (<?= count($vouchers) ?>)</h3></div>
    <div class="card-body">
        <div class="table-responsive"><table>
            <thead><tr><th><?= t('g.number') ?></th><th><?= t('g.received_from') ?></th><th><?= t('type') ?></th><th style="text-align:center;"><?= t('amount') ?></th><th><?= t('g.statement') ?></th><th><?= t('sales.payment_method') ?></th><th><?= t('date') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($vouchers as $v): ?>
            <tr>
                <td><strong><?= $v['voucher_number'] ?></strong></td>
                <td><?= htmlspecialchars($v['received_from']) ?></td>
                <td><span class="badge badge-info" style="font-size:10px;"><?= $partyLabels[$v['party_type']] ?? t('other') ?></span></td>
                <td style="text-align:center;font-weight:700;color:#16a34a;"><?= formatMoney($v['amount']) ?></td>
                <td style="color:#6b7280;font-size:12px;"><?= htmlspecialchars($v['description']) ?></td>
                <td><?= paymentTypeBadge($v['payment_method']) ?></td>
                <td><?= $v['voucher_date'] ?></td>
                <td style="white-space:nowrap;">
                    <a href="receipt_print?id=<?= $v['id'] ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-print"></i></a>
                    <?php if (hasPermission('receipts_delete')): ?><a href="#" class="btn btn-sm btn-danger" onclick="showDeleteModal(<?= $v['id'] ?>, '<?= htmlspecialchars($v['voucher_number'] ?? '', ENT_QUOTES) ?>')"><i class="fas fa-trash"></i></a><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<script>
function updateParty() {
    const type = document.getElementById('partyType').value;
    const opts = document.querySelectorAll('#partyId option[data-type]');
    opts.forEach(o => o.style.display = o.dataset.type === type ? '' : 'none');
    document.getElementById('partyId').value = '';
    document.getElementById('partySelect').style.display = type === 'other' ? 'none' : 'block';
}
function fillName() {
    const sel = document.getElementById('partyId');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.name) document.getElementById('receivedFrom').value = opt.dataset.name;
}
updateParty();
</script>
<!-- Modal تأكيد الحذف -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
    <div style="background:#fff;border-radius:16px;padding:30px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="width:60px;height:60px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;"><i class="fas fa-exclamation-triangle" style="color:#dc2626;font-size:24px;"></i></div>
        <h3 style="color:#1a2744;margin-bottom:8px;"><?= t('confirm_delete') ?></h3>
        <p style="color:#dc2626;font-weight:700;font-size:15px;margin-bottom:20px;" id="deleteModalName"></p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button onclick="closeDeleteModal()" class="btn" style="background:#e5e7eb;color:#374151;padding:10px 28px;border-radius:8px;font-size:14px;"><i class="fas fa-times"></i> <?= t('cancel') ?></button>
            <a id="deleteModalLink" href="#" class="btn" style="background:#dc2626;color:#fff;padding:10px 28px;border-radius:8px;font-size:14px;text-decoration:none;"><i class="fas fa-trash"></i> <?= t('delete') ?></a>
        </div>
    </div>
</div>
<script>
function showDeleteModal(id, name) { document.getElementById('deleteModalName').textContent = name; document.getElementById('deleteModalLink').href = '?delete=' + id; document.getElementById('deleteModal').style.display = 'flex'; }
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
document.getElementById('deleteModal').addEventListener('click', function(e) { if(e.target===this) closeDeleteModal(); });
</script>
<?php require_once 'includes/footer.php'; ?>
