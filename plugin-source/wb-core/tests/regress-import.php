<?php
/**
 * Regression tests for 0.3.6: upload a whole table from a CSV (WB_Import). Pure functions; the
 * two lookups (references by name, what is already on file) are closures over small arrays.
 *
 *   php tests/regress-import.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return strtolower( trim( (string) $s ) ); }
function is_email( $s ) { return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_html_class( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function selected( ...$a ) { return ''; }
function wp_nonce_field( ...$a ) { return ''; }
function wb_return_field() { return ''; }
function wb_enc( string $plain ): string { return 'enc:' . $plain; }
function current_user_can( $c ) { return true; }
function add_filter( ...$a ) {}
function add_action( ...$a ) {}
function get_users( ...$a ) { return []; }
class WB_Roles { public static function map() { return []; } }
class WB_CCT { public static function find( ...$a ) { return []; } public static function get( ...$a ) { return null; } public static function first( ...$a ) { return null; } }
date_default_timezone_set( 'UTC' );

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'render', 'records', 'payments', 'import' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/* the world: two price tiers, one customer, one staff login */
$tiers = [ 1 => [ '_ID' => 1, 'name' => 'Trade' ], 2 => [ '_ID' => 2, 'name' => 'Distributor' ] ];
$customers = [ 9 => [ '_ID' => 9, 'name' => 'Karoo Agri (Pty) Ltd' ] ];
$lookup = function ( string $cct, ?string $col, string $value ) use ( $tiers, $customers ) {
	if ( 'users' === $cct ) return 'thandi' === $value ? 5 : null;
	$pool = 'wb_price_tiers' === $cct ? $tiers : ( 'wb_customers' === $cct ? $customers : [] );
	foreach ( $pool as $r ) if ( strcasecmp( (string) $r[ $col ], $value ) === 0 ) return (int) $r['_ID'];
	return null;
};
$existing = function ( string $slug, string $col, $value ) use ( $tiers, $customers ) {
	$pool = 'wb_price_tiers' === $slug ? $tiers : ( 'wb_customers' === $slug ? $customers : [] );
	foreach ( $pool as $r ) if ( '_ID' === $col ? (int) $r['_ID'] === (int) $value : strcasecmp( (string) ( $r[ $col ] ?? '' ), (string) $value ) === 0 ) return $r;
	return null;
};

section( 'the layout' );
eq( '_ID first, then every typed field', array_slice( WB_Import::headers( 'wb_price_tiers' ), 0, 4 ), [ '_ID', 'name', 'discount_pct', 'is_default' ] );
eq( 'the example row comes from the form and switches every yes/no off', WB_Import::example_row( 'wb_price_tiers' ), [ '', 'Distributor', '12.5', 'false' ] );
eq( 'the example never carries an encrypted value or a reference', [ WB_Import::example_row( 'wb_staff' )[ array_search( 'id_number_enc', WB_Import::headers( 'wb_staff' ), true ) ], WB_Import::example_row( 'wb_customers' )[ array_search( 'price_tier_id', WB_Import::headers( 'wb_customers' ), true ) ] ], [ '', '' ] );
$m = WB_Import::header_map( 'wb_customers' );
eq( 'a header may be the column name or the screen word, any case', [ $m['name'], $m['company_name'], $m['days_to_pay'], $m['payment_terms_days'], $m['id'], $m['their_rep'] ], [ 'name', 'name', 'payment_terms_days', 'payment_terms_days', '_ID', 'rep_staff_id' ] );
eq( 'normalising a header', [ WB_Import::norm( ' Company Name ' ), WB_Import::norm( '"% off the list price"' ), WB_Import::norm( 'Days-to-pay' ) ], [ 'company_name', '%_off_the_list_price', 'days_to_pay' ] );
eq( 'formula guard on the way out', [ WB_Import::guard( '=SUM(A1)' ), WB_Import::guard( '-5' ), WB_Import::guard( 'plain' ), WB_Import::guard( '' ) ], [ "'=SUM(A1)", "'-5", 'plain', '' ] );
eq( 'and off again on the way in', [ WB_Import::unguard( "'=SUM(A1)" ), WB_Import::unguard( "'Quoted" ), WB_Import::unguard( 'x' ) ], [ '=SUM(A1)', "'Quoted", 'x' ] );
eq( 'CSV out: BOM, quoted, doubled quotes, CRLF', WB_Import::to_csv( [ [ '_ID', 'name' ], [ '', 'Karoo "K" Agri' ] ] ), "\xEF\xBB\xBF\"_ID\",\"name\"\r\n\"\",\"Karoo \"\"K\"\" Agri\"\r\n" );

section( 'a good file' );
$csv = "Company name,Days to pay,Credit limit,Price tier,Account\nKaroo Agri (Pty) Ltd,30,50 000,Distributor,open\nNew Co,0,,2,open\n";
$p = WB_Import::prepare( 'wb_customers', $csv, $lookup, $existing );
eq( 'a known name without its _ID is refused, so a re-upload can never make a twin', $p['errors'], [ 'Row 2: a customer with that name is already on file ("Karoo Agri (Pty) Ltd", _ID 9). Put its _ID in the row to update it.' ] );
$csv = "_ID,Company name,Days to pay,Credit limit,Price tier,Account\n9,Karoo Agri (Pty) Ltd,30,50 000,Distributor,open\n,New Co,0,,2,open\n";
$p = WB_Import::prepare( 'wb_customers', $csv, $lookup, $existing );
eq( 'with the _ID the known one updates and the new one adds', [ $p['errors'], $p['create'], $p['update'] ], [ [], 1, 1 ] );
eq( 'the reference by name became its id, and by number stayed', [ $p['rows'][0][1]['price_tier_id'], $p['rows'][1][1]['price_tier_id'] ], [ 2, 2 ] );
eq( 'a number with a space was read', $p['rows'][0][1]['credit_limit'], 50000.0 );
eq( 'a text default applies on add (currency ZAR) and not on update', [ $p['rows'][1][1]['currency'], $p['rows'][0][1]['currency'] ], [ 'ZAR', '' ] );
eq( 'the ids ride with the rows', [ $p['rows'][0][0], $p['rows'][1][0] ], [ 9, 0 ] );
$semi = "_ID;Company name;Days to pay\n;Semi Co;14\n";
eq( 'a semicolon file reads the same', WB_Import::prepare( 'wb_customers', $semi, $lookup, $existing )['rows'][0][1]['name'], 'Semi Co' );
$bom = "\xEF\xBB\xBF_ID,Company name\n,Bom Co\n";
eq( 'a BOM is ignored', WB_Import::prepare( 'wb_customers', $bom, $lookup, $existing )['rows'][0][1]['name'], 'Bom Co' );
$blank = "\n\n_ID,Company name\n,Late Co\n\n";
eq( 'blank lines before the header and after the rows are skipped, row numbers stay honest', WB_Import::prepare( 'wb_customers', $blank, $lookup, $existing )['create'], 1 );

section( 'files that are refused, with the row and the fix' );
$p = WB_Import::prepare( 'wb_customers', "_ID,Company name,Colour\n,X,red\n", $lookup, $existing );
eq( 'an unknown column refuses the whole file and lists the columns', 0 === strpos( $p['errors'][0], 'Column "Colour" is not one this table takes. The columns are: _ID, name, trading_name' ), true );
eq( '… and nothing is prepared', $p['rows'], [] );
$p = WB_Import::prepare( 'wb_customers', "Trading as,Region\nK,WC\n", $lookup, $existing );
eq( 'a missing required column is named', $p['errors'], [ 'The column "Company name" is needed and is not in the file.' ] );
$p = WB_Import::prepare( 'wb_customers', "_ID,Company name,Credit limit\n,A Co,R500\n,B Co,12\n", $lookup, $existing );
eq( 'a bad value names its row and the fix', $p['errors'], [ 'Row 2: Credit limit: "R500" is not a number (write 1200.50, no R, spaces or commas).' ] );
eq( 'one bad row refuses the file: nothing is prepared', $p['rows'], [] );
$p = WB_Import::prepare( 'wb_customers', "_ID,Company name,Price tier\n,A Co,Wholesale\n", $lookup, $existing );
eq( 'a reference that is not found', $p['errors'], [ 'Row 2: Price tier "Wholesale" not found — use its exact name or its _ID.' ] );
$p = WB_Import::prepare( 'wb_staff', "_ID,First name,Last name,Login\n,T,M,nobody\n", $lookup, $existing );
eq( 'a login that is not found', $p['errors'], [ 'Row 2: Login "nobody" not found — use its exact name (the login name or email).' ] );
eq( 'a login that is found becomes its id', WB_Import::prepare( 'wb_staff', "_ID,First name,Last name,Login\n,T,M,thandi\n", $lookup, $existing )['rows'][0][1]['wp_user_id'], 5 );
$p = WB_Import::prepare( 'wb_customers', "_ID,Company name\n,Twice Co\n,twice co\n", $lookup, $existing );
eq( 'the same key twice in one file', $p['errors'], [ 'Row 3: name "twice co" is also on row 2.' ] );
$p = WB_Import::prepare( 'wb_customers', "_ID,Company name\n,Karoo Agri (Pty) Ltd\n", $lookup, $existing );
eq( 'a key already on file without its _ID', $p['errors'], [ 'Row 2: a customer with that name is already on file ("Karoo Agri (Pty) Ltd", _ID 9). Put its _ID in the row to update it.' ] );
$p = WB_Import::prepare( 'wb_customers', "_ID,Company name\n77,Ghost Co\n", $lookup, $existing );
eq( 'an _ID that does not exist', $p['errors'], [ 'Row 2: there is no customer with _ID 77. Leave it blank to add a new one.' ] );
$p = WB_Import::prepare( 'wb_customers', "_ID,Company name\nabc,Ghost Co\n", $lookup, $existing );
eq( 'an _ID that is not a number', $p['errors'], [ 'Row 2: _ID "abc" must be a whole number, or blank for a new customer.' ] );
$p = WB_Import::prepare( 'wb_customers', "_ID,Company name\n,Karoo, Agri\n", $lookup, $existing );
eq( 'an unquoted comma', $p['errors'], [ 'Row 2: more cells than columns — put quotes around any value that contains a comma.' ] );
eq( 'an empty file', WB_Import::prepare( 'wb_customers', "  \n", $lookup, $existing )['errors'], [ 'That file is empty.' ] );
eq( 'a header with nothing under it', WB_Import::prepare( 'wb_customers', "_ID,Company name\n", $lookup, $existing )['errors'], [ 'The file has a header row and nothing under it.' ] );
eq( 'a table that cannot be uploaded', WB_Import::prepare( 'wb_invoices', "x\n1\n", $lookup, $existing )['errors'], [ 'That is not a table that can be uploaded.' ] );
$p = WB_Import::prepare( 'wb_staff', "_ID,First name,Last name,ID number\n,T,M,8001015009087\n", $lookup, $existing );
eq( 'an encrypted column is accepted by its plain word and stored encrypted', $p['rows'][0][1]['id_number_enc'], 'enc:8001015009087' );
$big = "_ID,Company name\n" . str_repeat( ",Row Co\n", 5001 );
eq( 'too many rows', WB_Import::prepare( 'wb_customers', $big, $lookup, $existing )['errors'], [ 'That file has more than 5,000 rows. Split it into smaller files.' ] );

section( 'the words' );
$ok = [ 'rows' => [ [ 0, [] ], [ 4, [] ] ], 'errors' => [], 'create' => 1, 'update' => 1 ];
eq( 'a check', WB_Import::summary( $ok, false, 'customer' ), 'File looks good: 2 customers (1 new, 1 to update) ready. Nothing has been imported yet — choose "Validate and import" to go ahead.' );
eq( 'an import', WB_Import::summary( $ok, true, 'customer' ), 'Imported 2 customers (1 new, 1 to update).' );
eq( 'one', WB_Import::summary( [ 'rows' => [ [ 0, [] ] ], 'errors' => [], 'create' => 1, 'update' => 0 ], true, 'supplier' ), 'Imported 1 supplier (1 new, 0 to update).' );
$errs = []; for ( $i = 1; $i <= 30; $i++ ) $errs[] = "Row {$i}: x";
$s = WB_Import::summary( [ 'rows' => [], 'errors' => $errs, 'create' => 0, 'update' => 0 ], false, 'customer' );
eq( 'errors: 25 shown, then how many more', [ 0 === strpos( $s, 'Nothing was imported. Fix these and try again: Row 1: x' ), false !== strpos( $s, 'Row 25: x … and 5 more.' ), false === strpos( $s, 'Row 26' ) ], [ true, true, true ] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
