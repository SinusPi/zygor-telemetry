<?php

class TelemetryStatus {
	static $last_statuses = [];
	static $last_tag = "";

	static function &get_status($tag,$force=false) {
		if ($force || !isset(self::$last_statuses[$tag]) && $last = Telemetry::$db->get_status($tag))
			self::$last_statuses[$tag] = $last;
		if (!isset(self::$last_statuses[$tag]))
			self::$last_statuses[$tag] = [];
		return self::$last_statuses[$tag];
	}
	static function status($tag,$data,$keep=false) {
		if (!Telemetry::$db) return; // DB not connected, no status
		$last_status = $keep ? self::get_status($tag) : [];
		$last_status = array_replace_recursive($last_status,$data);

		self::$last_tag = $tag;
		self::$last_statuses[$tag] = $last_status;

		Telemetry::$db->set_status($tag, $last_status);
	}
	static function test_status() {
		$testtag="TEST";
		self::status($testtag,['status'=>"TESTING1", 'foo'=>"bar"]);
		$status = self::get_status($testtag);
		if ($status['status']!="TESTING1" || $status['foo']!="bar") throw new Exception("Status test failed 1: ".print_r($status,true));

		self::status($testtag,['status'=>"TESTING2"], true);
		$status = self::get_status($testtag);
		if ($status['status']!="TESTING2" || $status['foo']!="bar") throw new Exception("Status test failed 2: ".print_r($status,true));

		Telemetry::$db->delete_status($testtag);
	}

	static function stat($data,$keep=false) {
		return self::status(self::$last_tag,$data,$keep);
	}

	static function update_progress($tag,$n,$total,$extra=[],$force=false) {
		static $time_last_status=0;
		static $n_last=0;
		static $speedbuffer=20;
		static $speeds=[]; if (count($speeds)==0) $speeds=array_fill(0,$speedbuffer,0);

		if (!self::get_status($tag)['time_started']) self::status($tag,['time_started'=>time()],true);

		if ((time()-$time_last_status >= Telemetry::$CFG['STATUS_INTERVAL']) || $force) {
			$mitime = microtime(true);
			$time_elapsed = $mitime-TelemetryStatus::get_status($tag)['time_started'];
			$last_time = $mitime-$time_last_status;
			$last_progress = $n-$n_last;
			$speed = $last_progress/$last_time;
			$speeds[]=$speed; array_shift($speeds);
			$speed_avg=array_sum($speeds)/count($speeds);
			$remaining = $total-$n;
			$time_remaining_est = $remaining/$speed_avg;
	
			$bar_length = 10;

			$tot1=array_filter($extra['totals'] ?: [],function($v) { return is_numeric($v); });
			$tots=array_map(function($k,$v) { return "$k=$v"; }, array_keys($tot1), array_values($tot1));

			$digits=strlen($total);

			$progress = [
				'progress'=>[
					'progress_raw'=>$n+1,
					'progress_total'=>$total,
					'progress_bar'=>str_repeat("#",floor($bar_length*($n+1)/$total)).str_repeat(" ",$bar_length-floor($bar_length*($n+1)/$total)),
					'progress_percent'=>floor(100*($n+1)/$total),
					'time_elapsed'=>$time_elapsed,
					'speed_fps'=>$speed_avg,
					'time_remaining'=>floor($time_remaining_est),
					'time_total_est_hr'=>date("Y-m-d H:i:s",time()+$time_remaining_est),
				],
			] + $extra;
			//print_r(self::get_last_status());
			self::status($tag,$progress,true);
			//print_r(self::get_last_status());
			//die();
			echo sprintf(
				"Progress: [%{$bar_length}s] %3d%% (%{$digits}d/%{$digits}d) - %ds elapsed, %ds remaining; totals: %s\n",
				$progress['progress']['progress_bar'],
				$progress['progress']['progress_percent'],
				$progress['progress']['progress_raw'],
				$progress['progress']['progress_total'],
				$progress['progress']['time_elapsed'],
				$progress['progress']['time_remaining'],
				implode(", ", $tots)
			);
			$time_last_status = time();
			$n_last=$n;
		}
	}

}

