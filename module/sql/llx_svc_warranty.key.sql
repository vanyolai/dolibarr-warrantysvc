-- Copyright (C) 2026 DPG Supply
-- Idempotent indexes: Dolibarr can execute module key files again on re-enable.
CREATE INDEX IF NOT EXISTS idx_svc_warranty_serial ON llx_svc_warranty (serial_number, entity);
CREATE INDEX IF NOT EXISTS idx_svc_warranty_fk_soc ON llx_svc_warranty (fk_soc);
CREATE INDEX IF NOT EXISTS idx_svc_warranty_fk_product ON llx_svc_warranty (fk_product);
CREATE INDEX IF NOT EXISTS idx_svc_warranty_status ON llx_svc_warranty (status);
CREATE INDEX IF NOT EXISTS idx_svc_warranty_fk_expeditiondet ON llx_svc_warranty (fk_expeditiondet);
