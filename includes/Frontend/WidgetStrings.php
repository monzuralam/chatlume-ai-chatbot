<?php
/**
 * Widget interface text.
 *
 * @package Chatlume
 */

namespace Chatlume\Frontend;

use Chatlume\Chat\BotText;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every visitor-facing label of the widget, translated in PHP so the widget
 * needs no JavaScript translation files.
 */
final class WidgetStrings {

	/**
	 * All strings, keyed by the names the widget uses.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'open'         => __( 'Open chat', 'chatlume-ai-chatbot' ),
			'close'        => __( 'Close chat', 'chatlume-ai-chatbot' ),
			'menu'         => __( 'Chat options', 'chatlume-ai-chatbot' ),
			'newChat'      => __( 'New conversation', 'chatlume-ai-chatbot' ),
			'placeholder'  => __( 'Type your message…', 'chatlume-ai-chatbot' ),
			'send'         => __( 'Send', 'chatlume-ai-chatbot' ),
			'stop'         => __( 'Stop generating', 'chatlume-ai-chatbot' ),
			'retry'        => __( 'Retry', 'chatlume-ai-chatbot' ),
			'copy'         => __( 'Copy', 'chatlume-ai-chatbot' ),
			'copied'       => __( 'Copied', 'chatlume-ai-chatbot' ),
			'copyCode'     => __( 'Copy code', 'chatlume-ai-chatbot' ),
			'helpful'      => __( 'Helpful', 'chatlume-ai-chatbot' ),
			'notHelpful'   => __( 'Not helpful', 'chatlume-ai-chatbot' ),
			'thanks'       => __( 'Thanks for your feedback!', 'chatlume-ai-chatbot' ),
			'askAi'        => __( 'Not what you meant? Ask AI', 'chatlume-ai-chatbot' ),
			'sources'      => __( 'Sources', 'chatlume-ai-chatbot' ),
			'typing'       => __( 'Typing…', 'chatlume-ai-chatbot' ),
			'online'       => __( 'Online', 'chatlume-ai-chatbot' ),
			'away'         => __( 'Away', 'chatlume-ai-chatbot' ),
			'you'          => __( 'You', 'chatlume-ai-chatbot' ),
			'conversation' => __( 'Conversation', 'chatlume-ai-chatbot' ),
			'newMessage'   => __( 'New message', 'chatlume-ai-chatbot' ),
			'timeout'      => __( 'This is taking longer than usual. Please try again.', 'chatlume-ai-chatbot' ),
			'error'        => BotText::error(),
			'offline'      => BotText::offline(),
			'closed'       => __( 'We are away right now', 'chatlume-ai-chatbot' ),
			'connection'   => __( 'Connection problem. Check your internet connection and try again.', 'chatlume-ai-chatbot' ),
			'busyTab'      => __( 'A reply is being written in another tab…', 'chatlume-ai-chatbot' ),
			'stopped'      => __( 'Stopped.', 'chatlume-ai-chatbot' ),
			/* translators: 1: Characters typed, 2: Maximum characters. */
			'charCount'    => __( '%1$d / %2$d', 'chatlume-ai-chatbot' ),
			'poweredBy'    => __( 'Powered by Chatlume', 'chatlume-ai-chatbot' ),
			'externalLink' => __( '(opens in a new tab)', 'chatlume-ai-chatbot' ),
		);
	}
}
