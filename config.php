<?php
// ============================================================
//  config.php — EDIT ALL VALUES BELOW DIRECTLY
//  No environment variables needed — just fill in your info
// ============================================================

// ── BOT SETTINGS ─────────────────────────────────────────────
define('BOT_TOKEN',        '7343295464:AAHhsL4sWFDMLA-mAXm27cfI_H4BV3yj1no');  // @BotFather token
define('BOT_USERNAME',     'Moinvipddos0_bot');                                   // without @
define('OWNER_ID',         6218253783);                                           // your Telegram user ID
define('CHANNEL_USERNAME', '@PYHOSTING0');                                      // updates channel
define('CHANNEL_ID',       '-1002676634475');                                    // channel numeric ID
define('WEBHOOK_URL',      'http://moin.kesug.com/webhook.php');                // your domain

// ── DATABASE ─────────────────────────────────────────────────
// 'localhost' ko badal kar InfinityFree ka hostname dalein
define('DB_HOST', 'localhost');
define('DB_NAME', 'if0_41442889_moin');
define('DB_USER', 'if0_41442889');
define('DB_PASS', 'qIvIoNfTAcHnF');

// ── UPI PAYMENT (fill your details) ──────────────────────────
define('UPI_ID',       'mohd.moin@superyes');          // e.g. 9876543210@paytm
define('UPI_NAME',     'Mohd Moin');              // account holder name
define('UPI_PHONE',    '9389832371');             // phone for QR
define('SUPPORT_USER', 'MoinOwner');    // telegram username for support

// ── HOSTING PLANS (price in INR) ──────────────────────────────
// Plans are stored in DB and editable via admin panel
// These are defaults loaded on first install only

// ── PATHS (do not change unless needed) ──────────────────────
define('BASE_DIR',      __DIR__);
define('USERS_DIR',     BASE_DIR . '/data/users');
define('LOGS_DIR',      BASE_DIR . '/data/logs');
define('UPLOADS_DIR',   BASE_DIR . '/data/uploads');
define('QUEUE_DIR',     BASE_DIR . '/data/queue');
define('BOTS_DIR',      BASE_DIR . '/data/hosted_bots');
define('TMP_DIR',       BASE_DIR . '/data/tmp');
define('DOWNLOADS_DIR', BASE_DIR . '/data/downloads');

// ── COMPILER BINARIES ─────────────────────────────────────────
define('GCC_BIN',     '/usr/bin/gcc');
define('GPP_BIN',     '/usr/bin/g++');
define('GO_BIN',      '/usr/local/go/bin/go');    // or /usr/bin/go
define('PYTHON_BIN',  '/usr/bin/python3');
define('PHP_BIN',     '/usr/bin/php');
define('NODE_BIN',    '/usr/bin/node');
define('BASH_BIN',    '/bin/bash');

// ── COMPILE OUTPUT BINARY NAME ────────────────────────────────
define('COMPILED_BINARY_NAME', 'Moin');           // name for compiled C/C++/Go binary

// ── LIMITS ───────────────────────────────────────────────────
define('MAX_EXEC_TIME',     30);        // seconds for running scripts
define('COMPILE_TIMEOUT',   15);        // seconds for compiling
define('MAX_FILE_SIZE',     5242880);   // 5 MB
define('RATE_LIMIT_SEC',    3);         // rate limit per user
define('ALLOWED_EXTENSIONS', ['php','py','js','sh','html','css','txt','json','env','c','cpp','go']);

// ── TIMEZONE ─────────────────────────────────────────────────
date_default_timezone_set('Asia/Kolkata');

// ── ERROR CONFIG ─────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
if (!is_dir(BASE_DIR.'/data/logs')) @mkdir(BASE_DIR.'/data/logs', 0750, true);
ini_set('error_log', BASE_DIR.'/data/logs/php_errors.log');
