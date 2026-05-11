<?php
namespace Zygor\Telemetry\Tools;

class Date {
	/**
	 * Generates next day string in given format.
	 * @param string $day in "Y-m-d" format
	 */
	static $format = "Y-m-d";
	static function next_day($day) {
		return date(self::$format, strtotime($day . " +1 day"));
	}

	/**
	 * Generates a range of days from $from to $to.
	 * @param string $from yyyy-mm-dd of the start day
	 * @param string $to yyyy-mm-dd of the end day, exclusive
	 * @return \Generator<string> yields each day in the range
	 */
	static function gen_days($from, $to) {
		for ($day = $from; $day < $to; $day = self::next_day($day))
			yield $day;
	}
}
