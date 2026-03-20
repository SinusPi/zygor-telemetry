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

		if (isset($_REQUEST['topic'])) {
			return self::serveDataRequest($_REQUEST['topic']);
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
			$scraper = isset($config['scraper']['input']) ? $config['scraper']['input'] : null;
			$has_crunchers = isset($config['crunchers']) && is_array($config['crunchers']) && count($config['crunchers']) > 0;
			$has_endpoint = isset($config['endpoint']);
			$has_view = isset($config['view']);
			
			$cruncher_count = 0;
			if ($has_crunchers) {
				$cruncher_count = count($config['crunchers']);
			}
			
			$topics[$name] = [
				'name' => $name,
				'scraper' => $scraper,
				'crunchers' => $cruncher_count,
				'endpoint' => $has_endpoint,
				'view' => $has_view,
			];
		}
		self::response([
			"success" => true,
			"code" => 200,
			"topics" => $topics,
		]);
	}

	static function serveDataRequest($topic) {
		$flavour = isset($_REQUEST['flavour']) ? $_REQUEST['flavour'] : "";
		$flavnum = isset(self::$CFG['WOW_FLAVOUR_DATA'][$flavour]['num']) ? self::$CFG['WOW_FLAVOUR_DATA'][$flavour]['num'] : 0;
		if (!$flavnum) self::response(["success" => false, "error" => "Invalid flavour specified", "errcode" => "BAD_FLAVOUR"]);

		try {
			$from = parent::parse_date($_REQUEST['from']);
			$to = parent::parse_date($_REQUEST['to']);
		} catch (Exception $e) {
			self::response([
				"success" => false,
				"code" => 400,
				"error" => "Invalid date in from/to parameters: " . $e->getMessage(),
				"errcode" => "BAD_DATE",
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

		$topicendpoint = isset(self::$CFG['TOPICS'][$topic]['endpoint']) ? self::$CFG['TOPICS'][$topic]['endpoint'] : null;
		if (!$topicendpoint || !is_callable($topicendpoint['queryfunc'])) {
			self::response([
				"success" => false,
				"code" => 400,
				"error" => "Invalid topic parameter, or no endpoint defined for topic",
				"topic" => $topic,
				"errcode" => "BAD_TOPIC",
			]);
		}

		$variant = isset($_REQUEST['variant']) ? $_REQUEST['variant'] : null;
		if ($variant === 'daymap') {
			return self::serveDataRequestDaymap($topic, $from, $to, $flavnum);
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

	static function serveDataRequestDaymap($topic, $from, $to, $flavnum) {
		$topicendpoint = isset(self::$CFG['TOPICS'][$topic]['endpoint']) ? self::$CFG['TOPICS'][$topic]['endpoint'] : null;
		$table = isset(self::$CFG['TOPICS'][$topic]['crunchers'][0]['table']) ? self::$CFG['TOPICS'][$topic]['crunchers'][0]['table'] : null;
		
		if (!$table) {
			self::response([
				"success" => false,
				"code" => 400,
				"error" => "Topic does not have a database table configured for daymap variant",
				"errcode" => "NO_TABLE",
			]);
		}

		try {
			// Build daymap for entire range in one query
			$query = self::db_qesc(
				"SELECT FROM_UNIXTIME(`time`, '%Y-%m-%d') as day, COUNT(*) as cnt FROM `$table`
				WHERE `flavnum`={d} AND `time`>={d} AND `time`<{d}
				GROUP BY day
				ORDER BY day ASC",
				$flavnum, $from, $to
			);
			$result = $query->fetch_all(MYSQLI_ASSOC);
			
			$daymap = [];

			// Initialize all days in range with 0
			// $current_day = intval($from / 86400) * 86400;
			// $end_day = intval($to / 86400) * 86400;
			// while ($current_day <= $end_day) {
			// 	$daymap[date('Y-m-d', $current_day)] = 0;
			// 	$current_day += 86400;
			// }
			
			// Fill in actual counts from query results
			foreach ($result as $row) {
				$daymap[$row['day']] = intval($row['cnt']);
			}
			
			self::response([
				"success" => true,
				"code" => 200,
				"id" => intval(isset($_REQUEST['id']) ? $_REQUEST['id'] : 0),
				"data" => $daymap,
				"query" => self::$LAST_QUERY,
			]);
		} catch (Exception $e) {
			self::response([
				"success" => false,
				"code" => 500,
				"error" => "Exception while processing daymap for topic " . $topic . ": " . $e->getMessage(),
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
