<?php
/* Copyright (C) 2026 DPG Supply */
/**
 * Customer-scoped, multi-shipment warranty letter and immutable PDF revision registry.
 * Warranty duration, LOT/SN allocation and shipment warranty creation remain untouched.
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
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
    public $fk_soc = 0;
    public $socid = 0;
    public $fk_project = 0;
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
        foreach (array('rowid', 'entity', 'fk_soc', 'current_version', 'last_sent_version') as $field) {
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

    public function fetch_thirdparty($force_thirdparty_id = 0)
    {
        return parent::fetch_thirdparty($force_thirdparty_id);
    }

    public function fetchProject() { return 0; }

    /**
     * Add a native Dolibarr Agenda event linked to this warranty letter.
     * Audit failure must never invalidate the underlying business operation.
     *
     * @param string $label    Event label
     * @param User   $user     Actor
     * @param string $note     Private event note
     * @param string $typeCode Action type code
     * @param string $code     Action code
     * @return int 1 on success, -1 on failure
     */
    public function logAgendaEvent($label, $user, $note = '', $typeCode = 'AC_OTH_AUTO', $code = 'AC_WARRANTYSVC_WARRANTYLETTER')
    {
        if ((int) $this->id <= 0) return -1;

        require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

        // Email logging may be reached after a successful SMTP send. Avoid a
        // duplicate native event if a retry happens with the same Message-ID.
        if ($typeCode === 'AC_EMAIL' && !empty($this->email_msgid)) {
            $sql = 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm';
            $sql .= ' WHERE fk_element = '.((int) $this->id);
            $sql .= " AND elementtype = 'svcwarrantyletter@warrantysvc'";
            $sql .= " AND email_msgid = '".$this->db->escape((string) $this->email_msgid)."'";
            $sql .= $this->db->plimit(1);
            $res = $this->db->query($sql);
            if ($res) {
                $exists = (bool) $this->db->fetch_object($res);
                $this->db->free($res);
                if ($exists) return 1;
            }
        }

        $now = dol_now();
        $event = new ActionComm($this->db);
        $event->type_code = $typeCode;
        $event->code = $code;
        $event->label = (string) $label;
        $event->note_private = (string) $note;
        $event->datep = $now;
        $event->datef = $now;
        $event->durationp = 0;
        $event->percentage = -1;
        $event->socid = (int) $this->fk_soc;
        $event->authorid = (int) $user->id;
        $event->userownerid = (int) $user->id;
        $event->fk_element = (int) $this->id;
        $event->elementid = (int) $this->id;
        $event->elementtype = 'svcwarrantyletter@warrantysvc';

        if ($typeCode === 'AC_EMAIL') {
            $event->email_msgid = !empty($this->email_msgid) ? (string) $this->email_msgid : null;
            $event->email_from = !empty($this->email_from) ? (string) $this->email_from : null;
            $event->email_subject = !empty($this->email_subject) ? (string) $this->email_subject : null;
            $event->email_to = !empty($this->email_to) ? (string) $this->email_to : null;
            $event->email_tocc = !empty($this->email_tocc) ? (string) $this->email_tocc : null;
            $event->email_tobcc = !empty($this->email_tobcc) ? (string) $this->email_tobcc : null;
        }

        $result = $event->create($user, 1);
        if ($result <= 0) {
            dol_syslog(__METHOD__.': failed to create ActionComm event: '.$event->error, LOG_WARNING);
            return -1;
        }

        return 1;
    }

    public static function findByShipment($db, $shipmentId)
    {
        global $conf;
        $sql = 'SELECT fk_letter FROM '.MAIN_DB_PREFIX.'svc_warranty_letter_shipment';
        $sql .= ' WHERE fk_expedition = '.((int) $shipmentId).' AND entity = '.((int) $conf->entity);
        $sql .= $db->plimit(1);
        $res = $db->query($sql);
        if (!$res) return -1;
        $row = $db->fetch_object($res);
        $db->free($res);
        return $row ? (int) $row->fk_letter : 0;
    }

    public function getShipments()
    {
        global $conf;
        $out = array();
        $sql = 'SELECT ls.fk_expedition, e.ref, e.fk_soc, e.date_expedition';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'svc_warranty_letter_shipment ls';
        $sql .= ' JOIN '.MAIN_DB_PREFIX.'expedition e ON e.rowid = ls.fk_expedition';
        $sql .= ' WHERE ls.fk_letter = '.((int) $this->id).' AND ls.entity = '.((int) $conf->entity);
        $sql .= ' ORDER BY e.date_expedition, e.ref, e.rowid';
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return null; }
        while ($row = $this->db->fetch_object($res)) $out[] = $row;
        $this->db->free($res);
        return $out;
    }

    public static function getAvailableShipmentsForCustomer($db, $socid, $letterId = 0)
    {
        global $conf;
        $out = array();
        $sql = 'SELECT w.fk_expedition, e.ref, e.date_expedition, COUNT(w.rowid) AS warranty_count, SUM(w.covered_qty) AS covered_qty';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'svc_warranty w';
        $sql .= ' JOIN '.MAIN_DB_PREFIX.'expedition e ON e.rowid = w.fk_expedition';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'svc_warranty_letter_shipment ls';
        $sql .= ' ON ls.entity = w.entity AND ls.fk_expedition = w.fk_expedition';
        $sql .= ' WHERE w.entity = '.((int) $conf->entity).' AND w.fk_soc = '.((int) $socid);
        $sql .= " AND w.status <> 'voided' AND w.fk_expedition IS NOT NULL AND w.fk_expedition > 0";
        if ($letterId > 0) {
            $sql .= ' AND (ls.fk_letter IS NULL OR ls.fk_letter = '.((int) $letterId).')';
        } else {
            $sql .= ' AND ls.fk_letter IS NULL';
        }
        $sql .= ' GROUP BY w.fk_expedition, e.ref, e.date_expedition';
        $sql .= ' ORDER BY e.date_expedition DESC, e.ref DESC';
        $res = $db->query($sql);
        if (!$res) return null;
        while ($row = $db->fetch_object($res)) $out[] = $row;
        $db->free($res);
        return $out;
    }

    private function ensureNativeLink($type, $sourceId, $user)
    {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'element_element';
        $sql .= ' WHERE fk_source = '.((int) $sourceId)." AND sourcetype = '".$this->db->escape($type)."'";
        $sql .= ' AND fk_target = '.((int) $this->id)." AND targettype = 'warrantysvc_svcwarrantyletter'";
        $sql .= $this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }
        $exists = (bool) $this->db->fetch_object($res);
        $this->db->free($res);
        if ($exists) return 1;
        if ($this->add_object_linked($type, (int) $sourceId, $user) <= 0) {
            $this->error = 'WarrantyLetterLinkFailed';
            return -1;
        }
        return 1;
    }

    public function addShipments($shipmentIds, $user)
    {
        global $conf, $langs;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $shipmentIds))));
        if (!$ids) { $this->error = 'WarrantyLetterNoShipments'; return -1; }
        $addedShipmentRefs = array();

        foreach ($ids as $shipmentId) {
            $sql = 'SELECT rowid, ref, fk_soc FROM '.MAIN_DB_PREFIX.'expedition WHERE rowid = '.$shipmentId;
            $res = $this->db->query($sql);
            if (!$res) { $this->error = $this->db->lasterror(); return -1; }
            $shipment = $this->db->fetch_object($res);
            $this->db->free($res);
            if (!$shipment) { $this->error = 'ShipmentNotFound'; return -1; }
            if ((int) $shipment->fk_soc !== (int) $this->fk_soc) {
                $this->error = 'WarrantyLetterCustomerMismatch';
                return -1;
            }

            $q = 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty';
            $q .= ' WHERE entity = '.((int) $conf->entity).' AND fk_expedition = '.$shipmentId." AND status <> 'voided'";
            $r = $this->db->query($q);
            if (!$r) { $this->error = $this->db->lasterror(); return -1; }
            $count = $this->db->fetch_object($r);
            $this->db->free($r);
            if (!$count || (int) $count->nb <= 0) { $this->error = 'WarrantyLetterNoWarranties'; return -1; }

            $existing = self::findByShipment($this->db, $shipmentId);
            if ($existing < 0) { $this->error = $this->db->lasterror(); return -1; }
            if ($existing > 0 && $existing !== (int) $this->id) {
                $this->error = 'WarrantyLetterShipmentAlreadyAssigned';
                return -1;
            }
            if ($existing === (int) $this->id) continue;

            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'svc_warranty_letter_shipment';
            $sql .= ' (entity, fk_letter, fk_expedition, date_creation, fk_user_creat) VALUES (';
            $sql .= ((int) $conf->entity).', '.((int) $this->id).', '.$shipmentId.", '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
            if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return -1; }
            $addedShipmentRefs[] = (string) $shipment->ref;

            if ($this->ensureNativeLink('shipping', $shipmentId, $user) < 0) return -1;

            $orders = 'SELECT DISTINCT fk_commande FROM '.MAIN_DB_PREFIX.'svc_warranty';
            $orders .= ' WHERE entity = '.((int) $conf->entity).' AND fk_expedition = '.$shipmentId.' AND fk_commande IS NOT NULL AND fk_commande > 0';
            $or = $this->db->query($orders);
            if (!$or) { $this->error = $this->db->lasterror(); return -1; }
            while ($order = $this->db->fetch_object($or)) {
                if ($this->ensureNativeLink('commande', (int) $order->fk_commande, $user) < 0) {
                    $this->db->free($or);
                    return -1;
                }
            }
            $this->db->free($or);
        }

        if ((int) $this->current_version > 0) {
            $this->status = self::STATUS_STALE;
            $sql = 'UPDATE '.MAIN_DB_PREFIX."svc_warranty_letter SET status = 'stale' WHERE rowid = ".((int) $this->id);
            if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return -1; }
        }

        if ($addedShipmentRefs) {
            $langs->load('warrantysvc@warrantysvc');
            $label = $langs->transnoentities('WarrantyLetterAgendaShipmentsAdded', $this->ref);
            $note = $langs->transnoentities('WarrantyLetterAgendaShipmentsAddedNote', implode(', ', $addedShipmentRefs));
            $this->logAgendaEvent($label, $user, $note);
        }

        return 1;
    }

    public function createForShipments($shipmentIds, $user)
    {
        global $conf;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $shipmentIds))));
        if (!$ids) { $this->error = 'WarrantyLetterNoShipments'; return -1; }

        $socid = 0;
        foreach ($ids as $shipmentId) {
            $sql = 'SELECT fk_soc FROM '.MAIN_DB_PREFIX.'expedition WHERE rowid = '.$shipmentId;
            $res = $this->db->query($sql);
            if (!$res) { $this->error = $this->db->lasterror(); return -1; }
            $row = $this->db->fetch_object($res);
            $this->db->free($res);
            if (!$row) { $this->error = 'ShipmentNotFound'; return -1; }
            if ($socid === 0) $socid = (int) $row->fk_soc;
            if ($socid <= 0 || (int) $row->fk_soc !== $socid) {
                $this->error = 'WarrantyLetterCustomerMismatch';
                return -1;
            }
        }

        $this->entity = (int) $conf->entity;
        $this->fk_soc = $socid;
        $this->socid = $socid;
        $this->date_creation = dol_now();
        $this->model_pdf = getDolGlobalString('WARRANTYSVC_WARRANTYLETTER_PDF_MODEL', 'warrantyletter_standard');

        $tempref = 'PROV-WL-'.str_replace('.', '', uniqid('', true));
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'svc_warranty_letter';
        $sql .= ' (ref, entity, fk_soc, status, current_version, last_sent_version, model_pdf, date_creation, fk_user_creat)';
        $sql .= " VALUES ('".$this->db->escape($tempref)."', ".$this->entity.', '.$socid.", 'draft', 0, 0, '".$this->db->escape($this->model_pdf)."', '".$this->db->idate($this->date_creation)."', ".((int) $user->id).')';
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return -1; }

        $this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'svc_warranty_letter');

        $addon = getDolGlobalString('WARRANTYSVC_WARRANTYLETTER_ADDON', 'mod_warrantyletter_standard');
        if (!preg_match('/^mod_warrantyletter_[a-zA-Z0-9_]+$/', $addon)) {
            $this->error = 'WarrantyLetterInvalidNumberingModule';
            return -1;
        }
        $addonFile = DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/warrantysvc/'.$addon.'.php';
        if (!is_readable($addonFile)) {
            $this->error = 'WarrantyLetterNumberingModuleNotFound';
            return -1;
        }
        require_once $addonFile;
        if (!class_exists($addon)) {
            $this->error = 'WarrantyLetterNumberingModuleNotFound';
            return -1;
        }
        $numref = new $addon();
        $nextref = $numref->getNextValue('', $this);
        if (!is_string($nextref) || $nextref === '') {
            $this->error = !empty($numref->error) ? $numref->error : 'WarrantyLetterNumberingFailed';
            return -1;
        }
        $this->ref = $nextref;

        if (!$this->db->query('UPDATE '.MAIN_DB_PREFIX."svc_warranty_letter SET ref = '".$this->db->escape($this->ref)."' WHERE rowid = ".$this->id)) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return $this->addShipments($ids, $user);
    }

    public function createFromShipment($shipment, $user)
    {
        return $this->createForShipments(array((int) $shipment->id), $user);
    }

    /** Current warranty records of all attached shipments are copied into an immutable PDF snapshot. */
    public function buildSnapshot()
    {
        global $conf, $mysoc;
        if ($this->fetch_thirdparty() <= 0) { $this->error = 'CustomerNotFound'; return null; }
        $shipments = $this->getShipments();
        if ($shipments === null || !$shipments) { $this->error = 'WarrantyLetterNoShipments'; return null; }

        $snapshotShipments = array();
        $warrantyIds = array();
        $allOrderRefs = array();
        $allShipmentRefs = array();

        foreach ($shipments as $shipment) {
            $groups = array();
            $indices = array();
            $orderRefs = array();

            $orders = 'SELECT DISTINCT c.ref FROM '.MAIN_DB_PREFIX.'svc_warranty w';
            $orders .= ' JOIN '.MAIN_DB_PREFIX.'commande c ON c.rowid = w.fk_commande';
            $orders .= ' WHERE w.entity = '.((int) $conf->entity).' AND w.fk_expedition = '.((int) $shipment->fk_expedition);
            $orders .= ' AND w.fk_commande IS NOT NULL AND w.fk_commande > 0 ORDER BY c.ref';
            $or = $this->db->query($orders);
            if (!$or) { $this->error = $this->db->lasterror(); return null; }
            while ($order = $this->db->fetch_object($or)) {
                $orderRefs[] = (string) $order->ref;
                $allOrderRefs[(string) $order->ref] = true;
            }
            $this->db->free($or);

            $sql = 'SELECT w.rowid, w.fk_product, w.serial_number, w.covered_qty, w.start_date, w.expiry_date, p.ref AS product_ref, p.label AS product_label';
            $sql .= ' FROM '.MAIN_DB_PREFIX.'svc_warranty w LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = w.fk_product';
            $sql .= ' WHERE w.fk_expedition = '.((int) $shipment->fk_expedition).' AND w.entity = '.((int) $conf->entity);
            $sql .= " AND w.status <> 'voided' ORDER BY p.ref, w.start_date, w.expiry_date, w.serial_number, w.rowid";
            $result = $this->db->query($sql);
            if (!$result) { $this->error = $this->db->lasterror(); return null; }
            while ($row = $this->db->fetch_object($result)) {
                $startDay = substr((string) $row->start_date, 0, 10);
                $expiryDay = substr((string) $row->expiry_date, 0, 10);
                $key = ((int) $row->fk_product).'|'.$startDay.'|'.$expiryDay;
                if (!isset($indices[$key])) {
                    $indices[$key] = count($groups);
                    $groups[] = array(
                        'product_id'=>(int) $row->fk_product,
                        'product_ref'=>(string) $row->product_ref,
                        'product_label'=>(string) $row->product_label,
                        'start_date'=>$startDay,
                        'expiry_date'=>$expiryDay,
                        'qty'=>0,
                        'serials'=>array()
                    );
                }
                $i = $indices[$key];
                $groups[$i]['qty'] += (float) $row->covered_qty;
                if ((string) $row->serial_number !== '') $groups[$i]['serials'][] = (string) $row->serial_number;
                $warrantyIds[] = (int) $row->rowid;
            }
            $this->db->free($result);

            $ref = (string) $shipment->ref;
            $allShipmentRefs[] = $ref;
            $snapshotShipments[] = array(
                'shipment_id'=>(int) $shipment->fk_expedition,
                'shipment_ref'=>$ref,
                'shipment_date'=>!empty($shipment->date_expedition) ? substr((string) $shipment->date_expedition, 0, 10) : '',
                'order_refs'=>$orderRefs,
                'groups'=>$groups
            );
        }

        if (!$warrantyIds) { $this->error = 'WarrantyLetterNoWarranties'; return null; }
        $myCompany = trim((string) $mysoc->name."\n".(string) $mysoc->address."\n".(string) $mysoc->zip.' '.(string) $mysoc->town);
        $buyer = trim((string) $this->thirdparty->name."\n".(string) $this->thirdparty->address."\n".(string) $this->thirdparty->zip.' '.(string) $this->thirdparty->town);

        return array(
            'letter_ref'=>$this->ref,
            'issued_at'=>date('Y-m-d H:i:s', dol_now()),
            'issuer'=>$myCompany,
            'buyer'=>$buyer,
            'shipment_refs'=>$allShipmentRefs,
            'order_refs'=>array_keys($allOrderRefs),
            'shipments'=>$snapshotShipments,
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
        $model = !empty($this->model_pdf) ? $this->model_pdf : getDolGlobalString('WARRANTYSVC_WARRANTYLETTER_PDF_MODEL', 'warrantyletter_standard');
        if (!preg_match('/^warrantyletter_[a-zA-Z0-9_]+$/', $model)) {
            $this->error = 'WarrantyLetterInvalidPdfModel';
            return -1;
        }
        $modelFile = DOL_DOCUMENT_ROOT.'/custom/warrantysvc/core/modules/warrantysvc/pdf_'.$model.'.php';
        $modelClass = 'pdf_'.$model;
        if (!is_readable($modelFile)) {
            $this->error = 'WarrantyLetterPdfModelNotFound';
            return -1;
        }
        require_once $modelFile;
        if (!class_exists($modelClass)) {
            $this->error = 'WarrantyLetterPdfModelNotFound';
            return -1;
        }
        $this->pending_snapshot = $snapshot;
        $this->pending_version = $number;
        $generator = new $modelClass($this->db);
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

        $outputlangs->load('warrantysvc@warrantysvc');
        $label = $outputlangs->transnoentities('WarrantyLetterAgendaRevisionCreated', $this->ref, $number);
        $note = $outputlangs->transnoentities('WarrantyLetterAgendaRevisionCreatedNote', basename($relative));
        $this->logAgendaEvent($label, $user, $note);

        return 1;
    }

    /**
     * Delete an unsent PDF revision. Revisions referenced by mail history are immutable.
     *
     * @param int  $number Revision number
     * @param User $user   Acting user
     * @return int 1=OK, -1=error
     */
    public function deleteVersion($number, $user)
    {
        global $conf;

        $version = $this->getVersion((int) $number);
        if (!$version) {
            $this->error = 'WarrantyLetterVersionNotFound';
            return -1;
        }

        $sql = 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'svc_warranty_letter_mail';
        $sql .= ' WHERE entity = '.((int) $conf->entity).' AND fk_letter = '.((int) $this->id).' AND fk_version = '.((int) $version->rowid);
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }
        $row = $this->db->fetch_object($res);
        $this->db->free($res);
        if ($row && (int) $row->nb > 0) {
            $this->error = 'WarrantyLetterSentVersionCannotBeDeleted';
            return -1;
        }

        $path = $this->versionFullPath($version);
        if (is_file($path) && !@unlink($path)) {
            $this->error = 'ErrorFailToDeleteFile';
            return -1;
        }

        $sql = 'DELETE FROM '.MAIN_DB_PREFIX.'svc_warranty_letter_version';
        $sql .= ' WHERE rowid = '.((int) $version->rowid).' AND fk_letter = '.((int) $this->id).' AND entity = '.((int) $conf->entity);
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $remaining = $this->getVersions();
        if ($remaining === null) return -1;
        $newCurrent = !empty($remaining) ? (int) $remaining[0]->version : 0;
        $newMainDoc = '';
        $newStatus = self::STATUS_DRAFT;

        if ($newCurrent > 0) {
            $currentRow = $remaining[0];
            $fullPath = $this->versionFullPath($currentRow);
            $dataRoot = rtrim(DOL_DATA_ROOT, '/').'/';
            if (strpos($fullPath, $dataRoot) === 0) {
                $newMainDoc = substr($fullPath, strlen($dataRoot));
            }

            if (!$this->isSnapshotCurrent($currentRow)) {
                $newStatus = self::STATUS_STALE;
            } elseif ((int) $this->last_sent_version === $newCurrent) {
                $newStatus = self::STATUS_SENT;
            } elseif ((int) $this->last_sent_version > 0) {
                $newStatus = self::STATUS_UPDATED;
            } else {
                $newStatus = self::STATUS_READY;
            }
        }

        $sql = 'UPDATE '.MAIN_DB_PREFIX.'svc_warranty_letter SET current_version = '.$newCurrent;
        $sql .= ", last_main_doc = '".$this->db->escape($newMainDoc)."'";
        $sql .= ", status = '".$this->db->escape($newStatus)."'";
        $sql .= ' WHERE rowid = '.((int) $this->id).' AND entity = '.((int) $conf->entity);
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        $this->current_version = $newCurrent;
        $this->last_main_doc = $newMainDoc;
        $this->status = $newStatus;

        global $langs;
        $langs->load('warrantysvc@warrantysvc');
        $label = $langs->transnoentities('WarrantyLetterAgendaRevisionDeleted', $this->ref, (int) $number);
        $note = $langs->transnoentities('WarrantyLetterAgendaRevisionDeletedNote', basename((string) $version->file_path));
        $this->logAgendaEvent($label, $user, $note);

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

        global $langs;
        $langs->load('warrantysvc@warrantysvc');
        $label = $langs->transnoentities('WarrantyLetterAgendaSentByEmail', $this->ref, (int) $matched->version);
        $note = $langs->transnoentities('WarrantyLetterAgendaSentByEmailNote', (string) $this->email_to, (string) $this->email_subject);
        $this->logAgendaEvent($label, $user, $note, 'AC_EMAIL', 'AC_SVCWARRANTYLETTER_SENTBYMAIL');

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
        $shipmentRefs = isset($data['shipment_refs']) && is_array($data['shipment_refs']) ? implode(', ', $data['shipment_refs']) : '';
        $orderRefs = isset($data['order_refs']) && is_array($data['order_refs']) ? implode(', ', $data['order_refs']) : '';
        return str_replace(
            array('__WARRANTY_LETTER_REF__','__WARRANTY_LETTER_VERSION__','__SHIPMENT_REF__','__SHIPMENT_REFS__','__ORDER_REF__','__ORDER_REFS__'),
            array($this->ref, (string) $this->current_version, $shipmentRefs, $shipmentRefs, $orderRefs, $orderRefs),
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
