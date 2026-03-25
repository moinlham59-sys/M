# 🤖 TELEGRAM BOT HOSTING SYSTEM v4.0

## QUICK SETUP — EDIT config.php FIRST!

Open `config.php` and fill in:
- BOT_TOKEN — from @BotFather
- OWNER_ID — your Telegram user ID
- CHANNEL / CHANNEL_ID — your updates channel
- WEBHOOK_URL — your domain URL
- DB_* — database credentials
- UPI_ID, UPI_NAME, UPI_PHONE — for payments
- SUPPORT_USER — support telegram username

Then run: `php setup.php`

## THREE ROLES

| Role | Menu Access |
|------|-------------|
| 👤 USER | Compile, Pay+QR, Files, Statement |
| 👮 ADMIN | + Bot management, User management |
| 👑 OWNER | Everything + Add/Remove Admins |

## FEATURES

### Hosting Languages
- 🐍 Python
- 🐘 PHP
- 🟢 Node.js
- ⚫ Shell/Bash
- 🌐 HTML (with mini PHP server)
- 🎨 CSS (analyzed, combine with HTML)

### Compiler (all users)
- 🔵 C — binary named **Moin**
- 🟣 C++ — binary named **Moin**
- 🩵 Go — binary named **Moin**
- 🐍 Python | 🐘 PHP | 🟢 Node.js | ⚫ Shell
- 🌐 HTML | 🎨 CSS — analyzed
- Code templates for every language

### Payments
- Select plan → UPI QR code auto-generated
- QR built from config.php UPI details
- Screenshot + UTR submission
- Admin approves with one tap

### Admin Features
- ➕ Add Admin | 🗑 Remove Admin
- 📅 Grant days by User ID or Bot ID
- 📊 User Statement | Bot Statement | VPS Statement
- 📁 File download system
- 📢 Broadcast to all users

## DEPLOY

```bash
# 1. Edit config.php with your details

# 2. Install compilers
apt-get install -y gcc g++ python3 nodejs php-cli

# For Go:
wget https://go.dev/dl/go1.21.linux-amd64.tar.gz
tar -C /usr/local -xzf go1.21.linux-amd64.tar.gz

# 3. Run installer
php setup.php

# 4. Add crons
*/5 * * * * /usr/bin/php /path/to/cron_worker.php
0   * * * * /usr/bin/php /path/to/cron_cleanup.php

# 5. Admin panel
# https://yourdomain.com/admin_panel.php
# Default: admin / admin2024 — CHANGE IT!
```

## DATABASE — 13 TABLES
users, admins, hosted_bots, hosting_plans, day_grants,
payments, redeem_codes, compile_log, user_files, vps_info,
bot_settings, broadcast_log, bot_activity_log, rate_limits

## DEFAULT PLANS
| Plan | Days | Price |
|------|------|-------|
| TRIAL | 1 | FREE |
| STARTER | 7 | ₹49 |
| BASIC | 30 | ₹149 |
| PRO | 90 | ₹349 |
| PREMIUM | 180 | ₹599 |
| ULTRA | 365 | ₹999 |

All editable via Admin Panel.
