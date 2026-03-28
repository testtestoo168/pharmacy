<?php
require_once 'includes/config.php';
$pageTitle = t('expenses.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('expenses_view');
$company = getCompanySettings($pdo);

if (isset($_GET['delete']) && hasPermission('expenses_delete')) {
    $pdo->prepare("DELETE FROM expenses WHERE tenant_id = $tid AND branch_id = $bid AND id = ?")->execute([$_GET['delete']]);
    logActivity($pdo, 'activity.delete_expense', $_GET['delete'], 'expenses');
    header('Location: expenses?msg=deleted'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasPermission('expenses_add')) {
    try {
        verifyCsrfToken();
        $pdo->beginTransaction();
        $amount = floatval($_POST['amount']);
        $vatRate = floatval($company['vat_rate'] ?? 15);
        $vatAmt = isset($_POST['has_vat']) ? round($amount * $vatRate / 100, 2) : 0;
        $total = $amount + $vatAmt;
        $expNum = 'EXP-' . str_pad($pdo->query("SELECT COALESCE(MAX(CAST(REPLACE(expense_number,'EXP-','') AS UNSIGNED)),0)+1 FROM expenses WHERE tenant_id = $tid")->fetchColumn(), 6, '0', STR_PAD_LEFT);
        
        $pdo->prepare("INSERT INTO expenses (tenant_id,expense_number, expense_date, category, amount, vat_amount, total, description, payment_method, branch_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$expNum, $_POST['expense_date'], $_POST['category'], $amount, $vatAmt, $total, $_POST['description'], $_POST['payment_method'], getCurrentBranch(), $_SESSION['user_id']]);
        $expId = $pdo->lastInsertId();
        
        // قيد محاسبي
        createExpenseJournalEntry($pdo, ['id'=>$expId, 'expense_date'=>$_POST['expense_date'], 'category'=>$_POST['category'], 'amount'=>$amount, 'vat_amount'=>$vatAmt, 'total'=>$total, 'payment_method'=>$_POST['payment_method'], 'description'=>$_POST['description']]);
        
        $pdo->commit();
        logActivity($pdo, 'expenses.expense', "{$_POST['category']} — $total", 'expenses');
        header('Location: expenses?msg=saved'); exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger">' . t('error') . ': ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

$month = $_GET['month'] ?? date('Y-m');
$expenses = $pdo->prepare("SELECT e.*, u.full_name as user_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by WHERE e.tenant_id = $tid AND e.branch_id = $bid AND DATE_FORMAT(e.expense_date, '%Y-%m') = ? ORDER BY e.expense_date DESC, e.id DESC");
$expenses->execute([$month]); $expenses = $expenses->fetchAll();
$totalExpenses = array_sum(array_column($expenses, 'total'));

// ملخص حسب التصنيف
$catSummary = $pdo->prepare("SELECT category, SUM(total) as total, COUNT(*) as cnt FROM expenses WHERE tenant_id = $tid AND branch_id = $bid AND DATE_FORMAT(expense_date, '%Y-%m') = ? GROUP BY category ORDER BY total DESC");
$catSummary->execute([$month]); $catSummary = $catSummary->fetchAll();
?>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div><?php endif; ?>

<?php if (hasPermission('expenses_add')): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-plus"></i><?= t('expenses.add_expense') ?></h3></div>
    <div class="card-body">
        <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;align-items:end;">
            <?= csrfField() ?>
            <div><label><?= t('date') ?></label><input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" class="form-control" required></div>
            <div><label><?= t('products.category') ?></label>
                <select name="category" class="form-control" required>
                    <option value=""><?= t('select') ?></option>
                    <?php foreach ([t('expenses.salaries'),t('expenses.rent'),t('expenses.utilities'),t('expenses.maintenance'),t('expenses.transportation'),t('expenses.office_supplies'),t('expenses.marketing'),t('expenses.hospitality'),t('expenses.telecom'),t('expenses.insurance'),t('expenses.government_fees'),t('expenses.damaged_meds'),t('expenses.cleaning'),t('other')] as $cat): ?>
                    <option value="<?= $cat ?>"><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label><?= t('amount') ?></label><input type="number" step="0.01" name="amount" class="form-control" required></div>
            <div><label><?= t('sales.payment_method') ?></label><select name="payment_method" class="form-control"><option value="cash"><?= t('sales.cash') ?></option><option value="transfer"><?= t('sales.bank_transfer') ?></option><option value="card"><?= t('sales.card') ?></option></select></div>
            <div><label><input type="checkbox" name="has_vat" value="1" checked> <?= t('settings.vat_included') ?> <?= $company['vat_rate'] ?? 15 ?>%</label></div>
            <div style="grid-column:span 2;"><label><?= t('description') ?></label><input type="text" name="description" class="form-control" placeholder="<?= t('expenses.expense_desc') ?>"></div>
            <div><button type="submit" class="btn btn-primary" style="width:100%;"><i class="fas fa-save"></i><?= t('save') ?></button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- فلتر + ملخص -->
<div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:6px;"><input type="month" name="month" value="<?= $month ?>" class="form-control" style="width:160px;"><button class="btn btn-primary btn-sm"><i class="fas fa-filter"></i></button></form>
    <div style="background:#fef2f2;padding:6px 14px;border-radius:8px;font-weight:700;color:#dc2626;"><?= t('g.month_total') ?>: <?= formatMoney($totalExpenses) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></div>
</div>

<!-- ملخص التصنيفات -->
<?php if (!empty($catSummary)): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-bottom:16px;">
<?php foreach ($catSummary as $cs): ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px;text-align:center;">
    <div style="font-size:11px;color:#6b7280;"><?= $cs['category'] ?></div>
    <div style="font-size:16px;font-weight:800;color:#dc2626;"><?= formatMoney($cs['total']) ?></div>
    <div style="font-size:10px;color:#94a3b8;"><?= $cs['cnt'] ?><?= t('expenses.expense') ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-receipt"></i> <?= t('expenses.title') ?> (<?= count($expenses) ?>)</h3></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('date') ?></th><th><?= t('products.category') ?></th><th><?= t('description') ?></th><th style="text-align:center;"><?= t('amount') ?></th><th style="text-align:center;"><?= t('tax') ?></th><th style="text-align:center;"><?= t('total') ?></th><th><?= t('sales.payment_method') ?></th><th><?= t('g.by') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($expenses as $e): ?>
            <tr>
                <td><?= $e['expense_date'] ?></td>
                <td><span class="badge badge-info"><?= $e['category'] ?></span></td>
                <td style="color:#6b7280;font-size:12px;"><?= $e['description'] ?: '-' ?></td>
                <td style="text-align:center;"><?= formatMoney($e['amount']) ?></td>
                <td style="text-align:center;color:#6b7280;"><?= formatMoney($e['vat_amount']) ?></td>
                <td style="text-align:center;font-weight:700;color:#dc2626;"><?= formatMoney($e['total']) ?></td>
                <td><?= paymentTypeBadge($e['payment_method']) ?></td>
                <td dir="auto" style="color:#6b7280;font-size:11px;"><?= $e['user_name'] ?? '-' ?></td>
                <td><?php if (hasPermission('expenses_delete')): ?><a href="#" class="btn btn-sm btn-danger" onclick="showDeleteModal(<?= $e['id'] ?>, '<?= htmlspecialchars($e['description'] ?? '', ENT_QUOTES) ?>')"><i class="fas fa-trash"></i></a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
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
function showDeleteModal(id, name) { document.getElementById('deleteModalName').textContent = name; document.getElementById('deleteModalLink').href = 'expenses?month=<?= $month ?>&delete=' + id; document.getElementById('deleteModal').style.display = 'flex'; }
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
document.getElementById('deleteModal').addEventListener('click', function(e) { if(e.target===this) closeDeleteModal(); });
</script>
<?php require_once 'includes/footer.php'; ?>
