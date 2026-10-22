<?php
/**
 * Draft previews on the real site.
 *
 * @package Chatlume
 */

namespace Chatlume\Appearance;

use Chatlume\Frontend\WidgetLoader;
use Chatlume\Plugin;
use Chatlume\REST\ChatController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows the draft appearance on a real page, only to the admin who asked:
 *
 * - frame:  inside the Customizer's preview iframe. The admin bar and the
 *           live widget are hidden, and a preview widget pinned open takes
 *           its updates from the Customizer.
 * - site:   "Preview on my site" in a new tab. The widget looks like the
 *           draft and answers with samples (no AI cost, no conversations).
 * - sample: a hidden page the Customizer reads the site's colors and fonts
 *           from ("Match my website"); no widget at all.
 *
 * The page address carries a signed token that expires after 30 minutes and
 * is bound to the admin's user ID. These pages are never cached.
 */
final class PreviewMode {

	/**
	 * Query parameter carrying the token.
	 */
	public const PARAM = 'chatlume_preview';

	/**
	 * Token lifetime.
	 */
	public const TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Modes.
	 */
	public const MODES = array( 'frame', 'site', 'sample' );

	/**
	 * Style handle of the draft notice.
	 */
	public const BADGE_HANDLE = 'chatlume-preview-badge';

	/**
	 * Mode of the current request, once checked.
	 *
	 * @var string|null
	 */
	private static ?string $mode = null;

	/**
	 * Hooks the preview when the request carries a valid token.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'start' ), 1 );
	}

	/**
	 * Applies the preview mode for this request.
	 *
	 * @return void
	 */
	public function start(): void {
		$mode = self::current();
		if ( '' === $mode ) {
			return;
		}

		// Never cache a page that shows the draft.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by caching plugins.
		}
		add_action( 'send_headers', 'nocache_headers' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		// Only the preview widget (or none) on this page.
		add_filter( 'chatlume_should_display_widget', '__return_false', 99 );

		if ( 'site' !== $mode ) {
			add_filter( 'show_admin_bar', '__return_false' );
		}
		if ( 'sample' !== $mode ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		}
	}

	/**
	 * The widget with the draft theme, in preview mode.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$mode   = self::current();
		$config = WidgetLoader::inline_config( false );

		$config['theme']       = TokenResolver::resolve( AppearanceStore::draft() );
		$config['previewMode'] = $mode;
		$config['preview']     = array(
			'origin'   => self::admin_origin(),
			'behavior' => ChatController::behavior_config( '' ),
			'samples'  => array(
				'reply' => __( 'This is a sample reply. Draft previews never contact your AI provider, so there is no cost.', 'chatlume-ai-chatbot' ),
			),
		);

		WidgetLoader::configure( $config );

		// In a tab of its own, a notice says the page shows a draft (printed by the server, not the launcher).
		if ( 'site' === $mode ) {
			wp_register_style( self::BADGE_HANDLE, false, array(), CHATLUME_VERSION );
			wp_enqueue_style( self::BADGE_HANDLE );
			wp_add_inline_style( self::BADGE_HANDLE, '.chatlume-preview-badge{position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:2147483647;padding:8px 14px;border-radius:999px;background:#111827;color:#fff;font:500 13px/1.4 system-ui,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.2);pointer-events:none;max-width:calc(100vw - 32px);text-align:center}' );
			add_action( 'wp_footer', array( self::class, 'print_badge' ) );
		}
	}

	/**
	 * The draft notice at the top of the page.
	 *
	 * @return void
	 */
	public static function print_badge(): void {
		echo '<div class="chatlume-preview-badge" role="status">' . esc_html__( 'Draft preview: only you can see this. Replies are samples.', 'chatlume-ai-chatbot' ) . '</div>';
	}

	/**
	 * The preview mode of this request, or ''.
	 *
	 * @return string
	 */
	public static function current(): string {
		if ( null !== self::$mode ) {
			return self::$mode;
		}

		self::$mode = '';
		// The signed token is the authorization here; there is no form to nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $token || is_admin() ) {
			return self::$mode;
		}

		$claims = self::verify( $token );
		if ( null !== $claims && get_current_user_id() === $claims['user'] && Plugin::current_user_can_manage() ) {
			self::$mode = $claims['mode'];
		}

		return self::$mode;
	}

	/**
	 * A new token.
	 *
	 * @param int    $user_id User ID.
	 * @param string $mode    One of MODES.
	 * @param int    $now     Current time (tests).
	 * @return array{token: string, expires: int}
	 */
	public static function token( int $user_id, string $mode, int $now = 0 ): array {
		$expires = ( $now ? $now : time() ) + self::TTL;
		$payload = $user_id . ':' . $mode . ':' . $expires;

		return array(
			'token'   => bin2hex( $payload ) . '.' . self::sign( $payload ),
			'expires' => $expires,
		);
	}

	/**
	 * Checks a token.
	 *
	 * @param string $token Token.
	 * @param int    $now   Current time (tests).
	 * @return array{user: int, mode: string}|null
	 */
	public static function verify( string $token, int $now = 0 ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! ctype_xdigit( $parts[0] ) || 0 !== strlen( $parts[0] ) % 2 ) {
			return null;
		}

		$payload = (string) hex2bin( $parts[0] );
		if ( ! hash_equals( self::sign( $payload ), $parts[1] ) ) {
			return null;
		}

		$fields = explode( ':', $payload );
		if ( 3 !== count( $fields ) || ! in_array( $fields[1], self::MODES, true ) || (int) $fields[2] < ( $now ? $now : time() ) ) {
			return null;
		}

		return array(
			'user' => (int) $fields[0],
			'mode' => $fields[1],
		);
	}

	/**
	 * Forgets the request's mode (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$mode = null;
	}

	/**
	 * Signature of a payload.
	 *
	 * @param string $payload Payload.
	 * @return string
	 */
	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', 'chatlume-preview|' . $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Origin of the admin (where Customizer messages come from).
	 *
	 * @return string
	 */
	private static function admin_origin(): string {
		$parts = wp_parse_url( admin_url() );
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . $port;
	}
}
