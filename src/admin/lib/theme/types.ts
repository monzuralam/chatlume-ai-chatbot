/**
 * The widget theme, mirroring `Chatlume\Appearance\ThemeSchema`. The server
 * validates and clamps every value; these types and the option lists keep
 * the Customizer's controls within the same limits.
 */

export const COLOR_TOKENS = [
	'primary',
	'onPrimary',
	'accent',
	'background',
	'surface',
	'headerBackground',
	'headerBackgroundEnd',
	'headerText',
	'botBubble',
	'botText',
	'userBubble',
	'userText',
	'link',
	'border',
	'inputBackground',
	'inputText',
	'launcherBackground',
	'launcherIcon',
	'sourceCard',
	'muted',
	'error',
	'success',
] as const;

export type ColorToken = ( typeof COLOR_TOKENS )[ number ];
export type ColorSet = Record< ColorToken, string >;

export const LAUNCHER_ICONS = [
	'chat',
	'chat-dots',
	'message',
	'sparkle',
	'help',
	'support',
	'headset',
	'bot',
	'smile',
	'wave',
	'lightbulb',
	'heart',
] as const;

export const ILLUSTRATIONS = [
	'bubbles',
	'robot',
	'spark',
	'leaf',
	'wave',
	'planet',
] as const;

export const SYSTEM_FONTS = [
	'inherit',
	'system',
	'system-serif',
	'system-rounded',
	'mono',
] as const;

export interface Theme {
	version: number;
	mode: 'light' | 'dark' | 'auto';
	colors: { light: ColorSet; dark: ColorSet };
	header: { style: 'solid' | 'gradient' | 'minimal'; gradientAngle: number };
	typography: {
		bodyFont: ( typeof SYSTEM_FONTS )[ number ];
		headingFont: 'same' | ( typeof SYSTEM_FONTS )[ number ];
		baseSize: number;
		lineHeight: number;
		titleWeight: number;
		messageWeight: number;
		letterSpacing: number;
	};
	layout: {
		style: 'floating' | 'panel' | 'modal';
		position: 'right' | 'left';
		offsetX: number;
		offsetY: number;
		mobileOffsetX: number;
		mobileOffsetY: number;
		zIndex: number;
		width: number;
		height: number;
		mobileFullscreen: boolean;
		fullscreenBelow: number;
	};
	shape: {
		preset: 'soft' | 'sharp' | 'rounded' | 'pill';
		radii: {
			window: number;
			bubble: number;
			button: number;
			input: number;
			card: number;
		};
		shadow: 'medium' | 'none' | 'soft' | 'strong';
		density: 'comfortable' | 'compact' | 'spacious';
	};
	messages: {
		style: 'bubbles' | 'flat' | 'outlined';
		avatar: boolean;
		timestamps: 'hover' | 'always' | 'never';
		animate: boolean;
	};
	background: {
		type: 'solid' | 'pattern' | 'gradient';
		pattern: 'dots' | 'grid' | 'waves' | 'diagonal';
		glass: boolean;
	};
	composer: { style: 'rounded' | 'bar'; sendButton: 'icon' | 'text' };
	launcher: {
		type: 'icon' | 'avatar' | 'image';
		icon: ( typeof LAUNCHER_ICONS )[ number ];
		image: string;
		shape: 'circle' | 'square' | 'pill';
		size: 'm' | 's' | 'l';
		label: string;
		attention: 'none' | 'pulse' | 'bounce' | 'badge';
		teaser: string;
	};
	motion: {
		open: 'scale' | 'slide' | 'none';
		speed: 'normal' | 'fast' | 'slow';
	};
	brand: {
		avatarType: 'initials' | 'image' | 'illustration';
		avatarUrl: string;
		illustration: ( typeof ILLUSTRATIONS )[ number ];
		logoUrl: string;
		showLogo: boolean;
		headerTitle: string;
		headerSubtitle: string;
		statusText: string;
		showAvatar: boolean;
		showStatus: boolean;
		poweredBy: boolean;
	};
}

/** Numeric limits (minimum, maximum), as clamped by the server. */
export const RANGES = {
	gradientAngle: [ 0, 360 ],
	baseSize: [ 13, 18 ],
	lineHeight: [ 1.2, 1.9 ],
	titleWeight: [ 400, 800 ],
	messageWeight: [ 300, 600 ],
	letterSpacing: [ -0.05, 0.1 ],
	offset: [ 0, 200 ],
	mobileOffset: [ 0, 100 ],
	width: [ 300, 520 ],
	height: [ 400, 820 ],
	fullscreenBelow: [ 320, 1024 ],
	radiusWindow: [ 0, 32 ],
	radiusBubble: [ 0, 28 ],
	radiusCard: [ 0, 24 ],
	radiusControl: [ 0, 40 ],
} as const;

/** Corner radius presets (window, bubble, button, input, card). */
export const SHAPE_PRESETS: Record<
	Theme[ 'shape' ][ 'preset' ],
	Theme[ 'shape' ][ 'radii' ]
> = {
	sharp: { window: 4, bubble: 6, button: 6, input: 6, card: 4 },
	rounded: { window: 12, bubble: 12, button: 10, input: 10, card: 10 },
	soft: { window: 16, bubble: 16, button: 999, input: 999, card: 12 },
	pill: { window: 24, bubble: 22, button: 999, input: 999, card: 18 },
};
