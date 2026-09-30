<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    warranty_card.php
 * \ingroup warrantysvc
 * \brief   Warranty create / view / edit card
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantytype.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies', 'products'));

$id     = GETPOST('id', 'int');
$ref    = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'alpha');
$duration_source = warrantysvc_get_duration_source();

$object = new SvcWarranty($db);
$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label($object->table_element);

if ($id > 0 || $ref) {
	$result = $object->fetch($id, $ref);
	if ($result <= 0) {
		dol_print_error($db, $object->error);
		exit;
	}
}

$permread   = $user->hasRight('warrantysvc', 'svcwarranty', 'read');
$permwrite  = $user->hasRight('warrantysvc', 'svcwarranty', 'write');
$permdelete = $user->hasRight('warrantysvc', 'svcwarranty', 'delete');

if (!$permread) { accessforbidden(); }

/*
 * Actions
 */
if ($cancel) {
	if ($id > 0) {
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	} else {
		header('Location: '.dol_buildpath('/warrantysvc/warranty_list.php', 1));
	}
	exit;
}

// ---- CREATE ----
if ($action == 'add' && $permwrite) {
	$object->fk_soc         = GETPOST('fk_soc', 'int');
	$object->fk_product     = GETPOST('fk_product', 'int');
	$object->serial_number  = GETPOST('serial_number', 'alpha');
	$object->warranty_type  = $duration_source === 'warranty_type' ? GETPOST('warranty_type', 'alpha') : '';
	$object->start_date     = dol_mktime(12, 0, 0, GETPOST('start_datemonth', 'int'), GETPOST('start_dateday', 'int'), GETPOST('start_dateyear', 'int'));
	$object->coverage_days= GETPOST('coverage_days', 'int');
	$object->coverage_terms = $duration_source === 'warranty_type' ? GETPOST('coverage_terms', 'restricthtml') : '';
	$object->exclusions     = $duration_source === 'warranty_type' ? GETPOST('exclusions', 'restricthtml') : '';
	$object->fk_commande    = GETPOST('fk_commande', 'int');
	$object->fk_expedition  = GETPOST('fk_expedition', 'int');
	$object->fk_expeditiondet = GETPOST('fk_expeditiondet', 'int');
	$posted_covered_qty = price2num(GETPOST('covered_qty', 'alphanohtml'), 'MS');
	if ($posted_covered_qty > 0) {
		$object->covered_qty = $posted_covered_qty;
	}
	$object->note_public    = GETPOST('note_public', 'restricthtml');
	$object->note_private   = GETPOST('note_private', 'restricthtml');

	// When this warranty comes from the shipment-item wizard, resolve the
	// physical item again from the database. Hidden Product/serial/quantity
	// fields are display helpers only and must not be trusted.
	$shipment_item_key = GETPOST('shipment_item', 'alphanohtml');
	if ($shipment_item_key !== '' && $object->fk_expedition > 0) {
		$shipment_item_error = '';
		$shipment_item = warrantysvc_resolve_shipment_item(
			$db,
			(int) $object->fk_expedition,
			$shipment_item_key,
			$shipment_item_error
		);
		if ($shipment_item === null) {
			$object->error = $shipment_item_error !== '' ? $shipment_item_error : $langs->trans('ErrorInvalidShipmentItem');
		} else {
			$object->fk_soc = (int) $shipment_item['fk_soc'];
			$object->fk_product = (int) $shipment_item['fk_product'];
			$object->fk_expeditiondet = (int) $shipment_item['fk_expeditiondet'];
			$object->serial_number = (string) $shipment_item['serial_number'];
			$object->covered_qty = (float) $shipment_item['covered_qty'];
			$object->start_date = (int) $shipment_item['start_date'];
		}
	}

	if ($duration_source === 'product_field' && empty($object->error)) {
		$period_error = '';
		$period = warrantysvc_compute_product_warranty_period(
			$db,
			(int) $object->fk_product,
			(int) $conf->entity,
			(int) $object->start_date,
			$period_error
		);
		if ($period_error !== '') {
			$object->error = $period_error;
		} elseif ($period === null) {
			$object->error = $langs->trans('ErrorProductWarrantyPeriodMissing');
		} else {
			$object->coverage_months = $period['months'];
			$object->coverage_days = $period['days'];
			$object->expiry_date = $period['expiry'];
		}
	} else {
		// Preserve upstream behaviour: an explicit expiry date overrides day-based coverage.
		$manual_expiry = dol_mktime(12, 0, 0, GETPOST('expiry_datemonth', 'int'), GETPOST('expiry_dateday', 'int'), GETPOST('expiry_dateyear', 'int'));
		if ($manual_expiry) {
			$object->expiry_date = $manual_expiry;
		}
	}

	// Warranty Type mode keeps the upstream terms/exclusions template behaviour.
	if ($duration_source === 'warranty_type' && !empty($object->warranty_type)) {
		$type = new SvcWarrantyType($db);
		if ($type->fetch(0, $object->warranty_type) > 0) {
			if (empty($object->coverage_terms)) {
				$object->coverage_terms = $type->coverage_terms;
			}
			if (empty($object->exclusions)) {
				$object->exclusions = $type->exclusions;
			}
		}
	}

	// Retrieve extrafields from POST
	$extrafields->setOptionalsFromPost(null, $object);

	$result = empty($object->error) ? $object->create($user) : -1;
	if ($result > 0) {
		if ($object->fk_expedition > 0) {
			$object->add_object_linked('expedition', $object->fk_expedition);
		}
		if ($object->fk_commande > 0) {
			$object->add_object_linked('commande', $object->fk_commande);
			// Discover and link invoices tied to this order
			$sql_inv = "SELECT fk_target FROM ".MAIN_DB_PREFIX."element_element WHERE fk_source = ".((int) $object->fk_commande)." AND sourcetype = 'commande' AND targettype = 'facture'";
			$res_inv = $db->query($sql_inv);
			if ($res_inv) {
				while ($row_inv = $db->fetch_object($res_inv)) {
					$object->add_object_linked('facture', (int) $row_inv->fk_target);
				}
			}
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$result);
		exit;
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
		$action = 'create';
	}
}

// ---- UPDATE ----
if ($action == 'update' && $permwrite) {
	$existing_warranty_type = $object->warranty_type;
	$existing_coverage_terms = $object->coverage_terms;
	$existing_exclusions = $object->exclusions;
	$existing_coverage_months = (int) $object->coverage_months;
	$existing_product_id = (int) $object->fk_product;
	$object->fk_soc         = GETPOST('fk_soc', 'int');
	$object->fk_product     = GETPOST('fk_product', 'int');
	$object->serial_number  = GETPOST('serial_number', 'alpha');
	$object->warranty_type  = $duration_source === 'warranty_type' ? GETPOST('warranty_type', 'alpha') : $existing_warranty_type;
	$object->start_date     = dol_mktime(12, 0, 0, GETPOST('start_datemonth', 'int'), GETPOST('start_dateday', 'int'), GETPOST('start_dateyear', 'int'));
	$object->coverage_days  = $duration_source === 'warranty_type' ? GETPOST('coverage_days', 'int') : $object->coverage_days;
	$object->coverage_terms = $duration_source === 'warranty_type' ? GETPOST('coverage_terms', 'restricthtml') : $existing_coverage_terms;
	$object->exclusions     = $duration_source === 'warranty_type' ? GETPOST('exclusions', 'restricthtml') : $existing_exclusions;
	$object->fk_commande    = GETPOST('fk_commande', 'int');
	$object->fk_expedition  = GETPOST('fk_expedition', 'int');
	$object->note_public    = GETPOST('note_public', 'restricthtml');
	$object->note_private   = GETPOST('note_private', 'restricthtml');

	if ($duration_source === 'product_field') {
		$months = ($existing_product_id === (int) $object->fk_product) ? $existing_coverage_months : 0;
		if ($months <= 0) {
			$period_error = '';
			$period = warrantysvc_compute_product_warranty_period(
				$db,
				(int) $object->fk_product,
				(int) $conf->entity,
				(int) $object->start_date,
				$period_error
			);
			if ($period_error !== '') {
				$object->error = $period_error;
			} elseif ($period === null) {
				$object->error = $langs->trans('ErrorProductWarrantyPeriodMissing');
			} else {
				$months = $period['months'];
			}
		}
		if (empty($object->error) && $months > 0) {
			$expiry = warrantysvc_add_months_clamped((int) $object->start_date, $months);
			$days = $expiry ? warrantysvc_calendar_days_between((int) $object->start_date, (int) $expiry) : null;
			if ($expiry === null || $days === null) {
				$object->error = $langs->trans('ErrorWarrantyCalendarExpiry');
			} else {
				$object->coverage_months = $months;
				$object->coverage_days = $days;
				$object->expiry_date = $expiry;
			}
		}
	} else {
		$object->coverage_months = null;
		$manual_expiry = dol_mktime(12, 0, 0, GETPOST('expiry_datemonth', 'int'), GETPOST('expiry_dateday', 'int'), GETPOST('expiry_dateyear', 'int'));
		if ($manual_expiry) {
			$object->expiry_date = $manual_expiry;
		} elseif ($object->coverage_days && $object->start_date) {
			$object->expiry_date = dol_time_plus_duree($object->start_date, $object->coverage_days, 'd');
		}
	}

	// Retrieve extrafields from POST
	$extrafields->setOptionalsFromPost(null, $object);

	$result = empty($object->error) ? $object->update($user) : -1;
	if ($result > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
		$action = 'edit';
	}
}

// ---- VOID ----
if ($action == 'confirm_void' && GETPOST('confirm', 'alpha') == 'yes' && $permwrite) {
	$object->status = SvcWarranty::STATUS_VOIDED;
	$object->update($user);
	setEventMessages($langs->trans('WarrantyVoided'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

// ---- DELETE ----
if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes' && $permdelete) {
	$result = $object->delete($user);
	if ($result > 0) {
		header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_list.php');
		exit;
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
}

$form        = new Form($db);
$formcompany = new FormCompany($db);

/*
 * View
 */
llxHeader('', $langs->trans('Warranty'), '');

// ============================================================
// CREATE FROM SHIPMENT FORM
// ============================================================
if ($action == 'create_from_shipment') {
	require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	$fk_expedition_src = GETPOST('fk_expedition_src', 'int');

	print load_fiche_titre($langs->trans('NewWarrantyFromShipment'), '', 'bill');
	print '<p><a href="'.$_SERVER['PHP_SELF'].'?action=create">'.img_picto('', 'back', 'class="paddingright"').$langs->trans('SwitchToManualWarranty').'</a></p>';

	// Build validated shipment list
	$sql_exp  = "SELECT e.rowid, e.ref, s.nom as customer_name";
	$sql_exp .= " FROM ".MAIN_DB_PREFIX."expedition e";
	$sql_exp .= " LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = e.fk_soc";
	$sql_exp .= " WHERE e.fk_statut >= 1";
	$sql_exp .= " AND e.entity IN (".getEntity('expedition').")";
	$sql_exp .= " ORDER BY e.rowid DESC LIMIT 500";
	$res_exp  = $db->query($sql_exp);
	$exp_options = array('' => '— '.$langs->trans('SelectShipment').' —');
	if ($res_exp) {
		while ($obj_exp = $db->fetch_object($res_exp)) {
			$exp_options[$obj_exp->rowid] = $obj_exp->ref.($obj_exp->customer_name ? ' — '.$obj_exp->customer_name : '');
		}
	}

	// Step 1 — shipment selector (GET reload)
	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="action" value="create_from_shipment">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('SelectShipmentStep').'</td></tr>';
	print '<tr class="oddeven"><td class="fieldrequired titlefieldcreate">'.$langs->trans('Shipment').'</td>';
	print '<td>';
	print Form::selectarray('fk_expedition_src', $exp_options, $fk_expedition_src, 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth300');
	print ' <input type="submit" class="button small" value="'.$langs->trans('Load').'">';
	print '</td></tr>';
	print '</table>';
	print '</form>';

	// Step 2 — warranty form (only once a shipment is selected)
	if ($fk_expedition_src > 0) {
		$expedition = new Expedition($db);
		$presoc     = ($expedition->fetch($fk_expedition_src) > 0) ? (int) $expedition->socid : 0;
		$shipment_start_date = warrantysvc_resolve_shipment_start_date($db, $expedition);
		if ($shipment_start_date === null) {
			setEventMessages($langs->trans('ErrorShipmentWarrantyStartDateMissing'), null, 'errors');
		}

		// Build one selectable warranty candidate for each physical shipment item:
		// one row per serial/lot allocation, or one row per ordinary shipment line.
		// Keep core shipment serials and module warranty serials in separate SQL
		// queries to avoid cross-collation comparisons on existing databases.
		$item_options = array('' => '— '.$langs->trans('SelectShipmentItem').' —');
		$item_map = array();
		$item_query_error = ($shipment_start_date === null);
		$res_items = false;

		if (!$item_query_error) {
			$sql_items  = "SELECT ed.rowid AS fk_expeditiondet, ed.fk_product, ed.qty AS line_qty,";
			$sql_items .= " edl.rowid AS fk_expeditiondet_batch, edl.batch AS serial_number, edl.qty AS batch_qty,";
			$sql_items .= " p.ref AS product_ref, p.label AS product_label";
			$sql_items .= " FROM ".MAIN_DB_PREFIX."expeditiondet ed";
			$sql_items .= " LEFT JOIN ".MAIN_DB_PREFIX."expeditiondet_batch edl ON edl.fk_expeditiondet = ed.rowid";
			$sql_items .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = ed.fk_product";
			$sql_items .= " WHERE ed.fk_expedition = ".((int) $fk_expedition_src);
			$sql_items .= " AND p.fk_product_type = 0";
			if (getDolGlobalInt('WARRANTYSVC_WARRANTY_REQUIRES_LOTS')) {
				$sql_items .= " AND p.tobatch > 0";
			}
			$sql_items .= " ORDER BY ed.rowid ASC, edl.rowid ASC";
			$res_items = $db->query($sql_items);
		}
		$covered_serials = array();
		$covered_lines = array();

		if ($item_query_error) {
			// The specific error message has already been queued above.
		} elseif (!$res_items) {
			$item_query_error = true;
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			// Only warranties already originating from this shipment make a current
			// shipment item unavailable. Older warranties from previous sales must
			// not hide a legitimately resold serial.
			$sql_cov  = "SELECT fk_product, serial_number, fk_expeditiondet";
			$sql_cov .= " FROM ".MAIN_DB_PREFIX."svc_warranty";
			$sql_cov .= " WHERE fk_expedition = ".((int) $fk_expedition_src);
			$sql_cov .= " AND status != 'voided'";
			$sql_cov .= " AND entity IN (".getEntity('svcwarranty').")";
			$res_cov = $db->query($sql_cov);
			if (!$res_cov) {
				$item_query_error = true;
				setEventMessages($db->lasterror(), null, 'errors');
			} else {
				while ($obj_cov = $db->fetch_object($res_cov)) {
					$covered_serial = trim((string) $obj_cov->serial_number);
					if ($covered_serial !== '') {
						$covered_serials[((int) $obj_cov->fk_product).'\\0'.$covered_serial] = true;
					} elseif (!empty($obj_cov->fk_expeditiondet)) {
						$covered_lines[(int) $obj_cov->fk_expeditiondet] = true;
					}
				}

				while ($obj_item = $db->fetch_object($res_items)) {
					$serial_number = trim((string) $obj_item->serial_number);
					$has_serial = ($serial_number !== '');
					$covered_qty = $has_serial ? (float) $obj_item->batch_qty : (float) $obj_item->line_qty;
					if ($covered_qty <= 0) {
						continue;
					}

					if ($has_serial) {
						$covered_key = ((int) $obj_item->fk_product).'\\0'.$serial_number;
						if (isset($covered_serials[$covered_key])) {
							continue;
						}
						$item_key = 'b:'.((int) $obj_item->fk_expeditiondet_batch);
					} else {
						if (isset($covered_lines[(int) $obj_item->fk_expeditiondet])) {
							continue;
						}
						$item_key = 'l:'.((int) $obj_item->fk_expeditiondet);
					}

					$product_label = $obj_item->product_ref.($obj_item->product_label ? ' — '.$obj_item->product_label : '');
					$item_label = $has_serial
						? $serial_number.' — '.$product_label
						: $product_label.' — '.$langs->trans('NoSerialShipmentItem', price($covered_qty));

					$product_months = 0;
					$product_coverage_days = 0;
					if ($duration_source === 'product_field') {
						$period_error = '';
						$period = warrantysvc_compute_product_warranty_period(
							$db,
							(int) $obj_item->fk_product,
							(int) $conf->entity,
							(int) $shipment_start_date,
							$period_error
						);
						if ($period_error !== '') {
							$item_query_error = true;
							setEventMessages($period_error, null, 'errors');
							break;
						}
						if ($period !== null) {
							$product_months = (int) $period['months'];
							$product_coverage_days = (int) $period['days'];
						}
					}

					$item_options[$item_key] = $item_label;
					$item_map[$item_key] = array(
						'fk_product' => (int) $obj_item->fk_product,
						'fk_expeditiondet' => (int) $obj_item->fk_expeditiondet,
						'serial_number' => $has_serial ? $serial_number : '',
						'covered_qty' => $covered_qty,
						'label' => $product_label,
						'coverage_months' => $product_months,
						'coverage_days' => $product_coverage_days,
					);
				}
			}
		}

		$item_map_js = json_encode($item_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($item_map_js === false) {
			$item_map_js = '{}';
			$item_query_error = true;
			setEventMessages($langs->trans('Error'), null, 'errors');
		}

		if ($item_query_error) {
			print '<div class="error" style="margin-top:10px">'.$langs->trans('Error').'</div>';
		} elseif (count($item_options) <= 1) {
			print '<div class="warning" style="margin-top:10px">'.$langs->trans('NoUncoveredItemsInShipment').'</div>';
		} else {
			$wtype_items       = array();
			$wtype_options     = array();
			$wtype_defaults_js = '{}';
			$selected_wtype    = '';
			$initial_days      = 0;
			$days_disabled     = '';
			if ($duration_source === 'warranty_type') {
				$wtype_items       = SvcWarrantyType::fetchAllForForm($db);
				$wtype_options     = array('' => '— '.$langs->trans('NoPredefinedType').' —');
				$wtype_defaults_js = '{';
				foreach ($wtype_items as $wt) {
					$wtype_options[$wt->code] = dol_escape_htmltag($wt->label);
					$wtype_defaults_js .= '"'.dol_escape_js($wt->code).'":'.((int) $wt->default_coverage_days).',';
				}
				$wtype_defaults_js = rtrim($wtype_defaults_js, ',').'}';
				$selected_wtype    = GETPOST('warranty_type', 'alpha');
				$initial_days      = 365;
				foreach ($wtype_items as $wt) {
					if ($wt->code === $selected_wtype) { $initial_days = (int) $wt->default_coverage_days; break; }
				}
				$days_disabled = ($selected_wtype ? ' readonly style="opacity:0.5"' : '');
			}

			print '<br>';
			print '<form action="'.$_SERVER['PHP_SELF'].'" method="POST">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="add">';
			print '<input type="hidden" name="fk_soc" value="'.((int) $presoc).'">';
			print '<input type="hidden" name="fk_expedition" value="'.((int) $fk_expedition_src).'">';
			print '<input type="hidden" id="fk_product" name="fk_product" value="">';
			print '<input type="hidden" id="fk_expeditiondet" name="fk_expeditiondet" value="">';
			print '<input type="hidden" id="serial_number" name="serial_number" value="">';
			print '<input type="hidden" id="covered_qty" name="covered_qty" value="1">';

			print dol_get_fiche_head(array(), '', '', -1);
			print '<table class="border centpercent tableforfieldcreate">';

			// Customer — display only
			$soc = new Societe($db);
			print '<tr><td class="titlefieldcreate">'.$langs->trans('Customer').'</td>';
			print '<td>';
			if ($presoc && $soc->fetch($presoc) > 0) {
				print $soc->getNomUrl(1);
			}
			print '</td></tr>';

			// Shipment item dropdown: serialized/lot allocations and ordinary lines.
			print '<tr><td class="fieldrequired">'.$form->textwithpicto($langs->trans('ShipmentItem'), $langs->trans('TooltipShipmentItem')).'</td>';
			print '<td>';
			print Form::selectarray('shipment_item', $item_options, GETPOST('shipment_item', 'alpha'), 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth300', 0, '', '', true);
			print '</td></tr>';

			// Product — auto-filled by JS when the shipment item is chosen
			print '<tr><td>'.$langs->trans('Product').'</td>';
			print '<td><span id="product_label" class="opacitymedium">'.$langs->trans('AutoFilledFromShipmentItem').'</span></td></tr>';

			print '<tr id="serial_display_row" style="display:none"><td>'.$langs->trans('SvcSerialNumber').'</td>';
			print '<td><span id="serial_display"></span></td></tr>';

			// Warranty Type is upstream duration/policy UI and is hidden in Product-field mode.
			if ($duration_source === 'warranty_type') {
				print '<tr><td>'.$form->textwithpicto($langs->trans('WarrantyType'), $langs->trans('TooltipWarrantyType')).'</td>';
				print '<td>';
				print Form::selectarray('warranty_type', $wtype_options, $selected_wtype, 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth200', 0, '', '', true);
				print '</td></tr>';
			}

			// Shipment-origin warranties always start on the contractual shipment date.
			print '<tr><td>'.$langs->trans('StartDate').'</td>';
			print '<td><strong>'.dol_print_date($shipment_start_date, 'day').'</strong>';
			print ' <span class="opacitymedium">('.$langs->trans('WarrantyStartFromShipment').')</span></td></tr>';

			if ($duration_source === 'product_field') {
				print '<tr><td>'.$langs->trans('WarrantyDuration').'</td>';
				print '<td><input type="hidden" id="coverage_days" name="coverage_days" value="0">';
				print '<span id="coverage_auto_hint" class="opacitymedium">'.$langs->trans('SelectShipmentItemForWarrantyPeriod').'</span></td></tr>';
			} else {
				print '<tr><td>'.$form->textwithpicto($langs->trans('CoverageDays'), $langs->trans('TooltipCoverageDays')).'</td>';
				print '<td>';
				print '<input type="number" id="coverage_days" name="coverage_days" value="'.$initial_days.'" class="flat width75" min="1" max="3650"'.$days_disabled.'>';
				print ' '.$langs->trans('SvcDays');
				print ' &nbsp;<span id="coverage_auto_hint" class="opacitymedium"'.($selected_wtype ? '' : ' style="display:none"').'>'.$langs->trans('CoverageFromType').'</span>';
				print '</td></tr>';
			}

			// Notes
			print '<tr><td>'.$form->textwithpicto($langs->trans('NotePublic'), $langs->trans('TooltipNotePublic')).'</td>';
			print '<td><textarea name="note_public" class="flat" rows="3" style="width:90%" placeholder="'.$langs->trans('NotePublicPlaceholder').'"></textarea></td></tr>';

			// Extrafields on create
			print $object->showOptionals($extrafields, 'create');

			print '</table>';
			print dol_get_fiche_end();

			print '<div class="center">';
			print '<input type="submit" id="btn_save_ship" class="button button-save" name="add" value="'.$langs->trans('Save').'" disabled>';
			print ' &nbsp; ';
			print '<a class="button button-cancel" href="'.dol_buildpath('/warrantysvc/warranty_list.php', 1).'">'.$langs->trans('Cancel').'</a>';
			print '</div>';

			print '</form>';

			print '<script>(function(){
	var items = '.$item_map_js.';
	var wtdef = '.$wtype_defaults_js.';
	var usesProductMonths = '.($duration_source === 'product_field' ? 'true' : 'false').';
	var selItem = document.querySelector("[name=shipment_item]");
	var selType = document.querySelector("[name=warranty_type]");
	var inpProd = document.getElementById("fk_product");
	var inpExpDet = document.getElementById("fk_expeditiondet");
	var inpSerial = document.getElementById("serial_number");
	var inpQty = document.getElementById("covered_qty");
	var lblProd = document.getElementById("product_label");
	var serialRow = document.getElementById("serial_display_row");
	var serialDisplay = document.getElementById("serial_display");
	var inpCov = document.getElementById("coverage_days");
	var hint = document.getElementById("coverage_auto_hint");
	var btn = document.getElementById("btn_save_ship");
	var fromTypeText = "'.dol_escape_js($langs->transnoentitiesnoconv('CoverageFromType')).'";
	var fromProductText = "'.dol_escape_js($langs->transnoentitiesnoconv('CoverageFromProductMonths', '__MONTHS__')).'";
	var missingProductText = "'.dol_escape_js($langs->transnoentitiesnoconv('ProductWarrantyPeriodMissing')).'";

	function currentItem(){
		var key = selItem ? selItem.value : "";
		return (key && items[key]) ? items[key] : null;
	}

	function syncItem(){
		var item = currentItem();
		if(item){
			if(inpProd) inpProd.value = item.fk_product;
			if(inpExpDet) inpExpDet.value = item.fk_expeditiondet;
			if(inpSerial) inpSerial.value = item.serial_number || "";
			if(inpQty) inpQty.value = item.covered_qty;
			if(lblProd) lblProd.textContent = item.label;
			if(serialDisplay) serialDisplay.textContent = item.serial_number || "";
			if(serialRow) serialRow.style.display = item.serial_number ? "" : "none";
		} else {
			if(inpProd) inpProd.value = "";
			if(inpExpDet) inpExpDet.value = "";
			if(inpSerial) inpSerial.value = "";
			if(inpQty) inpQty.value = "1";
			if(lblProd) lblProd.textContent = "'.dol_escape_js($langs->transnoentitiesnoconv('AutoFilledFromShipmentItem')).'";
			if(serialDisplay) serialDisplay.textContent = "";
			if(serialRow) serialRow.style.display = "none";
		}
		syncDuration();
	}

	function syncDuration(){
		var item = currentItem();
		if(usesProductMonths){
			if(item && item.coverage_days > 0){
				inpCov.value = item.coverage_days;
				inpCov.readOnly = true;
				inpCov.style.opacity = "0.5";
				if(hint){ hint.textContent = fromProductText.replace("__MONTHS__", item.coverage_months); hint.style.display = ""; }
				if(btn) btn.disabled = false;
			} else {
				inpCov.value = "";
				inpCov.readOnly = true;
				inpCov.style.opacity = "0.5";
				if(hint){ hint.textContent = item ? missingProductText : "'.dol_escape_js($langs->transnoentitiesnoconv('SelectShipmentItemForWarrantyPeriod')).'"; hint.style.display = ""; }
				if(btn) btn.disabled = true;
			}
			return;
		}

		if(btn) btn.disabled = !item;
		var code = selType ? selType.value : "";
		if(code && wtdef[code] !== undefined){
			inpCov.value = wtdef[code];
			inpCov.readOnly = true;
			inpCov.style.opacity = "0.5";
			if(hint){ hint.textContent = fromTypeText; hint.style.display = ""; }
		} else {
			inpCov.readOnly = false;
			inpCov.style.opacity = "";
			if(hint) hint.style.display = "none";
		}
	}

	if(selItem) selItem.addEventListener("change", syncItem);
	if(selType) selType.addEventListener("change", syncDuration);
	syncItem();
})();</script>';
		}
	}

	llxFooter();
	$db->close();
	exit;
}

// ============================================================
// CREATE FORM
// ============================================================
if ($action == 'create') {
	// Standard mode products/serials are loaded via AJAX scoped to the selected customer.
	// Only override and manual modes need static product lists built server-side.
	$prev_mode    = GETPOST('warranty_mode', 'alpha');
	$prev_product = (int) GETPOST('fk_product', 'int');
	$prev_serial  = GETPOST('serial_number', 'alpha');
	$valid_modes  = array('standard', 'override');
	if (!in_array($prev_mode, $valid_modes)) {
		$prev_mode = 'standard';
	}
	$std_disabled = false; // Always available — populated via AJAX after customer selection
	$ovr_disabled = false; // Override is a manual escape hatch — always selectable

	// Override mode: all products from any validated shipment (not customer-scoped)
	$sql_ovr  = "SELECT DISTINCT p.rowid, p.ref, p.label FROM ".MAIN_DB_PREFIX."product p";
	$sql_ovr .= " INNER JOIN ".MAIN_DB_PREFIX."expeditiondet ed ON ed.fk_product = p.rowid";
	$sql_ovr .= " INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition";
	$sql_ovr .= " WHERE e.fk_statut >= 1";
	$sql_ovr .= " AND e.entity IN (".getEntity('expedition').")";
	$sql_ovr .= " AND p.entity IN (".getEntity('product').")";
	$sql_ovr .= " ORDER BY p.ref ASC";
	$res_ovr = $db->query($sql_ovr);
	$override_product_list = array();
	if ($res_ovr) {
		while ($row_ovr = $db->fetch_object($res_ovr)) {
			$override_product_list[$row_ovr->rowid] = $row_ovr->ref.($row_ovr->label ? ' — '.$row_ovr->label : '');
		}
	}

	print load_fiche_titre($langs->trans('NewWarranty'), '', 'bill');
	print '<p><a href="'.$_SERVER['PHP_SELF'].'?action=create_from_shipment">'.img_picto('', 'shipment', 'class="paddingright"').$langs->trans('CreateWarrantyFromShipment').'</a></p>';

	print '<form action="'.$_SERVER['PHP_SELF'].'" method="POST">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	// 0 = standard, 1 = override
	$ovr_flag = ($prev_mode === 'override') ? '1' : '0';
	print '<input type="hidden" name="warranty_override" id="warranty_override_val" value="'.$ovr_flag.'">';

	// Mode radio toggle
	print '<div style="padding:8px 0 12px 0">';
	print '<label style="margin-right:24px; cursor:pointer">';
	print '<input type="radio" name="warranty_mode" value="standard"'.($prev_mode === 'standard' ? ' checked' : '').($std_disabled ? ' disabled' : '').'> ';
	print dol_escape_htmltag($langs->trans('WarrantyModeStandard'));
	print '</label>';
	print '<label style="cursor:pointer">';
	print '<input type="radio" name="warranty_mode" value="override"'.($prev_mode === 'override' ? ' checked' : '').($ovr_disabled ? ' disabled' : '').'> ';
	print dol_escape_htmltag($langs->trans('WarrantyModeOverride'));
	print '</label>';
	print '</div>';

	print dol_get_fiche_head(array(), '', '', -1);
	print '<table class="border centpercent tableforfieldcreate">';

	// Override warning banner
	print '<tr id="row_warn"'.($prev_mode !== 'override' ? ' style="display:none"' : '').'>';
	print '<td colspan="2"><div class="warning" id="warn_msg">'.img_picto('', 'warning_white', 'class="paddingright"');
	print $langs->trans('WarrantyOverrideWarning');
	print '</div></td>';
	print '</tr>';

	// Customer (shared across all modes)
	print '<tr><td class="fieldrequired">'.$langs->trans('Customer').'</td>';
	print '<td>';
	print $formcompany->select_company(GETPOST('fk_soc', 'int'), 'fk_soc', '(s.client:IN:1,3)', $langs->trans('SelectThirdParty'), 0, 0, null, 0, 'minwidth300 maxwidth500 widthcentpercentminusxx');
	print '</td></tr>';

	// Product — Standard row (populated via AJAX after customer is selected)
	print '<tr id="row_std_product"'.($prev_mode !== 'standard' ? ' style="display:none"' : '').'>';
	print '<td class="fieldrequired">'.$langs->trans('Product').'</td><td>';
	print '<select name="fk_product" id="fk_product_std" class="minwidth300" disabled>';
	print '<option value="-1">'.dol_escape_htmltag($langs->trans('SelectCustomerFirst')).'</option>';
	print '</select>';
	print '</td></tr>';

	// Product — Override row (products with any validated shipment on record)
	print '<tr id="row_ovr_product"'.($prev_mode !== 'override' ? ' style="display:none"' : '').'>';
	print '<td class="fieldrequired">'.$langs->trans('Product').'</td><td>';
	print '<select name="fk_product" id="fk_product_ovr" class="minwidth300"'.($prev_mode !== 'override' ? ' disabled' : '').'>';
	print '<option value="">— '.$langs->trans('SelectProduct').' —</option>';
	foreach ($override_product_list as $ovr_pid => $ovr_plabel) {
		print '<option value="'.(int) $ovr_pid.'"'.($prev_product === (int) $ovr_pid ? ' selected' : '').'>'.dol_escape_htmltag($ovr_plabel).'</option>';
	}
	print '</select>';
	print '</td></tr>';

	// Serial — Standard row (select from product_lot)
	print '<tr id="row_std_serial"'.($prev_mode !== 'standard' ? ' style="display:none"' : '').'>';
	print '<td class="fieldrequired">'.$form->textwithpicto($langs->trans('SvcSerialNumber'), $langs->trans('TooltipWarrantySerial')).'</td><td>';
	print '<select name="serial_number" id="manual_serial_select" class="minwidth200" disabled>';
	print '<option value="">'.$langs->trans('SelectProductFirst').'</option>';
	print '</select>';
	print '</td></tr>';

	// Serial — Override row (free-text input)
	$show_free_ser = ($prev_mode === 'override');
	print '<tr id="row_free_serial"'.(!$show_free_ser ? ' style="display:none"' : '').'>';
	print '<td class="fieldrequired">'.$form->textwithpicto($langs->trans('SvcSerialNumber'), $langs->trans('TooltipWarrantySerial')).'</td><td>';
	print '<input type="text" name="serial_number" id="serial_number_free"';
	print ' class="flat minwidth200"';
	print ' placeholder="'.dol_escape_htmltag($langs->trans('SerialNumberOverridePlaceholder')).'"';
	print ' value="'.($show_free_ser ? dol_escape_htmltag($prev_serial) : '').'"';
	print (!$show_free_ser ? ' disabled' : '').'>';
	print '</td></tr>';

	$warn_ovr_txt  = dol_escape_js($langs->trans('WarrantyOverrideWarning'));
	$ajax_base     = dol_escape_js(DOL_URL_ROOT.'/custom/warrantysvc/ajax/');
	// Hidden FK fields must exist in DOM before the script block so getElementById finds them at load time.
	// syncOrder() and the override order input handler both reference these by ID.
	print '<input type="hidden" name="fk_commande" id="fk_commande_val" value="'.((int) GETPOST('fk_commande', 'int')).'">';
	print '<input type="hidden" name="fk_expedition" id="fk_expedition_val" value="'.((int) GETPOST('fk_expedition', 'int')).'">';

	$prev_socid    = (int) GETPOST('fk_soc', 'int');
	$std_prev_prod = ($prev_mode === 'standard') ? $prev_product : 0;
	$std_prev_ser  = ($prev_mode === 'standard') ? dol_escape_js($prev_serial) : '';
	print '<script>(function(){
var ajaxBase   = "'.$ajax_base.'";
var selCustTxt = "'.dol_escape_js($langs->transnoentitiesnoconv('SelectCustomerFirst')).'";
var selProdTxt = "'.dol_escape_js($langs->transnoentitiesnoconv('SelectProductFirst')).'";
var selSerTxt  = "— '.dol_escape_js($langs->transnoentitiesnoconv('SelectSerial')).' —";
var noSerTxt   = "'.dol_escape_js($langs->transnoentitiesnoconv('NoSerialsAvailable')).'";
var autoOrdTxt = "'.dol_escape_js($langs->transnoentitiesnoconv('AutoFilledFromSerial')).'";
var warnOvr    = "'.$warn_ovr_txt.'";
var prevProduct = '.(int) $std_prev_prod.';
var prevSerial  = "'.$std_prev_ser.'";

var stdProdSel = document.getElementById("fk_product_std");
var stdSerSel  = document.getElementById("manual_serial_select");
var stdProdRow = document.getElementById("row_std_product");
var stdSerRow  = document.getElementById("row_std_serial");
var ovrProdSel = document.getElementById("fk_product_ovr");
var ovrProdRow = document.getElementById("row_ovr_product");
var freeSerInp = document.getElementById("serial_number_free");
var freeSerRow = document.getElementById("row_free_serial");
var warnRow    = document.getElementById("row_warn");
var warnMsg    = document.getElementById("warn_msg");
var ovrValInp  = document.getElementById("warranty_override_val");
var fkComVal   = document.getElementById("fk_commande_val");
var fkExpVal   = document.getElementById("fk_expedition_val");
var ordRefSpan = document.getElementById("origin_order_ref");
var ordStdRow  = document.getElementById("origin_order_std_row");
var ordManRow  = document.getElementById("origin_order_man_row");
var ordManInp  = document.getElementById("fk_commande_ovr_input");

function getCurrentSocid() {
	var s = document.querySelector("[name=fk_soc]");
	return s ? parseInt(s.value, 10) : 0;
}

function clearOrder() {
	if (fkComVal) fkComVal.value = "";
	if (fkExpVal) fkExpVal.value = "";
	if (ordRefSpan) { ordRefSpan.textContent = autoOrdTxt; ordRefSpan.classList.add("opacitymedium"); }
}

function syncOrder() {
	if (!stdSerSel) return;
	var opt = stdSerSel.options[stdSerSel.selectedIndex];
	if (!opt || !opt.value) { clearOrder(); updateDateLink(null); return; }
	var comId  = opt.dataset.fkCommande   || 0;
	var expId  = opt.dataset.fkExpedition || 0;
	var comRef = opt.dataset.commandeRef  || "";
	if (fkComVal) fkComVal.value = comId;
	if (fkExpVal) fkExpVal.value = expId;
	if (ordRefSpan) {
		ordRefSpan.textContent = comRef ? comRef : autoOrdTxt;
		if (comRef) ordRefSpan.classList.remove("opacitymedium");
		else ordRefSpan.classList.add("opacitymedium");
	}
	updateDateLink(opt);
}

function updateDateLink(opt) {
	var el = document.getElementById("origin_date_link");
	if (!el) return;
	var dateStr  = opt ? (opt.dataset.originDate  || "") : "";
	var labelStr = opt ? (opt.dataset.originLabel || "") : "";
	var day   = opt ? parseInt(opt.dataset.originDay, 10)   : 0;
	var month = opt ? parseInt(opt.dataset.originMonth, 10) : 0;
	var year  = opt ? parseInt(opt.dataset.originYear, 10)  : 0;
	var input = opt ? (opt.dataset.originInput || "")       : "";
	if (!dateStr || !day) { el.style.display = "none"; el.textContent = ""; return; }
	el.style.display = "";
	el.textContent = labelStr + " (" + dateStr + ")";
	el.onclick = function() {
		jQuery("#start_dateday").val(day);
		jQuery("#start_datemonth").val(month);
		jQuery("#start_dateyear").val(year);
		jQuery("#start_date").val(input);
	};
}

function resetSerials() {
	if (!stdSerSel) return;
	stdSerSel.innerHTML = "";
	var o = document.createElement("option"); o.value = ""; o.textContent = selProdTxt;
	stdSerSel.appendChild(o);
	stdSerSel.disabled = true;
	clearOrder();
}

function loadWarrantySerials(socid, pid) {
	if (!stdSerSel) return;
	pid = parseInt(pid, 10);
	if (!pid || pid <= 0) { resetSerials(); return; }
	stdSerSel.innerHTML = "";
	stdSerSel.disabled = true;
	var loadOpt = document.createElement("option"); loadOpt.value = ""; loadOpt.textContent = "Loading...";
	stdSerSel.appendChild(loadOpt);
	fetch(ajaxBase + "warranty_serials.php?socid=" + socid + "&fk_product=" + pid)
		.then(function(r){ return r.json(); })
		.then(function(serials){
			stdSerSel.innerHTML = "";
			if (!serials.length) {
				var o = document.createElement("option"); o.value = ""; o.textContent = noSerTxt;
				stdSerSel.appendChild(o); stdSerSel.disabled = true; clearOrder(); return;
			}
			var blank = document.createElement("option"); blank.value = ""; blank.textContent = selSerTxt;
			stdSerSel.appendChild(blank);
			serials.forEach(function(s){
				var o = document.createElement("option");
				o.value = s.serial; o.textContent = s.serial;
				o.dataset.fkCommande   = s.fk_commande   || 0;
				o.dataset.fkExpedition = s.fk_expedition || 0;
				o.dataset.commandeRef  = s.commande_ref  || "";
				o.dataset.expeditionRef = s.expedition_ref || "";
				o.dataset.originDate   = s.origin_date   || "";
				o.dataset.originLabel  = s.origin_label  || "";
				o.dataset.originDay    = s.origin_day    || 0;
				o.dataset.originMonth  = s.origin_month  || 0;
				o.dataset.originYear   = s.origin_year   || 0;
				o.dataset.originInput  = s.origin_input  || "";
				stdSerSel.appendChild(o);
			});
			stdSerSel.disabled = false;
			if (prevSerial) { stdSerSel.value = prevSerial; prevSerial = ""; }
			syncOrder();
		})
		.catch(function(){ resetSerials(); });
}

function loadWarrantyProducts(socid) {
	if (!stdProdSel) return;
	socid = parseInt(socid, 10);
	stdProdSel.innerHTML = "";
	stdProdSel.disabled = true;
	if (!socid || socid <= 0) {
		var o = document.createElement("option"); o.value = "-1"; o.textContent = selCustTxt;
		stdProdSel.appendChild(o); resetSerials(); return;
	}
	var loadOpt = document.createElement("option"); loadOpt.value = "-1"; loadOpt.textContent = "Loading...";
	stdProdSel.appendChild(loadOpt);
	fetch(ajaxBase + "warranty_products.php?socid=" + socid)
		.then(function(r){ return r.json(); })
		.then(function(products){
			stdProdSel.innerHTML = "";
			var blank = document.createElement("option"); blank.value = "-1"; blank.textContent = "— " + selProdTxt + " —";
			stdProdSel.appendChild(blank);
			products.forEach(function(p){
				var o = document.createElement("option"); o.value = p.rowid; o.textContent = p.label;
				stdProdSel.appendChild(o);
			});
			stdProdSel.disabled = (products.length === 0);
			if (prevProduct > 0) {
				stdProdSel.value = prevProduct;
				prevProduct = 0;
				var selectedPid = parseInt(stdProdSel.value, 10);
				if (selectedPid > 0) { loadWarrantySerials(socid, selectedPid); return; }
			}
			resetSerials();
		})
		.catch(function(){ stdProdSel.disabled = false; });
}

function setMode(mode) {
	var isStd = (mode === "standard");
	var isOvr = (mode === "override");
	// Product rows
	if (stdProdRow) stdProdRow.style.display = isStd ? "" : "none";
	if (ovrProdRow) ovrProdRow.style.display = isOvr ? "" : "none";
	// Serial rows
	if (stdSerRow)  stdSerRow.style.display  = isStd ? "" : "none";
	if (freeSerRow) freeSerRow.style.display  = isOvr ? "" : "none";
	// Warning row
	if (warnRow) warnRow.style.display = isOvr ? "" : "none";
	if (warnMsg) warnMsg.innerHTML = warnOvr;
	// Origin order rows
	if (ordStdRow) ordStdRow.style.display = isStd ? "" : "none";
	if (ordManRow) ordManRow.style.display = isOvr ? "" : "none";
	// Enable/disable (disabled fields do not submit)
	if (!isStd && stdProdSel) stdProdSel.disabled = true;
	if (!isStd && stdSerSel)  stdSerSel.disabled = true;
	if (ovrProdSel) ovrProdSel.disabled = !isOvr;
	if (freeSerInp) freeSerInp.disabled = isStd;
	if (ordManInp)  ordManInp.disabled  = isStd;
	// Hidden flag
	if (ovrValInp) ovrValInp.value = isOvr ? "1" : "0";
	// Entering standard: clear order if no serial selected
	if (isStd) {
		var socid = getCurrentSocid();
		if (socid > 0 && stdProdSel && (!stdProdSel.options.length || stdProdSel.options[0].value == "-1")) {
			loadWarrantyProducts(socid);
		}
	}
}

// Customer select (Select2)
if (typeof jQuery !== "undefined") {
	jQuery(document).on("select2:select", "[name=fk_soc]", function(e) {
		var socid = e.params && e.params.data ? parseInt(e.params.data.id, 10) : getCurrentSocid();
		var mode = (document.querySelector("[name=warranty_mode]:checked") || {}).value || "standard";
		if (mode === "standard") loadWarrantyProducts(socid);
	});
	jQuery(document).on("select2:clear", "[name=fk_soc]", function() {
		var mode = (document.querySelector("[name=warranty_mode]:checked") || {}).value || "standard";
		if (mode === "standard") loadWarrantyProducts(0);
	});
}

// Standard product select
if (stdProdSel) {
	stdProdSel.addEventListener("change", function() {
		loadWarrantySerials(getCurrentSocid(), this.value);
	});
}

// Standard serial select
if (stdSerSel) { stdSerSel.addEventListener("change", syncOrder); }

// Override/Manual manual order input → sync hidden field
if (ordManInp) {
	ordManInp.addEventListener("input", function() {
		if (fkComVal) fkComVal.value = this.value;
	});
}

// Mode radios
document.querySelectorAll("[name=warranty_mode]").forEach(function(r) {
	r.addEventListener("change", function() { setMode(this.value); });
});

// Init
var initRadio = document.querySelector("[name=warranty_mode]:checked");
var initMode  = initRadio ? initRadio.value : "standard";
setMode(initMode);
if (initMode === "standard") {
	var initSocid = '.(int) $prev_socid.';
	if (initSocid > 0) loadWarrantyProducts(initSocid);
}
})();</script>';

	$wtype_items = array();
	$wtype_options = array();
	$wtype_defaults_js = '{}';
	$selected_wtype = '';
	$initial_days = 0;
	$default_coverage_terms = '';
	$default_exclusions = '';

	if ($duration_source === 'warranty_type') {
		// Preserve the upstream per-product Warranty Type/default-day behaviour.
		$wtype_items = SvcWarrantyType::fetchAllForForm($db);
		$wtype_options = array('' => '— '.$langs->trans('NoPredefinedType').' —');
		$wtype_defaults_js = '{';
		foreach ($wtype_items as $wt) {
			$wtype_options[$wt->code] = dol_escape_htmltag($wt->label);
			$wtype_defaults_js .= '"'.dol_escape_js($wt->code).'":{"days":'.((int) $wt->default_coverage_days).',"terms":'.json_encode((string) $wt->coverage_terms).',"excl":'.json_encode((string) $wt->exclusions).'},';
		}
		$wtype_defaults_js = rtrim($wtype_defaults_js, ',').'}';

		$pre_wtype = '';
		$default_coverage_days = 0;
		if ($prev_product > 0 && !GETPOST('token', 'alpha')) {
			$sql_pd  = "SELECT warranty_type, coverage_days FROM ".MAIN_DB_PREFIX."warrantysvc_product_default";
			$sql_pd .= " WHERE fk_product = ".((int) $prev_product)." AND entity = ".((int) $conf->entity);
			$res_pd  = $db->query($sql_pd);
			$row_pd  = $res_pd ? $db->fetch_object($res_pd) : null;

			if (!$row_pd && isModEnabled('variants')) {
				$sql_par  = "SELECT fk_product_parent FROM ".MAIN_DB_PREFIX."product_attribute_combination";
				$sql_par .= " WHERE fk_product_child = ".((int) $prev_product);
				$sql_par .= " AND entity IN (".getEntity('product').")";
				$res_par  = $db->query($sql_par);
				if ($res_par && ($row_par = $db->fetch_object($res_par))) {
					$sql_pd2  = "SELECT warranty_type, coverage_days FROM ".MAIN_DB_PREFIX."warrantysvc_product_default";
					$sql_pd2 .= " WHERE fk_product = ".((int) $row_par->fk_product_parent)." AND entity = ".((int) $conf->entity);
					$res_pd2  = $db->query($sql_pd2);
					$row_pd   = $res_pd2 ? $db->fetch_object($res_pd2) : null;
				}
			}

			if ($row_pd) {
				$pre_wtype = $row_pd->warranty_type;
				foreach ($wtype_items as $wt) {
					if ($wt->code === $pre_wtype) {
						$default_coverage_days = $row_pd->coverage_days > 0 ? (int) $row_pd->coverage_days : (int) $wt->default_coverage_days;
						$default_coverage_terms = (string) $wt->coverage_terms;
						$default_exclusions = (string) $wt->exclusions;
						break;
					}
				}
			}
		}

		$selected_wtype = GETPOST('warranty_type', 'alpha') ?: $pre_wtype;
		$initial_days = GETPOST('coverage_days', 'int');
		if (!$initial_days) {
			$initial_days = $default_coverage_days > 0 ? $default_coverage_days : 365;
			if ($default_coverage_days <= 0) {
				foreach ($wtype_items as $wt) {
					if ($wt->code === $selected_wtype) {
						$initial_days = (int) $wt->default_coverage_days;
						break;
					}
				}
			}
		}

		print '<tr><td>'.$form->textwithpicto($langs->trans('WarrantyType'), $langs->trans('TooltipWarrantyType')).'</td>';
		print '<td>';
		print Form::selectarray('warranty_type', $wtype_options, $selected_wtype, 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth200', 0, 'id="warranty_type"', '', true);
		print '</td></tr>';
	}

	// Start date
	print '<tr><td class="fieldrequired">'.$langs->trans('StartDate').'</td>';
	print '<td>';
	print $form->selectDate(GETPOST('start_date', 'int') ? GETPOST('start_date', 'int') : dol_now(), 'start_date', 0, 0, 0, 'formcreate', 1, 1);
	print ' - <button class="dpInvisibleButtons datenowlink" type="button" id="origin_date_link" style="display:none"></button>';
	print '</td></tr>';

	if ($duration_source === 'product_field') {
		print '<tr><td>'.$langs->trans('WarrantyDuration').'</td>';
		print '<td><input type="hidden" id="coverage_days" name="coverage_days" value="0">';
		print '<span class="opacitymedium">'.$langs->trans('WarrantyDurationFromProductField').'</span></td></tr>';
	} else {
		$days_disabled = ($selected_wtype ? ' readonly style="opacity:0.5"' : '');
		print '<tr><td>'.$form->textwithpicto($langs->trans('CoverageDays'), $langs->trans('TooltipCoverageDays')).'</td>';
		print '<td>';
		print '<input type="number" id="coverage_days" name="coverage_days" value="'.$initial_days.'" class="flat width75" min="1" max="3650"'.$days_disabled.'>';
		print ' '.$langs->trans('SvcDays');
		print ' &nbsp;<span id="coverage_auto_hint" class="opacitymedium"'.($selected_wtype ? '' : ' style="display:none"').'>'.$langs->trans('CoverageFromType').'</span>';
		print ' <span id="coverage_manual_hint" class="opacitymedium"'.($selected_wtype ? ' style="display:none"' : '').'>'.$langs->trans('ExpiryAutoComputed').'</span>';
		print '</td></tr>';

		print '<script>
(function(){
	var defaults = '.$wtype_defaults_js.';
	var sel = document.getElementById("warranty_type");
	var cm = document.getElementById("coverage_days");
	var autoHint = document.getElementById("coverage_auto_hint");
	var manualHint = document.getElementById("coverage_manual_hint");
	function setEditorValue(name, val){
		if(typeof CKEDITOR !== "undefined" && CKEDITOR.instances[name]){
			CKEDITOR.instances[name].setData(val);
		} else {
			var el = document.getElementById(name);
			if(el) el.value = val;
		}
	}
	function sync(){
		var code = sel ? sel.value : "";
		if(code && defaults[code] !== undefined){
			var d = defaults[code];
			cm.value = d.days;
			cm.readOnly = true;
			cm.style.opacity = "0.5";
			if(autoHint) autoHint.style.display = "";
			if(manualHint) manualHint.style.display = "none";
			setEditorValue("coverage_terms", d.terms || "");
			setEditorValue("exclusions", d.excl || "");
		} else {
			cm.readOnly = false;
			cm.style.opacity = "";
			if(autoHint) autoHint.style.display = "none";
			if(manualHint) manualHint.style.display = "";
		}
	}
	if(sel){ sel.addEventListener("change", sync); sync(); }
})();
</script>';

		// Upstream mode keeps manual expiry, terms and exclusions.
		print '<tr><td>'.$form->textwithpicto($langs->trans('ExpiryDateOverride'), $langs->trans('TooltipExpiryDateOverride')).'</td>';
		print '<td>';
		print $form->selectDate('', 'expiry_date', 0, 0, 1, 'formcreate', 1, 0);
		print '</td></tr>';

		print '<tr><td class="tdtop">'.$form->textwithpicto($langs->trans('CoverageTerms'), $langs->trans('TooltipCoverageTerms')).'</td>';
		print '<td>';
		require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
		$doleditor = new DolEditor('coverage_terms', (GETPOST('coverage_terms', 'restricthtml') ?: $default_coverage_terms), '', 100, 'dolibarr_notes', '', false, true, getDolGlobalInt('FCKEDITOR_ENABLE_DETAILS'), ROWS_4, '90%');
		$doleditor->Create();
		print '</td></tr>';

		print '<tr><td class="tdtop">'.$form->textwithpicto($langs->trans('Exclusions'), $langs->trans('TooltipExclusions')).'</td>';
		print '<td>';
		$doleditor2 = new DolEditor('exclusions', (GETPOST('exclusions', 'restricthtml') ?: $default_exclusions), '', 100, 'dolibarr_notes', '', false, true, getDolGlobalInt('FCKEDITOR_ENABLE_DETAILS'), ROWS_3, '90%');
		$doleditor2->Create();
		print '</td></tr>';
	}

	// Origin order — Standard: auto-detect display; Override: manual entry
	$show_ord_manual = ($prev_mode === 'override');
	print '<tr><td>'.$form->textwithpicto($langs->trans('OriginOrder'), $langs->trans('TooltipOriginOrder')).'</td>';
	print '<td>';
	print '<span id="origin_order_std_row"'.($show_ord_manual ? ' style="display:none"' : '').'>';
	print '<span id="origin_order_ref" class="opacitymedium">'.dol_escape_htmltag($langs->trans('AutoFilledFromSerial')).'</span>';
	print '</span>';
	print '<input type="number" id="fk_commande_ovr_input" value="'.((int) GETPOST('fk_commande', 'int')).'" class="flat width100"'.(!$show_ord_manual ? ' style="display:none" disabled' : '').'>';
	print '</td></tr>';

	// Notes
	print '<tr><td>'.$form->textwithpicto($langs->trans('NotePublic'), $langs->trans('TooltipNotePublic')).'</td>';
	print '<td><textarea name="note_public" class="flat" rows="3" style="width:90%" placeholder="'.$langs->trans('NotePublicPlaceholder').'">'.dol_escape_htmltag(GETPOST('note_public', 'restricthtml'), 1).'</textarea></td></tr>';

	// Extrafields on manual create
	print $object->showOptionals($extrafields, 'create');

	print '</table>';

	print dol_get_fiche_end();

	print '<div class="center">';
	print '<input type="submit" class="button button-save" name="add" value="'.$langs->trans('Save').'">';
	print ' &nbsp; ';
	print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans('Cancel').'">';
	print '</div>';

	print '</form>';

	llxFooter();
	$db->close();
	exit;
}

// ============================================================
// VIEW / EDIT
// ============================================================
if (empty($object->id)) {
	dol_print_error($db, $langs->trans('ErrorRecordNotFound'));
	exit;
}

$head = svcwarranty_prepare_head($object);

// Determine live status
$now = dol_now();
$today = dol_print_date($now, '%Y-%m-%d', 'tzserver');
$display_status = $object->status;
if ($object->status != SvcWarranty::STATUS_VOIDED) {
	$expiry_day = !empty($object->expiry_date) ? dol_print_date($object->expiry_date, '%Y-%m-%d', 'tzserver') : '';
	if ($expiry_day !== '' && $expiry_day < $today) {
		$display_status = SvcWarranty::STATUS_EXPIRED;
	} else {
		$display_status = SvcWarranty::STATUS_ACTIVE;
	}
}

// --- Confirm dialogs ---
if ($action == 'void') {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$object->id,
		$langs->trans('VoidWarranty'),
		$langs->trans('ConfirmVoidWarranty'),
		'confirm_void',
		'',
		0,
		1
	);
}

if ($action == 'delete') {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$object->id,
		$langs->trans('DeleteWarranty'),
		$langs->trans('ConfirmDeleteWarranty'),
		'confirm_delete',
		'',
		0,
		1
	);
}

// Open form BEFORE the card content so all edit fields are inside it
if ($action == 'edit') {
	print '<form action="'.$_SERVER['PHP_SELF'].'" method="POST">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="'.$object->id.'">';
}

print dol_get_fiche_head($head, 'card', $langs->trans('Warranty'), -1, 'bill');

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_list.php">'.$langs->trans('BackToList').'</a>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', svcwarranty_status_badge($display_status));

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<table class="border centpercent tableforfield">';

// Customer
print '<tr><td class="titlefield">'.$langs->trans('Customer').'</td>';
print '<td>';
if ($action == 'edit') {
	print $formcompany->select_company($object->fk_soc, 'fk_soc', '(s.client:IN:1,3)', '', 0, 0, null, 0, 'minwidth200 maxwidth400');
} else {
	$soc = new Societe($db);
	if ($soc->fetch($object->fk_soc) > 0) {
		print $soc->getNomUrl(1);
	}
}
print '</td></tr>';

// Product
print '<tr><td>'.$langs->trans('Product').'</td>';
print '<td>';
if ($action == 'edit') {
	$form->select_produits($object->fk_product, 'fk_product', '', 0, 0, 1, 2, '', 0, array(), 0, 1, 0, 'minwidth200');
} else {
	$product = new Product($db);
	if ($product->fetch($object->fk_product) > 0) {
		print $product->getNomUrl(1);
	}
}
print '</td></tr>';

// Serial number
print '<tr><td>'.$form->textwithpicto($langs->trans('SvcSerialNumber'), $langs->trans('TooltipWarrantySerial')).'</td>';
print '<td>';
if ($action == 'edit') {
	print '<input type="text" name="serial_number" value="'.dol_escape_htmltag($object->serial_number).'" class="flat minwidth200">';
} else {
	print dol_escape_htmltag($object->serial_number);
}
print '</td></tr>';

// Warranty Type is part of upstream mode only.
if ($duration_source === 'warranty_type') {
	// Warranty type
	print '<tr><td>'.$form->textwithpicto($langs->trans('WarrantyType'), $langs->trans('TooltipWarrantyType')).'</td>';
	print '<td>';
	if ($action == 'edit') {
		$wtype_items_edit   = SvcWarrantyType::fetchAllForForm($db);
		$wtype_options_edit = array('' => '— '.$langs->trans('NoPredefinedType').' —');
		$wtype_defaults_edit_js = '{';
		foreach ($wtype_items_edit as $wt) {
			$wtype_options_edit[$wt->code] = dol_escape_htmltag($wt->label);
			$wtype_defaults_edit_js .= '"'.dol_escape_js($wt->code).'":{"days":'.((int) $wt->default_coverage_days).',"terms":'.json_encode((string) $wt->coverage_terms).',"excl":'.json_encode((string) $wt->exclusions).'},';
		}
		$wtype_defaults_edit_js = rtrim($wtype_defaults_edit_js, ',').'}';
		print Form::selectarray('warranty_type', $wtype_options_edit, $object->warranty_type, 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth200', 0, '', '', true);
		print '<script>
	(function(){
		var defaults = '.$wtype_defaults_edit_js.';
		var sel = document.getElementById("warranty_type");
		var cm  = document.getElementById("coverage_days");
		function setEditorValue(name, val){
			if(typeof CKEDITOR !== "undefined" && CKEDITOR.instances[name]){
				CKEDITOR.instances[name].setData(val);
			} else {
				var el = document.getElementById(name);
				if(el) el.value = val;
			}
		}
		var userChanged = false;
		function sync(){
			var code = sel ? sel.value : "";
			if(code && defaults[code] !== undefined){
				var d = defaults[code];
				cm.value = d.days; cm.disabled = true; cm.style.opacity = "0.5";
				if(userChanged){
					setEditorValue("coverage_terms", d.terms || "");
					setEditorValue("exclusions",     d.excl  || "");
				}
			} else { cm.disabled = false; cm.style.opacity = ""; }
		}
		if(sel){
			sel.addEventListener("change", function(){ userChanged = true; sync(); });
			sync();
		}
	})();
	</script>';
	} else {
		print $object->warranty_type
			? dol_escape_htmltag(SvcWarrantyType::getLabelByCode($db, $object->warranty_type))
			: '<span class="opacitymedium">&mdash;</span>';
	}
	print '</td></tr>';
	
}

// Status
print '<tr><td>'.$langs->trans('Status').'</td>';
print '<td>'.svcwarranty_status_badge($display_status).'</td></tr>';

// Claim summary — live count from svc_request to avoid stale denormalized counter
$sql_cc = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."svc_request WHERE fk_warranty = ".((int) $object->id)." AND entity IN (".getEntity('svcrequest').")";
$res_cc = $db->query($sql_cc);
$live_claim_count = 0;
if ($res_cc) {
	$row_cc = $db->fetch_object($res_cc);
	$live_claim_count = (int) $row_cc->cnt;
}
print '<tr><td>'.$langs->trans('Claims').'</td>';
print '<td>'.((int) $live_claim_count);
if ($object->total_claimed_value > 0) {
	print ' &mdash; '.price($object->total_claimed_value, 0, $langs, 1, -1, -1, $conf->currency);
}
print '</td></tr>';

print '</table>';
print '</div>'; // fichehalfleft

print '<div class="fichehalfright">';
print '<table class="border centpercent tableforfield">';

// Start date
print '<tr><td class="titlefield">'.$langs->trans('StartDate').'</td>';
print '<td>';
if ($action == 'edit') {
	print $form->selectDate($object->start_date, 'start_date', 0, 0, 0, 'cardform', 1, 1);
} else {
	print dol_print_date($object->start_date, 'day');
}
print '</td></tr>';

// Warranty duration
if ($duration_source === 'product_field') {
	print '<tr><td>'.$langs->trans('WarrantyDuration').'</td>';
	print '<td>';
	if ((int) $object->coverage_months > 0) {
		print ((int) $object->coverage_months).' '.$langs->trans('SvcMonths');
	} else {
		print '<span class="opacitymedium">'.$langs->trans('WarrantyDurationLegacyUnknown').'</span>';
	}
	print '</td></tr>';
} else {
	print '<tr><td>'.$form->textwithpicto($langs->trans('CoverageDays'), $langs->trans('TooltipCoverageDays')).'</td>';
	print '<td>';
	if ($action == 'edit') {
		$edit_days_disabled = ($object->warranty_type ? ' disabled style="opacity:0.5"' : '');
		print '<input type="number" id="coverage_days" name="coverage_days" value="'.((int) $object->coverage_days).'" class="flat width75" min="0" max="3650"'.$edit_days_disabled.'>';
		print ' '.$langs->trans('SvcDays');
		print ' &nbsp;<span class="opacitymedium" id="coverage_type_hint"'.($object->warranty_type ? '' : ' style="display:none"').'>'.$langs->trans('CoverageFromType').'</span>';
	} else {
		print $object->coverage_days ? ((int) $object->coverage_days).' '.$langs->trans('SvcDays') : '<span class="opacitymedium">&mdash;</span>';
	}
	print '</td></tr>';
}

// Expiry date
print '<tr><td>'.$langs->trans('ExpiryDate').'</td>';
print '<td>';
if ($action == 'edit' && $duration_source === 'warranty_type') {
	print $form->selectDate($object->expiry_date, 'expiry_date', 0, 0, 1, 'cardform', 1, 0);
	print ' <span class="opacitymedium">'.$langs->trans('ExpiryAutoComputed').'</span>';
} else {
	if ($object->expiry_date) {
		$expiry_ts = $object->expiry_date;
		if ($display_status == 'expired') {
			print '<span class="warning">'.dol_print_date($expiry_ts, 'day').'</span>';
		} elseif ($display_status == 'active' && $expiry_ts < dol_time_plus_duree($now, 30, 'd')) {
			print '<span class="opacitymediumhigh">'.dol_print_date($expiry_ts, 'day').'</span>';
			print ' &nbsp;<span class="badge badge-status4">'.$langs->trans('ExpiringSoon').'</span>';
		} else {
			print dol_print_date($expiry_ts, 'day');
		}
	} else {
		print '<span class="opacitymedium">&mdash;</span>';
	}
}
print '</td></tr>';

// Origin order
if ($object->fk_commande || $action == 'edit') {
	print '<tr><td>'.$form->textwithpicto($langs->trans('OriginOrder'), $langs->trans('TooltipOriginOrder')).'</td>';
	print '<td>';
	if ($action == 'edit') {
		print '<input type="number" name="fk_commande" value="'.((int) $object->fk_commande).'" class="flat width100">';
	} elseif ($object->fk_commande) {
		if (isModEnabled('commande')) {
			require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
			$commande = new Commande($db);
			if ($commande->fetch($object->fk_commande) > 0) {
				print $commande->getNomUrl(1);
			}
		} else {
			print $object->fk_commande;
		}
	}
	print '</td></tr>';
}

// Origin shipment
if ($object->fk_expedition || $action == 'edit') {
	print '<tr><td>'.$langs->trans('OriginShipment').'</td>';
	print '<td>';
	if ($action == 'edit') {
		print '<input type="number" name="fk_expedition" value="'.((int) $object->fk_expedition).'" class="flat width100">';
	} elseif ($object->fk_expedition) {
		if (isModEnabled('expedition')) {
			require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
			$expd = new Expedition($db);
			if ($expd->fetch($object->fk_expedition) > 0) {
				print $expd->getNomUrl(1);
			}
		} else {
			print $object->fk_expedition;
		}
	}
	print '</td></tr>';
}

print '</table>';
print '</div>'; // fichehalfright
print '</div>'; // fichecenter
print '<div class="clearboth"></div>';

// Coverage terms & exclusions are part of upstream Warranty Type mode.
if ($duration_source === 'warranty_type') {
	// Coverage terms & exclusions
	print '<div class="clearboth"></div>';
	print '<div class="fichecenter">';
	
	print '<div class="fichehalfleft">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield tdtop">'.$langs->trans('CoverageTerms').'</td>';
	print '<td>';
	if ($action == 'edit') {
		require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
		$doleditor = new DolEditor('coverage_terms', $object->coverage_terms, '', 120, 'dolibarr_notes', '', false, true, getDolGlobalInt('FCKEDITOR_ENABLE_DETAILS'), ROWS_4, '95%');
		$doleditor->Create();
	} else {
		print dol_htmlentitiesbr($object->coverage_terms);
	}
	print '</td></tr>';
	print '</table>';
	print '</div>';
	
	print '<div class="fichehalfright">';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield tdtop">'.$langs->trans('Exclusions').'</td>';
	print '<td>';
	if ($action == 'edit') {
		require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
		$doleditor3 = new DolEditor('exclusions', $object->exclusions, '', 120, 'dolibarr_notes', '', false, true, getDolGlobalInt('FCKEDITOR_ENABLE_DETAILS'), ROWS_4, '95%');
		$doleditor3->Create();
	} else {
		print dol_htmlentitiesbr($object->exclusions);
	}
	print '</td></tr>';
	print '</table>';
	print '</div>';
	
	print '</div>'; // fichecenter
	print '<div class="clearboth"></div>';
	
}

// ---- Unit Service History ----
if ($action != 'edit' && !empty($object->serial_number)) {
	require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcservicelog.class.php';
	$svclog = new SvcServiceLog($db);
	$log_found = $svclog->fetchBySerial($object->serial_number, $conf->entity);

	print '<div class="clearboth"></div>';
	print '<br>';
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td colspan="2">'.img_picto('', 'technic', 'class="pictofixedwidth"').$langs->trans('UnitServiceHistory').'</td>';
	print '</tr>';

	if ($log_found > 0) {
		$condition_labels = array(
			SvcServiceLog::CONDITION_GOOD => $langs->trans('ConditionGood'),
			SvcServiceLog::CONDITION_FAIR => $langs->trans('ConditionFair'),
			SvcServiceLog::CONDITION_POOR => $langs->trans('ConditionPoor'),
			SvcServiceLog::CONDITION_SCRAP => $langs->trans('ConditionScrap'),
		);
		$condition_badges = array(
			SvcServiceLog::CONDITION_GOOD => 'status4',
			SvcServiceLog::CONDITION_FAIR => 'status1',
			SvcServiceLog::CONDITION_POOR => 'status8',
			SvcServiceLog::CONDITION_SCRAP => 'status8',
		);

		print '<tr class="oddeven"><td style="width:220px;">'.$langs->trans('ServiceCount').'</td>';
		print '<td><strong>'.((int) $svclog->service_count).'</strong></td></tr>';

		print '<tr class="oddeven"><td>'.$langs->trans('ServiceHours').'</td>';
		print '<td>'.((float) $svclog->service_hours).' '.$langs->trans('Hours').'</td></tr>';

		print '<tr class="oddeven"><td>'.$langs->trans('ConditionStatus').'</td>';
		print '<td><span class="badge '.($condition_badges[$svclog->condition_status] ?? 'status0').'">'
			.($condition_labels[$svclog->condition_status] ?? $langs->trans('Unknown')).'</span></td></tr>';

		print '<tr class="oddeven"><td>'.$langs->trans('ConditionScore').'</td>';
		print '<td>'.((int) $svclog->condition_score).' <span class="opacitymedium">('.$langs->trans('LowerIsBetter').')</span></td></tr>';

		if ($svclog->last_service_date) {
			print '<tr class="oddeven"><td>'.$langs->trans('LastServiceDate').'</td>';
			print '<td>'.dol_print_date($svclog->last_service_date, 'day').'</td></tr>';
		}
		if ($svclog->condition_notes) {
			print '<tr class="oddeven"><td>'.$langs->trans('ConditionNotes').'</td>';
			print '<td>'.dol_escape_htmltag($svclog->condition_notes).'</td></tr>';
		}
	} else {
		print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('NoServiceHistory').'</span></td></tr>';
	}

	print '</table>';
	print '</div>';
}

// Extrafields in view/edit
if ($object->id > 0 && $action != 'create' && $action != 'create_from_shipment') {
	print '<div class="div-table-responsive"><table class="border centpercent tableforfield">';
	if ($action == 'edit' && $permwrite) {
		print $object->showOptionals($extrafields, 'edit');
	} else {
		print $object->showOptionals($extrafields, 'view');
	}
	print '</table></div>';
}

print dol_get_fiche_end();

// ---- Linked objects block ----
if ($action != 'edit' && $object->id > 0) {
	$object->fetchObjectLinked();
	$tmparray = $form->showLinkToObjectBlock($object, array(), array('svcwarranty'), 1);
	$linktoelem = isset($tmparray['linktoelem']) ? $tmparray['linktoelem'] : '';
	$htmltoenteralink = isset($tmparray['htmltoenteralink']) ? $tmparray['htmltoenteralink'] : '';
	print $htmltoenteralink;
	$somethingshown = $form->showLinkedObjectBlock($object, $linktoelem);
}

// ---- Action buttons ----
if ($action == 'edit') {
	print '<div class="center">';
	print '<input type="submit" class="button button-save" name="save" value="'.$langs->trans('Save').'">';
	print ' &nbsp; ';
	print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans('Cancel').'">';
	print '</div>';
	print '</form>'; // Close form opened before dol_get_fiche_head
} else {
	// View mode buttons
	print "\n".'<div class="tabsAction">'."\n";

	if ($permwrite && $display_status != SvcWarranty::STATUS_VOIDED) {
		print dolGetButtonAction('', $langs->trans('Modify'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=edit&token='.newToken(), '');
	}
	if ($user->hasRight('warrantysvc', 'svcrequest', 'write')) {
		$sr_url = DOL_URL_ROOT.'/custom/warrantysvc/card.php?action=create&fk_warranty='.$object->id.'&fk_soc='.$object->fk_soc.'&serial_number='.urlencode($object->serial_number)
			.(!empty($object->fk_product) ? '&fk_product='.$object->fk_product : '');
		print dolGetButtonAction('', $langs->trans('NewSvcRequest'), 'default', $sr_url, '');
	}
	if ($permwrite && $display_status != SvcWarranty::STATUS_VOIDED) {
		print dolGetButtonAction('', $langs->trans('VoidWarranty'), 'danger', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=void&token='.newToken(), '');
	}
	if ($permdelete) {
		print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete&token='.newToken(), '');
	}

	print '</div>';
}

// ============================================================
// CLAIM HISTORY — SvcRequests filed against this warranty
// ============================================================
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="5">'.$langs->trans('ClaimHistory').'</td>';
print '</tr>';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Ref').'</td>';
print '<td>'.$langs->trans('IssueDate').'</td>';
print '<td>'.$langs->trans('ResolutionType').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('AssignedTo').'</td>';
print '</tr>';

$sqlclaims  = "SELECT r.rowid, r.ref, r.issue_date, r.resolution_type, r.status, r.fk_user_assigned";
$sqlclaims .= " FROM ".MAIN_DB_PREFIX."svc_request as r";
$sqlclaims .= " WHERE r.serial_number = '".$db->escape($object->serial_number)."'";
$sqlclaims .= " AND r.entity IN (".getEntity('svcrequest').")";
$sqlclaims .= " ORDER BY r.issue_date DESC";

$resqlclaims = $db->query($sqlclaims);
if ($resqlclaims) {
	$numclaims = $db->num_rows($resqlclaims);
	if ($numclaims == 0) {
		print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('NoClaims').'</span></td></tr>';
	}
	$i = 0;
	while ($i < $numclaims) {
		$claim = $db->fetch_object($resqlclaims);
		print '<tr class="oddeven">';
		print '<td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$claim->rowid.'">'.dol_escape_htmltag($claim->ref).'</a></td>';
		print '<td>'.dol_print_date($db->jdate($claim->issue_date), 'day').'</td>';
		print '<td>'.svcrequest_resolution_label($claim->resolution_type).'</td>';
		print '<td class="center">'.svcrequest_status_badge($claim->status).'</td>';
		if ($claim->fk_user_assigned) {
			$u = new User($db);
			$u->fetch($claim->fk_user_assigned);
			print '<td>'.dol_escape_htmltag($u->getFullName($langs)).'</td>';
		} else {
			print '<td><span class="opacitymedium">&mdash;</span></td>';
		}
		print '</tr>';
		$i++;
	}
	$db->free($resqlclaims);
} else {
	print '<tr><td colspan="5">'.$db->lasterror().'</td></tr>';
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
