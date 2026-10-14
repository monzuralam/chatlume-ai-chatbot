<?php
/**
 * Site search fallback.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

use Chatlume\Support\Text;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the most relevant public pages for a question with WordPress's own
 * search. Used when the AI is unavailable, not configured, or over budget,
 * so visitors always get something useful.
 *
 * WordPress search requires every term to match, which rarely works for
 * whole questions, so the meaningful words are tried together first and
 * then one by one (longest first) until enough pages are found.
 */
class SiteSearchFallback {

	/**
	 * Up to $limit matching pages.
	 *
	 * @param string $question Visitor question.
	 * @param int    $limit    Maximum results.
	 * @return array<int, array{title: string, url: string}>
	 */
	public function search( string $question, int $limit = 3 ): array {
		$terms = Text::tokens( $question );

		// Longest words are usually the most specific.
		usort(
			$terms,
			static function ( string $a, string $b ): int {
				return mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' );
			}
		);
		$terms = array_slice( $terms, 0, 5 );

		if ( array() === $terms ) {
			return array();
		}

		$queries = array_merge( array( implode( ' ', $terms ) ), $terms );
		$results = array();

		foreach ( array_unique( $queries ) as $query ) {
			foreach ( $this->query( $query, $limit, array_keys( $results ) ) as $id ) {
				$results[ $id ] = array(
					'title' => wp_strip_all_tags( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ),
					'url'   => (string) get_permalink( $id ),
				);
				if ( count( $results ) >= $limit ) {
					break 2;
				}
			}
		}

		return array_values( $results );
	}

	/**
	 * Runs one WordPress search.
	 *
	 * @param string $query   Search terms.
	 * @param int    $limit   Maximum results.
	 * @param int[]  $exclude Post IDs already found.
	 * @return int[]
	 */
	private function query( string $query, int $limit, array $exclude ): array {
		$search = new WP_Query(
			array(
				's'                   => $query,
				'post_type'           => array_values( get_post_types( array( 'exclude_from_search' => false ) ) ),
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => $limit,
				'post__not_in'        => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- At most a few IDs.
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		return array_map( 'intval', $search->posts );
	}
}
