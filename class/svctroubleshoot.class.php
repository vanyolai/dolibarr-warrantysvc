<?php
/* Copyright (C) 2026 DPG Supply */

/**
 * \file    class/svctroubleshoot.class.php
 * \ingroup warrantysvc
 * \brief   A single guided troubleshooting session recorded against a
 *          service request. One row per saved diagnostic session.
 */

/**
 * Class to manage troubleshoot session records for a service request.
 * Each session captures the diagnostic checklist state, a free-text
 * summary and an outcome. Sessions are immutable history entries.
 */
class SvcTroubleshoot
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error */
	public $error = '';

	/** @var array Errors */
	public $errors = array();

	/** @var int ID */
	public $id;

	/** @var int Entity */
	public $entity;

	/** @var int Parent service request rowid */
	public $fk_svcrequest;

	/** @var int Session date (timestamp) */
	public $datec;

	/** @var int Author user id */
	public $fk_user_author;

	/** @var array Checklist: list of array('label'=>, 'done'=>bool, 'finding'=>) */
	public $checklist = array();

	/** @var string Free-text summary */
	public $summary;

	/** @var string Outcome key (resolved|no_fault|escalate|parts_needed|intervention) */
	public $outcome;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Create a new troubleshoot session record
	 *
	 * @param  User $user User performing the action
	 * @return int        >0 = new id, <0 if KO
	 */
	public function create($user)
	{
		global $conf;

		if (empty($this->datec)) {
			$this->datec = dol_now();
		}

		$checklist_json = json_encode(is_array($this->checklist) ? $this->checklist : array());

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."svc_troubleshoot";
		$sql .= " (entity, fk_svcrequest, datec, fk_user_author, checklist, summary, outcome)";
		$sql .= " VALUES (";
		$sql .= ((int) $conf->entity);
		$sql .= ", ".((int) $this->fk_svcrequest);
		$sql .= ", '".$this->db->idate($this->datec)."'";
		$sql .= ", ".($this->fk_user_author ? ((int) $this->fk_user_author) : "NULL");
		$sql .= ", '".$this->db->escape($checklist_json)."'";
		$sql .= ", ".($this->summary ? "'".$this->db->escape($this->summary)."'" : "NULL");
		$sql .= ", ".($this->outcome ? "'".$this->db->escape($this->outcome)."'" : "NULL");
		$sql .= ")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'svc_troubleshoot');
			return $this->id;
		}

		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Return all troubleshoot sessions for a service request, newest first.
	 *
	 * @param  DoliDB $db            Database handler
	 * @param  int    $fk_svcrequest Service request rowid
	 * @return SvcTroubleshoot[]     Array of populated session objects (empty on none/error)
	 */
	public static function fetchAllForRequest($db, $fk_svcrequest)
	{
		$list = array();

		$sql = "SELECT rowid, entity, fk_svcrequest, datec, fk_user_author, checklist, summary, outcome";
		$sql .= " FROM ".MAIN_DB_PREFIX."svc_troubleshoot";
		$sql .= " WHERE fk_svcrequest = ".((int) $fk_svcrequest);
		$sql .= " ORDER BY datec DESC, rowid DESC";

		$resql = $db->query($sql);
		if (!$resql) {
			return $list;
		}

		while ($obj = $db->fetch_object($resql)) {
			$s = new self($db);
			$s->id             = $obj->rowid;
			$s->entity         = $obj->entity;
			$s->fk_svcrequest  = $obj->fk_svcrequest;
			$s->datec          = $db->jdate($obj->datec);
			$s->fk_user_author = $obj->fk_user_author;
			$decoded           = $obj->checklist ? json_decode($obj->checklist, true) : array();
			$s->checklist      = is_array($decoded) ? $decoded : array();
			$s->summary        = $obj->summary;
			$s->outcome        = $obj->outcome;
			$list[] = $s;
		}

		return $list;
	}

	/**
	 * Count troubleshoot sessions for a service request.
	 *
	 * @param  DoliDB $db            Database handler
	 * @param  int    $fk_svcrequest Service request rowid
	 * @return int                   Number of sessions
	 */
	public static function countForRequest($db, $fk_svcrequest)
	{
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."svc_troubleshoot";
		$sql .= " WHERE fk_svcrequest = ".((int) $fk_svcrequest);
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			return $obj ? (int) $obj->nb : 0;
		}
		return 0;
	}
}
