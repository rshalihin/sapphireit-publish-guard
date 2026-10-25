<?php
/**
 * No false block on real WooCommerce objects.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Plugin;
use WC_Product_Simple;

/**
 * The roadmap's "false-block elimination": every legitimately complete fixture of
 * coding-plan.md §3.3 that WooCommerce can create publishes through every guard layer,
 * and nothing about it fails or warns under the default settings.
 *
 * The two subscription fixtures are unit-only (WooCommerce Subscriptions is not installed);
 * `Product_Type_Matrix_Test` covers them.
 *
 * @since 1.1.0
 */
final class Publish_Guard_No_False_Block_Test extends Publish_Guard_Test_Case {

	/**
	 * The §3.3 fixtures WooCommerce can create.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	private const FIXTURES = array(
		'simple',
		'simple virtual',
		'simple downloadable',
		'simple, price 0',
		'variable',
		'variable, parent SKU',
		'variable, parent-managed stock',
		'variable, second image on a variation',
		'grouped',
		'external',
	);

	/**
	 * Every fixture through every layer.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function fixture_layer_provider(): array {
		$cases = array();

		foreach ( self::FIXTURES as $fixture ) {
			foreach ( self::LAYERS as $layer ) {
				$cases[ $fixture . ' / ' . $layer ] = array( $fixture, $layer );
			}
		}

		return $cases;
	}

	/**
	 * The state every §3.3 fixture shares, on top of the passing props: a tag and one
	 * gallery image (two distinct images, the `min_images` default).
	 *
	 * @since 1.1.0
	 *
	 * @param array $props Props to change.
	 * @return array
	 */
	private function complete_props( array $props = array() ): array {
		$tag = wp_insert_term( 'sit-wcpg-' . wp_generate_password( 6, false ), 'product_tag' );

		return array_merge(
			array(
				'tag_ids'           => array( (int) $tag['term_id'] ),
				'gallery_image_ids' => array( $this->image() ),
			),
			$props
		);
	}

	/**
	 * A variation's props: priced, in stock, with or without its own SKU.
	 *
	 * @since 1.1.0
	 *
	 * @param bool  $own_sku Whether it has a SKU of its own.
	 * @param array $props   Props to change.
	 * @return array
	 */
	private static function variation( bool $own_sku, array $props = array() ): array {
		return array_merge(
			array(
				'regular_price' => '10',
				'stock_status'  => 'instock',
				'sku'           => $own_sku ? 'SIT-WCPG-' . wp_generate_password( 8, false ) : '',
			),
			$props
		);
	}

	/**
	 * Build one §3.3 fixture as a stored draft.
	 *
	 * @since 1.1.0
	 *
	 * @param string $fixture One of `FIXTURES`.
	 * @return int Product id.
	 */
	private function build( string $fixture ): int {
		switch ( $fixture ) {
			case 'simple':
				return $this->passing_product( 'draft', $this->complete_props() );

			case 'simple virtual':
				return $this->passing_product( 'draft', $this->complete_props( array( 'virtual' => true ) ) );

			case 'simple downloadable':
				return $this->passing_product( 'draft', $this->complete_props( array( 'downloadable' => true ) ) );

			case 'simple, price 0':
				return $this->passing_product( 'draft', $this->complete_props( array( 'regular_price' => '0' ) ) );

			case 'variable':
				// The fourth, disabled variation has no price and no SKU, and must be ignored.
				return $this->variable_product(
					array(
						self::variation( true ),
						self::variation( true ),
						self::variation( true ),
						array(
							'regular_price' => '',
							'sku'           => '',
							'status'        => 'private',
						),
					),
					'draft',
					$this->complete_props( array( 'sku' => '' ) )
				);

			case 'variable, parent SKU':
				return $this->variable_product(
					array( self::variation( false ), self::variation( false ), self::variation( false ) ),
					'draft',
					$this->complete_props()
				);

			case 'variable, parent-managed stock':
				$parent_managed = array( 'manage_stock' => false );

				return $this->variable_product(
					array(
						self::variation( true, $parent_managed ),
						self::variation( true, $parent_managed ),
						self::variation( true, $parent_managed ),
					),
					'draft',
					$this->complete_props(
						array(
							'sku'            => '',
							'manage_stock'   => true,
							'stock_quantity' => 5,
						)
					)
				);

			case 'variable, second image on a variation':
				// The parent has only its featured image, so `image_count` passes only when the
				// variation's gallery is read. WC 9.0 already stores it (R1): no floor skip.
				return $this->variable_product(
					array(
						self::variation( true, array( 'gallery_image_ids' => array( $this->image() ) ) ),
						self::variation( true ),
					),
					'draft',
					$this->complete_props(
						array(
							'sku'               => '',
							'gallery_image_ids' => array(),
						)
					)
				);

			case 'grouped':
				$id      = $this->grouped_product( array( $this->passing_product( 'publish' ), $this->passing_product( 'publish' ) ) );
				$product = wc_get_product( $id );
				$product->set_props( $this->complete_props() );

				return $this->save_unguarded( $product );

			case 'external':
				return $this->external_product( 'draft', $this->complete_props( array( 'regular_price' => '25' ) ) );
		}

		$this->fail( 'Unknown fixture ' . $fixture );
	}

	/**
	 * Assert a result has no failing and no warning row, naming the rows that do.
	 *
	 * @since 1.1.0
	 *
	 * @param Validation_Result $result The result.
	 * @param string            $what   What was validated, for the failure message.
	 * @return void
	 */
	private function assertClean( Validation_Result $result, string $what ): void {
		$noisy = array();

		foreach ( $result->get_results() as $row ) {
			if ( in_array( $row->get_status(), array( Status::FAIL, Status::WARNING ), true ) ) {
				$noisy[] = $row->get_rule_id() . ' (' . $row->get_status() . '): ' . $row->get_message();
			}
		}

		$this->assertSame( array(), $noisy, $what . ' has failing or warning rows.' );
		$this->assertSame( 0, $result->get_counts()['failed'] );
		$this->assertSame( 0, $result->get_counts()['warnings'] );
	}

	/**
	 * A complete product of any family publishes through any layer, queues no notice, and
	 * neither the context the layer judged nor the stored product fails or warns.
	 *
	 * @since 1.1.0
	 *
	 * @dataProvider fixture_layer_provider
	 *
	 * @param string $fixture One of `FIXTURES`.
	 * @param string $layer   Guard layer.
	 * @return void
	 */
	public function test_complete_product_publishes_through_every_layer( string $fixture, string $layer ): void {
		$id = $this->build( $fixture );

		$this->assertClean( Plugin::instance()->checklist()->validate_post( $id ), 'The stored fixture' );

		$user_id = $this->publish_through( $layer, $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertArrayNotHasKey( $id, $this->pending_notices( $user_id ) );
		$this->assertNotEmpty( $this->judged_contexts, 'The layer judged nothing.' );

		foreach ( $this->judged_results() as $result ) {
			$this->assertClean( $result, 'The judged context' );
		}

		$this->assertClean( Plugin::instance()->checklist()->validate_post( $id ), 'The published product' );
	}

	/**
	 * The fixture flags are really stored, so the virtual and downloadable cases are not
	 * plain simple products in disguise.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_flag_fixtures_carry_their_flags(): void {
		$virtual      = wc_get_product( $this->build( 'simple virtual' ) );
		$downloadable = wc_get_product( $this->build( 'simple downloadable' ) );

		$this->assertInstanceOf( WC_Product_Simple::class, $virtual );
		$this->assertTrue( $virtual->is_virtual() );
		$this->assertTrue( $downloadable->is_downloadable() );
	}
}
