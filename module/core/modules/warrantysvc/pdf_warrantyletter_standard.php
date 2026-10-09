<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * Dolibarr-style Warranty Letter PDF model.
 *
 * The PDF is generated from the frozen revision snapshot. The layout follows
 * Dolibarr's standard document conventions: company logo/title at the top,
 * right-aligned document identity, sender/recipient address blocks, native
 * margins and native PDF footer.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/warrantysvc/modules_warrantysvc.php';

class pdf_warrantyletter_standard extends ModelePDFWarrantySvc
{
	public $name = 'warrantyletter_standard';
	public $description = 'WarrantyLetterPdfStandardDesc';
	public $type = 'pdf';
	public $version = 3;
	public $update_main_doc_field = 1;
	public $db;
	public $error = '';
	public $marge_gauche;
	public $marge_droite;
	public $marge_haute;
	public $marge_basse;
	public $page_largeur;
	public $page_hauteur;
	public $page_unit;
	public $page_format;

	public function __construct($db)
	{
		global $langs;

		$this->db = $db;
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
		global $conf, $langs, $mysoc;

		if (!is_object($outputlangs)) $outputlangs = $langs;
		$outputlangs->loadLangs(array('warrantysvc@warrantysvc','main','companies','products','sendings','orders'));

		$data = $object->pending_snapshot;
		$revision = (int) $object->pending_version;
		if (!is_array($data) || $revision < 1 || empty($data['shipments']) || !is_array($data['shipments'])) {
			$this->error = 'WarrantyLetterSnapshotMissing';
			return -1;
		}

		if (!is_object($object->thirdparty)) {
			$object->fetch_thirdparty();
		}

		$base = rtrim($conf->warrantysvc->dir_output, '/').'/letters/'.dol_sanitizeFileName($object->ref);
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
		if (class_exists('TCPDF') && !getDolGlobalString('MAIN_PDF_FORCE_FONT')) $font = 'dejavusans';
		$pdf->SetFont($font);
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->SetAutoPageBreak(true, $this->marge_basse + 10);
		$pdf->AddPage();

		$fs = pdf_getPDFFontSize($outputlangs);
		$usable = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
		$toText = static function ($value) use ($outputlangs) {
			return $outputlangs->convToOutputCharset((string) $value);
		};

		// Dolibarr-style document heading.
		$logoWidth = 55;
		if (!empty($mysoc->logo) && !getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			$logodir = !empty($conf->mycompany->multidir_output[$object->entity])
				? $conf->mycompany->multidir_output[$object->entity]
				: $conf->mycompany->dir_output;
			$logo = $logodir.'/logos/'.$mysoc->logo;
			if (is_readable($logo)) {
				$pdf->Image($logo, $this->marge_gauche, $this->marge_haute, 0, min(18, pdf_getHeightForLogo($logo)));
			}
		}

		$pdf->SetFont($font, 'B', $fs + 4);
		$pdf->SetXY($this->marge_gauche + $logoWidth, $this->marge_haute);
		$pdf->MultiCell($usable - $logoWidth, 6, $toText($outputlangs->transnoentities('WarrantyLetterTitle')), 0, 'R');

		$pdf->SetFont($font, 'B', $fs + 1);
		$pdf->SetXY($this->marge_gauche + $logoWidth, $this->marge_haute + 8);
		$pdf->MultiCell($usable - $logoWidth, 5, $toText($object->ref), 0, 'R');

		$pdf->SetFont($font, '', $fs - 1);
		$pdf->SetXY($this->marge_gauche + $logoWidth, $this->marge_haute + 14);
		$pdf->MultiCell(
			$usable - $logoWidth,
			4,
			$toText($outputlangs->transnoentities('Date').': '.dol_print_date($object->date_creation, 'day', false, $outputlangs)),
			0,
			'R'
		);

		$y = $this->marge_haute + 30;
		$pdf->SetDrawColor(190, 190, 190);
		$pdf->Line($this->marge_gauche, $y, $this->page_largeur - $this->marge_droite, $y);
		$y += 5;

		// Sender / customer address blocks, following native Dolibarr PDF convention.
		$addressGap = 6;
		$addressWidth = ($usable - $addressGap) / 2;
		$addressHeight = 31;
		$leftX = $this->marge_gauche;
		$rightX = $this->marge_gauche + $addressWidth + $addressGap;

		$sourceAddress = trim((string) pdf_build_address($outputlangs, $mysoc, $object->thirdparty, '', 0, 'source', $object));
		$targetAddress = is_object($object->thirdparty)
			? trim((string) pdf_build_address($outputlangs, $mysoc, $object->thirdparty, '', 0, 'target', $object))
			: '';

		$pdf->Rect($leftX, $y, $addressWidth, $addressHeight);
		$pdf->Rect($rightX, $y, $addressWidth, $addressHeight);

		$pdf->SetFont($font, 'B', $fs - 1);
		$pdf->SetXY($leftX + 2, $y + 2);
		$pdf->Cell($addressWidth - 4, 5, $toText($outputlangs->transnoentities('WarrantyLetterIssuer')), 0, 1, 'L');
		$pdf->SetFont($font, '', $fs - 2);
		$pdf->SetXY($leftX + 2, $y + 7);
		$sourceText = trim((string) $mysoc->name.($sourceAddress !== '' ? "\n".$sourceAddress : ''));
		$pdf->MultiCell($addressWidth - 4, 4, $toText($sourceText), 0, 'L');

		$pdf->SetFont($font, 'B', $fs - 1);
		$pdf->SetXY($rightX + 2, $y + 2);
		$pdf->Cell($addressWidth - 4, 5, $toText($outputlangs->transnoentities('Customer')), 0, 1, 'L');
		$pdf->SetFont($font, '', $fs - 2);
		$pdf->SetXY($rightX + 2, $y + 7);
		$targetName = is_object($object->thirdparty) ? (string) $object->thirdparty->name : '';
		$targetText = trim($targetName.($targetAddress !== '' ? "\n".$targetAddress : ''));
		$pdf->MultiCell($addressWidth - 4, 4, $toText($targetText), 0, 'L');

		$y += $addressHeight + 6;

		$pdf->SetXY($this->marge_gauche, $y);
		$pdf->SetFont($font, 'B', $fs - 1);
		$pdf->Cell(52, 5, $toText($outputlangs->transnoentities('WarrantyLetterShipmentCount')).':', 0, 0, 'L');
		$pdf->SetFont($font, '', $fs - 1);
		$pdf->Cell($usable - 52, 5, (string) count($data['shipments']), 0, 1, 'L');
		$y = $pdf->GetY() + 4;

		$c1 = $usable * .40;
		$c2 = $usable * .10;
		$c3 = $usable * .25;
		$c4 = $usable * .25;

		$printTableHeader = static function () use ($pdf, $outputlangs, $toText, $font, $fs, $c1, $c2, $c3, $c4) {
			$pdf->SetFillColor(230, 230, 230);
			$pdf->SetFont($font, 'B', $fs - 2);
			$pdf->Cell($c1, 7, $toText($outputlangs->transnoentities('Product')), 1, 0, 'L', true);
			$pdf->Cell($c2, 7, $toText($outputlangs->transnoentities('Qty')), 1, 0, 'C', true);
			$pdf->Cell($c3, 7, $toText($outputlangs->transnoentities('WarrantyLetterStartDate')), 1, 0, 'C', true);
			$pdf->Cell($c4, 7, $toText($outputlangs->transnoentities('WarrantyLetterEndDate')), 1, 1, 'C', true);
		};

		foreach ($data['shipments'] as $shipmentIndex => $shipment) {
			if ($y > $this->page_hauteur - $this->marge_basse - 45) {
				pdf_pagefoot($pdf, $outputlangs, 'MAIN_PDF_FOOTER_TEXT', $mysoc, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, 1, 1, $this->page_largeur);
				$pdf->AddPage();
				$y = $this->marge_haute;
			}
			$pdf->SetXY($this->marge_gauche, $y);
			$pdf->SetFillColor(242, 242, 242);
			$pdf->SetFont($font, 'B', $fs - 1);
			$shipmentTitle = $outputlangs->transnoentities('ShipmentRef').': '.(string) ($shipment['shipment_ref'] ?? '');
			if (!empty($shipment['shipment_date'])) {
				$shipmentTitle .= ' — '.$outputlangs->transnoentities('Date').': '.dol_print_date($shipment['shipment_date'], 'day', false, $outputlangs);
			}
			$pdf->Cell($usable, 7, $toText($shipmentTitle), 1, 1, 'L', true);

			if (!empty($shipment['order_refs']) && is_array($shipment['order_refs'])) {
				$pdf->SetFont($font, '', $fs - 2);
				$pdf->Cell($usable, 6, $toText($outputlangs->transnoentities('Order').': '.implode(', ', $shipment['order_refs'])), 1, 1, 'L');
			}

			$printTableHeader();

			foreach ((array) ($shipment['groups'] ?? array()) as $item) {
				$label = trim((string) $item['product_ref'].' - '.(string) $item['product_label'], ' -');
				$serials = !empty($item['serials'])
					? implode(', ', $item['serials'])
					: $outputlangs->transnoentities('WarrantyLetterNoSerial');
				$description = $label."\n".$outputlangs->transnoentities('WarrantyLetterSerials').': '.$serials;
				$text = $toText($description);
				$pdf->SetFont($font, '', $fs - 2);
				$rowHeight = max(11, ($pdf->getNumLines($text, $c1 - 4) * 4.2) + 3);

				if ($pdf->GetY() + $rowHeight > $this->page_hauteur - $this->marge_basse - 15) {
					pdf_pagefoot($pdf, $outputlangs, 'MAIN_PDF_FOOTER_TEXT', $mysoc, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, 1, 1, $this->page_largeur);
					$pdf->AddPage();
					$pdf->SetXY($this->marge_gauche, $this->marge_haute);
					$pdf->SetFillColor(242, 242, 242);
					$pdf->SetFont($font, 'B', $fs - 1);
					$pdf->Cell($usable, 7, $toText($outputlangs->transnoentities('ShipmentRef').': '.(string) ($shipment['shipment_ref'] ?? '')), 1, 1, 'L', true);
					$printTableHeader();
				}

				$yRow = $pdf->GetY();
				$pdf->MultiCell($c1, $rowHeight, $text, 1, 'L', false, 0);
				$pdf->MultiCell($c2, $rowHeight, $toText((string) $item['qty']), 1, 'C', false, 0);
				$pdf->MultiCell($c3, $rowHeight, $toText(dol_print_date($item['start_date'], 'day', false, $outputlangs)), 1, 'C', false, 0);
				$pdf->MultiCell($c4, $rowHeight, $toText(dol_print_date($item['expiry_date'], 'day', false, $outputlangs)), 1, 'C', false, 1);
				$pdf->SetY($yRow + $rowHeight);
			}

			$y = $pdf->GetY() + ($shipmentIndex < count($data['shipments']) - 1 ? 6 : 0);
		}

		$pdf->SetY($y + 5);
		$pdf->SetFont($font, '', $fs - 2);
		$pdf->MultiCell($usable, 5, $toText($outputlangs->transnoentities('WarrantyLetterFooterNote')), 0, 'L');

		pdf_pagefoot($pdf, $outputlangs, 'MAIN_PDF_FOOTER_TEXT', $mysoc, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, 1, 1, $this->page_largeur);
		$pdf->Output($path, 'F');
		$this->result = array('fullpath'=>$path);
		return 1;
	}
}
