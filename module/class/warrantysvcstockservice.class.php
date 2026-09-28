<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    class/warrantysvcstockservice.class.php
 * \ingroup warrantysvc
 * \brief   Shared, idempotent stock movement service for WarrantySvc workflows
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

class WarrantySvcStockService
{
	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/** @var array */
	public $errors = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Validate that a requested quantity physically exists in the source warehouse.
	 *
	 * Unlike Dolibarr's optional negative-stock protection, WarrantySvc supplier
	 * return/RMA workflows always refuse to move stock that is not present.
	 *
	 * @param int    $productId  Product ID
	 * @param int    $warehouseId Warehouse ID
	 * @param float  $qty        Positive quantity
	 * @param string $batch      LOT/serial, empty for non-batch products
	 * @return int 1 if valid, -1 otherwise
	 */
	public function validateOutbound($productId, $warehouseId, $qty, $batch = '')
	{
		$this->error = '';
		$this->errors = array();

		$productId = (int) $productId;
		$warehouseId = (int) $warehouseId;
		$qty = (float) $qty;
		$batch = trim((string) $batch);

		if ($productId <= 0 || $warehouseId <= 0 || $qty <= 0) {
			$this->error = 'ErrorWarrantySvcInvalidStockMovement';
			return -1;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."entrepot";
		$sql .= " WHERE rowid = ".$warehouseId;
		$sql .= " AND entity IN (".getEntity('stock').")";
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->fetch_object($resql)) {
			if ($resql) $this->db->free($resql);
			$this->error = 'ErrorWarehouseNotFound';
			return -1;
		}
		$this->db->free($resql);

		$product = new Product($this->db);
		if ($product->fetch($productId) <= 0) {
			$this->error = 'ErrorProductNotFound';
			return -1;
		}
		if ((int) $product->stockable_product !== Product::ENABLED_STOCK) {
			$this->error = 'ErrorWarrantySvcProductNotStockable';
			return -1;
		}

		$hasBatch = method_exists($product, 'hasbatch') ? (bool) $product->hasbatch() : !empty($product->status_batch);
		if ($hasBatch && $batch === '') {
			$this->error = 'ErrorWarrantySvcBatchRequired';
			return -1;
		}
		if (!empty($product->status_batch) && (int) $product->status_batch === 2 && abs($qty - 1.0) > 0.00000001) {
			$this->error = 'ErrorWarrantySvcSerialQtyMustBeOne';
			return -1;
		}

		if ($hasBatch) {
			$sql = "SELECT COALESCE(SUM(pb.qty),0) AS qty";
			$sql .= " FROM ".MAIN_DB_PREFIX."product_stock ps";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_batch pb ON pb.fk_product_stock = ps.rowid";
			$sql .= " WHERE ps.fk_product = ".$productId;
			$sql .= " AND ps.fk_entrepot = ".$warehouseId;
			$sql .= " AND pb.batch = '".$this->db->escape($batch)."'";
		} else {
			$sql = "SELECT COALESCE(ps.reel,0) AS qty";
			$sql .= " FROM ".MAIN_DB_PREFIX."product_stock ps";
			$sql .= " WHERE ps.fk_product = ".$productId;
			$sql .= " AND ps.fk_entrepot = ".$warehouseId;
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		$available = $obj ? (float) $obj->qty : 0.0;

		if ($available + 0.00000001 < $qty) {
			$this->error = 'ErrorWarrantySvcInsufficientStock';
			$this->errors[] = 'available='.$available.' requested='.$qty;
			return -1;
		}

		return 1;
	}

	/**
	 * Create an idempotent outbound movement.
	 *
	 * The deterministic inventory code survives a crash between stock creation
	 * and local FK persistence. A retry returns the existing movement instead
	 * of subtracting stock a second time.
	 *
	 * @param User   $user
	 * @param string $originType
	 * @param int    $originId
	 * @param int    $lineId
	 * @param int    $productId
	 * @param int    $warehouseId
	 * @param float  $qty
	 * @param string $batch
	 * @param string $label
	 * @return int Movement ID or -1
	 */
	public function createOutboundMovement($user, $originType, $originId, $lineId, $productId, $warehouseId, $qty, $batch = '', $label = '')
	{
		$this->error = '';
		$this->errors = array();

		$originType = trim((string) $originType);
		$originId = (int) $originId;
		$lineId = (int) $lineId;
		$productId = (int) $productId;
		$warehouseId = (int) $warehouseId;
		$qty = (float) $qty;
		$batch = trim((string) $batch);

		if ($originType === '' || $originId <= 0 || $lineId < 0 || $productId <= 0 || $warehouseId <= 0 || $qty <= 0) {
			$this->error = 'ErrorWarrantySvcInvalidStockMovement';
			return -1;
		}

		$inventoryCode = substr('WSVC-OUT-'.preg_replace('/[^A-Za-z0-9_-]/', '', $originType).'-'.$originId.'-'.$lineId, 0, 128);

		/*
		 * Keep the idempotency lookup, stock check and actual movement in one
		 * transaction. Locking the source product_stock row serializes WarrantySvc
		 * outbound moves for the same product/warehouse, so two concurrent requests
		 * cannot both pass the "movement does not exist" check.
		 *
		 * MouvementStock opens its own nested transaction; Dolibarr's DoliDB
		 * transaction counter keeps the physical COMMIT deferred until this method
		 * commits the outer transaction.
		 */
		$this->db->begin();

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_stock";
		$sql .= " WHERE fk_product = ".$productId;
		$sql .= " AND fk_entrepot = ".$warehouseId;
		if ($this->db->type !== 'sqlite3') {
			$sql .= " FOR UPDATE";
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->free($resql);

		$sql = "SELECT rowid, fk_product, fk_entrepot, value, type_mouvement, batch";
		$sql .= " FROM ".MAIN_DB_PREFIX."stock_mouvement";
		$sql .= " WHERE inventorycode = '".$this->db->escape($inventoryCode)."'";
		$sql .= " AND origintype = '".$this->db->escape($originType)."'";
		$sql .= " AND fk_origin = ".$originId;
		$sql .= " ORDER BY rowid DESC";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$existing = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if ($existing) {
			$existingBatch = trim((string) $existing->batch);
			if ((int) $existing->fk_product !== $productId
				|| (int) $existing->fk_entrepot !== $warehouseId
				|| (int) $existing->type_mouvement !== 2
				|| abs(abs((float) $existing->value) - $qty) > 0.00000001
				|| $existingBatch !== $batch
			) {
				$this->error = 'ErrorWarrantySvcStockMovementConflict';
				$this->db->rollback();
				return -1;
			}
			$this->db->commit();
			return (int) $existing->rowid;
		}

		if ($this->validateOutbound($productId, $warehouseId, $qty, $batch) < 0) {
			$this->db->rollback();
			return -1;
		}

		$movement = new MouvementStock($this->db);
		$movement->setOrigin($originType, $originId);
		$result = $movement->livraison(
			$user,
			$productId,
			$warehouseId,
			$qty,
			0,
			(string) $label,
			dol_now(),
			'',
			'',
			$batch,
			0,
			$inventoryCode
		);
		if ($result <= 0) {
			$this->error = !empty($movement->error) ? $movement->error : 'ErrorWarrantySvcStockMovementFailed';
			$this->errors = !empty($movement->errors) ? $movement->errors : array();
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return (int) $result;
	}
}
