<?php
/**
 * The readiness filter on the products list.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Engine\Rule_Registry;
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
	 * The query var naming one failing required check, as the Dashboard links to it.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const RULE_QUERY_VAR = 'sit_wcpg_rule';

	/**
	 * Class of the chip naming the active check above the list.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const CHIP_CLASS = 'sit-wcpg-rule-chip';

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
	 * The rule registry, resolved on first use.
	 *
	 * @since 1.2.0
	 * @var Rule_Registry|null
	 */
	private ?Rule_Registry $registry;

	/**
	 * Construct the filter.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings               $settings The merchant's configuration.
	 * @param Readiness_Rebuild|null $rebuild  The rebuild. Null resolves the plugin's own.
	 * @param Rule_Registry|null     $registry The rule registry. Null resolves the plugin's own, only
	 *                                         when a rule is asked for.
	 */
	public function __construct( Settings $settings, ?Readiness_Rebuild $rebuild = null, ?Rule_Registry $registry = null ) {
		$this->settings = $settings;
		$this->rebuild  = $rebuild;
		$this->registry = $registry;
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
		add_action( 'manage_posts_extra_tablenav', array( $this, 'render_rule_chip' ), 10, 1 );
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
	 * The chosen failing check.
	 *
	 * Only a registered rule id passes: the submitted value must already be a sanitized key
	 * (a value that `sanitize_key()` would change is dropped, not cleaned into something
	 * else) and the registry must know it. The check narrows the "Not ready" list, so it is
	 * dropped when the select names another state.
	 *
	 * @since 1.2.0
	 *
	 * @return string A registered rule id, or '' for none.
	 */
	public function current_rule(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A read-only list filter, checked against the registry.
		if ( ! isset( $_GET[ self::RULE_QUERY_VAR ] ) || ! is_string( $_GET[ self::RULE_QUERY_VAR ] ) ) {
			return '';
		}

		$raw = wp_unslash( $_GET[ self::RULE_QUERY_VAR ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with its sanitized form below; only an exact match is used.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$rule_id = sanitize_key( $raw );
		$state   = self::current_filter();

		if ( '' === $rule_id || $rule_id !== $raw || ( '' !== $state && Readiness_Store::FILTER_FAIL !== $state ) ) {
			return '';
		}

		return $this->registry()->has( $rule_id ) ? $rule_id : '';
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

		$rule    = $this->current_rule();
		$current = '' === $rule ? self::current_filter() : Readiness_Store::FILTER_FAIL;

		echo '<label class="screen-reader-text" for="' . esc_attr( self::SELECT_ID ) . '">' . esc_html__( 'Filter by readiness', 'sapphireit-publish-guard' ) . '</label>';
		echo '<select id="' . esc_attr( self::SELECT_ID ) . '" name="' . esc_attr( self::QUERY_VAR ) . '" class="sit-wcpg-readiness-filter">';

		foreach ( self::options() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"';
			selected( $current, $value );
			echo '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';

		if ( '' !== $rule ) {
			// Kept through "Filter", so a search or a category change stays on this check.
			echo '<input type="hidden" name="' . esc_attr( self::RULE_QUERY_VAR ) . '" value="' . esc_attr( $rule ) . '" />';
		}
	}

	/**
	 * Name the active check above the list, with a link that removes it.
	 *
	 * Removing the check keeps the "Not ready" state, and goes back to the first page.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $which Which table navigation is being rendered.
	 * @return void
	 */
	public function render_rule_chip( $which = 'top' ): void {
		if (
			'top' !== $which
			|| ! Screen::is_product_list_screen()
			|| ! current_user_can( self::CAPABILITY )
			|| ! $this->settings->is_enabled()
		) {
			return;
		}

		$rule_id = $this->current_rule();
		$rule    = '' === $rule_id ? null : $this->registry()->get( $rule_id );

		if ( null === $rule ) {
			return;
		}

		$label  = $rule->get_label();
		$remove = add_query_arg( self::QUERY_VAR, Readiness_Store::FILTER_FAIL, remove_query_arg( array( self::RULE_QUERY_VAR, 'paged' ) ) );

		// A required pill of the admin UI kit (UI decision U5), with core's button to remove it.
		echo '<div class="alignleft actions sit-wcpg-ui ' . esc_attr( self::CHIP_CLASS ) . '">';
		echo '<span class="sit-wcpg-ui-pill sit-wcpg-ui-pill--required ' . esc_attr( self::CHIP_CLASS . '__label' ) . '">';
		/* translators: %s: the label of a check, such as "Featured image". */
		echo esc_html( sprintf( __( 'Failing: %s', 'sapphireit-publish-guard' ), $label ) );
		echo '</span> ';
		echo '<a class="button ' . esc_attr( self::CHIP_CLASS . '__remove' ) . '" href="' . esc_url( $remove ) . '" aria-label="';
		/* translators: %s: the label of a check, such as "Featured image". */
		echo esc_attr( sprintf( __( 'Remove the filter: %s', 'sapphireit-publish-guard' ), $label ) );
		echo '">&times;</a>';
		echo '</div>';
	}

	/**
	 * Narrow the list's own query to the chosen filter.
	 *
	 * Runs for every query on every admin screen, so the cheap checks come first, and the
	 * one that reads the settings comes last. The registry is built only when a check is
	 * asked for.
	 *
	 * A check adds a `LIKE` clause on `Readiness_Store::META_DATA`, the serialized list of
	 * required failures, matching the id in double quotes: `"price"` is a whole serialized
	 * string, so it never matches `"sale_price"`. The id is never escaped here with
	 * `esc_like()`: it comes from the registry's allow-list, already a sanitized key, so it
	 * holds no `%`, no quote and no backslash. `_`, a single-character wildcard in `LIKE`, is
	 * allowed in keys, but core's meta query escapes the value with `esc_like()` itself
	 * before adding its own `%…%`. The only text outside the list a quoted id could match is
	 * the array's own keys, `required_failures` and `checked_at`, which no rule is called.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence only; current_rule() validates it.
		if ( ( '' === $filter && ! isset( $_GET[ self::RULE_QUERY_VAR ] ) ) || ! $this->settings->is_enabled() ) {
			return;
		}

		$rule   = $this->current_rule();
		$filter = '' === $rule ? $filter : Readiness_Store::FILTER_FAIL;

		if ( '' === $filter ) {
			return;
		}

		$clauses  = array( 'relation' => 'AND' );
		$existing = $query->get( 'meta_query' );

		if ( is_array( $existing ) && array() !== $existing ) {
			$clauses[] = $existing;
		}

		$clauses[] = Readiness_Store::meta_query( $filter );

		if ( '' !== $rule ) {
			$clauses[] = array(
				'key'     => Readiness_Store::META_DATA,
				'value'   => '"' . $rule . '"',
				'compare' => 'LIKE',
			);
		}

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
			|| ! current_user_can( self::CAPABILITY )
			|| ! $this->settings->is_enabled()
			|| ( '' === self::current_filter() && '' === $this->current_rule() )
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

	/**
	 * The rule registry, resolved on first use.
	 *
	 * @since 1.2.0
	 *
	 * @return Rule_Registry
	 */
	private function registry(): Rule_Registry {
		if ( null === $this->registry ) {
			$this->registry = Plugin::instance()->registry();
		}

		return $this->registry;
	}
}
