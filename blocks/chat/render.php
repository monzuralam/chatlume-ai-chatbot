<?php
/**
 * Server rendering of the chatlume/chat block.
 *
 * @package Chatlume
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Output is built and escaped by Shortcode::render(); wrapper attributes come escaped from core.
echo \Chatlume\Frontend\Shortcode::render( (int) ( $attributes['height'] ?? 600 ), get_block_wrapper_attributes() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
