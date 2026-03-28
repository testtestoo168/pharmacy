<?php
require_once 'includes/config.php';
$pageTitle = t('pos.title');
require_once 'includes/config.php';
requireLogin();
if (!hasPermission('pos_access')) { header('Location: index.php'); exit; }
$company = getCompanySettings($pdo);
$vatRate = floatval($company['vat_rate'] ?? 15);
$branchId = getBranchId();
$tid = getTenantId();

// مزامنة المخزون — لو فيه منتجات مضافة بدون batches
unset($_SESSION['stock_synced_' . $tid . '_' . $branchId]); // إجبار المزامنة
syncStockToBatches($pdo, $tid, $branchId);

// === API: بحث المنتجات ===
if (isset($_GET['api']) && $_GET['api'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = $_GET['q'] ?? ''; if (strlen($q) < 1) { echo '[]'; exit; }
    $like = "%$q%";
    $stmt = $pdo->prepare("SELECT p.id, p.barcode, p.name, p.name_en, p.generic_name, p.unit_price, p.cost_price, p.stock_qty, p.vat_rate, p.requires_prescription, p.unit, c.name as category FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.tenant_id = ? AND p.branch_id = ? AND p.is_active = 1 AND (p.barcode = ? OR p.name LIKE ? OR p.name_en LIKE ? OR p.generic_name LIKE ? OR p.barcode LIKE ?) ORDER BY CASE WHEN p.barcode = ? THEN 0 ELSE 1 END, p.name LIMIT 15");
    $stmt->execute([$tid, $branchId, $q, $like, $like, $like, $like, $q]);
    $products = $stmt->fetchAll();
    foreach ($products as &$p) {
        $avail = $pdo->prepare("SELECT COALESCE(SUM(available_qty),0) FROM inventory_batches WHERE tenant_id = ? AND product_id = ? AND branch_id = ? AND available_qty > 0 AND (expiry_date > CURDATE() OR expiry_date IS NULL)");
        $avail->execute([$tid, $p['id'], $branchId]); $p['available_qty'] = intval($avail->fetchColumn());
        $p['name'] = displayName($p, 'name', 'name_en');
    }
    echo json_encode($products, JSON_UNESCAPED_UNICODE); exit;
}
// === API: بحث عملاء ===
if (($_GET['api'] ?? '') === 'search_customers') {
    header('Content-Type: application/json; charset=utf-8');
    $q = '%'.($_GET['q'] ?? '').'%';
    $stmt = $pdo->prepare("SELECT id, name, phone, loyalty_points FROM customers WHERE tenant_id = ? AND (name LIKE ? OR phone LIKE ?) ORDER BY name LIMIT 10");
    $stmt->execute([$tid, $q, $q]); echo json_encode($stmt->fetchAll()); exit;
}
// === API: تعليق ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hold_sale') {
    header('Content-Type: application/json; charset=utf-8');
    try { verifyCsrfAjax(); $holdData = json_encode(['items'=>json_decode($_POST['items'],true),'customer_name'=>$_POST['customer_name']??'','customer_id'=>$_POST['customer_id']??'','discount'=>$_POST['discount']??0]);
    $pdo->prepare("INSERT INTO sales_invoices (tenant_id,invoice_number,branch_id,invoice_date,status,notes,created_by,grand_total) VALUES (?,?,?,CURDATE(),'held',?,?,0)")->execute([$tid,'HOLD-'.time(),$branchId,$holdData,$_SESSION['user_id']]);
    echo json_encode(['success'=>true]); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); } exit;
}
// === API: معلقة ===
if (($_GET['api'] ?? '') === 'held_invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $held = $pdo->prepare("SELECT id,notes,created_at FROM sales_invoices WHERE tenant_id=? AND status='held' AND branch_id=? ORDER BY id DESC"); $held->execute([$tid,$branchId]);
    $r=[]; foreach($held->fetchAll() as $h){$d=json_decode($h['notes'],true);$r[]=['id'=>$h['id'],'customer_name'=>$d['customer_name']??t('pos.customer_default'),'items_count'=>count($d['items']??[]),'data'=>$d,'created_at'=>$h['created_at']];} echo json_encode($r); exit;
}
// === API: استرجاع معلقة ===
if (($_GET['api'] ?? '') === 'resume_held') {
    header('Content-Type: application/json; charset=utf-8');
    $hid=intval($_GET['id']??0); $h=$pdo->prepare("SELECT notes FROM sales_invoices WHERE id=? AND tenant_id=? AND branch_id=? AND status='held'"); $h->execute([$hid,$tid,$branchId]); $d=$h->fetchColumn();
    if($d){$pdo->prepare("DELETE FROM sales_invoices WHERE id=? AND tenant_id=? AND branch_id=? AND status='held'")->execute([$hid,$tid,$branchId]);echo $d;}else echo json_encode(null); exit;
}
// === API: بنود فاتورة للمرتجع ===
if (($_GET['api'] ?? '') === 'get_invoice_items') {
    header('Content-Type: application/json; charset=utf-8');
    $n=$_GET['invoice_number']??''; $inv=$pdo->prepare("SELECT id,invoice_number,grand_total,customer_id FROM sales_invoices WHERE tenant_id=? AND branch_id=? AND invoice_number=? AND status IN('completed','partial_return')"); $inv->execute([$tid,$branchId,$n]); $d=$inv->fetch();
    if(!$d){echo json_encode(null);exit;} $items=$pdo->prepare("SELECT id,product_name,quantity,returned_qty,unit_price FROM sales_invoice_items WHERE invoice_id=?"); $items->execute([$d['id']]); $d['items']=$items->fetchAll(); echo json_encode($d); exit;
}
// === API: حفظ البيع ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_sale') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        verifyCsrfAjax();
        $items = json_decode($_POST['items'], true); if (empty($items)) throw new Exception(t('no_results'));
        $pdo->beginTransaction();
        $invoiceNumber = generateNumber($pdo, 'sales_invoices', 'invoice_number', 'INV-');
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $paymentType = $_POST['payment_type'] ?? 'cash';
        $discount = floatval($_POST['discount'] ?? 0);
        $paidAmount = floatval($_POST['paid_amount'] ?? 0);
        $subtotal = 0; $totalVat = 0; $totalCost = 0; $itemsData = [];
        foreach ($items as $item) {
            $product = $pdo->prepare("SELECT * FROM products WHERE id = ? AND tenant_id = ? AND branch_id = $branchId"); $product->execute([$item['id'], $tid]); $product = $product->fetch();
            if (!$product) throw new Exception(t('pos.product_not_found'));
            $availQty = $pdo->prepare("SELECT COALESCE(SUM(available_qty),0) FROM inventory_batches WHERE tenant_id=? AND product_id=? AND branch_id=? AND available_qty>0 AND (expiry_date>CURDATE() OR expiry_date IS NULL)");
            $availQty->execute([$tid, $item['id'], $branchId]); $available = intval($availQty->fetchColumn());
            if ($available < $item['qty']) throw new Exception(t('pos.qty_unavailable') . " {$product['name']} ($available)");
            $lineTotal = $item['price'] * $item['qty']; $lineDiscount = floatval($item['discount'] ?? 0); $net = $lineTotal - $lineDiscount;
            $vat = round($net * $vatRate / (100 + $vatRate), 2); $subtotal += $net; $totalVat += $vat;
            // FEFO
            $batches = $pdo->prepare("SELECT id,available_qty,purchase_price FROM inventory_batches WHERE tenant_id=? AND product_id=? AND branch_id=? AND available_qty>0 AND (expiry_date>CURDATE() OR expiry_date IS NULL) ORDER BY expiry_date ASC");
            $batches->execute([$tid, $item['id'], $branchId]); $remaining = $item['qty']; $deducted = [];
            while ($remaining > 0 && ($batch = $batches->fetch())) { $deduct = min($remaining, $batch['available_qty']); $pdo->prepare("UPDATE inventory_batches SET available_qty=available_qty-? WHERE id=? AND tenant_id=?")->execute([$deduct, $batch['id'], $tid]); $deducted[] = ['batch_id'=>$batch['id'],'qty'=>$deduct,'cost'=>$batch['purchase_price']]; $remaining -= $deduct; }
            $pdo->prepare("UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND branch_id=?")->execute([$item['qty'], $item['id'], $tid, $branchId]);
            $batchId = $deducted[0]['batch_id'] ?? null;
            $costPrice = !empty($deducted) ? array_sum(array_map(fn($d) => $d['cost'] * $d['qty'], $deducted)) / $item['qty'] : $product['cost_price'];
            $totalCost += $costPrice * $item['qty'];
            $itemsData[] = [$product['id'], $batchId, $product['name'], $product['barcode']??'', $item['qty'], $item['price'], round($costPrice,2), $lineDiscount, $net, $vat, $net];
            foreach ($deducted as $d) { $pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id,batch_id,branch_id,movement_type,quantity,reference_type,notes,created_by) VALUES(?,?,?,?,'sale',?,?,?,?)")->execute([$tid,$product['id'],$d['batch_id'],$branchId,$d['qty'],'sale_invoice',t('pos.sale_pos'),$_SESSION['user_id']]); }
        }
        $netTotal = $subtotal - $discount; $grandTotal = $netTotal;
        if ($paymentType !== 'credit') $paidAmount = $grandTotal;
        $remaining = max(0, $grandTotal - $paidAmount);
        $pdo->prepare("INSERT INTO sales_invoices (tenant_id,invoice_number,customer_id,branch_id,invoice_date,hijri_date,subtotal,discount,net_total,vat_amount,grand_total,payment_type,paid_amount,remaining_amount,prescription_number,doctor_name,notes,status,created_by) VALUES(?,?,?,?,CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?,'completed',?)")
            ->execute([$tid,$invoiceNumber,$customerId,$branchId,gregorianToHijri(date('Y-m-d')),$subtotal,$discount,$netTotal,$totalVat,$grandTotal,$paymentType,$paidAmount,$remaining,$_POST['prescription']??'',$_POST['doctor']??'',$_POST['notes']??'',$_SESSION['user_id']]);
        $invoiceId = $pdo->lastInsertId();
        foreach ($itemsData as $d) { $pdo->prepare("INSERT INTO sales_invoice_items (invoice_id,product_id,batch_id,product_name,barcode,quantity,unit_price,cost_price,discount_amount,net_amount,vat_amount,total_amount) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$invoiceId,...$d]); }
        if ($customerId) { $points=floor($grandTotal); $pdo->prepare("UPDATE customers SET total_purchases=total_purchases+?,loyalty_points=loyalty_points+? WHERE id=? AND tenant_id = $tid")->execute([$grandTotal,$points,$customerId]);
            try{$pdo->prepare("INSERT INTO loyalty_transactions (customer_id,type,points,reference_type,reference_id,description,created_by) VALUES(?,'earn',?,'sale_invoice',?,?,?)")->execute([$customerId,$points,$invoiceId,"$invoiceNumber",$_SESSION['user_id']]);}catch(Exception $e){} }
        // القيد المحاسبي الموحد
        createSaleJournalEntry($pdo, [
            'id'=>$invoiceId, 'invoice_number'=>$invoiceNumber, 'invoice_date'=>date('Y-m-d'),
            'grand_total'=>$grandTotal, 'vat_amount'=>$totalVat, 'total_cost'=>$totalCost, 'payment_type'=>$paymentType
        ]);
        // E-invoice (Phase 1: metadata + QR)
        $meta = getInvoiceMetadata(['invoice_number'=>$invoiceNumber,'invoice_date'=>date('Y-m-d'),'grand_total'=>$grandTotal,'vat_amount'=>$totalVat], $company);
        $pdo->prepare("UPDATE sales_invoices SET uuid=?,invoice_hash=?,qr_code=?,total_cost=? WHERE id=? AND tenant_id = $tid")->execute([$meta['uuid'],$meta['hash'],$meta['qr'],$totalCost,$invoiceId]);
        $pdo->commit(); logActivity($pdo, 'pos.sale_pos',"$invoiceNumber — $grandTotal",'pos');

        // ===== ZATCA Phase 2: إرسال تلقائي لهيئة الزكاة =====
        $zatcaResult = null;
        try {
            $zatca = new ZATCAIntegrationService($pdo, $tid, $branchId);
            if ($zatca->isEnabled()) {
                $zatcaResult = $zatca->submitSalesInvoice($invoiceId);
            }
        } catch (Exception $ze) {
            // لا نوقف البيع بسبب خطأ ZATCA — يتسجل ويتعاد لاحقاً
            $zatcaResult = ['success' => false, 'error' => $ze->getMessage()];
        }

        $response = ['success'=>true,'invoice_id'=>$invoiceId,'invoice_number'=>$invoiceNumber,'total'=>$grandTotal];
        if ($zatcaResult) {
            $response['zatca_status'] = $zatcaResult['status'] ?? ($zatcaResult['success'] ? 'reported' : 'error');
            $response['zatca_error'] = $zatcaResult['error'] ?? null;
        }
        echo json_encode($response);
    } catch (Exception $e) { if($pdo->inTransaction())$pdo->rollBack(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); } exit;
}
// === API: مرتجع سريع ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_return') {
    header('Content-Type: application/json; charset=utf-8');
    try { verifyCsrfAjax(); $pdo->beginTransaction(); $invoiceId=intval($_POST['invoice_id']); $returnItems=json_decode($_POST['return_items'],true);
    $inv=$pdo->prepare("SELECT * FROM sales_invoices WHERE tenant_id = $tid AND branch_id = $branchId AND id=?"); $inv->execute([$invoiceId]); $invData=$inv->fetch(); if(!$invData) throw new Exception(t('pos.invoice_not_found'));
    $returnNumber=generateNumber($pdo,'sales_returns','return_number','SR-'); $totalReturn=0;
    $pdo->prepare("INSERT INTO sales_returns (tenant_id,return_number,invoice_id,branch_id,return_date,refund_method,reason,created_by) VALUES(?,?,?,?,CURDATE(),'cash',?,?)")->execute([$tid,$returnNumber,$invoiceId,$branchId,$_POST['reason']??'',$_SESSION['user_id']]);
    $returnId=$pdo->lastInsertId();
    foreach($returnItems as $ri){$rq=intval($ri['qty']);if($rq<=0)continue;$orig=$pdo->prepare("SELECT * FROM sales_invoice_items WHERE id=?");$orig->execute([intval($ri['item_id'])]);$orig=$orig->fetch();if(!$orig) throw new Exception(t('pos.item_not_in_invoice'));
    $maxReturn = $orig['quantity'] - intval($orig['returned_qty'] ?? 0);
    if($rq > $maxReturn) throw new Exception(t('pos.return_qty_exceeds') . ": $rq > $maxReturn — " . ($orig['product_name']??''));
    $rt=$rq*$orig['unit_price'];$totalReturn+=$rt;$pdo->prepare("INSERT INTO sales_return_items (return_id,invoice_item_id,product_id,quantity,unit_price,total) VALUES(?,?,?,?,?,?)")->execute([$returnId,$ri['item_id'],$orig['product_id'],$rq,$orig['unit_price'],$rt]);
    $pdo->prepare("UPDATE sales_invoice_items SET returned_qty=returned_qty+? WHERE id=?")->execute([$rq,$ri['item_id']]);
    if($orig['product_id']){addStock($pdo,$orig['product_id'],$rq,$orig['batch_id']);$pdo->prepare("INSERT INTO inventory_movements (tenant_id,product_id,batch_id,branch_id,movement_type,quantity,reference_type,reference_id,notes,created_by) VALUES(?,?,?,?,'return_sale',?,'sales_return',?,?,?)")->execute([$tid,$orig['product_id'],$orig['batch_id'],$branchId,$rq,$returnId,"$returnNumber",$_SESSION['user_id']]);}}
    $pdo->prepare("UPDATE sales_returns SET total_amount=? WHERE id=? AND tenant_id = $tid")->execute([$totalReturn,$returnId]);
    $pdo->prepare("UPDATE sales_invoices SET status='partial_return' WHERE id=? AND tenant_id = $tid")->execute([$invoiceId]);
    if($invData['customer_id']){$pdo->prepare("UPDATE customers SET loyalty_points=GREATEST(0,loyalty_points-?) WHERE id=? AND tenant_id = $tid")->execute([floor($totalReturn),$invData['customer_id']]);}
    // المحاسبة
    $retVat=round($totalReturn*floatval($company['vat_rate']??15)/(100+floatval($company['vat_rate']??15)),2);
    $retCost=0; foreach($returnItems as $ri2){$rq2=intval($ri2['qty']);if($rq2<=0)continue;$oi2=$pdo->prepare("SELECT cost_price FROM sales_invoice_items WHERE id=?");$oi2->execute([intval($ri2['item_id'])]);$oc=$oi2->fetch();if($oc)$retCost+=$rq2*floatval($oc['cost_price']);}
    $pdo->prepare("UPDATE sales_returns SET vat_amount=?,total_cost=? WHERE id=? AND tenant_id = $tid")->execute([$retVat,$retCost,$returnId]);
    $refMethod=$_POST['refund_method']??'cash';
    createSalesReturnJournalEntry($pdo, ['id'=>$returnId,'return_number'=>$returnNumber,'return_date'=>date('Y-m-d'),'total_amount'=>$totalReturn,'vat_amount'=>$retVat,'total_cost'=>$retCost,'refund_method'=>$refMethod]);
    $pdo->commit(); logActivity($pdo, 'pos.return_pos',"$returnNumber — $totalReturn",'pos');
    echo json_encode(['success'=>true,'return_number'=>$returnNumber,'total'=>$totalReturn]);
    }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}exit;
}
syncStockToBatches($pdo, $tid, $branchId);
$quickProducts = $pdo->query("SELECT p.*, c.name as cat_name, COALESCE((SELECT SUM(ib.available_qty) FROM inventory_batches ib WHERE ib.tenant_id = $tid AND ib.product_id = p.id AND ib.branch_id = $branchId AND ib.available_qty > 0 AND (ib.expiry_date > CURDATE() OR ib.expiry_date IS NULL)),0) as available_qty FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.tenant_id = $tid AND p.branch_id = $branchId AND p.is_active = 1 ORDER BY p.name LIMIT 60")->fetchAll();
?>
<!DOCTYPE html><html lang="<?= langCode() ?>" dir="<?= langDir() ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>POS - <?=$company['company_name']??'URS'?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Tajawal',sans-serif;direction:inherit;background:#f1f5f9;height:100vh;overflow:hidden}
.pos-layout{display:flex;height:100vh}.pos-main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.pos-header{background:linear-gradient(135deg,#0f3460,#1a2744);color:#fff;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.pos-header h1{font-size:16px;font-weight:700;display:flex;align-items:center;gap:6px}
.ha{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.ha a,.ha button{color:#fff;background:rgba(255,255,255,.15);border:none;padding:5px 10px;border-radius:6px;cursor:pointer;font-family:'Tajawal';font-size:12px;text-decoration:none;display:flex;align-items:center;gap:4px}.ha a:hover,.ha button:hover{background:rgba(255,255,255,.25)}
.pos-content{flex:1;display:flex;overflow:hidden}.pos-prods{flex:1;display:flex;flex-direction:column;padding:10px;overflow:hidden}
.sb{position:relative;margin-bottom:8px}.sb input{width:100%;padding:11px 42px 11px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:15px;font-family:'Tajawal';outline:none}.sb input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}.sb .ic{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:17px}
.sres{background:#fff;border-radius:10px;box-shadow:0 8px 25px rgba(0,0,0,.12);max-height:320px;overflow-y:auto;display:none;position:absolute;width:100%;z-index:100;border:1px solid #e2e8f0}.sres.show{display:block}
.si{padding:9px 13px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f1f5f9;cursor:pointer}.si:hover{background:#eff6ff}
.grd{flex:1;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:6px;padding-bottom:6px}
.pc{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px;cursor:pointer;text-align:center;transition:all .15s}.pc:hover{border-color:#3b82f6;transform:translateY(-1px);box-shadow:0 3px 8px rgba(59,130,246,.12)}
.pc .pn{font-size:11px;font-weight:600;height:28px;overflow:hidden;line-height:1.3;margin-bottom:2px}.pc .pp{font-size:14px;font-weight:800;color:#1d4ed8}.pc .ps{font-size:10px;display:inline-block;padding:1px 5px;border-radius:8px}
.sok{background:#dcfce7;color:#166534}.slo{background:#fef3c7;color:#92400e}.sou{background:#fee2e2;color:#991b1b}
.crt{width:370px;background:#fff;border-right:1px solid #e2e8f0;display:flex;flex-direction:column}
.crt-h{padding:10px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}.crt-h h3{font-size:14px;font-weight:700;display:flex;align-items:center;gap:5px}
.ccst{padding:6px 10px;border-bottom:1px solid #f1f5f9;display:flex;gap:6px;align-items:center}.ccst input{flex:1;border:1px solid #e2e8f0;border-radius:6px;padding:5px 8px;font-family:'Tajawal';font-size:12px;outline:none}
.ciw{flex:1;overflow-y:auto;padding:5px}.ci{background:#f8fafc;border-radius:7px;padding:7px 9px;margin-bottom:3px}
.ci-t{display:flex;justify-content:space-between;align-items:start}.ci-n{font-weight:600;font-size:11px;flex:1;line-height:1.3}
.ci-r{color:#ef4444;cursor:pointer;padding:1px 4px;border:none;background:none;font-size:12px}.ci-r:hover{background:#fee2e2;border-radius:4px}
.ci-c{display:flex;align-items:center;justify-content:space-between;margin-top:3px}.qc{display:flex;align-items:center;gap:3px}
.qb{width:24px;height:24px;border-radius:5px;border:1px solid #d1d5db;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}.qb:hover{background:#eff6ff;border-color:#3b82f6}
.qd{font-weight:700;font-size:13px;min-width:22px;text-align:center}.ci-tt{font-weight:700;color:#1d4ed8;font-size:12px}
.ce{text-align:center;padding:40px 16px;color:#94a3b8}.ce i{font-size:36px;margin-bottom:8px;opacity:.4}
.cs{padding:10px;border-top:1px solid #e2e8f0;background:#f8fafc}.sr2{display:flex;justify-content:space-between;padding:2px 0;font-size:12px}
.stt{font-size:19px;font-weight:800;color:#0f3460;border-top:2px solid #e2e8f0;padding-top:6px;margin-top:3px}
.pm{display:flex;gap:3px;margin:6px 0}.pb{flex:1;padding:6px 3px;border:2px solid #e2e8f0;border-radius:7px;background:#fff;cursor:pointer;font-family:'Tajawal';font-size:11px;font-weight:600;text-align:center}.pb.act{border-color:#3b82f6;background:#eff6ff;color:#1d4ed8}.pb:hover{border-color:#93c5fd}
.cobtn{width:100%;padding:13px;background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;border-radius:9px;font-family:'Tajawal';font-size:16px;font-weight:700;cursor:pointer;margin-top:6px}.cobtn:hover{background:linear-gradient(135deg,#047857,#059669)}.cobtn:disabled{opacity:.5;cursor:not-allowed}
.mo{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:none;align-items:center;justify-content:center}.mo.show{display:flex}
.mbox{background:#fff;border-radius:14px;padding:28px;max-width:420px;width:90%;text-align:center;max-height:90vh;overflow-y:auto}

/* POS Mobile Responsive */
@media (max-width:900px) {
    .pos-content{flex-direction:column !important}
    .crt{width:100% !important;border-right:none !important;border-top:2px solid #e2e8f0;max-height:45vh}
    .pos-prods{max-height:55vh}
    .grd{grid-template-columns:repeat(3,1fr) !important;gap:4px !important}
    .pc{padding:6px !important}
    .pc .pn{font-size:10px !important;height:22px !important}
    .pc .pp{font-size:12px !important}
    .pos-header{padding:8px 12px !important;flex-wrap:wrap}
    .pos-header h1{font-size:13px !important}
    .ha a,.ha button{font-size:10px !important;padding:4px 6px !important}
    .sb{display:flex;gap:6px;align-items:center}
    .sb input{flex:1;font-size:14px !important;padding:9px 12px 9px 36px !important}
    .barcode-scan-btn{flex-shrink:0;padding:9px 12px !important;font-size:14px !important}
    .cs{padding:8px !important}
    .stt{font-size:16px !important}
    .cobtn{padding:10px !important;font-size:14px !important}
    .ci-n{font-size:10px !important}
    .qb{width:26px !important;height:26px !important}
    .ccst input{font-size:11px !important;padding:4px 6px !important}
}
@media (max-width:480px) {
    .grd{grid-template-columns:repeat(2,1fr) !important}
    .pc .pn{font-size:9px !important}
    .pc .pp{font-size:11px !important}
    .crt{max-height:50vh}
    .pos-prods{max-height:50vh}
    .pos-header h1{font-size:12px !important}
    .ha{gap:3px !important}
    .mbox{padding:20px !important}
    /* iOS prevent zoom on input focus */
    input,select,textarea{font-size:16px !important}
}
.sic{width:64px;height:64px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:28px;color:#16a34a}
/* POS LTR Support */
html[dir="ltr"] .crt{border-right:none;border-left:1px solid #e2e8f0}
html[dir="ltr"] .sb .ic{right:auto;left:13px}
html[dir="ltr"] .sb input{padding:11px 12px 11px 42px}
html[dir="ltr"] body{font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,'Tajawal',sans-serif}
.db-data{unicode-bidi:plaintext}
</style></head><body>
<div class="pos-layout"><div class="pos-main">
<div class="pos-header"><h1><i class="fas fa-cash-register"></i> <?=$company['company_name']??'URS Pharmacy'?> — <?= t('pos.title') ?></h1>
<div class="ha"><span style="opacity:.7;font-size:11px;"><i class="fas fa-user"></i> <?=$_SESSION['full_name']??''?> | <?=date('Y-m-d')?></span>
<button onclick="showHeld()"><i class="fas fa-pause-circle"></i> <?= t('pos.held') ?> <span id="hCnt" style="display:none;background:#f59e0b;padding:0 4px;border-radius:8px;font-size:11px;"></span></button>
<?php if(hasPermission('pos_return')):?><button onclick="showRet()"><i class="fas fa-undo"></i><?= t('sales.returns') ?></button><?php endif;?>
<a href="index.php"><i class="fas fa-arrow-left"></i> <?= t('pos.system') ?></a></div></div>
<div class="pos-content">
<div class="pos-prods"><div class="sb"><i class="fas fa-barcode ic"></i><input type="text" id="sIn" placeholder="<?= t('pos.scan_or_search') ?>" autocomplete="off"><button type="button" class="barcode-scan-btn" onclick="openBarcodeScanner()" title="<?= t('pos.scan_camera') ?>"><i class="fas fa-camera"></i></button><div class="sres" id="sRes"></div></div>
<div class="grd">
<?php foreach($quickProducts as $p): $avQty = intval($p['available_qty']); $pName = displayName($p, 'name', 'name_en'); ?><div class="pc" onclick="atc(<?=htmlspecialchars(json_encode(['id'=>$p['id'],'name'=>$pName,'barcode'=>$p['barcode'],'price'=>floatval($p['unit_price']),'stock'=>$avQty,'cost'=>floatval($p['cost_price']),'available_qty'=>$avQty]),ENT_QUOTES)?>)"><div class="pn" dir="auto"><?=htmlspecialchars($pName)?></div><div class="pp"><?=number_format($p['unit_price'],2)?></div><div class="ps <?=$avQty <= 0 ? 'sou' : ($avQty<=($p['min_stock']??10)?'slo':'sok')?>"><?=$avQty?></div></div><?php endforeach;?>
</div></div>
<div class="crt"><div class="crt-h"><h3><i class="fas fa-shopping-cart"></i> <?= t('pos.invoice') ?> <span id="cCn" style="background:#3b82f6;color:#fff;padding:0 5px;border-radius:8px;font-size:11px;display:none;"></span></h3>
<div style="display:flex;gap:3px;"><?php if(hasPermission('pos_hold')):?><button onclick="holdS()" style="background:#fef3c7;color:#92400e;border:none;padding:3px 8px;border-radius:5px;cursor:pointer;font-family:'Tajawal';font-size:10px;"><i class="fas fa-pause"></i></button><?php endif;?>
<button onclick="clrC()" style="background:#fee2e2;color:#dc2626;border:none;padding:3px 8px;border-radius:5px;cursor:pointer;font-family:'Tajawal';font-size:10px;"><i class="fas fa-trash"></i></button></div></div>
<div class="ccst"><i class="fas fa-user" style="color:#94a3b8;font-size:12px;"></i><input type="text" id="cSrch" placeholder="<?= t('pos.search_customer') ?>" autocomplete="off" onkeyup="sCust(this.value)"><input type="hidden" id="cId" value=""><span id="cDsp" style="font-size:11px;color:#16a34a;display:none;"></span><button onclick="clrCust()" style="border:none;background:none;color:#94a3b8;cursor:pointer;font-size:11px;"><i class="fas fa-times"></i></button></div>
<div id="cRes" style="display:none;position:absolute;background:#fff;border:1px solid #e2e8f0;border-radius:6px;box-shadow:0 4px 10px rgba(0,0,0,.1);z-index:50;width:280px;max-height:180px;overflow-y:auto;"></div>
<div class="ciw" id="cItems"><div class="ce"><i class="fas fa-pills"></i><p><?= t('pos.scan_barcode') ?></p></div></div>
<div class="cs">
<div class="sr2"><span><?= t('subtotal') ?></span><span id="subD">0.00</span></div>
<div class="sr2"><span><?= t('vat') ?> (<?=$vatRate?>%)</span><span id="vatD">0.00</span></div>
<div class="sr2"><span><?= t('discount') ?></span><?php if(hasPermission('pos_discount')):?><input type="number" id="dIn" value="0" min="0" step="0.01" style="width:65px;text-align:center;border:1px solid #d1d5db;border-radius:5px;padding:1px;font-family:'Tajawal';font-size:11px;" onchange="uTot()"><?php else:?><span>0</span><input type="hidden" id="dIn" value="0"><?php endif;?></div>
<div class="sr2 stt"><span><?= t('total') ?></span><span id="totD">0.00 <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span></span></div>
<div class="pm"><button class="pb act" data-m="cash" onclick="sP('cash')"><i class="fas fa-money-bill"></i><?= t('sales.cash') ?></button><button class="pb" data-m="card" onclick="sP('card')"><i class="fas fa-credit-card"></i><?= t('sales.card') ?></button><button class="pb" data-m="transfer" onclick="sP('transfer')"><i class="fas fa-exchange-alt"></i><?= t('inventory.transfer') ?></button><button class="pb" data-m="insurance" onclick="sP('insurance')"><i class="fas fa-shield-alt"></i><?= t('g.insurance') ?></button></div>
<div id="cSec"><input type="number" id="pIn" placeholder="<?= t('pos.amount_paid') ?>" style="width:100%;padding:7px;border:2px solid #e2e8f0;border-radius:7px;font-size:15px;text-align:center;font-family:'Tajawal';font-weight:700;margin-bottom:3px;" oninput="uChg()"><div id="chD" style="text-align:center;font-size:13px;font-weight:700;color:#059669;display:none;padding:3px;background:#dcfce7;border-radius:5px;margin-bottom:5px;"></div></div>
<button class="cobtn" id="coB" disabled onclick="compS()"><i class="fas fa-check-circle"></i><?= t('g.complete_sale') ?></button>
</div></div></div></div></div>

<div class="mo" id="sMo"><div class="mbox"><div class="sic"><i class="fas fa-check"></i></div><h2 style="font-size:20px;color:#166534;margin-bottom:5px;"><?= t('saved_success') ?></h2><p style="color:#6b7280;margin-bottom:14px;"><?= t('invoice') ?>: <strong id="iNum"></strong></p><div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:14px;text-align:right;"><div style="display:flex;justify-content:space-between;margin-bottom:3px;"><span><?= t('total') ?></span><strong id="mT"></strong></div><div style="display:flex;justify-content:space-between;"><span><?= t('remaining') ?></span><strong id="mC" style="color:#059669;"></strong></div></div><div style="display:flex;gap:6px;"><button onclick="nS()" style="flex:1;padding:11px;background:#1d4ed8;color:#fff;border:none;border-radius:8px;font-family:'Tajawal';font-size:14px;font-weight:700;cursor:pointer;"><?= t('dash.new_sale') ?></button><button onclick="prR()" style="padding:11px 16px;background:#f1f5f9;border:1px solid #d1d5db;border-radius:8px;cursor:pointer;font-family:'Tajawal';font-weight:600;"><i class="fas fa-print"></i></button></div></div></div>
<div class="mo" id="hMo"><div class="mbox" style="text-align:right;"><h3 style="margin-bottom:10px;"><i class="fas fa-pause-circle" style="color:#f59e0b;"></i> <?= t('pos.held_invoices') ?></h3><div id="hList"></div><button onclick="document.getElementById('hMo').classList.remove('show')" style="margin-top:10px;padding:8px;background:#f1f5f9;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-family:'Tajawal';width:100%;"><?= t('close') ?></button></div></div>
<div class="mo" id="rMo"><div class="mbox" style="text-align:right;max-width:480px;"><h3 style="margin-bottom:10px;"><i class="fas fa-undo" style="color:#dc2626;"></i><?= t('sales.returns') ?></h3><input type="text" id="rIN" placeholder="<?= t('sales.invoice_number') ?>..." style="width:100%;padding:8px;border:2px solid #e2e8f0;border-radius:6px;font-family:'Tajawal';font-size:13px;margin-bottom:8px;" onkeydown="if(event.key==='Enter')ldRI()"><button onclick="ldRI()" style="padding:6px 14px;background:#3b82f6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:'Tajawal';margin-bottom:10px;"><?= t('search') ?></button><div id="rIt"></div><button onclick="document.getElementById('rMo').classList.remove('show')" style="margin-top:8px;padding:6px;background:#f1f5f9;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-family:'Tajawal';width:100%;"><?= t('close') ?></button></div></div>

<script>
// i18n translations for POS JS
const _t = {
    no_results: '<?= t('no_results') ?>',
    scan_barcode: '<?= t('pos.scan_barcode') ?>',
    not_available: '<?= t('pos.not_available') ?>',
    exceeded: '<?= t('pos.exceeded_available') ?>',
    held_success: '<?= t('pos.held_success') ?>',
    select_qty: '<?= t('pos.select_qty') ?>',
    not_found: '<?= t('pos.not_found') ?>',
    clear_all: '<?= t('pos.clear_all') ?>',
    amount_less: '<?= t('pos.amount_less') ?>',
    error_prefix: '<?= t('error') ?>',
    change_due: '<?= t('pos.change_due') ?>',
    sar: '<?= t('sar') ?>',
    done: '<?= t('pos.done') ?>',
    item_word: '<?= t('pos.items_word') ?>',
    available_label: '<?= t('pos.available_label') ?>',
    point_word: '<?= t('misc.point') ?>',
    no_held: '<?= t('pos.no_held') ?>',
    retrieve: '<?= t('pos.retrieve') ?>',
    customer_credit: '<?= t('pos.customer_credit') ?>',
    exec_return: '<?= t('pos.exec_return') ?>',
    reason_ph: '<?= t('pos.reason_placeholder') ?>',
    qty_label: '<?= t('pos.qty_label') ?>',
    return_label: '<?= t('pos.return_label') ?>',
    customer_default: '<?= t('pos.customer_default') ?>',
    point_camera: '<?= t('pos.point_camera') ?>',
    starting_camera: '<?= t('pos.starting_camera') ?>',
    camera_error: '<?= t('pos.camera_error') ?>',
    scanned: '<?= t('pos.scanned') ?>',
    point_barcode: '<?= t('pos.point_barcode') ?>',
    complete_sale: '<?= t('pos.complete_sale') ?>',
};
</script>

<script>
let cart=[],pM='cash',lIid=null;const vr=<?=$vatRate?>;let sT,cTm;
const sIn=document.getElementById('sIn'),sRes=document.getElementById('sRes');
sIn.addEventListener('input',function(){clearTimeout(sT);const q=this.value.trim();if(q.length<1){sRes.classList.remove('show');return;}sT=setTimeout(()=>{fetch('pos.php?api=search&q='+encodeURIComponent(q)).then(r=>r.json()).then(ps=>{if(!ps.length){sRes.innerHTML='<div style="padding:10px;text-align:center;color:#94a3b8;">'+_t.no_results+'</div>';sRes.classList.add('show');return;}if(ps.length===1&&ps[0].barcode===q){atc(ps[0]);sIn.value='';sRes.classList.remove('show');return;}sRes.innerHTML=ps.map(p=>`<div class="si" onclick='atc(${JSON.stringify(p).replace(/'/g,"&#39;")})'><div><strong style="font-size:13px;" dir="auto">${p.name}</strong>${p.requires_prescription?'<span style="background:#fef3c7;color:#92400e;font-size:9px;padding:0 4px;border-radius:8px;margin-right:3px;">Rx</span>':''}<br><small style="color:#6b7280;">${p.generic_name||''} ${p.barcode?'| '+p.barcode:''}</small></div><div style="text-align:left"><strong style="color:#1d4ed8;font-size:14px;">${parseFloat(p.unit_price).toFixed(2)}</strong><br><span class="ps ${p.available_qty<=0?'sou':p.available_qty<=10?'slo':'sok'}" style="font-size:10px;padding:1px 6px;border-radius:8px;">${_t.available_label} ${p.available_qty}</span></div></div>`).join('');sRes.classList.add('show');});},200);});
sIn.addEventListener('keydown',e=>{if(e.key==='Enter'){const i=sRes.querySelectorAll('.si');if(i.length===1)i[0].click();}});
document.addEventListener('click',e=>{if(!e.target.closest('.sb'))sRes.classList.remove('show');if(!e.target.closest('#cRes')&&!e.target.closest('#cSrch'))document.getElementById('cRes').style.display='none';});

function atc(p){const av=p.available_qty??p.stock_qty??p.stock;if(av<=0){alert(_t.not_available);return;}const ex=cart.find(i=>i.id==p.id);if(ex){if(ex.qty>=av){alert(_t.exceeded);return;}ex.qty++;}else cart.push({id:p.id,name:p.name,barcode:p.barcode,price:parseFloat(p.unit_price||p.price),cost:parseFloat(p.cost_price||p.cost||0),stock:av,qty:1,discount:0});sIn.value='';sRes.classList.remove('show');sIn.focus();rnd();}
function rmI(i){cart.splice(i,1);rnd();}function cQ(i,d){cart[i].qty=Math.max(1,Math.min(cart[i].stock,cart[i].qty+d));rnd();}
function rnd(){const c=document.getElementById('cItems'),cn=document.getElementById('cCn');if(!cart.length){c.innerHTML='<div class="ce"><i class="fas fa-pills"></i><p>'+_t.scan_barcode+'</p></div>';document.getElementById('coB').disabled=true;cn.style.display='none';}else{c.innerHTML=cart.map((it,i)=>`<div class="ci"><div class="ci-t"><div class="ci-n">${it.name}${it.barcode?' <small style="color:#94a3b8;">'+it.barcode+'</small>':''}</div><button class="ci-r" onclick="rmI(${i})"><i class="fas fa-times"></i></button></div><div class="ci-c"><div class="qc"><button class="qb" onclick="cQ(${i},-1)">-</button><span class="qd">${it.qty}</span><button class="qb" onclick="cQ(${i},1)">+</button><span style="font-size:10px;color:#6b7280;">× ${it.price.toFixed(2)}</span></div><div class="ci-tt">${(it.price*it.qty).toFixed(2)}</div></div></div>`).join('');document.getElementById('coB').disabled=false;cn.textContent=cart.length;cn.style.display='inline';}uTot();}
function uTot(){const sub=cart.reduce((s,i)=>s+(i.price*i.qty),0);const disc=parseFloat(document.getElementById('dIn').value)||0;const net=sub-disc;const vat=net*vr/(100+vr);document.getElementById('subD').textContent=(net-vat).toFixed(2);document.getElementById('vatD').textContent=vat.toFixed(2);document.getElementById('totD').innerHTML=net.toFixed(2)+' <span style="font-size:12px;color:#64748b;font-weight:600;"><?= t('sar') ?></span>';uChg();}
function uChg(){const tot=parseFloat(document.getElementById('totD').textContent);const paid=parseFloat(document.getElementById('pIn').value)||0;const el=document.getElementById('chD');if(paid>0&&paid>=tot){el.style.display='block';el.textContent=_t.change_due+' '+(paid-tot).toFixed(2)+' '+_t.sar;}else el.style.display='none';}
function sP(m){pM=m;document.querySelectorAll('.pb').forEach(b=>b.classList.toggle('act',b.dataset.m===m));document.getElementById('cSec').style.display=m==='cash'?'block':'none';}
function clrC(){if(cart.length&&confirm(_t.clear_all)){cart=[];rnd();}}
function sCust(q){clearTimeout(cTm);if(q.length<2){document.getElementById('cRes').style.display='none';return;}cTm=setTimeout(()=>{fetch('pos.php?api=search_customers&q='+encodeURIComponent(q)).then(r=>r.json()).then(cs=>{const d=document.getElementById('cRes');if(!cs.length){d.style.display='none';return;}d.innerHTML=cs.map(c=>`<div style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;font-size:12px;" onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''" onclick="selC(${c.id},'${c.name}',${c.loyalty_points})"><span><strong>${c.name}</strong></span><span style="color:#f59e0b;">${c.loyalty_points} ${_t.point_word}</span></div>`).join('');d.style.display='block';});},300);}
function selC(id,nm,pt){document.getElementById('cId').value=id;document.getElementById('cSrch').value=nm;document.getElementById('cDsp').textContent=pt+' '+_t.point_word;document.getElementById('cDsp').style.display='inline';document.getElementById('cRes').style.display='none';}
function clrCust(){document.getElementById('cId').value='';document.getElementById('cSrch').value='';document.getElementById('cDsp').style.display='none';}
function compS(){if(!cart.length)return;const tot=parseFloat(document.getElementById('totD').textContent);const paid=parseFloat(document.getElementById('pIn').value)||tot;if(pM==='cash'&&paid<tot){alert(_t.amount_less);return;}const btn=document.getElementById('coB');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';const fd=new FormData();fd.append('action','save_sale');fd.append('items',JSON.stringify(cart.map(i=>({id:i.id,qty:i.qty,price:i.price,discount:i.discount}))));fd.append('payment_type',pM);fd.append('discount',document.getElementById('dIn').value);fd.append('paid_amount',paid);fd.append('customer_id',document.getElementById('cId').value);fd.append('prescription','');fd.append('doctor','');fd.append('notes','');
fetch('pos.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){lIid=d.invoice_id;document.getElementById('iNum').textContent=d.invoice_number;document.getElementById('mT').textContent=parseFloat(d.total).toFixed(2)+' '+_t.sar;document.getElementById('mC').textContent=Math.max(0,paid-parseFloat(d.total)).toFixed(2)+' '+_t.sar;document.getElementById('sMo').classList.add('show');}else alert(_t.error_prefix+': '+d.error);}).catch(e=>alert(_t.error_prefix+': '+e.message)).finally(()=>{btn.disabled=false;btn.innerHTML='<i class="fas fa-check-circle"></i> '+_t.complete_sale;});}
function nS(){cart=[];rnd();clrCust();document.getElementById('dIn').value=0;document.getElementById('pIn').value='';document.getElementById('chD').style.display='none';document.getElementById('sMo').classList.remove('show');sIn.focus();}
function prR(){if(lIid)window.open('sales_print.php?id='+lIid,'_blank');}
function holdS(){if(!cart.length)return;const fd=new FormData();fd.append('action','hold_sale');fd.append('items',JSON.stringify(cart));fd.append('customer_name',document.getElementById('cSrch').value||_t.customer_default);fd.append('customer_id',document.getElementById('cId').value);fd.append('discount',document.getElementById('dIn').value);fetch('pos.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){cart=[];rnd();alert(_t.held_success);ldH();}});}
function showHeld(){fetch('pos.php?api=held_invoices').then(r=>r.json()).then(l=>{document.getElementById('hList').innerHTML=l.length?l.map(h=>`<div style="background:#f8fafc;border-radius:6px;padding:8px;margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;"><div><strong style="font-size:12px;">${h.customer_name}</strong><br><small style="color:#94a3b8;font-size:10px;">${h.items_count} ${_t.item_word}</small></div><button onclick="resH(${h.id})" style="background:#3b82f6;color:#fff;border:none;padding:5px 12px;border-radius:5px;cursor:pointer;font-family:'Tajawal';font-size:11px;">${_t.retrieve}</button></div>`).join(''):'<p style="text-align:center;color:#94a3b8;padding:16px;">'+_t.no_held+'</p>';document.getElementById('hMo').classList.add('show');});}
function resH(id){fetch('pos.php?api=resume_held&id='+id).then(r=>r.json()).then(d=>{if(d&&d.items){cart=d.items;if(d.customer_id){document.getElementById('cId').value=d.customer_id;document.getElementById('cSrch').value=d.customer_name;}document.getElementById('dIn').value=d.discount||0;rnd();document.getElementById('hMo').classList.remove('show');ldH();}});}
function ldH(){fetch('pos.php?api=held_invoices').then(r=>r.json()).then(l=>{const e=document.getElementById('hCnt');if(l.length>0){e.textContent=l.length;e.style.display='inline';}else e.style.display='none';});}
function showRet(){document.getElementById('rMo').classList.add('show');document.getElementById('rIN').focus();}
function ldRI(){const n=document.getElementById('rIN').value.trim();if(!n)return;fetch('pos.php?api=get_invoice_items&invoice_number='+encodeURIComponent(n)).then(r=>r.json()).then(d=>{if(!d){document.getElementById('rIt').innerHTML='<p style="color:#dc2626;text-align:center;">'+_t.not_found+'</p>';return;}let h='<div style="margin-bottom:8px;font-size:12px;"><strong>'+d.invoice_number+'</strong> | '+parseFloat(d.grand_total).toFixed(2)+'</div><table style="width:100%;font-size:11px;border-collapse:collapse;"><thead><tr style="background:#f8fafc;"><th style="padding:4px;"><?= t('item') ?></th><th>'+_t.qty_label+'</th><th><?= t('sales.returns') ?></th><th>'+_t.return_label+'</th></tr></thead><tbody>';d.items.forEach(it=>{const mx=it.quantity-it.returned_qty;h+=`<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:4px;">${it.product_name}</td><td>${it.quantity}</td><td>${it.returned_qty}</td><td><input type="number" class="rq" data-iid="${it.id}" value="0" min="0" max="${mx}" style="width:50px;text-align:center;border:1px solid #d1d5db;border-radius:3px;padding:1px;"></td></tr>`;});h+=`</tbody></table><textarea id="rRsn" placeholder="${_t.reason_ph}" style="width:100%;margin-top:6px;padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-family:'Tajawal';font-size:11px;" rows="2"></textarea><div style="margin-top:6px;display:flex;gap:4px;"><select id="rRefund" style="flex:1;padding:4px;border:1px solid #d1d5db;border-radius:4px;font-family:'Tajawal';font-size:11px;"><option value="cash"><?= t('sales.cash') ?></option><option value="card"><?= t('sales.card') ?></option><option value="credit">${_t.customer_credit}</option></select></div><button onclick="doRet(${d.id})" style="margin-top:6px;padding:8px;width:100%;background:#dc2626;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:'Tajawal';font-weight:700;font-size:12px;">${_t.exec_return}</button>`;document.getElementById('rIt').innerHTML=h;});}
function doRet(inv){const items=[];document.querySelectorAll('.rq').forEach(i=>{const q=parseInt(i.value);if(q>0)items.push({item_id:i.dataset.iid,qty:q});});if(!items.length){alert(_t.select_qty);return;}const fd=new FormData();fd.append('action','quick_return');fd.append('invoice_id',inv);fd.append('return_items',JSON.stringify(items));fd.append('reason',document.getElementById('rRsn')?.value||'');
fd.append('refund_method',document.getElementById('rRefund')?.value||'cash');fetch('pos.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){alert(_t.done+' '+d.return_number+' — '+parseFloat(d.total).toFixed(2));document.getElementById('rMo').classList.remove('show');document.getElementById('rIt').innerHTML='';document.getElementById('rIN').value='';}else alert(_t.error_prefix+': '+d.error);});}
sIn.focus();ldH();
const _csrf='<?=generateCsrfToken()?>';
const origFetch=window.fetch;window.fetch=function(url,opts){if(opts&&opts.method==='POST'){if(opts.body instanceof FormData){opts.body.append('_csrf_token',_csrf);}if(!opts.headers)opts.headers={};opts.headers['X-CSRF-TOKEN']=_csrf;}return origFetch(url,opts);};
</script>

<!-- Barcode Scanner Modal -->
<div id="barcodeScannerModal">
    <button class="close-scanner" onclick="closeBarcodeScanner()"><i class="fas fa-times"></i></button>
    <div style="color:#fff;font-size:16px;margin-bottom:12px;font-family:Tajawal,sans-serif;text-align:center;"><i class="fas fa-camera"></i> <?= t('pos.point_camera') ?></div>
    <video id="barcodeVideo" playsinline></video>
    <canvas id="barcodeCanvas" style="display:none;"></canvas>
    <div id="scanStatus" style="color:#3b82f6;font-size:14px;margin-top:12px;font-family:Tajawal,sans-serif;text-align:center;"><?= t('pos.starting_camera') ?></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/@nicolo-ribaudo/chained-promise@0.2.0/dist/index.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>

<script>
var scannerActive = false;

function openBarcodeScanner() {
    var modal = document.getElementById('barcodeScannerModal');
    modal.classList.add('active');
    document.getElementById('scanStatus').textContent = _t.starting_camera;
    
    Quagga.init({
        inputStream: {
            name: "Live",
            type: "LiveStream",
            target: document.getElementById('barcodeVideo'),
            constraints: {
                facingMode: "environment",
                width: { ideal: 640 },
                height: { ideal: 480 }
            }
        },
        decoder: {
            readers: ["ean_reader", "ean_8_reader", "code_128_reader", "code_39_reader", "upc_reader", "upc_e_reader"]
        },
        locate: true,
        frequency: 10
    }, function(err) {
        if (err) {
            document.getElementById('scanStatus').textContent = _t.camera_error + ' ' + err.message;
            console.error(err);
            return;
        }
        Quagga.start();
        scannerActive = true;
        document.getElementById('scanStatus').textContent = _t.point_barcode;
    });

    Quagga.onDetected(function(result) {
        if (!scannerActive) return;
        var code = result.codeResult.code;
        if (code && code.length >= 4) {
            scannerActive = false;
            document.getElementById('scanStatus').textContent = _t.scanned + ' ' + code;
            // Play beep sound
            try { var ctx = new (window.AudioContext || window.webkitAudioContext)(); var osc = ctx.createOscillator(); osc.type = 'sine'; osc.frequency.value = 1000; osc.connect(ctx.destination); osc.start(); setTimeout(function(){osc.stop();ctx.close();}, 150); } catch(e) {}
            
            // Put barcode in search and trigger search
            document.getElementById('sIn').value = code;
            document.getElementById('sIn').dispatchEvent(new Event('input'));
            
            setTimeout(function() { closeBarcodeScanner(); }, 500);
        }
    });
}

function closeBarcodeScanner() {
    scannerActive = false;
    try { Quagga.stop(); } catch(e) {}
    document.getElementById('barcodeScannerModal').classList.remove('active');
}
</script>
</body></html>
