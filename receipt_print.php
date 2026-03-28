<?php
require_once 'includes/config.php';
$tid = getTenantId();
$bid = getBranchId();
requireLogin();
$id = $_GET['id'] ?? 0;
$v = $pdo->prepare("SELECT * FROM receipt_vouchers WHERE tenant_id = $tid AND branch_id = $bid AND id = ?");
$v->execute([$id]);
$v = $v->fetch();
if (!$v) die(t('prt.voucher_not_found'));
$company = getCompanySettings($pdo);

$hijriDate = gregorianToHijri($v['voucher_date']);
$gregParts = explode('-', $v['voucher_date']);
$hijriParts = explode('-', $hijriDate);
$paymentMethodAr = paymentMethodLabel($v['payment_method']);
$paymentMethodEn = paymentMethodLabelEn($v['payment_method']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= t('prt.receipt_voucher') ?> - <?= $v['voucher_number'] ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap');
        
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Tajawal',sans-serif; background:#e8e8e8; padding:20px; direction:rtl; }
        
        .no-print { text-align:center; margin-bottom:20px; }
        .no-print button, .no-print a {
            padding:10px 24px; border:none; border-radius:4px; font-size:14px; 
            cursor:pointer; font-family:'Tajawal',sans-serif; text-decoration:none; display:inline-block;
        }
        .no-print button { background:#1a5276; color:#fff; }
        .no-print a { background:#7f8c8d; color:#fff; margin-right:8px; }

        .voucher {
            width:780px;
            margin:0 auto;
            background:#fff;
            border:2px solid #1a5276;
            position:relative;
            overflow:hidden;
        }

        /* الشريط العلوي الأزرق */
        .v-top-bar {
            background:#1a5276;
            height:8px;
        }

        /* الهيدر */
        .v-header {
            display:flex;
            align-items:stretch;
            border-bottom:2px solid #1a5276;
        }

        .v-header-right {
            flex:1;
            padding:14px 20px;
            text-align:center;
        }

        .v-title-ar {
            font-size:28px;
            font-weight:800;
            color:#1a5276;
            letter-spacing:2px;
        }

        .v-title-en {
            font-size:13px;
            color:#1a5276;
            font-weight:500;
            letter-spacing:1px;
            margin-top:2px;
        }

        .v-number-box {
            display:inline-block;
            margin-top:8px;
            background:#fff;
            border:2px solid #c0392b;
            border-radius:4px;
            padding:4px 20px;
        }

        .v-number-box span {
            font-size:22px;
            font-weight:800;
            color:#c0392b;
            letter-spacing:3px;
        }

        .v-header-left {
            width:200px;
            border-right:2px solid #1a5276;
            display:flex;
            flex-direction:column;
            justify-content:center;
            padding:12px 16px;
            font-size:13px;
        }

        .v-amount-header {
            text-align:center;
            margin-bottom:8px;
        }

        .v-amount-header .label-ar { font-size:12px; color:#555; }
        .v-amount-header .label-en { font-size:10px; color:#999; }

        .v-amount-box {
            border:2px solid #1a5276;
            background:#f0f5fa;
            padding:8px 12px;
            text-align:center;
            font-size:18px;
            font-weight:800;
            color:#1a5276;
            letter-spacing:1px;
        }

        /* التاريخ */
        .v-date-row {
            display:flex;
            border-bottom:1px solid #ccc;
        }

        .v-date-cell {
            flex:1;
            padding:8px 20px;
            display:flex;
            align-items:center;
            gap:8px;
            font-size:13px;
        }

        .v-date-cell:first-child {
            border-left:1px solid #ccc;
        }

        .v-date-cell .date-label {
            font-weight:700;
            color:#1a5276;
            white-space:nowrap;
        }

        .v-date-cell .date-label-en {
            font-size:10px;
            color:#999;
        }

        .v-date-cell .date-value {
            flex:1;
            text-align:center;
            font-weight:600;
            font-size:14px;
            border-bottom:1px dotted #999;
            padding-bottom:2px;
        }

        /* البودي - الحقول */
        .v-body {
            padding:16px 24px;
        }

        .v-field {
            display:flex;
            align-items:baseline;
            margin-bottom:14px;
            line-height:1.8;
        }

        .v-field .field-label {
            font-weight:700;
            color:#1a5276;
            white-space:nowrap;
            font-size:14px;
            min-width:170px;
        }

        .v-field .field-label-en {
            font-size:10px;
            color:#999;
            font-weight:400;
            display:block;
        }

        .v-field .field-value {
            flex:1;
            border-bottom:1px dotted #888;
            padding:0 8px 2px;
            font-size:14px;
            font-weight:600;
            color:#333;
            min-height:22px;
        }

        /* مبلغ بالوسط */
        .v-amount-main {
            margin:16px 0;
            padding:14px 20px;
            border:2px solid #1a5276;
            background:#f0f5fa;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:12px;
        }

        .v-amount-main .amount-label {
            font-size:13px;
            font-weight:700;
            color:#1a5276;
        }

        .v-amount-main .amount-value {
            font-size:24px;
            font-weight:800;
            color:#1a5276;
            letter-spacing:1px;
        }

        .v-amount-main .amount-currency {
            font-size:14px;
            font-weight:600;
            color:#1a5276;
        }

        /* التوقيعات */
        .v-signatures {
            display:flex;
            justify-content:space-between;
            padding:20px 40px;
            margin-top:20px;
            border-top:1px solid #ddd;
        }

        .v-sig {
            text-align:center;
            min-width:120px;
        }

        .v-sig .sig-label-ar {
            font-size:13px;
            font-weight:700;
            color:#1a5276;
        }

        .v-sig .sig-label-en {
            font-size:10px;
            color:#999;
        }

        .v-sig .sig-line {
            width:120px;
            border-top:1px solid #333;
            margin:35px auto 6px;
        }

        /* الشريط السفلي */
        .v-bottom-bar {
            background:#1a5276;
            padding:8px 20px;
            display:flex;
            justify-content:space-between;
            color:#fff;
            font-size:11px;
        }

        /* الشريط الملون على اليسار */
        .v-color-stripe {
            position:absolute;
            top:0;
            left:0;
            width:14px;
            height:100%;
            background:linear-gradient(to bottom, #2980b9, #1a5276);
        }

        @media print {
            .no-print { display:none !important; }
            body { background:#fff; padding:0; }
            .voucher { border:2px solid #1a5276; width:100%; }
            thead { display:table-header-group !important; }
            tbody tr { break-inside:avoid; page-break-inside:avoid; }
            @page { size:A4; margin:6mm; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()"><i class="fas fa-print"></i><?= t('print') ?></button>
    <a href="receipts"><?= t('back') ?></a>
</div>

<div class="voucher">
    <div class="v-color-stripe"></div>
    <div class="v-top-bar"></div>
    
    <!-- الهيدر -->
    <div class="v-header">
        <div class="v-header-right">
            <img src="<?= !empty($company['logo']) && file_exists($company['logo']) ? htmlspecialchars($company['logo']) : 'assets/logo.png' ?>" alt="Logo" style="width:60px;height:auto;margin-bottom:6px;">
            <div class="v-title-ar"><?= t('receipts.receipt_voucher') ?></div>
            <div class="v-title-en">RECEIPT VOUCHER</div>
            <div class="v-number-box">
                <span><?= str_pad(intval(preg_replace('/[^0-9]/', '', $v['voucher_number'])), 4, '0', STR_PAD_LEFT) ?></span>
            </div>
        </div>
        <div class="v-header-left">
            <div class="v-amount-header">
                <div class="label-ar">SAR</div>
            </div>
            <div class="v-amount-box"><?= formatMoney($v['amount']) ?></div>
        </div>
    </div>

    <!-- التاريخ -->
    <div class="v-date-row">
        <div class="v-date-cell">
            <div>
                <span class="date-label"><?= t('date') ?>:</span>
                <span class="date-label-en">Date</span>
            </div>
            <div class="date-value"><?= $gregParts[2] ?? '' ?> / <?= $gregParts[1] ?? '' ?> / <?= $gregParts[0] ?? '' ?><?= t('g.hijri_h') ?></div>
        </div>
        <div class="v-date-cell">
            <div>
                <span class="date-label"><?= t('g.hijri') ?>:</span>
                <span class="date-label-en">Hijri</span>
            </div>
            <div class="date-value"><?= $hijriParts[2] ?? '' ?> / <?= $hijriParts[1] ?? '' ?> / <?= $hijriParts[0] ?? '' ?><?= t('g.hijri_h') ?></div>
        </div>
    </div>

    <!-- البودي -->
    <div class="v-body">
        <div class="v-field">
            <div class="field-label">
                <?= t('prt.received_from') ?>
                <span class="field-label-en">Received From Mrs.</span>
            </div>
            <div class="field-value"><?= htmlspecialchars($v['received_from']) ?></div>
        </div>

        <div class="v-field">
            <div class="field-label">
                <?= t('prt.amount_of') ?>
                <span class="field-label-en">Amount</span>
            </div>
            <div class="field-value"><?= formatMoney($v['amount']) ?>SAR</div>
        </div>

        <div class="v-field">
            <div class="field-label">
                <?= t('prt.payment_method_label') ?>
                <span class="field-label-en">Cash / Cheque No.</span>
            </div>
            <div class="field-value"><?= $paymentMethodAr ?> <?= $v['payment_method'] === 'check' ? '— ' : '' ?></div>
        </div>

        <div class="v-field">
            <div class="field-label">
                <?= t('prt.for_reason') ?>
                <span class="field-label-en">Being</span>
            </div>
            <div class="field-value"><?= htmlspecialchars($v['description']) ?></div>
        </div>
    </div>

    <!-- التوقيعات -->
    <div class="v-signatures">
        <div class="v-sig">
            <div class="sig-line"></div>
            <div class="sig-label-ar"><?= t('g.receiver') ?></div>
            <div class="sig-label-en">Receiver</div>
        </div>
        <div class="v-sig">
            <div class="sig-line"></div>
            <div class="sig-label-ar"><?= t('g.accountant') ?></div>
            <div class="sig-label-en">Accountant</div>
        </div>
        <div class="v-sig">
            <div class="sig-line"></div>
            <div class="sig-label-ar"><?= t('users.manager') ?></div>
            <div class="sig-label-en">Manager</div>
        </div>
    </div>

    <!-- الفوتر -->
    <div class="v-bottom-bar">
        <span><?= $company['company_name'] ?? t('app_name') ?></span>
        <span><?= t('phone') ?>: <?= $company['phone'] ?? '' ?></span>
        <span><?= t('settings.cr_number') ?>: <?= $company['cr_number'] ?? '' ?></span>
        <span><?= t('settings.tax_number') ?>: <?= $company['tax_number'] ?? '' ?></span>
    </div>
</div>

</body>
</html>
