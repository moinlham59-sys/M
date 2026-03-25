<?php
// ============================================================
//  keyboards.php — All Keyboards
// ============================================================
class KB {

    // USER MENU — compile + pay + stats
    public static function userMenu(): array {
        return [
            ['📢 UPDATES CHANNEL'],
            ['💻 COMPILE CODE'],
            ['💳 PLANS & PAY',    '🔑 REDEEM CODE'],
            ['📁 MY FILES',       '📊 MY STATEMENT'],
            ['⚡ BOT SPEED',      '📞 SUPPORT'],
            ['🆘 HELP'],
        ];
    }

    // OWNER MENU — full control
    public static function ownerMenu(): array {
        return [
            ['📢 UPDATES CHANNEL'],
            ['🤖 MY BOTS',        '➕ ADD BOT'],
            ['🗑 REMOVE BOT',     '💻 COMPILE CODE'],
            ['💳 PLANS & PAY',    '🔑 REDEEM CODE'],
            ['📁 MY FILES',       '📊 MY STATEMENT'],
            ['👑 ADMIN PANEL',    '📢 BROADCAST'],
            ['🔒 LOCK BOT',       '🔓 UNLOCK BOT'],
            ['👥 ALL USERS',      '💰 PAYMENTS'],
            ['🤖 ALL BOTS',       '⚙️ SETTINGS'],
        ];
    }

    // ADMIN MENU — same as owner minus some controls
    public static function adminMenu(): array {
        return [
            ['📢 UPDATES CHANNEL'],
            ['🤖 MY BOTS',        '➕ ADD BOT'],
            ['🗑 REMOVE BOT',     '💻 COMPILE CODE'],
            ['💳 PLANS & PAY',    '🔑 REDEEM CODE'],
            ['📁 MY FILES',       '📊 MY STATEMENT'],
            ['👑 ADMIN PANEL',    '📢 BROADCAST'],
            ['👥 ALL USERS',      '💰 PAYMENTS'],
            ['🤖 ALL BOTS',       '⚙️ SETTINGS'],
        ];
    }

    public static function joinChannel(): array {
        return [
            [['text'=>'📢 JOIN CHANNEL','url'=>'https://t.me/'.ltrim(CHANNEL_USERNAME,'@')]],
            [['text'=>'✅ I HAVE JOINED — CHECK','callback_data'=>'check_join']],
        ];
    }

    // Compiler
    public static function compileLangs(): array {
        return [
            [
                ['text'=>'🔵 C',      'callback_data'=>'compile_c'],
                ['text'=>'🟣 C++',    'callback_data'=>'compile_cpp'],
                ['text'=>'🩵 GO',     'callback_data'=>'compile_go'],
            ],
            [
                ['text'=>'🐍 PYTHON', 'callback_data'=>'compile_python'],
                ['text'=>'🐘 PHP',    'callback_data'=>'compile_php'],
                ['text'=>'🟢 NODE',   'callback_data'=>'compile_node'],
                ['text'=>'⚫ SHELL',  'callback_data'=>'compile_shell'],
            ],
            [
                ['text'=>'📤 UPLOAD FILE',   'callback_data'=>'compile_upload'],
                ['text'=>'📋 TEMPLATES',     'callback_data'=>'compile_templates'],
                ['text'=>'🔧 COMPILER INFO', 'callback_data'=>'compile_info'],
            ],
            [['text'=>'🔙 BACK','callback_data'=>'main_menu']],
        ];
    }

    public static function compileResult(string $lang): array {
        return [
            [
                ['text'=>'🔄 RUN AGAIN','callback_data'=>'compile_'.$lang],
                ['text'=>'💻 OTHER LANG','callback_data'=>'compile_menu'],
            ],
            [['text'=>'🔙 MAIN MENU','callback_data'=>'main_menu']],
        ];
    }

    public static function templates(): array {
        return [
            [
                ['text'=>'🔵 C',      'callback_data'=>'tpl_c'],
                ['text'=>'🟣 C++',    'callback_data'=>'tpl_cpp'],
                ['text'=>'🩵 GO',     'callback_data'=>'tpl_go'],
            ],
            [
                ['text'=>'🐍 PYTHON', 'callback_data'=>'tpl_python'],
                ['text'=>'🐘 PHP',    'callback_data'=>'tpl_php'],
                ['text'=>'🟢 NODE',   'callback_data'=>'tpl_node'],
                ['text'=>'⚫ SHELL',  'callback_data'=>'tpl_shell'],
            ],
            [['text'=>'🔙 BACK','callback_data'=>'compile_menu']],
        ];
    }

    // Plans
    public static function plans(array $plans, ?int $botId=null): array {
        $btns=[];
        foreach($plans as $p){
            if(!(int)$p['is_active']||(float)$p['price']<=0) continue;
            $cb=$botId?"buyplan_{$p['id']}_{$botId}":"buyplan_{$p['id']}_0";
            $btns[]=[['text'=>strtoupper($p['name'])." — {$p['days']}D — ₹{$p['price']}",'callback_data'=>$cb]];
        }
        $btns[]=[['text'=>'🔙 BACK','callback_data'=>$botId?'bot_'.$botId:'main_menu']];
        return $btns;
    }

    // Payment
    public static function paymentOpts(int $payId, float $amount): array {
        return [
            [['text'=>"📲 RESEND QR CODE",'callback_data'=>"showqr_{$payId}"]],
            [['text'=>'📸 SEND PAYMENT PROOF','callback_data'=>"sendproof_{$payId}"]],
            [['text'=>'❌ CANCEL','callback_data'=>'main_menu']],
        ];
    }

    // Owner bot list
    public static function ownerBotList(array $bots, bool $isAdmin=false): array {
        $btns=[];
        foreach($bots as $b){
            $icon=match($b['status']){'active'=>'🟢','expired'=>'⏰','error'=>'❌','banned'=>'🚫',default=>'🔴'};
            $days=BotManager::daysLeft($b);
            $btns[]=[['text'=>"{$icon} @".($b['bot_username']??'BOT#'.$b['id'])." [{$days}D]",'callback_data'=>'bot_'.$b['id']]];
        }
        $btns[]=[
            ['text'=>'➕ ADD BOT','callback_data'=>'add_bot'],
            ['text'=>'🗑 REMOVE',  'callback_data'=>'remove_bot_pick'],
        ];
        $btns[]=[['text'=>'🔙 BACK','callback_data'=>'main_menu']];
        return $btns;
    }

    public static function removeBotPick(array $bots): array {
        $btns=[];
        foreach($bots as $b) $btns[]=[['text'=>"🗑 @".($b['bot_username']??'BOT#'.$b['id']),'callback_data'=>'confirm_remove_'.$b['id']]];
        $btns[]=[['text'=>'🔙 BACK','callback_data'=>'my_bots']];
        return $btns;
    }

    // Bot actions
    public static function botActions(array $bot): array {
        $a=$bot['status']==='active';
        return [
            [
                $a?['text'=>'🛑 STOP','callback_data'=>'bstop_'.$bot['id']]:['text'=>'▶️ START','callback_data'=>'bstart_'.$bot['id']],
                ['text'=>'🔄 RESTART','callback_data'=>'brestart_'.$bot['id']],
            ],
            [
                ['text'=>'📤 UPLOAD SCRIPT','callback_data'=>'bupload_'.$bot['id']],
                ['text'=>'📋 VIEW LOG',      'callback_data'=>'blog_'.$bot['id']],
            ],
            [
                ['text'=>'📊 BOT STATEMENT', 'callback_data'=>'bstatement_'.$bot['id']],
                ['text'=>'📁 BOT FILES',     'callback_data'=>'bfiles_'.$bot['id']],
            ],
            [
                ['text'=>'🔑 REDEEM',        'callback_data'=>'bredeem_'.$bot['id']],
                ['text'=>'💳 BUY DAYS',      'callback_data'=>'bbuy_'.$bot['id']],
            ],
            [
                ['text'=>'📈 STATUS',        'callback_data'=>'bstatus_'.$bot['id']],
                ['text'=>'🗑 DELETE BOT',    'callback_data'=>'bdel_'.$bot['id']],
            ],
            [['text'=>'🔙 MY BOTS','callback_data'=>'my_bots']],
        ];
    }

    // Script lang
    public static function scriptLang(int $botId): array {
        return [
            [
                ['text'=>'🐍 PYTHON','callback_data'=>"slang_{$botId}_python"],
                ['text'=>'🐘 PHP',   'callback_data'=>"slang_{$botId}_php"],
            ],
            [
                ['text'=>'🟢 NODE.JS','callback_data'=>"slang_{$botId}_node"],
                ['text'=>'⚫ SHELL',  'callback_data'=>"slang_{$botId}_shell"],
            ],
            [
                ['text'=>'🌐 HTML',  'callback_data'=>"slang_{$botId}_html"],
                ['text'=>'🎨 CSS',   'callback_data'=>"slang_{$botId}_css"],
            ],
            [['text'=>'🔙 CANCEL','callback_data'=>'bot_'.$botId]],
        ];
    }

    public static function confirmDel(int $botId): array {
        return [[
            ['text'=>'✅ YES DELETE','callback_data'=>'do_del_'.$botId],
            ['text'=>'❌ CANCEL',    'callback_data'=>'bot_'.$botId],
        ]];
    }

    public static function confirmRemove(int $botId): array {
        return [[
            ['text'=>'✅ YES REMOVE','callback_data'=>'do_remove_'.$botId],
            ['text'=>'❌ CANCEL',    'callback_data'=>'my_bots'],
        ]];
    }

    // Files list inline
    public static function filesList(array $files, string $backCb='main_menu'): array {
        $btns=[];
        foreach(array_slice($files,0,20) as $f){
            $sz=number_format($f['filesize']/1024,1);
            $btns[]=[['text'=>"📄 ".htmlspecialchars($f['filename'])." ({$sz}KB)",'callback_data'=>'dload_'.$f['id']]];
        }
        $btns[]=[['text'=>'🔙 BACK','callback_data'=>$backCb]];
        return $btns;
    }

    // Admin panel
    public static function adminPanel(): array {
        return [
            [
                ['text'=>'👥 ALL USERS',    'callback_data'=>'adm_users'],
                ['text'=>'🤖 ALL BOTS',     'callback_data'=>'adm_bots'],
            ],
            [
                ['text'=>'💰 PAYMENTS',     'callback_data'=>'adm_payments'],
                ['text'=>'🔑 GEN CODES',    'callback_data'=>'adm_genkeys'],
            ],
            [
                ['text'=>'📊 VPS STATUS',   'callback_data'=>'adm_vps'],
                ['text'=>'⚙️ SETTINGS',     'callback_data'=>'adm_settings'],
            ],
            [
                ['text'=>'📅 GRANT DAYS',   'callback_data'=>'adm_grant'],
                ['text'=>'➕ ADD BOT',       'callback_data'=>'adm_addbot'],
            ],
            [
                ['text'=>'👮 ADMINS',        'callback_data'=>'adm_admins'],
                ['text'=>'📢 BROADCAST',     'callback_data'=>'adm_broadcast_menu'],
            ],
            [
                ['text'=>'📋 USER STMT',    'callback_data'=>'adm_userstmt'],
                ['text'=>'🤖 BOT STMT',     'callback_data'=>'adm_botstmt'],
            ],
            [['text'=>'🔙 MAIN MENU',        'callback_data'=>'main_menu']],
        ];
    }

    // Admin management
    public static function adminList(array $admins): array {
        $btns=[];
        foreach($admins as $a){
            $name=strtoupper($a['first_name']??$a['tg_id']);
            $btns[]=[['text'=>"👮 {$name} @".($a['username']??'—'),'callback_data'=>'adminview_'.$a['tg_id']]];
        }
        $btns[]=[
            ['text'=>'➕ ADD ADMIN',    'callback_data'=>'add_admin'],
            ['text'=>'🔙 ADMIN PANEL', 'callback_data'=>'adm_panel'],
        ];
        return $btns;
    }

    public static function adminActions(int $tgId): array {
        return [
            [['text'=>'🗑 REMOVE ADMIN','callback_data'=>'remove_admin_'.$tgId]],
            [['text'=>'🔙 ADMINS LIST', 'callback_data'=>'adm_admins']],
        ];
    }

    // Grant days
    public static function grantDaysBtns(int $id, bool $isUser=false): array {
        $pfx=$isUser?"agdu_{$id}_":"agd_{$id}_";
        return [
            [
                ['text'=>'+1D',   'callback_data'=>$pfx.'1'],
                ['text'=>'+3D',   'callback_data'=>$pfx.'3'],
                ['text'=>'+7D',   'callback_data'=>$pfx.'7'],
                ['text'=>'+15D',  'callback_data'=>$pfx.'15'],
            ],
            [
                ['text'=>'+30D',  'callback_data'=>$pfx.'30'],
                ['text'=>'+60D',  'callback_data'=>$pfx.'60'],
                ['text'=>'+90D',  'callback_data'=>$pfx.'90'],
                ['text'=>'+365D', 'callback_data'=>$pfx.'365'],
            ],
            [
                ['text'=>'✏️ CUSTOM',  'callback_data'=>$pfx.'custom'],
                ['text'=>'➖ REMOVE',  'callback_data'=>($isUser?'ardu_':'ard_').$id],
            ],
            [['text'=>'🔙 BACK','callback_data'=>$isUser?'adm_users':'abotview_'.$id]],
        ];
    }

    // Admin bot actions
    public static function adminBotActions(array $bot): array {
        $a=$bot['status']==='active';
        return [
            [
                $a?['text'=>'🛑 STOP','callback_data'=>'abstop_'.$bot['id']]:['text'=>'▶️ START','callback_data'=>'abstart_'.$bot['id']],
                ['text'=>'🔄 RESTART','callback_data'=>'abrestart_'.$bot['id']],
            ],
            [
                ['text'=>'📅 GRANT DAYS','callback_data'=>'agdview_'.$bot['id']],
                ['text'=>'📋 LOG',        'callback_data'=>'ablog_'.$bot['id']],
            ],
            [
                ['text'=>'📊 BOT STMT',  'callback_data'=>'abstatement_'.$bot['id']],
                ['text'=>'📁 FILES',     'callback_data'=>'abfiles_'.$bot['id']],
            ],
            [
                ['text'=>'🚫 BAN BOT',   'callback_data'=>'abban_'.$bot['id']],
                ['text'=>'🗑 DELETE',    'callback_data'=>'abdel_'.$bot['id']],
            ],
            [['text'=>'🔙 ALL BOTS','callback_data'=>'adm_bots']],
        ];
    }

    // Admin user actions
    public static function adminUserActions(int $tgId, bool $banned): array {
        return [
            [
                $banned?['text'=>'✅ UNBAN','callback_data'=>'aunban_'.$tgId]:['text'=>'🚫 BAN','callback_data'=>'aban_'.$tgId],
                ['text'=>'📅 GRANT DAYS','callback_data'=>'agduser_'.$tgId],
            ],
            [
                ['text'=>'🤖 VIEW BOTS',    'callback_data'=>'aviewbots_'.$tgId],
                ['text'=>'📊 USER STMT',    'callback_data'=>'auserstmt_'.$tgId],
            ],
            [['text'=>'🔙 USERS','callback_data'=>'adm_users']],
        ];
    }

    public static function payApprove(int $payId): array {
        return [[
            ['text'=>'✅ APPROVE','callback_data'=>'payok_'.$payId],
            ['text'=>'❌ REJECT', 'callback_data'=>'payrej_'.$payId],
        ]];
    }

    public static function back(string $cb='main_menu'): array {
        return [[['text'=>'🔙 BACK','callback_data'=>$cb]]];
    }
}
