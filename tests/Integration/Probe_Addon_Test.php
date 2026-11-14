<?php
/**
 * Tests that an add-on can be built on the extension points alone.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Catalog_Page;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Checklist_Service;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use Sit_Wcpg_Probe;
use Sit_Wcpg_Probe_Rule;
use Spy_REST_Server;
use WP_REST_Request;

/**
 * `tests/fixtures/sit-wcpg-probe` is a whole add-on that touches nothing but public hooks
 * and `@api` members. The test bootstrap loads it right after the plugin, as WordPress
 * loads an add-on. Every behaviour an add-on needs is asserted here through it, so a
 * missing extension point shows up as a failing test, not as an edit inside `src/`.
 *
 * @since 1.3.0
 */
final class Probe_Addon_Test extends Publish_Guard_Test_Case {

	/**
	 * `$_SERVER['REQUEST_URI']` as it was.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	private string $request_uri = '';

	/**
	 * Switch the add-on on, with no index and an empty queue.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Sit_Wcpg_Probe::$enabled = true;

		$this->request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.

		delete_option( Readiness_Rebuild::OPTION );
		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );
	}

	/**
	 * Switch the add-on off, so no other test meets it.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function tear_down() {
		Sit_Wcpg_Probe::$enabled = false;

		unset( $GLOBALS['current_screen'], $GLOBALS['post'], $GLOBALS['wp_rest_server'] );

		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core rebuilds it on next use.

		$_SERVER['REQUEST_URI'] = $this->request_uri;

		delete_option( Readiness_Rebuild::OPTION );

		parent::tear_down();
	}

	/**
	 * Store a probe code on a product, past the guard.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $id   Product id.
	 * @param string $code The code.
	 * @return int The product id.
	 */
	private function with_code( int $id, string $code = 'stored' ): int {
		$product = wc_get_product( $id );

		$product->update_meta_data( Sit_Wcpg_Probe::META, $code );

		return $this->save_unguarded( $product );
	}

	/**
	 * The probe rule's row in a product's checklist, or null when it is not listed.
	 *
	 * @since 1.3.0
	 *
	 * @param int $id Product id.
	 * @return Rule_Result|null
	 */
	private function probe_row( int $id ): ?Rule_Result {
		Checklist_Service::flush_memo();

		foreach ( Plugin::instance()->checklist()->validate_post( $id )->get_results() as $row ) {
			if ( Sit_Wcpg_Probe_Rule::ID === $row->get_rule_id() ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The current readiness signature.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	private function signature(): string {
		return Readiness_Store::signature( Plugin::instance()->settings() );
	}

	/**
	 * S3: the rule runs with no entry in the settings.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_rule_is_listed_without_a_settings_entry(): void {
		$settings = Plugin::instance()->settings();

		$this->assertFalse( $settings->rule_is_enabled( Sit_Wcpg_Probe_Rule::ID ) );
		$this->assertArrayNotHasKey( Sit_Wcpg_Probe_Rule::ID, $settings->get_all()['rules'] );

		$row = $this->probe_row( $this->passing_product() );

		$this->assertInstanceOf( Rule_Result::class, $row );
		$this->assertSame( Status::FAIL, $row->get_status() );
		$this->assertSame( Sit_Wcpg_Probe::GROUP, $row->get_group() );
	}

	/**
	 * S3: out of the add-on's scope means not listed at all.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_exempt_category_product_does_not_list_the_rule(): void {
		$term = wp_insert_term( 'Probe exempt', 'product_cat', array( 'slug' => Sit_Wcpg_Probe::EXEMPT_CATEGORY ) );

		$exempt = $this->passing_product( 'draft', array( 'category_ids' => array( (int) $term['term_id'] ) ) );

		$this->assertNull( $this->probe_row( $exempt ) );
		$this->assertNotNull( $this->probe_row( $this->passing_product() ) );

		$this->publish_through( 'classic', $exempt );

		$this->assertSame( 'publish', $this->status_of( $exempt ), 'A rule that is not listed cannot block.' );
	}

	/**
	 * S3: the add-on sets the severity product by product.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_severity_is_per_product(): void {
		$external = $this->probe_row( $this->external_product() );
		$simple   = $this->probe_row( $this->passing_product() );

		$this->assertSame( Severity::WARNING, $external->get_severity() );
		$this->assertSame( Status::WARNING, $external->get_status() );
		$this->assertSame( Severity::REQUIRED, $simple->get_severity() );
		$this->assertSame( Status::FAIL, $simple->get_status() );
	}

	/**
	 * S8: a value typed in the classic editor is judged before it is stored.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_classic_publish_judges_the_posted_value(): void {
		$id   = $this->passing_product();
		$form = $this->classic_form( $id );

		$form[ Sit_Wcpg_Probe::META ] = 'abc';

		$this->publish( $id, $form );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * S8: a field the merchant has just emptied is not rescued by the stored value.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_classic_publish_refused_when_posted_empty_and_stored_set(): void {
		$id   = $this->with_code( $this->passing_product() );
		$form = $this->classic_form( $id );

		$form[ Sit_Wcpg_Probe::META ] = '';

		$this->publish( $id, $form );

		$this->assertSame( 'draft', $this->status_of( $id ) );
		$this->assertSame( 'stored', wc_get_product( $id )->get_meta( Sit_Wcpg_Probe::META ), 'Nothing but the status was touched.' );
	}

	/**
	 * S7: Quick Edit posts no probe field, so the stored value decides, either way.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_quick_edit_publish_judges_the_stored_value(): void {
		$with = $this->with_code( $this->passing_product() );

		$this->publish_through( 'quick_edit', $with );

		$this->assertSame( 'publish', $this->status_of( $with ) );

		$without = $this->passing_product();

		$this->publish_through( 'quick_edit', $without );

		$this->assertSame( 'draft', $this->status_of( $without ) );
	}

	/**
	 * S7: the scheduled-publish cron reads the stored value.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_scheduled_publish_judges_the_stored_value(): void {
		$with = $this->with_code( $this->passing_product() );

		$this->publish_through( 'scheduled', $with );

		$this->assertSame( 'publish', $this->status_of( $with ) );

		$without = $this->passing_product();

		$this->publish_through( 'scheduled', $without );

		$this->assertNotSame( 'publish', $this->status_of( $without ) );
	}

	/**
	 * S9: the live checklist judges what the add-on's editor script sent.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_rest_validate_reads_draft_extras(): void {
		global $wp_rest_server;

		$wp_rest_server = new Spy_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server for this test.

		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook.

		$id = $this->passing_product();

		$status = static function ( array $extras ) use ( $id, $wp_rest_server ): string {
			$request = new WP_REST_Request( 'POST', '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $id . '/validate' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'draft' => array( 'extras' => $extras ) ) ) );

			foreach ( $wp_rest_server->dispatch( $request )->get_data()['results'] as $row ) {
				if ( Sit_Wcpg_Probe_Rule::ID === $row['rule_id'] ) {
					return $row['status'];
				}
			}

			return '';
		};

		$this->assertSame( Status::PASS, $status( array( Sit_Wcpg_Probe::EXTRA => 'typed' ) ) );
		$this->assertSame( Status::FAIL, $status( array( Sit_Wcpg_Probe::EXTRA => '' ) ) );
		$this->assertSame( Status::FAIL, $status( array( 'somebody_elses' => 'typed' ) ), 'A key the add-on does not accept seeds nothing.' );
	}

	/**
	 * S11: the add-on's group reaches the editor panel with its label.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_group_label_is_in_the_editor_payload(): void {
		if ( ! file_exists( SIT_WCPG_PATH . 'build/editor.js' ) ) {
			$this->markTestSkipped( 'Run `npm run build` first: build/editor.js is missing.' );
		}

		$GLOBALS['post'] = get_post( $this->passing_product() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core sets it on the editor screen.

		set_current_screen( Screen::POST_TYPE );
		( new Assets( Plugin::instance()->settings() ) )->enqueue( 'post.php' );

		$inline  = implode( '', (array) wp_scripts()->get_data( Assets::EDITOR_HANDLE, 'before' ) );
		$payload = json_decode( substr( $inline, strlen( 'window.' . Assets::PAYLOAD_GLOBAL . ' = ' ), -1 ), true );

		$this->assertSame( 'Probe', $payload['groups'][ Sit_Wcpg_Probe::GROUP ] );
		$this->assertSame( Sit_Wcpg_Probe::GROUP, array_key_last( $payload['groups'] ) );
		$this->assertContains( Sit_Wcpg_Probe_Rule::ID, array_column( $payload['result']['results'], 'rule_id' ) );
	}

	/**
	 * S5: a change to the add-on's configuration makes every stored flag stale.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_salt_change_makes_stored_readiness_stale(): void {
		$rebuild = Plugin::instance()->readiness_rebuild();

		$rebuild->mark_complete( 3 );

		$this->assertTrue( $rebuild->status()['is_current'] );

		$before = $this->signature();

		update_option( Sit_Wcpg_Probe::SALT_OPTION, 'v2' );

		$this->assertNotSame( $before, $this->signature() );
		$this->assertFalse( $rebuild->status()['is_current'] );
	}

	/**
	 * S6: the add-on asks for the flags to be rebuilt.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_rules_changed_starts_a_rebuild(): void {
		$this->passing_product();

		update_option( Sit_Wcpg_Probe::SALT_OPTION, 'v3' );

		do_action( 'sit_wcpg_rules_changed' );

		$index = get_option( Readiness_Rebuild::OPTION );

		$this->assertSame( Readiness_Rebuild::STATE_BUILDING, $index['state'] );
		$this->assertSame( $this->signature(), $index['signature'] );
	}

	/**
	 * A live product that fails the add-on's rule is in the catalog view, under its label.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_catalog_view_shows_the_probe_label_for_a_failing_live_product(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?post_type=product&page=sit-wcpg-catalog';

		set_current_screen( Screen::CATALOG_HOOK );

		$id = $this->passing_product( 'publish' );

		Plugin::instance()->readiness_indexer()->index( $id, $this->signature() );
		Plugin::instance()->readiness_rebuild()->mark_complete( 1 );

		ob_start();

		try {
			( new Catalog_Page( Plugin::instance()->settings() ) )->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'A complete product', $html );
		$this->assertStringContainsString( 'Probe code', $html );
	}

	/**
	 * S3: the merchant is never offered a switch for the add-on's rule.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function test_settings_screen_has_no_probe_row(): void {
		$plugin = Plugin::instance();

		$this->assertTrue( $plugin->registry()->has( Sit_Wcpg_Probe_Rule::ID ) );

		ob_start();
		( new Settings_Page( $plugin->settings(), $plugin->registry() ) )->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'SKU', $output );
		$this->assertStringNotContainsString( 'Probe code', $output );
		$this->assertStringNotContainsString( Sit_Wcpg_Probe_Rule::ID, $output );
	}
}
