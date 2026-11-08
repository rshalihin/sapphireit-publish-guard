<?php
/**
 * Tests for the uninstall routine.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Activation_Notice;
use ProductPublishGuard\Admin\Review_Notice;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Override_Log;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;

/**
 * Uninstall leaves nothing behind (D19).
 *
 * `WP_UNINSTALL_PLUGIN` can only be defined once, so the WordPress case has a process of its
 * own. Action Scheduler can't be unloaded from one that has WooCommerce in it, so the other
 * case runs the file in a bare PHP process.
 *
 * @since 1.2.0
 */
final class Uninstall_Test extends Publish_Guard_Test_Case {

	/**
	 * Keep the separate process from reinstalling, and so emptying, the test database.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Read by the test library's bootstrap in the child process.
		putenv( 'WP_TESTS_SKIP_INSTALL=1' );

		parent::set_up_before_class();
	}

	/**
	 * Put the environment back for the rest of the suite.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function tear_down_after_class() {
		parent::tear_down_after_class();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Undoes set_up_before_class().
		putenv( 'WP_TESTS_SKIP_INSTALL' );
	}

	/**
	 * Everything 1.2.0 stores is gone afterwards.
	 *
	 * @since 1.2.0
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_uninstall_removes_everything_1_2_adds(): void {
		global $wpdb;

		$user_id = get_current_user_id();

		update_option( Settings::OPTION_NAME, Settings::class );
		update_option( Activation_Notice::OPTION, '1', false );
		update_option( Override_Log::OPTION, array( array( 'time' => time() ) ), false );
		update_option( Readiness_Rebuild::OPTION, array( 'state' => 'complete' ), false );
		update_option( Review_Notice::OPTION, array( 'first_seen' => time() ), false );
		set_transient( 'sit_wcpg_blocked_' . $user_id, array( 'x' ), MINUTE_IN_SECONDS );

		$ids = self::factory()->post->create_many(
			3,
			array(
				'post_type'   => 'product',
				'post_status' => 'draft',
			)
		);

		foreach ( $ids as $id ) {
			foreach ( Readiness_Store::meta_keys() as $key ) {
				update_post_meta( $id, $key, 'stored' );
			}
		}

		as_enqueue_async_action(
			Readiness_Rebuild::HOOK,
			array(
				'page'      => 1,
				'signature' => 'abc',
			),
			Readiness_Rebuild::GROUP
		);
		as_enqueue_async_action( Readiness_Rebuild::PRODUCTS_HOOK, array( 'ids' => $ids ), Readiness_Rebuild::GROUP );

		$pending = array(
			'group'  => Readiness_Rebuild::GROUP,
			'status' => 'pending',
		);

		$this->assertNotSame( array(), as_get_scheduled_actions( $pending, 'ids' ), 'The fixture queued nothing.' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant.
		define( 'WP_UNINSTALL_PLUGIN', 'sapphireit-publish-guard/sapphireit-publish-guard.php' );

		include SIT_WCPG_PATH . 'uninstall.php';

		wp_cache_flush();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading past every cache and API is the point.
		$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( 'sit_wcpg' ) . '%' ) );
		$meta    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_sit_wcpg_' ) . '%' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$this->assertSame( array(), $options );
		$this->assertSame( 0, $meta );
		$this->assertSame( array(), as_get_scheduled_actions( $pending, 'ids' ) );
	}

	/**
	 * With WooCommerce gone, `as_unschedule_all_actions()` is too.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_uninstall_without_action_scheduler_does_not_fatal(): void {
		$code = implode(
			' ',
			array(
				'function delete_option( $name ) {}',
				'function delete_post_meta_by_key( $key ) {}',
				'function delete_transient( $name ) {}',
				'function get_users( $args ) { return array( 1, 2 ); }',
				"define( 'WP_UNINSTALL_PLUGIN', 'test' );",
				"require '" . addslashes( SIT_WCPG_PATH . 'uninstall.php' ) . "';",
				"echo function_exists( 'as_unschedule_all_actions' ) ? 'scheduler present' : 'finished';",
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs the file with no WordPress or Action Scheduler loaded.
		$process = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=1', '-r', $code ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		$this->assertIsResource( $process );

		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipes, not files.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipes, not files.
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), (string) $output );
		$this->assertSame( 'finished', $output );
	}
}
