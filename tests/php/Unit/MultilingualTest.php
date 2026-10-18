<?php
/**
 * Tests for languages and per-language widget texts.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\Chat\BotText;
use Chatlume\Compat\Multilingual;
use Chatlume\Frontend\WidgetStrings;
use Chatlume\Frontend\WidgetTexts;
use Chatlume\REST\ChatController;
use Chatlume\REST\TextsController;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Language detection (Polylang, WPML, none), text resolution, saving, and
 * the Widget texts routes.
 */
class MultilingualTest extends TestCase {

	/**
	 * Locales switched to: locale => times.
	 *
	 * @var array<int, string>
	 */
	private array $switched = array();

	protected function set_up(): void {
		parent::set_up();
		$this->switched = array();
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'switch_to_locale' )->alias(
			function ( $locale ) {
				$this->switched[] = $locale;
				return true;
			}
		);
		Functions\when( 'restore_previous_locale' )->justReturn( true );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
	}

	/**
	 * Polylang with English (main), Bengali, and European Portuguese.
	 *
	 * @param string $current Current language slug.
	 * @return void
	 */
	private function polylang( string $current = 'bn' ): void {
		$languages = array(
			'slug'   => array( 'bn', 'en', 'pt' ),
			'locale' => array( 'bn_BD', 'en_US', 'pt_PT' ),
			'name'   => array( 'বাংলা', 'English', 'Português' ),
		);
		Functions\when( 'pll_languages_list' )->alias(
			static function ( $args = array() ) use ( $languages ) {
				return $languages[ $args['fields'] ?? 'slug' ];
			}
		);
		Functions\when( 'pll_default_language' )->justReturn( 'en' );
		Functions\when( 'pll_current_language' )->justReturn( $current );
		Multilingual::reset();
	}

	public function test_a_single_language_site(): void {
		$this->assertFalse( Multilingual::active() );
		$this->assertSame(
			array(
				array(
					'code'   => 'en',
					'locale' => 'en_US',
					'name'   => 'en_US',
				),
			),
			Multilingual::languages()
		);
		$this->assertSame( 'en', Multilingual::main() );
		$this->assertSame( 'en', Multilingual::current() );
		$this->assertSame( 'en', Multilingual::from_tag( 'bn-BD' ) );
	}

	public function test_polylang_languages_and_page_language_tags(): void {
		$this->polylang();

		$this->assertSame( 'polylang', Multilingual::plugin() );
		$this->assertTrue( Multilingual::active() );
		$this->assertSame( array( 'en', 'bn', 'pt' ), array_column( Multilingual::languages(), 'code' ), 'Main language first.' );
		$this->assertSame( 'বাংলা', Multilingual::languages()[1]['name'] );
		$this->assertSame( 'bn', Multilingual::current() );
		$this->assertSame( 'bn_BD', Multilingual::locale( 'bn' ) );

		$this->assertSame( 'bn', Multilingual::from_tag( 'bn-BD' ) );
		$this->assertSame( 'bn', Multilingual::from_tag( 'BN' ) );
		$this->assertSame( 'pt', Multilingual::from_tag( 'pt-BR' ), 'Same primary language.' );
		$this->assertSame( 'en', Multilingual::from_tag( 'fr-FR' ), 'Unknown: the main language.' );
		$this->assertSame( 'en', Multilingual::from_tag( '' ) );
		$this->assertFalse( Multilingual::known( 'fr' ) );
	}

	/**
	 * WPML defines a constant, so this runs on its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wpml_languages(): void {
		define( 'ICL_SITEPRESS_VERSION', '4.7.0' );
		Filters\expectApplied( 'wpml_active_languages' )->andReturn(
			array(
				'de' => array(
					'language_code'  => 'de',
					'default_locale' => 'de_DE',
					'native_name'    => 'Deutsch',
				),
				'en' => array(
					'language_code'  => 'en',
					'default_locale' => 'en_US',
					'native_name'    => 'English',
				),
			)
		);
		Filters\expectApplied( 'wpml_default_language' )->andReturn( 'en' );
		Filters\expectApplied( 'wpml_current_language' )->andReturn( 'de' );

		$this->assertSame( 'wpml', Multilingual::plugin() );
		$this->assertSame( array( 'en', 'de' ), array_column( Multilingual::languages(), 'code' ) );
		$this->assertSame( 'de', Multilingual::current() );
		$this->assertSame( 'de', Multilingual::from_tag( 'de-AT' ) );
	}

	public function test_texts_resolve_per_language(): void {
		$this->polylang();
		$this->options[ Settings::OPTION ]    = array(
			'chatbot' => array(
				'welcome_message'   => 'Hello!',
				'starter_questions' => array( 'Do you ship?', 'Prices?' ),
				'quick_actions'     => array(
					array(
						'label' => 'Ask about sizes',
						'type'  => 'message',
						'value' => 'Which size fits me?',
					),
				),
			),
		);
		$this->options[ WidgetTexts::OPTION ] = array(
			'bn' => array(
				'labels'  => array(
					'placeholder' => 'বার্তা লিখুন…',
					'error'       => 'ignored',
				),
				'content' => array(
					'chatbot.welcome_message'       => 'স্বাগতম!',
					'chatbot.starter_questions.0'   => 'আপনি কি পাঠান?',
					'chatbot.quick_actions.0.label' => 'সাইজ জানুন',
					'chatbot.quick_actions.0.value' => 'কোন সাইজ আমার?',
					'chatbot.refusal_message'       => 'not used: the main text is empty',
				),
			),
		);

		WidgetTexts::use_language( 'bn' );
		$this->assertSame( array( 'bn_BD' ), $this->switched, 'Built-in texts follow the language.' );
		$this->assertSame( 'স্বাগতম!', BotText::welcome() );
		$this->assertSame( array( 'আপনি কি পাঠান?', 'Prices?' ), BotText::starters(), 'Untranslated items keep the main text.' );
		$this->assertSame( BotText::default_refusal(), BotText::refusal(), 'Defaults are translated by WordPress, not here.' );
		$actions = ChatController::quick_actions();
		$this->assertSame( 'সাইজ জানুন', $actions[0]['label'] );
		$this->assertSame( 'কোন সাইজ আমার?', $actions[0]['message'] );

		$labels = WidgetTexts::labels();
		$this->assertSame( 'বার্তা লিখুন…', $labels['placeholder'] );
		$this->assertSame( WidgetStrings::all()['error'], $labels['error'], 'Fixed labels cannot be replaced.' );

		WidgetTexts::use_language( 'en' );
		$this->assertSame( 'Hello!', BotText::welcome() );
		$this->assertSame( WidgetStrings::all()['placeholder'], WidgetTexts::labels()['placeholder'] );

		WidgetTexts::use_language( 'fr' );
		$this->assertSame( 'en', WidgetTexts::language(), 'Unknown languages use the main one.' );
	}

	public function test_the_theme_texts_and_version_follow_the_language(): void {
		$this->polylang();
		$this->options[ WidgetTexts::OPTION ] = array(
			'bn' => array(
				'content' => array( 'theme.brand.headerTitle' => 'সহায়তা' ),
			),
		);
		$config                               = array(
			'v'  => 'abc123',
			'ui' => array(
				'brand'    => array(
					'headerTitle'    => 'Support',
					'headerSubtitle' => 'We reply fast',
				),
				'launcher' => array( 'label' => '' ),
			),
		);

		WidgetTexts::use_language( 'bn' );
		$translated = WidgetTexts::theme( $config );
		$this->assertSame( 'সহায়তা', $translated['ui']['brand']['headerTitle'] );
		$this->assertSame( 'We reply fast', $translated['ui']['brand']['headerSubtitle'] );
		$this->assertSame( substr( md5( 'abc123|bn' ), 0, 12 ), $translated['v'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', $translated['v'], 'The session route accepts it.' );

		WidgetTexts::use_language( 'en' );
		$this->assertSame( $config, WidgetTexts::theme( $config ) );
	}

	public function test_saving_keeps_only_known_clean_texts(): void {
		$this->polylang();
		$this->options[ Settings::OPTION ] = array(
			'chatbot' => array( 'welcome_message' => 'Hello!' ),
			'leads'   => array( 'consent' => 'I agree.' ),
		);
		Actions\expectDone( 'chatlume_widget_texts_saved' )->twice();

		$saved = WidgetTexts::save(
			'bn',
			array(
				'placeholder' => " <b>বার্তা</b> লিখুন \n",
				'leadName'    => '   ',
				'charCount'   => '%d',
				'nope'        => 'unknown',
			),
			array(
				'chatbot.welcome_message' => '<strong>স্বাগতম</strong><script>x</script>',
				'leads.consent'           => str_repeat( 'অ', 400 ),
				'chatbot.system_prompt'   => 'not translatable',
				'chatbot.bot_name'        => 'not shown: the main text is empty',
			)
		);

		$this->assertSame( array( 'placeholder' => 'বার্তা লিখুন' ), $saved['labels'] );
		$this->assertSame( '<strong>স্বাগতম</strong>x', $saved['content']['chatbot.welcome_message'] );
		$this->assertSame( 300, mb_strlen( $saved['content']['leads.consent'] ) );
		$this->assertSame( array( 'chatbot.welcome_message', 'leads.consent' ), array_keys( $saved['content'] ) );
		$this->assertFalse( $this->autoload[ WidgetTexts::OPTION ] );

		// The main language keeps no translations; an empty language is removed.
		$main = WidgetTexts::save( 'en', array(), array( 'chatbot.welcome_message' => 'x' ) );
		$this->assertSame( array(), $main['content'] );
		$this->assertArrayNotHasKey( 'en', $this->options[ WidgetTexts::OPTION ] );
		$this->assertArrayHasKey( 'bn', $this->options[ WidgetTexts::OPTION ] );
	}

	public function test_routes_and_state(): void {
		$this->polylang();
		$this->options[ Settings::OPTION ] = array(
			'chatbot' => array( 'welcome_message' => 'Hello!' ),
		);

		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route, $endpoints ) use ( &$routes ) {
				$routes[ $route ] = $endpoints;
				return true;
			}
		);
		$controller = new TextsController();
		$controller->register_routes();
		foreach ( $routes['/texts'] as $endpoint ) {
			$this->assertSame( array( $controller, 'permissions_check' ), $endpoint['permission_callback'] );
			$this->assertArrayHasKey( 'language', $endpoint['args'] );
		}
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, $controller->permissions_check() );

		Functions\when( 'rest_validate_request_arg' )->justReturn( true );
		$this->assertTrue( $controller->validate_language( 'bn', new WP_REST_Request(), 'language' ) );
		$this->assertTrue( $controller->validate_language( '', new WP_REST_Request(), 'language' ) );
		$this->assertInstanceOf( WP_Error::class, $controller->validate_language( 'fr', new WP_REST_Request(), 'language' ) );

		$main = $controller->get_item( new WP_REST_Request( array( 'language' => '' ) ) )->get_data();
		$this->assertSame( 'en', $main['language'] );
		$this->assertSame( array(), $main['content'], 'Own texts are edited on their pages in the main language.' );
		$this->assertArrayHasKey( 'placeholder', $main['labels'] );
		$this->assertArrayNotHasKey( 'charCount', $main['labels'] );
		$this->assertArrayNotHasKey( 'error', $main['labels'] );

		$saved = $controller->update_item(
			new WP_REST_Request(
				array(
					'language' => 'bn',
					'labels'   => array( 'send' => 'পাঠান' ),
					'content'  => array( 'chatbot.welcome_message' => 'স্বাগতম!' ),
				)
			)
		)->get_data();
		$this->assertSame( 'bn', $saved['language'] );
		$this->assertSame( 'পাঠান', $saved['labels']['send']['value'] );
		$this->assertSame( 'chatbot.welcome_message', $saved['content'][0]['path'] );
		$this->assertSame( 'Hello!', $saved['content'][0]['main'] );
		$this->assertSame( 'স্বাগতম!', $saved['content'][0]['value'] );
		$this->assertContains( 'bn_BD', $this->switched, 'Defaults are shown as Bengali visitors see them.' );
	}
}
