<?php
/**
 * URS Pharmacy - Test File
 * Upload this to check if the system works
 * Access: https://pharmacy-urs.moassasty.com/test_i18n.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>URS i18n Test</h2>";
echo "<pre>";

// 1. Check PHP version
echo "1. PHP Version: " . PHP_VERSION . "\n";

// 2. Check gettext
echo "2. gettext loaded: " . (extension_loaded('gettext') ? 'YES ⚠ (this is why __() was failing!)' : 'NO') . "\n";
echo "   function_exists('__'): " . (function_exists('__') ? 'YES (gettext defines it)' : 'NO') . "\n";

// 3. Check session
echo "3. Session status before: " . session_status() . "\n";

// 4. Try loading config
echo "4. Loading config...\n";
try {
    require_once 'includes/config.php';
    echo "   ✅ config.php loaded OK\n";
} catch (Throwable $e) {
    echo "   ❌ Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// 5. Check session after
echo "5. Session status after: " . session_status() . "\n";

// 6. Test translation function
echo "6. Testing t() function:\n";
if (function_exists('t')) {
    echo "   t('nav.dashboard') = " . t('nav.dashboard') . "\n";
    echo "   t('nav.settings') = " . t('nav.settings') . "\n";
    echo "   t('login.title') = " . t('login.title') . "\n";
    echo "   ✅ Translations working!\n";
} else {
    echo "   ❌ t() function not found\n";
}

// 7. Check language
echo "7. Current language: " . currentLang() . "\n";
echo "   Direction: " . langDir() . "\n";

// 8. Check lang files exist
echo "8. Lang files:\n";
echo "   ar.php: " . (file_exists(__DIR__.'/includes/lang/ar.php') ? '✅ exists' : '❌ missing') . "\n";
echo "   en.php: " . (file_exists(__DIR__.'/includes/lang/en.php') ? '✅ exists' : '❌ missing') . "\n";

// 9. Test switching
echo "\n9. Switch language: ";
echo "<a href='?lang=en'>English</a> | <a href='?lang=ar'>العربية</a>\n";

echo "</pre>";
echo "<p style='color:green;font-size:18px;font-weight:bold;'>If you see this, PHP is working correctly!</p>";
