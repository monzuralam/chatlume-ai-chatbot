/**
 * Copy-ready CSS for developers. Chatlume never stores or prints custom CSS;
 * these snippets are pasted into WordPress's own Additional CSS. Custom
 * properties set on the `chatlume-widget` element override the theme
 * because page styles win over a shadow root's `:host` rules.
 */
import type { ThemeConfig } from '../../../widget/types';

/** Elements exposed with `part`, for `chatlume-widget::part(…)`. */
export const PARTS = [
	'launcher',
	'teaser',
	'window',
	'header',
	'message-bot',
	'message-user',
	'composer',
	'source-card',
	'starters',
] as const;

const block = ( selector: string, tokens: Record< string, string > ) =>
	`${ selector } {\n${ Object.entries( tokens )
		.map( ( [ name, value ] ) => `\t${ name }: ${ value };` )
		.join( '\n' ) }\n}`;

/**
 * The resolved theme as CSS custom properties: light (and shared) values,
 * then dark-mode values.
 *
 * @param config Resolved theme.
 * @return CSS.
 */
export function tokensCss( config: ThemeConfig ): string {
	return [
		block( 'chatlume-widget', { ...config.vars, ...config.light } ),
		block( 'chatlume-widget[data-mode="dark"]', config.dark ),
	].join( '\n\n' );
}

/**
 * A short example snippet.
 *
 * @param config Resolved theme.
 * @return CSS.
 */
export function exampleCss( config: ThemeConfig ): string {
	return [
		'/* Change one color everywhere. */',
		block( 'chatlume-widget', {
			'--chatlume-primary':
				config.light[ '--chatlume-primary' ] ?? '#4f46e5',
		} ),
		'',
		'/* Style one element of the widget. */',
		block( 'chatlume-widget::part(launcher)', {
			'box-shadow': '0 0 0 4px rgba(0, 0, 0, 0.08)',
		} ),
	].join( '\n' );
}
