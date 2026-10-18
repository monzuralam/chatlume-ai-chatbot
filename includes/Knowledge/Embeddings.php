<?php
/**
 * Embeddings for the knowledge base.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

use Chatlume\Installer\Schema;
use Chatlume\Providers\ProviderError;
use Chatlume\Providers\ProviderRegistry;
use Chatlume\Settings\Settings;
use Chatlume\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates embeddings for knowledge chunks in the background (WP-Cron) and
 * for visitor questions on demand. Vectors are stored as packed 32-bit
 * floats with the model that made them; when the model or its dimensions
 * change, older vectors are marked stale and re-created, and vectors of
 * different models are never compared. Nothing runs unless embeddings are
 * switched on and an embeddings-capable provider and model are selected.
 */
class Embeddings {

	/**
	 * Cron hook for one run.
	 */
	public const HOOK = 'chatlume_embed_batch';

	/**
	 * State option (not autoloaded).
	 */
	public const OPTION = 'chatlume_embed_state';

	/**
	 * Chunks per embeddings request.
	 */
	public const BATCH = 32;

	/**
	 * Requests per background run.
	 */
	private const MAX_REQUESTS = 8;

	/**
	 * Seconds a background run may spend before it hands over to the next.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Seconds to wait after a provider error.
	 */
	private const RETRY_AFTER = 900;

	/**
	 * Seconds after which a due run is considered stuck.
	 */
	private const LATE = 60;

	/**
	 * Seconds a visitor's question waits for its embedding at most.
	 */
	private const QUERY_TIMEOUT = 5;

	/**
	 * Transient set when question embeddings fail, so visitors do not wait
	 * on a provider that is down.
	 */
	private const PAUSE_TRANSIENT = 'chatlume_embed_paused';

	/**
	 * Hooks the background job.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings' ), 10, 2 );
	}

	/**
	 * Provider or knowledge settings changed: embed what needs it.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public function on_settings( $old_value, $value ): void {
		$watch = static function ( $settings ): string {
			$settings = is_array( $settings ) ? $settings : array();
			return (string) wp_json_encode(
				array(
					$settings['providers']['primary']['provider'] ?? '',
					$settings['providers']['embeddings'] ?? null,
					$settings['knowledge']['use_embeddings'] ?? null,
				)
			);
		};

		if ( $watch( $old_value ) !== $watch( $value ) ) {
			delete_transient( self::PAUSE_TRANSIENT );
			self::schedule();
		}
	}

	/**
	 * Whether embeddings are switched on and a provider can make them.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return null !== self::model_key();
	}

	/**
	 * The model that vectors are made with: "provider/model", plus the
	 * requested dimensions when set. Null when embeddings are off.
	 *
	 * @return string|null
	 */
	public static function model_key(): ?string {
		if ( ! Settings::get( 'knowledge.use_embeddings', true ) ) {
			return null;
		}

		$target = ProviderRegistry::embeddings_target();
		if ( null === $target ) {
			return null;
		}

		return $target['provider'] . '/' . $target['model'] . ( $target['dimensions'] > 0 ? '#' . $target['dimensions'] : '' );
	}

	/**
	 * Schedules a run soon, unless one is already scheduled.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function schedule( int $delay = 5 ): void {
		if ( self::enabled() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	/**
	 * Embeds chunks that have no current vector, a batch at a time, within a
	 * time budget; schedules the next run when more remain.
	 *
	 * @param int $max_requests Requests at most.
	 * @return array<string, mixed> State.
	 */
	public function run( int $max_requests = self::MAX_REQUESTS ): array {
		global $wpdb;

		$model = self::model_key();
		if ( null === $model ) {
			return self::save_state( array( 'status' => 'idle' ) );
		}

		$table = Schema::table( 'knowledge' );

		// Vectors of another model are kept until replaced, but never used.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET is_stale = 1 WHERE embedding IS NOT NULL AND is_stale = 0 AND embedding_model <> %s', $table, $model ) );

		$started      = microtime( true );
		$done         = 0;
		$max_requests = max( 1, $max_requests );
		for ( $request = 0; $request < $max_requests; $request++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table; work queue read.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, content FROM %i WHERE embedding IS NULL OR is_stale = 1 OR embedding_model <> %s ORDER BY id ASC LIMIT %d', $table, $model, self::BATCH ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();
			if ( array() === $rows ) {
				return self::save_state(
					array(
						'status'   => 'done',
						'embedded' => $done,
					)
				);
			}

			$texts = array();
			foreach ( $rows as $row ) {
				$texts[] = self::passage( (string) $row['title'], (string) $row['content'] );
			}

			try {
				$result = ProviderRegistry::embed( $texts );
			} catch ( ProviderError $error ) {
				wp_clear_scheduled_hook( self::HOOK );
				wp_schedule_single_event( time() + self::RETRY_AFTER, self::HOOK );
				return self::save_state(
					array(
						'status'   => 'error',
						'error'    => $error->type(),
						'message'  => $error->admin_title(),
						'retry_at' => time() + self::RETRY_AFTER,
						'embedded' => $done,
					)
				);
			}

			foreach ( $rows as $index => $row ) {
				$vector = $result['vectors'][ $index ] ?? array();
				if ( array() === $vector ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table. Hex keeps the binary vector intact in the query text.
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET embedding = UNHEX(%s), embedding_model = %s, embedding_dims = %d, is_stale = 0 WHERE id = %d', $table, bin2hex( self::pack( $vector ) ), $model, count( $vector ), (int) $row['id'] ) );
				++$done;
			}

			if ( count( $rows ) < self::BATCH ) {
				// That was the last of them.
				return self::save_state(
					array(
						'status'   => 'done',
						'embedded' => $done,
					)
				);
			}
			if ( microtime( true ) - $started > self::TIME_BUDGET ) {
				break;
			}
		}

		wp_schedule_single_event( time() + 2, self::HOOK );

		return self::save_state(
			array(
				'status'   => 'running',
				'embedded' => $done,
				'next_run' => time() + 2,
			)
		);
	}

	/**
	 * Runs a short batch when WP-Cron is late (called from the admin's
	 * progress checks).
	 *
	 * @return void
	 */
	public function maybe_run(): void {
		if ( ! self::enabled() ) {
			return;
		}

		$next  = wp_next_scheduled( self::HOOK );
		$state = self::state();
		if ( 'error' === $state['status'] && time() < (int) $state['retry_at'] ) {
			return;
		}
		if ( false !== $next && $next + self::LATE >= time() ) {
			return;
		}
		if ( self::pending() > 0 ) {
			$this->run( 1 );
		}
	}

	/**
	 * Chunks that still need a vector from the current model.
	 *
	 * @return int
	 */
	public static function pending(): int {
		global $wpdb;

		$model = self::model_key();
		if ( null === $model ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count over Chatlume's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE embedding IS NULL OR is_stale = 1 OR embedding_model <> %s', Schema::table( 'knowledge' ), $model ) );
	}

	/**
	 * Background state.
	 *
	 * @return array{status: string, error: string, message: string, retry_at: int, embedded: int, updated: int}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'status'   => in_array( $state['status'] ?? '', array( 'running', 'done', 'error' ), true ) ? (string) $state['status'] : 'idle',
			'error'    => (string) ( $state['error'] ?? '' ),
			'message'  => (string) ( $state['message'] ?? '' ),
			'retry_at' => (int) ( $state['retry_at'] ?? 0 ),
			'embedded' => (int) ( $state['embedded'] ?? 0 ),
			'updated'  => (int) ( $state['updated'] ?? 0 ),
		);
	}

	/**
	 * Vector for a visitor's question, cached so repeated questions cost
	 * nothing. Null when embeddings are off or the provider fails (the
	 * caller then ranks by keywords alone).
	 *
	 * @param string $text Question.
	 * @return float[]|null
	 */
	public static function query_vector( string $text ): ?array {
		$model = self::model_key();
		if ( null === $model || false !== get_transient( self::PAUSE_TRANSIENT ) ) {
			return null;
		}

		$key    = 'chatlume_qv_' . md5( $model . '|' . Text::normalize( $text ) );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached && ctype_xdigit( $cached ) ) {
			$packed = hex2bin( $cached );
			return false !== $packed ? self::unpack( $packed ) : null;
		}

		try {
			$result = ProviderRegistry::embed( array( $text ), array( 'timeout' => self::QUERY_TIMEOUT ) );
		} catch ( ProviderError $error ) {
			set_transient( self::PAUSE_TRANSIENT, $error->type(), 5 * MINUTE_IN_SECONDS );
			return null;
		}

		$vector = $result['vectors'][0] ?? array();
		if ( array() === $vector ) {
			return null;
		}
		// Hex: transients may live in a text column that would not keep raw bytes.
		set_transient( $key, bin2hex( self::pack( $vector ) ), WEEK_IN_SECONDS );

		return $vector;
	}

	/**
	 * Text sent for a chunk: its title gives the passage context.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return string
	 */
	public static function passage( string $title, string $content ): string {
		$title = trim( $title );

		return '' !== $title ? $title . "\n\n" . $content : $content;
	}

	/**
	 * Packs a vector as little-endian 32-bit floats.
	 *
	 * @param float[] $vector Vector.
	 * @return string
	 */
	public static function pack( array $vector ): string {
		return pack( 'g*', ...array_map( 'floatval', array_values( $vector ) ) );
	}

	/**
	 * Unpacks a stored vector.
	 *
	 * @param string $packed Packed floats.
	 * @return float[]
	 */
	public static function unpack( string $packed ): array {
		if ( '' === $packed || 0 !== strlen( $packed ) % 4 ) {
			return array();
		}
		$values = unpack( 'g*', $packed );

		return is_array( $values ) ? array_values( $values ) : array();
	}

	/**
	 * Cosine similarity, or 0 for vectors of different sizes.
	 *
	 * @param float[] $a Vector.
	 * @param float[] $b Vector.
	 * @return float
	 */
	public static function cosine( array $a, array $b ): float {
		$size = count( $a );
		if ( 0 === $size || count( $b ) !== $size ) {
			return 0.0;
		}

		$dot   = 0.0;
		$norm  = 0.0;
		$other = 0.0;
		for ( $i = 0; $i < $size; $i++ ) {
			$dot   += $a[ $i ] * $b[ $i ];
			$norm  += $a[ $i ] * $a[ $i ];
			$other += $b[ $i ] * $b[ $i ];
		}

		return $norm > 0 && $other > 0 ? $dot / sqrt( $norm * $other ) : 0.0;
	}

	/**
	 * Saves the state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function save_state( array $state ): array {
		$state['updated'] = time();
		update_option( self::OPTION, $state, false );

		return self::state();
	}
}
