<?php
/**
 * Tests for the built-in rule catalogue.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Rules;

use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Tests\Stubs\Fake_Rule;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The catalogue is a contract: its identifiers are settings keys, so a rename is a
 * migration and a reorder is a visible change to every merchant's checklist.
 *
 * @since 1.0.0
 */
final class Rules_Provider_Test extends TestCase {

	/**
	 * A populated registry.
	 *
	 * @since 1.0.0
	 * @var Rule_Registry
	 */
	private Rule_Registry $registry;

	/**
	 * Populate a fresh registry for each case.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function tear_down() {
		unset( $GLOBALS['sit_wcpg_test_actions'] );
	}

	/**
	 * Exactly the eleven documented rules, in the documented order.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_it_registers_the_eleven_rules_in_display_order() {
		$ids = array_map(
			static function ( $rule ) {
				return $rule->get_id();
			},
			$this->registry->all()
		);

		$this->assertSame(
			array(
				'title',
				'description',
				'short_description',
				'featured_image',
				'image_count',
				'price',
				'sale_price',
				'category',
				'tags',
				'sku',
				'stock_status',
			),
			$ids
		);
	}

	/**
	 * Priorities are distinct and in ten-step increments, so a third-party rule can be
	 * slotted between any two of them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_priorities_leave_room_between_the_built_in_rules() {
		$priorities = array();

		foreach ( $this->registry->all() as $rule ) {
			$priorities[] = $rule->get_priority();
		}

		$this->assertSame( range( 10, 110, 10 ), $priorities );
	}

	/**
	 * Every rule declares one of the five documented groups, a non-empty label and a
	 * non-empty settings description.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_every_rule_is_fully_described() {
		$groups = array( 'content', 'media', 'pricing', 'organization', 'inventory' );

		foreach ( $this->registry->all() as $rule ) {
			$id = $rule->get_id();

			$this->assertContains( $rule->get_group(), $groups, $id . ' has an unknown group.' );
			$this->assertNotSame( '', $rule->get_label(), $id . ' has no label.' );
			$this->assertNotSame( '', $rule->get_description(), $id . ' has no settings description.' );
			$this->assertTrue( Severity::is_valid( $rule->get_default_severity() ), $id . ' has an invalid severity.' );
			$this->assertTrue( $rule->is_enabled_by_default(), $id . ' does not ship enabled.' );
			$this->assertNotSame( array(), $rule->get_fix_target(), $id . ' has no fix target.' );
		}
	}

	/**
	 * The shipped severities are the ones the plan settled on: five requirements, six
	 * advisories.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_shipped_severities() {
		$severities = array();

		foreach ( $this->registry->all() as $rule ) {
			$severities[ $rule->get_id() ] = $rule->get_default_severity();
		}

		$this->assertSame(
			array(
				'title'             => Severity::REQUIRED,
				'description'       => Severity::REQUIRED,
				'short_description' => Severity::WARNING,
				'featured_image'    => Severity::REQUIRED,
				'image_count'       => Severity::WARNING,
				'price'             => Severity::REQUIRED,
				'sale_price'        => Severity::WARNING,
				'category'          => Severity::REQUIRED,
				'tags'              => Severity::WARNING,
				'sku'               => Severity::WARNING,
				'stock_status'      => Severity::WARNING,
			),
			$severities
		);
	}

	/**
	 * The extension point is opened once the built-ins are in, and it is handed the
	 * registry so a third-party rule lands in the same list.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_it_opens_the_registry_to_other_plugins() {
		$fired = $GLOBALS['sit_wcpg_test_actions'];

		$this->assertCount( 1, $fired );
		$this->assertSame( 'sit_wcpg_register_rules', $fired[0]['hook'] );
		$this->assertSame( array( $this->registry ), $fired[0]['args'] );
	}

	/**
	 * A rule added by someone else takes its place by priority, exactly like a built-in.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_third_party_rule_slots_in_by_priority() {
		$this->registry->register( new Fake_Rule( 'alt_text', 'pass', 45 ) );

		$ids = array_map(
			static function ( $rule ) {
				return $rule->get_id();
			},
			$this->registry->all()
		);

		$this->assertSame( 'alt_text', $ids[4] );
		$this->assertCount( 12, $ids );
	}
}
