<?php
/**
 * Scheduling (Layer A) and the scheduled-publish backstop (Layer C).
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

/**
 * Covers section 9.8's two scheduling rows: a failing product cannot be scheduled, and
 * one broken after it was scheduled is returned to draft when the cron publishes it.
 *
 * @since 1.0.0
 */
final class Publish_Guard_Scheduled_Test extends Publish_Guard_Test_Case {

	/**
	 * Schedule a product for tomorrow.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Product id.
	 * @return void
	 */
	private function schedule( int $post_id ): void {
		$date = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		$this->publish(
			$post_id,
			array(
				'post_date'     => get_date_from_gmt( $date ),
				'post_date_gmt' => $date,
				'edit_date'     => true,
			)
		);
	}

	/**
	 * A failing product cannot be scheduled.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_failing_product_cannot_be_scheduled(): void {
		$id = $this->failing_product();

		$this->schedule( $id );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertNoticeQueued( $id );
	}

	/**
	 * A passing product schedules, and publishes when its time comes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_passing_product_schedules_and_publishes(): void {
		$id = $this->passing_product();

		$this->schedule( $id );
		$this->assertSame( 'future', $this->status_of( $id ) );

		wp_publish_post( $id );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * A product broken after it was scheduled is returned to draft by the backstop, and
	 * its author is told.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_backstop_reverts_a_product_broken_after_scheduling(): void {
		$id = $this->passing_product();

		$this->schedule( $id );
		$this->assertSame( 'future', $this->status_of( $id ) );

		// Broken behind every layer's back: a raw meta write, as a sync job might do.
		update_post_meta( $id, '_regular_price', '' );
		update_post_meta( $id, '_price', '' );
		wc_delete_product_transients( $id );
		clean_post_cache( $id );

		// The cron runs with no user.
		wp_set_current_user( 0 );
		wp_publish_post( $id );

		$this->assertSame( 'draft', $this->status_of( $id ) );

		$author = (int) get_post_field( 'post_author', $id );

		$this->assertNoticeQueued( $id, 'blocked', $author );
	}
}
