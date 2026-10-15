<?php
/**
 * [chatlume] shortcode and the chatlume/chat block.
 *
 * @package Chatlume
 */

namespace Chatlume\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders an inline chat (embedded in the page instead of floating).
 * When an inline chat is on a page, the widget hides the floating launcher.
 */
class Shortcode {

	/**
	 * Shortcode tag.
	 */
	public const TAG = 'chatlume';

	/**
	 * Height limits (px).
	 */
	private const MIN_HEIGHT = 320;
	private const MAX_HEIGHT = 1200;

	/**
	 * Registers the shortcode and the block.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * [chatlume height="600"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'height' => '600' ), is_array( $atts ) ? $atts : array(), self::TAG );

		return self::render( (int) $atts['height'] );
	}

	/**
	 * Registers the chatlume/chat block from its built metadata.
	 *
	 * @return void
	 */
	public function register_block(): void {
		if ( ! is_readable( CHATLUME_PATH . 'build/blocks/chat/block.json' ) ) {
			return;
		}

		$block = register_block_type( CHATLUME_PATH . 'build/blocks/chat' );

		if ( $block && ! empty( $block->editor_script_handles ) ) {
			foreach ( $block->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'chatlume-ai-chatbot', CHATLUME_PATH . 'languages' );
			}
		}
	}

	/**
	 * Inline chat markup.
	 *
	 * @param int    $height     Height in pixels.
	 * @param string $attributes Extra wrapper attributes (already escaped, from get_block_wrapper_attributes()).
	 * @return string
	 */
	public static function render( int $height, string $attributes = '' ): string {
		if ( ! WidgetLoader::is_built() ) {
			return '';
		}

		WidgetLoader::enqueue( WidgetLoader::should_display() );

		$height = max( self::MIN_HEIGHT, min( self::MAX_HEIGHT, $height ) );

		return sprintf(
			'<div %1$s><chatlume-widget data-inline="1" style="%2$s"></chatlume-widget><noscript>%3$s</noscript></div>',
			'' !== $attributes ? $attributes : 'class="chatlume-inline"',
			esc_attr( 'display:block;height:' . $height . 'px;max-width:100%' ),
			esc_html__( 'Please enable JavaScript to use the chat.', 'chatlume-ai-chatbot' )
		);
	}
}
