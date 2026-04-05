<?php

/**
 * Represents a telemetry topic loaded from topic-*.inc.php files
 */
class Topic implements ArrayAccess {
	private $data = [];

	/**
	 * Constructor
	 * @param string $name Topic name
	 * @param array $data Topic configuration data
	 */
	public function __construct($name, array $data = []) {
		$this->data = array_merge([
			'name' => $name,
			'eventtype' => $name, // default event name is the same as topic name, can be overridden
			'scraper' => null,
			'crunchers' => [],
			'endpoint' => null,
			'view' => null,
			'skip' => false,
		], $data);
	}

	/**
	 * Get topic name
	 */
	public function getName() {
		return $this->data['name'];
	}

	/**
	 * Get event type name
	 */
	public function getEventType() {
		return $this->data['eventtype'];
	}

	/**
	 * Get scraper configuration
	 */
	public function getScraper() {
		return $this->data['scraper'];
	}

	/**
	 * Get crunchers
	 */
	public function getCrunchers() {
		return $this->data['crunchers'];
	}

	/**
	 * Get endpoint configuration
	 */
	public function getEndpoint() {
		return $this->data['endpoint'];
	}

	/**
	 * Get view configuration
	 */
	public function getView() {
		return $this->data['view'];
	}

	/**
	 * Check if topic should be skipped
	 */
	public function isSkipped() {
		return $this->data['skip'] === false ? false : $this->data['skip'];
	}

	/**
	 * Get all data as array
	 */
	public function toArray() {
		return $this->data;
	}

	/**
	 * Access properties like array
	 */
	public function __get($name) {
		return isset($this->data[$name]) ? $this->data[$name] : null;
	}

	/**
	 * Set properties like array
	 */
	public function __set($name, $value) {
		$this->data[$name] = $value;
	}

	/**
	 * Check if property exists
	 */
	public function __isset($name) {
		return isset($this->data[$name]);
	}

	/**
	 * Support array-style access
	 */
	public function offsetExists($offset) {
		return isset($this->data[$offset]);
	}

	public function offsetGet($offset) {
		return isset($this->data[$offset]) ? $this->data[$offset] : null;
	}

	public function offsetSet($offset, $value) {
		$this->data[$offset] = $value;
	}

	public function offsetUnset($offset) {
		unset($this->data[$offset]);
	}
}
