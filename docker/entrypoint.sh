#!/bin/sh
set -e

# Generar APP_KEY si está vacía
if [ -z "$APP_KEY" ]; then
    echo "Generando APP_KEY..."
    php artisan key:generate --force
fi

# Ejecutar migraciones (con retry porque MySQL puede no estar lista)
echo "Ejecutando migraciones..."
php artisan migrate --force

# En producción, optimizar caches
if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
