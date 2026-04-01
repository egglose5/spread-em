<?php
/**
 * Clean up plugin data during uninstall.
 *
 * Drops the spread_em_log table and plugin-owned schema metadata.
 *
 * @package SpreadEm
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-spread-em-logger.php';
SpreadEm_Logger::drop_table();

delete_option( 'spread_em_log_schema_version' );

if ( is_multisite() ) {
	delete_site_option( 'spread_em_log_schema_version' );
}