<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Staff — timesheets, leave, KPIs and performance reviews.
 *
 * - Timesheets: one row per person per day; hours are COMPUTED from start, end and break
 *   (never typed). draft → submitted → approved | queried. The approver is never the owner.
 * - Leave: days = working days between the dates (weekends and SA public holidays excluded).
 *   Balance = entitlement accrued − approved days, computed every time, never stored as a typed
 *   number. Defaults follow the SA Basic Conditions of Employment Act: 15 working days annual
 *   leave per year (accrued monthly), 30 days sick leave per 36-month cycle (1 day per 26 days
 *   worked in the first 6 months), 3 days family responsibility per year after 4 months for people working 4+ days a week —
 *   editable per tenant (wb_leave_types). The approver is never the person on leave.
 * - KPIs measured from the engines' own records where the measure allows (source = auto).
 * - Reviews: scheduled → self_review → manager_review → discussed → signed (both signatures).
 *
 * The arithmetic is PURE static functions (hours, working_days, sa_public_holidays,
 * leave_balance, the kpi_* rates, review_can_move) — testable without WordPress.
 * People without the Staff dashboard act only on their OWN record (wp_user_id = them).
 */
class WB_Staff {

	/** wb_leave_types defaults (BCEA). days_per_year means days per cycle when cycle_months ≠ 12. */
	const DEFAULT_LEAVE = [
		[ 'code' => 'annual', 'name' => 'Annual leave', 'days_per_year' => 15, 'cycle_months' => 12, 'accrual' => 'monthly', 'carry_over_max' => 5 ],
		[ 'code' => 'sick', 'name' => 'Sick leave', 'days_per_year' => 30, 'cycle_months' => 36, 'accrual' => 'upfront', 'carry_over_max' => 0 ],
		[ 'code' => 'family', 'name' => 'Family responsibility leave', 'days_per_year' => 3, 'cycle_months' => 12, 'accrual' => 'upfront', 'carry_over_max' => 0 ],
		[ 'code' => 'unpaid', 'name' => 'Unpaid leave', 'days_per_year' => 0, 'cycle_months' => 12, 'accrual' => 'upfront', 'carry_over_max' => 0 ],
		[ 'code' => 'study', 'name' => 'Study leave', 'days_per_year' => 0, 'cycle_months' => 12, 'accrual' => 'upfront', 'carry_over_max' => 0 ],
	];

	const REVIEW_FLOW = [
		'scheduled'      => [ 'self_review' ],
		'self_review'    => [ 'manager_review' ],
		'manager_review' => [ 'discussed' ],
		'discussed'      => [ 'signed' ],
		'signed'         => [],
	];

	const KPI_AUTO = [ 'quotes_sent', 'quote_win_rate', 'revenue', 'on_time_delivery', 'stock_accuracy', 'timesheet_compliance' ];

	private static $staff_cache = [];

	/* ================================================================== pure: time */

	/** "08:00", "17:30", 30 → 9.0. Past midnight wraps ("22:00"→"06:00" = 8h). */
	public static function hours( string $start, string $end, int $break_minutes = 0 ): float {
		$m = function ( string $t ): ?int {
			if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', trim( $t ), $x ) || (int) $x[1] > 23 || (int) $x[2] > 59 ) return null;
			return (int) $x[1] * 60 + (int) $x[2];
		};
		$a = $m( $start );
		$b = $m( $end );
		if ( null === $a || null === $b ) return 0.0;
		$mins = $b - $a;
		if ( $mins <= 0 ) $mins += 1440;
		return round( max( 0, $mins - max( 0, $break_minutes ) ) / 60, 2 );
	}

	/** Easter Sunday (anonymous Gregorian algorithm — no calendar extension needed). */
	public static function easter_sunday( int $y ): string {
		$a = $y % 19; $b = intdiv( $y, 100 ); $c = $y % 100; $d = intdiv( $b, 4 ); $e = $b % 4;
		$f = intdiv( $b + 8, 25 ); $g = intdiv( $b - $f + 1, 3 ); $h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 ); $k = $c % 4; $l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;
		return sprintf( '%04d-%02d-%02d', $y, $month, $day );
	}

	/**
	 * South African public holidays (Public Holidays Act 36 of 1994): the ten fixed days, Good
	 * Friday and Family Day; a holiday on a Sunday makes the Monday a holiday.
	 *
	 * Checked against the Act (review P12, 5 October 2026): section 2(1) says that whenever a public
	 * holiday falls on a Sunday, the following Monday is a public holiday. It does NOT cascade: when
	 * 25 December is a Sunday, the Monday (26 December) is already the Day of Goodwill and the Act
	 * adds no further day. Extra days such as Tuesday 27 December 2022 were DECLARED by the President
	 * under section 2A, one at a time. Those are not in this pure list — they are added by
	 * public_holidays() from the wb_declared_holidays option (Payroll settings) and the
	 * wb_public_holidays filter.
	 */
	public static function sa_public_holidays( int $y ): array {
		$fixed = [ '01-01', '03-21', '04-27', '05-01', '06-16', '08-09', '09-24', '12-16', '12-25', '12-26' ];
		$out   = [];
		foreach ( $fixed as $md ) $out[] = $y . '-' . $md;
		$easter = strtotime( self::easter_sunday( $y ) . ' 12:00:00 UTC' );
		$out[]  = gmdate( 'Y-m-d', $easter - 2 * 86400 );   // Good Friday
		$out[]  = gmdate( 'Y-m-d', $easter + 86400 );       // Family Day
		foreach ( $out as $d ) {
			$ts = strtotime( $d . ' 12:00:00 UTC' );
			if ( '0' === gmdate( 'w', $ts ) ) $out[] = gmdate( 'Y-m-d', $ts + 86400 );
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * Public holidays for a year as the system uses them: the Act's days (sa_public_holidays) plus
	 * any day the President declared (option wb_declared_holidays, a list of Y-m-d, edited on the
	 * Payroll settings), then the wb_public_holidays filter ( $days, $year ). Without WordPress
	 * (the self-test) it is just the Act's days.
	 */
	public static function public_holidays( int $y ): array {
		$out = self::sa_public_holidays( $y );
		if ( function_exists( 'get_option' ) ) {
			foreach ( (array) get_option( 'wb_declared_holidays', [] ) as $d ) {
				$d = substr( (string) $d, 0, 10 );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && (int) substr( $d, 0, 4 ) === $y ) $out[] = $d;
			}
		}
		if ( function_exists( 'apply_filters' ) ) $out = (array) apply_filters( 'wb_public_holidays', $out, $y );
		$out = array_values( array_unique( array_map( 'strval', $out ) ) );
		sort( $out );
		return $out;
	}

	/** Working days from $from to $to inclusive. 5-day week = Mon–Fri; 6 = Mon–Sat. Holidays excluded. */
	public static function working_days( string $from, string $to, ?array $holidays = null, int $days_per_week = 5 ): int {
		$a = strtotime( substr( $from, 0, 10 ) . ' 12:00:00 UTC' );
		$b = strtotime( substr( $to, 0, 10 ) . ' 12:00:00 UTC' );
		if ( ! $a || ! $b || $b < $a ) return 0;
		if ( null === $holidays ) {
			$holidays = [];
			for ( $y = (int) gmdate( 'Y', $a ); $y <= (int) gmdate( 'Y', $b ); $y++ ) $holidays = array_merge( $holidays, self::public_holidays( $y ) );
		}
		$hol  = array_flip( $holidays );
		$last = $days_per_week >= 6 ? 6 : 5;   // ISO day numbers 1..5 or 1..6
		$n    = 0;
		for ( $t = $a; $t <= $b; $t += 86400 ) {
			$dow = (int) gmdate( 'N', $t );
			if ( $dow > $last ) continue;
			if ( isset( $hol[ gmdate( 'Y-m-d', $t ) ] ) ) continue;
			$n++;
		}
		return $n;
	}

	/** Whole months from $a to $b (Y-m-d). */
	public static function months_between( string $a, string $b ): int {
		[ $y1, $m1, $d1 ] = array_map( 'intval', explode( '-', substr( $a, 0, 10 ) ) );
		[ $y2, $m2, $d2 ] = array_map( 'intval', explode( '-', substr( $b, 0, 10 ) ) );
		$m = ( $y2 - $y1 ) * 12 + ( $m2 - $m1 );
		if ( $d2 < $d1 ) $m--;
		return max( 0, $m );
	}

	private static function add_months( string $ymd, int $months ): string {
		$d = new DateTimeImmutable( substr( $ymd, 0, 10 ) );
		return $d->modify( ( $months >= 0 ? '+' : '' ) . $months . ' months' )->format( 'Y-m-d' );
	}

	/* ================================================================== pure: leave */

	/**
	 * The leave balance for one type, as of a date.
	 * $type: code, days_per_year (per cycle), cycle_months, accrual (monthly|upfront), carry_over_max.
	 * $approved: the person's approved leave of this type, [ [from_date, days], … ].
	 * Returns cycle_start, cycle_end, accrued, carried, taken, balance (null balance = no limit: unpaid/study).
	 */
	public static function leave_balance( array $type, string $started_at, string $as_of, array $approved, int $days_per_week = 5 ): array {
		$code    = (string) ( $type['code'] ?? '' );
		$per     = (float) ( $type['days_per_year'] ?? 0 );
		$cm      = max( 1, (int) ( $type['cycle_months'] ?? 12 ) );
		$months  = self::months_between( $started_at, $as_of );
		$k       = intdiv( $months, $cm );
		$cstart  = self::add_months( $started_at, $k * $cm );
		$cend    = gmdate( 'Y-m-d', strtotime( self::add_months( $cstart, $cm ) . ' 12:00:00 UTC' ) - 86400 );
		$taken_in = function ( string $from, string $to ) use ( $approved ): float {
			$t = 0.0;
			foreach ( $approved as $a ) if ( substr( (string) $a[0], 0, 10 ) >= $from && substr( (string) $a[0], 0, 10 ) <= $to ) $t += (float) $a[1];
			return $t;
		};
		$taken = $taken_in( $cstart, $cend );
		if ( $per <= 0 ) return [ 'cycle_start' => $cstart, 'cycle_end' => $cend, 'accrued' => 0.0, 'carried' => 0.0, 'taken' => $taken, 'balance' => null ];

		if ( 'sick' === $code && $months < 6 ) {
			// BCEA s22(3): in the first six months, one day's paid sick leave for every 26 days worked.
			$accrued = (float) intdiv( self::working_days( $started_at, $as_of, null, $days_per_week ), 26 );
		} elseif ( 'family' === $code && ( $months < 4 || $days_per_week < 4 ) ) {
			$accrued = 0.0;   // BCEA s27(1): employed for longer than four months AND working at least four days a week
		} elseif ( 'monthly' === (string) ( $type['accrual'] ?? 'upfront' ) ) {
			$accrued = round( min( $per, $per / $cm * ( $months - $k * $cm ) ), 2 );
		} else {
			$accrued = $per;
		}
		$carried = 0.0;
		$cap     = (float) ( $type['carry_over_max'] ?? 0 );
		if ( $cap > 0 && $k > 0 ) {
			$pstart  = self::add_months( $started_at, ( $k - 1 ) * $cm );
			$pend    = gmdate( 'Y-m-d', strtotime( $cstart . ' 12:00:00 UTC' ) - 86400 );
			$carried = min( $cap, max( 0.0, $per - $taken_in( $pstart, $pend ) ) );
		}
		return [ 'cycle_start' => $cstart, 'cycle_end' => $cend, 'accrued' => $accrued, 'carried' => $carried, 'taken' => $taken, 'balance' => round( $accrued + $carried - $taken, 2 ) ];
	}

	/* ================================================================== pure: KPIs and reviews */

	public static function kpi_win_rate( int $sent, int $accepted ): float {
		return $sent > 0 ? round( $accepted / $sent * 100, 1 ) : 0.0;
	}

	/** [ [required_by Y-m-d|'' , delivered Y-m-d], … ] → % on or before required_by (rows without a date count as on time). */
	public static function kpi_on_time( array $rows ): float {
		if ( ! $rows ) return 0.0;
		$ok = 0;
		foreach ( $rows as $r ) if ( '' === (string) $r[0] || substr( (string) $r[1], 0, 10 ) <= substr( (string) $r[0], 0, 10 ) ) $ok++;
		return round( $ok / count( $rows ) * 100, 1 );
	}

	/** Count lines [ [system, counted], … ] → % of lines that matched exactly. */
	public static function kpi_stock_accuracy( array $lines ): float {
		if ( ! $lines ) return 0.0;
		$ok = 0;
		foreach ( $lines as $l ) if ( abs( (float) $l[0] - (float) $l[1] ) < 0.0001 ) $ok++;
		return round( $ok / count( $lines ) * 100, 1 );
	}

	public static function kpi_timesheet_compliance( int $expected_days, int $submitted_days ): float {
		return $expected_days > 0 ? round( min( 1, $submitted_days / $expected_days ) * 100, 1 ) : 100.0;
	}

	public static function review_can_move( string $from, string $to ): bool {
		return in_array( $to, self::REVIEW_FLOW[ $from ] ?? [], true );
	}

	/* ================================================================== who is who */

	/** The current login's wb_staff _ID, or 0. */
	public static function current_staff_id(): int {
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return $uid ? self::staff_for_user( $uid ) : 0;
	}

	public static function staff_for_user( int $uid ): int {
		if ( isset( self::$staff_cache[ $uid ] ) ) return self::$staff_cache[ $uid ];
		$row = WB_CCT::first( 'wb_staff', [ 'wp_user_id' => $uid, 'status' => [ 'active', '' ] ] );
		return self::$staff_cache[ $uid ] = $row ? (int) $row['_ID'] : 0;
	}

	/**
	 * Every wb_staff _ID linked to this login, whatever the person's status (a leaver keeps access
	 * to their own payslips). Use staff_for_user() for "may act now" — this is for "is it theirs".
	 */
	public static function staff_ids_for_user( int $uid ): array {
		if ( $uid <= 0 ) return [];
		return array_values( array_unique( array_map( fn( $r ) => (int) $r['_ID'], WB_CCT::find( 'wb_staff', [ 'wp_user_id' => $uid ], [ 'limit' => 20, 'active_only' => false ] ) ) ) );
	}

	public static function user_for_staff( int $staff_id ): int {
		$row = WB_CCT::get( 'wb_staff', $staff_id );
		return $row ? (int) $row['wp_user_id'] : 0;
	}

	/** May the current user act on this staff member's own records? Self, or the Staff dashboard. */
	private static function may_act_for( int $staff_id ): bool {
		return ( $staff_id > 0 && self::current_staff_id() === $staff_id ) || current_user_can( 'wb_manage_staff' );
	}

	/* ================================================================== timesheets */

	/**
	 * Save a day (create or edit while draft / queried). Hours are computed.
	 *
	 * @return int|WP_Error timesheet id
	 */
	public static function save_timesheet( string $work_date, string $start, string $end, int $break_minutes, string $activity, int $customer_id = 0, int $staff_id = 0 ) {
		$staff_id = $staff_id ?: self::current_staff_id();
		if ( ! $staff_id ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record.' );
		if ( ! self::may_act_for( $staff_id ) ) return new WP_Error( 'wb_forbidden', 'You can only fill in your own timesheet.' );
		if ( ! in_array( $activity, [ 'sales', 'warehouse', 'admin', 'delivery', 'leave' ], true ) ) return new WP_Error( 'wb_activity', 'Choose what the time was spent on.' );
		$date = substr( sanitize_text_field( $work_date ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date > wb_today() ) return new WP_Error( 'wb_date', 'Choose a day that has happened.' );
		$hours = self::hours( $start, $end, $break_minutes );
		if ( $hours <= 0 || $hours > 16 ) return new WP_Error( 'wb_hours', 'Check the start and end times.' );
		$data = [ 'staff_id' => $staff_id, 'work_date' => $date, 'start' => $start, 'end' => $end, 'break_minutes' => max( 0, $break_minutes ), 'hours' => $hours, 'activity' => $activity, 'customer_id' => $customer_id, 'status' => 'draft' ];
		$have = WB_CCT::first( 'wb_timesheets', [ 'staff_id' => $staff_id, 'work_date' => $date ] );
		if ( $have ) {
			if ( ! in_array( $have['status'], [ 'draft', 'queried' ], true ) ) return new WP_Error( 'wb_locked', 'That day is already submitted.' );
			$r = WB_CCT::update( 'wb_timesheets', (int) $have['_ID'], $data, 'timesheet_saved' );
			return is_wp_error( $r ) ? $r : (int) $have['_ID'];
		}
		return WB_CCT::insert( 'wb_timesheets', $data, 'timesheet_saved' );
	}

	/** @return true|WP_Error */
	public static function submit_timesheet( int $id ) {
		$t = WB_CCT::get( 'wb_timesheets', $id );
		if ( ! $t || ! in_array( $t['status'], [ 'draft', 'queried' ], true ) ) return new WP_Error( 'wb_locked', 'That day cannot be submitted.' );
		if ( ! self::may_act_for( (int) $t['staff_id'] ) ) return new WP_Error( 'wb_forbidden', 'You can only submit your own timesheet.' );
		return WB_CCT::update( 'wb_timesheets', $id, [ 'status' => 'submitted' ], 'timesheet_submitted' );
	}

	/** Approve or query. Needs wb_approve_timesheets; never your own. @return true|WP_Error */
	public static function decide_timesheet( int $id, bool $approve, string $query_note = '' ) {
		if ( ! current_user_can( 'wb_approve_timesheets' ) ) return new WP_Error( 'wb_forbidden', 'You cannot approve timesheets.' );
		$t = WB_CCT::get( 'wb_timesheets', $id );
		if ( ! $t || 'submitted' !== $t['status'] ) return new WP_Error( 'wb_not_submitted', 'That day is not waiting for approval.' );
		$me = self::current_staff_id();
		if ( ! $me || $me === (int) $t['staff_id'] ) return new WP_Error( 'wb_self_approval', 'Someone else must approve your own timesheet.' );
		if ( ! $approve && '' === trim( $query_note ) ) return new WP_Error( 'wb_no_note', 'Say what needs checking.' );
		$res = WB_CCT::update( 'wb_timesheets', $id, $approve ? [ 'status' => 'approved', 'approved_by_staff_id' => $me ] : [ 'status' => 'queried', 'query_note' => sanitize_text_field( $query_note ) ], $approve ? 'timesheet_approved' : 'timesheet_queried' );
		$uid = self::user_for_staff( (int) $t['staff_id'] );
		if ( true === $res && ! $approve && $uid ) WB_Notifications::notify( $uid, 'staff', sprintf( 'Your timesheet for %s was queried: %s', $t['work_date'], $query_note ), home_url( '/workspace/staff/' ), 'wb_timesheets', $id );
		return $res;
	}

	/* ================================================================== leave */

	/** Default BCEA leave types into an empty wb_leave_types table. Idempotent. */
	public static function ensure_leave_types(): void {
		if ( ! WB_CCT::table( 'wb_leave_types' ) || WB_CCT::count( 'wb_leave_types', [], false ) ) return;
		foreach ( (array) get_option( 'wb_leave_defaults', self::DEFAULT_LEAVE ) as $t ) WB_CCT::insert( 'wb_leave_types', (array) $t, 'leave_type_seeded' );
	}

	private static function type_of( array $row ): array {
		$code = (string) ( $row['code'] ?? '' );
		if ( '' === $code ) {   // a tenant-made type without a code: recognise the BCEA ones by name
			$n    = strtolower( (string) ( $row['name'] ?? '' ) );
			$code = false !== strpos( $n, 'sick' ) ? 'sick' : ( false !== strpos( $n, 'family' ) ? 'family' : ( false !== strpos( $n, 'annual' ) ? 'annual' : sanitize_key( $n ) ) );
		}
		return [ 'code' => $code, 'days_per_year' => (float) $row['days_per_year'], 'cycle_months' => (int) ( $row['cycle_months'] ?? 0 ) ?: ( 'sick' === $code ? 36 : 12 ), 'accrual' => (string) $row['accrual'], 'carry_over_max' => (float) $row['carry_over_max'] ];
	}

	/** @return array|WP_Error the leave_balance() array */
	public static function balance( int $staff_id, int $leave_type_id, string $as_of = '' ) {
		$staff = WB_CCT::get( 'wb_staff', $staff_id );
		$type  = WB_CCT::get( 'wb_leave_types', $leave_type_id );
		if ( ! $staff || ! $type ) return new WP_Error( 'wb_not_found', 'Staff member or leave type not found.' );
		if ( '' === (string) $staff['started_at'] ) return new WP_Error( 'wb_no_start', 'The staff file has no start date, so leave cannot be worked out.' );
		$approved = array_map( fn( $l ) => [ (string) $l['from_date'], (float) $l['days'] ], WB_CCT::find( 'wb_leave', [ 'staff_id' => $staff_id, 'leave_type_id' => $leave_type_id, 'status' => 'approved' ], [ 'limit' => 2000 ] ) );
		return self::leave_balance( self::type_of( $type ), (string) $staff['started_at'], $as_of ?: wb_today(), $approved, (int) ( $staff['days_per_week'] ?? 5 ) ?: 5 );
	}

	/** @return int|WP_Error leave id */
	public static function request_leave( int $leave_type_id, string $from, string $to, string $note = '', int $doc_id = 0, int $staff_id = 0 ) {
		$staff_id = $staff_id ?: self::current_staff_id();
		if ( ! $staff_id ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record.' );
		if ( ! self::may_act_for( $staff_id ) ) return new WP_Error( 'wb_forbidden', 'You can only ask for your own leave.' );
		$staff = WB_CCT::get( 'wb_staff', $staff_id );
		$type  = WB_CCT::get( 'wb_leave_types', $leave_type_id );
		if ( ! $staff || ! $type ) return new WP_Error( 'wb_not_found', 'Choose the kind of leave.' );
		$from = substr( $from, 0, 10 );
		$to   = substr( $to, 0, 10 );
		$days = self::working_days( $from, $to, null, (int) ( $staff['days_per_week'] ?? 5 ) ?: 5 );
		if ( $days <= 0 ) return new WP_Error( 'wb_no_days', 'Those dates contain no working days.' );
		foreach ( WB_CCT::find( 'wb_leave', [ 'staff_id' => $staff_id, 'status' => [ 'requested', 'approved' ] ], [ 'limit' => 2000 ] ) as $l ) {
			if ( $from <= (string) $l['to_date'] && $to >= (string) $l['from_date'] ) return new WP_Error( 'wb_overlap', 'You already have leave in those dates.' );
		}
		$bal = self::balance( $staff_id, $leave_type_id, $from );
		if ( is_wp_error( $bal ) ) return $bal;
		if ( null !== $bal['balance'] && $days > $bal['balance'] + 0.001 ) return new WP_Error( 'wb_no_balance', sprintf( 'That is %d days; the balance is %s.', $days, $bal['balance'] ) );
		$id = WB_CCT::insert( 'wb_leave', [ 'staff_id' => $staff_id, 'leave_type_id' => $leave_type_id, 'from_date' => $from, 'to_date' => $to, 'days' => $days, 'status' => 'requested', 'note' => sanitize_textarea_field( $note ), 'doc_id' => $doc_id ], 'leave_requested' );
		if ( ! is_wp_error( $id ) ) WB_Notifications::notify_cap( 'wb_approve_leave', 'staff', sprintf( '%s %s asked for %d days of %s from %s.', $staff['first_name'], $staff['last_name'], $days, strtolower( (string) $type['name'] ), $from ), home_url( '/workspace/staff/?leave=1' ), 'wb_leave', (int) $id, [ get_current_user_id() ] );
		return $id;
	}

	/** Approve or decline. Needs wb_approve_leave; never your own. Balance re-checked. @return true|WP_Error */
	public static function decide_leave( int $leave_id, bool $approve ) {
		if ( ! current_user_can( 'wb_approve_leave' ) ) return new WP_Error( 'wb_forbidden', 'You cannot approve leave.' );
		$l = WB_CCT::get( 'wb_leave', $leave_id );
		if ( ! $l || 'requested' !== $l['status'] ) return new WP_Error( 'wb_not_pending', 'That leave is not waiting for a decision.' );
		$me = self::current_staff_id();
		if ( ! $me || $me === (int) $l['staff_id'] ) return new WP_Error( 'wb_self_approval', 'Someone else must approve your own leave.' );
		if ( $approve ) {
			$bal = self::balance( (int) $l['staff_id'], (int) $l['leave_type_id'], (string) $l['from_date'] );
			if ( is_wp_error( $bal ) ) return $bal;
			if ( null !== $bal['balance'] && (float) $l['days'] > $bal['balance'] + 0.001 ) return new WP_Error( 'wb_no_balance', 'The balance no longer covers this leave.' );
		}
		$res = WB_CCT::update( 'wb_leave', $leave_id, [ 'status' => $approve ? 'approved' : 'declined', 'approved_by_staff_id' => $me ], $approve ? 'leave_approved' : 'leave_declined' );
		WB_Notifications::resolve( 'wb_leave', $leave_id );
		$uid = self::user_for_staff( (int) $l['staff_id'] );
		if ( true === $res && $uid ) WB_Notifications::notify( $uid, 'staff', sprintf( 'Your leave from %s was %s.', $l['from_date'], $approve ? 'approved' : 'declined' ), home_url( '/workspace/staff/' ), 'wb_leave', $leave_id );
		return $res;
	}

	/** Cancel leave that hasn't started. @return true|WP_Error */
	public static function cancel_leave( int $leave_id ) {
		$l = WB_CCT::get( 'wb_leave', $leave_id );
		if ( ! $l || ! in_array( $l['status'], [ 'requested', 'approved' ], true ) ) return new WP_Error( 'wb_not_open', 'That leave cannot be cancelled.' );
		if ( ! self::may_act_for( (int) $l['staff_id'] ) ) return new WP_Error( 'wb_forbidden', 'You can only cancel your own leave.' );
		if ( (string) $l['from_date'] <= wb_today() && ! current_user_can( 'wb_manage_staff' ) ) return new WP_Error( 'wb_started', 'Leave that has started can only be changed by a manager.' );
		return WB_CCT::update( 'wb_leave', $leave_id, [ 'status' => 'cancelled' ], 'leave_cancelled' );
	}

	/* ================================================================== KPIs */

	/** The automatic actual for one measure, one person, one period. null = not measurable automatically. */
	public static function measure( string $measure, int $staff_id, string $from, string $to ): ?float {
		$to_dt = $to . ' 23:59:59';
		switch ( $measure ) {
			case 'quotes_sent':
				return (float) WB_CCT::count( 'wb_quotes', [ 'rep_staff_id' => $staff_id, 'sent_at >=' => $from, 'sent_at <=' => $to_dt ] );
			case 'quote_win_rate':
				$sent = WB_CCT::find( 'wb_quotes', [ 'rep_staff_id' => $staff_id, 'sent_at >=' => $from, 'sent_at <=' => $to_dt ], [ 'limit' => 5000 ] );
				return self::kpi_win_rate( count( $sent ), count( array_filter( $sent, fn( $q ) => in_array( $q['status'], [ 'accepted', 'converted' ], true ) ) ) );
			case 'revenue':
				return WB_CCT::sum( 'wb_quotes', 'subtotal', [ 'rep_staff_id' => $staff_id, 'status' => 'converted', 'accepted_at >=' => $from, 'accepted_at <=' => $to_dt ] );
			case 'on_time_delivery':
				$rows = [];
				foreach ( WB_CCT::find( 'wb_delivery_notes', [ 'issued_by_staff_id' => $staff_id, 'issued_at >=' => $from, 'issued_at <=' => $to_dt ], [ 'limit' => 5000 ] ) as $dn ) {
					$o      = WB_CCT::get( 'wb_orders', (int) $dn['order_id'] );
					$rows[] = [ (string) ( $o['required_by'] ?? '' ), (string) $dn['issued_at'] ];
				}
				return $rows ? self::kpi_on_time( $rows ) : null;
			case 'stock_accuracy':
				$lines = [];
				foreach ( WB_CCT::find( 'wb_stocktakes', [ 'counted_by_staff_id' => $staff_id, 'status' => 'posted', 'started_at >=' => $from, 'started_at <=' => $to_dt ] ) as $st ) {
					foreach ( WB_CCT::json( $st['lines_json'] ) as $l ) $lines[] = [ (float) $l['system'], (float) $l['counted'] ];
				}
				return $lines ? self::kpi_stock_accuracy( $lines ) : null;
			case 'timesheet_compliance':
				$staff = WB_CCT::get( 'wb_staff', $staff_id );
				$end   = min( $to, wb_today() );
				$exp   = $end >= $from ? self::working_days( $from, $end, null, (int) ( $staff['days_per_week'] ?? 5 ) ?: 5 ) : 0;
				$done  = WB_CCT::count( 'wb_timesheets', [ 'staff_id' => $staff_id, 'status' => [ 'submitted', 'approved' ], 'work_date >=' => $from, 'work_date <=' => $end ] );
				return self::kpi_timesheet_compliance( $exp, $done );
		}
		return null;
	}

	/** Write auto scores for every KPI and the people it applies to. Manual scores are never overwritten. */
	public static function measure_kpis( string $period_start, string $period_end ): int {
		$n = 0;
		foreach ( WB_CCT::find( 'wb_kpis', [ 'measure' => self::KPI_AUTO ], [ 'limit' => 500 ] ) as $k ) {
			$people = 'staff' === $k['applies_to'] && ! empty( $k['staff_id'] ) ? [ WB_CCT::get( 'wb_staff', (int) $k['staff_id'] ) ] : WB_CCT::find( 'wb_staff', [ 'status' => [ 'active', '' ] ], [ 'limit' => 2000 ] );
			foreach ( array_filter( $people ) as $s ) {
				if ( 'role' === $k['applies_to'] && ! empty( $k['role_key'] ) ) {
					$u = get_userdata( (int) $s['wp_user_id'] );
					if ( ! $u || ! in_array( (string) $k['role_key'], (array) $u->roles, true ) ) continue;
				}
				$actual = self::measure( (string) $k['measure'], (int) $s['_ID'], $period_start, $period_end );
				if ( null === $actual ) continue;
				$have = WB_CCT::first( 'wb_kpi_scores', [ 'kpi_id' => (int) $k['_ID'], 'staff_id' => (int) $s['_ID'], 'period_start' => $period_start ] );
				if ( $have && 'manual' === $have['source'] ) continue;
				$row = [ 'kpi_id' => (int) $k['_ID'], 'staff_id' => (int) $s['_ID'], 'period_start' => $period_start, 'target' => (float) $k['target'], 'actual' => $actual, 'source' => 'auto' ];
				$have ? WB_CCT::update( 'wb_kpi_scores', (int) $have['_ID'], $row, 'kpi_measured' ) : WB_CCT::insert( 'wb_kpi_scores', $row, 'kpi_measured' );
				$n++;
			}
		}
		return $n;
	}

	/* ================================================================== reviews */

	/** @return int|WP_Error */
	public static function schedule_review( int $staff_id, int $reviewer_staff_id, string $period ) {
		if ( ! current_user_can( 'wb_run_reviews' ) ) return new WP_Error( 'wb_forbidden', 'You cannot run reviews.' );
		if ( $staff_id === $reviewer_staff_id ) return new WP_Error( 'wb_self_review', 'A person cannot be their own reviewer.' );
		if ( ! WB_CCT::get( 'wb_staff', $staff_id ) || ! WB_CCT::get( 'wb_staff', $reviewer_staff_id ) ) return new WP_Error( 'wb_not_found', 'Choose the person and the reviewer.' );
		return WB_CCT::insert( 'wb_reviews', [ 'staff_id' => $staff_id, 'reviewer_staff_id' => $reviewer_staff_id, 'period' => sanitize_text_field( $period ), 'status' => 'scheduled' ], 'review_scheduled' );
	}

	/**
	 * Move a review on. Who may: the reviewer opens the self review and writes the manager part;
	 * the person writes their own self review; both sign (the review is signed when both have).
	 * $data: self_json | manager_json + rating + goals_json, as the step needs.
	 *
	 * @return true|WP_Error
	 */
	public static function advance_review( int $review_id, string $to, array $data = [] ) {
		$r = WB_CCT::get( 'wb_reviews', $review_id );
		if ( ! $r ) return new WP_Error( 'wb_not_found', 'Review not found.' );
		$me         = self::current_staff_id();
		$is_person  = $me && $me === (int) $r['staff_id'];
		$is_reviewer = $me && $me === (int) $r['reviewer_staff_id'] && current_user_can( 'wb_run_reviews' );

		if ( 'sign' === $to ) {
			if ( 'discussed' !== $r['status'] ) return new WP_Error( 'wb_not_ready', 'A review is signed after it has been discussed.' );
			if ( ! $is_person && ! $is_reviewer ) return new WP_Error( 'wb_forbidden', 'Only the person and their reviewer sign.' );
			$upd = $is_person ? [ 'signed_at_staff' => current_time( 'mysql' ) ] : [ 'signed_at_manager' => current_time( 'mysql' ) ];
			$both = ( $is_person ? true : ! empty( $r['signed_at_staff'] ) ) && ( $is_reviewer ? true : ! empty( $r['signed_at_manager'] ) );
			if ( $both ) $upd['status'] = 'signed';
			return WB_CCT::update( 'wb_reviews', $review_id, $upd, 'review_signed' );
		}
		if ( ! self::review_can_move( (string) $r['status'], $to ) ) return new WP_Error( 'wb_bad_move', sprintf( 'A review cannot go from %s to %s.', $r['status'], $to ) );
		switch ( $to ) {
			case 'self_review':
				if ( ! $is_reviewer ) return new WP_Error( 'wb_forbidden', 'The reviewer opens the self review.' );
				return WB_CCT::update( 'wb_reviews', $review_id, [ 'status' => 'self_review' ], 'review_opened' );
			case 'manager_review':
				if ( ! $is_person ) return new WP_Error( 'wb_forbidden', 'Only the person writes their own self review.' );
				return WB_CCT::update( 'wb_reviews', $review_id, [ 'status' => 'manager_review', 'self_json' => (array) ( $data['self_json'] ?? [] ) ], 'review_self_done' );
			case 'discussed':
				if ( ! $is_reviewer ) return new WP_Error( 'wb_forbidden', 'Only the reviewer writes the manager review.' );
				$rating = (float) ( $data['rating'] ?? 0 );
				if ( $rating < 1 || $rating > 5 ) return new WP_Error( 'wb_rating', 'Give a rating from 1 to 5.' );
				return WB_CCT::update( 'wb_reviews', $review_id, [ 'status' => 'discussed', 'manager_json' => (array) ( $data['manager_json'] ?? [] ), 'rating' => $rating, 'goals_json' => (array) ( $data['goals_json'] ?? [] ) ], 'review_discussed' );
		}
		return new WP_Error( 'wb_bad_move', 'Unknown step.' );
	}
}
