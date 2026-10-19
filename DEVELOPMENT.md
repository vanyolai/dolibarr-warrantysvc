# Development Guide — WarrantySvc

> Last reviewed: 2026-10-19
>
> This file defines repository/branch/integration rules. The authoritative live feature status is `docs/PROJECT_STATUS.md` on the active development branch. Do not duplicate or override that status record here.

## Repository role

WarrantySvc uses a deliberate source/publish split.

```text
repository:          vanyolai/dolibarr-warrantysvc
development baseline: feature/supplier-rma-workflow
active Phase-1 branch: feature/rma-integrity-audit-concurrency
publish branch:      dist/dolibarr-module
consumer prefix:     htdocs/custom/warrantysvc
subtree mode:        squashed
production version:  1.46.9
```

Feature branches are **not** production subtree sources. Publishing to `dist/dolibarr-module` is a separate gate.

## Repository layout

Deployable module source is under:

```text
module/
```

Key areas:
- Supplier RMA model/transitions: `module/class/svcsupplierrma.class.php`
- Supplier RMA card/actions: `module/supplier_rma_card.php`
- list/documents: `module/supplier_rma_list.php`, `module/supplier_rma_document.php`
- module descriptor/migrations: `module/core/modules/modWarrantySvc.class.php`
- shared stock service: `module/class/warrantysvcstockservice.class.php`
- customer requests: `module/class/svcrequest.class.php`
- warranty letters: `module/class/svcwarrantyletter.class.php`
- translations: `module/langs/en_US/`, `module/langs/hu_HU/`

## Current development state

Published production line: **1.46.9**.

Active Supplier-RMA work is in Phase 1 on `feature/rma-integrity-audit-concurrency`.

Verified Phase-1 module baseline:
- `fe7cc8ac29b2` module code;
- expanded migration/retention tests at `4ae3df03fef0`;
- native Dolibarr 23.0.4 / PHP 8.4.26 / MariaDB 11.4.5 integration CI;
- PHP 8.2/8.3/8.4 lint;
- six DB/model/authenticated-HTTP suites passed;
- representative shared-sandbox/user acceptance remains outstanding.

Do not claim production readiness from CI alone.

## Current roadmap

The sequence is mandatory.

### Phase 1 — RMA integrity/audit/concurrency
Implemented and automated-test verified:
- revision-based optimistic concurrency;
- atomic field-level audit;
- lifecycle/date revision checks;
- business-effective vs recorded timestamps;
- rollback provenance;
- guarded hard-delete retention;
- entity/permission/CSRF/HTTP tests;
- migration/reactivation tests.

Remaining gate:
- representative sandbox/user acceptance using `docs/PHASE1_SANDBOX_ACCEPTANCE.md`.

Do **not** start physical stock work before Phase 1 acceptance.

### Phase 2 — physical supplier-RMA stock movements
- design outbound/inbound movements for repaired/replaced/rejected/credited units;
- reuse `WarrantySvcStockService`;
- store native movement IDs;
- ensure retry idempotency;
- preserve original/replacement LOT/SN;
- support reversal/failure recovery;
- verify native stock history.

### Phase 3 — result-dependent closure
- separate lifecycle status and outcome;
- define outcome-specific required fields;
- centralize close/reopen/rollback validation;
- test compatibility/migration.

### Phase 4 — customer claim ↔ supplier RMA coordination
- show active supplier RMAs on customer claim;
- warn on claim closure with outstanding supplier RMA where appropriate;
- improve linked-object/stock/document navigation.

### Phase 5 — refactor and stable release
- reduce hard-coded custom paths;
- review CommonObject/multientity/SQL/translations/PDF/email/hooks;
- synchronize technical docs and test plan with code;
- complete end-to-end regression and release notes;
- publish only after explicit acceptance.

Optional loaner-unit lifecycle remains outside the current phase unless separately approved.

## Architectural rules

- Reuse `SvcSupplierRma`; do not create a second RMA source of truth.
- Keep diagnosis, supplier work performed and structured outcome separate.
- Keep business-effective and recorded timestamps separate.
- Use native Dolibarr attachments/links.
- Reuse shared stock service for future physical movements.
- Migrations must be additive/idempotent.
- Every meaningful phase change updates `docs/PROJECT_STATUS.md`.
- Dist/publish changes are never an automatic side effect of feature development.

## Subtree publication/update

The Dolibarr consumer pulls only the publish branch:

```bash
git subtree pull \
  --prefix=htdocs/custom/warrantysvc \
  https://github.com/vanyolai/dolibarr-warrantysvc.git \
  dist/dolibarr-module \
  --squash \
  -m "Update WarrantySvc subtree"
```

Before publishing:
1. active feature acceptance is complete;
2. intended source is merged to the release/publish path;
3. module package/tree is reviewed;
4. version/release notes are updated;
5. integrated Dolibarr regression passes.

## New-session rule

Read, in this order:
1. this file;
2. `docs/PROJECT_STATUS.md`;
3. the current active PR and CI;
4. the relevant focused design document;
5. source code before making claims.

If status/code/CI disagree, Git/code/CI win and the status document must be corrected.
