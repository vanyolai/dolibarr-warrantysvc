<?php
/* Copyright (C) 2026 DPG Supply */
/**
 * Immutable, shipment-scoped warranty letter and PDF revision registry.
 * Warranty duration, LOT/SN allocation and shipment creation remain untouched.
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

class SvcWarrantyLetter extends CommonObject
{
    public $module = 'warrantysvc';
    public $element = 'svcwarrantyletter';
    public $table_element = 'svc_warranty_letter';
    public $picto = 'pdf';
    public $TRIGGER_PREFIX = 'SVCWARRANTYLETTER';
    protected $table_ref_field = 'ref';
    const STATUS_DRAFT = 'draft';
    const STATUS_READY = 'ready';
    const STATUS_SENT = 'sent';
    const STATUS_UPDATED = 'updated';
    const STATUS_STALE = 'stale';

    public $ref = '';
    public $entity = 0;
    public $fk_expedition = 0;
    public $fk_commande = 0;
    public $fk_soc = 0;
    public $socid = 0;
    public $status = self::STATUS_DRAFT;
    public $current_version = 0;
    public $last_sent_version = 0;
    public $date_creation;
    public $model_pdf = 'warrantyletter_standard';
    public $last_main_doc = '';
    public $pending_snapshot;
    public $pending_version;
    public $thirdparty;
    public $context = array();

    public $fields = array(
        'rowid' => array('type'=>'integer', 'label'=>'TechnicalID', 'enabled'=>1, 'visible'=>-1),
        'ref' => array('type'=>'varchar(50)', 'label'=>'Ref', 'enabled'=>1, 'visible'=>1),
        'entity' => array('type'=>'integer', 'label'=>'Entity', 'enabled'=>1, 'visible'=>-2),
        'fk_expedition' => array('type'=>'integer', 'label'=>'ShipmentRef', 'enabled'=>1, 'visible'=>1),
        'fk_commande' => array('type'=>'integer', 'label'=>'Order', 'enabled'=>1, 'visible'=>1),
        'fk_soc' => array('type'=>'integer', 'label'=>'Customer', 'enabled'=>1, 'visible'=>1),
        'status' => array('type'=>'varchar(20)', 'label'=>'Status', 'enabled'=>1, 'visible'=>1),
        'current_version' => array('type'=>'integer', 'label'=>'WarrantyLetterVersion', 'enabled'=>1),
        'last_sent_version' => array('type'=>'integer', 'label'=>'WarrantyLetterLastSentVersion', 'enabled'=>1),
        'model_pdf' => array('type'=>'varchar(255)', 'label'=>'ModelPdf', 'enabled'=>1, 'visible'=>-2),
        'last_main_doc' => array('type'=>'varchar(255)', 'label'=>'LastMainDoc', 'enabled'=>1, 'visible'=>-2),
        'date_creation' => array('type'=>'datetime', 'label'=>'DateCreation', 'enabled'=>1, 'visible'=>-2),
    );

    public function __construct($db) { $this->db = $db; }

    public function fetch($id, $ref = '')
    {
        global $conf;
        if ((int) $id <= 0 && $ref === '') return 0;
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'svc_warranty_letter WHERE entity = '.((int) $conf->entity);
        $sql .= ((int) $id > 0) ? ' AND rowid = '.((int) $id) : " AND ref = '".$this->db->escape($ref)."'";
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }
        $row = $this->db->fetch_object($res);
        $this->db->free($res);
        if (!$row) return 0;
        foreach (array('rowid', 'entity', 'fk_expedition', 'fk_commande', 'fk_soc', 'current_version', 'last_sent_version') as $field) {
            $this->{$field === 'rowid' ? 'id' : $field} = (int) $row->{$field};
        }
        foreach (array('ref', 'status', 'model_pdf', 'last_main_doc') as $field) {
            $this->{$field} = (string) $row->{$field};
        }
        if ($this->model_pdf === '') $this->model_pdf = 'warrantyletter_standard';
        $this->date_creation = $this->db->jdate($row->date_creation);
        $this->socid = $this->fk_soc;
        return 1;
    }

    public function fetch_thirdparty()
    {
        $this->thirdparty = new Societe($this->db);
        return $this->thirdparty->fetch((int) $this->fk_soc);
    }

    public function fetchProject() { return 0; }

    public static function findByShipment($db, $shipmentId)
    {
        global $conf;
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'svc_warranty_letter';
        $sql .= ' WHERE fk_expedition = '.((int) $shipmentId).' AND entity = '.((int) $conf->entity);
        $res = $db->query($sql);
        if (!$res) return -1;
        $row = $db->fetch_object($res);
        $db->free($res);
        return $row ? (int) $row->rowid : 0;
    }

    /**
     * Called in the shipment trigger transaction. Never creates a second letter
     * for the same shipment. The caller must generate the first revision.
     */
    public function createFromShipment($shipment, $user)
    {
        global $conf;
        $found = self::findByShipment($this->db, $shipment->id);
        if ($found < 0) { $this->error = $this->db->lasterror(); return -1; }
        if ($found > 0) return $this->fetch($found);

        $orderId = 0;
        $origin = !empty($shipment->origin_type) ? $shipment->origin_type : (!empty($shipment->origin) ? $shipment->origin : '');
        if ($origin === 'commande' && !empty($shipment->origin_id)) $orderId = (int) $shipment->origin_id;
        if (!$orderId) {
            $q = 'SELECT fk_commande FROM '.MAIN_DB_PREFIX.'svc_warranty WHERE fk_expedition = '.((int) $shipment->id);
            $q .= ' AND entity = '.((int) $conf->entity).' AND fk_commande > 0 ORDER BY rowid LIMIT 1';
            $r = $this->db->query($q);
            if ($r && ($w = $this->db->fetch_object($r))) $orderId = (int) $w->fk_commande;
        }
        $socid = !empty($shipment->socid) ? (int) $shipment->socid : 0;
        if ($socid <= 0) { $this->error = 'ErrorAutoWarrantyMissingCustomer'; return -1; }
        $this->entity = (int) $conf->entity;
        $this->fk_expedition = (int) $shipment->id;
        $this->fk_commande = $orderId;
        $this->fk_soc = $socid;
        $this->socid = $socid;
        $tempref = 'PROV-WL-'.str_replace('.', '', uniqid('', true));
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'svc_warranty_letter';
        $sql .= ' (ref, entity, fk_expedition, fk_commande, fk_soc, status, current_version, last_sent_version, model_pdf, date_creation, fk_user_creat)';
        $sql .= " VALUES ('".$this->db->escape($tempref)."', ".$this->entity.", ".$this->fk_expedition.", ";
        $sql .= ($orderId ? $orderId : 'NULL').', '.$socid.", 'draft', 0, 0, 'warrantyletter_standard', '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
        if (!$this->db->query($sql)) {
            // A concurrent trigger may have created the unique shipment row.
            $existing = self::findByShipment($this->db, $shipment->id);
            if ($existing > 0) return $this->fetch($existing);
            $this->error = $this->db->lasterror();
            return -1;
        }
        $this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'svc_warranty_letter');
        $this->ref = 'GL-'.date('Y', dol_now()).'-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
        if (!$this->db->query('UPDATE '.MAIN_DB_PREFIX."svc_warranty_letter SET ref = '".$this->db->escape($this->ref)."' WHERE rowid = ".$this->id)) {
            $this->error = $this->db->lasterror(); return -1;
        }
        if ($this->add_object_linked('shipping', $this->fk_expedition, $user) <= 0) { $this->error = 'WarrantyLetterLinkFailed'; return -1; }
        if ($this->fk_commande > 0 && $this->add_object_linked('commande', $this->fk_commande, $user) <= 0) { $this->error = 'WarrantyLetterLinkFailed'; return -1; }
        return 1;
    }

    /** Current warranty records are copied into an immutable PDF snapshot. */
    public function buildSnapshot()
    {
        global $conf, $mysoc;
        $shipment = new Expedition($this->db);
        if ($shipment->fetch((int) $this->fk_expedition) <= 0) { $this->error = 'ShipmentNotFound'; return null; }
        if ($this->fetch_thirdparty() <= 0) { $this->error = 'CustomerNotFound'; return null; }
        $orderRef = '';
        if ($this->fk_commande > 0) {
            $r = $this->db->query('SELECT ref FROM '.MAIN_DB_PREFIX.'commande WHERE rowid = '.((int) $this->fk_commande));
            if ($r && ($o = $this->db->fetch_object($r))) $orderRef = (string) $o->ref;
        }

        $sql = 'SELECT w.rowid, w.fk_product, w.serial_number, w.covered_qty, w.start_date, w.expiry_date, p.ref AS product_ref, p.label AS product_label';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'svc_warranty w LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = w.fk_product';
        $sql .= ' WHERE w.fk_expedition = '.((int) $this->fk_expedition).' AND w.entity = '.((int) $conf->entity);
        $sql .= " AND w.status <> 'voided' ORDER BY p.ref, w.start_date, w.expiry_date, w.serial_number, w.rowid";
        $result = $this->db->query($sql);
        if (!$result) { $this->error = $this->db->lasterror(); return null; }
        $groups = array(); $indices = array(); $warrantyIds = array();
        while ($row = $this->db->fetch_object($result)) {
            $key = ((int) $row->fk_product).'|'.((string) $row->start_date).'|'.((string) $row->expiry_date);
            if (!isset($indices[$key])) {
                $indices[$key] = count($groups);
                $groups[] = array(
                    'product_id'=>(int) $row->fk_product,
                    'product_ref'=>(string) $row->product_ref,
                    'product_label'=>(string) $row->product_label,
                    'start_date'=>(string) $row->start_date,
                    'expiry_date'=>(string) $row->expiry_date,
                    'qty'=>0, 'serials'=>array()
                );
            }
            $i = $indices[$key];
            $groups[$i]['qty'] += (float) $row->covered_qty;
            if ((string) $row->serial_number !== '') $groups[$i]['serials'][] = (string) $row->serial_number;
            $warrantyIds[] = (int) $row->rowid;
        }
        $this->db->free($result);
        if (!$warrantyIds) { $this->error = 'WarrantyLetterNoWarranties'; return null; }
        $myCompany = trim((string) $mysoc->name."\n".(string) $mysoc->address."\n".(string) $mysoc->zip.' '.(string) $mysoc->town);
        $buyer = trim((string) $this->thirdparty->name."\n".(string) $this->thirdparty->address."\n".(string) $this->thirdparty->zip.' '.(string) $this->thirdparty->town);
        return array(
            'letter_ref'=>$this->ref,
            'issued_at'=>date('Y-m-d H:i:s', dol_now()),
            'issuer'=>$myCompany,
            'buyer'=>$buyer,
            'order_ref'=>$orderRef,
            'shipment_ref'=>(string) $shipment->ref,
            'groups'=>$groups,
            'warranty_ids'=>$warrantyIds
        );
    }


    /** A historical version is never rewritten when a warranty changes. */
    public function isSnapshotCurrent($revision = null)
    {
        $revision = $revision ?: $this->getVersion();
        if (!$revision) return false;
        $saved = json_decode((string) $revision->snapshot, true);
        $live = $this->buildSnapshot();
        if (!is_array($saved) || !is_array($live)) return false;
        // Issue date is frozen per revision; compare all business data.
        unset($saved['issued_at'], $live['issued_at']);
        return json_encode($saved) === json_encode($live);
    }

    public function getVersions()
    {
        global $conf;
        $versions = array();
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'svc_warranty_letter_version';
        $sql .= ' WHERE fk_letter = '.((int) $this->id).' AND entity = '.((int) $conf->entity).' ORDER BY version DESC';
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return null; }
        while ($row = $this->db->fetch_object($res)) $versions[] = $row;
        $this->db->free($res);
        return $versions;
    }

    public function getVersion($number = 0)
    {
        global $conf;
        $number = $number > 0 ? $number : (int) $this->current_version;
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'svc_warranty_letter_version';
        $sql .= ' WHERE fk_letter = '.((int) $this->id).' AND version = '.((int) $number);
        $sql .= ' AND entity = '.((int) $conf->entity);
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return null; }
        $row = $this->db->fetch_object($res);
        $this->db->free($res);
        return $row ?: null;
    }

    public function versionFullPath($versionRow)
    {
        global $conf;
        return rtrim($conf->warrantysvc->dir_output, '/').'/'.(string) $versionRow->file_path;
    }

    public function verifyVersion($versionRow)
    {
        if (!$versionRow) return false;
        $path = $this->versionFullPath($versionRow);
        return is_file($path) && is_readable($path)
            && hash_equals((string) $versionRow->sha256, (string) hash_file('sha256', $path));
    }

    /**
     * Version rows and PDFs are append-only. Caller owns the DB transaction.
     * PDF generation never changes existing revisions, including unsent ones.
     */
    public function createRevision($user, $outputlangs)
    {
        global $conf;
        if ((int) $this->id <= 0) return -1;
        // Lock the letter row to serialize concurrent PDF regeneration.
        $res = $this->db->query('SELECT current_version, last_sent_version FROM '.MAIN_DB_PREFIX.'svc_warranty_letter WHERE rowid = '.((int) $this->id).' AND entity = '.((int) $conf->entity).' FOR UPDATE');
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }
        $row = $this->db->fetch_object($res);
        $this->db->free($res);
        if (!$row) { $this->error = 'WarrantyLetterNotFound'; return -1; }
        $this->current_version = (int) $row->current_version;
        $this->last_sent_version = (int) $row->last_sent_version;
        $snapshot = $this->buildSnapshot();
        if ($snapshot === null) return -1;

        $number = $this->current_version + 1;
        $relative = 'letters/'.dol_sanitizeFileName($this->ref).'/'.dol_sanitizeFileName($this->ref).'_v'.$number.'.pdf';
        $path = rtrim($conf->warrantysvc->dir_output, '/').'/'.$relative;
        if (file_exists($path)) {
            $this->error = 'WarrantyLetterVersionFileExists'; return -1;
        }
        require_once DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/warrantysvc/pdf_warrantyletter_standard.php';
        $this->pending_snapshot = $snapshot;
        $this->pending_version = $number;
        $generator = new pdf_warrantyletter_standard($this->db);
        $ok = $generator->write_file($this, $outputlangs);
        unset($this->pending_snapshot, $this->pending_version);
        if ($ok <= 0 || !is_file($path)) {
            $this->error = !empty($generator->error) ? $generator->error : 'WarrantyLetterPdfFailed';
            return -1;
        }
        $hash = hash_file('sha256', $path);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            @unlink($path);
            $this->error = 'WarrantyLetterSnapshotFailed'; return -1;
        }
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'svc_warranty_letter_version';
        $sql .= ' (entity, fk_letter, version, snapshot, file_path, sha256, date_creation, fk_user_creat) VALUES (';
        $sql .= ((int) $conf->entity).', '.((int) $this->id).', '.$number;
        $sql .= ", '".$this->db->escape($json)."', '".$this->db->escape($relative)."', '".$this->db->escape($hash)."', '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
        if (!$this->db->query($sql)) {
            @unlink($path);
            $this->error = $this->db->lasterror(); return -1;
        }
        // Native links to the individual warranties, including additions since v1.
        foreach ($snapshot['warranty_ids'] as $warrantyId) {
            // Dolibarr's add_object_linked() does not deduplicate on its own.
            $sqlCheck = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'element_element';
            $sqlCheck .= ' WHERE fk_source = '.((int) $warrantyId);
            $sqlCheck .= " AND sourcetype = 'warrantysvc_svcwarranty'";
            $sqlCheck .= ' AND fk_target = '.((int) $this->id);
            $sqlCheck .= " AND targettype = 'warrantysvc_svcwarrantyletter'";
            $r = $this->db->query($sqlCheck);
            if (!$r) { $this->error = $this->db->lasterror(); return -1; }
            $linked = (bool) $this->db->fetch_object($r);
            $this->db->free($r);
            if (!$linked && $this->add_object_linked('warrantysvc_svcwarranty', $warrantyId, $user) <= 0) {
                $this->error = 'WarrantyLetterLinkFailed'; return -1;
            }
        }
        $status = $this->last_sent_version > 0 ? self::STATUS_UPDATED : self::STATUS_READY;
        // Native Dolibarr mail uses DOL_DATA_ROOT / last_main_doc for the
        // initial PDF attachment. This must also work in multi-entity mode.
        $dataRoot = rtrim(DOL_DATA_ROOT, '/').'/';
        if (strpos($path, $dataRoot) !== 0) {
            $this->error = 'WarrantyLetterOutputOutsideDocumentRoot';
            return -1;
        }
        $mainDoc = substr($path, strlen($dataRoot));
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'svc_warranty_letter SET current_version = '.$number;
        $sql .= ", status = '".$status."', last_main_doc = '".$this->db->escape($mainDoc)."'";
        $sql .= ' WHERE rowid = '.((int) $this->id).' AND entity = '.((int) $conf->entity);
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return -1; }
        $this->current_version = $number;
        $this->last_main_doc = $mainDoc;
        $this->status = $status;
        return 1;
    }

    /** Triggered only by Dolibarr after a successful physical email send. */
    public function recordEmail($user)
    {
        global $conf;
        // The attachment is authoritative: another operator may regenerate the
        // letter while SMTP delivery is running. Log the actual PDF sent, not
        // the revision that happens to be current after SMTP completes.
        $paths = isset($this->attachedfiles['paths']) ? (array) $this->attachedfiles['paths'] : array();
        $matched = null;
        $versions = $this->getVersions();
        if ($versions === null) return -1;
        foreach ($versions as $v) {
            $expected = realpath($this->versionFullPath($v));
            foreach ($paths as $path) {
                if ($expected !== false && realpath((string) $path) === $expected) {
                    $matched = $v;
                    break 2;
                }
            }
        }
        if (!$matched) { $this->error = 'WarrantyLetterPdfAttachmentMissing'; return -1; }
        if (!$this->verifyVersion($matched)) { $this->error = 'WarrantyLetterPdfHashMismatch'; return -1; }

        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'svc_warranty_letter_mail';
        $sql .= ' (entity, fk_letter, fk_version, date_sent, fk_user, recipient, subject, message_id) VALUES (';
        $sql .= ((int) $conf->entity).', '.((int) $this->id).', '.((int) $matched->rowid).", '".$this->db->idate(dol_now())."', ".((int) $user->id);
        $sql .= ", '".$this->db->escape((string) $this->email_to)."', '".$this->db->escape((string) $this->email_subject)."', '".$this->db->escape((string) $this->email_msgid)."')";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return -1; }
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'svc_warranty_letter SET last_sent_version = '.((int) $matched->version);
        $sql .= ", status = CASE WHEN current_version = ".((int) $matched->version)." THEN 'sent' ELSE 'updated' END";
        $sql .= ' WHERE rowid = '.((int) $this->id).' AND entity = '.((int) $conf->entity);
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return -1; }
        $this->last_sent_version = (int) $matched->version;
        $this->status = ((int) $matched->version === (int) $this->current_version) ? self::STATUS_SENT : self::STATUS_UPDATED;
        return 1;
    }

    public function getMailHistory()
    {
        global $conf;
        $out = array();
        $sql = 'SELECT m.*, v.version FROM '.MAIN_DB_PREFIX.'svc_warranty_letter_mail m';
        $sql .= ' JOIN '.MAIN_DB_PREFIX.'svc_warranty_letter_version v ON v.rowid = m.fk_version';
        $sql .= ' WHERE m.fk_letter = '.((int) $this->id).' AND m.entity = '.((int) $conf->entity).' ORDER BY m.date_sent DESC, m.rowid DESC';
        $r = $this->db->query($sql);
        if (!$r) return $out;
        while ($row = $this->db->fetch_object($r)) $out[] = $row;
        $this->db->free($r);
        return $out;
    }

    public function makeSubstitution($text)
    {
        $version = $this->getVersion();
        $data = $version ? json_decode($version->snapshot, true) : array();
        return str_replace(
            array('__WARRANTY_LETTER_REF__','__WARRANTY_LETTER_VERSION__','__SHIPMENT_REF__','__ORDER_REF__'),
            array($this->ref, (string) $this->current_version, (string) ($data['shipment_ref'] ?? ''), (string) ($data['order_ref'] ?? '')),
            (string) $text
        );
    }

    /** Regeneration is deliberately explicit; native mail form cannot rewrite an issued PDF. */
    public function generateDocument($model, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
    {
        $this->error = 'WarrantyLetterUseExplicitNewRevision';
        return -1;
    }

    public function getNomUrl($withpicto = 0)
    {
        $label = dol_escape_htmltag($this->ref);
        if ($withpicto) $label = img_picto('', 'pdf', 'class="pictofixedwidth"').$label;
        return '<a href="'.DOL_URL_ROOT.'/custom/warrantysvc/warranty_letter_card.php?id='.((int) $this->id).'">'.$label.'</a>';
    }

    public function getLibStatut($mode = 0)
    {
        global $langs;
        $langs->load('warrantysvc@warrantysvc');
        $map = array(self::STATUS_DRAFT=>'WarrantyLetterDraft', self::STATUS_READY=>'WarrantyLetterReady',
            self::STATUS_SENT=>'WarrantyLetterSent', self::STATUS_UPDATED=>'WarrantyLetterUpdated', self::STATUS_STALE=>'WarrantyLetterStale');
        $label = $langs->trans($map[$this->status] ?? $this->status);
        return $mode ? $label : '<span class="badge">'.$label.'</span>';
    }
}
