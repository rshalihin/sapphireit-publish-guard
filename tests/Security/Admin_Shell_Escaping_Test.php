<?php
/**
 * Security coverage for what add-ons pass to the admin shell.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Admin\Admin_Shell;
use WP_UnitTestCase;

/**
 * Tab labels, badges, header items and URLs come from other plugins' filters (S12, S13), so
 * every one is escaped on output or dropped (UI decisions U9, U10).
 *
 * @since 1.2.0
 */
final class Admin_Shell_Escaping_Test extends WP_UnitTestCase {

	/**
	 * Filters added by the test.
	 *
	 * @since 1.2.0
	 * @var array<int, array{string, callable}>
	 */
	private array $filters = array();

	/**
	 * A shop manager.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
	}

	/**
	 * Remove the test's filters.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}

		parent::tear_down();
	}

	/**
	 * Return these entries from a seam.
	 *
	 * @since 1.2.0
	 *
	 * @param string $hook    Filter name.
	 * @param array  $entries Entries to append.
	 * @return void
	 */
	private function append( string $hook, array $entries ): void {
		$callback = static function ( $core ) use ( $entries ) {
			return array_merge( $core, $entries );
		};

		add_filter( $hook, $callback );

		$this->filters[] = array( $hook, $callback );
	}

	/**
	 * The header as printed.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		Admin_Shell::render( 'hostile' );

		return (string) ob_get_clean();
	}

	/**
	 * A tab label is text, never markup.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_tab_label_script_is_escaped(): void {
		$this->append(
			Admin_Shell::TABS_FILTER,
			array(
				array(
					'id'         => 'hostile',
					'label'      => '<script>alert(1)</script>',
					'url'        => admin_url( 'admin.php?page=hostile' ),
					'capability' => 'edit_products',
				),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	/**
	 * A badge cannot break out of its element or attribute.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_badge_with_quotes_is_escaped(): void {
		$this->append(
			Admin_Shell::TABS_FILTER,
			array(
				array(
					'id'         => 'hostile',
					'label'      => 'Tab',
					'badge'      => '"><b>x',
					'url'        => admin_url( 'admin.php?page=hostile' ),
					'capability' => 'edit_products',
				),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '"><b>x', $html );
		$this->assertStringContainsString( '&quot;&gt;&lt;b&gt;x', $html );
	}

	/**
	 * A header item label is text, never markup.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_item_label_with_html_is_escaped(): void {
		$this->append(
			Admin_Shell::ITEMS_FILTER,
			array(
				array(
					'id'    => 'hostile',
					'label' => '<img src=x onerror=alert(1)>',
				),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
	}

	/**
	 * A `javascript:` URL drops the tab or item that carries it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_javascript_url_is_dropped(): void {
		$this->append(
			Admin_Shell::TABS_FILTER,
			array(
				array(
					'id'         => 'hostile',
					'label'      => 'Script tab',
					'url'        => 'javascript:alert(1)',
					'capability' => 'edit_products',
				),
				array(
					'id'         => 'disguised',
					'label'      => 'Disguised tab',
					'url'        => 'javascript:alert(1)//' . admin_url(),
					'capability' => 'edit_products',
				),
			)
		);
		$this->append(
			Admin_Shell::ITEMS_FILTER,
			array(
				array(
					'id'    => 'hostile',
					'label' => 'Script item',
					'url'   => 'javascript:alert(1)',
				),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'Script tab', $html );
		$this->assertStringNotContainsString( 'Disguised tab', $html );
		$this->assertStringNotContainsString( 'Script item', $html );
	}
}
