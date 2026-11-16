<?php
/**
 * The catalog screen: live products the merchant's rules would refuse.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Products → Publish Guard.
 *
 * Everything on the screen comes from the stored flags, so opening it validates nothing.
 * It frames each row as a consequence of the merchant's own settings: no score, no grade
 * and no check count (roadmap "Gate-driven catalog view"). There is no export and no
 * "Re-check all" (UI decision U16): the page bar links to the products list instead.
 *
 * @since 1.2.0
 */
final class Catalog_Page {

	/**
	 * Menu slug of the screen.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const MENU_SLUG = 'sit-wcpg-catalog';

	/**
	 * Capability needed to see the screen.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const CAPABILITY = 'edit_products';

	/**
	 * The menu the screen sits under.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const PARENT = 'edit.php?post_type=product';

	/**
	 * Rows per page.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const PER_PAGE = 20;

	/**
	 * HTML id of the recheck progress message, which names the progress bar.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const PROGRESS_ID = 'sit-wcpg-catalog-progress';

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
	 * Construct the page.
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
	 * Hook the screen into the admin.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'load-' . Screen::CATALOG_HOOK, array( $this, 'on_load' ), 10, 0 );
	}

	/**
	 * Add the submenu entry.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			self::PARENT,
			self::title(),
			__( 'Publish Guard', 'sapphireit-publish-guard' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Start a rebuild when the screen opens on stale flags.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function on_load(): void {
		$this->rebuild()->ensure_current();
	}

	/**
	 * Render the screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sapphireit-publish-guard' ) );
		}

		Admin_Shell::render( Admin_Shell::TAB_LIVE_PRODUCTS );

		echo '<div class="wrap sit-wcpg-ui sit-wcpg-catalog">';

		Admin_Shell::render_page_bar(
			self::title(),
			__( 'These products are published. Under your required checks, each would be held as a draft if it were published today.', 'sapphireit-publish-guard' ),
			array(
				'label' => __( 'Open the products list', 'sapphireit-publish-guard' ),
				'url'   => add_query_arg( 'post_type', Screen::POST_TYPE, admin_url( 'edit.php' ) ),
			)
		);

		if ( ! $this->settings->is_enabled() ) {
			// Whatever is stored dates from before the switch, and nothing is checking it now.
			self::render_banner(
				'info',
				'info',
				__( 'The product checklist is switched off, so nothing is being checked. Turn it on under WooCommerce → Product Checklist.', 'sapphireit-publish-guard' )
			);

			echo '</div>';

			return;
		}

		$table  = $this->table();
		$total  = (int) $table->get_pagination_arg( 'total_items' );
		$status = $this->rebuild()->status();

		if ( $total > 0 ) {
			self::render_banner( 'success', 'shield', __( 'They stay live — Publish Guard never takes a product offline.', 'sapphireit-publish-guard' ) );
		}

		$this->render_progress( $status );

		if ( 0 === $total ) {
			$this->render_empty( (bool) $status['is_current'] );
		} else {
			$this->render_table( $table );
		}

		echo '</div>';
	}

	/**
	 * The screen's title, shared by the menu entry and the page bar.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private static function title(): string {
		return __( 'Live products that would fail today', 'sapphireit-publish-guard' );
	}

	/**
	 * Say so when the list cannot be trusted yet: how far a recheck has got, or that none can run.
	 *
	 * @since 1.2.0
	 *
	 * @param array $status The rebuild's status.
	 * @return void
	 */
	private function render_progress( array $status ): void {
		if ( $status['is_current'] ) {
			return;
		}

		if ( ! $status['scheduler'] ) {
			self::render_banner( 'warning', 'alert', Readiness_Filter::no_scheduler_message() );

			return;
		}

		if ( Readiness_Rebuild::STATE_BUILDING !== $status['state'] ) {
			return;
		}

		$total = max( 0, (int) $status['total'] );
		$done  = max( 0, (int) $status['done'] );
		$done  = $total > 0 ? min( $done, $total ) : $done;
		$share = $total > 0 ? (int) floor( $done * 100 / $total ) : 0;

		$message = sprintf(
			/* translators: 1: products rechecked so far, 2: products to recheck in total. */
			__( 'Rechecking products against your current settings: %1$d of %2$d done. This list will be complete when it finishes.', 'sapphireit-publish-guard' ),
			$done,
			$total
		);

		echo '<div class="sit-wcpg-ui-banner sit-wcpg-ui-banner--info sit-wcpg-catalog__progress">';
		echo wp_kses( Icons::svg( 'refresh', 18 ), Icons::allowed_html() );
		echo '<div class="sit-wcpg-ui-banner__body"><p id="' . esc_attr( self::PROGRESS_ID ) . '">' . esc_html( $message ) . '</p></div>';
		echo '<span class="sit-wcpg-ui-bar sit-wcpg-catalog__progress-bar" role="progressbar" aria-labelledby="' . esc_attr( self::PROGRESS_ID ) . '" aria-valuemin="0" aria-valuemax="' . esc_attr( (string) $total ) . '" aria-valuenow="' . esc_attr( (string) $done ) . '">';
		echo '<span class="sit-wcpg-ui-bar__fill" style="' . esc_attr( 'width: ' . $share . '%' ) . '"></span></span>';
		echo '</div>';
	}

	/**
	 * Print a banner of the kit (U5).
	 *
	 * @since 1.2.0
	 *
	 * @param string $tone    `success`, `info` or `warning`.
	 * @param string $icon    An icon name.
	 * @param string $message The message, plain text.
	 * @return void
	 */
	private static function render_banner( string $tone, string $icon, string $message ): void {
		echo '<div class="' . esc_attr( 'sit-wcpg-ui-banner sit-wcpg-ui-banner--' . $tone ) . '">';
		echo wp_kses( Icons::svg( $icon, 18 ), Icons::allowed_html() );
		echo '<div class="sit-wcpg-ui-banner__body"><p>' . esc_html( $message ) . '</p></div>';
		echo '</div>';
	}

	/**
	 * What an empty list means, which depends on whether checking has finished.
	 *
	 * @since 1.2.0
	 *
	 * @param bool $is_current Whether every flag is current.
	 * @return void
	 */
	private function render_empty( bool $is_current ): void {
		echo '<section class="sit-wcpg-ui-card sit-wcpg-catalog__empty"><div class="sit-wcpg-ui-empty">';
		echo '<span class="sit-wcpg-ui-empty__icon">' . wp_kses( Icons::svg( $is_current ? 'shield' : 'refresh', 24 ), Icons::allowed_html() ) . '</span>';

		if ( ! $is_current ) {
			echo '<h2 class="sit-wcpg-ui-empty__title">' . esc_html__( 'No failing live products found yet', 'sapphireit-publish-guard' ) . '</h2>';
			echo '<p class="sit-wcpg-ui-empty__text">' . esc_html__( 'Rechecking is still running, so this list may grow.', 'sapphireit-publish-guard' ) . '</p>';
			echo '</div></section>';

			return;
		}

		echo '<h2 class="sit-wcpg-ui-empty__title">' . esc_html__( 'Every live product passes your required checks', 'sapphireit-publish-guard' ) . '</h2>';
		echo '<p class="sit-wcpg-ui-empty__text">' . esc_html__( 'We\'ll list a product here if a rule you tighten later would stop it being published.', 'sapphireit-publish-guard' ) . '</p>';

		if ( current_user_can( Settings_Page::CAPABILITY ) ) {
			echo '<a class="sit-wcpg-catalog__empty-link" href="' . esc_url( add_query_arg( 'page', Settings_Page::MENU_SLUG, admin_url( 'admin.php' ) ) ) . '">';
			echo esc_html__( 'Review your checklist', 'sapphireit-publish-guard' ) . ' <span aria-hidden="true">&rarr;</span></a>';
		}

		echo '</div></section>';
	}

	/**
	 * The list table, prepared.
	 *
	 * @since 1.2.0
	 *
	 * @return Catalog_List_Table
	 */
	private function table(): Catalog_List_Table {
		$labels = array();

		foreach ( Plugin::instance()->registry()->all() as $rule ) {
			$labels[ $rule->get_id() ] = $rule->get_label();
		}

		$table = new Catalog_List_Table( Readiness_Store::signature( $this->settings ), $labels );

		$table->prepare_items();

		return $table;
	}

	/**
	 * Render the list table inside a form, so its page-number box stays on this screen.
	 *
	 * The table scrolls inside its card on narrow screens, so the page never does.
	 *
	 * @since 1.2.0
	 *
	 * @param Catalog_List_Table $table The prepared table.
	 * @return void
	 */
	private function render_table( Catalog_List_Table $table ): void {
		echo '<form method="get" class="sit-wcpg-catalog__form">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( Screen::POST_TYPE ) . '" />';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
		echo '<div class="sit-wcpg-ui-table sit-wcpg-catalog__table">';

		$table->display();

		echo '</div></form>';
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
