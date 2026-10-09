-- Warranty letter header: customer-scoped document that may contain one or more shipments.
CREATE TABLE llx_svc_warranty_letter (
 rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
 ref VARCHAR(50) NOT NULL,
 entity INTEGER NOT NULL DEFAULT 1,
 fk_soc INTEGER NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'draft',
 current_version INTEGER NOT NULL DEFAULT 0,
 last_sent_version INTEGER NOT NULL DEFAULT 0,
 model_pdf VARCHAR(255),
 last_main_doc VARCHAR(255),
 date_creation DATETIME NOT NULL,
 fk_user_creat INTEGER,
 tms TIMESTAMP
) ENGINE=innodb;
