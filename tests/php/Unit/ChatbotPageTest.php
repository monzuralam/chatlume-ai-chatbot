<?php
/**
 * Tests for the Chatbot page data.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Chat\BotText;
use Chatlume\Chat\CostGuard;
use Chatlume\Chat\PromptBuilder;
use Chatlume\REST\ChatbotController;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Preset defaults, prompt variables, and budget usage.
 */
class ChatbotPageTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
	}

	public function test_defaults_cover_every_preset(): void {
		$defaults = BotText::defaults();

		$this->assertSame( array( 'support', 'sales', 'faq', 'blank' ), array_keys( $defaults['presets'] ) );
		foreach ( $defaults['presets'] as $preset => $texts ) {
			$this->assertNotSame( '', $texts['welcome'], $preset );
			$this->assertSame( PromptBuilder::preset_prompt( $preset ), $texts['prompt'], $preset );
		}
		$this->assertSame( '', $defaults['presets']['blank']['prompt'] );
		$this->assertSame( array(), $defaults['presets']['blank']['starters'] );
		$this->assertSame( 'Assistant', $defaults['bot_name'] );
		$this->assertSame( BotText::default_offline(), $defaults['offline'] );
	}

	public function test_empty_settings_use_the_preset_defaults(): void {
		$this->options[ Settings::OPTION ] = array( 'chatbot' => array( 'preset' => 'faq' ) );

		$this->assertSame( BotText::default_welcome( 'faq' ), BotText::welcome() );
		$this->assertSame( BotText::default_starters( 'faq' ), BotText::starters() );
		$this->assertSame( BotText::default_refusal(), BotText::refusal() );

		$this->options[ Settings::OPTION ] = array(
			'chatbot' => array(
				'preset'            => 'faq',
				'welcome_message'   => 'Welcome!',
				'starter_questions' => array( 'Mine?' ),
				'refusal_message'   => 'No.',
			),
		);

		$this->assertSame( 'Welcome!', BotText::welcome() );
		$this->assertSame( array( 'Mine?' ), BotText::starters() );
		$this->assertSame( 'No.', BotText::refusal() );
	}

	public function test_unknown_presets_fall_back_to_support(): void {
		$this->assertSame( BotText::default_welcome( 'support' ), BotText::default_welcome( 'nope' ) );
		$this->assertSame( BotText::default_starters( 'support' ), BotText::default_starters( 'nope' ) );
	}

	public function test_route_returns_defaults_variables_and_usage(): void {
		Functions\when( 'wp_date' )->alias(
			static function ( $format ) {
				return gmdate( $format );
			}
		);
		$this->options[ CostGuard::OPTION ] = array(
			'day'          => gmdate( 'Y-m-d' ),
			'month'        => gmdate( 'Y-m' ),
			'day_tokens'   => 120,
			'month_tokens' => 3400,
			'day_cost'     => 0.0123456789,
			'month_cost'   => 1.5,
		);

		$data = ( new ChatbotController() )->get_item()->get_data();

		$this->assertArrayHasKey( 'presets', $data['defaults'] );
		$this->assertSame( PromptBuilder::VARIABLES, array_column( $data['variables'], 'name' ) );
		foreach ( $data['variables'] as $variable ) {
			$this->assertNotSame( '', $variable['description'] );
		}
		$this->assertSame(
			array(
				'day_tokens'   => 120,
				'month_tokens' => 3400,
				'day_cost'     => 0.012346,
				'month_cost'   => 1.5,
			),
			$data['usage']
		);
	}

	public function test_route_requires_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, ( new ChatbotController() )->permissions_check() );
	}
}
