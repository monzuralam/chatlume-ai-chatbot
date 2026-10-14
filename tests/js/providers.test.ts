import { filterModels } from '../../src/admin/components/providers/ModelPicker';
import {
	formatContext,
	formatPrice,
	providerConfigSchema,
	toProviderForm,
	type ModelInfo,
	type ProviderSummary,
} from '../../src/admin/lib/providers';
import { validateRouting, type RoutingForm } from '../../src/admin/lib/routing';

const model = (
	id: string,
	overrides: Partial< ModelInfo > = {}
): ModelInfo => ( {
	id,
	label: id,
	kind: 'chat',
	context_length: 0,
	input_price: null,
	output_price: null,
	free: false,
	recommended: false,
	created: 0,
	...overrides,
} );

const provider = ( id: string, embeddings: boolean ): ProviderSummary => ( {
	id,
	label: id,
	description: '',
	initials: 'XX',
	kind: 'openai-compatible',
	links: {},
	key_required: true,
	default_base_url: '',
	base_url_editable: false,
	supports: {
		streaming: true,
		embeddings,
		embedding_dimensions: embeddings,
		tools: false,
	},
	fields: [ 'default_model', 'timeout', 'extra_headers' ],
	key: { source: 'none', masked: '', readable: true, constant: '' },
	state: 'needs_key',
	last_test: null,
} );

const routing = ( overrides: Partial< RoutingForm > = {} ): RoutingForm => ( {
	primary: { provider: 'openai', model: 'a' },
	fallback: { provider: '', model: '' },
	embeddings: { provider: '', model: '', dimensions: 512 },
	...overrides,
} );

describe( 'toProviderForm', () => {
	it( 'keeps only the fields the provider supports', () => {
		expect(
			toProviderForm(
				{ default_model: 'm', timeout: 45, base_url: 'x', prices: [] },
				[ 'default_model', 'timeout', 'extra_headers' ]
			)
		).toEqual( { default_model: 'm', timeout: 45, extra_headers: [] } );
	} );

	it( 'fills defaults and optional fields', () => {
		expect(
			toProviderForm( undefined, [
				'base_url',
				'default_model',
				'timeout',
				'extra_headers',
				'prices',
				'app_attribution',
			] )
		).toEqual( {
			base_url: '',
			default_model: '',
			timeout: 30,
			extra_headers: [],
			prices: [],
			app_attribution: false,
		} );
	} );
} );

describe( 'providerConfigSchema', () => {
	const valid = {
		default_model: 'vendor/model:tag',
		timeout: 30,
		extra_headers: [],
	};

	it( 'accepts valid configuration', () => {
		expect( providerConfigSchema.safeParse( valid ).success ).toBe( true );
		expect(
			providerConfigSchema.safeParse( {
				...valid,
				base_url: 'http://127.0.0.1:11434/v1',
			} ).success
		).toBe( true );
	} );

	it.each( [
		[ 'non-http URL', { base_url: 'ftp://example.com' } ],
		[ 'timeout too low', { timeout: 1 } ],
		[ 'timeout too high', { timeout: 500 } ],
		[ 'bad model ID', { default_model: 'has space' } ],
		[
			'bad header name',
			{ extra_headers: [ { name: 'Bad Header', value: '' } ] },
		],
		[
			'negative price',
			{ prices: [ { model: 'm', input: -1, output: 0 } ] },
		],
		[
			'price without model',
			{ prices: [ { model: '', input: 1, output: 1 } ] },
		],
	] )( 'rejects %s', ( _label, override ) => {
		expect(
			providerConfigSchema.safeParse( { ...valid, ...override } ).success
		).toBe( false );
	} );
} );

describe( 'validateRouting', () => {
	const providers = [
		provider( 'openai', true ),
		provider( 'anthropic', false ),
	];

	it( 'accepts a valid setup', () => {
		expect( validateRouting( routing(), providers ) ).toEqual( {} );
	} );

	it( 'rejects a fallback identical to the primary', () => {
		const errors = validateRouting(
			routing( { fallback: { provider: 'openai', model: 'a' } } ),
			providers
		);
		expect( errors ).toHaveProperty( [ 'fallback.model' ] );
	} );

	it( 'rejects an embeddings provider without embeddings', () => {
		const errors = validateRouting(
			routing( {
				embeddings: {
					provider: 'anthropic',
					model: 'x',
					dimensions: 512,
				},
			} ),
			providers
		);
		expect( errors ).toHaveProperty( [ 'embeddings.provider' ] );
	} );

	it( 'requires an embeddings model for an explicit embeddings provider', () => {
		const errors = validateRouting(
			routing( {
				embeddings: { provider: 'openai', model: '', dimensions: 512 },
			} ),
			providers
		);
		expect( errors ).toHaveProperty( [ 'embeddings.model' ] );
	} );

	it( 'clamps dimensions to 0–4096 whole numbers', () => {
		expect(
			validateRouting(
				routing( {
					embeddings: { provider: '', model: '', dimensions: 5000 },
				} ),
				providers
			)
		).toHaveProperty( [ 'embeddings.dimensions' ] );
		expect(
			validateRouting(
				routing( {
					embeddings: { provider: '', model: '', dimensions: 1.5 },
				} ),
				providers
			)
		).toHaveProperty( [ 'embeddings.dimensions' ] );
	} );
} );

describe( 'filterModels', () => {
	const models = [
		model( 'gpt-x-mini', { label: 'Mini' } ),
		model( 'other', { label: 'Large Model' } ),
	];

	it( 'matches IDs and labels case-insensitively', () => {
		expect( filterModels( models, 'MINI' ).map( ( m ) => m.id ) ).toEqual( [
			'gpt-x-mini',
		] );
		expect( filterModels( models, 'large' ).map( ( m ) => m.id ) ).toEqual(
			[ 'other' ]
		);
		expect( filterModels( models, '  ' ) ).toBe( models );
	} );
} );

describe( 'formatting', () => {
	it( 'formats prices per 1M tokens', () => {
		expect( formatPrice( null ) ).toBe( '' );
		expect( formatPrice( 0 ) ).toBe( '$0' );
		expect( formatPrice( 0.15 ) ).toBe( '$0.15' );
		expect( formatPrice( 0.0025 ) ).toBe( '$0.0025' );
	} );

	it( 'formats context windows', () => {
		expect( formatContext( 0 ) ).toBe( '' );
		expect( formatContext( 128000 ) ).toBe( '128K' );
		expect( formatContext( 1048576 ) ).toBe( '1M' );
	} );
} );
