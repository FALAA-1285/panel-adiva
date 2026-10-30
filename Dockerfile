# Panel Adiva untuk Railway.
#
# Satu image dipakai tiga layanan yang hanya berbeda perintah startnya:
#   web     -> bawaan CMD (FrankenPHP di $PORT)
#   worker  -> php artisan queue:work --tries=3 --max-time=3600
#   cron    -> php artisan schedule:work
#
# Database ada di Supabase, jadi image ini tidak menjalankan Postgres apa pun.

# ---- tahap 1: dependensi PHP
FROM composer:2 AS vendor
WORKDIR /app
# dua langkah: paket dulu (lapisan cache yang jarang berubah), autoloader setelah
# kode masuk, karena classmap-nya memetakan app/
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---- tahap 2: aset frontend
# Butuh PHP, bukan Node saja: plugin Wayfinder menjalankan `php artisan
# wayfinder:generate` saat build untuk membuat resources/js/routes dan actions.
FROM dunglas/frankenphp:1-php8.4-alpine AS aset
RUN install-php-extensions pdo_pgsql pgsql gd zip intl \
    && apk add --no-cache nodejs npm
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN cp .env.example .env \
    && php artisan key:generate --force \
    && npm ci \
    && npm run build

# ---- tahap 3: runtime
FROM dunglas/frankenphp:1-php8.4-alpine

RUN install-php-extensions pdo_pgsql pgsql gd zip intl opcache pcntl
# pg_dump for `php artisan cadangan:buat` on the cron container (same major version as Supabase)
RUN apk add --no-cache postgresql17-client

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=aset /app/public/build ./public/build
COPY --from=aset /app/resources/js/routes ./resources/js/routes
COPY --from=aset /app/resources/js/actions ./resources/js/actions

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/mulai.sh /usr/local/bin/mulai
RUN chmod +x /usr/local/bin/mulai

EXPOSE 8080

ENTRYPOINT ["mulai"]
CMD ["frankenphp", "php-server", "--root", "public/", "--listen", ":8080"]
