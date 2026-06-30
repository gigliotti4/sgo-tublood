# ── Stage 1: compilar assets JS ──────────────────────────────────────────────
FROM node:22-alpine AS assets
WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY resources/ resources/
COPY public/     public/
COPY vite.config.ts tsconfig.json ./

RUN npm run build

# ── Stage 2: imagen PHP de producción ────────────────────────────────────────
FROM php:8.3-fpm-alpine AS app
WORKDIR /var/www/html

RUN apk add --no-cache \
        libzip-dev zip unzip \
        oniguruma-dev \
        libxml2-dev \
    && docker-php-ext-install \
        pdo pdo_mysql mbstring xml bcmath pcntl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dependencias PHP (cacheada por separado para builds rápidos)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Código fuente y assets compilados
COPY . .
COPY --from=assets /app/public/build public/build

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
