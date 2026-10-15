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
				// Buttons in the empty chat: open a URL, email, call, WhatsApp, or send a preset message.
				'quick_actions'       => array(
					'type'     => 'array',
					'maxItems' => 4,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'label' => self::text( '', 40 ),
							'type'  => array(
								'type'    => 'string',
								'format'  => 'key',
								'default' => 'url',
								'enum'    => array( 'url', 'email', 'phone', 'whatsapp', 'message' ),
							),
							'value' => self::text( '', 500 ),
						),
					),
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
	 * Widget display.
	 *
	 * @return array<string, mixed>
	 */
	public static function display(): array {
		return self::section(
			__( 'Where the widget appears.', 'chatlume-ai-chatbot' ),
			array(
				// Floating launcher on every page; the shortcode and block work regardless.
				'enabled'      => self::boolean( true ),
				// Load the chat app while the browser is idle instead of on the first click.
				'preload'      => self::boolean( false ),
				// Which pages show the floating launcher: all, only matching, or all except matching.
				'pages_mode'   => self::choice( 'all', array( 'all', 'include', 'exclude' ) ),
				'page_ids'     => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => self::integer( 0, 0, PHP_INT_MAX ),
				),
				'post_types'   => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => self::text( '', 20, 'key' ),
				),
				// Paths such as /shop/* (the * matches anything).
				'url_patterns' => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => self::text( '', 200, 'url-pattern' ),
				),
				// Checked in the browser, so cached pages stay correct.
				'devices'      => self::choice( 'all', array( 'all', 'desktop', 'mobile' ) ),
				'visitors'     => self::choice( 'all', array( 'all', 'logged_in', 'logged_out' ) ),
				// Logged-in visitors with any of these roles (empty: any role).
				'roles'        => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => self::text( '', 60, 'key' ),
				),
				'hours'        => self::section(
					__( 'Business hours in the site timezone.', 'chatlume-ai-chatbot' ),
					array(
						'enabled'  => self::boolean( false ),
						// Outside the hours: show the offline message, or hide the floating launcher.
						'behavior' => self::choice( 'offline', array( 'offline', 'hide' ) ),
						'days'     => self::section(
							__( 'Opening hours per day.', 'chatlume-ai-chatbot' ),
							array(
								'mon' => self::day( true ),
								'tue' => self::day( true ),
								'wed' => self::day( true ),
								'thu' => self::day( true ),
								'fri' => self::day( true ),
								'sat' => self::day( false ),
								'sun' => self::day( false ),
							)
						),
					)
				),
			)
		);
	}

	/**
	 * One day of business hours. An end before the start runs past midnight;
	 * equal times mean open all day.
	 *
	 * @param bool $open Open by default.
	 * @return array<string, mixed>
	 */
	private static function day( bool $open ): array {
		return self::section(
			__( 'Opening hours.', 'chatlume-ai-chatbot' ),
			array(
				'open'  => self::boolean( $open ),
				'start' => self::text( '09:00', 5, 'time' ),
				'end'   => self::text( '17:00', 5, 'time' ),
			)
		);
	}

	/**
	 * Choice from a fixed list.
	 *
	 * @param string   $fallback Default.
	 * @param string[] $choices  Allowed values.
	 * @return array<string, mixed>
	 */
	private static function choice( string $fallback, array $choices ): array {
		return array(
			'type'    => 'string',
			'format'  => 'key',
			'default' => $fallback,
			'enum'    => $choices,
		);
	}

	/**
	 * Streaming and server capacity.
	 *
	 * @return array<string, mixed>
	 */
	public static function streaming(): array {
		return self::section(
			__( 'Streaming replies.', 'chatlume-ai-chatbot' ),
			array(
				'enabled'             => self::boolean( true ),
				// Protects small hosts: extra visitors get non-streaming replies.
				'max_concurrent'      => self::integer( 3, 1, 20 ),
				// Always kept below the host's max_execution_time.
				'max_duration'        => self::integer( 60, 10, 300 ),
				// The widget shows "Retry" if no text arrives in this time.
				'first_token_timeout' => self::integer( 20, 5, 120 ),
			)
		);
	}

	/**
	 * Troubleshooting.
	 *
	 * @return array<string, mixed>
	 */
	public static function debug(): array {
		return self::section(
			__( 'Troubleshooting.', 'chatlume-ai-chatbot' ),
			array(
				// Records chat pipeline summaries (no message text) in a capped log viewable in Settings.
				'enabled' => self::boolean( false ),
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
