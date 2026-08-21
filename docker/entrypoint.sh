#!/bin/sh
set -eu

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
until php -r '
$host = getenv("DB_HOST") ?: "postgres";
$port = getenv("DB_PORT") ?: "5432";
$db = getenv("DB_DATABASE") ?: "erp";
$user = getenv("DB_USERNAME") ?: "app";
$pass = getenv("DB_PASSWORD") ?: "";
new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
' >/dev/null 2>&1; do
  sleep 1
done

php artisan config:clear
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan migrate --force
php artisan db:seed --force

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
