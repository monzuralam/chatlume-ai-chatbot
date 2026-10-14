<?php
/**
 * REST request double with headers.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;

/**
 * Header-aware request stub.
 */
class HeaderRequest extends WP_REST_Request {

	/**
	 * Headers.
	 *
	 * @var array<string, string>
	 */
	private array $headers;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>  $params  Params.
	 * @param array<string, string> $headers Headers.
	 */
	public function __construct( array $params = array(), array $headers = array() ) {
		parent::__construct( $params );
		$this->headers = $headers;
	}

	/**
	 * Header value.
	 *
	 * @param string $key Header.
	 * @return string|null
	 */
	public function get_header( $key ) {
		return $this->headers[ $key ] ?? null;
	}
}
