<?php
/**
 * Keeps the knowledge index in step with the site.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

use Chatlume\Data\KnowledgeRepository;
use Chatlume\Settings\Settings;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indexes published content of the chosen post types, single items chosen
 * one by one, and the free-text business information. A post is re-indexed
 * when it is saved and removed when it is unpublished, trashed, or deleted.
 * Only public, published, non-password-protected content is ever indexed.
 */
class Indexer {

	/**
	 * Source type of posts of any post type.
	 */
	public const TYPE_POST = 'post';

	/**
	 * Source type of the business information.
	 */
	public const TYPE_BUSINESS = 'business';

	/**
	 * Storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeRepository|null $repository Storage (tests).
	 */
	public function __construct( ?KnowledgeRepository $repository = null ) {
		$this->repository = $repository ?? new KnowledgeRepository();
	}

	/**
	 * Hooks content changes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'on_save' ), 20, 2 );
		add_action( 'trashed_post', array( $this, 'on_remove' ) );
		add_action( 'before_delete_post', array( $this, 'on_remove' ) );
	}

	/**
	 * A post was saved (after its terms and meta).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function on_save( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		// Orders, menu items, and other unchosen content cost nothing.
		if ( ! in_array( $post->post_type, self::post_types(), true )
			&& ! in_array( $post->ID, array_map( 'intval', (array) Settings::get( 'knowledge.include_ids', array() ) ), true ) ) {
			return;
		}
		$this->index_post( $post );
	}

	/**
	 * A post was trashed or is being deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_remove( $post_id ): void {
		$this->repository->delete( self::TYPE_POST, (string) (int) $post_id );
	}

	/**
	 * Indexes a post, or removes it when it should not be indexed.
	 *
	 * @param WP_Post $post Post.
	 * @return int Chunks stored.
	 */
	public function index_post( WP_Post $post ): int {
		$id = (string) $post->ID;
		if ( ! self::eligible( $post ) ) {
			$this->repository->delete( self::TYPE_POST, $id );
			return 0;
		}

		/**
		 * Filters a source before it is indexed. Return null to leave it out.
		 *
		 * @param array{title: string, url: string, language: string, text: string}|null $source Source.
		 * @param WP_Post|null                                                              $post   Post, or null for other sources.
		 */
		$source = apply_filters(
			'chatlume_knowledge_sources',
			array(
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'      => (string) get_permalink( $post ),
				'language' => Language::of_post( $post->ID ),
				'text'     => self::text_of( $post ),
			),
			$post
		);

		return $this->store( self::TYPE_POST, $id, $source );
	}

	/**
	 * Indexes the business information from the settings.
	 *
	 * @return int Chunks stored.
	 */
	public function index_business_info(): int {
		$source = apply_filters(
			'chatlume_knowledge_sources',
			array(
				'title'    => __( 'About this business', 'chatlume-ai-chatbot' ),
				'url'      => '',
				// Written once for every language on multilingual sites.
				'language' => Language::multilingual() ? '' : Language::site(),
				'text'     => (string) Settings::get( 'knowledge.business_info', '' ),
			),
			null
		);

		return $this->store( self::TYPE_BUSINESS, 'info', $source );
	}

	/**
	 * Whether a post belongs in the index: published, public, no password,
	 * and either of a chosen post type or chosen by itself, and not excluded.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function eligible( WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}

		$excluded = array_map( 'intval', (array) Settings::get( 'knowledge.exclude_ids', array() ) );
		if ( in_array( $post->ID, $excluded, true ) ) {
			return false;
		}

		return in_array( $post->post_type, self::post_types(), true )
			|| in_array( $post->ID, array_map( 'intval', (array) Settings::get( 'knowledge.include_ids', array() ) ), true );
	}

	/**
	 * Chosen post types that exist and are public.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array();
		foreach ( (array) Settings::get( 'knowledge.post_types', array( 'page', 'post' ) ) as $type ) {
			if ( is_string( $type ) && post_type_exists( $type ) && is_post_type_viewable( $type ) && 'attachment' !== $type ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * The text of a post: title, excerpt, and content as plain text.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function text_of( WP_Post $post ): string {
		$parts = array( Chunker::clean( (string) $post->post_title ) );
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			$parts[] = Chunker::clean( (string) $post->post_excerpt );
		}
		$parts[] = Chunker::clean( (string) $post->post_content );

		return trim( implode( "\n\n", array_filter( $parts ) ) );
	}

	/**
	 * Chunks and stores a source (or removes it when it has no text).
	 *
	 * @param string     $type   Source type.
	 * @param string     $id     Source ID.
	 * @param mixed      $source Filtered source.
	 * @return int Chunks stored.
	 */
	private function store( string $type, string $id, $source ): int {
		$text = is_array( $source ) ? trim( (string) ( $source['text'] ?? '' ) ) : '';
		if ( '' === $text ) {
			$this->repository->delete( $type, $id );
			return 0;
		}

		return $this->repository->replace(
			$type,
			$id,
			mb_substr( (string) ( $source['title'] ?? '' ), 0, 300 ),
			esc_url_raw( (string) ( $source['url'] ?? '' ) ),
			Language::primary( (string) ( $source['language'] ?? '' ) ),
			Chunker::split( $text )
		);
	}
}
