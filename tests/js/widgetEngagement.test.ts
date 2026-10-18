/**
 * Proactive invitations and voice.
 */
import {
	isDue,
	recordInvitation,
	scrolledPercent,
	watchTriggers,
} from '../../src/widget/lib/triggers';
import {
	canListen,
	canSpeak,
	listen,
	onSpeaking,
	speak,
	speakingId,
	stopSpeaking,
} from '../../src/widget/lib/voice';
import { looksLikeEmail } from '../../src/widget/app/Engagement';
import { sampleMessages, withDefaults } from '../../src/widget/app/samples';
import type { TriggerConfig } from '../../src/widget/types';

const DAY = 86400000;

const trigger = ( change: Partial< TriggerConfig > = {} ): TriggerConfig => ( {
	action: 'teaser',
	message: 'Hi!',
	delay: 0,
	scroll: 0,
	exit: false,
	frequency: 'always',
	skipReturning: false,
	...change,
} );

describe( 'isDue', () => {
	it( 'applies each frequency', () => {
		const now = 10 * DAY;
		expect( isDue( 'always', now, true, now ) ).toBe( true );
		expect( isDue( 'session', 0, false, now ) ).toBe( true );
		expect( isDue( 'session', 0, true, now ) ).toBe( false );
		expect( isDue( 'day', now - DAY + 1, false, now ) ).toBe( false );
		expect( isDue( 'day', now - DAY, false, now ) ).toBe( true );
		expect( isDue( 'week', now - 6 * DAY, false, now ) ).toBe( false );
		expect( isDue( 'week', now - 7 * DAY, false, now ) ).toBe( true );
		expect( isDue( 'day', 0, true, now ) ).toBe( false );
	} );

	it( 'measures how far the page was read', () => {
		expect( scrolledPercent( 0, 800, 800 ) ).toBe( 100 );
		expect( scrolledPercent( 0, 500, 2000 ) ).toBe( 25 );
		expect( scrolledPercent( 1500, 500, 2000 ) ).toBe( 100 );
	} );
} );

describe( 'watchTriggers', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		window.localStorage.clear();
		window.sessionStorage.clear();
	} );
	afterEach( () => jest.useRealTimers() );

	it( 'fires once after the delay', () => {
		const fire = jest.fn();
		watchTriggers( trigger( { delay: 10 } ), fire );

		jest.advanceTimersByTime( 9999 );
		expect( fire ).not.toHaveBeenCalled();
		jest.advanceTimersByTime( 1 );
		expect( fire ).toHaveBeenCalledTimes( 1 );
		jest.advanceTimersByTime( 60000 );
		expect( fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fires right after loading when no condition is set', () => {
		const fire = jest.fn();
		watchTriggers( trigger(), fire );
		jest.advanceTimersByTime( 1500 );
		expect( fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fires on scrolling far enough', () => {
		const fire = jest.fn();
		Object.defineProperty( document.documentElement, 'scrollHeight', {
			configurable: true,
			value: 4000,
		} );
		Object.defineProperty( window, 'innerHeight', {
			configurable: true,
			value: 1000,
		} );
		watchTriggers( trigger( { scroll: 50 } ), fire );

		Object.defineProperty( window, 'scrollY', {
			configurable: true,
			value: 500,
		} );
		window.dispatchEvent( new Event( 'scroll' ) );
		expect( fire ).not.toHaveBeenCalled();

		Object.defineProperty( window, 'scrollY', {
			configurable: true,
			value: 1000,
		} );
		window.dispatchEvent( new Event( 'scroll' ) );
		expect( fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fires on exit intent with a mouse only', () => {
		const original = window.matchMedia;
		const fire = jest.fn();
		window.matchMedia = jest.fn().mockReturnValue( { matches: true } );
		watchTriggers( trigger( { exit: true, delay: 600 } ), fire );

		document.dispatchEvent(
			new MouseEvent( 'mouseout', { clientY: 200, bubbles: true } )
		);
		expect( fire ).not.toHaveBeenCalled();
		document.dispatchEvent(
			new MouseEvent( 'mouseout', { clientY: 0, bubbles: true } )
		);
		expect( fire ).toHaveBeenCalledTimes( 1 );

		// Touch screens: no exit intent.
		window.matchMedia = jest.fn().mockReturnValue( { matches: false } );
		const touch = jest.fn();
		watchTriggers( trigger( { exit: true, delay: 600 } ), touch );
		document.dispatchEvent(
			new MouseEvent( 'mouseout', { clientY: 0, bubbles: true } )
		);
		expect( touch ).not.toHaveBeenCalled();
		window.matchMedia = original;
	} );

	it( 'respects the frequency and visitors who already chatted', () => {
		recordInvitation();
		const fire = jest.fn();
		watchTriggers( trigger( { frequency: 'day' } ), fire );
		jest.advanceTimersByTime( 5000 );
		expect( fire ).not.toHaveBeenCalled();

		window.localStorage.clear();
		window.sessionStorage.clear();
		window.localStorage.setItem( 'chatlume:seen', 'true' );
		watchTriggers( trigger( { skipReturning: true } ), fire );
		jest.advanceTimersByTime( 5000 );
		expect( fire ).not.toHaveBeenCalled();
	} );

	it( 'stops watching when asked', () => {
		const fire = jest.fn();
		const stop = watchTriggers( trigger( { delay: 5 } ), fire );
		stop();
		jest.advanceTimersByTime( 10000 );
		expect( fire ).not.toHaveBeenCalled();
	} );
} );

describe( 'voice', () => {
	type Scope = Record< string, unknown >;
	const scope = window as unknown as Scope;

	afterEach( () => {
		delete scope.webkitSpeechRecognition;
		delete scope.speechSynthesis;
		delete scope.SpeechSynthesisUtterance;
	} );

	it( 'is hidden where the browser has no speech support', () => {
		expect( canListen() ).toBe( false );
		expect( canSpeak() ).toBe( false );
	} );

	it( 'reports recognized words and blocked microphones', () => {
		const instances: Array< Record< string, unknown > > = [];
		scope.webkitSpeechRecognition = class {
			start = jest.fn();
			stop = jest.fn();
			constructor() {
				instances.push( this as unknown as Record< string, unknown > );
			}
		};
		document.documentElement.lang = 'bn-BD';
		const texts: string[] = [];
		const errors: string[] = [];
		const stop = listen( {
			onText: ( text ) => texts.push( text ),
			onEnd: () => texts.push( '(end)' ),
			onError: ( kind ) => errors.push( kind ),
		} );
		const recognition = instances[ 0 ] as {
			lang: string;
			onresult: ( event: unknown ) => void;
			onerror: ( event: unknown ) => void;
			onend: () => void;
			stop: jest.Mock;
		};

		expect( canListen() ).toBe( true );
		expect( recognition.lang ).toBe( 'bn-BD' );
		recognition.onresult( {
			resultIndex: 0,
			results: [ { isFinal: false, 0: { transcript: 'when are' } } ],
		} );
		recognition.onresult( {
			resultIndex: 0,
			results: [
				{ isFinal: true, 0: { transcript: 'when are you' } },
				{ isFinal: false, 0: { transcript: ' open' } },
			],
		} );
		recognition.onerror( { error: 'no-speech' } );
		recognition.onerror( { error: 'not-allowed' } );
		recognition.onend();
		stop();

		expect( texts ).toEqual( [ 'when are', 'when are you open', '(end)' ] );
		expect( errors ).toEqual( [ 'blocked' ] );
		expect( recognition.stop ).toHaveBeenCalled();
	} );

	it( 'reads one answer at a time and reports which', () => {
		const spoken: Array< { text: string; onend?: () => void } > = [];
		scope.SpeechSynthesisUtterance = class {
			text: string;
			lang = '';
			onend?: () => void;
			constructor( text: string ) {
				this.text = text;
			}
		};
		scope.speechSynthesis = {
			speak: ( utterance: { text: string; onend?: () => void } ) =>
				spoken.push( utterance ),
			cancel: jest.fn(),
		};
		const seen: Array< string | null > = [];
		const unsubscribe = onSpeaking( ( id ) => seen.push( id ) );

		speak( 'a', 'First answer' );
		speak( 'b', 'Second answer' );
		expect( speakingId() ).toBe( 'b' );
		// The first reading ending late does not clear the second.
		spoken[ 0 ].onend?.();
		expect( speakingId() ).toBe( 'b' );
		spoken[ 1 ].onend?.();
		expect( speakingId() ).toBe( null );
		speak( 'c', 'Third' );
		stopSpeaking();
		unsubscribe();

		expect( seen ).toEqual( [ 'a', 'b', null, 'c', null ] );
		expect( spoken.map( ( utterance ) => utterance.text ) ).toEqual( [
			'First answer',
			'Second answer',
			'Third',
		] );
	} );
} );

describe( 'lead form helpers', () => {
	it( 'checks email addresses before sending', () => {
		expect( looksLikeEmail( 'ana@example.test' ) ).toBe( true );
		expect( looksLikeEmail( ' ana@shop.co.uk ' ) ).toBe( true );
		expect( looksLikeEmail( 'ana@' ) ).toBe( false );
		expect( looksLikeEmail( 'ana example@test.com' ) ).toBe( false );
		expect( looksLikeEmail( '' ) ).toBe( false );
	} );

	it( 'previews the lead form on an empty chat', () => {
		expect(
			sampleMessages( 'lead', withDefaults( {} ), { offline: 'Away' } )
		).toEqual( [] );
	} );
} );
