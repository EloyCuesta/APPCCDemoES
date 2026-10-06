#!/usr/bin/env bash
set -euo pipefail
umask 077
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root"
backup=${1:?Usage: restore-fresh.sh BACKUP_DIR appcc_restore_NAME}
project=${2:?A new appcc_restore_NAME project is required}
[[ "$project" =~ ^appcc_restore_[a-z0-9_]+$ ]] || { echo 'Use a new appcc_restore_NAME project.' >&2; exit 1; }
[[ -f "$backup/COMPLETE" ]] || { echo 'Backup is incomplete.' >&2; exit 1; }
backup=$(cd "$backup" && pwd)
(cd "$backup" && sha256sum -c SHA256SUMS)
for volume in postgres evidencias jwt; do
    if docker volume inspect "${project}_${volume}" >/dev/null 2>&1; then
        echo 'Target volumes already exist. Restoration was refused before modifying data.' >&2
        exit 1
    fi
done
export APPCC_PROJECT_NAME="$project"
export APPCC_DEPLOY_DIR="$root/.deploy/restore/$project"
[[ ! -e "$APPCC_DEPLOY_DIR" ]] || { echo 'Target configuration already exists.' >&2; exit 1; }
mkdir -p "$APPCC_DEPLOY_DIR"
cp "$backup/runtime.env" "$backup/config.env" "$APPCC_DEPLOY_DIR/"
compose=(bash scripts/deploy/compose.sh)
"${compose[@]}" up -d --wait postgres
"${compose[@]}" exec -T postgres pg_restore -U appcc -d appcc --exit-on-error < "$backup/database.dump"
"${compose[@]}" run --rm --no-deps --entrypoint tar -T backend \
    -xzf - -C /var/appcc < "$backup/files.tar.gz"
"${compose[@]}" run --rm --no-deps backend php bin/console doctrine:schema:validate
"${compose[@]}" run --rm --no-deps backend php bin/console app:evidencias:verificar
printf '%s\n' "Restored into isolated project $project; no public ports were opened."
printf '%s\n' "Use the commit recorded in $backup/commit.txt before testing this restoration."
