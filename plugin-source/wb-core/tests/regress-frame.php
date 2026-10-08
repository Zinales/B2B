<?php
/**
 * Regression test for 0.3.0 (Zina, 7 October 2026): the workspace frame renders for every kind of
 * login, on every screen, with WordPress stood in for. BUILD-PATTERNS.md §2.2 (Kaycie's harness):
 * a one-off version of this caught a fatal (mb_strtoupper on a build without mbstring) that the
 * pure-function tests could not. Any PHP notice, warning or fatal here fails the build.
 *
 *   php tests/regress-frame.php
 *
 * What it checks, for owner / sales / warehouse / accounts / plain staff / customer logins:
 *  - every screen in WB_Workspace::SCREENS and the portal returns 200 with content, or 403 with
 *    the no-access words (never a blank page), and an unknown address returns 404;
 *  - the side menu lists only screens the login can open, and marks the current one;
 *  - the "Next" links only point at screens the login can open;
 *  - the no-access page names a way back for anyone who has one.
 * Shortcodes are stood in for by a marker (their output is the engines' business, tested elsewhere).
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler( function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );

define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WB_PLUGIN_FILE', WB_PLUGIN_DIR . 'wb-core.php' );
define( 'WB_VERSION', '0.3.0-test' );
define( 'KB_IN_BYTES', 1024 );

/* ---------- the stand-ins: just enough WordPress for the frame ---------- */
$GLOBALS['T'] = [ 'caps' => [], 'uid' => 5, 'logged_in' => true ];
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function wp_kses_post( $s ) { return $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function home_url( $p = '' ) { return 'https://b2b.test' . $p; }
function current_user_can( $c ) { return ! empty( $GLOBALS['T']['caps'][ $c ] ); }
function user_can( $u, $c ) { return current_user_can( $c ); }
function is_user_logged_in() { return $GLOBALS['T']['logged_in']; }
function get_current_user_id() { return $GLOBALS['T']['uid']; }
function wp_get_current_user() { return (object) [ 'ID' => $GLOBALS['T']['uid'], 'display_name' => 'Thandi Mokoena' ]; }
function get_userdata( $id ) { return (object) [ 'ID' => $id, 'display_name' => 'Owner ' . $id ]; }
function get_users( $args = [] ) { return [ 1, 2 ]; }   // owner_ids(): two owners
function wp_logout_url( $r = '' ) { return 'https://b2b.test/logout'; }
function wp_login_url( $r = '' ) { return 'https://b2b.test/wp-login.php?redirect_to=' . rawurlencode( (string) $r ); }
function wp_login_form( $a = [] ) { return '<form id="' . $a['form_id'] . '" class="login-form"><p><label for="u">' . $a['label_username'] . '</label><input id="u" type="text" name="log"></p><p><label for="p">' . $a['label_password'] . '</label><input id="p" type="password" name="pwd"></p><p class="login-remember"><label><input type="checkbox" name="rememberme"> ' . $a['label_remember'] . '</label></p><p><input type="submit" value="' . $a['label_log_in'] . '"><input type="hidden" name="redirect_to" value="' . esc_attr( $a['redirect'] ) . '"></p></form>'; }
function wp_lostpassword_url( $r = '' ) { return 'https://b2b.test/wp-login.php?action=lostpassword'; }
function wp_validate_redirect( $u, $d = '' ) { return 0 === strpos( (string) $u, 'https://b2b.test/' ) ? $u : $d; }
function wp_unslash( $v ) { return $v; }
function wp_get_referer() { return ''; }
function is_admin() { return false; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function language_attributes() { echo 'lang="en"'; }
function bloginfo( $k ) { echo 'UTF-8'; }
function get_bloginfo( $k ) { return 'Demo Technical Supplies'; }
function wp_head() { echo '<!--head-->'; }
function wp_body_open() {}
function wp_footer() { echo '<!--foot-->'; }
function do_shortcode( $s ) { return '<div data-shortcodes="' . esc_attr( $s ) . '">rendered</div>'; }
function get_option( $k, $d = false ) { return $GLOBALS['T']['options'][ $k ] ?? $d; }
function get_user_meta( $id, $k, $single = false ) { return ''; }
function get_query_var( $k, $d = '' ) { return $d; }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function add_shortcode( ...$a ) {}
function wb_notice( string $kind, string $msg ): string { return '<div class="wb-notice wb-' . $kind . '" role="status">' . $msg . '</div>'; }
class WB_Storage { public static function exists( $k ) { return false; } }

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'roles', 'setup', 'workspace', 'welcome', 'render', 'needs', 'demo', 'guide' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';
function date_i18n( $f ) { return date( $f, 1789982000 ); }   // Monday 21 September 2026, 09:13 UTC: a morning
function wp_nonce_field( ...$a ) { return ''; }
function sanitize_html_class( $s ) { return $s; }
function selected( ...$a ) { return ''; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }

$pass = 0;
$fail = 0;
function ok( string $name, bool $cond, string $detail = '' ): void {
	global $pass, $fail;
	if ( $cond ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}" . ( '' !== $detail ? "\n      " . $detail : '' ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/** The caps a WP role holds, from the plugin's own map (administrators: every staff cap). */
function caps_of( string $role ): array {
	if ( 'administrator' === $role ) return WB_Roles::staff_caps();
	$m = WB_Roles::map();
	return array_filter( $m[ $role ]['caps'] ?? [], fn( $v, $k ) => $v && 'read' !== $k, ARRAY_FILTER_USE_BOTH );
}
function menu_items( string $html ): array {
	preg_match_all( '#<a class="wb-side-item( is-active)?" href="https://b2b\.test/workspace/([a-z_-]*)/?"#', $html, $m, PREG_SET_ORDER );
	$out = [];
	foreach ( $m as $x ) $out[ '' === $x[2] ? 'home' : $x[2] ] = '' !== $x[1];
	return $out;
}
function next_items( string $html ): array {
	if ( ! preg_match( '#<nav class="wb-next".*?</nav>#s', $html, $nav ) ) return [];
	preg_match_all( '#href="https://b2b\.test/workspace/([a-z_-]*)/?"#', $nav[0], $m );
	return array_map( fn( $s ) => '' === $s ? 'home' : $s, $m[1] );
}

$logins = [ 'administrator', 'wb_owner', 'wb_sales', 'wb_warehouse', 'wb_accounts', 'wb_staff', 'wb_customer' ];
$slugs  = array_merge( array_keys( WB_Workspace::SCREENS ), [ 'portal' ] );

foreach ( $logins as $role ) {
	section( $role );
	$GLOBALS['T']['caps'] = caps_of( $role );
	$can = fn( string $c ): bool => current_user_can( $c );
	$expected_menu = [];
	foreach ( WB_Workspace::menu( $can ) as $items ) $expected_menu += $items;

	foreach ( $slugs as $slug ) {
		try {
			[ $status, $html ] = WB_Workspace::render( $slug );
		} catch ( Throwable $e ) {
			ok( "{$role} /{$slug}: renders without a PHP error", false, get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
			continue;
		}
		$s     = WB_Workspace::screen( $slug );
		$opens = $s && current_user_can( $s[2] );
		ok( "{$role} /{$slug}: status", $status === ( $opens ? 200 : 403 ), "got {$status}" );
		ok( "{$role} /{$slug}: a whole document", 0 === strpos( $html, '<!doctype html>' ) && false !== strpos( $html, '</html>' ) && false !== strpos( $html, '<!--head-->' ) && false !== strpos( $html, '<!--foot-->' ) );
		ok( "{$role} /{$slug}: the title is on the page", false !== strpos( $html, 'home' === $slug ? '<h1>Good morning, Thandi.</h1>' : '<h1>' . esc_html( $s[0] ) . '</h1>' ) );
		if ( 'portal' !== $slug ) ok( "{$role} /{$slug}: the eyebrow names the group (or the day on Today)", false !== strpos( $html, 'home' === $slug ? '<span class="wb-eyebrow">Monday 21 September</span>' : '<span class="wb-eyebrow">' . esc_html( WB_Workspace::GROUPS[ $s[3] ] ) . '</span>' ) || ( 'home' !== $slug && '' === WB_Workspace::GROUPS[ $s[3] ] ) );
		if ( $opens ) {
			ok( "{$role} /{$slug}: the screen's shortcodes are rendered", false !== strpos( $html, 'data-shortcodes="' . esc_attr( $s[4] ) . '"' ) );
			ok( "{$role} /{$slug}: no refusal on an open screen", false === strpos( $html, 'not part of your work' ) && false === strpos( $html, 'for customer logins' ) );
		} else {
			ok( "{$role} /{$slug}: never a blank refusal", false !== strpos( $html, 'wb-notice wb-warn' ) && ( false !== strpos( $html, 'not part of your work' ) || false !== strpos( $html, 'for customer logins' ) ) );
			ok( "{$role} /{$slug}: nothing of the screen leaks on a refusal", false === strpos( $html, 'data-shortcodes=' ) );
			$has_way_back = current_user_can( 'wb_access_workspace' ) || current_user_can( 'wb_portal' );
			ok( "{$role} /{$slug}: a way back when there is one", $has_way_back === ( false !== strpos( $html, 'Back to Today' ) || false !== strpos( $html, 'Go to your account' ) || false !== strpos( $html, 'Go to the workspace' ) ) );
		}
		if ( 'portal' === $slug ) {
			ok( "{$role} /portal: no staff menu on the portal", false === strpos( $html, '<aside class="wb-side"' ) );
			ok( "{$role} /portal: the eyebrow says whose account", false !== strpos( $html, '<span class="wb-eyebrow">Your account</span>' ) );
			continue;
		}
		$menu = menu_items( $html );
		ok( "{$role} /{$slug}: the menu lists exactly what this login can open", array_keys( $menu ) === array_keys( $expected_menu ), 'menu: ' . implode( ',', array_keys( $menu ) ) . ' | expected: ' . implode( ',', array_keys( $expected_menu ) ) );
		if ( $opens ) ok( "{$role} /{$slug}: the current screen is marked", ( $menu[ $slug ] ?? false ) === true );
		foreach ( next_items( $html ) as $to ) {
			ok( "{$role} /{$slug}: next link →{$to} is one this login can open", isset( $expected_menu[ $to ] ), "→{$to}" );
		}
	}
	[ $status, $html ] = WB_Workspace::render( 'wp-admin' );
	ok( "{$role} unknown address: 404 with words", 404 === $status && false !== strpos( $html, 'no screen at this address' ) );
	if ( current_user_can( 'wb_access_workspace' ) ) {
		[ , $html ] = WB_Workspace::render( 'home' );
		ok( "{$role}: breadcrumb on the top bar", false !== strpos( $html, '<ol class="wb-crumb"><li><span aria-current="page">Today</span></li></ol>' ) );
		ok( "{$role}: the waiting count is on the top bar and links to Needs attention", false !== strpos( $html, 'class="wb-top-wait is-clear" href="https://b2b.test/workspace/#wb-waiting"><b>0</b><span class="wb-top-wait-w"> waiting</span></a>' ) );
		ok( "{$role}: every menu item carries an icon", substr_count( $html, 'class="wb-side-item' ) === substr_count( $html, '<span class="wb-side-ic"' ) );
		ok( "{$role}: the person and Sign out are at the foot of the menu", false !== strpos( $html, '<div class="wb-side-foot">' ) && false !== strpos( $html, '<span class="wb-side-av" aria-hidden="true">TM</span>' ) );
	}
}

section( 'welcome page' );
$GLOBALS['T']['logged_in'] = false; $GLOBALS['T']['caps'] = [];
[ $status, $html ] = WB_Workspace::render( 'welcome' );
ok( 'signed out: 200', 200 === $status );
ok( 'signed out: a way in, to the system\'s own sign-in page', substr_count( $html, 'https://b2b.test/workspace/sign-in/?redirect_to=' ) >= 2 && false !== strpos( $html, '>Sign in</a>' ) && false === strpos( $html, 'wp-login.php' ) );
ok( 'signed out: no workspace or account buttons', false === strpos( $html, 'Open the workspace' ) && false === strpos( $html, 'Your account' ) );
ok( 'signed out: no staff menu, no sign out', false === strpos( $html, '<aside class="wb-side"' ) && false === strpos( $html, 'Sign out' ) );
ok( 'signed out: no getting-started block', false === strpos( $html, 'Getting started' ) );
ok( 'demo closed: no demo button', false === strpos( $html, 'Try the demo' ) );
ok( 'the one hand-drawn underline, once, in the H1', 1 === substr_count( $html, '<span class="wb-pen">' ) && false !== strpos( $html, '<h1 class="wb-display">' ) );
ok( 'the page rhythm: dark, light, light, dark, (light,) dark', preg_match_all( '/wb-sec--(navy|cream|white|tint|sink)/', $html, $mm ) >= 5 && 'navy' === $mm[1][0] && 'cream' === $mm[1][1] && 'white' === $mm[1][2] && 'navy' === $mm[1][3] && 'sink' === end( $mm[1] ) );
$GLOBALS['T']['options'] = [ WB_Demo::LOGIN_OPTION => [ 'enabled' => 'yes', 'user_id' => 7 ] ];
[ , $html ] = WB_Workspace::render( 'welcome' );
ok( 'demo open, signed out: Try the demo is the primary and links to the demo address', false !== strpos( $html, '<a class="wb-btn wb-btn-lg wb-btn-on-dark" href="https://b2b.test/workspace/demo/">Try the demo</a>' ) );
ok( 'demo open: Sign in stays as the secondary', false !== strpos( $html, 'wb-btn-ghost wb-btn-ghost-on-dark" href="https://b2b.test/workspace/sign-in/?redirect_to=' ) );
section( 'the sign-in page (1.0.0)' );
$GLOBALS['T']['logged_in'] = false; $GLOBALS['T']['caps'] = []; $GLOBALS['T']['options'] = [];
[ $status, $html ] = WB_Workspace::render( 'sign-in' );
ok( 'signed out: 200, the system\'s own page, no menu', 200 === $status && false !== strpos( $html, 'wb-app--signin' ) && false === strpos( $html, '<aside class="wb-side"' ) );
ok( 'the title and the company as the eyebrow', false !== strpos( $html, '<h1>Sign in</h1>' ) && false !== strpos( $html, '<span class="wb-eyebrow">Demo Technical Supplies</span>' ) );
ok( 'WordPress\'s own form, posted to wp-login.php, lands on Today after', false !== strpos( $html, '<form id="wb-login"' ) && false !== strpos( $html, 'name="redirect_to" value="https://b2b.test/workspace/"' ) );
ok( 'a way to reset a password', false !== strpos( $html, 'action=lostpassword' ) && false !== strpos( $html, 'Forgotten your password?' ) );
ok( 'demo closed: one card, no demo', false === strpos( $html, 'wb-signin--two' ) && false === strpos( $html, 'Enter the demo' ) );
$_GET['redirect_to'] = 'https://b2b.test/workspace/quotes/';
[ , $html ] = WB_Workspace::render( 'sign-in' );
ok( 'a redirect to a screen is kept', false !== strpos( $html, 'name="redirect_to" value="https://b2b.test/workspace/quotes/"' ) );
$_GET['redirect_to'] = 'https://evil.test/';
[ , $html ] = WB_Workspace::render( 'sign-in' );
ok( 'a redirect off the site is dropped', false !== strpos( $html, 'name="redirect_to" value="https://b2b.test/workspace/"' ) );
unset( $_GET['redirect_to'] );
$_GET['login'] = 'failed';
[ , $html ] = WB_Workspace::render( 'sign-in' );
ok( 'a failed attempt is said in words', false !== strpos( $html, 'That login name or password is not right.' ) );
unset( $_GET['login'] );
$GLOBALS['T']['options'] = [ WB_Demo::LOGIN_OPTION => [ 'enabled' => 'yes', 'user_id' => 7 ] ];
[ , $html ] = WB_Workspace::render( 'sign-in' );
ok( 'demo open: two cards, the demo visitor filled in, one button, the words about what is saved', false !== strpos( $html, 'wb-signin--two' ) && false !== strpos( $html, 'value="Demo visitor (demo)" readonly' ) && false !== strpos( $html, 'href="https://b2b.test/workspace/demo/">Enter the demo</a>' ) && false !== strpos( $html, 'kept for the day and cleared every night' ) );
$GLOBALS['T']['options'] = [];
$GLOBALS['T']['logged_in'] = true; $GLOBALS['T']['caps'] = caps_of( 'wb_owner' );
[ , $html ] = WB_Workspace::render( 'sign-in' );
ok( 'already signed in: where to go, and Sign out', false !== strpos( $html, 'You are signed in' ) && false !== strpos( $html, '>Open the workspace</a>' ) && false !== strpos( $html, '>Sign out</a>' ) && false === strpos( $html, '<form id="wb-login"' ) );
$GLOBALS['T']['caps'] = caps_of( 'wb_customer' );
[ , $html ] = WB_Workspace::render( 'sign-in' );
ok( 'a customer who is signed in is sent to their account', false !== strpos( $html, '>Your account</a>' ) && false === strpos( $html, 'Open the workspace' ) );
ok( 'the login address WordPress hands out is the system\'s page, except for wp-admin', [ WB_Workspace::login_url( 'https://b2b.test/wp-login.php', 'https://b2b.test/workspace/', false ), WB_Workspace::login_url( 'https://b2b.test/wp-login.php', 'https://b2b.test/wp-admin/', false ), WB_Workspace::login_url( 'https://b2b.test/wp-login.php', '', true ) ] === [ 'https://b2b.test/workspace/sign-in/?redirect_to=https%3A%2F%2Fb2b.test%2Fworkspace%2F', 'https://b2b.test/wp-login.php', 'https://b2b.test/wp-login.php' ] );
$GLOBALS['T']['logged_in'] = false; $GLOBALS['T']['caps'] = [];
$GLOBALS['T']['options'] = [ WB_Demo::LOGIN_OPTION => [ 'enabled' => 'yes', 'user_id' => 7 ] ];
[ , $html ] = WB_Workspace::render( 'welcome' );
ok( 'demo open: the five-minute tour', false !== strpos( $html, 'Five minutes in the demo.' ) && 5 === substr_count( $html, '<li><strong>' ) );
ok( 'the demo is open when the switch is on and the login exists', WB_Demo::is_open( [ 'enabled' => 'yes', 'user_id' => 7 ], true ) && ! WB_Demo::is_open( [ 'enabled' => 'yes', 'user_id' => 7 ], false ) && ! WB_Demo::is_open( [ 'enabled' => 'no', 'user_id' => 7 ], true ) && ! WB_Demo::is_open( [ 'enabled' => 'yes', 'user_id' => 0 ], true ) );
$GLOBALS['T']['options'] = [];
ok( 'welcome may be indexed; it holds no business data', false === strpos( $html, 'noindex' ) );
ok( 'what it does: six areas', 6 === substr_count( $html, '<div class="wb-area">' ) );
ok( 'how it keeps you safe: four points', 4 === substr_count( $html, '<div class="wb-safe">' ) );
ok( 'no plugin or WordPress talk on the welcome page', ! preg_match( '/plugin|WordPress|JetEngine|table/i', strip_tags( $html ) ) );
$GLOBALS['T']['logged_in'] = true;
$GLOBALS['T']['caps'] = caps_of( 'wb_owner' );
[ , $html ] = WB_Workspace::render( 'welcome' );
ok( 'owner: workspace button and getting started', false !== strpos( $html, 'Open the workspace' ) && false !== strpos( $html, 'Getting started' ) && false !== strpos( $html, 'Go to System Settings' ) );
$GLOBALS['T']['options'] = [ WB_Demo::LOGIN_OPTION => [ 'enabled' => 'yes', 'user_id' => 7 ] ];
[ , $html ] = WB_Workspace::render( 'welcome' );
ok( 'owner with the demo open: no demo button (they are already in)', false === strpos( $html, 'Try the demo' ) );
$GLOBALS['T']['options'] = [];
$GLOBALS['T']['caps'] = caps_of( 'wb_sales' );
[ , $html ] = WB_Workspace::render( 'welcome' );
ok( 'sales: workspace button, no getting started', false !== strpos( $html, 'Open the workspace' ) && false === strpos( $html, 'Getting started' ) );
$GLOBALS['T']['caps'] = caps_of( 'wb_customer' );
[ , $html ] = WB_Workspace::render( 'welcome' );
ok( 'customer: account button only', false !== strpos( $html, 'Your account' ) && false === strpos( $html, 'Open the workspace' ) );
[ , $html ] = WB_Workspace::render( 'home' );
ok( 'the screens stay unindexed', false !== strpos( $html, 'noindex' ) );

section( 'brand on the frame' );
$GLOBALS['T']['caps'] = caps_of( 'wb_owner' );
[ , $html ] = WB_Workspace::render( 'home' );
ok( 'the company name is on the frame', false !== strpos( $html, 'Demo Technical Supplies' ) );
ok( 'the mark is the first letter without mbstring', false !== strpos( $html, '<span class="wb-side-mark">D</span>' ) );
ok( 'the signed-in person and sign out are on the top bar', false !== strpos( $html, 'Thandi Mokoena' ) && false !== strpos( $html, 'Sign out' ) );
ok( 'the phone menu button is there', false !== strpos( $html, 'class="wb-top-menu"' ) );
ok( 'robots are told to stay out', false !== strpos( $html, 'noindex' ) );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
