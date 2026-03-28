<?php

require_once __DIR__ . '/../includes/services/ZATCAService.php';

/**
 * Verify the certificate hash uses base64(hex(SHA256(DER))) — the ZATCA-required format.
 */

// Generate a self-signed test certificate
$keyConfig = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$key = openssl_pkey_new($keyConfig);
$csr = openssl_csr_new(['CN' => 'ZATCA Test'], $key);
$x509 = openssl_csr_sign($csr, null, $key, 365);
openssl_x509_export($x509, $certPem);

// Write temp files for the constructor
$certFile = tempnam(sys_get_temp_dir(), 'cert');
$keyFile  = tempnam(sys_get_temp_dir(), 'key');
file_put_contents($certFile, $certPem);
openssl_pkey_export($key, $privKeyPem);
file_put_contents($keyFile, $privKeyPem);

// --- Compute the expected hash manually ---
// Extract DER from PEM
$pemLines = array_filter(
    explode("\n", $certPem),
    fn($l) => strpos($l, '-----') !== 0 && trim($l) !== ''
);
$derBytes = base64_decode(implode('', $pemLines));

$hexHash      = hash('sha256', $derBytes);          // hex string
$expectedHash = base64_encode($hexHash);             // base64(hex(...))

// --- The OLD (broken) approach for comparison ---
$rawHash       = hash('sha256', $derBytes, true);    // raw 32 bytes
$brokenHash    = base64_encode($rawHash);             // base64(raw bytes)

// --- Get hash from ZATCAService ---
$service    = new ZATCAService($certFile, $keyFile, 'https://example.com');
$actualHash = $service->getCertificateHash();

// Assertions
$passed = 0;
$failed = 0;

// 1. Must match the correct format
if ($actualHash === $expectedHash) {
    echo "PASS: Certificate hash matches base64(hex(SHA256(DER)))\n";
    $passed++;
} else {
    echo "FAIL: Expected '{$expectedHash}', got '{$actualHash}'\n";
    $failed++;
}

// 2. Must NOT match the old broken format
if ($actualHash !== $brokenHash) {
    echo "PASS: Certificate hash does NOT use the broken base64(raw_SHA256) format\n";
    $passed++;
} else {
    echo "FAIL: Certificate hash still uses the broken base64(raw_SHA256) format!\n";
    $failed++;
}

// 3. The hex hash should be 64 chars (SHA-256 = 64 hex chars)
$decoded = base64_decode($actualHash);
if (strlen($decoded) === 64 && ctype_xdigit($decoded)) {
    echo "PASS: Decoded value is a 64-char hex string (correct SHA-256 hex digest)\n";
    $passed++;
} else {
    echo "FAIL: Decoded value is not a valid 64-char hex string (length=" . strlen($decoded) . ")\n";
    $failed++;
}

// Cleanup
unlink($certFile);
unlink($keyFile);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
