<?php
/**
 * The handful of WordPress functions the rule engine touches, for the unit suite.
 *
 * These are deliberately minimal. They reproduce core's behaviour closely enough for the
 * cases the unit suite asserts on, and the real behaviour is covered by the integration
 * suite. The one known divergence is `strip_shortcodes()`: core also removes the content
 * of an *enclosing* shortcode, while this stub only removes the tags.
 *
 * @package ProductPublishGuard
 */

if ( ! function_exists( '__' ) ) {
	/**
	 * Pass-through translation.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Pass-through plural translation.
	 *
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Count deciding the form.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );

		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( '_x' ) ) {
	/**
	 * Pass-through contextual translation.
	 *
	 * @param string $text    Text to translate.
	 * @param string $context Disambiguating context.
	 * @param string $domain  Text domain.
	 * @return string
	 */
	function _x( $text, $context, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $context, $domain );

		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape for HTML output.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Return the filtered value unchanged; no filters are registered in the unit suite.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Further arguments.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $hook_name, $args );

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Fire an action; no listeners are registered in the unit suite.
	 *
	 * Calls are recorded in `$GLOBALS['sit_wcpg_test_actions']` so the provider test can
	 * assert that the rule extension point is opened.
	 *
	 * @param string $hook_name Action name.
	 * @param mixed  ...$args   Action arguments.
	 * @return void
	 */
	function do_action( $hook_name, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['sit_wcpg_test_actions'][] = array(
			'hook' => $hook_name,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Remove markup, optionally collapsing line breaks.
	 *
	 * @param string $text          Text to strip.
	 * @param bool   $remove_breaks Whether to collapse whitespace runs.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$text = (string) $text;
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = wp_kses_stub_strip_tags( $text );

		if ( $remove_breaks ) {
			$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'wp_kses_stub_strip_tags' ) ) {
	/**
	 * Wrapper around strip_tags(), isolated so the sniff exemption stays narrow.
	 *
	 * @param string $text Text to strip.
	 * @return string
	 */
	function wp_kses_stub_strip_tags( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return strip_tags( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'strip_shortcodes' ) ) {
	/**
	 * Remove shortcode tags.
	 *
	 * @param string $content Content to strip.
	 * @return string
	 */
	function strip_shortcodes( $content ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (string) preg_replace( '/\[\/?[^\]\[]*\]/', '', (string) $content );
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	/**
	 * Whether an attachment id is an image.
	 *
	 * Controlled by `$GLOBALS['sit_wcpg_test_image_ids']`.
	 *
	 * @param int $post Attachment id.
	 * @return bool
	 */
	function wp_attachment_is_image( $post = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$ids = isset( $GLOBALS['sit_wcpg_test_image_ids'] ) ? (array) $GLOBALS['sit_wcpg_test_image_ids'] : array();

		return in_array( (int) $post, array_map( 'intval', $ids ), true );
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	/**
	 * Terms attached to a post.
	 *
	 * Controlled by `$GLOBALS['sit_wcpg_test_terms'][ $taxonomy ]`, a list of term ids.
	 *
	 * @param int|object $post     Post or post id.
	 * @param string     $taxonomy Taxonomy name.
	 * @return array|false
	 */
	function get_the_terms( $post, $taxonomy ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $post );

		$terms = isset( $GLOBALS['sit_wcpg_test_terms'][ $taxonomy ] ) ? (array) $GLOBALS['sit_wcpg_test_terms'][ $taxonomy ] : array();

		if ( array() === $terms ) {
			return false;
		}

		return array_map(
			static function ( $term_id ) {
				return (object) array( 'term_id' => (int) $term_id );
			},
			$terms
		);
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Read an option.
	 *
	 * Controlled by `$GLOBALS['sit_wcpg_test_options']`.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Value when the option is unset.
	 * @return mixed
	 */
	function get_option( $option, $default_value = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['sit_wcpg_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Non-negative integer, exactly as core defines it.
	 *
	 * @param mixed $maybeint Value to convert.
	 * @return int
	 */
	function absint( $maybeint ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Lowercase key containing only alphanumerics, dashes and underscores.
	 *
	 * @param string $key Value to sanitize.
	 * @return string
	 */
	function sanitize_key( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode a value.
	 *
	 * @param mixed $data Value to encode.
	 * @return string|false
	 */
	function wp_json_encode( $data ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'add_settings_error' ) ) {
	/**
	 * Record a settings notice.
	 *
	 * Collected in `$GLOBALS['sit_wcpg_test_settings_errors']` so the sanitizer tests can
	 * assert that clamping is reported rather than done silently.
	 *
	 * @param string $setting Slug the notice belongs to.
	 * @param string $code    Notice identifier.
	 * @param string $message Notice text.
	 * @param string $type    Notice type.
	 * @return void
	 */
	function add_settings_error( $setting, $code, $message, $type = 'error' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['sit_wcpg_test_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}
