<?php
require_once 'includes/config.php';
$pageTitle = t('nav.dashboard');
require_once 'includes/header.php';

// إحصائيات مع عزل البيانات (Tenant + Branch)
$tid = getTenantId();
$bid = getBranchId();
$_ts = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) as total, COUNT(*) as cnt FROM sales_invoices WHERE tenant_id=? AND branch_id=? AND invoice_date = CURDATE()"); $_ts->execute([$tid,$bid]); $todaySales = $_ts->fetch();
$_ms = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) as total, COUNT(*) as cnt FROM sales_invoices WHERE tenant_id=? AND branch_id=? AND MONTH(invoice_date)=MONTH(CURDATE()) AND YEAR(invoice_date)=YEAR(CURDATE())"); $_ms->execute([$tid,$bid]); $monthSales = $_ms->fetch();
$_tp = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN inventory_batches ib ON ib.product_id=p.id WHERE p.tenant_id=? AND p.is_active=1 AND ib.branch_id=$bid"); $_tp->execute([$tid]); $totalProducts = $_tp->fetchColumn();
$_ls = $pdo->prepare("SELECT COUNT(*) FROM products WHERE tenant_id=? AND branch_id=? AND is_active=1 AND stock_qty <= min_stock AND stock_qty > 0"); $_ls->execute([$tid, $bid]); $lowStock = $_ls->fetchColumn();
$_os = $pdo->prepare("SELECT COUNT(*) FROM products WHERE tenant_id=? AND branch_id=? AND is_active=1 AND stock_qty = 0"); $_os->execute([$tid, $bid]); $outOfStock = $_os->fetchColumn();
$expiringCount = 0; $expiredCount = 0;
try {
    $_ec = $pdo->prepare("SELECT COUNT(*) FROM inventory_batches WHERE tenant_id=? AND branch_id=? AND available_qty > 0 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"); $_ec->execute([$tid,$bid]); $expiringCount = $_ec->fetchColumn();
    $_xc = $pdo->prepare("SELECT COUNT(*) FROM inventory_batches WHERE tenant_id=? AND branch_id=? AND available_qty > 0 AND expiry_date < CURDATE()"); $_xc->execute([$tid,$bid]); $expiredCount = $_xc->fetchColumn();
} catch (Exception $e) {}

$_ws = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales_invoices WHERE tenant_id=? AND branch_id=? AND YEARWEEK(invoice_date,1)=YEARWEEK(CURDATE(),1)"); $_ws->execute([$tid,$bid]); $weekSales = $_ws->fetchColumn();
$_tc = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id=?"); $_tc->execute([$tid]); $totalCustomers = $_tc->fetchColumn();
$_tpu = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM purchase_invoices WHERE tenant_id=? AND branch_id=? AND MONTH(invoice_date)=MONTH(CURDATE()) AND YEAR(invoice_date)=YEAR(CURDATE())"); $_tpu->execute([$tid,$bid]); $totalPurchases = $_tpu->fetchColumn();
?>

<!-- الإحصائيات -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-cash-register"></i></div>
        <div class="stat-info">
            <p class="stat-label"><?= t('dash.today_sales') ?></p>
            <p class="stat-value"><?= formatMoney($todaySales['total']) ?> <small><span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></small></p>
            <p class="stat-change positive"><?= $todaySales['cnt'] ?> <?= t('invoice') ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
        <div class="stat-info">
            <p class="stat-label"><?= t('dash.month_sales') ?></p>
            <p class="stat-value"><?= formatMoney($monthSales['total']) ?> <small><span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></small></p>
            <p class="stat-change positive"><?= $monthSales['cnt'] ?> <?= t('invoice') ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-info">
            <p class="stat-label"><?= t('dash.stock_alerts') ?></p>
            <p class="stat-value"><?= $lowStock + $outOfStock ?> <small><?= t('item') ?></small></p>
            <p class="stat-change negative"><?= $outOfStock ?> <?= t('dash.out_of_stock') ?> — <?= $lowStock ?> <?= t('dash.low_stock') ?></p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
        <div class="stat-info">
            <p class="stat-label"><?= t('dash.expiry_alerts') ?></p>
            <p class="stat-value"><?= $expiringCount + $expiredCount ?> <small><?= t('batch') ?></small></p>
            <p class="stat-change negative"><?= $expiredCount ?> <?= t('dash.expired') ?> — <?= $expiringCount ?> <?= t('dash.near_expiry') ?></p>
        </div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
        <div class="stat-info"><p class="stat-label"><?= t('dash.week_sales') ?></p><p class="stat-value" style="font-size:20px;"><?= formatMoney($weekSales) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-pills"></i></div>
        <div class="stat-info"><p class="stat-label"><?= t('dash.total_items') ?></p><p class="stat-value" style="font-size:20px;"><?= $totalProducts ?></p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
        <div class="stat-info"><p class="stat-label"><?= t('dash.month_purchases') ?></p><p class="stat-value" style="font-size:20px;"><?= formatMoney($totalPurchases) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div class="stat-info"><p class="stat-label"><?= t('dash.customers_count') ?></p><p class="stat-value" style="font-size:20px;"><?= $totalCustomers ?></p></div>
    </div>
</div>

<!-- اختصارات -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px;">
    <a href="pos" style="text-decoration:none;display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);color:var(--foreground);padding:20px;border-radius:var(--radius);font-weight:600;font-size:14px;transition:all 0.2s;" onmouseover="this.style.background='var(--secondary)'" onmouseout="this.style.background='var(--card)'"><i class="fas fa-cash-register" style="font-size:20px;color:var(--primary);"></i> <?= t('pos.title') ?></a>
    <a href="products?add=1" style="text-decoration:none;display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);color:var(--foreground);padding:20px;border-radius:var(--radius);font-weight:600;font-size:14px;transition:all 0.2s;" onmouseover="this.style.background='var(--secondary)'" onmouseout="this.style.background='var(--card)'"><i class="fas fa-plus-circle" style="font-size:20px;color:var(--primary);"></i> <?= t('products.add_product') ?></a>
    <a href="purchases_new" style="text-decoration:none;display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);color:var(--foreground);padding:20px;border-radius:var(--radius);font-weight:600;font-size:14px;transition:all 0.2s;" onmouseover="this.style.background='var(--secondary)'" onmouseout="this.style.background='var(--card)'"><i class="fas fa-shopping-cart" style="font-size:20px;color:var(--primary);"></i> <?= t('purchases.new_invoice') ?></a>
    <a href="inventory?tab=expiring" style="text-decoration:none;display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);color:var(--foreground);padding:20px;border-radius:var(--radius);font-weight:600;font-size:14px;transition:all 0.2s;" onmouseover="this.style.background='var(--secondary)'" onmouseout="this.style.background='var(--card)'"><i class="fas fa-exclamation-triangle" style="font-size:20px;color:var(--primary);"></i> <?= t('dash.expiry_alerts') ?></a>
</div>

<?php
// رسم بياني - مبيعات آخر 7 أيام
$_cd = $pdo->prepare("SELECT DATE_FORMAT(invoice_date,'%m/%d') as lbl, COALESCE(SUM(grand_total),0) as total FROM sales_invoices WHERE tenant_id=? AND branch_id=? AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY invoice_date ORDER BY invoice_date"); $_cd->execute([$tid,$bid]); $chartData = $_cd->fetchAll();
$chartLabels = array_column($chartData, 'lbl');
$chartValues = array_column($chartData, 'total');

// آخر الفواتير
$_rs = $pdo->prepare("SELECT si.*, c.name as customer_name, u.full_name as cashier FROM sales_invoices si LEFT JOIN customers c ON si.customer_id = c.id LEFT JOIN users u ON u.id = si.created_by WHERE si.tenant_id=? AND si.branch_id=? ORDER BY si.id DESC LIMIT 8"); $_rs->execute([$tid,$bid]); $recentSales = $_rs->fetchAll();

// أكثر الأدوية مبيعاً هذا الشهر
$_top = $pdo->prepare("SELECT sii.product_name, p.name_en as product_name_en, SUM(sii.quantity) as total_qty, SUM(sii.total_amount) as total_amount FROM sales_invoice_items sii JOIN sales_invoices si ON si.id = sii.invoice_id LEFT JOIN products p ON p.id = sii.product_id WHERE si.tenant_id=? AND si.branch_id=? AND MONTH(si.invoice_date)=MONTH(CURDATE()) AND YEAR(si.invoice_date)=YEAR(CURDATE()) GROUP BY sii.product_name, p.name_en ORDER BY total_qty DESC LIMIT 5"); $_top->execute([$tid,$bid]); $topProducts = $_top->fetchAll();
?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;">
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-chart-bar"></i><?= t('dash.daily_sales') ?></h3></div>
        <div class="card-body"><canvas id="salesChart" height="250"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><h3><i class="fas fa-fire"></i><?= t('dash.top_selling') ?></h3></div>
        <div class="card-body">
            <?php if (empty($topProducts)): ?>
            <p class="text-center text-muted" style="padding:40px;"><?= t('no_results') ?></p>
            <?php else: foreach ($topProducts as $i => $tp): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;background:<?= ['#f59e0b','#94a3b8','#d97706','#6b7280','#6b7280'][$i] ?>;"><?= $i+1 ?></span>
                    <span style="font-size:14px;font-weight:600;" dir="auto"><?= displayName($tp, 'product_name', 'product_name_en') ?></span>
                </div>
                <div style="text-align:left;"><div style="font-weight:700;color:#1d4ed8;"><?= formatMoney($tp['total_amount']) ?></div><div style="font-size:11px;color:#6b7280;"><?= $tp['total_qty'] ?> <?= t('pieces') ?></div></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- آخر الفواتير -->
<div class="card">
    <div class="card-header">
        <div><h3><i class="fas fa-file-invoice-dollar"></i><?= t('dash.recent_invoices') ?></h3></div>
        <a href="pos" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> <?= t('dash.new_sale') ?></a>
    </div>
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('sales.invoice_number') ?></th><th><?= t('sales.customer') ?></th><th><?= t('sales.payment_method') ?></th><th><?= t('total') ?></th><th><?= t('users.cashier') ?></th><th><?= t('date') ?></th></tr></thead>
            <tbody>
            <?php if (empty($recentSales)): ?>
            <tr><td colspan="6" class="text-center text-muted" style="padding:32px;"><?= t('no_results') ?></td></tr>
            <?php else: foreach ($recentSales as $s): ?>
            <tr>
                <td style="font-weight:600;"><?= $s['invoice_number'] ?></td>
                <td dir="auto"><?= $s['customer_name'] ?? t('sales.cash_customer') ?></td>
                <td><?= paymentTypeBadge($s['payment_type']) ?></td>
                <td style="font-weight:700;"><?= formatMoney($s['grand_total']) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td>
                <td dir="auto" style="color:#6b7280;"><?= $s['cashier'] ?? '-' ?></td>
                <td style="color:#6b7280;font-size:13px;"><?= $s['invoice_date'] ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Tajawal', sans-serif";
const ctx = document.getElementById('salesChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: t('dash.sales_chart'),
            data: <?= json_encode(array_map('floatval', $chartValues)) ?>,
            backgroundColor: '#1d4ed8',
            borderRadius: 4,
            barPercentage: 0.7,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { callback: v => v.toLocaleString() } },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php require_once 'includes/footer.php'; ?>
