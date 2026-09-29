-- Copyright (C) 2026 DPG Supply
CREATE TABLE llx_svc_supplier_return_line(
	rowid                    INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_supplier_return       INTEGER NOT NULL,
	fk_product               INTEGER NOT NULL,
	qty                      DECIMAL(24,8) NOT NULL DEFAULT 1,
	batch                    VARCHAR(128),
	fk_supplier_order_line   INTEGER,
	fk_reception_line        INTEGER,
	reason                   TEXT,
	fk_stock_movement_out    INTEGER,
	fk_stock_movement_reversal INTEGER,
	rang                     INTEGER DEFAULT 0,
	tms                      TIMESTAMP
) ENGINE=innodb;
