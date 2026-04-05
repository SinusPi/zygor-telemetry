<?php

/**
 * Represents a telemetry topic loaded from topic-*.inc.php files
 */
class Topic implements ArrayAccess {
	public $name;
	public $eventtype;
	public $scraper;
	public $crunchers;
	public $endpoint;
	public $view;
	public $skip;
	public $customFields = []; // For any additional fields not explicitly defined

	/**
	 * Constructor
	 * @param string $name Topic name
	 * @param array $data Topic configuration data
	 */
	public function __construct($name, array $data = []) {
		$this->name = $name;
		$this->eventtype = isset($data['eventtype']) ? $data['eventtype'] : $name;
		$this->scraper = isset($data['scraper']) ? $data['scraper'] : null;
		$this->crunchers = isset($data['crunchers']) ? $data['crunchers'] : [];
		$this->endpoint = isset($data['endpoint']) ? $data['endpoint'] : null;
		$this->view = isset($data['view']) ? $data['view'] : null;
		$this->skip = isset($data['skip']) ? $data['skip'] : false;
		
		// Store any additional fields not explicitly defined
		$definedFields = ['name', 'eventtype', 'scraper', 'crunchers', 'endpoint', 'view', 'skip'];
		foreach ($data as $key => $value) {
			if (!in_array($key, $definedFields)) {
				$this->customFields[$key] = $value;
			}
		}
	}

	/**
	 * Check if topic should be skipped
	 */
	public function isSkipped() {
		return $this->skip === false ? false : $this->skip;
	}

	/**
	 * Get a field value by name (for core fields and custom fields)
	 * @param string $fieldName Field name
	 * @return mixed Field value or null if not found
	 */
	public function get($fieldName) {
		return $this->__get($fieldName);
	}

	/**
	 * Get all data as array
	 */
	public function toArray() {
		return [
			'name' => $this->name,
			'eventtype' => $this->eventtype,
			'scraper' => $this->scraper,
			'crunchers' => $this->crunchers,
			'endpoint' => $this->endpoint,
			'view' => $this->view,
			'skip' => $this->skip,
		] + $this->customFields;
	}

	/**
	 * Access properties like array (magic getter)
	 */
	public function __get($name) {
		switch ($name) {
			case 'name':
				return $this->name;
			case 'eventtype':
				return $this->eventtype;
			case 'scraper':
				return $this->scraper;
			case 'crunchers':
				return $this->crunchers;
			case 'endpoint':
				return $this->endpoint;
			case 'view':
				return $this->view;
			case 'skip':
				return $this->skip;
			default:
				return isset($this->customFields[$name]) ? $this->customFields[$name] : null;
		}
	}

	/**
	 * Set properties like array (magic setter)
	 */
	public function __set($name, $value) {
		switch ($name) {
			case 'name':
				$this->name = $value;
				break;
			case 'eventtype':
				$this->eventtype = $value;
				break;
			case 'scraper':
				$this->scraper = $value;
				break;
			case 'crunchers':
				$this->crunchers = $value;
				break;
			case 'endpoint':
				$this->endpoint = $value;
				break;
			case 'view':
				$this->view = $value;
				break;
			case 'skip':
				$this->skip = $value;
				break;
			default:
				$this->customFields[$name] = $value;
				break;
		}
	}

	/**
	 * Check if property exists (magic isset)
	 */
	public function __isset($name) {
		switch ($name) {
			case 'name':
			case 'eventtype':
			case 'scraper':
			case 'crunchers':
			case 'endpoint':
			case 'view':
			case 'skip':
				return true;
			default:
				return isset($this->customFields[$name]);
		}
	}

	/**
	 * Support array-style access (ArrayAccess::offsetExists)
	 */
	public function offsetExists($offset) {
		return $this->__isset($offset);
	}

	/**
	 * Support array-style access (ArrayAccess::offsetGet)
	 */
	public function offsetGet($offset) {
		return $this->__get($offset);
	}

	/**
	 * Support array-style access (ArrayAccess::offsetSet)
	 */
	public function offsetSet($offset, $value) {
		$this->__set($offset, $value);
	}

	/**
	 * Support array-style access (ArrayAccess::offsetUnset)
	 */
	public function offsetUnset($offset) {
		switch ($offset) {
			case 'name':
			case 'eventtype':
			case 'scraper':
			case 'crunchers':
			case 'endpoint':
			case 'view':
			case 'skip':
				// Don't allow unsetting core fields, but silently ignore
				break;
			default:
				unset($this->customFields[$offset]);
				break;
		}
	}
}
