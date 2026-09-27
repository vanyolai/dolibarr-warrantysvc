# Supplier RMA and Loaner lifecycle design

## Goal

Extend WarrantySvc from customer-side warranty handling into a complete service lifecycle:

1. customer reports a faulty unit;
2. faulty unit may return to our warehouse;
3. the unit may be sent to a supplier/manufacturer service center;
4. a temporary loaner unit may be issued to the customer;
5. supplier repair/replacement is tracked until the unit returns;
6. the loaner is returned and checked back into stock;
7. all movements remain linked to the originating Service Request.

The existing customer-facing warranty record remains the source of customer warranty coverage. Supplier-side warranty is a separate concern.

## Design principles

- Do not overload the existing customer return/replacement fields on `svc_request`.
- Supplier service and loaner issue are child business objects of a Service Request.
- Reuse Dolibarr Product, ThirdParty, Warehouse, stock movement, LOT/serial and document infrastructure.
- Every irreversible stock movement must be idempotent and traceable by its movement row id.
- A loaner remains company-owned and must never create customer ownership/warranty records.
- Supplier warranty information may read the existing Product LOT `eatby` value as supporting data, but it is not customer warranty data.

## Supplier Service RMA

### Table: llx_svc_supplier_rma

Suggested fields:

- rowid
- ref
- entity
- fk_svc_request
- fk_soc_supplier
- fk_product
- serial_number
- supplier_rma_ref
- status
- date_request
- date_authorized
- date_shipped
- date_supplier_received
- date_supplier_completed
- date_returned
- outbound_carrier
- outbound_tracking
- return_carrier
- return_tracking
- result_type
- replacement_serial_number
- problem_description
- diagnosis
- accessories_sent
- fk_warehouse_source
- fk_warehouse_return
- fk_stock_movement_out
- fk_stock_movement_in
- note_private
- fk_user_creat
- fk_user_modif
- date_creation
- tms

### Statuses

- draft
- authorized
- shipped
- received_by_supplier
- in_service
- repaired
- replaced
- rejected
- returned
- closed
- cancelled

### Result types

- repaired
- replaced
- rejected
- credit
- no_fault_found
- other

### Stock semantics

When the defective unit is sent to supplier service:
- it leaves the selected local warehouse through a real Dolibarr stock movement;
- the movement id is stored on the Supplier RMA record;
- repeating the action must not create another movement.

When the unit or supplier replacement returns:
- it enters the selected return warehouse through a real stock movement;
- if the supplier replaced the serial, both original and replacement serials remain in the history.

## Loaner / temporary replacement

### Table: llx_svc_loaner

Suggested fields:

- rowid
- ref
- entity
- fk_svc_request
- fk_soc
- fk_product
- serial_number
- status
- fk_warehouse_source
- fk_warehouse_return
- date_reserved
- date_out
- date_due
- date_returned
- outbound_carrier
- outbound_tracking
- return_carrier
- return_tracking
- condition_out
- condition_in
- fk_stock_movement_out
- fk_stock_movement_in
- note_private
- fk_user_creat
- fk_user_modif
- date_creation
- tms

### Statuses

- reserved
- issued
- returned
- overdue
- lost
- charged
- cancelled

### Ownership

A loaner remains our asset. Issuing it:
- reduces stock in its source warehouse;
- creates no customer WarrantySvc warranty;
- creates no permanent Customer Inventory ownership record.

Returning it:
- moves the same serial into the selected return warehouse;
- records condition-in and return date.

## Supplier RMA PDF

Generate a supplier-facing RMA declaration from the Supplier RMA object.

Contents:
- our company details;
- supplier/service center;
- Service Request reference;
- internal Supplier RMA reference;
- supplier RMA/ticket reference;
- Product ref/label;
- serial/LOT;
- original customer complaint;
- diagnosis / troubleshooting summary;
- accessories shipped;
- physical condition / seal notes;
- shipment date and tracking;
- contact details;
- signature/date block.

Suggested storage:

`warrantysvc/<SRQ-ref>/supplier-rma/<Supplier-RMA-ref>.pdf`

## Service Request UI

Keep the current customer movement section.

Add two separate panels:

### Supplier service
- Create Supplier RMA
- Supplier
- Supplier RMA reference
- supplier warranty expiry (read-only supporting data when resolvable)
- status timeline
- Send to supplier
- Mark supplier received
- Mark repaired/replaced/rejected
- Receive back
- Generate RMA PDF

### Loaner
- Issue loaner
- Warehouse
- Product
- Serial/LOT
- Due date
- Return loaner
- Condition out / in
- overdue badge

## Implementation phases

### Phase 1
- Supplier RMA schema/class
- Loaner schema/class
- permissions
- Service Request child panels
- no stock movement yet

### Phase 2
- stock movement integration
- serial/warehouse validation
- idempotency and reversal rules

### Phase 3
- Supplier RMA PDF
- Documents integration
- supplier tracking/status actions

### Phase 4
- loaner overdue reminders
- optional charging workflow
- service-history enrichment
- supplier/original purchase auto-resolution
