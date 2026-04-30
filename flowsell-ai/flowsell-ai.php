<?php
/**
 * Plugin Name:       FlowSell AI MVP
 * Plugin URI:        https://flowsell.ai
 * Description:       Conversational commerce plugin for WooCommerce — guides customers through structured chat flows and converts them into purchases.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            FlowSell AI
 * Author URI:        https://flowsell.ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flowsell-ai
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'FLOWSELL_VERSION',     '1.0.0' );
define( 'FLOWSELL_PLUGIN_FILE', __FILE__ );
define( 'FLOWSELL_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'FLOWSELL_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'FLOWSELL_DATA_DIR',    FLOWSELL_PLUGIN_DIR . 'data/' );

/**
 * Declare WooCommerce HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

/**
 * Activation hook — create DB table + seed default flow.
 */
function flowsell_activate(): void {
	require_once FLOWSELL_PLUGIN_DIR . 'includes/class-logger.php';
	FlowSell_Logger::create_table();

	// Seed default flow if none exists
	if ( ! get_option( 'flowsell_active_flow' ) ) {
		$default_flow_path = FLOWSELL_DATA_DIR . 'default-flows.json';
		if ( file_exists( $default_flow_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = file_get_contents( $default_flow_path );
			update_option( 'flowsell_active_flow', $json, false );
		}
	}

	// Default settings
	if ( ! get_option( 'flowsell_settings' ) ) {
		update_option( 'flowsell_settings', [
			'enabled'       => true,
			'widget_label'  => 'Find Your Perfect Product',
			'primary_color' => '#25d366',
			'position'      => 'bottom-right',
		], false );
	}

	// Schedule GDPR data retention cron
	if ( ! wp_next_scheduled( 'flowsell_purge_sessions_cron' ) ) {
		wp_schedule_event( time(), 'daily', 'flowsell_purge_sessions_cron' );
	}
}
register_activation_hook( __FILE__, 'flowsell_activate' );

/**
 * Deactivation hook.
 */
function flowsell_deactivate(): void {
	// Clear cron
	wp_clear_scheduled_hook( 'flowsell_purge_sessions_cron' );
}
register_deactivation_hook( __FILE__, 'flowsell_deactivate' );

/**
 * Boot the plugin after all plugins are loaded (ensures WooCommerce is present).
 */
add_action( 'plugins_loaded', 'flowsell_boot', 20 );

function flowsell_boot(): void {
	// WooCommerce check
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>FlowSell AI</strong> requires WooCommerce to be installed and active.</p></div>';
		} );
		return;
	}

	// Load text domain
	load_plugin_textdomain( 'flowsell-ai', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Load core classes
	require_once FLOWSELL_PLUGIN_DIR . 'includes/class-logger.php';
	require_once FLOWSELL_PLUGIN_DIR . 'includes/class-flow-engine.php';
	require_once FLOWSELL_PLUGIN_DIR . 'includes/class-commerce-engine.php';
	require_once FLOWSELL_PLUGIN_DIR . 'includes/class-rest-api.php';
	require_once FLOWSELL_PLUGIN_DIR . 'admin/class-admin.php';

	// Init REST API
	add_action( 'rest_api_init', [ new FlowSell_REST_API(), 'register_routes' ] );

	// Init admin
	if ( is_admin() ) {
		new FlowSell_Admin();
	}

	// Enqueue frontend assets
	add_action( 'wp_enqueue_scripts', 'flowsell_enqueue_frontend' );

	// Render chat widget
	add_action( 'wp_footer', 'flowsell_render_widget' );

	// Setup Cron
	add_action( 'flowsell_purge_sessions_cron', function() {
		FlowSell_Logger::purge_old_sessions( 90 );
	} );

	// Privacy Policy
	add_action( 'admin_init', 'flowsell_add_privacy_policy_content' );
}

/**
 * Add privacy policy content for GDPR compliance.
 */
function flowsell_add_privacy_policy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content = sprintf(
		'<p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul>',
		__( 'This site uses FlowSell AI to provide a guided chat experience. When you interact with the chat widget:', 'flowsell-ai' ),
		__( 'A unique session ID is temporarily generated to remember your choices during the conversation.', 'flowsell-ai' ),
		__( 'Your answers and choices are stored securely in our database to provide product recommendations.', 'flowsell-ai' ),
		__( 'Your IP address is stored as a cryptographically salted hash for analytics without identifying you personally.', 'flowsell-ai' )
	);

	wp_add_privacy_policy_content(
		'FlowSell AI',
		wp_kses_post( wpautop( $content, false ) )
	);
}

/**
 * Enqueue frontend scripts and styles.
 */
function flowsell_enqueue_frontend(): void {
	$settings = get_option( 'flowsell_settings', [] );

	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	wp_enqueue_style(
		'flowsell-chat',
		FLOWSELL_PLUGIN_URL . 'assets/chat.css',
		[],
		FLOWSELL_VERSION
	);

	wp_enqueue_script(
		'flowsell-chat',
		FLOWSELL_PLUGIN_URL . 'assets/chat.js',
		[ 'jquery' ],
		FLOWSELL_VERSION,
		[ 'in_footer' => true, 'strategy'  => 'defer' ]
	);

	$lead_fields_str = $settings['lead_fields'] ?? '';
	$lead_fields     = array_filter( array_map( 'trim', explode( ',', $lead_fields_str ) ) );

	wp_localize_script( 'flowsell-chat', 'flowsellConfig', [
		'apiBase'      => esc_url_raw( rest_url( 'flowsell/v1' ) ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'cartUrl'      => wc_get_cart_url(),
		'primaryColor' => sanitize_hex_color( $settings['primary_color'] ?? '#25d366' ),
		'widgetLabel'  => esc_js( $settings['widget_label'] ?? 'Find Your Perfect Product' ),
		'position'     => sanitize_text_field( $settings['position'] ?? 'bottom-right' ),
		'whatsapp'     => sanitize_text_field( $settings['whatsapp'] ?? '' ),
		'leadFields'   => array_values( $lead_fields ),
	] );
}

/**
 * Output the chat widget HTML shell in the footer.
 */
function flowsell_render_widget(): void {
	$settings = get_option( 'flowsell_settings', [] );
	if ( empty( $settings['enabled'] ) ) {
		return;
	}
	?>
	<div id="flowsell-widget" class="flowsell-widget flowsell-<?php echo esc_attr( $settings['position'] ?? 'bottom-right' ); ?>" role="complementary" aria-label="<?php esc_attr_e( 'Product advisor chat', 'flowsell-ai' ); ?>">
		<!-- Launcher button -->
		<button id="flowsell-launcher" class="flowsell-launcher" aria-expanded="false" aria-controls="flowsell-chat-window">
			<span class="flowsell-launcher-icon flowsell-icon-chat" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="26" height="26">
					<path d="M20 2H4C2.9 2 2 2.9 2 4v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
				</svg>
			</span>
			<span class="flowsell-launcher-icon flowsell-icon-close" aria-hidden="true" style="display:none">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="26" height="26">
					<path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
				</svg>
			</span>
			<span class="flowsell-launcher-label"><?php echo esc_html( $settings['widget_label'] ?? 'Find Your Perfect Product' ); ?></span>
		</button>

		<!-- Chat window -->
		<div id="flowsell-chat-window" class="flowsell-chat-window" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Product advisor', 'flowsell-ai' ); ?>" style="display:none">
			<div class="flowsell-chat-header">
				<div class="flowsell-header-avatar" aria-hidden="true">🛍️</div>
				<div class="flowsell-header-info">
					<span class="flowsell-header-name"><?php esc_html_e( 'Product Advisor', 'flowsell-ai' ); ?></span>
					<span class="flowsell-header-status"><?php esc_html_e( 'Online · Usually replies instantly', 'flowsell-ai' ); ?></span>
				</div>
				<button class="flowsell-close-btn" aria-label="<?php esc_attr_e( 'Close chat', 'flowsell-ai' ); ?>">✕</button>
			</div>

			<div id="flowsell-messages" class="flowsell-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Chat messages', 'flowsell-ai' ); ?>"></div>

			<div id="flowsell-options" class="flowsell-options" role="group" aria-label="<?php esc_attr_e( 'Response options', 'flowsell-ai' ); ?>"></div>

			<div id="flowsell-products" class="flowsell-products" style="display:none"></div>

			<div class="flowsell-chat-footer">
				<span class="flowsell-powered-by"><?php esc_html_e( 'Powered by FlowSell AI', 'flowsell-ai' ); ?></span>
			</div>
		</div>
	</div>
	<?php
}
