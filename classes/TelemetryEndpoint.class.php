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
		$flavour = $_REQUEST['flavour'] ?: "";
		$flavnum = self::$CFG['WOW_FLAVOUR_DATA'][$flavour]['num'] ?: 0;
		if (!$flavnum) self::response(["success"=>false,"error"=>"Invalid flavour specified","errcode"=>"BAD_FLAVOUR"]);

		try {
			$from = parent::parse_date($_REQUEST['from']);
			$to = parent::parse_date($_REQUEST['to']);
		} catch (Exception $e) {
			self::response([
				"success"=>false,
				"code"=>400,
				"error"=>"Invalid date in from/to parameters: ".$e->getMessage(),
				"errcode"=>"BAD_DATE",
			]);
		}

		$topic = $_REQUEST['topic'] ?: null;
		$topicendpoint = self::$CFG['TOPICS'][$topic]['endpoint'] ?: null;
		if (!$topic || !$topicendpoint || !is_callable($topicendpoint['queryfunc'])) {
			self::response([
				"success"=>false,
				"code"=>400,
				"error"=>"Invalid topic parameter, or no endpoint defined for topic",
				"topic"=>$topic,
				"errcode"=>"BAD_TOPIC",
			]);
		}

		try {
			self::db_connect();
		} catch (Exception $e) {
			self::response([
				"success"=>false,
				"code"=>500,
				"error"=>"Database connection error: ".$e->getMessage(),
				"errcode"=>"DB_ERROR",
			]);
		}

		try {
			$data = $topicendpoint['queryfunc']($from,$to,$flavnum); // any other parameters should be handled by the queryfunc itself, not here
			self::response([
				"success"=>true,
				"code"=>200,
				"id"=>intval($_REQUEST['id']?:0),
				"data"=>$data,
				"query"=>self::$LAST_QUERY
			]);
		} catch (Exception $e) {
			self::response([
				"success"=>false,
				"code"=>500,
				"error"=>"Exception while processing topic ".$topic.": ".$e->getMessage(),
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
