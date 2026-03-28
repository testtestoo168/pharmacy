<?php
/**
 * URS Pharmacy ERP - Security Service
 * حماية شاملة: CSRF, Rate Limiting, Session, Input Validation
 */

class SecurityService {
    
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // ========== CSRF Protection ==========
    
    public static function generateCsrfToken() {
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) 
            || (time() - $_SESSION['csrf_token_time']) > (int)env('CSRF_TOKEN_LIFETIME', 3600)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function csrfField() {
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(self::generateCsrfToken()) . '">';
    }
    
    public static function verifyCsrfToken() {
        $token = $_POST['_csrf_token'] ?? $_REQUEST['_csrf_token'] ?? '';
        if (empty($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
            throw new Exception(t('security.csrf_expired'));
        }
    }
    
    public static function verifyCsrfAjax() {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf_token'] ?? '';
        if (empty($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
            exit;
        }
    }
    
    // ========== Session Hardening ==========
    
    public static function initSecureSession() {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = env('SESSION_SECURE', true);
            $httponly = env('SESSION_HTTPONLY', true);
            $samesite = env('SESSION_SAMESITE', 'Strict');
            $lifetime = (int)env('SESSION_LIFETIME', 120) * 60;
            
            // session cookie params disabled for HTTP
            
            session_name('URS_SESSION');
            session_start();
            
            // تجديد Session ID كل 30 دقيقة
            if (!isset($_SESSION['_last_regeneration'])) {
                $_SESSION['_last_regeneration'] = time();
            } elseif (time() - $_SESSION['_last_regeneration'] > 1800) {
                session_regenerate_id(true);
                $_SESSION['_last_regeneration'] = time();
            }
            
            // تحقق من User Agent لمنع Session Hijacking
            $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (!isset($_SESSION['_user_agent'])) {
                $_SESSION['_user_agent'] = $currentAgent;
            } elseif ($_SESSION['_user_agent'] !== $currentAgent) {
                session_destroy();
                session_start();
            }
        }
    }
    
    // ========== Login Rate Limiting ==========
    
    public function checkLoginAttempts($username, $ip) {
        $maxAttempts = (int)env('LOGIN_MAX_ATTEMPTS', 5);
        $lockoutMinutes = (int)env('LOGIN_LOCKOUT_MINUTES', 15);
        
        // تأكد من وجود جدول login_attempts
        $this->ensureLoginAttemptsTable();
        
        // نظف المحاولات القديمة
        $this->pdo->prepare(
            "DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        )->execute([$lockoutMinutes]);
        
        // عدّ المحاولات الفاشلة
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE (username = ? OR ip_address = ?) AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$username, $ip, $lockoutMinutes]);
        $attempts = (int)$stmt->fetchColumn();
        
        if ($attempts >= $maxAttempts) {
            // حساب الوقت المتبقي
            $stmt2 = $this->pdo->prepare(
                "SELECT MAX(attempted_at) FROM login_attempts WHERE (username = ? OR ip_address = ?) AND success = 0"
            );
            $stmt2->execute([$username, $ip]);
            $lastAttempt = $stmt2->fetchColumn();
            $unlockTime = strtotime($lastAttempt) + ($lockoutMinutes * 60);
            $remaining = ceil(($unlockTime - time()) / 60);
            
            return [
                'locked' => true,
                'attempts' => $attempts,
                'remaining_minutes' => max(1, $remaining),
                'message' => t('security.account_locked') . ": {$remaining}"
            ];
        }
        
        return [
            'locked' => false,
            'attempts' => $attempts,
            'remaining_attempts' => $maxAttempts - $attempts
        ];
    }
    
    public function recordLoginAttempt($username, $ip, $success) {
        $this->ensureLoginAttemptsTable();
        $this->pdo->prepare(
            "INSERT INTO login_attempts (username, ip_address, success, user_agent) VALUES (?, ?, ?, ?)"
        )->execute([$username, $ip, $success ? 1 : 0, $_SERVER['HTTP_USER_AGENT'] ?? '']);
    }
    
    private function ensureLoginAttemptsTable() {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100),
                ip_address VARCHAR(45),
                success TINYINT(1) DEFAULT 0,
                user_agent TEXT,
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_username (username),
                INDEX idx_ip (ip_address),
                INDEX idx_time (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}
    }
    
    // ========== Password Policy ==========
    
    public static function validatePassword($password) {
        $minLength = (int)env('PASSWORD_MIN_LENGTH', 8);
        $errors = [];
        
        if (strlen($password) < $minLength) {
            $errors[] = t('pwd.too_short');
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "يجب أن تحتوي على حرف كبير واحد على الأقل";
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "يجب أن تحتوي على حرف صغير واحد على الأقل";
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "يجب أن تحتوي على رقم واحد على الأقل";
        }
        
        return empty($errors) ? true : $errors;
    }
    
    // ========== Input Sanitization ==========
    
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public static function sanitizeInt($input) {
        return (int)$input;
    }
    
    public static function sanitizeFloat($input) {
        return (float)$input;
    }
    
    public static function sanitizeEmail($input) {
        return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
    }
    
    // ========== File Upload Protection ==========
    
    public static function validateUpload($file, $allowedTypes = [], $maxSize = 5242880) {
        $errors = [];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = t('security.upload_error');
            return $errors;
        }
        
        if ($file['size'] > $maxSize) {
            $errors[] = t('security.file_too_large');
        }
        
        // التحقق من نوع الملف الفعلي
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        
        $defaultAllowed = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf'
        ];
        $allowed = !empty($allowedTypes) ? $allowedTypes : $defaultAllowed;
        
        if (!in_array($mimeType, $allowed)) {
            $errors[] = t('security.file_type_not_allowed');
        }
        
        // منع الملفات التنفيذية
        $dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'exe', 'sh', 'bat', 'cmd', 'cgi', 'pl', 'py', 'jsp', 'asp', 'aspx'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $dangerousExtensions)) {
            $errors[] = t('security.dangerous_file');
        }
        
        return empty($errors) ? true : $errors;
    }
    
    public static function secureUpload($file, $destination, $allowedTypes = []) {
        $validation = self::validateUpload($file, $allowedTypes);
        if ($validation !== true) {
            return ['success' => false, 'errors' => $validation];
        }
        
        // إنشاء اسم آمن
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $fullPath = rtrim($destination, '/') . '/' . $safeName;
        
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        
        if (move_uploaded_file($file['tmp_name'], $fullPath)) {
            return ['success' => true, 'filename' => $safeName, 'path' => $fullPath];
        }
        
        return ['success' => false, 'errors' => [t('security.save_failed')]];
    }
}
