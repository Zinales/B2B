<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Payroll — South African monthly payroll: calculates, records and produces files. It never
 * pays anyone and never submits anything to SARS.
 *
 * Tables (JetEngine CCTs):
 *   wb_payroll_profiles  one per person paid: pay type, salary / hourly rate, tax number and bank
 *                        account (all ENCRYPTED with wb_enc), branch code, date of birth (for the
 *                        age rebates), medical scheme members, retirement contribution, UIF exempt.
 *   wb_pay_runs          one per month: draft → checked (by a DIFFERENT person) → finalised.
 *   wb_payslips          one per person per run; IMMUTABLE once finalised (WB_CCT refuses the
 *                        update). A correction is an adjustment line in the next run.
 *
 * Tax figures live in option wb_tax_years (one row per tax year, seeded from
 * docs/PAYROLL-RULES-2027.md) and are never hard-coded in the maths. A pay date with no tax-year
 * row is refused: payroll never guesses tax rates.
 *
 * Method (the standard tax-table method): monthly taxable × 12 → brackets → minus rebates by age
 * on the last day of the tax year → ÷ 12 → minus the monthly medical credit → never below zero →
 * cents. Bonuses: the difference method (tax on annual + bonus − tax on annual). Hourly pay from
 * APPROVED timesheets only: ordinary hours up to the weekly limit (45), overtime above it at the
 * overtime rate (1.5×; both settings). A week that starts in the previous month counts that
 * month's approved hours toward the weekly limit, but only this month's days are paid (P3).
 * Approved UNPAID leave, and working days before the profile's start date or after its end date
 * (joiners and leavers, P2), reduce a monthly salary pro rata by working days. Hourly staff are
 * paid their ordinary daily hours for a public holiday on an ordinary working day, and hours
 * worked on a public holiday at the holiday-work rate (2× by default; BCEA s18, P9).
 *
 * The arithmetic is PURE static functions — tested without WordPress.
 */
class WB_Payroll {

	/** The 2027 tax year (1 March 2026 – 28 February 2027), exactly as docs/PAYROLL-RULES-2027.md. */
	const DEFAULT_TAX_YEARS = [
		'2027' => [
			'label'      => '2027 tax year (1 March 2026 – 28 February 2027)',
			'from'       => '2026-03-01',
			'to'         => '2027-02-28',
			'brackets'   => [   // annual taxable income above `above`: base + rate% of the part above
				[ 'above' => 0, 'base' => 0, 'rate' => 18 ],
				[ 'above' => 245100, 'base' => 44118, 'rate' => 26 ],
				[ 'above' => 383100, 'base' => 79998, 'rate' => 31 ],
				[ 'above' => 530200, 'base' => 125599, 'rate' => 36 ],
				[ 'above' => 695800, 'base' => 185215, 'rate' => 39 ],
				[ 'above' => 887000, 'base' => 259783, 'rate' => 41 ],
				[ 'above' => 1878600, 'base' => 666339, 'rate' => 45 ],
			],
			'rebates'    => [ 'primary' => 17820, 'secondary' => 9765, 'tertiary' => 3249 ],
			'thresholds' => [ 'under_65' => 99000, 'age_65' => 153250, 'age_75' => 171300 ],
			'medical'    => [ 'member' => 376, 'member_plus_one' => 752, 'each_additional' => 254 ],   // monthly, combined mode
			'uif'        => [ 'employee_pct' => 1, 'employer_pct' => 1, 'ceiling_monthly' => 17712, 'min_hours_month' => 24 ],
			'sdl'        => [ 'pct' => 1, 'exempt_annual_payroll' => 500000 ],
			'retirement' => [ 'pct_cap' => 27.5, 'annual_cap' => 430000 ],
			'source'     => 'docs/PAYROLL-RULES-2027.md, checked 2 October 2026 (SARS; retirement cap from secondary sources — confirm on SARS).',
		],
	];

	const DEFAULT_SETTINGS = [ 'sdl_registered' => 0, 'overtime_multiplier' => 1.5, 'overtime_weekly_hours' => 45, 'configured' => 0, 'holiday_work_multiplier' => 2.0 ];

	/** A new profile's values for fields left blank (an EDIT keeps what is on file instead — P1). */
	const PROFILE_DEFAULTS = [ 'pay_type' => 'monthly', 'bank_branch_code' => '', 'date_of_birth' => '', 'medical_scheme_members' => 0, 'retirement_contribution_pct' => 0,
		'retirement_contribution_fixed' => 0, 'uif_exempt' => 'no', 'start_date' => '', 'end_date' => '' ];

	const RUN_FLOW = [ 'draft' => [ 'checked' ], 'checked' => [ 'finalised', 'draft' ], 'finalised' => [] ];

	/* ================================================================== pure: tax */

	/** The tax-year row whose from–to covers $ymd (with its key as 'year'), or null. */
	public static function tax_year_for( string $ymd, array $years ): ?array {
		$d = substr( $ymd, 0, 10 );
		foreach ( $years as $k => $y ) {
			if ( ! is_array( $y ) || empty( $y['from'] ) || empty( $y['to'] ) ) continue;
			if ( $d >= (string) $y['from'] && $d <= (string) $y['to'] ) return [ 'year' => (string) $k ] + $y;
		}
		return null;
	}

	/** Tax from the brackets on an annual amount (before rebates). */
	public static function bracket_tax( float $annual, array $brackets ): float {
		if ( $annual <= 0 ) return 0.0;
		usort( $brackets, fn( $a, $b ) => $a['above'] <=> $b['above'] );
		$use = null;
		foreach ( $brackets as $b ) if ( $annual > (float) $b['above'] ) $use = $b;
		if ( ! $use ) return 0.0;
		return (float) $use['base'] + (float) $use['rate'] / 100 * ( $annual - (float) $use['above'] );
	}

	/** Whole years of age on $on (Y-m-d). null when the date of birth is missing or later than $on. */
	public static function age_at( string $dob, string $on ): ?int {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $dob, $b ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $on, $o ) ) return null;
		$age = (int) $o[1] - (int) $b[1];
		if ( (int) $o[2] < (int) $b[2] || ( (int) $o[2] === (int) $b[2] && (int) $o[3] < (int) $b[3] ) ) $age--;
		return $age >= 0 ? $age : null;
	}

	public static function rebates( int $age, array $year ): float {
		$r = (array) $year['rebates'];
		return (float) $r['primary'] + ( $age >= 65 ? (float) $r['secondary'] : 0 ) + ( $age >= 75 ? (float) $r['tertiary'] : 0 );
	}

	/** Annual tax after rebates, never below zero. */
	public static function annual_tax( float $annual_taxable, int $age, array $year ): float {
		return max( 0.0, self::bracket_tax( $annual_taxable, (array) $year['brackets'] ) - self::rebates( $age, $year ) );
	}

	/** Monthly medical scheme fees tax credit. $members: 0 none, 1 member only, 2 member + 1, … */
	public static function medical_credit( int $members, array $year ): float {
		$m = (array) $year['medical'];
		if ( $members <= 0 ) return 0.0;
		if ( 1 === $members ) return (float) $m['member'];
		return (float) $m['member_plus_one'] + ( $members - 2 ) * (float) $m['each_additional'];
	}

	/** Monthly PAYE on regular pay: annualise, brackets, rebates, ÷ 12, minus medical credit, floor 0, cents. */
	public static function paye_monthly( float $monthly_taxable, int $age, int $medical_members, array $year ): float {
		$monthly = self::annual_tax( $monthly_taxable * 12, $age, $year ) / 12 - self::medical_credit( $medical_members, $year );
		return round( max( 0.0, $monthly ), 2 );
	}

	/** PAYE on a bonus by the difference method: tax on (annual + bonus) − tax on annual. */
	public static function paye_bonus( float $monthly_taxable, float $bonus, int $age, array $year ): float {
		if ( $bonus <= 0 ) return 0.0;
		$annual = $monthly_taxable * 12;
		return round( max( 0.0, self::annual_tax( $annual + $bonus, $age, $year ) - self::annual_tax( $annual, $age, $year ) ), 2 );
	}

	/** [ employee, employer ] UIF on monthly remuneration, up to the ceiling. */
	public static function uif( float $remuneration, array $year, bool $exempt = false ): array {
		if ( $exempt || $remuneration <= 0 ) return [ 0.0, 0.0 ];
		$u    = (array) $year['uif'];
		$base = min( $remuneration, (float) $u['ceiling_monthly'] );
		return [ round( $base * (float) $u['employee_pct'] / 100, 2 ), round( $base * (float) $u['employer_pct'] / 100, 2 ) ];
	}

	public static function sdl( float $leviable, array $year, bool $registered ): float {
		return $registered && $leviable > 0 ? round( $leviable * (float) $year['sdl']['pct'] / 100, 2 ) : 0.0;
	}

	/** The part of a retirement contribution that reduces taxable pay: within 27.5% and the annual cap (monthly share). */
	public static function retirement_allowed( float $contribution, float $remuneration, array $year ): float {
		if ( $contribution <= 0 ) return 0.0;
		$r = (array) $year['retirement'];
		return round( max( 0.0, min( $contribution, $remuneration * (float) $r['pct_cap'] / 100, (float) $r['annual_cap'] / 12 ) ), 2 );
	}

	/* ================================================================== pure: time */

	/**
	 * Approved days [ [Y-m-d, hours], … ] → ordinary and overtime hours. Overtime is what goes over
	 * $weekly_limit in one ISO week (Monday–Sunday), taken from the latest days of the week.
	 * $pay_from (P3): days before it still count toward their week's limit but are not returned
	 * (they were paid in the month before). '' = every day given is paid.
	 */
	public static function split_hours( array $days, float $weekly_limit = 45.0, string $pay_from = '' ): array {
		$list = [];
		foreach ( $days as $d ) {
			$ymd = substr( (string) $d[0], 0, 10 );
			$ts  = strtotime( $ymd . ' 12:00:00 UTC' );
			if ( ! $ts ) continue;
			$list[] = [ $ymd, gmdate( 'o-W', $ts ), max( 0.0, (float) $d[1] ) ];
		}
		usort( $list, fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
		$used = [];
		$ord  = 0.0;
		$ot   = 0.0;
		foreach ( $list as [ $ymd, $wk, $h ] ) {
			$before      = $used[ $wk ] ?? 0.0;
			$o           = max( 0.0, min( $h, $weekly_limit - $before ) );
			$used[ $wk ] = $before + $h;
			if ( '' !== $pay_from && $ymd < $pay_from ) continue;
			$ord += $o;
			$ot  += $h - $o;
		}
		return [ 'ordinary' => round( $ord, 2 ), 'overtime' => round( $ot, 2 ) ];
	}

	/** The Monday (Y-m-d) of the ISO week containing $ymd. */
	public static function week_monday( string $ymd ): string {
		$ts = strtotime( substr( $ymd, 0, 10 ) . ' 12:00:00 UTC' );
		return gmdate( 'Y-m-d', $ts - ( (int) gmdate( 'N', $ts ) - 1 ) * 86400 );
	}

	/**
	 * Hourly pay hours for a period, public holidays included (P3 + P9).
	 * $days: approved [ [Y-m-d, hours], … ] from the Monday of the period's first week to its end.
	 * $holidays: public holidays (Y-m-d) covering those dates. $daily_hours: the person's ordinary
	 * hours a day (hours a week ÷ days a week). $employed_from / $employed_to: profile dates ('' = open).
	 *
	 * - Ordinary days: split_hours() with the weekly limit; earlier-month days count to the limit only.
	 * - A public holiday on an ordinary working day (Mon–Fri, or Mon–Sat for a 6-day week) that the
	 *   person did not work: $daily_hours paid at the ordinary rate (BCEA s18(2)(a)).
	 * - Hours worked on a public holiday: holiday_work_hours, paid at the holiday-work multiplier
	 *   (2× by default, s18(2)(b)(i)); they do not count toward the weekly overtime limit. When the
	 *   ordinary day's wage plus the hours worked is more (s18(2)(b)(ii) and s18(3)), the difference
	 *   is added to holiday_hours at the ordinary rate.
	 * needs_daily: how many holidays needed $daily_hours (the caller refuses when it is unknown).
	 */
	public static function hourly_hours( array $days, array $holidays, string $pay_from, string $pay_to, float $daily_hours, int $dpw = 5, float $weekly_limit = 45.0, float $holiday_multiplier = 2.0, string $employed_from = '', string $employed_to = '' ): array {
		$hol    = array_flip( array_map( fn( $d ) => substr( (string) $d, 0, 10 ), $holidays ) );
		$normal = [];
		$worked = [];   // holiday date → hours worked that day
		foreach ( $days as $d ) {
			$ymd = substr( (string) $d[0], 0, 10 );
			if ( $ymd > $pay_to ) continue;
			if ( isset( $hol[ $ymd ] ) ) {
				if ( $ymd >= $pay_from ) $worked[ $ymd ] = ( $worked[ $ymd ] ?? 0.0 ) + max( 0.0, (float) $d[1] );
				continue;
			}
			$normal[] = [ $ymd, (float) $d[1] ];
		}
		$split   = self::split_hours( $normal, $weekly_limit, $pay_from );
		$last    = $dpw >= 6 ? 6 : 5;
		$from    = '' !== $employed_from && $employed_from > $pay_from ? $employed_from : $pay_from;
		$to      = '' !== $employed_to && $employed_to < $pay_to ? $employed_to : $pay_to;
		$paid    = 0.0;
		$hw      = 0.0;
		$needs   = 0;
		$unworked = 0;
		foreach ( array_keys( $hol ) as $h ) {
			$h = (string) $h;
			if ( $h < $from || $h > $to ) continue;
			$ordinary_day = (int) gmdate( 'N', strtotime( $h . ' 12:00:00 UTC' ) ) <= $last;
			$w            = $worked[ $h ] ?? 0.0;
			if ( $w > 0 ) {
				$hw += $w;
				$needs++;
				$paid += max( 0.0, $daily_hours + $w - $holiday_multiplier * $w );   // never less than a day's wage + the time worked
			} elseif ( $ordinary_day ) {
				$needs++;
				$unworked++;
				$paid += $daily_hours;
			}
		}
		foreach ( $worked as $h => $w ) if ( ( $h < $from || $h > $to ) && $w > 0 ) $hw += $w;   // worked outside the profile dates: still worked
		return [ 'ordinary' => $split['ordinary'], 'overtime' => $split['overtime'], 'holiday_hours' => round( $paid, 2 ), 'holiday_work_hours' => round( $hw, 2 ), 'holidays_not_worked' => $unworked, 'needs_daily' => $needs ];
	}

	/**
	 * Working days of the period the person was not employed (P2): before $from (profile start) and
	 * after $to (profile end). Same working-day count as unpaid leave (WB_Staff::working_days).
	 */
	public static function days_not_employed( string $start, string $end, string $from, string $to, int $dpw = 5, ?array $holidays = null ): int {
		$day = fn( string $ymd, int $add ) => gmdate( 'Y-m-d', strtotime( substr( $ymd, 0, 10 ) . ' 12:00:00 UTC' ) + $add * 86400 );
		$n   = 0;
		if ( '' !== $from && $from > $start ) $n += WB_Staff::working_days( $start, min( $end, $day( $from, -1 ) ), $holidays, $dpw );
		if ( '' !== $to && $to < $end ) $n += WB_Staff::working_days( max( $start, $day( $to, 1 ) ), $end, $holidays, $dpw );
		return $n;
	}

	/**
	 * P1: the profile row to store. $new holds the plain (non-encrypted) fields from the form, with
	 * '' (or null) for a field that was missing or left blank. On an edit ($have = the stored row)
	 * a blank keeps the stored value; on a new profile it takes PROFILE_DEFAULTS. Pure.
	 */
	public static function merge_profile( ?array $have, array $new ): array {
		$out = [];
		foreach ( $new as $k => $v ) {
			$blank = null === $v || ( is_string( $v ) && '' === trim( $v ) );
			if ( ! $blank ) $out[ $k ] = $v;
			elseif ( $have && array_key_exists( $k, $have ) ) $out[ $k ] = $have[ $k ];
			else $out[ $k ] = self::PROFILE_DEFAULTS[ $k ] ?? '';
		}
		return $out;
	}

	/** Monthly salary less unpaid leave, pro rata by working days. Returns the deduction. */
	public static function unpaid_deduction( float $salary, float $unpaid_days, int $working_days ): float {
		if ( $working_days <= 0 || $unpaid_days <= 0 ) return 0.0;
		return round( $salary * min( $unpaid_days, $working_days ) / $working_days, 2 );
	}

	/* ================================================================== pure: the payslip */

	/**
	 * One person's month. $in: pay_type (monthly|hourly), salary, hourly_rate, ordinary_hours,
	 * overtime_hours, overtime_multiplier, unpaid_days, working_days, bonus, retirement_pct,
	 * retirement_fixed, medical_members, age (null = unknown: primary rebate only),
	 * uif_exempt, sdl_registered, other_deductions [ [label, amount], … ].
	 *
	 * Returns lines (earnings and deductions, each with its purpose), gross, taxable, paye,
	 * uif_employee, uif_employer, sdl, retirement, other_deductions (retirement + others), net,
	 * hours and rates, warnings.
	 */
	public static function calc_payslip( array $in, array $year ): array {
		$in += [ 'pay_type' => 'monthly', 'salary' => 0, 'hourly_rate' => 0, 'ordinary_hours' => 0, 'overtime_hours' => 0, 'overtime_multiplier' => 1.5,
			'holiday_hours' => 0, 'holiday_work_hours' => 0, 'holiday_work_multiplier' => 2.0, 'days_not_employed' => 0,
			'unpaid_days' => 0, 'working_days' => 0, 'bonus' => 0, 'retirement_pct' => 0, 'retirement_fixed' => 0, 'medical_members' => 0,
			'age' => null, 'uif_exempt' => false, 'sdl_registered' => false, 'other_deductions' => [] ];
		$lines    = [];
		$warnings = [];
		$ord_h    = 0.0;
		$ot_h     = 0.0;
		$hol_h    = 0.0;
		$hw_h     = 0.0;
		$rate     = 0.0;
		$ot_rate  = 0.0;
		$hw_rate  = 0.0;
		$fmt      = fn( $n ) => rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
		if ( 'hourly' === $in['pay_type'] ) {
			$rate    = round( (float) $in['hourly_rate'], 2 );
			$ord_h   = round( (float) $in['ordinary_hours'], 2 );
			$ot_h    = round( (float) $in['overtime_hours'], 2 );
			$hol_h   = round( max( 0.0, (float) $in['holiday_hours'] ), 2 );
			$hw_h    = round( max( 0.0, (float) $in['holiday_work_hours'] ), 2 );
			$ot_rate = round( $rate * (float) $in['overtime_multiplier'], 2 );
			$hw_rate = round( $rate * (float) $in['holiday_work_multiplier'], 2 );
			$ord_pay = round( $ord_h * $rate, 2 );
			$ot_pay  = round( $ot_h * $ot_rate, 2 );
			$hol_pay = round( $hol_h * $rate, 2 );
			$hw_pay  = round( $hw_h * $hw_rate, 2 );
			$lines[] = [ 'kind' => 'earning', 'code' => 'ordinary', 'label' => 'Ordinary hours', 'hours' => $ord_h, 'rate' => $rate, 'amount' => $ord_pay ];
			if ( $ot_h > 0 ) $lines[] = [ 'kind' => 'earning', 'code' => 'overtime', 'label' => 'Overtime', 'hours' => $ot_h, 'rate' => $ot_rate, 'amount' => $ot_pay ];
			if ( $hol_h > 0 ) $lines[] = [ 'kind' => 'earning', 'code' => 'public_holiday', 'label' => 'Public holiday pay', 'hours' => $hol_h, 'rate' => $rate, 'amount' => $hol_pay ];
			if ( $hw_h > 0 ) $lines[] = [ 'kind' => 'earning', 'code' => 'holiday_work', 'label' => 'Work on a public holiday', 'hours' => $hw_h, 'rate' => $hw_rate, 'amount' => $hw_pay ];
			$regular = round( $ord_pay + $ot_pay + $hol_pay + $hw_pay, 2 );
			if ( $ord_h + $ot_h + $hw_h <= 0 ) $warnings[] = 'No approved hours this month.';
		} else {
			$salary  = round( (float) $in['salary'], 2 );
			$wd      = (int) $in['working_days'];
			$ne      = max( 0.0, (float) $in['days_not_employed'] );
			$ul      = max( 0.0, (float) $in['unpaid_days'] );
			$lines[] = [ 'kind' => 'earning', 'code' => 'salary', 'label' => 'Monthly salary', 'amount' => $salary ];
			$ne_ded  = self::unpaid_deduction( $salary, $ne, $wd );
			$all_ded = self::unpaid_deduction( $salary, $ne + $ul, $wd );   // together never more than the salary
			$unpaid  = round( $all_ded - $ne_ded, 2 );
			if ( $ne_ded > 0 ) $lines[] = [ 'kind' => 'earning', 'code' => 'not_employed', 'label' => sprintf( 'Not yet started or already left (%s of %d working days)', $fmt( $ne ), $wd ), 'amount' => -$ne_ded ];
			if ( $unpaid > 0 ) $lines[] = [ 'kind' => 'earning', 'code' => 'unpaid_leave', 'label' => sprintf( 'Unpaid leave (%s of %d working days)', $fmt( $ul ), $wd ), 'amount' => -$unpaid ];
			$regular = round( $salary - $all_ded, 2 );
		}
		$bonus = round( max( 0.0, (float) $in['bonus'] ), 2 );
		if ( $bonus > 0 ) $lines[] = [ 'kind' => 'earning', 'code' => 'bonus', 'label' => 'Bonus', 'amount' => $bonus ];
		$gross = round( $regular + $bonus, 2 );

		// Retirement (P11): the 27.5% limit is on all remuneration, bonus included. The part allowed on
		// regular pay reduces the (annualised) regular taxable pay; any extra room the bonus gives
		// reduces the taxable bonus, so a once-off bonus is not annualised.
		$contribution  = (float) $in['retirement_fixed'] > 0 ? round( (float) $in['retirement_fixed'], 2 ) : round( $regular * (float) $in['retirement_pct'] / 100, 2 );
		$allowed_reg   = self::retirement_allowed( $contribution, $regular, $year );
		$allowed       = $bonus > 0 ? max( $allowed_reg, self::retirement_allowed( $contribution, $gross, $year ) ) : $allowed_reg;
		$taxable_reg   = max( 0.0, round( $regular - $allowed_reg, 2 ) );
		$taxable_bonus = max( 0.0, round( $bonus - ( $allowed - $allowed_reg ), 2 ) );
		$age           = null === $in['age'] ? 0 : (int) $in['age'];
		if ( null === $in['age'] ) $warnings[] = 'No date of birth on file: only the primary rebate was given.';
		$paye_reg   = self::paye_monthly( $taxable_reg, $age, (int) $in['medical_members'], $year );
		$paye_bonus = self::paye_bonus( $taxable_reg, $taxable_bonus, $age, $year );
		$paye       = round( $paye_reg + $paye_bonus, 2 );

		$uif_exempt = (bool) $in['uif_exempt'];
		$worked_h   = $ord_h + $ot_h + $hw_h;
		if ( 'hourly' === $in['pay_type'] && $worked_h > 0 && $worked_h < (float) $year['uif']['min_hours_month'] ) $uif_exempt = true;   // under 24 hours a month
		[ $uif_e, $uif_r ] = self::uif( $gross, $year, $uif_exempt );
		$sdl = self::sdl( $gross, $year, (bool) $in['sdl_registered'] );

		$lines[] = [ 'kind' => 'deduction', 'code' => 'paye', 'label' => 'Income tax (PAYE)', 'purpose' => 'Paid to SARS on your behalf' . ( $paye_bonus > 0 ? sprintf( ' (%s of it on the bonus)', number_format( $paye_bonus, 2, '.', ' ' ) ) : '' ), 'amount' => $paye ];
		$lines[] = [ 'kind' => 'deduction', 'code' => 'uif', 'label' => 'UIF (your 1%)', 'purpose' => $uif_exempt ? 'Not deducted (exempt)' : 'Unemployment insurance, paid to SARS for the UIF', 'amount' => $uif_e ];
		if ( $contribution > 0 ) $lines[] = [ 'kind' => 'deduction', 'code' => 'retirement', 'label' => 'Retirement fund', 'purpose' => 'Your contribution, paid to your fund (' . number_format( $allowed, 2, '.', ' ' ) . ' of it reduces taxable pay)', 'amount' => $contribution ];
		$other = 0.0;
		foreach ( (array) $in['other_deductions'] as $od ) {
			$amt = round( (float) ( $od[1] ?? 0 ), 2 );
			if ( $amt <= 0 ) continue;
			$other  += $amt;
			$lines[] = [ 'kind' => 'deduction', 'code' => 'other', 'label' => (string) ( $od[0] ?? 'Other deduction' ), 'purpose' => (string) ( $od[2] ?? 'As agreed in writing' ), 'amount' => $amt ];
		}
		$lines[] = [ 'kind' => 'employer', 'code' => 'uif_employer', 'label' => 'UIF (employer 1%)', 'purpose' => 'Paid by the employer, not taken from your pay', 'amount' => $uif_r ];
		if ( $sdl > 0 ) $lines[] = [ 'kind' => 'employer', 'code' => 'sdl', 'label' => 'Skills levy (SDL)', 'purpose' => 'Paid by the employer, not taken from your pay', 'amount' => $sdl ];

		$net = round( $gross - $paye - $uif_e - $contribution - $other, 2 );
		if ( $net < 0 ) $warnings[] = 'Deductions are more than the pay.';
		return [
			'lines' => $lines, 'gross' => $gross, 'taxable' => round( $taxable_reg + $taxable_bonus, 2 ), 'paye' => $paye, 'paye_bonus' => $paye_bonus,
			'uif_employee' => $uif_e, 'uif_employer' => $uif_r, 'sdl' => $sdl, 'retirement' => $contribution, 'retirement_allowed' => $allowed,
			'other_deductions' => round( $contribution + $other, 2 ), 'net' => $net,
			'ordinary_hours' => $ord_h, 'overtime_hours' => $ot_h, 'ordinary_rate' => $rate, 'overtime_rate' => $ot_rate,
			'holiday_hours' => $hol_h, 'holiday_work_hours' => $hw_h, 'holiday_work_rate' => $hw_rate, 'warnings' => $warnings,
		];
	}

	/** EMP201 figures from a run's payslips (for the owner to capture on eFiling — never submitted). */
	public static function emp201( array $payslips ): array {
		$paye = 0.0; $uif = 0.0; $sdl = 0.0;
		foreach ( $payslips as $p ) {
			$paye += (float) $p['paye'];
			$uif  += (float) $p['uif_employee'] + (float) $p['uif_employer'];
			$sdl  += (float) $p['sdl'];
		}
		return [ 'paye' => round( $paye, 2 ), 'uif' => round( $uif, 2 ), 'sdl' => round( $sdl, 2 ), 'total' => round( $paye + $uif + $sdl, 2 ) ];
	}

	/** Net-pay CSV for the bank: [ [name, account, branch, amount, reference], … ] → text. */
	public static function bank_csv( array $rows ): string {
		$esc = fn( $v ) => '"' . str_replace( '"', '""', preg_replace( '/^[=+\-@\t\r]/', "'$0", (string) $v ) ) . '"';   // no spreadsheet formulas
		$out = "Name,Account number,Branch code,Amount,Reference\r\n";
		foreach ( $rows as $r ) $out .= implode( ',', [ $esc( $r[0] ), $esc( $r[1] ), $esc( $r[2] ), number_format( (float) $r[3], 2, '.', '' ), $esc( $r[4] ) ] ) . "\r\n";
		return $out;
	}

	/* ================================================================== WordPress side */

	public static function init(): void {
		add_shortcode( 'wb_payroll', [ __CLASS__, 'screen' ] );
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			foreach ( [ 'payroll_profile', 'payroll_run_new', 'payroll_run_recalc', 'payroll_run_check', 'payroll_run_reopen', 'payroll_run_finalise', 'payroll_adjust', 'payroll_settings' ] as $a ) {
				$h[ $a ] = [ __CLASS__, 'handle_' . substr( $a, 8 ) ];
			}
			return $h;
		} );
		// 0.2.2: tell the owners when someone with payslips can no longer open them
		add_action( 'set_user_role', fn( $uid ) => self::check_payslip_access( (int) $uid ), 20 );
		add_action( 'remove_user_role', fn( $uid ) => self::check_payslip_access( (int) $uid ), 20 );
		add_action( 'add_user_role', fn( $uid ) => self::check_payslip_access( (int) $uid ), 20 );
		add_action( 'updated_user_meta', function ( $mid, $uid, $key ) { if ( WB_Roles::META === $key ) self::check_payslip_access( (int) $uid ); }, 20, 3 );
		add_action( 'delete_user', fn( $uid ) => self::check_payslip_access( (int) $uid, true ), 5 );
		add_action( 'wb_nightly', [ __CLASS__, 'sweep_payslip_access' ], 40 );
	}

	/* ------------------------------------------------- 0.2.2 payslip access for leavers */

	const ACCESS_FLAG = 'wb_payslip_access_lost';   // user meta: set once the owners were told

	/**
	 * Pure: what to do about one login. 'notify' the first time someone with finalised payslips
	 * cannot open them; 'clear' the flag when access comes back; otherwise 'none'.
	 */
	public static function access_action( bool $has_payslips, bool $can_open, bool $already_told ): string {
		if ( ! $has_payslips ) return $already_told ? 'clear' : 'none';
		if ( $can_open ) return $already_told ? 'clear' : 'none';
		return $already_told ? 'none' : 'notify';
	}

	/** The words the owners read. $left: the staff file is ended or inactive. */
	public static function access_message( string $name, bool $left, bool $deleted ): string {
		$who = '' !== trim( $name ) ? $name : 'A staff member';
		$why = $deleted ? 'their login was removed' : 'their login no longer has workspace access';
		return sprintf( '%s %scan no longer open their payslips, because %s. If they ask for one, download it from Payroll and send it to them yourself.',
			$who, $left ? 'has left and ' : '', $why );
	}

	/**
	 * Check one login and tell the owners (in-app only, never email) the first time a person with
	 * finalised payslips loses access. Runs on role and dashboard changes, on deletion, and nightly.
	 */
	public static function check_payslip_access( int $uid, bool $deleting = false ): void {
		if ( $uid <= 0 || ! class_exists( 'WB_Staff' ) ) return;
		$staff_ids = WB_Staff::staff_ids_for_user( $uid );
		if ( ! $staff_ids ) return;
		$has = false;
		foreach ( $staff_ids as $sid ) {
			if ( WB_CCT::count( 'wb_payslips', [ 'staff_id' => $sid, 'status' => 'finalised' ], false ) > 0 ) { $has = true; break; }
		}
		$can  = ! $deleting && user_can( $uid, 'wb_access_workspace' );
		$told = (bool) get_user_meta( $uid, self::ACCESS_FLAG, true );
		$act  = self::access_action( $has, $can, $told );
		if ( 'clear' === $act ) { delete_user_meta( $uid, self::ACCESS_FLAG ); return; }
		if ( 'notify' !== $act ) return;

		$staff = WB_CCT::get( 'wb_staff', (int) $staff_ids[0] );
		$name  = $staff ? trim( (string) ( $staff['first_name'] ?? '' ) . ' ' . (string) ( $staff['last_name'] ?? '' ) ) : '';
		$left  = $staff && ( '' !== (string) ( $staff['ended_at'] ?? '' ) || in_array( (string) ( $staff['status'] ?? '' ), [ 'inactive', 'ended', 'left' ], true ) );
		$msg   = self::access_message( $name, $left, $deleting );
		WB_Notifications::notify_owners( 'staff', $msg, WB_Workspace::url( 'payroll' ), 'wb_staff', (int) $staff_ids[0] );
		wb_ledger_write( 'payslip_access_lost_notice', 'wb_staff', (int) $staff_ids[0], null, [ 'user_id' => $uid, 'left' => $left, 'login_removed' => $deleting ] );
		if ( ! $deleting ) update_user_meta( $uid, self::ACCESS_FLAG, current_time( 'mysql' ) );
	}

	/** Nightly backstop: anyone whose access changed without a hook firing (a role edited by another plugin). */
	public static function sweep_payslip_access(): void {
		$seen = [];
		foreach ( WB_CCT::find( 'wb_staff', [], [ 'limit' => 2000, 'active_only' => false ] ) as $s ) {
			$uid = (int) ( $s['wp_user_id'] ?? 0 );
			if ( $uid <= 0 || isset( $seen[ $uid ] ) || ! get_userdata( $uid ) ) continue;
			$seen[ $uid ] = true;
			self::check_payslip_access( $uid );
		}
	}

	public static function settings(): array {
		return array_merge( self::DEFAULT_SETTINGS, array_intersect_key( (array) get_option( 'wb_payroll', [] ), self::DEFAULT_SETTINGS ) );
	}

	public static function tax_years(): array {
		$y = get_option( 'wb_tax_years', self::DEFAULT_TAX_YEARS );
		return is_array( $y ) ? $y : [];
	}

	public static function may_view(): bool {
		return current_user_can( 'wb_view_payroll' ) || current_user_can( 'wb_run_payroll' ) || current_user_can( 'wb_check_payroll' );
	}

	private static function err( string $code, string $msg ): WP_Error {
		return new WP_Error( $code, $msg );
	}

	private static function staff_name( $id ): string {
		$s = WB_CCT::get( 'wb_staff', (int) $id );
		return $s ? trim( $s['first_name'] . ' ' . $s['last_name'] ) : '#' . (int) $id;
	}

	private static function dec_amount( string $enc ): ?float {
		if ( '' === $enc ) return null;
		$p = wb_dec( $enc );
		return '' === $p || ! is_numeric( $p ) ? null : (float) $p;
	}

	/* ---------------------------------------------------------------- profiles */

	/**
	 * Create or edit a person's payroll profile. Salary, hourly rate, tax number and bank account are
	 * encrypted; ANY field that is missing or blank on an edit keeps what is on file (P1). To remove
	 * a leaving date, send end_date_clear = yes.
	 *
	 * @return int|WP_Error profile id
	 */
	public static function save_profile( array $in ) {
		if ( ! current_user_can( 'wb_run_payroll' ) ) return self::err( 'wb_forbidden', 'You cannot set up payroll.' );
		if ( '' === wb_enc_key() ) return self::err( 'wb_no_key', 'The encryption key (WB_ENCRYPTION_KEY in wp-config.php) is not set, so pay details cannot be stored safely. Nothing was saved.' );
		$staff_id = absint( $in['staff_id'] ?? 0 );
		if ( ! WB_CCT::get( 'wb_staff', $staff_id ) ) return self::err( 'wb_no_staff', 'Choose the person.' );
		$have = WB_CCT::first( 'wb_payroll_profiles', [ 'staff_id' => $staff_id ] );
		$s    = fn( string $k ) => trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );

		// The plain fields: '' = missing or blank (kept on an edit, default on a new profile).
		$new = [ 'pay_type' => in_array( $s( 'pay_type' ), [ 'monthly', 'hourly' ], true ) ? $s( 'pay_type' ) : '' ];
		$branch = preg_replace( '/\D/', '', $s( 'bank_branch_code' ) );
		if ( '' !== $branch && 6 !== strlen( $branch ) ) return self::err( 'wb_branch', 'A branch code has 6 digits.' );
		$new['bank_branch_code'] = $branch;
		foreach ( [ 'date_of_birth' => 'Choose the date of birth.', 'start_date' => 'Choose the date pay starts.', 'end_date' => 'Choose the date pay ends.' ] as $f => $msg ) {
			$v = substr( $s( $f ), 0, 10 );
			if ( '' !== $v && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) return self::err( 'wb_date', $msg );
			$new[ $f ] = $v;
		}
		$med = $s( 'medical_scheme_members' );
		if ( '' !== $med && ( ! is_numeric( $med ) || (float) $med < 0 ) ) return self::err( 'wb_medical', 'Check the number of people on the medical scheme.' );
		$new['medical_scheme_members'] = '' === $med ? '' : min( 20, (int) $med );
		foreach ( [ 'retirement_contribution_pct', 'retirement_contribution_fixed' ] as $f ) {
			$v = $s( $f );
			if ( '' !== $v && ( ! is_numeric( $v ) || (float) $v < 0 || ( 'retirement_contribution_pct' === $f && (float) $v > 100 ) ) ) return self::err( 'wb_retirement', 'Check the retirement contribution.' );
			$new[ $f ] = '' === $v ? '' : ( 'retirement_contribution_fixed' === $f ? round( (float) $v, 2 ) : (float) $v );
		}
		$new['uif_exempt'] = in_array( $s( 'uif_exempt' ), [ 'yes', 'no' ], true ) ? $s( 'uif_exempt' ) : '';

		$row = [ 'staff_id' => $staff_id ] + self::merge_profile( $have, $new );
		if ( 'yes' === $s( 'end_date_clear' ) ) $row['end_date'] = '';
		$type = 'hourly' === (string) $row['pay_type'] ? 'hourly' : 'monthly';
		$row['pay_type'] = $type;
		if ( '' === (string) $row['start_date'] ) return self::err( 'wb_start', 'Choose the date pay starts.' );
		if ( '' !== (string) $row['end_date'] && (string) $row['end_date'] < (string) $row['start_date'] ) return self::err( 'wb_end', 'The date pay ends is before the date it starts.' );

		foreach ( [ 'salary' => 'salary_enc', 'hourly_rate' => 'hourly_rate_enc' ] as $f => $col ) {
			$v = trim( (string) ( $in[ $f ] ?? '' ) );
			if ( '' === $v ) continue;
			if ( ! is_numeric( $v ) || (float) $v < 0 ) return self::err( 'wb_amount', 'Pay amounts are numbers, for example 18500.00.' );
			$row[ $col ] = wb_enc( number_format( (float) $v, 2, '.', '' ) );
		}
		$need = 'hourly' === $type ? 'hourly_rate_enc' : 'salary_enc';
		if ( empty( $row[ $need ] ) && empty( $have[ $need ] ) ) return self::err( 'wb_amount', 'hourly' === $type ? 'Enter the rate per hour.' : 'Enter the monthly salary.' );

		$tax = preg_replace( '/\D/', '', (string) ( $in['tax_number'] ?? '' ) );
		if ( '' !== $tax ) {
			if ( 10 !== strlen( $tax ) ) return self::err( 'wb_tax_no', 'A SARS income tax number has 10 digits.' );
			$row['tax_number_enc'] = wb_enc( $tax );
		}
		$acc = preg_replace( '/\D/', '', (string) ( $in['bank_account'] ?? '' ) );
		if ( '' !== $acc ) {
			if ( strlen( $acc ) < 6 || strlen( $acc ) > 16 ) return self::err( 'wb_bank', 'Check the bank account number.' );
			$row['bank_account_enc'] = wb_enc( $acc );
		}

		if ( $have ) {
			$r = WB_CCT::update( 'wb_payroll_profiles', (int) $have['_ID'], $row, 'payroll_profile_updated' );
			return is_wp_error( $r ) ? $r : (int) $have['_ID'];
		}
		return WB_CCT::insert( 'wb_payroll_profiles', $row, 'payroll_profile_created' );
	}

	/** Profiles paid in a period (started on or before its end, not ended before its start). */
	public static function active_profiles( string $start, string $end ): array {
		return array_values( array_filter( WB_CCT::find( 'wb_payroll_profiles', [], [ 'limit' => 2000, 'order' => 'ASC' ] ), function ( $p ) use ( $start, $end ) {
			return '' !== (string) $p['start_date'] && (string) $p['start_date'] <= $end && ( '' === (string) $p['end_date'] || (string) $p['end_date'] >= $start );
		} ) );
	}

	/** Approved unpaid-leave working days inside the period. */
	private static function unpaid_days( int $staff_id, string $start, string $end, int $dpw ): float {
		$types = array_filter( WB_CCT::find( 'wb_leave_types', [], [ 'limit' => 100 ] ), fn( $t ) => 'unpaid' === (string) ( $t['code'] ?? '' ) || false !== stripos( (string) $t['name'], 'unpaid' ) );
		if ( ! $types ) return 0.0;
		$days = 0.0;
		foreach ( WB_CCT::find( 'wb_leave', [ 'staff_id' => $staff_id, 'status' => 'approved', 'leave_type_id' => array_map( fn( $t ) => (int) $t['_ID'], $types ) ], [ 'limit' => 500 ] ) as $l ) {
			$from = max( (string) $l['from_date'], $start );
			$to   = min( (string) $l['to_date'], $end );
			if ( $from <= $to ) $days += WB_Staff::working_days( $from, $to, null, $dpw );
		}
		return $days;
	}

	/**
	 * The calc_payslip input for one profile and month, read from the records. WP_Error when an
	 * encrypted amount cannot be read (fail closed — never pay R0 by accident).
	 *
	 * @return array|WP_Error
	 */
	private static function inputs_for( array $p, string $start, string $end, array $year, array $adj = [] ) {
		$staff = WB_CCT::get( 'wb_staff', (int) $p['staff_id'] );
		$name  = self::staff_name( $p['staff_id'] );
		$set   = self::settings();
		$dpw   = (int) ( $staff['days_per_week'] ?? 5 ) ?: 5;
		$in    = [
			'pay_type' => (string) $p['pay_type'], 'overtime_multiplier' => (float) $set['overtime_multiplier'],
			'holiday_work_multiplier' => max( 2.0, (float) $set['holiday_work_multiplier'] ),
			'retirement_pct' => (float) $p['retirement_contribution_pct'], 'retirement_fixed' => (float) $p['retirement_contribution_fixed'],
			'medical_members' => (int) $p['medical_scheme_members'], 'age' => self::age_at( (string) $p['date_of_birth'], (string) $year['to'] ),
			'uif_exempt' => wb_truthy( $p['uif_exempt'] ?? '' ), 'sdl_registered' => wb_truthy( $set['sdl_registered'] ?? '' ),
			'bonus' => (float) ( $adj['bonus'] ?? 0 ), 'other_deductions' => (array) ( $adj['other'] ?? [] ),
		];
		// The part of the period the person was employed (P2).
		$emp_from = max( $start, (string) $p['start_date'] );
		$emp_to   = '' !== (string) $p['end_date'] ? min( $end, (string) $p['end_date'] ) : $end;
		if ( 'hourly' === $p['pay_type'] ) {
			$rate = self::dec_amount( (string) $p['hourly_rate_enc'] );
			if ( null === $rate ) return self::err( 'wb_decrypt', sprintf( 'The hourly rate for %s cannot be read (check the encryption key). Nothing was calculated.', $name ) );
			// P3: from the Monday of the period's first week, so a week that started last month counts
			// its earlier days toward the weekly overtime limit (they are not paid again).
			$monday = self::week_monday( $start );
			$days   = array_map( fn( $t ) => [ (string) $t['work_date'], (float) $t['hours'] ], WB_CCT::find( 'wb_timesheets', [ 'staff_id' => (int) $p['staff_id'], 'status' => 'approved', 'work_date >=' => $monday, 'work_date <=' => $end ], [ 'limit' => 500, 'orderby' => 'work_date', 'order' => 'ASC' ] ) );
			$hols   = [];
			for ( $y = (int) substr( $monday, 0, 4 ); $y <= (int) substr( $end, 0, 4 ); $y++ ) $hols = array_merge( $hols, WB_Staff::public_holidays( $y ) );
			$daily  = (float) ( $staff['hours_per_week'] ?? 0 ) > 0 ? round( (float) $staff['hours_per_week'] / $dpw, 2 ) : 0.0;
			$h      = self::hourly_hours( $days, $hols, $start, $end, $daily, $dpw, (float) $set['overtime_weekly_hours'], $in['holiday_work_multiplier'], $emp_from, $emp_to );
			if ( $h['needs_daily'] > 0 && $daily <= 0 ) return self::err( 'wb_no_hours', sprintf( 'There is a public holiday this month and the staff file for %s has no hours a week, so their public holiday pay cannot be worked out. Add the hours a week, then recalculate. Nothing was calculated.', $name ) );
			$in += [ 'hourly_rate' => $rate, 'ordinary_hours' => $h['ordinary'], 'overtime_hours' => $h['overtime'], 'holiday_hours' => $h['holiday_hours'], 'holiday_work_hours' => $h['holiday_work_hours'] ];
		} else {
			$salary = self::dec_amount( (string) $p['salary_enc'] );
			if ( null === $salary ) return self::err( 'wb_decrypt', sprintf( 'The salary for %s cannot be read (check the encryption key). Nothing was calculated.', $name ) );
			$in += [
				'salary' => $salary, 'working_days' => WB_Staff::working_days( $start, $end, null, $dpw ),
				'days_not_employed' => self::days_not_employed( $start, $end, (string) $p['start_date'], (string) $p['end_date'], $dpw ),
				'unpaid_days' => $emp_from <= $emp_to ? self::unpaid_days( (int) $p['staff_id'], $emp_from, $emp_to, $dpw ) : 0.0,
			];
		}
		return $in;
	}

	/* ---------------------------------------------------------------- runs */

	private static function period_bounds( string $period ): ?array {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $period, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 ) return null;
		$start = $period . '-01';
		return [ $start, gmdate( 'Y-m-t', strtotime( $start . ' 12:00:00 UTC' ) ) ];
	}

	/**
	 * Start a month's pay run (draft) and calculate every payslip.
	 *
	 * @return int|WP_Error run id
	 */
	public static function create_run( string $period, string $pay_date ) {
		if ( ! current_user_can( 'wb_run_payroll' ) ) return self::err( 'wb_forbidden', 'You cannot run payroll.' );
		$me = WB_Staff::current_staff_id();
		if ( ! $me ) return self::err( 'wb_no_staff', 'Your login is not linked to a staff record, so it cannot prepare a pay run.' );
		$b = self::period_bounds( $period );
		if ( ! $b ) return self::err( 'wb_period', 'Choose the month (for example 2026-10).' );
		$pay_date = substr( sanitize_text_field( $pay_date ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pay_date ) ) return self::err( 'wb_date', 'Choose the pay date.' );
		$year = self::tax_year_for( $pay_date, self::tax_years() );
		if ( ! $year ) return self::err( 'wb_no_tax_year', sprintf( 'There are no tax figures for a pay date of %s. Add that tax year first — payroll never guesses tax rates.', $pay_date ) );
		$cols = WB_CCT::require_columns( 'wb_payslips', [ 'run_id', 'staff_id', 'lines_json', 'gross', 'paye', 'uif_employee', 'uif_employer', 'sdl', 'net', 'status' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		if ( WB_CCT::first( 'wb_pay_runs', [ 'period' => $period ] ) ) return self::err( 'wb_dup_run', 'There is already a pay run for ' . $period . '.' );
		$run = WB_CCT::insert( 'wb_pay_runs', [ 'period' => $period, 'period_start' => $b[0], 'period_end' => $b[1], 'pay_date' => $pay_date, 'tax_year' => $year['year'], 'status' => 'draft', 'prepared_by' => $me ], 'pay_run_created' );
		if ( is_wp_error( $run ) ) return $run;
		$res = self::build_payslips( (int) $run, [] );
		if ( is_wp_error( $res ) ) {
			WB_CCT::set_status( 'wb_pay_runs', (int) $run, 'void', $res->get_error_message() );
			return $res;
		}
		return (int) $run;
	}

	/** Calculate (or re-calculate) a draft run's payslips. $adjust = [ staff_id => [bonus, other] ]. @return true|WP_Error */
	private static function build_payslips( int $run_id, array $adjust ) {
		$run  = WB_CCT::get( 'wb_pay_runs', $run_id );
		$year = self::tax_year_for( (string) $run['pay_date'], self::tax_years() );
		if ( ! $year ) return self::err( 'wb_no_tax_year', 'There are no tax figures for this pay date.' );
		$calc = [];
		foreach ( self::active_profiles( (string) $run['period_start'], (string) $run['period_end'] ) as $p ) {
			$in = self::inputs_for( $p, (string) $run['period_start'], (string) $run['period_end'], $year, (array) ( $adjust[ (int) $p['staff_id'] ] ?? [] ) );
			if ( is_wp_error( $in ) ) return $in;   // nothing written yet: all or nothing
			$calc[] = [ $p, $in, self::calc_payslip( $in, $year ) ];
		}
		if ( ! $calc ) return self::err( 'wb_no_profiles', 'Nobody has a payroll profile for this month yet.' );
		// P5: never recalculate over a finalised payslip, and stop if an old payslip cannot be voided
		// (otherwise the run would hold two payslips for one person).
		$olds = WB_CCT::find( 'wb_payslips', [ 'run_id' => $run_id ], [ 'limit' => 2000 ] );
		foreach ( $olds as $old ) {
			if ( 'finalised' === (string) $old['status'] ) return self::err( 'wb_part_final', 'Some payslips on this run are already finalised, so it cannot be recalculated. Nothing was changed.' );
		}
		foreach ( $olds as $old ) {
			$v = WB_CCT::set_status( 'wb_payslips', (int) $old['_ID'], 'void', 'recalculated' );
			if ( is_wp_error( $v ) ) return $v;
		}
		foreach ( $calc as [ $p, $in, $c ] ) {
			$r = WB_CCT::insert( 'wb_payslips', [
				'run_id' => $run_id, 'staff_id' => (int) $p['staff_id'], 'period' => (string) $run['period'], 'status' => 'draft',
				'lines_json' => [ 'inputs' => array_diff_key( $in, [ 'salary' => 1, 'hourly_rate' => 1 ] ), 'adjust' => $adjust[ (int) $p['staff_id'] ] ?? [], 'lines' => $c['lines'], 'warnings' => $c['warnings'], 'tax_year' => $year['year'] ],
				'gross' => $c['gross'], 'taxable' => $c['taxable'], 'paye' => $c['paye'], 'uif_employee' => $c['uif_employee'], 'uif_employer' => $c['uif_employer'],
				'sdl' => $c['sdl'], 'other_deductions' => $c['other_deductions'], 'net' => $c['net'],
				'ordinary_hours' => $c['ordinary_hours'], 'overtime_hours' => $c['overtime_hours'],
			], 'payslip_calculated' );
			if ( is_wp_error( $r ) ) return $r;
		}
		self::retotal( $run_id );
		return true;
	}

	public static function payslips( int $run_id ): array {
		return WB_CCT::find( 'wb_payslips', [ 'run_id' => $run_id ], [ 'limit' => 2000, 'order' => 'ASC' ] );
	}

	private static function retotal( int $run_id ): void {
		$ps = self::payslips( $run_id );
		$t  = [ 'headcount' => count( $ps ), 'gross_total' => 0.0, 'paye_total' => 0.0, 'uif_employee_total' => 0.0, 'uif_employer_total' => 0.0, 'sdl_total' => 0.0, 'other_deductions_total' => 0.0, 'net_total' => 0.0 ];
		foreach ( $ps as $p ) {
			foreach ( [ 'gross', 'paye', 'uif_employee', 'uif_employer', 'sdl', 'other_deductions', 'net' ] as $k ) $t[ $k . '_total' ] += (float) $p[ $k ];
		}
		foreach ( $t as $k => $v ) if ( 'headcount' !== $k ) $t[ $k ] = round( $v, 2 );
		WB_CCT::update( 'wb_pay_runs', $run_id, $t, 'pay_run_totals' );
	}

	/** The adjustments already on a draft run, by person (kept through a recalculation). */
	private static function adjustments( int $run_id ): array {
		$out = [];
		foreach ( self::payslips( $run_id ) as $p ) {
			$a = (array) ( WB_CCT::json( $p['lines_json'] )['adjust'] ?? [] );
			if ( $a ) $out[ (int) $p['staff_id'] ] = $a;
		}
		return $out;
	}

	/**
	 * P4: record that the current person changed this draft run (so they cannot check it). Kept in
	 * wb_pay_runs.editors_json when that column exists (a JSON list of staff ids) AND always as a
	 * pay_run_edited ledger entry. If neither can be recorded the change is refused (fail closed).
	 *
	 * @return true|WP_Error
	 */
	private static function note_editor( int $run_id, string $what ) {
		$me = WB_Staff::current_staff_id();
		if ( ! $me ) return self::err( 'wb_no_staff', 'Your login is not linked to a staff record, so it cannot change a pay run.' );
		$stored = false;
		if ( WB_CCT::has_column( 'wb_pay_runs', 'editors_json' ) ) {
			$run = WB_CCT::get( 'wb_pay_runs', $run_id );
			$ed  = array_values( array_unique( array_map( 'intval', WB_CCT::json( $run['editors_json'] ?? '' ) ) ) );
			if ( ! in_array( $me, $ed, true ) ) {
				$ed[] = $me;
				$r    = WB_CCT::update( 'wb_pay_runs', $run_id, [ 'editors_json' => $ed ], 'pay_run_editor_added' );
				if ( is_wp_error( $r ) ) return $r;
			}
			$stored = true;
		}
		$entry = wb_ledger_write( 'pay_run_edited', 'wb_pay_runs', $run_id, null, [ 'staff_id' => $me, 'what' => $what ] );
		if ( ! $entry && ! $stored ) return self::err( 'wb_not_recorded', 'Who changed the pay run could not be recorded, so nothing was changed. Try again.' );
		return true;
	}

	/**
	 * Everyone who prepared or changed a run (P4): staff ids (preparer + editors_json) and login ids
	 * (from the ledger: who created it, changed it, adjusted it or caused its totals to change).
	 * null when the ledger cannot be read — the caller then refuses (fail closed).
	 */
	public static function run_editors( array $run ): ?array {
		$staff = array_map( 'intval', WB_CCT::json( $run['editors_json'] ?? '' ) );
		$staff[] = (int) $run['prepared_by'];
		global $wpdb;
		$t     = WB_Ledger::table();
		$users = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT actor_user_id FROM `{$t}` WHERE record_type = %s AND record_id = %d AND action IN ('pay_run_created','pay_run_edited','payslip_adjusted','pay_run_totals','pay_run_editor_added')", 'wb_pay_runs', (int) $run['_ID'] ) );
		if ( ! is_array( $users ) || '' !== (string) $wpdb->last_error ) return null;
		return [ 'staff' => array_values( array_unique( array_filter( $staff ) ) ), 'users' => array_values( array_unique( array_filter( array_map( 'intval', $users ) ) ) ) ];
	}

	/** May the current login check this run? Not its preparer, not anyone who changed it. */
	private static function is_run_editor( array $run ): bool {
		$e = self::run_editors( $run );
		if ( null === $e ) return true;   // cannot tell: treat as an editor (fail closed)
		return in_array( WB_Staff::current_staff_id(), $e['staff'], true ) || in_array( (int) get_current_user_id(), $e['users'], true );
	}

	/** @return true|WP_Error */
	public static function recalc_run( int $run_id ) {
		if ( ! current_user_can( 'wb_run_payroll' ) ) return self::err( 'wb_forbidden', 'You cannot run payroll.' );
		$run = WB_CCT::get( 'wb_pay_runs', $run_id );
		if ( ! $run || 'draft' !== $run['status'] ) return self::err( 'wb_not_draft', 'Only a draft pay run can be recalculated.' );
		$who = self::note_editor( $run_id, 'recalculated' );
		if ( is_wp_error( $who ) ) return $who;
		return self::build_payslips( $run_id, self::adjustments( $run_id ) );
	}

	/**
	 * A bonus and/or one other deduction for one person on a draft run. Recalculates the run.
	 *
	 * @return true|WP_Error
	 */
	public static function adjust( int $run_id, int $staff_id, float $bonus, string $other_label, float $other_amount, string $purpose = '' ) {
		if ( ! current_user_can( 'wb_run_payroll' ) ) return self::err( 'wb_forbidden', 'You cannot run payroll.' );
		$run = WB_CCT::get( 'wb_pay_runs', $run_id );
		if ( ! $run || 'draft' !== $run['status'] ) return self::err( 'wb_not_draft', 'Only a draft pay run can be changed.' );
		if ( $bonus < 0 || $other_amount < 0 ) return self::err( 'wb_amount', 'Amounts cannot be negative.' );
		if ( $other_amount > 0 && '' === trim( $other_label ) ) return self::err( 'wb_label', 'Say what the deduction is for — it appears on the payslip.' );
		// P14: only someone on this run can be adjusted (anyone else would be dropped silently).
		if ( ! in_array( $staff_id, array_map( fn( $p ) => (int) $p['staff_id'], self::payslips( $run_id ) ), true ) ) {
			return self::err( 'wb_not_in_run', 'That person is not on this pay run. Check the dates on their payroll profile, recalculate, then add the adjustment.' );
		}
		$who = self::note_editor( $run_id, 'adjusted' );
		if ( is_wp_error( $who ) ) return $who;
		$adj = self::adjustments( $run_id );
		$adj[ $staff_id ] = [ 'bonus' => round( $bonus, 2 ), 'other' => $other_amount > 0 ? [ [ sanitize_text_field( $other_label ), round( $other_amount, 2 ), sanitize_text_field( $purpose ) ?: 'As agreed in writing' ] ] : [] ];
		wb_ledger_write( 'payslip_adjusted', 'wb_pay_runs', $run_id, null, [ 'staff_id' => $staff_id ] + $adj[ $staff_id ] );
		return self::build_payslips( $run_id, $adj );
	}

	/** A different person checks the draft. @return true|WP_Error */
	public static function check_run( int $run_id ) {
		if ( ! current_user_can( 'wb_check_payroll' ) ) return self::err( 'wb_forbidden', 'You cannot check payroll.' );
		$run = WB_CCT::get( 'wb_pay_runs', $run_id );
		if ( ! $run || 'draft' !== $run['status'] ) return self::err( 'wb_not_draft', 'That pay run is not waiting to be checked.' );
		$me = WB_Staff::current_staff_id();
		if ( ! $me || $me === (int) $run['prepared_by'] ) return self::err( 'wb_self_check', 'Someone other than the person who prepared it must check the pay run.' );
		if ( self::is_run_editor( $run ) ) return self::err( 'wb_self_check', 'You changed this pay run (a recalculation or an adjustment), so someone else must check it.' );   // P4
		$ps = self::payslips( $run_id );
		if ( ! $ps ) return self::err( 'wb_empty', 'The pay run has no payslips.' );
		foreach ( $ps as $p ) if ( (float) $p['net'] < 0 ) return self::err( 'wb_negative', sprintf( 'The payslip for %s pays less than nothing. Fix it before checking.', self::staff_name( $p['staff_id'] ) ) );
		return WB_CCT::update( 'wb_pay_runs', $run_id, [ 'status' => 'checked', 'checked_by' => $me, 'checked_at' => current_time( 'mysql' ) ], 'pay_run_checked' );
	}

	/** Send a checked run back to draft (a problem was found). @return true|WP_Error */
	public static function reopen_run( int $run_id, string $why ) {
		if ( ! current_user_can( 'wb_check_payroll' ) && ! current_user_can( 'wb_run_payroll' ) ) return self::err( 'wb_forbidden', 'You cannot change payroll.' );
		$run = WB_CCT::get( 'wb_pay_runs', $run_id );
		if ( ! $run || 'checked' !== $run['status'] ) return self::err( 'wb_not_checked', 'Only a checked (not yet finalised) run can go back to draft.' );
		if ( '' === trim( $why ) ) return self::err( 'wb_no_note', 'Say what needs fixing.' );
		// P5: a finalise that stopped part-way has locked some payslips. Going back to draft would
		// recalculate around them and pay those people twice: finish the finalise instead.
		foreach ( self::payslips( $run_id ) as $p ) {
			if ( 'finalised' === (string) $p['status'] ) return self::err( 'wb_part_final', 'Some payslips on this run are already finalised, so it cannot go back to draft. Press Finalise again to finish it, and put any correction in the next run.' );
		}
		return WB_CCT::update( 'wb_pay_runs', $run_id, [ 'status' => 'draft', 'checked_by' => 0, 'checked_at' => '', 'notes' => sanitize_text_field( $why ) ], 'pay_run_reopened' );
	}

	/**
	 * Finalise: payslips are written out (stored privately), locked, and each person is told in the
	 * app. From here nothing on the run or its payslips can change.
	 *
	 * @return true|WP_Error
	 */
	public static function finalise_run( int $run_id ) {
		if ( ! current_user_can( 'wb_run_payroll' ) ) return self::err( 'wb_forbidden', 'You cannot finalise payroll.' );
		$run = WB_CCT::get( 'wb_pay_runs', $run_id );
		if ( ! $run || 'checked' !== $run['status'] ) return self::err( 'wb_not_checked', 'A pay run is finalised after a second person has checked it.' );
		if ( (int) $run['checked_by'] <= 0 || (int) $run['checked_by'] === (int) $run['prepared_by'] ) return self::err( 'wb_self_check', 'The pay run needs a check by someone other than the preparer.' );
		if ( in_array( (int) $run['checked_by'], array_map( 'intval', WB_CCT::json( $run['editors_json'] ?? '' ) ), true ) ) return self::err( 'wb_self_check', 'The pay run was checked by someone who also changed it. Send it back to draft for another check.' );   // P4
		$brand = WB_Setup::brand();
		foreach ( self::payslips( $run_id ) as $p ) {
			if ( "finalised" === (string) $p["status"] ) continue;   // a retry after a failure part-way: already done
			$staff = (array) WB_CCT::get( 'wb_staff', (int) $p['staff_id'] );
			$prof  = (array) WB_CCT::first( 'wb_payroll_profiles', [ 'staff_id' => (int) $p['staff_id'] ] );
			$html  = self::payslip_html( $p, $run, $staff, $prof, $brand );
			$key   = WB_Storage::put_contents( $html, 'payslips/' . $run['period'] . '/payslip-' . (int) $p['staff_id'] . '.html' );
			if ( '' === $key ) return self::err( 'wb_store_failed', 'A payslip could not be stored. Nothing was finalised past this point; try again.' );
			$r = WB_CCT::update( 'wb_payslips', (int) $p['_ID'], [ 'pdf_key' => $key, 'status' => 'finalised' ], 'payslip_finalised' );
			if ( is_wp_error( $r ) ) return $r;
		}
		$res = WB_CCT::update( 'wb_pay_runs', $run_id, [ 'status' => 'finalised', 'finalised_at' => current_time( 'mysql' ), 'finalised_by' => WB_Staff::current_staff_id() ], 'pay_run_finalised' );
		if ( is_wp_error( $res ) ) return $res;
		foreach ( self::payslips( $run_id ) as $p ) {
			$uid = WB_Staff::user_for_staff( (int) $p['staff_id'] );
			if ( $uid ) WB_Notifications::notify( $uid, 'staff', sprintf( 'Your payslip for %s is ready.', $run['period'] ), WB_Workspace::url( 'payroll' ), 'wb_payslips', (int) $p['_ID'] );
		}
		return true;
	}

	/* ---------------------------------------------------------------- outputs */

	/** The payslip page (BCEA s33 fields). Self-contained HTML, stored privately. */
	public static function payslip_html( array $p, array $run, array $staff, array $prof, array $brand ): string {
		$d     = WB_CCT::json( $p['lines_json'] );
		$c     = WB_Setup::safe_colors( (array) $brand['colors'] );   // P15: re-checked before printing
		$m     = fn( $v ) => esc_html( number_format( (float) $v, 2, '.', ' ' ) );
		$tax   = wb_dec( (string) ( $prof['tax_number_enc'] ?? '' ) );
		$logo  = WB_Setup::logo_data_uri();
		$rows  = function ( string $kind ) use ( $d, $m ) {
			$h = '';
			foreach ( (array) ( $d['lines'] ?? [] ) as $l ) {
				if ( $kind !== ( $l['kind'] ?? '' ) ) continue;
				$detail = isset( $l['hours'] ) ? esc_html( rtrim( rtrim( number_format( (float) $l['hours'], 2, '.', '' ), '0' ), '.' ) . ' h × ' . number_format( (float) $l['rate'], 2, '.', ' ' ) ) : esc_html( (string) ( $l['purpose'] ?? '' ) );
				$h     .= '<tr><td>' . esc_html( (string) $l['label'] ) . '</td><td class="d">' . $detail . '</td><td class="n">' . $m( $l['amount'] ) . '</td></tr>';
			}
			return $h;
		};
		$employer = esc_html( (string) ( $brand['legal_name'] ?: $brand['display_name'] ) );
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Payslip ' . esc_html( (string) $run['period'] ) . '</title><style>'
			. 'body{font-family:system-ui,sans-serif;color:' . esc_attr( $c['ink'] ) . ';background:#fff;margin:0;padding:24px;font-size:14px}'
			. '.top{display:flex;justify-content:space-between;gap:16px;border-bottom:3px solid ' . esc_attr( $c['primary'] ) . ';padding-bottom:12px;margin-bottom:16px}'
			. 'h1{font-size:20px;margin:0 0 4px}small,.d{color:' . esc_attr( $c['text_muted'] ) . '}table{width:100%;border-collapse:collapse;margin:0 0 16px}'
			. 'th{text-align:left;font-size:12px;color:' . esc_attr( $c['text_muted'] ) . ';border-bottom:1px solid ' . esc_attr( $c['line'] ) . ';padding:4px 0}td{padding:5px 0;border-bottom:1px solid ' . esc_attr( $c['line'] ) . ';vertical-align:top}'
			. '.n{text-align:right;white-space:nowrap}.net td{font-size:17px;font-weight:700;border-bottom:0}img{max-height:56px}</style></head><body>'
			. '<div class="top"><div>' . ( $logo ? '<img src="' . esc_attr( $logo ) . '" alt=""><br>' : '' ) . '<strong>' . $employer . '</strong><br><small>' . nl2br( esc_html( (string) $brand['physical_address'] ) ) . '</small></div>'
			. '<div><h1>Payslip</h1><small>Period ' . esc_html( (string) $run['period_start'] ) . ' to ' . esc_html( (string) $run['period_end'] ) . '<br>Paid on ' . esc_html( (string) $run['pay_date'] ) . '</small></div></div>'
			. '<table><tr><th>Employee</th><th>Occupation</th><th>Employee no.</th><th>Tax number</th></tr><tr><td>' . esc_html( trim( ( $staff['first_name'] ?? '' ) . ' ' . ( $staff['last_name'] ?? '' ) ) ) . '</td><td>' . esc_html( (string) ( $staff['job_title'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $staff['employee_no'] ?? '' ) ) . '</td><td>' . esc_html( '' !== $tax ? '******' . substr( $tax, -4 ) : '—' ) . '</td></tr></table>'
			. '<table><tr><th>Pay</th><th></th><th class="n">R</th></tr>' . $rows( 'earning' ) . '<tr><td><strong>Gross pay</strong></td><td></td><td class="n"><strong>' . $m( $p['gross'] ) . '</strong></td></tr></table>'
			. '<table><tr><th>Deductions</th><th>What it is for</th><th class="n">R</th></tr>' . $rows( 'deduction' ) . '</table>'
			. '<table class="net"><tr><td>Paid to you</td><td></td><td class="n">' . $m( $p['net'] ) . '</td></tr></table>'
			. '<table><tr><th>Paid by the employer on top of your pay</th><th></th><th class="n">R</th></tr>' . $rows( 'employer' ) . '</table>'
			. '<p><small>Basic Conditions of Employment Act, section 33. Tax year ' . esc_html( (string) ( $d['tax_year'] ?? $run['tax_year'] ) ) . '. Questions about this payslip: speak to the person who runs payroll.</small></p>'
			. '</body></html>';
	}

	/** May this user open this payslip? Their own (finalised), or payroll access. */
	public static function user_can_view_payslip( array $ps, int $uid ): bool {
		if ( $uid <= 0 ) return false;
		if ( user_can( $uid, 'wb_view_payroll' ) || user_can( $uid, 'wb_run_payroll' ) || user_can( $uid, 'wb_check_payroll' ) ) return true;
		// P10: theirs = the payslip's staff record is linked to this login, whatever the person's
		// status now (a leaver keeps their own payslips).
		return 'finalised' === (string) $ps['status'] && (int) $ps['staff_id'] > 0 && WB_Staff::user_for_staff( (int) $ps['staff_id'] ) === $uid;
	}

	/**
	 * The net-pay file for a finalised run. Refused when anyone's bank details are missing.
	 *
	 * @return string|WP_Error CSV text
	 */
	public static function bank_file( int $run_id ) {
		if ( ! current_user_can( 'wb_run_payroll' ) ) return self::err( 'wb_forbidden', 'You cannot download the payment file.' );
		$run = WB_CCT::get( 'wb_pay_runs', $run_id );
		if ( ! $run || 'finalised' !== $run['status'] ) return self::err( 'wb_not_final', 'The payment file is made from a finalised pay run.' );
		$rows    = [];
		$missing = [];
		foreach ( self::payslips( $run_id ) as $p ) {
			if ( (float) $p['net'] <= 0 ) continue;
			$prof = WB_CCT::first( 'wb_payroll_profiles', [ 'staff_id' => (int) $p['staff_id'] ] );
			$acc  = $prof ? wb_dec( (string) $prof['bank_account_enc'] ) : '';
			if ( '' === $acc || '' === (string) ( $prof['bank_branch_code'] ?? '' ) ) { $missing[] = self::staff_name( $p['staff_id'] ); continue; }
			$rows[] = [ self::staff_name( $p['staff_id'] ), $acc, (string) $prof['bank_branch_code'], (float) $p['net'], 'Salary ' . $run['period'] ];
		}
		if ( $missing ) return self::err( 'wb_no_bank', 'Bank details are missing for: ' . implode( ', ', $missing ) . '. Add them to the payroll profile; the file was not made.' );
		wb_ledger_write( 'payroll_bank_file_made', 'wb_pay_runs', $run_id, null, [ 'lines' => count( $rows ), 'total' => round( array_sum( array_column( $rows, 3 ) ), 2 ) ] );
		return self::bank_csv( $rows );
	}

	/**
	 * Monthly cash cost of payroll for the cashflow forecast: gross + employer UIF + SDL for every
	 * current profile (hourly: rate × the staff file's weekly hours, else 40, × 52 ÷ 12).
	 * null when nobody has a profile (the forecast then uses the wb_cashflow setting).
	 */
	public static function monthly_cost(): ?float {
		if ( ! WB_CCT::table( 'wb_payroll_profiles' ) ) return null;
		$today = wb_today();
		$profs = self::active_profiles( $today, $today );
		if ( ! $profs ) return null;
		$year  = self::tax_year_for( $today, self::tax_years() ) ?? ( self::DEFAULT_TAX_YEARS['2027'] + [ 'year' => '2027' ] );
		$set   = self::settings();
		$total = 0.0;
		foreach ( $profs as $p ) {
			if ( 'hourly' === $p['pay_type'] ) {
				$staff = WB_CCT::get( 'wb_staff', (int) $p['staff_id'] );
				$gross = (float) self::dec_amount( (string) $p['hourly_rate_enc'] ) * ( (float) ( $staff['hours_per_week'] ?? 0 ) ?: 40 ) * 52 / 12;
			} else {
				$gross = (float) self::dec_amount( (string) $p['salary_enc'] );
			}
			$total += $gross + self::uif( $gross, $year, wb_truthy( $p['uif_exempt'] ?? '' ) )[1] + self::sdl( $gross, $year, wb_truthy( $set['sdl_registered'] ?? '' ) );
		}
		return round( $total, 2 );
	}

	/* ---------------------------------------------------------------- panel handlers */

	private static function p( string $k, $d = '' ) {
		return isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : $d;
	}

	public static function handle_profile() {
		$r = self::save_profile( (array) wp_unslash( $_POST ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Payroll profile saved. Pay details are stored encrypted.' ];
	}

	public static function handle_run_new() {
		$r = self::create_run( sanitize_text_field( (string) self::p( 'period' ) ), (string) self::p( 'pay_date' ) );
		if ( is_wp_error( $r ) ) return $r;
		$_POST['_wb_return'] = add_query_arg( 'run', $r, (string) ( $_POST['_wb_return'] ?? '' ) );
		return [ 'msg' => 'Draft pay run calculated. Someone else must check it before it is finalised.' ];
	}

	public static function handle_run_recalc() {
		$r = self::recalc_run( absint( self::p( 'run_id' ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Recalculated from the latest timesheets, leave and profiles.' ];
	}

	public static function handle_run_check() {
		$r = self::check_run( absint( self::p( 'run_id' ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Checked. It can now be finalised.' ];
	}

	public static function handle_run_reopen() {
		$r = self::reopen_run( absint( self::p( 'run_id' ) ), (string) self::p( 'note' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Sent back to draft.' ];
	}

	public static function handle_run_finalise() {
		$r = self::finalise_run( absint( self::p( 'run_id' ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Finalised. Payslips are ready for each person; capture the EMP201 figures on eFiling yourself.' ];
	}

	public static function handle_adjust() {
		$r = self::adjust( absint( self::p( 'run_id' ) ), absint( self::p( 'staff_id' ) ), (float) self::p( 'bonus', 0 ), (string) self::p( 'other_label' ), (float) self::p( 'other_amount', 0 ), (string) self::p( 'purpose' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Adjustment saved and the run recalculated.' ];
	}

	public static function handle_settings() {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return self::err( 'wb_forbidden', 'Only Settings may change payroll settings.' );
		$mult = (float) self::p( 'overtime_multiplier', 1.5 );
		$hrs  = (float) self::p( 'overtime_weekly_hours', 45 );
		$hol  = (float) self::p( 'holiday_work_multiplier', 2 );
		if ( $mult < 1 || $mult > 3 || $hrs < 1 || $hrs > 60 ) return self::err( 'wb_bad', 'Check the overtime settings (rate 1–3 times, limit 1–60 hours a week).' );
		if ( $hol < 2 || $hol > 4 ) return self::err( 'wb_bad', 'Work on a public holiday is paid at least double (BCEA section 18): use 2 to 4 times.' );
		// Public holidays the President declared (Public Holidays Act s2A), e.g. 2022-12-27: one date per line.
		$declared = [];
		foreach ( preg_split( '/[\s,;]+/', (string) self::p( 'declared_holidays' ), -1, PREG_SPLIT_NO_EMPTY ) as $d ) {
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) return self::err( 'wb_bad', sprintf( '"%s" is not a date. Write declared public holidays as YYYY-MM-DD, one per line.', sanitize_text_field( $d ) ) );
			$declared[] = $d;
		}
		$declared = array_values( array_unique( $declared ) );
		sort( $declared );
		$before = self::settings();
		$after  = [ 'sdl_registered' => 'yes' === self::p( 'sdl_registered' ) ? 1 : 0, 'overtime_multiplier' => $mult, 'overtime_weekly_hours' => $hrs, 'configured' => 1, 'holiday_work_multiplier' => $hol ];
		update_option( 'wb_payroll', $after );
		wb_ledger_write( 'payroll_settings_saved', 'wp_options', 0, $before, $after );
		$was = array_values( (array) get_option( 'wb_declared_holidays', [] ) );
		if ( $was !== $declared ) {
			update_option( 'wb_declared_holidays', $declared, false );
			wb_ledger_write( 'declared_holidays_saved', 'wp_options', 0, [ 'wb_declared_holidays' => $was ], [ 'wb_declared_holidays' => $declared ] );
		}
		return [ 'msg' => 'Payroll settings saved.' ];
	}

	/* ---------------------------------------------------------------- screen */

	public static function private_url( string $kind, int $id ): string {
		return add_query_arg( [ 'kind' => $kind, 'id' => $id, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'wb/v1/private' ) );
	}

	private static function fold( string $title, string $body, bool $open = false ): string {
		return '<details class="wb-fold"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( $title ) . '</summary><div class="wb-fold-body">' . $body . '</div></details>';
	}

	public static function screen( $atts = [] ): string {
		if ( ! is_user_logged_in() ) return wb_notice( 'warn', 'Please sign in.' );
		if ( ! current_user_can( 'wb_access_workspace' ) ) return wb_notice( 'warn', 'This page is not part of your work.' );
		$h  = WB_RowActions::notice();
		$mine_ids = WB_Staff::staff_ids_for_user( (int) get_current_user_id() );   // P10: a leaver still sees their own payslips
		if ( $mine_ids ) {
			$mine = WB_CCT::find( 'wb_payslips', [ 'staff_id' => $mine_ids, 'status' => 'finalised' ], [ 'limit' => 36, 'orderby' => 'period' ] );
			$h   .= self::fold( 'My payslips', WB_Render::render_table( $mine, [ 'period', [ 'key' => 'gross', 'label' => 'Gross', 'type' => 'money' ], [ 'key' => 'net', 'label' => 'Paid to you', 'type' => 'money' ] ],
				[ 'action_html' => fn( $p ) => WB_RowActions::menuitem( 'download', 'Open payslip', [ 'href' => self::private_url( 'payslip', (int) $p['_ID'] ) ] ), 'empty' => 'No payslips yet.' ] ), true );
		}
		if ( ! self::may_view() ) return $h;

		$rid = absint( $_GET['run'] ?? 0 );
		if ( $rid && ( $run = WB_CCT::get( 'wb_pay_runs', $rid ) ) ) $h .= self::run_detail( $run );

		$runs = WB_CCT::find( 'wb_pay_runs', [], [ 'limit' => 60, 'orderby' => 'period' ] );
		$h   .= '<h3>Pay runs</h3>' . WB_Render::render_table( $runs, [ [ 'key' => 'period', 'render' => fn( $v, $r ) => '<a href="' . esc_url( add_query_arg( 'run', (int) $r['_ID'] ) ) . '">' . esc_html( (string) $v ) . '</a>' ], 'pay_date', 'status', 'headcount',
			[ 'key' => 'gross_total', 'label' => 'Gross', 'type' => 'money' ], [ 'key' => 'paye_total', 'label' => 'PAYE', 'type' => 'money' ], [ 'key' => 'net_total', 'label' => 'Net', 'type' => 'money' ],
			[ 'key' => 'prepared_by', 'label' => 'Prepared by', 'render' => fn( $v ) => esc_html( self::staff_name( $v ) ) ], [ 'key' => 'checked_by', 'label' => 'Checked by', 'render' => fn( $v ) => (int) $v ? esc_html( self::staff_name( $v ) ) : '' ] ],
			[ 'empty' => 'No pay runs yet.' ] );

		if ( current_user_can( 'wb_run_payroll' ) ) {
			$h .= self::fold( 'Start a pay run', WB_Render::form_open( 'payroll_run_new' ) . WB_Render::field( 'period', 'Month', 'month', current_time( 'Y-m' ), [ 'required' => true ] )
				. WB_Render::field( 'pay_date', 'Pay date', 'date', current_time( 'Y-m-25' ), [ 'required' => true, 'note' => 'The tax year is chosen from this date.' ] ) . WB_Render::form_close( 'Calculate draft' ) );

			$profs = WB_CCT::find( 'wb_payroll_profiles', [], [ 'limit' => 2000 ] );
			// P1: ?profile=<staff id> fills the form below from that person's stored profile (never the
			// encrypted fields: those stay blank, and blank keeps what is on file).
			$pf    = [];
			$pf_id = absint( $_GET['profile'] ?? 0 );
			if ( $pf_id ) $pf = (array) WB_CCT::first( 'wb_payroll_profiles', [ 'staff_id' => $pf_id ] );
			$pv    = fn( string $k, $d = '' ) => $pf && isset( $pf[ $k ] ) && '' !== (string) $pf[ $k ] ? $pf[ $k ] : $d;
			$body  = WB_Render::render_table( $profs, [ [ 'key' => 'staff_id', 'render' => fn( $v ) => '<a href="' . esc_url( add_query_arg( 'profile', (int) $v ) ) . '#wb-staff_id">' . esc_html( self::staff_name( $v ) ) . '</a>' ], 'pay_type',
				[ 'key' => 'salary_enc', 'label' => 'Pay', 'render' => function ( $v, $r ) {
					$a = 'hourly' === $r['pay_type'] ? self::dec_amount( (string) $r['hourly_rate_enc'] ) : self::dec_amount( (string) $v );
					return null === $a ? '<span class="wb-muted">cannot read</span>' : esc_html( WB_Render::money( $a ) . ( 'hourly' === $r['pay_type'] ? ' an hour' : ' a month' ) );
				} ],
				[ 'key' => 'bank_account_enc', 'label' => 'Bank', 'render' => fn( $v ) => '' !== (string) $v ? 'on file' : '<span class="wb-muted">missing</span>' ],
				'medical_scheme_members', 'start_date', 'end_date' ], [ 'empty' => 'No payroll profiles yet.' ] );
			$body .= ( $pf ? '<p class="wb-muted">Editing the profile of ' . esc_html( self::staff_name( $pf_id ) ) . '. Any field left blank keeps what is on file.</p>' : '<p class="wb-muted">Choose a name in the table to edit that profile. On an edit, any field left blank keeps what is on file.</p>' )
				. WB_Render::form_open( 'payroll_profile' ) . WB_Render::field( 'staff_id', 'Person', 'select', $pf ? $pf_id : '', [ 'options' => WB_Render::options( 'wb_staff', 'first_name' ), 'required' => true ] )
				. WB_Render::field( 'pay_type', 'Paid', 'select', $pv( 'pay_type', 'monthly' ), [ 'options' => [ 'monthly' => 'A monthly salary', 'hourly' => 'By the hour (from approved timesheets)' ] ] )
				. WB_Render::field( 'salary', 'Monthly salary', 'number', '', [ 'note' => 'Leave blank on an edit to keep what is on file.' ] ) . WB_Render::field( 'hourly_rate', 'Rate per hour', 'number', '', [ 'note' => 'Leave blank on an edit to keep what is on file.' ] )
				. WB_Render::field( 'tax_number', 'SARS tax number', 'text', '', [ 'note' => 'Stored encrypted. Blank keeps what is on file.' ] )
				. WB_Render::field( 'bank_account', 'Bank account number', 'text', '', [ 'note' => 'Stored encrypted. Blank keeps what is on file.' ] ) . WB_Render::field( 'bank_branch_code', 'Branch code', 'text', $pv( 'bank_branch_code' ) )
				. WB_Render::field( 'date_of_birth', 'Date of birth', 'date', $pv( 'date_of_birth' ), [ 'note' => 'For the age rebates (65 and 75).' ] )
				. WB_Render::field( 'medical_scheme_members', 'People on the medical scheme', 'number', $pv( 'medical_scheme_members', 0 ), [ 'note' => '0 none · 1 the member only · 2 member and one dependant …' ] )
				. WB_Render::field( 'retirement_contribution_pct', 'Retirement fund: % of pay', 'number', $pv( 'retirement_contribution_pct', 0 ) ) . WB_Render::field( 'retirement_contribution_fixed', 'or a fixed amount a month', 'number', $pv( 'retirement_contribution_fixed', 0 ) )
				. WB_Render::field( 'uif_exempt', 'Exempt from UIF?', 'select', $pv( 'uif_exempt', 'no' ), [ 'options' => [ 'no' => 'No', 'yes' => 'Yes' ] ] )
				. WB_Render::field( 'start_date', 'Pay starts', 'date', $pv( 'start_date' ), [ 'required' => true ] ) . WB_Render::field( 'end_date', 'Pay ends (leaving)', 'date', $pv( 'end_date' ) )
				. ( $pf && '' !== (string) ( $pf['end_date'] ?? '' ) ? WB_Render::field( 'end_date_clear', 'Remove the leaving date?', 'select', 'no', [ 'options' => [ 'no' => 'No, keep it', 'yes' => 'Yes, they are staying on' ] ] ) : '' )
				. WB_Render::form_close( 'Save profile' );
			$h .= self::fold( 'Payroll profiles', $body );
		}

		$years = [];
		foreach ( self::tax_years() as $k => $y ) $years[] = [ 'year' => $k, 'from' => $y['from'] ?? '', 'to' => $y['to'] ?? '', 'primary' => $y['rebates']['primary'] ?? '', 'uif_ceiling' => $y['uif']['ceiling_monthly'] ?? '', 'source' => $y['source'] ?? '' ];
		$h .= self::fold( 'Tax years on file', '<p class="wb-muted">Payroll refuses a pay date outside these years. A new year is added each March after the Budget.</p>' . WB_Render::render_table( $years, [ 'year', 'from', 'to', [ 'key' => 'primary', 'label' => 'Primary rebate' ], [ 'key' => 'uif_ceiling', 'label' => 'UIF ceiling a month' ], 'source' ] ) );

		if ( current_user_can( 'wb_manage_settings' ) ) {
			$s  = self::settings();
			$h .= self::fold( 'Payroll settings', WB_Render::form_open( 'payroll_settings' )
				. WB_Render::field( 'sdl_registered', 'Registered for the skills levy (SDL)?', 'select', $s['sdl_registered'] ? 'yes' : 'no', [ 'options' => [ 'no' => 'No — total payroll is R500 000 a year or less', 'yes' => 'Yes' ] ] )
				. WB_Render::field( 'overtime_multiplier', 'Overtime is paid at (times the hourly rate)', 'number', $s['overtime_multiplier'] )
				. WB_Render::field( 'overtime_weekly_hours', 'Overtime starts after (hours a week)', 'number', $s['overtime_weekly_hours'] )
				. WB_Render::field( 'holiday_work_multiplier', 'Work on a public holiday is paid at (times the hourly rate)', 'number', $s['holiday_work_multiplier'], [ 'note' => 'At least 2 (Basic Conditions of Employment Act, section 18).' ] )
				. WB_Render::field( 'declared_holidays', 'Extra public holidays declared by the President', 'textarea', implode( "\n", array_map( 'strval', (array) get_option( 'wb_declared_holidays', [] ) ) ), [ 'rows' => 3, 'placeholder' => '2022-12-27', 'note' => 'One date per line (YYYY-MM-DD). The Act\'s own holidays, and the Monday after a Sunday holiday, are already counted.' ] )
				. WB_Render::form_close( 'Save payroll settings' ) );
		}
		return $h;
	}

	private static function run_detail( array $run ): string {
		$rid  = (int) $run['_ID'];
		$ps   = self::payslips( $rid );
		$body = '<p>' . WB_Render::chip( $run['status'] ) . ' · paid on ' . esc_html( (string) $run['pay_date'] ) . ' · tax year ' . esc_html( (string) $run['tax_year'] )
			. ' · prepared by ' . esc_html( self::staff_name( $run['prepared_by'] ) ) . ( (int) $run['checked_by'] ? ' · checked by ' . esc_html( self::staff_name( $run['checked_by'] ) ) : '' ) . '</p>';
		if ( '' !== (string) ( $run['notes'] ?? '' ) ) $body .= wb_notice( 'warn', esc_html( 'Note: ' . $run['notes'] ) );
		$body .= WB_Render::render_table( $ps, [ [ 'key' => 'staff_id', 'render' => fn( $v ) => esc_html( self::staff_name( $v ) ) ], [ 'key' => 'gross', 'label' => 'Gross', 'type' => 'money' ], [ 'key' => 'paye', 'label' => 'PAYE', 'type' => 'money' ],
			[ 'key' => 'uif_employee', 'label' => 'UIF', 'type' => 'money' ], [ 'key' => 'other_deductions', 'label' => 'Other', 'type' => 'money' ], [ 'key' => 'net', 'label' => 'Net', 'type' => 'money' ],
			[ 'key' => 'lines_json', 'label' => 'Check', 'render' => fn( $v ) => esc_html( implode( ' ', (array) ( WB_CCT::json( $v )['warnings'] ?? [] ) ) ) ] ],
			[ 'action_html' => fn( $p ) => WB_RowActions::menuitem( 'download', 'Open payslip', [ 'href' => self::private_url( 'payslip', (int) $p['_ID'] ) ] ), 'empty' => 'No payslips.' ] );
		$e = self::emp201( $ps );
		$body .= '<h4>EMP201 figures</h4><p class="wb-muted">Capture these on SARS eFiling yourself. The system never submits anything.</p>'
			. WB_Render::render_table( [ [ 'what' => 'PAYE', 'amount' => $e['paye'] ], [ 'what' => 'UIF (employee + employer)', 'amount' => $e['uif'] ], [ 'what' => 'SDL', 'amount' => $e['sdl'] ], [ 'what' => 'Total to pay SARS', 'amount' => $e['total'] ] ], [ 'what', [ 'key' => 'amount', 'type' => 'money' ] ], [ 'cards' => false ] );
		$hidden = '<input type="hidden" name="run_id" value="' . $rid . '">';
		if ( 'draft' === $run['status'] ) {
			if ( current_user_can( 'wb_run_payroll' ) ) {
				$people = [ '' => '— choose —' ];
				foreach ( $ps as $p ) $people[ (int) $p['staff_id'] ] = self::staff_name( $p['staff_id'] );
				$body .= WB_Render::form_open( 'payroll_run_recalc' ) . $hidden . WB_Render::form_close( 'Recalculate' );
				$body .= WB_Render::form_open( 'payroll_adjust' ) . $hidden . WB_Render::field( 'staff_id', 'Person', 'select', '', [ 'options' => $people ] ) . WB_Render::field( 'bonus', 'Bonus this month', 'number', 0 )
					. WB_Render::field( 'other_label', 'Other deduction (what)', 'text' ) . WB_Render::field( 'other_amount', 'Amount', 'number', 0 ) . WB_Render::field( 'purpose', 'What it is for (on the payslip)', 'text' ) . WB_Render::form_close( 'Save adjustment' );
			}
			if ( current_user_can( 'wb_check_payroll' ) && WB_Staff::current_staff_id() !== (int) $run['prepared_by'] && ! self::is_run_editor( $run ) ) $body .= WB_Render::form_open( 'payroll_run_check' ) . $hidden . WB_Render::form_close( 'I have checked this pay run', 'Mark this pay run as checked by you?' );
		} elseif ( 'checked' === $run['status'] ) {
			if ( current_user_can( 'wb_run_payroll' ) ) $body .= WB_Render::form_open( 'payroll_run_finalise' ) . $hidden . WB_Render::form_close( 'Finalise', 'Finalise? Payslips can never be changed after this.' );
			$body .= WB_Render::form_open( 'payroll_run_reopen' ) . $hidden . WB_Render::field( 'note', 'What needs fixing', 'text', '', [ 'required' => true ] ) . WB_Render::form_close( 'Send back to draft' );
		} elseif ( 'finalised' === $run['status'] && current_user_can( 'wb_run_payroll' ) ) {
			$body .= '<p><a class="wb-btn" href="' . esc_url( add_query_arg( [ 'run' => $rid, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'wb/v1/payroll-bank-file' ) ) ) . '">Download the net-pay file for the bank (CSV)</a></p><p class="wb-muted">Upload it to the bank yourself. The system pays nobody.</p>';
		}
		return self::fold( 'Pay run ' . $run['period'], $body, true );
	}
}
