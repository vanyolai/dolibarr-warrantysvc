<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    supplier_return_document.php
 * \ingroup warrantysvc
 * \brief   Documents for Supplier Return
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierreturn.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc','other','companies'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref','alpha');
$action = GETPOST('action','aZ09');

$permread = $user->hasRight('warrantysvc','supplierreturn','read');
$permwrite = $user->hasRight('warrantysvc','supplierreturn','write');
if (!$permread || !empty($user->socid)) accessforbidden();

$object = new SvcSupplierReturn($db);
if ($object->fetch($id,$ref) <= 0) {
	recordNotFound('',0);
	exit;
}
$object->fetch_thirdparty();

$upload_dir = warrantysvc_supplier_return_output_dir($object);
$usercangeneratedoc = $permwrite && in_array($object->status, array(SvcSupplierReturn::STATUS_AUTHORIZED, SvcSupplierReturn::STATUS_SHIPPED, SvcSupplierReturn::STATUS_CLOSED), true);
$permissiontoadd = $usercangeneratedoc;
$permtoedit = $permwrite;
$modulepart = 'warrantysvc';

include DOL_DOCUMENT_ROOT.'/core/actions_linkedfiles.inc.php';
include DOL_DOCUMENT_ROOT.'/core/actions_builddoc.inc.php';

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader('', $langs->trans('Documents').' - '.$object->ref, '', '', 0, 0, '', '', '', 'mod-warrantysvc page-supplier-return-document');

$head = svcsupplierreturn_prepare_head($object);
print dol_get_fiche_head($head,'document',$langs->trans('SupplierReturn'),-1,'shipment');

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_list.php">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">';
if (is_object($object->thirdparty)) $morehtmlref .= $object->thirdparty->getNomUrl(1,'supplier');
$morehtmlref .= '</div>';
dol_banner_tab($object,'ref',$linkback,1,'ref','ref',$morehtmlref);

$filearray = is_dir($upload_dir) ? dol_dir_list($upload_dir,'files',0,'','(\.meta|_preview.*\.png)$','name',SORT_ASC,1) : array();
$totalsize = 0; foreach($filearray as $file) $totalsize += (int)$file['size'];
print '<table class="border tableforfield centpercent"><tr><td class="titlefield">'.$langs->trans('NbOfAttachedFiles').'</td><td>'.count($filearray).'</td></tr>';
print '<tr><td>'.$langs->trans('TotalSizeOfAttachedFiles').'</td><td>'.dol_print_size($totalsize,1,1).'</td></tr></table>';
print dol_get_fiche_end();

if ($usercangeneratedoc) {
	print load_fiche_titre($langs->trans('GeneratedDocuments'),'','pdf');
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" class="formdoc">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="builddoc">';
	print '<input type="hidden" name="model" value="supplierreturn_standard">';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Generate').'"> ';
	print '<span class="opacitymedium">'.$langs->trans('SupplierReturnPdfStandardDesc').'</span></div></form><br>';
}

$param = '&id='.$object->id;
$savingdocmask = dol_sanitizeFileName($object->ref).'-__file__';
include DOL_DOCUMENT_ROOT.'/core/tpl/document_actions_post_headers.tpl.php';

llxFooter();
$db->close();
