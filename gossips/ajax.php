<?php
header('Content-type: text/plain; charset=utf-8');

define("DAY",86400);

$TELEMETRY_CFG = require "../config.inc.php";
$servername = $TELEMETRY_CFG["DB"]["host"];
$username = $TELEMETRY_CFG["DB"]["user"];
$password = $TELEMETRY_CFG["DB"]["pass"];
$dbname = $TELEMETRY_CFG["DB"]["db"];
$servername = $TELEMETRY_CFG["DB"]["host"];

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);


// Check connection
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

$guide = $_GET['guide'];


if (empty($guide)) {
    die(json_encode(['error' => 'No guide specified.']));
}


$stmt = $conn->prepare("SELECT guide, step, stepgoals, gossip, gossipIcon, file, ver, raceclass,  COUNT(*) as count, GROUP_CONCAT(raceclass) as raceclasses FROM gossips WHERE guide = ? GROUP BY step, gossip ORDER BY step ASC");
$stmt->bind_param("s", $guide);
$stmt->execute();
$result = $stmt->get_result();


$data = [];

while ($row = $result->fetch_assoc()) {
	$data[] = $row;
};


// print guide header
echo "<h1>" . $data[0]["guide"] . "</h1>";
echo "<p>" . $data[0]["file"] . "  ver ". $data[0]["ver"] ."</h1>";


$current_step = null;

function sanitize($input) {
	$output = str_replace("<","&lt;",$input);
	$output = str_replace(">","&gt;",$output);
	return $output;
}

foreach ($data as $entry) {
	if ($current_step != $entry["step"]) {
		if ($current_step != null) {
			echo "</tbody></table>";
		};
		echo "<h3>Step ".sanitize($entry["step"])."</h3>\n";
		echo "<pre>".str_replace("\n","<br>",sanitize($entry["stepgoals"]))."</pre>\n";

		echo "<table><thead><tr><th>Count</th><th>Gossip</th><th>Icon</th><th>RaceClass</th></tr></thead><tbody>\n";
		
		$current_step = $entry["step"];
	};


	$sources = sanitize($entry['raceclasses']);
	$source_array = array_unique(explode(',', $sources));
	$sources_string = implode(', ', $source_array);


	echo "\n<tr>";
	echo "\n<td>" . $entry['count'] ."</td>";
	echo "\n<td>" . sanitize($entry['gossip']) ."</td>";
	echo "\n<td>" . $entry['gossipIcon'] ."</td>";
	echo "\n<td>" . $sources_string ."</td>";
	echo "\n</tr>";
}

echo "";


$stmt->close();
$conn->close();
?>