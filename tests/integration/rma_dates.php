<?php
/** Date provenance must survive workflow corrections and repeated milestones. */
require __DIR__.'/bootstrap.php';
$user->fetch((int) getenv('WARRANTYSVC_TEST_USER_ID'));
$date = dol_now() - 86400;
$rma = newTestRma();
checkRma($rma->setBusinessDate('date_authorized', $date, $user, 'Pre-entered milestone') > 0, 'Enter date before its workflow step');
checkRma($rma->setStatus('authorized', $user, 'Use existing business date') > 0, 'Authorize using pre-entered date');
$event = rmaLogRows($db, $rma->id, 1, 'STATUS')[0];
checkRma($event->date_effective === $db->idate($date), 'Milestone uses pre-entered effective time');
checkRma($rma->rollbackStatus($user) > 0 && $rma->date_authorized === $date
    && loadTestRma($rma->id)->date_authorized === $date, 'Rollback preserves manually pre-entered business date');
$newDate = $date - 86400;
checkRma($rma->setBusinessDate('date_authorized', $newDate, $user) > 0, 'Prepare next milestone after rollback');
checkRma(rmaLogRows($db, $rma->id, 1, 'STATUS')[0] == $event,
    'Pre-entry after rollback does not rewrite the undone historical milestone');
checkRma($rma->setStatus('authorized', $user) > 0, 'Repeat authorized milestone');
$events = rmaLogRows($db, $rma->id, 1, 'STATUS');
checkRma(count($events) === 2 && $events[0] == $event && $events[1]->date_effective === $db->idate($newDate),
    'Repeated milestone retains previous event and uses the new effective date');
checkRma($rma->setBusinessDate('date_authorized', null, $user) > 0 && empty($rma->date_authorized), 'Optional milestone date may be cleared');
checkRma(rmaLogRows($db, $rma->id, 1, 'STATUS')[1]->date_event === $events[1]->date_event,
    'Clearing effective date preserves recorded time');

$rma = newTestRma();
checkRma($rma->setStatus('authorized', $user) > 0, 'Generate automatic milestone date');
checkRma($rma->setBusinessDate('date_authorized', $date, $user, 'Correct auto date') > 0, 'Correct automatic date');
checkRma($rma->rollbackStatus($user) > 0 && $rma->date_authorized === $date,
    'Rollback preserves date explicitly corrected after transition');

$rma = newTestRma();
foreach (array('authorized', 'shipped', 'received_by_supplier', 'in_service') as $status) {
    checkRma($rma->setStatus($status, $user) > 0, 'Reach '.$status);
}
checkRma($rma->setBusinessDate('date_supplier_completed', $date, $user) > 0, 'Pre-enter completion date');
checkRma($rma->setStatus('repaired', $user) > 0 && $rma->result_type === 'repaired', 'Completion defaults result even with pre-entered date');
checkRma($rma->rollbackStatus($user) > 0 && $rma->date_supplier_completed === $date && $rma->result_type === '',
    'Rollback restores auto result without deleting manual completion date');

// Conservative compatibility: an old plain-text STATUS note has no proof of
// which fields it introduced. Retain those dates instead of guessing.
$rma = newTestRma();
checkRma($rma->setStatus('authorized', $user) > 0, 'Prepare legacy STATUS log');
$savedDate = $rma->date_authorized;
rmaTestQuery("UPDATE llx_svc_supplier_rma_log SET note = 'Legacy free text' WHERE fk_supplier_rma = ".$rma->id." AND event_code = 'STATUS'");
checkRma($rma->rollbackStatus($user) > 0 && $rma->date_authorized === $savedDate,
    'Legacy rollback conservatively preserves dates with unknown provenance');
echo "Milestone date provenance regression passed\n";
