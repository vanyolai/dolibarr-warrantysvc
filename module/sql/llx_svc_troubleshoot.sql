-- Copyright (C) 2026 DPG Supply
CREATE TABLE llx_svc_troubleshoot(
	rowid                  INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity                 INTEGER       NOT NULL DEFAULT 1,
	fk_svcrequest          INTEGER       NOT NULL,
	datec                  DATETIME      NOT NULL,
	fk_user_author         INTEGER,
	checklist              TEXT,
	summary                TEXT,
	outcome                VARCHAR(32),
	tms                    TIMESTAMP,
	import_key             VARCHAR(14)
) ENGINE=innodb;
