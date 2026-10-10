<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    supplier_rma_document.php
 * \ingroup warrantysvc
 * \brief   Native attachments and external document links for a Supplier RMA
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
dol_include_once('/warrantysvc/class/svcsupplierrma.class.php');
dol_include_once('/warrantysvc/class/svcrequest.class.php');
dol_include_once('/warrantysvc/lib/warrantysvc.lib.php');

$langs->loadLangs(array('warrantysvc@warrantysvc', 'other', 'companies', 'link'));

$permread = $user->hasRight('warrantysvc', 'supplierrma', 'read');
$permwrite = $user->hasRight('warrantysvc', 'supplierrma', 'write');
if (!$permread || !empty($user->socid)) accessforbidden();

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$object = new SvcSupplierRma($db);
if ($object->fetch($id, $ref) <= 0) {
	recordNotFound('', 0);
	exit;
}
$object->fetch_thirdparty();

$sr = new SvcRequest($db);
if ($sr->fetch((int) $object->fk_svc_request) <= 0) {
	recordNotFound('', 0);
	exit;
}

// Files live in the same existing RMA directory as the native mail form.
// Link objects belong to the RMA (not the parent service request).
$upload_dir = $object->getDocumentOutputDir((string) $sr->ref);
$modulepart = 'warrantysvc';
$relativepathwithnofile = dol_sanitizeFileName($sr->ref).'/supplier-rma/'.dol_sanitizeFileName($object->ref).'/';
$permissiontoadd = $permwrite ? 1 : 0;
$permtoedit = $permissiontoadd;
$permission = $permissiontoadd;
$param = '&id='.((int) $object->id);
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'name';
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09comma') ?: 'ASC');
if (!in_array($sortfield, array('name', 'size', 'date', 'position_name'), true)) $sortfield = 'name';
if (!in_array($sortorder, array('ASC', 'DESC'), true)) $sortorder = 'ASC';
$savingdocmask = dol_sanitizeFileName($object->ref).'-__file__';

if ($upload_dir !== '') {
	include DOL_DOCUMENT_ROOT.'/core/actions_linkedfiles.inc.php';
} else {
	setEventMessages($langs->trans('ErrorWarrantySvcOutputDirNotConfigured'), null, 'errors');
}

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader('', $object->ref.' - '.$langs->trans('SupplierRmaDocuments'));
$head = svcsupplierrma_prepare_head($object);
print dol_get_fiche_head($head, 'document', $langs->trans('SupplierRma'), -1, 'tools');

$linkback = '<a href="'.dol_buildpath('/warrantysvc/supplier_rma_list.php', 1).'">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno"><a href="'.dol_buildpath('/warrantysvc/card.php', 1).'?id='.((int) $sr->id).'">'.dol_escape_htmltag((string) $sr->ref).'</a>';
if (is_object($object->thirdparty)) $morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1, 'supplier');
$morehtmlref .= '</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
print dol_get_fiche_end();

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('SupplierRmaDocumentsHint').'</div>';
if ($upload_dir !== '') {
	$filearray = dol_dir_list($upload_dir, 'files', 0, '', '(\.meta|_preview.*\.png)$', 'name', SORT_ASC, 1);
	// Dolibarr draws the standard file uploader, document list and link list.
	// External links (including Paperless, subject to core URL security rules)
	// are managed here without introducing an RMA-specific document table.
	include DOL_DOCUMENT_ROOT.'/core/tpl/document_actions_post_headers.tpl.php';
}

llxFooter();
$db->close();
