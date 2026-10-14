<?php
/**
 * PHPUnit bootstrap: unit tests run without WordPress. WordPress functions
 * are mocked per test with Brain Monkey; a few core classes and pure helper
 * functions are stubbed in stubs.php.
 *
 * @package Chatlume
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/chatlume-tests-wordpress/' );
}

define( 'CHATLUME_VERSION', '0.1.0' );
define( 'CHATLUME_FILE', dirname( __DIR__, 2 ) . '/chatlume-ai-chatbot.php' );
define( 'CHATLUME_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'CHATLUME_URL', 'https://example.test/wp-content/plugins/chatlume-ai-chatbot/' );
define( 'CHATLUME_BASENAME', 'chatlume-ai-chatbot/chatlume-ai-chatbot.php' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs.php';
