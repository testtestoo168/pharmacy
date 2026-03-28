<?php
require_once 'includes/config.php';
$pageTitle = t('customers.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('customers_view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    if (!empty($_POST['edit_id'])) {
        $pdo->prepare("UPDATE customers SET name=?, phone=?, email=?, national_id=?, address=?, city=?, type=?, tax_number=?, insurance_company=?, insurance_number=?, notes=? WHERE id=? AND tenant_id = $tid")
            ->execute([$_POST['name'], $_POST['phone'], $_POST['email'] ?? '', $_POST['national_id'] ?? '', $_POST['address'], $_POST['city'] ?? '', $_POST['type'], $_POST['tax_number'], $_POST['insurance_company'] ?? '', $_POST['insurance_number'] ?? '', $_POST['notes'] ?? '', $_POST['edit_id']]);
        logActivity($pdo, 'activity.edit_customer', $_POST['name'], 'customers');
    } else {
        $pdo->prepare("INSERT INTO customers (tenant_id,name, phone, email, national_id, address, city, type, tax_number, insurance_company, insurance_number, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid,$_POST['name'], $_POST['phone'], $_POST['email'] ?? '', $_POST['national_id'] ?? '', $_POST['address'], $_POST['city'] ?? '', $_POST['type'], $_POST['tax_number'], $_POST['insurance_company'] ?? '', $_POST['insurance_number'] ?? '', $_POST['notes'] ?? '']);
        logActivity($pdo, 'activity.add_customer', $_POST['name'], 'customers');
    }
    echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . '</div>';
}

if (isset($_GET['delete']) && hasPermission('customers_delete')) {
    $has = $pdo->prepare("SELECT COUNT(*) FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = ?"); $has->execute([$_GET['delete']]);
    if ($has->fetchColumn() > 0) { echo '<div class="alert alert-danger">' . t('g.cant_delete_has_invoices') . '</div>'; }
    else { $pdo->prepare("DELETE FROM customers WHERE tenant_id = $tid AND id = ?")->execute([$_GET['delete']]); }
}

$search = $_GET['q'] ?? '';
$where = $search ? "WHERE c.tenant_id = $tid AND (c.name LIKE ? OR c.phone LIKE ? OR c.tax_number LIKE ?)" : "WHERE c.tenant_id = $tid";
$params = $search ? ["%$search%", "%$search%", "%$search%"] : [];

$customers = $pdo->prepare("SELECT c.*, 
    (SELECT COUNT(*) FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = c.id) as invoice_count,
    (SELECT COALESCE(SUM(grand_total),0) FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $bid AND customer_id = c.id) as total_spent
    FROM customers c $where ORDER BY c.name");
$customers->execute($params);
$customers = $customers->fetchAll();

$editC = null;
if (isset($_GET['edit'])) { $s = $pdo->prepare("SELECT * FROM customers WHERE tenant_id = $tid AND id = ?"); $s->execute([$_GET['edit']]); $editC = $s->fetch(); }
?>

<!-- إضافة/تعديل -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-user-plus"></i> <?= $editC ? t('customers.edit_customer') : t('customers.add_customer') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <?php if ($editC): ?><input type="hidden" name="edit_id" value="<?= $editC['id'] ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group"><label><?= t('name') ?> *</label><input type="text" name="name" class="form-control" value="<?= $editC['name'] ?? '' ?>" required></div>
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="phone" class="form-control" value="<?= $editC['phone'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('email') ?></label><input type="email" name="email" class="form-control" value="<?= $editC['email'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('g.id_number') ?></label><input type="text" name="national_id" class="form-control" value="<?= $editC['national_id'] ?? '' ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('type') ?></label><select name="type" class="form-control"><option value="individual" <?= ($editC['type']??'')==='individual'?'selected':'' ?>><?= t('g.individual') ?></option><option value="company" <?= ($editC['type']??'')==='company'?'selected':'' ?>><?= t('g.company') ?></option></select></div>
                <div class="form-group"><label><?= t('settings.tax_number') ?></label><input type="text" name="tax_number" class="form-control" value="<?= $editC['tax_number'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('address') ?></label><input type="text" name="address" class="form-control" value="<?= $editC['address'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('city') ?></label><input type="text" name="city" class="form-control" value="<?= $editC['city'] ?? '' ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('g.insurance_company') ?></label><input type="text" name="insurance_company" class="form-control" value="<?= $editC['insurance_company'] ?? '' ?>"></div>
                <div class="form-group"><label><?= t('g.insurance_number') ?></label><input type="text" name="insurance_number" class="form-control" value="<?= $editC['insurance_number'] ?? '' ?>"></div>
                <div class="form-group" style="flex:2;"><label><?= t('notes') ?></label><input type="text" name="notes" class="form-control" value="<?= $editC['notes'] ?? '' ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('save') ?></button>
            <?php if ($editC): ?><a href="customers" class="btn btn-secondary"><?= t('cancel') ?></a><?php endif; ?>
        </form>
    </div>
</div>

<!-- بحث -->
<div style="margin-bottom:12px;">
    <form method="GET" style="display:flex;gap:8px;"><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control" placeholder="<?= t('search_placeholder') ?>" style="width:300px;"><button class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button><?php if ($search): ?><a href="customers" class="btn btn-secondary btn-sm"><?= t('g.clear') ?></a><?php endif; ?></form>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-user-tie"></i> <?= t('customers.title') ?> (<?= count($customers) ?>)</h3></div>
    <div class="card-body">
        <div class="table-responsive"><table>
            <thead><tr><th><?= t('name') ?></th><th><?= t('phone') ?></th><th><?= t('type') ?></th><th style="text-align:center;"><?= t('invoices') ?></th><th style="text-align:center;"><?= t('purchases.title') ?></th><th style="text-align:center;"><?= t('balance') ?></th><th style="text-align:center;"><?= t('customers.points') ?></th><th><?= t('actions') ?></th></tr></thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
            <tr>
                <td dir="auto"><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                <td><?= $c['phone'] ?: '-' ?></td>
                <td><span class="badge <?= $c['type']==='company'?'badge-info':'badge-primary' ?>"><?= $c['type']==='company'?t('g.company'):t('g.individual') ?></span></td>
                <td style="text-align:center;"><?= $c['invoice_count'] ?></td>
                <td style="text-align:center;font-weight:600;"><?= formatMoney($c['total_spent']) ?></td>
                <td style="text-align:center;color:<?= $c['balance'] > 0 ? '#dc2626' : '#16a34a' ?>;"><?= formatMoney($c['balance']) ?></td>
                <td style="text-align:center;"><span style="background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:6px;font-size:11px;font-weight:600;"><?= $c['loyalty_points'] ?></span></td>
                <td style="white-space:nowrap;">
                    <a href="customer_view?id=<?= $c['id'] ?>" class="btn btn-sm btn-primary" title="<?= t('details') ?>"><i class="fas fa-eye"></i></a>
                    <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm btn-warning" title="<?= t('edit') ?>"><i class="fas fa-edit"></i></a>
                    <?php if (hasPermission('customers_delete')): ?><a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('<?= t('confirm_delete') ?>')" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></a><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>
