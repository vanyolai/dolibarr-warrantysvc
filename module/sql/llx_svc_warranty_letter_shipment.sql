-- Shipments attached to a warranty letter. A shipment belongs to at most one warranty letter.
CREATE TABLE llx_svc_warranty_letter_shipment (
 rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
 entity INTEGER NOT NULL DEFAULT 1,
 fk_letter INTEGER NOT NULL,
 fk_expedition INTEGER NOT NULL,
 date_creation DATETIME NOT NULL,
 fk_user_creat INTEGER
) ENGINE=innodb;
