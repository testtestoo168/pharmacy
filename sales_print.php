<?php
require_once 'includes/config.php';
$tid = getTenantId();
$bid = getBranchId();
requireLogin();

$id = $_GET['id'] ?? 0;
$invoice = $pdo->prepare("SELECT si.*, c.name as customer_name, c.tax_number as customer_tax, c.address as customer_address, c.phone as customer_phone FROM sales_invoices si LEFT JOIN customers c ON si.customer_id = c.id WHERE si.tenant_id = $tid AND si.branch_id = $bid AND si.id = ?");
$invoice->execute([$id]);
$invoice = $invoice->fetch();
if (!$invoice) die(t('prt.invoice_not_found'));

$items = $pdo->prepare("SELECT * FROM sales_invoice_items WHERE invoice_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$company = getCompanySettings($pdo);
$hijriDate = $invoice['hijri_date'] ?? gregorianToHijri($invoice['invoice_date']);

function numberToArabicWords($number) {
    $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة',
             'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    $hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
    $thousands = ['', 'ألف', 'ألفان', 'ثلاثة آلاف', 'أربعة آلاف', 'خمسة آلاف', 'ستة آلاف', 'سبعة آلاف', 'ثمانية آلاف', 'تسعة آلاف'];
    $num = intval($number);
    if ($num == 0) return 'صفر';
    $result = '';
    if ($num >= 1000) { $th = intval($num/1000); if($th<10) $result .= $thousands[$th]; $num %= 1000; if($num>0) $result .= ' ' . t('prt.and') . ' '; }
    if ($num >= 100) { $h = intval($num/100); $result .= $hundreds[$h]; $num %= 100; if($num>0) $result .= ' ' . t('prt.and') . ' '; }
    if ($num >= 20) { $t = intval($num/10); $o = $num%10; if($o>0) $result .= $ones[$o].' و'.$tens[$t]; else $result .= $tens[$t]; }
    elseif ($num > 0) $result .= $ones[$num];
    return $result;
}

$amountWords = t('prt.only_word') . ' ' . numberToArabicWords(intval($invoice['grand_total'])) . ' ' . t('prt.riyal');
$fraction = round(($invoice['grand_total'] - intval($invoice['grand_total'])) * 100);
if ($fraction > 0) $amountWords .= ' ' . t('prt.and') . ' ' . numberToArabicWords($fraction) . ' ' . t('prt.halala');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= t('prt.tax_invoice') ?> - <?= $invoice['invoice_number'] ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Tajawal',sans-serif; direction:rtl; background:#d5d5d5; padding:15px; color:#1d1d2e; }
        
        .no-print { text-align:center; margin-bottom:12px; }
        .no-print button, .no-print a {
            padding:8px 24px; border:none; border-radius:4px; font-size:13px;
            cursor:pointer; font-family:'Tajawal',sans-serif; text-decoration:none; display:inline-block;
        }
        .no-print button { background:#1a2744; color:#fff; }
        .no-print a { background:#888; color:#fff; margin-right:8px; }

        /* الصفحة الرئيسية - A4 بالظبط */
        .inv {
            width:210mm;
            min-height:297mm;
            margin:0 auto;
            background:#fff;
            border:1px solid #bbb;
            display:flex;
            flex-direction:column;
        }

        /* HEADER */
        .inv-header {
            display:flex; justify-content:space-between; align-items:flex-start;
            padding:14px 20px 10px; border-bottom:3px solid #1a2744;
        }
        .hdr-right { text-align:right; flex:1; }
        .hdr-right h2 { font-size:15px; font-weight:800; color:#1a2744; margin-bottom:1px; }
        .hdr-right p { font-size:10px; color:#444; line-height:1.65; }
        .hdr-center { text-align:center; padding:0 10px; flex-shrink:0; }
        .hdr-center img { width:80px; height:auto; }
        .hdr-left { text-align:left; direction:ltr; flex:1; }
        .hdr-left h2 { font-size:12px; font-weight:700; color:#1a2744; margin-bottom:1px; }
        .hdr-left p { font-size:10px; color:#444; line-height:1.65; }

        /* BRANCH */
        .branch-row { text-align:right; padding:3px 20px; font-size:11px; font-weight:700; color:#1a2744; border-bottom:1px solid #ddd; }

        /* TITLE BAR */
        .title-bar {
            display:flex; justify-content:space-between; align-items:center;
            padding:5px 20px; border-bottom:2px solid #1a2744; background:#fafafa;
        }
        .title-page { font-size:10px; color:#555; border:1px solid #aaa; padding:2px 8px; }
        .title-main { font-size:18px; font-weight:800; color:#1a2744; }
        .title-badge { background:#1a2744; color:#fff; padding:3px 16px; font-size:12px; font-weight:700; }

        /* META */
        .meta-bar {
            display:flex; justify-content:space-between; padding:6px 20px;
            border-bottom:1px solid #ddd; font-size:12px;
        }
        .m-group { display:flex; gap:20px; }
        .m-item { display:flex; gap:5px; }
        .m-label { font-weight:700; color:#1a2744; }

        /* CUSTOMER */
        .customer-box { padding:8px 20px; border-bottom:2px solid #1a2744; background:#f8f9fb; }
        .c-row { display:flex; gap:8px; font-size:12px; line-height:1.8; }
        .c-row .c-label { font-weight:700; color:#1a2744; min-width:80px; }
        .c-row .c-tax { margin-right:25px; font-size:11px; color:#555; }

        /* ITEMS - هنا المفتاح: flex:1 عشان يملا الصفحة */
        .items-wrap {
            flex:1;
            display:flex;
            flex-direction:column;
        }
        .items-wrap table { width:100%; border-collapse:collapse; flex:1; }
        .items-wrap thead th {
            background:#1a2744; color:#fff;
            padding:7px 5px; font-size:11px; font-weight:700;
            text-align:center; border:1px solid #0e1a30;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .items-wrap tbody { vertical-align:top; }
        .items-wrap tbody td {
            padding:6px 5px; font-size:12px; text-align:center;
            border-bottom:1px solid #e0e0e0;
            border-left:1px solid #e8e8e8; border-right:1px solid #e8e8e8;
        }
        .items-wrap tbody td.item-name { text-align:right; padding-right:12px; font-weight:500; }
        .items-wrap tbody tr:nth-child(even) { background:#f9fafb; }
        /* آخر صف فاضي يتمدد ويملا الباقي */
        .items-wrap tbody tr.spacer-row td { border-bottom:none; height:100%; }

        /* NOTES */
        .notes-row { padding:6px 20px; border-top:1px solid #ddd; font-size:11px; color:#555; }
        .notes-row strong { color:#1a2744; }

        /* TOTALS + SELLER */
        .totals-area {
            display:flex; justify-content:space-between; align-items:flex-start;
            padding:10px 20px 6px;
        }
        .seller-box .seller-title { font-size:15px; font-weight:800; letter-spacing:2px; color:#1a2744; margin-bottom:3px; }
        .seller-box .seller-name { font-size:12px; color:#555; }

        .totals-tbl { width:260px; border-collapse:collapse; border:1px solid #ccc; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .totals-tbl td { padding:5px 10px; font-size:12px; border-bottom:1px solid #e0e0e0; color:#1a2744; }
        .totals-tbl td:first-child { font-weight:700; background:#f5f6f8; color:#1a2744; border-left:1px solid #e0e0e0; white-space:nowrap; }
        .totals-tbl td:last-child { text-align:left; direction:ltr; font-weight:600; font-variant-numeric:tabular-nums; color:#1a2744; }
        .totals-tbl tr.grand { background:#e8ecf2; }
        .totals-tbl tr.grand td { color:#1a2744; font-weight:800; font-size:13px; padding:7px 10px; border:none; border-top:2px solid #1a2744; }

        /* AMOUNT WORDS */
        .amount-words {
            margin:0 20px 6px; padding:6px 12px;
            border:1px solid #1a2744; display:flex; justify-content:space-between;
            font-size:12px; font-weight:600; background:#f8f9fb;
        }
        .amount-words .aw-label { color:#b71c1c; font-weight:800; }
        .amount-words .aw-total { font-weight:800; font-size:13px; color:#1a2744; }

        /* FOOTER */
        .inv-footer {
            border-top:3px solid #1a2744; padding:8px 20px;
            display:flex; justify-content:space-between; align-items:center;
            font-size:10px; color:#666;
        }

        @media print {
            body { background:#fff; padding:0; margin:0; }
            .no-print { display:none !important; }
            .inv { border:none; width:100%; min-height:100vh; }
            @page { size:A4; margin:0; }
            thead { display:table-header-group !important; }
            tfoot { display:table-footer-group !important; }
            tbody tr { break-inside:avoid; page-break-inside:avoid; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()"><?= t('g.print_invoice') ?></button>
    <a href="sales"><?= t('back') ?></a>
</div>

<div class="inv">

    <!-- HEADER -->
    <div class="inv-header">
        <div class="hdr-right">
            <h2><?= $company['company_name'] ?? t('app_name') ?></h2>
            <p><?= $company['description_ar'] ?? t('prt.default_desc') ?></p>
            <p><?= $company['description_ar2'] ?? t('prt.default_desc2') ?></p>
            <p><?= t('prt.cr') ?><?= $company['cr_number'] ?? '403124823' ?></p>
            <p><?= t('prt.contact') ?> <?= $company['phone'] ?? '0555550177 / 0500666711' ?></p>
            <p><?= t('settings.tax_number') ?>:<?= $company['tax_number'] ?? '310118557900003' ?></p>
        </div>
        <div class="hdr-center">
            <img src="<?= !empty($company['logo']) && file_exists($company['logo']) ? htmlspecialchars($company['logo']) : 'assets/logo.png' ?>" alt="Rwaye Logo">
        </div>
        <div class="hdr-left">
            <h2><?= $company['company_name_en'] ?? 'URS Pharmacy' ?></h2>
            <p><?= $company['description_en'] ?? 'Fiber laser cutting works (metal formation)' ?></p>
            <p><?= $company['description_en2'] ?? 'blacksmithing works in general design and implementation' ?></p>
            <p>C.R:<?= $company['cr_number'] ?? '403124823' ?></p>
            <p>Contact: <?= $company['phone'] ?? '0555550177 /0500666711' ?></p>
            <p>Tax .N:<?= $company['tax_number'] ?? '310118557900003' ?></p>
        </div>
    </div>

    <!-- BRANCH -->
    <div class="branch-row"><?= t('prt.branch_label') ?> 1 - 001</div>

    <!-- TITLE BAR -->
    <div class="title-bar">
        <div class="title-page"><?= t('prt.page') ?> <strong>1</strong> <?= t('prt.of') ?> <strong>1</strong></div>
        <span class="title-main"><?= t('g.tax_invoice') ?></span>
        <div class="title-badge"><?= t('prt.sales_badge') ?></div>
    </div>

    <!-- META -->
    <div class="meta-bar">
        <div class="m-group">
            <div class="m-item"><span class="m-label"><?= t('date') ?>:</span> <span><?= $invoice['invoice_date'] ?></span></div>
            <div class="m-item"><span class="m-label"><?= t('g.hijri') ?>:</span> <span><?= $hijriDate ?></span></div>
        </div>
        <div class="m-group">
            <div class="m-item"><span class="m-label"><?= t('prt.type_label') ?></span> <span style="font-weight:700;"><?= paymentTypeLabel($invoice['payment_type']) ?></span></div>
            <div class="m-item"><span class="m-label"><?= t('prt.number_label') ?></span> <span style="font-weight:700;"><?= $invoice['invoice_number'] ?></span></div>
        </div>
    </div>

    <!-- CUSTOMER -->
    <div class="customer-box">
        <div class="c-row">
            <span class="c-label"><?= t('prt.customer_label') ?></span>
            <span style="font-weight:600;" dir="auto"><?= htmlspecialchars($invoice['customer_name'] ?? t('sales.cash_customer')) ?></span>
            <?php if ($invoice['customer_tax']): ?>
            <span class="c-tax"><?= t('prt.tax_number_label') ?> <strong><?= $invoice['customer_tax'] ?></strong></span>
            <?php endif; ?>
        </div>
        <?php if ($invoice['customer_address']): ?>
        <div class="c-row"><span class="c-label"><?= t('prt.address_label') ?></span> <span><?= htmlspecialchars($invoice['customer_address']) ?></span></div>
        <?php endif; ?>
        <?php if ($invoice['customer_phone']): ?>
        <div class="c-row"><span class="c-label"><?= t('prt.phone_label') ?></span> <span><?= htmlspecialchars($invoice['customer_phone']) ?></span></div>
        <?php endif; ?>
    </div>

    <!-- ITEMS TABLE - يتمدد ويملا الصفحة -->
    <div class="items-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:30px;"><?= t('prt.seq') ?></th>
                    <th><?= t('g.statement') ?></th>
                    <th style="width:60px;"><?= t('quantity') ?></th>
                    <th style="width:75px;"><?= t('price') ?></th>
                    <th style="width:85px;"><?= t('net') ?></th>
                    <th style="width:90px;"><?= t('vat') ?></th>
                    <th style="width:90px;"><?= t('net') ?>+<?= t('tax') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="item-name" dir="auto"><?= htmlspecialchars($item['product_name']) ?></td>
                    <td><?= $item['quantity'] ?></td>
                    <td><?= formatMoney($item['unit_price']) ?></td>
                    <td><?= formatMoney($item['net_amount']) ?></td>
                    <td><?= formatMoney($item['vat_amount']) ?></td>
                    <td><?= formatMoney($item['total_amount']) ?></td>
                </tr>
                <?php endforeach; ?>
                <!-- صف يتمدد ويملا المساحة الباقية -->
                <tr class="spacer-row">
                    <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- NOTES -->
    <div class="notes-row">
        <strong><?= t('prt.notes_label') ?></strong> <?= $invoice['notes'] ? htmlspecialchars($invoice['notes']) : '' ?>
    </div>

    <!-- TOTALS + SELLER -->
    <div class="totals-area">
        <div class="seller-box">
            <div class="seller-title"><?= t('prt.seller_title') ?></div>
            <div class="seller-name"><?= t('prt.seller_name') ?></div>
        </div>
        <table class="totals-tbl">
            <tr><td><?= t('total') ?></td><td><?= formatMoney($invoice['subtotal']) ?></td></tr>
            <tr><td><?= t('discount') ?></td><td><?= formatMoney($invoice['discount']) ?></td></tr>
            <tr><td><?= t('net') ?></td><td><?= formatMoney($invoice['net_total']) ?></td></tr>
            <tr><td><?= t('vat') ?> 15%</td><td><?= formatMoney($invoice['vat_amount']) ?></td></tr>
            <tr class="grand"><td><?= t('g.total_with_vat') ?></td><td><?= formatMoney($invoice['grand_total']) ?></td></tr>
            <?php if (isDeferred($invoice['payment_type'])): ?>
            <tr><td style="color:#e65100;"><?= t('sales.paid_amount') ?></td><td style="color:#e65100;"><?= formatMoney($invoice['paid_amount'] ?? 0) ?></td></tr>
            <tr style="background:#ffebee;"><td style="color:#c62828;font-weight:800;"><?= t('remaining') ?></td><td style="color:#c62828;font-weight:800;font-size:14px;"><?= formatMoney($invoice['remaining_amount'] ?? 0) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- AMOUNT IN WORDS -->
    <div class="amount-words">
        <div><span class="aw-label"><?= t('prt.no_more_sr') ?></span> <?= $amountWords ?></div>
        <div class="aw-total"><?= formatMoney($invoice['grand_total']) ?></div>
    </div>

    <!-- ZATCA E-Invoice Status -->
    <?php
    $zStatus = $invoice['zatca_status'] ?? 'not_sent';
    $zQR = $invoice['qr_code'] ?? '';
    if ($zStatus !== 'not_sent' || $zQR):
    ?>
    <div style="margin:10px 0;padding:8px;border:1px dashed #ccc;border-radius:6px;display:flex;align-items:center;gap:12px;justify-content:space-between">
        <div style="text-align:center">
            <?php if ($zQR): ?>
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode($zQR) ?>" alt="QR" style="width:100px;height:100px">
            <div style="font-size:8px;color:#64748b;margin-top:2px"><?= t('prt.e_invoice') ?></div>
            <?php endif; ?>
        </div>
        <div style="flex:1;text-align:center;font-size:10px;color:#64748b">
            <?php if ($invoice['uuid'] ?? ''): ?>
            <div>UUID: <?= $invoice['uuid'] ?></div>
            <?php endif; ?>
            <?php
            $zLabels = ['reported'=>t('prt.reported'),'cleared'=>t('prt.cleared_label'),'accepted_with_warnings'=>t('prt.accepted_warnings'),'rejected'=>t('prt.rejected_label'),'error'=>t('prt.error_label'),'pending'=>t('prt.sending')];
            if (isset($zLabels[$zStatus])):
            ?>
            <div style="margin-top:4px;font-weight:700;color:<?= in_array($zStatus,['reported','cleared'])?'#16a34a':'#dc2626' ?>"><?= $zLabels[$zStatus] ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- FOOTER -->
    <div class="inv-footer">
        <div><?= t('prt.address_prefix') ?> <?= $company['address'] ?? t('prt.default_address') ?></div>
        <div style="font-weight:600;color:#1a2744;"><?= t('prt.location') ?></div>
    </div>

</div>

</body>
</html>
