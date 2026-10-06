<?php
/* Copyright (C) 2026 DPG Supply */
/**
 * Native TCPDF/CommmonDocGenerator model for one frozen warranty letter version.
 * Does not read live warranty records: rendering always uses the saved snapshot.
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/warrantysvc/modules_warrantysvc.php';

class pdf_warrantyletter_standard extends ModelePDFWarrantySvc
{
    public $name = 'warrantyletter_standard';
    public $description = 'WarrantyLetterPdfStandardDesc';
    public $type = 'pdf';
    public $version = 1;
    public $db;
    public $error = '';

    public function __construct($db) { $this->db = $db; }

    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $conf;
        $data = $object->pending_snapshot;
        $revision = (int) $object->pending_version;
        if (!is_array($data) || $revision < 1) { $this->error = 'WarrantyLetterSnapshotMissing'; return -1; }
        $base = rtrim($conf->warrantysvc->dir_output, '/').'/letters/'.dol_sanitizeFileName($object->ref);
        if (!is_dir($base) && dol_mkdir($base) < 0) { $this->error = 'ErrorCanNotCreateDir'; return -1; }
        $path = $base.'/'.dol_sanitizeFileName($object->ref).'_v'.$revision.'.pdf';
        if (file_exists($path)) { $this->error = 'WarrantyLetterVersionFileExists'; return -1; }

        $outputlangs->loadLangs(array('warrantysvc@warrantysvc','main','products','sendings','orders'));
        $format = pdf_getFormat();
        $pdf = pdf_getInstance(array($format['width'], $format['height']), $format['unit'], 'P');
        if (method_exists($pdf, 'setPrintHeader')) $pdf->setPrintHeader(false);
        if (method_exists($pdf, 'setPrintFooter')) $pdf->setPrintFooter(false);
        $font = pdf_getPDFFont($outputlangs);
        if (class_exists('TCPDF') && !getDolGlobalString('MAIN_PDF_FORCE_FONT')) $font = 'dejavusans';
        $pdf->SetFont($font, '', 9);
        $pdf->SetMargins(12, 14, 12);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();
        $pageWidth = (float) $format['width'];
        $usable = $pageWidth - 24;
        $toText = static function ($v) use ($outputlangs) { return $outputlangs->convToOutputCharset((string) $v); };
        // Follow the native Dolibarr PDF convention for issuer logos.
        // The PDF revision remains immutable after this initial rendering.
        global $mysoc;
        if (!empty($mysoc->logo) && !getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
            $logo = $conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
            if (is_readable($logo)) {
                $pdf->Image($logo, 12, 14, 0, min(15, pdf_getHeightForLogo($logo)));
            }
        }
        $pdf->SetFont($font, 'B', 17);
        $pdf->SetX(42);
        $pdf->Cell($usable - 30, 11, $toText($outputlangs->transnoentities('WarrantyLetterTitle')), 0, 1, 'C');
        $pdf->SetFont($font, '', 9);
        $pdf->SetX(42);
        $pdf->Cell($usable - 30, 6, $toText($data['letter_ref'].' / v'.$revision), 0, 1, 'C');
        $pdf->Ln(5);

        $half = ($usable - 8) / 2;
        $y = $pdf->GetY();
        $pdf->SetFont($font, 'B', 9);
        $pdf->SetXY(12, $y);
        $pdf->Cell($half, 6, $toText($outputlangs->transnoentities('WarrantyLetterIssuer')), 0, 0);
        $pdf->SetXY(20 + $half, $y);
        $pdf->Cell($half, 6, $toText($outputlangs->transnoentities('WarrantyLetterCustomer')), 0, 1);
        $pdf->SetFont($font, '', 8.5);
        $pdf->SetXY(12, $y + 7);
        $pdf->MultiCell($half, 5, $toText($data['issuer']), 0, 'L', false, 0);
        $leftEnd = $pdf->GetY();
        $pdf->SetXY(20 + $half, $y + 7);
        $pdf->MultiCell($half, 5, $toText($data['buyer']), 0, 'L', false, 1);
        $pdf->SetY(max($pdf->GetY(), $leftEnd, $y + 32));
        $pdf->Ln(3);
        $entries = array(
            array('WarrantyLetterIssuedAt', substr($data['issued_at'], 0, 10)),
            array('Order', $data['order_ref']),
            array('ShipmentRef', $data['shipment_ref']),
        );
        foreach ($entries as $entry) {
            $pdf->SetFont($font, 'B', 9);
            $pdf->Cell(48, 6, $toText($outputlangs->transnoentities($entry[0])), 0, 0);
            $pdf->SetFont($font, '', 9);
            $pdf->Cell($usable - 48, 6, $toText($entry[1]), 0, 1);
        }
        $pdf->Ln(6);
        $pdf->SetFont($font, 'B', 10);
        $pdf->Cell($usable, 8, $toText($outputlangs->transnoentities('WarrantyLetterCoveredProducts')), 0, 1);

        $c1 = $usable * .40; $c2 = $usable * .10; $c3 = $usable * .25; $c4 = $usable * .25;
        $printHeader = static function () use ($pdf, $outputlangs, $toText, $font, $c1, $c2, $c3, $c4) {
            $pdf->SetFillColor(228, 231, 234);
            $pdf->SetFont($font, 'B', 8);
            $pdf->Cell($c1, 8, $toText($outputlangs->transnoentities('Product')), 1, 0, 'L', true);
            $pdf->Cell($c2, 8, $toText($outputlangs->transnoentities('Qty')), 1, 0, 'C', true);
            $pdf->Cell($c3, 8, $toText($outputlangs->transnoentities('WarrantyStart')), 1, 0, 'C', true);
            $pdf->Cell($c4, 8, $toText($outputlangs->transnoentities('WarrantyExpiry')), 1, 1, 'C', true);
        };
        $printHeader();
        foreach ($data['groups'] as $item) {
            $label = trim($item['product_ref'].' - '.$item['product_label'], ' -');
            $serials = !empty($item['serials']) ? implode(', ', $item['serials']) : $outputlangs->transnoentities('WarrantyLetterNoSerial');
            $description = $label."\n".$outputlangs->transnoentities('WarrantyLetterSerials').': '.$serials;
            $text = $toText($description);
            $pdf->SetFont($font, '', 8);
            $height = max(12, ($pdf->getNumLines($text, $c1 - 4) * 4.5) + 4);
            if ($pdf->GetY() + $height > (float) $format['height'] - 20) {
                $pdf->AddPage();
                $printHeader();
            }
            $y = $pdf->GetY();
            $pdf->MultiCell($c1, $height, $text, 1, 'L', false, 0);
            $pdf->MultiCell($c2, $height, $toText((string) $item['qty']), 1, 'C', false, 0);
            $pdf->MultiCell($c3, $height, $toText(substr($item['start_date'], 0, 10)), 1, 'C', false, 0);
            $pdf->MultiCell($c4, $height, $toText(substr($item['expiry_date'], 0, 10)), 1, 'C', false, 1);
            $pdf->SetY($y + $height);
        }
        $pdf->Ln(7);
        $pdf->SetFont($font, '', 8);
        $pdf->MultiCell($usable, 5, $toText($outputlangs->transnoentities('WarrantyLetterFooterNote')), 0, 'L');
        $pdf->Output($path, 'F');
        $this->result = array('fullpath'=>$path);
        return 1;
    }
}
