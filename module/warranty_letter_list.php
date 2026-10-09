<?php
/* Copyright (C) 2026 DPG Supply */
/**
 * Classic Dolibarr list of Warranty Letters.
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/warrantysvc/class/svcwarrantyletter.class.php');

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies', 'sendings'));

if (!$user->hasRight('warrantysvc', 'warrantyletter', 'read') || !empty($user->socid)) accessforbidden();

$searchRef = GETPOST('search_ref', 'restricthtml');
$searchCompany = GETPOST('search_company', 'restricthtml');
$searchStatus = GETPOST('search_status', 'aZ09');
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'l.date_creation';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';
if (!in_array(strtoupper($sortorder), array('ASC','DESC'), true)) $sortorder = 'DESC';
$limit = $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? GETPOSTINT('pageplusone') - 1 : max(0, GETPOSTINT('page'));
$offset = $page * $limit;

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$searchRef = '';
	$searchCompany = '';
	$searchStatus = '';
}

$listparam = '';
if ($searchRef !== '') $listparam .= '&search_ref='.urlencode($searchRef);
if ($searchCompany !== '') $listparam .= '&search_company='.urlencode($searchCompany);
if ($searchStatus !== '') $listparam .= '&search_status='.urlencode($searchStatus);

$sqlFrom = ' FROM '.MAIN_DB_PREFIX.'svc_warranty_letter l';
$sqlFrom .= ' JOIN '.MAIN_DB_PREFIX.'societe s ON s.rowid = l.fk_soc';
$sqlFrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'svc_warranty_letter_shipment ls ON ls.entity = l.entity AND ls.fk_letter = l.rowid';

$sqlWhere = ' WHERE l.entity = '.((int) $conf->entity);
if ($searchRef !== '') $sqlWhere .= natural_search('l.ref', $searchRef);
if ($searchCompany !== '') $sqlWhere .= natural_search('s.nom', $searchCompany);
if ($searchStatus !== '') $sqlWhere .= " AND l.status = '".$db->escape($searchStatus)."'";

$sqlCount = 'SELECT COUNT(*) AS nb FROM (SELECT l.rowid'.$sqlFrom.$sqlWhere.' GROUP BY l.rowid) x';
$nbtotalofrecords = 0;
$resCount = $db->query($sqlCount);
if ($resCount) {
	$countObj = $db->fetch_object($resCount);
	if ($countObj) $nbtotalofrecords = (int) $countObj->nb;
	$db->free($resCount);
}

$sql = 'SELECT l.rowid, l.ref, l.fk_soc, l.status, l.current_version, l.last_sent_version, l.date_creation,';
$sql .= ' s.nom AS company_name, COUNT(ls.rowid) AS shipment_count';
$sql .= $sqlFrom.$sqlWhere;
$sql .= ' GROUP BY l.rowid, l.ref, l.fk_soc, l.status, l.current_version, l.last_sent_version, l.date_creation, s.nom';

$allowedSort = array(
	'l.ref' => 'l.ref',
	's.nom' => 's.nom',
	'l.date_creation' => 'l.date_creation',
	'shipment_count' => 'shipment_count',
	'l.current_version' => 'l.current_version',
	'l.last_sent_version' => 'l.last_sent_version',
	'l.status' => 'l.status',
);
if (!isset($allowedSort[$sortfield])) {
	$sortfield = 'l.date_creation';
}
$sql .= $db->order($allowedSort[$sortfield], $sortorder);
$sql .= $db->plimit($limit, $offset);

llxHeader('', $langs->trans('WarrantyLetters'));

print_barre_liste(
	$langs->trans('WarrantyLetters'),
	$page,
	$_SERVER['PHP_SELF'],
	$listparam,
	$sortfield,
	$sortorder,
	'',
	$nbtotalofrecords,
	$nbtotalofrecords,
	'pdf',
	0,
	'',
	'',
	$limit
);

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" id="warrantyLetterListSearch">';
print '<div class="div-table-responsive">';
print '<table class="tabl noborder liste centpercent">';

print '<tr class="liste_titre_filter">';
print '<td><input type="text" class="flat maxwidth100" name="search_ref" value="'.dol_escape_htmltag($searchRef).'"></td>';
print '<td><input type="text" class="flat maxwidth150" name="search_company" value="'.dol_escape_htmltag($searchCompany).'"></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td>';
$statuses = array(
	'' => '',
	SvcWarrantyLetter::STATUS_DRAFT => $langs->trans('WarrantyLetterDraft'),
	SvcWarrantyLetter::STATUS_READY => $langs->trans('WarrantyLetterReady'),
	SvcWarrantyLetter::STATUS_SENT => $langs->trans('WarrantyLetterSent'),
	SvcWarrantyLetter::STATUS_UPDATED => $langs->trans('WarrantyLetterUpdated'),
	SvcWarrantyLetter::STATUS_STALE => $langs->trans('WarrantyLetterStale'),
);
print Form::selectarray('search_status', $statuses, $searchStatus, 0, 0, 0, '', 0, 0, 0, '', 'flat maxwidth150');
print '</td>';
print '<td class="right">';
print '<input type="submit" class="button small liste_titre" name="button_search_x" value="'.dol_escape_htmltag($langs->trans('Search')).'">';
print ' <input type="submit" class="button small liste_titre" name="button_removefilter_x" value="'.dol_escape_htmltag($langs->trans('Reset')).'">';
print '</td>';
print '</tr>';

print '<tr class="liste_titre">';
print getTitleFieldOfList('Ref', 0, $_SERVER['PHP_SELF'], 'l.ref', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('Company', 0, $_SERVER['PHP_SELF'], 's.nom', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('DateCreation', 0, $_SERVER['PHP_SELF'], 'l.date_creation', '', $listparam, '', $sortfield, $sortorder);
print getTitleFieldOfList('WarrantyLetterShipmentCount', 0, $_SERVER['PHP_SELF'], 'shipment_count', '', $listparam, 'class="center"', $sortfield, $sortorder);
print getTitleFieldOfList('WarrantyLetterVersion', 0, $_SERVER['PHP_SELF'], 'l.current_version', '', $listparam, 'class="center"', $sortfield, $sortorder);
print getTitleFieldOfList('WarrantyLetterLastSentVersion', 0, $_SERVER['PHP_SELF'], 'l.last_sent_version', '', $listparam, 'class="center"', $sortfield, $sortorder);
print getTitleFieldOfList('Status', 0, $_SERVER['PHP_SELF'], 'l.status', '', $listparam, '', $sortfield, $sortorder);
print '<th></th>';
print '</tr>';

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
} else {
	if ($db->num_rows($resql) === 0) {
		print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
	}
	while ($row = $db->fetch_object($resql)) {
		$letter = new SvcWarrantyLetter($db);
		$letter->id = (int) $row->rowid;
		$letter->ref = (string) $row->ref;
		$letter->status = (string) $row->status;

		print '<tr class="oddeven">';
		print '<td>'.$letter->getNomUrl(1).'</td>';
		print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $row->fk_soc).'">'.dol_escape_htmltag($row->company_name).'</a></td>';
		print '<td>'.dol_print_date($db->jdate($row->date_creation), 'dayhour').'</td>';
		print '<td class="center">'.((int) $row->shipment_count).'</td>';
		print '<td class="center">'.((int) $row->current_version).'</td>';
		print '<td class="center">'.((int) $row->last_sent_version).'</td>';
		print '<td>'.$letter->getLibStatut(2).'</td>';
		print '<td></td>';
		print '</tr>';
	}
	$db->free($resql);
}

print '</table></div>';
print '</form>';

llxFooter();
$db->close();
