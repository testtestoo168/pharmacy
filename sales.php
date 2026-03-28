<?php
require_once 'includes/config.php';
$pageTitle = t('sales.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('sales_view');

// حذف فاتورة مع عكس المخزون — مؤمّن بكلمة المرور
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['secure_delete_id']) && hasPermission('sales_delete')) {
    try {
        verifyCsrfToken();
        // التحقق من كلمة المرور
        $userCheck = $pdo->prepare("SELECT password FROM users WHERE id = ? AND tenant_id = ?");
        $userCheck->execute([$_SESSION['user_id'], $tid]);
        $userData = $userCheck->fetch();
        if (!$userData || !password_verify($_POST['delete_password'] ?? '', $userData['password'])) {
            throw new Exception(t('login.wrong_credentials'));
        }
        $deleteReason = trim($_POST['delete_reason'] ?? '');
        if (empty($deleteReason)) throw new Exception(t('g.reason') . ' ' . t('required_field'));

        $pdo->beginTransaction();
        $invId = intval($_POST['secure_delete_id']);
        $inv = $pdo->prepare("SELECT * FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND id = ?"); $inv->execute([$invId]); $invData = $inv->fetch();
        if ($invData && $invData['status'] !== 'held') {
            // إرجاع المخزون
            $items = $pdo->prepare("SELECT * FROM sales_invoice_items WHERE invoice_id = ?"); $items->execute([$invId]);
            while ($item = $items->fetch()) {
                if ($item['product_id']) {
                    addStock($pdo, $item['product_id'], $item['quantity'], $item['batch_id']);
                }
            }
            // حذف inventory.movements
            $pdo->prepare("DELETE FROM inventory_movements WHERE tenant_id = $tid AND reference_type = 'sale_invoice' AND reference_id = ?")->execute([$invId]);
            // حذف القيود
            $je = $pdo->prepare("SELECT id FROM journal_entries WHERE tenant_id = $tid AND reference_type = 'sale_invoice' AND reference_id = ?"); $je->execute([$invId]);
            while ($e = $je->fetch()) { $pdo->prepare("DELETE FROM journal_entry_lines WHERE entry_id = ?")->execute([$e['id']]); $pdo->prepare("DELETE FROM journal_entries WHERE tenant_id = $tid AND id = ?")->execute([$e['id']]); }
            // إرجاع نقاط العميل
            if ($invData['customer_id']) {
                $pdo->prepare("UPDATE customers SET total_purchases = GREATEST(0, total_purchases - ?), loyalty_points = GREATEST(0, loyalty_points - ?) WHERE id = ? AND tenant_id = $tid")
                    ->execute([$invData['grand_total'], floor($invData['grand_total']), $invData['customer_id']]);
            }
        }
        // حذف المرتجعات أولاً (عشان الـ Foreign Key)
        try {
            $rets = $pdo->prepare("SELECT id FROM sales_returns WHERE tenant_id = $tid AND invoice_id = ?"); $rets->execute([$invId]);
            while ($r = $rets->fetch()) {
                $pdo->prepare("DELETE FROM sales_return_items WHERE return_id = ?")->execute([$r['id']]);
                $pdo->prepare("DELETE FROM inventory_movements WHERE tenant_id = $tid AND reference_type = 'sales_return' AND reference_id = ?")->execute([$r['id']]);
            }
            $pdo->prepare("DELETE FROM sales_returns WHERE tenant_id = $tid AND invoice_id = ?")->execute([$invId]);
        } catch(Exception $e) {}
        $pdo->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?")->execute([$invId]);
        $pdo->prepare("DELETE FROM sales_invoices WHERE tenant_id = $tid AND id = ?")->execute([$invId]);
        $pdo->commit();
        // مزامنة المخزون بعد الحذف
        syncStockToBatches($pdo, $tid, $bid);
        // تسجيل الحذف في سجل خاص
        try {
            $pdo->prepare("INSERT INTO invoice_delete_log (tenant_id, invoice_number, grand_total, items_count, deleted_by, deleted_by_name, reason, ip_address) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$tid, $invData['invoice_number'] ?? '', $invData['grand_total'] ?? 0, 0, $_SESSION['user_id'], $_SESSION['username'] ?? '', $deleteReason, $_SERVER['REMOTE_ADDR'] ?? '']);
        } catch(Exception $logE) {}
        logActivity($pdo, 'activity.delete_sale', ($invData['invoice_number'] ?? $invId) . ' — ' . $deleteReason, 'sales');
        echo '<div class="alert alert-success"><i class="fas fa-check"></i> ' . t('saved_success') . '</div>';
    } catch (Exception $e) { if($pdo->inTransaction())$pdo->rollBack(); echo '<div class="alert alert-danger">' . t('error') . ': ' . $e->getMessage() . '</div>'; }
}

// فلترة
$where = "si.tenant_id = $tid AND si.status != 'held'"; $params = [];
if (!empty($_GET['customer'])) { $where .= " AND si.customer_id = ?"; $params[] = intval($_GET['customer']); }
if (!empty($_GET['date_from'])) { $where .= " AND si.invoice_date >= ?"; $params[] = $_GET['date_from']; }
if (!empty($_GET['date_to'])) { $where .= " AND si.invoice_date <= ?"; $params[] = $_GET['date_to']; }
if (!empty($_GET['payment'])) { $where .= " AND si.payment_type = ?"; $params[] = $_GET['payment']; }
if (!empty($_GET['search'])) { $where .= " AND si.invoice_number LIKE ?"; $params[] = '%'.$_GET['search'].'%'; }

$stmt = $pdo->prepare("SELECT si.*, c.name as customer_name, u.full_name as user_name,
                        (SELECT COUNT(*) FROM sales_invoice_items WHERE invoice_id = si.id) as items_count
                        FROM sales_invoices si LEFT JOIN customers c ON si.customer_id = c.id LEFT JOIN users u ON si.created_by = u.id
                        WHERE si.branch_id = $bid AND $where ORDER BY si.id DESC LIMIT 200");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$customers = $pdo->query("SELECT id, name FROM customers WHERE tenant_id = $tid ORDER BY name")->fetchAll();

// إحصائيات اليوم
$today = date('Y-m-d');
$todayStats = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(grand_total),0) as total, COALESCE(SUM(vat_amount),0) as vat FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND invoice_date = ? AND status = 'completed'");
$todayStats->execute([$today]); $ts = $todayStats->fetch();

$allStats = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(grand_total),0) as total, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(remaining_amount),0) as remaining FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND status != 'held'")->fetch();
?>

<!-- إحصائيات -->
<div class="form-row" style="margin-bottom:15px;">
    <div class="card" style="flex:1;text-align:center;padding:12px;margin:0 4px;border-top:3px solid #3b82f6;">
        <div style="font-size:22px;font-weight:800;color:#0f3460;"><?= $ts['cnt'] ?></div>
        <div style="color:#64748b;font-size:12px;"><?= t('g.today_invoices') ?></div>
    </div>
    <div class="card" style="flex:1;text-align:center;padding:12px;margin:0 4px;border-top:3px solid #16a34a;">
        <div style="font-size:22px;font-weight:800;color:#16a34a;"><?= formatMoney($ts['total']) ?></div>
        <div style="color:#64748b;font-size:12px;"><?= t('dash.today_sales') ?></div>
    </div>
    <div class="card" style="flex:1;text-align:center;padding:12px;margin:0 4px;border-top:3px solid #0f3460;">
        <div style="font-size:22px;font-weight:800;"><?= $allStats['cnt'] ?></div>
        <div style="color:#64748b;font-size:12px;"><?= t('g.total_invoices') ?></div>
    </div>
    <div class="card" style="flex:1;text-align:center;padding:12px;margin:0 4px;border-top:3px solid #dc2626;">
        <div style="font-size:22px;font-weight:800;color:#dc2626;"><?= formatMoney($allStats['remaining']) ?></div>
        <div style="color:#64748b;font-size:12px;"><?= t('g.receivables') ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-invoice-dollar"></i><?= t('sales.title') ?></h3>
        <div style="display:flex;gap:6px;">
            <?php if (hasPermission('sales_add')): ?>
            <a href="sales_new" class="btn btn-primary"><i class="fas fa-plus"></i><?= t('g.new_invoice') ?></a>
            <?php endif; ?>
            <a href="pos" class="btn btn-success"><i class="fas fa-cash-register"></i> POS</a>
        </div>
    </div>
    <div class="card-body">
        <!-- فلتر -->
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;padding:10px;background:#f8fafc;border-radius:8px;">
            <input type="text" name="search" class="form-control" placeholder="<?= t('sales.invoice_number') ?>..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" style="width:160px;">
            <select name="customer" class="form-control" style="width:160px;">
                <option value=""><?= t('g.all_customers') ?></option>
                <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>" <?= ($_GET['customer'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= $c['name'] ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>" style="width:140px;">
            <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>" style="width:140px;">
            <select name="payment" class="form-control" style="width:120px;">
                <option value=""><?= t('g.all_payments') ?></option>
                <option value="cash" <?= ($_GET['payment'] ?? '') === 'cash' ? 'selected' : '' ?>><?= t('sales.cash') ?></option>
                <option value="card" <?= ($_GET['payment'] ?? '') === 'card' ? 'selected' : '' ?>><?= t('sales.card') ?></option>
                <option value="transfer" <?= ($_GET['payment'] ?? '') === 'transfer' ? 'selected' : '' ?>><?= t('sales.bank_transfer') ?></option>
                <option value="credit" <?= ($_GET['payment'] ?? '') === 'credit' ? 'selected' : '' ?>><?= t('sales.credit') ?></option>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
            <a href="sales" class="btn btn-secondary"><i class="fas fa-times"></i></a>
        </form>

        <div class="table-responsive">
            <table>
                <thead><tr><th><?= t('sales.invoice_number') ?></th><th><?= t('sales.customer') ?></th><th><?= t('date') ?></th><th><?= t('g.invoice_items') ?></th><th><?= t('total') ?></th><th><?= t('sales.payment_method') ?></th><th><?= t('status') ?></th><th>ZATCA</th><th><?= t('remaining') ?></th><th><?= t('actions') ?></th></tr></thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                    <tr><td colspan="10" class="text-center text-muted" style="padding:25px;"><i class="fas fa-inbox" style="font-size:28px;margin-bottom:8px;display:block;"></i><?= t('no_results') ?></td></tr>
                    <?php else: foreach ($invoices as $inv): ?>
                    <tr>
                        <td><strong style="color:#0f3460;"><?= $inv['invoice_number'] ?></strong></td>
                        <td dir="auto"><?= $inv['customer_name'] ?? '<span style="color:#94a3b8;">' . t('sales.cash') . '</span>' ?></td>
                        <td><?= $inv['invoice_date'] ?></td>
                        <td><span class="badge badge-info"><?= $inv['items_count'] ?></span></td>
                        <td><strong><?= formatMoney($inv['grand_total']) ?></strong></td>
                        <td><?= paymentTypeBadge($inv['payment_type']) ?></td>
                        <td>
                            <?php $stMap = ['completed'=>[t('st.completed_f'),'badge-success'],'returned'=>[t('st.returned'),'badge-danger'],'partial_return'=>[t('st.partial_return'),'badge-warning']]; $s = $stMap[$inv['status']] ?? ['—','badge-secondary']; ?>
                            <span class="badge <?= $s[1] ?>"><?= $s[0] ?></span>
                        </td>
                        <td><?php
                            $zs = $inv['zatca_status'] ?? 'not_sent';
                            $zsMap = [
                                'not_sent'               => ['—',           '#94a3b8', '#f1f5f9'],
                                'pending'                => [t('st.processing'),     '#f59e0b', '#fef3c7'],
                                'reported'               => [t('st.arrived'),      '#16a34a', '#dcfce7'],
                                'cleared'                => [t('st.cleared'),    '#16a34a', '#dcfce7'],
                                'accepted_with_warnings' => [t('st.arrived_warn'),     '#f59e0b', '#fef3c7'],
                                'rejected'               => [t('st.rejected'),   '#dc2626', '#fef2f2'],
                                'error'                  => [t('st.error'),      '#dc2626', '#fef2f2'],
                            ];
                            $zd = $zsMap[$zs] ?? ['—','#94a3b8','#f1f5f9'];
                            if ($zs !== 'not_sent'):
                        ?><span style="background:<?=$zd[2]?>;color:<?=$zd[1]?>;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700"><?=$zd[0]?></span><?php
                            else: echo '<span style="color:#94a3b8;font-size:11px">—</span>';
                            endif; ?>
                        </td>
                        <td><?= $inv['remaining_amount'] > 0 ? '<strong style="color:#dc2626;">'.formatMoney($inv['remaining_amount']).'</strong>' : '<span style="color:#16a34a;">—</span>' ?></td>
                        <td style="white-space:nowrap;">
                            <a href="sales_print?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-info" title="<?= t('print') ?>"><i class="fas fa-print"></i></a>
                            <?php if (hasPermission('sales_delete')): ?>
                            <a href="#" class="btn btn-sm btn-danger" title="<?= t('delete') ?>" onclick="showDeleteModal(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES) ?>')"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal تأكيد حذف الفاتورة مع تأمين -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
    <div style="background:#fff;border-radius:16px;padding:30px;max-width:440px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="width:70px;height:70px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fas fa-shield-alt" style="font-size:32px;color:#dc2626;"></i>
        </div>
        <h3 style="color:#1a2744;margin-bottom:8px;"><?= t('confirm_delete') ?></h3>
        <p style="color:#6b7280;margin-bottom:6px;"><?= t('g.confirm_delete_invoice') ?></p>
        <p style="color:#dc2626;font-weight:700;font-size:15px;margin-bottom:16px;" id="deleteModalName"></p>
        <form method="POST" id="deleteForm">
            <?= csrfField() ?>
            <input type="hidden" name="secure_delete_id" id="deleteFormId" value="">
            <div style="text-align:right;margin-bottom:10px;">
                <label style="font-size:13px;font-weight:600;color:#374151;"><?= t('g.reason') ?> *</label>
                <input type="text" name="delete_reason" required placeholder="<?= t('g.reason') ?>..." class="form-control" style="width:100%;margin-top:4px;">
            </div>
            <div style="text-align:right;margin-bottom:16px;">
                <label style="font-size:13px;font-weight:600;color:#374151;"><?= t('login.password') ?> *</label>
                <input type="password" name="delete_password" required placeholder="<?= t('login.enter_password') ?>" class="form-control" style="width:100%;margin-top:4px;">
            </div>
            <div style="display:flex;gap:10px;justify-content:center;">
                <button type="button" onclick="closeDeleteModal()" class="btn" style="background:#e5e7eb;color:#374151;padding:10px 28px;border-radius:8px;font-size:14px;"><i class="fas fa-times"></i> <?= t('cancel') ?></button>
                <button type="submit" class="btn" style="background:#dc2626;color:#fff;padding:10px 28px;border-radius:8px;font-size:14px;"><i class="fas fa-trash"></i> <?= t('delete') ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function showDeleteModal(id, name) {
    document.getElementById('deleteModalName').textContent = name;
    document.getElementById('deleteFormId').value = id;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDeleteModal(); });
</script>

<?php require_once 'includes/footer.php'; ?>
