<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * Dolibarr-style Warranty Letter PDF model.
 *
 * Layout follows Dolibarr core PDF conventions (Azur/Espadon family):
 * - native page header initialization;
 * - logo/company identity left, document title/ref/date right;
 * - sender and recipient address frames on first page;
 * - compact repeated header on following pages;
 * - manually reserved footer area and native pdf_pagefoot();
 * - no TCPDF automatic page breaks.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
dol_include_once('/warrantysvc/core/modules/warrantysvc/modules_warrantysvc.php');

class pdf_warrantyletter_standard extends ModelePDFWarrantySvc
{
	public $name = 'warrantyletter_standard';
	public $description = 'WarrantyLetterPdfStandardDesc';
	public $type = 'pdf';
	public $version = 4;
	public $update_main_doc_field = 1;
	public $db;
	public $error = '';
	public $emetteur;
	public $watermark = '';

	public $marge_gauche;
	public $marge_droite;
	public $marge_haute;
	public $marge_basse;
	public $page_largeur;
	public $page_hauteur;
	public $page_unit;
	public $page_format;
	public $corner_radius = 0;

	public function __construct($db)
	{
		global $langs, $mysoc;

		$this->db = $db;
		$this->emetteur = clone $mysoc;

		$langs->load('warrantysvc@warrantysvc');
		$this->description = $langs->trans('WarrantyLetterPdfStandardDesc');

		$format = pdf_getFormat();
		$this->page_largeur = (float) $format['width'];
		$this->page_hauteur = (float) $format['height'];
		$this->page_unit = (string) $format['unit'];
		$this->page_format = array($this->page_largeur, $this->page_hauteur);

		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
	}

	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $langs, $user;

		if (!is_object($outputlangs)) $outputlangs = $langs;
		$outputlangs->loadLangs(array('warrantysvc@warrantysvc', 'main', 'companies', 'products', 'sendings', 'orders', 'bills'));

		$data = $object->pending_snapshot;
		$revision = (int) $object->pending_version;
		if (!is_array($data) || $revision < 1 || empty($data['shipments']) || !is_array($data['shipments'])) {
			$this->error = 'WarrantyLetterSnapshotMissing';
			return -1;
		}

		if (!is_object($object->thirdparty)) {
			$object->fetch_thirdparty();
		}

		$base = $object->getOutputRoot().'/letters/'.dol_sanitizeFileName($object->ref);
		if (!is_dir($base) && dol_mkdir($base) < 0) {
			$this->error = 'ErrorCanNotCreateDir';
			return -1;
		}

		$path = $base.'/'.dol_sanitizeFileName($object->ref).'_v'.$revision.'.pdf';
		if (file_exists($path)) {
			$this->error = 'WarrantyLetterVersionFileExists';
			return -1;
		}

		$pdf = pdf_getInstance($this->page_format, $this->page_unit, 'P');
		if (class_exists('TCPDF')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}

		$font = pdf_getPDFFont($outputlangs);
		if (class_exists('TCPDF') && !getDolGlobalString('MAIN_PDF_FORCE_FONT')) {
			$font = 'dejavusans';
		}
		$defaultFontSize = pdf_getPDFFontSize($outputlangs);

		$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
		$pdf->SetSubject($outputlangs->transnoentities('WarrantyLetterTitle'));
		$pdf->SetCreator('Dolibarr '.DOL_VERSION);
		$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
		$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref.' '.$object->thirdparty->name));
		if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
			$pdf->SetCompression(false);
		}

		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		// Native Dolibarr page footer uses negative Y positions at the physical bottom.
		// Automatic page breaks must therefore stay disabled; page flow is handled here.
		$pdf->SetAutoPageBreak(false, 0);
		if (method_exists($pdf, 'AliasNbPages')) {
			$pdf->AliasNbPages();
		}

		$usable = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
		$footerHeight = $this->marge_basse + 14;
		if (getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS')) {
			$footerHeight += 9;
		}
		$contentBottom = $this->page_hauteur - $footerHeight;

		$toText = static function ($value) use ($outputlangs) {
			return $outputlangs->convToOutputCharset((string) $value);
		};

		$addPage = function ($showAddress) use ($pdf, $object, $outputlangs, $defaultFontSize, $font) {
			$pdf->AddPage();
			$pdf->SetFont($font, '', $defaultFontSize - 1);
			return $this->_pagehead($pdf, $object, $showAddress, $outputlangs);
		};

		$y = $addPage(1);

		// Shipment count is useful only for a multi-shipment letter.
		if (count($data['shipments']) > 1) {
			$pdf->SetXY($this->marge_gauche, $y);
			$pdf->SetFont($font, 'B', $defaultFontSize - 1);
			$pdf->Cell(46, 5, $toText($outputlangs->transnoentities('WarrantyLetterShipmentCount')).':', 0, 0, 'L');
			$pdf->SetFont($font, '', $defaultFontSize - 1);
			$pdf->Cell($usable - 46, 5, (string) count($data['shipments']), 0, 1, 'L');
			$y = $pdf->GetY() + 3;
		}

		$cQty = 18;
		$cStart = 42;
		$cEnd = 42;
		$cProduct = $usable - $cQty - $cStart - $cEnd;

		$printTableHeader = static function () use ($pdf, $outputlangs, $toText, $font, $defaultFontSize, $cProduct, $cQty, $cStart, $cEnd) {
			$pdf->SetFillColor(230, 230, 230);
			$pdf->SetDrawColor(190, 190, 190);
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont($font, 'B', $defaultFontSize - 2);
			$pdf->Cell($cProduct, 7, $toText($outputlangs->transnoentities('Product')), 1, 0, 'L', true);
			$pdf->Cell($cQty, 7, $toText($outputlangs->transnoentities('Qty')), 1, 0, 'C', true);
			$pdf->Cell($cStart, 7, $toText($outputlangs->transnoentities('WarrantyLetterStartDate')), 1, 0, 'C', true);
			$pdf->Cell($cEnd, 7, $toText($outputlangs->transnoentities('WarrantyLetterEndDate')), 1, 1, 'C', true);
		};

		foreach ($data['shipments'] as $shipmentIndex => $shipment) {
			$shipmentHeaderHeight = !empty($shipment['order_refs']) ? 13 : 7;
			if ($y + $shipmentHeaderHeight + 10 > $contentBottom) {
				$this->_pagefoot($pdf, $object, $outputlangs);
				$y = $addPage(0);
			}

			$pdf->SetXY($this->marge_gauche, $y);
			$pdf->SetFillColor(242, 242, 242);
			$pdf->SetDrawColor(190, 190, 190);
			$pdf->SetFont($font, 'B', $defaultFontSize - 1);

			$shipmentTitle = $outputlangs->transnoentities('ShipmentRef').': '.(string) ($shipment['shipment_ref'] ?? '');
			if (!empty($shipment['shipment_date'])) {
				$shipmentTitle .= ' — '.$outputlangs->transnoentities('Date').': '
					.dol_print_date($this->db->jdate((string) $shipment['shipment_date']), 'day', false, $outputlangs);
			}
			$pdf->Cell($usable, 7, $toText($shipmentTitle), 1, 1, 'L', true);

			if (!empty($shipment['order_refs']) && is_array($shipment['order_refs'])) {
				$pdf->SetFont($font, '', $defaultFontSize - 2);
				$pdf->Cell(
					$usable,
					6,
					$toText($outputlangs->transnoentities('Order').': '.implode(', ', $shipment['order_refs'])),
					1,
					1,
					'L'
				);
			}

			$printTableHeader();

			foreach ((array) ($shipment['groups'] ?? array()) as $item) {
				$productRef = trim((string) ($item['product_ref'] ?? ''));
				$productLabel = trim((string) ($item['product_label'] ?? ''));
				$serials = !empty($item['serials'])
					? implode(', ', $item['serials'])
					: $outputlangs->transnoentities('WarrantyLetterNoSerial');

				// Keep product ref visually dominant. Description stays secondary and compact.
				$description = $productRef;
				if ($productLabel !== '') {
					$description .= ($description !== '' ? ' - ' : '').$productLabel;
				}
				$description .= "\n".$outputlangs->transnoentities('WarrantyLetterSerials').': '.$serials;
				$text = $toText($description);

				$pdf->SetFont($font, '', $defaultFontSize - 2);
				$textLines = max(1, $pdf->getNumLines($text, $cProduct - 4));
				$rowHeight = max(9, $textLines * 3.8 + 2);

				if ($pdf->GetY() + $rowHeight > $contentBottom) {
					$this->_pagefoot($pdf, $object, $outputlangs);
					$y = $addPage(0);

					$pdf->SetXY($this->marge_gauche, $y);
					$pdf->SetFillColor(242, 242, 242);
					$pdf->SetFont($font, 'B', $defaultFontSize - 2);
					$pdf->Cell(
						$usable,
						6,
						$toText($outputlangs->transnoentities('ShipmentRef').': '.(string) ($shipment['shipment_ref'] ?? '')),
						1,
						1,
						'L',
						true
					);
					$printTableHeader();
				}

				$yRow = $pdf->GetY();
				$pdf->SetFont($font, '', $defaultFontSize - 2);
				$pdf->MultiCell($cProduct, $rowHeight, $text, 1, 'L', false, 0);
				$pdf->MultiCell($cQty, $rowHeight, $toText((string) $item['qty']), 1, 'C', false, 0);
				$pdf->MultiCell(
					$cStart,
					$rowHeight,
					$toText(dol_print_date($this->db->jdate((string) $item['start_date']), 'day', false, $outputlangs)),
					1,
					'C',
					false,
					0
				);
				$pdf->MultiCell(
					$cEnd,
					$rowHeight,
					$toText(dol_print_date($this->db->jdate((string) $item['expiry_date']), 'day', false, $outputlangs)),
					1,
					'C',
					false,
					1
				);
				$pdf->SetY($yRow + $rowHeight);
			}

			$y = $pdf->GetY() + ($shipmentIndex < count($data['shipments']) - 1 ? 5 : 0);
		}

		$note = $toText($outputlangs->transnoentities('WarrantyLetterFooterNote'));
		$pdf->SetFont($font, '', $defaultFontSize - 2);
		$noteHeight = max(6, $pdf->getStringHeight($usable, $note) + 2);

		if ($y + 5 + $noteHeight > $contentBottom) {
			$this->_pagefoot($pdf, $object, $outputlangs);
			$y = $addPage(0);
		}

		$pdf->SetXY($this->marge_gauche, $y + 5);
		$pdf->MultiCell($usable, 4, $note, 0, 'L');

		$this->_pagefoot($pdf, $object, $outputlangs);

		$pdf->Close();
		$pdf->Output($path, 'F');
		$this->result = array('fullpath'=>$path);
		return 1;
	}

	/**
	 * Dolibarr-native style page header.
	 *
	 * @param TCPDF    $pdf
	 * @param object   $object
	 * @param int      $showAddress 1 on first page, 0 on following pages
	 * @param Translate $outputlangs
	 * @return float Y position where body can start
	 */
	protected function _pagehead(&$pdf, $object, $showAddress, $outputlangs)
	{
		global $conf;

		$defaultFontSize = pdf_getPDFFontSize($outputlangs);
		$ltrdirection = ($outputlangs->trans('DIRECTION') === 'rtl') ? 'R' : 'L';

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$pdf->SetTextColor(0, 0, 60);

		$w = 100;
		$posy = $this->marge_haute;
		$posx = $this->page_largeur - $this->marge_droite - $w;

		// Company logo/name on the left, exactly like core document models.
		$pdf->SetXY($this->marge_gauche, $posy);
		if (!getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			if (!empty($this->emetteur->logo)) {
				$logodir = $conf->mycompany->dir_output;
				if (!empty($conf->mycompany->multidir_output[$object->entity ?? $conf->entity])) {
					$logodir = $conf->mycompany->multidir_output[$object->entity ?? $conf->entity];
				}
				if (!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') && !empty($this->emetteur->logo_small)) {
					$logo = $logodir.'/logos/thumbs/'.$this->emetteur->logo_small;
				} else {
					$logo = $logodir.'/logos/'.$this->emetteur->logo;
				}
				if (is_readable($logo)) {
					$pdf->Image($logo, $this->marge_gauche, $posy, 0, pdf_getHeightForLogo($logo));
				} else {
					$pdf->SetFont('', 'B', $defaultFontSize - 2);
					$pdf->SetTextColor(200, 0, 0);
					$pdf->MultiCell($w, 3, $outputlangs->transnoentities('ErrorLogoFileNotFound', $logo), 0, 'L');
				}
			} else {
				$pdf->SetFont('', 'B', $defaultFontSize);
				$pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, $ltrdirection);
			}
		}

		// Document identity on the right.
		$pdf->SetFont('', 'B', $defaultFontSize + 3);
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$title = $outputlangs->transnoentities('WarrantyLetterTitle').' '.$outputlangs->convToOutputCharset($object->ref);
		$pdf->MultiCell($w, 4, $title, 0, 'R');

		$posy = $pdf->GetY() + 1;
		$pdf->SetFont('', '', $defaultFontSize - 2);
		$pdf->SetXY($posx, $posy);
		$pdf->MultiCell(
			$w,
			3,
			$outputlangs->transnoentities('Date').' : '.dol_print_date($object->date_creation, 'day', false, $outputlangs, true),
			0,
			'R'
		);

		if (!$showAddress) {
			$pdf->SetTextColor(0, 0, 0);
			$bodyY = max($this->marge_haute + 23, $pdf->GetY() + 5);
			$pdf->SetDrawColor(224, 224, 224);
			$pdf->Line($this->marge_gauche, $bodyY - 3, $this->page_largeur - $this->marge_droite, $bodyY - 3);
			return $bodyY;
		}

		// Sender block: native Dolibarr grey filled frame.
		$senderY = 42;
		$senderX = $this->marge_gauche;
		$senderW = 82;
		$frameH = 40;

		$senderAddress = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'source', $object);

		if (!getDolGlobalInt('MAIN_PDF_NO_SENDER_FRAME')) {
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('', '', $defaultFontSize - 2);
			$pdf->SetXY($senderX, $senderY - 5);
			$pdf->MultiCell(80, 5, $outputlangs->transnoentities('WarrantyLetterIssuer'), 0, $ltrdirection);
			$pdf->SetFillColor(230, 230, 230);
			$pdf->RoundedRect($senderX, $senderY, $senderW, $frameH, $this->corner_radius, '1234', 'F');
		}

		if (!getDolGlobalInt('MAIN_PDF_HIDE_SENDER_NAME')) {
			$pdf->SetXY($senderX + 2, $senderY + 3);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFont('', 'B', $defaultFontSize);
			$pdf->MultiCell(78, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, $ltrdirection);
			$senderTextY = $pdf->GetY();
		} else {
			$senderTextY = $senderY + 3;
		}

		$pdf->SetXY($senderX + 2, $senderTextY);
		$pdf->SetFont('', '', $defaultFontSize - 1);
		$pdf->MultiCell(78, 4, $senderAddress, 0, $ltrdirection);

		// Recipient block: native Dolibarr outlined frame on the right.
		$recipientW = ($this->page_largeur < 210) ? 84 : 100;
		$recipientX = $this->page_largeur - $this->marge_droite - $recipientW;
		$recipientY = 42;

		$thirdpartyName = pdfBuildThirdpartyName($object->thirdparty, $outputlangs);
		$recipientAddress = pdf_build_address($outputlangs, $this->emetteur, $object->thirdparty, '', 0, 'target', $object);

		if (!getDolGlobalInt('MAIN_PDF_NO_RECIPENT_FRAME')) {
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('', '', $defaultFontSize - 2);
			$pdf->SetXY($recipientX + 2, $recipientY - 5);
			$pdf->MultiCell($recipientW, 5, $outputlangs->transnoentities('WarrantyLetterCustomer'), 0, $ltrdirection);
			$pdf->RoundedRect($recipientX, $recipientY, $recipientW, $frameH, $this->corner_radius, '1234', 'D');
		}

		$pdf->SetXY($recipientX + 2, $recipientY + 3);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $defaultFontSize);
		$pdf->MultiCell($recipientW - 4, 4, $thirdpartyName, 0, $ltrdirection);
		$recipientTextY = $pdf->GetY();

		$pdf->SetXY($recipientX + 2, $recipientTextY);
		$pdf->SetFont('', '', $defaultFontSize - 1);
		$pdf->MultiCell($recipientW - 4, 4, $recipientAddress, 0, $ltrdirection);

		$pdf->SetTextColor(0, 0, 0);
		return $recipientY + $frameH + 8;
	}

	/**
	 * Native Dolibarr footer. Footer position is reserved manually by write_file().
	 */
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 1)
	{
		$showdetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
		return pdf_pagefoot(
			$pdf,
			$outputlangs,
			'WARRANTYSVC_WARRANTYLETTER_FREE_TEXT',
			$this->emetteur,
			$this->marge_basse,
			$this->marge_gauche,
			$this->page_hauteur,
			$object,
			$showdetails,
			$hidefreetext,
			$this->page_largeur,
			$this->watermark
		);
	}
}
