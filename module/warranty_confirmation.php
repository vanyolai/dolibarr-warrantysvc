<?php
/* Copyright (C) 2026 DPG Supply */
/** Backwards-compatible entry: old HTML-confirmation links now open the PDF letter. */
$res=0;
if (!$res && file_exists('../main.inc.php')) $res=@include '../main.inc.php';
if (!$res && file_exists('../../main.inc.php')) $res=@include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res=@include '../../../main.inc.php';
if (!$res) die('Include of main fails');
if (!$user->hasRight('warrantysvc','warrantyletter','read') || !empty($user->socid)) accessforbidden();
$id=GETPOSTINT('id');
if ($id<=0) { recordNotFound('',0); exit; }
header('Location: '.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?shipmentid='.$id);
exit;
