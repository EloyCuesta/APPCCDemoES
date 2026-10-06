#!/bin/sh
set -eu
umask 077
mkdir -p /var/appcc/evidencias /var/appcc/jwt /var/www/html/var
# The API and CLI share the same UID and private volumes.
chown www-data:www-data /var/appcc/evidencias /var/appcc/jwt /var/www/html/var
chmod 700 /var/appcc/evidencias /var/appcc/jwt
gosu www-data php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
gosu www-data php bin/console cache:clear --no-debug
if [ "$1" = "apache2-foreground" ]; then
    exec docker-php-entrypoint "$@"
fi
exec gosu www-data "$@"
