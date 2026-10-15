/**
 * Display rules decided in the browser: device and business hours.
 *
 * They run here rather than on the server so cached pages stay correct.
 * `isOpen()` mirrors `Chatlume\Frontend\DisplayRules::is_open()`.
 */
import type { DisplayConfig, DayHours } from '../types';

const DAYS = [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ] as const;

/** Screens narrower than this count as mobile. */
export const MOBILE_BELOW = 768;

/**
 * Minutes since midnight of an HH:MM time.
 *
 * @param time Time.
 * @return Minutes.
 */
function minutes( time: string ): number {
	const [ hours, mins ] = ( time || '00:00' ).split( ':' ).map( Number );
	return Math.min( 1439, Math.max( 0, ( hours || 0 ) * 60 + ( mins || 0 ) ) );
}

/**
 * Day of the week (0 = Sunday) and minutes since midnight at a moment in a
 * timezone: an IANA name ("Europe/Berlin") or an offset ("+05:30").
 *
 * @param date     Moment.
 * @param timezone Timezone.
 * @return Local weekday and minutes.
 */
export function zonedTime(
	date: Date,
	timezone: string
): { weekday: number; minutes: number } {
	const offset = /^([+-])(\d{2}):(\d{2})$/.exec( timezone || '' );
	if ( offset || ! timezone || timezone === 'UTC' ) {
		const sign = offset?.[ 1 ] === '-' ? -1 : 1;
		const shift = offset
			? sign * ( Number( offset[ 2 ] ) * 60 + Number( offset[ 3 ] ) )
			: 0;
		const local = new Date( date.getTime() + shift * 60_000 );
		return {
			weekday: local.getUTCDay(),
			minutes: local.getUTCHours() * 60 + local.getUTCMinutes(),
		};
	}

	try {
		const parts = new Intl.DateTimeFormat( 'en-US', {
			timeZone: timezone,
			weekday: 'short',
			hour: '2-digit',
			minute: '2-digit',
			hourCycle: 'h23',
		} ).formatToParts( date );
		const get = ( type: string ) =>
			parts.find( ( part ) => part.type === type )?.value ?? '';
		const weekday = [
			'Sun',
			'Mon',
			'Tue',
			'Wed',
			'Thu',
			'Fri',
			'Sat',
		].indexOf( get( 'weekday' ) );
		return {
			weekday: Math.max( 0, weekday ),
			minutes:
				( Number( get( 'hour' ) ) % 24 ) * 60 +
				Number( get( 'minute' ) ),
		};
	} catch {
		// Unknown timezone: fall back to UTC.
		return zonedTime( date, 'UTC' );
	}
}

/**
 * Whether the business is open. An end before the start runs past
 * midnight; equal times mean the whole day.
 *
 * @param days     Hours per day key.
 * @param date     Moment.
 * @param timezone Site timezone.
 * @return True when open.
 */
export function isOpen(
	days: Partial< Record< string, DayHours > >,
	date: Date,
	timezone: string
): boolean {
	const { weekday, minutes: now } = zonedTime( date, timezone );
	const read = ( index: number ) => {
		const day = days[ DAYS[ index ] ];
		return {
			open: !! day?.open,
			start: minutes( day?.start ?? '00:00' ),
			end: minutes( day?.end ?? '00:00' ),
		};
	};
	const today = read( weekday );

	if ( today.open ) {
		if ( today.start === today.end ) {
			return true;
		}
		if (
			today.start < today.end &&
			now >= today.start &&
			now < today.end
		) {
			return true;
		}
		if ( today.end < today.start && now >= today.start ) {
			return true;
		}
	}

	const yesterday = read( ( weekday + 6 ) % 7 );
	return (
		yesterday.open && yesterday.end < yesterday.start && now < yesterday.end
	);
}

/**
 * Whether business hours are on and the business is closed.
 *
 * @param display Display rules.
 * @param date    Moment.
 * @return True when closed.
 */
export function isClosed(
	display: DisplayConfig | undefined,
	date: Date = new Date()
): boolean {
	const hours = display?.hours;
	return !! hours?.enabled && ! isOpen( hours.days, date, hours.timezone );
}

/**
 * Whether the floating launcher belongs on this device and at this time.
 *
 * @param display Display rules.
 * @param width   Viewport width.
 * @param date    Moment.
 * @return True to show it.
 */
export function showFloating(
	display: DisplayConfig | undefined,
	width: number,
	date: Date = new Date()
): boolean {
	if ( ! display ) {
		return true;
	}
	if ( display.devices === 'mobile' && width >= MOBILE_BELOW ) {
		return false;
	}
	if ( display.devices === 'desktop' && width < MOBILE_BELOW ) {
		return false;
	}
	return ! ( display.hours.behavior === 'hide' && isClosed( display, date ) );
}
