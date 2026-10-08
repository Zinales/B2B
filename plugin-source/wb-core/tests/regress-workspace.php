<?php
/**
 * Regression tests for 0.3.0 (Zina, 7 October 2026): the plugin serves its own screens and
 * creates its own JetEngine tables.
 *  1. Routes: every screen has an address, a gate and words; unknown addresses are refused.
 *  2. The menu and the "Next" links show only what the person can open.
 *  3. The JetEngine tables: every schema CCT maps to a request, money keeps its decimals
 *     (a number field with no step is a whole-number column in JetEngine), references stay whole.
 *
 *   php tests/regress-workspace.php
 *
 * Pure functions only (no WordPress). Expected values are written out by hand.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'tables', 'workspace', 'setup' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

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
eq( 'plain staff: Today, Notifications, Staff, Payroll', $m, [ '' => [ 'home' => 'Today', 'notifications' => 'Notifications' ], 'Team' => [ 'staff' => 'Staff', 'payroll' => 'Payroll' ] ] );
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

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
