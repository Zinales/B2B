<?php
/**
 * wb-core regression test — the money and stock fixes from the 4 October 2026 review
 * (docs/review-2026-10-04/FINDINGS.md, M-series), pure functions only, without WordPress.
 *
 *   php tests/regress-money.php
 *
 * Every expected value below was worked out by hand (see the comments), not copied from output.
 *
 * Stubbed: ABSPATH (so the class files load), and mb_substr when the CLI lacks mbstring.
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $len = null ) { return null === $len ? substr( (string) $s, $start ) : substr( (string) $s, $start, $len ); }
}
date_default_timezone_set( 'UTC' );

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'ledger', 'sequences', 'pricing', 'stock', 'invoices', 'orders', 'payments', 'integrity' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

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

/* ===================================================================== M4 */
section( 'M4: deposits described "Total…" / "Saldo…" are money in; real total rows are skipped' );
$csv = "Date,Description,Reference,Amount\n"
	. "2026/10/01,Total payment for INV-2026-000005,ACME,1150.00\n"   // a deposit: kept
	. "2026/10/02,Saldo betaling,XYZ,200.00\n"                         // Afrikaans "balance payment": kept
	. "2026/10/03,Total,,1350.00\n"                                    // a total row: skipped
	. "2026/10/03,Balance brought forward,,5000.00\n"                  // balance row: skipped
	. "2026/10/04,Saldo:,,10.00\n";                                    // whole-cell Saldo: skipped
$p = WB_Payments::parse_statement( $csv, WB_Payments::default_mappings()['generic'] );
eq( 'two deposits kept', count( $p['lines'] ), 2 );
eq( 'first deposit amount', $p['lines'][0]['amount'] ?? null, 1150.0 );
eq( 'second deposit description', $p['lines'][1]['description'] ?? null, 'Saldo betaling' );
eq( 'three total/balance rows skipped', $p['skipped'], 3 );

/* ===================================================================== M5 */
section( 'M5: a fully credited invoice does not open the release gate' );
$terms = [ 'account_status' => 'open', 'payment_terms_days' => 30, 'credit_limit' => 10000 ];
$cash  = [ 'payment_terms_days' => 0 ] + $terms;
$cred  = [ 'status' => 'credited', 'total' => 100, 'amount_paid' => 0, 'amount_credited' => 100 ];
eq( 'terms customer, credited invoice → hold', WB_Orders::may_release_pure( $terms, $cred, 0, false, 100 ), [ false, 'credited' ] );
eq( 'cash customer, credited invoice → hold', WB_Orders::may_release_pure( $cash, $cred, 0, false, 100 ), [ false, 'credited' ] );
eq( 'paid still releases', WB_Orders::may_release_pure( $cash, [ 'status' => 'paid', 'total' => 100, 'amount_paid' => 100 ], 0, false, 100 ), [ true, 'paid' ] );
eq( 'credited has plain words', WB_Orders::gate_text( 'credited' ) !== 'Goods cannot leave yet.', true );

/* ===================================================================== M9 */
section( 'M9: money owed back after a credit note is visible' );
$inv = [ 'total' => 1000, 'amount_paid' => 1000, 'amount_credited' => 200 ];
eq( 'paid 1000, credited 200 → still "paid"', WB_Invoices::status_for( 1000, 1000, 200, '2026-11-01', '2026-10-05' ), 'paid' );
eq( 'outstanding is −200', WB_Invoices::outstanding( $inv ), -200.0 );
eq( 'owed back 200', WB_Invoices::owed_back( $inv ), 200.0 );
eq( 'nothing owed back on an unpaid invoice', WB_Invoices::owed_back( [ 'total' => 1000, 'amount_paid' => 0, 'amount_credited' => 0 ] ), 0.0 );
eq( 'nothing owed back when exactly settled', WB_Invoices::owed_back( [ 'total' => 1000, 'amount_paid' => 800, 'amount_credited' => 200 ] ), 0.0 );

/* ===================================================================== M10 */
section( 'M10: credit lines must be for invoiced products, and returns for delivered goods' );
$il = [ [ 'product_id' => 3, 'qty' => 5 ], [ 'product_id' => 4, 'qty' => 2 ] ];
eq( 'all of product 3 → fine', WB_Invoices::credit_lines_problem( [ [ 'product_id' => 3, 'qty' => 5 ] ], $il ), '' );
eq( 'amount-only line → fine (money total checked elsewhere)', WB_Invoices::credit_lines_problem( [ [ 'qty' => 1, 'unit_price' => 50 ] ], $il ), '' );
eq( 'product not on the invoice → refused', WB_Invoices::credit_lines_problem( [ [ 'product_id' => 9, 'qty' => 1 ] ], $il ), 'Product #9 is not on this invoice.' );
eq( 'more than invoiced → refused', WB_Invoices::credit_lines_problem( [ [ 'product_id' => 3, 'qty' => 6 ] ], $il ), 'Product #3: only 5 was invoiced.' );
eq( 'two lines of one product add up (3 + 3 > 5)', WB_Invoices::credit_lines_problem( [ [ 'product_id' => 3, 'qty' => 3 ], [ 'product_id' => 3, 'qty' => 3 ] ], $il ), 'Product #3: only 5 was invoiced.' );
eq( 'return: 2 delivered and not returned, 3 asked → refused', WB_Invoices::credit_lines_problem( [ [ 'product_id' => 3, 'qty' => 3 ] ], $il, [ 3 => 2 ] ), 'Product #3: only 2 was delivered and not yet returned, so no more can come back.' );
eq( 'return: within what was delivered → fine', WB_Invoices::credit_lines_problem( [ [ 'product_id' => 3, 'qty' => 2 ] ], $il, [ 3 => 2 ] ), '' );

/* ===================================================================== M14 */
section( 'M14: line-by-line credit notes can fully credit an invoice (VAT rounding)' );
// Invoice: 3 lines of 10.10 → subtotal 30.30, VAT 15% = 4.545 → 4.55, total 34.85.
$it = WB_Invoices::totals( [ [ 'line_total' => 10.10 ], [ 'line_total' => 10.10 ], [ 'line_total' => 10.10 ] ], 15 );
eq( 'invoice total 34.85', $it['total'], 34.85 );
// One line credited alone: 10.10 + VAT 1.515 → 1.52 = 11.62. Three of them = 34.86 > 34.85.
$ct = WB_Invoices::totals( [ [ 'line_total' => 10.10 ] ], 15 );
eq( 'one-line credit total 11.62', $ct['total'], 11.62 );
eq( 'fits as is when there is room', WB_Invoices::cap_credit( $ct, 11.62 ), $ct );
$last = WB_Invoices::cap_credit( $ct, 34.85 - 11.62 - 11.62, 0.02 );   // 11.61 left
eq( 'third credit capped to 11.61', $last['total'] ?? null, 11.61 );
eq( 'third credit VAT 1.51 (11.61 − 10.10)', $last['vat'] ?? null, 1.51 );
eq( 'third credit subtotal unchanged 10.10', $last['subtotal'] ?? null, 10.10 );
eq( '11.62 against 11.50 left → refused', WB_Invoices::cap_credit( $ct, 11.50, 0.02 ), null );
eq( '2c over with a 1c tolerance → refused', WB_Invoices::cap_credit( $ct, 11.60, 0.01 ), null );
eq( 'nothing left → refused', WB_Invoices::cap_credit( $ct, 0.0, 0.02 ), null );

/* ===================================================================== M17 */
section( 'M17: past due counts the day after the due date, before the nightly sweep' );
$i = [ 'status' => 'issued', 'due_at' => '2026-10-01', 'total' => 100, 'amount_paid' => 0, 'amount_credited' => 0 ];
eq( 'issued, due yesterday → past due', WB_Invoices::is_past_due( $i, '2026-10-02' ), true );
eq( 'due today → not yet', WB_Invoices::is_past_due( $i, '2026-10-01' ), false );
eq( 'part paid, due date with time, past → past due', WB_Invoices::is_past_due( [ 'status' => 'part_paid', 'due_at' => '2026-10-01 00:00:00', 'amount_paid' => 40 ] + $i, '2026-10-05' ), true );
eq( 'paid → never past due', WB_Invoices::is_past_due( [ 'status' => 'paid', 'amount_paid' => 100 ] + $i, '2026-12-01' ), false );
eq( 'marked overdue → past due', WB_Invoices::is_past_due( [ 'status' => 'overdue' ] + $i, '2026-09-01' ), true );
eq( 'no due date → not past due', WB_Invoices::is_past_due( [ 'due_at' => '' ] + $i, '2026-12-01' ), false );

/* ===================================================================== M18 */
section( 'M18: a bare year and an amount are not an invoice number' );
eq( '"INV 2026 500.00" → none', WB_Payments::find_invoice_numbers( 'INV 2026 500.00', 'INV' ), [] );
eq( '"INV 2026 1,500.00" → none', WB_Payments::find_invoice_numbers( 'INV 2026 1,500.00', 'INV' ), [] );
eq( '"INV 2026 500,00" → none', WB_Payments::find_invoice_numbers( 'INV 2026 500,00', 'INV' ), [] );
eq( 'number then comma and words still found', WB_Payments::find_invoice_numbers( 'Paid INV-2026-123, thanks', 'INV' ), [ 'INV-2026-000123' ] );
eq( 'number at the end of a sentence still found', WB_Payments::find_invoice_numbers( 'Ref INV-2026-000123.', 'INV' ), [ 'INV-2026-000123' ] );

/* ===================================================================== M19 */
section( 'M19: the Unicode minus sign (U+2212) makes an amount negative' );
eq( '−500,00', WB_Payments::parse_money( "\xE2\x88\x92" . '500,00' ), -500.0 );
eq( 'R −1 234,56', WB_Payments::parse_money( "R \xE2\x88\x92" . '1 234,56' ), -1234.56 );
eq( '1 234,56− (trailing)', WB_Payments::parse_money( "1 234,56\xE2\x88\x92" ), -1234.56 );
eq( 'plain 500.00 unchanged', WB_Payments::parse_money( '500.00' ), 500.0 );

/* ===================================================================== M13 */
section( 'M13: small part payments and another customer\'s invoice are only suggested' );
$open = [ [ '_ID' => 5, 'invoice_number' => 'INV-2026-000005', 'outstanding' => 1150.00, 'customer_id' => 9 ] ];
$m = WB_Payments::match_row( [ 'amount' => 100, 'reference' => 'INV-2026-000005', 'description' => '' ], $open, 'INV' );
eq( '100 of 1150 → suggested, not matched', [ $m['match_status'], $m['invoice_id'], $m['suggested_invoice_id'], $m['note'] ], [ 'suggested', 0, 5, 'small_part_payment' ] );
$m = WB_Payments::match_row( [ 'amount' => 575, 'reference' => 'INV-2026-000005', 'description' => '' ], $open, 'INV' );
eq( 'exactly half (575) → partial', [ $m['match_status'], $m['invoice_id'], $m['allocate'] ], [ 'partial', 5, 575.0 ] );
$m = WB_Payments::match_row( [ 'amount' => 1150, 'reference' => 'INV-2026-000005', 'description' => '', 'customer_id' => 4 ], $open, 'INV' );
eq( 'known payer ≠ invoice customer → suggested', [ $m['match_status'], $m['invoice_id'], $m['note'] ], [ 'suggested', 0, 'other_customer' ] );
$m = WB_Payments::match_row( [ 'amount' => 1150, 'reference' => 'INV-2026-000005', 'description' => '', 'customer_id' => 9 ], $open, 'INV' );
eq( 'known payer = invoice customer → matched', $m['match_status'], 'matched' );

/* ===================================================================== M8 */
section( 'M8: an EFT already recorded at the counter is spotted in the bank file' );
$rc = [ [ '_ID' => 31, 'amount' => 500, 'bank_reference' => 'SLIP-0042' ], [ '_ID' => 32, 'amount' => 75, 'bank_reference' => 'AB' ] ];
eq( 'same amount, slip number in the reference → 31', WB_Payments::counter_duplicate( [ 'amount' => 500, 'reference' => 'slip 0042 acme', 'description' => '' ], $rc ), 31 );
eq( 'slip number in the description → 31', WB_Payments::counter_duplicate( [ 'amount' => 500.00, 'reference' => '', 'description' => 'EFT SLIP0042' ], $rc ), 31 );
eq( 'different amount → 0', WB_Payments::counter_duplicate( [ 'amount' => 499, 'reference' => 'SLIP-0042', 'description' => '' ], $rc ), 0 );
eq( 'too short a reference to trust → 0', WB_Payments::counter_duplicate( [ 'amount' => 75, 'reference' => 'AB', 'description' => '' ], $rc ), 0 );

/* ===================================================================== M15 */
section( 'M15: a price above the list price (the ceiling) needs approval' );
$product = [ '_ID' => 10, 'cost_price' => 100, 'list_price' => 200, 'price_valid_from' => '', 'price_valid_to' => '' ];
$two = WB_Pricing::check_two( $product, 210.0, '2026-10-05', 25.0 );
eq( '210 > list 200 → above ceiling', $two['above_ceiling'], true );
eq( '… and needs approval', $two['needs_approval'], true );
eq( '200 (= list) needs none', WB_Pricing::check_two( $product, 200.0, '2026-10-05', 25.0 )['needs_approval'], false );
$line = [ 'below_floor' => 'no', 'out_of_date' => 'no', 'cost_price' => 100, 'list_price' => 200, 'unit_price' => 210 ];
eq( 'frozen line above list → flagged', WB_Pricing::line_flagged( $line ), true );
eq( 'frozen line at list → not flagged', WB_Pricing::line_flagged( [ 'unit_price' => 200 ] + $line ), false );
eq( 'no list price on file → no ceiling', WB_Pricing::line_flagged( [ 'list_price' => 0 ] + $line ), false );
eq( 'below floor still flagged', WB_Pricing::line_flagged( [ 'below_floor' => 'yes', 'unit_price' => 120 ] + $line ), true );

/* ===================================================================== M23 */
section( 'M23: write-offs grouped by product, as sizes (no netting)' );
$rows = [
	[ 'product' => 'A', 'qty' => -2, 'value' => -10 ],
	[ 'product' => 'B', 'qty' => -1, 'value' => -50 ],
	[ 'product' => 'A', 'qty' => -3, 'value' => -15 ],
];
eq( 'B (50) first, then A (2 write-offs, 5 units, 25)', WB_Integrity::by_product( $rows ), [
	[ 'product' => 'B', 'times' => 1, 'qty' => 1.0, 'value' => 50.0 ],
	[ 'product' => 'A', 'times' => 2, 'qty' => 5.0, 'value' => 25.0 ],
] );
// A +4 and a −4 count line on one product: signed they net to 0; as sizes they are 8 units.
eq( 'count +4 and −4 → 8 units, not 0', WB_Integrity::by_product( [ [ 'product' => 'C', 'qty' => 4, 'value' => 20 ], [ 'product' => 'C', 'qty' => -4, 'value' => -20 ] ] )[0]['qty'], 8.0 );
eq( 'nothing → empty', WB_Integrity::by_product( [] ), [] );

/* ===================================================================== 1.2.0: why a price broke a rule, in words */
section( '1.2.0: a broken price rule is explained in a sentence' );
$l = [ 'unit_price' => 85, 'floor_price' => 93.6, 'cost_price' => 72, 'list_price' => 129, 'below_floor' => 'yes', 'out_of_date' => 'no' ];
eq( 'below the floor', WB_Pricing::explain( $l ), 'Below the lowest allowed price: R 85.00 is under R 93.60 (cost R 72.00 + 30% margin).' );
eq( 'out of date', WB_Pricing::explain( [ 'below_floor' => 'no', 'out_of_date' => 'yes' ] + $l ), "The product's price is out of date: today is outside its valid-from and valid-to dates." );
eq( 'both at once, both said', substr_count( WB_Pricing::explain( [ 'out_of_date' => 'yes' ] + $l ), '. ' ), 1 );
eq( 'above the list price', WB_Pricing::explain( [ 'unit_price' => 140, 'below_floor' => 'no' ] + $l ), 'Above the list price: R 140.00 is more than the list price of R 129.00.' );
eq( 'no cost on file', WB_Pricing::explain( [ 'cost_price' => 0, 'below_floor' => 'yes' ] + $l ), 'The product has no cost price on file, so the lowest allowed price cannot be worked out.' );
eq( 'nothing wrong: nothing to say', WB_Pricing::explain( [ 'unit_price' => 100, 'below_floor' => 'no' ] + $l ), '' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
