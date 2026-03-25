#!/bin/bash

echo "🚀 Starting Setup..."

# UPDATE
sudo apt update -y && sudo apt upgrade -y

# APACHE
sudo apt install apache2 -y
sudo systemctl start apache2
sudo systemctl enable apache2

# PHP
sudo apt install php8.1 php8.1-mysql php8.1-curl php8.1-json php8.1-mbstring php8.1-xml php8.1-zip -y

# MYSQL
sudo apt install mysql-server -y
sudo systemctl start mysql
sudo systemctl enable mysql

# PYTHON
sudo apt install python3 python3-pip -y

# NODEJS
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install nodejs -y

# COMPILER
sudo apt install gcc g++ build-essential -y

# SSL (non-interactive)
sudo apt install certbot python3-certbot-apache -y
sudo certbot --apache -d yourdomain.com --non-interactive --agree-tos -m admin@yourdomain.com || true

# FIREWALL
sudo ufw allow 22
sudo ufw allow 80
sudo ufw allow 443
sudo ufw --force enable

# CRON (no editor)
(crontab -l 2>/dev/null; echo "* * * * * php /var/www/html/hosting_bot/cron.php >> /dev/null 2>&1") | crontab -

# DATABASE (no password)
sudo mysql -e "CREATE DATABASE IF NOT EXISTS hosting_bot;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'botuser'@'localhost' IDENTIFIED BY 'StrongPass123!';"
sudo mysql -e "GRANT ALL PRIVILEGES ON hosting_bot.* TO 'botuser'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# PERMISSIONS
sudo chown -R www-data:www-data /var/www/html/
sudo chmod -R 755 /var/www/html/

# RESTART
sudo systemctl restart apache2
sudo systemctl restart mysql

# CHECK
php -v
python3 --version
node --version
mysql --version

echo "✅ Setup Completed!"