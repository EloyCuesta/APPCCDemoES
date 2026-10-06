#!/usr/bin/env bash
set -euo pipefail
umask 077
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root"
compose=(bash scripts/deploy/compose.sh)
private=${APPCC_DEPLOY_DIR:-"$root/.deploy"}
mkdir -p "$private/backups"
exec 9>"$private/maintenance.lock"
flock -n 9 || { echo 'Another maintenance operation is running.' >&2; exit 1; }
destination="$private/backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir "$destination"
mapfile -t writers < <("${compose[@]}" ps --services --status running | awk '/^(backend|scheduler)$/')
if [[ ${#writers[@]} != 2 ]]; then
    echo 'Backend and scheduler must be running before taking an operational backup.' >&2
    exit 1
fi
restart_writers() { "${compose[@]}" start "${writers[@]}"; }
trap restart_writers EXIT
"${compose[@]}" stop backend scheduler
"${compose[@]}" exec -T postgres pg_dump -U appcc -Fc appcc > "$destination/database.dump"
"${compose[@]}" run --rm --no-deps --entrypoint tar backend \
    -czf - -C /var/appcc evidencias jwt > "$destination/files.tar.gz"
cp "$private/runtime.env" "$private/config.env" "$destination/"
git rev-parse HEAD > "$destination/commit.txt"
(cd "$destination" && sha256sum database.dump files.tar.gz runtime.env config.env commit.txt > SHA256SUMS)
touch "$destination/COMPLETE"
printf '%s\n' "Consistent backup: $destination. Copy it to a private location outside this server."
