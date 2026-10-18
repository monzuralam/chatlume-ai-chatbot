<?php
/**
 * Where and when the widget appears.
 *
 * @package Chatlume
 */

namespace Chatlume\Frontend;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display rules for the floating launcher and business hours.
 *
 * Page, post type, address, and visitor rules are decided on the server when
 * the page is built. Device and business-hours rules are decided in the
 * browser (see `client_config()`), because cached pages would otherwise keep
 * showing the state from the moment they were cached. The chat endpoints
 * enforce business hours again on the server.
 */
class DisplayRules {

	/**
	 * Day keys indexed like PHP's date( 'w' ) and JavaScript's getDay().
	 */
	public const DAYS = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/**
	 * Whether the current request passes the page and visitor rules.
	 *
	 * @return bool
	 */
	public static function matches_request(): bool {
		$display = (array) Settings::get( 'display', array() );
		$user    = wp_get_current_user();

		return self::page_matches( $display, self::request_context() )
			&& self::visitor_matches( $display, is_user_logged_in(), (array) $user->roles );
	}

	/**
	 * What the current request shows: the post or page, its post type (single
	 * view or archive), and the address path.
	 *
	 * @return array{object_id: int, post_type: string, path: string}
	 */
	public static function request_context(): array {
		$object_id = 0;
		$post_type = '';

		if ( is_singular() ) {
			$object_id = (int) get_queried_object_id();
			$post_type = (string) get_post_type( $object_id );
		} elseif ( is_home() && ! is_front_page() ) {
			// The page chosen as the "Posts page".
			$object_id = (int) get_queried_object_id();
			$post_type = 'post';
		} elseif ( is_post_type_archive() ) {
			$object    = get_queried_object();
			$post_type = $object instanceof \WP_Post_Type ? $object->name : '';
		}

		return array(
			'object_id' => $object_id,
			'post_type' => $post_type,
			'path'      => self::current_path(),
		);
	}

	/**
	 * Whether a page passes the page rules.
	 *
	 * @param array<string, mixed>                                   $display Display settings.
	 * @param array{object_id: int, post_type: string, path: string} $context Request context.
	 * @return bool
	 */
	public static function page_matches( array $display, array $context ): bool {
		$mode = (string) ( $display['pages_mode'] ?? 'all' );
		if ( 'include' !== $mode && 'exclude' !== $mode ) {
			return true;
		}

		$ids     = array_map( 'intval', (array) ( $display['page_ids'] ?? array() ) );
		$types   = array_map( 'strval', (array) ( $display['post_types'] ?? array() ) );
		$matched = ( $context['object_id'] > 0 && in_array( $context['object_id'], $ids, true ) )
			|| ( '' !== $context['post_type'] && in_array( $context['post_type'], $types, true ) )
			|| self::path_matches( $context['path'], (array) ( $display['url_patterns'] ?? array() ) );

		return 'include' === $mode ? $matched : ! $matched;
	}

	/**
	 * Whether the visitor passes the logged-in and role rules.
	 *
	 * @param array<string, mixed> $display   Display settings.
	 * @param bool                 $logged_in Whether the visitor is logged in.
	 * @param string[]             $roles     The visitor's roles.
	 * @return bool
	 */
	public static function visitor_matches( array $display, bool $logged_in, array $roles ): bool {
		switch ( (string) ( $display['visitors'] ?? 'all' ) ) {
			case 'logged_out':
				return ! $logged_in;
			case 'logged_in':
				$allowed = (array) ( $display['roles'] ?? array() );
				return $logged_in && ( array() === $allowed || array() !== array_intersect( $allowed, $roles ) );
			default:
				return true;
		}
	}

	/**
	 * Whether a path matches any pattern. `*` matches anything, and
	 * "/shop/*" also matches "/shop" itself. Case does not matter.
	 *
	 * @param string   $path     Normalized path.
	 * @param string[] $patterns Normalized patterns.
	 * @return bool
	 */
	public static function path_matches( string $path, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' === $pattern ) {
				continue;
			}

			$optional_tail = '/*' === substr( $pattern, -2 ) && strlen( $pattern ) > 2;
			$base          = $optional_tail ? substr( $pattern, 0, -2 ) : $pattern;
			$regex         = str_replace( '\*', '.*', preg_quote( $base, '#' ) ) . ( $optional_tail ? '(/.*)?' : '' );

			if ( 1 === preg_match( '#^' . $regex . '$#iu', $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes a page address pattern: a full URL becomes its path, a
	 * leading slash is added, the trailing slash is removed, and characters
	 * that cannot appear in a path are dropped.
	 *
	 * @param string $value Pattern as typed.
	 * @return string Empty when nothing usable is left.
	 */
	public static function normalize_pattern( string $value ): string {
		$value = trim( $value );
		if ( 1 === preg_match( '#^https?://#i', $value ) ) {
			$value = (string) wp_parse_url( $value, PHP_URL_PATH );
			$value = '' === $value ? '/' : $value;
		}

		$value = (string) preg_replace( '#[^\p{L}\p{N}\-._~/%*+:@!$&\'(),;=]#u', '', $value );
		$value = (string) preg_replace( '#\*+#', '*', $value );
		$value = (string) preg_replace( '#/+#', '/', $value );
		if ( '' === $value ) {
			return '';
		}

		if ( '/' !== $value[0] && '*' !== $value[0] ) {
			$value = '/' . $value;
		}
		if ( strlen( $value ) > 1 ) {
			$value = rtrim( $value, '/' );
		}

		return mb_strtolower( '' === $value ? '/' : $value );
	}

	/**
	 * The current request path relative to the site address, decoded,
	 * lowercase, without a trailing slash ("/" for the home page).
	 *
	 * @return string
	 */
	public static function current_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $base && 0 === stripos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		} elseif ( '' !== $base && 0 === strcasecmp( $path, $base ) ) {
			$path = '/';
		}

		$path = '/' . ltrim( $path, '/' );

		return mb_strtolower( strlen( $path ) > 1 ? rtrim( $path, '/' ) : '/' );
	}

	/**
	 * Whether the business is open at a moment (in the site timezone). A day
	 * whose end is before its start runs past midnight; equal times mean the
	 * whole day.
	 *
	 * @param array<string, mixed> $days Hours per day key.
	 * @param \DateTimeImmutable   $now  Moment, in the site timezone.
	 * @return bool
	 */
	public static function is_open( array $days, \DateTimeImmutable $now ): bool {
		$weekday = (int) $now->format( 'w' );
		$minutes = (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' );

		$today     = self::day( $days, self::DAYS[ $weekday ] );
		$yesterday = self::day( $days, self::DAYS[ ( $weekday + 6 ) % 7 ] );

		if ( $today['open'] ) {
			if ( $today['start'] === $today['end'] ) {
				return true;
			}
			if ( $today['start'] < $today['end'] && $minutes >= $today['start'] && $minutes < $today['end'] ) {
				return true;
			}
			if ( $today['end'] < $today['start'] && $minutes >= $today['start'] ) {
				return true;
			}
		}

		// Yesterday's hours running past midnight.
		return $yesterday['open'] && $yesterday['end'] < $yesterday['start'] && $minutes < $yesterday['end'];
	}

	/**
	 * Whether business hours are on and the business is closed right now.
	 *
	 * @return bool
	 */
	public static function is_closed_now(): bool {
		if ( ! Settings::get( 'display.hours.enabled', false ) ) {
			return false;
		}

		return ! self::is_open( (array) Settings::get( 'display.hours.days', array() ), new \DateTimeImmutable( 'now', wp_timezone() ) );
	}

	/**
	 * Rules the browser applies: device, business hours, and proactive
	 * invitations for this page.
	 *
	 * @return array{devices: string, hours: array<string, mixed>, triggers: array<string, mixed>|null}
	 */
	public static function client_config(): array {
		$hours = (array) Settings::get( 'display.hours', array() );

		return array(
			'devices'  => (string) Settings::get( 'display.devices', 'all' ),
			'hours'    => array(
				'enabled'  => ! empty( $hours['enabled'] ),
				'behavior' => (string) ( $hours['behavior'] ?? 'offline' ),
				'timezone' => wp_timezone_string(),
				'days'     => (array) ( $hours['days'] ?? array() ),
			),
			'triggers' => self::triggers( (array) Settings::get( 'display.triggers', array() ) ),
		);
	}

	/**
	 * The invitation for a page, or null when invitations are off or the
	 * page is not one of the chosen ones. Matched per address, so cached
	 * pages stay correct.
	 *
	 * @param array<string, mixed>                                        $triggers Trigger settings.
	 * @param array{object_id: int, post_type: string, path: string}|null $context  Request context (default: the current request).
	 * @return array{action: string, message: string, delay: int, scroll: int, exit: bool, frequency: string, skipReturning: bool}|null
	 */
	public static function triggers( array $triggers, ?array $context = null ): ?array {
		if ( empty( $triggers['enabled'] ) ) {
			return null;
		}

		$rules = array(
			'pages_mode'   => 'include' === ( $triggers['pages'] ?? 'all' ) ? 'include' : 'all',
			'page_ids'     => (array) ( $triggers['page_ids'] ?? array() ),
			'url_patterns' => (array) ( $triggers['url_patterns'] ?? array() ),
		);
		if ( ! self::page_matches( $rules, $context ?? self::request_context() ) ) {
			return null;
		}

		$message = trim( WidgetTexts::translate( 'display.triggers.message', trim( (string) ( $triggers['message'] ?? '' ) ) ) );

		return array(
			'action'        => 'open' === ( $triggers['action'] ?? '' ) ? 'open' : 'teaser',
			'message'       => '' !== $message ? $message : __( 'Hi there! Have a question? I can help.', 'chatlume-ai-chatbot' ),
			'delay'         => max( 0, min( 600, (int) ( $triggers['delay'] ?? 0 ) ) ),
			'scroll'        => max( 0, min( 100, (int) ( $triggers['scroll'] ?? 0 ) ) ),
			'exit'          => ! empty( $triggers['exit_intent'] ),
			'frequency'     => in_array( $triggers['frequency'] ?? '', array( 'session', 'day', 'week', 'always' ), true ) ? (string) $triggers['frequency'] : 'day',
			'skipReturning' => ! empty( $triggers['skip_returning'] ),
		);
	}

	/**
	 * One day's hours as minutes since midnight.
	 *
	 * @param array<string, mixed> $days Hours per day key.
	 * @param string               $key  Day key.
	 * @return array{open: bool, start: int, end: int}
	 */
	private static function day( array $days, string $key ): array {
		$day = (array) ( $days[ $key ] ?? array() );

		return array(
			'open'  => ! empty( $day['open'] ),
			'start' => self::minutes( (string) ( $day['start'] ?? '00:00' ) ),
			'end'   => self::minutes( (string) ( $day['end'] ?? '00:00' ) ),
		);
	}

	/**
	 * Minutes since midnight of an HH:MM time.
	 *
	 * @param string $time Time.
	 * @return int
	 */
	private static function minutes( string $time ): int {
		$parts = explode( ':', $time );

		return min( 1439, max( 0, (int) $parts[0] * 60 + (int) ( $parts[1] ?? 0 ) ) );
	}
}
