<?php
// ============================================================
//  bot_manager.php — Bot Hosting + Payments + Redeem + Statements
//  Hosting: PHP, Python, Node.js, Shell, HTML, CSS
// ============================================================
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/users.php';

class BotManager {

    // ── Add bot ───────────────────────────────────────────────
    public static function addBot(int $ownerId, string $token, string $plan='trial'): array {
        $token = trim($token);
        if (!preg_match('/^\d{8,12}:[A-Za-z0-9_\-]{35,}$/', $token))
            return [false, 'INVALID TOKEN FORMAT. EXAMPLE: 123456789:ABCdef...'];

        $exists = Database::one("SELECT id,owner_id FROM hosted_bots WHERE bot_token=?", [$token]);
        if ($exists) {
            return [false, (int)$exists['owner_id'] === $ownerId
                ? 'BOT ALREADY IN YOUR ACCOUNT.'
                : 'TOKEN ALREADY REGISTERED BY ANOTHER USER.'];
        }

        $info = Telegram::validateToken($token);
        if (!$info) return [false, 'INVALID BOT TOKEN. CHECK AND TRY AGAIN.'];

        $days = (int)Settings::get('trial_days', '1');
        $exp  = ($plan === 'trial') ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;

        $bid = Database::insert(
            "INSERT INTO hosted_bots(owner_id,bot_token,bot_username,bot_name,bot_tg_id,status,plan,days_granted,expires_at)
             VALUES(?,?,?,?,?,?,?,?,?)",
            [$ownerId, $token, $info['username']??'', $info['first_name']??'', $info['id']??0,
             'stopped', $plan, ($plan==='trial')?$days:0, $exp]
        );
        self::logAct($bid, $ownerId, 'ADDED', '');
        return [true, $bid, $info];
    }

    // ── Remove bot ────────────────────────────────────────────
    public static function removeBot(int $botId, int $actorId): array {
        $bot = self::getBot($botId);
        if (!$bot) return [false, 'BOT NOT FOUND'];
        if ((int)$bot['owner_id'] !== $actorId && $actorId !== OWNER_ID)
            return [false, 'PERMISSION DENIED'];

        self::stopBot($botId);
        try { Telegram::deleteWebhook($bot['bot_token']); } catch (\Exception $e) {}

        $dir = BOTS_DIR.'/'.$botId;
        if (is_dir($dir)) self::rmrec($dir);

        Database::q("DELETE FROM hosted_bots WHERE id=?",      [$botId]);
        Database::q("DELETE FROM bot_activity_log WHERE bot_id=?", [$botId]);
        Database::q("DELETE FROM user_files WHERE bot_id=?",    [$botId]);
        return [true, 'BOT REMOVED SUCCESSFULLY.'];
    }

    // ── Start bot ─────────────────────────────────────────────
    public static function startBot(int $botId): array {
        $bot = self::getBot($botId);
        if (!$bot) return [false, 'BOT NOT FOUND'];

        if ($bot['expires_at'] && strtotime($bot['expires_at']) < time()) {
            Database::q("UPDATE hosted_bots SET status='expired' WHERE id=?", [$botId]);
            return [false, 'HOSTING EXPIRED. RENEW YOUR PLAN.'];
        }

        if (!$bot['script_file']) return [false, 'NO SCRIPT UPLOADED YET. UPLOAD A SCRIPT FIRST.'];

        $scriptPath = BOTS_DIR.'/'.$botId.'/'.$bot['script_file'];
        if (!file_exists($scriptPath)) return [false, 'SCRIPT FILE MISSING ON SERVER. PLEASE RE-UPLOAD.'];

        // Kill existing process
        if ($bot['pid'] && file_exists("/proc/{$bot['pid']}")) {
            posix_kill((int)$bot['pid'], SIGTERM);
            sleep(1);
            if (file_exists("/proc/{$bot['pid']}")) posix_kill((int)$bot['pid'], SIGKILL);
        }

        $logFile = BOTS_DIR.'/'.$botId.'/bot.log';
        $esc     = escapeshellarg($scriptPath);
        $lang    = $bot['script_lang'];

        // Build run command based on language
        $cmd = match($lang) {
            'python' => PYTHON_BIN." -u {$esc}",
            'php'    => PHP_BIN." {$esc}",
            'node'   => NODE_BIN." {$esc}",
            'shell'  => BASH_BIN." {$esc}",
            'html'   => PHP_BIN." -S 0.0.0.0:808".($botId % 90 + 10)." -t ".escapeshellarg(BOTS_DIR.'/'.$botId),
            'css'    => null, // CSS doesn't run standalone
            default  => PYTHON_BIN." -u {$esc}",
        };

        if ($lang === 'css') {
            return [false, 'CSS FILES CANNOT RUN STANDALONE. INCLUDE CSS IN AN HTML FILE.'];
        }

        // Run in background
        $pid = (int)shell_exec(
            "nohup {$cmd} >> ".escapeshellarg($logFile)." 2>&1 & echo \$!"
        );

        if ($pid > 0) {
            Database::q(
                "UPDATE hosted_bots SET status='active',pid=?,last_ping=NOW() WHERE id=?",
                [$pid, $botId]
            );
            self::logAct($botId, (int)$bot['owner_id'], 'STARTED', "PID:{$pid}");
            return [true, "BOT STARTED! PID: {$pid}"];
        }

        Database::q("UPDATE hosted_bots SET status='error' WHERE id=?", [$botId]);
        return [false, 'FAILED TO START PROCESS. CHECK SERVER PERMISSIONS.'];
    }

    // ── Stop bot ──────────────────────────────────────────────
    public static function stopBot(int $botId): array {
        $bot = self::getBot($botId);
        if (!$bot) return [false, 'BOT NOT FOUND'];

        if ($bot['pid'] && file_exists("/proc/{$bot['pid']}")) {
            posix_kill((int)$bot['pid'], SIGTERM);
            sleep(1);
            if (file_exists("/proc/{$bot['pid']}")) posix_kill((int)$bot['pid'], SIGKILL);
        }
        Database::q("UPDATE hosted_bots SET status='stopped',pid=NULL WHERE id=?", [$botId]);
        self::logAct($botId, (int)$bot['owner_id'], 'STOPPED', '');
        return [true, 'BOT STOPPED.'];
    }

    public static function restartBot(int $botId): array {
        self::stopBot($botId); sleep(1); return self::startBot($botId);
    }

    // ── Grant days ────────────────────────────────────────────
    public static function grantDays(int $botId, int $days, int $adminId, string $reason='ADMIN'): array {
        $bot = self::getBot($botId);
        if (!$bot) return [false, 'BOT NOT FOUND'];
        if ($days <= 0) return [false, 'DAYS MUST BE > 0'];

        $base = ($bot['expires_at'] && strtotime($bot['expires_at']) > time())
                ? $bot['expires_at'] : date('Y-m-d H:i:s');
        $exp  = date('Y-m-d H:i:s', strtotime("+{$days} days", strtotime($base)));

        Database::q(
            "UPDATE hosted_bots SET expires_at=?,days_granted=days_granted+?,
             status=IF(status='expired','stopped',status) WHERE id=?",
            [$exp, $days, $botId]
        );
        Database::q(
            "INSERT INTO day_grants(admin_id,target_id,bot_id,days,reason) VALUES(?,?,?,?,?)",
            [$adminId, $bot['owner_id'], $botId, $days, $reason]
        );
        self::logAct($botId, $adminId, 'DAYS_GRANTED', "+{$days}D");
        return [true, $exp];
    }

    public static function grantDaysByUser(int $tgId, int $days, int $adminId, string $reason='ADMIN'): array {
        $bots = self::getUserBots($tgId);
        if (!$bots) {
            UserManager::grantPremium($tgId, $days);
            Database::q("INSERT INTO day_grants(admin_id,target_id,bot_id,days,reason) VALUES(?,?,NULL,?,?)",
                [$adminId, $tgId, $days, $reason]);
            return [true, 0];
        }
        $cnt = 0;
        foreach ($bots as $b) {
            [$ok] = self::grantDays((int)$b['id'], $days, $adminId, $reason);
            if ($ok) $cnt++;
        }
        UserManager::grantPremium($tgId, $days);
        return [true, $cnt];
    }

    public static function removeDays(int $botId, int $days, int $adminId): array {
        $bot = self::getBot($botId);
        if (!$bot)             return [false, 'BOT NOT FOUND'];
        if (!$bot['expires_at']) return [false, 'NO EXPIRY SET'];

        $new = date('Y-m-d H:i:s', max(time(), strtotime($bot['expires_at']) - ($days * 86400)));
        Database::q("UPDATE hosted_bots SET expires_at=? WHERE id=?", [$new, $botId]);

        if (strtotime($new) <= time()) {
            self::stopBot($botId);
            Database::q("UPDATE hosted_bots SET status='expired' WHERE id=?", [$botId]);
        }
        self::logAct($botId, $adminId, 'DAYS_REMOVED', "-{$days}D");
        return [true, $new];
    }

    // ── Upload script ─────────────────────────────────────────
    public static function uploadScript(int $botId, string $fn, string $content, string $lang='python'): array {
        $bot = self::getBot($botId);
        if (!$bot) return [false, 'BOT NOT FOUND'];
        if (strlen($content) > MAX_FILE_SIZE) return [false, 'FILE TOO LARGE (MAX 5MB)'];

        $safe = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $fn);
        $dir  = BOTS_DIR.'/'.$botId;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);

        $path = "{$dir}/{$safe}";
        file_put_contents($path, $content, LOCK_EX);
        if (in_array($lang, ['python','shell','php','node'])) chmod($path, 0750);

        Database::q("UPDATE hosted_bots SET script_file=?,script_lang=? WHERE id=?", [$safe, $lang, $botId]);

        // Track in user_files
        Database::q(
            "INSERT INTO user_files(user_id,bot_id,filename,filepath,filetype,filesize)
             VALUES(?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE filepath=VALUES(filepath),filesize=VALUES(filesize),uploaded_at=NOW()",
            [(int)$bot['owner_id'], $botId, $safe, $path, 'script', strlen($content)]
        );
        self::logAct($botId, (int)$bot['owner_id'], 'SCRIPT_UPLOADED', $safe);
        return [true, $path];
    }

    // ── Get log ───────────────────────────────────────────────
    public static function getLog(int $botId, int $lines=50): string {
        $f = BOTS_DIR.'/'.$botId.'/bot.log';
        if (!file_exists($f)) return 'NO LOG FILE YET.';
        $all = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return implode("\n", array_slice($all, -$lines)) ?: 'LOG IS EMPTY.';
    }

    // ── Sync status ───────────────────────────────────────────
    public static function syncStatus(int $botId): void {
        $bot = self::getBot($botId);
        if (!$bot) return;
        if ($bot['status'] === 'active' && $bot['pid'] && !file_exists("/proc/{$bot['pid']}")) {
            Database::q("UPDATE hosted_bots SET status='stopped',pid=NULL WHERE id=?", [$botId]);
        }
        if ($bot['expires_at'] && strtotime($bot['expires_at']) < time() && $bot['status'] !== 'expired') {
            self::stopBot($botId);
            Database::q("UPDATE hosted_bots SET status='expired' WHERE id=?", [$botId]);
        }
    }

    // ── File management ───────────────────────────────────────
    public static function getUserFiles(int $userId): array {
        return Database::all(
            "SELECT uf.*,hb.bot_username FROM user_files uf
             LEFT JOIN hosted_bots hb ON hb.id=uf.bot_id
             WHERE uf.user_id=? ORDER BY uf.uploaded_at DESC",
            [$userId]
        );
    }

    public static function sendFileTg(int $chat, int $userId, int $fileId): bool {
        $f = Database::one("SELECT * FROM user_files WHERE id=? AND user_id=?", [$fileId, $userId]);
        if (!$f) return false;
        if (!file_exists($f['filepath'])) {
            Telegram::send($chat, "❌ FILE NOT FOUND ON SERVER: <code>".htmlspecialchars($f['filename'])."</code>");
            return false;
        }
        $sz = number_format($f['filesize'] / 1024, 1);
        Telegram::sendDoc($chat, $f['filepath'],
            "📁 <b>".htmlspecialchars($f['filename'])."</b>\n".
            "📦 SIZE: {$sz} KB\n".
            "📅 UPLOADED: ".date('d M Y H:i', strtotime($f['uploaded_at']))
        );
        return true;
    }

    // ── STATEMENTS ────────────────────────────────────────────
    public static function userStatement(int $userId): string {
        $u = UserManager::get($userId);
        if (!$u) return '❌ USER NOT FOUND';

        $bots     = self::getUserBots($userId);
        $pays     = Database::all("SELECT * FROM payments WHERE user_id=? ORDER BY created_at DESC LIMIT 10", [$userId]);
        $redeems  = Database::all("SELECT rc.*,hb.bot_username FROM redeem_codes rc LEFT JOIN hosted_bots hb ON hb.id=rc.bot_id WHERE rc.used_by=? ORDER BY rc.used_at DESC LIMIT 5", [$userId]);
        $compiles = Database::cnt('compile_log', 'user_id=?', [$userId]);
        $files    = Database::cnt('user_files', 'user_id=?', [$userId]);
        $isAdm    = UserManager::isAdmin($u);

        $sep  = str_repeat('─', 30);
        $s    = "📋 <b>USER STATEMENT</b>\n{$sep}\n";
        $s   .= "👤 NAME: <b>".htmlspecialchars(strtoupper($u['first_name']??'N/A'))."</b>\n";
        $s   .= "🆔 TELEGRAM ID: <code>{$userId}</code>\n";
        $s   .= "📛 USERNAME: @".($u['username']??'N/A')."\n";
        $s   .= "🔐 PLAN: <b>".strtoupper($u['plan']??'FREE')."</b>\n";
        $s   .= "👮 ROLE: <b>".($isAdm?'ADMIN':'USER')."</b>\n";
        if ($u['premium_until']) $s .= "📆 PREMIUM UNTIL: ".date('d M Y', strtotime($u['premium_until']))."\n";
        $s   .= "📅 JOINED: ".date('d M Y', strtotime($u['joined_at']))."\n";
        $s   .= "🕐 LAST ACTIVE: ".date('d M Y H:i', strtotime($u['last_active']))."\n";
        $s   .= "💻 TOTAL COMPILES: {$compiles}\n";
        $s   .= "📁 TOTAL FILES: {$files}\n";
        $s   .= "{$sep}\n";
        $s   .= "🤖 <b>HOSTED BOTS (".count($bots).")</b>\n";
        if ($bots) {
            foreach ($bots as $b) {
                $d = self::daysLeft($b);
                $s .= "  • @".($b['bot_username']??'—')." — ".self::badge($b)." — {$d}D\n";
            }
        } else $s .= "  NO BOTS\n";
        $s .= "{$sep}\n";
        $s .= "💳 <b>RECENT PAYMENTS</b>\n";
        if ($pays) {
            foreach ($pays as $p) {
                $s .= "  • ".date('d M Y', strtotime($p['created_at']))." — ".strtoupper($p['plan'])." ₹{$p['amount']} — ".strtoupper($p['status'])."\n";
            }
        } else $s .= "  NO PAYMENTS\n";
        $s .= "{$sep}\n";
        $s .= "🔑 <b>REDEEMED CODES</b>\n";
        if ($redeems) {
            foreach ($redeems as $c) {
                $s .= "  • ".($c['used_at']?date('d M Y',strtotime($c['used_at'])):'—')." — {$c['days']}D\n";
            }
        } else $s .= "  NO CODES REDEEMED\n";
        return $s;
    }

    public static function botStatement(int $botId): string {
        $bot = self::getBot($botId);
        if (!$bot) return '❌ BOT NOT FOUND';

        self::syncStatus($botId);
        $bot   = self::getBot($botId);
        $owner = UserManager::get((int)$bot['owner_id']);
        $days  = self::daysLeft($bot);
        $alive = self::isAlive($botId) ? '✅ ALIVE' : '💀 DEAD';
        $grants= Database::all("SELECT * FROM day_grants WHERE bot_id=? ORDER BY granted_at DESC LIMIT 5", [$botId]);
        $logs  = Database::all("SELECT * FROM bot_activity_log WHERE bot_id=? ORDER BY created_at DESC LIMIT 8", [$botId]);
        $files = Database::all("SELECT * FROM user_files WHERE bot_id=?", [$botId]);

        $sep = str_repeat('─', 30);
        $s   = "🤖 <b>BOT STATEMENT</b>\n{$sep}\n";
        $s  .= "📛 BOT: @".($bot['bot_username']??'N/A')."\n";
        $s  .= "🆔 DB ID: #{$botId}\n";
        $s  .= "🤖 TG ID: ".($bot['bot_tg_id']??'N/A')."\n";
        $s  .= "👤 OWNER: @".($owner['username']??$bot['owner_id'])." [<code>{$bot['owner_id']}</code>]\n";
        $s  .= "📊 STATUS: ".self::badge($bot)."\n";
        $s  .= "🔍 PROCESS: {$alive} (PID: ".($bot['pid']?:'N/A').")\n";
        $s  .= "📅 PLAN: ".strtoupper($bot['plan'])."\n";
        $s  .= "⏳ DAYS LEFT: <b>{$days}</b>\n";
        $s  .= "📆 EXPIRES: ".($bot['expires_at']?date('d M Y H:i',strtotime($bot['expires_at'])):'NOT SET')."\n";
        $s  .= "📁 SCRIPT: ".($bot['script_file']?:'NONE')."\n";
        $s  .= "🔤 LANG: ".strtoupper($bot['script_lang'])."\n";
        $s  .= "📅 TOTAL DAYS GRANTED: {$bot['days_granted']}\n";
        $s  .= "📅 ADDED: ".date('d M Y', strtotime($bot['added_at']))."\n";
        $s  .= "{$sep}\n";
        $s  .= "📋 <b>RECENT GRANTS</b>\n";
        if ($grants) {
            foreach ($grants as $g) $s .= "  • ".date('d M Y',strtotime($g['granted_at']))." +{$g['days']}D — ".($g['reason']??'—')."\n";
        } else $s .= "  NO GRANTS\n";
        $s  .= "{$sep}\n";
        $s  .= "📦 <b>FILES</b>\n";
        if ($files) {
            foreach ($files as $f) $s .= "  • ".htmlspecialchars($f['filename'])." (".number_format($f['filesize']/1024,1)."KB)\n";
        } else $s .= "  NO FILES\n";
        $s  .= "{$sep}\n";
        $s  .= "🔔 <b>RECENT ACTIVITY</b>\n";
        if ($logs) {
            foreach ($logs as $l) $s .= "  • ".date('d M H:i',strtotime($l['created_at']))." ".strtoupper($l['action']??'')."\n";
        } else $s .= "  NO ACTIVITY\n";
        return $s;
    }

    public static function vpsStatement(): string {
        $srv  = self::serverStats();
        $vps  = Database::one("SELECT * FROM vps_info WHERE id=1");
        $bots = Database::all("SELECT status,COUNT(*) c FROM hosted_bots GROUP BY status");
        $bm   = [];
        foreach ($bots as $b) $bm[$b['status']] = (int)$b['c'];
        $load = trim(shell_exec('cat /proc/loadavg 2>/dev/null') ?? 'N/A');
        $sep  = str_repeat('─', 30);

        $s  = "🖥 <b>VPS STATEMENT</b>\n{$sep}\n";
        if ($vps) {
            $s .= "🏷 LABEL: ".($vps['label']??'MAIN VPS')."\n";
            $s .= "🌐 IP: ".($vps['ip_address']??'N/A')."\n";
            $s .= "📍 LOCATION: ".($vps['location']??'N/A')."\n";
            $s .= "🖥 OS: ".($vps['os_info']??'N/A')."\n";
            $s .= "💾 RAM: ".($vps['ram_gb']??'?')."GB\n";
            $s .= "💿 DISK: ".($vps['disk_gb']??'?')."GB\n";
            $s .= "🔌 STATUS: ".strtoupper($vps['status']??'online')."\n";
        }
        $s .= "{$sep}\n";
        $s .= "📊 <b>LIVE STATS</b>\n";
        $s .= "💾 RAM USED: {$srv['ram_used']}MB / {$srv['ram_tot']}MB ({$srv['ram_pct']}%)\n";
        $s .= "💿 DISK: {$srv['disk']}\n";
        $s .= "⏱ UPTIME: {$srv['uptime']}\n";
        $s .= "📈 LOAD AVG: {$load}\n";
        $s .= "{$sep}\n";
        $s .= "📦 <b>HOSTING STATS</b>\n";
        $s .= "👥 TOTAL USERS: ".UserManager::count()."\n";
        $s .= "🤖 TOTAL BOTS: ".array_sum($bm)."\n";
        $s .= "🟢 ACTIVE: ".($bm['active']??0)."\n";
        $s .= "🔴 STOPPED: ".($bm['stopped']??0)."\n";
        $s .= "⏰ EXPIRED: ".($bm['expired']??0)."\n";
        $s .= "🚫 BANNED: ".($bm['banned']??0)."\n";
        $s .= "💻 COMPILES: ".Database::cnt('compile_log')."\n";
        $s .= "{$sep}\n";
        $s .= "🕐 GENERATED: ".date('d M Y H:i:s')."\n";
        return $s;
    }

    // ── Getters ───────────────────────────────────────────────
    public static function getBot(int $id): ?array {
        return Database::one("SELECT * FROM hosted_bots WHERE id=?", [$id]);
    }

    public static function getUserBots(int $ownerId): array {
        return Database::all("SELECT * FROM hosted_bots WHERE owner_id=? ORDER BY added_at DESC", [$ownerId]);
    }

    public static function getAllBots(int $limit=200): array {
        return Database::all(
            "SELECT b.*,u.username,u.first_name FROM hosted_bots b
             LEFT JOIN users u ON u.tg_id=b.owner_id ORDER BY b.added_at DESC LIMIT ?",
            [$limit]
        );
    }

    public static function getExpiringSoon(int $hours=24): array {
        return Database::all(
            "SELECT b.*,u.username FROM hosted_bots b LEFT JOIN users u ON u.tg_id=b.owner_id
             WHERE b.status='active' AND b.expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL ? HOUR)",
            [$hours]
        );
    }

    // ── Helpers ───────────────────────────────────────────────
    public static function daysLeft(array $bot): int {
        if (!$bot['expires_at']) return 0;
        return max(0, (int)ceil((strtotime($bot['expires_at']) - time()) / 86400));
    }

    public static function isAlive(int $botId): bool {
        $b = self::getBot($botId);
        return $b && $b['pid'] && file_exists("/proc/{$b['pid']}");
    }

    public static function badge(array $bot): string {
        return match($bot['status']) {
            'active'  => '🟢 ACTIVE',
            'stopped' => '🔴 STOPPED',
            'expired' => '⏰ EXPIRED',
            'error'   => '❌ ERROR',
            'banned'  => '🚫 BANNED',
            default   => '⚫ UNKNOWN',
        };
    }

    public static function serverStats(): array {
        exec("free -m 2>/dev/null | awk 'NR==2{printf \"%d %d\",$3,$2}'", $r);
        exec("uptime -p 2>/dev/null", $up);
        exec("df -h / 2>/dev/null | tail -1 | awk '{print $3\"/\"$2\" \"$5}'", $dk);
        $parts = explode(' ', trim($r[0] ?? '0 1'));
        $used  = (int)($parts[0] ?? 0);
        $tot   = (int)($parts[1] ?? 1);
        return [
            'ram_used' => $used,
            'ram_tot'  => $tot,
            'ram_pct'  => $tot > 0 ? round($used / $tot * 100) : 0,
            'disk'     => strtoupper(trim($dk[0] ?? 'N/A')),
            'uptime'   => strtoupper(trim($up[0] ?? 'N/A')),
        ];
    }

    private static function logAct(int $botId, int $uid, string $action, string $detail=''): void {
        try {
            Database::q(
                "INSERT INTO bot_activity_log(bot_id,user_id,action,detail) VALUES(?,?,?,?)",
                [$botId, $uid, $action, $detail]
            );
        } catch (\Exception $e) {}
    }

    private static function rmrec(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath()); }
        @rmdir($dir);
    }
    // Aliases used by webhook.php
    public static function getUserStatement(int $uid): string { return self::userStatement($uid); }
    public static function getBotStatement(int $bid): string  { return self::botStatement($bid); }
    public static function getVpsStatement(): string           { return self::vpsStatement(); }
    public static function sendFileToUser(int $chat, int $uid, int $fid): bool { return self::sendFileTg($chat, $uid, $fid); }
}

// ── PaymentManager ────────────────────────────────────────────
class PaymentManager {

    public static function create(int $uid, ?int $bid, string $plan, int $days, float $amt): int {
        return Database::insert(
            "INSERT INTO payments(user_id,bot_id,plan,days,amount) VALUES(?,?,?,?,?)",
            [$uid, $bid, $plan, $days, $amt]
        );
    }

    public static function saveSS(int $pid, string $fid): void {
        Database::q("UPDATE payments SET screenshot=? WHERE id=?", [$fid, $pid]);
    }

    public static function saveUTR(int $pid, string $utr): void {
        Database::q("UPDATE payments SET utr=? WHERE id=?", [$utr, $pid]);
    }

    public static function approve(int $pid, int $adminId): ?array {
        $p = Database::one("SELECT * FROM payments WHERE id=?", [$pid]);
        if (!$p || $p['status'] !== 'pending') return null;
        Database::q("UPDATE payments SET status='approved',reviewed_at=NOW(),reviewed_by=? WHERE id=?", [$adminId, $pid]);
        if ($p['bot_id']) BotManager::grantDays((int)$p['bot_id'], (int)$p['days'], $adminId, 'PAYMENT APPROVED');
        UserManager::grantPremium((int)$p['user_id'], (int)$p['days']);
        return $p;
    }

    public static function reject(int $pid, int $adminId): ?array {
        $p = Database::one("SELECT * FROM payments WHERE id=?", [$pid]);
        if (!$p) return null;
        Database::q("UPDATE payments SET status='rejected',reviewed_at=NOW(),reviewed_by=? WHERE id=?", [$adminId, $pid]);
        return $p;
    }

    public static function pending(): array {
        return Database::all(
            "SELECT p.*,u.username,u.first_name,b.bot_username FROM payments p
             LEFT JOIN users u ON u.tg_id=p.user_id
             LEFT JOIN hosted_bots b ON b.id=p.bot_id
             WHERE p.status='pending' ORDER BY p.created_at ASC LIMIT 30"
        );
    }

    public static function getPlans(): array {
        return Database::all("SELECT * FROM hosting_plans WHERE is_active=1 ORDER BY days ASC");
    }

    // Generate UPI QR URL
    public static function qrUrl(float $amount, string $plan): string {
        $upiId   = Settings::get('upi_id',    UPI_ID);
        $upiName = urlencode(Settings::get('upi_name', UPI_NAME));
        $note    = urlencode("BOT HOSTING - ".strtoupper($plan));
        $upiLink = "upi://pay?pa={$upiId}&pn={$upiName}&am={$amount}&tn={$note}&cu=INR";
        $apiBase = Settings::get('qr_api', 'https://api.qrserver.com/v1/create-qr-code/?size=350x350&data=');
        return $apiBase . urlencode($upiLink);
    }

    public static function getQrUrl(float $amount, string $plan): string { return self::qrUrl($amount, $plan); }

    // Payment text with UPI details
    public static function paymentText(array $plan): string {
        $upiId   = Settings::get('upi_id',   UPI_ID);
        $upiName = Settings::get('upi_name',  UPI_NAME);
        $upiPhone= Settings::get('upi_phone', UPI_PHONE);
        $amount  = (float)$plan['price'];
        return
            "💳 <b>PAYMENT DETAILS</b>\n\n".
            "📦 PLAN: <b>".strtoupper($plan['name'])."</b>\n".
            "📅 DURATION: <b>{$plan['days']} DAYS</b>\n".
            "💰 AMOUNT: <b>₹{$amount}</b>\n\n".
            "━━━━━━━━━━━━━━━━━━━━\n".
            "📲 <b>UPI PAYMENT:</b>\n".
            "🏦 UPI ID: <code>{$upiId}</code>\n".
            "👤 NAME: {$upiName}\n".
            "📱 PHONE: {$upiPhone}\n".
            "━━━━━━━━━━━━━━━━━━━━\n\n".
            "✅ <b>AFTER PAYING:</b>\n".
            "1️⃣ TAKE A SCREENSHOT\n".
            "2️⃣ SEND SCREENSHOT HERE\n".
            "3️⃣ SEND UTR/TRANSACTION ID\n\n".
            "⏳ ACTIVATED WITHIN 30 MINUTES";
    }
}

// ── RedeemManager ─────────────────────────────────────────────
class RedeemManager {

    public static function generate(string $plan, int $days, int $count=1, int $expDays=30): array {
        $codes = [];
        $until = date('Y-m-d H:i:s', strtotime("+{$expDays} days"));
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper('HOST-'.bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(3)));
            try {
                Database::q("INSERT INTO redeem_codes(code,plan,days,expires_at) VALUES(?,?,?,?)",
                    [$code, $plan, $days, $until]);
                $codes[] = $code;
            } catch (\Exception $e) {}
        }
        return $codes;
    }

    public static function redeem(string $code, int $userId, ?int $botId): array {
        $r = Database::one("SELECT * FROM redeem_codes WHERE code=?", [strtoupper(trim($code))]);
        if (!$r)            return [false, 'INVALID REDEEM CODE'];
        if ($r['used_by'])  return [false, 'CODE ALREADY USED'];
        if ($r['expires_at'] && strtotime($r['expires_at']) < time()) return [false, 'CODE HAS EXPIRED'];

        Database::q("UPDATE redeem_codes SET used_by=?,bot_id=?,used_at=NOW() WHERE code=?",
            [$userId, $botId, strtoupper(trim($code))]);

        if ($botId) {
            [$ok, $exp] = BotManager::grantDays($botId, (int)$r['days'], $userId, "REDEEM CODE");
            if (!$ok) return [false, $exp];
        }
        UserManager::grantPremium($userId, (int)$r['days']);
        return [true, (int)$r['days'], $r['plan']];
    }

    public static function listUnused(): array {
        return Database::all(
            "SELECT * FROM redeem_codes
             WHERE used_by IS NULL AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY id DESC LIMIT 50"
        );
    }
}

