<?php
/**
 * The settings form posts back exactly what it was rendered with.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Settings\Settings_Sanitizer;
use ProductPublishGuard\Support\Readiness_Indexer;
use ProductPublishGuard\Support\Readiness_Rebuild;
use ProductPublishGuard\Support\Readiness_Store;
use WP_UnitTestCase;

/**
 * Guards UI decision U8 across the markup rewrite (UI phase U06): whatever the page looks
 * like, the browser posts the same field names, and a save without edits stores the
 * option it started from.
 *
 * @since 1.2.0
 */
final class Settings_Round_Trip_Test extends WP_UnitTestCase {

	/**
	 * The field-name snapshot taken from the pre-U06 markup.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const FIXTURE = SIT_WCPG_PATH . 'tests/fixtures/settings-field-names.json';

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
	 * @param Rule_Registry $registry Rules to list.
	 * @return string
	 */
	private function render( Rule_Registry $registry ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = new Settings( $registry );
		$rebuild  = new Readiness_Rebuild( $settings, new Readiness_Indexer( $settings, new Readiness_Store() ) );

		ob_start();
		( new Settings_Page( $settings, $registry, $rebuild ) )->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * The form's inputs that post inside the option.
	 *
	 * @since 1.2.0
	 *
	 * @param string $html Rendered screen.
	 * @return DOMElement[]
	 */
	private function controls( string $html ): array {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$document->loadHTML( '<?xml encoding="utf-8"?>' . $html );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath    = new DOMXPath( $document );
		$controls = array();

		// Every field is an `<input>`; a select or textarea needs this helper extended.
		$this->assertSame( 0, $xpath->query( '//form//select | //form//textarea' )->length );

		foreach ( $xpath->query( '//form//input' ) as $node ) {
			if ( $node instanceof DOMElement && 0 === strpos( $node->getAttribute( 'name' ), Settings::OPTION_NAME . '[' ) ) {
				$controls[] = $node;
			}
		}

		return $controls;
	}

	/**
	 * What the browser would submit: `name => value` pairs, checkboxes and radios only when
	 * checked.
	 *
	 * @since 1.2.0
	 *
	 * @param DOMElement[] $controls The form's controls.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function submitted_pairs( array $controls ): array {
		$pairs = array();

		foreach ( $controls as $control ) {
			$type = strtolower( $control->getAttribute( 'type' ) );

			if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $control->hasAttribute( 'checked' ) ) {
				continue;
			}

			$value = $control->hasAttribute( 'value' ) ? $control->getAttribute( 'value' ) : ( 'checkbox' === $type ? 'on' : '' );

			$pairs[] = array( $control->getAttribute( 'name' ), $value );
		}

		return $pairs;
	}

	/**
	 * Build `$_POST[ OPTION_NAME ]` from the pairs, the way PHP parses a form body.
	 *
	 * @since 1.2.0
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs Submitted pairs.
	 * @return array
	 */
	private function post_body( array $pairs ): array {
		$body = implode(
			'&',
			array_map(
				static function ( array $pair ): string {
					return rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
				},
				$pairs
			)
		);

		parse_str( $body, $parsed );

		return (array) ( $parsed[ Settings::OPTION_NAME ] ?? array() );
	}

	/**
	 * Every distinct field name, sorted.
	 *
	 * @since 1.2.0
	 *
	 * @param DOMElement[] $controls The form's controls.
	 * @return string[]
	 */
	private function field_names( array $controls ): array {
		$names = array_values(
			array_unique(
				array_map(
					static function ( DOMElement $control ): string {
						return $control->getAttribute( 'name' );
					},
					$controls
				)
			)
		);

		sort( $names );

		return $names;
	}

	/**
	 * Store a non-default option through the sanitizer, so each control has something to
	 * carry back.
	 *
	 * @since 1.2.0
	 *
	 * @param Rule_Registry $registry Rules to configure.
	 * @return array The stored option.
	 */
	private function store_changed_option( Rule_Registry $registry ): array {
		$raw = array(
			'enabled'      => '1',
			'rules'        => array(),
			'thresholds'   => array(
				'min_description_chars'       => '320',
				'min_short_description_chars' => '0',
				'min_images'                  => '4',
			),
			'publishing'   => array(
				'allow_admin_override' => '1',
				'enforce_scope'        => Settings::SCOPE_EDITOR,
			),
			'product_list' => array(),
		);

		$flip = true;

		foreach ( $registry->all() as $rule ) {
			$raw['rules'][ $rule->get_id() ] = array(
				'severity' => $flip ? Severity::WARNING : Severity::REQUIRED,
			);

			if ( ! $flip ) {
				$raw['rules'][ $rule->get_id() ]['enabled'] = '1';
			}

			$flip = ! $flip;
		}

		$stored = ( new Settings_Sanitizer( new Settings( $registry ) ) )->sanitize( $raw );

		update_option( Settings::OPTION_NAME, $stored );

		return $stored;
	}

	/**
	 * The form posts the same field names it always has.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_field_names_match_the_snapshot(): void {
		$names = $this->field_names( $this->controls( $this->render( $this->registry() ) ) );

		$this->assertFileExists( self::FIXTURE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$this->assertSame( json_decode( (string) file_get_contents( self::FIXTURE ), true ), $names, 'Field names: ' . wp_json_encode( $names ) );
	}

	/**
	 * Saving a changed option without edits stores it unchanged.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unedited_save_stores_the_option_it_started_from(): void {
		$registry = $this->registry();
		$stored   = $this->store_changed_option( $registry );
		$posted   = $this->post_body( $this->submitted_pairs( $this->controls( $this->render( $registry ) ) ) );

		$this->assertSame( $stored, ( new Settings_Sanitizer( new Settings( $registry ) ) )->sanitize( $posted ) );
	}

	/**
	 * The defaults survive an unedited save too, the checklist switched off included.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_unedited_save_of_defaults_and_disabled_checklist(): void {
		$registry  = $this->registry();
		$sanitizer = new Settings_Sanitizer( new Settings( $registry ) );
		$defaults  = ( new Settings( $registry ) )->get_all();

		$posted = $this->post_body( $this->submitted_pairs( $this->controls( $this->render( $registry ) ) ) );

		$this->assertSame( $sanitizer->sanitize( $defaults ), $sanitizer->sanitize( $posted ) );

		$off = array_merge( $defaults, array( 'enabled' => false ) );

		update_option( Settings::OPTION_NAME, $off );

		$posted = $this->post_body( $this->submitted_pairs( $this->controls( $this->render( $registry ) ) ) );

		$this->assertFalse( $sanitizer->sanitize( $posted )['enabled'] );
		$this->assertSame( $sanitizer->sanitize( $off ), $sanitizer->sanitize( $posted ) );
	}
}
