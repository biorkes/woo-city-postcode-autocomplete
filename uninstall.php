<?php
/**
 * Uninstall script.
 *
 * WordPress calls this file when the plugin is deleted from the admin UI.
 * The main class also registers a hook via register_uninstall_hook(), but
 * having a standalone uninstall.php is the recommended WordPress pattern.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove stored data files.
$upload_dir = wp_upload_dir();
$data_dir   = trailingslashit( $upload_dir['basedir'] ) . 'geo-cl-localities/';

if ( is_dir( $data_dir ) ) {
	$files = glob( $data_dir . '*' );
	if ( $files ) {
		foreach ( $files as $file ) {
			@unlink( $file ); // phpcs:ignore
		}
	}
	@rmdir( $data_dir ); // phpcs:ignore
}

// Drop plugin tables.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'geo_cl_localities' ); // phpcs:ignore
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'geo_cl_datasets' );   // phpcs:ignore
