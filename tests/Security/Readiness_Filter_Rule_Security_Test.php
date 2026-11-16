<?php
/**
 * Security coverage for the products-list filter by failing check.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Admin\Readiness_Filter;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Support\Readiness_Store;
use ProductPublishGuard\Tests\Integration\Publish_Guard_Test_Case;
use WP_Query;

/**
 * `sit_wcpg_rule` reaches the meta query only as a registered rule id (SIT-WCPG-SEC, U14).
 *
 * @since 1.2.0
 */
final class Readiness_Filter_Rule_Security_Test extends Publish_Guard_Test_Case {

	/**
	 * The main query before the test replaced it.
	 *
	 * @since 1.2.0
	 * @var WP_Query|null
	 */
	private ?WP_Query $main_query = null;

	/**
	 * The products list screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->main_query = $GLOBALS['wp_the_query'] ?? null;

		set_current_screen( 'edit-product' );
	}

	/**
	 * Leave no filter, screen or main query behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET[ Readiness_Filter::QUERY_VAR ], $_GET[ Readiness_Filter::RULE_QUERY_VAR ], $GLOBALS['current_screen'] );

		$GLOBALS['wp_the_query'] = $this->main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores what set_up() saved.

		parent::tear_down();
	}

	/**
	 * The meta query the list's main query ends up with.
	 *
	 * @since 1.2.0
	 *
	 * @return mixed
	 */
	private function applied_meta_query() {
		$query = new WP_Query();

		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The query under test is the main one.

		$query->set( 'post_type', 'product' );

		( new Readiness_Filter( Plugin::instance()->settings() ) )->apply( $query );

		return $query->get( 'meta_query' );
	}

	/**
	 * Hostile values are dropped, not cleaned into something registered.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_hostile_rule_ids
	 *
	 * @param mixed $value The submitted value.
	 * @return void
	 */
	public function test_hostile_rule_id_is_ignored( $value ): void {
		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = $value;

		$filter = new Readiness_Filter( Plugin::instance()->settings() );

		$this->assertSame( '', $filter->current_rule() );
		$this->assertSame( '', $this->applied_meta_query() );

		ob_start();
		$filter->render( 'product', 'top' );
		$filter->render_rule_chip( 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'sit_wcpg_rule', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * Values that must never reach the meta query.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{mixed}>
	 */
	public function data_hostile_rule_ids(): array {
		return array(
			'like wildcard'     => array( '%' ),
			'wildcard in a key' => array( 'pri%ce' ),
			'underscore only'   => array( '_' ),
			'like single char'  => array( 'pric_' ),
			'double quote'      => array( '"price' ),
			'quoted id'         => array( '"price"' ),
			'single quote'      => array( "price' OR 1=1 -- " ),
			'sql fragment'      => array( 'price) UNION SELECT user_pass FROM wp_users -- ' ),
			'serialized'        => array( 's:5:"price";' ),
			'upper case'        => array( 'PRICE' ),
			'markup'            => array( '"><script>alert(1)</script>' ),
			'array'             => array( array( 'price' ) ),
			'empty'             => array( '' ),
		);
	}

	/**
	 * A registered id reaches the query, quoted, with the not-ready clause.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_registered_rule_id_reaches_the_query_quoted(): void {
		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'price';

		$meta_query = $this->applied_meta_query();

		$this->assertIsArray( $meta_query );
		$this->assertContains( Readiness_Store::meta_query( Readiness_Store::FILTER_FAIL ), $meta_query );
		$this->assertContains(
			array(
				'key'     => Readiness_Store::META_DATA,
				'value'   => '"price"',
				'compare' => 'LIKE',
			),
			$meta_query
		);
	}

	/**
	 * Someone who cannot edit products never reaches the query, or the registry.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_user_without_edit_products_never_reaches_the_query(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$_GET[ Readiness_Filter::RULE_QUERY_VAR ] = 'price';

		$this->assertSame( '', $this->applied_meta_query() );

		$filter = new Readiness_Filter( Plugin::instance()->settings() );

		ob_start();
		$filter->render( 'product', 'top' );
		$filter->render_rule_chip( 'top' );
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
