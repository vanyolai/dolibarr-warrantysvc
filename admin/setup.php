<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    admin/setup.php
 * \ingroup warrantysvc
 * \brief   Module configuration page
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
dol_include_once('/warrantysvc/lib/warrantysvc.lib.php');

$langs->loadLangs(array('admin', 'warrantysvc@warrantysvc'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$product_month_fields = warrantysvc_get_product_month_field_options($db, (int) $conf->entity);
$current_duration_source = warrantysvc_get_duration_source();

// Save settings
if ($action == 'update') {
	$posted_duration_source = GETPOST('WARRANTYSVC_DURATION_SOURCE', 'alpha');
	if (!in_array($posted_duration_source, array('product_field', 'warranty_type'), true)) {
		$posted_duration_source = $current_duration_source;
	}

	$posted_month_field = GETPOST('WARRANTYSVC_PRODUCT_WARRANTY_MONTHS_FIELD', 'alpha');
	$settings_valid = true;
	if ($posted_duration_source === 'product_field') {
		if ($posted_month_field === '') {
			setEventMessages($langs->trans('ErrorProductWarrantyFieldNotConfigured'), null, 'errors');
			$settings_valid = false;
		} elseif (!isset($product_month_fields[$posted_month_field])) {
			setEventMessages($langs->trans('ErrorInvalidProductWarrantyMonthsField'), null, 'errors');
			$settings_valid = false;
		}
	}

	if ($settings_valid) {
		$common_settings = array(
			'WARRANTYSVC_WAREHOUSE_REFURB',
			'WARRANTYSVC_WAREHOUSE_RETURN',
			'WARRANTYSVC_RETURN_GRACE_DAYS',
			'WARRANTYSVC_RETURN_INVOICE_DAYS',
			'WARRANTYSVC_REPLACEMENT_STRATEGY',
			'WARRANTYSVC_AUTO_WARRANTY_CHECK',
			'WARRANTYSVC_AUTO_WARRANTY_ON_SHIPMENT',
			'WARRANTYSVC_WARRANTY_TRIGGER_EVENT',
			'WARRANTYSVC_AUTO_WARRANTY_ON_ORDER_CLOSE',
			'WARRANTYSVC_WARRANTY_REQUIRES_LOTS',
			'WARRANTYSVC_USE_CUSTOMERRETURN',
			'WARRANTYSVC_DEBUG_MODE',
		);

		foreach ($common_settings as $key) {
			dolibarr_set_const($db, $key, GETPOST($key, 'alpha'), 'chaine', 0, '', $conf->entity);
		}

		dolibarr_set_const($db, 'WARRANTYSVC_DURATION_SOURCE', $posted_duration_source, 'chaine', 0, '', $conf->entity);

		// Keep the inactive mode's settings intact so administrators can switch
		// between the fork policy and upstream behaviour without losing config.
		if ($posted_duration_source === 'product_field') {
			dolibarr_set_const($db, 'WARRANTYSVC_PRODUCT_WARRANTY_MONTHS_FIELD', $posted_month_field, 'chaine', 0, '', $conf->entity);
		} else {
			$default_days = GETPOSTINT('WARRANTYSVC_DEFAULT_COVERAGE_DAYS');
			if ($default_days <= 0) {
				$default_days = 365;
			}
			dolibarr_set_const($db, 'WARRANTYSVC_DEFAULT_COVERAGE_DAYS', $default_days, 'chaine', 0, '', $conf->entity);
		}

		$current_duration_source = $posted_duration_source;
		setEventMessages($langs->trans('SvcSetupSaved'), null, 'mesgs');
	}
}

// Load warehouses for selectors
$entrepot = new Entrepot($db);
$warehouses = $entrepot->list_array();

/*
 * View
 */

$wikihelp = '';
llxHeader('', $langs->trans('WarrantySvc').' - '.$langs->trans('SvcSetup'), $wikihelp);

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'
	.$langs->trans('BackToModuleList').'</a>';

print load_fiche_titre($langs->trans('WarrantySvcSetup'), $linkback, 'title_setup');

$head = warrantysvc_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans('WarrantySvc'), -1, 'technic');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Parameter').'</td>';
print '<td>'.$langs->trans('Value').'</td>';
print '</tr>';

// Refurbished stock warehouse
print '<tr class="oddeven">';
print '<td>'.$langs->trans('SvcWarehouseSource').'<br><span class="opacitymedium">'
	.$langs->trans('SvcWarehouseRefurbDesc').'</span></td>';
print '<td>';
print '<select name="WARRANTYSVC_WAREHOUSE_REFURB" class="flat minwidth300">';
print '<option value="">--- '.$langs->trans('SvcSelectWarehouse').' ---</option>';
foreach ($warehouses as $id => $label) {
	$sel = (getDolGlobalString('WARRANTYSVC_WAREHOUSE_REFURB') == $id) ? ' selected' : '';
	print '<option value="'.$id.'"'.$sel.'>'.dol_escape_htmltag($label).'</option>';
}
print '</select>';
print '</td></tr>';

// RMA return / repair warehouse
print '<tr class="oddeven">';
print '<td>'.$langs->trans('SvcWarehouseReturn').'<br><span class="opacitymedium">'
	.$langs->trans('SvcWarehouseReturnDesc').'</span></td>';
print '<td>';
print '<select name="WARRANTYSVC_WAREHOUSE_RETURN" class="flat minwidth300">';
print '<option value="">--- '.$langs->trans('SvcSelectWarehouse').' ---</option>';
foreach ($warehouses as $id => $label) {
	$sel = (getDolGlobalString('WARRANTYSVC_WAREHOUSE_RETURN') == $id) ? ' selected' : '';
	print '<option value="'.$id.'"'.$sel.'>'.dol_escape_htmltag($label).'</option>';
}
print '</select>';
print '</td></tr>';

// Return grace period (days before first reminder)
print '<tr class="oddeven">';
print '<td>'.$langs->trans('ReturnGraceDays').'<br><span class="opacitymedium">'
	.$langs->trans('ReturnGraceDaysDesc').'</span></td>';
print '<td>';
print '<input type="number" name="WARRANTYSVC_RETURN_GRACE_DAYS" class="flat" min="1" max="90" value="'.dol_escape_htmltag(getDolGlobalString('WARRANTYSVC_RETURN_GRACE_DAYS', '7')).'">';
print ' '.$langs->trans('days');
print '</td></tr>';

// Days before auto-invoice for non-return
print '<tr class="oddeven">';
print '<td>'.$langs->trans('ReturnInvoiceDays').'<br><span class="opacitymedium">'
	.$langs->trans('ReturnInvoiceDaysDesc').'</span></td>';
print '<td>';
print '<input type="number" name="WARRANTYSVC_RETURN_INVOICE_DAYS" class="flat" min="1" max="180" value="'.dol_escape_htmltag(getDolGlobalString('WARRANTYSVC_RETURN_INVOICE_DAYS', '30')).'">';
print ' '.$langs->trans('days');
print '</td></tr>';

// Replacement unit selection strategy
print '<tr class="oddeven">';
print '<td>'.$langs->trans('ReplacementStrategy').'<br><span class="opacitymedium">'
	.$langs->trans('ReplacementStrategyDesc').'</span></td>';
print '<td>';
$current_strategy = getDolGlobalString('WARRANTYSVC_REPLACEMENT_STRATEGY', 'fifo');
$strategies = array(
	'fifo'           => $langs->trans('StrategyFIFO'),
	'least_serviced' => $langs->trans('StrategyLeastServiced'),
	'best_condition' => $langs->trans('StrategyBestCondition'),
	'manual'         => $langs->trans('StrategyManual'),
);
print '<select name="WARRANTYSVC_REPLACEMENT_STRATEGY" class="flat minwidth300">';
foreach ($strategies as $key => $label) {
	$sel = ($current_strategy == $key) ? ' selected' : '';
	print '<option value="'.$key.'"'.$sel.'>'.dol_escape_htmltag($label).'</option>';
}
print '</select>';
print '</td></tr>';

// Auto warranty check on SvcRequest creation
print '<tr class="oddeven">';
print '<td>'.$langs->trans('AutoWarrantyCheck').'<br><span class="opacitymedium">'
	.$langs->trans('AutoWarrantyCheckDesc').'</span></td>';
print '<td>';
$chk = getDolGlobalString('WARRANTYSVC_AUTO_WARRANTY_CHECK', '1') ? ' checked' : '';
print '<input type="checkbox" name="WARRANTYSVC_AUTO_WARRANTY_CHECK" value="1"'.$chk.'>';
print '</td></tr>';

// Auto-create warranty on shipment
print '<tr class="oddeven">';
print '<td>'.$langs->trans('AutoWarrantyOnShipment').'<br><span class="opacitymedium">'
	.$langs->trans('AutoWarrantyOnShipmentDesc').'</span></td>';
print '<td>';
$chk2 = getDolGlobalString('WARRANTYSVC_AUTO_WARRANTY_ON_SHIPMENT') ? ' checked' : '';
print '<input type="checkbox" name="WARRANTYSVC_AUTO_WARRANTY_ON_SHIPMENT" value="1"'.$chk2.'>';
print '</td></tr>';

// Warranty trigger event (only shown when auto-create is enabled)
print '<tr class="oddeven">';
print '<td>'.$langs->trans('WarrantyTriggerEvent').'<br><span class="opacitymedium">'
	.$langs->trans('WarrantyTriggerEventDesc').'</span></td>';
print '<td>';
$trigger_event = getDolGlobalString('WARRANTYSVC_WARRANTY_TRIGGER_EVENT', 'close');
print '<select name="WARRANTYSVC_WARRANTY_TRIGGER_EVENT" class="flat minwidth200">';
print '<option value="validate"'.($trigger_event == 'validate' ? ' selected' : '').'>'.$langs->trans('OnShipmentValidate').'</option>';
print '<option value="close"'.($trigger_event == 'close' ? ' selected' : '').'>'.$langs->trans('OnShipmentClose').'</option>';
print '<option value="both"'.($trigger_event == 'both' ? ' selected' : '').'>'.$langs->trans('OnShipmentBoth').'</option>';
print '</select>';
print '</td></tr>';

// Auto-create warranty on order close (delivered)
print '<tr class="oddeven">';
print '<td>'.$langs->trans('AutoWarrantyOnOrderClose').'<br><span class="opacitymedium">'
	.$langs->trans('AutoWarrantyOnOrderCloseDesc').'</span></td>';
print '<td>';
$chk_oc = getDolGlobalString('WARRANTYSVC_AUTO_WARRANTY_ON_ORDER_CLOSE') ? ' checked' : '';
print '<input type="checkbox" name="WARRANTYSVC_AUTO_WARRANTY_ON_ORDER_CLOSE" value="1"'.$chk_oc.'>';
print '</td></tr>';

// Warranty duration source
$duration_source = $current_duration_source;
print '<tr class="oddeven">';
print '<td>'.$langs->trans('WarrantyDurationSource').'<br><span class="opacitymedium">'
	.$langs->trans('WarrantyDurationSourceDesc').'</span></td>';
print '<td>';
print '<select name="WARRANTYSVC_DURATION_SOURCE" class="flat minwidth300">';
print '<option value="product_field"'.($duration_source === 'product_field' ? ' selected' : '').'>'.$langs->trans('WarrantyDurationSourceProductField').'</option>';
print '<option value="warranty_type"'.($duration_source === 'warranty_type' ? ' selected' : '').'>'.$langs->trans('WarrantyDurationSourceWarrantyType').'</option>';
print '</select>';
print '</td></tr>';

// Product integer extrafield used as the customer warranty duration in calendar months
if ($duration_source === 'product_field') {
	print '<tr class="oddeven">';
	print '<td>'.$langs->trans('ProductWarrantyMonthsField').'<br><span class="opacitymedium">'
		.$langs->trans('ProductWarrantyMonthsFieldDesc').'</span></td>';
	print '<td>';
	$current_month_field = getDolGlobalString('WARRANTYSVC_PRODUCT_WARRANTY_MONTHS_FIELD');
	print '<select name="WARRANTYSVC_PRODUCT_WARRANTY_MONTHS_FIELD" class="flat minwidth300">';
	print '<option value="">--- '.$langs->trans('ProductWarrantyMonthsFieldNone').' ---</option>';
	foreach ($product_month_fields as $field_name => $field_label) {
		$sel = ($current_month_field === $field_name) ? ' selected' : '';
		print '<option value="'.dol_escape_htmltag($field_name).'"'.$sel.'>'.dol_escape_htmltag($field_label).'</option>';
	}
	print '</select>';
	print '</td></tr>';
}

// Day-based coverage belongs to upstream Warranty Type mode.
if ($duration_source === 'warranty_type') {
	print '<tr class="oddeven">';
	print '<td>'.$langs->trans('DefaultCoverageDays').'<br><span class="opacitymedium">'
		.$langs->trans('DefaultCoverageDaysDesc').'</span></td>';
	print '<td>';
	print '<input type="number" name="WARRANTYSVC_DEFAULT_COVERAGE_DAYS" value="'.((int) getDolGlobalInt('WARRANTYSVC_DEFAULT_COVERAGE_DAYS', 365)).'" class="flat width75" min="1" max="3650">';
	print ' '.$langs->trans('SvcDays');
	print '</td></tr>';
}

// Notifications are managed by Dolibarr's standard Notification module.
print '<tr class="oddeven">';
print '<td>'.$langs->trans('WarrantySvcNotifications').'<br><span class="opacitymedium">'
	.$langs->trans('WarrantySvcNotificationsDesc').'</span></td>';
print '<td>';
if (isModEnabled('notification')) {
	print '<a class="button" href="'.DOL_URL_ROOT.'/admin/notification.php">'.$langs->trans('WarrantySvcConfigureNotifications').'</a>';
} else {
	print '<span class="warning">'.$langs->trans('WarrantySvcNotificationModuleDisabled').'</span>';
}
print '</td></tr>';

// Restrict service requests to serialized/lot-tracked products only
print '<tr class="oddeven">';
print '<td>'.$langs->trans('WarrantyRequiresLots').'<br><span class="opacitymedium">'
	.$langs->trans('WarrantyRequiresLotsDesc').'</span></td>';
print '<td>';
$chk4 = getDolGlobalString('WARRANTYSVC_WARRANTY_REQUIRES_LOTS') ? ' checked' : '';
print '<input type="checkbox" name="WARRANTYSVC_WARRANTY_REQUIRES_LOTS" value="1"'.$chk4.'>';
print '</td></tr>';

// Use Customer Returns module for inbound returns (optional integration)
$customerreturn_available = isModEnabled('customerreturn');
print '<tr class="oddeven">';
print '<td>'.$langs->trans('UseCustomerReturns').'<br><span class="opacitymedium">'
	.$langs->trans('UseCustomerReturnsDesc').'</span>';
if (!$customerreturn_available) {
	print '<br><span class="warning">'.$langs->trans('CustomerReturnModuleNotInstalled').'</span>';
}
print '</td>';
print '<td>';
$chk5 = getDolGlobalString('WARRANTYSVC_USE_CUSTOMERRETURN') ? ' checked' : '';
$disabled = $customerreturn_available ? '' : ' disabled';
print '<input type="checkbox" name="WARRANTYSVC_USE_CUSTOMERRETURN" value="1"'.$chk5.$disabled.'>';
print '</td></tr>';

// Debug mode
print '<tr class="oddeven">';
print '<td>'.$langs->trans('DebugMode').'<br><span class="opacitymedium">'
	.$langs->trans('DebugModeDesc').'</span></td>';
print '<td>';
$chk_debug = getDolGlobalString('WARRANTYSVC_DEBUG_MODE') ? ' checked' : '';
print '<input type="checkbox" name="WARRANTYSVC_DEBUG_MODE" value="1"'.$chk_debug.'>';
print '</td></tr>';

print '</table>';

print dol_get_fiche_end();

print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</div>';

print '</form>';

// Numbering model selector (native Dolibarr UI)
print '<br>';
print load_fiche_titre($langs->trans('NumberingModule'), '', '');

$setupsql = "SELECT count(*) as nb FROM ".MAIN_DB_PREFIX."svc_request";
$resql    = $db->query($setupsql);
if (!$resql) {
	// table may not exist yet — skip numbering model display until module activated
} else {
	require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/warrantysvc/modules_warrantysvc.php';

	$dir     = DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/warrantysvc/';
	$type    = 'warrantysvc';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Name').'</td>';
	print '<td>'.$langs->trans('Description').'</td>';
	print '<td class="center">'.$langs->trans('Status').'</td>';
	print '<td class="center">'.$langs->trans('Example').'</td>';
	print '</tr>';

	$handle = opendir($dir);
	if ($handle) {
		while (($file = readdir($handle)) !== false) {
			if (substr($file, 0, 4) != 'mod_' || substr($file, -4) != '.php') {
				continue;
			}
			require_once $dir.$file;
			$classname = substr($file, 0, -4);
			if (!class_exists($classname)) {
				continue;
			}
			$mod = new $classname();
			$current = getDolGlobalString('WARRANTYSVC_ADDON', 'mod_warrantysvc_standard');
			$active  = ($current == $classname);

			print '<tr class="oddeven"><td>'.$mod->name.'</td>';
			print '<td>'.$mod->info($langs).'</td>';
			print '<td class="center">';
			if ($active) {
				print img_picto($langs->trans('Activated'), 'switch_on');
			} else {
				print '<a href="'.$_SERVER['PHP_SELF'].'?action=setmod&token='.newToken().'&value='.$classname.'">';
				print img_picto($langs->trans('Disabled'), 'switch_off');
				print '</a>';
			}
			print '</td>';
			print '<td class="center"><code>'.$mod->getExample().'</code></td>';
			print '</tr>';
		}
		closedir($handle);
	}
	print '</table>';
}

// Handle setmod action
if ($action == 'setmod') {
	$value = GETPOST('value', 'alpha');
	dolibarr_set_const($db, 'WARRANTYSVC_ADDON', $value, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SvcSetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxFooter();
$db->close();
