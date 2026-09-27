#!/bin/sh
set -eu

root_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$root_dir"

backup_dir=${BACKUP_DIR:-"$root_dir/backups"}
mkdir -p "$backup_dir"
timestamp=$(date -u '+%Y%m%d-%H%M%S')
target="$backup_dir/inventory-$timestamp.sql.gz"
temporary_sql=$(mktemp "$backup_dir/.inventory-backup-XXXXXX.sql")
completed=false

cleanup() {
    rm -f "$temporary_sql"
    if [ "$completed" != true ]; then
        rm -f "$target"
    fi
}
trap cleanup EXIT HUP INT TERM

umask 077
test ! -e "$target" || { echo "Backup already exists: $target" >&2; exit 1; }
docker compose exec -T db sh -c 'exec mariadb-dump --single-transaction --routines --triggers -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' > "$temporary_sql"
test -s "$temporary_sql" || { echo 'Database export was empty.' >&2; exit 1; }
gzip -9 -c "$temporary_sql" > "$target"

test -s "$target" || { echo 'Backup was empty.' >&2; exit 1; }
completed=true
echo "Backup created: $target"
