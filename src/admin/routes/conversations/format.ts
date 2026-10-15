/**
 * Display helpers for the conversation log.
 */
import { __ } from '@wordpress/i18n';

/**
 * Readable name of a reply source.
 *
 * @param source Source code.
 * @return Label.
 */
export function sourceLabel( source: string ): string {
	switch ( source ) {
		case 'ai':
			return __( 'AI', 'chatlume-ai-chatbot' );
		case 'quick_answer':
			return __( 'Quick answer', 'chatlume-ai-chatbot' );
		case 'cache':
			return __( 'Cached answer', 'chatlume-ai-chatbot' );
		case 'fallback_model':
			return __( 'Fallback AI', 'chatlume-ai-chatbot' );
		case 'site_search':
			return __( 'Site search', 'chatlume-ai-chatbot' );
		case 'guardrail':
			return __( 'Guardrail', 'chatlume-ai-chatbot' );
		case 'offline':
			return __( 'Offline message', 'chatlume-ai-chatbot' );
		default:
			return source;
	}
}

/**
 * An estimated cost in US dollars (more decimals for small amounts).
 *
 * @param value Amount.
 * @return Text.
 */
export function formatCost( value: number ): string {
	return new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency: 'USD',
		minimumFractionDigits: 2,
		maximumFractionDigits: value > 0 && value < 0.01 ? 5 : 2,
	} ).format( value );
}

/**
 * Date and time in the admin's locale.
 *
 * @param iso      ISO 8601 timestamp.
 * @param timeOnly Show only the time.
 * @return Text.
 */
export function formatDateTime( iso: string, timeOnly = false ): string {
	if ( ! iso ) {
		return '';
	}
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}
	return new Intl.DateTimeFormat(
		undefined,
		timeOnly
			? { hour: 'numeric', minute: '2-digit' }
			: { dateStyle: 'medium', timeStyle: 'short' }
	).format( date );
}
