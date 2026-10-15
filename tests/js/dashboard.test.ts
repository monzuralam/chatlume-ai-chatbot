/**
 * Dashboard helpers.
 */
import { niceMax } from '../../src/admin/components/charts/BarChart';
import { budgetShare, reasonLabel } from '../../src/admin/lib/stats';

describe( 'niceMax', () => {
	it( 'rounds the chart axis up to a readable number', () => {
		expect( niceMax( 0 ) ).toBe( 4 );
		expect( niceMax( 1 ) ).toBe( 1 );
		expect( niceMax( 3 ) ).toBe( 5 );
		expect( niceMax( 7 ) ).toBe( 10 );
		expect( niceMax( 13 ) ).toBe( 20 );
		expect( niceMax( 230 ) ).toBe( 500 );
		expect( niceMax( 0.03 ) ).toBe( 0.05 );
	} );
} );

describe( 'budgetShare', () => {
	it( 'gives the percentage used, or null without a budget', () => {
		expect( budgetShare( 2, 10 ) ).toBe( 20 );
		expect( budgetShare( 12, 10 ) ).toBe( 120 );
		expect( budgetShare( 5, 0 ) ).toBeNull();
	} );
} );

describe( 'reasonLabel', () => {
	it( 'explains each reason', () => {
		expect( reasonLabel( 'site_search' ) ).toBe(
			'Answered with site search'
		);
		expect( reasonLabel( 'ask_ai' ) ).toBe( 'Quick answer missed' );
	} );
} );
