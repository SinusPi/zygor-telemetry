<?php

/**
 * Set of utilities to display metrics' HTML forms and charts.
 */
class TelemetryView extends Telemetry {
	static function config($cfg=[]) {
		parent::config($cfg);

		$configfile = (array)(@include "config-view.inc.php"); // load defaults
		self::$CFG = self::merge_configs(self::$CFG, $configfile, $cfg);
	}

	static function renderMetrics() {
		foreach (self::$CFG['TOPICS'] as $topicname => &$topicdata) {
			$view = &$topicdata['view'] ?: null;
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
			self::db_create();
			self::test_status();
			self::vlog("Database: connected and present.");
		} catch (Exception $e) {
			die("DB Connection to ".self::$CFG['DB']['host']." FAILED - ".$e->getMessage());
		}
		self::vlog("Self-tests: \x1b[48;5;70;30mPASS\x1b[0m");
	}
}
