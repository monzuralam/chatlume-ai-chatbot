/**
 * Theme → widget configuration, mirroring
 * `Chatlume\Appearance\TokenResolver::resolve()` so the preview updates
 * instantly without a server round trip. A shared fixture test keeps both
 * implementations identical.
 */
import type { ThemeConfig } from '../../../widget/types';
import { COLOR_TOKENS, type ColorSet, type Theme } from './types';

const FONT_STACKS: Record< string, string > = {
	inherit: 'inherit',
	system: 'system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Bengali", "Noto Sans Arabic", "Noto Sans CJK SC", "Helvetica Neue", Arial, sans-serif',
	'system-serif':
		'ui-serif, Georgia, Cambria, "Noto Serif", "Noto Serif Bengali", "Noto Naskh Arabic", "Noto Serif CJK SC", "Times New Roman", serif',
	'system-rounded':
		'ui-rounded, "SF Pro Rounded", "Nunito", system-ui, "Noto Sans", "Noto Sans Bengali", "Noto Sans Arabic", "Noto Sans CJK SC", sans-serif',
	mono: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
};

const SHADOWS = {
	none: 'none',
	soft: '0 4px 16px rgba(15, 20, 40, 0.08)',
	medium: '0 12px 32px rgba(15, 20, 40, 0.14), 0 2px 6px rgba(15, 20, 40, 0.08)',
	strong: '0 24px 64px rgba(15, 20, 40, 0.24), 0 4px 12px rgba(15, 20, 40, 0.12)',
};

const DENSITY = { compact: 8, comfortable: 12, spacious: 16 };
const SPEED = { fast: 140, normal: 200, slow: 320 };
const LAUNCHER_SIZE = { s: 52, m: 58, l: 66 };

const kebab = ( name: string ) =>
	name.replace( /(?<!^)[A-Z]/g, ( letter ) => '-' + letter ).toLowerCase();

/**
 * The header background whatever its style (one value, so styles switch
 * without re-rendering).
 *
 * @param header Header options.
 * @param colors Colors of one mode.
 * @return CSS background.
 */
export function headerFill(
	header: Theme[ 'header' ],
	colors: ColorSet
): string {
	if ( header.style === 'gradient' ) {
		return `linear-gradient(${ Math.round( header.gradientAngle ) }deg, ${
			colors.headerBackground
		}, ${ colors.headerBackgroundEnd })`;
	}
	return header.style === 'minimal'
		? colors.background
		: colors.headerBackground;
}

/**
 * Resolves a theme into the configuration the widget renders.
 *
 * @param theme Theme.
 * @return Configuration (version "draft").
 */
export function resolveTheme( theme: Theme ): ThemeConfig {
	const modes: Record< 'light' | 'dark', Record< string, string > > = {
		light: {},
		dark: {},
	};

	for ( const mode of [ 'light', 'dark' ] as const ) {
		const colors = theme.colors[ mode ];
		for ( const token of COLOR_TOKENS ) {
			modes[ mode ][ `--chatlume-${ kebab( token ) }` ] = colors[ token ];
		}
		modes[ mode ][ '--chatlume-header-fill' ] = headerFill(
			theme.header,
			colors
		);
		modes[ mode ][ '--chatlume-header-ink' ] =
			theme.header.style === 'minimal'
				? colors.botText
				: colors.headerText;
	}

	const type = theme.typography;
	const body = FONT_STACKS[ type.bodyFont ] ?? 'inherit';
	const heading =
		type.headingFont === 'same'
			? body
			: ( FONT_STACKS[ type.headingFont ] ?? body );
	const { layout } = theme;
	const radii = theme.shape.radii;

	return {
		schema: 1,
		light: modes.light,
		dark: modes.dark,
		vars: {
			'--chatlume-font': body,
			'--chatlume-font-heading': heading,
			'--chatlume-font-size': `${ type.baseSize }px`,
			'--chatlume-line-height': String( type.lineHeight ),
			'--chatlume-title-weight': String( type.titleWeight ),
			'--chatlume-message-weight': String( type.messageWeight ),
			'--chatlume-letter-spacing': `${ type.letterSpacing }em`,
			'--chatlume-width': `${ layout.width }px`,
			'--chatlume-height': `${ layout.height }px`,
			'--chatlume-offset-x': `${ layout.offsetX }px`,
			'--chatlume-offset-y': `${ layout.offsetY }px`,
			'--chatlume-mobile-offset-x': `${ layout.mobileOffsetX }px`,
			'--chatlume-mobile-offset-y': `${ layout.mobileOffsetY }px`,
			'--chatlume-z': String( layout.zIndex ),
			'--chatlume-radius-window': `${ radii.window }px`,
			'--chatlume-radius-bubble': `${ radii.bubble }px`,
			'--chatlume-radius-button': `${ radii.button }px`,
			'--chatlume-radius-input': `${ radii.input }px`,
			'--chatlume-radius-card': `${ radii.card }px`,
			'--chatlume-shadow': SHADOWS[ theme.shape.shadow ],
			'--chatlume-space': `${ DENSITY[ theme.shape.density ] }px`,
			'--chatlume-duration': `${ SPEED[ theme.motion.speed ] }ms`,
			'--chatlume-launcher-size': `${
				LAUNCHER_SIZE[ theme.launcher.size ]
			}px`,
		},
		ui: {
			mode: theme.mode,
			style: layout.style,
			position: layout.position,
			mobileFullscreen: layout.mobileFullscreen,
			fullscreenBelow: layout.fullscreenBelow,
			header: theme.header.style,
			messages: theme.messages,
			background: theme.background,
			composer: theme.composer,
			launcher: theme.launcher,
			motion: theme.motion.open,
			brand: theme.brand,
		},
		v: 'draft',
	};
}
