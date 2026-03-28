<?php
require_once 'includes/config.php';
$pageTitle = t('suppliers.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('suppliers_view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    if (!empty($_POST['edit_id'])) {
        $pdo->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, mobile=?, email=?, tax_number=?, address=?, city=?, payment_terms=?, credit_limit=?, notes=? WHERE id=? AND tenant_id = $tid")
            ->execute([$_POST['name'], $_POST['contact_person'] ?? '', $_POST['phone'], $_POST['mobile'] ?? '', $_POST['email'] ?? '', $_POST['tax_number'], $_POST['address'], $_POST['city'] ?? '', $_POST['payment_terms'] ?? 30, $_POST['credit_limit'] ?? 0, $_POST['notes'] ?? '', $_POST['edit_id']]);
        logActivity($pdo, 'activity.edit_supplier', $_POST['name'], 'suppliers');
    } else {
        $pdo->prepare("INSERT INTO suppliers (tenant_id,name, contact_person, phone, mobile, email, tax_number, address, city, payment_terms, credit_limit, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$_POST['name'], $_POST['contact_person'] ?? '', $_POST['phone'], $_POST['mobile'] ?? '', $_POST['email'] ?? '', $_POST['tax_number'], $_POST['address'], $_POST['city'] ?? '', $_POST['payment_terms'] ?? 30, $_POST['credit_limit'] ?? 0, $_POST['notes'] ?? '']);
        logActivity($pdo, 'activity.add_supplier', $_POST['name'], 'suppliers');
    }
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
}

if (isset($_GET['delete']) && hasPermission('suppliers_delete')) {
    $has = $pdo->prepare("SELECT COUNT(*) FROM purchase_invoices WHERE tenant_id = $tid AND supplier_id = ?"); $has->execute([$_GET['delete']]);
    if ($has->fetchColumn() > 0) { echo '<div class="alert alert-danger">' . t('g.cant_delete_has_invoices') . '</div>'; }
    else { $pdo->prepare("DELETE FROM suppliers WHERE tenant_id = $tid AND id = ?")->execute([$_GET['delete']]); }
}

$suppliers = $pdo->query("SELECT s.*, 
    (SELECT COUNT(*) FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND supplier_id = s.id) as invoice_count,
    (SELECT COALESCE(SUM(grand_total),0) FROM purchase_invoices WHERE tenant_id = $tid AND branch_id = $bid AND supplier_id = s.id) as total_purchases
    FROM suppliers s ORDER BY s.name")->fetchAll();

$editS = null;
if (isset($_GET['edit'])) { $s = $pdo->prepare("SELECT * FROM suppliers WHERE tenant_id = $tid AND id = ?"); $s->execute([$_GET['edit']]); $editS = $s->fetch(); }
?>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-truck"></i> <?= $editS ? t('suppliers.edit_supplier') : t('suppliers.add_supplier') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <?php if ($editS): ?><input type="hidden" name="edit_id" value="<?= $editS['id'] ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group"><label><?= t('purchases.supplier') ?> *</label><input type="text" name="name" class="form-control" value="<?= $editS['name'] ?? '' ?>" required></div>
                <div class="form-group"><label><?= t('customers.contact_person') ?></label><input type="text" name="contact_person" class="form-control" value="<?= $editS['contact_person'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="phone" class="form-control" value="<?= $editS['phone'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="mobile" class="form-control" value="<?= $editS['mobile'] ?? '' ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('email') ?></label><input type="email" name="email" class="form-control" value="<?= $editS['email'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('settings.tax_number') ?></label><input type="text" name="tax_number" class="form-control" value="<?= $editS['tax_number'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('address') ?></label><input type="text" name="address" class="form-control" value="<?= $editS['address'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('city') ?></label><input type="text" name="city" class="form-control" value="<?= $editS['city'] ?? '' ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('g.payment_terms') ?></label><input type="number" name="payment_terms" class="form-control" value="<?= $editS['payment_terms'] ?? 30 ?>"></div>
                <div class="form-group"><label><?= t('g.credit_limit') ?></label><input type="number" name="credit_limit" class="form-control" value="<?= $editS['credit_limit'] ?? 0 ?>" step="0.01"></div>
                <div class="form-group" style="flex:2;"><label><?= t('notes') ?></label><input type="text" name="notes" class="form-control" value="<?= $editS['notes'] ?? '' ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('save') ?></button>
            <?php if ($editS): ?><a href="suppliers" class="btn btn-secondary"><?= t('cancel') ?></a><?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-truck"></i> <?= t('suppliers.title') ?> (<?= count($suppliers) ?>)</h3></div>
    <div class="card-body">
        <div class="table-responsive"><table>
            <thead><tr><th><?= t('purchases.supplier') ?></th><th><?= t('customers.contact_person') ?></th><th><?= t('phone') ?></th><th style="text-align:center;"><?= t('invoices') ?></th><th style="text-align:center;"><?= t('purchases.title') ?></th><th style="text-align:center;"><?= t('g.receivables') ?></th><th><?= t('actions') ?></th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $s): ?>
            <tr>
                <td dir="auto"><strong><?= htmlspecialchars($s['name']) ?></strong><?php if ($s['tax_number']): ?><br><small style="color:#94a3b8;"><?= $s['tax_number'] ?></small><?php endif; ?></td>
                <td style="color:#6b7280;"><?= $s['contact_person'] ?: '-' ?></td>
                <td><?= $s['phone'] ?: '-' ?></td>
                <td style="text-align:center;"><?= $s['invoice_count'] ?></td>
                <td style="text-align:center;font-weight:600;"><?= formatMoney($s['total_purchases']) ?></td>
                <td style="text-align:center;color:<?= $s['balance'] > 0 ? '#dc2626' : '#16a34a' ?>;font-weight:700;"><?= formatMoney($s['balance']) ?></td>
                <td style="white-space:nowrap;">
                    <a href="supplier_view?id=<?= $s['id'] ?>" class="btn btn-sm btn-primary" title="<?= t('details') ?>"><i class="fas fa-eye"></i></a>
                    <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-warning" title="<?= t('edit') ?>"><i class="fas fa-edit"></i></a>
                    <?php if (hasPermission('suppliers_delete')): ?><a href="?delete=<?= $s['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm(t('confirm_delete'))"><i class="fas fa-trash"></i></a><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>
