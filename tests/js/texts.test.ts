/**
 * The Widget texts page data.
 */
import {
	fromTextsForm,
	labelGroups,
	matchesSearch,
	sourceLabel,
	toTextsForm,
	type TextsState,
} from '../../src/admin/lib/texts';

const state: TextsState = {
	plugin: 'polylang',
	languages: [
		{ code: 'en', locale: 'en_US', name: 'English' },
		{ code: 'bn', locale: 'bn_BD', name: 'বাংলা' },
	],
	main: 'en',
	language: 'bn',
	labels: {
		placeholder: { default: 'বার্তা লিখুন…', value: '' },
		send: { default: 'পাঠান', value: 'পাঠাও' },
		futureLabel: { default: 'New', value: '' },
	},
	content: [
		{
			path: 'chatbot.welcome_message',
			group: 'chatbot',
			format: 'rich',
			max: 1000,
			main: 'Hello!',
			value: 'স্বাগতম!',
		},
		{
			path: 'chatbot.starter_questions.1',
			group: 'chatbot',
			format: 'text',
			max: 120,
			main: 'Prices?',
			value: '',
		},
	],
};

describe( 'texts form', () => {
	it( 'keeps content paths out of form field names', () => {
		expect( toTextsForm( state ) ).toEqual( {
			labels: { placeholder: '', send: 'পাঠাও', futureLabel: '' },
			content: [
				{ path: 'chatbot.welcome_message', value: 'স্বাগতম!' },
				{ path: 'chatbot.starter_questions.1', value: '' },
			],
		} );
	} );

	it( 'sends only filled, trimmed texts', () => {
		expect(
			fromTextsForm( {
				labels: { placeholder: '  ', send: ' পাঠাও ' },
				content: [
					{ path: 'chatbot.welcome_message', value: ' স্বাগতম! ' },
					{ path: 'chatbot.starter_questions.1', value: '' },
				],
			} )
		).toEqual( {
			labels: { send: 'পাঠাও' },
			content: { 'chatbot.welcome_message': 'স্বাগতম!' },
		} );
	} );
} );

describe( 'labelGroups', () => {
	it( 'groups known labels and collects the rest under Other', () => {
		const groups = labelGroups( Object.keys( state.labels ) );
		expect( groups.map( ( group ) => group.id ) ).toEqual( [
			'composer',
			'other',
		] );
		expect( groups[ 0 ].keys ).toEqual( [ 'placeholder', 'send' ] );
		expect( groups[ 1 ].keys ).toEqual( [ 'futureLabel' ] );
	} );
} );

describe( 'sourceLabel', () => {
	it( 'names numbered and fixed texts', () => {
		expect( sourceLabel( 'chatbot.starter_questions.1' ) ).toBe(
			'Starter question 2'
		);
		expect( sourceLabel( 'chatbot.quick_actions.0.label' ) ).toBe(
			'Quick action 1: button text'
		);
		expect( sourceLabel( 'chatbot.quick_actions.2.value' ) ).toBe(
			'Quick action 3: message sent'
		);
		expect( sourceLabel( 'theme.brand.headerTitle' ) ).toBe(
			'Header title'
		);
		expect( sourceLabel( 'unknown.path' ) ).toBe( 'unknown.path' );
	} );
} );

describe( 'matchesSearch', () => {
	it( 'matches any text, ignoring case', () => {
		expect( matchesSearch( '', 'anything' ) ).toBe( true );
		expect( matchesSearch( 'WELCOME', 'Welcome message' ) ).toBe( true );
		expect( matchesSearch( 'স্বাগ', 'Hello', 'স্বাগতম!' ) ).toBe( true );
		expect( matchesSearch( 'price', 'Hello' ) ).toBe( false );
	} );
} );
