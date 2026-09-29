<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Four-section Tools page; one operation, no installer or user-provided SQL. */
final class Admin_Page {
	private const CAPABILITY = 'manage_options';
	private const PAGE = 'commitcap';
	private const ACTION = 'commitcap_action';
	private const NOTICE_SECONDS = 120;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_post' ) );
	}

	public static function menu(): void {
		add_management_page( 'CommitCap', 'CommitCap', self::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** The same authenticated, POST-only, nonce-bound controller used by the hook. */
	public static function process( array $post, string $method = 'POST', ?\wpdb $runtime = null, ?string $detected_version = null ): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' );
		}
		if ( 'POST' !== $method ) {
			return array( 'status' => 'INVALID', 'reason' => 'post_required' );
		}
		$action = isset( $post['commitcap_task'] ) ? $post['commitcap_task'] : null;
		if ( ! is_string( $action ) || ! in_array( $action, array( 'budget', 'enable', 'disable', 'demo' ), true ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_action' );
		}
		$nonce = isset( $post['_wpnonce'] ) ? $post['_wpnonce'] : null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'commitcap_' . $action ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' );
		}
		$operation = Certified_Operation::redirection_5_5_2_bulk_disable();
		try {
			switch ( $action ) {
				case 'budget':
					// No integer coercion: Operation_Config rejects noncanonical input.
					$raw = array_key_exists( 'logical_budget', $post ) ? $post['logical_budget'] : null;
					$value = is_string( $raw ) ? wp_unslash( $raw ) : $raw;
					try {
						$state = Operation_Config::set_logical_budget( $operation, $value );
					} catch ( \InvalidArgumentException $error ) {
						return array( 'status' => 'INVALID', 'reason' => 'invalid_budget' );
					}
					return array( 'status' => 'OK', 'reason' => 'budget_saved', 'logical_budget' => $state['logical_budget'] );
				case 'enable':
					$version = null !== $detected_version ? $detected_version : Redirection_Bulk_Disable::detected_version();
					$ready = Certified_Operation_Status::can_enable( $operation, $version, $runtime );
					if ( Certified_Operation_Status::READY !== $ready['status'] ) {
						return array( 'status' => 'NOT_READY', 'reason' => $ready['reason'] );
					}
					Operation_Config::set_enabled( $operation, true );
					return array( 'status' => 'OK', 'reason' => 'operation_enabled' );
				case 'disable':
					Operation_Config::set_enabled( $operation, false );
					return array( 'status' => 'OK', 'reason' => 'operation_disabled' );
				case 'demo':
					$demo = ( new Disposable_Demo( $runtime ) )->run();
					return array( 'status' => $demo['status'], 'reason' => $demo['reason'], 'demo' => $demo );
			}
		} catch ( \Throwable $error ) {
			// Never echo exception text, SQL or credentials to Admin/CLI.
			return array( 'status' => 'INVALID', 'reason' => 'action_failed' );
		}
		return array( 'status' => 'INVALID', 'reason' => 'invalid_action' );
	}

	/** Direct admin-post endpoint obeys the same capability/nonce checks. */
	public static function handle_post(): array {
		$post = isset( $_POST ) && is_array( $_POST ) ? $_POST : array();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';
		$result = self::process( $post, $method );
		if ( 'POST' === $method && current_user_can( self::CAPABILITY ) &&
			! in_array( $result['reason'], array( 'invalid_nonce', 'invalid_action' ), true ) ) {
			set_transient( self::notice_key(), self::notice_facts( $result ), self::NOTICE_SECONDS );
		}
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		return $result;
	}

	private static function notice_key(): string {
		return 'commitcap_notice_' . get_current_user_id();
	}

	/** Only these bounded facts cross the PRG redirect; no Doctor or wpdb object. */
	private static function notice_facts( array $result ): array {
		$notice = array( 'status' => $result['status'], 'reason' => $result['reason'] );
		if ( 'COMPLETE' === $result['status'] && isset( $result['demo']['safe'], $result['demo']['denied'] ) ) {
			$notice['demo'] = array(
				'safe' => $result['demo']['safe']['consumed'],
				'denied' => $result['demo']['denied']['consumed'],
				'kind' => $result['demo']['denied']['denial_kind'],
				'rollback_attempted' => $result['demo']['denied']['transaction_rollback_attempted'],
			);
		}
		return $notice;
	}

	private static function form( string $task, string $button, string $extra = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="commitcap_task" value="' . esc_attr( $task ) . '">';
		wp_nonce_field( 'commitcap_' . $task );
		echo $extra; // Only locally constructed, escaped form markup.
		echo '<p><button type="submit" class="button button-primary">' . esc_html( $button ) . '</button></p></form>';
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html( 'You are not allowed to manage CommitCap.' ), '', array( 'response' => 403 ) );
		}
		$s = Product_Status::snapshot();
		$notice = get_transient( self::notice_key() );
		echo '<div class="wrap"><h1>CommitCap</h1>';
		if ( is_array( $notice ) && isset( $notice['status'], $notice['reason'] ) && is_string( $notice['status'] ) && is_string( $notice['reason'] ) ) {
			$allowed = array( 'budget_saved', 'invalid_budget', 'operation_enabled', 'operation_disabled', 'invalid_nonce', 'post_required', 'invalid_action', 'action_failed', 'trusted_reset_required', 'runtime_unavailable', 'doctor_not_ready', 'target_privileges_mismatch', 'physical_ceiling_mismatch', 'redirection_version_unsupported', 'logical_budget_missing', 'config_invalid', 'demo_state_unavailable', 'demo_table_not_owned', 'safe_run_not_confirmed', 'logical_denial_not_confirmed', 'guard_paths_exercised' );
			$reason = in_array( $notice['reason'], $allowed, true ) ? $notice['reason'] : 'action_failed';
			echo '<div class="notice notice-' . ( in_array( $notice['status'], array( 'OK', 'COMPLETE' ), true ) ? 'success' : 'error' ) . '"><p>' . esc_html( $notice['status'] ) . ': ' . esc_html( $reason ) . '. ' . esc_html( Product_Status::explanation( $reason ) ) . '</p>';
			if ( 'COMPLETE' === $notice['status'] && isset( $notice['demo'] ) && is_array( $notice['demo'] ) &&
				5 === ( $notice['demo']['safe'] ?? null ) && 6 === ( $notice['demo']['denied'] ?? null ) &&
				'logical' === ( $notice['demo']['kind'] ?? null ) && true === ( $notice['demo']['rollback_attempted'] ?? null ) ) {
				echo '<p>CommitCap-owned disposable demo: safe leg 5 UPDATE row events COMMITTED; denied leg 6 UPDATE row events, logical budget 5, physical ceiling 6, DENIED before CommitCap COMMIT; rollback attempted. Independent durable rollback is not verified by this request.</p>';
			}
			echo '</div>';
		}
		echo '<h2>1. Setup / readiness</h2>';
		echo '<p>Operation readiness: <strong>' . esc_html( $s['operation_status'] ) . '</strong> (' . esc_html( $s['operation_reason'] ) . '). ' . esc_html( Product_Status::explanation( $s['operation_reason'] ) ) . '</p>';
		echo '<p>Live enable preflight: ' . esc_html( $s['preflight_status'] ) . ' (' . esc_html( $s['preflight_reason'] ) . '). Runtime/Doctor evidence: ' . esc_html( $s['doctor_state'] ) . '.</p>';
		echo '<p>Restricted runtime available: ' . esc_html( $s['runtime_available'] ? 'yes' : 'no' ) . '. WordPress ' . esc_html( $s['wordpress_version'] ) . '; database ' . esc_html( $s['database_family'] ) . ' ' . esc_html( $s['database_version'] ) . '.</p>';
		if ( ! $s['runtime_available'] ) {
			echo '<p>' . esc_html( Product_Status::explanation( 'runtime_unavailable' ) ) . '</p>';
		} elseif ( 'READY' !== $s['preflight_status'] ) {
			echo '<p>' . esc_html( Product_Status::explanation( $s['preflight_reason'] ) ) . '</p>';
		}
		echo '<p>Trusted database provisioning is required before READY. Ask your operator to follow the plugin PROVISIONING.md; installer credentials are not entered here.</p>';
		echo '<h2>2. Certified production operation</h2>';
		echo '<p><strong>' . esc_html( $s['operation_label'] ) . '</strong> (' . esc_html( $s['operation_id'] ) . '). Redirection detected: ' . esc_html( $s['detected_version'] ?? 'unavailable' ) . '; certified: ' . esc_html( $s['certified_version'] ) . '.</p>';
		echo '<p>Only Redirection 5.5.2 global/select-all Bulk Disable is protected under the cooperative restricted-runtime Guard. UPDATE row events; physical ceiling P=' . esc_html( (string) $s['physical_ceiling'] ) . ', logical budget L=' . esc_html( null === $s['logical_budget'] ? 'not set' : (string) $s['logical_budget'] ) . '; enabled: ' . esc_html( true === $s['enabled'] ? 'yes' : 'no' ) . '.</p>';
		echo '<p>Set a logical budget 0..' . esc_html( (string) $s['physical_ceiling'] ) . '. To use the protected operation, go to <a href="' . esc_url( admin_url( 'tools.php?page=redirection.php' ) ) . '">Redirection → Redirects</a>, select all matching, then Bulk Actions → Disable. Other Redirection operations are not certified. Email, HTTP and filesystem effects are outside DB rollback.</p>';
		$budget = '<label for="commitcap-budget">Logical UPDATE row-event budget</label> <input id="commitcap-budget" type="number" min="0" max="' . esc_attr( (string) $s['physical_ceiling'] ) . '" step="1" name="logical_budget" value="' . esc_attr( null === $s['logical_budget'] ? '' : (string) $s['logical_budget'] ) . '" required>';
		self::form( 'budget', 'Save logical budget', $budget );
		self::form( 'enable', 'Enable certified operation' );
		self::form( 'disable', 'Disable certified operation' );
		echo '<p>Disabled means global/select-all Disable is unavailable; it does not fall back to an unguarded Redirection operation.</p>';
		echo '<h2>3. Recent local production outcome</h2>';
		if ( null === $s['last_outcome'] ) {
			echo '<p>No validated certified outcome has been recorded locally.</p>';
		} else {
			$e = $s['last_outcome'];
			echo '<p>' . esc_html( $e['timestamp'] ) . ' · ' . esc_html( $e['outcome'] ) . ' (' . esc_html( $e['reason'] ) . '). Budget L=' . esc_html( (string) $e['logical_budget'] ) . ', P=' . esc_html( (string) $e['physical_ceiling'] ) . '.</p>';
			echo '<p>Attempted: ' . esc_html( null === $e['attempted'] ? 'unknown' : (string) $e['attempted'] ) . '; consumed: ' . esc_html( null === $e['consumed'] ? 'unknown' : (string) $e['consumed'] ) . '; affected rows: ' . esc_html( null === $e['affected_rows'] ? 'unknown' : (string) $e['affected_rows'] ) . '; denial kind: ' . esc_html( $e['denial_kind'] ?? 'none' ) . '.</p>';
			echo '<p>Rollback attempted: ' . esc_html( $e['transaction_rollback_attempted'] ? 'yes' : 'no' ) . '; rollback completion: unknown; independent fresh-observer durability verification: no.</p>';
		}
		echo '<h2>4. Disposable demo</h2>';
		echo '<p>CommitCap-owned disposable demo status: ' . esc_html( $s['demo_status'] ) . ' (' . esc_html( $s['demo_reason'] ) . '). ' . esc_html( 'trusted_reset_required' === $s['demo_reason'] ? Product_Status::explanation( 'trusted_reset_required' ) : '' ) . '</p>';
		echo '<p>Uses only CommitCap-owned disposable data. Does not modify Redirection or normal site content. Safe five-row COMMIT; six-row logical denial with rollback attempted. The demo does not certify Redirection or arbitrary WordPress writes.</p>';
		self::form( 'demo', 'Run disposable demo' );
		echo '</div>';
	}
}
