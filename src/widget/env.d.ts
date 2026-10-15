/**
 * CSS imported as a string (`?inline`) for injection into the shadow root.
 */
declare module '*.css?inline' {
	const css: string;
	export default css;
}
