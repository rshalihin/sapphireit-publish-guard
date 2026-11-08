<?php
/**
 * Uninstall routine: remove every trace of the plugin.
 *
 * The plugin persists exactly eight things:
 *
 * 1. the `sit_wcpg_settings` option,
 * 2. the `sit_wcpg_activation_notice` option, set on activation until the welcome notice
 *    is shown,
 * 3. the `sit_wcpg_override_log` option, the capped record of overridden publishes,
 * 4. the `sit_wcpg_readiness_index` option, the state of the last background rebuild,
 * 5. the `sit_wcpg_review_notice` option, when to ask for a review and whether to stop,
 * 6. short-lived `sit_wcpg_blocked_{user_id}` transients used to carry a "publishing was
 *    blocked" notice across the post-save redirect,
 * 7. the three `_sit_wcpg_readiness*` post meta keys, each product's stored readiness, and
 * 8. the Action Scheduler jobs in the `sit-wcpg` group.
 *
 * There are no custom tables, no custom capabilities and no role edits, so nothing else
 * needs cleaning up. The autoloader is not loaded here, so names are written as
 * literals. Per the plan (section 9.6) this file uses no
 * `$wpdb` — the transients are enumerated through the users API instead of a LIKE
 * query over the options table.
 *
 * @package ProductPublishGuard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sit_wcpg_settings' );
delete_option( 'sit_wcpg_activation_notice' );
delete_option( 'sit_wcpg_override_log' );
delete_option( 'sit_wcpg_readiness_index' );
delete_option( 'sit_wcpg_review_notice' );

delete_post_meta_by_key( '_sit_wcpg_readiness' );
delete_post_meta_by_key( '_sit_wcpg_readiness_sig' );
delete_post_meta_by_key( '_sit_wcpg_readiness_data' );

// Defined by Action Scheduler, so absent when WooCommerce is inactive.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'sit-wcpg' );
}

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
