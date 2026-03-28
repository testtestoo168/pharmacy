<?php
require_once 'includes/config.php';
$tid = getTenantId();
$bid = getBranchId();
requireLogin();

$id = $_GET['id'] ?? 0;
$invoice = $pdo->prepare("SELECT pi.*, s.name as supplier_name, s.tax_number as supplier_tax, s.address as supplier_address, s.phone as supplier_phone FROM purchase_invoices pi LEFT JOIN suppliers s ON pi.supplier_id = s.id WHERE pi.tenant_id = $tid AND pi.branch_id = $bid AND pi.id = ?");
$invoice->execute([$id]);
$invoice = $invoice->fetch();
if (!$invoice) die(t('g.not_found'));

$items = $pdo->prepare("SELECT * FROM purchase_invoice_items WHERE invoice_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$company = getCompanySettings($pdo);
$hijriDate = gregorianToHijri($invoice['invoice_date']);

$statusLabels = ['paid' => t('paid'), 'partial' => t('partial'), 'unpaid' => t('unpaid')];
$paymentStatus = $statusLabels[$invoice['payment_status'] ?? 'unpaid'] ?? t('unknown');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= t('g.print_purchase') ?> - <?= $invoice['invoice_number'] ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Tajawal',sans-serif; direction:rtl; background:#d5d5d5; padding:15px; color:#1d1d2e; }
        .no-print { text-align:center; margin-bottom:12px; }
        .no-print button, .no-print a { padding:8px 24px; border:none; border-radius:4px; font-size:13px; cursor:pointer; font-family:'Tajawal',sans-serif; text-decoration:none; display:inline-block; }
        .no-print button { background:#1a2744; color:#fff; }
        .no-print a { background:#888; color:#fff; margin-right:8px; }
        .inv { width:210mm; min-height:297mm; margin:0 auto; background:#fff; border:1px solid #bbb; display:flex; flex-direction:column; }
        .inv-header { display:flex; justify-content:space-between; align-items:flex-start; padding:14px 20px 10px; border-bottom:3px solid #1a2744; }
        .hdr-right { text-align:right; flex:1; }
        .hdr-right h2 { font-size:15px; font-weight:800; color:#1a2744; margin-bottom:1px; }
        .hdr-right p { font-size:10px; color:#444; line-height:1.65; }
        .hdr-center { text-align:center; padding:0 10px; flex-shrink:0; }
        .hdr-center img { width:80px; height:auto; }
        .hdr-left { text-align:left; direction:ltr; flex:1; }
        .hdr-left h2 { font-size:12px; font-weight:700; color:#1a2744; margin-bottom:1px; }
        .hdr-left p { font-size:10px; color:#444; line-height:1.65; }
        .branch-row { text-align:right; padding:3px 20px; font-size:11px; font-weight:700; color:#1a2744; border-bottom:1px solid #ddd; }
        .title-bar { display:flex; justify-content:space-between; align-items:center; padding:5px 20px; border-bottom:2px solid #1a2744; background:#fafafa; }
        .title-page { font-size:10px; color:#555; border:1px solid #aaa; padding:2px 8px; }
        .title-main { font-size:18px; font-weight:800; color:#1a2744; }
        .title-badge { background:#ea580c; color:#fff; padding:3px 16px; font-size:12px; font-weight:700; border-radius:3px; }
        .meta-bar { display:flex; justify-content:space-between; padding:6px 20px; border-bottom:1px solid #ddd; font-size:12px; }
        .m-group { display:flex; gap:20px; }
        .m-item { display:flex; gap:5px; }
        .m-label { font-weight:700; color:#1a2744; }
        .supplier-box { padding:8px 20px; border-bottom:2px solid #1a2744; background:#f8f9fb; }
        .c-row { display:flex; gap:8px; font-size:12px; line-height:1.8; }
        .c-row .c-label { font-weight:700; color:#1a2744; min-width:100px; }
        .items-wrap { flex:1; display:flex; flex-direction:column; }
        .items-wrap table { width:100%; border-collapse:collapse; flex:1; }
        .items-wrap thead th { background:#1a2744; color:#fff; padding:7px 5px; font-size:11px; font-weight:700; text-align:center; border:1px solid #0e1a30; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .items-wrap tbody { vertical-align:top; }
        .items-wrap tbody td { padding:6px 5px; font-size:12px; text-align:center; border-bottom:1px solid #e0e0e0; border-left:1px solid #e8e8e8; border-right:1px solid #e8e8e8; }
        .items-wrap tbody td.item-name { text-align:right; padding-right:12px; font-weight:500; }
        .items-wrap tbody tr:nth-child(even) { background:#f9fafb; }
        .items-wrap tbody tr.spacer-row td { border-bottom:none; height:100%; }
        .notes-row { padding:6px 20px; border-top:1px solid #ddd; font-size:11px; color:#555; }
        .notes-row strong { color:#1a2744; }
        .totals-area { display:flex; justify-content:space-between; align-items:flex-start; padding:10px 20px 6px; }
        .totals-tbl { width:260px; border-collapse:collapse; border:1px solid #ccc; }
        .totals-tbl td { padding:5px 10px; font-size:12px; border-bottom:1px solid #e0e0e0; }
        .totals-tbl td:first-child { font-weight:700; background:#f5f6f8; color:#1a2744; border-left:1px solid #e0e0e0; white-space:nowrap; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .totals-tbl td:last-child { text-align:left; direction:ltr; font-weight:600; font-variant-numeric:tabular-nums; }
        .totals-tbl tr.grand { background:#e8ecf2; }
        .totals-tbl tr.grand td { color:#1a2744; font-weight:800; font-size:13px; padding:7px 10px; border:none; border-top:2px solid #1a2744; }
        .inv-footer { border-top:3px solid #1a2744; padding:8px 20px; display:flex; justify-content:space-between; align-items:center; font-size:10px; color:#666; }
        .status-badge { padding:2px 12px; border-radius:12px; font-size:11px; font-weight:700; }
        .status-paid { background:#dcfce7; color:#166534; }
        .status-partial { background:#fef3c7; color:#92400e; }
        .status-unpaid { background:#fee2e2; color:#991b1b; }
        @media print { body { background:#fff; padding:0; margin:0; } .no-print { display:none !important; } .inv { border:none; width:100%; min-height:100vh; } @page { size:A4; margin:0; } }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()"><?= t('g.print_purchase') ?></button>
    <a href="purchase_view?id=<?= $id ?>"><?= t('back') ?></a>
    <a href="purchases"><?= t('g.back_to_list') ?></a>
</div>

<div class="inv">
    <!-- HEADER -->
    <div class="inv-header">
        <div class="hdr-right">
            <h2><?= $company['company_name'] ?? t('app_name') ?></h2>
            <p><?= $company['description_ar'] ?? '' ?></p>
            <p><?= t('prt.cr') ?><?= $company['cr_number'] ?? '' ?></p>
            <p><?= t('settings.tax_number') ?>: <?= $company['tax_number'] ?? '' ?></p>
        </div>
        <div class="hdr-center">
            <img src="<?= !empty($company['logo']) && file_exists($company['logo']) ? htmlspecialchars($company['logo']) : 'assets/logo.png' ?>" alt="Logo">
        </div>
        <div class="hdr-left">
            <h2><?= $company['company_name_en'] ?? '' ?></h2>
            <p>C.R: <?= $company['cr_number'] ?? '' ?></p>
            <p>Tax .N: <?= $company['tax_number'] ?? '' ?></p>
        </div>
    </div>

    <!-- TITLE BAR -->
    <div class="title-bar">
        <div class="title-page"><?= t('prt.page') ?> <strong>1</strong> <?= t('prt.of') ?> <strong>1</strong></div>
        <span class="title-main"><?= t('nav.purchase_invoices') ?></span>
        <div class="title-badge"><?= t('purchases.title') ?></div>
    </div>

    <!-- META -->
    <div class="meta-bar">
        <div class="m-group">
            <div class="m-item"><span class="m-label"><?= t('date') ?>:</span> <span><?= $invoice['invoice_date'] ?></span></div>
            <div class="m-item"><span class="m-label"><?= t('g.hijri') ?>:</span> <span><?= $hijriDate ?></span></div>
        </div>
        <div class="m-group">
            <div class="m-item"><span class="m-label"><?= t('g.payment_type') ?>:</span> <span style="font-weight:700;"><?= paymentTypeLabel($invoice['payment_type']) ?></span></div>
            <div class="m-item"><span class="m-label"><?= t('g.number') ?>:</span> <span style="font-weight:700;"><?= $invoice['invoice_number'] ?></span></div>
            <div class="m-item"><span class="status-badge status-<?= $invoice['payment_status'] ?? 'unpaid' ?>"><?= $paymentStatus ?></span></div>
        </div>
    </div>

    <!-- SUPPLIER -->
    <div class="supplier-box">
        <div class="c-row">
            <span class="c-label"><?= t('purchases.supplier') ?>:</span>
            <span style="font-weight:600;" dir="auto"><?= htmlspecialchars($invoice['supplier_name'] ?? '—') ?></span>
            <?php if ($invoice['supplier_tax']): ?>
            <span style="margin-right:25px;font-size:11px;color:#555;"><?= t('settings.tax_number') ?>: <strong><?= $invoice['supplier_tax'] ?></strong></span>
            <?php endif; ?>
        </div>
        <?php if ($invoice['supplier_phone']): ?>
        <div class="c-row"><span class="c-label"><?= t('phone') ?>:</span> <span><?= htmlspecialchars($invoice['supplier_phone']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($invoice['supplier_invoice_no'])): ?>
        <div class="c-row"><span class="c-label"><?= t('g.supplier_invoice') ?>:</span> <span style="font-weight:700;"><?= htmlspecialchars($invoice['supplier_invoice_no']) ?></span></div>
        <?php endif; ?>
    </div>

    <!-- ITEMS TABLE -->
    <div class="items-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th><?= t('g.product') ?></th>
                    <th style="width:60px;"><?= t('quantity') ?></th>
                    <th style="width:75px;"><?= t('g.unit_price') ?></th>
                    <th style="width:85px;"><?= t('net') ?></th>
                    <th style="width:80px;"><?= t('vat') ?></th>
                    <th style="width:90px;"><?= t('g.total_with_vat') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $item): 
                    $lineNet = floatval($item['quantity']) * floatval($item['unit_price']);
                    $lineVat = $lineNet * 0.15;
                    $lineTotal = $lineNet + $lineVat;
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="item-name" dir="auto"><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= formatMoney($item['unit_price']) ?></td>
                    <td><?= formatMoney($lineNet) ?></td>
                    <td><?= formatMoney($lineVat) ?></td>
                    <td><?= formatMoney($lineTotal) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="spacer-row">
                    <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- NOTES -->
    <div class="notes-row">
        <strong><?= t('notes') ?>:</strong> <?= $invoice['notes'] ? htmlspecialchars($invoice['notes']) : '—' ?>
    </div>

    <!-- TOTALS -->
    <div class="totals-area">
        <div>
            <div style="font-size:12px;color:#666;margin-bottom:4px;"><?= t('g.supplier_invoice') ?>: <strong><?= $invoice['supplier_invoice_no'] ?: '—' ?></strong></div>
            <?php if ($invoice['due_date']): ?>
            <div style="font-size:12px;color:#666;"><?= t('purchases.due_date') ?>: <strong><?= $invoice['due_date'] ?></strong></div>
            <?php endif; ?>
        </div>
        <table class="totals-tbl">
            <tr><td><?= t('total') ?></td><td><?= formatMoney($invoice['subtotal']) ?></td></tr>
            <tr><td><?= t('discount') ?></td><td><?= formatMoney($invoice['discount']) ?></td></tr>
            <tr><td><?= t('net') ?></td><td><?= formatMoney($invoice['net_total']) ?></td></tr>
            <tr><td><?= t('vat') ?> 15%</td><td><?= formatMoney($invoice['vat_amount']) ?></td></tr>
            <tr class="grand"><td><?= t('g.total_with_vat') ?></td><td><?= formatMoney($invoice['grand_total']) ?></td></tr>
            <?php if ($invoice['payment_type'] === 'credit'): ?>
            <tr><td style="color:#e65100;"><?= t('sales.paid_amount') ?></td><td style="color:#e65100;"><?= formatMoney($invoice['paid_amount'] ?? 0) ?></td></tr>
            <tr style="background:#ffebee;"><td style="color:#c62828;font-weight:800;"><?= t('remaining') ?></td><td style="color:#c62828;font-weight:800;font-size:14px;"><?= formatMoney($invoice['remaining_amount'] ?? 0) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- FOOTER -->
    <div class="inv-footer">
        <div><?= $company['address'] ?? '' ?></div>
        <div style="font-weight:600;color:#1a2744;"><?= $company['company_name'] ?? t('app_name') ?></div>
    </div>
</div>

</body>
</html>
