# WarrantySvc — Warranty & Service Management for Dolibarr

**Fork target:** Dolibarr 23.x | **Upstream base:** WarrantySvc 1.36.0 | License: GPL-3.0

## Overview

> This fork keeps the upstream RMA/service workflow but adds a Dolibarr 23 compatibility layer and an optional **Product-field warranty model**. In that mode, customer warranty duration comes from one configurable integer Product extrafield expressed in calendar months. Warranty Type remains available as the original upstream mode, but the two duration models are mutually exclusive.


WarrantySvc adds full RMA (Return Merchandise Authorization) and warranty management to Dolibarr for serialized equipment. Track warranties per serial number, manage service requests through a complete lifecycle, and generate PDF authorization slips -- all from within your existing Dolibarr installation.

## Features

### Service Requests

Create and manage service requests for warranted equipment. The normal workflow is diagnosis-first:

1. **Draft** -- Initial creation, still editable
2. **Validated** -- Submitted for review
3. **Diagnosing** -- Remote troubleshooting and, when needed, physical Customer Return
4. **In Progress** -- Diagnosis is complete and the selected repair/replacement path is being executed
5. **Resolved** -- Work complete, resolution recorded
6. **Closed** -- Finalized and archived

**Awaiting Return** remains available for explicit/legacy return-waiting flows, but Customer Return itself is a linked logistics object and does not require a Resolution Type.

Each service request supports one of **7 resolution types**: Component Shipment, Component Shipment + Return, Full Unit Swap (Cross Ship), Full Unit Swap (Wait for Return), On-Site Service, Guidance Only, and Informational.

- **Security Seal tracking** -- Record the unique number from the physical security sticker shipped with repaired parts. An intact sticker on the closed service record confirms authorized warranty service was performed. A missing or broken seal voids warranty coverage.

### Warranties

- **Serialized and line-level warranties** -- Serial/LOT units get per-unit records; ordinary products can be covered at shipment-line level
- **Product-field duration mode** -- Use a configurable Product integer extrafield as the sole warranty-duration source in calendar months
- **Historical duration snapshot** -- Each created Product-field warranty stores the granted month count so later Product policy changes do not rewrite history
- **Warranty Type mode** -- The original upstream day-based Warranty Type workflow remains available as an alternative mode
- **Auto-warranty on shipment or order** -- Automatically create warranty records when shipments are validated or orders are confirmed
- **Extrafields support** -- Add custom fields to warranty records for your specific business needs

### PDF Authorization Slips

Generate printable PDF authorization slips from any service request. These include the RMA number, customer details, product and serial information, and authorization terms. Send them to customers as proof of RMA approval.

### Warranties Tab on Third-Party Cards

A dedicated "Warranties" tab appears on each customer's third-party card, showing all warranty records associated with that customer in one place.

## Requirements

| Requirement | Details |
|---|---|
| Dolibarr | Fork tested/targeted for 23.x; upstream supports older versions |
| PHP | Use the PHP version supported by the installed Dolibarr 23 release |
| **Required modules** | Third Parties, Products, Stock |
| **Optional modules** | Shipments, Orders, Projects, Customer Returns, Notifications |

Enabling optional modules unlocks additional features such as auto-warranty creation on shipment and RMA-initiated returns.

## Installation

1. Download the latest `.zip` file from the [GitHub Releases](https://github.com/zacharymelo/Dolibarr-Warranties/releases) page
2. Log in to your Dolibarr instance as an administrator
3. Navigate to **Home > Setup > Modules/Applications**
4. Click the **Deploy external module** button at the top of the page
5. Upload the `.zip` file you downloaded
6. Once uploaded, find "WarrantySvc" in the module list and click the toggle to **enable** it
7. After enabling, click the gear icon to open the **Admin Setup** page and configure the module

## Configuration

After installation, go to the module's admin setup page to configure the following options:

- **Replacement Warehouse** -- The warehouse from which replacement units are shipped
- **Return Warehouse** -- The warehouse where returned items are received into stock
- **Return Reminder Delay** -- Number of days after the expected return date before a reminder is sent
- **Auto-Invoice After Days** -- Automatically generate an invoice if a return is not received within this many days
- **Replacement Strategy** -- Controls how the system selects replacement stock. Set to FIFO (First In, First Out) to ship the oldest units first
- **Auto-Check Warranty on Creation** -- Automatically verify warranty status when a new service request is created
- **Auto-Create Warranty on Shipment** -- Automatically generate a warranty record when a shipment is validated
- **Warranty Creation Trigger** -- Choose the shipment event used for automatic warranty creation
- **Warranty Duration Source** -- Choose either Product field (calendar months) or Warranty Type / upstream logic
- **Product Warranty Duration Field** -- In Product-field mode, select the integer Product extrafield that stores the customer warranty in months

### Notifications

WarrantySvc lifecycle emails use Dolibarr's standard **Notifications** module rather than a separate mail-recipient system. Enable the Notifications module, then configure recipients in the normal Dolibarr notification pages (user subscriptions, third-party contacts, or fixed addresses). Event-specific message content can use normal Dolibarr Email Templates of type **Warranty Service Request** or **Warranty**.

WarrantySvc exposes events for Service Request creation, assignment, validation, diagnosis, In Progress, Awaiting Return, resolution, closure, cancellation, reopening and deletion, plus Warranty creation. Sent notification emails are recorded by Dolibarr in its standard notification history.

The scheduled overdue-return reminder remains a transactional workflow email; it is not an event subscription.

## Usage Guide

### Creating Warranties

The fork supports two mutually exclusive warranty-duration sources:

- **Product field (calendar months)** -- Recommended for the fork workflow. The selected Product extrafield is authoritative. The created warranty stores the granted month count and expiry as historical data.
- **Warranty Type / upstream logic** -- Preserves the original upstream day-based defaults, Product Warranty Type overrides, terms and exclusions.

A shipment warranty starts from the shipment date resolved by the module. In Product-field mode, expiry is calculated with calendar-month arithmetic (including end-of-month clamping).

### Managing Warranty Types

Warranty Types are only part of the upstream duration mode. In Product-field mode their menus, Product fields and warranty-card controls are hidden so there is no second source of truth for duration.

### Creating Service Requests

1. Navigate to the Service Requests menu
2. Click "New Service Request"
3. Select the customer and the serial number -- the system will display the current warranty status
4. Describe the issue and save the request as a Draft
5. Validate the request and start diagnosis
6. If the physical unit is needed, create a linked Customer Return during diagnosis
7. Complete diagnosis, then choose the appropriate resolution/work path

### Service Request Lifecycle

Use the status buttons on each service request card to advance through Draft, Validated, Diagnosing, In Progress, Resolved, and Closed. Customer Return can be created during Diagnosing without preselecting a resolution. Once diagnosis is complete, move the request to In Progress and select the repair/replacement path.

### PDF Generation

On any validated or later-stage service request, click the "Generate PDF" button to create an authorization slip. This PDF can be downloaded or emailed to the customer as their RMA authorization document.

## Optional Integrations

### Customer Returns Module

When the [Customer Returns](https://github.com/zacharymelo/doli-returns) module is also installed and enabled, WarrantySvc can initiate inbound returns directly from service requests. This links the return process to the RMA workflow, allowing stock movements and credit notes to be handled through Customer Returns while maintaining full traceability back to the originating service request.

## Screenshots

**Service Requests List**
![Service Requests List](docs/screenshots/service-requests-list.png)

**Warranty List**
![Warranty List](docs/screenshots/warranty-list.png)

**New Warranty Form**
![New Warranty Form](docs/screenshots/new-warranty-form.png)

**Admin Setup**
![Admin Setup](docs/screenshots/admin-setup.png)

## License

This module is licensed under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html).
