<?php
// api.php — REST API  Auth: ?key=OWNER_ID or header X-API-Key
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/bot_manager.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function resp(bool $ok, mixed $data=null, string $msg=''): never {
    echo json_encode(['ok'=>$ok,'data'=>$data,'msg'=>$msg,'ts'=>time()]); exit;
}

$key = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
if ((int)$key !== OWNER_ID && $key !== BOT_TOKEN) resp(false,null,'UNAUTHORIZED');

try { install_schema(); } catch (\Exception $e) {}
$act  = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'),true) ?? [];

switch ($act) {
    case 'stats':
        resp(true,[
            'users'   => UserManager::count(),
            'bots'    => Database::cnt('hosted_bots'),
            'active'  => Database::cnt('hosted_bots','status=?',['active']),
            'expired' => Database::cnt('hosted_bots','status=?',['expired']),
            'pending_pays' => Database::cnt('payments','status=?',['pending']),
            'admins'  => Database::cnt('admins','is_active=1'),
            'server'  => BotManager::serverStats(),
        ]);

    case 'list_bots':
        $uid=(int)($_GET['user_id']??0);
        resp(true,$uid?BotManager::getUserBots($uid):BotManager::getAllBots(500));

    case 'bot_info':
        $b=BotManager::getBot((int)($_GET['bot_id']??0));
        if(!$b) resp(false,null,'NOT FOUND');
        $b['days_left']=BotManager::daysLeft($b); resp(true,$b);

    case 'user_stmt':
        resp(true,['statement'=>BotManager::getUserStatement((int)($_GET['user_id']??0))]);

    case 'bot_stmt':
        resp(true,['statement'=>BotManager::getBotStatement((int)($_GET['bot_id']??0))]);

    case 'vps_stmt':
        resp(true,['statement'=>BotManager::getVpsStatement()]);

    case 'grant_days':
        $botId=(int)($body['bot_id']??$_GET['bot_id']??0);
        $uid=(int)($body['user_id']??$_GET['user_id']??0);
        $days=(int)($body['days']??$_GET['days']??0);
        if ($days<=0) resp(false,null,'DAYS > 0 REQUIRED');
        if ($botId) { [$ok,$exp]=BotManager::grantDays($botId,$days,OWNER_ID,$body['reason']??'API'); resp($ok,['expires'=>$exp]); }
        if ($uid)   { [$ok,$cnt]=BotManager::grantDaysByUser($uid,$days,OWNER_ID,$body['reason']??'API'); resp($ok,['bots_updated'=>$cnt]); }
        resp(false,null,'bot_id OR user_id REQUIRED');

    case 'add_bot':
        $token=trim($body['token']??$_GET['token']??'');
        $ownerId=(int)($body['owner_id']??$_GET['owner_id']??0);
        $days=(int)($body['days']??$_GET['days']??0);
        if (!$token||!$ownerId) resp(false,null,'token AND owner_id REQUIRED');
        [$ok,$res,$info]=BotManager::addBot($ownerId,$token,'api')+[2=>null];
        if (!$ok) resp(false,null,$res);
        if ($days>0) BotManager::grantDays((int)$res,$days,OWNER_ID,'API ADD');
        resp(true,['bot_id'=>$res,'info'=>$info]);

    case 'add_admin':
        $tgId=(int)($body['tg_id']??$_GET['tg_id']??0);
        $level=$body['level']??'admin';
        if (!$tgId) resp(false,null,'tg_id REQUIRED');
        [$ok,$msg]=UserManager::addAdmin($tgId,OWNER_ID,$level);
        resp($ok,null,$msg);

    case 'remove_admin':
        $tgId=(int)($body['tg_id']??$_GET['tg_id']??0);
        [$ok,$msg]=UserManager::removeAdmin($tgId,OWNER_ID);
        resp($ok,null,$msg);

    case 'list_admins':
        resp(true,UserManager::getAllAdmins());

    case 'start': [$ok,$r]=BotManager::startBot((int)($_GET['bot_id']??0)); resp($ok,null,$r);
    case 'stop':  [$ok,$r]=BotManager::stopBot((int)($_GET['bot_id']??0));  resp($ok,null,$r);
    case 'log':   resp(true,['log'=>BotManager::getLog((int)($_GET['bot_id']??0),(int)($_GET['lines']??50))]);
    case 'users': resp(true,UserManager::all());
    case 'ban':   UserManager::ban((int)($_GET['uid']??0));   resp(true,null,'BANNED');
    case 'unban': UserManager::unban((int)($_GET['uid']??0)); resp(true,null,'UNBANNED');
    case 'genkey':
        $codes=RedeemManager::generate($_GET['plan']??'custom',(int)($_GET['days']??30),(int)($_GET['count']??1));
        resp(true,['codes'=>$codes]);

    case 'list_files':
        $uid=(int)($_GET['user_id']??0);
        $files=$uid?BotManager::getUserFiles($uid):Database::all("SELECT * FROM user_files ORDER BY uploaded_at DESC LIMIT 100");
        resp(true,$files);

    default:
        resp(false,null,'UNKNOWN ACTION. VALID: stats,list_bots,bot_info,user_stmt,bot_stmt,vps_stmt,grant_days,add_bot,add_admin,remove_admin,list_admins,start,stop,log,users,ban,unban,genkey,list_files');
}
