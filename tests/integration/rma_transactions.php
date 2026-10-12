<?php
/** Real SQL failure injection and lifecycle/retention regression. */
require __DIR__.'/bootstrap.php';
$user->fetch((int) getenv('WARRANTYSVC_TEST_USER_ID'));
$rma = newTestRma();
$initial = loadTestRma($rma->id);
// Fail the actual INSERT at the SQL server, not a mocked model method.
rmaTestQuery('CREATE TRIGGER warrantysvc_test_audit_failure BEFORE INSERT ON '.MAIN_DB_PREFIX.'svc_supplier_rma_log FOR EACH ROW BEGIN IF COALESCE(@warrantysvc_fail_audit, 0) = 1 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Injected RMA audit failure\'; END IF; END');
try {
    rmaTestQuery('SET @warrantysvc_fail_audit = 1');
    $rma->diagnosis = 'Must not persist';
    checkRma($rma->update($user) < 0 && strpos($rma->error, 'Injected') !== false, 'Failed SQL audit rejects edit');
    $persisted = loadTestRma($rma->id);
    checkRma($persisted->diagnosis === '' && $persisted->revision === 1 && $rma->revision === 1,
        'Audit failure rolls back business field and persisted/in-memory revision');
    checkRma(count(rmaLogRows($db, $rma->id, 1, 'UPDATE')) === 0, 'Failed edit has no audit residue');
    $rma = $persisted;
    checkRma($rma->setStatus(SvcSupplierRma::STATUS_AUTHORIZED, $user) < 0, 'Failed audit rejects transition');
    $persisted = loadTestRma($rma->id);
    checkRma($rma->status === 'draft' && empty($rma->date_authorized) && $rma->revision === 1
        && $persisted->status === 'draft' && empty($persisted->date_authorized) && $persisted->revision === 1,
        'Failed transition leaves object and DB dates/status unchanged');
    checkRma($rma->setBusinessDate('date_request', dol_now() - 86400, $user) < 0, 'Failed audit rejects date correction');
    $persisted = loadTestRma($rma->id);
    checkRma($persisted->date_request === $initial->date_request && $rma->revision === 1
        && rmaLogRows($db, $rma->id, 1, 'CREATE')[0]->date_effective === $db->idate($initial->date_request),
        'Failed date audit restores RMA date and milestone effective time');
    rmaTestQuery('SET @warrantysvc_fail_audit = 0');
    checkRma($rma->setStatus(SvcSupplierRma::STATUS_AUTHORIZED, $user) > 0, 'Transition recovers after failed audit');
    $beforeRollback = loadTestRma($rma->id);
    rmaTestQuery('SET @warrantysvc_fail_audit = 1');
    checkRma($rma->rollbackStatus($user) < 0, 'Failed audit rejects rollback');
    $persisted = loadTestRma($rma->id);
    checkRma($rma->status === $beforeRollback->status && $rma->revision === $beforeRollback->revision
        && $rma->date_authorized === $beforeRollback->date_authorized
        && $persisted->status === $beforeRollback->status && $persisted->date_authorized === $beforeRollback->date_authorized,
        'Failed rollback leaves object and DB milestone unchanged');
} finally {
    rmaTestQuery('SET @warrantysvc_fail_audit = 0');
    rmaTestQuery('DROP TRIGGER warrantysvc_test_audit_failure');
}

$stale = loadTestRma($rma->id);
checkRma($rma->setStatus(SvcSupplierRma::STATUS_SHIPPED, $user) > 0, 'Second lifecycle step commits');
checkRma($stale->setStatus(SvcSupplierRma::STATUS_SHIPPED, $user) < 0
    && $stale->error === 'ErrorSupplierRmaConcurrentUpdate', 'Stale transition rejected');
checkRma($stale->rollbackStatus($user) < 0 && $stale->error === 'ErrorSupplierRmaConcurrentUpdate', 'Stale rollback rejected');
checkRma($stale->setBusinessDate('date_authorized', $stale->date_authorized, $user) < 0
    && $stale->error === 'ErrorSupplierRmaConcurrentUpdate', 'Stale same-date submission rejected');
checkRma($stale->setBusinessDate('date_authorized', dol_now() - 1000, $user) < 0
    && $stale->error === 'ErrorSupplierRmaConcurrentUpdate', 'Stale changed-date submission rejected');
$statusRows = rmaLogRows($db, $rma->id, 1, 'STATUS');
checkRma($rma->rollbackStatus($user) > 0 && $rma->status === 'authorized' && empty($rma->date_shipped), 'Rollback clears accidentally generated shipped date');
checkRma($rma->rollbackStatus($user) > 0 && $rma->status === 'draft' && empty($rma->date_authorized), 'Repeated rollback walks back to draft');
checkRma($rma->rollbackStatus($user) < 0, 'Draft cannot be rolled back');
$afterRows = rmaLogRows($db, $rma->id, 1, 'STATUS');
checkRma($statusRows == $afterRows, 'Rollback preserves historical status events and both timestamps');
checkRma($rma->delete($user) < 0, 'Rolled-back draft retains its audit history');
checkRma($rma->setStatus(SvcSupplierRma::STATUS_CLOSED, $user) < 0, 'Invalid lifecycle transition rejected');
checkRma($rma->setBusinessDate('date_request', null, $user) < 0, 'Required request date cannot be cleared');
checkRma($rma->setBusinessDate('not_a_column', dol_now(), $user) < 0, 'Date column allowlist enforced');

// A foreign-entity history row must not leak through history reads.
$beforeHistory = $rma->fetchHistory();
rmaTestQuery("INSERT INTO llx_svc_supplier_rma_log (entity, fk_supplier_rma, event_code, date_event) VALUES (2, ".$rma->id.", 'TEST-FOREIGN', NOW())");
checkRma($rma->fetchHistory() === $beforeHistory, 'History excludes another entity');
rmaTestQuery('DELETE FROM llx_svc_supplier_rma_log WHERE entity = 2 AND fk_supplier_rma = '.$rma->id);

foreach (array('link', 'contact', 'file', 'stock', 'email', 'missing_history') as $kind) {
    $draft = newTestRma();
    if ($kind === 'link') {
        rmaTestQuery("INSERT INTO llx_links (entity, objecttype, objectid, label, url, datea) VALUES (1, 'svcsupplierrma', ".$draft->id.", 'Test link', 'https://example.invalid', NOW())");
    } elseif ($kind === 'contact') {
        $res = rmaTestQuery("SELECT rowid FROM llx_c_type_contact WHERE element = 'svcsupplierrma' AND source = 'internal' LIMIT 1");
        $type = $db->fetch_object($res);
        rmaTestQuery('INSERT INTO llx_element_contact (element_id, fk_c_type_contact, fk_socpeople, statut) VALUES ('.$draft->id.', '.$type->rowid.', '.$user->id.', 4)');
    } elseif ($kind === 'file') {
        $dir = $draft->getDocumentOutputDir('TEST-SR-1');
        checkRma($dir !== '', 'Configured document folder');
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/fixture.txt', 'Retain this document');
    } elseif ($kind === 'stock') {
        rmaTestQuery('UPDATE llx_svc_supplier_rma SET fk_stock_movement_out = 999 WHERE rowid = '.$draft->id);
    } elseif ($kind === 'email') {
        checkRma($draft->logEvent('EMAIL', '', '', 'Previously communicated draft', $user) > 0, 'Fixture communication event');
    } else {
        rmaTestQuery('DELETE FROM llx_svc_supplier_rma_log WHERE fk_supplier_rma = '.$draft->id);
    }
    checkRma($draft->delete($user) < 0, 'Draft with '.$kind.' cannot be hard-deleted');
    checkRma(loadTestRma($draft->id)->revision === 1, 'Protected draft remains unchanged');
}
$draft = newTestRma();
$staleDelete = loadTestRma($draft->id);
$draft->diagnosis = 'Another editor changed this';
checkRma($draft->update($user) > 0 && $staleDelete->delete($user) < 0
    && $staleDelete->error === 'ErrorSupplierRmaConcurrentUpdate', 'Stale delete confirmation rejected');

$draft = newTestRma();
rmaTestQuery("CREATE TRIGGER warrantysvc_test_delete_failure BEFORE DELETE ON llx_svc_supplier_rma FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected RMA delete failure'");
try {
    checkRma($draft->delete($user) < 0, 'SQL failure rejects hard delete');
    checkRma(loadTestRma($draft->id)->revision === 1 && count(rmaLogRows($db, $draft->id, 1, 'CREATE')) === 1,
        'Failed RMA delete restores its previously deleted CREATE event');
} finally {
    rmaTestQuery('DROP TRIGGER warrantysvc_test_delete_failure');
}
echo "Transaction/lifecycle/retention regression passed\n";
