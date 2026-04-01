<?php
/**
 * Plugin Name: Spread Em
 * Plugin URI:  https://github.com/egglose5/spread-em
 * Description: A WooCommerce spreadsheet view for the existing product catalogue flow. Select products from the admin list, open them in a sheet, edit inline, and save in one click.
 * Version:     1.01
 * Author:      spread-em
 * License:     GPL-2.0-or-later
 * Text Domain: spread-em
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 *
 * @package SpreadEm
 */

defined( 'ABSPATH' ) || exit;

define( 'SPREAD_EM_VERSION', '1.01' );
define( 'SPREAD_EM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPREAD_EM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin translations.
 */
function spread_em_load_textdomain(): void {
	load_plugin_textdomain( 'spread-em', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'spread_em_load_textdomain' );

/**
 * Create the logging table on plugin activation.
 */
function spread_em_activate(): void {
	require_once SPREAD_EM_PLUGIN_DIR . 'includes/class-spread-em-logger.php';
	SpreadEm_Logger::create_table();
}
register_activation_hook( __FILE__, 'spread_em_activate' );

/**
 * Check WooCommerce is active before doing anything.
 */
function spread_em_check_dependencies(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function (): void {
			echo '<div class="notice notice-error"><p>' .
			     esc_html__( 'Spread Em requires WooCommerce to be installed and active.', 'spread-em' ) .
			     '</p></div>';
		} );
		return;
	}

	spread_em_init();
}
add_action( 'plugins_loaded', 'spread_em_check_dependencies' );

/**
 * Initialise the plugin after WooCommerce is confirmed active.
 */
function spread_em_init(): void {
	require_once SPREAD_EM_PLUGIN_DIR . 'includes/class-spread-em-logger.php';
	require_once SPREAD_EM_PLUGIN_DIR . 'includes/class-spread-em-admin.php';
	require_once SPREAD_EM_PLUGIN_DIR . 'includes/class-spread-em-ajax.php';
	require_once SPREAD_EM_PLUGIN_DIR . 'includes/class-spread-em-log-page.php';

	SpreadEm_Logger::ensure_schema();

	SpreadEm_Admin::init();
	SpreadEm_Ajax::init();
	SpreadEm_Log_Page::init();
}
