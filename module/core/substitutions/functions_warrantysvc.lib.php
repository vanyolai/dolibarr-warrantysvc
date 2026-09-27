<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    core/substitutions/functions_warrantysvc.lib.php
 * \ingroup warrantysvc
 * \brief   Standard Dolibarr substitution variables for WarrantySvc objects
 */

/**
 * Complete Dolibarr's standard substitution array with WarrantySvc values.
 *
 * Called by complete_substitutions_array() because modWarrantySvc declares
 * module_parts['substitutions'] = 1. When $object is null (for example while
 * editing an email template), descriptive values are returned so the variables
 * are visible in Dolibarr's standard "Available variables" help.
 *
 * @param array<string,string|float|null> $substitutionarray Substitution array
 * @param Translate                       $outputlangs       Recipient/output language
 * @param CommonObject|null               $object            Source object
 * @param mixed                           $parameters        Optional context
 * @return void
 */
function warrantysvc_completesubstitutionarray(&$substitutionarray, $outputlangs, $object = null, $parameters = null)
{
	$outputlangs->load('warrantysvc@warrantysvc');

	$descriptions = array(
		'__PRODUCT_REF__' => $outputlangs->transnoentitiesnoconv('SubstProductRef'),
		'__PRODUCT_LABEL__' => $outputlangs->transnoentitiesnoconv('SubstProductLabel'),
		'__SERIAL_NUMBER__' => $outputlangs->transnoentitiesnoconv('SubstSerialNumber'),
		'__WARRANTY_STATUS__' => $outputlangs->transnoentitiesnoconv('SubstWarrantyStatus'),
		'__WARRANTY_START_DATE__' => $outputlangs->transnoentitiesnoconv('SubstWarrantyStartDate'),
		'__WARRANTY_EXPIRY_DATE__' => $outputlangs->transnoentitiesnoconv('SubstWarrantyExpiryDate'),
		'__ISSUE_DATE__' => $outputlangs->transnoentitiesnoconv('SubstIssueDate'),
		'__ISSUE_DESCRIPTION__' => $outputlangs->transnoentitiesnoconv('SubstIssueDescription'),
		'__SERVICE_REQUEST_STATUS__' => $outputlangs->transnoentitiesnoconv('SubstServiceRequestStatus'),
	);

	// Email-template administration asks for available variables without an
	// object. Return human-readable descriptions in that context.
	if (!is_object($object) || empty($object->element) || !in_array($object->element, array('svcrequest', 'svcwarranty'), true)) {
		foreach ($descriptions as $key => $description) {
			if (!array_key_exists($key, $substitutionarray)) {
				$substitutionarray[$key] = $description;
			}
		}
		return;
	}

	$productRef = '';
	$productLabel = '';
	$serialNumber = '';
	$warrantyStatus = '';
	$warrantyStartDate = '';
	$warrantyExpiryDate = '';
	$issueDate = '';
	$issueDescription = '';
	$serviceRequestStatus = '';

	$productId = !empty($object->fk_product) ? (int) $object->fk_product : 0;
	if ($productId > 0) {
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		$product = new Product($object->db);
		if ($product->fetch($productId) > 0) {
			$productRef = (string) $product->ref;
			$productLabel = (string) $product->label;
		}
	}

	$serialNumber = isset($object->serial_number) ? (string) $object->serial_number : '';

	$warranty = null;
	if ($object->element === 'svcrequest') {
		$issueDate = !empty($object->issue_date) ? dol_print_date($object->issue_date, 'day', false, $outputlangs) : '';
		$issueDescription = isset($object->issue_description) ? (string) $object->issue_description : '';
		$warrantyStatus = isset($object->warranty_status) ? (string) $object->warranty_status : 'none';

		$statusLabels = array(
			0 => 'SvcDraft',
			1 => 'SvcValidated',
			2 => 'SvcInProgress',
			3 => 'AwaitingReturn',
			4 => 'SvcResolved',
			5 => 'SvcClosed',
			6 => 'SvcDiagnosing',
			9 => 'SvcCancelled',
		);
		$statusCode = isset($object->status) ? (int) $object->status : -1;
		$serviceRequestStatus = isset($statusLabels[$statusCode])
			? $outputlangs->transnoentitiesnoconv($statusLabels[$statusCode])
			: '';

		if (!empty($object->fk_warranty)) {
			require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
			$warranty = new SvcWarranty($object->db);
			if ($warranty->fetch((int) $object->fk_warranty) <= 0) {
				$warranty = null;
			}
		}
	} else {
		$warranty = $object;
		$warrantyStatus = isset($object->status) ? (string) $object->status : 'none';
	}

	if (is_object($warranty)) {
		$warrantyStartDate = !empty($warranty->start_date)
			? dol_print_date($warranty->start_date, 'day', false, $outputlangs)
			: '';
		$warrantyExpiryDate = !empty($warranty->expiry_date)
			? dol_print_date($warranty->expiry_date, 'day', false, $outputlangs)
			: '';
	}

	$warrantyLabels = array(
		'active' => 'SvcActive',
		'expired' => 'SvcExpired',
		'voided' => 'SvcVoided',
		'none' => 'NoCoverage',
		'' => 'NoCoverage',
	);
	$warrantyStatusLabel = isset($warrantyLabels[$warrantyStatus])
		? $outputlangs->transnoentitiesnoconv($warrantyLabels[$warrantyStatus])
		: $warrantyStatus;

	$substitutionarray['__PRODUCT_REF__'] = $productRef;
	$substitutionarray['__PRODUCT_LABEL__'] = $productLabel;
	$substitutionarray['__SERIAL_NUMBER__'] = $serialNumber;
	$substitutionarray['__WARRANTY_STATUS__'] = $warrantyStatusLabel;
	$substitutionarray['__WARRANTY_START_DATE__'] = $warrantyStartDate;
	$substitutionarray['__WARRANTY_EXPIRY_DATE__'] = $warrantyExpiryDate;
	$substitutionarray['__ISSUE_DATE__'] = $issueDate;
	$substitutionarray['__ISSUE_DESCRIPTION__'] = $issueDescription;
	$substitutionarray['__SERVICE_REQUEST_STATUS__'] = $serviceRequestStatus;
}
