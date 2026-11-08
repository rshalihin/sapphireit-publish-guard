<?php
/**
 * Tests for the messages the admin notices print.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Plugin;

/**
 * Coding-plan.md section 6.5: the editor notice names each fix, the list notice stays short.
 *
 * The editor notice prints each failure's message, falling back to the label when a
 * third-party rule returns none. The products-list notice keeps one line of labels per
 * product. Every printed string is escaped.
 *
 * @since 1.1.0
 */
final class Notices_Message_Test extends Publish_Guard_Test_Case {

	/**
	 * The empty-price failure from coding-plan.md section 5.6.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private const PRICE_MESSAGE = 'The regular price is empty — enter it in Product data → General → Regular price.';

	/**
	 * Restore the screen and global post.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * Queue a blocked entry whose only result is one fake rule's failure.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $post_id Product id.
	 * @param string $message The fake rule's message.
	 * @return void
	 */
	private function queue_fake_failure( int $post_id, string $message ): void {
		$result = Validation_Result::from_results(
			Product_Context::from_array( array( 'product_id' => $post_id ) ),
			array( Rule_Result::fail( 'fake_rule', 'Fake rule label', 'content', $message ) )
		);

		Plugin::instance()->notices()->queue( $this->admin_id, $post_id, $result );
	}

	/**
	 * Render the notices with the product's editor as the current screen.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Product id.
	 * @return string
	 */
	private function render_editor( int $post_id ): string {
		set_current_screen( 'product' );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's editor sets it; the notice reads the product from it.

		return $this->render();
	}

	/**
	 * Capture the notices.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		Plugin::instance()->notices()->render();

		return (string) ob_get_clean();
	}

	/**
	 * The editor notice tells the merchant what to fix, not just which check failed.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_editor_notice_lists_failure_messages(): void {
		$id = $this->failing_product();

		$this->publish( $id );
		$this->assertSame( 'draft', $this->status_of( $id ) );

		$output = $this->render_editor( $id );

		$this->assertStringContainsString( '<li>' . esc_html( self::PRICE_MESSAGE ) . '</li>', $output );
	}

	/**
	 * A rule that returns no message is still listed, by its label.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_editor_notice_falls_back_to_the_label(): void {
		$id = $this->passing_product();

		$this->queue_fake_failure( $id, '' );

		$this->assertStringContainsString( '<li>Fake rule label</li>', $this->render_editor( $id ) );
	}

	/**
	 * The products-list notice stays one line of labels per product.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_list_notice_keeps_labels_only(): void {
		$id = $this->failing_product();

		$this->publish( $id );
		$this->assertSame( 'draft', $this->status_of( $id ) );

		set_current_screen( 'edit-product' );

		$output = $this->render();

		$this->assertStringContainsString( '<li><strong>Product #' . $id . '</strong>: Regular price</li>', $output );
		$this->assertStringNotContainsString( esc_html( self::PRICE_MESSAGE ), $output );
	}

	/**
	 * A message is printed as text, never as markup.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_message_is_escaped(): void {
		$id = $this->passing_product();

		$this->queue_fake_failure( $id, 'Make it <b>bold</b> — somewhere.' );

		$output = $this->render_editor( $id );

		$this->assertStringContainsString( '<li>Make it &lt;b&gt;bold&lt;/b&gt; — somewhere.</li>', $output );
		$this->assertStringNotContainsString( '<b>', $output );
	}
}
