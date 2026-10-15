/**
 * Built-in theme presets. Each is a brand color plus style choices; the
 * full light and dark palettes are generated from the brand color and every
 * pair is checked against WCAG AA (see tests/js/theme.test.ts).
 */
import { __ } from '@wordpress/i18n';

import { generatePalette } from './color';
import { fixAll } from './contrast';
import { SHAPE_PRESETS, type Theme } from './types';

export interface ThemePreset {
	id: string;
	name: string;
	brand: string;
	mode: Theme[ 'mode' ];
	shape: Theme[ 'shape' ][ 'preset' ];
	shadow: Theme[ 'shape' ][ 'shadow' ];
	header: Theme[ 'header' ][ 'style' ];
	messages: Theme[ 'messages' ][ 'style' ];
	background: Theme[ 'background' ];
	composer: Theme[ 'composer' ][ 'style' ];
	font: Theme[ 'typography' ][ 'bodyFont' ];
	launcher: Theme[ 'launcher' ][ 'shape' ];
}

const solid: Theme[ 'background' ] = {
	type: 'solid',
	pattern: 'dots',
	glass: false,
};

/**
 * The presets, translated.
 *
 * @return Presets.
 */
export function themePresets(): ThemePreset[] {
	return [
		{
			id: 'minimal',
			name: __( 'Minimal', 'chatlume-ai-chatbot' ),
			brand: '#2563eb',
			mode: 'light',
			shape: 'rounded',
			shadow: 'soft',
			header: 'minimal',
			messages: 'bubbles',
			background: solid,
			composer: 'rounded',
			font: 'inherit',
			launcher: 'circle',
		},
		{
			id: 'midnight',
			name: __( 'Midnight', 'chatlume-ai-chatbot' ),
			brand: '#6366f1',
			mode: 'dark',
			shape: 'soft',
			shadow: 'strong',
			header: 'solid',
			messages: 'bubbles',
			background: solid,
			composer: 'rounded',
			font: 'system',
			launcher: 'circle',
		},
		{
			id: 'glass',
			name: __( 'Glass', 'chatlume-ai-chatbot' ),
			brand: '#0ea5e9',
			mode: 'light',
			shape: 'pill',
			shadow: 'medium',
			header: 'gradient',
			messages: 'bubbles',
			background: { type: 'gradient', pattern: 'dots', glass: true },
			composer: 'rounded',
			font: 'system',
			launcher: 'circle',
		},
		{
			id: 'corporate',
			name: __( 'Corporate', 'chatlume-ai-chatbot' ),
			brand: '#1e3a8a',
			mode: 'light',
			shape: 'sharp',
			shadow: 'soft',
			header: 'solid',
			messages: 'flat',
			background: solid,
			composer: 'bar',
			font: 'system',
			launcher: 'square',
		},
		{
			id: 'soft',
			name: __( 'Soft', 'chatlume-ai-chatbot' ),
			brand: '#db2777',
			mode: 'light',
			shape: 'pill',
			shadow: 'soft',
			header: 'gradient',
			messages: 'bubbles',
			background: { type: 'pattern', pattern: 'dots', glass: false },
			composer: 'rounded',
			font: 'system-rounded',
			launcher: 'circle',
		},
		{
			id: 'playful',
			name: __( 'Playful', 'chatlume-ai-chatbot' ),
			brand: '#f97316',
			mode: 'light',
			shape: 'pill',
			shadow: 'medium',
			header: 'gradient',
			messages: 'bubbles',
			background: { type: 'pattern', pattern: 'waves', glass: false },
			composer: 'rounded',
			font: 'system-rounded',
			launcher: 'pill',
		},
		{
			id: 'mono',
			name: __( 'Mono', 'chatlume-ai-chatbot' ),
			brand: '#171717',
			mode: 'light',
			shape: 'sharp',
			shadow: 'none',
			header: 'minimal',
			messages: 'outlined',
			background: solid,
			composer: 'bar',
			font: 'mono',
			launcher: 'square',
		},
		{
			id: 'ocean',
			name: __( 'Ocean', 'chatlume-ai-chatbot' ),
			brand: '#0f766e',
			mode: 'auto',
			shape: 'soft',
			shadow: 'medium',
			header: 'gradient',
			messages: 'bubbles',
			background: { type: 'pattern', pattern: 'waves', glass: false },
			composer: 'rounded',
			font: 'system',
			launcher: 'circle',
		},
		{
			id: 'sunset',
			name: __( 'Sunset', 'chatlume-ai-chatbot' ),
			brand: '#e11d48',
			mode: 'light',
			shape: 'soft',
			shadow: 'strong',
			header: 'gradient',
			messages: 'bubbles',
			background: { type: 'gradient', pattern: 'dots', glass: false },
			composer: 'rounded',
			font: 'system-serif',
			launcher: 'circle',
		},
		{
			id: 'forest',
			name: __( 'Forest', 'chatlume-ai-chatbot' ),
			brand: '#15803d',
			mode: 'auto',
			shape: 'rounded',
			shadow: 'medium',
			header: 'solid',
			messages: 'bubbles',
			background: { type: 'pattern', pattern: 'diagonal', glass: false },
			composer: 'rounded',
			font: 'system',
			launcher: 'square',
		},
	];
}

/**
 * The current brand color of a theme (its light primary).
 *
 * @param theme Theme.
 * @return Hex color.
 */
export function brandColor( theme: Theme ): string {
	return theme.colors.light.primary;
}

/**
 * New colors from one brand color, keeping everything else.
 *
 * @param theme Theme.
 * @param brand Brand color.
 * @return Theme.
 */
export function withBrandColor( theme: Theme, brand: string ): Theme {
	const palette = generatePalette( brand );
	return fixAll( {
		...theme,
		colors: {
			light: { ...theme.colors.light, ...palette.light },
			dark: { ...theme.colors.dark, ...palette.dark },
		},
	} );
}

/**
 * Applies a preset. The admin's texts and images (bot name, avatar, logo,
 * launcher image, label, teaser) are kept.
 *
 * @param theme     Current theme.
 * @param preset    Preset.
 * @param keepBrand Use the current brand color instead of the preset's.
 * @return Theme.
 */
export function applyPreset(
	theme: Theme,
	preset: ThemePreset,
	keepBrand = false
): Theme {
	const next: Theme = {
		...theme,
		mode: preset.mode,
		header: { ...theme.header, style: preset.header },
		typography: { ...theme.typography, bodyFont: preset.font },
		shape: {
			...theme.shape,
			preset: preset.shape,
			radii: { ...SHAPE_PRESETS[ preset.shape ] },
			shadow: preset.shadow,
		},
		messages: { ...theme.messages, style: preset.messages },
		background: { ...preset.background },
		composer: { ...theme.composer, style: preset.composer },
		launcher: { ...theme.launcher, shape: preset.launcher },
	};
	return withBrandColor(
		next,
		keepBrand ? brandColor( theme ) : preset.brand
	);
}
