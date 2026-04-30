<?php
/**
 * FlowSell AI — Commerce Engine
 *
 * Queries WooCommerce products based on dynamic filter criteria
 * derived from the conversation flow answers.
 *
 * @package FlowSell_AI
 */

defined( 'ABSPATH' ) || exit;

class FlowSell_Commerce_Engine {

	/**
	 * Maximum products to return per recommendation request.
	 */
	private const MAX_PRODUCTS = 5;

	/**
	 * Get product recommendations based on conversation filters.
	 *
	 * @param array $filters {
	 *   @type string|int $category   Category slug or ID.
	 *   @type float      $min_price  Minimum price.
	 *   @type float      $max_price  Maximum price.
	 *   @type bool       $in_stock   Only in-stock products.
	 *   @type array      $tag_ids    Product tag IDs.
	 * }
	 * @param int   $limit Override default max results.
	 * @return array Array of formatted product data.
	 */
	public function get_recommendations( array $filters = [], int $limit = 0 ): array {
		$args = $this->build_query_args( $filters, $limit );

		// Generate a unique cache key based on query args
		$cache_key = 'fs_products_' . md5( wp_json_encode( $args ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$query    = new WC_Product_Query( $args );
		$products = $query->get_products();

		$formatted = array_map( [ $this, 'format_product' ], $products );

		// Cache for 1 hour
		set_transient( $cache_key, $formatted, HOUR_IN_SECONDS );

		return $formatted;
	}

	/**
	 * Get a single product by ID.
	 *
	 * @param int $product_id
	 * @return array|null
	 */
	public function get_product( int $product_id ): ?array {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_visible() ) {
			return null;
		}

		return $this->format_product( $product );
	}

	/**
	 * Translate price-range strings from flow answers into numeric filters.
	 * Supports common patterns: "<20k", "20k-50k", "50k+", "<5000", "5000-10000".
	 *
	 * @param string $price_range
	 * @return array { min_price: float, max_price: float }
	 */
	public function parse_price_range( string $price_range ): array {
		$range     = strtolower( trim( $price_range ) );
		$min_price = 0.0;
		$max_price = PHP_FLOAT_MAX;

		// Normalise "k" suffix (e.g. 20k → 20000)
		$range = preg_replace_callback( '/(\d+(\.\d+)?)k/', function ( $m ) {
			return (float) $m[1] * 1000;
		}, $range );

		if ( preg_match( '/^<(\d+(\.\d+)?)$/', $range, $m ) ) {
			$max_price = (float) $m[1];
		} elseif ( preg_match( '/^(\d+(\.\d+)?)\+$/', $range, $m ) ) {
			$min_price = (float) $m[1];
		} elseif ( preg_match( '/^(\d+(\.\d+)?)-(\d+(\.\d+)?)$/', $range, $m ) ) {
			$min_price = (float) $m[1];
			$max_price = (float) $m[3];
		}

		return [ 'min_price' => $min_price, 'max_price' => $max_price ];
	}

	// ─── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Build WC_Product_Query args from filter array.
	 *
	 * @param array $filters
	 * @param int   $limit
	 * @return array
	 */
	private function build_query_args( array $filters, int $limit ): array {
		$args = [
			'status'  => 'publish',
			'limit'   => ( $limit > 0 ) ? min( $limit, self::MAX_PRODUCTS ) : self::MAX_PRODUCTS,
			'orderby' => 'popularity',
			'order'   => 'DESC',
		];

		// Stock filter
		if ( ! empty( $filters['in_stock'] ) ) {
			$args['stock_status'] = 'instock';
		}

		// Category (slug or ID)
		if ( ! empty( $filters['category'] ) ) {
			if ( is_numeric( $filters['category'] ) ) {
				$args['category'] = [ get_term( (int) $filters['category'], 'product_cat' )->slug ?? '' ];
			} else {
				$args['category'] = (array) $filters['category'];
			}
		}

		// Price range
		if ( ! empty( $filters['price_range'] ) ) {
			$parsed              = $this->parse_price_range( $filters['price_range'] );
			$filters['min_price'] = $parsed['min_price'];
			$filters['max_price'] = $parsed['max_price'];
		}

		if ( isset( $filters['min_price'] ) ) {
			$args['min_price'] = (float) $filters['min_price'];
		}

		if ( isset( $filters['max_price'] ) && $filters['max_price'] < PHP_FLOAT_MAX ) {
			$args['max_price'] = (float) $filters['max_price'];
		}

		// Tag filter
		if ( ! empty( $filters['tag_ids'] ) ) {
			$args['tag'] = array_map( 'intval', (array) $filters['tag_ids'] );
		}

		return apply_filters( 'flowsell_product_query_args', $args, $filters );
	}

	/**
	 * Format a WC_Product into a lightweight array for the API response.
	 *
	 * @param \WC_Product $product
	 * @return array
	 */
	private function format_product( \WC_Product $product ): array {
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );

		return [
			'id'           => $product->get_id(),
			'name'         => $product->get_name(),
			'price'        => $product->get_price(),
			'price_html'   => $product->get_price_html(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'   => $product->get_sale_price(),
			'on_sale'      => $product->is_on_sale(),
			'image'        => esc_url( $image_url ),
			'permalink'    => get_permalink( $product->get_id() ),
			'add_to_cart'  => esc_url( $product->add_to_cart_url() ),
			'in_stock'     => $product->is_in_stock(),
			'short_desc'   => wp_trim_words( wp_strip_all_tags( $product->get_short_description() ), 15 ),
			'rating'       => [
				'average' => $product->get_average_rating(),
				'count'   => $product->get_rating_count(),
			],
		];
	}
}
