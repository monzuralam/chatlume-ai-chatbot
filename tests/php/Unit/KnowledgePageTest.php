<?php
/**
 * Tests for the Knowledge page's server side: quick answer imports and the
 * index size warning.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Data\QuickAnswerRepository;
use Chatlume\REST\KnowledgeController;
use Chatlume\REST\QuickAnswersController;
use Chatlume\Tests\TestCase;
use WP_REST_Request;

/**
 * CSV import rows and size levels.
 */
class KnowledgePageTest extends TestCase {

	/**
	 * In-memory quick answers by ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $answers = array();

	/**
	 * Controller with an in-memory repository.
	 *
	 * @return QuickAnswersController
	 */
	private function controller(): QuickAnswersController {
		$test       = $this;
		$repository = new class( $test ) extends QuickAnswerRepository {
			/**
			 * Test case.
			 *
			 * @var KnowledgePageTest
			 */
			private KnowledgePageTest $test;

			/**
			 * Constructor.
			 *
			 * @param KnowledgePageTest $test Test case.
			 */
			public function __construct( KnowledgePageTest $test ) {
				$this->test = $test;
			}

			/** {@inheritDoc} */
			public function find_by_question( string $question, string $language ): int {
				foreach ( $this->test->answers as $id => $answer ) {
					if ( 0 === strcasecmp( $answer['question'], $question ) && $answer['language'] === $language ) {
						return $id;
					}
				}
				return 0;
			}

			/** {@inheritDoc} */
			public function create( array $data ): int {
				$id                         = count( $this->test->answers ) + 1;
				$this->test->answers[ $id ] = $data;
				return $id;
			}

			/** {@inheritDoc} */
			public function update( int $id, array $data ): bool {
				$this->test->answers[ $id ] = array_merge( $this->test->answers[ $id ], $data );
				return true;
			}
		};

		return new QuickAnswersController( $repository );
	}

	public function test_import_creates_updates_and_reports_bad_rows(): void {
		$this->answers[1] = array(
			'question' => 'What are your opening hours?',
			'answer'   => 'Old answer.',
			'keywords' => 'hours',
			'language' => '',
			'enabled'  => false,
		);

		$result = $this->controller()->import_items(
			new WP_REST_Request(
				array(
					'rows' => array(
						array(
							'question' => 'WHAT ARE YOUR OPENING HOURS?',
							'answer'   => '9 to 5, Monday to Friday.',
						),
						array(
							'question' => 'Do you ship abroad?',
							'answer'   => 'Yes, to 30 countries.',
							'keywords' => 'shipping, international',
							'language' => 'en_GB',
							'enabled'  => 'no',
						),
						array(
							'question' => 'Missing answer',
							'answer'   => '   ',
						),
						array(
							'question' => 'Bad language',
							'answer'   => 'Text.',
							'language' => 'english!',
						),
						'not a row',
					),
				)
			)
		)->get_data();

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 3, $result['skipped'] );
		$this->assertSame( array( 3, 4, 5 ), array_column( $result['errors'], 'row' ) );
		$this->assertSame( '9 to 5, Monday to Friday.', $this->answers[1]['answer'] );
		// Columns missing from the file leave the existing values alone.
		$this->assertFalse( $this->answers[1]['enabled'] );
		$this->assertSame( 'hours', $this->answers[1]['keywords'] );
		$this->assertSame(
			array(
				'question' => 'Do you ship abroad?',
				'answer'   => 'Yes, to 30 countries.',
				'language' => 'en-GB',
				'keywords' => 'shipping, international',
				'enabled'  => false,
			),
			$this->answers[2]
		);
	}

	public function test_import_route_is_limited_and_admin_only(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route, $args ) use ( &$routes ) {
				unset( $route_namespace );
				$routes[ $route ] = $args;
				return true;
			}
		);

		$this->controller()->register_routes();

		$import = $routes['/quick-answers/import'];
		$this->assertIsArray( $import['permission_callback'] );
		$this->assertSame( QuickAnswersController::MAX_IMPORT, $import['args']['rows']['maxItems'] );
		$this->assertTrue( $import['args']['rows']['required'] );
	}

	public function test_size_levels_depend_on_fulltext_support(): void {
		$index = array(
			'chunks' => 1000,
			'bytes'  => MB_IN_BYTES,
		);

		$this->assertSame( 'ok', KnowledgeController::size( $index, true )['level'] );
		$this->assertSame( 5000, KnowledgeController::size( $index, true )['chunks_limit'] );
		$this->assertSame( 2000, KnowledgeController::size( $index, false )['chunks_limit'] );

		$index['chunks'] = 1700;
		$this->assertSame( 'warn', KnowledgeController::size( $index, false )['level'] );
		$index['chunks'] = 2100;
		$this->assertSame( 'high', KnowledgeController::size( $index, false )['level'] );

		$this->assertSame(
			'high',
			KnowledgeController::size(
				array(
					'chunks' => 10,
					'bytes'  => 65 * MB_IN_BYTES,
				),
				true
			)['level']
		);
	}
}
