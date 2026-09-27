<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    supplier_rma_contact.php
 * \ingroup warrantysvc
 * \brief   Contacts/addresses tab for Supplier RMA
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierrma.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$lineid = GETPOSTINT('lineid');
$action = GETPOST('action', 'aZ09');

$permread = $user->hasRight('warrantysvc', 'supplierrma', 'read');
$permwrite = $user->hasRight('warrantysvc', 'supplierrma', 'write');
if (!$permread || !empty($user->socid)) {
	accessforbidden();
}

$hookmanager->initHooks(array('warrantysvcsupplierrmacontact', 'globalcard'));

$object = new SvcSupplierRma($db);
if ($object->fetch($id, $ref) <= 0) {
	recordNotFound('', 0);
	exit;
}
$object->fetch_thirdparty();

$sr = new SvcRequest($db);
if ($sr->fetch($object->fk_svc_request) <= 0) {
	recordNotFound('', 0);
	exit;
}

$parameters = array('id' => $object->id);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if ($action === 'addcontact' && $permwrite) {
		$contactid = GETPOSTINT('userid') ? GETPOSTINT('userid') : GETPOSTINT('contactid');
		$typeid = GETPOST('typecontact') ? GETPOST('typecontact') : GETPOST('type');
		$result = $object->add_contact($contactid, $typeid, GETPOST('source', 'aZ09'));
		if ($result >= 0) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		if ($object->error === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
			$langs->load('errors');
			setEventMessages($langs->trans('ErrorThisContactIsAlreadyDefinedAsThisType'), null, 'errors');
		} else {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif ($action === 'swapstatut' && $permwrite) {
		$result = $object->swapContactStatus(GETPOSTINT('ligne'));
		if ($result < 0) {
			setEventMessages($object->error, $object->errors, 'errors');
		}
	} elseif ($action === 'deletecontact' && $permwrite) {
		$result = $object->delete_contact($lineid);
		if ($result >= 0) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages($object->error, $object->errors, 'errors');
	}
}

$form = new Form($db);
$formcompany = new FormCompany($db);
$formother = new FormOther($db);
$contactstatic = new Contact($db);
$userstatic = new User($db);
$permission = $permwrite;

$title = $object->ref.' - '.$langs->trans('ContactsAddresses');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-warrantysvc page-supplier-rma-contact');

$head = svcsupplierrma_prepare_head($object);
print dol_get_fiche_head($head, 'contact', $langs->trans('SupplierRma'), -1, 'tools');

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$sr->id.'">'.$langs->trans('BackToSvcRequest').'</a>';
$morehtmlref = '<div class="refidno">';
$morehtmlref .= '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$sr->id.'">'.dol_escape_htmltag($sr->ref).'</a>';
if (is_object($object->thirdparty)) {
	$morehtmlref .= '<br>'.$object->thirdparty->getNomUrl(1, 'supplier');
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
print dol_get_fiche_end();

print '<br>';

$dirtpls = array_merge($conf->modules_parts['tpl'], array('/core/tpl'));
foreach ($dirtpls as $reldir) {
	$file = dol_buildpath($reldir.'/contacts.tpl.php');
	if (file_exists($file)) {
		$res = @include $file;
		if ($res) {
			break;
		}
	}
}

llxFooter();
$db->close();
