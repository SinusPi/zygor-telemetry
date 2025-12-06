<?php
class Zygor {

	public static $cfg;
	public static $cfg_meta;

	static $dbcfg_conn;
	
	public static function get_client_ip() {
		$ipaddress = '';
		if (!$ipaddress) $ipaddress = getenv('HTTP_CF_CONNECTING_IP');
		if (!$ipaddress) $ipaddress = getenv('HTTP_CLIENT_IP');
		if (!$ipaddress) $ipaddress = getenv('HTTP_X_FORWARDED_FOR');
		if (!$ipaddress) $ipaddress = getenv('HTTP_X_FORWARDED');
		if (!$ipaddress) $ipaddress = getenv('HTTP_FORWARDED_FOR');
		if (!$ipaddress) $ipaddress = getenv('HTTP_FORWARDED');
		if (!$ipaddress) $ipaddress = getenv('REMOTE_ADDR');
		if (!$ipaddress) $ipaddress = '0.0.0.0';
		return $ipaddress;
	}
	
	static function redirect($target) {
		$GLOBALS['ZYGOR_PAGE_META']['use_template']=false; // so that any emergency wrappers don't try to wrap the redirect
		header("Location: $target");
		exit();
	}

	static function die_code($code,$msg) {
		http_response_code($code);
		if (strpos($_SERVER['HTTP_ACCEPT'],"application/xhtml+xml")) echo "$code\n";
		echo $msg;
		exit(1);
	}
	
	static function display_error() {
	        $lasterror = error_get_last();
                $errortext = print_r($lasterror,1);
                $stacktrace = debug_backtrace();

		if (strpos($_SERVER['HTTP_HOST'],"www-dev")!==FALSE) { echo "</div></div></div></div></body><pre>"; print_r($errortext); print_r($stacktrace); die(); }

                http_response_code(500);
                
                        $uniqid = uniqid();
                        self::broadcast_error($errortext."\n\nID: ".$uniqid, "Zygor PHP error: ".$lasterror['message']);
                 
                        // public error
						$page_contents="";
                        while (ob_get_level()) $page_contents = ob_get_clean() . $page_contents;   // abort any output so far
                        
                        $error_template = @file_get_contents($_SERVER['DOCUMENT_ROOT']."/templates/".$GLOBALS['TEMPLATE']."/php_error.html");
                        if (!$error_template) $error_template = "<h1>Something crashed. We're very sorry.</h1><h2>Our staff have been notified of the error.</h2><h3>Error ID: {UNIQID}</h3>";
                        $error_template = str_replace("{UNIQID}",$uniqid,$error_template);
                        echo $error_template;
                        
                die();
	}

	static function broadcast_error($msg,$subject="Zygor PHP error") {
		$SESSIONCUT=$_SESSION;
		unset($SESSIONCUT['_amember_product_ids'],$SESSIONCUT['_amember_subscriptions'],$SESSIONCUT['_amember_products']);
		$msg .= "\n\nAt: ".$_SERVER['SCRIPT_URI']."\n";
		$msg .= "Session: ".print_r($_SESSION,1)."\n";
		$msg .= "Server: ".print_r($_SERVER,1)."\n";
		$msg .= "Request: ".print_r($_REQUEST,1)."\n";
		$msg .= "Backtrace: ".print_r(debug_backtrace(),1);
		@mail("sinus-zygor@sinpi.net",$subject,$msg,"From: php@zygorguides.com","-f php@zygorguides.com");
		global $db,$am_db;
		if (is_a($am_db,"amember_db")) $am_db->log_error("ERROR: ".$msg);
		elseif (is_a($db,"amember_db")) $db->log_error("ERROR: ".$msg);
	}

	static function dbcfg_connect() {
		extract($GLOBALS['ZYGORDB']);
		self::$dbcfg_conn = mysqli_connect($host,$user,$pass,$db);
		if (!self::$dbcfg_conn) die("Database failure.");
		$db = mysqli_select_db(self::$dbcfg_conn,"wowzygor_site");
		if (!$db) die(mysqli_error(self::$dbcfg_conn));
	}
	static function dbcfg_load_config() {
		$q = mysqli_query(self::$dbcfg_conn,"SELECT * FROM config");
		self::$cfg = [];
		self::$cfg_meta = [];
		while ($q && ($r=mysqli_fetch_assoc($q))) {
			self::$cfg[$r['variable']]=$r['value'];
			self::$cfg_meta[$r['variable']]=['comment'=>$r['comment'],'label'=>$r['label'],'type'=>$r['type']];
		}

		if ($_SERVER['HTTP_X_ZYGOR_OPTOVERRIDES']=="flurboogle") {
			foreach ($_SERVER as $k=>$v) {
				if (preg_match("/HTTP_X_ZYGOR_OPT_(.+)/",$k,$m)) {
					header("X-Zygor-Opts: ".$m[1].": ".$v,false);
					self::$cfg[strtolower($m[1])]=$v;
				}
			}
		}
	}
	static function dbcfg_get_ahscan_usage($amember_id=null) {
		$q = mysqli_query(self::$dbcfg_conn,"SELECT * FROM ahscan_usage WHERE ".($amember_id ? "amember_id=".intval($amember_id) : " ip='".$_SERVER['REMOTE_ADDR']."' LIMIT 100"));
		return mysqli_fetch_all($q,MYSQLI_ASSOC);
	}
	static function dbcfg_put_ahscan_usage($usage,$amember_id=null) {
		$q = mysqli_query(self::$dbcfg_conn,"INSERT INTO ahscan_usage VALUES (".($amember_id?intval($amember_id):'NULL').",'".$_SERVER['REMOTE_ADDR']."','".addslashes($usage)."')");
	}
	static function dbcfg_disconnect() {
		mysqli_close(self::$dbcfg_conn);
	}

	static function dbcfg_save_configval($k,$v) {
		$q="INSERT INTO config (variable,value) VALUES (\"".addslashes($k)."\",\"".addslashes($v)."\") ON DUPLICATE KEY UPDATE value=\"".addslashes($v)."\"";
		//$q = mysqli_query(self::$dbcfg_conn,"UPDATE config SET value=\"".addslashes($v)."\" WHERE variable=\"".addslashes($k)."\" ON DUPLICATE KEY UPDATE value=\"".addslashes($v)."\"");
		$r = mysqli_query(self::$dbcfg_conn,$q);
		if (!$r)
			self::die_with_error(500,"configerr","Error saving config: ".mysqli_error(self::$dbcfg_conn)." affected ".mysqli_affected_rows(self::$dbcfg_conn)." in ".$q,[]);
	}
	static function dbcfg_save_config() {
		foreach (self::$cfg as $k=>$v) {
			self::dbcfg_save_configval($k,$v);
		}
	}

	static function http_accepts($q_type) {
		$types = explode(",",$_SERVER['HTTP_ACCEPT']);
		foreach ($types as $type) {
			$type=trim($type);
			$sc = strpos($type,";");  if ($sc!==FALSE) $type=substr($type,0,$sc);
			if ($q_type==$type) return true;
		}
	}
	// v.01: code,text,
	static function get_requested_api() {
	    return intval($_SERVER['HTTP_X_ZYGOR_API']?:$_REQUEST['zygor_api'])?:0;
	}
	
	static function die_with_error($http_response=200,$code,$text="",$fields=[]) {
		http_response_code($http_response);
		$apiver = self::get_requested_api();
		if ($apiver>=2 && !self::http_accepts("application/json") && !$_REQUEST['zygor_api']) { http_response_code(400); header("Content-type: text/plain"); die("You have to Accept: application/json to use API version >=2."); }
		if ($apiver>=2) {
			header("Content-type: application/json; charset=utf-8");

			$error = ['success'=>false,'code'=>$code,'text'=>$text];
			$error = array_merge($error,$fields);
			die(json_encode($error));

		}
		// else
		die ($fields['legacy'] ?: $text);
	}

	static function die_or_json($text,$json) {
		$apiver = intval($_SERVER['HTTP_X_ZYGOR_API']?:$_REQUEST['zygor_api']);
		if ($apiver>=2 && !self::http_accepts("application/json") && !$_REQUEST['zygor_api']) { http_response_code(400); header("Content-type: text/plain"); die("You have to Accept: application/json to use API version >=2."); }
		if ($apiver>=2) {
			header("Content-type: application/json; charset=utf-8");
			$encoded = json_encode($json,0 | ($_REQUEST['prettify_json']?JSON_PRETTY_PRINT:0));
			$supportsGzip = strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false;
			if ($supportsGzip) {
				$gz = gzencode($encoded);
				header("Content-Encoding: gzip");
				header("Content-Length: ".strlen($gz));
				die($gz);
			} else
				die($encoded);
		}
		// else
		die ($text);
	}
	
	static function dump_collapsed($data) {
		@include_once(__DIR__."/../amember/includes/collapser.class.php");
		(new Collapser\Collapser([]))->show($data);
	}

	static function get_smarty() {
		require_once($_SERVER['DOCUMENT_ROOT']."/includes/config.inc.php"); // Main Zygor site config.
		require_once($_SERVER['DOCUMENT_ROOT']."/includes/smarty-3.1.30/libs/SmartyBC.class.php");  //<<<
		
		if (!class_exists("SmartyBC")) require_once($_SERVER['DOCUMENT_ROOT']."/includes/smarty-3.1.30/libs/SmartyBC.class.php");
		$t = new SmartyBC();
		$base = $_SERVER['DOCUMENT_ROOT'].'/templates/'.$GLOBALS['TEMPLATE'];
		$t->setTemplateDir($base);
		$t->setCompileDir($base."/smarty_compiled");
		$t->setCacheDir($base."/smarty_cache");
		$t->setConfigDir($base."/smarty_configs");

		return $t;
	}
	
	static function forum_avatar($vbuserid) {
		return "/vb_5-5-0/core/image.php?userid=".$vbuserid;
	}

	public static function isDebug() {
		return $_SERVER['HTTP_X_ZYGOR_DEBUG_FOO734'];
	}

	/**
	 * Borrowed from Amember.
     * Construct safe query string, think sprintf.
     * "SELECT * FROM {p}some_table WHERE number={d} and text={s}
     *.
     * {p} = prefix, replaced automatically from db config; UNUSED OUTSIDE AMEMBER
     * {s} = string, like %s; wraps in "", escapes as necessary
     * {d} = digits (int), like %d, if invalid outputs 0
     * {f} = float, like %f, if invalid outputs 0
     * {sn},{dn},{fn} = -nullable (shows "NULL" instead of "" or 0 when value is invalid, not just null).
     */
    static function qesc($db,$query,...$args) {
        $s = preg_replace_callback("/\\{([pdfs]n?a?)\\}/",function($m) use (&$args,$db) {
            $format = $m[1];
            $type=substr($format,0,1);
            //if ($type=="p") return $this->config['prefix'];
            $null = strpos($format,"n")!==FALSE;
			$arr = strpos($format,"a")!==FALSE;
            if (count($args)==0) throw new Exception("Too few args for qesc");
            $val = array_shift($args);
			if ($arr) {
				if (!is_array($val)) throw new Exception("qesc: expected array for {".$format."} but got ".gettype($val));
				$vals = array_map(function($v) use ($type,$null,$db) {
					if ($type=="f") return ($null && !is_numeric($v)) ? "NULL" : floatval($v);
					elseif ($type=="d") return ($null && !is_numeric($v)) ? "NULL": intval($v);
					elseif ($type=="s") return sprintf('"%s"',$db->real_escape_string($v));
				},$val);
				return join(",",$vals);
			} else {
				if ($type=="f") return ($null && !is_numeric($val)) ? "NULL" : floatval($val);
				elseif ($type=="d") return ($null && !is_numeric($val)) ? "NULL": intval($val);
				elseif ($type=="s") return ($null ? "NULL" : sprintf('"%s"',$db->real_escape_string($val)));
			}
        },$query);
        //error_log("QESC: ".$s);
        return $s;
    }

    static function qarrayesc($db,$query,$data) {
		$keys = [];
		$values = [];

		foreach($data as $key=>$value) {
			$keys[] = $key;
			if (is_float($value)) {
				$values[] = floatval($value);
			} elseif (is_numeric($value)) {
				$values[] = intval($value);
			} else {
				$values[] = sprintf('"%s"',$db->real_escape_string($value));
			}
		}

		$query = str_replace("{keys}",implode(', ', $keys),$query);
		$query = str_replace("{values}",implode(', ', $values),$query);

		return $query;
	}


	public static function setUp()
	{
		return new MockDb();
	}

	public static function testQesc()
	{
		$mockDb = self::setUp();

		self::assertEquals( 'SELECT * FROM users WHERE name="John Doe"',	Zygor::qesc($mockDb, "SELECT * FROM users WHERE name={s}", "John Doe")    		);
		self::assertEquals( 'SELECT * FROM users WHERE age=25',         	Zygor::qesc($mockDb, "SELECT * FROM users WHERE age={d}", 25)             		);
		self::assertEquals( 'SELECT * FROM products WHERE price=19.99', 	Zygor::qesc($mockDb, "SELECT * FROM products WHERE price={f}", 19.99)     		);
		self::assertEquals( 'SELECT * FROM users WHERE name=NULL',      	Zygor::qesc($mockDb, "SELECT * FROM users WHERE name={sn}", null)         		);
		self::assertEquals( 'SELECT * FROM users WHERE age=NULL',       	Zygor::qesc($mockDb, "SELECT * FROM users WHERE age={dn}", null)          		);
		self::assertEquals( 'SELECT * FROM users WHERE id IN (1,2,3)',  	Zygor::qesc($mockDb, "SELECT * FROM users WHERE id IN ({da})", [1, 2, 3]) 		);
		self::assertEquals( 'SELECT * FROM users WHERE names IN ("John Doe","Mary Sue")',	Zygor::qesc($mockDb, "SELECT * FROM users WHERE names IN ({sa})", ["John Doe", "Mary Sue"])	);
	}

	public static function testQescInvalidCases()
	{
		$mockDb = self::setUp();

		try {
			Zygor::qesc($mockDb, "SELECT * FROM users WHERE name={s}");
			echo "Failed: Expected exception for too few args\n";
		} catch (Exception $e) {
		}

		try {
			Zygor::qesc($mockDb, "SELECT * FROM users WHERE id IN ({da})", "not an array");
			echo "Failed: Expected exception for invalid array input\n";
		} catch (Exception $e) {
		}
	}

	private static function assertEquals($expected, $actual)
	{
		if ($expected !== $actual) {
			echo "Assertion failed: Expected '$expected', got '$actual'\n";
		} else {
			//echo "Assertion passed: '$expected' equals '$actual'\n";
		}
	}

	public static function tests() {
		self::testQesc();
		self::testQescInvalidCases();
		die ("All tests completed.\n");
	}

}

class MockDb {
	public function real_escape_string($str) {
		return addslashes($str);
	}
}