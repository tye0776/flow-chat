<?php
/**
 * FlowSell AI — REST API
 *
 * Registers all /flowsell/v1/* endpoints.
 *
 * @package FlowSell_AI
 */

defined( 'ABSPATH' ) || exit;

class FlowSell_REST_API {

	/** REST namespace. */
	const NS = 'flowsell/v1';

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {
		// GET /flowsell/v1/get-flow
		register_rest_route( self::NS, '/get-flow', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_flow' ],
			'permission_callback' => '__return_true',
		] );

		// POST /flowsell/v1/log-session
		register_rest_route( self::NS, '/log-session', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'log_session' ],
			'permission_callback' => [ $this, 'verify_nonce' ],
			'args'                => $this->log_session_args(),
		] );

		// POST /flowsell/v1/get-products
		register_rest_route( self::NS, '/get-products', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'get_products' ],
			'permission_callback' => [ $this, 'verify_nonce' ],
			'args'                => $this->get_products_args(),
		] );

		// POST /flowsell/v1/check-options
		register_rest_route( self::NS, '/check-options', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'check_options' ],
			'permission_callback' => [ $this, 'verify_nonce' ],
			'args'                => $this->check_options_args(),
		] );
	}

	// ─── Endpoint: get-flow ────────────────────────────────────────────────────

	/**
	 * Return the active flow JSON.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_flow( WP_REST_Request $request ): WP_REST_Response {
		$engine = new FlowSell_Flow_Engine();
		$flow   = $engine->get_flow();

		if ( empty( $flow ) ) {
			return rest_ensure_response( new WP_Error( 'no_flow', __( 'No active flow configured.', 'flowsell-ai' ), [ 'status' => 404 ] ) );
		}

		return rest_ensure_response( [
			'success' => true,
			'flow'    => $flow,
		] );
	}

	// ─── Endpoint: log-session ─────────────────────────────────────────────────

	/**
	 * Store or update a conversation session log.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function log_session( WP_REST_Request $request ): WP_REST_Response {
		$data = [
			'session_id'   => $request->get_param( 'session_id' ),
			'flow_name'    => $request->get_param( 'flow_name' ),
			'step_history' => $request->get_param( 'step_history' ) ?? [],
			'user_answers' => $request->get_param( 'user_answers' ) ?? [],
			'outcome'      => $request->get_param( 'outcome' ) ?? 'in_progress',
		];

		$saved = FlowSell_Logger::log_session( $data );

		if ( ! $saved ) {
			return rest_ensure_response( new WP_Error( 'log_failed', __( 'Failed to save session.', 'flowsell-ai' ), [ 'status' => 500 ] ) );
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	// ─── Endpoint: get-products ────────────────────────────────────────────────

	/**
	 * Return product recommendations based on conversation filters.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_products( WP_REST_Request $request ): WP_REST_Response {
		$filters = [];

		// Build filters from explicit params
		if ( $request->get_param( 'answers' ) ) {
			$engine  = new FlowSell_Flow_Engine();
			$answers = (array) $request->get_param( 'answers' );
			$filters = $engine->build_filters_from_answers( $answers );
		}

		// Allow direct filter overrides
		foreach ( [ 'category', 'min_price', 'max_price', 'price_range', 'in_stock', 'tag_ids' ] as $key ) {
			$val = $request->get_param( $key );
			if ( ! is_null( $val ) ) {
				$filters[ $key ] = $val;
			}
		}

		$commerce  = new FlowSell_Commerce_Engine();
		$products  = $commerce->get_recommendations( $filters );

		return rest_ensure_response( [
			'success'  => true,
			'products' => $products,
			'count'    => count( $products ),
		] );
	}

	// ─── Endpoint: check-options ───────────────────────────────────────────────

	/**
	 * Validate options for a specific step based on current answers.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function check_options( WP_REST_Request $request ): WP_REST_Response {
		$step_id = $request->get_param( 'step_id' );
		$answers = (array) $request->get_param( 'answers' );

		$engine        = new FlowSell_Flow_Engine();
		$valid_options = $engine->get_valid_options( $step_id, $answers );

		return rest_ensure_response( [
			'success'       => true,
			'valid_options' => $valid_options,
		] );
	}

	// ─── Permission Callbacks ──────────────────────────────────────────────────

	/**
	 * Verify WP REST nonce (set by wp_localize_script as X-WP-Nonce header or _wpnonce param).
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function verify_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid or missing nonce.', 'flowsell-ai' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// ─── Argument Schemas ──────────────────────────────────────────────────────

	/**
	 * REST args for /log-session.
	 */
	private function log_session_args(): array {
		return [
			'session_id'   => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => fn( $v ) => is_string( $v ) && strlen( $v ) > 0,
			],
			'flow_name'    => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'step_history' => [
				'required'          => false,
				'type'              => 'array',
				'sanitize_callback' => fn( $v ) => array_map( 'sanitize_text_field', (array) $v ),
			],
			'user_answers' => [
				'required'          => false,
				'type'              => 'object',
				'sanitize_callback' => [ $this, 'deep_sanitize_text_field' ],
			],
			'outcome'      => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'enum'              => [ 'in_progress', 'purchase', 'drop_off', 'recommended', 'lead_captured' ],
			],
		];
	}

	/**
	 * REST args for /get-products.
	 */
	private function get_products_args(): array {
		return [
			'answers'     => [
				'required'          => false,
				'type'              => 'object',
				'sanitize_callback' => [ $this, 'deep_sanitize_text_field' ],
			],
			'category'    => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'min_price'   => [
				'required'          => false,
				'type'              => 'number',
				'sanitize_callback' => fn( $v ) => abs( (float) $v ),
			],
			'max_price'   => [
				'required'          => false,
				'type'              => 'number',
				'sanitize_callback' => fn( $v ) => abs( (float) $v ),
			],
			'price_range' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'in_stock'    => [
				'required' => false,
				'type'     => 'boolean',
			],
			'tag_ids'     => [
				'required' => false,
				'type'     => 'array',
			],
		];
	}

	/**
	 * REST args for /check-options.
	 */
	private function check_options_args(): array {
		return [
			'step_id' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'answers' => [
				'required'          => false,
				'type'              => 'object',
				'sanitize_callback' => [ $this, 'deep_sanitize_text_field' ],
			],
		];
	}

	/**
	 * Recursively sanitize an array/object of strings.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	public function deep_sanitize_text_field( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			$sanitized = is_array( $data ) ? [] : new stdClass();
			foreach ( $data as $key => $value ) {
				$safe_key = sanitize_text_field( $key );
				if ( is_array( $data ) ) {
					$sanitized[ $safe_key ] = $this->deep_sanitize_text_field( $value );
				} else {
					$sanitized->$safe_key = $this->deep_sanitize_text_field( $value );
				}
			}
			return $sanitized;
		}
		return sanitize_text_field( $data );
	}
}
