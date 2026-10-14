<?php
/**
 * Uninstall routine.
 *
 * Removes Chatlume's data only for sites whose admin enabled
 * "Delete all data on uninstall" (Settings > Data & privacy).
 *
 * @package Chatlume
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Removes Chatlume's data from the current site if its admin opted in.
 *
 * @return bool Whether data was removed.
 */
function chatlume_uninstall_current_site(): bool {
	global $wpdb;

	$settings = get_option( \Chatlume\Settings\Settings::OPTION, array() );
	$opted_in = is_array( $settings ) && ! empty( $settings['privacy']['delete_data_on_uninstall'] );

	if ( ! $opted_in ) {
		return false;
	}

	\Chatlume\Installer\Schema::drop_tables( $wpdb->prefix );

	// Removes every chatlume_* option and transient of this site in one pass; there is no core API for prefix deletes.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'chatlume_' ) . '%',
			$wpdb->esc_like( '_transient_chatlume_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_chatlume_' ) . '%'
		)
	);

	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'options' );
	} else {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	return true;
}

if ( is_multisite() ) {
	$chatlume_main_site_opted_in = false;
	$chatlume_offset             = 0;

	do {
		$chatlume_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $chatlume_offset,
			)
		);

		foreach ( $chatlume_site_ids as $chatlume_site_id ) {
			switch_to_blog( (int) $chatlume_site_id );
			$chatlume_removed = chatlume_uninstall_current_site();
			if ( $chatlume_removed && is_main_site() ) {
				$chatlume_main_site_opted_in = true;
			}
			restore_current_blog();
		}

		$chatlume_offset  += 100;
		$chatlume_has_more = count( $chatlume_site_ids ) === 100;
	} while ( $chatlume_has_more );

	// User meta is shared by the whole network, so it follows the main site's choice.
	if ( $chatlume_main_site_opted_in ) {
		delete_metadata( 'user', 0, 'chatlume_admin_color_mode', '', true );
	}
} elseif ( chatlume_uninstall_current_site() ) {
	delete_metadata( 'user', 0, 'chatlume_admin_color_mode', '', true );
}
