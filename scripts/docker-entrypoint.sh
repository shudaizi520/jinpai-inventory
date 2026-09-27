#!/bin/sh
set -eu

attempt=0
until php /opt/inventory/scripts/migrate.php; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo 'Database migration did not become ready in time.' >&2
        exit 1
    fi
    echo "Waiting for the database (${attempt}/30)..." >&2
    sleep 2
done

php /opt/inventory/scripts/bootstrap-admin.php

exec "$@"
