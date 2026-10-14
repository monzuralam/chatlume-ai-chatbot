<?php
/**
 * Minimal stand-ins for WordPress core classes and pure helper functions
 * used by the code under test. Behavior mirrors core where it matters.
 *
 * @package Chatlume
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub of core's WP_Error.
	 */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		public $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}

		/**
		 * Replaces error data.
		 *
		 * @param mixed $data Data.
		 * @return void
		 */
		public function add_data( $data ) {
			$this->data = $data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * Stub of core's WP_REST_Server method constants.
	 */
	class WP_REST_Server {
		const READABLE  = 'GET';
		const EDITABLE  = 'POST, PUT, PATCH';
		const CREATABLE = 'POST';
		const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Stub of core's WP_REST_Response.
	 */
	class WP_REST_Response {
		/**
		 * Response data.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * Constructor.
		 *
		 * @param mixed $data Data.
		 */
		public function __construct( $data = null ) {
			$this->data = $data;
		}

		/**
		 * Response data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stub of core's WP_REST_Request (parameters only).
	 */
	class WP_REST_Request {
		/**
		 * Parameters.
		 *
		 * @var array<string, mixed>
		 */
		private $params;

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $params Parameters.
		 */
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		/**
		 * One parameter.
		 *
		 * @param string $key Key.
		 * @return mixed
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_Site' ) ) {
	/**
	 * Stub of core's WP_Site.
	 */
	class WP_Site {
		/**
		 * Site ID (core stores it as a string).
		 *
		 * @var string
		 */
		public $blog_id;

		/**
		 * Constructor.
		 *
		 * @param int $blog_id Site ID.
		 */
		public function __construct( $blog_id ) {
			$this->blog_id = (string) $blog_id;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Core's is_wp_error().
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	/**
	 * Core's rest_sanitize_boolean().
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	function rest_sanitize_boolean( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( $value );
			if ( in_array( $value, array( 'false', '0' ), true ) ) {
				$value = false;
			}
		}
		return (bool) $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Simplified sanitize_text_field(): strips tags and line breaks, trims.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub of the core function.
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Simplified sanitize_textarea_field(): strips tags, keeps line breaks.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_textarea_field( $value ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub of the core function.
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Core's sanitize_key().
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Simplified sanitize_email().
	 *
	 * @param string $email Email.
	 * @return string
	 */
	function sanitize_email( $email ) {
		$email = trim( (string) $email );
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
	/**
	 * Core's sanitize_hex_color().
	 *
	 * @param string $color Color.
	 * @return string|null
	 */
	function sanitize_hex_color( $color ) {
		if ( '' === $color ) {
			return '';
		}
		return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ? $color : null;
	}
}
