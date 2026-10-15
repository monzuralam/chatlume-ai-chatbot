import { readFileSync } from 'fs';
import { join } from 'path';

import {
	dataPrivacyFormSchema,
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
	chatbot: { bot_name: '' },
	quick_answers: { enabled: true },
	security: { rate_limit_messages: 20 },
	costs: { daily_budget: 0 },
	cache: { enabled: true },
	display: { enabled: true, preload: false },
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
		expect(
			dataPrivacyFormSchema.safeParse( {
				privacy: { delete_data_on_uninstall: true, other: 1 },
			} ).success
		).toBe( false );
	} );

	it( 'validates the Data & privacy form', () => {
		expect(
			dataPrivacyFormSchema.parse( {
				privacy: {
					delete_data_on_uninstall: true,
					store_conversations: false,
					retention_days: 30,
				},
			} ).privacy.retention_days
		).toBe( 30 );
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
