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
		$this->version = '1.36.4';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'technic';

		// Module parts (triggers, login, substitutions, menus, tpl, hooks, modulebuilder, cronjobs, unittest)
		$this->module_parts = array(
			'models' => 1,    // PDF/document models under core/modules/warrantysvc
			'triggers' => 1,  // triggers/ directory enabled
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'hooks' => array('data' => array('elementproperties', 'productcard', 'commonobject', 'ordercard'), 'entity' => '0'),
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
			'perms'    => '$user->hasRight("warrantysvc", "svcrequest", "read") || $user->hasRight("warrantysvc", "svcwarranty", "read")',
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
	}

	/**
	 * Upgrade an existing upstream WarrantySvc schema to the fork schema.
	 *
	 * Fresh installs already contain these fields in the base SQL files.
	 * Existing installs are upgraded here before _load_tables() runs so that
	 * subsequent key creation never references missing columns.
	 *
	 * @return int 1 if OK, -1 on a required schema change failure
	 */
	private function upgradeForkSchema()
	{
		$warrantyTable = MAIN_DB_PREFIX.'svc_warranty';
		$typeTable = MAIN_DB_PREFIX.'svc_warranty_type';

		$warrantyDesc = $this->db->DDLDescTable($warrantyTable);
		if ($warrantyDesc && $this->db->num_rows($warrantyDesc) > 0) {
			$fields = array(
				'covered_qty' => array('type' => 'decimal', 'value' => '24,8', 'default' => '1'),
				'fk_expeditiondet' => array('type' => 'int'),
				'coverage_months' => array('type' => 'int'),
			);
			foreach ($fields as $fieldName => $fieldDesc) {
				$res = $this->db->DDLDescTable($warrantyTable, $fieldName);
				$exists = $res && $this->db->fetch_object($res);
				if (!$exists && $this->db->DDLAddField($warrantyTable, $fieldName, $fieldDesc) < 0) {
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
			}
		}

		$typeDesc = $this->db->DDLDescTable($typeTable);
		if ($typeDesc && $this->db->num_rows($typeDesc) > 0) {
			foreach (array('coverage_terms', 'exclusions') as $fieldName) {
				$res = $this->db->DDLDescTable($typeTable, $fieldName);
				$exists = $res && $this->db->fetch_object($res);
				if (!$exists && $this->db->DDLAddField($typeTable, $fieldName, array('type' => 'text')) < 0) {
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
		if ($this->upgradeForkSchema() < 0) {
			return -1;
		}

		$result = $this->_load_tables('/warrantysvc/sql/');
		if ($result < 0) {
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
