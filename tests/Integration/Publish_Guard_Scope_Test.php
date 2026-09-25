<?php
/**
 * Enforcement scope.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Save_Request_Reader;
use ProductPublishGuard\Settings\Settings;

/**
 * Who is guarded (section 6.3.3): signed-in users by default, admin save payloads only
 * under the `editor` scope, never cron or a request with no user — and the integrator
 * filter has the last word.
 *
 * @since 1.0.0
 */
final class Publish_Guard_Scope_Test extends Publish_Guard_Test_Case {

	/**
	 * A request with no user is never judged, so imports are not silently demoted.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_request_without_a_user_is_not_judged(): void {
		$id = $this->failing_product();

		wp_set_current_user( 0 );
		$this->publish( $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * Cron is not judged by Layer A (Layer C covers the scheduled case).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_cron_is_not_judged(): void {
		$id = $this->failing_product();

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->publish( $id );
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * The `editor` scope judges the admin forms and nothing else.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_editor_scope_judges_only_admin_payloads(): void {
		$this->set_publishing( array( 'enforce_scope' => Settings::SCOPE_EDITOR ) );

		$programmatic = $this->failing_product();
		$this->publish( $programmatic );

		$form = $this->failing_product();
		$this->publish(
			$form,
			array(
				'action'  => 'editpost',
				'post_ID' => $form,
			)
		);

		$this->assertSame( 'publish', $this->status_of( $programmatic ) );
		$this->assertSame( 'draft', $this->status_of( $form ) );
	}

	/**
	 * `sit_wcpg_should_enforce` receives the context and source, and decides.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_should_enforce_filter_decides(): void {
		$seen   = array();
		$filter = static function ( $enforce, $context, $source ) use ( &$seen ) {
			$seen[] = array( $context->get_product_id(), $source );

			return false;
		};

		$id = $this->failing_product();

		add_filter( 'sit_wcpg_should_enforce', $filter, 10, 3 );
		$this->publish( $id );
		remove_filter( 'sit_wcpg_should_enforce', $filter, 10 );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertContains( array( $id, Save_Request_Reader::SOURCE_PROGRAMMATIC ), $seen );
	}
}
