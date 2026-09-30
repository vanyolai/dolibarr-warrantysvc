<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    class/svcsupplierreturn.class.php
 * \ingroup warrantysvc
 * \brief   Supplier return document (non-warranty goods return)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierreturnline.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/warrantysvcstockservice.class.php';

class SvcSupplierReturn extends CommonObject
{
	public $TRIGGER_PREFIX = 'SVCSUPPLIERRETURN';
	public $module = 'warrantysvc';
	public $element = 'svcsupplierreturn';
	public $table_element = 'svc_supplier_return';
	public $table_element_line = 'svc_supplier_return_line';
	public $picto = 'shipment';
	protected $table_ref_field = 'ref';

	const STATUS_DRAFT = 'draft';
	const STATUS_AUTHORIZED = 'authorized';
	const STATUS_SHIPPED = 'shipped';
	const STATUS_CLOSED = 'closed';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_REVERSED = 'reversed';

	const STOCK_ORIGIN_TYPE = 'SvcSupplierReturn@warrantysvc';
	const LEGACY_STOCK_ORIGIN_TYPE = 'svcsupplierreturn';

	public $fields = array(
		'rowid' => array('type'=>'integer', 'label'=>'TechnicalID', 'enabled'=>1, 'visible'=>-1, 'notnull'=>1),
		'ref' => array('type'=>'varchar(50)', 'label'=>'Ref', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'entity' => array('type'=>'integer', 'label'=>'Entity', 'enabled'=>1, 'visible'=>-2, 'notnull'=>1),
		'fk_soc_supplier' => array('type'=>'integer:Societe:societe/class/societe.class.php', 'label'=>'Supplier', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'fk_supplier_order' => array('type'=>'integer', 'label'=>'SupplierOrder', 'enabled'=>1, 'visible'=>1),
		'fk_reception' => array('type'=>'integer', 'label'=>'Reception', 'enabled'=>1, 'visible'=>1),
		'fk_supplier_invoice' => array('type'=>'integer', 'label'=>'SupplierInvoice', 'enabled'=>1, 'visible'=>-1),
		'supplier_return_ref' => array('type'=>'varchar(128)', 'label'=>'SupplierReturnExternalRef', 'enabled'=>1, 'visible'=>1),
		'reason' => array('type'=>'text', 'label'=>'SupplierReturnReason', 'enabled'=>1, 'visible'=>1),
		'status' => array('type'=>'varchar(32)', 'label'=>'Status', 'enabled'=>1, 'visible'=>1, 'notnull'=>1),
		'fk_warehouse_source' => array('type'=>'integer', 'label'=>'SvcWarehouseSource', 'enabled'=>1, 'visible'=>1),
		'outbound_carrier' => array('type'=>'varchar(100)', 'label'=>'OutboundCarrier', 'enabled'=>1, 'visible'=>1),
		'outbound_tracking' => array('type'=>'varchar(255)', 'label'=>'OutboundTracking', 'enabled'=>1, 'visible'=>1),
		'outbound_tracking_url' => array('type'=>'varchar(512)', 'label'=>'TrackingUrl', 'enabled'=>1, 'visible'=>-1),
		'date_authorized' => array('type'=>'datetime', 'label'=>'SupplierReturnDateAuthorized', 'enabled'=>1, 'visible'=>-1),
		'date_shipped' => array('type'=>'datetime', 'label'=>'DateShipped', 'enabled'=>1, 'visible'=>1),
		'date_closed' => array('type'=>'datetime', 'label'=>'DateClosed', 'enabled'=>1, 'visible'=>-1),
		'note_private' => array('type'=>'html', 'label'=>'NotePrivate', 'enabled'=>1, 'visible'=>1),
		'fk_user_creat' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'UserCreation', 'enabled'=>1, 'visible'=>-2),
		'fk_user_modif' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'UserModif', 'enabled'=>1, 'visible'=>-2),
		'date_creation' => array('type'=>'datetime', 'label'=>'DateCreation', 'enabled'=>1, 'visible'=>-2),
		'tms' => array('type'=>'timestamp', 'label'=>'DateModification', 'enabled'=>1, 'visible'=>-2),
		'model_pdf' => array('type'=>'varchar(255)', 'label'=>'ModelPdf', 'enabled'=>1, 'visible'=>-2),
		'last_main_doc' => array('type'=>'varchar(255)', 'label'=>'LastMainDoc', 'enabled'=>1, 'visible'=>-2),
	);

	public $ref;
	public $entity;
	public $fk_soc_supplier;
	public $socid;
	public $fk_supplier_order;
	public $fk_reception;
	public $fk_supplier_invoice;
	public $supplier_return_ref;
	public $reason;
	public $status = self::STATUS_DRAFT;
	public $fk_warehouse_source;
	public $outbound_carrier;
	public $outbound_tracking;
	public $outbound_tracking_url;
	public $date_authorized;
	public $date_shipped;
	public $date_closed;
	public $note_private;
	public $fk_user_creat;
	public $fk_user_modif;
	public $date_creation;
	public $model_pdf = 'supplierreturn_standard';
	public $last_main_doc = '';
	public $lines = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function sqlStringOrNull($value)
	{
		return ($value !== null && $value !== '') ? "'".$this->db->escape((string) $value)."'" : "NULL";
	}

	private function sqlIntOrNull($value)
	{
		return ((int) $value > 0) ? (string) ((int) $value) : "NULL";
	}

	/**
	 * Check whether this return already has any physical outbound movement.
	 *
	 * We inspect both the local line FK and the stock movement origin. The latter
	 * is essential for crash recovery: a movement may have committed before its
	 * local FK was persisted by an older build.
	 *
	 * @return int 1 if movement exists, 0 if none, -1 on database error
	 */
	public function hasStockMovements()
	{
		if ((int) $this->id <= 0) {
			return 0;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."svc_supplier_return_line";
		$sql .= " WHERE fk_supplier_return = ".((int) $this->id);
		$sql .= " AND fk_stock_movement_out IS NOT NULL AND fk_stock_movement_out > 0";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$found = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);
		if ($found) {
			return 1;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."stock_mouvement";
		$sql .= " WHERE origintype IN ('".self::STOCK_ORIGIN_TYPE."','".self::LEGACY_STOCK_ORIGIN_TYPE."')";
		$sql .= " AND fk_origin = ".((int) $this->id);
		$sql .= " AND value < 0";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$found = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $found ? 1 : 0;
	}

	private function refuseIfStockMoved($errorKey = 'ErrorSupplierReturnStockLocked')
	{
		$hasMovements = $this->hasStockMovements();
		if ($hasMovements < 0) {
			return -1;
		}
		if ($hasMovements > 0) {
			$this->error = $errorKey;
			return -1;
		}
		return 1;
	}

	private function validateSupplier()
	{
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
			$this->error = 'ErrorSupplierReturnInvalidSupplier';
			return -1;
		}
		return 1;
	}

	private function validateOrigins()
	{
		if ((int) $this->fk_supplier_order > 0) {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."commande_fournisseur";
			$sql .= " WHERE rowid = ".((int) $this->fk_supplier_order);
			$sql .= " AND fk_soc = ".((int) $this->fk_soc_supplier);
			$sql .= " AND entity = ".((int) $this->entity);
			$resql = $this->db->query($sql);
			if (!$resql || !$this->db->fetch_object($resql)) {
				if ($resql) $this->db->free($resql);
				$this->error = 'ErrorSupplierReturnOrderMismatch';
				return -1;
			}
			$this->db->free($resql);
		}
		if ((int) $this->fk_reception > 0) {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."reception";
			$sql .= " WHERE rowid = ".((int) $this->fk_reception);
			$sql .= " AND fk_soc = ".((int) $this->fk_soc_supplier);
			$sql .= " AND entity = ".((int) $this->entity);
			$resql = $this->db->query($sql);
			if (!$resql || !$this->db->fetch_object($resql)) {
				if ($resql) $this->db->free($resql);
				$this->error = 'ErrorSupplierReturnReceptionMismatch';
				return -1;
			}
			$this->db->free($resql);
		}
		return 1;
	}

	public function create($user, $notrigger = 0)
	{
		global $conf;
		$this->entity = (int) $conf->entity;
		if ($this->validateSupplier() < 0 || $this->validateOrigins() < 0) {
			return -1;
		}
		if (trim((string) $this->reason) === '') {
			$this->error = 'ErrorSupplierReturnReasonRequired';
			return -1;
		}

		$now = dol_now();
		$this->date_creation = $now;
		$this->fk_user_creat = (int) $user->id;
		$this->fk_user_modif = (int) $user->id;
		$this->status = self::STATUS_DRAFT;
		$provisionalRef = substr(str_replace('.', '', uniqid('PROV-SRET-', true)), 0, 50);

		$this->db->begin();
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."svc_supplier_return";
		$sql .= " (ref, entity, fk_soc_supplier, fk_supplier_order, fk_reception, fk_supplier_invoice,";
		$sql .= " supplier_return_ref, reason, status, fk_warehouse_source, outbound_carrier, outbound_tracking, outbound_tracking_url,";
		$sql .= " note_private, fk_user_creat, fk_user_modif, date_creation, model_pdf, last_main_doc) VALUES (";
		$sql .= "'".$this->db->escape($provisionalRef)."'";
		$sql .= ", ".((int) $this->entity);
		$sql .= ", ".((int) $this->fk_soc_supplier);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_supplier_order);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_reception);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_supplier_invoice);
		$sql .= ", ".$this->sqlStringOrNull($this->supplier_return_ref);
		$sql .= ", ".$this->sqlStringOrNull($this->reason);
		$sql .= ", '".self::STATUS_DRAFT."'";
		$sql .= ", ".$this->sqlIntOrNull($this->fk_warehouse_source);
		$sql .= ", ".$this->sqlStringOrNull($this->outbound_carrier);
		$sql .= ", ".$this->sqlStringOrNull($this->outbound_tracking);
		$sql .= ", ".$this->sqlStringOrNull($this->outbound_tracking_url);
		$sql .= ", ".$this->sqlStringOrNull($this->note_private);
		$sql .= ", ".((int) $this->fk_user_creat);
		$sql .= ", ".((int) $this->fk_user_modif);
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ", ".$this->sqlStringOrNull($this->model_pdf);
		$sql .= ", ".$this->sqlStringOrNull($this->last_main_doc);
		$sql .= ")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'svc_supplier_return');
		$this->ref = 'SRET-'.dol_print_date($now, '%Y', 'tzserver').'-'.sprintf('%06d', $this->id);
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return SET ref = '".$this->db->escape($this->ref)."' WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql) || $this->logEvent('CREATE', '', self::STATUS_DRAFT, '', $user) < 0) {
			$this->error = $this->error ?: $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		$this->socid = (int) $this->fk_soc_supplier;
		return $this->id;
	}

	public function fetch($id, $ref = '')
	{
		global $conf;
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."svc_supplier_return";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		if ((int) $id > 0) {
			$sql .= " AND rowid = ".((int) $id);
		} elseif ($ref !== '') {
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
		if (!$obj) return 0;

		foreach (array('rowid','entity','fk_soc_supplier','fk_supplier_order','fk_reception','fk_supplier_invoice','fk_warehouse_source','fk_user_creat','fk_user_modif') as $field) {
			$target = $field === 'rowid' ? 'id' : $field;
			$this->{$target} = (int) $obj->{$field};
		}
		foreach (array('ref','supplier_return_ref','reason','status','outbound_carrier','outbound_tracking','outbound_tracking_url','note_private','model_pdf','last_main_doc') as $field) {
			$this->{$field} = (string) $obj->{$field};
		}
		if (empty($this->model_pdf)) {
			$this->model_pdf = 'supplierreturn_standard';
		}
		foreach (array('date_authorized','date_shipped','date_closed','date_creation') as $field) {
			$this->{$field} = !empty($obj->{$field}) ? $this->db->jdate($obj->{$field}) : null;
		}
		$this->socid = (int) $this->fk_soc_supplier;
		$this->fetchLines();
		return 1;
	}

	public function fetchLines()
	{
		$this->lines = array();
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."svc_supplier_return_line";
		$sql .= " WHERE fk_supplier_return = ".((int) $this->id);
		$sql .= " ORDER BY rang ASC, rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$line = new SvcSupplierReturnLine($this->db);
			if ($line->fetch((int) $obj->rowid) > 0) {
				$this->lines[] = $line;
			}
		}
		$this->db->free($resql);
		return 1;
	}

	public function update($user, $notrigger = 0)
	{
		if (!in_array($this->status, array(self::STATUS_DRAFT, self::STATUS_AUTHORIZED), true)) {
			$this->error = 'ErrorSupplierReturnNotEditable';
			return -1;
		}
		if ($this->refuseIfStockMoved() < 0) {
			return -1;
		}
		if ($this->validateSupplier() < 0 || $this->validateOrigins() < 0) {
			return -1;
		}
		if (trim((string) $this->reason) === '') {
			$this->error = 'ErrorSupplierReturnReasonRequired';
			return -1;
		}

		$this->fk_user_modif = (int) $user->id;
		$expectedStatus = $this->status;
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return SET";
		$sql .= " fk_soc_supplier = ".((int) $this->fk_soc_supplier);
		$sql .= ", fk_supplier_order = ".$this->sqlIntOrNull($this->fk_supplier_order);
		$sql .= ", fk_reception = ".$this->sqlIntOrNull($this->fk_reception);
		$sql .= ", fk_supplier_invoice = ".$this->sqlIntOrNull($this->fk_supplier_invoice);
		$sql .= ", supplier_return_ref = ".$this->sqlStringOrNull($this->supplier_return_ref);
		$sql .= ", reason = ".$this->sqlStringOrNull($this->reason);
		$sql .= ", fk_warehouse_source = ".$this->sqlIntOrNull($this->fk_warehouse_source);
		$sql .= ", outbound_carrier = ".$this->sqlStringOrNull($this->outbound_carrier);
		$sql .= ", outbound_tracking = ".$this->sqlStringOrNull($this->outbound_tracking);
		$sql .= ", outbound_tracking_url = ".$this->sqlStringOrNull($this->outbound_tracking_url);
		$sql .= ", note_private = ".$this->sqlStringOrNull($this->note_private);
		$sql .= ", fk_user_modif = ".((int) $this->fk_user_modif);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $this->entity);
		$sql .= " AND status = '".$this->db->escape($expectedStatus)."'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		// affected_rows may be zero for a legitimate no-op update. Distinguish
		// that from a concurrent workflow transition before reporting success.
		if ($this->db->affected_rows($resql) < 1) {
			$sql = "SELECT status FROM ".MAIN_DB_PREFIX."svc_supplier_return";
			$sql .= " WHERE rowid = ".((int) $this->id);
			$sql .= " AND entity = ".((int) $this->entity);
			$resql = $this->db->query($sql);
			if (!$resql || !($current = $this->db->fetch_object($resql))) {
				if ($resql) {
					$this->db->free($resql);
				}
				$this->error = $resql ? 'ErrorRecordNotFound' : $this->db->lasterror();
				return -1;
			}
			$this->db->free($resql);
			if ((string) $current->status !== (string) $expectedStatus) {
				$this->error = 'ErrorSupplierReturnConcurrentUpdate';
				return -1;
			}
		}

		$this->socid = (int) $this->fk_soc_supplier;
		return 1;
	}

	/**
	 * Revalidate every line against current stock and supplier provenance.
	 * This also protects legacy draft rows created before line-level validation
	 * was introduced.
	 *
	 * @return int 1 if all lines are valid, -1 otherwise
	 */
	private function validateReturnLines()
	{
		if ($this->fetchLines() < 0) {
			return -1;
		}
		if (empty($this->lines)) {
			$this->error = 'ErrorSupplierReturnNeedsLines';
			return -1;
		}

		$stock = new WarrantySvcStockService($this->db);
		$movementIds = array();
		foreach ($this->lines as $line) {
			$source = $stock->validateSupplierReturnLine(
				(int) $this->fk_soc_supplier,
				(int) $this->fk_warehouse_source,
				(int) $this->id,
				(int) $line->id,
				(int) $line->fk_product,
				(float) $line->qty,
				(string) $line->batch
			);
			if ($source === false) {
				$this->error = $stock->error;
				$this->errors = $stock->errors;
				return -1;
			}

			if ((int) $line->fk_supplier_order_line !== (int) $source['fk_supplier_order_line']
				|| (int) $line->fk_reception_line !== (int) $source['fk_reception_line']
			) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return_line SET";
				$sql .= " fk_supplier_order_line = ".((int) $source['fk_supplier_order_line']);
				$sql .= ", fk_reception_line = ".((int) $source['fk_reception_line']);
				$sql .= " WHERE rowid = ".((int) $line->id);
				$sql .= " AND fk_supplier_return = ".((int) $this->id);
				if (!$this->db->query($sql)) {
					$this->error = $this->db->lasterror();
					return -1;
				}
				$line->fk_supplier_order_line = (int) $source['fk_supplier_order_line'];
				$line->fk_reception_line = (int) $source['fk_reception_line'];
			}
		}

		return 1;
	}

	/**
	 * Ensure the standard Dolibarr element_element link exists from each
	 * returned Product to this Supplier Return.
	 *
	 * @param User $user Current user
	 * @param int $notrigger 1 to skip OBJECT_LINK_INSERT trigger during backfill
	 * @return int 1 on success, -1 on error
	 */
	public function ensureProductLinks($user, $notrigger = 0)
	{
		if ((int) $this->id <= 0) {
			return -1;
		}
		if (empty($this->lines)) {
			$this->fetchLines();
		}

		$productIds = array();
		foreach ($this->lines as $line) {
			if ((int) $line->fk_product > 0) {
				$productIds[(int) $line->fk_product] = (int) $line->fk_product;
			}
		}

		foreach ($productIds as $productId) {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."element_element";
			$sql .= " WHERE fk_source = ".((int) $productId);
			$sql .= " AND sourcetype = 'product'";
			$sql .= " AND fk_target = ".((int) $this->id);
			$sql .= " AND targettype = 'warrantysvc_svcsupplierreturn'";
			$sql .= $this->db->plimit(1);
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$exists = (bool) $this->db->fetch_object($resql);
			$this->db->free($resql);
			if ($exists) {
				continue;
			}

			$result = $this->add_object_linked('product', $productId, $user, $notrigger);
			if ($result <= 0) {
				if (empty($this->error)) {
					$this->error = 'ErrorSupplierReturnProductLinkFailed';
				}
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Add one native Dolibarr Agenda event per Product for a shipment or
	 * compensating stock reversal. ref_ext makes the operation idempotent.
	 *
	 * @param User $user Event owner/author
	 * @param string $eventType 'ship' or 'reverse'
	 * @param int|null $eventDate Event timestamp
	 * @return int 1 on success, -1 on error
	 */
	public function ensureProductAgendaEvents($user, $eventType, $eventDate = null)
	{
		global $langs, $conf;

		if ((int) $this->id <= 0 || !in_array($eventType, array('ship', 'reverse'), true)) {
			return -1;
		}
		if (!isModEnabled('agenda')) {
			return 1;
		}
		if (empty($this->lines)) {
			$this->fetchLines();
		}
		$langs->load('warrantysvc@warrantysvc');

		$groups = array();
		foreach ($this->lines as $line) {
			$productId = (int) $line->fk_product;
			if ($productId <= 0) {
				continue;
			}
			if (!isset($groups[$productId])) {
				$groups[$productId] = array(
					'qty' => 0.0,
					'batches' => array(),
					'movements' => array(),
				);
			}
			$groups[$productId]['qty'] += (float) $line->qty;
			if (trim((string) $line->batch) !== '') {
				$groups[$productId]['batches'][trim((string) $line->batch)] = trim((string) $line->batch);
			}
			$movementId = ($eventType === 'reverse')
				? (int) $line->fk_stock_movement_reversal
				: (int) $line->fk_stock_movement_out;
			if ($movementId > 0) {
				$groups[$productId]['movements'][$movementId] = '#'.$movementId;
			}
		}

		foreach ($groups as $productId => $info) {
			$refExt = 'wsvcsret:'.((int) $this->id).':'.$eventType.':'.((int) $productId);
			$sql = "SELECT id FROM ".MAIN_DB_PREFIX."actioncomm";
			$sql .= " WHERE entity = ".((int) $conf->entity);
			$sql .= " AND ref_ext = '".$this->db->escape($refExt)."'";
			$sql .= $this->db->plimit(1);
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$exists = (bool) $this->db->fetch_object($resql);
			$this->db->free($resql);
			if ($exists) {
				continue;
			}

			$event = new ActionComm($this->db);
			$event->type_code = 'AC_OTH_AUTO';
			$event->code = ($eventType === 'reverse')
				? 'AC_WSVC_SUPPLIER_RETURN_REVERSE'
				: 'AC_WSVC_SUPPLIER_RETURN_SHIP';
			$event->label = $langs->transnoentitiesnoconv(
				$eventType === 'reverse' ? 'SupplierReturnProductAgendaReversed' : 'SupplierReturnProductAgendaShipped',
				$this->ref
			);
			$event->datep = !empty($eventDate) ? $eventDate : dol_now();
			$event->userownerid = (int) $user->id;
			$event->socid = (int) $this->fk_soc_supplier;
			$event->elementtype = 'product';
			$event->elementid = (int) $productId;
			$event->ref_ext = $refExt;
			$event->percentage = 100;

			$batches = !empty($info['batches']) ? implode(', ', array_values($info['batches'])) : '—';
			$movements = !empty($info['movements']) ? implode(', ', array_values($info['movements'])) : '—';
			$event->note_private = $langs->transnoentitiesnoconv(
				$eventType === 'reverse' ? 'SupplierReturnProductAgendaReverseNote' : 'SupplierReturnProductAgendaShipNote',
				$this->ref,
				price($info['qty'], 0, $langs, 0, 0, -1),
				$batches,
				$movements
			);

			if ($event->create($user, 1) <= 0) {
				$this->error = !empty($event->error) ? $event->error : 'ErrorSupplierReturnAgendaEventFailed';
				$this->errors = !empty($event->errors) ? $event->errors : array();
				return -1;
			}
		}

		return 1;
	}

	public function authorize($user, $note = '')
	{
		if ($this->status !== self::STATUS_DRAFT) {
			$this->error = 'ErrorSupplierReturnInvalidTransition';
			return -1;
		}
		if ((int) $this->fk_warehouse_source <= 0) {
			$this->error = 'ErrorSupplierReturnWarehouseRequired';
			return -1;
		}
		if ($this->validateReturnLines() < 0) {
			return -1;
		}
		return $this->setSimpleStatus(self::STATUS_AUTHORIZED, $user, $note);
	}

	public function cancel($user, $note = '')
	{
		if (!in_array($this->status, array(self::STATUS_DRAFT, self::STATUS_AUTHORIZED), true)) {
			$this->error = 'ErrorSupplierReturnInvalidTransition';
			return -1;
		}
		if ($this->refuseIfStockMoved() < 0) {
			return -1;
		}
		return $this->setSimpleStatus(self::STATUS_CANCELLED, $user, $note);
	}

	public function reopen($user, $note = '')
	{
		if ($this->status !== self::STATUS_CANCELLED) {
			$this->error = 'ErrorSupplierReturnInvalidTransition';
			return -1;
		}
		if ($this->refuseIfStockMoved() < 0) {
			return -1;
		}
		return $this->setSimpleStatus(self::STATUS_DRAFT, $user, $note);
	}

	private function setSimpleStatus($newStatus, $user, $note = '', $eventCode = 'STATUS')
	{
		$oldStatus = $this->status;
		$sets = array(
			"status = '".$this->db->escape($newStatus)."'",
			"fk_user_modif = ".((int) $user->id),
		);
		$now = dol_now();
		if ($newStatus === self::STATUS_AUTHORIZED) {
			$sets[] = "date_authorized = '".$this->db->idate($now)."'";
			$this->date_authorized = $now;
		} elseif ($newStatus === self::STATUS_DRAFT) {
			$sets[] = "date_authorized = NULL";
			$this->date_authorized = null;
		} elseif ($newStatus === self::STATUS_CLOSED) {
			$sets[] = "date_closed = '".$this->db->idate($now)."'";
			$this->date_closed = $now;
		}
		$this->db->begin();
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return SET ".implode(', ', $sets);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND status = '".$this->db->escape($oldStatus)."'";
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->affected_rows($resql) < 1) {
			$this->error = $resql ? 'ErrorSupplierReturnConcurrentUpdate' : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->status = $newStatus;
		if ($this->logEvent($eventCode, $oldStatus, $newStatus, $note, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Ship all lines. Per-line movement IDs make retries safe after partial failure.
	 *
	 * @return int 1 if all lines shipped, -1 otherwise
	 */
	public function ship($user, $note = '')
	{
		global $langs;

		if ($this->status !== self::STATUS_AUTHORIZED) {
			$this->error = 'ErrorSupplierReturnInvalidTransition';
			return -1;
		}
		if ((int) $this->fk_warehouse_source <= 0) {
			$this->error = 'ErrorSupplierReturnWarehouseRequired';
			return -1;
		}

		/*
		 * Serialize shipment against edits/cancel/delete and keep all line
		 * movements plus the workflow transition in one outer transaction.
		 * MouvementStock uses nested DoliDB transactions, so its commits are
		 * deferred until this outer transaction commits.
		 */
		$this->db->begin();

		$sql = "SELECT status FROM ".MAIN_DB_PREFIX."svc_supplier_return";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $this->entity);
		if ($this->db->type !== 'sqlite3') {
			$sql .= " FOR UPDATE";
		}
		$resql = $this->db->query($sql);
		if (!$resql || !($current = $this->db->fetch_object($resql))) {
			if ($resql) {
				$this->db->free($resql);
			}
			$this->error = $resql ? 'ErrorRecordNotFound' : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->free($resql);
		if ((string) $current->status !== self::STATUS_AUTHORIZED) {
			$this->error = 'ErrorSupplierReturnConcurrentUpdate';
			$this->db->rollback();
			return -1;
		}

		if ($this->validateReturnLines() < 0) {
			$this->db->rollback();
			return -1;
		}

		$stock = new WarrantySvcStockService($this->db);
		$movementIds = array();
		foreach ($this->lines as $line) {
			if (!empty($line->fk_stock_movement_out)) {
				$movementIds[(int) $line->fk_stock_movement_out] = '#'.((int) $line->fk_stock_movement_out);
				continue;
			}
			$movementId = $stock->createOutboundMovement(
				$user,
				self::STOCK_ORIGIN_TYPE,
				(int) $this->id,
				(int) $line->id,
				(int) $line->fk_product,
				(int) $this->fk_warehouse_source,
				(float) $line->qty,
				(string) $line->batch,
				'Supplier return '.$this->ref
			);
			if ($movementId <= 0) {
				$this->error = $stock->error;
				$this->errors = $stock->errors;
				$this->db->rollback();
				return -1;
			}

			$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return_line";
			$sql .= " SET fk_stock_movement_out = ".((int) $movementId);
			$sql .= " WHERE rowid = ".((int) $line->id);
			$sql .= " AND fk_supplier_return = ".((int) $this->id);
			$sql .= " AND (fk_stock_movement_out IS NULL OR fk_stock_movement_out = 0)";
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			if ($this->db->affected_rows($resql) < 1) {
				$this->error = 'ErrorSupplierReturnConcurrentUpdate';
				$this->db->rollback();
				return -1;
			}
			$line->fk_stock_movement_out = $movementId;
			$movementIds[(int) $movementId] = '#'.((int) $movementId);
		}

		$oldStatus = $this->status;
		$now = dol_now();
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return SET";
		$sql .= " status = '".self::STATUS_SHIPPED."'";
		$sql .= ", date_shipped = '".$this->db->idate($now)."'";
		$sql .= ", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $this->entity);
		$sql .= " AND status = '".self::STATUS_AUTHORIZED."'";
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->affected_rows($resql) < 1) {
			$this->error = $resql ? 'ErrorSupplierReturnConcurrentUpdate' : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->status = self::STATUS_SHIPPED;
		$this->date_shipped = $now;
		if ($this->logEvent('STATUS', $oldStatus, self::STATUS_SHIPPED, $note, $user) < 0) {
			$this->db->rollback();
			return -1;
		}

		$movementNote = $langs->transnoentitiesnoconv(
			'SupplierReturnStockOutAuditNote',
			!empty($movementIds) ? implode(', ', array_values($movementIds)) : '—'
		);
		if ($this->logEvent('STOCKOUT', self::STATUS_SHIPPED, self::STATUS_SHIPPED, $movementNote, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($this->ensureProductLinks($user) < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($this->ensureProductAgendaEvents($user, 'ship', $now) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Reverse a physically shipped Supplier Return without destroying its audit
	 * trail. Each outbound movement gets a native compensating movement and the
	 * document becomes Reversed.
	 *
	 * @param User $user Current user
	 * @param string $note Audit note
	 * @return int 1 on success, -1 on error
	 */
	public function reverseShipment($user, $note = '')
	{
		global $langs;

		if (!in_array($this->status, array(self::STATUS_SHIPPED, self::STATUS_CLOSED), true)) {
			$this->error = 'ErrorSupplierReturnInvalidTransition';
			return -1;
		}

		$this->db->begin();

		$sql = "SELECT status FROM ".MAIN_DB_PREFIX."svc_supplier_return";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $this->entity);
		if ($this->db->type !== 'sqlite3') {
			$sql .= " FOR UPDATE";
		}
		$resql = $this->db->query($sql);
		if (!$resql || !($current = $this->db->fetch_object($resql))) {
			if ($resql) {
				$this->db->free($resql);
			}
			$this->error = $resql ? 'ErrorRecordNotFound' : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->free($resql);
		if (!in_array((string) $current->status, array(self::STATUS_SHIPPED, self::STATUS_CLOSED), true)) {
			$this->error = 'ErrorSupplierReturnConcurrentUpdate';
			$this->db->rollback();
			return -1;
		}

		if ($this->fetchLines() < 0 || empty($this->lines)) {
			$this->error = 'ErrorSupplierReturnNeedsLines';
			$this->db->rollback();
			return -1;
		}

		$stock = new WarrantySvcStockService($this->db);
		$reversalIds = array();
		foreach ($this->lines as $line) {
			if ((int) $line->fk_stock_movement_out <= 0) {
				$this->error = 'ErrorSupplierReturnMissingStockMovement';
				$this->db->rollback();
				return -1;
			}

			if ((int) $line->fk_stock_movement_reversal > 0) {
				$reversalIds[(int) $line->fk_stock_movement_reversal] = '#'.((int) $line->fk_stock_movement_reversal);
				continue;
			}

			$reversalId = $stock->reverseOutboundMovement(
				$user,
				(int) $line->fk_stock_movement_out,
				array(self::STOCK_ORIGIN_TYPE, self::LEGACY_STOCK_ORIGIN_TYPE),
				(int) $this->id
			);
			if ($reversalId <= 0) {
				$this->error = $stock->error;
				$this->errors = $stock->errors;
				$this->db->rollback();
				return -1;
			}

			$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return_line";
			$sql .= " SET fk_stock_movement_reversal = ".((int) $reversalId);
			$sql .= " WHERE rowid = ".((int) $line->id);
			$sql .= " AND fk_supplier_return = ".((int) $this->id);
			$sql .= " AND (fk_stock_movement_reversal IS NULL OR fk_stock_movement_reversal = 0)";
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
			if ($this->db->affected_rows($resql) < 1) {
				$this->error = 'ErrorSupplierReturnConcurrentUpdate';
				$this->db->rollback();
				return -1;
			}

			$line->fk_stock_movement_reversal = $reversalId;
			$reversalIds[(int) $reversalId] = '#'.((int) $reversalId);
		}

		$oldStatus = $this->status;
		$now = dol_now();
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return SET";
		$sql .= " status = '".self::STATUS_REVERSED."'";
		$sql .= ", date_closed = NULL";
		$sql .= ", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $this->entity);
		$sql .= " AND status IN ('".self::STATUS_SHIPPED."','".self::STATUS_CLOSED."')";
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->affected_rows($resql) < 1) {
			$this->error = $resql ? 'ErrorSupplierReturnConcurrentUpdate' : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		$this->status = self::STATUS_REVERSED;
		$this->date_closed = null;
		$auditNote = $langs->transnoentitiesnoconv(
			'SupplierReturnStockRestoreAuditNote',
			!empty($reversalIds) ? implode(', ', array_values($reversalIds)) : '—'
		);
		if ($note !== '') {
			$auditNote .= ' - '.$note;
		}
		if ($this->logEvent('REVERSE', $oldStatus, self::STATUS_REVERSED, $auditNote, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($this->ensureProductLinks($user) < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($this->ensureProductAgendaEvents($user, 'reverse', $now) < 0) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

	public function close($user, $note = '')
	{
		if ($this->status !== self::STATUS_SHIPPED) {
			$this->error = 'ErrorSupplierReturnInvalidTransition';
			return -1;
		}
		return $this->setSimpleStatus(self::STATUS_CLOSED, $user, $note);
	}

	public function rollbackStatus($user, $note = '')
	{
		if ($this->status === self::STATUS_AUTHORIZED) {
			if ($this->refuseIfStockMoved('ErrorSupplierReturnRollbackStockMovement') < 0) {
				return -1;
			}
			return $this->setSimpleStatus(self::STATUS_DRAFT, $user, $note, 'ROLLBACK');
		}

		if ($this->status === self::STATUS_CLOSED) {
			$oldStatus = $this->status;
			$this->db->begin();
			$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return";
			$sql .= " SET status = '".self::STATUS_SHIPPED."', date_closed = NULL, fk_user_modif = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $this->id);
			$sql .= " AND entity = ".((int) $this->entity);
			$sql .= " AND status = '".self::STATUS_CLOSED."'";
			$resql = $this->db->query($sql);
			if (!$resql || $this->db->affected_rows($resql) < 1) {
				$this->error = $resql ? 'ErrorSupplierReturnConcurrentUpdate' : $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}

			$this->status = self::STATUS_SHIPPED;
			$this->date_closed = null;
			if ($this->logEvent('ROLLBACK', $oldStatus, self::STATUS_SHIPPED, $note, $user) < 0) {
				$this->db->rollback();
				return -1;
			}
			$this->db->commit();
			return 1;
		}

		if ($this->status === self::STATUS_SHIPPED) {
			$this->error = 'ErrorSupplierReturnRollbackStockMovement';
			return -1;
		}

		if ($this->status === self::STATUS_CANCELLED) {
			return $this->reopen($user, $note);
		}

		$this->error = 'ErrorSupplierReturnNoPreviousStatus';
		return -1;
	}

	public function delete($user, $notrigger = 0)
	{
		$this->db->begin();

		$sql = "SELECT status FROM ".MAIN_DB_PREFIX."svc_supplier_return";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND entity = ".((int) $this->entity);
		if ($this->db->type !== 'sqlite3') {
			$sql .= " FOR UPDATE";
		}
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->fetch_object($resql)) {
			if ($resql) {
				$this->db->free($resql);
			}
			$this->error = $resql ? 'ErrorRecordNotFound' : $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->free($resql);

		$hasMovements = $this->hasStockMovements();
		if ($hasMovements < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($hasMovements > 0) {
			if ($this->status !== self::STATUS_REVERSED) {
				$this->error = 'ErrorSupplierReturnDeleteStockMovements';
				$this->db->rollback();
				return -1;
			}

			// A physically shipped return may be deleted only after every outbound
			// stock movement has a recorded compensating movement. This mirrors
			// Dolibarr's native document deletion pattern while keeping the stock
			// movement and Product Agenda audit trail intact.
			if ($this->fetchLines() < 0) {
				$this->db->rollback();
				return -1;
			}
			foreach ($this->lines as $line) {
				if ((int) $line->fk_stock_movement_out <= 0 || (int) $line->fk_stock_movement_reversal <= 0) {
					$this->error = 'ErrorSupplierReturnDeleteNeedsReversal';
					$this->db->rollback();
					return -1;
				}

				$sql = "SELECT rowid, value FROM ".MAIN_DB_PREFIX."stock_mouvement";
				$sql .= " WHERE rowid = ".((int) $line->fk_stock_movement_reversal);
				$sql .= " AND fk_product = ".((int) $line->fk_product);
				$sql .= " AND fk_entrepot = ".((int) $this->fk_warehouse_source);
				$sql .= $this->db->plimit(1);
				$resql = $this->db->query($sql);
				if (!$resql) {
					$this->error = $this->db->lasterror();
					$this->db->rollback();
					return -1;
				}
				$reversal = $this->db->fetch_object($resql);
				$this->db->free($resql);
				if (!$reversal || (float) $reversal->value <= 0) {
					$this->error = 'ErrorSupplierReturnDeleteNeedsReversal';
					$this->db->rollback();
					return -1;
				}
			}
		}

		$targetType = $this->getElementType();
		foreach (array(
			"DELETE FROM ".MAIN_DB_PREFIX."element_element WHERE fk_target = ".((int) $this->id)." AND targettype = '".$this->db->escape($targetType)."'",
			"DELETE FROM ".MAIN_DB_PREFIX."element_element WHERE fk_source = ".((int) $this->id)." AND sourcetype = '".$this->db->escape($targetType)."'",
			"DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_return_log WHERE fk_supplier_return = ".((int) $this->id),
			"DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_return_line WHERE fk_supplier_return = ".((int) $this->id),
			"DELETE FROM ".MAIN_DB_PREFIX."element_contact WHERE element_id = ".((int) $this->id)." AND fk_c_type_contact IN (SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_contact WHERE element = 'svcsupplierreturn')",
			"DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_return WHERE rowid = ".((int) $this->id)." AND entity = ".((int) $this->entity),
		) as $sql) {
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return 1;
	}

	public function logEvent($eventCode, $oldStatus, $newStatus, $note, $user, $dateEvent = null)
	{
		global $conf;
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."svc_supplier_return_log";
		$sql .= " (entity, fk_supplier_return, event_code, old_status, new_status, note, date_event, fk_user) VALUES (";
		$sql .= ((int) $conf->entity).", ".((int) $this->id);
		$sql .= ", '".$this->db->escape($eventCode)."'";
		$sql .= ", ".$this->sqlStringOrNull($oldStatus);
		$sql .= ", ".$this->sqlStringOrNull($newStatus);
		$sql .= ", ".$this->sqlStringOrNull($note);
		$sql .= ", '".$this->db->idate(!empty($dateEvent) ? $dateEvent : dol_now())."'";
		$sql .= ", ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	public function fetchHistory()
	{
		$out = array();
		$sql = "SELECT l.*, u.login, u.firstname, u.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return_log l";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = l.fk_user";
		$sql .= " WHERE l.fk_supplier_return = ".((int) $this->id);
		$sql .= " ORDER BY l.date_event ASC, l.rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) return $out;
		while ($obj = $this->db->fetch_object($resql)) {
			$out[] = array(
				'event_code'=>(string) $obj->event_code,
				'old_status'=>(string) $obj->old_status,
				'new_status'=>(string) $obj->new_status,
				'note'=>(string) $obj->note,
				'date_event'=>!empty($obj->date_event) ? $this->db->jdate($obj->date_event) : null,
				'user_name'=>trim((string) $obj->firstname.' '.(string) $obj->lastname) ?: (string) $obj->login,
			);
		}
		$this->db->free($resql);
		return $out;
	}


	/**
	 * Generate a Supplier Return document through Dolibarr's standard pipeline.
	 */
	public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
		if (empty($modele)) {
			$modele = !empty($this->model_pdf) ? $this->model_pdf : 'supplierreturn_standard';
		}
		return $this->commonGenerateDocument(
			'core/modules/warrantysvc/',
			$modele,
			$outputlangs,
			$hidedetails,
			$hidedesc,
			$hideref,
			$moreparams
		);
	}

	public function getNomUrl($withpicto = 0)
	{
		$url = DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_card.php?id='.$this->id;
		$label = dol_escape_htmltag($this->ref);
		if ($withpicto) $label = img_picto('', $this->picto, 'class="pictofixedwidth"').$label;
		return '<a href="'.$url.'">'.$label.'</a>';
	}

	public function getLibStatut($mode = 0)
	{
		global $langs;
		$langs->load('warrantysvc@warrantysvc');
		$map = array(
			self::STATUS_DRAFT=>array('SupplierReturnStatusDraft','status0'),
			self::STATUS_AUTHORIZED=>array('SupplierReturnStatusAuthorized','status1'),
			self::STATUS_SHIPPED=>array('SupplierReturnStatusShipped','status4'),
			self::STATUS_CLOSED=>array('SupplierReturnStatusClosed','status6'),
			self::STATUS_CANCELLED=>array('SupplierReturnStatusCancelled','status9'),
			self::STATUS_REVERSED=>array('SupplierReturnStatusReversed','status9'),
		);
		$item = isset($map[$this->status]) ? $map[$this->status] : array($this->status,'status0');
		$label = $langs->trans($item[0]);
		return $mode == 1 ? $label : '<span class="badge '.$item[1].'">'.$label.'</span>';
	}
}
