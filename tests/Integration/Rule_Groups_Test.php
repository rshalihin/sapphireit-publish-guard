<?php
/**
 * Tests for the filter that lets an add-on name its own checklist groups.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Support\Readiness_Rebuild;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * The five core groups are fixed. An add-on can only append groups of its own.
 *
 * @since 1.3.0
 */
final class Rule_Groups_Test extends WP_UnitTestCase {

	/**
	 * The core groups, in order.
	 *
	 * @since 1.3.0
	 * @var array<string, string>
	 */
	private const CORE = array(
		'content'      => 'Content',
		'media'        => 'Media',
		'pricing'      => 'Pricing',
		'organization' => 'Organization',
		'inventory'    => 'Inventory',
	);

	/**
	 * A clean slate.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		delete_option( Readiness_Rebuild::OPTION );
		Plugin::instance()->settings()->refresh();
		Checklist_Service::flush_memo();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leave no screen, post or script registry behind.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'], $GLOBALS['post'] );

		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.

		Checklist_Service::flush_memo();

		parent::tear_down();
	}

	/**
	 * Run the group map through a callback.
	 *
	 * @since 1.3.0
	 *
	 * @param callable $callback Receives and returns the map.
	 * @return void
	 */
	private function filter_groups( callable $callback ): void {
		add_filter( 'sit_wcpg_rule_groups', $callback );
	}

	/**
	 * With no filter the map is the five core groups.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_core_groups_keep_labels_and_order(): void {
		$this->assertSame( self::CORE, Editor_Meta_Box::group_labels() );

		$this->filter_groups(
			static function ( array $labels ): array {
				unset( $labels['content'] );

				$labels['media'] = 'Pictures';

				return array_reverse( $labels, true );
			}
		);

		$this->assertSame( self::CORE, Editor_Meta_Box::group_labels() );
	}

	/**
	 * A filter that returns nonsense changes nothing.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_non_array_result_is_ignored(): void {
		$this->filter_groups( '__return_false' );

		$this->assertSame( self::CORE, Editor_Meta_Box::group_labels() );
	}

	/**
	 * Added groups follow the core ones, in the order they were added.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_added_group_is_appended(): void {
		$this->filter_groups(
			static function ( array $labels ): array {
				return array_merge(
					array(
						'identifiers'  => 'Identifiers',
						'store_policy' => 'Store policy',
					),
					$labels
				);
			}
		);

		$this->assertSame(
			array_merge(
				self::CORE,
				array(
					'identifiers'  => 'Identifiers',
					'store_policy' => 'Store policy',
				)
			),
			Editor_Meta_Box::group_labels()
		);
	}

	/**
	 * A key outside the pattern, and a label that is not a non-empty string, are dropped.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_invalid_keys_dropped(): void {
		$this->filter_groups(
			static function ( array $labels ): array {
				$labels['Bad Key']               = 'Bad';
				$labels['1starts_with_a_digit']  = 'Bad';
				$labels[ str_repeat( 'a', 31 ) ] = 'Too long';
				$labels['not_a_string']          = array( 'x' );
				$labels['empty_label']           = '';
				$labels[7]                       = 'Numeric';
				$labels['fine']                  = 'Fine';

				return $labels;
			}
		);

		$this->assertSame( array_merge( self::CORE, array( 'fine' => 'Fine' ) ), Editor_Meta_Box::group_labels() );
	}

	/**
	 * Ten added groups at most.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_capped_at_ten_added_groups(): void {
		$this->filter_groups(
			static function ( array $labels ): array {
				for ( $i = 1; $i <= 12; $i++ ) {
					$labels[ 'extra_' . $i ] = 'Extra ' . $i;
				}

				return $labels;
			}
		);

		$labels = Editor_Meta_Box::group_labels();

		$this->assertCount( 15, $labels );
		$this->assertArrayHasKey( 'extra_10', $labels );
		$this->assertArrayNotHasKey( 'extra_11', $labels );
	}

	/**
	 * The editor payload and the settings screen both use the filtered map.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_added_group_reaches_payload_and_settings_grouping(): void {
		$this->filter_groups(
			static function ( array $labels ): array {
				$labels['probe'] = 'Probe';

				return $labels;
			}
		);

		// The settings screen: a rule in the added group sits under its heading, not "Other".
		$registry = new Rule_Registry();
		$registry->register(
			new class() extends Abstract_Rule {

				/**
				 * Rule identifier.
				 *
				 * @return string
				 */
				public function get_id(): string {
					return 'grouped_probe';
				}

				/**
				 * Rule label.
				 *
				 * @return string
				 */
				public function get_label(): string {
					return 'Grouped probe';
				}

				/**
				 * The added group.
				 *
				 * @return string
				 */
				public function get_group(): string {
					return 'probe';
				}

				/**
				 * Always pass.
				 *
				 * @param Product_Context $context  The product being validated.
				 * @param Settings        $settings The merchant's configuration.
				 * @return Rule_Result
				 */
				public function check( Product_Context $context, Settings $settings ): Rule_Result {
					unset( $context, $settings );

					return $this->pass();
				}
			}
		);

		ob_start();
		( new Settings_Page( new Settings( $registry ), $registry ) )->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<th colspan="3" scope="colgroup">Probe</th>', $output );
		$this->assertStringNotContainsString( '<th colspan="3" scope="colgroup">Other</th>', $output );

		// The editor payload.
		if ( ! file_exists( SIT_WCPG_PATH . 'build/editor.js' ) ) {
			$this->markTestSkipped( 'Run `npm run build` first: build/editor.js is missing.' );
		}

		$product = new WC_Product_Simple();
		$product->set_name( 'Edited product' );
		$product->save();

		$GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core sets it on the editor screen.

		set_current_screen( Screen::POST_TYPE );
		( new Assets( Plugin::instance()->settings() ) )->enqueue( 'post.php' );

		$inline  = implode( '', (array) wp_scripts()->get_data( Assets::EDITOR_HANDLE, 'before' ) );
		$payload = json_decode( substr( $inline, strlen( 'window.' . Assets::PAYLOAD_GLOBAL . ' = ' ), -1 ), true );

		$this->assertSame( array_merge( self::CORE, array( 'probe' => 'Probe' ) ), $payload['groups'] );
	}
}
