<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    core/triggers/interface_99_modWarrantySvc_WarrantySvcTrigger.class.php
 * \ingroup warrantysvc
 * \brief   Automation trigger for Warranty & Service module events
 *
 * Fires on SVCREQUEST_* and SVCWARRANTY_* trigger codes produced by
 * SvcRequest::call_trigger() / SvcWarranty::call_trigger().
 * Also listens on FICHINTER_CLOSE to update SvcServiceLog.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantytype.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';


/**
 * Class InterfaceWarrantySvcTrigger
 */
class InterfaceWarrantySvcTrigger extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name        = preg_replace('/^Interface/i', '', get_class($this));
		$this->description = 'Automation for Warranty & Service module events';
		$this->version     = '1.0.0';
		$this->picto       = 'technic';
		$this->family      = 'warrantysvc';
	}

	/**
	 * Return name of trigger file
	 *
	 * @return string
	 */
	public function getName()
	{
		return 'WarrantySvcTrigger';
	}

	/**
	 * Return description of trigger file
	 *
	 * @return string
	 */
	public function getDesc()
	{
		return $this->description;
	}

	/**
	 * Run trigger
	 *
	 * @param  string    $action  Event code
	 * @param  mixed     $object  Object
	 * @param  User      $user    User
	 * @param  Translate $langs   Language
	 * @param  Conf      $conf    Config
	 * @return int                0=nothing done, 1=OK, <0=error
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('warrantysvc')) {
			return 0;
		}

		$langs->loadLangs(array('warrantysvc@warrantysvc', 'mails'));

		switch ($action) {
			// ------------------------------------------------------------------
			// Service Request: new record created (draft)
			// ------------------------------------------------------------------
			case 'WARRANTYSVC_CREATE':
				// Sync claim_count on the linked warranty
				if (!empty($object->fk_warranty)) {
					$this->_syncWarrantyClaimCount($object->fk_warranty);
				}
				dol_syslog('WarrantySvcTrigger: WARRANTYSVC_CREATE ref='.$object->ref, LOG_DEBUG);
				return 1;

			// ------------------------------------------------------------------
			// Service Request validated — keep warranty counters in sync.
			// Email notifications are handled by Dolibarr's Notification module.
			// ------------------------------------------------------------------
			case 'WARRANTYSVC_VALIDATE':
				if (!empty($object->fk_warranty)) {
					$this->_syncWarrantyClaimCount($object->fk_warranty);
				}
				return 1;

			// ------------------------------------------------------------------
			// Lifecycle events below are intentionally notification-neutral here.
			// Dolibarr's standard Notification trigger consumes them separately.
			// ------------------------------------------------------------------
			case 'WARRANTYSVC_ASSIGNED':
			case 'WARRANTYSVC_SETDIAGNOSING':
			case 'WARRANTYSVC_SETINPROGRESS':
			case 'WARRANTYSVC_AWAITRETURN':
			case 'WARRANTYSVC_RESOLVE':
			case 'WARRANTYSVC_CANCEL':
			case 'WARRANTYSVC_REOPEN':
				return 1;

			// ------------------------------------------------------------------
			// Closed
			// ------------------------------------------------------------------
			case 'WARRANTYSVC_CLOSE':
				dol_syslog('WarrantySvcTrigger: WARRANTYSVC_CLOSE ref='.$object->ref, LOG_DEBUG);
				return 1;

			// ------------------------------------------------------------------
			// Fichinter (Intervention) closed — update SvcServiceLog
			// ------------------------------------------------------------------
			case 'FICHINTER_CLOSE':
				$this->_syncServiceLogFromIntervention($object, $user);
				return 1;

			// ------------------------------------------------------------------
			// Warranty created. Standard Dolibarr Notification handles any email.
			// ------------------------------------------------------------------
			case 'SVCWARRANTY_CREATE':
				return 1;

			// ------------------------------------------------------------------
			// Supplier RMA email sent through Dolibarr's native
			// actions_sendmails.inc.php pipeline. The physical message has already
			// been accepted by CMailFile at this point, so add a non-status audit
			// event to the Supplier RMA lifecycle.
			// ------------------------------------------------------------------
			case 'SVCSUPPLIERRMA_SENTBYMAIL':
				if (isset($object->element) && $object->element === 'svcsupplierrma' && method_exists($object, 'logEvent')) {
					$recipient = !empty($object->email_to) ? (string) $object->email_to : '';
					$subject = !empty($object->email_subject) ? (string) $object->email_subject : '';
					$note = $langs->transnoentitiesnoconv('SupplierRmaEmailAuditNote', $recipient, $subject);
					$result = $object->logEvent('EMAIL', (string) $object->status, (string) $object->status, $note, $user);
					if ($result < 0) {
						// The email has already been sent. Do not turn a logging
						// failure into a misleading "mail failed" result.
						dol_syslog('WarrantySvcTrigger: unable to audit SVCSUPPLIERRMA_SENTBYMAIL for '.$object->ref.': '.$object->error, LOG_ERR);
					}
				}
				return 1;

			// ------------------------------------------------------------------
			// Supplier Return email sent through Dolibarr's native mail pipeline.
			// ------------------------------------------------------------------
			case 'SVCSUPPLIERRETURN_SENTBYMAIL':
				if (isset($object->element) && $object->element === 'svcsupplierreturn' && method_exists($object, 'logEvent')) {
					$recipient = !empty($object->email_to) ? (string) $object->email_to : '';
					$subject = !empty($object->email_subject) ? (string) $object->email_subject : '';
					$note = $langs->transnoentitiesnoconv('SupplierReturnEmailAuditNote', $recipient, $subject);
					$result = $object->logEvent('EMAIL', (string) $object->status, (string) $object->status, $note, $user);
					if ($result < 0) {
						dol_syslog('WarrantySvcTrigger: unable to audit SVCSUPPLIERRETURN_SENTBYMAIL for '.$object->ref.': '.$object->error, LOG_ERR);
					}
				}
				return 1;

			// ------------------------------------------------------------------
			// Shipment closed or validated — auto-create warranty records
			// for each shipped serialized product line.
			// Gated by WARRANTYSVC_AUTO_WARRANTY_ON_SHIPMENT (master switch)
			// and WARRANTYSVC_WARRANTY_TRIGGER_EVENT (validate|close|both).
			// ------------------------------------------------------------------
			case 'SHIPPING_CLOSED':
			case 'SHIPPING_VALIDATE':
				if (getDolGlobalInt('WARRANTYSVC_AUTO_WARRANTY_ON_SHIPMENT')) {
					$trigger_setting = getDolGlobalString('WARRANTYSVC_WARRANTY_TRIGGER_EVENT', 'close');
					$fire = false;
					if ($trigger_setting === 'both') {
						$fire = true;
					} elseif ($trigger_setting === 'close' && $action === 'SHIPPING_CLOSED') {
						$fire = true;
					} elseif ($trigger_setting === 'validate' && $action === 'SHIPPING_VALIDATE') {
						$fire = true;
					}
					if ($fire) {
						$this->_autoCreateWarrantiesFromShipment($object, $user, $langs);
					}
				}
				return 1;

			// ------------------------------------------------------------------
			// Order closed (classified as delivered) — auto-create warranty
			// records by iterating linked shipments. Reuses the existing
			// _autoCreateWarrantiesFromShipment() method for each expedition.
			// ------------------------------------------------------------------
			case 'ORDER_CLOSE':
				if (getDolGlobalInt('WARRANTYSVC_AUTO_WARRANTY_ON_ORDER_CLOSE')) {
					$object->fetchObjectLinked('', 'expedition', $object->id, 'commande');
					if (!empty($object->linkedObjects['expedition'])) {
						foreach ($object->linkedObjects['expedition'] as $expedition) {
							// Ensure the expedition has its socid set (needed by _autoCreateWarrantiesFromShipment)
							if (empty($expedition->socid) && !empty($object->socid)) {
								$expedition->socid = $object->socid;
							}
							$this->_autoCreateWarrantiesFromShipment($expedition, $user, $langs);
						}
					}
				}
				return 1;

			// ------------------------------------------------------------------
			// Sales order created — if origin is an SR, auto-link via
			// llx_element_element and store fk_commande on the SR
			// ------------------------------------------------------------------
			case 'ORDER_CREATE':
				if (!empty($object->origin) && $object->origin === 'warrantysvc_svcrequest' && !empty($object->origin_id)) {
					$this->_linkOrderToSvcRequest($object, $user);
				}
				return 0;

			// ------------------------------------------------------------------
			// Customer Return validated — if linked to an SR, record the receipt
			// ------------------------------------------------------------------
			case 'CUSTOMERRETURN_CUSTOMERRETURN_VALIDATE':
				if (!getDolGlobalString('WARRANTYSVC_USE_CUSTOMERRETURN')) {
					return 0;
				}
				$this->_handleCustomerReturnValidated($object, $user);
				return 0;

			// ------------------------------------------------------------------
			// Customer Return reopened — its stock movements were reversed, so
			// the recorded receipt on the linked SR is void. Inverse of VALIDATE.
			// ------------------------------------------------------------------
			case 'CUSTOMERRETURN_CUSTOMERRETURN_REOPEN':
				if (!getDolGlobalString('WARRANTYSVC_USE_CUSTOMERRETURN')) {
					return 0;
				}
				$this->_handleCustomerReturnReopened($object, $user);
				return 0;

			default:
				return 0;
		}
	}

	/**
	 * Auto-link a newly created SO to its originating SvcRequest.
	 * Inserts an llx_element_element row and stores fk_commande on the SR.
	 *
	 * @param  Commande $object  Newly created SO
	 * @param  User     $user    Actor
	 * @return void
	 */
	private function _linkOrderToSvcRequest($object, $user)
	{
		$so_id = (int) $object->id;
		$sr_id = (int) $object->origin_id;

		// Bidirectional link in Dolibarr's native element_element table
		// Direction 1: SO as source → SR as target
		$sql1 = "INSERT INTO ".MAIN_DB_PREFIX."element_element (fk_source, sourcetype, fk_target, targettype) VALUES (".$so_id.", 'commande', ".$sr_id.", 'warrantysvc_svcrequest')";
		$this->db->query($sql1); // non-fatal if it fails (e.g. duplicate)
		// Direction 2: SR as source → SO as target
		$sql2 = "INSERT INTO ".MAIN_DB_PREFIX."element_element (fk_source, sourcetype, fk_target, targettype) VALUES (".$sr_id.", 'warrantysvc_svcrequest', ".$so_id.", 'commande')";
		$this->db->query($sql2); // non-fatal if it fails (e.g. duplicate)

		// Store on the SR so the action panel can display the link directly
		require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
		$sr = new SvcRequest($this->db);
		if ($sr->fetch($sr_id) > 0 && empty($sr->fk_commande)) {
			$sr->fk_commande = $so_id;
			$sr->update($user);
		}

		dol_syslog('WarrantySvcTrigger: linked SO '.$so_id.' to SvcRequest '.$sr_id, LOG_DEBUG);
	}

	/**
	 * When a Customer Return is validated, check if it was linked to an SR.
	 * Record the physical receipt. If an older workflow had explicitly put the
	 * request in Await Return, resume Diagnosing: receiving the unit is an input
	 * to diagnosis, not proof that a final resolution is already known.
	 *
	 * @param  object $object  The validated CustomerReturn
	 * @param  User   $user    Actor
	 * @return void
	 */
	private function _handleCustomerReturnValidated($object, $user)
	{
		$cr_id = (int) $object->id;

		// Look for an SR linked as source → this customerreturn as target
		$sql = "SELECT fk_source FROM ".MAIN_DB_PREFIX."element_element WHERE fk_target = ".$cr_id." AND targettype IN ('customerreturn', 'customerreturn_customerreturn') AND sourcetype = 'warrantysvc_svcrequest' LIMIT 1";
		$res = $this->db->query($sql);
		if (!$res) {
			return;
		}
		$row = $this->db->fetch_object($res);
		if (!$row || empty($row->fk_source)) {
			return;
		}

		$sr_id = (int) $row->fk_source;
		require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
		$sr = new SvcRequest($this->db);
		if ($sr->fetch($sr_id) <= 0) {
			return;
		}

		// Update return-received date
		$sr->date_return_received = dol_now();
		$sr->update($user);

		// Legacy/explicit Await Return cases resume diagnosis when the unit arrives.
		if ($sr->status == SvcRequest::STATUS_AWAIT_RETURN) {
			$sr->status = SvcRequest::STATUS_DIAGNOSING;
			if ($sr->update($user) < 0) {
				// Do not fail Customer Return validation over an SR status update:
				// the stock receipt is the authoritative physical event.
				dol_syslog('WarrantySvcTrigger: could not resume diagnosis for SR '.$sr_id.' after CustomerReturn receipt: '.$sr->error, LOG_WARNING);
			}
		}

		dol_syslog('WarrantySvcTrigger: CustomerReturn '.$cr_id.' validated, updated SR '.$sr_id, LOG_DEBUG);
	}

	/**
	 * When a Customer Return linked to an SR is reopened, its stock movements
	 * have been reversed — the goods are no longer booked in. Clear the physical
	 * receipt date, but do not infer a Service Request status transition from
	 * that bookkeeping reversal. Diagnosis may have started before the return,
	 * and Resolved/Closed cases must never be moved backward automatically.
	 *
	 * @param  object $object  The reopened CustomerReturn
	 * @param  User   $user    Actor
	 * @return void
	 */
	private function _handleCustomerReturnReopened($object, $user)
	{
		$cr_id = (int) $object->id;

		$sql = "SELECT fk_source FROM ".MAIN_DB_PREFIX."element_element WHERE fk_target = ".$cr_id." AND targettype IN ('customerreturn', 'customerreturn_customerreturn') AND sourcetype = 'warrantysvc_svcrequest' LIMIT 1";
		$res = $this->db->query($sql);
		if (!$res) {
			return;
		}
		$row = $this->db->fetch_object($res);
		if (!$row || empty($row->fk_source)) {
			return;
		}

		$sr_id = (int) $row->fk_source;
		require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
		$sr = new SvcRequest($this->db);
		if ($sr->fetch($sr_id) <= 0) {
			return;
		}

		// The receipt this date recorded no longer stands.
		$sr->date_return_received = null;
		$sr->update($user);

		if (in_array($sr->status, array(SvcRequest::STATUS_RESOLVED, SvcRequest::STATUS_CLOSED))) {
			dol_syslog('WarrantySvcTrigger: CustomerReturn '.$cr_id.' reopened but SR '.$sr_id.' is already resolved/closed (status '.$sr->status.') — review manually', LOG_WARNING);
		}

		dol_syslog('WarrantySvcTrigger: CustomerReturn '.$cr_id.' reopened, receipt cleared on SR '.$sr_id, LOG_DEBUG);
	}

	/**
	 * Sync claim_count on a warranty record from actual linked service requests.
	 * Idempotent — safe to call from any trigger without risk of double-counting.
	 *
	 * @param  int  $fk_warranty  Warranty ID
	 * @return void
	 */
	private function _syncWarrantyClaimCount($fk_warranty)
	{
		$fk = (int) $fk_warranty;
		$sql = "UPDATE ".MAIN_DB_PREFIX."svc_warranty SET claim_count = (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."svc_request WHERE fk_warranty = ".$fk.") WHERE rowid = ".$fk;
		$this->db->query($sql);
		dol_syslog('WarrantySvcTrigger: synced claim_count on warranty '.$fk, LOG_DEBUG);
	}

	/**
	 * On FICHINTER_CLOSE: find any SvcRequest linked to this intervention,
	 * then upsert SvcServiceLog with updated service hours and count.
	 *
	 * @param  Fichinter $object Intervention
	 * @param  User      $user   Actor
	 * @return void
	 */
	private function _syncServiceLogFromIntervention($object, $user)
	{
		// Find the SvcRequest that references this intervention
		$sql  = "SELECT rowid, serial_number, fk_product FROM ".MAIN_DB_PREFIX."svc_request";
		$sql .= " WHERE fk_intervention = ".((int) $object->id);
		$sql .= " AND entity IN (".getEntity('svcrequest').")";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$obj || empty($obj->serial_number)) {
			return;
		}

		require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcservicelog.class.php';

		$log = new SvcServiceLog($this->db);
		$log->fetchBySerial($obj->serial_number);

		// Accumulate hours from intervention lines
		$total_hours = 0;
		if (!empty($object->lines)) {
			foreach ($object->lines as $line) {
				$total_hours += (float) ($line->duree ?? 0) / 3600; // duree is in seconds
			}
		}

		$log->serial_number      = $obj->serial_number;
		$log->fk_product         = $obj->fk_product;
		$log->service_hours      = $log->service_hours + $total_hours;
		$log->service_count      = $log->service_count + 1;
		$log->last_service_date  = dol_now();
		$log->condition_score    = $log->computeConditionScore();

		$log->save($user);
	}

	/**
	 * Auto-create SvcWarranty records for serialized products
	 * in a closed shipment (SHIPPING_CLOSED trigger).
	 *
	 * For each expedition line with a serial/lot (llx_expeditiondet_batch),
	 * check if a warranty already exists for that serial. If not, create one
	 * using the product-specific warranty type default (if configured),
	 * falling back to the first active warranty type, with coverage_terms
	 * and exclusions populated from the resolved type.
	 *
	 * @param  Expedition $object Closed shipment object
	 * @param  User       $user   Actor
	 * @param  Translate  $langs  Lang
	 * @return void
	 */
	private function _autoCreateWarrantiesFromShipment($object, $user, $langs)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
		require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantytype.class.php';

		$global_coverage_days = getDolGlobalInt('WARRANTYSVC_DEFAULT_COVERAGE_DAYS', 365);
		$duration_source = warrantysvc_get_duration_source();

		// Resolve the contractual warranty start from the shipment, not from the
		// time this trigger happens to run.
		$warranty_start = warrantysvc_resolve_shipment_start_date($this->db, $object);
		if ($warranty_start === null) {
			dol_syslog('WarrantySvcTrigger: no shipment date available for warranty start on shipment '.$object->id, LOG_WARNING);
			return;
		}

		// Validate Product-field mode once before iterating shipment lines.
		if ($duration_source === 'product_field') {
			$product_month_field_error = '';
			$product_month_field = warrantysvc_get_product_month_field($this->db, (int) $conf->entity, $product_month_field_error);
			if ($product_month_field === '' || $product_month_field_error !== '') {
				dol_syslog(
					'WarrantySvcTrigger: '.($product_month_field_error !== '' ? $product_month_field_error : 'Product warranty-duration field is not configured'),
					LOG_ERR
				);
				return;
			}
		}

		// Warranty Types are only part of the upstream duration mode.
		$all_types = $duration_source === 'warranty_type' ? SvcWarrantyType::fetchAllForForm($this->db) : array();

		// Resolve order ID from shipment origin
		$order_id = 0;
		if (!empty($object->origin) && $object->origin == 'commande' && !empty($object->origin_id)) {
			$order_id = (int) $object->origin_id;
		}

		// Fetch every physical shipment line. Serialized/lot-tracked lines expand to
		// one row per batch allocation; ordinary lines remain one row with NULL serial.
		$sql  = "SELECT ed.rowid AS fk_expeditiondet, ed.fk_product, ed.qty AS line_qty,";
		$sql .= " edl.batch AS serial_number, edl.qty AS batch_qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."expeditiondet ed";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."expeditiondet_batch edl ON edl.fk_expeditiondet = ed.rowid";
		$sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = ed.fk_product";
		$sql .= " WHERE ed.fk_expedition = ".((int) $object->id);
		$sql .= " AND p.fk_product_type = 0";
		$sql .= " ORDER BY ed.rowid ASC, edl.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('WarrantySvcTrigger: SHIPPING_CLOSED query failed: '.$this->db->lasterror(), LOG_WARNING);
			return;
		}

		while ($line = $this->db->fetch_object($resql)) {
			$serial_number = trim((string) $line->serial_number);
			$has_serial = ($serial_number !== '');
			$covered_qty = $has_serial ? (float) $line->batch_qty : (float) $line->line_qty;
			if ($covered_qty <= 0) {
				continue;
			}

			// Idempotency: a serialized/batched unit is unique within its shipment;
			// an ordinary product warranty is unique by originating shipment line.
			$sql_dup = "SELECT rowid FROM ".MAIN_DB_PREFIX."svc_warranty";
			if ($has_serial) {
				$sql_dup .= " WHERE serial_number = '".$this->db->escape($serial_number)."'";
				$sql_dup .= " AND fk_expedition = ".((int) $object->id);
				$sql_dup .= " AND fk_product = ".((int) $line->fk_product);
			} else {
				$sql_dup .= " WHERE fk_expeditiondet = ".((int) $line->fk_expeditiondet);
				$sql_dup .= " AND (serial_number IS NULL OR serial_number = '')";
			}
			$sql_dup .= " AND entity = ".((int) $conf->entity);
			$res_dup = $this->db->query($sql_dup);
			if ($res_dup && $this->db->fetch_object($res_dup)) {
				continue;
			}

			// ---- Resolve upstream Warranty Type only in Warranty Type duration mode ----
			$type_code = '';
			$product_coverage_days = 0;
			$matched_type = null;
			if ($duration_source === 'warranty_type') {
				$sql_pd  = "SELECT warranty_type, coverage_days FROM ".MAIN_DB_PREFIX."warrantysvc_product_default";
				$sql_pd .= " WHERE fk_product = ".((int) $line->fk_product)." AND entity = ".((int) $conf->entity);
				$res_pd  = $this->db->query($sql_pd);
				$row_pd  = ($res_pd) ? $this->db->fetch_object($res_pd) : null;

				if (!$row_pd && isModEnabled('variants')) {
					$sql_par  = "SELECT fk_product_parent FROM ".MAIN_DB_PREFIX."product_attribute_combination";
					$sql_par .= " WHERE fk_product_child = ".((int) $line->fk_product);
					$sql_par .= " AND entity IN (".getEntity('product').")";
					$res_par  = $this->db->query($sql_par);
					if ($res_par && ($row_par = $this->db->fetch_object($res_par))) {
						$sql_pd2  = "SELECT warranty_type, coverage_days FROM ".MAIN_DB_PREFIX."warrantysvc_product_default";
						$sql_pd2 .= " WHERE fk_product = ".((int) $row_par->fk_product_parent)." AND entity = ".((int) $conf->entity);
						$res_pd2  = $this->db->query($sql_pd2);
						$row_pd   = ($res_pd2) ? $this->db->fetch_object($res_pd2) : null;
					}
				}

				if ($row_pd && !empty($row_pd->warranty_type)) {
					$type_code = $row_pd->warranty_type;
					$product_coverage_days = ($row_pd->coverage_days > 0) ? (int) $row_pd->coverage_days : 0;
				}

				if ($all_types) {
					foreach ($all_types as $wt) {
						if ($type_code && $wt->code === $type_code) {
							$matched_type = $wt;
							break;
						}
					}
					if (!$matched_type) {
						$matched_type = $all_types[0];
						$type_code = $matched_type->code;
					}
				}
			}

			// ---- Build warranty record ----
			$warranty                  = new SvcWarranty($this->db);
			$warranty->serial_number   = $has_serial ? $serial_number : null;
			$warranty->covered_qty     = $covered_qty;
			$warranty->fk_product      = $line->fk_product;
			$warranty->fk_soc          = $object->socid;
			$warranty->fk_expedition   = $object->id;
			$warranty->fk_expeditiondet= $line->fk_expeditiondet;
			$warranty->fk_commande     = $order_id;
			$warranty->warranty_type   = ($duration_source === 'warranty_type' && $type_code !== '') ? $type_code : null;
			$warranty->coverage_terms  = ($duration_source === 'warranty_type' && $matched_type) ? $matched_type->coverage_terms : '';
			$warranty->exclusions      = ($duration_source === 'warranty_type' && $matched_type) ? $matched_type->exclusions : '';
			$warranty->start_date      = $warranty_start;

			if ($duration_source === 'product_field') {
				$period_error = '';
				$period = warrantysvc_compute_product_warranty_period(
					$this->db,
					(int) $line->fk_product,
					(int) $conf->entity,
					(int) $warranty_start,
					$period_error
				);
				if ($period_error !== '') {
					dol_syslog('WarrantySvcTrigger: '.$period_error.' Product '.$line->fk_product, LOG_ERR);
					continue;
				}
				if ($period === null) {
					dol_syslog(
						'WarrantySvcTrigger: skipped automatic warranty for product '.$line->fk_product.' because its configured warranty period is blank or zero',
						LOG_WARNING
					);
					continue;
				}
				$warranty->coverage_months = $period['months'];
				$warranty->expiry_date = $period['expiry'];
				$warranty->coverage_days = $period['days'];
			} elseif ($product_coverage_days > 0) {
				$warranty->coverage_days = $product_coverage_days;
			} elseif ($matched_type && $matched_type->default_coverage_days > 0) {
				$warranty->coverage_days = (int) $matched_type->default_coverage_days;
			} else {
				$warranty->coverage_days = $global_coverage_days;
			}
			// In legacy day-based mode create() computes expiry_date.

			$result = $warranty->create($user);
			if ($result > 0) {
				// Link warranty to shipment and order in element_element
				if ($warranty->fk_expedition > 0) {
					$warranty->add_object_linked('shipping', $warranty->fk_expedition);
				}
				if ($warranty->fk_commande > 0) {
					$warranty->add_object_linked('commande', $warranty->fk_commande);
					// Discover and link invoices tied to this order
					$sql_inv = "SELECT fk_target FROM ".MAIN_DB_PREFIX."element_element WHERE fk_source = ".((int) $warranty->fk_commande)." AND sourcetype = 'commande' AND targettype = 'facture'";
					$res_inv = $this->db->query($sql_inv);
					if ($res_inv) {
						while ($row_inv = $this->db->fetch_object($res_inv)) {
							$warranty->add_object_linked('facture', (int) $row_inv->fk_target);
						}
					}
				}
			} else {
				dol_syslog(
					'WarrantySvcTrigger: failed to create warranty for '.($has_serial ? 'serial '.$serial_number : 'shipment line '.$line->fk_expeditiondet).': '.$warranty->error,
					LOG_WARNING
				);
			}
		}

		$this->db->free($resql);
	}
}
