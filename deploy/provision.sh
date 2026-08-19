#!/usr/bin/env bash
#
# Armado inicial del droplet para SGO Tublood (Ubuntu 24.04).
#
# Se corre UNA vez, como root, en un droplet recién creado:
#
#   ssh root@<IP>
#   apt-get update && apt-get install -y git
#   git clone <repo> /var/www/sgo && cd /var/www/sgo
#   bash deploy/provision.sh
#
# Después de esto queda por hacer, a mano y en este orden (ver deploy/README.md):
#   1. Cargar el .env de producción
#   2. certbot --nginx -d sgo.tublood.com   (necesita el DNS ya apuntando)
#   3. La primera migración y las sincronizaciones
#
# Es idempotente: se puede volver a correr sin romper nada.
#
set -euo pipefail

APP_DIR=/var/www/sgo
APP_USER=deploy

echo "==> Paquetes base"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y software-properties-common curl gnupg2 ca-certificates lsb-release unzip git ufw

echo "==> PHP 8.3"
# El repo de Ondrej trae 8.3 en 24.04. `composer.json` pide ^8.3.
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd php8.3-dev php8.3-common \
    pkg-config
# zip y gd no son opcionales acá: los usan PhpSpreadsheet (los Excel de clientes
# y proveedores) y DomPDF (el PDF de observaciones).

echo "==> Driver de SQL Server (proveedores y ventas del ERP)"
# Esto es lo que el hosting compartido no permitía instalar, y por lo que
# proveedores y ventas nunca se sincronizaron en producción. Las dos tareas
# agendadas se auto-apagan si falta (ver el guard en routes/console.php).
curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
    | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg
curl -fsSL https://packages.microsoft.com/config/ubuntu/24.04/prod.list \
    | sed 's|^deb |deb [signed-by=/usr/share/keyrings/microsoft-prod.gpg] |' \
    > /etc/apt/sources.list.d/mssql-release.list
apt-get update
ACCEPT_EULA=Y apt-get install -y msodbcsql18 unixodbc-dev

pecl channel-update pecl.php.net || true
printf "\n" | pecl install -f sqlsrv || true
printf "\n" | pecl install -f pdo_sqlsrv || true

# El orden importa: pdo_sqlsrv depende de pdo, y sqlsrv tiene que cargar antes.
# Y van en los DOS SAPI: el scheduler corre por CLI, así que si solo estuviera
# en fpm las tareas agendadas fallarían aunque la web anduviera.
for sapi in fpm cli; do
    echo "extension=sqlsrv.so"     > "/etc/php/8.3/${sapi}/conf.d/20-sqlsrv.ini"
    echo "extension=pdo_sqlsrv.so" > "/etc/php/8.3/${sapi}/conf.d/30-pdo_sqlsrv.ini"
done

echo "==> Composer"
if ! command -v composer >/dev/null; then
    curl -fsSL https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer
fi

echo "==> Node 22 (para compilar los assets en el servidor)"
if ! command -v node >/dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi

echo "==> MySQL"
apt-get install -y mysql-server
systemctl enable --now mysql
# La base y el usuario se crean a mano (ver deploy/README.md): esto no inventa
# contraseñas ni las deja escritas en un script versionado.

echo "==> nginx"
apt-get install -y nginx
cp "${APP_DIR}/deploy/nginx-sgo.conf" /etc/nginx/sites-available/sgo
ln -sf /etc/nginx/sites-available/sgo /etc/nginx/sites-enabled/sgo
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> Certbot"
apt-get install -y certbot python3-certbot-nginx
# El certificado se pide a mano, cuando el DNS ya resuelva:
#   certbot --nginx -d sgo.tublood.com

echo "==> Usuario de la aplicación"
id -u "$APP_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$APP_USER"
usermod -aG www-data "$APP_USER"
chown -R "${APP_USER}:www-data" "$APP_DIR"
# storage y bootstrap/cache los escribe php-fpm (www-data), no solo el deploy.
chmod -R 775 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"

echo "==> Worker de colas"
cp "${APP_DIR}/deploy/sgo-worker.service" /etc/systemd/system/
touch /var/log/sgo-worker.log && chown "${APP_USER}:www-data" /var/log/sgo-worker.log
systemctl daemon-reload
systemctl enable sgo-worker
# Se arranca recién cuando exista el .env:  systemctl start sgo-worker

echo "==> Scheduler"
# La otra pieza que faltaba en el compartido. Con esto arrancan solas las cinco
# tareas de routes/console.php: clientes cada 5 min, artículos 03:00,
# proveedores cada hora, ventas 04:00 y las alertas de observaciones cada hora.
cat > /etc/cron.d/sgo-scheduler <<CRON
* * * * * ${APP_USER} cd ${APP_DIR} && /usr/bin/php artisan schedule:run >> /var/log/sgo-scheduler.log 2>&1
CRON
touch /var/log/sgo-scheduler.log && chown "${APP_USER}:www-data" /var/log/sgo-scheduler.log

echo "==> Backup diario de la base"
cp "${APP_DIR}/deploy/backup.sh" /usr/local/bin/sgo-backup
chmod +x /usr/local/bin/sgo-backup
cat > /etc/cron.d/sgo-backup <<CRON
30 2 * * * root /usr/local/bin/sgo-backup >> /var/log/sgo-backup.log 2>&1
CRON

echo "==> Firewall"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
# MySQL no se expone: la app se conecta por localhost.

echo
echo "✅ Servidor armado. Falta, en este orden:"
echo "   1. Crear la base y el usuario de MySQL"
echo "   2. Escribir ${APP_DIR}/.env y correr: php artisan key:generate"
echo "   3. composer install --no-dev -o && npm ci && npm run build"
echo "   4. php artisan migrate --force && php artisan db:seed --force"
echo "   5. certbot --nginx -d sgo.tublood.com   (con el DNS ya apuntando)"
echo "   6. systemctl start sgo-worker"
echo
echo "   Ver deploy/README.md para el detalle y la verificación."
