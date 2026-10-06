ALTER TABLE llx_svc_warranty_letter ADD UNIQUE INDEX uk_svc_warranty_letter_shipment (entity, fk_expedition);
ALTER TABLE llx_svc_warranty_letter ADD UNIQUE INDEX uk_svc_warranty_letter_ref (entity, ref);
ALTER TABLE llx_svc_warranty_letter ADD INDEX idx_svc_warranty_letter_order (fk_commande);
ALTER TABLE llx_svc_warranty_letter ADD INDEX idx_svc_warranty_letter_soc (fk_soc);
