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

		/**
		 * Headers.
		 *
		 * @var array<string, string>
		 */
		private $headers = array();

		/**
		 * Status.
		 *
		 * @var int
		 */
		private $status = 200;

		/**
		 * Sets a header.
		 *
		 * @param string $key   Header.
		 * @param string $value Value.
		 * @return void
		 */
		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}

		/**
		 * Headers.
		 *
		 * @return array<string, string>
		 */
		public function get_headers() {
			return $this->headers;
		}

		/**
		 * Sets the status.
		 *
		 * @param int $code Status.
		 * @return void
		 */
		public function set_status( $code ) {
			$this->status = (int) $code;
		}

		/**
		 * Status.
		 *
		 * @return int
		 */
		public function get_status() {
			return $this->status;
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

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stub of core's WP_Post.
	 */
	class WP_Post {
		/**
		 * Post ID.
		 *
		 * @var int
		 */
		public $ID = 0;

		/**
		 * Post type.
		 *
		 * @var string
		 */
		public $post_type = 'post';

		/**
		 * Status.
		 *
		 * @var string
		 */
		public $post_status = 'publish';

		/**
		 * Password.
		 *
		 * @var string
		 */
		public $post_password = '';

		/**
		 * Title.
		 *
		 * @var string
		 */
		public $post_title = '';

		/**
		 * Excerpt.
		 *
		 * @var string
		 */
		public $post_excerpt = '';

		/**
		 * Content.
		 *
		 * @var string
		 */
		public $post_content = '';

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $fields Fields.
		 */
		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->$key = $value;
			}
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Core's wp_json_encode() (simplified).
	 *
	 * @param mixed $data  Data.
	 * @param int   $flags Flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stub of the core function.
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Core's untrailingslashit().
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/' . chr( 92 ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Simplified wp_strip_all_tags().
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub of the core function.
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Core's wp_parse_url() (simplified).
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stub of the core function.
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * Random UUID-like string.
	 *
	 * @return string
	 */
	function wp_generate_uuid4() {
		return bin2hex( random_bytes( 16 ) );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Core's wp_remote_retrieve_body() for array responses.
	 *
	 * @param array $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Core's wp_remote_retrieve_response_code() for array responses.
	 *
	 * @param array $response Response.
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	/**
	 * Core's wp_remote_retrieve_headers() for array responses.
	 *
	 * @param array $response Response.
	 * @return array
	 */
	function wp_remote_retrieve_headers( $response ) {
		// Returned as-is: real responses carry a header object, not an array.
		return is_array( $response ) ? ( $response['headers'] ?? array() ) : array();
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Simplified is_email().
	 *
	 * @param string $email Email.
	 * @return string|false
	 */
	function is_email( $email ) {
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Simplified wp_kses(): keeps only the allowed tag names (attributes are not filtered).
	 *
	 * @param string $content Content.
	 * @param array  $allowed Allowed tags.
	 * @return string
	 */
	function wp_kses( $content, $allowed ) {
		return strip_tags( (string) $content, '<' . implode( '><', array_keys( (array) $allowed ) ) . '>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub of the core function.
	}
}
