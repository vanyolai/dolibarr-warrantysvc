<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    class/svcsupplierreturnline.class.php
 * \ingroup warrantysvc
 * \brief   Line of a supplier return document
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobjectline.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/warrantysvcstockservice.class.php';

class SvcSupplierReturnLine extends CommonObjectLine
{
	public $element = 'svcsupplierreturnline';
	public $table_element = 'svc_supplier_return_line';

	public $fk_supplier_return;
	public $fk_product;
	public $qty = 1;
	public $batch;
	public $fk_supplier_order_line;
	public $fk_reception_line;
	public $reason;
	public $fk_stock_movement_out;
	public $fk_stock_movement_reversal;
	public $rang = 0;

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
	 * Lock and validate the parent Supplier Return before mutating a line.
	 *
	 * This prevents a line edit/add/delete from racing the shipment action.
	 * Once any physical movement exists the complete document is frozen; the
	 * only safe operation is to retry/finish the shipment workflow.
	 *
	 * Caller must have an open transaction.
	 *
	 * @return int 1 if editable, -1 otherwise
	 */
	private function validateEditableParent()
	{
		global $conf;

		$sql = "SELECT status FROM ".MAIN_DB_PREFIX."svc_supplier_return";
		$sql .= " WHERE rowid = ".((int) $this->fk_supplier_return);
		$sql .= " AND entity = ".((int) $conf->entity);
		if ($this->db->type !== 'sqlite3') {
			$sql .= " FOR UPDATE";
		}
		$resql = $this->db->query($sql);
		if (!$resql || !($parent = $this->db->fetch_object($resql))) {
			if ($resql) {
				$this->db->free($resql);
			}
			$this->error = $resql ? 'ErrorSupplierReturnRequired' : $this->db->lasterror();
			return -1;
		}
		$this->db->free($resql);

		if (!in_array((string) $parent->status, array('draft', 'authorized'), true)) {
			$this->error = 'ErrorSupplierReturnNotEditable';
			return -1;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."svc_supplier_return_line";
		$sql .= " WHERE fk_supplier_return = ".((int) $this->fk_supplier_return);
		$sql .= " AND fk_stock_movement_out IS NOT NULL AND fk_stock_movement_out > 0";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$hasLinkedMovement = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);
		if ($hasLinkedMovement) {
			$this->error = 'ErrorSupplierReturnStockLocked';
			return -1;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."stock_mouvement";
		$sql .= " WHERE origintype IN ('SvcSupplierReturn@warrantysvc','svcsupplierreturn')";
		$sql .= " AND fk_origin = ".((int) $this->fk_supplier_return);
		$sql .= " AND value < 0";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$hasOriginMovement = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);
		if ($hasOriginMovement) {
			$this->error = 'ErrorSupplierReturnStockLocked';
			return -1;
		}

		return 1;
	}

	/**
	 * Apply Supplier Return business rules after the parent row has been locked.
	 *
	 * @return int 1 when valid, -1 otherwise
	 */
	private function validateSupplierStockSource()
	{
		global $conf;

		$sql = "SELECT fk_soc_supplier, fk_warehouse_source, entity";
		$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return";
		$sql .= " WHERE rowid = ".((int) $this->fk_supplier_return);
		$sql .= " AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql || !($parent = $this->db->fetch_object($resql))) {
			if ($resql) {
				$this->db->free($resql);
			}
			$this->error = $resql ? 'ErrorSupplierReturnRequired' : $this->db->lasterror();
			return -1;
		}
		$this->db->free($resql);

		if ((int) $parent->fk_warehouse_source <= 0) {
			$this->error = 'ErrorSupplierReturnWarehouseRequired';
			return -1;
		}

		$product = new Product($this->db);
		if ($product->fetch((int) $this->fk_product) <= 0) {
			$this->error = 'ErrorProductNotFound';
			return -1;
		}
		$hasBatch = method_exists($product, 'hasbatch') ? (bool) $product->hasbatch() : !empty($product->status_batch);
		if (!$hasBatch) {
			$this->batch = '';
		}

		$stock = new WarrantySvcStockService($this->db);
		$source = $stock->validateSupplierReturnLine(
			(int) $parent->fk_soc_supplier,
			(int) $parent->fk_warehouse_source,
			(int) $this->fk_supplier_return,
			(int) $this->id,
			(int) $this->fk_product,
			(float) $this->qty,
			(string) $this->batch
		);
		if ($source === false) {
			$this->error = $stock->error;
			$this->errors = $stock->errors;
			return -1;
		}

		$this->fk_supplier_order_line = (int) $source['fk_supplier_order_line'];
		$this->fk_reception_line = (int) $source['fk_reception_line'];

		return 1;
	}

	public function validateData()
	{
		if ((int) $this->fk_supplier_return <= 0) {
			$this->error = 'ErrorSupplierReturnRequired';
			return -1;
		}
		if ((int) $this->fk_product <= 0) {
			$this->error = 'ErrorProductRequired';
			return -1;
		}
		if ((float) $this->qty <= 0) {
			$this->error = 'ErrorSupplierReturnQtyRequired';
			return -1;
		}
		return 1;
	}

	public function create($user, $notrigger = 0)
	{
		if ($this->validateData() < 0) {
			return -1;
		}

		$this->db->begin();
		if ($this->validateEditableParent() < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($this->validateSupplierStockSource() < 0) {
			$this->db->rollback();
			return -1;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."svc_supplier_return_line";
		$sql .= " (fk_supplier_return, fk_product, qty, batch, fk_supplier_order_line, fk_reception_line, reason, fk_stock_movement_out, fk_stock_movement_reversal, rang) VALUES (";
		$sql .= ((int) $this->fk_supplier_return);
		$sql .= ", ".((int) $this->fk_product);
		$sql .= ", ".price2num((float) $this->qty, 'MU');
		$sql .= ", ".$this->sqlStringOrNull($this->batch);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_supplier_order_line);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_reception_line);
		$sql .= ", ".$this->sqlStringOrNull($this->reason);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_stock_movement_out);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_stock_movement_reversal);
		$sql .= ", ".((int) $this->rang);
		$sql .= ")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'svc_supplier_return_line');
		$this->db->commit();
		return $this->id;
	}

	public function fetch($id)
	{
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."svc_supplier_return_line WHERE rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) return 0;
		$this->id = (int) $obj->rowid;
		$this->fk_supplier_return = (int) $obj->fk_supplier_return;
		$this->fk_product = (int) $obj->fk_product;
		$this->qty = (float) $obj->qty;
		$this->batch = (string) $obj->batch;
		$this->fk_supplier_order_line = (int) $obj->fk_supplier_order_line;
		$this->fk_reception_line = (int) $obj->fk_reception_line;
		$this->reason = (string) $obj->reason;
		$this->fk_stock_movement_out = (int) $obj->fk_stock_movement_out;
		$this->fk_stock_movement_reversal = (int) $obj->fk_stock_movement_reversal;
		$this->rang = (int) $obj->rang;
		return 1;
	}

	public function update($user, $notrigger = 0)
	{
		if ($this->validateData() < 0) {
			return -1;
		}
		if (!empty($this->fk_stock_movement_out)) {
			$this->error = 'ErrorSupplierReturnLineAlreadyMoved';
			return -1;
		}

		$this->db->begin();
		if ($this->validateEditableParent() < 0) {
			$this->db->rollback();
			return -1;
		}
		if ($this->validateSupplierStockSource() < 0) {
			$this->db->rollback();
			return -1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return_line SET";
		$sql .= " fk_product = ".((int) $this->fk_product);
		$sql .= ", qty = ".price2num((float) $this->qty, 'MU');
		$sql .= ", batch = ".$this->sqlStringOrNull($this->batch);
		$sql .= ", fk_supplier_order_line = ".$this->sqlIntOrNull($this->fk_supplier_order_line);
		$sql .= ", fk_reception_line = ".$this->sqlIntOrNull($this->fk_reception_line);
		$sql .= ", reason = ".$this->sqlStringOrNull($this->reason);
		$sql .= ", rang = ".((int) $this->rang);
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND fk_supplier_return = ".((int) $this->fk_supplier_return);
		$sql .= " AND (fk_stock_movement_out IS NULL OR fk_stock_movement_out = 0)";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) < 1) {
			// A no-op update is valid only if the row still exists and is unmoved.
			$sql = "SELECT rowid, fk_stock_movement_out FROM ".MAIN_DB_PREFIX."svc_supplier_return_line";
			$sql .= " WHERE rowid = ".((int) $this->id);
			$sql .= " AND fk_supplier_return = ".((int) $this->fk_supplier_return);
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
			if (!empty($current->fk_stock_movement_out)) {
				$this->error = 'ErrorSupplierReturnLineAlreadyMoved';
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return 1;
	}

	public function delete($user, $notrigger = 0)
	{
		if (!empty($this->fk_stock_movement_out)) {
			$this->error = 'ErrorSupplierReturnLineAlreadyMoved';
			return -1;
		}

		$this->db->begin();
		if ($this->validateEditableParent() < 0) {
			$this->db->rollback();
			return -1;
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_return_line";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$sql .= " AND fk_supplier_return = ".((int) $this->fk_supplier_return);
		$sql .= " AND (fk_stock_movement_out IS NULL OR fk_stock_movement_out = 0)";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->db->affected_rows($resql) < 1) {
			$this->error = 'ErrorSupplierReturnLineAlreadyMoved';
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return 1;
	}

}
