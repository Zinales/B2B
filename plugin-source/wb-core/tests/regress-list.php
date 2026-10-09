<?php
/**
 * Regression tests for 1.5.0 (review of 9 October): search, status filter, sort and paging on
 * every list; the customer page's ageing.
 *  1. WB_List's pure parts: reading the query string, building links, the paging arithmetic.
 *  2. The search condition WB_CCT builds: an OR of LIKEs across the named columns plus the ids
 *     found in a related table, escaped, and "nothing searchable" matching nothing.
 *  3. The list as rendered against an in-memory table: the search box, the chips, the sortable
 *     heads, the pager, the empty words when a search finds nothing.
 *  4. WB_Pages::ageing(): what is owed by how late.
 *
 *   php tests/regress-list.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function home_url( $p = '' ) { return 'https://b2b.test' . $p; }
function add_query_arg( $args, $url ) { $q = http_build_query( $args ); return $url . ( '' === $q ? '' : ( false === strpos( $url, '?' ) ? '?' : '&' ) . $q ); }
function wp_unslash( $v ) { return $v; } function add_action( ...$a ) {} function add_filter( ...$a ) {} function add_shortcode( ...$a ) {}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } function wp_json_encode( $v ) { return json_encode( $v ); }
function get_query_var( $k, $d = '' ) { return 'invoices'; } function current_user_can( $c ) { return true; }

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'workspace', 'render', 'list', 'pages' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

/** The in-memory table the list renders against: the same where/order/limit/offset contract as WB_CCT. */
class WB_CCT {
	public static array $rows = [];
	public static function columns( $s ) { return [ '_ID', 'invoice_number', 'customer_id', 'status', 'total', 'record_status' ]; }
	private static function match( array $r, array $where ): bool {
		foreach ( $where as $k => $v ) {
			if ( '_search' === $k ) {
				$hit = false;
				foreach ( $v['cols'] as $c ) if ( false !== stripos( (string) $r[ $c ], $v['q'] ) ) $hit = true;
				foreach ( $v['ids'] ?? [] as $c => $ids ) if ( in_array( (int) $r[ $c ], $ids, true ) ) $hit = true;
				if ( ! $hit ) return false;
				continue;
			}
			if ( (string) $r[ $k ] !== (string) $v ) return false;
		}
		return true;
	}
	public static function count( $s, $where = [] ) { return count( array_filter( self::$rows, fn( $r ) => self::match( $r, $where ) ) ); }
	public static function find( $s, $where = [], $o = [] ) {
		if ( 'wb_customers' === $s ) return array_values( array_filter( [ [ '_ID' => 7, 'name' => 'Karoo Agri' ], [ '_ID' => 8, 'name' => 'Bayside Hardware' ] ], fn( $c ) => false !== stripos( $c['name'], $where['_search']['q'] ) ) );
		$r = array_values( array_filter( self::$rows, fn( $r ) => self::match( $r, $where ) ) );
		$by = $o['orderby'] ?? '_ID';
		usort( $r, fn( $a, $b ) => ( 'asc' === strtolower( $o['order'] ?? 'desc' ) ? 1 : -1 ) * ( is_numeric( $a[ $by ] ) ? $a[ $by ] <=> $b[ $by ] : strcmp( $a[ $by ], $b[ $by ] ) ) );
		return array_slice( $r, (int) ( $o['offset'] ?? 0 ), (int) ( $o['limit'] ?? 500 ) );
	}
}
class WB_RowActions { public static function cell( ...$a ) { return ''; } }
class WB_Records { public static function schema( $s ) { return [ 'status' => [ 'options' => [ 'issued', 'paid', 'overdue', 'void' ] ] ]; } }

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( is_string( $got ) && strlen( $got ) > 400 ? substr( $got, 0, 400 ) . '…' : $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function has( string $name, string $hay, string $needle, bool $want = true ): void { eq( $name, false !== strpos( $hay, $needle ), $want ); }
function section( string $t ): void { echo "-- {$t}\n"; }

/* ============================================================ 1. pure */
section( 'reading and linking' );
eq( 'nothing asked', WB_List::read( [] ), [ 'q' => '', 'st' => '', 'by' => '', 'dir' => '', 'pg' => 1 ] );
eq( 'everything asked', WB_List::read( [ 'q' => ' karoo ', 'st' => 'Overdue', 'by' => 'total', 'dir' => 'ASC', 'pg' => '3' ] ), [ 'q' => 'karoo', 'st' => 'overdue', 'by' => 'total', 'dir' => 'asc', 'pg' => 3 ] );
eq( 'a prefix keeps two lists apart', WB_List::read( [ 'q' => 'x', 'poq' => 'acme' ], 'po' )['q'], 'acme' );
eq( 'junk in the column and status is dropped', WB_List::read( [ 'by' => 'total; DROP', 'st' => "o'verdue" ] ), [ 'q' => '', 'st' => 'overdue', 'by' => 'totaldrop', 'dir' => '', 'pg' => 1 ] );
eq( 'a page below one is one', WB_List::read( [ 'pg' => '-4' ] )['pg'], 1 );
eq( 'a long search is cut', strlen( WB_List::read( [ 'q' => str_repeat( 'a', 200 ) ] )['q'] ), 80 );
eq( 'links keep the rest of the address', WB_List::args( [ 'quote' => '12', 'q' => 'k' ], '', [ 'st' => 'sent' ] ), [ 'quote' => '12', 'q' => 'k', 'st' => 'sent' ] );
eq( 'an empty value removes the parameter', WB_List::args( [ 'q' => 'k', 'pg' => '2' ], '', [ 'q' => '', 'pg' => '' ] ), [] );
eq( 'one-shot messages never ride along', WB_List::args( [ 'wbmsg' => 'x', 'wbra' => 'y', 'q' => 'k' ], '', [] ), [ 'q' => 'k' ] );
eq( 'the prefix is applied', WB_List::args( [], 'po', [ 'q' => 'acme' ] ), [ 'poq' => 'acme' ] );
eq( 'span: nothing', WB_List::span( 0, 1 ), [ 0, 0, 1 ] );
eq( 'span: first page', WB_List::span( 812, 1 ), [ 1, 50, 17 ] );
eq( 'span: second page', WB_List::span( 812, 2 ), [ 51, 100, 17 ] );
eq( 'span: last page is short', WB_List::span( 812, 17 ), [ 801, 812, 17 ] );
eq( 'span: a page past the end is the last', WB_List::span( 812, 99 ), [ 801, 812, 17 ] );
eq( 'span: exactly one page', WB_List::span( 50, 1 ), [ 1, 50, 1 ] );

/* ============================================================ 2. the SQL */
section( 'the search condition' );
class FakeDb { public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); } }
$GLOBALS['wpdb'] = new FakeDb();
$src = file_get_contents( $base . 'class-wb-cct.php' );
eval( '?>' . str_replace( 'class WB_CCT', 'class Real_CCT', $src ) );
$rc = new ReflectionClass( 'Real_CCT' );
$rc->getProperty( 'columns' )->setValue( null, [ 'wb_invoices' => [ '_ID', 'invoice_number', 'customer_id', 'status', 'record_status' ] ] );
$where = $rc->getMethod( 'where' );
[ $sql, $args ] = $where->invoke( null, 'wb_invoices', [ 'status' => 'overdue', '_search' => [ 'cols' => [ 'invoice_number' ], 'q' => '50%_off', 'ids' => [ 'customer_id' => [ 7, '8' ] ] ] ], true );
eq( 'search is an OR beside the other conditions', $sql, "1=1 AND `status` = %s AND (`invoice_number` LIKE %s OR `customer_id` IN (7,8)) AND COALESCE(`record_status`,'') NOT IN ('archived','inactive','void')" );
eq( 'the word is escaped for LIKE', $args, [ 'overdue', '%50\\%\\_off%' ] );
[ $sql ] = $where->invoke( null, 'wb_invoices', [ '_search' => [ 'cols' => [ 'not_a_column' ], 'q' => 'x' ] ], false );
eq( 'nothing searchable matches nothing, never everything', $sql, '1=1 AND 1=0' );
[ $sql ] = $where->invoke( null, 'wb_invoices', [ '_search' => [ 'cols' => [ 'invoice_number' ], 'q' => '  ' ] ], false );
eq( 'a blank search adds nothing', $sql, '1=1' );
eq( 'an unknown column still fails closed', $where->invoke( null, 'wb_invoices', [ 'nope' => 1 ], false ), null );

/* ============================================================ 3. the rendered list */
section( 'the list' );
for ( $i = 1; $i <= 120; $i++ ) WB_CCT::$rows[] = [ '_ID' => $i, 'invoice_number' => sprintf( 'INV-2026-%06d', $i ), 'customer_id' => 0 === $i % 2 ? 7 : 8, 'status' => 0 === $i % 10 ? 'overdue' : 'paid', 'total' => $i * 10, 'record_status' => 'active' ];
$cols = [ 'invoice_number', 'status', [ 'key' => 'total', 'type' => 'money' ] ];
$o    = [ 'what' => 'invoices', 'search' => [ 'invoice_number' ], 'search_in' => [ 'customer_id' => [ 'wb_customers', 'name' ] ], 'base' => 'https://b2b.test/workspace/invoices/' ];
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [] ] );
has( 'a search box', $h, 'type="search" name="q"' );
has( 'the search box has a label', $h, '<span class="wb-sr">Search invoices</span>' );
has( 'the chips from the schema, void left out', $h, '>Overdue</a>' );
has( 'void is not a chip', $h, '>Void</a>', false );
has( 'All is on when nothing is filtered', $h, 'class="wb-chip-filter is-on"' );
eq( 'fifty rows on the first page', substr_count( $h, '<tr>' ) - 1, 50 );
has( 'the newest first by default', $h, 'INV-2026-000120' );
has( 'the pager says where you are', $h, 'Showing 1 to 50 of 120' );
has( 'Next goes to page two', $h, 'invoices/?pg=2' );
has( 'no Previous on page one', $h, '>Previous<', false );
has( 'sortable heads', $h, 'class="wb-sort"' );
has( 'the money column sorts', $h, '?by=total&amp;dir=asc' );
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [ 'pg' => '3' ] ] );
has( 'page three', $h, 'Showing 101 to 120 of 120' );
has( 'Previous on the last page', $h, '>Previous<' );
has( 'no Next on the last page', $h, '>Next<', false );
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [ 'st' => 'overdue' ] ] );
eq( 'the overdue chip filters', substr_count( $h, '<tr>' ) - 1, 12 );
has( 'the chip is on and says so', $h, 'is-on" href="https://b2b.test/workspace/invoices/" aria-current="true">Overdue' );
has( 'the count when filtered', $h, '12 found.' );
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [ 'q' => '000077' ] ] );
eq( 'search by number', substr_count( $h, '<tr>' ) - 1, 1 );
has( 'the search stays in the box', $h, 'value="000077"' );
has( 'and can be cleared', $h, '>Clear</a>' );
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [ 'q' => 'karoo', 'st' => 'overdue' ] ] );
eq( 'search by customer name, inside a status', substr_count( $h, '<tr>' ) - 1, 12 );
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [ 'by' => 'total', 'dir' => 'asc' ] ] );
eq( 'sorted by total, smallest first', strpos( $h, 'INV-2026-000001' ) < strpos( $h, 'INV-2026-000002' ), true );
has( 'the head says how it is sorted', $h, 'aria-sort="ascending"' );
has( 'pressing it again turns it round', $h, '?by=total&amp;dir=desc' );
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [ 'by' => 'not_shown' ] ] );
has( 'an unknown sort falls back to the default', $h, 'INV-2026-000120' );
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [ 'q' => 'zzz' ] ] );
has( 'a search that finds nothing says so', $h, 'Nothing matches &#039;zzz&#039; among invoices.' );
has( 'and offers the way back', $h, '>Show everything</a>' );
WB_CCT::$rows = [];
$h = WB_List::render( 'wb_invoices', $cols, $o + [ 'get' => [], 'empty' => 'No invoices yet.' ] );
has( 'an empty table keeps its own words', $h, 'No invoices yet.' );

/* ============================================================ 4. ageing */
section( 'ageing' );
$inv = fn( $total, $due, $paid = 0, $cred = 0 ) => [ 'total' => $total, 'amount_paid' => $paid, 'amount_credited' => $cred, 'due_at' => $due ];
$a = WB_Pages::ageing( [ $inv( 1000, '2026-10-20' ), $inv( 500, '2026-10-01', 100 ), $inv( 300, '2026-08-25' ), $inv( 200, '2026-07-30' ), $inv( 150, '2026-06-01', 0, 50 ), $inv( 80, '2026-09-01', 80 ) ], '2026-10-09' );
eq( 'not yet due', $a['current'], 1000.0 );
eq( '8 days late, part paid', $a['d30'], 400.0 );
eq( '45 days late', $a['d60'], 300.0 );
eq( '71 days late', $a['d90'], 200.0 );
eq( 'over 90, part credited', $a['d90p'], 100.0 );
eq( 'paid in full is not owed', $a['total'], 2000.0 );
eq( 'overdue is everything past its date', $a['overdue'], 1000.0 );
eq( 'due today is not late', WB_Pages::ageing( [ $inv( 10, '2026-10-09' ) ], '2026-10-09' )['current'], 10.0 );
eq( 'no due date counts as current', WB_Pages::ageing( [ $inv( 10, '' ) ], '2026-10-09' )['current'], 10.0 );
eq( 'nothing owed', WB_Pages::ageing( [], '2026-10-09' )['total'], 0.0 );
$strip = WB_Pages::ageing_strip( $a );
has( 'the strip names every bucket', $strip, 'Over 90 days' );
has( 'late buckets are marked', $strip, 'wb-age is-late' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
