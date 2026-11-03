#!/bin/sh
set -eu
cd /var/www/html

: "${APP_KEY:?APP_KEY doit être définie dans Render}"
: "${DB_URL:?DB_URL doit référencer la base PostgreSQL Render}"
case "${PORT:-}" in
    ''|*[!0-9]*) echo "PORT doit être un entier" >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT est hors limites" >&2
    exit 1
fi

# Render provides its own canonical HTTPS origin after service creation.
export APP_URL="${RENDER_EXTERNAL_URL:-${APP_URL:-http://localhost}}"
php artisan config:cache
# Free services have no pre-deploy command: migrate before accepting traffic.
# Migrations are additive; never reset the production database here.
php artisan migrate --force
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground
