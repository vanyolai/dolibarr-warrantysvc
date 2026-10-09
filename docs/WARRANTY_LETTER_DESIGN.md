# Warranty Letter v2 — shipment-centric warranty workflow

Feature branch: `feature/warranty-letter-v2`. No direct changes to dist or production.

## Domain model

Individual `svc_warranty` records remain the source of truth for product, LOT/SN,
coverage start/end and service eligibility. The normal user-facing warranty view
groups those rows by Shipment.

A `SvcWarrantyLetter` is a customer-scoped document. It no longer owns
`fk_expedition` or `fk_commande` columns. Shipments are assigned through
`svc_warranty_letter_shipment`.

Relationship:

```
Customer
  +-- Shipment A
  |     +-- warranty / serial rows
  +-- Shipment B
  |     +-- warranty / serial rows
  |
  +-- Warranty Letter
        +-- Shipment A
        +-- Shipment B
```

All shipments on one letter must belong to the same customer. A shipment may
belong to at most one warranty letter; additional deliveries are added to the
same letter and represented by a new immutable PDF revision.

## Creation lifecycle

Shipment/order triggers continue to create missing `svc_warranty` rows using
the existing warranty-duration, LOT/SN and shipment-date policy. They no longer
create warranty letters automatically.

The shipment-centric warranty list is the normal entry point. Users can select
one or more unassigned shipments belonging to the same customer and create a
letter. A Shipment card can also start with one shipment or navigate to the
grouped list to combine deliveries.

Additional unassigned shipments for the same customer can be attached later.
This marks the current PDF stale. The user explicitly creates the next PDF
revision before sending it.

## Immutable revisions

`svc_warranty_letter_version` stores the complete historical JSON snapshot,
relative PDF path, SHA256, revision number, timestamp and creator.

Each snapshot contains:

- issuer and customer address snapshot;
- all shipment references and shipment dates;
- related order references per shipment;
- grouped warranty rows per shipment;
- all source warranty IDs.

Sent PDF revisions are immutable. Unsent revisions may be explicitly deleted
from the Warranty Letter card; deleting one removes both its registry row and
physical file transactionally. A current warranty change or a change to the
attached shipment set makes the current revision stale but never alters an
already sent PDF.

## Numbering and PDF model

Warranty Letters use a selectable numbering module and PDF model in the
WarrantySvc setup page. The standard numbering model is:

`WL-YYYY-MMUID`

where `YYYY-MM` is the creation month and `UID` is the letter's continuous
database identifier padded to at least four digits. The UID never resets at
month or year boundaries.

The standard PDF follows Dolibarr core document conventions for company logo,
document identity, sender/recipient frames, footer, margins and pagination.

## PDF grouping

The PDF renders a separate section for every shipment. Inside a shipment,
warranties are grouped by:

`(product ID, warranty start date, warranty expiry date)`

Quantities are summed and serial/LOT identifiers are listed in that row.

## Email

Email uses Dolibarr's native `card_presend.tpl.php` and
`actions_sendmails.inc.php` pipeline with template type
`svcwarrantyletter`. Multi-shipment substitutions include
`__SHIPMENT_REFS__` and `__ORDER_REFS__`; the singular legacy names resolve
to the same comma-separated values for compatibility.

The exact immutable PDF attachment is checked before delivery. The post-send
trigger records the actual PDF version delivered. The private mail-audit table
remains the evidence source, while user-facing history is also written as native
Dolibarr Agenda events.

## Migration from the abandoned v1 development model

If the previous development-only letter table is found with
`fk_expedition`, activation copies that relation into
`svc_warranty_letter_shipment`, removes the old shipment/order indexes and
drops `fk_expedition` and `fk_commande` from the letter header.

This migration exists only to make repeated sandbox testing safe; the v1 model
was never intended for production deployment.

## Sandbox acceptance scenarios

1. Refresh the sandbox from production and activate WarrantySvc repeatedly.
2. Existing warranty rows appear grouped by shipment without modifying them.
3. Select two shipments for the same customer and create one letter.
4. Selecting shipments from different customers is rejected transactionally.
5. A shipment already assigned to another letter cannot be selected again.
6. PDF shows one shipment section per selected shipment and grouped serials.
7. Add another shipment after v1 exists: current document becomes stale.
8. Generate v2: v1 remains byte-for-byte unchanged and downloadable.
9. Modify a source warranty: sending is blocked until a new revision is made.
10. Send through the native mail composer and verify the exact PDF version in
    the mail audit.
11. Tamper with a PDF: hash mismatch blocks download/send.
12. Delete an unsent revision and verify that both metadata and the physical
    PDF disappear; sent revisions must remain protected.
13. Verify the native Dolibarr card header, linked-files block and Agenda event
    list.
14. Only after sandbox acceptance should a dist/release commit be created.

PDF generation occurs inside a caller-owned DB transaction. If a later
revision-registration step fails, the generated file is explicitly cleaned up,
so rolled-back revisions do not leave orphan PDFs.
