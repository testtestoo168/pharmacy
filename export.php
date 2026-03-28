<?php
require_once 'includes/config.php';
requireLogin();
$tid = getTenantId();
$bid = getBranchId();
$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'xlsx';
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? date('Y-m-d');

// تحقق: هل الفرع الحالي هو الفرع الرئيسي؟
$_isMain = false;
try { $_bCheck = $pdo->prepare("SELECT is_main FROM branches WHERE id=? AND tenant_id=?"); $_bCheck->execute([$bid,$tid]); $_isMain = (bool)$_bCheck->fetchColumn(); } catch(Exception $e) {}
if ($_isMain) {
    $branchId = intval($_GET['branch_id'] ?? 0);
} else {
    $branchId = $bid;
}

// Get branch name
$branchName = '';
if ($branchId) {
    try { $br = $pdo->prepare("SELECT name FROM branches WHERE id=? AND tenant_id=?"); $br->execute([$branchId,$tid]); $branchName = $br->fetchColumn() ?: ''; } catch(Exception $e) {}
}

$data = []; $headers = []; $filename = 'export'; $reportTitle = '';

switch ($type) {
    case 'sales':
        requirePermission('reports_view');
        $reportTitle = t('reports.sales_report');
        $filename = ($branchName ? "{$branchName}_" : '') . "sales_{$fromDate}_{$toDate}";
        $headers = [t('sales.invoice_number'),t('date'),t('sales.customer'),t('sales.payment_method'),'الصافي','الضريبة',t('total'),t('status')];
        $brFilter = $branchId ? " AND si.branch_id = $branchId" : "";
        $stmt = $pdo->prepare("SELECT si.invoice_number, si.invoice_date, COALESCE(c.name,'" . t('sales.cash_customer') . "'),
            CASE si.payment_type WHEN 'cash' THEN '" . t('pay.cash') . "' WHEN 'card' THEN '" . t('pay.card') . "' WHEN 'network' THEN '" . t('pay.card') . "' WHEN 'transfer' THEN '" . t('pay.transfer') . "' WHEN 'credit' THEN '" . t('pay.credit') . "' WHEN 'insurance' THEN '" . t('pay.insurance') . "' ELSE si.payment_type END,
            si.net_total, si.vat_amount, si.grand_total,
            CASE si.status WHEN 'completed' THEN '" . t('st.completed_f') . "' WHEN 'returned' THEN '" . t('st.returned') . "' WHEN 'partial_return' THEN '" . t('st.partial_return') . "' WHEN 'held' THEN '" . t('pending') . "' WHEN 'cancelled' THEN '" . t('cancelled') . "' ELSE si.status END
            FROM sales_invoices si LEFT JOIN customers c ON c.id=si.customer_id WHERE si.tenant_id=? AND si.status!='held' AND si.invoice_date BETWEEN ? AND ? $brFilter ORDER BY si.invoice_date DESC");
        $stmt->execute([$tid,$fromDate,$toDate]);
        $data = $stmt->fetchAll();
        break;
        
    case 'purchases':
        requirePermission('reports_view');
        $reportTitle = t('reports.purchase_report');
        $filename = ($branchName ? "{$branchName}_" : '') . "purchases_{$fromDate}_{$toDate}";
        $headers = [t('sales.invoice_number'),t('date'),t('purchases.supplier'),'الصافي','الضريبة',t('total'),t('sales.paid_amount'),t('remaining')];
        $brFilter = $branchId ? " AND pi.branch_id = $branchId" : "";
        $stmt = $pdo->prepare("SELECT pi.invoice_number, pi.invoice_date, COALESCE(s.name,'-'), pi.net_total, pi.vat_amount, pi.grand_total, pi.paid_amount, pi.remaining_amount FROM purchase_invoices pi LEFT JOIN suppliers s ON s.id=pi.supplier_id WHERE pi.tenant_id=? AND pi.invoice_date BETWEEN ? AND ? $brFilter ORDER BY pi.invoice_date DESC");
        $stmt->execute([$tid,$fromDate,$toDate]);
        $data = $stmt->fetchAll();
        break;
        
    case 'products':
        requirePermission('products_view');
        $reportTitle = t('products.current_stock');
        $filename = ($branchName ? "{$branchName}_" : '') . "inventory";
        $headers = [t('products.barcode'),t('products.product_name'),t('products.generic_name'),t('products.category'),t('products.sell_price'),t('products.cost_price'),t('products.current_stock'),t('products.min_stock'),t('status')];
        $stmt = $pdo->prepare("SELECT p.barcode, p.name, p.generic_name, COALESCE(c.name,'-'), p.unit_price, p.cost_price, p.stock_qty, p.min_stock, CASE WHEN p.stock_qty=0 THEN '" . t('dash.out_of_stock') . "' WHEN p.stock_qty<=p.min_stock THEN '" . t('dash.low_stock') . "' ELSE '" . t('available') . "' END FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.tenant_id=? AND p.branch_id=? AND p.is_active=1 ORDER BY p.name");
        $stmt->execute([$tid,$bid]);
        $data = $stmt->fetchAll();
        break;
        
    case 'customers':
        requirePermission('customers_view');
        $reportTitle = t('reports.customer_report');
        $filename = ($branchName ? "{$branchName}_" : '') . "customers";
        $headers = [t('name'),'الجوال',t('email'),t('type'),'إجمالي المشتريات',t('balance'),t('customers.loyalty_points')];
        $stmt = $pdo->prepare("SELECT name, phone, email, 
            CASE type WHEN 'individual' THEN '" . t('g.individual') . "' WHEN 'company' THEN '" . t('g.company') . "' WHEN 'insurance' THEN '" . t('g.insurance') . "' WHEN 'government' THEN '" . t('exp.government') . "' ELSE COALESCE(type,'" . t('g.individual') . "') END,
            total_purchases, balance, loyalty_points FROM customers WHERE tenant_id=? ORDER BY name");
        $stmt->execute([$tid]);
        $data = $stmt->fetchAll();
        break;
        
    case 'suppliers':
        requirePermission('suppliers_view');
        $reportTitle = t('reports.supplier_report');
        $filename = ($branchName ? "{$branchName}_" : '') . "suppliers";
        $headers = [t('name'),t('customers.contact_person'),'الجوال',t('email'),t('settings.tax_number'),t('balance')];
        $stmt = $pdo->prepare("SELECT name, contact_person, phone, email, tax_number, balance FROM suppliers WHERE tenant_id=? ORDER BY name");
        $stmt->execute([$tid]);
        $data = $stmt->fetchAll();
        break;

    case 'expenses':
        requirePermission('expenses_view');
        $reportTitle = t('expenses.title');
        $filename = ($branchName ? "{$branchName}_" : '') . "expenses_{$fromDate}_{$toDate}";
        $headers = ['الرقم',t('date'),t('products.category'),t('amount'),'الضريبة',t('total'),t('description'),t('sales.payment_method')];
        $stmt = $pdo->prepare("SELECT expense_number, expense_date, category, amount, vat_amount, total, description,
            CASE payment_method WHEN 'cash' THEN '" . t('pay.cash') . "' WHEN 'card' THEN '" . t('pay.card') . "' WHEN 'transfer' THEN '" . t('pay.transfer') . "' WHEN 'check' THEN '" . t('pay.check') . "' ELSE payment_method END
            FROM expenses WHERE tenant_id=? AND expense_date BETWEEN ? AND ? ORDER BY expense_date DESC");
        $stmt->execute([$tid,$fromDate,$toDate]);
        $data = $stmt->fetchAll();
        break;

    case 'expiring':
        requirePermission('inventory_view');
        $reportTitle = t('dash.expiring_soon');
        $filename = ($branchName ? "{$branchName}_" : '') . "near_expiry";
        $headers = ['الدواء',t('inventory.batch_number'),t('inventory.available_qty'),t('inventory.expiry_date'),'الأيام المتبقية'];
        $stmt = $pdo->prepare("SELECT p.name, ib.batch_number, ib.available_qty, ib.expiry_date, DATEDIFF(ib.expiry_date, CURDATE()) FROM inventory_batches ib JOIN products p ON p.id=ib.product_id WHERE ib.tenant_id=? AND ib.branch_id=? AND ib.available_qty>0 AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY ib.expiry_date ASC");
        $stmt->execute([$tid,$bid]);
        $data = $stmt->fetchAll();
        break;

    case 'profits':
        requirePermission('reports_view');
        $reportTitle = t('reports.profit_report');
        $filename = ($branchName ? "{$branchName}_" : '') . "profit_{$fromDate}_{$toDate}";
        $headers = ['الصنف',t('quantity'),'الإيراد',t('products.cost_price'),'الربح',t('products.profit_margin')];
        $brFilter = $branchId ? " AND si.branch_id = $branchId" : "";
        $stmt = $pdo->prepare("SELECT sii.product_name, SUM(sii.quantity), SUM(sii.quantity * sii.unit_price), SUM(sii.quantity * sii.cost_price), SUM(sii.quantity * sii.unit_price) - SUM(sii.quantity * sii.cost_price), CASE WHEN SUM(sii.quantity * sii.unit_price)>0 THEN ROUND((SUM(sii.quantity * sii.unit_price) - SUM(sii.quantity * sii.cost_price))/SUM(sii.quantity * sii.unit_price)*100,1) ELSE 0 END FROM sales_invoice_items sii JOIN sales_invoices si ON si.id=sii.invoice_id WHERE si.tenant_id=? AND si.status IN ('completed','partial_return') AND si.invoice_date BETWEEN ? AND ? $brFilter GROUP BY sii.product_name ORDER BY SUM(sii.quantity) DESC");
        $stmt->execute([$tid,$fromDate,$toDate]);
        $data = $stmt->fetchAll();
        break;

    case 'top_selling':
        requirePermission('reports_view');
        $reportTitle = t('dash.top_selling');
        $filename = ($branchName ? "{$branchName}_" : '') . "top_selling_{$fromDate}_{$toDate}";
        $headers = [t('item'),t('quantity'),t('sales.invoice_count'),t('sales.revenue')];
        $brFilter = $branchId ? " AND si.branch_id = $branchId" : "";
        $stmt = $pdo->prepare("SELECT sii.product_name, SUM(sii.quantity), COUNT(DISTINCT sii.invoice_id), SUM(sii.total_amount) FROM sales_invoice_items sii JOIN sales_invoices si ON si.id=sii.invoice_id WHERE si.tenant_id=? AND si.status IN ('completed','partial_return') AND si.invoice_date BETWEEN ? AND ? $brFilter GROUP BY sii.product_name ORDER BY SUM(sii.quantity) DESC LIMIT 50");
        $stmt->execute([$tid,$fromDate,$toDate]);
        $data = $stmt->fetchAll();
        break;

    case 'low_stock':
        requirePermission('reports_view');
        $reportTitle = t('inventory.low_stock');
        $filename = ($branchName ? "{$branchName}_" : '') . "low_stock";
        $headers = ['الصنف',t('products.category'),'المتوفر',t('products.min_stock'),t('products.reorder_point'),t('status')];
        $stmt = $pdo->prepare("SELECT p.name, COALESCE(c.name,'-'), p.stock_qty, p.min_stock, p.reorder_point, CASE WHEN p.stock_qty=0 THEN '" . t('dash.out_of_stock') . "' ELSE '" . t('dash.low_stock') . "' END FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.tenant_id=? AND p.branch_id=? AND p.is_active=1 AND p.stock_qty<=p.min_stock ORDER BY p.stock_qty");
        $stmt->execute([$tid,$bid]);
        $data = $stmt->fetchAll();
        break;

    case 'returns':
        requirePermission('reports_view');
        $reportTitle = t('sales.returns');
        $filename = ($branchName ? "{$branchName}_" : '') . "returns_{$fromDate}_{$toDate}";
        $headers = ['رقم المرتجع',t('sales.invoice_number'),t('date'),'السبب',t('amount'),'المستخدم'];
        $brFilter = $branchId ? " AND sr.branch_id = $branchId" : "";
        $stmt = $pdo->prepare("SELECT sr.return_number, si.invoice_number, sr.return_date, COALESCE(sr.reason,'-'), sr.total_amount, COALESCE(u.full_name,'-') FROM sales_returns sr JOIN sales_invoices si ON si.id=sr.invoice_id LEFT JOIN users u ON u.id=sr.created_by WHERE sr.tenant_id=? $brFilter AND sr.return_date BETWEEN ? AND ? ORDER BY sr.id DESC");
        $stmt->execute([$tid,$fromDate,$toDate]);
        $data = $stmt->fetchAll();
        break;

    default:
        die(t('error'));
}

// ============================================================
// EXCEL Export (.xlsx)
// ============================================================
if ($format === 'xlsx') {
    require_once 'includes/helpers/XlsxWriter.php';
    $xlsx = new XlsxWriter($reportTitle ?: $filename);
    $xlsx->setHeaders($headers);
    $xlsx->addRows($data);
    $xlsx->download($filename);
    exit;
}

// ============================================================
// PDF-Ready Print
// ============================================================
if ($format === 'print') {
    $company = getCompanySettings($pdo);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title><?=$reportTitle ?: $filename?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Tajawal',sans-serif; padding:0; direction:rtl; font-size:12px; color:#1d1d2e; background:#eee; }

.no-print { text-align:center; padding:14px; background:#fff; margin-bottom:0; }
.no-print button { padding:8px 24px; background:#1a2744; color:#fff; border:none; border-radius:4px; cursor:pointer; font-family:'Tajawal'; font-size:13px; margin:0 4px; }
.no-print a { padding:8px 24px; background:#6b7280; color:#fff; border-radius:4px; font-family:'Tajawal'; font-size:13px; text-decoration:none; margin:0 4px; }

.page { width:210mm; margin:0 auto; background:#fff; border:1px solid #d1d5db; min-height:297mm; display:flex; flex-direction:column; }

.pg-header { display:flex; justify-content:space-between; align-items:flex-start; padding:16px 24px 12px; border-bottom:2px solid #1a2744; }
.pg-header .right { text-align:right; flex:1; }
.pg-header .right h2 { font-size:16px; font-weight:800; color:#1a2744; }
.pg-header .right p { font-size:10px; color:#6b7280; line-height:1.7; }
.pg-header .center { text-align:center; padding:0 14px; }
.pg-header .center img { width:60px; }
.pg-header .left { text-align:left; flex:1; direction:ltr; }
.pg-header .left h2 { font-size:13px; font-weight:700; color:#1a2744; }
.pg-header .left p { font-size:10px; color:#6b7280; line-height:1.7; }

.pg-title { text-align:center; padding:10px 24px; border-bottom:1px solid #e5e7eb; background:#f8f9fa; }
.pg-title h3 { font-size:16px; font-weight:800; color:#1a2744; margin-bottom:2px; }
.pg-title .branch { font-size:12px; font-weight:600; color:#374151; }
.pg-title .period { font-size:11px; color:#6b7280; margin-top:2px; }

.pg-body { flex:1; padding:14px 20px; }
table { width:100%; border-collapse:collapse; }
thead { display:table-header-group; }
th { background:#1a2744; color:#fff; padding:8px 10px; font-size:11px; font-weight:700; text-align:right; }
td { padding:6px 10px; border-bottom:1px solid #e5e7eb; font-size:11px; }
tbody tr:nth-child(even) { background:#fafbfc; }
tfoot tr { background:#1a2744; color:#fff; font-weight:700; }
tfoot td { padding:8px 10px; font-size:11px; border:none; }

.pg-footer { border-top:2px solid #1a2744; padding:10px 24px; display:flex; justify-content:space-between; font-size:9px; color:#6b7280; margin-top:auto; }

@media print {
    body { background:#fff !important; padding:0; margin:0; }
    .no-print { display:none !important; }
    .page { border:none !important; width:100% !important; min-height:auto; }
    th { background:#1a2744 !important; color:#fff !important; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    tfoot tr { background:#1a2744 !important; color:#fff !important; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    tbody tr:nth-child(even) { background:#f8f9fa !important; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    .pg-title { background:#f8f9fa !important; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    tbody tr { break-inside:avoid; page-break-inside:avoid; }
    @page { size:A4; margin:8mm 10mm; }
}
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()"><?= t('g.print_pdf') ?></button>
    <a href="javascript:history.back()"><?= t('back') ?></a>
</div>

<div class="page">
    <div class="pg-header">
        <div class="right">
            <h2><?=htmlspecialchars($company['company_name']??t('app_name'))?></h2>
            <p><?= t('prt.cr') ?> <?=$company['cr_number']??''?> | ض: <?=$company['tax_number']??''?></p>
            <p><?=$company['phone']??''?></p>
        </div>
        <div class="center">
            <img src="<?=!empty($company['logo'])&&file_exists($company['logo'])?htmlspecialchars($company['logo']):'assets/logo.png'?>" alt="Logo">
        </div>
        <div class="left">
            <h2><?=htmlspecialchars($company['company_name_en']??'URS Pharmacy')?></h2>
            <p>C.R: <?=$company['cr_number']??''?> | Tax: <?=$company['tax_number']??''?></p>
            <p><?=$company['phone']??''?></p>
        </div>
    </div>

    <div class="pg-title">
        <h3><?=$reportTitle ?: $filename?></h3>
        <?php if ($branchName): ?><div class="branch"><?= t('g.branch') ?>: <?=htmlspecialchars($branchName)?></div><?php endif; ?>
        <div class="period"><?= t('prt.period') ?> <?=$fromDate?> — <?=$toDate?> | <?= t('prt.generated_at') ?>: <?=date('Y-m-d H:i')?></div>
    </div>

    <div class="pg-body">
        <table>
            <thead><tr><?php foreach($headers as $h): ?><th><?=$h?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if(empty($data)): ?>
                <tr><td colspan="<?=count($headers)?>" style="text-align:center;padding:30px;color:#9ca3af;"><?= t('no_results') ?></td></tr>
            <?php else: ?>
                <?php foreach($data as $row): ?><tr><?php foreach(array_values($row) as $v): ?><td><?=htmlspecialchars($v)?></td><?php endforeach; ?></tr><?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot><tr><td colspan="<?=count($headers)?>" style="text-align:center;"><?= t('total') ?>: <?=count($data)?></td></tr></tfoot>
        </table>
    </div>

    <div class="pg-footer">
        <div><?=$company['address']??''?></div>
        <div><?=$branchName?htmlspecialchars($branchName).' — ':''?>URS Pharmacy System — <?=date('Y-m-d H:i')?></div>
    </div>
</div>

</body></html>
<?php exit; }
