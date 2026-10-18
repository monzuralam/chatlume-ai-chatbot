/**
 * Chatbot page helpers: form conversion, validation, and the preview.
 */
import {
	chatbotFormSchema,
	fromChatbotForm,
	previewBehavior,
	previewQuickAction,
	toChatbotForm,
	type ChatbotInfo,
} from '../../src/admin/lib/chatbot';
import { fieldMessage, issuesToErrors } from '../../src/admin/lib/forms';
import type { Settings } from '../../src/admin/lib/settingsSchema';

const chatbot: Settings[ 'chatbot' ] = {
	bot_name: '',
	preset: 'sales',
	system_prompt: '',
	welcome_message: '',
	starter_questions: [ 'Prices?' ],
	quick_actions: [],
	temperature: 0.3,
	max_output_tokens: 600,
	history_window: 10,
	max_message_length: 1000,
	language_mode: 'visitor',
	fixed_language: '',
	site_topics_only: false,
	refusal_message: '',
	blocked_words: [ 'casino', 'free money' ],
	offline_message: '',
	error_message: '',
	site_search_message: '',
	voice_input: true,
	read_aloud: false,
};

const settings = {
	chatbot,
	quick_answers: { enabled: true, threshold: 0.8, show_as_starters: false },
	costs: {
		conversation_message_cap: 50,
		budget_unit: 'currency',
		daily_budget: 0,
		monthly_budget: 0,
		cap_behavior: 'site_search',
		alert_email: '',
	},
} as unknown as Settings;

const defaults: ChatbotInfo[ 'defaults' ] = {
	bot_name: 'Assistant',
	refusal: 'Sorry.',
	offline: 'Offline.',
	error: 'Error.',
	site_search: 'Pages:',
	presets: {
		support: {
			prompt: 'p1',
			welcome: 'Hi support',
			starters: [ 'A', 'B' ],
		},
		sales: {
			prompt: 'p2',
			welcome: 'Hi sales',
			starters: [ 'C', 'D', 'E' ],
		},
		faq: { prompt: 'p3', welcome: 'Hi faq', starters: [] },
		blank: { prompt: '', welcome: 'Hi', starters: [] },
	},
};

describe( 'chatbot form conversion', () => {
	it( 'round-trips lists through the text boxes and starter slots', () => {
		const form = toChatbotForm( settings );

		expect( form.chatbot.blocked_words ).toBe( 'casino\nfree money' );
		expect( form.chatbot.starter_questions ).toEqual( [
			'Prices?',
			'',
			'',
			'',
		] );

		form.chatbot.blocked_words = ' poker , \n\nlottery \n';
		form.chatbot.starter_questions = [ '', ' Shipping? ', '', '' ];
		const stored = fromChatbotForm( form );

		expect( stored.chatbot.blocked_words ).toEqual( [
			'poker',
			'lottery',
		] );
		expect( stored.chatbot.starter_questions ).toEqual( [ 'Shipping?' ] );
		expect( chatbotFormSchema.safeParse( stored ).success ).toBe( true );
	} );

	it( 'reports problems on the right fields', () => {
		const form = toChatbotForm( settings );
		form.chatbot.blocked_words = 'x'.repeat( 61 );
		form.chatbot.quick_actions = [
			{ label: 'y'.repeat( 41 ), type: 'url', value: '' },
		];
		form.costs.alert_email = 'not-an-email';

		const result = chatbotFormSchema.safeParse( fromChatbotForm( form ) );
		expect( result.success ).toBe( false );
		const errors = issuesToErrors< typeof form >(
			result.success ? [] : result.error.issues,
			[ 'chatbot.blocked_words' ]
		);

		expect( errors.chatbot?.blocked_words?.type ).toBe( 'too_big' );
		expect( errors.chatbot?.quick_actions?.[ 0 ]?.label?.type ).toBe(
			'too_big'
		);
		expect( errors.costs?.alert_email ).toBeDefined();
		expect( fieldMessage( errors.chatbot?.blocked_words ) ).toBe(
			'This is too long or too large.'
		);
	} );
} );

describe( 'previewQuickAction', () => {
	it( 'builds the same links as the server', () => {
		expect(
			previewQuickAction( {
				label: 'Mail',
				type: 'email',
				value: 'a@b.test',
			} )
		).toEqual( { label: 'Mail', type: 'email', href: 'mailto:a@b.test' } );
		expect(
			previewQuickAction( {
				label: 'Call',
				type: 'phone',
				value: '+44 (20) 1234',
			} )
		).toEqual( { label: 'Call', type: 'phone', href: 'tel:+44201234' } );
		expect(
			previewQuickAction( {
				label: 'WA',
				type: 'whatsapp',
				value: '+44 7700 900',
			} )
		).toEqual( {
			label: 'WA',
			type: 'whatsapp',
			href: 'https://wa.me/447700900',
		} );
		expect(
			previewQuickAction( {
				label: 'Ask',
				type: 'message',
				value: 'Prices?',
			} )
		).toEqual( { label: 'Ask', type: 'message', message: 'Prices?' } );
	} );

	it( 'leaves out unusable actions', () => {
		expect(
			previewQuickAction( {
				label: 'X',
				type: 'url',
				value: 'javascript:alert(1)',
			} )
		).toBeNull();
		expect(
			previewQuickAction( {
				label: '',
				type: 'url',
				value: 'https://a.test',
			} )
		).toBeNull();
		expect(
			previewQuickAction( { label: 'X', type: 'email', value: 'nope' } )
		).toBeNull();
	} );
} );

describe( 'previewBehavior', () => {
	it( 'uses the preset defaults for empty fields', () => {
		const behavior = previewBehavior(
			{ ...chatbot, starter_questions: [] },
			defaults
		);

		expect( behavior.bot_name ).toBe( 'Assistant' );
		expect( behavior.welcome_message ).toBe( 'Hi sales' );
		expect( behavior.starter_questions ).toEqual( [ 'C', 'D', 'E' ] );
	} );

	it( 'shows what the admin typed', () => {
		const behavior = previewBehavior(
			{
				...chatbot,
				bot_name: 'Ava',
				welcome_message: 'Hello!',
				quick_actions: [
					{ label: 'Shop', type: 'url', value: 'https://a.test' },
				],
			},
			defaults
		);

		expect( behavior.bot_name ).toBe( 'Ava' );
		expect( behavior.welcome_message ).toBe( 'Hello!' );
		expect( behavior.starter_questions ).toEqual( [ 'Prices?' ] );
		expect( behavior.quick_actions ).toEqual( [
			{ label: 'Shop', type: 'url', href: 'https://a.test' },
		] );
		// The voice switches show in the preview right away.
		expect( behavior.voice_input ).toBe( true );
		expect( behavior.read_aloud ).toBe( false );
	} );
} );

describe( 'quick action validation', () => {
	it( 'explains unusable buttons on the field to fix', () => {
		const form = toChatbotForm( settings );
		form.chatbot.quick_actions = [
			{ label: 'Shop', type: 'url', value: 'shop' },
			{ label: '', type: 'email', value: 'a@b.test' },
			{ label: 'Call', type: 'phone', value: '+44 20 7946 0000' },
			{ label: '', type: 'url', value: '' },
		];
		const result = chatbotFormSchema.safeParse( fromChatbotForm( form ) );
		const errors = issuesToErrors< typeof form >(
			result.success ? [] : result.error.issues
		);

		expect(
			fieldMessage( errors.chatbot?.quick_actions?.[ 0 ]?.value )
		).toBe( 'Enter a web address starting with http:// or https://.' );
		expect(
			fieldMessage( errors.chatbot?.quick_actions?.[ 1 ]?.label )
		).toBe( 'Enter the button text.' );
		expect( errors.chatbot?.quick_actions?.[ 2 ] ).toBeUndefined();
		expect( errors.chatbot?.quick_actions?.[ 3 ] ).toBeUndefined();
	} );
} );
