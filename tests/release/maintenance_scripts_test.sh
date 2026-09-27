#!/bin/sh
set -eu

root_dir=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
test_dir=$(mktemp -d "${TMPDIR:-/tmp}/inventory-maintenance-test-XXXXXX")
cleanup() {
    rm -rf "$test_dir"
}
trap cleanup EXIT HUP INT TERM

mkdir -p "$test_dir/bin" "$test_dir/backups"
cat > "$test_dir/bin/docker" <<'SCRIPT'
#!/bin/sh
case "$*" in
    *mariadb-dump*)
        if [ "${FAKE_DOCKER_FAIL_DUMP:-}" = 1 ]; then
            exit 9
        fi
        printf '%s\n' 'CREATE TABLE inventory_test (id INT);'
        ;;
    *'exec mariadb -uroot'*)
        cat > "$FAKE_DOCKER_CAPTURE"
        ;;
    *)
        echo "Unexpected docker call: $*" >&2
        exit 10
        ;;
esac
SCRIPT
chmod +x "$test_dir/bin/docker"

PATH="$test_dir/bin:$PATH" BACKUP_DIR="$test_dir/backups" "$root_dir/scripts/backup.sh" >/dev/null
backup_file=$(find "$test_dir/backups" -name 'inventory-*.sql.gz' -type f)
test -n "$backup_file"
gzip -t "$backup_file"

export FAKE_DOCKER_CAPTURE="$test_dir/restored.sql"
printf '%s\n' RESTORE | PATH="$test_dir/bin:$PATH" "$root_dir/scripts/restore.sh" "$backup_file" >/dev/null
grep -q 'CREATE TABLE inventory_test' "$FAKE_DOCKER_CAPTURE"

mkdir -p "$test_dir/failed"
if PATH="$test_dir/bin:$PATH" BACKUP_DIR="$test_dir/failed" FAKE_DOCKER_FAIL_DUMP=1 "$root_dir/scripts/backup.sh" >/dev/null 2>&1; then
    echo 'Backup script accepted a failed database export.' >&2
    exit 1
fi
test -z "$(find "$test_dir/failed" -type f -print -quit)"

echo 'Maintenance script checks passed.'
