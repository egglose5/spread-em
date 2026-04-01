<?php
/**
 * Capability management for Spread Em.
 *
 * Defines plugin-specific capabilities so role/capability manager plugins can
 * assign responsibilities without custom code changes.
 *
 * @package SpreadEm
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SpreadEm_Permissions
 */
class SpreadEm_Permissions {

	/** @var string Option key for capability migration versioning. */
	const OPTION_CAPS_VERSION = 'spread_em_caps_version';

	/** @var string Capability schema version. */
	const CAPS_VERSION = '1';

	/** @var string Capability: use the spreadsheet editor. */
	const CAP_USE_EDITOR = 'spread_em_use_editor';

	/** @var string Capability: participate as an individual contributor. */
	const CAP_LIVE_INDIVIDUAL = 'spread_em_live_individual_contributor';

	/** @var string Capability: use global operator mode. */
	const CAP_LIVE_GLOBAL = 'spread_em_live_global_operator';

	/** @var string Capability: view Spread Em logs. */
	const CAP_VIEW_LOGS = 'spread_em_view_logs';

	/** @var string Capability: revert changes from logs. */
	const CAP_REVERT_CHANGES = 'spread_em_revert_changes';

	/** @var string Capability: send live IM messages. */
	const CAP_SEND_IM = 'spread_em_send_im';

	/**
	 * Return all plugin capabilities.
	 *
	 * @return array<int,string>
	 */
	public static function all_caps(): array {
		return [
			self::CAP_USE_EDITOR,
			self::CAP_LIVE_INDIVIDUAL,
			self::CAP_LIVE_GLOBAL,
			self::CAP_VIEW_LOGS,
			self::CAP_REVERT_CHANGES,
			self::CAP_SEND_IM,
		];
	}

	/**
	 * Return default role-to-capability mapping.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function default_capability_map(): array {
		$map = [
			'administrator' => self::all_caps(),
			'shop_manager'  => [
				self::CAP_USE_EDITOR,
				self::CAP_LIVE_INDIVIDUAL,
				self::CAP_VIEW_LOGS,
				self::CAP_REVERT_CHANGES,
				self::CAP_SEND_IM,
			],
		];

		/**
		 * Filter default role capability grants for Spread Em.
		 *
		 * @param array<string,array<int,string>> $map Default role-to-capability map.
		 */
		return apply_filters( 'spread_em_default_capability_map', $map );
	}

	/**
	 * Grant default plugin capabilities to supported roles.
	 */
	public static function grant_default_capabilities(): void {
		$map = self::default_capability_map();

		foreach ( $map as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				$role->add_cap( (string) $cap );
			}
		}
	}

	/**
	 * Ensure capability grants are applied for current plugin version.
	 */
	public static function ensure_capabilities(): void {
		$stored_version = (string) get_option( self::OPTION_CAPS_VERSION, '' );

		if ( self::CAPS_VERSION === $stored_version ) {
			return;
		}

		self::grant_default_capabilities();
		update_option( self::OPTION_CAPS_VERSION, self::CAPS_VERSION );
	}

	/**
	 * Can user access the spreadsheet editor?
	 *
	 * @return bool
	 */
	public static function current_user_can_use_editor(): bool {
		return current_user_can( self::CAP_USE_EDITOR ) && current_user_can( 'edit_products' );
	}

	/**
	 * Can user participate in live collaboration?
	 *
	 * @return bool
	 */
	public static function current_user_can_live_collaborate(): bool {
		return current_user_can( self::CAP_LIVE_INDIVIDUAL ) || current_user_can( self::CAP_LIVE_GLOBAL );
	}

	/**
	 * Can user act as a global operator?
	 *
	 * @return bool
	 */
	public static function current_user_can_be_global_operator(): bool {
		return current_user_can( self::CAP_LIVE_GLOBAL );
	}

	/**
	 * Can user view log pages?
	 *
	 * @return bool
	 */
	public static function current_user_can_view_logs(): bool {
		return current_user_can( self::CAP_VIEW_LOGS );
	}

	/**
	 * Can user revert changes?
	 *
	 * @return bool
	 */
	public static function current_user_can_revert_changes(): bool {
		return current_user_can( self::CAP_REVERT_CHANGES );
	}

	/**
	 * Can user send live direct messages?
	 *
	 * @return bool
	 */
	public static function current_user_can_send_im(): bool {
		return current_user_can( self::CAP_SEND_IM );
	}
}
