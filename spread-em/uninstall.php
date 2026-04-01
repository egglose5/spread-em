<?php
/**
 * Clean up plugin data during uninstall.
 *
 * Drops the spread_em_log table that was created on activation.
 *
 * @package SpreadEm
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-spread-em-logger.php';
SpreadEm_Logger::drop_table();