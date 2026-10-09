ALTER TABLE llx_svc_warranty_letter ADD UNIQUE INDEX uk_svc_warranty_letter_ref (entity, ref);
ALTER TABLE llx_svc_warranty_letter ADD INDEX idx_svc_warranty_letter_soc (fk_soc);

ALTER TABLE llx_svc_warranty_letter ADD INDEX idx_svc_warranty_letter_status (entity, status);
ALTER TABLE llx_svc_warranty_letter ADD INDEX idx_svc_warranty_letter_date (entity, date_creation);
