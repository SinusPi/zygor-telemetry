<?php

/**
 * Query the database for telemetry metrics and produce them in JSON form
 */
class TelemetryEndpoint extends Telemetry {
	static function config($cfg=[]) {
		parent::config($cfg);

		$configfile = (array)(@include "config-endpoint.inc.php"); // load defaults
		self::$CFG = self::merge_configs(self::$CFG, $configfile, $cfg);
	}

	static function serveRequest() {
		$do = isset($_REQUEST['do']) ? $_REQUEST['do'] : null;
		
		if ($do === 'list_topics') {
			return self::serveListTopics();
		}

		$from = isset($_REQUEST['from']) ? $_REQUEST['from'] : null;
		$to = isset($_REQUEST['to']) ? $_REQUEST['to'] : null;
		$topic = isset($_REQUEST['topic']) ? $_REQUEST['topic'] : null;

		if ($from && $to && $topic) {
			return self::serveDataRequest($from, $to, $topic);
		}

		// Handle other requests here
		self::response([
			"success" => false,
			"code" => 400,
			"error" => "Invalid request parameters",
		]);
	}

	static function serveListTopics() {
		$topics = [];
		foreach (self::$CFG['TOPICS'] as $name => $config) {
			$scraper = isset($config['scraper']['input']) ? $config['scraper']['input'] : 'unknown';
			$topics[$name] = [
				'name' => $name,
				'scraper' => $scraper,
				'has_endpoint' => isset($config['endpoint']),
				'has_view' => isset($config['view']),
			];
		}
		self::response([
			"success" => true,
			"code" => 200,
			"topics" => $topics,
		]);
	}

	static function serveDataRequest($from, $to, $topic) {
		$flavour = isset($_REQUEST['flavour']) ? $_REQUEST['flavour'] : "";
		$flavnum = isset(self::$CFG['WOW_FLAVOUR_DATA'][$flavour]['num']) ? self::$CFG['WOW_FLAVOUR_DATA'][$flavour]['num'] : 0;
		if (!$flavnum) self::response(["success" => false, "error" => "Invalid flavour specified", "errcode" => "BAD_FLAVOUR"]);

		try {
			$from = parent::parse_date($from);
			$to = parent::parse_date($to);
		} catch (Exception $e) {
			self::response([
				"success" => false,
				"code" => 400,
				"error" => "Invalid date in from/to parameters: " . $e->getMessage(),
				"errcode" => "BAD_DATE",
			]);
		}

		$topicendpoint = isset(self::$CFG['TOPICS'][$topic]['endpoint']) ? self::$CFG['TOPICS'][$topic]['endpoint'] : null;
		if (!$topic || !$topicendpoint || !is_callable($topicendpoint['queryfunc'])) {
			self::response([
				"success" => false,
				"code" => 400,
				"error" => "Invalid topic parameter, or no endpoint defined for topic",
				"topic" => $topic,
				"errcode" => "BAD_TOPIC",
			]);
		}

		try {
			self::db_connect();
		} catch (Exception $e) {
			self::response([
				"success" => false,
				"code" => 500,
				"error" => "Database connection error: " . $e->getMessage(),
				"errcode" => "DB_ERROR",
			]);
		}

		try {
			$data = call_user_func($topicendpoint['queryfunc'], $from, $to, $flavnum);
			self::response([
				"success" => true,
				"code" => 200,
				"id" => intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0),
				"data" => $data,
				"query" => self::$LAST_QUERY,
			]);
		} catch (Exception $e) {
			self::response([
				"success" => false,
				"code" => 500,
				"error" => "Exception while processing topic " . $topic . ": " . $e->getMessage(),
			]);
		}
	}

	static function response($details=[]) {
		http_response_code($details['code'] ?: 200);
		die(json_encode($details));
	}

	static function get_where_from_to_flavour($from,$to,$flavour) {
		return [
			"`flavnum`=".intval($flavour),
			"`time`>=".intval($from),
			"`time`<".intval($to),
		];
	}

	/*
	static function query($select,$table,$from,$to,$flavour,$where=[1],$groupby="",$order="",$limit="") {
		$q = self::db_qesc($select." FROM `$table` WHERE `flavnum`={d} AND `time`>={d} AND `time`<={d} AND ".join(" AND ",$where)." $groupby $order", $flavour, $from, $to);
		return $results = $q->fetch_all();
	}
	*/

	// Tests, DB schemas

	static function self_tests() {
		return;
		//self::test_paths();
	}
}
