-- Compatibility upgrade for installations coming from upstream WarrantySvc.
-- This file is intentionally idempotent because Dolibarr executes llx_*.sql
-- files whenever the module is enabled.

ALTER TABLE llx_svc_warranty_type ADD COLUMN IF NOT EXISTS coverage_terms TEXT AFTER description;
ALTER TABLE llx_svc_warranty_type ADD COLUMN IF NOT EXISTS exclusions TEXT AFTER coverage_terms;

-- Unified serialized and non-serialized warranty model.
ALTER TABLE llx_svc_warranty MODIFY serial_number VARCHAR(128) NULL;
ALTER TABLE llx_svc_warranty ADD COLUMN IF NOT EXISTS covered_qty DECIMAL(24,8) DEFAULT 1 AFTER serial_number;
ALTER TABLE llx_svc_warranty ADD COLUMN IF NOT EXISTS fk_expeditiondet INTEGER AFTER fk_expedition;

-- Product-field mode stores the calendar-month duration that was actually
-- granted at creation time. This preserves history if the Product policy
-- changes later.
ALTER TABLE llx_svc_warranty ADD COLUMN IF NOT EXISTS coverage_months INTEGER DEFAULT NULL AFTER coverage_days;

-- Upstream used a unique serial index. A returned serial can legitimately be
-- sold and covered again, so uniqueness is enforced by shipment origin in
-- application logic instead.
ALTER TABLE llx_svc_warranty DROP INDEX IF EXISTS uk_svc_warranty_serial;
