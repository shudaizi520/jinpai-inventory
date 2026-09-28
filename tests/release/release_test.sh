#!/bin/sh
set -eu

root_dir=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root_dir"

for file in README.md SECURITY.md LICENSE .github/workflows/ci.yml scripts/backup.sh scripts/restore.sh php/assets/inventory-logo.svg; do
    test -s "$file" || { echo "Missing release file: $file" >&2; exit 1; }
done

for file in php/lib/migrations.php php/lib/auth.php php/lib/inventory_consistency.php php/lib/audit.php; do
    test -s "$file" || { echo "Missing integrity runtime file: $file" >&2; exit 1; }
done

grep -q 'COPY php/ /var/www/html/' Dockerfile || { echo 'Docker web runtime does not receive PHP libraries.' >&2; exit 1; }
grep -q 'COPY php/lib/ /opt/inventory/php/lib/' Dockerfile || { echo 'Docker migration runtime does not receive PHP libraries.' >&2; exit 1; }
grep -q "php/lib/migrations.php" scripts/migrate.php || { echo 'Migration entry point is not packaged correctly.' >&2; exit 1; }

for page in php/login.php php/register.php php/index.php; do
    grep -q 'assets/inventory-logo.svg' "$page" || {
        echo "Inventory logo is missing from: $page" >&2
        exit 1
    }
done

if grep -R -q 'M9 19c-5 1.5-5-2.5-7-3' php --include='*.php'; then
    echo 'Legacy GitHub logo is still present.' >&2
    exit 1
fi

for topic in 'Docker' '邀请码' '备份' '恢复' 'HTTPS' '更新' '回滚'; do
    grep -q "$topic" README.md || { echo "README is missing topic: $topic" >&2; exit 1; }
done


for topic in '10 秒' '版本冲突' '操作日志' '强制退出' '迁移校验'; do
    grep -q "$topic" README.md || { echo "README is missing integrity topic: $topic" >&2; exit 1; }
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
