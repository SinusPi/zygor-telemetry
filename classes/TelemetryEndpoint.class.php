<?php

use Telemetry as Tm;

/**
 * Query the database for telemetry metrics and produce them in JSON form
 * 
 * Maintenance requests:
 * - do=list_topics: list all loaded topics with metadata (e.g. which scraper they use, how many crunchers, etc.)
 * - do=list_sources: list all registered sources with metadata (e.g. which topics use them, whether they are configured properly, etc.)
 * - do=get_status: return all status records from the database (for now, just dump them all, maybe later add filtering by tag or something)
 * Data requests:
 * - topic=<topic>&flavour=<flavour>&from=<timestamp>&to=<timestamp>: return data for a specific topic and flavour in the given time range.
 *   - optional: variant=daymap: get a day->count map instead of raw data (for showing activity over time in a calendar-style heatmap)
 */
class TelemetryEndpoint {
	static $CFG = null;

	static function config() {
		self::$CFG = &Telemetry::$CFG; // reference main config for easy access

		$configfile = (array)(@include "config-endpoint.inc.php"); // load defaults
		self::$CFG->add($configfile, 50, "endpoint config file"); // add to main config with medium priority
	}

	static function startup() {
		self::config();
	}

	static function serveRequest() {
		$do = isset($_REQUEST['do']) ? $_REQUEST['do'] : null;
		
		if ($do === 'list_topics') {
			return self::serveListTopics();
		}

		if ($do === 'list_sources') {
			return self::serveListSources();
		}

		if ($do === 'get_status') {
			return self::serveGetStatus();
		}

		if (isset($_REQUEST['topic'])) {
			return self::serveDataRequest();
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
		foreach (Telemetry::$TOPICS as $name => $topicObj) {
			/** @var Topic $topicObj */
			$scraper = $topicObj->getScraper();
			$scraper_input = isset($scraper['input']) ? $scraper['input'] : null;
			$crunchers = $topicObj->getCrunchers();
			$has_crunchers = is_array($crunchers) && count($crunchers) > 0;
			$has_endpoint = $topicObj->getEndpoint() !== null;
			$has_view = $topicObj->getView() !== null;
			
			$crunchers_list = [];
			if ($has_crunchers) {
				foreach ($crunchers as $idx => $cruncher) {
					$eventtype = isset($cruncher['eventtype']) ? $cruncher['eventtype'] : 'unknown';
					$table = isset($cruncher['table']) ? $cruncher['table'] : null;
					$crunchers_list[] = [
						'index' => $idx,
						'eventtype' => $eventtype,
						'table' => $table,
					];
				}
			}
			
			$topics[$name] = [
				'name' => $name,
				'scraper' => $scraper_input,
				'crunchers' => count($crunchers_list),
				'crunchers_list' => $crunchers_list,
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

	static function serveListSources() {
		$sources = [];
		
		// Get registered sources from TelemetryScrape
		//TelemetryScrape::startup(); // ensure scrapers are initialized to get their config
		$registered = TelemetryScrape::list_scrapers();
		
		foreach ($registered as $key => $source_info) {
			$class = $source_info['class'];
			$topic_count = 0;
			$topics_list = [];
			$source_paths = [];
			
			try {
				$error = null;
				// Count topics using this scraper and collect topic names
				$topics_list = array_keys(array_filter((array)Telemetry::$TOPICS, function($t) use ($key) {
					/** @var Topic $t */
					$scraper = $t->getScraper();
					return isset($scraper['input']) && $scraper['input'] === $key;
				}));
				$topic_count = count($topics_list);
				
				// Get configured paths from the scraper class itself
				$class::startup();
				$source_paths = $class::getConfiguredPaths();
				
				// Determine if scraper is configured based on whether paths exist
				//$status = (count($source_paths) > 0 && $class::verifyConfiguredPaths()) ? 'configured' : 'not-configured';

			} catch (Exception $e) {
				$error = $e->getMessage();
			}
			
			$sources[$key] = array_merge($source_info, [
				'topics' => $topic_count,
				'topics_list' => $topics_list,
				'status' => !$error,
				'error' => $error,
				'source_paths' => $source_paths
			]);
		}
		
		self::response([
			"success" => true,
			"code" => 200,
			"sources" => $sources,
		]);
	}

	static function serveGetStatus() {

		try {
			// Fetch all status records from the database
			$query = Telemetry::$db->query("SELECT tag, status, updated_at FROM status ORDER BY tag ASC");
			if (!$query) 
				throw new Exception("Database query failed: " . Telemetry::$db->error());
			$result = $query->fetch_all(MYSQLI_ASSOC);

			$statuses = [];
			foreach ($result as $row) {
				$row['data'] = (array)json_decode($row['status'], true);
				$statuses[] = $row;
			}

			self::response([
				"success" => true,
				"code" => 200,
				"statuses" => $statuses,
			]);
		} catch (Exception $e) {
			self::response([
				"success" => false,
				"code" => 500,
				"error" => "Exception while fetching status records: " . $e->getMessage(),
			]);
		}
	}

	static function serveDataRequest() {
		$topic = isset($_REQUEST['topic']) ? $_REQUEST['topic'] : "";
		$flavour = isset($_REQUEST['flavour']) ? $_REQUEST['flavour'] : "";
		$flavnum = isset(self::$CFG['WOW_FLAVOUR_DATA'][$flavour]['num']) ? self::$CFG['WOW_FLAVOUR_DATA'][$flavour]['num'] : 0;
		if (!$flavnum) self::response(["success" => false, "error" => "Invalid flavour specified", "errcode" => "BAD_FLAVOUR"]);

		try {
			$from = Tm::parse_date($_REQUEST['from']);
			$to = Tm::parse_date($_REQUEST['to']);
		} catch (Exception $e) {
			self::response([
				"success" => false,
				"code" => 400,
				"error" => "Invalid date in from/to parameters: " . $e->getMessage(),
				"errcode" => "BAD_DATE",
			]);
		}

		$topicObj = Telemetry::getTopic($topic);
		$topicendpoint = $topicObj ? $topicObj->getEndpoint() : null;
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
				"query" => Tm::$db->LAST_QUERY,
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
		$topicObj = Telemetry::getTopic($topic);
		$topicendpoint = $topicObj ? $topicObj->getEndpoint() : null;
		$crunchers = $topicObj ? $topicObj->getCrunchers() : [];
		$table = isset($crunchers[0]['table']) ? $crunchers[0]['table'] : null;

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
			$query = Telemetry::$db->query(Telemetry::$db->qesc(
				"SELECT FROM_UNIXTIME(`time`, '%Y-%m-%d') as day, COUNT(*) as cnt FROM `$table`
				WHERE `flavnum`={d} AND `time`>={d} AND `time`<{d}
				GROUP BY day
				ORDER BY day ASC",
				$flavnum, $from, $to
			));
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
				"query" => Tm::$db->LAST_QUERY,
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
		$q = Telemetry::$db->query(Telemetry::$db->qesc($select." FROM `$table` WHERE `flavnum`={d} AND `time`>={d} AND `time`<={d} AND ".join(" AND ",$where)." $groupby $order", $flavour, $from, $to));
		return $results = $q->fetch_all();
	}
	*/

	// Tests, DB schemas

	static function self_tests() {
		return;
		//self::test_paths();
	}
}
