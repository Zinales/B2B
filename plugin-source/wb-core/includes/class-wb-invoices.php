<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Invoices — immutable invoices and approved credit notes (rule 6).
 *
 * An issued invoice is never edited: its lines (lines_json), subtotal, VAT and total are written
 * once, from the order's frozen lines. Only the DERIVED money state moves — amount_paid,
 * amount_credited and status (issued / part_paid / paid / overdue / credited) — and every change
 * is ledgered. A correction is a credit note: a new numbered document (CRN-…), asked for by one
 * person (wb_issue_credit_notes) and approved by a DIFFERENT person (wb_approve_credit_notes).
 * The number is only taken on approval, so the CRN series stays gapless.
 *
 * The VAT rate is the tenant's wb_vat_rate at the moment of issue, frozen on the invoice.
 */
class WB_Invoices {

	/* ================================================================== pure */

	/** Lines (each with line_total) → subtotal, vat, total. VAT is on the subtotal, rounded once. */
	public static function totals( array $lines, float $vat_rate ): array {
		$sub = 0.0;
		foreach ( $lines as $l ) $sub += round( (float) ( $l['line_total'] ?? 0 ), 2 );
		$sub = round( $sub, 2, PHP_ROUND_HALF_UP );
		$vat = round( $sub * $vat_rate / 100, 2, PHP_ROUND_HALF_UP );
		return [ 'subtotal' => $sub, 'vat_rate' => $vat_rate, 'vat' => $vat, 'total' => round( $sub + $vat, 2 ) ];
	}

	/** Due date = issue date + terms days (0 = due on issue). */
	public static function due_date( string $issued_ymd, int $terms_days ): string {
		$d = new DateTimeImmutable( substr( $issued_ymd, 0, 10 ) );
		return $d->modify( '+' . max( 0, $terms_days ) . ' days' )->format( 'Y-m-d' );
	}

	/** The one status rule. void is set explicitly and never recomputed away. */
	public static function status_for( float $total, float $paid, float $credited, string $due_ymd, string $today_ymd ): string {
		$net = round( $total - $credited, 2 );
		if ( $credited > 0 && $net <= 0.004 ) return 'credited';
		if ( $paid + 0.004 >= $net ) return 'paid';
		if ( '' !== $due_ymd && $today_ymd > substr( $due_ymd, 0, 10 ) ) return 'overdue';
		if ( $paid > 0.004 ) return 'part_paid';
		return 'issued';
	}

	public static function outstanding( array $inv ): float {
		return round( (float) $inv['total'] - (float) ( $inv['amount_paid'] ?? 0 ) - (float) ( $inv['amount_credited'] ?? 0 ), 2 );
	}

	/**
	 * Money we owe the customer back on this invoice: paid + credited beyond the total (paid 1000,
	 * credited 200 on a 1000 invoice → 200). 0 when nothing is owed back.
	 */
	public static function owed_back( array $inv ): float {
		return max( 0.0, -self::outstanding( $inv ) );
	}

	/**
	 * Is the invoice past its due date with money still owing, as of $today_ymd? True for an
	 * 'overdue' invoice, and for an issued / part-paid one whose due date has passed but which the
	 * nightly sweep has not marked yet.
	 */
	public static function is_past_due( array $inv, string $today_ymd ): bool {
		$s = (string) ( $inv['status'] ?? '' );
		if ( 'overdue' === $s ) return true;
		if ( ! in_array( $s, [ 'issued', 'part_paid' ], true ) ) return false;
		$due = substr( (string) ( $inv['due_at'] ?? '' ), 0, 10 );
		return '' !== $due && $due < $today_ymd && self::outstanding( $inv ) > 0.004;
	}

	/**
	 * Fit a credit note into what is left to credit. Line-by-line credits round their VAT one
	 * note at a time, so the last one can come out a cent or two above what is left (3 × 11.62 =
	 * 34.86 against a 34.85 invoice). Within $tolerance the note is capped at $remaining (VAT takes
	 * the difference); beyond it → null (refuse: that really is over-crediting).
	 */
	public static function cap_credit( array $tot, float $remaining, float $tolerance = 0.01 ): ?array {
		$remaining = round( $remaining, 2 );
		if ( (float) $tot['total'] <= $remaining + 0.004 ) return $tot;
		if ( (float) $tot['total'] - $remaining > $tolerance + 0.004 || $remaining <= 0 ) return null;
		$tot['total'] = $remaining;
		$tot['vat']   = round( max( 0.0, $remaining - (float) $tot['subtotal'] ), 2 );
		if ( (float) $tot['subtotal'] > $remaining ) $tot['subtotal'] = $remaining;
		return $tot;
	}

	/**
	 * Check credit lines against the invoice (pure). $invoice_lines = the invoice's lines_json.
	 * Every line with a product must be for a product on the invoice, for no more than was
	 * invoiced. $returnable = [ product_id => qty delivered and not yet returned on another credit
	 * note ], given only when the goods come back into stock. Lines without a product (a pricing or
	 * goodwill amount) are not checked here — the money total is.
	 * Returns '' when fine, else a plain-English reason.
	 */
	public static function credit_lines_problem( array $lines, array $invoice_lines, ?array $returnable = null ): string {
		$invoiced = [];
		foreach ( $invoice_lines as $il ) {
			$pid = (int) ( $il['product_id'] ?? 0 );
			if ( $pid ) $invoiced[ $pid ] = ( $invoiced[ $pid ] ?? 0 ) + (float) ( $il['qty'] ?? 0 );
		}
		$want = [];
		foreach ( $lines as $l ) {
			$pid = (int) ( $l['product_id'] ?? 0 );
			if ( $pid ) $want[ $pid ] = ( $want[ $pid ] ?? 0 ) + (float) ( $l['qty'] ?? 0 );
		}
		foreach ( $want as $pid => $q ) {
			if ( ! isset( $invoiced[ $pid ] ) ) return sprintf( 'Product #%d is not on this invoice.', $pid );
			if ( $q > $invoiced[ $pid ] + 0.0001 ) return sprintf( 'Product #%d: only %s was invoiced.', $pid, round( $invoiced[ $pid ], 3 ) );
			if ( null !== $returnable && $q > (float) ( $returnable[ $pid ] ?? 0 ) + 0.0001 ) return sprintf( 'Product #%d: only %s was delivered and not yet returned, so no more can come back.', $pid, round( max( 0, (float) ( $returnable[ $pid ] ?? 0 ) ), 3 ) );
		}
		return '';
	}

	/* ================================================================== issuing */

	/**
	 * Issue the invoice for an order. One live invoice per order. $system = called by an engine
	 * whose own gate passed (quote acceptance, delivery note); otherwise needs wb_issue_invoices.
	 *
	 * @return int|WP_Error invoice id
	 */
	public static function issue_for_order( int $order_id, bool $system = false ) {
		if ( ! $system && ! current_user_can( 'wb_issue_invoices' ) ) return new WP_Error( 'wb_forbidden', 'You cannot issue invoices.' );
		$cols = WB_CCT::require_columns( 'wb_invoices', [ 'invoice_number', 'order_id', 'total', 'amount_paid', 'status', 'due_at' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		$order = WB_CCT::get( 'wb_orders', $order_id );
		if ( ! $order ) return new WP_Error( 'wb_not_found', 'Order not found.' );
		if ( 'cancelled' === $order['status'] ) return new WP_Error( 'wb_cancelled', 'That order is cancelled.' );
		if ( self::for_order( $order_id ) ) return new WP_Error( 'wb_already_invoiced', 'That order already has an invoice.' );
		$customer = WB_CCT::get( 'wb_customers', (int) $order['customer_id'] );
		if ( ! $customer ) return new WP_Error( 'wb_no_customer', 'The order\'s customer could not be found.' );
		$lines = WB_CCT::find( 'wb_order_lines', [ 'order_id' => $order_id ], [ 'order' => 'ASC', 'limit' => 1000 ] );
		if ( ! $lines ) return new WP_Error( 'wb_no_lines', 'The order has no lines to invoice.' );

		$snap = array_map( fn( $l ) => [
			'order_line_id' => (int) $l['_ID'], 'product_id' => (int) $l['product_id'], 'description' => (string) $l['description'],
			'qty' => (float) $l['qty_ordered'], 'unit_price' => (float) $l['unit_price'], 'line_total' => (float) $l['line_total'],
		], $lines );
		$tot   = self::totals( $snap, (float) get_option( 'wb_vat_rate', 15 ) );
		$now   = current_time( 'mysql' );
		$terms = (int) ( '' !== (string) ( $customer['payment_terms_days'] ?? '' ) ? $customer['payment_terms_days'] : get_option( 'wb_default_terms_days', 30 ) );

		$res = WB_Sequences::issue( 'INV', function ( string $number ) use ( $order, $customer, $snap, $tot, $now, $terms ) {
			// Lock the order and look again: two triggers at once (acceptance + a person) queue here,
			// and the second finds the first invoice. One live invoice per order.
			$o = WB_Orders::row_for_update( 'wb_orders', (int) $order['_ID'] );
			if ( ! $o || 'cancelled' === (string) $o['status'] ) return new WP_Error( 'wb_cancelled', 'That order is cancelled.' );
			if ( self::for_order( (int) $order['_ID'] ) ) return new WP_Error( 'wb_already_invoiced', 'That order already has an invoice.' );
			$id = WB_CCT::insert( 'wb_invoices', [
				'invoice_number'  => $number,
				'order_id'        => (int) $order['_ID'],
				'customer_id'     => (int) $customer['_ID'],
				'issued_at'       => $now,
				'due_at'          => self::due_date( $now, $terms ),
				'subtotal'        => $tot['subtotal'],
				'vat_rate'        => $tot['vat_rate'],
				'vat'             => $tot['vat'],
				'total'           => $tot['total'],
				'amount_paid'     => 0,
				'amount_credited' => 0,
				'status'          => 'issued',
				'lines_json'      => $snap,
			], 'invoice_issued' );
			if ( is_wp_error( $id ) ) return $id;
			WB_Orders::touchpoint( (int) $customer['_ID'], 'note', 'Invoice ' . $number . ' issued', 'invoice:' . $id );
			return $id;
		} );
		if ( ! is_wp_error( $res ) ) WB_Orders::mark_invoiced( $order_id );
		return $res;
	}

	/** The order's live (not void) invoice, or null. */
	public static function for_order( int $order_id ): ?array {
		foreach ( WB_CCT::find( 'wb_invoices', [ 'order_id' => $order_id ] ) as $inv ) {
			if ( 'void' !== $inv['status'] ) return $inv;
		}
		return null;
	}

	/** Recompute and store the derived status. Returns the status. */
	public static function recompute( int $invoice_id ): string {
		$inv = WB_CCT::get( 'wb_invoices', $invoice_id );
		if ( ! $inv || 'void' === $inv['status'] ) return (string) ( $inv['status'] ?? '' );
		$s = self::status_for( (float) $inv['total'], (float) $inv['amount_paid'], (float) ( $inv['amount_credited'] ?? 0 ), (string) $inv['due_at'], wb_today() );
		if ( $s !== $inv['status'] ) {
			WB_CCT::update( 'wb_invoices', $invoice_id, [ 'status' => $s ], 'invoice_status_' . $s );
			if ( in_array( $s, [ 'paid', 'credited' ], true ) ) WB_Orders::on_invoice_settled( (int) $inv['order_id'] );
			if ( 'overdue' === $s ) {
				WB_Notifications::notify_cap( 'wb_match_payments', 'money', sprintf( 'Invoice %s is overdue: %s outstanding.', (string) $inv['invoice_number'], number_format( self::outstanding( $inv ), 2 ) ), WB_Workspace::url( 'invoices' ), 'wb_invoices', $invoice_id );
			}
		}
		return $s;
	}

	/**
	 * Put money against an invoice. Called by WB_Payments (which holds the matching gate) with
	 * $system = true, or directly by someone with wb_match_payments. Never more than is outstanding.
	 *
	 * @return true|WP_Error
	 */
	public static function apply_payment( int $invoice_id, float $amount, int $payment_id, bool $system = false ) {
		if ( ! $system && ! current_user_can( 'wb_match_payments' ) ) return new WP_Error( 'wb_forbidden', 'You cannot apply payments.' );
		$inv = WB_CCT::get( 'wb_invoices', $invoice_id );
		if ( ! $inv ) return new WP_Error( 'wb_not_found', 'Invoice not found.' );
		if ( in_array( $inv['status'], [ 'void', 'credited' ], true ) ) return new WP_Error( 'wb_invoice_closed', 'That invoice is ' . $inv['status'] . ' and cannot take a payment.' );
		$amount = wb_money( $amount );
		if ( $amount <= 0 ) return new WP_Error( 'wb_bad_amount', 'The amount must be more than zero.' );
		if ( $amount > self::outstanding( $inv ) + 0.004 ) return new WP_Error( 'wb_overpayment', sprintf( 'Only %s is outstanding on %s.', number_format( self::outstanding( $inv ), 2 ), $inv['invoice_number'] ) );
		// One conditional UPDATE adds the money only while it still fits, so two payments put on
		// the same invoice at the same moment can never take it past its total (no lost update).
		global $wpdb;
		$t        = WB_CCT::table( 'wb_invoices' );
		$credited = WB_CCT::has_column( 'wb_invoices', 'amount_credited' ) ? 'COALESCE(`amount_credited`,0)' : '0';
		$set_mod  = WB_CCT::has_column( 'wb_invoices', 'cct_modified' ) ? $wpdb->prepare( ', `cct_modified` = %s', current_time( 'mysql' ) ) : '';
		$n = $t ? $wpdb->query( $wpdb->prepare(
			"UPDATE `{$t}` SET `amount_paid` = ROUND(COALESCE(`amount_paid`,0) + %f, 2){$set_mod} WHERE _ID = %d AND `status` NOT IN ('void','credited') AND ROUND(`total` - COALESCE(`amount_paid`,0) - {$credited}, 2) + 0.004 >= %f",
			$amount, $invoice_id, $amount
		) ) : false;
		if ( false === $n ) return new WP_Error( 'wb_update_failed', 'The payment could not be put against the invoice.' );
		if ( 1 !== (int) $n ) return new WP_Error( 'wb_overpayment', sprintf( '%s changed a moment ago and no longer has %s outstanding. Look at it again.', $inv['invoice_number'], number_format( $amount, 2 ) ) );
		$after = WB_CCT::get( 'wb_invoices', $invoice_id );
		$now_paid = wb_money( (float) ( $after['amount_paid'] ?? 0 ) );
		wb_ledger_write( 'invoice_payment_applied', 'wb_invoices', $invoice_id, [ 'amount_paid' => wb_money( $now_paid - $amount ) ], [ 'amount_paid' => $now_paid ] );
		wb_ledger_write( 'payment_allocated', 'wb_invoices', $invoice_id, null, [ 'payment_id' => $payment_id, 'amount' => $amount ] );
		self::recompute( $invoice_id );
		return true;
	}

	/** Nightly: issued / part_paid invoices past their due date become overdue. */
	public static function sweep_overdue(): void {
		foreach ( WB_CCT::find( 'wb_invoices', [ 'status' => [ 'issued', 'part_paid' ], 'due_at <' => wb_today() ], [ 'limit' => 5000 ] ) as $inv ) {
			self::recompute( (int) $inv['_ID'] );
		}
	}

	/* ================================================================== credit notes */

	/**
	 * Ask for a credit note. $lines: [ [product_id, qty, unit_price, description?], … ] — or one
	 * line with just an amount for a pricing/goodwill credit. reason: return / pricing / damage / goodwill.
	 * A return with $return_stock puts the goods back (a `return` movement) on approval.
	 *
	 * @return int|WP_Error credit note id (unnumbered until approved)
	 */
	public static function request_credit_note( int $invoice_id, string $reason, array $lines, bool $return_stock = false ) {
		if ( ! current_user_can( 'wb_issue_credit_notes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot ask for credit notes.' );
		if ( ! in_array( $reason, [ 'return', 'pricing', 'damage', 'goodwill' ], true ) ) return new WP_Error( 'wb_reason', 'Choose why the credit is being given.' );
		$cols = WB_CCT::require_columns( 'wb_credit_notes', [ 'status', 'requested_by_staff_id', 'lines_json', 'total', 'return_stock' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		$inv = WB_CCT::get( 'wb_invoices', $invoice_id );
		if ( ! $inv || 'void' === $inv['status'] ) return new WP_Error( 'wb_not_found', 'Invoice not found.' );
		$me = WB_Staff::current_staff_id();
		if ( ! $me ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record, so the request cannot carry your name.' );

		$clean = [];
		foreach ( $lines as $l ) {
			$qty   = (float) ( $l['qty'] ?? 1 );
			$price = wb_money( $l['unit_price'] ?? ( $l['amount'] ?? 0 ) );
			if ( $qty <= 0 || $price <= 0 ) return new WP_Error( 'wb_bad_line', 'Every credit line needs a quantity and an amount.' );
			$clean[] = [ 'product_id' => (int) ( $l['product_id'] ?? 0 ), 'description' => sanitize_text_field( (string) ( $l['description'] ?? '' ) ), 'qty' => $qty, 'unit_price' => $price, 'line_total' => wb_money( $qty * $price ) ];
		}
		if ( ! $clean ) return new WP_Error( 'wb_no_lines', 'Add what is being credited.' );
		if ( $return_stock && 'return' !== $reason ) $return_stock = false;
		$problem = self::credit_lines_problem( $clean, WB_CCT::json( $inv['lines_json'] ?? '' ), $return_stock ? self::returnable( $inv ) : null );
		if ( '' !== $problem ) return new WP_Error( 'wb_bad_line', $problem );
		$tot = self::totals( $clean, (float) $inv['vat_rate'] );
		// Pending requests count too: two requests can't together credit more than the invoice.
		$others  = WB_CCT::find( 'wb_credit_notes', [ 'invoice_id' => $invoice_id, 'status' => [ 'requested', 'approved' ] ], [ 'limit' => 1000 ] );
		$pending = array_sum( array_map( fn( $c ) => (float) $c['total'], $others ) );
		$capped  = self::cap_credit( $tot, (float) $inv['total'] - $pending, max( 0.01, round( 0.005 * ( count( $others ) + 1 ), 2 ) ) );   // VAT rounding: up to half a cent per note
		if ( null === $capped ) return new WP_Error( 'wb_over_credit', 'That would credit more than the invoice total.' );
		$tot = $capped;

		$id = WB_CCT::insert( 'wb_credit_notes', [
			'credit_number' => '', 'invoice_id' => $invoice_id, 'customer_id' => (int) $inv['customer_id'], 'reason' => $reason,
			'lines_json' => $clean, 'subtotal' => $tot['subtotal'], 'vat' => $tot['vat'], 'total' => $tot['total'],
			'status' => 'requested', 'requested_by_staff_id' => $me, 'return_stock' => $return_stock ? 'yes' : 'no',
		], 'credit_note_requested' );
		if ( ! is_wp_error( $id ) ) {
			WB_Notifications::notify_cap( 'wb_approve_credit_notes', 'money', sprintf( 'A credit note of %s against %s needs approval (%s).', number_format( $tot['total'], 2 ), (string) $inv['invoice_number'], $reason ),
				WB_Workspace::url( 'invoices', [ 'credits' => 1 ] ), 'wb_credit_notes', $id, [ get_current_user_id() ] );
		}
		return $id;
	}

	/** [ product_id => qty delivered on the invoice's order and not already coming back on another credit note ]. */
	private static function returnable( array $inv ): array {
		$out = [];
		foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => (int) $inv['order_id'] ], [ 'limit' => 1000 ] ) as $l ) {
			$out[ (int) $l['product_id'] ] = ( $out[ (int) $l['product_id'] ] ?? 0 ) + (float) $l['qty_delivered'];
		}
		foreach ( WB_CCT::find( 'wb_credit_notes', [ 'invoice_id' => (int) $inv['_ID'], 'status' => [ 'requested', 'approved' ], 'return_stock' => 'yes' ], [ 'limit' => 1000 ] ) as $c ) {
			foreach ( WB_CCT::json( $c['lines_json'] ) as $l ) {
				$pid = (int) ( $l['product_id'] ?? 0 );
				if ( $pid ) $out[ $pid ] = ( $out[ $pid ] ?? 0 ) - (float) ( $l['qty'] ?? 0 );
			}
		}
		return $out;
	}

	/** The last payment put against an invoice (to pair a credit note with), including split payments. */
	private static function last_payment_for( int $invoice_id ): ?array {
		$p = WB_CCT::first( 'wb_payments', [ 'invoice_id' => $invoice_id ], [ 'orderby' => 'matched_at', 'order' => 'DESC' ] );
		if ( ! WB_CCT::has_column( 'wb_payments', 'allocations_json' ) ) return $p;
		// A payment split over several invoices keeps its first invoice in invoice_id; the others
		// are in allocations_json (M11).
		$q = WB_CCT::first( 'wb_payments', [ 'allocations_json LIKE' => '%"invoice_id":' . $invoice_id . ',%' ], [ 'orderby' => 'matched_at', 'order' => 'DESC' ] );
		if ( ! $p ) return $q;
		if ( ! $q ) return $p;
		return (string) $q['matched_at'] > (string) $p['matched_at'] ? $q : $p;
	}

	/**
	 * Approve: needs wb_approve_credit_notes and a different person from the one who asked. Takes
	 * the CRN number, credits the invoice, pairs it with the invoice's last payment, and for a
	 * return puts the goods back.
	 *
	 * @return int|WP_Error credit note id
	 */
	public static function approve_credit_note( int $credit_id ) {
		if ( ! current_user_can( 'wb_approve_credit_notes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot approve credit notes.' );
		$cn = WB_CCT::get( 'wb_credit_notes', $credit_id );
		if ( ! $cn || 'requested' !== $cn['status'] ) return new WP_Error( 'wb_not_pending', 'That credit note is not waiting for approval.' );
		$me = WB_Staff::current_staff_id();
		if ( ! $me ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record.' );
		if ( $me === (int) $cn['requested_by_staff_id'] ) return new WP_Error( 'wb_self_approval', 'You cannot approve a credit note you asked for — someone else must.' );
		$inv = WB_CCT::get( 'wb_invoices', (int) $cn['invoice_id'] );
		if ( ! $inv || 'void' === $inv['status'] ) return new WP_Error( 'wb_not_found', 'The invoice is gone.' );
		if ( (float) $cn['total'] > (float) $inv['total'] - (float) ( $inv['amount_credited'] ?? 0 ) + 0.004 ) return new WP_Error( 'wb_over_credit', 'The invoice has already been credited past this amount.' );
		$last_payment = self::last_payment_for( (int) $inv['_ID'] );

		// Everything inside the CRN transaction, on rows read again under a lock: a double click
		// or two approvers queue here; the second finds the note approved. The number, the credit
		// on the invoice and the goods coming back all happen together or not at all.
		$res = WB_Sequences::issue( 'CRN', function ( string $number ) use ( $cn, $inv, $me, $last_payment, $credit_id ) {
			$c = WB_Orders::row_for_update( 'wb_credit_notes', (int) $cn['_ID'] );
			if ( ! $c || 'requested' !== (string) $c['status'] ) return new WP_Error( 'wb_not_pending', 'That credit note has just been decided by someone else.' );
			$i = WB_Orders::row_for_update( 'wb_invoices', (int) $inv['_ID'] );
			if ( ! $i || 'void' === (string) $i['status'] ) return new WP_Error( 'wb_not_found', 'The invoice is gone.' );
			$credited = (float) ( $i['amount_credited'] ?? 0 );
			if ( (float) $c['total'] > (float) $i['total'] - $credited + 0.004 ) return new WP_Error( 'wb_over_credit', 'The invoice has already been credited past this amount.' );
			$ok = WB_CCT::update( 'wb_credit_notes', (int) $c['_ID'], [
				'credit_number' => $number, 'status' => 'approved', 'approved_by_staff_id' => $me, 'approved_at' => current_time( 'mysql' ),
				'payment_id' => $last_payment ? (int) $last_payment['_ID'] : 0,
			], 'credit_note_approved' );
			if ( is_wp_error( $ok ) ) return $ok;
			$ok = WB_CCT::update( 'wb_invoices', (int) $i['_ID'], [ 'amount_credited' => wb_money( $credited + (float) $c['total'] ) ], 'invoice_credited' );
			if ( is_wp_error( $ok ) ) return $ok;
			if ( 'yes' === (string) ( $c['return_stock'] ?? 'no' ) ) {
				foreach ( WB_CCT::json( $c['lines_json'] ) as $l ) {
					if ( empty( $l['product_id'] ) ) continue;
					$m = WB_Stock::move( (int) $l['product_id'], (float) $l['qty'], 'return', [ 'system' => true, 'ref_type' => 'credit_note', 'ref_id' => $credit_id, 'reason' => 'Returned on credit note ' . $number ] );
					if ( is_wp_error( $m ) ) return new WP_Error( $m->get_error_code(), 'The goods could not be booked back into stock, so the credit note was not approved: ' . $m->get_error_message() );
				}
			}
			return (int) $c['_ID'];
		} );
		if ( is_wp_error( $res ) ) return $res;

		self::recompute( (int) $inv['_ID'] );
		WB_Notifications::resolve( 'wb_credit_notes', $credit_id );
		// Paid and then credited: the customer may now be owed money back. Say so (in the app);
		// a person decides whether to refund or hold it as credit (M9).
		$after = WB_CCT::get( 'wb_invoices', (int) $inv['_ID'] );
		$back  = $after ? self::owed_back( $after ) : 0.0;
		if ( $back > 0.004 ) {
			wb_ledger_write( 'customer_owed_back', 'wb_invoices', (int) $inv['_ID'], null, [ 'amount' => $back, 'credit_note_id' => $credit_id, 'customer_id' => (int) $inv['customer_id'] ] );
			WB_Notifications::notify_cap( 'wb_match_payments', 'money', sprintf( 'After the credit note on %s the customer has paid %s more than they owe. Refund it or keep it as credit on their account.', (string) $inv['invoice_number'], number_format( $back, 2 ) ),
				WB_Workspace::url( 'invoices' ), 'wb_invoices', (int) $inv['_ID'] );
		}
		return $credit_id;
	}

	/** @return true|WP_Error */
	public static function decline_credit_note( int $credit_id, string $why = '' ) {
		if ( ! current_user_can( 'wb_approve_credit_notes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot decide credit notes.' );
		$cn = WB_CCT::get( 'wb_credit_notes', $credit_id );
		if ( ! $cn || 'requested' !== $cn['status'] ) return new WP_Error( 'wb_not_pending', 'That credit note is not waiting for approval.' );
		if ( WB_Staff::current_staff_id() === (int) $cn['requested_by_staff_id'] ) return new WP_Error( 'wb_self_approval', 'Someone else must decide your own request.' );
		$res = WB_CCT::update( 'wb_credit_notes', $credit_id, [ 'status' => 'declined', 'approved_by_staff_id' => WB_Staff::current_staff_id(), 'approved_at' => current_time( 'mysql' ) ], 'credit_note_declined' );
		if ( true === $res && '' !== $why ) wb_ledger_write( 'credit_note_decline_reason', 'wb_credit_notes', $credit_id, null, [ 'why' => sanitize_text_field( $why ) ] );
		WB_Notifications::resolve( 'wb_credit_notes', $credit_id );
		return $res;
	}
}
