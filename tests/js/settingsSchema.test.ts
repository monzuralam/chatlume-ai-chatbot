import {
	dataPrivacyFormSchema,
	mergeSettings,
	settingsSchema,
	type Settings,
} from '../../src/admin/lib/settingsSchema';

const settings: Settings = {
	setup: { completed: false },
	privacy: { delete_data_on_uninstall: false },
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

		expect( next ).toEqual( {
			setup: { completed: false },
			privacy: { delete_data_on_uninstall: true },
		} );
		expect( next ).not.toBe( settings );
		expect( settings.privacy.delete_data_on_uninstall ).toBe( false );
	} );

	it( 'ignores unknown sections', () => {
		const next = mergeSettings( settings, {
			unknown: { a: 1 },
		} as unknown as Parameters< typeof mergeSettings >[ 1 ] );
		expect( next ).toEqual( settings );
	} );
} );
