<?php
/**
 * wb-core payroll regression test — the 4 October 2026 review findings (P1, P2, P3, P9, P11, P12,
 * P13), pure maths only, without WordPress.
 *
 *   php tests/regress-payroll.php
 *
 * Every expected value below was worked out by hand (see the comments), not copied from output.
 * Stubbed: ABSPATH (so the class files load), and mb_substr when the CLI lacks mbstring.
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $len = null ) { return null === $len ? substr( (string) $s, $start ) : substr( (string) $s, $start, $len ); }
}
date_default_timezone_set( 'UTC' );

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'staff', 'payroll', 'setup' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

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

$Y = WB_Payroll::tax_year_for( '2026-10-25', WB_Payroll::DEFAULT_TAX_YEARS );

/* ===================================================================== P1 */
section( 'P1: editing a profile keeps what is on file when a field is missing or blank' );
$have = [ '_ID' => 4, 'staff_id' => 7, 'pay_type' => 'monthly', 'bank_branch_code' => '250655', 'date_of_birth' => '1980-05-01', 'medical_scheme_members' => '3',
	'retirement_contribution_pct' => '7.5', 'retirement_contribution_fixed' => '0', 'uif_exempt' => 'yes', 'start_date' => '2025-01-01', 'end_date' => '' ];
$blank = [ 'pay_type' => '', 'bank_branch_code' => '', 'date_of_birth' => '', 'medical_scheme_members' => '', 'retirement_contribution_pct' => '',
	'retirement_contribution_fixed' => '', 'uif_exempt' => '', 'start_date' => '', 'end_date' => '' ];
$m = WB_Payroll::merge_profile( $have, [ 'end_date' => '2026-12-31' ] + $blank );
eq( 'edit: date of birth kept', $m['date_of_birth'], '1980-05-01' );
eq( 'edit: medical scheme members kept', $m['medical_scheme_members'], '3' );
eq( 'edit: retirement % kept', $m['retirement_contribution_pct'], '7.5' );
eq( 'edit: UIF exempt kept', $m['uif_exempt'], 'yes' );
eq( 'edit: start date kept', $m['start_date'], '2025-01-01' );
eq( 'edit: branch code kept', $m['bank_branch_code'], '250655' );
eq( 'edit: the one field typed is changed', $m['end_date'], '2026-12-31' );
eq( 'edit: whitespace counts as blank', WB_Payroll::merge_profile( $have, [ 'date_of_birth' => '   ' ] )['date_of_birth'], '1980-05-01' );
eq( 'edit: a typed 0 is a value, not blank', WB_Payroll::merge_profile( $have, [ 'medical_scheme_members' => 0 ] )['medical_scheme_members'], 0 );
eq( 'edit: only the fields given are returned', array_keys( WB_Payroll::merge_profile( $have, [ 'uif_exempt' => 'no' ] ) ), [ 'uif_exempt' ] );
$n = WB_Payroll::merge_profile( null, [ 'start_date' => '2026-10-15' ] + $blank );
eq( 'new: blank medical → 0', $n['medical_scheme_members'], 0 );
eq( 'new: blank UIF exempt → no', $n['uif_exempt'], 'no' );
eq( 'new: blank pay type → monthly', $n['pay_type'], 'monthly' );
eq( 'new: start date as typed', $n['start_date'], '2026-10-15' );

/* ===================================================================== P2 */
section( 'P2: joiners and leavers are paid for the working days they were employed' );
// October 2026: 22 working days (no public holiday). 1–14 Oct: Thu 1, Fri 2, 5–9, 12–14 = 10.
eq( 'joiner on 15 Oct: 10 working days before', WB_Payroll::days_not_employed( '2026-10-01', '2026-10-31', '2026-10-15', '' ), 10 );
// 10–31 Oct: 12–16, 19–23, 26–30 = 15.
eq( 'leaver on 9 Oct: 15 working days after', WB_Payroll::days_not_employed( '2026-10-01', '2026-10-31', '2025-01-01', '2026-10-09' ), 15 );
// Before 5 Oct: Thu 1, Fri 2 = 2. After 23 Oct: 26–30 = 5.
eq( 'joined 5 Oct and left 23 Oct: 2 + 5', WB_Payroll::days_not_employed( '2026-10-01', '2026-10-31', '2026-10-05', '2026-10-23' ), 7 );
eq( 'employed all month: 0', WB_Payroll::days_not_employed( '2026-10-01', '2026-10-31', '2024-03-01', '' ), 0 );
// September 2026: Heritage Day Thu 24 Sep. Joiner on 21 Sep: 1–18 Sep = 14 working days (1–4, 7–11, 14–18).
eq( 'same working-day count as unpaid leave (holidays excluded)', WB_Payroll::days_not_employed( '2026-09-01', '2026-09-30', '2026-09-21', '' ), 14 );
// R22 000, 10 of 22 days not employed → 22 000 × 12 / 22 = 12 000. PAYE: 144 000 × 18% = 25 920 − 17 820 = 8 100 / 12 = 675.
$c = WB_Payroll::calc_payslip( [ 'salary' => 22000, 'working_days' => 22, 'days_not_employed' => 10, 'age' => 40 ], $Y );
eq( 'joiner: gross 12 000', $c['gross'], 12000.0 );
eq( 'joiner: line shows the 10 000 not earned', $c['lines'][1]['amount'] ?? null, -10000.0 );
eq( 'joiner: PAYE on 12 000', $c['paye'], 675.0 );
eq( 'joiner: net 12 000 − 675 − 120', $c['net'], 11205.0 );
// With 2 unpaid leave days too: 22 000 × (10 + 2) / 22 = 12 000 off → gross 10 000; unpaid line 2 000.
$c = WB_Payroll::calc_payslip( [ 'salary' => 22000, 'working_days' => 22, 'days_not_employed' => 10, 'unpaid_days' => 2, 'age' => 40 ], $Y );
eq( 'joiner + unpaid leave: gross 10 000', $c['gross'], 10000.0 );
eq( 'joiner + unpaid leave: unpaid line 2 000', $c['lines'][2]['amount'] ?? null, -2000.0 );
eq( 'never more off than the salary', WB_Payroll::calc_payslip( [ 'salary' => 22000, 'working_days' => 22, 'days_not_employed' => 20, 'unpaid_days' => 5, 'age' => 40 ], $Y )['gross'], 0.0 );

/* ===================================================================== P3 */
section( 'P3: a week that crosses the month end keeps its overtime' );
eq( 'Monday of the week of Thu 1 Oct 2026', WB_Payroll::week_monday( '2026-10-01' ), '2026-09-28' );
eq( 'a Monday is its own week start', WB_Payroll::week_monday( '2026-09-28' ), '2026-09-28' );
$days = [];
foreach ( [ '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02' ] as $d ) $days[] = [ $d, 12 ];
// Week total 60. Running: 12, 24, 36 (Sep) | Thu 1 Oct: 9 ordinary to reach 45, 3 overtime | Fri 2 Oct: 12 overtime.
eq( 'October pays 9 ordinary + 15 overtime', WB_Payroll::split_hours( $days, 45, '2026-10-01' ), [ 'ordinary' => 9.0, 'overtime' => 15.0 ] );
eq( 'September (28–30 Sep only) paid 36 ordinary', WB_Payroll::split_hours( array_slice( $days, 0, 3 ), 45, '2026-09-01' ), [ 'ordinary' => 36.0, 'overtime' => 0.0 ] );
eq( 'the two months together = 45 + 15 = 60 hours', 36 + 9 + 15, 60 );
eq( 'without a pay-from date the whole week counts', WB_Payroll::split_hours( $days, 45 ), [ 'ordinary' => 45.0, 'overtime' => 15.0 ] );
eq( 'day order does not matter', WB_Payroll::split_hours( array_reverse( $days ), 45, '2026-10-01' ), [ 'ordinary' => 9.0, 'overtime' => 15.0 ] );
$h = WB_Payroll::hourly_hours( $days, WB_Staff::public_holidays( 2026 ), '2026-10-01', '2026-10-31', 9, 5, 45 );
eq( 'hourly_hours: same split (no holiday in October)', [ $h['ordinary'], $h['overtime'], $h['holiday_hours'] ], [ 9.0, 15.0, 0.0 ] );
// R100/hour: 9 × 100 + 15 × 150 = 900 + 2 250 = 3 150 (the old code paid 24 × 100 = 2 400).
$c = WB_Payroll::calc_payslip( [ 'pay_type' => 'hourly', 'hourly_rate' => 100, 'ordinary_hours' => $h['ordinary'], 'overtime_hours' => $h['overtime'], 'age' => 40 ], $Y );
eq( 'October gross 900 + 2 250', $c['gross'], 3150.0 );

/* ===================================================================== P9 */
section( 'P9: hourly staff and public holidays (BCEA s18)' );
$h26 = WB_Staff::public_holidays( 2026 );
// September 2026: Heritage Day Thursday 24 Sep. Worked Mon 21–Wed 23 and Fri 25, 9 h each; 45 h a week ÷ 5 = 9 h a day.
$wk = [ [ '2026-09-21', 9 ], [ '2026-09-22', 9 ], [ '2026-09-23', 9 ], [ '2026-09-25', 9 ] ];
$h  = WB_Payroll::hourly_hours( $wk, $h26, '2026-09-01', '2026-09-30', 9, 5, 45, 2.0 );
eq( 'holiday not worked: 36 ordinary hours', $h['ordinary'], 36.0 );
eq( 'holiday not worked: 9 hours of holiday pay', $h['holiday_hours'], 9.0 );
eq( 'holiday not worked: no holiday work', $h['holiday_work_hours'], 0.0 );
$c = WB_Payroll::calc_payslip( [ 'pay_type' => 'hourly', 'hourly_rate' => 100, 'ordinary_hours' => 36, 'holiday_hours' => 9, 'age' => 40 ], $Y );
eq( 'gross 36 × 100 + 9 × 100', $c['gross'], 4500.0 );
eq( 'holiday pay line 900', $c['lines'][1]['amount'] ?? null, 900.0 );
// Worked 10 h on 24 Sep: 10 × 2 × 100 = 2 000 ≥ the day's wage + time worked (9 + 10 = 19 h = 1 900): no top-up.
$h = WB_Payroll::hourly_hours( array_merge( $wk, [ [ '2026-09-24', 10 ] ] ), $h26, '2026-09-01', '2026-09-30', 9, 5, 45, 2.0 );
eq( 'worked the holiday: 10 hours of holiday work', $h['holiday_work_hours'], 10.0 );
eq( 'worked the holiday: no holiday pay on top', $h['holiday_hours'], 0.0 );
eq( 'worked the holiday: it does not count toward overtime', [ $h['ordinary'], $h['overtime'] ], [ 36.0, 0.0 ] );
$c = WB_Payroll::calc_payslip( [ 'pay_type' => 'hourly', 'hourly_rate' => 100, 'ordinary_hours' => 36, 'holiday_work_hours' => 10, 'holiday_work_multiplier' => 2.0, 'age' => 40 ], $Y );
eq( 'holiday work rate 2 × 100', $c['holiday_work_rate'], 200.0 );
eq( 'gross 3 600 + 10 × 200', $c['gross'], 5600.0 );
// Worked only 8 h on the holiday: double = 16 h; a day's wage + time worked = 9 + 8 = 17 h → 1 h top-up (s18(2)(b)(ii)).
$h = WB_Payroll::hourly_hours( array_merge( $wk, [ [ '2026-09-24', 8 ] ] ), $h26, '2026-09-01', '2026-09-30', 9, 5, 45, 2.0 );
eq( 'short holiday shift: 8 h at double + 1 h top-up', [ $h['holiday_work_hours'], $h['holiday_hours'] ], [ 8.0, 1.0 ] );
$c = WB_Payroll::calc_payslip( [ 'pay_type' => 'hourly', 'hourly_rate' => 100, 'ordinary_hours' => 36, 'holiday_work_hours' => 8, 'holiday_hours' => 1, 'age' => 40 ], $Y );
eq( 'short holiday shift: gross 3 600 + 1 600 + 100', $c['gross'], 5300.0 );
// December 2026: Wed 16, Fri 25, Sat 26 (Day of Goodwill). 8 h a day, nothing worked.
eq( '5-day week: Saturday holiday is not an ordinary day → 2 × 8', WB_Payroll::hourly_hours( [], $h26, '2026-12-01', '2026-12-31', 8, 5 )['holiday_hours'], 16.0 );
eq( '6-day week: Saturday counts → 3 × 8', WB_Payroll::hourly_hours( [], $h26, '2026-12-01', '2026-12-31', 8, 6 )['holiday_hours'], 24.0 );
eq( 'joined 20 Dec: only Christmas Day', WB_Payroll::hourly_hours( [], $h26, '2026-12-01', '2026-12-31', 8, 5, 45, 2.0, '2026-12-20' )['holiday_hours'], 8.0 );
eq( 'unknown daily hours are flagged (the run refuses)', WB_Payroll::hourly_hours( [], $h26, '2026-12-01', '2026-12-31', 0, 5 )['needs_daily'], 2 );
eq( 'the holiday-work setting defaults to 2', WB_Payroll::DEFAULT_SETTINGS['holiday_work_multiplier'], 2.0 );

/* ===================================================================== P11 */
section( 'P11: the retirement limit includes the bonus' );
// R20 000 + R20 000 bonus, R6 000 fixed contribution, age 40.
// On regular pay alone: 27.5% × 20 000 = 5 500 allowed. With the bonus: 27.5% × 40 000 = 11 000 → all 6 000 allowed.
// Regular taxable 14 500 → 174 000 × 18% = 31 320 − 17 820 = 13 500 / 12 = 1 125.
// The extra 500 reduces the bonus: 19 500. tax(193 500) = 34 830 − 17 820 = 17 010; − 13 500 = 3 510.
$c = WB_Payroll::calc_payslip( [ 'salary' => 20000, 'retirement_fixed' => 6000, 'bonus' => 20000, 'age' => 40 ], $Y );
eq( 'all 6 000 allowed', $c['retirement_allowed'], 6000.0 );
eq( 'taxable 14 500 + 19 500', $c['taxable'], 34000.0 );
eq( 'PAYE on the bonus 3 510', $c['paye_bonus'], 3510.0 );
eq( 'PAYE 1 125 + 3 510', $c['paye'], 4635.0 );
eq( 'net 40 000 − 4 635 − 177.12 − 6 000', $c['net'], 29187.88 );
$c = WB_Payroll::calc_payslip( [ 'salary' => 20000, 'retirement_fixed' => 6000, 'age' => 40 ], $Y );
eq( 'no bonus: still 27.5% of 20 000', $c['retirement_allowed'], 5500.0 );
eq( 'no bonus: PAYE 1 125', $c['paye'], 1125.0 );

/* ===================================================================== P12 */
section( 'P12: Sunday holidays (Public Holidays Act s2(1)) — no cascade, declared days are separate' );
$h22 = WB_Staff::public_holidays( 2022 );
eq( '2022: Workers\' Day on a Sunday → Monday 2 May', in_array( '2022-05-02', $h22, true ), true );
eq( '2022: Christmas on a Sunday → Monday 26 Dec (already Goodwill)', in_array( '2022-12-26', $h22, true ), true );
eq( '2022: 27 Dec is not in the Act (it was declared by the President)', in_array( '2022-12-27', $h22, true ), false );
eq( '2022: 13 days', count( $h22 ), 13 );
eq( 'without WordPress, public_holidays = the Act\'s days', WB_Staff::public_holidays( 2026 ), WB_Staff::sa_public_holidays( 2026 ) );

/* ===================================================================== P13 */
section( 'P13: family responsibility leave needs at least four days a week (BCEA s27)' );
$fam = [ 'code' => 'family', 'days_per_year' => 3, 'cycle_months' => 12, 'accrual' => 'upfront', 'carry_over_max' => 0 ];
eq( '3 days a week: none', WB_Staff::leave_balance( $fam, '2026-01-01', '2026-10-02', [], 3 )['balance'], 0.0 );
eq( '4 days a week: 3 days', WB_Staff::leave_balance( $fam, '2026-01-01', '2026-10-02', [], 4 )['balance'], 3.0 );
eq( '5 days a week: 3 days', WB_Staff::leave_balance( $fam, '2026-01-01', '2026-10-02', [], 5 )['balance'], 3.0 );
eq( '5 days a week but under 4 months: none', WB_Staff::leave_balance( $fam, '2026-08-01', '2026-10-02', [], 5 )['balance'], 0.0 );

/* ===================================================================== P15 */
section( 'P15: brand colours are re-checked before printing' );
$sc = WB_Setup::safe_colors( [ 'primary' => 'red;}</style><script>', 'ink' => '#abc' ] );
eq( 'a bad stored colour falls back to the default', $sc['primary'], '#8A3B52' );
eq( 'a good one is normalised', $sc['ink'], '#AABBCC' );
eq( 'missing keys get the default', $sc['line'], WB_Setup::DEFAULT_COLORS['line'] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
