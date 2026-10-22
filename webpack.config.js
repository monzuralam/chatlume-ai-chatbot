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
 * 4. commands – Commands for WordPress's Command Palette (every admin screen).
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
	// The launcher loads the chat app and the invitation code as plain scripts
	// (not webpack chunks), which keeps webpack's chunk runtime out of the
	// launcher's 6 KB budget. Each entry gets its own .asset.php version.
	entry: {
		index: path.resolve( __dirname, 'src/widget/index.ts' ),
		'chatlume-app': path.resolve( __dirname, 'src/widget/app/entry.ts' ),
		'chatlume-triggers': path.resolve(
			__dirname,
			'src/widget/lib/triggers-entry.ts'
		),
	},
	output: {
		...scriptConfig.output,
		path: path.resolve( __dirname, 'build/widget' ),
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
	// Guards the launcher, which loads on every page (budget: 6 KB gzipped,
	// about 18 KB minified). The chat app loads only when a visitor opens it.
	performance: {
		hints: 'warning',
		assetFilter: ( name ) => name === 'index.js',
		maxEntrypointSize: 20 * 1024,
		maxAssetSize: 20 * 1024,
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

// Command Palette commands: a tiny script on every admin screen.
const commands = {
	...scriptConfig,
	name: 'commands',
	entry: {
		index: path.resolve( __dirname, 'src/commands/index.ts' ),
	},
	output: {
		...scriptConfig.output,
		path: path.resolve( __dirname, 'build/commands' ),
	},
	resolve: {
		...scriptConfig.resolve,
		extensions,
	},
	plugins: scriptConfig.plugins.filter(
		( plugin ) => plugin.constructor.name !== 'CopyPlugin'
	),
};

module.exports = [ admin, widget, block, commands ];
