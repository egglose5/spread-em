<?php
/**
 * Change-log admin page for Spread Em.
 *
 * Provides a "Spread Em Log" entry under the WooCommerce menu showing every
 * field-level change made through the spreadsheet editor.  Each row displays
 * who made the change and includes a Revert button that re-applies the
 * previous value via an AJAX request.
 *
 * @package SpreadEm
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SpreadEm_Log_Page
 */
class SpreadEm_Log_Page {

	/** @var string Admin page slug. */
	const PAGE_SLUG = 'spread-em-log';

	/** @var string AJAX action name for reverting a change. */
	const REVERT_ACTION = 'spread_em_revert';

	/** @var string AJAX action name for reverting an entire save state. */
	const REVERT_SAVE_ACTION = 'spread_em_revert_save_state';

	/**
	 * Register WordPress hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'wp_ajax_' . self::REVERT_ACTION, [ __CLASS__, 'handle_revert' ] );
		add_action( 'wp_ajax_' . self::REVERT_SAVE_ACTION, [ __CLASS__, 'handle_revert_save_state' ] );
	}

	/**
	 * Register the log page as a sub-menu item under WooCommerce.
	 */
	public static function register_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Spread Em Log', 'spread-em' ),
			__( 'Spread Em Log', 'spread-em' ),
			'edit_products',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Render the change-log admin page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'spread-em' ) );
		}

		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$offset  = ( $current - 1 ) * $per_page;

		$filter_args = [ 'limit' => $per_page, 'offset' => $offset ];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['product_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter_args['product_id'] = absint( $_GET['product_id'] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['user_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter_args['user_id'] = absint( $_GET['user_id'] );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['save_state_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter_args['save_state_id'] = sanitize_key( wp_unslash( $_GET['save_state_id'] ) );
		}

		$entries      = SpreadEm_Logger::get_entries( $filter_args );
		$total        = SpreadEm_Logger::count_entries( $filter_args );
		$total_pages  = (int) ceil( $total / $per_page );
		$revert_nonce = wp_create_nonce( 'spread_em_revert_nonce' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spread Em – Change Log', 'spread-em' ); ?></h1>

			<?php self::render_filters( $filter_args ); ?>

			<p>
				<?php
				/* translators: %d: total number of log entries */
				printf( esc_html__( 'Total entries: %d', 'spread-em' ), (int) $total );
				?>
			</p>

			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No changes have been logged yet.', 'spread-em' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:50px;"><?php esc_html_e( 'ID', 'spread-em' ); ?></th>
							<th style="width:130px;"><?php esc_html_e( 'Save State', 'spread-em' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Date', 'spread-em' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'User', 'spread-em' ); ?></th>
							<th style="width:160px;"><?php esc_html_e( 'Product', 'spread-em' ); ?></th>
							<th style="width:130px;"><?php esc_html_e( 'Field', 'spread-em' ); ?></th>
							<th><?php esc_html_e( 'Old Value', 'spread-em' ); ?></th>
							<th><?php esc_html_e( 'New Value', 'spread-em' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Status', 'spread-em' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Actions', 'spread-em' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) :
							$save_state_id = ! empty( $entry['save_state_id'] ) ? (string) $entry['save_state_id'] : '';
							$user_info    = get_userdata( (int) $entry['user_id'] );
							$user_label   = $user_info
								? '<a href="' . esc_url( get_edit_user_link( (int) $entry['user_id'] ) ) . '">' . esc_html( $user_info->display_name ) . '</a>'
								: '#' . (int) $entry['user_id'];
							$product      = wc_get_product( (int) $entry['product_id'] );
							$product_link = $product
								? '<a href="' . esc_url( (string) get_edit_post_link( (int) $entry['product_id'] ) ) . '">#' . (int) $entry['product_id'] . ' ' . esc_html( $product->get_name() ) . '</a>'
								: '#' . (int) $entry['product_id'];
							$is_reverted  = (bool) $entry['reverted'];
						?>
						<tr id="spread-em-log-row-<?php echo (int) $entry['id']; ?>"
							<?php echo $is_reverted ? 'style="opacity:0.6;"' : ''; ?>>
							<td><?php echo (int) $entry['id']; ?></td>
							<td>
								<?php if ( '' !== $save_state_id ) : ?>
									<code><?php echo esc_html( substr( $save_state_id, 0, 8 ) ); ?></code>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( get_date_from_gmt( $entry['changed_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
							<td><?php echo $user_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo $product_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><code><?php echo esc_html( $entry['field'] ); ?></code></td>
							<td><span class="spread-em-log-value"><?php echo esc_html( $entry['old_value'] ); ?></span></td>
							<td><span class="spread-em-log-value"><?php echo esc_html( $entry['new_value'] ); ?></span></td>
							<td>
								<?php if ( $is_reverted ) : ?>
									<span class="spread-em-badge-reverted"><?php esc_html_e( 'Reverted', 'spread-em' ); ?></span>
								<?php else : ?>
									<span class="spread-em-badge-active"><?php esc_html_e( 'Active', 'spread-em' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $is_reverted ) : ?>
									<button type="button"
										class="button button-small spread-em-revert-btn"
										data-log-id="<?php echo (int) $entry['id']; ?>"
										data-nonce="<?php echo esc_attr( $revert_nonce ); ?>"
										data-confirm="<?php esc_attr_e( 'Revert this change? The old value will be restored.', 'spread-em' ); ?>">
										<?php esc_html_e( 'Revert', 'spread-em' ); ?>
									</button>
									<?php if ( '' !== $save_state_id ) : ?>
										<button type="button"
											class="button button-small spread-em-revert-save-btn"
											data-save-state-id="<?php echo esc_attr( $save_state_id ); ?>"
											data-nonce="<?php echo esc_attr( $revert_nonce ); ?>"
											data-confirm="<?php esc_attr_e( 'Revert this entire save state? All its field changes will be restored.', 'spread-em' ); ?>">
											<?php esc_html_e( 'Revert Save', 'spread-em' ); ?>
										</button>
									<?php endif; ?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php self::render_pagination( $current, $total_pages, $filter_args ); ?>
			<?php endif; ?>
		</div>

		<style>
			.spread-em-log-value { display:block; max-width:250px; white-space:pre-wrap; word-break:break-all; font-size:12px; }
			.spread-em-badge-reverted { background:#dba617; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px; white-space:nowrap; }
			.spread-em-badge-active   { background:#00a32a; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px; white-space:nowrap; }
		</style>

		<script>
		(function ($) {
			$('.spread-em-revert-btn').on('click', function () {
				var $btn  = $(this);
				var logId = $btn.data('log-id');
				var nonce = $btn.data('nonce');
				var msg   = $btn.data('confirm');

				if (!confirm(msg)) {
					return;
				}

				$btn.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Reverting…', 'spread-em' ) ); ?>);

				$.post(
					<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
					{
						action: <?php echo wp_json_encode( self::REVERT_ACTION ); ?>,
						nonce:  nonce,
						log_id: logId
					},
					function (response) {
						if (response.success) {
							var $row = $('#spread-em-log-row-' + logId);
							$row.css('opacity', '0.6');
							$row.find('.spread-em-badge-active')
								.removeClass('spread-em-badge-active')
								.addClass('spread-em-badge-reverted')
								.text(<?php echo wp_json_encode( __( 'Reverted', 'spread-em' ) ); ?>);
							$btn.closest('td').html('&mdash;');
						} else {
							alert(
								response.data && response.data.message
									? response.data.message
									: <?php echo wp_json_encode( __( 'Revert failed. Please try again.', 'spread-em' ) ); ?>
							);
							$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Revert', 'spread-em' ) ); ?>);
						}
					}
				).fail(function () {
					alert(<?php echo wp_json_encode( __( 'Revert failed. Please try again.', 'spread-em' ) ); ?>);
					$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Revert', 'spread-em' ) ); ?>);
				});
			});

			$('.spread-em-revert-save-btn').on('click', function () {
				var $btn = $(this);
				var saveStateId = $btn.data('save-state-id');
				var nonce = $btn.data('nonce');
				var msg = $btn.data('confirm');

				if (!confirm(msg)) {
					return;
				}

				$btn.prop('disabled', true).text(<?php echo wp_json_encode( __( 'Reverting…', 'spread-em' ) ); ?>);

				$.post(
					<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
					{
						action: <?php echo wp_json_encode( self::REVERT_SAVE_ACTION ); ?>,
						nonce: nonce,
						save_state_id: saveStateId
					},
					function (response) {
						if (response.success) {
							window.location.reload();
							return;
						}

						alert(
							response.data && response.data.message
								? response.data.message
								: <?php echo wp_json_encode( __( 'Revert failed. Please try again.', 'spread-em' ) ); ?>
						);
						$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Revert Save', 'spread-em' ) ); ?>);
					}
				).fail(function () {
					alert(<?php echo wp_json_encode( __( 'Revert failed. Please try again.', 'spread-em' ) ); ?>);
					$btn.prop('disabled', false).text(<?php echo wp_json_encode( __( 'Revert Save', 'spread-em' ) ); ?>);
				});
			});
		}(jQuery));
		</script>
		<?php
	}

	/**
	 * Render the filter form above the log table.
	 *
	 * @param array<string, mixed> $current_filters Currently applied filter arguments.
	 */
	private static function render_filters( array $current_filters ): void {
		?>
		<form method="get" action="">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<p class="search-box">
				<label for="spread-em-log-product"><?php esc_html_e( 'Product ID:', 'spread-em' ); ?></label>
				<input type="number" id="spread-em-log-product" name="product_id" min="1"
					value="<?php echo ! empty( $current_filters['product_id'] ) ? (int) $current_filters['product_id'] : ''; ?>">

				<label for="spread-em-log-user" style="margin-left:10px;">
					<?php esc_html_e( 'User ID:', 'spread-em' ); ?>
				</label>
				<input type="number" id="spread-em-log-user" name="user_id" min="1"
					value="<?php echo ! empty( $current_filters['user_id'] ) ? (int) $current_filters['user_id'] : ''; ?>">

				<label for="spread-em-log-save-state" style="margin-left:10px;">
					<?php esc_html_e( 'Save State:', 'spread-em' ); ?>
				</label>
				<input type="text" id="spread-em-log-save-state" name="save_state_id"
					value="<?php echo ! empty( $current_filters['save_state_id'] ) ? esc_attr( (string) $current_filters['save_state_id'] ) : ''; ?>">

				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'spread-em' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button">
					<?php esc_html_e( 'Reset', 'spread-em' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	/**
	 * Render simple numbered pagination links.
	 *
	 * @param int                  $current      Current page number.
	 * @param int                  $total_pages  Total number of pages.
	 * @param array<string, mixed> $filters      Active filter arguments.
	 */
	private static function render_pagination( int $current, int $total_pages, array $filters ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( ! empty( $filters['product_id'] ) ) {
			$base_url = add_query_arg( 'product_id', (int) $filters['product_id'], $base_url );
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$base_url = add_query_arg( 'user_id', (int) $filters['user_id'], $base_url );
		}

		if ( ! empty( $filters['save_state_id'] ) ) {
			$base_url = add_query_arg( 'save_state_id', sanitize_key( (string) $filters['save_state_id'] ), $base_url );
		}

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';

		for ( $i = 1; $i <= $total_pages; $i++ ) {
			$url = add_query_arg( 'paged', $i, $base_url );
			if ( $i === $current ) {
				echo '<span class="current" style="margin:0 2px;">' . (int) $i . '</span>';
			} else {
				echo '<a href="' . esc_url( $url ) . '" class="page-numbers" style="margin:0 2px;">' . (int) $i . '</a>';
			}
		}

		echo '</div></div>';
	}

	/**
	 * AJAX handler for the revert action.
	 *
	 * Expects POST fields: nonce, log_id.
	 * Re-applies the old value for the logged field, logs the reversal, and
	 * marks the original entry as reverted.
	 */
	public static function handle_revert(): void {
		if ( ! check_ajax_referer( 'spread_em_revert_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to revert changes.', 'spread-em' ) ], 403 );
		}

		$log_id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;

		if ( ! $log_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid log ID.', 'spread-em' ) ], 400 );
		}

		$entry = SpreadEm_Logger::get_entry( $log_id );

		if ( ! $entry ) {
			wp_send_json_error( [ 'message' => __( 'Log entry not found.', 'spread-em' ) ], 404 );
		}

		if ( (bool) $entry['reverted'] ) {
			wp_send_json_error( [ 'message' => __( 'This change has already been reverted.', 'spread-em' ) ], 409 );
		}

		$product_id = (int) $entry['product_id'];
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json_error(
				[
					/* translators: %d: product ID */
					'message' => sprintf( __( 'Product %d not found.', 'spread-em' ), $product_id ),
				],
				404
			);
		}

		// Build a minimal row containing only the field being reverted.
		$row = [
			'id'            => $product_id,
			$entry['field'] => $entry['old_value'],
		];

		$result = SpreadEm_Ajax::apply_row_to_product( $product, $row );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 422 );
		}

		// Record the reversal as a new log entry (old↔new swapped).
		SpreadEm_Logger::log_change(
			get_current_user_id(),
			$product_id,
			$entry['field'],
			$entry['new_value'],
			$entry['old_value']
		);

		SpreadEm_Logger::mark_reverted( $log_id );

		wp_send_json_success( [ 'log_id' => $log_id ] );
	}

	/**
	 * AJAX handler to revert all entries in one save state.
	 */
	public static function handle_revert_save_state(): void {
		if ( ! check_ajax_referer( 'spread_em_revert_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to revert changes.', 'spread-em' ) ], 403 );
		}

		$save_state_id = isset( $_POST['save_state_id'] ) ? sanitize_key( wp_unslash( $_POST['save_state_id'] ) ) : '';

		if ( '' === $save_state_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid save state ID.', 'spread-em' ) ], 400 );
		}

		$entries = SpreadEm_Logger::get_entries_by_save_state( $save_state_id );

		if ( empty( $entries ) ) {
			wp_send_json_error( [ 'message' => __( 'No entries found for this save state.', 'spread-em' ) ], 404 );
		}

		$active_entries = array_values(
			array_filter(
				$entries,
				static function ( $entry ) {
					return isset( $entry['reverted'] ) && ! (bool) $entry['reverted'];
				}
			)
		);

		if ( empty( $active_entries ) ) {
			wp_send_json_error( [ 'message' => __( 'This save state has already been reverted.', 'spread-em' ) ], 409 );
		}

		$reverted_count  = 0;
		$error_messages  = [];
		$revert_state_id = sanitize_key( wp_generate_uuid4() );

		foreach ( $active_entries as $entry ) {
			$product_id = isset( $entry['product_id'] ) ? (int) $entry['product_id'] : 0;
			$field      = isset( $entry['field'] ) ? (string) $entry['field'] : '';
			$old_value  = isset( $entry['old_value'] ) ? (string) $entry['old_value'] : '';
			$new_value  = isset( $entry['new_value'] ) ? (string) $entry['new_value'] : '';
			$entry_id   = isset( $entry['id'] ) ? (int) $entry['id'] : 0;

			if ( ! $product_id || '' === $field || ! $entry_id ) {
				$error_messages[] = __( 'One log entry was invalid and could not be reverted.', 'spread-em' );
				continue;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				/* translators: %d: product ID */
				$error_messages[] = sprintf( __( 'Product %d not found while reverting save state.', 'spread-em' ), $product_id );
				continue;
			}

			$row = [
				'id'    => $product_id,
				$field  => $old_value,
			];

			$result = SpreadEm_Ajax::apply_row_to_product( $product, $row );

			if ( is_wp_error( $result ) ) {
				/* translators: %1$s: field name, %2$s: error message */
				$error_messages[] = sprintf( __( 'Field %1$s failed to revert: %2$s', 'spread-em' ), $field, $result->get_error_message() );
				continue;
			}

			SpreadEm_Logger::log_change(
				get_current_user_id(),
				$product_id,
				$field,
				$new_value,
				$old_value,
				$revert_state_id
			);

			SpreadEm_Logger::mark_reverted( $entry_id );
			$reverted_count++;
		}

		if ( ! empty( $error_messages ) ) {
			wp_send_json_error(
				[
					'message'  => implode( "\n", array_unique( $error_messages ) ),
					'reverted' => $reverted_count,
				],
				422
			);
		}

		wp_send_json_success(
			[
				'save_state_id' => $save_state_id,
				'reverted'      => $reverted_count,
			]
		);
	}
}
