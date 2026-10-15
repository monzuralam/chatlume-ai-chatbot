<?php
/**
 * System status for the admin.
 *
 * @package Chatlume
 */

namespace Chatlume\Admin;

use Chatlume\Cron\RetentionCleanup;
use Chatlume\Installer\Migrations;
use Chatlume\Installer\Schema;
use Chatlume\Plugin;
use Chatlume\Security\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects environment and installation status shown in the admin, and the
 * Health check: one entry per check with a plain-language fix.
 */
class HealthCheck {

	/**
	 * Check statuses, most to least severe.
	 */
	public const CRITICAL    = 'critical';
	public const RECOMMENDED = 'recommended';
	public const GOOD        = 'good';
	public const INFO        = 'info';

	/**
	 * Lowest PHP version that still gets security fixes (checked on 2026-10-15:
	 * PHP 8.2 is supported until the end of 2026).
	 */
	private const PHP_RECOMMENDED = '8.2';

	/**
	 * Caching and optimization plugins: name => detector (constant or class).
	 */
	private const OPTIMIZERS = array(
		'WP Rocket'            => array( 'constant', 'WP_ROCKET_VERSION' ),
		'LiteSpeed Cache'      => array( 'constant', 'LSCWP_V' ),
		'W3 Total Cache'       => array( 'constant', 'W3TC' ),
		'WP Super Cache'       => array( 'constant', 'WPCACHEHOME' ),
		'WP Fastest Cache'     => array( 'class', 'WpFastestCache' ),
		'SiteGround Optimizer' => array( 'class', 'SiteGround_Optimizer\Loader\Loader' ),
		'Autoptimize'          => array( 'constant', 'AUTOPTIMIZE_PLUGIN_VERSION' ),
		'Perfmatters'          => array( 'constant', 'PERFMATTERS_VERSION' ),
		'FlyingPress'          => array( 'constant', 'FLYING_PRESS_VERSION' ),
	);

	/**
	 * Snapshot of the installation and detected core features.
	 *
	 * Core features are detected by function/class existence so older
	 * WordPress versions never hit undefined functions.
	 *
	 * @return array<string, mixed>
	 */
	public static function snapshot(): array {
		return array(
			'wpVersion'         => (string) get_bloginfo( 'version' ),
			'phpVersion'        => PHP_VERSION,
			'dbVersion'         => (int) get_option( Migrations::DB_VERSION_OPTION, 0 ),
			'dbVersionExpected' => Migrations::DB_VERSION,
			'fulltext'          => 'yes' === get_option( Schema::FULLTEXT_OPTION, 'no' ),
			'encryption'        => ( new Encryption() )->status(),
			'isMultisite'       => is_multisite(),
			'features'          => array(
				'abilitiesApi'   => function_exists( 'wp_register_ability' ),
				'aiClient'       => function_exists( 'wp_ai_client_prompt' ),
				'connectors'     => function_exists( 'wp_get_connectors' ),
				'fontLibrary'    => class_exists( 'WP_Font_Library' ),
				'commandPalette' => wp_script_is( 'wp-commands', 'registered' ),
			),
		);
	}

	/**
	 * Runs every server-side check. (The live streaming test runs in the
	 * browser, because only the browser can tell whether replies arrive in
	 * pieces.)
	 *
	 * @return array<int, array{id: string, status: string, label: string, detail: string, fix: string}>
	 */
	public function checks(): array {
		return array(
			$this->php_version(),
			$this->database(),
			$this->encryption(),
			$this->http_transport(),
			$this->rest_api(),
			$this->cron(),
			$this->fulltext(),
			$this->time_limit(),
			$this->object_cache(),
			$this->optimizers(),
			$this->ai_client(),
		);
	}

	/**
	 * PHP version.
	 *
	 * @return array<string, string>
	 */
	private function php_version(): array {
		if ( version_compare( PHP_VERSION, self::PHP_RECOMMENDED, '>=' ) ) {
			return self::result(
				'php',
				self::GOOD,
				__( 'PHP version', 'chatlume-ai-chatbot' ),
				/* translators: %s: PHP version. */
				sprintf( __( 'Your site runs PHP %s.', 'chatlume-ai-chatbot' ), PHP_VERSION )
			);
		}

		return self::result(
			'php',
			self::RECOMMENDED,
			__( 'PHP version', 'chatlume-ai-chatbot' ),
			/* translators: %s: PHP version. */
			sprintf( __( 'Your site runs PHP %s, which no longer receives security updates. Chatlume works, but your site is less safe and slower.', 'chatlume-ai-chatbot' ), PHP_VERSION ),
			/* translators: %s: Recommended PHP version. */
			sprintf( __( 'Ask your hosting provider to switch your site to PHP %s or newer. Most hosts offer this in their control panel.', 'chatlume-ai-chatbot' ), self::PHP_RECOMMENDED )
		);
	}

	/**
	 * Database tables and version.
	 *
	 * @return array<string, string>
	 */
	private function database(): array {
		$current = (int) get_option( Migrations::DB_VERSION_OPTION, 0 );

		if ( Schema::tables_exist() && $current >= Migrations::DB_VERSION ) {
			return self::result( 'database', self::GOOD, __( 'Database tables', 'chatlume-ai-chatbot' ), __( 'All of Chatlume’s tables exist and are up to date.', 'chatlume-ai-chatbot' ) );
		}

		return self::result(
			'database',
			self::CRITICAL,
			__( 'Database tables', 'chatlume-ai-chatbot' ),
			__( 'Some of Chatlume’s tables are missing or out of date, so conversations and quick answers may not be saved.', 'chatlume-ai-chatbot' ),
			__( 'Deactivate and reactivate Chatlume on the Plugins screen. This repairs the tables without losing data. If it keeps failing, your database user may lack permission to create tables; ask your host.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * Encryption of stored API keys.
	 *
	 * @return array<string, string>
	 */
	private function encryption(): array {
		$label = __( 'API key encryption', 'chatlume-ai-chatbot' );

		switch ( ( new Encryption() )->status() ) {
			case 'ok':
				return self::result( 'encryption', self::GOOD, $label, __( 'Stored API keys are encrypted and readable.', 'chatlume-ai-chatbot' ) );
			case 'key_changed':
				return self::result(
					'encryption',
					self::CRITICAL,
					$label,
					__( 'Your site’s security keys (salts) changed, so saved API keys can no longer be read. The bot uses quick answers and site search until you fix this.', 'chatlume-ai-chatbot' ),
					__( 'Open Chatlume > Providers and re-enter your API keys. To avoid this in future, define keys in wp-config.php (for example CHATLUME_OPENAI_API_KEY).', 'chatlume-ai-chatbot' )
				);
			default:
				return self::result(
					'encryption',
					self::CRITICAL,
					$label,
					__( 'The libsodium encryption functions are not available, so API keys cannot be stored safely.', 'chatlume-ai-chatbot' ),
					__( 'Ask your hosting provider to enable the PHP sodium extension, or define your API keys in wp-config.php instead.', 'chatlume-ai-chatbot' )
				);
		}
	}

	/**
	 * HTTP transport used to reach AI providers.
	 *
	 * @return array<string, string>
	 */
	private function http_transport(): array {
		$label = __( 'Connection to AI providers', 'chatlume-ai-chatbot' );

		if ( function_exists( 'curl_init' ) && function_exists( 'curl_version' ) ) {
			$version = curl_version();
			return self::result(
				'http',
				self::GOOD,
				$label,
				/* translators: %s: cURL version. */
				sprintf( __( 'WordPress connects with cURL %s, which supports streaming and stopping replies quickly.', 'chatlume-ai-chatbot' ), (string) ( $version['version'] ?? '' ) )
			);
		}

		return self::result(
			'http',
			self::RECOMMENDED,
			$label,
			__( 'The PHP cURL extension is not available. Replies still work, but stopping a reply may take longer.', 'chatlume-ai-chatbot' ),
			__( 'Ask your hosting provider to enable the PHP cURL extension.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * The site can reach its own REST API (the widget depends on it).
	 *
	 * @return array<string, string>
	 */
	private function rest_api(): array {
		$label    = __( 'REST API', 'chatlume-ai-chatbot' );
		$response = wp_remote_get(
			rest_url( Plugin::REST_NAMESPACE . '/' ),
			array(
				'timeout'   => 10,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback requests, as used by Site Health.
			)
		);

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::result( 'rest', self::GOOD, $label, __( 'The chat widget can reach your site’s REST API.', 'chatlume-ai-chatbot' ) );
		}

		$detail = is_wp_error( $response )
			? $response->get_error_message()
			/* translators: %d: HTTP status code. */
			: sprintf( __( 'The REST API answered with HTTP status %d.', 'chatlume-ai-chatbot' ), (int) wp_remote_retrieve_response_code( $response ) );

		return self::result(
			'rest',
			self::CRITICAL,
			$label,
			/* translators: %s: Error details. */
			sprintf( __( 'Your site could not reach its own REST API, so visitors may not be able to chat. Details: %s', 'chatlume-ai-chatbot' ), $detail ),
			__( 'A security plugin or server rule may be blocking /wp-json/. Allow requests to /wp-json/chatlume/v1/ for visitors, or ask your host to check firewall rules. Tools > Site Health shows more details.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * WP-Cron runs scheduled tasks (cleanup, budget resets).
	 *
	 * @return array<string, string>
	 */
	private function cron(): array {
		$label = __( 'Scheduled tasks (WP-Cron)', 'chatlume-ai-chatbot' );
		$next  = wp_next_scheduled( RetentionCleanup::HOOK );

		if ( false === $next ) {
			return self::result(
				'cron',
				self::RECOMMENDED,
				$label,
				__( 'Chatlume’s daily cleanup is not scheduled, so old conversations are not removed automatically.', 'chatlume-ai-chatbot' ),
				__( 'Deactivate and reactivate Chatlume to schedule it again.', 'chatlume-ai-chatbot' )
			);
		}

		if ( $next < time() - 6 * HOUR_IN_SECONDS ) {
			return self::result(
				'cron',
				self::RECOMMENDED,
				$label,
				__( 'Scheduled tasks are running late, so the daily cleanup has not run on time.', 'chatlume-ai-chatbot' ),
				defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
					? __( 'WP-Cron is turned off in wp-config.php. Make sure a server cron job calls wp-cron.php regularly, or ask your host to set one up.', 'chatlume-ai-chatbot' )
					: __( 'WP-Cron runs only when someone visits your site. On quiet sites, ask your host to set up a server cron job for wp-cron.php.', 'chatlume-ai-chatbot' )
			);
		}

		return self::result( 'cron', self::GOOD, $label, __( 'Scheduled tasks run on time.', 'chatlume-ai-chatbot' ) );
	}

	/**
	 * FULLTEXT index for knowledge search.
	 *
	 * @return array<string, string>
	 */
	private function fulltext(): array {
		$label = __( 'Full-text search', 'chatlume-ai-chatbot' );

		if ( 'yes' === get_option( Schema::FULLTEXT_OPTION, 'no' ) ) {
			return self::result( 'fulltext', self::GOOD, $label, __( 'Your database supports fast full-text search of your knowledge base.', 'chatlume-ai-chatbot' ) );
		}

		return self::result(
			'fulltext',
			self::RECOMMENDED,
			$label,
			__( 'Your database does not support full-text search indexes. Chatlume uses a slower built-in search instead, which is fine for small sites.', 'chatlume-ai-chatbot' ),
			__( 'Ask your host for MySQL 5.6+ or MariaDB 10.0+ with InnoDB tables, then deactivate and reactivate Chatlume.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * Time limit for one request (read, never changed).
	 *
	 * @return array<string, string>
	 */
	private function time_limit(): array {
		$limit = (int) ini_get( 'max_execution_time' );
		$label = __( 'Request time limit', 'chatlume-ai-chatbot' );

		if ( $limit > 0 && $limit < 30 ) {
			return self::result(
				'time_limit',
				self::RECOMMENDED,
				$label,
				/* translators: %d: Seconds. */
				sprintf( __( 'PHP stops each request after %d seconds, so long replies may be cut short.', 'chatlume-ai-chatbot' ), $limit ),
				__( 'Ask your host to raise max_execution_time to at least 60 seconds.', 'chatlume-ai-chatbot' )
			);
		}

		return self::result(
			'time_limit',
			self::GOOD,
			$label,
			$limit > 0
				/* translators: %d: Seconds. */
				? sprintf( __( 'Requests may run for %d seconds; streamed replies always stop a few seconds before that.', 'chatlume-ai-chatbot' ), $limit )
				: __( 'Requests have no PHP time limit; streamed replies stop at Chatlume’s own limit.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * Persistent object cache.
	 *
	 * @return array<string, string>
	 */
	private function object_cache(): array {
		return self::result(
			'object_cache',
			self::INFO,
			__( 'Object cache', 'chatlume-ai-chatbot' ),
			wp_using_ext_object_cache()
				? __( 'A persistent object cache is active. Chatlume keeps rate limits and cached answers there.', 'chatlume-ai-chatbot' )
				: __( 'No persistent object cache. Chatlume keeps rate limits in its own table and cached answers as transients, which works on any host.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * Caching and optimization plugins.
	 *
	 * @return array<string, string>
	 */
	private function optimizers(): array {
		$found = self::detected_optimizers();
		$label = __( 'Caching and optimization plugins', 'chatlume-ai-chatbot' );

		if ( array() === $found ) {
			return self::result( 'optimizers', self::INFO, $label, __( 'None detected.', 'chatlume-ai-chatbot' ) );
		}

		return self::result(
			'optimizers',
			self::INFO,
			$label,
			/* translators: %s: Comma-separated plugin names. */
			sprintf( __( 'Detected: %s. Pages stay fully cacheable because Chatlume loads visitor data at runtime.', 'chatlume-ai-chatbot' ), implode( ', ', $found ) ),
			__( 'If the chat button does not appear, exclude scripts containing “chatlume” from JavaScript delay, defer, and combine features in that plugin’s settings.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * Names of active caching and optimization plugins.
	 *
	 * @return string[]
	 */
	public static function detected_optimizers(): array {
		$found = array();

		foreach ( self::OPTIMIZERS as $name => $detector ) {
			$active = 'constant' === $detector[0] ? defined( $detector[1] ) : class_exists( $detector[1], false );
			if ( $active ) {
				$found[] = $name;
			}
		}

		return $found;
	}

	/**
	 * WordPress AI Client (WordPress 7.0+).
	 *
	 * @return array<string, string>
	 */
	private function ai_client(): array {
		return self::result(
			'ai_client',
			self::INFO,
			__( 'WordPress AI Client', 'chatlume-ai-chatbot' ),
			function_exists( 'wp_ai_client_prompt' )
				? __( 'Available in this WordPress version. Chatlume connects to AI providers through its own provider settings.', 'chatlume-ai-chatbot' )
				: __( 'Not available in this WordPress version. Chatlume uses its own provider connections, so everything works.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * One check result.
	 *
	 * @param string $id     Check ID.
	 * @param string $status Status.
	 * @param string $label  Label.
	 * @param string $detail What was found.
	 * @param string $fix    How to fix it ('' when nothing to do).
	 * @return array{id: string, status: string, label: string, detail: string, fix: string}
	 */
	private static function result( string $id, string $status, string $label, string $detail, string $fix = '' ): array {
		return array(
			'id'     => $id,
			'status' => $status,
			'label'  => $label,
			'detail' => $detail,
			'fix'    => $fix,
		);
	}
}
