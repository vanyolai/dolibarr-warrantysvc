# Official Warranty Letter (starting from 1.46.9)

Feature branch: feature/dolibarr-23-migration. No direct changes to dist or production.

## Data and lifecycle

One SvcWarrantyLetter per shipment via unique (entity, fk_expedition).
Native object links connect the letter with shipment, order, and warranties.
Existing warranty generator and LOT/SN, duration and eligibility policy remain
unchanged. The shipment validation/close trigger invokes the letter creation
only *after* the existing algorithm finishes. ORDER_CLOSE uses the same helper.

A letter starts in draft. Its initial PDF creates ready status; native email
sending creates sent status; regeneration after sending creates updated status
until the new version is sent. The letter is append-only for PDF versions:
svc_warranty_letter_version stores a full historical JSON snapshot of the issuer,
recipient, shipment, order and warranty grouping; immutable relative PDF path;
SHA256; revision number; creation timestamp and user. Version numbers never
overwrite old PDFs, including unsent ones. The mail table associates the actual
sent PDF version with email sender/user, recipient, subject and message ID.

Email uses Dolibarr 23 native card_presend.tpl.php and actions_sendmails.inc.php
and template type svcwarrantyletter. Attachment integrity and presence are
validated before delivery. Post-send trigger records immutable version sent.
The deprecated warranty_confirmation.php redirects to the new letter card.

## Grouping and rendering

Each PDF row groups warranties by (product ID, start date, expiry date),
summing covered quantities and listing all serial/LOT numbers. The PDF renderer
reads the frozen snapshot, not live warranty data. The customer sees the issuer,
buyer, shipment and order identifiers, document ref and version.

## Deployment and validation

On an already installed module, migrations add only missing letter tables,
without re-running the existing module full SQL bootstrap. Fresh installs load
the SQL files as normal. Existing 1.46.9 editable HTML mail templates remain
untouched but no new legacy templates are installed.

Required sandbox acceptance scenarios before dist:
1. Migration against an existing 1.46.9 database and repeated activation.
2. Two serial numbers for same product+dates produce a single grouped row;
   different start/end dates produce separate rows. Verify long lists across pages.
3. No eligible warranties -> no letter; repeated validate/close -> no duplicates.
4. Native links appear on shipment, customer order, each warranty.
5. Send exactly one PDF from native mail composer; check email log and agenda.
6. Remove required attachment: Send blocked before physical delivery.
7. Tamper with file on disk: hash mismatch blocks download/send.
8. Change warranty after v1 mailed and explicitly generate v2: v1 remains intact.
9. Access controls disallow unauthorized/third-party users.
10. After sandbox test, create separate dist commit; production remains untouched.

Note: rolled-back DB transactions after PDF output can leave unreferenced files.
An orphan reconciliation tool should be added before general deployment.
