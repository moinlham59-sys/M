<?php
// ============================================================
//  database.php — PDO Layer + Complete Schema
// ============================================================
require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $pdo = null;

    public static function get(): PDO {
        if (!self::$pdo) {
            self::$pdo = new PDO(
                'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
            );
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $p=[]): \PDOStatement {
        $st = self::get()->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public static function one(string $sql, array $p=[]): ?array {
        $r = self::q($sql,$p)->fetch();
        return $r ?: null;
    }

    public static function all(string $sql, array $p=[]): array {
        return self::q($sql,$p)->fetchAll();
    }

    public static function insert(string $sql, array $p=[]): int {
        self::q($sql,$p);
        return (int)self::get()->lastInsertId();
    }

    public static function cnt(string $t, string $w='1', array $p=[]): int {
        return (int)(self::one("SELECT COUNT(*) c FROM `{$t}` WHERE {$w}", $p)['c'] ?? 0);
    }
}

// ── Full Schema ────────────────────────────────────────────────
function install_schema(): void {
    $db = Database::get();

    $db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tg_id         BIGINT UNIQUE NOT NULL,
        username      VARCHAR(64),
        first_name    VARCHAR(128),
        is_owner      TINYINT(1) DEFAULT 0,
        is_admin      TINYINT(1) DEFAULT 0,
        is_banned     TINYINT(1) DEFAULT 0,
        is_premium    TINYINT(1) DEFAULT 0,
        premium_until DATETIME NULL,
        plan          VARCHAR(32) DEFAULT 'free',
        joined_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_active   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        state         VARCHAR(64) DEFAULT 'idle',
        state_data    TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS admins (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tg_id      BIGINT UNIQUE NOT NULL,
        added_by   BIGINT NOT NULL,
        level      ENUM('admin','moderator') DEFAULT 'admin',
        added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_active  TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS hosted_bots (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_id     BIGINT NOT NULL,
        bot_token    VARCHAR(200) NOT NULL,
        bot_username VARCHAR(64) NULL,
        bot_name     VARCHAR(128) NULL,
        bot_tg_id    BIGINT NULL,
        status       ENUM('active','stopped','expired','error','banned') DEFAULT 'stopped',
        plan         VARCHAR(32) DEFAULT 'trial',
        days_granted INT DEFAULT 0,
        added_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at   DATETIME NULL,
        last_ping    DATETIME NULL,
        pid          INT NULL,
        script_file  VARCHAR(255) NULL,
        script_lang  ENUM('python','php','node','shell','html','css') DEFAULT 'python',
        notes        TEXT NULL,
        error_log    TEXT NULL,
        INDEX(owner_id), INDEX(status), INDEX(expires_at),
        UNIQUE KEY uq_tok (bot_token(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS hosting_plans (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(64) NOT NULL,
        days        INT NOT NULL DEFAULT 30,
        price       DECIMAL(10,2) DEFAULT 0.00,
        max_bots    INT DEFAULT 1,
        description TEXT NULL,
        is_active   TINYINT(1) DEFAULT 1,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS day_grants (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id   BIGINT NOT NULL,
        target_id  BIGINT NOT NULL,
        bot_id     INT UNSIGNED NULL,
        days       INT NOT NULL,
        reason     VARCHAR(255) NULL,
        granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(target_id), INDEX(bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS payments (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     BIGINT NOT NULL,
        bot_id      INT UNSIGNED NULL,
        amount      DECIMAL(10,2) DEFAULT 0.00,
        plan        VARCHAR(32) NOT NULL,
        days        INT DEFAULT 0,
        utr         VARCHAR(64) NULL,
        screenshot  VARCHAR(255) NULL,
        status      ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,
        reviewed_by BIGINT NULL,
        INDEX(user_id), INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS redeem_codes (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code       VARCHAR(32) UNIQUE NOT NULL,
        plan       VARCHAR(32) NOT NULL,
        days       INT DEFAULT 30,
        used_by    BIGINT NULL,
        bot_id     INT UNSIGNED NULL,
        used_at    DATETIME NULL,
        expires_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS compile_log (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    BIGINT NOT NULL,
        lang       VARCHAR(16) NOT NULL,
        filename   VARCHAR(255),
        success    TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS user_files (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     BIGINT NOT NULL,
        bot_id      INT UNSIGNED NULL,
        filename    VARCHAR(255) NOT NULL,
        filepath    VARCHAR(512) NOT NULL,
        filetype    VARCHAR(32) DEFAULT 'script',
        filesize    INT DEFAULT 0,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id), INDEX(bot_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS vps_info (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        label       VARCHAR(128) DEFAULT 'MAIN VPS',
        ip_address  VARCHAR(64) NULL,
        ram_gb      DECIMAL(6,2) DEFAULT 0,
        disk_gb     DECIMAL(6,2) DEFAULT 0,
        os_info     VARCHAR(255) NULL,
        location    VARCHAR(128) NULL,
        status      ENUM('online','offline','maintenance') DEFAULT 'online',
        notes       TEXT NULL,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS bot_settings (
        k VARCHAR(64) PRIMARY KEY,
        v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS broadcast_log (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id   BIGINT,
        message    TEXT,
        sent_count INT DEFAULT 0,
        fail_count INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS bot_activity_log (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bot_id     INT UNSIGNED NOT NULL,
        user_id    BIGINT NOT NULL,
        action     VARCHAR(128),
        detail     TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(bot_id), INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS rate_limits (
        tg_id    BIGINT PRIMARY KEY,
        last_cmd DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Default settings from config
    $db->exec("
    INSERT IGNORE INTO bot_settings (k,v) VALUES
        ('bot_locked',       '0'),
        ('maintenance_msg',  'BOT IS UNDER MAINTENANCE. PLEASE TRY LATER.'),
        ('upi_id',           '".addslashes(UPI_ID)."'),
        ('upi_name',         '".addslashes(UPI_NAME)."'),
        ('upi_phone',        '".addslashes(UPI_PHONE)."'),
        ('support_username', '".addslashes(SUPPORT_USER)."'),
        ('trial_days',       '1'),
        ('max_runtime',      '60'),
        ('qr_api',           'https://api.qrserver.com/v1/create-qr-code/?size=350x350&data=');

    INSERT IGNORE INTO hosting_plans (id,name,days,price,max_bots,description,is_active) VALUES
        (1,'TRIAL',    1,    0, 1,'1 DAY FREE TRIAL — 1 BOT',           1),
        (2,'STARTER',  7,   49, 1,'7 DAYS — 1 BOT — BASIC HOSTING',     1),
        (3,'BASIC',   30,  149, 2,'30 DAYS — 2 BOTS — FULL FEATURES',   1),
        (4,'PRO',     90,  349, 5,'90 DAYS — 5 BOTS — PRIORITY SUPPORT',1),
        (5,'PREMIUM',180,  599,10,'180 DAYS — 10 BOTS — PREMIUM',        1),
        (6,'ULTRA',  365,  999,20,'365 DAYS — 20 BOTS — BEST VALUE',    1);

    INSERT IGNORE INTO vps_info (id,label,status,notes) VALUES
        (1,'MAIN VPS','online','PRIMARY HOSTING SERVER — EDIT IN ADMIN PANEL');
    ");
}
