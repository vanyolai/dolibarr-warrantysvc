# Native Dolibarr 23 supplier RMA regression

The suite executes real Dolibarr models, native installation/activation, independent SQL sessions and authenticated HTTP card requests. It does not mock the database. See `docs/PROJECT_STATUS.md` for actual results and remaining acceptance gates.

## Fresh, disposable fixture

Requirements: PHP CLI with mysqli, mbstring, gd, curl, XML and zip; Python 3; Git; a local disposable MariaDB server. The GitHub workflow runs PHP 8.4 and `mariadb:11.4.5`; Dolibarr is pinned to **23.0.4 commit `cb82037066c1c71f7c867482e9e92975222dfc14`**.

Example using a throwaway container (no persistent volume, bound to loopback):

```sh
docker run --detach --rm --name warrantysvc-test-db \
  --publish 127.0.0.1:33067:3306 \
  --env MARIADB_ROOT_PASSWORD=disposable-root-password \
  --env MARIADB_DATABASE=warrantysvc_test \
  --env MARIADB_USER=warrantysvc_test \
  --env MARIADB_PASSWORD=disposable-test-password \
  mariadb:11.4.5

# Proceed when the disposable server is ready:
docker exec warrantysvc-test-db healthcheck.sh --connect --innodb_initialized

git clone --depth 1 --branch 23.0.4 https://github.com/Dolibarr/dolibarr.git /tmp/warrantysvc-dolibarr-fixture

# From the WarrantySvc repository:
WARRANTYSVC_TEST_ALLOW=yes \
WARRANTYSVC_TEST_HTDOCS=/tmp/warrantysvc-dolibarr-fixture/htdocs \
WARRANTYSVC_TEST_DB_PORT=33067 \
WARRANTYSVC_TEST_DB_PASS=disposable-test-password \
  bash tests/integration/run.sh

# Tear down the complete disposable DB after inspection:
docker stop warrantysvc-test-db
```

Use a fresh checkout and database for each full run. The preparer **refuses existing config, document directory, module installation or nonempty schema**; it never drops/resets an existing database. The guard allows only loopback, dedicated `warrantysvc_test` SQL user and a database named `warrantysvc_test` or `warrantysvc_test_<alphanumeric>`. Never use production/shared sandbox credentials or paths. Fixtures use synthetic users/business data and force SMTP to loopback port 1 with an `.invalid` recipient. No email sending action is tested.

`run.sh` uses Dolibarr's native CLI installer and `activateModule()`, seeds fixture records, executes the suites, starts the PHP HTTP server on loopback port 18080, and always stops that HTTP server. It leaves the disposable schema/documents for inspection. Test users have the fixture-only password `Fixture-Only-23!`. The full suite is destructive **within the disposable schema**: migration coverage temporarily removes `revision`, and failure coverage creates/drops SQL triggers.

## Suites

| Script | Checks |
| --- | --- |
| `rma_integrity.php` | Create, revision, old/new UPDATE audit, no-op, stale/cross-entity edit, identity lock, backdated effective date with immutable recorded time, pristine-only deletion |
| `rma_transactions.php` | Server-side audit failure injection for edit/status/date/rollback; atomic hard-delete failure; stale lifecycle/date actions, repeated rollback, invalid dates/states, history isolation, links/contacts/files/stock/email/missing-history retention |
| `rma_dates.php` | Pre-entered/corrected dates survive rollback; automatic values may be undone; new workflow pass does not rewrite old milestones; legacy provenance is preserved; completion defaults |
| `rma_concurrency.php` + worker | Four overlapping independent PHP/SQL sessions (edit/status/date/rollback); observes worker SQL blocked on the parent's row lock before releasing the winning update; loser must not write/log |
| `rma_migration.php` | Legacy schema without revision; native disable/enable migration; all other data/logs preserved; existing RMA editable; two further activations cannot reset revision/data/history |
| `rma_http.py` + CLI fixture | Actual native login, read/write/read+delete/no-access roles, GET refusals, missing/forged CSRF, revision forms and stale edits, lifecycle/date writes, native AJAX POST confirmations, retention and cross-entity refusal |

Every failed assertion exits nonzero. The native installer exits nonzero on failure; its diagnostics are in `<checkout>/install.log`, HTTP server diagnostics in `<checkout>/http.log`. GitHub Actions keeps installer diagnostics on failure.

After a successful full run, a single model suite can be rerun in that same **disposable** fixture:

```sh
export WARRANTYSVC_TEST_ALLOW=yes
export WARRANTYSVC_TEST_HTDOCS=/tmp/warrantysvc-dolibarr-fixture/htdocs
. /tmp/warrantysvc-dolibarr-fixture/fixture.env
php tests/integration/rma_integrity.php
```

HTTP provisioning expects fresh synthetic usernames. The suite is an automated integrity gate, not proof of PDF/email/stock integration or representative user sandbox acceptance. Never promote this branch to production based on these tests alone; Phase 2 stock movements are outside this suite.
