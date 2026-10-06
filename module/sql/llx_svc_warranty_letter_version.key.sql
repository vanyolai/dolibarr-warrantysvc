ALTER TABLE llx_svc_warranty_letter_version ADD UNIQUE INDEX uk_svc_warranty_letter_version (fk_letter, version);
ALTER TABLE llx_svc_warranty_letter_version ADD INDEX idx_svc_warranty_letter_version_entity (entity, fk_letter);
