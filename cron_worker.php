<?php
// cron_worker.php — Run: */5 * * * * php /path/cron_worker.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/bot_manager.php';

$lock = QUEUE_DIR.'/cron.lock';
if (file_exists($lock) && time()-filemtime($lock) < 240) exit(0);
@file_put_contents($lock, getmypid());

try { install_schema(); } catch (\Exception $e) { Logger::error('CRON:'.$e->getMessage()); }
Logger::info('CRON START');

// 1. Sync active bots (check if process alive)
foreach (Database::all("SELECT * FROM hosted_bots WHERE status='active'") as $bot) {
    if ($bot['pid'] && !file_exists("/proc/{$bot['pid']}")) {
        Database::q("UPDATE hosted_bots SET status='stopped',pid=NULL WHERE id=?",[$bot['id']]);
        Logger::warn("BOT #{$bot['id']} PID {$bot['pid']} DIED — STOPPED");
        try { Telegram::send((int)$bot['owner_id'],"⚠️ <b>BOT STOPPED!</b>\n\n🤖 @{$bot['bot_username']}\n💀 PROCESS DIED UNEXPECTEDLY.\n\nSEND ▶️ START TO RESTART."); } catch(\Exception $e){}
    } else {
        Database::q("UPDATE hosted_bots SET last_ping=NOW() WHERE id=?",[$bot['id']]);
    }
}

// 2. Expire bots
$expired = Database::all("SELECT * FROM hosted_bots WHERE status IN ('active','stopped') AND expires_at IS NOT NULL AND expires_at < NOW()");
foreach ($expired as $bot) {
    if ($bot['pid'] && file_exists("/proc/{$bot['pid']}")) {
        posix_kill((int)$bot['pid'], SIGTERM); sleep(1);
        if (file_exists("/proc/{$bot['pid']}")) posix_kill((int)$bot['pid'], SIGKILL);
    }
    Database::q("UPDATE hosted_bots SET status='expired',pid=NULL WHERE id=?",[$bot['id']]);
    Logger::info("EXPIRED BOT #{$bot['id']} @{$bot['bot_username']}");
    try {
        Telegram::send((int)$bot['owner_id'],
            "⏰ <b>BOT HOSTING EXPIRED!</b>\n\n🤖 @{$bot['bot_username']}\n\nYOUR HOSTING HAS EXPIRED AND BOT WAS STOPPED.\n\n💳 RENEW: BOT → 🤖 MY BOTS → SELECT BOT → 💳 BUY DAYS"
        );
    } catch(\Exception $e){}
}

// 3. Warn expiring soon (every 6h, not spam)
foreach (Database::all("SELECT * FROM hosted_bots WHERE status='active' AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 24 HOUR) AND (last_ping IS NULL OR last_ping < DATE_SUB(NOW(),INTERVAL 6 HOUR))") as $bot) {
    $days = BotManager::daysLeft($bot);
    try {
        Telegram::send((int)$bot['owner_id'],
            "⚠️ <b>BOT EXPIRING SOON!</b>\n\n🤖 @{$bot['bot_username']}\n⏳ <b>{$days} DAY(S) LEFT</b>\n\n💳 RENEW NOW:\n🤖 MY BOTS → SELECT BOT → 💳 BUY DAYS"
        );
        Database::q("UPDATE hosted_bots SET last_ping=NOW() WHERE id=?",[$bot['id']]);
    } catch(\Exception $e){}
}

// 4. Expire user premium
Database::q("UPDATE users SET is_premium=0,plan='free' WHERE is_premium=1 AND premium_until IS NOT NULL AND premium_until < NOW()");

Logger::info("CRON DONE — EXPIRED_BOTS:".count($expired));
@unlink($lock);
