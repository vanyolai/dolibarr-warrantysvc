<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    card.php
 * \ingroup warrantysvc
 * \brief   Service Request create / view / edit card
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequestline.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierrma.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies', 'bills', 'stocks'));

// Standard Dolibarr card/email hooks used by the native presend form.
$hookmanager->initHooks(array('warrantysvccard', 'globalcard'));

$id         = GETPOST('id', 'int');
$ref        = GETPOST('ref', 'alpha');
$action     = GETPOST('action', 'aZ09');
$cancel     = GETPOST('cancel', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

$object = new SvcRequest($db);
$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label($object->table_element);

// Load existing object
if ($id > 0 || $ref) {
	$result = $object->fetch($id, $ref);
	if ($result <= 0) {
		dol_print_error($db, $object->error);
		exit;
	}
	$object->fetch_thirdparty();
}

// Permission checks
$permread    = $user->hasRight('warrantysvc', 'svcrequest', 'read');
$permwrite   = $user->hasRight('warrantysvc', 'svcrequest', 'write');
$permdelete  = $user->hasRight('warrantysvc', 'svcrequest', 'delete');
$permvalidate= $user->hasRight('warrantysvc', 'svcrequest', 'validate');
$permclose   = $user->hasRight('warrantysvc', 'svcrequest', 'close');
$permsupplierrmaread = $user->hasRight('warrantysvc', 'supplierrma', 'read');
$permsupplierrmawrite = $user->hasRight('warrantysvc', 'supplierrma', 'write');

if (!$permread) { accessforbidden(); }

/*
 * Resolution type helpers — used throughout to gate UI sections
 */
$types_with_outbound   = array('component', 'component_return', 'swap_cross', 'swap_wait');
$types_with_return     = array('component_return', 'swap_cross', 'swap_wait');
$types_intervention    = array('intervention');
$types_no_movement     = array('guidance', 'informational');

/*
 * Actions
 */
$error = 0;
$backurlforlist = DOL_URL_ROOT.'/custom/warrantysvc/list.php';

// Standard document/email form options. card_presend.tpl.php can generate the
// current Service Request PDF on demand and attach it to the outgoing message.
$hidedetails = GETPOSTINT('hidedetails') ? 1 : (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS') ? 1 : 0);
$hidedesc = GETPOSTINT('hidedesc') ? 1 : (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DESC') ? 1 : 0);
$hideref = GETPOSTINT('hideref') ? 1 : (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_REF') ? 1 : 0);

if (empty($backtopage) || ($cancel && empty($id))) {
	if (empty($backtopage) || ($cancel && strpos($backtopage, '__ID__'))) {
		if (empty($id) && (($action != 'add' && $action != 'create') || $cancel)) {
			$backtopage = $backurlforlist;
		} else {
			$backtopage = DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.(($id > 0) ? $id : '__ID__');
		}
	}
}

if ($cancel) {
	header('Location: '.$backtopage);
	exit;
}

if ($action == 'add' && $permwrite) {
	$claim_source              = GETPOST('claim_source', 'alpha');
	if ($claim_source !== 'manual') {
		$claim_source = 'warranty';
	}

	$object->fk_soc            = GETPOST('fk_soc', 'int');
	$object->fk_contact        = GETPOST('fk_contact', 'int');
	$object->customer_site     = GETPOST('customer_site', 'alphanohtml');
	$object->fk_project        = GETPOST('fk_project', 'int');
	$object->fk_commande       = GETPOST('fk_commande', 'int');
	$object->issue_date        = dol_mktime(12, 0, 0, GETPOST('issue_datemonth', 'int'), GETPOST('issue_dateday', 'int'), GETPOST('issue_dateyear', 'int'));
	$object->reported_via      = GETPOST('reported_via', 'alpha');
	$object->issue_description = GETPOST('issue_description', 'restricthtml');
	$object->fk_user_assigned  = GETPOST('fk_user_assigned', 'int');
	// resolution_type is not set at intake — chosen during the Diagnosing stage

	if ($claim_source === 'manual') {
		// Explicit warranty-less/service intake. Product and serial are entered
		// manually and the case starts as billable.
		$object->fk_product      = GETPOST('fk_product', 'int');
		$object->serial_number   = GETPOST('serial_number', 'alpha');
		$object->fk_warranty     = null;
		$object->warranty_status = 'none';
		$object->billable        = 1;

		if ($object->fk_product <= 0) {
			$error++;
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->trans('Product')), null, 'errors');
		} elseif (!warrantysvc_service_request_product_allowed($db, $object->fk_product)) {
			$error++;
			setEventMessages($langs->trans('ErrorWarrantyRequiresLotProduct'), null, 'errors');
		}
	} else {
		// Warranty-backed intake. The selected Warranty row is authoritative for
		// customer, Product and serial identity; do not trust hidden duplicates.
		$fk_warranty_posted = GETPOST('fk_warranty', 'int');
		if ($fk_warranty_posted <= 0) {
			$error++;
			setEventMessages($langs->trans('ErrorWarrantyRequiredForClaim'), null, 'errors');
		} else {
			$w = new SvcWarranty($db);
			if ($w->fetch($fk_warranty_posted) > 0) {
				if ((int) $w->entity !== (int) $conf->entity || (int) $w->fk_soc !== (int) $object->fk_soc) {
					$error++;
					setEventMessages($langs->trans('ErrorWarrantyDoesNotMatchClaim'), null, 'errors');
				} elseif ($w->status === SvcWarranty::STATUS_VOIDED) {
					$error++;
					setEventMessages($langs->trans('ErrorVoidedWarrantyClaim'), null, 'errors');
				} elseif (!warrantysvc_service_request_product_allowed($db, (int) $w->fk_product)) {
					$error++;
					setEventMessages($langs->trans('ErrorWarrantyRequiresLotProduct'), null, 'errors');
				} else {
					$effective_warranty_status = $w->getStatusAt($object->issue_date);
					$object->fk_product      = (int) $w->fk_product;
					$object->serial_number   = (string) $w->serial_number;
					$object->fk_warranty     = (int) $w->id;
					$object->warranty_status = $effective_warranty_status;
					$object->billable        = ($effective_warranty_status === SvcWarranty::STATUS_ACTIVE) ? 0 : 1;
				}
			} else {
				$error++;
				setEventMessages($langs->trans('ErrorWarrantyNotFound'), null, 'errors');
			}
		}
	}

	// Retrieve extrafields from POST
	$ret = $extrafields->setOptionalsFromPost(null, $object);
	if ($ret < 0) {
		$error++;
	}

	$result = $error ? -1 : $object->create($user);
	if ($result > 0) {
		// Sync all FK-based links into element_element
		$object->syncLinkedObjects();
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$result);
		exit;
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
		$action = 'create';
	}
}

if ($action == 'update' && $permwrite) {
	$object->fk_soc              = GETPOST('fk_soc', 'int');
	$object->fk_product          = GETPOST('fk_product', 'int');
	$object->serial_number       = GETPOST('serial_number', 'alpha');
	$object->fk_contact          = GETPOST('fk_contact', 'int');
	$object->customer_site       = GETPOST('customer_site', 'alphanohtml');
	$object->fk_project          = GETPOST('fk_project', 'int');
	$object->fk_commande         = GETPOST('fk_commande', 'int');
	$object->issue_date          = dol_mktime(12, 0, 0, GETPOST('issue_datemonth', 'int'), GETPOST('issue_dateday', 'int'), GETPOST('issue_dateyear', 'int'));
	$object->reported_via        = GETPOST('reported_via', 'alpha');
	$object->fk_pbxcall          = GETPOST('fk_pbxcall', 'int');
	$object->issue_description   = GETPOST('issue_description', 'restricthtml');
	if (GETPOSTISSET('resolution_type')) {
		$object->resolution_type = GETPOST('resolution_type', 'alpha');
	}
	$object->resolution_notes    = GETPOST('resolution_notes', 'restricthtml');
	$object->serial_in           = GETPOST('serial_in', 'alpha');
	$object->serial_out          = GETPOST('serial_out', 'alpha');
	$object->seal_number         = GETPOST('seal_number', 'alphanohtml');
	$object->fk_warehouse_source = GETPOST('fk_warehouse_source', 'int');
	$object->fk_warehouse_return = GETPOST('fk_warehouse_return', 'int');
	$object->fk_user_assigned    = GETPOST('fk_user_assigned', 'int');
	$object->billable            = GETPOST('billable', 'int');
	$object->outbound_carrier    = GETPOST('outbound_carrier', 'alphanohtml');
	$object->outbound_tracking   = GETPOST('outbound_tracking', 'alphanohtml');
	$object->return_carrier      = GETPOST('return_carrier', 'alphanohtml');
	$object->return_tracking     = GETPOST('return_tracking', 'alphanohtml');
	$object->date_return_expected = dol_mktime(12, 0, 0, GETPOST('date_return_expectedmonth', 'int'), GETPOST('date_return_expectedday', 'int'), GETPOST('date_return_expectedyear', 'int'));
	$object->note_private        = GETPOST('note_private', 'restricthtml');
	$object->note_public         = GETPOST('note_public', 'restricthtml');

	// Retrieve extrafields from POST
	$extrafields->setOptionalsFromPost(null, $object);

	$result = $object->update($user);
	if ($result >= 0) {
		// Sync all FK-based links into element_element (catches manual FK changes)
		$object->syncLinkedObjects();
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
}

if ($action == 'confirm_validate' && $permvalidate) {
	$result = $object->validate($user);
	if ($result < 0) {
		setEventMessages($object->error, $object->errors, 'errors');
	} else {
		setEventMessages($langs->trans('SvcValidated'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'confirm_setdiagnosing' && $permwrite) {
	$result = $object->setDiagnosing($user);
	if ($result < 0) {
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	} else {
		setEventMessages($langs->trans('SvcDiagnosingStarted'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'set_resolution_type' && $permwrite) {
	$object->resolution_type = GETPOST('resolution_type', 'alpha');
	$object->update($user);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'confirm_setinprogress' && $permwrite) {
	$result = $object->setInProgress($user);
	if ($result < 0) {
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'confirm_resolve' && $permwrite) {
	$object->resolve($user);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'confirm_close' && $permclose) {
	$result = $object->close($user);
	if ($result < 0) {
		setEventMessages($object->error, $object->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'confirm_cancel' && GETPOST('confirm', 'alpha') == 'yes' && $permwrite) {
	$result = $object->cancel($user);
	if ($result > 0 && !empty($object->fk_shipment)) {
		$shipment_url = DOL_URL_ROOT.'/expedition/card.php?id='.((int) $object->fk_shipment);
		setEventMessages($langs->trans('SvcRequestCancelledWithShipmentWarning', $object->fk_shipment, $shipment_url), null, 'warnings');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'confirm_reopen' && GETPOST('confirm', 'alpha') == 'yes' && $permclose) {
	$object->reopen($user);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

// Create return reception (warehouse chosen in inline form)
if ($action == 'create_return_reception' && $permwrite && isModEnabled('reception')) {
	$return_allowed_statuses = array(
		SvcRequest::STATUS_VALIDATED,
		SvcRequest::STATUS_DIAGNOSING,
		SvcRequest::STATUS_IN_PROGRESS,
		SvcRequest::STATUS_AWAIT_RETURN,
	);
	if (in_array($object->status, $return_allowed_statuses) && empty($object->fk_reception)) {
		$rec_warehouse = GETPOST('rec_warehouse', 'int');
		$object->serial_in = GETPOST('serial_in', 'alpha');
		$rec_id = $object->createReturnReception($user, $rec_warehouse);
		if ($rec_id > 0) {
			setEventMessages($langs->trans('ReceptionCreated'), null, 'mesgs');
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

// Step 5 — Validate return reception
if ($action == 'validate_return_reception' && $permwrite && isModEnabled('reception')) {
	if (!empty($object->fk_reception)) {
		$result = $object->validateReception($user);
		if ($result > 0) {
			setEventMessages($langs->trans('SvcReceptionValidated'), null, 'mesgs');
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

// Create replacement shipment directly (no sales order required)
if ($action == 'createreplacementshipment' && $permwrite) {
	if (!isModEnabled('shipping') && !isModEnabled('expedition')) {
		setEventMessages($langs->trans('ErrorExpeditionModuleDisabled'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	$shipment_id = $object->createReplacementShipment($user);
	if ($shipment_id > 0) {
		setEventMessages($langs->trans('ShipmentCreated'), null, 'mesgs');
		// Redirect to dispatch page so user can assign serial/lot from stock
		header('Location: '.DOL_URL_ROOT.'/expedition/dispatch.php?id='.$shipment_id);
		exit;
	} else {
		setEventMessages($object->error ?: $langs->trans('ErrorCreatingReplacementShipment'), $object->errors, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
}

// Clear orphaned shipment FK (shipment was deleted externally)
if ($action == 'clearorphanshipment' && $permwrite) {
	$object->fk_shipment = null;
	$object->update($user, 1);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

// Clear orphaned order FK (order was deleted externally)
if ($action == 'clearorphanorder' && $permwrite) {
	$object->fk_commande = null;
	$object->update($user, 1);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

// Log that a return was received (manually, before Reception module workflow)
if ($action == 'log_return_received' && $permwrite) {
	$object->date_return_received = dol_now();
	$object->return_carrier   = GETPOST('return_carrier', 'alphanohtml');
	$object->return_tracking  = GETPOST('return_tracking', 'alphanohtml');
	$object->serial_in        = GETPOST('serial_in', 'alpha');
	$result = $object->update($user);
	if ($result >= 0) {
		// Advance to In Progress if waiting for return
		if ($object->status == SvcRequest::STATUS_AWAIT_RETURN) {
			$object->setInProgress($user);
		}
		setEventMessages($langs->trans('ReturnLogged'), null, 'mesgs');
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'create_intervention' && $permwrite && isModEnabled('ficheinter')) {
	if (in_array($object->resolution_type, $types_intervention)) {
		$fichinter_id = $object->createLinkedIntervention($user);
		if ($fichinter_id > 0) {
			setEventMessages($langs->trans('InterventionCreated'), null, 'mesgs');
			header('Location: '.DOL_URL_ROOT.'/fichinter/card.php?id='.$fichinter_id);
			exit;
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'send_reminder' && $permwrite) {
	$object->sendReturnReminder($user);
	setEventMessages($langs->trans('ReminderSent'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action == 'invoice_nonreturn' && $permwrite && isModEnabled('facture')) {
	$inv_id = $object->invoiceForNonReturn($user);
	if ($inv_id > 0) {
		setEventMessages($langs->trans('InvoiceCreated'), null, 'mesgs');
		header('Location: '.DOL_URL_ROOT.'/compta/facture/card.php?id='.$inv_id);
		exit;
	} else {
		setEventMessages($object->error, $object->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

// Add component line
if ($action == 'addline' && $permwrite) {
	$line = new SvcRequestLine($db);
	$line->fk_rma_case  = $object->id;
	$line->fk_product   = GETPOST('product_id', 'int');
	$line->qty          = GETPOST('qty', 'int');
	$line->description  = GETPOST('line_desc', 'alphanohtml');
	$line->line_type    = GETPOST('line_type', 'alpha');
	$line->subprice     = price2num(GETPOST('subprice', 'alpha'));
	$result = $line->insert($user);
	if ($result < 0) {
		setEventMessages($line->error, $line->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'#components');
	exit;
}

// Delete component line
if ($action == 'deleteline' && $permwrite) {
	$lineid = GETPOST('lineid', 'int');
	$line   = new SvcRequestLine($db);
	$line->fetch($lineid);
	if ($line->fk_rma_case == $object->id) {
		$line->delete($user);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'#components');
	exit;
}

if ($action == 'confirm_delete' && GETPOST('confirm', 'alpha') == 'yes' && $permdelete) {
	$result = $object->delete($user);
	if ($result > 0) {
		header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/list.php');
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
}

// Remove orphaned customer return link from element_element
if ($action == 'remove_orphan_return_link' && $permwrite) {
	$link_id = GETPOSTINT('link_id');
	if ($link_id > 0) {
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."element_element WHERE rowid = ".((int) $link_id);
		if ($db->query($sql)) {
			setEventMessages($langs->trans('LinkRemoved'), null, 'mesgs');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
}

// Native Dolibarr email sending. This intentionally uses the core mail form
// instead of a WarrantySvc-specific sender so recipient selection, templates,
// attachments, signatures and agenda logging behave like other Dolibarr objects.
if ($object->id > 0 && $permwrite) {
	$triggersendname = '';
	$autocopy = '';
	$trackid = 'wsvcsr'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';
}

/*
 * View
 */
$form = new Form($db);
$formcompany = new FormCompany($db);

// Create uses AJAX. Edit needs a deterministic server-side selector so the
// serialized/LOT-only policy cannot be bypassed by a stale or hand-crafted UI.
// Historical requests on a formerly allowed non-batch product keep their
// current product in the list, but may only be changed to another eligible one.
$filtered_product_list = null;
if ($object->id > 0 && getDolGlobalString('WARRANTYSVC_WARRANTY_REQUIRES_LOTS')) {
	$filtered_product_list = array();
	$sqlProducts = "SELECT p.rowid, p.ref, p.label FROM ".MAIN_DB_PREFIX."product p";
	$sqlProducts .= " WHERE p.entity IN (".getEntity('product').")";
	$sqlProducts .= " AND (p.tobatch > 0 OR p.rowid = ".((int) $object->fk_product).")";
	$sqlProducts .= " ORDER BY p.ref ASC";
	$resProducts = $db->query($sqlProducts);
	if ($resProducts) {
		while ($productRow = $db->fetch_object($resProducts)) {
			$filtered_product_list[(int) $productRow->rowid] = trim((string) $productRow->ref.' - '.(string) $productRow->label);
		}
		$db->free($resProducts);
	}
}

// Product list is loaded dynamically via AJAX on create.

llxHeader('', ($id ? $object->ref : $langs->trans('NewSvcRequest')), '');

if ($action == 'create') {
	// =====================================================================
	// CREATE FORM
	// =====================================================================

	print load_fiche_titre($langs->trans('NewSvcRequest'), '', 'technic');

	print '<form action="'.$_SERVER['PHP_SELF'].'" method="POST">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="backtopage" value="'.dol_escape_htmltag($backtopage).'">';

	print dol_get_fiche_head(array(), '', '', -1);

	print '<table class="border centpercent tableforfieldcreate">';

	// Pre-fill values from GET params (e.g. when arriving from warranty card button)
	$prefill_soc     = GETPOST('fk_soc', 'int');
	$prefill_product = GETPOST('fk_product', 'int');
	$prefill_serial  = GETPOST('serial_number', 'alpha');
	$prefill_project = GETPOST('fk_project', 'int');

	// Customer
	print '<tr><td class="fieldrequired">'.$langs->trans('ThirdParty').'</td>';
	print '<td>'.$form->select_company($prefill_soc, 'fk_soc', '(s.client:IN:1,3)', 1, 0, 0, array(), 0, 'minwidth300').'</td></tr>';

	$prefill_warranty = (int) GETPOST('fk_warranty', 'int');
	$prefill_source = GETPOST('claim_source', 'alpha') === 'manual' ? 'manual' : 'warranty';

	print '<input type="hidden" name="claim_source" id="claim_source" value="'.dol_escape_htmltag($prefill_source).'">';
	print '<input type="hidden" name="fk_warranty" id="fk_warranty" value="'.((int) $prefill_warranty).'">';

	// Issue date comes before warranty selection because warranty status is
	// evaluated against the date the customer reported the issue.
	$posted_issue_date = dol_mktime(
		12,
		0,
		0,
		GETPOST('issue_datemonth', 'int'),
		GETPOST('issue_dateday', 'int'),
		GETPOST('issue_dateyear', 'int')
	);
	$prefill_issue_date = $posted_issue_date > 0 ? $posted_issue_date : dol_now();
	print '<tr><td>'.$form->textwithpicto($langs->trans('IssueDate'), $langs->trans('TooltipIssueDate')).'</td>';
	print '<td>'.$form->selectDate($prefill_issue_date, 'issue_date', 0, 0, 0, '', 1, 1).'</td></tr>';

	// Warranty/device picker. The selected Warranty record is the authoritative
	// identity of Product + serial/LOT for normal intake.
	print '<tr><td class="tdtop fieldrequired">'.$langs->trans('WarrantyCoveredUnit').'</td>';
	print '<td>';
	print '<div id="warranty_picker">';
	print '<div class="tagtable" style="margin-bottom:8px">';
	print '<input type="text" id="warranty_search" class="flat minwidth300"'
		.' placeholder="'.dol_escape_htmltag($langs->trans('SearchWarrantyUnitPlaceholder')).'" disabled>';
	print '</div>';
	print '<div id="warranty_picker_message" class="opacitymedium">'.$langs->trans('SelectCustomerForWarrantyUnits').'</div>';
	print '<div id="warranty_picker_table_wrap" class="div-table-responsive" style="display:none">';
	print '<table class="noborder centpercent">';
	print '<thead><tr class="liste_titre">';
	print '<td class="center" style="width:32px"></td>';
	print '<td>'.$langs->trans('Product').'</td>';
	print '<td>'.$langs->trans('SerialOrLot').'</td>';
	print '<td>'.$langs->trans('Warranty').'</td>';
	print '<td class="center">'.$langs->trans('StartDate').'</td>';
	print '<td class="center">'.$langs->trans('ExpiryDate').'</td>';
	print '<td class="center">'.$langs->trans('Status').'</td>';
	print '</tr></thead>';
	print '<tbody id="warranty_picker_body"></tbody>';
	print '</table>';
	print '</div>';
	print '<div style="margin-top:8px">';
	print '<button type="button" class="button button-cancel" id="manual_claim_toggle">'
		.$langs->trans('CreateClaimWithoutWarranty').'</button>';
	print ' <span class="opacitymedium">'.$langs->trans('CreateClaimWithoutWarrantyDesc').'</span>';
	print '</div>';
	print '</div>';
	print '</td></tr>';

	// Explicit warranty-less/manual intake. Hidden by default so products without
	// WarrantySvc coverage never clutter the normal warranty workflow.
	$manual_style = ($prefill_source === 'manual') ? '' : ' style="display:none"';
	print '<tr class="manual-claim-row"'.$manual_style.'><td colspan="2">';
	print '<div class="warning">'.$langs->trans('ManualClaimEntryWarning').'</div>';
	print '</td></tr>';

	print '<tr class="manual-claim-row"'.$manual_style.'><td class="fieldrequired">'.$langs->trans('Product').'</td>';
	print '<td>';
	print '<select name="fk_product" id="fk_product" class="flat minwidth300" '.($prefill_soc > 0 ? '' : 'disabled').'>';
	print '<option value="">'.dol_escape_htmltag($langs->trans('SelectCustomerFirst')).'</option>';
	print '</select>';
	print '</td></tr>';

	print '<tr class="manual-claim-row"'.$manual_style.'><td>'.$form->textwithpicto($langs->trans('SvcSerialNumber'), $langs->trans('TooltipSerialNumber')).'</td>';
	print '<td>';
	print '<select name="serial_number" id="serial_number" class="minwidth200" disabled>';
	print '<option value="">'.dol_escape_htmltag($langs->trans('SelectProductFirst')).'</option>';
	print '</select>';
	print '</td></tr>';

	// Resolution type is intentionally NOT collected at intake — it is the
	// solution, chosen after diagnosis (see Diagnosing lifecycle stage).

	// Reported via
	print '<tr><td>'.$form->textwithpicto($langs->trans('ReportedVia'), $langs->trans('TooltipReportedVia')).'</td>';
	print '<td>';
	$via_options = array(
		'phone'   => $langs->trans('ReportedViaPhone'),
		'email'   => $langs->trans('ReportedViaEmail'),
		'onsite'  => $langs->trans('ReportedViaOnSite'),
		'other'   => $langs->trans('ReportedViaOther'),
	);
	print Form::selectarray('reported_via', $via_options, 'phone', 0, 0, 0, '', 0, 0, 0, '', 'flat');
	print '</td></tr>';

	// Issue description
	print '<tr><td class="fieldrequired tdtop">'.$form->textwithpicto($langs->trans('IssueDescription'), $langs->trans('TooltipIssueDescription')).'</td>';
	print '<td><textarea name="issue_description" class="centpercent" rows="4" placeholder="'.$langs->trans('IssueDescriptionPlaceholder').'"></textarea></td></tr>';

	// Customer site note
	print '<tr><td>'.$form->textwithpicto($langs->trans('CustomerSite'), $langs->trans('TooltipCustomerSite')).'</td>';
	print '<td><input type="text" name="customer_site" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans('CustomerSitePlaceholder')).'"></td></tr>';

	// Assigned to
	print '<tr><td>'.$langs->trans('AssignedTo').'</td>';
	print '<td>'.$form->select_dolusers($user->id, 'fk_user_assigned', 1).'</td></tr>';

	// Project (if enabled) — disabled until a customer is chosen; populated via AJAX
	if (isModEnabled('project')) {
		print '<tr><td>'.$langs->trans('Project').'</td>';
		print '<td>';
		print '<select name="fk_project" id="fk_project" class="flat minwidth300" disabled>';
		print '<option value="">'.dol_escape_htmltag($langs->trans('SelectCustomerFirst')).'</option>';
		print '</select>';
		print '</td></tr>';
	}

	// Extrafields on create
	print $object->showOptionals($extrafields, 'create');

	print '</table>';
	print dol_get_fiche_end();

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Create').'">';
	print ' &nbsp; ';
	print '<input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans('Cancel').'">';
	print '</div>';

	print '</form>';

	print '<script>(function(){
	var warrantyAjaxUrl = "'.DOL_URL_ROOT.'/custom/warrantysvc/ajax/customer_warranties.php";
	var serialAjaxUrl = "'.DOL_URL_ROOT.'/custom/warrantysvc/ajax/serials.php?mode=svcrequest";
	var srProductAjaxUrl = "'.DOL_URL_ROOT.'/custom/warrantysvc/ajax/sr_products.php";
	var projectAjaxUrl = "'.DOL_URL_ROOT.'/custom/warrantysvc/ajax/projects.php";

	var warrantyInput = document.getElementById("fk_warranty");
	var sourceInput = document.getElementById("claim_source");
	var warrantyBody = document.getElementById("warranty_picker_body");
	var warrantyWrap = document.getElementById("warranty_picker_table_wrap");
	var warrantyMessage = document.getElementById("warranty_picker_message");
	var warrantySearch = document.getElementById("warranty_search");
	var manualToggle = document.getElementById("manual_claim_toggle");
	var manualRows = Array.prototype.slice.call(document.querySelectorAll(".manual-claim-row"));
	var selSer = document.getElementById("serial_number");
	var selProj = document.getElementById("fk_project");
	var submitButton = document.querySelector("input.button-save[type=submit]");
	var pendingWarranty = '.((int) $prefill_warranty).';
	var pendingProduct = '.((int) $prefill_product).';
	var pendingSerial = "'.dol_escape_js($prefill_serial).'";
	var warrantyRows = [];

	var txt = {
		selectCustomer: "'.dol_escape_js($langs->transnoentitiesnoconv('SelectCustomerForWarrantyUnits')).'",
		noWarranties: "'.dol_escape_js($langs->transnoentitiesnoconv('NoWarrantiesForCustomer')).'",
		noMatches: "'.dol_escape_js($langs->transnoentitiesnoconv('NoWarrantiesMatchFilter')).'",
		active: "'.dol_escape_js($langs->transnoentitiesnoconv('SvcActive')).'",
		expired: "'.dol_escape_js($langs->transnoentitiesnoconv('SvcExpired')).'",
		noSerial: "'.dol_escape_js($langs->transnoentitiesnoconv('NoSerialNumber')).'",
		coveredQty: "'.dol_escape_js($langs->transnoentitiesnoconv('WarrantyCoveredQuantity', '__QTY__')).'",
		manual: "'.dol_escape_js($langs->transnoentitiesnoconv('CreateClaimWithoutWarranty')).'",
		back: "'.dol_escape_js($langs->transnoentitiesnoconv('BackToWarrantySelection')).'",
		pickCust: "'.dol_escape_js($langs->transnoentitiesnoconv('SelectCustomerFirst')).'",
		pickProd: "'.dol_escape_js($langs->transnoentitiesnoconv('SelectProductFirst')).'",
		pickSel: "'.dol_escape_js($langs->transnoentitiesnoconv('SelectProduct')).'",
		noProd: "'.dol_escape_js($langs->transnoentitiesnoconv('NoProductForCustomer')).'",
		pickSer: "\u2014 '.dol_escape_js($langs->transnoentitiesnoconv('SelectSerial')).' \u2014",
		noSerialAvail: "'.dol_escape_js($langs->transnoentitiesnoconv('NoSerialsAvailable')).'",
		noProj: "'.dol_escape_js($langs->transnoentitiesnoconv('NoProjectForCustomer')).'",
		pickProj: "\u2014 '.dol_escape_js($langs->transnoentitiesnoconv('SelectProject')).' \u2014"
	};

	function notifySelect2(el){
		if(typeof jQuery !== "undefined" && jQuery.fn.select2 && el){
			jQuery(el).trigger("change.select2");
		}
	}

	function esc(value){
		return String(value == null ? "" : value)
			.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;").replace(/\x27/g, "&#039;");
	}

	function getIssueDateIso(){
		var y = document.querySelector("[name=issue_dateyear]");
		var m = document.querySelector("[name=issue_datemonth]");
		var d = document.querySelector("[name=issue_dateday]");
		if(!y || !m || !d || !y.value || !m.value || !d.value) return "";
		return String(y.value).padStart(4, "0") + "-" + String(m.value).padStart(2, "0") + "-" + String(d.value).padStart(2, "0");
	}

	function effectiveStatus(row){
		var issue = getIssueDateIso();
		if(row.expiry_date && issue && row.expiry_date < issue) return "expired";
		return "active";
	}

	function statusBadge(row){
		var status = effectiveStatus(row);
		var label = status === "active" ? txt.active : txt.expired;
		var cls = status === "active" ? "badge-status4" : "badge-status8";
		return "<span class=\"badge badge-status " + cls + "\">" + esc(label) + "</span>";
	}

	function selectWarranty(id){
		id = parseInt(id, 10) || 0;
		warrantyInput.value = id ? String(id) : "";
		warrantyBody.querySelectorAll("tr[data-warranty-id]").forEach(function(tr){
			var selected = parseInt(tr.dataset.warrantyId, 10) === id;
			tr.classList.toggle("highlight", selected);
			var radio = tr.querySelector("input[type=radio]");
			if(radio) radio.checked = selected;
		});
		updateSubmitState();
	}

	function updateSubmitState(){
		if(!submitButton) return;
		if(sourceInput.value === "manual"){
			submitButton.disabled = false;
		} else {
			submitButton.disabled = !(parseInt(warrantyInput.value, 10) > 0);
		}
	}

	function renderWarrantyRows(){
		if(!warrantyBody) return;
		var query = warrantySearch ? warrantySearch.value.trim().toLowerCase() : "";
		var html = "";
		var visible = 0;
		warrantyRows.forEach(function(row){
			var product = row.product_ref + (row.product_label ? " — " + row.product_label : "");
			var serial = row.serial_number || txt.noSerial;
			if(!row.serial_number && parseFloat(row.covered_qty || 0) > 0){
				serial += " · " + txt.coveredQty.replace("__QTY__", row.covered_qty);
			}
			var haystack = (product + " " + serial + " " + row.ref + " " + row.start_label + " " + row.expiry_label).toLowerCase();
			if(query && haystack.indexOf(query) === -1) return;
			visible++;
			var checked = parseInt(warrantyInput.value, 10) === parseInt(row.rowid, 10);
			html += "<tr class=\"oddeven warrantysvc-warranty-choice" + (checked ? " highlight" : "") + "\" data-warranty-id=\"" + parseInt(row.rowid,10) + "\" style=\"cursor:pointer\">";
			html += "<td class=\"center\"><input type=\"radio\" name=\"warranty_choice\" value=\"" + parseInt(row.rowid,10) + "\"" + (checked ? " checked" : "") + "></td>";
			html += "<td>" + esc(product) + "</td>";
			html += "<td>" + esc(serial) + "</td>";
			html += "<td><strong>" + esc(row.ref) + "</strong></td>";
			html += "<td class=\"center\">" + esc(row.start_label || "—") + "</td>";
			html += "<td class=\"center\">" + esc(row.expiry_label || "—") + "</td>";
			html += "<td class=\"center warranty-status-cell\">" + statusBadge(row) + "</td>";
			html += "</tr>";
		});
		warrantyBody.innerHTML = html;
		if(warrantyRows.length && visible === 0){
			warrantyWrap.style.display = "none";
			warrantyMessage.textContent = txt.noMatches;
			warrantyMessage.style.display = "";
		} else if(visible > 0){
			warrantyWrap.style.display = sourceInput.value === "manual" ? "none" : "";
			warrantyMessage.style.display = "none";
		} else {
			warrantyWrap.style.display = "none";
		}
	}

	function refreshWarrantyStatuses(){
		renderWarrantyRows();
	}

	function loadWarranties(){
		var socEl = document.querySelector("[name=fk_soc]");
		var sid = socEl ? parseInt(socEl.value, 10) || 0 : 0;
		warrantyRows = [];
		selectWarranty(0);
		if(!sid){
			warrantySearch.disabled = true;
			warrantySearch.value = "";
			warrantyWrap.style.display = "none";
			warrantyMessage.textContent = txt.selectCustomer;
			warrantyMessage.style.display = "";
			return;
		}
		warrantyMessage.textContent = "…";
		warrantyMessage.style.display = "";
		warrantyWrap.style.display = "none";
		warrantySearch.disabled = true;
		fetch(warrantyAjaxUrl + "?socid=" + sid, {credentials:"same-origin"})
			.then(function(r){ if(!r.ok) throw new Error("HTTP " + r.status); return r.json(); })
			.then(function(data){
				warrantyRows = Array.isArray(data) ? data : [];
				warrantySearch.disabled = false;
				if(!warrantyRows.length){
					warrantyMessage.textContent = txt.noWarranties;
					warrantyMessage.style.display = "";
					warrantyWrap.style.display = "none";
				} else {
					warrantyMessage.style.display = "none";
					renderWarrantyRows();
					if(pendingWarranty){
						var found = warrantyRows.some(function(row){ return parseInt(row.rowid,10) === pendingWarranty; });
						if(found) selectWarranty(pendingWarranty);
						pendingWarranty = 0;
					}
				}
				updateSubmitState();
			})
			.catch(function(){
				warrantyRows = [];
				warrantySearch.disabled = true;
				warrantyMessage.textContent = "'.dol_escape_js($langs->transnoentitiesnoconv('ErrorLoadingWarrantyUnits')).'";
				warrantyMessage.style.display = "";
				warrantyWrap.style.display = "none";
				updateSubmitState();
			});
	}

	function setManualMode(enabled){
		sourceInput.value = enabled ? "manual" : "warranty";
		manualRows.forEach(function(row){ row.style.display = enabled ? "" : "none"; });
		manualToggle.textContent = enabled ? txt.back : txt.manual;
		if(enabled){
			selectWarranty(0);
			warrantyWrap.style.display = "none";
			warrantyMessage.style.display = "none";
			warrantySearch.parentElement.style.display = "none";
			loadSrProducts();
		} else {
			warrantySearch.parentElement.style.display = "";
			if(warrantyRows.length) renderWarrantyRows();
			else warrantyMessage.style.display = "";
		}
		updateSubmitState();
	}

	function setSerialOptions(serials){
		if(!selSer) return;
		selSer.innerHTML = "";
		if(!serials || !serials.length){
			selSer.disabled = true;
			var opt = document.createElement("option");
			opt.value = "";
			opt.textContent = txt.noSerialAvail;
			selSer.appendChild(opt);
		} else {
			selSer.disabled = false;
			var blank = document.createElement("option");
			blank.value = "";
			blank.textContent = txt.pickSer;
			selSer.appendChild(blank);
			serials.forEach(function(s){
				var opt = document.createElement("option");
				opt.value = s;
				opt.textContent = s;
				selSer.appendChild(opt);
			});
		}
		if(pendingSerial){
			selSer.value = pendingSerial;
			pendingSerial = "";
		}
	}

	function loadSerials(){
		if(sourceInput.value !== "manual") return;
		var el = document.getElementById("fk_product");
		var pid = el ? parseInt(el.value, 10) || 0 : 0;
		if(!pid){
			setSerialOptions([]);
			return;
		}
		var socEl = document.querySelector("[name=fk_soc]");
		var sid = socEl ? parseInt(socEl.value, 10) || 0 : 0;
		fetch(serialAjaxUrl + "&fk_product=" + pid + (sid > 0 ? "&fk_soc=" + sid : ""), {credentials:"same-origin"})
			.then(function(r){ return r.json(); })
			.then(function(data){ setSerialOptions(data); })
			.catch(function(){ setSerialOptions([]); });
	}

	function loadSrProducts(){
		if(sourceInput.value !== "manual") return;
		var socEl = document.querySelector("[name=fk_soc]");
		var sid = socEl ? parseInt(socEl.value, 10) || 0 : 0;
		var prodEl = document.getElementById("fk_product");
		if(!prodEl) return;
		if(!sid){
			prodEl.innerHTML = "<option value=\"\">" + txt.pickCust + "</option>";
			prodEl.disabled = true;
			setSerialOptions([]);
			return;
		}
		fetch(srProductAjaxUrl + "?socid=" + sid, {credentials:"same-origin"})
			.then(function(r){ return r.json(); })
			.then(function(data){
				prodEl.innerHTML = "";
				var blank = document.createElement("option");
				blank.value = "";
				blank.textContent = data.length ? ("— " + txt.pickSel + " —") : txt.noProd;
				prodEl.appendChild(blank);
				data.forEach(function(p){
					var opt = document.createElement("option");
					opt.value = p.rowid;
					opt.textContent = p.label;
					prodEl.appendChild(opt);
				});
				prodEl.disabled = (data.length === 0);
				if(pendingProduct && prodEl.querySelector("option[value=\"" + pendingProduct + "\"]")){
					prodEl.value = pendingProduct;
					pendingProduct = 0;
					loadSerials();
				} else {
					setSerialOptions([]);
				}
			})
			.catch(function(){ prodEl.disabled = true; });
	}

	function loadProjects(){
		if(!selProj) return;
		var el = document.querySelector("[name=fk_soc]");
		var sid = el ? parseInt(el.value, 10) || 0 : 0;
		selProj.innerHTML = "";
		selProj.disabled = true;
		if(!sid){
			var opt = document.createElement("option");
			opt.value = "";
			opt.textContent = txt.pickCust;
			selProj.appendChild(opt);
			notifySelect2(selProj);
			return;
		}
		fetch(projectAjaxUrl + "?socid=" + sid, {credentials:"same-origin"})
			.then(function(r){ return r.json(); })
			.then(function(data){
				var blank = document.createElement("option");
				blank.value = "";
				blank.textContent = data.length ? txt.pickProj : txt.noProj;
				selProj.appendChild(blank);
				data.forEach(function(p){
					var opt = document.createElement("option");
					opt.value = p.rowid;
					opt.textContent = p.label;
					selProj.appendChild(opt);
				});
				selProj.disabled = (data.length === 0);
				notifySelect2(selProj);
			})
			.catch(function(){ selProj.disabled = true; });
	}

	if(warrantyBody){
		warrantyBody.addEventListener("click", function(e){
			var tr = e.target.closest("tr[data-warranty-id]");
			if(tr) selectWarranty(tr.dataset.warrantyId);
		});
	}
	if(warrantySearch) warrantySearch.addEventListener("input", renderWarrantyRows);
	if(manualToggle) manualToggle.addEventListener("click", function(){ setManualMode(sourceInput.value !== "manual"); });

	if(typeof jQuery !== "undefined"){
		jQuery(document).on("select2:select select2:clear", "[name=fk_product]", loadSerials);
		jQuery(document).on("select2:select select2:clear", "[name=fk_soc]", function(){
			pendingWarranty = 0;
			loadWarranties();
			loadProjects();
			if(sourceInput.value === "manual") loadSrProducts();
		});
	}
	document.addEventListener("change", function(e){
		if(e.target && e.target.name === "fk_product") loadSerials();
		if(e.target && e.target.name === "fk_soc"){
			pendingWarranty = 0;
			loadWarranties();
			loadProjects();
			if(sourceInput.value === "manual") loadSrProducts();
		}
		if(e.target && (e.target.name === "issue_dateyear" || e.target.name === "issue_datemonth" || e.target.name === "issue_dateday")){
			refreshWarrantyStatuses();
		}
	});

	setManualMode(sourceInput.value === "manual");
	loadWarranties();
	loadProjects();
	updateSubmitState();
})();</script>';
} else {
	// =====================================================================
	// VIEW / EDIT
	// =====================================================================
	if (!$object->id) {
		dol_print_error($db, $langs->trans('ErrorRecordNotFound'));
		llxFooter();
		exit;
	}

	$head = warrantysvc_prepare_head($object);
	print dol_get_fiche_head($head, 'card', $langs->trans('SvcRequest'), -1, 'technic');

	// Confirmation dialogs
	$formconfirm = '';
	if ($action == 'cancel') {
		$formconfirm = $form->formconfirm(
			$_SERVER['PHP_SELF'].'?id='.$object->id,
			$langs->trans('CancelSvcRequest'),
			$langs->trans('ConfirmCancelSvcRequest'),
			'confirm_cancel',
			'',
			0,
			1
		);
	}
	if ($action == 'reopen') {
		$formconfirm = $form->formconfirm(
			$_SERVER['PHP_SELF'].'?id='.$object->id,
			$langs->trans('ReopenSvcRequest'),
			$langs->trans('ConfirmReopenSvcRequest'),
			'confirm_reopen',
			'',
			0,
			1
		);
	}
	if ($action == 'delete') {
		$formconfirm = $form->formconfirm(
			$_SERVER['PHP_SELF'].'?id='.$object->id,
			$langs->trans('DeleteSvcRequest'),
			$langs->trans('ConfirmDeleteSvcRequest', $object->ref),
			'confirm_delete',
			'',
			0,
			1
		);
	}
	print $formconfirm;

	// Object banner
	$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/list.php">'
		.img_picto($langs->trans('BackToList'), 'back', 'class="pictofixedwidth"')
		.$langs->trans('BackToList').'</a>';

	$morehtmlref = '';
	if ($object->fk_soc) {
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($db);
		$soc->fetch($object->fk_soc);
		$morehtmlref .= ' &mdash; <a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.$soc->id.'">'
			.dol_escape_htmltag($soc->name).'</a>';
	}

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

	// Wrap entire edit form around two-column layout so inputs are captured
	if ($action == 'edit') {
		print '<form action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" method="POST">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="update">';
	}

	print '<div class="fichecenter">';
	print '<div class="fichehalfleft">';
	print '<table class="border centpercent tableforfield">';

	// Customer
	print '<tr><td class="titlefield">'.$langs->trans('Company').'</td><td>';
	if ($action == 'edit' && $permwrite) {
		print $form->select_company($object->fk_soc, 'fk_soc', '(s.client:IN:1,3)', 1, 0, 0, array(), 0, 'minwidth200');
	} else {
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($db);
		if ($soc->fetch($object->fk_soc) > 0) {
			print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.$soc->id.'">'.dol_escape_htmltag($soc->name).'</a>';
		}
	}
	print '</td></tr>';

	// Product
	print '<tr><td>'.$langs->trans('Product').'</td><td>';
	if ($action == 'edit' && $permwrite) {
		if (!is_null($filtered_product_list)) {
			print Form::selectarray('fk_product', $filtered_product_list, $object->fk_product, 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth200');
		} else {
			$form->select_produits($object->fk_product, 'fk_product', '', 0, 0, -1, 0, '', 1, 0, 'minwidth200');
		}
	} else {
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		$prod = new Product($db);
		if ($prod->fetch($object->fk_product) > 0) {
			print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.$prod->id.'">'
				.dol_escape_htmltag($prod->ref).' &mdash; '.dol_escape_htmltag($prod->label).'</a>';
		}
	}
	print '</td></tr>';

	// Serial number (customer's defective unit)
	print '<tr><td>'.$form->textwithpicto($langs->trans('SvcSerialNumber'), $langs->trans('TooltipSerialNumber')).'</td><td>';
	if ($action == 'edit' && $permwrite) {
		print '<input type="text" name="serial_number" id="serial_number" class="minwidth200" value="'.dol_escape_htmltag($object->serial_number).'" list="serial_suggestions">';
		$serials = $object->getCustomerSerials($object->fk_soc, $object->fk_product);
		if ($serials) {
			print '<datalist id="serial_suggestions">';
			foreach ($serials as $s) {
				print '<option value="'.dol_escape_htmltag($s['serial']).'">'.dol_escape_htmltag($s['serial']).' ('.$s['product_ref'].')</option>';
			}
			print '</datalist>';
		}
	} else {
		print '<strong>'.dol_escape_htmltag($object->serial_number).'</strong>';
	}
	print '</td></tr>';

	// Issue date
	print '<tr><td>'.$form->textwithpicto($langs->trans('IssueDate'), $langs->trans('TooltipIssueDate')).'</td><td>';
	if ($action == 'edit' && $permwrite) {
		print $form->selectDate($object->issue_date, 'issue_date', 0, 0, 0, '', 1, 1);
	} else {
		print dol_print_date($object->issue_date, 'day');
	}
	print '</td></tr>';

	// Reported via
	print '<tr><td>'.$form->textwithpicto($langs->trans('ReportedVia'), $langs->trans('TooltipReportedVia')).'</td><td>';
	if ($action == 'edit' && $permwrite) {
		$via_options = array('phone'=>$langs->trans('ReportedViaPhone'),'email'=>$langs->trans('ReportedViaEmail'),'onsite'=>$langs->trans('ReportedViaOnSite'),'other'=>$langs->trans('ReportedViaOther'));
		print Form::selectarray('reported_via', $via_options, $object->reported_via, 0, 0, 0, '', 0, 0, 0, '', 'flat');
	} else {
		$via_labels = array('phone'=>'ReportedViaPhone','email'=>'ReportedViaEmail','onsite'=>'ReportedViaOnSite','other'=>'ReportedViaOther');
		print $langs->trans(isset($via_labels[$object->reported_via]) ? $via_labels[$object->reported_via] : 'ReportedViaOther');
	}
	print '</td></tr>';

	// Linked Call (pbxcalls integration — optional)
	if (isModEnabled('pbxcalls')) {
		print '<tr><td>'.$form->textwithpicto($langs->trans('LinkedCall'), $langs->trans('TooltipLinkedCall')).'</td><td>';
		if ($action == 'edit' && $permwrite) {
			// Dropdown of recent calls for this company
			$call_options = array(0 => '('.$langs->trans('None').')');
			if (!empty($object->fk_soc)) {
				global $conf;
				$sql_calls  = "SELECT rowid, ref, caller_number, caller_name, call_start";
				$sql_calls .= " FROM ".MAIN_DB_PREFIX."pbxcalls_call";
				$sql_calls .= " WHERE fk_soc = ".((int) $object->fk_soc);
				$sql_calls .= " AND entity = ".((int) $conf->entity);
				$sql_calls .= " ORDER BY call_start DESC LIMIT 30";
				$res_calls = $db->query($sql_calls);
				if ($res_calls) {
					while ($c = $db->fetch_object($res_calls)) {
						$call_label = dol_escape_htmltag($c->ref);
						if ($c->caller_name) {
							$call_label .= ' — '.dol_escape_htmltag($c->caller_name);
						} elseif ($c->caller_number) {
							$call_label .= ' — '.dol_escape_htmltag($c->caller_number);
						}
						if ($c->call_start) {
							$call_label .= ' ('.dol_print_date($db->jdate($c->call_start), 'dayhour').')';
						}
						$call_options[(int) $c->rowid] = $call_label;
					}
				}
			}
			print Form::selectarray('fk_pbxcall', $call_options, (int) $object->fk_pbxcall, 0, 0, 0, '', 0, 0, 0, '', 'flat');
		} else {
			if (!empty($object->fk_pbxcall)) {
				print img_picto('', 'phoning', 'class="pictofixedwidth"');
				print '<a href="'.dol_buildpath('/pbxcalls/call_card.php', 1).'?id='.$object->fk_pbxcall.'">';
				// Fetch call ref for display
				$sql_cr  = "SELECT ref, caller_name, caller_number FROM ".MAIN_DB_PREFIX."pbxcalls_call";
				$sql_cr .= " WHERE rowid = ".((int) $object->fk_pbxcall)." AND entity = ".((int) $conf->entity);
				$res_cr = $db->query($sql_cr);
				if ($res_cr && ($cr = $db->fetch_object($res_cr))) {
					print dol_escape_htmltag($cr->ref);
					if ($cr->caller_name) {
						print ' — '.dol_escape_htmltag($cr->caller_name);
					} elseif ($cr->caller_number) {
						print ' — '.dol_escape_htmltag($cr->caller_number);
					}
				} else {
					print '#'.$object->fk_pbxcall;
				}
				print '</a>';
			} else {
				print '<span class="opacitymedium">'.$langs->trans('None').'</span>';
			}
		}
		print '</td></tr>';
	}

	// Assigned to
	print '<tr><td>'.$langs->trans('AssignedTo').'</td><td>';
	if ($action == 'edit' && $permwrite) {
		print $form->select_dolusers($object->fk_user_assigned, 'fk_user_assigned', 1);
	} else {
		if ($object->fk_user_assigned) {
			$u = new User($db);
			$u->fetch($object->fk_user_assigned);
			print dol_escape_htmltag($u->getFullName($langs));
		}
	}
	print '</td></tr>';

	// Customer site
	print '<tr><td>'.$form->textwithpicto($langs->trans('CustomerSite'), $langs->trans('TooltipCustomerSite')).'</td><td>';
	if ($action == 'edit' && $permwrite) {
		print '<input type="text" name="customer_site" class="minwidth200" value="'.dol_escape_htmltag($object->customer_site).'">';
	} else {
		print dol_escape_htmltag($object->customer_site);
	}
	print '</td></tr>';

	// Project link (view only)
	if (isModEnabled('project') && $object->fk_project) {
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
		$proj = new Project($db);
		if ($proj->fetch($object->fk_project) > 0) {
			print '<tr><td>'.$langs->trans('Project').'</td>';
			print '<td><a href="'.DOL_URL_ROOT.'/projet/card.php?id='.$proj->id.'">'.dol_escape_htmltag($proj->ref).' &mdash; '.dol_escape_htmltag($proj->title).'</a></td></tr>';
		}
	}

	print '</table>';
	print '</div>'; // fichehalfleft

	print '<div class="fichehalfright">';
	print '<table class="border centpercent tableforfield">';

	// Issue description
	print '<tr><td class="titlefield tdtop">'.$form->textwithpicto($langs->trans('IssueDescription'), $langs->trans('TooltipIssueDescription')).'</td><td>';
	if ($action == 'edit' && $permwrite) {
		print '<textarea name="issue_description" class="centpercent" rows="5">'.dol_escape_htmltag($object->issue_description, 1, 1).'</textarea>';
	} else {
		print dol_nl2br(dol_escape_htmltag($object->issue_description, 0, 1));
	}
	print '</td></tr>';

	// Resolution type becomes relevant only after diagnosis has been completed.
	if (in_array($object->status, array(
		SvcRequest::STATUS_IN_PROGRESS,
		SvcRequest::STATUS_AWAIT_RETURN,
		SvcRequest::STATUS_RESOLVED,
		SvcRequest::STATUS_CLOSED,
	)) || (!empty($object->resolution_type) && $object->status != SvcRequest::STATUS_CANCELLED)) {
		print '<tr><td>'.$form->textwithpicto($langs->trans('ResolutionType'), $langs->trans('TooltipResolutionType')).'</td><td>';
		if ($action == 'edit' && $permwrite && in_array($object->status, array(SvcRequest::STATUS_IN_PROGRESS, SvcRequest::STATUS_AWAIT_RETURN))) {
			print Form::selectarray('resolution_type', svcrequest_resolution_types(), $object->resolution_type, 1, 0, 0, '', 0, 0, 0, '', 'flat minwidth200');
		} elseif ($object->status == SvcRequest::STATUS_IN_PROGRESS && $permwrite) {
			print '<form action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" method="POST" style="display:inline">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="set_resolution_type">';
			print Form::selectarray('resolution_type', svcrequest_resolution_types(), $object->resolution_type, 1, 0, 0, '', 0, 0, 0, '', 'flat minwidth200');
			print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
			print '</form>';
			if (empty($object->resolution_type)) {
				print ' <span class="opacitymedium">'.$langs->trans('SvcChooseResolutionAfterDiagnosis').'</span>';
			}
		} else {
			print !empty($object->resolution_type)
				? svcrequest_resolution_label($object->resolution_type)
				: '<span class="opacitymedium">&mdash;</span>';
		}
		print '</td></tr>';
	}

	// Warranty
	print '<tr><td>'.$langs->trans('WarrantyStatus').'</td><td>';
	print svcwarranty_status_badge($object->warranty_status);
	if ($object->fk_warranty) {
		print ' &nbsp;<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_card.php?id='.$object->fk_warranty.'">'.$langs->trans('ViewWarranty').'</a>';
	}
	print '</td></tr>';

	// Billable
	print '<tr><td>'.$form->textwithpicto($langs->trans('Billable'), $langs->trans('TooltipBillable')).'</td><td>';
	if ($action == 'edit' && $permwrite) {
		print '<input type="checkbox" name="billable" value="1"'.($object->billable ? ' checked' : '').'>';
	} else {
		print $object->billable
			? '<span class="badge status8">'.$langs->trans('Yes').'</span>'
			: '<span class="opacitymedium">'.$langs->trans('No').'</span>';
	}
	print '</td></tr>';

	// Resolution notes (visible once in progress)
	if (!in_array($object->status, array(SvcRequest::STATUS_DRAFT, SvcRequest::STATUS_VALIDATED)) || $action == 'edit') {
		print '<tr><td class="tdtop">'.$form->textwithpicto($langs->trans('ResolutionNotes'), $langs->trans('TooltipResolutionNotes')).'</td><td>';
		if ($action == 'edit' && $permwrite) {
			print '<textarea name="resolution_notes" class="centpercent" rows="3">'.dol_escape_htmltag($object->resolution_notes, 1, 1).'</textarea>';
		} else {
			print dol_nl2br(dol_escape_htmltag($object->resolution_notes, 0, 1));
		}
		print '</td></tr>';
	}

	// Security seal number (visible once in progress)
	if (!in_array($object->status, array(SvcRequest::STATUS_DRAFT, SvcRequest::STATUS_VALIDATED)) || $action == 'edit') {
		print '<tr><td>'.$form->textwithpicto($langs->trans('SealNumber'), $langs->trans('TooltipSealNumber')).'</td><td>';
		if ($action == 'edit' && $permwrite) {
			print '<input type="text" name="seal_number" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('SealNumberPlaceholder')).'" value="'.dol_escape_htmltag($object->seal_number).'">';
		} else {
			if ($object->seal_number) {
				print img_picto('', 'lock', 'class="pictofixedwidth"');
				print '<strong>'.dol_escape_htmltag($object->seal_number).'</strong>';
			} else {
				print '<span class="opacitymedium">&mdash;</span>';
			}
		}
		print '</td></tr>';
	}

	// Extrafields in view/edit
	if ($action == 'edit' && $permwrite) {
		print $object->showOptionals($extrafields, 'edit');
	} else {
		print $object->showOptionals($extrafields, 'view');
	}

	print '</table>';
	print '</div>'; // fichehalfright
	print '</div>'; // fichecenter
	print '<div class="clearboth"></div>'; // clear floated half-columns before movement panel

	// =====================================================================
	// RMA ACTION PANEL — Replacement Order & Return Reception
	// =====================================================================
	$res_type = $object->resolution_type;
	$s        = $object->status;

	$has_outbound     = in_array($res_type, $types_with_outbound);
	$use_customerreturn = getDolGlobalString('WARRANTYSVC_USE_CUSTOMERRETURN') && isModEnabled('customerreturn');
	$has_return       = in_array($res_type, $types_with_return)
		|| $use_customerreturn
		|| !empty($object->fk_reception);
	$has_intervention = in_array($res_type, $types_intervention);
	$is_no_movement   = in_array($res_type, $types_no_movement);

	if (!$is_no_movement) {
		// Fetch warranty product + serial for pre-population of return reception
		$warranty_product_id    = 0;
		$warranty_product_label = '';
		$warranty_serial        = '';
		if (!empty($object->fk_warranty)) {
			require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
			$_war = new SvcWarranty($db);
			if ($_war->fetch($object->fk_warranty) > 0) {
				$warranty_product_id = (int) $_war->fk_product;
				$warranty_serial     = $_war->serial;
				if ($warranty_product_id > 0) {
					require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
					$_wprod = new Product($db);
					if ($_wprod->fetch($warranty_product_id) > 0) {
						$warranty_product_label = $_wprod->label;
					}
				}
			}
		}

		$active_status = in_array($s, array(SvcRequest::STATUS_VALIDATED, SvcRequest::STATUS_DIAGNOSING, SvcRequest::STATUS_IN_PROGRESS, SvcRequest::STATUS_AWAIT_RETURN));

		print '<br>';
		print '<div class="div-table-responsive">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td colspan="2">'.img_picto('', 'truck', 'class="pictofixedwidth"').$langs->trans('RMAActions').'</td>';
		print '</tr>';

		// --- Replacement Order / Shipment rows ---
		// A warranty claim can carry multiple replacement orders when additional
		// parts are needed for the same problem. Each linked sales order shows on
		// its own row; the "Add Replacement Order" button remains available while
		// the claim is active so more orders can be attached.
		if ($has_outbound) {
			// Direct $0 shipment row (when the original "Ship Replacement Unit" flow was used)
			if (!empty($object->fk_shipment)) {
				require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
				$_exp = new Expedition($db);
				$_exp_exists = ($_exp->fetch($object->fk_shipment) > 0);

				print '<tr class="oddeven">';
				print '<td style="padding:8px 12px; font-weight:bold; width:220px;">'.img_picto('', 'rightarrow', 'class="pictofixedwidth"').$langs->trans('ReplacementOrder').'</td>';
				print '<td style="padding:8px 12px;">';
				if ($_exp_exists) {
					print img_picto('', 'dolly', 'class="pictofixedwidth"');
					print '<a href="'.DOL_URL_ROOT.'/expedition/card.php?id='.$object->fk_shipment.'">'.$langs->trans('Shipment').' '.$_exp->ref.'</a>';
					if ($object->serial_out) {
						print ' &mdash; '.$langs->trans('Serial').': <strong>'.dol_escape_htmltag($object->serial_out).'</strong>';
					}
				} else {
					print '<span class="opacitymedium" style="text-decoration:line-through;">'.img_picto('', 'dolly', 'class="pictofixedwidth"').$langs->trans('Shipment').' #'.$object->fk_shipment.' ('.$langs->trans('Deleted').')</span>';
					if ($permwrite) {
						print ' <a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=clearorphanshipment&token='.newToken().'" class="butActionDelete" style="margin-left:8px;">'.$langs->trans('RemoveLink').'</a>';
					}
				}
				print '</td></tr>';
			}

			// All linked sales orders (via element_element, regardless of source-type spelling)
			$linked_orders = $object->getLinkedCommandes();
			foreach ($linked_orders as $_ord) {
				print '<tr class="oddeven">';
				print '<td style="padding:8px 12px; font-weight:bold; width:220px;">'.img_picto('', 'rightarrow', 'class="pictofixedwidth"').$langs->trans('ReplacementOrder').'</td>';
				print '<td style="padding:8px 12px;">';
				print $_ord->getNomUrl(1).' '.$_ord->getLibStatut(5);
				if ((int) $_ord->id === (int) $object->fk_commande && $object->serial_out) {
					print ' &mdash; '.$langs->trans('Serial').': <strong>'.dol_escape_htmltag($object->serial_out).'</strong>';
				}
				print '</td></tr>';
			}

			// Orphan: fk_commande set but its commande was deleted (won't appear in the linked list)
			if (!empty($object->fk_commande) && empty($linked_orders)) {
				print '<tr class="oddeven">';
				print '<td style="padding:8px 12px; font-weight:bold; width:220px;">'.img_picto('', 'rightarrow', 'class="pictofixedwidth"').$langs->trans('ReplacementOrder').'</td>';
				print '<td style="padding:8px 12px;">';
				print '<span class="opacitymedium" style="text-decoration:line-through;">'.img_picto('', 'order', 'class="pictofixedwidth"').$langs->trans('Order').' #'.$object->fk_commande.' ('.$langs->trans('Deleted').')</span>';
				if ($permwrite) {
					print ' <a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=clearorphanorder&token='.newToken().'" class="butActionDelete" style="margin-left:8px;">'.$langs->trans('RemoveLink').'</a>';
				}
				print '</td></tr>';
			}

			// Action row: always offer "Add Replacement Order" while the claim is active.
			// "Ship Replacement Unit" (direct $0 shipment) is offered only the first time,
			// before any shipment or order exists — it's the single-swap shortcut.
			if ($active_status && $permwrite) {
				$nothing_yet = empty($object->fk_shipment) && empty($linked_orders);

				print '<tr class="oddeven">';
				print '<td style="padding:8px 12px; font-weight:bold; width:220px;">&nbsp;</td>';
				print '<td style="padding:8px 12px;">';
				if ($nothing_yet && isModEnabled('shipping')) {
					print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=createreplacementshipment&token='.newToken().'" class="butAction" style="margin:0;">'.$langs->trans('ShipReplacementUnit').'</a> ';
				}
				if (isModEnabled('order')) {
					$btnlabel = $nothing_yet ? 'CreateReplacementOrder' : 'AddReplacementOrder';
					print '<a href="'.dol_escape_htmltag(DOL_URL_ROOT.'/commande/card.php?action=create&socid='.((int) $object->fk_soc).'&rma_sr_id='.((int) $object->id).'&backtopage='.urlencode(DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$object->id)).'" class="butAction" style="margin:0;">'.$langs->trans($btnlabel).'</a>';
				}
				print '</td></tr>';
			} elseif (empty($object->fk_shipment) && empty($linked_orders) && empty($object->fk_commande)) {
				print '<tr class="oddeven">';
				print '<td style="padding:8px 12px; font-weight:bold; width:220px;">'.img_picto('', 'rightarrow', 'class="pictofixedwidth"').$langs->trans('ReplacementOrder').'</td>';
				print '<td style="padding:8px 12px;"><span class="opacitymedium">'.$langs->trans('NoReplacementOrderYet').'</span></td></tr>';
			}
		}

		// --- Return Reception row ---
		if ($has_return) {
			print '<tr class="oddeven">';
			$return_label = $use_customerreturn ? $langs->trans('CustomerReturn') : $langs->trans('ReturnReception');
			print '<td style="padding:8px 12px; font-weight:bold; width:220px;">'.img_picto('', 'leftarrow', 'class="pictofixedwidth"').$return_label.'</td>';
			print '<td style="padding:8px 12px;">';

			if ($use_customerreturn) {
				// --- Customer Returns module integration ---
				// Check if a customerreturn is already linked via element_element
				$linked_return_id = 0;
				$linked_return_ref = '';
				$linked_return_exists = false;
				$linked_ee_rowid = 0;
				$sql_cr = "SELECT rowid, fk_target FROM ".MAIN_DB_PREFIX."element_element WHERE fk_source = ".((int) $object->id)." AND sourcetype = 'warrantysvc_svcrequest' AND targettype IN ('customerreturn', 'customerreturn_customerreturn') LIMIT 1";
				$res_cr = $db->query($sql_cr);
				if ($res_cr && ($row_cr = $db->fetch_object($res_cr))) {
					$linked_return_id = (int) $row_cr->fk_target;
					$linked_ee_rowid = (int) $row_cr->rowid;
					dol_include_once('/customerreturn/class/customerreturn.class.php');
					$_cr = new CustomerReturn($db);
					if ($_cr->fetch($linked_return_id) > 0) {
						$linked_return_ref = $_cr->ref;
						$linked_return_exists = true;
					}
				}

				if ($linked_return_id > 0 && $linked_return_exists) {
					// Linked and record exists — show clickable link
					print img_picto('', 'dollyrevert', 'class="pictofixedwidth"');
					print '<a href="'.DOL_URL_ROOT.'/custom/customerreturn/customerreturn_card.php?id='.$linked_return_id.'">'
						.$langs->trans('CustomerReturn').' '.$linked_return_ref.'</a>';
				} elseif ($linked_return_id > 0 && !$linked_return_exists) {
					// Orphaned link — record was deleted
					print '<span class="opacitymedium" style="text-decoration:line-through;">'
						.img_picto('', 'dollyrevert', 'class="pictofixedwidth"')
						.$langs->trans('CustomerReturn').' #'.$linked_return_id
						.' ('.$langs->trans('Deleted').')</span>';
					if ($permwrite && $linked_ee_rowid > 0) {
						print ' <a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=remove_orphan_return_link&token='.newToken().'&link_id='.$linked_ee_rowid.'" class="butActionDelete" style="margin-left:8px;">'.$langs->trans('RemoveLink').'</a>';
					}
					// Show create button again since the return no longer exists
					if ($active_status && $permwrite) {
						print '<br>';
						$cr_url = DOL_URL_ROOT.'/custom/customerreturn/customerreturn_card.php?action=create&socid='.((int) $object->fk_soc).'&from_svcrequest='.((int) $object->id);
						if (!empty($object->fk_shipment)) {
							$cr_url .= '&fk_expedition='.((int) $object->fk_shipment);
						}
						print '<a href="'.dol_escape_htmltag($cr_url).'" class="butAction" style="margin:0;">'
							.$langs->trans('CreateCustomerReturn').'</a>';
					}
				} elseif ($active_status && $permwrite) {
					// No link — show create button
					$cr_url = DOL_URL_ROOT.'/custom/customerreturn/customerreturn_card.php?action=create&socid='.((int) $object->fk_soc).'&from_svcrequest='.((int) $object->id);
					if (!empty($object->fk_shipment)) {
						$cr_url .= '&fk_expedition='.((int) $object->fk_shipment);
					}
					print '<a href="'.dol_escape_htmltag($cr_url).'" class="butAction" style="margin:0;">'
						.$langs->trans('CreateCustomerReturn').'</a>';
				} else {
					print '<span class="opacitymedium">'.$langs->trans('NoReturnReceptionYet').'</span>';
				}
			} else {
				// --- Fallback: native Reception flow ---
				if (!empty($object->fk_reception)) {
					require_once DOL_DOCUMENT_ROOT.'/reception/class/reception.class.php';
					$_rec = new Reception($db);
					$_rec_label = ($_rec->fetch($object->fk_reception) > 0 && $_rec->ref) ? $_rec->ref : '#'.$object->fk_reception;
					print img_picto('', 'reception', 'class="pictofixedwidth"');
					print '<a href="'.DOL_URL_ROOT.'/reception/card.php?id='.$object->fk_reception.'">'.$langs->trans('Reception').' '.$_rec_label.'</a>';
					if ($object->serial_in) {
						print ' &mdash; '.$langs->trans('SerialIn').': <strong>'.dol_escape_htmltag($object->serial_in).'</strong>';
					}
				} elseif ($active_status && $permwrite && isModEnabled('reception')) {
					$form_id2 = 'form_rec_'.$object->id;
					print '<a href="#" onclick="document.getElementById(\''.$form_id2.'\').style.display=\'block\';this.style.display=\'none\';return false;" class="butAction" style="margin:0;">'.$langs->trans('CreateReturnReception').'</a>';
					print '<div id="'.$form_id2.'" style="display:none; margin-top:8px;">';
					print '<form action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" method="POST">';
					print '<input type="hidden" name="token" value="'.newToken().'">';
					print '<input type="hidden" name="action" value="create_return_reception">';
					if ($warranty_product_id > 0) {
						print '<input type="hidden" name="rec_product_id" value="'.$warranty_product_id.'">';
						print '<strong>'.dol_escape_htmltag($warranty_product_label ?: $langs->trans('Product').' #'.$warranty_product_id).'</strong>';
						print ' ';
					}
					if ($warranty_serial) {
						print '<input type="hidden" name="serial_in" value="'.dol_escape_htmltag($warranty_serial).'">';
						print '<span class="opacitymedium">'.dol_escape_htmltag($warranty_serial).'</span>';
						print ' ';
					} else {
						print '<input type="text" name="serial_in" class="minwidth120" placeholder="'.$langs->trans('SerialIn').'" value=""> ';
					}
					print $form->select_warehouse($object->fk_warehouse_return > 0 ? $object->fk_warehouse_return : 0, 'rec_warehouse', 1, '', 1, 0, '', 0, 0, array(), 'minwidth150');
					print ' <input type="submit" class="butAction" style="margin:0;" value="'.$langs->trans('SvcConfirm').'">';
					print '</form>';
					print '</div>';
				} else {
					print '<span class="opacitymedium">'.$langs->trans('NoReturnReceptionYet').'</span>';
				}
			}

			print '</td>';
			print '</tr>';
		}

		// --- Intervention row ---
		if ($has_intervention) {
			$inter_done = !empty($object->fk_intervention);
			print '<tr class="oddeven">';
			print '<td style="padding:8px 12px; font-weight:bold; width:220px;">'.img_picto('', 'intervention', 'class="pictofixedwidth"').$langs->trans('OnSiteIntervention').'</td>';
			print '<td style="padding:8px 12px;">';
			if ($inter_done) {
				print img_picto('', 'intervention', 'class="pictofixedwidth"');
				print '<a href="'.DOL_URL_ROOT.'/fichinter/card.php?id='.$object->fk_intervention.'">'.$langs->trans('Intervention').' #'.$object->fk_intervention.'</a>';
			} elseif ($permwrite && in_array($s, array(SvcRequest::STATUS_VALIDATED, SvcRequest::STATUS_IN_PROGRESS)) && isModEnabled('ficheinter')) {
				print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=create_intervention&token='.newToken().'" class="butAction" style="margin:0;">'.$langs->trans('ScheduleIntervention').'</a>';
			} else {
				print '<span class="opacitymedium">'.$langs->trans('NotYetScheduled').'</span>';
			}
			print '</td>';
			print '</tr>';
		}

		// --- Invoice link ---
		if ($object->fk_facture) {
			print '<tr class="oddeven">';
			print '<td style="padding:8px 12px; font-weight:bold; width:220px;">'.img_picto('', 'bill', 'class="pictofixedwidth"').$langs->trans('Invoice').'</td>';
			print '<td style="padding:8px 12px;">';
			print '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.$object->fk_facture.'">'.$langs->trans('Invoice').' #'.$object->fk_facture.'</a>';
			print '</td>';
			print '</tr>';
		}

		print '</table>';
		print '</div>';
	}

	print dol_get_fiche_end();

	// ---- Linked objects block ----
	if ($action != 'edit' && $object->id > 0) {
		$tmparray = $form->showLinkToObjectBlock($object, array(), array('svcrequest'), 1);
		$linktoelem = isset($tmparray['linktoelem']) ? $tmparray['linktoelem'] : '';
		$htmltoenteralink = isset($tmparray['htmltoenteralink']) ? $tmparray['htmltoenteralink'] : '';
		print $htmltoenteralink;
		$somethingshown = $form->showLinkedObjectBlock($object, $linktoelem);
	}

	// =====================================================================
	// ACTION BUTTONS — outside the fiche card per Dolibarr standard layout
	// =====================================================================
	print '<div class="tabsAction">';

	if ($action == 'edit') {
		print '<input type="submit" class="butAction" value="'.$langs->trans('Save').'">';
		print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" class="butActionDelete">'.$langs->trans('Cancel').'</a>';
	} else {
		// Edit: available while request is not yet closed/cancelled
		if ($permwrite && in_array($s, array(SvcRequest::STATUS_DRAFT, SvcRequest::STATUS_VALIDATED, SvcRequest::STATUS_DIAGNOSING, SvcRequest::STATUS_IN_PROGRESS, SvcRequest::STATUS_AWAIT_RETURN))) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=edit&token='.newToken().'" class="butAction">'.$langs->trans('Modify').'</a>';
		}

		// Native Dolibarr email form: partner + partner contacts, svcrequest
		// templates, WarrantySvc substitutions and optional PDF attachment.
		// Preselect explicitly assigned customer-side contacts; when there is no
		// usable assigned contact, fall back to the customer's default email.
		if ($permwrite && $action != 'presend') {
			$mailurl = dolBuildUrl($_SERVER['PHP_SELF'], array('id' => $object->id, 'action' => 'presend', 'mode' => 'init'), true);
			foreach (warrantysvc_default_customer_email_receivers($object) as $receiverKey) {
				$mailurl .= '&receiver%5B%5D='.urlencode((string) $receiverKey);
			}
			$mailurl .= '#formmailbeforetitle';
			print dolGetButtonAction('', $langs->trans('SendMail'), 'email', $mailurl, '');
		}

		// DRAFT → Validate
		if ($s == SvcRequest::STATUS_DRAFT && $permvalidate) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_validate&token='.newToken().'" class="butAction">'.$langs->trans('ValidateSvcRequest').'</a>';
		}

		// VALIDATED → Begin Diagnosis
		if ($s == SvcRequest::STATUS_VALIDATED && $permwrite) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_setdiagnosing&token='.newToken().'" class="butAction">'.$langs->trans('BeginDiagnosis').'</a>';
		}

		// DIAGNOSING → troubleshoot / finish diagnosis. Physical Customer Return is
		// available independently in the RMA panel and does not require a solution yet.
		if ($s == SvcRequest::STATUS_DIAGNOSING && $permwrite) {
			print '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/troubleshoot.php?id='.$object->id.'" class="butAction">'.$langs->trans('OpenTroubleshoot').'</a>';
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_setinprogress&token='.newToken().'" class="butAction">'.$langs->trans('CompleteDiagnosis').'</a>';
		}

		// IN PROGRESS — resolution-type-specific next actions
		if ($s == SvcRequest::STATUS_IN_PROGRESS && $permwrite) {
			// Guidance / Informational: nothing to dispatch — just resolve
			if ($is_no_movement) {
				print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_resolve&token='.newToken().'" class="butAction">'.$langs->trans('MarkResolved').'</a>';
			}
			// Mark resolved available for all types once movement is done
			if (!$is_no_movement) {
				print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_resolve&token='.newToken().'" class="butAction">'.$langs->trans('MarkResolved').'</a>';
			}
		}

		// AWAITING RETURN — can still mark resolved (e.g., return confirmed out-of-band)
		if ($s == SvcRequest::STATUS_AWAIT_RETURN && $permwrite) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_resolve&token='.newToken().'" class="butAction">'.$langs->trans('MarkResolved').'</a>';
		}

		// RESOLVED / IN_PROGRESS / AWAIT_RETURN → Close
		if (in_array($s, array(SvcRequest::STATUS_RESOLVED, SvcRequest::STATUS_IN_PROGRESS, SvcRequest::STATUS_AWAIT_RETURN)) && $permclose) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=confirm_close&token='.newToken().'" class="butAction">'.$langs->trans('CloseSvcRequest').'</a>';
		}

		// RESOLVED / CLOSED / CANCELLED → Re-open
		if (in_array($s, array(SvcRequest::STATUS_RESOLVED, SvcRequest::STATUS_CLOSED, SvcRequest::STATUS_CANCELLED)) && $permclose) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=reopen&token='.newToken().'" class="butActionDelete">'.$langs->trans('ReopenSvcRequest').'</a>';
		}

		// DRAFT / VALIDATED / DIAGNOSING / IN_PROGRESS / AWAIT_RETURN → Cancel
		if (in_array($s, array(SvcRequest::STATUS_DRAFT, SvcRequest::STATUS_VALIDATED, SvcRequest::STATUS_DIAGNOSING, SvcRequest::STATUS_IN_PROGRESS, SvcRequest::STATUS_AWAIT_RETURN)) && $permwrite) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=cancel&token='.newToken().'" class="butActionDelete">'.$langs->trans('CancelSvcRequest').'</a>';
		}

		// DRAFT / CANCELLED → Delete
		if (in_array($s, array(SvcRequest::STATUS_DRAFT, SvcRequest::STATUS_CANCELLED)) && $permdelete) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete&token='.newToken().'" class="butActionDelete">'.$langs->trans('Delete').'</a>';
		}
	}

	print '</div>'; // tabsAction

	// Close edit form
	if ($action == 'edit') {
		print '</form>';
	}

	// =====================================================================
	// COMPONENT LINES — only relevant for component dispatch types
	// =====================================================================
	if (in_array($res_type, array('component', 'component_return'))) {
		print '<br>';
		print '<a name="components"></a>';
		print '<div class="div-table-responsive">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.img_picto('', 'product', 'class="pictofixedwidth"').$langs->trans('ComponentLines').'</td>';
		print '<td>'.$langs->trans('Description').'</td>';
		print '<td class="center">'.$langs->trans('Qty').'</td>';
		print '<td>'.$langs->trans('Type').'</td>';
		print '<td class="center">'.$langs->trans('Status').'</td>';
		if ($permwrite && in_array($s, array(SvcRequest::STATUS_VALIDATED, SvcRequest::STATUS_IN_PROGRESS, SvcRequest::STATUS_AWAIT_RETURN))) {
			print '<td></td>';
		}
		print '</tr>';

		if (!empty($object->lines)) {
			foreach ($object->lines as $line) {
				require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
				$prod = new Product($db);
				$prod->fetch($line->fk_product);
				$shipped_badge = $line->shipped
					? '<span class="badge status6">'.$langs->trans('SvcShipped').'</span>'
					: '<span class="badge status0">'.$langs->trans('Pending').'</span>';
				print '<tr class="oddeven">';
				print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$prod->id.'">'.dol_escape_htmltag($prod->ref).'</a></td>';
				print '<td>'.dol_escape_htmltag($line->description).'</td>';
				print '<td class="center">'.dol_escape_htmltag($line->qty).'</td>';
				print '<td>'.dol_escape_htmltag($line->line_type).'</td>';
				print '<td class="center">'.$shipped_badge.'</td>';
				if ($permwrite && in_array($s, array(SvcRequest::STATUS_VALIDATED, SvcRequest::STATUS_IN_PROGRESS, SvcRequest::STATUS_AWAIT_RETURN))) {
					print '<td class="center"><a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=deleteline&lineid='.$line->id.'&token='.newToken().'" class="reposition">'.img_picto($langs->trans('Delete'), 'delete').'</a></td>';
				}
				print '</tr>';
			}
		}

		// Add line form
		if ($permwrite && in_array($s, array(SvcRequest::STATUS_VALIDATED, SvcRequest::STATUS_IN_PROGRESS, SvcRequest::STATUS_AWAIT_RETURN))) {
			print '<tr>';
			print '<form action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" method="POST">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="addline">';
			print '<td>'.$form->select_produits(0, 'product_id', '', 0, 0, 1, 2, '', 0, array(), 0, 0, 0, 'minwidth150').'</td>';
			print '<td><input type="text" name="line_desc" class="minwidth150" placeholder="'.$langs->trans('Description').'"></td>';
			print '<td class="center"><input type="number" name="qty" value="1" min="1" style="width:50px"></td>';
			print '<td>';
			$line_types = array('component_out'=>$langs->trans('ComponentOut'),'component_in'=>$langs->trans('ComponentIn'));
			print Form::selectarray('line_type', $line_types, 'component_out', 0, 0, 0, '', 0, 0, 0, '', 'flat');
			print '</td>';
			print '<td colspan="2"><input type="submit" name="addline" class="button small" value="'.$langs->trans('Add').'"></td>';
			print '</form>';
			print '</tr>';
		}

		print '</table>';
		print '</div>';
	}

	// =====================================================================
	// SUPPLIER SERVICE / RMA — child lifecycle objects
	// =====================================================================
	if ($permsupplierrmaread && $object->id > 0 && $action != 'edit') {
		$supplierRmas = SvcSupplierRma::fetchAllForServiceRequest($db, $object->id, $object->entity);
		print '<br>';
		print '<a name="supplier-rma"></a>';
		print load_fiche_titre($langs->trans('SupplierServiceRma'), '', 'tools');
		print '<div class="div-table-responsive">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('Ref').'</td>';
		print '<td>'.$langs->trans('Supplier').'</td>';
		print '<td>'.$langs->trans('SupplierRmaExternalRef').'</td>';
		print '<td>'.$langs->trans('Status').'</td>';
		print '<td>'.$langs->trans('OutboundTracking').'</td>';
		print '<td>'.$langs->trans('ReturnTracking').'</td>';
		print '</tr>';

		if (empty($supplierRmas)) {
			print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoSupplierRma').'</span></td></tr>';
		} else {
			foreach ($supplierRmas as $supplierRma) {
				$supplier = new Societe($db);
				$supplierLabel = '—';
				if ($supplier->fetch($supplierRma->fk_soc_supplier) > 0) {
					$supplierLabel = $supplier->getNomUrl(1, 'supplier');
				}
				$outbound = dol_escape_htmltag($supplierRma->outbound_tracking ?: '—');
				if (!empty($supplierRma->outbound_tracking) && !empty($supplierRma->outbound_tracking_url)) {
					$outbound = '<a href="'.dol_escape_htmltag($supplierRma->outbound_tracking_url).'" target="_blank" rel="noopener">'.$outbound.'</a>';
				}
				$returnTracking = dol_escape_htmltag($supplierRma->return_tracking ?: '—');
				if (!empty($supplierRma->return_tracking) && !empty($supplierRma->return_tracking_url)) {
					$returnTracking = '<a href="'.dol_escape_htmltag($supplierRma->return_tracking_url).'" target="_blank" rel="noopener">'.$returnTracking.'</a>';
				}

				print '<tr class="oddeven">';
				print '<td>'.$supplierRma->getNomUrl(1).'</td>';
				print '<td>'.$supplierLabel.'</td>';
				print '<td>'.dol_escape_htmltag($supplierRma->supplier_rma_ref ?: '—').'</td>';
				print '<td>'.$supplierRma->getLibStatut().'</td>';
				print '<td>'.$outbound.'</td>';
				print '<td>'.$returnTracking.'</td>';
				print '</tr>';
			}
		}
		print '</table>';
		print '</div>';

		if ($permsupplierrmawrite) {
			print '<div class="tabsAction">';
			print '<a class="butAction" href="'.DOL_URL_ROOT.'/custom/warrantysvc/supplier_rma_card.php?action=create&fk_svc_request='.$object->id.'">'.$langs->trans('CreateSupplierRma').'</a>';
			print '</div>';
		}
	}

	// Selecting an email model reloads the same native presend form.
	if (GETPOST('modelselected')) {
		$action = 'presend';
	}

	if ($action == 'presend' && $permwrite) {
		$modelmail = 'svcrequest';
		$defaulttopic = 'SvcRequestEmailSubject';
		$defaulttopiclang = 'warrantysvc@warrantysvc';
		$diroutput = !empty($conf->warrantysvc->multidir_output[$object->entity])
			? $conf->warrantysvc->multidir_output[$object->entity]
			: (!empty($conf->warrantysvc->dir_output) ? $conf->warrantysvc->dir_output : DOL_DATA_ROOT.'/warrantysvc');
		$trackid = 'wsvcsr'.$object->id;
		include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
	}
}

llxFooter();
$db->close();
