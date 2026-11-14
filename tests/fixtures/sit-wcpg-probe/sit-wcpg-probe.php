<?php
/**
 * Plugin Name: Publish Guard Probe
 * Description: A test fixture. A complete add-on built on the rule API 2.1 extension points and nothing else.
 * Requires Plugins: sapphireit-publish-guard
 *
 * It adds one check, "Probe code": a product needs a value in the post meta `_probe_code`.
 * Everything it touches is a public hook or a member tagged `@api`. If this file ever needs
 * a class or method that is not, the extension points are incomplete.
 *
 * Not shipped: `tests/` is left out of the release zip.
 *
 * @package ProductPublishGuard
 */

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Save_Path;
use ProductPublishGuard\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The probe add-on.
 *
 * @since 1.3.0
 */
final class Sit_Wcpg_Probe {

	/**
	 * The context extra the rule reads.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const EXTRA = 'probe_code';

	/**
	 * The post meta, and the classic editor's field name, the value lives in.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const META = '_probe_code';

	/**
	 * The option that stands for the add-on's own configuration.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const SALT_OPTION = 'probe_salt';

	/**
	 * The checklist group the rule is listed under.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const GROUP = 'probe';

	/**
	 * The slug of the product category the rule does not apply to.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	public const EXEMPT_CATEGORY = 'probe-exempt';

	/**
	 * Whether the add-on does anything.
	 *
	 * A test switch, not part of the pattern. The test suite runs every test in one PHP
	 * process and a registry cannot forget a rule, so the probe stays loaded for the rest
	 * of the run and must be inert outside its own test.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	public static bool $enabled = true;

	/**
	 * Whether `boot()` has run.
	 *
	 * @since 1.3.0
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boot after the plugin: on `sit_wcpg_loaded`, or at once when it has already fired.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( did_action( 'sit_wcpg_loaded' ) ) {
			self::boot( Plugin::instance() );

			return;
		}

		add_action( 'sit_wcpg_loaded', array( self::class, 'boot' ) );
	}

	/**
	 * Wire the add-on to the extension points (S1, S2).
	 *
	 * @since 1.3.0
	 *
	 * @param Plugin $plugin The booted plugin.
	 * @return void
	 */
	public static function boot( Plugin $plugin ): void {
		if ( self::$booted || 2 !== Rule_Registry::API_VERSION || Rule_Registry::API_MINOR < 1 ) {
			return;
		}

		self::$booted = true;

		require_once __DIR__ . '/class-sit-wcpg-probe-rule.php';

		add_action( 'sit_wcpg_register_rules', array( self::class, 'register_rules' ) );
		add_filter( 'sit_wcpg_rule_groups', array( self::class, 'add_group' ) );
		add_filter( 'sit_wcpg_context_extra', array( self::class, 'resolve_extra' ), 10, 3 );
		add_filter( 'sit_wcpg_save_request_extras', array( self::class, 'save_request_extras' ), 10, 3 );
		add_filter( 'sit_wcpg_draft_extras', array( self::class, 'draft_extras' ), 10, 2 );
		add_filter( 'sit_wcpg_settings_hash_salt', array( self::class, 'hash_salt' ) );

		// Loaded after the rules were collected, as in the test suite: register directly.
		if ( did_action( 'sit_wcpg_register_rules' ) ) {
			self::register_rules( $plugin->registry() );
		}
	}

	/**
	 * Register the managed rule (S3).
	 *
	 * @since 1.3.0
	 *
	 * @param Rule_Registry $registry The registry.
	 * @return void
	 */
	public static function register_rules( Rule_Registry $registry ): void {
		if ( ! $registry->has( Sit_Wcpg_Probe_Rule::ID ) ) {
			$registry->register( new Sit_Wcpg_Probe_Rule() );
		}
	}

	/**
	 * Name the add-on's checklist group (S11).
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $labels Group labels by key.
	 * @return mixed
	 */
	public static function add_group( $labels ) {
		if ( self::$enabled && is_array( $labels ) ) {
			$labels[ self::GROUP ] = 'Probe';
		}

		return $labels;
	}

	/**
	 * Read the stored value, for every path that seeds nothing (S7).
	 *
	 * @since 1.3.0
	 *
	 * @param mixed  $value   The value so far.
	 * @param string $key     The extra key.
	 * @param mixed  $context The context being read.
	 * @return mixed
	 */
	public static function resolve_extra( $value, $key, $context ) {
		if ( ! self::$enabled || self::EXTRA !== $key || ! $context instanceof Product_Context ) {
			return $value;
		}

		$product = $context->get_product();

		return null === $product ? $value : (string) $product->get_meta( self::META );
	}

	/**
	 * Read the value the classic editor posts, so the rule judges what was typed (S8).
	 *
	 * A request that does not carry the field seeds nothing, and the stored value is used.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed  $extras  The extras so far.
	 * @param array  $postarr The slashed request data.
	 * @param string $source  One of the `Save_Path` constants.
	 * @return mixed
	 */
	public static function save_request_extras( $extras, $postarr, $source ) {
		if ( ! self::$enabled || ! is_array( $extras ) || Save_Path::CLASSIC !== $source ) {
			return $extras;
		}

		if ( isset( $postarr[ self::META ] ) && is_string( $postarr[ self::META ] ) ) {
			$extras[ self::EXTRA ] = sanitize_text_field( wp_unslash( $postarr[ self::META ] ) );
		}

		return $extras;
	}

	/**
	 * Accept the value the live editor sends (S9). Nothing else in `$raw` is passed on.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $extras The extras so far.
	 * @param mixed $raw    The draft's extras, unsanitized.
	 * @return mixed
	 */
	public static function draft_extras( $extras, $raw ) {
		if ( ! self::$enabled || ! is_array( $extras ) || ! is_array( $raw ) ) {
			return $extras;
		}

		if ( isset( $raw[ self::EXTRA ] ) && is_string( $raw[ self::EXTRA ] ) ) {
			$extras[ self::EXTRA ] = sanitize_text_field( $raw[ self::EXTRA ] );
		}

		return $extras;
	}

	/**
	 * Add the add-on's configuration to the settings hash (S5).
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $salt The salt so far.
	 * @return mixed
	 */
	public static function hash_salt( $salt ) {
		$own = self::$enabled ? get_option( self::SALT_OPTION, '' ) : '';

		if ( ! is_string( $own ) || '' === $own ) {
			return $salt;
		}

		return ( is_string( $salt ) ? $salt : '' ) . '|probe:' . $own;
	}
}

Sit_Wcpg_Probe::load();
