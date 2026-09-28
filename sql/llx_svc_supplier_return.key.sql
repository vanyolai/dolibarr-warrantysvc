ALTER TABLE llx_svc_supplier_return ADD UNIQUE INDEX uk_svc_supplier_return_ref (entity, ref);
ALTER TABLE llx_svc_supplier_return ADD INDEX idx_svc_supplier_return_supplier (fk_soc_supplier);
ALTER TABLE llx_svc_supplier_return ADD INDEX idx_svc_supplier_return_status (status);
ALTER TABLE llx_svc_supplier_return ADD INDEX idx_svc_supplier_return_order (fk_supplier_order);
ALTER TABLE llx_svc_supplier_return ADD INDEX idx_svc_supplier_return_reception (fk_reception);
