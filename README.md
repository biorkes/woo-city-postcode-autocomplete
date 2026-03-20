# City & Postcode Autocomplete for WooCommerce

City and postcode autocomplete for WooCommerce and FunnelKit checkout, powered by [GeoNames](https://www.geonames.org/) postal code data. Supports multiple countries with admin upload and per-country dataset management.

## Features

- AJAX autocomplete for the city/locality field at checkout
- Automatic postcode autofill on city selection
- Works with **WooCommerce standard checkout** and **FunnelKit** checkout layouts
- Supports any country available on GeoNames — import as many as you need
- Admin UI for uploading, re-importing, and deleting country datasets
- Handles both `.txt` and `.zip` uploads (ZIP is extracted automatically)
- Memory-efficient line-by-line parsing — safe for large files (DE, GB, US…)
- State/province matching works across both GeoNames admin1 and admin2 levels (handles countries like Spain where WooCommerce province codes map to admin2)
- No external dependencies — core WordPress + WooCommerce only

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WooCommerce 6.0+
- selectWoo or select2 (both ship with WooCommerce)

## Installation

1. Download or clone this repository.
2. Copy the `woo-city-postcode-autocomplete` folder to `wp-content/plugins/`.
3. Activate the plugin from **Plugins → Installed Plugins**.
4. Go to **Tools → Checkout Localities** and import a GeoNames file for your country.

## Usage

### Importing a country dataset

1. Go to **Tools → Checkout Localities** in the WordPress admin.
2. Download the postal code file for your country from [https://download.geonames.org/export/zip/](https://download.geonames.org/export/zip/) — either the `.zip` or the `.txt` inside it.
3. Upload the file using the form on the admin page.
4. The plugin will parse the file and import the data. The previous dataset for that country (if any) is replaced safely — old data is never lost on a parse failure.

You can import multiple countries. Each country gets its own dataset entry in the admin table.

### How it works at checkout

- When a customer reaches the checkout page, the plain city text input is replaced with an AJAX-powered autocomplete select (using selectWoo/select2).
- As the customer types (minimum 2 characters), matching localities are fetched from the local database filtered by the selected country and state/province.
- Selecting a locality automatically fills in the city name and postcode.
- Changing the country or state resets the city selection.

## GeoNames file format

GeoNames postal files are tab-delimited text files with 12 columns (no header row):

| Column | Field |
|--------|-------|
| 0 | country_code |
| 1 | postal_code |
| 2 | place_name |
| 3 | admin1_name (region/state) |
| 4 | admin1_code |
| 5 | admin2_name (province/county) |
| 6 | admin2_code |
| 7 | admin3_name |
| 8 | admin3_code |
| 9 | latitude |
| 10 | longitude |
| 11 | accuracy |

Download files from: [https://download.geonames.org/export/zip/](https://download.geonames.org/export/zip/)

## Notes

- **Large files**: Some country files (GB, DE, US) are very large. If your server's `upload_max_filesize` is below 5 MB, the plugin will show a warning. Ask your host to increase it in `php.ini` if needed.
- **State matching**: The plugin matches WooCommerce state codes against both GeoNames `admin1_code` and `admin2_code`, so it works correctly for countries where WooCommerce uses province-level codes (e.g. Spain).
- **Uninstalling**: Deleting the plugin from the WordPress admin will remove all database tables and stored data files.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
