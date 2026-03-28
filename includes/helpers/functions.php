<?php
/**
 * URS Pharmacy ERP - دوال مساعدة عامة
 * التنسيق، طرق الدفع، العملة، الولاء، سجل العمليات
 */

// ========== توليد أرقام تسلسلية ==========
function generateNumber($pdo,$table,$column,$prefix=''){$tid=getTenantId();$s=$pdo->prepare("SELECT MAX(CAST(REPLACE($column,?,'') AS UNSIGNED)) as mx FROM $table WHERE tenant_id=?");$s->execute([$prefix,$tid]);$r=$s->fetch();return $prefix.str_pad(($r['mx']??0)+1,6,'0',STR_PAD_LEFT);}

// ========== تنسيق المبالغ ==========
function formatMoney($a){return number_format($a,2);}
function formatCurrency($a){return number_format($a,2) . ' <span style="font-size:12px;color:#64748b;font-weight:600;">'.t('sar').'</span>';}

// ========== العملة ==========
function getCurrencySymbol() { return t('sar'); }
function sar($size='14'){$sym=getCurrencySymbol();return '<span style="font-weight:700;color:#166534;font-size:'.$size.'px;">'.$sym.'</span>';}

// ========== طرق الدفع ==========
function paymentTypeLabel($t){return['cash'=>t('pay.cash'),'card'=>t('pay.card'),'transfer'=>t('pay.transfer'),'insurance'=>t('pay.insurance'),'credit'=>t('pay.credit'),'check'=>t('pay.check'),'network'=>t('pay.network')][$t]??$t;}
function paymentMethodLabel($m){return paymentTypeLabel($m);}
function paymentTypeBadge($t){$c=['cash'=>'badge-success','card'=>'badge-primary','transfer'=>'badge-info','insurance'=>'badge-warning','credit'=>'badge-warning','check'=>'badge-info'];return'<span class="badge '.($c[$t]??'badge-secondary').'">'.paymentTypeLabel($t).'</span>';}

function paymentMethodLabelEn($method) {
    $labels = ['cash'=>'Cash','transfer'=>'Bank Transfer','card'=>'Card/Network','check'=>'Check','network'=>'Network'];
    return $labels[$method] ?? ucfirst($method ?? 'Cash');
}

function isDeferred($paymentType) {
    return in_array($paymentType, ['credit','deferred']);
}

// ========== إعدادات الشركة ==========
function getCompanySettings($pdo){$tid=getTenantId();try{$s=$pdo->prepare("SELECT * FROM tenant_settings WHERE tenant_id=? LIMIT 1");$s->execute([$tid]);$r=$s->fetch();if($r)return $r;}catch(Exception $e){}try{$s=$pdo->prepare("SELECT * FROM company_settings WHERE tenant_id=? LIMIT 1");$s->execute([$tid]);$r=$s->fetch();if($r)return $r;}catch(Exception $e){try{$pdo->exec("ALTER TABLE company_settings ADD COLUMN tenant_id INT DEFAULT 1");$pdo->exec("ALTER TABLE company_settings ADD COLUMN logo VARCHAR(255) DEFAULT ''");}catch(Exception $e2){}try{$s=$pdo->prepare("SELECT * FROM company_settings WHERE tenant_id=? LIMIT 1");$s->execute([$tid]);return $s->fetch()?:[];}catch(Exception $e3){}}try{$s=$pdo->query("SELECT * FROM company_settings LIMIT 1");return $s->fetch()?:[];}catch(Exception $e){return [];}}

// ========== سجل العمليات ==========
function logActivity($pdo,$action,$details='',$module=''){try{$pdo->prepare("INSERT INTO activity_log (tenant_id,user_id,username,action,details,module,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)")->execute([getTenantId(),$_SESSION['user_id']??null,$_SESSION['username']??'Unknown',$action,$details,$module,$_SERVER['REMOTE_ADDR']??'',$_SERVER['HTTP_USER_AGENT']??'']);}catch(Exception $e){}}

// ========== نظام الولاء ==========
function getLoyaltySettings($pdo){try{$s=$pdo->prepare("SELECT * FROM loyalty_settings WHERE tenant_id=? LIMIT 1");$s->execute([getTenantId()]);$r=$s->fetch();return $r?:['points_per_sar'=>1,'sar_per_point'=>0.10,'min_redeem_points'=>100,'max_redeem_percent'=>50,'is_active'=>1];}catch(Exception $e){return['points_per_sar'=>1,'sar_per_point'=>0.10,'min_redeem_points'=>100,'max_redeem_percent'=>50,'is_active'=>1];}}
function calcLoyaltyPoints($a,$s){return($s['is_active']??1)?floor(floatval($a)*floatval($s['points_per_sar']??1)):0;}
function calcRedeemValue($p,$s){return round($p*floatval($s['sar_per_point']??0.10),2);}

// ========== Language-aware product name ==========
function productName($product) {
    if (currentLang() === 'en' && !empty($product['name_en'])) {
        return $product['name_en'];
    }
    return $product['name'];
}

// ========== SQL expression for language-aware product name ==========
function sqlProductName($alias = 'p') {
    if (currentLang() === 'en') {
        return "COALESCE(NULLIF({$alias}.name_en, ''), {$alias}.name)";
    }
    return "{$alias}.name";
}

// ========== Get display name from a row that has name and name_en ==========
function displayName($row, $nameField = 'name', $nameEnField = 'name_en') {
    if (currentLang() === 'en' && !empty($row[$nameEnField])) {
        return $row[$nameEnField];
    }
    return $row[$nameField] ?? '';
}

// ========== Translate dosage form key ==========
function dosageFormLabel($key) {
    if (empty($key) || $key === '-') return '-';
    $translated = t('products.' . $key);
    // If translation returns the key itself (not found), try as-is (legacy data)
    if ($translated === 'products.' . $key) return $key;
    return $translated;
}
