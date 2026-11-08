<?php
/**
 * The catalog screen: live products the merchant's rules would refuse.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Products → Publish Guard.
 *
 * Everything on the screen comes from the stored flags, so opening it validates nothing.
 * It frames each row as a consequence of the merchant's own settings: no score, no grade
 * and no check count (roadmap "Gate-driven catalog view").
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
			__( 'Live products that would fail your rules', 'sapphireit-publish-guard' ),
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

		echo '<div class="wrap sit-wcpg-catalog">';
		echo '<h1>' . esc_html__( 'Live products that would fail your rules', 'sapphireit-publish-guard' ) . '</h1>';
		echo '<p>' . esc_html__( 'These products are published. Under the checks you have set as required, each one would be refused if it were published today. They stay live — Publish Guard never takes a product offline.', 'sapphireit-publish-guard' ) . '</p>';

		if ( $this->settings->is_enabled() ) {
			$this->render_banner();
			$this->render_table();
		} else {
			// Whatever is stored dates from before the switch, and nothing is checking it now.
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The product checklist is switched off, so nothing is being checked. Turn it on under WooCommerce → Product Checklist.', 'sapphireit-publish-guard' ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * Say so when the list cannot be trusted yet.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_banner(): void {
		$status = $this->rebuild()->status();

		if ( $status['is_current'] ) {
			return;
		}

		if ( ! $status['scheduler'] ) {
			$message = Readiness_Filter::no_scheduler_message();
		} elseif ( Readiness_Rebuild::STATE_BUILDING === $status['state'] ) {
			$message = sprintf(
				/* translators: 1: products rechecked so far, 2: products to recheck in total. */
				__( 'Rechecking products against your current settings: %1$d of %2$d done. This list will be complete when it finishes.', 'sapphireit-publish-guard' ),
				$status['done'],
				$status['total']
			);
		} else {
			return;
		}

		echo '<div class="notice notice-info inline"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Render the list table inside a form, so its page-number box stays on this screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function render_table(): void {
		$labels = array();

		foreach ( Plugin::instance()->registry()->all() as $rule ) {
			$labels[ $rule->get_id() ] = $rule->get_label();
		}

		$table = new Catalog_List_Table( Readiness_Store::signature( $this->settings ), $labels );

		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( Screen::POST_TYPE ) . '" />';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';

		$table->display();

		echo '</form>';
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
