<?php
/**
 * The readiness filter on the products list.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Finds not-ready products across the whole catalog, from the stored flags.
 *
 * The readiness column keeps validating live, so a stale flag can never mislabel a row. It
 * can only leave a row out of a filtered list, and the banner says when that may be so.
 *
 * @since 1.2.0
 */
final class Readiness_Filter {

	/**
	 * The query var the select submits.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const QUERY_VAR = 'sit_wcpg_readiness';

	/**
	 * Capability needed to see and use the filter.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const CAPABILITY = 'edit_products';

	/**
	 * The id of the select, which its label points at.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const SELECT_ID = 'sit-wcpg-readiness-filter';

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The rebuild, resolved on first use.
	 *
	 * @since 1.2.0
	 * @var Readiness_Rebuild|null
	 */
	private ?Readiness_Rebuild $rebuild;

	/**
	 * Construct the filter.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings               $settings The merchant's configuration.
	 * @param Readiness_Rebuild|null $rebuild  The rebuild. Null resolves the plugin's own.
	 */
	public function __construct( Settings $settings, ?Readiness_Rebuild $rebuild = null ) {
		$this->settings = $settings;
		$this->rebuild  = $rebuild;
	}

	/**
	 * Hook the filter into the products list.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		// After WooCommerce's own selects, which render at the default priority (R14).
		add_action( 'restrict_manage_posts', array( $this, 'render' ), 20, 2 );
		add_action( 'pre_get_posts', array( $this, 'apply' ), 10, 1 );
		add_action( 'load-edit.php', array( $this, 'on_load' ), 10, 0 );
		add_action( 'admin_notices', array( $this, 'render_notice' ), 10, 0 );
	}

	/**
	 * The chosen filter.
	 *
	 * @since 1.2.0
	 *
	 * @return string One of the Readiness_Store filters, or '' for none.
	 */
	public static function current_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list filter, checked against a whitelist.
		$value = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		return in_array( $value, Readiness_Store::filters(), true ) ? $value : '';
	}

	/**
	 * The banner shown when flags are stale and nothing can recheck them.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function no_scheduler_message(): string {
		return __( 'This filtered list may be out of date: background rechecks need WooCommerce\'s Action Scheduler, which isn\'t available. Run "wp sit-wcpg reindex" to update it.', 'sapphireit-publish-guard' );
	}

	/**
	 * Render the select.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $post_type The list's post type.
	 * @param mixed $which     Which table navigation is being rendered.
	 * @return void
	 */
	public function render( $post_type, $which = 'top' ): void {
		if ( Screen::POST_TYPE !== $post_type || 'top' !== $which || ! current_user_can( self::CAPABILITY ) || ! $this->settings->is_enabled() ) {
			return;
		}

		$current = self::current_filter();

		echo '<label class="screen-reader-text" for="' . esc_attr( self::SELECT_ID ) . '">' . esc_html__( 'Filter by readiness', 'sapphireit-publish-guard' ) . '</label>';
		echo '<select id="' . esc_attr( self::SELECT_ID ) . '" name="' . esc_attr( self::QUERY_VAR ) . '" class="sit-wcpg-readiness-filter">';

		foreach ( self::options() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"';
			selected( $current, $value );
			echo '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Narrow the list's own query to the chosen filter.
	 *
	 * Runs for every query on every admin screen, so the cheap checks come first, and the
	 * one that reads the settings comes last.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $query The query about to run.
	 * @return void
	 */
	public function apply( $query ): void {
		if (
			! $query instanceof WP_Query
			|| ! is_admin()
			|| ! $query->is_main_query()
			|| Screen::POST_TYPE !== $query->get( 'post_type' )
			|| ! Screen::is_product_list_screen()
			|| ! current_user_can( self::CAPABILITY )
		) {
			return;
		}

		$filter = self::current_filter();

		if ( '' === $filter || ! $this->settings->is_enabled() ) {
			return;
		}

		$clauses  = array( 'relation' => 'AND' );
		$existing = $query->get( 'meta_query' );

		if ( is_array( $existing ) && array() !== $existing ) {
			$clauses[] = $existing;
		}

		$clauses[] = Readiness_Store::meta_query( $filter );

		$query->set( 'meta_query', $clauses );
	}

	/**
	 * Start a rebuild when the products list opens on stale flags.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function on_load(): void {
		if ( Screen::is_product_list_screen() && current_user_can( self::CAPABILITY ) ) {
			$this->rebuild()->ensure_current();
		}
	}

	/**
	 * Say so when a filtered list cannot be trusted yet.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if (
			! Screen::is_product_list_screen()
			|| '' === self::current_filter()
			|| ! current_user_can( self::CAPABILITY )
			|| ! $this->settings->is_enabled()
		) {
			return;
		}

		$status = $this->rebuild()->status();

		if ( $status['is_current'] ) {
			return;
		}

		if ( ! $status['scheduler'] ) {
			$message = self::no_scheduler_message();
		} elseif ( Readiness_Rebuild::STATE_BUILDING === $status['state'] ) {
			$message = sprintf(
				/* translators: 1: products rechecked so far, 2: products to recheck in total. */
				__( 'Rechecking products against your current settings: %1$d of %2$d done. This filtered list will be complete when it finishes.', 'sapphireit-publish-guard' ),
				$status['done'],
				$status['total']
			);
		} else {
			return;
		}

		echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * The select's options, in display order.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string>
	 */
	private static function options(): array {
		return array(
			''                                => __( 'All readiness states', 'sapphireit-publish-guard' ),
			Readiness_Store::FILTER_READY     => __( 'Ready', 'sapphireit-publish-guard' ),
			Readiness_Store::FILTER_WARNING   => __( 'Warnings', 'sapphireit-publish-guard' ),
			Readiness_Store::FILTER_FAIL      => __( 'Not ready', 'sapphireit-publish-guard' ),
			Readiness_Store::FILTER_UNCHECKED => __( 'Not checked yet', 'sapphireit-publish-guard' ),
		);
	}

	/**
	 * The rebuild, resolved on first use.
	 *
	 * @since 1.2.0
	 *
	 * @return Readiness_Rebuild
	 */
	private function rebuild(): Readiness_Rebuild {
		if ( null === $this->rebuild ) {
			$this->rebuild = Plugin::instance()->readiness_rebuild();
		}

		return $this->rebuild;
	}
}
