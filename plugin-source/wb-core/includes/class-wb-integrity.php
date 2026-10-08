<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Integrity — the monthly Integrity report (DATA-ARCHITECTURE §8).
 *
 * Puts in front of the owner, BY STAFF MEMBER, every place a digital cover-up would have to pass:
 *   - stock adjustments and write-offs (who asked, who approved, how much, what value)
 *   - stocktake count variances (counter and checker)
 *   - credit notes, each paired with the payment it reverses (asked by, approved by)
 *   - payments matched by hand, listed apart from automatic matches (and confirmed suggestions)
 *   - prices approved below the floor (asked by, approved by, margin)
 *   - the audit-trail check (the nightly chain verification)
 * Generated on the 1st for the month before (in-app notification to the owners; no email unless
 * wb_notify_integrity_email is on). [wb_integrity month="YYYY-MM"] renders any month.
 */
class WB_Integrity {

	public static function init(): void {
		add_shortcode( 'wb_integrity', [ __CLASS__, 'shortcode' ] );
		add_action( 'wb_nightly', [ __CLASS__, 'maybe_monthly' ], 90 );
	}

	/** First and last day of a YYYY-MM month. */
	public static function bounds( string $month ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) $month = gmdate( 'Y-m', strtotime( 'first day of last month' ) );
		$from = $month . '-01';
		return [ $from, gmdate( 'Y-m-t', strtotime( $from . ' 12:00:00 UTC' ) ) ];
	}

	private static function staff_name( int $staff_id ): string {
		if ( $staff_id <= 0 ) return 'Not recorded';
		$s = WB_CCT::get( 'wb_staff', $staff_id );
		return $s ? trim( $s['first_name'] . ' ' . $s['last_name'] ) : 'Staff #' . $staff_id;
	}

	private static function user_name( int $uid ): string {
		$u = $uid ? get_userdata( $uid ) : null;
		return $u ? (string) $u->display_name : ( $uid ? 'User #' . $uid : 'Automatic' );
	}

	/**
	 * Stock rows grouped by product (pure): [ product, times, qty, value ] with qty and value as sizes
	 * (absolute), largest value first — so repeated small write-offs of one product stand out.
	 */
	public static function by_product( array $rows ): array {
		$g = [];
		foreach ( $rows as $r ) {
			$k = (string) ( $r['product'] ?? '' );
			$g[ $k ] = [
				'product' => $k,
				'times'   => ( $g[ $k ]['times'] ?? 0 ) + 1,
				'qty'     => round( ( $g[ $k ]['qty'] ?? 0 ) + abs( (float) ( $r['qty'] ?? 0 ) ), 3 ),
				'value'   => round( ( $g[ $k ]['value'] ?? 0 ) + abs( (float) ( $r['value'] ?? 0 ) ), 2 ),
			];
		}
		usort( $g, fn( $a, $b ) => $b['value'] <=> $a['value'] ?: strcmp( $a['product'], $b['product'] ) );
		return array_values( $g );
	}

	/** The report as data: sections of rows + a by-person summary. */
	public static function report( string $month ): array {
		[ $from, $to ] = self::bounds( $month );
		$to_dt = $to . ' 23:59:59';
		$by    = [];
		$bump  = function ( string $who, string $what, float $value = 0 ) use ( &$by ) {
			$by[ $who ][ $what ]['n']     = ( $by[ $who ][ $what ]['n'] ?? 0 ) + 1;
			$by[ $who ][ $what ]['value'] = round( ( $by[ $who ][ $what ]['value'] ?? 0 ) + $value, 2 );
		};

		$stock = [];
		foreach ( [ 'adjustment', 'write_off', 'count' ] as $type ) {
			foreach ( WB_CCT::find( 'wb_stock_movements', [ 'type' => $type, 'cct_created >=' => $from, 'cct_created <=' => $to_dt ], [ 'limit' => 5000, 'active_only' => false, 'order' => 'ASC' ] ) as $m ) {
				$p     = WB_CCT::get( 'wb_products', (int) $m['product_id'] );
				$value = round( (float) $m['qty'] * (float) $m['unit_cost'], 2 );
				$asked = self::staff_name( (int) $m['staff_id'] );
				$appr  = self::staff_name( (int) $m['approved_by_staff_id'] );
				$stock[ $type ][] = [ 'when' => $m['cct_created'], 'product' => $p ? $p['sku'] . ' ' . $p['name'] : '#' . $m['product_id'], 'qty' => (float) $m['qty'], 'value' => $value, 'asked_by' => $asked, 'approved_by' => $appr, 'reason' => (string) $m['reason'] ];
				// Per person, the SIZE of what moved: a +500 and a −500 count must not net to nothing.
				$bump( $asked, $type . ' (asked / counted)', abs( $value ) );
				$bump( $appr, $type . ' (approved / checked)', abs( $value ) );
			}
		}

		$credits = [];
		foreach ( WB_CCT::find( 'wb_credit_notes', [ 'status' => 'approved', 'approved_at >=' => $from, 'approved_at <=' => $to_dt ], [ 'limit' => 5000, 'active_only' => false ] ) as $c ) {
			$inv = WB_CCT::get( 'wb_invoices', (int) $c['invoice_id'] );
			$pay = ! empty( $c['payment_id'] ) ? WB_CCT::get( 'wb_payments', (int) $c['payment_id'] ) : null;
			$asked = self::staff_name( (int) $c['requested_by_staff_id'] );
			$appr  = self::staff_name( (int) $c['approved_by_staff_id'] );
			$credits[] = [ 'number' => $c['credit_number'], 'invoice' => $inv['invoice_number'] ?? '', 'reason' => $c['reason'], 'total' => (float) $c['total'],
				'reverses_payment' => $pay ? sprintf( '%s on %s (%s)', number_format( (float) $pay['amount'], 2 ), $pay['received_at'], $pay['match_method'] ) : 'No payment on the invoice',
				'asked_by' => $asked, 'approved_by' => $appr ];
			$bump( $asked, 'credit notes asked', (float) $c['total'] );
			$bump( $appr, 'credit notes approved', (float) $c['total'] );
		}

		$manual = [];
		$confirmed = [];
		// By how it was matched, not by who: a hand match is listed even when no staff record was
		// on it ("Not recorded"). Confirmed suggestions = any non-manual match a person made.
		$range   = [ 'matched_at >=' => $from, 'matched_at <=' => $to_dt ];
		$by_hand = WB_CCT::find( 'wb_payments', $range + [ 'match_method' => 'manual' ], [ 'limit' => 5000, 'active_only' => false ] );
		$by_conf = WB_CCT::find( 'wb_payments', $range + [ 'match_method !=' => 'manual', 'matched_by_staff_id >' => 0 ], [ 'limit' => 5000, 'active_only' => false ] );
		foreach ( array_merge( $by_hand, $by_conf ) as $p ) {
			$inv = ! empty( $p['invoice_id'] ) ? WB_CCT::get( 'wb_invoices', (int) $p['invoice_id'] ) : null;
			$who = self::staff_name( (int) $p['matched_by_staff_id'] );
			$row = [ 'when' => $p['matched_at'], 'amount' => (float) $p['amount'], 'reference' => trim( $p['bank_reference'] . ' ' . $p['bank_description'] ), 'invoice' => $inv['invoice_number'] ?? '', 'status' => $p['match_status'], 'by' => $who, 'note' => (string) ( $p['match_note'] ?? '' ) ];
			if ( 'manual' === $p['match_method'] ) { $manual[] = $row; $bump( $who, 'payments matched by hand', (float) $p['amount'] ); }
			else { $confirmed[] = $row; $bump( $who, 'suggestions confirmed', (float) $p['amount'] ); }
		}

		$floor = [];
		global $wpdb;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WB_Pricing::table() . " WHERE decision = 'approved' AND decided_at BETWEEN %s AND %s ORDER BY decided_at ASC", $from, $to_dt ), ARRAY_A ) as $a ) {
			$asked = self::user_name( (int) $a['requested_by'] );
			$appr  = self::user_name( (int) $a['decided_by'] );
			$floor[] = [ 'when' => $a['decided_at'], 'quote_id' => (int) $a['quote_id'], 'reason' => $a['reason'], 'floor' => (float) $a['floor_price'], 'price' => (float) $a['asked_price'], 'margin_pct' => (float) $a['margin_pct'], 'asked_by' => $asked, 'approved_by' => $appr ];
			$bump( $asked, 'below-floor prices asked', 0 );
			$bump( $appr, 'below-floor prices approved', 0 );
		}

		ksort( $by );
		return [
			'month' => substr( $from, 0, 7 ), 'from' => $from, 'to' => $to,
			'adjustments' => $stock['adjustment'] ?? [], 'write_offs' => $stock['write_off'] ?? [], 'count_variances' => $stock['count'] ?? [],
			'write_offs_by_product' => self::by_product( $stock['write_off'] ?? [] ),
			'credit_notes' => $credits, 'manual_matches' => $manual, 'confirmed_suggestions' => $confirmed, 'below_floor' => $floor,
			'by_person' => $by, 'ledger' => (array) get_option( 'wb_ledger_last_check', [] ),
		];
	}

	/** On the 1st (or the first nightly run after it), once per month: tell the owners last month's report is ready. */
	public static function maybe_monthly(): void {
		$month = gmdate( 'Y-m', strtotime( current_time( 'Y-m-01' ) . ' -1 day' ) );
		if ( get_option( 'wb_integrity_last_month' ) === $month ) return;
		$r = self::report( $month );
		update_option( 'wb_integrity_last_month', $month, false );
		$counts = [ count( $r['adjustments'] ), count( $r['write_offs'] ), count( $r['credit_notes'] ), count( $r['manual_matches'] ), count( $r['below_floor'] ) ];
		wb_ledger_write( 'integrity_report', 'wb_integrity', 0, null, [ 'month' => $month, 'adjustments' => $counts[0], 'write_offs' => $counts[1], 'credit_notes' => $counts[2], 'manual_matches' => $counts[3], 'below_floor' => $counts[4] ] );
		WB_Notifications::notify_owners( 'integrity', vsprintf( 'The Integrity report for ' . $month . ' is ready: %d adjustments, %d write-offs, %d credit notes, %d payments matched by hand, %d prices below the floor.', $counts ), home_url( '/workspace/integrity/?month=' . $month ) );
	}

	/** What each audit-trail break means, in words a person reads (0.2.1). */
	const LEDGER_REASONS = [
		'prev_link'          => 'an entry no longer links to the one before it',
		'hash'               => 'an entry was changed after it was written',
		'downgrade'          => 'an entry was rewritten in an older, weaker form',
		'key_missing'        => 'the encryption key is missing, so signed entries cannot be checked',
		'anchor_missing'     => 'the record of the latest entry is missing',
		'anchor_forged'      => 'the record of the latest entry was altered',
		'anchor_mismatch'    => 'the latest entries do not match the record kept of them',
		'anchor_row_missing' => 'the latest entries were removed',
		'truncated'          => 'entries were removed from the end of the trail',
		'missing_table'      => 'the audit trail table is missing',
	];

	/** The audit-trail status line: last nightly check, any break, and entries still waiting. */
	public static function ledger_notice( array $l ): string {
		$waiting = class_exists( 'WB_Ledger' ) && method_exists( 'WB_Ledger', 'pending_count' ) ? WB_Ledger::pending_count() : (int) ( $l['pending'] ?? 0 );
		$h = '';
		if ( empty( $l ) ) {
			$h .= wb_notice( 'warn', 'The audit trail has not been checked yet (it runs every night).' );
		} elseif ( ! empty( $l['ok'] ) ) {
			$h .= wb_notice( 'ok', 'Audit trail intact (checked ' . esc_html( (string) $l['at'] ) . ').' );
		} else {
			$why = self::LEDGER_REASONS[ (string) ( $l['reason'] ?? '' ) ] ?? 'the entries do not add up';
			$at  = (int) ( $l['entry_id'] ?? 0 ) > 0 ? ' at entry #' . (int) $l['entry_id'] : '';
			$h  .= wb_notice( 'err', 'The audit trail check found a break' . $at . ': ' . esc_html( $why ) . '. Records may have been changed outside the system.' );
		}
		if ( $waiting > 0 ) {
			$h .= wb_notice( 'warn', sprintf( '%d change(s) were saved but are still waiting to be added to the audit trail. They are added automatically; if this stays, the database is under strain or missing a column.', $waiting ) );
		}
		return $h;
	}

	public static function shortcode( $atts = [] ): string {
		if ( ! current_user_can( 'wb_view_integrity' ) ) return wb_notice( 'warn', 'The Integrity report is for the owner and the people they choose.' );
		$atts  = shortcode_atts( [ 'month' => '' ], (array) $atts );
		$month = sanitize_text_field( (string) ( $_GET['month'] ?? $atts['month'] ) );
		$r     = self::report( $month );
		$h     = '<form method="get" class="wb-inline-form"><label>Month <input type="month" name="month" value="' . esc_attr( $r['month'] ) . '"></label> <button type="submit" class="wb-btn wb-btn-ghost">Show</button></form>';
		$l     = $r['ledger'];
		$h    .= self::ledger_notice( $l );

		$h .= '<h3>By person</h3>';
		$people = [];
		foreach ( $r['by_person'] as $who => $items ) {
			foreach ( $items as $what => $v ) $people[] = [ 'who' => $who, 'what' => $what, 'n' => $v['n'], 'value' => number_format( (float) $v['value'], 2 ) ];
		}
		$h .= $people ? WB_Render::render_table( $people, [ [ 'key' => 'who', 'label' => 'Person' ], [ 'key' => 'what', 'label' => 'What' ], [ 'key' => 'n', 'label' => 'How many' ], [ 'key' => 'value', 'label' => 'Value' ] ] ) : wb_notice( 'ok', 'Nothing to report this month.' );

		$sections = [
			'adjustments'           => [ 'Stock adjustments', [ 'when', 'product', 'qty', 'value', 'asked_by', 'approved_by', 'reason' ] ],
			'write_offs'            => [ 'Write-offs', [ 'when', 'product', 'qty', 'value', 'asked_by', 'approved_by', 'reason' ] ],
			'write_offs_by_product' => [ 'Write-offs by product', [ 'product', 'times', 'qty', 'value' ] ],
			'count_variances'       => [ 'Stocktake differences', [ 'when', 'product', 'qty', 'value', 'asked_by', 'approved_by', 'reason' ] ],
			'credit_notes'          => [ 'Credit notes and the payment each reverses', [ 'number', 'invoice', 'reason', 'total', 'reverses_payment', 'asked_by', 'approved_by' ] ],
			'manual_matches'        => [ 'Payments matched by hand', [ 'when', 'amount', 'reference', 'invoice', 'by', 'note' ] ],
			'confirmed_suggestions' => [ 'Suggested matches confirmed', [ 'when', 'amount', 'reference', 'invoice', 'by' ] ],
			'below_floor'           => [ 'Prices approved below the floor or above list', [ 'when', 'quote_id', 'reason', 'floor', 'price', 'margin_pct', 'asked_by', 'approved_by' ] ],
		];
		foreach ( $sections as $key => [ $title, $cols ] ) {
			$h .= '<details class="wb-fold"' . ( $r[ $key ] ? ' open' : '' ) . '><summary>' . esc_html( $title ) . ' <span class="wb-count">' . count( $r[ $key ] ) . '</span></summary>';
			$h .= $r[ $key ] ? WB_Render::render_table( $r[ $key ], array_map( fn( $c ) => [ 'key' => $c, 'label' => WB_Render::label( $c ) ], $cols ) ) : '<p class="wb-muted">None.</p>';
			$h .= '</details>';
		}
		return $h;
	}
}
