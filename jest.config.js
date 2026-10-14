/**
 * Jest configuration (run with `wp-scripts test-unit-jest`).
 *
 * `@wordpress/scripts` 36 defaults to Vitest; Chatlume keeps Jest using the
 * documented legacy setup: the WordPress Jest preset (jsdom, CSS mocks,
 * console assertions) and Babel with the WordPress preset (TypeScript, JSX).
 */
module.exports = {
	preset: '@wordpress/jest-preset-default',
	rootDir: __dirname,
	testMatch: [ '<rootDir>/tests/js/**/*.test.[jt]s?(x)' ],
	testPathIgnorePatterns: [ '/node_modules/', '/build/', '/vendor/' ],
	transform: {
		'\\.[jt]sx?$': [
			'babel-jest',
			{ presets: [ '@wordpress/babel-preset-default' ] },
		],
	},
};
