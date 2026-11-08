<?php
/**
 * Tests for the canonical save paths.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit\Engine;

use ProductPublishGuard\Engine\Save_Path;
use ProductPublishGuard\Engine\Save_Request_Reader;
use ProductPublishGuard\Publishing\Publish_Guard;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * These strings are already public: `sit_wcpg_should_enforce` has received them since 1.0.0,
 * so changing one breaks a third-party filter.
 *
 * @since 1.2.0
 */
final class Save_Path_Test extends TestCase {

	/**
	 * The nine paths, in their documented order.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_all_lists_nine_paths_in_order(): void {
		$this->assertSame(
			array( 'classic', 'quick_edit', 'bulk_edit', 'rest', 'programmatic', 'crud', 'scheduled', 'cli', 'none' ),
			Save_Path::all()
		);
	}

	/**
	 * Only the exact strings are valid.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_is_valid_accepts_each_and_rejects_unknown(): void {
		foreach ( Save_Path::all() as $path ) {
			$this->assertTrue( Save_Path::is_valid( $path ), $path );
		}

		foreach ( array( 'editor', '', 'CLASSIC' ) as $path ) {
			$this->assertFalse( Save_Path::is_valid( $path ), $path );
		}
	}

	/**
	 * The admin forms are the three paths the `editor` scope covers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_admin_paths(): void {
		$this->assertSame( array( 'classic', 'quick_edit', 'bulk_edit' ), Save_Path::admin_paths() );
		$this->assertSame( Save_Path::admin_paths(), Save_Request_Reader::admin_sources() );
	}

	/**
	 * The legacy constants are aliases, so no filter sees a different string.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_values_match_the_legacy_source_strings(): void {
		$this->assertSame( Save_Path::CLASSIC, Save_Request_Reader::SOURCE_CLASSIC );
		$this->assertSame( Save_Path::QUICK_EDIT, Save_Request_Reader::SOURCE_QUICK_EDIT );
		$this->assertSame( Save_Path::BULK_EDIT, Save_Request_Reader::SOURCE_BULK_EDIT );
		$this->assertSame( Save_Path::REST, Save_Request_Reader::SOURCE_REST );
		$this->assertSame( Save_Path::PROGRAMMATIC, Save_Request_Reader::SOURCE_PROGRAMMATIC );
		$this->assertSame( Save_Path::CRUD, Publish_Guard::SOURCE_CRUD );
		$this->assertSame( Save_Path::SCHEDULED, Publish_Guard::SOURCE_SCHEDULED );
	}
}
