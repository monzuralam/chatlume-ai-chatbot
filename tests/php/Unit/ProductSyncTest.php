<?php
/**
 * Tests for WooCommerce product sync.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Cron\IndexQueue;
use Chatlume\Knowledge\Indexer;
use Chatlume\Knowledge\ProductSync;
use Chatlume\Tests\TestCase;
use WP_Post;

/**
 * Product text, eligibility, routing from the post indexer, and batching.
 */
class ProductSyncTest extends TestCase {

	/**
	 * Products by ID.
	 *
	 * @var array<int, object>
	 */
	private array $products = array();

	/**
	 * Fake database.
	 *
	 * @var object
	 */
	private object $db;

	protected function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->db = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Posts table.
			 *
			 * @var string
			 */
			public $posts = 'wp_posts';

			/**
			 * Stored sources: "type:id" => [title, url, chunks].
			 *
			 * @var array<string, array>
			 */
			public array $stored = array();

			/**
			 * Inserts per source, to count index runs.
			 *
			 * @var array<string, int>
			 */
			public array $writes = array();

			/**
			 * Last prepared values.
			 *
			 * @var array
			 */
			public array $last = array();

			/**
			 * Records values.
			 *
			 * @param string $query   Query.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$this->last = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return $query;
			}

			/**
			 * No earlier rows.
			 *
			 * @return array
			 */
			public function get_results() {
				return array();
			}

			/**
			 * Deletes a source.
			 *
			 * @param string $query Query.
			 * @return int
			 */
			public function query( $query ) {
				if ( 0 === strpos( $query, 'DELETE' ) && isset( $this->last[2] ) ) {
					unset( $this->stored[ $this->last[1] . ':' . $this->last[2] ] );
				}
				return 1;
			}

			/**
			 * Stores a chunk.
			 *
			 * @param string $table Table.
			 * @param array  $data  Row.
			 * @return int
			 */
			public function insert( $table, $data ) {
				unset( $table );
				$key = $data['source_type'] . ':' . $data['source_id'];
				if ( 0 === $data['chunk_index'] ) {
					$this->stored[ $key ] = array( $data['title'], $data['url'], array() );
					$this->writes[ $key ] = ( $this->writes[ $key ] ?? 0 ) + 1;
				}
				$this->stored[ $key ][2][] = $data['content'];
				return 1;
			}

			/**
			 * Candidate count.
			 *
			 * @return string
			 */
			public function get_var() {
				return '0';
			}
		};
		$wpdb     = $this->db;

		$this->products = array();
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				return $this->products[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'post_type_exists' )->alias(
			static function ( $type ) {
				return in_array( $type, array( 'post', 'page', 'product' ), true );
			}
		);
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'wc_price' )->alias(
			static function ( $amount ) {
				return '<span class="amount"><bdi><span class="symbol">&#2547;</span>&nbsp;' . number_format( (float) $amount ) . '</bdi></span>';
			}
		);
		Functions\when( 'wc_get_price_to_display' )->alias(
			static function ( $product, $args = array() ) {
				return (float) ( $args['price'] ?? $product->get_price() );
			}
		);
		Functions\when( 'wc_get_product_category_list' )->justReturn( '<a href="/mugs">Mugs</a>, <a href="/gifts">Gifts &amp; more</a>' );
		Functions\when( 'wc_attribute_label' )->alias(
			static function ( $name ) {
				return ucfirst( str_replace( 'pa_', '', (string) $name ) );
			}
		);
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
	}

	protected function tear_down(): void {
		global $wpdb;
		$wpdb = null;
		ProductSync::flush();
		parent::tear_down();
	}

	/**
	 * A product double.
	 *
	 * @param int                  $id     ID.
	 * @param array<string, mixed> $fields Fields.
	 * @return object
	 */
	private function product( int $id, array $fields = array() ): object {
		$product = new class( array_merge(
			array(
				'id'                 => $id,
				'type'               => 'simple',
				'name'               => 'Travel mug',
				'status'             => 'publish',
				'post_password'      => '',
				'catalog_visibility' => 'visible',
				'stock_status'       => 'instock',
				'managing_stock'     => false,
				'stock_quantity'     => null,
				'price'              => '720',
				'regular_price'      => '720',
				'on_sale'            => false,
				'sku'                => '',
				'attributes'         => array(),
				'short_description'  => '',
				'description'        => '',
				'parent_id'          => 0,
				'permalink'          => 'https://example.test/product/' . $id . '/',
				'variation_prices'   => array( 0, 0 ),
			),
			$fields
		) ) {
			/**
			 * Fields.
			 *
			 * @var array<string, mixed>
			 */
			public array $f;

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $fields Fields.
			 */
			public function __construct( array $fields ) {
				$this->f = $fields;
			}

			/**
			 * Getters by name, like WC_Product.
			 *
			 * @param string $method Method.
			 * @param array  $args   Arguments.
			 * @return mixed
			 */
			public function __call( $method, $args ) {
				$map = array(
					'get_id'                 => 'id',
					'get_type'               => 'type',
					'get_name'               => 'name',
					'get_status'             => 'status',
					'get_post_password'      => 'post_password',
					'get_catalog_visibility' => 'catalog_visibility',
					'get_stock_status'       => 'stock_status',
					'managing_stock'         => 'managing_stock',
					'get_stock_quantity'     => 'stock_quantity',
					'get_price'              => 'price',
					'get_regular_price'      => 'regular_price',
					'is_on_sale'             => 'on_sale',
					'get_sku'                => 'sku',
					'get_attributes'         => 'attributes',
					'get_short_description'  => 'short_description',
					'get_description'        => 'description',
					'get_parent_id'          => 'parent_id',
					'get_permalink'          => 'permalink',
				);
				if ( 'get_attribute' === $method ) {
					return $this->f['attribute_values'][ $args[0] ] ?? '';
				}
				return $this->f[ $map[ $method ] ];
			}

			/**
			 * Variable product price.
			 *
			 * @param string $which min or max.
			 * @return float
			 */
			public function get_variation_price( $which ) {
				return 'min' === $which ? $this->f['variation_prices'][0] : $this->f['variation_prices'][1];
			}
		};

		$this->products[ $id ] = $product;

		return $product;
	}

	/**
	 * An attribute double.
	 *
	 * @param string $name    Name.
	 * @param bool   $visible Visible on the product page.
	 * @return object
	 */
	private static function attribute( string $name, bool $visible ): object {
		return new class( $name, $visible ) {
			/**
			 * Name.
			 *
			 * @var string
			 */
			private string $name;

			/**
			 * Visible.
			 *
			 * @var bool
			 */
			private bool $visible;

			/**
			 * Constructor.
			 *
			 * @param string $name    Name.
			 * @param bool   $visible Visible.
			 */
			public function __construct( string $name, bool $visible ) {
				$this->name    = $name;
				$this->visible = $visible;
			}

			/**
			 * Name.
			 *
			 * @return string
			 */
			public function get_name() {
				return $this->name;
			}

			/**
			 * Visible.
			 *
			 * @return bool
			 */
			public function get_visible() {
				return $this->visible;
			}
		};
	}

	public function test_product_text_has_price_stock_categories_and_details(): void {
		$product = $this->product(
			241,
			array(
				'name'              => 'মিনি টোট ব্যাগ',
				'price'             => '260',
				'regular_price'     => '320',
				'on_sale'           => true,
				'managing_stock'    => true,
				'stock_quantity'    => 7,
				'sku'               => 'TOTE-1',
				'attributes'        => array(
					'pa_color' => self::attribute( 'pa_color', true ),
					'internal' => self::attribute( 'internal', false ),
				),
				'attribute_values'  => array(
					'pa_color' => 'Red, Blue',
					'internal' => 'secret',
				),
				'short_description' => '<p>Small &amp; light.</p>',
				'description'       => '<p>Cotton canvas.</p>[gallery]',
			)
		);

		$this->assertSame(
			"মিনি টোট ব্যাগ\nPrice: ৳ 260 on sale (regular price ৳ 320)\nAvailability: In stock (7 available)\nCategories: Mugs, Gifts & more\nSKU: TOTE-1\nColor: Red, Blue\n\nSmall & light.\n\nCotton canvas.",
			ProductSync::text_of( $product )
		);
	}

	public function test_variable_prices_and_stock_states(): void {
		$range = $this->product(
			1,
			array(
				'type'             => 'variable',
				'variation_prices' => array( 10, 30 ),
			)
		);
		$this->assertStringContainsString( 'Price: from ৳ 10 to ৳ 30', ProductSync::text_of( $range ) );

		$single = $this->product(
			2,
			array(
				'type'             => 'variable',
				'variation_prices' => array( 15, 15 ),
			)
		);
		$this->assertStringContainsString( "Price: ৳ 15\n", ProductSync::text_of( $single ) );

		$no_price = $this->product(
			3,
			array(
				'price'        => '',
				'stock_status' => 'outofstock',
			)
		);
		$text     = ProductSync::text_of( $no_price );
		$this->assertStringNotContainsString( 'Price:', $text );
		$this->assertStringContainsString( 'Availability: Out of stock', $text );

		$backorder = $this->product( 4, array( 'stock_status' => 'onbackorder' ) );
		$this->assertStringContainsString( 'Availability: Available on backorder', ProductSync::text_of( $backorder ) );
	}

	public function test_only_visible_published_products_are_eligible(): void {
		$this->assertTrue( ProductSync::eligible( $this->product( 1 ) ) );
		$this->assertFalse( ProductSync::eligible( $this->product( 2, array( 'status' => 'draft' ) ) ) );
		$this->assertFalse( ProductSync::eligible( $this->product( 3, array( 'post_password' => 'x' ) ) ) );
		$this->assertFalse( ProductSync::eligible( $this->product( 4, array( 'catalog_visibility' => 'hidden' ) ) ) );

		$out = $this->product( 5, array( 'stock_status' => 'outofstock' ) );
		$this->assertTrue( ProductSync::eligible( $out ) );
		$this->options['woocommerce_hide_out_of_stock_items'] = 'yes';
		$this->assertFalse( ProductSync::eligible( $out ) );

		$this->options['chatlume_settings'] = array(
			'knowledge' => array(
				'products'    => false,
				'include_ids' => array( 6 ),
				'exclude_ids' => array( 1 ),
			),
		);
		$this->assertFalse( ProductSync::eligible( $this->products[1] ) );
		$this->assertFalse( ProductSync::eligible( $this->product( 7 ) ) );
		$this->assertTrue( ProductSync::eligible( $this->product( 6 ) ) );
	}

	public function test_index_stores_the_product_and_variations_index_their_parent(): void {
		$this->product( 10, array( 'name' => 'Teapot' ) );
		$this->product(
			11,
			array(
				'type'      => 'variation',
				'parent_id' => 10,
			)
		);
		$sync = new ProductSync();

		$this->assertSame( 10, $sync->index( 11 ) );
		$this->assertSame( 'Teapot', $this->db->stored['product:10'][0] );
		$this->assertSame( 'https://example.test/product/10/', $this->db->stored['product:10'][1] );
		$this->assertArrayNotHasKey( 'product:11', $this->db->stored );

		$this->products[10]->f['status'] = 'draft';
		$this->assertSame( 0, $sync->index( 10 ) );
		$this->assertArrayNotHasKey( 'product:10', $this->db->stored );

		$this->assertSame( 0, $sync->index( 999 ) );
	}

	public function test_changes_are_indexed_once_at_the_end_of_the_request(): void {
		$this->product( 20 );
		$this->product( 21 );
		$sync = new ProductSync();

		$sync->on_change( 20 );
		$sync->on_change( $this->products[20] );
		$sync->on_sales( array( 20, 21, 0 ) );
		$this->assertSame( array(), $this->db->stored );

		$this->assertSame( 2, ProductSync::flush() );
		$this->assertSame( 1, $this->db->writes['product:20'] );
		$this->assertSame( 1, $this->db->writes['product:21'] );
		$this->assertSame( 0, ProductSync::flush() );
	}

	public function test_post_indexer_routes_products_to_the_sync(): void {
		$this->options['chatlume_settings'] = array( 'knowledge' => array( 'post_types' => array( 'page', 'product' ) ) );
		$this->product( 30, array( 'name' => 'Kettle' ) );
		$post    = new WP_Post(
			array(
				'ID'        => 30,
				'post_type' => 'product',
			)
		);
		$indexer = new Indexer();

		$this->assertSame( array( 'page' ), Indexer::post_types() );

		$indexer->on_save( 30, $post );
		$this->assertArrayNotHasKey( 'product:30', $this->db->stored );
		ProductSync::flush();
		$this->assertSame( 'Kettle', $this->db->stored['product:30'][0] );
		$this->assertArrayNotHasKey( 'post:30', $this->db->stored );

		$this->assertSame( 1, $indexer->index_post( $post ) );

		$indexer->on_remove( 30 );
		$this->assertArrayNotHasKey( 'product:30', $this->db->stored );
	}

	public function test_rebuilds_include_products_only_when_enabled(): void {
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );

		( new IndexQueue() )->start();
		$this->assertContains( 'product', $this->db->last );

		$this->options['chatlume_settings'] = array( 'knowledge' => array( 'products' => false ) );
		( new IndexQueue() )->start();
		$this->assertNotContains( 'product', $this->db->last );
	}

	public function test_an_index_without_products_is_rebuilt_when_woocommerce_appears(): void {
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		$this->options[ IndexQueue::OPTION ] = array(
			'status' => 'done',
			'types'  => array( 'page', 'post' ),
		);

		( new IndexQueue() )->maybe_start_first();
		$this->assertSame( 'running', IndexQueue::state()['status'] );
		$this->assertSame( array( 'page', 'post', 'product' ), IndexQueue::state()['types'] );

		// Same types: nothing to do.
		$this->options[ IndexQueue::OPTION ]['status'] = 'done';
		( new IndexQueue() )->maybe_start_first();
		$this->assertSame( 'done', IndexQueue::state()['status'] );
	}

	public function test_switching_products_rebuilds_the_index(): void {
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		$old = array( 'knowledge' => array( 'products' => true ) );
		$new = array( 'knowledge' => array( 'products' => false ) );

		( new IndexQueue() )->on_settings( $old, $new );

		$this->assertSame( 'running', IndexQueue::state()['status'] );
	}
}
