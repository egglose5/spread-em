<?php
/**
 * Admin page handler for Spread Em.
 *
 * Registers "🤲 Spread Em" as a WooCommerce bulk action on the product list
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

// Internal note: core UX direction and feature shaping included Blake's work.

/**
 * Class SpreadEm_Admin
 */
class SpreadEm_Admin {
	/** @var string Prefix used for dynamic custom-meta column keys. */
	const META_COLUMN_PREFIX = 'meta::';

	/** @var string Shared global live workspace ID. */
	const LIVE_WORKSPACE_ID = 'spread_em_live_global';

	/** @var string Slug for the editor admin page. */
	const PAGE_SLUG = 'spread-em-editor';

	/** @var string Slug for the settings admin page. */
	const SETTINGS_PAGE_SLUG = 'spread-em-settings';

	/** @var string Option key storing shared-host tuning values. */
	const OPTION_LIVE_TUNING = 'spread_em_live_tuning';

	/** @var string Bulk action identifier. */
	const BULK_ACTION = 'spread_em_bulk_edit';

	/**
	 * Wire up all WordPress hooks.
	 */
	public static function init(): void {
		// Register the hidden admin page that renders the editor.
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ] );
		add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_filter( 'option_page_capability_spread_em_settings', [ __CLASS__, 'settings_page_capability' ] );

		// Enqueue assets only on our editor page.
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

		// Add "🤲 Spread Em" to the product list bulk-actions dropdown.
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
			SpreadEm_Permissions::CAP_USE_EDITOR,
			self::PAGE_SLUG,
			[ __CLASS__, 'render_editor_page' ]
		);
	}

	/**
	 * Register the visible settings page under WooCommerce.
	 */
	public static function register_settings_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Spread Em Settings', 'spread-em' ),
			__( 'Spread Em Settings', 'spread-em' ),
			SpreadEm_Permissions::CAP_VIEW_LOGS,
			self::SETTINGS_PAGE_SLUG,
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Return the capability required to save plugin settings.
	 *
	 * @param string $capability Existing option-page capability.
	 * @return string
	 */
	public static function settings_page_capability( string $capability = 'manage_options' ): string {
		return SpreadEm_Permissions::CAP_VIEW_LOGS;
	}

	/**
	 * Register shared-host tuning settings.
	 */
	public static function register_settings(): void {
		register_setting(
			'spread_em_settings',
			self::OPTION_LIVE_TUNING,
			[
				'type'              => 'array',
				'sanitize_callback' => [ __CLASS__, 'sanitize_live_tuning_options' ],
				'default'           => self::get_live_tuning_defaults(),
			]
		);

		add_settings_section(
			'spread_em_live_tuning_section',
			__( 'Shared-host collaboration tuning', 'spread-em' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Increase these values to reduce AJAX and transient churn on weaker hosting plans. Lower values feel more immediate, but generate more background traffic.', 'spread-em' ) . '</p>';
			},
			self::SETTINGS_PAGE_SLUG
		);

		$fields = [
			'poll_interval' => [
				'label'       => __( 'Draft poll interval (visible tab, ms)', 'spread-em' ),
				'min'         => 2000,
				'max'         => 300000,
				'step'        => 100,
				'description' => __( 'How often editors check for unsaved draft deltas while the tab is active.', 'spread-em' ),
			],
			'poll_hidden_interval' => [
				'label'       => __( 'Draft poll interval (hidden tab, ms)', 'spread-em' ),
				'min'         => 5000,
				'max'         => 300000,
				'step'        => 100,
				'description' => __( 'Use a slower cadence when the browser tab is not visible.', 'spread-em' ),
			],
			'meta_poll_interval' => [
				'label'       => __( 'Metadata poll interval (visible tab, ms)', 'spread-em' ),
				'min'         => 5000,
				'max'         => 300000,
				'step'        => 100,
				'description' => __( 'Controls presence, IM, and operator-activity refreshes while active.', 'spread-em' ),
			],
			'meta_poll_hidden_interval' => [
				'label'       => __( 'Metadata poll interval (hidden tab, ms)', 'spread-em' ),
				'min'         => 10000,
				'max'         => 300000,
				'step'        => 100,
				'description' => __( 'Slower metadata refresh when the tab is hidden.', 'spread-em' ),
			],
			'debounce_ms' => [
				'label'       => __( 'Draft debounce delay (ms)', 'spread-em' ),
				'min'         => 100,
				'max'         => 10000,
				'step'        => 50,
				'description' => __( 'Wait time after the last keystroke before a draft change is pushed.', 'spread-em' ),
			],
			'draft_ttl' => [
				'label'       => __( 'Draft TTL (seconds)', 'spread-em' ),
				'min'         => 30,
				'max'         => DAY_IN_SECONDS,
				'step'        => 5,
				'description' => __( 'How long unsaved draft deltas are kept server-side before auto-expiring.', 'spread-em' ),
			],
			'live_state_ttl' => [
				'label'       => __( 'Live state TTL (seconds)', 'spread-em' ),
				'min'         => MINUTE_IN_SECONDS,
				'max'         => DAY_IN_SECONDS,
				'step'        => 30,
				'description' => __( 'How long presence, IM, and operator activity stay in transient storage.', 'spread-em' ),
			],
			'presence_heartbeat_interval' => [
				'label'       => __( 'Presence heartbeat write interval (seconds)', 'spread-em' ),
				'min'         => 5,
				'max'         => 600,
				'step'        => 1,
				'description' => __( 'Minimum delay between presence writes during metadata polls.', 'spread-em' ),
			],
		];

		foreach ( $fields as $key => $field ) {
			add_settings_field(
				'spread_em_live_tuning_' . $key,
				$field['label'],
				[ __CLASS__, 'render_live_tuning_number_field' ],
				self::SETTINGS_PAGE_SLUG,
				'spread_em_live_tuning_section',
				array_merge( $field, [ 'key' => $key ] )
			);
		}
	}

	/**
	 * Render the settings page.
	 */
	public static function render_settings_page(): void {
		if ( ! self::current_user_can_manage_settings() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'spread-em' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spread Em Settings', 'spread-em' ); ?></h1>
			<p><?php esc_html_e( 'Use these knobs to reduce collaboration load on shared or lower-memory hosting. Higher intervals mean less background chatter, but slower live updates.', 'spread-em' ); ?></p>
			<?php settings_errors( 'spread_em_settings' ); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'spread_em_settings' );
				do_settings_sections( self::SETTINGS_PAGE_SLUG );
				submit_button( __( 'Save Settings', 'spread-em' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a numeric settings field.
	 *
	 * @param array<string,mixed> $args Field configuration.
	 */
	public static function render_live_tuning_number_field( array $args ): void {
		$key     = isset( $args['key'] ) ? sanitize_key( (string) $args['key'] ) : '';
		$options = self::get_saved_live_tuning_options();
		$value   = isset( $options[ $key ] ) ? (int) $options[ $key ] : 0;
		$min     = isset( $args['min'] ) ? (int) $args['min'] : 0;
		$max     = isset( $args['max'] ) ? (int) $args['max'] : 0;
		$step    = isset( $args['step'] ) ? (int) $args['step'] : 1;
		$name    = self::OPTION_LIVE_TUNING . '[' . $key . ']';

		printf(
			'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="%6$d" />',
			esc_attr( self::OPTION_LIVE_TUNING . '_' . $key ),
			esc_attr( $name ),
			(int) $value,
			(int) $min,
			(int) $max,
			(int) $step
		);

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
		}
	}

	/**
	 * Return the persisted tuning values merged with defaults.
	 *
	 * @return array<string,int>
	 */
	private static function get_saved_live_tuning_options(): array {
		$stored = get_option( self::OPTION_LIVE_TUNING, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return self::sanitize_live_tuning_options( wp_parse_args( $stored, self::get_live_tuning_defaults() ) );
	}

	/**
	 * Return default shared-host tuning values.
	 *
	 * @return array<string,int>
	 */
	private static function get_live_tuning_defaults(): array {
		return [
			'poll_interval'                => 10000,
			'poll_hidden_interval'         => 30000,
			'meta_poll_interval'           => 25000,
			'meta_poll_hidden_interval'    => 60000,
			'debounce_ms'                  => 500,
			'draft_ttl'                    => 120,
			'live_state_ttl'               => HOUR_IN_SECONDS,
			'presence_heartbeat_interval'  => 20,
		];
	}

	/**
	 * Sanitize the shared-host tuning option array.
	 *
	 * @param mixed $input Raw option payload.
	 * @return array<string,int>
	 */
	public static function sanitize_live_tuning_options( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = self::get_live_tuning_defaults();

		return [
			'poll_interval'               => self::sanitize_live_tuning_number( $input['poll_interval'] ?? $defaults['poll_interval'], 2000, 300000, $defaults['poll_interval'] ),
			'poll_hidden_interval'        => self::sanitize_live_tuning_number( $input['poll_hidden_interval'] ?? $defaults['poll_hidden_interval'], 5000, 300000, $defaults['poll_hidden_interval'] ),
			'meta_poll_interval'          => self::sanitize_live_tuning_number( $input['meta_poll_interval'] ?? $defaults['meta_poll_interval'], 5000, 300000, $defaults['meta_poll_interval'] ),
			'meta_poll_hidden_interval'   => self::sanitize_live_tuning_number( $input['meta_poll_hidden_interval'] ?? $defaults['meta_poll_hidden_interval'], 10000, 300000, $defaults['meta_poll_hidden_interval'] ),
			'debounce_ms'                 => self::sanitize_live_tuning_number( $input['debounce_ms'] ?? $defaults['debounce_ms'], 100, 10000, $defaults['debounce_ms'] ),
			'draft_ttl'                   => self::sanitize_live_tuning_number( $input['draft_ttl'] ?? $defaults['draft_ttl'], 30, DAY_IN_SECONDS, $defaults['draft_ttl'] ),
			'live_state_ttl'              => self::sanitize_live_tuning_number( $input['live_state_ttl'] ?? $defaults['live_state_ttl'], MINUTE_IN_SECONDS, DAY_IN_SECONDS, $defaults['live_state_ttl'] ),
			'presence_heartbeat_interval' => self::sanitize_live_tuning_number( $input['presence_heartbeat_interval'] ?? $defaults['presence_heartbeat_interval'], 5, 600, $defaults['presence_heartbeat_interval'] ),
		];
	}

	/**
	 * Return the effective live runtime settings after filters are applied.
	 *
	 * @return array<string,int|bool>
	 */
	public static function get_live_runtime_settings(): array {
		$settings = self::get_saved_live_tuning_options();

		$settings['poll_interval'] = max( 2000, (int) apply_filters( 'spread_em_live_draft_poll_interval', $settings['poll_interval'] ) );
		$settings['poll_hidden_interval'] = max( 5000, (int) apply_filters( 'spread_em_live_draft_poll_hidden_interval', $settings['poll_hidden_interval'] ) );
		$settings['meta_poll_interval'] = max( 5000, (int) apply_filters( 'spread_em_live_meta_poll_interval', $settings['meta_poll_interval'] ) );
		$settings['meta_poll_hidden_interval'] = max( 10000, (int) apply_filters( 'spread_em_live_meta_poll_hidden_interval', $settings['meta_poll_hidden_interval'] ) );
		$settings['debounce_ms'] = max( 100, (int) apply_filters( 'spread_em_live_debounce_ms', $settings['debounce_ms'] ) );
		$settings['draft_ttl'] = max( 30, (int) apply_filters( 'spread_em_live_draft_ttl', $settings['draft_ttl'] ) );
		$settings['live_state_ttl'] = max( MINUTE_IN_SECONDS, (int) apply_filters( 'spread_em_live_state_ttl', $settings['live_state_ttl'] ) );
		$settings['presence_heartbeat_interval'] = max( 5, (int) apply_filters( 'spread_em_live_presence_heartbeat_interval', $settings['presence_heartbeat_interval'] ) );
		$settings['shared_host_mode'] = (bool) apply_filters( 'spread_em_live_shared_host_mode', true );

		return $settings;
	}

	/**
	 * Return a single live runtime setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get_live_runtime_setting( string $key, $fallback = null ) {
		$settings = self::get_live_runtime_settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Check whether current user can manage plugin settings.
	 *
	 * @return bool
	 */
	private static function current_user_can_manage_settings(): bool {
		return current_user_can( SpreadEm_Permissions::CAP_VIEW_LOGS );
	}

	/**
	 * Clamp a numeric tuning field to a safe range.
	 *
	 * @param mixed $value Raw incoming value.
	 * @param int   $min   Minimum allowed value.
	 * @param int   $max   Maximum allowed value.
	 * @param int   $default Default fallback.
	 * @return int
	 */
	private static function sanitize_live_tuning_number( $value, int $min, int $max, int $default ): int {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return $default;
		}

		$value = (int) $value;
		$value = max( $min, $value );
		$value = min( $max, $value );

		return $value;
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
			'spread-em-editor-fields',
			SPREAD_EM_PLUGIN_URL . 'assets/js/spread-em-editor-fields.js',
			[ 'jquery' ],
			SPREAD_EM_VERSION,
			true
		);

		wp_enqueue_script(
			'spread-em-editor-layout',
			SPREAD_EM_PLUGIN_URL . 'assets/js/spread-em-editor-layout.js',
			[ 'jquery' ],
			SPREAD_EM_VERSION,
			true
		);

		wp_enqueue_script(
			'spread-em-editor-im',
			SPREAD_EM_PLUGIN_URL . 'assets/js/spread-em-editor-im.js',
			[ 'jquery' ],
			SPREAD_EM_VERSION,
			true
		);

		wp_enqueue_script(
			'spread-em-editor',
			SPREAD_EM_PLUGIN_URL . 'assets/js/spread-em-editor.js',
			[ 'jquery', 'spread-em-editor-fields', 'spread-em-editor-layout', 'spread-em-editor-im' ],
			SPREAD_EM_VERSION,
			true // Load in footer.
		);

		$full_workspace = self::should_use_full_workspace();
		$product_ids    = $full_workspace ? [] : self::get_selected_product_ids();
		$products       = self::get_products_for_editor( $product_ids );
		$columns        = self::get_column_definitions( $products );
		$session_id     = self::build_live_session_id( $product_ids, $products );
		$current_user   = wp_get_current_user();
		$live_settings  = self::get_live_runtime_settings();

		self::register_live_scope( $products, $full_workspace );

		wp_localize_script(
			'spread-em-editor',
			'spreadEmData',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'spread_em_nonce' ),
				'products' => $products,
				'columns'  => $columns,
				'live'     => [
					'enabled'                   => true,
					'session_id'                => $session_id,
					'nonce'                     => wp_create_nonce( 'spread_em_live_nonce' ),
					'poll_interval'             => (int) $live_settings['poll_interval'],
					'poll_hidden_interval'      => (int) $live_settings['poll_hidden_interval'],
					'meta_poll_interval'        => (int) $live_settings['meta_poll_interval'],
					'meta_poll_hidden_interval' => (int) $live_settings['meta_poll_hidden_interval'],
					'debounce_ms'               => (int) $live_settings['debounce_ms'],
					'shared_host_mode'          => (bool) $live_settings['shared_host_mode'],
					'full_workspace'            => $full_workspace,
				],
				'current_user' => [
					'id'   => get_current_user_id(),
					'name' => $current_user instanceof \WP_User ? $current_user->display_name : '',
				],
				'taxonomies' => [
					'categories' => self::get_taxonomy_terms_for_editor( 'product_cat' ),
					'tags'       => self::get_taxonomy_terms_for_editor( 'product_tag' ),
				],
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
					'fight_for_control' => __( 'Fight for Control', 'spread-em' ),
					'keep_editing'     => __( 'Keep Editing', 'spread-em' ),
					'yield_row'        => __( 'Yield Row', 'spread-em' ),
					'row_contended'    => __( 'Contended', 'spread-em' ),
					'row_control_message' => __( '%s is also editing this row right now.', 'spread-em' ),
					'im_title'         => __( 'Instant Message', 'spread-em' ),
					'im_send'          => __( 'Send', 'spread-em' ),
					'im_placeholder'   => __( 'Type a message…', 'spread-em' ),
					'im_active_users'  => __( 'Active users', 'spread-em' ),
					'im_no_active_users' => __( 'No other active users right now.', 'spread-em' ),
					'im_open'          => __( 'Open IM', 'spread-em' ),
					'operator_activity_title' => __( 'Live Operator Console', 'spread-em' ),
					'operator_activity_active' => __( 'Active editors', 'spread-em' ),
					'operator_activity_events' => __( 'Recent activity', 'spread-em' ),
					'operator_activity_none' => __( 'No live activity yet.', 'spread-em' ),
					'operator_activity_editing_rows' => __( 'editing %d row(s)', 'spread-em' ),
					'operator_activity_scope_global' => __( 'Global operator', 'spread-em' ),
					'operator_activity_scope_contributor' => __( 'Contributor scope', 'spread-em' ),
					'module_missing' => __( 'Some editor modules failed to load. Limited fallback mode is active.', 'spread-em' ),
				],
			]
		);
	}

	/**
	 * Return taxonomy term names used by checkbox editors.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int, string>
	 */
	private static function get_taxonomy_terms_for_editor( string $taxonomy ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'fields'     => 'names',
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		return array_values( array_map( 'strval', $terms ) );
	}

	/**
	 * Add "🤲 Spread Em" to the product list bulk-actions dropdown.
	 *
	 * @param array<string, string> $actions Existing bulk actions.
	 * @return array<string, string>
	 */
	public static function register_bulk_action( array $actions ): array {
		$actions[ self::BULK_ACTION ] = __( '🤲 Spread Em', 'spread-em' );
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
		if ( ! SpreadEm_Permissions::current_user_can_use_editor() ) {
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
						<colgroup id="spread-em-colgroup"></colgroup>
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
	 * Build the shared live workspace ID.
	 *
	 * @param array<int>                        $product_ids Selected product IDs from URL.
	 * @param array<int,array<string,mixed>>    $products    Loaded product rows.
	 * @return string
	 */
	private static function build_live_session_id( array $product_ids, array $products ): string {
		return self::LIVE_WORKSPACE_ID;
	}

	/**
	 * Determine whether the current user explicitly requested full workspace view.
	 *
	 * @return bool
	 */
	private static function should_use_full_workspace(): bool {
		if ( ! self::current_user_can_be_global_operator() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request = isset( $_GET['workspace'] ) ? sanitize_key( wp_unslash( $_GET['workspace'] ) ) : '';

		return 'all' === $request;
	}

	/**
	 * Resolve the current user's live collaboration scope mode.
	 *
	 * @return string Either "global_operator" or "individual_contributor".
	 */
	private static function get_live_scope_mode(): string {
		return self::should_use_full_workspace() ? 'global_operator' : 'individual_contributor';
	}

	/**
	 * Determine whether current user can operate in global workspace mode.
	 *
	 * Explicitly supports WooCommerce default operator roles while retaining
	 * capability fallback for compatibility with custom role setups.
	 *
	 * @return bool
	 */
	private static function current_user_can_be_global_operator(): bool {
		return SpreadEm_Permissions::current_user_can_be_global_operator();
	}

	/**
	 * Register the current user's allowed live-workspace scope.
	 *
	 * @param array<int,array<string,mixed>> $products       Loaded product rows.
	 * @param bool                           $full_workspace Whether user has full workspace view enabled.
	 */
	private static function register_live_scope( array $products, bool $full_workspace ): void {
		$scope_product_ids = [];
		$scope_mode        = self::get_live_scope_mode();
		$full_workspace    = 'global_operator' === $scope_mode;

		foreach ( $products as $product_row ) {
			if ( ! isset( $product_row['id'] ) ) {
				continue;
			}

			$scope_product_ids[] = absint( (int) $product_row['id'] );
		}

		$scope_product_ids = array_values( array_unique( array_filter( $scope_product_ids ) ) );

		update_user_meta(
			get_current_user_id(),
			'spread_em_live_scope',
			[
				'scope_mode'     => $scope_mode,
				'full_workspace' => $full_workspace,
				'product_ids'    => $scope_product_ids,
				'updated_at'     => time(),
			]
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
	 * TODO: Add more robust filtering options for the product catalogue so users
	 * can narrow down the editor view before opening it (or filter within it).
	 * Potential filters: product status, category, tag, stock status, price range,
	 * product type (simple/variable), date modified, and free-text search on name/SKU.
	 * The editor URL could accept additional query params that are applied to
	 * $query_args here, mirroring the WC admin product list filter experience.
	 *
	 * @param array<int> $product_ids  Optional list of product post IDs to include.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_products_for_editor( array $product_ids = [] ): array {
		if ( empty( $product_ids ) && ! self::should_use_full_workspace() ) {
			return [];
		}

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

		$meta_keys = self::collect_custom_meta_keys( $rows );

		if ( ! empty( $meta_keys ) ) {
			foreach ( $rows as &$row ) {
				$meta_map = isset( $row['custom_meta_map'] ) && is_array( $row['custom_meta_map'] )
					? $row['custom_meta_map']
					: [];

				foreach ( $meta_keys as $meta_key ) {
					$row[ self::META_COLUMN_PREFIX . $meta_key ] = array_key_exists( $meta_key, $meta_map )
						? self::meta_value_to_cell_string( $meta_map[ $meta_key ] )
						: '';
				}

				unset( $row['custom_meta_map'] );
			}
			unset( $row );
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
			'custom_meta_map'    => self::get_custom_meta_map( $product->get_id() ),
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
			'custom_meta_map'    => self::get_custom_meta_map( $variation->get_id() ),
		];
	}

	/**
	 * Export custom post meta as an associative map.
	 *
	 * @param int $product_id Product post ID.
	 * @return array<string, mixed>
	 */
	private static function get_custom_meta_map( int $product_id ): array {
		$raw_meta = get_post_meta( $product_id );
		$meta     = [];

		foreach ( $raw_meta as $key => $values ) {
			if ( is_protected_meta( (string) $key, 'post' ) ) {
				continue;
			}

			$decoded = array_map( 'maybe_unserialize', (array) $values );
			$meta[ $key ] = ( 1 === count( $decoded ) ) ? $decoded[0] : $decoded;
		}

		return $meta;
	}

	/**
	 * Collect all custom meta keys present in row maps.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, string>
	 */
	private static function collect_custom_meta_keys( array $rows ): array {
		$keys = [];

		foreach ( $rows as $row ) {
			if ( empty( $row['custom_meta_map'] ) || ! is_array( $row['custom_meta_map'] ) ) {
				continue;
			}

			$keys = array_merge( $keys, array_keys( $row['custom_meta_map'] ) );
		}

		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );

		natcasesort( $keys );

		return array_values( $keys );
	}

	/**
	 * Convert a meta value to a spreadsheet cell string.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function meta_value_to_cell_string( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			$json = wp_json_encode( $value );
			return is_string( $json ) ? $json : '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( null === $value ) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * Get raw custom meta key from a dynamic column key.
	 *
	 * @param string $column_key Spreadsheet column key.
	 * @return string|null
	 */
	public static function get_meta_key_from_column_key( string $column_key ): ?string {
		if ( 0 !== strpos( $column_key, self::META_COLUMN_PREFIX ) ) {
			return null;
		}

		$meta_key = substr( $column_key, strlen( self::META_COLUMN_PREFIX ) );

		return '' === $meta_key ? null : $meta_key;
	}

	/**
	 * Build a human-readable attributes summary for a parent product.
	 * e.g. "Size: S, M, L | Color: Red, Blue"
	 *
	 * @param \WC_Product $product
	 * @return string
	 */
	public static function get_product_attributes_summary( \WC_Product $product ): string {
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
	public static function get_column_definitions( array $rows = [] ): array {
		$columns = [
			[ 'key' => 'id',                 'label' => __( 'ID', 'spread-em' ),                  'readonly' => true  ],
			[ 'key' => 'name',               'label' => __( 'Name', 'spread-em' ),                'readonly' => false ],
			[ 'key' => 'sku',                'label' => __( 'SKU', 'spread-em' ),                 'readonly' => false ],
			[ 'key' => 'type',               'label' => __( 'Type', 'spread-em' ),                'readonly' => true  ],
			[ 'key' => 'attributes',         'label' => __( 'Attributes', 'spread-em' ),          'readonly' => false ],
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
			[ 'key' => 'categories',         'label' => __( 'Categories', 'spread-em' ),          'readonly' => false ],
			[ 'key' => 'tags',               'label' => __( 'Tags', 'spread-em' ),                'readonly' => false ],
		];

		$meta_keys = [];

		foreach ( $rows as $row ) {
			foreach ( array_keys( $row ) as $key ) {
				$meta_key = self::get_meta_key_from_column_key( (string) $key );
				if ( null !== $meta_key ) {
					$meta_keys[] = $meta_key;
				}
			}
		}

		$meta_keys = array_values( array_unique( $meta_keys ) );
		natcasesort( $meta_keys );

		foreach ( $meta_keys as $meta_key ) {
			$columns[] = [
				'key'      => self::META_COLUMN_PREFIX . $meta_key,
				'label'    => sprintf( __( 'Meta: %s', 'spread-em' ), $meta_key ),
				'readonly' => false,
			];
		}

		return $columns;
	}
}
