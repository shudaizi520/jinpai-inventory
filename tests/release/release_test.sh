#!/bin/sh
set -eu

root_dir=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root_dir"

for file in README.md SECURITY.md LICENSE .github/workflows/ci.yml scripts/backup.sh scripts/restore.sh; do
    test -s "$file" || { echo "Missing release file: $file" >&2; exit 1; }
done

for topic in 'Docker' '邀请码' '备份' '恢复' 'HTTPS' '更新' '回滚'; do
    grep -q "$topic" README.md || { echo "README is missing topic: $topic" >&2; exit 1; }
done

grep -q 'mariadb:11.4' .github/workflows/ci.yml
grep -q 'compose_test.sh' .github/workflows/ci.yml
grep -q 'tests/run.php' .github/workflows/ci.yml
grep -q 'version="0.20.3"' php/assets/xlsx.full.min.js

if git ls-files | grep -Eq '(^|/)\.env$|\.(sql|sql\.gz|sqlite|db|rar)$'; then
    echo 'Tracked secret/data/archive file detected.' >&2
    exit 1
fi

if git ls-files php | grep -Eq '(^|/)(setup|upgrade|fix_db|fix_database)\.php$'; then
    echo 'Unsupported web maintenance script detected.' >&2
    exit 1
fi

if git grep -I -n -E '(DB_PASSWORD|MYSQL_ROOT_PASSWORD|BOOTSTRAP_ADMIN_PASSWORD)=[^[:space:]]+' -- php scripts Dockerfile docker-compose.yml docker; then
    echo 'Possible tracked environment secret detected.' >&2
    exit 1
fi

echo 'Release checks passed.'
