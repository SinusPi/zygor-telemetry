# Copilot Instructions for Zygor Telemetry

## Overview
This repository is designed to handle telemetry data for Zygor products. It includes components for data collection, processing, and visualization. The architecture is PHP-based, with a focus on modularity and separation of concerns.

## Key Components

### 1. Telemetry Data Flow
- **Collection**: Data is collected via `telemetry_scrape.php`, and - via `TelemetryScrape` and subclasses - stored in a MySQL database.
  - Each subclass of `TelemetryScrape` is responsible for scraping a specific source of telemetry data.
    - `TelemetryScrapeSVs`: Scrapes data from SVs (Saved Variables files, coming from users' game clients).
    - `TelemetryScrapePackagerLog`: Scrapes data from Packager logs.
- **Processing**: Data is crunched and analyzed using `telemetry_crunch.php`, via `TelemetryCrunch` class.
- **Querying**: Processed data can be queried through `telemetry_endpoint.php`, via `TelemetryEndpoint` class.
- **Visualization**: Processed data is displayed through `telemetry_view.php` and `TelemetryView` class.

### 2. Classes Directory
- Contains core classes for handling telemetry logic.
- Examples:
  - `Telemetry.class.php`: Base class for telemetry operations.
  - `TelemetryCrunch.class.php`: Handles data crunching.
  - `TelemetryView.class.php`: Manages data visualization.

### 3. Mock Data
- Located in `mock_logs/` and `mock_storage/`.
- Useful for testing without live data.

### 4. Configuration Files
- `config.inc.php` and its variants control environment-specific settings.
- `config-dist.inc.php` serves as a template for new configurations.

## Developer Workflows

### Testing
- Use mock data from `mock_logs/` and `mock_storage/`.
- Ensure changes do not break the data flow across collection, processing, and visualization.

### Debugging
- Check `php_errors.log` for runtime errors.
- Use `VerboseException` class for detailed error reporting.

### Adding New Features
- Follow the existing class structure in `classes/`.
- Update relevant configuration files if needed.

## Project-Specific Conventions
- **File Naming**: Use `CamelCase` for class files and `snake_case` for scripts.
- **Error Handling**: Use `VerboseException` for consistent error reporting.
- **Data Storage**: Mock data is stored in `mock_storage/` for testing purposes.
- **Codebase**: Ensure PHP 5.6 compatibility, as the project may be running on older environments.

## Integration Points
- **External Libraries**: Uses `jquery-ui` for frontend components.
- **Cross-Component Communication**: Classes in `classes/` interact via well-defined interfaces.

## Access Points

- `index.php` and `telemetry_view.php` are designed to be accessed from the web.
- `telemetry_endpoint.php` serves as the API endpoint for querying processed telemetry data.
- `telemetry_scrape.php` and `telemetry_crunch.php` are CLI scripts for data collection and processing, respectively.

## Examples

### Adding a New Telemetry Processor
1. Create a new class in `classes/` (e.g., `TelemetryNewProcessor.class.php`).
2. Implement the required methods based on `Telemetry.class.php`.
3. Update `telemetry_crunch.php` to include the new processor.

### Debugging a View Issue
1. Check `telemetry_view.php` for errors.
2. Verify data integrity using `TelemetryCrunch`.
3. Use mock data to replicate the issue.

---

Feel free to update this document as the project evolves.