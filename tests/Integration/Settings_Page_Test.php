<?php
/**
 * The Product Checklist screen's layout and controls (UI phase U06).
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ProductPublishGuard\Admin\Admin_Shell;
use ProductPublishGuard\Engine\Rule_Interface;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Readiness_Indexer;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use ProductPublishGuard\Tests\Stubs\Managed_Fake_Rule;
use WP_UnitTestCase;

/**
 * One card per rule group with counts, rows with a switch and a segmented severity
 * control, thresholds inside their rule's row, the section nav and the save bars, all
 * posting the same fields as before (UI decisions U8, U15).
 *
 * @since 1.2.0
 */
final class Settings_Page_Test extends WP_UnitTestCase {

	/**
	 * Start from a site that has never saved the form.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_NAME );
		delete_option( Readiness_Rebuild::OPTION );
	}

	/**
	 * The shipped rule catalogue.
	 *
	 * @since 1.2.0
	 *
	 * @return Rule_Registry
	 */
	private function registry(): Rule_Registry {
		$registry = new Rule_Registry();

		Rules_Provider::populate( $registry );

		return $registry;
	}

	/**
	 * The screen as an administrator sees it.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Registry|null $registry Rules to list. Null uses the shipped ones.
	 * @return string
	 */
	private function render( ?Rule_Registry $registry = null ): string {
		$registry = $registry ?? $this->registry();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = new Settings( $registry );
		$rebuild  = new Readiness_Rebuild( $settings, new Readiness_Indexer( $settings, new Readiness_Store() ) );

		ob_start();
		( new Settings_Page( $settings, $registry, $rebuild ) )->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * An XPath over the rendered screen.
	 *
	 * @since 1.2.0
	 *
	 * @param string $html Rendered screen.
	 * @return DOMXPath
	 */
	private function xpath( string $html ): DOMXPath {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$document->loadHTML( '<?xml encoding="utf-8"?>' . $html );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $document );
	}

	/**
	 * XPath test for an element carrying a class.
	 *
	 * @since 1.2.0
	 *
	 * @param string $css_class Class name.
	 * @return string
	 */
	private static function has_class( string $css_class ): string {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $css_class . ' ")';
	}

	/**
	 * Normalised text of a node.
	 *
	 * @since 1.2.0
	 *
	 * @param \DOMNode $node The node.
	 * @return string
	 */
	private static function text( \DOMNode $node ): string {
		// C14N() rather than the camel-case text property, which the naming sniff rejects.
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $node->C14N() ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Each group is a card with its heading, its description and its counts.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_each_group_is_a_card_with_counts(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'rules' => array(
					'title'             => array(
						'enabled'  => true,
						'severity' => Severity::REQUIRED,
					),
					'description'       => array(
						'enabled'  => true,
						'severity' => Severity::REQUIRED,
					),
					'short_description' => array(
						'enabled'  => false,
						'severity' => Severity::WARNING,
					),
				),
			)
		);

		$xpath = $this->xpath( $this->render() );
		$cards = $xpath->query( '//section[' . self::has_class( 'sit-wcpg-ui-card' ) . ' and ' . self::has_class( 'sit-wcpg-settings__group' ) . ']' );

		$headings = array();

		foreach ( $cards as $card ) {
			$headings[] = self::text( $xpath->query( './/h2', $card )->item( 0 ) );
		}

		$this->assertSame( array( 'Content', 'Media', 'Pricing', 'Organization', 'Inventory' ), $headings );

		$content = $cards->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $content );
		$this->assertSame( 'sit-wcpg-section-content', $content->getAttribute( 'id' ) );
		$this->assertStringContainsString( 'What shoppers and search engines read first.', self::text( $content ) );

		$counts = $xpath->query( './/*[' . self::has_class( 'sit-wcpg-settings__counts' ) . ']', $content )->item( 0 );

		$this->assertNotNull( $counts );
		$this->assertSame( '2 required 1 off', self::text( $counts ) );
		$this->assertSame( 1, $xpath->query( './/*[' . self::has_class( 'sit-wcpg-ui-pill--required' ) . ']', $counts )->length );

		$media = self::text( $xpath->query( './/*[' . self::has_class( 'sit-wcpg-settings__counts' ) . ']', $cards->item( 1 ) )->item( 0 ) );

		$this->assertSame( '1 required 1 warning', $media );
		$this->assertStringContainsString( 'Channels disapprove products without images.', self::text( $cards->item( 1 ) ) );
	}

	/**
	 * Every rule row carries a labelled switch and the two severity radios as a segmented
	 * control, with the same names and values as before.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rule_row_has_switch_with_role_and_label_and_segmented_radios(): void {
		$xpath = $this->xpath( $this->render() );
		$rows  = $xpath->query( '//*[' . self::has_class( 'sit-wcpg-settings__row' ) . ' and @data-rule]' );

		$this->assertSame( 12, $rows->length );

		foreach ( $rows as $row ) {
			$rule_id = $row->getAttribute( 'data-rule' );
			$switch  = $xpath->query( './/input[@type="checkbox"]', $row );

			$this->assertSame( 1, $switch->length, $rule_id );
			$this->assertSame( 'switch', $switch->item( 0 )->getAttribute( 'role' ) );
			$this->assertSame( Settings::OPTION_NAME . '[rules][' . $rule_id . '][enabled]', $switch->item( 0 )->getAttribute( 'name' ) );
			$this->assertSame( '1', $switch->item( 0 )->getAttribute( 'value' ) );

			$label = $xpath->query( '//label[@for="' . $switch->item( 0 )->getAttribute( 'id' ) . '"]' );
			$title = self::text( $xpath->query( './/h3', $row )->item( 0 ) );

			$this->assertSame( 1, $label->length, $rule_id );
			$this->assertSame( 'Run the ' . $title . ' check', self::text( $label->item( 0 ) ) );

			$fieldset = $xpath->query( './/fieldset[' . self::has_class( 'sit-wcpg-ui-segmented' ) . ']', $row );

			$this->assertSame( 1, $fieldset->length, $rule_id );
			$this->assertSame( 'Severity of the ' . $title . ' check', self::text( $xpath->query( './legend', $fieldset->item( 0 ) )->item( 0 ) ) );

			$radios = $xpath->query( './/input[@type="radio"]', $fieldset->item( 0 ) );
			$values = array();

			foreach ( $radios as $radio ) {
				$values[] = $radio->getAttribute( 'value' );

				$this->assertSame( Settings::OPTION_NAME . '[rules][' . $rule_id . '][severity]', $radio->getAttribute( 'name' ) );
				$this->assertStringContainsString( 'sit-wcpg-ui-segmented__input--' . $radio->getAttribute( 'value' ), $radio->getAttribute( 'class' ) );
				$this->assertSame( 1, $xpath->query( './label[@for="' . $radio->getAttribute( 'id' ) . '"]', $fieldset->item( 0 ) )->length );
			}

			$this->assertSame( Severity::all(), $values );
			$this->assertSame( 1, $xpath->query( './/input[@type="radio" and @checked]', $fieldset->item( 0 ) )->length );
			$this->assertSame( 1, $xpath->query( './/svg', $row )->length, 'Each row has its icon.' );
		}
	}

	/**
	 * Each threshold sits in its rule's row, not in a section of its own.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_threshold_renders_inside_its_rule_row(): void {
		$html  = $this->render();
		$xpath = $this->xpath( $html );

		foreach ( Settings_Page::THRESHOLD_RULES as $threshold => $rule_id ) {
			$input = $xpath->query( '//input[@name="' . Settings::OPTION_NAME . '[thresholds][' . $threshold . ']"]' );

			$this->assertSame( 1, $input->length, $threshold );
			$this->assertSame( 'number', $input->item( 0 )->getAttribute( 'type' ) );

			$row = $xpath->query( 'ancestor::*[@data-rule][1]', $input->item( 0 ) )->item( 0 );

			$this->assertInstanceOf( DOMElement::class, $row, $threshold );
			$this->assertSame( $rule_id, $row->getAttribute( 'data-rule' ) );
		}

		$this->assertStringNotContainsString( 'Content thresholds', $html );
		$this->assertStringNotContainsString( 'id="sit-wcpg-section-thresholds"', $html );
	}

	/**
	 * A threshold whose rule is not registered still renders, in a card at the end.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_orphan_threshold_renders_in_thresholds_card(): void {
		$registry = new Rule_Registry();

		foreach ( $this->registry()->all() as $rule ) {
			if ( 'image_count' !== $rule->get_id() ) {
				$registry->register( $rule );
			}
		}

		$xpath = $this->xpath( $this->render( $registry ) );
		$card  = $xpath->query( '//section[@id="sit-wcpg-section-thresholds"]' );

		$this->assertSame( 1, $card->length );
		$this->assertSame( 'Thresholds', self::text( $xpath->query( './/h2', $card->item( 0 ) )->item( 0 ) ) );
		$this->assertSame( 1, $xpath->query( './/input[@name="' . Settings::OPTION_NAME . '[thresholds][min_images]"]', $card->item( 0 ) )->length );
		$this->assertSame( 0, $xpath->query( './/input[@name="' . Settings::OPTION_NAME . '[thresholds][min_description_chars]"]', $card->item( 0 ) )->length );

		$sections = $xpath->query( '//*[' . self::has_class( 'sit-wcpg-settings__sections' ) . ']/section' );
		$ids      = array();

		foreach ( $sections as $section ) {
			$ids[] = $section->getAttribute( 'id' );
		}

		$this->assertSame( 'sit-wcpg-section-thresholds', $ids[ array_search( 'sit-wcpg-section-inventory', $ids, true ) + 1 ], 'After the rule groups.' );
	}

	/**
	 * A managed rule is configured by its add-on and gets no row.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_managed_rules_still_not_listed(): void {
		$registry = $this->registry();

		$registry->register( new Managed_Fake_Rule( 'probe_managed', true, Severity::WARNING, Severity::REQUIRED, 'Probe managed' ) );

		$html = $this->render( $registry );

		$this->assertStringNotContainsString( 'Probe managed', $html );
		$this->assertStringNotContainsString( 'probe_managed', $html );
		$this->assertStringContainsString( 'data-rule="sku"', $html );
	}

	/**
	 * The nav links each group, Publishing and Readiness, and every link has its target.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_section_nav_lists_groups_publishing_and_readiness_with_anchor_targets(): void {
		$xpath = $this->xpath( $this->render() );
		$nav   = $xpath->query( '//nav[' . self::has_class( 'sit-wcpg-settings__nav' ) . ']' );

		$this->assertSame( 1, $nav->length );
		$this->assertNotSame( '', $nav->item( 0 )->getAttribute( 'aria-label' ) );

		$labels = array();

		foreach ( $xpath->query( './/a[' . self::has_class( 'sit-wcpg-ui-nav__item' ) . ']', $nav->item( 0 ) ) as $link ) {
			$href = $link->getAttribute( 'href' );

			$this->assertStringStartsWith( '#' . Settings_Page::SECTION_ID_PREFIX, $href );
			$this->assertSame( 1, $xpath->query( '//section[@id="' . substr( $href, 1 ) . '"]' )->length, $href . ' has a target.' );

			$labels[ substr( $href, 1 + strlen( Settings_Page::SECTION_ID_PREFIX ) ) ] = $link;
		}

		$this->assertSame( array( 'content', 'media', 'pricing', 'organization', 'inventory', 'publishing', 'readiness' ), array_keys( $labels ) );

		$count = $xpath->query( './/*[' . self::has_class( 'sit-wcpg-ui-nav__count' ) . ']', $labels['content'] )->item( 0 );

		$this->assertNotNull( $count );
		$this->assertSame( '3', self::text( $count ) );
		$this->assertStringStartsWith( 'Content', self::text( $labels['content'] ) );

		// The box sits below the links while the prompts are on.
		$box = $xpath->query( './/*[' . self::has_class( 'sit-wcpg-settings__pro' ) . ']', $nav->item( 0 ) );

		$this->assertSame( 1, $box->length );
		$this->assertStringContainsString( 'Need channel rules?', self::text( $box->item( 0 ) ) );

		$link = $xpath->query( './/a', $box->item( 0 ) )->item( 0 );

		$this->assertSame( Admin_Shell::PRO_URL, $link->getAttribute( 'href' ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
		$this->assertSame( 'noopener', $link->getAttribute( 'rel' ) );
	}

	/**
	 * No Readiness link while the checklist is off, as the section is not printed.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_section_nav_has_no_readiness_link_while_checklist_off(): void {
		update_option( Settings::OPTION_NAME, array( 'enabled' => false ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '#' . Settings_Page::SECTION_ID_PREFIX . 'readiness', $html );
		$this->assertStringContainsString( '#' . Settings_Page::SECTION_ID_PREFIX . 'publishing', $html );
	}

	/**
	 * With the prompts off the box is gone and nothing on the page links to the sales page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_need_channel_rules_box_hidden_when_prompts_false(): void {
		add_filter( Admin_Shell::PROMPTS_FILTER, '__return_false' );

		$html = $this->render();

		remove_filter( Admin_Shell::PROMPTS_FILTER, '__return_false' );

		$this->assertStringNotContainsString( 'sit-wcpg-settings__pro', $html );
		$this->assertStringNotContainsString( 'Need channel rules?', $html );
		$this->assertStringNotContainsString( Admin_Shell::PRO_URL, $html );
	}

	/**
	 * Every control has an accessible name: a `<label for>`, an `aria-label`, an
	 * `aria-labelledby`, or (for a radio) its own label inside a fieldset with a legend.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_every_control_has_an_accessible_name(): void {
		$xpath    = $this->xpath( $this->render() );
		$controls = $xpath->query( '//form//input[not(@type="hidden")] | //form//select | //form//textarea | //form//button' );

		$this->assertGreaterThan( 30, $controls->length );

		foreach ( $controls as $control ) {
			$name = $control->getAttribute( 'name' );
			$type = $control->getAttribute( 'type' );

			if ( 'submit' === $type ) {
				$this->assertNotSame( '', trim( $control->getAttribute( 'value' ) ), $name );
				continue;
			}

			// A <button> (Discard, U07) is named by its own text.
			if ( 1 === $xpath->query( 'self::button', $control )->length ) {
				$this->assertNotSame( '', self::text( $control ), 'A button has text.' );
				continue;
			}

			if ( '' !== $control->getAttribute( 'aria-label' ) ) {
				continue;
			}

			if ( '' !== $control->getAttribute( 'aria-labelledby' ) ) {
				foreach ( explode( ' ', $control->getAttribute( 'aria-labelledby' ) ) as $id ) {
					$this->assertSame( 1, $xpath->query( '//*[@id="' . $id . '"]' )->length, $name );
				}

				continue;
			}

			$id = $control->getAttribute( 'id' );

			$this->assertNotSame( '', $id, $name . ' has an id.' );
			$this->assertSame( 1, $xpath->query( '//*[@id="' . $id . '"]' )->length, $id . ' is unique.' );

			$label = $xpath->query( '//label[@for="' . $id . '"]' );

			$this->assertSame( 1, $label->length, $name . ' has a label.' );
			$this->assertNotSame( '', self::text( $label->item( 0 ) ), $name . ' has a non-empty label.' );

			if ( 'radio' === $type ) {
				$legend = $xpath->query( 'ancestor::fieldset[1]/legend', $control );

				$this->assertSame( 1, $legend->length, $name . ' sits in a fieldset with a legend.' );
				$this->assertNotSame( '', self::text( $legend->item( 0 ) ) );
			}

			if ( 'checkbox' === $type ) {
				$this->assertSame( 'switch', $control->getAttribute( 'role' ), $name . ' is a switch.' );
			}
		}
	}

	/**
	 * The page bar carries the title, the Required/Warning sentence, the master switch and
	 * Save; the bottom bar repeats Save. Both submit the one form.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_page_bar_and_bottom_bar(): void {
		$xpath = $this->xpath( $this->render() );
		$bar   = $xpath->query( '//form//*[' . self::has_class( 'sit-wcpg-ui-pagebar' ) . ']' );

		$this->assertSame( 1, $bar->length );
		$this->assertSame( 'Product checklist', self::text( $xpath->query( './/h1', $bar->item( 0 ) )->item( 0 ) ) );
		$this->assertStringContainsString( 'A Required check must pass before a product can be published. A Warning is shown in the editor but never blocks publishing.', self::text( $bar->item( 0 ) ) );

		$master = $xpath->query( './/input[@name="' . Settings::OPTION_NAME . '[enabled]"]', $bar->item( 0 ) );

		$this->assertSame( 1, $master->length );
		$this->assertSame( 'switch', $master->item( 0 )->getAttribute( 'role' ) );
		$this->assertSame( 1, $xpath->query( './/input[@type="submit"]', $bar->item( 0 ) )->length );

		$bottom = $xpath->query( '//form//*[' . self::has_class( 'sit-wcpg-ui-savebar--bottom' ) . ']//input[@type="submit" and @name="submit"]' );

		$this->assertSame( 1, $bottom->length );
		$this->assertSame( 2, $xpath->query( '//form//input[@type="submit"]' )->length );
	}

	/**
	 * The Publishing card holds the enforcement switches, the scope as a segmented control
	 * and the products-list switch.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_publishing_card(): void {
		$xpath = $this->xpath( $this->render() );
		$card  = $xpath->query( '//section[@id="sit-wcpg-section-publishing"]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $card );

		foreach ( array( 'publishing][block_on_required_failure', 'publishing][allow_admin_override', 'product_list][show_column' ) as $key ) {
			$input = $xpath->query( './/input[@name="' . Settings::OPTION_NAME . '[' . $key . ']"]', $card );

			$this->assertSame( 1, $input->length, $key );
			$this->assertSame( 'switch', $input->item( 0 )->getAttribute( 'role' ) );
		}

		$scope = $xpath->query( './/fieldset[' . self::has_class( 'sit-wcpg-ui-segmented' ) . ']//input[@name="' . Settings::OPTION_NAME . '[publishing][enforce_scope]"]', $card );

		$this->assertSame( count( Settings::scopes() ), $scope->length );
	}

	/**
	 * The readiness status line sits in an info banner.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_readiness_banner(): void {
		$xpath  = $this->xpath( $this->render() );
		$banner = $xpath->query( '//section[@id="sit-wcpg-section-readiness" and ' . self::has_class( 'sit-wcpg-ui-banner--info' ) . ']' );

		$this->assertSame( 1, $banner->length );
		$this->assertSame( 'Readiness filter', self::text( $xpath->query( './/h2', $banner->item( 0 ) )->item( 0 ) ) );
	}

	/**
	 * Rule text an add-on supplies is escaped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_rule_text_is_escaped(): void {
		$registry = $this->registry();
		$rule     = $this->createMock( Rule_Interface::class );

		$rule->method( 'get_id' )->willReturn( 'acme_xss' );
		$rule->method( 'get_label' )->willReturn( '<script>alert(1)</script>Acme' );
		$rule->method( 'get_description' )->willReturn( '"><img src=x onerror=alert(1)>' );
		$rule->method( 'get_group' )->willReturn( 'content' );
		$rule->method( 'get_priority' )->willReturn( 500 );
		$rule->method( 'get_default_severity' )->willReturn( Severity::WARNING );
		$rule->method( 'is_enabled_by_default' )->willReturn( true );

		$registry->register( $rule );

		$html = $this->render( $registry );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;Acme', $html );
	}
}
