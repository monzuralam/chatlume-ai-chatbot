<?php
/**
 * Visitor-facing text with built-in defaults.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the admin's text, or a polished default when the setting is empty.
 * Defaults depend on the chosen persona preset.
 */
final class BotText {

	/**
	 * Selected preset.
	 *
	 * @return string support, sales, faq, or blank.
	 */
	public static function preset(): string {
		$preset = (string) Settings::get( 'chatbot.preset', 'support' );

		return in_array( $preset, array( 'support', 'sales', 'faq', 'blank' ), true ) ? $preset : 'support';
	}

	/**
	 * Bot name.
	 *
	 * @return string
	 */
	public static function bot_name(): string {
		return self::setting( 'chatbot.bot_name', __( 'Assistant', 'chatlume-ai-chatbot' ) );
	}

	/**
	 * Welcome message (may contain limited HTML: bold, italics, links).
	 *
	 * @return string
	 */
	public static function welcome(): string {
		return self::setting( 'chatbot.welcome_message', self::default_welcome( self::preset() ) );
	}

	/**
	 * The preset's default welcome message.
	 *
	 * @param string $preset Preset.
	 * @return string
	 */
	public static function default_welcome( string $preset ): string {
		$defaults = array(
			'support' => __( 'Hi there! 👋 How can I help you today?', 'chatlume-ai-chatbot' ),
			'sales'   => __( 'Hi! Looking for something specific? I can help you find the right fit.', 'chatlume-ai-chatbot' ),
			'faq'     => __( 'Hi! Ask me anything about us and I’ll answer right away.', 'chatlume-ai-chatbot' ),
			'blank'   => __( 'Hi! How can I help?', 'chatlume-ai-chatbot' ),
		);

		return $defaults[ $preset ] ?? $defaults['support'];
	}

	/**
	 * Starter questions.
	 *
	 * @return string[]
	 */
	public static function starters(): array {
		$saved = array_values( array_filter( (array) Settings::get( 'chatbot.starter_questions', array() ), 'is_string' ) );

		return array() !== $saved ? $saved : self::default_starters( self::preset() );
	}

	/**
	 * The preset's default starter questions.
	 *
	 * @param string $preset Preset.
	 * @return string[]
	 */
	public static function default_starters( string $preset ): array {
		$defaults = array(
			'support' => array(
				__( 'How can I contact you?', 'chatlume-ai-chatbot' ),
				__( 'What do you offer?', 'chatlume-ai-chatbot' ),
				__( 'How do I get started?', 'chatlume-ai-chatbot' ),
			),
			'sales'   => array(
				__( 'Which option is right for me?', 'chatlume-ai-chatbot' ),
				__( 'How much does it cost?', 'chatlume-ai-chatbot' ),
				__( 'What makes you different?', 'chatlume-ai-chatbot' ),
			),
			'faq'     => array(
				__( 'What do you offer?', 'chatlume-ai-chatbot' ),
				__( 'How does it work?', 'chatlume-ai-chatbot' ),
				__( 'How can I contact you?', 'chatlume-ai-chatbot' ),
			),
			'blank'   => array(),
		);

		return $defaults[ $preset ] ?? $defaults['support'];
	}

	/**
	 * Built-in texts used when a setting is empty, per preset, for the admin.
	 *
	 * @return array{bot_name: string, refusal: string, offline: string, error: string, site_search: string, presets: array<string, array{prompt: string, welcome: string, starters: string[]}>}
	 */
	public static function defaults(): array {
		$presets = array();
		foreach ( array( 'support', 'sales', 'faq', 'blank' ) as $preset ) {
			$presets[ $preset ] = array(
				'prompt'   => PromptBuilder::preset_prompt( $preset ),
				'welcome'  => self::default_welcome( $preset ),
				'starters' => self::default_starters( $preset ),
			);
		}

		return array(
			'bot_name'    => __( 'Assistant', 'chatlume-ai-chatbot' ),
			'refusal'     => self::default_refusal(),
			'offline'     => self::default_offline(),
			'error'       => self::default_error(),
			'site_search' => self::default_site_search(),
			'presets'     => $presets,
		);
	}

	/**
	 * Reply when a question is off-topic or contains a blocked word.
	 *
	 * @return string
	 */
	public static function refusal(): string {
		return self::setting( 'chatbot.refusal_message', self::default_refusal() );
	}

	/**
	 * Built-in refusal.
	 *
	 * @return string
	 */
	public static function default_refusal(): string {
		return __( 'Sorry, I can only help with questions about this website.', 'chatlume-ai-chatbot' );
	}

	/**
	 * Reply when the bot is offline (for example when a budget is used up).
	 *
	 * @return string
	 */
	public static function offline(): string {
		return self::setting( 'chatbot.offline_message', self::default_offline() );
	}

	/**
	 * Built-in offline message.
	 *
	 * @return string
	 */
	public static function default_offline(): string {
		return __( 'Our assistant is taking a short break. Please try again later or contact us directly.', 'chatlume-ai-chatbot' );
	}

	/**
	 * Reply when something goes wrong.
	 *
	 * @return string
	 */
	public static function error(): string {
		return self::setting( 'chatbot.error_message', self::default_error() );
	}

	/**
	 * Built-in error message.
	 *
	 * @return string
	 */
	public static function default_error(): string {
		return __( 'Sorry, I’m having trouble answering right now. Please try again in a moment.', 'chatlume-ai-chatbot' );
	}

	/**
	 * Introduction shown above site-search results.
	 *
	 * @return string
	 */
	public static function site_search(): string {
		return self::setting( 'chatbot.site_search_message', self::default_site_search() );
	}

	/**
	 * Built-in introduction to site-search results.
	 *
	 * @return string
	 */
	public static function default_site_search(): string {
		return __( 'I couldn’t answer that directly, but these pages might help:', 'chatlume-ai-chatbot' );
	}

	/**
	 * Reply when site search finds nothing either.
	 *
	 * @return string
	 */
	public static function nothing_found(): string {
		return __( 'Sorry, I couldn’t find an answer to that. Could you rephrase your question, or contact us directly?', 'chatlume-ai-chatbot' );
	}

	/**
	 * Reply when a conversation reached its message limit.
	 *
	 * @return string
	 */
	public static function conversation_limit(): string {
		return __( 'This conversation has reached its limit. Please start a new conversation to keep chatting.', 'chatlume-ai-chatbot' );
	}

	/**
	 * Reply when the visitor sends messages too quickly.
	 *
	 * @return string
	 */
	public static function rate_limited(): string {
		return __( 'You’re sending messages a little too quickly. Please wait a moment and try again.', 'chatlume-ai-chatbot' );
	}

	/**
	 * A text setting, or the default when empty.
	 *
	 * @param string $path     Setting path.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function setting( string $path, string $fallback ): string {
		$value = trim( (string) Settings::get( $path, '' ) );

		return '' !== $value ? $value : $fallback;
	}
}
