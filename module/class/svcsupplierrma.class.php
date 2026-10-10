<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    class/svcsupplierrma.class.php
 * \ingroup warrantysvc
 * \brief   Supplier/manufacturer service RMA linked to a WarrantySvc Service Request
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

class SvcSupplierRma extends CommonObject
{
	public $TRIGGER_PREFIX = 'SVCSUPPLIERRMA';
	public $module = 'warrantysvc';
	public $element = 'svcsupplierrma';
	public $table_element = 'svc_supplier_rma';
	public $picto = 'tools';
	protected $table_ref_field = 'ref';

	const STATUS_DRAFT = 'draft';
	const STATUS_AUTHORIZED = 'authorized';
	const STATUS_SHIPPED = 'shipped';
	const STATUS_RECEIVED_BY_SUPPLIER = 'received_by_supplier';
	const STATUS_IN_SERVICE = 'in_service';
	const STATUS_REPAIRED = 'repaired';
	const STATUS_REPLACED = 'replaced';
	const STATUS_REJECTED = 'rejected';
	const STATUS_RETURNED = 'returned';
	const STATUS_CLOSED = 'closed';
	const STATUS_CANCELLED = 'cancelled';

	const RESULT_REPAIRED = 'repaired';
	const RESULT_REPLACED = 'replaced';
	const RESULT_REJECTED = 'rejected';
	const RESULT_CREDIT = 'credit';
	const RESULT_NO_FAULT_FOUND = 'no_fault_found';
	const RESULT_OTHER = 'other';

	public $fields = array(
		'rowid' => array('type'=>'integer', 'label'=>'TechnicalID', 'enabled'=>1, 'visible'=>-1, 'notnull'=>1),
		'ref' => array('type'=>'varchar(50)', 'label'=>'Ref', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'entity' => array('type'=>'integer', 'label'=>'Entity', 'enabled'=>1, 'visible'=>-2, 'notnull'=>1),
		'fk_svc_request' => array('type'=>'integer', 'label'=>'SvcRequest', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'fk_soc_supplier' => array('type'=>'integer:Societe:societe/class/societe.class.php', 'label'=>'Supplier', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'fk_product' => array('type'=>'integer:Product:product/class/product.class.php', 'label'=>'Product', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'qty' => array('type'=>'double(24,8)', 'label'=>'Qty', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'serial_number' => array('type'=>'varchar(128)', 'label'=>'SerialNumber', 'enabled'=>1, 'visible'=>1),
		'supplier_rma_ref' => array('type'=>'varchar(128)', 'label'=>'SupplierRmaExternalRef', 'enabled'=>1, 'visible'=>1),
		'status' => array('type'=>'varchar(32)', 'label'=>'Status', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'date_request' => array('type'=>'datetime', 'label'=>'SupplierRmaDateRequest', 'enabled'=>1, 'visible'=>1),
		'date_authorized' => array('type'=>'datetime', 'label'=>'SupplierRmaDateAuthorized', 'enabled'=>1, 'visible'=>-1),
		'date_shipped' => array('type'=>'datetime', 'label'=>'DateShipped', 'enabled'=>1, 'visible'=>1),
		'date_supplier_received' => array('type'=>'datetime', 'label'=>'SupplierRmaDateSupplierReceived', 'enabled'=>1, 'visible'=>-1),
		'date_supplier_completed' => array('type'=>'datetime', 'label'=>'SupplierRmaDateSupplierCompleted', 'enabled'=>1, 'visible'=>-1),
		'date_returned' => array('type'=>'datetime', 'label'=>'SupplierRmaDateReturned', 'enabled'=>1, 'visible'=>-1),
		'outbound_carrier' => array('type'=>'varchar(100)', 'label'=>'OutboundCarrier', 'enabled'=>1, 'visible'=>1),
		'outbound_tracking' => array('type'=>'varchar(255)', 'label'=>'OutboundTracking', 'enabled'=>1, 'visible'=>1),
		'outbound_tracking_url' => array('type'=>'varchar(512)', 'label'=>'TrackingUrl', 'enabled'=>1, 'visible'=>-1),
		'return_carrier' => array('type'=>'varchar(100)', 'label'=>'ReturnCarrier', 'enabled'=>1, 'visible'=>1),
		'return_tracking' => array('type'=>'varchar(255)', 'label'=>'ReturnTracking', 'enabled'=>1, 'visible'=>1),
		'return_tracking_url' => array('type'=>'varchar(512)', 'label'=>'TrackingUrl', 'enabled'=>1, 'visible'=>-1),
		'result_type' => array('type'=>'varchar(32)', 'label'=>'SupplierRmaResult', 'enabled'=>1, 'visible'=>1),
		'replacement_serial_number' => array('type'=>'varchar(128)', 'label'=>'ReplacementSerial', 'enabled'=>1, 'visible'=>1),
		'problem_description' => array('type'=>'text', 'label'=>'SupplierRmaProblemDescription', 'enabled'=>1, 'visible'=>1),
		'diagnosis' => array('type'=>'text', 'label'=>'SupplierRmaDiagnosis', 'enabled'=>1, 'visible'=>1),
		'supplier_work_done' => array('type'=>'text', 'label'=>'SupplierRmaWorkDone', 'enabled'=>1, 'visible'=>1),
		'accessories_sent' => array('type'=>'text', 'label'=>'SupplierRmaAccessoriesSent', 'enabled'=>1, 'visible'=>1),
		'fk_warehouse_source' => array('type'=>'integer', 'label'=>'SvcWarehouseSource', 'enabled'=>1, 'visible'=>-1),
		'fk_warehouse_return' => array('type'=>'integer', 'label'=>'SvcWarehouseReturn', 'enabled'=>1, 'visible'=>-1),
		'fk_stock_movement_out' => array('type'=>'integer', 'label'=>'SupplierRmaStockMovementOut', 'enabled'=>1, 'visible'=>-1),
		'fk_stock_movement_in' => array('type'=>'integer', 'label'=>'SupplierRmaStockMovementIn', 'enabled'=>1, 'visible'=>-1),
		'note_private' => array('type'=>'html', 'label'=>'NotePrivate', 'enabled'=>1, 'visible'=>1),
		'fk_user_creat' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'UserCreation', 'enabled'=>1, 'visible'=>-2),
		'fk_user_modif' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'UserModif', 'enabled'=>1, 'visible'=>-2),
		'date_creation' => array('type'=>'datetime', 'label'=>'DateCreation', 'enabled'=>1, 'visible'=>-2, 'notnull'=>1),
		'tms' => array('type'=>'timestamp', 'label'=>'DateModification', 'enabled'=>1, 'visible'=>-2),
	);

	public $ref;
	public $entity;
	public $fk_svc_request;
	public $fk_soc_supplier;
	public $socid;
	public $fk_product;
	public $qty = 1;
	public $serial_number;
	public $supplier_rma_ref;
	public $status = self::STATUS_DRAFT;
	public $date_request;
	public $date_authorized;
	public $date_shipped;
	public $date_supplier_received;
	public $date_supplier_completed;
	public $date_returned;
	public $outbound_carrier;
	public $outbound_tracking;
	public $outbound_tracking_url;
	public $return_carrier;
	public $return_tracking;
	public $return_tracking_url;
	public $result_type;
	public $replacement_serial_number;
	public $problem_description;
	public $diagnosis;
	public $supplier_work_done;
	public $accessories_sent;
	public $fk_warehouse_source;
	public $fk_warehouse_return;
	public $fk_stock_movement_out;
	public $fk_stock_movement_in;
	public $note_private;
	public $fk_user_creat;
	public $fk_user_modif;
	public $date_creation;
	public $model_pdf = '';
	public $last_main_doc = '';

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Resolve this RMA's document folder consistently for uploads and email.
	 * Kept beneath the parent request so pre-existing RMA mail paths remain
	 * stable when the new Documents tab is enabled.
	 *
	 * @param string $requestRef Parent service request reference
	 * @return string Absolute folder path or empty string if unconfigured
	 */
	public function getDocumentOutputDir($requestRef)
	{
		global $conf;
		$base = !empty($conf->warrantysvc->multidir_output[$this->entity])
			? $conf->warrantysvc->multidir_output[$this->entity]
			: (!empty($conf->warrantysvc->dir_output) ? $conf->warrantysvc->dir_output : '');
		if ($base === '' || $requestRef === '' || empty($this->ref)) return '';
		return rtrim($base, '/').'/'.dol_sanitizeFileName($requestRef)
			.'/supplier-rma/'.dol_sanitizeFileName($this->ref);
	}

	private function sqlStringOrNull($value)
	{
		return ($value !== null && $value !== '') ? "'".$this->db->escape((string) $value)."'" : "NULL";
	}

	private function sqlIntOrNull($value)
	{
		return ((int) $value > 0) ? (string) ((int) $value) : "NULL";
	}

	private function sqlDateOrNull($value)
	{
		return !empty($value) ? "'".$this->db->idate($value)."'" : "NULL";
	}

	public function validateSupplier()
	{
		global $conf;

		if ((int) $this->fk_soc_supplier <= 0) {
			$this->error = 'ErrorSupplierRequired';
			return -1;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
		$sql .= " WHERE rowid = ".((int) $this->fk_soc_supplier);
		$sql .= " AND fournisseur = 1";
		$sql .= " AND entity IN (".getEntity('societe').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$ok = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$ok) {
			$this->error = 'ErrorSupplierRmaInvalidSupplier';
			return -1;
		}

		return 1;
	}

	public function validateServiceRequest()
	{
		global $conf;

		if ((int) $this->fk_svc_request <= 0) {
			$this->error = 'ErrorSupplierRmaServiceRequestRequired';
			return -1;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."svc_request";
		$sql .= " WHERE rowid = ".((int) $this->fk_svc_request);
		$sql .= " AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$ok = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$ok) {
			$this->error = 'ErrorSupplierRmaServiceRequestNotFound';
			return -1;
		}
		return 1;
	}

	public function create($user, $notrigger = 0)
	{
		global $conf;

		if ($this->validateServiceRequest() < 0 || $this->validateSupplier() < 0) {
			return -1;
		}
		if ((int) $this->fk_product <= 0) {
			$this->error = 'ErrorProductRequired';
			return -1;
		}
		if ((float) $this->qty <= 0) {
			$this->error = 'ErrorSupplierRmaQtyRequired';
			return -1;
		}
		if (trim((string) $this->problem_description) === '') {
			$this->error = 'ErrorSupplierRmaProblemRequired';
			return -1;
		}

		$this->db->begin();

		$now = dol_now();
		$this->entity = (int) $conf->entity;
		$this->date_creation = $now;
		if (empty($this->date_request)) {
			$this->date_request = $now;
		}
		$this->fk_user_creat = (int) $user->id;
		$this->fk_user_modif = (int) $user->id;
		$this->status = self::STATUS_DRAFT;
		$provisionalRef = substr(str_replace('.', '', uniqid('PROV-SRMA-', true)), 0, 50);

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."svc_supplier_rma (";
		$sql .= "ref, entity, fk_svc_request, fk_soc_supplier, fk_product, qty, serial_number, supplier_rma_ref, status,";
		$sql .= "date_request, outbound_carrier, outbound_tracking, outbound_tracking_url,";
		$sql .= "return_carrier, return_tracking, return_tracking_url, result_type, replacement_serial_number,";
		$sql .= "problem_description, diagnosis, supplier_work_done, accessories_sent, fk_warehouse_source, fk_warehouse_return,";
		$sql .= "fk_stock_movement_out, fk_stock_movement_in, note_private, fk_user_creat, fk_user_modif, date_creation";
		$sql .= ") VALUES (";
		$sql .= "'".$this->db->escape($provisionalRef)."'";
		$sql .= ", ".((int) $this->entity);
		$sql .= ", ".((int) $this->fk_svc_request);
		$sql .= ", ".((int) $this->fk_soc_supplier);
		$sql .= ", ".((int) $this->fk_product);
		$sql .= ", ".price2num((float) $this->qty, 'MU');
		$sql .= ", ".$this->sqlStringOrNull($this->serial_number);
		$sql .= ", ".$this->sqlStringOrNull($this->supplier_rma_ref);
		$sql .= ", '".$this->db->escape($this->status)."'";
		$sql .= ", ".$this->sqlDateOrNull($this->date_request);
		$sql .= ", ".$this->sqlStringOrNull($this->outbound_carrier);
		$sql .= ", ".$this->sqlStringOrNull($this->outbound_tracking);
		$sql .= ", ".$this->sqlStringOrNull($this->outbound_tracking_url);
		$sql .= ", ".$this->sqlStringOrNull($this->return_carrier);
		$sql .= ", ".$this->sqlStringOrNull($this->return_tracking);
		$sql .= ", ".$this->sqlStringOrNull($this->return_tracking_url);
		$sql .= ", ".$this->sqlStringOrNull($this->result_type);
		$sql .= ", ".$this->sqlStringOrNull($this->replacement_serial_number);
		$sql .= ", ".$this->sqlStringOrNull($this->problem_description);
		$sql .= ", ".$this->sqlStringOrNull($this->diagnosis);
		$sql .= ", ".$this->sqlStringOrNull($this->supplier_work_done);
		$sql .= ", ".$this->sqlStringOrNull($this->accessories_sent);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_warehouse_source);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_warehouse_return);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_stock_movement_out);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_stock_movement_in);
		$sql .= ", ".$this->sqlStringOrNull($this->note_private);
		$sql .= ", ".((int) $this->fk_user_creat);
		$sql .= ", ".((int) $this->fk_user_modif);
		$sql .= ", '".$this->db->idate($this->date_creation)."'";
		$sql .= ")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX."svc_supplier_rma");
		$this->ref = 'SRMA-'.dol_print_date($now, '%Y', 'tzserver').'-'.sprintf('%06d', $this->id);

		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_rma";
		$sql .= " SET ref = '".$this->db->escape($this->ref)."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		if ($this->logEvent('CREATE', '', self::STATUS_DRAFT, '', $user, $this->date_request) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		$this->socid = (int) $this->fk_soc_supplier;
		return $this->id;
	}

	public function fetch($id, $ref = null)
	{
		global $conf;

		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."svc_supplier_rma WHERE entity = ".((int) $conf->entity);
		if ((int) $id > 0) {
			$sql .= " AND rowid = ".((int) $id);
		} elseif (!empty($ref)) {
			$sql .= " AND ref = '".$this->db->escape($ref)."'";
		} else {
			return 0;
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}

		$this->id = (int) $obj->rowid;
		$this->ref = (string) $obj->ref;
		$this->entity = (int) $obj->entity;
		$this->fk_svc_request = (int) $obj->fk_svc_request;
		$this->fk_soc_supplier = (int) $obj->fk_soc_supplier;
		$this->socid = (int) $obj->fk_soc_supplier;
		$this->fk_product = (int) $obj->fk_product;
		$this->qty = (float) $obj->qty;
		$this->serial_number = (string) $obj->serial_number;
		$this->supplier_rma_ref = (string) $obj->supplier_rma_ref;
		$this->status = (string) $obj->status;
		foreach (array('date_request','date_authorized','date_shipped','date_supplier_received','date_supplier_completed','date_returned','date_creation') as $field) {
			$this->{$field} = !empty($obj->{$field}) ? $this->db->jdate($obj->{$field}) : null;
		}
		$this->outbound_carrier = (string) $obj->outbound_carrier;
		$this->outbound_tracking = (string) $obj->outbound_tracking;
		$this->outbound_tracking_url = (string) $obj->outbound_tracking_url;
		$this->return_carrier = (string) $obj->return_carrier;
		$this->return_tracking = (string) $obj->return_tracking;
		$this->return_tracking_url = (string) $obj->return_tracking_url;
		$this->result_type = (string) $obj->result_type;
		$this->replacement_serial_number = (string) $obj->replacement_serial_number;
		$this->problem_description = (string) $obj->problem_description;
		$this->diagnosis = (string) $obj->diagnosis;
		$this->supplier_work_done = (string) $obj->supplier_work_done;
		$this->accessories_sent = (string) $obj->accessories_sent;
		$this->fk_warehouse_source = (int) $obj->fk_warehouse_source;
		$this->fk_warehouse_return = (int) $obj->fk_warehouse_return;
		$this->fk_stock_movement_out = (int) $obj->fk_stock_movement_out;
		$this->fk_stock_movement_in = (int) $obj->fk_stock_movement_in;
		$this->note_private = (string) $obj->note_private;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_modif = (int) $obj->fk_user_modif;

		return 1;
	}

	/**
	 * Return whether the commercial/product identity is frozen for this workflow state.
	 *
	 * Supplier, product, quantity and serial/LOT identify the physical unit(s)
	 * shipped to the supplier. Once shipment happened these values must remain
	 * immutable, while logistics/result fields may still evolve.
	 *
	 * @param string|null $status Status to test, current object status when null
	 * @return bool
	 */
	public function isIdentityLocked($status = null)
	{
		$status = $status !== null ? (string) $status : (string) $this->status;

		return in_array($status, array(
			self::STATUS_SHIPPED,
			self::STATUS_RECEIVED_BY_SUPPLIER,
			self::STATUS_IN_SERVICE,
			self::STATUS_REPAIRED,
			self::STATUS_REPLACED,
			self::STATUS_REJECTED,
			self::STATUS_RETURNED,
			self::STATUS_CLOSED,
		), true);
	}

	public function update($user, $notrigger = 0)
	{
		if ((float) $this->qty <= 0) {
			$this->error = 'ErrorSupplierRmaQtyRequired';
			return -1;
		}
		if ($this->id > 0) {
			$sqlCurrent = "SELECT fk_soc_supplier, fk_product, qty, serial_number, status FROM ".MAIN_DB_PREFIX."svc_supplier_rma";
			$sqlCurrent .= " WHERE rowid = ".((int) $this->id);
			$resCurrent = $this->db->query($sqlCurrent);
			if (!$resCurrent || !($current = $this->db->fetch_object($resCurrent))) {
				$this->error = $resCurrent ? 'ErrorRecordNotFound' : $this->db->lasterror();
				if ($resCurrent) $this->db->free($resCurrent);
				return -1;
			}
			$this->db->free($resCurrent);
			if ($this->isIdentityLocked((string) $current->status)) {
				if ((int) $current->fk_soc_supplier !== (int) $this->fk_soc_supplier
					|| (int) $current->fk_product !== (int) $this->fk_product
					|| abs((float) $current->qty - (float) $this->qty) > 0.00000001
					|| (string) $current->serial_number !== (string) $this->serial_number
				) {
					$this->error = 'ErrorSupplierRmaIdentityLocked';
					return -1;
				}
			}
		}
		if ($this->id <= 0) {
			$this->error = 'ErrorRecordNotFound';
			return -1;
		}
		if ($this->validateServiceRequest() < 0 || $this->validateSupplier() < 0) {
			return -1;
		}
		if ((int) $this->fk_product <= 0) {
			$this->error = 'ErrorProductRequired';
			return -1;
		}
		if (trim((string) $this->problem_description) === '') {
			$this->error = 'ErrorSupplierRmaProblemRequired';
			return -1;
		}

		$this->fk_user_modif = (int) $user->id;
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_rma SET";
		$sql .= " fk_svc_request = ".((int) $this->fk_svc_request);
		$sql .= ", fk_soc_supplier = ".((int) $this->fk_soc_supplier);
		$sql .= ", fk_product = ".((int) $this->fk_product);
		$sql .= ", qty = ".price2num((float) $this->qty, 'MU');
		$sql .= ", serial_number = ".$this->sqlStringOrNull($this->serial_number);
		$sql .= ", supplier_rma_ref = ".$this->sqlStringOrNull($this->supplier_rma_ref);
		$sql .= ", outbound_carrier = ".$this->sqlStringOrNull($this->outbound_carrier);
		$sql .= ", outbound_tracking = ".$this->sqlStringOrNull($this->outbound_tracking);
		$sql .= ", outbound_tracking_url = ".$this->sqlStringOrNull($this->outbound_tracking_url);
		$sql .= ", return_carrier = ".$this->sqlStringOrNull($this->return_carrier);
		$sql .= ", return_tracking = ".$this->sqlStringOrNull($this->return_tracking);
		$sql .= ", return_tracking_url = ".$this->sqlStringOrNull($this->return_tracking_url);
		$sql .= ", result_type = ".$this->sqlStringOrNull($this->result_type);
		$sql .= ", replacement_serial_number = ".$this->sqlStringOrNull($this->replacement_serial_number);
		$sql .= ", problem_description = ".$this->sqlStringOrNull($this->problem_description);
		$sql .= ", diagnosis = ".$this->sqlStringOrNull($this->diagnosis);
		$sql .= ", supplier_work_done = ".$this->sqlStringOrNull($this->supplier_work_done);
		$sql .= ", accessories_sent = ".$this->sqlStringOrNull($this->accessories_sent);
		$sql .= ", fk_warehouse_source = ".$this->sqlIntOrNull($this->fk_warehouse_source);
		$sql .= ", fk_warehouse_return = ".$this->sqlIntOrNull($this->fk_warehouse_return);
		$sql .= ", note_private = ".$this->sqlStringOrNull($this->note_private);
		$sql .= ", fk_user_modif = ".((int) $this->fk_user_modif);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $this->entity);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->socid = (int) $this->fk_soc_supplier;
		return 1;
	}

	public function delete($user, $notrigger = 0)
	{
		// Phase 1 has no stock movements yet, so an RMA may be permanently
		// deleted from any workflow state. Once Phase 2 has created an actual
		// movement, hard deletion is deliberately blocked to preserve stock and
		// audit traceability.
		if (!empty($this->fk_stock_movement_out) || !empty($this->fk_stock_movement_in)) {
			$this->error = 'ErrorSupplierRmaDeleteStockMovements';
			return -1;
		}

		// An RMA with supplier correspondence is an auditable business record.
		// Never leave orphaned files or Paperless/external links behind on hard delete.
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'links';
		$sql .= " WHERE objecttype = '".$this->db->escape($this->element)."'";
		$sql .= ' AND objectid = '.((int) $this->id);
		$sql .= $this->db->plimit(1);
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$hasLinks = (bool) $this->db->fetch_object($res);
		$this->db->free($res);
		if ($hasLinks) {
			$this->error = 'ErrorSupplierRmaHasDocuments';
			return -1;
		}

		$sql = 'SELECT ref FROM '.MAIN_DB_PREFIX.'svc_request';
		$sql .= ' WHERE rowid = '.((int) $this->fk_svc_request);
		$sql .= ' AND entity = '.((int) $this->entity);
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$request = $this->db->fetch_object($res);
		$this->db->free($res);
		if (!$request) {
			$this->error = 'ErrorSupplierRmaServiceRequestNotFound';
			return -1;
		}
		$documentDir = $this->getDocumentOutputDir((string) $request->ref);
		if ($documentDir !== '' && is_dir($documentDir)) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
			if (!empty(dol_dir_list($documentDir, 'files', 0, '', '(\.meta|_preview.*\.png)$'))) {
				$this->error = 'ErrorSupplierRmaHasDocuments';
				return -1;
			}
		}

		$this->db->begin();

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_rma_log WHERE fk_supplier_rma = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$sql = "DELETE ec FROM ".MAIN_DB_PREFIX."element_contact ec";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."c_type_contact tc ON tc.rowid = ec.fk_c_type_contact";
		$sql .= " WHERE ec.element_id = ".((int) $this->id);
		$sql .= " AND tc.element = 'svcsupplierrma'";
		if ($this->db->type === 'pgsql') {
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."element_contact";
			$sql .= " WHERE element_id = ".((int) $this->id);
			$sql .= " AND fk_c_type_contact IN (SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_contact WHERE element = 'svcsupplierrma')";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_rma WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Correct one business event timestamp without rewriting the audit timeline.
	 *
	 * The timestamp stored on the RMA records when the supplier/business event
	 * actually happened; the DATE audit event always uses the time of the user's
	 * correction. Updating one whitelisted column avoids changing other fields.
	 *
	 * @param string $field Whitelisted date column
	 * @param int|null $timestamp Event time, or null to clear an optional date
	 * @param User $user Operator
	 * @param string $reason Optional explanatory note
	 * @return int 1 on success, -1 on failure
	 */
	/**
	 * Map workflow milestones to their actual business event date columns.
	 * States without a dedicated date use their recorded transition time.
	 *
	 * @param string $status Workflow status
	 * @return string|null Column name
	 */
	private function statusDateField($status)
	{
		$map = array(
			self::STATUS_AUTHORIZED => 'date_authorized',
			self::STATUS_SHIPPED => 'date_shipped',
			self::STATUS_RECEIVED_BY_SUPPLIER => 'date_supplier_received',
			self::STATUS_REPAIRED => 'date_supplier_completed',
			self::STATUS_REPLACED => 'date_supplier_completed',
			self::STATUS_REJECTED => 'date_supplier_completed',
			self::STATUS_RETURNED => 'date_returned'
		);
		return isset($map[$status]) ? $map[$status] : null;
	}

	public function setBusinessDate($field, $timestamp, $user, $reason = '')
	{
		$allowed = array(
			'date_request', 'date_authorized', 'date_shipped',
			'date_supplier_received', 'date_supplier_completed', 'date_returned'
		);
		if (!in_array((string) $field, $allowed, true) || $this->id <= 0) {
			$this->error = 'ErrorSupplierRmaInvalidDateField';
			return -1;
		}
		if ($timestamp === null && $field === 'date_request') {
			$this->error = 'ErrorSupplierRmaRequiredDate';
			return -1;
		}
		if ($timestamp !== null && (!is_int($timestamp) || $timestamp <= 0)) {
			$this->error = 'ErrorSupplierRmaInvalidDate';
			return -1;
		}
		$previous = !empty($this->{$field}) ? (int) $this->{$field} : null;
		if ($previous === $timestamp) return 1;

		$previousSql = $previous === null ? 'NULL' : $this->db->idate($previous);
		$newSql = $timestamp === null ? 'NULL' : $this->db->idate($timestamp);
		$eventNote = $field.': '.$previousSql.' -> '.$newSql;
		if (trim((string) $reason) !== '') $eventNote .= "\n".trim((string) $reason);

		$this->db->begin();
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'svc_supplier_rma SET '.$field.' = '.$this->sqlDateOrNull($timestamp);
		$sql .= ', fk_user_modif = '.((int) $user->id);
		$sql .= ' WHERE rowid = '.((int) $this->id).' AND entity = '.((int) $this->entity);
		$sql .= ' AND '.($previous === null ? $field.' IS NULL' : $field." = '".$this->db->escape($previousSql)."'");
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) !== 1) {
			$this->error = 'ErrorSupplierRmaConcurrentUpdate';
			$this->db->rollback();
			return -1;
		}
		// Synchronize the effective time on the actual lifecycle milestone,
		// without altering date_event (the immutable time it was recorded).
		// A date entered before its workflow step has no milestone yet; the
		// future setStatus() call picks up that date from this RMA.
		$milestoneStatuses = array(
			'date_authorized' => array(self::STATUS_AUTHORIZED),
			'date_shipped' => array(self::STATUS_SHIPPED),
			'date_supplier_received' => array(self::STATUS_RECEIVED_BY_SUPPLIER),
			'date_supplier_completed' => array(self::STATUS_REPAIRED, self::STATUS_REPLACED, self::STATUS_REJECTED),
			'date_returned' => array(self::STATUS_RETURNED)
		);
		$eventQuery = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'svc_supplier_rma_log';
		$eventQuery .= ' WHERE entity = '.((int) $this->entity);
		$eventQuery .= ' AND fk_supplier_rma = '.((int) $this->id);
		if ($field === 'date_request') {
			$eventQuery .= " AND event_code = 'CREATE'";
		} else {
			$statusList = array_map(array($this->db, 'escape'), $milestoneStatuses[$field]);
			$eventQuery .= " AND event_code = 'STATUS' AND new_status IN ('".implode("','", $statusList)."')";
		}
		$eventQuery .= ' ORDER BY rowid DESC'.$this->db->plimit(1);
		$eventResult = $this->db->query($eventQuery);
		if (!$eventResult) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$milestoneRow = $this->db->fetch_object($eventResult);
		$this->db->free($eventResult);
		if ($milestoneRow) {
			$updateEvent = 'UPDATE '.MAIN_DB_PREFIX.'svc_supplier_rma_log';
			$updateEvent .= ' SET date_effective = '.$this->sqlDateOrNull($timestamp);
			$updateEvent .= ' WHERE rowid = '.((int) $milestoneRow->rowid);
			$updateEvent .= ' AND entity = '.((int) $this->entity);
			$updateEvent .= ' AND fk_supplier_rma = '.((int) $this->id);
			if (!$this->db->query($updateEvent)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		if ($this->logEvent('DATE', $this->status, $this->status, $eventNote, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($this->db->commit() <= 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->{$field} = $timestamp;
		$this->fk_user_modif = (int) $user->id;
		return 1;
	}

	public function setStatus($newStatus, $user, $note = '')
	{
		$transitions = array(
			self::STATUS_DRAFT => array(self::STATUS_AUTHORIZED, self::STATUS_CANCELLED),
			self::STATUS_AUTHORIZED => array(self::STATUS_SHIPPED, self::STATUS_CANCELLED),
			self::STATUS_SHIPPED => array(self::STATUS_RECEIVED_BY_SUPPLIER),
			self::STATUS_RECEIVED_BY_SUPPLIER => array(self::STATUS_IN_SERVICE),
			self::STATUS_IN_SERVICE => array(self::STATUS_REPAIRED, self::STATUS_REPLACED, self::STATUS_REJECTED),
			self::STATUS_REPAIRED => array(self::STATUS_RETURNED),
			self::STATUS_REPLACED => array(self::STATUS_RETURNED),
			self::STATUS_REJECTED => array(self::STATUS_RETURNED),
			self::STATUS_RETURNED => array(self::STATUS_CLOSED),
			self::STATUS_CANCELLED => array(self::STATUS_DRAFT),
			self::STATUS_CLOSED => array(),
		);

		if (!isset($transitions[$this->status]) || !in_array($newStatus, $transitions[$this->status], true)) {
			$this->error = 'ErrorSupplierRmaInvalidTransition';
			return -1;
		}

		$oldStatus = $this->status;
		$now = dol_now();
		$sets = array(
			"status = '".$this->db->escape($newStatus)."'",
			"fk_user_modif = ".((int) $user->id),
		);
		if ($newStatus === self::STATUS_AUTHORIZED && empty($this->date_authorized)) {
			$sets[] = "date_authorized = '".$this->db->idate($now)."'";
			$this->date_authorized = $now;
		}
		if ($newStatus === self::STATUS_SHIPPED && empty($this->date_shipped)) {
			$sets[] = "date_shipped = '".$this->db->idate($now)."'";
			$this->date_shipped = $now;
		}
		if ($newStatus === self::STATUS_RECEIVED_BY_SUPPLIER && empty($this->date_supplier_received)) {
			$sets[] = "date_supplier_received = '".$this->db->idate($now)."'";
			$this->date_supplier_received = $now;
		}
		if (in_array($newStatus, array(self::STATUS_REPAIRED, self::STATUS_REPLACED, self::STATUS_REJECTED), true) && empty($this->date_supplier_completed)) {
			$sets[] = "date_supplier_completed = '".$this->db->idate($now)."'";
			$this->date_supplier_completed = $now;
			if (empty($this->result_type)) {
				$this->result_type = $newStatus;
				$sets[] = "result_type = '".$this->db->escape($this->result_type)."'";
			}
		}
		if ($newStatus === self::STATUS_RETURNED && empty($this->date_returned)) {
			$sets[] = "date_returned = '".$this->db->idate($now)."'";
			$this->date_returned = $now;
		}

		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_rma SET ".implode(', ', $sets);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND status = '".$this->db->escape($oldStatus)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) < 1) {
			$this->error = 'ErrorSupplierRmaConcurrentUpdate';
			$this->db->rollback();
			return -1;
		}

		$this->status = $newStatus;
		$this->fk_user_modif = (int) $user->id;
		$eventDateField = $this->statusDateField($newStatus);
		$effectiveDate = $eventDateField && !empty($this->{$eventDateField}) ? $this->{$eventDateField} : $now;
		if ($this->logEvent('STATUS', $oldStatus, $newStatus, $note, $user, $effectiveDate) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}


	/**
	 * Roll back one workflow step as a correction.
	 *
	 * The target is taken from the most recent forward STATUS event that led to
	 * the current state. ROLLBACK events are intentionally ignored so repeated
	 * corrections continue walking backwards instead of oscillating.
	 *
	 * Stock-aware guards are already present for Phase 2: once a real outbound
	 * or inbound stock movement exists we never move the workflow to a state
	 * that would contradict that physical movement.
	 *
	 * @param  User   $user User performing the correction
	 * @param  string $note Optional audit note
	 * @return int          1 if OK, -1 on error
	 */
	public function rollbackStatus($user, $note = '')
	{
		if ($this->status === self::STATUS_DRAFT) {
			$this->error = 'ErrorSupplierRmaNoPreviousStatus';
			return -1;
		}

		$sql = "SELECT old_status FROM ".MAIN_DB_PREFIX."svc_supplier_rma_log";
		$sql .= " WHERE fk_supplier_rma = ".((int) $this->id);
		$sql .= " AND event_code = 'STATUS'";
		$sql .= " AND new_status = '".$this->db->escape($this->status)."'";
		$sql .= " AND old_status IS NOT NULL AND old_status <> ''";
		$sql .= " ORDER BY rowid DESC";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj || empty($obj->old_status)) {
			$this->error = 'ErrorSupplierRmaNoPreviousStatus';
			return -1;
		}

		$oldStatus = $this->status;
		$newStatus = (string) $obj->old_status;

		// Physical stock already left our warehouse: going back before SHIPPED
		// would make the workflow lie about where the unit is.
		if ($oldStatus === self::STATUS_SHIPPED
			&& $newStatus === self::STATUS_AUTHORIZED
			&& !empty($this->fk_stock_movement_out)
		) {
			$this->error = 'ErrorSupplierRmaRollbackOutboundStock';
			return -1;
		}

		// Physical stock already returned: going back before RETURNED would
		// contradict the recorded inbound movement.
		if ($oldStatus === self::STATUS_RETURNED && !empty($this->fk_stock_movement_in)) {
			$this->error = 'ErrorSupplierRmaRollbackInboundStock';
			return -1;
		}

		$sets = array(
			"status = '".$this->db->escape($newStatus)."'",
			"fk_user_modif = ".((int) $user->id),
		);

		// Clear timestamps introduced by the accidentally-entered state.
		if ($oldStatus === self::STATUS_AUTHORIZED) {
			$sets[] = "date_authorized = NULL";
			$this->date_authorized = null;
		} elseif ($oldStatus === self::STATUS_SHIPPED) {
			$sets[] = "date_shipped = NULL";
			$this->date_shipped = null;
		} elseif ($oldStatus === self::STATUS_RECEIVED_BY_SUPPLIER) {
			$sets[] = "date_supplier_received = NULL";
			$this->date_supplier_received = null;
		} elseif (in_array($oldStatus, array(self::STATUS_REPAIRED, self::STATUS_REPLACED, self::STATUS_REJECTED), true)) {
			$sets[] = "date_supplier_completed = NULL";
			$this->date_supplier_completed = null;
			if ($this->result_type === $oldStatus) {
				$sets[] = "result_type = NULL";
				$this->result_type = '';
			}
		} elseif ($oldStatus === self::STATUS_RETURNED) {
			$sets[] = "date_returned = NULL";
			$this->date_returned = null;
		}

		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_rma SET ".implode(', ', $sets);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND status = '".$this->db->escape($oldStatus)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) < 1) {
			$this->error = 'ErrorSupplierRmaConcurrentUpdate';
			$this->db->rollback();
			return -1;
		}

		$this->status = $newStatus;
		$this->fk_user_modif = (int) $user->id;
		if ($this->logEvent('ROLLBACK', $oldStatus, $newStatus, $note, $user) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	public function logEvent($eventCode, $oldStatus, $newStatus, $note, $user, $effectiveDate = null)
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."svc_supplier_rma_log";
		$sql .= " (entity, fk_supplier_rma, event_code, old_status, new_status, note, date_event, date_effective, fk_user) VALUES (";
		$sql .= ((int) $conf->entity);
		$sql .= ", ".((int) $this->id);
		$sql .= ", '".$this->db->escape($eventCode)."'";
		$sql .= ", ".$this->sqlStringOrNull($oldStatus);
		$sql .= ", ".$this->sqlStringOrNull($newStatus);
		$sql .= ", ".$this->sqlStringOrNull($note);
		$sql .= ", '".$this->db->idate(dol_now())."'";
		$sql .= ", ".$this->sqlDateOrNull($effectiveDate);
		$sql .= ", ".((int) $user->id);
		$sql .= ")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	public function fetchHistory()
	{
		$rows = array();
		$sql = "SELECT l.*, u.login, u.firstname, u.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_rma_log l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.fk_supplier_rma = ".((int) $this->id);
		$sql .= " ORDER BY COALESCE(l.date_effective, l.date_event) ASC, l.rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'event_code' => (string) $obj->event_code,
				'old_status' => (string) $obj->old_status,
				'new_status' => (string) $obj->new_status,
				'note' => (string) $obj->note,
				'date_event' => !empty($obj->date_event) ? $this->db->jdate($obj->date_event) : null,
				'date_effective' => !empty($obj->date_effective) ? $this->db->jdate($obj->date_effective) : null,
				'fk_user' => (int) $obj->fk_user,
				'user_name' => trim((string) $obj->firstname.' '.(string) $obj->lastname) ?: (string) $obj->login,
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	public static function fetchAllForServiceRequest($db, $serviceRequestId, $entity = null)
	{
		global $conf;
		$entity = $entity !== null ? (int) $entity : (int) $conf->entity;
		$out = array();
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."svc_supplier_rma";
		$sql .= " WHERE fk_svc_request = ".((int) $serviceRequestId);
		$sql .= " AND entity = ".$entity;
		$sql .= " ORDER BY date_creation DESC, rowid DESC";
		$resql = $db->query($sql);
		if (!$resql) {
			return $out;
		}
		while ($obj = $db->fetch_object($resql)) {
			$item = new self($db);
			if ($item->fetch((int) $obj->rowid) > 0) {
				$out[] = $item;
			}
		}
		$db->free($resql);
		return $out;
	}

	public static function countForServiceRequest($db, $serviceRequestId, $entity = null)
	{
		global $conf;
		$entity = $entity !== null ? (int) $entity : (int) $conf->entity;
		$sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."svc_supplier_rma";
		$sql .= " WHERE fk_svc_request = ".((int) $serviceRequestId);
		$sql .= " AND entity = ".$entity;
		$resql = $db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		return $obj ? (int) $obj->nb : 0;
	}

	public function getNomUrl($withpicto = 0)
	{
		$url = DOL_URL_ROOT.'/custom/warrantysvc/supplier_rma_card.php?id='.$this->id;
		$label = dol_escape_htmltag($this->ref);
		if ($withpicto) {
			$label = img_picto('', $this->picto, 'class="pictofixedwidth"').$label;
		}
		return '<a href="'.$url.'">'.$label.'</a>';
	}

	public function getLibStatut($mode = 0)
	{
		global $langs;
		$langs->load('warrantysvc@warrantysvc');
		$map = array(
			self::STATUS_DRAFT => array('SupplierRmaStatusDraft', 'status0'),
			self::STATUS_AUTHORIZED => array('SupplierRmaStatusAuthorized', 'status1'),
			self::STATUS_SHIPPED => array('SupplierRmaStatusShipped', 'status4'),
			self::STATUS_RECEIVED_BY_SUPPLIER => array('SupplierRmaStatusReceivedBySupplier', 'status4'),
			self::STATUS_IN_SERVICE => array('SupplierRmaStatusInService', 'status3'),
			self::STATUS_REPAIRED => array('SupplierRmaStatusRepaired', 'status6'),
			self::STATUS_REPLACED => array('SupplierRmaStatusReplaced', 'status6'),
			self::STATUS_REJECTED => array('SupplierRmaStatusRejected', 'status8'),
			self::STATUS_RETURNED => array('SupplierRmaStatusReturned', 'status6'),
			self::STATUS_CLOSED => array('SupplierRmaStatusClosed', 'status6'),
			self::STATUS_CANCELLED => array('SupplierRmaStatusCancelled', 'status9'),
		);
		$item = isset($map[$this->status]) ? $map[$this->status] : array($this->status, 'status0');
		$label = $langs->trans($item[0]);
		if ($mode == 1) {
			return $label;
		}
		return '<span class="badge '.$item[1].'">'.$label.'</span>';
	}

	public static function getResultOptions($langs)
	{
		return array(
			'' => $langs->trans('SupplierRmaResultNone'),
			self::RESULT_REPAIRED => $langs->trans('SupplierRmaResultRepaired'),
			self::RESULT_REPLACED => $langs->trans('SupplierRmaResultReplaced'),
			self::RESULT_REJECTED => $langs->trans('SupplierRmaResultRejected'),
			self::RESULT_CREDIT => $langs->trans('SupplierRmaResultCredit'),
			self::RESULT_NO_FAULT_FOUND => $langs->trans('SupplierRmaResultNoFaultFound'),
			self::RESULT_OTHER => $langs->trans('SupplierRmaResultOther'),
		);
	}
}
))) {
				$this->error = 'ErrorSupplierRmaHasDocuments';
				return -1;
			}
		}

		$this->db->begin();

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_rma_log WHERE fk_supplier_rma = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$sql = "DELETE ec FROM ".MAIN_DB_PREFIX."element_contact ec";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."c_type_contact tc ON tc.rowid = ec.fk_c_type_contact";
		$sql .= " WHERE ec.element_id = ".((int) $this->id);
		$sql .= " AND tc.element = 'svcsupplierrma'";
		if ($this->db->type === 'pgsql') {
			$sql = "DELETE FROM ".MAIN_DB_PREFIX."element_contact";
			$sql .= " WHERE element_id = ".((int) $this->id);
			$sql .= " AND fk_c_type_contact IN (SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_contact WHERE element = 'svcsupplierrma')";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_rma WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Correct one business event timestamp without rewriting the audit timeline.
	 *
	 * The timestamp stored on the RMA records when the supplier/business event
	 * actually happened; the DATE audit event always uses the time of the user's
	 * correction. Updating one whitelisted column avoids changing other fields.
	 *
	 * @param string $field Whitelisted date column
	 * @param int|null $timestamp Event time, or null to clear an optional date
	 * @param User $user Operator
	 * @param string $reason Optional explanatory note
	 * @return int 1 on success, -1 on failure
	 */
	/**
	 * Map workflow milestones to their actual business event date columns.
	 * States without a dedicated date use their recorded transition time.
	 *
	 * @param string $status Workflow status
	 * @return string|null Column name
	 */
	private function statusDateField($status)
	{
		$map = array(
			self::STATUS_AUTHORIZED => 'date_authorized',
			self::STATUS_SHIPPED => 'date_shipped',
			self::STATUS_RECEIVED_BY_SUPPLIER => 'date_supplier_received',
			self::STATUS_REPAIRED => 'date_supplier_completed',
			self::STATUS_REPLACED => 'date_supplier_completed',
			self::STATUS_REJECTED => 'date_supplier_completed',
			self::STATUS_RETURNED => 'date_returned'
		);
		return isset($map[$status]) ? $map[$status] : null;
	}

	public function setBusinessDate($field, $timestamp, $user, $reason = '')
	{
		$allowed = array(
			'date_request', 'date_authorized', 'date_shipped',
			'date_supplier_received', 'date_supplier_completed', 'date_returned'
		);
		if (!in_array((string) $field, $allowed, true) || $this->id <= 0) {
			$this->error = 'ErrorSupplierRmaInvalidDateField';
			return -1;
		}
		if ($timestamp === null && $field === 'date_request') {
			$this->error = 'ErrorSupplierRmaRequiredDate';
			return -1;
		}
		if ($timestamp !== null && (!is_int($timestamp) || $timestamp <= 0)) {
			$this->error = 'ErrorSupplierRmaInvalidDate';
			return -1;
		}
		$previous = !empty($this->{$field}) ? (int) $this->{$field} : null;
		if ($previous === $timestamp) return 1;

		$previousSql = $previous === null ? 'NULL' : $this->db->idate($previous);
		$newSql = $timestamp === null ? 'NULL' : $this->db->idate($timestamp);
		$eventNote = $field.': '.$previousSql.' -> '.$newSql;
		if (trim((string) $reason) !== '') $eventNote .= "\n".trim((string) $reason);

		$this->db->begin();
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'svc_supplier_rma SET '.$field.' = '.$this->sqlDateOrNull($timestamp);
		$sql .= ', fk_user_modif = '.((int) $user->id);
		$sql .= ' WHERE rowid = '.((int) $this->id).' AND entity = '.((int) $this->entity);
		$sql .= ' AND '.($previous === null ? $field.' IS NULL' : $field." = '".$this->db->escape($previousSql)."'");
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) !== 1) {
			$this->error = 'ErrorSupplierRmaConcurrentUpdate';
			$this->db->rollback();
			return -1;
		}
		// Synchronize the effective time on the actual lifecycle milestone,
		// without altering date_event (the immutable time it was recorded).
		// A date entered before its workflow step has no milestone yet; the
		// future setStatus() call picks up that date from this RMA.
		$milestoneStatuses = array(
			'date_authorized' => array(self::STATUS_AUTHORIZED),
			'date_shipped' => array(self::STATUS_SHIPPED),
			'date_supplier_received' => array(self::STATUS_RECEIVED_BY_SUPPLIER),
			'date_supplier_completed' => array(self::STATUS_REPAIRED, self::STATUS_REPLACED, self::STATUS_REJECTED),
			'date_returned' => array(self::STATUS_RETURNED)
		);
		$eventQuery = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'svc_supplier_rma_log';
		$eventQuery .= ' WHERE entity = '.((int) $this->entity);
		$eventQuery .= ' AND fk_supplier_rma = '.((int) $this->id);
		if ($field === 'date_request') {
			$eventQuery .= " AND event_code = 'CREATE'";
		} else {
			$statusList = array_map(array($this->db, 'escape'), $milestoneStatuses[$field]);
			$eventQuery .= " AND event_code = 'STATUS' AND new_status IN ('".implode("','", $statusList)."')";
		}
		$eventQuery .= ' ORDER BY rowid DESC'.$this->db->plimit(1);
		$eventResult = $this->db->query($eventQuery);
		if (!$eventResult) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$milestoneRow = $this->db->fetch_object($eventResult);
		$this->db->free($eventResult);
		if ($milestoneRow) {
			$updateEvent = 'UPDATE '.MAIN_DB_PREFIX.'svc_supplier_rma_log';
			$updateEvent .= ' SET date_effective = '.$this->sqlDateOrNull($timestamp);
			$updateEvent .= ' WHERE rowid = '.((int) $milestoneRow->rowid);
			$updateEvent .= ' AND entity = '.((int) $this->entity);
			$updateEvent .= ' AND fk_supplier_rma = '.((int) $this->id);
			if (!$this->db->query($updateEvent)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		if ($this->logEvent('DATE', $this->status, $this->status, $eventNote, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($this->db->commit() <= 0) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->{$field} = $timestamp;
		$this->fk_user_modif = (int) $user->id;
		return 1;
	}

	public function setStatus($newStatus, $user, $note = '')
	{
		$transitions = array(
			self::STATUS_DRAFT => array(self::STATUS_AUTHORIZED, self::STATUS_CANCELLED),
			self::STATUS_AUTHORIZED => array(self::STATUS_SHIPPED, self::STATUS_CANCELLED),
			self::STATUS_SHIPPED => array(self::STATUS_RECEIVED_BY_SUPPLIER),
			self::STATUS_RECEIVED_BY_SUPPLIER => array(self::STATUS_IN_SERVICE),
			self::STATUS_IN_SERVICE => array(self::STATUS_REPAIRED, self::STATUS_REPLACED, self::STATUS_REJECTED),
			self::STATUS_REPAIRED => array(self::STATUS_RETURNED),
			self::STATUS_REPLACED => array(self::STATUS_RETURNED),
			self::STATUS_REJECTED => array(self::STATUS_RETURNED),
			self::STATUS_RETURNED => array(self::STATUS_CLOSED),
			self::STATUS_CANCELLED => array(self::STATUS_DRAFT),
			self::STATUS_CLOSED => array(),
		);

		if (!isset($transitions[$this->status]) || !in_array($newStatus, $transitions[$this->status], true)) {
			$this->error = 'ErrorSupplierRmaInvalidTransition';
			return -1;
		}

		$oldStatus = $this->status;
		$now = dol_now();
		$sets = array(
			"status = '".$this->db->escape($newStatus)."'",
			"fk_user_modif = ".((int) $user->id),
		);
		if ($newStatus === self::STATUS_AUTHORIZED && empty($this->date_authorized)) {
			$sets[] = "date_authorized = '".$this->db->idate($now)."'";
			$this->date_authorized = $now;
		}
		if ($newStatus === self::STATUS_SHIPPED && empty($this->date_shipped)) {
			$sets[] = "date_shipped = '".$this->db->idate($now)."'";
			$this->date_shipped = $now;
		}
		if ($newStatus === self::STATUS_RECEIVED_BY_SUPPLIER && empty($this->date_supplier_received)) {
			$sets[] = "date_supplier_received = '".$this->db->idate($now)."'";
			$this->date_supplier_received = $now;
		}
		if (in_array($newStatus, array(self::STATUS_REPAIRED, self::STATUS_REPLACED, self::STATUS_REJECTED), true) && empty($this->date_supplier_completed)) {
			$sets[] = "date_supplier_completed = '".$this->db->idate($now)."'";
			$this->date_supplier_completed = $now;
			if (empty($this->result_type)) {
				$this->result_type = $newStatus;
				$sets[] = "result_type = '".$this->db->escape($this->result_type)."'";
			}
		}
		if ($newStatus === self::STATUS_RETURNED && empty($this->date_returned)) {
			$sets[] = "date_returned = '".$this->db->idate($now)."'";
			$this->date_returned = $now;
		}

		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_rma SET ".implode(', ', $sets);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND status = '".$this->db->escape($oldStatus)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) < 1) {
			$this->error = 'ErrorSupplierRmaConcurrentUpdate';
			$this->db->rollback();
			return -1;
		}

		$this->status = $newStatus;
		$this->fk_user_modif = (int) $user->id;
		$eventDateField = $this->statusDateField($newStatus);
		$effectiveDate = $eventDateField && !empty($this->{$eventDateField}) ? $this->{$eventDateField} : $now;
		if ($this->logEvent('STATUS', $oldStatus, $newStatus, $note, $user, $effectiveDate) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}


	/**
	 * Roll back one workflow step as a correction.
	 *
	 * The target is taken from the most recent forward STATUS event that led to
	 * the current state. ROLLBACK events are intentionally ignored so repeated
	 * corrections continue walking backwards instead of oscillating.
	 *
	 * Stock-aware guards are already present for Phase 2: once a real outbound
	 * or inbound stock movement exists we never move the workflow to a state
	 * that would contradict that physical movement.
	 *
	 * @param  User   $user User performing the correction
	 * @param  string $note Optional audit note
	 * @return int          1 if OK, -1 on error
	 */
	public function rollbackStatus($user, $note = '')
	{
		if ($this->status === self::STATUS_DRAFT) {
			$this->error = 'ErrorSupplierRmaNoPreviousStatus';
			return -1;
		}

		$sql = "SELECT old_status FROM ".MAIN_DB_PREFIX."svc_supplier_rma_log";
		$sql .= " WHERE fk_supplier_rma = ".((int) $this->id);
		$sql .= " AND event_code = 'STATUS'";
		$sql .= " AND new_status = '".$this->db->escape($this->status)."'";
		$sql .= " AND old_status IS NOT NULL AND old_status <> ''";
		$sql .= " ORDER BY rowid DESC";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj || empty($obj->old_status)) {
			$this->error = 'ErrorSupplierRmaNoPreviousStatus';
			return -1;
		}

		$oldStatus = $this->status;
		$newStatus = (string) $obj->old_status;

		// Physical stock already left our warehouse: going back before SHIPPED
		// would make the workflow lie about where the unit is.
		if ($oldStatus === self::STATUS_SHIPPED
			&& $newStatus === self::STATUS_AUTHORIZED
			&& !empty($this->fk_stock_movement_out)
		) {
			$this->error = 'ErrorSupplierRmaRollbackOutboundStock';
			return -1;
		}

		// Physical stock already returned: going back before RETURNED would
		// contradict the recorded inbound movement.
		if ($oldStatus === self::STATUS_RETURNED && !empty($this->fk_stock_movement_in)) {
			$this->error = 'ErrorSupplierRmaRollbackInboundStock';
			return -1;
		}

		$sets = array(
			"status = '".$this->db->escape($newStatus)."'",
			"fk_user_modif = ".((int) $user->id),
		);

		// Clear timestamps introduced by the accidentally-entered state.
		if ($oldStatus === self::STATUS_AUTHORIZED) {
			$sets[] = "date_authorized = NULL";
			$this->date_authorized = null;
		} elseif ($oldStatus === self::STATUS_SHIPPED) {
			$sets[] = "date_shipped = NULL";
			$this->date_shipped = null;
		} elseif ($oldStatus === self::STATUS_RECEIVED_BY_SUPPLIER) {
			$sets[] = "date_supplier_received = NULL";
			$this->date_supplier_received = null;
		} elseif (in_array($oldStatus, array(self::STATUS_REPAIRED, self::STATUS_REPLACED, self::STATUS_REJECTED), true)) {
			$sets[] = "date_supplier_completed = NULL";
			$this->date_supplier_completed = null;
			if ($this->result_type === $oldStatus) {
				$sets[] = "result_type = NULL";
				$this->result_type = '';
			}
		} elseif ($oldStatus === self::STATUS_RETURNED) {
			$sets[] = "date_returned = NULL";
			$this->date_returned = null;
		}

		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_rma SET ".implode(', ', $sets);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND status = '".$this->db->escape($oldStatus)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) < 1) {
			$this->error = 'ErrorSupplierRmaConcurrentUpdate';
			$this->db->rollback();
			return -1;
		}

		$this->status = $newStatus;
		$this->fk_user_modif = (int) $user->id;
		if ($this->logEvent('ROLLBACK', $oldStatus, $newStatus, $note, $user) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	public function logEvent($eventCode, $oldStatus, $newStatus, $note, $user, $effectiveDate = null)
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."svc_supplier_rma_log";
		$sql .= " (entity, fk_supplier_rma, event_code, old_status, new_status, note, date_event, date_effective, fk_user) VALUES (";
		$sql .= ((int) $conf->entity);
		$sql .= ", ".((int) $this->id);
		$sql .= ", '".$this->db->escape($eventCode)."'";
		$sql .= ", ".$this->sqlStringOrNull($oldStatus);
		$sql .= ", ".$this->sqlStringOrNull($newStatus);
		$sql .= ", ".$this->sqlStringOrNull($note);
		$sql .= ", '".$this->db->idate(dol_now())."'";
		$sql .= ", ".$this->sqlDateOrNull($effectiveDate);
		$sql .= ", ".((int) $user->id);
		$sql .= ")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	public function fetchHistory()
	{
		$rows = array();
		$sql = "SELECT l.*, u.login, u.firstname, u.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_rma_log l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.fk_supplier_rma = ".((int) $this->id);
		$sql .= " ORDER BY COALESCE(l.date_effective, l.date_event) ASC, l.rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return array();
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'event_code' => (string) $obj->event_code,
				'old_status' => (string) $obj->old_status,
				'new_status' => (string) $obj->new_status,
				'note' => (string) $obj->note,
				'date_event' => !empty($obj->date_event) ? $this->db->jdate($obj->date_event) : null,
				'date_effective' => !empty($obj->date_effective) ? $this->db->jdate($obj->date_effective) : null,
				'fk_user' => (int) $obj->fk_user,
				'user_name' => trim((string) $obj->firstname.' '.(string) $obj->lastname) ?: (string) $obj->login,
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	public static function fetchAllForServiceRequest($db, $serviceRequestId, $entity = null)
	{
		global $conf;
		$entity = $entity !== null ? (int) $entity : (int) $conf->entity;
		$out = array();
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."svc_supplier_rma";
		$sql .= " WHERE fk_svc_request = ".((int) $serviceRequestId);
		$sql .= " AND entity = ".$entity;
		$sql .= " ORDER BY date_creation DESC, rowid DESC";
		$resql = $db->query($sql);
		if (!$resql) {
			return $out;
		}
		while ($obj = $db->fetch_object($resql)) {
			$item = new self($db);
			if ($item->fetch((int) $obj->rowid) > 0) {
				$out[] = $item;
			}
		}
		$db->free($resql);
		return $out;
	}

	public static function countForServiceRequest($db, $serviceRequestId, $entity = null)
	{
		global $conf;
		$entity = $entity !== null ? (int) $entity : (int) $conf->entity;
		$sql = "SELECT COUNT(*) AS nb FROM ".MAIN_DB_PREFIX."svc_supplier_rma";
		$sql .= " WHERE fk_svc_request = ".((int) $serviceRequestId);
		$sql .= " AND entity = ".$entity;
		$resql = $db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		return $obj ? (int) $obj->nb : 0;
	}

	public function getNomUrl($withpicto = 0)
	{
		$url = DOL_URL_ROOT.'/custom/warrantysvc/supplier_rma_card.php?id='.$this->id;
		$label = dol_escape_htmltag($this->ref);
		if ($withpicto) {
			$label = img_picto('', $this->picto, 'class="pictofixedwidth"').$label;
		}
		return '<a href="'.$url.'">'.$label.'</a>';
	}

	public function getLibStatut($mode = 0)
	{
		global $langs;
		$langs->load('warrantysvc@warrantysvc');
		$map = array(
			self::STATUS_DRAFT => array('SupplierRmaStatusDraft', 'status0'),
			self::STATUS_AUTHORIZED => array('SupplierRmaStatusAuthorized', 'status1'),
			self::STATUS_SHIPPED => array('SupplierRmaStatusShipped', 'status4'),
			self::STATUS_RECEIVED_BY_SUPPLIER => array('SupplierRmaStatusReceivedBySupplier', 'status4'),
			self::STATUS_IN_SERVICE => array('SupplierRmaStatusInService', 'status3'),
			self::STATUS_REPAIRED => array('SupplierRmaStatusRepaired', 'status6'),
			self::STATUS_REPLACED => array('SupplierRmaStatusReplaced', 'status6'),
			self::STATUS_REJECTED => array('SupplierRmaStatusRejected', 'status8'),
			self::STATUS_RETURNED => array('SupplierRmaStatusReturned', 'status6'),
			self::STATUS_CLOSED => array('SupplierRmaStatusClosed', 'status6'),
			self::STATUS_CANCELLED => array('SupplierRmaStatusCancelled', 'status9'),
		);
		$item = isset($map[$this->status]) ? $map[$this->status] : array($this->status, 'status0');
		$label = $langs->trans($item[0]);
		if ($mode == 1) {
			return $label;
		}
		return '<span class="badge '.$item[1].'">'.$label.'</span>';
	}

	public static function getResultOptions($langs)
	{
		return array(
			'' => $langs->trans('SupplierRmaResultNone'),
			self::RESULT_REPAIRED => $langs->trans('SupplierRmaResultRepaired'),
			self::RESULT_REPLACED => $langs->trans('SupplierRmaResultReplaced'),
			self::RESULT_REJECTED => $langs->trans('SupplierRmaResultRejected'),
			self::RESULT_CREDIT => $langs->trans('SupplierRmaResultCredit'),
			self::RESULT_NO_FAULT_FOUND => $langs->trans('SupplierRmaResultNoFaultFound'),
			self::RESULT_OTHER => $langs->trans('SupplierRmaResultOther'),
		);
	}
}
