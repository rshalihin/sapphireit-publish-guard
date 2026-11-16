<?php
/**
 * Tests for the catalog screen.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use ProductPublishGuard\Admin\Catalog_List_Table;
use ProductPublishGuard\Admin\Catalog_Page;
use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Screen;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use WC_Product_Simple;
use WPDieException;

/**
 * One screen answers "which live products would my rules refuse today, and why", from the
 * stored flags and with no score or grade (D14).
 *
 * @since 1.2.0
 */
final class Catalog_Page_Test extends Publish_Guard_Test_Case {

	/**
	 * The admin URL the list table reads to build its links.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const REQUEST_URI = '/wp-admin/edit.php?post_type=product&page=sit-wcpg-catalog';

	/**
	 * The request URI before the test replaced it.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private string $request_uri = '';

	/**
	 * On the catalog screen, with a current index and an empty queue.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$this->request_uri      = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.
		$_SERVER['REQUEST_URI'] = self::REQUEST_URI;

		as_unschedule_all_actions( '', array(), Readiness_Rebuild::GROUP );
		set_current_screen( Screen::CATALOG_HOOK );
		$this->set_index( array() );
	}

	/**
	 * Leave no screen, request or menu behind.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['current_screen'], $GLOBALS['submenu'] );

		// WordPress reads it again at shutdown, so it is put back rather than removed.
		$_SERVER['REQUEST_URI'] = $this->request_uri;

		delete_option( Readiness_Rebuild::OPTION );

		parent::tear_down();
	}

	/**
	 * Store the index option, current unless told otherwise.
	 *
	 * @since 1.2.0
	 *
	 * @param array $values Fields to set.
	 * @return void
	 */
	private function set_index( array $values ): void {
		update_option(
			Readiness_Rebuild::OPTION,
			array_merge(
				array(
					'version'    => SIT_WCPG_VERSION,
					'signature'  => $this->signature(),
					'state'      => 'complete',
					'total'      => 40,
					'done'       => 40,
					'started_at' => time(),
				),
				$values
			),
			false
		);
	}

	/**
	 * The current signature.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function signature(): string {
		return Readiness_Store::signature( Plugin::instance()->settings() );
	}

	/**
	 * Index a product under the current signature.
	 *
	 * @since 1.2.0
	 *
	 * @param int $id Product id.
	 * @return int The same id.
	 */
	private function indexed( int $id ): int {
		Plugin::instance()->readiness_indexer()->index( $id, $this->signature() );

		return $id;
	}

	/**
	 * A live product with a stored failure, without the cost of a real one.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $rule_ids  Failing rule ids.
	 * @param string $signature The signature to store; defaults to the current one.
	 * @param string $name      Product name.
	 * @return int Product id.
	 */
	private function flagged_product( array $rule_ids = array( 'price' ), string $signature = '', string $name = 'A live product' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );

		$id = $this->save_unguarded( $product );

		update_post_meta( $id, Readiness_Store::META_STATUS, 'fail' );
		update_post_meta( $id, Readiness_Store::META_SIGNATURE, '' === $signature ? $this->signature() : $signature );
		update_post_meta(
			$id,
			Readiness_Store::META_DATA,
			array(
				'required_failures' => $rule_ids,
				'checked_at'        => time() - HOUR_IN_SECONDS,
			)
		);

		return $id;
	}

	/**
	 * Store a current failure on an existing product.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $id       Product id.
	 * @param array $rule_ids Failing rule ids.
	 * @return int The same id.
	 */
	private function flag( int $id, array $rule_ids = array( 'price' ) ): int {
		update_post_meta( $id, Readiness_Store::META_STATUS, 'fail' );
		update_post_meta( $id, Readiness_Store::META_SIGNATURE, $this->signature() );
		update_post_meta(
			$id,
			Readiness_Store::META_DATA,
			array(
				'required_failures' => $rule_ids,
				'checked_at'        => time() - HOUR_IN_SECONDS,
			)
		);

		return $id;
	}

	/**
	 * The rendered page from the end of the shell on (the shell is tested in Admin_Shell_Test).
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function content(): string {
		$html = $this->page();
		$end  = strpos( $html, 'wp-header-end' );

		return false === $end ? $html : substr( $html, $end );
	}

	/**
	 * The rendered page.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function page(): string {
		ob_start();

		try {
			( new Catalog_Page( Plugin::instance()->settings() ) )->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * A list table, prepared.
	 *
	 * @since 1.2.0
	 *
	 * @return Catalog_List_Table
	 */
	private function table(): Catalog_List_Table {
		$table = new Catalog_List_Table( $this->signature(), array( 'price' => 'Regular price' ) );

		$table->prepare_items();

		return $table;
	}

	/**
	 * The screen is under Products, for whoever can edit products.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_submenu_registered_under_products_for_edit_products(): void {
		global $submenu;

		( new Catalog_Page( Plugin::instance()->settings() ) )->add_menu();

		$entries = array_column( $submenu['edit.php?post_type=product'], 1, 2 );

		$this->assertSame( 'edit_products', $entries[ Catalog_Page::MENU_SLUG ] );
	}

	/**
	 * The hook suffix names the screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_load_hook_is_registered_on_the_catalog_screen(): void {
		$page = new Catalog_Page( Plugin::instance()->settings() );

		$page->register();

		$this->assertNotFalse( has_action( 'load-product_page_sit-wcpg-catalog', array( $page, 'on_load' ) ) );
	}

	/**
	 * Opening the screen on stale flags starts a rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_on_load_ensures_a_current_index(): void {
		$this->set_index( array( 'signature' => 'stale' ) );

		( new Catalog_Page( Plugin::instance()->settings() ) )->on_load();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Readiness_Rebuild::HOOK,
				'group'  => Readiness_Rebuild::GROUP,
				'status' => 'pending',
			),
			'ids'
		);

		$this->assertCount( 1, $actions );
	}

	/**
	 * Only products that are live and failing are listed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_lists_only_published_failing_products(): void {
		$listed = $this->indexed( $this->failing_product( 'publish' ) );

		$this->indexed( $this->spotless_product( 'publish' ) );
		$this->indexed( $this->failing_product( 'draft' ) );
		$this->indexed( $this->passing_product( 'publish' ) );

		$ids = wp_list_pluck( $this->table()->items, 'ID' );

		$this->assertSame( array( $listed ), array_map( 'intval', $ids ) );
	}

	/**
	 * Failing checks are named by the registry's labels.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_failing_check_labels_are_shown(): void {
		$this->indexed( $this->failing_product( 'publish' ) );

		$this->assertStringContainsString( 'Regular price', $this->page() );
	}

	/**
	 * A rule that has since gone away still reads sensibly.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unknown_rule_id_shows_fallback_label(): void {
		$this->flagged_product( array( 'price', 'gone_rule' ) );

		$html = $this->page();

		$this->assertStringContainsString( '<li class="sit-wcpg-ui-pill sit-wcpg-ui-pill--required">A check that is no longer active</li>', $html );
		$this->assertStringNotContainsString( 'gone_rule', $html );
	}

	/**
	 * A row checked under other settings says so, and a current one does not.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_stale_row_is_marked(): void {
		$this->flagged_product( array( 'price' ), 'an-older-signature' );

		$this->assertStringContainsString( ' (not rechecked yet)', $this->page() );
	}

	/**
	 * A current row is not marked.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_current_row_is_not_marked_and_reads_as_a_time(): void {
		$this->flagged_product();

		$html = $this->page();

		$this->assertStringNotContainsString( 'not rechecked yet', $html );
		$this->assertStringContainsString( '1 hour ago', $html );
	}

	/**
	 * A hostile title is printed as text.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_title_is_escaped(): void {
		$this->flagged_product( array( 'price' ), '', '<img src=x onerror=alert(1)>' );

		$html = $this->page();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
	}

	/**
	 * The title links to the checklist in the editor.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_title_links_to_the_checklist(): void {
		$id = $this->flagged_product();

		$this->assertStringContainsString(
			esc_url( get_edit_post_link( $id, 'raw' ) . '#' . Editor_Meta_Box::ID ),
			$this->page()
		);
	}

	/**
	 * Someone who cannot edit a product gets its name without a link.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_edit_link_without_edit_post(): void {
		$id   = $this->flagged_product();
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user->ID );

		$this->assertFalse( current_user_can( 'edit_post', $id ) );

		$html = $this->page();

		$this->assertStringContainsString( 'A live product', $html );
		$this->assertStringNotContainsString( 'post=' . $id . '&amp;action=edit', $html );
	}

	/**
	 * An empty list while rechecking says the list may still grow.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_empty_state_copy_while_rechecking(): void {
		$this->set_index( array( 'state' => 'building' ) );

		$html = $this->page();

		$this->assertStringContainsString( '<h2 class="sit-wcpg-ui-empty__title">No failing live products found yet</h2>', $html );
		$this->assertStringContainsString( 'Rechecking is still running, so this list may grow.', $html );
		$this->assertStringNotContainsString( 'Every live product passes', $html );
	}

	/**
	 * While rebuilding, the page says how far along it is.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rechecking_banner_while_building(): void {
		$this->set_index(
			array(
				'state' => 'building',
				'done'  => 10,
				'total' => 40,
			)
		);

		$this->assertStringContainsString(
			'Rechecking products against your current settings: 10 of 40 done. This list will be complete when it finishes.',
			$this->page()
		);
	}

	/**
	 * With the checklist off the page explains, and lists nothing it cannot vouch for.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_disabled_checklist_message(): void {
		$this->flagged_product();

		$settings = Plugin::instance()->settings();
		$stored   = $settings->get_defaults();

		$stored['enabled'] = false;
		update_option( Settings::OPTION_NAME, $stored );
		$settings->refresh();

		$html = $this->page();

		$this->assertStringContainsString( esc_html( 'The product checklist is switched off, so nothing is being checked. Turn it on under WooCommerce → Product Checklist.' ), $html );
		$this->assertStringNotContainsString( 'A live product', $html );
	}

	/**
	 * Someone without the capability is stopped at the door.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_render_page_dies_for_unprivileged_user(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );

		$this->page();
	}

	/**
	 * Rendering reads flags and validates nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_validation_runs_on_render(): void {
		$this->flagged_product();

		$validations = 0;

		add_filter(
			'sit_wcpg_validation_result',
			static function ( $result ) use ( &$validations ) {
				++$validations;

				return $result;
			}
		);

		$this->page();

		$this->assertSame( 0, $validations );
	}

	/**
	 * Twenty a page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_pagination_at_20(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->flagged_product();
		}

		$table = $this->table();

		$this->assertCount( Catalog_Page::PER_PAGE, $table->items );
		$this->assertSame( 25, $table->get_pagination_arg( 'total_items' ) );
	}

	/**
	 * The screen reports consequences, and never a score (roadmap "Gate-driven catalog view").
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_score_or_grade_anywhere(): void {
		$this->flagged_product();

		$text = wp_strip_all_tags( $this->page() );

		$this->assertStringNotContainsStringIgnoringCase( 'score', $text );

		// A word match: the shell's "Upgrade to Pro" button (UI decision U13) is not a grade.
		$this->assertDoesNotMatchRegularExpression( '/\bgrade/i', $text );
		$this->assertStringNotContainsString( '%', $text );
	}

	/**
	 * The page bar names the consequence and links to the products list (U16: no export,
	 * no "Re-check all").
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_page_bar_title_and_intro(): void {
		$html = $this->content();

		$this->assertStringContainsString( '<h1 class="sit-wcpg-ui-pagebar__title">Live products that would fail today</h1>', $html );
		$this->assertStringContainsString( 'These products are published. Under your required checks, each would be held as a draft if it were published today.', $html );
		$this->assertStringContainsString(
			'<a class="sit-wcpg-ui-button sit-wcpg-ui-button--secondary" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">Open the products list</a>',
			$html
		);
		$this->assertStringNotContainsStringIgnoringCase( 'export', wp_strip_all_tags( $html ) );
		$this->assertStringNotContainsStringIgnoringCase( 're-check all', wp_strip_all_tags( $html ) );
	}

	/**
	 * Listed products come with the reassurance banner; a running recheck adds the info banner.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_success_and_progress_banners(): void {
		$this->flagged_product();

		$html = $this->content();

		$this->assertMatchesRegularExpression( '#<div class="sit-wcpg-ui-banner sit-wcpg-ui-banner--success">.*They stay live — Publish Guard never takes a product offline\.#s', $html );
		$this->assertStringNotContainsString( 'sit-wcpg-ui-banner--info', $html );

		$this->set_index(
			array(
				'state' => 'building',
				'done'  => 10,
				'total' => 40,
			)
		);

		$html = $this->content();

		$this->assertStringContainsString( 'sit-wcpg-ui-banner--success', $html );
		$this->assertMatchesRegularExpression(
			'#<div class="sit-wcpg-ui-banner sit-wcpg-ui-banner--info sit-wcpg-catalog__progress">.*Rechecking products against your current settings: 10 of 40 done\. This list will be complete when it finishes\.#s',
			$html
		);
	}

	/**
	 * Without products to reassure about, there is no reassurance banner.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_success_banner_on_an_empty_list(): void {
		$this->assertStringNotContainsString( 'sit-wcpg-ui-banner--success', $this->content() );
	}

	/**
	 * The bar shows the same numbers as the sentence, and is named by it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_progress_bar_values_match_rebuild_status(): void {
		$this->set_index(
			array(
				'state' => 'building',
				'done'  => 10,
				'total' => 40,
			)
		);

		$html = $this->content();

		$this->assertStringContainsString( '<p id="sit-wcpg-catalog-progress">', $html );
		$this->assertStringContainsString(
			'role="progressbar" aria-labelledby="sit-wcpg-catalog-progress" aria-valuemin="0" aria-valuemax="40" aria-valuenow="10"',
			$html
		);
		$this->assertStringContainsString( '<span class="sit-wcpg-ui-bar__fill" style="width: 25%"></span>', $html );
	}

	/**
	 * A finished index draws no bar.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_no_progress_bar_when_current(): void {
		$this->flagged_product();

		$this->assertStringNotContainsString( 'role="progressbar"', $this->content() );
	}

	/**
	 * A product with an image shows it at 44 px; one without shows its initials, hidden from
	 * assistive technology because the name is already in the cell.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_row_shows_thumbnail_or_initials_placeholder(): void {
		$with_image = $this->flagged_product( array( 'price' ), '', 'Pictured product' );

		$image = $this->image();

		// A real upload has dimensions; WordPress scales the 44 px request from them.
		wp_update_attachment_metadata(
			$image,
			array(
				'width'  => 600,
				'height' => 600,
				'file'   => 'sit-wcpg-test.jpg',
			)
		);
		set_post_thumbnail( $with_image, $image );

		$this->flagged_product( array( 'price' ), '', 'Linen Hoodie — Oat' );

		$html = $this->content();

		$this->assertMatchesRegularExpression( '#<span class="sit-wcpg-catalog__thumb"><img[^>]*width="44"[^>]*height="44"[^>]*class="[^"]*sit-wcpg-catalog__thumb-image#', $html );
		$this->assertStringContainsString( '<span class="sit-wcpg-catalog__thumb sit-wcpg-catalog__thumb--initials" aria-hidden="true">LH</span>', $html );
	}

	/**
	 * Initials skip dashes and symbols and stop at two.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_initials(): void {
		$this->assertSame( 'LH', Catalog_List_Table::initials( 'Linen Hoodie — Oat' ) );
		$this->assertSame( 'C', Catalog_List_Table::initials( 'café' ) );
		$this->assertSame( 'ÉT', Catalog_List_Table::initials( 'été & thé' ) );
		$this->assertSame( '', Catalog_List_Table::initials( '— —' ) );
	}

	/**
	 * A variable product says so and counts its variations; others show the SKU, else the type.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_variable_product_line_says_variable_and_variation_count(): void {
		$this->flag(
			$this->variable_product(
				array(
					array( 'regular_price' => '10' ),
					array( 'regular_price' => '12' ),
					array(
						'regular_price' => '14',
						'status'        => 'private',
					),
				),
				'publish'
			)
		);
		$this->flag( $this->passing_product( 'publish', array( 'sku' => 'LH-OAT-M' ) ) );
		$this->flagged_product();

		$html = $this->content();

		$this->assertStringContainsString( '<span class="sit-wcpg-catalog__product-meta">Variable · 3 variations</span>', $html );
		$this->assertStringContainsString( '<span class="sit-wcpg-catalog__product-meta">SKU LH-OAT-M</span>', $html );
		$this->assertStringContainsString( '<span class="sit-wcpg-catalog__product-meta">Simple</span>', $html );
	}

	/**
	 * Each failing check is a required pill named by the registry's label.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_failing_checks_render_as_required_pills_with_rule_labels(): void {
		$this->flagged_product( array( 'price', 'sku' ) );

		$labels = array();

		foreach ( array( 'price', 'sku' ) as $rule_id ) {
			$labels[] = Plugin::instance()->registry()->get( $rule_id )->get_label();
		}

		$this->assertStringContainsString(
			'<ul class="sit-wcpg-catalog__checks"><li class="sit-wcpg-ui-pill sit-wcpg-ui-pill--required">' . esc_html( $labels[0] ) . '</li><li class="sit-wcpg-ui-pill sit-wcpg-ui-pill--required">' . esc_html( $labels[1] ) . '</li></ul>',
			$this->content()
		);
	}

	/**
	 * "Edit product" opens the editor, and names the product for screen readers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_edit_product_action(): void {
		$id = $this->flagged_product();

		$this->assertStringContainsString(
			'<a class="sit-wcpg-catalog__edit" href="' . esc_url( get_edit_post_link( $id, 'raw' ) ) . '">Edit product<span class="screen-reader-text">: A live product</span></a>',
			$this->content()
		);
	}

	/**
	 * The empty state says nothing fails, and links to the checklist only for whoever can
	 * change it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_empty_state_copy_and_link_capability(): void {
		$html = $this->content();
		$link = esc_url( admin_url( 'admin.php?page=' . Settings_Page::MENU_SLUG ) );

		$this->assertStringContainsString( '<h2 class="sit-wcpg-ui-empty__title">Every live product passes your required checks</h2>', $html );
		$this->assertStringContainsString( esc_html( "We'll list a product here if a rule you tighten later would stop it being published." ), $html );
		$this->assertStringContainsString( '<a class="sit-wcpg-catalog__empty-link" href="' . $link . '">Review your checklist', $html );
		$this->assertStringNotContainsString( '<table', $html );

		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user->ID );

		$this->assertFalse( current_user_can( Settings_Page::CAPABILITY ) );

		$html = $this->content();

		$this->assertStringContainsString( 'Every live product passes your required checks', $html );
		$this->assertStringNotContainsString( 'Review your checklist', $html );
	}

	/**
	 * Twenty a page still, newest change first, and sortable by name with an allow-listed order.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_list_table_still_paginates_and_sorts(): void {
		foreach ( array( 'Bravo', 'Alpha', 'Charlie' ) as $name ) {
			$this->flagged_product( array( 'price' ), '', $name );
		}

		for ( $i = 0; $i < 22; $i++ ) {
			$this->flagged_product( array( 'price' ), '', 'Zulu ' . $i );
		}

		$table = $this->table();

		$this->assertCount( Catalog_Page::PER_PAGE, $table->items );
		$this->assertSame( 25, $table->get_pagination_arg( 'total_items' ) );

		$_GET['orderby'] = 'title';
		$_GET['order']   = 'asc';

		try {
			$titles = wp_list_pluck( $this->table()->items, 'post_title' );

			$this->assertSame( array( 'Alpha', 'Bravo', 'Charlie' ), array_slice( $titles, 0, 3 ) );

			$_GET['order'] = 'desc';

			$this->assertStringStartsWith( 'Zulu', wp_list_pluck( $this->table()->items, 'post_title' )[0] );

			// Anything off the allow-list falls back to the default order.
			$_GET['orderby'] = 'post_title; DROP';
			$_GET['order']   = 'sideways';

			$this->assertCount( Catalog_Page::PER_PAGE, $this->table()->items );
			$this->assertStringContainsString( 'sortable', $this->content() );
		} finally {
			unset( $_GET['orderby'], $_GET['order'] );
		}
	}
}
