# Warranty & Service Management — Test Plan

**Modules:** vanyolai/dolibarr-warrantysvc fork + Customer Returns
**Target environment:** Dolibarr 23.x

---

## Prerequisites

- [ ] Both modules installed and enabled
- [ ] At least one warehouse configured
- [ ] At least one customer (third party, type=customer)
- [ ] At least one active supplier (third party, supplier=yes)
- [ ] At least one serialized product and one ordinary non-serialized product
- [ ] At least one LOT-tracked product with stock in the test warehouse
- [ ] An integer Product extrafield containing customer warranty duration in calendar months
- [ ] Stock module enabled
- [ ] Shipments module enabled
- [ ] Orders module enabled
- [ ] Notifications module enabled for notification tests

---

## 1. WARRANTY LIFECYCLE

### 1.1 Create Warranty Manually — Product-field mode
- [ ] Set Warranty duration source = Product field (calendar months)
- [ ] Select the configured Product warranty-month extrafield
- [ ] Navigate to Warranties > New Warranty
- [ ] Select customer, product and serial number where applicable
- [ ] Verify Warranty Type / terms / exclusions controls are hidden
- [ ] Save — verify expiry = start date + Product calendar months
- [ ] Verify the created record stores coverage_months as an immutable snapshot
- [ ] Change the Product warranty-month value afterwards — verify the existing warranty expiry does not change
- [ ] Verify warranty appears in Warranty List with correct filters

### 1.2 Auto-Create Warranty on Shipment
- [ ] Enable automatic warranty creation and select the intended shipment event
- [ ] Create a Sales Order containing a serialized product and an ordinary product
- [ ] Create and validate/close a shipment from that SO
- [ ] Verify one warranty is created per shipped serial/batch allocation
- [ ] Verify one line-level warranty is created for the non-serialized shipment line
- [ ] Verify start date comes from the shipment date, not the current time
- [ ] Verify expiry uses calendar-month arithmetic from the configured Product field
- [ ] Verify shipment line, shipment, order, quantity and serial (when present) are persisted
- [ ] Repeat the trigger event — verify no duplicate warranty records are created

### 1.3 Void Warranty
- [ ] Open a warranty card > click "Void"
- [ ] Confirm void — verify status changes to Voided
- [ ] Verify voided warranty cannot be used when creating a new SR

### 1.4 Product Warranty Policy
- [ ] In Product-field mode open a Product card
- [ ] Verify the configured Product extrafield is the only visible warranty-duration policy
- [ ] Verify "Default Warranty Terms" / Warranty Type rows are not injected by WarrantySvc
- [ ] Change the Product warranty duration and save
- [ ] Create a new warranty — verify the new value is used without changing older warranty snapshots
- [ ] Switch to Warranty Type / upstream mode — verify the upstream Product defaults become visible again

---

## 2. SERVICE REQUEST LIFECYCLE

### 2.0 New Claim Warranty Picker
- [ ] Select a customer — only that customer's non-voided WarrantySvc records appear in the table
- [ ] Verify Product, serial/LOT, warranty ref, start, expiry and effective status columns
- [ ] Change Issue Date across a warranty expiry boundary — Active/Expired status changes immediately
- [ ] Search by Product ref/label, serial/LOT and warranty ref
- [ ] Select a warranty row — Create becomes enabled and the submitted claim derives Product/serial from the selected Warranty record
- [ ] Open New Warranty Claim from a warranty card — the corresponding row is pre-selected
- [ ] Switch to warranty-less / other service intake — Product + optional serial controls appear and the claim starts billable
- [ ] Switch back — manual controls hide and a Warranty row is required again
- [ ] Change customer after selecting a warranty — previous selection is cleared

### 2.1 Create Service Request
- [ ] Navigate to Service Requests > New Service Request
- [ ] Select customer, product, serial number
- [ ] Verify warranty auto-lookup occurs (if serial has active warranty)
- [ ] Do not select a resolution type at intake; resolution is chosen only after diagnosis
- [ ] Save — verify SR created in Draft status

### 2.2 Warranty Claim Count
- [ ] Note the claim_count on the linked warranty BEFORE creating the SR
- [ ] Create an SR linked to that warranty
- [ ] Verify warranty claim_count incremented by 1
- [ ] Create a second SR for the same warranty — verify count is now +2

### 2.3 Status Transitions
- [ ] **Draft → Validated:** Click Validate > confirm. Verify status badge changes.
- [ ] **Validated → Diagnosing:** Click "Begin Diagnosis" > confirm.
- [ ] **Diagnosing → In Progress:** Complete diagnosis and move to In Progress; Resolution Type may still be empty until a concrete work path is known.
- [ ] **In Progress → Awaiting Return:** (only for return-type resolutions) Click "Set Awaiting Return" > confirm.
- [ ] **Awaiting Return → In Progress:** (via return reception — see section 4)
- [ ] **In Progress → Resolved:** Click "Mark Resolved" > confirm.
- [ ] **Resolved → Closed:** Click "Close" > confirm.
- [ ] **Draft → Cancelled:** Click "Cancel" > confirm. Verify SR shows as Cancelled.
- [ ] **Cancelled → Draft:** Click "Re-open" > verify SR returns to Draft.

### 2.4 Edit Service Request
- [ ] In Draft or Validated status, click Edit
- [ ] Modify label, description, resolution type, assigned user, notes
- [ ] Save — verify changes persisted
- [ ] Verify editing is blocked when Closed

### 2.5 Component Lines
- [ ] In edit mode, add a component line (product, qty, description)
- [ ] Save — verify line appears in the lines table
- [ ] Add a second line, delete the first — verify correct behavior
- [ ] Verify lines are read-only when not in edit mode

### 2.6 Notes Tab
- [ ] Click Notes tab on an SR
- [ ] Add a public note and a private note
- [ ] Save — verify both persist
- [ ] Verify public/private visibility labels are correct


### 2.8 Standard Dolibarr Notifications
- [ ] Disable/enable WarrantySvc once after upgrading so the new action catalog and hook contexts are registered
- [ ] Open Dolibarr Notifications setup and verify only two WarrantySvc mail events are listed: `WARRANTYSVC_ASSIGNED`, `SVCWARRANTY_CREATE`
- [ ] Open a user Notifications tab and verify the same two WarrantySvc events can be subscribed using the normal Dolibarr UI
- [ ] Open Email Templates and verify **Warranty Service Request** and **Warranty** are available template types
- [ ] With no WarrantySvc event subscription configured, validate/close an SR — verify no module-specific direct email is sent
- [ ] Subscribe a test user or fixed address to `WARRANTYSVC_ASSIGNED`; assign/reassign an SR — verify one notification is sent through Dolibarr
- [ ] Verify the sent message is recorded in Dolibarr notification history (`llx_notify`)
- [ ] On the fixed/automatic notification screen, verify the net-amount threshold field is hidden for both WarrantySvc events
- [ ] Subscribe a customer contact to a WarrantySvc create event and verify a freshly created SR/garancia can notify through the normal third-party-contact subscription path
- [ ] Configure an event-specific Email Template and verify its subject/body substitutions are used
- [ ] Verify the Email Template variable help lists `__PRODUCT_REF__`, `__PRODUCT_LABEL__`, `__SERIAL_NUMBER__`, `__WARRANTY_STATUS__`, `__WARRANTY_START_DATE__`, `__WARRANTY_EXPIRY_DATE__`, `__ISSUE_DATE__`, `__ISSUE_DESCRIPTION__`, `__SERVICE_REQUEST_STATUS__`
- [ ] Send a Service Request notification and verify all applicable WarrantySvc variables are replaced with real values
- [ ] Send a Warranty-created notification and verify Product/serial/warranty dates/status are replaced with real values
- [ ] Verify Warranty creation uses `SVCWARRANTY_CREATE` subscriptions instead of the removed WarrantySvc-specific notify toggle

---

## 3. OUTBOUND REPLACEMENT (Sales Order)

### 3.1 Create Replacement Order
- [ ] On an SR with resolution type `component` or `swap_cross`
- [ ] Verify "Replacement Order" row appears in RMA Actions panel
- [ ] Click "Create Replacement Order"
- [ ] Verify Dolibarr's native SO creation page opens
- [ ] Verify customer is pre-filled
- [ ] Create the SO with a product line
- [ ] Return to the SR card — verify SO link now appears in the Replacement Order row
- [ ] Verify the SO also shows the SR in its Linked Objects section

### 3.2 Resolution Types Without Outbound
- [ ] Create an SR with resolution type `guidance` or `informational`
- [ ] Verify the Replacement Order row does NOT appear in RMA Actions
- [ ] Verify the Return Reception row does NOT appear

---

## 4. INBOUND RETURN — Customer Returns Integration

### 4.1 Enable Integration
- [ ] Navigate to Warranty/RMA > Setup
- [ ] Verify "Use Customer Returns module" checkbox is visible
- [ ] If customerreturn module is not enabled, verify the checkbox is disabled with warning message
- [ ] Enable the customerreturn module, return to setup page
- [ ] Check the "Use Customer Returns module" checkbox > Save
- [ ] Verify setting persisted on page reload

### 4.2 Create Return from SR Card
- [ ] Open an SR with resolution type `component_return` or `swap_cross` (must be Validated or In Progress)
- [ ] Verify the Return Reception row shows "Create Customer Return" button (not the old Reception form)
- [ ] Click "Create Customer Return"
- [ ] Verify customerreturn creation page opens with:
  - Customer pre-filled
  - Shipment pre-filled (if SR has a linked shipment)
  - `from_svcrequest` param in URL
- [ ] Select shipment lines and quantities to return
- [ ] Select receiving warehouse
- [ ] Click Create
- [ ] Verify return created successfully with ref (RT-YYMM-NNNN format)

### 4.3 Verify Linking
- [ ] On the new customer return card, check Linked Objects section
- [ ] Verify the SR appears as a linked object
- [ ] Go back to the SR card
- [ ] Verify the Return Reception row now shows the customer return ref as a link
- [ ] Click the link — verify it opens the correct customer return

### 4.4 Validate Return (Stock Movement)
- [ ] On the customer return card, click "Validate"
- [ ] Confirm validation
- [ ] Verify status changes to Validated
- [ ] Navigate to Products > Stock > Movements
- [ ] Verify a stock movement entry exists for each returned line:
  - Type: reception (input)
  - Product: correct product
  - Warehouse: the one selected during creation
  - Qty: positive (items received)
  - Label references the return ref

### 4.5 Auto-Advance SR on Return Validation
- [ ] After validating the return (step 4.4), go back to the SR card
- [ ] If SR was in "Awaiting Return" status: verify it advanced to "In Progress"
- [ ] Verify `date_return_received` is now populated on the SR

### 4.6 Close Return & Credit Note
- [ ] On a Validated customer return, click "Close"
- [ ] Verify status changes to Closed
- [ ] If "Create Credit Note" button appears, click it
- [ ] Verify a draft credit note (Facture avoir) is created
- [ ] Verify the credit note appears in the return's Linked Objects

### 4.7 Fallback Without Integration
- [ ] Disable "Use Customer Returns module" in warrantysvc settings
- [ ] Open an SR with a return-type resolution
- [ ] Verify the Return Reception row shows the old inline Reception form (product/serial pre-filled from warranty, warehouse picker)
- [ ] Re-enable the setting for remaining tests

---

## 5. CUSTOMER RETURNS MODULE (Standalone)

### 5.1 Create Return from Shipment Card
- [ ] Open a validated shipment card
- [ ] Verify "Create Return" button appears (injected by customerreturn hooks)
- [ ] Click it — verify customerreturn creation page opens with shipment pre-selected
- [ ] Create the return — verify it links back to the shipment

### 5.2 Create Return from List
- [ ] Navigate to Products > Customer Returns > New Customer Return
- [ ] Select a customer
- [ ] Select a shipment from the AJAX-loaded list
- [ ] Select lines and quantities
- [ ] Create — verify successful

### 5.3 Customer Return List
- [ ] Navigate to Products > Customer Returns > List
- [ ] Verify list shows all returns with ref, customer, status, date
- [ ] Test filters: search by ref, filter by status
- [ ] Verify sort works on columns

### 5.4 Sidebar Navigation
- [ ] Navigate to Products section
- [ ] Verify "Customer Returns" heading appears in sidebar below Receptions
- [ ] Verify icon and text alignment matches Shipments/Receptions headings
- [ ] Click "New Customer Return" — verify creation page opens
- [ ] Click "List" — verify list page opens

### 5.5 Admin Settings
- [ ] Navigate to Customer Returns setup page
- [ ] Set default receiving warehouse
- [ ] Verify setting persists after save

---

## 6. CROSS-MODULE LINKED OBJECTS

### 6.1 "Link to" Dropdown
- [ ] On any third party card, click "Link to..."
- [ ] Verify "Service Request" and "Customer Return" appear in the dropdown
- [ ] Select one and link — verify it appears in Linked Objects

### 6.2 Bidirectional Display
- [ ] Create an SR linked to a warranty, a sales order, and a customer return
- [ ] On the SR card: verify warranty, SO, and return all show in linked objects or action panel
- [ ] On the warranty card: verify the SR appears in the claims history table
- [ ] On the SO card: verify the SR appears in linked objects
- [ ] On the customer return card: verify the SR appears in linked objects

---

## 7. SETTINGS VERIFICATION

| Setting | Test |
|---------|------|
| Warranty duration source | Product-field mode uses Product calendar months; Warranty Type mode retains upstream day-based behaviour |
| Product warranty duration field | Only valid integer Product extrafields are selectable; blank/invalid configuration is rejected |
| Default Coverage Days | Visible and used only in Warranty Type / upstream mode |
| Return Grace Days | Set to 3 days. Create SR, set to awaiting return. After 3 days, verify overdue indicators |
| Auto Warranty Check | Create SR with serial — verify warranty_status auto-populates |
| Warranty Requires Lots | Enable, then create SR — verify product picker only shows lot-tracked items |
| Numbering Model | Verify SR and warranty refs follow the configured pattern |

---

## 8. SCHEMA / UPGRADE SAFETY

- [ ] Enable the fork over an existing upstream WarrantySvc installation
- [ ] Verify covered_qty, fk_expeditiondet and coverage_months are added only when missing
- [ ] Verify serial_number becomes nullable
- [ ] Verify the old unique serial index is removed on MySQL/MariaDB
- [ ] Disable and re-enable the module — verify schema upgrade remains idempotent
- [ ] Fresh install — verify the base schema already contains all fork fields

---

## 9. EDGE CASES

- [ ] Create SR without a warranty (product not under warranty) — verify it works, warranty_status = "not_covered"
- [ ] Create SR for an expired warranty — verify warranty_status = "expired"
- [ ] Create multiple returns for the same shipment — verify qty_already_returned accumulates correctly
- [ ] Attempt to validate a return when warehouse stock module is disabled — verify graceful error
- [ ] Create a customer return with `from_svcrequest` but warrantysvc module disabled — verify return creates normally without SR linking

---

## 10. SUPPLIER RETURN

### 10.1 Create and edit a Supplier Return
- [ ] Navigate to Products > WarrantySvc > Supplier Returns > New Supplier Return
- [ ] Verify Supplier, source Warehouse and Return reason are required as applicable
- [ ] Create a Draft return and verify the reference follows `SRET-YYYY-NNNNNN`
- [ ] Add an ordinary stock-managed product with quantity > 1
- [ ] Add a LOT-tracked product with a valid LOT and quantity available in the selected warehouse
- [ ] Add a serial-numbered product with quantity 1 and a valid serial
- [ ] Edit and delete lines while the return is Draft
- [ ] Verify Product, quantity, LOT/serial and line reason persist after reload
- [ ] Verify the Hungarian UI shows translated Supplier Return labels instead of raw language keys

### 10.2 Authorization — no physical stock movement yet
- [ ] Record the real warehouse quantities before authorization
- [ ] Draft → Authorized
- [ ] Verify no `llx_stock_mouvement` row is created by authorization
- [ ] Verify warehouse quantities are unchanged
- [ ] Authorized → Draft rollback and verify exactly one ROLLBACK lifecycle event is recorded
- [ ] Re-authorize and verify editing is still allowed before shipment
- [ ] Cancel an unshipped return, then reopen it as Draft

### 10.3 Shipment — atomic stock movement
- [ ] Record stock quantities for every return line immediately before shipment
- [ ] Authorized → Shipped
- [ ] Verify each line creates exactly one outbound `llx_stock_mouvement` row
- [ ] Verify movement `origintype = 'svcsupplierreturn'` and `fk_origin` equals the Supplier Return rowid
- [ ] Verify each movement inventory code is `WSVC-OUT-svcsupplierreturn-<return-id>-<line-id>`
- [ ] Verify each line stores the created movement rowid in `fk_stock_movement_out`
- [ ] Verify ordinary and LOT stock are reduced by exactly the requested quantities
- [ ] Verify the serial-numbered line reduces stock by exactly 1 for the selected serial
- [ ] Verify the Supplier Return changes to Shipped only after all line movements succeed

### 10.4 All-or-nothing failure handling
- [ ] Create an Authorized return with at least two lines
- [ ] Make the second line invalid at shipment time (for example insufficient stock or an invalid/missing LOT)
- [ ] Attempt shipment
- [ ] Verify the return remains Authorized
- [ ] Verify the first line has no committed stock movement and its stock quantity is unchanged
- [ ] Verify no line receives a committed `fk_stock_movement_out`
- [ ] Correct the invalid line and ship again — verify all movements are created once

### 10.5 Idempotency and stale/concurrent requests
- [ ] After a successful shipment, reload and verify the Ship action is no longer available
- [ ] Re-submit the previous shipment request / stale browser confirmation — verify no second stock movement is created
- [ ] Verify the deterministic inventory code still resolves to the original movement
- [ ] Verify an existing deterministic movement with mismatching Product, warehouse, quantity or LOT/serial is rejected as a conflict instead of being silently reused
- [ ] Verify a stale edit submitted after another request ships the return is rejected and does not change the shipped document
- [ ] Verify line add/edit/delete cannot race a shipment into modifying a return after stock has moved

### 10.6 LOT/SN and stock validation
- [ ] Try to ship a LOT-tracked product without a LOT — verify shipment is rejected
- [ ] Try to ship a serial-numbered product with quantity other than 1 — verify shipment is rejected
- [ ] Try to ship more than the available quantity in the selected warehouse/LOT — verify shipment is rejected even if Dolibarr negative stock is otherwise permitted
- [ ] Try to return a non-stock-managed Product/Service — verify shipment is rejected
- [ ] Verify a LOT/serial that exists only in another warehouse cannot be shipped from the selected source warehouse

### 10.7 Post-shipment integrity and lifecycle
- [ ] From Shipped, verify rollback to Authorized is blocked because physical stock has left the warehouse
- [ ] Verify header and line identity/quantity cannot be edited after shipment
- [ ] Verify a shipped return cannot be cancelled or permanently deleted
- [ ] Shipped → Closed
- [ ] Closed → Shipped rollback — verify stock remains out and no new/reverse movement is created
- [ ] Close again and verify lifecycle timestamps and audit history are consistent

### 10.8 Documents, contacts and email
- [ ] Add an internal handler and a supplier contact to the Supplier Return
- [ ] Generate the Supplier Return PDF and verify Supplier, reference, warehouse, reason, Product, quantity and LOT/serial are correct
- [ ] Verify the PDF is stored under the Supplier Return object output directory
- [ ] Open the email form and verify the supplier contact is selected by default; supplier company email is the fallback
- [ ] Verify Email Templates offers the Supplier Return object type
- [ ] Verify `__SUPPLIER_RETURN_REF__`, `__SUPPLIER_RETURN_EXTERNAL_REF__`, `__SUPPLIER_RETURN_STATUS__`, `__SUPPLIER_RETURN_REASON__` and `__SUPPLIER_RETURN_LINES__` are replaced with real values
- [ ] Verify `__SUPPLIER_NAME__`, `__OUTBOUND_CARRIER__`, `__OUTBOUND_TRACKING__` and `__OUTBOUND_TRACKING_URL__` are populated for Supplier Return emails

---

## Test Results

| Section | Pass | Fail | Notes |
|---------|------|------|-------|
| 1. Warranty Lifecycle | | | |
| 2. Service Request Lifecycle | | | |
| 3. Outbound Replacement | | | |
| 4. Inbound Return Integration | | | |
| 5. Customer Returns Standalone | | | |
| 6. Cross-Module Linked Objects | | | |
| 7. Settings Verification | | | |
| 8. Schema / Upgrade Safety | | | |
| 9. Edge Cases | | | |
| 10. Supplier Return | | | |
