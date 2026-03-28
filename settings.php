<?php
require_once 'includes/config.php';
$pageTitle = t('settings.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();
requirePermission('settings_view');

$company = getCompanySettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_company']) && hasPermission('settings_edit')) {
        // رفع اللوجو
        $logoPath = $company['logo'] ?? '';
        if (!empty($_FILES['logo']['tmp_name'])) {
            $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
            if (in_array($_FILES['logo']['type'], $allowed) && $_FILES['logo']['size'] <= 2*1024*1024) {
                $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $logoName = 'logo_' . $tid . '_' . time() . '.' . $ext;
                $uploadDir = 'assets/uploads/logos/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $logoName)) {
                    // حذف اللوجو القديم
                    if ($logoPath && file_exists($logoPath)) @unlink($logoPath);
                    $logoPath = $uploadDir . $logoName;
                }
            } else {
                echo '<div class="alert alert-danger">' . t('settings.logo_error') . '</div>';
            }
        }
        
        if ($company) {
            $pdo->prepare("UPDATE company_settings SET company_name=?, company_name_en=?, tax_number=?, cr_number=?, phone=?, address=?, vat_rate=?, description_ar=?, description_ar2=?, description_en=?, description_en2=?, logo=?, street=?, city=?, district=?, building_number=?, postal_code=? WHERE id=? AND tenant_id=?")->execute([
                $_POST['company_name'], $_POST['company_name_en'], $_POST['tax_number'], $_POST['cr_number'], $_POST['phone'], $_POST['address'], $_POST['vat_rate'],
                $_POST['description_ar'] ?? '', $_POST['description_ar2'] ?? '', $_POST['description_en'] ?? '', $_POST['description_en2'] ?? '',
                $logoPath, $_POST['street'] ?? '', $_POST['city'] ?? '', $_POST['district'] ?? '', $_POST['building_number'] ?? '', $_POST['postal_code'] ?? '',
                $company['id'], $tid
            ]);
        } else {
            $pdo->prepare("INSERT INTO company_settings (tenant_id, company_name, company_name_en, tax_number, cr_number, phone, address, vat_rate, description_ar, description_ar2, description_en, description_en2, logo, street, city, district, building_number, postal_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([
                $tid, $_POST['company_name'], $_POST['company_name_en'], $_POST['tax_number'], $_POST['cr_number'], $_POST['phone'], $_POST['address'], $_POST['vat_rate'],
                $_POST['description_ar'] ?? '', $_POST['description_ar2'] ?? '', $_POST['description_en'] ?? '', $_POST['description_en2'] ?? '', $logoPath,
                $_POST['street'] ?? '', $_POST['city'] ?? '', $_POST['district'] ?? '', $_POST['building_number'] ?? '', $_POST['postal_code'] ?? ''
            ]);
        }
        echo '<div class="alert alert-success">' . t('saved_success') . '</div>';
        $company = getCompanySettings($pdo);
    }
    
    if (isset($_POST['change_password'])) {
        if (!empty($_POST['new_password']) && $_POST['new_password'] === $_POST['confirm_password']) {
            $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND tenant_id = $tid")->execute([$hash, $_SESSION['user_id']]);
            echo '<div class="alert alert-success">' . t('pwd.changed') . '</div>';
        } else {
            echo '<div class="alert alert-danger">' . t('pwd.mismatch') . '</div>';
        }
    }
}
?>

<!-- إعدادات الشركة -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-building"></i><?= t('g.company_info') ?></h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            
            <!-- اللوجو -->
            <div style="margin-bottom:20px;padding:16px;background:#f8fafc;border-radius:10px;border:1px dashed #d1d5db;">
                <label style="font-weight:700;font-size:14px;margin-bottom:10px;display:block;"><i class="fas fa-image" style="color:#1d4ed8;"></i> <?= t('settings.logo') ?></label>
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                    <?php if (!empty($company['logo']) && file_exists($company['logo'])): ?>
                    <img src="<?= htmlspecialchars($company['logo']) ?>" alt="Logo" style="max-height:80px;max-width:200px;border-radius:8px;border:1px solid #e2e8f0;">
                    <?php else: ?>
                    <div style="width:80px;height:80px;background:#e2e8f0;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:24px;"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                    <?php if (hasPermission('settings_edit')): ?>
                    <div>
                        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif" class="form-control" style="max-width:280px;">
                        <small style="color:#64748b;font-size:11px;">JPG/PNG — Max 2MB</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group"><label><?= t('settings.company_name') ?></label><input type="text" name="company_name" class="form-control" value="<?= $company['company_name'] ?? '' ?>" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                <div class="form-group"><label><?= t('settings.company_name_en') ?></label><input type="text" name="company_name_en" class="form-control" value="<?= $company['company_name_en'] ?? '' ?>" dir="ltr" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('settings.tax_number') ?></label><input type="text" name="tax_number" class="form-control" value="<?= $company['tax_number'] ?? '' ?>" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                <div class="form-group"><label><?= t('settings.cr_number') ?></label><input type="text" name="cr_number" class="form-control" value="<?= $company['cr_number'] ?? '' ?>" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                <div class="form-group"><label><?= t('settings.vat_rate') ?> %</label><input type="number" name="vat_rate" class="form-control" value="<?= $company['vat_rate'] ?? 15 ?>" step="0.01" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('settings.desc_ar_1') ?></label><input type="text" name="description_ar" class="form-control" value="<?= $company['description_ar'] ?? '' ?>" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                <div class="form-group"><label><?= t('settings.desc_ar_2') ?></label><input type="text" name="description_ar2" class="form-control" value="<?= $company['description_ar2'] ?? '' ?>" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('settings.desc_en_1') ?></label><input type="text" name="description_en" class="form-control" value="<?= $company['description_en'] ?? '' ?>" dir="ltr" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                <div class="form-group"><label><?= t('settings.desc_en_2') ?></label><input type="text" name="description_en2" class="form-control" value="<?= $company['description_en2'] ?? '' ?>" dir="ltr" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label><?= t('phone') ?></label><input type="text" name="phone" class="form-control" value="<?= $company['phone'] ?? '' ?>" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                <div class="form-group"><label><?= t('address') ?></label><input type="text" name="address" class="form-control" value="<?= $company['address'] ?? '' ?>" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
            </div>
            <!-- حقول العنوان التفصيلية - مطلوبة لربط ZATCA (BR-KSA-09) -->
            <div style="margin-top:12px;padding:16px;background:#eff6ff;border-radius:10px;border:1px solid #bfdbfe;margin-bottom:16px">
                <label style="font-weight:700;font-size:14px;margin-bottom:12px;display:block;color:#1d4ed8"><i class="fas fa-map-marker-alt"></i> العنوان التفصيلي (مطلوب لهيئة الزكاة ZATCA)</label>
                <div class="form-row">
                    <div class="form-group"><label>اسم الشارع <small style="color:#dc2626">*</small></label><input type="text" name="street" class="form-control" value="<?= htmlspecialchars($company['street'] ?? '') ?>" placeholder="مثال: شارع الملك فهد" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                    <div class="form-group"><label>الحي (District) <small style="color:#dc2626">*</small></label><input type="text" name="district" class="form-control" value="<?= htmlspecialchars($company['district'] ?? '') ?>" placeholder="مثال: حي العليا" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>المدينة <small style="color:#dc2626">*</small></label><input type="text" name="city" class="form-control" value="<?= htmlspecialchars($company['city'] ?? '') ?>" placeholder="مثال: الرياض" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                    <div class="form-group"><label>رقم المبنى <small style="color:#dc2626">*</small> <small style="color:#94a3b8">(4 أرقام)</small></label><input type="text" name="building_number" class="form-control" value="<?= htmlspecialchars($company['building_number'] ?? '') ?>" placeholder="مثال: 1234" maxlength="4" pattern="\d{4}" dir="ltr" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                    <div class="form-group"><label>الرمز البريدي <small style="color:#dc2626">*</small> <small style="color:#94a3b8">(5 أرقام)</small></label><input type="text" name="postal_code" class="form-control" value="<?= htmlspecialchars($company['postal_code'] ?? '') ?>" placeholder="مثال: 12345" maxlength="5" pattern="\d{5}" dir="ltr" <?= hasPermission('settings_edit') ? '' : 'readonly' ?>></div>
                </div>
            </div>
            <?php if (hasPermission('settings_edit')): ?>
            <button type="submit" name="save_company" class="btn btn-primary"><i class="fas fa-save"></i><?= t('g.save_settings') ?></button>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- تغيير كلمة المرور (متاحة لأي مستخدم) -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-key"></i><?= t('users.change_password') ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group"><label><?= t('pwd.new') ?></label><input type="password" name="new_password" class="form-control" required></div>
                <div class="form-group"><label><?= t('pwd.confirm') ?></label><input type="password" name="confirm_password" class="form-control" required></div>
            </div>
            <button type="submit" name="change_password" class="btn btn-warning"><i class="fas fa-key"></i><?= t('g.change') ?></button>
        </form>
    </div>
</div>


<?php if (hasPermission('settings_edit')): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-star"></i> <?= t('settings.loyalty') ?></h3></div>
    <div class="card-body">
        <?php
        $loyalty = getLoyaltySettings($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_loyalty'])) {
            try { verifyCsrfToken();
                $pdo->prepare("UPDATE loyalty_settings SET points_per_sar=?,sar_per_point=?,min_redeem_points=?,max_redeem_percent=?,is_active=? WHERE id=? AND tenant_id = $tid")
                    ->execute([floatval($_POST['loy_pps']),floatval($_POST['loy_spp']),intval($_POST['loy_min']),floatval($_POST['loy_max']),isset($_POST['loy_active'])?1:0,$loyalty['id']]);
                echo '<div class="alert alert-success">' . t('saved_success') . '</div>'; $loyalty=getLoyaltySettings($pdo);
            } catch(Exception $e) { echo '<div class="alert alert-danger">'.$e->getMessage().'</div>'; }
        } ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group"><label><?= t('g.points_per_rial') ?></label><input type="number" name="loy_pps" class="form-control" value="<?= $loyalty['points_per_sar'] ?>" step="0.01"></div>
                <div class="form-group"><label><?= t('g.point_value') ?></label><input type="number" name="loy_spp" class="form-control" value="<?= $loyalty['sar_per_point'] ?>" step="0.0001"></div>
                <div class="form-group"><label><?= t('g.min_redeem') ?></label><input type="number" name="loy_min" class="form-control" value="<?= $loyalty['min_redeem_points'] ?>"></div>
                <div class="form-group"><label><?= t('g.max_discount_pct') ?></label><input type="number" name="loy_max" class="form-control" value="<?= $loyalty['max_redeem_percent'] ?>" max="100"></div>
            </div>
            <div class="form-group"><label><input type="checkbox" name="loy_active" <?= $loyalty['is_active']?'checked':'' ?>> <?= t('settings.loyalty_active') ?></label></div>
            <button type="submit" name="save_loyalty" class="btn btn-primary"><i class="fas fa-save"></i><?= t('save') ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (hasPermission('users_manage')): ?>
<!-- رابط لإدارة المستخدمين -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-user-shield"></i><?= t('perms.manage_users') ?></h3></div>
    <div class="card-body" style="text-align:center;padding:32px;">
        <p style="margin-bottom:16px;color:var(--muted-foreground);"><?= t('settings.manage_users_desc') ?></p>
        <a href="users" class="btn btn-primary btn-lg"><i class="fas fa-user-shield"></i><?= t('users.title') ?></a>
    </div>
</div>
<?php endif; ?>

<?php
// ========== ربط هيئة الزكاة والضريبة (ZATCA) ==========
// يظهر فقط لو السوبر أدمن فعّل الربط لهذه الصيدلية
$zatcaService = new ZATCAIntegrationService($pdo);
$zatcaAdminEnabled = $zatcaService->isEnabledByAdmin();

if ($zatcaAdminEnabled && hasPermission('settings_edit')):
    $zSettings = $zatcaService->getSettings();
    $zStatus = $zSettings['zatca_onboarding_status'] ?? 'not_started';
    $zEnv = $zSettings['zatca_env'] ?? 'sandbox';
    $zEnabled = intval($zSettings['zatca_enabled'] ?? 0);
    $zMsg = '';
    $zErr = '';

    // ---- معالجة إجراءات ZATCA ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // إعادة ضبط ربط ZATCA بالكامل (للساند بوكس والاختبار)
        if (isset($_POST['zatca_reset'])) {
            try { verifyCsrfToken();
                $zatcaService->saveSettings([
                    'zatca_enabled' => 0,
                    'zatca_onboarding_status' => 'not_started',
                    'zatca_csr' => null,
                    'zatca_private_key' => null,
                    'zatca_compliance_csid' => null,
                    'zatca_compliance_secret' => null,
                    'zatca_compliance_request_id' => null,
                    'zatca_production_csid' => null,
                    'zatca_production_secret' => null,
                    'zatca_last_error' => null,
                    'zatca_last_error_step' => null,
                    'zatca_otp' => null,
                ]);
                $zMsg = 'تم إعادة ضبط ربط ZATCA بنجاح. يمكنك البدء من جديد.';
                $zSettings = $zatcaService->getSettings();
                $zStatus = $zSettings['zatca_onboarding_status'] ?? 'not_started';
                $zEnabled = 0;
            } catch(Exception $e) { $zErr = $e->getMessage(); error_log("ZATCA Error: ".$e->getMessage()); }
        }

        // تفعيل/إيقاف ZATCA من جهة الصيدلية
        if (isset($_POST['zatca_toggle'])) {
            try { verifyCsrfToken();
                $newVal = intval($_POST['zatca_enable'] ?? 0);
                $zatcaService->saveSettings(['zatca_enabled' => $newVal, 'zatca_env' => $_POST['zatca_env'] ?? 'sandbox']);
                $zMsg = t('saved_success');
                $zSettings = $zatcaService->getSettings();
                $zEnabled = intval($zSettings['zatca_enabled'] ?? 0);
                $zEnv = $zSettings['zatca_env'] ?? 'sandbox';
                $zStatus = $zSettings['zatca_onboarding_status'] ?? 'not_started';
            } catch(Exception $e) { $zErr = $e->getMessage(); error_log("ZATCA Error: ".$e->getMessage()); }
        }

        // الخطوة 1: توليد CSR
        if (isset($_POST['zatca_generate_csr'])) {
            try { verifyCsrfToken();
                $result = $zatcaService->generateCSR([
                    'common_name'    => trim($_POST['z_common_name'] ?? 'EGS-URS'),
                    'serial_number'  => trim($_POST['z_serial'] ?? ''),
                    'org_identifier' => trim($_POST['z_vat'] ?? $company['tax_number'] ?? ''),
                    'org_unit_name'  => trim($_POST['z_org_unit'] ?? ''),
                    'org_name'       => trim($_POST['z_org_name'] ?? $company['company_name'] ?? ''),
                    'country'        => 'SA',
                    'invoice_type'   => $_POST['z_invoice_type'] ?? '0100',
                    'location'       => trim($_POST['z_location'] ?? $company['address'] ?? ''),
                    'industry'       => 'Pharmacy'
                ]);
                if ($result['success']) { $zMsg = t('saved_success'); }
                else { $zErr = $result['error'] ?? t('error'); $zatcaService->saveSettings(['zatca_last_error_step' => 'csr']); }
                $zSettings = $zatcaService->getSettings();
                $zStatus = $zSettings['zatca_onboarding_status'] ?? 'not_started';
            } catch(Exception $e) { $zErr = $e->getMessage(); $zatcaService->saveSettings(['zatca_last_error_step' => 'csr']); }
        }

        // الخطوة 2: إرسال CSR والحصول على Compliance CSID
        if (isset($_POST['zatca_get_compliance'])) {
            try { verifyCsrfToken();
                $otp = trim($_POST['z_otp'] ?? '');
                if (!$otp) { $zErr = t('settings.enter_otp'); }
                else {
                    $result = $zatcaService->requestComplianceCSID($otp);
                    if ($result['success']) { $zMsg = t('saved_success'); }
                    else { $zErr = $result['error'] ?? t('error'); $zatcaService->saveSettings(['zatca_last_error_step' => 'compliance']); }
                }
                $zSettings = $zatcaService->getSettings();
                $zStatus = $zSettings['zatca_onboarding_status'] ?? 'not_started';
            } catch(Exception $e) { $zErr = $e->getMessage(); $zatcaService->saveSettings(['zatca_last_error_step' => 'compliance']); }
        }

        // الخطوة 3: الحصول على Production CSID
        if (isset($_POST['zatca_get_production'])) {
            try { verifyCsrfToken();
                $result = $zatcaService->requestProductionCSID();
                if ($result['success']) { $zMsg = t('saved_success'); }
                else { $zErr = $result['error'] ?? t('error'); $zatcaService->saveSettings(['zatca_last_error_step' => 'production']); }
                $zSettings = $zatcaService->getSettings();
                $zStatus = $zSettings['zatca_onboarding_status'] ?? 'not_started';
            } catch(Exception $e) { $zErr = $e->getMessage(); $zatcaService->saveSettings(['zatca_last_error_step' => 'production']); }
        }

        // الخطوة 2.5: فحص الامتثال - إرسال فواتير تجريبية (Compliance Check)
        if (isset($_POST['zatca_compliance_check'])) {
            set_time_limit(120); ini_set('max_execution_time', 120);
            try {
                $checkResults = $zatcaService->runComplianceChecks($company);
                $passed = 0; $failed = 0; $details = [];
                foreach ($checkResults as $cr) {
                    if ($cr['success']) $passed++; else $failed++;
                    $details[] = $cr;
                }
                if ($failed === 0) {
                    $zMsg = "تم اجتياز فحص الامتثال بنجاح ($passed/$passed فواتير) ✅";
                    $zatcaService->saveSettings(['zatca_onboarding_status' => 'compliance_checked']);
                } else {
                    $zErr = "فشل بعض الفواتير في فحص الامتثال ($passed نجح / $failed فشل)";
                    $_SESSION['zatca_compliance_details'] = $details;
                    $zatcaService->saveSettings(['zatca_last_error_step' => 'compliance_check']);
                }
                $zSettings = $zatcaService->getSettings();
                $zStatus = $zSettings['zatca_onboarding_status'] ?? 'not_started';
            } catch(Exception $e) { $zErr = $e->getMessage(); error_log("ZATCA Error: ".$e->getMessage()); }
        }
    }

    // حالات الخطوات
    $steps = [
        'not_started'         => ['num' => 0, 'label' => t('zatca.not_started'),                    'color' => '#94a3b8', 'bg' => '#f1f5f9'],
        'csr_generated'       => ['num' => 1, 'label' => t('zatca.csr_generated'),               'color' => '#f59e0b', 'bg' => '#fef3c7'],
        'compliance_obtained' => ['num' => 2, 'label' => t('zatca.compliance_obtained'),    'color' => '#3b82f6', 'bg' => '#dbeafe'],
        'compliance_checked'  => ['num' => 3, 'label' => t('zatca.compliance_checked'),              'color' => '#8b5cf6', 'bg' => '#ede9fe'],
        'active'              => ['num' => 4, 'label' => t('zatca.active_status'),              'color' => '#16a34a', 'bg' => '#dcfce7'],
        'error'               => ['num' => 0, 'label' => t('error'),                        'color' => '#dc2626', 'bg' => '#fef2f2'],
    ];
    $cs = $steps[$zStatus] ?? $steps['not_started'];
?>

<div class="card" id="zatca-settings">
    <div class="card-header"><h3><i class="fas fa-file-invoice" style="color:#16a34a"></i> <?= t('zatca.link_title') ?></h3></div>
    <div class="card-body" style="padding:20px">
    
    <?php if ($zMsg): ?><div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($zMsg) ?></div><?php endif; ?>
    <?php if ($zErr): ?><div style="background:#fef2f2;color:#dc2626;padding:12px 16px;border-radius:8px;margin-bottom:16px"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($zErr) ?></div><?php endif; ?>

    <!-- تفعيل/إيقاف + اختيار البيئة -->
    <form method="POST" style="margin-bottom:20px">
        <?= csrfField() ?>
        <div style="display:flex;align-items:center;gap:16px;padding:16px;background:<?= $zEnabled ? '#f0fdf4' : '#f8fafc' ?>;border-radius:10px;border:1px solid <?= $zEnabled ? '#bbf7d0' : '#e2e8f0' ?>;flex-wrap:wrap">
            <div style="flex:1">
                <label style="font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="hidden" name="zatca_enable" value="0">
                    <input type="checkbox" name="zatca_enable" value="1" <?= $zEnabled ? 'checked' : '' ?> style="width:18px;height:18px" onchange="this.form.submit()">
                    <?= t('settings.zatca') ?>
                </label>
                <div style="font-size:12px;color:#64748b;margin-top:2px"><?= t('zatca.after_enable') ?></div>
            </div>
            <div>
                <select name="zatca_env" class="form-control" style="width:180px;font-size:13px" onchange="this.form.submit()">
                    <option value="sandbox" <?= $zEnv === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                    <option value="simulation" <?= $zEnv === 'simulation' ? 'selected' : '' ?>>Simulation</option>
                    <option value="production" <?= $zEnv === 'production' ? 'selected' : '' ?>>Production</option>
                </select>
            </div>
            <input type="hidden" name="zatca_toggle" value="1">
        </div>
    </form>

    <?php if ($zEnabled): ?>

    <!-- شريط التقدم -->
    <div style="display:flex;gap:4px;margin-bottom:24px">
        <?php 
        $stepNames = [t('zatca.step_csr'), 'Compliance CSID', t('zatca.step_check'), 'Production CSID'];
        $currentStep = $cs['num'];
        foreach ($stepNames as $i => $sn):
            $done = ($i + 1) <= $currentStep;
            $active = ($i + 1) == $currentStep;
        ?>
        <div style="flex:1;text-align:center;padding:8px;border-radius:8px;font-size:12px;font-weight:<?= $active ? '700' : '600' ?>;
            background:<?= $done ? '#dcfce7' : ($active ? '#dbeafe' : '#f1f5f9') ?>;
            color:<?= $done ? '#16a34a' : ($active ? '#1d4ed8' : '#94a3b8') ?>">
            <?= $done ? '<i class="fas fa-check-circle"></i>' : ($active ? '<i class="fas fa-spinner fa-spin"></i>' : '<i class="far fa-circle"></i>') ?>
            <?= $sn ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- الحالة الحالية -->
    <div style="text-align:center;padding:12px;background:<?= $cs['bg'] ?>;border-radius:8px;margin-bottom:20px">
        <span style="color:<?= $cs['color'] ?>;font-weight:700;font-size:14px"><?= $cs['label'] ?></span>
        <?php if ($zStatus === 'active'): ?>
        <div style="font-size:12px;color:#16a34a;margin-top:4px"><?= t('zatca.active_desc') ?></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($zSettings['zatca_last_error'])): ?>
    <div style="padding:10px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;font-size:12px;color:#dc2626;margin-bottom:16px">
        <i class="fas fa-exclamation-triangle"></i> <strong><?= t('zatca.last_error') ?>:</strong> <?= htmlspecialchars($zSettings['zatca_last_error']) ?>
    </div>
    <?php endif; ?>

    <!-- الخطوة 1: توليد CSR -->
    <?php 
    // ★ إصلاح: Error recovery ذكي — لو حصل خطأ نرجع للخطوة الصحيحة مش دايماً CSR
    $showCsrStep = in_array($zStatus, ['not_started']);
    $showComplianceStep = ($zStatus === 'csr_generated');
    $showCheckStep = ($zStatus === 'compliance_obtained');
    $showProductionStep = ($zStatus === 'compliance_checked');
    
    // لو الحالة error، نشوف فين بالظبط وقفنا
    if ($zStatus === 'error') {
        $errorStep = $zSettings['zatca_last_error_step'] ?? '';
        $hasCsr = !empty($zSettings['zatca_csr']);
        $hasComplianceCsid = !empty($zSettings['zatca_compliance_csid']);
        
        if ($errorStep === 'production' && $hasComplianceCsid) {
            // الخطأ في Production → نعيد من Compliance Check
            $showCheckStep = true;
        } elseif ($errorStep === 'compliance_check' && $hasComplianceCsid) {
            // الخطأ في Compliance Check → نعيد Compliance Check
            $showCheckStep = true;
        } elseif ($errorStep === 'compliance' && $hasCsr) {
            // الخطأ في Compliance CSID → نعيد من OTP
            $showComplianceStep = true;
        } else {
            // الخطأ في CSR أو مش معروف → نبدأ من الأول
            $showCsrStep = true;
        }
    }
    ?>
    <?php if ($showCsrStep): ?>
    <div class="card" style="border:2px solid #3b82f6;margin-bottom:16px">
        <div class="card-header" style="background:#eff6ff"><h3 style="color:#1d4ed8"><i class="fas fa-key"></i> <?= t('zatca.step1_title') ?></h3></div>
        <div class="card-body" style="padding:20px">
            <div style="background:#fffbeb;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;color:#92400e;border:1px solid #fde68a">
                <i class="fas fa-info-circle"></i> <?= t('zatca.step1_desc') ?>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group">
                        <label><?= t('zatca.device_name') ?> <small style="color:#94a3b8">(Common Name)</small></label>
                        <input type="text" name="z_common_name" class="form-control" value="EGS-<?= htmlspecialchars($company['company_name_en'] ?? 'URS') ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?= t('zatca.egs_serial') ?> <small style="color:#94a3b8">(EGS Serial)</small></label>
                        <input type="text" name="z_serial" class="form-control" value="1-URS|2-1.0|3-<?= $tid ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?= t('settings.tax_number') ?> <small style="color:#dc2626">*</small> <small style="color:#94a3b8">(15 رقم)</small></label>
                        <input type="text" name="z_vat" class="form-control" value="<?= htmlspecialchars($company['tax_number'] ?? '') ?>" required pattern="3\d{13}3" dir="ltr" maxlength="15">
                    </div>
                    <div class="form-group">
                        <label><?= t('settings.company_name') ?></label>
                        <input type="text" name="z_org_name" class="form-control" value="<?= htmlspecialchars($company['company_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label><?= t('zatca.branch_name') ?> <small style="color:#94a3b8">(Organization Unit)</small></label>
                        <input type="text" name="z_org_unit" class="form-control" value="<?= htmlspecialchars($company['company_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label><?= t('g.invoice_type') ?></label>
                        <select name="z_invoice_type" class="form-control">
                            <option value="0100">B2C</option>
                            <option value="1000">B2B</option>
                            <option value="1100">B2B + B2C</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label><?= t('g.branch_address') ?></label>
                        <input type="text" name="z_location" class="form-control" value="<?= htmlspecialchars($company['address'] ?? '') ?>">
                    </div>
                </div>
                <button type="submit" name="zatca_generate_csr" class="btn btn-primary" style="margin-top:12px"><i class="fas fa-key"></i> <?= t('zatca.generate_csr') ?></button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- الخطوة 2: إرسال CSR + OTP للحصول على Compliance CSID -->
    <?php if ($showComplianceStep): ?>
    <div class="card" style="border:2px solid #3b82f6;margin-bottom:16px">
        <div class="card-header" style="background:#eff6ff"><h3 style="color:#1d4ed8"><i class="fas fa-certificate"></i> <?= t('zatca.step2_title') ?></h3></div>
        <div class="card-body" style="padding:20px">
            <div style="background:#fffbeb;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;color:#92400e;border:1px solid #fde68a">
                <i class="fas fa-info-circle"></i>
                <strong><?= t('zatca.before_step') ?>:</strong> <?= t('zatca.go_to') ?> <a href="https://fatoora.zatca.gov.sa" target="_blank" style="color:#1d4ed8;font-weight:700">بوابة فاتورة</a>
                <?php if ($zEnv === 'sandbox'): ?>
                <div style="margin-top:8px;padding:8px;background:#dbeafe;border-radius:6px;color:#1d4ed8;font-weight:700">
                    <i class="fas fa-flask"></i> بيئة الاختبار (Sandbox): استخدم OTP = <code style="font-size:18px;background:#fff;padding:2px 8px;border-radius:4px">123456</code>
                </div>
                <?php endif; ?>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group" style="max-width:300px">
                    <label><?= t('settings.otp') ?> <small style="color:#dc2626">*</small></label>
                    <input type="text" name="z_otp" class="form-control" required maxlength="6" dir="ltr" placeholder="123456" value="<?= $zEnv === 'sandbox' ? '123345' : '' ?>" style="font-size:20px;text-align:center;letter-spacing:8px;font-weight:700">
                </div>
                <button type="submit" name="zatca_get_compliance" class="btn btn-primary"><i class="fas fa-paper-plane"></i> <?= t('zatca.send_csr') ?></button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- الخطوة 2.5: فحص الامتثال (Compliance Check) - إرسال 6 فواتير تجريبية -->
    <?php if ($showCheckStep): ?>
    <div class="card" style="border:2px solid #8b5cf6;margin-bottom:16px">
        <div class="card-header" style="background:#f5f3ff"><h3 style="color:#7c3aed"><i class="fas fa-clipboard-check"></i> فحص الامتثال (Compliance Check)</h3></div>
        <div class="card-body" style="padding:20px">
            <div style="background:#fef3c7;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;color:#92400e;border:1px solid #fde68a">
                <i class="fas fa-info-circle"></i>
                <strong>هذه الخطوة مطلوبة:</strong> سيتم إرسال 6 فواتير تجريبية تلقائياً لنظام ZATCA للتحقق من امتثال النظام.
                <div style="margin-top:8px;font-size:12px">
                    الفواتير: فاتورة ضريبية (Standard) + مبسطة (Simplified) + إشعار دائن Standard + إشعار دائن Simplified + إشعار مدين Standard + إشعار مدين Simplified
                </div>
            </div>
            <?php if (!empty($_SESSION['zatca_compliance_details'])): ?>
            <div style="margin-bottom:16px;font-size:12px">
                <table style="width:100%;border-collapse:collapse">
                    <tr style="background:#f1f5f9"><th style="padding:6px 8px;text-align:right;border:1px solid #e2e8f0">النوع</th><th style="padding:6px 8px;text-align:center;border:1px solid #e2e8f0">الكود</th><th style="padding:6px 8px;text-align:center;border:1px solid #e2e8f0">النتيجة</th><th style="padding:6px 8px;text-align:right;border:1px solid #e2e8f0">التفاصيل</th></tr>
                    <?php foreach ($_SESSION['zatca_compliance_details'] as $cd): ?>
                    <tr>
                        <td style="padding:6px 8px;border:1px solid #e2e8f0"><?= htmlspecialchars($cd['type_label'] ?? '') ?></td>
                        <td style="padding:6px 8px;text-align:center;border:1px solid #e2e8f0;font-size:11px"><?= $cd['http_code'] ?? '—' ?></td>
                        <td style="padding:6px 8px;text-align:center;border:1px solid #e2e8f0"><?= $cd['success'] ? '<span style="color:#16a34a">✅ نجح</span>' : '<span style="color:#dc2626">❌ فشل</span>' ?></td>
                        <td style="padding:6px 8px;border:1px solid #e2e8f0;font-size:11px;direction:ltr;text-align:left">
                            <?= htmlspecialchars($cd['error'] ?? $cd['message'] ?? '') ?>
                            <?php if (!empty($cd['errors'])): ?>
                                <div style="color:#dc2626;margin-top:4px">
                                <?php foreach ($cd['errors'] as $err): ?>
                                    <div>• <?= htmlspecialchars(is_array($err) ? ($err['message'] ?? json_encode($err)) : $err) ?></div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($cd['warnings'])): ?>
                                <div style="color:#f59e0b;margin-top:4px">
                                <?php foreach ($cd['warnings'] as $w): ?>
                                    <div>⚠ <?= htmlspecialchars(is_array($w) ? ($w['message'] ?? json_encode($w)) : $w) ?></div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; unset($_SESSION['zatca_compliance_details']); ?>
                </table>
            </div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <button type="submit" name="zatca_compliance_check" value="1" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed" onclick="this.disabled=true;this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> جاري فحص الامتثال...';"><i class="fas fa-clipboard-check"></i> بدء فحص الامتثال (6 فواتير)</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- الخطوة 3: الحصول على Production CSID -->
    <?php if ($showProductionStep): ?>
    <div class="card" style="border:2px solid #16a34a;margin-bottom:16px">
        <div class="card-header" style="background:#f0fdf4"><h3 style="color:#16a34a"><i class="fas fa-check-double"></i> <?= t('zatca.step3_title') ?></h3></div>
        <div class="card-body" style="padding:20px">
            <div style="background:#f0fdf4;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;color:#166534;border:1px solid #bbf7d0">
                <i class="fas fa-check-circle"></i> <?= t('zatca.step3_desc') ?>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <button type="submit" name="zatca_get_production" class="btn btn-primary" style="background:#16a34a;border-color:#16a34a" onclick="return confirm('<?= t('zatca.step3_desc') ?>')"><i class="fas fa-rocket"></i> <?= t('zatca.production_obtained') ?></button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- الحالة النهائية: مفعّل -->
    <?php if ($zStatus === 'active'): ?>
    <div style="padding:20px;background:#f0fdf4;border-radius:10px;border:2px solid #bbf7d0;text-align:center">
        <div style="font-size:48px;color:#16a34a;margin-bottom:8px"><i class="fas fa-check-circle"></i></div>
        <div style="font-size:18px;font-weight:700;color:#16a34a;margin-bottom:4px"><?= t('zatca.active_status') ?></div>
        <div style="font-size:13px;color:#166534"><?= t('zatca.active_desc') ?></div>
        <div style="margin-top:12px;font-size:12px;color:#64748b"><?= t('zatca.environment') ?>: <strong><?= $zEnv === 'production' ? t('settings.zatca_live') : t('settings.zatca_trial') ?></strong></div>
    </div>
    <?php endif; ?>

    <!-- ★ قسم جديد: معلومات تشخيصية + زر إعادة ضبط -->
    <div style="margin-top:24px;padding-top:20px;border-top:1px solid #e2e8f0">
        
        <!-- معلومات تشخيصية (للساند بوكس) -->
        <?php if ($zEnv === 'sandbox' || $zEnv === 'simulation'): ?>
        <details style="margin-bottom:16px">
            <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#64748b;padding:8px 0">
                <i class="fas fa-bug"></i> معلومات تشخيصية (للمطورين)
            </summary>
            <div style="background:#f8fafc;padding:12px;border-radius:8px;margin-top:8px;font-size:11px;direction:ltr;text-align:left;font-family:monospace;max-height:300px;overflow:auto">
                <div><strong>Status:</strong> <?= htmlspecialchars($zStatus) ?></div>
                <div><strong>Environment:</strong> <?= htmlspecialchars($zEnv) ?></div>
                <div><strong>Base URL:</strong> <?= htmlspecialchars($zatcaService->getBaseUrl()) ?></div>
                <div><strong>Has CSR:</strong> <?= !empty($zSettings['zatca_csr']) ? 'Yes ('.strlen($zSettings['zatca_csr']).' chars)' : 'No' ?></div>
                <div><strong>Has Private Key:</strong> <?= !empty($zSettings['zatca_private_key']) ? 'Yes' : 'No' ?></div>
                <div><strong>Compliance CSID:</strong> <?= !empty($zSettings['zatca_compliance_csid']) ? 'Yes ('.substr($zSettings['zatca_compliance_csid'],0,20).'...)' : 'No' ?></div>
                <div><strong>Compliance Secret:</strong> <?= !empty($zSettings['zatca_compliance_secret']) ? 'Yes' : 'No' ?></div>
                <div><strong>Request ID:</strong> <?= htmlspecialchars($zSettings['zatca_compliance_request_id'] ?? 'N/A') ?></div>
                <div><strong>Production CSID:</strong> <?= !empty($zSettings['zatca_production_csid']) ? 'Yes' : 'No' ?></div>
                <div><strong>Last Error:</strong> <?= htmlspecialchars($zSettings['zatca_last_error'] ?? 'None') ?></div>
                <div><strong>Last Error Step:</strong> <?= htmlspecialchars($zSettings['zatca_last_error_step'] ?? 'None') ?></div>
                <div><strong>EGS Serial:</strong> <?= htmlspecialchars($zSettings['zatca_egs_serial'] ?? 'N/A') ?></div>
                <div><strong>Invoice Type Map:</strong> <?= htmlspecialchars($zSettings['zatca_functionality_map'] ?? 'N/A') ?></div>
            </div>
        </details>
        <?php endif; ?>

        <!-- زر إعادة ضبط -->
        <?php if ($zStatus !== 'not_started'): ?>
        <form method="POST" style="text-align:center;margin-top:12px">
            <?= csrfField() ?>
            <button type="submit" name="zatca_reset" class="btn" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;font-size:12px;padding:6px 16px" 
                onclick="return confirm('⚠️ هل أنت متأكد؟\n\nسيتم حذف جميع بيانات الربط (CSR, CSID, المفاتيح) والبدء من جديد.\n\nهذا الإجراء لا يمكن التراجع عنه.')">
                <i class="fas fa-undo"></i> إعادة ضبط ربط ZATCA والبدء من جديد
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php endif; // zEnabled ?>
    </div>
</div>
<?php endif; // zatcaAdminEnabled ?>

<?php require_once 'includes/footer.php'; ?>
