/**
 * Babel configuration (build and Jest).
 *
 * The WordPress preset, with JSX compiled by the *classic* runtime to `createElement`
 * from `@wordpress/element` instead of the preset's automatic runtime. The automatic
 * runtime imports `react/jsx-runtime`, which maps to core's `react-jsx-runtime` handle —
 * registered only from WordPress 6.6, while the declared floor is 6.5 — and bundling it
 * instead would tie the element format to the React version in node_modules rather than
 * the one WordPress ships. `createElement` works with every React core has shipped.
 *
 * Each file that contains JSX therefore imports `createElement` (and `Fragment` when it
 * uses `<>`) from `@wordpress/element`.
 */

module.exports = ( api ) => {
	api.cache( true );

	return {
		presets: [ '@wordpress/babel-preset-default' ],
		plugins: [
			[
				'@babel/plugin-transform-react-jsx',
				{
					runtime: 'classic',
					pragma: 'createElement',
					pragmaFrag: 'Fragment',
				},
			],
		],
	};
};
