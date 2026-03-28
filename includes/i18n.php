<?php
/**
 * URS Pharmacy ERP - Internationalization (i18n) System
 * نظام الترجمة - عربي / إنجليزي
 * 
 * Uses t() as the primary function to avoid conflict with PHP gettext __()
 * Also defines __() only if gettext is NOT loaded
 */

define('DEFAULT_LANG', 'ar');
define('SUPPORTED_LANGS', ['ar', 'en']);

$_TRANSLATIONS = null;
$_LANG_INITIALIZED = false;

/**
 * Initialize language — MUST be called AFTER session_start()
 */
function initLanguage() {
    global $_LANG_INITIALIZED;
    if ($_LANG_INITIALIZED) return currentLang();
    $_LANG_INITIALIZED = true;

    // Check URL parameter first (for switching)
    if (isset($_GET['lang']) && in_array($_GET['lang'], SUPPORTED_LANGS)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lang'] = $_GET['lang'];
        }
        setcookie('urs_lang', $_GET['lang'], time() + (365 * 24 * 60 * 60), '/');
        $url = strtok($_SERVER['REQUEST_URI'], '?');
        $params = $_GET;
        unset($params['lang']);
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        header("Location: $url");
        exit;
    }
    
    // Check session
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['lang']) && in_array($_SESSION['lang'], SUPPORTED_LANGS)) {
        return $_SESSION['lang'];
    }
    
    // Check cookie
    if (isset($_COOKIE['urs_lang']) && in_array($_COOKIE['urs_lang'], SUPPORTED_LANGS)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['lang'] = $_COOKIE['urs_lang'];
        }
        return $_COOKIE['urs_lang'];
    }
    
    // Default
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['lang'] = DEFAULT_LANG;
    }
    return DEFAULT_LANG;
}

function currentLang() {
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['lang'])) {
        return $_SESSION['lang'];
    }
    if (isset($_COOKIE['urs_lang']) && in_array($_COOKIE['urs_lang'], SUPPORTED_LANGS)) {
        return $_COOKIE['urs_lang'];
    }
    return DEFAULT_LANG;
}

function isRTL() { return currentLang() === 'ar'; }
function langDir() { return isRTL() ? 'rtl' : 'ltr'; }
function langCode() { return currentLang(); }

function loadTranslations() {
    global $_TRANSLATIONS;
    if ($_TRANSLATIONS !== null) return $_TRANSLATIONS;
    $lang = currentLang();
    $file = __DIR__ . "/lang/{$lang}.php";
    if (file_exists($file)) {
        $_TRANSLATIONS = require $file;
    } else {
        $_TRANSLATIONS = require __DIR__ . '/lang/ar.php';
    }
    return $_TRANSLATIONS;
}

/**
 * Primary translation function — safe name, no conflicts
 */
function t($key, $params = []) {
    global $_TRANSLATIONS;
    if ($_TRANSLATIONS === null) loadTranslations();
    
    $value = $_TRANSLATIONS;
    foreach (explode('.', $key) as $segment) {
        if (is_array($value) && isset($value[$segment])) {
            $value = $value[$segment];
        } else {
            return $key;
        }
    }
    
    if (!empty($params) && is_string($value)) {
        foreach ($params as $k => $v) {
            $value = str_replace(":{$k}", $v, $value);
        }
    }
    return $value;
}

// Define __() ONLY if gettext hasn't already defined it
if (!function_exists('__')) {
    function __($key, $params = []) {
        return t($key, $params);
    }
}

function _e($key, $params = []) { echo t($key, $params); }
function otherLang() { return currentLang() === 'ar' ? 'en' : 'ar'; }
function otherLangLabel() { return currentLang() === 'ar' ? 'English' : 'العربية'; }
function langSwitchUrl($lang = null) {
    $lang = $lang ?? otherLang();
    $url = strtok($_SERVER['REQUEST_URI'], '?');
    $params = $_GET;
    $params['lang'] = $lang;
    return $url . '?' . http_build_query($params);
}
