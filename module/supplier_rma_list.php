<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    supplier_rma_list.php
 * \ingroup warrantysvc
 * \brief   Independent, permission-scoped supplier service/RMA worklist
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/warrantysvc/class/svcsupplierrma.class.php');

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies', 'products'));
if (!$user->hasRight('warrantysvc', 'supplierrma', 'read') || !empty($user->socid)) accessforbidden();

$searchRef = GETPOST('search_ref', 'alphanohtml');
$searchSupplier = GETPOST('search_supplier', 'alphanohtml');
$searchRequest = GETPOST('search_request', 'alphanohtml');
$searchExternalRef = GETPOST('search_external_ref', 'alphanohtml');
$searchStatus = GETPOST('search_status', 'aZ09');
$preset = GETPOST('preset', 'aZ09');
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'r.date_creation';
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09comma') ?: 'DESC');
if (!in_array($sortorder, array('ASC', 'DESC'), true)) $sortorder = 'DESC';
$allowedSort = array(
	'r.ref'=>'r.ref',
	'r.date_creation'=>'r.date_creation',
	'r.date_supplier_received'=>'r.date_supplier_received',
	'r.status'=>'r.status',
	's.nom'=>'s.nom',
	'sr.ref'=>'sr.ref',
	'p.ref'=>'p.ref',
);
if (!isset($allowedSort[$sortfield])) $sortfield = 'r.date_creation';

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$searchRef = $searchSupplier = $searchRequest = $searchExternalRef = $searchStatus = $preset = '';
}
if (!in_array($preset, array('', 'open', 'service'), true)) $preset = '';

$limit = max(1, (int) $conf->liste_limit);
$page = GETPOSTISSET('pageplusone') ? max(0, GETPOSTINT('pageplusone') - 1) : max(0, GETPOSTINT('page'));
$offset = $page * $limit;

$listparam = '';
foreach (array(
	'search_ref'=>$searchRef,
	'search_supplier'=>$searchSupplier,
	'search_request'=>$searchRequest,
	'search_external_ref'=>$searchExternalRef,
	'search_status'=>$searchStatus,
	'preset'=>$preset
) as $name => $value) {
	if ($value !== '') $listparam .= '&'.$name.'='.urlencode($value);
}

$sqlFrom = ' FROM '.MAIN_DB_PREFIX.'svc_supplier_rma r';
$sqlFrom .= ' JOIN '.MAIN_DB_PREFIX.'svc_request sr ON sr.rowid = r.fk_svc_request AND sr.entity = r.entity';
$sqlFrom .= ' JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid = r.fk_soc_supplier';
$sqlFrom .= ' JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = r.fk_product AND p.entity IN ('.getEntity('product').')';
$sqlWhere = ' WHERE r.entity = '.((int) $conf->entity);
if ($searchRef !== '') $sqlWhere .= natural_search('r.ref', $searchRef);
if ($searchSupplier !== '') $sqlWhere .= natural_search('s.nom', $searchSupplier);
if ($searchRequest !== '') $sqlWhere .= natural_search('sr.ref', $searchRequest);
if ($searchExternalRef !== '') $sqlWhere .= natural_search('r.supplier_rma_ref', $searchExternalRef);
if ($searchStatus !== '') {
	$sqlWhere .= " AND r.status = '".$db->escape($searchStatus)."'";
} elseif ($preset === 'open') {
	$sqlWhere .= " AND r.status NOT IN ('".SvcSupplierRma::STATUS_CLOSED."','".SvcSupplierRma::STATUS_CANCELLED."')";
} elseif ($preset === 'service') {
	$sqlWhere .= " AND r.status IN ('".SvcSupplierRma::STATUS_RECEIVED_BY_SUPPLIER."','".SvcSupplierRma::STATUS_IN_SERVICE."')";
}

$sqlCount = 'SELECT COUNT(*) AS nb'.$sqlFrom.$sqlWhere;
$nbtotalofrecords = 0;
$resCount = $db->query($sqlCount);
if (!$resCount) {
	dol_print_error($db);
} else {
	$count = $db->fetch_object($resCount);
	if ($count) $nbtotalofrecords = (int) $count->nb;
	$db->free($resCount);
}

$sql = 'SELECT r.rowid, r.ref, r.status, r.supplier_rma_ref, r.date_creation, r.date_supplier_received,';
$sql .= ' r.fk_soc_supplier, r.fk_product, sr.rowid AS request_id, sr.ref AS request_ref,';
$sql .= ' s.nom AS supplier_name, p.ref AS product_ref, p.label AS product_label';
$sql .= $sqlFrom.$sqlWhere;
$sql .= $db->order($allowedSort[$sortfield], $sortorder);
$sql .= $db->plimit($limit, $offset);

llxHeader('', $langs->trans('SupplierRmaServiceMenu'));

print_barre_liste(
	$langs->trans('SupplierRmaServiceMenu'), $page, $_SERVER['PHP_SELF'],
	$listparam, $sortfield, $sortorder, '', $nbtotalofrecords,
	$nbtotalofrecords, 'tools', 0, '', '', $limit
);

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
if ($preset !== '') print '<input type="hidden" name="preset" value="'.dol_escape_htmltag($preset).'">';
print '<div class="div-table-responsive">';
print '<table class="liste noborder centpercent">';
print '<tr class="liste_titre_filter">';
print '<td><input class="flat maxwidth100" type="text" name="search_ref" value="'.dol_escape_htmltag($searchRef).'"></td>';
print '<td><input class="flat maxwidth150" type="text" name="search_supplier" value="'.dol_escape_htmltag($searchSupplier).'"></td>';
print '<td><input class="flat maxwidth100" type="text" name="search_request" value="'.dol_escape_htmltag($searchRequest).'"></td>';
print '<td><input class="flat maxwidth100" type="text" name="search_external_ref" value="'.dol_escape_htmltag($searchExternalRef).'"></td>';
print '<td></td><td></td>';
print '<td>';
$statuses = array(''=>'');
foreach (array(
	SvcSupplierRma::STATUS_DRAFT, SvcSupplierRma::STATUS_AUTHORIZED,
	SvcSupplierRma::STATUS_SHIPPED, SvcSupplierRma::STATUS_RECEIVED_BY_SUPPLIER,
	SvcSupplierRma::STATUS_IN_SERVICE, SvcSupplierRma::STATUS_REPAIRED,
	SvcSupplierRma::STATUS_REPLACED, SvcSupplierRma::STATUS_REJECTED,
	SvcSupplierRma::STATUS_RETURNED, SvcSupplierRma::STATUS_CLOSED,
	SvcSupplierRma::STATUS_CANCELLED
) as $status) {
	$stub = new SvcSupplierRma($db);
	$stub->status = $status;
	$statuses[$status] = $stub->getLibStatut(1);
}
print Form::selectarray('search_status', $statuses, $searchStatus, 0, 0, 0, '', 0, 0, 0, '', 'flat maxwidth150');
print '</td>';
print '<td class="right">';
print '<input type="submit" name="button_search_x" class="button small" value="'.dol_escape_htmltag($langs->trans('Search')).'">';
print ' <input type="submit" name="button_removefilter_x" class="button small" value="'.dol_escape_htmltag($langs->trans('Reset')).'">';
print '</td></tr>';

print '<tr class="liste_titre">';
print getTitleFieldOfList('Ref', 0, $_SERVER['PHP_SELF'], 'r.ref', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('Supplier', 0, $_SERVER['PHP_SELF'], 's.nom', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('SvcRequest', 0, $_SERVER['PHP_SELF'], 'sr.ref', '', $listparam, '', $sortfield, $sortorder);
print '<th>'.$langs->trans('SupplierRmaExternalRef').'</th>';
print getTitleFieldOfList('Product', 0, $_SERVER['PHP_SELF'], 'p.ref', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('DateCreation', 0, $_SERVER['PHP_SELF'], 'r.date_creation', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('Status', 0, $_SERVER['PHP_SELF'], 'r.status', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('SupplierRmaDateSupplierReceived', 0, $_SERVER['PHP_SELF'], 'r.date_supplier_received', '', $listparam, '', $sortfield, $sortorder);
print '</tr>';

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
} else {
	if (!$db->num_rows($resql)) {
		print '<tr class="oddeven"><td colspan="8" class="opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
	}
	while ($row = $db->fetch_object($resql)) {
		$item = new SvcSupplierRma($db);
		$item->id = (int) $row->rowid;
		$item->ref = (string) $row->ref;
		$item->status = (string) $row->status;

		print '<tr class="oddeven">';
		print '<td>'.$item->getNomUrl(1).'</td>';
		print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $row->fk_soc_supplier).'">'.dol_escape_htmltag((string) $row->supplier_name).'</a></td>';
		print '<td><a href="'.dol_buildpath('/warrantysvc/card.php', 1).'?id='.((int) $row->request_id).'">'.dol_escape_htmltag((string) $row->request_ref).'</a></td>';
		print '<td>'.dol_escape_htmltag((string) $row->supplier_rma_ref).'</td>';
		print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $row->fk_product).'">'.dol_escape_htmltag((string) $row->product_ref).'</a></td>';
		print '<td>'.dol_print_date($db->jdate($row->date_creation), 'day').'</td>';
		print '<td>'.$item->getLibStatut().'</td>';
		print '<td>'.(!empty($row->date_supplier_received) ? dol_print_date($db->jdate($row->date_supplier_received), 'day') : '—').'</td>';
		print '</tr>';
	}
	$db->free($resql);
}
print '</table></div></form>';

llxFooter();
$db->close();
