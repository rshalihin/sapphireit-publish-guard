<?php
/**
 * Tests for the inline SVG icon helper.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Admin;

use ProductPublishGuard\Admin\Icons;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Icons are static, allow-listed markup that screen readers skip (UI decision U5), and
 * the editor panel's JavaScript set stays the same as the PHP one.
 *
 * @since 1.2.0
 */
final class Icons_Test extends TestCase {

	/**
	 * The names UI decision U5 and phase U02 allow-list.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private const EXPECTED_NAMES = array(
		'shield',
		'check',
		'cross',
		'alert',
		'info',
		'refresh',
		'search',
		'chevron-down',
		'chevron-right',
		'book',
		'chat',
		'star',
		'crown',
		'bag',
		'barcode',
		'grid',
		'sparkle',
		'store',
		'fields',
		'key',
		'edit',
		'trash',
		'plus',
		'image',
		'images',
		'text',
		'title',
		'tag',
		'ban',
		'price',
	);

	/**
	 * A known icon is an svg that assistive technology and the tab order skip.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_known_icon_returns_an_svg_with_aria_hidden_and_focusable_false(): void {
		$svg = Icons::svg( 'shield' );

		$this->assertStringStartsWith( '<svg ', $svg );
		$this->assertStringEndsWith( '</svg>', $svg );
		$this->assertStringContainsString( 'aria-hidden="true"', $svg );
		$this->assertStringContainsString( 'focusable="false"', $svg );
		$this->assertStringContainsString( 'width="18" height="18"', $svg );
		$this->assertStringContainsString( 'stroke="currentColor"', $svg );
		$this->assertStringContainsString( 'class="sit-wcpg-ui-icon"', $svg );
	}

	/**
	 * A name outside the allow-list renders nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unknown_icon_returns_empty_string(): void {
		$this->assertSame( '', Icons::svg( 'not-an-icon' ) );
		$this->assertSame( '', Icons::svg( '<script>' ) );
		$this->assertSame( '', Icons::svg( '' ) );
	}

	/**
	 * The size is clamped to 12–48 px.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_size_is_clamped_to_12_48(): void {
		$this->assertStringContainsString( 'width="12" height="12"', Icons::svg( 'check', 4 ) );
		$this->assertStringContainsString( 'width="12" height="12"', Icons::svg( 'check', -20 ) );
		$this->assertStringContainsString( 'width="48" height="48"', Icons::svg( 'check', 200 ) );
		$this->assertStringContainsString( 'width="20" height="20"', Icons::svg( 'check', 20 ) );
	}

	/**
	 * Every allow-listed name renders, and nothing else is allow-listed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_every_allow_listed_name_renders(): void {
		$this->assertSame( self::EXPECTED_NAMES, Icons::names() );

		foreach ( self::EXPECTED_NAMES as $name ) {
			$svg = Icons::svg( $name, 16 );

			$this->assertStringContainsString( '<path d="', $svg, $name );
			$this->assertStringContainsString( 'viewBox="' . Icons::VIEW_BOX . '"', $svg, $name );
		}
	}

	/**
	 * The fixture the Jest suite compares `icons.js` against is current: run
	 * `php bin/icon-names.php` after changing the set.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_fixture_matches_the_php_set(): void {
		$path = dirname( __DIR__, 2 ) . '/fixtures/icon-names.json';

		$this->assertFileExists( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$fixture = json_decode( (string) file_get_contents( $path ), true );

		$this->assertSame( Icons::definitions(), $fixture, 'Run `php bin/icon-names.php` to refresh tests/fixtures/icon-names.json.' );
	}
}
