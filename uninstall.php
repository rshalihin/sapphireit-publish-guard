<?php
/**
 * Uninstall routine: remove every trace of the plugin.
 *
 * The plugin persists exactly two things:
 *
 * 1. the `sit_wcpg_settings` option, and
 * 2. short-lived `sit_wcpg_blocked_{user_id}` transients used to carry a "publishing was
 *    blocked" notice across the post-save redirect.
 *
 * There are no custom tables, no post meta, no custom capabilities and no role edits,
 * so nothing else needs cleaning up. Per the plan (section 9.6) this file uses no
 * `$wpdb` — the transients are enumerated through the users API instead of a LIKE
 * query over the options table.
 *
 * @package ProductPublishGuard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sit_wcpg_settings' );

/*
 * Notice transients expire after 60 seconds on their own, so this loop is belt and
 * braces for a site uninstalled within a minute of a blocked publish. Only users who
 * can edit products can ever have one.
 */
$sit_wcpg_user_ids = get_users(
	array(
		'capability' => 'edit_products',
		'fields'     => 'ID',
		'number'     => 500,
	)
);

foreach ( $sit_wcpg_user_ids as $sit_wcpg_user_id ) {
	delete_transient( 'sit_wcpg_blocked_' . (int) $sit_wcpg_user_id );
}

unset( $sit_wcpg_user_ids, $sit_wcpg_user_id );
