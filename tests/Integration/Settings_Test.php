<?php
/**
 * Tests for the settings facade against a real options table.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Admin\Settings_Footer;
use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Settings\Settings_Sanitizer;
use ProductPublishGuard\Support\Readiness_Indexer;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use ProductPublishGuard\Tests\Stubs\No_Scheduler_Rebuild;
use WP_UnitTestCase;

/**
 * Covers defaults on a fresh site, the round trip through `update_option()`, and the
 * cache-key hash.
 *
 * @since 1.0.0
 */
final class Settings_Test extends WP_UnitTestCase {

	/**
	 * Start every test from a site that has never saved the form.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		delete_option( Readiness_Rebuild::OPTION );
	}

	/**
	 * A registry holding two rules with different shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return Rule_Registry
	 */
	private function registry(): Rule_Registry {
		$registry = new Rule_Registry();
		$registry->register( new Fake_Rule( 'title', 'pass', 10, true, false, Severity::REQUIRED, true ) );
		$registry->register( new Fake_Rule( 'sku', 'pass', 20, true, false, Severity::WARNING, false ) );

		return $registry;
	}

	/**
	 * With no stored option, every value is the documented default.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_defaults_apply_when_the_option_has_never_been_saved(): void {
		$settings = new Settings( $this->registry() );

		$this->assertTrue( $settings->is_enabled() );
		$this->assertTrue( $settings->rule_is_enabled( 'title' ) );
		$this->assertFalse( $settings->rule_is_enabled( 'sku' ) );
		$this->assertSame( Severity::REQUIRED, $settings->rule_severity( 'title' ) );
		$this->assertSame( Severity::WARNING, $settings->rule_severity( 'sku' ) );
		$this->assertSame( 150, $settings->threshold( 'min_description_chars' ) );
		$this->assertSame( 50, $settings->threshold( 'min_short_description_chars' ) );
		$this->assertSame( 2, $settings->threshold( 'min_images' ) );
		$this->assertTrue( $settings->blocks_publishing() );
		$this->assertFalse( $settings->allows_admin_override() );
		$this->assertSame( Settings::SCOPE_AUTHENTICATED, $settings->enforcement_scope() );
		$this->assertTrue( $settings->shows_list_column() );
	}

	/**
	 * A rule the stored option predates still gets its shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_rule_added_after_the_option_was_saved_uses_its_own_defaults(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'version' => Settings::VERSION,
				'enabled' => true,
				'rules'   => array(
					'title' => array(
						'enabled'  => true,
						'severity' => Severity::WARNING,
					),
				),
			)
		);

		$registry = $this->registry();
		$registry->register( new Fake_Rule( 'alt_text', 'pass', 30, true, false, Severity::WARNING, true ) );

		$settings = new Settings( $registry );

		$this->assertSame( Severity::WARNING, $settings->rule_severity( 'title' ), 'Stored values still win.' );
		$this->assertTrue( $settings->rule_is_enabled( 'alt_text' ) );
		$this->assertSame( Severity::WARNING, $settings->rule_severity( 'alt_text' ) );
	}

	/**
	 * Values written through the sanitizer come back out unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_saved_values_survive_a_round_trip(): void {
		$registry = $this->registry();

		update_option(
			Settings::OPTION_NAME,
			( new Settings_Sanitizer( new Settings( $registry ) ) )->sanitize(
				array(
					'enabled'    => '1',
					'rules'      => array(
						'title' => array(
							'enabled'  => '1',
							'severity' => Severity::WARNING,
						),
						'sku'   => array(
							'enabled'  => '1',
							'severity' => Severity::REQUIRED,
						),
					),
					'thresholds' => array(
						'min_description_chars'       => '400',
						'min_short_description_chars' => '0',
						'min_images'                  => '3',
					),
					'publishing' => array(
						'block_on_required_failure' => '1',
						'allow_admin_override'      => '1',
						'enforce_scope'             => Settings::SCOPE_EDITOR,
					),
				)
			)
		);

		$settings = new Settings( $registry );

		$this->assertSame( Severity::WARNING, $settings->rule_severity( 'title' ) );
		$this->assertTrue( $settings->rule_is_enabled( 'sku' ) );
		$this->assertSame( Severity::REQUIRED, $settings->rule_severity( 'sku' ) );
		$this->assertSame( 400, $settings->threshold( 'min_description_chars' ) );
		$this->assertSame( 0, $settings->threshold( 'min_short_description_chars' ) );
		$this->assertSame( 3, $settings->threshold( 'min_images' ) );
		$this->assertTrue( $settings->allows_admin_override() );
		$this->assertSame( Settings::SCOPE_EDITOR, $settings->enforcement_scope() );
		$this->assertFalse( $settings->shows_list_column(), 'The column checkbox was not submitted.' );
	}

	/**
	 * The sanitizer runs for a direct `update_option()`, not only for form posts.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_registered_sanitizer_filters_a_direct_update_option(): void {
		register_setting(
			Settings_Page::OPTION_GROUP,
			Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( new Settings_Sanitizer( new Settings( $this->registry() ) ), 'sanitize' ),
			)
		);

		update_option(
			Settings::OPTION_NAME,
			array(
				'version'    => 99,
				'injected'   => 'anything',
				'rules'      => array(
					'evil' => array( 'enabled' => '1' ),
				),
				'publishing' => array( 'enforce_scope' => 'nonsense' ),
			)
		);

		$stored = get_option( Settings::OPTION_NAME );

		unregister_setting( Settings_Page::OPTION_GROUP, Settings::OPTION_NAME );

		$this->assertSame( Settings::VERSION, $stored['version'] );
		$this->assertArrayNotHasKey( 'injected', $stored );
		$this->assertSame( array( 'title', 'sku' ), array_keys( $stored['rules'] ) );
		$this->assertSame( Settings::SCOPE_AUTHENTICATED, $stored['publishing']['enforce_scope'] );
	}

	/**
	 * Any change to any value changes the hash, which is what expires cached checklists.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_hash_changes_when_any_value_changes(): void {
		$registry = $this->registry();
		$baseline = ( new Settings( $registry ) )->get_hash();

		$this->assertSame( $baseline, ( new Settings( $registry ) )->get_hash(), 'The hash is stable for identical settings.' );

		$variations = array(
			'a threshold' => array( 'thresholds' => array( 'min_images' => 4 ) ),
			'a severity'  => array( 'rules' => array( 'title' => array( 'severity' => Severity::WARNING ) ) ),
			'a rule flag' => array( 'rules' => array( 'title' => array( 'enabled' => false ) ) ),
			'enforcement' => array( 'publishing' => array( 'enforce_scope' => Settings::SCOPE_EDITOR ) ),
			'the column'  => array( 'product_list' => array( 'show_column' => false ) ),
		);

		foreach ( $variations as $label => $stored ) {
			update_option( Settings::OPTION_NAME, $stored );

			$this->assertNotSame( $baseline, ( new Settings( $registry ) )->get_hash(), 'Changing ' . $label . ' must change the hash.' );
		}
	}

	/**
	 * A different set of registered rules produces a different hash.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_hash_changes_when_a_rule_is_registered(): void {
		$baseline = ( new Settings( $this->registry() ) )->get_hash();

		$registry = $this->registry();
		$registry->register( new Fake_Rule( 'alt_text', 'pass', 30 ) );

		$this->assertNotSame( $baseline, ( new Settings( $registry ) )->get_hash() );
	}

	/**
	 * `refresh()` drops the memo so a same-request write is seen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_refresh_rereads_the_option(): void {
		$settings = new Settings( $this->registry() );

		$this->assertTrue( $settings->is_enabled() );

		update_option( Settings::OPTION_NAME, array( 'enabled' => false ) );

		$this->assertTrue( $settings->is_enabled(), 'The value is memoized for the request.' );

		$settings->refresh();

		$this->assertFalse( $settings->is_enabled() );
	}

	/**
	 * A corrupt option degrades to the defaults instead of fataling.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_corrupt_option_falls_back_to_the_defaults(): void {
		update_option( Settings::OPTION_NAME, 'not an array' );

		$settings = new Settings( $this->registry() );

		$this->assertTrue( $settings->is_enabled() );
		$this->assertSame( 150, $settings->threshold( 'min_description_chars' ) );
		$this->assertSame( Settings::SCOPE_AUTHENTICATED, $settings->enforcement_scope() );
	}

	/**
	 * An identifier no rule claims is never enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_unknown_rule_id_is_never_enabled(): void {
		$settings = new Settings( $this->registry() );

		$this->assertFalse( $settings->rule_is_enabled( 'no_such_rule' ) );
		$this->assertSame( Severity::REQUIRED, $settings->rule_severity( 'no_such_rule' ) );
		$this->assertSame( 0, $settings->threshold( 'no_such_threshold' ) );
	}

	/**
	 * The shipped rule catalogue.
	 *
	 * @since 1.1.0
	 *
	 * @return Rule_Registry
	 */
	private function built_in_registry(): Rule_Registry {
		$registry = new Rule_Registry();

		Rules_Provider::populate( $registry );

		return $registry;
	}

	/**
	 * An option saved by v1.0: the eleven v1.0 rules, every one made required.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function store_a_v1_0_option(): void {
		$rules = array();

		foreach ( array( 'title', 'description', 'short_description', 'featured_image', 'image_count', 'price', 'sale_price', 'category', 'tags', 'sku', 'stock_status' ) as $rule_id ) {
			$rules[ $rule_id ] = array(
				'enabled'  => true,
				'severity' => Severity::REQUIRED,
			);
		}

		update_option(
			Settings::OPTION_NAME,
			array(
				'version' => Settings::VERSION,
				'enabled' => true,
				'rules'   => $rules,
			)
		);
	}

	/**
	 * On a site upgraded from v1.0, child_products arrives enabled and as a warning, even
	 * though the merchant made every stored rule required (D7).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_child_products_defaults_to_a_warning_over_a_v1_0_option(): void {
		$this->store_a_v1_0_option();

		$settings = new Settings( $this->built_in_registry() );

		$this->assertSame( Severity::REQUIRED, $settings->rule_severity( 'price' ), 'Stored values still win.' );
		$this->assertTrue( $settings->rule_is_enabled( 'child_products' ) );
		$this->assertSame( Severity::WARNING, $settings->rule_severity( 'child_products' ) );
	}

	/**
	 * The settings screen renders a row for the new rule, with warning selected.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_the_settings_page_renders_the_child_products_row(): void {
		$this->store_a_v1_0_option();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$registry = $this->built_in_registry();

		ob_start();
		( new Settings_Page( new Settings( $registry ), $registry ) )->render_page();
		$output = (string) ob_get_clean();

		$field = Settings::OPTION_NAME . '[rules][child_products]';

		$this->assertStringContainsString( '<strong>Variations and grouped products</strong>', $output );
		$this->assertStringContainsString( 'Checks that variable and grouped products have something to sell.', $output );
		$this->assertMatchesRegularExpression(
			'/name="' . preg_quote( $field . '[enabled]', '/' ) . '"[^>]*checked=/',
			$output,
			'The new rule must render enabled.'
		);
		$this->assertMatchesRegularExpression(
			'/name="' . preg_quote( $field . '[severity]', '/' ) . '" value="' . Severity::WARNING . '"\s*checked=/',
			$output,
			'The new rule must render with warning selected.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/name="' . preg_quote( $field . '[severity]', '/' ) . '" value="' . Severity::REQUIRED . '"\s*checked=/',
			$output
		);
	}

	/**
	 * The settings screen as an administrator sees it.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Registry          $registry Rules to list.
	 * @param Readiness_Rebuild|null $rebuild  The rebuild. Null builds an ordinary one.
	 * @return string
	 */
	private function render_screen( Rule_Registry $registry, ?Readiness_Rebuild $rebuild = null ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = new Settings( $registry );

		ob_start();
		( new Settings_Page( $settings, $registry, $rebuild ?? $this->rebuild_for( $settings ) ) )->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * A rebuild over the given settings.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings $settings The merchant's configuration.
	 * @return Readiness_Rebuild
	 */
	private function rebuild_for( Settings $settings ): Readiness_Rebuild {
		return new Readiness_Rebuild( $settings, new Readiness_Indexer( $settings, new Readiness_Store() ) );
	}

	/**
	 * Record a rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @param string $signature The signature it was for.
	 * @param string $state     Its state.
	 * @param int    $total     Products in the catalog.
	 * @param int    $done      Products rechecked so far.
	 * @return void
	 */
	private function store_index( string $signature, string $state, int $total, int $done ): void {
		update_option(
			Readiness_Rebuild::OPTION,
			array(
				'version'    => SIT_WCPG_VERSION,
				'signature'  => $signature,
				'state'      => $state,
				'total'      => $total,
				'done'       => $done,
				'started_at' => time(),
			),
			false
		);
	}

	/**
	 * Headings follow the editor panel's order, and each rule sits under its own.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rules_render_under_group_headings_in_editor_order(): void {
		$output = $this->render_screen( $this->built_in_registry() );

		preg_match_all( '#<tr class="sit-wcpg-settings-rules__group"><th colspan="3" scope="colgroup">([^<]+)</th></tr>#', $output, $matches );

		$this->assertSame( array_values( Editor_Meta_Box::group_labels() ), $matches[1] );

		$price = strpos( $output, '<strong>Regular price</strong>' );

		$this->assertGreaterThan( strpos( $output, '>Pricing</th>' ), $price );
		$this->assertLessThan( strpos( $output, '>Organization</th>' ), $price );
		$this->assertSame( count( $matches[1] ), substr_count( $output, '<tr class="sit-wcpg-settings-rules__group">' ) );
		$this->assertSame( count( $matches[1] ), substr_count( $output, '<tbody><tr class="sit-wcpg-settings-rules__group">' ), 'Each group is its own tbody.' );
	}

	/**
	 * A rule in a group the editor has no heading for still gets a row, after the known groups.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_third_party_rule_with_unknown_group_renders_under_other(): void {
		$registry = $this->built_in_registry();

		$registry->register(
			new class() extends Abstract_Rule {

				/**
				 * Rule identifier.
				 *
				 * @return string
				 */
				public function get_id(): string {
					return 'acme_check';
				}

				/**
				 * Rule label.
				 *
				 * @return string
				 */
				public function get_label(): string {
					return 'Acme check';
				}

				/**
				 * A group core has no heading for.
				 *
				 * @return string
				 */
				public function get_group(): string {
					return 'acme';
				}

				/**
				 * Always passes.
				 *
				 * @param Product_Context $context  The product.
				 * @param Settings        $settings The configuration.
				 * @return Rule_Result
				 */
				public function check( Product_Context $context, Settings $settings ): Rule_Result {
					unset( $context, $settings );

					return $this->pass();
				}
			}
		);

		$output = $this->render_screen( $registry );

		$this->assertGreaterThan( strpos( $output, '>Inventory</th>' ), strpos( $output, '>Other</th>' ) );
		$this->assertGreaterThan( strpos( $output, '>Other</th>' ), strpos( $output, '<strong>Acme check</strong>' ) );
	}

	/**
	 * A screen with nothing in an unknown group has no "Other" heading.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_other_heading_without_unknown_groups(): void {
		$this->assertStringNotContainsString( '>Other</th>', $this->render_screen( $this->built_in_registry() ) );
	}

	/**
	 * Every flag was built under these settings.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_readiness_line_current(): void {
		$registry = $this->built_in_registry();

		$this->store_index( Readiness_Store::signature( new Settings( $registry ) ), Readiness_Rebuild::STATE_COMPLETE, 7, 7 );

		$output = $this->render_screen( $registry );

		$this->assertStringContainsString( '<h2>Readiness filter</h2>', $output );
		$this->assertStringContainsString( 'All 7 products have been checked against these settings.', $output );
	}

	/**
	 * A rebuild is running.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_readiness_line_building(): void {
		$registry = $this->built_in_registry();

		$this->store_index( Readiness_Store::signature( new Settings( $registry ) ), Readiness_Rebuild::STATE_BUILDING, 9, 4 );

		$this->assertStringContainsString( 'Rechecking products against these settings: 4 of 9 done.', $this->render_screen( $registry ) );
	}

	/**
	 * Stale flags, and Action Scheduler is here to fix them.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_readiness_line_stale_with_scheduler(): void {
		$this->store_index( 'an-older-signature', Readiness_Rebuild::STATE_COMPLETE, 3, 3 );

		$this->assertStringContainsString(
			'Products will be rechecked against these settings in the background.',
			$this->render_screen( $this->built_in_registry() )
		);
	}

	/**
	 * Stale flags, and nothing can fix them in the background.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_readiness_line_no_scheduler(): void {
		$registry = $this->built_in_registry();
		$settings = new Settings( $registry );

		$this->store_index( 'an-older-signature', Readiness_Rebuild::STATE_COMPLETE, 3, 3 );

		$output = $this->render_screen( $registry, new No_Scheduler_Rebuild( $settings, new Readiness_Indexer( $settings, new Readiness_Store() ) ) );

		$this->assertStringContainsString(
			esc_html( 'Background rechecks need WooCommerce\'s Action Scheduler, which isn\'t available. Run "wp sit-wcpg reindex" to update the products list filter.' ),
			$output
		);
	}

	/**
	 * The status line has nothing true to say while nothing is being checked.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_readiness_section_while_the_checklist_is_off(): void {
		update_option( Settings::OPTION_NAME, array( 'enabled' => false ) );

		$this->assertStringNotContainsString( 'Readiness filter', $this->render_screen( $this->built_in_registry() ) );
	}

	/**
	 * Opening the screen on stale flags starts a rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_settings_screen_load_starts_a_rebuild_when_stale(): void {
		$registry = $this->built_in_registry();
		$settings = new Settings( $registry );
		$page     = new Settings_Page( $settings, $registry, $this->rebuild_for( $settings ) );

		$page->register();

		$this->assertNotFalse( has_action( 'load-' . Screen::SETTINGS_HOOK, array( $page, 'on_load' ) ) );

		$page->on_load();

		$index = get_option( Readiness_Rebuild::OPTION );

		$this->assertSame( Readiness_Rebuild::STATE_BUILDING, $index['state'] );
		$this->assertSame( Readiness_Store::signature( $settings ), $index['signature'] );

		as_unschedule_all_actions( Readiness_Rebuild::HOOK );
	}

	/**
	 * Free never links out from this screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_settings_page_has_no_outbound_links(): void {
		preg_match_all( '/\bhref="([^"]*)"/', $this->render_screen( $this->built_in_registry() ), $matches );

		$outbound = array_filter(
			$matches[1],
			static function ( string $href ): bool {
				return Settings_Footer::REVIEW_URL !== $href && 1 !== preg_match( '#(^|/)(admin|options)\.php(\?|$)#', $href );
			}
		);

		$this->assertSame( array(), array_values( $outbound ) );
	}

	/**
	 * The screen primes nothing by selling it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_settings_page_mentions_no_pro(): void {
		$this->assertDoesNotMatchRegularExpression(
			'/\b(pro|upgrade|premium)\b/i',
			$this->render_screen( $this->built_in_registry() )
		);
	}
}
