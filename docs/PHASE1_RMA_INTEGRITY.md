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
7. **Retention:** only an untouched DRAFT at revision 1, with exactly one CREATE event and no stock movement, files, document links or contact records, may be hard-deleted; a persisted row lock and the user-provided revision prevent stale confirmation. Edited, emailed or progressed records must be retained (use cancellation/closure as applicable). Historical records are not rewritten and existing audit rows are never purged as a side effect of updating.
8. **Native Dolibarr:** keep core DB abstractions, permission checks, Dolibarr CSRF patterns, module helpers, translations and the existing `module/` source layout. No changes to production or `dist/`.

## Delivery and acceptance gates

- **P1-A — Audit and executable test contract:** inspect source, publish this plan, establish isolated test/bootstrap strategy. Verify PHP lint for any new PHP.
- **P1-B — Revision + field-level audit:** SQL fresh-install and idempotent migration, model update CAS/audit, POST revision token, localized conflict, history presentation. Tests: existing data readable, first update commits, second stale edit rejected, failed log INSERT rolls back, same-value save doesn't create UPDATE event, locked identity can't change, other entity rejected.
- **P1-C — Lifecycle + dates:** revision-aware transitions/rollback/date changes, entity-safe log read/write, preserve audit chronology. Tests: concurrent edit vs transition, competing transitions, date corrections including clear/backdate, repeated rollback, invalid state, no recorded timestamp rewrite.
- **P1-D — Retention and HTTP:** deletion policy, POST/CSRF and permission defenses on all mutations, cross-entity refusal; preserve native attachment protections. Tests for read-only/delete-only roles, direct HTTP requests, contacts, existing links and stock movement IDs.
- **P1-E — Integration fixture + CI:** isolated Dolibarr **23.x pinned version** + MariaDB, install/activate module against an ephemeral schema, exercise model and HTTP paths with an isolated test user. Never point tests at production credentials or send real email. Include migration/re-enable twice, existing-RMA preservation, concurrency with independent DB sessions, negative transaction tests and full PHP lint. Pin image/dependencies once confirmed available, avoid `latest`.
- **P1-F — Sandbox acceptance:** back up real sandbox, deploy feature branch module files, run migration twice and the documented tests, perform manual UI/role/date/rollback checks; only then propose integration PR.

Each package is a small reviewable commit or set of commits. Update `docs/PROJECT_STATUS.md` at each completed milestone, with exact evidence and next steps. CI lint success alone is never a release gate.

## Implementation notes — 2026-10-11 (P1-D source work)

- Source implementation: [retention guard](https://github.com/vanyolai/dolibarr-warrantysvc/commit/5ff4ab04e1ab613b182d5a742374b8b77a5e16a1), [HTTP POST/revision guard](https://github.com/vanyolai/dolibarr-warrantysvc/commit/8946bdaed2772362a63844df6ebd564cdc77d2f6), [transactional in-memory correction](https://github.com/vanyolai/dolibarr-warrantysvc/commit/478b742ba0a5b3a3747666bbbbf7bb730ac90449).
- After that, the smoke runner was adapted to assert that an audited RMA cannot be deleted, while a separate untouched draft can be deleted, and to leave auditable test records in the **disposable** fixture for inspection.
- **Validation:** CI PHP lint passed for `ea6f3a3e2eb7` after a smoke-test syntax fix. No Dolibarr or MariaDB tests have run. The native confirmation forms and permissions require HTTP integration verification.
- **Risk:** a physical document filesystem write and a database deletion cannot be guaranteed atomic by the SQL transaction alone. The current guard refuses a deletion if any directory content is found; still test race scenarios. Do not claim complete deletion safety until these are reviewed.
