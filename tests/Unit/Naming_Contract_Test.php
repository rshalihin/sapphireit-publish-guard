<?php
/**
 * Tests for the naming contract.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit;

use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Product_List_Column;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Checklist_Service;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every identifier WordPress, WooCommerce or the browser stores globally carries the
 * prefix in the form its context calls for (coding-plan.md section 10.6), and linked
 * identifiers stay linked. A drift here breaks a feature without any PHP error.
 *
 * @since 1.0.0
 */
final class Naming_Contract_Test extends TestCase {

	/**
	 * The settings screen hook is the one core derives from the menu slug.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_settings_hook_is_derived_from_the_menu_slug() {
		$this->assertSame( 'woocommerce_page_' . Settings_Page::MENU_SLUG, Screen::SETTINGS_HOOK );
	}

	/**
	 * The settings group and the option share one name, which core relies on for the
	 * `option_page_capability_{group}` and `sanitize_option_{name}` hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_settings_group_matches_the_option_name() {
		$this->assertSame( Settings::OPTION_NAME, Settings_Page::OPTION_GROUP );
	}

	/**
	 * Snake-case contexts use `sit_wcpg`.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider data_snake_identifiers
	 *
	 * @param string $identifier The identifier under test.
	 * @return void
	 */
	public function test_snake_identifiers_carry_the_snake_prefix( string $identifier ) {
		$this->assertMatchesRegularExpression( '/^sit_wcpg(_[a-z0-9]+)*$/', $identifier );
	}

	/**
	 * Snake-case identifiers.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{string}>
	 */
	public function data_snake_identifiers(): array {
		return array(
			'option name'  => array( Settings::OPTION_NAME ),
			'option group' => array( Settings_Page::OPTION_GROUP ),
			'meta box id'  => array( Editor_Meta_Box::ID ),
			'list column'  => array( Product_List_Column::COLUMN ),
			'cache group'  => array( Checklist_Service::CACHE_GROUP ),
		);
	}

	/**
	 * Kebab-case contexts use `sit-wcpg`.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider data_kebab_identifiers
	 *
	 * @param string $identifier The identifier under test.
	 * @return void
	 */
	public function test_kebab_identifiers_carry_the_kebab_prefix( string $identifier ) {
		$this->assertMatchesRegularExpression( '#^sit-wcpg(-[a-z0-9]+)*(/v[0-9]+)?$#', $identifier );
	}

	/**
	 * Kebab-case identifiers.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{string}>
	 */
	public function data_kebab_identifiers(): array {
		return array(
			'menu slug'      => array( Settings_Page::MENU_SLUG ),
			'mount id'       => array( Editor_Meta_Box::MOUNT_ID ),
			'editor handle'  => array( Assets::EDITOR_HANDLE ),
			'admin handle'   => array( Assets::ADMIN_HANDLE ),
			'rest namespace' => array( Validate_Controller::REST_NAMESPACE ),
		);
	}

	/**
	 * JS globals use `sitWcpg` + PascalCase.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_payload_global_carries_the_camel_prefix() {
		$this->assertMatchesRegularExpression( '/^sitWcpg[A-Z][A-Za-z0-9]*$/', Assets::PAYLOAD_GLOBAL );
	}

	/**
	 * The editor entry cannot import PHP constants, so it repeats the mount id and the
	 * payload global once; this keeps the two sides from drifting apart.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_editor_entry_matches_the_php_constants() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/editor/index.js' );

		$this->assertStringContainsString( "const MOUNT_ID = '" . Editor_Meta_Box::MOUNT_ID . "';", $source );
		$this->assertStringContainsString( "const PAYLOAD_GLOBAL = '" . Assets::PAYLOAD_GLOBAL . "';", $source );
	}
}
