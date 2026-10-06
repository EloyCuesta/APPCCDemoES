#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root"
deploy_dir=${APPCC_DEPLOY_DIR:-"$root/.deploy"}
if [[ ! -f "$deploy_dir/config.env" || ! -f "$deploy_dir/runtime.env" ]]; then
    echo 'Run python3 scripts/deploy/init.py DOMAIN EMAIL first.' >&2
    exit 1
fi
export APPCC_RUNTIME_ENV_FILE="$deploy_dir/runtime.env"
args=(--env-file "$deploy_dir/runtime.env" --env-file "$deploy_dir/config.env" -f compose.prod.yml)
if [[ -n ${APPCC_PROJECT_NAME:-} ]]; then
    args+=(--project-name "$APPCC_PROJECT_NAME")
fi
if [[ -n ${APPCC_COMPOSE_OVERRIDE:-} ]]; then
    args+=(-f "$APPCC_COMPOSE_OVERRIDE")
fi
exec docker compose "${args[@]}" "$@"
