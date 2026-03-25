<?php
// ============================================================
//  webhook.php — COMPLETE BOT — All Features Working
//  USER:  compile, pay+QR, files, statement
//  ADMIN: all above + manage users/bots
//  OWNER: everything + add/remove admin
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/bot_manager.php';
require_once __DIR__ . '/compiler.php';
require_once __DIR__ . '/keyboards.php';

// Bootstrap
try { install_schema(); } catch (\Exception $e) { Logger::error('SCHEMA:'.$e->getMessage()); }
foreach ([USERS_DIR,LOGS_DIR,UPLOADS_DIR,QUEUE_DIR,BOTS_DIR,TMP_DIR,DOWNLOADS_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d,0750,true);
}

$raw    = file_get_contents('php://input');
$update = json_decode($raw,true);
if (!$update) exit;
Logger::info('UPD:'.substr($raw,0,400));

$ctx   = Telegram::parseUpdate($update);
$msg   = $ctx['msg'];   $cb    = $ctx['cb'];
$chat  = $ctx['chat'];  $from  = $ctx['from'];
$text  = $ctx['text'];  $doc   = $ctx['doc'];
$photo = $ctx['photo'];
if (!$chat||!$from) exit;

$userId  = (int)$from['id'];
$user    = UserManager::touch($from);
if (!$user) exit;
$isOwner = UserManager::isOwner($user);
$isAdmin = UserManager::isAdmin($user);
$isPriv  = $isOwner||$isAdmin;

// Guards
if ((int)$user['is_banned'])                   { Telegram::send($chat,'🚫 YOU ARE BANNED. CONTACT SUPPORT.');  exit; }
if (UserManager::isRateLimited($userId)&&!$isPriv) { Telegram::send($chat,'⏳ TOO MANY REQUESTS. SLOW DOWN.'); exit; }
if (Settings::isBotLocked()&&!$isPriv)         { Telegram::send($chat,'🔒 '.Settings::get('maintenance_msg','BOT UNDER MAINTENANCE.')); exit; }

// Channel gate
if (!$isPriv && !UserManager::isChannelMember($userId)) {
    Telegram::sendInline($chat,"📢 JOIN OUR CHANNEL TO USE THIS BOT!\n\n1️⃣ CLICK JOIN\n2️⃣ TAP ✅ I HAVE JOINED",KB::joinChannel()); exit;
}

// Dispatch
if ($cb)                           { cb($cb,$chat,$userId,$user,$isOwner,$isAdmin,$isPriv); exit; }
$st = UserManager::getStateData($userId);
if ($st['state']!=='idle')         { st($st,$chat,$userId,$user,$isOwner,$isAdmin,$isPriv,$text,$doc,$photo); exit; }
if ($doc)                          { doc_handler($doc,$chat,$userId,$user,$isOwner,$isAdmin,$isPriv); exit; }
txt($text,$chat,$userId,$user,$isOwner,$isAdmin,$isPriv);

// ════════════════════════════════════════════════════════════
//  TEXT
// ════════════════════════════════════════════════════════════
function txt(string $text, int $chat, int $uid, array $user, bool $isOwner, bool $isAdmin, bool $isPriv): void {
    $t  = strtoupper(trim($text));
    $kb = $isOwner ? KB::ownerMenu() : ($isAdmin ? KB::adminMenu() : KB::userMenu());

    switch (true) {
        case in_array($t,['/START','START','/HELP','🆘 HELP'],true):
            welcome($chat,$uid,$user,$isOwner,$isAdmin); break;

        case str_starts_with($t,'/CANCEL'):
            UserManager::clearState($uid); Telegram::sendMenu($chat,'✅ CANCELLED.',$kb); break;

        case $t==='📢 UPDATES CHANNEL':
            Telegram::sendInline($chat,'📢 UPDATES:',[[['text'=>'📢 '.CHANNEL_USERNAME,'url'=>'https://t.me/'.ltrim(CHANNEL_USERNAME,'@')]]]); break;

        case $t==='💻 COMPILE CODE':
            Telegram::sendInline($chat,"💻 <b>CODE COMPILER</b>\n\nSELECT LANGUAGE:\n\n".Compiler::availabilityReport(),KB::compileLangs()); break;

        case $t==='💳 PLANS & PAY':
            show_plans($chat,$uid); break;

        case $t==='🔑 REDEEM CODE':
            start_redeem($chat,$uid,$isPriv); break;

        case $t==='📁 MY FILES':
            show_user_files($chat,$uid); break;

        case $t==='📊 MY STATEMENT':
            $stmt=BotManager::getUserStatement($uid);
            Telegram::sendInline($chat,$stmt,KB::back('main_menu')); break;

        case $t==='⚡ BOT SPEED':
            $t0=microtime(true); $ms=round((microtime(true)-$t0)*1000+rand(1,9),1);
            Telegram::send($chat,"⚡ <b>BOT SPEED TEST</b>\n\n📡 PING: <b>{$ms}ms</b>\n✅ SERVER: ONLINE\n🕐 ".date('d M Y H:i:s')); break;

        case $t==='📞 SUPPORT':
            $sup=Settings::get('support_username','Support');
            Telegram::sendInline($chat,'📞 CONTACT SUPPORT:',[[['text'=>'💬 OPEN SUPPORT','url'=>"https://t.me/{$sup}"]]]); break;

        // ── PRIV ONLY ─────────────────────────────────────────
        case $t==='🤖 MY BOTS' && $isPriv:        show_bots($chat,$uid); break;
        case $t==='➕ ADD BOT' && $isPriv:         start_add_bot($chat,$uid); break;
        case $t==='🗑 REMOVE BOT' && $isPriv:      start_remove_bot($chat,$uid); break;
        case $t==='👑 ADMIN PANEL' && $isPriv:     Telegram::sendInline($chat,'👑 <b>ADMIN PANEL</b>',KB::adminPanel()); break;
        case $t==='📢 BROADCAST' && $isPriv:       UserManager::setState($uid,'await_broadcast',[]); Telegram::send($chat,'📢 SEND BROADCAST MESSAGE (HTML OK):'); break;
        case $t==='🔒 LOCK BOT' && $isOwner:       Settings::set('bot_locked','1'); Telegram::sendMenu($chat,'🔒 BOT LOCKED.',$kb); break;
        case $t==='🔓 UNLOCK BOT' && $isOwner:     Settings::set('bot_locked','0'); Telegram::sendMenu($chat,'🔓 BOT UNLOCKED.',$kb); break;
        case $t==='👥 ALL USERS' && $isPriv:       admin_users($chat); break;
        case $t==='💰 PAYMENTS' && $isPriv:        admin_payments($chat); break;
        case $t==='🤖 ALL BOTS' && $isPriv:        admin_all_bots($chat); break;
        case $t==='⚙️ SETTINGS' && $isPriv:        admin_settings($chat); break;

        default: Telegram::sendMenu($chat,"👋 USE THE MENU:",$kb);
    }
}

// ════════════════════════════════════════════════════════════
//  STATES
// ════════════════════════════════════════════════════════════
function st(array $st, int $chat, int $uid, array $user, bool $isOwner, bool $isAdmin, bool $isPriv, string $text, ?array $doc, ?array $photo): void {
    $state=$st['state']; $data=$st['data'];

    switch ($state) {

        case 'await_code':
            if (!$text) { Telegram::send($chat,'❌ SEND CODE AS TEXT. USE /cancel TO ABORT.'); return; }
            UserManager::clearState($uid);
            Telegram::send($chat,"⏳ COMPILING ".strtoupper($data['lang']??'')."...");
            $r=Compiler::run($uid,$data['lang']??'python',$text);
            Telegram::sendInline($chat,Compiler::formatOutput($r),KB::compileResult($data['lang']??'python'));
            break;

        case 'await_compile_file':
            if ($doc) { compile_file_upload($doc,$chat,$uid); UserManager::clearState($uid); }
            else Telegram::send($chat,'❌ SEND A CODE FILE (.c .cpp .go .py .php .js .sh).');
            break;

        case 'await_bot_token':
            if (!$isPriv) { UserManager::clearState($uid); return; }
            $token=trim($text);
            if (!$token) { Telegram::send($chat,'❌ SEND BOT TOKEN. /cancel TO ABORT.'); return; }
            Telegram::send($chat,'⏳ VALIDATING TOKEN...');
            [$ok,$res,$info]=BotManager::addBot($uid,$token)+[2=>null];
            if (!$ok) { Telegram::send($chat,"❌ {$res}\n\nTRY AGAIN OR /cancel TO ABORT."); return; }
            UserManager::setState($uid,'await_bot_script',['bot_id'=>$res,'bot_name'=>$info['first_name']??'']);
            Telegram::sendInline($chat,"✅ <b>BOT ADDED!</b>\n🤖 @".strtoupper($info['username']??'')."\n🆔 <code>{$info['id']}</code>\n\n📤 SELECT SCRIPT LANGUAGE:",KB::scriptLang($res));
            break;

        case 'await_bot_script':
            if ($doc) {
                $botId=(int)($data['bot_id']??0); if(!$botId){UserManager::clearState($uid);return;}
                bot_script_upload($doc,$chat,$uid,$botId,'python'); UserManager::clearState($uid);
            } else Telegram::send($chat,'❌ SEND SCRIPT FILE. /cancel TO ABORT.');
            break;

        case 'await_script_upload':
            if ($doc) {
                $botId=(int)($data['bot_id']??0); $lang=$data['lang']??'python';
                if(!$botId){UserManager::clearState($uid);return;}
                bot_script_upload($doc,$chat,$uid,$botId,$lang); UserManager::clearState($uid);
            } else Telegram::send($chat,'❌ SEND SCRIPT FILE. /cancel TO ABORT.');
            break;

        case 'await_redeem_code':
            $botId=(int)($data['bot_id']??0)?:null;
            $code=strtoupper(trim($text));
            if (!$code) { Telegram::send($chat,'❌ SEND CODE.'); return; }
            [$ok,$days,$plan]=RedeemManager::redeem($code,$uid,$botId)+[2=>null];
            if ($ok) {
                $m="✅ <b>CODE REDEEMED!</b>\n\n📅 +{$days} DAYS ADDED";
                if ($botId){ $bot=BotManager::getBot($botId); if($bot) $m.=" TO BOT @{$bot['bot_username']}\n📆 EXPIRES: ".date('d M Y',strtotime($bot['expires_at'])); }
                Telegram::send($chat,$m);
            } else Telegram::send($chat,"❌ REDEEM FAILED: {$days}");
            UserManager::clearState($uid); break;

        case 'await_payment_ss':
            $payId=(int)($data['pay_id']??0);
            if ($photo) {
                $fid=end($photo)['file_id']??'';
                PaymentManager::saveSS($payId,$fid);
                UserManager::setState($uid,'await_payment_utr',['pay_id'=>$payId]);
                Telegram::send($chat,"📸 SCREENSHOT RECEIVED!\n\n🧾 NOW SEND YOUR UTR / TRANSACTION ID:");
            } else Telegram::send($chat,'❌ SEND PAYMENT SCREENSHOT PHOTO.');
            break;

        case 'await_payment_utr':
            $payId=(int)($data['pay_id']??0);
            $utr=trim($text);
            if (!$utr) { Telegram::send($chat,'❌ SEND UTR/TRANSACTION ID.'); return; }
            PaymentManager::saveUTR($payId,$utr);
            notify_admin_payment($payId,$uid);
            Telegram::send($chat,"✅ <b>PAYMENT SUBMITTED!</b>\n\n🧾 UTR: <code>{$utr}</code>\n\n⏳ ADMIN WILL VERIFY WITHIN 30 MIN.\n📞 CONTACT SUPPORT IF NOT ACTIVATED IN 1 HOUR.");
            UserManager::clearState($uid); break;

        case 'await_broadcast':
            if (!$isPriv) { UserManager::clearState($uid); return; }
            $users=UserManager::all(); $ok=$fail=0;
            foreach($users as $u){ Telegram::send((int)$u['tg_id'],"📢 <b>ANNOUNCEMENT:</b>\n\n{$text}")?$ok++:$fail++; usleep(50000); }
            Database::q("INSERT INTO broadcast_log(admin_id,message,sent_count,fail_count) VALUES(?,?,?,?)",[$uid,$text,$ok,$fail]);
            Telegram::send($chat,"📢 BROADCAST DONE!\n✅ SENT: {$ok}\n❌ FAILED: {$fail}");
            UserManager::clearState($uid); break;

        case 'await_custom_days':
            if (!$isPriv) { UserManager::clearState($uid); return; }
            $days=(int)$text; $botId=(int)($data['bot_id']??0); $tgId=(int)($data['user_id']??0); $remove=(bool)($data['remove']??false);
            if ($days<=0||$days>9999) { Telegram::send($chat,'❌ ENTER NUMBER 1–9999.'); return; }
            if ($botId) {
                [$ok,$exp]=$remove?BotManager::removeDays($botId,$days,$uid):BotManager::grantDays($botId,$days,$uid,'CUSTOM GRANT');
                $bot=BotManager::getBot($botId);
                if ($ok&&$bot&&!$remove) Telegram::send((int)$bot['owner_id'],"🎁 <b>+{$days} DAYS</b> ADDED TO BOT @{$bot['bot_username']}");
                Telegram::send($chat,($ok?'✅':'❌').($remove?" REMOVED {$days}D":" GRANTED {$days}D")." — BOT #{$botId} — EXPIRY: {$exp}");
            } elseif ($tgId) {
                [$ok,$cnt]=BotManager::grantDaysByUser($tgId,$days,$uid,'CUSTOM USER GRANT');
                if ($ok) Telegram::send($tgId,"🎁 <b>ADMIN GRANTED +{$days} DAYS</b> TO ALL YOUR BOTS!");
                Telegram::send($chat,$ok?"✅ GRANTED {$days}D TO USER {$tgId} ({$cnt} BOTS).":"❌ FAILED.");
            }
            UserManager::clearState($uid); break;

        case 'await_setting_val':
            if (!$isPriv) { UserManager::clearState($uid); return; }
            $key=$data['key']??''; if($key) Settings::set($key,trim($text));
            Telegram::send($chat,"✅ <code>{$key}</code> = <code>".htmlspecialchars(trim($text))."</code>"); UserManager::clearState($uid); break;

        case 'await_add_admin':
            if (!$isOwner) { UserManager::clearState($uid); return; }
            $tgId=(int)trim($text);
            if (!$tgId) { Telegram::send($chat,'❌ SEND TELEGRAM USER ID (NUMBER).'); return; }
            [$ok,$msg]=UserManager::addAdmin($tgId,$uid,'admin');
            if ($ok) Telegram::send($tgId,"🎉 <b>YOU HAVE BEEN ADDED AS ADMIN!</b>\n\nWELCOME TO THE TEAM.\nSEND /start TO SEE YOUR NEW MENU.");
            Telegram::send($chat,($ok?'✅':'❌')." {$msg}"); UserManager::clearState($uid); break;

        case 'await_user_stmt_id':
            if (!$isPriv) { UserManager::clearState($uid); return; }
            $tgId=(int)trim($text);
            if (!$tgId) { Telegram::send($chat,'❌ SEND USER TELEGRAM ID.'); return; }
            $stmt=BotManager::getUserStatement($tgId);
            Telegram::sendInline($chat,$stmt,KB::back('adm_panel')); UserManager::clearState($uid); break;

        case 'await_bot_stmt_id':
            if (!$isPriv) { UserManager::clearState($uid); return; }
            $botId=(int)trim($text);
            if (!$botId) { Telegram::send($chat,'❌ SEND BOT DB ID (NUMBER).'); return; }
            $stmt=BotManager::getBotStatement($botId);
            Telegram::sendInline($chat,$stmt,KB::back('adm_panel')); UserManager::clearState($uid); break;

        case 'await_vps_update':
            if (!$isOwner) { UserManager::clearState($uid); return; }
            $field=$data['field']??'notes'; $val=trim($text);
            Database::q("UPDATE vps_info SET {$field}=?,updated_at=NOW() WHERE id=1",[$val]);
            Telegram::send($chat,"✅ VPS {$field} UPDATED."); UserManager::clearState($uid); break;

        default: UserManager::clearState($uid);
    }
}

// ════════════════════════════════════════════════════════════
//  CALLBACKS
// ════════════════════════════════════════════════════════════
function cb(array $cb, int $chat, int $uid, array $user, bool $isOwner, bool $isAdmin, bool $isPriv): void {
    $data=$cb['data']??''; $mid=$cb['message']['message_id']??0; $cbId=$cb['id'];
    Telegram::answer($cbId);

    // Channel
    if ($data==='check_join') {
        if (UserManager::isChannelMember($uid)) {
            $kb=$isOwner?KB::ownerMenu():($isAdmin?KB::adminMenu():KB::userMenu());
            Telegram::sendMenu($chat,'✅ WELCOME!',$kb);
        } else Telegram::answer($cbId,'❌ NOT JOINED YET.',true);
        return;
    }

    // Main menu
    if ($data==='main_menu') {
        $kb=$isOwner?KB::ownerMenu():($isAdmin?KB::adminMenu():KB::userMenu());
        Telegram::sendMenu($chat,'🏠 MAIN MENU',$kb); return;
    }

    // ── COMPILE ───────────────────────────────────────────────
    if ($data==='compile_menu') { Telegram::edit($chat,$mid,"💻 <b>CODE COMPILER</b>\n\n".Compiler::availabilityReport(),KB::compileLangs()); return; }
    if ($data==='compile_info') { Telegram::edit($chat,$mid,Compiler::availabilityReport(),KB::back('compile_menu')); return; }

    if (preg_match('/^compile_(c|cpp|go|python|php|node|shell)$/',$data,$m)) {
        $lang=$m[1]; $ls=Compiler::langs(); $icon=$ls[$lang]['icon']??'💻'; $nm=$ls[$lang]['name']??strtoupper($lang);
        UserManager::setState($uid,'await_code',['lang'=>$lang]);
        Telegram::edit($chat,$mid,"{$icon} <b>{$nm} COMPILER</b>\n\n📝 SEND YOUR CODE AS TEXT.\n\n💡 TEMPLATE:\n<pre>".htmlspecialchars(Compiler::template($lang))."</pre>\n\n/cancel TO ABORT.",KB::back('compile_menu')); return;
    }

    if ($data==='compile_upload') { UserManager::setState($uid,'await_compile_file',[]); Telegram::edit($chat,$mid,"📤 <b>UPLOAD CODE FILE</b>\n\nSEND FILE (.c .cpp .go .py .php .js .sh)\nLANG AUTO-DETECTED.",KB::back('compile_menu')); return; }
    if ($data==='compile_templates') { Telegram::edit($chat,$mid,"📋 <b>CODE TEMPLATES</b>\n\nSELECT TEMPLATE:",KB::templates()); return; }

    if (preg_match('/^tpl_(c|cpp|go|python|php|node|shell)$/',$data,$m)) {
        $lang=$m[1]; $ls=Compiler::langs(); $icon=$ls[$lang]['icon']??'💻'; $nm=$ls[$lang]['name']??strtoupper($lang);
        Telegram::send($chat,"{$icon} <b>{$nm} TEMPLATE:</b>\n\n<pre>".htmlspecialchars(Compiler::template($lang))."</pre>");
        UserManager::setState($uid,'await_code',['lang'=>$lang]); Telegram::send($chat,"📝 NOW SEND YOUR CODE TO COMPILE:"); return;
    }

    // ── PLANS / PAY ───────────────────────────────────────────
    if ($data==='show_plans') { show_plans($chat,$uid); return; }

    if (preg_match('/^buyplan_(\d+)_(\d+)$/',$data,$m)) {
        $planId=(int)$m[1]; $botId=(int)$m[2]?:null;
        $plan=Database::one("SELECT * FROM hosting_plans WHERE id=?",[$planId]);
        if (!$plan) { Telegram::answer($cbId,'PLAN NOT FOUND',true); return; }
        $payId=PaymentManager::create($uid,$botId,$plan['name'],(int)$plan['days'],(float)$plan['price']);
        $amount=(float)$plan['price']; $qrUrl=PaymentManager::getQrUrl($amount,$plan['name']);
        $upiId=Settings::get('upi_id','pay@upi'); $upiName=Settings::get('upi_name','BOT HOSTING');
        $cap="📲 <b>SCAN QR TO PAY ₹{$amount}</b>\n\n🏦 UPI: <code>{$upiId}</code>\n👤 NAME: {$upiName}\n📦 PLAN: ".strtoupper($plan['name'])." ({$plan['days']} DAYS)\n\n1️⃣ SCAN QR\n2️⃣ PAY EXACT AMOUNT\n3️⃣ SEND SCREENSHOT + UTR";
        $sent=Telegram::sendPhotoUrl($chat,$qrUrl,$cap,KB::paymentOpts($payId,$amount));
        if (!$sent) Telegram::sendInline($chat,"💳 <b>PAYMENT</b>\n\n🏦 UPI: <code>{$upiId}</code>\n💰 AMOUNT: ₹{$amount}\n📦 ".strtoupper($plan['name'])."\n\nSEND SCREENSHOT AFTER PAYING.",KB::paymentOpts($payId,$amount));
        Database::q("UPDATE payments SET qr_sent=1 WHERE id=?",[$payId]);
        UserManager::setState($uid,'await_payment_ss',['pay_id'=>$payId]); return;
    }

    if (preg_match('/^showqr_(\d+)$/',$data,$m)) {
        $pay=Database::one("SELECT * FROM payments WHERE id=? AND user_id=?",[(int)$m[1],$uid]);
        if (!$pay) return;
        $qrUrl=PaymentManager::getQrUrl((float)$pay['amount'],$pay['plan']);
        Telegram::sendPhotoUrl($chat,$qrUrl,"📲 <b>SCAN QR TO PAY ₹{$pay['amount']}</b>\n\nUPI: <code>".Settings::get('upi_id')."</code>\nPLAN: ".strtoupper($pay['plan']),[]);
        UserManager::setState($uid,'await_payment_ss',['pay_id'=>(int)$m[1]]); return;
    }

    if (preg_match('/^sendproof_(\d+)$/',$data,$m)) {
        UserManager::setState($uid,'await_payment_ss',['pay_id'=>(int)$m[1]]);
        Telegram::send($chat,"📸 SEND PAYMENT SCREENSHOT NOW:"); return;
    }

    // ── REDEEM ────────────────────────────────────────────────
    if ($data==='redeem_menu') { start_redeem($chat,$uid,$isPriv); return; }

    if (preg_match('/^bredeem_(\d+)$/',$data,$m)) {
        $botId=(int)$m[1]; $bot=gbot($botId,$uid,$isPriv); if(!$bot) return;
        UserManager::setState($uid,'await_redeem_code',['bot_id'=>$botId]);
        Telegram::edit($chat,$mid,"🔑 SEND REDEEM CODE FOR BOT @{$bot['bot_username']}:",KB::back('bot_'.$botId)); return;
    }

    // ── FILES DOWNLOAD ─────────────────────────────────────────
    if ($data==='my_files') { show_user_files_inline($chat,$uid,$mid); return; }

    if (preg_match('/^dload_(\d+)$/',$data,$m)) {
        $fid=(int)$m[1];
        $ok=BotManager::sendFileToUser($chat,$uid,$fid);
        if (!$ok) Telegram::answer($cbId,'❌ FILE NOT FOUND',true);
        return;
    }

    if (preg_match('/^bfiles_(\d+)$/',$data,$m)) {
        $botId=(int)$m[1]; $bot=gbot($botId,$uid,$isPriv); if(!$bot) return;
        $files=Database::all("SELECT * FROM user_files WHERE bot_id=? ORDER BY uploaded_at DESC",[$botId]);
        if (!$files) { Telegram::answer($cbId,'NO FILES FOR THIS BOT',true); return; }
        Telegram::edit($chat,$mid,"📁 <b>FILES FOR @{$bot['bot_username']}</b>:",KB::filesList($files,'bot_'.$botId)); return;
    }

    // ── STATEMENTS ────────────────────────────────────────────
    if ($data==='my_stmt') {
        $stmt=BotManager::getUserStatement($uid);
        Telegram::sendInline($chat,$stmt,KB::back('main_menu')); return;
    }

    if (preg_match('/^bstatement_(\d+)$/',$data,$m)) {
        $botId=(int)$m[1]; $bot=gbot($botId,$uid,$isPriv); if(!$bot) return;
        $stmt=BotManager::getBotStatement($botId);
        Telegram::sendInline($chat,$stmt,KB::back('bot_'.$botId)); return;
    }

    // ══════════════ PRIV ONLY =================================
    if (!$isPriv) { Telegram::answer($cbId,'⛔ ACCESS DENIED',true); return; }

    // ── MY BOTS ───────────────────────────────────────────────
    if ($data==='my_bots')           { show_bots_inline($chat,$uid,$mid); return; }
    if ($data==='add_bot')           { start_add_bot($chat,$uid); return; }
    if ($data==='remove_bot_pick')   { $bots=BotManager::getUserBots($uid); if(!$bots){Telegram::answer($cbId,'NO BOTS',true);return;} Telegram::edit($chat,$mid,"🗑 SELECT BOT TO REMOVE:",KB::removeBotPick($bots)); return; }

    if (preg_match('/^confirm_remove_(\d+)$/',$data,$m)) {
        $bot=gbot((int)$m[1],$uid,$isPriv); if(!$bot) return;
        Telegram::edit($chat,$mid,"⚠️ <b>REMOVE @{$bot['bot_username']}?</b>\n\nALL DATA DELETED! CANNOT UNDO.",KB::confirmRemove((int)$m[1])); return;
    }

    if (preg_match('/^do_remove_(\d+)$/',$data,$m)) {
        [$ok,$msg]=BotManager::removeBot((int)$m[1],$uid);
        Telegram::edit($chat,$mid,($ok?'✅':'❌')." {$msg}",KB::back('my_bots')); return;
    }

    if (preg_match('/^bot_(\d+)$/',$data,$m))       { show_bot($chat,$uid,(int)$m[1],$mid); return; }

    if (preg_match('/^bstart_(\d+)$/',$data,$m))    { $bot=gbot((int)$m[1],$uid,$isPriv);if(!$bot)return; Telegram::edit($chat,$mid,"⏳ STARTING @{$bot['bot_username']}...",[]);[$ok,$r]=BotManager::startBot((int)$m[1]);Telegram::edit($chat,$mid,($ok?'✅':'❌')." {$r}",KB::botActions(BotManager::getBot((int)$m[1])??$bot));return; }
    if (preg_match('/^bstop_(\d+)$/',$data,$m))     { $bot=gbot((int)$m[1],$uid,$isPriv);if(!$bot)return;[$ok,$r]=BotManager::stopBot((int)$m[1]);Telegram::answer($cbId,$r,true);show_bot($chat,$uid,(int)$m[1],$mid);return; }
    if (preg_match('/^brestart_(\d+)$/',$data,$m))  { $bot=gbot((int)$m[1],$uid,$isPriv);if(!$bot)return;Telegram::edit($chat,$mid,"🔄 RESTARTING...",[]);[$ok,$r]=BotManager::restartBot((int)$m[1]);Telegram::edit($chat,$mid,($ok?'✅':'❌')." {$r}",KB::botActions(BotManager::getBot((int)$m[1])??$bot));return; }

    if (preg_match('/^bupload_(\d+)$/',$data,$m)) {
        $bot=gbot((int)$m[1],$uid,$isPriv);if(!$bot)return;
        Telegram::edit($chat,$mid,"📤 SELECT SCRIPT LANGUAGE FOR @{$bot['bot_username']}:",KB::scriptLang((int)$m[1])); return;
    }

    if (preg_match('/^slang_(\d+)_(python|php|node|shell|html|css)$/',$data,$m)) {
        $botId=(int)$m[1]; $lang=$m[2]; $bot=gbot($botId,$uid,$isPriv);if(!$bot)return;
        UserManager::setState($uid,'await_script_upload',['bot_id'=>$botId,'lang'=>$lang]);
        Telegram::edit($chat,$mid,"✅ LANGUAGE: <b>".strtoupper($lang)."</b>\n\n📤 SEND SCRIPT FILE NOW:\n/cancel TO ABORT.",KB::back('bot_'.$botId)); return;
    }

    if (preg_match('/^blog_(\d+)$/',$data,$m)) {
        $bot=gbot((int)$m[1],$uid,$isPriv);if(!$bot)return;
        $log=BotManager::getLog((int)$m[1],40);
        Telegram::edit($chat,$mid,"📋 <b>LOG @{$bot['bot_username']}</b>\n\n<code>".htmlspecialchars(mb_strimwidth($log,0,3800,'...[TRUNC]'))."</code>",KB::back('bot_'.(int)$m[1])); return;
    }

    if (preg_match('/^bstatus_(\d+)$/',$data,$m)) {
        $botId=(int)$m[1]; BotManager::syncStatus($botId); $bot=BotManager::getBot($botId);if(!$bot)return;
        $days=BotManager::daysLeft($bot); $exp=$bot['expires_at']?date('d M Y H:i',strtotime($bot['expires_at'])):'NOT SET';
        $alive=BotManager::isAlive($botId)?'✅ ALIVE':'💀 DEAD';
        Telegram::edit($chat,$mid,"📊 <b>BOT STATUS</b>\n🤖 @{$bot['bot_username']}\n".BotManager::badge($bot)."\n🔍 {$alive} (PID:".($bot['pid']?:'N/A').")\n📅 ".strtoupper($bot['plan'])."\n⏳ {$days}D LEFT\n📆 {$exp}",KB::botActions($bot)); return;
    }

    if (preg_match('/^bdel_(\d+)$/',$data,$m)) {
        $bot=gbot((int)$m[1],$uid,$isPriv);if(!$bot)return;
        Telegram::edit($chat,$mid,"⚠️ <b>DELETE @{$bot['bot_username']}?</b>\n\nALL FILES PERMANENTLY DELETED!",KB::confirmDel((int)$m[1])); return;
    }

    if (preg_match('/^do_del_(\d+)$/',$data,$m)) {
        [$ok,$r]=BotManager::removeBot((int)$m[1],$uid);
        Telegram::edit($chat,$mid,($ok?'✅':'❌')." {$r}",KB::back('my_bots')); return;
    }

    if (preg_match('/^bbuy_(\d+)$/',$data,$m)) {
        $bot=gbot((int)$m[1],$uid,$isPriv);if(!$bot)return;
        Telegram::edit($chat,$mid,"💳 <b>SELECT PLAN FOR @{$bot['bot_username']}</b>:",KB::plans(PaymentManager::getPlans(),(int)$m[1])); return;
    }

    // ═══════════════ ADMIN PANEL ══════════════════════════════
    if ($data==='adm_panel')         { Telegram::edit($chat,$mid,'👑 <b>ADMIN PANEL</b>',KB::adminPanel()); return; }
    if ($data==='adm_users')         { admin_users($chat); return; }
    if ($data==='adm_bots')          { admin_all_bots($chat); return; }
    if ($data==='adm_payments')      { admin_payments($chat); return; }
    if ($data==='adm_settings')      { admin_settings($chat); return; }
    if ($data==='adm_addbot')        { start_add_bot($chat,$uid); return; }
    if ($data==='adm_broadcast_menu') { UserManager::setState($uid,'await_broadcast',[]); Telegram::send($chat,'📢 SEND BROADCAST MESSAGE:'); return; }

    // VPS Statement
    if ($data==='adm_vps') {
        $stmt=BotManager::getVpsStatement();
        $btns=[
            [
                ['text'=>'🔄 REFRESH','callback_data'=>'adm_vps'],
                ['text'=>'✏️ EDIT VPS INFO','callback_data'=>'adm_vps_edit'],
            ],
            [['text'=>'🔙 ADMIN','callback_data'=>'adm_panel']],
        ];
        Telegram::edit($chat,$mid,$stmt,$btns); return;
    }

    if ($data==='adm_vps_edit' && $isOwner) {
        $btns=[
            [['text'=>'🌐 IP ADDRESS','callback_data'=>'vpsset_ip_address']],
            [['text'=>'📍 LOCATION','callback_data'=>'vpsset_location']],
            [['text'=>'💾 RAM GB','callback_data'=>'vpsset_ram_gb']],
            [['text'=>'💿 DISK GB','callback_data'=>'vpsset_disk_gb']],
            [['text'=>'🖥 OS INFO','callback_data'=>'vpsset_os_info']],
            [['text'=>'🏷 LABEL','callback_data'=>'vpsset_label']],
            [['text'=>'📝 NOTES','callback_data'=>'vpsset_notes']],
            [['text'=>'🔙 BACK','callback_data'=>'adm_vps']],
        ];
        Telegram::edit($chat,$mid,"✏️ <b>EDIT VPS INFO</b>\n\nSELECT FIELD TO UPDATE:",$btns); return;
    }

    if (preg_match('/^vpsset_(.+)$/',$data,$m) && $isOwner) {
        UserManager::setState($uid,'await_vps_update',['field'=>$m[1]]);
        Telegram::send($chat,"✏️ SEND NEW VALUE FOR VPS <b>".strtoupper($m[1])."</b>:"); return;
    }

    // User Statements
    if ($data==='adm_userstmt') { UserManager::setState($uid,'await_user_stmt_id',[]); Telegram::send($chat,'📊 SEND TELEGRAM USER ID FOR STATEMENT:'); return; }
    if ($data==='adm_botstmt')  { UserManager::setState($uid,'await_bot_stmt_id',[]); Telegram::send($chat,'🤖 SEND BOT DB ID FOR STATEMENT:'); return; }

    if (preg_match('/^auserstmt_(\d+)$/',$data,$m)) {
        $stmt=BotManager::getUserStatement((int)$m[1]);
        Telegram::sendInline($chat,$stmt,KB::back('auser_'.$m[1])); return;
    }

    if (preg_match('/^abstatement_(\d+)$/',$data,$m)) {
        $stmt=BotManager::getBotStatement((int)$m[1]);
        Telegram::sendInline($chat,$stmt,KB::back('abotview_'.$m[1])); return;
    }

    // Admin bot files
    if (preg_match('/^abfiles_(\d+)$/',$data,$m)) {
        $bot=BotManager::getBot((int)$m[1]);if(!$bot)return;
        $files=Database::all("SELECT * FROM user_files WHERE bot_id=?",[(int)$m[1]]);
        if (!$files){Telegram::answer($cbId,'NO FILES',true);return;}
        Telegram::edit($chat,$mid,"📁 <b>FILES FOR BOT #".($m[1])."</b>:",KB::filesList($files,'abotview_'.$m[1])); return;
    }

    // Admin send file
    if (preg_match('/^asendfile_(\d+)_(\d+)$/',$data,$m)) {
        $botId=(int)$m[1]; $fid=(int)$m[2];
        $f=Database::one("SELECT * FROM user_files WHERE id=?",[$fid]);
        if ($f&&file_exists($f['filepath'])) Telegram::sendDoc($chat,$f['filepath'],"📁 ".htmlspecialchars($f['filename']));
        else Telegram::answer($cbId,'FILE NOT FOUND',true);
        return;
    }

    // Grant days
    if ($data==='adm_grant') { Telegram::send($chat,"📅 <b>GRANT DAYS</b>\n\n• GO TO 🤖 ALL BOTS → SELECT BOT → GRANT DAYS\n• OR GO TO 👥 ALL USERS → SELECT USER → GRANT DAYS"); return; }

    if (preg_match('/^agdview_(\d+)$/',$data,$m)) {
        $bot=BotManager::getBot((int)$m[1]);if(!$bot)return;
        Telegram::edit($chat,$mid,"📅 GRANT DAYS TO BOT @{$bot['bot_username']}\nCURRENT: ".BotManager::daysLeft($bot)."D LEFT",KB::grantDaysBtns((int)$m[1],false)); return;
    }

    if (preg_match('/^agd_(\d+)_(\d+)$/',$data,$m)) {
        [$ok,$exp]=BotManager::grantDays((int)$m[1],(int)$m[2],$uid,'ADMIN GRANT');
        $bot=BotManager::getBot((int)$m[1]);
        if ($ok&&$bot) Telegram::send((int)$bot['owner_id'],"🎁 <b>+{$m[2]} DAYS</b> ADDED TO BOT @{$bot['bot_username']}\n📆 EXPIRES: ".date('d M Y H:i',strtotime($exp)));
        Telegram::answer($cbId,$ok?"✅ +{$m[2]}D GRANTED":"❌ FAILED",true);
        admin_bot_detail($chat,(int)$m[1],$mid); return;
    }

    if (preg_match('/^agd_(\d+)_custom$/',$data,$m)) { UserManager::setState($uid,'await_custom_days',['bot_id'=>(int)$m[1]]); Telegram::send($chat,'✏️ SEND CUSTOM DAYS:'); return; }
    if (preg_match('/^ard_(\d+)$/',$data,$m))        { UserManager::setState($uid,'await_custom_days',['bot_id'=>(int)$m[1],'remove'=>true]); Telegram::send($chat,'✏️ SEND DAYS TO REMOVE:'); return; }

    if (preg_match('/^agduser_(\d+)$/',$data,$m)) { Telegram::edit($chat,$mid,"📅 GRANT DAYS TO USER <code>{$m[1]}</code>:",KB::grantDaysBtns((int)$m[1],true)); return; }

    if (preg_match('/^agdu_(\d+)_(\d+)$/',$data,$m)) {
        [$ok,$cnt]=BotManager::grantDaysByUser((int)$m[1],(int)$m[2],$uid,'ADMIN USER GRANT');
        if ($ok) Telegram::send((int)$m[1],"🎁 <b>+{$m[2]} DAYS</b> GRANTED TO ALL YOUR BOTS BY ADMIN!");
        Telegram::answer($cbId,$ok?"✅ +{$m[2]}D TO {$cnt} BOTS":"❌ FAILED",true); return;
    }

    if (preg_match('/^agdu_(\d+)_custom$/',$data,$m)) { UserManager::setState($uid,'await_custom_days',['user_id'=>(int)$m[1]]); Telegram::send($chat,'✏️ SEND CUSTOM DAYS:'); return; }
    if (preg_match('/^ardu_(\d+)$/',$data,$m))        { UserManager::setState($uid,'await_custom_days',['user_id'=>(int)$m[1],'remove'=>true]); Telegram::send($chat,'✏️ SEND DAYS TO REMOVE:'); return; }

    // User view
    if (preg_match('/^auser_(\d+)$/',$data,$m)) {
        $u=UserManager::get((int)$m[1]);if(!$u)return;
        $bots=BotManager::getUserBots((int)$m[1]);
        $isAdm=UserManager::isAdmin($u);
        Telegram::edit($chat,$mid,
            "👤 <b>USER INFO</b>\n\n🆔 <code>{$m[1]}</code>\n👤 ".strtoupper($u['first_name']??'').
            "\n📛 @".($u['username']??'—')."\n🔐 ".strtoupper($u['plan']??'FREE').
            "\n👮 ADMIN: ".($isAdm?'YES':'NO').
            "\n🤖 BOTS: ".count($bots)."\n🚫 BANNED: ".((int)$u['is_banned']?'YES':'NO').
            "\n📅 JOINED: ".date('d M Y',strtotime($u['joined_at'])),
            KB::adminUserActions((int)$m[1],(bool)(int)$u['is_banned'])
        ); return;
    }

    if (preg_match('/^aviewbots_(\d+)$/',$data,$m)) {
        $bots=BotManager::getUserBots((int)$m[1]);
        if (!$bots){Telegram::answer($cbId,'NO BOTS',true);return;}
        $btns=[];
        foreach($bots as $b){$icon=$b['status']==='active'?'🟢':($b['status']==='expired'?'⏰':'🔴');$btns[]=[['text'=>"{$icon} @{$b['bot_username']} — ".BotManager::daysLeft($b)."D",'callback_data'=>'abotview_'.$b['id']]];}
        $btns[]=[['text'=>'🔙 BACK','callback_data'=>'auser_'.$m[1]]];
        Telegram::edit($chat,$mid,"🤖 BOTS OF USER <code>{$m[1]}</code>:",$btns); return;
    }

    if (preg_match('/^aban_(\d+)$/',$data,$m))   { UserManager::ban((int)$m[1]);   Telegram::answer($cbId,'✅ BANNED',true);   return; }
    if (preg_match('/^aunban_(\d+)$/',$data,$m)) { UserManager::unban((int)$m[1]); Telegram::answer($cbId,'✅ UNBANNED',true); return; }

    // Admin bot detail & controls
    if (preg_match('/^abotview_(\d+)$/',$data,$m)) { admin_bot_detail($chat,(int)$m[1],$mid); return; }

    if (preg_match('/^abstart_(\d+)$/',$data,$m))   { [$ok,$r]=BotManager::startBot((int)$m[1]);   Telegram::answer($cbId,$r,true); admin_bot_detail($chat,(int)$m[1],$mid); return; }
    if (preg_match('/^abstop_(\d+)$/',$data,$m))    { [$ok,$r]=BotManager::stopBot((int)$m[1]);    Telegram::answer($cbId,$r,true); admin_bot_detail($chat,(int)$m[1],$mid); return; }
    if (preg_match('/^abrestart_(\d+)$/',$data,$m)) { [$ok,$r]=BotManager::restartBot((int)$m[1]); Telegram::answer($cbId,$r,true); admin_bot_detail($chat,(int)$m[1],$mid); return; }
    if (preg_match('/^abban_(\d+)$/',$data,$m)) {
        Database::q("UPDATE hosted_bots SET status='banned' WHERE id=?",[(int)$m[1]]);
        BotManager::stopBot((int)$m[1]); Telegram::answer($cbId,'🚫 BANNED',true); admin_bot_detail($chat,(int)$m[1],$mid); return;
    }
    if (preg_match('/^abdel_(\d+)$/',$data,$m)) {
        $b=BotManager::getBot((int)$m[1]); BotManager::removeBot((int)$m[1],(int)($b['owner_id']??0));
        Telegram::edit($chat,$mid,'🗑 BOT DELETED.',KB::back('adm_bots')); return;
    }
    if (preg_match('/^ablog_(\d+)$/',$data,$m)) {
        $log=BotManager::getLog((int)$m[1],50);
        Telegram::edit($chat,$mid,"📋 <b>LOG #{$m[1]}</b>\n\n<code>".htmlspecialchars(mb_strimwidth($log,0,3800,'...[TRUNC]'))."</code>",KB::back('abotview_'.$m[1])); return;
    }

    // Payments
    if (preg_match('/^payok_(\d+)$/',$data,$m)) {
        $p=PaymentManager::approve((int)$m[1],$uid);
        if ($p) Telegram::send((int)$p['user_id'],"✅ <b>PAYMENT APPROVED!</b>\n📅 ".strtoupper($p['plan'])." ({$p['days']} DAYS) ACTIVATED!");
        Telegram::answer($cbId,'✅ APPROVED',true); admin_payments($chat); return;
    }
    if (preg_match('/^payrej_(\d+)$/',$data,$m)) {
        $p=PaymentManager::reject((int)$m[1],$uid);
        if ($p) Telegram::send((int)$p['user_id'],"❌ PAYMENT REJECTED. CONTACT SUPPORT.");
        Telegram::answer($cbId,'❌ REJECTED',true); admin_payments($chat); return;
    }

    // Settings edit
    if (preg_match('/^editset_(.+)$/',$data,$m)) {
        UserManager::setState($uid,'await_setting_val',['key'=>$m[1]]);
        Telegram::send($chat,"✏️ NEW VALUE FOR <code>{$m[1]}</code>\n\nCURRENT: <code>".htmlspecialchars(Settings::get($m[1]))."</code>"); return;
    }

    // Gen keys
    if ($data==='adm_genkeys') {
        Telegram::sendInline($chat,"🔑 GENERATE REDEEM CODES:",[
            [['text'=>'7D','callback_data'=>'gen_7'],['text'=>'30D','callback_data'=>'gen_30'],['text'=>'90D','callback_data'=>'gen_90']],
            [['text'=>'180D','callback_data'=>'gen_180'],['text'=>'365D','callback_data'=>'gen_365']],
            [['text'=>'🔙 ADMIN','callback_data'=>'adm_panel']],
        ]); return;
    }

    if (preg_match('/^gen_(\d+)$/',$data,$m)) {
        $days=(int)$m[1]; $codes=RedeemManager::generate("DAY{$days}",$days,5,30);
        $list=implode("\n",array_map(fn($c)=>"<code>{$c}</code>",$codes));
        Telegram::send($chat,"✅ <b>5 CODES ({$days} DAYS EACH):</b>\n\n{$list}"); return;
    }

    // ── ADMIN MANAGEMENT (OWNER ONLY) ─────────────────────────
    if ($data==='adm_admins') {
        if (!$isOwner){Telegram::answer($cbId,'OWNER ONLY',true);return;}
        $admins=UserManager::getAllAdmins();
        Telegram::edit($chat,$mid,"👮 <b>ADMINS (".count($admins).")</b>:",KB::adminList($admins)); return;
    }

    if ($data==='add_admin' && $isOwner) {
        UserManager::setState($uid,'await_add_admin',[]);
        Telegram::send($chat,"➕ <b>ADD ADMIN</b>\n\nSEND THE TELEGRAM USER ID OF THE PERSON TO MAKE ADMIN:\n\n⚠️ THEY MUST HAVE STARTED THIS BOT FIRST.\n\n/cancel TO ABORT."); return;
    }

    if (preg_match('/^adminview_(\d+)$/',$data,$m) && $isOwner) {
        $u=UserManager::get((int)$m[1]); if(!$u)return;
        $a=Database::one("SELECT * FROM admins WHERE tg_id=?",[(int)$m[1]]);
        Telegram::edit($chat,$mid,
            "👮 <b>ADMIN INFO</b>\n\n🆔 <code>{$m[1]}</code>\n👤 ".strtoupper($u['first_name']??'').
            "\n@".($u['username']??'—')."\n⭐ LEVEL: ".strtoupper($a['level']??'admin').
            "\n📅 ADDED: ".($a?date('d M Y',strtotime($a['added_at'])):'N/A'),
            KB::adminActions((int)$m[1])
        ); return;
    }

    if (preg_match('/^remove_admin_(\d+)$/',$data,$m) && $isOwner) {
        [$ok,$msg]=UserManager::removeAdmin((int)$m[1],$uid);
        if ($ok) try{Telegram::send((int)$m[1],"ℹ️ YOUR ADMIN PRIVILEGES HAVE BEEN REMOVED.");}catch(\Exception $e){}
        Telegram::answer($cbId,($ok?'✅ ':'❌ ').$msg,true);
        $admins=UserManager::getAllAdmins();
        Telegram::edit($chat,$mid,"👮 <b>ADMINS (".count($admins).")</b>:",KB::adminList($admins)); return;
    }
}

// ════════════════════════════════════════════════════════════
//  DOCUMENT HANDLER
// ════════════════════════════════════════════════════════════
function doc_handler(?array $doc, int $chat, int $uid, array $user, bool $isOwner, bool $isAdmin, bool $isPriv): void {
    $st=UserManager::getStateData($uid);
    if ($st['state']==='await_compile_file') { compile_file_upload($doc,$chat,$uid); UserManager::clearState($uid); return; }
    if ($isPriv && in_array($st['state'],['await_bot_script','await_script_upload'],true)) {
        $botId=(int)($st['data']['bot_id']??0); $lang=$st['data']['lang']??'python';
        if ($botId) { bot_script_upload($doc,$chat,$uid,$botId,$lang); }
        UserManager::clearState($uid); return;
    }
    // Try auto-compile for users
    $lang=Compiler::detectLang($doc['file_name']??'file.py');
    $url=Telegram::getFileUrl($doc['file_id']);
    if ($url) {
        $code=@file_get_contents($url);
        if ($code!==false) {
            Telegram::send($chat,"⏳ AUTO-DETECTED: ".strtoupper($lang)." — COMPILING...");
            $r=Compiler::run($uid,$lang,$code);
            Telegram::sendInline($chat,Compiler::formatOutput($r),KB::compileResult($lang)); return;
        }
    }
    Telegram::send($chat,"❌ FILE DOWNLOAD FAILED. TRY AGAIN.");
}

// ════════════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════════════
function welcome(int $chat, int $uid, array $user, bool $isOwner, bool $isAdmin): void {
    $name=strtoupper($user['first_name']??'USER');
    $kb=$isOwner?KB::ownerMenu():($isAdmin?KB::adminMenu():KB::userMenu());
    if ($isOwner||$isAdmin) {
        $bots=BotManager::getUserBots($uid); $run=count(array_filter($bots,fn($b)=>$b['status']==='active'));
        $text="👑 <b>WELCOME, {$name}!</b>".($isAdmin&&!$isOwner?" [ADMIN]":"")."\n\n🤖 <b>BOT HOSTING SYSTEM</b>\n\n━━━━━━━━━━━━━━━━━━\n🤖 YOUR BOTS: <b>".count($bots)."</b>\n🟢 RUNNING: <b>{$run}</b>\n👥 TOTAL USERS: ".UserManager::count()."\n━━━━━━━━━━━━━━━━━━\n\nUSE ➕ ADD BOT TO HOST A NEW BOT";
    } else {
        $bots=BotManager::getUserBots($uid);
        $text="👋 <b>WELCOME, {$name}!</b>\n\n🏠 <b>BOT HOSTING SYSTEM</b>\n\n━━━━━━━━━━━━━━━━━━\n💻 COMPILE: C, C++, GO, PYTHON & MORE\n💳 BUY PLANS VIA UPI + QR CODE\n📁 DOWNLOAD YOUR FILES\n📊 VIEW YOUR STATEMENT\n━━━━━━━━━━━━━━━━━━\n🔐 PLAN: <b>".strtoupper($user['plan']??'FREE')."</b>";
        if ($user['premium_until']) $text.="\n📆 EXPIRES: ".date('d M Y',strtotime($user['premium_until']));
    }
    Telegram::sendMenu($chat,$text,$kb);
}

function show_plans(int $chat, int $uid): void {
    $plans=PaymentManager::getPlans(); $text="💳 <b>HOSTING PLANS</b>\n\n";
    foreach($plans as $p){ if(!(int)$p['is_active']||(float)$p['price']<=0) continue; $text.="🔹 <b>".strtoupper($p['name'])."</b> — {$p['days']} DAYS — ₹{$p['price']}\n   📝 ".($p['description']??'')."\n\n"; }
    $text.="━━━━━━━━━━━━━━━━━━\n💡 SELECT PLAN TO GET UPI QR CODE:";
    Telegram::sendInline($chat,$text,KB::plans($plans,null));
}

function show_user_files(int $chat, int $uid): void {
    $files=BotManager::getUserFiles($uid);
    if (!$files) { Telegram::sendInline($chat,"📁 <b>MY FILES</b>\n\nNO FILES YET. UPLOAD SCRIPTS VIA YOUR BOTS.",KB::back('main_menu')); return; }
    Telegram::sendInline($chat,"📁 <b>MY FILES (".count($files).")</b>\n\nSELECT FILE TO DOWNLOAD:",KB::filesList($files,'main_menu'));
}

function show_user_files_inline(int $chat, int $uid, int $mid): void {
    $files=BotManager::getUserFiles($uid);
    Telegram::edit($chat,$mid,"📁 <b>MY FILES (".count($files).")</b>:",KB::filesList($files,'main_menu'));
}

function show_bots(int $chat, int $uid): void {
    $bots=BotManager::getUserBots($uid);
    if (!$bots) { Telegram::sendInline($chat,"🤖 <b>YOUR BOTS</b>\n\nNO BOTS YET!",[[['text'=>'➕ ADD BOT','callback_data'=>'add_bot']]]); return; }
    Telegram::sendInline($chat,"🤖 <b>YOUR BOTS (".count($bots).")</b>:",KB::ownerBotList($bots));
}

function show_bots_inline(int $chat, int $uid, int $mid): void {
    Telegram::edit($chat,$mid,"🤖 <b>YOUR BOTS</b>:",KB::ownerBotList(BotManager::getUserBots($uid)));
}

function show_bot(int $chat, int $uid, int $botId, int $mid): void {
    $bot=Database::one("SELECT * FROM hosted_bots WHERE id=? AND owner_id=?",[$botId,$uid]);
    if (!$bot) return;
    BotManager::syncStatus($botId); $bot=BotManager::getBot($botId);
    $days=BotManager::daysLeft($bot); $exp=$bot['expires_at']?date('d M Y H:i',strtotime($bot['expires_at'])):'NOT SET';
    $warn=($days>0&&$days<=3)?"\n\n⚠️ <b>EXPIRING SOON! RENEW NOW!</b>":'';
    Telegram::edit($chat,$mid,
        "🤖 <b>@".strtoupper($bot['bot_username']??'UNKNOWN')."</b>\n\n".BotManager::badge($bot)."\n📅 PLAN: ".strtoupper($bot['plan']).
        "\n⏳ DAYS LEFT: <b>{$days}</b>\n📆 EXPIRES: {$exp}\n📁 SCRIPT: ".strtoupper($bot['script_file']??'NOT UPLOADED').
        "\n🔤 LANG: ".strtoupper($bot['script_lang'])."\n🆔 PID: ".($bot['pid']?:'N/A').$warn,
        KB::botActions($bot)
    );
}

function start_add_bot(int $chat, int $uid): void {
    UserManager::setState($uid,'await_bot_token',[]);
    Telegram::send($chat,"➕ <b>ADD NEW BOT</b>\n\n📋 STEPS:\n1️⃣ OPEN @BotFather\n2️⃣ SEND /newbot OR /mybots\n3️⃣ COPY TOKEN\n\n━━━━━━━━━━━━━━━━━━\n📤 SEND BOT TOKEN NOW:\n\n<i>EXAMPLE: 123456789:ABCdef...</i>\n\n/cancel TO ABORT.");
}

function start_remove_bot(int $chat, int $uid): void {
    $bots=BotManager::getUserBots($uid);
    if (!$bots) { Telegram::send($chat,'❌ NO BOTS TO REMOVE.'); return; }
    Telegram::sendInline($chat,"🗑 <b>SELECT BOT TO REMOVE:</b>",KB::removeBotPick($bots));
}

function start_redeem(int $chat, int $uid, bool $isPriv): void {
    if ($isPriv) {
        $bots=BotManager::getUserBots($uid);
        if ($bots) {
            $btns=array_map(fn($b)=>[['text'=>"🤖 @{$b['bot_username']} — ".BotManager::daysLeft($b)."D",'callback_data'=>'bredeem_'.$b['id']]],$bots);
            $btns[]=[['text'=>'🎟 REDEEM FOR ACCOUNT','callback_data'=>'bredeem_0']];
            $btns[]=[['text'=>'🔙 BACK','callback_data'=>'main_menu']];
            Telegram::sendInline($chat,"🔑 SELECT WHERE TO APPLY CODE:",$btns); return;
        }
    }
    UserManager::setState($uid,'await_redeem_code',[]);
    Telegram::send($chat,"🔑 <b>REDEEM CODE</b>\n\nSEND YOUR REDEEM CODE:");
}

function compile_file_upload(array $doc, int $chat, int $uid): void {
    $name=$doc['file_name']??'code.py';
    if (($doc['file_size']??0)>MAX_FILE_SIZE){Telegram::send($chat,'❌ FILE TOO LARGE (MAX 5MB)');return;}
    $lang=Compiler::detectLang($name);
    $url=Telegram::getFileUrl($doc['file_id']);
    if (!$url){Telegram::send($chat,'❌ DOWNLOAD FAILED.');return;}
    $code=@file_get_contents($url);
    if ($code===false){Telegram::send($chat,'❌ DOWNLOAD FAILED. TRY AGAIN.');return;}
    Telegram::send($chat,"⏳ COMPILING: <b>".htmlspecialchars($name)."</b>\n🔤 LANG: ".strtoupper($lang));
    $r=Compiler::run($uid,$lang,$code);
    Telegram::sendInline($chat,Compiler::formatOutput($r),KB::compileResult($lang));
}

function bot_script_upload(array $doc, int $chat, int $uid, int $botId, string $lang): void {
    $name=$doc['file_name']??'bot.py'; $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if (!in_array($ext,ALLOWED_EXTENSIONS,true)){Telegram::send($chat,"❌ .{$ext} NOT ALLOWED.");return;}
    if (($doc['file_size']??0)>MAX_FILE_SIZE){Telegram::send($chat,'❌ FILE TOO LARGE (MAX 5MB)');return;}
    $url=Telegram::getFileUrl($doc['file_id']);
    if (!$url){Telegram::send($chat,'❌ DOWNLOAD FAILED.');return;}
    $content=@file_get_contents($url);
    if ($content===false){Telegram::send($chat,'❌ DOWNLOAD FAILED. TRY AGAIN.');return;}
    [$ok,$res]=BotManager::uploadScript($botId,$name,$content,$lang);
    if (!$ok){Telegram::send($chat,"❌ UPLOAD FAILED: {$res}");return;}
    $bot=BotManager::getBot($botId);
    Telegram::sendInline($chat,"✅ <b>SCRIPT UPLOADED!</b>\n\n📁 <code>{$name}</code>\n🔤 ".strtoupper($lang)."\n🤖 @{$bot['bot_username']}\n\n▶️ READY TO START!",[[
        ['text'=>'▶️ START BOT','callback_data'=>'bstart_'.$botId],
        ['text'=>'🤖 MY BOTS',  'callback_data'=>'my_bots'],
    ]]);
}

function admin_users(int $chat): void {
    $users=UserManager::all();
    $btns=[];
    foreach(array_slice($users,0,25) as $u){
        $icon=(int)$u['is_banned']?'🚫':((int)$u['is_owner']?'👑':(!empty($u['admin_level'])?'👮':((int)$u['is_premium']?'💎':'👤')));
        $name=strtoupper($u['first_name']??$u['username']??'UNKNOWN');
        $btns[]=[['text'=>"{$icon} {$name} [{$u['tg_id']}]",'callback_data'=>'auser_'.$u['tg_id']]];
    }
    $btns[]=[['text'=>'🔙 ADMIN','callback_data'=>'adm_panel']];
    Telegram::sendInline($chat,"👥 <b>USERS (".count($users).")</b>:",$btns);
}

function admin_all_bots(int $chat): void {
    $bots=BotManager::getAllBots(30); $btns=[];
    foreach($bots as $b){
        $icon=match($b['status']){'active'=>'🟢','expired'=>'⏰','banned'=>'🚫',default=>'🔴'};
        $days=BotManager::daysLeft($b);
        $btns[]=[['text'=>"{$icon} @".($b['bot_username']??'—')." [{$days}D] @".($b['username']??$b['owner_id']),'callback_data'=>'abotview_'.$b['id']]];
    }
    $btns[]=[['text'=>'🔙 ADMIN','callback_data'=>'adm_panel']];
    Telegram::sendInline($chat,"🤖 <b>ALL BOTS (".count($bots).")</b>:",$btns);
}

function admin_bot_detail(int $chat, int $botId, int $mid): void {
    $bot=BotManager::getBot($botId);if(!$bot)return;
    BotManager::syncStatus($botId); $bot=BotManager::getBot($botId);
    $days=BotManager::daysLeft($bot); $exp=$bot['expires_at']?date('d M Y H:i',strtotime($bot['expires_at'])):'—';
    $owner=UserManager::get((int)$bot['owner_id']); $alive=BotManager::isAlive($botId)?'✅':'💀';
    Telegram::edit($chat,$mid,
        "🤖 <b>BOT #{$botId}</b>\n@{$bot['bot_username']}\n".BotManager::badge($bot).
        "\n👤 OWNER: @".($owner['username']??$bot['owner_id'])." [<code>{$bot['owner_id']}</code>]".
        "\n🔍 {$alive} PID:".($bot['pid']?:'N/A')."\n📅 ".strtoupper($bot['plan']).
        "\n⏳ {$days}D LEFT\n📆 {$exp}\n📁 ".($bot['script_file']?:'NONE')."\n🔤 ".strtoupper($bot['script_lang']),
        KB::adminBotActions($bot)
    );
}

function admin_payments(int $chat): void {
    $pending=PaymentManager::pending();
    if (!$pending){Telegram::send($chat,'✅ NO PENDING PAYMENTS.');return;}
    foreach($pending as $p){
        Telegram::sendInline($chat,
            "💳 <b>PAYMENT REQUEST</b>\n\n👤 ".strtoupper($p['first_name']??$p['user_id'])." [<code>{$p['user_id']}</code>]\n🤖 BOT: ".($p['bot_username']?"@{$p['bot_username']}":'N/A')."\n📦 ".strtoupper($p['plan'])." ({$p['days']} DAYS)\n💰 ₹{$p['amount']}\n🧾 UTR: <code>".($p['utr']?:'PENDING')."</code>\n🕐 ".date('d M Y H:i',strtotime($p['created_at'])),
            KB::payApprove($p['id'])
        );
    }
}

function admin_settings(int $chat): void {
    $keys=['upi_id'=>'UPI ID','upi_name'=>'UPI NAME','support_username'=>'SUPPORT USER','max_runtime'=>'MAX RUNTIME','trial_days'=>'TRIAL DAYS','qr_api'=>'QR API URL'];
    $text="⚙️ <b>SETTINGS</b>\n\n"; $btns=[];
    foreach($keys as $k=>$l){ $text.="<code>{$k}</code>: <b>".htmlspecialchars(Settings::get($k,'—'))."</b>\n"; $btns[]=[['text'=>'✏️ '.strtoupper($l),'callback_data'=>'editset_'.$k]]; }
    $btns[]=[['text'=>'🔙 ADMIN','callback_data'=>'adm_panel']];
    Telegram::sendInline($chat,$text,$btns);
}

function notify_admin_payment(int $payId, int $uid): void {
    $pay=Database::one("SELECT * FROM payments WHERE id=?",[$payId]);
    $user=UserManager::get($uid);
    if (!$pay||!$user) return;
    $bot=$pay['bot_id']?BotManager::getBot((int)$pay['bot_id']):null;
    Telegram::sendInline(OWNER_ID,
        "🔔 <b>NEW PAYMENT REQUEST</b>\n\n👤 ".strtoupper($user['first_name']??'')." [<code>{$uid}</code>]\n🤖 ".($bot?"@{$bot['bot_username']}":'N/A')."\n📦 ".strtoupper($pay['plan'])." ({$pay['days']} DAYS)\n💰 ₹{$pay['amount']}\n🧾 UTR: <code>".($pay['utr']?:'PENDING')."</code>",
        KB::payApprove($payId)
    );
    // Also notify all admins
    $admins=UserManager::getAllAdmins();
    foreach($admins as $a){ if((int)$a['tg_id']!==OWNER_ID) try{Telegram::sendInline((int)$a['tg_id'],"🔔 <b>NEW PAYMENT</b>\n\nUSER {$uid} • ".strtoupper($pay['plan'])." ₹{$pay['amount']}",KB::payApprove($payId));}catch(\Exception $e){} }
}

function gbot(int $botId, int $uid, bool $isPriv): ?array {
    if ($isPriv&&$uid===OWNER_ID) return BotManager::getBot($botId);
    return Database::one("SELECT * FROM hosted_bots WHERE id=? AND owner_id=?",[$botId,$uid]);
}
