<?php
/**
 * The product-type matrix: the false-block contract.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Rules\Variation_Fix;
use ProductPublishGuard\Settings\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every cell of the product-type matrix (coding-plan §3.1), on the complete fixtures of §3.3.
 *
 * A legitimately complete product of any family must pass every built-in rule under the
 * default settings, and every cell the matrix marks "skip" must skip with its exact reason.
 *
 * @since 1.1.0
 */
final class Product_Type_Matrix_Test extends TestCase {

	/**
	 * The twelve built-in rules.
	 *
	 * @since 1.1.0
	 * @var Rule_Registry
	 */
	private Rule_Registry $registry;

	/**
	 * Populate the registry.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 *
	 * @return void
	 */
	protected function tear_down() {
		unset( $GLOBALS['sit_wcpg_test_actions'] );
	}

	/**
	 * A settings double with the v1.0 defaults: thresholds 150, 50 and 2, every rule
	 * enabled, every rule at its default severity.
	 *
	 * @since 1.1.0
	 *
	 * @return Settings
	 */
	private function settings(): Settings {
		$registry   = $this->registry;
		$thresholds = array(
			'min_description_chars'       => 150,
			'min_short_description_chars' => 50,
			'min_images'                  => 2,
		);

		$settings = $this->createMock( Settings::class );

		$settings->method( 'threshold' )->willReturnCallback(
			static function ( $key ) use ( $thresholds ) {
				return $thresholds[ $key ] ?? 0;
			}
		);
		$settings->method( 'rule_is_enabled' )->willReturn( true );
		$settings->method( 'rule_severity' )->willReturnCallback(
			static function ( $rule_id ) use ( $registry ) {
				return $registry->get( $rule_id )->get_default_severity();
			}
		);

		return $settings;
	}

	/**
	 * The state every complete fixture shares (§3.3).
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private static function complete_base(): array {
		return array(
			'title'               => 'A complete product',
			'description'         => str_repeat( 'A full description of the product. ', 5 ),
			'short_description'   => 'A short description of the product, comfortably long enough.',
			'featured_image_id'   => 10,
			'gallery_image_ids'   => array( 11 ),
			'category_ids'        => array( 15 ),
			'default_category_id' => 1,
			'tag_ids'             => array( 20 ),
			'sku'                 => 'SKU-1',
			'stock_status'        => 'instock',
		);
	}

	/**
	 * Three enabled variations, each priced, in stock and with its own SKU.
	 *
	 * The fixture's fourth, disabled variation (no price, no SKU) is not seeded: the loader
	 * drops disabled variations before they reach the context (D4, `Child_Loader_Test`),
	 * and Phase 8 runs the same fixture on real WooCommerce objects.
	 *
	 * @since 1.1.0
	 *
	 * @param array $overrides Values applied to every variation.
	 * @return array
	 */
	private static function enabled_variations( array $overrides = array() ): array {
		$variations = array();

		foreach ( array( 101, 102, 103 ) as $index => $id ) {
			$variations[] = array_merge(
				array(
					'id'            => $id,
					'regular_price' => '10',
					'sku'           => 'SKU-1-' . ( $index + 1 ),
					'stock_status'  => 'instock',
				),
				$overrides
			);
		}

		return $variations;
	}

	/**
	 * Every fixture by name: the eleven complete ones of §3.3, then the incomplete ones
	 * that only the skip cells use.
	 *
	 * The `virtual`, `downloadable` and `product_url` keys are not context keys, so the
	 * context drops them. That is the point: no rule may depend on them (§3).
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array>
	 */
	private static function fixtures(): array {
		$base = self::complete_base();

		$variable = array_merge(
			$base,
			array(
				'product_type' => 'variable',
				'sku'          => '',
				'variations'   => self::enabled_variations(),
			)
		);

		return array(
			'simple'                          => array_merge(
				$base,
				array(
					'product_type'  => 'simple',
					'regular_price' => '19.99',
				)
			),
			'simple virtual'                  => array_merge(
				$base,
				array(
					'product_type'  => 'simple',
					'regular_price' => '19.99',
					'virtual'       => true,
				)
			),
			'simple downloadable'             => array_merge(
				$base,
				array(
					'product_type'  => 'simple',
					'regular_price' => '19.99',
					'downloadable'  => true,
				)
			),
			'simple, price 0'                 => array_merge(
				$base,
				array(
					'product_type'  => 'simple',
					'regular_price' => '0',
				)
			),
			'subscription'                    => array_merge(
				$base,
				array(
					'product_type'  => 'subscription',
					'regular_price' => '9.99',
				)
			),
			'variable'                        => $variable,
			'variable, parent SKU'            => array_merge(
				$variable,
				array(
					'sku'        => 'SKU-1',
					'variations' => self::enabled_variations( array( 'sku' => '' ) ),
				)
			),
			'variable, parent-managed stock'  => array_merge(
				$variable,
				array(
					'manage_stock'   => true,
					'stock_quantity' => 5,
					// The view context reports the parent's quantity on a parent-managed variation.
					'variations'     => self::enabled_variations(
						array(
							'manage_stock'   => 'parent',
							'stock_quantity' => 5,
						)
					),
				)
			),
			'variable-subscription'           => array_merge( $variable, array( 'product_type' => 'variable-subscription' ) ),
			'grouped'                         => array_merge(
				$base,
				array(
					'product_type'          => 'grouped',
					'child_ids'             => array( 31, 32 ),
					'published_child_count' => 2,
				)
			),
			'external'                        => array_merge(
				$base,
				array(
					'product_type'  => 'external',
					'regular_price' => '25',
					'product_url'   => 'https://example.com/product',
				)
			),
			'variable, 0 enabled'             => array_merge( $variable, array( 'variations' => array() ) ),
			'variable, 0 enabled, parent SKU' => array_merge(
				$variable,
				array(
					'sku'        => 'SKU-1',
					'variations' => array(),
				)
			),
		);
	}

	/**
	 * The names of the eleven complete fixtures of §3.3.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	private const COMPLETE = array(
		'simple',
		'simple virtual',
		'simple downloadable',
		'simple, price 0',
		'subscription',
		'variable',
		'variable, parent SKU',
		'variable, parent-managed stock',
		'variable-subscription',
		'grouped',
		'external',
	);

	/**
	 * One context per complete fixture.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{0: Product_Context}>
	 */
	public function complete_products(): array {
		$fixtures = self::fixtures();
		$rows     = array();

		foreach ( self::COMPLETE as $name ) {
			$rows[ $name ] = array( Product_Context::from_array( $fixtures[ $name ] ) );
		}

		return $rows;
	}

	/**
	 * The eleven complete fixtures are all present.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_every_complete_fixture_is_provided() {
		$this->assertCount( 11, $this->complete_products() );
	}

	/**
	 * No built-in rule reports a problem on a legitimately complete product.
	 *
	 * Every rule runs on every family (D10): a rule that does not apply skips with a
	 * reason, it never opts out through `supports()`.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider complete_products
	 *
	 * @param Product_Context $context The complete product.
	 * @return void
	 */
	public function test_no_rule_fires_on_a_complete_product( Product_Context $context ) {
		$fixture  = (string) $this->dataName();
		$settings = $this->settings();
		$active   = $this->registry->get_active( $settings, $context );

		$this->assertCount( 12, $active, "Every built-in rule must run on the {$fixture} fixture." );

		foreach ( $active as $rule ) {
			$outcome = $rule->check( $context, $settings )->get_outcome();

			$this->assertNotContains(
				$outcome,
				array( Status::FAIL, Status::WARNING ),
				"{$rule->get_id()} reported {$outcome} on the complete {$fixture} fixture."
			);
		}
	}

	/**
	 * Every "skip" cell of §3.1, as `[ fixture, rule id, expected reason ]`.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function skip_cells(): array {
		$zero          = Variation_Fix::zero_variations_reason();
		$not_a_parent  = 'Only variable and grouped products have variations or linked products.';
		$grouped_stock = 'A grouped product has no stock of its own — its products carry their own.';

		return array(
			'variable, 0 enabled / price'          => array( 'variable, 0 enabled', 'price', $zero ),
			'variable, 0 enabled / sale_price'     => array( 'variable, 0 enabled', 'sale_price', $zero ),
			'variable, 0 enabled / sku'            => array( 'variable, 0 enabled', 'sku', $zero ),
			'variable, 0 enabled / stock_status'   => array( 'variable, 0 enabled', 'stock_status', $zero ),
			'grouped / price'                      => array( 'grouped', 'price', 'A grouped product has no price of its own — its products carry their own prices.' ),
			'grouped / sale_price'                 => array( 'grouped', 'sale_price', 'A grouped product has no sale price of its own.' ),
			'grouped / stock_status'               => array( 'grouped', 'stock_status', $grouped_stock ),
			'external / stock_status'              => array( 'external', 'stock_status', 'External products have no stock — the store they link to sells them.' ),
			'simple / child_products'              => array( 'simple', 'child_products', $not_a_parent ),
			'simple virtual / child_products'      => array( 'simple virtual', 'child_products', $not_a_parent ),
			'simple downloadable / child_products' => array( 'simple downloadable', 'child_products', $not_a_parent ),
			'subscription / child_products'        => array( 'subscription', 'child_products', $not_a_parent ),
			'external / child_products'            => array( 'external', 'child_products', $not_a_parent ),
		);
	}

	/**
	 * A rule that does not apply to a family says why.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider skip_cells
	 *
	 * @param string $fixture         Fixture name.
	 * @param string $rule_id         Rule identifier.
	 * @param string $expected_reason Exact skip message.
	 * @return void
	 */
	public function test_skip_reason( string $fixture, string $rule_id, string $expected_reason ) {
		$rule = $this->registry->get( $rule_id );

		$this->assertNotNull( $rule, "{$rule_id} is not registered." );

		$result = $rule->check( Product_Context::from_array( self::fixtures()[ $fixture ] ), $this->settings() );

		$this->assertSame( Status::SKIPPED, $result->get_outcome(), "{$rule_id} on {$fixture}" );
		$this->assertSame( $expected_reason, $result->get_message(), "{$rule_id} on {$fixture}" );
	}

	/**
	 * Every "applies" and "own" cell of §3.1, as `[ fixture, rule id, parent overrides,
	 * first-variation overrides, expected outcome ]`.
	 *
	 * The complete-product test alone cannot tell "applies" from a wrong skip, since both
	 * pass. Here each fixture is broken in the one field the rule reads, and the rule must
	 * report it.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{0: string, 1: string, 2: array, 3: array, 4: string}>
	 */
	public function applies_cells(): array {
		$parent_rules = array(
			'title'             => array( 'title' => '' ),
			'description'       => array( 'description' => '' ),
			'short_description' => array( 'short_description' => '' ),
			'category'          => array( 'category_ids' => array() ),
			'tags'              => array( 'tag_ids' => array() ),
			'featured_image'    => array( 'featured_image_id' => 0 ),
			'image_count'       => array(
				'featured_image_id' => 0,
				'gallery_image_ids' => array(),
			),
		);

		$cells = array();

		foreach ( array( 'simple', 'variable', 'variable, 0 enabled', 'grouped', 'external' ) as $fixture ) {
			foreach ( $parent_rules as $rule_id => $broken ) {
				$cells[ "{$fixture} / {$rule_id}" ] = array( $fixture, $rule_id, $broken, array(), Status::FAIL );
			}
		}

		return array_merge(
			$cells,
			array(
				'simple / price'                           => array( 'simple', 'price', array( 'regular_price' => '' ), array(), Status::FAIL ),
				'subscription / price'                     => array( 'subscription', 'price', array( 'regular_price' => '' ), array(), Status::FAIL ),
				'external / price'                         => array( 'external', 'price', array( 'regular_price' => '' ), array(), Status::FAIL ),
				'variable / price'                         => array( 'variable', 'price', array(), array( 'regular_price' => '' ), Status::FAIL ),
				'simple / sale_price'                      => array( 'simple', 'sale_price', array( 'sale_price' => '30' ), array(), Status::FAIL ),
				'external / sale_price'                    => array( 'external', 'sale_price', array( 'sale_price' => '30' ), array(), Status::FAIL ),
				'variable / sale_price'                    => array( 'variable', 'sale_price', array(), array( 'sale_price' => '20' ), Status::FAIL ),
				'simple / sku'                             => array( 'simple', 'sku', array( 'sku' => '' ), array(), Status::FAIL ),
				'grouped / sku'                            => array( 'grouped', 'sku', array( 'sku' => '' ), array(), Status::FAIL ),
				'external / sku'                           => array( 'external', 'sku', array( 'sku' => '' ), array(), Status::FAIL ),
				'variable / sku'                           => array( 'variable', 'sku', array(), array( 'sku' => '' ), Status::FAIL ),
				'simple / stock_status'                    => array( 'simple', 'stock_status', array( 'stock_status' => '' ), array(), Status::FAIL ),
				'variable / stock_status'                  => array( 'variable', 'stock_status', array(), array( 'stock_status' => '' ), Status::FAIL ),
				'variable, parent-managed / stock_status'  => array( 'variable, parent-managed stock', 'stock_status', array(), array( 'stock_quantity' => 0 ), Status::WARNING ),
				'grouped, none published / child_products' => array( 'grouped', 'child_products', array( 'published_child_count' => 0 ), array(), Status::FAIL ),
				'grouped, empty / child_products'          => array(
					'grouped',
					'child_products',
					array(
						'child_ids'             => array(),
						'published_child_count' => 0,
					),
					array(),
					Status::FAIL,
				),
			)
		);
	}

	/**
	 * A rule that applies to a family reports a broken field on it.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider applies_cells
	 *
	 * @param string $fixture             Fixture name.
	 * @param string $rule_id             Rule identifier.
	 * @param array  $parent_overrides    Values that break the product.
	 * @param array  $variation_overrides Values that break its first variation.
	 * @param string $expected            Expected outcome.
	 * @return void
	 */
	public function test_applies_cell( string $fixture, string $rule_id, array $parent_overrides, array $variation_overrides, string $expected ) {
		$data = array_merge( self::fixtures()[ $fixture ], $parent_overrides );

		if ( array() !== $variation_overrides ) {
			$data['variations'][0] = array_merge( $data['variations'][0], $variation_overrides );
		}

		$result = $this->registry->get( $rule_id )->check( Product_Context::from_array( $data ), $this->settings() );

		$this->assertSame( $expected, $result->get_outcome(), "{$rule_id} on a broken {$fixture} fixture" );
	}

	/**
	 * The two non-skip cells of the zero-variation column: a parent SKU still passes the
	 * SKU rule, and `child_products` carries the failure the other rules skip.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_zero_variation_column_non_skip_cells() {
		$fixtures = self::fixtures();
		$settings = $this->settings();

		$sku = $this->registry->get( 'sku' )->check( Product_Context::from_array( $fixtures['variable, 0 enabled, parent SKU'] ), $settings );

		$this->assertSame( Status::PASS, $sku->get_outcome() );

		$children = $this->registry->get( 'child_products' )->check( Product_Context::from_array( $fixtures['variable, 0 enabled'] ), $settings );

		$this->assertSame( Status::FAIL, $children->get_outcome() );
		$this->assertSame( Status::WARNING, $children->with_severity( $settings->rule_severity( 'child_products' ) )->get_status() );
	}

	/**
	 * Images on enabled variations count on a variable fixture, and only the parent's on
	 * one without variations.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_image_count_cells() {
		$fixtures = self::fixtures();
		$rule     = $this->registry->get( 'image_count' );

		$with_images = $fixtures['variable'];

		$with_images['variations'][0]['image_id'] = 40;

		$this->assertSame( 3, $rule->check( Product_Context::from_array( $with_images ), $this->settings() )->get_data()['image_count'] );
		$this->assertSame( 2, $rule->check( Product_Context::from_array( $fixtures['variable, 0 enabled'] ), $this->settings() )->get_data()['image_count'] );
	}
}
