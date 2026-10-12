<?php
/** Legacy schema simulation and real native module disable/enable regression. */
require __DIR__.'/bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/modWarrantySvc.class.php';
$user->fetch((int) getenv('WARRANTYSVC_TEST_USER_ID'));
$legacy = newTestRma();
$legacy->diagnosis = 'Preserved legacy diagnosis';
checkRma($legacy->update($user) > 0 && $legacy->setStatus('authorized', $user) > 0, 'Prepare historical RMA');
function rmaSnapshot()
{
    global $db;
    $rows = array();
    $res = rmaTestQuery('SELECT * FROM llx_svc_supplier_rma ORDER BY rowid');
    while ($row = $db->fetch_array($res)) {
        // DoliDB returns associative AND numeric keys. ALTER may change column
        // ordinal positions, which are not business data.
        foreach (array_keys($row) as $key) if (is_int($key)) unset($row[$key]);
        unset($row['revision']);
        ksort($row);
        $rows[] = $row;
    }
    return $rows;
}
$before = rmaSnapshot();
$history = $legacy->fetchHistory();
// This is allowed only by bootstrap's disposable/local/account guard. Never
// include this legacy-schema simulation in deployment/migration commands.
rmaTestQuery('ALTER TABLE llx_svc_supplier_rma DROP COLUMN revision');
$module = new modWarrantySvc($db);
checkRma($module->remove() > 0 && $module->init() > 0, 'Native legacy migration on reactivation');
$column = $db->fetch_object(rmaTestQuery("SHOW COLUMNS FROM llx_svc_supplier_rma LIKE 'revision'"));
checkRma($column && $column->Null === 'NO' && (string) $column->Default === '1', 'Revision migration is NOT NULL DEFAULT 1');
checkRma(rmaSnapshot() === $before, 'All legacy business fields, stock IDs and recorded metadata preserved');
$legacy = loadTestRma($legacy->id);
checkRma($legacy->revision === 1 && $legacy->fetchHistory() === $history, 'Legacy row reads with revision 1 and unchanged audit history');
$legacy->supplier_work_done = 'Editable after migration';
checkRma($legacy->update($user) > 0 && $legacy->revision === 2, 'Existing RMA remains editable after migration');
$before = rmaSnapshot();
$history = $legacy->fetchHistory();
for ($pass = 1; $pass <= 2; $pass++) {
    checkRma($module->remove() > 0 && $module->init() > 0, 'Native reactivation pass '.$pass);
    $current = loadTestRma($legacy->id);
    checkRma($current->revision === 2 && rmaSnapshot() === $before && $current->fetchHistory() === $history,
        'Reactivation '.$pass.' does not reset revision, fields or audit dates');
}
echo "Native migration/reactivation regression passed\n";
