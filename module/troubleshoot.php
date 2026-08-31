<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    troubleshoot.php
 * \ingroup warrantysvc
 * \brief   STRETCH: Guided troubleshooting workflow for a Service Request
 *
 * Presents a checklist of diagnostic steps for the product associated with
 * the service request. Agents can mark steps done and record findings.
 * Findings are saved as a structured JSON note appended to note_private.
 *
 * No additional database table is required — data is stored in
 * llx_svc_request.note_private as a structured block.
 */

$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svctroubleshoot.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/troubleshoot.lib.php';

$langs->loadLangs(array('warrantysvc@warrantysvc'));

$id     = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');
$cancel = GETPOST('cancel', 'alpha');

$object = new SvcRequest($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	dol_print_error($db, $object->error);
	exit;
}

if (!$user->hasRight('warrantysvc', 'svcrequest', 'read')) {
	accessforbidden();
}

$permwrite = $user->hasRight('warrantysvc', 'svcrequest', 'write');

/*
 * Actions
 */
if ($cancel) {
	$action = '';
}

if ($action == 'save_findings' && $permwrite) {
	// Read submitted checklist results
	$steps  = warrantysvc_checklist_steps($object->fk_product);
	$checks = array();
	foreach ($steps as $key => $step) {
		$checks[$key] = array(
			'done'    => GETPOST('check_'.$key, 'int') ? 1 : 0,
			'finding' => GETPOST('finding_'.$key, 'alphanohtml'),
		);
	}
	$summary  = GETPOST('troubleshoot_summary', 'restricthtml');
	$outcome  = GETPOST('troubleshoot_outcome', 'alpha');

	// Record the session as a structured, immutable history entry
	$session = new SvcTroubleshoot($db);
	$session->fk_svcrequest  = $object->id;
	$session->datec          = dol_now();
	$session->fk_user_author = $user->id;
	$session->summary        = $summary;
	$session->outcome        = $outcome;
	$session->checklist      = array();
	foreach ($steps as $key => $step) {
		$session->checklist[] = array(
			'label'   => $step['label'],
			'done'    => !empty($checks[$key]['done']) ? 1 : 0,
			'finding' => $checks[$key]['finding'],
		);
	}
	$session->create($user);

	// Suggest a resolution type from the outcome, but only if none chosen yet —
	// never overwrite a type the user explicitly picked during diagnosis.
	if (empty($object->resolution_type)) {
		$outcome_to_resolution = array(
			'no_fault'     => 'informational',
			'resolved'     => 'guidance',
			'escalate'     => 'intervention',
			'parts_needed' => 'component',
			'intervention' => 'intervention',
		);
		if (!empty($outcome) && isset($outcome_to_resolution[$outcome])) {
			$object->resolution_type = $outcome_to_resolution[$outcome];
			$object->update($user);
		}
	}

	// Update the service log for this serial — populates Unit Service History on warranty card
	if (!empty($object->serial_number)) {
		require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcservicelog.class.php';
		$svclog = new SvcServiceLog($db);
		$svclog->fetchBySerial($object->serial_number, $conf->entity);

		$svclog->serial_number    = $object->serial_number;
		$svclog->fk_product       = $object->fk_product;
		$svclog->service_count    = ((int) $svclog->service_count) + 1;
		$svclog->last_service_date = dol_now();
		$svclog->condition_notes  = $summary ?: $svclog->condition_notes;

		// Map outcome to condition status
		$outcome_to_condition = array(
			'resolved'     => SvcServiceLog::CONDITION_GOOD,
			'no_fault'     => SvcServiceLog::CONDITION_GOOD,
			'escalate'     => SvcServiceLog::CONDITION_POOR,
			'parts_needed' => SvcServiceLog::CONDITION_FAIR,
			'intervention' => SvcServiceLog::CONDITION_FAIR,
		);
		if (!empty($outcome) && isset($outcome_to_condition[$outcome])) {
			$svclog->condition_status = $outcome_to_condition[$outcome];
		}

		// If the svcrecord module manages condition scoring, don't overwrite
		// with the old formula — let svcrecord's multi-factor scores take precedence
		if (isModEnabled('svcrecord')) {
			$svclog->skip_auto_score = true;
		}

		$svclog->save($user);
	}

	setEventMessages($langs->trans('TroubleshootSaved'), null, 'mesgs');
	$action = '';
}

/*
 * View
 */
$form = new Form($db);
$head = warrantysvc_prepare_head($object);

llxHeader('', $langs->trans('Troubleshoot').' — '.$object->ref, '');

print dol_get_fiche_head($head, 'troubleshoot', $langs->trans('ServiceRequest'), -1, 'technic');

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/list.php">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', svcrequest_status_badge($object->status));

print dol_get_fiche_end();

// ---- CHECKLIST FORM ----
print '<form action="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'" method="POST">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save_findings">';

print '<div class="fichecenter">';

// Product info banner
print '<div class="underbanner clearboth">';
if ($object->fk_product) {
	$product = new Product($db);
	$product->fetch($object->fk_product);
	print '<strong>'.$langs->trans('Product').':</strong> '.$product->getNomUrl(1);
	print ' &nbsp; <strong>'.$langs->trans('SvcSerialNumber').':</strong> '.dol_escape_htmltag($object->serial_number);
}
print '</div>';

$steps = warrantysvc_checklist_steps($object->fk_product);

print '<table class="border centpercent tableforfieldcreate" style="margin-top:12px">';
print '<tr class="liste_titre">';
print '<th style="width:36px">'.$langs->trans('SvcDone').'</th>';
print '<th>'.$langs->trans('DiagnosticStep').'</th>';
print '<th>'.$langs->trans('Finding').'</th>';
print '</tr>';

foreach ($steps as $key => $step) {
	print '<tr class="oddeven">';
	print '<td class="center"><input type="checkbox" name="check_'.$key.'" value="1"></td>';
	print '<td>';
	print '<strong>'.dol_escape_htmltag($step['label']).'</strong>';
	if (!empty($step['description'])) {
		print '<br><span class="opacitymedium small">'.dol_escape_htmltag($step['description']).'</span>';
	}
	print '</td>';
	print '<td><input type="text" name="finding_'.$key.'" class="flat" style="width:100%" placeholder="'.$langs->trans('FindingOptional').'"></td>';
	print '</tr>';
}

print '</table>';

print '<br>';

// Summary text
print '<div class="tagtable">';
print '<table class="border centpercent">';
print '<tr><td class="tdtop" style="width:180px">'.$form->textwithpicto($langs->trans('TroubleshootSummary'), $langs->trans('TooltipTroubleshootSummary')).'</td>';
print '<td><textarea name="troubleshoot_summary" class="flat" rows="4" style="width:90%" placeholder="'.$langs->trans('TroubleshootSummaryPlaceholder').'"></textarea></td></tr>';

// Outcome
$outcomes = array(
	''              => $langs->trans('SelectOutcome'),
	'resolved'      => $langs->trans('TroubleshootOutcome_resolved'),
	'no_fault'      => $langs->trans('TroubleshootOutcome_no_fault'),
	'escalate'      => $langs->trans('TroubleshootOutcome_escalate'),
	'parts_needed'  => $langs->trans('TroubleshootOutcome_parts_needed'),
	'intervention'  => $langs->trans('TroubleshootOutcome_intervention'),
);
print '<tr><td>'.$form->textwithpicto($langs->trans('TroubleshootOutcome'), $langs->trans('TooltipTroubleshootOutcome')).'</td>';
print '<td>';
print Form::selectarray('troubleshoot_outcome', $outcomes, '', 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth200');
print '</td></tr>';

print '</table>';
print '</div>';

print '</div>'; // fichecenter

if ($permwrite) {
	print '<div class="center" style="margin-top:12px">';
	print '<input type="submit" class="button button-save" name="save_findings" value="'.$langs->trans('SaveFindings').'">';
	print ' &nbsp; ';
	print '<a class="button button-cancel" href="'.DOL_URL_ROOT.'/custom/warrantysvc/card.php?id='.$object->id.'">'.$langs->trans('Cancel').'</a>';
	print '</div>';
}

print '</form>';

// ---- PREVIOUS TROUBLESHOOT SESSIONS ----
$sessions = SvcTroubleshoot::fetchAllForRequest($db, $object->id);
if (!empty($sessions)) {
	$outcomelabels = array(
		'resolved'     => $langs->trans('TroubleshootOutcome_resolved'),
		'no_fault'     => $langs->trans('TroubleshootOutcome_no_fault'),
		'escalate'     => $langs->trans('TroubleshootOutcome_escalate'),
		'parts_needed' => $langs->trans('TroubleshootOutcome_parts_needed'),
		'intervention' => $langs->trans('TroubleshootOutcome_intervention'),
	);

	print '<br>';
	print load_fiche_titre($langs->trans('PreviousTroubleshootSessions'), '', 'technic');

	foreach ($sessions as $sess) {
		$author = '';
		if ($sess->fk_user_author > 0) {
			$u = new User($db);
			if ($u->fetch($sess->fk_user_author) > 0) {
				$author = $u->getFullName($langs);
			}
		}

		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';

		// Header row: date + author + outcome badge
		print '<tr class="liste_titre">';
		print '<td>'.img_picto('', 'object_action', 'class="pictofixedwidth"').dol_print_date($sess->datec, 'dayhour');
		if ($author) {
			print ' &mdash; <span class="opacitymedium">'.dol_escape_htmltag($author).'</span>';
		}
		print '</td>';
		print '<td class="right">';
		if ($sess->outcome) {
			$olabel = isset($outcomelabels[$sess->outcome]) ? $outcomelabels[$sess->outcome] : $sess->outcome;
			print $langs->trans('TroubleshootOutcome').': '.dolGetStatus($olabel, '', '', 'status4', 3);
		}
		print '</td>';
		print '</tr>';

		// Checklist rows
		foreach ($sess->checklist as $item) {
			$done  = !empty($item['done']);
			$label = isset($item['label']) ? $item['label'] : '';
			$find  = isset($item['finding']) ? $item['finding'] : '';
			print '<tr class="oddeven">';
			print '<td class="center nowraponall" style="width:24px">'.($done ? img_picto($langs->trans('SvcDone'), 'tick') : '<span class="opacitymedium">&mdash;</span>').'</td>';
			print '<td colspan="1"'.($done ? '' : ' class="opacitymedium"').'>'.dol_escape_htmltag($label);
			if ($find !== '') {
				print '<br><span class="opacitymedium small">'.dol_escape_htmltag($find).'</span>';
			}
			print '</td>';
			print '</tr>';
		}

		// Summary row
		if (!empty($sess->summary)) {
			print '<tr class="oddeven"><td class="tdtop">'.$langs->trans('TroubleshootSummary').'</td>';
			print '<td>'.dol_nl2br(dol_escape_htmltag($sess->summary, 0, 1)).'</td></tr>';
		}

		print '</table></div><br>';
	}
}

llxFooter();
$db->close();
