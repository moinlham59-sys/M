<?php
// ============================================================
//  admin_panel.php — Full Dark Web Admin Panel
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/bot_manager.php';

session_start();
try { install_schema(); } catch (\Exception $e) {}

$PANEL_USER='admin'; $PANEL_PASS='admin2024'; // ← CHANGE THIS

if (isset($_POST['do_login'])) {
    if ($_POST['au']===$PANEL_USER&&$_POST['ap']===$PANEL_PASS){$_SESSION['adm']=true;$_SESSION['at']=time();}
    else $lerr='WRONG CREDENTIALS';
}
if (isset($_GET['logout'])){session_destroy();header('Location:'.$_SERVER['PHP_SELF']);exit;}
if (isset($_SESSION['at'])&&time()-$_SESSION['at']>14400){session_destroy();header('Location:'.$_SERVER['PHP_SELF']);exit;}
if (!($_SESSION['adm']??false)){login_page($lerr??'');exit;}
$_SESSION['at']=time();

$flash='';
$tab=$_GET['tab']??'dash';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act=$_POST['act']??'';
    switch($act) {
        case 'grant_bot':
            $bid=(int)$_POST['bot_id'];$days=(int)$_POST['days'];
            if($bid&&$days>0){[$ok,$exp]=BotManager::grantDays($bid,$days,OWNER_ID,$_POST['reason']??'WEB');$b=BotManager::getBot($bid);if($ok&&$b)Telegram::send((int)$b['owner_id'],"🎁 <b>+{$days} DAYS</b> ON BOT @{$b['bot_username']}\n📆 ".date('d M Y H:i',strtotime($exp)));$flash=$ok?"✅ GRANTED {$days}D TO BOT #{$bid}":"❌ FAILED: {$exp}";}break;
        case 'grant_user':
            $uid=(int)$_POST['user_tg_id'];$days=(int)$_POST['days'];
            if($uid&&$days>0){[$ok,$cnt]=BotManager::grantDaysByUser($uid,$days,OWNER_ID,$_POST['reason']??'WEB');if($ok)Telegram::send($uid,"🎁 <b>+{$days} DAYS</b> GRANTED TO ALL YOUR BOTS!");$flash=$ok?"✅ GRANTED {$days}D TO USER {$uid} ({$cnt} BOTS)":"❌ FAILED";}break;
        case 'add_bot':
            $token=trim($_POST['bot_token']);$ownerId=(int)$_POST['owner_id'];$days=(int)($_POST['days']??0);
            if($token&&$ownerId){$ou=UserManager::get($ownerId);if(!$ou){$flash="❌ USER {$ownerId} NOT FOUND. MUST START BOT FIRST.";break;}
            [$ok,$res,$info]=BotManager::addBot($ownerId,$token,'admin')+[2=>null];
            if($ok){$botId=$res;if($days>0)BotManager::grantDays($botId,$days,OWNER_ID,'ADMIN ADD');$flash="✅ BOT @{$info['username']} ADDED".($days?" +{$days}D":'');Telegram::send($ownerId,"✅ <b>YOUR BOT WAS ADDED!</b>\n🤖 @{$info['username']}".($days?"\n📅 {$days} DAYS HOSTING":"\n⚠️ NO HOSTING DAYS YET — BUY A PLAN"));}else $flash="❌ {$res}";}else $flash='❌ TOKEN AND OWNER ID REQUIRED';break;
        case 'remove_bot': $b=BotManager::getBot((int)$_POST['bot_id']);[$ok,$r]=BotManager::removeBot((int)$_POST['bot_id'],(int)($b['owner_id']??0));$flash=($ok?'✅':'❌')." {$r}";break;
        case 'remove_days': [$ok,$r]=BotManager::removeDays((int)$_POST['bot_id'],(int)$_POST['days'],OWNER_ID);$flash=($ok?'✅':'❌')." {$r}";break;
        case 'start':  [$ok,$r]=BotManager::startBot((int)$_POST['bot_id']); $flash=($ok?'✅ ':'❌ ').$r;break;
        case 'stop':   [$ok,$r]=BotManager::stopBot((int)$_POST['bot_id']);  $flash=($ok?'✅ ':'❌ ').$r;break;
        case 'restart':[$ok,$r]=BotManager::restartBot((int)$_POST['bot_id']);$flash=($ok?'✅ ':'❌ ').$r;break;
        case 'ban_bot':Database::q("UPDATE hosted_bots SET status='banned' WHERE id=?",[(int)$_POST['bot_id']]);BotManager::stopBot((int)$_POST['bot_id']);$flash='✅ BOT BANNED';break;
        case 'pay_ok': $p=PaymentManager::approve((int)$_POST['pay_id'],OWNER_ID);if($p)Telegram::send((int)$p['user_id'],"✅ <b>PAYMENT APPROVED!</b>\n📅 ".strtoupper($p['plan'])." ({$p['days']} DAYS) ACTIVATED!");$flash='✅ PAYMENT APPROVED';break;
        case 'pay_rej':$p=PaymentManager::reject((int)$_POST['pay_id'],OWNER_ID);if($p)Telegram::send((int)$p['user_id'],"❌ PAYMENT REJECTED. CONTACT SUPPORT.");$flash='✅ PAYMENT REJECTED';break;
        case 'ban_user':   UserManager::ban((int)$_POST['uid']);  $flash='✅ BANNED';break;
        case 'unban_user': UserManager::unban((int)$_POST['uid']);$flash='✅ UNBANNED';break;
        case 'add_admin':
            $tgId=(int)$_POST['admin_tg_id'];
            if($tgId){[$ok,$msg]=UserManager::addAdmin($tgId,OWNER_ID,$_POST['admin_level']??'admin');if($ok)Telegram::send($tgId,"🎉 <b>YOU HAVE BEEN ADDED AS ADMIN!</b>\nSend /start to see your new menu.");$flash=($ok?'✅':'❌')." {$msg}";}break;
        case 'remove_admin':
            $tgId=(int)$_POST['admin_tg_id'];[$ok,$msg]=UserManager::removeAdmin($tgId,OWNER_ID);if($ok)try{Telegram::send($tgId,"ℹ️ YOUR ADMIN PRIVILEGES HAVE BEEN REMOVED.");}catch(\Exception $e){}$flash=($ok?'✅':'❌')." {$msg}";break;
        case 'gen_codes':$codes=RedeemManager::generate($_POST['plan']??'CUSTOM',(int)($_POST['days']??30),(int)($_POST['count']??5));$flash="✅ ".count($codes)." CODES:\n".implode(', ',$codes);break;
        case 'save_plan':
            $pid=(int)($_POST['pid']??0);$cols=[$_POST['pname']??'',(int)($_POST['pdays']??30),(float)($_POST['pprice']??0),(int)($_POST['pbots']??1),$_POST['pdesc']??'',(int)($_POST['pactive']??1)];
            if($pid) Database::q("UPDATE hosting_plans SET name=?,days=?,price=?,max_bots=?,description=?,is_active=? WHERE id=?",array_merge($cols,[$pid]));
            else Database::q("INSERT INTO hosting_plans(name,days,price,max_bots,description,is_active) VALUES(?,?,?,?,?,?)",$cols);
            $flash='✅ PLAN SAVED';break;
        case 'del_plan':Database::q("DELETE FROM hosting_plans WHERE id=?",[(int)$_POST['pid']]);$flash='✅ PLAN DELETED';break;
        case 'save_set':$k=preg_replace('/[^a-z_]/','',strtolower($_POST['skey']??''));if($k){Settings::set($k,trim($_POST['sval']??''));$flash="✅ UPDATED: {$k}";}break;
        case 'save_vps':
            $fields=['label','ip_address','cpu_info','ram_gb','disk_gb','os_info','location','status','notes'];
            $vals=[];foreach($fields as $f) $vals[$f]=$_POST['vps_'.$f]??'';
            Database::q("UPDATE vps_info SET label=?,ip_address=?,cpu_info=?,ram_gb=?,disk_gb=?,os_info=?,location=?,status=?,notes=?,updated_at=NOW() WHERE id=1",array_values($vals));
            $flash='✅ VPS INFO SAVED';break;
        case 'broadcast':$bmsg=trim($_POST['bmsg']??'');if($bmsg){$users=UserManager::all();$ok=$fail=0;foreach($users as $u){Telegram::send((int)$u['tg_id'],"📢 <b>ANNOUNCEMENT:</b>\n\n{$bmsg}")?$ok++:$fail++;usleep(50000);}$flash="📢 SENT:{$ok} FAILED:{$fail}";}break;
        case 'lock':   Settings::set('bot_locked','1');$flash='✅ BOT LOCKED';break;
        case 'unlock': Settings::set('bot_locked','0');$flash='✅ BOT UNLOCKED';break;
        case 'clear_log':@file_put_contents(LOGS_DIR.'/bot.log','');$flash='✅ LOG CLEARED';break;
        case 'send_file':
            $fid=(int)$_POST['file_id'];$tgId=(int)$_POST['target_tg_id'];
            $f=Database::one("SELECT * FROM user_files WHERE id=?",[$fid]);
            if($f&&file_exists($f['filepath'])){Telegram::sendDoc($tgId,$f['filepath'],"📁 ".htmlspecialchars($f['filename']));$flash='✅ FILE SENT';}else $flash='❌ FILE NOT FOUND';break;
    }
}

$allBots=BotManager::getAllBots(300);
$users=UserManager::all();
$pays=Database::all("SELECT p.*,u.username,u.first_name,b.bot_username FROM payments p LEFT JOIN users u ON u.tg_id=p.user_id LEFT JOIN hosted_bots b ON b.id=p.bot_id ORDER BY p.created_at DESC LIMIT 100");
$plans=PaymentManager::getPlans();
$codes=RedeemManager::listUnused();
$stats=BotManager::serverStats();
$admins=UserManager::getAllAdmins();
$vps=Database::one("SELECT * FROM vps_info WHERE id=1");
$files=Database::all("SELECT uf.*,u.username,u.first_name,hb.bot_username FROM user_files uf LEFT JOIN users u ON u.tg_id=uf.user_id LEFT JOIN hosted_bots hb ON hb.id=uf.bot_id ORDER BY uf.uploaded_at DESC LIMIT 100");

function f(string $t):string{return htmlspecialchars($t,ENT_QUOTES,'UTF-8');}
function btn(string $l,string $a,string $c='blue',string $cf=''):string{$cc=$cf?"onclick=\"return confirm('".addslashes($cf)."')\"":"";return "<button class='btn btn-{$c}' name='act' value='{$a}' {$cc}>{$l}</button>";}

$tabs=['dash'=>'📊 DASHBOARD','bots'=>'🤖 ALL BOTS','add_bot'=>'➕ ADD BOT','grant'=>'📅 GRANT DAYS',
       'users'=>'👥 USERS','admins'=>'👮 ADMINS','pays'=>'💳 PAYMENTS','plans'=>'📦 PLANS',
       'codes'=>'🔑 CODES','files'=>'📁 FILES','vps'=>'🖥 VPS INFO',
       'broadcast'=>'📢 BROADCAST','settings'=>'⚙️ SETTINGS','logs'=>'📋 LOGS'];
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BOT HOSTING ADMIN</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d1117;color:#e6edf3;font-family:'Segoe UI',sans-serif;font-size:13px}
a{color:inherit;text-decoration:none}
.layout{display:flex;min-height:100vh}
.sidebar{width:220px;background:#161b22;border-right:1px solid #21262d;position:fixed;top:0;bottom:0;overflow-y:auto;z-index:100}
.main{margin-left:220px;flex:1}
.brand{padding:18px 16px;border-bottom:1px solid #21262d}
.brand h2{color:#58a6ff;font-size:13px;letter-spacing:2px}
.brand p{color:#8b949e;font-size:11px;margin-top:3px}
nav a{display:flex;align-items:center;gap:8px;padding:9px 16px;color:#8b949e;font-size:12px;border-left:3px solid transparent;transition:.15s}
nav a:hover,nav a.on{color:#e6edf3;background:#21262d;border-left-color:#58a6ff}
.sep{height:1px;background:#21262d;margin:6px 0}
.topbar{display:flex;justify-content:space-between;align-items:center;padding:13px 22px;background:#161b22;border-bottom:1px solid #21262d;position:sticky;top:0;z-index:50}
.topbar h1{font-size:14px;letter-spacing:1px}
.topbar .r{display:flex;gap:14px;align-items:center;font-size:12px;color:#8b949e}
.content{padding:20px}
.flash{padding:10px 14px;border-radius:8px;margin-bottom:18px;font-size:12px;white-space:pre-wrap;word-break:break-all;line-height:1.6}
.flash.ok{background:#1a3a2a;border:1px solid #3fb950;color:#3fb950}
.flash.er{background:#3d1a1a;border:1px solid #f85149;color:#f85149}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:14px;margin-bottom:20px}
.card{background:#161b22;border:1px solid #21262d;border-radius:10px;padding:16px;text-align:center;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#58a6ff,#388bfd)}
.card .n{font-size:26px;font-weight:700;color:#58a6ff}
.card .l{font-size:10px;color:#8b949e;margin-top:4px;letter-spacing:1px}
.box{background:#161b22;border:1px solid #21262d;border-radius:10px;margin-bottom:20px;overflow:hidden}
.bh{display:flex;justify-content:space-between;align-items:center;padding:11px 16px;border-bottom:1px solid #21262d}
.bh h3{font-size:12px;letter-spacing:1px}
table{width:100%;border-collapse:collapse}
th{background:#21262d;padding:8px 12px;text-align:left;color:#8b949e;font-size:10px;letter-spacing:1px;font-weight:700}
td{padding:8px 12px;border-bottom:1px solid #21262d;font-size:12px;vertical-align:middle}
tr:last-child td{border-bottom:0}
tr:hover td{background:#1c2128}
.badge{display:inline-flex;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700}
.g{background:#1a3a2a;color:#3fb950}.r{background:#3d1a1a;color:#f85149}
.y{background:#3a2a0a;color:#d29922}.b{background:#0a1a3a;color:#58a6ff}.gr{background:#21262d;color:#8b949e}
.fr{display:flex;gap:10px;flex-wrap:wrap;padding:12px 16px;align-items:flex-end;border-bottom:1px solid #21262d}
.fr:last-child{border-bottom:0}
.fg{display:flex;flex-direction:column;gap:5px;flex:1;min-width:120px}
.fg label{font-size:10px;color:#8b949e;letter-spacing:1px;font-weight:700}
input,select,textarea{background:#0d1117;border:1px solid #30363d;border-radius:6px;color:#e6edf3;padding:7px 10px;font-size:12px;outline:none;width:100%;transition:.15s;font-family:inherit}
input:focus,select:focus,textarea:focus{border-color:#58a6ff}
textarea{resize:vertical;min-height:70px}
.btn{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border:none;border-radius:6px;cursor:pointer;font-size:11px;font-weight:700;letter-spacing:.5px;transition:.15s;white-space:nowrap}
.btn-blue{background:#1f6feb;color:#fff}.btn-green{background:#238636;color:#fff}
.btn-red{background:#da3633;color:#fff}.btn-orange{background:#b08800;color:#fff}
.btn-gray{background:#21262d;color:#e6edf3;border:1px solid #30363d}
.btn:hover{opacity:.85;transform:translateY(-1px)}
.sm{padding:3px 8px!important;font-size:10px!important}
.log{background:#0d1117;border:1px solid #21262d;border-radius:6px;padding:12px;font-family:monospace;font-size:11px;max-height:280px;overflow-y:auto;color:#7ee787;white-space:pre-wrap;word-break:break-all;line-height:1.5}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
code{background:#21262d;padding:1px 5px;border-radius:4px;font-family:monospace;font-size:11px}
@media(max-width:800px){.sidebar{display:none}.main{margin-left:0}.grid2{grid-template-columns:1fr}}
</style></head><body>
<div class="layout">
<div class="sidebar">
  <div class="brand"><h2>🤖 BOT HOSTING</h2><p>ADMIN PANEL v3</p></div>
  <nav>
    <?php foreach($tabs as $k=>$l) echo "<a href='?tab={$k}' class='".($tab===$k?'on':'')."'>{$l}</a>"; ?>
    <div class="sep"></div>
    <a href="?logout=1" style="color:#f85149">🚪 LOGOUT</a>
  </nav>
</div>
<div class="main">
<div class="topbar">
  <h1><?=f($tabs[$tab]??$tab)?></h1>
  <div class="r">
    <span style="color:<?=Settings::isBotLocked()?'#f85149':'#3fb950'?>"><?=Settings::isBotLocked()?'🔒 LOCKED':'🟢 ONLINE'?></span>
    <span><?=date('d M Y H:i')?></span>
    <a href="?logout=1" style="color:#f85149">LOGOUT</a>
  </div>
</div>
<div class="content">
<?php if($flash):$cls=str_starts_with($flash,'❌')?'er':'ok';?>
<div class="flash <?=$cls?>"><?=f($flash)?></div>
<?php endif;?>
<?php
switch($tab){
    case 'dash':    t_dash();    break;
    case 'bots':    t_bots();    break;
    case 'add_bot': t_addbot();  break;
    case 'grant':   t_grant();   break;
    case 'users':   t_users();   break;
    case 'admins':  t_admins();  break;
    case 'pays':    t_pays();    break;
    case 'plans':   t_plans();   break;
    case 'codes':   t_codes();   break;
    case 'files':   t_files();   break;
    case 'vps':     t_vps();     break;
    case 'broadcast':t_broadcast();break;
    case 'settings':t_settings();break;
    case 'logs':    t_logs();    break;
    default:        t_dash();
}
?>
</div></div></div>
<script>
function fillPlan(p){
    document.getElementById('pid').value=p.id;
    document.getElementById('pname').value=p.name;
    document.getElementById('pdays').value=p.days;
    document.getElementById('pprice').value=p.price;
    document.getElementById('pbots').value=p.max_bots;
    document.getElementById('pdesc').value=p.description||'';
    document.getElementById('pactive').value=p.is_active;
    document.getElementById('plan-title').textContent='EDIT PLAN #'+p.id;
    document.getElementById('plan-form').scrollIntoView({behavior:'smooth'});
}
</script></body></html>
<?php
function t_dash(){global $allBots,$users,$pays,$stats,$admins,$vps;}
function t_dash_content(){
    global $allBots,$users,$pays,$stats,$admins,$vps;
    $act=count(array_filter($allBots,fn($b)=>$b['status']==='active'));
    $pend=count(array_filter($pays,fn($p)=>$p['status']==='pending'));
    $exp=count(array_filter($allBots,fn($b)=>$b['status']==='expired'));
?>
<div class="cards">
  <div class="card"><div class="n"><?=count($users)?></div><div class="l">TOTAL USERS</div></div>
  <div class="card"><div class="n"><?=count($allBots)?></div><div class="l">HOSTED BOTS</div></div>
  <div class="card"><div class="n"><?=$act?></div><div class="l">ACTIVE BOTS</div></div>
  <div class="card"><div class="n"><?=$pend?></div><div class="l">PENDING PAYS</div></div>
  <div class="card"><div class="n"><?=count($admins)?></div><div class="l">ADMINS</div></div>
  <div class="card"><div class="n"><?=$stats['ram_pct']?>%</div><div class="l">RAM USAGE</div></div>
</div>
<div class="grid2">
<div class="box"><div class="bh"><h3>⚡ QUICK ACTIONS</h3></div>
<div class="fr"><form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
  <?=btn('🔒 LOCK','lock','red')?> <?=btn('🔓 UNLOCK','unlock','green')?>
  <a href="?tab=add_bot" class="btn btn-blue">➕ ADD BOT</a>
  <a href="?tab=grant"   class="btn btn-orange">📅 GRANT DAYS</a>
  <a href="?tab=pays"    class="btn btn-gray">💳 PAYMENTS</a>
  <a href="?tab=admins"  class="btn btn-gray">👮 ADMINS</a>
</form></div>
<div class="fr" style="flex-direction:column;gap:4px;font-size:12px;color:#8b949e">
  <div>⏱ UPTIME: <b style="color:#e6edf3"><?=$stats['uptime']?></b></div>
  <div>💾 RAM: <b style="color:#e6edf3"><?=$stats['ram_used']?>/<?=$stats['ram_tot']?>MB (<?=$stats['ram_pct']?>%)</b></div>
  <div>💿 DISK: <b style="color:#e6edf3"><?=$stats['disk']?></b></div>
  <?php if($vps): ?><div>🌐 VPS: <b style="color:#e6edf3"><?=f($vps['label']??'')?> — <?=f($vps['ip_address']??'N/A')?></b></div><?php endif;?>
</div></div>
<div class="box"><div class="bh"><h3>⏰ EXPIRING SOON</h3></div>
<?php $expiring=BotManager::getExpiringSoon(24);
if($expiring):?>
<table><tr><th>BOT</th><th>OWNER</th><th>EXPIRES</th><th>ACTION</th></tr>
<?php foreach($expiring as $b):?>
<tr>
  <td>@<?=f($b['bot_username']??'—')?></td>
  <td>@<?=f($b['username']??$b['owner_id'])?></td>
  <td style="color:#d29922"><?=date('d M H:i',strtotime($b['expires_at']))?></td>
  <td><form method="post" style="display:inline">
    <input type="hidden" name="bot_id" value="<?=$b['id']?>">
    <input type="hidden" name="days" value="30">
    <input type="hidden" name="reason" value="RENEWAL">
    <?=btn('+30D','grant_bot','green sm')?>
  </form></td>
</tr>
<?php endforeach;?></table>
<?php else:?><div style="padding:12px;color:#8b949e;font-size:12px">✅ NO BOTS EXPIRING IN 24H</div><?php endif;?>
</div></div>
<?php }
// Fix: t_dash was empty
function t_dash(){t_dash_content();}

function t_bots(){global $allBots;?>
<div class="box"><div class="bh"><h3>🤖 ALL BOTS (<?=count($allBots)?>)</h3><a href="?tab=add_bot" class="btn btn-blue sm">➕ ADD</a></div>
<table><tr><th>#</th><th>BOT</th><th>OWNER</th><th>STATUS</th><th>DAYS</th><th>EXPIRES</th><th>SCRIPT</th><th>ACTIONS</th></tr>
<?php foreach($allBots as $b):
  $days=BotManager::daysLeft($b);
  $sc=match($b['status']){'active'=>"<span class='badge g'>🟢</span>",'expired'=>"<span class='badge y'>⏰</span>",'banned'=>"<span class='badge r'>🚫</span>",default=>"<span class='badge gr'>🔴</span>"};
  $ds=$days>0&&$days<=3?' style="color:#d29922;font-weight:700"':'';
?>
<tr>
  <td><code>#<?=$b['id']?></code></td>
  <td>@<?=f($b['bot_username']??'—')?></td>
  <td>@<?=f($b['username']??'')?><br><code><?=$b['owner_id']?></code></td>
  <td><?=$sc?></td>
  <td<?=$ds?>><?=$days?>D</td>
  <td><?=$b['expires_at']?date('d M Y',strtotime($b['expires_at'])):'—'?></td>
  <td><code><?=f($b['script_file']??'—')?></code></td>
  <td style="white-space:nowrap">
    <form method="post" style="display:inline">
      <input type="hidden" name="bot_id" value="<?=$b['id']?>">
      <?=$b['status']==='active'?btn('STOP','stop','red sm'):btn('START','start','green sm')?>
      <?=btn('↺','restart','orange sm')?>
      <?=btn('🗑','remove_bot','red sm','REMOVE BOT? ALL DATA LOST!')?>
    </form>
  </td>
</tr>
<tr><td colspan="8" style="background:#0d1117;padding:6px 12px">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="bot_id" value="<?=$b['id']?>">
    <input type="number" name="days" placeholder="Days" style="width:70px">
    <input type="text" name="reason" placeholder="Reason" style="flex:1;min-width:80px">
    <?=btn('➕ GRANT','grant_bot','green sm')?> <?=btn('➖ REMOVE','remove_days','orange sm','REMOVE DAYS?')?>
  </form>
  <div style="margin-top:5px"><div class="log" style="max-height:60px;font-size:10px"><?=f(BotManager::getLog($b['id'],5))?></div></div>
</td></tr>
<?php endforeach;?></table></div>
<?php }

function t_addbot(){global $plans;?>
<div class="grid2">
<div class="box"><div class="bh"><h3>➕ ADD BOT BY TOKEN</h3></div>
<form method="post">
<div class="fr"><div class="fg"><label>BOT TOKEN *</label><input name="bot_token" placeholder="123456789:ABCdef..." required></div></div>
<div class="fr">
  <div class="fg"><label>OWNER USER ID *</label><input type="number" name="owner_id" placeholder="User must start bot first" required></div>
  <div class="fg"><label>HOSTING DAYS (0=NONE)</label><input type="number" name="days" value="0" min="0"></div>
</div>
<div class="fr"><div class="fg"><label>PLAN</label><select name="plan"><option value="admin">ADMIN ADDED</option><?php foreach($plans as $p) echo "<option value='".f($p['name'])."'>".strtoupper($p['name'])."</option>";?></select></div></div>
<div class="fr"><?=btn('➕ ADD BOT & NOTIFY USER','add_bot','blue')?></div>
</form></div>
<div class="box"><div class="bh"><h3>ℹ️ HOW TO ADD</h3></div>
<div style="padding:14px;font-size:12px;line-height:2;color:#8b949e">
  <p>1️⃣ USER MUST START YOUR BOT FIRST</p>
  <p>2️⃣ ENTER THEIR TELEGRAM USER ID</p>
  <p>3️⃣ ENTER BOT TOKEN FROM @BOTFATHER</p>
  <p>4️⃣ SET HOSTING DAYS (0 = NO DAYS)</p>
  <p>5️⃣ CLICK ADD — BOT VALIDATED, USER NOTIFIED</p>
  <br><p style="color:#d29922">⚠️ ONLY OWNER/ADMIN CAN SEE BOT MANAGEMENT IN TELEGRAM BOT</p>
  <p style="color:#d29922">⚠️ REGULAR USERS ONLY SEE: COMPILE, PAY, REDEEM, FILES, STATEMENT</p>
</div></div>
</div><?php }

function t_grant(){global $allBots;?>
<div class="grid2">
<div class="box"><div class="bh"><h3>📅 GRANT BY USER ID</h3></div>
<form method="post">
<div class="fr"><div class="fg"><label>TELEGRAM USER ID *</label><input type="number" name="user_tg_id" placeholder="123456789" required></div><div class="fg"><label>DAYS *</label><input type="number" name="days" placeholder="30" min="1" required></div></div>
<div class="fr"><div class="fg"><label>REASON</label><input name="reason" placeholder="Payment received"></div></div>
<div class="fr"><?=btn('🎁 GRANT TO ALL USER BOTS','grant_user','green')?></div>
<div style="padding:0 16px 10px;font-size:11px;color:#8b949e">GRANTS DAYS TO ALL BOTS + UPDATES PREMIUM STATUS</div>
</form></div>
<div class="box"><div class="bh"><h3>📅 GRANT BY BOT ID</h3></div>
<form method="post">
<div class="fr"><div class="fg"><label>SELECT BOT *</label><select name="bot_id">
<?php foreach($allBots as $b) echo "<option value='{$b['id']}'>#".f($b['id'])." @".f($b['bot_username']??'—')." [".BotManager::daysLeft($b)."D] @".f($b['username']??$b['owner_id'])."</option>";?>
</select></div></div>
<div class="fr"><div class="fg"><label>DAYS *</label><input type="number" name="days" placeholder="30" min="1" required></div><div class="fg"><label>REASON</label><input name="reason" placeholder="Admin grant"></div></div>
<div class="fr"><?=btn('➕ GRANT','grant_bot','green')?> <?=btn('➖ REMOVE','remove_days','orange','REMOVE DAYS?')?></div>
</form></div></div>
<div class="box"><div class="bh"><h3>📋 RECENT GRANTS</h3></div>
<table><tr><th>DATE</th><th>ADMIN</th><th>USER</th><th>BOT</th><th>DAYS</th><th>REASON</th></tr>
<?php foreach(Database::all("SELECT * FROM day_grants ORDER BY granted_at DESC LIMIT 30") as $g):?>
<tr><td><?=date('d M Y H:i',strtotime($g['granted_at']))?></td><td><code><?=$g['admin_id']?></code></td><td><code><?=$g['target_id']?></code></td><td><?=$g['bot_id']??'ALL'?></td><td style="color:#3fb950;font-weight:700">+<?=$g['days']?>D</td><td><?=f($g['reason']??'—')?></td></tr>
<?php endforeach;?></table></div>
<?php }

function t_users(){global $users;?>
<div class="box"><div class="bh"><h3>👥 USERS (<?=count($users)?>)</h3></div>
<table><tr><th>TG ID</th><th>NAME</th><th>USERNAME</th><th>PLAN</th><th>PREMIUM UNTIL</th><th>ROLE</th><th>JOINED</th><th>ACTION</th></tr>
<?php foreach($users as $u):
  $badge=(int)$u['is_banned']?"<span class='badge r'>🚫 BANNED</span>":((int)$u['is_owner']?"<span class='badge b'>👑 OWNER</span>":(!empty($u['admin_level'])?"<span class='badge b'>👮 ADMIN</span>":((int)$u['is_premium']?"<span class='badge g'>💎 PREM</span>":"<span class='badge gr'>👤 FREE</span>")));?>
<tr>
  <td><code><?=$u['tg_id']?></code></td>
  <td><?=f(strtoupper($u['first_name']??'—'))?></td>
  <td>@<?=f($u['username']??'—')?></td>
  <td><?=strtoupper($u['plan']??'FREE')?></td>
  <td><?=$u['premium_until']?date('d M Y',strtotime($u['premium_until'])):'—'?></td>
  <td><?=$badge?></td>
  <td><?=date('d M Y',strtotime($u['joined_at']))?></td>
  <td><form method="post" style="display:inline">
    <input type="hidden" name="uid" value="<?=$u['tg_id']?>">
    <?=(int)$u['is_banned']?btn('UNBAN','unban_user','green sm'):btn('BAN','ban_user','red sm','BAN USER?')?>
  </form></td>
</tr>
<?php endforeach;?></table></div>
<?php }

function t_admins(){global $admins;?>
<div class="grid2">
<div class="box"><div class="bh"><h3>👮 ADMINS (<?=count($admins)?>)</h3></div>
<table><tr><th>TG ID</th><th>NAME</th><th>USERNAME</th><th>LEVEL</th><th>ADDED</th><th>ACTION</th></tr>
<?php foreach($admins as $a):?>
<tr>
  <td><code><?=$a['tg_id']?></code></td>
  <td><?=f(strtoupper($a['first_name']??'—'))?></td>
  <td>@<?=f($a['username']??'—')?></td>
  <td><span class="badge b"><?=strtoupper($a['level']??'admin')?></span></td>
  <td><?=date('d M Y',strtotime($a['added_at']))?></td>
  <td><form method="post" style="display:inline">
    <input type="hidden" name="admin_tg_id" value="<?=$a['tg_id']?>">
    <?=btn('🗑 REMOVE','remove_admin','red sm','REMOVE ADMIN?')?>
  </form></td>
</tr>
<?php endforeach;?>
<?php if(!$admins):?><tr><td colspan="6" style="text-align:center;color:#8b949e;padding:20px">NO ADMINS ADDED YET</td></tr><?php endif;?>
</table></div>
<div class="box"><div class="bh"><h3>➕ ADD ADMIN</h3></div>
<form method="post">
<div class="fr"><div class="fg"><label>TELEGRAM USER ID *</label><input type="number" name="admin_tg_id" placeholder="User must have started bot" required></div></div>
<div class="fr"><div class="fg"><label>ADMIN LEVEL</label><select name="admin_level"><option value="admin">ADMIN</option><option value="moderator">MODERATOR</option></select></div></div>
<div class="fr"><?=btn('➕ ADD AS ADMIN','add_admin','blue')?></div>
<div style="padding:0 16px 10px;font-size:11px;color:#8b949e">
  ⚠️ USER MUST HAVE STARTED THE BOT FIRST<br>
  ADMINS CAN MANAGE BOTS, USERS, PAYMENTS<br>
  ONLY OWNER CAN ADD/REMOVE ADMINS
</div>
</form></div></div>
<?php }

function t_pays(){global $pays;?>
<div class="box"><div class="bh"><h3>💳 PAYMENTS (<?=count($pays)?>)</h3></div>
<table><tr><th>DATE</th><th>USER</th><th>BOT</th><th>PLAN</th><th>DAYS</th><th>AMOUNT</th><th>UTR</th><th>STATUS</th><th>ACTION</th></tr>
<?php foreach($pays as $p):
  $sc=match($p['status']){'approved'=>"<span class='badge g'>✅ OK</span>",'rejected'=>"<span class='badge r'>❌ REJ</span>",default=>"<span class='badge y'>⏳ PEND</span>"};?>
<tr>
  <td><?=date('d M H:i',strtotime($p['created_at']))?></td>
  <td><?=f(strtoupper($p['first_name']??$p['user_id']))?><br><code><?=$p['user_id']?></code></td>
  <td><?=$p['bot_username']?'@'.f($p['bot_username']):'—'?></td>
  <td><?=strtoupper($p['plan'])?></td>
  <td><?=$p['days']?>D</td>
  <td><b>₹<?=$p['amount']?></b></td>
  <td><code><?=f($p['utr']??'—')?></code></td>
  <td><?=$sc?></td>
  <td><?php if($p['status']==='pending'):?><form method="post" style="display:inline"><input type="hidden" name="pay_id" value="<?=$p['id']?>"><?=btn('✅','pay_ok','green sm')?> <?=btn('❌','pay_rej','red sm')?></form><?php endif;?></td>
</tr>
<?php endforeach;?></table></div>
<?php }

function t_plans(){global $plans;?>
<div class="grid2">
<div class="box"><div class="bh"><h3>📦 PLANS (<?=count($plans)?>)</h3></div>
<table><tr><th>ID</th><th>NAME</th><th>DAYS</th><th>PRICE</th><th>BOTS</th><th>ACTIVE</th><th>EDIT</th></tr>
<?php foreach($plans as $p):?>
<tr>
  <td>#<?=$p['id']?></td>
  <td><b><?=strtoupper($p['name'])?></b></td>
  <td><?=$p['days']?>D</td>
  <td>₹<?=$p['price']?></td>
  <td><?=$p['max_bots']?></td>
  <td><?=(int)$p['is_active']?"<span class='badge g'>ON</span>":"<span class='badge gr'>OFF</span>"?></td>
  <td>
    <button onclick="fillPlan(<?=htmlspecialchars(json_encode($p),ENT_QUOTES)?>)" class="btn btn-blue sm">EDIT</button>
    <form method="post" style="display:inline"><input type="hidden" name="pid" value="<?=$p['id']?>"><?=btn('🗑','del_plan','red sm','DELETE PLAN?')?></form>
  </td>
</tr>
<?php endforeach;?></table></div>
<div class="box" id="plan-form"><div class="bh"><h3 id="plan-title">➕ ADD PLAN</h3></div>
<form method="post">
<div class="fr"><input type="hidden" name="pid" id="pid" value="0">
  <div class="fg"><label>NAME *</label><input id="pname" name="pname" placeholder="STARTER" required></div>
  <div class="fg"><label>DAYS *</label><input id="pdays" type="number" name="pdays" placeholder="30" required></div>
</div>
<div class="fr">
  <div class="fg"><label>PRICE ₹</label><input id="pprice" type="number" name="pprice" placeholder="149" step="0.01"></div>
  <div class="fg"><label>MAX BOTS</label><input id="pbots" type="number" name="pbots" placeholder="1" min="1"></div>
</div>
<div class="fr">
  <div class="fg"><label>DESCRIPTION</label><input id="pdesc" name="pdesc" placeholder="Short description"></div>
  <div class="fg"><label>ACTIVE</label><select id="pactive" name="pactive"><option value="1">YES</option><option value="0">NO</option></select></div>
</div>
<div class="fr"><?=btn('💾 SAVE PLAN','save_plan','blue')?></div>
</form></div></div>
<?php }

function t_codes(){global $codes;?>
<div class="grid2">
<div class="box"><div class="bh"><h3>🔑 GENERATE CODES</h3></div>
<form method="post">
<div class="fr"><div class="fg"><label>PLAN NAME</label><input name="plan" value="CUSTOM"></div><div class="fg"><label>DAYS</label><input type="number" name="days" value="30" min="1"></div><div class="fg"><label>COUNT (MAX 50)</label><input type="number" name="count" value="5" min="1" max="50"></div></div>
<div class="fr"><?=btn('🔑 GENERATE','gen_codes','blue')?></div>
</form></div>
<div class="box"><div class="bh"><h3>📋 UNUSED CODES (<?=count($codes)?>)</h3></div>
<table><tr><th>CODE</th><th>PLAN</th><th>DAYS</th><th>EXPIRES</th></tr>
<?php foreach($codes as $c):?><tr><td><code><?=f($c['code'])?></code></td><td><?=strtoupper($c['plan'])?></td><td><?=$c['days']?>D</td><td><?=$c['expires_at']?date('d M Y',strtotime($c['expires_at'])):'—'?></td></tr><?php endforeach;?>
</table></div></div>
<?php }

function t_files(){global $files;?>
<div class="box"><div class="bh"><h3>📁 ALL FILES (<?=count($files)?>)</h3></div>
<table><tr><th>DATE</th><th>USER</th><th>BOT</th><th>FILENAME</th><th>SIZE</th><th>EXISTS</th><th>ACTION</th></tr>
<?php foreach($files as $f):
  $exists=file_exists($f['filepath']);
  $sz=number_format($f['filesize']/1024,1);
?>
<tr>
  <td><?=date('d M Y H:i',strtotime($f['uploaded_at']))?></td>
  <td>@<?=f($f['username']??$f['user_id'])?><br><code><?=$f['user_id']?></code></td>
  <td><?=$f['bot_username']?'@'.f($f['bot_username']):'—'?></td>
  <td><code><?=f($f['filename'])?></code></td>
  <td><?=$sz?>KB</td>
  <td><?=$exists?"<span class='badge g'>✅ YES</span>":"<span class='badge r'>❌ NO</span>"?></td>
  <td><?php if($exists):?>
    <form method="post" style="display:inline">
      <input type="hidden" name="file_id" value="<?=$f['id']?>">
      <div class="fg" style="min-width:0"><input type="number" name="target_tg_id" placeholder="User TG ID" style="width:130px;margin-bottom:4px"></div>
      <?=btn('📤 SEND','send_file','blue sm')?>
    </form>
  <?php endif;?></td>
</tr>
<?php endforeach;?></table></div>
<?php }

function t_vps(){global $vps,$stats;
$s=BotManager::getVpsStatement();?>
<div class="grid2">
<div class="box"><div class="bh"><h3>📊 VPS STATEMENT</h3></div>
<div style="padding:14px;font-size:12px;white-space:pre-wrap;line-height:1.6;font-family:monospace;color:#e6edf3"><?=f($s)?></div>
</div>
<div class="box"><div class="bh"><h3>✏️ EDIT VPS INFO</h3></div>
<form method="post">
<?php
$vpsFields=['label'=>'LABEL','ip_address'=>'IP ADDRESS','cpu_info'=>'CPU INFO','ram_gb'=>'RAM (GB)','disk_gb'=>'DISK (GB)','os_info'=>'OS INFO','location'=>'LOCATION','status'=>'STATUS','notes'=>'NOTES'];
foreach($vpsFields as $k=>$l):
    $val=f($vps[$k]??'');
    if($k==='status'):?>
<div class="fr"><div class="fg"><label><?=$l?></label>
  <select name="vps_status"><option value="online" <?=$val==='online'?'selected':''?>>ONLINE</option><option value="offline" <?=$val==='offline'?'selected':''?>>OFFLINE</option><option value="maintenance" <?=$val==='maintenance'?'selected':''?>>MAINTENANCE</option></select>
</div></div>
    <?php elseif($k==='notes'):?>
<div class="fr"><div class="fg"><label><?=$l?></label><textarea name="vps_<?=$k?>" rows="3"><?=$val?></textarea></div></div>
    <?php else:?>
<div class="fr"><div class="fg"><label><?=$l?></label><input name="vps_<?=$k?>" value="<?=$val?>" placeholder="<?=$l?>"></div></div>
    <?php endif;
endforeach;?>
<div class="fr"><?=btn('💾 SAVE VPS INFO','save_vps','blue')?></div>
</form></div></div>
<?php }

function t_broadcast(){?>
<div class="box"><div class="bh"><h3>📢 BROADCAST</h3></div>
<form method="post">
<div class="fr"><div class="fg"><label>MESSAGE (HTML OK)</label><textarea name="bmsg" rows="5" placeholder="Your announcement..."></textarea></div></div>
<div class="fr"><?=btn('📢 SEND TO ALL USERS','broadcast','blue','BROADCAST TO ALL?')?></div>
</form></div>
<div class="box"><div class="bh"><h3>📋 HISTORY</h3></div>
<table><tr><th>DATE</th><th>SENT</th><th>FAILED</th><th>PREVIEW</th></tr>
<?php foreach(Database::all("SELECT * FROM broadcast_log ORDER BY created_at DESC LIMIT 20") as $b):?>
<tr><td><?=date('d M Y H:i',strtotime($b['created_at']))?></td><td style="color:#3fb950"><?=$b['sent_count']?></td><td style="color:#f85149"><?=$b['fail_count']?></td><td><?=f(mb_strimwidth($b['message']??'',0,60,'...'))?></td></tr>
<?php endforeach;?></table></div>
<?php }

function t_settings(){
    $keys=['upi_id'=>'UPI ID','upi_name'=>'UPI NAME','support_username'=>'SUPPORT USERNAME',
           'max_runtime'=>'MAX RUNTIME (SEC)','trial_days'=>'TRIAL DAYS','qr_api'=>'QR API URL'];?>
<div class="grid2">
<div class="box"><div class="bh"><h3>⚙️ SETTINGS</h3></div>
<?php foreach($keys as $k=>$l):$v=Settings::get($k,'');?>
<form method="post">
<div class="fr"><div class="fg"><label><?=$l?></label><input name="sval" value="<?=f($v)?>" placeholder="<?=f($k)?>"><input type="hidden" name="skey" value="<?=$k?>"></div>
<div style="display:flex;align-items:flex-end"><?=btn('SAVE','save_set','blue sm')?></div></div>
</form>
<?php endforeach;?>
</div>
<div class="box"><div class="bh"><h3>🔒 BOT STATUS</h3></div>
<div class="fr" style="flex-direction:column;gap:8px">
<div style="font-size:12px;color:#8b949e">STATUS: <strong style="color:<?=Settings::isBotLocked()?'#f85149':'#3fb950'?>"><?=Settings::isBotLocked()?'🔒 LOCKED':'🟢 ONLINE'?></strong></div>
<form method="post" style="display:flex;gap:10px"><?=btn('🔒 LOCK','lock','red')?> <?=btn('🔓 UNLOCK','unlock','green')?></form>
</div></div></div>
<?php }

function t_logs(){$lf=LOGS_DIR.'/bot.log';$lines=file_exists($lf)?array_reverse(array_slice(file($lf,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES),-150)):[];?>
<div class="box"><div class="bh">
  <h3>📋 BOT LOGS (LAST 150)</h3>
  <form method="post" style="display:inline"><?=btn('CLEAR','clear_log','red sm','CLEAR LOG?')?></form>
</div>
<div style="padding:12px"><div class="log"><?php
  foreach($lines as $line){
    $l=f($line);
    if(str_contains($l,'ERROR'))$l="<span style='color:#f85149'>{$l}</span>";
    elseif(str_contains($l,'WARN'))$l="<span style='color:#d29922'>{$l}</span>";
    elseif(str_contains($l,'INFO'))$l="<span style='color:#58a6ff'>{$l}</span>";
    echo $l."\n";
  }
  if(!$lines) echo 'NO LOGS YET.';
?></div></div></div>
<?php }

function login_page(string $err):void{?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>ADMIN LOGIN</title>
<style>*{box-sizing:border-box;margin:0;padding:0}body{background:linear-gradient(135deg,#0d1117,#161b22);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',monospace}
.box{background:#161b22;border:1px solid #30363d;border-radius:16px;padding:44px 36px;width:360px}
h1{text-align:center;color:#58a6ff;font-size:18px;letter-spacing:2px;margin-bottom:6px}
.sub{text-align:center;color:#8b949e;font-size:11px;margin-bottom:26px}
label{display:block;color:#8b949e;font-size:11px;margin-bottom:5px;letter-spacing:1px}
input{width:100%;padding:11px 12px;margin-bottom:14px;background:#0d1117;border:1px solid #30363d;border-radius:7px;color:#e6edf3;font-size:13px;outline:none}
input:focus{border-color:#58a6ff}
button{width:100%;padding:12px;background:linear-gradient(135deg,#1f6feb,#388bfd);border:none;border-radius:7px;color:#fff;font-size:14px;font-weight:700;cursor:pointer}
.err{background:#3d1a1a;border:1px solid #f85149;color:#f85149;padding:9px;border-radius:7px;margin-bottom:14px;font-size:12px;text-align:center}
</style></head><body>
<div class="box">
  <h1>🤖 BOT HOSTING</h1><p class="sub">ADMIN CONTROL PANEL</p>
  <?php if($err) echo "<div class='err'>❌ ".htmlspecialchars($err)."</div>";?>
  <form method="post"><label>USERNAME</label><input type="text" name="au" required autocomplete="off"><label>PASSWORD</label><input type="password" name="ap" required><button type="submit" name="do_login">LOGIN →</button></form>
</div></body></html>
<?php }
