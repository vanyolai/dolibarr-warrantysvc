<?php
/** Real overlapping operations, independent PHP processes and DB connections. */
require __DIR__.'/bootstrap.php';
$user->fetch((int) getenv('WARRANTYSVC_TEST_USER_ID'));
foreach (array('update', 'status', 'date', 'rollback') as $operation) {
    $rma = newTestRma();
    if ($operation === 'rollback') checkRma($rma->setStatus('authorized', $user) > 0, 'Prepare rollback race');
    $before = loadTestRma($rma->id);
    $history = $rma->fetchHistory();
    checkRma($db->begin() > 0, 'Acquire parent transaction');
    rmaTestQuery('SELECT rowid FROM llx_svc_supplier_rma WHERE rowid = '.$rma->id.' FOR UPDATE');
    $process = proc_open(array(PHP_BINARY, __DIR__.'/rma_race_worker.php', (string) $rma->id, $operation),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency worker');
    $finished = false;
    try {
        stream_set_timeout($pipes[1], 10);
        $connectionId = 0;
        while (($line = fgets($pipes[1])) !== false) {
            if (preg_match('/^READY (\d+)/', $line, $match)) { $connectionId = (int) $match[1]; break; }
        }
        checkRma($connectionId > 0, 'Independent '.$operation.' worker loaded its snapshot');
        fwrite($pipes[0], "GO\n");
        fflush($pipes[0]);
        $waiting = false;
        $deadline = microtime(true) + 10;
        do {
            $res = rmaTestQuery('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = '.$connectionId);
            $row = $db->fetch_object($res);
            if ($row && strpos((string) $row->INFO, 'svc_supplier_rma') !== false
                && (stripos((string) $row->INFO, 'FOR UPDATE') !== false || stripos((string) $row->INFO, 'UPDATE ') === 0)) {
                $waiting = true;
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        checkRma($waiting, 'Observed '.$operation.' SQL blocked by parent row lock');
        $rma->supplier_work_done = 'Winning parent save';
        checkRma($rma->update($user) > 0, 'Parent update commits inside held transaction');
        checkRma($db->commit() > 0, 'Parent releases SQL lock');
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) fclose($pipe);
        $code = proc_close($process);
        $finished = true;
        checkRma($code === 0 && strpos($output, "DONE\n") !== false, 'Worker conflict result: '.$output.$errors);
        $current = loadTestRma($rma->id);
        checkRma($current->revision === $before->revision + 1 && $current->supplier_work_done === 'Winning parent save'
            && $current->diagnosis === $before->diagnosis && $current->status === $before->status
            && $current->date_request === $before->date_request && $current->date_authorized === $before->date_authorized,
            'Overlapping '.$operation.' cannot overwrite winner or change lifecycle/date');
        checkRma(count($current->fetchHistory()) === count($history) + 1
            && count(rmaLogRows($db, $rma->id, 1, 'UPDATE')) === 1, 'Only winning operation emits an audit event');
    } finally {
        $db->rollback();
        if (!$finished) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }
}
echo "Independent-session concurrency regression passed\n";
