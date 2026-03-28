<?php
require_once 'includes/config.php';
$s = $pdo->prepare("SELECT zatca_csr FROM compliance_settings WHERE tenant_id=8");
$s->execute();
$r = $s->fetch();
$csr = $r['zatca_csr'];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/compliance',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json','Accept-Version: V2','OTP: 123456'],
    CURLOPT_POSTFIELDS => json_encode(['csr' => $csr]),
    CURLOPT_SSL_VERIFYPEER => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP CODE: $code\n";
echo "CURL ERROR: $err\n";
echo "RESPONSE: $resp\n";
echo "CSR LENGTH: " . strlen($csr) . "\n";
echo "CSR FIRST 50: " . substr($csr, 0, 50) . "\n";
