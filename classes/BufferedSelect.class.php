<?php
namespace Zygor\Telemetry;

/**
 * Buffered keyset-paging SELECT generator for efficient large dataset iteration.
 * Fetches rows in batches using keyset (seek) pagination, yielding one row at a time.
 * More efficient than OFFSET pagination for large tables.
 */
class BufferedSelect {
	private $db;
	private $query_template;
	private $key_field;
	private $offset;
	private $buffer_size = 100;
	public $count = 0;

	/**
	 * @param $db TelemetryDB instance
	 * @param $query_template Base query ending with WHERE clause (e.g., "SELECT * FROM events WHERE flavnum={d}")
	 * @param $key_field Column name for keyset pagination (typically 'id')
	 * @param $offset Starting value for key_field (usually 0 for id, or 0 for first run)
	 * @param $buffer_size Rows to fetch per batch (default 100)
	 */
	public function __construct($db, $query_template, $key_field, $offset, $buffer_size = 100) {
		$this->db = $db;
		$this->query_template = $query_template;
		$this->key_field = $key_field;
		$this->offset = $offset;
		$this->buffer_size = $buffer_size;
	}

	/**
	 * Query keyset paginated results, buffer limited results, serve as generator
	 */
	public function rows() {
		while (true) {
			// do keyset paging: fetch rows where key_field > last_offset, ordered by key_field
			$r = $this->db->query($this->query_template . " AND {$this->key_field} > {d} ORDER BY {$this->key_field} ASC LIMIT {d}", $this->offset, $this->buffer_size);
			if (!$r) throw new \Exception("DB error in buffered SELECT: " . $this->db->error());
			$rows = $r->fetch_all(MYSQLI_ASSOC);
			if (count($rows) == 0) break;
			foreach ($rows as $row) {
				$this->count++;
				yield $row;
			}
			$this->offset = end($rows)[$this->key_field];
			if (count($rows) < $this->buffer_size) break; // no more rows
		}
	}
}
