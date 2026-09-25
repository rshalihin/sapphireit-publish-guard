/**
 * ESLint flat config: wp-scripts' default, plus the plugin's own rules.
 *
 * - `react/no-danger` is an error: `dangerouslySetInnerHTML` is banned
 *   (coding-plan.md section 9.4).
 * - JSX compiles to `createElement` (classic runtime, see babel.config.js), so the
 *   imported pragma counts as used.
 * - `jquery` is core's script handle, provided at runtime and never installed; the
 *   build maps the import to it. Only field-watcher.js may import it (section 10.3).
 * - The JS unit tests run on Jest (`wp-scripts test-unit-jest`), so they get Jest's
 *   globals; wp-scripts' default test override assumes Vitest.
 */

const globals = require( 'globals' );
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		settings: {
			react: { pragma: 'createElement', fragment: 'Fragment' },
			'import/core-modules': [ 'jquery' ],
		},
		rules: {
			'react/no-danger': 'error',
			'react/jsx-uses-react': 'error',
		},
	},
	{
		files: [ 'tests/js/**/*.js' ],
		languageOptions: {
			globals: globals.jest,
		},
	},
];
