# Supplier RMA integration tests (Phase 1)

This directory contains **real Dolibarr-model test scenarios**, not a PHPUnit mock of the database. The first smoke script is intentionally **manual to invoke and not yet part of CI**. It has not been executed against a configured Dolibarr/MariaDB fixture. Syntax linting is not an integration result.

## Mandatory isolation

1. Prepare a **disposable Dolibarr 23 + MariaDB** installation with a database named `warrantysvc_test` or `warrantysvc_test_*`. Do not use a copy mounted with production database credentials. Block outgoing SMTP and webhooks in this environment.
2. Install and activate the current branch's **`module/`** into the Dolibarr custom-module directory; verify the `revision` migration is present first.
3. In that disposable database, prepare a Service Request, a supplier (`fournisseur=1`), a product and an operator; all must be valid for the same Dolibarr entity. Supply their numeric IDs as environment variables.
4. Run from CLI using the disposable Dolibarr installation:

```sh
WARRANTYSVC_TEST_ALLOW=yes \
WARRANTYSVC_TEST_HTDOCS=/opt/warrantysvc-test/htdocs \
WARRANTYSVC_TEST_REQUEST_ID=123 \
WARRANTYSVC_TEST_SUPPLIER_ID=456 \
WARRANTYSVC_TEST_PRODUCT_ID=789 \
WARRANTYSVC_TEST_USER_ID=1 \
php tests/integration/rma_integrity.php
```

The numeric IDs above are **examples**, not preconfigured fixture IDs. Only use IDs from the throwaway database.

The runner refuses non-CLI execution, requires explicit opt-in and refuses databases whose names do not start with `warrantysvc_test`. It creates and attempts to delete its own RMA record; no other objects are intentionally modified. Inspect the database after a failed run, as test cleanup is best-effort. The test does **not** re-enable/disable the module or test HTTP/CSRF.

## Current covered scenarios

- RMA create and initial revision.
- Two editor snapshots, successful first save, stale second save rejected without overwrite.
- Structured UPDATE event with old/new values, no-op save with no new event.
- Cross-entity edit refusal.
- Backdated business date with unchanged recorded timestamp.
- Post-shipment identity lock.

## Not yet implemented or executed

- CI-provisioned/pinned Dolibarr 23 fixture with isolated MariaDB database.
- Failure injection to prove UPDATE rollback if audit insert fails.
- Actual simultaneous transactions with two independent SQL connections.
- HTTP/permission/CSRF/delete tests and migration reactivation twice.
- Revision-aware status/rollback/date operations (Phase 1-C).

Record actual test commands, DB/software versions, outcomes and CI run URLs in `docs/PROJECT_STATUS.md`. Never declare Phase 1 completed based on syntax checks or this test file existing.
