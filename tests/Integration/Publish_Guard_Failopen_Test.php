<?php
/**
 * The guard fails open.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Publishing\Publish_Guard;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use RuntimeException;

/**
 * A bug in this plugin must never stop a merchant from publishing (section 10.1): an
 * exception anywhere in the guard lets the save through, with a logged warning.
 *
 * The plugin's own guard is swapped for one running known rules.
 *
 * @since 1.0.0
 */
final class Publish_Guard_Failopen_Test extends Publish_Guard_Test_Case {

	/**
	 * The guard under test.
	 *
	 * @since 1.0.0
	 * @var Publish_Guard|null
	 */
	private ?Publish_Guard $guard = null;

	/**
	 * Where PHP was logging before the test redirected it.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $previous_log = '';

	/**
	 * File the test logs into.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $log = '';

	/**
	 * Redirect the error log so the warning can be read back.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->log          = get_temp_dir() . 'sit-wcpg-failopen-' . wp_generate_password( 8, false ) . '.log';
		$this->previous_log = (string) ini_get( 'error_log' );

		ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	}

	/**
	 * Restore the plugin's guard and the error log.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( null !== $this->guard ) {
			$this->guard->unregister();
			Plugin::instance()->publish_guard()->register();
		}

		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		wp_delete_file( $this->log );

		parent::tear_down();
	}

	/**
	 * Replace the plugin's guard with one running exactly these rules.
	 *
	 * @since 1.0.0
	 *
	 * @param Fake_Rule ...$rules Rules to run.
	 * @return void
	 */
	private function guard_with( Fake_Rule ...$rules ): void {
		$registry = new Rule_Registry();

		foreach ( $rules as $rule ) {
			$registry->register( $rule );
		}

		$settings = new Settings( $registry );

		Plugin::instance()->publish_guard()->unregister();

		$this->guard = new Publish_Guard(
			Plugin::instance()->settings(),
			Plugin::instance()->notices(),
			new Checklist_Service( new Validator( $registry, $settings ), $settings )
		);
		$this->guard->register();
	}

	/**
	 * Assert the log mentions a string, when WordPress is logging at all.
	 *
	 * @since 1.0.0
	 *
	 * @param string $needle Expected text.
	 * @return void
	 */
	private function assertLogged( string $needle ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$this->assertStringContainsString( $needle, (string) file_get_contents( $this->log ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * A required rule that throws does not block, and the throw is logged.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_throwing_rule_lets_the_publish_through(): void {
		$this->guard_with( new Fake_Rule( 'boom', 'fail', 10, true, true, Severity::REQUIRED ) );

		$id = $this->failing_product();
		$this->publish( $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertLogged( 'rule "boom" threw' );
	}

	/**
	 * An exception inside Layer A itself lets the publish through, and is logged.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_exception_in_layer_a_fails_open(): void {
		$this->guard_with( new Fake_Rule( 'always_fails', 'fail', 10, true, false, Severity::REQUIRED ) );

		// Control: the rule on its own does block.
		$blocked = $this->passing_product();
		$this->publish( $blocked );
		$this->assertSame( 'draft', $this->status_of( $blocked ) );

		$thrower = static function () {
			throw new RuntimeException( 'Simulated guard bug' );
		};

		add_filter( 'sit_wcpg_validation_result', $thrower );

		$id = $this->passing_product();
		$this->publish( $id );

		remove_filter( 'sit_wcpg_validation_result', $thrower );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertLogged( 'wp_insert_post_data failed open' );
	}

	/**
	 * An exception inside Layer B lets the CRUD save through, and is logged.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_an_exception_in_layer_b_fails_open(): void {
		$this->guard_with( new Fake_Rule( 'always_fails', 'fail', 10, true, false, Severity::REQUIRED ) );

		$thrower = static function () {
			throw new RuntimeException( 'Simulated guard bug' );
		};

		add_filter( 'sit_wcpg_validation_result', $thrower );

		$id      = $this->passing_product();
		$product = wc_get_product( $id );
		$product->set_status( 'publish' );
		$product->save();

		remove_filter( 'sit_wcpg_validation_result', $thrower );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertLogged( 'woocommerce_before_product_object_save failed open' );
	}
}
