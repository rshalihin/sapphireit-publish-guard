<?php
/**
 * Tests for extras on the save paths.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Save_Path;
use ProductPublishGuard\Tests\Stubs\Extra_Probe_Rule;

/**
 * An admin save seeds extras from the request, through `sit_wcpg_save_request_extras`. Every
 * other path reads them from storage, through `sit_wcpg_context_extra`.
 *
 * @since 1.3.0
 */
final class Publish_Guard_Extras_Test extends Publish_Guard_Test_Case {

	/**
	 * The arguments of each `sit_wcpg_save_request_extras` call.
	 *
	 * @since 1.3.0
	 * @var array[]
	 */
	private array $calls = array();

	/**
	 * Switch the probe rule on.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->calls = array();

		Extra_Probe_Rule::activate();
	}

	/**
	 * Switch the probe rule off, so no other test meets it.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function tear_down() {
		Extra_Probe_Rule::deactivate();

		parent::tear_down();
	}

	/**
	 * Answer the save-request filter with a fixed value, recording each call.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $result What the filter returns.
	 * @return void
	 */
	private function seed_with( $result ): void {
		add_filter(
			'sit_wcpg_save_request_extras',
			function ( $extras, $postarr, $source, $post_id ) use ( $result ) {
				$this->calls[] = array( $extras, $postarr, $source, $post_id );

				return $result;
			},
			10,
			4
		);
	}

	/**
	 * Resolve the probe extra from the product's meta, as an add-on reads storage.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	private function resolve_from_meta(): void {
		add_filter(
			'sit_wcpg_context_extra',
			static function ( $value, $key, $context ) {
				if ( Extra_Probe_Rule::EXTRA !== $key || ! $context instanceof Product_Context || null === $context->get_product() ) {
					return $value;
				}

				return (string) $context->get_product()->get_meta( '_probe_code' );
			},
			10,
			3
		);
	}

	/**
	 * Without a value the probe rule refuses the publish; with a seeded one it goes through.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_classic_publish_seeds_the_filter_result(): void {
		$refused = $this->passing_product();

		$this->publish_through( 'classic', $refused );

		$this->assertSame( 'draft', $this->status_of( $refused ) );

		$this->seed_with( array( Extra_Probe_Rule::EXTRA => 'x' ) );

		$published = $this->passing_product();

		$this->publish_through( 'classic', $published );

		$this->assertSame( 'publish', $this->status_of( $published ) );
	}

	/**
	 * The filter gets the request as WordPress received it, the route and the product id.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_filter_receives_postarr_source_and_id(): void {
		$this->seed_with( array( Extra_Probe_Rule::EXTRA => 'x' ) );

		$id   = $this->passing_product();
		$form = $this->classic_form( $id );

		$form['_probe_code'] = "it's";

		$this->publish( $id, $form );

		$this->assertCount( 1, $this->calls );

		list( $extras, $postarr, $source, $post_id ) = $this->calls[0];

		$this->assertSame( array(), $extras );
		$this->assertSame( Save_Path::CLASSIC, $source );
		$this->assertSame( $id, $post_id );
		$this->assertSame( 'editpost', $postarr['action'] );
		$this->assertSame( "it\\'s", $postarr['_probe_code'], 'The request arrives slashed.' );
	}

	/**
	 * A save that is not an admin request has no request fields to seed from.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_filter_not_called_for_programmatic_saves(): void {
		$this->seed_with( array( Extra_Probe_Rule::EXTRA => 'x' ) );

		$id = $this->passing_product();

		$this->publish( $id );

		$this->assertSame( array(), $this->calls );
		$this->assertSame( 'draft', $this->status_of( $id ), 'Nothing was seeded, so the probe rule still fails.' );
	}

	/**
	 * A filter that returns something other than an array seeds nothing.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_non_array_filter_result_is_ignored(): void {
		$this->seed_with( 'x' );
		$this->resolve_from_meta();

		$without = $this->passing_product();

		$this->publish_through( 'classic', $without );

		$this->assertSame( 'draft', $this->status_of( $without ) );

		$with    = $this->passing_product();
		$product = wc_get_product( $with );

		$product->update_meta_data( '_probe_code', 'stored' );
		$this->save_unguarded( $product );

		$this->publish_through( 'classic', $with );

		$this->assertSame( 'publish', $this->status_of( $with ), 'The save is judged on the stored value.' );
		$this->assertCount( 2, $this->calls );
	}

	/**
	 * A CRUD save seeds nothing: the resolver reads the object being saved.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_crud_save_reads_the_resolver(): void {
		$this->seed_with( array( Extra_Probe_Rule::EXTRA => 'x' ) );
		$this->resolve_from_meta();

		$refused = wc_get_product( $this->passing_product() );

		$refused->set_status( 'publish' );
		$refused->save();

		$this->assertSame( 'draft', $this->status_of( $refused->get_id() ) );

		$published = wc_get_product( $this->passing_product() );

		$published->update_meta_data( '_probe_code', 'set in this save' );
		$published->set_status( 'publish' );
		$published->save();

		$this->assertSame( 'publish', $this->status_of( $published->get_id() ) );
		$this->assertSame( array(), $this->calls, 'A CRUD save is not an admin request.' );
	}
}
