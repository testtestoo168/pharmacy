<?php
session_start();
require_once 'includes/config.php';
$zatcaService = new ZATCAIntegrationService($pdo);
$company = getCompanySettings($pdo);
echo "Starting compliance check...\n";
$results = $zatcaService->runComplianceChecks($company);
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
