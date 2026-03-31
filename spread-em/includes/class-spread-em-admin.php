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
 * Selection is handled entirely by WooCommerce's existing bulk-actions form.
 * This plugin adds no custom selection UI.
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
		add_action( 'admin_footer-edit.php', [ __CLASS__, 'ensure_bulk_action_is_visible' ] );

		// Handle the bulk action: redirect to the editor with selected IDs.
		add_filter( 'handle_bulk_actions-edit-product', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );

		// Fix the category filter so it includes products in sub-categories.
		add_action( 'pre_get_posts', [ __CLASS__, 'fix_category_filter_includes_children' ] );
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
	 * Fallback injector for WooCommerce product screens that do not expose
	 * custom bulk actions registered through the standard WordPress filter.
	 */
	public static function ensure_bulk_action_is_visible(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}

		$label  = wp_json_encode( __( '📊 Spread Em', 'spread-em' ) );
		$action = wp_json_encode( self::BULK_ACTION );
		?>
		<script>
			(function () {
				const actionValue = <?php echo $action; ?>;
				const actionLabel = <?php echo $label; ?>;

				['action', 'action2'].forEach(function (selectId) {
					const select = document.getElementById(selectId);

					if (!select) {
						return;
					}

					const exists = Array.from(select.options).some(function (option) {
						return option.value === actionValue;
					});

					if (exists) {
						return;
					}

					select.add(new Option(actionLabel, actionValue));
				});
			})();
		</script>
		<?php
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
		global $pagenow;

		if (
			! is_admin() ||
			! $query->is_main_query() ||
			'edit.php' !== $pagenow ||
			'product' !== $query->get( 'post_type' )
		) {
			return;
		}

		$raw_category = '';

		if ( isset( $_GET['product_cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw_category = sanitize_text_field( wp_unslash( $_GET['product_cat'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( $query->get( 'product_cat' ) ) {
			$raw_category = sanitize_text_field( (string) $query->get( 'product_cat' ) );
		}

		if ( '' === $raw_category ) {
			return;
		}

		$term = ctype_digit( $raw_category )
			? get_term_by( 'id', absint( $raw_category ), 'product_cat' )
			: get_term_by( 'slug', sanitize_title( $raw_category ), 'product_cat' );

		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$term_ids    = [ (int) $term->term_id ];
		$descendants = get_term_children( (int) $term->term_id, 'product_cat' );

		if ( ! is_wp_error( $descendants ) && ! empty( $descendants ) ) {
			$term_ids = array_merge( $term_ids, array_map( 'absint', $descendants ) );
		}

		$term_ids = array_values( array_unique( array_filter( $term_ids ) ) );

		if ( empty( $term_ids ) ) {
			return;
		}

		$existing_tax_query = $query->get( 'tax_query' );
		$tax_query          = is_array( $existing_tax_query ) ? $existing_tax_query : [];

		$tax_query = array_values(
			array_filter(
				$tax_query,
				static function ( $clause ) {
					return ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) || 'product_cat' !== $clause['taxonomy'];
				}
			)
		);

		$query->set(
			'tax_query',
			array_merge(
				$tax_query,
				[
					[
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $term_ids,
					'operator'         => 'IN',
					'include_children' => false,
					],
				]
			)
		);
		$query->set( 'product_cat', '' );
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
				<label style="display:flex;align-items:center;gap:5px;font-size:13px;">
					<input type="checkbox" id="spread-em-hide-parents">
					<?php esc_html_e( 'Hide Parent Products', 'spread-em' ); ?>
				</label>
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

			// If variable, load variations exactly as WC CSV exporter does.
			if ( 'variable' === $product->get_type() ) {
				foreach ( $product->get_children( true ) as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation instanceof \WC_Product_Variation ) {
						$rows[] = self::variation_to_row( $variation, $product->get_id() );
					}
				}
			}
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
			'parent_id'          => 0,
			'is_variation'       => false,
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
			'attributes'         => self::get_product_attributes_summary( $product ),
		];
	}

	/**
	 * Convert a WC_Product_Variation to a row, mirroring WC CSV exporter format.
	 *
	 * @param \WC_Product_Variation $variation
	 * @param int                   $parent_id
	 * @return array<string, mixed>
	 */
	private static function variation_to_row( \WC_Product_Variation $variation, int $parent_id ): array {
		$attrs = [];
		foreach ( $variation->get_attributes() as $attr_name => $attr_value ) {
			$label       = wc_attribute_label( $attr_name );
			// Taxonomy attribute – get the term label instead of the slug.
			if ( taxonomy_exists( $attr_name ) && '' !== $attr_value ) {
				$term = get_term_by( 'slug', $attr_value, $attr_name );
				if ( $term instanceof \WP_Term ) {
					$attr_value = $term->name;
				}
			}
			$attrs[] = $label . ': ' . $attr_value;
		}

		return [
			'id'                 => $variation->get_id(),
			'parent_id'          => $parent_id,
			'is_variation'       => true,
			'name'               => '',
			'sku'                => $variation->get_sku(),
			'type'               => 'variation',
			'status'             => $variation->get_status(),
			'catalog_visibility' => '',
			'short_description'  => '',
			'description'        => $variation->get_description(),
			'regular_price'      => $variation->get_regular_price(),
			'sale_price'         => $variation->get_sale_price(),
			'tax_status'         => $variation->get_tax_status(),
			'tax_class'          => $variation->get_tax_class(),
			'stock_status'       => $variation->get_stock_status(),
			'stock_quantity'     => $variation->get_stock_quantity(),
			'manage_stock'       => $variation->get_manage_stock() ? 'yes' : 'no',
			'backorders'         => $variation->get_backorders(),
			'weight'             => $variation->get_weight(),
			'length'             => $variation->get_length(),
			'width'              => $variation->get_width(),
			'height'             => $variation->get_height(),
			'categories'         => '',
			'tags'               => '',
			'attributes'         => implode( ' | ', $attrs ),
		];
	}

	/**
	 * Build a human-readable attributes summary for a parent product.
	 * e.g. "Size: S, M, L | Color: Red, Blue"
	 *
	 * @param \WC_Product $product
	 * @return string
	 */
	private static function get_product_attributes_summary( \WC_Product $product ): string {
		$parts = [];

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->get_visible() && ! $attribute->get_variation() ) {
				continue;
			}

			$label  = wc_attribute_label( $attribute->get_name() );
			$values = [];

			if ( $attribute->is_taxonomy() ) {
				$terms = $attribute->get_terms();
				if ( is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$values[] = $term->name;
					}
				}
			} else {
				$values = $attribute->get_options();
			}

			if ( ! empty( $values ) ) {
				$parts[] = $label . ': ' . implode( ', ', $values );
			}
		}

		return implode( ' | ', $parts );
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
			[ 'key' => 'attributes',         'label' => __( 'Attributes', 'spread-em' ),          'readonly' => true  ],
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
