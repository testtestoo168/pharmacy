<?php
require_once 'includes/config.php';
$s = $pdo->prepare("SELECT zatca_csr FROM compliance_settings WHERE tenant_id=8");
$s->execute();
$r = $s->fetch();
$csr = $r['zatca_csr'];

$body = json_encode(['csr' => $csr]);
echo "BODY LENGTH: " . strlen($body) . "\n";
echo "BODY: " . $body . "\n";
