#!/bin/sh
set -eu
cd /var/www/html
last_cleanup=0
last_verify=0
while :; do
    # On failure the container exits; restart and health status make it visible.
    php bin/console app:tareas:procesar --json
    now=$(date +%s)
    if [ $((now - last_cleanup)) -ge 3600 ]; then
        php bin/console app:evidencias:limpiar-temporales
        last_cleanup=$now
    fi
    if [ $((now - last_verify)) -ge 86400 ]; then
        php bin/console app:evidencias:verificar
        last_verify=$now
    fi
    printf '%s\n' "$now" > /tmp/appcc-scheduler-last-success
    sleep 300
done
