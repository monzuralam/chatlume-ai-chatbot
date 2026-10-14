<?php
/**
 * Activation, deactivation, and the one-time welcome redirect.
 *
 * @package Chatlume
 */

namespace Chatlume\Installer;

use Chatlume\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation lifecycle.
 *
 * Redirect rules (WordPress.org guidelines): after activation the admin is
 * sent to the Chatlume screen once, and only for a single-plugin activation
 * in wp-admin; never on bulk activation, network activation, WP-CLI, AJAX,
 * or REST requests, and never for users who cannot manage Chatlume.
 */
class Activation {

	/**
	 * Transient holding the ID of the user who activated the plugin.
	 */
	public const REDIRECT_TRANSIENT = 'chatlume_activation_redirect';

	/**
	 * Hooks the redirect check.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Activation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		$network_wide = (bool) $network_wide;

		if ( is_multisite() && $network_wide ) {
			Multisite::install_all_sites();
		} else {
			Migrations::install_site();
		}

		if ( self::should_flag_redirect( $network_wide, self::is_cli() ) ) {
			set_transient( self::REDIRECT_TRANSIENT, get_current_user_id(), MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Deactivation hook callback: removes scheduled events and the redirect flag.
	 * Data is kept; it is only removed on uninstall when the admin opted in.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		delete_transient( self::REDIRECT_TRANSIENT );

		foreach ( self::scheduled_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Names of all scheduled `chatlume_*` cron hooks.
	 *
	 * @return string[]
	 */
	public static function scheduled_hooks(): array {
		$hooks = array();
		$crons = _get_cron_array();

		foreach ( $crons as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, 'chatlume_' ) ) {
					$hooks[ $hook ] = true;
				}
			}
		}

		return array_keys( $hooks );
	}

	/**
	 * Whether activation should set the redirect flag.
	 *
	 * @param bool $network_wide Network activation.
	 * @param bool $is_cli       Running under WP-CLI.
	 * @return bool
	 */
	public static function should_flag_redirect( bool $network_wide, bool $is_cli ): bool {
		return ! $network_wide && ! $is_cli;
	}

	/**
	 * Decides whether to redirect now. Pure function for testability.
	 *
	 * @param array<string, mixed> $context {
	 *     Request context.
	 *
	 *     @type int|false $flag_user        User ID stored in the transient, or false.
	 *     @type int       $current_user     Current user ID.
	 *     @type bool      $can_manage       Current user can manage Chatlume.
	 *     @type bool      $bulk             Request follows a bulk activation.
	 *     @type bool      $is_network_admin Request is in the network admin.
	 *     @type bool      $is_ajax          AJAX request.
	 *     @type bool      $is_rest          REST request.
	 *     @type bool      $is_cli           WP-CLI.
	 * }
	 * @return bool
	 */
	public static function should_redirect( array $context ): bool {
		if ( false === $context['flag_user'] || null === $context['flag_user'] ) {
			return false;
		}

		if ( $context['bulk'] || $context['is_network_admin'] || $context['is_ajax'] || $context['is_rest'] || $context['is_cli'] ) {
			return false;
		}

		return (int) $context['flag_user'] === (int) $context['current_user'] && (bool) $context['can_manage'];
	}

	/**
	 * Performs the one-time redirect on `admin_init`.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		$flag_user = get_transient( self::REDIRECT_TRANSIENT );

		if ( false === $flag_user ) {
			return;
		}

		$context = array(
			'flag_user'        => $flag_user,
			'current_user'     => get_current_user_id(),
			'can_manage'       => Plugin::current_user_can_manage(),
			// Read-only check of the flag core adds after a bulk activation; no data is processed.
			'bulk'             => isset( $_GET['activate-multi'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'is_network_admin' => is_network_admin(),
			'is_ajax'          => wp_doing_ajax(),
			'is_rest'          => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'is_cli'           => self::is_cli(),
		);

		// The flag is single-use: it is removed whether or not we redirect.
		if ( ! $context['is_ajax'] && ! $context['is_rest'] ) {
			delete_transient( self::REDIRECT_TRANSIENT );
		}

		if ( ! self::should_redirect( $context ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . Plugin::ADMIN_SLUG ) );
		exit;
	}

	/**
	 * Whether the current process is WP-CLI.
	 *
	 * @return bool
	 */
	private static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}
}
