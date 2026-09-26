-- This fork starts from upstream v1.36.x where coverage_days already exists.
-- coverage_months below is a separate immutable snapshot for Product-field mode.

-- v1.9.0: add coverage_terms and exclusions template fields to warranty type dictionary
ALTER TABLE llx_svc_warranty_type ADD COLUMN coverage_terms TEXT AFTER description;
ALTER TABLE llx_svc_warranty_type ADD COLUMN exclusions TEXT AFTER coverage_terms;


-- vanyolai fork: unified serialized and non-serialized warranty model
-- serial_number becomes optional; non-serialized warranties are tied to the
-- originating shipment line and carry the covered shipment quantity.
ALTER TABLE llx_svc_warranty MODIFY serial_number VARCHAR(128) NULL;
ALTER TABLE llx_svc_warranty ADD COLUMN covered_qty DECIMAL(24,8) DEFAULT 1 AFTER serial_number;
ALTER TABLE llx_svc_warranty ADD COLUMN fk_expeditiondet INTEGER AFTER fk_expedition;

-- The upstream unique serial index prevents legitimate re-sale/re-warranty of
-- a returned serial. Idempotency is enforced by shipment origin in application code.
ALTER TABLE llx_svc_warranty DROP INDEX uk_svc_warranty_serial;
ALTER TABLE llx_svc_warranty ADD INDEX idx_svc_warranty_serial (serial_number, entity);
ALTER TABLE llx_svc_warranty ADD INDEX idx_svc_warranty_fk_expeditiondet (fk_expeditiondet);

-- vanyolai fork: snapshot the calendar-month duration used when a warranty is created
ALTER TABLE llx_svc_warranty ADD COLUMN coverage_months INTEGER DEFAULT NULL AFTER coverage_days;
