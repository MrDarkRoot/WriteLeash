<?php
// With WP_DEBUG enabled on the real adapter fixture, fail WriteLeash-originated
// PHP diagnostics without misattributing Plugin Check / core / Redirection noise.
if ( 3 !== $argc || ! in_array( $argv[2], array( 'mysql', 'mariadb' ), true ) ) {
	throw new RuntimeException( 'Usage: debug-audit.php debug.log engine' );
}
$log = is_file( $argv[1] ) ? file_get_contents( $argv[1] ) : '';
if ( ! is_string( $log ) ) {
	throw new RuntimeException( 'Cannot inspect WordPress debug log' );
}
$errors = array();
foreach ( explode( "\n", $log ) as $line ) {
	if ( preg_match( '/\b(?:PHP (?:Warning|Notice|Deprecated|Fatal|Parse error)|_doing_it_wrong|headers already sent)\b/i', $line ) &&
		str_contains( $line, '/wp-content/plugins/writeleash/' ) ) {
		$errors[] = $line;
	}
}
if ( $errors ) {
	throw new RuntimeException( '#62 WriteLeash-originated debug diagnostics: ' . implode( "\n", $errors ) );
}
echo '#62 ' . $argv[2] . ' WP_DEBUG: WriteLeash-originated Warning/Notice/Deprecated/fatal/doing_it_wrong/headers-sent=0 PASS' . "\n";
