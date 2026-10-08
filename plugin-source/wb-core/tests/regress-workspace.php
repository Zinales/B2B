<?php
/**
 * Regression tests for 0.3.0 (Zina, 7 October 2026): the plugin serves its own screens and
 * creates its own JetEngine tables.
 *  1. Routes: every screen has an address, a gate and words; unknown addresses are refused.
 *  2. The menu and the "Next" links show only what the person can open.
 *  3. The JetEngine tables: every schema CCT maps to a request, money keeps its decimals
 *     (a number field with no step is a whole-number column in JetEngine), references stay whole.
 *  4. 0.3.2 (BUILD-PATTERNS §2.1): every address comes from WB_Workspace::url() by slug, nothing
 *     types '/workspace/', every slug used in code is a screen, and every dashboard tick in
 *     WB_Roles::catalog() opens at least one screen of its own.
 *
 *   php tests/regress-workspace.php
 *
 * Pure functions only (no WordPress). Expected values are written out by hand.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'tables', 'workspace', 'setup', 'roles', 'render', 'needs' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0;
$fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	$ok = $got === $want;
	if ( $ok ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/* ============================================================ 1. routes */
section( 'routes' );
eq( 'workspace root is Today', WB_Workspace::screen( '' )[0], 'Today' );
eq( 'home is Today', WB_Workspace::screen( 'home' )[0], 'Today' );
eq( 'quotes gate', WB_Workspace::screen( 'quotes' )[2], 'wb_create_quotes' );
eq( 'setup gate', WB_Workspace::screen( 'setup' )[2], 'wb_manage_settings' );
eq( 'portal gate', WB_Workspace::screen( 'portal' )[2], 'wb_portal' );
eq( 'unknown screen refused', WB_Workspace::screen( 'wp-admin' ), null );
eq( 'portal is not a workspace screen', isset( WB_Workspace::SCREENS['portal'] ), false );
eq( 'payslips reachable by every staff login', WB_Workspace::screen( 'payroll' )[2], 'wb_access_workspace' );
// the notification bar links to /workspace/notifications/ — it must exist
eq( 'notifications screen exists', WB_Workspace::screen( 'notifications' )[4], '[wb_notifications]' );
$caps = [];
foreach ( WB_Workspace::SCREENS as $slug => $s ) {
	if ( 6 !== count( $s ) || '' === $s[0] || '' === $s[1] || 0 !== strpos( $s[2], 'wb_' ) || ! array_key_exists( $s[3], WB_Workspace::GROUPS ) || false === strpos( $s[4], '[wb_' ) ) eq( "screen {$slug} complete", false, true );
	foreach ( $s[5] as $to => $words ) if ( ! isset( WB_Workspace::SCREENS[ $to ] ) ) eq( "next link {$slug}→{$to} exists", false, true );
	if ( ! preg_match( '/^[a-z_-]+$/', $slug ) ) eq( "slug {$slug} matches the rewrite rule", false, true );
}
eq( 'every 0.2 dashboard page has a screen', array_values( array_diff( [ 'cashflow', 'customers', 'deliveries', 'documents', 'home', 'integrity', 'invoices', 'marketing', 'orders', 'payments', 'payroll', 'products', 'purchasing', 'quotes', 'settings', 'setup', 'staff', 'stock' ], array_keys( WB_Workspace::SCREENS ) ) ), [] );

/* ============================================================ 2. menu */
section( 'menu' );
$admin = fn( string $c ): bool => true;
$sales = fn( string $c ): bool => in_array( $c, [ 'wb_access_workspace', 'wb_view_customers', 'wb_create_quotes', 'wb_manage_orders', 'wb_view_products', 'wb_view_stock', 'wb_view_documents', 'wb_view_marketing' ], true );
$staff = fn( string $c ): bool => 'wb_access_workspace' === $c;
$cust  = fn( string $c ): bool => 'wb_portal' === $c;

$m = WB_Workspace::menu( $admin );
eq( 'admin sees every group in order', array_keys( $m ), [ '', 'Sell', 'Stock', 'Know', 'Team', 'Admin' ] );
eq( 'admin sees every screen', array_sum( array_map( 'count', $m ) ), count( WB_Workspace::SCREENS ) );
eq( 'top group first item is Today', array_key_first( $m[''] ), 'home' );

$m = WB_Workspace::menu( $sales );
eq( 'sales: no Admin group', isset( $m['Admin'] ), false );
eq( 'sales: Sell has customers, quotes, orders', array_keys( $m['Sell'] ), [ 'customers', 'quotes', 'orders' ] );
eq( 'sales: no Integrity', isset( $m['Know']['integrity'] ), false );

$m = WB_Workspace::menu( $staff );
eq( 'plain staff: Today, Notifications, How to, Staff, Payroll', $m, [ '' => [ 'home' => 'Today', 'notifications' => 'Notifications', 'howto' => 'How to' ], 'Team' => [ 'staff' => 'Staff', 'payroll' => 'Payroll' ] ] );
eq( 'customer login: empty menu', WB_Workspace::menu( $cust ), [] );

eq( 'next links: all for admin', WB_Workspace::next_links( 'cashflow', $admin ), [ 'invoices' => 'Overdue invoices', 'purchasing' => 'Supplier orders' ] );
eq( 'next links: sales cannot open invoices or purchasing', WB_Workspace::next_links( 'cashflow', $sales ), [] );
eq( 'next links: sales on customers', WB_Workspace::next_links( 'customers', $sales ), [ 'quotes' => 'Quote a customer', 'marketing' => 'Who is due to order' ] );
eq( 'next links: unknown screen', WB_Workspace::next_links( 'nope', $admin ), [] );

/* ============================================================ 2b. the short name */
section( 'short name' );
eq( 'site title with a dash tagline', WB_Setup::short_name( 'GroB2B - Systems to Streamline and Gro Your B2B Business' ), 'GroB2B' );
eq( 'en dash', WB_Setup::short_name( 'Demo Supplies – wholesale adhesives' ), 'Demo Supplies' );
eq( 'bar', WB_Setup::short_name( 'Demo Supplies | Shop' ), 'Demo Supplies' );
eq( 'colon', WB_Setup::short_name( 'Demo: the system' ), 'Demo' );
eq( 'no tagline: unchanged', WB_Setup::short_name( 'Demo Technical Supplies' ), 'Demo Technical Supplies' );
eq( 'a hyphen inside a word is kept', WB_Setup::short_name( 'Smith-Jones Wholesale' ), 'Smith-Jones Wholesale' );
eq( 'empty stays empty', WB_Setup::short_name( '' ), '' );

/* ============================================================ 3. tables */
section( 'tables' );
eq( 'whole: customer_id', WB_Tables::is_whole_number( 'customer_id' ), true );
eq( 'whole: checked_by', WB_Tables::is_whole_number( 'checked_by' ), true );
eq( 'whole: payment_terms_days', WB_Tables::is_whole_number( 'payment_terms_days' ), true );
eq( 'decimal: unit_price', WB_Tables::is_whole_number( 'unit_price' ), false );
eq( 'decimal: hours', WB_Tables::is_whole_number( 'hours' ), false );
eq( 'decimal: qty', WB_Tables::is_whole_number( 'qty' ), false );
eq( 'decimal: days (half days of leave)', WB_Tables::is_whole_number( 'days' ), false );

$f = WB_Tables::field_def( [ 'name' => 'credit_limit', 'type' => 'number' ], 7 );
eq( 'money field', $f, [ 'id' => 7, 'title' => 'Credit limit', 'name' => 'credit_limit', 'object_type' => 'field', 'type' => 'number', 'width' => '100%', 'is_required' => false, 'step_value' => '0.01' ] );
$f = WB_Tables::field_def( [ 'name' => 'customer_id', 'type' => 'number' ], 1 );
eq( 'reference field has no step', isset( $f['step_value'] ), false );
$f = WB_Tables::field_def( [ 'name' => 'record_status', 'type' => 'select', 'options' => [ 'active', 'void' ], 'default' => 'active' ], 3 );
eq( 'select options', $f['options'], [ [ 'id' => 1, 'key' => 'active', 'value' => 'Active', 'is_checked' => true ], [ 'id' => 2, 'key' => 'void', 'value' => 'Void', 'is_checked' => false ] ] );
eq( 'select has no default_val', isset( $f['default_val'] ), false );
$f = WB_Tables::field_def( [ 'name' => 'currency', 'type' => 'text', 'default' => 'ZAR', 'note' => 'ISO code' ], 2 );
eq( 'text default', $f['default_val'], 'ZAR' );
eq( 'note becomes description', $f['description'], 'ISO code' );

$schema = WB_Tables::schema();
eq( 'schema read: 35 tables', count( $schema ), 35 );
$req = WB_Tables::content_type_request( 'wb_customers', $schema['wb_customers'] );
eq( 'request slug', $req['slug'], 'wb_customers' );
eq( 'request name', $req['name'], 'Customers' );
eq( 'no REST access', [ $req['args']['rest_get_enabled'], $req['args']['rest_post_enabled'] ], [ false, false ] );
eq( 'no single pages', $req['args']['has_single'], false );
eq( 'every field carried', count( $req['meta_fields'] ), count( $schema['wb_customers']['fields'] ) );
$ids = [];
$bad = [];
foreach ( $schema as $slug => $def ) {
	$r = WB_Tables::content_type_request( $slug, $def );
	$ids = array_column( $r['meta_fields'], 'id' );
	if ( count( $ids ) !== count( array_unique( $ids ) ) ) $bad[] = $slug . ' ids';
	if ( ! in_array( 'record_status', array_column( $r['meta_fields'], 'name' ), true ) ) $bad[] = $slug . ' record_status';
	foreach ( $r['meta_fields'] as $mf ) {
		if ( 'number' !== $mf['type'] ) continue;
		$money = in_array( $mf['name'], [ 'amount', 'total', 'subtotal', 'vat', 'unit_price', 'line_total', 'cost_price', 'list_price', 'floor_price', 'gross', 'net', 'paye', 'credit_limit', 'amount_paid' ], true );
		if ( $money && ! isset( $mf['step_value'] ) ) $bad[] = $slug . '.' . $mf['name'] . ' loses its cents';
	}
}
eq( 'every table: unique field ids, record_status, money with cents', $bad, [] );

/* ============================================================ 4. one list drives the addresses (0.3.2) */
section( 'addresses by slug' );
function home_url( $p = '' ) { return 'https://b2b.test' . $p; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
eq( 'home is the workspace root', WB_Workspace::url( 'home' ), 'https://b2b.test/workspace/' );
eq( 'empty slug is home', WB_Workspace::url( '' ), 'https://b2b.test/workspace/' );
eq( 'a screen', WB_Workspace::url( 'quotes' ), 'https://b2b.test/workspace/quotes/' );
eq( 'view state as a query string', WB_Workspace::url( 'quotes', [ 'quote' => 12 ] ), 'https://b2b.test/workspace/quotes/?quote=12' );
eq( 'the portal', WB_Workspace::url( 'portal' ), 'https://b2b.test/portal/' );
eq( 'portal_url agrees', WB_Workspace::portal_url(), WB_Workspace::url( 'portal' ) );
eq( 'an unknown slug lands on Today, never a dead address', WB_Workspace::url( 'wp-admin' ), 'https://b2b.test/workspace/' );
foreach ( array_keys( WB_Workspace::SCREENS ) as $slug ) {
	if ( WB_Workspace::url( $slug ) !== 'https://b2b.test/workspace/' . ( 'home' === $slug ? '' : $slug . '/' ) ) eq( "url({$slug}) matches the rewrite rule", false, true );
}

section( 'nothing types a workspace address' );
$typed = [];
$used  = [];
$files = array_merge( glob( WB_PLUGIN_DIR . 'includes/*.php' ), [ WB_PLUGIN_DIR . 'wb-core.php' ] );
foreach ( $files as $file ) {
	$src  = (string) file_get_contents( $file );
	$name = basename( $file );
	foreach ( explode( "\n", $src ) as $i => $line ) {
		if ( ( false !== strpos( $line, "'/workspace" ) || false !== strpos( $line, "'/portal/" ) ) && ! ( 'class-wb-workspace.php' === $name && ( false !== strpos( $line, 'home_url(' ) || false !== strpos( $line, '* ' ) ) ) ) $typed[] = $name . ':' . ( $i + 1 );
	}
	preg_match_all( "/(?:WB_Workspace::url|self::url|wb_return_url)\\( '([a-z_-]+)'/", $src, $m );
	foreach ( $m[1] as $slug ) $used[ $slug ][] = $name;
}
eq( 'no typed /workspace/ or /portal/ outside WB_Workspace::url()', $typed, [] );
$unknown = array_filter( array_keys( $used ), fn( $s ) => null === WB_Workspace::screen( $s ) );
eq( 'every slug used in code is a screen', array_values( $unknown ), [] );
eq( 'the engines do use it (a regex that matched nothing would pass the test above for free)', count( $used ) >= 10, true );

section( 'every dashboard tick does something' );
// A tick either opens a screen of its own (its cap is a screen gate) or opens a fold on a screen
// every login can reach (Staff, Payroll: the screen is open, the folds are gated). Either way
// some code must check the cap; a cap nobody checks is a tick that does nothing.
$everyone = [ 'home', 'notifications', 'staff', 'payroll' ];
$no_screen_yet = [ 'export' ];   // wb_export_data: nothing checks it yet (0.3.2). A decision to make, not an accident.
$checked = [];
foreach ( $files as $file ) {
	if ( 'class-wb-roles.php' === basename( $file ) ) continue;   // granting is not checking
	preg_match_all( "/'(wb_[a-z_]+)'/", (string) file_get_contents( $file ), $m );
	foreach ( $m[1] as $cap ) $checked[ $cap ] = true;
}
$idle = [];
$no_screen = [];
foreach ( WB_Roles::catalog() as $key => $tick ) {
	if ( ! empty( $tick['modifier'] ) ) continue;
	$opens = false;
	foreach ( $tick['caps'] as $cap ) {
		if ( ! isset( $checked[ $cap ] ) ) $idle[] = $key . ':' . $cap;
		foreach ( WB_Workspace::SCREENS as $slug => $s ) if ( ! in_array( $slug, $everyone, true ) && $s[2] === $cap ) $opens = true;
	}
	if ( ! $opens ) $no_screen[] = $key;
}
eq( 'every cap a tick grants is checked somewhere (Export is the known gap)', $idle, [ 'export:wb_export_data' ] );
eq( 'ticks with no screen of their own are exactly the fold-gated ones', $no_screen, [ 'staff', 'reviews', 'export', 'payroll' ] );
foreach ( WB_Workspace::SCREENS as $slug => $s ) {
	$granted = 'wb_access_workspace' === $s[2];
	foreach ( WB_Roles::catalog() as $tick ) if ( in_array( $s[2], $tick['caps'], true ) ) $granted = true;
	if ( ! $granted ) eq( "screen {$slug}: some tick grants its gate {$s[2]}", false, true );
}

/* ============================================================ 5. 0.3.4: the shared primitives and what is waiting */
section( 'screen primitives (0.3.4)' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function current_user_can( $c ) { return true; }
function sanitize_html_class( $s ) { return $s; }
function selected( ...$a ) { return ''; }
$f = WB_Render::fold( 'Add a customer', '<p>form</p>', [ 'open' => true, 'id' => 'wb-add' ] );
eq( 'a plain fold', $f, '<details class="wb-fold" open id="wb-add"><summary>Add a customer</summary><div class="wb-fold-body"><p>form</p></div></details>' );
eq( 'a sibling fold says "Also here"', 0 === strpos( WB_Render::fold( 'Price rules', '', [ 'kind' => 'sibling' ] ), '<details class="wb-fold wb-fold--sibling"><summary><span class="wb-fold-eyebrow">Also here</span>Price rules</summary>' ), true );
eq( 'a reference fold is marked read only', false !== strpos( WB_Render::fold( 'Audit trail', '', [ 'kind' => 'reference' ] ), 'Audit trail <span class="wb-fold-ro">Read only</span>' ), true );
eq( 'a hint sits on the summary, a note at the top of the body', WB_Render::fold( 'Entries', 'x', [ 'hint' => '3 entries', 'note' => 'This month.' ] ), '<details class="wb-fold"><summary>Entries<span class="wb-fold-hint">3 entries</span></summary><div class="wb-fold-body"><p class="wb-fold-note">This month.</p>x</div></details>' );
eq( 'an unknown kind is a plain fold', 0 === strpos( WB_Render::fold( 'T', '', [ 'kind' => 'odd' ] ), '<details class="wb-fold">' ), true );
eq( 'the empty state has a title and a line', WB_Render::state( 'empty', 'No customers yet.', 'Add the first one below.' ), '<div class="wb-state wb-state--empty"><span class="wb-state-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12l3-7h12l3 7v7H3z"/><path d="M3 12h5l2 3h4l2-3h5"/></svg></span><div><p class="wb-state-t">No customers yet.</p><p class="wb-state-s">Add the first one below.</p></div></div>' );
eq( 'a stat tile: label → number → note, hot carries the rule', WB_Render::stat( 'Overdue invoices', 3, 'https://b2b.test/workspace/invoices/', 'past the due date', true ), '<a class="wb-stat wb-stat--hot" href="https://b2b.test/workspace/invoices/"><span class="wb-stat-label">Overdue invoices</span><span class="wb-stat-num">3</span><span class="wb-stat-sub">past the due date</span></a>' );
eq( 'a bounded list says its bound', WB_Render::bounded( array_fill( 0, 500, [] ), 500 ), '<p class="wb-list-more">Showing the newest <strong>500</strong>. There may be more; narrow the list to find the rest.</p>' );
eq( 'under the bound: nothing', WB_Render::bounded( array_fill( 0, 499, [] ), 500 ), '' );
eq( 'stored value → screen word', [ WB_Render::words( 'on_hold' ), WB_Render::words( 'auto_reference' ), WB_Render::words( 'pct_off_list' ), WB_Render::words( 'msds' ) ], [ 'On hold', 'By reference', '% off list', 'Safety data sheet' ] );
eq( 'a value not in the map is the key with spaces', WB_Render::words( 'some_new_status' ), 'Some new status' );
eq( 'chips use the map', WB_Render::chip( 'part_paid' ), '<span class="wb-chip wb-chip--wait">Part paid</span>' );
eq( 'a required field carries the mark', false !== strpos( WB_Render::field( 'name', 'Company name', 'text', '', [ 'required' => true ] ), '<span>Company name <span class="wb-req" aria-hidden="true">*</span></span>' ), true );

section( 'what is waiting (0.3.4)' );
$rows = [
	[ 'key' => 'overdue', 'kind' => 'open', 'count' => 9 ],
	[ 'key' => 'payments', 'kind' => 'waiting', 'count' => 2 ],
	[ 'key' => 'prices', 'kind' => 'waiting', 'count' => 2 ],
	[ 'key' => 'leave', 'kind' => 'waiting', 'count' => 5 ],
];
eq( 'waiting before open, bigger first, then list order', array_column( WB_Needs::sort( $rows ), 'key' ), [ 'leave', 'payments', 'prices', 'overdue' ] );
eq( 'nothing at all', WB_Needs::lede( 0, 0 ), 'Nothing is waiting on you, and nothing is still open.' );
eq( 'one waiting', WB_Needs::lede( 1, 0 ), 'One thing is waiting on you.' );
eq( 'several waiting and several open', WB_Needs::lede( 3, 2 ), '3 things are waiting on you, and 2 are still open.' );
eq( 'none waiting, one open', WB_Needs::lede( 0, 1 ), 'Nothing is waiting on you, and one thing is still open.' );
eq( 'thousands are formatted', WB_Needs::lede( 1200, 0 ), '1,200 things are waiting on you.' );
eq( 'morning / afternoon / evening', [ WB_Needs::greeting( 6 ), WB_Needs::greeting( 11 ), WB_Needs::greeting( 12 ), WB_Needs::greeting( 16 ), WB_Needs::greeting( 17 ), WB_Needs::greeting( 23 ) ], [ 'Good morning', 'Good morning', 'Good afternoon', 'Good afternoon', 'Good evening', 'Good evening' ] );
eq( 'every line has a verb', array_values( array_filter( array_map( fn( $l ) => $l['key'], WB_Needs::all() ), fn( $k ) => 'Open' === WB_Needs::verb( $k ) ) ), [] );
eq( 'without a database every count fails to zero, never a fatal', array_sum( array_column( WB_Needs::all(), 'count' ) ), 0 );
eq( 'every line lands on a screen that exists', array_values( array_filter( array_map( fn( $l ) => $l['slug'], WB_Needs::all() ), fn( $s ) => null === WB_Workspace::screen( $s ) ) ), [] );
eq( 'every line is gated by a real capability', array_values( array_filter( array_map( fn( $l ) => $l['cap'], WB_Needs::all() ), fn( $c ) => ! isset( WB_Roles::CAPS[ $c ] ) ) ), [] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
