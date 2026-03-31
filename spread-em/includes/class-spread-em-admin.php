<?php
/**
 * Admin page handler for Spread Em.
 *
 * Registers "📊 Spread Em" as a WooCommerce bulk action on the product list
 * and provides the standalone spreadsheet editor admin page.  The editor
 * loads only the products that were selected in the bulk action.
 *
 * Also fixes the WC admin product-category filter so that choosing a parent
 * category includes products assigned to any of its descendant categories.
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

	/** @var string Bulk action identifier. */
	const BULK_ACTION = 'spread_em_bulk_edit';

	/**
	 * Wire up all WordPress hooks.
	 */
	public static function init(): void {
		// Register the hidden admin page that renders the editor.
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );

		// Enqueue assets only on our editor page.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// Add "📊 Spread Em" to the product list bulk-actions dropdown.
		add_filter( 'bulk_actions-edit-product', [ __CLASS__, 'register_bulk_action' ] );

		// Handle the bulk action: redirect to the editor with selected IDs.
		add_filter( 'handle_bulk_actions-edit-product', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );

		// Fix the category filter so it includes products in sub-categories.
		add_action( 'pre_get_posts', [ __CLASS__, 'fix_category_filter_includes_children' ] );

		// Show an admin notice when the user reaches the editor without selecting products.
		add_action( 'admin_notices', [ __CLASS__, 'render_admin_notices' ] );
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

		$product_ids = self::get_selected_product_ids();

		wp_localize_script(
			'spread-em-editor',
			'spreadEmData',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'spread_em_nonce' ),
				'products' => self::get_products_for_editor( $product_ids ),
				'columns'  => self::get_column_definitions(),
				'i18n'     => [
					'save'             => __( 'Save All Changes', 'spread-em' ),
					'undo'             => __( 'Undo Last Change', 'spread-em' ),
					'saving'           => __( 'Saving…', 'spread-em' ),
					'saved'            => __( 'All changes saved!', 'spread-em' ),
					'save_error'       => __( 'Save failed. Please try again.', 'spread-em' ),
					'nothing_to_undo'  => __( 'Nothing to undo.', 'spread-em' ),
					'confirm_save'     => __( 'Save all pending changes to the database?', 'spread-em' ),
					'back_to_products' => __( '← Back to Products', 'spread-em' ),
					'unsaved_changes'  => __( 'You have unsaved changes. Leave anyway?', 'spread-em' ),
				],
			]
		);
	}

	/**
	 * Add "📊 Spread Em" to the product list bulk-actions dropdown.
	 *
	 * @param array<string, string> $actions Existing bulk actions.
	 * @return array<string, string>
	 */
	public static function register_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( '📊 Spread Em', 'spread-em' );
		return $actions;
	}

	/**
	 * Handle the "Spread Em" bulk action.
	 *
	 * Redirects to the editor page with the selected product IDs encoded in
	 * the URL query string.
	 *
	 * @param string     $redirect_url Current redirect URL from WordPress.
	 * @param string     $action       The bulk action being processed.
	 * @param array<int> $post_ids     IDs of the selected posts.
	 * @return string Modified redirect URL (or original if not our action).
	 */
	public static function handle_bulk_action( string $redirect_url, string $action, array $post_ids ): string {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect_url;
		}

		if ( empty( $post_ids ) ) {
			return $redirect_url;
		}

		$ids_param = implode( ',', array_map( 'intval', $post_ids ) );

		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&products=' . rawurlencode( $ids_param ) );
	}

	/**
	 * Fix the WooCommerce admin product-category filter so that selecting a
	 * parent category also shows products assigned to its descendant categories.
	 *
	 * WordPress's default admin taxonomy filter only queries the exact term
	 * chosen in the URL.  This hook expands the tax_query to include every
	 * descendant term whenever the selected category has children.
	 *
	 * @param \WP_Query $query The current query object (passed by reference).
	 */
	public static function fix_category_filter_includes_children( \WP_Query $query ): void {
		// Only act on the main admin query for the product list page.
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$post_type   = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$product_cat = isset( $_GET['product_cat'] ) ? sanitize_text_field( wp_unslash( $_GET['product_cat'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'product' !== $post_type || '' === $product_cat ) {
			return;
		}

		// Look up the term so we can find its children.
		$term = get_term_by( 'slug', $product_cat, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$child_ids = get_term_children( $term->term_id, 'product_cat' );
		if ( is_wp_error( $child_ids ) || empty( $child_ids ) ) {
			// Leaf category or error – WordPress's default query is already correct.
			return;
		}

		// Replace the tax_query to cover the parent term and all descendants.
		$all_term_ids = array_merge( [ $term->term_id ], $child_ids );

		$query->set(
			'tax_query',
			[
				[
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $all_term_ids,
					'include_children' => false, // We've already expanded children ourselves.
				],
			]
		);
	}

	/**
	 * Display admin notices injected by Spread Em into the URL.
	 *
	 * Currently handles:
	 *   spread_em_notice=no_selection – user opened the editor without
	 *   selecting any products in the bulk-actions form first.
	 */
	public static function render_admin_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['spread_em_notice'] ) ? sanitize_key( $_GET['spread_em_notice'] ) : '';

		if ( 'no_selection' === $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. esc_html__( 'Spread Em: Please select at least one product using the checkboxes, then choose "📊 Spread Em" from the Bulk actions dropdown.', 'spread-em' )
				. '</p></div>';
		}
	}

	/**
	 * Render the full-screen spreadsheet editor page.
	 *
	 * Requires at least one product ID to be present in the URL (added by the
	 * bulk action handler).  If none are found, the user is redirected back to
	 * the product list with an error notice.
	 */
	public static function render_editor_page(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'spread-em' ) );
		}

		$product_ids = self::get_selected_product_ids();

		if ( empty( $product_ids ) ) {
			// No products were selected – send the user back with a notice.
			wp_safe_redirect(
				add_query_arg(
					[ 'spread_em_notice' => 'no_selection' ],
					admin_url( 'edit.php?post_type=product' )
				)
			);
			exit;
		}

		$count = count( $product_ids );
		?>
		<div class="wrap spread-em-wrap">
			<h1>
				<?php
				/* translators: %d: number of products being edited */
				printf( esc_html( _n( 'Spread Em – Editing %d product', 'Spread Em – Editing %d products', $count, 'spread-em' ) ), (int) $count );
				?>
			</h1>
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
	 * Parse and sanitise the comma-separated product IDs from the URL.
	 *
	 * Returns an empty array if no (or invalid) IDs are present.
	 *
	 * @return array<int>
	 */
	private static function get_selected_product_ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['products'] ) ) {
			return [];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = sanitize_text_field( wp_unslash( $_GET['products'] ) );

		return array_values(
			array_filter(
				array_map( 'intval', explode( ',', $raw ) )
			)
		);
	}

	/**
	 * Return products formatted for the spreadsheet editor.
	 *
	 * When $product_ids is non-empty only those products are returned;
	 * otherwise every product in the catalogue is returned (fallback).
	 *
	 * We mirror the fields WooCommerce exports so the editor shows the same
	 * data as a WC CSV export.
	 *
	 * @param array<int> $product_ids  Optional list of product post IDs to include.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_products_for_editor( array $product_ids = [] ): array {
		$query_args = [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		];

		if ( ! empty( $product_ids ) ) {
			$query_args['post__in'] = $product_ids;
		}

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
