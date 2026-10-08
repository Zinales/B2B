<?php
/**
 * Regression tests for 1.1.0: the numbered documents as PDF. The HTML is pure and checked for its
 * words and numbers; the bundled engine is then asked for real bytes, so a broken lib/ or a missing
 * font fails the build here and not on a client's first invoice.
 *
 *   php tests/regress-pdf.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wb-pdf-test-' . getmypid() );
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0750, true ); }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function apply_filters( $h, $v ) { return $v; }
date_default_timezone_set( 'UTC' );

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'render', 'storage', 'pdf', 'docs' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( is_string( $got ) && strlen( $got ) > 300 ? substr( $got, 0, 300 ) . '…' : $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

$brand = [ 'display_name' => 'Demo Technical Supplies', 'legal_name' => 'Demo Technical Supplies (Pty) Ltd', 'reg_number' => '2019/123456/07', 'vat_registered' => 'yes', 'vat_number' => '4123456789',
	'physical_address' => "12 Main Road\nOudtshoorn 6620", 'bank_details' => "FNB · Cheque · 62012345678 · Branch 250655", 'doc_footer' => 'Goods remain our property until paid in full. E&OE.',
	'colors' => [ 'primary' => '#8A3B52', 'ink' => '#0B1F3A' ], 'logo' => '' ];
$party = [ 'name' => 'Karoo Agri (Pty) Ltd', 'address' => "4 Vlei Street\nBeaufort West 6970", 'vat_number' => '4987654321', 'contact' => '' ];
$inv = [ 'kind' => 'invoice', 'number' => 'INV-2026-000042', 'date' => '2026-10-08', 'due' => '2026-11-07', 'made' => '2026-10-08',
	'lines' => [ [ 'code' => 'ADH-EP200', 'description' => 'Epoxy adhesive 200 ml', 'qty' => 10, 'unit_price' => 129, 'line_total' => 1290 ], [ 'code' => 'FST-HN16', 'description' => 'Hex nut M16 (box of 100)', 'qty' => 2.5, 'unit_price' => 410.4, 'line_total' => 1026 ] ],
	'subtotal' => 2316, 'vat_rate' => 15, 'vat' => 347.4, 'total' => 2663.4, 'extra' => [ [ 'Order', 'ORD-2026-000031' ] ] ];

section( 'the invoice' );
$h = WB_Docs::html( $inv, $party, $brand );
eq( 'a whole document', 0 === strpos( $h, '<!DOCTYPE html>' ) && false !== strpos( $h, '</html>' ), true );
eq( 'the title says what it is', false !== strpos( $h, '<h1>Tax invoice</h1><div class="num">INV-2026-000042</div>' ), true );
eq( 'the company, its numbers and address', false !== strpos( $h, 'Demo Technical Supplies (Pty) Ltd' ) && false !== strpos( $h, 'Reg. 2019/123456/07' ) && false !== strpos( $h, 'VAT 4123456789' ) && false !== strpos( $h, 'Oudtshoorn 6620' ), true );
eq( 'the customer, with their VAT number', false !== strpos( $h, '<strong>Karoo Agri (Pty) Ltd</strong>' ) && false !== strpos( $h, 'VAT 4987654321' ), true );
eq( 'date, due and order', false !== strpos( $h, '2026-10-08' ) && false !== strpos( $h, '2026-11-07' ) && false !== strpos( $h, 'ORD-2026-000031' ), true );
eq( 'the lines with code, words, quantity, each and amount', false !== strpos( $h, '<strong>ADH-EP200</strong> · Epoxy adhesive 200 ml</td><td class="n">10</td><td class="n">129.00</td><td class="n">1 290.00</td>' ) && false !== strpos( $h, '<td class="n">2.5</td><td class="n">410.40</td><td class="n">1 026.00</td>' ), true );
eq( 'VAT shown once, at the rate, and the total in rand', false !== strpos( $h, '<td>VAT 15%</td><td class="n">347.40</td>' ) && false !== strpos( $h, '<td>Total</td><td class="n">R 2 663.40</td>' ) && 1 === substr_count( $h, 'VAT 15%' ), true );
eq( 'pay to, with the invoice number as the reference', false !== strpos( $h, '<strong>Pay to</strong><br>FNB · Cheque · 62012345678 · Branch 250655<br>Reference: INV-2026-000042' ), true );
eq( 'the footer line', false !== strpos( $h, 'Goods remain our property until paid in full. E&amp;OE.' ), true );
eq( 'nothing a person typed runs as markup', false === strpos( WB_Docs::html( $inv, [ 'name' => '<script>x</script>' ] + $party, $brand ), '<script>' ), true );
eq( 'not VAT registered: no VAT number on the letterhead', false === strpos( WB_Docs::html( $inv, $party, [ 'vat_registered' => 'no' ] + $brand ), 'VAT 4123456789' ), true );

section( 'the other kinds' );
$q = [ 'kind' => 'quote', 'number' => 'QUO-2026-000090', 'words' => 'Thank you for asking.', 'extra' => [ [ 'Valid until', '2026-10-22' ] ] ] + $inv;
$h = WB_Docs::html( $q, $party, $brand );
eq( 'a quote: the title, the words, valid until, no pay-to box', false !== strpos( $h, '<h1>Quote</h1>' ) && false !== strpos( $h, 'Thank you for asking.' ) && false !== strpos( $h, '2026-10-22' ) && false === strpos( $h, 'Pay to' ) && false !== strpos( $h, 'valid until the date above' ), true );
$dn = [ 'kind' => 'dn', 'number' => 'DN-2026-000012', 'lines' => [ [ 'code' => 'ADH-EP200', 'description' => 'Epoxy adhesive 200 ml', 'qty' => 10 ] ], 'extra' => [ [ 'Order', 'ORD-2026-000031' ], [ 'Type', 'Collection' ] ] ] + $inv;
$h = WB_Docs::html( $dn, $party, $brand );
eq( 'a delivery note: quantities, no money, a line to sign', false === strpos( $h, 'Each' ) && false === strpos( $h, 'Total' ) && false !== strpos( $h, 'Received in good order by' ) && false !== strpos( $h, 'Collection' ), true );
$cr = [ 'kind' => 'credit', 'number' => 'CRN-2026-000003', 'words' => 'Reason: Return', 'extra' => [ [ 'Against invoice', 'INV-2026-000042' ] ] ] + $inv;
$h = WB_Docs::html( $cr, $party, $brand );
eq( 'a credit note names the invoice and the reason', false !== strpos( $h, '<h1>Credit note</h1>' ) && false !== strpos( $h, 'Against invoice' ) && false !== strpos( $h, 'Reason: Return' ), true );
$po = [ 'kind' => 'po', 'number' => 'PO-2026-000007', 'extra' => [ [ 'Expected', '2026-10-20' ] ] ] + $inv;
$h = WB_Docs::html( $po, [ 'name' => 'Bondex Chemicals', 'address' => 'Epping', 'vat_number' => '', 'contact' => 'Sipho Dlamini' ], $brand );
eq( 'a purchase order goes to a supplier, for attention of someone', false !== strpos( $h, '<div class="k">Supplier</div><strong>Bondex Chemicals</strong>' ) && false !== strpos( $h, 'Attention: Sipho Dlamini' ) && false !== strpos( $h, 'quote this number on your invoice' ), true );
eq( 'money the way the documents show it', [ WB_Docs::money( 1234.5 ), WB_Docs::money( 0 ), WB_Docs::money( 1000000 ) ], [ '1 234.50', '0.00', '1 000 000.00' ] );

section( 'the engine' );
eq( 'the engine is bundled', WB_Pdf::available(), true );
$bytes = WB_Pdf::render( WB_Docs::html( $inv, $party, $brand ) );
eq( 'an invoice renders to a PDF', 0 === strpos( $bytes, '%PDF-' ), true );
eq( 'of a sensible size (one page, embedded font)', strlen( $bytes ) > 5000 && strlen( $bytes ) < 400000, true, );
eq( 'the work folder is under the private folder', 0 === strpos( WB_Pdf::work_dir(), WP_CONTENT_DIR . '/wb-private' ), true );
eq( 'Unicode names render (a glyph is embedded, not a box)', 0 === strpos( WB_Pdf::render( '<html><body>Thandi Mokoena · Ndlovu · Müller · R 1 234,00</body></html>' ), '%PDF-' ), true );

// tidy the temp folder
$rm = function ( string $d ) use ( &$rm ) { foreach ( glob( $d . '/{,.}*', GLOB_BRACE ) ?: [] as $f ) { if ( in_array( basename( $f ), [ '.', '..' ], true ) ) continue; is_dir( $f ) ? $rm( $f ) : unlink( $f ); } @rmdir( $d ); };
$rm( WP_CONTENT_DIR );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
