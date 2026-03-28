<?php
/**
 * ZATCA Compliance Test Script - CLI Only
 * Usage: php test_zatca_cli.php [step]
 * Steps: csr | csid | check | full
 */

// Bootstrap minimal environment without session
define('TENANT_ID', 8);
define('BRANCH_ID', 1);

// Load env (parse_ini_file fails on ! in values, do it manually)
$envRaw = file_get_contents(__DIR__ . '/.env');
$env = [];
foreach (explode("\n", $envRaw) as $line) {
    $line = trim($line);
    if (!$line || $line[0] === '#') continue;
    if (strpos($line, '=') !== false) {
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\n\r\"'");
    }
}

// Try socket first, then TCP
$dsn = "mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname={$env['DB_NAME']};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Exception $e) {
    $dsn2 = "mysql:host=127.0.0.1;port=3306;dbname={$env['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn2, $env['DB_USER'], $env['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

// Stub session functions so ZATCAService doesn't crash
$_SESSION = ['tenant_id' => TENANT_ID, 'branch_id' => BRANCH_ID];
function getTenantId() { return TENANT_ID; }

require_once __DIR__ . '/includes/services/ZATCAService.php';

$step = $argv[1] ?? 'status';
$zatca = new ZATCAIntegrationService($pdo, TENANT_ID, BRANCH_ID);
$settings = $zatca->getSettings();

echo "=== ZATCA CLI Test ===\n";
echo "Tenant: " . TENANT_ID . " | Step: $step\n";
echo "Current Status: " . ($settings['zatca_onboarding_status'] ?? 'not_started') . "\n";
echo "Has CSR: " . (empty($settings['zatca_csr']) ? 'NO' : 'YES ('.strlen($settings['zatca_csr']).' chars)') . "\n";
echo "Has CSID: " . (empty($settings['zatca_compliance_csid']) ? 'NO' : 'YES ('.strlen($settings['zatca_compliance_csid']).' chars)') . "\n";
echo "Has Secret: " . (empty($settings['zatca_compliance_secret']) ? 'NO' : 'YES') . "\n";
echo "Env: " . ($settings['zatca_env'] ?? 'sandbox') . "\n\n";

// ===== STEP: status =====
if ($step === 'status') {
    echo "Use: php test_zatca_cli.php [csr|csid|check|full|rawtest]\n";
    echo "\n--- Full settings dump ---\n";
    foreach ($settings as $k => $v) {
        if (in_array($k, ['zatca_private_key', 'zatca_csr', 'zatca_compliance_csid', 'zatca_production_csid'])) {
            echo "$k: " . (empty($v) ? 'NULL' : '['.strlen($v).' chars]') . "\n";
        } else {
            echo "$k: " . ($v ?? 'NULL') . "\n";
        }
    }
    exit(0);
}

// ===== STEP: rawtest — directly test ZATCA API connectivity =====
if ($step === 'rawtest') {
    echo "Testing ZATCA sandbox API connectivity...\n";
    $url = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/compliance';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Accept-Version: V2', 'OTP: 123456'],
        CURLOPT_POSTFIELDS => json_encode(['csr' => 'test']),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $err = curl_error($ch);
    curl_close($ch);
    echo "HTTP: $code | Time: {$time}s\n";
    echo "Error: " . ($err ?: 'none') . "\n";
    echo "Response: " . substr($resp, 0, 500) . "\n";
    exit(0);
}

// ===== STEP: csr — generate new CSR =====
if ($step === 'csr' || $step === 'full') {
    echo "=== STEP 1: Generate CSR ===\n";
    // Use standard ZATCA sandbox test VAT number
    $taxNumber = '300000000000003';
    $result = $zatca->generateCSR([
        'common_name'    => 'EGS-URS-Pharmacy',
        'serial_number'  => '1-URS|2-1.0|3-' . time(),
        'org_identifier' => $taxNumber,
        'org_unit_name'  => '3000000000',
        'org_name'       => '3000000000',
        'country'        => 'SA',
        'invoice_type'   => '1100',
        'location'       => 'Riyadh',
        'industry'       => 'Pharmacy',
    ]);
    echo "Result: " . ($result['success'] ? "SUCCESS" : "FAILED") . "\n";
    if (!$result['success']) {
        echo "Error: " . $result['error'] . "\n";
        if ($step === 'full') exit(1);
    } else {
        echo "CSR length: " . strlen($result['csr']) . " chars\n";
        echo "Private key: " . (empty($result['private_key']) ? 'MISSING!' : 'OK') . "\n";
    }
    echo "\n";
    if ($step === 'csr') exit(0);
}

// ===== STEP: csid — get compliance CSID =====
if ($step === 'csid' || $step === 'full') {
    echo "=== STEP 2: Get Compliance CSID (OTP=123456) ===\n";
    // Re-read settings in case CSR was just generated
    $settings = $zatca->getSettings();
    if (empty($settings['zatca_csr'])) {
        echo "ERROR: No CSR found. Run 'csr' step first.\n";
        if ($step === 'full') exit(1);
    } else {
        $result = $zatca->requestComplianceCSID('123456');
        echo "Result: " . ($result['success'] ? "SUCCESS" : "FAILED") . "\n";
        if (!$result['success']) {
            echo "Error: " . ($result['error'] ?? 'Unknown') . "\n";
            echo "HTTP Code: " . ($result['http_code'] ?? 'N/A') . "\n";
            echo "Raw response: " . substr($result['raw_response'] ?? '', 0, 1000) . "\n";
            if ($step === 'full') exit(1);
        } else {
            $s2 = $zatca->getSettings();
            echo "CSID length: " . strlen($s2['zatca_compliance_csid'] ?? '') . " chars\n";
            echo "Secret: " . (empty($s2['zatca_compliance_secret']) ? 'MISSING!' : 'OK') . "\n";
            echo "Request ID: " . ($s2['zatca_compliance_request_id'] ?? 'MISSING!') . "\n";
        }
    }
    echo "\n";
    if ($step === 'csid') exit(0);
}

// ===== STEP: check — run compliance checks =====
if ($step === 'check' || $step === 'full') {
    echo "=== STEP 3: Run Compliance Checks (6 invoices) ===\n";
    $settings = $zatca->getSettings();
    if (empty($settings['zatca_compliance_csid'])) {
        echo "ERROR: No compliance CSID. Run 'csid' step first.\n";
        exit(1);
    }

    $company = [
        'company_name'    => 'URS Pharmacy Test',
        'tax_number'      => '300000000000003',
        'cr_number'       => '1234567890',
        'address'         => 'King Fahd Road, Riyadh',
        'street'          => 'King Fahd Road',
        'city'            => 'Riyadh',
        'district'        => 'Al Olaya',
        'building_number' => '1234',
        'postal_code'     => '12345',
        'currency'        => 'SAR',
        'vat_rate'        => 15,
    ];

    $results = $zatca->runComplianceChecks($company);

    $passed = 0; $failed = 0;
    foreach ($results as $r) {
        $icon = $r['success'] ? '✅' : '❌';
        $label = $r['type_label'] ?? 'Unknown';
        $http = $r['http_code'] ?? '?';
        echo "$icon [$http] $label\n";
        if (!$r['success']) {
            echo "   Error: " . ($r['error'] ?? 'unknown') . "\n";
            if (!empty($r['errors'])) {
                foreach ($r['errors'] as $e) {
                    $msg = is_array($e) ? ($e['message'] ?? json_encode($e)) : $e;
                    echo "   - $msg\n";
                }
            }
            $failed++;
        } else {
            if (!empty($r['warnings'])) {
                foreach ($r['warnings'] as $w) {
                    $msg = is_array($w) ? ($w['message'] ?? json_encode($w)) : $w;
                    echo "   ⚠ $msg\n";
                }
            }
            $passed++;
        }
    }

    echo "\n--- Summary: $passed passed, $failed failed ---\n";
    if ($failed === 0 && $passed > 0) {
        echo "All compliance checks PASSED! Status updated to compliance_checked.\n";
    }
}
