<?php

/**
 * Database utility class for Telemetry operations.
 * Wraps mysqli connection and provides query helpers.
 */
class TelemetryDB {
	/** @var mysqli */
	public $conn = null;

	public $LAST_QUERY = null;

	/**
	 * Connect to the database.
	 * @param array $cfg Database configuration with 'host', 'user', 'pass', 'db' keys
	 */
	function connect($cfg) {
		$this->conn = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);
		if ($this->conn->connect_errno) {
			throw new ErrorException("Failed to connect to MySQL: (" . $this->conn->connect_errno . ") " . $this->conn->connect_error);
		}
		$this->conn->set_charset("utf8mb4");
	}

	function disconnect() {
		if ($this->conn) $this->conn->close();
		$this->conn = null;
	}

	/**
	 * Escape and format a query string using Zygor::qesc
	 */
	function qesc($query, ...$args) {
		return Zygor::qesc($this->conn, $query, ...$args);
	}

	/**
	 * Escape and format a query string for array inserts using Zygor::qarrayesc
	 */
	function qarrayesc($query, ...$args) {
		return Zygor::qarrayesc($this->conn, $query, ...$args);
	}

	/**
	 * Execute a query with escaped parameters
	 * @return mysqli_result|bool
	 */
	function query($query, ...$args) {
		if (count($args) > 0) {
			$query = $this->qesc($query, ...$args);
		}
		$this->LAST_QUERY = $query;
		return $this->conn->query($query);
	}

	/**
	 * Execute a query and return the first column of the first row
	 * @return mixed
	 */
	function query_one($query, ...$args) {
		$r = $this->query($query, ...$args);
		if (!$r) throw new Exception("DB error: " . $this->conn->error);
		return $r->fetch_row()[0];
	}

	/**
	 * Get last insert ID
	 * @return int
	 */
	function insert_id() {
		return $this->conn->insert_id;
	}

	/**
	 * Get number of affected rows from last query
	 * @return int
	 */
	function affected_rows() {
		return $this->conn->affected_rows;
	}

	/**
	 * Get last error message
	 * @return string
	 */
	function error() {
		return $this->conn->error;
	}

	/**
	 * Get last error number
	 * @return int
	 */
	function errno() {
		return $this->conn->errno;
	}

	/**
	 * Escape a string for use in a query
	 * @return string
	 */
	function escape($str) {
		return $this->conn->real_escape_string($str);
	}

	/**
	 * Begin a transaction
	 */
	function begin_transaction() {
		return $this->conn->begin_transaction();
	}

	/**
	 * Commit current transaction
	 */
	function commit() {
		return $this->conn->commit();
	}

	/**
	 * Rollback current transaction
	 */
	function rollback() {
		return $this->conn->rollback();
	}

	// =======================================================================
	// Status table methods
	// =======================================================================

	function get_status($tag) {
		$r = $this->query("SELECT status FROM status WHERE tag={s} LIMIT 1", $tag);
		if ($r && $r->num_rows) return json_decode($r->fetch_row()[0], true);
		return null;
	}

	function set_status($tag, $status) {
		$status_json = json_encode($status);
		$this->query("INSERT INTO status (tag, status, updated_at) VALUES ({s}, {s}, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status={s}, updated_at=CURRENT_TIMESTAMP", $tag, $status_json, $status_json);
	}

	function delete_status($tag) {
		$this->query("DELETE FROM status WHERE tag={s}", $tag);
	}

	// =======================================================================
	// Files table methods
	// =======================================================================

	function get_file($filename) {
		$r = $this->query("SELECT * FROM files WHERE slugname={s} OR id={d} LIMIT 1", $filename, is_numeric($filename) ? intval($filename) : -1);
		if (!$r && $this->errno() == 3572) throw new FileLockedException(); // lock wait timeout
		if ($this->error()) throw new ErrorException("DB error getting file '$filename': " . $this->error());
		if ($r && $r->num_rows) {
			$file = $r->fetch_assoc();
			return new File($file['id'], $file['slugname']);
		}
		$this->query("INSERT INTO files (slugname) VALUES ({s}) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", $filename);
		return new File($this->insert_id(), $filename);
	}

	/**
	 * Returns file records for the given filenames.
	 * If $do_insert_missing is true, missing filenames will be inserted and included in the results. 
	 * @param string[] $slugnames array of slugnames to look up
	 * @param string $filetype file type identifier
	 * @param bool $do_insert_missing whether to insert missing slugnames
	 * @return File[] array of File objects in the same order as $slugnames
	 */
	function get_files($slugnames, $filetype, $do_insert_missing = true) {
		$r = $this->query("SELECT * FROM files WHERE slugname in ({sa}) AND filetype={s}", $slugnames, $filetype);
		if ($this->error()) throw new ErrorException("DB error getting files '" . join(", ", array_slice($slugnames, 0, 5)) . "...': " . $this->error());
		if ($r && $r->num_rows) $file_rows = $r->fetch_all(MYSQLI_ASSOC);
		else $file_rows = [];
		$files_found = array_column($file_rows, 'slugname');
		$files_not_found = array_diff($slugnames, $files_found);
		if ($do_insert_missing && count($files_not_found)) {
			foreach ($files_not_found as $nf) {
				$this->query("INSERT INTO files (slugname, filetype) VALUES ({s}, {s}) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", $nf, $filetype);
				if ($this->error()) throw new ErrorException("DB error inserting file '$nf': " . $this->error());
				$file_rows[] = ['id' => $this->insert_id(), 'slugname' => $nf]; // mock entry
			}
		}
		// sort result array in the same order as $slugnames
		$file_rows_by_slugname = array_column($file_rows, null, 'slugname');
		$sorted_file_rows = [];
		foreach ($slugnames as $fn) {
			$row = $file_rows_by_slugname[$fn];
			$sorted_file_rows[] = $row ? new File($row['id'], $row['slugname']) : null;
		}
		return $sorted_file_rows;
	}

	function delete_file($slugname_or_id) {
		$this->query("DELETE FROM files WHERE slugname={s} OR id={d}", $slugname_or_id, is_numeric($slugname_or_id) ? intval($slugname_or_id) : -1);
	}

	// =======================================================================
	// Locking methods
	// =======================================================================

	function lock($lock, $timeout = 10) {
		$lock = "'scrape/" . $this->escape($lock) . "'";
		return $this->query_one("SELECT GET_LOCK($lock, {d});", $timeout);
	}

	function unlock($lock) {
		$lock = "'scrape/" . $this->escape($lock) . "'";
		return $this->query_one("SELECT RELEASE_LOCK($lock);");
	}

	// =======================================================================
	// Data storage methods
	// =======================================================================

	/**
	 * Store datapoints into the events table
	 * @param string $flavour Game flavour identifier
	 * @param int $flavnum Flavour number
	 * @param int|null $file_id File ID reference
	 * @param array $datapoints Array of datapoint arrays with 'time', 'type', and other fields
	 * @return int Number of inserted rows
	 */
	function store_datapoints($flavnum, $file_id, $datapoints) {
		if (!count($datapoints)) return 0;
		$inserted = 0;
		$chunk_size = 100;
		// process data in batches of $chunk_size
		for ($i = 0; $i < count($datapoints); $i += $chunk_size) {
			$chunk = array_slice($datapoints, $i, $chunk_size);
			$values = [];
			foreach ($chunk as $dp) {
				$time = intval($dp['time'] ?: 0);	unset($dp['time']);
				$type = $dp['type'] ?: '?';			unset($dp['type']);
				$subtype = $dp['subtype'] ?: null;	unset($dp['subtype']);
				
				$values[] = $this->qesc("({d},{d},{d},{s},{sn},{s})", $flavnum, $file_id, $time, $type, $subtype, json_encode($dp));
			}
			$q = "INSERT INTO events (flavnum,file_id,time,type,subtype,data) VALUES " . join(",", $values);
			$r = $this->conn->query($q);
			if (!$r) throw new Exception("DB error: " . $this->error());
			$inserted += $this->affected_rows();
		}
		return $inserted;
	}
}
