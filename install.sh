#!/usr/bin/env bash
# ============================================================================
# Trading Journal — Container Installer
# Läuft INNERHALB des LXC. Installiert alle Abhängigkeiten, klont das Repo,
# richtet Nginx + PHP-FPM + Cron ein.
#
# Kann auch direkt manuell im Container ausgeführt werden:
#   bash install.sh
# Bei erneutem Aufruf (z.B. nach git pull) wird alles idempotent nachgezogen.
# ============================================================================
set -euo pipefail

REPO_URL="https://github.com/swissneo85/trading-journal.git"
APP_DIR="/opt/trading-journal"
DATA_DIR="$APP_DIR/data"
WEB_ROOT="/var/www/trading-journal"

echo "=== Trading Journal — Setup startet ==="

# ---- Pakete installieren ----
# Bewusst OHNE das generische "php" Metapaket - das zieht libapache2-mod-php
# und damit Apache2 mit rein, was mit Nginx auf Port 80 kollidiert.
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y git curl unzip nginx \
    php-fpm php-cli php-sqlite3 php-mbstring php-xml php-curl php-bcmath php-zip

# ---- Composer installieren (falls fehlend) ----
# /usr/bin statt /usr/local/bin, da letzteres bei non-interaktiven
# "pct exec" Aufrufen nicht im PATH ist.
if ! command -v composer >/dev/null 2>&1; then
    echo "Installiere Composer..."
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/bin/composer
    chmod +x /usr/bin/composer
fi
export COMPOSER_ALLOW_SUPERUSER=1

# ---- Repo klonen oder aktualisieren ----
git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true

if [ -d "$APP_DIR/.git" ]; then
    echo "Repo existiert bereits, ziehe Updates..."
    cd "$APP_DIR"
    git fetch origin
    git reset --hard origin/main
else
    echo "Klone Repo..."
    git clone "$REPO_URL" "$APP_DIR"
    cd "$APP_DIR"
fi

mkdir -p "$DATA_DIR"

# ---- Backend (Laravel) ----
cd "$APP_DIR/backend"
composer install --no-dev --optimize-autoloader

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi

# DB Pfad in .env sicherstellen
if ! grep -q "^DB_DATABASE=" .env; then
    echo "DB_DATABASE=$DATA_DIR/journal.sqlite" >> .env
else
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$DATA_DIR/journal.sqlite|" .env
fi
sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|" .env

touch "$DATA_DIR/journal.sqlite"

php artisan migrate --force
php artisan db:seed --force || true

chown -R www-data:www-data "$APP_DIR" "$DATA_DIR"
chmod -R 775 "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"

# ---- Frontend statisch kopieren ----
mkdir -p "$WEB_ROOT"
cp "$APP_DIR/www/index.html" "$WEB_ROOT/"

# ---- PHP-FPM Version ermitteln ----
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"

# ---- Nginx: interner Block für Laravel API (127.0.0.1:8001) ----
cat > /etc/nginx/sites-available/trading-journal-api << EOF
server {
    listen 127.0.0.1:8001;
    server_name _;
    root $APP_DIR/backend/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:$FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF

# ---- Nginx: öffentlicher Block (Port 8080) — Frontend + Proxy zu Laravel ----
cat > /etc/nginx/sites-available/trading-journal << EOF
server {
    listen 8080;
    server_name _;
    root $WEB_ROOT;
    index index.html;

    location /trading/ {
        proxy_pass http://127.0.0.1:8001/trading/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
    }
}
EOF

ln -sf /etc/nginx/sites-available/trading-journal-api /etc/nginx/sites-enabled/
ln -sf /etc/nginx/sites-available/trading-journal /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl restart "php${PHP_VERSION}-fpm"
systemctl restart nginx
systemctl enable nginx "php${PHP_VERSION}-fpm"

# ---- systemd Timer fuer Laravel Scheduler (robuster als crontab) ----
# Ersetzt den frueheren Crontab-Eintrag: eine einzelne, leicht durch
# "crontab -r" o.ae. ueberschreibbare Datei ist kein verlaesslicher Ort fuer
# einen Job, der bei jedem install.sh-Lauf ueberleben muss.
PHP_BIN=$(command -v php)

cat > /etc/systemd/system/trading-journal-scheduler.service << EOF
[Unit]
Description=Trading Journal Laravel Scheduler

[Service]
Type=oneshot
WorkingDirectory=$APP_DIR/backend
ExecStart=$PHP_BIN $APP_DIR/backend/artisan schedule:run
EOF

cat > /etc/systemd/system/trading-journal-scheduler.timer << 'EOF'
[Unit]
Description=Trading Journal Scheduler - jede Minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
AccuracySec=5s

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now trading-journal-scheduler.timer

if systemctl is-active --quiet trading-journal-scheduler.timer; then
    echo "✅ Scheduler-Timer aktiv"
else
    echo "⚠️  WARNUNG: Scheduler-Timer läuft nicht! Manuell prüfen mit:"
    echo "   systemctl status trading-journal-scheduler.timer"
fi

IP=$(hostname -I | awk '{print $1}')
echo ""
echo "============================================"
echo "✅ Installation abgeschlossen!"
echo "Weboberfläche: http://${IP}:8080"
echo "Beim ersten Aufruf: Settings-Tab öffnen und"
echo "Capital.com + Telegram Zugangsdaten eintragen."
echo "============================================"
