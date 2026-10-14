#!/bin/bash
#
# Atajo del deploy. Corre git pull, npm run production en un contenedor Node 20, rebuild de Docker
# solo si cambió la imagen, migraciones y cache.
#
# Uso, en el servidor:
#   bash deploy.sh
#
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
exec bash "$SCRIPT_DIR/docker/deploy.sh"
