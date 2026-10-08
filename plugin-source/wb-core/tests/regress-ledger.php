<?php
/**
 * wb-core regression tests for the 0.2.1 ledger, storage and demo fixes (review 2026-10-04:
 * S1 pending queue, S3 tail anchor + keyed hashes, S5 demo capture, S6 deferred events,
 * S7 lock name, S8 path sanitiser, S9 extension whitelist, S10 link routes), without WordPress.
 *
 *   php tests/regress-ledger.php
 *
 * Stubbed: ABSPATH, mb_substr when the CLI lacks mbstring, and — for the queue and anchor tests —
 * a small in-memory $wpdb and the handful of option functions WB_Ledger calls. The fake answers
 * only the exact queries WB_Ledger sends; anything else fails the run loudly.
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $len = null ) { return null === $len ? substr( (string) $s, $start ) : substr( (string) $s, $start, $len ); }
}
date_default_timezone_set( 'UTC' );
ini_set( 'error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wb-regress-ledger.log' );   // the ledger logs every queued entry

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'ledger', 'storage', 'rest', 'demo' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0;
$fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	$ok = is_float( $want ) || is_float( $got ) ? ( null !== $got && abs( (float) $got - (float) $want ) < 0.0005 ) : $got === $want;
	if ( $ok ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/* ---------------------------------------------------------------- WordPress stand-ins */

define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['test_key'] = '';
$GLOBALS['events']   = [];
function wb_enc_key(): string { return '' === $GLOBALS['test_key'] ? '' : hash( 'sha256', $GLOBALS['test_key'], true ); }
function sanitize_key( $k ) { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function current_time( $type, $gmt = 0 ) { static $n = 0; $n++; return gmdate( 'Y-m-d H:i:', 1790000000 ) . sprintf( '%02d', $n % 60 ); }
function do_action( $hook, ...$args ) { $GLOBALS['events'][] = array_merge( [ $hook ], $args ); }
function wp_generate_password( $len = 12, $special = true, $extra = false ) { $c = 'abcdefghijklmnopqrstuvwxyz0123456789'; $s = ''; for ( $i = 0; $i < $len; $i++ ) $s .= $c[ random_int( 0, 35 ) ]; return $s; }
function maybe_unserialize( $v ) { return $v; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['wpdb']->opts ) ? $GLOBALS['wpdb']->opts[ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['wpdb']->opts[ $name ] = $value; return true; }
function add_option( $name, $value = '', $dep = '', $autoload = 'yes' ) {
	if ( $GLOBALS['wpdb']->fail_options || array_key_exists( $name, $GLOBALS['wpdb']->opts ) ) return false;
	$GLOBALS['wpdb']->opts[ $name ] = $value;
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['wpdb']->opts[ $name ] ); return true; }

/** Answers exactly the queries WB_Ledger sends. prepare() keeps the args beside the SQL. */
class Fake_WPDB {
	public $prefix = 'wp_', $dbname = 'shop', $options = 'wp_options', $insert_id = 0, $last_error = '';
	public $rows = [], $opts = [], $lock_ok = true, $fail_insert = false, $fail_options = false, $next_id = 1;
	public function prepare( $q, ...$a ) { return [ $q, ( 1 === count( $a ) && is_array( $a[0] ) ) ? $a[0] : $a ]; }
	public function esc_like( $s ) { return $s; }
	private function split( $q ): array { return is_array( $q ) ? $q : [ $q, [] ]; }
	private function prefixed( string $like ): array { $p = rtrim( $like, '%' ); return array_filter( $this->opts, fn( $k ) => 0 === strpos( $k, $p ), ARRAY_FILTER_USE_KEY ); }
	public function get_var( $q ) {
		[ $sql, $a ] = $this->split( $q );
		if ( false !== strpos( $sql, 'GET_LOCK' ) ) return $this->lock_ok ? '1' : '0';
		if ( false !== strpos( $sql, 'SHOW TABLES' ) ) return $a[0];
		if ( false !== strpos( $sql, 'COUNT(*) FROM wp_options' ) ) return (string) count( $this->prefixed( $a[0] ) );
		if ( false !== strpos( $sql, 'SELECT option_value FROM wp_options' ) ) return $this->opts[ $a[0] ] ?? null;
		if ( false !== strpos( $sql, 'SELECT entry_hash FROM wp_wb_ledger WHERE entry_id' ) ) return $this->rows[ (int) $a[0] ]['entry_hash'] ?? null;
		throw new RuntimeException( 'fake wpdb: unexpected get_var ' . $sql );
	}
	public function get_row( $q, $o = null ) {
		[ $sql ] = $this->split( $q );
		if ( false !== strpos( $sql, 'ORDER BY entry_id DESC LIMIT 1' ) ) { if ( ! $this->rows ) return null; $r = $this->rows[ max( array_keys( $this->rows ) ) ]; return [ 'entry_id' => $r['entry_id'], 'entry_hash' => $r['entry_hash'], 'hash_version' => $r['hash_version'] ]; }
		throw new RuntimeException( 'fake wpdb: unexpected get_row ' . $sql );
	}
	public function get_results( $q, $o = null ) {
		[ $sql, $a ] = $this->split( $q );
		if ( false !== strpos( $sql, 'SELECT option_name, option_value' ) ) { $out = []; foreach ( $this->prefixed( $a[0] ) as $k => $v ) $out[] = [ 'option_name' => $k, 'option_value' => $v ]; return array_slice( $out, 0, (int) $a[1] ); }
		if ( false !== strpos( $sql, 'WHERE entry_id > %d' ) ) { ksort( $this->rows ); return array_slice( array_values( array_filter( $this->rows, fn( $r ) => $r['entry_id'] > (int) $a[0] ) ), 0, (int) $a[1] ); }
		throw new RuntimeException( 'fake wpdb: unexpected get_results ' . $sql );
	}
	public function query( $q ) { return 1; }
	public function insert( $t, $data, $fmt = null ) {
		if ( $this->fail_insert ) { $this->last_error = 'simulated failure'; return false; }
		$id = $this->next_id++;
		$this->rows[ $id ] = [ 'entry_id' => $id ] + $data;
		$this->insert_id   = $id;
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();
function fresh_db(): Fake_WPDB { $GLOBALS['wpdb'] = new Fake_WPDB(); $GLOBALS['events'] = []; return $GLOBALS['wpdb']; }

/* ===================================================================== S3 hashes */
section( 'S3: version 1 hashes unchanged, version 2 keyed' );
$r1 = [ 'entry_id' => 1, 'action' => 'invoice_issued', 'record_type' => 'wb_invoices', 'record_id' => 5, 'before_json' => '', 'after_json' => '{"total":115}', 'actor_user_id' => 2, 'ip' => '', 'created_at' => '2026-10-02 08:00:00', 'prev_hash' => '' ];
eq( 'v1 formula pinned (same value as selftest)', WB_Ledger::hash_for( '', $r1 ), '6d22c567fe1569ead2602532c3348c5c8897be5941834d52cdc36e6d9a948729' );
eq( 'v1 ignores any key', WB_Ledger::hash_for( '', $r1, 'k' ), WB_Ledger::hash_for( '', $r1 ) );
$v2 = $r1 + [ 'hash_version' => 2 ];
eq( 'v2 without a key cannot be computed', WB_Ledger::hash_for( '', $v2 ), '' );
eq( 'v2 = HMAC-SHA256 over the tagged fields', WB_Ledger::hash_for( '', $v2, 'k' ), hash_hmac( 'sha256', implode( "\n", [ 'wb-ledger-v2', '', 'invoice_issued', 'wb_invoices', '5', '', '{"total":115}', '2', '', '2026-10-02 08:00:00' ] ), 'k' ) );
eq( 'v2 differs by key', WB_Ledger::hash_for( '', $v2, 'k' ) !== WB_Ledger::hash_for( '', $v2, 'other' ), true );

$key = 'chain-key';
$a = $r1; $a['entry_hash'] = WB_Ledger::hash_for( '', $a );                                                        // an old v1 row
$b = [ 'entry_id' => 2, 'action' => 'x', 'record_type' => 'y', 'record_id' => 1, 'actor_user_id' => 1, 'created_at' => '2026-10-03 00:00:00', 'hash_version' => 2, 'prev_hash' => $a['entry_hash'] ];
$b['entry_hash'] = WB_Ledger::hash_for( $a['entry_hash'], $b, $key );
$c = [ 'entry_id' => 3, 'action' => 'z', 'record_type' => 'y', 'record_id' => 2, 'actor_user_id' => 1, 'created_at' => '2026-10-03 00:01:00', 'hash_version' => 2, 'prev_hash' => $b['entry_hash'] ];
$c['entry_hash'] = WB_Ledger::hash_for( $b['entry_hash'], $c, $key );
eq( 'mixed v1 → v2 chain verifies with the key', WB_Ledger::verify_rows( [ $a, $b, $c ], '', $key ), true );
eq( 'v2 rows without the key: key_missing', WB_Ledger::verify_rows( [ $a, $b, $c ] )['broken'] ?? null, 'key_missing' );
eq( 'v2 rows with the wrong key: hash', WB_Ledger::verify_rows( [ $a, $b, $c ], '', 'guess' )['broken'] ?? null, 'hash' );
$t = $c; $t['after_json'] = '{"x":1}'; $t['hash_version'] = 1; $t['entry_hash'] = WB_Ledger::hash_for( $b['entry_hash'], $t );   // rewritten without the key
$res = WB_Ledger::verify_rows( [ $a, $b, $t ], '', $key );
eq( 'a plain row after a keyed one: downgrade', $res['broken'] ?? null, 'downgrade' );
eq( 'downgrade found at the rewritten row', $res['entry_id'] ?? null, 3 );
eq( 'downgrade seen across batches ($v2_seen)', WB_Ledger::verify_rows( [ $t ], $b['entry_hash'], $key, true )['broken'] ?? null, 'downgrade' );
$t = $c; $t['after_json'] = '{"x":1}'; $t['entry_hash'] = hash( 'sha256', 'anything' );
eq( 'edited keyed row: hash', WB_Ledger::verify_rows( [ $a, $b, $t ], '', $key )['broken'] ?? null, 'hash' );

/* ===================================================================== S3 anchor */
section( 'S3: tail anchor' );
$an = WB_Ledger::make_anchor( 3, $c['entry_hash'], '' );
eq( 'no anchor, empty table: fine', WB_Ledger::verify_anchor( null, 0, null ), true );
eq( 'no anchor, rows present: anchor_missing', WB_Ledger::verify_anchor( null, 3, null ), 'anchor_missing' );
eq( 'anchor at the tail: fine', WB_Ledger::verify_anchor( $an, 3, $c['entry_hash'] ), true );
eq( 'anchor lagging, its row unchanged: fine', WB_Ledger::verify_anchor( $an, 9, $c['entry_hash'] ), true );
eq( 'tail rows deleted (anchor beyond the tail): truncated', WB_Ledger::verify_anchor( $an, 2, null ), 'truncated' );
eq( 'table emptied: truncated', WB_Ledger::verify_anchor( $an, 0, null ), 'truncated' );
eq( 'anchor row deleted, new rows after: anchor_row_missing', WB_Ledger::verify_anchor( $an, 5, null ), 'anchor_row_missing' );
eq( 'anchor row replaced: anchor_mismatch', WB_Ledger::verify_anchor( $an, 3, $b['entry_hash'] ), 'anchor_mismatch' );
$ak = WB_Ledger::make_anchor( 3, $c['entry_hash'], $key );
eq( 'keyed anchor verifies with the key', WB_Ledger::verify_anchor( $ak, 3, $c['entry_hash'], $key, true ), true );
eq( 'keyed anchor without the key: key_missing', WB_Ledger::verify_anchor( $ak, 3, $c['entry_hash'] ), 'key_missing' );
$forged = WB_Ledger::make_anchor( 2, $b['entry_hash'], 'guess' );
eq( 'anchor moved back without the key: anchor_forged', WB_Ledger::verify_anchor( $forged, 2, $b['entry_hash'], $key, true ), 'anchor_forged' );
eq( 'keyed chain + unkeyed anchor: anchor_forged', WB_Ledger::verify_anchor( WB_Ledger::make_anchor( 2, $b['entry_hash'], '' ), 2, $b['entry_hash'], $key, true ), 'anchor_forged' );

/* ===================================================================== S7 */
section( 'S7: lock name fits MySQL (64 characters)' );
$long = str_repeat( 'a_really_long_database_name_', 4 );
eq( 'long database name: 42 characters', strlen( WB_Ledger::lock_name_for( $long, 'wp_' ) ), 42 );
eq( 'never over 64', strlen( WB_Ledger::lock_name_for( str_repeat( 'x', 500 ), str_repeat( 'y', 500 ) ) ) <= 64, true );
eq( 'stable', WB_Ledger::lock_name_for( 'shop', 'wp_' ), WB_Ledger::lock_name_for( 'shop', 'wp_' ) );
eq( 'two sites in one database get two locks', WB_Ledger::lock_name_for( 'shop', 'wp_' ) !== WB_Ledger::lock_name_for( 'shop', 'wp2_' ), true );

/* ===================================================================== S1 + S3 + S6 with the fake database */
section( 'S1: an entry that cannot be chained is queued, then chained in order' );
$db = fresh_db();
$id1 = WB_Ledger::write( 'one', 't', 1 );
eq( 'normal write is chained', $id1, 1 );
eq( 'anchor follows the tail', $db->opts['wb_ledger_tail']['entry_id'] ?? null, 1 );
$db->lock_ok = false;
eq( 'lock timeout → QUEUED, not 0', WB_Ledger::write( 'two', 't', 2 ), WB_Ledger::QUEUED );
eq( 'nothing chained while locked', count( $db->rows ), 1 );
eq( 'pending_count sees it', WB_Ledger::pending_count(), 1 );
$db->lock_ok = true;
$db->fail_insert = true;
eq( 'insert failure → QUEUED', WB_Ledger::write( 'three', 't', 3 ), WB_Ledger::QUEUED );
eq( 'two waiting', WB_Ledger::pending_count(), 2 );
$db->fail_insert = false;
$id4 = WB_Ledger::write( 'four', 't', 4 );
eq( 'next write drains the queue first, in order', array_map( fn( $r ) => $r['action'], array_values( $db->rows ) ), [ 'one', 'two', 'three', 'four' ] );
eq( 'the new entry chains after the drained ones', $id4, 4 );
eq( 'queue is empty', WB_Ledger::pending_count(), 0 );
eq( 'chain verifies after the drain', WB_Ledger::verify_chain(), true );
$db->lock_ok = false;
$db->fail_options = true;
eq( 'cannot chain AND cannot queue → 0 (callers treat as not witnessed)', WB_Ledger::write( 'five', 't', 5 ), 0 );
$db->fail_options = false;
$db->lock_ok = false;
WB_Ledger::write( 'six', 't', 6 );
$db->lock_ok = true;
eq( 'drain() chains what is waiting and reports 0 left', WB_Ledger::drain(), 0 );
eq( 'six is chained', end( $db->rows )['action'], 'six' );

section( 'S3: anchor catches removed tail rows, even after later writes' );
$db = fresh_db();
foreach ( [ 'a', 'b', 'c' ] as $i => $act ) WB_Ledger::write( $act, 't', $i + 1 );
eq( 'intact', WB_Ledger::verify_chain(), true );
unset( $db->rows[3] );                                           // someone deletes the newest row
eq( 'deleted tail row is found', WB_Ledger::verify_chain()['broken'] ?? null, 'truncated' );
WB_Ledger::write( 'd', 't', 4 );                                 // business carries on
eq( 'a later write does not move the anchor over the gap', $db->opts['wb_ledger_tail']['entry_id'] ?? null, 3 );
eq( 'still reported after the next write', WB_Ledger::verify_chain()['broken'] ?? null, 'anchor_row_missing' );
$db->rows = [];
eq( 'emptied table is reported', WB_Ledger::verify_chain()['broken'] ?? null, 'truncated' );

section( 'S3: keyed rows when WB_ENCRYPTION_KEY is set' );
$db = fresh_db();
WB_Ledger::write( 'old', 't', 1 );                              // before the key: v1
$GLOBALS['test_key'] = 'tenant-secret';
WB_Ledger::write( 'new', 't', 2 );
eq( 'rows before the key stay v1', (int) $db->rows[1]['hash_version'], 1 );
eq( 'rows after the key are v2', (int) $db->rows[2]['hash_version'], 2 );
eq( 'anchor is MACd', '' !== (string) ( $db->opts['wb_ledger_tail']['mac'] ?? '' ), true );
eq( 'mixed chain verifies', WB_Ledger::verify_chain(), true );
$db->rows[2]['after_json'] = '{"x":1}';
$db->rows[2]['entry_hash'] = WB_Ledger::hash_for( $db->rows[1]['entry_hash'], $db->rows[2], 'guess' );
eq( 'rewriting a keyed row without the key is found', WB_Ledger::verify_chain()['broken'] ?? null, 'hash' );
$GLOBALS['test_key'] = '';

section( 'S6: wb_event waits for COMMIT' );
$db = fresh_db();
WB_Ledger::begin_defer();
eq( 'deferred write returns -1', wb_ledger_write( 'invoice_issued', 'wb_invoices', 7, null, [ 'n' => 1 ] ), -1 );
eq( 'no event inside the transaction', count( $GLOBALS['events'] ), 0 );
WB_Ledger::discard_deferred();
eq( 'rollback: no entry', count( $db->rows ), 0 );
eq( 'rollback: no event, ever', count( $GLOBALS['events'] ), 0 );
WB_Ledger::begin_defer();
wb_ledger_write( 'invoice_issued', 'wb_invoices', 8, null, [ 'n' => 2 ] );
wb_ledger_write( 'number_issued', 'wb_sequences', 8 );
eq( 'still nothing before flush', count( $GLOBALS['events'] ), 0 );
eq( 'flush: nothing lost', WB_Ledger::flush_deferred(), 0 );
eq( 'flush: entries chained', count( $db->rows ), 2 );
eq( 'flush: events fired after, in order, with their arguments', array_map( fn( $e ) => $e[1] . ':' . $e[3], $GLOBALS['events'] ), [ 'invoice_issued:8', 'number_issued:8' ] );
wb_ledger_write( 'plain', 't', 1 );
eq( 'outside a transaction the event fires at once', end( $GLOBALS['events'] )[1], 'plain' );

/* ===================================================================== S8 */
section( 'S8: storage key sanitiser' );
eq( '.%./.%./wp-config.php cannot climb', WB_Storage::sane_key( '.%./.%./wp-config.php' ), 'wp-config.php' );
eq( 'a/../b', WB_Storage::sane_key( 'a/../b' ), 'a/b' );
eq( './x', WB_Storage::sane_key( './x' ), 'x' );
eq( 'backslashes and leading slash', WB_Storage::sane_key( '\\..\\..\\etc/passwd' ), 'etc/passwd' );
eq( 'a normal key is unchanged', WB_Storage::sane_key( 'bank/2026/10/statement-abc.csv' ), 'bank/2026/10/statement-abc.csv' );
eq( 'dots inside a name are kept', WB_Storage::sane_key( 'docs/v1.2-sheet.pdf' ), 'docs/v1.2-sheet.pdf' );

/* ===================================================================== S9 */
section( 'S9: stored extension whitelist' );
eq( 'evil.php → bin', WB_Storage::safe_extension( 'evil.php' ), 'bin' );
eq( 'x.phtml → bin', WB_Storage::safe_extension( 'x.phtml' ), 'bin' );
eq( 'logo.svg → bin (script in an image)', WB_Storage::safe_extension( 'logo.svg' ), 'bin' );
eq( 'no extension → bin', WB_Storage::safe_extension( 'README' ), 'bin' );
eq( 'STATEMENT.CSV → csv', WB_Storage::safe_extension( 'STATEMENT.CSV' ), 'csv' );
eq( 'tsv kept', WB_Storage::safe_extension( 'a.tsv' ), 'tsv' );
eq( 'pdf kept', WB_Storage::safe_extension( 'sheet.pdf' ), 'pdf' );
eq( 'payslip html kept', WB_Storage::safe_extension( 'payslips/2026-10/payslip-4.html' ), 'html' );
$m = new ReflectionMethod( 'WB_Storage', 'unguessable' );
$m->setAccessible( true );
eq( 'uploaded evil.php is stored as .bin', (bool) preg_match( '#^bank/2026/10/evil-[a-z0-9]{16}\.bin$#', $m->invoke( null, 'bank/2026/10/evil.php' ) ), true );
eq( 'x.php.csv: no inner dot left', (bool) preg_match( '#^x-php-[a-z0-9]{16}\.csv$#', $m->invoke( null, 'x.php.csv' ) ), true );
eq( 'statement.csv keeps .csv', (bool) preg_match( '#^bank/statement-[a-z0-9]{16}\.csv$#', $m->invoke( null, 'bank/statement.csv' ) ), true );

/* ===================================================================== S10 */
section( 'S10: link routes that get the "link expired" page' );
eq( 'download', WB_Rest::is_link_route( '/wb/v1/download' ), true );
eq( 'private', WB_Rest::is_link_route( 'wb/v1/private/' ), true );
eq( 'payroll bank file', WB_Rest::is_link_route( '/wb/v1/payroll-bank-file' ), true );
eq( 'bank import stays JSON', WB_Rest::is_link_route( '/wb/v1/bank-import' ), false );
eq( 'other plugins untouched', WB_Rest::is_link_route( '/wp/v2/posts' ), false );

/* ===================================================================== S5 */
section( 'S5: the demo captures only its own creations' );
eq( 'a demo quote', WB_Demo::is_demo_creation( 'quote_created', 'wb_quotes', 4, null ), true );
eq( 'a demo customer (default action)', WB_Demo::is_demo_creation( 'wb_customers_created', 'wb_customers', 9, null ), true );
eq( 'a demo touchpoint', WB_Demo::is_demo_creation( 'touchpoint_quote_sent', 'wb_touchpoints', 3, null ), true );
eq( 'a demo staff member', WB_Demo::is_demo_creation( 'staff_added', 'wb_staff', 12, null, 11 ), true );
eq( 'never the owner\'s own staff row', WB_Demo::is_demo_creation( 'staff_added', 'wb_staff', 11, null, 11 ), false );
eq( 'never the tenant\'s leave types', WB_Demo::is_demo_creation( 'leave_type_seeded', 'wb_leave_types', 2, null ), false );
eq( 'a pure event about a real order is not a creation', WB_Demo::is_demo_creation( 'order_released', 'wb_orders', 5, null ), false );
eq( 'an update is not a creation', WB_Demo::is_demo_creation( 'quote_created', 'wb_quotes', 4, [ 'status' => 'draft' ] ), false );
eq( 'action and type must agree', WB_Demo::is_demo_creation( 'quote_created', 'wb_orders', 4, null ), false );
eq( 'unknown creations are not captured', WB_Demo::is_demo_creation( 'payroll_profile_created', 'wb_payroll_profiles', 1, null ), false );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
