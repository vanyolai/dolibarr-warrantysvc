<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    supplier_return_product.php
 * \ingroup warrantysvc
 * \brief   Supplier Returns referencing a Product
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierreturn.class.php';

$langs->loadLangs(array('warrantysvc@warrantysvc', 'products', 'stocks', 'companies'));

$productid = GETPOSTINT('productid');
$socid = GETPOSTINT('socid');

if (!$user->hasRight('warrantysvc', 'supplierreturn', 'read') || !empty($user->socid)) {
	accessforbidden();
}

$product = new Product($db);
if ($productid <= 0 || $product->fetch($productid) <= 0) {
	recordNotFound('', 0);
	exit;
}

restrictedArea(
	$user,
	$product->type == Product::TYPE_SERVICE ? 'service' : 'produit',
	$product->id,
	'product&product'
);

$title = $langs->trans('SupplierReturns').' - '.$product->ref;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-warrantysvc page-supplier-return-product');

$head = product_prepare_head($product);
$picto = ($product->type == Product::TYPE_SERVICE ? 'service' : 'product');
print dol_get_fiche_head($head, 'referers', $langs->trans('CardProduct'.$product->type), -1, $picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/product/list.php?restore_lastsearch_values=1&type='.$product->type.'">'.$langs->trans('BackToList').'</a>';
$product->next_prev_filter = "(te.fk_product_type:=:".((int) $product->type).")";
dol_banner_tab($product, 'ref', $linkback, 1, 'ref');

print dol_get_fiche_end();

print load_fiche_titre($langs->trans('SupplierReturnsForProduct'), '', 'shipment');

$sql = "SELECT r.rowid as return_id, r.ref, r.fk_soc_supplier, r.status,";
$sql .= " r.date_authorized, r.date_shipped, r.date_creation,";
$sql .= " l.rowid as line_id, l.qty, l.batch,";
$sql .= " l.fk_stock_movement_out, l.fk_stock_movement_reversal,";
$sql .= " s.nom as supplier_name";
$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return_line l";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."svc_supplier_return r ON r.rowid = l.fk_supplier_return";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = r.fk_soc_supplier";
$sql .= " WHERE l.fk_product = ".((int) $product->id);
$sql .= " AND r.entity = ".((int) $conf->entity);
if ($socid > 0) {
	$sql .= " AND r.fk_soc_supplier = ".((int) $socid);
}
$sql .= " ORDER BY COALESCE(r.date_shipped, r.date_authorized, r.date_creation) DESC, r.rowid DESC, l.rang ASC, l.rowid ASC";

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	llxFooter();
	$db->close();
	exit;
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Ref').'</td>';
print '<td>'.$langs->trans('Supplier').'</td>';
print '<td>'.$langs->trans('SerialOrLot').'</td>';
print '<td class="right">'.$langs->trans('Qty').'</td>';
print '<td class="center">'.$langs->trans('Date').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td>'.$langs->trans('StockMovement').'</td>';
print '</tr>';

$num = $db->num_rows($resql);
if ($num === 0) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('NoSupplierReturnsForProduct').'</span></td></tr>';
} else {
	$staticReturn = new SvcSupplierReturn($db);
	$staticSupplier = new Societe($db);

	while ($obj = $db->fetch_object($resql)) {
		$staticReturn->id = (int) $obj->return_id;
		$staticReturn->ref = (string) $obj->ref;
		$staticReturn->status = (string) $obj->status;

		$staticSupplier->id = (int) $obj->fk_soc_supplier;
		$staticSupplier->name = (string) $obj->supplier_name;

		$date = !empty($obj->date_shipped)
			? $db->jdate($obj->date_shipped)
			: (!empty($obj->date_authorized) ? $db->jdate($obj->date_authorized) : $db->jdate($obj->date_creation));

		$movementHtml = '—';
		if (!empty($obj->fk_stock_movement_out)) {
			$movementHtml = '<a href="'.DOL_URL_ROOT.'/product/stock/movement_list.php?msid='.((int) $obj->fk_stock_movement_out).'">#'.((int) $obj->fk_stock_movement_out).'</a>';
		}
		if (!empty($obj->fk_stock_movement_reversal)) {
			$movementHtml .= ' '.$langs->trans('SupplierReturnMovementReversedBy').' <a href="'.DOL_URL_ROOT.'/product/stock/movement_list.php?msid='.((int) $obj->fk_stock_movement_reversal).'">#'.((int) $obj->fk_stock_movement_reversal).'</a>';
		}

		print '<tr class="oddeven">';
		print '<td>'.$staticReturn->getNomUrl(1).'</td>';
		print '<td>'.$staticSupplier->getNomUrl(1, 'supplier').'</td>';
		print '<td>'.dol_escape_htmltag((string) $obj->batch ?: '—').'</td>';
		print '<td class="right">'.price((float) $obj->qty, 0, $langs, 0, 0, -1).'</td>';
		print '<td class="center">'.dol_print_date($date, 'dayhour').'</td>';
		print '<td class="center">'.$staticReturn->getLibStatut().'</td>';
		print '<td>'.$movementHtml.'</td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

$db->free($resql);
llxFooter();
$db->close();
