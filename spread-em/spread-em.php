<?php
/**
 * Plugin Name: Spread Em
 * Plugin URI:  https://github.com/egglose5/spread-em
 * Description: A WooCommerce spreadsheet view for the existing product catalogue flow. Select products from the admin list, open them in a sheet, edit inline, and save in one click.
 * Version:     1.0.0
 * Author:      spread-em
 * License:     GPL-2.0-or-later
 * Text Domain: spread-em
 * Requires Plugins: woocommerce
 *
 * @package SpreadEm
 */

defined( 'ABSPATH' ) || exit;

define( 'SPREAD_EM_VERSION', '1.0.0' );
define( 'SPREAD_EM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPREAD_EM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
	require_once SPREAD_EM_PLUGIN_DIR . 'includes/class-spread-em-admin.php';
	require_once SPREAD_EM_PLUGIN_DIR . 'includes/class-spread-em-ajax.php';

	SpreadEm_Admin::init();
	SpreadEm_Ajax::init();
}
