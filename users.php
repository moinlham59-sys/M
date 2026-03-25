<?php
// ============================================================
//  users.php — User/Admin Management, Logger, Settings
// ============================================================
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/telegram.php';

class UserManager {

    public static function touch(array $from): array {
        $id    = (int)$from['id'];
        $name  = htmlspecialchars(mb_substr($from['first_name'] ?? '', 0, 64), ENT_QUOTES, 'UTF-8');
        $uname = htmlspecialchars($from['username'] ?? '', ENT_QUOTES, 'UTF-8');
        $owner = ($id === OWNER_ID) ? 1 : 0;
        Database::q(
            "INSERT INTO users(tg_id,username,first_name,is_owner,last_active)
             VALUES(?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
               username=VALUES(username),first_name=VALUES(first_name),last_active=NOW(),
               is_owner=IF(tg_id=".OWNER_ID.",1,is_owner)",
            [$id, $uname, $name, $owner]
        );
        return self::get($id) ?? [];
    }

    public static function get(int $id): ?array {
        return Database::one("SELECT u.* FROM users u WHERE u.tg_id=?", [$id]);
    }

    public static function all(): array {
        return Database::all(
            "SELECT u.*,IFNULL(a.level,'') adm_level FROM users u
             LEFT JOIN admins a ON a.tg_id=u.tg_id AND a.is_active=1
             ORDER BY u.joined_at DESC"
        );
    }

    public static function count(): int { return Database::cnt('users'); }

    public static function isOwner(array $user): bool {
        return (int)($user['tg_id'] ?? 0) === OWNER_ID || (int)($user['is_owner'] ?? 0) === 1;
    }

    public static function isAdmin(array $user): bool {
        if (self::isOwner($user)) return true;
        $a = Database::one("SELECT id FROM admins WHERE tg_id=? AND is_active=1", [(int)$user['tg_id']]);
        return (bool)$a;
    }

    public static function isPremium(array $user): bool {
        if (self::isAdmin($user)) return true;
        if (!(int)($user['is_premium'] ?? 0)) return false;
        if ($user['premium_until'] && strtotime($user['premium_until']) < time()) {
            Database::q("UPDATE users SET is_premium=0,plan='free' WHERE tg_id=?", [$user['tg_id']]);
            return false;
        }
        return true;
    }

    public static function addAdmin(int $tgId, int $addedBy, string $level='admin'): array {
        $u = self::get($tgId);
        if (!$u) return [false, 'USER NOT FOUND. THEY MUST START THE BOT FIRST.'];
        if (self::isOwner($u)) return [false, 'CANNOT CHANGE OWNER ROLE.'];
        $ex = Database::one("SELECT id FROM admins WHERE tg_id=?", [$tgId]);
        if ($ex) {
            Database::q("UPDATE admins SET is_active=1,level=?,added_by=? WHERE tg_id=?", [$level, $addedBy, $tgId]);
        } else {
            Database::q("INSERT INTO admins(tg_id,added_by,level) VALUES(?,?,?)", [$tgId, $addedBy, $level]);
        }
        Database::q("UPDATE users SET is_admin=1 WHERE tg_id=?", [$tgId]);
        return [true, 'ADMIN ADDED: '.strtoupper($u['first_name'] ?? $tgId)];
    }

    public static function removeAdmin(int $tgId, int $removedBy): array {
        if ($tgId === OWNER_ID) return [false, 'CANNOT REMOVE OWNER.'];
        Database::q("UPDATE admins SET is_active=0 WHERE tg_id=?", [$tgId]);
        Database::q("UPDATE users SET is_admin=0 WHERE tg_id=?", [$tgId]);
        return [true, 'ADMIN REMOVED.'];
    }

    public static function getAllAdmins(): array {
        return Database::all(
            "SELECT a.*,u.username,u.first_name FROM admins a
             LEFT JOIN users u ON u.tg_id=a.tg_id WHERE a.is_active=1 ORDER BY a.added_at DESC"
        );
    }

    public static function ban(int $id): void   { Database::q("UPDATE users SET is_banned=1 WHERE tg_id=?", [$id]); }
    public static function unban(int $id): void  { Database::q("UPDATE users SET is_banned=0 WHERE tg_id=?", [$id]); }

    public static function setState(int $id, string $state, mixed $data=null): void {
        Database::q("UPDATE users SET state=?,state_data=? WHERE tg_id=?",
            [$state, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, $id]);
    }

    public static function clearState(int $id): void { self::setState($id, 'idle', null); }

    public static function getStateData(int $id): array {
        $u = self::get($id);
        return [
            'state' => $u['state'] ?? 'idle',
            'data'  => $u['state_data'] ? (json_decode($u['state_data'], true) ?? []) : [],
        ];
    }

    public static function grantPremium(int $id, int $days): void {
        $u     = self::get($id);
        $base  = ($u && $u['premium_until'] && strtotime($u['premium_until']) > time())
                 ? $u['premium_until'] : date('Y-m-d H:i:s');
        $until = date('Y-m-d H:i:s', strtotime("+{$days} days", strtotime($base)));
        Database::q("UPDATE users SET is_premium=1,plan='premium',premium_until=? WHERE tg_id=?", [$until, $id]);
    }

    public static function isChannelMember(int $uid): bool {
        $s = Telegram::getMemberStatus(CHANNEL_ID, $uid);
        return in_array($s, ['member','administrator','creator'], true);
    }

    public static function isRateLimited(int $id): bool {
        if ($id === OWNER_ID) return false;
        $r = Database::one("SELECT last_cmd FROM rate_limits WHERE tg_id=?", [$id]);
        if ($r && time() - strtotime($r['last_cmd']) < RATE_LIMIT_SEC) return true;
        Database::q("INSERT INTO rate_limits(tg_id) VALUES(?) ON DUPLICATE KEY UPDATE last_cmd=NOW()", [$id]);
        return false;
    }
}

// ── Settings ──────────────────────────────────────────────────
class Settings {
    private static array $c = [];

    public static function get(string $k, string $def=''): string {
        if (!array_key_exists($k, self::$c)) {
            $r = Database::one("SELECT v FROM bot_settings WHERE k=?", [$k]);
            self::$c[$k] = $r ? ($r['v'] ?? $def) : $def;
        }
        return self::$c[$k];
    }

    public static function set(string $k, string $v): void {
        self::$c[$k] = $v;
        Database::q("INSERT INTO bot_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=?", [$k, $v, $v]);
    }

    public static function isBotLocked(): bool { return (bool)(int)self::get('bot_locked', '0'); }
}

// ── Logger ─────────────────────────────────────────────────────
class Logger {
    public static function log(string $lvl, string $msg): void {
        $dir = LOGS_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        @file_put_contents(
            $dir.'/bot.log',
            '['.date('Y-m-d H:i:s').'] ['.strtoupper($lvl).'] '.mb_substr($msg, 0, 500).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
    public static function info(string $m):  void { self::log('INFO',  $m); }
    public static function error(string $m): void { self::log('ERROR', $m); }
    public static function warn(string $m):  void { self::log('WARN',  $m); }
}
