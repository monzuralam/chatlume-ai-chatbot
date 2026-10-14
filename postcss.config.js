/**
 * PostCSS configuration.
 *
 * Providing this file replaces the wp-scripts fallback, so the WordPress
 * preset (autoprefixer) and cssnano for production builds are added here.
 * Tailwind CSS runs only for the admin app; widget styles are hand-written
 * CSS and never pass through Tailwind.
 *
 * @param {Object} api      PostCSS loader API.
 * @param {string} api.file Absolute path of the file being processed.
 * @param {string} api.mode Webpack mode.
 */
module.exports = ( api ) => {
	const file = ( api.file || '' ).replace( /\\/g, '/' );
	const isAdmin = file.includes( '/src/admin/' );
	const isProduction = api.mode === 'production';

	return {
		plugins: [
			...( isAdmin ? [ require( '@tailwindcss/postcss' ) ] : [] ),
			...require( '@wordpress/postcss-plugins-preset' ),
			...( isProduction
				? [
						require( 'cssnano' )( {
							preset: [
								'default',
								{ discardComments: { removeAll: true } },
							],
						} ),
					]
				: [] ),
		],
	};
};
