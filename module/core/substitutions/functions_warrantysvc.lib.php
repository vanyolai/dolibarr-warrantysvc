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
		'__SERVICE_REQUEST_REF__' => $outputlangs->transnoentitiesnoconv('SubstServiceRequestRef'),
		'__CUSTOMER_NAME__' => $outputlangs->transnoentitiesnoconv('SubstCustomerName'),
		'__SUPPLIER_RMA_REF__' => $outputlangs->transnoentitiesnoconv('SubstSupplierRmaRef'),
		'__SUPPLIER_RMA_EXTERNAL_REF__' => $outputlangs->transnoentitiesnoconv('SubstSupplierRmaExternalRef'),
		'__SUPPLIER_RMA_STATUS__' => $outputlangs->transnoentitiesnoconv('SubstSupplierRmaStatus'),
		'__SUPPLIER_NAME__' => $outputlangs->transnoentitiesnoconv('SubstSupplierName'),
		'__SUPPLIER_RMA_PROBLEM_DESCRIPTION__' => $outputlangs->transnoentitiesnoconv('SubstSupplierRmaProblemDescription'),
		'__SUPPLIER_RMA_DIAGNOSIS__' => $outputlangs->transnoentitiesnoconv('SubstSupplierRmaDiagnosis'),
		'__SUPPLIER_RMA_ACCESSORIES__' => $outputlangs->transnoentitiesnoconv('SubstSupplierRmaAccessories'),
		'__OUTBOUND_CARRIER__' => $outputlangs->transnoentitiesnoconv('SubstOutboundCarrier'),
		'__OUTBOUND_TRACKING__' => $outputlangs->transnoentitiesnoconv('SubstOutboundTracking'),
		'__OUTBOUND_TRACKING_URL__' => $outputlangs->transnoentitiesnoconv('SubstOutboundTrackingUrl'),
		'__RETURN_CARRIER__' => $outputlangs->transnoentitiesnoconv('SubstReturnCarrier'),
		'__RETURN_TRACKING__' => $outputlangs->transnoentitiesnoconv('SubstReturnTracking'),
		'__RETURN_TRACKING_URL__' => $outputlangs->transnoentitiesnoconv('SubstReturnTrackingUrl'),
		'__REPLACEMENT_SERIAL_NUMBER__' => $outputlangs->transnoentitiesnoconv('SubstReplacementSerialNumber'),
		'__SUPPLIER_RETURN_REF__' => $outputlangs->transnoentitiesnoconv('SubstSupplierReturnRef'),
		'__SUPPLIER_RETURN_EXTERNAL_REF__' => $outputlangs->transnoentitiesnoconv('SubstSupplierReturnExternalRef'),
		'__SUPPLIER_RETURN_STATUS__' => $outputlangs->transnoentitiesnoconv('SubstSupplierReturnStatus'),
		'__SUPPLIER_RETURN_REASON__' => $outputlangs->transnoentitiesnoconv('SubstSupplierReturnReason'),
		'__SUPPLIER_RETURN_LINES__' => $outputlangs->transnoentitiesnoconv('SubstSupplierReturnLines'),
		'__WARRANTY_CONFIRMATION_LINES__' => $outputlangs->transnoentitiesnoconv('SubstWarrantyConfirmationLines'),
	);

	if (!is_object($object) || empty($object->element) || !in_array($object->element, array('svcrequest', 'svcwarranty', 'svcsupplierrma', 'svcsupplierreturn', 'shipping'), true)) {
		foreach ($descriptions as $key => $description) {
			if (!array_key_exists($key, $substitutionarray)) {
				$substitutionarray[$key] = $description;
			}
		}
		return;
	}

	$productRef = '';
	$productLabel = '';
	$serialNumber = isset($object->serial_number) ? (string) $object->serial_number : '';
	$warrantyStatus = '';
	$warrantyStartDate = '';
	$warrantyExpiryDate = '';
	$issueDate = '';
	$issueDescription = '';
	$serviceRequestStatus = '';
	$serviceRequestRef = '';
	$customerName = '';
	$supplierRmaRef = '';
	$supplierRmaExternalRef = '';
	$supplierRmaStatus = '';
	$supplierName = '';
	$supplierRmaProblem = '';
	$supplierRmaDiagnosis = '';
	$supplierRmaAccessories = '';
	$outboundCarrier = '';
	$outboundTracking = '';
	$outboundTrackingUrl = '';
	$returnCarrier = '';
	$returnTracking = '';
	$returnTrackingUrl = '';
	$replacementSerial = '';
	$supplierReturnRef = '';
	$supplierReturnExternalRef = '';
	$supplierReturnStatus = '';
	$supplierReturnReason = '';
	$supplierReturnLines = '';
	$warrantyConfirmationLines = '';

	$productId = !empty($object->fk_product) ? (int) $object->fk_product : 0;
	if ($productId > 0) {
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		$product = new Product($object->db);
		if ($product->fetch($productId) > 0) {
			$productRef = (string) $product->ref;
			$productLabel = (string) $product->label;
		}
	}

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

	$warranty = null;
	$serviceRequest = null;

	if ($object->element === 'svcrequest') {
		$serviceRequest = $object;
	} elseif ($object->element === 'svcsupplierrma') {
		require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcrequest.class.php';
		$serviceRequest = new SvcRequest($object->db);
		if ($serviceRequest->fetch((int) $object->fk_svc_request) <= 0) {
			$serviceRequest = null;
		}

		$supplierRmaRef = isset($object->ref) ? (string) $object->ref : '';
		$supplierRmaExternalRef = isset($object->supplier_rma_ref) ? (string) $object->supplier_rma_ref : '';
		$supplierRmaStatus = method_exists($object, 'getLibStatut') ? (string) $object->getLibStatut(1) : (string) $object->status;
		$supplierRmaProblem = isset($object->problem_description) ? (string) $object->problem_description : '';
		$supplierRmaDiagnosis = isset($object->diagnosis) ? (string) $object->diagnosis : '';
		$supplierRmaAccessories = isset($object->accessories_sent) ? (string) $object->accessories_sent : '';
		$outboundCarrier = isset($object->outbound_carrier) ? (string) $object->outbound_carrier : '';
		$outboundTracking = isset($object->outbound_tracking) ? (string) $object->outbound_tracking : '';
		$outboundTrackingUrl = isset($object->outbound_tracking_url) ? (string) $object->outbound_tracking_url : '';
		$returnCarrier = isset($object->return_carrier) ? (string) $object->return_carrier : '';
		$returnTracking = isset($object->return_tracking) ? (string) $object->return_tracking : '';
		$returnTrackingUrl = isset($object->return_tracking_url) ? (string) $object->return_tracking_url : '';
		$replacementSerial = isset($object->replacement_serial_number) ? (string) $object->replacement_serial_number : '';

		$object->socid = (int) $object->fk_soc_supplier;
		if (!is_object($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		if (is_object($object->thirdparty)) {
			$supplierName = (string) $object->thirdparty->name;
		}

		// Generic issue placeholder is useful in supplier-facing templates too.
		$issueDescription = $supplierRmaProblem;
	} elseif ($object->element === 'svcsupplierreturn') {
		$supplierReturnRef = isset($object->ref) ? (string) $object->ref : '';
		$supplierReturnExternalRef = isset($object->supplier_return_ref) ? (string) $object->supplier_return_ref : '';
		$supplierReturnStatus = method_exists($object, 'getLibStatut') ? (string) $object->getLibStatut(1) : (string) $object->status;
		$supplierReturnReason = isset($object->reason) ? trim(strip_tags((string) $object->reason)) : '';
		$outboundCarrier = isset($object->outbound_carrier) ? (string) $object->outbound_carrier : '';
		$outboundTracking = isset($object->outbound_tracking) ? (string) $object->outbound_tracking : '';
		$outboundTrackingUrl = isset($object->outbound_tracking_url) ? (string) $object->outbound_tracking_url : '';

		$object->socid = (int) $object->fk_soc_supplier;
		if (!is_object($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		if (is_object($object->thirdparty)) {
			$supplierName = (string) $object->thirdparty->name;
		}

		if (empty($object->lines) && method_exists($object, 'fetchLines')) {
			$object->fetchLines();
		}
		$lineTexts = array();
		if (!empty($object->lines) && is_array($object->lines)) {
			require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
			foreach ($object->lines as $line) {
				$productText = '#'.((int) $line->fk_product);
				$product = new Product($object->db);
				if ($product->fetch((int) $line->fk_product) > 0) {
					$productText = (string) $product->ref;
					if (!empty($product->label)) {
						$productText .= ' - '.(string) $product->label;
					}
				}
				$parts = array($productText, $outputlangs->transnoentitiesnoconv('Qty').': '.price($line->qty, 0, $outputlangs, 0, 0, -1));
				if (!empty($line->batch)) {
					$parts[] = $outputlangs->transnoentitiesnoconv('SerialOrLot').': '.(string) $line->batch;
				}
				$effectiveReason = trim(strip_tags((string) $line->reason));
				if ($effectiveReason === '') {
					$effectiveReason = $supplierReturnReason;
				}
				if ($effectiveReason !== '') {
					$parts[] = $outputlangs->transnoentitiesnoconv('Reason').': '.$effectiveReason;
				}
				$lineTexts[] = implode(' | ', $parts);
			}
		}
		$supplierReturnLines = implode("\n", $lineTexts);
	} elseif ($object->element === 'shipping') {
		dol_include_once('/warrantysvc/lib/warrantysvc.lib.php');
		$warrantyConfirmationLines = warrantysvc_render_warranty_confirmation_lines(
			$object->db,
			(int) $object->id,
			$outputlangs
		);

		if (!is_object($object->thirdparty)) {
			$object->fetch_thirdparty();
		}
		if (is_object($object->thirdparty)) {
			$customerName = (string) $object->thirdparty->name;
		}
	} else {
		$warranty = $object;
		$warrantyStatus = isset($object->status) ? (string) $object->status : 'none';
	}

	if (is_object($serviceRequest)) {
		$serviceRequestRef = isset($serviceRequest->ref) ? (string) $serviceRequest->ref : '';
		$issueDate = !empty($serviceRequest->issue_date) ? dol_print_date($serviceRequest->issue_date, 'day', false, $outputlangs) : '';
		if ($issueDescription === '') {
			$issueDescription = isset($serviceRequest->issue_description) ? (string) $serviceRequest->issue_description : '';
		}
		$warrantyStatus = isset($serviceRequest->warranty_status) ? (string) $serviceRequest->warranty_status : 'none';
		$statusCode = isset($serviceRequest->status) ? (int) $serviceRequest->status : -1;
		$serviceRequestStatus = isset($statusLabels[$statusCode])
			? $outputlangs->transnoentitiesnoconv($statusLabels[$statusCode])
			: '';

		$serviceRequest->socid = (int) $serviceRequest->fk_soc;
		if (!is_object($serviceRequest->thirdparty)) {
			$serviceRequest->fetch_thirdparty();
		}
		if (is_object($serviceRequest->thirdparty)) {
			$customerName = (string) $serviceRequest->thirdparty->name;
		}

		if (!empty($serviceRequest->fk_warranty)) {
			require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcwarranty.class.php';
			$warranty = new SvcWarranty($object->db);
			if ($warranty->fetch((int) $serviceRequest->fk_warranty) <= 0) {
				$warranty = null;
			}
		}
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

	$values = array(
		'__PRODUCT_REF__' => $productRef,
		'__PRODUCT_LABEL__' => $productLabel,
		'__SERIAL_NUMBER__' => $serialNumber,
		'__WARRANTY_STATUS__' => $warrantyStatusLabel,
		'__WARRANTY_START_DATE__' => $warrantyStartDate,
		'__WARRANTY_EXPIRY_DATE__' => $warrantyExpiryDate,
		'__ISSUE_DATE__' => $issueDate,
		'__ISSUE_DESCRIPTION__' => $issueDescription,
		'__SERVICE_REQUEST_STATUS__' => $serviceRequestStatus,
		'__SERVICE_REQUEST_REF__' => $serviceRequestRef,
		'__CUSTOMER_NAME__' => $customerName,
		'__SUPPLIER_RMA_REF__' => $supplierRmaRef,
		'__SUPPLIER_RMA_EXTERNAL_REF__' => $supplierRmaExternalRef,
		'__SUPPLIER_RMA_STATUS__' => $supplierRmaStatus,
		'__SUPPLIER_NAME__' => $supplierName,
		'__SUPPLIER_RMA_PROBLEM_DESCRIPTION__' => $supplierRmaProblem,
		'__SUPPLIER_RMA_DIAGNOSIS__' => $supplierRmaDiagnosis,
		'__SUPPLIER_RMA_ACCESSORIES__' => $supplierRmaAccessories,
		'__OUTBOUND_CARRIER__' => $outboundCarrier,
		'__OUTBOUND_TRACKING__' => $outboundTracking,
		'__OUTBOUND_TRACKING_URL__' => $outboundTrackingUrl,
		'__RETURN_CARRIER__' => $returnCarrier,
		'__RETURN_TRACKING__' => $returnTracking,
		'__RETURN_TRACKING_URL__' => $returnTrackingUrl,
		'__REPLACEMENT_SERIAL_NUMBER__' => $replacementSerial,
		'__SUPPLIER_RETURN_REF__' => $supplierReturnRef,
		'__SUPPLIER_RETURN_EXTERNAL_REF__' => $supplierReturnExternalRef,
		'__SUPPLIER_RETURN_STATUS__' => $supplierReturnStatus,
		'__SUPPLIER_RETURN_REASON__' => $supplierReturnReason,
		'__SUPPLIER_RETURN_LINES__' => $supplierReturnLines,
		'__WARRANTY_CONFIRMATION_LINES__' => $warrantyConfirmationLines,
	);

	foreach ($values as $key => $value) {
		$substitutionarray[$key] = $value;
	}
}
