<?php
// ============================================================
//  compiler.php — Multi-Language Compiler & Runner
//  C, C++, Go → compiled binary named COMPILED_BINARY_NAME (Moin)
//  Python, PHP, Node, Shell → interpreted
//  HTML, CSS → syntax check / preview info
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/users.php';

class Compiler {

    public static function langs(): array {
        return [
            'c'      => ['name'=>'C',       'ext'=>'c',   'icon'=>'🔵', 'bin'=>GCC_BIN,    'available'=>file_exists(GCC_BIN),    'compiled'=>true],
            'cpp'    => ['name'=>'C++',      'ext'=>'cpp', 'icon'=>'🟣', 'bin'=>GPP_BIN,    'available'=>file_exists(GPP_BIN),    'compiled'=>true],
            'go'     => ['name'=>'Go',       'ext'=>'go',  'icon'=>'🩵', 'bin'=>GO_BIN,     'available'=>file_exists(GO_BIN),     'compiled'=>true],
            'python' => ['name'=>'Python',   'ext'=>'py',  'icon'=>'🐍', 'bin'=>PYTHON_BIN, 'available'=>file_exists(PYTHON_BIN), 'compiled'=>false],
            'php'    => ['name'=>'PHP',      'ext'=>'php', 'icon'=>'🐘', 'bin'=>PHP_BIN,    'available'=>file_exists(PHP_BIN),    'compiled'=>false],
            'node'   => ['name'=>'Node.js',  'ext'=>'js',  'icon'=>'🟢', 'bin'=>NODE_BIN,   'available'=>file_exists(NODE_BIN),   'compiled'=>false],
            'shell'  => ['name'=>'Shell',    'ext'=>'sh',  'icon'=>'⚫', 'bin'=>BASH_BIN,   'available'=>file_exists(BASH_BIN),   'compiled'=>false],
            'html'   => ['name'=>'HTML',     'ext'=>'html','icon'=>'🌐', 'bin'=>'',         'available'=>true,                   'compiled'=>false],
            'css'    => ['name'=>'CSS',      'ext'=>'css', 'icon'=>'🎨', 'bin'=>'',         'available'=>true,                   'compiled'=>false],
        ];
    }

    public static function run(int $userId, string $lang, string $code, string $stdin=''): array {
        // HTML/CSS — validate and return stats
        if ($lang === 'html') return self::analyzeHtml($code, $userId);
        if ($lang === 'css')  return self::analyzeCss($code, $userId);

        $dir = TMP_DIR.'/compile_'.$userId.'_'.getmypid().'_'.time();
        if (!is_dir($dir)) @mkdir($dir, 0750, true);

        $langs = self::langs();
        if (!isset($langs[$lang])) {
            self::cleanDir($dir);
            return self::mkres(false, 'UNSUPPORTED LANGUAGE: '.strtoupper($lang), $lang, 0);
        }

        $ext = $langs[$lang]['ext'];
        $src = "{$dir}/main.{$ext}";
        file_put_contents($src, $code);
        $start = microtime(true);
        $output = '';
        $ok = false;
        $binName = COMPILED_BINARY_NAME; // "Moin"

        switch ($lang) {
            case 'c':
                $bin = "{$dir}/{$binName}";
                $comp = self::exec(
                    GCC_BIN." -Wall -Wextra -O2 -o ".escapeshellarg($bin)." ".escapeshellarg($src)." -lm 2>&1",
                    COMPILE_TIMEOUT, '', $dir
                );
                if ($comp['code'] !== 0) {
                    self::cleanDir($dir);
                    return self::mkres(false, "❌ COMPILE ERROR (GCC):\n\n".$comp['out'], $lang, microtime(true)-$start);
                }
                $run    = self::exec(escapeshellarg($bin)." 2>&1", MAX_EXEC_TIME, $stdin, $dir);
                $output = $run['out'];
                $ok     = $run['code'] === 0;
                break;

            case 'cpp':
                $bin  = "{$dir}/{$binName}";
                $comp = self::exec(
                    GPP_BIN." -std=c++17 -Wall -Wextra -O2 -o ".escapeshellarg($bin)." ".escapeshellarg($src)." -lm 2>&1",
                    COMPILE_TIMEOUT, '', $dir
                );
                if ($comp['code'] !== 0) {
                    self::cleanDir($dir);
                    return self::mkres(false, "❌ COMPILE ERROR (G++):\n\n".$comp['out'], $lang, microtime(true)-$start);
                }
                $run    = self::exec(escapeshellarg($bin)." 2>&1", MAX_EXEC_TIME, $stdin, $dir);
                $output = $run['out'];
                $ok     = $run['code'] === 0;
                break;

            case 'go':
                file_put_contents("{$dir}/go.mod", "module {$binName}\ngo 1.21\n");
                $bin  = "{$dir}/{$binName}";
                $comp = self::exec(
                    GO_BIN." build -o ".escapeshellarg($bin)." ".escapeshellarg($src)." 2>&1",
                    COMPILE_TIMEOUT, '', $dir
                );
                if ($comp['code'] !== 0) {
                    self::cleanDir($dir);
                    return self::mkres(false, "❌ COMPILE ERROR (Go):\n\n".$comp['out'], $lang, microtime(true)-$start);
                }
                $run    = self::exec(escapeshellarg($bin)." 2>&1", MAX_EXEC_TIME, $stdin, $dir);
                $output = $run['out'];
                $ok     = $run['code'] === 0;
                break;

            case 'python':
                $run    = self::exec(PYTHON_BIN." -u ".escapeshellarg($src)." 2>&1", MAX_EXEC_TIME, $stdin, $dir);
                $output = $run['out'];
                $ok     = $run['code'] === 0;
                break;

            case 'php':
                // Syntax check first
                $check = self::exec(PHP_BIN." -l ".escapeshellarg($src)." 2>&1", 5, '', $dir);
                if ($check['code'] !== 0) {
                    self::cleanDir($dir);
                    return self::mkres(false, "❌ PHP SYNTAX ERROR:\n\n".$check['out'], $lang, microtime(true)-$start);
                }
                $run    = self::exec(PHP_BIN." ".escapeshellarg($src)." 2>&1", MAX_EXEC_TIME, $stdin, $dir);
                $output = $run['out'];
                $ok     = $run['code'] === 0;
                break;

            case 'node':
                // Syntax check
                $check = self::exec(NODE_BIN." --check ".escapeshellarg($src)." 2>&1", 5, '', $dir);
                if ($check['code'] !== 0) {
                    self::cleanDir($dir);
                    return self::mkres(false, "❌ JS SYNTAX ERROR:\n\n".$check['out'], $lang, microtime(true)-$start);
                }
                $run    = self::exec(NODE_BIN." ".escapeshellarg($src)." 2>&1", MAX_EXEC_TIME, $stdin, $dir);
                $output = $run['out'];
                $ok     = $run['code'] === 0;
                break;

            case 'shell':
                chmod($src, 0750);
                // Syntax check
                $check = self::exec(BASH_BIN." -n ".escapeshellarg($src)." 2>&1", 5, '', $dir);
                if ($check['code'] !== 0) {
                    self::cleanDir($dir);
                    return self::mkres(false, "❌ SHELL SYNTAX ERROR:\n\n".$check['out'], $lang, microtime(true)-$start);
                }
                $run    = self::exec(BASH_BIN." ".escapeshellarg($src)." 2>&1", MAX_EXEC_TIME, $stdin, $dir);
                $output = $run['out'];
                $ok     = $run['code'] === 0;
                break;

            default:
                self::cleanDir($dir);
                return self::mkres(false, 'UNSUPPORTED LANGUAGE', $lang, 0);
        }

        $elapsed = round(microtime(true) - $start, 3);

        // Trim output
        if (strlen($output) > 3800) {
            $output = mb_substr($output, 0, 3800)."\n\n⚠️ [OUTPUT TRUNCATED — MAX 3800 CHARS]";
        }
        $output = trim($output) ?: '(NO OUTPUT)';

        // Log
        try {
            Database::q(
                "INSERT INTO compile_log(user_id,lang,filename,success) VALUES(?,?,?,?)",
                [$userId, $lang, "main.{$ext}", $ok ? 1 : 0]
            );
        } catch (\Exception $e) {}

        self::cleanDir($dir);
        return self::mkres($ok, $output, $lang, $elapsed, $binName);
    }

    // ── HTML analysis ─────────────────────────────────────────
    private static function analyzeHtml(string $code, int $uid): array {
        $tags    = preg_match_all('/<[^>]+>/', $code);
        $lines   = substr_count($code, "\n") + 1;
        $scripts = preg_match_all('/<script/i', $code);
        $links   = preg_match_all('/<link/i', $code);
        $hasDoctype = (bool)preg_match('/<!DOCTYPE/i', $code);
        $title   = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $code, $m)) $title = strip_tags($m[1]);

        $output  = "🌐 HTML FILE ANALYZED\n\n";
        $output .= "📄 LINES: {$lines}\n";
        $output .= "🏷 TOTAL TAGS: {$tags}\n";
        $output .= "📜 SCRIPT TAGS: {$scripts}\n";
        $output .= "🔗 LINK TAGS: {$links}\n";
        $output .= "✅ DOCTYPE: ".($hasDoctype ? 'YES' : 'NO')."\n";
        if ($title) $output .= "📌 TITLE: {$title}\n";
        $output .= "\n✅ HTML FILE READY FOR HOSTING";

        Database::q("INSERT INTO compile_log(user_id,lang,filename,success) VALUES(?,?,?,1)", [$uid, 'html', 'main.html']);
        return self::mkres(true, $output, 'html', 0);
    }

    // ── CSS analysis ─────────────────────────────────────────
    private static function analyzeCss(string $code, int $uid): array {
        $rules   = preg_match_all('/\{[^}]*\}/', $code);
        $lines   = substr_count($code, "\n") + 1;
        $selectors = preg_match_all('/[a-zA-Z#.][^{]+\{/', $code);
        $vars    = preg_match_all('/--[a-zA-Z]/', $code);
        $media   = preg_match_all('/@media/i', $code);

        $output  = "🎨 CSS FILE ANALYZED\n\n";
        $output .= "📄 LINES: {$lines}\n";
        $output .= "📋 RULES: {$rules}\n";
        $output .= "🎯 SELECTORS: {$selectors}\n";
        $output .= "🔵 CSS VARIABLES: {$vars}\n";
        $output .= "📱 MEDIA QUERIES: {$media}\n";
        $output .= "\n✅ CSS FILE READY FOR HOSTING";

        Database::q("INSERT INTO compile_log(user_id,lang,filename,success) VALUES(?,?,?,1)", [$uid, 'css', 'main.css']);
        return self::mkres(true, $output, 'css', 0);
    }

    // ── Safe exec ─────────────────────────────────────────────
    private static function exec(string $cmd, int $timeout, string $stdin='', string $cwd=''): array {
        // Security blocks
        $blocked = ['rm -rf /', 'wget http', '; wget', '| wget', 'curl -', '; curl', '| curl',
                    'nc -', 'netcat', '/etc/passwd', '/etc/shadow', 'chmod 777', '>/dev/null&&'];
        foreach ($blocked as $b) {
            if (stripos($cmd, $b) !== false) return ['code' => 1, 'out' => 'BLOCKED: DANGEROUS COMMAND'];
        }

        $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = proc_open($cmd, $desc, $pipes, $cwd ?: null);
        if (!is_resource($proc)) return ['code' => 1, 'out' => 'FAILED TO START PROCESS'];

        if ($stdin) fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        stream_set_timeout($pipes[1], $timeout);
        $out  = stream_get_contents($pipes[1]) ?? '';
        $err  = stream_get_contents($pipes[2]) ?? '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc, 9);
            proc_close($proc);
            return ['code' => 1, 'out' => "⏱️ EXECUTION TIMED OUT (>{$timeout}s)\nBUILD NAME: ".COMPILED_BINARY_NAME];
        }

        $code = proc_close($proc);
        return ['code' => $code, 'out' => trim($out . ($err ? "\n".$err : ''))];
    }

    // ── Detect language from filename extension ────────────────
    public static function detectLang(string $fn): string {
        return match(strtolower(pathinfo($fn, PATHINFO_EXTENSION))) {
            'c'             => 'c',
            'cpp','cc','cxx'=> 'cpp',
            'go'            => 'go',
            'py'            => 'python',
            'php'           => 'php',
            'js','mjs'      => 'node',
            'sh','bash'     => 'shell',
            'html','htm'    => 'html',
            'css'           => 'css',
            default         => 'python',
        };
    }

    // ── Format output for Telegram ────────────────────────────
    public static function formatOutput(array $r): string {
        $langs  = self::langs();
        $icon   = $langs[$r['lang']]['icon'] ?? '💻';
        $name   = $langs[$r['lang']]['name'] ?? strtoupper($r['lang']);
        $status = $r['success'] ? '✅ SUCCESS' : '❌ FAILED';
        $time   = number_format($r['time'], 3);
        $bin    = !empty($r['binary']) ? "\n🔧 BINARY: <code>".$r['binary']."</code>" : '';
        $out    = htmlspecialchars($r['output'], ENT_QUOTES, 'UTF-8');

        return "{$icon} <b>{$name} COMPILER</b>\n".
               "━━━━━━━━━━━━━━━━━━━━\n".
               "📊 STATUS: <b>{$status}</b>{$bin}\n".
               "⏱ TIME: <b>{$time}s</b>\n".
               "━━━━━━━━━━━━━━━━━━━━\n".
               "📤 <b>OUTPUT:</b>\n<pre>{$out}</pre>";
    }

    // ── Availability report ───────────────────────────────────
    public static function availabilityReport(): string {
        $t = "🔧 <b>COMPILER STATUS:</b>\n";
        foreach (self::langs() as $k => $l) {
            $t .= ($l['available'] ? '✅' : '❌')." {$l['icon']} <b>{$l['name']}</b>\n";
        }
        return $t;
    }

    // ── Code templates ────────────────────────────────────────
    public static function template(string $lang): string {
        return match($lang) {
            'c'    => "#include <stdio.h>\n#include <stdlib.h>\n#include <string.h>\n#include <math.h>\n\nint main(void) {\n    printf(\"Hello from Moin!\\n\");\n    return 0;\n}\n",
            'cpp'  => "#include <iostream>\n#include <vector>\n#include <string>\n#include <map>\n#include <algorithm>\n#include <cmath>\nusing namespace std;\n\nint main() {\n    cout << \"Hello from Moin!\" << endl;\n    return 0;\n}\n",
            'go'   => "package main\n\nimport (\n\t\"fmt\"\n)\n\nfunc main() {\n\tfmt.Println(\"Hello from Moin!\")\n}\n",
            'python'=> "# Python 3\nname = \"Moin\"\nprint(f\"Hello from {name}!\")\n\nnumbers = [1, 2, 3, 4, 5]\nprint(\"Sum:\", sum(numbers))\n",
            'php'  => "<?php\n\$name = 'Moin';\necho \"Hello from {\$name}!\\n\";\n\$arr = [1, 2, 3, 4, 5];\necho \"Sum: \" . array_sum(\$arr) . \"\\n\";\n",
            'node' => "// Node.js\nconst name = 'Moin';\nconsole.log(`Hello from ${name}!`);\n\nconst arr = [1,2,3,4,5];\nconst sum = arr.reduce((a,b)=>a+b,0);\nconsole.log('Sum:', sum);\n",
            'shell'=> "#!/bin/bash\nNAME=\"Moin\"\necho \"Hello from $NAME!\"\n\nfor i in 1 2 3 4 5; do\n  echo \"Number: $i\"\ndone\n",
            'html' => "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n  <meta charset=\"UTF-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n  <title>Moin Bot</title>\n  <style>\n    body { font-family: Arial, sans-serif; background: #0d1117; color: #e6edf3; }\n    h1   { color: #58a6ff; }\n  </style>\n</head>\n<body>\n  <h1>Hello from Moin!</h1>\n  <p>This is a hosted HTML page.</p>\n</body>\n</html>\n",
            'css'  => "/* CSS Template — Moin */\n:root {\n  --primary: #58a6ff;\n  --bg: #0d1117;\n  --text: #e6edf3;\n}\n\n* { box-sizing: border-box; margin: 0; padding: 0; }\n\nbody {\n  background: var(--bg);\n  color: var(--text);\n  font-family: Arial, sans-serif;\n  padding: 20px;\n}\n\nh1 { color: var(--primary); }\n\n@media (max-width: 600px) {\n  body { padding: 10px; }\n}\n",
            default => "// Code here\n",
        };
    }

    private static function mkres(bool $ok, string $out, string $lang, float $t, string $bin=''): array {
        return ['success'=>$ok, 'output'=>$out, 'lang'=>$lang, 'time'=>$t, 'binary'=>$bin];
    }

    private static function cleanDir(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getRealPath()) : @unlink($f->getRealPath()); }
        @rmdir($dir);
    }
}
