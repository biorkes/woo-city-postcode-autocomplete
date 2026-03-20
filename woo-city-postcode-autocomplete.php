<?php
/**
 * Plugin Name:       City & Postcode Autocomplete for WooCommerce
 * Plugin URI:        https://github.com/biorkes/woo-city-postcode-autocomplete
 * Description:       City and postcode autocomplete for WooCommerce and FunnelKit checkout, powered by GeoNames postal code data. Supports multiple countries with admin upload and per-country dataset management.
 * Version:           1.0.0
 * Author:            biorkes
 * Author URI:        https://github.com/biorkes
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-city-postcode-autocomplete
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'lib/plugin-update-checker/plugin-update-checker.php';
$geo_cl_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/biorkes/woo-city-postcode-autocomplete/',
    __FILE__,
    'woo-city-postcode-autocomplete'
);
$geo_cl_update_checker->setBranch( 'main' );
$geo_cl_update_checker->getVcsApi()->enableReleaseAssets(); 


// ============================================================================
// Main plugin class
// ============================================================================

final class GEO_Checkout_Localities {

	const VERSION     = '1.0.0';
	const SLUG        = 'woo-city-postcode-autocomplete';
	const AJAX_ACTION = 'geo_cl_search_localities';
	const NONCE_AJAX  = 'geo_cl_search_localities';
	const NONCE_ADMIN = 'geo_cl_admin';

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
		register_uninstall_hook( __FILE__, [ __CLASS__, 'uninstall' ] );

		// WooCommerce dependency check.
		add_action( 'admin_notices', [ __CLASS__, 'maybe_notice_wc_missing' ] );

		add_action( 'admin_menu',                       [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_geo_cl_upload',         [ __CLASS__, 'handle_upload' ] );
		add_action( 'admin_post_geo_cl_reimport',       [ __CLASS__, 'handle_reimport' ] );
		add_action( 'admin_post_geo_cl_delete_dataset', [ __CLASS__, 'handle_delete_dataset' ] );

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_checkout_assets' ] );
		add_action( 'wp_footer',          [ __CLASS__, 'print_checkout_js' ], 99 );

		add_action( 'wp_ajax_'        . self::AJAX_ACTION, [ __CLASS__, 'ajax_search' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_search' ] );
	}

	// -------------------------------------------------------------------------
	// WooCommerce dependency notice
	// -------------------------------------------------------------------------

	public static function maybe_notice_wc_missing() {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. '<strong>City &amp; Postcode Autocomplete for WooCommerce</strong> requires WooCommerce to be installed and active.'
			. '</p></div>';
	}

	// -------------------------------------------------------------------------
	// Activation / Uninstall
	// -------------------------------------------------------------------------

	public static function activate() {
		self::create_tables();
	}

	/**
	 * Called by WordPress when the plugin is deleted from the admin UI.
	 * Drops both DB tables and removes stored files.
	 */
	public static function uninstall() {
		global $wpdb;

		// Remove stored data files.
		$upload_dir = wp_upload_dir();
		$data_dir   = trailingslashit( $upload_dir['basedir'] ) . 'geo-cl-localities/';
		if ( is_dir( $data_dir ) ) {
			$files = glob( $data_dir . '*' );
			if ( $files ) {
				foreach ( $files as $f ) {
					@unlink( $f ); // phpcs:ignore
				}
			}
			@rmdir( $data_dir ); // phpcs:ignore
		}

		// Drop tables.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_localities() ); // phpcs:ignore
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_datasets() );   // phpcs:ignore
	}

	// -------------------------------------------------------------------------
	// Database – table names
	// -------------------------------------------------------------------------

	private static function table_localities() {
		global $wpdb;
		return $wpdb->prefix . 'geo_cl_localities';
	}

	private static function table_datasets() {
		global $wpdb;
		return $wpdb->prefix . 'geo_cl_datasets';
	}

	// -------------------------------------------------------------------------
	// Database – schema
	// -------------------------------------------------------------------------

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$tl      = self::table_localities();
		$td      = self::table_datasets();

		$sql_datasets = "CREATE TABLE {$td} (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			country_code      VARCHAR(5)   NOT NULL,
			source_filename   VARCHAR(255) NOT NULL,
			original_filename VARCHAR(255) NOT NULL DEFAULT '',
			rows_imported     INT UNSIGNED NOT NULL DEFAULT 0,
			uploaded_at       DATETIME     NOT NULL,
			imported_at       DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY country_code (country_code)
		) {$charset};";

		$sql_localities = "CREATE TABLE {$tl} (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			country_code     VARCHAR(5)   NOT NULL,
			postcode         VARCHAR(20)  NOT NULL DEFAULT '',
			place_name       VARCHAR(191) NOT NULL,
			place_name_norm  VARCHAR(191) NOT NULL,
			admin1_name      VARCHAR(191) NOT NULL DEFAULT '',
			admin1_name_norm VARCHAR(191) NOT NULL DEFAULT '',
			admin1_code      VARCHAR(20)  NOT NULL DEFAULT '',
			admin2_name      VARCHAR(191) NOT NULL DEFAULT '',
			admin2_name_norm VARCHAR(191) NOT NULL DEFAULT '',
			admin2_code      VARCHAR(20)  NOT NULL DEFAULT '',
			admin3_name      VARCHAR(191) NOT NULL DEFAULT '',
			admin3_name_norm VARCHAR(191) NOT NULL DEFAULT '',
			admin3_code      VARCHAR(20)  NOT NULL DEFAULT '',
			latitude         VARCHAR(20)  NOT NULL DEFAULT '',
			longitude        VARCHAR(20)  NOT NULL DEFAULT '',
			accuracy         TINYINT UNSIGNED NOT NULL DEFAULT 0,
			source_filename  VARCHAR(255) NOT NULL DEFAULT '',
			dataset_country  VARCHAR(5)   NOT NULL DEFAULT '',
			created_at       DATETIME     NOT NULL,
			updated_at       DATETIME     NOT NULL,
			PRIMARY KEY  (id),
			KEY country_code     (country_code),
			KEY place_name_norm  (country_code, place_name_norm(100)),
			KEY admin1_code      (country_code, admin1_code),
			KEY admin1_name_norm (country_code, admin1_name_norm(100)),
			KEY admin2_code      (country_code, admin2_code),
			KEY admin2_name_norm (country_code, admin2_name_norm(100)),
			KEY postcode         (country_code, postcode)
		) {$charset};";

		dbDelta( $sql_datasets );
		dbDelta( $sql_localities );
	}

	// -------------------------------------------------------------------------
	// Admin menu
	// -------------------------------------------------------------------------

	public static function admin_menu() {
		add_management_page(
			'City & Postcode Autocomplete',
			'Checkout Localities',
			'manage_options',
			'geo-cl-localities',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Admin page render
	// -------------------------------------------------------------------------

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$td       = self::table_datasets();
		$datasets = $wpdb->get_results( "SELECT * FROM {$td} ORDER BY uploaded_at DESC", ARRAY_A ); // phpcs:ignore

		// Flash notices from redirect.
		if ( ! empty( $_GET['geo_cl_notice'] ) ) { // phpcs:ignore
			$type = sanitize_key( $_GET['geo_cl_notice'] ); // phpcs:ignore
			switch ( $type ) {
				case 'import_ok':
					printf(
						'<div class="notice notice-success is-dismissible"><p>Import successful: <strong>%d</strong> rows imported for country <strong>%s</strong>.</p></div>',
						(int) ( $_GET['rows'] ?? 0 ), // phpcs:ignore
						esc_html( strtoupper( sanitize_text_field( wp_unslash( $_GET['cc'] ?? '' ) ) ) ) // phpcs:ignore
					);
					break;
				case 'reimport_ok':
					printf(
						'<div class="notice notice-success is-dismissible"><p>Re-import successful: <strong>%d</strong> rows for country <strong>%s</strong>.</p></div>',
						(int) ( $_GET['rows'] ?? 0 ), // phpcs:ignore
						esc_html( strtoupper( sanitize_text_field( wp_unslash( $_GET['cc'] ?? '' ) ) ) ) // phpcs:ignore
					);
					break;
				case 'delete_ok':
					echo '<div class="notice notice-success is-dismissible"><p>Dataset deleted successfully.</p></div>';
					break;
				case 'error':
					printf(
						'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
						esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['msg'] ?? 'Unknown error.' ) ) ) ) // phpcs:ignore
					);
					break;
			}
		}

		// Upload size warning.
		$max_upload = wp_max_upload_size();
		?>
		<div class="wrap">
			<h1>City &amp; Postcode Autocomplete</h1>

			<h2>Import a GeoNames Postal Code File</h2>
			<p>
				Upload a <code>.txt</code> or <code>.zip</code> file from GeoNames.
				The file is parsed first, then safely replaces any existing data for that country — existing data is never lost on parse failure.
			</p>

			<?php if ( $max_upload < 5 * MB_IN_BYTES ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong>Note:</strong> Your server's maximum upload size is
						<strong><?php echo esc_html( size_format( $max_upload ) ); ?></strong>.
						Some GeoNames files (e.g. DE, GB, US) can exceed this limit.
						Ask your host to increase <code>upload_max_filesize</code> and <code>post_max_size</code> in <code>php.ini</code> if needed.
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="geo_cl_upload">
				<?php wp_nonce_field( self::NONCE_ADMIN, '_geo_cl_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="geo_cl_file">File (.txt or .zip)</label></th>
						<td>
							<input type="file" name="geo_cl_file" id="geo_cl_file" accept=".txt,.zip" required>
							<p class="description">
								Select the GeoNames postal code file for the country you want to import
								(e.g. <code>ES.txt</code> or <code>ES.zip</code>).
								Maximum upload size: <strong><?php echo esc_html( size_format( $max_upload ) ); ?></strong>.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Upload & Import' ); ?>
			</form>

			<hr>

			<h2>Imported Datasets</h2>
			<?php if ( empty( $datasets ) ) : ?>
				<p>No datasets imported yet.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Country</th>
							<th>Filename</th>
							<th>Rows Imported</th>
							<th>Uploaded At</th>
							<th>Last Imported At</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $datasets as $ds ) : ?>
							<tr>
								<td><strong><?php echo esc_html( strtoupper( $ds['country_code'] ) ); ?></strong></td>
								<td><?php echo esc_html( $ds['original_filename'] ); ?></td>
								<td><?php echo esc_html( number_format( (int) $ds['rows_imported'] ) ); ?></td>
								<td><?php echo esc_html( $ds['uploaded_at'] ); ?></td>
								<td><?php echo esc_html( $ds['imported_at'] ); ?></td>
								<td>
									<?php
									$reimport_url = wp_nonce_url(
										add_query_arg( [
											'action'     => 'geo_cl_reimport',
											'dataset_id' => (int) $ds['id'],
										], admin_url( 'admin-post.php' ) ),
										self::NONCE_ADMIN,
										'_geo_cl_nonce'
									);
									$delete_url = wp_nonce_url(
										add_query_arg( [
											'action'     => 'geo_cl_delete_dataset',
											'dataset_id' => (int) $ds['id'],
										], admin_url( 'admin-post.php' ) ),
										self::NONCE_ADMIN,
										'_geo_cl_nonce'
									);
									?>
									<a class="button button-secondary"
									   href="<?php echo esc_url( $reimport_url ); ?>">Re-import</a>
									&nbsp;
									<a class="button"
									   href="<?php echo esc_url( $delete_url ); ?>"
									   onclick="return confirm('Delete all data for <?php echo esc_js( strtoupper( $ds['country_code'] ) ); ?>? This cannot be undone.');"
									   style="color:#a00;">Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr>

			<h2>About GeoNames Postal Code Files</h2>
			<p>
				GeoNames provides free, regularly-updated postal code files for most countries.
				Each file is a tab-delimited text file with 12 columns:
				<code>country_code, postal_code, place_name, admin1_name, admin1_code,
				admin2_name, admin2_code, admin3_name, admin3_code, latitude, longitude, accuracy</code>.
			</p>
			<p>
				Download the file for your country from:<br>
				<a href="https://download.geonames.org/export/zip/" target="_blank" rel="noopener noreferrer">
					https://download.geonames.org/export/zip/
				</a>
			</p>
			<p>
				Download the ZIP for your target country (e.g. <code>ES.zip</code>), upload it here,
				and the plugin will extract and import it automatically.
				You can import as many countries as you need.
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Admin POST handlers
	// -------------------------------------------------------------------------

	public static function handle_upload() {
		self::check_admin_access();

		// Check for PHP upload errors.
		$upload_error = $_FILES['geo_cl_file']['error'] ?? UPLOAD_ERR_NO_FILE; // phpcs:ignore
		if ( $upload_error !== UPLOAD_ERR_OK ) {
			self::admin_redirect_error( self::upload_error_message( $upload_error ) );
		}

		$tmp_path  = $_FILES['geo_cl_file']['tmp_name']; // phpcs:ignore
		$orig_name = sanitize_file_name( wp_unslash( $_FILES['geo_cl_file']['name'] ) ); // phpcs:ignore

		if ( empty( $tmp_path ) || ! is_uploaded_file( $tmp_path ) ) {
			self::admin_redirect_error( 'Invalid upload. Please try again.' );
		}

		$result = self::import_file( $tmp_path, $orig_name );

		if ( is_wp_error( $result ) ) {
			self::admin_redirect_error( $result->get_error_message() );
		}

		wp_safe_redirect( add_query_arg( [
			'page'          => 'geo-cl-localities',
			'geo_cl_notice' => 'import_ok',
			'rows'          => $result['rows_imported'],
			'cc'            => $result['country_code'],
		], admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function handle_reimport() {
		self::check_admin_access();

		$dataset_id = isset( $_GET['dataset_id'] ) ? (int) $_GET['dataset_id'] : 0; // phpcs:ignore
		if ( ! $dataset_id ) {
			self::admin_redirect_error( 'Invalid dataset ID.' );
		}

		global $wpdb;
		$td      = self::table_datasets();
		$dataset = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$td} WHERE id = %d", $dataset_id ), ARRAY_A ); // phpcs:ignore

		if ( ! $dataset ) {
			self::admin_redirect_error( 'Dataset not found.' );
		}

		$upload_dir = wp_upload_dir();
		$file_path  = trailingslashit( $upload_dir['basedir'] ) . 'geo-cl-localities/' . $dataset['source_filename'];

		if ( ! file_exists( $file_path ) ) {
			self::admin_redirect_error( 'Stored file not found on disk. Please re-upload the original file.' );
		}

		$result = self::run_import_from_txt( $file_path, $dataset['original_filename'], $dataset['country_code'], $dataset_id );

		if ( is_wp_error( $result ) ) {
			self::admin_redirect_error( $result->get_error_message() );
		}

		wp_safe_redirect( add_query_arg( [
			'page'          => 'geo-cl-localities',
			'geo_cl_notice' => 'reimport_ok',
			'rows'          => $result['rows_imported'],
			'cc'            => $result['country_code'],
		], admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function handle_delete_dataset() {
		self::check_admin_access();

		$dataset_id = isset( $_GET['dataset_id'] ) ? (int) $_GET['dataset_id'] : 0; // phpcs:ignore
		if ( ! $dataset_id ) {
			self::admin_redirect_error( 'Invalid dataset ID.' );
		}

		global $wpdb;
		$td      = self::table_datasets();
		$tl      = self::table_localities();
		$dataset = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$td} WHERE id = %d", $dataset_id ), ARRAY_A ); // phpcs:ignore

		if ( ! $dataset ) {
			self::admin_redirect_error( 'Dataset not found.' );
		}

		// Remove stored file.
		$upload_dir = wp_upload_dir();
		$file_path  = trailingslashit( $upload_dir['basedir'] ) . 'geo-cl-localities/' . $dataset['source_filename'];
		if ( file_exists( $file_path ) ) {
			@unlink( $file_path ); // phpcs:ignore
		}

		$wpdb->delete( $tl, [ 'country_code' => $dataset['country_code'] ], [ '%s' ] );
		$wpdb->delete( $td, [ 'id' => $dataset_id ], [ '%d' ] );

		wp_safe_redirect( add_query_arg( [
			'page'          => 'geo-cl-localities',
			'geo_cl_notice' => 'delete_ok',
		], admin_url( 'tools.php' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Import pipeline – entry point
	// -------------------------------------------------------------------------

	/**
	 * Main import entry point. Accepts a tmp file path + original filename.
	 * Handles ZIP extraction, parsing, storing, and DB persistence.
	 *
	 * @param  string $tmp_path   Path to the uploaded tmp file.
	 * @param  string $orig_name  Original filename (sanitized).
	 * @return array|WP_Error     ['rows_imported' => int, 'country_code' => string]
	 */
	private static function import_file( $tmp_path, $orig_name ) {
		// Extract ZIP if needed; returns path to a plain .txt file.
		$txt_path = self::maybe_extract_zip( $tmp_path, $orig_name );
		if ( is_wp_error( $txt_path ) ) {
			return $txt_path;
		}

		// Parse — line by line to keep memory low.
		$parsed = self::parse_geonames_txt( $txt_path );

		if ( $txt_path !== $tmp_path ) {
			@unlink( $txt_path ); // phpcs:ignore — temp extract file
		}

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$rows         = $parsed['rows'];
		$country_code = $parsed['country_code'];

		if ( empty( $rows ) ) {
			return new WP_Error( 'empty_rows', 'No valid rows were found in the file.' );
		}

		// Persist the .txt for future re-imports.
		$stored_name = self::store_txt_file( $tmp_path, $orig_name, $country_code );

		// Upsert dataset record.
		global $wpdb;
		$td  = self::table_datasets();
		$now = current_time( 'mysql' );

		$existing = $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare( "SELECT id FROM {$td} WHERE country_code = %s", $country_code ), // phpcs:ignore
			ARRAY_A
		);

		if ( $existing ) {
			$dataset_id = (int) $existing['id'];
		} else {
			$wpdb->insert( $td, [
				'country_code'      => $country_code,
				'source_filename'   => $stored_name,
				'original_filename' => $orig_name,
				'rows_imported'     => 0,
				'uploaded_at'       => $now,
				'imported_at'       => $now,
			], [ '%s', '%s', '%s', '%d', '%s', '%s' ] );
			$dataset_id = (int) $wpdb->insert_id;
		}

		$result = self::persist_rows( $rows, $country_code, $stored_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update dataset meta after successful import.
		$wpdb->update(
			$td,
			[
				'source_filename'   => $stored_name,
				'original_filename' => $orig_name,
				'rows_imported'     => $result['rows_imported'],
				'uploaded_at'       => $now,
				'imported_at'       => $now,
			],
			[ 'id' => $dataset_id ],
			[ '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		return $result;
	}

	/**
	 * Re-import from a previously stored .txt file on disk.
	 */
	private static function run_import_from_txt( $file_path, $orig_name, $country_code, $dataset_id ) {
		$parsed = self::parse_geonames_txt( $file_path );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$rows = $parsed['rows'];
		if ( empty( $rows ) ) {
			return new WP_Error( 'empty_rows', 'No valid rows found in the stored file.' );
		}

		$stored_name = basename( $file_path );
		$result      = self::persist_rows( $rows, $country_code, $stored_name );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		global $wpdb;
		$wpdb->update(
			self::table_datasets(),
			[ 'rows_imported' => $result['rows_imported'], 'imported_at' => current_time( 'mysql' ) ],
			[ 'id' => $dataset_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		return $result;
	}

	/**
	 * Delete existing rows for a country then insert the new ones row-by-row.
	 * Truncation happens AFTER a successful parse so old data is never lost on error.
	 *
	 * @return array|WP_Error
	 */
	private static function persist_rows( array $rows, $country_code, $stored_name ) {
		global $wpdb;

		// Ensure tables exist — safety net for missed activation hook.
		self::create_tables();

		$tl = self::table_localities();

		// Verify table was actually created.
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$tl}'" ); // phpcs:ignore
		if ( $exists !== $tl ) {
			return new WP_Error(
				'table_missing',
				"Table {$tl} could not be created. Ensure your database user has CREATE TABLE privilege."
			);
		}

		// Extend execution time for large files (GB, US can have 500k+ rows).
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}

		$now = current_time( 'mysql' );

		// Safe delete: only THIS country, only after parse succeeded.
		$wpdb->delete( $tl, [ 'country_code' => $country_code ], [ '%s' ] );

		$inserted   = 0;
		$seen_dedup = [];

		$formats = [ '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s' ];

		foreach ( $rows as $row ) {
			// Deduplicate on country + postcode + normalised place name.
			$key = $row['country_code'] . '|' . $row['postcode'] . '|' . $row['place_name_norm'];
			if ( isset( $seen_dedup[ $key ] ) ) {
				continue;
			}
			$seen_dedup[ $key ] = true;

			$res = $wpdb->insert(
				$tl,
				[
					'country_code'     => (string) $row['country_code'],
					'postcode'         => (string) $row['postcode'],
					'place_name'       => (string) $row['place_name'],
					'place_name_norm'  => (string) $row['place_name_norm'],
					'admin1_name'      => (string) $row['admin1_name'],
					'admin1_name_norm' => (string) $row['admin1_name_norm'],
					'admin1_code'      => (string) $row['admin1_code'],
					'admin2_name'      => (string) $row['admin2_name'],
					'admin2_name_norm' => (string) $row['admin2_name_norm'],
					'admin2_code'      => (string) $row['admin2_code'],
					'admin3_name'      => (string) $row['admin3_name'],
					'admin3_name_norm' => (string) $row['admin3_name_norm'],
					'admin3_code'      => (string) $row['admin3_code'],
					'latitude'         => (string) $row['latitude'],
					'longitude'        => (string) $row['longitude'],
					'accuracy'         => (int)    $row['accuracy'],
					'source_filename'  => (string) $stored_name,
					'dataset_country'  => (string) $country_code,
					'created_at'       => $now,
					'updated_at'       => $now,
				],
				$formats
			);

			if ( false === $res ) {
				return new WP_Error(
					'db_insert_failed',
					sprintf(
						'DB insert failed on row %d (%s / %s): %s',
						$inserted + 1,
						esc_html( $row['country_code'] ),
						esc_html( $row['place_name'] ),
						$wpdb->last_error ?: '(no error detail — check DB user privileges and table structure)'
					)
				);
			}

			$inserted++;
		}

		return [
			'rows_imported' => $inserted,
			'country_code'  => $country_code,
		];
	}

	// -------------------------------------------------------------------------
	// File helpers
	// -------------------------------------------------------------------------

	/**
	 * If the file is a ZIP archive, extract the first .txt inside it to a temp
	 * file and return that path. Otherwise return the original path unchanged.
	 *
	 * @return string|WP_Error
	 */
	private static function maybe_extract_zip( $path, $orig_name ) {
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			return new WP_Error( 'file_open_failed', 'Could not open the uploaded file.' );
		}
		$magic = fread( $fh, 2 );
		fclose( $fh );

		if ( strncmp( $magic, 'PK', 2 ) !== 0 ) {
			return $path; // Not a ZIP.
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', 'PHP ZipArchive extension is required to extract ZIP files. Please upload the .txt file directly instead.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'zip_open_failed', 'Could not open the file as a ZIP archive.' );
		}

		$txt_index = -1;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( substr( strtolower( $name ), -4 ) === '.txt'
				&& strpos( $name, '__MACOSX' ) === false
				&& strpos( basename( $name ), '.' ) !== 0
			) {
				$txt_index = $i;
				break;
			}
		}

		if ( -1 === $txt_index ) {
			$zip->close();
			return new WP_Error( 'no_txt_in_zip', 'No .txt file found inside the ZIP archive.' );
		}

		$content = $zip->getFromIndex( $txt_index );
		$zip->close();

		if ( false === $content || '' === trim( $content ) ) {
			return new WP_Error( 'empty_txt', 'The extracted .txt file inside the ZIP is empty.' );
		}

		$tmp = wp_tempnam( 'geo_cl_' );
		file_put_contents( $tmp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $tmp;
	}

	/**
	 * Copy the source txt to a permanent location for later re-import.
	 * The source can be either the original tmp path or a ZIP-extracted tmp.
	 *
	 * @return string Stored basename filename.
	 */
	private static function store_txt_file( $source_path, $orig_name, $country_code ) {
		$upload_dir = wp_upload_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'geo-cl-localities/';

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
			// Prevent direct HTTP access to the data directory.
			file_put_contents( $target_dir . '.htaccess', 'Deny from all' . PHP_EOL ); // phpcs:ignore
			file_put_contents( $target_dir . 'index.php', '<?php // silence' . PHP_EOL ); // phpcs:ignore
		}

		$filename = strtoupper( $country_code ) . '_' . gmdate( 'Ymd_His' ) . '.txt';
		copy( $source_path, $target_dir . $filename );

		return $filename;
	}

	// -------------------------------------------------------------------------
	// GeoNames parser — line-by-line (memory efficient)
	// -------------------------------------------------------------------------

	/**
	 * Parse a GeoNames postal code .txt file.
	 *
	 * GeoNames format — 12 tab-separated fields, no header row:
	 *   [0]  country_code   [1]  postal_code  [2]  place_name
	 *   [3]  admin1_name    [4]  admin1_code
	 *   [5]  admin2_name    [6]  admin2_code
	 *   [7]  admin3_name    [8]  admin3_code
	 *   [9]  latitude       [10] longitude    [11] accuracy
	 *
	 * @param  string $file_path
	 * @return array|WP_Error  ['rows' => [...], 'country_code' => string]
	 */
	private static function parse_geonames_txt( $file_path ) {
		$fh = @fopen( $file_path, 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'file_open_failed', 'Could not open file for reading.' );
		}

		$rows            = [];
		$detected        = '';
		$line_number     = 0;
		$validated       = false;

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );

			// Skip empty lines and comments.
			if ( '' === trim( $line ) || '#' === $line[0] ) {
				continue;
			}

			// Strip UTF-8 BOM on first data line.
			if ( 0 === $line_number ) {
				$line = preg_replace( '/^\xEF\xBB\xBF/', '', $line );
			}

			$line_number++;

			$f = explode( "\t", $line );

			// Validate format on first data line.
			if ( ! $validated ) {
				if ( count( $f ) < 11 ) {
					fclose( $fh );
					return new WP_Error(
						'invalid_format',
						sprintf(
							'File does not appear to be a GeoNames postal code export (expected ≥11 tab-separated columns, got %d on line 1).',
							count( $f )
						)
					);
				}
				$detected = strtoupper( trim( $f[0] ) );
				if ( ! preg_match( '/^[A-Z]{2}$/', $detected ) ) {
					fclose( $fh );
					return new WP_Error( 'invalid_country_code', 'Could not detect a valid ISO-2 country code in column 1.' );
				}
				$validated = true;
			}

			$country_code = strtoupper( trim( $f[0] ) );
			$place_name   = trim( $f[2] );

			if ( '' === $country_code || '' === $place_name ) {
				continue;
			}

			$rows[] = [
				'country_code'     => $country_code,
				'postcode'         => self::normalize_postcode( $f[1] ),
				'place_name'       => $place_name,
				'place_name_norm'  => self::normalize( $place_name ),
				'admin1_name'      => trim( $f[3] ?? '' ),
				'admin1_name_norm' => self::normalize( $f[3] ?? '' ),
				'admin1_code'      => strtoupper( trim( $f[4] ?? '' ) ),
				'admin2_name'      => trim( $f[5] ?? '' ),
				'admin2_name_norm' => self::normalize( $f[5] ?? '' ),
				'admin2_code'      => trim( $f[6] ?? '' ),
				'admin3_name'      => trim( $f[7] ?? '' ),
				'admin3_name_norm' => self::normalize( $f[7] ?? '' ),
				'admin3_code'      => trim( $f[8] ?? '' ),
				'latitude'         => trim( $f[9] ?? '' ),
				'longitude'        => trim( $f[10] ?? '' ),
				'accuracy'         => isset( $f[11] ) ? (int) $f[11] : 0,
			];
		}

		fclose( $fh );

		if ( ! $validated ) {
			return new WP_Error( 'no_data_lines', 'The file contains no readable data lines.' );
		}

		if ( empty( $rows ) ) {
			return new WP_Error( 'no_valid_rows', 'Parser found no valid rows in the file.' );
		}

		return [
			'rows'         => $rows,
			'country_code' => $detected,
		];
	}

	// -------------------------------------------------------------------------
	// AJAX: locality search
	// -------------------------------------------------------------------------

	public static function ajax_search() {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );

		$term        = isset( $_GET['term'] )        ? sanitize_text_field( wp_unslash( $_GET['term'] ) )        : ''; // phpcs:ignore
		$country     = isset( $_GET['country'] )     ? strtoupper( sanitize_text_field( wp_unslash( $_GET['country'] ) ) ) : ''; // phpcs:ignore
		$state       = isset( $_GET['state'] )       ? sanitize_text_field( wp_unslash( $_GET['state'] ) )       : ''; // phpcs:ignore
		$state_label = isset( $_GET['state_label'] ) ? sanitize_text_field( wp_unslash( $_GET['state_label'] ) ) : ''; // phpcs:ignore

		if ( mb_strlen( $term, 'UTF-8' ) < 2 || '' === $country ) {
			wp_send_json_success( [ 'results' => [] ] );
			return;
		}

		$term_norm       = self::normalize( $term );
		$state_norm      = self::normalize( $state_label );
		$state_code_norm = strtoupper( trim( $state ) );

		global $wpdb;
		$tl    = self::table_localities();
		$where = [ 'country_code = %s' ];
		$args  = [ $country ];

		/*
		 * State / province filter.
		 *
		 * WooCommerce state codes don't always map to GeoNames admin1_code.
		 * Example: Spain — WC uses province codes (V, A, CS...) stored in
		 * GeoNames admin2_code; admin1_code holds the region (VC, CT...).
		 *
		 * We match the WC state against ALL four columns so search works
		 * regardless of whether WC states map to admin1 or admin2.
		 */
		if ( '' !== $state_code_norm || '' !== $state_norm ) {
			$sc = [];

			if ( '' !== $state_code_norm ) {
				$sc[]   = 'admin1_code = %s';
				$args[] = $state_code_norm;
				$sc[]   = 'admin2_code = %s';
				$args[] = $state_code_norm;
			}

			if ( '' !== $state_norm ) {
				$sc[]   = 'admin1_name_norm = %s';
				$args[] = $state_norm;
				$sc[]   = 'admin2_name_norm = %s';
				$args[] = $state_norm;
			}

			$where[] = '(' . implode( ' OR ', $sc ) . ')';
		}

		// Text search across place name, municipality, and postcode.
		$where[] = '(place_name_norm LIKE %s OR admin2_name_norm LIKE %s OR postcode LIKE %s)';
		$args[]  = $wpdb->esc_like( $term_norm ) . '%';
		$args[]  = '%' . $wpdb->esc_like( $term_norm ) . '%';
		$args[]  = $wpdb->esc_like( $term ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = 'SELECT id, place_name, postcode, country_code, admin1_name, admin2_name, accuracy
			FROM ' . $tl . '
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY accuracy DESC, place_name_norm ASC
			LIMIT 20';

		$rows    = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore
		$results = [];

		foreach ( $rows as $row ) {
			$locality = $row['place_name'];
			$admin2   = $row['admin2_name'];
			$pc       = $row['postcode'];

			$display = $locality;
			if ( '' !== $admin2 && self::normalize( $admin2 ) !== self::normalize( $locality ) ) {
				$display .= ' (' . $admin2 . ')';
			}
			if ( '' !== $pc ) {
				$display .= ' — ' . $pc;
			}

			$results[] = [
				'id'            => (string) $row['id'],
				'text'          => $display,
				'locality_name' => $locality,
				'postcode'      => $pc,
				'country_code'  => $row['country_code'],
				'admin1_name'   => $row['admin1_name'],
				'admin2_name'   => $admin2,
			];
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	// -------------------------------------------------------------------------
	// Front-end: enqueue
	// -------------------------------------------------------------------------

	public static function enqueue_checkout_assets() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		// Prefer selectWoo (bundled with WooCommerce); fall back to select2.
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
			wp_enqueue_style( 'select2' );
		} elseif ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_script( 'select2' );
			wp_enqueue_style( 'select2' );
		}
		// If neither is available, JS will gracefully exit via getSelectLib() —
		// but we still register and localize so GeoCL is always defined on the page.

		wp_register_script( 'geo-cl-inline', '', [ 'jquery' ], self::VERSION, true );
		wp_enqueue_script( 'geo-cl-inline' );

		wp_localize_script( 'geo-cl-inline', 'GeoCL', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
			'i18n'    => [
				'placeholder'   => __( 'Select city / locality', 'woo-city-postcode-autocomplete' ),
				'searching'     => __( 'Searching...', 'woo-city-postcode-autocomplete' ),
				'inputTooShort' => __( 'Type at least 2 characters', 'woo-city-postcode-autocomplete' ),
				'noResults'     => __( 'No localities found', 'woo-city-postcode-autocomplete' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Front-end: inline JS
	// -------------------------------------------------------------------------

	public static function print_checkout_js() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		?>
		<script>
		(function ($) {
			'use strict';

			function getSelectLib() {
				if ($.fn.selectWoo) return 'selectWoo';
				if ($.fn.select2)   return 'select2';
				return null;
			}

			function getCountry($form) {
				var cc = ($form.find('#shipping_country').val() || '').trim();
				if (!cc) cc = ($form.find('#billing_country').val() || '').trim();
				if (!cc) {
					$form.find('select[id$="_country"]').each(function () {
						var v = ($(this).val() || '').trim();
						if (v) { cc = v; return false; }
					});
				}
				return cc.toUpperCase();
			}

			function initShippingCityAutocomplete() {
				var lib = getSelectLib();
				if (!lib) return;

				// Support both FunnelKit and standard WooCommerce checkout forms.
				var $form = $('#wfacp_checkout_form');
				if (!$form.length) $form = $('form.checkout, form#checkout');
				if (!$form.length) return;

				var $state       = $form.find('#shipping_state');
				var $city        = $form.find('#shipping_city');
				var $postcode    = $form.find('#shipping_postcode');
				var $billingCity = $form.find('#billing_city');
				var $billingPc   = $form.find('#billing_postcode');
				var $cityField   = $form.find('#shipping_city_field');

				if (!$city.length || !$cityField.length) return;

				// Guard: do not double-initialise after updated_checkout.
				if ($cityField.find('#geo_cl_city_select').length) return;

				// Keep the original text input in the DOM (WC validation & submit
				// need it) but hide it visually.
				$city.hide().attr('data-geo-cl-hidden', '1');

				var $wrapper = $cityField.find('.woocommerce-input-wrapper').first();
				if (!$wrapper.length) $wrapper = $cityField;

				var $select = $('<select id="geo_cl_city_select" class="wfacp-form-control" style="width:100%"></select>');
				$wrapper.append($select);

				$select[lib]({
					width: '100%',
					allowClear: true,
					placeholder: (typeof GeoCL !== 'undefined') ? GeoCL.i18n.placeholder : 'Select city…',
					minimumInputLength: 2,
					ajax: {
						url: (typeof GeoCL !== 'undefined') ? GeoCL.ajaxUrl : '',
						dataType: 'json',
						delay: 250,
						data: function (params) {
							return {
								action:      '<?php echo esc_js( self::AJAX_ACTION ); ?>',
								nonce:       GeoCL.nonce,
								term:        params.term || '',
								country:     getCountry($form),
								state:       $state.val() || '',
								state_label: $state.find('option:selected').text() || ''
							};
						},
						processResults: function (resp) {
							if (!resp || !resp.success || !resp.data || !resp.data.results) {
								return { results: [] };
							}
							return { results: resp.data.results };
						},
						cache: true
					},
					templateResult: function (item) {
						return item.text || '';
					},
					templateSelection: function (item) {
						// Show only the bare locality name once selected.
						if (!item.id) return item.text || '';
						return item.locality_name || item.text || '';
					},
					escapeMarkup: function (m) { return m; },
					language: {
						inputTooShort: function () { return (typeof GeoCL !== 'undefined') ? GeoCL.i18n.inputTooShort : 'Type at least 2 characters'; },
						noResults:     function () { return (typeof GeoCL !== 'undefined') ? GeoCL.i18n.noResults     : 'No results'; },
						searching:     function () { return (typeof GeoCL !== 'undefined') ? GeoCL.i18n.searching     : 'Searching…'; }
					}
				});

				// Pre-populate if a city value already exists (page reload, order editing).
				var existingCity = ($city.val() || '').trim();
				if (existingCity) {
					$select.append(new Option(existingCity, existingCity, true, true)).trigger('change');
				}

				/* ---- Locality selected ------------------------------------- */
				$select.on('select2:select', function (e) {
					var d = e.params && e.params.data ? e.params.data : null;
					if (!d) return;

					var cityName = (d.locality_name || '').trim();
					var pc       = (d.postcode      || '').trim();

					$city.val(cityName).trigger('change');
					if ($billingCity.length) $billingCity.val(cityName).trigger('change');

					// Only update postcode when value actually changes — prevents
					// redundant update_checkout AJAX calls.
					if (pc && pc !== $postcode.val()) {
						$postcode.val(pc).trigger('change');
						if ($billingPc.length && pc !== $billingPc.val()) {
							$billingPc.val(pc).trigger('change');
						}
					}

					$(document.body).trigger('update_checkout');
				});

				/* ---- Selection cleared ------------------------------------ */
				$select.on('select2:clear', function () {
					$city.val('').trigger('change');
					if ($billingCity.length) $billingCity.val('').trigger('change');
					$(document.body).trigger('update_checkout');
				});

				/* ---- State / province changed → reset city ---------------- */
				$state.off('change.geoCl').on('change.geoCl', function () {
					$city.val('').trigger('change');
					if ($billingCity.length) $billingCity.val('').trigger('change');
					$select.val(null).trigger('change');
				});

				/* ---- Country changed → reset city ------------------------- */
				$form.find('select[id$="_country"]').off('change.geoCl').on('change.geoCl', function () {
					$city.val('').trigger('change');
					if ($billingCity.length) $billingCity.val('').trigger('change');
					$select.val(null).trigger('change');
				});
			}

			$(document).ready(initShippingCityAutocomplete);
			$(document.body).on('updated_checkout', initShippingCityAutocomplete);

		}(jQuery));
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Normalization helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalise a string for loose matching:
	 * strip tags → trim → remove accents → lowercase → collapse whitespace.
	 */
	public static function normalize( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = trim( $value );
		$value = remove_accents( $value );
		$value = mb_strtolower( $value, 'UTF-8' );
		$value = str_replace( [ '-', '_', '.', ',' ], ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		return trim( $value );
	}

	/**
	 * Normalise a postcode: trim, keep only alphanumerics and hyphens, uppercase.
	 */
	public static function normalize_postcode( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/[^A-Za-z0-9\-]/', '', $value );
		return strtoupper( $value );
	}

	// -------------------------------------------------------------------------
	// Misc helpers
	// -------------------------------------------------------------------------

	private static function check_admin_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.', 403 );
		}
		check_admin_referer( self::NONCE_ADMIN, '_geo_cl_nonce' );
	}

	private static function admin_redirect_error( $message ) {
		wp_safe_redirect( add_query_arg( [
			'page'          => 'geo-cl-localities',
			'geo_cl_notice' => 'error',
			'msg'           => rawurlencode( $message ),
		], admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Human-readable message for PHP upload error codes.
	 */
	private static function upload_error_message( $code ) {
		$messages = [
			UPLOAD_ERR_INI_SIZE   => 'The file exceeds the upload_max_filesize directive in php.ini.',
			UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the MAX_FILE_SIZE directive in the HTML form.',
			UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
			UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
			UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
			UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
			UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
		];
		return $messages[ $code ] ?? 'Unknown upload error (code ' . $code . ').';
	}
}

// ============================================================================
// Bootstrap
// ============================================================================

GEO_Checkout_Localities::init();
