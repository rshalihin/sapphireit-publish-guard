<?php
/**
 * Tests for the naming contract.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit;

use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Catalog_Page;
use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Product_List_Column;
use ProductPublishGuard\Admin\Readiness_Filter;
use ProductPublishGuard\Admin\Review_Notice;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Cli\Gate_Command;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Support\Override_Log;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
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
	 * The catalog screen hook is the one core derives from the Products submenu (R8).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_catalog_hook_is_derived_from_the_menu_slug() {
		$this->assertSame( 'product_page_' . Catalog_Page::MENU_SLUG, Screen::CATALOG_HOOK );
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
			'override log' => array( Override_Log::OPTION ),
			'index option' => array( Readiness_Rebuild::OPTION ),
			'rebuild hook' => array( Readiness_Rebuild::HOOK ),
			'reindex hook' => array( Readiness_Rebuild::PRODUCTS_HOOK ),
			'filter var'   => array( Readiness_Filter::QUERY_VAR ),
			'review state' => array( Review_Notice::OPTION ),
		);
	}

	/**
	 * Hidden post meta uses `_sit_wcpg`.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_hidden_meta_keys_carry_the_hidden_prefix() {
		foreach ( Readiness_Store::meta_keys() as $key ) {
			$this->assertMatchesRegularExpression( '/^_sit_wcpg(_[a-z0-9]+)*$/', $key );
		}
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
			'as group'       => array( Readiness_Rebuild::GROUP ),
			'catalog slug'   => array( Catalog_Page::MENU_SLUG ),
			'review handle'  => array( Assets::REVIEW_HANDLE ),
			'cli command'    => array( Gate_Command::COMMAND ),
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
		$this->assertMatchesRegularExpression( '/^sitWcpg[A-Z][A-Za-z0-9]*$/', Assets::REVIEW_PAYLOAD_GLOBAL );
	}

	/**
	 * The review script reads the global the enqueuer writes, and hardcodes no route.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_review_script_matches_the_php_constants() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/review-notice/index.js' );

		$this->assertStringContainsString( 'window.' . Assets::REVIEW_PAYLOAD_GLOBAL . '.restPath', $source );
		$this->assertStringNotContainsString( Validate_Controller::REST_NAMESPACE, $source );
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
