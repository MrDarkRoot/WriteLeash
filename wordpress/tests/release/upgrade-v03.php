<?php
// #283 exact-artifact upgrade harness: published WriteLeash 0.2.2 -> a
// v0.3.0-shaped candidate. Repository-only fixture run with WP-CLI against a
// disposable WordPress + WooCommerce site.
//
// This fixture never installs a ZIP. The driver installs the exact artifacts.
// It seeds supported-workflow data on 0.2.2, then inspects the lazy migrations
// the target performs on first supported use.
//
// Environment:
//   WL283_UPGRADE_MODE=seed|verify|negative
//   WL283_UPGRADE_RECEIPT=<private receipt file>
//   WL283_SOURCE_ZIP=<published 0.2.2 ZIP>
//   WL283_SOURCE_SHA256=<expected source SHA-256>
//   WL283_TARGET_ZIP=<v0.3.0 candidate ZIP>          (verify/negative)
//   WL283_TARGET_SHA256=<expected target SHA-256>    (optional)
//   WL283_TARGET_VERSION=<expected target version>   (default 0.3.0)
//   WL283_NEGATIVE_KIND=unknown-grant-schema|malformed-grant-option
//   WL283_POST_ACTIVATION_STATE={"grant":1,"journal":3,"job":2}
//                                                     (verify/negative, set by driver)
use WriteLeash\Change_Plan as Plan;
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Grant_Schema as GrantSchema;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Schema as JobSchema;
use WriteLeash\Job_State as JobState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Decimal as Decimal;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Preset_Configuration as PresetConfig;
use WriteLeash\Price_Preset_Repository as Presets;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Undo_Repository as Undo;
use WriteLeash\Undo_Schema as UndoSchema;
use WriteLeash\Undo_State as UndoState;
use WriteLeash\Undo_Worker as UndoWorker;
use WriteLeash\Woo_Price_Planner as Planner;

if ( PHP_SAPI !== 'cli' ) {
	throw new RuntimeException( '#283 requires WP-CLI' );
}
$env = static function ( string $name ): string {
	$value = getenv( $name );
	return false === $value ? '' : (string) $value;
};
$mode = $env( 'WL283_UPGRADE_MODE' );
$receipt_path = $env( 'WL283_UPGRADE_RECEIPT' );
if ( ! in_array( $mode, array( 'seed', 'verify', 'negative' ), true ) ) {
	throw new RuntimeException( '#283 invalid WL283_UPGRADE_MODE' );
}
if ( '' === $receipt_path ) {
	throw new RuntimeException( '#283 explicit private receipt required' );
}
$source_zip = $env( 'WL283_SOURCE_ZIP' );
$source_sha = strtolower( $env( 'WL283_SOURCE_SHA256' ) );
$target_zip = $env( 'WL283_TARGET_ZIP' );
$target_sha = strtolower( $env( 'WL283_TARGET_SHA256' ) );
$target_version = $env( 'WL283_TARGET_VERSION' ) ?: '0.3.0';
$negative_kind = $env( 'WL283_NEGATIVE_KIND' ) ?: 'unknown-grant-schema';
$post_activation_raw = $env( 'WL283_POST_ACTIVATION_STATE' );
if ( ! in_array( $negative_kind, array( 'unknown-grant-schema', 'malformed-grant-option' ), true ) ) {
	throw new RuntimeException( '#283 invalid WL283_NEGATIVE_KIND' );
}
if ( '' === $source_sha ) {
	throw new RuntimeException( '#283 WL283_SOURCE_SHA256 is required' );
}
if ( 'seed' !== $mode && '' === $target_zip ) {
	throw new RuntimeException( '#283 WL283_TARGET_ZIP is required for ' . $mode );
}

global $wpdb;
wp_set_current_user( 1 );

$checks = array();
$receipt = array();
$failures = 0;

$emit = static function ( string $id, string $status, string $label, string $detail = '', string $claim = '' ) use ( &$checks ): void {
	$checks[] = array( 'id' => $id, 'status' => $status, 'label' => $label, 'detail' => $detail, 'claim_withheld' => $claim );
	$suffix = '' === $detail ? '' : ' :: ' . $detail;
	$withheld = '' === $claim ? '' : ' [withheld: ' . $claim . ']';
	echo '#283 ' . $id . ': ' . $status . ' - ' . $label . $suffix . $withheld . "\n";
};
$check = static function ( string $id, string $label, callable $fn, string $claim = '' ) use ( $emit, &$failures ): bool {
	try {
		$detail = $fn();
		$emit( $id, 'PASS', $label, is_string( $detail ) ? $detail : '' );
		return true;
	} catch ( \Throwable $error ) {
		++$failures;
		$emit( $id, 'FAIL', $label, $error->getMessage(), $claim );
		return false;
	}
};
$skip = static function ( string $id, string $label, string $reason, string $claim = '' ) use ( $emit ): void {
	$emit( $id, 'NOT_TESTED', $label, $reason, $claim );
};
$blocked = static function ( string $id, string $label, string $reason, string $claim = '' ) use ( $emit ): void {
	$emit( $id, 'BLOCKED', $label, $reason, $claim );
};
$write_receipt = static function () use ( &$receipt, &$checks, $receipt_path ): void {
	$receipt['checks'] = $checks;
	$receipt['finished_at'] = gmdate( 'c' );
	$json = wp_json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( false === $json ) {
		throw new RuntimeException( '#283 receipt JSON encoding failed' );
	}
	$dir = dirname( $receipt_path );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
		throw new RuntimeException( '#283 cannot create receipt directory' );
	}
	if ( false === file_put_contents( $receipt_path, $json . "\n" ) ) {
		throw new RuntimeException( '#283 cannot write receipt' );
	}
	chmod( $receipt_path, 0600 );
};
$finish = static function () use ( &$failures, $write_receipt ): void {
	$write_receipt();
	if ( $failures > 0 ) {
		throw new RuntimeException( '#283 one or more checks failed' );
	}
};
$zip_meta = static function ( string $zip ): array {
	if ( ! is_file( $zip ) ) {
		throw new RuntimeException( '#283 ZIP not found: ' . $zip );
	}
	$archive = new ZipArchive();
	if ( true !== $archive->open( $zip ) ) {
		throw new RuntimeException( '#283 cannot open ZIP: ' . $zip );
	}
	$entry = null;
	for ( $i = 0; $i < $archive->numFiles; ++$i ) {
		$name = (string) $archive->getNameIndex( $i );
		if ( preg_match( '#\A([^/]+)/writeleash\.php\z#', $name ) ) {
			$entry = $name;
			break;
		}
	}
	if ( null === $entry ) {
		$archive->close();
		throw new RuntimeException( '#283 ZIP has no writeleash/writeleash.php entry' );
	}
	$text = (string) $archive->getFromName( $entry );
	$archive->close();
	if ( ! preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $text, $header ) ) {
		throw new RuntimeException( '#283 ZIP entry has no Version header' );
	}
	if ( ! preg_match( "/define\(\s*'WRITELEASH_VERSION'\s*,\s*'([^']+)'\s*\)/", $text, $constant ) ) {
		throw new RuntimeException( '#283 ZIP entry has no WRITELEASH_VERSION constant' );
	}
	$header_version = trim( $header[1] );
	$constant_version = $constant[1];
	return array(
		'zip' => realpath( $zip ),
		'sha256' => hash_file( 'sha256', $zip ),
		'entry' => $entry,
		'header_version' => $header_version,
		'constant_version' => $constant_version,
		'version' => $header_version === $constant_version ? $header_version : '',
	);
};
$environment = static function () use ( $wpdb ): array {
	return array(
		'php' => PHP_VERSION,
		'wordpress' => get_bloginfo( 'version' ),
		'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		'database_server' => $wpdb->get_var( 'SELECT VERSION()' ),
		'database_host' => defined( 'DB_HOST' ) ? DB_HOST : '',
		'table_prefix' => $wpdb->prefix,
		'object_cache' => wp_using_ext_object_cache() ? 'external' : 'default',
		'wp_cli' => defined( 'WP_CLI_VERSION' ) ? WP_CLI_VERSION : null,
		'price_journal_schema_option' => get_option( 'writeleash_price_journal_schema', null ),
		'job_schema_option' => get_option( 'writeleash_job_schema', null ),
		'grant_schema_option' => get_option( 'writeleash_grant_schema', null ),
		'undo_schema_option' => get_option( 'writeleash_undo_schema', null ),
	);
};
$table_snapshot = static function ( string $table ) use ( $wpdb ): array {
	$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A );
	if ( ! is_array( $columns ) || ! is_array( $rows ) ) {
		throw new RuntimeException( '#283 cannot snapshot ' . $table );
	}
	return array(
		'table' => $table,
		'columns' => array_column( $columns, 'Field' ),
		'rows' => $rows,
		'hash' => hash( 'sha256', (string) wp_json_encode( $rows ) ),
	);
};
$option_snapshot = static function (): array {
	global $wpdb;
	$names = array(
		'writeleash_version', 'writeleash_runner_state',
		'writeleash_grant_schema', 'writeleash_grant_setup', 'writeleash_grants_disabled',
		'writeleash_job_schema', 'writeleash_job_setup',
		'writeleash_price_journal_schema',
		'writeleash_undo_schema', 'writeleash_undo_setup',
	);
	$out = array();
	foreach ( $names as $name ) {
		$out[ $name ] = get_option( $name, '__ABSENT__' );
	}
	$presets = array();
	$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'writeleash_price_preset_%' ORDER BY option_name", ARRAY_A );
	foreach ( (array) $rows as $row ) {
		$presets[ $row['option_name'] ] = $row['option_value'];
	}
	$out['__presets'] = $presets;
	return $out;
};
$product_snapshot = static function ( array $ids ): array {
	$out = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		Verifier::invalidate( $id );
		$product = wc_get_product( $id );
		if ( ! $product ) {
			throw new RuntimeException( '#283 product missing: ' . $id );
		}
		$regular = (string) $product->get_regular_price( 'edit' );
		$sale = (string) $product->get_sale_price( 'edit' );
		$fields = array(
			'regular_price' => $regular,
			'sale_price' => $sale,
			'regular_units' => '' === $regular ? '' : Decimal::parse( $regular ),
			'price_units' => '' === (string) $product->get_price( 'edit' ) ? '' : Decimal::parse( (string) $product->get_price( 'edit' ) ),
			'stock_status' => (string) $product->get_stock_status( 'edit' ),
			'stock_quantity' => null === $product->get_stock_quantity( 'edit' ) ? null : (string) $product->get_stock_quantity( 'edit' ),
			'manage_stock' => (string) $product->get_manage_stock( 'edit' ),
		);
		$meta = array();
		foreach ( array( '_regular_price', '_sale_price', '_price', '_stock', '_stock_status', '_manage_stock' ) as $key ) {
			$meta[ $key ] = get_post_meta( $id, $key, true );
		}
		$out[ $id ] = array(
			'fields' => $fields,
			'meta' => $meta,
			'hash' => hash( 'sha256', (string) wp_json_encode( array( $fields, $meta ) ) ),
		);
	}
	return $out;
};
$assert_columns = static function ( array $before, array $after, array $columns, string $label ): void {
	$after_rows = array();
	foreach ( $after['rows'] as $row ) {
		$after_rows[ (string) $row['id'] ] = $row;
	}
	foreach ( $before['rows'] as $row ) {
		$id = (string) $row['id'];
		if ( ! isset( $after_rows[ $id ] ) ) {
			throw new RuntimeException( $label . ': row ' . $id . ' disappeared' );
		}
		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $after_rows[ $id ] ) ) {
				throw new RuntimeException( $label . ': column ' . $column . ' disappeared on row ' . $id );
			}
			$current = $after_rows[ $id ][ $column ];
			if ( ( null === $row[ $column ] ) !== ( null === $current ) || ( null !== $row[ $column ] && (string) $row[ $column ] !== (string) $current ) ) {
				throw new RuntimeException( $label . ': row ' . $id . ' column ' . $column . ' changed' );
			}
		}
	}
	if ( count( $after['rows'] ) < count( $before['rows'] ) ) {
		throw new RuntimeException( $label . ': row count decreased' );
	}
};
$plan_for = static function ( array $job ): Plan {
	return Repo::hydrate_plan( $job );
};
$make_plan = static function ( array $ids, string $amount = '10', string $type = Operation::INCREASE_PERCENT ): Plan {
	return Planner::preview( Selection::ids( $ids ), new Operation( $type, $amount ), new Policy( 1000, '100', '100', true, '100' ) );
};
$run_job = static function ( int $job_id, int $max_rounds = 12 ): array {
	for ( $i = 0; $i < $max_rounds; ++$i ) {
		$job = Repo::read( $job_id );
		if ( ! $job ) {
			throw new RuntimeException( '#283 job disappeared: ' . $job_id );
		}
		if ( JobState::is_terminal( $job['status'] ) ) {
			return $job;
		}
		Worker::run( $job_id, array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
	}
	$job = Repo::read( $job_id );
	if ( ! $job || ! JobState::is_terminal( $job['status'] ) ) {
		throw new RuntimeException( '#283 job did not finish: ' . $job_id . ' status=' . ( $job['status'] ?? 'MISSING' ) );
	}
	return $job;
};
$run_undo = static function ( int $undo_id, int $max_rounds = 12 ): array {
	for ( $i = 0; $i < $max_rounds; ++$i ) {
		$operation = Undo::read_operation( $undo_id );
		if ( ! $operation ) {
			throw new RuntimeException( '#283 Undo operation disappeared: ' . $undo_id );
		}
		if ( UndoState::is_terminal( $operation['status'] ) ) {
			return $operation;
		}
		UndoWorker::run( $undo_id, array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
	}
	$operation = Undo::read_operation( $undo_id );
	if ( ! $operation || ! UndoState::is_terminal( $operation['status'] ) ) {
		throw new RuntimeException( '#283 Undo did not finish: ' . $undo_id . ' status=' . ( $operation['status'] ?? 'MISSING' ) );
	}
	return $operation;
};
$product = static function ( string $name, string $price ): int {
	$item = new WC_Product_Simple();
	$item->set_name( $name . ' ' . wp_generate_uuid4() );
	$item->set_status( 'publish' );
	$item->set_regular_price( $price );
	$item->set_manage_stock( true );
	$item->set_stock_quantity( 7 );
	$item->set_stock_status( 'instock' );
	$item->save();
	return (int) $item->get_id();
};
$set_price = static function ( int $id, string $price ): void {
	$item = wc_get_product( $id );
	if ( ! $item ) {
		throw new RuntimeException( '#283 product missing: ' . $id );
	}
	$item->set_regular_price( $price );
	$item->save();
	Verifier::invalidate( $id );
};
$user_by_login = static function ( string $login ): int {
	$user = get_user_by( 'login', $login );
	if ( $user instanceof WP_User ) {
		return (int) $user->ID;
	}
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 32, true, true ), 'user_email' => $login . '@example.test', 'role' => 'shop_manager' ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( '#283 cannot create actor: ' . $id->get_error_message() );
	}
	return (int) $id;
};
$journal_rows = static function ( string $plan_id, array $product_ids ) use ( $wpdb ): array {
	$rows = array();
	foreach ( $product_ids as $product_id ) {
		$rows[ (int) $product_id ] = Journal::read( $wpdb, $plan_id, (int) $product_id );
	}
	return $rows;
};
$pending_product = static function ( int $job_id ): int {
	foreach ( Repo::items( $job_id, 'PENDING', 0, 100 ) as $item ) {
		return (int) $item['product_id'];
	}
	throw new RuntimeException( '#283 no PENDING item in job ' . $job_id );
};

$source_meta = $zip_meta( $source_zip );
$receipt['schema'] = 'writeleash-upgrade-v03-receipt/1';
$receipt['mode'] = $mode;
$receipt['source'] = $source_meta;
$receipt['environment'] = $environment();

if ( 'seed' === $mode ) {
	$check( 'src-01', 'published source SHA-256 matches the recorded artifact', static function () use ( $source_meta, $source_sha ): string {
		if ( ! hash_equals( $source_sha, (string) $source_meta['sha256'] ) ) {
			throw new RuntimeException( 'source SHA-256 mismatch' );
		}
		return (string) $source_meta['sha256'];
	} );
	$check( 'src-02', 'published source version is exactly 0.2.2', static function () use ( $source_meta ): string {
		if ( '0.2.2' !== $source_meta['version'] ) {
			throw new RuntimeException( 'ZIP version is ' . $source_meta['version'] );
		}
		return 'header=' . $source_meta['header_version'] . ' constant=' . $source_meta['constant_version'];
	} );
	$check( 'src-03', 'installed source runtime is exactly 0.2.2', static function (): string {
		if ( ! defined( 'WRITELEASH_VERSION' ) || '0.2.2' !== WRITELEASH_VERSION ) {
			throw new RuntimeException( 'installed ' . ( defined( 'WRITELEASH_VERSION' ) ? WRITELEASH_VERSION : 'MISSING' ) );
		}
		return WRITELEASH_VERSION;
	} );
	if ( method_exists( Grants::class, 'is_restricted_actor' ) ) {
		$check( 'src-06', 'source can evaluate the #307 restriction predicate before upgrade', static function (): string {
			return 'available';
		} );
	} else {
		$blocked( 'src-06', 'source can evaluate the #307 restriction predicate before upgrade', '0.2.2 predates #307 and has no is_restricted_actor()' , 'pre-upgrade restriction state can only be asserted after migration' );
	}
	$skip( 'src-07', 'browser-rendered admin History on the source release', 'CLI fixture checks the server-side History renderer only; no browser is driven' );

	$actor = $user_by_login( 'wl283_actor' );
	$control_ids = array( $product( 'WL283 control A', '100' ), $product( 'WL283 control B', '100' ) );
	$completed_ids = array( $product( 'WL283 completed A', '100' ), $product( 'WL283 completed B', '100' ) );
	$resumable_ids = array( $product( 'WL283 resumable A', '100' ), $product( 'WL283 resumable B', '100' ), $product( 'WL283 resumable C', '100' ) );
	$conflict_ids = array( $product( 'WL283 conflict A', '100' ), $product( 'WL283 conflict B', '100' ), $product( 'WL283 conflict C', '100' ) );
	$grant_ids = array( $product( 'WL283 grant A', '100' ), $product( 'WL283 grant B', '100' ) );

	$completed_job = null;
	$resumable_job = null;
	$conflict_job = null;
	$conflict_product = 0;
	$undo_operation = null;
	$grant = null;
	$grant_job = null;
	$preset = null;
	$grant_key = 'wl283-idempotency';

	$check( 'seed-01', 'completed job applied every item through the supported worker', static function () use ( $completed_ids, $make_plan, $run_job, &$completed_job ): string {
		$job = Repo::create_from_plan( $make_plan( $completed_ids ), 1 );
		$job = Repo::approve( (int) $job['id'], 1 );
		$job = $run_job( (int) $job['id'] );
		$counts = Repo::counts( (int) $job['id'] );
		if ( 'COMPLETED' !== $job['status'] || 2 !== (int) $counts['applied'] || 0 !== (int) $counts['pending'] ) {
			throw new RuntimeException( 'status=' . $job['status'] . ' counts=' . wp_json_encode( $counts ) );
		}
		$completed_job = $job;
		return 'job=' . $job['id'] . ' applied=2';
	} );
	$check( 'seed-02', 'resumable job stops after one APPLIED item with two PENDING', static function () use ( $resumable_ids, $make_plan, &$resumable_job ): string {
		$job = Repo::create_from_plan( $make_plan( $resumable_ids ), 1 );
		$job = Repo::approve( (int) $job['id'], 1 );
		Worker::run( (int) $job['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
		$job = Repo::read( (int) $job['id'] );
		$counts = Repo::counts( (int) $job['id'] );
		if ( 1 !== (int) $counts['applied'] || 2 !== (int) $counts['pending'] || JobState::is_terminal( $job['status'] ) ) {
			throw new RuntimeException( 'status=' . $job['status'] . ' counts=' . wp_json_encode( $counts ) );
		}
		$resumable_job = $job;
		return 'job=' . $job['id'] . ' status=' . $job['status'] . ' applied=1 pending=2';
	} );
	$check( 'seed-03', 'resume-conflict job has one APPLIED item and a newer price on a PENDING item', static function () use ( $conflict_ids, $make_plan, $set_price, $pending_product, &$conflict_job, &$conflict_product ): string {
		$job = Repo::create_from_plan( $make_plan( $conflict_ids ), 1 );
		$job = Repo::approve( (int) $job['id'], 1 );
		Worker::run( (int) $job['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
		$counts = Repo::counts( (int) $job['id'] );
		if ( 1 !== (int) $counts['applied'] || 2 !== (int) $counts['pending'] ) {
			throw new RuntimeException( 'unexpected counts ' . wp_json_encode( $counts ) );
		}
		$conflict_product = $pending_product( (int) $job['id'] );
		$set_price( $conflict_product, '120' );
		$conflict_job = Repo::read( (int) $job['id'] );
		return 'job=' . $job['id'] . ' newer_price_product=' . $conflict_product . ' price=120';
	} );
	$check( 'seed-04', 'completed job Undo is initiated through the supported Undo repository', static function () use ( $completed_job, &$undo_operation ): string {
		if ( ! is_array( $completed_job ) ) {
			throw new RuntimeException( 'completed job missing' );
		}
		$operation = Undo::initiate( (int) $completed_job['id'], 1 );
		$counts = Undo::counts( (int) $operation['id'] );
		if ( 2 !== (int) $counts['eligible'] ) {
			throw new RuntimeException( 'eligible=' . ( $counts['eligible'] ?? '?' ) );
		}
		$undo_operation = $operation;
		return 'operation=' . $operation['id'] . ' eligible=2';
	} );
	$check( 'seed-05', 'standing grant acceptance creates reservations, idempotency and audit evidence', static function () use ( $actor, $grant_ids, $make_plan, $grant_key, $wpdb, &$grant, &$grant_job ): string {
		$grant = Grants::create_grant( 1, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 7 * 86400 ) );
		wp_set_current_user( $actor );
		try {
			$plan = $make_plan( $grant_ids, '5' );
		} finally {
			wp_set_current_user( 1 );
		}
		$accepted = Grants::accept_plan( $plan, (string) $grant['public_id'], 1, $grant_key );
		$grant_job = $accepted['job'];
		$reservations = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grant_db_id=%d', GrantSchema::reservations_table( $wpdb ), (int) $grant['id'] ) );
		$idempotency = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grant_db_id=%d', GrantSchema::idempotency_table( $wpdb ), (int) $grant['id'] ) );
		$audit = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grant_db_id=%d', GrantSchema::audit_table( $wpdb ), (int) $grant['id'] ) );
		if ( 2 !== $reservations || 1 !== $idempotency || $audit < 2 ) {
			throw new RuntimeException( 'reservations=' . $reservations . ' idempotency=' . $idempotency . ' audit=' . $audit );
		}
		return 'grant=' . $grant['public_id'] . ' reservations=2 idempotency=1 audit=' . $audit;
	} );
	$check( 'seed-06', 'preset is saved through the supported preset pipeline', static function () use ( $completed_ids, &$preset ): string {
		$form = array(
			'selector' => 'manual_ids',
			'ids' => (string) $completed_ids[0],
			'operation' => Operation::DECREASE_PERCENT,
			'amount' => '10',
			'ending' => '99',
			'price_field' => Operation::FIELD_REGULAR,
			'max_products' => '1000',
			'max_increase' => '50',
			'max_decrease' => '50',
			'warning_threshold' => '20',
			'block_zero' => '1',
		);
		$preset = Presets::save( 'WL283 upgrade preset', PresetConfig::capture( $form ) );
		if ( ! isset( $preset['id'] ) ) {
			throw new RuntimeException( 'preset id missing' );
		}
		return 'preset=' . $preset['id'];
	} );

	if ( ! is_array( $completed_job ) || ! is_array( $resumable_job ) || ! is_array( $conflict_job ) || ! is_array( $undo_operation ) || ! is_array( $grant ) || ! is_array( $grant_job ) || ! is_array( $preset ) || $conflict_product < 1 ) {
		throw new RuntimeException( '#283 seed did not produce every required case' );
	}

	// The source release creates its tables lazily, so verify the source shapes
	// only after the supported seed workflows have materialized them.
	$check( 'src-04', 'source schema versions are grant v1, journal v3, job v2', static function (): string {
		$grant = (int) get_option( 'writeleash_grant_schema', 0 );
		$journal = (int) get_option( 'writeleash_price_journal_schema', 0 );
		$job = (int) get_option( 'writeleash_job_schema', 0 );
		if ( 1 !== $grant || 3 !== $journal || 2 !== $job ) {
			throw new RuntimeException( 'observed grant=' . $grant . ' journal=' . $journal . ' job=' . $job );
		}
		return 'grant=1 journal=3 job=2';
	} );
	$check( 'src-05', 'source tables do not yet carry the target-only columns', static function () use ( $wpdb, $table_snapshot ): string {
		$grants = $table_snapshot( GrantSchema::grants_table( $wpdb ) );
		$journal = $table_snapshot( Journal::table( $wpdb ) );
		if ( in_array( 'restriction_removed_at', $grants['columns'], true ) ) {
			throw new RuntimeException( 'source grants already have restriction_removed_at' );
		}
		if ( in_array( 'asset', $journal['columns'], true ) ) {
			throw new RuntimeException( 'source journal already has asset' );
		}
		return 'no restriction_removed_at, no asset';
	} );

	$all_ids = array_merge( $control_ids, $completed_ids, $resumable_ids, $conflict_ids, $grant_ids );
	$receipt['before'] = array(
		'options' => $option_snapshot(),
		'tables' => array(
			'grants' => $table_snapshot( GrantSchema::grants_table( $wpdb ) ),
			'grant_reservations' => $table_snapshot( GrantSchema::reservations_table( $wpdb ) ),
			'grant_idempotency' => $table_snapshot( GrantSchema::idempotency_table( $wpdb ) ),
			'grant_audit' => $table_snapshot( GrantSchema::audit_table( $wpdb ) ),
			'jobs' => $table_snapshot( JobSchema::jobs_table( $wpdb ) ),
			'job_items' => $table_snapshot( JobSchema::items_table( $wpdb ) ),
			'price_items' => $table_snapshot( Journal::table( $wpdb ) ),
			'undo_operations' => $table_snapshot( UndoSchema::operations_table( $wpdb ) ),
			'undo_items' => $table_snapshot( UndoSchema::items_table( $wpdb ) ),
		),
		'products' => $product_snapshot( $all_ids ),
	);
	$receipt['cases'] = array(
		'actor_id' => $actor,
		'all_product_ids' => $all_ids,
		'control_product_ids' => $control_ids,
		'completed' => array(
			'job_id' => (int) $completed_job['id'],
			'plan_id' => (string) $completed_job['plan_id'],
			'plan_hash' => $plan_for( $completed_job )->hash(),
			'product_ids' => $completed_ids,
			'journal' => $journal_rows( (string) $completed_job['plan_id'], $completed_ids ),
			'undo_operation_id' => (int) $undo_operation['id'],
		),
		'resumable' => array(
			'job_id' => (int) $resumable_job['id'],
			'plan_id' => (string) $resumable_job['plan_id'],
			'plan_hash' => $plan_for( $resumable_job )->hash(),
			'product_ids' => $resumable_ids,
			'journal' => $journal_rows( (string) $resumable_job['plan_id'], $resumable_ids ),
		),
		'resume_conflict' => array(
			'job_id' => (int) $conflict_job['id'],
			'plan_id' => (string) $conflict_job['plan_id'],
			'plan_hash' => $plan_for( $conflict_job )->hash(),
			'product_ids' => $conflict_ids,
			'newer_price_product_id' => $conflict_product,
			'journal' => $journal_rows( (string) $conflict_job['plan_id'], $conflict_ids ),
		),
		'grant' => array(
			'job_id' => (int) $grant_job['id'],
			'plan_id' => (string) $grant_job['plan_id'],
			'plan_hash' => $plan_for( $grant_job )->hash(),
			'product_ids' => $grant_ids,
			'grant_db_id' => (int) $grant['id'],
			'grant_public_id' => (string) $grant['public_id'],
			'grant_idempotency_key' => $grant_key,
			'issuer_id' => (int) $grant['issuer_id'],
			'actor_id' => (int) $grant['actor_id'],
			'budget_max' => (int) $grant['budget_max'],
			'journal' => $journal_rows( (string) $grant_job['plan_id'], $grant_ids ),
		),
		'preset_id' => (string) $preset['id'],
	);
	$finish();
	echo "#283 seed complete; private receipt written\n";
	return;
}

if ( ! is_file( $receipt_path ) ) {
	throw new RuntimeException( '#283 receipt not found for ' . $mode );
}
$receipt = json_decode( (string) file_get_contents( $receipt_path ), true, 512, JSON_THROW_ON_ERROR );
if ( ! is_array( $receipt ) || 'writeleash-upgrade-v03-receipt/1' !== ( $receipt['schema'] ?? null ) ) {
	throw new RuntimeException( '#283 receipt is not a #283 receipt' );
}
$before = $receipt['before'];
$cases = $receipt['cases'];
$completed = $cases['completed'];
$resumable = $cases['resumable'];
$conflict = $cases['resume_conflict'];
$grant = $cases['grant'];
$source_expected = (string) ( $receipt['source']['sha256'] ?? '' );
$receipt['mode'] = $mode;
$receipt['target'] = $zip_meta( $target_zip );
$receipt['target']['expected_version'] = $target_version;
$receipt['environment_after_upgrade'] = $environment();

$check( 'src-11', 'source ZIP still matches the seeded 0.2.2 artifact', static function () use ( $source_meta, $source_expected, $source_sha ): string {
	if ( ! hash_equals( $source_expected, (string) $source_meta['sha256'] ) || ! hash_equals( $source_sha, (string) $source_meta['sha256'] ) ) {
		throw new RuntimeException( 'source artifact changed' );
	}
	if ( '0.2.2' !== $source_meta['version'] ) {
		throw new RuntimeException( 'source version is ' . $source_meta['version'] );
	}
	return (string) $source_meta['sha256'];
} );
$check( 'tgt-01', 'target ZIP SHA-256 is recorded and matches any supplied expectation', static function () use ( $receipt, $target_sha ): string {
	$actual = (string) $receipt['target']['sha256'];
	if ( '' !== $target_sha && ! hash_equals( $target_sha, $actual ) ) {
		throw new RuntimeException( 'target SHA-256 mismatch' );
	}
	return $actual;
} );
$check( 'tgt-02', 'target ZIP and installed runtime declare the expected v0.3.0 candidate version', static function () use ( $receipt, $target_version ): string {
	$meta = $receipt['target'];
	if ( '' === $meta['version'] ) {
		throw new RuntimeException( 'header/constant version disagree' );
	}
	if ( $meta['version'] !== $target_version ) {
		throw new RuntimeException( 'target version is ' . $meta['version'] );
	}
	if ( ! defined( 'WRITELEASH_VERSION' ) || WRITELEASH_VERSION !== $meta['version'] ) {
		throw new RuntimeException( 'installed target is ' . ( defined( 'WRITELEASH_VERSION' ) ? WRITELEASH_VERSION : 'MISSING' ) );
	}
	return 'header=' . $meta['header_version'] . ' constant=' . $meta['constant_version'];
} );
$check( 'env-01', 'installed WordPress and WooCommerce match the seed environment', static function () use ( $receipt ): string {
	$wp = get_bloginfo( 'version' );
	$wc = defined( 'WC_VERSION' ) ? WC_VERSION : '';
	if ( (string) $receipt['environment']['wordpress'] !== (string) $wp || (string) $receipt['environment']['woocommerce'] !== (string) $wc ) {
		throw new RuntimeException( 'WP/Woo changed since seed' );
	}
	return 'WP=' . $wp . ' Woo=' . $wc . ' PHP=' . PHP_VERSION;
} );

if ( 'negative' === $mode ) {
	$check( 'neg-01', 'activation did not migrate before the negative control', static function () use ( $post_activation_raw ): string {
		$state = json_decode( $post_activation_raw, true );
		if ( ! is_array( $state ) ) {
			throw new RuntimeException( 'WL283_POST_ACTIVATION_STATE missing or malformed' );
		}
		if ( 1 !== (int) ( $state['grant'] ?? -1 ) || 3 !== (int) ( $state['journal'] ?? -1 ) || 2 !== (int) ( $state['job'] ?? -1 ) ) {
			throw new RuntimeException( 'post-activation state=' . $post_activation_raw );
		}
		return $post_activation_raw;
	} );
	$corrupt = 'unknown-grant-schema' === $negative_kind ? 99 : 'not-a-number';
	$check( 'neg-02', 'corrupt grant precondition is rejected with a typed diagnostic and zero writes', static function () use ( $corrupt, $grant, $plan_for, $negative_kind ): string {
		update_option( 'writeleash_grant_schema', $corrupt, false );
		$plan = $plan_for( Repo::read( (int) $grant['job_id'] ) );
		$thrown = null;
		try {
			Grants::accept_plan( $plan, (string) $grant['grant_public_id'], 1, (string) $grant['grant_idempotency_key'] );
		} catch ( \Throwable $error ) {
			$thrown = $error;
		}
		if ( null === $thrown ) {
			throw new RuntimeException( 'corrupt precondition was accepted' );
		}
		$reason = $thrown instanceof \WriteLeash\Grant_Error ? $thrown->reason() : $thrown->getMessage();
		$setup = (string) get_option( 'writeleash_grant_setup', '' );
		if ( 'SCHEMA_UNAVAILABLE' !== $reason || 'SCHEMA_UNAVAILABLE' !== $setup ) {
			throw new RuntimeException( 'diagnostic reason=' . $reason . ' setup=' . $setup );
		}
		return 'kind=' . $negative_kind . ' reason=' . $reason . ' setup=' . $setup;
	} );
	$check( 'neg-03', 'refused migration leaves plugin rows and products untouched', static function () use ( $before, $product_snapshot, $receipt, $table_snapshot ): string {
		$now = array();
		foreach ( array( 'grants', 'grant_reservations', 'grant_idempotency', 'grant_audit', 'jobs', 'job_items', 'price_items' ) as $name ) {
			$now[ $name ] = $table_snapshot( $before['tables'][ $name ]['table'] );
		}
		foreach ( $now as $name => $snapshot ) {
			if ( $snapshot['hash'] !== $before['tables'][ $name ]['hash'] ) {
				throw new RuntimeException( $name . ' changed after the refused migration' );
			}
		}
		$products = $product_snapshot( $receipt['cases']['all_product_ids'] );
		foreach ( $products as $id => $row ) {
			if ( $row['hash'] !== $before['products'][ $id ]['hash'] ) {
				throw new RuntimeException( 'product ' . $id . ' changed after the refused migration' );
			}
		}
		return 'tables and products byte-identical';
	} );
	$check( 'neg-04', 'restoring the option recovers the supported migration path', static function () use ( $grant, $plan_for ): string {
		update_option( 'writeleash_grant_schema', 1, false );
		$accepted = Grants::accept_plan( $plan_for( Repo::read( (int) $grant['job_id'] ) ), (string) $grant['grant_public_id'], 1, (string) $grant['grant_idempotency_key'] );
		if ( true !== $accepted['replayed'] ) {
			throw new RuntimeException( 'recovery was not an idempotent replay' );
		}
		if ( 2 !== (int) get_option( 'writeleash_grant_schema', 0 ) ) {
			throw new RuntimeException( 'grant schema did not recover to v2' );
		}
		if ( ! Grants::is_restricted_actor( (int) $grant['actor_id'] ) ) {
			throw new RuntimeException( 'restriction predicate did not recover' );
		}
		return 'replayed=true grant_schema=2 restricted=true';
	} );
	$finish();
	echo "#283 negative control complete\n";
	return;
}

$check( 'mig-01', 'target activation left every source schema marker in place', static function () use ( $post_activation_raw ): string {
	$state = json_decode( $post_activation_raw, true );
	if ( ! is_array( $state ) ) {
		throw new RuntimeException( 'WL283_POST_ACTIVATION_STATE missing or malformed' );
	}
	$live = array(
		'grant' => (int) get_option( 'writeleash_grant_schema', 0 ),
		'journal' => (int) get_option( 'writeleash_price_journal_schema', 0 ),
		'job' => (int) get_option( 'writeleash_job_schema', 0 ),
	);
	if ( 1 !== $live['grant'] || 3 !== $live['journal'] || 2 !== $live['job'] ) {
		throw new RuntimeException( 'live state=' . wp_json_encode( $live ) );
	}
	foreach ( array( 'grant', 'journal', 'job' ) as $key ) {
		if ( (int) ( $state[ $key ] ?? -1 ) !== $live[ $key ] ) {
			throw new RuntimeException( 'driver post-activation state=' . $post_activation_raw . ' live=' . wp_json_encode( $live ) );
		}
	}
	return 'driver and live agree: grant=1 journal=3 job=2';
} );
$check( 'mig-02', 'journal v3 -> v4 migration is triggered by a supported idempotent job replay', static function () use ( $completed, $plan_for ): string {
	$job = Repo::read( (int) $completed['job_id'] );
	if ( ! $job ) {
		throw new RuntimeException( 'completed job missing' );
	}
	$plan = $plan_for( $job );
	if ( $plan->hash() !== $completed['plan_hash'] ) {
		throw new RuntimeException( 'plan hash changed before replay' );
	}
	$again = Repo::create_from_plan( $plan, 1 );
	if ( (int) $again['id'] !== (int) $completed['job_id'] ) {
		throw new RuntimeException( 'replay created a different job' );
	}
	if ( 4 !== (int) get_option( 'writeleash_price_journal_schema', 0 ) ) {
		throw new RuntimeException( 'journal schema option=' . get_option( 'writeleash_price_journal_schema', 'missing' ) );
	}
	return 'job=' . $again['id'] . ' journal_schema=4';
} );
$check( 'mig-03', 'grant v1 -> v2 migration is triggered by a supported idempotent acceptance replay', static function () use ( $grant, $plan_for ): string {
	$job = Repo::read( (int) $grant['job_id'] );
	if ( ! $job ) {
		throw new RuntimeException( 'grant job missing' );
	}
	$accepted = Grants::accept_plan( $plan_for( $job ), (string) $grant['grant_public_id'], 1, (string) $grant['grant_idempotency_key'] );
	if ( true !== $accepted['replayed'] || (int) $accepted['job']['id'] !== (int) $grant['job_id'] ) {
		throw new RuntimeException( 'acceptance replay did not return the original job' );
	}
	if ( 2 !== (int) get_option( 'writeleash_grant_schema', 0 ) ) {
		throw new RuntimeException( 'grant schema option=' . get_option( 'writeleash_grant_schema', 'missing' ) );
	}
	return 'grant_schema=2 replayed=true';
} );
$check( 'mig-04', 'neither lazy migration wrote a product or stock value', static function () use ( $before, $cases, $product_snapshot ): string {
	$now = $product_snapshot( $cases['all_product_ids'] );
	foreach ( $now as $id => $row ) {
		if ( $row['hash'] !== $before['products'][ $id ]['hash'] ) {
			throw new RuntimeException( 'product ' . $id . ' changed during migration' );
		}
	}
	return 'products byte-identical';
} );

$check( 'jrn-01', 'journal keeps every pre-v4 row and adds only the asset column', static function () use ( $before, $wpdb, $table_snapshot, $assert_columns ): string {
	$now = $table_snapshot( Journal::table( $wpdb ) );
	if ( ! in_array( 'asset', $now['columns'], true ) ) {
		throw new RuntimeException( 'asset column missing' );
	}
	$old_columns = array_values( array_diff( $before['tables']['price_items']['columns'], array( 'asset' ) ) );
	$assert_columns( $before['tables']['price_items'], $now, $old_columns, 'journal' );
	if ( count( $now['rows'] ) !== count( $before['tables']['price_items']['rows'] ) ) {
		throw new RuntimeException( 'journal row count changed' );
	}
	return 'rows=' . count( $now['rows'] ) . ' asset column added';
} );
$check( 'jrn-02', 'every pre-v4 journal row is backfilled with asset=price', static function () use ( $before, $wpdb, $table_snapshot ): string {
	$now = $table_snapshot( Journal::table( $wpdb ) );
	$by_id = array();
	foreach ( $now['rows'] as $row ) {
		$by_id[ (int) $row['id'] ] = $row;
	}
	foreach ( $before['tables']['price_items']['rows'] as $row ) {
		if ( ! isset( $by_id[ (int) $row['id'] ] ) || 'price' !== $by_id[ (int) $row['id'] ]['asset'] ) {
			throw new RuntimeException( 'row ' . $row['id'] . ' asset=' . ( $by_id[ (int) $row['id'] ]['asset'] ?? 'MISSING' ) );
		}
	}
	return 'asset=price on every pre-v4 row';
} );
$check( 'jrn-03', 'all pre-upgrade APPLIED journal evidence remains intact', static function () use ( $before, $wpdb, $table_snapshot ): string {
	$now = $table_snapshot( Journal::table( $wpdb ) );
	$by_id = array();
	foreach ( $now['rows'] as $row ) {
		$by_id[ (int) $row['id'] ] = $row;
	}
	$applied = 0;
	foreach ( $before['tables']['price_items']['rows'] as $row ) {
		if ( 'APPLIED' !== $row['state'] ) {
			continue;
		}
		++$applied;
		$current = $by_id[ (int) $row['id'] ] ?? null;
		if ( ! $current || 'APPLIED' !== $current['state'] ) {
			throw new RuntimeException( 'APPLIED row ' . $row['id'] . ' was not preserved' );
		}
		foreach ( array( 'attempt_id', 'evidence', 'expected_price', 'target_price', 'applied_at' ) as $column ) {
			if ( (string) $row[ $column ] !== (string) $current[ $column ] ) {
				throw new RuntimeException( 'APPLIED row ' . $row['id'] . ' column ' . $column . ' changed' );
			}
		}
	}
	return 'applied_rows=' . $applied;
} );
$check( 'grt-01', 'grant schema v2 adds the restriction marker without rewriting old grant evidence', static function () use ( $before, $wpdb, $table_snapshot, $assert_columns ): string {
	$now = $table_snapshot( GrantSchema::grants_table( $wpdb ) );
	if ( ! in_array( 'restriction_removed_at', $now['columns'], true ) ) {
		throw new RuntimeException( 'restriction_removed_at column missing' );
	}
	$old_columns = array_values( array_diff( $before['tables']['grants']['columns'], array( 'restriction_removed_at' ) ) );
	$assert_columns( $before['tables']['grants'], $now, $old_columns, 'grants' );
	if ( count( $now['rows'] ) !== count( $before['tables']['grants']['rows'] ) ) {
		throw new RuntimeException( 'grant row count changed' );
	}
	foreach ( $now['rows'] as $row ) {
		if ( null !== $row['restriction_removed_at'] ) {
			throw new RuntimeException( 'unexpected restriction-removal marker on grant ' . $row['id'] );
		}
	}
	return 'grants=' . count( $now['rows'] ) . ' marker column added';
} );
$check( 'grt-02', 'existing grant actors remain restricted and are not un-leashed', static function () use ( $grant ): string {
	if ( Grants::is_restricted_actor( (int) $grant['actor_id'] ) !== true || Grants::restriction_removed( (int) $grant['actor_id'] ) !== false ) {
		throw new RuntimeException( 'actor restriction predicate changed' );
	}
	return 'actor=' . $grant['actor_id'] . ' restricted=true removed=false';
} );
$check( 'grt-03', 'reservations, idempotency and audit rows are preserved exactly', static function () use ( $before, $wpdb, $table_snapshot ): string {
	foreach ( array( 'grant_reservations', 'grant_idempotency', 'grant_audit' ) as $name ) {
		$now = $table_snapshot( $before['tables'][ $name ]['table'] );
		if ( $now['hash'] !== $before['tables'][ $name ]['hash'] ) {
			throw new RuntimeException( $name . ' changed during migration' );
		}
	}
	return 'reservations, idempotency and audit unchanged';
} );
$check( 'job-01', 'durable job columns, item columns and plan hashes are preserved', static function () use ( $before, $wpdb, $table_snapshot, $assert_columns, $plan_for, $completed, $resumable, $conflict, $grant ): string {
	$jobs = $table_snapshot( JobSchema::jobs_table( $wpdb ) );
	$items = $table_snapshot( JobSchema::items_table( $wpdb ) );
	$job_columns = array( 'id', 'schema_version', 'public_id', 'plan_id', 'plan_schema_version', 'plan_hash_version', 'plan_hash', 'plan_json', 'creator_id', 'approver_id', 'currency', 'price_decimals', 'total_selected', 'total_eligible', 'total_changing', 'total_unchanged', 'total_unsupported', 'total_blocked', 'approval_mode', 'grant_public_id', 'grant_version', 'grant_idempotency_key', 'created_at', 'approved_at' );
	$item_columns = array( 'id', 'schema_version', 'job_id', 'plan_id', 'product_id', 'sequence', 'plan_result', 'eligibility_state', 'eligibility_reason', 'blockers', 'warnings', 'expected_price', 'planned_price', 'state', 'reason', 'applied_at', 'created_at' );
	$assert_columns( $before['tables']['jobs'], $jobs, $job_columns, 'jobs' );
	$assert_columns( $before['tables']['job_items'], $items, $item_columns, 'job_items' );
	foreach ( array( $completed, $resumable, $conflict, $grant ) as $case ) {
		$plan = $plan_for( Repo::read( (int) $case['job_id'] ) );
		if ( $plan->hash() !== $case['plan_hash'] ) {
			throw new RuntimeException( 'plan hash changed for job ' . $case['job_id'] );
		}
	}
	return 'durable jobs/items unchanged, 4 plan hashes match';
} );

$check( 'res-01', 'Resume completes the resumable job without rewriting the frozen plan', static function () use ( $resumable, $run_job, $plan_for ): string {
	$before_hash = $plan_for( Repo::read( (int) $resumable['job_id'] ) )->hash();
	$job = $run_job( (int) $resumable['job_id'] );
	$counts = Repo::counts( (int) $job['id'] );
	if ( 'COMPLETED' !== $job['status'] || 3 !== (int) $counts['applied'] || 0 !== (int) $counts['pending'] ) {
		throw new RuntimeException( 'status=' . $job['status'] . ' counts=' . wp_json_encode( $counts ) );
	}
	if ( $plan_for( Repo::read( (int) $job['id'] ) )->hash() !== $before_hash ) {
		throw new RuntimeException( 'plan hash changed during Resume' );
	}
	foreach ( $resumable['product_ids'] as $id ) {
		$price = Decimal::parse( (string) wc_get_product( $id )->get_regular_price( 'edit' ) );
		if ( '110' !== $price ) {
			throw new RuntimeException( 'product ' . $id . ' price=' . $price );
		}
	}
	return 'applied=3 price=110';
} );
$check( 'res-02', 'Resume-conflict marks the newer-price item CONFLICT and never overwrites it', static function () use ( $conflict, $run_job, $plan_for, $wpdb ): string {
	$before_hash = $plan_for( Repo::read( (int) $conflict['job_id'] ) )->hash();
	$job = $run_job( (int) $conflict['job_id'] );
	$counts = Repo::counts( (int) $job['id'] );
	if ( 'COMPLETED_WITH_ISSUES' !== $job['status'] || 2 !== (int) $counts['applied'] || 1 !== (int) $counts['conflict'] ) {
		throw new RuntimeException( 'status=' . $job['status'] . ' counts=' . wp_json_encode( $counts ) );
	}
	$newer = (int) $conflict['newer_price_product_id'];
	$newer_price = Decimal::parse( (string) wc_get_product( $newer )->get_regular_price( 'edit' ) );
	if ( '120' !== $newer_price ) {
		throw new RuntimeException( 'newer price overwritten: ' . $newer_price );
	}
	$row = Journal::read( $wpdb, (string) $job['plan_id'], $newer );
	if ( ! $row || 'CONFLICT' !== $row['state'] ) {
		throw new RuntimeException( 'conflict journal state=' . ( $row['state'] ?? 'MISSING' ) );
	}
	if ( $plan_for( Repo::read( (int) $job['id'] ) )->hash() !== $before_hash ) {
		throw new RuntimeException( 'plan hash changed during Resume' );
	}
	return 'applied=2 conflict=1 newer_price=120';
} );
$check( 'res-03', 'no pre-upgrade APPLIED evidence was lost or promoted falsely', static function () use ( $before, $wpdb, $table_snapshot ): string {
	$now = $table_snapshot( Journal::table( $wpdb ) );
	$by_id = array();
	foreach ( $now['rows'] as $row ) {
		$by_id[ (int) $row['id'] ] = $row;
	}
	foreach ( $before['tables']['price_items']['rows'] as $row ) {
		if ( 'APPLIED' !== $row['state'] ) {
			continue;
		}
		$current = $by_id[ (int) $row['id'] ] ?? null;
		if ( ! $current || 'APPLIED' !== $current['state'] || (string) $row['evidence'] !== (string) $current['evidence'] ) {
			throw new RuntimeException( 'APPLIED evidence changed for row ' . $row['id'] );
		}
	}
	return 'pre-upgrade APPLIED evidence intact';
} );

$check( 'undo-01', 'pre-upgrade Undo eligibility matches the completed job applied items', static function () use ( $completed ): string {
	$operation = Undo::read_operation( (int) $completed['undo_operation_id'] );
	if ( ! $operation ) {
		throw new RuntimeException( 'Undo operation missing' );
	}
	$counts = Undo::counts( (int) $operation['id'] );
	if ( 2 !== (int) $counts['eligible'] || 0 !== (int) $counts['undone'] ) {
		throw new RuntimeException( 'counts=' . wp_json_encode( $counts ) );
	}
	return 'operation=' . $operation['id'] . ' eligible=2 undone=0';
} );
$check( 'undo-02', 'Undo restores the completed job applied items', static function () use ( $completed, $run_undo ): string {
	$operation = $run_undo( (int) $completed['undo_operation_id'] );
	$counts = Undo::counts( (int) $operation['id'] );
	if ( 'UNDO_COMPLETED' !== $operation['status'] || 2 !== (int) $counts['undone'] || 0 !== (int) $counts['conflict'] ) {
		throw new RuntimeException( 'status=' . $operation['status'] . ' counts=' . wp_json_encode( $counts ) );
	}
	foreach ( $completed['product_ids'] as $id ) {
		$price = Decimal::parse( (string) wc_get_product( $id )->get_regular_price( 'edit' ) );
		if ( '100' !== $price ) {
			throw new RuntimeException( 'product ' . $id . ' price=' . $price );
		}
	}
	return 'undone=2 prices=100';
} );
$check( 'undo-03', 'the conflicted newer-price item never becomes Undo-eligible or overwritten', static function () use ( $conflict, $run_undo, $wpdb ): string {
	$operation = Undo::initiate( (int) $conflict['job_id'], 1 );
	$counts = Undo::counts( (int) $operation['id'] );
	if ( 2 !== (int) $counts['eligible'] || 0 !== (int) $counts['undone'] ) {
		throw new RuntimeException( 'conflict job counts=' . wp_json_encode( $counts ) );
	}
	$newer = (int) $conflict['newer_price_product_id'];
	if ( null !== Undo::read_item( $wpdb, (int) $conflict['job_id'], $newer ) ) {
		throw new RuntimeException( 'conflicted item is Undo-eligible' );
	}
	$operation = $run_undo( (int) $operation['id'] );
	$counts = Undo::counts( (int) $operation['id'] );
	if ( 'UNDO_COMPLETED' !== $operation['status'] || 2 !== (int) $counts['undone'] || 0 !== (int) $counts['conflict'] ) {
		throw new RuntimeException( 'Undo status=' . $operation['status'] . ' counts=' . wp_json_encode( $counts ) );
	}
	if ( '120' !== Decimal::parse( (string) wc_get_product( $newer )->get_regular_price( 'edit' ) ) ) {
		throw new RuntimeException( 'conflicted newer price changed during Undo' );
	}
	return 'eligible=2 undone=2 conflicted=1 preserved=120';
} );

$check( 'pre-01', 'preset options are preserved byte-for-byte and load through the supported pipeline', static function () use ( $before, $cases, $option_snapshot ): string {
	$now = $option_snapshot();
	if ( $now['__presets'] !== $before['options']['__presets'] ) {
		throw new RuntimeException( 'preset options changed' );
	}
	$record = Presets::load( (string) $cases['preset_id'] );
	if ( ! isset( $record['configuration'] ) || ! is_array( PresetConfig::form( $record['configuration'] ) ) ) {
		throw new RuntimeException( 'preset did not load' );
	}
	return 'options unchanged, preset loadable';
} );
$check( 'pre-02', 'History renders from the durable job and Undo repositories', static function () use ( $completed ): string {
	$history = Undo::history_job( (int) $completed['job_id'] );
	if ( 2 !== (int) $history['apply']['applied'] ) {
		throw new RuntimeException( 'history applied count changed' );
	}
	$items = Undo::history_items( (int) $completed['job_id'] );
	if ( ! isset( $items['items'] ) || 2 !== count( $items['items'] ) ) {
		throw new RuntimeException( 'history item count changed' );
	}
	ob_start();
	try {
		Admin::render_view( 'history', '', 0 );
		$html = (string) ob_get_clean();
	} catch ( \Throwable $error ) {
		ob_end_clean();
		throw $error;
	}
	if ( false === strpos( $html, 'History' ) || false !== strpos( $html, 'History is unavailable' ) ) {
		throw new RuntimeException( 'history view did not render' );
	}
	return 'history_job=2 items, rendered History HTML';
} );

$check( 'wri-01', 'no unsolicited product writes occurred outside Resume and Undo', static function () use ( $before, $cases, $product_snapshot ): string {
	$now = $product_snapshot( $cases['all_product_ids'] );
	$expected = array();
	foreach ( $cases['control_product_ids'] as $id ) {
		$expected[ (int) $id ] = '100';
	}
	foreach ( $cases['completed']['product_ids'] as $id ) {
		$expected[ (int) $id ] = '100';
	}
	foreach ( $cases['resumable']['product_ids'] as $id ) {
		$expected[ (int) $id ] = '110';
	}
	foreach ( $cases['resume_conflict']['product_ids'] as $id ) {
		$expected[ (int) $id ] = (int) $id === (int) $cases['resume_conflict']['newer_price_product_id'] ? '120' : '100';
	}
	foreach ( $cases['grant']['product_ids'] as $id ) {
		$expected[ (int) $id ] = '100';
	}
	foreach ( $now as $id => $row ) {
		if ( ! isset( $expected[ $id ] ) ) {
			throw new RuntimeException( 'unexpected product ' . $id );
		}
		if ( $row['fields']['regular_units'] !== $expected[ $id ] ) {
			throw new RuntimeException( 'product ' . $id . ' price=' . $row['fields']['regular_units'] . ' expected=' . $expected[ $id ] );
		}
		if ( $row['fields']['stock_status'] !== $before['products'][ $id ]['fields']['stock_status'] || $row['fields']['stock_quantity'] !== $before['products'][ $id ]['fields']['stock_quantity'] ) {
			throw new RuntimeException( 'product ' . $id . ' stock changed' );
		}
	}
	return 'control/grant/stock unchanged; expected Resume/Undo prices only';
} );

$finish();
echo "#283 upgrade verification complete\n";
