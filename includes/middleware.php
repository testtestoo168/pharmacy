<?php
/**
 * URS Pharmacy ERP — Middleware Layer
 * Unified auth, tenant isolation, branch isolation, permissions
 */

class Middleware {
    
    /**
     * Auth + Tenant + Branch + Subscription — كل الفحوصات في مكان واحد
     */
    public static function requireAuth($pdo) {
        // 1. Auth check
        if (!isset($_SESSION['user_id'])) {
            header('Location: login.php'); exit;
        }
        
        // 2. Tenant check
        if (!isset($_SESSION['tenant_id']) || intval($_SESSION['tenant_id']) <= 0) {
            session_destroy();
            header('Location: login.php?error=' . urlencode(t('login.session_expired'))); exit;
        }
        
        // 3. Branch check
        if (!isset($_SESSION['branch_id']) || intval($_SESSION['branch_id']) <= 0) {
            session_destroy();
            header('Location: login.php?error=' . urlencode(t('g.cant_switch_branch'))); exit;
        }
        
        // 4. Subscription check (cached 5 min)
        self::checkSubscription($pdo);
    }
    
    /**
     * فحص الاشتراك — مع cache
     */
    private static function checkSubscription($pdo) {
        if (($_SESSION['role'] ?? '') === 'super_admin') return;
        if (isset($_SESSION['sub_checked']) && $_SESSION['sub_checked'] > time() - 300) return;
        
        try {
            $s = $pdo->prepare("SELECT status, subscription_end, grace_period_days FROM tenants WHERE id = ?");
            $s->execute([intval($_SESSION['tenant_id'])]);
            $t = $s->fetch();
            
            if (!$t) {
                session_destroy();
                header('Location: login.php?error=' . urlencode(t('g.not_found'))); exit;
            }
            
            $_SESSION['sub_checked'] = time();
            
            if ($t['status'] === 'suspended' || $t['status'] === 'cancelled') {
                session_destroy();
                header('Location: login.php?error=' . urlencode(t('login.suspended'))); exit;
            }
            
            if ($t['subscription_end']) {
                $grace = intval($t['grace_period_days'] ?? 0);
                $deadline = strtotime($t['subscription_end'] . " +$grace days");
                if (time() > $deadline) {
                    $pdo->prepare("UPDATE tenants SET status='suspended' WHERE id=?")->execute([intval($_SESSION['tenant_id'])]);
                    session_destroy();
                    header('Location: login.php?error=' . urlencode(t('login.expired'))); exit;
                }
            }
        } catch(Exception $e) {
            error_log("Middleware subscription check: " . $e->getMessage());
        }
    }
    
    /**
     * Tenant-safe query helper
     */
    public static function tenantId() {
        return intval($_SESSION['tenant_id'] ?? 0);
    }
    
    /**
     * Branch-safe query helper
     */
    public static function branchId() {
        return intval($_SESSION['branch_id'] ?? 0);
    }
    
    /**
     * Check permission — admin has all
     */
    public static function hasPermission($perm) {
        if (!isset($_SESSION['user_id'])) return false;
        if (($_SESSION['role'] ?? '') === 'admin') return true;
        return in_array($perm, $_SESSION['permissions'] ?? []);
    }
    
    /**
     * Require permission — redirect if not allowed
     */
    public static function requirePermission($perm) {
        if (!self::hasPermission($perm)) {
            http_response_code(403);
            echo '<div style="text-align:center;padding:50px;font-family:Tajawal,sans-serif;"><h2>' . t('unauthorized') . '</h2><p>' . t('no_permission') . '</p><a href="index.php">' . t('nav.home') . '</a></div>';
            exit;
        }
    }
}
