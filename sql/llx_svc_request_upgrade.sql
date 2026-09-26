-- v1.32.0: add security seal number field to service requests
ALTER TABLE llx_svc_request ADD COLUMN seal_number VARCHAR(128) AFTER serial_out;

-- v1.32.2: migrate stale 'expedition' element type to 'shipping' in element_element.
-- Old trigger code called add_object_linked('expedition',...) but Expedition::$element = 'shipping',
-- so those rows were invisible to Dolibarr's Related Objects renderer. Fix in-place.
UPDATE llx_element_element
SET sourcetype = 'shipping'
WHERE sourcetype = 'expedition'
  AND targettype IN ('warrantysvc_svcwarranty', 'svcwarranty');

UPDATE llx_element_element
SET targettype = 'shipping'
WHERE targettype = 'expedition'
  AND sourcetype IN ('warrantysvc_svcwarranty', 'svcwarranty');

-- v1.32.3: normalise bare element-type keys stored by old showLinkToObjectBlock hook.
-- The hook used 'svcwarranty'/'svcrequest' as array keys, which became the sourcetype
-- in element_element. Dolibarr's showLinkedObjectBlock derives the template path from
-- objecttype; 'warrantysvc_svcwarranty' splits correctly to tplpath=warrantysvc/svcwarranty,
-- while bare 'svcwarranty' does not. Migrate all existing rows so old links display.
UPDATE llx_element_element SET sourcetype = 'warrantysvc_svcwarranty' WHERE sourcetype = 'svcwarranty';
UPDATE llx_element_element SET targettype = 'warrantysvc_svcwarranty' WHERE targettype = 'svcwarranty';
UPDATE llx_element_element SET sourcetype = 'warrantysvc_svcrequest'  WHERE sourcetype = 'svcrequest';
UPDATE llx_element_element SET targettype = 'warrantysvc_svcrequest'  WHERE targettype = 'svcrequest';
