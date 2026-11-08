<?php
/**
 * The guard announces every refused or overridden publish.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Settings\Settings;
use RuntimeException;
use WC_Product_Simple;

/**
 * `sit_wcpg_publish_blocked` and `sit_wcpg_publish_overridden` fire exactly once per outcome,
 * with the final product id: the override log, the review notice and Pro all depend on it.
 *
 * @since 1.2.0
 */
final class Publish_Guard_Events_Test extends Publish_Guard_Test_Case {

	/**
	 * The hook names, kept in one place so a typo cannot pass as "nothing fired".
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private const HOOKS = array( 'sit_wcpg_publish_blocked', 'sit_wcpg_publish_overridden' );

	/**
	 * Every event fired during the test, in order.
	 *
	 * @since 1.2.0
	 * @var array<int, array{hook: string, args: array}>
	 */
	private array $events = array();

	/**
	 * The recorder callbacks, by hook name.
	 *
	 * @since 1.2.0
	 * @var array<string, callable>
	 */
	private array $recorders = array();

	/**
	 * Start recording.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->events = array();

		foreach ( self::HOOKS as $hook ) {
			$this->recorders[ $hook ] = function ( ...$args ) use ( $hook ) {
				$this->events[] = array(
					'hook' => $hook,
					'args' => $args,
				);
			};

			add_action( $hook, $this->recorders[ $hook ], 10, 4 );
		}
	}

	/**
	 * Stop recording.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->recorders as $hook => $recorder ) {
			remove_action( $hook, $recorder, 10 );
		}

		parent::tear_down();
	}

	/**
	 * The recorded events of one hook.
	 *
	 * @since 1.2.0
	 *
	 * @param string $hook Hook name.
	 * @return array[] Each event's arguments.
	 */
	private function fired( string $hook ): array {
		$args = array();

		foreach ( $this->events as $event ) {
			if ( $hook === $event['hook'] ) {
				$args[] = $event['args'];
			}
		}

		return $args;
	}

	/**
	 * A failing new product, unsaved, about to be published through CRUD.
	 *
	 * @since 1.2.0
	 *
	 * @return WC_Product_Simple
	 */
	private function new_failing_product(): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_props( $this->passing_props( 'publish', array( 'regular_price' => '' ) ) );

		return $product;
	}

	/**
	 * A refused publish fires the blocked action once, with the full payload.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider layer_provider
	 *
	 * @param string $layer One of the guard layers.
	 * @return void
	 */
	public function test_blocked_publish_fires_blocked_once( string $layer ): void {
		$id      = $this->failing_product();
		$user_id = $this->publish_through( $layer, $id );

		$this->assertCount( 0, $this->fired( 'sit_wcpg_publish_overridden' ) );
		$this->assertSame(
			array( array( $id, $user_id, array( 'price' ), self::LAYER_PATHS[ $layer ] ) ),
			$this->fired( 'sit_wcpg_publish_blocked' )
		);
	}

	/**
	 * An allowed override fires the overridden action once, and never the blocked one.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider layer_provider
	 *
	 * @param string $layer One of the guard layers.
	 * @return void
	 */
	public function test_override_fires_overridden_once( string $layer ): void {
		$this->set_publishing( array( 'allow_admin_override' => true ) );

		$id      = $this->failing_product();
		$user_id = $this->publish_through( $layer, $id );

		$this->assertCount( 0, $this->fired( 'sit_wcpg_publish_blocked' ) );
		$this->assertSame(
			array( array( $id, $user_id, array( 'price' ), self::LAYER_PATHS[ $layer ] ) ),
			$this->fired( 'sit_wcpg_publish_overridden' )
		);
	}

	/**
	 * A new product is announced under the id the save gave it, not 0.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_new_product_fires_with_its_final_id(): void {
		$product = $this->new_failing_product();
		$id      = $product->save();

		$this->assertGreaterThan( 0, $id );

		$fired = $this->fired( 'sit_wcpg_publish_blocked' );

		$this->assertCount( 1, $fired );
		$this->assertSame( $id, $fired[0][0] );
	}

	/**
	 * Several new products in one request are each announced under their own id.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_new_products_in_one_import_fire_once_each(): void {
		$ids = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->new_failing_product()->save();
		}

		$fired = array_column( $this->fired( 'sit_wcpg_publish_blocked' ), 0 );

		$this->assertCount( 3, array_unique( $ids ) );
		$this->assertSame( $ids, $fired );
	}

	/**
	 * A product that passes announces nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_passing_publish_fires_nothing(): void {
		$this->publish_through( 'crud', $this->passing_product() );

		$this->assertSame( array(), $this->events );
	}

	/**
	 * A save the scope does not cover announces nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_out_of_scope_save_fires_nothing(): void {
		$this->set_publishing( array( 'enforce_scope' => Settings::SCOPE_EDITOR ) );

		$id = $this->failing_product();

		$this->publish_through( 'rest', $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertSame( array(), $this->events );
	}

	/**
	 * A listener that throws cannot undo the refusal.
	 *
	 * Layer A announces before the downgraded row is returned, so an exception escaping
	 * from a listener would otherwise publish the product through the fail-open path.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider layer_provider
	 *
	 * @param string $layer One of the guard layers.
	 * @return void
	 */
	public function test_throwing_listener_cannot_undo_a_refusal( string $layer ): void {
		$listener = static function () {
			throw new RuntimeException( 'A listener failed.' );
		};

		add_action( 'sit_wcpg_publish_blocked', $listener, 5 );

		$id = $this->failing_product();

		$this->publish_through( $layer, $id );

		remove_action( 'sit_wcpg_publish_blocked', $listener, 5 );

		$this->assertSame( 'draft', $this->status_of( $id ) );
	}
}
