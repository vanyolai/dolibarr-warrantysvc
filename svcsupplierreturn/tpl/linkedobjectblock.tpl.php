<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    svcsupplierreturn/tpl/linkedobjectblock.tpl.php
 * \ingroup warrantysvc
 * \brief   Render Supplier Return rows in Dolibarr linked-object blocks
 */

if (empty($conf) || !is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}

$langs->load('warrantysvc@warrantysvc');

print "<!-- BEGIN PHP TEMPLATE warrantysvc/svcsupplierreturn/tpl/linkedobjectblock.tpl.php -->\n";

$ilink = 0;
foreach ($linkedObjectBlock as $key => $objectlink) {
	$ilink++;

	$trclass = 'oddeven';
	if ($ilink == count($linkedObjectBlock) && empty($noMoreLinkedObjectBlockAfter) && count($linkedObjectBlock) <= 1) {
		$trclass .= ' liste_sub_total';
	}

	$date = !empty($objectlink->date_shipped)
		? $objectlink->date_shipped
		: (!empty($objectlink->date_authorized) ? $objectlink->date_authorized : $objectlink->date_creation);

	print '<tr class="'.$trclass.'">';
	print '<td class="linkedcol-element tdoverflowmax100">'.$langs->trans('SupplierReturn').'</td>';
	print '<td class="linkedcol-name tdoverflowmax150">'.$objectlink->getNomUrl(1).'</td>';
	print '<td class="linkedcol-ref tdoverflowmax150">'.dol_escape_htmltag((string) $objectlink->supplier_return_ref).'</td>';
	print '<td class="linkedcol-date center">'.dol_print_date($date, 'day').'</td>';
	print '<td class="linkedcol-amount right"></td>';
	print '<td class="linkedcol-statut right">'.$objectlink->getLibStatut().'</td>';
	print '<td class="linkedcol-action right"><a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=dellink&token='.newToken().'&dellinkid='.$key.'">'.img_picto($langs->transnoentitiesnoconv('RemoveLink'), 'unlink').'</a></td>';
	print "</tr>\n";
}

print "<!-- END PHP TEMPLATE -->\n";
