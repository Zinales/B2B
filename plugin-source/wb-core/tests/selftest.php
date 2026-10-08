<?php
/**
 * wb-core self-test — the pure engine maths, without WordPress.
 *
 *   php tests/selftest.php
 *
 * Covers: the two pricing checks, demand prediction + seasonality + journey stage, the 13-week
 * cashflow, leave (working days, SA public holidays, BCEA balances), timesheet hours, KPI rates,
 * the ledger hash chain, invoice totals/status, the release gate, the order state machine,
 * stock movement rules, bank-statement parsing and payment matching, document numbering.
 * 0.2.0 adds: the bank CSV reader (delimiters, BOM, quotes, header row, debit/credit or signed
 * amounts, decimal styles, duplicates), South African payroll for the 2027 tax year (PAYE, rebates
 * by age, medical credits, UIF, SDL, retirement limits, bonus by the difference method, overtime,
 * unpaid leave, EMP201) and the Setup screen's colour checks (WCAG contrast).
 * Every expected value below was worked out by hand (see the comments), not copied from output.
 *
 * Stubbed: ABSPATH (so the class files load), and mb_substr when the CLI lacks mbstring
 * (WordPress provides its own polyfill on a live site). No other WordPress function is called by
 * the code under test.
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $len = null ) { return null === $len ? substr( (string) $s, $start ) : substr( (string) $s, $start, $len ); }
}
date_default_timezone_set( 'UTC' );

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'ledger', 'sequences', 'pricing', 'stock', 'invoices', 'orders', 'payments', 'demand', 'staff', 'payroll', 'setup' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

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

/* ===================================================================== pricing */
section( 'Pricing: check one (customer price) and check two (product floor)' );
$product = [ '_ID' => 10, 'cost_price' => 100, 'list_price' => 200, 'min_margin_pct' => '', 'category_id' => 3, 'price_valid_from' => '2026-01-01', 'price_valid_to' => '2026-12-31' ];
$cats    = [ [ '_ID' => 3, 'min_margin_pct' => '' ], [ '_ID' => 1, 'min_margin_pct' => 25 ] ];   // nearest first; parent sets 25%
$rules   = [
	[ '_ID' => 1, 'customer_id' => 7, 'product_id' => 10, 'category_id' => 0, 'rule_type' => 'fixed_price', 'value' => 150, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30', 'status' => 'approved' ],
	[ '_ID' => 2, 'customer_id' => 7, 'product_id' => 0, 'category_id' => 1, 'rule_type' => 'pct_off_list', 'value' => 20, 'valid_from' => '', 'valid_to' => '', 'status' => 'approved' ],
	[ '_ID' => 3, 'customer_id' => 7, 'product_id' => 10, 'rule_type' => 'fixed_price', 'value' => 140, 'status' => 'draft' ],               // not approved: ignored
	[ '_ID' => 9, 'customer_id' => 8, 'product_id' => 10, 'rule_type' => 'fixed_price', 'value' => 90, 'status' => 'approved' ],             // another customer: ignored
];
$tier = [ 'discount_pct' => 10 ];

$e = WB_Pricing::evaluate( $product, 7, $rules, $tier, '2026-03-01', $cats, 20.0, null, 4 );
eq( 'product rule wins (price)', $e['unit_price'], 150.0 );
eq( 'product rule wins (source)', $e['price_source'], 'rule' );
eq( 'product rule id', $e['rule_id'], 1 );
eq( 'floor = 100 × 1.25 (margin from parent category)', $e['floor_price'], 125.0 );
eq( '150 is not below floor', $e['below_floor'], false );
eq( 'in date', $e['out_of_date'], false );
eq( 'no approval needed', $e['needs_approval'], false );
eq( 'margin on price (150−100)/150', $e['margin_pct'], 33.33 );
eq( 'line total 4 × 150', $e['line_total'], 600.0 );
eq( 'discount off list 25%', $e['discount_pct'], 25.0 );

$e = WB_Pricing::evaluate( $product, 7, $rules, $tier, '2026-08-01', $cats, 20.0 );
eq( 'product rule expired → category rule (200 × 0.8)', $e['unit_price'], 160.0 );
eq( 'category rule id', $e['rule_id'], 2 );

$e = WB_Pricing::evaluate( $product, 7, [], $tier, '2026-03-01', $cats, 20.0 );
eq( 'no rules → tier (200 × 0.9)', $e['unit_price'], 180.0 );
eq( 'tier source', $e['price_source'], 'tier' );

$e = WB_Pricing::evaluate( $product, 7, [], null, '2026-03-01', $cats, 20.0 );
eq( 'no tier → list', $e['unit_price'], 200.0 );
eq( 'list source', $e['price_source'], 'list' );

$e = WB_Pricing::evaluate( $product, 7, $rules, $tier, '2026-03-01', $cats, 20.0, 110.0 );
eq( 'typed price is manual', $e['price_source'], 'manual' );
eq( '110 is below the 125 floor', $e['below_floor'], true );
eq( 'below floor needs approval', $e['needs_approval'], true );

$e = WB_Pricing::evaluate( $product, 7, $rules, $tier, '2027-01-05', $cats, 20.0 );
eq( 'after price_valid_to → out of date', $e['out_of_date'], true );
eq( 'out of date needs approval', $e['needs_approval'], true );
eq( 'open-ended category rule still applies in 2027', $e['unit_price'], 160.0 );

eq( 'explicit 0% product margin beats category', WB_Pricing::margin_pct_for( [ 'min_margin_pct' => '0' ] + $product, $cats, 20.0 ), 0.0 );
eq( 'no margin anywhere → policy default', WB_Pricing::margin_pct_for( $product, [ [ '_ID' => 3, 'min_margin_pct' => '' ] ], 20.0 ), 20.0 );
$two = WB_Pricing::check_two( [ 'cost_price' => 0, 'list_price' => 50 ], 40.0, '2026-03-01', 20.0 );
eq( 'no cost on file → cannot check floor → approval (fail closed)', $two['needs_approval'], true );
eq( 'pct_on_cost 30% on 100', WB_Pricing::rule_price( [ 'rule_type' => 'pct_on_cost', 'value' => 30 ], 200, 100 ), 130.0 );
$two = WB_Pricing::check_two( [ 'cost_price' => 33.33, 'list_price' => 50 ], 38.33, '2026-03-01', 15.0 );
eq( 'floor rounds half-up to cents (33.33 × 1.15 = 38.3295)', $two['floor_price'], 38.33 );
eq( 'price equal to floor is not below it', $two['below_floor'], false );
eq( 'above list is flagged above ceiling', WB_Pricing::check_two( $product, 210.0, '2026-03-01', 25.0 )['above_ceiling'], true );
$two_rules = [
	[ '_ID' => 5, 'customer_id' => 7, 'product_id' => 10, 'rule_type' => 'fixed_price', 'value' => 150, 'valid_from' => '2026-01-01', 'status' => 'approved' ],
	[ '_ID' => 4, 'customer_id' => 7, 'product_id' => 10, 'rule_type' => 'fixed_price', 'value' => 145, 'valid_from' => '2026-02-01', 'status' => 'approved' ],
];
eq( 'two product rules: the most recently started wins', WB_Pricing::check_one( $product, 7, $two_rules, null, '2026-03-01', [ 3, 1 ] )['unit_price'], 145.0 );

/* ===================================================================== demand */
section( 'Demand: intervals, prediction, confidence, seasonality, journey stage' );
$p = WB_Demand::predict( [ [ '2026-01-01', 10 ], [ '2026-01-31', 12 ], [ '2026-03-02', 8 ], [ '2026-04-01', 10 ] ] );   // every 30 days
eq( 'steady: average interval', $p['avg_interval_days'], 30.0 );
eq( 'steady: predicted next = last + 30', $p['predicted_next_at'], '2026-05-01' );
eq( 'steady: average quantity', $p['avg_qty'], 10.0 );
eq( 'steady: confidence = min(1, 3/5) × (1 − 0)', $p['confidence'], 0.6 );
$p = WB_Demand::predict( [ [ '2026-02-10', 1 ], [ '2026-01-01', 1 ], [ '2026-01-11', 1 ] ] );   // unsorted; gaps 10 and 30
eq( 'irregular: mean of 10 and 30', $p['avg_interval_days'], 20.0 );
eq( 'irregular: predicted 2026-02-10 + 20', $p['predicted_next_at'], '2026-03-02' );
eq( 'irregular: confidence = (2/5) × (1 − 10/20)', $p['confidence'], 0.2 );
$p = WB_Demand::predict( [ [ '2026-01-01', 5 ], [ '2026-01-01', 5 ], [ '2026-02-01', 10 ] ] );
eq( 'same-day orders merge: 2 orders', $p['orders'], 2 );
eq( 'same-day orders merge: avg qty 10', $p['avg_qty'], 10.0 );
$p = WB_Demand::predict( [ [ '2026-01-01', 5 ] ] );
eq( 'one order: no prediction', $p['predicted_next_at'], null );
eq( 'one order: zero confidence', $p['confidence'], 0.0 );

$m = [];
foreach ( [ 2024, 2025 ] as $y ) for ( $i = 1; $i <= 12; $i++ ) $m[ sprintf( '%04d-%02d', $y, $i ) ] = 12 === $i ? 300 : 100;
$s = WB_Demand::seasonal_index( $m );   // overall monthly average = (11×100 + 300) / 12 = 116.667
eq( 'December index 300 / 116.667', $s[12], 2.571 );
eq( 'January index 100 / 116.667', $s[1], 0.857 );
$s = WB_Demand::seasonal_index( [ '2025-01' => 120, '2025-03' => 60 ] );   // Feb is a real zero: avg 60
eq( 'gap month counts as zero: Jan 120/60', $s[1], 2.0 );
eq( 'gap month counts as zero: Feb', $s[2], 0.0 );
eq( 'Mar 60/60', $s[3], 1.0 );
eq( 'month never in range is unknown', $s[4], null );

$rules_j = [ 'at_risk_days' => 60, 'lapsed_days' => 180, 'at_risk_interval_multiple' => 2 ];
eq( 'no quotes, no orders → lead', WB_Demand::journey_stage( [ 'quotes' => 0, 'orders' => 0 ], $rules_j ), 'lead' );
eq( 'quoted, no orders → quoted', WB_Demand::journey_stage( [ 'quotes' => 2, 'orders' => 0 ], $rules_j ), 'quoted' );
eq( 'one order → first_order', WB_Demand::journey_stage( [ 'orders' => 1, 'days_since_last_order' => 10 ], $rules_j ), 'first_order' );
eq( 'regular, on time → repeat', WB_Demand::journey_stage( [ 'orders' => 4, 'days_since_last_order' => 20, 'avg_interval_days' => 30 ], $rules_j ), 'repeat' );
eq( '60+ days → at_risk', WB_Demand::journey_stage( [ 'orders' => 4, 'days_since_last_order' => 61, 'avg_interval_days' => 30 ], $rules_j ), 'at_risk' );
eq( '180+ days → lapsed', WB_Demand::journey_stage( [ 'orders' => 4, 'days_since_last_order' => 200 ], $rules_j ), 'lapsed' );
eq( 'twice their own gap late → at_risk early', WB_Demand::journey_stage( [ 'orders' => 5, 'days_since_last_order' => 50, 'avg_interval_days' => 20 ], $rules_j ), 'at_risk' );
eq( 'days paid late: 10 and early(0) → 5', WB_Demand::avg_days_late( [ [ '2026-01-31', '2026-02-10' ], [ '2026-02-28', '2026-02-20' ] ] ), 5.0 );

/* ===================================================================== cashflow */
section( 'Cashflow: 13-week forecast slotting' );
$cf = WB_Demand::cashflow_forecast( [
	'receivables'    => [ [ '2026-10-01', 1000, 1 ], [ '2026-10-10', 500, 2 ], [ '2026-10-20', 2000, 1 ], [ '2027-03-01', 999, 2 ] ],
	'pay_delay'      => [ 1 => 3 ],
	'predicted'      => [ [ '2026-10-13', 300 ] ],
	'purchases'      => [ [ '2026-10-12', 800 ] ],
	'payroll_weekly' => 100,
	'opening'        => 50,
], '2026-10-05', 3 );
eq( 'three weeks', count( $cf ), 3 );
eq( 'week 2 starts 2026-10-12', $cf[1]['week_start'], '2026-10-12' );
eq( 'week 1 receipts: overdue 1000 (+3d still overdue) + 500', $cf[0]['expected_receipts'], 1500.0 );
eq( 'week 1 net 1500 − 100 wages', $cf[0]['net'], 1400.0 );
eq( 'week 1 running total 50 + 1400', $cf[0]['cumulative'], 1450.0 );
eq( 'week 2 predicted orders', $cf[1]['predicted_orders'], 300.0 );
eq( 'week 2 net 300 − 800 − 100', $cf[1]['net'], -600.0 );
eq( 'week 3 receipt due 10-20 + 3 days late', $cf[2]['expected_receipts'], 2000.0 );
eq( 'week 3 running total', $cf[2]['cumulative'], 2750.0 );

/* ===================================================================== staff */
section( 'Staff: hours, SA public holidays, working days, BCEA leave, KPIs' );
eq( '08:00–17:00 less 60 min', WB_Staff::hours( '08:00', '17:00', 60 ), 8.0 );
eq( 'overnight 22:00–06:00 less 30', WB_Staff::hours( '22:00', '06:00', 30 ), 7.5 );
eq( '08:30–12:45', WB_Staff::hours( '08:30', '12:45' ), 4.25 );
eq( 'nonsense time → 0', WB_Staff::hours( '25:00', '12:00' ), 0.0 );
eq( 'Easter 2026', WB_Staff::easter_sunday( 2026 ), '2026-04-05' );
eq( 'Easter 2024', WB_Staff::easter_sunday( 2024 ), '2024-03-31' );
eq( 'Easter 2027', WB_Staff::easter_sunday( 2027 ), '2027-03-28' );
$h26 = WB_Staff::sa_public_holidays( 2026 );
eq( '2026: Good Friday 3 April', in_array( '2026-04-03', $h26, true ), true );
eq( '2026: Family Day 6 April', in_array( '2026-04-06', $h26, true ), true );
eq( '2026: Women\'s Day is a Sunday → Monday 10 Aug off', in_array( '2026-08-10', $h26, true ), true );
eq( '2026: 13 public holidays', count( $h26 ), 13 );
eq( 'working days 1–10 Apr 2026 (Easter weekend)', WB_Staff::working_days( '2026-04-01', '2026-04-10' ), 6 );
eq( 'working days 21–31 Dec 2026 (Christmas Fri, Goodwill Sat)', WB_Staff::working_days( '2026-12-21', '2026-12-31' ), 8 );
eq( '6-day week counts Saturday', WB_Staff::working_days( '2026-04-01', '2026-04-10', null, 6 ), 7 );
eq( 'to before from → 0', WB_Staff::working_days( '2026-04-10', '2026-04-01' ), 0 );

$annual = [ 'code' => 'annual', 'days_per_year' => 15, 'cycle_months' => 12, 'accrual' => 'monthly', 'carry_over_max' => 5 ];
$b = WB_Staff::leave_balance( $annual, '2025-01-15', '2026-04-20', [ [ '2025-06-02', 8 ], [ '2026-02-10', 2 ] ] );
eq( 'annual: cycle started 15 Jan 2026', $b['cycle_start'], '2026-01-15' );
eq( 'annual: 3 months into the cycle × 1.25', $b['accrued'], 3.75 );
eq( 'annual: carried 15 − 8 = 7, capped at 5', $b['carried'], 5.0 );
eq( 'annual: taken this cycle', $b['taken'], 2.0 );
eq( 'annual: balance 3.75 + 5 − 2', $b['balance'], 6.75 );
$sick = [ 'code' => 'sick', 'days_per_year' => 30, 'cycle_months' => 36, 'accrual' => 'upfront', 'carry_over_max' => 0 ];
eq( 'sick, first 6 months: 62 working days ÷ 26 = 2', WB_Staff::leave_balance( $sick, '2026-01-05', '2026-03-31', [] )['accrued'], 2.0 );
$b = WB_Staff::leave_balance( $sick, '2024-03-01', '2026-10-02', [ [ '2025-05-05', 3 ] ] );
eq( 'sick: 30 per 36-month cycle, 3 taken', $b['balance'], 27.0 );
eq( 'sick: cycle from start date', $b['cycle_start'], '2024-03-01' );
$fam = [ 'code' => 'family', 'days_per_year' => 3, 'cycle_months' => 12, 'accrual' => 'upfront', 'carry_over_max' => 0 ];
eq( 'family responsibility: none before 4 months', WB_Staff::leave_balance( $fam, '2026-08-01', '2026-10-02', [] )['balance'], 0.0 );
eq( 'family responsibility: 3 after 4 months', WB_Staff::leave_balance( $fam, '2026-01-01', '2026-10-02', [] )['balance'], 3.0 );
eq( 'unpaid leave has no limit', WB_Staff::leave_balance( [ 'code' => 'unpaid', 'days_per_year' => 0 ], '2026-01-01', '2026-10-02', [] )['balance'], null );

eq( 'win rate 3 of 8', WB_Staff::kpi_win_rate( 8, 3 ), 37.5 );
eq( 'on time 3 of 4', WB_Staff::kpi_on_time( [ [ '2026-10-05', '2026-10-04' ], [ '2026-10-05', '2026-10-06' ], [ '', '2026-10-01' ], [ '2026-10-10', '2026-10-10 09:00:00' ] ] ), 75.0 );
eq( 'stock accuracy 2 of 3 lines exact', WB_Staff::kpi_stock_accuracy( [ [ 10, 10 ], [ 5, 4 ], [ 3, 3 ] ] ), 66.7 );
eq( 'timesheet compliance 18 of 20', WB_Staff::kpi_timesheet_compliance( 20, 18 ), 90.0 );
eq( 'review: scheduled → self_review', WB_Staff::review_can_move( 'scheduled', 'self_review' ), true );
eq( 'review: cannot skip to signed', WB_Staff::review_can_move( 'self_review', 'signed' ), false );

/* ===================================================================== ledger */
section( 'Ledger: hash chain' );
$r1 = [ 'entry_id' => 1, 'action' => 'invoice_issued', 'record_type' => 'wb_invoices', 'record_id' => 5, 'before_json' => '', 'after_json' => '{"total":115}', 'actor_user_id' => 2, 'ip' => '', 'created_at' => '2026-10-02 08:00:00', 'prev_hash' => '' ];
$r1['entry_hash'] = WB_Ledger::hash_for( '', $r1 );
eq( 'hash formula pinned (independently computed)', $r1['entry_hash'], '6d22c567fe1569ead2602532c3348c5c8897be5941834d52cdc36e6d9a948729' );
$r2 = [ 'entry_id' => 2, 'action' => 'payment_allocated', 'record_type' => 'wb_invoices', 'record_id' => 5, 'before_json' => '', 'after_json' => '{"amount":115}', 'actor_user_id' => 3, 'ip' => '10.0.0.1', 'created_at' => '2026-10-02 09:00:00', 'prev_hash' => $r1['entry_hash'] ];
$r2['entry_hash'] = WB_Ledger::hash_for( $r1['entry_hash'], $r2 );
$r3 = [ 'entry_id' => 3, 'action' => 'stock_write_off', 'record_type' => 'wb_stock_movements', 'record_id' => 9, 'before_json' => '', 'after_json' => '{"qty":-4}', 'actor_user_id' => 4, 'ip' => '', 'created_at' => '2026-10-02 10:00:00', 'prev_hash' => $r2['entry_hash'] ];
$r3['entry_hash'] = WB_Ledger::hash_for( $r2['entry_hash'], $r3 );
eq( 'intact chain verifies', WB_Ledger::verify_rows( [ $r1, $r2, $r3 ] ), true );
eq( 'a run from the middle verifies with its predecessor hash', WB_Ledger::verify_rows( [ $r2, $r3 ], $r1['entry_hash'] ), true );
$t = $r2; $t['after_json'] = '{"amount":15}';
$res = WB_Ledger::verify_rows( [ $r1, $t, $r3 ] );
eq( 'edited row is found', $res['entry_id'] ?? null, 2 );
eq( 'edited row: reason hash', $res['broken'] ?? null, 'hash' );
$res = WB_Ledger::verify_rows( [ $r1, $r3 ] );
eq( 'removed row: the next one is found', $res['entry_id'] ?? null, 3 );
eq( 'removed row: reason prev_link', $res['broken'] ?? null, 'prev_link' );
$t = $r3; $t['actor_user_id'] = 2;
eq( 'changing who did it breaks the hash', ( WB_Ledger::verify_rows( [ $r1, $r2, $t ] )['broken'] ?? null ), 'hash' );
eq( 'stable JSON key order', WB_Ledger::encode( [ 'b' => 1, 'a' => 2 ] ), '{"a":2,"b":1}' );

/* ===================================================================== invoices + gate + state machine */
section( 'Invoices, the release gate, the order state machine' );
$tot = WB_Invoices::totals( [ [ 'line_total' => 100 ], [ 'line_total' => 33.33 ], [ 'line_total' => 0.10 ] ], 15 );
eq( 'subtotal', $tot['subtotal'], 133.43 );
eq( 'VAT 15% of 133.43 = 20.0145 → 20.01', $tot['vat'], 20.01 );
eq( 'total', $tot['total'], 153.44 );
eq( 'due 30 days after issue', WB_Invoices::due_date( '2026-10-02 10:00:00', 30 ), '2026-11-01' );
eq( 'unpaid, in terms → issued', WB_Invoices::status_for( 153.44, 0, 0, '2026-11-01', '2026-10-02' ), 'issued' );
eq( 'part paid', WB_Invoices::status_for( 153.44, 50, 0, '2026-11-01', '2026-10-02' ), 'part_paid' );
eq( 'paid', WB_Invoices::status_for( 153.44, 153.44, 0, '2026-11-01', '2026-10-02' ), 'paid' );
eq( 'part paid and past due → overdue', WB_Invoices::status_for( 153.44, 50, 0, '2026-11-01', '2026-11-02' ), 'overdue' );
eq( 'fully credited', WB_Invoices::status_for( 153.44, 0, 153.44, '2026-11-01', '2026-12-01' ), 'credited' );
eq( 'credit + payment settle it → paid', WB_Invoices::status_for( 153.44, 100, 53.44, '2026-11-01', '2026-12-01' ), 'paid' );

$terms = [ 'account_status' => 'open', 'payment_terms_days' => 30, 'credit_limit' => 10000 ];
eq( 'terms, within limit → release', WB_Orders::may_release_pure( $terms, null, 2000, false, 5000 ), [ true, 'within_terms' ] );
eq( 'terms, over limit → hold', WB_Orders::may_release_pure( $terms, null, 2000, false, 9000 ), [ false, 'over_limit' ] );
eq( 'terms, overdue elsewhere → hold', WB_Orders::may_release_pure( $terms, null, 0, true, 100 ), [ false, 'overdue' ] );
eq( 'cash customer unpaid → hold', WB_Orders::may_release_pure( [ 'payment_terms_days' => 0 ] + $terms, [ 'status' => 'issued', 'total' => 100, 'amount_paid' => 0 ], 0, false, 100 ), [ false, 'cash_unpaid' ] );
eq( 'cash customer paid → release', WB_Orders::may_release_pure( [ 'payment_terms_days' => 0 ] + $terms, [ 'status' => 'paid', 'total' => 100, 'amount_paid' => 100 ], 0, false, 100 ), [ true, 'paid' ] );
eq( 'terms without a credit limit → hold (fail closed)', WB_Orders::may_release_pure( [ 'credit_limit' => 0 ] + $terms, null, 0, false, 1 ), [ false, 'no_credit_limit' ] );
eq( 'account on hold → hold, even if paid', WB_Orders::may_release_pure( [ 'account_status' => 'on_hold' ] + $terms, [ 'status' => 'paid' ], 0, false, 1 ), [ false, 'account_on_hold' ] );
eq( 'accepted → invoiced', WB_Orders::can_transition( 'accepted', 'invoiced' ), true );
eq( 'ready → part_delivered', WB_Orders::can_transition( 'ready', 'part_delivered' ), true );
eq( 'delivered → cancelled is not allowed', WB_Orders::can_transition( 'delivered', 'cancelled' ), false );
eq( 'closed is final', WB_Orders::can_transition( 'closed', 'ready' ), false );

/* ===================================================================== stock */
section( 'Stock: signs, two-person rule, reorder, count variance' );
eq( 'sale is always out', WB_Stock::normalise_qty( 'sale', 5 ), -5.0 );
eq( 'receipt is always in', WB_Stock::normalise_qty( 'receipt', -3 ), 3.0 );
eq( 'adjustment keeps its sign', WB_Stock::normalise_qty( 'adjustment', -2 ), -2.0 );
eq( 'unknown type → 0', WB_Stock::normalise_qty( 'teleport', 2 ), 0.0 );
$adj = [ 'type' => 'adjustment', 'qty' => -2, 'staff_id' => 3, 'approved_by_staff_id' => 4, 'reason' => 'breakage' ];
eq( 'adjustment: asker 3, approver 4 recording it → ok', WB_Stock::validate_movement( $adj, 4 ), true );
eq( 'adjustment: approve your own → refused', WB_Stock::validate_movement( [ 'approved_by_staff_id' => 3 ] + $adj, 3 ), 'self_approval' );
eq( 'adjustment: someone else records the approval → refused', WB_Stock::validate_movement( $adj, 5 ), 'approver_not_actor' );
eq( 'write-off without an approver → refused', WB_Stock::validate_movement( [ 'type' => 'write_off', 'approved_by_staff_id' => 0 ] + $adj, 4 ), 'no_approver' );
eq( 'write-off without a reason → refused', WB_Stock::validate_movement( [ 'type' => 'write_off', 'reason' => ' ' ] + $adj, 4 ), 'no_reason' );
eq( 'receipt needs no second person', WB_Stock::validate_movement( [ 'type' => 'receipt', 'qty' => 10 ], 3 ), true );
$s = WB_Stock::reorder_suggestion( 30, 15, 0, 20, 50, 1 );
eq( 'available 15 ≤ point 20 → raise', $s['raise'], true );
eq( 'suggest the reorder quantity', $s['suggested_qty'], 50.0 );
eq( 'above the point → no alert', WB_Stock::reorder_suggestion( 30, 0, 0, 20, 0, 24 )['raise'], false );
eq( 'no reorder qty: shortfall 16 rounded up to a pack of 24', WB_Stock::reorder_suggestion( 5, 0, 0, 20, 0, 24 )['suggested_qty'], 24.0 );
eq( 'stock on order covers it → no alert', WB_Stock::reorder_suggestion( 5, 0, 50, 20, 50 )['raise'], false );
$v = WB_Stock::count_variance( [ [ 'system' => 10, 'counted' => 8, 'unit_cost' => 5 ], [ 'system' => 4, 'counted' => 4, 'unit_cost' => 9 ], [ 'system' => 0, 'counted' => 1, 'unit_cost' => 2 ] ] );
eq( 'variance lines', $v['lines'], 2 );
eq( 'variance quantity', $v['variance_qty'], 3.0 );
eq( 'variance value −10 + 2', $v['variance_value'], -8.0 );

/* ===================================================================== payments */
section( 'Payments: parsing a statement and matching' );
eq( 'R 1 234,56', WB_Payments::parse_money( 'R 1 234,56' ), 1234.56 );
eq( '1,234.56', WB_Payments::parse_money( '1,234.56' ), 1234.56 );
eq( '(500.00) is negative', WB_Payments::parse_money( '(500.00)' ), -500.0 );
eq( '500.00 Dr is negative', WB_Payments::parse_money( '500.00 Dr' ), -500.0 );
eq( '1,234 thousands', WB_Payments::parse_money( '1,234' ), 1234.0 );
eq( 'Y/m/d', WB_Payments::parse_date( '2026/10/02' ), '2026-10-02' );
eq( 'd/m/Y (SA order)', WB_Payments::parse_date( '02/10/2026' ), '2026-10-02' );
eq( '13 Sep 2026', WB_Payments::parse_date( '13 Sep 2026' ), '2026-09-13' );
eq( 'impossible date → empty', WB_Payments::parse_date( '31/02/2026' ), '' );
eq( 'invoice number in a reference', WB_Payments::find_invoice_numbers( 'Payment INV-2026-000123 thanks', 'INV' ), [ 'INV-2026-000123' ] );
eq( 'typed loosely, two numbers', WB_Payments::find_invoice_numbers( 'inv 2026 123 and INV2026000124', 'INV' ), [ 'INV-2026-000123', 'INV-2026-000124' ] );

$csv  = "Account,62000000000\n\nDate,Description,Reference,Amount,Balance\n2026/10/01,EFT CREDIT,INV-2026-000005,1150.00,5000.00\n2026/10/02,DEBIT ORDER,,-200.00,4800.00\n,Closing balance,,,\n";
$rows = WB_Payments::parse_csv( $csv, WB_Payments::default_mappings()['fnb'] );
eq( 'FNB: header found under the account line; 2 transactions', is_array( $rows ) ? count( $rows ) : $rows, 2 );
eq( 'FNB: amount', $rows[0]['amount'] ?? null, 1150.0 );
eq( 'FNB: reference', $rows[0]['reference'] ?? null, 'INV-2026-000005' );
eq( 'FNB: debit is negative', $rows[1]['amount'] ?? null, -200.0 );
$rows = WB_Payments::parse_csv( "Transaction Date;Description;Reference;Debit;Credit\n01/10/2026;Deposit;ABC;;2 500,00\n", WB_Payments::default_mappings()['nedbank'] );
eq( 'Nedbank: semicolons, credit column, comma decimals', $rows[0]['amount'] ?? null, 2500.0 );
eq( 'Nedbank: date', $rows[0]['date'] ?? null, '2026-10-01' );
eq( 'no date/amount header → refused', WB_Payments::parse_csv( "a,b\n1,2\n", WB_Payments::default_mappings()['generic'] ), 'no_header' );

$open = [
	[ '_ID' => 5, 'invoice_number' => 'INV-2026-000005', 'outstanding' => 1150.00, 'customer_id' => 9 ],
	[ '_ID' => 6, 'invoice_number' => 'INV-2026-000006', 'outstanding' => 300.00, 'customer_id' => 9 ],
	[ '_ID' => 7, 'invoice_number' => 'INV-2026-000007', 'outstanding' => 300.00, 'customer_id' => 4 ],
	[ '_ID' => 8, 'invoice_number' => 'INV-2026-000008', 'outstanding' => 777.77, 'customer_id' => 4 ],
];
$m = WB_Payments::match_row( [ 'amount' => 1150, 'reference' => 'INV-2026-000005', 'description' => '' ], $open, 'INV' );
eq( 'exact reference + exact amount → matched', [ $m['match_status'], $m['match_method'], $m['invoice_id'] ], [ 'matched', 'auto_reference', 5 ] );
$m = WB_Payments::match_row( [ 'amount' => 1000, 'reference' => 'inv 2026-5', 'description' => '' ], $open, 'INV' );
eq( 'reference, less than owed → partial', [ $m['match_status'], $m['invoice_id'], $m['allocate'] ], [ 'partial', 5, 1000.0 ] );
$m = WB_Payments::match_row( [ 'amount' => 1200, 'reference' => 'INV-2026-000005', 'description' => '' ], $open, 'INV' );
eq( 'reference, more than owed → only suggested', [ $m['match_status'], $m['invoice_id'], $m['suggested_invoice_id'] ], [ 'suggested', 0, 5 ] );
$m = WB_Payments::match_row( [ 'amount' => 1450, 'reference' => 'INV-2026-000005 INV-2026-000006', 'description' => '' ], $open, 'INV' );
eq( 'two references → never guessed', [ $m['match_status'], $m['note'] ], [ 'unmatched', 'several_references' ] );
$m = WB_Payments::match_row( [ 'amount' => 777.77, 'reference' => 'ACME', 'description' => 'deposit' ], $open, 'INV' );
eq( 'one invoice of that amount → suggested, NOT matched', [ $m['match_status'], $m['match_method'], $m['invoice_id'], $m['suggested_invoice_id'] ], [ 'suggested', 'auto_amount', 0, 8 ] );
$m = WB_Payments::match_row( [ 'amount' => 300, 'reference' => '', 'description' => '' ], $open, 'INV' );
eq( 'two invoices of that amount → unmatched', [ $m['match_status'], $m['note'] ], [ 'unmatched', 'several_amount_candidates' ] );
$m = WB_Payments::match_row( [ 'amount' => 50, 'reference' => 'INV-2026-000099', 'description' => '' ], $open, 'INV' );
eq( 'reference to an invoice that is not open → unmatched', $m['match_status'], 'unmatched' );
eq( 'withdrawal is not a receipt', WB_Payments::match_row( [ 'amount' => -50, 'reference' => '', 'description' => '' ], $open, 'INV' )['note'], 'not_a_deposit' );

/* ===================================================================== 0.2.0: bank CSV with mapping */
section( 'Bank CSV (0.2.0): delimiters, BOM, quotes, header row, debit/credit, decimals, duplicates' );
eq( 'detect: semicolon beats the comma inside 1 234,56', WB_Payments::detect_delimiter( "Date;Amount\n01/10/2026;1 234,56\n02/10/2026;-500,00\n" ), ';' );
eq( 'detect: tab', WB_Payments::detect_delimiter( "Date\tAmount\n2026-10-01\t100.00\n" ), "\t" );
$r = WB_Payments::csv_rows( "a,\"b, with comma\",\"say \"\"hi\"\"\"\n\"two\nlines\",x\r\n", ',' );
eq( 'quoted field keeps its comma', $r[0][1] ?? null, 'b, with comma' );
eq( 'doubled quotes become one', $r[0][2] ?? null, 'say "hi"' );
eq( 'line break inside quotes stays in the field', $r[1][0] ?? null, "two\nlines" );
eq( 'R1,500.00 (dot style)', WB_Payments::parse_money( 'R1,500.00', 'dot' ), 1500.0 );
eq( '1.234,56 (comma style)', WB_Payments::parse_money( '1.234,56', 'comma' ), 1234.56 );
eq( 'R 2 500,00 auto', WB_Payments::parse_money( 'R 2 500,00' ), 2500.0 );
eq( 'R-100.00 is negative', WB_Payments::parse_money( 'R-100.00' ), -100.0 );
eq( 'trailing minus 75.00- is negative', WB_Payments::parse_money( '75.00-' ), -75.0 );
eq( '120.00 Cr stays positive', WB_Payments::parse_money( '120.00 Cr' ), 120.0 );
eq( 'decimal style detected: comma', WB_Payments::detect_decimal( [ '1 234,56', '-500,00', '' ] ), 'comma' );
eq( 'decimal style detected: dot', WB_Payments::detect_decimal( [ '1,234.56', '99.00' ] ), 'dot' );
eq( 'YYYY-MM-DD', WB_Payments::parse_date( '2026-10-01', 'ymd' ), '2026-10-01' );
eq( 'YYYY/MM/DD with a time', WB_Payments::parse_date( '2026/10/01 00:00:00' ), '2026-10-01' );
eq( 'DD MMM YYYY', WB_Payments::parse_date( '01 Oct 2026', 'dmony' ), '2026-10-01' );
eq( 'Afrikaans month (Okt)', WB_Payments::parse_date( '5 Okt 2026' ), '2026-10-05' );
eq( 'DD/MM/YYYY explicit', WB_Payments::parse_date( '05/10/2026', 'dmy' ), '2026-10-05' );
eq( 'MM/DD/YYYY only when chosen', WB_Payments::parse_date( '10/05/2026', 'mdy' ), '2026-10-05' );
eq( 'date format detected: day first', WB_Payments::detect_date_format( [ '01/10/2026', '15/10/2026' ] ), 'dmy' );
eq( 'date format detected: year first', WB_Payments::detect_date_format( [ '2026/10/01', '2026/10/15' ] ), 'ymd' );
eq( 'date format detected: month names', WB_Payments::detect_date_format( [ '01 Oct 2026' ] ), 'dmony' );

// Separate debit and credit columns, header on row 1.
$csv = "Date,Description,Reference,Debit,Credit,Balance\n2026/10/01,EFT IN ACME,INV-2026-000005,,1150.00,5000.00\n2026/10/02,BANK FEES,,25.00,,4975.00\n\n2026/10/03,Closing balance,,,,4975.00\n";
$p   = WB_Payments::parse_statement( $csv, [ 'header_row' => 1, 'columns' => [ 'date' => 0, 'description' => 1, 'reference' => 2, 'debit' => 3, 'credit' => 4 ], 'amount_mode' => 'split' ] );
eq( 'split: read ok', $p['ok'], true );
eq( 'split: 2 transactions', count( $p['lines'] ), 2 );
eq( 'split: money in', $p['lines'][0]['amount'] ?? null, 1150.0 );
eq( 'split: money out is negative', $p['lines'][1]['amount'] ?? null, -25.0 );
eq( 'split: 1 credit, 1 debit', [ $p['credits'], $p['debits'] ], [ 1, 1 ] );
eq( 'split: dated "Closing balance" row skipped, blank row not counted', $p['skipped'], 1 );

// Signed column, comma decimals, semicolons, UTF-8 BOM, header on row 4 (Afrikaans names).
$csv2 = "\xEF\xBB\xBFRekening;62000000000\nStaat;Oktober 2026\n\nDatum;Beskrywing;Verwysing;Bedrag\n01/10/2026;Deposit ACME;ABC123;1 234,56\n02/10/2026;Debit order;;-500,00\n;Closing balance;;4 000,00\n";
$map2 = [ 'header_row' => 4, 'columns' => [ 'date' => 0, 'description' => 1, 'reference' => 2, 'amount' => 3 ], 'names' => [ 'date' => 'Datum', 'amount' => 'Bedrag' ], 'amount_mode' => 'signed' ];
$p    = WB_Payments::parse_statement( $csv2, $map2 );
eq( 'BOM + semicolons: delimiter', $p['delimiter'], ';' );
eq( 'header on row 4', $p['header_row'], 4 );
eq( 'comma decimals detected', $p['decimal'], 'comma' );
eq( 'signed: 1 234,56 in', $p['lines'][0]['amount'] ?? null, 1234.56 );
eq( 'signed: -500,00 out', $p['lines'][1]['amount'] ?? null, -500.0 );
eq( 'signed: date day-first', $p['lines'][0]['date'] ?? null, '2026-10-01' );
eq( 'signed: closing balance without a date is skipped', $p['skipped'], 1 );
eq( 'BOM did not leak into the first cell', $p['ok'] && 'Datum' === $p['header'][0], true );
// The same saved layout on a filtered export where the header is on row 1: found by its names.
$p = WB_Payments::parse_statement( "Datum;Beskrywing;Verwysing;Bedrag\n03/10/2026;Deposit;X1;300,00\n", $map2 );
eq( 'saved layout finds its header by name when the preamble is gone', [ $p['header_row'], $p['lines'][0]['amount'] ?? null ], [ 1, 300.0 ] );
eq( 'a layout that does not fit is refused, never guessed', WB_Payments::parse_statement( "foo;bar\n1;2\n", $map2 )['error'], 'no_header' );

// Quoted fields with commas, "DD MMM YYYY", R prefix, found by guessing (no saved layout).
$p = WB_Payments::parse_statement( "\"Date\",\"Description\",\"Amount\"\n\"01 Oct 2026\",\"PAYMENT, ACME (PTY) LTD\",\"R1,500.00\"\n", [] );
eq( 'guessed layout, quoted description keeps its comma', $p['lines'][0]['description'] ?? null, 'PAYMENT, ACME (PTY) LTD' );
eq( 'guessed layout, R1,500.00', $p['lines'][0]['amount'] ?? null, 1500.0 );

// Duplicates.
$lines = WB_Payments::parse_statement( $csv, [ 'header_row' => 1, 'columns' => [ 'date' => 0, 'description' => 1, 'reference' => 2, 'debit' => 3, 'credit' => 4 ] ] )['lines'];
$first = WB_Payments::dedupe( $lines, [] );
eq( 'first import: every line is new', [ count( $first['new'] ), $first['duplicates'] ], [ 2, 0 ] );
$again = WB_Payments::dedupe( $lines, array_column( $first['new'], 1 ) );
eq( 're-importing the same file adds nothing', [ count( $again['new'] ), $again['duplicates'] ], [ 0, 2 ] );
$twins = [ [ 'date' => '2026-10-05', 'description' => 'Cash deposit', 'reference' => '', 'amount' => 100.0 ], [ 'date' => '2026-10-05', 'description' => 'Cash deposit', 'reference' => '', 'amount' => 100.0 ] ];
$h     = WB_Payments::line_hashes( $twins );
eq( 'two identical deposits in one file are two payments', $h[0] !== $h[1], true );
eq( '...and both are recognised on a re-import', WB_Payments::dedupe( $twins, $h )['duplicates'], 2 );
eq( 'hash ignores case and extra spaces', WB_Payments::line_hash( [ 'date' => '2026-10-01', 'amount' => 5, 'reference' => 'ABC  1', 'description' => 'x' ] ), WB_Payments::line_hash( [ 'date' => '2026-10-01', 'amount' => 5.0, 'reference' => 'abc 1', 'description' => 'X' ] ) );

/* ===================================================================== 0.2.0: payroll */
section( 'Payroll (0.2.0): 2027 tax year figures, PAYE, UIF, SDL, bonus, overtime, unpaid leave' );
$years = WB_Payroll::DEFAULT_TAX_YEARS;
$Y     = WB_Payroll::tax_year_for( '2026-10-25', $years );
eq( 'pay date 25 Oct 2026 → tax year 2027', $Y['year'] ?? null, '2027' );
eq( 'pay date 1 Mar 2027 → no tax year on file (refused)', WB_Payroll::tax_year_for( '2027-03-01', $years ), null );
eq( 'pay date 28 Feb 2026 → no tax year on file', WB_Payroll::tax_year_for( '2026-02-28', $years ), null );
// The seeded figures agree with themselves: each bracket's base = the previous base + rate × width,
// and each threshold = its rebates ÷ 18% (PAYROLL-RULES-2027.md re-added by hand).
$br = $Y['brackets'];
for ( $i = 1; $i < count( $br ); $i++ ) {
	eq( 'bracket base ' . $br[ $i ]['base'] . ' follows from the one below', $br[ $i - 1 ]['base'] + $br[ $i - 1 ]['rate'] / 100 * ( $br[ $i ]['above'] - $br[ $i - 1 ]['above'] ), (float) $br[ $i ]['base'] );
}
eq( 'threshold under 65 = 17 820 / 18%', 17820 / 0.18, (float) $Y['thresholds']['under_65'] );
eq( 'threshold 65+ = (17 820 + 9 765) / 18%', ( 17820 + 9765 ) / 0.18, (float) $Y['thresholds']['age_65'] );
eq( 'threshold 75+ = (17 820 + 9 765 + 3 249) / 18%', ( 17820 + 9765 + 3249 ) / 0.18, (float) $Y['thresholds']['age_75'] );
eq( 'brackets: 240 000 × 18%', WB_Payroll::bracket_tax( 240000, $br ), 43200.0 );
eq( 'brackets: 600 000 → 125 599 + 36% × 69 800', WB_Payroll::bracket_tax( 600000, $br ), 150727.0 );
eq( 'brackets: exactly 245 100 → 44 118', WB_Payroll::bracket_tax( 245100, $br ), 44118.0 );

eq( 'PAYE R20 000/month, age 40, no medical: 25 380 / 12', WB_Payroll::paye_monthly( 20000, 40, 0, $Y ), 2115.00 );
eq( 'PAYE R50 000/month, age 40, member + 1: 132 907 / 12 − 752', WB_Payroll::paye_monthly( 50000, 40, 2, $Y ), 10323.58 );
eq( 'PAYE R15 000/month, age 66: 32 400 − 17 820 − 9 765 = 4 815 / 12', WB_Payroll::paye_monthly( 15000, 66, 0, $Y ), 401.25 );
eq( 'PAYE below the tax threshold (R8 000/month) → 0', WB_Payroll::paye_monthly( 8000, 40, 0, $Y ), 0.0 );
eq( 'rebates at 75: 17 820 + 9 765 + 3 249', WB_Payroll::rebates( 75, $Y ), 30834.0 );
eq( 'medical credit: member only', WB_Payroll::medical_credit( 1, $Y ), 376.0 );
eq( 'medical credit: member + 1', WB_Payroll::medical_credit( 2, $Y ), 752.0 );
eq( 'medical credit: member + 3 = 752 + 2 × 254', WB_Payroll::medical_credit( 4, $Y ), 1260.0 );
eq( 'age on 28 Feb 2027, born 1 Mar 1960 → 66 (birthday not yet)', WB_Payroll::age_at( '1960-03-01', '2027-02-28' ), 66 );
eq( 'age on 28 Feb 2027, born 28 Feb 1962 → 65 (secondary rebate)', WB_Payroll::age_at( '1962-02-28', '2027-02-28' ), 65 );
eq( 'no date of birth → unknown', WB_Payroll::age_at( '', '2027-02-28' ), null );

eq( 'UIF at R30 000: capped at 1% of 17 712', WB_Payroll::uif( 30000, $Y ), [ 177.12, 177.12 ] );
eq( 'UIF at R10 000: 1% each', WB_Payroll::uif( 10000, $Y ), [ 100.0, 100.0 ] );
eq( 'UIF exempt', WB_Payroll::uif( 10000, $Y, true ), [ 0.0, 0.0 ] );
eq( 'SDL 1% when registered', WB_Payroll::sdl( 30000, $Y, true ), 300.0 );
eq( 'no SDL when not registered', WB_Payroll::sdl( 30000, $Y, false ), 0.0 );
eq( 'retirement: 27.5% limit', WB_Payroll::retirement_allowed( 50000, 100000, $Y ), 27500.0 );
eq( 'retirement: R430 000 a year cap (35 833.33 a month)', WB_Payroll::retirement_allowed( 40000, 200000, $Y ), 35833.33 );

// Bonus, difference method: R20 000/month + R20 000 bonus, age 40.
// tax(240 000 + 20 000) = 44 118 + 26% × 14 900 = 47 992 − 17 820 = 30 172; tax(240 000) = 25 380; difference 4 792.
eq( 'bonus PAYE by the difference method', WB_Payroll::paye_bonus( 20000, 20000, 40, $Y ), 4792.00 );
$c = WB_Payroll::calc_payslip( [ 'salary' => 20000, 'bonus' => 20000, 'age' => 40 ], $Y );
eq( 'payslip with bonus: gross', $c['gross'], 40000.0 );
eq( 'payslip with bonus: PAYE 2 115 + 4 792', $c['paye'], 6907.0 );
eq( 'payslip with bonus: UIF still capped', $c['uif_employee'], 177.12 );
eq( 'payslip with bonus: net 40 000 − 6 907 − 177.12', $c['net'], 32915.88 );

// Overtime: R100/hour; week of 5 Oct 50 h (45 ordinary + 5 overtime), week of 12 Oct 36 h.
$days = [];
foreach ( [ '05', '06', '07', '08', '09' ] as $d ) $days[] = [ "2026-10-$d", 10 ];
foreach ( [ '12', '13', '14', '15' ] as $d ) $days[] = [ "2026-10-$d", 9 ];
$hrs = WB_Payroll::split_hours( $days, 45 );
eq( 'overtime: over 45 hours in one week only', $hrs, [ 'ordinary' => 81.0, 'overtime' => 5.0 ] );
$c = WB_Payroll::calc_payslip( [ 'pay_type' => 'hourly', 'hourly_rate' => 100, 'ordinary_hours' => $hrs['ordinary'], 'overtime_hours' => $hrs['overtime'], 'overtime_multiplier' => 1.5, 'age' => 40 ], $Y );
eq( 'hourly gross 81 × 100 + 5 × 150', $c['gross'], 8850.0 );
eq( 'overtime rate 1.5 × 100', $c['overtime_rate'], 150.0 );
eq( 'hourly PAYE: 106 200 × 18% − 17 820 = 1 296 / 12', $c['paye'], 108.0 );
eq( 'hourly UIF 1% of 8 850', $c['uif_employee'], 88.5 );
eq( 'hourly net 8 850 − 108 − 88.50', $c['net'], 8653.5 );
eq( 'under 24 hours a month: no UIF', WB_Payroll::calc_payslip( [ 'pay_type' => 'hourly', 'hourly_rate' => 100, 'ordinary_hours' => 20, 'age' => 30 ], $Y )['uif_employee'], 0.0 );

// Unpaid leave: R22 000 salary, 2 unpaid days of the 22 working days in October 2026.
eq( 'October 2026 has 22 working days', WB_Staff::working_days( '2026-10-01', '2026-10-31' ), 22 );
eq( 'unpaid leave: 22 000 × 2 / 22', WB_Payroll::unpaid_deduction( 22000, 2, 22 ), 2000.0 );
$c = WB_Payroll::calc_payslip( [ 'salary' => 22000, 'unpaid_days' => 2, 'working_days' => 22, 'age' => 40 ], $Y );
eq( 'unpaid leave: gross 20 000', $c['gross'], 20000.0 );
eq( 'unpaid leave: PAYE on 20 000', $c['paye'], 2115.0 );
eq( 'unpaid leave: net 20 000 − 2 115 − 177.12', $c['net'], 17707.88 );

// Retirement, medical and SDL together: R30 000, 7.5% to the fund (2 250), member only, SDL registered.
// Taxable 27 750 × 12 = 333 000 → 44 118 + 26% × 87 900 = 66 972 − 17 820 = 49 152 / 12 = 4 096 − 376 = 3 720.
$c = WB_Payroll::calc_payslip( [ 'salary' => 30000, 'retirement_pct' => 7.5, 'medical_members' => 1, 'sdl_registered' => true, 'age' => 45 ], $Y );
eq( 'composite: taxable after the retirement contribution', $c['taxable'], 27750.0 );
eq( 'composite: PAYE', $c['paye'], 3720.0 );
eq( 'composite: SDL 1% of 30 000 (employer)', $c['sdl'], 300.0 );
eq( 'composite: net 30 000 − 3 720 − 177.12 − 2 250', $c['net'], 23852.88 );
eq( 'age 66 by date gives the secondary rebate in the payslip', WB_Payroll::calc_payslip( [ 'salary' => 15000, 'age' => WB_Payroll::age_at( '1960-03-01', $Y['to'] ) ], $Y )['paye'], 401.25 );
eq( 'no date of birth: warned, primary rebate only', count( WB_Payroll::calc_payslip( [ 'salary' => 15000 ], $Y )['warnings'] ), 1 );
eq( 'EMP201: PAYE + UIF (both halves) + SDL', WB_Payroll::emp201( [ [ 'paye' => 2115, 'uif_employee' => 177.12, 'uif_employer' => 177.12, 'sdl' => 0 ], [ 'paye' => 108, 'uif_employee' => 88.5, 'uif_employer' => 88.5, 'sdl' => 0 ] ] ), [ 'paye' => 2223.0, 'uif' => 531.24, 'sdl' => 0.0, 'total' => 2754.24 ] );
eq( 'bank file: a name cannot start a spreadsheet formula', strpos( WB_Payroll::bank_csv( [ [ '=HYPERLINK("x")', '62000000000', '250655', 100, 'Salary 2026-10' ] ] ), "\"'=HYPERLINK" ) !== false, true );

/* ===================================================================== 0.2.0: setup colours */
section( 'Setup (0.2.0): colour codes and contrast' );
eq( '#abc → #AABBCC', WB_Setup::valid_hex( '#abc' ), '#AABBCC' );
eq( 'not a colour → refused', WB_Setup::valid_hex( 'blue' ), '' );
eq( 'black on white is 21:1', WB_Setup::contrast( '#000000', '#FFFFFF' ), 21.0 );
eq( 'rose on white ≈ 7.4:1 (accepted)', round( WB_Setup::contrast( '#8A3B52', '#FFFFFF' ), 1 ), 7.4 );
eq( 'the shipped defaults pass every pair', WB_Setup::check_pairs( WB_Setup::DEFAULT_COLORS ), [] );
$bad = WB_Setup::check_pairs( [ 'text_muted' => '#E3A9B8' ] + WB_Setup::DEFAULT_COLORS );
eq( 'pale pink body text is refused, naming the pair', $bad[0][2] ?? null, 'body text on the page background' );
eq( '...with its ratio', ( $bad[0][3] ?? 99 ) < 4.5, true );
eq( 'rose on white accepted as button colour with white text', WB_Setup::check_pairs( [ 'primary' => '#8A3B52', 'on_primary' => '#FFFFFF' ] + WB_Setup::DEFAULT_COLORS ), [] );
$css = WB_Setup::css_vars( [ 'primary' => 'red;}</style><script>' ] + WB_Setup::DEFAULT_COLORS );
eq( 'a bad colour cannot break out of the style block', false === strpos( $css, '<' ) && false !== strpos( $css, '--kc-primary:#8A3B52' ), true );
eq( 'the --wb-* aliases are printed too', false !== strpos( WB_Setup::css_vars( WB_Setup::DEFAULT_COLORS ), '--wb-ink:#0B1F3A' ), true );

/* ===================================================================== numbering */
section( 'Numbering' );
eq( 'format', WB_Sequences::format( 'INV', 2026, 42 ), 'INV-2026-000042' );
eq( 'grows past six digits, never wraps', WB_Sequences::format( 'DN', 2026, 1234567 ), 'DN-2026-1234567' );
eq( 'prefix cleaned', WB_Sequences::clean_prefix( 'in-v', 'INV' ), 'INV' );
eq( 'empty prefix → type key', WB_Sequences::clean_prefix( '', 'DN' ), 'DN' );
eq( 'over-long prefix → type key', WB_Sequences::clean_prefix( 'TOOLONGPREFIX', 'PO' ), 'PO' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
