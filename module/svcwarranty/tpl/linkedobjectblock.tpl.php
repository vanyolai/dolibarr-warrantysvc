<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    svcwarranty/tpl/linkedobjectblock.tpl.php
 * \ingroup warrantysvc
 * \brief   Render Warranty rows in Dolibarr's Related Objects panel.
 */

if (empty($conf) || !is_object($conf)) {
	print "Error, template page can't be called as URL";
	exit(1);
}

print "<!-- BEGIN PHP TEMPLATE warrantysvc/svcwarranty/tpl/linkedobjectblock.tpl.php -->\n";

$langs->loadLangs(array('warrantysvc@warrantysvc', 'products'));

$ilink = 0;
$productCache = array();

foreach ($linkedObjectBlock as $key => $objectlink) {
	$ilink++;

	$productRef = '';
	$productLabel = '';
	$productId = (int) $objectlink->fk_product;

	if ($productId > 0) {
		if (!isset($productCache[$productId])) {
			$sql = 'SELECT ref, label FROM '.MAIN_DB_PREFIX.'product WHERE rowid = '.$productId;
			$res = $db->query($sql);
			if ($res && ($productRow = $db->fetch_object($res))) {
				$productCache[$productId] = array(
					'ref' => (string) $productRow->ref,
					'label' => (string) $productRow->label,
				);
			} else {
				$productCache[$productId] = array('ref'=>'', 'label'=>'');
			}
			if ($res) $db->free($res);
		}

		$productRef = $productCache[$productId]['ref'];
		$productLabel = $productCache[$productId]['label'];
	}

	$productText = trim($productRef.($productLabel !== '' ? ' - '.$productLabel : ''));
	$serialText = trim((string) $objectlink->serial_number);

	$trclass = 'oddeven';
	if ($ilink == count($linkedObjectBlock) && empty($noMoreLinkedObjectBlockAfter) && count($linkedObjectBlock) <= 1) {
		$trclass .= ' liste_sub_total';
	}

	print '<tr class="'.$trclass.'">';
	print '<td class="linkedcol-element tdoverflowmax100">'.$langs->trans("Warranty").'</td>';
	print '<td class="linkedcol-name tdoverflowmax150">'.$objectlink->getNomUrl(1).'</td>';

	print '<td class="linkedcol-ref tdoverflowmax300">';
	if ($productText !== '') {
		if ($productId > 0) {
			print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.$productId.'">'.dol_escape_htmltag($productText).'</a>';
		} else {
			print dol_escape_htmltag($productText);
		}
	}
	if ($serialText !== '') {
		if ($productText !== '') print '<br>';
		print '<span class="opacitymedium">'.$langs->trans('SerialNumber').': '.dol_escape_htmltag($serialText).'</span>';
	}
	print '</td>';

	// Date and amount are not useful in this shipment context.
	print '<td class="linkedcol-date center"></td>';
	print '<td class="linkedcol-amount right"></td>';
	print '<td class="linkedcol-statut right">';
	if (function_exists('svcwarranty_status_badge')) {
		print svcwarranty_status_badge($objectlink->status);
	} else {
		print $objectlink->getLibStatut(3);
	}
	print '</td>';

	print '<td class="linkedcol-action right"><a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=dellink&token='.newToken().'&dellinkid='.$key.'">'.img_picto($langs->transnoentitiesnoconv("RemoveLink"), 'unlink').'</a></td>';
	print "</tr>\n";
}

print "<!-- END PHP TEMPLATE -->\n";
