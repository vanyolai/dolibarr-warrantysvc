<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * Standard Warranty Letter numbering model.
 *
 * Format: WL-YYYY-MMUID
 * UID is continuous for the lifetime of the table and does not reset on
 * month/year boundaries. It uses the database rowid as the monotonic unique
 * sequence, padded to at least four digits.
 */

dol_include_once('/warrantysvc/core/modules/warrantysvc/modules_warrantysvc.php');

class mod_warrantyletter_standard extends ModeleNumRefWarrantyLetter
{
	public $version = '1.0.0';
	public $name = 'standard';
	public $prefix = 'WL';
	public $error = '';

	public function info($langs)
	{
		return $langs->trans('WarrantyLetterNumberingStandardDesc');
	}

	public function getExample()
	{
		return 'WL-2026-100001';
	}

	public function canBeActivated($object = null)
	{
		return true;
	}

	public function getNextValue($objsoc = '', $object = '')
	{
		if (!is_object($object) || empty($object->id)) {
			$this->error = 'WarrantyLetterMissingIdForNumbering';
			return -1;
		}

		$date = !empty($object->date_creation) ? $object->date_creation : dol_now();
		$year = dol_print_date($date, '%Y');
		$month = dol_print_date($date, '%m');
		$uid = str_pad((string) ((int) $object->id), 4, '0', STR_PAD_LEFT);

		return $this->prefix.'-'.$year.'-'.$month.$uid;
	}
}
