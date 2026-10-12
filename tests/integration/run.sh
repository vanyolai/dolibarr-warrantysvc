#!/usr/bin/env bash
set -euo pipefail
test "${WARRANTYSVC_TEST_ALLOW:-}" = yes
: "${WARRANTYSVC_TEST_HTDOCS:?Set the disposable checkout htdocs path}"
test_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
fixture_root=$(cd -- "$WARRANTYSVC_TEST_HTDOCS/.." && pwd)
test "$(git -C "$fixture_root" rev-parse HEAD)" = cb82037066c1c71f7c867482e9e92975222dfc14
test ! -e "$WARRANTYSVC_TEST_HTDOCS/custom/warrantysvc"
php "$test_dir/fixture.php" prepare
cp -R "$test_dir/../../module" "$WARRANTYSVC_TEST_HTDOCS/custom/warrantysvc"
(cd -- "$WARRANTYSVC_TEST_HTDOCS/install" && php step2.php set en_US) > "$fixture_root/install.log" 2>&1
php "$test_dir/fixture.php" seed
source "$fixture_root/fixture.env"
php "$test_dir/rma_integrity.php"
php "$test_dir/rma_transactions.php"
php "$test_dir/rma_dates.php"
php "$test_dir/rma_concurrency.php"
php "$test_dir/rma_migration.php"
php -S 127.0.0.1:18080 -t "$WARRANTYSVC_TEST_HTDOCS" > "$fixture_root/http.log" 2>&1 &
http_pid=$!
trap 'kill "$http_pid" 2>/dev/null || true' EXIT
python3 "$test_dir/rma_http.py"
