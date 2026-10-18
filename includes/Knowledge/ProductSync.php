<?php
/**
 * WooCommerce products in the knowledge base.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

use Chatlume\Data\KnowledgeRepository;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indexes each visible, published product as one source: name, price as
 * shoppers see it (sale and regular price, ranges for variable products),
 * stock status, categories, SKU, visible attributes, short description,
 * description, and link. Products are re-indexed when saved, when stock or
 * stock status changes, and when scheduled sales start or end. Changes are
 * collected during a request and indexed once at its end, so bulk edits
 * and imports index each product only once.
 */
class ProductSync {

	/**
	 * Source type of products.
	 */
	public const TYPE = 'product';

	/**
	 * Product IDs waiting to be indexed at the end of the request.
	 *
	 * @var array<int, true>
	 */
	private static array $pending = array();

	/**
	 * Whether the end-of-request hook is added.
	 *
	 * @var bool
	 */
	private static bool $flush_hooked = false;

	/**
	 * Indexer (stores sources).
	 *
	 * @var Indexer
	 */
	private Indexer $indexer;

	/**
	 * Storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param Indexer|null             $indexer    Indexer (tests).
	 * @param KnowledgeRepository|null $repository Storage (tests).
	 */
	public function __construct( ?Indexer $indexer = null, ?KnowledgeRepository $repository = null ) {
		$this->repository = $repository ?? new KnowledgeRepository();
		$this->indexer    = $indexer ?? new Indexer( $this->repository );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return function_exists( 'wc_get_product' ) && post_type_exists( 'product' );
	}

	/**
	 * Whether all visible products are indexed (otherwise only products
	 * chosen one by one).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return self::active() && (bool) Settings::get( 'knowledge.products', true );
	}

	/**
	 * Hooks product changes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_new_product', array( $this, 'on_change' ) );
		add_action( 'woocommerce_update_product', array( $this, 'on_change' ) );
		add_action( 'woocommerce_new_product_variation', array( $this, 'on_change' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_change' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_change' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_change' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_change' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_change' ) );
		add_action( 'wc_after_products_starting_sales', array( $this, 'on_sales' ) );
		add_action( 'wc_after_products_ending_sales', array( $this, 'on_sales' ) );
	}

	/**
	 * A product or variation changed (ID or product object).
	 *
	 * @param mixed $product Product ID or object.
	 * @return void
	 */
	public function on_change( $product ): void {
		$id = is_object( $product ) && is_callable( array( $product, 'get_id' ) ) ? (int) $product->get_id() : ( is_numeric( $product ) ? (int) $product : 0 );
		if ( $id > 0 ) {
			self::queue( $id );
		}
	}

	/**
	 * Scheduled sales started or ended.
	 *
	 * @param mixed $product_ids Product IDs.
	 * @return void
	 */
	public function on_sales( $product_ids ): void {
		foreach ( (array) $product_ids as $id ) {
			if ( (int) $id > 0 ) {
				self::queue( (int) $id );
			}
		}
	}

	/**
	 * Indexes a product at the end of the request (once, however often it
	 * changed).
	 *
	 * @param int $id Product or variation ID.
	 * @return void
	 */
	public static function queue( int $id ): void {
		self::$pending[ $id ] = true;
		if ( ! self::$flush_hooked ) {
			self::$flush_hooked = true;
			add_action( 'shutdown', array( self::class, 'flush' ) );
		}
	}

	/**
	 * Indexes the queued products.
	 *
	 * @return int Products indexed.
	 */
	public static function flush(): int {
		$ids           = array_keys( self::$pending );
		self::$pending = array();
		$sync          = new self();
		$done          = array();
		foreach ( $ids as $id ) {
			$done[ $sync->index( $id ) ] = true;
		}

		return count( array_filter( array_keys( $done ) ) );
	}

	/**
	 * Indexes a product, or removes it when it should not be indexed.
	 * Variations index their parent product.
	 *
	 * @param int $id Product or variation ID.
	 * @return int The product's ID, or 0 when it was removed or not found.
	 */
	public function index( int $id ): int {
		$product = self::active() ? wc_get_product( $id ) : null;
		if ( $product && 'variation' === $product->get_type() ) {
			$id      = (int) $product->get_parent_id();
			$product = wc_get_product( $id );
		}

		if ( ! $product || ! self::eligible( $product ) ) {
			$this->repository->delete( self::TYPE, (string) $id );
			return 0;
		}

		$post = get_post( $id );

		/** This filter is documented in includes/Knowledge/Indexer.php */
		$source = apply_filters(
			'chatlume_knowledge_sources',
			array(
				'title'    => html_entity_decode( wp_strip_all_tags( (string) $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'url'      => (string) $product->get_permalink(),
				'language' => Language::of_post( $id ),
				'text'     => self::text_of( $product ),
			),
			$post
		);

		return $this->indexer->store( self::TYPE, (string) $id, $source ) > 0 ? $id : 0;
	}

	/**
	 * Whether a product belongs in the index: published, no password,
	 * visible in the shop or search, not excluded, and either all products
	 * are indexed or this one was chosen.
	 *
	 * @param object $product WC_Product.
	 * @return bool
	 */
	public static function eligible( $product ): bool {
		$id = (int) $product->get_id();

		if ( 'publish' !== $product->get_status() || '' !== (string) $product->get_post_password() || 'hidden' === $product->get_catalog_visibility() ) {
			return false;
		}
		if ( 'outofstock' === $product->get_stock_status() && 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' ) ) {
			return false;
		}
		if ( in_array( $id, array_map( 'intval', (array) Settings::get( 'knowledge.exclude_ids', array() ) ), true ) ) {
			return false;
		}

		return self::enabled() || in_array( $id, array_map( 'intval', (array) Settings::get( 'knowledge.include_ids', array() ) ), true );
	}

	/**
	 * The product as text for the AI.
	 *
	 * @param object $product WC_Product.
	 * @return string
	 */
	public static function text_of( $product ): string {
		$lines = array( html_entity_decode( wp_strip_all_tags( (string) $product->get_name() ), ENT_QUOTES, 'UTF-8' ) );

		$price = self::price_text( $product );
		if ( '' !== $price ) {
			/* translators: %s: Product price, for example "$20" or "from $10 to $30". */
			$lines[] = sprintf( __( 'Price: %s', 'chatlume-ai-chatbot' ), $price );
		}

		/* translators: %s: Stock status, for example "In stock". */
		$lines[] = sprintf( __( 'Availability: %s', 'chatlume-ai-chatbot' ), self::stock_text( $product ) );

		$categories = trim( html_entity_decode( wp_strip_all_tags( (string) wc_get_product_category_list( $product->get_id(), ', ' ) ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' !== $categories ) {
			/* translators: %s: Comma-separated product categories. */
			$lines[] = sprintf( __( 'Categories: %s', 'chatlume-ai-chatbot' ), $categories );
		}

		$sku = trim( (string) $product->get_sku() );
		if ( '' !== $sku ) {
			/* translators: %s: Product SKU (stock-keeping unit). */
			$lines[] = sprintf( __( 'SKU: %s', 'chatlume-ai-chatbot' ), $sku );
		}

		foreach ( (array) $product->get_attributes() as $name => $attribute ) {
			if ( ! is_object( $attribute ) || ! $attribute->get_visible() ) {
				continue;
			}
			$value = trim( html_entity_decode( wp_strip_all_tags( (string) $product->get_attribute( (string) $name ) ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $value ) {
				$lines[] = wc_attribute_label( (string) $attribute->get_name(), $product ) . ': ' . $value;
			}
		}

		$parts = array( implode( "\n", $lines ) );
		foreach ( array( (string) $product->get_short_description(), (string) $product->get_description() ) as $text ) {
			$text = Chunker::clean( $text );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Price as shoppers see it (with or without tax, as the shop shows it).
	 *
	 * @param object $product WC_Product.
	 * @return string
	 */
	private static function price_text( $product ): string {
		if ( 'variable' === $product->get_type() && method_exists( $product, 'get_variation_price' ) ) {
			$min = (float) $product->get_variation_price( 'min', true );
			$max = (float) $product->get_variation_price( 'max', true );
			if ( $max <= 0 ) {
				return '';
			}
			return $min === $max
				? self::money( $min )
				/* translators: 1: Lowest price, 2: Highest price. */
				: sprintf( __( 'from %1$s to %2$s', 'chatlume-ai-chatbot' ), self::money( $min ), self::money( $max ) );
		}

		if ( '' === (string) $product->get_price() ) {
			return '';
		}

		$price = (float) wc_get_price_to_display( $product );
		if ( $product->is_on_sale() && '' !== (string) $product->get_regular_price() ) {
			$regular = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
			/* translators: 1: Sale price, 2: Regular price. */
			return sprintf( __( '%1$s on sale (regular price %2$s)', 'chatlume-ai-chatbot' ), self::money( $price ), self::money( $regular ) );
		}

		return self::money( $price );
	}

	/**
	 * Stock status in words.
	 *
	 * @param object $product WC_Product.
	 * @return string
	 */
	private static function stock_text( $product ): string {
		switch ( $product->get_stock_status() ) {
			case 'outofstock':
				return __( 'Out of stock', 'chatlume-ai-chatbot' );
			case 'onbackorder':
				return __( 'Available on backorder', 'chatlume-ai-chatbot' );
			default:
				$quantity = $product->managing_stock() ? $product->get_stock_quantity() : null;
				return null !== $quantity && (int) $quantity > 0
					/* translators: %d: Number of items in stock. */
					? sprintf( _n( 'In stock (%d available)', 'In stock (%d available)', (int) $quantity, 'chatlume-ai-chatbot' ), (int) $quantity )
					: __( 'In stock', 'chatlume-ai-chatbot' );
		}
	}

	/**
	 * Formatted amount as plain text, for example "৳ 260".
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( float $amount ): string {
		$text = html_entity_decode( wp_strip_all_tags( (string) wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );

		return trim( str_replace( "\xC2\xA0", ' ', $text ) );
	}
}
