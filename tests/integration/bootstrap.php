<?php
/** CLI-only bootstrap for an explicitly opted-in, local disposable database. */
if (PHP_SAPI !== 'cli' || getenv('WARRANTYSVC_TEST_ALLOW') !== 'yes') {
    throw new RuntimeException('CLI and WARRANTYSVC_TEST_ALLOW=yes are required');
}
$htdocs = realpath((string) getenv('WARRANTYSVC_TEST_HTDOCS'));
if (!$htdocs || !is_file($htdocs.'/master.inc.php') || !is_file($htdocs.'/conf/conf.php')) {
    throw new RuntimeException('Provide the disposable Dolibarr htdocs directory');
}
// Validate before bootstrapping Dolibarr, not after a GUI login/redirect.
// Keep native config variables in global scope: filefunc.inc.php uses
// include_once, so loading this file inside a closure would hide them.
require $htdocs.'/conf/conf.php';
$testConfig = get_defined_vars();
if (!preg_match('/^warrantysvc_test(?:_[a-zA-Z0-9]+)?$/', $testConfig['dolibarr_main_db_name'] ?? '')
    || !in_array($testConfig['dolibarr_main_db_host'] ?? '', array('127.0.0.1', 'localhost'), true)
    || ($testConfig['dolibarr_main_db_user'] ?? '') !== 'warrantysvc_test'
    || ($testConfig['dolibarr_main_db_type'] ?? '') !== 'mysqli') {
    throw new RuntimeException('Refusing non-local/non-disposable database configuration');
}
require_once $htdocs.'/master.inc.php';
if (DOL_VERSION !== '23.0.4') {
    throw new RuntimeException('Fixture requires pinned Dolibarr 23.0.4');
}
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierrma.class.php';

function checkRma($ok, $description)
{
    if (!$ok) throw new RuntimeException($description);
    echo 'PASS: '.$description."\n";
}

function rmaTestQuery($sql)
{
    global $db;
    $res = $db->query($sql);
    if (!$res) throw new RuntimeException($db->lasterror().' ['.$sql.']');
    return $res;
}

function rmaLogRows($db, $id, $entity, $code)
{
    $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'svc_supplier_rma_log';
    $sql .= ' WHERE fk_supplier_rma = '.((int) $id).' AND entity = '.((int) $entity);
    $sql .= " AND event_code = '".$db->escape($code)."' ORDER BY rowid";
    $res = $db->query($sql);
    if (!$res) throw new RuntimeException($db->lasterror());
    $rows = array();
    while ($row = $db->fetch_object($res)) $rows[] = $row;
    $db->free($res);
    return $rows;
}

function newTestRma($connection = null)
{
    global $db, $user;
    $rma = new SvcSupplierRma($connection ?: $db);
    $rma->fk_svc_request = (int) getenv('WARRANTYSVC_TEST_REQUEST_ID');
    $rma->fk_soc_supplier = (int) getenv('WARRANTYSVC_TEST_SUPPLIER_ID');
    $rma->fk_product = (int) getenv('WARRANTYSVC_TEST_PRODUCT_ID');
    $rma->qty = 1;
    $rma->serial_number = 'TEST-'.uniqid();
    $rma->problem_description = 'Disposable integration test';
    checkRma($rma->create($user) > 0, 'Create test RMA: '.$rma->error);
    return $rma;
}

function loadTestRma($id, $connection = null)
{
    global $db;
    $rma = new SvcSupplierRma($connection ?: $db);
    checkRma($rma->fetch($id) > 0, 'Load RMA '.((int) $id));
    return $rma;
}
