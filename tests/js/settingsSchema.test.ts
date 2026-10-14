import {
	dataPrivacyFormSchema,
	mergeSettings,
	settingsSchema,
	type Settings,
} from '../../src/admin/lib/settingsSchema';

const settings: Settings = {
	setup: { completed: false },
	privacy: { delete_data_on_uninstall: false },
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
				privacy: { delete_data_on_uninstall: true },
			} )
		).toEqual( { privacy: { delete_data_on_uninstall: true } } );
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
