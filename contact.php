<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    contact.php
 * \ingroup warrantysvc
 * \brief   Native Dolibarr contacts/addresses tab for Service Requests
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
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$lineid = GETPOSTINT('lineid');
$action = GETPOST('action', 'aZ09');

$permread = $user->hasRight('warrantysvc', 'svcrequest', 'read');
$permwrite = $user->hasRight('warrantysvc', 'svcrequest', 'write');
if (!$permread) {
	accessforbidden();
}

$hookmanager->initHooks(array('warrantysvccontact', 'globalcard'));

$object = new SvcRequest($db);
if ($object->fetch($id, $ref) <= 0) {
	recordNotFound('', 0);
	exit;
}
$object->fetch_thirdparty();

if (!empty($user->socid) && (int) $user->socid !== (int) $object->fk_soc) {
	accessforbidden();
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
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-warrantysvc page-card_contact');

$head = warrantysvc_prepare_head($object);
print dol_get_fiche_head($head, 'contact', $langs->trans('SvcRequest'), -1, 'technic');

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/list.php?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';

$morehtmlref = '<div class="refidno">';
if (is_object($object->thirdparty)) {
	$morehtmlref .= $object->thirdparty->getNomUrl(1);
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
print dol_get_fiche_end();

print '<br>';

// Reuse Dolibarr's standard contact editor/list. Contact types for the
// svcrequest element are registered by modWarrantySvc::init().
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
