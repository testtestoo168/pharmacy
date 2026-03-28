<?php
require_once 'includes/config.php';
$pageTitle = t('accounting.journal_entries');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('accounting_view');

$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');
$refType = $_GET['ref_type'] ?? '';

// إضافة قيد يدوي
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_entry'])) {
    try { verifyCsrfToken();
        $pdo->beginTransaction();
        $entryDate = $_POST['entry_date'];
        $description = $_POST['description'];
        $accountIds = $_POST['account_id'] ?? [];
        $debits = $_POST['debit'] ?? [];
        $credits = $_POST['credit'] ?? [];
        $lineDescs = $_POST['line_desc'] ?? [];
        
        $lines = [];
        $totalDebit = 0; $totalCredit = 0;
        for ($i = 0; $i < count($accountIds); $i++) {
            if (!$accountIds[$i]) continue;
            $d = floatval($debits[$i] ?? 0);
            $c = floatval($credits[$i] ?? 0);
            if ($d == 0 && $c == 0) continue;
            $lines[] = ['account_id' => $accountIds[$i], 'debit' => $d, 'credit' => $c, 'description' => $lineDescs[$i] ?? ''];
            $totalDebit += $d; $totalCredit += $c;
        }
        
        if (empty($lines)) throw new Exception(t('validation.add_one_item'));
        if (round($totalDebit, 2) !== round($totalCredit, 2)) throw new Exception(t('accounting.unbalanced') . ' ' . t('accounting.debit_total') . ' ' . number_format($totalDebit, 2) . ' ' . t('accounting.credit_total') . ' ' . number_format($totalCredit, 2));
        
        createJournalEntry($pdo, $entryDate, $description, 'manual', 0, $lines);
        $pdo->commit();
        logActivity($pdo, 'accounting.manual_journal', $description, 'accounting');
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
    } catch (Exception $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        echo '<div class="alert alert-danger">' . t('error') . ': ' . $e->getMessage() . '</div>';
    }
}

// جلب القيود
$where = "WHERE je.tenant_id = $tid AND je.entry_date BETWEEN ? AND ?";
$params = [$fromDate, $toDate];
if ($refType) { $where .= " AND je.reference_type = ?"; $params[] = $refType; }

$entries = $pdo->prepare("SELECT je.*, u.full_name as creator FROM journal_entries je LEFT JOIN users u ON u.id = je.created_by $where ORDER BY je.entry_date DESC, je.id DESC");
$entries->execute($params);
$entries = $entries->fetchAll();

$accounts = $pdo->query("SELECT id, code, name FROM accounts WHERE tenant_id = $tid AND is_active = 1 ORDER BY code")->fetchAll();

$refTypeLabels = ['sale_invoice'=>t('perms.sales'),'purchase_invoice'=>t('perms.purchases'),'receipt_voucher'=>t('receipts.receipt_voucher'),'payment_voucher'=>t('payments.payment_voucher'),'expense'=>t('expenses.expense'),'manual'=>t('manual'),'sales_return'=>t('tx.sale_return'),'purchase_return'=>t('tx.purchase_return')];
?>

<!-- فلتر -->
<div class="card no-print">
    <div class="card-body" style="padding:12px;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <label><?= t('from') ?>:</label><input type="date" name="from" class="form-control" value="<?= $fromDate ?>" style="width:150px;">
            <label><?= t('to') ?>:</label><input type="date" name="to" class="form-control" value="<?= $toDate ?>" style="width:150px;">
            <label><?= t('type') ?>:</label>
            <select name="ref_type" class="form-control" style="width:140px;">
                <option value=""><?= t('all') ?></option>
                <?php foreach ($refTypeLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $refType === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i><?= t('view') ?></button>
        </form>
    </div>
</div>

<!-- إضافة قيد يدوي -->
<div class="card no-print">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i><?= t('accounting.manual_journal') ?></h3>
        <button class="btn btn-sm btn-info" onclick="document.getElementById('manualEntry').style.display=document.getElementById('manualEntry').style.display==='none'?'block':'none'">
            <i class="fas fa-edit"></i> <?= t('g.toggle') ?>
        </button>
    </div>
    <div id="manualEntry" style="display:none;">
        <div class="card-body">
            <form method="POST" id="jeForm">
                <?= csrfField() ?>
                <input type="hidden" name="add_entry" value="1">
                <div class="form-row">
                    <div class="form-group"><label><?= t('date') ?></label><input type="date" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group" style="flex:3;"><label><?= t('description') ?></label><input type="text" name="description" class="form-control" required placeholder="<?= t('description') ?>"></div>
                </div>
                <table id="jeLines" style="width:100%;border-collapse:collapse;">
                    <thead><tr style="background:#f1f5f9;"><th style="padding:8px;"><?= t('accounting.account') ?></th><th style="padding:8px;width:130px;"><?= t('accounting.debit') ?></th><th style="padding:8px;width:130px;"><?= t('accounting.credit') ?></th><th style="padding:8px;"><?= t('g.statement') ?></th><th style="padding:8px;width:40px;"></th></tr></thead>
                    <tbody id="jeLinesBody">
                        <tr>
                            <td style="padding:4px;"><select name="account_id[]" class="form-control" required><option value=""><?= t('select') ?></option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= $a['code'] ?> - <?= $a['name'] ?></option><?php endforeach; ?></select></td>
                            <td style="padding:4px;"><input type="number" name="debit[]" class="form-control" value="0" step="0.01" onchange="calcJE()"></td>
                            <td style="padding:4px;"><input type="number" name="credit[]" class="form-control" value="0" step="0.01" onchange="calcJE()"></td>
                            <td style="padding:4px;"><input type="text" name="line_desc[]" class="form-control" placeholder="<?= t('g.statement') ?>"></td>
                            <td style="padding:4px;"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcJE()">×</button></td>
                        </tr>
                        <tr>
                            <td style="padding:4px;"><select name="account_id[]" class="form-control" required><option value=""><?= t('select') ?></option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= $a['code'] ?> - <?= $a['name'] ?></option><?php endforeach; ?></select></td>
                            <td style="padding:4px;"><input type="number" name="debit[]" class="form-control" value="0" step="0.01" onchange="calcJE()"></td>
                            <td style="padding:4px;"><input type="number" name="credit[]" class="form-control" value="0" step="0.01" onchange="calcJE()"></td>
                            <td style="padding:4px;"><input type="text" name="line_desc[]" class="form-control" placeholder="<?= t('g.statement') ?>"></td>
                            <td style="padding:4px;"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcJE()">×</button></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc;font-weight:700;">
                            <td style="padding:8px;"><?= t('total') ?></td>
                            <td style="padding:8px;" id="totalDebit">0.00</td>
                            <td style="padding:8px;" id="totalCredit">0.00</td>
                            <td colspan="2" style="padding:8px;"><span id="balanceStatus" style="font-size:12px;"></span></td>
                        </tr>
                    </tfoot>
                </table>
                <div style="margin-top:8px;display:flex;gap:8px;">
                    <button type="button" class="btn btn-sm btn-success" onclick="addJELine()"><i class="fas fa-plus"></i><?= t('g.add_line') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('g.save_entry') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- عرض القيود -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-book"></i> <?= t('accounting.journal_entries') ?></h3>
        <span class="badge badge-info"><?= count($entries) ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($entries)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-book-open" style="font-size:40px;margin-bottom:10px;display:block;"></i><?= t('no_results') ?></div>
        <?php else: ?>
        <?php foreach ($entries as $entry): 
            $lines = $pdo->prepare("SELECT jl.*, a.code, a.name as account_name FROM journal_entry_lines jl JOIN accounts a ON a.id = jl.account_id WHERE jl.entry_id = ? ORDER BY jl.debit DESC");
            $lines->execute([$entry['id']]);
            $entryLines = $lines->fetchAll();
        ?>
        <div style="border:1px solid #e2e8f0;border-radius:8px;margin-bottom:10px;overflow:hidden;">
            <div style="background:#f8fafc;padding:8px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
                <div>
                    <code style="background:#1d4ed8;color:#fff;padding:2px 8px;border-radius:4px;font-size:12px;"><?= $entry['entry_number'] ?></code>
                    <span style="margin-right:8px;font-weight:600;"><?= htmlspecialchars($entry['description']) ?></span>
                </div>
                <div style="display:flex;gap:6px;align-items:center;font-size:12px;">
                    <span style="color:#6b7280;"><?= $entry['entry_date'] ?></span>
                    <span style="background:<?= $entry['is_auto'] ? '#dcfce7' : '#fef3c7' ?>;color:<?= $entry['is_auto'] ? '#166534' : '#92400e' ?>;padding:1px 6px;border-radius:8px;font-size:10px;"><?= $entry['is_auto'] ? 'تلقائي' : 'يدوي' ?></span>
                    <?php if ($entry['reference_type']): ?>
                    <span style="background:#eff6ff;color:#1d4ed8;padding:1px 6px;border-radius:8px;font-size:10px;"><?= $refTypeLabels[$entry['reference_type']] ?? $entry['reference_type'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="background:#f1f5f9;font-size:12px;"><th style="padding:5px 10px;text-align:right;"><?= t('accounting.code') ?></th><th style="padding:5px 10px;text-align:right;"><?= t('accounting.account') ?></th><th style="padding:5px 10px;text-align:center;"><?= t('accounting.debit') ?></th><th style="padding:5px 10px;text-align:center;"><?= t('accounting.credit') ?></th><th style="padding:5px 10px;text-align:right;"><?= t('g.statement') ?></th></tr></thead>
                <tbody>
                <?php foreach ($entryLines as $line): ?>
                <tr style="border-top:1px solid #f1f5f9;font-size:12px;">
                    <td style="padding:4px 10px;"><code style="font-size:11px;"><?= $line['code'] ?></code></td>
                    <td style="padding:4px 10px;"><?= $line['account_name'] ?></td>
                    <td style="padding:4px 10px;text-align:center;<?= $line['debit'] > 0 ? 'font-weight:700;color:#1d4ed8;' : 'color:#d1d5db;' ?>"><?= $line['debit'] > 0 ? formatMoney($line['debit']) : '-' ?></td>
                    <td style="padding:4px 10px;text-align:center;<?= $line['credit'] > 0 ? 'font-weight:700;color:#dc2626;' : 'color:#d1d5db;' ?>"><?= $line['credit'] > 0 ? formatMoney($line['credit']) : '-' ?></td>
                    <td style="padding:4px 10px;color:#6b7280;font-size:11px;"><?= htmlspecialchars($line['description']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr style="background:#f0fdf4;font-weight:700;font-size:12px;">
                    <td colspan="2" style="padding:5px 10px;"><?= t('total') ?></td>
                    <td style="padding:5px 10px;text-align:center;color:#1d4ed8;"><?= formatMoney($entry['total_debit']) ?></td>
                    <td style="padding:5px 10px;text-align:center;color:#dc2626;"><?= formatMoney($entry['total_credit']) ?></td>
                    <td></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
const accOpts = `<option value=""><?= t('select') ?></option><?php foreach ($accounts as $a): ?><option value="<?= $a['id'] ?>"><?= $a['code'] ?> - <?= addslashes($a['name']) ?></option><?php endforeach; ?>`;
function addJELine(){
    const tr = document.createElement('tr');
    tr.innerHTML = `<td style="padding:4px;"><select name="account_id[]" class="form-control" required>${accOpts}</select></td><td style="padding:4px;"><input type="number" name="debit[]" class="form-control" value="0" step="0.01" onchange="calcJE()"></td><td style="padding:4px;"><input type="number" name="credit[]" class="form-control" value="0" step="0.01" onchange="calcJE()"></td><td style="padding:4px;"><input type="text" name="line_desc[]" class="form-control" placeholder="<?= t('g.statement') ?>"></td><td style="padding:4px;"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove();calcJE()">×</button></td>`;
    document.getElementById('jeLinesBody').appendChild(tr);
}
function calcJE(){
    let td=0,tc=0;
    document.querySelectorAll('[name="debit[]"]').forEach(i=>td+=parseFloat(i.value)||0);
    document.querySelectorAll('[name="credit[]"]').forEach(i=>tc+=parseFloat(i.value)||0);
    document.getElementById('totalDebit').textContent=td.toFixed(2);
    document.getElementById('totalCredit').textContent=tc.toFixed(2);
    const bs=document.getElementById('balanceStatus');
    if(Math.abs(td-tc)<0.01){bs.textContent='✓ ' + '<?= t("g.balanced") ?>';bs.style.color='#16a34a';}
    else{bs.textContent='✗ ' + '<?= t("accounting.unbalanced") ?>' + ' ('+(td-tc).toFixed(2)+')';bs.style.color='#dc2626';}
}
document.getElementById('jeForm')?.addEventListener('submit',function(e){
    let td=0,tc=0;
    document.querySelectorAll('[name="debit[]"]').forEach(i=>td+=parseFloat(i.value)||0);
    document.querySelectorAll('[name="credit[]"]').forEach(i=>tc+=parseFloat(i.value)||0);
    if(Math.abs(td-tc)>0.01){e.preventDefault();alert('القيد غير متوازن!');}
});
</script>

<?php require_once 'includes/footer.php'; ?>
