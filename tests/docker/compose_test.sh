#!/bin/sh
set -eu

root_dir=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root_dir"

required_files='Dockerfile compose.yaml .dockerignore .env.example docker/apache-security.conf php/health.php scripts/docker-entrypoint.sh scripts/healthcheck.php'
for file in $required_files; do
    test -f "$file" || { echo "Missing $file" >&2; exit 1; }
done

grep -Eq '^FROM php:8\.4-apache' Dockerfile
grep -q 'pdo_mysql' Dockerfile
grep -q 'COPY php/lib/ /opt/inventory/php/lib/' Dockerfile
grep -q 'inventory_db_data:' compose.yaml
grep -q 'condition: service_healthy' compose.yaml
grep -q 'healthcheck:' compose.yaml
grep -q 'DEFAULT_REGISTRATION_MODE:' compose.yaml
grep -q '^DEFAULT_REGISTRATION_MODE=invite$' .env.example
grep -q 'scripts/migrate.php' scripts/docker-entrypoint.sh
grep -q 'scripts/bootstrap-admin.php' scripts/docker-entrypoint.sh
grep -q 'BOOTSTRAP_ADMIN_PASSWORD:-' scripts/docker-entrypoint.sh
grep -q 'BOOTSTRAP_ADMIN_USERNAME:.*BOOTSTRAP_ADMIN_USERNAME:-admin' compose.yaml
grep -q 'BOOTSTRAP_ADMIN_PASSWORD:.*BOOTSTRAP_ADMIN_PASSWORD:-}' compose.yaml
if grep -q 'BOOTSTRAP_ADMIN_PASSWORD:.*:?' compose.yaml; then
    echo 'Compose must not require an administrator password before first startup.' >&2
    exit 1
fi
grep -q 'apache2-foreground' Dockerfile
grep -Eq '^\.env$' .dockerignore
grep -Eq '^\.git$' .dockerignore
grep -Eq '\*\.sql' .dockerignore
grep -q 'http://127.0.0.1/health.php' scripts/healthcheck.php

migration_line=$(grep -n 'scripts/migrate.php' scripts/docker-entrypoint.sh | head -n1 | cut -d: -f1)
start_line=$(grep -n 'exec.*apache2-foreground\|exec.*"\$@"' scripts/docker-entrypoint.sh | tail -n1 | cut -d: -f1)
test "$migration_line" -lt "$start_line"

if command -v docker >/dev/null 2>&1; then
    DB_PASSWORD='test-database-password-123' \
    MYSQL_ROOT_PASSWORD='test-root-password-123' \
        docker compose config >/dev/null
fi

echo 'Docker packaging checks passed.'
