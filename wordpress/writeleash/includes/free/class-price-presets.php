<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** Versioned configuration-to-form adapter. No snapshots or execution authority. */
final class Price_Preset_Configuration {
	public const MAX_BYTES = 20000;
	public static function capture( array $form ): array {
		$selection = Free_Admin::build_selection( $form )->data();
		unset( $selection['warnings'] );
		$config = array( 'schema' => 1, 'selection' => $selection,
			'operation' => Free_Admin::build_operation( $form )->data(),
			'safety' => Free_Admin::build_policy( $form )->data() );
		if ( strlen( wp_json_encode( $config ) ) > self::MAX_BYTES ) { throw new Price_Validation_Error( 'invalid_preset' ); }
		return $config;
	}

	/** Also useful for a future historical configuration prefill; never imports a plan. */
	public static function form( array $config ): array {
		if ( 1 !== ( $config['schema'] ?? null ) || strlen( wp_json_encode( $config ) ) > self::MAX_BYTES ) { throw new Price_Validation_Error( 'invalid_preset' ); }
		$s = $config['selection'] ?? array(); $o = $config['operation'] ?? array(); $p = $config['safety'] ?? array();
		if ( ! is_array( $s ) || ! is_array( $o ) || ! is_array( $p ) ) { throw new Price_Validation_Error( 'invalid_preset' ); }
		$form = array( 'operation' => $o['type'] ?? null, 'amount' => $o['input'] ?? null,
			'price_field' => $o['field'] ?? null, 'ending' => $o['ending'] ?? 'default',
			'max_products' => is_int( $p['max_products_changed'] ?? null ) ? (string) $p['max_products_changed'] : null,
			'max_increase' => $p['max_increase_percent'] ?? null, 'max_decrease' => $p['max_decrease_percent'] ?? null,
			'warning_threshold' => $p['warning_threshold_percent'] ?? null );
		if ( ! is_bool( $p['block_zero'] ?? null ) ) { throw new Price_Validation_Error( 'invalid_preset' ); }
		if ( $p['block_zero'] ) { $form['block_zero'] = '1'; }
		if ( 'IDS' === ( $s['type'] ?? null ) ) {
			if ( ! is_array( $s['ids'] ?? null ) ) { throw new Price_Validation_Error( 'invalid_preset' ); }
			foreach ( $s['ids'] as $id ) { if ( ! is_int( $id ) ) { throw new Price_Validation_Error( 'invalid_preset' ); } }
			// Exact IDs remain visible even if a product disappeared from discovery.
			$form['selector'] = 'manual_ids'; $form['ids'] = implode( ',', $s['ids'] );
		} elseif ( 'SKU' === ( $s['type'] ?? null ) ) { $form['selector'] = 'sku'; $form['sku'] = $s['sku'] ?? null; }
		elseif ( 'CATEGORY' === ( $s['type'] ?? null ) ) {
			if ( ! is_int( $s['term_id'] ?? null ) || ! is_bool( $s['include_children'] ?? null ) ) { throw new Price_Validation_Error( 'invalid_preset' ); }
			$form['selector'] = 'category'; $form['category'] = (string) $s['term_id'];
			if ( $s['include_children'] ) { $form['include_subcategories'] = '1'; }
		} else { throw new Price_Validation_Error( 'invalid_preset' ); }
		// Reject unknown fields, malformed types and noncanonical saved configuration.
		// Future selection metadata can extend the versioned selection envelope.
		if ( self::capture( $form ) !== $config ) { throw new Price_Validation_Error( 'invalid_preset' ); }
		return $form;
	}
}

/** Per-blog bounded options, not autoloaded. Atomic slots enforce the store-wide cap. */
final class Price_Preset_Repository {
	public const MAX_PRESETS = 20;
	public const MAX_NAME_BYTES = 100;
	private const PREFIX = 'writeleash_price_preset_';

	public static function name( $name ): string {
		if ( ! is_string( $name ) || '' === trim( $name ) || strlen( $name ) > self::MAX_NAME_BYTES ||
			! preg_match( '//u', $name ) || preg_match( '/[\x00-\x1f\x7f<>]/', $name ) ) { throw new Price_Validation_Error( 'invalid_preset_name' ); }
		return trim( $name );
	}
	private static function signature( array $record ): string {
		unset( $record['signature'] );
		return hash_hmac( 'sha256', get_current_blog_id() . ':' . wp_json_encode( $record ), wp_salt( 'auth' ) );
	}
	private static function verify( $record ): array {
		if ( ! is_array( $record ) || array_keys( $record ) !== array( 'id', 'creator_id', 'name', 'configuration', 'signature' ) ||
			! is_string( $record['id'] ) || ! preg_match( Job_Repository::PUBLIC_ID_REGEX, $record['id'] ) ||
			! is_int( $record['creator_id'] ) || $record['creator_id'] < 1 || ! is_array( $record['configuration'] ) ||
			! is_string( $record['signature'] ) || ! hash_equals( self::signature( $record ), $record['signature'] ) ) { throw new Price_Validation_Error( 'invalid_preset' ); }
		self::name( $record['name'] ); Price_Preset_Configuration::form( $record['configuration'] );
		return $record;
	}
	private static function allowed( array $record ): bool {
		return get_current_user_id() === $record['creator_id'] || current_user_can( 'manage_options' );
	}
	private static function require_actor(): void {
		if ( get_current_user_id() < 1 || ! Free_Admin::can_mutate() ) { throw new Price_Validation_Error( 'permission_denied' ); }
	}
	public static function listing(): array {
		self::require_actor(); $records = array();
		for ( $slot = 0; $slot < self::MAX_PRESETS; ++$slot ) {
			$record = get_option( self::PREFIX . $slot, null );
			if ( null === $record ) { continue; }
			try { $record = self::verify( $record ); }
			catch ( \Throwable $error ) { continue; } // Corrupt slots still consume capacity.
			if ( self::allowed( $record ) ) { $records[] = $record; }
		}
		return $records;
	}
	private static function find( $id ): array {
		self::require_actor();
		if ( ! is_string( $id ) || ! preg_match( Job_Repository::PUBLIC_ID_REGEX, $id ) ) { throw new Price_Validation_Error( 'preset_unavailable' ); }
		for ( $slot = 0; $slot < self::MAX_PRESETS; ++$slot ) {
			$raw = get_option( self::PREFIX . $slot, null );
			if ( ! is_array( $raw ) || ( $raw['id'] ?? null ) !== $id ) { continue; }
			$record = self::verify( $raw );
			if ( ! self::allowed( $record ) ) { break; }
			return array( $slot, $record );
		}
		throw new Price_Validation_Error( 'preset_unavailable' );
	}
	public static function load( $id ): array { return self::find( $id )[1]; }
	public static function save( $name, array $configuration ): array {
		global $wpdb;
		self::require_actor(); Price_Preset_Configuration::form( $configuration );
		$record = array( 'id' => wp_generate_uuid4(), 'creator_id' => get_current_user_id(), 'name' => self::name( $name ), 'configuration' => $configuration );
		$record['signature'] = self::signature( $record );
		for ( $slot = 0; $slot < self::MAX_PRESETS; ++$slot ) {
			$key = self::PREFIX . $slot;
			// WordPress add_option uses an upsert in some supported versions.
			// INSERT IGNORE reserves a slot without ever replacing its owner.
			$sql = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,%s)", $key, maybe_serialize( $record ), 'no' );
			$inserted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $inserted ) { throw new Price_Validation_Error( 'preset_unavailable' ); }
			if ( 1 === $inserted ) { wp_cache_delete( $key, 'options' ); wp_cache_delete( 'notoptions', 'options' ); return $record; }

		}
		throw new Price_Validation_Error( 'preset_limit' );
	}
	/** Compare-and-swap prevents a stale rename/delete from changing a reused slot. */
	private static function replace( int $slot, array $old, ?array $new ): void {
		global $wpdb;
		$key = self::PREFIX . $slot;
		if ( null === $new ) {
			$sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s", $key, maybe_serialize( $old ) );
		} else {
			$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", maybe_serialize( $new ), $key, maybe_serialize( $old ) );
		}
		if ( 1 !== $wpdb->query( $sql ) ) { throw new Price_Validation_Error( 'preset_changed' ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		wp_cache_delete( $key, 'options' ); wp_cache_delete( 'notoptions', 'options' );
	}
	public static function rename( $id, $name ): void {
		list( $slot, $old ) = self::find( $id ); $new = $old; $new['name'] = self::name( $name );
		if ( $new['name'] === $old['name'] ) { return; }
		$new['signature'] = self::signature( $new ); self::replace( $slot, $old, $new );
	}
	public static function delete( $id ): void { list( $slot, $old ) = self::find( $id ); self::replace( $slot, $old, null ); }
}
