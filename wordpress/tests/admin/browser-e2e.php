<?php
// #111 (Blocker 3) real-HTTP browser-equivalent Admin E2E.
//
// Drives the production WordPress request stack over HTTP with an
// authenticated cookie session: menu navigation, actual form POSTs to
// admin-post.php, nonce submission, PRG redirects, page reloads and a fresh
// re-login proving durable reconstruction. Never calls Free_Admin::
// process_*()/render_*() directly; those stay covered by integration.php.
// Fixture seeding uses Woo CRUD as shop setup before the journey starts;
// every journey assertion below is an HTTP status/redirect/HTML fact.
$base = (string) getenv( 'WL111_BASE_URL' );
$admin_password = (string) getenv( 'WL111_ADMIN_PASSWORD' );
if ( '' === $base || '' === $admin_password ) {
	throw new RuntimeException( '#111 browser E2E needs WL111_BASE_URL and WL111_ADMIN_PASSWORD' );
}
// eval-file scope: the global declaration first makes the assignments below
// (and every function-level global import) share one store.
global $wl111_base, $wl111_admin_password, $wl111_jar;
$wl111_base = $base;
$wl111_admin_password = $admin_password;
$wl111_jar = array();
function beq( $actual, $expected, string $label ): void {
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function bok( bool $condition, string $label ): void { beq( $condition, true, $label ); }
function http_request( string $method, string $url, array $params = array() ): array {
	global $wl111_jar;
	$args = array( 'method' => $method, 'timeout' => 90, 'redirection' => 0, 'cookies' => array_values( $wl111_jar ), 'sslverify' => false );
	if ( 'POST' === $method ) {
		$args['body'] = $params;
	} elseif ( $params ) {
		$url = add_query_arg( $params, $url );
	}
	$response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( 'HTTP ' . $method . ' failed: ' . $response->get_error_message() );
	}
	foreach ( (array) ( $response['cookies'] ?? array() ) as $cookie ) {
		if ( $cookie instanceof WP_Http_Cookie ) { $wl111_jar[ $cookie->name ] = $cookie; }
	}
	return array(
		'code' => wp_remote_retrieve_response_code( $response ),
		'location' => wp_remote_retrieve_header( $response, 'location' ),
		'body' => (string) wp_remote_retrieve_body( $response ),
		'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		'content_disposition' => (string) wp_remote_retrieve_header( $response, 'content-disposition' ),
	);
}
function http_get( string $url ): array { return http_request( 'GET', $url ); }
function http_post( string $url, array $params ): array { return http_request( 'POST', $url, $params ); }
/** Hidden fields of every form whose action input equals $action. */
function forms_for_action( string $html, string $action ): array {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	$matches = array();
	foreach ( $dom->getElementsByTagName( 'form' ) as $form ) {
		$fields = array();
		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			$name = $input->getAttribute( 'name' );
			if ( '' !== $name ) { $fields[ $name ] = $input->getAttribute( 'value' ); }
		}
		foreach ( $form->getElementsByTagName( 'select' ) as $select ) {
			$name = $select->getAttribute( 'name' );
			if ( '' !== $name && ! isset( $fields[ $name ] ) ) {
				$fields[ $name ] = $select->getAttribute( 'value' );
			}
		}
		if ( ( $fields['action'] ?? null ) === $action ) { $matches[] = $fields; }
	}
	return $matches;
}
/**
 * Structural keyboard/focus proof over fetched admin HTML: every workflow
 * control is a natively focusable element, every visible field is labelled,
 * notices are role=alert text, and disabled pagination is not actionable.
 */
function a11y_check( string $html, string $label ): void {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	$xpath = new DOMXPath( $dom );
	// Scope to the plugin content region: core admin chrome (bar, screen
	// options) is outside this subtree and outside this product's control.
	$roots = $xpath->query( '//*[@id="wpbody-content"]' );
	bok( 1 === $roots->length, $label . ' admin content region present' );
	$root = $roots->item( 0 );
	$sub = static function ( string $query ) use ( $xpath, $root ) {
		return $xpath->query( $query, $root );
	};
	foreach ( $sub( './/*[@onclick]' ) as $node ) {
		bok( false, $label . ' no scripted click controls' );
		break;
	}
	$labels = array();
	foreach ( $sub( './/label[@for]' ) as $node ) { $labels[ $node->getAttribute( 'for' ) ] = true; }
	foreach ( array( 'input', 'select', 'textarea' ) as $tag ) {
		foreach ( $sub( './/' . $tag ) as $node ) {
			$type = strtolower( $node->getAttribute( 'type' ) );
			if ( 'hidden' === $type || 'submit' === $type ) { continue; }
			$id = $node->getAttribute( 'id' );
			bok( '' !== $id && isset( $labels[ $id ] ), $label . " labelled control $tag#$id" );
			$tabindex = $node->getAttribute( 'tabindex' );
			bok( '' === $tabindex || (int) $tabindex >= 0, $label . ' no removed-from-tab-order control' );
		}
	}
	foreach ( $sub( './/a' ) as $node ) {
		bok( '' !== $node->getAttribute( 'href' ), $label . ' every link actionable with href' );
	}
	$notices = $sub( './/*[contains(@class,"notice")]' );
	if ( $notices->length > 0 ) {
		foreach ( $notices as $node ) {
			bok( 'alert' === $node->getAttribute( 'role' ), $label . ' notices announced as text alerts' );
		}
	}
	foreach ( $sub( './/button[@disabled]' ) as $node ) {
		bok( 'true' === $node->getAttribute( 'aria-disabled' ), $label . ' disabled controls expose aria-disabled' );
	}
}
function admin_url_abs( string $path ): string {
	global $wl111_base;
	return rtrim( $wl111_base, '/' ) . $path;
}
/**
 * Fixture plugins intercept the first wp-admin visit with a one-time setup
 * nudge (Woo onboarding, Redis Cache settings). Follow it at most once so
 * the journey underneath stays deterministic; anything else is reported.
 * Every follow is logged; the journey still asserts its own landing pages.
 */
function admin_get( string $url ): array {
	$response = http_get( $url );
	if ( in_array( $response['code'], array( 302, 303 ), true ) ) {
		$location = (string) $response['location'];
		if ( 1 === preg_match( '/page=wc-admin|wc-setup|setup-wizard|page=redis-cache/', $location ) ) {
			echo "#111 browser note: consumed one fixture setup intercept, continuing\n";
			http_get( $location );
			$response = http_get( $url );
		}
	}
	return $response;
}
function login_session(): void {
	global $wl111_jar, $wl111_base, $wl111_admin_password;
	$wl111_jar = array();
	$login = http_get( admin_url_abs( '/wp-login.php' ) );
	beq( $login['code'], 200, 'login page reachable' );
	$auth = http_post(
		admin_url_abs( '/wp-login.php' ),
		array( 'log' => 'admin', 'pwd' => $wl111_admin_password, 'wp-submit' => 'Log In', 'redirect_to' => admin_url_abs( '/wp-admin/' ), 'testcookie' => '1' )
	);
	$logged_in = false;
	foreach ( $wl111_jar as $name => $cookie ) {
		if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) ) { $logged_in = true; }
	}
	bok( $logged_in, 'authenticated session cookie issued' );
	bok( in_array( $auth['code'], array( 302, 303 ), true ), 'login redirects (PRG)' );
}

// Shop setup (not the journey): twelve simple products at 100.00.
wp_set_current_user( 1 );
$tag = 'WL111B-' . wp_generate_uuid4();
$ids = array();
for ( $i = 0; $i < 12; ++$i ) {
	$p = new WC_Product_Simple();
	$p->set_name( $tag . '-' . $i );
	$p->set_status( 'publish' );
	$p->set_regular_price( '100.00' );
	$p->save();
	$ids[] = $p->get_id();
}
sort( $ids, SORT_NUMERIC );

// The measured first-plan browser journey starts at opening Bulk Prices.
// Woo re-arms its one-time 30-second onboarding intercept on every
// activation; clear it deterministically so the journey does not race its
// TTL. (Real admins complete onboarding; this is fixture setup, and the
// loader below still logs any intercept it ever meets.)
delete_transient( '_wc_activation_redirect' );
login_session();
$journey_start = microtime( true );
$bulk_url = admin_url_abs( '/wp-admin/admin.php?page=writeleash-bulk-prices' );
$home = admin_get( $bulk_url );
if ( 200 !== $home['code'] ) {
	throw new RuntimeException( 'Bulk Prices GET returned ' . $home['code'] . ' location=' . (string) $home['location'] . ' body=' . substr( $home['body'], 0, 500 ) );
}
bok( str_contains( $home['body'], 'WriteLeash Bulk Prices' ), 'menu navigation lands on Bulk Prices' );
bok( str_contains( $home['body'], 'Build frozen preview' ), 'preview form present' );
a11y_check( $home['body'], 'bulk prices home' );
$preview_forms = forms_for_action( $home['body'], 'writeleash_free_preview' );
beq( count( $preview_forms ), 1, 'exactly one preview form' );
bok( isset( $preview_forms[0]['_wpnonce'] ) && '' !== $preview_forms[0]['_wpnonce'], 'preview nonce rendered' );

// Submit the preview form over HTTP.
$preview = http_post(
	admin_url_abs( '/wp-admin/admin-post.php' ),
	array(
		'action' => 'writeleash_free_preview',
		'selector' => 'ids',
		'ids' => implode( ',', $ids ),
		'operation' => 'SET',
		'amount' => '80.00',
		'max_products' => '100',
		'max_increase' => '500',
		'max_decrease' => '500',
		'warning_threshold' => '20',
		'_wpnonce' => $preview_forms[0]['_wpnonce'],
	)
);
bok( in_array( $preview['code'], array( 302, 303 ), true ), 'preview POST redirects (PRG)' );
if ( ! (bool) preg_match( '/wl_view=preview&wl_job=([0-9a-f-]{36})/', (string) $preview['location'], $match ) ) {
	$debug_body = is_string( $preview['location'] ) && '' !== $preview['location'] ? substr( admin_get( (string) $preview['location'] )['body'], 0, 3000 ) : '(no location)';
	throw new RuntimeException( 'preview redirect without job ref; landing says: ' . $debug_body );
}
$public_id = $match[1];
$preview_page = admin_get( (string) $preview['location'] );
beq( $preview_page['code'], 200, 'frozen preview renders' );
bok( str_contains( $preview_page['body'], 'Review price change' ), 'preview heading' );
bok( str_contains( $preview_page['body'], '12 planned changes' ), 'preview changing count' );
bok( str_contains( $preview_page['body'], '80.00' ), 'frozen target visible' );
a11y_check( $preview_page['body'], 'frozen preview' );
echo '#111 browser first-plan journey: ' . round( microtime( true ) - $journey_start, 2 ) . "s (Bulk Prices open to frozen preview render)\n";

// Approve the exact plan over HTTP, then follow to durable progress.
$approve_forms = forms_for_action( $preview_page['body'], 'writeleash_free_approve' );
beq( count( $approve_forms ), 1, 'exactly one approval form bound to the plan' );
$approve = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $approve_forms[0] );
bok( in_array( $approve['code'], array( 302, 303 ), true ), 'approval POST redirects (PRG)' );
bok( str_contains( (string) $approve['location'], 'wl_view=job' ), 'approval lands on durable progress' );
$job_url = (string) $approve['location'];
$job_page = admin_get( $job_url );
beq( $job_page['code'], 200, 'durable progress renders' );
bok( str_contains( $job_page['body'], '12 remaining' ), 'queued work visible, nothing implied applied' );
a11y_check( $job_page['body'], 'durable progress' );

// Bounded manual resume over HTTP: 10 of 12, then the remainder.
$resume_forms = forms_for_action( $job_page['body'], 'writeleash_free_resume' );
beq( count( $resume_forms ), 1, 'resume control present while work remains' );
$resume1 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $resume_forms[0] );
bok( in_array( $resume1['code'], array( 302, 303 ), true ), 'resume POST redirects (PRG)' );
$job_page = admin_get( $job_url );
bok( str_contains( $job_page['body'], '10 changed' ), 'first bounded chunk 10 changed' );
bok( str_contains( $job_page['body'], '2 remaining' ), 'remainder stays pending, never success' );
// Reopen with a brand-new authenticated session: identical durable truth.
login_session();
$reopened = admin_get( $job_url );
bok( str_contains( $reopened['body'], '10 changed' ), 'reopened session sees 10 changed' );
bok( str_contains( $reopened['body'], '2 remaining' ), 'reopened session sees 2 remaining' );
$resume_forms = forms_for_action( $reopened['body'], 'writeleash_free_resume' );
beq( count( $resume_forms ), 1, 'resume control present for remainder' );
http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $resume_forms[0] );
$done = admin_get( $job_url );
bok( str_contains( $done['body'], 'COMPLETED' ), 'resumed job completes' );
bok( str_contains( $done['body'], '12 changed' ), 'all items applied' );
bok( 0 === count( forms_for_action( $done['body'], 'writeleash_free_resume' ) ), 'no resume control once terminal' );

// History over HTTP, then Undo over HTTP in two bounded chunks.
$history = admin_get( admin_url_abs( '/wp-admin/admin.php?page=writeleash-bulk-prices&wl_view=history' ) );
beq( $history['code'], 200, 'history renders' );
bok( str_contains( $history['body'], substr( $public_id, 0, 8 ) ), 'history links the job' );
a11y_check( $history['body'], 'history' );
$undo_forms = forms_for_action( $done['body'], 'writeleash_free_undo' );
beq( count( $undo_forms ), 1, 'Restore offered exactly once for the eligible job' );
http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $undo_forms[0] );
$after_undo1 = admin_get( $job_url );
bok( str_contains( $after_undo1['body'], '10 restored' ), 'first Undo chunk restored 10' );
$undo_forms = forms_for_action( $after_undo1['body'], 'writeleash_free_undo' );
if ( $undo_forms ) {
	// A second bounded chunk finishes only when items remain; otherwise the
	// section already reports the terminal operation with no Restore form.
	http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $undo_forms[0] );
	$after_undo1 = admin_get( $job_url );
}
bok( str_contains( $after_undo1['body'], '12 restored' ), 'Undo restored all eligible prices' );
bok( 0 === count( forms_for_action( $after_undo1['body'], 'writeleash_free_undo' ) ), 'no Restore form after finished Undo' );

// Negatives over HTTP: GET mutation refused with zero mutation, bad nonce refused.
$before_neg = admin_get( $job_url );
$get_mutation = http_request( 'GET', admin_url_abs( '/wp-admin/admin-post.php' ), array( 'action' => 'writeleash_free_resume', 'job' => $public_id ) );
bok( in_array( $get_mutation['code'], array( 302, 303 ), true ), 'GET mutation redirects, never executes' );
$get_notice = admin_get( (string) $get_mutation['location'] );
bok( str_contains( $get_notice['body'], 'authenticated POST' ), 'GET refusal explained as text' );
a11y_check( $get_notice['body'], 'refusal notice' );
$after_neg = admin_get( $job_url );
// WordPress core prints a per-second clock (userSettings.time) into every
// admin page; normalize that volatile chrome before proving zero change.
$normalize = static function ( string $html ): string {
	return (string) preg_replace( '/"time":"\d+"/', '"time":"T"', $html );
};
beq( $normalize( $after_neg['body'] ), $normalize( $before_neg['body'] ), 'GET mutation changed nothing' );
$bad_nonce = http_post(
	admin_url_abs( '/wp-admin/admin-post.php' ),
	array( 'action' => 'writeleash_free_resume', 'job' => $public_id, '_wpnonce' => 'bad-nonce-value' )
);
bok( in_array( $bad_nonce['code'], array( 302, 303 ), true ), 'bad nonce redirects, never executes' );
$bad_page = admin_get( (string) $bad_nonce['location'] );
bok( str_contains( $bad_page['body'], 'security token' ), 'bad nonce explained as text' );

// Supplementary price-level truth (the journey assertions above are HTTP).
foreach ( $ids as $id ) {
	$p = wc_get_product( $id );
	if ( (string) $p->get_regular_price( 'edit' ) !== '100.00' ) {
		throw new RuntimeException( 'Undo did not restore product ' . $id );
	}
}
require __DIR__ . '/selection-http.php';
require __DIR__ . '/presentation-http.php';

echo "#111 real-HTTP browser Admin E2E (menu, POST, nonce, PRG, reload, resume, history, Undo, negatives): PASS\n";
