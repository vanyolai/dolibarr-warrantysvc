<?php
/** Independent process/SQL session coordinated by rma_concurrency.php. */
require __DIR__.'/bootstrap.php';
$user->fetch((int) getenv('WARRANTYSVC_TEST_USER_ID'));
$rma = loadTestRma((int) ($argv[1] ?? 0));
$operation = $argv[2] ?? '';
$res = rmaTestQuery('SELECT CONNECTION_ID() AS id');
echo 'READY '.$db->fetch_object($res)->id."\n";
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') throw new RuntimeException('Missing parent barrier');
switch ($operation) {
    case 'update':
        $rma->diagnosis = 'Losing concurrent edit';
        $result = $rma->update($user);
        break;
    case 'status':
        $result = $rma->setStatus(SvcSupplierRma::STATUS_AUTHORIZED, $user);
        break;
    case 'date':
        $result = $rma->setBusinessDate('date_request', dol_now() - 12345, $user);
        break;
    case 'rollback':
        $result = $rma->rollbackStatus($user);
        break;
    default:
        throw new RuntimeException('Unknown operation');
}
checkRma($result < 0 && $rma->error === 'ErrorSupplierRmaConcurrentUpdate', 'Concurrent '.$operation.' rejected');
echo "DONE\n";
