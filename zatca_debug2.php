<?php
require_once 'includes/config.php';

// Generate fresh CSR
$params = json_encode([
    'common_name' => 'EGS-URS-Pharmacy',
    'serial_number' => '1-URS|2-1.0|3-' . time(),
    'org_identifier' => '300000000000003',
    'org_unit_name' => 'URS Pharmacy',
    'org_name' => 'URS Pharmacy Co',
    'invoice_type' => '0100',
    'location' => 'Riyadh',
    'industry' => 'Pharmacy',
    'env' => 'sandbox'
]);

$tmpIn = tempnam(sys_get_temp_dir(), 'zatca_');
file_put_contents($tmpIn, $params);
$output = shell_exec('/usr/bin/python3 /var/www/pharmacy/zatca_csr_gen.py < ' . escapeshellarg($tmpIn));
unlink($tmpIn);

$result = json_decode($output, true);
$csr = $result['csr_b64'];

echo "CSR LENGTH: " . strlen($csr) . "\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/compliance',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json','Accept-Version: V2','OTP: 123345'],
    CURLOPT_POSTFIELDS => json_encode(['csr' => $csr]),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'curl/8.5.0',
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP CODE: $code\n";
echo "RESPONSE: $resp\n";
