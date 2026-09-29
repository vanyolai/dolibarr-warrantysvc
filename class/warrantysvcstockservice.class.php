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
	 * Find a real, validated Dolibarr reception proving that a product (and,
	 * when supplied, a specific LOT/serial) came from the selected supplier.
	 *
	 * We deliberately follow receptiondet_batch -> supplier order line ->
	 * supplier order instead of the supplier price list. A price-list relation
	 * only says the supplier can sell the product; it does not prove that the
	 * physical stock being returned was purchased from them.
	 *
	 * @param int    $supplierId Supplier thirdparty id
	 * @param int    $productId  Product id
	 * @param string $batch      Optional LOT/serial
	 * @return array|false Source ids on success, false when no proven source exists
	 */
	/**
	 * Find a real supplier order line proving that this product has been ordered
	 * from the selected supplier.
	 *
	 * Supplier Return eligibility is intentionally based on supplier order
	 * history plus current physical stock. A reception record is useful extra
	 * traceability when available, but must not be mandatory because older or
	 * migrated Dolibarr data may not have a complete reception history.
	 *
	 * @param int $supplierId Supplier thirdparty id
	 * @param int $productId  Product id
	 * @return array|false Supplier order ids on success
	 */
	public function findSupplierOrderSource($supplierId, $productId)
	{
		global $conf;

		$supplierId = (int) $supplierId;
		$productId = (int) $productId;
		if ($supplierId <= 0 || $productId <= 0) {
			return false;
		}

		$sql = "SELECT cfd.rowid AS supplier_order_line_id, cf.rowid AS supplier_order_id";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande_fournisseurdet cfd";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande_fournisseur cf ON cf.rowid = cfd.fk_commande";
		$sql .= " WHERE cfd.fk_product = ".$productId;
		$sql .= " AND cf.fk_soc = ".$supplierId;
		$sql .= " AND cf.entity = ".((int) $conf->entity);
		$sql .= " AND cf.fk_statut IN (3, 4, 5)";
		$sql .= " ORDER BY COALESCE(cf.date_reception, cf.date_commande, cf.date_creation) DESC, cfd.rowid DESC";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return false;
		}

		return array(
			'fk_supplier_order_line' => (int) $obj->supplier_order_line_id,
			'fk_supplier_order' => (int) $obj->supplier_order_id,
			'fk_reception_line' => 0,
			'fk_reception' => 0,
		);
	}

	public function findSupplierReceiptSource($supplierId, $productId, $batch = '')
	{
		global $conf;

		$supplierId = (int) $supplierId;
		$productId = (int) $productId;
		$batch = trim((string) $batch);
		if ($supplierId <= 0 || $productId <= 0) {
			return false;
		}

		$sql = "SELECT rd.rowid AS reception_line_id, rd.fk_elementdet AS supplier_order_line_id,";
		$sql .= " r.rowid AS reception_id, cf.rowid AS supplier_order_id";
		$sql .= " FROM ".MAIN_DB_PREFIX."receptiondet_batch rd";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."reception r ON r.rowid = rd.fk_reception";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande_fournisseurdet cfd ON cfd.rowid = rd.fk_elementdet";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande_fournisseur cf ON cf.rowid = cfd.fk_commande";
		$sql .= " WHERE rd.element_type IN ('supplier_order', 'order_supplier')";
		$sql .= " AND rd.fk_product = ".$productId;
		$sql .= " AND r.fk_soc = ".$supplierId;
		$sql .= " AND cf.fk_soc = ".$supplierId;
		$sql .= " AND r.entity = ".((int) $conf->entity);
		$sql .= " AND r.fk_statut > 0";
		if ($batch !== '') {
			$sql .= " AND rd.batch = '".$this->db->escape($batch)."'";
		}
		$sql .= " ORDER BY r.date_valid DESC, rd.rowid DESC";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return false;
		}

		return array(
			'fk_reception_line' => (int) $obj->reception_line_id,
			'fk_supplier_order_line' => (int) $obj->supplier_order_line_id,
			'fk_reception' => (int) $obj->reception_id,
			'fk_supplier_order' => (int) $obj->supplier_order_id,
		);
	}

	/**
	 * Products eligible for a Supplier Return.
	 *
	 * Conditions:
	 * - physically on hand in the selected source warehouse;
	 * - previously ordered on a real supplier order belonging to the selected
	 *   supplier (reception history is linked when available, not required);
	 * - not fully consumed by other lines of this same draft return.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getSupplierReturnProductChoices($supplierId, $warehouseId, $returnId = 0, $excludeLineId = 0)
	{
		global $conf;

		$supplierId = (int) $supplierId;
		$warehouseId = (int) $warehouseId;
		$returnId = (int) $returnId;
		$excludeLineId = (int) $excludeLineId;
		$out = array();
		if ($supplierId <= 0 || $warehouseId <= 0) {
			return $out;
		}

		$reservedSql = "SELECT l.fk_product, SUM(l.qty) AS qty_reserved";
		$reservedSql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return_line l";
		$reservedSql .= " WHERE l.fk_supplier_return = ".$returnId;
		if ($excludeLineId > 0) {
			$reservedSql .= " AND l.rowid <> ".$excludeLineId;
		}
		$reservedSql .= " GROUP BY l.fk_product";

		$sql = "SELECT p.rowid, p.ref, p.label, p.tobatch, ps.reel,";
		$sql .= " COALESCE(res.qty_reserved, 0) AS qty_reserved";
		$sql .= " FROM ".MAIN_DB_PREFIX."product p";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock ps";
		$sql .= " ON ps.fk_product = p.rowid AND ps.fk_entrepot = ".$warehouseId;
		$sql .= " LEFT JOIN (".$reservedSql.") res ON res.fk_product = p.rowid";
		$sql .= " WHERE ps.reel > 0";
		$sql .= " AND (ps.reel - COALESCE(res.qty_reserved, 0)) > 0";
		$sql .= " AND EXISTS (";
		$sql .= " SELECT 1 FROM ".MAIN_DB_PREFIX."commande_fournisseurdet cfd";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande_fournisseur cf ON cf.rowid = cfd.fk_commande";
		$sql .= " WHERE cfd.fk_product = p.rowid";
		$sql .= " AND cf.fk_soc = ".$supplierId;
		$sql .= " AND cf.entity = ".((int) $conf->entity);
		$sql .= " AND cf.fk_statut IN (3, 4, 5)";
		$sql .= ")";
		$sql .= " ORDER BY p.ref";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $out;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$available = (float) $obj->reel - (float) $obj->qty_reserved;
			$out[(int) $obj->rowid] = array(
				'ref' => (string) $obj->ref,
				'label' => (string) $obj->label,
				'status_batch' => (int) $obj->tobatch,
				'stock' => (float) $obj->reel,
				'available' => $available,
			);
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Current LOT/serial choices for an eligible Supplier Return product,
	 * restricted to identifiers physically present in the selected warehouse.
	 *
	 * @return array<string,float> batch => available qty
	 */
	public function getSupplierReturnBatchChoices($supplierId, $warehouseId, $productId, $returnId = 0, $excludeLineId = 0)
	{
		global $conf;

		$supplierId = (int) $supplierId;
		$warehouseId = (int) $warehouseId;
		$productId = (int) $productId;
		$returnId = (int) $returnId;
		$excludeLineId = (int) $excludeLineId;
		$out = array();
		if ($supplierId <= 0 || $warehouseId <= 0 || $productId <= 0) {
			return $out;
		}

		$reservedSql = "SELECT l.batch, SUM(l.qty) AS qty_reserved";
		$reservedSql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return_line l";
		$reservedSql .= " WHERE l.fk_supplier_return = ".$returnId;
		$reservedSql .= " AND l.fk_product = ".$productId;
		if ($excludeLineId > 0) {
			$reservedSql .= " AND l.rowid <> ".$excludeLineId;
		}
		$reservedSql .= " GROUP BY l.batch";

		$sql = "SELECT pb.batch, pb.qty, COALESCE(res.qty_reserved, 0) AS qty_reserved";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_stock ps";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch pb ON pb.fk_product_stock = ps.rowid";
		$sql .= " LEFT JOIN (".$reservedSql.") res ON res.batch = pb.batch";
		$sql .= " WHERE ps.fk_product = ".$productId;
		$sql .= " AND ps.fk_entrepot = ".$warehouseId;
		$sql .= " AND pb.qty > 0";
		$sql .= " AND (pb.qty - COALESCE(res.qty_reserved, 0)) > 0";
		$sql .= " AND EXISTS (";
		$sql .= " SELECT 1 FROM ".MAIN_DB_PREFIX."commande_fournisseurdet cfd";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande_fournisseur cf ON cf.rowid = cfd.fk_commande";
		$sql .= " WHERE cfd.fk_product = ".$productId;
		$sql .= " AND cf.fk_soc = ".$supplierId;
		$sql .= " AND cf.entity = ".((int) $conf->entity);
		$sql .= " AND cf.fk_statut IN (3, 4, 5)";
		$sql .= ")";
		$sql .= " ORDER BY pb.batch";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $out;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$out[(string) $obj->batch] = (float) $obj->qty - (float) $obj->qty_reserved;
		}
		$this->db->free($resql);

		return $out;
	}

	/**
	 * Validate one Supplier Return line and resolve its source reception/order.
	 *
	 * @return array|false Provenance ids on success, false on failure
	 */
	public function validateSupplierReturnLine($supplierId, $warehouseId, $returnId, $lineId, $productId, $qty, $batch = '')
	{
		$supplierId = (int) $supplierId;
		$warehouseId = (int) $warehouseId;
		$returnId = (int) $returnId;
		$lineId = (int) $lineId;
		$productId = (int) $productId;
		$qty = (float) $qty;
		$batch = trim((string) $batch);

		if ($this->validateOutbound($productId, $warehouseId, $qty, $batch) < 0) {
			return false;
		}

		$product = new Product($this->db);
		if ($product->fetch($productId) <= 0) {
			$this->error = 'ErrorProductNotFound';
			return false;
		}
		$hasBatch = method_exists($product, 'hasbatch') ? (bool) $product->hasbatch() : !empty($product->status_batch);

		$source = $this->findSupplierOrderSource($supplierId, $productId);
		if ($source === false) {
			$this->error = 'ErrorSupplierReturnProductNotFromSupplier';
			return false;
		}

		// Enrich the audit link with the exact reception when Dolibarr has one,
		// but do not make reception history a prerequisite for returning stock.
		$receiptSource = $this->findSupplierReceiptSource($supplierId, $productId, $hasBatch ? $batch : '');
		if ($receiptSource !== false) {
			$source = $receiptSource;
		}

		// Prevent the same draft Supplier Return from reserving more than the
		// currently available physical quantity across several lines.
		$sql = "SELECT COALESCE(SUM(qty),0) AS qty_reserved";
		$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return_line";
		$sql .= " WHERE fk_supplier_return = ".$returnId;
		$sql .= " AND fk_product = ".$productId;
		if ($hasBatch) {
			$sql .= " AND batch = '".$this->db->escape($batch)."'";
		}
		if ($lineId > 0) {
			$sql .= " AND rowid <> ".$lineId;
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		$reserved = $obj ? (float) $obj->qty_reserved : 0.0;

		if ($hasBatch) {
			$sql = "SELECT COALESCE(SUM(pb.qty),0) AS qty";
			$sql .= " FROM ".MAIN_DB_PREFIX."product_stock ps";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch pb ON pb.fk_product_stock = ps.rowid";
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
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		$physical = $obj ? (float) $obj->qty : 0.0;
		if (($physical - $reserved) + 0.00000001 < $qty) {
			$this->error = 'ErrorSupplierReturnInsufficientUnreservedStock';
			$this->errors[] = 'available='.($physical - $reserved).' requested='.$qty;
			return false;
		}

		return $source;
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

		$inventoryOriginKey = ($originType === 'SvcSupplierReturn@warrantysvc') ? 'svcsupplierreturn' : $originType;
		$originAliases = array($originType);
		if ($originType === 'SvcSupplierReturn@warrantysvc') {
			$originAliases[] = 'svcsupplierreturn';
		}
		$inventoryCode = substr('WSVC-OUT-'.preg_replace('/[^A-Za-z0-9_-]/', '', $inventoryOriginKey).'-'.$originId.'-'.$lineId, 0, 128);

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

		$sql = "SELECT rowid, fk_product, fk_entrepot, value, type_mouvement, batch, origintype";
		$sql .= " FROM ".MAIN_DB_PREFIX."stock_mouvement";
		$sql .= " WHERE inventorycode = '".$this->db->escape($inventoryCode)."'";
		if (count($originAliases) > 1) {
			$escapedOriginAliases = array();
			foreach ($originAliases as $originAlias) {
				$escapedOriginAliases[] = "'".$this->db->escape($originAlias)."'";
			}
			$sql .= " AND origintype IN (".implode(',', $escapedOriginAliases).")";
		} else {
			$sql .= " AND origintype = '".$this->db->escape($originType)."'";
		}
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
			if ((string) $existing->origintype !== $originType) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."stock_mouvement";
				$sql .= " SET origintype = '".$this->db->escape($originType)."'";
				$sql .= " WHERE rowid = ".((int) $existing->rowid);
				if (!$this->db->query($sql)) {
					$this->error = $this->db->lasterror();
					$this->db->rollback();
					return -1;
				}
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

	/**
	 * Reverse an existing outbound stock movement using Dolibarr's native
	 * MouvementStock::reverseMovement() implementation.
	 *
	 * The lookup is idempotent: if the standard REVERT-* movement already
	 * exists, its rowid is returned instead of creating a second reversal.
	 *
	 * @param User $user Current user
	 * @param int  $movementId Outbound stock movement rowid
	 * @param string|array<string> $expectedOriginType Optional origin type guard
	 * @param int $expectedOriginId Optional origin id guard
	 * @return int Reversal movement rowid or -1 on error
	 */
	public function reverseOutboundMovement($user, $movementId, $expectedOriginType = '', $expectedOriginId = 0)
	{
		$this->error = '';
		$this->errors = array();

		$movementId = (int) $movementId;
		if ($movementId <= 0) {
			$this->error = 'ErrorWarrantySvcInvalidStockMovement';
			return -1;
		}

		$movement = new MouvementStock($this->db);
		if ($movement->fetch($movementId) <= 0) {
			$this->error = !empty($movement->error) ? $movement->error : 'ErrorRecordNotFound';
			return -1;
		}

		if ((int) $movement->type !== 2 || (float) $movement->qty >= 0) {
			$this->error = 'ErrorWarrantySvcStockMovementConflict';
			return -1;
		}
		if ($expectedOriginType !== '') {
			$expectedOriginTypes = is_array($expectedOriginType) ? $expectedOriginType : array($expectedOriginType);
			if (!in_array((string) $movement->origin_type, $expectedOriginTypes, true)) {
				$this->error = 'ErrorWarrantySvcStockMovementConflict';
				return -1;
			}
		}
		if ((int) $expectedOriginId > 0 && (int) $movement->origin_id !== (int) $expectedOriginId) {
			$this->error = 'ErrorWarrantySvcStockMovementConflict';
			return -1;
		}

		$revertCode = 'REVERT-'.(!empty($movement->inventorycode)
			? (string) $movement->inventorycode
			: dol_print_date($movement->datem, '%Y%m%d%His'));

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."stock_mouvement";
		$sql .= " WHERE inventorycode = '".$this->db->escape($revertCode)."'";
		if (!empty($movement->origin_type)) {
			$sql .= " AND origintype = '".$this->db->escape((string) $movement->origin_type)."'";
		}
		if ((int) $movement->origin_id > 0) {
			$sql .= " AND fk_origin = ".((int) $movement->origin_id);
		}
		$sql .= " ORDER BY rowid DESC";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$existing = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if ($existing) {
			return (int) $existing->rowid;
		}

		/*
		 * reverseMovement() intentionally uses the current global Dolibarr user.
		 * The caller passes that same authenticated user here; keeping the native
		 * helper preserves core batch/serial handling and STOCK_MOVEMENT trigger
		 * behaviour.
		 */
		$result = $movement->reverseMovement();
		if ($result <= 0) {
			$this->error = !empty($movement->error) ? $movement->error : 'ErrorWarrantySvcStockMovementReverseFailed';
			$this->errors = !empty($movement->errors) ? $movement->errors : array();
			return -1;
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$created = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$created) {
			$this->error = 'ErrorWarrantySvcStockMovementReverseFailed';
			return -1;
		}

		return (int) $created->rowid;
	}
}
