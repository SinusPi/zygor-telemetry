<?php
namespace Zygor\Telemetry;

/**
 * Config class for managing hierarchical configuration with priorities.
 */
class Config implements \ArrayAccess {
	private $configs = [];
	private $priorities = [];
	private $merged = null;

	/**
	 * Add a configuration array with a given priority.
	 * Higher priority values override lower ones.
	 *
	 * @param array $config Key-value pairs to store
	 * @param int $priority Priority level (higher = overrides lower)
	 * @param string $name Optional name for this config set
	 */
	public function add($config, $priority = 0, $name = null) {
		$key = $name ?: count($this->configs);
		$this->configs[$key] = $config;
		$this->priorities[$key] = $priority;
		$this->merged = null; // Invalidate cache
	}

	/**
	 * Get the merged configuration with priorities applied.
	 *
	 * @return array Merged configuration
	 */
	public function get() {
		if ($this->merged === null) {
			$this->merged = $this->merge();
		}
		return $this->merged;
	}

	/**
	 * Merge all configurations by priority (low to high).
	 *
	 * @return array
	 */
	private function merge() {
		$keys = array_keys($this->configs);
		$priorities = array_values(array_intersect_key($this->priorities, array_flip($keys)));
		array_multisort($priorities, SORT_ASC, $keys);

		$result = [];
		foreach ($keys as $key) {
			$result = $this->mergeRecursive($result, $this->configs[$key]);
		}

		return $result;
	}

	/**
	 * Recursively merge two arrays.
	 *
	 * @param array $base Base array
	 * @param array $override Override array
	 * @return array Merged result
	 */
	private function mergeRecursive($base, $override) {
		foreach ($override as $key => $value) {
			if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
				$base[$key] = $this->mergeRecursive($base[$key], $value);
			} else {
				$base[$key] = $value;
			}
		}
		return $base;
	}

	/**
	 * Get a specific value by key path (supports dot notation).
	 *
	 * @param string $path Key path (e.g., "database.host")
	 * @param mixed $default Default value if not found
	 * @return mixed
	 */
	public function getValue($path, $default = null) {
		$merged = $this->get();
		$keys = explode(".", $path);
		$current = $merged;

		foreach ($keys as $key) {
			if (is_array($current) && isset($current[$key])) {
				$current = $current[$key];
			} else {
				return $default;
			}
		}

		return $current;
	}

	/**
	 * ArrayAccess: Check if offset exists in merged config.
	 *
	 * @param mixed $offset
	 * @return bool
	 */
	public function offsetExists($offset) {
		return isset($this->get()[$offset]);
	}

	/**
	 * ArrayAccess: Get value by offset from merged config.
	 *
	 * @param mixed $offset
	 * @return mixed
	 */
	public function offsetGet($offset) {
		$merged = $this->get();
		return isset($merged[$offset]) ? $merged[$offset] : null;
	}

	/**
	 * ArrayAccess: Set value by offset (not supported for merged config).
	 *
	 * @param mixed $offset
	 * @param mixed $value
	 * @throws Exception
	 */
	public function offsetSet($offset, $value) {
		throw new Exception("Cannot modify merged config directly. Use add() method. Attempted to set '$offset' to '$value' at location: ".debug_backtrace()[0]['file'].":".debug_backtrace()[0]['line']);
	}

	/**
	 * ArrayAccess: Unset offset (not supported for merged config).
	 *
	 * @param mixed $offset
	 * @throws Exception
	 */
	public function offsetUnset($offset) {
		throw new Exception("Cannot modify merged config directly. Use add() method.");
	}
}
