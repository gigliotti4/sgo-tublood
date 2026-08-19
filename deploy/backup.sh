#!/usr/bin/env bash
#
# Backup diario de SGO Tublood. Lo instala provision.sh en /usr/local/bin/sgo-backup
# y lo corre un cron a las 02:30.
#
# Guarda dos cosas, porque con una sola no se puede restaurar el sistema:
#   - el dump de MySQL
#   - storage/app/private, donde viven los adjuntos de clientes y de
#     observaciones. **Esos archivos no están en ningún otro lado**: no se
#     sincronizan del ERP ni se pueden regenerar.
#
set -euo pipefail

APP_DIR=/var/www/sgo
DESTINO=/var/backups/sgo
RETENCION_DIAS=14

# Las credenciales salen del .env de la app en vez de repetirse acá.
leer_env() { grep -E "^${1}=" "${APP_DIR}/.env" | head -1 | cut -d= -f2- | tr -d '"'; }

DB_DATABASE=$(leer_env DB_DATABASE)
DB_USERNAME=$(leer_env DB_USERNAME)
DB_PASSWORD=$(leer_env DB_PASSWORD)

mkdir -p "$DESTINO"
sello=$(date +%Y-%m-%d_%H%M)

echo "[$(date '+%F %T')] backup ${sello}"

# --single-transaction: dump consistente sin trabar la app mientras corre.
MYSQL_PWD="$DB_PASSWORD" mysqldump \
    --user="$DB_USERNAME" \
    --single-transaction \
    --quick \
    --routines \
    "$DB_DATABASE" | gzip > "${DESTINO}/db-${sello}.sql.gz"

tar -czf "${DESTINO}/adjuntos-${sello}.tar.gz" \
    -C "${APP_DIR}/storage/app" private

# Rotación simple por fecha de modificación.
find "$DESTINO" -name '*.gz' -mtime "+${RETENCION_DIAS}" -delete

echo "[$(date '+%F %T')] ok — $(du -sh "$DESTINO" | cut -f1) en total"

# ⚠️ Esto deja los backups EN EL MISMO DROPLET: sirve para un borrado
# accidental, no para la pérdida del servidor. Los snapshots semanales de
# DigitalOcean cubren ese caso; si los datos crecen, el paso siguiente es
# empujar este directorio a un Space.
