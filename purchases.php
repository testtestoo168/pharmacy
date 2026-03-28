<?php
require_once 'includes/config.php';
$pageTitle = t('purchases.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('purchases_view');

if (isset($_GET['delete']) && hasPermission('purchases_delete')) {
    $stmt = $pdo->prepare("DELETE FROM purchase_invoices WHERE tenant_id = $tid AND id = ?");
    $stmt->execute([$_GET['delete']]);
    echo '<div class="alert alert-success"><i class="fas fa-check"></i> ' . t('saved_success') . '</div>';
}

$invoices = $pdo->query("SELECT pi.*, s.name as supplier_name FROM purchase_invoices pi LEFT JOIN suppliers s ON pi.supplier_id = s.id WHERE pi.tenant_id = $tid AND pi.branch_id = $bid ORDER BY pi.id DESC")->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-shopping-cart"></i><?= t('purchases.title') ?></h3>
        <a href="purchases_new" class="btn btn-primary"><i class="fas fa-plus"></i><?= t('g.new_invoice') ?></a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead><tr><th><?= t('sales.invoice_number') ?></th><th><?= t('purchases.supplier') ?></th><th><?= t('date') ?></th><th><?= t('type') ?></th><th><?= t('total') ?></th><th><?= t('sales.paid_amount') ?></th><th><?= t('remaining') ?></th><th><?= t('actions') ?></th></tr></thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                    <tr><td colspan="8" class="text-center text-muted"><?= t('no_results') ?></td></tr>
                    <?php else: foreach ($invoices as $inv): ?>
                    <tr>
                        <td><strong><?= $inv['invoice_number'] ?></strong></td>
                        <td dir="auto"><?= $inv['supplier_name'] ?? '-' ?></td>
                        <td><?= $inv['invoice_date'] ?></td>
                        <td><?= paymentTypeBadge($inv['payment_type']) ?></td>
                        <td><strong><?= formatMoney($inv['grand_total']) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></strong></td>
                        <td><?= formatMoney($inv['paid_amount'] ?? $inv['grand_total']) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td>
                        <td>
                            <?php $remaining = floatval($inv['remaining_amount'] ?? 0); ?>
                            <?php if ($remaining > 0): ?>
                            <strong style="color:#c62828;"><?= formatMoney($remaining) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></strong>
                            <?php else: ?>
                            <span style="color:#16a34a;"><?= t('paid') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <a href="purchase_view?id=<?= $inv['id'] ?>" class="btn btn-sm btn-info" title="<?= t('view') ?>"><i class="fas fa-eye"></i></a>
                            <a href="purchase_print?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-primary" title="<?= t('print') ?>"><i class="fas fa-print"></i></a>
                            <?php if (hasPermission('purchases_delete')): ?>
                            <a href="#" class="btn btn-sm btn-danger" onclick="showDeleteModal(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number'] ?? '', ENT_QUOTES) ?>')"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- Modal تأكيد الحذف -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
    <div style="background:#fff;border-radius:16px;padding:30px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="width:60px;height:60px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;"><i class="fas fa-exclamation-triangle" style="color:#dc2626;font-size:24px;"></i></div>
        <h3 style="color:#1a2744;margin-bottom:8px;"><?= t('confirm_delete') ?></h3>
        <p style="color:#6b7280;margin-bottom:6px;">هل أنت متأكد من حذف هذه الفاتورة؟</p>
        <p style="color:#dc2626;font-weight:700;font-size:15px;margin-bottom:20px;" id="deleteModalName"></p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button onclick="closeDeleteModal()" class="btn" style="background:#e5e7eb;color:#374151;padding:10px 28px;border-radius:8px;font-size:14px;"><i class="fas fa-times"></i> <?= t('cancel') ?></button>
            <a id="deleteModalLink" href="#" class="btn" style="background:#dc2626;color:#fff;padding:10px 28px;border-radius:8px;font-size:14px;text-decoration:none;"><i class="fas fa-trash"></i> <?= t('delete') ?></a>
        </div>
    </div>
</div>
<script>
function showDeleteModal(id, name) {
    document.getElementById('deleteModalName').textContent = name;
    document.getElementById('deleteModalLink').href = '?delete=' + id;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
document.getElementById('deleteModal').addEventListener('click', function(e) { if(e.target===this) closeDeleteModal(); });
</script>
<?php require_once 'includes/footer.php'; ?>
