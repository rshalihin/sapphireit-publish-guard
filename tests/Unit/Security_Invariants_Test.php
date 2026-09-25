<?php
/**
 * Static security invariants over the shipped source.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The Phase 10 "confirm there is no …" list, made mechanical (coding-plan.md sections 9
 * and 13): no `$wpdb`, no admin-ajax or admin-post handler, no outbound HTTP, no role or
 * capability edits, no shortcode execution, no EscapeOutput exclusion, and no raw-HTML
 * sink in the editor scripts. A regression fails here without a WordPress bootstrap.
 *
 * @since 1.0.0
 */
final class Security_Invariants_Test extends TestCase {

	/**
	 * Every file with one of the given extensions under a plugin-relative directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $dir        Directory relative to the plugin root.
	 * @param string[] $extensions File extensions to include.
	 * @return array<string, string> Relative path => contents.
	 */
	private static function sources( string $dir, array $extensions ): array {
		$root    = SIT_WCPG_PATH;
		$sources = array();
		$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( in_array( $file->getExtension(), $extensions, true ) ) {
				$relative             = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) ) );
				$sources[ $relative ] = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
			}
		}

		return $sources;
	}

	/**
	 * The shipped PHP: `src/`, the main file and the uninstaller.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Relative path => contents.
	 */
	private static function php_sources(): array {
		$sources = self::sources( 'src', array( 'php' ) );

		foreach ( array( 'product-publish-guard.php', 'uninstall.php' ) as $file ) {
			$sources[ $file ] = (string) file_get_contents( SIT_WCPG_PATH . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
		}

		return $sources;
	}

	/**
	 * Assert no source matches any of the patterns.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $sources  Relative path => contents.
	 * @param array<string, string> $patterns Reason => regular expression.
	 * @return void
	 */
	private function assertNoMatch( array $sources, array $patterns ): void {
		$this->assertNotEmpty( $sources, 'No source files were found, so nothing was checked.' );

		foreach ( $sources as $path => $source ) {
			foreach ( $patterns as $reason => $pattern ) {
				$this->assertDoesNotMatchRegularExpression( $pattern, $source, "{$path}: {$reason}" );
			}
		}
	}

	/**
	 * Section 9.6: no direct database access.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_there_is_no_direct_database_access(): void {
		$this->assertNoMatch(
			self::php_sources(),
			array(
				'uses $wpdb' => '/global\s+\$wpdb|\$wpdb\s*->|\$GLOBALS\s*\[\s*[\'"]wpdb[\'"]\s*\]/',
			)
		);
	}

	/**
	 * Sections 9.2 and 13.19: REST only, no admin-ajax or admin-post handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_there_is_no_ajax_or_admin_post_handler(): void {
		$this->assertNoMatch(
			self::php_sources(),
			array(
				'registers an admin-ajax handler' => '/[\'"]wp_ajax_(nopriv_)?/',
				'registers an admin-post handler' => '/[\'"]admin_post_(nopriv_)?/',
				'references admin-ajax.php'       => '/admin-ajax\.php/',
			)
		);
	}

	/**
	 * Section 13.8: no outbound network calls at all.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_there_is_no_outbound_http(): void {
		$this->assertNoMatch(
			self::php_sources(),
			array(
				'makes an HTTP request' => '/\b(wp_remote_\w+|wp_safe_remote_\w+|download_url|curl_init|fsockopen|stream_socket_client)\s*\(/',
				'reads a remote URL'    => '/file_get_contents\s*\(\s*[\'"]https?:/',
			)
		);

		$this->assertNoMatch(
			self::sources( 'assets/js', array( 'js' ) ),
			array(
				'uses fetch() instead of apiFetch' => '/(?<![\w.])fetch\s*\(/',
				'uses XMLHttpRequest'              => '/XMLHttpRequest/',
				'uses jQuery AJAX'                 => '/\$\.(ajax|get|post|getJSON)\s*\(/',
			)
		);
	}

	/**
	 * Section 9.7: no role or capability is added, removed or edited.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_role_or_capability_is_changed(): void {
		$this->assertNoMatch(
			self::php_sources(),
			array(
				'changes a role or capability' => '/\b(add_role|remove_role|add_cap|remove_cap)\s*\(/',
			)
		);
	}

	/**
	 * Section 9.9: shortcodes are stripped, never executed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_no_shortcode_is_executed(): void {
		$this->assertNoMatch(
			self::php_sources(),
			array(
				'executes shortcodes' => '/\bdo_shortcode\s*\(/',
				'applies the_content' => '/apply_filters\s*\(\s*[\'"]the_content[\'"]/',
			)
		);
	}

	/**
	 * Section 9.4: no inline exclusion of the output-escaping sniff, anywhere.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_output_escaping_is_never_excluded(): void {
		$this->assertNoMatch(
			self::php_sources(),
			array(
				'excludes EscapeOutput'     => '/phpcs:(ignore|disable)[^\n]*EscapeOutput/',
				'disables PHPCS wholesale'  => '/phpcs:disable\s*$/m',
				'ignores every sniff'       => '/phpcs:ignore\s*$/m',
				'uses the legacy exclusion' => '/@codingStandardsIgnore/',
			)
		);
	}

	/**
	 * Section 9.4: the editor scripts never write raw HTML.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_the_editor_scripts_have_no_raw_html_sink(): void {
		$this->assertNoMatch(
			self::sources( 'assets/js', array( 'js' ) ),
			array(
				'uses dangerouslySetInnerHTML' => '/dangerouslySetInnerHTML/',
				'writes innerHTML/outerHTML'   => '/\.(innerHTML|outerHTML)\s*=/',
				'uses insertAdjacentHTML'      => '/insertAdjacentHTML/',
				'uses document.write'          => '/document\.write/',
				'sets HTML through jQuery'     => '/\.html\s*\(\s*[^)\s]/',
				'evaluates strings as code'    => '/\beval\s*\(|new\s+Function\s*\(/',
			)
		);
	}
}
