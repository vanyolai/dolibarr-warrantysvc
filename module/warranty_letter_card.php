<?php
/* Copyright (C) 2026 DPG Supply */
/** Official warranty-letter card, PDF revisions and Dolibarr-native email. */
$res=0;
if (!$res && file_exists('../main.inc.php')) $res=@include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res=@include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res=@include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantyletter.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
$langs->loadLangs(array('warrantysvc@warrantysvc','main','mails','orders','sendings','products'));

$id=GETPOSTINT('id');
$shipmentid=GETPOSTINT('shipmentid');
$action=GETPOST('action','aZ09');
$permread=$user->hasRight('warrantysvc','warrantyletter','read');
$permwrite=$user->hasRight('warrantysvc','warrantyletter','write');
if (!$permread || !empty($user->socid)) accessforbidden();
$hookmanager->initHooks(array('warrantysvcwarrantylettercard','globalcard'));
$letter=new SvcWarrantyLetter($db);
$shipment=null;
$needsCreation=false;

if ($id>0) {
    if ($letter->fetch($id)<=0) { recordNotFound('',0); exit; }
} elseif ($shipmentid>0) {
    $shipment=new Expedition($db);
    if ($shipment->fetch($shipmentid)<=0) { recordNotFound('',0); exit; }
    $found=SvcWarrantyLetter::findByShipment($db,$shipmentid);
    if ($found<0) { dol_print_error($db); exit; }
    if ($found>0) {
        header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.$found);
        exit;
    }
    $needsCreation=true;
} else {
    recordNotFound('',0); exit;
}

if ($needsCreation && $action==='create_letter' && $permwrite) {
    $db->begin();
    $ok=$letter->createFromShipment($shipment,$user);
    if ($ok>0) $ok=$letter->createRevision($user,$langs);
    if ($ok>0) {
        $db->commit();
        setEventMessages($langs->trans('WarrantyLetterCreated'),null,'mesgs');
        header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.$letter->id);
        exit;
    }
    $db->rollback();
    setEventMessages($langs->trans('WarrantyLetterError').': '.$letter->error,null,'errors');
}
if (!$needsCreation && $action==='new_revision' && $permwrite) {
    $db->begin();
    $ok=$letter->createRevision($user,$langs);
    if ($ok>0) {
        $db->commit();
        setEventMessages($langs->trans('WarrantyLetterRevisionCreated'),null,'mesgs');
        header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.$letter->id);
        exit;
    }
    $db->rollback();
    setEventMessages($langs->trans('WarrantyLetterError').': '.$letter->error,null,'errors');
}

$canSend=false;
if (!$needsCreation) {
    $letter->fetch_thirdparty();
    $object=$letter;
    $id=(int)$object->id;
    $trackid='wsvcl'.$id.'v'.$object->current_version;
    $hidedetails=0; $hidedesc=0; $hideref=0;
    $revision=$object->getVersion();
    $verified=$object->verifyVersion($revision);
    $stale=$verified && !$object->isSnapshotCurrent($revision);
    $canSend=$permwrite && $revision && $verified && !$stale;

    // Never allow native CMailFile to send without the exact immutable PDF.
    if (in_array($action,array('send','relance'),true)) {
        if (!$canSend) {
            setEventMessages($langs->trans($stale ? 'WarrantyLetterStaleWarning' : 'WarrantyLetterPdfHashMismatch'),null,'errors');
            $action='';
        } else {
            require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
            $mailcheck=new FormMail($db);
            $mailcheck->trackid=$trackid;
            $attached=$mailcheck->get_attached_files();
            $expected=realpath($object->versionFullPath($revision));
            $exists=false;
            foreach ((array)($attached['paths']??array()) as $path) {
                if (realpath((string)$path)===$expected) { $exists=true; break; }
            }
            if (!$exists) {
                setEventMessages($langs->trans('WarrantyLetterPdfAttachmentMissing'),null,'errors');
                $action='presend';
            }
        }
    }
    if ($canSend) {
        $triggersendname='SVCWARRANTYLETTER_SENTBYMAIL';
        $sendcontext='warrantysvc_warranty_letter';
        $autocopy='';
        $paramname='id';
        include DOL_DOCUMENT_ROOT.'/core/actions_sendmails.inc.php';
    }
}

llxHeader('',$langs->trans('WarrantyLetterTitle'));
$form=new Form($db);
if ($needsCreation) {
    print load_fiche_titre($langs->trans('WarrantyLetterTitle'),'','pdf');
    print '<p>'.dol_escape_htmltag($shipment->ref).'</p>';
    if (warrantysvc_count_shipment_warranties($db,$shipmentid)<=0) {
        print '<div class="warning">'.$langs->trans('WarrantyLetterNoWarranties').'</div>';
    } elseif ($permwrite) {
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="shipmentid" value="'.((int)$shipmentid).'">';
        print '<input type="hidden" name="action" value="create_letter">';
        print '<button class="button" type="submit">'.$langs->trans('WarrantyLetterCreate').'</button></form>';
    }
    llxFooter(); $db->close(); exit;
}
$shipmentUrl=DOL_URL_ROOT.'/expedition/card.php?id='.((int)$object->fk_expedition);
print load_fiche_titre($langs->trans('WarrantyLetterTitle').' '.$object->ref,'<a href="'.$shipmentUrl.'">'.$langs->trans('BackToList').'</a>','pdf');
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>'.dol_escape_htmltag($object->ref).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(1).'</td></tr>';
print '<tr><td>'.$langs->trans('Customer').'</td><td>'.(is_object($object->thirdparty)?$object->thirdparty->getNomUrl(1):'').'</td></tr>';
print '<tr><td>'.$langs->trans('ShipmentRef').'</td><td><a href="'.$shipmentUrl.'">#'.((int)$object->fk_expedition).'</a></td></tr>';
if ($object->fk_commande>0) print '<tr><td>'.$langs->trans('Order').'</td><td><a href="'.DOL_URL_ROOT.'/commande/card.php?id='.((int)$object->fk_commande).'">#'.((int)$object->fk_commande).'</a></td></tr>';
print '<tr><td>'.$langs->trans('WarrantyLetterVersion').'</td><td>'.((int)$object->current_version).'</td></tr>';
print '<tr><td>'.$langs->trans('WarrantyLetterLastSentVersion').'</td><td>'.((int)$object->last_sent_version).'</td></tr>';
print '</table>';

if ($stale) print '<div class="warning">'.$langs->trans('WarrantyLetterStaleWarning').'</div>';
if ($permwrite) {
    print '<div class="tabsAction">';
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int)$object->id).'" style="display:inline-block">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="id" value="'.((int)$object->id).'">';
    print '<input type="hidden" name="action" value="new_revision">';
    print '<button class="button" type="submit" title="'.dol_escape_htmltag($langs->trans('WarrantyLetterNewRevisionWarning')).'">'.$langs->trans('WarrantyLetterNewRevision').'</button></form>';
    if ($verified) print ' <a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.((int)$object->id).'&action=presend#formmailbeforetitle">'.$langs->trans('WarrantyLetterSend').'</a>';
    print '</div>';
}
print load_fiche_titre($langs->trans('WarrantyLetterVersions'),'','pdf');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('WarrantyLetterVersion').'</th><th>'.$langs->trans('DateCreation').'</th><th>'.$langs->trans('Document').'</th><th>'.$langs->trans('Status').'</th></tr>';
foreach ((array)$object->getVersions() as $v) {
    $url=DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_download.php?id='.((int)$object->id).'&v='.((int)$v->version);
    $ok=$object->verifyVersion($v);
    print '<tr class="oddeven"><td>v'.((int)$v->version).'</td>';
    print '<td>'.dol_print_date($db->jdate($v->date_creation),'dayhour').'</td>';
    print '<td>'.($ok?'<a href="'.$url.'" target="_blank" rel="noopener">'.dol_escape_htmltag(basename($v->file_path)).'</a>':dol_escape_htmltag(basename($v->file_path))).'</td>';
    print '<td>'.($ok?$langs->trans('Available'):$langs->trans('WarrantyLetterPdfHashMismatch')).'</td></tr>';
}
print '</table></div>';

print load_fiche_titre($langs->trans('WarrantyLetterHistory'),'','email');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('WarrantyLetterDateSent').'</th><th>'.$langs->trans('WarrantyLetterVersion').'</th><th>'.$langs->trans('WarrantyLetterRecipient').'</th><th>'.$langs->trans('WarrantyLetterSubject').'</th></tr>';
foreach ($object->getMailHistory() as $entry) {
    print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($entry->date_sent),'dayhour').'</td><td>v'.((int)$entry->version).'</td>';
    print '<td>'.dol_escape_htmltag($entry->recipient).'</td><td>'.dol_escape_htmltag($entry->subject).'</td></tr>';
}
print '</table></div>';

if (GETPOST('modelselected') && $canSend) $action='presend';
if ($action==='presend' && $canSend) {
    $modelmail='svcwarrantyletter';
    $defaulttopic='WarrantyLetterEmailSubject';
    $defaulttopiclang='warrantysvc@warrantysvc';
    $diroutput=$conf->warrantysvc->dir_output;
    include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
}
llxFooter();
$db->close();
