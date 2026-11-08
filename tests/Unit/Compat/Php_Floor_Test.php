<?php
/**
 * The declared PHP floor agrees everywhere it is written down.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Compat;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * WordPress reads the main file's header and the readme, the plugin enforces the constant at
 * runtime, and Composer reads `composer.json`. A mismatch means the plugin installs on a PHP
 * it then refuses to boot on, or the reverse.
 *
 * @since 1.2.0
 */
final class Php_Floor_Test extends TestCase {

	/**
	 * The minimum PHP version (coding-plan.md D1).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const FLOOR = '8.1';

	/**
	 * Read a plugin-relative file.
	 *
	 * @since 1.2.0
	 *
	 * @param string $file File relative to the plugin root.
	 * @return string The contents.
	 */
	private static function read( string $file ): string {
		return (string) file_get_contents( SIT_WCPG_PATH . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
	}

	/**
	 * The first capture group of a pattern over a plugin-relative file.
	 *
	 * @since 1.2.0
	 *
	 * @param string $pattern Regular expression with one capture group.
	 * @param string $file    File relative to the plugin root.
	 * @return string The trimmed match, or '' when the pattern does not match.
	 */
	private static function capture( string $pattern, string $file ): string {
		return preg_match( $pattern, self::read( $file ), $matches ) ? trim( $matches[1] ) : '';
	}

	/**
	 * The plugin header declares the floor.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_main_file_header_requires_php_81(): void {
		$this->assertSame( self::FLOOR, self::capture( '/^\s*\*\s*Requires PHP:\s*(.+)$/m', 'sapphireit-publish-guard.php' ) );
	}

	/**
	 * The constant the runtime check compares against is the floor.
	 *
	 * Read from the source, because the unit suite does not load the main file.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_min_php_constant_is_81(): void {
		$this->assertSame( self::FLOOR, self::capture( "/define\(\s*'SIT_WCPG_MIN_PHP'\s*,\s*'([^']+)'/", 'sapphireit-publish-guard.php' ) );
	}

	/**
	 * The readme declares the floor.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_readme_requires_php_81(): void {
		$this->assertSame( self::FLOOR, self::capture( '/^Requires PHP:\s*(.+)$/m', 'readme.txt' ) );
	}

	/**
	 * Composer's platform requirement is the floor.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_composer_requires_php_81(): void {
		$composer = json_decode( self::read( 'composer.json' ), true );

		$this->assertSame( '>=' . self::FLOOR, $composer['require']['php'] );
	}
}
