#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root"
compose=(bash scripts/deploy/compose.sh)
# Validate interpolation privately: config output contains credentials.
"${compose[@]}" config --quiet
private=${APPCC_DEPLOY_DIR:-"$root/.deploy"}
exec 9>"$private/maintenance.lock"
flock -n 9 || { echo 'Another maintenance operation is running.' >&2; exit 1; }
"${compose[@]}" build backend frontend
"${compose[@]}" up -d --wait postgres
# Stop writers during migration; rebuild does not touch persistent volumes.
"${compose[@]}" stop backend scheduler
"${compose[@]}" run --rm --no-deps backend php bin/console doctrine:migrations:migrate --no-interaction
"${compose[@]}" run --rm --no-deps backend php bin/console doctrine:schema:validate
"${compose[@]}" run --rm --no-deps backend php bin/console lint:container
"${compose[@]}" run --rm --no-deps backend php bin/console app:plantillas:cargar
"${compose[@]}" run --rm --no-deps backend php bin/console app:tareas:procesar --json
"${compose[@]}" run --rm --no-deps backend php bin/console app:evidencias:verificar
"${compose[@]}" up -d --wait
# Reload bind-mounted proxy configuration even when its image did not change.
"${compose[@]}" restart gateway caddy
printf '%s\n' 'Services started. Verify HTTPS and bootstrap an account using docs/DEPLOYMENT.md.'
