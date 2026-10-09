<?php
/**
 * Regression tests for 1.5.0 (review of 9 October): sending documents by email, and the statement.
 *  1. fill(): placeholders filled; one with no value left out, never shown as {key}.
 *  2. recipients(): who is ticked for each kind of document.
 *  3. addresses(): what a person types in "Another address".
 *  4. Every kind has a template that fills completely, and the cap and screen it needs.
 *  5. The statement's HTML: open items only, days late, the ageing, the bank details, real PDF bytes.
 *
 *   php tests/regress-send.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wb-send-test-' . getmypid() );
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; } function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0750, true ); }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function apply_filters( $h, $v ) { return $v; } function add_shortcode( ...$a ) {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $s ) { return esc_html( $s ); }
function get_option( $k, $d = false ) { return $d; }

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'render', 'storage', 'pdf', 'docs', 'pages', 'statements', 'send' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( is_string( $got ) && strlen( $got ) > 300 ? substr( $got, 0, 300 ) . '…' : $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/* ============================================================ 1 */
section( 'filling the words' );
eq( 'filled', WB_Send::fill( 'Quote {number} for R {total}', [ 'number' => 'QUO-2026-000012', 'total' => '1 483.50' ] ), 'Quote QUO-2026-000012 for R 1 483.50' );
eq( 'an unknown placeholder is left out', WB_Send::fill( 'Hello {nobody}there', [] ), 'Hello there' );
eq( 'braces that are not placeholders stay', WB_Send::fill( 'Keep {Not A Key} as typed', [] ), 'Keep {Not A Key} as typed' );
eq( 'a value is not filled twice', WB_Send::fill( '{a}', [ 'a' => '{b}', 'b' => 'x' ] ), '{b}' );

/* ============================================================ 2 */
section( 'who gets it' );
$c = [
	[ '_ID' => 1, 'first_name' => 'Thandi', 'last_name' => 'Mokoena', 'email' => 'Thandi@KarooAgri.co.za', 'is_primary' => 'true', 'receives_invoices' => 'false', 'receives_datasheets' => 'true' ],
	[ '_ID' => 2, 'first_name' => 'Accounts', 'last_name' => '', 'email' => 'accounts@karooagri.co.za', 'is_primary' => 'false', 'receives_invoices' => 'true', 'receives_datasheets' => 'false' ],
	[ '_ID' => 3, 'first_name' => 'Pieter', 'last_name' => 'Botha', 'email' => 'pieter@karooagri.co.za', 'is_primary' => 'false', 'receives_invoices' => 'false', 'receives_datasheets' => 'false' ],
	[ '_ID' => 4, 'first_name' => 'No', 'last_name' => 'Email', 'email' => ' ', 'is_primary' => 'false', 'receives_invoices' => 'true' ],
];
$ticked = fn( array $r ) => array_column( array_filter( $r, fn( $x ) => $x[2] ), 0 );
eq( 'contacts with no email are not offered', count( WB_Send::recipients( $c, 'invoice' ) ), 3 );
eq( 'addresses are lower case', WB_Send::recipients( $c, 'invoice' )[0][0], 'thandi@karooagri.co.za' );
eq( 'an invoice goes to accounts', $ticked( WB_Send::recipients( $c, 'invoice' ) ), [ 'accounts@karooagri.co.za' ] );
eq( 'a statement too', $ticked( WB_Send::recipients( $c, 'statement' ) ), [ 'accounts@karooagri.co.za' ] );
eq( 'a reminder too', $ticked( WB_Send::recipients( $c, 'reminder' ) ), [ 'accounts@karooagri.co.za' ] );
eq( 'a datasheet to whoever receives datasheets', $ticked( WB_Send::recipients( $c, 'datasheet' ) ), [ 'thandi@karooagri.co.za' ] );
eq( 'a quote to the contact it was written for', $ticked( WB_Send::recipients( $c, 'quote', 3 ) ), [ 'pieter@karooagri.co.za' ] );
eq( 'a quote with no contact goes to the main contact', $ticked( WB_Send::recipients( $c, 'quote' ) ), [ 'thandi@karooagri.co.za' ] );
$nobody = array_map( fn( $x ) => array_merge( $x, [ 'receives_invoices' => 'false' ] ), $c );
eq( 'nobody marked for invoices: the main contact', $ticked( WB_Send::recipients( $nobody, 'invoice' ) ), [ 'thandi@karooagri.co.za' ] );
$nomain = array_map( fn( $x ) => array_merge( $x, [ 'receives_invoices' => 'false', 'is_primary' => 'false' ] ), $c );
eq( 'no main contact either: the first with an email', $ticked( WB_Send::recipients( $nomain, 'invoice' ) ), [ 'thandi@karooagri.co.za' ] );
eq( 'no contacts at all', WB_Send::recipients( [], 'invoice' ), [] );

/* ============================================================ 3 */
section( 'typed addresses' );
eq( 'one', WB_Send::addresses( 'buyer@acme.co.za' ), [ [ 'buyer@acme.co.za' ], [] ] );
eq( 'several, any separator, deduplicated, lower case', WB_Send::addresses( "A@acme.co.za; b@acme.co.za,  a@ACME.co.za\nc@x.com" ), [ [ 'a@acme.co.za', 'b@acme.co.za', 'c@x.com' ], [] ] );
eq( 'a bad one is named', WB_Send::addresses( 'good@acme.co.za, not-an-address' ), [ [ 'good@acme.co.za' ], [ 'not-an-address' ] ] );
eq( 'nothing typed', WB_Send::addresses( '   ' ), [ [], [] ] );

/* ============================================================ 4 */
section( 'every kind' );
$vars = [ 'company' => 'Demo Technical Supplies', 'me' => 'Thandi', 'contact' => 'Pieter', 'customer' => 'Karoo Agri', 'number' => 'X-1', 'total' => '1.00', 'date' => '2026-10-09', 'valid_until' => '2026-11-08', 'link' => 'https://x', 'due' => '2026-11-08', 'invoice' => 'INV-1', 'owing' => '9.00', 'overdue' => '4.00', 'product' => 'ADH-EP200', 'link_line' => '' ];
foreach ( WB_Send::KINDS as $k => $def ) {
	eq( "{$k} has a template", isset( WB_Send::DEFAULTS[ $k ] ), true );
	eq( "{$k}: every placeholder in the standard words is known", preg_match( '/\{[a-z_]+\}/', WB_Send::fill( implode( "\n", WB_Send::DEFAULTS[ $k ] ), $vars ) ), 0 );
	eq( "{$k}: every placeholder used is documented", array_diff( preg_match_all( '/\{([a-z_]+)\}/', implode( "\n", WB_Send::DEFAULTS[ $k ] ), $m ) ? $m[0] : [], explode( ' ', preg_replace( '/[^{}a-z_ ]/', ' ', WB_Send::PLACEHOLDERS ) ) ), [] );
	eq( "{$k} needs a capability", 0 === strpos( $def[1], 'wb_' ), true );
}
eq( 'a quote email carries the acceptance link', false !== strpos( WB_Send::DEFAULTS['quote'][1], '{link}' ), true );
eq( 'an invoice email asks for the invoice number as the reference', false !== strpos( WB_Send::DEFAULTS['invoice'][1], 'use {number} as the reference' ), true );
eq( 'a reminder is polite about payments in flight', false !== strpos( WB_Send::DEFAULTS['reminder'][1], 'If you have paid in the last few days' ), true );

/* ============================================================ 5 */
section( 'the statement' );
$brand = [ 'display_name' => 'Demo Technical Supplies', 'legal_name' => 'Demo Technical Supplies (Pty) Ltd', 'reg_number' => '2019/123456/07', 'vat_registered' => 'yes', 'vat_number' => '4123456789', 'physical_address' => "12 Main Road\nOudtshoorn 6620",
	'bank_details' => 'FNB · Cheque · 62012345678 · Branch 250655', 'doc_footer' => 'E&OE.', 'colors' => [ 'primary' => '#8A3B52', 'ink' => '#0B1F3A' ], 'logo' => '' ];
$inv = [
	[ 'invoice_number' => 'INV-2026-000031', 'issued_at' => '2026-07-01 10:00:00', 'due_at' => '2026-07-31', 'total' => 2000, 'amount_paid' => 500, 'amount_credited' => 0 ],
	[ 'invoice_number' => 'INV-2026-000044', 'issued_at' => '2026-09-20 10:00:00', 'due_at' => '2026-10-20', 'total' => 1000, 'amount_paid' => 0, 'amount_credited' => 0 ],
	[ 'invoice_number' => 'INV-2026-000040', 'issued_at' => '2026-09-01 10:00:00', 'due_at' => '2026-10-01', 'total' => 300, 'amount_paid' => 300, 'amount_credited' => 0 ],
];
$d = [ 'customer' => [ 'name' => 'Karoo Agri (Pty) Ltd', 'billing_address' => "4 Vlei Street\nBeaufort West 6970", 'vat_number' => '4987654321' ], 'date' => '2026-10-09', 'invoices' => $inv, 'ageing' => WB_Pages::ageing( $inv, '2026-10-09' ), 'reference' => 'ACC-7' ];
$h = WB_Statements::html( $d, $brand );
eq( 'a whole document', 0 === strpos( $h, '<!DOCTYPE html>' ), true );
eq( 'the customer and the date', false !== strpos( $h, 'Karoo Agri (Pty) Ltd' ) && false !== strpos( $h, '2026-10-09' ), true );
eq( 'the balance owing at the top', false !== strpos( $h, 'R 2 500.00' ), true );
eq( 'open items only: a paid invoice is left off', false === strpos( $h, 'INV-2026-000040' ), true );
eq( 'the part-paid one shows what is left', false !== strpos( $h, '<td class="n">1 500.00</td>' ), true );
eq( 'days late on the late one', false !== strpos( $h, '<td class="n late">70</td>' ), true );
eq( 'the not-yet-due one is not late', 1 === substr_count( $h, ' late">' ), true );
eq( 'the ageing row', false !== strpos( $h, '61 to 90 days' ) && false !== strpos( $h, 'R 1 500.00' ), true );
eq( 'the bank details and the reference', false !== strpos( $h, 'FNB · Cheque' ) && false !== strpos( $h, 'ACC-7 for several at once' ), true );
eq( 'the overdue line', false !== strpos( $h, 'R 1 500.00 is past its due date' ), true );
$clear = WB_Statements::html( [ 'invoices' => [] , 'ageing' => WB_Pages::ageing( [], '2026-10-09' ) ] + $d, $brand );
eq( 'nothing owing says thank you', false !== strpos( $clear, 'Nothing is owing. Thank you.' ) && false === strpos( $clear, 'past its due date' ), true );
eq( 'real PDF bytes', 0 === strpos( WB_Pdf::render( $h ), '%PDF' ), true );

if ( is_dir( WP_CONTENT_DIR ) ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $x ) $x->isDir() ? rmdir( $x ) : unlink( $x ); rmdir( WP_CONTENT_DIR ); }
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
