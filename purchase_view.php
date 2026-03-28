<?php
require_once 'includes/config.php';
$pageTitle = t('purchases.view_invoice');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('purchases_view');

$invId = intval($_GET['id'] ?? 0);
if (!$invId) { echo '<div class="alert alert-danger">' . t('g.not_found') . '</div>'; require_once 'includes/footer.php'; exit; }

$inv = $pdo->prepare("SELECT pi.*, s.name as supplier_name, s.phone as supplier_phone, s.tax_number as supplier_tax, 
                       u.full_name as created_by_name, b.name as branch_name
                       FROM purchase_invoices pi 
                       LEFT JOIN suppliers s ON pi.supplier_id = s.id 
                       LEFT JOIN users u ON pi.created_by = u.id 
                       LEFT JOIN branches b ON pi.branch_id = b.id
                       WHERE pi.id = ? AND pi.tenant_id = $tid AND pi.branch_id = $bid");
$inv->execute([$invId]);
$invoice = $inv->fetch();

if (!$invoice) { echo '<div class="alert alert-danger">' . t('g.not_found') . '</div>'; require_once 'includes/footer.php'; exit; }

$items = $pdo->prepare("SELECT pii.*, p.barcode, p.unit, p.stock_qty
                        FROM purchase_invoice_items pii 
                        LEFT JOIN products p ON pii.product_id = p.id 
                        WHERE pii.invoice_id = ?");
$items->execute([$invId]);
$invoiceItems = $items->fetchAll();

// القيد المحاسبي المرتبط
$je = $pdo->prepare("SELECT je.*, (SELECT GROUP_CONCAT(CONCAT(a.name, ': D ', jel.debit, ' / C ', jel.credit) SEPARATOR ' | ')
                     FROM journal_entry_lines jel LEFT JOIN accounts a ON jel.account_id = a.id WHERE jel.entry_id = je.id) as lines_summary
                     FROM journal_entries je WHERE je.tenant_id = $tid AND je.reference_type = 'purchase_invoice' AND je.reference_id = ?");
$je->execute([$invId]);
$journalEntry = $je->fetch();

// inventory.movements المرتبطة
$movements = $pdo->prepare("SELECT im.*, p.name as product_name FROM inventory_movements im LEFT JOIN products p ON im.product_id = p.id WHERE im.tenant_id = $tid AND im.reference_type = 'purchase_invoice' AND im.reference_id = ?");
$movements->execute([$invId]);
$invMovements = $movements->fetchAll();

$statusLabels = ['paid' => [t('paid'),'badge-success'], 'partial' => [t('partial'),'badge-warning'], 'unpaid' => [t('unpaid'),'badge-danger']];
$st = $statusLabels[$invoice['payment_status'] ?? 'unpaid'] ?? [t('unknown'),'badge-secondary'];
?>

<div style="display:flex;gap:10px;margin-bottom:15px;">
    <a href="purchases" class="btn btn-secondary"><i class="fas fa-arrow-right"></i><?= t('g.back_to_list') ?></a>
    <a href="purchase_print?id=<?= $invId ?>" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i><?= t('g.print_purchase') ?></a>
    <button onclick="window.print()" class="btn btn-info"><i class="fas fa-print"></i><?= t('print') ?></button>
    <a href="purchase_return?invoice_id=<?= $invId ?>" class="btn btn-warning"><i class="fas fa-undo"></i><?= t('sales.returns') ?></a>
</div>

<!-- بيانات الفاتورة -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-invoice"></i> <?= t('purchases.title') ?>: <?= $invoice['invoice_number'] ?></h3>
        <span class="badge <?= $st[1] ?>" style="font-size:14px;padding:6px 14px;"><?= $st[0] ?></span>
    </div>
    <div class="card-body">
        <div class="form-row">
            <div class="form-group">
                <label style="color:#94a3b8;font-size:12px;"><?= t('sales.invoice_number') ?></label>
                <div style="font-weight:700;font-size:16px;color:#0f3460;"><?= $invoice['invoice_number'] ?></div>
            </div>
            <div class="form-group">
                <label style="color:#94a3b8;font-size:12px;"><?= t('g.supplier_invoice') ?></label>
                <div><?= $invoice['supplier_invoice_no'] ?: '-' ?></div>
            </div>
            <div class="form-group">
                <label style="color:#94a3b8;font-size:12px;"><?= t('purchases.supplier') ?></label>
                <div><strong><?= $invoice['supplier_name'] ?></strong>
                <?= $invoice['supplier_tax'] ? '<br><small>ض: '.$invoice['supplier_tax'].'</small>' : '' ?></div>
            </div>
            <div class="form-group">
                <label style="color:#94a3b8;font-size:12px;"><?= t('date') ?></label>
                <div><?= $invoice['invoice_date'] ?></div>
            </div>
            <div class="form-group">
                <label style="color:#94a3b8;font-size:12px;"><?= t('g.branch') ?></label>
                <div><?= $invoice['branch_name'] ?? '-' ?></div>
            </div>
            <div class="form-group">
                <label style="color:#94a3b8;font-size:12px;"><?= t('sales.payment_method') ?></label>
                <div><?= paymentTypeBadge($invoice['payment_type']) ?></div>
            </div>
            <div class="form-group">
                <label style="color:#94a3b8;font-size:12px;"><?= t('g.by') ?></label>
                <div><?= $invoice['created_by_name'] ?? '-' ?></div>
            </div>
        </div>
    </div>
</div>

<!-- البنود -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-list"></i><?= t('g.invoice_items') ?></h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>م</th><th><?= t('g.product') ?></th><th><?= t('products.barcode') ?></th><th><?= t('quantity') ?></th><th><?= t('g.unit_price') ?></th><th><?= t('net') ?></th><th><?= t('tax') ?></th><th><?= t('total') ?></th><th><?= t('inventory.batch_number') ?></th><th><?= t('inventory.expiry_date') ?></th></tr>
                </thead>
                <tbody>
                    <?php $n = 0; foreach ($invoiceItems as $item): $n++; ?>
                    <tr>
                        <td><?= $n ?></td>
                        <td dir="auto"><strong><?= $item['product_name'] ?></strong></td>
                        <td><?= $item['barcode'] ?? '-' ?></td>
                        <td><?= $item['quantity'] ?> <?= $item['unit'] ?? '' ?></td>
                        <td><?= formatMoney($item['unit_price']) ?></td>
                        <td><?= formatMoney($item['net_amount']) ?></td>
                        <td><?= formatMoney($item['vat_amount']) ?></td>
                        <td><strong><?= formatMoney($item['total_amount']) ?></strong></td>
                        <td><?= $item['batch_number'] ?: '-' ?></td>
                        <td><?= $item['expiry_date'] ?: '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- الإجماليات -->
        <div style="display:flex;justify-content:flex-end;margin-top:15px;">
            <table style="width:300px;border:2px solid #e2e8f0;border-collapse:collapse;">
                <tr><td style="padding:8px;background:#f8fafc;font-weight:700;"><?= t('subtotal') ?></td><td style="padding:8px;"><?= formatMoney($invoice['subtotal']) ?></td></tr>
                <tr><td style="padding:8px;background:#f8fafc;font-weight:700;"><?= t('discount') ?></td><td style="padding:8px;color:#dc2626;"><?= formatMoney($invoice['discount']) ?></td></tr>
                <tr><td style="padding:8px;background:#f8fafc;font-weight:700;"><?= t('net') ?></td><td style="padding:8px;"><?= formatMoney($invoice['net_total']) ?></td></tr>
                <tr><td style="padding:8px;background:#f8fafc;font-weight:700;"><?= t('tax') ?></td><td style="padding:8px;"><?= formatMoney($invoice['vat_amount']) ?></td></tr>
                <tr style="background:#0f3460;color:#fff;"><td style="padding:10px;font-weight:800;"><?= t('total') ?></td><td style="padding:10px;font-weight:800;"><?= formatMoney($invoice['grand_total']) ?> <span style="font-size:12px;font-weight:600;"><?= t('sar') ?></span></td></tr>
                <?php if ($invoice['payment_status'] !== 'paid'): ?>
                <tr><td style="padding:8px;background:#f8fafc;font-weight:700;"><?= t('sales.paid_amount') ?></td><td style="padding:8px;color:#16a34a;"><?= formatMoney($invoice['paid_amount']) ?></td></tr>
                <tr style="background:#fef2f2;"><td style="padding:8px;font-weight:700;color:#dc2626;"><?= t('remaining') ?></td><td style="padding:8px;font-weight:700;color:#dc2626;"><?= formatMoney($invoice['remaining_amount']) ?> <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- <?= t('inventory.movements') ?> -->
<?php if (!empty($invMovements)): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-exchange-alt"></i> <?= t('inventory.movements') ?> المرتبطة</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table>
                <thead><tr><th><?= t('g.product') ?></th><th><?= t('type') ?></th><th><?= t('quantity') ?></th><th><?= t('date') ?></th><th><?= t('notes') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($invMovements as $mv): ?>
                    <tr>
                        <td dir="auto"><?= $mv['product_name'] ?></td>
                        <td><span class="badge badge-success"><?= t('tx.purchase') ?></span></td>
                        <td><strong style="color:#16a34a;">+<?= $mv['quantity'] ?></strong></td>
                        <td><?= $mv['created_at'] ?></td>
                        <td><?= $mv['notes'] ?? '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- القيد المحاسبي -->
<?php if ($journalEntry): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-balance-scale"></i> <?= t('g.journal_entry') ?></h3></div>
    <div class="card-body">
        <div style="margin-bottom:10px;">
            <strong>رقم القيد:</strong> <?= $journalEntry['entry_number'] ?> |
            <strong><?= t('date') ?>:</strong> <?= $journalEntry['entry_date'] ?> |
            <strong>الوصف:</strong> <?= $journalEntry['description'] ?>
        </div>
        <?php
        $jeLines = $pdo->prepare("SELECT jel.*, a.name as account_name, a.code as account_code FROM journal_entry_lines jel LEFT JOIN accounts a ON jel.account_id = a.id WHERE jel.entry_id = ?");
        $jeLines->execute([$journalEntry['id']]);
        ?>
        <table>
            <thead><tr><th><?= t('accounting.account') ?></th><th><?= t('accounting.debit') ?></th><th><?= t('accounting.credit') ?></th><th><?= t('g.statement') ?></th></tr></thead>
            <tbody>
                <?php while ($line = $jeLines->fetch()): ?>
                <tr>
                    <td><strong><?= $line['account_code'] ?></strong> - <?= $line['account_name'] ?></td>
                    <td style="color:<?= $line['debit'] > 0 ? '#16a34a' : '#94a3b8' ?>"><?= formatMoney($line['debit']) ?></td>
                    <td style="color:<?= $line['credit'] > 0 ? '#dc2626' : '#94a3b8' ?>"><?= formatMoney($line['credit']) ?></td>
                    <td><?= $line['description'] ?? '-' ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($invoice['notes']): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-sticky-note"></i> <?= t('notes') ?></h3></div>
    <div class="card-body"><?= nl2br(htmlspecialchars($invoice['notes'])) ?></div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
