<?php
/**
 * The structural stored-XSS guarantee for rule output.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * No rule message may carry product-supplied text.
 *
 * This is the mitigation in coding-plan.md section 9.5, and it is worth testing directly
 * rather than trusting review: every message is a translated literal whose only
 * placeholders are integers, so hostile product data has nothing to travel on into the
 * checklist panel, the admin notices or the REST response.
 *
 * @since 1.0.0
 */
final class Rule_Message_Safety_Test extends TestCase {

	/**
	 * Strings planted in every text field of the hostile context.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const PAYLOADS = array(
		'<script>alert(1)</script>',
		'"><img src=x onerror=alert(1)>',
		"';DROP TABLE wp_posts;--",
		'{{constructor}}',
	);

	/**
	 * A populated registry of the eleven built-in rules.
	 *
	 * @since 1.0.0
	 * @var Rule_Registry
	 */
	private Rule_Registry $registry;

	/**
	 * Populate the registry.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function set_up() {
		$GLOBALS['sit_wcpg_test_actions'] = array();

		$this->registry = new Rule_Registry();

		Rules_Provider::populate( $this->registry );
	}

	/**
	 * Clear the globals the stubs write to.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function tear_down() {
		unset( $GLOBALS['sit_wcpg_test_actions'] );
	}

	/**
	 * A settings double with the given thresholds.
	 *
	 * @since 1.0.0
	 *
	 * @param array $thresholds Threshold values by key.
	 * @return Settings
	 */
	private function settings( array $thresholds ): Settings {
		$settings = $this->createMock( Settings::class );

		$settings->method( 'threshold' )->willReturnCallback(
			static function ( $key ) use ( $thresholds ) {
				return (int) ( $thresholds[ $key ] ?? 0 );
			}
		);

		return $settings;
	}

	/**
	 * Contexts built so that between them every rule branch is reached, with every text
	 * field carrying an attack payload.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function hostile_contexts(): array {
		$contexts = array();

		foreach ( self::PAYLOADS as $payload ) {
			// Everything missing or malformed: the failing branches.
			$contexts[] = Product_Context::from_array(
				array(
					'product_type'  => $payload,
					'title'         => '',
					'description'   => '',
					'regular_price' => $payload,
					'sale_price'    => $payload,
					'sku'           => '',
					'stock_status'  => $payload,
				)
			);

			// Everything present but below par: the advisory branches.
			$contexts[] = Product_Context::from_array(
				array(
					'product_type'        => 'simple',
					'title'               => $payload,
					'description'         => $payload,
					'short_description'   => $payload,
					'featured_image_id'   => 9,
					'gallery_image_ids'   => array( 9 ),
					'regular_price'       => '10',
					'sale_price'          => '20',
					'sale_from'           => $payload,
					'sale_to'             => $payload,
					'sku'                 => $payload,
					'stock_status'        => 'instock',
					'manage_stock'        => true,
					'stock_quantity'      => 0,
					'category_ids'        => array( 15 ),
					'default_category_id' => 15,
					'tag_ids'             => array(),
				)
			);
		}

		return $contexts;
	}

	/**
	 * Not one payload reaches a message, under any threshold configuration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_message_ever_repeats_product_text() {
		$configurations = array(
			array(),
			array(
				'min_description_chars'       => 150,
				'min_short_description_chars' => 50,
				'min_images'                  => 3,
			),
		);

		$seen = 0;

		foreach ( $configurations as $thresholds ) {
			$settings = $this->settings( $thresholds );

			foreach ( $this->hostile_contexts() as $context ) {
				foreach ( $this->registry->all() as $rule ) {
					$message = $rule->check( $context, $settings )->get_message();

					++$seen;

					foreach ( self::PAYLOADS as $payload ) {
						$this->assertStringNotContainsString(
							$payload,
							$message,
							$rule->get_id() . ' repeated product text back into its message.'
						);
					}

					$this->assertSame(
						$message,
						wp_strip_all_tags( $message ),
						$rule->get_id() . ' produced a message containing markup.'
					);
				}
			}
		}

		$this->assertGreaterThan( 0, $seen );
	}

	/**
	 * The `data` payload carries numbers and booleans only, so the UI has nothing
	 * unescapable to render even if a rule tried to pass text through it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_data_payload_ever_carries_text() {
		$settings = $this->settings( array( 'min_images' => 3 ) );

		foreach ( $this->hostile_contexts() as $context ) {
			foreach ( $this->registry->all() as $rule ) {
				foreach ( $rule->check( $context, $settings )->get_data() as $key => $value ) {
					$this->assertTrue(
						is_int( $value ) || is_float( $value ) || is_bool( $value ),
						$rule->get_id() . ' put a non-numeric value in data["' . $key . '"].'
					);
				}
			}
		}
	}

	/**
	 * No rule source file contains a string placeholder at all.
	 *
	 * The runtime assertions above prove the shipped branches are safe; this one closes
	 * the door on the next rule someone writes, because `%s` is the only way product
	 * text could get into a message in the first place.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_rule_interpolates_a_string_placeholder() {
		$files = glob( SIT_WCPG_PATH . 'src/Rules/*.php' );

		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			$this->assertSame(
				0,
				preg_match( '/%(?:\d+\$)?s/', $source ),
				basename( $file ) . ' interpolates a string into a message.'
			);
		}
	}
}
