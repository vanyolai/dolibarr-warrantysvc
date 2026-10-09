<?php
/* Copyright (C) 2026 DPG Supply */
/** Multi-shipment warranty-letter card, immutable PDF revisions and native Dolibarr email. */
$res=0;
if (!$res && file_exists('../main.inc.php')) $res=@include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res=@include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res=@include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantyletter.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formactions.class.php';
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';

$langs->loadLangs(array('warrantysvc@warrantysvc','main','mails','orders','sendings','products','companies'));

$id=GETPOSTINT('id');
$shipmentid=GETPOSTINT('shipmentid');
$action=GETPOST('action','aZ09');
$permread=$user->hasRight('warrantysvc','warrantyletter','read');
$permwrite=$user->hasRight('warrantysvc','warrantyletter','write');
if (!$permread || !empty($user->socid)) accessforbidden();

$getIntArray=static function($name) {
    $raw=isset($_POST[$name]) ? (array) $_POST[$name] : array();
    $out=array();
    foreach ($raw as $value) {
        $id=(int) $value;
        if ($id>0) $out[$id]=$id;
    }
    return array_values($out);
};

$hookmanager->initHooks(array('warrantysvcwarrantylettercard','globalcard'));
$letter=new SvcWarrantyLetter($db);
$shipment=null;
$needsCreation=false;

if ($id>0) {
    if ($letter->fetch($id)<=0) { recordNotFound('',0); exit; }
} elseif ($action==='create_letter' && $permwrite && $_SERVER['REQUEST_METHOD']==='POST') {
    $shipmentIds=$getIntArray('shipmentids');
    if (!$shipmentIds && $shipmentid>0) $shipmentIds=array($shipmentid);
    $db->begin();
    $ok=$letter->createForShipments($shipmentIds,$user);
    if ($ok>0) $ok=$letter->createRevision($user,$langs);
    if ($ok>0) {
        $db->commit();
        setEventMessages($langs->trans('WarrantyLetterCreated'),null,'mesgs');
        header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.$letter->id);
        exit;
    }
    $db->rollback();
    setEventMessages($langs->trans('WarrantyLetterError').': '.$langs->trans($letter->error),null,'errors');
    header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_shipment_list.php');
    exit;
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
    recordNotFound('',0);
    exit;
}

if (!$needsCreation && $action==='add_shipments' && $permwrite && $_SERVER['REQUEST_METHOD']==='POST') {
    $shipmentIds=$getIntArray('add_shipmentids');
    $db->begin();
    $ok=$letter->addShipments($shipmentIds,$user);
    if ($ok>0) {
        $db->commit();
        setEventMessages($langs->trans('WarrantyLetterShipmentsAdded'),null,'mesgs');
    } else {
        $db->rollback();
        setEventMessages($langs->trans('WarrantyLetterError').': '.$langs->trans($letter->error),null,'errors');
    }
    header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.$letter->id);
    exit;
}

if (!$needsCreation && $action==='delete_revision' && $permwrite) {
    $fileToDelete=GETPOST('file','restricthtml');
    $revisionNumber=0;
    if (preg_match('/_v([0-9]+)\.pdf$/', basename((string) $fileToDelete), $match)) {
        $revisionNumber=(int) $match[1];
    }

    if ($revisionNumber<=0) {
        setEventMessages($langs->trans('WarrantyLetterVersionNotFound'),null,'errors');
    } else {
        $db->begin();
        $ok=$letter->deleteVersion($revisionNumber,$user);
        if ($ok>0) {
            $db->commit();
            setEventMessages($langs->trans('WarrantyLetterVersionDeleted'),null,'mesgs');
        } else {
            $db->rollback();
            setEventMessages($langs->trans($letter->error),null,'errors');
        }
    }
    header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.$letter->id);
    exit;
}

if (!$needsCreation && $action==='new_revision' && $permwrite && $_SERVER['REQUEST_METHOD']==='POST') {
    $db->begin();
    $ok=$letter->createRevision($user,$langs);
    if ($ok>0) {
        $db->commit();
        setEventMessages($langs->trans('WarrantyLetterRevisionCreated'),null,'mesgs');
        header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.$letter->id);
        exit;
    }
    $db->rollback();
    setEventMessages($langs->trans('WarrantyLetterError').': '.$langs->trans($letter->error),null,'errors');
}

$canSend=false;
$revision=null;
$verified=false;
$stale=false;

// Native FormMail cancel posts action=send together with cancel=Cancel.
// Cancel must leave the mail composer before any send-only validation runs.
if (!$needsCreation && $_SERVER['REQUEST_METHOD']==='POST' && GETPOST('cancel', 'alpha') !== '') {
    header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.((int) $letter->id));
    exit;
}

if (!$needsCreation) {
    $letter->fetch_thirdparty();
    $object=$letter;
    $id=(int)$object->id;
    $trackid='wsvcl'.$id.'v'.$object->current_version;
    $hidedetails=0;
    $hidedesc=0;
    $hideref=0;
    $revision=$object->getVersion();
    $verified=$object->verifyVersion($revision);
    $stale=$verified && !$object->isSnapshotCurrent($revision);
    $canSend=$permwrite && $revision && $verified && !$stale;

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
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('ShipmentRef').'</td><td>'.dol_escape_htmltag($shipment->ref).'</td></tr>';
    print '</table>';

    if (warrantysvc_count_shipment_warranties($db,$shipmentid)<=0) {
        print '<div class="warning">'.$langs->trans('WarrantyLetterNoWarranties').'</div>';
    } elseif ($permwrite) {
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" id="warrantyLetterCreateForm" class="hidden">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="shipmentids[]" value="'.((int)$shipmentid).'">';
        print '<input type="hidden" name="action" value="create_letter">';
        print '</form>';
        print '<div class="tabsAction">';
        print dolGetButtonAction(
			'',
			$langs->trans('WarrantyLetterCreate'),
			'default',
			'',
			'',
			1,
			array('attr'=>array(
				'onclick'=>"document.getElementById('warrantyLetterCreateForm').submit();",
				'role'=>'button',
				'tabindex'=>'0'
			))
		);
        print dolGetButtonAction('', $langs->trans('WarrantyLetterCombineShipments'), 'default', DOL_URL_ROOT.'/custom/warrantysvc/warranty_shipment_list.php?socid='.((int)$shipment->socid));
        print '</div>';
    }
    llxFooter();
    $db->close();
    exit;
}

$shipments=$object->getShipments();
if ($shipments===null) {
    dol_print_error($db,$object->error);
    $shipments=array();
}

print load_fiche_titre(
    $langs->trans('WarrantyLetterTitle').' '.$object->ref,
    '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_shipment_list.php">'.$langs->trans('WarrantyShipments').'</a>',
    'pdf'
);

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>'.dol_escape_htmltag($object->ref).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(1).'</td></tr>';
print '<tr><td>'.$langs->trans('Customer').'</td><td>'.(is_object($object->thirdparty)?$object->thirdparty->getNomUrl(1):'').'</td></tr>';
print '<tr><td>'.$langs->trans('WarrantyLetterShipmentCount').'</td><td>'.count($shipments).'</td></tr>';
print '<tr><td>'.$langs->trans('WarrantyLetterVersion').'</td><td>'.((int)$object->current_version).'</td></tr>';
print '<tr><td>'.$langs->trans('WarrantyLetterLastSentVersion').'</td><td>'.((int)$object->last_sent_version).'</td></tr>';
print '</table>';

if ($stale) print '<div class="warning">'.$langs->trans('WarrantyLetterStaleWarning').'</div>';

// Current live business content. This uses the very same grouping logic that
// will be frozen into the next immutable PDF revision.
$liveSnapshot=$object->buildSnapshot();
if (is_array($liveSnapshot) && !empty($liveSnapshot['shipments'])) {
    print load_fiche_titre($langs->trans('WarrantyLetterContents'),'','product');
    foreach ($liveSnapshot['shipments'] as $contentShipment) {
        print '<div class="div-table-responsive">';
        print '<table class="noborder centpercent marginbottomonly">';
        print '<tr class="liste_titre">';
        print '<th colspan="5">';
        print $langs->trans('ShipmentRef').': ';
        print '<a href="'.DOL_URL_ROOT.'/expedition/card.php?id='.((int)$contentShipment['shipment_id']).'">'.dol_escape_htmltag((string)$contentShipment['shipment_ref']).'</a>';
        if (!empty($contentShipment['shipment_date'])) {
            print ' &mdash; '.$langs->trans('Date').': '.dol_print_date($db->jdate((string)$contentShipment['shipment_date']),'day');
        }
        if (!empty($contentShipment['order_refs'])) {
            $orderLinks=array();
            $sqlOrders='SELECT DISTINCT c.rowid, c.ref FROM '.MAIN_DB_PREFIX.'svc_warranty w';
            $sqlOrders.=' JOIN '.MAIN_DB_PREFIX.'commande c ON c.rowid=w.fk_commande';
            $sqlOrders.=' WHERE w.entity='.((int)$conf->entity);
            $sqlOrders.=' AND w.fk_expedition='.((int)$contentShipment['shipment_id']);
            $sqlOrders.=' AND w.fk_commande IS NOT NULL AND w.fk_commande>0';
            $sqlOrders.=' ORDER BY c.ref';
            $resOrders=$db->query($sqlOrders);
            if ($resOrders) {
                while ($orderRow=$db->fetch_object($resOrders)) {
                    $orderLinks[]='<a href="'.DOL_URL_ROOT.'/commande/card.php?id='.((int)$orderRow->rowid).'">'.dol_escape_htmltag((string)$orderRow->ref).'</a>';
                }
                $db->free($resOrders);
            }

            print ' &mdash; '.$langs->trans('Order').': ';
            if ($orderLinks) {
                print implode(', ', $orderLinks);
            } else {
                print dol_escape_htmltag(implode(', ', (array)$contentShipment['order_refs']));
            }
        }
        print '</th>';
        print '</tr>';
        print '<tr class="liste_titre">';
        print '<th>'.$langs->trans('Product').'</th>';
        print '<th class="center">'.$langs->trans('Qty').'</th>';
        print '<th>'.$langs->trans('WarrantyLetterSerials').'</th>';
        print '<th>'.$langs->trans('WarrantyLetterStartDate').'</th>';
        print '<th>'.$langs->trans('WarrantyLetterEndDate').'</th>';
        print '</tr>';

        foreach ((array)$contentShipment['groups'] as $group) {
            $qty=(float)$group['qty'];
            $qtyDisplay=((float)(int)$qty===$qty) ? (string)(int)$qty : price($qty,0,'',0,0,2);

            print '<tr class="oddeven">';
            print '<td>';
            if (!empty($group['product_id'])) {
                print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int)$group['product_id']).'">'.dol_escape_htmltag((string)$group['product_ref']).'</a>';
            } else {
                print dol_escape_htmltag((string)$group['product_ref']);
            }
            if (!empty($group['product_label'])) {
                print ' - '.dol_escape_htmltag((string)$group['product_label']);
            }
            print '</td>';
            print '<td class="center">'.$qtyDisplay.'</td>';
            print '<td>'.(!empty($group['serials']) ? dol_escape_htmltag(implode(', ', (array)$group['serials'])) : '<span class="opacitymedium">&mdash;</span>').'</td>';
            print '<td>'.(!empty($group['start_date']) ? dol_print_date($db->jdate((string)$group['start_date']),'day') : '').'</td>';
            print '<td>'.(!empty($group['expiry_date']) ? dol_print_date($db->jdate((string)$group['expiry_date']),'day') : '').'</td>';
            print '</tr>';
        }
        print '</table>';
        print '</div>';
    }
} else {
    print '<div class="warning">'.$langs->trans('WarrantyLetterNoWarranties').'</div>';
}

if ($permwrite) {
    $available=SvcWarrantyLetter::getAvailableShipmentsForCustomer($db,(int)$object->fk_soc,0);
    if (is_array($available) && count($available)>0) {
        print load_fiche_titre($langs->trans('WarrantyLetterAddShipments'),'','shipment');
        print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int)$object->id).'" id="warrantyLetterAddShipmentsForm">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="id" value="'.((int)$object->id).'">';
        print '<input type="hidden" name="action" value="add_shipments">';
        print '<div class="div-table-responsive"><table class="noborder centpercent">';
        print '<tr class="liste_titre"><th class="center"></th><th>'.$langs->trans('ShipmentRef').'</th><th>'.$langs->trans('Date').'</th><th class="center">'.$langs->trans('WarrantyRecords').'</th><th class="right">'.$langs->trans('CoveredQuantity').'</th></tr>';
        foreach ($available as $s) {
            print '<tr class="oddeven">';
            print '<td class="center"><input type="checkbox" name="add_shipmentids[]" value="'.((int)$s->fk_expedition).'"></td>';
            print '<td><a href="'.DOL_URL_ROOT.'/expedition/card.php?id='.((int)$s->fk_expedition).'">'.dol_escape_htmltag($s->ref).'</a></td>';
            print '<td>'.(!empty($s->date_expedition)?dol_print_date($db->jdate($s->date_expedition),'day'):'').'</td>';
            print '<td class="center">'.((int)$s->warranty_count).'</td>';
            print '<td class="right">'.price((float)$s->covered_qty,0,'',0,0,2).'</td>';
            print '</tr>';
        }
        print '</table></div>';
        print '</form>';
        print '<div class="tabsAction">';
        print dolGetButtonAction(
			'',
			$langs->trans('WarrantyLetterAddSelectedShipments'),
			'default',
			'',
			'',
			1,
			array('attr'=>array(
				'onclick'=>"document.getElementById('warrantyLetterAddShipmentsForm').submit();",
				'role'=>'button',
				'tabindex'=>'0'
			))
		);
        print '</div>';
    }

    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int)$object->id).'" id="warrantyLetterRevisionForm" class="hidden">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="id" value="'.((int)$object->id).'">';
    print '<input type="hidden" name="action" value="new_revision">';
    print '</form>';
    print '<div class="tabsAction">';
    print dolGetButtonAction(
        $langs->trans('WarrantyLetterNewRevisionWarning'),
        $langs->trans('WarrantyLetterNewRevision'),
        'default',
        '',
        '',
        1,
        array('attr'=>array(
            'onclick'=>"document.getElementById('warrantyLetterRevisionForm').submit();",
            'role'=>'button',
            'tabindex'=>'0'
        ))
    );
    if ($verified) {
        $sendHref=$canSend?$_SERVER['PHP_SELF'].'?id='.((int)$object->id).'&action=presend#formmailbeforetitle':'#';
        print dolGetButtonAction(
            $canSend ? '' : $langs->trans('WarrantyLetterStaleWarning'),
            $langs->trans('WarrantyLetterSend'),
            'email',
            $sendHref,
            '',
            $canSend
        );
    }
    print '</div>';
}

if (GETPOST('modelselected') && $canSend) $action='presend';

if ($action !== 'presend') {
    $versions=$object->getVersions();
    $hasInvalidVersion=false;
    foreach ((array)$versions as $versionRow) {
        if (!$object->verifyVersion($versionRow)) {
            $hasInvalidVersion=true;
            break;
        }
    }
    if ($hasInvalidVersion) {
        print '<div class="warning">'.$langs->trans('WarrantyLetterPdfHashMismatch').'</div>';
    }

    print '<div class="fichecenter"><div class="fichehalfleft">';

    $formfile=new FormFile($db);
    $letterSubdir='letters/'.dol_sanitizeFileName($object->ref);
    $letterDir=rtrim($conf->warrantysvc->dir_output,'/').'/'.$letterSubdir;
    $documentUrlWasSet=isset($conf->global->DOL_URL_ROOT_DOCUMENT_PHP);
    $previousDocumentUrl=$documentUrlWasSet ? $conf->global->DOL_URL_ROOT_DOCUMENT_PHP : null;
    $conf->global->DOL_URL_ROOT_DOCUMENT_PHP=DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_download.php';

    print $formfile->showdocuments(
        'warrantysvc',
        $letterSubdir,
        $letterDir,
        $_SERVER['PHP_SELF'].'?id='.((int)$object->id),
        0,
        $permwrite ? 1 : 0,
        '',
        1,
        1,
        0,
        28,
        0,
        'id='.((int)$object->id),
        $langs->trans('LinkedFiles'),
        '',
        '',
        '',
        $object,
        0,
        'delete_revision'
    );

    if ($documentUrlWasSet) {
        $conf->global->DOL_URL_ROOT_DOCUMENT_PHP=$previousDocumentUrl;
    } else {
        unset($conf->global->DOL_URL_ROOT_DOCUMENT_PHP);
    }

    print '</div><div class="fichehalfright">';

    $formactions=new FormActions($db);
    $formactions->showactions(
        $object,
        'svcwarrantyletter@warrantysvc',
        (int)$object->fk_soc,
        1,
        '',
        10
    );

    print '</div></div>';
}

if ($action==='presend' && $canSend) {
    $modelmail='svcwarrantyletter';
    $defaulttopic='WarrantyLetterEmailSubject';
    $defaulttopiclang='warrantysvc@warrantysvc';
    $diroutput=$conf->warrantysvc->dir_output;
    include DOL_DOCUMENT_ROOT.'/core/tpl/card_presend.tpl.php';
}

llxFooter();
$db->close();
