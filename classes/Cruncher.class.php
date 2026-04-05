<?php

/**
 * Represents a cruncher definition from a topic's crunchers array
 */
class Cruncher {
	public $name;
	public $eventtype;
	public $function;
	public $crunch_function;
	public $table;
	public $table_schema;
	public $action;
	public $input;
	public $output_mode;
	public $customFields = [];

	/**
	 * Constructor
	 * @param array $data Cruncher configuration data
	 */
	public function __construct(array $data = []) {
		foreach ($data as $key => $value) {
			if (property_exists($this, $key) && $key !== 'customFields') {
				$this->$key = $value;
			} else {
				$this->customFields[$key] = $value;
			}
		}
	}

	/**
	 * Get a field value by name (for core fields and custom fields)
	 * @param string $fieldName Field name
	 * @return mixed Field value or null if not found
	 */
	public function get($fieldName) {
		if (property_exists($this, $fieldName)) {
			return $this->$fieldName;
		}
		return isset($this->customFields[$fieldName]) ? $this->customFields[$fieldName] : null;
	}

	/**
	 * Get all data as array
	 */
	public function toArray() {
		return [
			'name' => $this->name,
			'eventtype' => $this->eventtype,
			'function' => $this->function,
			'crunch_function' => $this->crunch_function,
			'table' => $this->table,
			'table_schema' => $this->table_schema,
			'action' => $this->action,
			'input' => $this->input,
			'output_mode' => $this->output_mode,
		] + $this->customFields;
	}
}
