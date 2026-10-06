<?php
/* Copyright (C) 2026 DPG Supply */
/** Authorized, SHA256-verified read of a preserved PDF version. */
$res=0;
if (!$res && file_exists('../main.inc.php')) $res=@include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res=@include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res=@include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarrantyletter.class.php';
if (!$user->hasRight('warrantysvc','warrantyletter','read') || !empty($user->socid)) accessforbidden();
$id=GETPOSTINT('id'); $number=GETPOSTINT('v');
$letter=new SvcWarrantyLetter($db);
if ($id<=0 || $number<=0 || $letter->fetch($id)<=0) { recordNotFound('',0); exit; }
$revision=$letter->getVersion($number);
if (!$revision || !$letter->verifyVersion($revision)) { header('HTTP/1.1 404 Not Found'); exit; }
$file=$letter->versionFullPath($revision);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.basename($revision->file_path).'"');
header('Content-Length: '.filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
exit;
