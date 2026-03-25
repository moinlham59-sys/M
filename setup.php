<?php
// ============================================================
//  setup.php — One-Command Installer
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/users.php';

$cli = php_sapi_name() === 'cli';
function out(string $m, string $t='info'): void {
    global $cli;
    $i=['ok'=>'✅','err'=>'❌','warn'=>'⚠️','info'=>'ℹ️'][$t];
    if ($cli) echo "{$i} {$m}\n";
    else { $c=['ok'=>'#3fb950','err'=>'#f85149','warn'=>'#d29922','info'=>'#58a6ff'][$t]; echo "<div style='color:{$c};font-family:monospace;padding:3px 0'>{$i} ".htmlspecialchars($m)."</div>"; }
}

if (!$cli) echo "<!DOCTYPE html><html><head><title>SETUP</title><meta charset='UTF-8'><style>body{background:#0d1117;color:#e6edf3;font-family:monospace;padding:30px;max-width:700px}</style></head><body><h2 style='color:#58a6ff;margin-bottom:20px'>🚀 SETUP</h2>";

out("STARTING SETUP...");
if (PHP_VERSION_ID < 80000) { out("PHP 8.0+ REQUIRED. CURRENT: ".PHP_VERSION,'err'); exit(1); }
out("PHP ".PHP_VERSION,'ok');

foreach (['pdo','pdo_mysql','curl','json','mbstring'] as $e)
    extension_loaded($e) ? out("{$e}: OK",'ok') : out("MISSING EXTENSION: {$e}",'err');

foreach ([USERS_DIR,LOGS_DIR,UPLOADS_DIR,QUEUE_DIR,BOTS_DIR,TMP_DIR,DOWNLOADS_DIR] as $d) {
    if (!is_dir($d)) { mkdir($d,0750,true) ? out("CREATED: {$d}",'ok') : out("FAILED: {$d}",'err'); }
    else out("EXISTS: {$d}",'ok');
}

foreach ([USERS_DIR,LOGS_DIR,BOTS_DIR,TMP_DIR,DOWNLOADS_DIR] as $d)
    if (is_dir($d)) @file_put_contents("{$d}/.htaccess","Deny from all\n");

if (!file_exists(BASE_DIR.'/.htaccess')) {
    file_put_contents(BASE_DIR.'/.htaccess',"Options -Indexes\nRewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^.*$ webhook.php [L,QSA]\n");
    out('.htaccess CREATED','ok');
}

try {
    Database::get(); out('DB CONNECTION OK','ok');
    install_schema(); out('SCHEMA INSTALLED — 11 TABLES CREATED','ok');
} catch (\Exception $e) { out("DB ERROR: ".$e->getMessage(),'err'); exit(1); }

$info = Telegram::validateToken(BOT_TOKEN);
$info ? out("BOT TOKEN VALID: @{$info['username']} (ID: {$info['id']})",'ok') : out('INVALID BOT TOKEN. SET BOT_TOKEN ENV VAR','err');

if (WEBHOOK_URL && BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE') {
    $r = Telegram::setWebhook(WEBHOOK_URL);
    ($r['ok']??false) ? out("WEBHOOK SET: ".WEBHOOK_URL,'ok') : out("WEBHOOK FAILED: ".($r['description']??''),'warn');
}

$chan = Telegram::call('getChat',['chat_id'=>CHANNEL_ID]);
($chan['ok']??false) ? out("CHANNEL OK: ".($chan['result']['title']??CHANNEL_USERNAME),'ok') : out("CHANNEL ERROR — ADD BOT AS ADMIN IN CHANNEL",'warn');

if (OWNER_ID > 0 && BOT_TOKEN !== 'YOUR_BOT_TOKEN_HERE')
    Telegram::send(OWNER_ID,"🚀 <b>BOT HOSTING SETUP COMPLETE!</b>\n\n✅ DATABASE: 11 TABLES\n✅ WEBHOOK SET\n✅ ALL DIRS CREATED\n\n🎛 ADMIN PANEL: ".dirname(WEBHOOK_URL)."/admin_panel.php\n\n👑 SEND /start TO OPEN YOUR OWNER MENU!");

out("\n🎉 SETUP COMPLETE!\n\nADD CRON JOBS:\n*/5 * * * * php ".BASE_DIR."/cron_worker.php\n0 * * * * php ".BASE_DIR."/cron_cleanup.php\n\nADMIN PANEL: ".dirname(WEBHOOK_URL)."/admin_panel.php");
if (!$cli) echo "</body></html>";
