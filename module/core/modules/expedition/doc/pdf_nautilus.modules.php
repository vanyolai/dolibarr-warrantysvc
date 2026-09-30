<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

/**
 * \file       core/modules/expedition/doc/pdf_nautilus.modules.php
 * \ingroup    warrantysvc
 * \brief      Digital Nautics shipment PDF model based on Espadon.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/expedition/doc/pdf_espadon.modules.php';
require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/lib/warrantysvc.lib.php';

/**
 * Digital Nautics shipment PDF.
 *
 * Nautilus intentionally inherits Espadon's mature page-breaking, header,
 * address, totals and extra-field machinery, while customising the line table
 * for the way Digital Nautics uses shipment documents.
 */
class pdf_nautilus extends pdf_espadon
{
    /** Synthetic PDF-only column key; no Shipment-line extrafield is created. */
    private const WARRANTY_EXPIRY_COLUMN = 'warrantysvc_warranty_expiry';

    /** @var bool Whether the current shipment has at least one Product-field warranty expiry. */
    private $showWarrantyExpiryColumn = false;
    /**
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        global $langs;

        parent::__construct($db);

        $langs->load('warrantysvc@warrantysvc');
        $this->name = 'nautilus';
        $this->description = $langs->trans('DocumentModelNautilus');
        $this->version = 'dolibarr';
    }

    /**
     * Define a compact shipment table.
     *
     * Weight is displayed only when at least one physical product has a
     * meaningful configured weight. Volume is intentionally suppressed in
     * Nautilus. In WarrantySvc Product-field mode, a
     * PDF-only warranty-expiry column is added when at least one shipped
     * physical Product has a configured warranty period.
     *
     * @param CommonObject $object Shipment object
     * @param Translate $outputlangs Output language
     * @param int<0,1> $hidedetails Hide line details
     * @param int<0,1> $hidedesc Hide description
     * @param int<0,1> $hideref Hide product reference
     * @return void
     */
    public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        parent::defineColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);

        // The line number adds little value on a shipment and costs useful width.
        if (isset($this->cols['position'])) {
            $this->cols['position']['status'] = false;
        }

        // Do not reserve an empty weight column. Nautilus intentionally ignores
        // Product volume: only configured weight makes this column relevant.
        $hasWeight = false;
        if (!empty($object->lines) && is_array($object->lines)) {
            foreach ($object->lines as $line) {
                $productType = isset($line->fk_product_type)
                    ? (int) $line->fk_product_type
                    : (isset($line->product_type) ? (int) $line->product_type : 0);
                if ($productType !== 0) {
                    continue;
                }
                if (!empty($line->weight)) {
                    $hasWeight = true;
                    break;
                }
            }
        }
        if (isset($this->cols['weight'])) {
            $this->cols['weight']['status'] = $hasWeight;
            $this->cols['weight']['width'] = 24;
            // Espadon labels this shared column as Weight/Volume. Nautilus only
            // renders weight, so reuse Dolibarr's native "Weight" translation.
            $this->cols['weight']['title']['textkey'] = 'Weight';
        }

        // Compact quantity columns leave more room for product descriptions.
        if (isset($this->cols['qty_asked'])) {
            $this->cols['qty_asked']['width'] = 22;
        }
        if (isset($this->cols['unit_order'])) {
            $this->cols['unit_order']['width'] = 18;
        }
        if (isset($this->cols['qty_shipped'])) {
            $this->cols['qty_shipped']['width'] = 22;
        }

        // Product-field warranty expiry is a PDF-only value. WarrantySvc keeps
        // the authoritative warranty snapshot on SvcWarranty; Nautilus never
        // creates or synchronizes a Shipment-line extra field.
        if ($this->showWarrantyExpiryColumn) {
            $this->insertNewColumnDef(
                self::WARRANTY_EXPIRY_COLUMN,
                array(
                    'rank' => 10000,
                    'width' => 27,
                    'status' => true,
                    'title' => array(
                        'textkey' => 'NautilusWarrantyExpiry',
                        'align' => 'C',
                    ),
                    'content' => array(
                        'align' => 'C',
                    ),
                    'border-left' => true,
                )
            );
        }
    }

    /**
     * Decorate shipment lines with a transient warranty-expiry value and let
     * Espadon perform the actual document generation.
     *
     * Nothing is persisted on the Shipment or its lines. WarrantySvc remains
     * authoritative for warranty creation and snapshots; Nautilus is only a
     * presentation model.
     *
     * @param Expedition $object Shipment
     * @param Translate $outputlangs Output language
     * @param string $srctemplatepath Source template path
     * @param int<0,1> $hidedetails Hide details
     * @param int<0,1> $hidedesc Hide description
     * @param int<0,1> $hideref Hide reference
     * @return int<-1,1>
     */
    public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
    {
        global $conf;

        $this->showWarrantyExpiryColumn = false;
        $decoratedLines = array();

        // Espadon has one shared Weight/Volume column and can suppress volume
        // through a standard Dolibarr setting. Force that setting only for the
        // duration of Nautilus generation, then restore the caller's state.
        $hadHideVolume = isset($conf->global->SHIPPING_PDF_HIDE_VOLUME);
        $previousHideVolume = $hadHideVolume ? $conf->global->SHIPPING_PDF_HIDE_VOLUME : null;
        $conf->global->SHIPPING_PDF_HIDE_VOLUME = 1;

        if (warrantysvc_uses_product_months() && !empty($object->lines) && is_array($object->lines)) {
            $startDate = warrantysvc_resolve_shipment_start_date($this->db, $object);

            if ($startDate !== null) {
                $entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;

                foreach ($object->lines as $index => $line) {
                    $productId = !empty($line->fk_product) ? (int) $line->fk_product : 0;
                    $productType = isset($line->fk_product_type)
                        ? (int) $line->fk_product_type
                        : (isset($line->product_type) ? (int) $line->product_type : 0);

                    if ($productId <= 0 || $productType !== 0) {
                        continue;
                    }

                    $periodError = '';
                    $period = warrantysvc_compute_product_warranty_period(
                        $this->db,
                        $productId,
                        $entity,
                        (int) $startDate,
                        $periodError
                    );

                    if ($periodError !== '') {
                        dol_syslog(__METHOD__.' Product '.$productId.': '.$periodError, LOG_WARNING);
                        continue;
                    }
                    if ($period === null || empty($period['expiry'])) {
                        continue;
                    }

                    if (!isset($line->array_options) || !is_array($line->array_options)) {
                        $line->array_options = array();
                    }
                    $line->array_options[self::WARRANTY_EXPIRY_COLUMN] = dol_print_date(
                        (int) $period['expiry'],
                        'day',
                        'tzserver',
                        $outputlangs
                    );
                    $decoratedLines[] = $index;
                    $this->showWarrantyExpiryColumn = true;
                }
            }
        }

        // Nautilus is a presentation model: consolidate repeated serialized
        // shipment lines only for the generated PDF, then restore the original
        // Shipment lines unchanged.
        $originalLines = $object->lines;
        if (!empty($object->lines) && is_array($object->lines)) {
            $object->lines = $this->groupShipmentLinesForPdf($object->lines);
        }

        try {
            return parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);
        } finally {
            $object->lines = $originalLines;
            foreach ($decoratedLines as $index) {
                if (isset($object->lines[$index]->array_options[self::WARRANTY_EXPIRY_COLUMN])) {
                    unset($object->lines[$index]->array_options[self::WARRANTY_EXPIRY_COLUMN]);
                }
            }
            $this->showWarrantyExpiryColumn = false;

            if ($hadHideVolume) {
                $conf->global->SHIPPING_PDF_HIDE_VOLUME = $previousHideVolume;
            } else {
                unset($conf->global->SHIPPING_PDF_HIDE_VOLUME);
            }
        }
    }

    /**
     * Return the transient WarrantySvc expiry value without pretending it is a
     * real Dolibarr extra field. All genuine extra fields remain delegated to
     * the parent implementation.
     *
     * @param CommonObject $object Shipment line
     * @param string $extrafieldKey Column key
     * @param Translate|null $outputlangs Output language
     * @return string
     */
    public function getExtrafieldContent($object, $extrafieldKey, $outputlangs = null)
    {
        if ($extrafieldKey === self::WARRANTY_EXPIRY_COLUMN) {
            return isset($object->array_options[self::WARRANTY_EXPIRY_COLUMN])
                ? (string) $object->array_options[self::WARRANTY_EXPIRY_COLUMN]
                : '';
        }

        return parent::getExtrafieldContent($object, $extrafieldKey, $outputlangs);
    }

    /**
     * Print a shipment description without Espadon's redundant lot metadata.
     *
     * Core pdf_getlinedesc() is reused for the normal product reference, label,
     * description, variants, periods and barcode behaviour. Batch data is
     * temporarily removed from the line so core does not append sell-by/eat-by
     * dates and a duplicate quantity. We then add back only the information a
     * shipment recipient needs:
     *
     * - lot/serial number;
     * - quantity only when it carries information (for example a line split
     *   between several lots with quantities greater than one);
     * - warehouse only when Dolibarr's existing batch/warehouse option asks for
     *   it.
     *
     * @param TCPDI|TCPDF $pdf PDF object
     * @param float $curY Current Y position
     * @param string $colKey Column key
     * @param CommonObject $object Shipment
     * @param int $i Line index
     * @param Translate $outputlangs Output language
     * @param int<0,1> $hideref Hide product reference
     * @param int<0,1> $hidedesc Hide description
     * @param int<0,1> $issupplierline Supplier-line mode
     * @return void
     */
    public function printColDescContent($pdf, &$curY, $colKey, $object, $i, $outputlangs, $hideref = 0, $hidedesc = 0, $issupplierline = 0)
    {
        global $hookmanager;

        $colDef = $this->cols[$colKey];
        $currentCellPaddings = $pdf->getCellPaddings();
        $pdf->setCellPaddings(
            $colDef['content']['padding'][3],
            $colDef['content']['padding'][0],
            $colDef['content']['padding'][1],
            $colDef['content']['padding'][2]
        );

        $line = $object->lines[$i];
        $batchDetails = !empty($line->detail_batch) && is_array($line->detail_batch)
            ? $line->detail_batch
            : array();

        // Reuse Dolibarr's standard description builder, but without its batch
        // suffix. This keeps us compatible with product/variant/translation and
        // description settings without copying core logic.
        $savedBatchDetails = $line->detail_batch ?? null;
        $line->detail_batch = false;
        $description = pdf_getlinedesc($object, $i, $outputlangs, $hideref, $hidedesc, $issupplierline);
        $line->detail_batch = $savedBatchDetails;

        if (!empty($batchDetails)) {
            $outputlangs->load('productbatch');

            $showBatchQty = $this->batchQuantityAddsInformation($batchDetails);
            $showWarehouse = getDolGlobalInt('PRODUCTBATCH_SHOW_WAREHOUSE_ON_SHIPMENT');
            $tmpWarehouse = null;
            $tmpProductBatch = null;

            if ($showWarehouse) {
                include_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
                include_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';
                $tmpWarehouse = new Entrepot($this->db);
                $tmpProductBatch = new Productbatch($this->db);
            }

            foreach ($batchDetails as $detail) {
                if (empty($detail->batch)) {
                    continue;
                }

                $parts = array();
                $parts[] = $outputlangs->transnoentitiesnoconv('printBatch', $detail->batch);

                if ($showBatchQty && !empty($detail->qty)) {
                    $parts[] = $outputlangs->transnoentitiesnoconv('printQty', (string) $detail->qty);
                }

                if ($showWarehouse && !empty($detail->fk_origin_stock) && $tmpProductBatch instanceof Productbatch && $tmpWarehouse instanceof Entrepot) {
                    if ($tmpProductBatch->fetch((int) $detail->fk_origin_stock) > 0 && $tmpWarehouse->fetch((int) $tmpProductBatch->warehouseid) > 0) {
                        $parts[] = $tmpWarehouse->ref;
                    }
                }

                $description .= '<br>'.dol_htmlentitiesbr(implode(' - ', $parts), 1);
            }
        }

        $align = empty($colDef['content']['align']) ? 'J' : $colDef['content']['align'];
        $pdf->SetXY($colDef['xStartPos'], $curY);
        $pdf->writeHTMLCell($colDef['width'], 3, $colDef['xStartPos'], $curY, $description, 0, 1, false, true, $align);
        $posYAfterDescription = $pdf->GetY() - $colDef['content']['padding'][0];

        $pdf->setCellPaddings(
            $currentCellPaddings['L'],
            $currentCellPaddings['T'],
            $currentCellPaddings['R'],
            $currentCellPaddings['B']
        );

        // Preserve the normal Dolibarr handling of extra fields configured to be
        // printed below the line description instead of as dedicated columns.
        $params = array(
            'display' => 'list',
            'printableEnable' => array(3),
            'printableEnableNotEmpty' => array(4),
        );
        $extrafieldDesc = $this->getExtrafieldsInHtml($line, $outputlangs, $params);
        if (!empty($extrafieldDesc)) {
            $this->printStdColumnContent($pdf, $posYAfterDescription, $colKey, $extrafieldDesc);
        }

        $parameters = array(
            'curY' => &$curY,
            'colKey' => $colKey,
            'object' => $object,
            'i' => $i,
            'outputlangs' => $outputlangs,
            'pdf' => &$pdf,
        );
        $reshook = $hookmanager->executeHooks('printColDescContent', $parameters, $this);
        if ($reshook < 0) {
            setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
        }
    }

    /**
     * Merge repeated serialized/LOT shipment lines for PDF presentation only.
     *
     * A group must have the same Product, rendered warranty expiry and other
     * printable line semantics. Therefore different warranty expiry dates
     * always remain separate PDF rows while equal rows list all serials below
     * the Product description.
     *
     * @param array<int,object> $lines Shipment lines
     * @return array<int,object>
     */
    private function groupShipmentLinesForPdf(array $lines)
    {
        $grouped = array();
        $positions = array();

        foreach ($lines as $line) {
            $batchDetails = !empty($line->detail_batch) && is_array($line->detail_batch)
                ? $line->detail_batch
                : array();
            $productId = !empty($line->fk_product) ? (int) $line->fk_product : 0;
            $productType = isset($line->fk_product_type)
                ? (int) $line->fk_product_type
                : (isset($line->product_type) ? (int) $line->product_type : 0);

            // Ordinary/non-batch lines retain their original row structure.
            if ($productId <= 0 || $productType !== 0 || empty($batchDetails)
                || (!empty($line->special_code) && (int) $line->special_code === SUBTOTALS_SPECIAL_CODE)
            ) {
                $grouped[] = $line;
                continue;
            }

            $options = !empty($line->array_options) && is_array($line->array_options)
                ? $line->array_options
                : array();
            ksort($options);

            // Keep prices/discounts in the key too: if the optional amount
            // columns are enabled, lines with different commercial semantics
            // must not be collapsed merely because the Product is identical.
            $groupKey = implode("\x1f", array(
                (string) $productId,
                isset($options[self::WARRANTY_EXPIRY_COLUMN]) ? (string) $options[self::WARRANTY_EXPIRY_COLUMN] : '',
                isset($line->fk_unit) ? (string) $line->fk_unit : '',
                isset($line->desc) ? (string) $line->desc : '',
                isset($line->product_label) ? (string) $line->product_label : '',
                isset($line->weight) ? (string) $line->weight : '',
                isset($line->weight_units) ? (string) $line->weight_units : '',
                isset($line->subprice) ? (string) $line->subprice : '',
                isset($line->remise_percent) ? (string) $line->remise_percent : '',
                isset($line->tva_tx) ? (string) $line->tva_tx : '',
                json_encode($options),
            ));

            if (!isset($positions[$groupKey])) {
                $copy = clone $line;
                $copy->detail_batch = $this->mergeBatchDetailsForPdf(array(), $batchDetails);
                $positions[$groupKey] = count($grouped);
                $grouped[] = $copy;
                continue;
            }

            $pos = $positions[$groupKey];
            $target = $grouped[$pos];

            if (isset($target->qty_shipped) || isset($line->qty_shipped)) {
                $target->qty_shipped = (float) ($target->qty_shipped ?? 0) + (float) ($line->qty_shipped ?? 0);
            }
            if (isset($target->qty_asked) || isset($line->qty_asked)) {
                $target->qty_asked = (float) ($target->qty_asked ?? 0) + (float) ($line->qty_asked ?? 0);
            }
            if (isset($target->qty) || isset($line->qty)) {
                $target->qty = (float) ($target->qty ?? 0) + (float) ($line->qty ?? 0);
            }
            foreach (array('total_ht', 'total_tva', 'total_ttc', 'multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc') as $totalField) {
                if (isset($target->{$totalField}) || isset($line->{$totalField})) {
                    $target->{$totalField} = (float) ($target->{$totalField} ?? 0) + (float) ($line->{$totalField} ?? 0);
                }
            }

            $target->detail_batch = $this->mergeBatchDetailsForPdf(
                !empty($target->detail_batch) && is_array($target->detail_batch) ? $target->detail_batch : array(),
                $batchDetails
            );
            $grouped[$pos] = $target;
        }

        return $grouped;
    }

    /**
     * Merge LOT/serial detail rows for a consolidated PDF line.
     *
     * @param array<int,object> $base Existing details
     * @param array<int,object> $extra Additional details
     * @return array<int,object>
     */
    private function mergeBatchDetailsForPdf(array $base, array $extra)
    {
        $merged = array();
        $positions = array();

        foreach (array_merge($base, $extra) as $detail) {
            if (!is_object($detail)) {
                continue;
            }

            $key = (string) ($detail->batch ?? '')."\x1f".(string) ($detail->fk_origin_stock ?? 0);
            if (!isset($positions[$key])) {
                $copy = clone $detail;
                $positions[$key] = count($merged);
                $merged[] = $copy;
                continue;
            }

            $pos = $positions[$key];
            $merged[$pos]->qty = (float) ($merged[$pos]->qty ?? 0) + (float) ($detail->qty ?? 0);
        }

        return $merged;
    }
    /**
     * Decide whether per-batch quantity adds useful information.
     *
     * Single-lot lines and ordinary serial-number lines (all detail quantities
     * are exactly one) already have their total in the dedicated shipped-qty
     * column, so repeating "Quantity: 1" only creates noise. A genuine split
     * across lot quantities retains the per-lot quantity.
     *
     * @param array<int,object> $batchDetails Batch detail rows
     * @return bool
     */
    private function batchQuantityAddsInformation(array $batchDetails)
    {
        if (count($batchDetails) <= 1) {
            return false;
        }

        foreach ($batchDetails as $detail) {
            if (isset($detail->qty) && (float) $detail->qty !== 1.0) {
                return true;
            }
        }

        return false;
    }
}
