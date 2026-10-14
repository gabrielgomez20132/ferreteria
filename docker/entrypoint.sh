#!/bin/bash
set -e

cd /var/www/html

php artisan storage:link --force 2>/dev/null || true

# El build y los `artisan` del deploy corren como root. Apache es www-data:
# si storage/ queda de root, Laravel no puede escribir el log y el error
# se ve como un 500 vacío.
echo "[entrypoint] Normalizando permisos de storage/ y bootstrap/cache/"
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

exec apache2-foreground
