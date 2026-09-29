<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    class/actions_warrantysvc.class.php
 * \ingroup warrantysvc
 * \brief   Hook actions for the Warranty & Service module
 */

/**
 * Class ActionsWarrantySvc
 *
 * Implements Dolibarr hooks so that svcwarranty and svcrequest element types
 * are recognised system-wide — appearing in the "Link to" dropdown on any
 * Dolibarr object card (shipments, orders, projects, etc.).
 */
class ActionsWarrantySvc
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var string[] Errors */
	public $errors = array();

	/** @var array Result set returned to hook manager */
	public $results = array();

	/** @var string HTML output injected by hook into the page */
	public $resprints = '';

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Provide element properties for svcwarranty and svcrequest so Dolibarr
	 * can resolve them in linked-object lookups and the "Link to" dropdown.
	 *
	 * Called by HookManager on the 'elementproperties' context.
	 * 'warrantysvc' is kept as a backward-compat alias for svcrequest to handle
	 * any existing llx_element_element rows written before the $element fix.
	 *
	 * @param  array      $parameters  Hook parameters, including $parameters['elementType']
	 * @param  object     $object      Current page object
	 * @param  string     $action      Current action
	 * @param  HookManager $hookmanager Hook manager instance
	 * @return int                     0 = merge results via array_replace
	 */
	public function getElementProperties($parameters, &$object, &$action, $hookmanager)
	{
		$elementType = isset($parameters['elementType']) ? $parameters['elementType'] : '';

		if ($elementType === 'svcwarranty' || $elementType === 'warrantysvc_svcwarranty') {
			$this->results = array(
				'module'        => 'warrantysvc',
				'element'       => 'svcwarranty',
				'table_element' => 'svc_warranty',
				'subelement'    => 'svcwarranty',
				'classpath'     => 'custom/warrantysvc/class',
				'classfile'     => 'svcwarranty',
				'classname'     => 'SvcWarranty',
			);
		} elseif ($elementType === 'svcrequest' || $elementType === 'warrantysvc' || $elementType === 'warrantysvc_svcrequest') {
			// 'warrantysvc' and 'warrantysvc_svcrequest' are aliases — the prefixed
			// form is what getElementType() returns and what llx_element_element stores
			$this->results = array(
				'module'        => 'warrantysvc',
				'element'       => 'svcrequest',
				'table_element' => 'svc_request',
				'subelement'    => 'svcrequest',
				'classpath'     => 'custom/warrantysvc/class',
				'classfile'     => 'svcrequest',
				'classname'     => 'SvcRequest',
			);
		} elseif ($elementType === 'svcsupplierrma' || $elementType === 'warrantysvc_svcsupplierrma') {
			$this->results = array(
				'module'        => 'warrantysvc',
				'element'       => 'svcsupplierrma',
				'table_element' => 'svc_supplier_rma',
				'subelement'    => 'svcsupplierrma',
				'classpath'     => 'custom/warrantysvc/class',
				'classfile'     => 'svcsupplierrma',
				'classname'     => 'SvcSupplierRma',
			);
		} elseif ($elementType === 'svcsupplierreturn' || $elementType === 'warrantysvc_svcsupplierreturn') {
			$this->results = array(
				'module'        => 'warrantysvc',
				'element'       => 'svcsupplierreturn',
				'table_element' => 'svc_supplier_return',
				'subelement'    => 'svcsupplierreturn',
				'classpath'     => 'custom/warrantysvc/class',
				'classfile'     => 'svcsupplierreturn',
				'classname'     => 'SvcSupplierReturn',
			);
		}

		return 0;
	}

	/**
	 * Inject warranty default fields onto the product card in edit/view mode.
	 *
	 * Fires on the 'formObjectOptions' hook for the 'productcard' context.
	 * Outputs a "Warranty Default Type" table row (and a "Coverage Days" row in
	 * edit mode) into $this->resprints for the hook manager to append.
	 *
	 * @param  array      $parameters  Hook parameters (includes 'colspan')
	 * @param  object     $object      Current page object
	 * @param  string     $action      Current action
	 * @param  HookManager $hookmanager Hook manager instance
	 * @return int                     0 = continue other hooks
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $db, $user, $form, $conf;

		if (!isModEnabled('warrantysvc')) return 0;

		// ----------------------------------------------------------------
		// Commande creation: inject hidden origin fields when rma_sr_id is
		// present in GET so the SR ↔ SO link is created on form submit
		// without rendering the origin section visually.
		// ----------------------------------------------------------------
		if (isset($object->element) && $object->element === 'commande' && $action === 'create') {
			$rma_sr_id = GETPOSTINT('rma_sr_id');
			if ($rma_sr_id > 0) {
				$this->resprints  = '<input type="hidden" name="origin" value="warrantysvc_svcrequest">';
				$this->resprints .= '<input type="hidden" name="originid" value="'.$rma_sr_id.'">';
			}
			return 0;
		}

		// ----------------------------------------------------------------
		// Product card: inject "Warranty Default Type" row
		// ----------------------------------------------------------------
		if (isset($object->element) && $object->element === 'product' && !empty($object->id)) {
			if (!$user->hasRight('warrantysvc', 'svcwarranty', 'read')) return 0;
			dol_include_once('/warrantysvc/lib/warrantysvc.lib.php');
			$duration_source = warrantysvc_get_duration_source();
			if ($duration_source !== 'warranty_type') return 0;
			$langs->load('warrantysvc@warrantysvc');
			require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantytype.class.php';

			$colspan = isset($parameters['colspan']) ? $parameters['colspan'] : '';

			// 1. Detect variant + fetch parent's warranty default
			$is_variant       = false;
			$parent_wtype     = '';
			$parent_days      = null;
			$parent_wtype_lbl = '';

			if (isModEnabled('variants')) {
				$sql_par  = "SELECT fk_product_parent FROM ".MAIN_DB_PREFIX."product_attribute_combination";
				$sql_par .= " WHERE fk_product_child = ".((int) $object->id);
				$sql_par .= " AND entity IN (".getEntity('product').")";
				$res_par  = $this->db->query($sql_par);
				if ($res_par && ($row_par = $this->db->fetch_object($res_par))) {
					$is_variant = true;
					$parent_id  = (int) $row_par->fk_product_parent;
					$sql_ppd    = "SELECT warranty_type, coverage_days FROM ".MAIN_DB_PREFIX."warrantysvc_product_default";
					$sql_ppd   .= " WHERE fk_product = ".((int) $parent_id)." AND entity = ".((int) $conf->entity);
					$res_ppd    = $this->db->query($sql_ppd);
					if ($res_ppd && ($row_ppd = $this->db->fetch_object($res_ppd))) {
						$parent_wtype     = $row_ppd->warranty_type;
						$parent_days      = $row_ppd->coverage_days;
						$parent_wtype_lbl = SvcWarrantyType::getLabelByCode($this->db, $parent_wtype);
						if (empty($parent_wtype_lbl)) $parent_wtype_lbl = $parent_wtype;
					}
				}
			}

			// 2. Fetch this product's own default
			$current_wtype = '';
			$current_days  = null;
			$sql_own  = "SELECT warranty_type, coverage_days FROM ".MAIN_DB_PREFIX."warrantysvc_product_default";
			$sql_own .= " WHERE fk_product = ".((int) $object->id)." AND entity = ".((int) $conf->entity);
			$res_own  = $this->db->query($sql_own);
			if ($res_own && ($row_own = $this->db->fetch_object($res_own))) {
				$current_wtype = $row_own->warranty_type;
				$current_days  = $row_own->coverage_days;
			}

			// 3. Effective display value (own overrides parent)
			$effective_wtype = $current_wtype !== '' ? $current_wtype : $parent_wtype;
			$effective_days  = ($current_days !== null) ? $current_days : $parent_days;

			// 4. Render
			ob_start();

			if ($action === 'edit') {
				$wtype_items   = SvcWarrantyType::fetchAllForForm($this->db);
				$wtype_options = array();
				$type_days_js  = '{';
				foreach ($wtype_items as $wt) {
					$wtype_options[$wt->code] = dol_escape_htmltag($wt->label);
					$type_days_js .= '"'.dol_escape_js($wt->code).'":'.((int) $wt->default_coverage_days).',';
				}
				$type_days_js = rtrim($type_days_js, ',').'}';

				if ($is_variant && $parent_wtype !== '') {
					$inherit_lbl = $langs->trans('WarrantyInheritParent', $parent_wtype_lbl);
					$wtype_options = array_merge(array('' => '— '.$inherit_lbl.' —'), $wtype_options);
				} else {
					$wtype_options = array_merge(array('' => '— '.$langs->trans('WarrantyNoDefault').' —'), $wtype_options);
				}

				if (!is_object($form)) {
					require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
					$form = new Form($this->db);
				}

				print '<tr>';
				print '<td>'.$langs->trans('WarrantyDefaultType').'</td>';
				print '<td'.$colspan.'>';
				print $form->selectarray('warrantysvc_type', $wtype_options, $current_wtype, 0, 0, 0, '',
					0, 0, 0, '', 'flat minwidth200', 0, 'id="warrantysvc_type"');
				if ($is_variant && $parent_wtype !== '') {
					print ' <span class="opacitymedium small">'.$langs->trans('WarrantyBlankInherits', $parent_wtype_lbl).'</span>';
				}
				print '</td></tr>';

					$days_placeholder = ($is_variant && $parent_days !== null)
						? $langs->trans('WarrantyInheritDays', (int) $parent_days)
						: $langs->trans('WarrantyDefaultDaysPlaceholder');
					print '<tr>';
					print '<td>'.$langs->trans('WarrantyDefaultDays').'</td>';
					print '<td'.$colspan.'>';
					print '<input type="number" name="warrantysvc_days" id="warrantysvc_days"';
					print ' value="'.($current_days !== null ? (int) $current_days : '').'"';
					print ' class="flat width75" min="1" max="3650"';
					print ' placeholder="'.dol_escape_htmltag($days_placeholder).'">';
					print '</td></tr>';

					// Auto-suggest type's default days when type changes and days field is blank.
					print '<script>(function(){';
					print 'var td='.$type_days_js.';';
					print 'var ts=document.getElementById("warrantysvc_type");';
					print 'var td2=document.getElementById("warrantysvc_days");';
					print 'if(ts&&td2){ts.addEventListener("change",function(){';
					print 'if(!td2.value&&td[this.value])td2.value=td[this.value];';
					print '});}';
					print '})();</script>';
			} else {
				// View mode
				print '<tr>';
				print '<td>'.$langs->trans('WarrantyDefaultType').'</td>';
				print '<td'.$colspan.'>';
				if ($effective_wtype !== '') {
					$lbl = SvcWarrantyType::getLabelByCode($this->db, $effective_wtype);
					if (empty($lbl)) $lbl = $effective_wtype;
					print dol_escape_htmltag($lbl);
					if ($effective_days !== null) {
							print ' / '.(int) $effective_days.' '.$langs->trans('SvcDays');
					} else {
						print ' <span class="opacitymedium">('.$langs->trans('WarrantyDefaultDaysFromType').')</span>';
					}
					if ($is_variant && $current_wtype === '') {
						print ' <span class="opacitymedium small">('.$langs->trans('WarrantyInheritedFromParent').')</span>';
					} elseif ($is_variant && $current_wtype !== '') {
						print ' <span class="opacitymedium small">('.$langs->trans('WarrantyVariantOverride').')</span>';
					}
				} else {
					print '<span class="opacitymedium">'.$langs->trans('WarrantyNoDefault').'</span>';
				}
				print '</td></tr>';
			}

			$this->resprints = ob_get_clean();
			return 0;
		}

		return 0;
	}

	/**
	 * Add Supplier Returns to the native Product "Related elements" statistics.
	 * This is called from core product.lib.php::show_stats_for_company().
	 *
	 * @param array $parameters Hook parameters (socid may be set)
	 * @param Product $object Product
	 * @param mixed $action Core passes its row counter here
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0
	 */
	public function addMoreProductStat($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		$this->resprints = '';
		if (!isModEnabled('warrantysvc')
			|| empty($object->id)
			|| !$user->hasRight('warrantysvc', 'supplierreturn', 'read')
		) {
			return 0;
		}

		$sql = "SELECT COUNT(DISTINCT r.fk_soc_supplier) as suppliers";
		$sql .= ", COUNT(DISTINCT r.rowid) as nb";
		$sql .= ", COALESCE(SUM(l.qty), 0) as qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return_line l";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."svc_supplier_return r ON r.rowid = l.fk_supplier_return";
		$sql .= " WHERE l.fk_product = ".((int) $object->id);
		$sql .= " AND r.entity = ".((int) $conf->entity);
		if (!empty($parameters['socid'])) {
			$sql .= " AND r.fk_soc_supplier = ".((int) $parameters['socid']);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$stats = $this->db->fetch_object($resql);
		$this->db->free($resql);

		$langs->load('warrantysvc@warrantysvc');
		$url = DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_product.php?productid='.((int) $object->id);
		if (!empty($parameters['socid'])) {
			$url .= '&socid='.((int) $parameters['socid']);
		}

		$this->resprints .= '<tr><td>';
		$this->resprints .= '<a href="'.$url.'">'.img_object('', 'shipment', 'class="pictofixedwidth"').$langs->trans('SupplierReturns').'</a>';
		$this->resprints .= '</td><td class="right">'.((int) ($stats ? $stats->suppliers : 0)).'</td>';
		$this->resprints .= '<td class="right">'.((int) ($stats ? $stats->nb : 0)).'</td>';
		$this->resprints .= '<td class="right">'.price($stats ? (float) $stats->qty : 0, 1, $langs, 0, 0).'</td>';
		$this->resprints .= '</tr>';

		if (is_numeric($action)) {
			$action++;
		}

		return 0;
	}


	/**
	 * Inject svcwarranty and svcrequest into the "Link to..." dropdown on any
	 * native Dolibarr object card (orders, invoices, shipments, etc.).
	 *
	 * Form::showLinkToObjectBlock() fires this hook on the 'commonobject'
	 * context after building its hardcoded $possiblelinks array, passing
	 * 'listofidcompanytoscan' so we can scope results to the right customer.
	 * Returning 0 merges our entries into the existing array.
	 *
	 * @param  array       $parameters  Hook parameters (includes listofidcompanytoscan)
	 * @param  object      $object      Current page object
	 * @param  string      $action      Current action
	 * @param  HookManager $hookmanager Hook manager instance
	 * @return int                      0 = merge results into possiblelinks
	 */
	public function showLinkToObjectBlock($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $user;

		if (!isModEnabled('warrantysvc')) return 0;

		$listofidcompanytoscan = isset($parameters['listofidcompanytoscan'])
			? $parameters['listofidcompanytoscan']
			: '';

		if (empty($listofidcompanytoscan)) return 0;

		$sanitized = $db->sanitize($listofidcompanytoscan);
		$this->results = array();

		if ($user->hasRight('warrantysvc', 'svcwarranty', 'read')) {
			// Key must be 'module_element' form so showLinkedObjectBlock can resolve
			// the template path as /warrantysvc/svcwarranty/tpl/linkedobjectblock.tpl.php
			$this->results['warrantysvc_svcwarranty'] = array(
				'enabled' => 1,
				'perms'   => 1,
				'label'   => 'LinkToWarranty',
				'sql'     => $this->buildLinkToObjectSQL('svc_warranty', 'svcwarranty', $sanitized),
			);
		}

		if ($user->hasRight('warrantysvc', 'svcrequest', 'read')) {
			$this->results['warrantysvc_svcrequest'] = array(
				'enabled' => 1,
				'perms'   => 1,
				'label'   => 'LinkToServiceRequest',
				'sql'     => $this->buildLinkToObjectSQL('svc_request', 'svcrequest', $sanitized),
			);
		}

		// When viewing a warranty or service-request card, also offer calls as linkable.
		// 'pbxcalls_call' key produces tplpath=pbxcalls/call which resolves to
		// custom/pbxcalls/call/tpl/linkedobjectblock.tpl.php via dol_buildpath.
		if (isset($object->element) && in_array($object->element, array('svcwarranty', 'svcrequest'))
			&& isModEnabled('pbxcalls')
			&& $user->hasRight('pbxcalls', 'call', 'read')
		) {
			$this->results['pbxcalls_call'] = array(
				'enabled' => 1,
				'perms'   => 1,
				'label'   => 'LinkToCall',
				'sql'     => $this->buildLinkToObjectSQL('pbxcalls_call', 'call', $sanitized),
			);
		}

		return 0;
	}

	/**
	 * Build the SQL for a "Link to..." dropdown entry scoped to a company list.
	 *
	 * @param  string $table      DB table suffix (e.g. 'svc_request')
	 * @param  string $element    Element type string for getEntity() (e.g. 'svcrequest')
	 * @param  string $sanitized  Already-sanitized comma-separated company IDs
	 * @return string
	 */
	private function buildLinkToObjectSQL($table, $element, $sanitized)
	{
		return "SELECT s.rowid as socid, s.nom as name, s.client, t.rowid, t.ref FROM ".MAIN_DB_PREFIX."societe as s, ".MAIN_DB_PREFIX.$table." as t WHERE t.fk_soc = s.rowid AND t.fk_soc IN (".$sanitized.") AND t.entity IN (".getEntity($element).") ORDER BY t.ref";
	}

	/**
	 * Save per-product warranty defaults when a product record is updated.
	 *
	 * Fires on the 'doActions' hook for the 'productcard' context.
	 * Reads warrantysvc_type and warrantysvc_days from POST, deletes the
	 * existing row, and inserts a new one if a type is selected.
	 * Selecting the blank option (empty type) clears the default, allowing
	 * variant products to revert to inheriting from their parent.
	 *
	 * @param  array      $parameters  Hook parameters
	 * @param  object     $object      Current page object
	 * @param  string     $action      Current action
	 * @param  HookManager $hookmanager Hook manager instance
	 * @return int                     0 = continue normal product save
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user;

		if (!isModEnabled('warrantysvc')) return 0;
		if (!isset($object->element) || $object->element !== 'product') return 0;
		if ($action !== 'update') return 0;
		if (!$user->hasRight('warrantysvc', 'svcwarranty', 'write')) return 0;
		dol_include_once('/warrantysvc/lib/warrantysvc.lib.php');
		$duration_source = warrantysvc_get_duration_source();
		if ($duration_source !== 'warranty_type') return 0;

		$wtype = GETPOST('warrantysvc_type', 'alpha');
		$days  = GETPOSTINT('warrantysvc_days');

		// Clean slate — delete any existing default for this product+entity
		$this->db->query(
			"DELETE FROM ".MAIN_DB_PREFIX."warrantysvc_product_default WHERE fk_product = ".((int) $object->id)." AND entity = ".((int) $conf->entity)
		);

		if (!empty($wtype)) {
			$days_val = ($days > 0) ? ((int) $days) : 'NULL';
			$this->db->query(
				"INSERT INTO ".MAIN_DB_PREFIX."warrantysvc_product_default (fk_product, entity, warranty_type, coverage_days, date_creation, fk_user_creat) VALUES (".((int) $object->id).", ".((int) $conf->entity).", '".$this->db->escape($wtype)."', ".$days_val.", '".$this->db->idate(dol_now())."', ".((int) $user->id).")"
			);
		}
		// Empty $wtype = variant clearing override to inherit from parent → row stays deleted

		return 0; // Let the normal product save continue uninterrupted
	}

	/**
	 * Register WarrantySvc business events with Dolibarr's standard
	 * Notification module.
	 *
	 * @param  array       $parameters  Hook parameters
	 * @param  object      $object      Current object
	 * @param  string      $action      Current action
	 * @param  HookManager $hookmanager Hook manager
	 * @return int                      0 = merge hook results
	 */
	public function notifsupported($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		if (!isModEnabled('warrantysvc')) {
			return 0;
		}

		$langs->load('warrantysvc@warrantysvc');
		$this->prepareNotificationObjectConfig($conf);

		$this->results = array(
			'arrayofnotifsupported' => array(
				'WARRANTYSVC_ASSIGNED',
				'SVCWARRANTY_CREATE',
			),
		);

		return 0;
	}

	/**
	 * Remove the generic amount-threshold editor from WarrantySvc fixed-email
	 * notification rows. Dolibarr 23 renders a net-amount threshold for almost
	 * every fixed notification event, but Service Requests and Warranties do
	 * not have an amount semantic. The backend remains the core Notification
	 * module; this hook only removes an irrelevant UI field and therefore lets
	 * Dolibarr persist the standard threshold value as zero.
	 *
	 * @param  array       $parameters  Hook parameters
	 * @param  object|null $object      Current object
	 * @param  string      $action      Current action
	 * @param  HookManager $hookmanager Hook manager
	 * @return int                      0 = continue
	 */
	public function addHtmlHeader($parameters, &$object, &$action, $hookmanager)
	{
		$script = isset($_SERVER['PHP_SELF']) ? basename($_SERVER['PHP_SELF']) : '';
		if ($script !== 'notification.php') {
			return 0;
		}

		$codes = array(
			'WARRANTYSVC_ASSIGNED',
			'SVCWARRANTY_CREATE',
		);

		$this->resprints = '<script nonce="'.getNonce().'">';
		$this->resprints .= 'document.addEventListener("DOMContentLoaded",function(){';
		$this->resprints .= 'var codes='.json_encode(array_fill_keys($codes, 1)).';';
		$this->resprints .= 'document.querySelectorAll("table.noborder tr").forEach(function(row){';
		$this->resprints .= 'var cells=row.cells;if(!cells||cells.length<5){return;}';
		$this->resprints .= 'var code=(cells[1].textContent||"").trim();';
		$this->resprints .= 'if(!codes[code]){return;}';
		$this->resprints .= 'if(!row.querySelector("input[name$=\"_amount\"]")){return;}';
		$this->resprints .= 'cells[4].innerHTML="<span class=\"opacitymedium\">—</span>";';
		$this->resprints .= '});';
		$this->resprints .= '});';
		$this->resprints .= '</script>';

		return 0;
	}

	/**
	 * Add WarrantySvc object types to the standard Dolibarr Email Templates UI.
	 *
	 * @param  array       $parameters  Hook parameters
	 * @param  object      $object      Current object
	 * @param  string      $action      Current action
	 * @param  HookManager $hookmanager Hook manager
	 * @return int                      0 = merge hook results
	 */
	public function emailElementlist($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!isModEnabled('warrantysvc')) {
			return 0;
		}

		$langs->load('warrantysvc@warrantysvc');
		$this->results = array();

		if ($user->hasRight('warrantysvc', 'svcrequest', 'read')) {
			$this->results['svcrequest'] = img_picto('', 'technic', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('MailToSvcRequest'));
		}
		if ($user->hasRight('warrantysvc', 'svcwarranty', 'read')) {
			$this->results['svcwarranty'] = img_picto('', 'bill', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('MailToSvcWarranty'));
		}
		if ($user->hasRight('warrantysvc', 'supplierrma', 'read')) {
			$this->results['svcsupplierrma'] = img_picto('', 'tools', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('MailToSupplierRma'));
		}
		if ($user->hasRight('warrantysvc', 'supplierreturn', 'read')) {
			$this->results['svcsupplierreturn'] = img_picto('', 'shipment', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('MailToSupplierReturn'));
		}

		return 0;
	}

	/**
	 * The core Notification class uses CommonObject::$element to resolve the
	 * output directory for custom events. WarrantySvc keeps 'svcrequest' and
	 * 'svcwarranty' as its element names, while Dolibarr creates the module
	 * output configuration under 'warrantysvc'. Provide lightweight aliases
	 * only while the notification hook is active; no core patch is required.
	 *
	 * @param Conf $conf Dolibarr configuration
	 * @return void
	 */
	private function prepareNotificationObjectConfig($conf)
	{
		$baseDir = DOL_DATA_ROOT.'/warrantysvc';
		$multiDir = array((int) $conf->entity => $baseDir);

		if (isset($conf->warrantysvc) && is_object($conf->warrantysvc)) {
			if (!empty($conf->warrantysvc->dir_output)) {
				$baseDir = $conf->warrantysvc->dir_output;
			}
			if (!empty($conf->warrantysvc->multidir_output) && is_array($conf->warrantysvc->multidir_output)) {
				$multiDir = $conf->warrantysvc->multidir_output;
			} else {
				$multiDir[(int) $conf->entity] = $baseDir;
			}
		}

		foreach (array('svcrequest', 'svcwarranty', 'svcsupplierrma', 'svcsupplierreturn') as $element) {
			if (!isset($conf->{$element}) || !is_object($conf->{$element})) {
				$conf->{$element} = new stdClass();
			}
			$conf->{$element}->enabled = 1;
			$conf->{$element}->dir_output = $baseDir;
			$conf->{$element}->multidir_output = $multiDir;
		}
	}

}
