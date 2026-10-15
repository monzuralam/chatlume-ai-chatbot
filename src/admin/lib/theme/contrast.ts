/**
 * WCAG AA checks for every text and background pair the widget draws.
 */
import { __ } from '@wordpress/i18n';

import { contrast, fixContrast } from './color';
import type { ColorSet, ColorToken, Theme } from './types';

export interface ContrastPair {
	id: string;
	foreground: ColorToken;
	background: ColorToken;
	/** 4.5 for text, 3 for icons. */
	minimum: number;
}

export interface ContrastIssue extends ContrastPair {
	mode: 'light' | 'dark';
	ratio: number;
}

/**
 * Pairs to test for a theme (the gradient end only matters with a
 * gradient header).
 *
 * @param theme Theme.
 * @return Pairs.
 */
export function contrastPairs( theme: Theme ): ContrastPair[] {
	const pairs: ContrastPair[] = [
		{
			id: 'button',
			foreground: 'onPrimary',
			background: 'primary',
			minimum: 4.5,
		},
		{
			id: 'header',
			foreground: 'headerText',
			background: 'headerBackground',
			minimum: 4.5,
		},
		{
			id: 'bot',
			foreground: 'botText',
			background: 'botBubble',
			minimum: 4.5,
		},
		{
			id: 'botPage',
			foreground: 'botText',
			background: 'background',
			minimum: 4.5,
		},
		{
			id: 'user',
			foreground: 'userText',
			background: 'userBubble',
			minimum: 4.5,
		},
		{
			id: 'link',
			foreground: 'link',
			background: 'botBubble',
			minimum: 4.5,
		},
		{
			id: 'input',
			foreground: 'inputText',
			background: 'inputBackground',
			minimum: 4.5,
		},
		{
			id: 'muted',
			foreground: 'muted',
			background: 'background',
			minimum: 4.5,
		},
		{
			id: 'error',
			foreground: 'error',
			background: 'background',
			minimum: 4.5,
		},
		{
			id: 'launcher',
			foreground: 'launcherIcon',
			background: 'launcherBackground',
			minimum: 3,
		},
	];
	if ( theme.header.style === 'gradient' ) {
		pairs.push( {
			id: 'headerEnd',
			foreground: 'headerText',
			background: 'headerBackgroundEnd',
			minimum: 4.5,
		} );
	}
	return pairs;
}

/**
 * Pairs below their minimum in light and dark mode.
 *
 * @param theme Theme.
 * @return Issues.
 */
export function contrastIssues( theme: Theme ): ContrastIssue[] {
	const issues: ContrastIssue[] = [];
	for ( const mode of [ 'light', 'dark' ] as const ) {
		const colors = theme.colors[ mode ];
		for ( const pair of contrastPairs( theme ) ) {
			const ratio = contrast(
				colors[ pair.foreground ],
				colors[ pair.background ]
			);
			if ( ratio < pair.minimum - 0.005 ) {
				issues.push( { ...pair, mode, ratio } );
			}
		}
	}
	return issues;
}

/**
 * Fixes one issue by adjusting the foreground's lightness, so the pair
 * passes. The background stays as chosen.
 *
 * @param colors Color set.
 * @param issue  Issue.
 * @return New color set.
 */
export function fixIssue( colors: ColorSet, issue: ContrastPair ): ColorSet {
	return {
		...colors,
		[ issue.foreground ]: fixContrast(
			colors[ issue.foreground ],
			colors[ issue.background ],
			issue.minimum
		),
	};
}

/**
 * Fixes every issue (a fix can create another, e.g. a darker link on a
 * different background, so this repeats a few times).
 *
 * @param theme Theme.
 * @return Theme with passing colors.
 */
export function fixAll( theme: Theme ): Theme {
	let next = theme;
	for ( let round = 0; round < 4; round++ ) {
		const issues = contrastIssues( next );
		if ( ! issues.length ) {
			break;
		}
		const colors = {
			light: { ...next.colors.light },
			dark: { ...next.colors.dark },
		};
		for ( const issue of issues ) {
			colors[ issue.mode ] = fixIssue( colors[ issue.mode ], issue );
		}
		next = { ...next, colors };
	}
	return next;
}

/**
 * Label for a pair.
 *
 * @param id Pair ID.
 * @return Label.
 */
export function pairLabel( id: string ): string {
	switch ( id ) {
		case 'button':
			return __( 'Text on buttons', 'chatlume-ai-chatbot' );
		case 'header':
		case 'headerEnd':
			return __( 'Header text', 'chatlume-ai-chatbot' );
		case 'bot':
			return __( 'Bot messages', 'chatlume-ai-chatbot' );
		case 'botPage':
			return __( 'Bot text on the background', 'chatlume-ai-chatbot' );
		case 'user':
			return __( 'Visitor messages', 'chatlume-ai-chatbot' );
		case 'link':
			return __( 'Links in messages', 'chatlume-ai-chatbot' );
		case 'input':
			return __( 'Message field', 'chatlume-ai-chatbot' );
		case 'muted':
			return __( 'Secondary text', 'chatlume-ai-chatbot' );
		case 'error':
			return __( 'Error messages', 'chatlume-ai-chatbot' );
		case 'launcher':
			return __( 'Launcher icon', 'chatlume-ai-chatbot' );
		default:
			return id;
	}
}
