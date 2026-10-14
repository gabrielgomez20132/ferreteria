#!/bin/bash
#
# Deploy del día a día, en el servidor:
#   1. git pull
#   2. npm ci (si cambió el lock o no hay node_modules) y npm run production
#   3. docker compose build SOLO si cambió Dockerfile, composer o el entrypoint
#      El build no borra datos: storage/app, logs, sesiones y public/build
#      están montados desde el host. No se corre image prune ni compose down -v.
#   4. migraciones, permisos y cache de Laravel
#
# Uso (en el servidor, dentro del proyecto):
#   bash docker/deploy.sh
#   bash deploy.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR/.."

if [ ! -f .env ]; then
    echo "ERROR: no existe .env. Copiá .env.example a .env y completá APP_KEY, APP_URL y la base." >&2
    echo "       DB_HOST tiene que ser el nombre del contenedor MySQL en la red mysql_default, no 127.0.0.1." >&2
    exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
    echo "ERROR: npm no está instalado en el servidor. Hace falta Node 20 o superior para compilar el frontend." >&2
    exit 1
fi

echo ">> Guardando el commit actual, para saber después qué trajo el pull"
COMMIT_ANTES="$(git rev-parse HEAD)"

echo ">> git pull"
git pull

COMMIT_DESPUES="$(git rev-parse HEAD)"

if [ "$COMMIT_ANTES" = "$COMMIT_DESPUES" ]; then
    echo ">> No había nada nuevo en git. Sigo igual, por las dudas."
fi

echo ">> Dependencias de Node"
if [ ! -d node_modules ] || git diff --name-only "$COMMIT_ANTES" "$COMMIT_DESPUES" | grep -qE '^package(-lock)?\.json$'; then
    npm ci
else
    echo "   node_modules ya está y no cambió package-lock.json."
fi

echo ">> npm run production"
mkdir -p public/build storage/app/private storage/app/public storage/logs storage/framework/sessions
npm run production

echo ">> Revisando si hace falta rebuildear la imagen de Docker"
if git diff --name-only "$COMMIT_ANTES" "$COMMIT_DESPUES" | grep -qE '^(Dockerfile|composer\.(json|lock)|docker/entrypoint\.sh)$'; then
    echo "   Sí: cambió Dockerfile, composer o entrypoint. Rebuildeando (sin borrar imágenes ni storage)..."
    docker compose build
else
    echo "   No hace falta: no cambió nada de lo que queda grabado dentro de la imagen."
fi

echo ">> Asegurar contenedor arriba"
docker compose up -d

echo ">> Migraciones"
docker compose exec app php artisan migrate --force

echo ">> Normalizando permisos"
bash "$SCRIPT_DIR/fix-permissions.sh"

echo ">> Cacheando configuración, rutas y vistas"
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose exec app chown -R www-data:www-data storage bootstrap/cache

echo ""
echo ">> Listo. Deploy terminado."
echo ">> Commit actual: $(git rev-parse --short HEAD) - $(git log -1 --format=%s)"
