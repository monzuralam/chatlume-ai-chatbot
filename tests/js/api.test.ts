import { ApiError, toApiError } from '../../src/admin/lib/api';

describe( 'toApiError', () => {
	it( 'prefers the per-parameter explanation for invalid parameters', () => {
		const error = toApiError( {
			code: 'rest_invalid_param',
			message: 'Invalid parameter(s): privacy',
			data: {
				status: 400,
				params: {
					privacy:
						'The setting "privacy.delete_data_on_uninstall" must be true or false.',
				},
			},
		} );

		expect( error ).toBeInstanceOf( ApiError );
		expect( error.message ).toBe(
			'The setting "privacy.delete_data_on_uninstall" must be true or false.'
		);
		expect( error.code ).toBe( 'rest_invalid_param' );
		expect( error.status ).toBe( 400 );
	} );

	it( 'uses the WordPress error message and status', () => {
		const error = toApiError( {
			code: 'rest_forbidden',
			message: 'Sorry, you are not allowed to manage Chatlume settings.',
			data: { status: 403 },
		} );

		expect( error.message ).toBe(
			'Sorry, you are not allowed to manage Chatlume settings.'
		);
		expect( error.status ).toBe( 403 );
	} );

	it( 'falls back to a friendly message for unknown failures', () => {
		const error = toApiError( new TypeError( '' ) );

		expect( error.code ).toBe( 'unknown_error' );
		expect( error.status ).toBe( 0 );
		expect( error.message ).toMatch( /Something went wrong/ );
	} );

	it( 'returns ApiError instances unchanged', () => {
		const original = new ApiError( 'x', 'y', 500 );
		expect( toApiError( original ) ).toBe( original );
	} );
} );
