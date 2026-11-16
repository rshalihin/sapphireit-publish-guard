<?php
/**
 * Security coverage for the Dashboard page.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Admin\Dashboard_Page;
use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Stats;
use ProductPublishGuard\Support\Readiness_Store;
use WP_UnitTestCase;

/**
 * Third-party rule labels are escaped, and the page changes nothing (SIT-WCPG-SEC, U12).
 *
 * @since 1.2.0
 */
final class Dashboard_Page_Security_Test extends WP_UnitTestCase {

	/**
	 * The label a hostile add-on registers.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const EVIL_LABEL = '<script>alert(1)</script><img src=x onerror=alert(2)>Evil "check"';

	/**
	 * A shop manager and no cached numbers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		delete_transient( Readiness_Stats::TRANSIENT );
	}

	/**
	 * Leave no cached numbers or request data behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_transient( Readiness_Stats::TRANSIENT );
		delete_option( Readiness_Rebuild::OPTION );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * The built-in rules plus one whose label carries markup.
	 *
	 * @since 1.2.0
	 *
	 * @return Rule_Registry
	 */
	private function registry(): Rule_Registry {
		$registry = new Rule_Registry();

		Rules_Provider::populate( $registry );

		$registry->register(
			new class() extends Abstract_Rule {

				/**
				 * Rule identifier.
				 *
				 * @return string
				 */
				public function get_id(): string {
					return 'acme_evil';
				}

				/**
				 * A label with markup in it.
				 *
				 * @return string
				 */
				public function get_label(): string {
					return '<script>alert(1)</script><img src=x onerror=alert(2)>Evil "check"';
				}

				/**
				 * Always fails.
				 *
				 * @param Product_Context $context  The product.
				 * @param Settings        $settings The configuration.
				 * @return Rule_Result
				 */
				public function check( Product_Context $context, Settings $settings ): Rule_Result {
					unset( $context, $settings );

					return $this->fail( 'Fails.' );
				}
			}
		);

		return $registry;
	}

	/**
	 * The page, rendered with the given registry.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Registry $registry The rules.
	 * @return string
	 */
	private function page( Rule_Registry $registry ): string {
		$settings = new Settings( $registry );

		update_option(
			Readiness_Rebuild::OPTION,
			array(
				'version'    => SIT_WCPG_VERSION,
				'signature'  => Readiness_Store::signature( $settings ),
				'state'      => Readiness_Rebuild::STATE_COMPLETE,
				'total'      => 3,
				'done'       => 3,
				'started_at' => time(),
			),
			false
		);

		set_transient(
			Readiness_Stats::TRANSIENT,
			array(
				'ready'        => 1,
				'warning'      => 0,
				'fail'         => 2,
				'unchecked'    => 0,
				'total'        => 3,
				'live_fail'    => 1,
				'top_failures' => array( 'acme_evil' => 2 ),
				'capped'       => false,
				'computed_at'  => time(),
			),
			Readiness_Stats::TTL
		);

		ob_start();
		( new Dashboard_Page( $settings, $registry, new Readiness_Stats( $settings, $registry ) ) )->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * A third-party label is printed as text, in the issues list and in the active checks.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_third_party_rule_label_is_escaped(): void {
		$html = $this->page( $this->registry() );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( 'onerror=alert(2)>', $html );
		$this->assertStringContainsString( '<span class="sit-wcpg-dashboard__issue-label" id="sit-wcpg-dashboard-issue-acme_evil">' . esc_html( self::EVIL_LABEL ) . '</span>', $html );
		$this->assertStringContainsString( '<span class="sit-wcpg-dashboard__check-label">' . esc_html( self::EVIL_LABEL ) . '</span>', $html );
	}

	/**
	 * The page is read-only: no form, no nonce, and request data changes nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_page_has_no_state_change(): void {
		$before = get_option( Settings::OPTION_NAME );

		$request = array(
			'action'              => 'delete',
			'sit_wcpg_rule'       => 'price',
			Settings::OPTION_NAME => array( 'enabled' => '0' ),
		);

		// phpcs:disable WordPress.Security.NonceVerification -- Hostile request data the page must ignore.
		$_GET     = $request;
		$_POST    = $request;
		$_REQUEST = $request;
		// phpcs:enable WordPress.Security.NonceVerification

		$html = $this->page( $this->registry() );

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '_wpnonce', $html );
		$this->assertStringNotContainsString( 'method="post"', $html );
		$this->assertSame( $before, get_option( Settings::OPTION_NAME ) );
	}

	/**
	 * Every external link opens a new tab without access to this one.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_external_links_carry_noopener(): void {
		$html = $this->page( $this->registry() );

		preg_match_all( '#<a [^>]*href="https?://(?!' . preg_quote( wp_parse_url( home_url(), PHP_URL_HOST ), '#' ) . ')[^"]*"[^>]*>#', $html, $links );

		$this->assertNotEmpty( $links[0] );

		foreach ( $links[0] as $link ) {
			$this->assertStringContainsString( 'target="_blank" rel="noopener"', $link );
		}
	}
}
