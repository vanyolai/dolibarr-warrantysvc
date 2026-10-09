-- Preserved PDF revision metadata. Sent revisions are immutable; unsent revisions may be explicitly deleted.
CREATE TABLE llx_svc_warranty_letter_version (
 rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL DEFAULT 1,
 fk_letter INTEGER NOT NULL,
 version INTEGER NOT NULL,
 snapshot TEXT NOT NULL,
 file_path VARCHAR(255) NOT NULL,
 sha256 VARCHAR(64) NOT NULL,
 date_creation DATETIME NOT NULL,
 fk_user_creat INTEGER
) ENGINE=innodb;
