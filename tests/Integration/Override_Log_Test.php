<?php
/**
 * The override log.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Support\Override_Log;

/**
 * Who published past which required rule, and when: capped, self-pruning, and holding no
 * product text (D6).
 *
 * @since 1.2.0
 */
final class Override_Log_Test extends Publish_Guard_Test_Case {

	/**
	 * The log under test.
	 *
	 * @since 1.2.0
	 * @var Override_Log
	 */
	private Override_Log $log;

	/**
	 * Start from an empty log.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Override_Log::OPTION );

		$this->log = new Override_Log();
	}

	/**
	 * A stored entry, for seeding the option directly.
	 *
	 * @since 1.2.0
	 *
	 * @param int $age        Seconds before now.
	 * @param int $product_id Product id.
	 * @return array
	 */
	private static function seeded_entry( int $age, int $product_id = 7 ): array {
		return array(
			'time'       => time() - $age,
			'user_id'    => 3,
			'product_id' => $product_id,
			'rule_ids'   => array( 'price' ),
			'save_path'  => 'crud',
		);
	}

	/**
	 * An override the guard allows reaches the log, fully described.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_override_through_the_guard_is_recorded(): void {
		$this->set_publishing( array( 'allow_admin_override' => true ) );

		$id = $this->failing_product();

		$this->publish_through( 'crud', $id );

		$entries = $this->log->entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( $id, $entries[0]['product_id'] );
		$this->assertSame( $this->admin_id, $entries[0]['user_id'] );
		$this->assertSame( array( 'price' ), $entries[0]['rule_ids'] );
		$this->assertSame( 'crud', $entries[0]['save_path'] );
		$this->assertEqualsWithDelta( time(), $entries[0]['time'], 5 );
	}

	/**
	 * A refusal is not an override.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_blocked_publish_is_not_recorded(): void {
		$this->publish_through( 'crud', $this->failing_product() );

		$this->assertSame( array(), $this->log->entries() );
	}

	/**
	 * Reads return the latest override first.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_entries_are_newest_first(): void {
		$this->log->record( 1, 3, array( 'price' ), 'crud' );
		$this->log->record( 2, 3, array( 'price' ), 'crud' );
		$this->log->record( 3, 3, array( 'price' ), 'crud' );

		$this->assertSame( array( 3, 2, 1 ), array_column( $this->log->entries(), 'product_id' ) );
	}

	/**
	 * The oldest entries make room for new ones.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_log_is_capped_at_200(): void {
		for ( $product_id = 1; $product_id <= 205; $product_id++ ) {
			$this->log->record( $product_id, 3, array( 'price' ), 'crud' );
		}

		$products = array_column( $this->log->entries(), 'product_id' );

		$this->assertCount( Override_Log::MAX_ENTRIES, $products );
		$this->assertSame( 205, $products[0] );
		$this->assertSame( 6, end( $products ) );
	}

	/**
	 * Every write drops what has aged out.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_entries_older_than_90_days_are_pruned_on_write(): void {
		update_option( Override_Log::OPTION, array( self::seeded_entry( 91 * DAY_IN_SECONDS ) ), false );

		$this->log->record( 8, 3, array( 'price' ), 'crud' );

		$this->assertSame( array( 8 ), array_column( $this->log->entries(), 'product_id' ) );
	}

	/**
	 * The log never loads on a request that does not ask for it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_option_is_not_autoloaded(): void {
		$this->log->record( 1, 3, array( 'price' ), 'crud' );

		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayNotHasKey( Override_Log::OPTION, wp_load_alloptions() );
	}

	/**
	 * Deleting a user keeps the record of what happened and forgets who did it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_deleted_user_is_anonymized_not_dropped(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );

		$this->log->record( 1, $user_id, array( 'price' ), 'crud' );
		$this->log->record( 2, $this->admin_id, array( 'price' ), 'crud' );

		wp_delete_user( $user_id );

		$entries = $this->log->entries();

		$this->assertSame( array( 2, 1 ), array_column( $entries, 'product_id' ) );
		$this->assertSame( array( $this->admin_id, 0 ), array_column( $entries, 'user_id' ) );
	}

	/**
	 * Nothing a merchant or an attacker typed can reach the log.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_entries_hold_no_strings_but_rule_ids_and_path(): void {
		$this->log->record( 1, 3, array( '<script>x</script>', 'price' ), '<img src=x>' );

		$entries = $this->log->entries();

		$this->assertSame( array( 'scriptxscript', 'price' ), $entries[0]['rule_ids'] );
		$this->assertSame( 'none', $entries[0]['save_path'] );
		$this->assertStringNotContainsString( '<', (string) wp_json_encode( $entries ) );
	}

	/**
	 * A damaged option reads as empty and is replaced by the next write.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_malformed_option_is_ignored(): void {
		update_option( Override_Log::OPTION, 'not a list', false );
		$this->assertSame( array(), $this->log->entries() );

		update_option(
			Override_Log::OPTION,
			array(
				'junk',
				array( 'time' => time() ),
				array(
					'time'       => 0,
					'product_id' => 4,
				),
				array(
					'time'       => time(),
					'product_id' => -1,
				),
			),
			false
		);
		$this->assertSame( array(), $this->log->entries() );

		$this->log->record( 9, 3, array( 'price' ), 'crud' );

		$stored = get_option( Override_Log::OPTION );

		$this->assertCount( 1, $stored );
		$this->assertSame( 9, $stored[0]['product_id'] );
	}
}
