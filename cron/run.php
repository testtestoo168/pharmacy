<?php
/**
 * URS Pharmacy ERP — Cron Jobs
 * Run: 15 * * * * php /path/to/cron/run.php
 */

require_once __DIR__ . '/../includes/helpers/env.php';
require_once __DIR__ . '/../includes/services/Database.php';

try {
    $pdo = Database::getInstance()->getPdo();
} catch(Exception $e) {
    error_log("CRON ERROR: " . $e->getMessage());
    exit(1);
}

$now = date('Y-m-d H:i:s');
echo "[$now] Starting cron jobs...\n";

// Job 1: فحص الأدوية القريبة من الانتهاء
function jobCheckExpiring($pdo) {
    $tenants = $pdo->query("SELECT id FROM tenants WHERE status='active'")->fetchAll();
    foreach ($tenants as $t) {
        $tid = $t['id'];
        // فحص هل الإشعار اتبعت اليوم
        $sent = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE tenant_id=? AND type='expiry_alert' AND DATE(created_at)=CURDATE()");
        $sent->execute([$tid]);
        if ($sent->fetchColumn() > 0) continue; // skip if already sent today
        
        $exp = $pdo->prepare("SELECT COUNT(*) FROM inventory_batches ib JOIN products p ON p.id=ib.product_id WHERE ib.tenant_id=? AND ib.available_qty>0 AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
        $exp->execute([$tid]);
        $count = $exp->fetchColumn();
        if ($count > 0) {
            $pdo->prepare("INSERT INTO notifications (tenant_id, title, message, type, priority) VALUES (?,?,?,?,?)")
                ->execute([$tid, 'تنبيه صلاحية', "يوجد $count دفعة قريبة من انتهاء الصلاحية", 'expiry_alert', 'high']);
        }
    }
    echo "  [OK] Expiry check done\n";
}

// Job 2: فحص نقص المخزون
function jobCheckLowStock($pdo) {
    $tenants = $pdo->query("SELECT id FROM tenants WHERE status='active'")->fetchAll();
    foreach ($tenants as $t) {
        $tid = $t['id'];
        $sent = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE tenant_id=? AND type='low_stock' AND DATE(created_at)=CURDATE()");
        $sent->execute([$tid]);
        if ($sent->fetchColumn() > 0) continue;
        
        $low = $pdo->prepare("SELECT COUNT(*) FROM products WHERE tenant_id=? AND is_active=1 AND stock_qty <= min_stock AND stock_qty > 0");
        $low->execute([$tid]);
        $count = $low->fetchColumn();
        if ($count > 0) {
            $pdo->prepare("INSERT INTO notifications (tenant_id, title, message, type, priority) VALUES (?,?,?,?,?)")
                ->execute([$tid, 'نقص مخزون', "يوجد $count صنف بمخزون منخفض", 'low_stock', 'medium']);
        }
    }
    echo "  [OK] Low stock check done\n";
}

// Job 3: فحص الاشتراكات المنتهية
function jobCheckSubscriptions($pdo) {
    // إيقاف الصيدليات المنتهية بعد فترة السماح
    $expired = $pdo->query("SELECT id, name FROM tenants WHERE status='active' AND subscription_end IS NOT NULL AND DATE_ADD(subscription_end, INTERVAL COALESCE(grace_period_days,0) DAY) < CURDATE()")->fetchAll();
    foreach ($expired as $t) {
        $pdo->prepare("UPDATE tenants SET status='suspended' WHERE id=?")->execute([$t['id']]);
        echo "  [SUSPEND] {$t['name']} (subscription expired)\n";
    }
    
    // تنبيه الصيدليات اللي باقيلها 7 أيام
    $expiring = $pdo->query("SELECT id, name, subscription_end FROM tenants WHERE status='active' AND subscription_end IS NOT NULL AND subscription_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchAll();
    foreach ($expiring as $t) {
        $sent = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE tenant_id=? AND type='subscription_expiry' AND DATE(created_at)=CURDATE()");
        $sent->execute([$t['id']]);
        if ($sent->fetchColumn() > 0) continue;
        
        $days = floor((strtotime($t['subscription_end']) - time()) / 86400);
        $pdo->prepare("INSERT INTO notifications (tenant_id, title, message, type, priority) VALUES (?,?,?,?,?)")
            ->execute([$t['id'], 'اشتراكك ينتهي قريباً', "اشتراكك ينتهي بعد $days يوم في {$t['subscription_end']}. جدد اشتراكك لتجنب التوقف.", 'subscription_expiry', 'critical']);
    }
    echo "  [OK] Subscription check done (expired: " . count($expired) . ", expiring: " . count($expiring) . ")\n";
}

// Job 4: تنظيف
function jobCleanup($pdo) {
    // حذف الإشعارات القديمة (أكثر من 90 يوم)
    $pdo->exec("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    // حذف سجل العمليات القديم (أكثر من 180 يوم)
    $pdo->exec("DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
    // حذف محاولات الدخول القديمة
    try { $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"); } catch(Exception $e) {}
    echo "  [OK] Cleanup done\n";
}

// تشغيل
try {
    jobCheckExpiring($pdo);
    jobCheckLowStock($pdo);
    jobCheckSubscriptions($pdo);
    jobCleanup($pdo);
    echo "[$now] All jobs completed.\n";
} catch(Exception $e) {
    error_log("CRON FATAL: " . $e->getMessage());
    echo "FATAL: " . $e->getMessage() . "\n";
}
