<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    ajax/customer_warranties.php
 * \ingroup warrantysvc
 * \brief   Returns non-voided warranty records for one customer.
 *
 * The Service Request create form uses this endpoint as the authoritative
 * warranty/device picker. Effective Active/Expired state is calculated by the
 * browser against the selected Issue Date so historical claims are represented
 * correctly without another round trip.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) {
	http_response_code(500);
	exit;
}

if (!$user->id || !$user->hasRight('warrantysvc', 'svcrequest', 'read')) {
	http_response_code(403);
	exit;
}

$socid = (int) GETPOST('socid', 'int');
if ($socid <= 0) {
	header('Content-Type: application/json; charset=utf-8');
	print '[]';
	exit;
}

$sql  = "SELECT w.rowid, w.ref, w.fk_product, w.serial_number, w.covered_qty,";
$sql .= " w.start_date, w.expiry_date, w.coverage_months,";
$sql .= " p.ref AS product_ref, p.label AS product_label";
$sql .= " FROM ".MAIN_DB_PREFIX."svc_warranty w";
$sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = w.fk_product";
$sql .= " WHERE w.fk_soc = ".$socid;
$sql .= " AND w.status != 'voided'";
$sql .= " AND w.entity IN (".getEntity('svcwarranty').")";
$sql .= " AND p.entity IN (".getEntity('product').")";
if (getDolGlobalString('WARRANTYSVC_WARRANTY_REQUIRES_LOTS')) {
	$sql .= " AND p.tobatch > 0";
}
$sql .= " ORDER BY p.ref ASC, w.serial_number ASC, w.ref ASC";

$resql = $db->query($sql);
if (!$resql) {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	print json_encode(array('error' => $db->lasterror()));
	exit;
}

$rows = array();
while ($obj = $db->fetch_object($resql)) {
	$startTs = !empty($obj->start_date) ? $db->jdate($obj->start_date) : null;
	$expiryTs = !empty($obj->expiry_date) ? $db->jdate($obj->expiry_date) : null;
	$rows[] = array(
		'rowid' => (int) $obj->rowid,
		'ref' => (string) $obj->ref,
		'fk_product' => (int) $obj->fk_product,
		'product_ref' => (string) $obj->product_ref,
		'product_label' => (string) $obj->product_label,
		'serial_number' => !empty($obj->serial_number) ? (string) $obj->serial_number : '',
		'covered_qty' => (float) $obj->covered_qty,
		'start_date' => $startTs ? dol_print_date($startTs, '%Y-%m-%d', 'tzserver') : '',
		'start_label' => $startTs ? dol_print_date($startTs, 'day') : '',
		'expiry_date' => $expiryTs ? dol_print_date($expiryTs, '%Y-%m-%d', 'tzserver') : '',
		'expiry_label' => $expiryTs ? dol_print_date($expiryTs, 'day') : '',
		'coverage_months' => !empty($obj->coverage_months) ? (int) $obj->coverage_months : 0,
	);
}

header('Content-Type: application/json; charset=utf-8');
print json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
