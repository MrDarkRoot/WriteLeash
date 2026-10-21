<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #309 operator grant management: list, issue, revoke and remove the
 * restricted-principal flag. No new capability: every screen and action
 * requires the reviewed product-management pair (manage_woocommerce +
 * edit_products) and the repository re-verifies the authenticated principal
 * on every mutation. Un-leashing is stronger than revoking, so the separate
 * remove-restriction action additionally requires manage_options and can
 * never be applied by the restricted principal to itself. All mutations are
 * nonce-checked admin_post_ handlers with a transient-notice + safe-redirect
 * PRG flow; listing is a read-only page render. `remove restriction` is a
 * separate action with an explicit standing warning — it is never bundled
 * with a single-grant revoke.
 *
 * @package WriteLeash
 */
final class Grant_Admin {

	public const SLUG = 'writeleash-grants';
	public const ACTION_CREATE = 'writeleash_grant_create';
	public const ACTION_REVOKE = 'writeleash_grant_revoke';
	public const ACTION_REMOVE = 'writeleash_grant_remove';
	private const NOTICE_SECONDS = 120;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION_CREATE, array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE, array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_' . self::ACTION_REMOVE, array( __CLASS__, 'handle_remove' ) );
	}

	/**
	 * Operator surface next to Bulk Prices: Woo-first placement with the same
	 * dependency fallbacks. Visibility needs product editing; every mutation
	 * additionally requires manage_woocommerce.
	 */
	public static function menu(): void {
		$title = __( 'WriteLeash Grants', 'writeleash' );
		if ( post_type_exists( 'product' ) ) {
			add_submenu_page( 'edit.php?post_type=product', $title, esc_html__( 'Grants', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		} elseif ( function_exists( 'wc_get_product' ) ) {
			add_submenu_page( 'woocommerce', $title, esc_html__( 'Grants', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		} else {
			add_management_page( $title, esc_html__( 'WriteLeash Grants', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	/** Whether both mutation capabilities are present for the current actor. */
	public static function can_mutate(): bool {
		return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_products' );
	}

	/**
	 * UI visibility for the remove section: site administration on top of the
	 * product-management pair, and never for a restricted principal (even an
	 * administrator). The server-side action re-checks the capabilities and
	 * additionally refuses self-removal outright.
	 */
	public static function can_remove(): bool {
		return self::can_mutate() && current_user_can( 'manage_options' ) && ! Grant_Repository::is_restricted_actor();
	}

	// ------------------------------------------------------------------
	// HTTP handlers: thin input extraction, then the testable process_*
	// core, then a transient notice plus safe redirect (PRG).
	// ------------------------------------------------------------------

	public static function request_method(): string {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		}
		return '';
	}

	private static function post_field( string $key ): ?string {
		$value = filter_input( INPUT_POST, $key );
		return is_string( $value ) ? $value : null;
	}

	private static function normalize_nonce( $value ): ?string {
		if ( ! is_string( $value ) ) { return null; }
		return sanitize_text_field( wp_unslash( $value ) );
	}

	private static function post_input(): array {
		return array(
			'_wpnonce' => self::post_field( '_wpnonce' ),
			'grant'    => self::post_field( 'grant' ),
			'actor'    => self::post_field( 'actor' ),
			'budget'   => self::post_field( 'budget' ),
			'expires'  => self::post_field( 'expires' ),
		);
	}

	private static function int_field( $value ): int {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9]{1,9}\z/D', $value ) ? (int) $value : 0;
	}

	private static function gate( string $nonce_action, array $post, string $method ): ?array {
		if ( ! self::can_mutate() ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' );
		}
		if ( 'POST' !== $method ) {
			return array( 'status' => 'INVALID', 'reason' => 'post_required' );
		}
		$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' );
		}
		return null;
	}

	/** Map a repository failure to a typed UI result; refusals never mutate. */
	private static function refused( \Throwable $error ): array {
		$reason = 'invalid_input';
		if ( $error instanceof Grant_Error ) {
			$reason = $error->reason();
		}
		return array( 'status' => 'INVALID', 'reason' => $reason );
	}

	private static function finish( array $result ): array {
		$user_id = get_current_user_id();
		if ( $user_id > 0 && isset( $result['status'], $result['reason'] ) ) {
			set_transient( self::notice_key( $user_id ), $result, self::NOTICE_SECONDS );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		return $result;
	}

	private static function notice_key( int $user_id ): string {
		return 'writeleash_grant_notice_' . $user_id;
	}

	public static function handle_create(): array {
		$post = self::post_input();
		return self::finish( self::process_create( $post, self::request_method() ) );
	}

	public static function handle_revoke(): array {
		$post = self::post_input();
		return self::finish( self::process_revoke( $post, self::request_method() ) );
	}

	public static function handle_remove(): array {
		$post = self::post_input();
		return self::finish( self::process_remove( $post, self::request_method() ) );
	}

	// ------------------------------------------------------------------
	// Testable cores.
	// ------------------------------------------------------------------

	/**
	 * Issue a standing grant with the authenticated operator as issuer.
	 * The actor must be a real, distinct user; budget and expiry contracts
	 * are enforced by the repository (1..MAX_BUDGET, future UTC datetime).
	 */
	public static function process_create( array $post, string $method ): array {
		$gate = self::gate( self::ACTION_CREATE, $post, $method );
		if ( null !== $gate ) { return $gate; }
		$actor_id = self::int_field( $post['actor'] ?? null );
		$budget = self::int_field( $post['budget'] ?? null );
		$expires = trim( (string) ( $post['expires'] ?? '' ) );
		if ( $actor_id < 1 || ! get_user_by( 'id', $actor_id ) ) { return array( 'status' => 'INVALID', 'reason' => 'GRANT_IDENTITY_INVALID' ); }
		$operator_id = (int) get_current_user_id();
		try {
			$grant = Grant_Repository::create_grant( $operator_id, $actor_id, $budget, $expires );
		} catch ( \Throwable $error ) { return self::refused( $error ); }
		return array( 'status' => 'OK', 'reason' => 'grant_created', 'public_id' => (string) $grant['public_id'], 'actor_id' => $actor_id );
	}

	/** Revoke one grant durably; the principal stays restricted. */
	public static function process_revoke( array $post, string $method ): array {
		$gate = self::gate( self::ACTION_REVOKE, $post, $method );
		if ( null !== $gate ) { return $gate; }
		$public_id = trim( (string) ( $post['grant'] ?? '' ) );
		$operator_id = (int) get_current_user_id();
		try {
			$grant = Grant_Repository::revoke( $public_id, $operator_id );
		} catch ( \Throwable $error ) { return self::refused( $error ); }
		return array( 'status' => 'OK', 'reason' => 'grant_revoked', 'public_id' => (string) $grant['public_id'], 'actor_id' => (int) $grant['actor_id'] );
	}

	/**
	 * Remove the restricted-principal flag for one target user. Privileged,
	 * separate from revoke, administrator-only and audited with the target
	 * user id by the repository. The acting principal can never un-leash
	 * itself; the repository enforces the same rule transactionally.
	 */
	public static function process_remove( array $post, string $method ): array {
		if ( ! self::can_mutate() ) { return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ); }
		if ( ! current_user_can( 'manage_options' ) ) { return array( 'status' => 'FORBIDDEN', 'reason' => 'manage_options_required' ); }
		$gate = self::gate( self::ACTION_REMOVE, $post, $method );
		if ( null !== $gate ) { return $gate; }
		$actor_id = self::int_field( $post['actor'] ?? null );
		$operator_id = (int) get_current_user_id();
		if ( $actor_id > 0 && $actor_id === $operator_id ) { return array( 'status' => 'FORBIDDEN', 'reason' => 'self_removal_denied' ); }
		try {
			$outcome = Grant_Repository::remove_restriction( $actor_id, $operator_id );
		} catch ( \Throwable $error ) { return self::refused( $error ); }
		return array(
			'status' => 'OK',
			'reason' => $outcome['already_removed'] ? 'restriction_already_removed' : 'restriction_removed',
			'actor_id' => $actor_id,
			'removed_rows' => (int) $outcome['removed_rows'],
		);
	}

	// ------------------------------------------------------------------
	// Read-only listing page.
	// ------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html__( 'You are not allowed to manage product grants.', 'writeleash' ) . '</p></div>';
			return;
		}
		self::render_notice();
		$grants = Grant_Repository::list_grants();
		$actor_ids = array();
		foreach ( $grants as $grant ) { $actor_ids[] = (int) $grant['actor_id']; }
		$removed = Grant_Repository::removed_actors( $actor_ids );
		$manage = self::can_mutate();
		$is_admin = current_user_can( 'manage_options' );
		$can_remove = self::can_remove();
		$current_id = (int) get_current_user_id();
		$create_nonce = $manage ? wp_create_nonce( self::ACTION_CREATE ) : '';
		$revoke_nonce = $manage ? wp_create_nonce( self::ACTION_REVOKE ) : '';
		$remove_nonce = $can_remove ? wp_create_nonce( self::ACTION_REMOVE ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WriteLeash Grants', 'writeleash' ); ?></h1>
			<p><?php esc_html_e( 'Standing grants leash an agent user to WriteLeash-controlled price writes only. Revoking a grant stops future work but keeps the principal restricted; removing the restriction is a separate action below.', 'writeleash' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Grant', 'writeleash' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'writeleash' ); ?></th>
						<th><?php esc_html_e( 'Issuer', 'writeleash' ); ?></th>
						<th><?php esc_html_e( 'Status', 'writeleash' ); ?></th>
						<th><?php esc_html_e( 'Budget (consumed / reserved / max)', 'writeleash' ); ?></th>
						<th><?php esc_html_e( 'Expires (UTC)', 'writeleash' ); ?></th>
						<th><?php esc_html_e( 'Version', 'writeleash' ); ?></th>
						<th><?php esc_html_e( 'Restriction', 'writeleash' ); ?></th>
						<?php if ( $manage ) { ?><th><?php esc_html_e( 'Actions', 'writeleash' ); ?></th><?php } ?>
					</tr>
				</thead>
				<tbody>
				<?php if ( array() === $grants ) { ?>
					<tr><td colspan="<?php echo $manage ? 9 : 8; ?>"><?php esc_html_e( 'No standing grants exist.', 'writeleash' ); ?></td></tr>
				<?php } else { ?>
					<?php foreach ( $grants as $grant ) { ?>
					<tr>
						<td><code><?php echo esc_html( substr( (string) $grant['public_id'], 0, 8 ) ); ?></code></td>
						<td><?php echo esc_html( '#' . (int) $grant['actor_id'] ); ?></td>
						<td><?php echo esc_html( '#' . (int) $grant['issuer_id'] ); ?></td>
						<td><?php echo esc_html( (string) $grant['status'] ); ?></td>
						<td><?php echo esc_html( (int) $grant['budget_consumed'] . ' / ' . (int) $grant['budget_reserved'] . ' / ' . (int) $grant['budget_max'] ); ?></td>
						<td><?php echo esc_html( (string) $grant['expires_at'] ); ?></td>
						<td><?php echo esc_html( (int) $grant['version'] ); ?></td>
						<td><?php echo esc_html( in_array( (int) $grant['actor_id'], $removed, true ) ? __( 'Removed', 'writeleash' ) : __( 'Restricted', 'writeleash' ) ); ?></td>
						<?php if ( $manage ) { ?>
						<td>
							<?php if ( $current_id === (int) $grant['issuer_id'] || $is_admin ) { ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REVOKE ); ?>" />
								<input type="hidden" name="grant" value="<?php echo esc_attr( (string) $grant['public_id'] ); ?>" />
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $revoke_nonce ); ?>" />
								<button type="submit" class="button"><?php esc_html_e( 'Revoke', 'writeleash' ); ?></button>
							</form>
							<?php } else { ?>
							<span class="description"><?php esc_html_e( 'Issuer only', 'writeleash' ); ?></span>
							<?php } ?>
						</td>
						<?php } ?>
					</tr>
					<?php } ?>
				<?php } ?>
				</tbody>
			</table>
			<?php if ( $manage ) { ?>
			<h2><?php esc_html_e( 'Issue a standing grant', 'writeleash' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CREATE ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $create_nonce ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="writeleash-grant-actor"><?php esc_html_e( 'Actor user ID', 'writeleash' ); ?></label></th>
						<td><input type="number" min="1" step="1" id="writeleash-grant-actor" name="actor" value="" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="writeleash-grant-budget"><?php esc_html_e( 'Budget maximum (1–10000 items)', 'writeleash' ); ?></label></th>
						<td><input type="number" min="1" max="10000" step="1" id="writeleash-grant-budget" name="budget" value="1" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="writeleash-grant-expires"><?php esc_html_e( 'Expires (UTC, YYYY-MM-DD HH:MM:SS)', 'writeleash' ); ?></label></th>
						<td><input type="text" id="writeleash-grant-expires" name="expires" value="" class="regular-text" required aria-describedby="writeleash-grant-expires-help" />
						<p id="writeleash-grant-expires-help" class="description"><?php esc_html_e( 'Must be a future UTC datetime; expired grants stay restricted.', 'writeleash' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create grant', 'writeleash' ) ); ?>
			</form>
			<?php } ?>
			<?php if ( $can_remove ) { ?>
			<h2><?php esc_html_e( 'Remove restriction', 'writeleash' ); ?></h2>
			<div class="notice notice-warning" role="alert"><p><?php esc_html_e( 'Warning: removing the restriction returns this user to ordinary WooCommerce capability rules immediately. The user will be able to edit products, stock and prices like any other editor of their role. Existing grant evidence is retained; this action is audited with the target user ID.', 'writeleash' ); ?></p></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REMOVE ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $remove_nonce ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="writeleash-grant-remove-actor"><?php esc_html_e( 'Restricted user ID', 'writeleash' ); ?></label></th>
						<td><input type="number" min="1" step="1" id="writeleash-grant-remove-actor" name="actor" value="" required /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Remove restriction', 'writeleash' ) ); ?>
			</form>
			<?php } ?>
		</div>
		<?php
	}

	/** Bounded ephemeral feedback; alerts are announced to assistive tech. */
	private static function render_notice(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) { return; }
		$notice = get_transient( self::notice_key( $user_id ) );
		if ( ! is_array( $notice ) ) { return; }
		delete_transient( self::notice_key( $user_id ) );
		$status = isset( $notice['status'] ) && is_string( $notice['status'] ) ? $notice['status'] : 'INVALID';
		$reason = isset( $notice['reason'] ) && is_string( $notice['reason'] ) ? $notice['reason'] : 'invalid_input';
		$class = 'OK' === $status ? 'notice-success' : ( 'FORBIDDEN' === $status ? 'notice-error' : 'notice-warning' );
		echo '<div class="notice ' . esc_attr( $class ) . '" role="alert"><p>' . esc_html( self::reason_message( $reason ) ) . '</p>';
		if ( 'OK' === $status && ! empty( $notice['public_id'] ) && is_string( $notice['public_id'] ) ) {
			echo '<p>' . esc_html( sprintf( /* translators: %s: grant public id prefix. */ __( 'Grant reference: %s', 'writeleash' ), substr( $notice['public_id'], 0, 8 ) ) ) . '</p>';
		}
		echo '</div>';
	}

	/** Operator-facing copy for the typed outcome reasons. */
	private static function reason_message( string $reason ): string {
		$messages = array(
			'grant_created' => __( 'Standing grant created. The actor is now restricted to WriteLeash-controlled price writes.', 'writeleash' ),
			'grant_revoked' => __( 'Grant revoked. The principal stays restricted until the restriction is removed.', 'writeleash' ),
			'restriction_removed' => __( 'Restriction removed. The user returned to ordinary WooCommerce capability rules.', 'writeleash' ),
			'restriction_already_removed' => __( 'The restriction was already removed for this user.', 'writeleash' ),
			'capability_required' => __( 'You are not allowed to manage product grants.', 'writeleash' ),
			'post_required' => __( 'The request must be a form submission.', 'writeleash' ),
			'invalid_nonce' => __( 'The form session expired. Reload the page and try again.', 'writeleash' ),
			'GRANT_IDENTITY_INVALID' => __( 'The actor must be an existing user other than you.', 'writeleash' ),
			'GRANT_BUDGET_INVALID' => __( 'The budget must be between 1 and 10000 items.', 'writeleash' ),
			'GRANT_EXPIRY_INVALID' => __( 'The expiry must be a future UTC datetime in YYYY-MM-DD HH:MM:SS format.', 'writeleash' ),
			'GRANT_NOT_FOUND' => __( 'No matching grant or restricted principal was found.', 'writeleash' ),
			'PERMISSION_DENIED' => __( 'Only the issuing operator or an administrator may manage this grant.', 'writeleash' ),
			'manage_options_required' => __( 'Only a site administrator can remove a restriction.', 'writeleash' ),
			'self_removal_denied' => __( 'The restricted user cannot remove their own restriction; another administrator must do it.', 'writeleash' ),
			'GRANT_STORE_UNAVAILABLE' => __( 'The grant store is unavailable. Nothing was changed.', 'writeleash' ),
			'SCHEMA_UNAVAILABLE' => __( 'The grant store is unavailable. Nothing was changed.', 'writeleash' ),
			'invalid_input' => __( 'The submitted values are invalid. Nothing was changed.', 'writeleash' ),
		);
		return $messages[ $reason ] ?? $messages['invalid_input'];
	}
}
