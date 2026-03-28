<?php
require_once 'includes/config.php';
$pageTitle = t('payments.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('payments_view');

$customers = $pdo->query("SELECT id, name FROM customers WHERE tenant_id = $tid ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE tenant_id = $tid ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasPermission('payments_add')) {
    try {
        verifyCsrfToken();
        $pdo->beginTransaction();
        $vNum = generateNumber($pdo, 'payment_vouchers', 'voucher_number', 'PV');
        $partyType = $_POST['party_type'] ?? 'other';
        $partyId = intval($_POST['party_id'] ?? 0) ?: null;
        $amount = floatval($_POST['amount']);
        
        $pdo->prepare("INSERT INTO payment_vouchers (tenant_id,voucher_number, paid_to, amount, description, payment_method, voucher_date, party_type, party_id, branch_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$vNum, $_POST['paid_to'], $amount, $_POST['description'], $_POST['payment_method'], $_POST['voucher_date'], $partyType, $partyId, getCurrentBranch(), $_SESSION['user_id']]);
        $voucherId = $pdo->lastInsertId();
        
        // تحديث رصيد المورد
        if ($partyType === 'supplier' && $partyId) {
            $pdo->prepare("UPDATE suppliers SET balance = balance - ? WHERE id = ? AND tenant_id = $tid")->execute([$amount, $partyId]);
        }
        
        // قيد محاسبي
        createPaymentJournalEntry($pdo, ['id'=>$voucherId, 'voucher_number'=>$vNum, 'voucher_date'=>$_POST['voucher_date'], 'amount'=>$amount, 'payment_method'=>$_POST['payment_method'], 'party_type'=>$partyType, 'description'=>$_POST['description']]);
        
        $pdo->commit();
        logActivity($pdo, 'payments.payment_voucher', "$vNum — {$_POST['paid_to']} — $amount "  . 'SAR', 'payments');
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . ': <strong>' . $vNum . '</strong> <a href="payment_print?id=' . $voucherId . '" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-print"></i>' . t('print') . '</a></div>';
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger">' . t('error') . ': ' . $e->getMessage() . '</div>';
    }
}

if (isset($_GET['delete']) && hasPermission('payments_delete')) {
    $pdo->prepare("DELETE FROM payment_vouchers WHERE tenant_id = $tid AND branch_id = $bid AND id = ?")->execute([$_GET['delete']]);
}

$vouchers = $pdo->query("SELECT pv.*, u.full_name as creator FROM payment_vouchers pv LEFT JOIN users u ON u.id = pv.created_by WHERE pv.tenant_id = $tid AND pv.branch_id = $bid ORDER BY pv.id DESC LIMIT 50")->fetchAll();
$partyLabels = ['customer'=>t('sales.customer'),'supplier'=>t('purchases.supplier'),'employee'=>t('g.employee'),'other'=>t('other')];
?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-money-check-alt"></i><?= t('g.new_payment') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label><?= t('g.party_type') ?></label>
                    <select name="party_type" id="partyType" class="form-control" onchange="updateParty()">
                        <option value="supplier"><?= t('purchases.supplier') ?></option>
                        <option value="customer"><?= t('sales.customer') ?></option>
                        <option value="employee"><?= t('g.employee') ?></option>
                        <option value="other"><?= t('other') ?></option>
                    </select>
                </div>
                <div class="form-group" id="partySelect">
                    <label><?= t('g.party_type') ?></label>
                    <select name="party_id" id="partyId" class="form-control" onchange="fillName()">
                        <option value=""><?= t('select') ?></option>
                        <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>" data-type="supplier" data-name="<?= htmlspecialchars($s['name']) ?>"><?= $s['name'] ?></option><?php endforeach; ?>
                        <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" data-type="customer" data-name="<?= htmlspecialchars($c['name']) ?>"><?= $c['name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label><?= t('g.paid_to') ?></label><input type="text" name="paid_to" id="paidTo" class="form-control" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('amount') ?> (<?= t('sar') ?>)</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
                <div class="form-group"><label><?= t('sales.payment_method') ?></label><select name="payment_method" class="form-control"><option value="cash"><?= t('sales.cash') ?></option><option value="transfer"><?= t('sales.bank_transfer') ?></option><option value="card"><?= t('sales.card') ?></option><option value="check"><?= t('g.cheque') ?></option></select></div>
                <div class="form-group"><label><?= t('date') ?></label><input type="date" name="voucher_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group" style="flex:2;"><label><?= t('g.statement') ?></label><input type="text" name="description" class="form-control" placeholder="<?= t('g.for_reason') ?>" required></div>
            </div>
            <button type="submit" class="btn btn-danger"><i class="fas fa-save"></i><?= t('g.save_payment') ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><?= t('payments.title') ?> (<?= count($vouchers) ?>)</h3></div>
    <div class="card-body">
        <div class="table-responsive"><table>
            <thead><tr><th><?= t('g.number') ?></th><th><?= t('g.paid_to') ?></th><th><?= t('type') ?></th><th style="text-align:center;"><?= t('amount') ?></th><th><?= t('g.statement') ?></th><th><?= t('sales.payment_method') ?></th><th><?= t('date') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($vouchers as $v): ?>
            <tr>
                <td><strong><?= $v['voucher_number'] ?></strong></td>
                <td><?= htmlspecialchars($v['paid_to']) ?></td>
                <td><span class="badge badge-warning" style="font-size:10px;"><?= $partyLabels[$v['party_type']] ?? t('other') ?></span></td>
                <td style="text-align:center;font-weight:700;color:#dc2626;"><?= formatMoney($v['amount']) ?></td>
                <td style="color:#6b7280;font-size:12px;"><?= htmlspecialchars($v['description']) ?></td>
                <td><?= paymentTypeBadge($v['payment_method']) ?></td>
                <td><?= $v['voucher_date'] ?></td>
                <td style="white-space:nowrap;">
                    <a href="payment_print?id=<?= $v['id'] ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-print"></i></a>
                    <?php if (hasPermission('payments_delete')): ?><a href="#" class="btn btn-sm btn-danger" onclick="showDeleteModal(<?= $v['id'] ?>, '<?= htmlspecialchars($v['voucher_number'] ?? '', ENT_QUOTES) ?>')"><i class="fas fa-trash"></i></a><?php endif; ?>
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
    document.getElementById('partySelect').style.display = ['employee','other'].includes(type) ? 'none' : 'block';
}
function fillName() {
    const sel = document.getElementById('partyId');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.name) document.getElementById('paidTo').value = opt.dataset.name;
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
