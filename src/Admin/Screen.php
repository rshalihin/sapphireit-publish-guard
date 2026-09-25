<?php
/**
 * Admin screen predicates.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Settings\Settings_Page;
use WP_Post;
use WP_Screen;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that knows what a WordPress admin screen is called.
 *
 * Hook suffixes and screen bases are spelled out here and nowhere else, so the asset
 * matrix (coding-plan.md section 11.1), the meta box and the products-list column all
 * agree on what "the product editor" means, and a rename costs one edit.
 *
 * Every predicate takes an optional hook suffix because `admin_enqueue_scripts` passes
 * one. When it is supplied it is checked first, which keeps the answer cheap on the
 * overwhelming majority of admin requests that are none of these screens.
 *
 * @since 1.0.0
 */
final class Screen {

	/**
	 * The post type every predicate is about.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const POST_TYPE = 'product';

	/**
	 * Hook suffix of the settings page, as produced by `add_submenu_page( 'woocommerce', … )`.
	 *
	 * Derived from the menu slug rather than repeated, so the two cannot drift apart and
	 * leave the settings page silently unstyled.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SETTINGS_HOOK = 'woocommerce_page_' . Settings_Page::MENU_SLUG;

	/**
	 * Hook suffixes that render a single post.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const EDITOR_HOOKS = array( 'post.php', 'post-new.php' );

	/**
	 * Whether this request is the product editor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Hook suffix from `admin_enqueue_scripts`, or '' to ask the screen.
	 * @return bool
	 */
	public static function is_product_edit_screen( string $hook_suffix = '' ): bool {
		if ( '' !== $hook_suffix && ! in_array( $hook_suffix, self::EDITOR_HOOKS, true ) ) {
			return false;
		}

		$screen = self::screen();

		return null !== $screen && 'post' === $screen->base && self::POST_TYPE === $screen->post_type;
	}

	/**
	 * Whether this request is the products list table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Hook suffix from `admin_enqueue_scripts`, or '' to ask the screen.
	 * @return bool
	 */
	public static function is_product_list_screen( string $hook_suffix = '' ): bool {
		if ( '' !== $hook_suffix && 'edit.php' !== $hook_suffix ) {
			return false;
		}

		$screen = self::screen();

		return null !== $screen && 'edit' === $screen->base && self::POST_TYPE === $screen->post_type;
	}

	/**
	 * Whether this request is the plugin's settings page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Hook suffix from `admin_enqueue_scripts`, or '' to ask the screen.
	 * @return bool
	 */
	public static function is_settings_screen( string $hook_suffix = '' ): bool {
		if ( '' !== $hook_suffix ) {
			return self::SETTINGS_HOOK === $hook_suffix;
		}

		$screen = self::screen();

		return null !== $screen && self::SETTINGS_HOOK === $screen->id;
	}

	/**
	 * The product being edited, or zero when this is not the product editor.
	 *
	 * Read from the global post rather than from the query string: by the time any admin
	 * screen hook runs, core has already resolved and validated it, and reading it here
	 * would mean sanitizing a superglobal for no gain.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function current_product_id(): int {
		if ( ! self::is_product_edit_screen() ) {
			return 0;
		}

		$post = get_post();

		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return 0;
		}

		return (int) $post->ID;
	}

	/**
	 * The current screen, or null when there is none.
	 *
	 * `get_current_screen()` returns null before `admin_init` on some requests, and the
	 * function itself does not exist outside the admin, so both are guarded.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Screen|null
	 */
	private static function screen(): ?WP_Screen {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return null;
		}

		$screen = get_current_screen();

		return $screen instanceof WP_Screen ? $screen : null;
	}
}
