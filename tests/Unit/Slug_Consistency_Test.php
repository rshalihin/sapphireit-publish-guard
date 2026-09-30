<?php
/**
 * Slug consistency guard for the WordPress.org rename.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The plugin was renamed from "Product Publish Guard" (`product-publish-guard`) to
 * "SapphireIT Publish Guard for WooCommerce" (`sapphireit-publish-guard`) after the
 * WordPress.org review. The slug, main file, text domain, readme title and POT file must
 * agree, and the old slug or name must not come back into anything that ships.
 *
 * @since 1.0.0
 */
final class Slug_Consistency_Test extends TestCase {

	/**
	 * The slug, which is also the folder, main file and text domain.
	 *
	 * @var string
	 */
	private const SLUG = 'sapphireit-publish-guard';

	/**
	 * The display name in the plugin header and readme title.
	 *
	 * @var string
	 */
	private const NAME = 'SapphireIT Publish Guard for WooCommerce';

	/**
	 * The retired slug.
	 *
	 * @var string
	 */
	private const OLD_SLUG = 'product-publish-guard';

	/**
	 * The retired display name.
	 *
	 * @var string
	 */
	private const OLD_NAME = 'Product Publish Guard';

	/**
	 * Read a plugin-relative file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file File relative to the plugin root.
	 * @return string The contents.
	 */
	private static function read( string $file ): string {
		return (string) file_get_contents( SIT_WCPG_PATH . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
	}

	/**
	 * Every shipped text file: `src/`, `assets/`, `build/`, `languages/`, the main file, the uninstaller
	 * and the readme.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Relative path => contents.
	 */
	private static function shipped_sources(): array {
		$root       = SIT_WCPG_PATH;
		$sources    = array();
		$extensions = array( 'php', 'js', 'css', 'scss', 'json', 'txt', 'pot' );

		foreach ( array( 'src', 'assets', 'build', 'languages' ) as $dir ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir, FilesystemIterator::SKIP_DOTS ) );

			foreach ( $files as $file ) {
				if ( in_array( $file->getExtension(), $extensions, true ) ) {
					$relative             = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
					$sources[ $relative ] = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
				}
			}
		}

		foreach ( array( self::SLUG . '.php', 'uninstall.php', 'readme.txt' ) as $file ) {
			$sources[ $file ] = self::read( $file );
		}

		return $sources;
	}

	/**
	 * The main file carries the slug, and the old one is gone.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_main_file_is_named_after_the_slug(): void {
		$this->assertFileExists( SIT_WCPG_PATH . self::SLUG . '.php' );
		$this->assertFileDoesNotExist( SIT_WCPG_PATH . self::OLD_SLUG . '.php' );
	}

	/**
	 * The plugin header names the plugin and its text domain.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_header_text_domain_matches_the_slug(): void {
		$header = self::read( self::SLUG . '.php' );

		$this->assertMatchesRegularExpression( '/^\s*\*\s*Text Domain:\s*(\S+)\s*$/m', $header );
		preg_match( '/^\s*\*\s*Text Domain:\s*(\S+)\s*$/m', $header, $domain );
		$this->assertSame( self::SLUG, $domain[1] );

		$this->assertMatchesRegularExpression( '/^\s*\*\s*Plugin Name:\s*(.+?)\s*$/m', $header );
		preg_match( '/^\s*\*\s*Plugin Name:\s*(.+?)\s*$/m', $header, $name );
		$this->assertSame( self::NAME, $name[1] );
	}

	/**
	 * The readme title is the plugin name.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_readme_title_matches_the_plugin_name(): void {
		$lines = preg_split( '/\R/', self::read( 'readme.txt' ) );

		$this->assertSame( '=== ' . self::NAME . ' ===', trim( $lines[0] ) );
	}

	/**
	 * The translation template carries the slug.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_pot_file_is_named_after_the_slug(): void {
		$this->assertFileExists( SIT_WCPG_PATH . 'languages/' . self::SLUG . '.pot' );
		$this->assertFileDoesNotExist( SIT_WCPG_PATH . 'languages/' . self::OLD_SLUG . '.pot' );
	}

	/**
	 * PHPCS enforces the slug as the only text domain.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_phpcs_text_domain_matches_the_slug(): void {
		$ruleset = simplexml_load_string( self::read( 'phpcs.xml.dist' ) );

		$this->assertNotFalse( $ruleset, 'phpcs.xml.dist is not valid XML.' );

		$domains = array_map( 'strval', $ruleset->xpath( '//property[@name="text_domain"]/element/@value' ) );

		$this->assertSame( array( self::SLUG ), $domains );
	}

	/**
	 * Nothing that ships uses the old slug or name.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_shipped_file_uses_the_old_slug_or_name(): void {
		$sources = self::shipped_sources();

		$this->assertNotEmpty( $sources, 'No source files were found, so nothing was checked.' );

		foreach ( $sources as $path => $source ) {
			$this->assertStringNotContainsString( self::OLD_SLUG, $source, "{$path}: uses the old slug" );
			$this->assertStringNotContainsString( self::OLD_NAME, $source, "{$path}: uses the old name" );
		}
	}
}
