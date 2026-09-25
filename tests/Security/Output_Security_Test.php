<?php
/**
 * Security coverage for everything the plugin prints about a product.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Security;

use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Product_List_Column;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Tests\Integration\Publish_Guard_Test_Case;

/**
 * Hostile product text and executable shortcodes (coding-plan.md section 12.3).
 *
 * Section 9.5 closes the stored-XSS class structurally: no message, `data` value, notice
 * or column value carries product-supplied text. These tests assert the outcome on the
 * raw output strings of the checklist, the list column and the admin notice. The REST
 * response is covered in `Rest_Validate_Security_Test`.
 *
 * @since 1.0.0
 */
final class Output_Security_Test extends Publish_Guard_Test_Case {

	/**
	 * The hostile title from section 12.3.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PAYLOAD = '"><script>alert(1)</script>';

	/**
	 * Shortcode tag whose callback records that it ran.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const PROBE = 'sit_wcpg_probe';

	/**
	 * How often the probe shortcode ran.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private int $probe_calls = 0;

	/**
	 * Remove the probe and restore the screen and global post.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_shortcode( self::PROBE );
		set_current_screen( 'front' );
		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * A draft titled with the payload that fails a required rule (no price).
	 *
	 * @since 1.0.0
	 *
	 * @param array $props Extra props.
	 * @return int Product id.
	 */
	private function hostile_product( array $props = array() ): int {
		return $this->passing_product(
			'draft',
			array_merge(
				array(
					'name'              => self::PAYLOAD,
					'short_description' => self::PAYLOAD,
					'regular_price'     => '',
				),
				$props
			)
		);
	}

	/**
	 * Assert a rendered string carries nothing of the payload.
	 *
	 * @since 1.0.0
	 *
	 * @param string $output  Raw rendered output.
	 * @param string $surface What was rendered, for the failure message.
	 * @return void
	 */
	private function assertNoPayload( string $output, string $surface ): void {
		$this->assertNotSame( '', $output, "The {$surface} rendered nothing, so the assertion would prove nothing." );
		$this->assertStringNotContainsString( '<script', $output, "The {$surface} contains a script tag." );
		$this->assertStringNotContainsString( 'alert(1)', $output, "The {$surface} contains product-supplied text." );
	}

	/**
	 * Capture the meta box for a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Product id.
	 * @return string
	 */
	private function render_meta_box( int $id ): string {
		ob_start();
		( new Editor_Meta_Box( Plugin::instance()->settings() ) )->render( get_post( $id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Capture the list column cell for a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Product id.
	 * @return string
	 */
	private function render_cell( int $id ): string {
		ob_start();
		( new Product_List_Column( Plugin::instance()->settings() ) )->render_column( Product_List_Column::COLUMN, $id );

		return (string) ob_get_clean();
	}

	/**
	 * Capture the admin notices for the current screen.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function render_notices(): string {
		ob_start();
		Plugin::instance()->notices()->render();

		return (string) ob_get_clean();
	}

	/**
	 * The server-rendered checklist carries none of a hostile title.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_hostile_title_never_reaches_the_checklist(): void {
		$this->assertNoPayload( $this->render_meta_box( $this->hostile_product() ), 'checklist' );
	}

	/**
	 * The Readiness cell carries none of a hostile title.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_hostile_title_never_reaches_the_list_column(): void {
		$output = $this->render_cell( $this->hostile_product() );

		$this->assertNoPayload( $output, 'list column' );
		$this->assertStringContainsString( 'sit-wcpg-readiness--fail', $output );
	}

	/**
	 * The products-list notice names the product by id, never by its title.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_hostile_title_never_reaches_the_list_notice(): void {
		$id = $this->hostile_product();

		$this->publish( $id );
		$this->assertSame( 'draft', $this->status_of( $id ) );

		set_current_screen( 'edit-product' );

		$output = $this->render_notices();

		$this->assertNoPayload( $output, 'products-list notice' );
		$this->assertStringContainsString( '#' . $id, $output );
	}

	/**
	 * The product editor's notice carries none of a hostile title either.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_hostile_title_never_reaches_the_editor_notice(): void {
		$id = $this->hostile_product();

		$this->publish( $id );
		$this->assertSame( 'draft', $this->status_of( $id ) );

		set_current_screen( 'product' );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's editor sets it; the notice reads the product from it.

		$this->assertNoPayload( $this->render_notices(), 'product editor notice' );
	}

	/**
	 * A shortcode in a description is stripped for measuring, never run, on every path
	 * that reads the description: the validator, the checklist, the column and the guard.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_a_shortcode_in_the_description_is_never_executed(): void {
		add_shortcode(
			self::PROBE,
			function () {
				++$this->probe_calls;

				return self::PAYLOAD;
			}
		);

		$id = $this->hostile_product(
			array(
				'description'       => '[' . self::PROBE . '] ' . str_repeat( 'Words. ', 40 ),
				'short_description' => '[' . self::PROBE . ']',
			)
		);

		Plugin::instance()->checklist()->validate_post( $id );
		$this->render_meta_box( $id );
		$this->render_cell( $id );
		$this->publish( $id );

		$this->assertSame( 0, $this->probe_calls, 'A product shortcode was executed.' );
		$this->assertSame( 'draft', $this->status_of( $id ) );
	}
}
