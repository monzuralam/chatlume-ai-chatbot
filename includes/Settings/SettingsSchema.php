<?php
/**
 * Declarative settings schema, validation, and sanitization.
 *
 * @package Chatlume
 */

namespace Chatlume\Settings;

use Chatlume\Providers\ProviderRegistry;
use Chatlume\Security\IpResolver;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings schema.
 *
 * The schema is a subset of JSON Schema (type, default, enum, minimum,
 * maximum, maxLength, maxItems, items, properties, format). One definition
 * drives defaults, REST argument validation, sanitization, and clamping. The
 * admin's Zod schemas mirror it.
 *
 * Supported types: object, boolean, integer, number, string, array.
 * String formats: text (default), textarea, email, url (http/https only),
 * hex-color, key, model (provider model IDs), header-name (HTTP header names).
 */
final class SettingsSchema {

	/**
	 * Top-level settings sections. Later phases add sections here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definition(): array {
		return array(
			'setup'         => array(
				'type'        => 'object',
				'description' => __( 'Setup state.', 'chatlume-ai-chatbot' ),
				'properties'  => array(
					'completed' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'privacy'       => array(
				'type'        => 'object',
				'description' => __( 'Data and privacy settings.', 'chatlume-ai-chatbot' ),
				'properties'  => array(
					'delete_data_on_uninstall' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					// When off, conversations live only in a short-lived transient and nothing is written to the tables.
					'store_conversations'      => array(
						'type'    => 'boolean',
						'default' => true,
					),
					// Days to keep conversations; 0 keeps them until deleted.
					'retention_days'           => array(
						'type'    => 'integer',
						'default' => 90,
						'minimum' => 0,
						'maximum' => 3650,
					),
				),
			),
			'providers'     => self::providers_section(),
			'chatbot'       => ChatSettingsSchema::chatbot(),
			'quick_answers' => ChatSettingsSchema::quick_answers(),
			'security'      => ChatSettingsSchema::security(),
			'costs'         => ChatSettingsSchema::costs(),
			'cache'         => ChatSettingsSchema::cache(),
			'display'       => ChatSettingsSchema::display(),
			'streaming'     => ChatSettingsSchema::streaming(),
			'debug'         => ChatSettingsSchema::debug(),
		);
	}

	/**
	 * The providers section: routing roles plus each provider's own settings.
	 * API keys are not settings; ApiKeys stores them encrypted.
	 *
	 * @return array<string, mixed>
	 */
	private static function providers_section(): array {
		$ids  = array_merge( array( '' ), ProviderRegistry::ids() );
		$role = static function ( bool $embeddings = false ) use ( $ids ): array {
			$properties = array(
				'provider' => array(
					'type'    => 'string',
					'format'  => 'key',
					'default' => '',
					'enum'    => $ids,
				),
				'model'    => array(
					'type'      => 'string',
					'format'    => 'model',
					'default'   => '',
					'maxLength' => 200,
				),
			);
			if ( $embeddings ) {
				// Reduced dimensions keep stored vectors small; 0 uses the model's default size.
				$properties['dimensions'] = array(
					'type'    => 'integer',
					'default' => 512,
					'minimum' => 0,
					'maximum' => 4096,
				);
			}
			return array(
				'type'       => 'object',
				'properties' => $properties,
			);
		};

		return array(
			'type'        => 'object',
			'description' => __( 'AI providers, models, and routing.', 'chatlume-ai-chatbot' ),
			'properties'  => array(
				'primary'    => $role(),
				'fallback'   => $role(),
				'embeddings' => $role( true ),
				'configs'    => array(
					'type'       => 'object',
					'properties' => ProviderRegistry::settings_schemas(),
				),
			),
		);
	}

	/**
	 * Default values for a schema node.
	 *
	 * @param array<string, mixed> $schema Schema node.
	 * @return mixed
	 */
	public static function defaults( array $schema ) {
		if ( 'object' === ( $schema['type'] ?? '' ) ) {
			$values = array();
			foreach ( $schema['properties'] ?? array() as $key => $child ) {
				$values[ $key ] = self::defaults( $child );
			}
			return $values;
		}

		if ( array_key_exists( 'default', $schema ) ) {
			return $schema['default'];
		}

		switch ( $schema['type'] ?? '' ) {
			case 'boolean':
				return false;
			case 'integer':
				return (int) ( $schema['minimum'] ?? 0 );
			case 'number':
				return (float) ( $schema['minimum'] ?? 0 );
			case 'array':
				return array();
			default:
				return '';
		}
	}

	/**
	 * Defaults for every section.
	 *
	 * @return array<string, mixed>
	 */
	public static function all_defaults(): array {
		return self::defaults(
			array(
				'type'       => 'object',
				'properties' => self::definition(),
			)
		);
	}

	/**
	 * Validates a (possibly partial) value against a schema node.
	 *
	 * Types, enums, unknown keys, and maximum lengths are enforced strictly.
	 * Out-of-range numbers are accepted here and clamped by sanitize().
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $schema Schema node.
	 * @param string               $path   Dotted path, used in error messages.
	 * @return true|WP_Error
	 */
	public static function validate( $value, array $schema, string $path ) {
		$type = $schema['type'] ?? 'string';

		switch ( $type ) {
			case 'object':
				if ( ! is_array( $value ) || ( array() !== $value && self::is_list( $value ) ) ) {
					return self::error( $path, __( 'must be an object', 'chatlume-ai-chatbot' ) );
				}
				$properties = $schema['properties'] ?? array();
				foreach ( $value as $key => $child ) {
					if ( ! is_string( $key ) || ! isset( $properties[ $key ] ) ) {
						return self::error( $path . '.' . $key, __( 'is not a recognized setting', 'chatlume-ai-chatbot' ) );
					}
					$result = self::validate( $child, $properties[ $key ], $path . '.' . $key );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				return true;

			case 'boolean':
				if ( ! is_bool( $value ) && ! in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true ) ) {
					return self::error( $path, __( 'must be true or false', 'chatlume-ai-chatbot' ) );
				}
				return true;

			case 'integer':
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return self::error( $path, __( 'must be a number', 'chatlume-ai-chatbot' ) );
				}
				if ( 'integer' === $type && 0.0 !== fmod( (float) $value, 1.0 ) ) {
					return self::error( $path, __( 'must be a whole number', 'chatlume-ai-chatbot' ) );
				}
				return true;

			case 'array':
				if ( ! is_array( $value ) || ! self::is_list( $value ) ) {
					return self::error( $path, __( 'must be a list', 'chatlume-ai-chatbot' ) );
				}
				if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) {
					return self::error( $path, __( 'has too many items', 'chatlume-ai-chatbot' ) );
				}
				foreach ( $value as $index => $item ) {
					$result = self::validate( $item, $schema['items'] ?? array( 'type' => 'string' ), $path . '.' . $index );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				return true;

			default:
				if ( ! is_string( $value ) ) {
					return self::error( $path, __( 'must be text', 'chatlume-ai-chatbot' ) );
				}
				if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
					return self::error( $path, __( 'is not an allowed value', 'chatlume-ai-chatbot' ) );
				}
				if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > (int) $schema['maxLength'] ) {
					return self::error( $path, __( 'is too long', 'chatlume-ai-chatbot' ) );
				}
				if ( 'url' === ( $schema['format'] ?? '' ) && '' !== trim( $value ) && ! self::is_http_url( trim( $value ) ) ) {
					return self::error( $path, __( 'must be a web address starting with http:// or https://', 'chatlume-ai-chatbot' ) );
				}
				if ( 'header-name' === ( $schema['format'] ?? '' ) && '' !== $value && ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $value ) ) {
					return self::error( $path, __( 'may contain only letters, numbers, and dashes', 'chatlume-ai-chatbot' ) );
				}
				if ( 'ip-range' === ( $schema['format'] ?? '' ) && ! IpResolver::is_valid_range( trim( $value ) ) ) {
					return self::error( $path, __( 'must be an IP address or a range such as 203.0.113.0/24', 'chatlume-ai-chatbot' ) );
				}
				if ( 'email' === ( $schema['format'] ?? '' ) && '' !== trim( $value ) && ! is_email( trim( $value ) ) ) {
					return self::error( $path, __( 'must be a valid email address', 'chatlume-ai-chatbot' ) );
				}
				return true;
		}
	}

	/**
	 * Sanitizes a (possibly partial) value against a schema node.
	 *
	 * Unknown object keys are dropped, numbers are clamped to minimum/maximum,
	 * strings are sanitized by format and cut to maxLength, and values outside
	 * an enum fall back to the default.
	 *
	 * @param mixed                $value  Raw value.
	 * @param array<string, mixed> $schema Schema node.
	 * @return mixed
	 */
	public static function sanitize( $value, array $schema ) {
		$type = $schema['type'] ?? 'string';

		switch ( $type ) {
			case 'object':
				$clean      = array();
				$properties = $schema['properties'] ?? array();
				if ( ! is_array( $value ) ) {
					return $clean;
				}
				foreach ( $value as $key => $child ) {
					if ( is_string( $key ) && isset( $properties[ $key ] ) ) {
						$clean[ $key ] = self::sanitize( $child, $properties[ $key ] );
					}
				}
				return $clean;

			case 'boolean':
				return rest_sanitize_boolean( $value );

			case 'integer':
				return (int) self::clamp( (int) round( (float) $value ), $schema );

			case 'number':
				return (float) self::clamp( (float) $value, $schema );

			case 'array':
				$items = is_array( $value ) ? array_values( $value ) : array();
				if ( isset( $schema['maxItems'] ) ) {
					$items = array_slice( $items, 0, (int) $schema['maxItems'] );
				}
				$item_schema = $schema['items'] ?? array( 'type' => 'string' );
				return array_map(
					static function ( $item ) use ( $item_schema ) {
						return self::sanitize( $item, $item_schema );
					},
					$items
				);

			default:
				return self::sanitize_string( is_scalar( $value ) ? (string) $value : '', $schema );
		}
	}

	/**
	 * Clamps a number to the schema's minimum and maximum.
	 *
	 * @param int|float            $value  Number.
	 * @param array<string, mixed> $schema Schema node.
	 * @return int|float
	 */
	private static function clamp( $value, array $schema ) {
		if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$value = $schema['minimum'];
		}
		if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
			$value = $schema['maximum'];
		}
		return $value;
	}

	/**
	 * Sanitizes a string by format, enum, and maximum length.
	 *
	 * @param string               $value  Raw string.
	 * @param array<string, mixed> $schema Schema node.
	 * @return string
	 */
	private static function sanitize_string( string $value, array $schema ): string {
		switch ( $schema['format'] ?? 'text' ) {
			case 'textarea':
				$value = sanitize_textarea_field( $value );
				break;
			case 'email':
				$value = sanitize_email( $value );
				break;
			case 'url':
				$value = trim( $value );
				$value = '' !== $value && self::is_http_url( $value ) ? untrailingslashit( esc_url_raw( $value, array( 'http', 'https' ) ) ) : '';
				break;
			case 'model':
				// Model IDs look like "vendor/name:tag@version"; anything else is removed.
				$value = (string) preg_replace( '/[^A-Za-z0-9._:\/@+\-]/', '', trim( $value ) );
				break;
			case 'header-name':
				$value = (string) preg_replace( '/[^A-Za-z0-9-]/', '', $value );
				break;
			case 'rich':
				// Visitor-facing rich text (welcome message): bold, italics, links, line breaks.
				$value = wp_kses(
					$value,
					array(
						'strong' => array(),
						'b'      => array(),
						'em'     => array(),
						'i'      => array(),
						'br'     => array(),
						'a'      => array(
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
					)
				);
				break;
			case 'ip-range':
				$value = (string) preg_replace( '/[^0-9A-Fa-f:.\/]/', '', $value );
				break;
			case 'hex-color':
				$value = (string) sanitize_hex_color( $value );
				break;
			case 'key':
				$value = sanitize_key( $value );
				break;
			default:
				$value = sanitize_text_field( $value );
		}

		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return (string) self::defaults( $schema );
		}

		if ( isset( $schema['maxLength'] ) ) {
			$value = mb_substr( $value, 0, (int) $schema['maxLength'] );
		}

		return $value;
	}

	/**
	 * REST route arguments generated from the schema: one optional object
	 * argument per section, validated and sanitized with this class.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function rest_args(): array {
		$args = array();

		foreach ( self::definition() as $section => $schema ) {
			$args[ $section ] = array(
				'type'              => 'object',
				'description'       => $schema['description'] ?? '',
				'required'          => false,
				'validate_callback' => static function ( $value, $request, $param ) {
					unset( $request );
					$definition = self::definition();
					return self::validate( $value, $definition[ $param ], (string) $param );
				},
				'sanitize_callback' => static function ( $value, $request, $param ) {
					unset( $request );
					$definition = self::definition();
					return self::sanitize( $value, $definition[ $param ] );
				},
			);
		}

		return $args;
	}

	/**
	 * Whether a string is an absolute http(s) URL. Local addresses are allowed
	 * on purpose (Ollama and other self-hosted servers).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_http_url( string $url ): bool {
		return false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true );
	}

	/**
	 * Whether an array has sequential integer keys starting at 0
	 * (array_is_list() requires PHP 8.1; Chatlume supports 8.0).
	 *
	 * @param array<mixed> $value Array to test.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 ) || array() === $value;
	}

	/**
	 * Builds a validation error.
	 *
	 * @param string $path    Dotted setting path.
	 * @param string $problem What is wrong, e.g. "must be a number".
	 * @return WP_Error
	 */
	private static function error( string $path, string $problem ): WP_Error {
		return new WP_Error(
			'rest_invalid_param',
			/* translators: 1: Setting path such as "privacy.delete_data_on_uninstall", 2: Problem description such as "must be a number". */
			sprintf( __( 'The setting "%1$s" %2$s.', 'chatlume-ai-chatbot' ), $path, $problem ),
			array( 'status' => 400 )
		);
	}
}
