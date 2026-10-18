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
			'voiceStart'   => __( 'Speak your message', 'chatlume-ai-chatbot' ),
			'voiceStop'    => __( 'Stop listening', 'chatlume-ai-chatbot' ),
			'listening'    => __( 'Listening…', 'chatlume-ai-chatbot' ),
			'voiceBlocked' => __( 'Microphone access is blocked. Allow it in your browser settings, or type your message.', 'chatlume-ai-chatbot' ),
			'voiceFailed'  => __( 'Voice input did not work. Please type your message.', 'chatlume-ai-chatbot' ),
			'readAloud'    => __( 'Read aloud', 'chatlume-ai-chatbot' ),
			'stopReading'  => __( 'Stop reading', 'chatlume-ai-chatbot' ),
			'leadBefore'   => __( 'Before we start, how can we reach you?', 'chatlume-ai-chatbot' ),
			'leadAfter'    => __( 'Would you like us to follow up by email?', 'chatlume-ai-chatbot' ),
			'leadName'     => __( 'Name', 'chatlume-ai-chatbot' ),
			'leadEmail'    => __( 'Email', 'chatlume-ai-chatbot' ),
			'leadSubmit'   => __( 'Continue', 'chatlume-ai-chatbot' ),
			'leadSkip'     => __( 'Skip', 'chatlume-ai-chatbot' ),
			'leadThanks'   => __( 'Thanks! We have your details.', 'chatlume-ai-chatbot' ),
			'leadInvalid'  => __( 'Please enter a valid email address.', 'chatlume-ai-chatbot' ),
			'leadConsent'  => __( 'Please tick the box to continue.', 'chatlume-ai-chatbot' ),
			'talkToPerson' => __( 'Talk to a person', 'chatlume-ai-chatbot' ),
			'handoffTitle' => __( 'How would you like to reach us?', 'chatlume-ai-chatbot' ),
			'emailTeam'    => __( 'Email this conversation to the team', 'chatlume-ai-chatbot' ),
			'emailNeeded'  => __( 'Add your email so the team can reply:', 'chatlume-ai-chatbot' ),
			'emailTeamOk'  => __( 'Sent! The team will reply by email.', 'chatlume-ai-chatbot' ),
			'emailMe'      => __( 'Email me this conversation', 'chatlume-ai-chatbot' ),
			'emailMeOk'    => __( 'Sent to your email.', 'chatlume-ai-chatbot' ),
			'sending'      => __( 'Sending…', 'chatlume-ai-chatbot' ),
		);
	}
}
