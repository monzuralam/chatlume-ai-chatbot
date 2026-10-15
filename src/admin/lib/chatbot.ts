/**
 * Chatbot page data and the preview mapping.
 */
import { z } from 'zod';

import type { BehaviorConfig, QuickAction } from '../../widget/types';
import { request } from './api';
import {
	chatbotSettingsSchema,
	costsSettingsSchema,
	quickAnswersSettingsSchema,
	type Settings,
} from './settingsSchema';

export type Preset = Settings[ 'chatbot' ][ 'preset' ];

export interface PresetDefaults {
	prompt: string;
	welcome: string;
	starters: string[];
}

export interface ChatbotInfo {
	defaults: {
		bot_name: string;
		refusal: string;
		offline: string;
		error: string;
		site_search: string;
		presets: Record< Preset, PresetDefaults >;
	};
	variables: Array< { name: string; description: string } >;
	usage: {
		day_tokens: number;
		month_tokens: number;
		day_cost: number;
		month_cost: number;
	};
}

export const fetchChatbotInfo = () => request< ChatbotInfo >( '/chatbot' );

/**
 * A quick action as the widget receives it (mirrors
 * ChatController::quick_actions() on the server).
 *
 * @param action Stored action.
 * @return Widget action, or null when it would be left out.
 */
export function previewQuickAction(
	action: Settings[ 'chatbot' ][ 'quick_actions' ][ number ]
): QuickAction | null {
	const label = action.label.trim();
	const value = action.value.trim();
	if ( ! label || ! value ) {
		return null;
	}
	switch ( action.type ) {
		case 'message':
			return { label, type: 'message', message: value };
		case 'email':
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value )
				? { label, type: 'email', href: `mailto:${ value }` }
				: null;
		case 'phone': {
			const digits = value.replace( /[^0-9+]/g, '' );
			return digits.length >= 3
				? { label, type: 'phone', href: `tel:${ digits }` }
				: null;
		}
		case 'whatsapp': {
			const digits = value.replace( /\D/g, '' );
			return digits.length >= 6
				? { label, type: 'whatsapp', href: `https://wa.me/${ digits }` }
				: null;
		}
		default:
			return /^https?:\/\/\S+$/i.test( value )
				? { label, type: 'url', href: value }
				: null;
	}
}

/**
 * What the widget shows for the current (unsaved) chatbot settings, with the
 * preset's built-in texts where a field is empty.
 *
 * @param chatbot  Chatbot settings.
 * @param defaults Built-in texts.
 * @return Behavior config.
 */
export function previewBehavior(
	chatbot: Settings[ 'chatbot' ],
	defaults: ChatbotInfo[ 'defaults' ]
): BehaviorConfig {
	const preset =
		defaults.presets[ chatbot.preset ] ?? defaults.presets.support;
	const starters = chatbot.starter_questions
		.map( ( question ) => question.trim() )
		.filter( Boolean );

	return {
		bot_name: chatbot.bot_name.trim() || defaults.bot_name,
		welcome_message: chatbot.welcome_message.trim() || preset.welcome,
		starter_questions: ( starters.length
			? starters
			: preset.starters
		).slice( 0, 4 ),
		quick_actions: chatbot.quick_actions
			.map( previewQuickAction )
			.filter( ( action ): action is QuickAction => action !== null ),
		max_message_length: chatbot.max_message_length,
		store_conversations: true,
	};
}

export const chatbotFormSchema = z
	.object( {
		chatbot: chatbotSettingsSchema,
		quick_answers: quickAnswersSettingsSchema,
		costs: costsSettingsSchema,
	} )
	.superRefine( ( values, context ) => {
		// The server leaves out unusable buttons; tell the admin instead.
		values.chatbot.quick_actions.forEach( ( action, index ) => {
			const filled = action.label.trim() || action.value.trim();
			if ( ! filled || previewQuickAction( action ) ) {
				return;
			}
			context.addIssue( {
				code: 'custom',
				path: [
					'chatbot',
					'quick_actions',
					index,
					action.label.trim() ? 'value' : 'label',
				],
				message: action.label.trim()
					? `action:${ action.type }`
					: 'label',
			} );
		} );
	} );

export type ChatbotStored = z.infer< typeof chatbotFormSchema >;

export type ChatbotFormValues = Omit< ChatbotStored, 'chatbot' > & {
	chatbot: Omit<
		ChatbotStored[ 'chatbot' ],
		'blocked_words' | 'starter_questions'
	> & {
		/** One word or phrase per line. */
		blocked_words: string;
		/** Always four slots; empty slots are dropped on save. */
		starter_questions: string[];
	};
};

export const STARTER_SLOTS = 4;

/**
 * Form values from stored settings.
 *
 * @param settings Settings.
 * @return Values.
 */
export function toChatbotForm( settings: Settings ): ChatbotFormValues {
	const starters = settings.chatbot.starter_questions.slice(
		0,
		STARTER_SLOTS
	);
	return {
		chatbot: {
			...settings.chatbot,
			blocked_words: settings.chatbot.blocked_words.join( '\n' ),
			starter_questions: [
				...starters,
				...Array( STARTER_SLOTS - starters.length ).fill( '' ),
			],
		},
		quick_answers: settings.quick_answers,
		costs: settings.costs,
	};
}

/**
 * Stored settings from form values.
 *
 * @param values Values.
 * @return Settings update.
 */
export function fromChatbotForm( values: ChatbotFormValues ): ChatbotStored {
	return {
		...values,
		chatbot: {
			...values.chatbot,
			blocked_words: values.chatbot.blocked_words
				.split( /\r?\n|,/ )
				.map( ( word ) => word.trim() )
				.filter( Boolean ),
			starter_questions: values.chatbot.starter_questions
				.map( ( question ) => question.trim() )
				.filter( Boolean ),
			quick_actions: values.chatbot.quick_actions.map( ( action ) => ( {
				...action,
				label: action.label.trim(),
				value: action.value.trim(),
			} ) ),
		},
	};
}
