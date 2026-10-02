<?php
function wl112_assert( bool $ok, string $label ): void {
    if ( ! $ok ) { throw new RuntimeException( $label ); }
}
function wl112_http( string $method, string $url, array $fields = array() ): array {
    global $wl112_cookies;
    $start = microtime( true );
    $response = wp_remote_request( $url, array( 'method' => $method, 'body' => $fields, 'cookies' => array_values( $wl112_cookies ), 'timeout' => 120, 'redirection' => 0 ) );
    if ( is_wp_error( $response ) ) { throw new RuntimeException( 'HTTP: ' . $response->get_error_message() ); }
    foreach ( $response['cookies'] as $cookie ) { $wl112_cookies[$cookie->name] = $cookie; }
    return array( 'seconds' => microtime( true ) - $start, 'code' => wp_remote_retrieve_response_code( $response ), 'location' => wp_remote_retrieve_header( $response, 'location' ), 'body' => wp_remote_retrieve_body( $response ) );
}
function wl112_get( string $url ): array {
    $r = wl112_http( 'GET', $url );
    // Record and consume only known, one-time third-party onboarding redirects.
    if ( in_array( $r['code'], array( 302, 303 ), true ) && preg_match( '/page=wc-admin|wc-setup|setup-wizard|page=redis-cache/', $r['location'] ) ) {
        wl112_http( 'GET', $r['location'] );
        $r = wl112_http( 'GET', $url );
    }
    wl112_assert( 200 === $r['code'], 'GET did not render Admin' );
    return $r;
}
function wl112_login( string $user = 'admin' ): void {
    global $wl112_cookies;
    $wl112_cookies = array();
    wl112_http( 'GET', 'http://127.0.0.1:8080/wp-login.php' );
    $r = wl112_http( 'POST', 'http://127.0.0.1:8080/wp-login.php', array( 'log' => $user, 'pwd' => 'disposable_admin_password', 'testcookie' => '1' ) );
    wl112_assert( in_array( $r['code'], array( 302, 303 ), true ), 'login failed' );
    wl112_assert( (bool) array_filter( array_keys( $wl112_cookies ), static function ( $k ) { return 0 === strpos( $k, 'wordpress_logged_in_' ); } ), 'no authenticated cookie' );
}
function wl112_form( string $html, string $action ): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors( true );
    $dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    foreach ( $dom->getElementsByTagName( 'form' ) as $form ) {
        $fields = array();
        foreach ( $form->getElementsByTagName( 'input' ) as $input ) { $fields[$input->getAttribute( 'name' )] = $input->getAttribute( 'value' ); }
        if ( ( $fields['action'] ?? '' ) === $action ) { return $fields; }
    }
    throw new RuntimeException( 'missing Admin form ' . $action );
}
function wl112_post( array $fields ): array {
    $r = wl112_http( 'POST', 'http://127.0.0.1:8080/wp-admin/admin-post.php', $fields );
    wl112_assert( in_array( $r['code'], array( 302, 303 ), true ), 'POST did not PRG' );
    return $r;
}
function wl112_rows( string $html ): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors( true );
    $dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
    libxml_clear_errors();
    $xp = new DOMXPath( $dom );
    $ids = array();
    foreach ( $xp->query( '//table/tbody/tr/td[1]' ) as $td ) {
        if ( preg_match( '/^([0-9]+)/', trim( $td->textContent ), $m ) ) { $ids[] = (int) $m[1]; }
    }
    return $ids;
}
/** Test-only nonce bound to the real HTTP login, even when no form is exposed. */
function wl112_nonce( string $action ): string {
    global $wl112_cookies;
    wl112_assert( isset( $wl112_cookies[LOGGED_IN_COOKIE] ), 'logged-in cookie required for crafted authenticated request' );
    $_COOKIE[LOGGED_IN_COOKIE] = rawurldecode( $wl112_cookies[LOGGED_IN_COOKIE]->value );
    wp_set_current_user( 1 );
    return wp_create_nonce( $action );
}
/** Exact owned evidence counts; missing tables count as zero, no DDL. */
function wl112_evidence_rows(): array {
    global $wpdb;
    $counts = array();
    foreach ( array( 'writeleash_jobs', 'writeleash_job_items', 'writeleash_price_items', 'writeleash_undo_operations', 'writeleash_undo_items' ) as $suffix ) {
        $table = $wpdb->prefix . $suffix;
        $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
        $counts[$suffix] = $exists ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) : 0;
    }
    return $counts;
}
