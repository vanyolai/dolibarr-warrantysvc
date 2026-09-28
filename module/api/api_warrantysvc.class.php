<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    api/api_warrantysvc.class.php
 * \ingroup warrantysvc
 * \brief   REST API endpoints for Warranty & Service module
 *
 * Exposes SvcRequest and SvcWarranty resources via Dolibarr's Luracast
 * REST API infrastructure. Accessible at:
 *   GET  /api/index.php/warrantysvc/requests
 *   GET  /api/index.php/warrantysvc/requests/{id}
 *   POST /api/index.php/warrantysvc/requests
 *   PUT  /api/index.php/warrantysvc/requests/{id}
 *   POST /api/index.php/warrantysvc/requests/{id}/createfromcall
 *   POST /api/index.php/warrantysvc/requests/{id}/createfromintervention
 *   GET  /api/index.php/warrantysvc/warranties
 *   GET  /api/index.php/warrantysvc/warranties/{id}
 *   GET  /api/index.php/warrantysvc/warranties/byserial/{serial}
 */

require_once DOL_DOCUMENT_ROOT.'/api/class/dolgenerics.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';


/**
 * API for Warranty & Service module
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class WarrantySvc extends DolibarrApi
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * WarrantySvc REST is an internal staff API.
	 *
	 * @return void
	 * @throws RestException
	 */
	protected function assertInternalUser()
	{
		if (!empty(DolibarrApiAccess::$user->socid)) {
			throw new RestException(403, 'WarrantySvc API is restricted to internal users');
		}
	}

	/**
	 * Apply only explicitly supported request fields.
	 *
	 * @param object $object  Target object
	 * @param array  $payload Request payload
	 * @param array  $allowed Allowed field names
	 * @return void
	 */
	protected function applyAllowedFields($object, $payload, $allowed)
	{
		if (!is_array($payload)) {
			return;
		}
		$allowedMap = array_fill_keys($allowed, true);
		foreach ($payload as $field => $value) {
			if (isset($allowedMap[$field])) {
				$object->{$field} = $value;
			}
		}
	}

	// ====================================================================
	// SERVICE REQUESTS
	// ====================================================================

	/**
	 * List service requests
	 *
	 * @param string $sortfield  Sort field (default: t.rowid)
	 * @param string $sortorder  Sort order (default: ASC)
	 * @param int    $limit      Page size (default: 100)
	 * @param int    $page       Page number (default: 0)
	 * @param string $sqlfilters Extra SQL WHERE clauses e.g. "(t.status:=:1)"
	 *
	 * @url GET /requests
	 * @return array Array of SvcRequest objects
	 * @throws RestException
	 */
	public function indexRequests($sortfield = 't.rowid', $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '')
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcrequest', 'read')) {
			throw new RestException(403);
		}

		$obj_ret = array();
		$offset  = $limit * $page;

		$sql  = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."svc_request as t";
		$sql .= " WHERE t.entity IN (".getEntity('svcrequest').")";

		if ($sqlfilters) {
			$regexstring = '\(([^:\'\(\)]+:[^:\'\(\)]+:[^:\'\(\)]+)\)';
			$sql .= " AND (".DolibarrApi::_checkFilters($sqlfilters, $regexstring).")";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		$sql .= $this->db->plimit($limit, $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(500, $this->db->lasterror());
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$request = new SvcRequest($this->db);
			if ($request->fetch($obj->rowid) > 0) {
				$obj_ret[] = $this->cleanObjectDatas($request);
			}
		}
		$this->db->free($resql);

		return $obj_ret;
	}

	/**
	 * Get a service request by ID
	 *
	 * @param int $id Service request ID
	 *
	 * @url GET /requests/{id}
	 * @return array SvcRequest object
	 * @throws RestException
	 */
	public function getRequest($id)
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcrequest', 'read')) {
			throw new RestException(403);
		}

		$request = new SvcRequest($this->db);
		$result  = $request->fetch((int) $id);

		if ($result == 0) {
			throw new RestException(404, 'Service request not found');
		}
		if ($result < 0) {
			throw new RestException(500, $request->error);
		}

		return $this->cleanObjectDatas($request);
	}

	/**
	 * Create a service request
	 *
	 * @param array $request Request body (SvcRequest fields)
	 *
	 * @url POST /requests
	 * @return int New service request ID
	 * @throws RestException
	 */
	public function postRequest($request)
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcrequest', 'write')) {
			throw new RestException(403);
		}

		$obj = new SvcRequest($this->db);
		$this->applyAllowedFields($obj, $request, array(
			'fk_soc', 'fk_product', 'serial_number', 'fk_contact', 'customer_site',
			'fk_project', 'fk_commande', 'fk_expedition_origin', 'fk_lot',
			'issue_description', 'issue_date', 'reported_via', 'fk_pbxcall',
			'fk_warranty', 'fk_user_assigned', 'note_private', 'note_public',
		));

		$result = $obj->create(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, $obj->error);
		}
		$obj->syncLinkedObjects();

		return $result;
	}

	/**
	 * Update a service request
	 *
	 * @param int   $id      Service request ID
	 * @param array $request Request body (SvcRequest fields to update)
	 *
	 * @url PUT /requests/{id}
	 * @return int 1 if OK
	 * @throws RestException
	 */
	public function putRequest($id, $request)
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcrequest', 'write')) {
			throw new RestException(403);
		}

		$obj    = new SvcRequest($this->db);
		$result = $obj->fetch((int) $id);
		if ($result == 0) {
			throw new RestException(404, 'Service request not found');
		}
		if ($result < 0) {
			throw new RestException(500, $obj->error);
		}

		$this->applyAllowedFields($obj, $request, array(
			'fk_soc', 'fk_product', 'serial_number', 'fk_contact', 'customer_site',
			'fk_project', 'fk_commande', 'fk_expedition_origin', 'fk_lot',
			'issue_description', 'issue_date', 'reported_via', 'fk_pbxcall',
			'resolution_type', 'resolution_notes', 'fk_warranty',
			'serial_in', 'serial_out', 'seal_number',
			'fk_warehouse_source', 'fk_warehouse_return',
			'outbound_carrier', 'outbound_tracking',
			'return_carrier', 'return_tracking', 'date_return_expected',
			'fk_intervention', 'fk_user_assigned', 'note_private', 'note_public',
		));

		$result = $obj->update(DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, $obj->error);
		}

		return 1;
	}

	/**
	 * Create a service request from a CRM phone call (actioncomm)
	 *
	 * Pre-fills the new service request from the actioncomm record fields
	 * (thirdparty, contact, description, date) and links them together.
	 *
	 * @param int   $id      Actioncomm ID of the call
	 * @param array $request Optional extra fields to override (serial_number, fk_product, etc.)
	 *
	 * @url POST /requests/createfromcall/{id}
	 * @return int New service request ID
	 * @throws RestException
	 */
	public function postRequestFromCall($id, $request = array())
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcrequest', 'write')) {
			throw new RestException(403);
		}

		$obj = new SvcRequest($this->db);

		// Only asset/intake fields may override values inherited from the call.
		$this->applyAllowedFields($obj, $request, array(
			'fk_product', 'serial_number', 'fk_warranty', 'customer_site',
			'fk_project', 'fk_user_assigned', 'note_private', 'note_public',
		));

		$result = $obj->createFromCall((int) $id, DolibarrApiAccess::$user);
		if ($result < 0) {
			throw new RestException(500, $obj->error);
		}

		return $result;
	}

	/**
	 * Create a service request from a core Intervention.
	 *
	 * Customer, project, date and description are inherited from the work sheet.
	 * Asset identity is supplied in the optional request body; at minimum
	 * fk_product is required unless fk_warranty identifies the covered asset.
	 *
	 * @param int   $id      Intervention ID
	 * @param array $request Optional intake fields / asset identity
	 *
	 * @url POST /requests/createfromintervention/{id}
	 * @return int New service request ID
	 * @throws RestException
	 */
	public function postRequestFromIntervention($id, $request = array())
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcrequest', 'write')) {
			throw new RestException(403);
		}

		if (!is_array($request)) {
			$request = array();
		}

		$allowed = array(
			'fk_product', 'serial_number', 'fk_warranty', 'fk_contact', 'customer_site',
			'fk_project', 'issue_description', 'issue_date', 'fk_user_assigned',
			'reported_via', 'note_private', 'note_public',
		);
		$filtered = array();
		foreach ($allowed as $field) {
			if (array_key_exists($field, $request)) {
				$filtered[$field] = $request[$field];
			}
		}
		$obj = new SvcRequest($this->db);
		$result = $obj->createFromIntervention((int) $id, DolibarrApiAccess::$user, $filtered);
		if ($result < 0) {
			$message = !empty($obj->error) ? $obj->error : 'Unable to create Service Request from Intervention';
			throw new RestException(500, $message);
		}

		return $result;
	}


	// ====================================================================
	// WARRANTIES
	// ====================================================================

	/**
	 * List warranties
	 *
	 * @param string $sortfield  Sort field (default: t.rowid)
	 * @param string $sortorder  Sort order (default: ASC)
	 * @param int    $limit      Page size (default: 100)
	 * @param int    $page       Page number (default: 0)
	 * @param string $sqlfilters Extra SQL WHERE clauses
	 *
	 * @url GET /warranties
	 * @return array Array of SvcWarranty objects
	 * @throws RestException
	 */
	public function indexWarranties($sortfield = 't.rowid', $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '')
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcwarranty', 'read')) {
			throw new RestException(403);
		}

		$obj_ret = array();
		$offset  = $limit * $page;

		$sql  = "SELECT t.rowid FROM ".MAIN_DB_PREFIX."svc_warranty as t";
		$sql .= " WHERE t.entity IN (".getEntity('svcwarranty').")";

		if ($sqlfilters) {
			$regexstring = '\(([^:\'\(\)]+:[^:\'\(\)]+:[^:\'\(\)]+)\)';
			$sql .= " AND (".DolibarrApi::_checkFilters($sqlfilters, $regexstring).")";
		}

		$sql .= $this->db->order($sortfield, $sortorder);
		$sql .= $this->db->plimit($limit, $offset);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(500, $this->db->lasterror());
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$warranty = new SvcWarranty($this->db);
			if ($warranty->fetch($obj->rowid) > 0) {
				$obj_ret[] = $this->cleanObjectDatas($warranty);
			}
		}
		$this->db->free($resql);

		return $obj_ret;
	}

	/**
	 * Get a warranty by ID
	 *
	 * @param int $id Warranty ID
	 *
	 * @url GET /warranties/{id}
	 * @return array SvcWarranty object
	 * @throws RestException
	 */
	public function getWarranty($id)
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcwarranty', 'read')) {
			throw new RestException(403);
		}

		$warranty = new SvcWarranty($this->db);
		$result   = $warranty->fetch((int) $id);

		if ($result == 0) {
			throw new RestException(404, 'Warranty not found');
		}
		if ($result < 0) {
			throw new RestException(500, $warranty->error);
		}

		return $this->cleanObjectDatas($warranty);
	}

	/**
	 * Get a warranty by serial number
	 *
	 * @param string $serial Serial number
	 *
	 * @url GET /warranties/byserial/{serial}
	 * @return array SvcWarranty object
	 * @throws RestException
	 */
	public function getWarrantyBySerial($serial)
	{
		$this->assertInternalUser();
		if (!DolibarrApiAccess::$user->hasRight('warrantysvc', 'svcwarranty', 'read')) {
			throw new RestException(403);
		}

		$warranty = new SvcWarranty($this->db);
		$result   = $warranty->fetchBySerial($serial);

		if ($result == 0) {
			throw new RestException(404, 'No warranty found for serial: '.$serial);
		}
		if ($result < 0) {
			throw new RestException(500, $warranty->error);
		}

		return $this->cleanObjectDatas($warranty);
	}

	// ====================================================================
	// Internal helpers
	// ====================================================================

	/**
	 * Strip properties not suitable for API output
	 *
	 * @param  CommonObject $object Object to clean
	 * @return array                Cleaned associative array
	 */
	protected function cleanObjectDatas($object)
	{
		$object = parent::_cleanObjectDatas($object);
		// Remove large/internal fields not relevant to API consumers
		unset($object->linkedObjectsIds, $object->context, $object->canvas, $object->fk_project);
		return $object;
	}
}
