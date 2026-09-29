<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Secret-free view of the single production operation and synthetic demo. */
final class Product_Status {
	public static function snapshot( ?\wpdb $runtime = null, ?string $detected_version = null ): array {
		$operation = Certified_Operation::redirection_5_5_2_bulk_disable();
		$version = null !== $detected_version ? $detected_version : Redirection_Bulk_Disable::detected_version();
		$db = $runtime ?: Certified_Operation_Status::runtime_connection();
		$config = Operation_Config::read( $operation );
		$current = Certified_Operation_Status::check( $operation, $version, $db );
		// An enabled operation was just checked live against the same contract;
		// only a disabled operation needs the preflight path past DISABLED.
		$preflight = true === $config['enabled'] ? $current : Certified_Operation_Status::can_enable( $operation, $version, $db );
		$doctor = isset( $preflight['doctor']['overall'] ) ? $preflight['doctor']['overall'] : 'UNKNOWN';
		$normal = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ? $GLOBALS['wpdb'] : null;
		$server = null !== $normal ? (string) $normal->get_var( 'SELECT VERSION()' ) : '';
		try {
			$family = Update_Engine::target_family( $server );
		} catch ( \Throwable $error ) {
			$family = 'UNKNOWN';
		}
		$demo = ( new Disposable_Demo( $db ) )->status();
		return array(
			'operation_id' => $operation->id(),
			'operation_label' => $operation->display_name(),
			'enabled' => $config['enabled'],
			'logical_budget' => $config['logical_budget'],
			'physical_ceiling' => $operation->physical_ceiling(),
			'operation_status' => $current['status'],
			'operation_reason' => $current['reason'],
			'preflight_status' => $preflight['status'],
			'preflight_reason' => $preflight['reason'],
			'actual_physical_ceiling' => $preflight['actual_physical_ceiling'],
			'doctor_state' => $doctor,
			'runtime_available' => $db instanceof \wpdb && $db->ready && $db->dbh instanceof \mysqli,
			'detected_version' => $version,
			'certified_version' => $operation->plugin_version(),
			'database_family' => $family,
			'database_version' => $server,
			'wordpress_version' => Environment::wordpress_version(),
			'last_outcome' => Last_Outcome::read(),
			'demo_status' => $demo['status'],
			'demo_reason' => $demo['reason'],
			'demo_state' => isset( $demo['canonical_state'] ) ? $demo['canonical_state'] : null,
		);
	}

	/** Fixed actionable copy; reason strings themselves remain machine-readable. */
	public static function explanation( string $reason ): string {
		$messages = array(
			'budget_saved' => 'The logical UPDATE row-event budget was saved without changing the physical policy.',
			'invalid_budget' => 'Enter a canonical whole number from 0 through 2000 (no sign, spaces or leading zero). The previous budget was preserved.',
			'operation_enabled' => 'The certified operation passed live preflight and is enabled.',
			'guard_paths_exercised' => 'The CommitCap-owned disposable demo exercised its guarded safe and denied legs.',
			'operation_disabled' => 'Protection disabled: the certified global Bulk Disable operation is unavailable until CommitCap is re-enabled and READY.',
			'config_invalid' => 'The stored operation configuration needs trusted review before it can be changed.',
			'logical_budget_missing' => 'Choose a logical UPDATE row-event budget before enabling.',
			'redirection_version_unsupported' => 'Redirection version is not the certified 5.5.2 version.',
			'adapter_unavailable' => 'The certified Redirection adapter is unavailable.',
			'runtime_unavailable' => 'Restricted runtime is not configured or cannot connect. Ask the trusted operator to follow PROVISIONING.md.',
			'target_privileges_mismatch' => 'Runtime target privileges do not match the reviewed SELECT/UPDATE boundary.',
			'physical_ceiling_mismatch' => 'Physical ceiling differs from the certified P=2000; ask the trusted operator to repair the policy.',
			'doctor_not_ready' => 'Runtime Doctor could not prove this policy READY; trusted operator review is required.',
			'trusted_reset_required' => 'Disposable demo data needs trusted operator recovery before it can run again.',
			'no_connection' => 'The restricted runtime is unavailable.',
		);
		return isset( $messages[ $reason ] ) ? $messages[ $reason ] : 'Inspect the reported machine reason and ask the trusted operator to review setup.';
	}
}
