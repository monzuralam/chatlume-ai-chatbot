<?php
/**
 * Tests for ModelSuggestions.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Providers\ModelSuggestions;
use Chatlume\Tests\TestCase;

/**
 * Family-pattern badges and ordering.
 */
class ModelSuggestionsTest extends TestCase {

	/**
	 * Model entry.
	 *
	 * @param string $id      ID.
	 * @param int    $created Creation time.
	 * @param bool   $free    Free.
	 * @param string $kind    Kind.
	 * @return array<string, mixed>
	 */
	private function model( string $id, int $created = 0, bool $free = false, string $kind = 'chat' ): array {
		return array(
			'id'      => $id,
			'kind'    => $kind,
			'created' => $created,
			'free'    => $free,
		);
	}

	public function test_shipped_file_is_valid_and_uses_patterns_only(): void {
		$data = json_decode( (string) file_get_contents( CHATLUME_PATH . 'data/model-suggestions.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertIsArray( $data );
		foreach ( $data['providers'] as $provider => $kinds ) {
			foreach ( $kinds as $kind => $patterns ) {
				$this->assertContains( $kind, array( 'chat', 'embedding' ), $provider );
				foreach ( $patterns as $pattern ) {
					$this->assertNotFalse( @preg_match( '~' . $pattern . '~i', '' ), "Invalid pattern {$pattern}" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
	}

	public function test_orders_recommended_then_free_then_alphabetical(): void {
		ModelSuggestions::set_data(
			array(
				'providers' => array(
					'demo' => array(
						'chat'      => array( 'mini$', 'large' ),
						'embedding' => array( 'embed' ),
					),
				),
			)
		);

		$sorted = ModelSuggestions::apply(
			'demo',
			array(
				$this->model( 'zeta' ),
				$this->model( 'large-v1', 100 ),
				$this->model( 'v2-mini', 50 ),
				$this->model( 'alpha' ),
				$this->model( 'v3-mini', 200 ),
				$this->model( 'free-one', 0, true ),
				$this->model( 'large-v2', 300 ),
				$this->model( 'text-embed', 0, false, 'embedding' ),
			)
		);

		$this->assertSame(
			array( 'v3-mini', 'v2-mini', 'text-embed', 'large-v2', 'large-v1', 'free-one', 'alpha', 'zeta' ),
			array_column( $sorted, 'id' )
		);
		$this->assertTrue( $sorted[1]['recommended'] );
		$this->assertFalse( $sorted[5]['recommended'] );
		$this->assertTrue( $sorted[5]['free'] );
	}

	public function test_patterns_apply_only_to_their_kind_and_provider(): void {
		ModelSuggestions::set_data( array( 'providers' => array( 'demo' => array( 'chat' => array( 'embed' ) ) ) ) );

		$this->assertSame( 0, ModelSuggestions::rank( 'demo', 'chat', 'x-embed' ) );
		$this->assertNull( ModelSuggestions::rank( 'demo', 'embedding', 'x-embed' ) );
		$this->assertNull( ModelSuggestions::rank( 'other', 'chat', 'x-embed' ) );
	}

	public function test_malformed_patterns_are_ignored(): void {
		ModelSuggestions::set_data( array( 'providers' => array( 'demo' => array( 'chat' => array( '([', 'ok' ) ) ) ) );

		$this->assertSame( 1, ModelSuggestions::rank( 'demo', 'chat', 'ok-model' ) );
		$this->assertNull( ModelSuggestions::rank( 'demo', 'chat', 'nothing' ) );
	}
}
