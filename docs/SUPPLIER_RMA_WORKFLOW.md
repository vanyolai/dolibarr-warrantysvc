# Supplier service / RMA workspace (Dolibarr 23)

## Scope and ownership

This change works on the existing `SvcSupplierRma` / `llx_svc_supplier_rma` object. It does **not** create another RMA entity or reparent legacy RMAs. The customer Service Request remains the parent and can be opened from every RMA card or worklist row.

- Navigation: Products → WarrantySvc → Service requests → **Supplier service / RMA**.
- Overview: **Open supplier RMAs** and **Supplier service in progress**, each linked to a filtered worklist.
- List: RMA reference, supplier, originating request, supplier reference, product, creation date, status, received-by-supplier date; ordinary Dolibarr search/sort/page helpers.
- Supplier action: `supplier_work_done` is independent of internal diagnosis and of the existing structured result type.
- Dates: each of six business dates has its own native Dolibarr editable date/time field and correction audit. All date corrections are POST/CSRF-protected.
- Documents: native Dolibarr file uploads and external document links on the RMA's own Documents tab. External Paperless links are subject to Dolibarr's URL restrictions (particularly for private or localhost addresses).
- Hard deletion now refuses an RMA with related files/links; previous audit and stock protections remain.

## Schema and activation

Two additive columns are introduced:

- `llx_svc_supplier_rma.supplier_work_done TEXT NULL`
- `llx_svc_supplier_rma_log.date_effective DATETIME NULL`

`modWarrantySvc::upgradeForkSchema()` adds missing columns idempotently. When the effective date column is first introduced, existing CREATE/STATUS events are backfilled from the recorded `date_event`, **once**. Later reactivations do not overwrite intentionally corrected/cleared effective dates. There is no destructive migration.

The existing `date_event` continues to mean **time of recording**. The new `date_effective` means **when the business event happened**. The timeline displays both. Correcting a business date also updates the latest matching status milestone's effective date while emitting a separate DATE audit event (with the previous value, new value and an optional reason). Audit rows retain their original recorded timestamps.

When sending status transitions, POST is used rather than a mutating GET.

## Sandbox regression checklist

1. **Migration:** back up the sandbox database, refresh the module files, disable/enable WarrantySvc once, and verify both new columns. Re-enable again: no schema errors, no duplicate data or changed effective dates.
2. **Navigation:** see the new submenu under customer Service Requests. List all RMAs, then check the open/service presets, sorting and pagination. Verify access with an RMA-read-only user and deny access without this permission.
3. **Existing data:** old RMA cards, status, contacts, parent Service Request links, shipment and stock movement values must stay unchanged.
4. **Supplier action:** edit the description of work performed (e.g. firmware update, component replacement). Save, reload and confirm it is independent from the problem, diagnosis and `result_type` fields.
5. **Backdated business date:** mark an RMA as received, then edit the receive timestamp to an earlier day. The RMA field and effective timeline time must change; the recorded timestamp must not. The separate DATE audit event shows old/new values and user.
6. **Empty or incorrect date:** clear an optional business date and ensure the request date cannot be cleared. Invalid input is rejected, and updates from a stale browser tab should fail rather than overwrite a concurrent change.
7. **Workflow:** exercise draft → authorized → shipped → supplier received → in service → repaired/replaced/rejected → returned → closed. Verify POST status forms and that a manually prepopulated date is respected when the corresponding status is reached. Test rollback.
8. **Documents:** upload an RMA PDF and a service report from the new tab; verify links/downloads and that files stay confined to this RMA. Add a Paperless HTTPS link permitted by the Dolibarr security configuration. Confirm the RMA cannot be hard-deleted while a file or link exists.
9. **Other modules:** customer service requests, Warranty Letters, Warranties and Supplier Returns still load and can be edited as before.

A PHP lint pass cannot verify database migration, HTTP actions, file permissions or actual Dolibarr rendering. Finish those checks in the sandbox before considering production integration.
