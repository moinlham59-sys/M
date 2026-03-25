<?php
// cron_cleanup.php — Run: 0 * * * * php /path/cron_cleanup.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/users.php';

try { install_schema(); } catch (\Exception $e) {}
Logger::info('CLEANUP START');

// Rotate main log > 5MB
$log = LOGS_DIR.'/bot.log';
if (file_exists($log) && filesize($log) > 5242880) {
    rename($log, $log.'.'.date('Ymd_His').'.bak');
    Logger::info('LOG ROTATED');
}
// Delete old bak logs > 7 days
foreach (glob(LOGS_DIR.'/*.bak') ?: [] as $f)
    if (time()-filemtime($f) > 604800) unlink($f);

// DB cleanup
Database::q("DELETE FROM rate_limits WHERE last_cmd < DATE_SUB(NOW(), INTERVAL 1 DAY)");
Database::q("DELETE FROM bot_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
Database::q("DELETE FROM compile_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");

// Truncate bot logs > 2MB
foreach (glob(BOTS_DIR.'/*/bot.log') ?: [] as $f) {
    if (file_exists($f) && filesize($f) > 2097152) {
        $lines = file($f, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
        file_put_contents($f, implode("\n", array_slice($lines, -500))."\n", LOCK_EX);
    }
}

// Clean tmp compile dirs > 1 hour old
foreach (glob(TMP_DIR.'/c_*') ?: [] as $d) {
    if (is_dir($d) && time()-filemtime($d) > 3600) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath()); }
        @rmdir($d);
    }
}

Logger::info('CLEANUP DONE');
