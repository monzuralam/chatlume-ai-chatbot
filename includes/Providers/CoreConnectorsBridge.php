<?php
/**
 * WordPress Connectors bridge.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

use WordPress\AiClient\AiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers the AI providers connected in WordPress (Settings > Connectors,
 * WordPress 7.0+) as Chatlume providers. Requests go through WordPress's
 * AI Client, which adds the saved key itself, so Chatlume never reads,
 * copies, or stores those keys. Nothing changes on older WordPress
 * versions or where AI is turned off.
 */
class CoreConnectorsBridge {

	/**
	 * Hooks the bridge.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'chatlume_register_providers', array( self::class, 'add_providers' ) );
		// Connectors exist from init (priority 15): rebuild a provider list made earlier in the request.
		add_action( 'init', array( ProviderRegistry::class, 'reset' ), 20 );
	}

	/**
	 * Whether this WordPress can use connected providers.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'wp_get_connectors' )
			&& function_exists( 'wp_ai_client_prompt' )
			&& function_exists( 'wp_supports_ai' )
			&& wp_supports_ai()
			&& class_exists( AiClient::class )
			&& did_action( 'init' ) > 0;
	}

	/**
	 * Adds one provider per AI connector whose provider plugin is active
	 * (connected or not, so a choice survives a missing key).
	 *
	 * @param mixed $providers Providers by ID.
	 * @return mixed
	 */
	public static function add_providers( $providers ) {
		if ( ! is_array( $providers ) ) {
			return $providers;
		}

		foreach ( self::connectors() as $core_id => $connector ) {
			$provider = new CoreAiProvider( $core_id, $connector['name'], $connector['credentials_url'] );
			if ( ! isset( $providers[ $provider->get_id() ] ) ) {
				$providers[ $provider->get_id() ] = $provider;
			}
		}

		return $providers;
	}

	/**
	 * AI connectors with an active provider: core ID => {name, credentials_url}.
	 *
	 * @return array<string, array{name: string, credentials_url: string}>
	 */
	public static function connectors(): array {
		if ( ! self::available() ) {
			return array();
		}

		try {
			$registry = AiClient::defaultRegistry();
		} catch ( \Throwable $error ) {
			return array();
		}

		$found = array();
		foreach ( (array) wp_get_connectors() as $id => $connector ) {
			if ( ! is_string( $id ) || ! is_array( $connector ) || 'ai_provider' !== ( $connector['type'] ?? '' ) ) {
				continue;
			}
			try {
				if ( ! $registry->hasProvider( $id ) ) {
					continue;
				}
			} catch ( \Throwable $error ) {
				continue;
			}

			$url          = (string) ( $connector['authentication']['credentials_url'] ?? '' );
			$found[ $id ] = array(
				'name'            => wp_strip_all_tags( (string) ( $connector['name'] ?? $id ) ),
				'credentials_url' => (string) wp_http_validate_url( $url ) ? esc_url_raw( $url ) : '',
			);
		}

		return $found;
	}
}
