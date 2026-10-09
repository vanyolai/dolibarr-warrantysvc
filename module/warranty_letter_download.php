<?php
/* Copyright (C) 2026 DPG Supply */
/** Authorized, SHA256-verified read of a preserved PDF version. */
$res=0;
if (!$res && file_exists('../main.inc.php')) $res=@include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res=@include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res=@include '../../../main.inc.php';
if (!$res) die('Include of main fails');

dol_include_once('/warrantysvc/class/svcwarrantyletter.class.php');

if (!$user->hasRight('warrantysvc','warrantyletter','read') || !empty($user->socid)) accessforbidden();

$id=GETPOSTINT('id');
$number=GETPOSTINT('v');
$fileParam=GETPOST('file','restricthtml');

$letter=new SvcWarrantyLetter($db);
if ($id<=0 || $letter->fetch($id)<=0) {
    recordNotFound('',0);
    exit;
}

$revision=null;

if ($number>0) {
    $revision=$letter->getVersion($number);
} elseif ($fileParam!=='') {
    global $conf;
    $normalized=ltrim(str_replace('\\','/',$fileParam),'/');
    $sql='SELECT * FROM '.MAIN_DB_PREFIX.'svc_warranty_letter_version';
    $sql.=' WHERE entity='.((int)$conf->entity).' AND fk_letter='.((int)$letter->id);
    $sql.=" AND file_path='".$db->escape($normalized)."'";
    $sql.=$db->plimit(1);
    $resql=$db->query($sql);
    if ($resql) {
        $revision=$db->fetch_object($resql);
        $db->free($resql);
    }
}

if (!$revision || !$letter->verifyVersion($revision)) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$file=$letter->versionFullPath($revision);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.basename($revision->file_path).'"');
header('Content-Length: '.filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
exit;
