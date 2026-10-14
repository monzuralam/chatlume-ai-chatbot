/**
 * ESLint flat configuration.
 *
 * WordPress recommended rules (with TypeScript support) for all sources,
 * plus Jest rules for tests. The `@wordpress/scripts` default applies Vitest
 * rules to tests, but Chatlume's unit tests run on Jest.
 */
const wpPlugin = require( '@wordpress/eslint-plugin' );
const jestPlugin = require( 'eslint-plugin-jest' );

const testFiles = [ 'tests/js/**/*.{js,jsx,ts,tsx}' ];

module.exports = [
	{
		ignores: [
			'build/**',
			'vendor/**',
			'vendor-prefixed/**',
			'node_modules/**',
			'docs/**',
		],
	},
	{ files: [ '**/*.ts', '**/*.tsx' ] },
	...wpPlugin.configs.recommended,
	{
		languageOptions: {
			parserOptions: {
				requireConfigFile: false,
				babelOptions: {
					presets: [
						require.resolve( '@wordpress/babel-preset-default' ),
					],
				},
			},
		},
	},
	{
		files: [ '**/*.ts', '**/*.tsx' ],
		settings: {
			'import/resolver': {
				node: {
					extensions: [ '.js', '.jsx', '.ts', '.tsx' ],
				},
			},
		},
		rules: {
			// TypeScript reports undefined identifiers and checks types.
			'no-undef': 'off',
			'jsdoc/require-param-type': 'off',
			'jsdoc/require-returns-type': 'off',
		},
	},
	{
		...jestPlugin.configs[ 'flat/recommended' ],
		files: testFiles,
	},
];
