<?php
// ============================================================
//  telegram.php — Telegram Bot API Wrapper
// ============================================================
require_once __DIR__ . '/config.php';

class Telegram {

    public static function call(string $method, array $data=[], string $tok=''): ?array {
        $t  = $tok ?: BOT_TOKEN;
        $ch = curl_init('https://api.telegram.org/bot'.$t.'/'.$method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        if (curl_errno($ch)) {
            Logger::error('CURL:'.$method.' '.curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        $d = json_decode($res, true);
        if (!($d['ok'] ?? false) && isset($d['description'])) {
            Logger::warn("TG[{$method}]: ".$d['description']);
        }
        return $d;
    }

    public static function send(int|string $chat, string $text, array $extra=[], string $tok=''): ?array {
        if (!trim($text)) return null;
        return self::call('sendMessage', array_merge([
            'chat_id'                  => $chat,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra), $tok);
    }

    public static function sendMenu(int|string $chat, string $text, array $rows, string $tok=''): ?array {
        return self::send($chat, $text, ['reply_markup' => json_encode([
            'keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => false,
        ])], $tok);
    }

    public static function sendInline(int|string $chat, string $text, array $btns, string $tok=''): ?array {
        return self::send($chat, $text, ['reply_markup' => json_encode(['inline_keyboard' => $btns])], $tok);
    }

    public static function edit(int|string $chat, int $mid, string $text, array $btns=[], string $tok=''): ?array {
        $d = [
            'chat_id'                  => $chat,
            'message_id'               => $mid,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($btns) $d['reply_markup'] = json_encode(['inline_keyboard' => $btns]);
        return self::call('editMessageText', $d, $tok);
    }

    public static function answer(string $id, string $text='', bool $alert=false, string $tok=''): void {
        self::call('answerCallbackQuery', [
            'callback_query_id' => $id,
            'text'              => strtoupper($text),
            'show_alert'        => $alert,
        ], $tok);
    }

    public static function sendDoc(int|string $chat, string $path, string $cap='', array $btns=[], string $tok=''): ?array {
        if (!file_exists($path)) return null;
        $d = ['chat_id' => $chat, 'document' => new CURLFile($path), 'caption' => $cap, 'parse_mode' => 'HTML'];
        if ($btns) $d['reply_markup'] = json_encode(['inline_keyboard' => $btns]);
        return self::call('sendDocument', $d, $tok);
    }

    public static function sendPhoto(int|string $chat, string $fid, string $cap='', array $btns=[], string $tok=''): ?array {
        $d = ['chat_id' => $chat, 'photo' => $fid, 'caption' => $cap, 'parse_mode' => 'HTML'];
        if ($btns) $d['reply_markup'] = json_encode(['inline_keyboard' => $btns]);
        return self::call('sendPhoto', $d, $tok);
    }

    public static function sendPhotoUrl(int|string $chat, string $url, string $cap='', array $btns=[], string $tok=''): ?array {
        $d = ['chat_id' => $chat, 'photo' => $url, 'caption' => $cap, 'parse_mode' => 'HTML'];
        if ($btns) $d['reply_markup'] = json_encode(['inline_keyboard' => $btns]);
        return self::call('sendPhoto', $d, $tok);
    }

    public static function getFileUrl(string $fid, string $tok=''): ?string {
        $t   = $tok ?: BOT_TOKEN;
        $res = self::call('getFile', ['file_id' => $fid], $t);
        return isset($res['result']['file_path'])
            ? "https://api.telegram.org/file/bot{$t}/{$res['result']['file_path']}"
            : null;
    }

    public static function downloadFile(string $fid, string $dest, string $tok=''): bool {
        $url = self::getFileUrl($fid, $tok);
        if (!$url) return false;
        $dir = dirname($dest);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $ch = curl_init($url);
        $fp = fopen($dest, 'wb');
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true]);
        curl_exec($ch);
        $ok = !curl_errno($ch);
        curl_close($ch);
        fclose($fp);
        return $ok && file_exists($dest) && filesize($dest) > 0;
    }

    public static function getMemberStatus(int|string $chat, int $uid, string $tok=''): string {
        $r = self::call('getChatMember', ['chat_id' => $chat, 'user_id' => $uid], $tok);
        return $r['result']['status'] ?? 'left';
    }

    public static function validateToken(string $token): ?array {
        $r = self::call('getMe', [], $token);
        return ($r['ok'] ?? false) ? $r['result'] : null;
    }

    public static function setWebhook(string $url, string $tok=''): ?array {
        return self::call('setWebhook', ['url' => $url, 'max_connections' => 100], $tok);
    }

    public static function deleteWebhook(string $tok=''): ?array {
        return self::call('deleteWebhook', ['drop_pending_updates' => true], $tok);
    }

    public static function parseUpdate(array $upd): array {
        $msg   = $upd['message'] ?? null;
        $cb    = $upd['callback_query'] ?? null;
        $chat  = $msg['chat']['id'] ?? ($cb['message']['chat']['id'] ?? null);
        $from  = $msg['from'] ?? ($cb['from'] ?? null);
        $text  = trim($msg['text'] ?? ($cb['data'] ?? ''));
        $doc   = $msg['document'] ?? null;
        $photo = $msg['photo'] ?? null;
        return compact('msg','cb','chat','from','text','doc','photo');
    }
}
