<?php
/** Provision only a new local fixture; never reset an existing database/install. */
if (PHP_SAPI !== 'cli' || getenv('WARRANTYSVC_TEST_ALLOW') !== 'yes') {
    throw new RuntimeException('CLI and WARRANTYSVC_TEST_ALLOW=yes are required');
}
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $htdocs = realpath((string) getenv('WARRANTYSVC_TEST_HTDOCS'));
    if (!$htdocs || !is_file($htdocs.'/install/step2.php') || is_file($htdocs.'/conf/conf.php')) {
        throw new RuntimeException('Provide a fresh disposable Dolibarr checkout (no conf.php)');
    }
    $host = getenv('WARRANTYSVC_TEST_DB_HOST') ?: '127.0.0.1';
    $name = getenv('WARRANTYSVC_TEST_DB_NAME') ?: 'warrantysvc_test';
    $port = (int) (getenv('WARRANTYSVC_TEST_DB_PORT') ?: 3306);
    $pass = (string) getenv('WARRANTYSVC_TEST_DB_PASS');
    if (!in_array($host, array('127.0.0.1', 'localhost'), true)
        || !preg_match('/^warrantysvc_test(?:_[a-zA-Z0-9]+)?$/', $name) || $pass === '') {
        throw new RuntimeException('Only local, explicitly named test databases are allowed');
    }
    $sql = new mysqli($host, 'warrantysvc_test', $pass, $name, $port);
    if ($sql->query('SHOW TABLES')->num_rows !== 0) {
        throw new RuntimeException('Refusing existing database: fixture requires zero tables');
    }
    $sql->close();
    $dataRoot = dirname($htdocs).'/test-documents';
    if (file_exists($dataRoot)) throw new RuntimeException('Refusing existing document directory');
    mkdir($dataRoot, 0700, true);
    $values = array(
        'dolibarr_main_url_root' => 'http://127.0.0.1:18080',
        'dolibarr_main_document_root' => $htdocs,
        'dolibarr_main_url_root_alt' => '/custom',
        'dolibarr_main_document_root_alt' => $htdocs.'/custom',
        'dolibarr_main_data_root' => $dataRoot,
        'dolibarr_main_db_host' => $host, 'dolibarr_main_db_port' => (string) $port,
        'dolibarr_main_db_name' => $name, 'dolibarr_main_db_user' => 'warrantysvc_test',
        'dolibarr_main_db_pass' => $pass, 'dolibarr_main_db_type' => 'mysqli',
        'dolibarr_main_db_prefix' => 'llx_', 'dolibarr_main_db_character_set' => 'utf8mb4',
        'dolibarr_main_db_collation' => 'utf8mb4_unicode_ci',
        'dolibarr_main_authentication' => 'dolibarr',
        'dolibarr_main_instance_unique_id' => bin2hex(random_bytes(16)),
    );
    $content = "<?php\n// Disposable integration fixture. Never deploy.\n";
    foreach ($values as $key => $value) $content .= '$'.$key.' = '.var_export($value, true).";\n";
    if (file_put_contents($htdocs.'/conf/conf.php', $content) === false) throw new RuntimeException('Cannot write config');
    chmod($htdocs.'/conf/conf.php', 0600);
    echo "Prepared fresh test config\n";
    exit;
}
if ($mode !== 'seed') throw new RuntimeException('Usage: fixture.php prepare|seed');
require __DIR__.'/bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
// Remove the native installer flag and explicitly disable mail transmission.
foreach (array('MAIN_NOT_INSTALLED' => '0', 'MAIN_VERSION_LAST_INSTALL' => DOL_VERSION,
    'MAIN_SECURITY_CSRF_WITH_TOKEN' => '1', 'MAIN_MAIL_SENDMODE' => 'smtps',
    'MAIN_MAIL_SMTP_SERVER' => '127.0.0.1', 'MAIN_MAIL_SMTP_PORT' => '1',
    'MAIN_MAIL_FORCE_SENDTO' => 'fixture@example.invalid', 'DATABASE_PWD_ENCRYPTED' => '1',
    'MAIN_INFO_SOCIETE_NOM' => 'Disposable WarrantySvc fixture') as $key => $value) {
    checkRma(dolibarr_set_const($db, $key, $value, 'chaine', 0, '', 1) > 0, 'Fixture constant '.$key);
}
$operator = new User($db);
$operator->login = 'fixture-admin';
$operator->lastname = 'Fixture';
$operator->admin = 1;
checkRma($operator->create($user, 1) > 0, 'Native fixture user creation');
$user = $operator;
checkRma($user->setPassword($user, 'Fixture-Only-23!', 0, 1) > 0, 'Native test password');
$conf->setValues($db);
$activated = activateModule('modWarrantySvc');
checkRma(empty($activated['errors']) && $activated['nbmodules'] > 0,
    'Native module activation: '.implode('; ', $activated['errors']));
$conf->setValues($db);
rmaTestQuery("INSERT INTO llx_societe (nom, entity, fournisseur, client, code_fournisseur, code_client) VALUES ('Fixture supplier', 1, 1, 1, 'TEST-SUPPLIER', 'TEST-CUSTOMER')");
$supplierId = (int) $db->last_insert_id('llx_societe');
rmaTestQuery("INSERT INTO llx_product (ref, label, entity) VALUES ('TEST-PRODUCT', 'Fixture product', 1)");
$productId = (int) $db->last_insert_id('llx_product');
rmaTestQuery("INSERT INTO llx_svc_request (ref, entity, fk_soc, fk_product, issue_description, date_creation, fk_user_creat) VALUES ('TEST-SR-1', 1, ".$supplierId.', '.$productId.", 'Fixture issue', NOW(), ".$user->id.')');
$requestId = (int) $db->last_insert_id('llx_svc_request');
$env = array('WARRANTYSVC_TEST_REQUEST_ID' => $requestId, 'WARRANTYSVC_TEST_SUPPLIER_ID' => $supplierId,
    'WARRANTYSVC_TEST_PRODUCT_ID' => $productId, 'WARRANTYSVC_TEST_USER_ID' => $user->id);
// Values are numeric IDs only, safe for the shell runner to source.
$text = '';
foreach ($env as $key => $value) $text .= 'export '.$key.'='.((int) $value)."\n";
file_put_contents(dirname(DOL_DOCUMENT_ROOT).'/fixture.env', $text);
file_put_contents(DOL_DATA_ROOT.'/install.lock', 'Disposable test fixture');
echo 'Fixture: Dolibarr '.DOL_VERSION.', PHP '.PHP_VERSION.', DB '.$db->getVersion()."\n";
