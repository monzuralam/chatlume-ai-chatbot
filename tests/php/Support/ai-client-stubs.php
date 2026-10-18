<?php
/**
 * Minimal stand-ins for the WordPress AI Client message classes (WordPress
 * 7.0+), which the unit tests run without.
 *
 * @package Chatlume
 */

namespace WordPress\AiClient\Messages\DTO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Test stand-ins for one external namespace, kept together.

if ( ! class_exists( MessagePart::class ) ) {
	/**
	 * A text part.
	 */
	class MessagePart {

		/**
		 * Text.
		 *
		 * @var string
		 */
		public $text;

		/**
		 * Constructor.
		 *
		 * @param string $text Text.
		 */
		public function __construct( $text ) {
			$this->text = $text;
		}
	}

	/**
	 * A message.
	 */
	class Message {

		/**
		 * Role.
		 *
		 * @var string
		 */
		public $role;

		/**
		 * Parts.
		 *
		 * @var MessagePart[]
		 */
		public $parts;

		/**
		 * Constructor.
		 *
		 * @param string        $role  Role.
		 * @param MessagePart[] $parts Parts.
		 */
		public function __construct( $role, array $parts ) {
			$this->role  = $role;
			$this->parts = $parts;
		}
	}

	/**
	 * A user message.
	 */
	class UserMessage extends Message {

		/**
		 * Constructor.
		 *
		 * @param MessagePart[] $parts Parts.
		 */
		public function __construct( array $parts ) {
			parent::__construct( 'user', $parts );
		}
	}

	/**
	 * A model message.
	 */
	class ModelMessage extends Message {

		/**
		 * Constructor.
		 *
		 * @param MessagePart[] $parts Parts.
		 */
		public function __construct( array $parts ) {
			parent::__construct( 'model', $parts );
		}
	}
}
