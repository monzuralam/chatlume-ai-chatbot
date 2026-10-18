<?php
/**
 * Abilities API registration.
 *
 * @package Chatlume
 */

namespace Chatlume\Abilities;

use Chatlume\Chat\ChatException;
use Chatlume\Chat\ChatService;
use Chatlume\Data\QuickAnswerRepository;
use Chatlume\Data\Stats;
use Chatlume\Plugin;
use Chatlume\REST\ChatController;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Chatlume's abilities (WordPress 6.9+), so site owners can manage
 * the bot from AI agents and automation tools that use the Abilities API:
 * ask the bot a question, read statistics, list questions it could not
 * answer, and add quick answers. Every ability needs the same permission
 * as Chatlume's admin pages.
 */
class AbilitiesRegistrar {

	/**
	 * Ability category.
	 */
	public const CATEGORY = 'chatlume';

	/**
	 * Hooks registration (the hooks only fire where the Abilities API exists).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Chatlume chatbot', 'chatlume-ai-chatbot' ),
				'description' => __( 'Ask your site’s chatbot questions, read its statistics, and improve its answers.', 'chatlume-ai-chatbot' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Ability definitions: name => arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions(): array {
		$permission = array( $this, 'permission' );
		$language   = array(
			'type'        => 'string',
			'description' => __( 'Language code such as "en" or "bn-BD". Empty: the site language.', 'chatlume-ai-chatbot' ),
			'maxLength'   => 20,
			'pattern'     => '^$|' . ChatController::LANGUAGE_PATTERN,
			'default'     => '',
		);
		$days       = array(
			'type'        => 'integer',
			'description' => __( 'Period: 1 (today), 7, or 30 days.', 'chatlume-ai-chatbot' ),
			'enum'        => array( 1, 7, 30 ),
			'default'     => 7,
		);

		return array(
			'chatlume/ask'                       => array(
				'label'               => __( 'Ask the chatbot', 'chatlume-ai-chatbot' ),
				'description'         => __( 'Asks the site’s chatbot a question and returns its answer with the sources it used, exactly as a visitor would get it. The conversation is kept as a test, so it does not change the statistics.', 'chatlume-ai-chatbot' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'question' => array(
							'type'        => 'string',
							'description' => __( 'The question to ask.', 'chatlume-ai-chatbot' ),
							'minLength'   => 1,
							'maxLength'   => 4000,
						),
						'language' => $language,
					),
					'required'             => array( 'question' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'answer'  => array( 'type' => 'string' ),
						'source'  => array(
							'type'        => 'string',
							'description' => __( 'Where the answer came from: ai, quick_answer, cache, fallback_model, or site_search.', 'chatlume-ai-chatbot' ),
						),
						'sources' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title' => array( 'type' => 'string' ),
									'url'   => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'ask' ),
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			),
			'chatlume/get-stats'                 => array(
				'label'               => __( 'Get chatbot statistics', 'chatlume-ai-chatbot' ),
				'description'         => __( 'Conversations, questions, tokens, estimated AI cost, and money saved by quick answers and the cache for today, 7, or 30 days.', 'chatlume-ai-chatbot' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'days' => $days ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'days'              => array( 'type' => 'integer' ),
						'conversations'     => array( 'type' => 'integer' ),
						'questions'         => array( 'type' => 'integer' ),
						'tokens'            => array( 'type' => 'integer' ),
						'cost'              => array( 'type' => 'number' ),
						'free_answers'      => array( 'type' => 'integer' ),
						'saved'             => array( 'type' => 'number' ),
						'replies_by_source' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'get_stats' ),
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
			'chatlume/list-unanswered-questions' => array(
				'label'               => __( 'List questions the chatbot could not answer', 'chatlume-ai-chatbot' ),
				'description'         => __( 'Visitor questions the bot answered from site search, without matching knowledge, rated as not helpful, or re-asked after a quick answer, most frequent first (up to 10).', 'chatlume-ai-chatbot' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'days' => array_merge( $days, array( 'default' => 30 ) ) ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'question'  => array( 'type' => 'string' ),
							'count'     => array( 'type' => 'integer' ),
							'reasons'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'last_seen' => array( 'type' => 'string' ),
							'language'  => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'list_unanswered' ),
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
			'chatlume/create-quick-answer'       => array(
				'label'               => __( 'Create a quick answer', 'chatlume-ai-chatbot' ),
				'description'         => __( 'Adds a quick answer: a fixed reply the bot gives without calling the AI when a visitor asks this question. An existing quick answer with the same question and language is updated instead.', 'chatlume-ai-chatbot' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'question' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 500,
						),
						'answer'   => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 5000,
						),
						'keywords' => array(
							'type'        => 'string',
							'description' => __( 'Extra words that should match, separated by commas.', 'chatlume-ai-chatbot' ),
							'maxLength'   => 500,
							'default'     => '',
						),
						'language' => $language,
					),
					'required'             => array( 'question', 'answer' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'question' => array( 'type' => 'string' ),
						'updated'  => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'create_quick_answer' ),
				'permission_callback' => $permission,
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Only Chatlume managers.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return Plugin::current_user_can_manage();
	}

	/**
	 * Asks the bot.
	 *
	 * @param mixed $input {question, language}.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ask( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		try {
			$result = $this->service()->handle(
				array(
					'session_id'         => 'ability-' . get_current_user_id(),
					'message'            => sanitize_textarea_field( (string) ( $input['question'] ?? '' ) ),
					'conversation_id'    => '',
					'page_url'           => home_url( '/' ),
					'page_title'         => (string) get_bloginfo( 'name' ),
					'language'           => ChatController::language( (string) ( $input['language'] ?? '' ) ),
					'user_id'            => 0,
					'user_name'          => '',
					'skip_quick_answers' => false,
					'quick_answer_id'    => 0,
					'is_test'            => true,
					'skip_rate_limit'    => true,
				)
			);
		} catch ( ChatException $exception ) {
			return new WP_Error( 'chatlume_' . $exception->error_code(), $exception->visitor_message(), array( 'status' => $exception->status() ) );
		}

		$reply   = (array) ( $result['reply'] ?? array() );
		$sources = array();
		foreach ( (array) ( $reply['sources'] ?? array() ) as $source ) {
			if ( is_array( $source ) ) {
				$sources[] = array(
					'title' => (string) ( $source['title'] ?? '' ),
					'url'   => (string) ( $source['url'] ?? '' ),
				);
			}
		}

		return array(
			'answer'  => (string) ( $reply['text'] ?? '' ),
			'source'  => (string) ( $reply['source'] ?? '' ),
			'sources' => $sources,
		);
	}

	/**
	 * Statistics.
	 *
	 * @param mixed $input {days}.
	 * @return array<string, mixed>
	 */
	public function get_stats( $input = array() ): array {
		$summary = $this->stats()->summary( self::days( $input, 7 ) );
		$totals  = (array) $summary['totals'];

		return array(
			'days'              => (int) $summary['days'],
			'conversations'     => (int) ( $totals['conversations'] ?? 0 ),
			'questions'         => (int) ( $totals['questions'] ?? 0 ),
			'tokens'            => (int) ( $totals['tokens'] ?? 0 ),
			'cost'              => (float) ( $totals['cost'] ?? 0 ),
			'free_answers'      => (int) ( $totals['free_answers'] ?? 0 ),
			'saved'             => (float) ( $totals['saved'] ?? 0 ),
			'replies_by_source' => (object) ( $totals['replies_by_source'] ?? array() ),
		);
	}

	/**
	 * Questions the bot could not answer.
	 *
	 * @param mixed $input {days}.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_unanswered( $input = array() ): array {
		return array_values( (array) $this->stats()->summary( self::days( $input, 30 ) )['unanswered'] );
	}

	/**
	 * Creates (or updates) a quick answer.
	 *
	 * @param mixed $input {question, answer, keywords, language}.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_quick_answer( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$values = array(
			'question' => sanitize_text_field( (string) ( $input['question'] ?? '' ) ),
			'answer'   => sanitize_textarea_field( (string) ( $input['answer'] ?? '' ) ),
			'keywords' => sanitize_text_field( (string) ( $input['keywords'] ?? '' ) ),
			'language' => str_replace( '_', '-', sanitize_text_field( (string) ( $input['language'] ?? '' ) ) ),
		);
		if ( '' === $values['question'] || '' === $values['answer'] ) {
			return new WP_Error( 'chatlume_invalid_quick_answer', __( 'A quick answer needs a question and an answer.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		$repository = $this->quick_answers();
		$existing   = $repository->find_by_question( $values['question'], $values['language'] );
		if ( $existing > 0 ) {
			$repository->update( $existing, $values );
			$id = $existing;
		} else {
			$values['enabled'] = true;
			$id                = $repository->create( $values );
		}

		if ( $id <= 0 ) {
			return new WP_Error( 'chatlume_save_failed', __( 'The quick answer could not be saved.', 'chatlume-ai-chatbot' ), array( 'status' => 500 ) );
		}

		return array(
			'id'       => $id,
			'question' => $values['question'],
			'updated'  => $existing > 0,
		);
	}

	/**
	 * Days from the input.
	 *
	 * @param mixed $input    Input.
	 * @param int   $fallback Default.
	 * @return int
	 */
	private static function days( $input, int $fallback ): int {
		$days = is_array( $input ) ? (int) ( $input['days'] ?? $fallback ) : $fallback;

		return in_array( $days, array( 1, 7, 30 ), true ) ? $days : $fallback;
	}

	/**
	 * The chat pipeline (replaceable in tests).
	 *
	 * @return ChatService
	 */
	protected function service(): ChatService {
		return new ChatService();
	}

	/**
	 * Statistics (replaceable in tests).
	 *
	 * @return Stats
	 */
	protected function stats(): Stats {
		return new Stats();
	}

	/**
	 * Quick answers (replaceable in tests).
	 *
	 * @return QuickAnswerRepository
	 */
	protected function quick_answers(): QuickAnswerRepository {
		return new QuickAnswerRepository();
	}
}
