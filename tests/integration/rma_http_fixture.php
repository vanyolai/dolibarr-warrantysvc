<?php
/** CLI data provisioning/inspection for real authenticated HTTP tests. */
require __DIR__.'/bootstrap.php';
$user->fetch((int) getenv('WARRANTYSVC_TEST_USER_ID'));
if (($argv[1] ?? '') === 'snapshot') {
    $id = (int) ($argv[2] ?? 0);
    $res = rmaTestQuery('SELECT * FROM llx_svc_supplier_rma WHERE rowid = '.$id);
    $row = $db->fetch_object($res);
    if ($row) $row->revision = (int) $row->revision;
    $res = rmaTestQuery('SELECT * FROM llx_svc_supplier_rma_log WHERE fk_supplier_rma = '.$id.' ORDER BY rowid');
    $logs = array();
    while ($log = $db->fetch_object($res)) $logs[] = $log;
    echo 'JSON:'.json_encode(array('row' => $row ?: null, 'logs' => $logs))."\n";
    exit;
}
if (($argv[1] ?? '') !== 'prepare') throw new RuntimeException('Expected prepare|snapshot');
$roles = array('reader' => array(510021), 'writer' => array(510021, 510022),
    'deleter' => array(510021, 510023), 'noaccess' => array());
foreach ($roles as $login => $rights) {
    $role = new User($db);
    $role->login = 'fixture-'.$login;
    $role->lastname = 'HTTP '.$login;
    $role->admin = 0;
    checkRma($role->create($user, 1) > 0, 'Create HTTP '.$login.' user');
    checkRma($role->setPassword($user, 'Fixture-Only-23!', 0, 1) > 0, 'Set HTTP test password');
    foreach ($rights as $right) checkRma($role->addrights($right, '', '', 1, 1) > 0, 'Set HTTP role '.$right);
}
$data = array();
foreach (array('editable', 'deletable', 'stale_delete', 'retained', 'foreign') as $name) {
    $rma = newTestRma();
    if ($name === 'retained') { $rma->diagnosis = 'History retained'; checkRma($rma->update($user) > 0, 'Prepare protected draft'); }
    if ($name === 'foreign') rmaTestQuery('UPDATE llx_svc_supplier_rma SET entity = 2 WHERE rowid = '.$rma->id);
    $data[$name] = $rma->id;
}
echo 'JSON:'.json_encode($data)."\n";
