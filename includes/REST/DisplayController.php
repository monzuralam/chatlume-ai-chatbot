<?php
/**
 * REST: choices for the Display page.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Frontend\DisplayRules;
use Chatlume\Frontend\Shortcode;
use Chatlume\Plugin;
use Chatlume\Settings\Settings;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET chatlume/v1/display: the post types and roles to choose from, the
 * titles of the chosen pages, the site timezone, and whether business hours
 * are open right now. Rules themselves are saved through /settings.
 */
class DisplayController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/display',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Only Chatlume managers.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( Plugin::current_user_can_manage() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage where the chat appears.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /display.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item(): WP_REST_Response {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		$response = new WP_REST_Response(
			array(
				'post_types' => self::post_types(),
				'roles'      => self::roles(),
				'pages'      => self::pages( array_map( 'intval', (array) Settings::get( 'display.page_ids', array() ) ) ),
				'timezone'   => array(
					'name'  => wp_timezone_string(),
					'label' => self::timezone_label( $now ),
				),
				'now'        => array(
					'day'  => DisplayRules::DAYS[ (int) $now->format( 'w' ) ],
					'time' => $now->format( 'H:i' ),
					'open' => DisplayRules::is_open( (array) Settings::get( 'display.hours.days', array() ), $now ),
				),
				'shortcode'  => '[' . Shortcode::TAG . ' height="600"]',
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Public post types with an admin screen.
	 *
	 * @return array<int, array{name: string, label: string}>
	 */
	public static function post_types(): array {
		$types = array();
		foreach ( get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$types[] = array(
				'name'  => (string) $type->name,
				'label' => (string) $type->labels->name,
			);
		}

		return $types;
	}

	/**
	 * Site roles.
	 *
	 * @return array<int, array{slug: string, name: string}>
	 */
	public static function roles(): array {
		$roles = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[] = array(
				'slug' => (string) $slug,
				'name' => translate_user_role( (string) $name ),
			);
		}

		return $roles;
	}

	/**
	 * Titles of the chosen pages that still exist.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int, array{id: int, title: string, type: string}>
	 */
	public static function pages( array $ids ): array {
		$ids = array_values( array_filter( $ids ) );
		if ( array() === $ids ) {
			return array();
		}

		$pages = array();
		foreach ( get_posts(
			array(
				'post__in'       => $ids,
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'private', 'draft', 'future', 'pending' ),
				'posts_per_page' => count( $ids ),
				'orderby'        => 'post__in',
			)
		) as $post ) {
			$type    = get_post_type_object( $post->post_type );
			$pages[] = array(
				'id'    => (int) $post->ID,
				'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'type'  => $type ? (string) $type->labels->singular_name : (string) $post->post_type,
			);
		}

		return $pages;
	}

	/**
	 * "Europe/Berlin (UTC+2)" or "UTC+5:30".
	 *
	 * @param \DateTimeImmutable $now Current time in the site timezone.
	 * @return string
	 */
	private static function timezone_label( \DateTimeImmutable $now ): string {
		$offset  = $now->getOffset();
		$hours   = intdiv( abs( $offset ), HOUR_IN_SECONDS );
		$minutes = intdiv( abs( $offset ) % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		$utc     = 'UTC' . ( $offset < 0 ? '-' : '+' ) . $hours . ( $minutes ? sprintf( ':%02d', $minutes ) : '' );
		$name    = wp_timezone_string();

		return false !== strpos( $name, '/' ) ? $name . ' (' . $utc . ')' : $utc;
	}
}
