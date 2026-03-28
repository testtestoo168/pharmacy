<?php
require_once 'includes/config.php';
$pageTitle = t('inventory.stale_inventory');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('inventory_view');

$tab = $_GET['tab'] ?? 'aging';

// Inventory Aging
$aging = $pdo->prepare("SELECT p.name, p.barcode, ib.batch_number, ib.available_qty, ib.purchase_price, ib.expiry_date, ib.received_date,
    DATEDIFF(CURDATE(), ib.received_date) as days_in_stock,
    (ib.available_qty * ib.purchase_price) as stock_value
    FROM inventory_batches ib JOIN products p ON p.id=ib.product_id
    WHERE ib.tenant_id=? AND ib.branch_id=? AND ib.available_qty>0
    ORDER BY days_in_stock DESC");
$aging->execute([$tid, $bid]);
$agingData = $aging->fetchAll();

// Dead/Slow Stock (no sales in last 90 days)
$deadStock = $pdo->prepare("SELECT p.id, p.name, p.barcode, p.stock_qty, p.cost_price, (p.stock_qty * p.cost_price) as value,
    (SELECT MAX(si.invoice_date) FROM sales_invoice_items sii JOIN sales_invoices si ON si.id=sii.invoice_id WHERE sii.product_id=p.id AND si.tenant_id=? AND si.branch_id=?) as last_sale,
    DATEDIFF(CURDATE(), COALESCE((SELECT MAX(si2.invoice_date) FROM sales_invoice_items sii2 JOIN sales_invoices si2 ON si2.id=sii2.invoice_id WHERE sii2.product_id=p.id AND si2.tenant_id=? AND si2.branch_id=?), p.created_at)) as days_no_sale
    FROM products p WHERE p.tenant_id=? AND p.branch_id=? AND p.is_active=1 AND p.stock_qty>0
    HAVING days_no_sale > 60
    ORDER BY days_no_sale DESC");
$deadStock->execute([$tid,$bid,$tid,$bid,$tid,$bid]);
$deadData = $deadStock->fetchAll();
$deadValue = array_sum(array_column($deadData, 'value'));
?>

<div class="card no-print" style="margin-bottom:16px;"><div class="card-body" style="padding:12px;display:flex;gap:8px;">
    <a href="?tab=aging" class="btn btn-sm <?=$tab==='aging'?'btn-primary':''?>" style="text-decoration:none;padding:8px 16px;"><?= t('inventory.stale_inventory') ?></a>
    <a href="?tab=dead" class="btn btn-sm <?=$tab==='dead'?'btn-primary':''?>" style="text-decoration:none;padding:8px 16px;"><?= t('inventory.stale_inventory') ?> (<?=count($deadData)?>)</a>
    <button class="btn btn-sm btn-info" onclick="window.print()" style="margin-right:auto;"><i class="fas fa-print"></i><?= t('print') ?></button>
</div></div>

<?php if($tab === 'aging'): ?>
<div class="stats-grid" style="margin-bottom:16px;">
    <?php 
    $a30=0;$a60=0;$a90=0;$a90p=0;
    foreach($agingData as $r){
        if($r['days_in_stock']<=30) $a30+=$r['stock_value'];
        elseif($r['days_in_stock']<=60) $a60+=$r['stock_value'];
        elseif($r['days_in_stock']<=90) $a90+=$r['stock_value'];
        else $a90p+=$r['stock_value'];
    } ?>
    <div class="stat-card"><div class="stat-icon" style="background:#dcfce7;"><i class="fas fa-box" style="color:#16a34a;"></i></div><div class="stat-info"><p class="stat-label">0-30 <?= t('days') ?></p><p class="stat-value" style="font-size:18px;color:#16a34a;"><?=formatMoney($a30)?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#fef9c3;"><i class="fas fa-box" style="color:#ca8a04;"></i></div><div class="stat-info"><p class="stat-label">31-60 <?= t('days') ?></p><p class="stat-value" style="font-size:18px;color:#ca8a04;"><?=formatMoney($a60)?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#fed7aa;"><i class="fas fa-box" style="color:#ea580c;"></i></div><div class="stat-info"><p class="stat-label">61-90 <?= t('days') ?></p><p class="stat-value" style="font-size:18px;color:#ea580c;"><?=formatMoney($a90)?></p></div></div>
    <div class="stat-card"><div class="stat-icon" style="background:#fecaca;"><i class="fas fa-box" style="color:#dc2626;"></i></div><div class="stat-info"><p class="stat-label">90+ <?= t('days') ?></p><p class="stat-value" style="font-size:18px;color:#dc2626;"><?=formatMoney($a90p)?></p></div></div>
</div>
<div class="card"><div class="card-header"><h3><i class="fas fa-boxes"></i> <?= t('aging.title') ?> (<?=count($agingData)?>)</h3></div>
    <div class="table-responsive"><table>
        <thead><tr><th><?= t('products.product_name') ?></th><th><?= t('batch') ?></th><th><?= t('quantity') ?></th><th><?= t('amount') ?></th><th><?= t('g.received_date') ?></th><th><?= t('days') ?></th><th><?= t('inventory.expiry_date') ?></th></tr></thead>
        <tbody>
        <?php foreach($agingData as $r): $color = $r['days_in_stock']<=30?'#16a34a':($r['days_in_stock']<=60?'#ca8a04':($r['days_in_stock']<=90?'#ea580c':'#dc2626')); ?>
        <tr>
            <td style="font-weight:600;"><?=htmlspecialchars($r['name'])?></td>
            <td style="font-size:12px;"><?=$r['batch_number']??'-'?></td>
            <td><?=$r['available_qty']?></td>
            <td><?=formatMoney($r['stock_value'])?></td>
            <td style="font-size:12px;"><?=$r['received_date']??'-'?></td>
            <td style="font-weight:600;color:<?=$color?>;"><?=$r['days_in_stock']?><?= t('day') ?></td>
            <td style="font-size:12px;"><?=$r['expiry_date']??'-'?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>

<?php else: ?>
<div class="card" style="margin-bottom:16px;"><div class="card-body" style="padding:16px;display:flex;justify-content:space-between;align-items:center;">
    <div><strong><?= t('g.total_stale') ?>:</strong> <?=count($deadData)?><?= t('item') ?></div>
    <div><strong><?= t('g.frozen_value') ?>:</strong> <span style="color:#dc2626;font-weight:700;"><?=formatMoney($deadValue)?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></span></div>
</div></div>
<div class="card"><div class="card-header"><h3><i class="fas fa-exclamation-triangle"></i> <?= t('inventory.stale_inventory') ?></h3></div>
    <div class="table-responsive"><table>
        <thead><tr><th><?= t('products.product_name') ?></th><th><?= t('products.barcode') ?></th><th><?= t('inventory.title') ?></th><th><?= t('sales.cost') ?></th><th><?= t('g.frozen_value') ?></th><th><?= t('g.last_sale') ?></th><th><?= t('g.days_no_sale') ?></th></tr></thead>
        <tbody>
        <?php if(empty($deadData)): ?><tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;"><?= t('no_results') ?></td></tr>
        <?php else: foreach($deadData as $r): ?>
        <tr>
            <td style="font-weight:600;"><?=htmlspecialchars($r['name'])?></td>
            <td style="font-size:12px;"><?=$r['barcode']??'-'?></td>
            <td><?=$r['stock_qty']?></td>
            <td><?=formatMoney($r['cost_price'])?></td>
            <td style="font-weight:700;color:#dc2626;"><?=formatMoney($r['value'])?></td>
            <td style="font-size:12px;"><?=$r['last_sale']??t('inventory.not_sold')?></td>
            <td style="font-weight:600;color:#dc2626;"><?=$r['days_no_sale']?><?= t('day') ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>
<?php require_once 'includes/footer.php'; ?>
