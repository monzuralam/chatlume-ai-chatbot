<?php
/**
 * Tests for ProviderError and generic classification.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Providers\AbstractProvider;
use Chatlume\Providers\ProviderError;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Error types, explanations, fix links, visitor messages, and redaction.
 */
class ProviderErrorTest extends TestCase {

	/**
	 * Status and wording cases.
	 *
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public function classification_cases(): array {
		return array(
			'401 bad key'       => array( 401, 'Incorrect API key provided', ProviderError::INVALID_KEY ),
			'400 key wording'   => array( 400, 'API key not valid. Please pass a valid API key.', ProviderError::INVALID_KEY ),
			'429 quota wording' => array( 429, 'You exceeded your current quota, please check your plan and billing details.', ProviderError::QUOTA ),
			'402 balance'       => array( 402, 'Insufficient Balance', ProviderError::QUOTA ),
			'429 rate limit'    => array( 429, 'Rate limit reached for requests', ProviderError::RATE_LIMITED ),
			'404 model'         => array( 404, 'The model `x` does not exist', ProviderError::MODEL_NOT_FOUND ),
			'400 model wording' => array( 400, "model 'x' not found", ProviderError::MODEL_NOT_FOUND ),
			'400 context'       => array( 400, "This model's maximum context length is 8192 tokens", ProviderError::CONTEXT_TOO_LONG ),
			'413 too large'     => array( 413, 'Request too large', ProviderError::CONTEXT_TOO_LONG ),
			'504 timeout'       => array( 504, 'Gateway timeout', ProviderError::TIMEOUT ),
			'500 server'        => array( 500, 'The server had an error', ProviderError::SERVER_ERROR ),
			'529 overloaded'    => array( 529, 'Overloaded', ProviderError::SERVER_ERROR ),
			'501 unsupported'   => array( 501, 'This server does not support embeddings', ProviderError::UNSUPPORTED ),
			'400 other'         => array( 400, 'model name is missing from the request', ProviderError::BAD_REQUEST ),
		);
	}

	/**
	 * Classification by status and wording.
	 *
	 * @dataProvider classification_cases
	 *
	 * @param int    $status   Status.
	 * @param string $message  Message.
	 * @param string $expected Expected type.
	 */
	public function test_classify( int $status, string $message, string $expected ): void {
		$this->assertSame( $expected, AbstractProvider::classify( $status, $message ) );
	}

	public function test_every_type_has_admin_text_and_a_safe_visitor_message(): void {
		foreach ( ProviderError::types() as $type ) {
			$error = new ProviderError( $type, 'openai', 'detail' );
			$this->assertNotSame( '', $error->admin_title(), $type );
			$this->assertNotSame( '', $error->admin_explanation(), $type );
			$this->assertStringNotContainsString( 'detail', $error->visitor_message(), $type );
		}

		$this->assertSame( ProviderError::BAD_REQUEST, ( new ProviderError( 'made_up' ) )->type() );
	}

	public function test_fix_links_point_to_the_right_place(): void {
		$links = array(
			'keys'    => 'https://example.test/keys',
			'billing' => 'https://example.test/billing',
			'status'  => 'https://example.test/status',
		);

		$this->assertSame( $links['keys'], ( new ProviderError( ProviderError::INVALID_KEY, 'x', '', 401, $links ) )->fix_link()['url'] );
		$this->assertSame( $links['billing'], ( new ProviderError( ProviderError::QUOTA, 'x', '', 429, $links ) )->fix_link()['url'] );
		$this->assertSame( $links['status'], ( new ProviderError( ProviderError::SERVER_ERROR, 'x', '', 500, $links ) )->fix_link()['url'] );
		$this->assertStringContainsString( 'page=chatlume#/providers', ( new ProviderError( ProviderError::KEY_UNREADABLE ) )->fix_link()['url'] );
		$this->assertStringContainsString( 'page=chatlume#/providers', ( new ProviderError( ProviderError::INVALID_KEY ) )->fix_link()['url'] );
	}

	public function test_redacts_secrets_but_keeps_error_codes(): void {
		$redacted = ProviderError::redact( 'Incorrect API key provided: sk-proj-ABCDEFGH12345678WXYZ. Code organization_spend_limit_exceeded, gsk_abcdefghijklmnop1234 AIzaSyA1234567890abcdefghijklmnop' );

		$this->assertStringNotContainsString( 'ABCDEFGH12345678', $redacted );
		$this->assertStringNotContainsString( 'abcdefghijklmnop1234', $redacted );
		$this->assertStringNotContainsString( 'SyA1234567890', $redacted );
		$this->assertStringContainsString( 'organization_spend_limit_exceeded', $redacted );
		$this->assertStringContainsString( 'sk-…WXYZ', $redacted );
	}

	public function test_to_array_and_partial_text(): void {
		$error = ( new ProviderError( ProviderError::RATE_LIMITED, 'groq', 'slow down', 429, array(), 12 ) )->with_partial_text( 'Hel' );
		$data  = $error->to_array();

		$this->assertSame( 'rate_limited', $data['type'] );
		$this->assertSame( 'groq', $data['provider'] );
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 12, $data['retry_after'] );
		$this->assertSame( 'Hel', $error->partial_text() );
		$this->assertArrayNotHasKey( 'partial_text', $data );
	}

	public function test_from_transport(): void {
		$this->assertSame( ProviderError::TIMEOUT, ProviderError::from_transport( new WP_Error( 'x', 'cURL error 28: Operation timed out' ), 'openai' )->type() );
		$this->assertSame( ProviderError::CONNECTION, ProviderError::from_transport( new WP_Error( 'x', 'cURL error 7: Failed to connect' ), 'openai' )->type() );
	}

	public function test_exception_message_is_html_escaped(): void {
		$error = new ProviderError( ProviderError::BAD_REQUEST, 'x', '<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>', $error->getMessage() );
		$this->assertSame( '<script>alert(1)</script>', $error->detail() );
	}
}
