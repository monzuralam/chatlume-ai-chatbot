<?php
/**
 * Draft, published, and earlier versions of the widget theme.
 *
 * @package Chatlume
 */

namespace Chatlume\Appearance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Customizer edits a draft; visitors see only the published theme.
 * Publishing keeps the last ten published versions for one-click restore.
 * Everything here lives in non-autoloaded options; only the resolved tokens
 * (TokenResolver::OPTION) are autoloaded.
 */
final class AppearanceStore {

	/**
	 * Draft theme (absent when there are no unpublished changes).
	 */
	public const DRAFT_OPTION = 'chatlume_theme_draft';

	/**
	 * Published versions, newest first.
	 */
	public const VERSIONS_OPTION = 'chatlume_theme_versions';

	/**
	 * Versions kept.
	 */
	public const MAX_VERSIONS = 10;

	/**
	 * The admin's saved presets.
	 */
	public const PRESETS_OPTION = 'chatlume_theme_presets';

	/**
	 * Presets kept.
	 */
	public const MAX_PRESETS = 20;

	/**
	 * The theme being edited: the draft, or the published theme when there is
	 * no draft.
	 *
	 * @return array<string, mixed>
	 */
	public static function draft(): array {
		$draft = get_option( self::DRAFT_OPTION, null );

		return is_array( $draft ) ? ThemeSchema::sanitize( $draft ) : TokenResolver::published_theme();
	}

	/**
	 * Whether the draft differs from what visitors see.
	 *
	 * @return bool
	 */
	public static function has_changes(): bool {
		$draft = get_option( self::DRAFT_OPTION, null );

		return is_array( $draft ) && ! self::same( ThemeSchema::sanitize( $draft ), TokenResolver::published_theme() );
	}

	/**
	 * Saves the draft. A draft equal to the published theme is removed, so
	 * "Unpublished changes" disappears when edits are undone.
	 *
	 * @param mixed $theme Theme.
	 * @return array<string, mixed> The sanitized draft.
	 */
	public static function save_draft( $theme ): array {
		$theme = ThemeSchema::sanitize( $theme );

		if ( self::same( $theme, TokenResolver::published_theme() ) ) {
			delete_option( self::DRAFT_OPTION );
		} else {
			update_option( self::DRAFT_OPTION, $theme, false );
		}

		return $theme;
	}

	/**
	 * Drops unpublished changes.
	 *
	 * @return void
	 */
	public static function discard(): void {
		delete_option( self::DRAFT_OPTION );
	}

	/**
	 * Publishes the draft and records it as a version.
	 *
	 * @return array<string, mixed> The published theme.
	 */
	public static function publish(): array {
		$theme = self::draft();

		TokenResolver::publish( $theme );
		delete_option( self::DRAFT_OPTION );

		$versions = self::stored_versions();
		array_unshift(
			$versions,
			array(
				'id'           => wp_generate_uuid4(),
				'published_at' => gmdate( 'Y-m-d H:i:s' ),
				'user_id'      => get_current_user_id(),
				'theme'        => $theme,
			)
		);
		update_option( self::VERSIONS_OPTION, array_slice( $versions, 0, self::MAX_VERSIONS ), false );

		/**
		 * Fires after the widget appearance is published.
		 *
		 * @param array<string, mixed> $theme Published theme.
		 */
		do_action( 'chatlume_appearance_published', $theme );

		return $theme;
	}

	/**
	 * Published versions, newest first, without their themes.
	 *
	 * @return array<int, array{id: string, published_at: string, author: string, current: bool}>
	 */
	public static function versions(): array {
		$list = array();
		foreach ( self::stored_versions() as $index => $version ) {
			$user   = $version['user_id'] ? get_userdata( (int) $version['user_id'] ) : false;
			$list[] = array(
				'id'           => (string) $version['id'],
				'published_at' => str_replace( ' ', 'T', (string) $version['published_at'] ) . 'Z',
				'author'       => $user ? (string) $user->display_name : '',
				'current'      => 0 === $index,
			);
		}

		return $list;
	}

	/**
	 * Loads a published version into the draft.
	 *
	 * @param string $id Version ID.
	 * @return array<string, mixed>|null The new draft, or null when not found.
	 */
	public static function restore( string $id ): ?array {
		foreach ( self::stored_versions() as $version ) {
			if ( hash_equals( (string) $version['id'], $id ) ) {
				return self::save_draft( $version['theme'] );
			}
		}

		return null;
	}

	/**
	 * The admin's saved presets, newest first.
	 *
	 * @return array<int, array{id: string, name: string, theme: array<string, mixed>}>
	 */
	public static function presets(): array {
		$stored  = get_option( self::PRESETS_OPTION, array() );
		$presets = array();

		foreach ( is_array( $stored ) ? $stored : array() as $preset ) {
			if ( is_array( $preset ) && isset( $preset['id'], $preset['theme'] ) && preg_match( '/^[a-f0-9]{12}$/', (string) $preset['id'] ) ) {
				$presets[] = array(
					'id'    => (string) $preset['id'],
					'name'  => (string) ( $preset['name'] ?? '' ),
					'theme' => ThemeSchema::sanitize( $preset['theme'] ),
				);
			}
		}

		return $presets;
	}

	/**
	 * Saves a theme as a named preset.
	 *
	 * @param string $name  Name.
	 * @param mixed  $theme Theme.
	 * @return array{id: string, name: string, theme: array<string, mixed>}|null Null when the limit is reached.
	 */
	public static function save_preset( string $name, $theme ): ?array {
		$presets = self::presets();
		if ( count( $presets ) >= self::MAX_PRESETS ) {
			return null;
		}

		$preset = array(
			'id'    => bin2hex( random_bytes( 6 ) ),
			'name'  => mb_substr( sanitize_text_field( $name ), 0, 40 ),
			'theme' => ThemeSchema::sanitize( $theme ),
		);
		array_unshift( $presets, $preset );
		update_option( self::PRESETS_OPTION, $presets, false );

		return $preset;
	}

	/**
	 * Deletes a saved preset.
	 *
	 * @param string $id Preset ID.
	 * @return bool Whether it existed.
	 */
	public static function delete_preset( string $id ): bool {
		$presets = self::presets();
		$kept    = array_values(
			array_filter(
				$presets,
				static fn( array $preset ): bool => ! hash_equals( $preset['id'], $id )
			)
		);
		if ( count( $kept ) === count( $presets ) ) {
			return false;
		}
		update_option( self::PRESETS_OPTION, $kept, false );

		return true;
	}

	/**
	 * Stored versions with valid shape.
	 *
	 * @return array<int, array{id: string, published_at: string, user_id: int, theme: array<string, mixed>}>
	 */
	private static function stored_versions(): array {
		$stored = get_option( self::VERSIONS_OPTION, array() );
		$valid  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $version ) {
			if ( is_array( $version ) && isset( $version['id'], $version['theme'] ) && is_array( $version['theme'] ) ) {
				$valid[] = array(
					'id'           => (string) $version['id'],
					'published_at' => (string) ( $version['published_at'] ?? '' ),
					'user_id'      => (int) ( $version['user_id'] ?? 0 ),
					'theme'        => $version['theme'],
				);
			}
		}

		return $valid;
	}

	/**
	 * Whether two sanitized themes are equal.
	 *
	 * @param array<string, mixed> $a Theme.
	 * @param array<string, mixed> $b Theme.
	 * @return bool
	 */
	private static function same( array $a, array $b ): bool {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}
}
