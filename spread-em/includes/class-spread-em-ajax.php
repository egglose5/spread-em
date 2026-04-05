<?php
/**
 * AJAX handler for Spread Em.
 *
 * Handles the single "save all changes" AJAX request sent by the
 * spreadsheet editor.  Each changed row is validated and written to the
 * WooCommerce product via the WC_Product API, mirroring the fields that
 * the WC CSV importer/exporter uses.
 *
 * @package SpreadEm
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SpreadEm_Ajax
 */
class SpreadEm_Ajax {

	/** @var string AJAX action name. */
	const ACTION = 'spread_em_save';

	/** @var string AJAX action for pushing one live cell update. */
	const ACTION_LIVE_PUSH = 'spread_em_live_push';

	/** @var string AJAX action for pulling live session state. */
	const ACTION_LIVE_PULL = 'spread_em_live_pull';

	/** @var string AJAX action for direct live messaging. */
	const ACTION_LIVE_IM_SEND = 'spread_em_live_im_send';

	/** @var string AJAX action: save a draft field delta (idempotent, short TTL). */
	const ACTION_SAVE_DRAFT = 'spread_em_save_draft';

	/** @var string AJAX action: poll for draft deltas since a given token. */
	const ACTION_POLL_DRAFT = 'spread_em_poll_draft';

	/** @var string User meta key containing live workspace scope. */
	const LIVE_SCOPE_META_KEY = 'spread_em_live_scope';

	/** @var int Max activity events retained in live state. */
	const LIVE_ACTIVITY_MAX = 120;

	/**
	 * Draft transient TTL in seconds.
	 *
	 * Drafts are short-lived; this keeps the DB lean and ensures ghost entries
	 * expire automatically without a cleanup job.
	 */
	const DRAFT_TTL = 120;

	/**
	 * Register AJAX hooks (logged-in users only).
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle_save' ] );
		add_action( 'wp_ajax_' . self::ACTION_LIVE_PUSH, [ __CLASS__, 'handle_live_push' ] );
		add_action( 'wp_ajax_' . self::ACTION_LIVE_PULL, [ __CLASS__, 'handle_live_pull' ] );
		add_action( 'wp_ajax_' . self::ACTION_LIVE_IM_SEND, [ __CLASS__, 'handle_live_im_send' ] );
		add_action( 'wp_ajax_' . self::ACTION_SAVE_DRAFT, [ __CLASS__, 'handle_save_draft' ] );
		add_action( 'wp_ajax_' . self::ACTION_POLL_DRAFT, [ __CLASS__, 'handle_poll_draft' ] );
	}

	/**
	 * Handle the save request.
	 *
	 * Expects a POST body with:
	 *   nonce   – security nonce
	 *   changes – JSON-encoded array of changed product rows
	 *             Each row must contain at least an "id" key.
	 */
	public static function handle_save(): void {
		// 1. Verify nonce.
		if ( ! check_ajax_referer( 'spread_em_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		// 2. Verify capability.
		if ( ! SpreadEm_Permissions::current_user_can_use_editor() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit products.', 'spread-em' ) ], 403 );
		}

		// 3. Decode and validate the changes payload.
		$raw_changes     = isset( $_POST['changes'] ) ? wp_unslash( $_POST['changes'] ) : '';
		$changes         = json_decode( $raw_changes, true );
		$live_session_id = isset( $_POST['live_session_id'] ) ? sanitize_key( wp_unslash( $_POST['live_session_id'] ) ) : '';

		if ( ! is_array( $changes ) || empty( $changes ) ) {
			wp_send_json_error( [ 'message' => __( 'No changes received.', 'spread-em' ) ], 400 );
		}

		// 4. Process each changed row.
		$save_state_id = sanitize_key( wp_generate_uuid4() );
		$saved  = [];
		$errors = [];

		foreach ( $changes as $row ) {
			if ( empty( $row['id'] ) || ! is_numeric( $row['id'] ) ) {
				$errors[] = __( 'Row is missing a valid product ID and was skipped.', 'spread-em' );
				continue;
			}

			$product_id = (int) $row['id'];

			if ( ! self::current_user_can_access_product( $product_id ) ) {
				/* translators: %d: product ID */
				$errors[] = sprintf( __( 'You do not have access to edit product %d in this workspace.', 'spread-em' ), $product_id );
				continue;
			}

			$product    = wc_get_product( $product_id );

			if ( ! $product ) {
				/* translators: %d: product ID */
				$errors[] = sprintf( __( 'Product %d not found.', 'spread-em' ), $product_id );
				continue;
			}

			// Snapshot the current field values before applying the change.
			$old_snapshot = self::snapshot_product( $product, $row );

			$result = self::apply_row_to_product( $product, $row );

			if ( is_wp_error( $result ) ) {
				/* translators: %1$d: product ID, %2$s: error message */
				$errors[] = sprintf( __( 'Product %1$d: %2$s', 'spread-em' ), $product_id, $result->get_error_message() );
			} else {
				$saved[] = $product_id;

				// Log each field that actually changed.
				if ( class_exists( 'SpreadEm_Logger' ) ) {
					$user_id      = get_current_user_id();
					$saved_product = wc_get_product( $product_id );

					if ( $saved_product ) {
						$new_snapshot = self::snapshot_product( $saved_product, $row );

						foreach ( $old_snapshot as $field => $old_value ) {
							$new_value = isset( $new_snapshot[ $field ] ) ? $new_snapshot[ $field ] : '';
							if ( $old_value !== $new_value ) {
								SpreadEm_Logger::log_change( $user_id, $product_id, $field, $old_value, $new_value, $save_state_id );
							}
						}
					}
				}
			}
		}

		if ( ! empty( $errors ) ) {
			wp_send_json_error(
				[
					'message' => implode( "\n", $errors ),
					'saved'   => $saved,
				],
				422
			);
		}

		$parent_ids = self::get_context_parent_ids( $saved );
		$products   = [];

		if ( '' !== $live_session_id && ! empty( $saved ) ) {
			$state = self::get_live_state( $live_session_id );
			$state['activity'] = isset( $state['activity'] ) && is_array( $state['activity'] ) ? $state['activity'] : [];
			$state['activity'] = self::append_live_activity_event(
				$state['activity'],
				[
					'type'          => 'save',
					'user_id'       => get_current_user_id(),
					'user_name'     => self::get_current_editor_name(),
					'save_state_id' => $save_state_id,
					'rows'          => count( $saved ),
					'ts'            => time(),
				]
			);
			$state['updated_at'] = current_time( 'mysql', true );
			self::save_live_state( $live_session_id, $state );
		}

		if ( class_exists( 'SpreadEm_Admin' ) && method_exists( 'SpreadEm_Admin', 'get_products_for_editor' ) ) {
			$products = SpreadEm_Admin::get_products_for_editor( $parent_ids );
		}

		wp_send_json_success(
			[
				'saved'          => $saved,
				'products'       => $products,
				'save_state_id'  => $save_state_id,
			]
		);
	}

	/**
	 * Push one live cell update into the shared session state.
	 */
	public static function handle_live_push(): void {
		if ( ! check_ajax_referer( 'spread_em_live_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		if ( ! SpreadEm_Permissions::current_user_can_live_collaborate() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit products.', 'spread-em' ) ], 403 );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'update';
		$key        = isset( $_POST['key'] ) ? (string) wp_unslash( $_POST['key'] ) : '';
		$value      = isset( $_POST['value'] ) ? (string) wp_unslash( $_POST['value'] ) : '';

		$key = sanitize_text_field( $key );

		if ( '' === $session_id || ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid live update payload.', 'spread-em' ) ], 400 );
		}

		if ( 'claim' !== $mode && ( '' === $key || 'id' === $key ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid live update payload.', 'spread-em' ) ], 400 );
		}

		if ( ! self::current_user_can_access_product( $product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have access to update this product in the live workspace.', 'spread-em' ) ], 403 );
		}

		$state = self::get_live_state( $session_id );

		if ( ! isset( $state['cells'] ) || ! is_array( $state['cells'] ) ) {
			$state['cells'] = [];
		}

		if ( ! isset( $state['row_owners'] ) || ! is_array( $state['row_owners'] ) ) {
			$state['row_owners'] = [];
		}

		if ( 'claim' === $mode ) {
			$state['row_owners'] = self::touch_live_row_owner( $state['row_owners'], $product_id );
		}

		if ( ! isset( $state['activity'] ) || ! is_array( $state['activity'] ) ) {
			$state['activity'] = [];
		}

		if ( 'claim' !== $mode ) {
			if ( ! isset( $state['cells'][ $product_id ] ) || ! is_array( $state['cells'][ $product_id ] ) ) {
				$state['cells'][ $product_id ] = [];
			}

			$state['cells'][ $product_id ][ $key ] = $value;
			$state['version'] = isset( $state['version'] ) ? ( (int) $state['version'] + 1 ) : 1;
			$state['activity'] = self::append_live_activity_event(
				$state['activity'],
				[
					'type'       => 'edit',
					'user_id'    => get_current_user_id(),
					'user_name'  => self::get_current_editor_name(),
					'product_id' => $product_id,
					'field'      => $key,
					'ts'         => time(),
				]
			);
		} else {
			$state['activity'] = self::append_live_activity_event(
				$state['activity'],
				[
					'type'       => 'claim',
					'user_id'    => get_current_user_id(),
					'user_name'  => self::get_current_editor_name(),
					'product_id' => $product_id,
					'ts'         => time(),
				]
			);
		}

		if ( ! isset( $state['version'] ) ) {
			$state['version'] = 0;
		}
		$state['updated_at'] = current_time( 'mysql', true );

		$presence      = isset( $state['presence'] ) && is_array( $state['presence'] ) ? $state['presence'] : [];
		$current_scope = self::get_current_user_live_scope();
		$presence = self::prune_live_presence( $presence );
		$presence[ get_current_user_id() ] = [
			'name'       => self::get_current_editor_name(),
			'ts'         => time(),
			'scope_mode' => isset( $current_scope['scope_mode'] ) ? (string) $current_scope['scope_mode'] : 'individual_contributor',
		];
		$state['presence'] = $presence;

		self::save_live_state( $session_id, $state );

		wp_send_json_success( [ 'version' => (int) $state['version'] ] );
	}

	/**
	 * Pull shared live session state and heartbeat current user's presence.
	 */
	public static function handle_live_pull(): void {
		if ( ! check_ajax_referer( 'spread_em_live_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		if ( ! SpreadEm_Permissions::current_user_can_live_collaborate() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit products.', 'spread-em' ) ], 403 );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
		$since      = isset( $_POST['since_version'] ) ? absint( $_POST['since_version'] ) : 0;

		if ( '' === $session_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid session ID.', 'spread-em' ) ], 400 );
		}

		$state = self::get_live_state( $session_id );
		$state['version'] = isset( $state['version'] ) ? (int) $state['version'] : 0;
		$state['cells']   = isset( $state['cells'] ) && is_array( $state['cells'] ) ? $state['cells'] : [];
		$state['cells']   = self::filter_live_cells_for_current_user( $state['cells'] );
		$state['row_owners'] = isset( $state['row_owners'] ) && is_array( $state['row_owners'] ) ? $state['row_owners'] : [];
		$state['row_owners'] = self::prune_live_row_owners( $state['row_owners'] );
		$state['row_owners'] = self::filter_live_row_owners_for_current_user( $state['row_owners'] );
		$state['direct_messages'] = isset( $state['direct_messages'] ) && is_array( $state['direct_messages'] ) ? $state['direct_messages'] : [];
		$state['direct_messages'] = self::prune_live_direct_messages( $state['direct_messages'] );
		$state['activity'] = isset( $state['activity'] ) && is_array( $state['activity'] ) ? $state['activity'] : [];
		$state['activity'] = self::prune_live_activity( $state['activity'] );

		$presence      = isset( $state['presence'] ) && is_array( $state['presence'] ) ? $state['presence'] : [];
		$current_scope = self::get_current_user_live_scope();
		$presence = self::prune_live_presence( $presence );
		$presence[ get_current_user_id() ] = [
			'name'       => self::get_current_editor_name(),
			'ts'         => time(),
			'scope_mode' => isset( $current_scope['scope_mode'] ) ? (string) $current_scope['scope_mode'] : 'individual_contributor',
		];
		$state['presence'] = $presence;

		self::save_live_state( $session_id, $state );

		$has_updates = $state['version'] > $since;

		wp_send_json_success(
			[
				'version'     => $state['version'],
				'has_updates' => $has_updates,
				'cells'       => $has_updates ? $state['cells'] : [],
				'row_owners'  => $state['row_owners'],
				'direct_messages' => self::filter_live_direct_messages_for_current_user( $state['direct_messages'] ),
				'activity'    => self::filter_live_activity_for_current_user( $state['activity'] ),
				'presence'    => $state['presence'],
			]
		);
	}

	/**
	 * Send a direct IM to an active user in the live workspace.
	 */
	public static function handle_live_im_send(): void {
		if ( ! check_ajax_referer( 'spread_em_live_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		if ( ! SpreadEm_Permissions::current_user_can_send_im() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to use live messaging.', 'spread-em' ) ], 403 );
		}

		$session_id = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
		$to_user_id = isset( $_POST['to_user_id'] ) ? absint( $_POST['to_user_id'] ) : 0;
		$message    = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( '' === $session_id || ! $to_user_id || '' === trim( $message ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid direct message payload.', 'spread-em' ) ], 400 );
		}

		$state = self::get_live_state( $session_id );
		$presence = isset( $state['presence'] ) && is_array( $state['presence'] ) ? $state['presence'] : [];
		$presence = self::prune_live_presence( $presence );

		if ( ! isset( $presence[ $to_user_id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'That user is not currently active in this workspace.', 'spread-em' ) ], 409 );
		}

		if ( ! isset( $state['direct_messages'] ) || ! is_array( $state['direct_messages'] ) ) {
			$state['direct_messages'] = [];
		}

		$state['direct_messages'][] = [
			'id'           => sanitize_key( wp_generate_uuid4() ),
			'from_user_id' => get_current_user_id(),
			'to_user_id'   => $to_user_id,
			'from_name'    => self::get_current_editor_name(),
			'message'      => $message,
			'ts'           => time(),
		];

		$state['direct_messages'] = self::prune_live_direct_messages( $state['direct_messages'] );
		$state['activity'] = isset( $state['activity'] ) && is_array( $state['activity'] ) ? $state['activity'] : [];
		$state['activity'] = self::append_live_activity_event(
			$state['activity'],
			[
				'type'         => 'im',
				'user_id'      => get_current_user_id(),
				'user_name'    => self::get_current_editor_name(),
				'to_user_id'   => $to_user_id,
				'to_user_name' => isset( $presence[ $to_user_id ]['name'] ) ? (string) $presence[ $to_user_id ]['name'] : '',
				'ts'           => time(),
			]
		);
		$state['presence'] = $presence;
		self::save_live_state( $session_id, $state );

		wp_send_json_success( [ 'sent' => true ] );
	}

	/**
	 * Store a single draft field delta server-side.
	 *
	 * Accepts a debounced cell change from the editor and persists it to a
	 * short-lived draft transient (DRAFT_TTL seconds).  The client_request_id
	 * makes the operation idempotent so retries on network failure never
	 * duplicate an edit.  Returns a monotonically increasing token that
	 * clients pass to poll_draft as since_token.
	 */
	public static function handle_save_draft(): void {
		if ( ! check_ajax_referer( 'spread_em_live_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		if ( ! SpreadEm_Permissions::current_user_can_live_collaborate() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit products.', 'spread-em' ) ], 403 );
		}

		$session_id      = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
		$client_req_id   = isset( $_POST['client_request_id'] ) ? sanitize_key( wp_unslash( $_POST['client_request_id'] ) ) : '';
		$product_id      = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$key             = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$value           = isset( $_POST['value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['value'] ) ) : '';

		if ( '' === $session_id || '' === $client_req_id || ! $product_id || '' === $key || 'id' === $key ) {
			wp_send_json_error( [ 'message' => __( 'Invalid draft payload.', 'spread-em' ) ], 400 );
		}

		if ( ! self::current_user_can_access_product( $product_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have access to update this product in the live workspace.', 'spread-em' ) ], 403 );
		}

		$draft = self::get_draft_state( $session_id );

		// Idempotency: if this exact request was already applied, return the
		// same token without touching the draft data.
		$seen = isset( $draft['seen_request_ids'] ) && is_array( $draft['seen_request_ids'] )
			? $draft['seen_request_ids']
			: [];

		if ( in_array( $client_req_id, $seen, true ) ) {
			wp_send_json_success( [ 'token' => (int) ( $draft['version'] ?? 0 ) ] );
			return;
		}

		// Apply the delta.
		if ( ! isset( $draft['cells'][ $product_id ] ) || ! is_array( $draft['cells'][ $product_id ] ) ) {
			$draft['cells'][ $product_id ] = [];
		}

		$draft['cells'][ $product_id ][ $key ] = $value;
		$draft['version'] = (int) ( $draft['version'] ?? 0 ) + 1;

		// Track seen request IDs (bounded to last 100 to avoid unbounded growth).
		$seen[]                    = $client_req_id;
		$draft['seen_request_ids'] = array_slice( $seen, -100 );

		self::save_draft_state( $session_id, $draft );

		wp_send_json_success( [ 'token' => (int) $draft['version'] ] );
	}

	/**
	 * Return draft deltas that occurred after the given since_token.
	 *
	 * This endpoint is deliberately cheap: when the draft version has not
	 * advanced beyond since_token it returns immediately without a DB write,
	 * making it safe to call on every poll tick on shared hosting.
	 */
	public static function handle_poll_draft(): void {
		if ( ! check_ajax_referer( 'spread_em_live_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'spread-em' ) ], 403 );
		}

		if ( ! SpreadEm_Permissions::current_user_can_live_collaborate() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit products.', 'spread-em' ) ], 403 );
		}

		$session_id  = isset( $_POST['session_id'] ) ? sanitize_key( wp_unslash( $_POST['session_id'] ) ) : '';
		$since_token = isset( $_POST['since_token'] ) ? absint( $_POST['since_token'] ) : 0;

		if ( '' === $session_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid session ID.', 'spread-em' ) ], 400 );
		}

		$draft   = self::get_draft_state( $session_id );
		$version = (int) ( $draft['version'] ?? 0 );

		// Short-circuit: nothing has changed since the client's last token.
		if ( $version <= $since_token ) {
			wp_send_json_success( [ 'has_updates' => false, 'token' => $version ] );
			return;
		}

		$cells = isset( $draft['cells'] ) && is_array( $draft['cells'] ) ? $draft['cells'] : [];
		$cells = self::filter_live_cells_for_current_user( $cells );

		wp_send_json_success(
			[
				'has_updates' => true,
				'token'       => $version,
				'cells'       => $cells,
			]
		);
	}

	/**
	 * Build transient key for one live session.
	 *
	 * @param string $session_id Live session ID.
	 * @return string
	 */
	private static function live_transient_key( string $session_id ): string {
		return 'spread_em_live_' . $session_id;
	}

	/**
	 * Build transient key for a draft session.
	 *
	 * Draft keys are intentionally separate from the live-session keys so
	 * that the different TTLs don't interfere with presence or IM state.
	 *
	 * @param string $session_id Draft session ID.
	 * @return string
	 */
	private static function draft_transient_key( string $session_id ): string {
		return 'spread_em_draft_' . $session_id;
	}

	/**
	 * Fetch draft state from transient storage.
	 *
	 * @param string $session_id Draft session ID.
	 * @return array<string,mixed>
	 */
	private static function get_draft_state( string $session_id ): array {
		$draft = get_transient( self::draft_transient_key( $session_id ) );
		return is_array( $draft ) ? $draft : [];
	}

	/**
	 * Persist draft state with a short TTL to auto-expire ghost entries.
	 *
	 * @param string             $session_id Draft session ID.
	 * @param array<string,mixed> $draft     Draft payload.
	 */
	private static function save_draft_state( string $session_id, array $draft ): void {
		set_transient( self::draft_transient_key( $session_id ), $draft, self::DRAFT_TTL );
	}

	/**
	 * Fetch live session state from transient storage.
	 *
	 * @param string $session_id Live session ID.
	 * @return array<string,mixed>
	 */
	private static function get_live_state( string $session_id ): array {
		$state = get_transient( self::live_transient_key( $session_id ) );
		return is_array( $state ) ? $state : [];
	}

	/**
	 * Get current user's allowed live-workspace scope.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_current_user_live_scope(): array {
		$scope = get_user_meta( get_current_user_id(), self::LIVE_SCOPE_META_KEY, true );
		$can_global = self::current_user_can_be_global_operator();

		if ( ! is_array( $scope ) ) {
			return [
				'scope_mode'     => 'individual_contributor',
				'full_workspace' => false,
				'product_ids'    => [],
			];
		}

		$scope_mode = isset( $scope['scope_mode'] ) ? sanitize_key( (string) $scope['scope_mode'] ) : 'individual_contributor';
		$scope_mode = ( $can_global && 'global_operator' === $scope_mode ) ? 'global_operator' : 'individual_contributor';

		$scope['scope_mode'] = $scope_mode;
		$scope['full_workspace'] = 'global_operator' === $scope_mode;
		$scope['product_ids']    = isset( $scope['product_ids'] ) && is_array( $scope['product_ids'] )
			? array_values( array_unique( array_map( 'absint', $scope['product_ids'] ) ) )
			: [];

		return $scope;
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
	 * Determine whether current user can access a product within the live workspace.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private static function current_user_can_access_product( int $product_id ): bool {
		$scope = self::get_current_user_live_scope();

		if ( ! empty( $scope['full_workspace'] ) ) {
			return true;
		}

		return in_array( $product_id, $scope['product_ids'], true );
	}

	/**
	 * Filter live cell map down to products current user is allowed to see.
	 *
	 * @param array<int|string,mixed> $cells Live cell state.
	 * @return array<int|string,mixed>
	 */
	private static function filter_live_cells_for_current_user( array $cells ): array {
		$scope = self::get_current_user_live_scope();

		if ( ! empty( $scope['full_workspace'] ) ) {
			return $cells;
		}

		$allowed_ids = array_flip( $scope['product_ids'] );
		$filtered    = [];

		foreach ( $cells as $product_id => $values ) {
			$product_id = absint( (int) $product_id );

			if ( ! isset( $allowed_ids[ $product_id ] ) ) {
				continue;
			}

			$filtered[ $product_id ] = is_array( $values ) ? $values : [];
		}

		return $filtered;
	}

	/**
	 * Refresh current user's ownership timestamp for a row.
	 *
	 * @param array<int|string,mixed> $row_owners Existing ownership map.
	 * @param int                     $product_id Product row ID.
	 * @return array<int|string,mixed>
	 */
	private static function touch_live_row_owner( array $row_owners, int $product_id ): array {
		$row_owners = self::prune_live_row_owners( $row_owners );

		if ( ! isset( $row_owners[ $product_id ] ) || ! is_array( $row_owners[ $product_id ] ) ) {
			$row_owners[ $product_id ] = [];
		}

		$row_owners[ $product_id ][ get_current_user_id() ] = [
			'name' => self::get_current_editor_name(),
			'ts'   => time(),
		];

		return $row_owners;
	}

	/**
	 * Remove stale row-owner entries.
	 *
	 * @param array<int|string,mixed> $row_owners Row ownership map.
	 * @return array<int|string,mixed>
	 */
	private static function prune_live_row_owners( array $row_owners ): array {
		$cutoff = time() - 30;
		$clean  = [];

		foreach ( $row_owners as $product_id => $owners ) {
			$product_id = absint( (int) $product_id );

			if ( ! $product_id || ! is_array( $owners ) ) {
				continue;
			}

			foreach ( $owners as $user_id => $owner ) {
				if ( ! is_array( $owner ) || empty( $owner['ts'] ) || (int) $owner['ts'] < $cutoff ) {
					continue;
				}

				$clean[ $product_id ][ $user_id ] = [
					'name' => isset( $owner['name'] ) ? (string) $owner['name'] : '',
					'ts'   => (int) $owner['ts'],
				];
			}
		}

		return $clean;
	}

	/**
	 * Trim direct messages to recent conversation history.
	 *
	 * @param array<int,mixed> $messages Direct message list.
	 * @return array<int,mixed>
	 */
	private static function prune_live_direct_messages( array $messages ): array {
		$cutoff = time() - HOUR_IN_SECONDS;
		$clean  = [];

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || empty( $message['ts'] ) || (int) $message['ts'] < $cutoff ) {
				continue;
			}

			$clean[] = [
				'id'           => isset( $message['id'] ) ? sanitize_key( (string) $message['id'] ) : '',
				'from_user_id' => isset( $message['from_user_id'] ) ? absint( (int) $message['from_user_id'] ) : 0,
				'to_user_id'   => isset( $message['to_user_id'] ) ? absint( (int) $message['to_user_id'] ) : 0,
				'from_name'    => isset( $message['from_name'] ) ? (string) $message['from_name'] : '',
				'message'      => isset( $message['message'] ) ? (string) $message['message'] : '',
				'ts'           => (int) $message['ts'],
			];
		}

		return array_slice( $clean, -100 );
	}

	/**
	 * Append one activity event and keep the feed bounded.
	 *
	 * @param array<int,mixed>    $activity Existing activity feed.
	 * @param array<string,mixed> $event    New event payload.
	 * @return array<int,mixed>
	 */
	private static function append_live_activity_event( array $activity, array $event ): array {
		$activity = self::prune_live_activity( $activity );

		$activity[] = [
			'id'           => sanitize_key( wp_generate_uuid4() ),
			'type'         => isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : 'activity',
			'user_id'      => isset( $event['user_id'] ) ? absint( (int) $event['user_id'] ) : 0,
			'user_name'    => isset( $event['user_name'] ) ? (string) $event['user_name'] : '',
			'product_id'   => isset( $event['product_id'] ) ? absint( (int) $event['product_id'] ) : 0,
			'field'        => isset( $event['field'] ) ? sanitize_text_field( (string) $event['field'] ) : '',
			'save_state_id'=> isset( $event['save_state_id'] ) ? sanitize_key( (string) $event['save_state_id'] ) : '',
			'rows'         => isset( $event['rows'] ) ? absint( (int) $event['rows'] ) : 0,
			'to_user_id'   => isset( $event['to_user_id'] ) ? absint( (int) $event['to_user_id'] ) : 0,
			'to_user_name' => isset( $event['to_user_name'] ) ? (string) $event['to_user_name'] : '',
			'ts'           => isset( $event['ts'] ) ? absint( (int) $event['ts'] ) : time(),
		];

		return array_slice( $activity, -1 * self::LIVE_ACTIVITY_MAX );
	}

	/**
	 * Prune stale activity and normalize structure.
	 *
	 * @param array<int,mixed> $activity Activity list.
	 * @return array<int,mixed>
	 */
	private static function prune_live_activity( array $activity ): array {
		$cutoff = time() - ( 30 * MINUTE_IN_SECONDS );
		$clean  = [];

		foreach ( $activity as $event ) {
			if ( ! is_array( $event ) || empty( $event['ts'] ) || (int) $event['ts'] < $cutoff ) {
				continue;
			}

			$clean[] = [
				'id'            => isset( $event['id'] ) ? sanitize_key( (string) $event['id'] ) : sanitize_key( wp_generate_uuid4() ),
				'type'          => isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : 'activity',
				'user_id'       => isset( $event['user_id'] ) ? absint( (int) $event['user_id'] ) : 0,
				'user_name'     => isset( $event['user_name'] ) ? (string) $event['user_name'] : '',
				'product_id'    => isset( $event['product_id'] ) ? absint( (int) $event['product_id'] ) : 0,
				'field'         => isset( $event['field'] ) ? sanitize_text_field( (string) $event['field'] ) : '',
				'save_state_id' => isset( $event['save_state_id'] ) ? sanitize_key( (string) $event['save_state_id'] ) : '',
				'rows'          => isset( $event['rows'] ) ? absint( (int) $event['rows'] ) : 0,
				'to_user_id'    => isset( $event['to_user_id'] ) ? absint( (int) $event['to_user_id'] ) : 0,
				'to_user_name'  => isset( $event['to_user_name'] ) ? (string) $event['to_user_name'] : '',
				'ts'            => (int) $event['ts'],
			];
		}

		return array_slice( $clean, -1 * self::LIVE_ACTIVITY_MAX );
	}

	/**
	 * Filter activity feed down to current user's visible scope.
	 *
	 * @param array<int,mixed> $activity Activity list.
	 * @return array<int,mixed>
	 */
	private static function filter_live_activity_for_current_user( array $activity ): array {
		$scope = self::get_current_user_live_scope();

		if ( ! empty( $scope['full_workspace'] ) ) {
			return $activity;
		}

		$allowed_ids = array_flip( $scope['product_ids'] );
		$current_id  = get_current_user_id();
		$filtered    = [];

		foreach ( $activity as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$event_product_id = isset( $event['product_id'] ) ? absint( (int) $event['product_id'] ) : 0;
			$event_user_id    = isset( $event['user_id'] ) ? absint( (int) $event['user_id'] ) : 0;
			$event_to_user_id = isset( $event['to_user_id'] ) ? absint( (int) $event['to_user_id'] ) : 0;

			if ( $event_product_id && isset( $allowed_ids[ $event_product_id ] ) ) {
				$filtered[] = $event;
				continue;
			}

			if ( $event_user_id === $current_id || $event_to_user_id === $current_id ) {
				$filtered[] = $event;
			}
		}

		return $filtered;
	}

	/**
	 * Return only direct messages involving the current user.
	 *
	 * @param array<int,mixed> $messages Direct message list.
	 * @return array<int,mixed>
	 */
	private static function filter_live_direct_messages_for_current_user( array $messages ): array {
		$current_user_id = get_current_user_id();
		$filtered = [];

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$from_user_id = isset( $message['from_user_id'] ) ? absint( (int) $message['from_user_id'] ) : 0;
			$to_user_id   = isset( $message['to_user_id'] ) ? absint( (int) $message['to_user_id'] ) : 0;

			if ( $from_user_id !== $current_user_id && $to_user_id !== $current_user_id ) {
				continue;
			}

			$filtered[] = $message;
		}

		return $filtered;
	}

	/**
	 * Filter row owners down to the current user's visible scope.
	 *
	 * @param array<int|string,mixed> $row_owners Ownership map.
	 * @return array<int|string,mixed>
	 */
	private static function filter_live_row_owners_for_current_user( array $row_owners ): array {
		$scope = self::get_current_user_live_scope();

		if ( ! empty( $scope['full_workspace'] ) ) {
			return $row_owners;
		}

		$allowed_ids = array_flip( $scope['product_ids'] );
		$filtered    = [];

		foreach ( $row_owners as $product_id => $owners ) {
			$product_id = absint( (int) $product_id );

			if ( ! isset( $allowed_ids[ $product_id ] ) ) {
				continue;
			}

			$filtered[ $product_id ] = is_array( $owners ) ? $owners : [];
		}

		return $filtered;
	}

	/**
	 * Persist live session state for short-lived collaborative editing.
	 *
	 * @param string             $session_id Live session ID.
	 * @param array<string,mixed> $state     Session state payload.
	 */
	private static function save_live_state( string $session_id, array $state ): void {
		set_transient( self::live_transient_key( $session_id ), $state, HOUR_IN_SECONDS );
	}

	/**
	 * Remove stale live presence entries.
	 *
	 * @param array<int|string,mixed> $presence Presence map keyed by user ID.
	 * @return array<int|string,mixed>
	 */
	private static function prune_live_presence( array $presence ): array {
		$cutoff = time() - 30;
		$clean  = [];

		foreach ( $presence as $user_id => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['ts'] ) ) {
				continue;
			}

			if ( (int) $entry['ts'] < $cutoff ) {
				continue;
			}

			$clean[ $user_id ] = [
				'name'       => isset( $entry['name'] ) ? (string) $entry['name'] : '',
				'ts'         => (int) $entry['ts'],
				'scope_mode' => isset( $entry['scope_mode'] ) ? sanitize_key( (string) $entry['scope_mode'] ) : 'individual_contributor',
			];
		}

		return $clean;
	}

	/**
	 * Resolve current editor display name.
	 *
	 * @return string
	 */
	private static function get_current_editor_name(): string {
		$user = wp_get_current_user();

		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		return (string) $user->display_name;
	}

	/**
	 * Resolve parent product IDs for a full post-save refresh.
	 *
	 * @param array<int> $saved Saved product IDs from this request.
	 * @return array<int>
	 */
	private static function get_context_parent_ids( array $saved ): array {
		$context_parent_ids = [];

		if ( isset( $_POST['context_parent_ids'] ) ) {
			$raw = wp_unslash( $_POST['context_parent_ids'] );
			$ids = json_decode( $raw, true );

			if ( is_array( $ids ) ) {
				$context_parent_ids = array_map( 'absint', $ids );
			}
		}

		if ( empty( $context_parent_ids ) ) {
			foreach ( $saved as $id ) {
				$product = wc_get_product( (int) $id );
				if ( ! $product ) {
					continue;
				}

				if ( $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ) {
					$context_parent_ids[] = (int) $product->get_parent_id();
				} else {
					$context_parent_ids[] = (int) $product->get_id();
				}
			}
		}

		$context_parent_ids = array_values( array_unique( array_filter( $context_parent_ids ) ) );

		return $context_parent_ids;
	}

	/**
	 * Apply a single changed row's field values to a WC_Product and save it.
	 *
	 * Only fields that exist in the row array are updated; omitted fields are
	 * left unchanged.
	 *
	 * @param \WC_Product           $product The product to update.
	 * @param array<string, mixed>  $row     Associative array of field values.
	 * @return true|\WP_Error
	 */
	public static function apply_row_to_product( \WC_Product $product, array $row ) {
		$is_variation = $product->is_type( 'variation' );

		// Name and catalog visibility are inherited by variations – skip them.
		if ( ! $is_variation && array_key_exists( 'name', $row ) ) {
			$product->set_name( sanitize_text_field( $row['name'] ) );
		}

		if ( array_key_exists( 'sku', $row ) ) {
			try {
				$product->set_sku( sanitize_text_field( $row['sku'] ) );
			} catch ( \WC_Data_Exception $e ) {
				return new \WP_Error( 'sku_error', $e->getMessage() );
			}
		}

		if ( array_key_exists( 'status', $row ) ) {
			$allowed_statuses = [ 'publish', 'draft', 'pending', 'private', 'trash' ];
			$status           = sanitize_key( $row['status'] );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$product->set_status( $status );
			}
		}

		if ( ! $is_variation && array_key_exists( 'catalog_visibility', $row ) ) {
			$allowed = [ 'visible', 'catalog', 'search', 'hidden' ];
			$vis     = sanitize_key( $row['catalog_visibility'] );
			if ( in_array( $vis, $allowed, true ) ) {
				$product->set_catalog_visibility( $vis );
			}
		}

		if ( array_key_exists( 'regular_price', $row ) ) {
			$product->set_regular_price( wc_format_decimal( $row['regular_price'] ) );
		}

		if ( array_key_exists( 'sale_price', $row ) ) {
			$sale = '' === trim( (string) $row['sale_price'] ) ? '' : wc_format_decimal( $row['sale_price'] );
			$product->set_sale_price( $sale );
		}

		if ( array_key_exists( 'tax_status', $row ) ) {
			$allowed = [ 'taxable', 'shipping', 'none' ];
			$ts      = sanitize_key( $row['tax_status'] );
			if ( in_array( $ts, $allowed, true ) ) {
				$product->set_tax_status( $ts );
			}
		}

		if ( array_key_exists( 'tax_class', $row ) ) {
			$product->set_tax_class( sanitize_text_field( $row['tax_class'] ) );
		}

		if ( array_key_exists( 'manage_stock', $row ) ) {
			$product->set_manage_stock( 'yes' === $row['manage_stock'] );
		}

		if ( array_key_exists( 'stock_status', $row ) ) {
			$allowed = [ 'instock', 'outofstock', 'onbackorder' ];
			$ss      = sanitize_key( $row['stock_status'] );
			if ( in_array( $ss, $allowed, true ) ) {
				$product->set_stock_status( $ss );
			}
		}

		if ( array_key_exists( 'stock_quantity', $row ) ) {
			$qty = '' === trim( (string) $row['stock_quantity'] ) ? null : (int) $row['stock_quantity'];
			$product->set_stock_quantity( $qty );
		}

		if ( array_key_exists( 'backorders', $row ) ) {
			$allowed = [ 'no', 'notify', 'yes' ];
			$bo      = sanitize_key( $row['backorders'] );
			if ( in_array( $bo, $allowed, true ) ) {
				$product->set_backorders( $bo );
			}
		}

		if ( array_key_exists( 'weight', $row ) ) {
			$product->set_weight( wc_format_decimal( $row['weight'] ) );
		}

		if ( array_key_exists( 'length', $row ) ) {
			$product->set_length( wc_format_decimal( $row['length'] ) );
		}

		if ( array_key_exists( 'width', $row ) ) {
			$product->set_width( wc_format_decimal( $row['width'] ) );
		}

		if ( array_key_exists( 'height', $row ) ) {
			$product->set_height( wc_format_decimal( $row['height'] ) );
		}

		if ( array_key_exists( 'short_description', $row ) ) {
			$product->set_short_description( wp_kses_post( $row['short_description'] ) );
		}

		if ( array_key_exists( 'description', $row ) ) {
			$product->set_description( wp_kses_post( $row['description'] ) );
		}

		if ( ! $is_variation && array_key_exists( 'categories', $row ) ) {
			$cats = self::parse_list_values( (string) $row['categories'] );
			wp_set_post_terms( $product->get_id(), $cats, 'product_cat', false );
		}

		if ( ! $is_variation && array_key_exists( 'tags', $row ) ) {
			$tags = self::parse_list_values( (string) $row['tags'] );
			wp_set_post_terms( $product->get_id(), $tags, 'product_tag', false );
		}

		if ( array_key_exists( 'attributes', $row ) ) {
			self::apply_attributes_value( $product, (string) $row['attributes'] );
		}

		$meta_result = self::apply_meta_column_values( $product->get_id(), $row );
		if ( is_wp_error( $meta_result ) ) {
			return $meta_result;
		}

		// Persist the product.
		$result = $product->save();

		if ( ! $result ) {
			return new \WP_Error( 'save_failed', __( 'Could not save product.', 'spread-em' ) );
		}

		return true;
	}

	/**
	 * Apply dynamic meta column values from a row payload.
	 *
	 * @param int                 $product_id Product post ID.
	 * @param array<string,mixed> $row        Changed row payload.
	 * @return true|\WP_Error
	 */
	private static function apply_meta_column_values( int $product_id, array $row ) {
		foreach ( $row as $key => $value ) {
			$column_key = (string) $key;
			$meta_key   = class_exists( 'SpreadEm_Admin' )
				? SpreadEm_Admin::get_meta_key_from_column_key( $column_key )
				: null;

			if ( null === $meta_key ) {
				continue;
			}

			if ( is_protected_meta( $meta_key, 'post' ) ) {
				continue;
			}

			$raw_value = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
			$raw_value = trim( (string) $raw_value );

			if ( '' === $raw_value ) {
				delete_post_meta( $product_id, $meta_key );
				continue;
			}

			$parsed_value = self::parse_meta_cell_value( $raw_value );
			update_post_meta( $product_id, $meta_key, $parsed_value );
		}

		return true;
	}

	/**
	 * Parse a meta cell string back to native PHP value when JSON is supplied.
	 *
	 * @param string $value Cell value.
	 * @return mixed
	 */
	private static function parse_meta_cell_value( string $value ) {
		$first = substr( ltrim( $value ), 0, 1 );

		if ( '{' === $first || '[' === $first ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $decoded;
			}
		}

		return $value;
	}

	/**
	 * Parse comma-separated values to an array of sanitized strings.
	 *
	 * @param string $value Raw input.
	 * @return array<int, string>
	 */
	private static function parse_list_values( string $value ): array {
		$parts = array_map( 'trim', explode( ',', $value ) );
		$parts = array_filter( $parts, static function ( $part ) {
			return '' !== $part;
		} );

		return array_values( array_map( 'sanitize_text_field', $parts ) );
	}

	/**
	 * Apply attributes from the spreadsheet string format.
	 *
	 * Accepted format: "Size: S, M, L | Color: Red, Blue".
	 * For variations, the first value for each attribute is used as the combo.
	 *
	 * @param \WC_Product $product Product to update.
	 * @param string      $raw     Raw attributes string.
	 */
	private static function apply_attributes_value( \WC_Product $product, string $raw ): void {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			if ( ! $product->is_type( 'variation' ) ) {
				$product->set_attributes( [] );
			} else {
				$product->set_attributes( [] );
			}
			return;
		}

		$pairs = array_map( 'trim', explode( '|', $raw ) );

		if ( $product->is_type( 'variation' ) ) {
			$variation_attrs = [];

			foreach ( $pairs as $pair ) {
				if ( '' === $pair || false === strpos( $pair, ':' ) ) {
					continue;
				}

				[ $label, $values_raw ] = array_map( 'trim', explode( ':', $pair, 2 ) );
				$values = self::parse_list_values( $values_raw );

				if ( '' === $label || empty( $values ) ) {
					continue;
				}

				$taxonomy = self::resolve_attribute_taxonomy_by_label( $label );
				$value    = $values[0];

				if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
					$term = get_term_by( 'name', $value, $taxonomy );
					if ( ! $term instanceof \WP_Term ) {
						$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
					}
					$variation_attrs[ $taxonomy ] = $term instanceof \WP_Term ? $term->slug : sanitize_title( $value );
				} else {
					$variation_attrs[ sanitize_title( $label ) ] = sanitize_text_field( $value );
				}
			}

			$product->set_attributes( $variation_attrs );
			return;
		}

		$attributes = [];
		$position   = 0;

		foreach ( $pairs as $pair ) {
			if ( '' === $pair || false === strpos( $pair, ':' ) ) {
				continue;
			}

			[ $label, $values_raw ] = array_map( 'trim', explode( ':', $pair, 2 ) );
			$values = self::parse_list_values( $values_raw );

			if ( '' === $label || empty( $values ) ) {
				continue;
			}

			$taxonomy = self::resolve_attribute_taxonomy_by_label( $label );
			$attr     = new \WC_Product_Attribute();

			if ( $taxonomy && taxonomy_exists( $taxonomy ) ) {
				$term_ids = [];
				foreach ( $values as $value ) {
					$term = get_term_by( 'name', $value, $taxonomy );
					if ( ! $term instanceof \WP_Term ) {
						$inserted = wp_insert_term( $value, $taxonomy );
						if ( ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) ) {
							$term_ids[] = (int) $inserted['term_id'];
						}
					} else {
						$term_ids[] = (int) $term->term_id;
					}
				}

				if ( empty( $term_ids ) ) {
					continue;
				}

				$attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attr->set_name( $taxonomy );
				$attr->set_options( $term_ids );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$attr->set_position( $position++ );
			} else {
				$attr->set_id( 0 );
				$attr->set_name( sanitize_text_field( $label ) );
				$attr->set_options( array_map( 'sanitize_text_field', $values ) );
				$attr->set_visible( true );
				$attr->set_variation( false );
				$attr->set_position( $position++ );
			}

			$attributes[] = $attr;
		}

		$product->set_attributes( $attributes );
	}

	/**
	 * Resolve a global attribute taxonomy name from a human label.
	 *
	 * @param string $label Human-readable attribute label.
	 * @return string|null e.g. pa_color or null.
	 */
	private static function resolve_attribute_taxonomy_by_label( string $label ): ?string {
		$label = sanitize_text_field( $label );
		$slug  = sanitize_title( $label );

		if ( taxonomy_exists( 'pa_' . $slug ) ) {
			return 'pa_' . $slug;
		}

		$taxonomies = wc_get_attribute_taxonomies();

		if ( ! is_array( $taxonomies ) ) {
			return null;
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! isset( $taxonomy->attribute_name ) || ! isset( $taxonomy->attribute_label ) ) {
				continue;
			}

			if (
				0 === strcasecmp( $taxonomy->attribute_label, $label ) ||
				0 === strcasecmp( $taxonomy->attribute_name, $slug )
			) {
				return 'pa_' . $taxonomy->attribute_name;
			}
		}

		return null;
	}

	/**
	 * Snapshot the current values of every field present in a row payload.
	 *
	 * The 'id' key is excluded; all other keys in $row are read from the
	 * product and returned as a field-name → string-value map.
	 *
	 * @param \WC_Product          $product Current product object.
	 * @param array<string, mixed> $row     Row payload (field keys to snapshot).
	 * @return array<string, string>
	 */
	private static function snapshot_product( \WC_Product $product, array $row ): array {
		$snapshot = [];

		foreach ( array_keys( $row ) as $field ) {
			if ( 'id' === $field ) {
				continue;
			}

			$snapshot[ $field ] = self::get_product_field_value( $product, $field );
		}

		return $snapshot;
	}

	/**
	 * Read the current string value of a single editable field from a product.
	 *
	 * @param \WC_Product $product
	 * @param string      $field   Column key (e.g. "regular_price", "meta::custom_key").
	 * @return string
	 */
	private static function get_product_field_value( \WC_Product $product, string $field ): string {
		switch ( $field ) {
			case 'name':
				return (string) $product->get_name();
			case 'sku':
				return (string) $product->get_sku();
			case 'status':
				return (string) $product->get_status();
			case 'catalog_visibility':
				return (string) $product->get_catalog_visibility();
			case 'regular_price':
				return (string) $product->get_regular_price();
			case 'sale_price':
				return (string) $product->get_sale_price();
			case 'tax_status':
				return (string) $product->get_tax_status();
			case 'tax_class':
				return (string) $product->get_tax_class();
			case 'manage_stock':
				return $product->get_manage_stock() ? 'yes' : 'no';
			case 'stock_status':
				return (string) $product->get_stock_status();
			case 'stock_quantity':
				$qty = $product->get_stock_quantity();
				return null === $qty ? '' : (string) $qty;
			case 'backorders':
				return (string) $product->get_backorders();
			case 'weight':
				return (string) $product->get_weight();
			case 'length':
				return (string) $product->get_length();
			case 'width':
				return (string) $product->get_width();
			case 'height':
				return (string) $product->get_height();
			case 'short_description':
				return (string) $product->get_short_description();
			case 'description':
				return (string) $product->get_description();
			case 'categories':
				return wp_strip_all_tags( wc_get_product_category_list( $product->get_id(), ', ' ) );
			case 'tags':
				$tags = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] );
				return is_array( $tags ) ? implode( ', ', $tags ) : '';
			case 'attributes':
				return class_exists( 'SpreadEm_Admin' )
					? SpreadEm_Admin::get_product_attributes_summary( $product )
					: '';
			default:
				$meta_key = class_exists( 'SpreadEm_Admin' )
					? SpreadEm_Admin::get_meta_key_from_column_key( $field )
					: null;

				if ( null !== $meta_key ) {
					$value = get_post_meta( $product->get_id(), $meta_key, true );

					if ( is_array( $value ) || is_object( $value ) ) {
						$encoded = wp_json_encode( $value );
						return is_string( $encoded ) ? $encoded : '';
					}

					return null !== $value ? (string) $value : '';
				}

				return '';
		}
	}
}
