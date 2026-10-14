#!/bin/bash
#
# Repara los permisos de storage/ y bootstrap/cache/ dentro del contenedor.
#
# public/build no se toca: lo genera `npm run production` en el host y Apache
# solo necesita leerlo. Cambiarle el dueño rompe el próximo build de Vite.
#
# USO
#   ./docker/fix-permissions.sh
#   CONTAINER=otro_contenedor ./docker/fix-permissions.sh
#
set -euo pipefail

CONTAINER="${CONTAINER:-ferretero_web}"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    echo "ERROR: el contenedor '$CONTAINER' no esta corriendo." >&2
    echo "       Arrancalo con 'docker compose up -d' y volve a intentar." >&2
    exit 1
fi

echo ">> Normalizando propietario de storage/ y bootstrap/cache/"
docker exec "$CONTAINER" chown -R www-data:www-data storage bootstrap/cache
docker exec "$CONTAINER" chmod -R ug+rwX storage bootstrap/cache

echo ">> Limpiando cache"
docker exec "$CONTAINER" php artisan config:clear
docker exec "$CONTAINER" php artisan cache:clear
docker exec "$CONTAINER" php artisan route:clear
docker exec "$CONTAINER" php artisan view:clear

docker exec "$CONTAINER" chown -R www-data:www-data storage bootstrap/cache

echo ">> Rehaciendo el symlink public/storage"
docker exec "$CONTAINER" php artisan storage:link --force >/dev/null 2>&1 || true

echo ">> Verificando"
LEFTOVERS="$(docker exec "$CONTAINER" find storage bootstrap/cache ! -user www-data -print 2>/dev/null | head -20 || true)"

if [ -n "$LEFTOVERS" ]; then
    echo "" >&2
    echo "ATENCION: quedaron archivos que no son de www-data:" >&2
    echo "$LEFTOVERS" >&2
    exit 1
fi

echo ">> OK. storage/ y bootstrap/cache/ son de www-data."
