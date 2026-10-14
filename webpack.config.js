/**
 * Webpack configuration for Chatlume.
 *
 * Extends the default `@wordpress/scripts` configuration and exports one named
 * configuration per bundle. The admin app keeps the default Dependency
 * Extraction plugin, so `react`, `react-dom` and `@wordpress/*` resolve to the
 * globals WordPress core already ships, and PHP reads dependencies and the
 * version hash from `build/admin/index.asset.php`.
 *
 * The widget and block bundles are added to this array in the phase that
 * introduces their source code.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// wp-scripts exports an array only when experimental modules are enabled.
const scriptConfig = Array.isArray( defaultConfig )
	? defaultConfig[ 0 ]
	: defaultConfig;

const admin = {
	...scriptConfig,
	name: 'admin',
	entry: {
		index: path.resolve( __dirname, 'src/admin/index.tsx' ),
	},
	output: {
		...scriptConfig.output,
		path: path.resolve( __dirname, 'build/admin' ),
	},
	resolve: {
		...scriptConfig.resolve,
		extensions: [ '.tsx', '.ts', '.jsx', '.js', '...' ],
	},
	// The admin app loads only on the Chatlume screen and routes are
	// code-split, so it gets an explicit budget instead of webpack's
	// public-page default (244 KiB). Exceeding it still warns.
	performance: {
		hints: 'warning',
		maxEntrypointSize: 500 * 1024,
		maxAssetSize: 500 * 1024,
	},
};

module.exports = [ admin ];
