#!/usr/bin/env bash
#
# Deploy de SGO Tublood en el droplet de DigitalOcean.
#
#   cd /var/www/sgo && ./deploy.sh
#
# Trae los cambios de git, reinstala dependencias y recompila los assets solo si
# hicieron falta, migra, resiembra los catálogos y regenera las cachés.
#
# A diferencia del deploy viejo (Hostinger, hosting compartido), acá **sí se
# compilan los assets**: el droplet tiene Node, así que `public/build` ya no se
# versiona en el repo. Y hay un worker de colas corriendo, así que hay que
# avisarle que se reinicie o sigue ejecutando el código viejo que tiene en
# memoria.
#
# Todo lo que hace es idempotente: correrlo dos veces seguidas no rompe nada.
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

cambio() { ! git diff --quiet "$commit_previo" HEAD -- "$@"; }

# Reinstalar dependencias solo si cambió el lock: `composer install` tarda y no
# vale la pena correrlo de más en cada deploy.
if cambio composer.lock; then
    echo "==> composer.lock cambió: reinstalando dependencias PHP"
    composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "==> composer.lock sin cambios: salteo composer"
fi

# Mismo criterio con el front. `npm ci` respeta el lock (a diferencia de
# `npm install`), así que el build es el mismo que se probó en desarrollo.
if cambio package-lock.json; then
    echo "==> package-lock.json cambió: reinstalando dependencias JS"
    npm ci
fi

if cambio package-lock.json vite.config.js resources/js resources/css; then
    echo "==> Compilando assets"
    npm run build
else
    echo "==> Sin cambios en el front: salteo el build"
fi

echo "==> Migraciones"
"$PHP" artisan migrate --force

# Los permisos nuevos se crean acá, no en una migración — sin este paso, un
# permiso agregado en el código (ej. bitacora.view) queda invisible en
# producción hasta que alguien lo corra a mano. Es seguro en cada deploy:
# el seeder es idempotente y los roles de este proyecto no se tocan a mano
# desde la pantalla de Roles, solo desde este archivo.
echo "==> Roles y permisos"
"$PHP" artisan db:seed --class=RolesAndPermissionsSeeder --force

# Mismo motivo que los permisos: el catálogo de sectores es fijo y vive en el
# seeder, así que un sector nuevo (ej. Producción) queda invisible en producción
# hasta que alguien lo corra a mano. Idempotente: usa firstOrCreate, y
# `dias_gestion` se edita desde el ABM de Sectores sin que este paso lo pise.
echo "==> Sectores"
"$PHP" artisan db:seed --class=SectorSeeder --force

echo "==> Regenerando cachés"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

# El worker tiene el código viejo cargado en memoria: sin esto sigue corriendo
# la versión anterior hasta que alguien lo reinicie a mano. `queue:restart` le
# avisa que termine el job actual y salga; systemd lo vuelve a levantar solo.
echo "==> Reiniciando el worker de colas"
"$PHP" artisan queue:restart

echo "==> Saliendo de mantenimiento"
"$PHP" artisan up

echo "✅ Deploy terminado — $(git rev-parse --short HEAD)"
