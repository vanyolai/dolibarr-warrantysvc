# WarrantySvc – development status and handoff

> **Source of truth for ongoing development.** Read this file first when resuming the project, including in a new chat or AI coding session. This file is a *status record*, not a replacement for source-code review. If it disagrees with code or CI, verify the repository and correct this file.
>
> **Last reviewed:** 2026-10-11  
> **Working branch:** [feature/rma-integrity-audit-concurrency](https://github.com/vanyolai/dolibarr-warrantysvc/tree/feature/rma-integrity-audit-concurrency) (based on `feature/supplier-rma-workflow` @ `51002351c5d8`)  
> **Code baseline reviewed:** [51002351c5d8](https://github.com/vanyolai/dolibarr-warrantysvc/commit/51002351c5d8bf74e80793cf46fde04d779c8c1f) (2026-10-11; before Phase 1 implementation)  
> **Release status:** **Development / sandbox validation** — **NOT production-ready**.  
> **Target:** Dolibarr 23, PHP 8.4; PHP lint matrix currently also covers 8.2 and 8.3.

## 1. Where we are

| Area | Implementation | Verification | Remarks |
| --- | --- | --- | --- |
| Customer warranties, shipment generation, LOT/SN and warranty duration | Implemented | Previously exercised in sandbox; full regression pending | Avoid unrelated rewrites |
| Shipment-based warranty letters, versioned PDF, email, native card | Implemented in parent branch | Individual UI fixes user-confirmed; end-to-end regression pending | Source branch: `feature/warranty-letter-v2` |
| Customer service requests (`SvcRequest`) | Existing workflow | Broader regression pending | Parent of supplier RMAs |
| Supplier RMA separate menu, list, overview | Implemented | Basic sandbox UI exercised; full permissions/filters test pending | Reuses `SvcSupplierRma`, no parallel RMA table |
| Supplier's work performed (`supplier_work_done`) | Implemented | Display on existing RMA confirmed by user | Separate from diagnosis and result |
| Six editable business dates and dual-timestamp RMA history | Implemented | Automated integration and concurrency tests **not** done | `date_event` = recorded at; `date_effective` = business event time |
| Supplier RMA documents and external links (e.g. Paperless URL) | Implemented using native Dolibarr file/link tools | Upload, access control, links and delete behavior require sandbox verification | A Paperless API integration is **not** implemented |
| Supplier RMA physical outbound and inbound stock movement | **Not implemented** in RMA lifecycle | Not applicable | Existing fields and shared stock service must be assessed |
| Field-level RMA edit audit and optimistic concurrency | **Not implemented** | Not applicable | **Next work package** |
| Dolibarr/MariaDB automated integration tests | **Not implemented** | PHP syntax CI passes only | **Next work package** |
| Final refactor and release | Pending | Not applicable | Do not merge into production prematurely |

**Evidence:** the [PHP lint CI](https://github.com/vanyolai/dolibarr-warrantysvc/actions/runs/38034233644) passed on the baseline commit. PHP lint is **not** proof of correct database migrations, transactions, permissions, SMTP, PDF generation or UI behavior. Distinguish user-confirmed sandbox behavior from untested functionality in every future update.

## 2. Repository and workflow

- **Repository:** https://github.com/vanyolai/dolibarr-warrantysvc
- **Current development branch:** `feature/rma-integrity-audit-concurrency` (based on `feature/supplier-rma-workflow`; do not touch production).
- **Deployable source:** `module/`. Do not confuse this with generated distribution files or another Dolibarr fork.
- **Sandbox module path:** `/mnt/pool1/work/code/web/dolibarr-sandbox/htdocs/custom/warrantysvc/`.
- The sandbox is refreshed by cloning the chosen feature branch and syncing **the repository's `module/` contents** into that directory.
- **DB changes:** use additive/idempotent migrations via the module's upgrade path; back up sandbox DB before enabling a migration. Never assume refresh/re-enable is safe without checking what the change actually needs.
- **Do not update production or `dist/`** as a side effect of feature work. Integrate only after explicit validation.

### Core code entry points

| Purpose | File |
| --- | --- |
| Supplier RMA model/business transitions | `module/class/svcsupplierrma.class.php` |
| Supplier RMA card + HTTP actions | `module/supplier_rma_card.php` |
| Supplier RMA list / documents | `module/supplier_rma_list.php`, `module/supplier_rma_document.php` |
| Dashboard / counters | `module/index.php` |
| Module menus, setup, DB migration | `module/core/modules/modWarrantySvc.class.php` |
| Shared stock movement service | `module/class/warrantysvcstockservice.class.php` |
| Customer requests | `module/class/svcrequest.class.php`, `module/card.php` |
| Warranty letters | `module/class/svcwarrantyletter.class.php`, `module/warranty_letter_card.php` |
| Translation strings | `module/langs/hu_HU/warrantysvc.lang`, `module/langs/en_US/warrantysvc.lang` |

Relevant references:
- [Contributor / AI coding instructions](../AGENTS.md)
- [Supplier RMA workflow and sandbox checks](SUPPLIER_RMA_WORKFLOW.md)
- [Supplier RMA / loaner longer-term design](SUPPLIER_RMA_LOANER_DESIGN.md)
- [Warranty-letter design](WARRANTY_LETTER_DESIGN.md)
- [Technical reference](TECHNICAL.md) — **partly outdated; verify against code**
- [Manual test plan](../TEST_PLAN.md) — checklist, **not automated test results**

## 3. Roadmap and acceptance gates

**The sequence matters.** Do not begin stock movement work until the audit/transaction groundwork is checked and the identified high-impact defects are addressed.

### Phase 1 — Data integrity, field-level audit and automated testing — IN PROGRESS

**Goal:** Prevent silent overwrites and make every important supplier RMA change explainable and testable.

- [x] Initial source audit of `SvcSupplierRma` and associated card actions, migration and CI; see [Phase 1 audit and acceptance plan](PHASE1_RMA_INTEGRITY.md). Findings require fixes and runtime testing; this checkbox marks source review only.
- [ ] Define field-level changes to record (old/new values, operator, recorded timestamp) for supplier action, results, replacement serial, diagnosis, logistics and other relevant edits.
- [ ] Persist changes and audit events atomically using existing RMA audit infrastructure where feasible, rather than adding a second unrelated audit system.
- [ ] Add optimistic concurrency protection against two operators overwriting one another's edits.
- [ ] Verify that date correction never rewrites the historical *recorded at* time; preserve both dates in lifecycle history.
- [ ] Add repeatable tests for validation, state transitions, concurrency, rollback, date corrections, permissions and schema reactivation.
- [ ] Establish a supported Dolibarr 23 + database test fixture/CI setup, with isolation from production databases and mail recipients.
- [ ] Run the tests on a representative sandbox database; record the exact commands and outcomes here.

**Done when:** high-impact findings are fixed and reviewed, new audit/concurrency tests run successfully, an existing RMA is still readable/editable after migration, and sandbox behavior is confirmed. A green PHP lint check alone does **not** close this phase.

### Phase 2 — Supplier RMA physical stock movements

- [ ] Design outbound / inbound movement rules for repaired, replaced, rejected and credited units.
- [ ] Reuse and extend `WarrantySvcStockService`; do not duplicate stock-transaction logic.
- [ ] Record actual Dolibarr stock movement IDs on the RMA and ensure retry idempotency.
- [ ] Preserve original and replacement LOT/SN identities; validate warehouse, ownership, quantities and concurrent operations.
- [ ] Cover rollbacks/reversals and failure recovery without destroying stock history.
- [ ] Verify Dolibarr product stock history and linked business objects in sandbox.

**Done when:** every physical movement reconciles with native stock history, retries do not duplicate it, and failure cases leave consistent state.

### Phase 3 — Result-dependent RMA workflow / closure

- [ ] Separate workflow status from outcome where appropriate (repaired, replaced, rejected, credited, no fault found, other).
- [ ] Define outcome-specific mandatory data and whether physical return is applicable.
- [ ] Add central business validations for closing, reopening and rollback, including necessary stock checks.
- [ ] Preserve existing records; migration and compatibility need explicit tests.

### Phase 4 — Customer claim ↔ supplier RMA coordination

- [ ] Display active supplier RMAs and their lifecycle on customer claim cards.
- [ ] Warn when closing a claim with an outstanding supplier RMA, without blanket prohibition: a customer may already have a replacement.
- [ ] Improve navigation between business objects, stock movements and documents.

### Phase 5 — Refactor, docs and stable release

- [ ] Replace remaining hardwired `/custom/warrantysvc/` paths with module-aware Dolibarr helpers where suitable.
- [ ] Review `CommonObject` conventions, SQL/multientity, translations, PDFs, email, notifications and hooks.
- [ ] Bring `docs/TECHNICAL.md`, README and manual test plan in sync with actual code.
- [ ] Complete end-to-end regression, release notes and a reviewed PR to the intended integration branch.
- [ ] Mark production-ready only after explicit acceptance.

**Later, optional:** loaner-unit lifecycle from `SUPPLIER_RMA_LOANER_DESIGN.md`, not within the current phase without a separate decision.

## 4. Open risks / known architectural gaps (not all confirmed runtime bugs)

| ID | Priority | Observation and file | Next action |
| --- | --- | --- | --- |
| RMA-01 | High | `SvcSupplierRma::update()` writes multiple editable fields without a field-level `logEvent` record; a second editor may overwrite changes | Audit then implement atomic diff logging + version/concurrency guard |
| RMA-02 | High | RMA `setStatus()` handles state and dates, but supplier RMA outbound/inbound physical stock movement is not integrated | Handle in Phase 2, using stock service |
| RMA-03 | High | Only PHP lint is automated; no real Dolibarr/database regression jobs are present | Start Phase 1 test harness |
| RMA-04 | Medium | Status and `result_type` support different concepts; credits/no-fault may not fit the existing physical-return-only path | Phase 3 business design |
| RMA-05 | Medium | `SvcSupplierRma::setBusinessDate()` updates a matching milestone's `date_effective` and adds a DATE event; complex rollback/correction sequences are not tested | Cover historical and concurrent scenarios in Phase 1 |
| CROSS-01 | Medium | Remaining hard-coded `DOL_URL_ROOT.'/custom/warrantysvc/'` and similar paths appear in several module files | Audit and refactor in Phase 5 |
| LETTER-01 | Medium | Warranty-letter PDF file deletion and SQL updates are not fully atomic on failure | Review before production release |
| DOC-01 | Low | `docs/TECHNICAL.md` references earlier module versions and may describe older APIs | Update from real code in Phase 5 |

A risk is **not closed** until the corresponding test or review evidence is recorded; do not treat a plan or completed PHP lint as proof.

## 5. Design decisions (maintain this log)

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-10-11 | Use the existing `SvcSupplierRma` model and its audit log rather than creating a second RMA model | Avoid duplicate sources of truth and data migration |
| 2026-10-11 | Separate business event date (`date_effective`) from recorded timestamp (`date_event`) | Late data entry must not falsify audit chronology |
| 2026-10-11 | Keep `supplier_work_done`, diagnosis and structured result separately | Distinct business semantics |
| 2026-10-11 | Supplier documents use native attachments and links; Paperless API integration is deferred | Avoid bespoke document storage |
| 2026-10-11 | Separate stock movements / release gating from current UI changes | Stock and financial traceability require tested transactional operations |
| 2026-10-11 | A single living status document is authoritative for project handoffs | Continuing work should not depend on chat history |

Document new decisions here, especially exceptions, changes to workflow semantics and migrations.

## 6. How to resume after a chat/context change

1. Read **this file**, then inspect the current GitHub branch, recent commits/PRs and CI; do not assume the recorded commit is still HEAD.
2. Review the code paths for the **current phase** and read its referenced design/tests. Validate any factual claim in this document that affects a new code change.
3. State a bounded work package and identify acceptance tests. Work in an appropriately named feature branch derived from the latest agreed development baseline.
4. Commit in logical steps; run appropriate checks, not just syntax lint.
5. **Update this document in the same PR/change set** to record finished work, remaining problems, code/CI links and the *exact next step*. Update dated baseline and verification notes, never mark untested behavior as passing.
6. If a phase is interrupted, write a **Handoff checkpoint** below before stopping; the next session should be able to continue from repository state alone.

### Handoff checkpoint — 2026-10-11 (Phase 1 opened)

- **Last completed functional fix:** Existing Supplier RMA card now displays and edits `supplier_work_done` correctly; commit [14e3ba389848](https://github.com/vanyolai/dolibarr-warrantysvc/commit/14e3ba389848ecf303aae7bf150b4be7e3f152fb). User confirmed the display appeared correct in sandbox.
- **Verified automated check:** PHP lint succeeded for PHP 8.2/8.3/8.4 for that baseline; [CI run](https://github.com/vanyolai/dolibarr-warrantysvc/actions/runs/38034233644).
- **New branch:** `feature/rma-integrity-audit-concurrency`, derived from audited `51002351c5d8`. [Phase 1 audit, detailed design and test gates](PHASE1_RMA_INTEGRITY.md) committed as `7b12762308e7`.
- **Source-confirmed risks:** `update()` lacks transactional revision/audit; status updates omit entity predicate and only compare status; history lookup omits entity; hard delete removes RMA log; date CAS checks only the date. Not yet reproduced in a DB-backed test.
- **Next exact action:** Implement P1-B (additive `revision` migration + fresh schema, edit-form revision token, transactional CAS with structured field-level UPDATE log); add tests before claiming completion. Continue P1-C/P1-D/P1-E per linked plan. **Do not start Phase 2 stock writes yet**.
- **Open blockers:** No integration test harness yet; no production approval. Do not infer full sandbox regression success from the user confirming one field.

## 7. Rules for updating this document

Keep it concise, current and evidence-based. After **each meaningful development milestone**:
- Update the relevant phase checkbox, milestone checkpoint, code baseline, test results and unresolved risk IDs.
- Link the commit/PR and CI run. Where a manual sandbox test is needed, record **who verified what**, without inventing success.
- Distinguish **implemented**, **syntax checked**, **integration tested**, **user accepted** and **production deployed**.
- If work stops midstream, write next commands/target files or a clear reproduction path in the handoff checkpoint.
- Update design details in focused documents; **do not maintain competing status tables** elsewhere.
