/**
 * The admin UI's icon set, shared by the editor panel and any script that draws icons.
 *
 * The same names and paths as `src/Admin/Icons.php` (UI decision U5). The unit suite
 * checks the PHP set against `tests/fixtures/icon-names.json` (written by
 * `php bin/icon-names.php`) and the Jest suite checks this file against the same
 * fixture, so the two cannot drift.
 */

import { createElement } from '@wordpress/element';

/**
 * Coordinate system every icon is drawn in (`Icons::VIEW_BOX`).
 */
const VIEW_BOX = '0 0 24 24';

/**
 * CSS class on every icon (`Icons::CSS_CLASS`).
 */
const CSS_CLASS = 'sit-wcpg-ui-icon';

/**
 * Rendered size bounds, in pixels (`Icons::MIN_SIZE`, `Icons::MAX_SIZE`).
 */
const MIN_SIZE = 12;
const MAX_SIZE = 48;

/**
 * One icon definition.
 *
 * @param {string[]} paths The `d` attribute of each stroked path.
 * @return {{viewBox: string, paths: string[]}} The definition.
 */
const icon = ( paths ) => ( { viewBox: VIEW_BOX, paths } );

/**
 * Icon name => definition, in the PHP declaration order.
 */
export const ICONS = {
	shield: icon( [ 'M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3z' ] ),
	check: icon( [ 'M5 12.5l4.5 4.5L19 7.5' ] ),
	cross: icon( [ 'M6 6l12 12', 'M18 6L6 18' ] ),
	alert: icon( [
		'M12 3.5L2.5 20h19L12 3.5z',
		'M12 10v4.5',
		'M12 17.5v.01',
	] ),
	info: icon( [
		'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18z',
		'M12 11v5.5',
		'M12 7.5v.01',
	] ),
	refresh: icon( [
		'M20 11a8 8 0 0 0-14.6-4.5L4 8',
		'M4 4v4h4',
		'M4 13a8 8 0 0 0 14.6 4.5L20 16',
		'M20 20v-4h-4',
	] ),
	search: icon( [ 'M11 4a7 7 0 1 0 0 14a7 7 0 1 0 0-14z', 'M20 20l-4-4' ] ),
	'chevron-down': icon( [ 'M6 9l6 6 6-6' ] ),
	'chevron-right': icon( [ 'M9 6l6 6-6 6' ] ),
	book: icon( [
		'M4 19.5v-14A2.5 2.5 0 0 1 6.5 3H20v14H6.5A2.5 2.5 0 0 0 4 19.5z',
		'M4 19.5A2.5 2.5 0 0 0 6.5 22H20',
	] ),
	chat: icon( [ 'M4 5h16v11H9l-5 4V5z' ] ),
	star: icon( [
		'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3z',
	] ),
	crown: icon( [ 'M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8z' ] ),
	bag: icon( [ 'M5 8h14l-1 13H6L5 8z', 'M9 8V6a3 3 0 0 1 6 0v2' ] ),
	barcode: icon( [
		'M4 5v14',
		'M7 5v14',
		'M10 5v14',
		'M14 5v14',
		'M17 5v14',
		'M20 5v14',
	] ),
	grid: icon( [
		'M4 4h6v6H4z',
		'M14 4h6v6h-6z',
		'M4 14h6v6H4z',
		'M14 14h6v6h-6z',
	] ),
	sparkle: icon( [
		'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z',
		'M19 16l.7 2.3L22 19l-2.3.7L19 22l-.7-2.3L16 19l2.3-.7L19 16z',
	] ),
	store: icon( [
		'M3 9l1.5-5h15L21 9H3z',
		'M5 12v8h14v-8',
		'M10 20v-5h4v5',
	] ),
	fields: icon( [ 'M4 5h16v5H4z', 'M4 14h16v5H4z' ] ),
	key: icon( [
		'M8 11a4 4 0 1 0 0 8a4 4 0 1 0 0-8z',
		'M10.8 12.2L20 3',
		'M16 7l3 3',
		'M18 5l2 2',
	] ),
	edit: icon( [ 'M4 20h4L19 9l-4-4L4 16v4z', 'M13.5 6.5l4 4' ] ),
	trash: icon( [
		'M4 7h16',
		'M9 7V4h6v3',
		'M6 7l1 13h10l1-13',
		'M10 11v6',
		'M14 11v6',
	] ),
	plus: icon( [ 'M12 5v14', 'M5 12h14' ] ),
	image: icon( [ 'M4 4h16v16H4z', 'M4 16l5-5 4 4 2-2 5 5', 'M15 8.5v.01' ] ),
	images: icon( [ 'M7 7h13v13H7z', 'M4 17V4h13', 'M7 17l4-4 3 3 2-2 4 4' ] ),
	text: icon( [ 'M4 6h16', 'M4 10h16', 'M4 14h16', 'M4 18h10' ] ),
	title: icon( [ 'M5 5h14', 'M12 5v14', 'M9 19h6' ] ),
	tag: icon( [ 'M3 12V3h9l9 9-9 9-9-9z', 'M7.5 7.5v.01' ] ),
	ban: icon( [
		'M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18z',
		'M5.6 5.6l12.8 12.8',
	] ),
	price: icon( [
		'M12 3v18',
		'M16.5 7.5A3.5 3 0 0 0 13 5h-2a3 3 0 0 0 0 6h2a3 3 0 0 1 0 6h-2a3.5 3 0 0 1-3.5-2.5',
	] ),
};

/**
 * An inline SVG icon, hidden from assistive technology. Unknown names render nothing.
 *
 * @param {Object} props      Component props.
 * @param {string} props.name Icon name, a key of `ICONS`.
 * @param {number} props.size Width and height in pixels, clamped to 12–48.
 * @return {Element|null} The icon.
 */
export function Icon( { name, size = 18 } ) {
	if ( ! Object.prototype.hasOwnProperty.call( ICONS, name ) ) {
		return null;
	}

	const definition = ICONS[ name ];
	const px = Math.max(
		MIN_SIZE,
		Math.min( MAX_SIZE, Math.round( Number( size ) || 18 ) )
	);

	return createElement(
		'svg',
		{
			className: CSS_CLASS,
			width: px,
			height: px,
			viewBox: definition.viewBox,
			fill: 'none',
			stroke: 'currentColor',
			strokeWidth: 2,
			strokeLinecap: 'round',
			strokeLinejoin: 'round',
			'aria-hidden': 'true',
			focusable: 'false',
		},
		definition.paths.map( ( d ) => createElement( 'path', { key: d, d } ) )
	);
}
