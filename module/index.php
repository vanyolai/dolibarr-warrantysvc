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

dol_include_once('/warrantysvc/class/svcrequest.class.php');
dol_include_once('/warrantysvc/class/svcwarranty.class.php');
dol_include_once('/warrantysvc/class/svcwarrantyletter.class.php');
dol_include_once('/warrantysvc/class/svcsupplierreturn.class.php');
dol_include_once('/warrantysvc/class/svcsupplierrma.class.php');

$langs->loadLangs(array('warrantysvc@warrantysvc','companies','sendings'));

if (!isModEnabled('warrantysvc')) accessforbidden();

$canReadRequests = $user->hasRight('warrantysvc','svcrequest','read');
$canReadWarranties = $user->hasRight('warrantysvc','svcwarranty','read');
$canReadLetters = $user->hasRight('warrantysvc','warrantyletter','read');
$canReadReturns = $user->hasRight('warrantysvc','supplierreturn','read');
$canReadSupplierRma = $user->hasRight('warrantysvc','supplierrma','read');

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
		." AND status <> '".SvcWarranty::STATUS_VOIDED."'"
		." AND (expiry_date IS NULL OR expiry_date >= '".$db->escape($today)."')"
	);
	$stats['warranty_expiring'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status <> '".SvcWarranty::STATUS_VOIDED."'"
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
		." AND w.status <> '".SvcWarranty::STATUS_VOIDED."'"
		.' AND w.fk_expedition IS NOT NULL AND w.fk_expedition > 0'
		.' AND ls.rowid IS NULL'
		.') x'
	);
}

if ($canReadLetters) {
	$stats['letters_ready'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty_letter'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status = '".SvcWarrantyLetter::STATUS_READY."'"
	);
	$stats['letters_stale'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty_letter'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status = '".SvcWarrantyLetter::STATUS_STALE."'"
	);
}

if ($canReadSupplierRma) {
	$stats['supplier_rma_open'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_supplier_rma'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status NOT IN ('".SvcSupplierRma::STATUS_CLOSED."','".SvcSupplierRma::STATUS_CANCELLED."')"
	);
	$stats['supplier_rma_in_service'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_supplier_rma'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status IN ('".SvcSupplierRma::STATUS_RECEIVED_BY_SUPPLIER."','".SvcSupplierRma::STATUS_IN_SERVICE."')"
	);
}

if ($canReadReturns) {
	$stats['returns_open'] = $scalar($db,
		'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_supplier_return'
		.' WHERE entity = '.((int) $conf->entity)
		." AND status NOT IN ('".SvcSupplierReturn::STATUS_CLOSED."','".SvcSupplierReturn::STATUS_CANCELLED."')"
	);
}

llxHeader('', $langs->trans('WarrantySvcOverview'));

print load_fiche_titre($langs->trans('WarrantySvcOverview'), '', 'technic');

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

if ($canReadRequests) {
	print load_fiche_titre($langs->trans('SvcRequests'), '', 'technic');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/list.php',1).'?search_status=0%2C1%2C2%2C3%2C4%2C6">'.$langs->trans('WarrantySvcOpenRequests').'</a></td><td class="right"><strong>'.$stats['requests_open'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/list.php',1).'?preset=awaitreturn">'.$langs->trans('AwaitingReturn').'</a></td><td class="right"><strong>'.$stats['requests_await'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/list.php',1).'?search_assigned=-1&search_status=0%2C1%2C2%2C3%2C4%2C6">'.$langs->trans('Unassigned').'</a></td><td class="right"><strong>'.$stats['requests_unassigned'].'</strong></td></tr>';
	print '</table>';
	print '<br>';
}

if ($canReadSupplierRma) {
	print load_fiche_titre($langs->trans('SupplierRmaServiceMenu'), '', 'tools');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/supplier_rma_list.php',1).'?preset=open">'.$langs->trans('SupplierRmaOpenOverview').'</a></td><td class="right"><strong>'.$stats['supplier_rma_open'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/supplier_rma_list.php',1).'?preset=service">'.$langs->trans('SupplierRmaInServiceOverview').'</a></td><td class="right"><strong>'.$stats['supplier_rma_in_service'].'</strong></td></tr>';
	print '</table>';
	print '<br>';
}

if ($canReadReturns) {
	print load_fiche_titre($langs->trans('SupplierReturns'), '', 'shipment');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/supplier_return_list.php',1).'">'.$langs->trans('WarrantySvcOpenSupplierReturns').'</a></td><td class="right"><strong>'.$stats['returns_open'].'</strong></td></tr>';
	print '</table>';
}

print '</div>';
print '<div class="fichehalfright">';

if ($canReadWarranties) {
	print load_fiche_titre($langs->trans('Warranties'), '', 'bill');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/warranty_list.php',1).'?preset=active">'.$langs->trans('SvcActive').'</a></td><td class="right"><strong>'.$stats['warranty_active'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/warranty_list.php',1).'?preset=expiring">'.$langs->trans('ExpiringSoon').'</a></td><td class="right"><strong>'.$stats['warranty_expiring'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/warranty_shipment_list.php',1).'?preset=withoutletter">'.$langs->trans('WarrantySvcShipmentsWithoutLetter').'</a></td><td class="right"><strong>'.$stats['shipment_without_letter'].'</strong></td></tr>';
	print '</table>';
	print '<br>';
}

if ($canReadLetters) {
	print load_fiche_titre($langs->trans('WarrantyLetters'), '', 'pdf');
	print '<table class="noborder centpercent">';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/warranty_letter_list.php',1).'?search_status='.urlencode(SvcWarrantyLetter::STATUS_READY).'">'.$langs->trans('WarrantyLetterReady').'</a></td><td class="right"><strong>'.$stats['letters_ready'].'</strong></td></tr>';
	print '<tr class="oddeven"><td><a href="'.dol_buildpath('/warrantysvc/warranty_letter_list.php',1).'?search_status='.urlencode(SvcWarrantyLetter::STATUS_STALE).'">'.$langs->trans('WarrantyLetterStale').'</a></td><td class="right"><strong>'.$stats['letters_stale'].'</strong></td></tr>';
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
			print '<td><a href="'.dol_buildpath('/warrantysvc/card.php',1).'?id='.((int) $row->rowid).'">'.dol_escape_htmltag($row->ref).'</a></td>';
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
