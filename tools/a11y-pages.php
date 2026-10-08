<?php
/**
 * Render the plugin-served pages to static HTML for the accessibility check, with WordPress stood
 * in for (the same stand-ins as tests/regress-frame.php) and the real stylesheets linked in.
 *
 *   php tools/a11y-pages.php <out dir>
 *
 * Writes one .html per page × login. Shortcode panels are stood in for by a marker, so this covers
 * the frame, the welcome page, the refusals and the portal shell; screen panels are checked by
 * tools/a11y-panels.php once they render without a database.
 */
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler( function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );

$out = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( '' === $out ) { fwrite( STDERR, "usage: php tools/a11y-pages.php <out dir>\n" ); exit( 2 ); }
if ( ! is_dir( $out ) ) mkdir( $out, 0777, true );

$plugin = dirname( __DIR__ ) . '/plugin-source/wb-core/';
define( 'ABSPATH', $plugin );
define( 'WB_PLUGIN_DIR', $plugin );
define( 'WB_PLUGIN_FILE', $plugin . 'wb-core.php' );
define( 'WB_VERSION', 'a11y' );
define( 'KB_IN_BYTES', 1024 );

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
function get_users( $args = [] ) { return [ 1, 2 ]; }
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
function wp_body_open() {}
function wp_footer() {}
function get_option( $k, $d = false ) { return $GLOBALS['T']['options'][ $k ] ?? $d; }
function date_i18n( $f ) { return date( $f ); }
function wp_nonce_field( ...$a ) { return ''; }
function sanitize_html_class( $s ) { return $s; }
function selected( ...$a ) { return ''; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_user_meta( $id, $k, $single = false ) { return ''; }
function get_query_var( $k, $d = '' ) { return $d; }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function add_shortcode( ...$a ) {}
function wb_notice( string $kind, string $msg ): string { return '<div class="wb-notice wb-' . $kind . '" role="status">' . $msg . '</div>'; }
class WB_Storage { public static function exists( $k ) { return false; } }
class WB_Tables { public static function all_present() { return true; } }
class WB_CCT { public static function count( ...$a ) { return 3; } }
function wb_truthy( $v ) { return in_array( strtolower( (string) $v ), [ '1', 'yes', 'true', 'on' ], true ); }
/** The real stylesheets and the default brand tokens, as the plugin would print them. */
function wp_head() {
	echo '<style>' . WB_Setup::css_vars( WB_Setup::DEFAULT_COLORS ) . '</style>';
	echo '<link rel="stylesheet" href="file://' . WB_PLUGIN_DIR . 'assets/wb-dashboard.css">';
	echo '<link rel="stylesheet" href="file://' . WB_PLUGIN_DIR . 'assets/wb-workspace.css">';
}
/** A stand-in panel with the primitives every screen uses, so the frame's content area is not empty. */
function do_shortcode( $s ) {
	if ( '[wb_howto]' === $s ) return WB_Guide::render();   // the How-to screen is pure words, so the real thing is rendered
	if ( '[wb_setup]' === $s ) return WB_Setup::checklist_card() . do_shortcode( '' );   // the set-up checklist (six of eight done, as a new site looks)
	return '<div class="wb-panel"><h2>Panel</h2><p class="wb-muted">Showing the newest 50.</p>'
		. '<p><button type="submit" class="wb-btn">Save</button> <button type="submit" class="wb-btn wb-btn-ghost">Cancel</button> '
		. '<a class="wb-btn" href="#">Open</a> <a class="wb-btn wb-btn-ghost" href="#">Back</a></p>'
		. '<span class="wb-chip wb-chip-ok">paid</span> <span class="wb-chip wb-chip-warn">overdue</span> <span class="wb-chip">draft</span>'
		. '<div class="wb-notice wb-ok" role="status">Saved.</div><div class="wb-notice wb-err" role="status">Refused.</div><div class="wb-notice wb-warn" role="status">Waiting.</div>'
		. '<a class="wb-stat" href="#"><span class="wb-stat-n">12</span><span class="wb-stat-l">Quotes out</span></a>'
		. '<div class="wb-field"><label for="f1">Customer name</label><input id="f1" placeholder="Karoo Agri (Pty) Ltd"></div>'
		. '</div>';
}

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'roles', 'setup', 'workspace', 'welcome', 'render', 'needs', 'demo', 'guide' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

function caps_of( string $role ): array {
	if ( 'administrator' === $role ) return WB_Roles::staff_caps();
	$m = WB_Roles::map();
	return array_filter( $m[ $role ]['caps'] ?? [], fn( $v, $k ) => $v && 'read' !== $k, ARRAY_FILTER_USE_BOTH );
}

$pages = [
	[ 'welcome-signed-out', 'welcome', null ],
	[ 'welcome-demo-open', 'welcome', null, [ WB_Demo::LOGIN_OPTION => [ 'enabled' => 'yes', 'user_id' => 7 ] ] ],
	[ 'sign-in', 'sign-in', null ],
	[ 'sign-in-demo-open', 'sign-in', null, [ WB_Demo::LOGIN_OPTION => [ 'enabled' => 'yes', 'user_id' => 7 ] ] ],
	[ 'welcome-owner', 'welcome', 'wb_owner' ],
	[ 'welcome-customer', 'welcome', 'wb_customer' ],
	[ 'home-owner', 'home', 'wb_owner' ],
	[ 'quotes-sales', 'quotes', 'wb_sales' ],
	[ 'howto-sales', 'howto', 'wb_sales' ],
	[ 'setup-owner', 'setup', 'wb_owner' ],
	[ 'payroll-refused-sales', 'setup', 'wb_sales' ],
	[ 'home-refused-customer', 'home', 'wb_customer' ],
	[ 'portal-customer', 'portal', 'wb_customer' ],
	[ 'not-found-owner', 'wp-admin', 'wb_owner' ],
];
$n = 0;
foreach ( $pages as $page ) {
	[ $file, $slug, $role ] = $page;
	$GLOBALS['T']['options']   = $page[3] ?? [];
	$GLOBALS['T']['logged_in'] = null !== $role;
	$GLOBALS['T']['caps']      = null === $role ? [] : caps_of( $role );
	[ $status, $html ] = WB_Workspace::render( $slug );
	file_put_contents( "{$out}/{$file}.html", $html );
	$n++;
}
echo "{$n} pages written to {$out}\n";
