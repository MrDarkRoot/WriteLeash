<?php
// #120 deterministic public include closure and semantic dependency boundary.
// No plugin execution, recursive PHP publishing, autoloading or research loader.
function writeleash_public_runtime_audit( string $root, array $entries ): void {
	// PHP 7.4 tokenizes qualified names into separate namespace separators.
	$fully_qualified = defined( 'T_NAME_FULLY_QUALIFIED' ) ? constant( 'T_NAME_FULLY_QUALIFIED' ) : -120;
	$qualified = defined( 'T_NAME_QUALIFIED' ) ? constant( 'T_NAME_QUALIFIED' ) : -121;
	$fail = static function ( string $message ): void {
		throw new RuntimeException( '#120 public runtime audit: ' . $message );
	};
	$legacy = array(
		'Update_Engine', 'Guard_Error', 'Unsupported_Transaction_State', 'Budget_Denied',
		'Guard_Transaction', 'Guard_Sql', 'Guard_Monitor', 'Guard',
		'Compatibility_Grants', 'Compatibility_Doctor', 'Provisioning_Plan',
		'Redirection_Bulk_Disable', 'Redirection_Bulk_Disable_Rest', 'Certified_Operation',
		'Operation_Config', 'Certified_Operation_Status', 'Last_Outcome',
		'Disposable_Demo', 'Disposable_Demo_Setup', 'Product_Status', 'Admin_Page', 'Product_CLI',
	);
	foreach ( $entries as $entry ) {
		// Finite release files + reviewed common classes + Woo class directory.
		// The closure below further rejects even an unused PHP file here.
		if ( ! in_array( $entry, array( 'LICENSE', 'readme.txt', 'writeleash.php', 'uninstall.php', 'includes/free/free-selection.js', 'includes/free/free-selection.css',
			'includes/class-environment.php', 'includes/class-lifecycle.php', 'includes/class-plugin.php' ), true ) &&
			! preg_match( '#\Aincludes/free/class-[a-z-]+\.php\z#D', $entry ) ) {
			$fail( 'non-public artifact in manifest: ' . $entry );
		}
		if ( ! is_file( $root . '/' . $entry ) || is_link( $root . '/' . $entry ) ) {
			$fail( 'missing or symlinked public file: ' . $entry );
		}
	}
	foreach ( array( 'js', 'css' ) as $extension ) {
		$asset = 'includes/free/free-selection.' . $extension;
		if ( in_array( $asset, $entries, true ) && ! str_contains( (string) file_get_contents( $root . '/includes/free/class-free-admin.php' ), "plugins_url( '" . $asset . "', WRITELEASH_PLUGIN_FILE )" ) ) { $fail( 'unreferenced public selection asset' ); }
	}
	$pending = array( 'writeleash.php', 'uninstall.php' );
	$seen = array();
	$definitions = array();
	$references = array();
	while ( $pending ) {
		$entry = array_pop( $pending );
		if ( isset( $seen[ $entry ] ) ) { continue; }
		if ( ! in_array( $entry, $entries, true ) ) { $fail( 'required include omitted from manifest: ' . $entry ); }
		$seen[ $entry ] = true;
		$tokens = array_values( array_filter( token_get_all( file_get_contents( $root . '/' . $entry ) ),
			static fn( $token ) => ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) );
		if ( PHP_VERSION_ID < 80000 ) {
			$normalized = array();
			for ( $i = 0; $i < count( $tokens ); ++$i ) {
				$token = $tokens[ $i ];
				if ( is_array( $token ) && ( T_NS_SEPARATOR === $token[0] ||
					( T_STRING === $token[0] && isset( $tokens[ $i + 1 ][0] ) && T_NS_SEPARATOR === $tokens[ $i + 1 ][0] ) ) ) {
					$text = $token[1];
					while ( isset( $tokens[ $i + 1 ][0] ) && in_array( $tokens[ $i + 1 ][0], array( T_STRING, T_NS_SEPARATOR ), true ) ) {
						$text .= $tokens[ ++$i ][1];
					}
					$token = array( '\\' === $text[0] ? $fully_qualified : $qualified, $text );
				}
				$normalized[] = $token;
			}
			$tokens = $normalized;
		}
		$code = '';
		foreach ( $tokens as $i => $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			$code .= $text . ' ';
			if ( ! is_array( $token ) ) { continue; }
			if ( in_array( $token[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
				$expression = '';
				for ( $j = $i + 1; isset( $tokens[ $j ] ) && ';' !== $tokens[ $j ]; ++$j ) {
					$expression .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				}
				if ( "ABSPATH.'wp-admin/includes/upgrade.php'" === $expression ) { continue; }
				if ( ! preg_match( "#\\A__DIR__\\.'(/[a-z/-]+\\.php)'\\z#D", $expression, $match ) ) {
					$fail( 'non-literal/unreviewed include in ' . $entry . ': ' . $expression );
				}
				$path = ( 'writeleash.php' === $entry || 'uninstall.php' === $entry ? '' : dirname( $entry ) ) . $match[1];
				$pending[] = ltrim( $path, '/' );
			}
			if ( in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) &&
				isset( $tokens[ $i + 1 ][0] ) && T_STRING === $tokens[ $i + 1 ][0] ) {
				$name = $tokens[ $i + 1 ][1];
				if ( isset( $definitions[ $name ] ) ) { $fail( 'duplicate public class: ' . $name ); }
				$definitions[ $name ] = $entry;
			}
			// Same-namespace class/type/static/trait symbols are PascalCase.
			// Constants are ALL_CAPS; functions are lower case. External PHP/WP/Woo
			// classes must be explicitly fully qualified, as in the reviewed source.
			if ( T_STRING === $token[0] && preg_match( '/\A[A-Z][A-Za-z_]*[a-z][A-Za-z_]*\z/D', $text ) && 'WriteLeash' !== $text ) {
				$references[ $text ] = $entry;
			}
			if ( $fully_qualified === $token[0] && 0 === strpos( $text, '\\WriteLeash\\' ) ) {
				$references[ substr( $text, strlen( '\\WriteLeash\\' ) ) ] = $entry;
			}
			if ( $qualified === $token[0] ) { $fail( 'unreviewed relative qualified class dependency in ' . $entry ); }
			if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$literal = str_replace( '\\\\', '\\', trim( $text, "'\"" ) );
				if ( 0 === stripos( ltrim( $literal, '\\' ), 'WriteLeash\\' ) ) {
					$references[ substr( ltrim( $literal, '\\' ), strlen( 'WriteLeash\\' ) ) ] = $entry;
				}
			}
			if ( T_VARIABLE === $token[0] && isset( $tokens[ $i + 1 ][0] ) && T_DOUBLE_COLON === $tokens[ $i + 1 ][0] ) {
				$fail( 'dynamic static class dependency in ' . $entry );
			}
			if ( T_NEW === $token[0] && isset( $tokens[ $i + 1 ][0] ) && T_VARIABLE === $tokens[ $i + 1 ][0] ) {
				$fail( 'dynamic class dependency in ' . $entry );
			}
			// Audit exact semantic class symbols and string-held class names.
			// Comments (including ordinary English "guard") are not inspected.
			if ( in_array( $token[0], array( T_STRING, $qualified, $fully_qualified, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
				$identifier = ltrim( str_replace( '\\\\', '\\', trim( $text, "'\"" ) ), '\\' );
				if ( 0 === stripos( $identifier, 'WriteLeash\\' ) ) { $identifier = substr( $identifier, strlen( 'WriteLeash\\' ) ); }
				$identifier = explode( '::', $identifier, 2 )[0];
				foreach ( $legacy as $symbol ) {
					if ( 0 === strcasecmp( $symbol, $identifier ) ) {
						$fail( 'historical product symbol ' . $symbol . ' in ' . $entry );
					}
				}
			}
		}
		if ( preg_match( '/WRITELEASH_DB_(?:USER|PASSWORD|NAME|HOST)|operator-setup\.txt|rest_dispatch_request|admin_post_writeleash_action|WriteLeash Advanced|writeleash_v01_/', $code ) ) {
			$fail( 'historical authority/hook/path in ' . $entry );
		}
	}
	$manifest_php = array_values( array_filter( $entries, static fn( $entry ) => 'php' === pathinfo( $entry, PATHINFO_EXTENSION ) ) );
	$closure = array_keys( $seen );
	sort( $manifest_php, SORT_STRING ); sort( $closure, SORT_STRING );
	if ( $manifest_php !== $closure ) { $fail( 'manifest PHP is not exactly bootstrap + uninstall include closure' ); }
	foreach ( $references as $name => $entry ) {
		if ( ! isset( $definitions[ $name ] ) ) { $fail( 'unresolved public class/type ' . $name . ' in ' . $entry ); }
	}
	$readme = file_get_contents( $root . '/readme.txt' );
	if ( preg_match( '/Redirection|operator-setup|Doctor|WRITELEASH_DB_|GRANT|trusted.*(?:database|DB)/i', $readme ) ) {
		$fail( 'historical user requirement in public readme' );
	}
	echo '#120 public runtime: literal include closure ' . count( $closure ) . ' PHP files, ' . count( $definitions ) . " resolved classes/types; no historical artifacts/hooks/authority PASS\n";
}
