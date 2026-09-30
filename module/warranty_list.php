<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    warranty_list.php
 * \ingroup warrantysvc
 * \brief   Warranty list view
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantytype.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'companies', 'products'));

if (!$user->hasRight('warrantysvc', 'svcwarranty', 'read')) {
	accessforbidden();
}

$action      = GETPOST('action', 'aZ09');
$optioncss   = GETPOST('optioncss', 'alpha');
$socid       = GETPOSTINT('socid');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'svcwarranty';
$duration_source = warrantysvc_get_duration_source();
$use_warranty_types = ($duration_source === 'warranty_type');

// Search filters
$search_ref         = GETPOST('search_ref', 'alpha');
$search_company     = GETPOST('search_company', 'alpha');
$search_product     = GETPOST('search_product', 'alpha');
$search_serial      = GETPOST('search_serial', 'alpha');
$search_wtype       = GETPOST('search_wtype', 'alpha');
$search_status      = GETPOST('search_status', 'alpha');
$search_expiry_from = dol_mktime(0, 0, 0, GETPOST('search_expiry_frommonth', 'int'), GETPOST('search_expiry_fromday', 'int'), GETPOST('search_expiry_fromyear', 'int'));
$search_expiry_to   = dol_mktime(23, 59, 59, GETPOST('search_expiry_tomonth', 'int'), GETPOST('search_expiry_today', 'int'), GETPOST('search_expiry_toyear', 'int'));

// Quick filter presets
$preset = GETPOST('preset', 'alpha');
if ($preset == 'active') {
	$search_status = 'active';
}
if ($preset == 'expiring') {
	$search_status      = 'active';
	$search_expiry_from = dol_now();
	$search_expiry_to   = dol_time_plus_duree(dol_now(), 30, 'd');
}
if ($preset == 'expired') {
	$search_status = 'expired';
}

// Reset filters
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_ref = $search_company = $search_product = $search_serial = '';
	$search_wtype = $search_status = '';
	$search_expiry_from = $search_expiry_to = '';
	$preset = '';
}

$sortfield = GETPOST('sortfield', 'aZ09comma') ? GETPOST('sortfield', 'aZ09comma') : 't.expiry_date';
$sortorder = GETPOST('sortorder', 'aZ09comma') ? GETPOST('sortorder', 'aZ09comma') : 'ASC';
$limit     = $conf->liste_limit;
$page      = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : max(0, GETPOST('page', 'int'));
$offset    = $limit * $page;
$today_date = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');

// Product-field mode stores expiry_date explicitly. Upstream mode retains the
// historical fallback to the selected Warranty Type duration.
$eff_exp = $use_warranty_types
	? "COALESCE(t.expiry_date, IF(t.start_date IS NOT NULL AND wt.default_coverage_days > 0, DATE_ADD(t.start_date, INTERVAL wt.default_coverage_days DAY), NULL))"
	: "t.expiry_date";

// Build query
$sql  = "SELECT t.rowid, t.ref, t.fk_soc, t.fk_product, t.serial_number,";
$sql .= " t.warranty_type, t.start_date, t.expiry_date, t.coverage_months, t.status,";
$sql .= " (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."svc_request sr WHERE sr.fk_warranty = t.rowid) AS claim_count,";
$sql .= " t.total_claimed_value,";
$sql .= " s.nom as company_name,";
$sql .= " p.ref as product_ref, p.label as product_label,";
if ($use_warranty_types) {
	$sql .= " wt.default_coverage_days,";
}
$sql .= " ".$eff_exp." AS effective_expiry";
$sql .= " FROM ".MAIN_DB_PREFIX."svc_warranty as t";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = t.fk_soc";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = t.fk_product";
if ($use_warranty_types) {
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."svc_warranty_type as wt ON wt.code = t.warranty_type";
	$sql .= "  AND wt.entity IN (".getEntity('svcwarrantytype').")";
}
$sql .= " WHERE t.entity IN (".getEntity('svcwarranty').")";

if ($socid > 0) {
	$sql .= " AND t.fk_soc = ".((int) $socid);
}
if ($search_ref) {
	$sql .= natural_search('t.ref', $search_ref);
}
if ($search_company) {
	$sql .= natural_search('s.nom', $search_company);
}
if ($search_product) {
	$sql .= natural_search('p.ref', $search_product);
}
if ($search_serial) {
	$sql .= natural_search('t.serial_number', $search_serial);
}
if ($use_warranty_types && $search_wtype) {
	$sql .= " AND t.warranty_type = '".$db->escape($search_wtype)."'";
}
if ($search_status && $search_status != '-1') {
	if ($search_status == 'active') {
		$sql .= " AND t.status != 'voided' AND (".$eff_exp." IS NULL OR ".$eff_exp." >= '".$db->escape($today_date)."')";
	} elseif ($search_status == 'expiring') {
		$expiring_to = dol_print_date(dol_time_plus_duree(dol_now(), 30, 'd'), '%Y-%m-%d', 'tzserver');
		$sql .= " AND t.status != 'voided'";
		$sql .= " AND ".$eff_exp." >= '".$db->escape($today_date)."'";
		$sql .= " AND ".$eff_exp." <= '".$db->escape($expiring_to)."'";
	} elseif ($search_status == 'expired') {
		$sql .= " AND t.status != 'voided' AND ".$eff_exp." < '".$db->escape($today_date)."'";
	} elseif ($search_status == 'voided') {
		$sql .= " AND t.status = 'voided'";
	}
}
if ($search_expiry_from) {
	$sql .= " AND ".$eff_exp." >= '".$db->idate($search_expiry_from)."'";
}
if ($search_expiry_to) {
	$sql .= " AND ".$eff_exp." <= '".$db->idate($search_expiry_to)."'";
}

// Count before ordering/limiting. Wrap the filtered query instead of
// rewriting SELECT with a regex: the warranty query itself contains a
// correlated SELECT for claim_count, so regex rewriting can produce invalid SQL.
$sqlcount = "SELECT COUNT(*) as nb FROM (".$sql.") AS warranty_count";
$nbtotalofrecords = 0;
$resqlcount = $db->query($sqlcount);
if ($resqlcount) {
	$objcount = $db->fetch_object($resqlcount);
	$nbtotalofrecords = $objcount->nb;
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit, $offset);

/*
 * View
 */
llxHeader('', $langs->trans('Warranties'), '');

// When accessed from a third party tab, show the third party header + tabs
if ($socid > 0) {
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
	$soc = new Societe($db);
	$soc->fetch($socid);
	$head = societe_prepare_head($soc);
	print dol_get_fiche_head($head, 'warrantysvc_warranties', $langs->trans('ThirdParty'), -1, 'company');
	dol_banner_tab($soc, 'socid', '', 0, 'rowid', 'nom');
	print dol_get_fiche_end();
	print '<br>';
}

$newcardbutton = '';
if ($user->hasRight('warrantysvc', 'svcwarranty', 'write')) {
	$newcardbutton = dolGetButtonTitle(
		$langs->trans('NewWarranty'),
		'',
		'fa fa-plus-circle',
		DOL_URL_ROOT.'/custom/warrantysvc/warranty_card.php?action=create'
	);
}

print_barre_liste(
	$langs->trans('Warranties'),
	$page,
	$_SERVER['PHP_SELF'],
	'',
	$sortfield,
	$sortorder,
	'',
	$nbtotalofrecords,
	$nbtotalofrecords,
	'bill',
	0,
	$newcardbutton,
	'',
	$limit,
	0,
	0,
	1
);

// Build type label cache only when upstream Warranty Type mode is active.
$wtype_labels = array();
if ($use_warranty_types) {
	foreach (SvcWarrantyType::fetchAllForForm($db) as $wt) {
		$wtype_labels[$wt->code] = $wt->label;
	}
}

print '<style>.warranty-row-expired { background-color: rgba(220,53,69,0.07) !important; }</style>';

// Quick filter presets
print '<div class="divsearchfield">';
$socparam = ($socid > 0) ? '&socid='.((int) $socid) : '';
print '<a class="btnTitle'.($preset == 'active' ? ' btnTitleSelected' : '').'" href="'.$_SERVER['PHP_SELF'].'?preset=active'.$socparam.'">'.$langs->trans('SvcActive').'</a> &nbsp;';
print '<a class="btnTitle'.($preset == 'expiring' ? ' btnTitleSelected' : '').'" href="'.$_SERVER['PHP_SELF'].'?preset=expiring'.$socparam.'">'.$langs->trans('ExpiringSoon').'</a> &nbsp;';
print '<a class="btnTitle'.($preset == 'expired' ? ' btnTitleSelected' : '').'" href="'.$_SERVER['PHP_SELF'].'?preset=expired'.$socparam.'">'.$langs->trans('SvcExpired').'</a>';
print '</div>';

print '<form method="GET" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'">';
if ($socid > 0) {
	print '<input type="hidden" name="socid" value="'.((int) $socid).'">';
}
if ($preset) {
	print '<input type="hidden" name="preset" value="'.dol_escape_htmltag($preset).'">';
}

print '<div class="div-table-responsive">';
print '<table class="tabl noborder liste '.($optioncss == 'print' ? 'listwithout' : 'centpercent').'">';

// Filter row
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth75imp" name="search_ref" value="'.dol_escape_htmltag($search_ref).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100imp" name="search_company" value="'.dol_escape_htmltag($search_company).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100imp" name="search_product" value="'.dol_escape_htmltag($search_product).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth75imp" name="search_serial" value="'.dol_escape_htmltag($search_serial).'"></td>';

if ($use_warranty_types) {
	$wtype_filter = array(-1 => '');
	foreach (SvcWarrantyType::fetchAllForForm($db) as $wt) {
		$wtype_filter[$wt->code] = $wt->label;
	}
	print '<td class="liste_titre">';
	print Form::selectarray('search_wtype', $wtype_filter, $search_wtype, 0, 0, 0, '', 0, 0, 0, '', 'flat maxwidth100');
	print '</td>';
}
if (!$use_warranty_types) {
	print '<td class="liste_titre"></td>';
}

// Status filter
$statuses = array(
	-1        => '',
	'active'  => $langs->trans('SvcActive'),
	'expiring'=> $langs->trans('ExpiringSoon'),
	'expired' => $langs->trans('SvcExpired'),
	'voided'  => $langs->trans('SvcVoided'),
);
print '<td class="liste_titre">';
print Form::selectarray('search_status', $statuses, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'flat maxwidth100');
print '</td>';

// Expiry date range
$form = new Form($db);
print '<td class="liste_titre">';
print $form->selectDate($search_expiry_from, 'search_expiry_from', 0, 0, 1, 'searchFormList', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('DateStart'));
print '</td>';
print '<td class="liste_titre">';
print $form->selectDate($search_expiry_to, 'search_expiry_to', 0, 0, 1, 'searchFormList', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('DateEnd'));
print '</td>';

print '<td class="liste_titre center"></td>'; // claims
print '<td class="liste_titre liste_titre_right">';
print '<input type="submit" class="button small liste_titre" name="button_search_x" value="'.dol_escape_htmltag($langs->trans('Search')).'">';
print ' <input type="submit" class="button small liste_titre" name="button_removefilter_x" value="'.dol_escape_htmltag($langs->trans('Reset')).'">';
print '</td>';
print '</tr>';

// Column headers
print '<tr class="liste_titre">';
print getTitleFieldOfList('Ref',           0, $_SERVER['PHP_SELF'], 't.ref',          '', '', '',       '', $sortfield, $sortorder);
print getTitleFieldOfList('Company',       0, $_SERVER['PHP_SELF'], 's.nom',          '', '', '',       '', $sortfield, $sortorder);
print getTitleFieldOfList('Product',       0, $_SERVER['PHP_SELF'], 'p.ref',          '', '', '',       '', $sortfield, $sortorder);
print getTitleFieldOfList('SvcSerialNumber',  0, $_SERVER['PHP_SELF'], 't.serial_number', '', '', '',       '', $sortfield, $sortorder);
if ($use_warranty_types) {
	print getTitleFieldOfList('WarrantyType', 0, $_SERVER['PHP_SELF'], 't.warranty_type', '', '', '', '', $sortfield, $sortorder);
} else {
	print getTitleFieldOfList('WarrantyDuration', 0, $_SERVER['PHP_SELF'], 't.coverage_months', '', '', '', '', $sortfield, $sortorder);
}
print getTitleFieldOfList('Status',        0, $_SERVER['PHP_SELF'], 't.status',       '', '', 'center', '', $sortfield, $sortorder);
print getTitleFieldOfList('StartDate',     0, $_SERVER['PHP_SELF'], 't.start_date',   '', '', '',       '', $sortfield, $sortorder);
print getTitleFieldOfList('ExpiryDate',    0, $_SERVER['PHP_SELF'], 't.expiry_date',  '', '', '',       '', $sortfield, $sortorder);
print getTitleFieldOfList('Claims',        0, $_SERVER['PHP_SELF'], 't.claim_count',  '', '', 'center', '', $sortfield, $sortorder);
print getTitleFieldOfList('',              0, $_SERVER['PHP_SELF'], '',               '', '', 'maxwidthsearch', '', $sortfield, $sortorder);
print '</tr>';

// Data rows
$resql = $db->query($sql);
if ($resql) {
	$num = $db->num_rows($resql);
	$i   = 0;

	if ($num == 0) {
		$column_count = 10;
		print '<tr class="oddeven"><td colspan="'.$column_count.'"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
	}

	$now = dol_now();

	// Buffer the current page so Product LOT ids can be resolved in one query.
	// svc_warranty stores the Product FK and serial/LOT text, not llx_product_lot.rowid.
	// The core Product LOT table has a unique (fk_product, batch) key, so the
	// canonical productlot_card.php?id=... target can be derived safely.
	$rows = array();
	$lot_lookup_conditions = array();
	while ($i < $num) {
		$obj = $db->fetch_object($resql);
		$rows[] = $obj;

		$serial_number = trim((string) $obj->serial_number);
		if (!empty($obj->fk_product) && $serial_number !== '') {
			$lot_lookup_conditions[((int) $obj->fk_product).'\\0'.$serial_number] =
				"(fk_product = ".((int) $obj->fk_product)." AND batch = '".$db->escape($serial_number)."')";
		}
		$i++;
	}
	$db->free($resql);

	$product_lot_ids = array();
	if (!empty($lot_lookup_conditions)) {
		$sql_lots = "SELECT rowid, fk_product, batch FROM ".MAIN_DB_PREFIX."product_lot WHERE ";
		$sql_lots .= implode(" OR ", array_values($lot_lookup_conditions));
		$res_lots = $db->query($sql_lots);
		if ($res_lots) {
			while ($obj_lot = $db->fetch_object($res_lots)) {
				$product_lot_ids[((int) $obj_lot->fk_product).'\\0'.(string) $obj_lot->batch] = (int) $obj_lot->rowid;
			}
			$db->free($res_lots);
		}
	}

	foreach ($rows as $obj) {
		$cardurl = DOL_URL_ROOT.'/custom/warrantysvc/warranty_card.php?id='.$obj->rowid;

		// Use effective_expiry (stored date, or start_date + type duration) for all status logic
		$expiry_ts          = $obj->effective_expiry ? $db->jdate($obj->effective_expiry) : null;
		$expiry_is_calc     = (!$obj->expiry_date && $obj->effective_expiry);

		// Compute live status for display
		if ($obj->status == 'voided') {
			$display_status = 'voided';
		} elseif ($expiry_ts && dol_print_date($expiry_ts, '%Y-%m-%d', 'tzserver') < $today_date) {
			$display_status = 'expired';
		} else {
			$display_status = 'active';
		}

		// Row highlighting: red tint for expired, yellow for expiring within 30 days
		$row_class = 'oddeven';
		if ($display_status == 'expired') {
			$row_class = 'oddeven warranty-row-expired';
		} elseif ($display_status == 'active' && $expiry_ts && $expiry_ts < dol_time_plus_duree($now, 30, 'd')) {
			$row_class = 'oddeven highlight';
		}

		print '<tr class="'.$row_class.'">';
		print '<td><a href="'.$cardurl.'">'.dol_escape_htmltag($obj->ref).'</a></td>';
		print '<td>'.dol_escape_htmltag($obj->company_name).'</td>';

		$product_ref = (string) ($obj->product_ref ? $obj->product_ref : '');
		print '<td>';
		if (!empty($obj->fk_product) && $product_ref !== '') {
			$product_url = DOL_URL_ROOT.'/product/card.php?id='.((int) $obj->fk_product);
			print '<a href="'.$product_url.'">'.dol_escape_htmltag($product_ref).'</a>';
		} else {
			print dol_escape_htmltag($product_ref);
		}
		print '</td>';

		$serial_number = trim((string) $obj->serial_number);
		print '<td>';
		if ($serial_number !== '') {
			$lot_key = ((int) $obj->fk_product).'\\0'.$serial_number;
			$product_lot_id = isset($product_lot_ids[$lot_key]) ? (int) $product_lot_ids[$lot_key] : 0;
			if ($product_lot_id > 0) {
				$lot_url = DOL_URL_ROOT.'/product/stock/productlot_card.php?id='.$product_lot_id;
				print '<a href="'.$lot_url.'">'.dol_escape_htmltag($serial_number).'</a>';
			} else {
				print dol_escape_htmltag($serial_number);
			}
		}
		print '</td>';
		if ($use_warranty_types) {
			$wtype_label = $obj->warranty_type ? ($wtype_labels[$obj->warranty_type] ?? $obj->warranty_type) : '';
			print '<td>'.($wtype_label ? dol_escape_htmltag($wtype_label) : '<span class="opacitymedium">&mdash;</span>').'</td>';
		} else {
			print '<td>'.((int) $obj->coverage_months > 0 ? ((int) $obj->coverage_months).' '.$langs->trans('SvcMonths') : '<span class="opacitymedium">&mdash;</span>').'</td>';
		}
		print '<td class="center">'.svcwarranty_status_badge($display_status).'</td>';
		print '<td>'.dol_print_date($db->jdate($obj->start_date), 'day').'</td>';
		print '<td>';
		if ($expiry_ts) {
			$expiry_label = dol_print_date($expiry_ts, 'day');
			// Italicise calculated dates so users know it derives from the warranty type duration
			if ($expiry_is_calc) {
				$expiry_label = '<em title="'.dol_escape_htmltag($langs->trans('ExpiryDateCalculated')).'">'.$expiry_label.'</em>';
			}
			if ($display_status == 'expired') {
				print '<span class="warning">'.$expiry_label.'</span>';
			} elseif ($display_status == 'active' && $expiry_ts < dol_time_plus_duree($now, 30, 'd')) {
				print '<span class="opacitymediumhigh">'.$expiry_label.'</span>';
			} else {
				print $expiry_label;
			}
		} else {
			print '<span class="opacitymedium">&mdash;</span>';
		}
		print '</td>';
		// Claims count — link to filtered SR list for this warranty
		$claim_count = (int) $obj->claim_count;
		$sr_list_url = DOL_URL_ROOT.'/custom/warrantysvc/list.php?fk_warranty='.$obj->rowid;
		print '<td class="center">';
		if ($claim_count > 0) {
			print '<a href="'.$sr_list_url.'">'.$claim_count.'</a>';
		} else {
			print '<span class="opacitymedium">0</span>';
		}
		print '</td>';
		// Quick-action: New Service Request for this warranty
		print '<td class="center">';
		if ($user->hasRight('warrantysvc', 'svcrequest', 'write') && $display_status !== 'voided') {
			$new_sr_url = DOL_URL_ROOT.'/custom/warrantysvc/card.php?action=create&fk_warranty='.$obj->rowid.'&fk_soc='.$obj->fk_soc.'&serial_number='.urlencode($obj->serial_number)
				.($obj->fk_product ? '&fk_product='.(int) $obj->fk_product : '');
			print '<a href="'.$new_sr_url.'" title="'.dol_escape_htmltag($langs->trans('NewSvcRequest')).'">'.img_picto($langs->trans('NewSvcRequest'), 'add', 'class="size15"').'</a>';
		}
		print '</td>';
		print '</tr>';

	}
} else {
	print '<tr><td colspan="10">'.$db->lasterror().'</td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
