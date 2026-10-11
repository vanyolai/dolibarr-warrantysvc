# Project guidance for coding assistants

Before changing this repository, **read [docs/PROJECT_STATUS.md](docs/PROJECT_STATUS.md)**. This is the single living development-status and chat-to-chat handoff record. Then inspect the actual code, current branch HEAD, recent commits/PRs and CI results. The code is authoritative when the status document is stale.

## Working agreement

- Target **Dolibarr 23** and PHP 8.4; the active PHP lint CI also checks 8.2 and 8.3.
- Work from the **latest agreed feature branch** and do not assume the default branch or production code is the correct baseline.
- The deployable module resides in `module/`. Do not modify `dist/`, production or deployment configuration as a side effect.
- Prefer native Dolibarr APIs (CommonObject, permissions, CSRF, hooks, translations, database methods, file/document helpers). Avoid duplicated business logic and hard-coded deployment paths.
- Preserve schema and historical data. Make migrations idempotent, validate multi-entity isolation, and ensure transactional failures are visible.
- Distinguish business event time from audit-recorded time. Keep stock movements idempotent, traceable and native to Dolibarr.
- Add meaningful tests for behavioral changes; lint alone does not demonstrate correctness. Never claim sandbox or production acceptance without evidence.
- Keep changes incremental with descriptive commits and a reviewable diff.
- **Update `docs/PROJECT_STATUS.md` with each significant code change or PR**, including actual test evidence, open issues and a precise next action. If interrupted, leave a handoff checkpoint there.
- Other documents in `docs/` provide architecture and test details; never maintain a conflicting second status tracker.

## Current next work package

At the time this file was introduced, the next task was **Phase 1: supplier-RMA field-level audit, transaction and optimistic-concurrency protections, plus a real integration test harness**. Read the status file for the current phase; this sentence becomes obsolete if that file advances.
