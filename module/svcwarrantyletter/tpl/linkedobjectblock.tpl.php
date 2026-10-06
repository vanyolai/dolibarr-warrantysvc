<?php
/* Copyright (C) 2026 DPG Supply */
/** Render native linked warranty-letter rows. */
if (empty($conf) || !is_object($conf)) { print 'Access denied'; exit(1); }
$langs->load('warrantysvc@warrantysvc');
$count=count($linkedObjectBlock); $i=0;
foreach ($linkedObjectBlock as $key=>$objectlink) {
    $i++;
    $css='oddeven';
    if ($i===$count && empty($noMoreLinkedObjectBlockAfter) && $count<=1) $css.=' liste_sub_total';
    print '<tr class="'.$css.'">';
    print '<td class="linkedcol-element tdoverflowmax100">'.$langs->trans('WarrantyLetter').'</td>';
    print '<td class="linkedcol-name tdoverflowmax150">'.$objectlink->getNomUrl(1).'</td>';
    print '<td class="linkedcol-ref tdoverflowmax150">'.dol_escape_htmltag($objectlink->ref).'</td>';
    print '<td class="linkedcol-date center">'.dol_print_date($objectlink->date_creation,'day').'</td>';
    print '<td class="linkedcol-amount right"></td>';
    print '<td class="linkedcol-statut right">'.$objectlink->getLibStatut(1).'</td>';
    print '<td class="linkedcol-action right"><a class="reposition" href="'.$_SERVER['PHP_SELF'].'?id='.((int)$object->id).'&action=dellink&token='.newToken().'&dellinkid='.((int)$key).'">'.img_picto($langs->transnoentitiesnoconv('RemoveLink'),'unlink').'</a></td>';
    print '</tr>';
}
