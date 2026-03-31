<?php
/**
 * Clean up plugin data during uninstall.
 *
 * Spread Em does not currently create plugin-owned options, tables, cron jobs,
 * or files, so there is nothing to remove here yet. This file exists to make
 * uninstall behavior explicit and to provide a single place for future cleanup.
 *
 * @package SpreadEm
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;