<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modWarrantySvc.class.php
 * \ingroup warrantysvc
 * \brief   Description and activation file for module SvcRequest
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module SvcRequest (RMA & Warranty Management)
 */
class modWarrantySvc extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Module identifier — must be unique globally.
		// Current value (510000) is valid for private/internal use (>500000 range).
		// TODO (DoliStore pre-publication): reserve a block in the 100000–499999 range at
		// https://wiki.dolibarr.org/index.php?title=List_of_modules_id
		// then update this value to your registered ID before submitting to DoliStore.
		$this->numero = 510000;

		// Family: crm, financial, hr, projects, products, ecm, technic, interface, other
		$this->family = "crm";
		$this->module_position = '50';

		// Module name (no spaces), used if translation string 'ModuleXXXName' not found
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleWarrantySvcDesc';
		$this->version = '1.46.8';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';

		// Module parts (triggers, login, substitutions, menus, tpl, hooks, modulebuilder, cronjobs, unittest)
		$this->module_parts = array(
			'models' => 1,    // PDF/document models under core/modules/warrantysvc
			'triggers' => 1,  // triggers/ directory enabled
			'login' => 0,
			'substitutions' => 1,
			'menus' => 0,
			'hooks' => array('data' => array('elementproperties', 'productcard', 'productstatsinvoice', 'commonobject', 'ordercard', 'notification', 'emailtemplates', 'main'), 'entity' => '0'),
			'apis' => 1,      // api/ directory enabled (registers via Luracast)
		);

		// Data dirs created when module enabled
		$this->dirs = array(
			"/warrantysvc/temp",
		);

		// Config page
		$this->config_page_url = array("setup.php@warrantysvc");

		// Dependencies
		$this->hidden = false;
		$this->depends = array('modSociete', 'modProduct', 'modStock');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("warrantysvc@warrantysvc");
		$this->phpmin = array(7, 0);
		$this->need_dolibarr_version = array(16, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants saved in llx_const
		$this->const = array();

		// New pages on existing object tabs
		$this->tabs = array();
		$this->tabs[] = array('data' => 'project:+warrantysvc_svcrequest:ServiceRequests,technic,/warrantysvc/class/svcrequest.class.php,countForProject:warrantysvc@warrantysvc:$user->hasRight(\'warrantysvc\', \'svcrequest\', \'read\'):/warrantysvc/list.php?projectid=__ID__');
		$this->tabs[] = array('data' => 'thirdparty:+warrantysvc_warranties:Warranties,bill,/warrantysvc/class/svcwarranty.class.php,countForThirdparty:warrantysvc@warrantysvc:$user->hasRight(\'warrantysvc\', \'svcwarranty\', \'read\'):/warrantysvc/warranty_list.php?socid=__ID__');

		// Dictionaries
		$this->dictionaries = array();

		// Boxes / Widgets
		$this->boxes = array();

		// Cronjobs
		$this->cronjobs = array(
			0 => array(
				'label'         => 'CheckOverdueRMAReturns',
				'jobtype'       => 'method',
				'class'         => '/warrantysvc/class/svcrequest.class.php',
				'objectname'    => 'SvcRequest',
				'method'        => 'checkOverdueReturns',
				'parameters'    => '',
				'comment'       => 'CheckOverdueRMAReturnsDesc',
				'frequency'     => 1,
				'unitfrequency' => 86400,
				'status'        => 0,
				'test'          => 'isModEnabled("warrantysvc")',
				'priority'      => 50,
			),
		);

		// Permissions defined below
		$this->rights = array();
		$this->rights_class = 'warrantysvc';

		$r = 0;

		// Service Request permissions — object = 'svcrequest'
		$r++;
		$this->rights[$r][0] = 510001;
		$this->rights[$r][1] = 'PermissionReadSvcRequests';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'svcrequest';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = 510002;
		$this->rights[$r][1] = 'PermissionWriteSvcRequests';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'svcrequest';
		$this->rights[$r][5] = 'write';

		$r++;
		$this->rights[$r][0] = 510003;
		$this->rights[$r][1] = 'PermissionDeleteSvcRequests';
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'svcrequest';
		$this->rights[$r][5] = 'delete';

		$r++;
		$this->rights[$r][0] = 510004;
		$this->rights[$r][1] = 'PermissionValidateSvcRequests';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'svcrequest';
		$this->rights[$r][5] = 'validate';

		$r++;
		$this->rights[$r][0] = 510005;
		$this->rights[$r][1] = 'PermissionCloseSvcRequests';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'svcrequest';
		$this->rights[$r][5] = 'close';

		// Supplier RMA permissions
		$r++;
		$this->rights[$r][0] = 510021;
		$this->rights[$r][1] = 'PermissionReadSupplierRma';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'supplierrma';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = 510022;
		$this->rights[$r][1] = 'PermissionWriteSupplierRma';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'supplierrma';
		$this->rights[$r][5] = 'write';

		$r++;
		$this->rights[$r][0] = 510023;
		$this->rights[$r][1] = 'PermissionDeleteSupplierRma';
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'supplierrma';
		$this->rights[$r][5] = 'delete';

		// Supplier Return permissions
		$r++;
		$this->rights[$r][0] = 510031;
		$this->rights[$r][1] = 'PermissionReadSupplierReturn';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'supplierreturn';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = 510032;
		$this->rights[$r][1] = 'PermissionWriteSupplierReturn';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'supplierreturn';
		$this->rights[$r][5] = 'write';

		$r++;
		$this->rights[$r][0] = 510033;
		$this->rights[$r][1] = 'PermissionDeleteSupplierReturn';
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'supplierreturn';
		$this->rights[$r][5] = 'delete';

		// Warranty permissions
		$r++;
		$this->rights[$r][0] = 510011;
		$this->rights[$r][1] = 'PermissionReadWarranties';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'svcwarranty';
		$this->rights[$r][5] = 'read';

		$r++;
		$this->rights[$r][0] = 510012;
		$this->rights[$r][1] = 'PermissionWriteWarranties';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'svcwarranty';
		$this->rights[$r][5] = 'write';

		$r++;
		$this->rights[$r][0] = 510013;
		$this->rights[$r][1] = 'PermissionDeleteWarranties';
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'svcwarranty';
		$this->rights[$r][5] = 'delete';

		// Main menu entries
		$this->menu = array();
		$r = 0;

		// WarrantySvc is integrated into the Products top menu. It gets its own
		// section in the Products left navigation instead of creating another top
		// navigation entry.
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=products',
			'type'     => 'left',
			'titre'    => 'WarrantySvc',
			'prefix'   => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'warrantysvc',
			'url'      => '/warrantysvc/list.php?mainmenu=products&leftmenu=warrantysvc_list',
			'langs'    => 'warrantysvc@warrantysvc',
			'position' => 900,
			'enabled'  => 'isModEnabled("warrantysvc")',
			'perms'    => '$user->hasRight("warrantysvc", "svcrequest", "read") || $user->hasRight("warrantysvc", "svcwarranty", "read") || $user->hasRight("warrantysvc", "supplierreturn", "read")',
			'target'   => '',
			'user'     => 0,
		);
		$r++;

		// Service Requests
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=warrantysvc',
			'type'     => 'left',
			'titre'    => 'SvcRequests',
			'prefix'   => img_picto('', 'technic', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'warrantysvc_list',
			'url'      => '/warrantysvc/list.php?mainmenu=products&leftmenu=warrantysvc_list',
			'langs'    => 'warrantysvc@warrantysvc',
			'position' => 910,
			'enabled'  => 'isModEnabled("warrantysvc")',
			'perms'    => '$user->hasRight("warrantysvc", "svcrequest", "read")',
			'target'   => '',
			'user'     => 0,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=warrantysvc_list',
			'type'     => 'left',
			'titre'    => 'NewSvcRequest',
			'prefix'   => img_picto('', 'add', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'warrantysvc_new',
			'url'      => '/warrantysvc/card.php?action=create&mainmenu=products&leftmenu=warrantysvc_new',
			'langs'    => 'warrantysvc@warrantysvc',
			'position' => 920,
			'enabled'  => 'isModEnabled("warrantysvc")',
			'perms'    => '$user->hasRight("warrantysvc", "svcrequest", "write")',
			'target'   => '',
			'user'     => 0,
		);
		$r++;

		// Warranties
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=warrantysvc',
			'type'     => 'left',
			'titre'    => 'Warranties',
			'prefix'   => img_picto('', 'bill', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'warrantysvc_warranty_list',
			'url'      => '/warrantysvc/warranty_list.php?mainmenu=products&leftmenu=warrantysvc_warranty_list',
			'langs'    => 'warrantysvc@warrantysvc',
			'position' => 930,
			'enabled'  => 'isModEnabled("warrantysvc")',
			'perms'    => '$user->hasRight("warrantysvc", "svcwarranty", "read")',
			'target'   => '',
			'user'     => 0,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=warrantysvc_warranty_list',
			'type'     => 'left',
			'titre'    => 'NewWarranty',
			'prefix'   => img_picto('', 'add', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'warrantysvc_warranty_new',
			'url'      => '/warrantysvc/warranty_card.php?action=create&mainmenu=products&leftmenu=warrantysvc_warranty_new',
			'langs'    => 'warrantysvc@warrantysvc',
			'position' => 940,
			'enabled'  => 'isModEnabled("warrantysvc")',
			'perms'    => '$user->hasRight("warrantysvc", "svcwarranty", "write")',
			'target'   => '',
			'user'     => 0,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=warrantysvc_warranty_list',
			'type'     => 'left',
			'titre'    => 'WarrantyTypes',
			'prefix'   => img_picto('', 'setup', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'warrantysvc_warranty_types',
			'url'      => '/warrantysvc/warranty_type_list.php?mainmenu=products&leftmenu=warrantysvc_warranty_types',
			'langs'    => 'warrantysvc@warrantysvc',
			'position' => 950,
			'enabled'  => 'isModEnabled("warrantysvc") && (getDolGlobalString("WARRANTYSVC_DURATION_SOURCE") == "warranty_type" || (getDolGlobalString("WARRANTYSVC_DURATION_SOURCE") == "" && getDolGlobalString("WARRANTYSVC_PRODUCT_WARRANTY_MONTHS_FIELD") == ""))',
			'perms'    => '$user->hasRight("warrantysvc", "svcwarranty", "read")',
			'target'   => '',
			'user'     => 0,
		);
		$r++;

		// Supplier Returns
		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=warrantysvc',
			'type'     => 'left',
			'titre'    => 'SupplierReturns',
			'prefix'   => img_picto('', 'shipment', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'warrantysvc_supplier_return_list',
			'url'      => '/warrantysvc/supplier_return_list.php?mainmenu=products&leftmenu=warrantysvc_supplier_return_list',
			'langs'    => 'warrantysvc@warrantysvc',
			'position' => 960,
			'enabled'  => 'isModEnabled("warrantysvc")',
			'perms'    => '$user->hasRight("warrantysvc", "supplierreturn", "read")',
			'target'   => '',
			'user'     => 0,
		);
		$r++;

		$this->menu[$r] = array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=warrantysvc_supplier_return_list',
			'type'     => 'left',
			'titre'    => 'NewSupplierReturn',
			'prefix'   => img_picto('', 'add', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products',
			'leftmenu' => 'warrantysvc_supplier_return_new',
			'url'      => '/warrantysvc/supplier_return_card.php?action=create&mainmenu=products&leftmenu=warrantysvc_supplier_return_new',
			'langs'    => 'warrantysvc@warrantysvc',
			'position' => 970,
			'enabled'  => 'isModEnabled("warrantysvc")',
			'perms'    => '$user->hasRight("warrantysvc", "supplierreturn", "write")',
			'target'   => '',
			'user'     => 0,
		);
		$r++;
	}

	/**
	 * Check whether a module table exists without issuing an erroring DESCRIBE.
	 *
	 * @param  string $table Full table name including Dolibarr prefix
	 * @return bool
	 */
	private function tableExists($table)
	{
		$tables = $this->db->DDLListTables($this->db->database_name, $table);
		return in_array($table, $tables, true);
	}

	/**
	 * Return database column types keyed by column name.
	 *
	 * DDLInfoTable() exposes the same first two fields (name/type) on MySQL and
	 * PostgreSQL, which is enough for the legacy service-log migration.
	 *
	 * @param  string $table Full table name including Dolibarr prefix
	 * @return array<string,string>
	 */
	private function getColumnTypes($table)
	{
		$result = array();
		foreach ((array) $this->db->DDLInfoTable($table) as $row) {
			if (isset($row[0])) {
				$result[(string) $row[0]] = isset($row[1]) ? strtolower((string) $row[1]) : '';
			}
		}
		return $result;
	}

	/**
	 * Upgrade an existing upstream/older WarrantySvc schema to the current one.
	 *
	 * Every change in this method is introspection-gated and therefore safe to
	 * run repeatedly. Legacy one-shot SQL migration files are deliberately not
	 * used here: Dolibarr's _load_tables() scans every llx_*.sql on each module
	 * activation, so destructive migrations must never live in that directory.
	 *
	 * @return int 1 if OK, -1 on a required schema change failure
	 */
	private function upgradeForkSchema()
	{
		$warrantyTable = MAIN_DB_PREFIX.'svc_warranty';
		$typeTable = MAIN_DB_PREFIX.'svc_warranty_type';
		$requestTable = MAIN_DB_PREFIX.'svc_request';
		$serviceLogTable = MAIN_DB_PREFIX.'svc_service_log';
		$supplierRmaTable = MAIN_DB_PREFIX.'svc_supplier_rma';
		$supplierReturnLineTable = MAIN_DB_PREFIX.'svc_supplier_return_line';

		if ($this->tableExists($warrantyTable)) {
			$columns = $this->getColumnTypes($warrantyTable);
			$fields = array(
				'covered_qty' => array('type' => 'decimal', 'value' => '24,8', 'default' => '1'),
				'fk_expeditiondet' => array('type' => 'int'),
				'coverage_months' => array('type' => 'int'),
			);
			foreach ($fields as $fieldName => $fieldDesc) {
				if (!isset($columns[$fieldName]) && $this->db->DDLAddField($warrantyTable, $fieldName, $fieldDesc) < 0) {
					return -1;
				}
			}

			// Upstream required a serial number; the fork also supports line-level
			// warranties for products without LOT/SN tracking.
			$resSerial = $this->db->DDLDescTable($warrantyTable, 'serial_number');
			$serialField = $resSerial ? $this->db->fetch_object($resSerial) : null;
			$serialNeedsNullable = false;
			if ($serialField) {
				if (isset($serialField->Null)) {
					$serialNeedsNullable = (strtoupper((string) $serialField->Null) === 'NO');
				} elseif ($this->db->type === 'pgsql') {
					// PostgreSQL DDLDescTable does not expose nullability in the same shape.
					$serialNeedsNullable = true;
				}
			}
			if ($serialNeedsNullable) {
				$serialDesc = array('type' => 'varchar', 'value' => '128');
				if ($this->db->DDLUpdateField($warrantyTable, 'serial_number', $serialDesc) < 0) {
					return -1;
				}
			}

			// Upstream used a unique serial index. A returned unit can legitimately
			// be sold again, so uniqueness is enforced by shipment origin instead.
			if ($this->db->type === 'mysqli') {
				$resIndex = $this->db->query("SHOW INDEX FROM ".$warrantyTable." WHERE Key_name = 'uk_svc_warranty_serial'");
				if ($resIndex && $this->db->fetch_object($resIndex)) {
					if (!$this->db->query("ALTER TABLE ".$warrantyTable." DROP INDEX uk_svc_warranty_serial")) {
						return -1;
					}
				}
			} elseif ($this->db->type === 'pgsql') {
				$sqlIndex = "SELECT 1 FROM pg_indexes WHERE schemaname = 'public'";
				$sqlIndex .= " AND tablename = '".$this->db->escape($warrantyTable)."'";
				$sqlIndex .= " AND indexname = 'uk_svc_warranty_serial'";
				$resIndex = $this->db->query($sqlIndex);
				if ($resIndex && $this->db->fetch_object($resIndex)) {
					if (!$this->db->query("DROP INDEX uk_svc_warranty_serial")) {
						return -1;
					}
				}
			}
		}

		if ($this->tableExists($typeTable)) {
			$columns = $this->getColumnTypes($typeTable);
			foreach (array('coverage_terms', 'exclusions') as $fieldName) {
				if (!isset($columns[$fieldName]) && $this->db->DDLAddField($typeTable, $fieldName, array('type' => 'text')) < 0) {
					return -1;
				}
			}
		}

		// v1.32: service requests gained a security-seal field.
		if ($this->tableExists($requestTable)) {
			$columns = $this->getColumnTypes($requestTable);
			if (!isset($columns['seal_number']) && $this->db->DDLAddField($requestTable, 'seal_number', array('type'=>'varchar', 'value'=>'128')) < 0) {
				return -1;
			}

			// Historical versions used bare/mismatching linked-object type names.
			// These normalisations are idempotent and preserve all link IDs.
			$linkUpdates = array(
				"UPDATE ".MAIN_DB_PREFIX."element_element SET sourcetype = 'shipping' WHERE sourcetype = 'expedition' AND targettype IN ('warrantysvc_svcwarranty', 'svcwarranty')",
				"UPDATE ".MAIN_DB_PREFIX."element_element SET targettype = 'shipping' WHERE targettype = 'expedition' AND sourcetype IN ('warrantysvc_svcwarranty', 'svcwarranty')",
				"UPDATE ".MAIN_DB_PREFIX."element_element SET sourcetype = 'warrantysvc_svcwarranty' WHERE sourcetype = 'svcwarranty'",
				"UPDATE ".MAIN_DB_PREFIX."element_element SET targettype = 'warrantysvc_svcwarranty' WHERE targettype = 'svcwarranty'",
				"UPDATE ".MAIN_DB_PREFIX."element_element SET sourcetype = 'warrantysvc_svcrequest' WHERE sourcetype = 'svcrequest'",
				"UPDATE ".MAIN_DB_PREFIX."element_element SET targettype = 'warrantysvc_svcrequest' WHERE targettype = 'svcrequest'",
			);
			foreach ($linkUpdates as $sql) {
				if (!$this->db->query($sql)) {
					return -1;
				}
			}
		}

		// Legacy service-log releases stored condition_status as text. Convert it
		// exactly once, based on the actual column type, and ensure import_key.
		if ($this->tableExists($serviceLogTable)) {
			$columns = $this->getColumnTypes($serviceLogTable);

			// Clean up a temporary column left by an interrupted historical upgrade.
			if (isset($columns['condition_status_int']) && isset($columns['condition_status'])) {
				if ($this->db->DDLDropField($serviceLogTable, 'condition_status_int') < 0) {
					return -1;
				}
				unset($columns['condition_status_int']);
			}

			if (!isset($columns['condition_status'])) {
				if ($this->db->DDLAddField($serviceLogTable, 'condition_status', array('type'=>'smallint', 'default'=>'0')) < 0) {
					return -1;
				}
			} elseif (strpos($columns['condition_status'], 'char') !== false || strpos($columns['condition_status'], 'text') !== false) {
				if ($this->db->type === 'pgsql') {
					$sql = "ALTER TABLE ".$serviceLogTable." ALTER COLUMN condition_status TYPE SMALLINT USING (CASE LOWER(condition_status::text)";
					$sql .= " WHEN 'good' THEN 0 WHEN 'fair' THEN 1 WHEN 'poor' THEN 2 WHEN 'scrap' THEN 3";
					$sql .= " WHEN '0' THEN 0 WHEN '1' THEN 1 WHEN '2' THEN 2 WHEN '3' THEN 3 ELSE 0 END)";
					if (!$this->db->query($sql)) {
						return -1;
					}
					if (!$this->db->query("ALTER TABLE ".$serviceLogTable." ALTER COLUMN condition_status SET DEFAULT 0")) {
						return -1;
					}
				} else {
					$sql = "UPDATE ".$serviceLogTable." SET condition_status = CASE LOWER(condition_status)";
					$sql .= " WHEN 'good' THEN '0' WHEN 'fair' THEN '1' WHEN 'poor' THEN '2' WHEN 'scrap' THEN '3'";
					$sql .= " WHEN '0' THEN '0' WHEN '1' THEN '1' WHEN '2' THEN '2' WHEN '3' THEN '3' ELSE '0' END";
					if (!$this->db->query($sql)) {
						return -1;
					}
					if ($this->db->DDLUpdateField($serviceLogTable, 'condition_status', array('type'=>'smallint', 'default'=>'0')) < 0) {
						return -1;
					}
				}
			}

			$columns = $this->getColumnTypes($serviceLogTable);
			if (!isset($columns['import_key']) && $this->db->DDLAddField($serviceLogTable, 'import_key', array('type'=>'varchar', 'value'=>'14')) < 0) {
				return -1;
			}
		}

		// 1.46: keep the compensating stock movement so physical shipment
		// corrections remain fully traceable without deleting stock history.
		if ($this->tableExists($supplierReturnLineTable)) {
			$columns = $this->getColumnTypes($supplierReturnLineTable);
			if (!isset($columns['fk_stock_movement_reversal']) && $this->db->DDLAddField($supplierReturnLineTable, 'fk_stock_movement_reversal', array('type'=>'int')) < 0) {
				return -1;
			}
		}

		// 1.44: Supplier RMA gained quantity so LOT based RMAs can represent
		// more than one unit while serial-numbered RMAs still use qty=1.
		if ($this->tableExists($supplierRmaTable)) {
			$columns = $this->getColumnTypes($supplierRmaTable);
			if (!isset($columns['qty']) && $this->db->DDLAddField($supplierRmaTable, 'qty', array('type'=>'decimal', 'value'=>'24,8', 'default'=>'1')) < 0) {
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Register native Dolibarr contact roles for Service Requests.
	 *
	 * Rows are updated in place so existing llx_element_contact relations keep
	 * their fk_c_type_contact values across module upgrades/re-enables.
	 *
	 * @return int 1 if OK, -1 on error
	 */
	private function syncContactTypeCatalog()
	{
		$types = array(
			array('svcrequest', 'internal', 'SERVICE_MANAGER', 'Service Request handler', 10),
			array('svcrequest', 'external', 'CUSTOMER_SERVICE', 'Customer service contact', 20),
			array('svcsupplierrma', 'internal', 'SERVICE_MANAGER', 'Supplier RMA handler', 10),
			array('svcsupplierrma', 'external', 'SUPPLIER_SERVICE', 'Supplier service contact', 20),
			array('svcsupplierreturn', 'internal', 'SERVICE_MANAGER', 'Supplier Return handler', 10),
			array('svcsupplierreturn', 'external', 'SUPPLIER_RETURN', 'Supplier return contact', 20),
		);

		foreach ($types as $type) {
			list($element, $source, $code, $label, $position) = $type;
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_contact";
			$sql .= " WHERE element = '".$this->db->escape($element)."'";
			$sql .= " AND source = '".$this->db->escape($source)."'";
			$sql .= " AND code = '".$this->db->escape($code)."'";
			$resql = $this->db->query($sql);
			if (!$resql) {
				return -1;
			}
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);

			if ($obj) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."c_type_contact SET";
				$sql .= " libelle = '".$this->db->escape($label)."'";
				$sql .= ", active = 1";
				$sql .= ", module = 'warrantysvc'";
				$sql .= ", position = ".((int) $position);
				$sql .= " WHERE rowid = ".((int) $obj->rowid);
			} else {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_type_contact";
				$sql .= " (element, source, code, libelle, active, module, position) VALUES (";
				$sql .= "'".$this->db->escape($element)."',";
				$sql .= "'".$this->db->escape($source)."',";
				$sql .= "'".$this->db->escape($code)."',";
				$sql .= "'".$this->db->escape($label)."',1,'warrantysvc',".((int) $position).")";
			}

			if (!$this->db->query($sql)) {
				return -1;
			}
		}

		return 1;
	}

	/**
	 * Register WarrantySvc trigger codes in Dolibarr's central action catalog.
	 *
	 * Existing rows are updated in place instead of deleted/reinserted so
	 * llx_notify_def subscriptions keep their fk_action references.
	 *
	 * @return int 1 if OK, -1 on error
	 */
	private function syncNotificationEventCatalog()
	{
		$events = array(
			array('WARRANTYSVC_ASSIGNED', 'Service Request assigned', 'Executed when a WarrantySvc Service Request is assigned to a user', 'svcrequest', 511),
			array('SVCWARRANTY_CREATE', 'Warranty created', 'Executed when a WarrantySvc warranty record is created', 'svcwarranty', 521),
		);

		foreach ($events as $event) {
			list($code, $label, $description, $elementtype, $rang) = $event;
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_action_trigger";
			$sql .= " WHERE code = '".$this->db->escape($code)."'";
			$resql = $this->db->query($sql);
			if (!$resql) {
				return -1;
			}
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);

			if ($obj) {
				$sql = "UPDATE ".MAIN_DB_PREFIX."c_action_trigger SET";
				$sql .= " label = '".$this->db->escape($label)."'";
				$sql .= ", description = '".$this->db->escape($description)."'";
				$sql .= ", elementtype = '".$this->db->escape($elementtype)."'";
				$sql .= ", rang = ".((int) $rang);
				$sql .= " WHERE rowid = ".((int) $obj->rowid);
			} else {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_action_trigger";
				$sql .= " (code, label, description, elementtype, rang) VALUES (";
				$sql .= "'".$this->db->escape($code)."',";
				$sql .= "'".$this->db->escape($label)."',";
				$sql .= "'".$this->db->escape($description)."',";
				$sql .= "'".$this->db->escape($elementtype)."',";
				$sql .= ((int) $rang).")";
			}

			if (!$this->db->query($sql)) {
				return -1;
			}
		}

		return 1;
	}


	/**
	 * Ensure an editable Dolibarr email template exists for Supplier Returns.
	 *
	 * Existing templates are never overwritten. We only seed the language when
	 * the current entity has no Supplier Return template for that language.
	 *
	 * @return int 1 on success, -1 on database error
	 */
	private function syncSupplierReturnEmailTemplates()
	{
		global $conf;

		$templates = array(
			'hu_HU' => array(
				'label' => 'Beszállítói visszáru bejelentés',
				'topic' => 'Beszállítói visszáru - __SUPPLIER_RETURN_REF__',
				'content' => 'Tisztelt Partnerünk!<br><br>'
					.'Mellékelten küldjük a <strong>__SUPPLIER_RETURN_REF__</strong> azonosítójú visszáru bejelentőt.<br>'
					.'Visszaküldés oka: __SUPPLIER_RETURN_REASON__<br><br>'
					.'Visszaküldött tételek:<br>__SUPPLIER_RETURN_LINES__<br><br>'
					.'Üdvözlettel,<br>__SENDEREMAIL_SIGNATURE__',
			),
			'en_US' => array(
				'label' => 'Supplier Return notification',
				'topic' => 'Supplier Return - __SUPPLIER_RETURN_REF__',
				'content' => 'Dear Partner,<br><br>'
					.'Please find attached Supplier Return <strong>__SUPPLIER_RETURN_REF__</strong>.<br>'
					.'Return reason: __SUPPLIER_RETURN_REASON__<br><br>'
					.'Returned items:<br>__SUPPLIER_RETURN_LINES__<br><br>'
					.'Kind regards,<br>__SENDEREMAIL_SIGNATURE__',
			),
		);

		foreach ($templates as $lang => $tpl) {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_email_templates";
			$sql .= " WHERE entity = ".((int) $conf->entity);
			$sql .= " AND type_template = 'svcsupplierreturn'";
			$sql .= " AND lang = '".$this->db->escape($lang)."'";
			$sql .= " AND active = 1";
			$sql .= $this->db->plimit(1);
			$resql = $this->db->query($sql);
			if (!$resql) {
				return -1;
			}
			$exists = (bool) $this->db->fetch_object($resql);
			$this->db->free($resql);
			if ($exists) {
				continue;
			}

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_email_templates";
			$sql .= " (entity, module, type_template, lang, private, fk_user, datec, label, position, defaultfortype, enabled, active, topic, joinfiles, content)";
			$sql .= " VALUES (";
			$sql .= ((int) $conf->entity);
			$sql .= ", 'warrantysvc'";
			$sql .= ", 'svcsupplierreturn'";
			$sql .= ", '".$this->db->escape($lang)."'";
			$sql .= ", 0, NULL";
			$sql .= ", '".$this->db->idate(dol_now())."'";
			$sql .= ", '".$this->db->escape($tpl['label'])."'";
			$sql .= ", 10, 1";
			$sql .= ", '1'";
			$sql .= ", 1";
			$sql .= ", '".$this->db->escape($tpl['topic'])."'";
			$sql .= ", 1";
			$sql .= ", '".$this->db->escape($tpl['content'])."'";
			$sql .= ")";
			if (!$this->db->query($sql)) {
				return -1;
			}
		}

		return 1;
	}


	/**
	 * Backfill Supplier Return traceability introduced in 1.46.
	 *
	 * Existing shipped records already have authoritative Dolibarr stock
	 * movements. Re-use those rows to seed product links, product Agenda
	 * events and missing lifecycle stock events without changing stock.
	 *
	 * @return int 1 on success, -1 on required backfill failure
	 */
	private function backfillSupplierReturnTraceability()
	{
		global $conf, $langs, $user;

		$langs->load('warrantysvc@warrantysvc');
		dol_include_once('/warrantysvc/class/svcsupplierreturn.class.php');
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

		$sql = "SELECT DISTINCT r.rowid";
		$sql .= " FROM ".MAIN_DB_PREFIX."svc_supplier_return r";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."svc_supplier_return_line l ON l.fk_supplier_return = r.rowid";
		$sql .= " WHERE r.entity = ".((int) $conf->entity);
		$sql .= " AND l.fk_stock_movement_out IS NOT NULL";
		$sql .= " AND l.fk_stock_movement_out > 0";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}

		$returnIds = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$returnIds[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		foreach ($returnIds as $returnId) {
			$return = new SvcSupplierReturn($this->db);
			if ($return->fetch($returnId) <= 0) {
				return -1;
			}

			// Dolibarr's stock movement origin renderer resolves external objects
			// in Class@Module form. Normalize legacy metadata only; quantities,
			// warehouses, batches and movement rowids are left untouched.
			$sql = "UPDATE ".MAIN_DB_PREFIX."stock_mouvement";
			$sql .= " SET origintype = '".$this->db->escape(SvcSupplierReturn::STOCK_ORIGIN_TYPE)."'";
			$sql .= " WHERE fk_origin = ".((int) $return->id);
			$sql .= " AND origintype = '".$this->db->escape(SvcSupplierReturn::LEGACY_STOCK_ORIGIN_TYPE)."'";
			if (!$this->db->query($sql)) {
				return -1;
			}

			$actor = null;
			$actorId = (int) (!empty($return->fk_user_modif) ? $return->fk_user_modif : $return->fk_user_creat);
			if ($actorId > 0) {
				$tmpUser = new User($this->db);
				if ($tmpUser->fetch($actorId) > 0) {
					$actor = $tmpUser;
				}
			}
			if (!is_object($actor)) {
				$actor = $user;
			}
			if (!is_object($actor) || empty($actor->id)) {
				continue;
			}

			if ($return->ensureProductLinks($actor, 1) < 0) {
				return -1;
			}

			$shipDate = !empty($return->date_shipped) ? $return->date_shipped : $return->date_creation;
			if ($return->ensureProductAgendaEvents($actor, 'ship', $shipDate) < 0) {
				return -1;
			}

			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."svc_supplier_return_log";
			$sql .= " WHERE fk_supplier_return = ".((int) $return->id);
			$sql .= " AND event_code = 'STOCKOUT'";
			$sql .= $this->db->plimit(1);
			$check = $this->db->query($sql);
			if (!$check) {
				return -1;
			}
			$hasStockOutLog = (bool) $this->db->fetch_object($check);
			$this->db->free($check);

			if (!$hasStockOutLog) {
				$movementIds = array();
				foreach ($return->lines as $line) {
					if ((int) $line->fk_stock_movement_out > 0) {
						$movementIds[(int) $line->fk_stock_movement_out] = '#'.((int) $line->fk_stock_movement_out);
					}
				}
				$note = $langs->transnoentitiesnoconv(
					'SupplierReturnStockOutAuditNote',
					!empty($movementIds) ? implode(', ', array_values($movementIds)) : '—'
				);
				if ($return->logEvent('STOCKOUT', $return->status, $return->status, $note, $actor, $shipDate) < 0) {
					return -1;
				}
			}

			$hasReversal = false;
			$reversalIds = array();
			foreach ($return->lines as $line) {
				if ((int) $line->fk_stock_movement_reversal > 0) {
					$hasReversal = true;
					$reversalIds[(int) $line->fk_stock_movement_reversal] = '#'.((int) $line->fk_stock_movement_reversal);
				}
			}
			if ($hasReversal) {
				$sql = "SELECT rowid, date_event FROM ".MAIN_DB_PREFIX."svc_supplier_return_log";
				$sql .= " WHERE fk_supplier_return = ".((int) $return->id);
				$sql .= " AND event_code = 'REVERSE'";
				$sql .= " ORDER BY rowid DESC";
				$sql .= $this->db->plimit(1);
				$check = $this->db->query($sql);
				if (!$check) {
					return -1;
				}
				$reverseLog = $this->db->fetch_object($check);
				$this->db->free($check);

				$reverseDate = $reverseLog && !empty($reverseLog->date_event)
					? $this->db->jdate($reverseLog->date_event)
					: dol_now();

				if (!$reverseLog) {
					$note = $langs->transnoentitiesnoconv(
						'SupplierReturnStockRestoreAuditNote',
						implode(', ', array_values($reversalIds))
					);
					if ($return->logEvent('REVERSE', $return->status, $return->status, $note, $actor, $reverseDate) < 0) {
						return -1;
					}
				}
				if ($return->ensureProductAgendaEvents($actor, 'reverse', $reverseDate) < 0) {
					return -1;
				}
			}
		}

		return 1;
	}


	/**
	 * Function called when module is enabled.
	 * Loads SQL tables from sql/ directory using standard Dolibarr mechanism.
	 *
	 * @param  string $options Options
	 * @return int             1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		// _load_tables() is intended to bootstrap missing module tables, but it
		// scans every llx_*.sql file. Re-running it against a complete existing
		// schema produces duplicate CREATE/INDEX errors and historically also
		// re-ran destructive legacy migration files. Only bootstrap when at least
		// one required module table is genuinely missing.
		$requiredTables = array(
			MAIN_DB_PREFIX.'svc_request',
			MAIN_DB_PREFIX.'svc_request_extrafields',
			MAIN_DB_PREFIX.'svc_request_line',
			MAIN_DB_PREFIX.'svc_service_log',
			MAIN_DB_PREFIX.'svc_supplier_return',
			MAIN_DB_PREFIX.'svc_supplier_return_line',
			MAIN_DB_PREFIX.'svc_supplier_return_log',
			MAIN_DB_PREFIX.'svc_supplier_rma',
			MAIN_DB_PREFIX.'svc_supplier_rma_log',
			MAIN_DB_PREFIX.'svc_troubleshoot',
			MAIN_DB_PREFIX.'svc_warranty',
			MAIN_DB_PREFIX.'svc_warranty_extrafields',
			MAIN_DB_PREFIX.'svc_warranty_type',
			MAIN_DB_PREFIX.'warrantysvc_product_default',
		);
		$needsBootstrap = false;
		foreach ($requiredTables as $table) {
			if (!$this->tableExists($table)) {
				$needsBootstrap = true;
				break;
			}
		}

		if ($needsBootstrap) {
			$result = $this->_load_tables('/warrantysvc/sql/');
			if ($result < 0) {
				return -1;
			}
		}

		if ($this->upgradeForkSchema() < 0) {
			return -1;
		}
		if ($this->syncContactTypeCatalog() < 0) {
			return -1;
		}
		if ($this->syncNotificationEventCatalog() < 0) {
			return -1;
		}
		if ($this->syncSupplierReturnEmailTemplates() < 0) {
			return -1;
		}
		if ($this->backfillSupplierReturnTraceability() < 0) {
			return -1;
		}
		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string $options Options
	 * @return int             1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
