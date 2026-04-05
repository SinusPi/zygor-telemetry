<?php

/**
 * Set of utilities to display metrics' HTML forms and charts.
 */
class TelemetryView {
	static function config($cfg=[]) {
		$configfile = (array)(@include "config-view.inc.php"); // load defaults
		Telemetry::$CFG->add($configfile, 30, $cfg);
	}

	static function renderMetrics() {
		foreach (Telemetry::$TOPICS as $topicname => $topicObj) {
			/** @var Topic $topicObj */
			$view = $topicObj->view;
			if (is_callable($view['printer'])) {
				?><div id="topic-<?=$topicname?>" class="telemetry-topic telemetry-topic-<?=$topicname?> <?=($view['class'] ?: '')?>"><?php
				?><h2 class="telemetry-topic-title"><?=$view['title']?></h2><?php
					$printout = $view['printer']();
					echo $printout;
				?></div><?php
			}
		}
	}

	static function self_tests() {
		return;
		//self::test_paths();
		try {
			Telemetry::$db->create_tables();
			//self::test_status();
			Logger::vlog("Database: connected and present.");
		} catch (Exception $e) {
			die("DB Connection to ".Telemetry::$CFG['DB']['host']." FAILED - ".$e->getMessage());
		}
		Logger::vlog("Self-tests: \x1b[48;5;70;30mPASS\x1b[0m");
	}
}
