<?php
/**
 * Tests for Text and the quick answer Matcher.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Filters;
use Chatlume\Data\QuickAnswerRepository;
use Chatlume\QuickAnswers\Matcher;
use Chatlume\Support\Text;
use Chatlume\Tests\TestCase;

/**
 * Multibyte-safe normalization, tokens, and scoring.
 */
class TextAndMatcherTest extends TestCase {

	public function test_normalize_is_multibyte_safe(): void {
		$this->assertSame( 'what s your price', Text::normalize( '  What’s YOUR <b>price</b>?!  ' ) );
		$this->assertSame( 'ÉCOLE', mb_strtoupper( Text::normalize( 'École' ), 'UTF-8' ) );
		$this->assertSame( 'আপনার দাম কত', Text::normalize( 'আপনার দাম কত?' ) );
		$this->assertSame( 'سعر المنتج', Text::normalize( 'سعر، المنتج؟' ) );
	}

	public function test_tokens_drop_stopwords_and_split_unspaced_scripts(): void {
		$this->assertSame( array( 'opening', 'hours' ), Text::tokens( 'What are your opening hours?' ) );
		$this->assertSame( array( '营业', '业时', '时间' ), Text::tokens( '营业时间' ) );
		$this->assertContains( '24', Text::tokens( 'open 24 hours' ) );
		$this->assertSame( array( 'দাম', 'কত' ), Text::tokens( 'দাম কত' ) );
	}

	public function test_contains_phrase_matches_whole_words(): void {
		$this->assertTrue( Text::contains_phrase( 'do you ship to canada', 'ship' ) );
		$this->assertFalse( Text::contains_phrase( 'friendship offer', 'ship' ) );
		$this->assertTrue( Text::contains_phrase( 'gift card balance', 'gift card' ) );
		$this->assertTrue( Text::contains_phrase( '你们几点营业', '营业' ) );
		$this->assertFalse( Text::contains_phrase( 'anything', '' ) );
	}

	/**
	 * Scoring cases.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: float, 4: float}>
	 */
	public function score_cases(): array {
		return array(
			'identical'          => array( 'What are your opening hours?', 'what are your opening hours', '', 1.0, 1.0 ),
			'contained question' => array( 'opening hours please', 'What are your opening hours?', '', 0.85, 1.0 ),
			'keyword'            => array( 'when are you open', 'Opening hours', 'when are you open, hours', 0.9, 1.0 ),
			'two keywords'       => array( 'business hours today, are you open', 'Opening hours', 'business hours, are you open', 1.0, 1.0 ),
			'unrelated'          => array( 'Do you ship to Canada?', 'What are your opening hours?', 'hours', 0.0, 0.3 ),
			'partial overlap'    => array( 'what are your shipping hours', 'What are your opening hours?', '', 0.3, 0.79 ),
			'chinese'            => array( '你们的营业时间是什么', '营业时间', '', 0.5, 1.0 ),
			'empty'              => array( '?!', 'Hours', '', 0.0, 0.0 ),
		);
	}

	/**
	 * Score ranges.
	 *
	 * @dataProvider score_cases
	 *
	 * @param string $message  Message.
	 * @param string $question Stored question.
	 * @param string $keywords Keywords.
	 * @param float  $min      Minimum score.
	 * @param float  $max      Maximum score.
	 */
	public function test_score( string $message, string $question, string $keywords, float $min, float $max ): void {
		$score = Matcher::score( $message, $question, $keywords );

		$this->assertGreaterThanOrEqual( $min, $score );
		$this->assertLessThanOrEqual( $max, $score );
	}

	public function test_match_picks_best_above_threshold_and_is_filterable(): void {
		$repository = new class() extends QuickAnswerRepository {
			/**
			 * Fixed candidates.
			 *
			 * @param string $language Language.
			 * @return array
			 */
			public function enabled_for( string $language ): array {
				unset( $language );
				return array(
					array(
						'id'       => 1,
						'question' => 'Do you ship internationally?',
						'answer'   => 'Yes, worldwide.',
						'keywords' => '',
					),
					array(
						'id'       => 2,
						'question' => 'What are your opening hours?',
						'answer'   => '9 to 5.',
						'keywords' => 'opening hours',
					),
				);
			}
		};

		$matcher = new Matcher( $repository );

		$match = $matcher->match( 'what are your opening hours', 'en' );
		$this->assertSame( 2, $match['id'] );
		$this->assertSame( 1.0, $match['score'] );

		$this->assertNull( $matcher->match( 'tell me a joke', 'en' ), 'Below the threshold the AI answers.' );

		// Scores about 0.9 (keyword hit, extra word): served at the default threshold, not at 1.0.
		$this->assertSame( 2, $matcher->match( 'opening hours on sunday', 'en' )['id'] ?? null, 'Close match at the default threshold.' );
		$this->options['chatlume_settings'] = array( 'quick_answers' => array( 'threshold' => 1.0 ) );
		$this->assertNull( $matcher->match( 'opening hours on sunday', 'en' ), 'Threshold is configurable.' );

		// Brain Monkey treats a null return as "unchanged", so a filter rejecting the match returns false.
		Filters\expectApplied( 'chatlume_quick_answer_match' )->andReturn( false );
		$this->assertNull( $matcher->match( 'what are your opening hours', 'en' ) );
	}
}
