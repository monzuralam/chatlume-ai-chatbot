/**
 * Webpack configuration for Chatlume.
 *
 * Extends the default `@wordpress/scripts` configuration and exports one
 * named configuration per bundle:
 *
 * 1. admin  – React admin app. Keeps the default Dependency Extraction plugin,
 *             so `react`, `react-dom`, and `@wordpress/*` resolve to the globals
 *             WordPress core already ships.
 * 2. widget – Visitor chat widget. Source is written with React APIs but
 *             compiled against Preact through `preact/compat` aliases. WordPress
 *             core does not ship Preact, so it is bundled; the widget has no
 *             WordPress script dependencies at all. Its CSS is imported as a
 *             string (`?inline`) and injected into the shadow root.
 * 3. block  – The `chatlume/chat` block (editor script, block.json, render.php).
 */
const path = require( 'path' );
const CopyPlugin = require( 'copy-webpack-plugin' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// wp-scripts exports an array only when experimental modules are enabled.
const scriptConfig = Array.isArray( defaultConfig )
	? defaultConfig[ 0 ]
	: defaultConfig;

const extensions = [ '.tsx', '.ts', '.jsx', '.js', '...' ];

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
		extensions,
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

/**
 * Default rules, with the regular CSS rule skipping `?inline` imports so
 * the two CSS rules never apply to the same file.
 */
const widgetRules = scriptConfig.module.rules.map( ( rule ) =>
	String( rule.test ) === String( /\.css$/ )
		? { ...rule, resourceQuery: { not: [ /inline/ ] } }
		: rule
);

const widget = {
	...scriptConfig,
	name: 'widget',
	entry: {
		index: path.resolve( __dirname, 'src/widget/index.ts' ),
	},
	output: {
		...scriptConfig.output,
		path: path.resolve( __dirname, 'build/widget' ),
		// Lazy chunks are named so optimization plugins can be told to leave them alone.
		chunkFilename: 'chatlume-[name].js?ver=[contenthash]',
	},
	resolve: {
		...scriptConfig.resolve,
		extensions,
		alias: {
			...( scriptConfig.resolve?.alias || {} ),
			react: 'preact/compat',
			'react-dom': 'preact/compat',
			'react-dom/client': 'preact/compat/client',
			'react/jsx-runtime': 'preact/jsx-runtime',
			'react/jsx-dev-runtime': 'preact/jsx-dev-runtime',
		},
	},
	module: {
		...scriptConfig.module,
		rules: [
			{
				test: /\.css$/,
				resourceQuery: /inline/,
				// The processed CSS as a plain string: no loader runtime, and
				// data: URIs stay inline instead of becoming separate assets.
				type: 'asset/source',
				use: [ { loader: require.resolve( 'postcss-loader' ) } ],
			},
			...widgetRules,
		],
	},
	plugins: [
		...scriptConfig.plugins.filter(
			( plugin ) =>
				! [
					'DependencyExtractionWebpackPlugin',
					'CopyPlugin',
					'RtlCssPlugin',
					'MiniCssExtractPlugin',
				].includes( plugin.constructor.name )
		),
		new DependencyExtractionWebpackPlugin( {
			// Bundle Preact (aliased as React) instead of using WordPress globals.
			requestToExternal( request ) {
				if (
					request.startsWith( 'react' ) ||
					request.startsWith( 'preact' )
				) {
					return false;
				}
				return undefined;
			},
		} ),
	],
	optimization: {
		...scriptConfig.optimization,
		// One lazy chunk for the chat app (Preact included).
		splitChunks: false,
	},
	performance: {
		hints: 'warning',
		maxEntrypointSize: 60 * 1024,
		maxAssetSize: 200 * 1024,
	},
};

const block = {
	...scriptConfig,
	name: 'block',
	entry: {
		index: path.resolve( __dirname, 'blocks/chat/index.tsx' ),
	},
	output: {
		...scriptConfig.output,
		path: path.resolve( __dirname, 'build/blocks/chat' ),
	},
	resolve: {
		...scriptConfig.resolve,
		extensions,
	},
	plugins: [
		...scriptConfig.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'CopyPlugin'
		),
		new CopyPlugin( {
			patterns: [
				{ from: path.resolve( __dirname, 'blocks/chat/block.json' ) },
				{ from: path.resolve( __dirname, 'blocks/chat/render.php' ) },
			],
		} ),
	],
};

module.exports = [ admin, widget, block ];
