<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    document.php
 * \ingroup warrantysvc
 * \brief   Documents attached to a WarrantySvc Service Request
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'other', 'companies'));

$id = GETPOST('id', 'int');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$permread = $user->hasRight('warrantysvc', 'svcrequest', 'read');
$permwrite = $user->hasRight('warrantysvc', 'svcrequest', 'write');
if (!$permread) {
	accessforbidden();
}

$object = new SvcRequest($db);
if ($object->fetch($id, $ref) <= 0) {
	dol_print_error($db, $object->error);
	exit;
}

if ($user->socid > 0 && (int) $object->fk_soc !== (int) $user->socid) {
	accessforbidden();
}

$object->fetch_thirdparty();

$base_output_dir = !empty($conf->warrantysvc->multidir_output[$object->entity])
	? $conf->warrantysvc->multidir_output[$object->entity]
	: (isset($conf->warrantysvc->dir_output) ? $conf->warrantysvc->dir_output : '');

if (empty($base_output_dir)) {
	setEventMessages($langs->trans('ErrorWarrantySvcOutputDirNotConfigured'), null, 'errors');
	$upload_dir = '';
} else {
	$upload_dir = $base_output_dir.'/'.dol_sanitizeFileName($object->ref);
}

$permissiontoadd = $permwrite;
$permtoedit = $permwrite;
$usercangeneratedoc = $permwrite;
$modulepart = 'warrantysvc';

// File upload/link/delete actions.
include DOL_DOCUMENT_ROOT.'/core/actions_linkedfiles.inc.php';

// Standard Dolibarr PDF generation action.
include DOL_DOCUMENT_ROOT.'/core/actions_builddoc.inc.php';

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader('', $langs->trans('SvcDocuments').' - '.$object->ref, '', '', 0, 0, '', '', '', 'mod-warrantysvc page-card_document');

$head = warrantysvc_prepare_head($object);
print dol_get_fiche_head($head, 'document', $langs->trans('SvcRequest'), -1, 'technic');

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/list.php?restore_lastsearch_values=1">'
	.$langs->trans('BackToList').'</a>';

$morehtmlref = '<div class="refidno">';
if (!empty($object->thirdparty) && is_object($object->thirdparty)) {
	$morehtmlref .= $object->thirdparty->getNomUrl(1);
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

if (!empty($upload_dir)) {
	$filearray = dol_dir_list($upload_dir, 'files', 0, '', '(\.meta|_preview.*\.png)$', 'name', SORT_ASC, 1);
} else {
	$filearray = array();
}

$totalsize = 0;
foreach ($filearray as $file) {
	$totalsize += (int) $file['size'];
}

print '<table class="border tableforfield centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('NbOfAttachedFiles').'</td><td>'.count($filearray).'</td></tr>';
print '<tr><td>'.$langs->trans('TotalSizeOfAttachedFiles').'</td><td>'.dol_print_size($totalsize, 1, 1).'</td></tr>';
print '</table>';
print '</div>';

print dol_get_fiche_end();

// The module currently has one standard document model. Use the normal
// Dolibarr builddoc action so adding more models later remains straightforward.
if ($permwrite && !empty($upload_dir)) {
	print load_fiche_titre($langs->trans('GeneratedDocuments'), '', 'pdf');
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'" class="formdoc">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="builddoc">';
	print '<input type="hidden" name="model" value="svcrequest_standard">';
	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="'.$langs->trans('Generate').'">';
	print ' <span class="opacitymedium">'.$langs->trans('SvcRequestPdfStandardDesc').'</span>';
	print '</div>';
	print '</form>';
	print '<br>';
}

// Standard attachment and link area. Generated PDFs live in the same object
// directory, therefore they appear here immediately after generation.
if (!empty($upload_dir)) {
	$param = '&id='.(int) $object->id;
	$savingdocmask = dol_sanitizeFileName($object->ref).'-__file__';
	include DOL_DOCUMENT_ROOT.'/core/tpl/document_actions_post_headers.tpl.php';
}

llxFooter();
$db->close();
