File map:
* config*-dist.inc.php - remove -dist, fill with local credentials and settings.
* topic-*.inc.php - data topic definitions.
* classes/
** Telemetry.class.php - contains main Telemetry class, derivable
** TelemetryScrapeSVs.class.php - class for Scrape module, SV source
** TelemetryScrapePackagerLog.class.php - class for Scrape module, Packager log source
** TelemetryCrunch.class.php - class for Crunch module, ran after Scraping
** TelemetryEndpoint.class.php - class for Endpoint module, queried via HTTP
** TelemetryView.class.php - class for View module, rendering graphs and charts
* telemetry_scrape.php - locally executable script for the Scraper module (pass 1)
* telemetry_crunch.php - locally executable script for the Cruncher module (pass 2)
* telemetry_endpoint.php - externally executable script for the Endpoint module
* telemetry_view.php - primary frontend script for the Dashboard module
* includes/, lib/ - external libraries
* mock_storage/ - sample input files
* gossips/ - draft of a dashboard for ui-gossip data topic
