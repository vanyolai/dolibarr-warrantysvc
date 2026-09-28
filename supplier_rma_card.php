<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    supplier_rma_card.php
 * \ingroup warrantysvc
 * \brief   Supplier/manufacturer service RMA card
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierrma.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies', 'products', 'stocks'));

$hookmanager->initHooks(array('warrantysvcsupplierrmacard', 'globalcard'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

$permread = $user->hasRight('warrantysvc', 'supplierrma', 'read');
$permwrite = $user->hasRight('warrantysvc', 'supplierrma', 'write');
$permdelete = $user->hasRight('warrantysvc', 'supplierrma', 'delete');
if (!$permread || !empty($user->socid)) {
	accessforbidden();
}

$object = new SvcSupplierRma($db);
$sr = new SvcRequest($db);

if ($id > 0 || $ref) {
	if ($object->fetch($id, $ref) <= 0) {
		recordNotFound('', 0);
		exit;
	}
	if ($sr->fetch($object->fk_svc_request) <= 0) {
		recordNotFound('', 0);
		exit;
	}
	$object->fetch_thirdparty();
} elseif ($action === 'create') {
	$fkSvcRequest = GETPOSTINT('fk_svc_request');
	if ($fkSvcRequest <= 0 || $sr->fetch($fkSvcRequest) <= 0) {
		recordNotFound('', 0);
		exit;
	}
	$object->fk_svc_request = $sr->id;
	$object->fk_product = $sr->fk_product;
	$object->serial_number = $sr->serial_number;
	$object->problem_description = $sr->issue_description;
	$object->diagnosis = $sr->resolution_notes;
	$object->date_request = dol_now();
}

if ($cancel) {
	$target = !empty($object->id)
		? DOL_URL_ROOT.'/custom/warrantysvc/supplier_rma_card.php?id='.$object->id
		: DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$sr->id;
	header('Location: '.$target);
	exit;
}

function warrantysvc_supplier_rma_fill_from_post($object, $identityEditable = true)
{
	$object->fk_svc_request = GETPOSTINT('fk_svc_request');
	if ($identityEditable) {
		$object->fk_soc_supplier = GETPOSTINT('fk_soc_supplier');
		$object->fk_product = GETPOSTINT('fk_product');
		$object->qty = price2num(GETPOST('qty', 'alphanohtml'));
		$object->serial_number = GETPOST('serial_number', 'alphanohtml');
	}
	$object->supplier_rma_ref = GETPOST('supplier_rma_ref', 'alphanohtml');
	$object->outbound_carrier = GETPOST('outbound_carrier', 'alphanohtml');
	$object->outbound_tracking = GETPOST('outbound_tracking', 'alphanohtml');
	$object->outbound_tracking_url = trim(GETPOST('outbound_tracking_url', 'url'));
	$object->return_carrier = GETPOST('return_carrier', 'alphanohtml');
	$object->return_tracking = GETPOST('return_tracking', 'alphanohtml');
	$object->return_tracking_url = trim(GETPOST('return_tracking_url', 'url'));
	$object->result_type = GETPOST('result_type', 'alpha');
	$object->replacement_serial_number = GETPOST('replacement_serial_number', 'alphanohtml');
	$object->problem_description = GETPOST('problem_description', 'restricthtml');
	$object->diagnosis = GETPOST('diagnosis', 'restricthtml');
	$object->accessories_sent = GETPOST('accessories_sent', 'restricthtml');
	$object->fk_warehouse_source = GETPOSTINT('fk_warehouse_source');
	$object->fk_warehouse_return = GETPOSTINT('fk_warehouse_return');
	$object->note_private = GETPOST('note_private', 'restricthtml');
}

/*
 * Actions
 */
if ($action === 'add' && $permwrite) {
	warrantysvc_supplier_rma_fill_from_post($object, true);

	if ($sr->fetch($object->fk_svc_request) <= 0) {
		$object->error = 'ErrorSupplierRmaServiceRequestNotFound';
		$result = -1;
	} else {
		$result = $object->create($user);
	}

	if ($result > 0) {
		setEventMessages($langs->trans('SupplierRmaCreated', $object->ref), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	$action = 'create';
}

if ($action === 'update' && $permwrite && $object->id > 0) {
	$previousRequestId = (int) $object->fk_svc_request;
	$previousWarehouseSource = (int) $object->fk_warehouse_source;
	$previousWarehouseReturn = (int) $object->fk_warehouse_return;
	warrantysvc_supplier_rma_fill_from_post($object, !$object->isIdentityLocked());
	// Parent request and internal stock-routing fields are not edited on this
	// form, so keep their persisted values instead of clearing them on save.
	$object->fk_svc_request = $previousRequestId;
	$object->fk_warehouse_source = $previousWarehouseSource;
	$object->fk_warehouse_return = $previousWarehouseReturn;
	$result = $object->update($user);
	if ($result > 0) {
		setEventMessages($langs->trans('SupplierRmaUpdated'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	$action = 'edit';
}

if ($action === 'setstatus' && $permwrite && $object->id > 0) {
	$newStatus = GETPOST('newstatus', 'alpha');
	$note = GETPOST('status_note', 'restricthtml');
	$result = $object->setStatus($newStatus, $user, $note);
	if ($result > 0) {
		setEventMessages($langs->trans('SupplierRmaStatusUpdated'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action === 'confirm_rollback' && GETPOST('confirm', 'alpha') === 'yes' && $permwrite && $object->id > 0) {
	$result = $object->rollbackStatus($user, $langs->transnoentitiesnoconv('SupplierRmaRollbackAuditNote'));
	if ($result > 0) {
		setEventMessages($langs->trans('SupplierRmaRolledBack'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
	exit;
}

if ($action === 'confirm_delete' && GETPOST('confirm', 'alpha') === 'yes' && $permdelete && $object->id > 0) {
	$srid = (int) $object->fk_svc_request;
	$result = $object->delete($user);
	if ($result > 0) {
		setEventMessages($langs->trans('SupplierRmaDeleted'), null, 'mesgs');
		header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$srid.'#supplier-rma');
		exit;
	}
	setEventMessages($langs->trans($object->error), $object->errors, 'errors');
}

// Native Dolibarr email backend.
// The trigger is fired by actions_sendmails.inc.php only after CMailFile
// successfully sent the message, so the Supplier RMA audit trail reflects
// real outgoing emails instead of merely opening/submitting the mail form.
if ($object->id > 0 && $permwrite) {
	$triggersendname = 'SVCSUPPLIERRMA_SENTBYMAIL';
	$autocopy = '';
	$trackid = 'wsvcsrma'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';
}

/*
 * View
 */
$form = new Form($db);
$formcompany = new FormCompany($db);

$hidedetails = GETPOSTINT('hidedetails') ? 1 : 0;
$hidedesc = GETPOSTINT('hidedesc') ? 1 : 0;
$hideref = GETPOSTINT('hideref') ? 1 : 0;

llxHeader('', $object->id ? $object->ref : $langs->trans('NewSupplierRma'), '');

if ($action === 'create') {
	print load_fiche_titre($langs->trans('NewSupplierRma'), '', 'tools');

	print '<form action="'.$_SERVER['PHP_SELF'].'" method="POST">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="fk_svc_request" value="'.((int) $sr->id).'">';

	print dol_get_fiche_head(array(), '', '', -1);
	print '<table class="border centpercent tableforfieldcreate">';

	print '<tr><td class="fieldrequired">'.$langs->trans('SvcRequest').'</td><td>';
	print '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$sr->id.'">'.dol_escape_htmltag($sr->ref).'</a>';
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Supplier').'</td><td>';
	print $form->select_company($object->fk_soc_supplier, 'fk_soc_supplier', '(s.fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Product').'</td><td>';
	print $form->select_produits($object->fk_product, 'fk_product', '', 0, 0, -1, 2, '', 0, array(), 0, 0, 0, 'minwidth300');
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Qty').'</td><td>';
	print '<input type="number" name="qty" min="0.00000001" step="any" class="width100" value="'.dol_escape_htmltag((string) ($object->qty ?: 1)).'">';
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('SerialNumber').'</td><td>';
	print '<input type="text" name="serial_number" class="minwidth300" value="'.dol_escape_htmltag($object->serial_number).'">';
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('SupplierRmaExternalRef').'</td><td>';
	print '<input type="text" name="supplier_rma_ref" class="minwidth300" value="'.dol_escape_htmltag($object->supplier_rma_ref).'">';
	print '</td></tr>';

	print '<tr><td class="fieldrequired tdtop">'.$langs->trans('SupplierRmaProblemDescription').'</td><td>';
	print '<textarea name="problem_description" class="quatrevingtpercent" rows="6">'.dol_escape_htmltag($object->problem_description).'</textarea>';
	print '</td></tr>';

	print '<tr><td class="tdtop">'.$langs->trans('SupplierRmaDiagnosis').'</td><td>';
	print '<textarea name="diagnosis" class="quatrevingtpercent" rows="5">'.dol_escape_htmltag($object->diagnosis).'</textarea>';
	print '</td></tr>';

	print '<tr><td class="tdtop">'.$langs->trans('SupplierRmaAccessoriesSent').'</td><td>';
	print '<textarea name="accessories_sent" class="quatrevingtpercent" rows="3">'.dol_escape_htmltag($object->accessories_sent).'</textarea>';
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('OutboundCarrier').'</td><td><input type="text" name="outbound_carrier" class="minwidth200"></td></tr>';
	print '<tr><td>'.$langs->trans('OutboundTracking').'</td><td><input type="text" name="outbound_tracking" class="minwidth300"></td></tr>';
	print '<tr><td>'.$langs->trans('OutboundTrackingUrl').'</td><td><input type="url" name="outbound_tracking_url" class="quatrevingtpercent"></td></tr>';

	print '<tr><td class="tdtop">'.$langs->trans('NotePrivate').'</td><td>';
	print '<textarea name="note_private" class="quatrevingtpercent" rows="4"></textarea>';
	print '</td></tr>';

	print '</table>';
	print dol_get_fiche_end();

	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Create').'">';
	print ' &nbsp; ';
	print '<a class="button button-cancel" href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$sr->id.'#supplier-rma">'.$langs->trans('Cancel').'</a>';
	print '</div>';
	print '</form>';

	llxFooter();
	$db->close();
	exit;
}

$head = svcsupplierrma_prepare_head($object);
print dol_get_fiche_head($head, 'card', $langs->trans('SupplierRma'), -1, 'tools');

$formconfirm = '';
if ($action === 'delete') {
	$formconfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$object->id,
		$langs->trans('DeleteSupplierRma'),
		$langs->trans('ConfirmDeleteSupplierRma', $object->ref),
		'confirm_delete',
		'',
		0,
		1
	);
} elseif ($action === 'rollback') {
	$formconfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$object->id,
		$langs->trans('SupplierRmaRollback'),
		$langs->trans('ConfirmSupplierRmaRollback'),
		'confirm_rollback',
		'',
		0,
		1
	);
}
print $formconfirm;

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$sr->id.'#supplier-rma">'.$langs->trans('BackToSvcRequest').'</a>';

$morehtmlref = '<div class="refidno">';
$morehtmlref .= '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$sr->id.'">'.dol_escape_htmltag($sr->ref).'</a>';
if (is_object($object->thirdparty)) {
	$morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1, 'supplier');
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

$isEdit = ($action === 'edit' && $permwrite);
$identityEditable = ($isEdit && !$object->isIdentityLocked());

if ($isEdit) {
	print '<form action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" method="POST">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="fk_svc_request" value="'.((int) $object->fk_svc_request).'">';
}

$trackingRender = function ($tracking, $url) {
	if (empty($tracking)) return '—';
	$label = dol_escape_htmltag($tracking);
	if (!empty($url)) {
		return '<a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener">'.$label.' '.img_picto('', 'globe').'</a>';
	}
	return $label;
};

$supplier = new Societe($db);
$supplierLoaded = ($supplier->fetch($object->fk_soc_supplier) > 0);
$product = new Product($db);
$productLoaded = ($product->fetch($object->fk_product) > 0);

/*
 * Identity and workflow information.
 *
 * Keep this table full-width: Dolibarr product selectors can contain long
 * references/labels, so placing them in a fichehalf column makes the native
 * combo overflow into the logistics column.
 */
print '<div class="fichecenter">';
print '<table class="border centpercent tableforfield">';

print '<tr><td class="titlefield">'.$langs->trans('SvcRequest').'</td><td>';
print '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$sr->id.'">'.dol_escape_htmltag($sr->ref).'</a>';
print '</td></tr>';

print '<tr><td>'.$langs->trans('Supplier').'</td><td>';
if ($identityEditable) {
	print $form->select_company($object->fk_soc_supplier, 'fk_soc_supplier', '(s.fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
} elseif ($supplierLoaded) {
	print $supplier->getNomUrl(1, 'supplier');
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('Product').'</td><td>';
if ($identityEditable) {
	print $form->select_produits($object->fk_product, 'fk_product', '', 0, 0, -1, 2, '', 0, array(), 0, 0, 0, 'minwidth300');
} elseif ($productLoaded) {
	print $product->getNomUrl(1).' - '.dol_escape_htmltag($product->label);
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('Qty').'</td><td>';
if ($identityEditable) {
	print '<input type="number" name="qty" min="0.00000001" step="any" class="width100" value="'.dol_escape_htmltag((string) $object->qty).'">';
} else {
	print price($object->qty, 0, $langs, 0, 0, -1);
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('SerialNumber').'</td><td>';
if ($identityEditable) {
	print '<input type="text" name="serial_number" class="minwidth300" value="'.dol_escape_htmltag($object->serial_number).'">';
} else {
	print dol_escape_htmltag($object->serial_number ?: '—');
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('SupplierRmaExternalRef').'</td><td>';
if ($isEdit) {
	print '<input type="text" name="supplier_rma_ref" class="minwidth300" value="'.dol_escape_htmltag($object->supplier_rma_ref).'">';
} else {
	print dol_escape_htmltag($object->supplier_rma_ref ?: '—');
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut().'</td></tr>';
print '<tr><td>'.$langs->trans('SupplierRmaDateRequest').'</td><td>'.($object->date_request ? dol_print_date($object->date_request, 'dayhour') : '—').'</td></tr>';
print '<tr><td>'.$langs->trans('SupplierRmaDateAuthorized').'</td><td>'.($object->date_authorized ? dol_print_date($object->date_authorized, 'dayhour') : '—').'</td></tr>';
print '<tr><td>'.$langs->trans('DateShipped').'</td><td>'.($object->date_shipped ? dol_print_date($object->date_shipped, 'dayhour') : '—').'</td></tr>';
print '<tr><td>'.$langs->trans('SupplierRmaDateSupplierReceived').'</td><td>'.($object->date_supplier_received ? dol_print_date($object->date_supplier_received, 'dayhour') : '—').'</td></tr>';
print '<tr><td>'.$langs->trans('SupplierRmaDateSupplierCompleted').'</td><td>'.($object->date_supplier_completed ? dol_print_date($object->date_supplier_completed, 'dayhour') : '—').'</td></tr>';
print '<tr><td>'.$langs->trans('SupplierRmaDateReturned').'</td><td>'.($object->date_returned ? dol_print_date($object->date_returned, 'dayhour') : '—').'</td></tr>';

print '</table>';
print '</div>';

print '<div class="clearboth"></div><br>';
print load_fiche_titre($langs->trans('SupplierRmaLogistics'), '', 'shipment');
print '<table class="border centpercent tableforfield">';

print '<tr><td class="titlefield">'.$langs->trans('OutboundCarrier').'</td><td>';
if ($isEdit) {
	print '<input type="text" name="outbound_carrier" class="minwidth300" value="'.dol_escape_htmltag($object->outbound_carrier).'">';
} else {
	print dol_escape_htmltag($object->outbound_carrier ?: '—');
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('OutboundTracking').'</td><td>';
if ($isEdit) {
	print '<input type="text" name="outbound_tracking" class="minwidth300" value="'.dol_escape_htmltag($object->outbound_tracking).'">';
} else {
	print $trackingRender($object->outbound_tracking, $object->outbound_tracking_url);
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('OutboundTrackingUrl').'</td><td>';
if ($isEdit) {
	print '<input type="url" name="outbound_tracking_url" class="quatrevingtpercent" value="'.dol_escape_htmltag($object->outbound_tracking_url).'">';
} else {
	print !empty($object->outbound_tracking_url) ? '<a href="'.dol_escape_htmltag($object->outbound_tracking_url).'" target="_blank" rel="noopener">'.$langs->trans('OpenTracking').'</a>' : '—';
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('ReturnCarrier').'</td><td>';
if ($isEdit) {
	print '<input type="text" name="return_carrier" class="minwidth300" value="'.dol_escape_htmltag($object->return_carrier).'">';
} else {
	print dol_escape_htmltag($object->return_carrier ?: '—');
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('ReturnTracking').'</td><td>';
if ($isEdit) {
	print '<input type="text" name="return_tracking" class="minwidth300" value="'.dol_escape_htmltag($object->return_tracking).'">';
} else {
	print $trackingRender($object->return_tracking, $object->return_tracking_url);
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('ReturnTrackingUrl').'</td><td>';
if ($isEdit) {
	print '<input type="url" name="return_tracking_url" class="quatrevingtpercent" value="'.dol_escape_htmltag($object->return_tracking_url).'">';
} else {
	print !empty($object->return_tracking_url) ? '<a href="'.dol_escape_htmltag($object->return_tracking_url).'" target="_blank" rel="noopener">'.$langs->trans('OpenTracking').'</a>' : '—';
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('SupplierRmaResult').'</td><td>';
if ($isEdit) {
	print Form::selectarray('result_type', SvcSupplierRma::getResultOptions($langs), $object->result_type, 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth200');
} else {
	$resultOptions = SvcSupplierRma::getResultOptions($langs);
	print isset($resultOptions[$object->result_type]) ? dol_escape_htmltag($resultOptions[$object->result_type]) : '—';
}
print '</td></tr>';

print '<tr><td>'.$langs->trans('ReplacementSerial').'</td><td>';
if ($isEdit) {
	print '<input type="text" name="replacement_serial_number" class="minwidth300" value="'.dol_escape_htmltag($object->replacement_serial_number).'">';
} else {
	print dol_escape_htmltag($object->replacement_serial_number ?: '—');
}
print '</td></tr>';

print '</table>';

print '<br>';
print load_fiche_titre($langs->trans('SupplierRmaServiceDetails'), '', 'note');
print '<table class="border centpercent tableforfield">';

print '<tr><td class="titlefield tdtop">'.$langs->trans('SupplierRmaProblemDescription').'</td><td>';
if ($isEdit) {
	print '<textarea name="problem_description" class="quatrevingtpercent" rows="6">'.dol_escape_htmltag($object->problem_description).'</textarea>';
} else {
	print dol_string_onlythesehtmltags(dol_htmlentitiesbr($object->problem_description));
}
print '</td></tr>';

print '<tr><td class="tdtop">'.$langs->trans('SupplierRmaDiagnosis').'</td><td>';
if ($isEdit) {
	print '<textarea name="diagnosis" class="quatrevingtpercent" rows="5">'.dol_escape_htmltag($object->diagnosis).'</textarea>';
} else {
	print !empty($object->diagnosis) ? dol_string_onlythesehtmltags(dol_htmlentitiesbr($object->diagnosis)) : '<span class="opacitymedium">—</span>';
}
print '</td></tr>';

print '<tr><td class="tdtop">'.$langs->trans('SupplierRmaAccessoriesSent').'</td><td>';
if ($isEdit) {
	print '<textarea name="accessories_sent" class="quatrevingtpercent" rows="3">'.dol_escape_htmltag($object->accessories_sent).'</textarea>';
} else {
	print !empty($object->accessories_sent) ? dol_string_onlythesehtmltags(dol_htmlentitiesbr($object->accessories_sent)) : '<span class="opacitymedium">—</span>';
}
print '</td></tr>';

print '<tr><td class="tdtop">'.$langs->trans('NotePrivate').'</td><td>';
if ($isEdit) {
	print '<textarea name="note_private" class="quatrevingtpercent" rows="4">'.dol_escape_htmltag($object->note_private).'</textarea>';
} else {
	print !empty($object->note_private) ? dol_string_onlythesehtmltags(dol_htmlentitiesbr($object->note_private)) : '<span class="opacitymedium">—</span>';
}
print '</td></tr>';

print '</table>';

print dol_get_fiche_end();

print '<div class="tabsAction">';

if ($isEdit) {
	print '<input type="submit" class="butAction" value="'.$langs->trans('Save').'">';
	print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" class="butActionDelete">'.$langs->trans('Cancel').'</a>';
} else {
	if ($permwrite && !in_array($object->status, array(SvcSupplierRma::STATUS_CLOSED), true) && $action !== 'presend') {
		print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=edit&token='.newToken().'" class="butAction">'.$langs->trans('Modify').'</a>';
	}

	if ($permwrite && $action !== 'presend') {
		$mailurl = dolBuildUrl($_SERVER['PHP_SELF'], array('id'=>$object->id, 'action'=>'presend', 'mode'=>'init'), true);
		foreach (warrantysvc_default_supplier_email_receivers($object) as $receiverKey) {
			$mailurl .= '&receiver%5B%5D='.urlencode((string) $receiverKey);
		}
		$mailurl .= '#formmailbeforetitle';
		print dolGetButtonAction('', $langs->trans('SendMail'), 'email', $mailurl, '');
	}

	$next = array();
	switch ($object->status) {
		case SvcSupplierRma::STATUS_DRAFT:
			$next = array(
				SvcSupplierRma::STATUS_AUTHORIZED => 'SupplierRmaAuthorize',
				SvcSupplierRma::STATUS_CANCELLED => 'Cancel',
			);
			break;
		case SvcSupplierRma::STATUS_AUTHORIZED:
			$next = array(
				SvcSupplierRma::STATUS_SHIPPED => 'SupplierRmaMarkShipped',
				SvcSupplierRma::STATUS_CANCELLED => 'Cancel',
			);
			break;
		case SvcSupplierRma::STATUS_SHIPPED:
			$next = array(SvcSupplierRma::STATUS_RECEIVED_BY_SUPPLIER => 'SupplierRmaMarkSupplierReceived');
			break;
		case SvcSupplierRma::STATUS_RECEIVED_BY_SUPPLIER:
			$next = array(SvcSupplierRma::STATUS_IN_SERVICE => 'SupplierRmaMarkInService');
			break;
		case SvcSupplierRma::STATUS_IN_SERVICE:
			$next = array(
				SvcSupplierRma::STATUS_REPAIRED => 'SupplierRmaMarkRepaired',
				SvcSupplierRma::STATUS_REPLACED => 'SupplierRmaMarkReplaced',
				SvcSupplierRma::STATUS_REJECTED => 'SupplierRmaMarkRejected',
			);
			break;
		case SvcSupplierRma::STATUS_REPAIRED:
		case SvcSupplierRma::STATUS_REPLACED:
		case SvcSupplierRma::STATUS_REJECTED:
			$next = array(SvcSupplierRma::STATUS_RETURNED => 'SupplierRmaMarkReturned');
			break;
		case SvcSupplierRma::STATUS_RETURNED:
			$next = array(SvcSupplierRma::STATUS_CLOSED => 'SupplierRmaClose');
			break;
		case SvcSupplierRma::STATUS_CANCELLED:
			$next = array(SvcSupplierRma::STATUS_DRAFT => 'Reopen');
			break;
	}

	if ($permwrite) {
		foreach ($next as $nextStatus => $labelKey) {
			$css = ($nextStatus === SvcSupplierRma::STATUS_CANCELLED) ? 'butActionDelete' : 'butAction';
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=setstatus&newstatus='.urlencode($nextStatus).'&token='.newToken().'" class="'.$css.'">'.$langs->trans($labelKey).'</a>';
		}
	}

	if ($permwrite && $object->status !== SvcSupplierRma::STATUS_DRAFT) {
		print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=rollback&token='.newToken().'" class="butAction">'.$langs->trans('SupplierRmaRollback').'</a>';
	}

	if ($permdelete && empty($object->fk_stock_movement_out) && empty($object->fk_stock_movement_in)) {
		print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete&token='.newToken().'" class="butActionDelete">'.$langs->trans('DeleteSupplierRma').'</a>';
	}
}

print '</div>';

if ($isEdit) {
	print '</form>';
}

// Lifecycle timeline
$history = $object->fetchHistory();
print '<br>';
print load_fiche_titre($langs->trans('SupplierRmaLifecycle'), '', 'history');
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('Event').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('User').'</td>';
print '<td>'.$langs->trans('Note').'</td>';
print '</tr>';

if (empty($history)) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('NoLifecycleEvents').'</span></td></tr>';
} else {
	foreach ($history as $entry) {
		$tmpStatus = new SvcSupplierRma($db);
		$tmpStatus->status = $entry['new_status'];
		print '<tr class="oddeven">';
		print '<td>'.($entry['date_event'] ? dol_print_date($entry['date_event'], 'dayhour') : '—').'</td>';
		print '<td>'.dol_escape_htmltag($langs->trans('SupplierRmaEvent'.$entry['event_code'])).'</td>';
		print '<td>'.$tmpStatus->getLibStatut().'</td>';
		print '<td>'.dol_escape_htmltag($entry['user_name']).'</td>';
		print '<td>'.(!empty($entry['note']) ? dol_string_onlythesehtmltags(dol_htmlentitiesbr($entry['note'])) : '—').'</td>';
		print '</tr>';
	}
}
print '</table>';
print '</div>';

if (GETPOST('modelselected')) {
	$action = 'presend';
}

if ($action === 'presend' && $permwrite) {
	$modelmail = 'svcsupplierrma';
	$defaulttopic = 'SupplierRmaEmailSubject';
	$defaulttopiclang = 'warrantysvc@warrantysvc';
	$baseOutput = !empty($conf->warrantysvc->multidir_output[$object->entity])
		? $conf->warrantysvc->multidir_output[$object->entity]
		: (!empty($conf->warrantysvc->dir_output) ? $conf->warrantysvc->dir_output : DOL_DATA_ROOT.'/warrantysvc');
	$diroutput = $baseOutput.'/'.$sr->ref.'/supplier-rma/'.$object->ref;
	$trackid = 'wsvcsrma'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
}

llxFooter();
$db->close();
