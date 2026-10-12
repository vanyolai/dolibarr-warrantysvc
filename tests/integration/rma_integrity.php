<?php
/**
 * Isolated Dolibarr 23 + MariaDB integration smoke test for supplier RMA.
 *
 * Usage and database safety requirements: see tests/integration/README.md.
 * This file intentionally never bootstraps an arbitrary production database.
 */
require __DIR__.'/bootstrap.php';

$requestId = (int) getenv('WARRANTYSVC_TEST_REQUEST_ID');
$supplierId = (int) getenv('WARRANTYSVC_TEST_SUPPLIER_ID');
$productId = (int) getenv('WARRANTYSVC_TEST_PRODUCT_ID');
$userId = (int) getenv('WARRANTYSVC_TEST_USER_ID');
if (!$requestId || !$supplierId || !$productId || !$userId) {
    fwrite(STDERR, "Provide TEST_REQUEST_ID / SUPPLIER_ID / PRODUCT_ID / USER_ID in WARRANTY SVC environment variables.\n");
    exit(2);
}
$user = new User($db);
if ($user->fetch($userId) <= 0) throw new RuntimeException('Test user not found');

$rma = new SvcSupplierRma($db);
$rma->fk_svc_request = $requestId;
$rma->fk_soc_supplier = $supplierId;
$rma->fk_product = $productId;
$rma->qty = 1;
$rma->serial_number = 'TEST-'.uniqid();
$rma->problem_description = 'Integration test item – safe to delete';
$id = 0;
try {
    $id = (int) $rma->create($user);
    checkRma($id > 0, 'RMA create with audit event: '.$rma->error);
    checkRma((int) $rma->revision === 1, 'Initial revision is 1');

    $editorA = new SvcSupplierRma($db);
    $editorB = new SvcSupplierRma($db);
    checkRma($editorA->fetch($id) > 0 && $editorB->fetch($id) > 0, 'Two snapshots loaded');

    $editorA->supplier_work_done = 'Replaced connector';
    checkRma($editorA->update($user) > 0, 'First editor saves');
    checkRma($editorA->revision === 2, 'First update increments revision');
    $updates = rmaLogRows($db, $id, (int) $rma->entity, 'UPDATE');
    checkRma(count($updates) === 1, 'Exactly one UPDATE audit event');
    $payload = json_decode($updates[0]->note, true);
    checkRma(isset($payload['changes']['supplier_work_done'])
        && $payload['changes']['supplier_work_done']['old'] === ''
        && $payload['changes']['supplier_work_done']['new'] === 'Replaced connector',
        'Field audit stores both prior and new values');

    $editorB->diagnosis = 'Stale tab must not overwrite';
    checkRma($editorB->update($user) < 0
        && $editorB->error === 'ErrorSupplierRmaConcurrentUpdate', 'Stale update rejected');
    $check = new SvcSupplierRma($db);
    checkRma($check->fetch($id) > 0
        && $check->diagnosis === ''
        && $check->supplier_work_done === 'Replaced connector'
        && $check->revision === 2, 'Stale update preserved persisted data');

    checkRma($check->update($user) > 0 && $check->revision === 2, 'No-op save does not bump revision');
    checkRma(count(rmaLogRows($db, $id, (int) $rma->entity, 'UPDATE')) === 1,
        'No-op save creates no audit event');

    $check->entity = ((int) $rma->entity) + 100;
    $check->diagnosis = 'Cross-entity modification';
    checkRma($check->update($user) < 0, 'Cross-entity edit rejected');

    $check->entity = (int) $rma->entity;
    $check->diagnosis = '';
    $staleBeforeTransition = new SvcSupplierRma($db);
    checkRma($staleBeforeTransition->fetch($id) > 0, 'Pre-transition snapshot loaded');
    checkRma($check->setStatus(SvcSupplierRma::STATUS_AUTHORIZED, $user) > 0, 'Authorized state reached');
    checkRma($check->revision === 3, 'Status change increments common revision');
    $staleBeforeTransition->diagnosis = 'Outdated status-screen edit';
    checkRma($staleBeforeTransition->update($user) < 0
        && $staleBeforeTransition->error === 'ErrorSupplierRmaConcurrentUpdate',
        'Transition prevents stale edit across action types');
    $authLog = rmaLogRows($db, $id, (int) $rma->entity, 'STATUS');
    checkRma(count($authLog) === 1, 'Status has exactly one event');
    $originalRecordedAt = $authLog[0]->date_event;
    $corrected = dol_now() - 86400;
    checkRma($check->setBusinessDate('date_authorized', $corrected, $user, 'Delayed reporting') > 0,
        'Backdated business timestamp accepted');
    checkRma($check->revision === 4, 'Date correction increments common revision');
    $after = rmaLogRows($db, $id, (int) $rma->entity, 'STATUS');
    checkRma($after[0]->date_event === $originalRecordedAt
        && $after[0]->date_effective === $db->idate($corrected),
        'Recorded timeline timestamp remains immutable');

    checkRma($check->setStatus(SvcSupplierRma::STATUS_SHIPPED, $user) > 0, 'Shipped state reached');
    $locked = new SvcSupplierRma($db);
    checkRma($locked->fetch($id) > 0, 'Locked identity snapshot loaded');
    $locked->serial_number = 'ILLEGAL-CHANGE';
    checkRma($locked->update($user) < 0 && $locked->error === 'ErrorSupplierRmaIdentityLocked',
        'Shipped RMA identity cannot be edited');
    $keep = new SvcSupplierRma($db);
    checkRma($keep->fetch($id) > 0, 'Audited RMA still exists');
    checkRma($keep->delete($user) < 0 && $keep->error === 'ErrorSupplierRmaDeleteHistory',
        'History-bearing RMA cannot be permanently deleted');

    // Only a truly untouched draft may be hard-deleted. Test the positive
    // path using a second record entirely inside the disposable test DB.
    $pristine = new SvcSupplierRma($db);
    $pristine->fk_svc_request = $requestId;
    $pristine->fk_soc_supplier = $supplierId;
    $pristine->fk_product = $productId;
    $pristine->qty = 1;
    $pristine->serial_number = 'TEST-PRISTINE-'.uniqid();
    $pristine->problem_description = 'Untouched disposable draft';
    $pristineId = (int) $pristine->create($user);
    checkRma($pristineId > 0, 'Pristine draft created');
    checkRma($pristine->delete($user) > 0, 'Pristine draft can be deleted');
    checkRma($pristine->fetch($pristineId) === 0, 'Deleted draft is no longer accessible');

    echo "Integration smoke test passed. Audited RMA ".$id." intentionally retained in disposable DB.\n";
} finally {
    fwrite(STDERR, "Disposable test fixture: destroy the entire warrantysvc_test database after running tests.\n");
}
