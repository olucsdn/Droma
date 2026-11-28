#!/bin/sh
set -e

# Suporte à porta dinâmica para nuvem (Render/Koyeb usam $PORT ou 10000; local usa 80)
TARGET_PORT="${PORT:-80}"

sed -i "s/Listen 80/Listen ${TARGET_PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${TARGET_PORT}>/g" /etc/apache2/sites-available/000-default.conf

php /var/www/html/database/migrate.php || echo "[entrypoint] Aviso: migrate.php falhou na inicializacao" >&2

exec apache2-foreground
