#!/bin/sh
# Dijalankan sebelum perintah layanan apa pun (web, worker, cron).
set -e

# Railway memberi PORT; FrankenPHP membacanya lewat SERVER_NAME
if [ -n "$PORT" ]; then
    export SERVER_NAME=":$PORT"
fi

# Migrasi hanya dari layanan web, supaya worker dan cron tidak ikut menjalankannya
# bersamaan saat ketiganya naik serentak.
if [ "${JALANKAN_MIGRASI:-0}" = "1" ]; then
    echo "> migrasi database"
    php artisan migrate --force --no-interaction
fi

# Cache dibangun saat start, bukan saat build: nilainya baru ada di sini.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Foto produk disimpan di volume Railway yang dipasang di storage/app/public
php artisan storage:link || true

exec "$@"
