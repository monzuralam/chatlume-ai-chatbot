/**
 * Dashboard statistics.
 */
import { __ } from '@wordpress/i18n';

import { request } from './api';
import type { ConversationSummary } from './conversations';

export type Period = 1 | 7 | 30;

export interface DayStats {
	date: string;
	conversations: number;
	questions: number;
	cost: number;
}

export type UnansweredReason =
	'site_search' | 'not_helpful' | 'no_knowledge' | 'ask_ai';

export interface UnansweredQuestion {
	question: string;
	count: number;
	reasons: UnansweredReason[];
	last_seen: string;
	language: string;
}

export interface ChecklistStep {
	id: 'provider' | 'personalize' | 'widget' | 'quick_answers' | 'first_chat';
	done: boolean;
	route: string;
}

export interface StatsResponse {
	days: Period;
	totals: {
		conversations: number;
		questions: number;
		tokens: number;
		cost: number;
		replies_by_source: Record< string, number >;
		free_answers: number;
		average_ai_cost: number;
		saved: number;
	};
	series: DayStats[];
	unanswered: UnansweredQuestion[];
	recent: ConversationSummary[];
	budget: {
		unit: 'currency' | 'tokens';
		daily: number;
		monthly: number;
		day_tokens: number;
		month_tokens: number;
		day_cost: number;
		month_cost: number;
	};
	checklist: ChecklistStep[];
}

export const fetchStats = ( days: Period ) =>
	request< StatsResponse >( `/stats?days=${ days }` );

export interface QuickAnswerInput {
	question: string;
	answer: string;
	language: string;
	enabled: boolean;
}

export const createQuickAnswer = ( input: QuickAnswerInput ) =>
	request< { id: number } >( '/quick-answers', {
		method: 'POST',
		data: input,
	} );

/**
 * Why a question appears in the "couldn't answer" list.
 *
 * @param reason Reason code.
 * @return Label.
 */
export function reasonLabel( reason: UnansweredReason ): string {
	switch ( reason ) {
		case 'site_search':
			return __( 'Answered with site search', 'chatlume-ai-chatbot' );
		case 'not_helpful':
			return __( 'Rated not helpful', 'chatlume-ai-chatbot' );
		case 'no_knowledge':
			return __(
				'Nothing in your knowledge base',
				'chatlume-ai-chatbot'
			);
		case 'ask_ai':
			return __( 'Quick answer missed', 'chatlume-ai-chatbot' );
	}
	return reason;
}

/**
 * Percentage of a budget used, or null when there is no budget.
 *
 * @param used   Amount used.
 * @param budget Budget (0 = none).
 * @return Percentage (0–100+), or null.
 */
export function budgetShare( used: number, budget: number ): number | null {
	return budget > 0 ? Math.round( ( used / budget ) * 100 ) : null;
}
