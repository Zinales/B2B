<?php
/**
 * Regression tests for 0.3.5: every master record can be added and edited, every field, from the
 * schema (WB_Records). Pure functions, no WordPress.
 *
 *   php tests/regress-records.php
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
function get_users( ...$a ) { return []; }
class WB_Roles { public static function map() { return []; } }
class WB_CCT { public static function find( ...$a ) { return []; } public static function get( ...$a ) { return null; } public static function first( ...$a ) { return null; } }

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'render', 'records' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

section( 'every typed field is a real column' );
$schema = json_decode( (string) file_get_contents( WB_PLUGIN_DIR . 'schema/wb-ccts.json' ), true )['ccts'];
$unknown = []; $count = 0; $typed = [];
foreach ( WB_Records::TABLES as $slug => $pol ) {
	$cols = array_column( $schema[ $slug ]['fields'], 'name' );
	foreach ( WB_Records::fields( $slug ) as $name => $f ) { $count++; $typed[ $slug ][] = $name; if ( ! in_array( $name, $cols, true ) ) $unknown[] = $slug . '.' . $name; }
}
eq( 'no field in the policy is missing from the schema', $unknown, [] );
eq( 'nine master tables', count( WB_Records::TABLES ), 9 );
eq( 'the customer form carries every column a person should type (17 of 17 minus the engine\'s journey_stage)', count( $typed['wb_customers'] ), 16 );
eq( 'the staff form carries 15 columns', count( $typed['wb_staff'] ), 15 );
eq( 'the price tier form carries the discount', in_array( 'discount_pct', $typed['wb_price_tiers'], true ), true );
$engine = [ 'journey_stage', 'portal_wp_user_id', 'marketing_optin_at', 'datasheet_doc_id' ];
$leaked = [];
foreach ( $typed as $slug => $names ) foreach ( $names as $n ) if ( in_array( $n, $engine, true ) ) $leaked[] = $slug . '.' . $n;
eq( 'engine-owned columns are never typed', $leaked, [] );

section( 'field resolution' );
$f = WB_Records::fields( 'wb_customers' );
eq( 'a schema select becomes a select with screen words', $f['account_status'], [ 'type' => 'select', 'store' => 'select', 'label' => 'Account', 'required' => false, 'ref' => null, 'options' => [ 'open' => 'Open', 'on_hold' => 'On hold', 'closed' => 'Closed' ], 'note' => '', 'placeholder' => '', 'default' => 'open', 'enc' => false ] );
eq( 'a reference column becomes a select from its table', [ $f['price_tier_id']['type'], $f['price_tier_id']['ref'] ], [ 'select', [ 'wb_price_tiers', 'name' ] ] );
eq( 'a textarea stays a textarea', $f['billing_address']['type'], 'textarea' );
eq( 'a number stays a number', $f['credit_limit']['type'], 'number' );
$c = WB_Records::fields( 'wb_contacts' );
eq( 'a switcher becomes Yes/No', $c['is_primary']['options'], [ 'false' => 'No', 'true' => 'Yes' ] );
eq( 'a consent date is typed as a date and stored the way its column stores it', [ $c['popia_consent_at']['type'], $c['popia_consent_at']['store'] ], [ 'date', 'datetime-local' ] );
$s = WB_Records::fields( 'wb_staff' );
eq( 'an encrypted column is typed as plain text and marked', [ $s['id_number_enc']['type'], $s['id_number_enc']['enc'], $s['id_number_enc']['label'] ], [ 'text', true, 'ID number' ] );
eq( 'the login picker is the users list', $s['wp_user_id']['ref'], 'users' );

section( 'cleaning what was typed' );
[ $row, $bad ] = WB_Records::clean( 'wb_customers', [ 'name' => '  Karoo Agri (Pty) Ltd ', 'payment_terms_days' => '30', 'credit_limit' => '50 000,00', 'account_status' => 'open', 'price_tier_id' => '3', 'currency' => 'zar', 'notes' => "Collects <b>Fridays</b>\nAsk for Pieter" ], false );
eq( 'no problems', $bad, [] );
eq( 'text is trimmed and stripped', $row['name'], 'Karoo Agri (Pty) Ltd' );
eq( 'a number with spaces and a comma is read', $row['credit_limit'], 50000.0 );
eq( 'R in front is not a number', WB_Records::clean( 'wb_customers', [ 'name' => 'X', 'credit_limit' => 'R50' ], false )[1], [ 'Credit limit: "R50" is not a number (write 1200.50, no R, spaces or commas).' ] );
eq( 'a reference is a whole number', $row['price_tier_id'], 3 );
eq( 'a blank reference is 0', $row['rep_staff_id'], 0 );
eq( 'a typed value wins over the default', $row['currency'], 'zar' );
eq( 'a blank text takes its default on add', WB_Records::clean( 'wb_customers', [ 'name' => 'X' ], false )[0]['currency'], 'ZAR' );
eq( 'a textarea keeps its lines and loses its tags', $row['notes'], "Collects Fridays\nAsk for Pieter" );
eq( 'a blank required field is named', WB_Records::clean( 'wb_customers', [ 'name' => '' ], false )[1], [ 'Company name is needed.' ] );
eq( 'a select off the list is refused with the choices', WB_Records::clean( 'wb_customers', [ 'name' => 'X', 'account_status' => 'frozen' ], false )[1], [ 'Account: "frozen" is not one of the choices (open, on_hold, closed).' ] );
[ $row, $bad ] = WB_Records::clean( 'wb_contacts', [ 'customer_id' => '4', 'first_name' => 'Thandi', 'email' => 'Thandi@KarooAgri.co.za', 'is_primary' => 'true', 'popia_consent_at' => '8 October 2026' ], false );
eq( 'email is lower-cased', $row['email'], 'thandi@karooagri.co.za' );
eq( 'a switcher stores true/false like JetEngine', [ $row['is_primary'], $row['receives_invoices'] ], [ 'true', '' ] );
eq( 'a date typed in words is stored the way its column stores it', $row['popia_consent_at'], '2026-10-08 00:00:00' );
eq( 'a bad email is refused', WB_Records::clean( 'wb_contacts', [ 'customer_id' => '4', 'first_name' => 'T', 'email' => 'not-an-email' ], false )[1], [ 'Check the email address.' ] );
eq( 'a bad date is refused', WB_Records::clean( 'wb_contacts', [ 'customer_id' => '4', 'first_name' => 'T', 'popia_consent_at' => 'soon' ], false )[1], [ 'POPIA consent given on: "soon" is not a date.' ] );
[ $row ] = WB_Records::clean( 'wb_products', [ 'sku' => 'adh-ep200', 'name' => 'Epoxy', 'spec_json' => "Viscosity | 12000 | mPa·s\n\nCure time | 24 | h" ], false );
eq( 'a product code is upper-cased', $row['sku'], 'ADH-EP200' );
eq( 'specification lines become rows', $row['spec_json'], '[{"label":"Viscosity","value":"12000","unit":"mPa·s"},{"label":"Cure time","value":"24","unit":"h"}]' );
eq( 'and come back as lines', WB_Records::json_to_lines( $row['spec_json'] ), "Viscosity | 12000 | mPa·s\nCure time | 24 | h" );
eq( 'template rows have no value', WB_Records::lines_to_json( 'Viscosity | mPa·s' ), '[{"label":"Viscosity","unit":"mPa·s"}]' );
eq( 'nothing typed → nothing stored', WB_Records::lines_to_json( "\n  \n" ), '' );
[ $new ] = WB_Records::clean( 'wb_staff', [ 'first_name' => 'T', 'last_name' => 'M', 'id_number_enc' => '8001015009087' ], false );
eq( 'an encrypted field is stored through wb_enc', $new['id_number_enc'], 'enc:8001015009087' );
[ $edited ] = WB_Records::clean( 'wb_staff', [ 'first_name' => 'T', 'last_name' => 'M', 'id_number_enc' => '' ], true );
eq( 'blank on edit means keep what is on file', array_key_exists( 'id_number_enc', $edited ), false );
[ $added ] = WB_Records::clean( 'wb_staff', [ 'first_name' => 'T', 'last_name' => 'M' ], false );
eq( 'blank on add is stored blank', $added['id_number_enc'], '' );
eq( 'numbers default on add', [ $added['hours_per_week'], $added['days_per_week'] ], [ 45, 5 ] );
[ $edited ] = WB_Records::clean( 'wb_staff', [ 'first_name' => 'T', 'last_name' => 'M', 'hours_per_week' => '' ], true );
eq( 'a blank number on edit stays blank (not the default)', $edited['hours_per_week'], '' );

section( 'the form' );
$html = WB_Records::form( 'wb_price_tiers', null );
eq( 'the form posts to the one handler with the table', 0 === strpos( $html, '<form method="post" class="wb-form"><input type="hidden" name="wb_panel" value="record_save"><input type="hidden" name="record_cct" value="wb_price_tiers"><input type="hidden" name="record_id" value="0">' ), true );
eq( 'required fields carry the mark', false !== strpos( $html, '<span>Name <span class="wb-req" aria-hidden="true">*</span></span>' ), true );
eq( 'a placeholder is an example, never an instruction', false !== strpos( $html, 'placeholder="Distributor"' ), true );
eq( 'the button names the record', false !== strpos( $html, '>Add price tier</button>' ), true );
$html = WB_Records::form( 'wb_price_tiers', [ '_ID' => 7, 'name' => 'Trade', 'discount_pct' => '10', 'is_default' => 'true' ] );
eq( 'editing carries the id and the values', false !== strpos( $html, 'name="record_id" value="7"' ) && false !== strpos( $html, 'value="Trade"' ) && false !== strpos( $html, '>Save changes</button>' ), true );
$html = WB_Records::form( 'wb_staff', [ '_ID' => 2, 'first_name' => 'T', 'last_name' => 'M', 'id_number_enc' => 'enc:x' ] );
eq( 'an encrypted value is never printed back', false === strpos( $html, 'enc:x' ) && false !== strpos( $html, 'placeholder="On file — type to replace"' ), true );
eq( 'a name for a row', [ WB_Records::name_of( 'wb_customers', [ 'name' => 'Karoo Agri' ] ), WB_Records::name_of( 'wb_staff', [ 'first_name' => 'Thandi', 'last_name' => 'Mokoena' ] ), WB_Records::name_of( 'wb_price_tiers', [ '_ID' => 4 ] ) ], [ 'Karoo Agri', 'Thandi Mokoena', 'price tier #4' ] );
eq( 'the first table on a screen owns #wb-add, the rest their own anchor', [ WB_Records::anchor( 'wb_customers' ), WB_Records::anchor( 'wb_contacts' ), WB_Records::anchor( 'wb_products' ), WB_Records::anchor( 'wb_price_tiers' ) ], [ 'wb-add', 'wb-add-contacts', 'wb-add', 'wb-add-price_tiers' ] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
