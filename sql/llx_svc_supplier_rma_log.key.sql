-- Copyright (C) 2026 DPG Supply
ALTER TABLE llx_svc_supplier_rma_log ADD INDEX idx_svc_supplier_rma_log_rma (fk_supplier_rma);
ALTER TABLE llx_svc_supplier_rma_log ADD INDEX idx_svc_supplier_rma_log_date (date_event);
