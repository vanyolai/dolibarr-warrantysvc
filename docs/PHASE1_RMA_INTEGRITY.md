# Phase 1 — Supplier RMA integrity: implementation and verification plan

Baseline audited: `feature/supplier-rma-workflow` @ `51002351c5d8bf74e80793cf46fde04d779c8c1f` (2026-10-11). This is a technical plan; runtime observations need tests. See [PROJECT_STATUS.md](PROJECT_STATUS.md) for authoritative progress.

## Source audit (confirmed by code inspection)

| Finding | Code | Risk | Work package |
| --- | --- | --- | --- |
| RMA-01 | `SvcSupplierRma::update` rewrites all editable columns, with neither revision guard nor transaction or audit event | Lost updates; untraceable changes | P1-B |
| RMA-06 | `setStatus`/`rollbackStatus` compare current status but not revision; update SQL omits entity predicate | Stale actions; weak isolation | P1-C |
| RMA-07 | `fetchHistory` and rollback event lookup have no entity predicate; `logEvent` uses global entity instead of object entity | Multientity correctness | P1-C |
| RMA-08 | `delete` deletes historical events and may delete non-draft records when no documents/stock exist | Irrecoverable business audit loss | P1-D |
| RMA-05 | `setBusinessDate` CAS checks only the selected date; same-record edits/transitions may race; rollback clears dates without preserving effective-date intent | Business chronology discrepancies | P1-C |
| RMA-03 | CI runs PHP lint only on 8.2/8.3/8.4; no real Dolibarr/DB tests | Behavior unverified | P1-A, P1-E |

## Contract and architectural decisions

1. **Existing object, existing audit log:** keep `SvcSupplierRma`, `svc_supplier_rma` and `svc_supplier_rma_log`; record field changes as structured UPDATE events in that log. Do not create a parallel source of truth. Preserve old CREATE/STATUS/DATE/ROLLBACK events.
2. **Integer revision:** introduce `revision INTEGER NOT NULL DEFAULT 1` on RMA (idempotent `upgradeForkSchema()`; also add to fresh-install SQL). Never use the second-resolution `tms` timestamp as the concurrency token. Existing rows start at 1 without changing other data.
3. **Compare-and-swap:** edits carry the revision rendered with the form. In the model, open a DB transaction, read persisted values and compare the expected revision, validate identity locks against persisted status, calculate diffs, and UPDATE with `WHERE rowid = ? AND entity = ? AND revision = ?`. Increment revision once per actual state-changing operation. Zero affected rows means a stale form. Return a translated conflict error and do not silently reload/replay a stale submission.
4. **Atomic change ledger:** write an UPDATE event within the *same DB transaction* as the data change. It contains deterministic changed-field old/new values, operator and recorded timestamp. Only whitelist model-managed business fields; never audit internal framework/transient properties. Do not create audit rows for no-op saves. A failing audit INSERT must roll back the UPDATE.
5. **Audit chronology:** `date_event` is immutable recorded time; `date_effective` is only a business event time. Date corrections must not rewrite `date_event`, even during rollback or reactivation.
6. **Lifecycle concurrency:** status, rollback and date corrections must check and increment the same revision. Entity predicates belong on every read/write. Checks and writes should be in one transaction; never update the in-memory object permanently before commit.
7. **Retention:** restrict hard deletion of meaningful lifecycle history; assess native cancellation/closure instead of deleting audited rows. Do not change historical data or drop existing tables.
8. **Native Dolibarr:** keep core DB abstractions, permission checks, Dolibarr CSRF patterns, module helpers, translations and the existing `module/` source layout. No changes to production or `dist/`.

## Delivery and acceptance gates

- **P1-A — Audit and executable test contract:** inspect source, publish this plan, establish isolated test/bootstrap strategy. Verify PHP lint for any new PHP.
- **P1-B — Revision + field-level audit:** SQL fresh-install and idempotent migration, model update CAS/audit, POST revision token, localized conflict, history presentation. Tests: existing data readable, first update commits, second stale edit rejected, failed log INSERT rolls back, same-value save doesn't create UPDATE event, locked identity can't change, other entity rejected.
- **P1-C — Lifecycle + dates:** revision-aware transitions/rollback/date changes, entity-safe log read/write, preserve audit chronology. Tests: concurrent edit vs transition, competing transitions, date corrections including clear/backdate, repeated rollback, invalid state, no recorded timestamp rewrite.
- **P1-D — Retention and HTTP:** deletion policy, POST/CSRF and permission defenses on all mutations, cross-entity refusal; preserve native attachment protections. Tests for read-only/delete-only roles, direct HTTP requests, contacts, existing links and stock movement IDs.
- **P1-E — Integration fixture + CI:** isolated Dolibarr **23.x pinned version** + MariaDB, install/activate module against an ephemeral schema, exercise model and HTTP paths with an isolated test user. Never point tests at production credentials or send real email. Include migration/re-enable twice, existing-RMA preservation, concurrency with independent DB sessions, negative transaction tests and full PHP lint. Pin image/dependencies once confirmed available, avoid `latest`.
- **P1-F — Sandbox acceptance:** back up real sandbox, deploy feature branch module files, run migration twice and the documented tests, perform manual UI/role/date/rollback checks; only then propose integration PR.

Each package is a small reviewable commit or set of commits. Update `docs/PROJECT_STATUS.md` at each completed milestone, with exact evidence and next steps. CI lint success alone is never a release gate.
