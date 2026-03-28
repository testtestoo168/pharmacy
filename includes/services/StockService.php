<?php
/**
 * URS Pharmacy ERP - خدمة المخزون
 * إدارة الدُفعات، الخصم، الإضافة، التنبيهات، والمزامنة
 */

// ========== تنبيهات المخزون ==========
function getLowStockCount($pdo,$branchId=null){try{$bid=$branchId??getBranchId();$s=$pdo->prepare("SELECT COUNT(*) FROM products WHERE tenant_id=? AND branch_id=? AND is_active=1 AND stock_qty<=min_stock");$s->execute([getTenantId(),$bid]);return $s->fetchColumn();}catch(Exception $e){return 0;}}
function getExpiringCount($pdo,$days=90,$branchId=null){try{$bid=$branchId??getBranchId();$s=$pdo->prepare("SELECT COUNT(*) FROM inventory_batches WHERE tenant_id=? AND branch_id=? AND available_qty>0 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL ? DAY)");$s->execute([getTenantId(),$bid,$days]);return $s->fetchColumn();}catch(Exception $e){return 0;}}
function getExpiredCount($pdo,$branchId=null){try{$bid=$branchId??getBranchId();$s=$pdo->prepare("SELECT COUNT(*) FROM inventory_batches WHERE tenant_id=? AND branch_id=? AND available_qty>0 AND expiry_date<CURDATE()");$s->execute([getTenantId(),$bid]);return $s->fetchColumn();}catch(Exception $e){return 0;}}

// ========== خصم مخزون بنظام FEFO ==========
function deductStock($pdo,$productId,$quantity,$branchId=null){$branchId=$branchId??getBranchId();$tid=getTenantId();$bs=$pdo->prepare("SELECT id,available_qty,purchase_price FROM inventory_batches WHERE tenant_id=? AND product_id=? AND branch_id=? AND available_qty>0 AND(expiry_date>CURDATE() OR expiry_date IS NULL) ORDER BY expiry_date ASC");$bs->execute([$tid,$productId,$branchId]);$rem=$quantity;$ded=[];while($rem>0&&($b=$bs->fetch())){$d=min($rem,$b['available_qty']);$pdo->prepare("UPDATE inventory_batches SET available_qty=available_qty-? WHERE id=? AND tenant_id=?")->execute([$d,$b['id'],$tid]);$ded[]=['batch_id'=>$b['id'],'qty'=>$d,'cost'=>$b['purchase_price']];$rem-=$d;}$pdo->prepare("UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND branch_id=?")->execute([$quantity,$productId,$tid,$branchId]);return $ded;}

// ========== إضافة مخزون ==========
function addStock($pdo,$productId,$quantity,$batchId=null,$branchId=null){$tid=getTenantId();$branchId=$branchId??getBranchId();if($batchId)$pdo->prepare("UPDATE inventory_batches SET available_qty=available_qty+? WHERE id=? AND tenant_id=?")->execute([$quantity,$batchId,$tid]);$pdo->prepare("UPDATE products SET stock_qty=stock_qty+? WHERE id=? AND tenant_id=? AND branch_id=?")->execute([$quantity,$productId,$tid,$branchId]);}

// ========== مزامنة المخزون من الدُفعات ==========
function syncProductStockFromBatches($pdo,$pid,$bid=null){$tid=getTenantId();$bid=$bid??getBranchId();$s=$pdo->prepare("SELECT COALESCE(SUM(available_qty),0) FROM inventory_batches WHERE tenant_id=? AND product_id=? AND branch_id=?");$s->execute([$tid,$pid,$bid]);$t=intval($s->fetchColumn());$pdo->prepare("UPDATE products SET stock_qty=? WHERE id=? AND tenant_id=? AND branch_id=?")->execute([$t,$pid,$tid,$bid]);return $t;}

/**
 * مزامنة المخزون — لو منتج عنده stock_qty > 0 بس مفيش batch في الفرع، ينشئ batch تلقائي
 * بيتشغل مرة واحدة لكل session عشان ما يبطئش
 */
function syncStockToBatches($pdo, $tenantId, $branchId) {
    $cacheKey = 'stock_synced_' . $tenantId . '_' . $branchId;
    if (!empty($_SESSION[$cacheKey]) && $_SESSION[$cacheKey] > time() - 3600) return;
    
    try {
        // نجيب المنتجات اللي عندها stock_qty > 0 بس مفيش batches صالحة (غير منتهية الصلاحية)
        $orphans = $pdo->prepare("
            SELECT p.id, p.name, p.stock_qty, p.cost_price, p.unit_price
            FROM products p 
            WHERE p.tenant_id = ? AND p.branch_id = ? AND p.is_active = 1 AND p.stock_qty > 0
            AND NOT EXISTS (
                SELECT 1 FROM inventory_batches ib 
                WHERE ib.product_id = p.id AND ib.tenant_id = ? AND ib.branch_id = ? 
                AND ib.available_qty > 0 
                AND (ib.expiry_date > CURDATE() OR ib.expiry_date IS NULL)
            )
        ");
        $orphans->execute([$tenantId, $branchId, $tenantId, $branchId]);
        $products = $orphans->fetchAll();
        
        if (!empty($products)) {
            $notes = 'رصيد افتتاحي'; // Opening balance
            $insert = $pdo->prepare("
                INSERT INTO inventory_batches 
                (tenant_id, product_id, branch_id, batch_number, quantity, available_qty, purchase_price, selling_price, received_date, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)
            ");
            foreach ($products as $p) {
                $batchNum = 'INIT-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT) . '-' . date('ymd');
                $insert->execute([
                    $tenantId, $p['id'], $branchId, $batchNum,
                    $p['stock_qty'], $p['stock_qty'],
                    $p['cost_price'] ?? 0, $p['unit_price'] ?? 0,
                    $notes
                ]);
            }
        }
    } catch (Exception $e) {
        error_log("Stock sync error: " . $e->getMessage());
    }
    
    $_SESSION[$cacheKey] = time();
}
