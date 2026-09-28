<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    class/svcsupplierreturnline.class.php
 * \ingroup warrantysvc
 * \brief   Line of a supplier return document
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobjectline.class.php';

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
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."svc_supplier_return_line";
		$sql .= " (fk_supplier_return, fk_product, qty, batch, fk_supplier_order_line, fk_reception_line, reason, fk_stock_movement_out, rang) VALUES (";
		$sql .= ((int) $this->fk_supplier_return);
		$sql .= ", ".((int) $this->fk_product);
		$sql .= ", ".price2num((float) $this->qty, 'MU');
		$sql .= ", ".$this->sqlStringOrNull($this->batch);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_supplier_order_line);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_reception_line);
		$sql .= ", ".$this->sqlStringOrNull($this->reason);
		$sql .= ", ".$this->sqlIntOrNull($this->fk_stock_movement_out);
		$sql .= ", ".((int) $this->rang);
		$sql .= ")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'svc_supplier_return_line');
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
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_supplier_return_line SET";
		$sql .= " fk_product = ".((int) $this->fk_product);
		$sql .= ", qty = ".price2num((float) $this->qty, 'MU');
		$sql .= ", batch = ".$this->sqlStringOrNull($this->batch);
		$sql .= ", fk_supplier_order_line = ".$this->sqlIntOrNull($this->fk_supplier_order_line);
		$sql .= ", fk_reception_line = ".$this->sqlIntOrNull($this->fk_reception_line);
		$sql .= ", reason = ".$this->sqlStringOrNull($this->reason);
		$sql .= ", rang = ".((int) $this->rang);
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	public function delete($user, $notrigger = 0)
	{
		if (!empty($this->fk_stock_movement_out)) {
			$this->error = 'ErrorSupplierReturnLineAlreadyMoved';
			return -1;
		}
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."svc_supplier_return_line WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}
}
