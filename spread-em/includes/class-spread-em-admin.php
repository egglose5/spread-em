<?php
/**
 * Admin page handler for Spread Em.
 *
 * Injects the "Spread Em" link into the WooCommerce product list bulk-action
 * bar and registers the standalone spreadsheet editor admin page.
 *
 * @package SpreadEm
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SpreadEm_Admin
 */
class SpreadEm_Admin {

	/** @var string Slug for the editor admin page. */
	const PAGE_SLUG = 'spread-em-editor';

	/**
	 * Wire up all WordPress hooks.
	 */
	public static function init(): void {
		// Register the hidden admin page that renders the editor.
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );

		// Enqueue assets only on our editor page.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// Add a "Spread Em" button above the product list table.
		add_action( 'manage_posts_extra_tablenav', [ __CLASS__, 'render_open_button' ] );
	}

	/**
	 * Register a sub-menu page under WooCommerce > Products (hidden from nav).
	 */
	public static function register_admin_page(): void {
		add_submenu_page(
			'',                                     // No parent – hidden from menus.
			__( 'Spread Em Editor', 'spread-em' ),
			__( 'Spread Em Editor', 'spread-em' ),
			'edit_products',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_editor_page' ]
		);
	}

	/**
	 * Enqueue JavaScript and CSS on the Spread Em editor page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( string $hook ): void {
		// WordPress generates hooks like "admin_page_{slug}" for hidden sub-pages.
		if ( 'admin_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'spread-em-editor',
			SPREAD_EM_PLUGIN_URL . 'assets/css/spread-em-editor.css',
			[],
			SPREAD_EM_VERSION
		);

		wp_enqueue_script(
			'spread-em-editor',
			SPREAD_EM_PLUGIN_URL . 'assets/js/spread-em-editor.js',
			[ 'jquery' ],
			SPREAD_EM_VERSION,
			true // Load in footer.
		);

		wp_localize_script(
			'spread-em-editor',
			'spreadEmData',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'spread_em_nonce' ),
				'products' => self::get_products_for_editor(),
				'columns'  => self::get_column_definitions(),
				'i18n'     => [
					'save'           => __( 'Save All Changes', 'spread-em' ),
					'undo'           => __( 'Undo Last Change', 'spread-em' ),
					'saving'         => __( 'Saving…', 'spread-em' ),
					'saved'          => __( 'All changes saved!', 'spread-em' ),
					'save_error'     => __( 'Save failed. Please try again.', 'spread-em' ),
					'nothing_to_undo' => __( 'Nothing to undo.', 'spread-em' ),
					'confirm_save'   => __( 'Save all pending changes to the database?', 'spread-em' ),
					'back_to_products' => __( '← Back to Products', 'spread-em' ),
					'unsaved_changes'  => __( 'You have unsaved changes. Leave anyway?', 'spread-em' ),
				],
			]
		);
	}

	/**
	 * Output the "Open Spread Em" button above the product list table.
	 *
	 * The button is only rendered on the Products screen (edit.php?post_type=product).
	 *
	 * @param string $which 'top' or 'bottom' table navigation.
	 */
	public static function render_open_button( string $which ): void {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || 'top' !== $which ) {
			return;
		}

		$editor_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		printf(
			'<div class="spread-em-launch-wrap alignleft actions">'
			. '<a href="%s" class="button spread-em-launch-btn">%s</a>'
			. '</div>',
			esc_url( $editor_url ),
			esc_html__( '📊 Spread Em', 'spread-em' )
		);
	}

	/**
	 * Render the full-screen spreadsheet editor page.
	 */
	public static function render_editor_page(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'spread-em' ) );
		}
		?>
		<div class="wrap spread-em-wrap">
			<h1><?php esc_html_e( 'Spread Em – Product Editor', 'spread-em' ); ?></h1>
			<div id="spread-em-toolbar">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"
				   class="button spread-em-back-btn"
				   id="spread-em-back">
					<?php esc_html_e( '← Back to Products', 'spread-em' ); ?>
				</a>
				<button type="button" id="spread-em-undo" class="button" disabled>
					<?php esc_html_e( 'Undo Last Change', 'spread-em' ); ?>
				</button>
				<button type="button" id="spread-em-save" class="button button-primary">
					<?php esc_html_e( 'Save All Changes', 'spread-em' ); ?>
				</button>
				<span id="spread-em-status" class="spread-em-status" aria-live="polite"></span>
			</div>

			<div id="spread-em-container">
				<div id="spread-em-loading" class="spread-em-loading">
					<?php esc_html_e( 'Loading products…', 'spread-em' ); ?>
				</div>
				<div id="spread-em-table-wrap" style="display:none;">
					<table id="spread-em-table" class="spread-em-table">
						<thead id="spread-em-thead"></thead>
						<tbody id="spread-em-tbody"></tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Data helpers
	// -------------------------------------------------------------------------

	/**
	 * Return all products formatted for the spreadsheet editor.
	 *
	 * We mirror the fields WooCommerce exports so the editor shows the same
	 * data as a WC CSV export.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_products_for_editor(): array {
		$query_args = [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		];

		$products = get_posts( $query_args );
		$rows     = [];

		foreach ( $products as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			$rows[] = self::product_to_row( $product );
		}

		return $rows;
	}

	/**
	 * Convert a WC_Product object to a flat associative row array.
	 *
	 * Fields match the WooCommerce CSV exporter column set so data is
	 * consistent with what a WC export would produce.
	 *
	 * @param \WC_Product $product
	 * @return array<string, mixed>
	 */
	private static function product_to_row( \WC_Product $product ): array {
		$cats = wc_get_product_category_list( $product->get_id(), ', ' );

		return [
			'id'                 => $product->get_id(),
			'name'               => $product->get_name(),
			'sku'                => $product->get_sku(),
			'type'               => $product->get_type(),
			'status'             => $product->get_status(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'short_description'  => $product->get_short_description(),
			'description'        => $product->get_description(),
			'regular_price'      => $product->get_regular_price(),
			'sale_price'         => $product->get_sale_price(),
			'tax_status'         => $product->get_tax_status(),
			'tax_class'          => $product->get_tax_class(),
			'stock_status'       => $product->get_stock_status(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'manage_stock'       => $product->get_manage_stock() ? 'yes' : 'no',
			'backorders'         => $product->get_backorders(),
			'weight'             => $product->get_weight(),
			'length'             => $product->get_length(),
			'width'              => $product->get_width(),
			'height'             => $product->get_height(),
			'categories'         => wp_strip_all_tags( $cats ),
			'tags'               => implode( ', ', wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] ) ),
		];
	}

	/**
	 * Return column definition objects used by the editor JS to render headers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_column_definitions(): array {
		return [
			[ 'key' => 'id',                 'label' => __( 'ID', 'spread-em' ),                  'readonly' => true  ],
			[ 'key' => 'name',               'label' => __( 'Name', 'spread-em' ),                'readonly' => false ],
			[ 'key' => 'sku',                'label' => __( 'SKU', 'spread-em' ),                 'readonly' => false ],
			[ 'key' => 'type',               'label' => __( 'Type', 'spread-em' ),                'readonly' => true  ],
			[ 'key' => 'status',             'label' => __( 'Status', 'spread-em' ),              'readonly' => false ],
			[ 'key' => 'catalog_visibility', 'label' => __( 'Visibility', 'spread-em' ),          'readonly' => false ],
			[ 'key' => 'regular_price',      'label' => __( 'Regular Price', 'spread-em' ),       'readonly' => false ],
			[ 'key' => 'sale_price',         'label' => __( 'Sale Price', 'spread-em' ),          'readonly' => false ],
			[ 'key' => 'stock_status',       'label' => __( 'Stock Status', 'spread-em' ),        'readonly' => false ],
			[ 'key' => 'stock_quantity',     'label' => __( 'Stock Qty', 'spread-em' ),           'readonly' => false ],
			[ 'key' => 'manage_stock',       'label' => __( 'Manage Stock', 'spread-em' ),        'readonly' => false ],
			[ 'key' => 'backorders',         'label' => __( 'Backorders', 'spread-em' ),          'readonly' => false ],
			[ 'key' => 'weight',             'label' => __( 'Weight', 'spread-em' ),              'readonly' => false ],
			[ 'key' => 'length',             'label' => __( 'Length', 'spread-em' ),              'readonly' => false ],
			[ 'key' => 'width',              'label' => __( 'Width', 'spread-em' ),               'readonly' => false ],
			[ 'key' => 'height',             'label' => __( 'Height', 'spread-em' ),              'readonly' => false ],
			[ 'key' => 'tax_status',         'label' => __( 'Tax Status', 'spread-em' ),          'readonly' => false ],
			[ 'key' => 'tax_class',          'label' => __( 'Tax Class', 'spread-em' ),           'readonly' => false ],
			[ 'key' => 'short_description',  'label' => __( 'Short Description', 'spread-em' ),   'readonly' => false ],
			[ 'key' => 'description',        'label' => __( 'Description', 'spread-em' ),         'readonly' => false ],
			[ 'key' => 'categories',         'label' => __( 'Categories', 'spread-em' ),          'readonly' => true  ],
			[ 'key' => 'tags',               'label' => __( 'Tags', 'spread-em' ),                'readonly' => true  ],
		];
	}
}
