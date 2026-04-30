<?php
/**
 * FlowSell AI — Admin Panel
 *
 * Provides the WordPress admin pages for:
 *  - Viewing conversation logs
 *  - Editing the active JSON flow
 *  - Plugin settings
 *
 * @package FlowSell_AI
 */

defined( 'ABSPATH' ) || exit;

class FlowSell_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// ─── Menu ──────────────────────────────────────────────────────────────────

	public function register_menu(): void {
		add_menu_page(
			__( 'FlowSell AI', 'flowsell-ai' ),
			__( 'FlowSell AI', 'flowsell-ai' ),
			'manage_options',
			'flowsell-ai',
			[ $this, 'render_dashboard' ],
			'dashicons-format-chat',
			56
		);

		add_submenu_page(
			'flowsell-ai',
			__( 'Conversation Logs', 'flowsell-ai' ),
			__( 'Logs', 'flowsell-ai' ),
			'manage_options',
			'flowsell-logs',
			[ $this, 'render_logs' ]
		);

		add_submenu_page(
			'flowsell-ai',
			__( 'Flow Editor', 'flowsell-ai' ),
			__( 'Flow Editor', 'flowsell-ai' ),
			'manage_options',
			'flowsell-flow-editor',
			[ $this, 'render_flow_editor' ]
		);

		add_submenu_page(
			'flowsell-ai',
			__( 'Settings', 'flowsell-ai' ),
			__( 'Settings', 'flowsell-ai' ),
			'manage_options',
			'flowsell-settings',
			[ $this, 'render_settings' ]
		);
	}

	// ─── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'flowsell' ) === false ) {
			return;
		}

		// Enqueue CodeMirror for JSON editing
		wp_enqueue_code_editor( [ 'type' => 'application/json' ] );
		wp_enqueue_script( 'wp-theme-plugin-editor' );
		wp_enqueue_style( 'wp-codemirror' );

		wp_add_inline_style( 'wp-codemirror', '
			.flowsell-admin-wrap { max-width: 1200px; }
			.flowsell-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 20px 0; }
			.flowsell-stat-card  { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; }
			.flowsell-stat-card .stat-number { font-size: 2rem; font-weight: 700; color: #25d366; }
			.flowsell-stat-card .stat-label  { color: #666; margin-top: 4px; }
			.flowsell-editor-wrap { border: 1px solid #ddd; border-radius: 6px; overflow: hidden; margin-top: 10px; }
			.flowsell-editor-wrap .CodeMirror { height: 500px; font-size: 13px; }
			.flowsell-table-wrap table { border-radius: 6px; overflow: hidden; }
		' );
	}

	// ─── Dashboard Page ────────────────────────────────────────────────────────

	public function render_dashboard(): void {
		$total      = FlowSell_Logger::get_sessions( 1, 1 )['total'];
		$purchases  = FlowSell_Logger::get_sessions( 1, 1, 'purchase' )['total'];
		$drop_offs  = FlowSell_Logger::get_sessions( 1, 1, 'drop_off' )['total'];
		$recommended = FlowSell_Logger::get_sessions( 1, 1, 'recommended' )['total'];
		$conversion = $total > 0 ? round( ( $purchases / $total ) * 100, 1 ) : 0;
		?>
		<div class="wrap flowsell-admin-wrap">
			<h1><?php esc_html_e( '🛍️ FlowSell AI — Dashboard', 'flowsell-ai' ); ?></h1>
			<p><?php esc_html_e( 'Conversational commerce overview.', 'flowsell-ai' ); ?></p>

			<div class="flowsell-stats-grid">
				<div class="flowsell-stat-card">
					<div class="stat-number"><?php echo esc_html( number_format( $total ) ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Total Sessions', 'flowsell-ai' ); ?></div>
				</div>
				<div class="flowsell-stat-card">
					<div class="stat-number" style="color:#4caf50"><?php echo esc_html( number_format( $purchases ) ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Purchases', 'flowsell-ai' ); ?></div>
				</div>
				<div class="flowsell-stat-card">
					<div class="stat-number" style="color:#ff9800"><?php echo esc_html( number_format( $recommended ) ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Recommended', 'flowsell-ai' ); ?></div>
				</div>
				<div class="flowsell-stat-card">
					<div class="stat-number" style="color:#f44336"><?php echo esc_html( number_format( $drop_offs ) ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Drop-offs', 'flowsell-ai' ); ?></div>
				</div>
				<div class="flowsell-stat-card">
					<div class="stat-number"><?php echo esc_html( $conversion ); ?>%</div>
					<div class="stat-label"><?php esc_html_e( 'Conversion Rate', 'flowsell-ai' ); ?></div>
				</div>
			</div>

			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=flowsell-logs' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'View Logs', 'flowsell-ai' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=flowsell-flow-editor' ) ); ?>" class="button">
					<?php esc_html_e( 'Edit Flow', 'flowsell-ai' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ─── Logs Page ─────────────────────────────────────────────────────────────

	public function render_logs(): void {
		$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$outcome = sanitize_text_field( $_GET['outcome'] ?? '' );
		$result  = FlowSell_Logger::get_sessions( 20, $page, $outcome );
		$items   = $result['items'];
		$total   = $result['total'];
		$pages   = (int) ceil( $total / 20 );

		$outcome_labels = [
			'in_progress'   => __( 'In Progress', 'flowsell-ai' ),
			'purchase'      => __( 'Purchase ✅', 'flowsell-ai' ),
			'drop_off'      => __( 'Drop-off ❌', 'flowsell-ai' ),
			'recommended'   => __( 'Recommended 💡', 'flowsell-ai' ),
			'lead_captured' => __( 'Lead Captured 📞', 'flowsell-ai' ),
		];
		?>
		<div class="wrap flowsell-admin-wrap">
			<h1><?php esc_html_e( 'Conversation Logs', 'flowsell-ai' ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="flowsell-logs">
				<select name="outcome">
					<option value=""><?php esc_html_e( 'All Outcomes', 'flowsell-ai' ); ?></option>
					<?php foreach ( $outcome_labels as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $outcome, $val ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'flowsell-ai' ), 'secondary', '', false ); ?>
			</form>

			<p><?php printf( esc_html__( 'Showing %d of %d sessions.', 'flowsell-ai' ), count( $items ), $total ); ?></p>

			<div class="flowsell-table-wrap">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Session ID', 'flowsell-ai' ); ?></th>
							<th><?php esc_html_e( 'Flow', 'flowsell-ai' ); ?></th>
							<th><?php esc_html_e( 'Outcome', 'flowsell-ai' ); ?></th>
							<th><?php esc_html_e( 'User', 'flowsell-ai' ); ?></th>
							<th><?php esc_html_e( 'Date', 'flowsell-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $items ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No sessions found.', 'flowsell-ai' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $items as $item ) : ?>
								<tr>
									<td><code><?php echo esc_html( substr( $item['session_id'], 0, 16 ) . '…' ); ?></code></td>
									<td><?php echo esc_html( $item['flow_name'] ); ?></td>
									<td><?php echo esc_html( $outcome_labels[ $item['outcome'] ] ?? $item['outcome'] ); ?></td>
									<td><?php echo $item['user_id'] ? esc_html( get_userdata( (int) $item['user_id'] )->display_name ?? $item['user_id'] ) : esc_html__( 'Guest', 'flowsell-ai' ); ?></td>
									<td><?php echo esc_html( wp_date( 'd M Y H:i', strtotime( $item['created_at'] ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php echo paginate_links( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => $pages,
						] ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ─── Flow Editor Page ──────────────────────────────────────────────────────

	public function render_flow_editor(): void {
		$saved_message = '';
		$error_message = '';

		if ( isset( $_GET['updated'] ) ) {
			$saved_message = __( '✅ Flow saved successfully.', 'flowsell-ai' );
		} elseif ( isset( $_GET['error'] ) ) {
			$error_message = urldecode( sanitize_text_field( $_GET['error'] ) );
		}

		$current_json = get_option( 'flowsell_active_flow', '' );
		if ( empty( $current_json ) ) {
			$default_path = FLOWSELL_DATA_DIR . 'default-flows.json';
			if ( file_exists( $default_path ) ) {
				$current_json = file_get_contents( $default_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
		?>
		<div class="wrap flowsell-admin-wrap">
			<h1><?php esc_html_e( 'Flow Editor', 'flowsell-ai' ); ?></h1>
			<p><?php esc_html_e( 'Edit the active JSON flow below. Must be valid JSON with required flow_name and steps fields.', 'flowsell-ai' ); ?></p>

			<?php if ( $saved_message ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $saved_message ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error_message ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error_message ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'flowsell_save_flow', 'flowsell_flow_nonce' ); ?>
				<input type="hidden" name="action" value="flowsell_save_flow">

				<div class="flowsell-editor-wrap">
					<textarea id="flowsell-flow-json" name="flowsell_flow_json" rows="30" style="width:100%;font-family:monospace"><?php echo esc_textarea( $current_json ); ?></textarea>
				</div>

				<p class="submit">
					<?php submit_button( __( 'Save Flow', 'flowsell-ai' ), 'primary', '', false ); ?>
					<?php $reset_url = wp_nonce_url( admin_url( 'admin.php?page=flowsell-flow-editor&reset=1' ), 'flowsell_reset_flow' ); ?>
					<a href="<?php echo esc_url( $reset_url ); ?>" class="button" onclick="return confirm('<?php esc_attr_e( 'Reset to default flow?', 'flowsell-ai' ); ?>')">
						<?php esc_html_e( 'Reset to Default', 'flowsell-ai' ); ?>
					</a>
				</p>
			</form>

			<script>
			jQuery(function($) {
				if (typeof wp !== 'undefined' && wp.codeEditor) {
					wp.codeEditor.initialize(document.getElementById('flowsell-flow-json'), {
						codemirror: { mode: 'application/json', lineNumbers: true, lineWrapping: true }
					});
				}
			});
			</script>
		</div>
		<?php
	}

	// ─── Settings Page ─────────────────────────────────────────────────────────

	public function render_settings(): void {
		$settings = get_option( 'flowsell_settings', [] );
		?>
		<div class="wrap flowsell-admin-wrap">
			<h1><?php esc_html_e( 'FlowSell AI Settings', 'flowsell-ai' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( '✅ Settings saved.', 'flowsell-ai' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'flowsell_save_settings', 'flowsell_settings_nonce' ); ?>
				<input type="hidden" name="action" value="flowsell_save_settings">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fs_enabled"><?php esc_html_e( 'Enable Widget', 'flowsell-ai' ); ?></label></th>
						<td><input type="checkbox" id="fs_enabled" name="flowsell_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>></td>
					</tr>
					<tr>
						<th scope="row"><label for="fs_label"><?php esc_html_e( 'Widget Button Label', 'flowsell-ai' ); ?></label></th>
						<td><input type="text" id="fs_label" name="flowsell_widget_label" class="regular-text" value="<?php echo esc_attr( $settings['widget_label'] ?? 'Find Your Perfect Product' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="fs_color"><?php esc_html_e( 'Primary Color', 'flowsell-ai' ); ?></label></th>
						<td><input type="color" id="fs_color" name="flowsell_primary_color" value="<?php echo esc_attr( $settings['primary_color'] ?? '#25d366' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="fs_position"><?php esc_html_e( 'Widget Position', 'flowsell-ai' ); ?></label></th>
						<td>
							<select id="fs_position" name="flowsell_position">
								<?php foreach ( [ 'bottom-right' => __( 'Bottom Right', 'flowsell-ai' ), 'bottom-left' => __( 'Bottom Left', 'flowsell-ai' ) ] as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['position'] ?? 'bottom-right', $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fs_whatsapp"><?php esc_html_e( 'WhatsApp Number', 'flowsell-ai' ); ?></label></th>
						<td>
							<input type="text" id="fs_whatsapp" name="flowsell_whatsapp" class="regular-text" value="<?php echo esc_attr( $settings['whatsapp'] ?? '' ); ?>" placeholder="+2348000000000">
							<p class="description"><?php esc_html_e( 'Number for Live Agent fallback (include country code, no spaces or +).', 'flowsell-ai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fs_lead_fields"><?php esc_html_e( 'Lead Capture Fields', 'flowsell-ai' ); ?></label></th>
						<td>
							<input type="text" id="fs_lead_fields" name="flowsell_lead_fields" class="regular-text" value="<?php echo esc_attr( $settings['lead_fields'] ?? '' ); ?>" placeholder="Name, Email, Phone">
							<p class="description"><?php esc_html_e( 'Comma-separated list of info to ask if user does not buy.', 'flowsell-ai' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'flowsell-ai' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ─── Form Submissions ──────────────────────────────────────────────────────

	public function handle_form_submissions(): void {
		add_action( 'admin_post_flowsell_save_flow',     [ $this, 'handle_save_flow' ] );
		add_action( 'admin_post_flowsell_save_settings', [ $this, 'handle_save_settings' ] );

		// Handle reset
		if ( isset( $_GET['page'], $_GET['reset'] ) && $_GET['page'] === 'flowsell-flow-editor' && current_user_can( 'manage_options' ) ) {
			check_admin_referer( 'flowsell_reset_flow' );
			$default_path = FLOWSELL_DATA_DIR . 'default-flows.json';
			if ( file_exists( $default_path ) ) {
				update_option( 'flowsell_active_flow', file_get_contents( $default_path ), false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			wp_safe_redirect( admin_url( 'admin.php?page=flowsell-flow-editor&updated=1' ) );
			exit;
		}
	}

	/**
	 * Save the JSON flow.
	 */
	public function handle_save_flow(): void {
		check_admin_referer( 'flowsell_save_flow', 'flowsell_flow_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'flowsell-ai' ) );
		}

		$json   = wp_unslash( $_POST['flowsell_flow_json'] ?? '' );
		$engine = new FlowSell_Flow_Engine();
		$result = $engine->save_flow( $json );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=flowsell-flow-editor&error=' . rawurlencode( $result->get_error_message() ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=flowsell-flow-editor&updated=1' ) );
		exit;
	}

	/**
	 * Save plugin settings.
	 */
	public function handle_save_settings(): void {
		check_admin_referer( 'flowsell_save_settings', 'flowsell_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'flowsell-ai' ) );
		}

		$settings = [
			'enabled'       => ! empty( $_POST['flowsell_enabled'] ),
			'widget_label'  => sanitize_text_field( $_POST['flowsell_widget_label'] ?? 'Find Your Perfect Product' ),
			'primary_color' => sanitize_hex_color( $_POST['flowsell_primary_color'] ?? '#25d366' ),
			'position'      => in_array( $_POST['flowsell_position'] ?? '', [ 'bottom-right', 'bottom-left' ], true )
				? $_POST['flowsell_position']
				: 'bottom-right',
			'whatsapp'      => sanitize_text_field( $_POST['flowsell_whatsapp'] ?? '' ),
			'lead_fields'   => sanitize_text_field( $_POST['flowsell_lead_fields'] ?? '' ),
		];

		update_option( 'flowsell_settings', $settings );

		wp_safe_redirect( admin_url( 'admin.php?page=flowsell-settings&updated=1' ) );
		exit;
	}
}
