<?php
/* Copyright (C) 2026 DPG Supply */
/**
 * WarrantySvc operational overview.
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) $res = @include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantyletter.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierreturn.class.php';

$langs->loadLangs(array('warrantysvc@warrantysvc','companies','sendings'));

if (!isModEnabled('warrantysvc')) accessforbidden();

$canReadRequests = $user->hasRight('warrantysvc','svcrequest','read');
$canReadWarranties = $user->hasRight('warrantysvc','svcwarranty','read');
$canReadLetters = $user->hasRight('warrantysvc','warrantyletter','read');
$canReadReturns = $user->hasRight('warrantysvc','supplierreturn','read');

$scalar = static function($db, $sql) {
	$res = $db->query($sql);
	if (!$res) return 0;
	$row = $db->fetch_object($res);
	$db->free($res);
	if (!$row) return 0;
	foreach ((array) $row as $value) return (int) $value;
	return 0;
};

$stats = array();

if ($canReadRequests) {
	$stats['requests_open'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_request'
		.' WHERE entity = '.((int) $conf->entity)
		.' AND status NOT IN ('.SvcRequest::STATUS_CLOSED.','.SvcRequest::STATUS_CANCELLED.')'
	);
	$stats['requests_await'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_request'
		.' WHERE entity = '.((int) $conf->entity)
		.' AND status = '.SvcRequest::STATUS_AWAIT_RETURN
	);
	$stats['requests_unassigned'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_request'
		.' WHERE entity = '.((int) $conf->entity)
		.' AND status NOT IN ('.SvcRequest::STATUS_CLOSED.','.SvcRequest::STATUS_CANCELLED.')'
		.' AND (fk_user_assigned IS NULL OR fk_user_assigned = 0)'
	);
}

if ($canReadWarranties) {
	$today = $db->idate(dol_now());
	$soon = $db->idate(dol_time_plus_duree(dol_now(), 30, 'd'));
	$stats['warranty_active'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status <> 'voided'"
		." AND (expiry_date IS NULL OR expiry_date >= '".$db->escape($today)."')"
	);
	$stats['warranty_expiring'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status <> 'voided'"
		." AND expiry_date >= '".$db->escape($today)."'"
		." AND expiry_date <= '".$db->escape($soon)."'"
	);
	$stats['shipment_without_letter'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM ('
		.' SELECT DISTINCT w.fk_expedition'
		.' FROM '.MAIN_DB_PREFIX.'svc_warranty w'
		.' LEFT JOIN '.MAIN_DB_PREFIX.'svc_warranty_letter_shipment ls'
		.' ON ls.entity = w.entity AND ls.fk_expedition = w.fk_expedition'
		.' WHERE w.entity = '.((int) $conf->entity)
		." AND w.status <> 'voided'"
		.' AND w.fk_expedition IS NOT NULL AND w.fk_expedition > 0'
		.' AND ls.rowid IS NULL'
		.') x'
	);
}

if ($canReadLetters) {
	$stats['letters_ready'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty_letter'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status = 'ready'"
	);
	$stats['letters_stale'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty_letter'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status = 'stale'"
	);
}

if ($canReadReturns) {
	$stats['returns_open'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_supplier_return'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status NOT IN ('closed','cancelled')"
	);
}

llxHeader('', $langs->trans('WarrantySvcOverview'));

print load_fiche_titre($langs->trans('WarrantySvcOverview'), '', 'technic');

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

if ($canReadRequests) {
	print load_fiche_titre($langs->trans('SvcRequests'), '', 'technic');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/list.php?preset=myopen">'.$langs->trans('WarrantySvcOpenRequests').'</a></td><td class="right"><strong>'.$stats['requests_open'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/list.php?preset=awaitreturn">'.$langs->trans('AwaitingReturn').'</a></td><td class="right"><strong>'.$stats['requests_await'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/list.php?preset=unassigned">'.$langs->trans('Unassigned').'</a></td><td class="right"><strong>'.$stats['requests_unassigned'].'</strong></td></tr>';
	print '</table>';
	print '<br>';
}

if ($canReadReturns) {
	print load_fiche_titre($langs->trans('SupplierReturns'), '', 'shipment');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/supplier_return_list.php">'.$langs->trans('WarrantySvcOpenSupplierReturns').'</a></td><td class="right"><strong>'.$stats['returns_open'].'</strong></td></tr>';
	print '</table>';
}

print '</div>';
print '<div class="fichehalfright">';

if ($canReadWarranties) {
	print load_fiche_titre($langs->trans('Warranties'), '', 'bill');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_list.php?preset=active">'.$langs->trans('SvcActive').'</a></td><td class="right"><strong>'.$stats['warranty_active'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_list.php?preset=expiring">'.$langs->trans('ExpiringSoon').'</a></td><td class="right"><strong>'.$stats['warranty_expiring'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_shipment_list.php">'.$langs->trans('WarrantySvcShipmentsWithoutLetter').'</a></td><td class="right"><strong>'.$stats['shipment_without_letter'].'</strong></td></tr>';
	print '</table>';
	print '<br>';
}

if ($canReadLetters) {
	print load_fiche_titre($langs->trans('WarrantyLetters'), '', 'pdf');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_list.php?search_status=ready">'.$langs->trans('WarrantyLetterReady').'</a></td><td class="right"><strong>'.$stats['letters_ready'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_list.php?search_status=stale">'.$langs->trans('WarrantyLetterStale').'</a></td><td class="right"><strong>'.$stats['letters_stale'].'</strong></td></tr>';
	print '</table>';
}

print '</div>';
print '</div>';

if ($canReadRequests) {
	print '<div class="clearboth"></div><br>';
	print load_fiche_titre($langs->trans('WarrantySvcAttentionNeeded'), '', 'warning');

	$sql = 'SELECT rowid, ref, subject, status, issue_date, fk_user_assigned';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'svc_request';
	$sql .= ' WHERE entity = '.((int) $conf->entity);
	$sql .= ' AND status NOT IN ('.SvcRequest::STATUS_CLOSED.','.SvcRequest::STATUS_CANCELLED.')';
	$sql .= ' ORDER BY issue_date ASC, rowid ASC';
	$sql .= $db->plimit(10);
	$res = $db->query($sql);

	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Subject').'</th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('Status').'</th></tr>';
	if ($res) {
		while ($row = $db->fetch_object($res)) {
			$request = new SvcRequest($db);
			$request->id = (int) $row->rowid;
			$request->ref = (string) $row->ref;
			$request->status = (int) $row->status;
			print '<tr class="oddeven">';
			print '<td><a href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.((int) $row->rowid).'">'.dol_escape_htmltag($row->ref).'</a></td>';
			print '<td>'.dol_escape_htmltag((string) $row->subject).'</td>';
			print '<td>'.(!empty($row->issue_date) ? dol_print_date($db->jdate($row->issue_date), 'day') : '').'</td>';
			print '<td>'.$request->getLibStatut(2).'</td>';
			print '</tr>';
		}
		$db->free($res);
	}
	print '</table></div>';
}

llxFooter();
$db->close();
