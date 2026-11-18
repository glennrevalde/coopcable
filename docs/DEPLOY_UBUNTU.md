# Deploying on an Ubuntu VPS (22.04 / 24.04)

A production layout: Apache (or nginx) + PHP 8.x + MySQL / MariaDB on one server, HTTPS, the worker on cron, nightly
backups. Replace `example.coop` with your domain.

## 1. Packages

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php php php-mysql php-mbstring php-curl php-xml php-zip php-gd php-bcmath unzip
sudo apt install -y mariadb-server          # or mysql-server; MySQL 5.5+ / MariaDB 5.5+ are supported
sudo mysql_secure_installation
```

## 2. Database user

```bash
sudo mysql
CREATE USER 'gsmart'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON `gsmart_coop_cable`.* TO 'gsmart'@'localhost';
GRANT ALL PRIVILEGES ON `gsmart_coop_cable\_%`.* TO 'gsmart'@'localhost';   -- the master and every company database
GRANT CREATE ON *.* TO 'gsmart'@'localhost';                                 -- the platform creates company databases
FLUSH PRIVILEGES;
```

Import one database file as root (it creates `gsmart_coop_cable` and `gsmart_coop_cable_master`):

```bash
sudo mysql < /var/www/gsmart-coop-cable/database/gsmart_coop_cable_clean.sql
```

If binary logging is on, the audit-trail triggers need `SET GLOBAL log_bin_trust_function_creators = 1;` (or import as
root).

## 3. Program files

```bash
sudo unzip GSMaRT_Coop_Cable_System.zip -d /var/www/
sudo chown -R root:www-data /var/www/gsmart-coop-cable
sudo find /var/www/gsmart-coop-cable -type d -exec chmod 750 {} \;
sudo find /var/www/gsmart-coop-cable -type f -exec chmod 640 {} \;
sudo chmod -R 770 /var/www/gsmart-coop-cable/storage          # backups, uploads, keys, platform flag
```

`config/config.php`: database host, user `gsmart` and its password. Prefer environment variables in production
(Apache `SetEnv DB_PASS ...` in the virtual host, or `/etc/environment` for cron): `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASS`, `APP_KEY`, `APP_DEBUG` (keep `0`).

`APP_KEY` encrypts device / gateway / SMS / e-mail credentials and PPPoE passwords. Generate one and keep it with the
backups: `php -r 'echo "base64:".base64_encode(random_bytes(32)),PHP_EOL;'`. Without `APP_KEY` the program writes
`storage/keys/app.key` (back it up).

## 4. Apache virtual host

```apache
<VirtualHost *:80>
    ServerName example.coop
    DocumentRoot /var/www/gsmart-coop-cable
    <Directory /var/www/gsmart-coop-cable>
        AllowOverride All
        Require all granted
    </Directory>
    SetEnv DB_USER gsmart
    SetEnv DB_PASS a-long-random-password
    SetEnv APP_KEY base64:REPLACE_WITH_YOUR_KEY
    ErrorLog ${APACHE_LOG_DIR}/gsmart_error.log
    CustomLog ${APACHE_LOG_DIR}/gsmart_access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite headers
sudo a2ensite gsmart && sudo systemctl reload apache2
sudo apt install -y certbot python3-certbot-apache && sudo certbot --apache -d example.coop
```

The `.htaccess` files close every program folder (`app`, `config`, `cron`, `database`, `modules`, `storage`, `tests`,
`tools`, `views`, `docs`) and SQL / Markdown / log files; only the pages, `assets/`, `portal/` and `api/gateway.php` are
served. HTTPS is required for the customer portal and the payment-gateway webhook.

### nginx instead of Apache

```nginx
server {
    listen 443 ssl http2;
    server_name example.coop;
    root /var/www/gsmart-coop-cable;
    index index.php;
    location ~ ^/(app|config|cron|database|modules|storage|tests|tools|views|docs|portal/views)(/|$) { deny all; }
    location ~ /\.(?!well-known) { deny all; }
    location ~ \.(sql|md|log|ini|sh)$ { deny all; }
    location / { try_files $uri $uri/ =404; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param DB_USER gsmart;
        fastcgi_param DB_PASS a-long-random-password;
        fastcgi_param APP_KEY base64:REPLACE_WITH_YOUR_KEY;
    }
    client_max_body_size 64M;
}
```

## 5. PHP settings

`/etc/php/8.3/apache2/php.ini` (or `fpm/php.ini`): `upload_max_filesize = 60M`, `post_max_size = 64M`,
`max_input_vars = 5000`, `memory_limit = 512M`, `date.timezone = Asia/Manila`, `session.cookie_secure = 1` (HTTPS),
`expose_php = Off`, `display_errors = Off`.

## 6. The worker and the backups

See [CRON.md](CRON.md) (worker every 5 minutes, scheduled reports daily) and [BACKUP_RECOVERY.md](BACKUP_RECOVERY.md).

## 7. Firewall and the network devices

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Apache Full' && sudo ufw enable
```

The server must reach the network devices it controls: the MikroTik API port (8728, or 8729 with TLS), the RADIUS
database (3306 on the RADIUS server), the OLT / ACS HTTP APIs. Keep these on a management VLAN or a VPN; never expose
the router API to the internet. Payment gateways must reach `https://example.coop/api/gateway.php`.

## 8. Checklist before going live

* `APP_DEBUG` is `0`; `config/config.php` is not readable by other users.
* Every sample password changed (or the clean database used); the Super Admin password is long and private.
* The default network device is the real adapter, not the simulator (the dashboard warns while the simulator is used).
* VAT registration, TIN, BIR ATP and serial range set; the service invoice and collection receipt print correctly.
* A billing run previewed and approved on a copy of the data; the worker has run (ISP Setup > Scheduled Jobs Monitor).
* A restore of last night's backup tested on another server.
