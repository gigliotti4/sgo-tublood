#!/usr/bin/env bash
#
# Deploy de SGO Tublood en el server (Hostinger, hosting compartido).
#
#   cd ~/domains/sgo.tublood.com/sgo-app && ./deploy.sh
#
# Trae los cambios de git, reinstala dependencias solo si hicieron falta,
# migra y regenera las cachés. Los assets de Vite vienen versionados en
# public/build, así que no hay que compilar nada acá (no hay Node).
#
# Si el `php` del CLI no es 8.3+, invocalo con el binario correcto:
#   PHP=/opt/alt/php83/usr/bin/php ./deploy.sh
#
set -euo pipefail

cd "$(dirname "$0")"

PHP="${PHP:-php}"

# Pase lo que pase, sacar la app de mantenimiento al salir. Sin esto, un fallo
# a mitad de camino deja el sitio caído hasta que alguien corra `artisan up`.
trap '"$PHP" artisan up >/dev/null 2>&1 || true' EXIT

echo "==> Modo mantenimiento"
"$PHP" artisan down --retry=15

echo "==> git pull"
commit_previo=$(git rev-parse HEAD)
git pull --ff-only

# Reinstalar dependencias solo si cambió el lock: `composer install` tarda y en
# compartido a veces se queda sin memoria, no vale la pena correrlo de más.
if ! git diff --quiet "$commit_previo" HEAD -- composer.lock; then
    echo "==> composer.lock cambió: reinstalando dependencias"
    "$PHP" -d memory_limit=-1 "$(command -v composer)" install --no-dev --optimize-autoloader
else
    echo "==> composer.lock sin cambios: salteo composer"
fi

echo "==> Migraciones"
"$PHP" artisan migrate --force

# Los permisos nuevos se crean acá, no en una migración — sin este paso, un
# permiso agregado en el código (ej. auditoria.view) queda invisible en
# producción hasta que alguien lo corra a mano. Es seguro en cada deploy:
# el seeder es idempotente y los roles de este proyecto no se tocan a mano
# desde la pantalla de Roles, solo desde este archivo.
echo "==> Roles y permisos"
"$PHP" artisan db:seed --class=RolesAndPermissionsSeeder --force

echo "==> Regenerando cachés"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

echo "==> Saliendo de mantenimiento"
"$PHP" artisan up

echo "✅ Deploy terminado — $(git rev-parse --short HEAD)"
