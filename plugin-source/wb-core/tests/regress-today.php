<?php
/**
 * Regression tests for 1.6.0 (review of 9 October).
 *  1. Today's month card: this month to date against the same days last year.
 *  2. The next four weeks from the forecast; the words for a change.
 *  3. Dates and money read one way everywhere ("8 Oct 2026", "R 12 345.00").
 *  4. The quick sale's report: what was done, where it stopped and why.
 *  5. Product pictures: what an upload may be.
 *
 *   php tests/regress-today.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $s ) { return (string) $s; }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function add_shortcode( ...$a ) {} function wp_strip_all_tags( $s ) { return strip_tags( $s ); } function wp_json_encode( $v ) { return json_encode( $v ); }
$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'render', 'today', 'quick-sale', 'product-images' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/* ============================================================ 1 */
section( 'the month so far' );
$inv = [
	[ 'issued_at' => '2026-10-02 09:00:00', 'subtotal' => 1000, 'status' => 'paid' ],
	[ 'issued_at' => '2026-10-09 16:00:00', 'subtotal' => 500, 'status' => 'issued' ],
	[ 'issued_at' => '2026-10-12 09:00:00', 'subtotal' => 9999, 'status' => 'issued' ],    // after today: not yet
	[ 'issued_at' => '2026-10-03 09:00:00', 'subtotal' => 777, 'status' => 'void' ],       // void: never counted
	[ 'issued_at' => '2025-10-05 09:00:00', 'subtotal' => 1200, 'status' => 'paid' ],      // last year, inside the same days
	[ 'issued_at' => '2025-10-20 09:00:00', 'subtotal' => 4000, 'status' => 'paid' ],      // last year, after the same days
	[ 'issued_at' => '2026-09-30 09:00:00', 'subtotal' => 300, 'status' => 'paid' ],       // last month
];
$pay = [ [ 'received_at' => '2026-10-05', 'amount' => 800 ], [ 'received_at' => '2025-10-01', 'amount' => 600 ], [ 'received_at' => '2025-10-31', 'amount' => 50 ] ];
$m = WB_Today::month( $inv, $pay, '2026-10-09' );
eq( 'sales so far this month, before VAT, void left out', $m['sales'], 1500.0 );
eq( 'the same days last year', $m['sales_ly'], 1200.0 );
eq( 'cash so far', $m['cash'], 800.0 );
eq( 'cash on the same days last year', $m['cash_ly'], 600.0 );
eq( 'the month in words', $m['month'], 'October' );
eq( 'how many days', $m['days'], 9 );

/* ============================================================ 2 */
section( 'the next four weeks' );
$rows = [
	[ 'week_start' => '2026-10-12', 'expected_receipts' => 10000, 'predicted_orders' => 2000, 'committed_purchases' => 3000, 'payroll' => 0 ],
	[ 'week_start' => '2026-10-19', 'expected_receipts' => 5000, 'predicted_orders' => 1000, 'committed_purchases' => 0, 'payroll' => 0 ],
	[ 'week_start' => '2026-10-26', 'expected_receipts' => 2000, 'predicted_orders' => 500, 'committed_purchases' => 1000, 'payroll' => 42000 ],
	[ 'week_start' => '2026-11-02', 'expected_receipts' => 8000, 'predicted_orders' => 0, 'committed_purchases' => 0, 'payroll' => 0 ],
	[ 'week_start' => '2026-11-09', 'expected_receipts' => 99999, 'predicted_orders' => 0, 'committed_purchases' => 0, 'payroll' => 0 ],
];
$n = WB_Today::next4( $rows );
eq( 'coming in: four weeks only', $n['in'], 28500.0 );
eq( 'going out', $n['out'], 46000.0 );
eq( 'left over can be less than nothing', $n['left'], -17500.0 );
eq( 'each week\'s net', array_column( $n['weeks'], 1 ), [ 9000.0, 6000.0, -40500.0, 8000.0 ] );
eq( 'no forecast yet', WB_Today::next4( [] ), [ 'in' => 0.0, 'out' => 0.0, 'left' => 0.0, 'weeks' => [] ] );
eq( 'up', WB_Today::change( 1500, 1200 ), 'up 25% on last year' );
eq( 'down', WB_Today::change( 900, 1200 ), 'down 25% on last year' );
eq( 'the same', WB_Today::change( 1201, 1200 ), 'the same as last year' );
eq( 'nothing last year: no words', WB_Today::change( 500, 0 ), '' );

/* ============================================================ 3 */
section( 'dates and money' );
eq( 'a date', WB_Render::date( '2026-10-08' ), '8 Oct 2026' );
eq( 'a moment drops its time', WB_Render::date( '2026-01-31 23:59:59' ), '31 Jan 2026' );
eq( 'empty stays empty', WB_Render::date( '' ), '' );
eq( 'a zero date is left alone', WB_Render::date( '0000-00-00' ), '0000-00-00' );
eq( 'words are left alone', WB_Render::date( 'tomorrow' ), 'tomorrow' );
eq( 'issued_at is a date column', WB_Render::is_date_col( 'issued_at' ), true );
eq( 'valid_until is a date column', WB_Render::is_date_col( 'valid_until' ), true );
eq( 'next_action_date is a date column', WB_Render::is_date_col( 'next_action_date' ), true );
eq( 'a status is not', WB_Render::is_date_col( 'status' ), false );
$t = WB_Render::render_table( [ [ 'invoice_number' => 'INV-1', 'issued_at' => '2026-10-08 10:00:00', 'total' => 12345.5 ] ], [ 'invoice_number', 'issued_at', [ 'key' => 'total', 'type' => 'money' ] ] );
eq( 'a table shows the date as people read it', false !== strpos( $t, '>8 Oct 2026<' ), true );
eq( 'and money with its R', false !== strpos( $t, '>R&nbsp;12 345.50<' ), true );

/* ============================================================ 4 */
section( 'the quick sale report' );
eq( 'all the way', WB_Quick_Sale::report( array_keys( WB_Quick_Sale::STEPS ) ), 'Sold and paid: quote made, marked sent, accepted as an order, invoiced, paid, released, collection note issued, signed for.' );
eq( 'refused before anything was written', WB_Quick_Sale::report( [], 'quote', 'Only 2 of ADH-CT5 is available.' ), 'Stopped before "quote made": Only 2 of ADH-CT5 is available. Nothing was written.' );
eq( 'stopped part-way says what is on record', WB_Quick_Sale::report( [ 'quote', 'sent', 'order', 'invoice' ], 'paid', 'Write the slip or receipt number.' ),
	'Done: quote made, marked sent, accepted as an order, invoiced. Stopped before "paid": Write the slip or receipt number. Everything done so far is on record; carry on from the order on the Orders screen.' );
eq( 'a counter sale needs all five kinds of work', WB_Quick_Sale::CAPS, [ 'wb_create_quotes', 'wb_send_quotes', 'wb_manage_orders', 'wb_match_payments', 'wb_issue_delivery_notes' ] );

/* ============================================================ 5 */
section( 'product pictures' );
$f = [ 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 120000, 'type' => 'image/jpeg' ];
eq( 'a JPG is fine', WB_Product_Images::problem( $f ), '' );
eq( 'a PNG is fine', WB_Product_Images::problem( [ 'type' => 'image/png' ] + $f ), '' );
eq( 'a PDF is not a picture', WB_Product_Images::problem( [ 'type' => 'application/pdf' ] + $f ), 'A product picture must be a JPG, PNG or WebP image.' );
eq( 'an SVG is refused (it can carry script)', WB_Product_Images::problem( [ 'type' => 'image/svg+xml' ] + $f ), 'A product picture must be a JPG, PNG or WebP image.' );
eq( 'too large', WB_Product_Images::problem( [ 'size' => 6 * 1024 * 1024 ] + $f ), 'That picture is larger than 5 MB. Save a smaller copy and try again.' );
eq( 'nothing chosen', WB_Product_Images::problem( [] ), 'Choose a picture to upload.' );
eq( 'an upload error', WB_Product_Images::problem( [ 'error' => 3 ] + $f ), 'Choose a picture to upload.' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
