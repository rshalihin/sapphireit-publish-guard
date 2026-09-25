<?php
/**
 * Feedback for publishes the guard refused or let through on an override.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Engine\Validation_Result;

defined( 'ABSPATH' ) || exit;

/**
 * A short-lived, per-user queue of "publishing blocked" and "override used" events.
 *
 * The guard runs inside a save and cannot print anything; the merchant sees the outcome
 * on the next admin page instead. One transient per user holds one entry per product,
 * because neither Quick Edit nor Bulk Edit redirects with a product id, and a Bulk Edit
 * notice has to name every product it refused (coding-plan.md section 6.3.1).
 *
 * An entry carries only rule ids, labels and statuses. Product titles are read, and
 * escaped, when the notice is rendered.
 *
 * @since 1.0.0
 */
final class Notices {

	/**
	 * Transient name prefix; the user id completes it.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const TRANSIENT_PREFIX = 'sit_wcpg_blocked_';

	/**
	 * Query argument added to the editor redirect after a refused publish.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const QUERY_ARG = 'sit_wcpg_blocked';

	/**
	 * Seconds an entry waits to be shown.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const TTL = 60;

	/**
	 * Entry kind: the publish was refused.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const KIND_BLOCKED = 'blocked';

	/**
	 * Entry kind: the publish went through on an override.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const KIND_OVERRIDE = 'override';

	/**
	 * The editor messages that would otherwise claim the product was published or scheduled.
	 *
	 * @since 1.0.0
	 * @var int[]
	 */
	private const PUBLISHED_MESSAGES = array( 6, 9 );

	/**
	 * Products refused in this request, so their editor redirect can say so.
	 *
	 * @since 1.0.0
	 * @var array<int, true>
	 */
	private array $refused = array();

	/**
	 * Register the admin hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_filter( 'post_updated_messages', array( $this, 'filter_messages' ) );
		add_filter( 'redirect_post_location', array( $this, 'filter_redirect' ), 10, 2 );
	}

	/**
	 * Queue a notice for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int               $user_id The user to tell.
	 * @param int               $post_id The product; 0 for one that was never saved.
	 * @param Validation_Result $result  The result that decided the outcome.
	 * @param string            $kind    One of the KIND_* constants.
	 * @return void
	 */
	public function queue( int $user_id, int $post_id, Validation_Result $result, string $kind = self::KIND_BLOCKED ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$failures = array();

		foreach ( $result->get_results() as $row ) {
			if ( in_array( $row->get_rule_id(), $result->get_required_failures(), true ) ) {
				$failures[] = array(
					'rule_id' => $row->get_rule_id(),
					'label'   => $row->get_label(),
					'status'  => $row->get_status(),
				);
			}
		}

		$entries             = $this->pending( $user_id );
		$entries[ $post_id ] = array(
			'kind'     => self::KIND_OVERRIDE === $kind ? self::KIND_OVERRIDE : self::KIND_BLOCKED,
			'failures' => $failures,
		);

		set_transient( self::transient( $user_id ), $entries, self::TTL );

		if ( self::KIND_BLOCKED === $kind ) {
			$this->refused[ $post_id ] = true;
		}
	}

	/**
	 * The entries waiting for a user, keyed by product id.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id The user.
	 * @return array
	 */
	public function pending( int $user_id ): array {
		$entries = get_transient( self::transient( $user_id ) );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Mark the editor redirect after a refused publish.
	 *
	 * @since 1.0.0
	 *
	 * @param string $location Redirect URL.
	 * @param int    $post_id  The saved post.
	 * @return string
	 */
	public function filter_redirect( $location, $post_id = 0 ) {
		if ( ! is_string( $location ) || ! isset( $this->refused[ (int) $post_id ] ) ) {
			return $location;
		}

		return add_query_arg( self::QUERY_ARG, '1', $location );
	}

	/**
	 * Stop the editor claiming a refused product was published.
	 *
	 * Core picks "Product published." from the button that was pressed, not from the
	 * status the product ended up with.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $messages Messages keyed by post type, then by message number.
	 * @return mixed
	 */
	public function filter_messages( $messages ) {
		// Existence check only; the value is never read or echoed (section 9.3).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_array( $messages ) || ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return $messages;
		}

		foreach ( self::PUBLISHED_MESSAGES as $number ) {
			$messages[ Screen::POST_TYPE ][ $number ] = esc_html__( 'Product saved, but not published.', 'product-publish-guard' );
		}

		return $messages;
	}

	/**
	 * Print, then forget, the entries this screen is about.
	 *
	 * The product editor shows its own product's entry; the products list shows all of
	 * them. Anywhere else they wait, until they expire.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$entries = $this->pending( $user_id );

		if ( array() === $entries ) {
			return;
		}

		if ( Screen::is_product_edit_screen() ) {
			$product_id = Screen::current_product_id();
			$shown      = isset( $entries[ $product_id ] ) ? array( $product_id => $entries[ $product_id ] ) : array();
		} elseif ( Screen::is_product_list_screen() ) {
			$shown = $entries;
		} else {
			return;
		}

		if ( array() === $shown ) {
			return;
		}

		$remaining = array_diff_key( $entries, $shown );

		if ( array() === $remaining ) {
			delete_transient( self::transient( $user_id ) );
		} else {
			set_transient( self::transient( $user_id ), $remaining, self::TTL );
		}

		$single = Screen::is_product_edit_screen();

		foreach ( array( self::KIND_BLOCKED, self::KIND_OVERRIDE ) as $kind ) {
			$group = array_filter(
				$shown,
				static function ( $entry ) use ( $kind ) {
					return is_array( $entry ) && ( $entry['kind'] ?? '' ) === $kind;
				}
			);

			if ( array() !== $group ) {
				$this->render_group( $kind, $group, $single );
			}
		}
	}

	/**
	 * Print one notice for every entry of one kind.
	 *
	 * @since 1.0.0
	 *
	 * @param string $kind    One of the KIND_* constants.
	 * @param array  $entries Entries keyed by product id.
	 * @param bool   $single  Whether this is the product's own editor.
	 * @return void
	 */
	private function render_group( string $kind, array $entries, bool $single ): void {
		$blocked = self::KIND_BLOCKED === $kind;

		if ( $single ) {
			$heading = $blocked
				? __( 'This product was not published because required checks failed:', 'product-publish-guard' )
				: __( 'This product was published although required checks failed, because you are allowed to override the publishing guard:', 'product-publish-guard' );
		} else {
			$heading = $blocked
				? sprintf(
					/* translators: %d: number of products. */
					_n(
						'%d product was not published because required checks failed:',
						'%d products were not published because required checks failed:',
						count( $entries ),
						'product-publish-guard'
					),
					count( $entries )
				)
				: sprintf(
					/* translators: %d: number of products. */
					_n(
						'%d product was published although required checks failed, because you are allowed to override the publishing guard:',
						'%d products were published although required checks failed, because you are allowed to override the publishing guard:',
						count( $entries ),
						'product-publish-guard'
					),
					count( $entries )
				);
		}

		printf(
			'<div class="notice %1$s is-dismissible sit-wcpg-notice sit-wcpg-notice--%2$s"><p>%3$s</p><ul class="ul-disc sit-wcpg-notice__list">',
			esc_attr( $blocked ? 'notice-error' : 'notice-warning' ),
			esc_attr( $kind ),
			esc_html( $heading )
		);

		foreach ( $entries as $post_id => $entry ) {
			if ( $single ) {
				foreach ( self::labels( $entry ) as $label ) {
					printf( '<li>%s</li>', esc_html( $label ) );
				}

				continue;
			}

			printf(
				'<li><strong>%1$s</strong>: %2$s</li>',
				esc_html( self::product_name( (int) $post_id ) ),
				esc_html( implode( ', ', self::labels( $entry ) ) )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * The failed rule labels of one entry.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $entry A queued entry.
	 * @return string[]
	 */
	private static function labels( $entry ): array {
		$labels = array();

		foreach ( (array) ( $entry['failures'] ?? array() ) as $failure ) {
			if ( is_array( $failure ) && isset( $failure['label'] ) && is_string( $failure['label'] ) ) {
				$labels[] = $failure['label'];
			}
		}

		return $labels;
	}

	/**
	 * How a notice refers to a product.
	 *
	 * By id, never by title: section 9.5 forbids product-supplied text in any notice, so
	 * the stored-XSS class is closed structurally rather than by escaping discipline.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Product id; 0 for one that was never saved.
	 * @return string Plain text; the caller escapes it.
	 */
	private static function product_name( int $post_id ): string {
		return $post_id > 0
			/* translators: %d: product id. */
			? sprintf( __( 'Product #%d', 'product-publish-guard' ), $post_id )
			: __( 'A new product', 'product-publish-guard' );
	}

	/**
	 * The transient name for a user.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id The user.
	 * @return string
	 */
	private static function transient( int $user_id ): string {
		return self::TRANSIENT_PREFIX . $user_id;
	}
}
