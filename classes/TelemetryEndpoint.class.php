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
		if (!in_array($flavour,array_keys(self::$CFG['WOW_FLAVOUR_DATA']))) self::response(["success"=>false,"error"=>"Invalid flavour specified","errcode"=>"BAD_FLAVOUR"]);

		try {
			$from = strtotime($_REQUEST['from']);
			$to = strtotime($_REQUEST['to']);
		} catch (Exception $e) {
			self::response([
				"success"=>false,
				"code"=>400,
				"error"=>"Invalid date format in from/to parameters: ".$e->getMessage(),
				"errcode"=>"BAD_DATE",
			]);
		}

		$topic = $_REQUEST['topic'] ?: null;
		$topicendpoint = self::$CFG['TOPICS'][$topic]['endpoint'] ?: null;
		if (!$topic || !$topicendpoint || !is_callable($topicendpoint)) {
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
			$data = $topicendpoint($from,$to,$flavour);
			self::response([
				"success"=>true,
				"code"=>200,
				"id"=>intval($_REQUEST['id']?:0),
				"data"=>$data,
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

	static function query($from,$to,$flavour,$table,$select,$groupby="",$order="",$limit="") {
		$q = self::db_qesc($select." FROM `%d` WHERE `flavnum`=%d AND `time`>=%d AND `time`<=%d $groupby $order", $table, $flavour, $from, $to);
		return $results = self::$db->fetch_all($q);
	}

	// Tests, DB schemas

	static function self_tests() {
		return;
		//self::test_paths();
	}
}
