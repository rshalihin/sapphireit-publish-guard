/**
 * Build configuration.
 *
 * Extends the `@wordpress/scripts` default config with two explicit entries:
 *
 * - `editor` — the React checklist panel. Its SCSS is imported from index.js, so
 *   wp-scripts emits build/editor.js, build/editor.css and build/editor.asset.php.
 * - `admin`  — style only (the products list column and the settings page are plain
 *   server-rendered HTML). wp-scripts' RemoveEmptyScripts plugin drops the empty
 *   companion JS file, leaving only build/admin.css.
 *
 * An entry whose source does not exist is left out rather than failing the build, and
 * `Admin\Assets` skips any stylesheet that was not built.
 *
 * JSX uses the classic runtime (see babel.config.js), so the bundle depends on
 * `wp-element` and never on `react-jsx-runtime`, which WordPress 6.5 does not register.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const entries = {
	editor: path.resolve( __dirname, 'assets/js/editor/index.js' ),
	admin: path.resolve( __dirname, 'assets/scss/admin.scss' ),
};

module.exports = {
	...defaultConfig,
	entry: Object.fromEntries(
		Object.entries( entries ).filter( ( [ , file ] ) =>
			fs.existsSync( file )
		)
	),
};
