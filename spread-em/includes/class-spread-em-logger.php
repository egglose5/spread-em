<?php
/**
 * Change-log handler for Spread Em.
 *
 * Creates and manages the {prefix}spread_em_log table.  Every field-level
 * change saved through the spreadsheet editor is recorded here along with
 * the previous value, so changes can be reviewed by whom made them and
 * easily reverted.
 *
 * @package SpreadEm
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SpreadEm_Logger
 */
class SpreadEm_Logger {

	/** @var string Unprefixed table name. */
	const TABLE = 'spread_em_log';

	/** @var int Hard cap for retained log rows. */
	const MAX_ENTRIES = 15;

	/** @var string Schema version for upgrade checks. */
	const SCHEMA_VERSION = '2';

	/**
	 * Return the full (prefixed) table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create (or upgrade) the log table using dbDelta.
	 *
	 * Safe to call multiple times – dbDelta only alters the schema when needed.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			product_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			save_state_id varchar(64) NOT NULL DEFAULT '',
			field varchar(191) NOT NULL DEFAULT '',
			old_value longtext NOT NULL,
			new_value longtext NOT NULL,
			changed_at datetime NOT NULL,
			reverted tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY product_id (product_id),
			KEY save_state_id (save_state_id),
			KEY user_id (user_id),
			KEY changed_at (changed_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ensure table schema is up to date without requiring deactivate/reactivate.
	 */
	public static function ensure_schema(): void {
		$stored_version = (string) get_option( 'spread_em_log_schema_version', '' );

		if ( self::SCHEMA_VERSION === $stored_version ) {
			self::prune_to_limit();
			return;
		}

		self::create_table();
		update_option( 'spread_em_log_schema_version', self::SCHEMA_VERSION, false );
		self::prune_to_limit();
	}

	/**
	 * Drop the log table (called on plugin uninstall).
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * Insert a change record.
	 *
	 * @param int    $user_id    WordPress user ID who made the change.
	 * @param int    $product_id Product post ID.
	 * @param string $field         Column / field name (e.g. "regular_price").
	 * @param string $old_value     Value before the change.
	 * @param string $new_value     Value after the change.
	 * @param string $save_state_id Group ID for one Save button action.
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public static function log_change( int $user_id, int $product_id, string $field, string $old_value, string $new_value, string $save_state_id = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			self::table(),
			[
				'user_id'    => $user_id,
				'product_id' => $product_id,
				'save_state_id' => substr( sanitize_key( $save_state_id ), 0, 64 ),
				'field'      => $field,
				'old_value'  => $old_value,
				'new_value'  => $new_value,
				'changed_at' => current_time( 'mysql', true ),
				'reverted'   => 0,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		if ( ! $result ) {
			return false;
		}

		$insert_id = (int) $wpdb->insert_id;
		self::prune_to_limit();

		return $insert_id;
	}

	/**
	 * Prune oldest rows so the table never exceeds MAX_ENTRIES.
	 */
	public static function prune_to_limit(): void {
		global $wpdb;

		$limit = (int) self::MAX_ENTRIES;

		if ( $limit < 1 ) {
			return;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count <= $limit ) {
			return;
		}

		$to_delete = $count - $limit;

		if ( $to_delete < 1 ) {
			return;
		}

		// Delete oldest rows first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} ORDER BY changed_at ASC, id ASC LIMIT %d",
				$to_delete
			)
		);
	}

	/**
	 * Fetch log entries with optional filtering.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Filter arguments.
	 *
	 *     @type int $product_id Filter by product ID.
	 *     @type int $user_id    Filter by user ID.
	 *     @type string $save_state_id Filter by save state ID.
	 *     @type int    $limit         Maximum rows to return. Default 100.
	 *     @type int    $offset        Offset for pagination. Default 0.
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_entries( array $args = [] ): array {
		global $wpdb;

		$table  = self::table();
		$where  = [];
		$params = [];

		if ( ! empty( $args['product_id'] ) ) {
			$where[]  = 'product_id = %d';
			$params[] = (int) $args['product_id'];
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( ! empty( $args['save_state_id'] ) ) {
			$where[]  = 'save_state_id = %s';
			$params[] = sanitize_key( (string) $args['save_state_id'] );
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$limit     = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 100;
		$offset    = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} {$where_sql} ORDER BY changed_at DESC, id DESC LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Count total log entries (for pagination).
	 *
	 * @param array<string, mixed> $args Same filter args as get_entries().
	 * @return int
	 */
	public static function count_entries( array $args = [] ): int {
		global $wpdb;

		$table  = self::table();
		$where  = [];
		$params = [];

		if ( ! empty( $args['product_id'] ) ) {
			$where[]  = 'product_id = %d';
			$params[] = (int) $args['product_id'];
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		if ( ! empty( $args['save_state_id'] ) ) {
			$where[]  = 'save_state_id = %s';
			$params[] = sanitize_key( (string) $args['save_state_id'] );
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		if ( $params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} {$where_sql}",
					$params
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return (int) $count;
	}

	/**
	 * Get a single log entry by its ID.
	 *
	 * @param int $log_id Log row ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_entry( int $log_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1',
				$log_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get entries for one save state, newest first.
	 *
	 * @param string $save_state_id Save state ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_entries_by_save_state( string $save_state_id ): array {
		global $wpdb;

		$save_state_id = sanitize_key( $save_state_id );

		if ( '' === $save_state_id ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . self::table() . ' WHERE save_state_id = %s ORDER BY id DESC',
				$save_state_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Mark a log entry as reverted.
	 *
	 * @param int $log_id Log row ID.
	 */
	public static function mark_reverted( int $log_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			self::table(),
			[ 'reverted' => 1 ],
			[ 'id' => $log_id ],
			[ '%d' ],
			[ '%d' ]
		);
	}
}
