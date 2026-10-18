import { readFileSync } from 'fs';
import { join } from 'path';

import {
	generalSettingsFormSchema,
	importableSettings,
	mergeSettings,
	settingsSchema,
	type Settings,
} from '../../src/admin/lib/settingsSchema';

const settings: Settings = {
	setup: { completed: false },
	privacy: {
		delete_data_on_uninstall: false,
		store_conversations: true,
		retention_days: 90,
	},
	chatbot: {
		bot_name: '',
		preset: 'support',
		system_prompt: '',
		welcome_message: '',
		starter_questions: [],
		quick_actions: [
			{ label: 'Shop', type: 'url', value: 'https://a.test' },
		],
		temperature: 0.3,
		max_output_tokens: 600,
		history_window: 10,
		max_message_length: 1000,
		language_mode: 'visitor',
		fixed_language: '',
		site_topics_only: false,
		refusal_message: '',
		blocked_words: [],
		offline_message: '',
		error_message: '',
		site_search_message: '',
	},
	quick_answers: { enabled: true, threshold: 0.8, show_as_starters: false },
	security: {
		rate_limit_messages: 20,
		rate_limit_minutes: 10,
		session_hours: 24,
		trusted_proxy: 'none',
		trusted_proxy_ips: [],
	},
	costs: {
		conversation_message_cap: 50,
		budget_unit: 'currency',
		daily_budget: 0,
		monthly_budget: 0,
		cap_behavior: 'site_search',
		alert_email: '',
	},
	cache: { enabled: true, ttl_hours: 24 },
	display: {
		enabled: true,
		preload: false,
		pages_mode: 'all',
		page_ids: [],
		post_types: [],
		url_patterns: [],
		devices: 'all',
		visitors: 'all',
		roles: [],
		hours: {
			enabled: false,
			behavior: 'offline',
			days: {
				mon: { open: true, start: '09:00', end: '17:00' },
				tue: { open: true, start: '09:00', end: '17:00' },
				wed: { open: true, start: '09:00', end: '17:00' },
				thu: { open: true, start: '09:00', end: '17:00' },
				fri: { open: true, start: '09:00', end: '17:00' },
				sat: { open: false, start: '09:00', end: '17:00' },
				sun: { open: false, start: '09:00', end: '17:00' },
			},
		},
	},
	debug: { enabled: false },
	knowledge: {
		post_types: [ 'page', 'post' ],
		include_ids: [],
		exclude_ids: [],
		business_info: '',
		use_embeddings: true,
		top_k: 4,
	},
	streaming: {
		enabled: true,
		max_concurrent: 3,
		max_duration: 60,
		first_token_timeout: 20,
	},
	providers: {
		primary: { provider: '', model: '' },
		fallback: { provider: '', model: '' },
		embeddings: { provider: '', model: '', dimensions: 512 },
		configs: {
			openai: {
				default_model: '',
				timeout: 30,
				extra_headers: [ { name: 'X-A', value: '1' } ],
			},
			custom: { base_url: '', default_model: '', timeout: 30 },
		},
	},
};

describe( 'settings schema', () => {
	it( 'accepts the shape returned by GET /settings', () => {
		expect( settingsSchema.parse( settings ) ).toEqual( settings );
	} );

	it( 'lists exactly the sections the PHP schema defines', () => {
		// The strict schema rejects any section it does not know, so a section
		// added in PHP without a matching entry here breaks every settings save.
		const php = readFileSync(
			join( __dirname, '../../includes/Settings/SettingsSchema.php' ),
			'utf8'
		);
		const body = php.slice(
			php.indexOf( 'public static function definition()' ),
			php.indexOf( 'private static function providers_section()' )
		);
		const sections = [ ...body.matchAll( /^\t{3}'([a-z_]+)'\s+=>/gm ) ].map(
			( match ) => match[ 1 ]
		);

		expect( sections.length ).toBeGreaterThan( 0 );
		expect( Object.keys( settingsSchema.shape ).sort() ).toEqual(
			sections.sort()
		);
	} );

	it( 'rejects out-of-range streaming limits', () => {
		expect(
			settingsSchema.safeParse( {
				...settings,
				streaming: { ...settings.streaming, max_concurrent: 0 },
			} ).success
		).toBe( false );
	} );

	it( 'rejects wrong types and unknown keys', () => {
		expect(
			settingsSchema.safeParse( {
				...settings,
				privacy: { delete_data_on_uninstall: 'yes' },
			} ).success
		).toBe( false );
		expect(
			settingsSchema.safeParse( { ...settings, extra: true } ).success
		).toBe( false );
		expect(
			settingsSchema.safeParse( {
				...settings,
				providers: { ...settings.providers, primary: { provider: 1 } },
			} ).success
		).toBe( false );
	} );

	it( 'validates the General settings form', () => {
		const form = {
			security: settings.security,
			streaming: settings.streaming,
			cache: settings.cache,
			privacy: settings.privacy,
			debug: settings.debug,
		};
		expect( generalSettingsFormSchema.parse( form ) ).toEqual( form );
		expect(
			generalSettingsFormSchema.safeParse( {
				...form,
				privacy: { ...form.privacy, other: 1 },
			} ).success
		).toBe( false );
		expect(
			generalSettingsFormSchema.safeParse( {
				...form,
				cache: { ...form.cache, ttl_hours: 0 },
			} ).success
		).toBe( false );
	} );

	it( 'accepts IPv4 and IPv6 proxy addresses and ranges only', () => {
		const withIps = ( ips: string[] ) =>
			generalSettingsFormSchema.safeParse( {
				security: { ...settings.security, trusted_proxy_ips: ips },
				streaming: settings.streaming,
				cache: settings.cache,
				privacy: settings.privacy,
				debug: settings.debug,
			} ).success;

		expect(
			withIps( [
				'203.0.113.7',
				'198.51.100.0/24',
				'2001:db8::/32',
				'::1',
			] )
		).toBe( true );
		expect( withIps( [ 'example.com' ] ) ).toBe( false );
		expect( withIps( [ '10.0.0.1; drop' ] ) ).toBe( false );
	} );
} );

describe( 'importableSettings', () => {
	it( 'reads exported files and bare settings, skipping setup and unknown keys', () => {
		expect(
			importableSettings( {
				plugin: 'chatlume-ai-chatbot',
				settings: {
					setup: { completed: true },
					cache: { enabled: false },
					other: { a: 1 },
				},
			} )
		).toEqual( { cache: { enabled: false } } );
		expect(
			importableSettings( { streaming: { enabled: false } } )
		).toEqual( { streaming: { enabled: false } } );
	} );

	it( 'rejects files without settings', () => {
		expect( importableSettings( null ) ).toBeNull();
		expect( importableSettings( [ 1, 2 ] ) ).toBeNull();
		expect(
			importableSettings( { setup: { completed: true } } )
		).toBeNull();
		expect( importableSettings( { cache: 'yes' } ) ).toBeNull();
	} );
} );

describe( 'mergeSettings', () => {
	it( 'deep-merges a partial update without touching other sections', () => {
		const next = mergeSettings( settings, {
			privacy: { delete_data_on_uninstall: true },
		} );

		expect( next.privacy.delete_data_on_uninstall ).toBe( true );
		expect( next.setup ).toBe( settings.setup );
		expect( next ).not.toBe( settings );
		expect( settings.privacy.delete_data_on_uninstall ).toBe( false );
	} );

	it( 'merges nested provider configs and keeps the other providers', () => {
		const next = mergeSettings( settings, {
			providers: {
				primary: { provider: 'openai' },
				configs: { openai: { timeout: 60 } },
			},
		} );

		expect( next.providers.primary ).toEqual( {
			provider: 'openai',
			model: '',
		} );
		expect( next.providers.configs.openai ).toEqual( {
			default_model: '',
			timeout: 60,
			extra_headers: [ { name: 'X-A', value: '1' } ],
		} );
		expect( next.providers.configs.custom ).toBe(
			settings.providers.configs.custom
		);
	} );

	it( 'replaces lists instead of merging them', () => {
		const next = mergeSettings( settings, {
			providers: { configs: { openai: { extra_headers: [] } } },
		} );

		expect( next.providers.configs.openai.extra_headers ).toEqual( [] );
	} );

	it( 'ignores unknown sections', () => {
		const next = mergeSettings( settings, {
			unknown: { a: 1 },
		} as unknown as Parameters< typeof mergeSettings >[ 1 ] );
		expect( next ).toEqual( settings );
	} );
} );
