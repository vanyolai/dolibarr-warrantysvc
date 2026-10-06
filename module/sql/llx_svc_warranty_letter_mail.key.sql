ALTER TABLE llx_svc_warranty_letter_mail ADD INDEX idx_svc_warranty_letter_mail_letter (fk_letter, date_sent);
ALTER TABLE llx_svc_warranty_letter_mail ADD INDEX idx_svc_warranty_letter_mail_version (fk_version);
