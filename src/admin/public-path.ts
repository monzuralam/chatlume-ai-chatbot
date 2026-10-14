/**
 * Points webpack at the plugin's build folder for lazy-loaded route chunks.
 *
 * Must be the first import of the entry point. Using the PHP-provided URL
 * keeps chunk loading working even when optimization plugins move, rename,
 * or combine the main script.
 */
const assetsUrl = window.chatlume?.bootstrap?.assetsUrl;

if ( assetsUrl ) {
	// eslint-disable-next-line camelcase -- Webpack runtime global; its name is fixed by webpack.
	__webpack_public_path__ = assetsUrl;
}

export {};
