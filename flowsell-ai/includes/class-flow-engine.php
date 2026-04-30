<?php
/**
 * FlowSell AI — Flow Engine
 *
 * Loads, validates, and traverses the JSON decision-tree flow.
 *
 * @package FlowSell_AI
 */

defined( 'ABSPATH' ) || exit;

class FlowSell_Flow_Engine {

	/**
	 * Parsed flow data (array).
	 *
	 * @var array|null
	 */
	private ?array $flow = null;

	/**
	 * Constructor — load the active flow from options.
	 */
	public function __construct() {
		$this->load_active_flow();
	}

	// ─── Public API ────────────────────────────────────────────────────────────

	/**
	 * Return the full flow array.
	 */
	public function get_flow(): array {
		return $this->flow ?? [];
	}

	/**
	 * Return the flow name.
	 */
	public function get_flow_name(): string {
		return $this->flow['flow_name'] ?? 'default_flow';
	}

	/**
	 * Return all steps.
	 *
	 * @return array
	 */
	public function get_steps(): array {
		return $this->flow['steps'] ?? [];
	}

	/**
	 * Return a single step by its ID.
	 *
	 * @param string $step_id
	 * @return array|null
	 */
	public function get_step( string $step_id ): ?array {
		foreach ( $this->get_steps() as $step ) {
			if ( isset( $step['id'] ) && $step['id'] === $step_id ) {
				return $step;
			}
		}
		return null;
	}

	/**
	 * Get the first step of the flow.
	 *
	 * @return array|null
	 */
	public function get_first_step(): ?array {
		$steps = $this->get_steps();
		return ! empty( $steps ) ? $steps[0] : null;
	}

	/**
	 * Given a step and a chosen option, resolve the next step ID.
	 * Returns null if this is a terminal step (end of flow).
	 *
	 * @param array  $step
	 * @param string $chosen_option
	 * @return string|null
	 */
	public function resolve_next_step_id( array $step, string $chosen_option ): ?string {
		// Explicit routing map
		if ( isset( $step['next'] ) && is_array( $step['next'] ) ) {
			return $step['next'][ $chosen_option ] ?? null;
		}

		// Default: advance to the following step in the array
		$steps = $this->get_steps();
		foreach ( $steps as $index => $s ) {
			if ( $s['id'] === $step['id'] ) {
				return $steps[ $index + 1 ]['id'] ?? null;
			}
		}
		return null;
	}

	/**
	 * Check whether the given step is the last step in the flow.
	 *
	 * @param string $step_id
	 * @return bool
	 */
	public function is_terminal_step( string $step_id ): bool {
		$steps = $this->get_steps();
		if ( empty( $steps ) ) {
			return false;
		}
		$last = end( $steps );
		return $last['id'] === $step_id;
	}

	/**
	 * Given a step ID and current cumulative answers, return only the options
	 * that result in at least 1 valid WooCommerce product.
	 *
	 * @param string $step_id
	 * @param array  $answers
	 * @return array Array of valid option labels.
	 */
	public function get_valid_options( string $step_id, array $answers ): array {
		$step = $this->get_step( $step_id );
		if ( ! $step ) {
			return [];
		}

		// Handle dynamic steps
		if ( isset( $step['type'] ) && strpos( $step['type'], 'dynamic_' ) === 0 ) {
			return $this->generate_dynamic_options( $step, $answers );
		}

		if ( empty( $step['options'] ) ) {
			return [];
		}

		$valid_options = [];
		$base_filters  = $this->build_filters_from_answers( $answers );
		$commerce      = new FlowSell_Commerce_Engine();

		foreach ( $step['options'] as $option ) {
			$option_filters = $this->extract_product_filters( $step, $option );
			$combined       = array_merge( $base_filters, $option_filters );

			// If there are no filters at all, it's globally valid
			if ( empty( $combined ) ) {
				$valid_options[] = $option;
				continue;
			}

			// Otherwise, check product existence
			if ( $commerce->has_products( $combined ) ) {
				$valid_options[] = $option;
			}
		}

		return $valid_options;
	}

	/**
	 * Generate options dynamically for special step types.
	 *
	 * @param array $step
	 * @param array $answers
	 * @return array
	 */
	private function generate_dynamic_options( array $step, array $answers ): array {
		$options = [];

		if ( $step['type'] === 'dynamic_delivery' ) {
			if ( class_exists( 'WC_Shipping_Zones' ) ) {
				$zones = \WC_Shipping_Zones::get_zones();
				foreach ( $zones as $zone ) {
					$options[] = $zone['zone_name'];
				}
				// Always add Rest of the World fallback if no zones exist
				if ( empty( $options ) ) {
					$options = [ 'Lagos - Island', 'Lagos - Mainland', 'Outside Lagos' ];
				} else {
					$options[] = 'Everywhere Else';
				}
			}
		} elseif ( $step['type'] === 'dynamic_pricing' ) {
			$commerce     = new FlowSell_Commerce_Engine();
			$base_filters = $this->build_filters_from_answers( $answers );
			
			// Get min/max prices of matching products
			$prices = $commerce->get_price_range( $base_filters );
			if ( ! $prices || $prices['min'] === null ) {
				return []; // No products match, handled gracefully by frontend
			}

			$min = ceil( $prices['min'] );
			$max = ceil( $prices['max'] );

			// Mathematical bucketing strategy
			if ( $min == $max ) {
				$options[] = 'Exactly ₦' . number_format( $min );
			} else {
				$diff = $max - $min;
				if ( $diff <= 5000 ) {
					// Very tight range
					$options[] = 'Under ₦' . number_format( $min + ( $diff / 2 ) );
					$options[] = 'Above ₦' . number_format( $min + ( $diff / 2 ) );
				} else {
					// Split into 3 buckets
					$step_size = ceil( $diff / 3 / 1000 ) * 1000; // Round to nearest 1000
					$b1_max = $min + $step_size;
					$b2_max = $min + ( $step_size * 2 );

					$options[] = 'Under ₦' . number_format( $b1_max );
					$options[] = '₦' . number_format( $b1_max ) . ' – ₦' . number_format( $b2_max );
					$options[] = '₦' . number_format( $b2_max ) . '+';
				}
			}
		}

		return $options;
	}

	/**
	 * Extract product filter hints from a step's answers.
	 * Looks for optional 'product_filters' key in step definition.
	 *
	 * @param array  $step
	 * @param string $answer
	 * @return array Associative array of filters for FlowSell_Commerce_Engine.
	 */
	public function extract_product_filters( array $step, string $answer ): array {
		$filters = [];

		if ( isset( $step['type'] ) && $step['type'] === 'dynamic_pricing' ) {
			// Parse mathematical buckets like "Under ₦20,000" or "₦20,000 – ₦40,000" or "₦40,000+"
			$clean = preg_replace( '/[^0-9–]/', '', $answer ); // Keep numbers and en-dash
			if ( strpos( $answer, 'Exactly' ) !== false ) {
				$val = preg_replace( '/[^0-9]/', '', $answer );
				$filters['price_range'] = $val . '-' . $val;
			} elseif ( strpos( $answer, 'Under' ) !== false ) {
				$val = preg_replace( '/[^0-9]/', '', $answer );
				$filters['price_range'] = '<' . $val;
			} elseif ( strpos( $answer, '+' ) !== false || strpos( $answer, 'Above' ) !== false ) {
				$val = preg_replace( '/[^0-9]/', '', $answer );
				$filters['price_range'] = $val . '+';
			} elseif ( strpos( $clean, '–' ) !== false ) {
				$parts = explode( '–', $clean );
				$filters['price_range'] = $parts[0] . '-' . $parts[1];
			}
		}

		// Static filter map defined per option
		if ( isset( $step['product_filters'][ $answer ] ) ) {
			$filters = array_merge( $filters, (array) $step['product_filters'][ $answer ] );
		}

		// Global filters on step (apply regardless of answer)
		if ( isset( $step['global_filters'] ) && is_array( $step['global_filters'] ) ) {
			$filters = array_merge( $step['global_filters'], $filters );
		}

		return $filters;
	}

	/**
	 * Build cumulative filters from an array of step_id => answer pairs.
	 *
	 * @param array $answers [ step_id => answer ]
	 * @return array
	 */
	public function build_filters_from_answers( array $answers ): array {
		$merged = [];

		foreach ( $answers as $step_id => $answer ) {
			$step = $this->get_step( $step_id );
			if ( $step ) {
				$filters = $this->extract_product_filters( $step, $answer );
				$merged  = array_merge( $merged, $filters );
			}
		}

		return $merged;
	}

	/**
	 * Save a JSON flow string to the options table after validation.
	 *
	 * @param string $json Raw JSON string.
	 * @return true|\WP_Error
	 */
	public function save_flow( string $json ) {
		$decoded = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'invalid_json', __( 'Invalid JSON: ', 'flowsell-ai' ) . json_last_error_msg() );
		}

		$error = $this->validate_flow_structure( $decoded );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		update_option( 'flowsell_active_flow', $json, false );
		$this->flow = $decoded;

		return true;
	}

	// ─── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Load the active flow from the options table.
	 */
	private function load_active_flow(): void {
		$json = get_option( 'flowsell_active_flow', '' );

		if ( empty( $json ) ) {
			return;
		}

		$decoded = json_decode( $json, true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			$this->flow = $decoded;
		}
	}

	/**
	 * Validate the basic structure of a flow array.
	 *
	 * @param array $flow
	 * @return true|\WP_Error
	 */
	private function validate_flow_structure( array $flow ) {
		if ( empty( $flow['flow_name'] ) ) {
			return new \WP_Error( 'missing_flow_name', __( 'Flow must have a "flow_name" field.', 'flowsell-ai' ) );
		}

		if ( empty( $flow['steps'] ) || ! is_array( $flow['steps'] ) ) {
			return new \WP_Error( 'missing_steps', __( 'Flow must have a "steps" array with at least one step.', 'flowsell-ai' ) );
		}

		foreach ( $flow['steps'] as $index => $step ) {
			if ( empty( $step['id'] ) ) {
				return new \WP_Error(
					'missing_step_id',
					/* translators: %d: step index */
					sprintf( __( 'Step at index %d is missing an "id" field.', 'flowsell-ai' ), $index )
				);
			}

			if ( empty( $step['question'] ) ) {
				return new \WP_Error(
					'missing_step_question',
					/* translators: %s: step id */
					sprintf( __( 'Step "%s" is missing a "question" field.', 'flowsell-ai' ), $step['id'] )
				);
			}

			if ( empty( $step['options'] ) || ! is_array( $step['options'] ) ) {
				return new \WP_Error(
					'missing_step_options',
					/* translators: %s: step id */
					sprintf( __( 'Step "%s" must have an "options" array.', 'flowsell-ai' ), $step['id'] )
				);
			}
		}

		return true;
	}
}
