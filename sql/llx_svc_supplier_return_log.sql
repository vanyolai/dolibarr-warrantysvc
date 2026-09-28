-- Copyright (C) 2026 DPG Supply
CREATE TABLE llx_svc_supplier_return_log(
	rowid                    INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity                   INTEGER NOT NULL DEFAULT 1,
	fk_supplier_return       INTEGER NOT NULL,
	event_code               VARCHAR(32) NOT NULL,
	old_status               VARCHAR(32),
	new_status               VARCHAR(32),
	note                     TEXT,
	date_event               DATETIME NOT NULL,
	fk_user                  INTEGER
) ENGINE=innodb;
