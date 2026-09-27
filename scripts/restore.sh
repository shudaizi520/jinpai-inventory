#!/bin/sh
set -eu

if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then
    echo "Usage: $0 /path/to/backup.sql[.gz]" >&2
    exit 2
fi

backup_file=$(CDPATH= cd -- "$(dirname -- "$1")" && pwd)/$(basename -- "$1")
case "$backup_file" in
    *.sql.gz) compressed=true ;;
    *.sql) compressed=false ;;
    *) echo 'Backup must end in .sql or .sql.gz' >&2; exit 2 ;;
esac

printf 'This will overwrite the current inventory database. Type RESTORE to continue: '
read -r confirmation
test "$confirmation" = 'RESTORE' || { echo 'Restore cancelled.'; exit 1; }

root_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$root_dir"

temporary_sql=$(mktemp "${TMPDIR:-/tmp}/inventory-restore-XXXXXX.sql")
cleanup() {
    rm -f "$temporary_sql"
}
trap cleanup EXIT HUP INT TERM

if [ "$compressed" = true ]; then
    gzip -t "$backup_file"
    gzip -dc "$backup_file" > "$temporary_sql"
else
    cp "$backup_file" "$temporary_sql"
fi

test -s "$temporary_sql" || { echo 'Backup contains no SQL data.' >&2; exit 1; }
docker compose exec -T db sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' < "$temporary_sql"
echo 'Restore completed. Restart the app service before use.'
