<?php
namespace Zygor\Telemetry;

/**
 * Represents a cruncher definition from a topic's crunchers array
 */
class Cruncher {
	public $name;
	public $input = "event";
	public $eventtype; // = $name
	public $eventsubtype;
	public $function;
	public $table; // = $name
	public $table_schema;
	public $action;
	public $output_mode;
	public $customFields = [];

	/**
	 * Constructor
	 * @param array $data Cruncher configuration data
	 * @param Topic|null $topicObj Parent topic object (for defaults)
	 */
	public function __construct(array $data = [], $topicObj = null) {
		foreach ($data as $key => $value) {
			if (property_exists($this, $key) && $key !== 'customFields') {
				$this->$key = $value;
			} else {
				$this->customFields[$key] = $value;
			}
		}
		if (!$this->eventtype) $this->eventtype = $topicObj ? $topicObj->name : $this->name;
		if (!$this->eventsubtype) $this->eventsubtype = null;
		if (!$this->table) $this->table = $this->name;
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
		$vars = get_object_vars($this);
		unset($vars['customFields']);
		return $vars + $this->customFields;
	}
}
