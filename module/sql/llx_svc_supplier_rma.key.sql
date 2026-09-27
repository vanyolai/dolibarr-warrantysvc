-- Copyright (C) 2026 DPG Supply
ALTER TABLE llx_svc_supplier_rma ADD UNIQUE INDEX uk_svc_supplier_rma_ref (entity, ref);
ALTER TABLE llx_svc_supplier_rma ADD INDEX idx_svc_supplier_rma_request (fk_svc_request);
ALTER TABLE llx_svc_supplier_rma ADD INDEX idx_svc_supplier_rma_supplier (fk_soc_supplier);
ALTER TABLE llx_svc_supplier_rma ADD INDEX idx_svc_supplier_rma_product (fk_product);
ALTER TABLE llx_svc_supplier_rma ADD INDEX idx_svc_supplier_rma_status (status);
