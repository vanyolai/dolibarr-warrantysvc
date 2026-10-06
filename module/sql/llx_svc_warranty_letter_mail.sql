-- Mail audit row is written after successful native Dolibarr email delivery.
CREATE TABLE llx_svc_warranty_letter_mail (
 rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL DEFAULT 1,
 fk_letter INTEGER NOT NULL,
 fk_version INTEGER NOT NULL,
 date_sent DATETIME NOT NULL,
 fk_user INTEGER,
 recipient TEXT,
 subject VARCHAR(255),
 message_id VARCHAR(255)
) ENGINE=innodb;
