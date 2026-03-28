<?php
/**
 * URS Pharmacy ERP - Database Service
 * الاتصال بقاعدة البيانات مع دعم Multi-Tenant
 */

require_once __DIR__ . '/../helpers/env.php';

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $host = env('DB_HOST');
        $name = env('DB_NAME');
        $user = env('DB_USER');
        $pass = env('DB_PASS');
        $charset = env('DB_CHARSET', 'utf8mb4');
        
        if (!$host || !$name || !$user) {
            die('<div style="text-align:center;padding:50px;font-family:Tajawal;direction:rtl;">
                <h2 style="color:#dc2626;">Configuration Error</h2>
                <p style="color:#666;">Check .env configuration</p></div>');
        }
        
        try {
            $this->pdo = new PDO(
                "mysql:host={$host};dbname={$name};charset={$charset}",
                $user, $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci"
                ]
            );
        } catch (PDOException $e) {
            error_log("DB Connection Error: " . $e->getMessage());
            die('<div style="text-align:center;padding:50px;font-family:Tajawal;direction:rtl;">
                <h2 style="color:#dc2626;">Database Error</h2>
                <p style="color:#666;">Database connection failed. Check .env</p></div>');
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * استعلام مع tenant isolation تلقائي
     */
    public function tenantQuery($sql, $params = [], $tenantId = null) {
        if ($tenantId === null) {
            $tenantId = $_SESSION['tenant_id'] ?? null;
        }
        if ($tenantId) {
            $params[':tenant_id'] = $tenantId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    // منع النسخ
    private function __clone() {}
    public function __wakeup() { throw new \Exception("Cannot unserialize singleton"); }
}
