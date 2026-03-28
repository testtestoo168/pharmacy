<?php
require_once 'includes/config.php';
$pageTitle = t('products.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('products_view');

// حذف
if (isset($_GET['delete']) && hasPermission('products_delete')) {
    $pdo->prepare("UPDATE products SET is_active = 0 WHERE id = ? AND tenant_id = $tid AND branch_id = $bid")->execute([$_GET['delete']]);
    logActivity($pdo, 'activity.delete_product', $_GET['delete'], 'products');
    header('Location: products?msg=deleted'); exit;
}

// رفع ملف Excel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_excel']) && hasPermission('products_add')) {
    verifyCsrfToken();
    $importResult = ['success' => 0, 'skipped' => 0, 'errors' => []];
    
    if (!empty($_FILES['excel_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'])) {
            $importResult['errors'][] = t('products.excel_format_error');
        } else {
            try {
                $tmpFile = $_FILES['excel_file']['tmp_name'];
                
                // قراءة Excel بـ PHP فقط (بدون shell_exec)
                require_once 'includes/helpers/XlsxReader.php';
                $allRows = XlsxReader::read($tmpFile, 500);
                
                if (empty($allRows)) {
                    $importResult['errors'][] = t('products.file_empty_error');
                } else {
                    // أول سطر هو الـ header — نتخطاه
                    $data = array_slice($allRows, 1);
                    $branchId = getBranchId();
                    
                    foreach ($data as $row) {
                        // Pad row to 14 columns
                        while (count($row) < 14) $row[] = '';
                        
                        $barcode = trim($row[0]);
                        $nameAr = trim($row[1]);
                        $nameEn = trim($row[2]);
                        $genericName = trim($row[3]);
                        $category = trim($row[4]);
                        $dosageForm = trim($row[5]);
                        $concentration = trim($row[6]);
                        $unit = trim($row[7]) ?: t('pieces');
                        $costPrice = floatval($row[8]);
                        $salePrice = floatval($row[9]);
                        $openingQty = intval($row[10]);
                        $expiryDate = trim($row[11]) ?: null;
                        $batchNumber = trim($row[12]) ?: ('INIT-' . time() . '-' . rand(100,999));
                        $prescription = (trim($row[13]) === t('yes') || strtolower(trim($row[13])) === 'yes') ? 1 : 0;
                        
                        // فحص الحقول الإجبارية
                        if (!$nameAr || !$nameEn || !$barcode || !$category || !$unit || $salePrice <= 0 || $costPrice <= 0) {
                            $importResult['skipped']++;
                            continue;
                        }
                        
                        // التحقق من الحقول الإجبارية
                        $missing = [];
                        if (!$barcode) $missing[] = t('products.barcode');
                        if (!$nameAr) $missing[] = t('products.product_name');
                        if (!$nameEn) $missing[] = t('products.product_name_en');
                        if (!$category) $missing[] = t('products.category');
                        if (!$unit) $missing[] = t('products.unit');
                        if ($costPrice <= 0) $missing[] = t('g.purchase_price');
                        if ($salePrice <= 0) $missing[] = t('products.sell_price');
                        
                        if (!empty($missing)) {
                            $importResult['errors'][] = "$nameAr: " . implode(', ', $missing);
                            $importResult['skipped']++;
                            continue;
                        }
                        
                        // Skip if barcode already exists
                        if ($barcode) {
                            $exists = $pdo->prepare("SELECT id FROM products WHERE tenant_id=? AND branch_id=? AND barcode=? AND is_active=1");
                            $exists->execute([$tid, $branchId, $barcode]);
                            if ($exists->fetchColumn()) {
                                $importResult['skipped']++;
                                continue;
                            }
                        }
                        
                        // Find or create category
                        $categoryId = null;
                        if ($category) {
                            $catQ = $pdo->prepare("SELECT id FROM categories WHERE tenant_id=? AND name=?");
                            $catQ->execute([$tid, $category]);
                            $categoryId = $catQ->fetchColumn();
                            if (!$categoryId) {
                                $pdo->prepare("INSERT INTO categories (tenant_id, name, is_active, sort_order) VALUES (?,?,1,0)")->execute([$tid, $category]);
                                $categoryId = $pdo->lastInsertId();
                            }
                        }
                        
                        // Insert product
                        try {
                            $pdo->prepare("INSERT INTO products (tenant_id, branch_id, barcode, name, name_en, generic_name, category_id, dosage_form, concentration, unit, cost_price, unit_price, requires_prescription, is_active, stock_qty, min_stock, max_stock, reorder_point) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,10,1000,20)")
                                ->execute([$tid, $branchId, $barcode, $nameAr, $nameEn, $genericName, $categoryId, $dosageForm, $concentration, $unit, $costPrice, $salePrice, $prescription, $openingQty]);
                            $newId = $pdo->lastInsertId();
                            
                            // Add opening stock if qty > 0
                            if ($openingQty > 0) {
                                if ($expiryDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) $expiryDate = null;
                                
                                $pdo->prepare("INSERT INTO inventory_batches (tenant_id, product_id, branch_id, batch_number, quantity, available_qty, purchase_price, selling_price, expiry_date, received_date) VALUES (?,?,?,?,?,?,?,?,?,CURDATE())")
                                    ->execute([$tid, $newId, $branchId, $batchNumber, $openingQty, $openingQty, $costPrice, $salePrice, $expiryDate]);
                                $batchId = $pdo->lastInsertId();
                                
                                $pdo->prepare("INSERT INTO inventory_movements (tenant_id, product_id, batch_id, branch_id, movement_type, quantity, reference_type, notes, created_by) VALUES (?,?,?,?,'adjustment',?,'opening_balance',?,?)")
                                    ->execute([$tid, $newId, $batchId, $branchId, $openingQty, t('accounting.opening_balance'), $_SESSION['user_id']]);
                            }
                            
                            $importResult['success']++;
                        } catch (Exception $e) {
                            $importResult['errors'][] = t('error') . " $nameAr: " . $e->getMessage();
                        }
                    }
                    
                    logActivity($pdo, 'activity.excel_import', "{$importResult['success']}", 'products');
                }
            } catch (Exception $e) {
                $importResult['errors'][] = $e->getMessage();
            }
        }
    } else {
        $importResult['errors'][] = t('choose_file');
    }
    
    header('Location: products?msg=imported&count=' . $importResult['success'] . '&skipped=' . $importResult['skipped'] . '&errors=' . count($importResult['errors'])); exit;
}

// إضافة / تعديل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasPermission('products_add')) {
    verifyCsrfToken();
    $id = $_POST['id'] ?? '';
    // Permission check for price changes
    if ($id) {
        $oldP = $pdo->prepare("SELECT unit_price, cost_price FROM products WHERE tenant_id = $tid AND branch_id = $bid AND id=?"); $oldP->execute([$id]); $oldPr=$oldP->fetch();
        if ($oldPr) {
            if (floatval($_POST['unit_price']) != floatval($oldPr['unit_price']) && !hasPermission('price_edit')) { header('Location: products?msg=no_price_perm'); exit; }
            if (floatval($_POST['cost_price']) != floatval($oldPr['cost_price']) && !hasPermission('cost_edit')) { header('Location: products?msg=no_cost_perm'); exit; }
        }
    }
    $data = [
        $_POST['barcode'], $_POST['sku'] ?? '', $_POST['name'], trim($_POST['name_en'] ?? ''),
        $_POST['generic_name'] ?? '', $_POST['category_id'] ?: null, $_POST['manufacturer'] ?? '',
        $_POST['concentration'] ?? '', $_POST['dosage_form'] ?? '', $_POST['unit'] ?? t('pieces'),
        $_POST['description'] ?? '', floatval($_POST['unit_price']), floatval($_POST['cost_price']),
        floatval($_POST['vat_rate'] ?? 15), intval($_POST['requires_prescription'] ?? 0),
        intval($_POST['min_stock'] ?? 10), intval($_POST['max_stock'] ?? 1000),
        intval($_POST['reorder_point'] ?? 20), $_POST['notes'] ?? ''
    ];
    if ($id) {
        $pdo->prepare("UPDATE products SET barcode=?,sku=?,name=?,name_en=?,generic_name=?,category_id=?,manufacturer=?,concentration=?,dosage_form=?,unit=?,description=?,unit_price=?,cost_price=?,vat_rate=?,requires_prescription=?,min_stock=?,max_stock=?,reorder_point=?,notes=? WHERE id=? AND tenant_id = $tid")->execute([...$data, $id]);
        logActivity($pdo, 'activity.edit_product', $_POST['name'], 'products');
    } else {
        $pdo->prepare("INSERT INTO products (tenant_id,branch_id,barcode,sku,name,name_en,generic_name,category_id,manufacturer,concentration,dosage_form,unit,description,unit_price,cost_price,vat_rate,requires_prescription,min_stock,max_stock,reorder_point,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge([$tid, $bid], $data));
        $newProductId = $pdo->lastInsertId();
        
        // إنشاء دفعة مخزون أولية
        $initialStock = intval($_POST['initial_stock'] ?? 0);
        if ($initialStock > 0) {
            $branchId = getBranchId();
            $expiryDate = $_POST['expiry_date'] ?? null;
            $batchNumber = $_POST['batch_number'] ?? ('INIT-' . time());
            if (empty($expiryDate)) $expiryDate = null;
            if (empty($batchNumber)) $batchNumber = 'INIT-' . time();
            
            $pdo->prepare("INSERT INTO inventory_batches (tenant_id,product_id, branch_id, batch_number, quantity, available_qty, purchase_price, selling_price, expiry_date, received_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())")
                ->execute([$tid,$newProductId, $branchId, $batchNumber, $initialStock, $initialStock, floatval($_POST['cost_price']), floatval($_POST['unit_price']), $expiryDate]);
            
            // تحديث المخزون في جدول المنتجات
            $pdo->prepare("UPDATE products SET stock_qty = ? WHERE id = ? AND tenant_id = $tid AND branch_id = $bid")->execute([$initialStock, $newProductId]);
            
            // تسجيل حركة المخزون
            $batchId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id, batch_id, branch_id, movement_type, quantity, reference_type, notes, created_by) VALUES (?,?, ?, ?, 'adjustment', ?, 'opening_balance', ?, ?)")
                ->execute([$tid,$newProductId, $batchId, $branchId, $initialStock, t('accounting.opening_balance'), $_SESSION['user_id']]);
        }
        
        logActivity($pdo, 'activity.add_product', $_POST['name'] . ($initialStock > 0 ? " — $initialStock" : ''), 'products');
    }
    header('Location: products?msg=saved'); exit;
}

// جلب البيانات
$search = $_GET['search'] ?? '';
$catFilter = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';

$where = "WHERE p.tenant_id = $tid AND p.branch_id = $bid AND p.is_active = 1";
$params = [];
if ($search) { $where .= " AND (p.name LIKE ? OR p.barcode LIKE ? OR p.generic_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where .= " AND p.category_id = ?"; $params[] = $catFilter; }
if ($stockFilter === 'low') { $where .= " AND p.stock_qty <= p.min_stock AND p.stock_qty > 0"; }
if ($stockFilter === 'out') { $where .= " AND p.stock_qty = 0"; }

$products = $pdo->prepare("SELECT p.*, c.name as category_name, c.name_ar as category_name_ar,
    COALESCE((SELECT SUM(ib.available_qty) FROM inventory_batches ib WHERE ib.product_id = p.id AND ib.branch_id = $bid), 0) as branch_stock
    FROM products p LEFT JOIN categories c ON p.category_id = c.id $where ORDER BY p.name");
$products->execute($params);
$products = $products->fetchAll();

$categories = $pdo->query("SELECT *, COALESCE(name_ar, name) as name_ar FROM categories WHERE tenant_id = $tid AND is_active = 1 ORDER BY sort_order, name")->fetchAll();
// Helper: show Arabic name when available and language is Arabic
function catName($cat) { if (currentLang() === 'ar') { return !empty($cat['name_ar']) ? $cat['name_ar'] : $cat['name']; } return $cat['name']; }

$editProduct = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE tenant_id = $tid AND branch_id = $bid AND id = ?");
    $stmt->execute([$_GET['edit']]);
    $editProduct = $stmt->fetch();
}
?>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success" style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
    <i class="fas fa-check-circle"></i> 
    <?php 
    $m = htmlspecialchars($_GET['msg'] ?? '');
    if ($m === 'deleted') echo '<div class="alert alert-success">' . t('saved_success') . '</div>';
    elseif ($m === 'imported') {
        $cnt = intval($_GET['count'] ?? 0);
        $skip = intval($_GET['skipped'] ?? 0);
        $errs = intval($_GET['errors'] ?? 0);
        echo "$cnt " . t('excel.imported');
        if ($skip > 0) echo " — " . t('excel.skipped') . ": $skip";
        if ($errs > 0) echo " — " . t('excel.errors') . ": $errs";
    }
    else echo t('saved_success');
    ?>
</div>
<?php endif; ?>

<!-- استيراد من Excel -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="cursor:pointer;" onclick="document.getElementById('importSection').style.display=document.getElementById('importSection').style.display==='none'?'block':'none'">
        <h3><i class="fas fa-file-excel" style="color:#16a34a;"></i><?= t('g.import_excel') ?></h3>
        <i class="fas fa-chevron-down" style="color:#94a3b8;"></i>
    </div>
    <div id="importSection" style="display:none;padding:20px;">
        <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
            <a href="assets/templates/products_import_template.xlsx" download class="btn" style="background:#f0fdf4;color:#16a34a;text-decoration:none;font-weight:600;border:1px solid #bbf7d0;">
                <i class="fas fa-download"></i> <?= t('products.download_template') ?>
            </a>
            <span style="color:#64748b;font-size:13px;"><?= t('products.template_hint') ?></span>
        </div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="import_excel" value="1">
            <input type="file" name="excel_file" accept=".xlsx,.xls" class="form-control" style="max-width:300px;" required>
            <button type="submit" class="btn btn-primary" onclick="return confirm('<?= t('products.confirm_import') ?>')"><i class="fas fa-upload"></i><?= t('g.upload_import') ?></button>
        </form>
        <div style="margin-top:12px;padding:10px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b;">
            <i class="fas fa-info-circle"></i> <strong style="color:#dc2626;"><?= t('g.required_fields') ?>:</strong> <?= t('products.required_fields_list') ?> + <?= t('products.product_name_en') ?> + <?= t('products.sell_price') ?>. 
        </div>
    </div>
</div>

<!-- فلترة وبحث -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="padding:16px;">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
            <div style="flex:1;min-width:200px;">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="<?= t('products.search_products') ?> " class="form-control">
            </div>
            <select name="category" class="form-control" style="width:180px;">
                <option value=""><?= t('g.all_categories') ?></option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $catFilter == $cat['id'] ? 'selected' : '' ?>><?= catName($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="stock" class="form-control" style="width:150px;">
                <option value=""><?= t('g.all_stock') ?></option>
                <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>><?= t('dash.low_stock') ?></option>
                <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>><?= t('dash.out_of_stock') ?></option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i><?= t('search') ?></button>
            <a href="products" class="btn btn-sm" style="background:#e5e7eb;color:#374151;"><i class="fas fa-times"></i></a>
        </form>
    </div>
</div>

<!-- نموذج الإضافة/التعديل -->
<?php if (isset($_GET['add']) || $editProduct): $p = $editProduct; ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><h3><i class="fas fa-<?= $p ? 'edit' : 'plus' ?>"></i> <?= $p ? t('products.edit_product') : t('products.add_product') ?></h3></div>
    <div class="card-body">
        <form method="POST">
        <?= csrfField() ?>
            <?php if ($p): ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;">
                <div><label><?= t('products.barcode') ?></label><input type="text" name="barcode" value="<?= $p['barcode'] ?? '' ?>" class="form-control" placeholder="<?= t('products.barcode') ?>"></div>
                <div><label>SKU</label><input type="text" name="sku" value="<?= $p['sku'] ?? '' ?>" class="form-control"></div>
                <div><label><?= t('products.product_name') ?> *</label><input type="text" name="name" value="<?= $p['name'] ?? '' ?>" class="form-control" required></div>
                <div><label><?= t('products.product_name_en') ?> *</label><input type="text" name="name_en" value="<?= $p['name_en'] ?? '' ?>" class="form-control" required></div>
                <div><label><?= t('products.generic_name') ?></label><input type="text" name="generic_name" value="<?= $p['generic_name'] ?? '' ?>" class="form-control"></div>
                <div><label><?= t('products.category') ?></label>
                    <select name="category_id" class="form-control">
                        <option value=""><?= t('select') ?></option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($p['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= catName($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label><?= t('products.manufacturer') ?></label><input type="text" name="manufacturer" value="<?= $p['manufacturer'] ?? '' ?>" class="form-control"></div>
                <div><label><?= t('products.concentration') ?></label><input type="text" name="concentration" value="<?= $p['concentration'] ?? '' ?>" class="form-control" placeholder="<?= t('products.concentration') ?>"></div>
                <div><label><?= t('products.dosage_form') ?></label>
                    <select name="dosage_form" class="form-control">
                        <option value=""><?= t('select') ?></option>
                        <?php $dosageForms = ['tablets','capsules','syrup','spray','injection','cream','ointment','drops','suppositories','effervescent','powder','patches','other'];
                        foreach ($dosageForms as $formKey): ?>
                        <option value="<?= $formKey ?>" <?= ($p['dosage_form'] ?? '') == $formKey ? 'selected' : '' ?>><?= $formKey === 'other' ? t('other') : t('products.'.$formKey) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label><?= t('products.unit') ?></label><input type="text" name="unit" value="<?= $p['unit'] ?? t('pieces') ?>" class="form-control"></div>
                <div><label><?= t('products.sell_price') ?> *</label><input type="number" step="0.01" name="unit_price" value="<?= $p['unit_price'] ?? '0' ?>" class="form-control" required></div>
                <div><label><?= t('g.purchase_price') ?></label><input type="number" step="0.01" name="cost_price" value="<?= $p['cost_price'] ?? '0' ?>" class="form-control"></div>
                <div><label><?= t('settings.vat_rate') ?> %</label><input type="number" step="0.01" name="vat_rate" value="<?= $p['vat_rate'] ?? '15' ?>" class="form-control"></div>
                <div><label><?= t('products.min_stock') ?></label><input type="number" name="min_stock" value="<?= $p['min_stock'] ?? '10' ?>" class="form-control"></div>
                <div><label><?= t('products.reorder_point') ?></label><input type="number" name="reorder_point" value="<?= $p['reorder_point'] ?? '20' ?>" class="form-control"></div>
                <div><label><?= t('g.max_stock') ?></label><input type="number" name="max_stock" value="<?= $p['max_stock'] ?? '1000' ?>" class="form-control"></div>
                <?php if (!$p): /* فقط عند الإضافة */ ?>
                <div style="grid-column:1/-1;border-top:2px solid #e2e8f0;padding-top:12px;margin-top:4px;">
                    <h4 style="margin-bottom:10px;color:var(--primary);"><i class="fas fa-boxes"></i> <?= t('g.opening_stock') ?></h4>
                </div>
                <div><label><?= t('g.initial_qty') ?></label><input type="number" name="initial_stock" value="0" min="0" class="form-control" placeholder="0"></div>
                <div><label><?= t('inventory.expiry_date') ?></label><input type="date" name="expiry_date" class="form-control"></div>
                <div><label><?= t('inventory.batch_number') ?> </label><input type="text" name="batch_number" class="form-control" placeholder="LOT-001"></div>
                <?php endif; ?>
                <div><label><input type="checkbox" name="requires_prescription" value="1" <?= ($p['requires_prescription'] ?? 0) ? 'checked' : '' ?>> <?= t('products.requires_prescription') ?></label></div>
            </div>
            <div style="margin-top:12px;"><label><?= t('notes') ?></label><textarea name="notes" class="form-control" rows="2"><?= $p['notes'] ?? '' ?></textarea></div>
            <div style="margin-top:16px;display:flex;gap:10px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i><?= t('save') ?></button>
                <a href="products" class="btn" style="background:#e5e7eb;color:#374151;"><?= t('cancel') ?></a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- جدول المنتجات -->
<div class="card">
    <div class="card-header">
        <div><h3><i class="fas fa-pills"></i> <?= t('products.title') ?></h3><small><?= count($products) ?><?= t('item') ?></small></div>
        <?php if (hasPermission('products_add')): ?>
        <a href="products?add=1" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> <?= t('products.add_product') ?></a>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th><?= t('products.barcode') ?></th>
                    <th><?= t('products.product_name') ?></th>
                    <th><?= t('products.generic_name') ?></th>
                    <th><?= t('products.category') ?></th>
                    <th><?= t('products.dosage_form') ?></th>
                    <th><?= t('products.sell_price') ?></th>
                    <th><?= t('sales.cost') ?></th>
                    <th><?= t('inventory.title') ?></th>
                    <th><?= t('status') ?></th>
                    <th><?= t('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:40px;"><?= t('no_results') ?></td></tr>
            <?php else: foreach ($products as $p):
                $bStock = intval($p['branch_stock']);
                $stockClass = $bStock <= 0 ? 'badge-danger' : ($bStock <= $p['min_stock'] ? 'badge-warning' : 'badge-success');
                $stockLabel = $bStock <= 0 ? t('dash.out_of_stock') : ($bStock <= $p['min_stock'] ? t('dash.low_stock') : t('available'));
            ?>
                <tr>
                    <td style="font-family:monospace;font-size:12px;"><?= $p['barcode'] ?: '-' ?></td>
                    <td dir="auto" style="font-weight:600;"><?= htmlspecialchars(displayName($p, 'name', 'name_en')) ?><?= $p['concentration'] ? ' <small style="color:#6b7280;">'.$p['concentration'].'</small>' : '' ?></td>
                    <td dir="auto" style="color:#6b7280;font-size:13px;"><?= $p['generic_name'] ?: '-' ?></td>
                    <td dir="auto"><?php if ($p['category_name']): $catDisplay = (currentLang() === 'ar' && !empty($p['category_name_ar'])) ? $p['category_name_ar'] : $p['category_name']; ?><span class="badge badge-info"><?= $catDisplay ?></span><?php else: ?>-<?php endif; ?></td>
                    <td dir="auto"><?= $p['dosage_form'] ? dosageFormLabel($p['dosage_form']) : '-' ?></td>
                    <td style="font-weight:600;"><?= formatMoney($p['unit_price']) ?></td>
                    <td style="color:#6b7280;"><?= formatMoney($p['cost_price']) ?></td>
                    <td style="font-weight:700;"><?= $bStock ?></td>
                    <td><span class="badge <?= $stockClass ?>"><?= $stockLabel ?></span></td>
                    <td>
                        <?php if (hasPermission('products_edit')): ?>
                        <a href="products?edit=<?= $p['id'] ?>" class="btn btn-sm" style="background:#dbeafe;color:#1d4ed8;" title="<?= t('edit') ?>"><i class="fas fa-edit"></i></a>
                        <?php endif; ?>
                        <?php if (hasPermission('products_delete')): ?>
                        <a href="#" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;" title="<?= t('delete') ?>" onclick="showDeleteModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal تأكيد الحذف -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
    <div style="background:#fff;border-radius:16px;padding:30px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="width:70px;height:70px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fas fa-exclamation-triangle" style="font-size:32px;color:#dc2626;"></i>
        </div>
        <h3 style="color:#1a2744;margin-bottom:8px;"><?= t('confirm_delete') ?></h3>
        <p style="color:#6b7280;margin-bottom:6px;" id="deleteModalMsg"><?= t('g.confirm_delete_product') ?></p>
        <p style="color:#dc2626;font-weight:700;font-size:15px;margin-bottom:20px;" id="deleteModalName"></p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button onclick="closeDeleteModal()" class="btn" style="background:#e5e7eb;color:#374151;padding:10px 28px;border-radius:8px;font-size:14px;"><i class="fas fa-times"></i> <?= t('cancel') ?></button>
            <a id="deleteModalLink" href="#" class="btn" style="background:#dc2626;color:#fff;padding:10px 28px;border-radius:8px;font-size:14px;"><i class="fas fa-trash"></i> <?= t('delete') ?></a>
        </div>
    </div>
</div>
<script>
function showDeleteModal(id, name) {
    document.getElementById('deleteModalName').textContent = name;
    document.getElementById('deleteModalLink').href = 'products?delete=' + id;
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
document.getElementById('deleteModal').addEventListener('click', function(e) { if (e.target === this) closeDeleteModal(); });
</script>

<?php require_once 'includes/footer.php'; ?>
