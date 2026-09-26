<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    ajax/warranty_products.php
 * \ingroup warrantysvc
 * \brief   Returns JSON list of products with unassigned serials that were
 *          shipped to a specific customer. Used to populate the Standard mode
 *          product select on the New Warranty form.
 *
 * GET params:
 *   socid  (int, required) — customer (fk_soc) to scope results to
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) { http_response_code(500); exit; }

if (!$user->id || !$user->hasRight('warrantysvc', 'svcwarranty', 'read')) {
	http_response_code(403);
	exit;
}

$socid = (int) GETPOST('socid', 'int');
if ($socid <= 0) {
	header('Content-Type: application/json');
	print '[]';
	exit;
}

// Products shipped to this customer that still have at least one unassigned serial.
// Fetch candidate shipment serials and existing warranty serials separately so the
// module never compares text columns with potentially different database collations.
$sql  = "SELECT DISTINCT p.rowid, p.ref, p.label, edb.batch AS serial_number";
$sql .= " FROM ".MAIN_DB_PREFIX."product p";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."expeditiondet ed ON ed.fk_product = p.rowid";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."expedition e ON e.rowid = ed.fk_expedition";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."expeditiondet_batch edb ON edb.fk_expeditiondet = ed.rowid";
$sql .= " WHERE e.fk_soc = ".((int) $socid);
$sql .= " AND e.fk_statut >= 1";
$sql .= " AND e.entity IN (".getEntity('expedition').")";
$sql .= " AND p.entity IN (".getEntity('product').")";
$sql .= " AND edb.batch IS NOT NULL AND edb.batch != ''";
$sql .= " ORDER BY p.ref ASC, edb.batch ASC";

$resql = $db->query($sql);
if (!$resql) {
	http_response_code(500);
	header('Content-Type: application/json');
	print json_encode(array('error' => $db->lasterror()));
	exit;
}

$covered = array();
$sqlw  = "SELECT fk_product, serial_number FROM ".MAIN_DB_PREFIX."svc_warranty";
$sqlw .= " WHERE status != 'voided'";
$sqlw .= " AND serial_number IS NOT NULL AND serial_number != ''";
$sqlw .= " AND entity IN (".getEntity('svcwarranty').")";
$resw = $db->query($sqlw);
if (!$resw) {
	http_response_code(500);
	header('Content-Type: application/json');
	print json_encode(array('error' => $db->lasterror()));
	exit;
}
while ($ow = $db->fetch_object($resw)) {
	$covered[((int) $ow->fk_product).'\0'.(string) $ow->serial_number] = true;
}

$products_by_id = array();
while ($obj = $db->fetch_object($resql)) {
	$key = ((int) $obj->rowid).'\0'.(string) $obj->serial_number;
	if (isset($covered[$key])) {
		continue;
	}
	$products_by_id[(int) $obj->rowid] = array(
		'rowid' => (int) $obj->rowid,
		'label' => $obj->ref.($obj->label ? ' — '.$obj->label : ''),
	);
}

$products = array_values($products_by_id);
header('Content-Type: application/json');
print json_encode($products);
