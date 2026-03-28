<?php
/**
 * URS Pharmacy ERP - Environment Loader
 * تحميل متغيرات البيئة من ملف .env
 */

function loadEnv($path = null) {
    if ($path === null) {
        // من includes/helpers/ نطلع مستويين للوصول لجذر المشروع
        $path = dirname(dirname(__DIR__)) . '/.env';
    }
    
    if (!file_exists($path)) {
        // جرّب المسار البديل
        $altPath = $_SERVER['DOCUMENT_ROOT'] . '/.env';
        if (file_exists($altPath)) {
            $path = $altPath;
        } else {
            return false;
        }
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // تجاهل التعليقات والأسطر الفارغة
        if (empty($line) || $line[0] === '#') continue;
        
        // فصل المفتاح عن القيمة
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        
        // إزالة علامات التنصيص
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        
        // تحويل القيم الخاصة
        $lower = strtolower($value);
        if ($lower === 'true') $value = true;
        elseif ($lower === 'false') $value = false;
        elseif ($lower === 'null') $value = null;
        
        // تخزين في البيئة
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
    
    return true;
}

/**
 * الحصول على متغير بيئة مع قيمة افتراضية
 */
function env($key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) return $default;
    
    // تحويل القيم النصية
    if (is_string($value)) {
        $lower = strtolower($value);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;
    }
    
    return $value;
}

// تحميل البيئة فوراً
loadEnv();
