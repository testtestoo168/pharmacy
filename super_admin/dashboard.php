<?php
ini_set('display_errors',1);error_reporting(E_ALL);
try{require_once __DIR__.'/../includes/helpers/env.php';require_once __DIR__.'/../includes/i18n.php';initLanguage();require_once __DIR__.'/../includes/services/Database.php';require_once __DIR__.'/../includes/services/SecurityService.php';require_once __DIR__.'/../includes/services/TenantService.php';}catch(Exception $e){die('Load error: '.$e->getMessage());}
try{$pdo=Database::getInstance()->getPdo();}catch(Exception $e){die('DB error: '.$e->getMessage());}
SecurityService::initSecureSession();
if(!isset($_SESSION['super_admin_id'])){header('Location: ../super_login.php');exit;}

$tenantService=new TenantService($pdo);$action=$_GET['action']??'dashboard';

// ===== معالجة الإجراءات =====

// إضافة صيدلية
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['do']??'')==='add_pharmacy'){
    try{
        $result=$tenantService->createTenant(['name'=>$_POST['name'],'name_en'=>$_POST['name_en']??'','email'=>$_POST['email']??'','phone'=>$_POST['phone']??'','owner_name'=>$_POST['owner_name']??'','city'=>$_POST['city']??'','address'=>$_POST['address']??'','tax_number'=>'','cr_number'=>'','license_number'=>'','admin_username'=>$_POST['admin_username']??'admin','password'=>$_POST['admin_password']??'Admin@123','plan_id'=>intval($_POST['plan_id']??2)]);
        if($result['success']){
            $pdo->prepare("UPDATE tenants SET status='active',max_branches=?,max_users=?,subscription_price=?,subscription_end=?,grace_period_days=?,subscription_notes=? WHERE id=?")
                ->execute([intval($_POST['max_branches']??1),intval($_POST['max_users']??5),floatval($_POST['sub_price']??0),$_POST['sub_end']??null,intval($_POST['grace_days']??7),$_POST['sub_notes']??'',$result['tenant_id']]);
            try{$pdo->prepare("UPDATE subscriptions SET status='active',start_date=CURDATE(),end_date=? WHERE tenant_id=?")->execute([$_POST['sub_end']??date('Y-m-d',strtotime('+1 year')),$result['tenant_id']]);}catch(Exception $e){} // sync for history
        }
        $msg=$result['success']?'تم إضافة الصيدلية بنجاح':'خطأ: '.($result['error']??'');
    }catch(Exception $e){$msg='خطأ: '.$e->getMessage();}
    header('Location: dashboard.php?action=tenants&msg='.urlencode($msg));exit;
}

// تعديل صيدلية
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['do']??'')==='update_pharmacy'){
    try{
        $eid=intval($_POST['tenant_id']);
        $pdo->prepare("UPDATE tenants SET name=?,name_en=?,email=?,phone=?,max_branches=?,max_users=?,subscription_price=?,subscription_end=?,grace_period_days=?,subscription_notes=? WHERE id=?")
            ->execute([$_POST['edit_name'],$_POST['edit_name_en']??'',$_POST['edit_email']??'',$_POST['edit_phone']??'',intval($_POST['edit_max_branches']??1),intval($_POST['edit_max_users']??5),floatval($_POST['edit_sub_price']??0),$_POST['edit_sub_end']??null,intval($_POST['edit_grace_days']??7),$_POST['edit_sub_notes']??'',$eid]);
        $msg='تم التحديث';
    }catch(Exception $e){$msg='خطأ: '.$e->getMessage();}
    header('Location: dashboard.php?action=tenants&msg='.urlencode($msg));exit;
}

// تعديل يوزر وباسورد صيدلية
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['do']??'')==='update_credentials'){
    try{
        $eid=intval($_POST['tenant_id']);
        $uid=intval($_POST['user_id']);
        $newUser=trim($_POST['new_username']??'');
        $newPass=trim($_POST['new_password']??'');
        if($newUser){$pdo->prepare("UPDATE users SET username=? WHERE id=? AND tenant_id=?")->execute([$newUser,$uid,$eid]);}
        if($newPass){$pdo->prepare("UPDATE users SET password=? WHERE id=? AND tenant_id=?")->execute([password_hash($newPass,PASSWORD_DEFAULT),$uid,$eid]);}
        $msg='تم تحديث بيانات الدخول';
    }catch(Exception $e){$msg='خطأ: '.$e->getMessage();}
    header('Location: dashboard.php?action=edit&id='.intval($_POST['tenant_id']).'&msg='.urlencode($msg));exit;
}

// تحديث أسعار الفروع
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['do']??'')==='update_branch_prices'){
    try{
        $eid=intval($_POST['tenant_id']);
        $prices=$_POST['branch_price']??[];
        foreach($prices as $bid=>$price){
            $pdo->prepare("UPDATE branches SET branch_price=? WHERE id=? AND tenant_id=?")->execute([floatval($price),intval($bid),$eid]);
        }
        $msg='تم تحديث أسعار الفروع';
    }catch(Exception $e){$msg='خطأ: '.$e->getMessage();}
    header('Location: dashboard.php?action=edit&id='.intval($_POST['tenant_id']).'&msg='.urlencode($msg));exit;
}

// تفعيل / إيقاف ربط ZATCA لصيدلية
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['do']??'')==='toggle_zatca'){
    try{
        $eid=intval($_POST['tenant_id']);
        $enabled=intval($_POST['zatca_enabled_by_admin']??0);
        $pdo->prepare("UPDATE tenants SET zatca_enabled_by_admin=? WHERE id=?")->execute([$enabled,$eid]);
        $msg=$enabled?'تم تفعيل ربط ZATCA للصيدلية':'تم إيقاف ربط ZATCA للصيدلية';
    }catch(Exception $e){$msg='خطأ: '.$e->getMessage();}
    header('Location: dashboard.php?action=edit&id='.intval($_POST['tenant_id']).'&msg='.urlencode($msg));exit;
}

// إيقاف / تفعيل / مسح
if(($_GET['do']??'')==='suspend'&&isset($_GET['id'])){$pdo->prepare("UPDATE tenants SET status='suspended' WHERE id=?")->execute([intval($_GET['id'])]);header('Location: dashboard.php?action=tenants&msg='.urlencode('تم الإيقاف'));exit;}
if(($_GET['do']??'')==='activate'&&isset($_GET['id'])){$pdo->prepare("UPDATE tenants SET status='active' WHERE id=?")->execute([intval($_GET['id'])]);header('Location: dashboard.php?action=tenants&msg='.urlencode('تم التفعيل'));exit;}
if(($_GET['do']??'')==='delete'&&isset($_GET['id'])&&isset($_GET['confirm'])){$d=intval($_GET['id']);$hard=isset($_GET['hard']);if($hard){$result=$tenantService->deleteTenant($d,true);$msg=$result['success']?'تم مسح الصيدلية وكل بياناتها نهائياً':'خطأ: '.($result['error']??'');}else{$result=$tenantService->deleteTenant($d,false);$msg=$result['success']?'تم إلغاء الصيدلية (البيانات محفوظة)':'خطأ: '.($result['error']??'');}header('Location: dashboard.php?action=tenants&msg='.urlencode($msg));exit;}

// رد على تذكرة
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['do']??'')==='reply_ticket'){
    try{$ticketId=intval($_POST['ticket_id']);$replyMsg=trim($_POST['reply_message']??'');
    if($replyMsg){$pdo->prepare("INSERT INTO support_ticket_replies (ticket_id, user_id, is_admin_reply, message) VALUES (?,?,1,?)")->execute([$ticketId,0,$replyMsg]);
    $pdo->prepare("UPDATE support_tickets SET status='in_progress', updated_at=NOW() WHERE id=?")->execute([$ticketId]);}
    header('Location: dashboard.php?action=tickets&view='.$ticketId.'&msg='.urlencode('تم إرسال الرد'));exit;
    }catch(Exception $e){header('Location: dashboard.php?action=tickets&msg='.urlencode('خطأ'));exit;}}
// إغلاق / فتح تذكرة
if(($_GET['do']??'')==='close_ticket'&&isset($_GET['tid'])){$pdo->prepare("UPDATE support_tickets SET status='closed',updated_at=NOW() WHERE id=?")->execute([intval($_GET['tid'])]);header('Location: dashboard.php?action=tickets&msg='.urlencode('تم إغلاق التذكرة'));exit;}
if(($_GET['do']??'')==='reopen_ticket'&&isset($_GET['tid'])){$pdo->prepare("UPDATE support_tickets SET status='open',updated_at=NOW() WHERE id=?")->execute([intval($_GET['tid'])]);header('Location: dashboard.php?action=tickets&msg='.urlencode('تم إعادة فتح التذكرة'));exit;}

// تغيير باسورد Super Admin
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['do']??'')==='change_password'){$p=$_POST['new_password']??'';if(strlen($p)>=6){$pdo->prepare("UPDATE super_admins SET password=? WHERE id=?")->execute([password_hash($p,PASSWORD_DEFAULT),$_SESSION['super_admin_id']]);$msg='تم تغيير كلمة المرور';}else{$msg='قصيرة';}header('Location: dashboard.php?action=settings&msg='.urlencode($msg));exit;}

// إحصائيات
try{$stats=$tenantService->getAllTenantsStats();}catch(Exception $e){$stats=['total_tenants'=>0,'active_tenants'=>0,'suspended_tenants'=>0];}
try{$totalUsers=$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();}catch(Exception $e){$totalUsers=0;}

// فحص الاشتراكات المنتهية
try{
    $expired=$pdo->query("SELECT id,name,subscription_end,grace_period_days FROM tenants WHERE status='active' AND subscription_end IS NOT NULL AND DATE_ADD(subscription_end, INTERVAL COALESCE(grace_period_days,0) DAY) < CURDATE()")->fetchAll();
    foreach($expired as $exp){
        $pdo->prepare("UPDATE tenants SET status='suspended' WHERE id=?")->execute([$exp['id']]);
    }
    $expiredCount=count($expired);
}catch(Exception $e){$expiredCount=0;}

$pageTitle=['dashboard'=>'لوحة التحكم','tenants'=>'إدارة الصيدليات','add'=>'إضافة صيدلية','edit'=>'تعديل صيدلية','subscriptions'=>'الاشتراكات','tickets'=>'تذاكر الدعم','settings'=>'الإعدادات'][$action]??'لوحة التحكم';
?><!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title><?=$pageTitle?> | URS</title><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></head><body>
<aside class="sidebar" id="sidebar"><div class="sidebar-brand"><img src="../assets/logo-small.png" alt="URS" class="sidebar-logo"><div class="sidebar-brand-text"><h1>URS Admin</h1><p>إدارة المنصة</p></div></div><nav class="sidebar-nav">
<div class="sidebar-section"><div class="sidebar-section-title">الرئيسية</div><a href="?action=dashboard" class="sidebar-link <?=$action==='dashboard'?'active':''?>"><i class="fas fa-tachometer-alt"></i><span>لوحة التحكم</span></a></div>
<div class="sidebar-section"><div class="sidebar-section-title">الصيدليات</div><a href="?action=tenants" class="sidebar-link <?=$action==='tenants'?'active':''?>"><i class="fas fa-store"></i><span>إدارة الصيدليات</span></a><a href="?action=add" class="sidebar-link <?=$action==='add'?'active':''?>"><i class="fas fa-plus-circle"></i><span>إضافة صيدلية</span></a><a href="?action=subscriptions" class="sidebar-link <?=$action==='subscriptions'?'active':''?>"><i class="fas fa-credit-card"></i><span>الاشتراكات</span></a></div>
<div class="sidebar-section"><div class="sidebar-section-title">النظام</div><a href="?action=tickets" class="sidebar-link <?=$action==='tickets'?'active':''?>"><i class="fas fa-headset"></i><span>تذاكر الدعم</span></a><a href="?action=settings" class="sidebar-link <?=$action==='settings'?'active':''?>"><i class="fas fa-cog"></i><span>الإعدادات</span></a></div>
</nav><div class="sidebar-footer"><a href="../super_login.php?logout=1" class="sidebar-link logout-link"><i class="fas fa-sign-out-alt"></i><span>تسجيل خروج</span></a><div class="sidebar-user"><img src="../assets/logo-small.png" alt="URS" class="sidebar-logo"><div class="sidebar-user-info"><p><?=htmlspecialchars($_SESSION['super_admin_name']??'Admin')?></p><small>مدير المنصة</small></div></div></div></aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div><button class="mob-menu-toggle" id="mobMenuToggle" type="button"><i class="fas fa-bars"></i></button>
<div class="main-wrapper"><header class="top-bar"><div class="top-bar-right"><button class="sidebar-toggle" type="button" id="topBarToggle"><i class="fas fa-bars"></i></button><h2 class="page-title"><?=$pageTitle?></h2></div><div class="top-bar-left"></div></header><main class="content">
<?php if(isset($_GET['msg'])):?><div style="background:#dcfce7;color:#166534;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($_GET['msg'])?></div><?php endif;?>
<?php if($expiredCount>0):?><div style="background:#fef2f2;color:#dc2626;padding:12px 20px;border-radius:8px;margin-bottom:16px;"><i class="fas fa-exclamation-triangle"></i> تم إيقاف <?=$expiredCount?> صيدلية تلقائياً بسبب انتهاء الاشتراك</div><?php endif;?>

<?php if($action==='dashboard'):?>
<div class="stats-grid">
<div class="stat-card"><div class="stat-icon"><i class="fas fa-store"></i></div><div class="stat-info"><p class="stat-label">الصيدليات</p><p class="stat-value"><?=$stats['total_tenants']??0?></p></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-info"><p class="stat-label">نشطة</p><p class="stat-value"><?=$stats['active_tenants']??0?></p></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fas fa-pause-circle"></i></div><div class="stat-info"><p class="stat-label">موقوفة</p><p class="stat-value"><?=$stats['suspended_tenants']??0?></p></div></div>
<div class="stat-card"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-info"><p class="stat-label">المستخدمين</p><p class="stat-value"><?=$totalUsers?></p></div></div>
</div>
<?php try{$ts=$tenantService->listTenants(1,10);}catch(Exception $e){$ts=[];}?>
<div class="card"><div class="card-header"><h3><i class="fas fa-store"></i> الصيدليات</h3><a href="?action=add" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> إضافة</a></div><div class="table-responsive"><table><thead><tr><th>#</th><th>الصيدلية</th><th>الفروع</th><th>الاشتراك</th><th>الحالة</th><th>إجراءات</th></tr></thead><tbody>
<?php foreach($ts as $t):$subEnd=$t['subscription_end']??null;$daysLeft=$subEnd?floor((strtotime($subEnd)-time())/86400):null;?>
<tr><td><?=$t['id']?></td><td style="font-weight:600"><?=htmlspecialchars($t['name'])?></td><td><?=$t['branch_count']?></td>
<td><?php if($subEnd):?><span style="color:<?=$daysLeft<=7?'#dc2626':($daysLeft<=30?'#f59e0b':'#16a34a')?>;"><?=$subEnd?> (<?=$daysLeft?> يوم)</span><?php else:?>-<?php endif;?></td>
<td><?=$t['status']==='active'?'<span class="badge badge-success">نشط</span>':'<span class="badge badge-danger">موقوف</span>'?></td>
<td><a href="?action=edit&id=<?=$t['id']?>" style="color:#1d4ed8;text-decoration:none"><i class="fas fa-edit"></i></a> <?php if($t['status']==='active'):?><a href="?do=suspend&id=<?=$t['id']?>" style="color:#f59e0b;text-decoration:none" onclick="return confirm('إيقاف؟')"><i class="fas fa-pause-circle"></i></a><?php else:?><a href="?do=activate&id=<?=$t['id']?>" style="color:#16a34a;text-decoration:none"><i class="fas fa-play-circle"></i></a><?php endif;?> <a href="?do=delete&id=<?=$t['id']?>&confirm=1" style="color:#f59e0b;text-decoration:none" onclick="return confirm('إلغاء الصيدلية؟ (البيانات ستبقى محفوظة)')" title="إلغاء"><i class="fas fa-ban"></i></a> <a href="?do=delete&id=<?=$t['id']?>&confirm=1&hard=1" style="color:#dc2626;text-decoration:none" onclick="return confirm('مسح نهائي؟ كل البيانات هتتمسح ومفيش رجعة!')" title="مسح نهائي"><i class="fas fa-trash"></i></a></td></tr>
<?php endforeach;?></tbody></table></div></div>

<?php elseif($action==='tenants'):?>
<?php try{$ts=$tenantService->listTenants(1,100);}catch(Exception $e){$ts=[];}?>
<div class="card"><div class="card-header"><h3><i class="fas fa-store"></i> جميع الصيدليات</h3><a href="?action=add" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> إضافة</a></div><div class="table-responsive"><table><thead><tr><th>#</th><th>الصيدلية</th><th>الفروع</th><th>إجمالي السعر</th><th>انتهاء الاشتراك</th><th>السماح</th><th>ZATCA</th><th>الحالة</th><th>إجراءات</th></tr></thead><tbody>
<?php foreach($ts as $t):
try{$td=$pdo->prepare("SELECT max_branches,max_users,subscription_price,subscription_end,grace_period_days FROM tenants WHERE id=?");$td->execute([$t['id']]);$td=$td->fetch();}catch(Exception $e){$td=[];}
try{$branchPrices=$pdo->prepare("SELECT id,name,branch_price,is_main FROM branches WHERE tenant_id=? AND is_active=1 ORDER BY is_main DESC,name");$branchPrices->execute([$t['id']]);$bpList=$branchPrices->fetchAll();}catch(Exception $e){$bpList=[];}
$totalBranchPrice=0;foreach($bpList as $bp){$totalBranchPrice+=floatval($bp['branch_price']??0);}
$subEnd=$td['subscription_end']??null;$daysLeft=$subEnd?floor((strtotime($subEnd)-time())/86400):null;$grace=$td['grace_period_days']??0;
?>
<tr>
<td><?=$t['id']?></td>
<td style="font-weight:600"><?=htmlspecialchars($t['name'])?></td>
<td><?=$t['branch_count']?><span style="color:#94a3b8">/<?=$td['max_branches']??'∞'?></span></td>
<td style="font-weight:700;color:#1d4ed8"><?=number_format($totalBranchPrice)?> ر.س
<?php if(count($bpList)>0):?><a href="javascript:void(0)" onclick="document.getElementById('bp<?=$t['id']?>').style.display=document.getElementById('bp<?=$t['id']?>').style.display==='none'?'table-row':'none'" style="font-size:11px;color:#3b82f6;text-decoration:none;margin-right:4px"><i class="fas fa-eye"></i> عرض</a><?php endif;?></td>
<td><?php if($subEnd):?><span style="color:<?=$daysLeft<=0?'#dc2626':($daysLeft<=7?'#f59e0b':'#16a34a')?>"><?=$subEnd?><br><small><?=$daysLeft>0?"باقي $daysLeft يوم":'منتهي!'?></small></span><?php else:?>-<?php endif;?></td>
<td><?=$grace?> يوم</td>
<td><?php $ze=intval($t['zatca_enabled_by_admin']??0); echo $ze?'<span style="background:#dcfce7;color:#16a34a;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700"><i class="fas fa-check"></i> مفعّل</span>':'<span style="color:#94a3b8;font-size:11px">—</span>'; ?></td>
<td><?=$t['status']==='active'?'<span class="badge badge-success">نشط</span>':'<span class="badge badge-danger">موقوف</span>'?></td>
<td style="white-space:nowrap"><a href="?action=edit&id=<?=$t['id']?>" style="color:#1d4ed8;text-decoration:none;margin-left:6px"><i class="fas fa-edit"></i></a><?php if($t['status']==='active'):?><a href="?do=suspend&id=<?=$t['id']?>" style="color:#f59e0b;text-decoration:none;margin-left:6px" onclick="return confirm('إيقاف؟')"><i class="fas fa-pause-circle"></i></a><?php else:?><a href="?do=activate&id=<?=$t['id']?>" style="color:#16a34a;text-decoration:none;margin-left:6px"><i class="fas fa-play-circle"></i></a><?php endif;?><a href="?do=delete&id=<?=$t['id']?>&confirm=1" style="color:#f59e0b;text-decoration:none" onclick="return confirm('إلغاء الصيدلية؟ (البيانات ستبقى محفوظة)')" title="إلغاء"><i class="fas fa-ban"></i></a> <a href="?do=delete&id=<?=$t['id']?>&confirm=1&hard=1" style="color:#dc2626;text-decoration:none" onclick="return confirm('مسح نهائي؟ كل البيانات هتتمسح ومفيش رجعة!')" title="مسح نهائي"><i class="fas fa-trash"></i></a></td>
</tr>
<tr id="bp<?=$t['id']?>" style="display:none"><td colspan="9" style="padding:0">
<table style="width:100%;background:#f8fafc;margin:0"><thead><tr style="background:#e2e8f0"><th style="padding:6px 12px;font-size:12px">الفرع</th><th style="padding:6px 12px;font-size:12px">النوع</th><th style="padding:6px 12px;font-size:12px">سعر الاشتراك</th></tr></thead><tbody>
<?php foreach($bpList as $bp):?>
<tr><td style="padding:6px 12px;font-size:13px"><?=htmlspecialchars($bp['name'])?></td><td style="padding:6px 12px;font-size:12px"><?=$bp['is_main']?'<span style="color:#1d4ed8;font-weight:600">رئيسي</span>':'فرعي'?></td><td style="padding:6px 12px;font-size:13px;font-weight:600;color:#1d4ed8"><?=number_format($bp['branch_price']??0)?> ر.س</td></tr>
<?php endforeach;?>
<tr style="background:#e2e8f0;font-weight:700"><td style="padding:6px 12px" colspan="2">الإجمالي</td><td style="padding:6px 12px;color:#1d4ed8"><?=number_format($totalBranchPrice)?> ر.س</td></tr>
</tbody></table></td></tr>
<?php endforeach;?></tbody></table></div></div>

<?php elseif($action==='subscriptions'):?>
<?php try{$ts=$tenantService->listTenants(1,100);}catch(Exception $e){$ts=[];}?>
<div class="card"><div class="card-header"><h3><i class="fas fa-credit-card"></i> الاشتراكات</h3></div><div class="table-responsive"><table><thead><tr><th>الصيدلية</th><th>السعر</th><th>تاريخ الانتهاء</th><th>فترة السماح</th><th>الأيام المتبقية</th><th>الحالة</th></tr></thead><tbody>
<?php foreach($ts as $t):
try{$sd=$pdo->prepare("SELECT subscription_price,subscription_end,grace_period_days FROM tenants WHERE id=?");$sd->execute([$t['id']]);$sub=$sd->fetch();}catch(Exception $e){$sub=[];}
$subEnd=$sub['subscription_end']??null;$daysLeft=$subEnd?floor((strtotime($subEnd)-time())/86400):null;$grace=$sub['grace_period_days']??0;$totalDays=$daysLeft!==null?$daysLeft+$grace:null;
?>
<tr><td style="font-weight:600"><?=htmlspecialchars($t['name'])?></td>
<td style="font-weight:700;color:#1d4ed8"><?=number_format($sub['subscription_price']??0)?> ر.س</td>
<td><?=$subEnd??'غير محدد'?></td>
<td><?=$grace?> يوم</td>
<td><?php if($totalDays!==null):?><span style="font-weight:700;color:<?=$totalDays<=0?'#dc2626':($totalDays<=7?'#f59e0b':($totalDays<=30?'#ca8a04':'#16a34a'))?>"><?php if($totalDays<=0):?>منتهي<?php elseif($daysLeft<=0):?>فترة سماح (<?=$totalDays?> يوم)<?php else:?><?=$daysLeft?> يوم<?php endif;?></span><?php else:?>-<?php endif;?></td>
<td><?=$t['status']==='active'?'<span class="badge badge-success">نشط</span>':'<span class="badge badge-danger">موقوف</span>'?></td></tr>
<?php endforeach;?></tbody></table></div></div>

<?php elseif($action==='add'):?>
<?php try{$plans=$pdo->query("SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY sort_order")->fetchAll();}catch(Exception $e){$plans=[];}?>
<div class="card"><div class="card-header"><h3><i class="fas fa-plus-circle"></i> إضافة صيدلية</h3></div><div class="card-body" style="padding:20px"><form method="POST"><input type="hidden" name="do" value="add_pharmacy">
<h4 style="font-size:14px;margin-bottom:12px"><i class="fas fa-store"></i> بيانات الصيدلية</h4>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px"><div class="form-group"><label>اسم الصيدلية *</label><input type="text" name="name" class="form-control" required></div><div class="form-group"><label>الاسم بالإنجليزي</label><input type="text" name="name_en" class="form-control"></div><div class="form-group"><label>اسم المالك</label><input type="text" name="owner_name" class="form-control"></div><div class="form-group"><label>البريد</label><input type="email" name="email" class="form-control"></div><div class="form-group"><label>الجوال</label><input type="text" name="phone" class="form-control"></div><div class="form-group"><label>المدينة</label><input type="text" name="city" class="form-control"></div></div>
<hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0">
<h4 style="font-size:14px;margin-bottom:12px"><i class="fas fa-sliders-h"></i> الحدود</h4>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px"><div class="form-group"><label>الحد الأقصى للفروع</label><input type="number" name="max_branches" class="form-control" value="1" min="1"></div><div class="form-group"><label>الحد الأقصى للمستخدمين</label><input type="number" name="max_users" class="form-control" value="5" min="1"></div></div>
<hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0">
<h4 style="font-size:14px;margin-bottom:12px"><i class="fas fa-credit-card"></i> الاشتراك</h4>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px"><div class="form-group"><label>سعر الاشتراك (ر.س)</label><input type="number" step="0.01" name="sub_price" class="form-control" value="0"></div><div class="form-group"><label>تاريخ انتهاء الاشتراك</label><input type="date" name="sub_end" class="form-control" value="<?=date('Y-m-d',strtotime('+1 year'))?>"></div><div class="form-group"><label>فترة السماح (أيام)</label><input type="number" name="grace_days" class="form-control" value="7" min="0"></div></div>
<div class="form-group" style="margin-top:8px"><label>ملاحظات الاشتراك</label><textarea name="sub_notes" class="form-control" rows="2"></textarea></div>
<hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0">
<h4 style="font-size:14px;margin-bottom:12px"><i class="fas fa-user-shield"></i> حساب المدير</h4>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px"><div class="form-group"><label>اسم المستخدم *</label><input type="text" name="admin_username" class="form-control" value="admin" required></div><div class="form-group"><label>كلمة المرور *</label><input type="text" name="admin_password" class="form-control" value="Admin@123" required></div></div>
<div style="margin-top:20px"><button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> إضافة الصيدلية</button></div></form></div></div>

<?php elseif($action==='edit'&&isset($_GET['id'])):$eid=intval($_GET['id']);
try{$s=$pdo->prepare("SELECT * FROM tenants WHERE id=?");$s->execute([$eid]);$et=$s->fetch();}catch(Exception $e){$et=null;}
if(!$et):?><div class="alert alert-danger">غير موجودة</div><?php else:
try{$bc=$pdo->prepare("SELECT COUNT(*) FROM branches WHERE tenant_id=?");$bc->execute([$eid]);$bCnt=$bc->fetchColumn();}catch(Exception $e){$bCnt=0;}
try{$uc=$pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=?");$uc->execute([$eid]);$uCnt=$uc->fetchColumn();}catch(Exception $e){$uCnt=0;}
// جلب كل اليوزرات
try{$usersQ=$pdo->prepare("SELECT u.*, b.name as branch_name FROM users u LEFT JOIN branches b ON b.id=u.branch_id WHERE u.tenant_id=? ORDER BY u.id");$usersQ->execute([$eid]);$usersList=$usersQ->fetchAll();}catch(Exception $e){$usersList=[];}
?>
<div class="card" style="margin-bottom:16px"><div class="card-header"><h3><i class="fas fa-edit"></i> تعديل: <?=htmlspecialchars($et['name'])?></h3><a href="?action=tenants" class="btn btn-sm" style="text-decoration:none"><i class="fas fa-arrow-right"></i> رجوع</a></div><div class="card-body" style="padding:20px"><form method="POST"><input type="hidden" name="do" value="update_pharmacy"><input type="hidden" name="tenant_id" value="<?=$eid?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
<div class="form-group"><label>الاسم</label><input type="text" name="edit_name" class="form-control" value="<?=htmlspecialchars($et['name'])?>" required></div>
<div class="form-group"><label>الاسم بالإنجليزي</label><input type="text" name="edit_name_en" class="form-control" value="<?=htmlspecialchars($et['name_en']??'')?>"></div>
<div class="form-group"><label>البريد</label><input type="email" name="edit_email" class="form-control" value="<?=htmlspecialchars($et['email']??'')?>"></div>
<div class="form-group"><label>الجوال</label><input type="text" name="edit_phone" class="form-control" value="<?=htmlspecialchars($et['phone']??'')?>"></div>
<div class="form-group"><label>حد الفروع (حالياً: <?=$bCnt?>)</label><input type="number" name="edit_max_branches" class="form-control" value="<?=$et['max_branches']??1?>" min="1"></div>
<div class="form-group"><label>حد المستخدمين (حالياً: <?=$uCnt?>)</label><input type="number" name="edit_max_users" class="form-control" value="<?=$et['max_users']??5?>" min="1"></div>
</div>
<hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0">
<h4 style="font-size:14px;margin-bottom:12px"><i class="fas fa-credit-card"></i> الاشتراك</h4>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
<div class="form-group"><label>سعر الاشتراك (ر.س)</label><input type="number" step="0.01" name="edit_sub_price" class="form-control" value="<?=$et['subscription_price']??0?>"></div>
<div class="form-group"><label>تاريخ انتهاء الاشتراك</label><input type="date" name="edit_sub_end" class="form-control" value="<?=$et['subscription_end']??''?>"></div>
<div class="form-group"><label>فترة السماح (أيام)</label><input type="number" name="edit_grace_days" class="form-control" value="<?=$et['grace_period_days']??7?>" min="0"></div>
</div>
<div class="form-group" style="margin-top:8px"><label>ملاحظات</label><textarea name="edit_sub_notes" class="form-control" rows="2"><?=$et['subscription_notes']??''?></textarea></div>
<div style="margin-top:16px"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button> <a href="?action=tenants" class="btn" style="text-decoration:none">إلغاء</a></div>
</form></div></div>

<!-- يوزرات الصيدلية -->
<div class="card"><div class="card-header"><h3><i class="fas fa-users"></i> مستخدمين الصيدلية (<?=count($usersList)?>)</h3></div><div class="table-responsive"><table><thead><tr><th>#</th><th>الاسم</th><th>اليوزر</th><th>الفرع</th><th>الدور</th><th>تعديل الدخول</th></tr></thead><tbody>
<?php foreach($usersList as $u):?>
<tr><td><?=$u['id']?></td><td style="font-weight:600"><?=htmlspecialchars($u['full_name'])?></td><td><code><?=htmlspecialchars($u['username'])?></code></td><td style="font-size:12px"><?=htmlspecialchars($u['branch_name']??'رئيسي')?></td>
<td><?=$u['role']==='admin'?'<span class="badge badge-primary">مدير</span>':'<span class="badge badge-info">فرع</span>'?></td>
<td><form method="POST" style="display:flex;gap:4px;align-items:center"><input type="hidden" name="do" value="update_credentials"><input type="hidden" name="tenant_id" value="<?=$eid?>"><input type="hidden" name="user_id" value="<?=$u['id']?>">
<input type="text" name="new_username" placeholder="يوزر جديد" class="form-control" style="width:110px;padding:4px 8px;font-size:12px" value="<?=htmlspecialchars($u['username'])?>">
<input type="text" name="new_password" placeholder="باسورد جديد" class="form-control" style="width:110px;padding:4px 8px;font-size:12px">
<button type="submit" class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:12px" onclick="return confirm('تحديث بيانات الدخول؟')"><i class="fas fa-save"></i></button></form></td></tr>
<?php endforeach;?></tbody></table></div></div>

<!-- أسعار الفروع -->
<?php 
try{$editBranches=$pdo->prepare("SELECT id,name,is_main,branch_price FROM branches WHERE tenant_id=? AND is_active=1 ORDER BY is_main DESC,name");$editBranches->execute([$eid]);$editBranchesList=$editBranches->fetchAll();}catch(Exception $e){$editBranchesList=[];}
$editTotalPrice=0;foreach($editBranchesList as $eb){$editTotalPrice+=floatval($eb['branch_price']??0);}
?>
<div class="card"><div class="card-header"><h3><i class="fas fa-money-bill"></i> أسعار الفروع — الإجمالي: <span style="color:#1d4ed8"><?=number_format($editTotalPrice)?> ر.س</span></h3></div>
<div class="card-body" style="padding:20px">
<form method="POST">
<input type="hidden" name="do" value="update_branch_prices">
<input type="hidden" name="tenant_id" value="<?=$eid?>">
<table style="width:100%"><thead><tr><th>الفرع</th><th>النوع</th><th>سعر الاشتراك (ر.س)</th></tr></thead><tbody>
<?php foreach($editBranchesList as $eb):?>
<tr><td style="font-weight:600"><?=htmlspecialchars($eb['name'])?></td><td><?=$eb['is_main']?'<span class="badge badge-primary">رئيسي</span>':'فرعي'?></td>
<td><input type="number" step="0.01" name="branch_price[<?=$eb['id']?>]" class="form-control" value="<?=$eb['branch_price']??0?>" style="width:200px"></td></tr>
<?php endforeach;?>
<tr style="background:#f1f5f9;font-weight:700"><td colspan="2">الإجمالي</td><td id="totalBP" style="color:#1d4ed8;font-size:18px"><?=number_format($editTotalPrice)?> ر.س</td></tr>
</tbody></table>
<div style="margin-top:12px"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الأسعار</button></div>
</form>
<script>document.querySelectorAll('input[name^="branch_price"]').forEach(function(inp){inp.addEventListener('input',function(){var t=0;document.querySelectorAll('input[name^="branch_price"]').forEach(function(i){t+=parseFloat(i.value)||0});document.getElementById('totalBP').textContent=t.toLocaleString()+' ر.س'})});</script>
</div></div>

<!-- ربط ZATCA -->
<?php
$zatcaEnabled = intval($et['zatca_enabled_by_admin'] ?? 0);
$zatcaSettings = [];
try { $zs = $pdo->prepare("SELECT * FROM compliance_settings WHERE tenant_id = ?"); $zs->execute([$eid]); $zatcaSettings = $zs->fetch() ?: []; } catch(Exception $e) {}
$zatcaStatus = $zatcaSettings['zatca_onboarding_status'] ?? 'not_started';
$zatcaEnv = $zatcaSettings['zatca_env'] ?? 'sandbox';
$statusLabels = [
    'not_started' => ['لم يبدأ', '#94a3b8', '#f1f5f9'],
    'csr_generated' => ['تم توليد CSR', '#f59e0b', '#fef3c7'],
    'compliance_obtained' => ['تم الحصول على Compliance CSID', '#3b82f6', '#dbeafe'],
    'compliance_checked' => ['تم فحص التوافق', '#8b5cf6', '#ede9fe'],
    'production_obtained' => ['تم الحصول على Production CSID', '#059669', '#d1fae5'],
    'active' => ['مفعّل ويعمل', '#16a34a', '#dcfce7'],
    'expired' => ['منتهي الصلاحية', '#dc2626', '#fef2f2'],
    'error' => ['خطأ', '#dc2626', '#fef2f2'],
];
$sl = $statusLabels[$zatcaStatus] ?? ['غير معروف', '#94a3b8', '#f1f5f9'];
?>
<div class="card"><div class="card-header"><h3><i class="fas fa-file-invoice" style="color:#16a34a"></i> ربط هيئة الزكاة والضريبة (ZATCA)</h3></div>
<div class="card-body" style="padding:20px">
<form method="POST">
<input type="hidden" name="do" value="toggle_zatca">
<input type="hidden" name="tenant_id" value="<?=$eid?>">

<div style="display:flex;align-items:center;gap:16px;padding:16px;background:<?=$zatcaEnabled?'#f0fdf4':'#fef2f2'?>;border-radius:10px;border:1px solid <?=$zatcaEnabled?'#bbf7d0':'#fecaca'?>;margin-bottom:16px">
    <div style="flex:1">
        <div style="font-weight:700;font-size:15px;margin-bottom:4px">
            <i class="fas fa-<?=$zatcaEnabled?'check-circle':'times-circle'?>" style="color:<?=$zatcaEnabled?'#16a34a':'#dc2626'?>"></i>
            ربط الفوترة الإلكترونية: <?=$zatcaEnabled?'<span style="color:#16a34a">مفعّل</span>':'<span style="color:#dc2626">غير مفعّل</span>'?>
        </div>
        <div style="font-size:12px;color:#64748b">عند التفعيل، ستتمكن الصيدلية من إعداد ربط ZATCA من إعداداتها</div>
    </div>
    <div>
        <?php if($zatcaEnabled): ?>
        <input type="hidden" name="zatca_enabled_by_admin" value="0">
        <button type="submit" class="btn btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca" onclick="return confirm('إيقاف ربط ZATCA لهذه الصيدلية؟')"><i class="fas fa-power-off"></i> إيقاف الربط</button>
        <?php else: ?>
        <input type="hidden" name="zatca_enabled_by_admin" value="1">
        <button type="submit" class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0"><i class="fas fa-plug"></i> تفعيل الربط</button>
        <?php endif; ?>
    </div>
</div>

<?php if($zatcaEnabled): ?>
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
    <div style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:#64748b;margin-bottom:4px">حالة الربط</div>
        <span style="background:<?=$sl[2]?>;color:<?=$sl[1]?>;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700"><?=$sl[0]?></span>
    </div>
    <div style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:#64748b;margin-bottom:4px">البيئة</div>
        <span style="font-weight:700;color:<?=$zatcaEnv==='production'?'#dc2626':'#3b82f6'?>"><?=$zatcaEnv==='production'?'إنتاج (Production)':'تجريبي (Sandbox)'?></span>
    </div>
    <div style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center">
        <div style="font-size:11px;color:#64748b;margin-bottom:4px">فواتير مرسلة</div>
        <?php try{$ic=$pdo->prepare("SELECT COUNT(*) FROM e_invoice_logs WHERE tenant_id=? AND submission_status IN('reported','accepted')");$ic->execute([$eid]);$invCount=$ic->fetchColumn();}catch(Exception $e){$invCount=0;}?>
        <span style="font-weight:700;color:#1d4ed8"><?=$invCount?></span>
    </div>
</div>
<?php if(!empty($zatcaSettings['zatca_last_error'])): ?>
<div style="margin-top:12px;padding:10px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;font-size:12px;color:#dc2626">
    <i class="fas fa-exclamation-triangle"></i> آخر خطأ: <?=htmlspecialchars($zatcaSettings['zatca_last_error'])?>
</div>
<?php endif; ?>
<?php endif; ?>
</form>
</div></div>
<?php endif;?>

<?php elseif($action==='tickets'):?>
<?php
$viewTicket = isset($_GET['view']) ? intval($_GET['view']) : 0;
$ticketFilter = $_GET['status'] ?? '';

if ($viewTicket > 0):
    // عرض تذكرة واحدة مع الردود
    try{$tk=$pdo->prepare("SELECT st.*, t.name as tenant_name, u.full_name as user_name FROM support_tickets st LEFT JOIN tenants t ON t.id=st.tenant_id LEFT JOIN users u ON u.id=st.user_id WHERE st.id=?");$tk->execute([$viewTicket]);$ticket=$tk->fetch();}catch(Exception $e){$ticket=null;}
    if($ticket):
    try{$replies=$pdo->prepare("SELECT r.*, u.full_name as user_name FROM support_ticket_replies r LEFT JOIN users u ON u.id=r.user_id WHERE r.ticket_id=? ORDER BY r.id ASC");$replies->execute([$viewTicket]);$replyList=$replies->fetchAll();}catch(Exception $e){$replyList=[];}
?>
<div class="card" style="margin-bottom:16px">
    <div class="card-header">
        <h3><i class="fas fa-ticket-alt"></i> تذكرة #<?=$ticket['id']?> — <?=htmlspecialchars($ticket['subject'])?></h3>
        <div style="display:flex;gap:6px">
            <a href="?action=tickets" class="btn btn-sm" style="text-decoration:none"><i class="fas fa-arrow-right"></i> رجوع</a>
            <?php if($ticket['status']!=='closed'):?><a href="?do=close_ticket&tid=<?=$ticket['id']?>" class="btn btn-sm" style="background:#fef2f2;color:#dc2626;text-decoration:none" onclick="return confirm('إغلاق التذكرة؟')"><i class="fas fa-times"></i> إغلاق</a>
            <?php else:?><a href="?do=reopen_ticket&tid=<?=$ticket['id']?>" class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;text-decoration:none"><i class="fas fa-redo"></i> إعادة فتح</a><?php endif;?>
        </div>
    </div>
    <div class="card-body" style="padding:20px">
        <div style="display:flex;gap:20px;margin-bottom:16px;font-size:13px;color:#64748b">
            <div><i class="fas fa-store"></i> <?=htmlspecialchars($ticket['tenant_name']??'—')?></div>
            <div><i class="fas fa-user"></i> <?=htmlspecialchars($ticket['user_name']??'—')?></div>
            <div><i class="fas fa-clock"></i> <?=$ticket['created_at']?></div>
            <div><span style="background:<?=$ticket['status']==='open'?'#fef3c7':($ticket['status']==='in_progress'?'#dbeafe':'#f1f5f9')?>; color:<?=$ticket['status']==='open'?'#92400e':($ticket['status']==='in_progress'?'#1d4ed8':'#64748b')?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700"><?=$ticket['status']==='open'?'جديدة':($ticket['status']==='in_progress'?'قيد المعالجة':'مغلقة')?></span></div>
        </div>
        <div style="background:#f8fafc;padding:16px;border-radius:8px;margin-bottom:16px;border-right:4px solid #3b82f6">
            <div style="font-size:12px;color:#64748b;margin-bottom:4px"><?=htmlspecialchars($ticket['user_name']??'المستخدم')?> — <?=$ticket['created_at']?></div>
            <?=nl2br(htmlspecialchars($ticket['message']))?>
        </div>

        <?php foreach($replyList as $r):?>
        <div style="background:<?=$r['is_admin_reply']?'#eff6ff':'#f8fafc'?>;padding:14px;border-radius:8px;margin-bottom:8px;border-right:4px solid <?=$r['is_admin_reply']?'#1d4ed8':'#94a3b8'?>">
            <div style="font-size:12px;color:#64748b;margin-bottom:4px"><strong><?=$r['is_admin_reply']?'إدارة النظام':htmlspecialchars($r['user_name']??'المستخدم')?></strong> — <?=$r['created_at']?></div>
            <?=nl2br(htmlspecialchars($r['message']))?>
        </div>
        <?php endforeach;?>

        <?php if($ticket['status']!=='closed'):?>
        <form method="POST" style="margin-top:16px">
            <input type="hidden" name="do" value="reply_ticket">
            <input type="hidden" name="ticket_id" value="<?=$ticket['id']?>">
            <div class="form-group" style="margin-bottom:10px"><label>الرد</label><textarea name="reply_message" class="form-control" rows="3" required placeholder="اكتب ردك هنا..."></textarea></div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> إرسال الرد</button>
        </form>
        <?php endif;?>
    </div>
</div>
<?php else:?><div class="alert alert-danger">التذكرة غير موجودة</div><?php endif;?>

<?php else:
    // قائمة كل التذاكر
    $where = "WHERE 1=1";
    if($ticketFilter) $where .= " AND st.status='".addslashes($ticketFilter)."'";
    try{$tickets=$pdo->query("SELECT st.*, t.name as tenant_name, u.full_name as user_name, (SELECT COUNT(*) FROM support_ticket_replies WHERE ticket_id=st.id AND is_admin_reply=0) as user_replies, (SELECT COUNT(*) FROM support_ticket_replies WHERE ticket_id=st.id AND is_admin_reply=1) as admin_replies FROM support_tickets st LEFT JOIN tenants t ON t.id=st.tenant_id LEFT JOIN users u ON u.id=st.user_id $where ORDER BY CASE WHEN st.status='open' THEN 0 WHEN st.status='in_progress' THEN 1 ELSE 2 END, st.updated_at DESC LIMIT 100")->fetchAll();}catch(Exception $e){$tickets=[];}
    $openCount=0;$progressCount=0;$closedCount=0;
    foreach($tickets as $tk){if($tk['status']==='open')$openCount++;elseif($tk['status']==='in_progress')$progressCount++;else $closedCount++;}
?>
<div style="display:flex;gap:8px;margin-bottom:16px">
    <a href="?action=tickets" class="btn btn-sm" style="background:<?=!$ticketFilter?'#1d4ed8;color:#fff':'#f1f5f9;color:#374151'?>;text-decoration:none">الكل (<?=count($tickets)?>)</a>
    <a href="?action=tickets&status=open" class="btn btn-sm" style="background:<?=$ticketFilter==='open'?'#f59e0b;color:#fff':'#fef3c7;color:#92400e'?>;text-decoration:none">جديدة (<?=$openCount?>)</a>
    <a href="?action=tickets&status=in_progress" class="btn btn-sm" style="background:<?=$ticketFilter==='in_progress'?'#3b82f6;color:#fff':'#dbeafe;color:#1d4ed8'?>;text-decoration:none">قيد المعالجة (<?=$progressCount?>)</a>
    <a href="?action=tickets&status=closed" class="btn btn-sm" style="background:<?=$ticketFilter==='closed'?'#64748b;color:#fff':'#f1f5f9;color:#64748b'?>;text-decoration:none">مغلقة (<?=$closedCount?>)</a>
</div>
<div class="card"><div class="card-header"><h3><i class="fas fa-headset"></i> تذاكر الدعم</h3></div><div class="table-responsive"><table>
<thead><tr><th>#</th><th>الموضوع</th><th>الصيدلية</th><th>المستخدم</th><th>الحالة</th><th>الردود</th><th>آخر تحديث</th><th></th></tr></thead><tbody>
<?php if(empty($tickets)):?><tr><td colspan="8" style="text-align:center;padding:40px;color:#94a3b8">لا توجد تذاكر</td></tr>
<?php else: foreach($tickets as $tk):
$stColor=['open'=>['#fef3c7','#92400e','جديدة'],'in_progress'=>['#dbeafe','#1d4ed8','قيد المعالجة'],'closed'=>['#f1f5f9','#64748b','مغلقة']];
$sc=$stColor[$tk['status']]??['#f1f5f9','#64748b',$tk['status']];
?>
<tr><td><?=$tk['id']?></td>
<td style="font-weight:600"><a href="?action=tickets&view=<?=$tk['id']?>" style="color:#1d4ed8;text-decoration:none"><?=htmlspecialchars($tk['subject'])?></a></td>
<td style="font-size:12px"><?=htmlspecialchars($tk['tenant_name']??'—')?></td>
<td style="font-size:12px"><?=htmlspecialchars($tk['user_name']??'—')?></td>
<td><span style="background:<?=$sc[0]?>;color:<?=$sc[1]?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700"><?=$sc[2]?></span></td>
<td style="font-size:12px"><?=$tk['user_replies']?> مستخدم / <?=$tk['admin_replies']?> إدارة</td>
<td style="font-size:12px;color:#64748b"><?=$tk['updated_at']?></td>
<td><a href="?action=tickets&view=<?=$tk['id']?>" class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:12px;text-decoration:none"><i class="fas fa-eye"></i></a></td></tr>
<?php endforeach;endif;?>
</tbody></table></div></div>
<?php endif;?>

<?php elseif($action==='settings'):?>
<div class="card"><div class="card-header"><h3><i class="fas fa-key"></i> تغيير كلمة المرور</h3></div><div class="card-body" style="padding:20px"><form method="POST" style="max-width:400px"><input type="hidden" name="do" value="change_password"><div class="form-group" style="margin-bottom:14px"><label>كلمة المرور الجديدة</label><input type="password" name="new_password" class="form-control" required minlength="6"></div><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button></form></div></div>
<?php endif;?>
</main><div style="text-align:center;padding:15px;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px">URS System</div></div>
<script>document.getElementById('sidebarOverlay')?.addEventListener('click',function(){document.getElementById('sidebar').classList.remove('active');this.classList.remove('active')});document.getElementById('mobMenuToggle')?.addEventListener('click',function(){document.getElementById('sidebar').classList.toggle('active');document.getElementById('sidebarOverlay').classList.toggle('active')});document.getElementById('topBarToggle')?.addEventListener('click',function(){if(window.innerWidth>1024)document.body.classList.toggle('sidebar-collapsed');else{document.getElementById('sidebar').classList.toggle('active');document.getElementById('sidebarOverlay').classList.toggle('active')}});</script></body></html>
