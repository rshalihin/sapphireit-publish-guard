<?php
/**
 * Security coverage for the settings save path.
 *
 * The settings form posts to core's `options.php`, which runs three checks before any
 * value is stored: the nonce (`check_admin_referer`), the capability
 * (`option_page_capability_{group}`) and the registered sanitize callback. Each is
 * exercised here exactly as `options.php` calls it (coding-plan.md section 12.3).
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use WP_UnitTestCase;
use WPDieException;

/**
 * Nonce, capability and whitelist enforcement on `sit_wcpg_settings`.
 *
 * @since 1.0.0
 */
final class Settings_Security_Test extends WP_UnitTestCase {

	/**
	 * A role that can edit and publish products but not manage WooCommerce.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PRODUCT_EDITOR_ROLE = 'sit_wcpg_product_editor';

	/**
	 * The settings page under test, registered as `is_admin()` would register it.
	 *
	 * @since 1.0.0
	 * @var Settings_Page
	 */
	private Settings_Page $page;

	/**
	 * Register the page's hooks and setting, and add the limited role.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();

		add_role(
			self::PRODUCT_EDITOR_ROLE,
			'SIT-WCPG product editor',
			array(
				'read'             => true,
				'edit_products'    => true,
				'publish_products' => true,
			)
		);

		$this->page = new Settings_Page( Plugin::instance()->settings() );
		$this->page->register();
		$this->page->register_settings();
	}

	/**
	 * Undo every registration and leave no request state behind.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unregister_setting( Settings_Page::OPTION_GROUP, Settings::OPTION_NAME );
		remove_filter( 'option_page_capability_' . Settings_Page::OPTION_GROUP, array( $this->page, 'filter_capability' ) );
		remove_action( 'admin_menu', array( $this->page, 'add_menu' ) );
		remove_action( 'admin_init', array( $this->page, 'register_settings' ) );
		remove_role( self::PRODUCT_EDITOR_ROLE );

		unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );

		delete_option( Settings::OPTION_NAME );
		Plugin::instance()->settings()->refresh();

		parent::tear_down();
	}

	/**
	 * The capability `options.php` demands for this group, resolved the way it resolves it.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function save_capability(): string {
		return (string) apply_filters( 'option_page_capability_' . Settings_Page::OPTION_GROUP, 'manage_options' );
	}

	/**
	 * The form carries the nonce for exactly the action `options.php` verifies.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_form_carries_the_options_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		ob_start();
		settings_fields( Settings_Page::OPTION_GROUP );
		$html = (string) ob_get_clean();

		$group = preg_quote( Settings_Page::OPTION_GROUP, '/' );

		$this->assertMatchesRegularExpression( "/name=['\"]option_page['\"] value=['\"]{$group}['\"]/", $html );
		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="([^"]+)"/', $html );

		preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $match );

		$this->assertNotFalse( wp_verify_nonce( $match[1], Settings_Page::OPTION_GROUP . '-options' ) );
	}

	/**
	 * A save with no nonce dies in `check_admin_referer()`, before anything is stored.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_save_without_a_nonce_dies(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );

		$this->expectException( WPDieException::class );

		check_admin_referer( Settings_Page::OPTION_GROUP . '-options' );
	}

	/**
	 * A forged nonce dies the same way.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_save_with_a_forged_nonce_dies(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$_REQUEST['_wpnonce'] = 'forged';

		$this->expectException( WPDieException::class );

		check_admin_referer( Settings_Page::OPTION_GROUP . '-options' );
	}

	/**
	 * A genuine nonce passes, so the two refusals above are about the nonce alone.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_save_with_a_valid_nonce_passes_the_referer_check(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Settings_Page::OPTION_GROUP . '-options' );

		$this->assertSame( 1, check_admin_referer( Settings_Page::OPTION_GROUP . '-options' ) );
	}

	/**
	 * The group's save capability is `manage_woocommerce`, not core's `manage_options`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_save_capability_is_manage_woocommerce(): void {
		$this->assertSame( Settings_Page::CAPABILITY, self::save_capability() );
		$this->assertSame( 'manage_woocommerce', self::save_capability() );
	}

	/**
	 * A subscriber and a product-only editor are refused; a shop manager is not.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_only_users_who_manage_woocommerce_may_save(): void {
		$refused = array(
			'subscriber'              => self::factory()->user->create( array( 'role' => 'subscriber' ) ),
			self::PRODUCT_EDITOR_ROLE => self::factory()->user->create( array( 'role' => self::PRODUCT_EDITOR_ROLE ) ),
		);

		foreach ( $refused as $role => $user_id ) {
			wp_set_current_user( $user_id );

			$this->assertFalse( current_user_can( self::save_capability() ), "A {$role} must not be able to save the settings." );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$this->assertTrue( current_user_can( self::save_capability() ), 'A shop manager must be able to save the settings.' );
	}

	/**
	 * An injected rule id, an injected version and an unknown key are all dropped, so the
	 * stored option has exactly the whitelisted shape.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_injected_keys_never_reach_the_stored_option(): void {
		$settings = Plugin::instance()->settings();
		$defaults = $settings->get_defaults();
		$input    = $defaults;

		$input['version']                     = 999;
		$input['injected']                    = 'anything';
		$input['rules']['sit_wcpg_injected']  = array(
			'enabled'  => '1',
			'severity' => 'required',
		);
		$input['publishing']['sneaky_switch'] = '1';

		update_option( Settings::OPTION_NAME, $input );

		$stored = get_option( Settings::OPTION_NAME );

		$this->assertIsArray( $stored );
		$this->assertSame( Settings::VERSION, $stored['version'] );
		$this->assertArrayNotHasKey( 'injected', $stored );
		$this->assertArrayNotHasKey( 'sit_wcpg_injected', $stored['rules'] );
		$this->assertArrayNotHasKey( 'sneaky_switch', $stored['publishing'] );
		$this->assertSame( self::shape( $defaults ), self::shape( $stored ) );
	}

	/**
	 * The key structure of a nested array, with every leaf replaced by null.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Nested array.
	 * @return array
	 */
	private static function shape( array $values ): array {
		$shape = array();

		foreach ( $values as $key => $value ) {
			$shape[ $key ] = is_array( $value ) ? self::shape( $value ) : null;
		}

		ksort( $shape );

		return $shape;
	}
}
