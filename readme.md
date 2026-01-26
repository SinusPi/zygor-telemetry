File map:
* config*-dist.inc.php - remove -dist, fill with local credentials and settings.
* topic-*.inc.php - data topic definitions.
* Telemetry.class.php - contains Telemetry class and module subclasses.
* telemetry_scrape.php - locally executable script for the Scraper module (pass 1)
* telemetry_crunch.php - locally executable script for the Cruncher module (pass 2)
* telemetry_endpoint.php - externally executable script for the Endpoint module
* telemetry_view.php - primary frontend script for the Dashboard module
* includes/, lib/ - external libraries
* mock_storage/ - sample input files
* gossips/ - draft of a dashboard for ui-gossip data topic
