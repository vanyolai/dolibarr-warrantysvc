<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    warranty_confirmation.php
 * \ingroup warrantysvc
 * \brief   Customer-facing grouped warranty confirmation for a Shipment
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'sendings', 'products', 'stocks'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

$permread = $user->hasRight('warrantysvc', 'svcwarranty', 'read');
$permwrite = $user->hasRight('warrantysvc', 'svcwarranty', 'write');
if (!$permread || !empty($user->socid)) {
	accessforbidden();
}

$object = new Expedition($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	recordNotFound('', 0);
	exit;
}
$object->fetch_thirdparty();

$warrantyCount = warrantysvc_count_shipment_warranties($db, $object->id);
if ($warrantyCount <= 0) {
	setEventMessages($langs->trans('WarrantyConfirmationNoWarranties'), null, 'warnings');
}

$hookmanager->initHooks(array('warrantysvcwarrantyconfirmationcard', 'globalcard'));

$hidedetails = GETPOSTINT('hidedetails') ? 1 : 0;
$hidedesc = GETPOSTINT('hidedesc') ? 1 : 0;
$hideref = GETPOSTINT('hideref') ? 1 : 0;

// Native Dolibarr email backend. The email is deliberately Shipment-scoped:
// one outgoing message can contain all Warranty rows created by the Shipment.
if ($permwrite && $warrantyCount > 0) {
	$triggersendname = 'WARRANTYSVC_CONFIRMATION_SENTBYMAIL';
	$autocopy = '';
	$trackid = 'wsvcwc'.$object->id;
	$paramname = 'id';
	$sendcontext = 'warrantysvc_warranty_confirmation';
	include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';
}

$form = new Form($db);

llxHeader('', $langs->trans('WarrantyConfirmation'), '');

$shipmentUrl = DOL_URL_ROOT.'/expedition/card.php?id='.$object->id;
$backLink = '<a href="'.$shipmentUrl.'">'.$langs->trans('BackToList').'</a>';
print load_fiche_titre($langs->trans('WarrantyConfirmationForShipment', $object->ref), $backLink, 'email');

print '<div class="div-table-responsive">';
print warrantysvc_render_warranty_confirmation_lines($db, $object->id, $langs);
print '</div>';

if (GETPOST('modelselected') && $warrantyCount > 0) {
	$action = 'presend';
}

if ($action === 'presend' && $permwrite && $warrantyCount > 0) {
	$modelmail = 'svcwarrantyconfirmation';
	$defaulttopic = 'WarrantyConfirmationEmailSubject';
	$defaulttopiclang = 'warrantysvc@warrantysvc';
	$diroutput = $conf->expedition->dir_output.'/sending';
	$trackid = 'wsvcwc'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
}

llxFooter();
$db->close();
