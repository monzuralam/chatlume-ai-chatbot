<?php
/**
 * Built-in launcher icons.
 *
 * @package Chatlume
 */

namespace Chatlume\Appearance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Path data of the built-in launcher icons (24×24 line drawings, drawn for
 * Chatlume). The widget configuration carries only the chosen icon, so the
 * launcher script does not have to include all of them. The same paths are
 * in src/widget/launcher/icons.ts for the Customizer (a test keeps them in
 * sync).
 */
final class LauncherIcons {

	/**
	 * Icon name => path data.
	 */
	public const PATHS = array(
		'chat'      => 'M4 5h16v11H9l-5 4z',
		'chat-dots' => 'M4 5h16v11H9l-5 4zM8.5 10.5h.01M12 10.5h.01M15.5 10.5h.01',
		'message'   => 'M12 4c4.4 0 8 3 8 6.8s-3.6 6.7-8 6.7c-1 0-2-.1-2.9-.4L5 19l1-3.6C4.7 14.2 4 12.6 4 10.8 4 7 7.6 4 12 4z',
		'sparkle'   => 'M12 3l1.9 5.6 5.6 1.9-5.6 1.9L12 18l-1.9-5.6-5.6-1.9 5.6-1.9zM19 16l.8 2.2 2.2.8-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z',
		'help'      => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01',
		'support'   => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5.6 5.6l3.6 3.6M14.8 14.8l3.6 3.6M18.4 5.6l-3.6 3.6M9.2 14.8l-3.6 3.6',
		'headset'   => 'M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v5H5a1 1 0 0 1-1-1zM20 14h-3v5h2a1 1 0 0 0 1-1zM17 19c0 1.1-1.3 2-3 2h-2',
		'bot'       => 'M6 9h12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2zM12 9V5M12 5h.01M9 13.5v1M15 13.5v1',
		'smile'     => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8.5 14a4.5 4.5 0 0 0 7 0M9 9.5h.01M15 9.5h.01',
		'wave'      => 'M3 7c2-3 4-3 6 0s4 3 6 0 4-3 6 0M3 12c2-3 4-3 6 0s4 3 6 0 4-3 6 0M3 17c2-3 4-3 6 0s4 3 6 0 4-3 6 0',
		'lightbulb' => 'M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z',
		'heart'     => 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z',
	);

	/**
	 * Path data of an icon (the chat bubble for unknown names).
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	public static function path( string $name ): string {
		return self::PATHS[ $name ] ?? self::PATHS['chat'];
	}
}
