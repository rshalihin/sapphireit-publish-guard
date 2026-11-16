<?php
/**
 * Inline SVG icons for the admin UI.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The one source of the admin UI's icons (UI decision U5): allow-listed names, static
 * markup, `currentColor` strokes, hidden from assistive technology. No icon font, no emoji.
 *
 * The editor panel renders the same set from `assets/js/shared/icons.js`; the unit and
 * Jest suites compare both against `tests/fixtures/icon-names.json`, which
 * `bin/icon-names.php` writes from {@see Icons::definitions()}.
 *
 * @since 1.2.0
 */
final class Icons {

	/**
	 * The coordinate system every icon is drawn in.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const VIEW_BOX = '0 0 24 24';

	/**
	 * CSS class on every icon (`icon` component of U5).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const CSS_CLASS = 'sit-wcpg-ui-icon';

	/**
	 * Smallest rendered size, in pixels.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MIN_SIZE = 12;

	/**
	 * Largest rendered size, in pixels.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const MAX_SIZE = 48;

	/**
	 * Icon name => the `d` attribute of each stroked path.
	 *
	 * @since 1.2.0
	 * @var array<string, string[]>
	 */
	private const PATHS = array(
		'shield'        => array( 'M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3z' ),
		'check'         => array( 'M5 12.5l4.5 4.5L19 7.5' ),
		'cross'         => array( 'M6 6l12 12', 'M18 6L6 18' ),
		'alert'         => array( 'M12 3.5L2.5 20h19L12 3.5z', 'M12 10v4.5', 'M12 17.5v.01' ),
		'info'          => array( 'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18z', 'M12 11v5.5', 'M12 7.5v.01' ),
		'refresh'       => array( 'M20 11a8 8 0 0 0-14.6-4.5L4 8', 'M4 4v4h4', 'M4 13a8 8 0 0 0 14.6 4.5L20 16', 'M20 20v-4h-4' ),
		'search'        => array( 'M11 4a7 7 0 1 0 0 14a7 7 0 1 0 0-14z', 'M20 20l-4-4' ),
		'chevron-down'  => array( 'M6 9l6 6 6-6' ),
		'chevron-right' => array( 'M9 6l6 6-6 6' ),
		'book'          => array( 'M4 19.5v-14A2.5 2.5 0 0 1 6.5 3H20v14H6.5A2.5 2.5 0 0 0 4 19.5z', 'M4 19.5A2.5 2.5 0 0 0 6.5 22H20' ),
		'chat'          => array( 'M4 5h16v11H9l-5 4V5z' ),
		'star'          => array( 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3z' ),
		'crown'         => array( 'M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z' ),
		'bag'           => array( 'M5 8h14l-1 13H6L5 8z', 'M9 8V6a3 3 0 0 1 6 0v2' ),
		'barcode'       => array( 'M4 5v14', 'M7 5v14', 'M10 5v14', 'M14 5v14', 'M17 5v14', 'M20 5v14' ),
		'grid'          => array( 'M4 4h6v6H4z', 'M14 4h6v6h-6z', 'M4 14h6v6H4z', 'M14 14h6v6h-6z' ),
		'sparkle'       => array( 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z', 'M19 16l.7 2.3L22 19l-2.3.7L19 22l-.7-2.3L16 19l2.3-.7L19 16z' ),
		'store'         => array( 'M3 9l1.5-5h15L21 9H3z', 'M5 12v8h14v-8', 'M10 20v-5h4v5' ),
		'fields'        => array( 'M4 5h16v5H4z', 'M4 14h16v5H4z' ),
		'key'           => array( 'M8 11a4 4 0 1 0 0 8a4 4 0 1 0 0-8z', 'M10.8 12.2L20 3', 'M16 7l3 3', 'M18 5l2 2' ),
		'edit'          => array( 'M4 20h4L19 9l-4-4L4 16v4z', 'M13.5 6.5l4 4' ),
		'trash'         => array( 'M4 7h16', 'M9 7V4h6v3', 'M6 7l1 13h10l1-13', 'M10 11v6', 'M14 11v6' ),
		'plus'          => array( 'M12 5v14', 'M5 12h14' ),
		'image'         => array( 'M4 4h16v16H4z', 'M4 16l5-5 4 4 2-2 5 5', 'M15 8.5v.01' ),
		'images'        => array( 'M7 7h13v13H7z', 'M4 17V4h13', 'M7 17l4-4 3 3 2-2 4 4' ),
		'text'          => array( 'M4 6h16', 'M4 10h16', 'M4 14h16', 'M4 18h10' ),
		'title'         => array( 'M5 5h14', 'M12 5v14', 'M9 19h6' ),
		'tag'           => array( 'M3 12V3h9l9 9-9 9-9-9z', 'M7.5 7.5v.01' ),
		'ban'           => array( 'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18z', 'M5.6 5.6l12.8 12.8' ),
		'price'         => array( 'M12 3v18', 'M16.5 7.5A3.5 3 0 0 0 13 5h-2a3 3 0 0 0 0 6h2a3 3 0 0 1 0 6h-2a3.5 3 0 0 1-3.5-2.5' ),
	);

	/**
	 * The `wp_kses()` allow-list that lets {@see Icons::svg()} markup through unchanged.
	 *
	 * Output goes through `wp_kses( Icons::svg( … ), Icons::allowed_html() )`, so printing
	 * an icon never needs an escaping exclusion.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'svg'  => array(
				'class'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
				'focusable'       => true,
			),
			'path' => array(
				'd' => true,
			),
		);
	}

	/**
	 * The allow-listed names, in declaration order.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_keys( self::PATHS );
	}

	/**
	 * Every icon as the JavaScript set describes it: `{ name: { viewBox, paths } }`.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{viewBox: string, paths: string[]}>
	 */
	public static function definitions(): array {
		$definitions = array();

		foreach ( self::PATHS as $name => $paths ) {
			$definitions[ $name ] = array(
				'viewBox' => self::VIEW_BOX,
				'paths'   => $paths,
			);
		}

		return $definitions;
	}

	/**
	 * The icon's inline SVG markup, or an empty string for a name outside the allow-list.
	 *
	 * The markup is built from constants and an integer only, so it carries no user data
	 * and needs no escaping; callers echo it through `wp_kses()` with an SVG allow-list or
	 * as trusted static markup.
	 *
	 * @since 1.2.0
	 *
	 * @param string $name Icon name, one of {@see Icons::names()}.
	 * @param int    $size Rendered width and height in pixels, clamped to 12–48.
	 * @return string
	 */
	public static function svg( string $name, int $size = 18 ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}

		$size  = max( self::MIN_SIZE, min( self::MAX_SIZE, $size ) );
		$paths = '';

		foreach ( self::PATHS[ $name ] as $d ) {
			$paths .= '<path d="' . $d . '"/>';
		}

		return sprintf(
			'<svg class="%1$s" width="%2$d" height="%2$d" viewBox="%3$s" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%4$s</svg>',
			self::CSS_CLASS,
			$size,
			self::VIEW_BOX,
			$paths
		);
	}
}
