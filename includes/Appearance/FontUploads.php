<?php
/**
 * Uploaded woff2 fonts.
 *
 * @package Chatlume
 */

namespace Chatlume\Appearance;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores woff2 files uploaded in the Customizer in uploads/chatlume-fonts/.
 * Only woff2 is accepted, checked by extension, size, and the file's own
 * signature; the file is never executed or parsed, only served as a font.
 */
final class FontUploads {

	/**
	 * Largest accepted file.
	 */
	public const MAX_BYTES = 2 * MB_IN_BYTES;

	/**
	 * Most fonts kept.
	 */
	public const MAX_FONTS = 20;

	/**
	 * Weights an uploaded file can declare.
	 */
	public const WEIGHTS = array( '400', '500', '600', '700', '100 900' );

	/**
	 * Checks an upload before anything is written.
	 *
	 * @param array<string, mixed> $file  Entry of $_FILES (name, size, error).
	 * @param string               $bytes File contents.
	 * @return true|WP_Error
	 */
	public static function check( array $file, string $bytes ) {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error( 'chatlume_font_upload_failed', __( 'The font could not be uploaded. Please try again.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}
		if ( 'woff2' !== strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'chatlume_font_type', __( 'Only .woff2 font files can be uploaded.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}
		if ( strlen( $bytes ) > self::MAX_BYTES || (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new WP_Error( 'chatlume_font_size', __( 'The font file is larger than 2 MB.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}
		// Every woff2 file starts with the signature "wOF2".
		if ( 'wOF2' !== substr( $bytes, 0, 4 ) ) {
			return new WP_Error( 'chatlume_font_type', __( 'This file is not a valid woff2 font.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}
		if ( count( FontCatalog::uploaded() ) >= self::MAX_FONTS ) {
			return new WP_Error( 'chatlume_font_limit', __( 'You can keep up to 20 uploaded fonts. Delete one first.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Saves an upload.
	 *
	 * @param array<string, mixed> $file   Entry of $_FILES.
	 * @param string               $name   Display name.
	 * @param string               $weight Weight (see WEIGHTS).
	 * @param string               $style  normal or italic.
	 * @return array<string, string>|WP_Error The stored font.
	 */
	public static function store( array $file, string $name, string $weight, string $style ) {
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'chatlume_font_upload_failed', __( 'The font could not be uploaded. Please try again.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		$filesystem = self::filesystem();
		if ( ! $filesystem ) {
			return new WP_Error( 'chatlume_font_storage', __( 'Fonts cannot be saved because the uploads folder is not writable.', 'chatlume-ai-chatbot' ), array( 'status' => 500 ) );
		}

		$bytes = (string) $filesystem->get_contents( $tmp );
		$valid = self::check( $file, $bytes );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$folder = self::folder();
		if ( ! wp_mkdir_p( $folder['dir'] ) ) {
			return new WP_Error( 'chatlume_font_storage', __( 'Fonts cannot be saved because the uploads folder is not writable.', 'chatlume-ai-chatbot' ), array( 'status' => 500 ) );
		}
		if ( ! $filesystem->exists( $folder['dir'] . '/index.php' ) ) {
			$filesystem->put_contents( $folder['dir'] . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}

		$name = mb_substr( sanitize_text_field( '' !== trim( $name ) ? $name : pathinfo( (string) $file['name'], PATHINFO_FILENAME ) ), 0, 60 );
		$id   = bin2hex( random_bytes( 6 ) );
		$slug = sanitize_title( $name );
		$base = sanitize_file_name( ( '' !== $slug ? $slug : 'font' ) . '-' . $id . '.woff2' );

		if ( ! $filesystem->put_contents( $folder['dir'] . '/' . $base, $bytes, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'chatlume_font_storage', __( 'Fonts cannot be saved because the uploads folder is not writable.', 'chatlume-ai-chatbot' ), array( 'status' => 500 ) );
		}

		$font = array(
			'id'     => $id,
			'name'   => '' !== $name ? $name : __( 'Uploaded font', 'chatlume-ai-chatbot' ),
			'file'   => $base,
			'url'    => $folder['url'] . '/' . $base,
			'weight' => in_array( $weight, self::WEIGHTS, true ) ? $weight : '400',
			'style'  => 'italic' === $style ? 'italic' : 'normal',
		);

		$fonts   = FontCatalog::uploaded();
		$fonts[] = $font;
		update_option( FontCatalog::CUSTOM_OPTION, $fonts, false );
		FontCatalog::flush();

		return $font;
	}

	/**
	 * Deletes an uploaded font. Themes still naming it fall back to the
	 * system font.
	 *
	 * @param string $id Font ID.
	 * @return bool Whether it existed.
	 */
	public static function delete( string $id ): bool {
		$fonts = FontCatalog::uploaded();
		$found = null;
		foreach ( $fonts as $index => $font ) {
			if ( hash_equals( $font['id'], $id ) ) {
				$found = $font;
				unset( $fonts[ $index ] );
			}
		}
		if ( null === $found ) {
			return false;
		}

		$file = self::folder()['dir'] . '/' . basename( $found['file'] );
		if ( '' !== $found['file'] && file_exists( $file ) ) {
			wp_delete_file( $file );
		}

		update_option( FontCatalog::CUSTOM_OPTION, array_values( $fonts ), false );
		FontCatalog::flush();
		TokenResolver::rebuild();

		return true;
	}

	/**
	 * The fonts folder.
	 *
	 * @return array{dir: string, url: string}
	 */
	public static function folder(): array {
		$uploads = wp_upload_dir( null, false );

		return array(
			'dir' => untrailingslashit( (string) $uploads['basedir'] ) . '/' . FontCatalog::UPLOAD_DIR,
			'url' => set_url_scheme( untrailingslashit( (string) $uploads['baseurl'] ) . '/' . FontCatalog::UPLOAD_DIR ),
		);
	}

	/**
	 * The WordPress filesystem, initialized.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! $wp_filesystem && ! WP_Filesystem() ) {
			return null;
		}

		return $wp_filesystem;
	}
}
