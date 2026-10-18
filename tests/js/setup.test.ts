/**
 * Setup wizard helpers.
 */
import type { ModelInfo, ProviderSummary } from '../../src/admin/lib/providers';
import {
	freeTier,
	orderProviders,
	pickModel,
	wizardSteps,
} from '../../src/admin/lib/setup';

const provider = ( id: string, kind = 'openai-compatible' ) =>
	( { id, kind } ) as unknown as ProviderSummary;

const model = ( id: string, extra: Partial< ModelInfo > = {} ): ModelInfo => ( {
	id,
	label: id,
	kind: 'chat',
	context_length: 0,
	input_price: null,
	output_price: null,
	free: false,
	recommended: false,
	created: 0,
	...extra,
} );

describe( 'orderProviders', () => {
	it( 'puts providers connected in WordPress first, then popular ones', () => {
		const ordered = orderProviders( [
			provider( 'groq' ),
			provider( 'gemini', 'native' ),
			provider( 'custom' ),
			provider( 'wp-core', 'core' ),
			provider( 'openai' ),
			provider( 'anthropic', 'native' ),
		] ).map( ( item ) => item.id );

		expect( ordered ).toEqual( [
			'wp-core',
			'openai',
			'anthropic',
			'gemini',
			'groq',
			'custom',
		] );
	} );
} );

describe( 'pickModel', () => {
	it( 'prefers recommended, then free, then any chat model', () => {
		expect(
			pickModel( [
				model( 'embed', { kind: 'embedding', recommended: true } ),
				model( 'a' ),
				model( 'b', { free: true } ),
				model( 'c', { recommended: true } ),
			] )
		).toBe( 'c' );
		expect(
			pickModel( [ model( 'a' ), model( 'b', { free: true } ) ] )
		).toBe( 'b' );
		expect( pickModel( [ model( 'a' ) ] ) ).toBe( 'a' );
		expect( pickModel( [] ) ).toBe( '' );
	} );
} );

describe( 'wizard steps', () => {
	it( 'are shorter without an AI', () => {
		expect( wizardSteps( false ) ).toEqual( [
			'provider',
			'guide',
			'key',
			'model',
			'look',
			'done',
		] );
		expect( wizardSteps( true ) ).toEqual( [ 'provider', 'look', 'done' ] );
	} );

	it( 'notes free options', () => {
		expect( freeTier( 'gemini' ) ).toMatch( /free tier/ );
		expect( freeTier( 'openai' ) ).toBeNull();
	} );
} );
