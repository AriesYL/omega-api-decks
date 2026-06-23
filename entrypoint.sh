#!/bin/bash

$(cd scripts; ./install.sh /opt/bin)

if [ "$#" -gt "0" ]; then
    $@; exit
fi

set +x

# Hosts like Render assign the port via $PORT (and route external traffic to it).
# Apache defaults to 80, so rewrite its listen port to $PORT (fallback 80 for local
# docker-compose). The /deck-image loopback to /imageify uses the same $PORT.
PORT="${PORT:-80}"
sed -ri "s/^Listen 80\$/Listen ${PORT}/" /etc/apache2/ports.conf || true
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf || true

# Free hosts have ephemeral disks, so the card database is gone on each cold start.
# Download it on boot if missing (DATABASE_URL). Only the DB (--database); the image
# cache is filled on-demand per request (we never run populate-cache → no huge disk).
if [ ! -f "${DATA_DIR}/card.db" ]; then
    echo "[entrypoint] card.db missing → downloading from DATABASE_URL ..."
    update-database --database || echo "[entrypoint] WARN: update-database failed (check DATABASE_URL)"
fi

docker-php-entrypoint apache2-foreground
