<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #309 WP-CLI container for the `writeleash` command namespace. Registered
 * before the grant leaf so the nested `writeleash grant` registration has a
 * material parent; it intentionally declares no subcommands of its own.
 *
 * @package WriteLeash
 */
final class Grant_CLI_Namespace {
}

/**
 * #309/#308 operator grant management over WP-CLI: `wp writeleash grant
 * list|create|revoke|disable|enable`, including `revoke --all`. Every
 * subcommand requires an authenticated --user operator and never bypasses
 * Grant_Repository::is_authenticated_self: without --user the current
 * principal stays anonymous and every command refuses. No new capability;
 * the repository re-checks manage_woocommerce + edit_products on every
 * mutation.
 *
 * Registered in the runtime boot (CLI contexts only), never by test loaders.
 *
 * @package WriteLeash
 */
final class Grant_CLI {

	public static function boot(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) { return; }
		// The namespace container must be material before the nested leaf:
		// WP-CLI defers a nested registration indefinitely when the parent
		// command does not exist, which would silently unregister the surface.
		\WP_CLI::add_command( 'writeleash', Grant_CLI_Namespace::class );
		// The shortdesc forces eager registration so the reviewed boundary can
		// inspect the registered namespace in the same process. It stays
		// untranslated on purpose: a boot-time __() call would trigger
		// just-in-time translation loading before init on every wp/wp-admin
		// request.
		\WP_CLI::add_command( 'writeleash grant', Grant_CLI::class, array( 'shortdesc' => 'Manage WriteLeash standing grants.' ) );
	}

	/**
	 * Resolve the authenticated operator for the running command. --user is a
	 * global WP-CLI parameter: it is applied to the process principal during
	 * bootstrap and is not reliably mirrored into the command arguments, so
	 * the applied principal is the authority and an explicit --user value in
	 * the arguments must match it. Possession of an id proves nothing: an
	 * absent flag, a zero/negative id, an unknown id and a mismatch all
	 * refuse before any capability is considered; the product-management
	 * capability pair is re-checked exactly like the admin surface.
	 */
	private static function operator( array $assoc_args ): int {
		$raw = $assoc_args['user'] ?? null;
		$claimed = is_numeric( (string) $raw ) ? (int) $raw : (int) get_current_user_id();
		if ( $claimed < 1 ) {
			\WP_CLI::error( 'writeleash grant requires --user=<operator id>; refusing to act without an authenticated operator.' );
		}
		if ( ! Grant_Repository::is_authenticated_self( $claimed ) ) {
			\WP_CLI::error( 'PERMISSION_DENIED: --user must be the authenticated WP-CLI principal.' );
		}
		$operator = new \WP_User( $claimed );
		if ( ! $operator->exists() || ! user_can( $operator, 'manage_woocommerce' ) || ! user_can( $operator, 'edit_products' ) ) {
			\WP_CLI::error( 'PERMISSION_DENIED: the --user operator must be able to manage WooCommerce products.' );
		}
		return $claimed;
	}

	/** List standing grants: actor, issuer, status, budget, expiry, version. */
	public function list( array $args, array $assoc_args ): void {
		unset( $args );
		self::operator( $assoc_args );
		$grants = Grant_Repository::list_grants();
		$rows = array();
		foreach ( $grants as $grant ) {
			$rows[] = array(
				'public_id'       => (string) $grant['public_id'],
				'actor_id'        => (int) $grant['actor_id'],
				'issuer_id'       => (int) $grant['issuer_id'],
				'status'          => (string) $grant['status'],
				'budget_consumed' => (int) $grant['budget_consumed'],
				'budget_reserved' => (int) $grant['budget_reserved'],
				'budget_max'      => (int) $grant['budget_max'],
				'expires_at'      => (string) $grant['expires_at'],
				'version'         => (int) $grant['version'],
			);
		}
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'csv' ), true ) ) {
			\WP_CLI::error( 'Unsupported --format. Use table, json or csv.' );
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'public_id', 'actor_id', 'issuer_id', 'status', 'budget_consumed', 'budget_reserved', 'budget_max', 'expires_at', 'version' ) );
	}

	/** Issue a standing grant for an actor user with the caller as issuer. */
	public function create( array $args, array $assoc_args ): void {
		unset( $args );
		$operator_id = self::operator( $assoc_args );
		$actor_id = isset( $assoc_args['actor'] ) && is_numeric( (string) $assoc_args['actor'] ) ? (int) $assoc_args['actor'] : 0;
		$budget = isset( $assoc_args['budget'] ) && is_numeric( (string) $assoc_args['budget'] ) ? (int) $assoc_args['budget'] : 0;
		$expires = isset( $assoc_args['expires'] ) && is_string( $assoc_args['expires'] ) ? trim( $assoc_args['expires'] ) : '';
		if ( $actor_id < 1 || ! get_user_by( 'id', $actor_id ) ) {
			\WP_CLI::error( 'GRANT_IDENTITY_INVALID: --actor must be an existing user id distinct from the operator.' );
		}
		try {
			$grant = Grant_Repository::create_grant( $operator_id, $actor_id, $budget, $expires );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error instanceof Grant_Error ? $error->reason() : 'GRANT_STORE_UNAVAILABLE' );
		}
		\WP_CLI::success( 'grant ' . (string) $grant['public_id'] . ' created for actor #' . $actor_id . ' (budget ' . (int) $grant['budget_max'] . ', expires ' . (string) $grant['expires_at'] . ' UTC).' );
	}

	/** #308 pause restricted (grant-bound) execution. */
	public function disable( array $args, array $assoc_args ): void {
		unset( $args );
		$operator_id = self::operator( $assoc_args );
		try {
			Grant_Repository::disable( $operator_id );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error instanceof Grant_Error ? $error->reason() : 'GRANT_STORE_UNAVAILABLE' );
		}
		\WP_CLI::success( 'restricted execution paused; grant-bound writes are denied and principals stay restricted.' );
	}

	/** #308 resume restricted execution (explicit '0'). */
	public function enable( array $args, array $assoc_args ): void {
		unset( $args );
		$operator_id = self::operator( $assoc_args );
		try {
			Grant_Repository::enable( $operator_id );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error instanceof Grant_Error ? $error->reason() : 'GRANT_STORE_UNAVAILABLE' );
		}
		\WP_CLI::success( 'restricted execution resumed; grant-bound writes are allowed.' );
	}

	/**
	 * Revoke one standing grant by public id, or every active grant with
	 * --all. The principal stays restricted in both cases.
	 */
	public function revoke( array $args, array $assoc_args ): void {
		$operator_id = self::operator( $assoc_args );
		if ( ! empty( $assoc_args['all'] ) ) {
			if ( isset( $args[0] ) && '' !== trim( (string) $args[0] ) ) {
				\WP_CLI::error( 'Use either a grant public id or --all, not both.' );
			}
			try {
				$revoked = Grant_Repository::revoke_all( $operator_id );
			} catch ( \Throwable $error ) {
				\WP_CLI::error( $error instanceof Grant_Error ? $error->reason() : 'GRANT_STORE_UNAVAILABLE' );
			}
			\WP_CLI::success( 0 === $revoked ? 'no active grants; nothing revoked.' : $revoked . ' active grant(s) revoked; principals stay restricted.' );
			return;
		}
		$public_id = isset( $args[0] ) && is_string( $args[0] ) ? trim( $args[0] ) : '';
		try {
			$grant = Grant_Repository::revoke( $public_id, $operator_id );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( $error instanceof Grant_Error ? $error->reason() : 'GRANT_STORE_UNAVAILABLE' );
		}
		\WP_CLI::success( 'grant ' . (string) $grant['public_id'] . ' revoked; actor #' . (int) $grant['actor_id'] . ' stays restricted.' );
	}
}
