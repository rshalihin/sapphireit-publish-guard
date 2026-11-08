<?php
/**
 * Every hook the plugin fires is classified, and the public ones are documented.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit;

use FilesystemIterator;
use ProductPublishGuard\Support\Readiness_Rebuild;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A hook that ships without a README entry, or a classification in the contract fixture, has
 * quietly become part of the API.
 *
 * @since 1.2.0
 */
final class Hook_Documentation_Test extends TestCase {

	/**
	 * The classified hooks.
	 *
	 * @since 1.2.0
	 *
	 * @return array{public: string[], internal: string[]}
	 */
	private static function hooks(): array {
		$json = file_get_contents( SIT_WCPG_PATH . 'tests/fixtures/rule-api-v2.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local fixture.

		return json_decode( (string) $json, true )['hooks'];
	}

	/**
	 * Every PHP source file, concatenated.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private static function sources(): string {
		$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SIT_WCPG_PATH . 'src', FilesystemIterator::SKIP_DOTS ) );
		$source = '';

		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$source .= (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
			}
		}

		return $source;
	}

	/**
	 * The plugin's hooks that `src/` fires.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	private static function fired_hooks(): array {
		preg_match_all( "/(?:do_action|apply_filters)\(\s*'(sit_wcpg_[a-z_]+)'/", self::sources(), $matches );

		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Nothing fired is unclassified, and nothing classified has stopped firing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_every_fired_hook_is_classified(): void {
		$hooks = self::hooks();
		$fired = self::fired_hooks();

		$this->assertNotEmpty( $fired, 'No hooks were found, so nothing was checked.' );

		foreach ( $fired as $hook ) {
			$this->assertContains( $hook, array_merge( $hooks['public'], $hooks['internal'] ), $hook . ' is not classified in rule-api-v2.json.' );
		}

		foreach ( $hooks['public'] as $hook ) {
			$this->assertContains( $hook, $fired, $hook . ' is classified as public but is never fired.' );
		}

		// Action Scheduler fires these from its own queue runner, so only the name is in src/.
		foreach ( $hooks['internal'] as $hook ) {
			$this->assertStringContainsString( "'{$hook}'", self::sources(), $hook . ' is classified as internal but appears nowhere in src/.' );
		}
	}

	/**
	 * Every hook a job is registered on is classified as internal, and the reverse.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_action_scheduler_hooks_are_classified_as_internal(): void {
		preg_match_all( '/add_action\(\s*self::(HOOK|PRODUCTS_HOOK)\b/', self::sources(), $matches );

		$registered = array_map(
			static function ( string $name ): string {
				return constant( Readiness_Rebuild::class . '::' . $name );
			},
			$matches[1]
		);

		$internal = self::hooks()['internal'];

		sort( $registered );
		sort( $internal );

		$this->assertSame( $internal, $registered );
	}

	/**
	 * Each public hook has its own README entry.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_every_public_hook_has_a_readme_heading(): void {
		$readme = (string) file_get_contents( SIT_WCPG_PATH . 'README.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file.

		foreach ( self::hooks()['public'] as $hook ) {
			$this->assertStringContainsString( '### `' . $hook . '`', $readme, $hook . ' has no README heading.' );
		}
	}

	/**
	 * The README states the stability contract, and the version argument it depends on.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_readme_documents_the_stability_contract(): void {
		$readme = (string) file_get_contents( SIT_WCPG_PATH . 'README.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file.

		$this->assertStringContainsString( '### Rule API v2 — stability contract', $readme );
		$this->assertStringContainsString( "do_action( 'sit_wcpg_register_rules', Rule_Registry \$registry, int \$api_version );", $readme );
		$this->assertStringContainsString( 'Save_Path::REST', $readme );
	}
}
