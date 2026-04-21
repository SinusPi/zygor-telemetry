<?php
namespace Zygor\Telemetry;

/**
 * Buffered batch INSERT utility for efficient bulk inserts.
 * Accumulates rows and flushes them in batches to reduce query overhead.
 */
class BufferedInsert {
	private $table;
	private $columns;
	private $db;
	private $buffer = [];
	private $buffer_size = 100;

	public function __construct($db, $table, $buffer_size = 100) {
		$this->db = $db;
		$this->table = $table;
		$this->buffer_size = $buffer_size;
	}

	public function __destruct() {
		$this->flush();
	}

	public function abort() {
		$this->buffer = [];
	}

	public function insert($data) {
		if (!$this->columns) $this->columns = array_keys($data);
		$this->buffer[] = $data;
		if (count($this->buffer) >= $this->buffer_size) {
			$this->flush();
		}
	}

	public function flush() {
		if (count($this->buffer) == 0) return;
		$sql_values = [];
		foreach ($this->buffer as $row) {
			$row_values = [];
			foreach ($this->columns as $col) {
				$row_values[] = isset($row[$col]) ? $this->db->qesc("{s}", $row[$col]) : "NULL";
			}
			$sql_values[] = "(" . join(",", $row_values) . ")";
		}
		$q = "INSERT INTO {$this->table} (" . join(",", $this->columns) . ") VALUES " . join(",", $sql_values);
		if (!$this->db->query($q)) throw new \Exception("DB error in buffered INSERT: " . $this->db->error());
		$this->buffer = [];
	}
}
