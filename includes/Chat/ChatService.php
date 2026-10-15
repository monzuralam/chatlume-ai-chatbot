<?php
/**
 * The chat pipeline.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

use Chatlume\Data\QuickAnswerRepository;
use Chatlume\Knowledge\SiteSearchFallback;
use Chatlume\Providers\ProviderError;
use Chatlume\Providers\ProviderRegistry;
use Chatlume\QuickAnswers\Matcher;
use Chatlume\Security\RateLimiter;
use Chatlume\Settings\Settings;
use Chatlume\Support\DebugLog;
use Chatlume\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ChatException messages are visitor-facing text returned as JSON (rendered as text by the
// widget), never printed as HTML; getMessage() is HTML-escaped as a safeguard.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Answers one visitor message, in this order:
 *
 * 1. Validate: rate limits (per hashed IP and per session), message length,
 *    conversation cap, blocked words.
 * 2. Quick answers: a clear match is served instantly without the AI.
 * 3. Response cache: a recent AI answer to the same first question.
 * 4. Knowledge context (filled by the knowledge base through `chatlume_retrieved_context`).
 * 5. The AI provider (primary, then fallback), unless budgets say otherwise.
 * 6. Site-search fallback when the AI is unavailable, not configured, or over budget.
 * 7. Log the turn with its source, tokens, estimated cost, and latency; fire hooks.
 */
class ChatService {

	/**
	 * Reply sources stored with each assistant message.
	 */
	public const SOURCE_AI             = 'ai';
	public const SOURCE_QUICK_ANSWER   = 'quick_answer';
	public const SOURCE_CACHE          = 'cache';
	public const SOURCE_FALLBACK_MODEL = 'fallback_model';
	public const SOURCE_SITE_SEARCH    = 'site_search';
	public const SOURCE_GUARDRAIL      = 'guardrail';
	public const SOURCE_OFFLINE        = 'offline';

	/**
	 * Collaborators.
	 *
	 * @var ConversationState
	 */
	private ConversationState $state;

	/**
	 * Quick answer matcher.
	 *
	 * @var Matcher
	 */
	private Matcher $matcher;

	/**
	 * Quick answer counters.
	 *
	 * @var QuickAnswerRepository
	 */
	private QuickAnswerRepository $quick_answers;

	/**
	 * Response cache.
	 *
	 * @var ResponseCache
	 */
	private ResponseCache $cache;

	/**
	 * Budgets and caps.
	 *
	 * @var CostGuard
	 */
	private CostGuard $costs;

	/**
	 * Site search.
	 *
	 * @var SiteSearchFallback
	 */
	private SiteSearchFallback $site_search;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Prompt builder.
	 *
	 * @var PromptBuilder
	 */
	private PromptBuilder $prompts;

	/**
	 * Streaming callbacks for the current request: on_token (receives each text
	 * fragment), should_abort (returns a non-empty reason to stop), and timeout (seconds).
	 *
	 * @var array<string, mixed>
	 */
	private array $stream = array();

	/**
	 * How the current reply was produced (the admin Playground shows it):
	 * route, quick answer, cache, knowledge chunks, and the final prompt.
	 *
	 * @var array<string, mixed>
	 */
	private array $trace = array();

	/**
	 * Constructor. Every collaborator can be replaced (tests).
	 *
	 * @param array<string, object> $deps Optional: state, matcher, quick_answers, cache, costs, site_search, limiter, prompts.
	 */
	public function __construct( array $deps = array() ) {
		$this->state         = $deps['state'] ?? new ConversationState();
		$this->quick_answers = $deps['quick_answers'] ?? new QuickAnswerRepository();
		$this->matcher       = $deps['matcher'] ?? new Matcher( $this->quick_answers );
		$this->cache         = $deps['cache'] ?? new ResponseCache();
		$this->costs         = $deps['costs'] ?? new CostGuard();
		$this->site_search   = $deps['site_search'] ?? new SiteSearchFallback();
		$this->limiter       = $deps['limiter'] ?? new RateLimiter();
		$this->prompts       = $deps['prompts'] ?? new PromptBuilder();
	}

	/**
	 * Answers a visitor message.
	 *
	 * @param array<string, mixed> $request session_id, message, conversation_id, page_url, page_title, language,
	 *                                      user_id, user_name, visitor_hash, skip_quick_answers, quick_answer_id, is_test,
	 *                                      skip_rate_limit and debug (admin Playground only: never set from visitor input).
	 * @param array<string, mixed> $stream  Optional streaming callbacks: on_start, on_token, should_abort, timeout.
	 *                                      Only AI replies stream; other replies are returned whole.
	 * @return array{conversation_id: string, message_id: int, reply: array<string, mixed>, details: array<string, mixed>}
	 * @throws ChatException When the request cannot be served.
	 */
	public function handle( array $request, array $stream = array() ): array {
		$this->stream = $stream;
		$this->trace  = array(
			'route'        => 'ai',
			'quick_answer' => null,
			'cache'        => 'skipped',
			'knowledge'    => array(),
			'prompt'       => array(),
		);
		$started      = microtime( true );
		$message      = trim( (string) ( $request['message'] ?? '' ) );
		$session_id   = (string) $request['session_id'];
		$context      = array(
			'page_url'   => (string) ( $request['page_url'] ?? '' ),
			'page_title' => (string) ( $request['page_title'] ?? '' ),
			'language'   => (string) ( $request['language'] ?? '' ),
			'user_id'    => (int) ( $request['user_id'] ?? 0 ),
			'user_name'  => (string) ( $request['user_name'] ?? '' ),
		);

		// 1. Validation.
		if ( empty( $request['skip_rate_limit'] ) ) {
			$this->check_rate_limits( (string) ( $request['visitor_hash'] ?? '' ), $session_id );
		}

		if ( '' === $message ) {
			throw new ChatException( 'empty_message', __( 'Please type a message.', 'chatlume-ai-chatbot' ) );
		}

		$max_length = (int) Settings::get( 'chatbot.max_message_length', 1000 );
		if ( mb_strlen( $message, 'UTF-8' ) > $max_length ) {
			throw new ChatException(
				'message_too_long',
				/* translators: %d: Maximum number of characters. */
				sprintf( __( 'Your message is too long. Please keep it under %d characters.', 'chatlume-ai-chatbot' ), $max_length )
			);
		}

		$conversation = $this->conversation( (string) ( $request['conversation_id'] ?? '' ), $session_id, $context, $request );
		$context     += array( 'conversation_id' => $conversation['id'] );

		if ( $this->costs->conversation_capped( $conversation['user_messages'] ) ) {
			throw new ChatException( 'conversation_limit', BotText::conversation_limit(), 403 );
		}

		if ( isset( $this->stream['on_start'] ) && is_callable( $this->stream['on_start'] ) ) {
			// Lets a stream report the conversation before the reply, so a stopped reply keeps it.
			( $this->stream['on_start'] )( $conversation['id'] );
		}

		/**
		 * Fires when a visitor message passed validation.
		 *
		 * @param string               $message         Visitor message.
		 * @param string               $conversation_id Conversation ID.
		 * @param array<string, mixed> $context         Request context.
		 */
		do_action( 'chatlume_message_received', $message, $conversation['id'], $context );

		$meta = array();

		if ( (int) ( $request['quick_answer_id'] ?? 0 ) > 0 && ! empty( $request['skip_quick_answers'] ) ) {
			// The visitor chose "Not what you meant? Ask AI": count a miss for that quick answer.
			$this->quick_answers->record_miss( (int) $request['quick_answer_id'] );
			$meta['ask_ai_miss'] = (int) $request['quick_answer_id'];
		}

		$reply = $this->blocked_word_reply( $message )
			?? $this->quick_answer_reply( $message, $context, ! empty( $request['skip_quick_answers'] ) )
			?? $this->ai_or_fallback_reply( $message, $conversation, $context );

		$reply['meta']       = $meta + ( $reply['meta'] ?? array() );
		$reply['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		/**
		 * Filters the reply text before it is stored and returned.
		 *
		 * @param string               $text    Reply text.
		 * @param array<string, mixed> $reply   Reply data (source, sources, provider, model, tokens).
		 * @param array<string, mixed> $context Request context.
		 */
		$reply['content'] = (string) apply_filters( 'chatlume_response_text', $reply['content'], $reply, $context );

		$message_id = $this->state->record_turn( $conversation['id'], $message, $reply );

		$public = array(
			'text'            => $reply['content'],
			'source'          => $reply['source'],
			'sources'         => $reply['sources'],
			'quick_answer_id' => $reply['quick_answer_id'] ?? null,
		);
		if ( ! empty( $reply['meta']['interrupted'] ) ) {
			$public['interrupted'] = (string) $reply['meta']['interrupted'];
		}

		/**
		 * Fires after a reply was produced and logged.
		 *
		 * @param array<string, mixed> $public          Reply sent to the visitor.
		 * @param string               $conversation_id Conversation ID.
		 * @param array<string, mixed> $context         Request context.
		 */
		do_action( 'chatlume_response_sent', $public, $conversation['id'], $context );

		DebugLog::record(
			'reply',
			array(
				'source'        => $reply['source'],
				'provider'      => $reply['provider'] ?? '',
				'model'         => $reply['model'] ?? '',
				'input_tokens'  => (int) ( $reply['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $reply['output_tokens'] ?? 0 ),
				'cost'          => (float) ( $reply['cost'] ?? 0 ),
				'latency_ms'    => $reply['latency_ms'],
				'error_type'    => $reply['error_type'] ?? '',
				'interrupted'   => (string) ( $reply['meta']['interrupted'] ?? '' ),
				'streamed'      => isset( $this->stream['on_token'] ),
			)
		);

		$details = array(
			'provider'      => $reply['provider'] ?? '',
			'model'         => $reply['model'] ?? '',
			'input_tokens'  => $reply['input_tokens'] ?? 0,
			'output_tokens' => $reply['output_tokens'] ?? 0,
			'cost'          => $reply['cost'] ?? 0,
			'latency_ms'    => $reply['latency_ms'],
			'error_type'    => $reply['error_type'] ?? '',
			'meta'          => $reply['meta'],
		);
		if ( ! empty( $request['debug'] ) ) {
			$details['trace'] = $this->trace;
		}

		return array(
			'conversation_id' => $conversation['id'],
			'message_id'      => $message_id,
			'reply'           => $public,
			'details'         => $details,
		);
	}

	/**
	 * Rate limits per hashed IP and per session.
	 *
	 * @param string $visitor_hash Hashed IP.
	 * @param string $session_id   Session ID.
	 * @return void
	 * @throws ChatException When a limit is exceeded.
	 */
	private function check_rate_limits( string $visitor_hash, string $session_id ): void {
		$limit  = (int) Settings::get( 'security.rate_limit_messages', 20 );
		$window = (int) Settings::get( 'security.rate_limit_minutes', 10 ) * MINUTE_IN_SECONDS;

		$buckets = array( 'session:' . $session_id );
		if ( '' !== $visitor_hash ) {
			$buckets[] = 'ip:' . $visitor_hash;
		}

		foreach ( $buckets as $bucket ) {
			$result = $this->limiter->hit( $bucket, $limit, $window );
			if ( ! $result['allowed'] ) {
				throw new ChatException( 'rate_limited', BotText::rate_limited(), 429, $result['retry_after'] );
			}
		}
	}

	/**
	 * Loads the visitor's conversation or starts a new one.
	 *
	 * @param string               $id         Requested conversation ID.
	 * @param string               $session_id Session ID.
	 * @param array<string, mixed> $context    Request context.
	 * @param array<string, mixed> $request    Request.
	 * @return array{id: string, user_messages: int, new: bool}
	 * @throws ChatException When a conversation cannot be created.
	 */
	private function conversation( string $id, string $session_id, array $context, array $request ): array {
		$existing = '' !== $id ? $this->state->find( $id, $session_id ) : null;
		if ( null !== $existing ) {
			return $existing + array( 'new' => false );
		}

		$new_id = $this->state->start(
			$session_id,
			array(
				'user_id'      => $context['user_id'],
				'visitor_hash' => (string) ( $request['visitor_hash'] ?? '' ),
				'page_url'     => $context['page_url'],
				'language'     => $context['language'],
				'is_test'      => ! empty( $request['is_test'] ),
			)
		);

		if ( '' === $new_id ) {
			throw new ChatException( 'unavailable', BotText::error(), 500 );
		}

		/**
		 * Fires when a new conversation starts.
		 *
		 * @param string               $conversation_id Conversation ID.
		 * @param array<string, mixed> $context         Request context.
		 */
		do_action( 'chatlume_conversation_started', $new_id, $context );

		return array(
			'id'            => $new_id,
			'user_messages' => 0,
			'new'           => true,
		);
	}

	/**
	 * Refusal when the message contains a blocked word.
	 *
	 * @param string $message Visitor message.
	 * @return array<string, mixed>|null
	 */
	private function blocked_word_reply( string $message ): ?array {
		$normalized = Text::normalize( $message );

		foreach ( (array) Settings::get( 'chatbot.blocked_words', array() ) as $word ) {
			if ( is_string( $word ) && Text::contains_phrase( $normalized, Text::normalize( $word ) ) ) {
				$this->trace['route'] = 'blocked_word';
				return self::reply( BotText::refusal(), self::SOURCE_GUARDRAIL );
			}
		}

		return null;
	}

	/**
	 * Quick answer, when one clearly matches.
	 *
	 * @param string               $message Visitor message.
	 * @param array<string, mixed> $context Request context.
	 * @param bool                 $skip    The visitor asked for the AI instead.
	 * @return array<string, mixed>|null
	 */
	private function quick_answer_reply( string $message, array $context, bool $skip ): ?array {
		if ( $skip || ! Settings::get( 'quick_answers.enabled', true ) ) {
			return null;
		}

		$match = $this->matcher->match( $message, (string) $context['language'], $context );
		if ( null === $match ) {
			return null;
		}

		$this->quick_answers->record_hit( (int) $match['id'] );

		$this->trace['route']        = 'quick_answer';
		$this->trace['quick_answer'] = array(
			'id'       => (int) $match['id'],
			'question' => (string) ( $match['question'] ?? '' ),
			'score'    => $match['score'],
		);

		$reply                    = self::reply( (string) $match['answer'], self::SOURCE_QUICK_ANSWER );
		$reply['quick_answer_id'] = (int) $match['id'];
		$reply['meta']            = array( 'quick_answer_score' => $match['score'] );

		return $reply;
	}

	/**
	 * AI answer (or cached answer), falling back to site search.
	 *
	 * @param string                                     $message      Visitor message.
	 * @param array{id: string, user_messages: int, new: bool} $conversation Conversation.
	 * @param array<string, mixed>                       $context      Request context.
	 * @return array<string, mixed>
	 */
	private function ai_or_fallback_reply( string $message, array $conversation, array $context ): array {
		$exhausted = $this->costs->exhausted_period();
		$route     = '';

		if ( '' !== $exhausted ) {
			$this->trace['budget'] = $exhausted;
			$behavior              = $this->costs->cap_behavior();
			if ( 'offline' === $behavior ) {
				$this->trace['route'] = 'offline';
				return self::reply( BotText::offline(), self::SOURCE_OFFLINE, array( 'meta' => array( 'budget' => $exhausted ) ) );
			}
			if ( 'fallback_model' !== $behavior || '' === ProviderRegistry::role( 'fallback' )['provider'] ) {
				return $this->site_search_reply( $message, array( 'budget' => $exhausted ) );
			}
			$route                = 'fallback';
			$this->trace['route'] = 'fallback_model';
		}

		$primary = ProviderRegistry::role( 'fallback' === $route ? 'fallback' : 'primary' );
		if ( '' === $primary['provider'] ) {
			// No-key mode: no AI provider is set up yet.
			return $this->site_search_reply( $message, array( 'no_provider' => true ) );
		}

		$history   = $this->state->history( $conversation['id'], (int) Settings::get( 'chatbot.history_window', 10 ) );
		$knowledge = $this->knowledge( $message, $context );
		$system    = $this->prompts->system_prompt( $context + array( 'knowledge' => $knowledge['text'] ) );
		$target    = $primary['provider'] . '/' . $primary['model'];

		$cacheable = ResponseCache::eligible( array() === $history, (int) $context['user_id'] );
		$cache_key = $cacheable ? ResponseCache::key( $message, (string) $context['language'], $system, $target ) : '';

		$this->trace['prompt'] = array(
			'system'  => $system,
			'history' => count( $history ),
		);

		if ( $cacheable ) {
			$cached               = $this->cache->get( $cache_key );
			$this->trace['cache'] = null !== $cached ? 'hit' : 'miss';
			if ( null !== $cached ) {
				$this->trace['route'] = 'cache';
				return self::reply( $cached['text'], self::SOURCE_CACHE, array( 'sources' => (array) $cached['sources'] ) );
			}
		}

		$messages                          = $this->prompts->messages( $system, $history, $message, $context );
		$this->trace['prompt']['messages'] = array_values(
			array_map(
				static fn( $item ): array => array(
					'role'    => (string) ( $item['role'] ?? '' ),
					'content' => is_string( $item['content'] ?? null ) ? $item['content'] : (string) wp_json_encode( $item['content'] ?? '' ),
				),
				array_filter( $messages, 'is_array' )
			)
		);

		$options  = array(
			'temperature' => (float) Settings::get( 'chatbot.temperature', 0.3 ),
			'max_tokens'  => (int) Settings::get( 'chatbot.max_output_tokens', 600 ),
			'route'       => $route,
		);
		$on_token = null;
		$streamed = '';

		if ( isset( $this->stream['on_token'] ) && is_callable( $this->stream['on_token'] ) ) {
			$forward  = $this->stream['on_token'];
			$on_token = static function ( string $text ) use ( $forward, &$streamed ) {
				$streamed .= $text;
				$forward( $text );
			};
			if ( isset( $this->stream['should_abort'] ) && is_callable( $this->stream['should_abort'] ) ) {
				$options['should_abort'] = $this->stream['should_abort'];
			}
			if ( ! empty( $this->stream['timeout'] ) ) {
				$options['timeout'] = (int) $this->stream['timeout'];
			}
		}

		try {
			$response = ProviderRegistry::chat( $messages, $options, $on_token );
		} catch ( ProviderError $error ) {
			$partial = trim( '' !== $streamed ? $streamed : $error->partial_text() );
			if ( '' !== $partial ) {
				// The stream broke after the visitor already saw part of the answer: keep that part.
				return self::reply(
					$partial,
					'fallback' === $route ? self::SOURCE_FALLBACK_MODEL : self::SOURCE_AI,
					array(
						'sources'    => $knowledge['sources'],
						'provider'   => $error->provider(),
						'error_type' => $error->type(),
						'meta'       => array( 'interrupted' => 'error' ),
					)
				);
			}
			return $this->site_search_reply( $message, array( 'provider_error' => $error->type() ), $error->type() );
		}

		$cost = ProviderRegistry::estimate_cost( $response->provider, $response->model, $response->input_tokens, $response->output_tokens );
		$this->costs->record( $response->input_tokens + $response->output_tokens, $cost );

		$source  = ( $response->used_fallback || 'fallback' === $route ) ? self::SOURCE_FALLBACK_MODEL : self::SOURCE_AI;
		$text    = trim( $response->text );
		$aborted = 'aborted' === $response->finish_reason;

		if ( '' === $text && ! $aborted ) {
			return $this->site_search_reply( $message, array( 'empty_reply' => true ) );
		}

		// Stopped or cut-off answers are never cached.
		if ( $cacheable && ! $aborted && 'length' !== $response->finish_reason ) {
			$this->cache->set( $cache_key, $text, $knowledge['sources'] );
			$this->trace['cache'] = 'stored';
		}

		$meta = array();
		if ( $aborted ) {
			$meta['interrupted'] = 'stopped';
		}
		if ( '' === $knowledge['text'] && has_filter( 'chatlume_retrieved_context' ) ) {
			// Knowledge retrieval ran but found nothing: a "question your bot couldn't answer" signal.
			$meta['no_knowledge_match'] = true;
		}
		if ( '' !== $exhausted ) {
			$meta['budget'] = $exhausted;
		}

		return self::reply(
			$text,
			$source,
			array(
				'sources'       => $knowledge['sources'],
				'provider'      => $response->provider,
				'model'         => $response->model,
				'input_tokens'  => $response->input_tokens,
				'output_tokens' => $response->output_tokens,
				'cost'          => (float) $cost,
				'meta'          => $meta,
			)
		);
	}

	/**
	 * Knowledge context for the prompt and the source links to show.
	 *
	 * @param string               $message Visitor message.
	 * @param array<string, mixed> $context Request context.
	 * @return array{text: string, sources: array<int, array{title: string, url: string}>}
	 */
	private function knowledge( string $message, array $context ): array {
		/**
		 * Filters the knowledge retrieved for a message.
		 *
		 * The knowledge base adds chunks here; each chunk has content, title, and url.
		 *
		 * @param array<int, array{content: string, title?: string, url?: string}> $chunks  Retrieved chunks.
		 * @param string                                                          $message Visitor message.
		 * @param array<string, mixed>                                            $context Request context.
		 */
		$chunks = apply_filters( 'chatlume_retrieved_context', array(), $message, $context );

		$texts   = array();
		$sources = array();

		foreach ( is_array( $chunks ) ? $chunks : array() as $chunk ) {
			if ( ! is_array( $chunk ) || '' === trim( (string) ( $chunk['content'] ?? '' ) ) ) {
				continue;
			}

			$title   = trim( (string) ( $chunk['title'] ?? '' ) );
			$texts[] = ( '' !== $title ? '## ' . $title . "\n" : '' ) . trim( (string) $chunk['content'] );

			$url = esc_url_raw( (string) ( $chunk['url'] ?? '' ) );

			$this->trace['knowledge'][] = array(
				'title'   => $title,
				'url'     => $url,
				'score'   => isset( $chunk['score'] ) && is_numeric( $chunk['score'] ) ? round( (float) $chunk['score'], 3 ) : null,
				'excerpt' => mb_substr( trim( (string) $chunk['content'] ), 0, 300 ),
			);
			if ( '' !== $url && ! isset( $sources[ $url ] ) && count( $sources ) < 3 ) {
				$sources[ $url ] = array(
					'title' => '' !== $title ? $title : $url,
					'url'   => $url,
				);
			}
		}

		return array(
			'text'    => implode( "\n\n", $texts ),
			'sources' => array_values( $sources ),
		);
	}

	/**
	 * Friendly message plus the top matching pages.
	 *
	 * @param string               $message    Visitor message.
	 * @param array<string, mixed> $meta       Why site search was used.
	 * @param string               $error_type Provider error type, if any.
	 * @return array<string, mixed>
	 */
	private function site_search_reply( string $message, array $meta, string $error_type = '' ): array {
		$this->trace['route'] = 'site_search';
		$pages                = $this->site_search->search( $message, 3 );

		return self::reply(
			array() === $pages ? BotText::nothing_found() : BotText::site_search(),
			self::SOURCE_SITE_SEARCH,
			array(
				'sources'    => $pages,
				'error_type' => $error_type,
				'meta'       => $meta,
			)
		);
	}

	/**
	 * Reply structure.
	 *
	 * @param string               $content Text.
	 * @param string               $source  Source.
	 * @param array<string, mixed> $extra   Extra fields.
	 * @return array<string, mixed>
	 */
	private static function reply( string $content, string $source, array $extra = array() ): array {
		return array_merge(
			array(
				'content'       => $content,
				'source'        => $source,
				'sources'       => array(),
				'provider'      => '',
				'model'         => '',
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'cost'          => 0.0,
				'error_type'    => '',
				'meta'          => array(),
			),
			$extra
		);
	}
}
