<?php
define("DAY",86400);
//define("WEBHOME","/home/zygordata/www");

$TELEMETRY_CFG = require "../config.inc.php";
$servername = $TELEMETRY_CFG["DB"]["host"];
$username = $TELEMETRY_CFG["DB"]["user"];
$password = $TELEMETRY_CFG["DB"]["pass"];
$dbname = $TELEMETRY_CFG["DB"]["db"];
$servername = $TELEMETRY_CFG["DB"]["host"];

$flavours = [
	1=>"Retail",
	2=>"Classic",
	3=>"Classic MOP"
];


// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get unique guides
$sql_guides = "SELECT DISTINCT guide,flavnum FROM gossips ORDER BY flavnum ASC, guide ASC";
$result_guides = $conn->query($sql_guides);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mined Guides</title>
    <style>
        body { font-family: sans-serif; display: flex; }
		#guides-column { flex: 1; padding: 20px; border-right: 1px solid #ccc; height: 100vh; overflow-y: auto;}
		#info-column { flex: 3; padding: 20px; }
		#guides-column ul { list-style-type: none; padding: 0; }
		#guides-column li { margin-bottom: 5px; cursor: pointer; color: #007bff; }
		#guides-column li:hover { text-decoration: underline; }
		#info-column table {width: 100%;}
		#info-column th {text-align: left;}
		#filterInput {width: 100%; }
    </style>
</head>
<body>

<div id="guides-column">
	<input type="text" id="filterInput" placeholder="Start typing to filter list...">
	<h2>Guides</h2>
    <ul>
        <?php
		$current_flavour = 0;
        if ($result_guides->num_rows > 0) {
            while($row = $result_guides->fetch_assoc()) {
				if ($row["flavnum"] != $current_flavour) {
					$current_flavour = $row["flavnum"];
					echo "<h3>Flavour: ".$flavours[$current_flavour]."</h3>";
				};
                echo "<li>".htmlspecialchars($row["guide"]) . "</li>";
            }
        } else {
            echo "<li>No guides found.</li>";
        }
        ?>
    </ul>
</div>

<div id="info-column">
    <h2>Guide Information</h2>
    <div id="info-content">
        <p>Select a guide from the left column to view its information.</p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const guideItems = document.querySelectorAll('#guides-column li');
    const infoContent = document.getElementById('info-content');

    guideItems.forEach(item => {
        item.addEventListener('click', function() {
            const guideName = this.textContent;
            infoContent.innerHTML = 'Loading...';

            fetch(`ajax.php?guide=${encodeURIComponent(guideName)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text(); // Use .text() instead of .json()
                })
                .then(html => {
                    infoContent.innerHTML = html;
                })
                .catch(error => {
                    infoContent.innerHTML = `<p>Error: ${error.message}</p>`;
                    console.error('Fetch error:', error);
                });
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {
	const input = document.getElementById('filterInput');
    const guideItems = document.querySelectorAll('#guides-column li');

  // Listen for the 'input' event, which fires immediately when the value changes
  input.addEventListener('input', function() {
    // Get the current value from the input and convert it to lowercase for case-insensitive search
    const filterText = input.value.toLowerCase();

    guideItems.forEach(item => {
      const itemText = item.textContent.toLowerCase();
      if (itemText.includes(filterText)) {
        item.style.display = 'list-item';
      } else {
        item.style.display = 'none';
      }
    })
  });
});
</script>

</body>
</html>