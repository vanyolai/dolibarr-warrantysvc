<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    lib/warrantysvc.lib.php
 * \ingroup warrantysvc
 * \brief   Tab helpers and shared utility functions
 */


/**
 * Return array of tabs for a SvcRequest card
 *
 * @param  SvcRequest $object SvcRequest object
 * @return array              Tab array for dol_get_fiche_head()
 */
function warrantysvc_prepare_head($object)
{
	global $langs, $conf, $user;

	$langs->loadLangs(array('warrantysvc@warrantysvc'));

	$h   = 0;
	$head = array();

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$object->id;
	$head[$h][1] = $langs->trans('SvcDetails');
	$head[$h][2] = 'card';
	$h++;

	// Contacts / addresses tab. Use Dolibarr's native element_contact model so
	// Service Request participants behave like contacts on orders/proposals.
	$internalContacts = $object->liste_contact(-1, 'internal');
	$externalContacts = $object->liste_contact(-1, 'external');
	$nbContacts = (is_array($internalContacts) ? count($internalContacts) : 0)
		+ (is_array($externalContacts) ? count($externalContacts) : 0);
	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/contact.php?id='.$object->id;
	$head[$h][1] = $langs->trans('ContactsAddresses');
	if ($nbContacts > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">'.$nbContacts.'</span>';
	}
	$head[$h][2] = 'contact';
	$h++;

	// Notes tab
	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/note.php?id='.$object->id;
	$head[$h][1] = $langs->trans('SvcNotes');
	// Append count badge if notes exist
	if (!empty($object->note_private) || !empty($object->note_public)) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">!</span>';
	}
	$head[$h][2] = 'note';
	$h++;

	// Troubleshoot tab
	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/troubleshoot.php?id='.$object->id;
	$head[$h][1] = $langs->trans('Troubleshoot');
	require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svctroubleshoot.class.php';
	$nbts = SvcTroubleshoot::countForRequest($object->db, $object->id);
	if ($nbts > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">'.$nbts.'</span>';
	}
	$head[$h][2] = 'troubleshoot';
	$h++;

	// Documents tab
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';

	$upload_dir = !empty($conf->warrantysvc->multidir_output[$object->entity])
		? $conf->warrantysvc->multidir_output[$object->entity]
		: (isset($conf->warrantysvc->dir_output) ? $conf->warrantysvc->dir_output : '');

	$nbFiles = 0;
	if ($upload_dir) {
		$filearray = dol_dir_list($upload_dir.'/'.$object->ref, 'files', 0, '', '(\.meta|_preview.*\.png)$');
		$nbFiles   = count($filearray);
	}

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/document.php?id='.$object->id;
	$head[$h][1] = $langs->trans('SvcDocuments');
	if ($nbFiles > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">'.$nbFiles.'</span>';
	}
	$head[$h][2] = 'document';
	$h++;

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'svcrequest@warrantysvc');

	return $head;
}


/**
 * Return default recipient keys for a customer-facing Service Request email.
 *
 * Priority:
 * 1. active external contacts linked with CUSTOMER_SERVICE and belonging to
 *    the Service Request customer, provided they have an email address;
 * 2. legacy fk_contact when it belongs to the same customer and has email;
 * 3. the customer's default email address ('thirdparty' key);
 * 4. nothing.
 *
 * The returned values are FormMail receiver keys, so they can be passed as
 * receiver[] parameters to Dolibarr's native presend form.
 *
 * @param  SvcRequest $object Service Request
 * @return array<int,string|int> FormMail receiver keys
 */
function warrantysvc_default_customer_email_receivers($object)
{
	$receivers = array();
	$contacts = $object->liste_contact(-1, 'external', 0, 'CUSTOMER_SERVICE', 1);
	if (is_array($contacts)) {
		foreach ($contacts as $contact) {
			if ((int) $contact['socid'] === (int) $object->fk_soc && !empty($contact['email'])) {
				$receivers[(int) $contact['id']] = (int) $contact['id'];
			}
		}
	}
	if (!empty($receivers)) {
		return array_values($receivers);
	}

	// Backward compatibility for Service Requests created before the native
	// Contacts/Addresses tab existed.
	if (!empty($object->fk_contact)) {
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$contact = new Contact($object->db);
		if ($contact->fetch((int) $object->fk_contact) > 0
			&& (int) $contact->socid === (int) $object->fk_soc
			&& !empty($contact->email)
		) {
			return array((int) $contact->id);
		}
	}

	if (!is_object($object->thirdparty)) {
		$object->fetch_thirdparty();
	}
	if (is_object($object->thirdparty) && !empty($object->thirdparty->email)) {
		return array('thirdparty');
	}

	return array();
}


/**
 * Return array of tabs for a Supplier RMA card.
 *
 * @param  SvcSupplierRma $object Supplier RMA object
 * @return array
 */
function svcsupplierrma_prepare_head($object)
{
	global $langs, $conf;

	$langs->loadLangs(array('warrantysvc@warrantysvc'));
	$head = array();
	$h = 0;

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/supplier_rma_card.php?id='.$object->id;
	$head[$h][1] = $langs->trans('SupplierRmaDetails');
	$head[$h][2] = 'card';
	$h++;

	$internalContacts = $object->liste_contact(-1, 'internal');
	$externalContacts = $object->liste_contact(-1, 'external');
	$nbContacts = (is_array($internalContacts) ? count($internalContacts) : 0)
		+ (is_array($externalContacts) ? count($externalContacts) : 0);
	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/supplier_rma_contact.php?id='.$object->id;
	$head[$h][1] = $langs->trans('ContactsAddresses');
	if ($nbContacts > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">'.$nbContacts.'</span>';
	}
	$head[$h][2] = 'contact';
	$h++;

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'svcsupplierrma@warrantysvc');
	return $head;
}


/**
 * Return native FormMail receiver keys for a supplier-facing RMA email.
 *
 * Explicit Supplier Service contacts on the RMA win. If none of those have
 * an email address, fall back to the supplier's default company email.
 *
 * @param  SvcSupplierRma $object Supplier RMA
 * @return array<int,string|int>
 */
function warrantysvc_default_supplier_email_receivers($object)
{
	$receivers = array();
	$contacts = $object->liste_contact(-1, 'external', 0, 'SUPPLIER_SERVICE', 1);
	if (is_array($contacts)) {
		foreach ($contacts as $contact) {
			if ((int) $contact['socid'] === (int) $object->fk_soc_supplier && !empty($contact['email'])) {
				$receivers[(int) $contact['id']] = (int) $contact['id'];
			}
		}
	}
	if (!empty($receivers)) {
		return array_values($receivers);
	}

	if (!is_object($object->thirdparty)) {
		$object->socid = (int) $object->fk_soc_supplier;
		$object->fetch_thirdparty();
	}
	if (is_object($object->thirdparty) && !empty($object->thirdparty->email)) {
		return array('thirdparty');
	}

	return array();
}


/**
 * Check whether a Product is eligible for a new Service Request under the
 * optional "serialized/LOT products only" policy.
 *
 * When the setting is disabled all products remain eligible. When enabled,
 * Dolibarr's native Product::tobatch flag is authoritative.
 *
 * @param  DoliDB $db        Database handler
 * @param  int    $productId Product id
 * @return bool
 */
function warrantysvc_service_request_product_allowed($db, $productId)
{
	require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
	return SvcRequest::isProductAllowedByPolicy($db, (int) $productId);
}


/**
 * Return tabs for a Supplier Return card.
 *
 * @param SvcSupplierReturn $object Supplier Return
 * @return array
 */
function svcsupplierreturn_prepare_head($object)
{
	global $langs, $conf;

	$langs->load('warrantysvc@warrantysvc');
	$head = array();
	$h = 0;

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_card.php?id='.$object->id;
	$head[$h][1] = $langs->trans('SupplierReturnDetails');
	$head[$h][2] = 'card';
	$h++;

	$internalContacts = $object->liste_contact(-1, 'internal');
	$externalContacts = $object->liste_contact(-1, 'external');
	$nbContacts = (is_array($internalContacts) ? count($internalContacts) : 0)
		+ (is_array($externalContacts) ? count($externalContacts) : 0);
	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_contact.php?id='.$object->id;
	$head[$h][1] = $langs->trans('ContactsAddresses');
	if ($nbContacts > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">'.$nbContacts.'</span>';
	}
	$head[$h][2] = 'contact';
	$h++;

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_document.php?id='.$object->id;
	$head[$h][1] = $langs->trans('Documents');
	$head[$h][2] = 'document';
	$h++;

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'svcsupplierreturn@warrantysvc');
	return $head;
}


/**
 * Default recipient for a supplier-return email.
 *
 * @param SvcSupplierReturn $object Supplier Return
 * @return array<int,string|int>
 */
function warrantysvc_default_supplier_return_email_receivers($object)
{
	$receivers = array();
	$contacts = $object->liste_contact(-1, 'external', 0, 'SUPPLIER_RETURN', 1);
	if (is_array($contacts)) {
		foreach ($contacts as $contact) {
			if ((int) $contact['socid'] === (int) $object->fk_soc_supplier && !empty($contact['email'])) {
				$receivers[(int) $contact['id']] = (int) $contact['id'];
			}
		}
	}
	if (!empty($receivers)) {
		return array_values($receivers);
	}

	$object->socid = (int) $object->fk_soc_supplier;
	if (!is_object($object->thirdparty)) {
		$object->fetch_thirdparty();
	}
	if (is_object($object->thirdparty) && !empty($object->thirdparty->email)) {
		return array('thirdparty');
	}

	return array();
}


/**
 * Return Supplier Return output directory.
 *
 * @param SvcSupplierReturn $object Supplier Return
 * @return string
 */
function warrantysvc_supplier_return_output_root($object)
{
	global $conf;

	$base = !empty($conf->warrantysvc->multidir_output[$object->entity])
		? $conf->warrantysvc->multidir_output[$object->entity]
		: (!empty($conf->warrantysvc->dir_output) ? $conf->warrantysvc->dir_output : DOL_DATA_ROOT.'/warrantysvc');

	return $base.'/supplier-return';
}

/**
 * Return Supplier Return object output directory.
 *
 * @param SvcSupplierReturn $object Supplier Return
 * @return string
 */
function warrantysvc_supplier_return_output_dir($object)
{
	return warrantysvc_supplier_return_output_root($object).'/'.dol_sanitizeFileName($object->ref);
}


/**
 * Return array of tabs for a SvcWarranty card
 *
 * @param  SvcWarranty $object SvcWarranty object
 * @return array               Tab array for dol_get_fiche_head()
 */
function svcwarranty_prepare_head($object)
{
	global $langs, $conf;

	$langs->loadLangs(array('warrantysvc@warrantysvc'));

	$h    = 0;
	$head = array();

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/warranty_card.php?id='.$object->id;
	$head[$h][1] = $langs->trans('SvcDetails');
	$head[$h][2] = 'card';
	$h++;

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/warranty_note.php?id='.$object->id;
	$head[$h][1] = $langs->trans('SvcNotes');
	$head[$h][2] = 'note';
	$h++;

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'svcwarranty@warrantysvc');

	return $head;
}


/**
 * Return HTML badge for a SvcRequest status
 *
 * @param  int    $status  Status code
 * @param  int    $mode    0=badge with label, 1=label only
 * @return string          HTML
 */
function svcrequest_status_badge($status, $mode = 0)
{
	global $langs;
	$langs->loadLangs(array('warrantysvc@warrantysvc'));

	$map = array(
		0 => array('label' => 'SvcDraft',         'color' => 'status0'),
		1 => array('label' => 'SvcValidated',      'color' => 'status1'),
		6 => array('label' => 'SvcDiagnosing',     'color' => 'status2'),
		2 => array('label' => 'SvcInProgress',     'color' => 'status3'),
		3 => array('label' => 'AwaitingReturn', 'color' => 'status4'),
		4 => array('label' => 'SvcResolved',       'color' => 'status6'),
		5 => array('label' => 'SvcClosed',         'color' => 'status6'),
		9 => array('label' => 'SvcCancelled',      'color' => 'status9'),
	);

	$s     = isset($map[$status]) ? $map[$status] : array('label' => 'Unknown', 'color' => 'status0');
	$label = $langs->trans($s['label']);

	if ($mode == 1) {
		return $label;
	}

	return '<span class="badge '.$s['color'].'">'.$label.'</span>';
}


/**
 * Return HTML badge for a warranty status
 *
 * @param  string $status  Status string: active|expired|voided|none
 * @param  int    $mode    0=badge, 1=label only
 * @return string          HTML
 */
function svcwarranty_status_badge($status, $mode = 0)
{
	global $langs;
	$langs->loadLangs(array('warrantysvc@warrantysvc'));

	$map = array(
		'active'  => array('label' => 'SvcActive',  'badge' => 'badge-status4'),
		'expired' => array('label' => 'SvcExpired', 'badge' => 'badge-status8'),
		'voided'  => array('label' => 'SvcVoided',  'badge' => 'badge-status9'),
		'none'    => array('label' => 'NoCoverage',  'badge' => 'badge-status0'),
	);

	$s = isset($map[$status]) ? $map[$status] : array('label' => 'NoCoverage', 'badge' => 'badge-status0');
	$label = $langs->trans($s['label']);

	if ($mode == 1) {
		return $label;
	}

	return '<span class="badge badge-status '.$s['badge'].'">'.$label.'</span>';
}


/**
 * Return translated label for a resolution type
 *
 * @param  string $type Resolution type constant string
 * @return string       Translated label
 */
function svcrequest_resolution_label($type)
{
	global $langs;
	$langs->loadLangs(array('warrantysvc@warrantysvc'));

	$map = array(
		'component'        => 'ResolutionComponent',
		'component_return' => 'ResolutionComponentReturn',
		'swap_cross'       => 'ResolutionSwapCross',
		'swap_wait'        => 'ResolutionSwapWait',
		'intervention'     => 'ResolutionIntervention',
		'guidance'         => 'ResolutionGuidance',
		'informational'    => 'ResolutionInformational',
	);

	return isset($map[$type]) ? $langs->trans($map[$type]) : $type;
}


/**
 * Return list of resolution types as array for select boxes
 *
 * @return array key=>label
 */
function svcrequest_resolution_types()
{
	global $langs;
	$langs->loadLangs(array('warrantysvc@warrantysvc'));

	return array(
		''               => $langs->trans('ResolutionTypeSelect'),
		'component'        => $langs->trans('ResolutionComponent'),
		'component_return' => $langs->trans('ResolutionComponentReturn'),
		'swap_cross'       => $langs->trans('ResolutionSwapCross'),
		'swap_wait'        => $langs->trans('ResolutionSwapWait'),
		'intervention'     => $langs->trans('ResolutionIntervention'),
		'guidance'         => $langs->trans('ResolutionGuidance'),
		'informational'    => $langs->trans('ResolutionInformational'),
	);
}


/**
 * Return array of tabs for the admin setup pages
 *
 * @return array Tab array for dol_get_fiche_head()
 */
function warrantysvc_admin_prepare_head()
{
	global $langs, $conf;

	$head = array();
	$h = 0;

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/admin/setup.php';
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/admin/svcrequest_extrafields.php';
	$head[$h][1] = $langs->trans('ServiceRequestExtraFields');
	$head[$h][2] = 'svcrequest_extrafields';
	$h++;

	$head[$h][0] = DOL_URL_ROOT.'/custom/warrantysvc/admin/svcwarranty_extrafields.php';
	$head[$h][1] = $langs->trans('WarrantyExtraFields');
	$head[$h][2] = 'svcwarranty_extrafields';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'warrantysvc_admin');

	return $head;
}


/**
 * Return the active warranty-duration policy.
 *
 * product_field: duration comes exclusively from the configured Product
 * integer extrafield and is interpreted as calendar months.
 *
 * warranty_type: retain the upstream Warranty Type/day-based behaviour.
 *
 * For installations upgraded from an earlier fork revision, infer Product
 * field mode when a Product month field is already configured.
 *
 * @return string product_field|warranty_type
 */
function warrantysvc_get_duration_source()
{
	$source = trim(getDolGlobalString('WARRANTYSVC_DURATION_SOURCE'));
	if (in_array($source, array('product_field', 'warranty_type'), true)) {
		return $source;
	}

	return trim(getDolGlobalString('WARRANTYSVC_PRODUCT_WARRANTY_MONTHS_FIELD')) !== ''
		? 'product_field'
		: 'warranty_type';
}


/**
 * Whether Product calendar months are the authoritative duration source.
 *
 * @return bool
 */
function warrantysvc_uses_product_months()
{
	return warrantysvc_get_duration_source() === 'product_field';
}


/**
 * Return Product integer extrafields that can act as the customer warranty
 * duration source (value expressed in calendar months).
 *
 * @param DoliDB $db Database handler
 * @param int $entity Current entity
 * @return array<string,string> field name => display label
 */
function warrantysvc_get_product_month_field_options($db, $entity)
{
	$options = array();
	$sql  = "SELECT name, label FROM ".MAIN_DB_PREFIX."extrafields";
	$sql .= " WHERE elementtype = 'product'";
	$sql .= " AND type = 'int'";
	$sql .= " AND entity IN (0, ".((int) $entity).")";
	$sql .= " ORDER BY entity ASC, pos ASC, label ASC";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$options[(string) $obj->name] = (string) $obj->label.' ['.(string) $obj->name.']';
		}
	}

	return $options;
}


/**
 * Validate and return the configured Product extrafield used as warranty months.
 *
 * An empty configuration is valid and means the module uses its legacy
 * day-based coverage fallback.
 *
 * @param DoliDB $db Database handler
 * @param int $entity Current entity
 * @param string $error Output error message
 * @return string Empty when not configured, otherwise the validated field name
 */
function warrantysvc_get_product_month_field($db, $entity, &$error = '')
{
	global $langs;
	$langs->loadLangs(array('warrantysvc@warrantysvc'));
	$error = '';
	$field = trim(getDolGlobalString('WARRANTYSVC_PRODUCT_WARRANTY_MONTHS_FIELD'));
	if ($field === '') {
		return '';
	}
	if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field)) {
		$error = $langs->trans('ErrorInvalidProductWarrantyMonthsFieldName', $field);
		return '';
	}

	$sql  = "SELECT rowid FROM ".MAIN_DB_PREFIX."extrafields";
	$sql .= " WHERE elementtype = 'product'";
	$sql .= " AND name = '".$db->escape($field)."'";
	$sql .= " AND type = 'int'";
	$sql .= " AND entity IN (0, ".((int) $entity).")";
	$sql .= " ORDER BY entity DESC";

	$resql = $db->query($sql);
	if (!$resql) {
		$error = $db->lasterror();
		return '';
	}
	if (!$db->fetch_object($resql)) {
		$error = $langs->trans('ErrorProductWarrantyMonthsFieldMissingOrInvalid', $field);
		return '';
	}

	return $field;
}


/**
 * Read the configured warranty duration in calendar months for a Product.
 *
 * A blank/zero Product value returns null. In Product-field mode callers must
 * treat that as no configured customer warranty; there is no Warranty Type
 * fallback. Product variants inherit the configured field value from
 * their parent when the child has no positive value.
 *
 * @param DoliDB $db Database handler
 * @param int $productId Product id
 * @param int $entity Current entity
 * @param string $error Output error message
 * @return int|null Positive month count or null when no value is configured
 */
function warrantysvc_get_product_warranty_months($db, $productId, $entity, &$error = '')
{
	$error = '';
	$productId = (int) $productId;
	if ($productId <= 0) {
		return null;
	}

	$field = warrantysvc_get_product_month_field($db, $entity, $error);
	if ($error !== '' || $field === '') {
		return null;
	}

	$readMonths = function ($id) use ($db, $field, &$error) {
		$sql  = "SELECT pe.".$field." AS warranty_months";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_extrafields pe";
		$sql .= " WHERE pe.fk_object = ".((int) $id);
		$resql = $db->query($sql);
		if (!$resql) {
			$error = $db->lasterror();
			return null;
		}
		$obj = $db->fetch_object($resql);
		if (!$obj || !is_numeric($obj->warranty_months)) {
			return null;
		}
		$months = (int) $obj->warranty_months;
		return $months > 0 ? $months : null;
	};

	$months = $readMonths($productId);
	if ($error !== '' || $months !== null) {
		return $months;
	}

	if (function_exists('isModEnabled') && isModEnabled('variants')) {
		$sql  = "SELECT fk_product_parent FROM ".MAIN_DB_PREFIX."product_attribute_combination";
		$sql .= " WHERE fk_product_child = ".$productId;
		$sql .= " AND entity IN (".getEntity('product').")";
		$resql = $db->query($sql);
		if (!$resql) {
			$error = $db->lasterror();
			return null;
		}
		if ($parent = $db->fetch_object($resql)) {
			return $readMonths((int) $parent->fk_product_parent);
		}
	}

	return null;
}


/**
 * Resolve and validate one physical item selected from a shipment.
 *
 * Item keys are internal UI tokens:
 * - b:<expeditiondet_batch rowid> for a serial/lot allocation
 * - l:<expeditiondet rowid> for an ordinary non-serialized shipment line
 *
 * The database is authoritative; Product, customer, serial and quantity are
 * never trusted from hidden form inputs.
 *
 * @param DoliDB $db Database handler
 * @param int $shipmentId Shipment id
 * @param string $itemKey Internal item token
 * @param string $error Output error
 * @return array<string,mixed>|null
 */
function warrantysvc_resolve_shipment_item($db, $shipmentId, $itemKey, &$error = '')
{
	global $langs;
	$langs->loadLangs(array('warrantysvc@warrantysvc'));
	$error = '';
	$shipmentId = (int) $shipmentId;
	$itemKey = trim((string) $itemKey);
	if ($shipmentId <= 0 || !preg_match('/^([bl]):(\\d+)$/', $itemKey, $matches)) {
		$error = $langs->trans('ErrorInvalidShipmentItem');
		return null;
	}

	$type = $matches[1];
	$rowId = (int) $matches[2];

	if ($type === 'l' && getDolGlobalInt('WARRANTYSVC_WARRANTY_REQUIRES_LOTS')) {
		$error = $langs->trans('ErrorWarrantyRequiresLotProduct');
		return null;
	}

	if ($type === 'b') {
		$sql  = "SELECT e.fk_soc, e.date_expedition, e.date_delivery, ed.rowid AS fk_expeditiondet, ed.fk_product,";
		$sql .= " edl.batch AS serial_number, edl.qty AS covered_qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet_batch edl";
		$sql .= " JOIN ".MAIN_DB_PREFIX."expeditiondet ed ON ed.rowid = edl.fk_expeditiondet";
		$sql .= " JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition";
		$sql .= " WHERE edl.rowid = ".$rowId;
		$sql .= " AND ed.fk_expedition = ".$shipmentId;
		$sql .= " AND edl.batch IS NOT NULL AND edl.batch != ''";
	} else {
		$sql  = "SELECT e.fk_soc, e.date_expedition, e.date_delivery, ed.rowid AS fk_expeditiondet, ed.fk_product,";
		$sql .= " NULL AS serial_number, ed.qty AS covered_qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet ed";
		$sql .= " JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition";
		$sql .= " WHERE ed.rowid = ".$rowId;
		$sql .= " AND ed.fk_expedition = ".$shipmentId;
		$sql .= " AND NOT EXISTS (";
		$sql .= "SELECT 1 FROM ".MAIN_DB_PREFIX."expeditiondet_batch edl";
		$sql .= " WHERE edl.fk_expeditiondet = ed.rowid";
		$sql .= " AND edl.batch IS NOT NULL AND edl.batch != ''";
		$sql .= ")";
	}

	$resql = $db->query($sql);
	if (!$resql) {
		$error = $db->lasterror();
		return null;
	}

	$obj = $db->fetch_object($resql);
	if (!$obj) {
		$error = $langs->trans('ErrorShipmentItemNotInShipment');
		return null;
	}

	$coveredQty = (float) $obj->covered_qty;
	if ($coveredQty <= 0) {
		$error = $langs->trans('ErrorShipmentItemQuantityInvalid');
		return null;
	}

	$startDate = warrantysvc_normalize_date($obj->date_expedition);
	if ($startDate === null) {
		$startDate = warrantysvc_normalize_date($obj->date_delivery);
	}
	if ($startDate === null) {
		$error = $langs->trans('ErrorShipmentWarrantyStartDateMissing');
		return null;
	}

	return array(
		'fk_soc' => (int) $obj->fk_soc,
		'fk_product' => (int) $obj->fk_product,
		'fk_expeditiondet' => (int) $obj->fk_expeditiondet,
		'serial_number' => !empty($obj->serial_number) ? (string) $obj->serial_number : '',
		'covered_qty' => $coveredQty,
		'start_date' => (int) $startDate,
	);
}


/**
 * Compute the Product-field warranty period for one concrete warranty.
 *
 * @param DoliDB $db Database handler
 * @param int $productId Product id
 * @param int $entity Current entity
 * @param int $startDate Warranty start timestamp
 * @param string $error Output error message
 * @return array<string,int>|null Keys: months, expiry, days; null if unavailable
 */
function warrantysvc_compute_product_warranty_period($db, $productId, $entity, $startDate, &$error = '')
{
	global $langs;
	$langs->loadLangs(array('warrantysvc@warrantysvc'));
	$error = '';
	if (!warrantysvc_uses_product_months()) {
		return null;
	}

	if ((int) $startDate <= 0) {
		$error = $langs->trans('ErrorWarrantyStartDateMissing');
		return null;
	}

	$months = warrantysvc_get_product_warranty_months($db, (int) $productId, (int) $entity, $error);
	if ($error !== '') {
		return null;
	}
	if ($months === null || $months <= 0) {
		return null;
	}

	$expiry = warrantysvc_add_months_clamped((int) $startDate, (int) $months);
	if ($expiry === null) {
		$error = $langs->trans('ErrorWarrantyCalendarExpiry');
		return null;
	}

	$days = warrantysvc_calendar_days_between((int) $startDate, (int) $expiry);
	if ($days === null) {
		$error = $langs->trans('ErrorWarrantyCoverageDaysCalculation');
		return null;
	}

	return array(
		'months' => (int) $months,
		'expiry' => (int) $expiry,
		'days' => (int) $days,
	);
}


/**
 * Normalize a Dolibarr date/timestamp/string into a timestamp at noon.
 *
 * @param mixed $value Date-like value
 * @return int|null
 */
function warrantysvc_normalize_date($value)
{
	if (empty($value)) {
		return null;
	}

	if (is_numeric($value)) {
		$timestamp = (int) $value;
		if ($timestamp <= 0) {
			return null;
		}
		$date = dol_print_date($timestamp, '%Y-%m-%d', 'tzserver');
	} else {
		$value = trim((string) $value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
			$date = substr($value, 0, 10);
		} else {
			$timestamp = strtotime($value);
			if ($timestamp === false) {
				return null;
			}
			$date = dol_print_date($timestamp, '%Y-%m-%d', 'tzserver');
		}
	}

	$parts = explode('-', $date);
	if (count($parts) !== 3) {
		return null;
	}

	return dol_mktime(12, 0, 0, (int) $parts[1], (int) $parts[2], (int) $parts[0]);
}


/**
 * Resolve the contractual warranty start from a shipment.
 *
 * Actual shipment date wins. Planned delivery is used only when the actual
 * shipment date is not yet available.
 *
 * @param DoliDB $db Database handler
 * @param object $shipment Expedition-like object
 * @return int|null Timestamp at noon
 */
function warrantysvc_resolve_shipment_start_date($db, $shipment)
{
	foreach (array('date_shipping', 'date_expedition', 'date_delivery') as $property) {
		if (isset($shipment->{$property}) && !empty($shipment->{$property})) {
			$value = warrantysvc_normalize_date($shipment->{$property});
			if ($value !== null) {
				return $value;
			}
		}
	}

	if (!empty($shipment->id)) {
		$sql = "SELECT date_expedition, date_delivery FROM ".MAIN_DB_PREFIX."expedition WHERE rowid = ".((int) $shipment->id);
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$value = warrantysvc_normalize_date($obj->date_expedition);
			if ($value !== null) {
				return $value;
			}
			return warrantysvc_normalize_date($obj->date_delivery);
		}
	}

	return null;
}


/**
 * Add calendar months to a date, clamping to the last valid target-month day.
 *
 * Examples: 2024-01-31 + 1 month = 2024-02-29,
 *           2025-01-31 + 1 month = 2025-02-28.
 *
 * @param int $timestamp Start timestamp
 * @param int $months Positive number of calendar months
 * @return int|null Expiry timestamp at noon
 */
function warrantysvc_add_months_clamped($timestamp, $months)
{
	$months = (int) $months;
	if ($timestamp <= 0 || $months <= 0) {
		return null;
	}

	$date = dol_print_date((int) $timestamp, '%Y-%m-%d', 'tzserver');
	$start = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
	$errors = DateTimeImmutable::getLastErrors();
	if ($start === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
		return null;
	}

	$day = (int) $start->format('d');
	$targetMonth = $start->modify('first day of this month')->modify('+'.$months.' months');
	$targetDay = min($day, (int) $targetMonth->format('t'));
	$target = $targetMonth->setDate(
		(int) $targetMonth->format('Y'),
		(int) $targetMonth->format('m'),
		$targetDay
	);

	return dol_mktime(
		12,
		0,
		0,
		(int) $target->format('m'),
		(int) $target->format('d'),
		(int) $target->format('Y')
	);
}


/**
 * Return the number of whole calendar days between two date timestamps.
 *
 * @param int $start Start timestamp
 * @param int $end End timestamp
 * @return int|null
 */
function warrantysvc_calendar_days_between($start, $end)
{
	if ($start <= 0 || $end <= 0) {
		return null;
	}
	$a = new DateTimeImmutable(dol_print_date((int) $start, '%Y-%m-%d', 'tzserver'));
	$b = new DateTimeImmutable(dol_print_date((int) $end, '%Y-%m-%d', 'tzserver'));
	return (int) $a->diff($b)->days;
}