<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Demand — what customers are likely to order, and when the money moves (nightly).
 *
 *  - Per customer × product: average days between orders, last order, predicted next order,
 *    average quantity, and a confidence (more orders, steadier rhythm → higher).
 *  - Per product × calendar month: seasonal index = that month's average ÷ the overall monthly
 *    average (1.0 = a normal month, 1.5 = half again as busy).
 *  - Per customer: journey_stage (lead / quoted / first_order / repeat / at_risk / lapsed) by
 *    wb_journey_rules, stored on wb_customers for filtering (only written when it changes).
 *  - 13-week cashflow: expected receipts (invoices by due date shifted by the customer's own
 *    paying habit), predicted orders (demand × confidence, paid on terms), committed purchases
 *    (open POs on the supplier's terms), payroll — and net. Every night's run is KEPT as a
 *    snapshot (run_date), so forecast accuracy can be measured later.
 *
 * The maths is PURE static functions (predict, seasonal_index, journey_stage, avg_days_late,
 * cashflow_forecast) — testable without WordPress.
 */
class WB_Demand {

	/* ================================================================== pure */

	private static function days_between( string $a, string $b ): int {
		return (int) round( ( strtotime( substr( $b, 0, 10 ) . ' 12:00:00 UTC' ) - strtotime( substr( $a, 0, 10 ) . ' 12:00:00 UTC' ) ) / 86400 );
	}

	private static function add_days( string $ymd, float $days ): string {
		return gmdate( 'Y-m-d', strtotime( substr( $ymd, 0, 10 ) . ' 12:00:00 UTC' ) + (int) round( $days ) * 86400 );
	}

	/**
	 * Order history for one customer × product: [ [date Y-m-d, qty], … ] (any order).
	 * Several orders on one day count as one order of their total.
	 * confidence = min(1, (orders − 1) / 5) × max(0, 1 − coefficient of variation of the intervals).
	 */
	public static function predict( array $history ): array {
		$by_day = [];
		foreach ( $history as $h ) {
			$d = substr( (string) $h[0], 0, 10 );
			$by_day[ $d ] = ( $by_day[ $d ] ?? 0 ) + (float) $h[1];
		}
		ksort( $by_day );
		$dates = array_keys( $by_day );
		$n     = count( $dates );
		$out   = [ 'orders' => $n, 'avg_interval_days' => null, 'last_order_at' => $n ? end( $dates ) : null, 'predicted_next_at' => null, 'avg_qty' => $n ? round( array_sum( $by_day ) / $n, 3 ) : 0.0, 'confidence' => 0.0 ];
		if ( $n < 2 ) return $out;
		$iv = [];
		for ( $i = 1; $i < $n; $i++ ) $iv[] = self::days_between( $dates[ $i - 1 ], $dates[ $i ] );
		$mean = array_sum( $iv ) / count( $iv );
		if ( $mean <= 0 ) return $out;
		$var = 0.0;
		foreach ( $iv as $x ) $var += ( $x - $mean ) ** 2;
		$cv = sqrt( $var / count( $iv ) ) / $mean;
		$out['avg_interval_days'] = round( $mean, 2 );
		$out['predicted_next_at'] = self::add_days( (string) $out['last_order_at'], $mean );
		$out['confidence']        = round( min( 1.0, ( $n - 1 ) / 5 ) * max( 0.0, 1 - $cv ), 2 );
		return $out;
	}

	/**
	 * Monthly totals [ 'YYYY-MM' => qty ] → seasonal index per calendar month [ 1..12 => float|null ].
	 * Months between the first and last month with no orders count as 0 (a quiet month is data).
	 * A calendar month never seen in the range is null (unknown, not "average").
	 */
	public static function seasonal_index( array $monthly ): array {
		$out = array_fill( 1, 12, null );
		if ( ! $monthly ) return $out;
		ksort( $monthly );
		$keys = array_keys( $monthly );
		$y    = (int) substr( $keys[0], 0, 4 );
		$m    = (int) substr( $keys[0], 5, 2 );
		$end  = $keys[ count( $keys ) - 1 ];
		$all  = [];
		while ( sprintf( '%04d-%02d', $y, $m ) <= $end ) {
			$k         = sprintf( '%04d-%02d', $y, $m );
			$all[ $k ] = (float) ( $monthly[ $k ] ?? 0 );
			if ( ++$m > 12 ) { $m = 1; $y++; }
		}
		$overall = array_sum( $all ) / count( $all );
		if ( $overall <= 0 ) return $out;
		$per = [];
		foreach ( $all as $k => $v ) $per[ (int) substr( $k, 5, 2 ) ][] = $v;
		foreach ( $per as $month => $vals ) $out[ $month ] = round( ( array_sum( $vals ) / count( $vals ) ) / $overall, 3 );
		return $out;
	}

	/**
	 * $f: quotes (count), orders (count), days_since_last_order, avg_interval_days (or null).
	 * $rules: at_risk_days, lapsed_days, at_risk_interval_multiple (a regular customer is at risk
	 * when they are this many of their own intervals late, even before at_risk_days).
	 */
	public static function journey_stage( array $f, array $rules ): string {
		$orders = (int) ( $f['orders'] ?? 0 );
		if ( 0 === $orders ) return (int) ( $f['quotes'] ?? 0 ) > 0 ? 'quoted' : 'lead';
		$since  = (int) ( $f['days_since_last_order'] ?? 0 );
		$lapsed = (int) ( $rules['lapsed_days'] ?? 180 );
		$risk   = (int) ( $rules['at_risk_days'] ?? 60 );
		$mult   = (float) ( $rules['at_risk_interval_multiple'] ?? 2 );
		if ( $since >= $lapsed ) return 'lapsed';
		if ( $since >= $risk ) return 'at_risk';
		$iv = $f['avg_interval_days'] ?? null;
		if ( $orders >= 3 && null !== $iv && (float) $iv > 0 && $mult > 0 && $since > $mult * (float) $iv ) return 'at_risk';
		return 1 === $orders ? 'first_order' : 'repeat';
	}

	/** Paid invoices [ [due Y-m-d, paid Y-m-d], … ] → average days paid after due (early counts as 0). */
	public static function avg_days_late( array $paid ): float {
		if ( ! $paid ) return 0.0;
		$t = 0;
		foreach ( $paid as $p ) $t += max( 0, self::days_between( (string) $p[0], (string) $p[1] ) );
		return round( $t / count( $paid ), 1 );
	}

	/**
	 * The 13-week forecast. $in:
	 *   receivables  [ [due Y-m-d, amount, customer_id], … ]   open invoice balances
	 *   pay_delay    [ customer_id => avg days late ]
	 *   predicted    [ [expected receipt Y-m-d, amount], … ]   orders not yet placed (already × confidence)
	 *   purchases    [ [payment due Y-m-d, amount], … ]        open POs
	 *   payroll_weekly float
	 *   opening      float (cash now)
	 * Money expected before $start (overdue) lands in week 1. Beyond the horizon is ignored.
	 */
	public static function cashflow_forecast( array $in, string $start, int $weeks = 13 ): array {
		$rows = [];
		for ( $w = 0; $w < $weeks; $w++ ) {
			$rows[ $w ] = [ 'week_start' => self::add_days( $start, 7 * $w ), 'expected_receipts' => 0.0, 'predicted_orders' => 0.0, 'committed_purchases' => 0.0, 'payroll' => round( (float) ( $in['payroll_weekly'] ?? 0 ), 2 ), 'net' => 0.0, 'cumulative' => 0.0 ];
		}
		$slot = function ( string $date ) use ( $start, $weeks ): ?int {
			$d = self::days_between( $start, $date );
			if ( $d < 0 ) return 0;
			$w = intdiv( $d, 7 );
			return $w < $weeks ? $w : null;
		};
		foreach ( (array) ( $in['receivables'] ?? [] ) as $r ) {
			$delay = (float) ( $in['pay_delay'][ (int) ( $r[2] ?? 0 ) ] ?? 0 );
			$w     = $slot( self::add_days( (string) $r[0], $delay ) );
			if ( null !== $w ) $rows[ $w ]['expected_receipts'] += (float) $r[1];
		}
		foreach ( (array) ( $in['predicted'] ?? [] ) as $r ) {
			$w = $slot( (string) $r[0] );
			if ( null !== $w ) $rows[ $w ]['predicted_orders'] += (float) $r[1];
		}
		foreach ( (array) ( $in['purchases'] ?? [] ) as $r ) {
			$w = $slot( (string) $r[0] );
			if ( null !== $w ) $rows[ $w ]['committed_purchases'] += (float) $r[1];
		}
		$cum = (float) ( $in['opening'] ?? 0 );
		foreach ( $rows as &$r ) {
			foreach ( [ 'expected_receipts', 'predicted_orders', 'committed_purchases' ] as $k ) $r[ $k ] = round( $r[ $k ], 2 );
			$r['net']        = round( $r['expected_receipts'] + $r['predicted_orders'] - $r['committed_purchases'] - $r['payroll'], 2 );
			$cum            += $r['net'];
			$r['cumulative'] = round( $cum, 2 );
		}
		unset( $r );
		return array_values( $rows );
	}

	/* ================================================================== tables */

	public static function stats_table(): string { global $wpdb; return $wpdb->prefix . 'wb_demand_stats'; }
	public static function cash_table(): string { global $wpdb; return $wpdb->prefix . 'wb_cashflow_forecast'; }

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::stats_table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(20) NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			month TINYINT UNSIGNED NOT NULL DEFAULT 0,
			orders INT UNSIGNED NOT NULL DEFAULT 0,
			avg_interval_days DECIMAL(10,2) NULL,
			last_order_at DATE NULL,
			predicted_next_at DATE NULL,
			avg_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
			confidence DECIMAL(4,2) NOT NULL DEFAULT 0,
			seasonal_index DECIMAL(8,3) NULL,
			computed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stat (kind, customer_id, product_id, month),
			KEY predicted (predicted_next_at)
		) $cs;" );
		dbDelta( "CREATE TABLE " . self::cash_table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_date DATE NOT NULL,
			week_start DATE NOT NULL,
			expected_receipts DECIMAL(14,2) NOT NULL DEFAULT 0,
			predicted_orders DECIMAL(14,2) NOT NULL DEFAULT 0,
			committed_purchases DECIMAL(14,2) NOT NULL DEFAULT 0,
			payroll DECIMAL(14,2) NOT NULL DEFAULT 0,
			net DECIMAL(14,2) NOT NULL DEFAULT 0,
			cumulative DECIMAL(14,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY run_week (run_date, week_start)
		) $cs;" );
	}

	/* ================================================================== nightly */

	public static function run_nightly(): void {
		// P8: every order, newest first, read in pages by _ID (never just the oldest 5 000).
		$odate  = [];
		$ocust  = [];
		$cursor = 0;
		do {
			$where = [ 'status NOT IN' => [ 'cancelled' ] ] + ( $cursor ? [ '_ID <' => $cursor ] : [] );
			$page  = WB_CCT::find( 'wb_orders', $where, [ 'limit' => 5000, 'orderby' => '_ID', 'order' => 'DESC' ] );
			foreach ( $page as $o ) {
				$odate[ (int) $o['_ID'] ] = substr( (string) $o['cct_created'], 0, 10 );
				$ocust[ (int) $o['_ID'] ] = (int) $o['customer_id'];
				$cursor = (int) $o['_ID'];
			}
		} while ( count( $page ) >= 5000 && $cursor > 0 );
		$cp = [];   // customer × product history
		$pm = [];   // product × YYYY-MM qty
		$last_price = [];
		$price_at   = [];   // product → [ order date, order id ] of the price kept: the newest order wins
		if ( $odate ) {
			foreach ( array_chunk( array_keys( $odate ), 500 ) as $chunk ) {
				$lcursor = 0;
				do {   // every line of these orders, in pages (orders can have many lines)
					$where = [ 'order_id' => $chunk ] + ( $lcursor ? [ '_ID >' => $lcursor ] : [] );
					$lines = WB_CCT::find( 'wb_order_lines', $where, [ 'limit' => 5000, 'orderby' => '_ID', 'order' => 'ASC' ] );
					foreach ( $lines as $l ) {
						$lcursor = (int) $l['_ID'];
						$oid     = (int) $l['order_id'];
						$pid     = (int) $l['product_id'];
						if ( ! isset( $odate[ $oid ] ) ) continue;
						$cp[ $ocust[ $oid ] ][ $pid ][] = [ $odate[ $oid ], (float) $l['qty_ordered'] ];
						$mk = substr( $odate[ $oid ], 0, 7 );
						$pm[ $pid ][ $mk ] = ( $pm[ $pid ][ $mk ] ?? 0 ) + (float) $l['qty_ordered'];
						$key = [ $odate[ $oid ], $oid ];
						if ( ! isset( $price_at[ $pid ] ) || $key > $price_at[ $pid ] ) {
							$price_at[ $pid ]   = $key;
							$last_price[ $pid ] = (float) $l['unit_price'];
						}
					}
				} while ( count( $lines ) >= 5000 && $lcursor > 0 );
			}
		}
		global $wpdb;
		$st  = self::stats_table();
		$now = wb_now();
		$predictions = [];
		foreach ( $cp as $cid => $products ) {
			foreach ( $products as $pid => $hist ) {
				$p = self::predict( $hist );
				$wpdb->replace( $st, [ 'kind' => 'customer_product', 'customer_id' => $cid, 'product_id' => $pid, 'month' => 0, 'orders' => $p['orders'],
					'avg_interval_days' => $p['avg_interval_days'], 'last_order_at' => $p['last_order_at'], 'predicted_next_at' => $p['predicted_next_at'],
					'avg_qty' => $p['avg_qty'], 'confidence' => $p['confidence'], 'seasonal_index' => null, 'computed_at' => $now ] );
				if ( $p['predicted_next_at'] ) $predictions[] = [ 'customer_id' => $cid, 'product_id' => $pid ] + $p;
			}
		}
		foreach ( $pm as $pid => $monthly ) {
			foreach ( self::seasonal_index( $monthly ) as $month => $idx ) {
				if ( null === $idx ) continue;
				$wpdb->replace( $st, [ 'kind' => 'product_month', 'customer_id' => 0, 'product_id' => $pid, 'month' => $month, 'seasonal_index' => $idx, 'computed_at' => $now ] );
			}
		}
		wb_ledger_write( 'demand_computed', 'wb_demand_stats', 0, null, [ 'pairs' => count( $predictions ), 'products' => count( $pm ) ] );
		self::update_journeys( $cp );
		self::forecast_cash( $predictions, $last_price );
	}

	/** journey_stage for every customer; written only when it changes (each change is ledgered). */
	private static function update_journeys( array $cp ): void {
		$rules = wp_parse_args( (array) get_option( 'wb_journey_rules', [] ), [ 'at_risk_days' => 60, 'lapsed_days' => 180, 'at_risk_interval_multiple' => 2 ] );
		$today = wb_today();
		foreach ( WB_CCT::find( 'wb_customers', [], [ 'limit' => 5000 ] ) as $c ) {
			$cid  = (int) $c['_ID'];
			$hist = [];
			foreach ( (array) ( $cp[ $cid ] ?? [] ) as $h ) $hist = array_merge( $hist, array_map( fn( $x ) => [ $x[0], 1 ], $h ) );
			$p     = self::predict( $hist );
			$stage = self::journey_stage( [
				'quotes' => WB_CCT::count( 'wb_quotes', [ 'customer_id' => $cid ] ),
				'orders' => $p['orders'],
				'days_since_last_order' => $p['last_order_at'] ? self::days_between( (string) $p['last_order_at'], $today ) : 0,
				'avg_interval_days' => $p['avg_interval_days'],
			], $rules );
			if ( (string) ( $c['journey_stage'] ?? '' ) !== $stage ) WB_CCT::update( 'wb_customers', $cid, [ 'journey_stage' => $stage ], 'customer_journey_stage' );
		}
	}

	private static function forecast_cash( array $predictions, array $last_price ): void {
		$today = wb_today();
		$cust  = [];
		foreach ( WB_CCT::find( 'wb_customers', [], [ 'limit' => 5000 ] ) as $c ) $cust[ (int) $c['_ID'] ] = $c;

		$receivables = [];
		foreach ( WB_CCT::find( 'wb_invoices', [ 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 5000 ] ) as $i ) {
			$o = WB_Invoices::outstanding( $i );
			if ( $o > 0.004 ) $receivables[] = [ (string) $i['due_at'], $o, (int) $i['customer_id'] ];
		}
		// Each customer's paying habit, from their paid invoices and when the money was matched.
		$late = [];
		foreach ( WB_CCT::find( 'wb_invoices', [ 'status' => 'paid' ], [ 'limit' => 5000 ] ) as $i ) {
			$pay = WB_CCT::first( 'wb_payments', [ 'invoice_id' => (int) $i['_ID'] ], [ 'orderby' => 'received_at', 'order' => 'DESC' ] );
			if ( $pay && ! empty( $pay['received_at'] ) ) $late[ (int) $i['customer_id'] ][] = [ (string) $i['due_at'], (string) $pay['received_at'] ];
		}
		$delay = [];
		foreach ( $late as $cid => $rows ) $delay[ $cid ] = self::avg_days_late( $rows );

		$predicted = [];
		foreach ( $predictions as $p ) {
			$terms = (int) ( $cust[ $p['customer_id'] ]['payment_terms_days'] ?? 0 );
			$value = (float) $p['avg_qty'] * (float) ( $last_price[ $p['product_id'] ] ?? 0 ) * (float) $p['confidence'];
			if ( $value > 0 && $p['predicted_next_at'] >= $today ) $predicted[] = [ self::add_days( (string) $p['predicted_next_at'], $terms ), round( $value * ( 1 + (float) get_option( 'wb_vat_rate', 15 ) / 100 ), 2 ) ];
		}

		$purchases = [];
		foreach ( WB_CCT::find( 'wb_purchase_orders', [ 'status' => [ 'sent', 'part_received' ] ], [ 'limit' => 2000 ] ) as $po ) {
			$sup   = WB_CCT::get( 'wb_suppliers', (int) $po['supplier_id'] );
			$value = 0.0;
			foreach ( WB_CCT::find( 'wb_po_lines', [ 'po_id' => (int) $po['_ID'] ] ) as $l ) $value += max( 0, (float) $l['qty_ordered'] - (float) $l['qty_received'] ) * (float) $l['unit_cost'];
			$when = '' !== (string) $po['expected_at'] ? (string) $po['expected_at'] : $today;
			if ( $value > 0 ) $purchases[] = [ self::add_days( $when, (int) ( $sup['payment_terms_days'] ?? 0 ) ), $value ];
		}

		$cfg  = wp_parse_args( (array) get_option( 'wb_cashflow', [] ), [ 'payroll_monthly' => 0, 'opening_balance' => 0 ] );
		$rows = self::cashflow_forecast( [
			'receivables' => $receivables, 'pay_delay' => $delay, 'predicted' => $predicted, 'purchases' => $purchases,
			'payroll_weekly' => ( WB_Payroll::monthly_cost() ?? (float) $cfg['payroll_monthly'] ) * 12 / 52, 'opening' => (float) $cfg['opening_balance'],   // 0.2.0: payroll profiles, when there are any
		], $today );
		global $wpdb;
		foreach ( $rows as $r ) $wpdb->replace( self::cash_table(), [ 'run_date' => $today ] + $r );
		wb_ledger_write( 'cashflow_forecast', 'wb_cashflow_forecast', 0, null, [ 'run_date' => $today, 'net_13w' => $rows ? end( $rows )['cumulative'] : 0 ] );
	}

	/** The latest night's 13 weeks. */
	public static function latest_forecast(): array {
		global $wpdb;
		$t   = self::cash_table();
		$run = (string) $wpdb->get_var( "SELECT MAX(run_date) FROM {$t}" );
		if ( '' === $run ) return [];
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE run_date = %s ORDER BY week_start ASC", $run ), ARRAY_A );
	}

	/** Customers likely to order in the next $days days (the Marketing "due to order" list). */
	public static function due_soon( int $days = 14, float $min_confidence = 0.3 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::stats_table() . " WHERE kind = 'customer_product' AND predicted_next_at BETWEEN %s AND %s AND confidence >= %f ORDER BY predicted_next_at ASC LIMIT 500",
			wb_today(), gmdate( 'Y-m-d', strtotime( wb_today() . ' +' . $days . ' days' ) ), $min_confidence ), ARRAY_A );
	}
}
