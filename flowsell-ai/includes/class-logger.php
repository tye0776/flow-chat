<?php
/**
 * FlowSell AI — Logger
 *
 * Creates the wp_flowsell_logs table and provides read/write helpers.
 *
 * @package FlowSell_AI
 */

defined( 'ABSPATH' ) || exit;

class FlowSell_Logger {

	/** Table name (without prefix). */
	const TABLE_SLUG = 'flowsell_logs';

	// ─── Table Management ──────────────────────────────────────────────────────

	/**
	 * Create the logs table on activation.
	 * Safe to call multiple times (uses IF NOT EXISTS).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE_SLUG;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id   VARCHAR(64)     NOT NULL,
			flow_name    VARCHAR(100)    NOT NULL DEFAULT '',
			step_history LONGTEXT        NOT NULL DEFAULT '[]',
			user_answers LONGTEXT        NOT NULL DEFAULT '{}',
			outcome      VARCHAR(30)     NOT NULL DEFAULT 'in_progress',
			ip_address   VARCHAR(64)     NOT NULL DEFAULT '',
			user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY session_id (session_id),
			KEY flow_name  (flow_name),
			KEY outcome    (outcome),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'flowsell_db_version', '1.0.0' );
	}

	// ─── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Create a new session log row.
	 *
	 * @param string $session_id  Unique session identifier.
	 * @param string $flow_name
	 * @return int|false  Insert ID or false on failure.
	 */
	public static function create_session( string $session_id, string $flow_name ) {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE_SLUG;
		$result = $wpdb->insert(
			$table,
			[
				'session_id'   => substr( $session_id, 0, 64 ),
				'flow_name'    => substr( $flow_name, 0, 100 ),
				'step_history' => '[]',
				'user_answers' => '{}',
				'outcome'      => 'in_progress',
				'ip_address'   => self::get_safe_ip(),
				'user_id'      => get_current_user_id(),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing session log with new step + answer data.
	 *
	 * @param string $session_id
	 * @param array  $step_history  Full ordered list of step IDs visited.
	 * @param array  $user_answers  Map of step_id => chosen answer.
	 * @param string $outcome       'in_progress' | 'purchase' | 'drop_off' | 'recommended'.
	 * @return bool
	 */
	public static function update_session(
		string $session_id,
		array  $step_history,
		array  $user_answers,
		string $outcome = 'in_progress'
	): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SLUG;

		$allowed_outcomes = [ 'in_progress', 'purchase', 'drop_off', 'recommended' ];
		if ( ! in_array( $outcome, $allowed_outcomes, true ) ) {
			$outcome = 'in_progress';
		}

		$result = $wpdb->update(
			$table,
			[
				'step_history' => wp_json_encode( $step_history ),
				'user_answers' => wp_json_encode( $user_answers ),
				'outcome'      => $outcome,
			],
			[ 'session_id' => $session_id ],
			[ '%s', '%s', '%s' ],
			[ '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Log a complete session payload in one call (atomic upsert).
	 *
	 * @param array $data {
	 *   @type string $session_id
	 *   @type string $flow_name
	 *   @type array  $step_history
	 *   @type array  $user_answers
	 *   @type string $outcome
	 * }
	 * @return bool
	 */
	public static function log_session( array $data ): bool {
		global $wpdb;

		$session_id = sanitize_text_field( $data['session_id'] ?? '' );
		if ( empty( $session_id ) ) {
			return false;
		}

		$table        = $wpdb->prefix . self::TABLE_SLUG;
		$flow_name    = sanitize_text_field( $data['flow_name'] ?? 'unknown' );
		$step_history = wp_json_encode( is_array( $data['step_history'] ?? null ) ? $data['step_history'] : [] );
		$user_answers = wp_json_encode( is_array( $data['user_answers'] ?? null ) ? $data['user_answers'] : [] );
		$outcome      = sanitize_text_field( $data['outcome'] ?? 'in_progress' );

		$allowed_outcomes = [ 'in_progress', 'purchase', 'drop_off', 'recommended' ];
		if ( ! in_array( $outcome, $allowed_outcomes, true ) ) {
			$outcome = 'in_progress';
		}

		$ip_address = self::get_safe_ip();
		$user_id    = get_current_user_id();

		$sql = "INSERT INTO {$table} 
			(session_id, flow_name, step_history, user_answers, outcome, ip_address, user_id) 
			VALUES (%s, %s, %s, %s, %s, %s, %d) 
			ON DUPLICATE KEY UPDATE 
			flow_name = VALUES(flow_name),
			step_history = VALUES(step_history),
			user_answers = VALUES(user_answers),
			outcome = VALUES(outcome)";

		$prepared = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql,
			$session_id,
			$flow_name,
			$step_history,
			$user_answers,
			$outcome,
			$ip_address,
			$user_id
		);

		$result = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $result !== false;
	}

	// ─── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Get a session by session_id.
	 *
	 * @param string $session_id
	 * @return array|null
	 */
	public static function get_session( string $session_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SLUG;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s LIMIT 1", $session_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['step_history'] = json_decode( $row['step_history'], true ) ?? [];
		$row['user_answers'] = json_decode( $row['user_answers'], true ) ?? [];

		return $row;
	}

	/**
	 * Get paginated session logs for admin display.
	 *
	 * @param int    $per_page
	 * @param int    $page     1-indexed.
	 * @param string $outcome  Optional outcome filter.
	 * @return array { items: array, total: int }
	 */
	public static function get_sessions( int $per_page = 20, int $page = 1, string $outcome = '' ): array {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE_SLUG;
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		$where = '';
		$args  = [];

		if ( ! empty( $outcome ) ) {
			$where  = 'WHERE outcome = %s';
			$args[] = $outcome;
		}

		// Total count
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Rows
		$args[]  = $per_page;
		$args[]  = $offset;
		$row_sql = "SELECT id, session_id, flow_name, outcome, ip_address, user_id, created_at FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $wpdb->prepare( $row_sql, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return [
			'items' => $rows ?: [],
			'total' => $total,
		];
	}

	/**
	 * Delete old sessions (for GDPR / data retention).
	 *
	 * @param int $days_old  Delete sessions older than this many days.
	 * @return int|false Number of rows deleted.
	 */
	public static function purge_old_sessions( int $days_old = 90 ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SLUG;

		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days_old
			)
		);
	}

	// ─── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Get a salted hashed (pseudonymised) client IP for GDPR-compliant storage.
	 */
	private static function get_safe_ip(): string {
		$ip = '';
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Take only the first address in a comma-separated list
				$ip = explode( ',', $ip )[0];
				break;
			}
		}
		// Store salted hash to avoid storing raw IPs
		return $ip ? hash( 'sha256', $ip . wp_salt() ) : '';
	}
}
