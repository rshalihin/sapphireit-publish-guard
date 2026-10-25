<?php
/**
 * The structural stored-XSS guarantee for rule output.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Status;
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
	 * A populated registry of the twelve built-in rules.
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

			// A variable product whose variations carry the payloads: the per-variation branches.
			$contexts[] = Product_Context::from_array(
				array(
					'product_type'  => 'variable',
					'title'         => $payload,
					'regular_price' => $payload,
					'sku'           => '',
					'stock_status'  => $payload,
					'variations'    => array(
						array(
							'id'            => 3,
							'regular_price' => $payload,
							'sale_price'    => $payload,
							'sale_from'     => $payload,
							'sale_to'       => $payload,
							'sku'           => $payload,
							'stock_status'  => $payload,
						),
						array(
							'id'            => 7,
							'regular_price' => $payload,
							'sku'           => '',
							'stock_status'  => $payload,
						),
					),
				)
			);

			// No parent SKU, every variation has its own; valid sales; a managed quantity of zero.
			$contexts[] = Product_Context::from_array(
				array(
					'product_type'  => 'variable',
					'title'         => $payload,
					'regular_price' => $payload,
					'sale_price'    => $payload,
					'sku'           => '',
					'stock_status'  => $payload,
					'variations'    => array(
						array(
							'id'             => 3,
							'regular_price'  => '20',
							'sale_price'     => '15',
							'sale_from'      => $payload,
							'sale_to'        => $payload,
							'sku'            => $payload,
							'stock_status'   => 'instock',
							'manage_stock'   => 'yes',
							'stock_quantity' => 0,
						),
						array(
							'id'             => 7,
							'regular_price'  => '20',
							'sku'            => $payload,
							'stock_status'   => 'instock',
							'manage_stock'   => 'parent',
							'stock_quantity' => -1,
						),
					),
				)
			);

			// Nothing on sale, variations out of stock, a manage_stock value WooCommerce never stores.
			$contexts[] = Product_Context::from_array(
				array(
					'product_type'  => 'variable',
					'title'         => $payload,
					'regular_price' => $payload,
					'sale_price'    => $payload,
					'sku'           => $payload,
					'stock_status'  => $payload,
					'variations'    => array(
						array(
							'id'             => 3,
							'regular_price'  => $payload,
							'sale_price'     => '',
							'sale_from'      => $payload,
							'sale_to'        => $payload,
							'sku'            => $payload,
							'stock_status'   => 'outofstock',
							'manage_stock'   => $payload,
							'stock_quantity' => 0,
						),
					),
				)
			);

			// Healthy variations: every per-variation check passes.
			$contexts[] = Product_Context::from_array(
				array(
					'product_type'  => 'variable',
					'title'         => $payload,
					'regular_price' => $payload,
					'sale_price'    => $payload,
					'sku'           => '',
					'stock_status'  => $payload,
					'variations'    => array(
						array(
							'id'             => 3,
							'regular_price'  => '20',
							'sale_price'     => '15',
							'sale_from'      => '2026-05-01 00:00:00',
							'sale_to'        => '2026-05-10 00:00:00',
							'sku'            => $payload,
							'stock_status'   => 'onbackorder',
							'manage_stock'   => 'parent',
							'stock_quantity' => 5,
						),
					),
				)
			);

			// A variable product with no enabled variation, with and without a parent SKU.
			foreach ( array( $payload, '' ) as $sku ) {
				$contexts[] = Product_Context::from_array(
					array(
						'product_type'  => 'variable',
						'title'         => $payload,
						'regular_price' => $payload,
						'sale_price'    => $payload,
						'sku'           => $sku,
						'stock_status'  => $payload,
					)
				);
			}

			// An external product: its stock is never checked.
			$contexts[] = Product_Context::from_array(
				array(
					'product_type'  => 'external',
					'title'         => $payload,
					'regular_price' => $payload,
					'sale_price'    => $payload,
					'sku'           => $payload,
					'stock_status'  => $payload,
				)
			);

			// Grouped products: one empty, one with nothing published.
			foreach ( array( array(), array( 11, 12 ) ) as $child_ids ) {
				$contexts[] = Product_Context::from_array(
					array(
						'product_type'          => 'grouped',
						'title'                 => $payload,
						'regular_price'         => $payload,
						'sale_price'            => $payload,
						'sku'                   => $payload,
						'stock_status'          => $payload,
						'child_ids'             => $child_ids,
						'published_child_count' => 0,
					)
				);
			}
		}

		return $contexts;
	}

	/**
	 * The hostile contexts reach every per-variation branch of the variation-aware rules,
	 * and every branch that depends on the type family, so the two assertions above cover
	 * each one rather than only the first failure.
	 *
	 * Each branch is named by its rule, its outcome and a fragment of its fixed copy.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_hostile_contexts_reach_every_per_variation_branch() {
		$branches = array(
			array( 'price', Status::FAIL, 'no valid regular price (first: variation' ),
			array( 'price', Status::SKIPPED, 'no enabled variations yet' ),
			array( 'sale_price', Status::FAIL, 'on sale has a sale price problem' ),
			array( 'sale_price', Status::PASS, '' ),
			array( 'sale_price', Status::SKIPPED, 'No enabled variation is on sale.' ),
			array( 'sale_price', Status::SKIPPED, 'no enabled variations yet' ),
			array( 'sale_price', Status::SKIPPED, 'A grouped product has no sale price' ),
			array( 'sku', Status::FAIL, 'none to pass down' ),
			array( 'sku', Status::PASS, '' ),
			array( 'sku', Status::SKIPPED, 'no enabled variations yet' ),
			array( 'stock_status', Status::FAIL, 'no stock status (first: variation' ),
			array( 'stock_status', Status::WARNING, 'with a quantity of zero or less' ),
			array( 'stock_status', Status::WARNING, 'marked out of stock (first: variation' ),
			array( 'stock_status', Status::PASS, '' ),
			array( 'stock_status', Status::SKIPPED, 'no enabled variations yet' ),
			array( 'stock_status', Status::SKIPPED, 'A grouped product has no stock' ),
			array( 'stock_status', Status::SKIPPED, 'External products have no stock' ),
			array( 'image_count', Status::FAIL, 'This product and its variations have' ),
		);

		$settings = $this->settings( array( 'min_images' => 3 ) );
		$reached  = array();

		foreach ( $this->hostile_contexts() as $context ) {
			if ( ! in_array( $context->get_type_family(), array( 'variable', 'grouped', 'external' ), true ) ) {
				continue;
			}

			foreach ( $this->registry->all() as $rule ) {
				$result = $rule->check( $context, $settings );

				foreach ( $branches as $index => $branch ) {
					list( $rule_id, $outcome, $fragment ) = $branch;

					if ( $rule_id !== $rule->get_id() || $outcome !== $result->get_outcome() ) {
						continue;
					}

					if ( '' === $fragment ? '' === $result->get_message() : false !== strpos( $result->get_message(), $fragment ) ) {
						$reached[ $index ] = true;
					}
				}
			}
		}

		foreach ( $branches as $index => $branch ) {
			$this->assertArrayHasKey( $index, $reached, implode( ' / ', $branch ) . ' is never reached by a hostile context.' );
		}
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
	 * Every failure and warning names the fix after an em dash (D9).
	 *
	 * The hostile contexts already reach a failure in every rule; the extra contexts reach
	 * the branches they miss, so each shipped message is checked at least once.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_every_failure_message_names_a_fix_after_an_em_dash() {
		$contexts = array_merge(
			$this->hostile_contexts(),
			array(
				Product_Context::from_array(
					array(
						'featured_image_id'        => 9,
						'has_valid_featured_image' => false,
						'regular_price'            => '10',
						'sale_price'               => '-1',
						'stock_status'             => 'outofstock',
					)
				),
				Product_Context::from_array(
					array(
						'regular_price' => '10',
						'sale_price'    => '5',
						'sale_from'     => '2026-12-01 00:00:00',
						'sale_to'       => '2026-11-01 00:00:00',
					)
				),
			)
		);

		$settings = $this->settings(
			array(
				'min_description_chars'       => 150,
				'min_short_description_chars' => 50,
				'min_images'                  => 3,
			)
		);

		$flagged = array();

		foreach ( $contexts as $context ) {
			foreach ( $this->registry->all() as $rule ) {
				$result = $rule->check( $context, $settings );

				if ( ! in_array( $result->get_outcome(), array( Status::FAIL, Status::WARNING ), true ) ) {
					continue;
				}

				$flagged[ $rule->get_id() ] = true;

				$this->assertStringContainsString(
					' — ',
					$result->get_message(),
					$rule->get_id() . ' reported "' . $result->get_message() . '" without naming the fix.'
				);
			}
		}

		$this->assertCount( count( $this->registry->all() ), $flagged, 'Every built-in rule must reach a failing branch here.' );
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
