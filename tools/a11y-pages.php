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
function update_option( $k, $v, $a = null ) { $GLOBALS['T']['options'][ $k ] = $v; return true; }
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
/** 1.5.0: a small in-memory company so the quote editor and the customer page render for real. */
class WB_CCT {
	public static function count( ...$a ) { return 3; }
	public static function columns( $s ) { return [ '_ID', 'name', 'status', 'account_status', 'quote_number', 'invoice_number' ]; }
	public static function get( $s, $id ) {
		$rows = [ 'wb_customers' => [ '_ID' => 7, 'name' => 'Karoo Agri (Pty) Ltd', 'account_status' => 'open', 'payment_terms_days' => 30, 'credit_limit' => 80000, 'price_tier_id' => 0, 'journey_stage' => 'repeat', 'region' => 'Western Cape', 'notes' => '' ],
			'wb_quotes' => [ '_ID' => 9, 'quote_number' => 'QUO-2026-000012', 'customer_id' => 7, 'status' => 'draft', 'pricing_check_status' => 'needs_approval', 'valid_until' => '2026-11-08', 'subtotal' => 1460, 'vat' => 219, 'total' => 1679 ] ];
		return $rows[ $s ] ?? null;
	}
	public static function first( ...$a ) { return null; }
	public static function find( $s, $w = [], $o = [] ) {
		if ( 'wb_invoices' === $s ) return [ [ '_ID' => 31, 'invoice_number' => 'INV-2026-000031', 'customer_id' => 7, 'issued_at' => '2026-07-01', 'due_at' => '2026-07-31', 'total' => 2000, 'amount_paid' => 500, 'amount_credited' => 0, 'status' => 'overdue' ],
			[ '_ID' => 44, 'invoice_number' => 'INV-2026-000044', 'customer_id' => 7, 'issued_at' => '2026-09-20', 'due_at' => '2026-10-20', 'total' => 1000, 'amount_paid' => 0, 'amount_credited' => 0, 'status' => 'issued' ] ];
		if ( 'wb_contacts' === $s ) return [ [ '_ID' => 1, 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'role_title' => 'Buyer', 'email' => 'thandi@karooagri.example', 'phone' => '082 123 4567', 'is_primary' => 'true', 'portal_wp_user_id' => 0 ] ];
		if ( 'wb_touchpoints' === $s ) return [ [ 'happened_at' => '2026-10-02 10:00:00', 'type' => 'call', 'summary' => 'Asked about epoxy lead times', 'next_action' => 'Send the datasheet', 'next_action_date' => '2026-10-06' ] ];
		return [];
	}
}
class WB_RowActions { public static function cell( ...$a ) { return '<button type="submit" class="wb-menuitem" role="menuitem">Ask for price approval</button>'; } public static function menuitem( $i, $l, $o = [] ) { return '<a class="wb-menuitem" role="menuitem" href="#">' . $l . '</a>'; } public static function notice() { return ''; } }
function rest_url( $p = '' ) { return 'https://b2b.test/wp-json/' . $p; } function wp_create_nonce( $a ) { return 'n'; } function wb_today() { return '2026-10-09'; }
function remove_query_arg( $k, $u = '' ) { return $u; }
function wb_return_field() { return ""; }
function wb_truthy( $v ) { return in_array( strtolower( (string) $v ), [ '1', 'yes', 'true', 'on' ], true ); }
/** The real stylesheets and the default brand tokens, as the plugin would print them. */
function wp_head() {
	echo '<style>' . WB_Setup::css_vars( WB_Setup::DEFAULT_COLORS ) . '</style>';
	echo '<link rel="stylesheet" href="file://' . WB_PLUGIN_DIR . 'assets/wb-dashboard.css">';
	echo '<link rel="stylesheet" href="file://' . WB_PLUGIN_DIR . 'assets/wb-workspace.css">';
}
/** A stand-in panel with the primitives every screen uses, so the frame's content area is not empty. */
function do_shortcode( $s ) {
	if ( '[wb_notify_bar][wb_quotes]' === $s ) {   // 1.5.0: the real quote line editor, a draft with a broken line
		$q = WB_CCT::get( 'wb_quotes', 9 );
		$lines = [ [ '_ID' => 31, 'description' => 'ADH-EP200 Epoxy adhesive 200 ml', 'qty' => '10', 'unit_price' => 129, 'price_source' => 'tier', 'line_total' => 1290, 'floor_price' => 109.85, 'cost_price' => 84.5, 'list_price' => 129, 'below_floor' => 'no', 'out_of_date' => 'no' ],
			[ '_ID' => 32, 'description' => 'FST-HN16 Hex nut M16 (box of 100)', 'qty' => '2', 'unit_price' => 85, 'price_source' => 'manual', 'line_total' => 170, 'floor_price' => 288, 'cost_price' => 240, 'list_price' => 410.4, 'below_floor' => 'yes', 'out_of_date' => 'no' ] ];
		return WB_Render::fold( 'Quote QUO-2026-000012', WB_Quote_Editor::draft( $q, $lines ), [ 'open' => true ] ) . WB_Render::fold( 'New quote', WB_Quote_Editor::new_form( 7 ), [ 'open' => true, 'id' => 'wb-add' ] );
	}
	if ( '[wb_notify_bar][wb_customers]' === $s ) return WB_Pages::customer( 7 );   // 1.5.0: the customer's own page
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
foreach ( [ 'roles', 'setup', 'workspace', 'welcome', 'render', 'needs', 'demo', 'guide', 'pricing', 'quote-editor', 'pages', 'send', 'records', 'invoices', 'orders', 'screens', 'documents', 'docs', 'datasheets', 'statements', 'optin' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

function caps_of( string $role ): array {
	if ( 'administrator' === $role ) return WB_Roles::staff_caps();
	$m = WB_Roles::map();
	return array_filter( $m[ $role ]['caps'] ?? [], fn( $v, $k ) => $v && 'read' !== $k, ARRAY_FILTER_USE_BOTH );
}

$pages = [
	[ 'welcome-signed-out', 'welcome', null ],
	[ 'welcome-demo-open', 'welcome', null, [ WB_Demo::LOGIN_OPTION => [ 'enabled' => 'yes', 'user_id' => 7 ], WB_Demo::PASS_OPTION => 'demo-4821' ] ],
	[ 'sign-in', 'sign-in', null ],
	[ 'sign-in-demo-open', 'sign-in', null, [ WB_Demo::LOGIN_OPTION => [ 'enabled' => 'yes', 'user_id' => 7 ], WB_Demo::PASS_OPTION => 'demo-4821' ] ],
	[ 'welcome-owner', 'welcome', 'wb_owner' ],
	[ 'welcome-customer', 'welcome', 'wb_customer' ],
	[ 'home-owner', 'home', 'wb_owner' ],
	[ 'quotes-sales', 'quotes', 'wb_sales' ],
	[ 'howto-sales', 'howto', 'wb_sales' ],
	[ 'customer-page-owner', 'customers', 'wb_owner' ],
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
