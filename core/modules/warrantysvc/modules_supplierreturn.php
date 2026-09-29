<?php
/* Copyright (C) 2026 DPG Supply */

require_once DOL_DOCUMENT_ROOT.'/core/class/commondocgenerator.class.php';

/**
 * Supplier Return PDF model catalog used by Dolibarr's standard document block.
 */
abstract class ModelePDFSupplierReturn extends CommonDocGenerator
{
	/**
	 * Return available Supplier Return document models.
	 *
	 * @param DoliDB $db Database handler
	 * @param int $maxfilenamelength Unused compatibility argument
	 * @return array<string,string>
	 */
	public static function liste_modeles($db, $maxfilenamelength = 0)
	{
		global $langs;
		$langs->load('warrantysvc@warrantysvc');

		return array(
			'supplierreturn_standard' => $langs->transnoentitiesnoconv('SupplierReturnPdfStandardDesc'),
		);
	}
}
