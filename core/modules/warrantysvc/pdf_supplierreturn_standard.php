<?php
/* Copyright (C) 2026 DPG Supply */

require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/class/svcsupplierreturn.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/warrantysvc/modules_warrantysvc.php';

class pdf_supplierreturn_standard extends ModelePDFWarrantySvc
{
	public $db;
	public $name = 'supplierreturn_standard';
	public $description = 'SupplierReturnPdfStandardDesc';
	public $type = 'pdf';
	public $version = 1;
	public $update_main_doc_field = 1;
	public $marge_gauche;
	public $marge_droite;
	public $marge_haute;
	public $marge_basse;
	public $page_largeur;
	public $page_hauteur;
	public $page_unit;

	public function __construct($db)
	{
		global $langs;
		$this->db = $db;
		$langs->load('warrantysvc@warrantysvc');
		$this->description = $langs->trans('SupplierReturnPdfStandardDesc');
		$this->page_orientation = 'P';
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
		$outputlangs->loadLangs(array('main','companies','products','stocks','warrantysvc@warrantysvc'));

		if (empty($object->lines)) $object->fetchLines();
		$object->socid = (int) $object->fk_soc_supplier;
		if (!is_object($object->thirdparty)) $object->fetch_thirdparty();

		$dir = warrantysvc_supplier_return_output_dir($object);
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			$this->error = $langs->trans('ErrorCanNotCreateDir', $dir);
			return -1;
		}
		$filepath = $dir.'/'.dol_sanitizeFileName($object->ref).'.pdf';

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

		// Header
		if (!empty($mysoc->logo) && !getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			$logodir = !empty($conf->mycompany->multidir_output[$object->entity]) ? $conf->mycompany->multidir_output[$object->entity] : $conf->mycompany->dir_output;
			$logo = $logodir.'/logos/'.$mysoc->logo;
			if (is_readable($logo)) $pdf->Image($logo, $this->marge_gauche, $this->marge_haute, 0, min(18, pdf_getHeightForLogo($logo)));
		}
		$pdf->SetFont('', 'B', $fs + 4);
		$pdf->SetXY($this->marge_gauche + 90, $this->marge_haute);
		$pdf->MultiCell($usable - 90, 6, $outputlangs->transnoentities('SupplierReturnPdfNotice'), 0, 'R');
		$pdf->SetFont('', 'B', $fs + 1);
		$pdf->SetXY($this->marge_gauche + 90, $this->marge_haute + 8);
		$pdf->MultiCell($usable - 90, 5, $object->ref, 0, 'R');
		$pdf->SetFont('', '', $fs - 1);
		$pdf->SetXY($this->marge_gauche + 90, $this->marge_haute + 14);
		$pdf->MultiCell($usable - 90, 4, $outputlangs->transnoentities('DatePrinted').': '.dol_print_date(dol_now(),'day',false,$outputlangs), 0, 'R');

		$y = $this->marge_haute + 30;
		$pdf->SetDrawColor(190,190,190);
		$pdf->Line($this->marge_gauche,$y,$this->page_largeur-$this->marge_droite,$y);
		$y += 5;

		$supplierName = is_object($object->thirdparty) ? $object->thirdparty->name : '';

		// Sender / recipient blocks use Dolibarr's native PDF address formatter.
		$addressGap = 6;
		$addressWidth = ($usable - $addressGap) / 2;
		$addressHeight = 31;
		$sourceAddress = trim((string) pdf_build_address($outputlangs, $mysoc, $object->thirdparty, '', 0, 'source', $object));
		$targetAddress = is_object($object->thirdparty)
			? trim((string) pdf_build_address($outputlangs, $mysoc, $object->thirdparty, '', 0, 'target', $object))
			: '';

		$leftX = $this->marge_gauche;
		$rightX = $this->marge_gauche + $addressWidth + $addressGap;
		$pdf->Rect($leftX, $y, $addressWidth, $addressHeight);
		$pdf->Rect($rightX, $y, $addressWidth, $addressHeight);

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetXY($leftX + 2, $y + 2);
		$pdf->Cell($addressWidth - 4, 5, $outputlangs->transnoentities('SupplierReturnPdfSender'), 0, 1, 'L');
		$pdf->SetFont('', '', $fs - 2);
		$pdf->SetXY($leftX + 2, $y + 7);
		$sourceText = trim((string) $mysoc->name.($sourceAddress !== '' ? "\n".$sourceAddress : ''));
		$pdf->MultiCell($addressWidth - 4, 4, $sourceText, 0, 'L');

		$pdf->SetFont('', 'B', $fs - 1);
		$pdf->SetXY($rightX + 2, $y + 2);
		$pdf->Cell($addressWidth - 4, 5, $outputlangs->transnoentities('SupplierReturnPdfRecipient'), 0, 1, 'L');
		$pdf->SetFont('', '', $fs - 2);
		$pdf->SetXY($rightX + 2, $y + 7);
		$targetText = trim((string) $supplierName.($targetAddress !== '' ? "\n".$targetAddress : ''));
		$pdf->MultiCell($addressWidth - 4, 4, $targetText, 0, 'L');

		$y += $addressHeight + 5;

		$warehouseName = '';
		if ($object->fk_warehouse_source) {
			$wh = new Entrepot($this->db);
			if ($wh->fetch($object->fk_warehouse_source) > 0) $warehouseName = $wh->ref.' - '.$wh->lieu;
		}

		$rows = array(
			array($outputlangs->transnoentities('Ref'), $object->ref),
			array($outputlangs->transnoentities('SupplierReturnDateAuthorized'), $object->date_authorized ? dol_print_date($object->date_authorized, 'day', false, $outputlangs) : ''),
			array($outputlangs->transnoentities('SupplierReturnExternalRef'), $object->supplier_return_ref),
			array($outputlangs->transnoentities('Warehouse'), $warehouseName),
			array($outputlangs->transnoentities('OutboundCarrier'), $object->outbound_carrier),
			array($outputlangs->transnoentities('OutboundTracking'), $object->outbound_tracking),
		);
		foreach ($rows as $row) {
			if ($row[1] === '' || $row[1] === null) continue;
			$pdf->SetXY($this->marge_gauche,$y);
			$pdf->SetFont('','B',$fs-1);
			$pdf->Cell(48,5,$row[0].':',0,0,'L');
			$pdf->SetFont('','',$fs-1);
			$pdf->MultiCell($usable-48,5,$outputlangs->convToOutputCharset((string)$row[1]),0,'L');
			$y = $pdf->GetY();
		}

		if (!$hidedesc && !empty($object->reason)) {
			$y += 4;
			$pdf->SetFont('','B',$fs);
			$pdf->SetXY($this->marge_gauche,$y);
			$pdf->Cell($usable,6,$outputlangs->transnoentities('SupplierReturnReason'),0,1,'L');
			$pdf->SetFont('','',$fs-1);
			$pdf->MultiCell($usable,5,$outputlangs->convToOutputCharset(strip_tags(str_replace('<br>',"\n",$object->reason))),0,'L');
			$y = $pdf->GetY()+5;
		}

		// Lines
		$colProduct = $usable * 0.43;
		$colBatch = $usable * 0.25;
		$colQty = $usable * 0.12;
		$colReason = $usable - $colProduct - $colBatch - $colQty;
		$pdf->SetFillColor(230,230,230);
		$pdf->SetFont('','B',$fs-1);
		$pdf->SetXY($this->marge_gauche,$y);
		$pdf->Cell($colProduct,6,$outputlangs->transnoentities('Product'),1,0,'L',1);
		$pdf->Cell($colBatch,6,$outputlangs->transnoentities('SerialOrLot'),1,0,'L',1);
		$pdf->Cell($colQty,6,$outputlangs->transnoentities('Qty'),1,0,'R',1);
		$pdf->Cell($colReason,6,$outputlangs->transnoentities('Reason'),1,1,'L',1);
		$pdf->SetFont('','',$fs-2);

		foreach ($object->lines as $line) {
			$p = new Product($this->db);
			$productLabel = '#'.$line->fk_product;
			if ($p->fetch($line->fk_product) > 0) $productLabel = $p->ref.($p->label ? ' - '.$p->label : '');
			$reason = trim(strip_tags((string) $line->reason));
			if ($reason === '') {
				$reason = trim(strip_tags((string) $object->reason));
			}
			$rowh = 6 * max(
				1,
				$pdf->getNumLines($outputlangs->convToOutputCharset($productLabel), $colProduct),
				$pdf->getNumLines($outputlangs->convToOutputCharset($reason), $colReason)
			);
			if ($pdf->GetY() + $rowh > $this->page_hauteur - $this->marge_basse - 15) {
				$pdf->AddPage();
				$pdf->SetXY($this->marge_gauche,$this->marge_haute);
			}
			$yrow = $pdf->GetY();
			$pdf->SetXY($this->marge_gauche,$yrow);
			$pdf->MultiCell($colProduct,$rowh,$outputlangs->convToOutputCharset($productLabel),1,'L',false,0);
			$pdf->MultiCell($colBatch,$rowh,$outputlangs->convToOutputCharset((string)$line->batch),1,'L',false,0);
			$pdf->MultiCell($colQty,$rowh,price($line->qty,0,$outputlangs,0,0,-1),1,'R',false,0);
			$pdf->MultiCell($colReason,$rowh,$outputlangs->convToOutputCharset($reason),1,'L',false,1);
		}

		pdf_pagefoot($pdf,$outputlangs,'MAIN_PDF_FOOTER_TEXT',$mysoc,$this->marge_basse,$this->marge_gauche,$this->page_hauteur,$object,1,1,$this->page_largeur);
		$pdf->Output($filepath,'F');
		$this->result = array('fullpath'=>$filepath);
		return 1;
	}
}
