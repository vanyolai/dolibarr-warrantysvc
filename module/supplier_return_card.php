<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    supplier_return_card.php
 * \ingroup warrantysvc
 * \brief   Supplier Return card
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierreturn.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierreturnline.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies', 'products', 'stocks', 'orders'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$lineid = GETPOSTINT('lineid');
$confirm = GETPOST('confirm', 'alpha');

$permread = $user->hasRight('warrantysvc', 'supplierreturn', 'read');
$permwrite = $user->hasRight('warrantysvc', 'supplierreturn', 'write');
$permdelete = $user->hasRight('warrantysvc', 'supplierreturn', 'delete');
if (!$permread || !empty($user->socid)) accessforbidden();

$hookmanager->initHooks(array('warrantysvcsupplierreturncard', 'globalcard'));

$object = new SvcSupplierReturn($db);
if ($id > 0 || $ref) {
	if ($object->fetch($id, $ref) <= 0) {
		recordNotFound('', 0);
		exit;
	}
	$object->fetch_thirdparty();
}

function warrantysvc_supplier_return_fill_header_from_post($object)
{
	$object->fk_soc_supplier = GETPOSTINT('fk_soc_supplier');
	$object->socid = $object->fk_soc_supplier;
	$object->supplier_return_ref = GETPOST('supplier_return_ref', 'alphanohtml');
	$object->reason = GETPOST('reason', 'restricthtml');
	$object->fk_warehouse_source = GETPOSTINT('fk_warehouse_source');
	$object->outbound_carrier = GETPOST('outbound_carrier', 'alphanohtml');
	$object->outbound_tracking = GETPOST('outbound_tracking', 'alphanohtml');
	$object->outbound_tracking_url = trim(GETPOST('outbound_tracking_url', 'url'));
	$object->note_private = GETPOST('note_private', 'restricthtml');
}

function warrantysvc_supplier_return_error($langs, $object)
{
	$msg = $object->error ? $langs->trans($object->error) : $langs->trans('Error');
	setEventMessages($msg, $object->errors, 'errors');
}

/*
 * Actions
 */
if ($action === 'add' && $permwrite) {
	warrantysvc_supplier_return_fill_header_from_post($object);
	$result = $object->create($user);
	if ($result > 0) {
		setEventMessages($langs->trans('SupplierReturnCreated', $object->ref), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	warrantysvc_supplier_return_error($langs, $object);
	$action = 'create';
}

if ($object->id > 0 && $action === 'update' && $permwrite) {
	warrantysvc_supplier_return_fill_header_from_post($object);
	$result = $object->update($user);
	if ($result > 0) {
		setEventMessages($langs->trans('SupplierReturnUpdated'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		exit;
	}
	warrantysvc_supplier_return_error($langs, $object);
	$action = 'edit';
}

if ($object->id > 0 && $action === 'addline' && $permwrite) {
	if (!in_array($object->status, array(SvcSupplierReturn::STATUS_DRAFT, SvcSupplierReturn::STATUS_AUTHORIZED), true)) {
		setEventMessages($langs->trans('ErrorSupplierReturnNotEditable'), null, 'errors');
	} else {
		$line = new SvcSupplierReturnLine($db);
		$line->fk_supplier_return = $object->id;
		$line->fk_product = GETPOSTINT('line_fk_product');
		$line->qty = price2num(GETPOST('line_qty', 'alphanohtml'));
		$line->batch = trim(GETPOST('line_batch', 'alphanohtml'));
		$line->reason = GETPOST('line_reason', 'restricthtml');
		$line->rang = count($object->lines) + 1;
		if ($line->create($user) > 0) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'#lines');
			exit;
		}
		warrantysvc_supplier_return_error($langs, $line);
	}
}

if ($object->id > 0 && $action === 'updateline' && $permwrite) {
	$line = new SvcSupplierReturnLine($db);
	if ($line->fetch($lineid) > 0 && (int) $line->fk_supplier_return === (int) $object->id) {
		$line->fk_product = GETPOSTINT('line_fk_product');
		$line->qty = price2num(GETPOST('line_qty', 'alphanohtml'));
		$line->batch = trim(GETPOST('line_batch', 'alphanohtml'));
		$line->reason = GETPOST('line_reason', 'restricthtml');
		if ($line->update($user) > 0) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'#lines');
			exit;
		}
		warrantysvc_supplier_return_error($langs, $line);
	}
}

if ($object->id > 0 && $action === 'deleteline' && $permwrite) {
	$line = new SvcSupplierReturnLine($db);
	if ($line->fetch($lineid) > 0 && (int) $line->fk_supplier_return === (int) $object->id) {
		if ($line->delete($user) > 0) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id.'#lines');
			exit;
		}
		warrantysvc_supplier_return_error($langs, $line);
	}
}

if ($object->id > 0 && $action === 'confirm_authorize' && $confirm === 'yes' && $permwrite) {
	$result = $object->authorize($user);
	if ($result < 0) warrantysvc_supplier_return_error($langs, $object);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id); exit;
}
if ($object->id > 0 && $action === 'confirm_ship' && $confirm === 'yes' && $permwrite) {
	$result = $object->ship($user);
	if ($result < 0) warrantysvc_supplier_return_error($langs, $object);
	else setEventMessages($langs->trans('SupplierReturnStockMovedOut'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id); exit;
}
if ($object->id > 0 && $action === 'confirm_close' && $confirm === 'yes' && $permwrite) {
	if ($object->close($user) < 0) warrantysvc_supplier_return_error($langs, $object);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id); exit;
}
if ($object->id > 0 && $action === 'confirm_cancel' && $confirm === 'yes' && $permwrite) {
	if ($object->cancel($user) < 0) warrantysvc_supplier_return_error($langs, $object);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id); exit;
}
if ($object->id > 0 && $action === 'confirm_reopen' && $confirm === 'yes' && $permwrite) {
	if ($object->reopen($user) < 0) warrantysvc_supplier_return_error($langs, $object);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id); exit;
}
if ($object->id > 0 && $action === 'confirm_rollback' && $confirm === 'yes' && $permwrite) {
	if ($object->rollbackStatus($user, $langs->transnoentitiesnoconv('SupplierReturnRollbackAuditNote')) < 0) warrantysvc_supplier_return_error($langs, $object);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id); exit;
}
if ($object->id > 0 && $action === 'confirm_delete' && $confirm === 'yes' && $permdelete) {
	if ($object->delete($user) > 0) {
		setEventMessages($langs->trans('SupplierReturnDeleted'), null, 'mesgs');
		header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_list.php');
		exit;
	}
	warrantysvc_supplier_return_error($langs, $object);
}

// Native Dolibarr email backend.
if ($object->id > 0 && $permwrite) {
	$triggersendname = '';
	$autocopy = '';
	$trackid = 'wsvcsret'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';
}

$form = new Form($db);
$formcompany = new FormCompany($db);
$formproduct = new FormProduct($db);

llxHeader('', $object->id ? $object->ref : $langs->trans('NewSupplierReturn'), '');

if ($action === 'create' || empty($object->id)) {
	print load_fiche_titre($langs->trans('NewSupplierReturn'), '', 'shipment');
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print dol_get_fiche_head(array(), '', '', -1);
	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="fieldrequired titlefieldcreate">'.$langs->trans('Supplier').'</td><td>';
	print $form->select_company($object->fk_soc_supplier, 'fk_soc_supplier', '(s.fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
	print '</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Warehouse').'</td><td>';
	print $formproduct->selectWarehouses($object->fk_warehouse_source, 'fk_warehouse_source', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth300');
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('SupplierReturnExternalRef').'</td><td><input type="text" name="supplier_return_ref" class="minwidth300" value="'.dol_escape_htmltag($object->supplier_return_ref).'"></td></tr>';
	print '<tr><td class="fieldrequired tdtop">'.$langs->trans('SupplierReturnReason').'</td><td><textarea name="reason" class="quatrevingtpercent" rows="4">'.dol_escape_htmltag($object->reason).'</textarea></td></tr>';
	print '<tr><td>'.$langs->trans('OutboundCarrier').'</td><td><input type="text" name="outbound_carrier" class="minwidth200"></td></tr>';
	print '<tr><td>'.$langs->trans('OutboundTracking').'</td><td><input type="text" name="outbound_tracking" class="minwidth300"></td></tr>';
	print '<tr><td>'.$langs->trans('TrackingUrl').'</td><td><input type="url" name="outbound_tracking_url" class="minwidth500"></td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans('NotePrivate').'</td><td><textarea name="note_private" class="quatrevingtpercent" rows="3"></textarea></td></tr>';
	print '</table>';
	print dol_get_fiche_end();
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Create').'"> ';
	print '<a class="button button-cancel" href="'.DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_list.php">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
	llxFooter(); $db->close(); exit;
}

$head = svcsupplierreturn_prepare_head($object);
print dol_get_fiche_head($head, 'card', $langs->trans('SupplierReturn'), -1, 'shipment');

$formconfirm = '';
if ($action === 'authorize') {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('SupplierReturnAuthorize'), $langs->trans('ConfirmSupplierReturnAuthorize'), 'confirm_authorize', '', 0, 1);
} elseif ($action === 'ship') {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('SupplierReturnShip'), $langs->trans('ConfirmSupplierReturnShip'), 'confirm_ship', '', 0, 1);
} elseif ($action === 'close') {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('SupplierReturnClose'), $langs->trans('ConfirmSupplierReturnClose'), 'confirm_close', '', 0, 1);
} elseif ($action === 'cancel') {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('Cancel'), $langs->trans('ConfirmSupplierReturnCancel'), 'confirm_cancel', '', 0, 1);
} elseif ($action === 'reopen') {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('Reopen'), $langs->trans('ConfirmSupplierReturnReopen'), 'confirm_reopen', '', 0, 1);
} elseif ($action === 'rollback') {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('SupplierReturnRollback'), $langs->trans('ConfirmSupplierReturnRollback'), 'confirm_rollback', '', 0, 1);
} elseif ($action === 'delete') {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('DeleteSupplierReturn'), $langs->trans('ConfirmDeleteSupplierReturn', $object->ref), 'confirm_delete', '', 0, 1);
}
print $formconfirm;

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_list.php">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">';
if (is_object($object->thirdparty)) $morehtmlref .= $object->thirdparty->getNomUrl(1, 'supplier');
$morehtmlref .= '</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

$isEdit = ($action === 'edit' && $permwrite && in_array($object->status, array(SvcSupplierReturn::STATUS_DRAFT, SvcSupplierReturn::STATUS_AUTHORIZED), true));
if ($isEdit) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="update">';
}

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Supplier').'</td><td>';
if ($isEdit) print $form->select_company($object->fk_soc_supplier, 'fk_soc_supplier', '(s.fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
else if (is_object($object->thirdparty)) print $object->thirdparty->getNomUrl(1, 'supplier');
print '</td></tr>';
print '<tr><td>'.$langs->trans('Warehouse').'</td><td>';
if ($isEdit) print $formproduct->selectWarehouses($object->fk_warehouse_source, 'fk_warehouse_source', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth300');
else {
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
	$wh = new Entrepot($db);
	print ($object->fk_warehouse_source > 0 && $wh->fetch($object->fk_warehouse_source) > 0) ? $wh->getNomUrl(1) : '—';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('SupplierReturnExternalRef').'</td><td>';
if ($isEdit) print '<input type="text" name="supplier_return_ref" class="minwidth300" value="'.dol_escape_htmltag($object->supplier_return_ref).'">';
else print dol_escape_htmltag($object->supplier_return_ref ?: '—');
print '</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut().'</td></tr>';
print '<tr><td class="tdtop">'.$langs->trans('SupplierReturnReason').'</td><td>';
if ($isEdit) print '<textarea name="reason" class="quatrevingtpercent" rows="4">'.dol_escape_htmltag($object->reason).'</textarea>';
else print dol_string_onlythesehtmltags(dol_htmlentitiesbr($object->reason));
print '</td></tr>';
print '<tr><td>'.$langs->trans('OutboundCarrier').'</td><td>';
if ($isEdit) print '<input type="text" name="outbound_carrier" class="minwidth200" value="'.dol_escape_htmltag($object->outbound_carrier).'">';
else print dol_escape_htmltag($object->outbound_carrier ?: '—');
print '</td></tr>';
print '<tr><td>'.$langs->trans('OutboundTracking').'</td><td>';
if ($isEdit) print '<input type="text" name="outbound_tracking" class="minwidth300" value="'.dol_escape_htmltag($object->outbound_tracking).'">';
else if ($object->outbound_tracking && $object->outbound_tracking_url) print '<a target="_blank" rel="noopener" href="'.dol_escape_htmltag($object->outbound_tracking_url).'">'.dol_escape_htmltag($object->outbound_tracking).'</a>';
else print dol_escape_htmltag($object->outbound_tracking ?: '—');
print '</td></tr>';
if ($isEdit) print '<tr><td>'.$langs->trans('TrackingUrl').'</td><td><input type="url" name="outbound_tracking_url" class="minwidth500" value="'.dol_escape_htmltag($object->outbound_tracking_url).'"></td></tr>';
print '<tr><td class="tdtop">'.$langs->trans('NotePrivate').'</td><td>';
if ($isEdit) print '<textarea name="note_private" class="quatrevingtpercent" rows="3">'.dol_escape_htmltag($object->note_private).'</textarea>';
else print $object->note_private ? dol_string_onlythesehtmltags(dol_htmlentitiesbr($object->note_private)) : '—';
print '</td></tr>';
print '</table>';
print dol_get_fiche_end();

if ($isEdit) {
	print '<div class="tabsAction"><input class="butAction" type="submit" value="'.$langs->trans('Save').'">';
	print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'">'.$langs->trans('Cancel').'</a></div></form>';
} else {
	print '<div class="tabsAction">';
	if ($permwrite && in_array($object->status, array(SvcSupplierReturn::STATUS_DRAFT, SvcSupplierReturn::STATUS_AUTHORIZED), true) && $action !== 'presend') {
		print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=edit&token='.newToken().'">'.$langs->trans('Modify').'</a>';
	}
	if ($permwrite && $action !== 'presend') {
		$mailurl = dolBuildUrl($_SERVER['PHP_SELF'], array('id'=>$object->id, 'action'=>'presend', 'mode'=>'init'), true);
		foreach (warrantysvc_default_supplier_return_email_receivers($object) as $receiverKey) $mailurl .= '&receiver%5B%5D='.urlencode((string) $receiverKey);
		$mailurl .= '#formmailbeforetitle';
		print dolGetButtonAction('', $langs->trans('SendMail'), 'email', $mailurl, '');
	}
	if ($permwrite && $object->status === SvcSupplierReturn::STATUS_DRAFT) print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=authorize&token='.newToken().'">'.$langs->trans('SupplierReturnAuthorize').'</a>';
	if ($permwrite && $object->status === SvcSupplierReturn::STATUS_AUTHORIZED) print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=ship&token='.newToken().'">'.$langs->trans('SupplierReturnShip').'</a>';
	if ($permwrite && $object->status === SvcSupplierReturn::STATUS_SHIPPED) print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=close&token='.newToken().'">'.$langs->trans('SupplierReturnClose').'</a>';
	if ($permwrite && in_array($object->status, array(SvcSupplierReturn::STATUS_DRAFT, SvcSupplierReturn::STATUS_AUTHORIZED), true)) print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=cancel&token='.newToken().'">'.$langs->trans('Cancel').'</a>';
	if ($permwrite && $object->status === SvcSupplierReturn::STATUS_CANCELLED) print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=reopen&token='.newToken().'">'.$langs->trans('Reopen').'</a>';
	if ($permwrite && in_array($object->status, array(SvcSupplierReturn::STATUS_AUTHORIZED, SvcSupplierReturn::STATUS_CLOSED), true)) print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=rollback&token='.newToken().'">'.$langs->trans('SupplierReturnRollback').'</a>';
	$hasMovement = false; foreach ($object->lines as $l) if (!empty($l->fk_stock_movement_out)) $hasMovement = true;
	if ($permdelete && !$hasMovement) print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete&token='.newToken().'">'.$langs->trans('DeleteSupplierReturn').'</a>';
	print '</div>';
}

// Lines
$object->fetchLines();
print '<a name="lines"></a><br>';
print load_fiche_titre($langs->trans('SupplierReturnLines'), '', 'product');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Product').'</td><td>'.$langs->trans('SerialOrLot').'</td><td class="right">'.$langs->trans('Qty').'</td><td>'.$langs->trans('Reason').'</td><td>'.$langs->trans('StockMovement').'</td><td></td></tr>';
if (empty($object->lines)) print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoSupplierReturnLines').'</span></td></tr>';
foreach ($object->lines as $line) {
	$p = new Product($db); $plabel = '#'.((int) $line->fk_product);
	if ($p->fetch($line->fk_product) > 0) $plabel = $p->getNomUrl(1).' - '.dol_escape_htmltag($p->label);
	if ($action === 'editline' && $lineid === $line->id && empty($line->fk_stock_movement_out)) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'#lines"><tr class="oddeven">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="updateline"><input type="hidden" name="lineid" value="'.$line->id.'">';
		print '<td>'; $form->select_produits($line->fk_product, 'line_fk_product', 0, 0, 0, -1, 2, '', 0, array(), 0, 0, 0, 'minwidth250'); print '</td>';
		print '<td><input name="line_batch" class="minwidth150" value="'.dol_escape_htmltag($line->batch).'"></td>';
		print '<td class="right"><input type="number" min="0.00000001" step="any" name="line_qty" class="width75" value="'.dol_escape_htmltag((string)$line->qty).'"></td>';
		print '<td><input name="line_reason" class="minwidth200" value="'.dol_escape_htmltag($line->reason).'"></td>';
		print '<td>—</td><td><input class="button small" type="submit" value="'.$langs->trans('Save').'"> <a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'#lines">'.$langs->trans('Cancel').'</a></td></tr></form>';
	} else {
		print '<tr class="oddeven"><td>'.$plabel.'</td><td>'.dol_escape_htmltag($line->batch ?: '—').'</td><td class="right">'.price($line->qty,0,$langs,0,0,-1).'</td><td>'.dol_escape_htmltag($line->reason ?: '—').'</td>';
		print '<td>'.($line->fk_stock_movement_out ? '<a href="'.DOL_URL_ROOT.'/product/stock/movement.php?id='.$line->fk_stock_movement_out.'">#'.$line->fk_stock_movement_out.'</a>' : '—').'</td><td class="right">';
		if ($permwrite && in_array($object->status,array(SvcSupplierReturn::STATUS_DRAFT,SvcSupplierReturn::STATUS_AUTHORIZED),true) && empty($line->fk_stock_movement_out)) {
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=editline&lineid='.$line->id.'#lines">'.img_edit().'</a> ';
			print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=deleteline&lineid='.$line->id.'&token='.newToken().'#lines">'.img_delete().'</a>';
		}
		print '</td></tr>';
	}
}
if ($permwrite && in_array($object->status,array(SvcSupplierReturn::STATUS_DRAFT,SvcSupplierReturn::STATUS_AUTHORIZED),true) && $action !== 'editline') {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'#lines"><tr class="liste_titre_create">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addline">';
	print '<td>'; $form->select_produits(0, 'line_fk_product', 0, 0, 0, -1, 2, '', 0, array(), 0, 0, 0, 'minwidth250'); print '</td>';
	print '<td><input name="line_batch" class="minwidth150" placeholder="'.$langs->trans('SerialOrLot').'"></td>';
	print '<td class="right"><input type="number" min="0.00000001" step="any" name="line_qty" class="width75" value="1"></td>';
	print '<td><input name="line_reason" class="minwidth200" placeholder="'.$langs->trans('Reason').'"></td>';
	print '<td></td><td class="right"><input class="button" type="submit" value="'.$langs->trans('Add').'"></td></tr></form>';
}
print '</table></div>';

// Lifecycle
$history = $object->fetchHistory();
print '<br>'.load_fiche_titre($langs->trans('SupplierReturnLifecycle'), '', 'history');
print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Event').'</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('User').'</td><td>'.$langs->trans('Note').'</td></tr>';
foreach ($history as $h) {
	$tmp = new SvcSupplierReturn($db); $tmp->status = $h['new_status'];
	print '<tr class="oddeven"><td>'.dol_print_date($h['date_event'],'dayhour').'</td><td>'.$langs->trans('SupplierReturnEvent'.$h['event_code']).'</td><td>'.$tmp->getLibStatut().'</td><td>'.dol_escape_htmltag($h['user_name']).'</td><td>'.dol_escape_htmltag($h['note'] ?: '—').'</td></tr>';
}
print '</table></div>';

if (GETPOST('modelselected')) $action = 'presend';
if ($action === 'presend' && $permwrite) {
	$modelmail = 'svcsupplierreturn';
	$defaulttopic = 'SupplierReturnEmailSubject';
	$defaulttopiclang = 'warrantysvc@warrantysvc';
	$diroutput = warrantysvc_supplier_return_output_dir($object);
	$trackid = 'wsvcsret'.$object->id;
	include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
}

llxFooter();
$db->close();
