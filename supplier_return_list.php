<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    supplier_return_list.php
 * \ingroup warrantysvc
 * \brief   Supplier Return list
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierreturn.class.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies'));

if (!$user->hasRight('warrantysvc', 'supplierreturn', 'read') || !empty($user->socid)) accessforbidden();

$search_ref = GETPOST('search_ref', 'alphanohtml');
$search_supplier = GETPOST('search_supplier', 'alphanohtml');
$search_status = GETPOST('search_status', 'alpha');
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 't.date_creation';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';
$limit = $conf->liste_limit;
$page = max(0, GETPOSTINT('page'));
$offset = $limit * $page;

$sql = "SELECT t.rowid, t.ref, t.fk_soc_supplier, t.supplier_return_ref, t.status, t.date_creation, t.date_shipped,";
$sql .= " s.nom AS supplier_name, COUNT(l.rowid) AS line_count, COALESCE(SUM(l.qty),0) AS qty_total";
$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = t.fk_soc_supplier";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."svc_supplier_return_line l ON l.fk_supplier_return = t.rowid";
$sql .= " WHERE t.entity = ".((int) $conf->entity);
if ($search_ref) $sql .= natural_search('t.ref', $search_ref);
if ($search_supplier) $sql .= natural_search('s.nom', $search_supplier);
if ($search_status) $sql .= " AND t.status = '".$db->escape($search_status)."'";
$sql .= " GROUP BY t.rowid, t.ref, t.fk_soc_supplier, t.supplier_return_ref, t.status, t.date_creation, t.date_shipped, s.nom";

$sqlcount = "SELECT COUNT(*) AS nb FROM (".$sql.") tmp";
$rescount = $db->query($sqlcount);
$nbtotalofrecords = 0;
if ($rescount && ($oc = $db->fetch_object($rescount))) $nbtotalofrecords = (int) $oc->nb;
if ($rescount) $db->free($rescount);

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit, $offset);
$resql = $db->query($sql);

llxHeader('', $langs->trans('SupplierReturns'), '');

$newbutton = '';
if ($user->hasRight('warrantysvc', 'supplierreturn', 'write')) {
	$newbutton = dolGetButtonTitle($langs->trans('NewSupplierReturn'), '', 'fa fa-plus-circle', DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_card.php?action=create');
}
print_barre_liste($langs->trans('SupplierReturns'), $page, $_SERVER['PHP_SELF'], '', $sortfield, $sortorder, '', $nbtotalofrecords, $nbtotalofrecords, 'shipment', 0, $newbutton, '', $limit);

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'"><div class="div-table-responsive"><table class="tabl noborder liste centpercent">';
print '<tr class="liste_titre_filter">';
print '<td><input name="search_ref" class="flat maxwidth100" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td><input name="search_supplier" class="flat maxwidth150" value="'.dol_escape_htmltag($search_supplier).'"></td>';
print '<td></td><td>';
$statusOptions = array(
	''=>'',
	SvcSupplierReturn::STATUS_DRAFT=>$langs->trans('SupplierReturnStatusDraft'),
	SvcSupplierReturn::STATUS_AUTHORIZED=>$langs->trans('SupplierReturnStatusAuthorized'),
	SvcSupplierReturn::STATUS_SHIPPED=>$langs->trans('SupplierReturnStatusShipped'),
	SvcSupplierReturn::STATUS_CLOSED=>$langs->trans('SupplierReturnStatusClosed'),
	SvcSupplierReturn::STATUS_CANCELLED=>$langs->trans('SupplierReturnStatusCancelled'),
);
print Form::selectarray('search_status', $statusOptions, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'flat maxwidth150');
print '</td><td></td><td class="right"><input type="submit" class="button small" value="'.$langs->trans('Search').'"></td></tr>';

print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('Supplier').'</td><td>'.$langs->trans('SupplierReturnExternalRef').'</td><td>'.$langs->trans('Status').'</td><td class="right">'.$langs->trans('Qty').'</td><td>'.$langs->trans('DateCreation').'</td></tr>';

if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$tmp = new SvcSupplierReturn($db);
		$tmp->id = (int) $obj->rowid;
		$tmp->ref = $obj->ref;
		$tmp->status = $obj->status;
		print '<tr class="oddeven"><td>'.$tmp->getNomUrl(1).'</td>';
		print '<td>'.dol_escape_htmltag($obj->supplier_name).'</td>';
		print '<td>'.dol_escape_htmltag($obj->supplier_return_ref ?: '—').'</td>';
		print '<td>'.$tmp->getLibStatut().'</td>';
		print '<td class="right">'.price($obj->qty_total,0,$langs,0,0,-1).'</td>';
		print '<td>'.dol_print_date($db->jdate($obj->date_creation),'dayhour').'</td></tr>';
	}
	$db->free($resql);
}
print '</table></div></form>';

llxFooter();
$db->close();
