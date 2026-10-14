<?php
/**
 * Settings sections for the chatbot, quick answers, security, costs, and caching.
 *
 * @package Chatlume
 */

namespace Chatlume\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Section definitions in the SettingsSchema format. Empty text values mean
 * "use the built-in default" (resolved by the code that uses them), so
 * defaults stay translatable and improve with updates.
 */
final class ChatSettingsSchema {

	/**
	 * Chatbot persona, parameters, language, guardrails, and visitor messages.
	 *
	 * @return array<string, mixed>
	 */
	public static function chatbot(): array {
		return self::section(
			__( 'Chatbot behavior.', 'chatlume-ai-chatbot' ),
			array(
				'bot_name'            => self::text( '', 60 ),
				'preset'              => array(
					'type'    => 'string',
					'format'  => 'key',
					'default' => 'support',
					'enum'    => array( 'support', 'sales', 'faq', 'blank' ),
				),
				'system_prompt'       => self::text( '', 8000, 'textarea' ),
				'welcome_message'     => self::text( '', 1000, 'rich' ),
				'starter_questions'   => array(
					'type'     => 'array',
					'maxItems' => 4,
					'items'    => self::text( '', 120 ),
				),
				'temperature'         => self::number( 0.3, 0, 2 ),
				'max_output_tokens'   => self::integer( 600, 50, 4000 ),
				'history_window'      => self::integer( 10, 0, 30 ),
				'max_message_length'  => self::integer( 1000, 50, 4000 ),
				'language_mode'       => array(
					'type'    => 'string',
					'format'  => 'key',
					'default' => 'visitor',
					'enum'    => array( 'visitor', 'fixed' ),
				),
				'fixed_language'      => self::text( '', 40 ),
				'site_topics_only'    => self::boolean( false ),
				'refusal_message'     => self::text( '', 500, 'textarea' ),
				'blocked_words'       => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => self::text( '', 60 ),
				),
				'offline_message'     => self::text( '', 500, 'textarea' ),
				'error_message'       => self::text( '', 500, 'textarea' ),
				'site_search_message' => self::text( '', 500, 'textarea' ),
			)
		);
	}

	/**
	 * Quick answers.
	 *
	 * @return array<string, mixed>
	 */
	public static function quick_answers(): array {
		return self::section(
			__( 'Quick answers.', 'chatlume-ai-chatbot' ),
			array(
				'enabled'          => self::boolean( true ),
				// High default so only clear matches skip the AI.
				'threshold'        => self::number( 0.8, 0.5, 1 ),
				'show_as_starters' => self::boolean( false ),
			)
		);
	}

	/**
	 * Abuse protection and visitor IP handling.
	 *
	 * @return array<string, mixed>
	 */
	public static function security(): array {
		return self::section(
			__( 'Security and rate limits.', 'chatlume-ai-chatbot' ),
			array(
				'rate_limit_messages' => self::integer( 20, 1, 1000 ),
				'rate_limit_minutes'  => self::integer( 10, 1, 1440 ),
				'session_hours'       => self::integer( 24, 1, 720 ),
				'trusted_proxy'       => array(
					'type'    => 'string',
					'format'  => 'key',
					'default' => 'none',
					'enum'    => array( 'none', 'cloudflare', 'custom' ),
				),
				'trusted_proxy_ips'   => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => self::text( '', 64, 'ip-range' ),
				),
			)
		);
	}

	/**
	 * Cost controls.
	 *
	 * @return array<string, mixed>
	 */
	public static function costs(): array {
		return self::section(
			__( 'Cost controls.', 'chatlume-ai-chatbot' ),
			array(
				// 0 means unlimited.
				'conversation_message_cap' => self::integer( 50, 0, 1000 ),
				'budget_unit'              => array(
					'type'    => 'string',
					'format'  => 'key',
					'default' => 'currency',
					'enum'    => array( 'currency', 'tokens' ),
				),
				// 0 means no cap. Currency budgets are in US dollars.
				'daily_budget'             => self::number( 0, 0, 1000000000 ),
				'monthly_budget'           => self::number( 0, 0, 1000000000 ),
				'cap_behavior'             => array(
					'type'    => 'string',
					'format'  => 'key',
					'default' => 'site_search',
					'enum'    => array( 'site_search', 'offline', 'fallback_model' ),
				),
				'alert_email'              => self::text( '', 100, 'email' ),
			)
		);
	}

	/**
	 * Response cache.
	 *
	 * @return array<string, mixed>
	 */
	public static function cache(): array {
		return self::section(
			__( 'Response cache.', 'chatlume-ai-chatbot' ),
			array(
				'enabled'   => self::boolean( true ),
				'ttl_hours' => self::integer( 24, 1, 720 ),
			)
		);
	}

	/**
	 * Object section.
	 *
	 * @param string               $description Description.
	 * @param array<string, mixed> $properties  Properties.
	 * @return array<string, mixed>
	 */
	private static function section( string $description, array $properties ): array {
		return array(
			'type'        => 'object',
			'description' => $description,
			'properties'  => $properties,
		);
	}

	/**
	 * String field.
	 *
	 * @param string $fallback   Default.
	 * @param int    $max_length Maximum length.
	 * @param string $format     Format.
	 * @return array<string, mixed>
	 */
	private static function text( string $fallback, int $max_length, string $format = 'text' ): array {
		return array(
			'type'      => 'string',
			'format'    => $format,
			'default'   => $fallback,
			'maxLength' => $max_length,
		);
	}

	/**
	 * Integer field.
	 *
	 * @param int $fallback Default.
	 * @param int $minimum  Minimum.
	 * @param int $maximum  Maximum.
	 * @return array<string, mixed>
	 */
	private static function integer( int $fallback, int $minimum, int $maximum ): array {
		return array(
			'type'    => 'integer',
			'default' => $fallback,
			'minimum' => $minimum,
			'maximum' => $maximum,
		);
	}

	/**
	 * Number field.
	 *
	 * @param float $fallback Default.
	 * @param float $minimum  Minimum.
	 * @param float $maximum  Maximum.
	 * @return array<string, mixed>
	 */
	private static function number( float $fallback, float $minimum, float $maximum ): array {
		return array(
			'type'    => 'number',
			'default' => $fallback,
			'minimum' => $minimum,
			'maximum' => $maximum,
		);
	}

	/**
	 * Boolean field.
	 *
	 * @param bool $fallback Default.
	 * @return array<string, mixed>
	 */
	private static function boolean( bool $fallback ): array {
		return array(
			'type'    => 'boolean',
			'default' => $fallback,
		);
	}
}
