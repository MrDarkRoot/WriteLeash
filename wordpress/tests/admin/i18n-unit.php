<?php
// Exercise the existing #204 read-only boundary model in both presentation locales.
require __DIR__ . '/progress-unit.php';
use WriteLeash\Free_Admin as A;
require_once __DIR__ . '/../../writeleash/includes/free/class-product-snapshot.php';
$english = A::progress_snapshot( $post, 'POST' );
$GLOBALS['wl210_prefix'] = '[Ü] ';
$localized = A::progress_snapshot( $post, 'POST' );
foreach ( array( 'status', 'counts', 'undo_counts', 'resume_available', 'undo_available', 'resume_nonce', 'undo_nonce', 'poll', 'next_url' ) as $key ) { check204( $localized[$key], $english[$key], 'localized polling machine contract: ' . $key ); }
foreach ( $localized['rows'] as $i => $row ) { foreach ( array( 'id', 'apply_attention', 'undo_attention' ) as $key ) { check204( $row[$key], $english['rows'][$i][$key], 'localized row machine contract' ); } }
foreach ( array( 0, 1, 3 ) as $count ) {
	check204( A::count_copy( 'conflict', $count ), '[Ü] ' . $count . ( 1 === $count ? ' conflict' : ' conflicts' ), 'localized plural conflicts' );
	check204( A::count_copy( 'review', $count ), '[Ü] ' . $count . ( 1 === $count ? ' needs checking' : ' need checking' ), 'localized plural review' );
	$message = A::selection_count_message( array( 'selected' => $count, 'unreadable' => 0, 'missing' => 0 ) );
	check204( str_starts_with( $message, '[Ü] ' ), true, 'effective translated selection count' );
	check204( str_contains( $message, '%' ), false, 'placeholders substituted' );
}
check204( str_starts_with( A::reason_message( 'invalid_nonce' ), '[Ü] ' ), true, 'localized session error' );
check204( str_starts_with( A::item_label( 'CONFLICT', 'COMPLETED_WITH_ISSUES' ), '[Ü] ' ), true, 'localized conflict explanation' );
foreach ( array( WriteLeash\Price_Reason_Messages::all(), WriteLeash\Job_Reason::messages(), WriteLeash\Undo_Reason::messages() ) as $messages ) {
	foreach ( $messages as $code => $text ) { check204( str_starts_with( $text, '[Ü] ' ), true, 'every reason explanation uses writeleash: ' . $code ); }
}
$GLOBALS['wl210_prefix'] = '[Ü] <img src=x onerror=alert(1)> ';
ob_start(); ( new ReflectionMethod( A::class, 'render_item_outcome' ) )->invoke( null, 'FAILED', null, 'COMPLETED_WITH_ISSUES', array( 'context' => '' ) ); $html = ob_get_clean();
check204( str_contains( $html, '<img src=x' ), false, 'translated catalog text escaped at HTML boundary' );
unset( $GLOBALS['wl210_prefix'] );
echo "#210 localized presentation unit: count/placeholder/context, polling authority and escaping PASS\n";
