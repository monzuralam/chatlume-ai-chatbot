<?php
/**
 * Admin menu and app container.
 *
 * @package Chatlume
 */

namespace Chatlume\Admin;

use Chatlume\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level "Chatlume" menu and renders the React app root.
 */
class AdminPage {

	/**
	 * User meta key for the admin light/dark preference.
	 */
	public const COLOR_MODE_META = 'chatlume_admin_color_mode';

	/**
	 * Allowed color modes.
	 */
	public const COLOR_MODES = array( 'system', 'light', 'dark' );

	/**
	 * Hook suffix returned by add_menu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Hooks into wp-admin.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'in_admin_header', array( $this, 'suppress_foreign_notices' ), 1000 );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Adds the top-level menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$hook_suffix = add_menu_page(
			__( 'Chatlume – AI Chatbot', 'chatlume-ai-chatbot' ),
			__( 'Chatlume', 'chatlume-ai-chatbot' ),
			Plugin::capability(),
			Plugin::ADMIN_SLUG,
			array( $this, 'render' ),
			self::menu_icon(),
			58
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Hook suffix of the Chatlume screen ('' before admin_menu).
	 *
	 * @return string
	 */
	public function get_hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Whether the current admin screen is Chatlume's.
	 *
	 * @return bool
	 */
	public function is_current_screen(): bool {
		if ( '' === $this->hook_suffix || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return null !== $screen && $screen->id === $this->hook_suffix;
	}

	/**
	 * Renders the app container. The React app mounts into #chatlume-admin.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div id="chatlume-admin" class="chatlume-admin">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Chatlume – AI Chatbot', 'chatlume-ai-chatbot' ); ?></h1>
			<?php if ( ! Assets::is_built() ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'The Chatlume admin app is missing its compiled files. Please reinstall the plugin, or run "npm run build" if you are working from source.', 'chatlume-ai-chatbot' ); ?></p>
				</div>
			<?php else : ?>
				<noscript>
					<div class="notice notice-error inline">
						<p><?php esc_html_e( 'Chatlume needs JavaScript to display its settings. Please enable JavaScript in your browser.', 'chatlume-ai-chatbot' ); ?></p>
					</div>
				</noscript>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Hides other plugins' admin notices on the Chatlume screen only, so the
	 * app has a clean canvas. Other screens are untouched.
	 *
	 * @return void
	 */
	public function suppress_foreign_notices(): void {
		if ( ! $this->is_current_screen() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Adds a body class on the Chatlume screen (used to remove wp-admin's
	 * content padding so the app can fill the content area).
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ): string {
		$classes = is_string( $classes ) ? $classes : '';

		if ( $this->is_current_screen() ) {
			$classes .= ' chatlume-admin-screen';
		}

		return $classes;
	}

	/**
	 * Registers the per-user color mode preference, editable through core's
	 * /wp/v2/users/me endpoint by the user themselves.
	 *
	 * @return void
	 */
	public static function register_user_meta(): void {
		register_meta(
			'user',
			self::COLOR_MODE_META,
			array(
				'type'              => 'string',
				'description'       => __( 'Chatlume admin color mode.', 'chatlume-ai-chatbot' ),
				'single'            => true,
				'default'           => 'system',
				'sanitize_callback' => array( self::class, 'sanitize_color_mode' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $user_id ) {
					unset( $allowed, $meta_key );
					return get_current_user_id() === (int) $user_id && Plugin::current_user_can_manage();
				},
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => self::COLOR_MODES,
					),
				),
			)
		);
	}

	/**
	 * Sanitizes a color mode value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_color_mode( $value ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';

		return in_array( $value, self::COLOR_MODES, true ) ? $value : 'system';
	}

	/**
	 * Color mode preference of a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_color_mode( int $user_id ): string {
		return self::sanitize_color_mode( get_user_meta( $user_id, self::COLOR_MODE_META, true ) );
	}

	/**
	 * Menu icon as a data URI. wp-admin's svg-painter only recolors base64
	 * SVG data URIs to match the admin color scheme, so base64 is required here;
	 * the input is a static SVG string defined in this method.
	 *
	 * @return string
	 */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M10 2c4.42 0 8 3.13 8 7s-3.58 7-8 7c-.9 0-1.77-.13-2.58-.37L3.5 17.5l1.08-3.24C3.03 12.98 2 11.1 2 9c0-3.87 3.58-7 8-7Zm0 2.8.95 2.45 2.45.95-2.45.95L10 11.6l-.95-2.45-2.45-.95 2.45-.95L10 4.8Z"/></svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes the static menu icon SVG above; required by core's svg-painter.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
