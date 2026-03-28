<?php
/**
 * URS Pharmacy ERP - Tenant Service
 * ادارة الصيدليات: اضافة يدوي فقط - ايقاف - تفعيل - مسح كامل
 */

class TenantService {
    
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * اضافة صيدلية جديدة (من Super Admin فقط)
     * الصيدلية تبدا فاضية تماما - صفحة نظيفة
     */
    public function createTenant($data) {
        $this->pdo->beginTransaction();
        try {
            // 1. انشاء الصيدلية
            $stmt = $this->pdo->prepare("INSERT INTO tenants 
                (name, name_en, slug, email, phone, address, city, country, 
                 tax_number, cr_number, license_number, owner_name, status, plan_id) 
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $data['name'], $data['name_en'] ?? '', 
                $this->generateSlug($data['name_en'] ?: $data['name']),
                $data['email'] ?? '', $data['phone'] ?? '', $data['address'] ?? '',
                $data['city'] ?? '', $data['country'] ?? 'SA',
                $data['tax_number'] ?? '', $data['cr_number'] ?? '',
                $data['license_number'] ?? '', $data['owner_name'] ?? '',
                'active', $data['plan_id'] ?? 2
            ]);
            $tenantId = $this->pdo->lastInsertId();
            
            // 2. انشاء الفرع الرئيسي
            $stmt = $this->pdo->prepare("INSERT INTO branches (tenant_id, name, is_main, is_active) VALUES (?,?,1,1)");
            $stmt->execute([$tenantId, t('branches.main_branch')]);
            $branchId = $this->pdo->lastInsertId();
            
            // 3. انشاء مستخدم المدير
            $password = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("INSERT INTO users 
                (tenant_id, branch_id, username, password, full_name, role, is_active) 
                VALUES (?,?,?,?,?,?,1)");
            $stmt->execute([
                $tenantId, $branchId,
                $data['admin_username'],
                $password,
                $data['owner_name'] ?: t('users.admin'),
                'admin'
            ]);
            
            // 4. اعدادات الصيدلية
            $stmt = $this->pdo->prepare("INSERT INTO tenant_settings 
                (tenant_id, company_name, company_name_en, vat_rate, currency) 
                VALUES (?,?,?,?,?)");
            $stmt->execute([
                $tenantId, $data['name'], $data['name_en'] ?? '',
                $data['vat_rate'] ?? 15.00, 'SAR'
            ]);
            
            // 5. شجرة حسابات افتراضية
            $this->createDefaultAccounts($tenantId);
            
            // 6. تصنيفات افتراضية
            $this->createDefaultCategories($tenantId);
            
            // 7. اعدادات الولاء
            $this->pdo->prepare("INSERT INTO loyalty_settings (tenant_id) VALUES (?)")->execute([$tenantId]);
            
            // لا اشتراك تجريبي - لا باقات - الصيدلية نشطة مباشرة
            
            $this->pdo->commit();
            return ['success' => true, 'tenant_id' => $tenantId, 'branch_id' => $branchId];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * ايقاف صيدلية (بدون مسح بياناتها)
     */
    public function suspendTenant($tenantId) {
        $this->pdo->prepare("UPDATE tenants SET status = 'suspended', updated_at = NOW() WHERE id = ?")
            ->execute([$tenantId]);
        return true;
    }
    
    /**
     * تفعيل صيدلية موقوفة
     */
    public function activateTenant($tenantId) {
        $this->pdo->prepare("UPDATE tenants SET status = 'active', updated_at = NOW() WHERE id = ?")
            ->execute([$tenantId]);
        return true;
    }
    
    /**
     * مسح صيدلية نهائيا مع كل بياناتها
     * الاصناف - المخزون - الفواتير - المحاسبة - الموظفين - كل حاجة
     */
    /**
     * حذف صيدلية — مع soft delete أو hard delete
     * @param int $tenantId
     * @param bool $hardDelete — true = مسح نهائي، false = تعليق فقط (soft delete)
     */
    public function deleteTenant($tenantId, $hardDelete = true) {
        $tid = intval($tenantId);
        if ($tid <= 0) return ['success' => false, 'error' => t('error')];
        
        // جلب بيانات الصيدلية قبل المسح
        $tenantInfo = null;
        try {
            $t = $this->pdo->prepare("SELECT id, name, email, phone, status, subscription_end, created_at FROM tenants WHERE id=?");
            $t->execute([$tid]);
            $tenantInfo = $t->fetch();
        } catch(Exception $e) {}
        
        if (!$tenantInfo) return ['success' => false, 'error' => t('g.not_found')];
        
        // Audit trail مفصّل
        $auditData = json_encode([
            'action' => $hardDelete ? 'HARD_DELETE' : 'SOFT_DELETE',
            'tenant_id' => $tid,
            'tenant_name' => $tenantInfo['name'] ?? '',
            'tenant_email' => $tenantInfo['email'] ?? '',
            'tenant_status' => $tenantInfo['status'] ?? '',
            'subscription_end' => $tenantInfo['subscription_end'] ?? '',
            'created_at' => $tenantInfo['created_at'] ?? '',
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => 'super_admin#' . ($_SESSION['super_admin_id'] ?? 'unknown'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        error_log("[TENANT_DELETE] $auditData");
        
        // Soft delete = تعليق فقط
        if (!$hardDelete) {
            try {
                $this->pdo->prepare("UPDATE tenants SET status='cancelled', updated_at=NOW() WHERE id=?")->execute([$tid]);
                return ['success' => true, 'mode' => 'soft_delete'];
            } catch(Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }
        
        // Hard delete
        $this->pdo->beginTransaction();
        try {
            // مسح كل الجداول المرتبطة بالترتيب (عشان الـ Foreign Keys)
            $tables = [
                // بنود اولا
                'journal_entry_lines' => "DELETE FROM journal_entry_lines WHERE entry_id IN (SELECT id FROM journal_entries WHERE tenant_id = $tid)",
                'sales_invoice_items' => "DELETE FROM sales_invoice_items WHERE invoice_id IN (SELECT id FROM sales_invoices WHERE tenant_id = $tid)",
                'sales_return_items' => "DELETE FROM sales_return_items WHERE return_id IN (SELECT id FROM sales_returns WHERE tenant_id = $tid)",
                'purchase_invoice_items' => "DELETE FROM purchase_invoice_items WHERE invoice_id IN (SELECT id FROM purchase_invoices WHERE tenant_id = $tid)",
                'purchase_order_items' => "DELETE FROM purchase_order_items WHERE order_id IN (SELECT id FROM purchase_orders WHERE tenant_id = $tid)",
                'stock_transfer_items' => "DELETE FROM stock_transfer_items WHERE transfer_id IN (SELECT id FROM stock_transfers WHERE tenant_id = $tid)",
                'stock_count_items' => "DELETE FROM stock_count_items WHERE count_id IN (SELECT id FROM stock_counts WHERE tenant_id = $tid)",
                'support_ticket_replies' => "DELETE FROM support_ticket_replies WHERE ticket_id IN (SELECT id FROM support_tickets WHERE tenant_id = $tid)",
                'customer_segment_members' => "DELETE FROM customer_segment_members WHERE segment_id IN (SELECT id FROM customer_segments WHERE tenant_id = $tid)",
                'user_branches' => "DELETE FROM user_branches WHERE user_id IN (SELECT id FROM users WHERE tenant_id = $tid)",
                
                // جداول رئيسية
                'e_invoice_logs' => "DELETE FROM e_invoice_logs WHERE tenant_id = $tid",
                'prescription_usage' => "DELETE FROM prescription_usage WHERE tenant_id = $tid",
                'prescriptions' => "DELETE FROM prescriptions WHERE tenant_id = $tid",
                'loyalty_transactions' => "DELETE FROM loyalty_transactions WHERE tenant_id = $tid",
                'loyalty_settings' => "DELETE FROM loyalty_settings WHERE tenant_id = $tid",
                'notifications' => "DELETE FROM notifications WHERE tenant_id = $tid",
                'activity_log' => "DELETE FROM activity_log WHERE tenant_id = $tid",
                'journal_entries' => "DELETE FROM journal_entries WHERE tenant_id = $tid",
                'accounts' => "DELETE FROM accounts WHERE tenant_id = $tid",
                'sales_returns' => "DELETE FROM sales_returns WHERE tenant_id = $tid",
                'sales_invoices' => "DELETE FROM sales_invoices WHERE tenant_id = $tid",
                'purchase_returns' => "DELETE FROM purchase_returns WHERE tenant_id = $tid",
                'purchase_invoices' => "DELETE FROM purchase_invoices WHERE tenant_id = $tid",
                'purchase_orders' => "DELETE FROM purchase_orders WHERE tenant_id = $tid",
                'receipt_vouchers' => "DELETE FROM receipt_vouchers WHERE tenant_id = $tid",
                'payment_vouchers' => "DELETE FROM payment_vouchers WHERE tenant_id = $tid",
                'expenses' => "DELETE FROM expenses WHERE tenant_id = $tid",
                'inventory_movements' => "DELETE FROM inventory_movements WHERE tenant_id = $tid",
                'inventory_batches' => "DELETE FROM inventory_batches WHERE tenant_id = $tid",
                'stock_transfers' => "DELETE FROM stock_transfers WHERE tenant_id = $tid",
                'stock_counts' => "DELETE FROM stock_counts WHERE tenant_id = $tid",
                'product_alternatives' => "DELETE FROM product_alternatives WHERE product_id IN (SELECT id FROM products WHERE tenant_id = $tid)",
                'products' => "DELETE FROM products WHERE tenant_id = $tid",
                'categories' => "DELETE FROM categories WHERE tenant_id = $tid",
                'customers' => "DELETE FROM customers WHERE tenant_id = $tid",
                'suppliers' => "DELETE FROM suppliers WHERE tenant_id = $tid",
                'support_tickets' => "DELETE FROM support_tickets WHERE tenant_id = $tid",
                'customer_segments' => "DELETE FROM customer_segments WHERE tenant_id = $tid",
                'supplier_ratings' => "DELETE FROM supplier_ratings WHERE tenant_id = $tid",
                'price_history' => "DELETE FROM price_history WHERE tenant_id = $tid",
                'campaigns' => "DELETE FROM campaigns WHERE tenant_id = $tid",
                'financial_periods' => "DELETE FROM financial_periods WHERE tenant_id = $tid",
                'compliance_settings' => "DELETE FROM compliance_settings WHERE tenant_id = $tid",
                'roles' => "DELETE FROM roles WHERE tenant_id = $tid",
                'users' => "DELETE FROM users WHERE tenant_id = $tid",
                'branches' => "DELETE FROM branches WHERE tenant_id = $tid",
                'tenant_settings' => "DELETE FROM tenant_settings WHERE tenant_id = $tid",
                'subscriptions' => "DELETE FROM subscriptions WHERE tenant_id = $tid",
                'tenants' => "DELETE FROM tenants WHERE id = $tid",
            ];
            
            foreach ($tables as $table => $sql) {
                try {
                    $this->pdo->exec($sql);
                } catch (Exception $e) {
                    // بعض الجداول ممكن تكون مش موجودة لسه - نكمل
                    continue;
                }
            }
            
            $this->pdo->commit();
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * جلب بيانات صيدلية
     */
    public function getTenant($tenantId) {
        $stmt = $this->pdo->prepare("SELECT * FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        return $stmt->fetch();
    }
    
    /**
     * التحقق ان الصيدلية نشطة
     */
    public function validateTenantAccess($tenantId) {
        $stmt = $this->pdo->prepare("SELECT status FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $data = $stmt->fetch();
        
        if (!$data) return ['valid' => false, 'reason' => t('g.not_found')];
        if ($data['status'] === 'suspended') return ['valid' => false, 'reason' => t('login.suspended')];
        if ($data['status'] === 'cancelled') return ['valid' => false, 'reason' => t('login.cancelled')];
        if ($data['status'] !== 'active') return ['valid' => false, 'reason' => t('inactive')];
        
        return ['valid' => true];
    }
    
    /**
     * شرط tenant في الاستعلامات
     */
    public static function tenantCondition($alias = '', $tenantId = null) {
        $tid = $tenantId ?? ($_SESSION['tenant_id'] ?? 0);
        $prefix = $alias ? "{$alias}." : '';
        return "{$prefix}tenant_id = {$tid}";
    }
    
    /**
     * احصائيات عامة
     */
    public function getAllTenantsStats() {
        return $this->pdo->query("SELECT 
            COUNT(*) as total_tenants,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_tenants,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_tenants
        FROM tenants")->fetch();
    }
    
    /**
     * قائمة كل الصيدليات
     */
    public function listTenants($page = 1, $perPage = 50) {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->query("SELECT t.*, 
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) as user_count,
                (SELECT COUNT(*) FROM branches b WHERE b.tenant_id = t.id) as branch_count,
                (SELECT COUNT(*) FROM products p WHERE p.tenant_id = t.id) as product_count,
                (SELECT COUNT(*) FROM sales_invoices s WHERE s.tenant_id = t.id) as invoice_count
            FROM tenants t 
            ORDER BY t.created_at DESC 
            LIMIT {$perPage} OFFSET {$offset}");
        return $stmt->fetchAll();
    }
    
    // ========== دوال مساعدة ==========
    
    private function generateSlug($name) {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        if (empty($slug)) $slug = 'pharmacy-' . time();
        $baseSlug = $slug;
        $counter = 1;
        while (true) {
            $exists = $this->pdo->prepare("SELECT COUNT(*) FROM tenants WHERE slug = ?");
            $exists->execute([$slug]);
            if ($exists->fetchColumn() == 0) break;
            $slug = $baseSlug . '-' . $counter++;
        }
        return $slug;
    }
    
    private function createDefaultAccounts($tenantId) {
        $accounts = [
            ['1000',t('accounting.assets'),'asset',null], ['1100',t('accounting.cash'),'asset','1000'],
            ['1110',t('accounting.cashbox'),'asset','1100'], ['1120',t('accounting.bank'),'asset','1100'],
            ['1130',t('accounting.pos_account'),'asset','1100'], ['1140',t('accounting.insurance_receivable'),'asset','1100'],
            ['1200',t('accounting.inventory_acc'),'asset','1000'], ['1300',t('accounting.customers_acc'),'asset','1000'],
            ['2000',t('accounting.liabilities'),'liability',null], ['2100',t('accounting.suppliers_acc'),'liability','2000'],
            ['2200',t('accounting.vat_payable'),'liability','2000'],
            ['2210',t('accounting.input_vat'),'asset','1000'],
            ['3000',t('accounting.equity'),'equity',null], ['3100',t('accounting.capital'),'equity','3000'],
            ['4000',t('accounting.revenue'),'revenue',null], ['4100',t('accounting.sales_revenue'),'revenue','4000'],
            ['4110',t('accounting.sales_returns'),'revenue','4000'], ['4200',t('accounting.other_revenue'),'revenue','4000'],
            ['5000',t('accounting.expenses_type'),'expense',null], ['5100',t('g.cogs'),'expense','5000'],
            ['5150',t('accounting.count_diff'),'expense','5000'], ['5200',t('perm_labels.payroll'),'expense','5000'],
            ['5300',t('expenses.rent'),'expense','5000'], ['5400',t('expenses.utilities'),'expense','5000'],
            ['5500',t('accounting.admin_expenses'),'expense','5000'], ['5600',t('expenses.marketing'),'expense','5000'],
            ['5900',t('accounting.other_expenses'),'expense','5000'],
        ];
        $parentMap = [];
        foreach ($accounts as $acc) {
            $parentId = null;
            if ($acc[3] && isset($parentMap[$acc[3]])) $parentId = $parentMap[$acc[3]];
            $this->pdo->prepare("INSERT INTO accounts (tenant_id, code, name, account_type, parent_id, is_system) VALUES (?,?,?,?,?,1)")
                ->execute([$tenantId, $acc[0], $acc[1], $acc[2], $parentId]);
            $parentMap[$acc[0]] = $this->pdo->lastInsertId();
        }
    }
    
    private function createDefaultCategories($tenantId) {
        $cats = [t('cat.painkillers'),t('cat.antibiotics'),t('cat.respiratory'),t('cat.diabetes'),t('cat.cardiovascular'),
                 t('cat.cholesterol'),t('cat.gastro'),t('cat.allergy'),t('cat.supplements'),
                 t('cat.skincare'),t('cat.haircare'),t('cat.pediatrics'),t('cat.medical_supplies'),
                 t('cat.psychiatry'),t('cat.ophthalmology'),t('cat.ent'),t('other')];
        $sort = 1;
        foreach ($cats as $cat) {
            $this->pdo->prepare("INSERT INTO categories (tenant_id, name, sort_order) VALUES (?,?,?)")
                ->execute([$tenantId, $cat, $sort++]);
        }
    }
}
