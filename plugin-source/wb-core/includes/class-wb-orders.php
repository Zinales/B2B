<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Orders — the document chain and THE ORDER STATE MACHINE (DATA-ARCHITECTURE §7).
 *
 *   quote.sent ─accept→ order.accepted ─invoice→ order.invoiced
 *     → awaiting_payment ─payment matched (full, or terms allow)→ paid
 *     → ready (stock reserved) ─delivery note issued→ part_delivered | delivered
 *     → closed (all lines delivered, invoice paid or within terms)
 *   cancelled from any pre-delivery state.
 *
 * Nothing is retyped: an order is built from the accepted quote's FROZEN lines (unit_price,
 * price_source, floor_price, below_floor), the invoice from the order, the delivery note from the
 * order. The release gate is one function: may_release() — terms + credit limit + payment.
 *
 * Invoice trigger (option wb_invoice_trigger): "acceptance" invoices the moment the quote is
 * accepted; "dispatch" invoices when the first delivery note is issued. A CASH customer
 * (payment_terms_days = 0) is always invoiced on acceptance — they cannot pay before collection
 * without an invoice to pay.
 */
class WB_Orders {

	/** Allowed moves. ready is reached only through release() (the gate), closed only through close(). */
	const ALLOWED = [
		'accepted'         => [ 'invoiced', 'ready', 'cancelled' ],
		'invoiced'         => [ 'awaiting_payment', 'paid', 'ready', 'cancelled' ],
		'awaiting_payment' => [ 'paid', 'ready', 'cancelled' ],
		'paid'             => [ 'ready', 'cancelled' ],
		'ready'            => [ 'part_delivered', 'delivered', 'cancelled' ],
		'part_delivered'   => [ 'delivered' ],
		'delivered'        => [ 'closed' ],
		'closed'           => [],
		'cancelled'        => [],
	];

	const PRE_DELIVERY = [ 'accepted', 'invoiced', 'awaiting_payment', 'paid', 'ready' ];

	const TOKEN_PREFIX = 'wb_qa_';

	public static function init(): void {
		add_action( 'wb_nightly', [ __CLASS__, 'expire_quotes' ], 15 );
	}

	/* ================================================================== pure */

	public static function can_transition( string $from, string $to ): bool {
		return in_array( $to, self::ALLOWED[ $from ] ?? [], true );
	}

	/**
	 * THE RELEASE GATE, as plain data. May goods leave (be collected / delivered) for this order?
	 * $invoice = the order's invoice or null; $other_outstanding = what the customer owes on OTHER
	 * invoices; $has_overdue = any of the customer's invoices overdue; $order_total = used when
	 * not yet invoiced. Returns [ bool, reason ].
	 *   on_hold / closed account → no.   Invoice paid → yes.   Cash customer unpaid → no.
	 *   Terms customer: no overdue invoices, and everything owed incl. this order within credit_limit.
	 */
	public static function may_release_pure( array $customer, ?array $invoice, float $other_outstanding, bool $has_overdue, float $order_total ): array {
		if ( 'open' !== (string) ( $customer['account_status'] ?? 'open' ) ) return [ false, 'account_' . ( $customer['account_status'] ?? '' ) ];
		if ( $invoice && 'paid' === (string) $invoice['status'] ) return [ true, 'paid' ];
		if ( $invoice && 'credited' === (string) $invoice['status'] ) return [ false, 'credited' ];   // credited in full: nothing was paid for these goods
		$terms = (int) ( $customer['payment_terms_days'] ?? 0 );
		if ( $terms <= 0 ) return [ false, 'cash_unpaid' ];
		$limit = (float) ( $customer['credit_limit'] ?? 0 );
		if ( $limit <= 0 ) return [ false, 'no_credit_limit' ];   // terms without a limit: fail closed
		if ( $has_overdue ) return [ false, 'overdue' ];
		$this_one = $invoice ? WB_Invoices::outstanding( $invoice ) : $order_total;
		if ( $other_outstanding + $this_one > $limit + 0.004 ) return [ false, 'over_limit' ];
		return [ true, 'within_terms' ];
	}

	/** Plain-English words for a gate reason. */
	public static function gate_text( string $reason ): string {
		$t = [
			'paid' => 'Paid in full.', 'within_terms' => 'On account, within terms and credit limit.',
			'cash_unpaid' => 'Cash customer: the invoice must be paid before goods leave.',
			'no_credit_limit' => 'This customer has terms but no credit limit set, so goods cannot leave before payment.',
			'overdue' => 'This customer has an overdue invoice.', 'over_limit' => 'This order would take the customer over their credit limit.',
			'account_on_hold' => 'The account is on hold.', 'account_closed' => 'The account is closed.',
			'credited' => 'The invoice has been credited in full, so these goods have not been paid for.',
		];
		return $t[ $reason ] ?? 'Goods cannot leave yet.';
	}

	/**
	 * Re-read a CCT row with a row lock (SELECT … FOR UPDATE). Only meaningful inside a transaction
	 * (a WB_Sequences::issue callback): two people pressing the same button then queue on the row
	 * and the second one sees the first one's result. Null when the table or row is missing.
	 */
	public static function row_for_update( string $slug, int $id ): ?array {
		$t = WB_CCT::table( $slug );
		if ( ! $t || $id <= 0 ) return null;
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE _ID = %d FOR UPDATE", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** The quote-line columns pricing depends on. Missing any → refuse (fail closed). */
	const QUOTE_LINE_COLS = [ 'quote_id', 'product_id', 'unit_price', 'price_source', 'floor_price', 'below_floor', 'out_of_date', 'list_price', 'cost_price', 'line_total', 'approval_id', 'priced_by_user_id' ];

	/* ================================================================== quotes */

	/**
	 * A draft quote. $lines: [ [product_id, qty, manual_price?], … ]. Each line is priced by both
	 * checks and the result frozen onto the line. Numbered QUO-YYYY-…
	 *
	 * @return int|WP_Error quote id
	 */
	public static function create_quote( int $customer_id, array $lines, array $args = [] ) {
		if ( ! current_user_can( 'wb_create_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot write quotes.' );
		return self::build_quote( $customer_id, $lines, $args );
	}

	/**
	 * A customer asks for a quote in the portal (WB_Portal has already scoped the login to this
	 * customer and contact). Prices come from both checks — never typed by the customer — and the
	 * quote stays a DRAFT for the rep, who is told in the app. Nothing is sent outside.
	 *
	 * @return int|WP_Error quote id
	 */
	public static function request_quote_portal( int $customer_id, int $contact_id, array $lines, string $note ) {
		$c = WB_CCT::get( 'wb_contacts', $contact_id );
		if ( ! current_user_can( 'wb_portal' ) || ! $c || (int) $c['customer_id'] !== $customer_id || (int) $c['portal_wp_user_id'] !== get_current_user_id() ) return new WP_Error( 'wb_forbidden', 'Your login is not linked to that company.' );
		$lines = array_map( fn( $l ) => [ 'product_id' => (int) $l['product_id'], 'qty' => (float) $l['qty'] ], $lines );   // never a customer-typed price
		$qid   = self::build_quote( $customer_id, $lines, [ 'contact_id' => $contact_id, 'notes' => '' ] );
		if ( is_wp_error( $qid ) ) return $qid;
		if ( '' !== trim( $note ) ) wb_ledger_write( 'quote_request_note', 'wb_quotes', (int) $qid, null, [ 'note' => $note ] );
		$q    = WB_CCT::get( 'wb_quotes', (int) $qid );
		$cust = WB_CCT::get( 'wb_customers', $customer_id );
		self::touchpoint( $customer_id, 'quote_requested', 'Asked for a quote in the portal' . ( '' !== trim( $note ) ? ': ' . $note : '' ), 'quote:' . $qid, $contact_id );
		$msg  = sprintf( '%s at %s asked for a quote in the portal. Draft %s is ready to check and send.', trim( $c['first_name'] . ' ' . $c['last_name'] ), (string) ( $cust['name'] ?? '' ), (string) ( $q['quote_number'] ?? '' ) );
		$link = WB_Workspace::url( 'quotes', [ 'quote' => (int) $qid ] );
		$rep  = (int) ( $q['rep_staff_id'] ?? 0 ) ? WB_Staff::user_for_staff( (int) $q['rep_staff_id'] ) : 0;
		if ( ! $rep || ! WB_Notifications::notify( $rep, 'orders', $msg, $link, 'wb_quotes', (int) $qid ) ) WB_Notifications::notify_cap( 'wb_create_quotes', 'orders', $msg, $link, 'wb_quotes', (int) $qid );
		return (int) $qid;
	}

	/** The quote itself (the capability is checked by the caller). @return int|WP_Error */
	private static function build_quote( int $customer_id, array $lines, array $args = [] ) {
		$cols = WB_CCT::require_columns( 'wb_quote_lines', self::QUOTE_LINE_COLS );
		if ( is_wp_error( $cols ) ) return $cols;
		$customer = WB_CCT::get( 'wb_customers', $customer_id );
		if ( ! $customer ) return new WP_Error( 'wb_no_customer', 'Choose the customer.' );
		if ( 'closed' === (string) ( $customer['account_status'] ?? '' ) ) return new WP_Error( 'wb_closed', 'That account is closed.' );
		$today  = wb_today();
		$priced = [];
		foreach ( $lines as $l ) {
			$qty = (float) ( $l['qty'] ?? 0 );
			if ( $qty <= 0 ) return new WP_Error( 'wb_bad_line', 'Every line needs a quantity.' );
			$manual = ( isset( $l['manual_price'] ) && '' !== (string) $l['manual_price'] ) ? (float) $l['manual_price'] : null;
			$p      = WB_Pricing::price_for( $customer_id, (int) ( $l['product_id'] ?? 0 ), $qty, $today, $manual );
			if ( is_wp_error( $p ) ) return $p;
			$priced[] = [ 'product_id' => (int) $l['product_id'], 'pricing' => $p ];
		}
		if ( ! $priced ) return new WP_Error( 'wb_no_lines', 'A quote needs at least one line.' );
		$validity = (int) WB_Pricing::policy()['quote_validity_days'];
		$valid    = sanitize_text_field( (string) ( $args['valid_until'] ?? '' ) ) ?: gmdate( 'Y-m-d', strtotime( $today . ' +' . max( 1, $validity ) . ' days' ) );

		return WB_Sequences::issue( 'QUO', function ( string $number ) use ( $customer, $priced, $args, $valid ) {
			$qid = WB_CCT::insert( 'wb_quotes', [
				'quote_number' => $number, 'customer_id' => (int) $customer['_ID'], 'contact_id' => (int) ( $args['contact_id'] ?? 0 ),
				'status' => 'draft', 'valid_until' => $valid, 'subtotal' => 0, 'vat' => 0, 'total' => 0, 'pricing_check_status' => 'passed',
				'rep_staff_id' => WB_Staff::current_staff_id() ?: (int) ( $customer['rep_staff_id'] ?? 0 ),
				'notes_to_customer' => sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) ),
			], 'quote_created' );
			if ( is_wp_error( $qid ) ) return $qid;
			foreach ( $priced as $pl ) {
				$r = self::insert_quote_line( $qid, $pl['product_id'], $pl['pricing'] );
				if ( is_wp_error( $r ) ) return $r;
			}
			self::retotal_quote( $qid );
			return $qid;
		} );
	}

	/** Freeze both checks onto a quote line. */
	private static function insert_quote_line( int $quote_id, int $product_id, array $p ) {
		$cols = WB_CCT::require_columns( 'wb_quote_lines', self::QUOTE_LINE_COLS );
		if ( is_wp_error( $cols ) ) return $cols;
		$product = WB_CCT::get( 'wb_products', $product_id );
		$sheet   = WB_Documents::current_datasheet( $product_id );
		return WB_CCT::insert( 'wb_quote_lines', [
			'quote_id' => $quote_id, 'product_id' => $product_id,
			'description'        => trim( (string) ( $product['sku'] ?? '' ) . ' ' . (string) ( $product['name'] ?? '' ) ),
			'qty'                => (float) $p['qty'],
			'unit_price'         => (float) $p['unit_price'],
			'price_source'       => (string) $p['price_source'],
			'floor_price'        => (float) $p['floor_price'],
			'below_floor'        => $p['below_floor'] ? 'yes' : 'no',
			'out_of_date'        => $p['out_of_date'] ? 'yes' : 'no',
			'list_price'         => (float) $p['list_price'],
			'cost_price'         => (float) $p['cost_price'],
			'discount_pct'       => (float) $p['discount_pct'],
			'line_total'         => (float) $p['line_total'],
			'spec_snapshot_json' => (string) ( $product['spec_json'] ?? '' ),
			'datasheet_doc_id'   => $sheet ? (int) $sheet['_ID'] : 0,
			'priced_by_user_id'  => get_current_user_id(),   // who set this price: they may not approve it (M20)
		], 'quote_line_priced' );
	}

	/** Add a line to a draft quote. @return int|WP_Error */
	public static function add_quote_line( int $quote_id, int $product_id, float $qty, ?float $manual_price = null ) {
		if ( ! current_user_can( 'wb_create_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot write quotes.' );
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		if ( ! $q || 'draft' !== $q['status'] ) return new WP_Error( 'wb_quote_locked', 'Only a draft quote can be changed. A sent quote is a record of what was offered.' );
		$p = WB_Pricing::price_for( (int) $q['customer_id'], $product_id, $qty, wb_today(), $manual_price );
		if ( is_wp_error( $p ) ) return $p;
		$id = self::insert_quote_line( $quote_id, $product_id, $p );
		if ( ! is_wp_error( $id ) ) self::retotal_quote( $quote_id );
		return $id;
	}

	/** Re-price one draft line at a typed price (source "manual"); check two runs again. @return true|WP_Error */
	public static function set_line_price( int $line_id, float $price ) {
		if ( ! current_user_can( 'wb_create_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot write quotes.' );
		$line = WB_CCT::get( 'wb_quote_lines', $line_id );
		$q    = $line ? WB_CCT::get( 'wb_quotes', (int) $line['quote_id'] ) : null;
		if ( ! $q || 'draft' !== $q['status'] ) return new WP_Error( 'wb_quote_locked', 'Only a draft quote can be changed.' );
		$cols = WB_CCT::require_columns( 'wb_quote_lines', self::QUOTE_LINE_COLS );
		if ( is_wp_error( $cols ) ) return $cols;
		$p = WB_Pricing::price_for( (int) $q['customer_id'], (int) $line['product_id'], (float) $line['qty'], wb_today(), $price );
		if ( is_wp_error( $p ) ) return $p;
		$res = WB_CCT::update( 'wb_quote_lines', $line_id, [
			'unit_price' => (float) $p['unit_price'], 'price_source' => 'manual', 'floor_price' => (float) $p['floor_price'],
			'below_floor' => $p['below_floor'] ? 'yes' : 'no', 'out_of_date' => $p['out_of_date'] ? 'yes' : 'no',
			'list_price' => (float) $p['list_price'],
			'cost_price' => (float) $p['cost_price'], 'discount_pct' => (float) $p['discount_pct'], 'line_total' => (float) $p['line_total'],
			'priced_by_user_id' => get_current_user_id(),
			'approval_id' => 0,   // a new price needs its own approval; the old decision stays in wb_pricing_approvals
		], 'quote_line_repriced' );
		if ( true === $res ) self::retotal_quote( (int) $q['_ID'] );
		return $res;
	}

	/**
	 * What a line edit means for its price (1.5.0). Pure. $line: the frozen line; $qty; $typed: the
	 * price in the box (null when left empty). Returns [ changed, manual price or null ]:
	 *   a typed price that differs from the line's → that price, manual;
	 *   an unchanged price on a manual line → the same price, still manual;
	 *   otherwise → null, so the customer's rules price the new quantity.
	 */
	public static function line_edit( array $line, float $qty, ?float $typed ): array {
		$price   = (float) $line['unit_price'];
		$manual  = 'manual' === (string) ( $line['price_source'] ?? '' );
		$retyped = null !== $typed && abs( $typed - $price ) > 0.004;
		$requty  = abs( $qty - (float) $line['qty'] ) > 0.0001;
		if ( ! $retyped && ! $requty ) return [ false, null ];
		if ( $retyped ) return [ true, round( $typed, 2 ) ];
		return [ true, $manual ? $price : null ];
	}

	/**
	 * Change a draft line's quantity and/or price, and put it through both checks again (1.5.0, the
	 * quote line editor). A quantity of 0 removes the line. @return 'changed'|'same'|WP_Error
	 */
	public static function update_quote_line( int $line_id, float $qty, ?float $typed_price = null ) {
		if ( ! current_user_can( 'wb_create_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot write quotes.' );
		$line = WB_CCT::get( 'wb_quote_lines', $line_id );
		$q    = $line ? WB_CCT::get( 'wb_quotes', (int) $line['quote_id'] ) : null;
		if ( ! $q || 'draft' !== $q['status'] ) return new WP_Error( 'wb_quote_locked', 'Only a draft quote can be changed. A sent quote is a record of what was offered.' );
		if ( $qty < 0 ) return new WP_Error( 'wb_bad_line', 'A quantity cannot be less than nothing.' );
		if ( $qty <= 0 ) { $r = self::remove_quote_line( $line_id ); return is_wp_error( $r ) ? $r : 'changed'; }
		[ $changed, $manual ] = self::line_edit( $line, $qty, $typed_price );
		if ( ! $changed ) return 'same';
		$p = WB_Pricing::price_for( (int) $q['customer_id'], (int) $line['product_id'], $qty, wb_today(), $manual );
		if ( is_wp_error( $p ) ) return $p;
		$res = WB_CCT::update( 'wb_quote_lines', $line_id, [
			'qty' => $qty, 'unit_price' => (float) $p['unit_price'], 'price_source' => null !== $manual ? 'manual' : (string) $p['price_source'], 'floor_price' => (float) $p['floor_price'],
			'below_floor' => $p['below_floor'] ? 'yes' : 'no', 'out_of_date' => $p['out_of_date'] ? 'yes' : 'no', 'list_price' => (float) $p['list_price'],
			'cost_price' => (float) $p['cost_price'], 'discount_pct' => (float) $p['discount_pct'], 'line_total' => (float) $p['line_total'],
			'priced_by_user_id' => get_current_user_id(), 'approval_id' => 0,   // a new price needs its own approval
		], 'quote_line_repriced' );
		if ( is_wp_error( $res ) ) return $res;
		self::retotal_quote( (int) $q['_ID'] );
		return 'changed';
	}

	/** Remove a draft line (soft: archived). */
	public static function remove_quote_line( int $line_id ) {
		if ( ! current_user_can( 'wb_create_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot write quotes.' );
		$line = WB_CCT::get( 'wb_quote_lines', $line_id );
		$q    = $line ? WB_CCT::get( 'wb_quotes', (int) $line['quote_id'] ) : null;
		if ( ! $q || 'draft' !== $q['status'] ) return new WP_Error( 'wb_quote_locked', 'Only a draft quote can be changed.' );
		$res = WB_CCT::set_status( 'wb_quote_lines', $line_id, 'archived', 'removed from draft' );
		if ( true === $res ) self::retotal_quote( (int) $q['_ID'] );
		return $res;
	}

	private static function retotal_quote( int $quote_id ): void {
		$lines = WB_CCT::find( 'wb_quote_lines', [ 'quote_id' => $quote_id ], [ 'limit' => 1000 ] );
		$tot   = WB_Invoices::totals( $lines, (float) get_option( 'wb_vat_rate', 15 ) );
		WB_CCT::update( 'wb_quotes', $quote_id, [ 'subtotal' => $tot['subtotal'], 'vat' => $tot['vat'], 'total' => $tot['total'] ], 'quote_totalled' );
		WB_Pricing::refresh_quote_status( $quote_id );
	}

	/**
	 * Mark a quote sent and hand back the customer's acceptance link (7 days, single use). This
	 * sends NOTHING — the person sends the quote and the link themselves. Refused while any line
	 * needs a price approval that hasn't been given.
	 *
	 * @return string|WP_Error the acceptance URL
	 */
	public static function send_quote( int $quote_id ) {
		if ( ! current_user_can( 'wb_send_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot send quotes.' );
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		if ( ! $q || 'draft' !== $q['status'] ) return new WP_Error( 'wb_not_draft', 'Only a draft quote can be sent.' );
		if ( ! WB_CCT::count( 'wb_quote_lines', [ 'quote_id' => $quote_id ] ) ) return new WP_Error( 'wb_no_lines', 'The quote has no lines.' );
		$status = WB_Pricing::refresh_quote_status( $quote_id );
		if ( 'needs_approval' === $status ) return new WP_Error( 'wb_needs_approval', 'Some prices are below the floor, out of date, or have no cost on file. Ask for approval first.' );
		$valid = (string) $q['valid_until'] >= wb_today() ? (string) $q['valid_until'] : gmdate( 'Y-m-d', strtotime( wb_today() . ' +' . (int) WB_Pricing::policy()['quote_validity_days'] . ' days' ) );
		$res = WB_CCT::update( 'wb_quotes', $quote_id, [ 'status' => 'sent', 'sent_at' => wb_now(), 'valid_until' => $valid ], 'quote_sent' );
		if ( is_wp_error( $res ) ) return $res;
		self::touchpoint( (int) $q['customer_id'], 'quote_sent', 'Quote ' . $q['quote_number'] . ' sent', 'quote:' . $quote_id, (int) $q['contact_id'] );
		return self::acceptance_url( $quote_id );
	}

	/** A fresh single-use acceptance link (also used to re-issue one). @return string|WP_Error */
	public static function acceptance_url( int $quote_id ) {
		if ( ! current_user_can( 'wb_send_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot send quotes.' );
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		if ( ! $q || 'sent' !== $q['status'] ) return new WP_Error( 'wb_not_sent', 'Only a sent quote can be accepted.' );
		$token = strtolower( wp_generate_password( 40, false, false ) );
		set_transient( self::TOKEN_PREFIX . hash( 'sha256', $token ), [ 'quote' => $quote_id, 'exp' => time() + 7 * DAY_IN_SECONDS ], 7 * DAY_IN_SECONDS );
		wb_ledger_write( 'quote_accept_link_issued', 'wb_quotes', $quote_id, null, [ 'expires_days' => 7 ] );
		return add_query_arg( 'token', $token, rest_url( 'wb/v1/quote-accept' ) );
	}

	/** Look at a token without using it (the confirmation page). */
	public static function peek_token( string $token ): ?array {
		$token = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) );
		if ( strlen( $token ) < 32 ) return null;
		$d = get_transient( self::TOKEN_PREFIX . hash( 'sha256', $token ) );
		return ( is_array( $d ) && (int) $d['exp'] >= time() ) ? $d : null;
	}

	/**
	 * The customer accepts through the link. The token is the credential and is used up here.
	 *
	 * @return int|WP_Error order id
	 */
	public static function accept_by_token( string $token, string $accepted_by_name ) {
		$d = self::peek_token( $token );
		if ( ! $d ) return new WP_Error( 'wb_bad_token', 'This acceptance link has expired or has already been used.' );
		delete_transient( self::TOKEN_PREFIX . hash( 'sha256', (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) ) ) );   // single use, even if acceptance fails
		$name = mb_substr( sanitize_text_field( $accepted_by_name ), 0, 120 );
		if ( '' === $name ) return new WP_Error( 'wb_no_name', 'Type your name to accept.' );
		return self::do_accept( (int) $d['quote'], 'link: ' . $name, 0 );
	}

	/**
	 * Staff record an acceptance (a signed PDF, an email). $acceptance_doc_id = the signed quote filed in documents.
	 *
	 * @return int|WP_Error order id
	 */
	public static function accept_quote( int $quote_id, string $accepted_by, int $acceptance_doc_id = 0 ) {
		if ( ! current_user_can( 'wb_manage_orders' ) ) return new WP_Error( 'wb_forbidden', 'You cannot record acceptances.' );
		if ( '' === trim( $accepted_by ) ) return new WP_Error( 'wb_no_name', 'Record who accepted (a contact, or "signed PDF").' );
		return self::do_accept( $quote_id, sanitize_text_field( $accepted_by ), $acceptance_doc_id );
	}

	/**
	 * A portal login accepts its own company's sent quote: accepted_by = the contact id (as
	 * DATA-ARCHITECTURE says) and the touchpoint names the contact. WB_Portal has scoped the login;
	 * this checks it again (fail closed).
	 *
	 * @return int|WP_Error order id
	 */
	public static function accept_quote_portal( int $quote_id, int $contact_id, string $who ) {
		$c = WB_CCT::get( 'wb_contacts', $contact_id );
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		if ( ! current_user_can( 'wb_portal' ) || ! $c || ! $q || (int) $c['portal_wp_user_id'] !== get_current_user_id() || (int) $c['customer_id'] !== (int) $q['customer_id'] ) return new WP_Error( 'wb_forbidden', 'That quote is not yours to accept.' );
		return self::do_accept( $quote_id, (string) $contact_id, 0, $contact_id, $who . ' (portal)' );
	}

	/** A portal login declines its own company's sent quote. @return true|WP_Error */
	public static function decline_quote_portal( int $quote_id, int $contact_id, string $why = '' ) {
		$c = WB_CCT::get( 'wb_contacts', $contact_id );
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		if ( ! current_user_can( 'wb_portal' ) || ! $c || ! $q || (int) $c['portal_wp_user_id'] !== get_current_user_id() || (int) $c['customer_id'] !== (int) $q['customer_id'] ) return new WP_Error( 'wb_forbidden', 'That quote is not yours to decline.' );
		if ( 'sent' !== $q['status'] ) return new WP_Error( 'wb_closed', 'That quote is already closed.' );
		$res = WB_CCT::update( 'wb_quotes', $quote_id, [ 'status' => 'declined' ], 'quote_declined_portal' );
		if ( is_wp_error( $res ) ) return $res;
		self::touchpoint( (int) $q['customer_id'], 'quote_declined', 'Quote ' . $q['quote_number'] . ' declined in the portal' . ( '' !== trim( $why ) ? ': ' . sanitize_text_field( $why ) : '' ), 'quote:' . $quote_id, $contact_id );
		$rep = (int) $q['rep_staff_id'] ? WB_Staff::user_for_staff( (int) $q['rep_staff_id'] ) : 0;
		if ( $rep ) WB_Notifications::notify( $rep, 'orders', sprintf( 'Quote %s was declined in the portal.', (string) $q['quote_number'] ), WB_Workspace::url( 'quotes', [ 'quote' => $quote_id ] ), 'wb_quotes', $quote_id );
		return true;
	}

	/** Quote → order (+ lines, frozen prices) → reservations → invoice when the trigger says so. */
	private static function do_accept( int $quote_id, string $accepted_by, int $doc_id, int $contact_id = 0, string $who = '' ) {
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		if ( ! $q ) return new WP_Error( 'wb_not_found', 'Quote not found.' );
		if ( 'sent' !== $q['status'] ) return new WP_Error( 'wb_not_sent', 'This quote is ' . $q['status'] . ' and can no longer be accepted.' );
		if ( (string) $q['valid_until'] < wb_today() ) {
			WB_CCT::update( 'wb_quotes', $quote_id, [ 'status' => 'expired' ], 'quote_expired' );
			return new WP_Error( 'wb_expired', 'This quote has expired. Ask us for a new one.' );
		}
		if ( ! in_array( WB_Pricing::refresh_quote_status( $quote_id ), [ 'passed', 'approved' ], true ) ) return new WP_Error( 'wb_needs_approval', 'This quote has prices waiting for approval.' );
		$customer = WB_CCT::get( 'wb_customers', (int) $q['customer_id'] );
		if ( ! $customer || 'open' !== (string) ( $customer['account_status'] ?? 'open' ) ) return new WP_Error( 'wb_account', 'The account is not open. Please contact us.' );
		$lines = WB_CCT::find( 'wb_quote_lines', [ 'quote_id' => $quote_id ], [ 'order' => 'ASC', 'limit' => 1000 ] );

		$order_id = WB_Sequences::issue( 'ORD', function ( string $number ) use ( $q, $lines, $accepted_by, $doc_id, $customer ) {
			// Read the quote again under a row lock: a second Accept (double click, link + portal)
			// waits here, then finds it converted and stops — one quote, one order.
			$fresh = self::row_for_update( 'wb_quotes', (int) $q['_ID'] );
			if ( ! $fresh || 'sent' !== (string) $fresh['status'] ) return new WP_Error( 'wb_not_sent', 'This quote has already been accepted or closed, so it was not accepted again.' );
			$oid = WB_CCT::insert( 'wb_orders', [
				'order_number' => $number, 'quote_id' => (int) $q['_ID'], 'customer_id' => (int) $q['customer_id'], 'status' => 'accepted',
				'fulfilment' => 'collection', 'subtotal' => (float) $q['subtotal'], 'vat' => (float) $q['vat'], 'total' => (float) $q['total'],
			], 'order_created' );
			if ( is_wp_error( $oid ) ) return $oid;
			foreach ( $lines as $l ) {
				$r = WB_CCT::insert( 'wb_order_lines', [
					'order_id' => $oid, 'quote_line_id' => (int) $l['_ID'], 'product_id' => (int) $l['product_id'], 'description' => (string) $l['description'],
					'unit_price' => (float) $l['unit_price'], 'price_source' => (string) $l['price_source'], 'floor_price' => (float) $l['floor_price'],
					'below_floor' => (string) $l['below_floor'], 'line_total' => (float) $l['line_total'],
					'qty_ordered' => (float) $l['qty'], 'qty_reserved' => 0, 'qty_delivered' => 0, 'qty_backordered' => 0,
				], 'order_line_created' );
				if ( is_wp_error( $r ) ) return $r;
			}
			$ok = WB_CCT::update( 'wb_quotes', (int) $q['_ID'], [ 'status' => 'converted', 'accepted_at' => wb_now(), 'accepted_by' => $accepted_by, 'acceptance_doc_id' => $doc_id ], 'quote_accepted' );
			return is_wp_error( $ok ) ? $ok : $oid;
		} );
		if ( is_wp_error( $order_id ) ) return $order_id;

		self::reserve( (int) $order_id );
		self::touchpoint( (int) $q['customer_id'], 'quote_accepted', 'Quote ' . $q['quote_number'] . ' accepted by ' . ( '' !== $who ? $who : $accepted_by ), 'order:' . $order_id, $contact_id ?: (int) $q['contact_id'] );
		WB_Notifications::notify_cap( 'wb_manage_orders', 'orders', sprintf( 'Quote %s was accepted: new order for %s.', (string) $q['quote_number'], (string) $customer['name'] ), WB_Workspace::url( 'orders' ), 'wb_orders', (int) $order_id );

		if ( 'acceptance' === (string) get_option( 'wb_invoice_trigger', 'acceptance' ) || (int) ( $customer['payment_terms_days'] ?? 0 ) <= 0 ) {
			self::invoice_now( (int) $order_id );
		}
		return (int) $order_id;
	}

	/** Issue the invoice and move the order to invoiced → awaiting_payment. */
	private static function invoice_now( int $order_id ) {
		$inv = WB_Invoices::issue_for_order( $order_id, true );
		if ( is_wp_error( $inv ) ) {
			WB_Notifications::notify_cap( 'wb_issue_invoices', 'money', sprintf( 'Order #%d could not be invoiced automatically: %s', $order_id, $inv->get_error_message() ), WB_Workspace::url( 'orders' ), 'wb_orders', $order_id );
			return $inv;
		}
		return $inv;   // issue_for_order moved the order on (mark_invoiced)
	}

	/**
	 * After an invoice exists: an accepted order moves to invoiced → awaiting_payment. Called by
	 * WB_Invoices::issue_for_order; engine-internal (the invoice gate already passed). Orders
	 * invoiced on dispatch are past these states and are left alone.
	 */
	public static function mark_invoiced( int $order_id ): void {
		$o = WB_CCT::get( 'wb_orders', $order_id );
		if ( ! $o || 'accepted' !== $o['status'] ) return;
		self::set_status( $order_id, 'invoiced' );
		self::set_status( $order_id, 'awaiting_payment' );
	}

	/** Customer facts for the gate, then the gate. */
	public static function may_release( int $order_id ): array {
		$o = WB_CCT::get( 'wb_orders', $order_id );
		if ( ! $o ) return [ false, 'not_found' ];
		$c = WB_CCT::get( 'wb_customers', (int) $o['customer_id'] );
		if ( ! $c ) return [ false, 'not_found' ];
		$inv   = WB_Invoices::for_order( $order_id );
		$other = 0.0;
		$overdue = false;
		$today   = wb_today();
		foreach ( WB_CCT::find( 'wb_invoices', [ 'customer_id' => (int) $c['_ID'], 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 2000 ] ) as $i ) {
			if ( WB_Invoices::is_past_due( $i, $today ) ) $overdue = true;   // past due today, even before the nightly sweep marks it
			if ( $inv && (int) $i['_ID'] === (int) $inv['_ID'] ) continue;
			$other += WB_Invoices::outstanding( $i );
		}
		return self::may_release_pure( $c, $inv, $other, $overdue, (float) ( $o['total'] ?? 0 ) );
	}

	/* ================================================================== transitions */

	/** Write a status change the machine allows. Internal; public callers go through transition(). */
	private static function set_status( int $order_id, string $to, string $note = '' ) {
		$o = WB_CCT::get( 'wb_orders', $order_id );
		if ( ! $o ) return new WP_Error( 'wb_not_found', 'Order not found.' );
		if ( ! self::can_transition( (string) $o['status'], $to ) ) return new WP_Error( 'wb_bad_move', sprintf( 'An order cannot go from %s to %s.', $o['status'], $to ) );
		$res = WB_CCT::update( 'wb_orders', $order_id, [ 'status' => $to ], 'order_' . $to );
		if ( true === $res && '' !== $note ) wb_ledger_write( 'order_note', 'wb_orders', $order_id, null, [ 'to' => $to, 'note' => $note ] );
		return $res;
	}

	/**
	 * A manual move by staff. ready, closed and cancelled have their own guarded doors
	 * (release, close, cancel); delivery states only come from delivery notes.
	 *
	 * @return true|WP_Error
	 */
	public static function transition( int $order_id, string $to, string $note = '' ) {
		if ( ! current_user_can( 'wb_manage_orders' ) ) return new WP_Error( 'wb_forbidden', 'You cannot change orders.' );
		switch ( $to ) {
			case 'ready':     return self::release( $order_id );
			case 'closed':    return self::close( $order_id );
			case 'cancelled': return self::cancel( $order_id, $note );
			case 'part_delivered': case 'delivered':
				return new WP_Error( 'wb_use_dn', 'Delivery is recorded by issuing a delivery note.' );
			case 'paid':
				return new WP_Error( 'wb_use_payment', 'An order becomes paid when a payment is matched to its invoice.' );
			case 'invoiced': case 'awaiting_payment':
				if ( ! WB_Invoices::for_order( $order_id ) ) return new WP_Error( 'wb_no_invoice', 'Issue the invoice first.' );
		}
		return self::set_status( $order_id, $to, $note );
	}

	/** The gate's door: may_release → reserve what we can → ready. */
	public static function release( int $order_id ) {
		if ( ! current_user_can( 'wb_manage_orders' ) && ! current_user_can( 'wb_issue_delivery_notes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot release orders.' );
		[ $ok, $why ] = self::may_release( $order_id );
		if ( ! $ok ) return new WP_Error( 'wb_gate_' . $why, self::gate_text( $why ) );
		self::reserve( $order_id );
		$res = self::set_status( $order_id, 'ready', self::gate_text( $why ) );
		if ( true === $res ) wb_ledger_write( 'order_released', 'wb_orders', $order_id, null, [ 'gate' => $why ] );
		return $res;
	}

	/** Called when the order's invoice becomes paid or fully credited. */
	public static function on_invoice_settled( int $order_id ): void {
		$o = WB_CCT::get( 'wb_orders', $order_id );
		if ( $o && in_array( $o['status'], [ 'invoiced', 'awaiting_payment' ], true ) ) self::set_status( $order_id, 'paid', 'invoice settled' );
	}

	/**
	 * Reserve available stock against the order's undelivered lines. Never reserves more than is
	 * available; the rest is back-ordered. Engine-internal (callers hold their own gate).
	 */
	public static function reserve( int $order_id ): void {
		foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => $order_id ], [ 'limit' => 1000 ] ) as $l ) {
			$want = (float) $l['qty_ordered'] - (float) $l['qty_delivered'] - (float) $l['qty_reserved'];
			if ( $want <= 0.0001 ) continue;
			$take = min( $want, max( 0.0, WB_Stock::available( (int) $l['product_id'] ) ) );
			if ( $take > 0.0001 ) {
				$m = WB_Stock::move( (int) $l['product_id'], $take, 'reserve', [ 'system' => true, 'ref_type' => 'order', 'ref_id' => $order_id, 'reason' => 'Reserved for order' ] );
				if ( is_wp_error( $m ) ) $take = 0;
			}
			$reserved = (float) $l['qty_reserved'] + $take;
			WB_CCT::update( 'wb_order_lines', (int) $l['_ID'], [
				'qty_reserved'    => $reserved,
				'qty_backordered' => max( 0, (float) $l['qty_ordered'] - (float) $l['qty_delivered'] - $reserved ),
			], 'order_line_reserved' );
		}
	}

	/** Give back every reservation on the order (cancel). False when any line could not be given back. */
	private static function unreserve( int $order_id ): bool {
		$all_ok = true;
		foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => $order_id ], [ 'limit' => 1000 ] ) as $l ) {
			$r = (float) $l['qty_reserved'];
			if ( $r <= 0.0001 ) continue;
			$m = WB_Stock::move( (int) $l['product_id'], $r, 'release', [ 'system' => true, 'ref_type' => 'order', 'ref_id' => $order_id, 'reason' => 'Order cancelled' ] );
			if ( is_wp_error( $m ) ) { $all_ok = false; continue; }
			if ( true !== WB_CCT::update( 'wb_order_lines', (int) $l['_ID'], [ 'qty_reserved' => 0 ], 'order_line_unreserved' ) ) $all_ok = false;
		}
		return $all_ok;
	}

	/* ================================================================== delivery */

	/**
	 * Issue a delivery (or collection) note. $qtys = [ order_line_id => qty ]; omitted lines ship
	 * nothing. $args: type (collection|delivery), vehicle_or_courier, batches [ line_id => batch_id ].
	 * Writes a `sale` movement per line and releases the matching reservation, all inside the DN's
	 * numbering transaction — a failure leaves no half-shipped order.
	 *
	 * @return int|WP_Error delivery note id
	 */
	public static function issue_delivery_note( int $order_id, array $qtys, array $args = [] ) {
		if ( ! current_user_can( 'wb_issue_delivery_notes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot issue delivery notes.' );
		$o = WB_CCT::get( 'wb_orders', $order_id );
		if ( ! $o || ! in_array( $o['status'], [ 'ready', 'part_delivered' ], true ) ) return new WP_Error( 'wb_not_ready', 'Only a released (ready) order can be delivered.' );
		[ $ok, $why ] = self::may_release( $order_id );   // checked again at the door
		if ( ! $ok ) return new WP_Error( 'wb_gate_' . $why, self::gate_text( $why ) );
		$type = in_array( $args['type'] ?? '', [ 'collection', 'delivery' ], true ) ? $args['type'] : ( (string) ( $o['fulfilment'] ?? '' ) ?: 'collection' );

		$plan = [];
		foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => $order_id ], [ 'order' => 'ASC', 'limit' => 1000 ] ) as $l ) {
			$q = (float) ( $qtys[ (int) $l['_ID'] ] ?? 0 );
			if ( $q <= 0 ) continue;
			$left = (float) $l['qty_ordered'] - (float) $l['qty_delivered'];
			if ( $q > $left + 0.0001 ) return new WP_Error( 'wb_over_delivery', sprintf( 'Line %s: only %s left to deliver.', $l['description'], $left ) );
			$plan[] = [ 'line' => $l, 'qty' => $q, 'batch_id' => (int) ( $args['batches'][ (int) $l['_ID'] ] ?? $l['batch_id'] ?? 0 ) ];
		}
		if ( ! $plan ) return new WP_Error( 'wb_nothing', 'Enter the quantities leaving.' );

		$dn = WB_Sequences::issue( 'DN', function ( string $number ) use ( $o, $plan, $type, $args, $order_id ) {
			// Lock the order, then read every line again: a second submit of the same note waits
			// here and then sees the first one's deliveries (no double sale).
			$fresh = self::row_for_update( 'wb_orders', $order_id );
			if ( ! $fresh || ! in_array( (string) $fresh['status'], [ 'ready', 'part_delivered' ], true ) ) return new WP_Error( 'wb_not_ready', 'This order changed while you were working on it. Open it again and check what is left to deliver.' );
			$free = [];   // product → stock not reserved for anyone, what this note may take beyond the order's own reservation
			foreach ( $plan as $i => $p ) {
				$l = self::row_for_update( 'wb_order_lines', (int) $p['line']['_ID'] );
				if ( ! $l ) return new WP_Error( 'wb_not_found', 'An order line could not be found.' );
				$left = (float) $l['qty_ordered'] - (float) $l['qty_delivered'];
				if ( $p['qty'] > $left + 0.0001 ) return new WP_Error( 'wb_over_delivery', sprintf( 'Line %s: only %s left to deliver. It may already have been sent on another note.', $l['description'], $left ) );
				$pid = (int) $l['product_id'];
				if ( ! isset( $free[ $pid ] ) ) {
					self::row_for_update( 'wb_products', $pid );   // one note at a time per product, so two orders cannot take the same free stock
					$free[ $pid ] = max( 0.0, WB_Stock::available( $pid ) );
				}
				$extra = $p['qty'] - min( $p['qty'], (float) $l['qty_reserved'] );   // beyond this order's own reservation
				if ( $extra > $free[ $pid ] + 0.0001 ) {
					return new WP_Error( 'wb_reserved_elsewhere', sprintf( 'Line %s: only %s can leave now (%s reserved for this order, %s free). The rest of the stock is reserved for other orders.',
						$l['description'], round( min( $left, (float) $l['qty_reserved'] + $free[ $pid ] ), 3 ), (float) $l['qty_reserved'], $free[ $pid ] ) );
				}
				$free[ $pid ]       -= $extra;
				$plan[ $i ]['line'] = $l;
			}
			// The note first, so every movement can point at it; its lines are filled in below,
			// inside the same transaction (a failure rolls the whole note back, number included).
			$dn_id = WB_CCT::insert( 'wb_delivery_notes', [
				'dn_number' => $number, 'order_id' => $order_id, 'issued_at' => wb_now(), 'type' => $type,
				'vehicle_or_courier' => sanitize_text_field( (string) ( $args['vehicle_or_courier'] ?? '' ) ),
				'lines_json' => [], 'status' => 'issued', 'issued_by_staff_id' => WB_Staff::current_staff_id(),
			], 'delivery_note_issued' );
			if ( is_wp_error( $dn_id ) ) return $dn_id;
			$lines_json = [];
			foreach ( $plan as $p ) {
				$l   = $p['line'];
				$mid = WB_Stock::move( (int) $l['product_id'], $p['qty'], 'sale', [ 'system' => true, 'batch_id' => $p['batch_id'], 'ref_type' => 'delivery_note', 'ref_id' => $dn_id, 'reason' => $number . ' for order ' . $o['order_number'] ] );
				if ( is_wp_error( $mid ) ) return $mid;
				$rel = min( $p['qty'], (float) $l['qty_reserved'] );
				if ( $rel > 0.0001 ) {
					$rid = WB_Stock::move( (int) $l['product_id'], $rel, 'release', [ 'system' => true, 'ref_type' => 'delivery_note', 'ref_id' => $dn_id, 'reason' => 'Delivered on ' . $number ] );
					if ( is_wp_error( $rid ) ) return $rid;
				}
				$delivered = (float) $l['qty_delivered'] + $p['qty'];
				$reserved  = max( 0, (float) $l['qty_reserved'] - $rel );
				$u = WB_CCT::update( 'wb_order_lines', (int) $l['_ID'], [
					'qty_delivered' => $delivered, 'qty_reserved' => $reserved,
					'qty_backordered' => max( 0, (float) $l['qty_ordered'] - $delivered - $reserved ),
				], 'order_line_delivered' );
				if ( is_wp_error( $u ) ) return $u;
				$lines_json[] = [ 'order_line_id' => (int) $l['_ID'], 'product_id' => (int) $l['product_id'], 'description' => (string) $l['description'], 'qty' => $p['qty'], 'batch_id' => $p['batch_id'], 'movement_id' => (int) $mid ];
			}
			$u = WB_CCT::update( 'wb_delivery_notes', $dn_id, [ 'lines_json' => $lines_json ], 'delivery_note_lines' );
			return is_wp_error( $u ) ? $u : $dn_id;
		} );
		if ( is_wp_error( $dn ) ) return $dn;

		$all  = WB_CCT::find( 'wb_order_lines', [ 'order_id' => $order_id ], [ 'limit' => 1000 ] );
		$done = ! array_filter( $all, fn( $l ) => (float) $l['qty_delivered'] + 0.0001 < (float) $l['qty_ordered'] );
		$to   = $done ? 'delivered' : 'part_delivered';
		if ( $to !== $o['status'] ) self::set_status( $order_id, $to );
		if ( ! WB_Invoices::for_order( $order_id ) ) self::invoice_on_dispatch( $order_id );
		self::touchpoint( (int) $o['customer_id'], 'delivery', ucfirst( $type ) . ' note issued for order ' . $o['order_number'], 'delivery_note:' . $dn );
		return (int) $dn;
	}

	/** Dispatch trigger: the first delivery note invoices the order (status already past invoicing). */
	private static function invoice_on_dispatch( int $order_id ): void {
		$inv = WB_Invoices::issue_for_order( $order_id, true );
		if ( is_wp_error( $inv ) ) {
			WB_Notifications::notify_cap( 'wb_issue_invoices', 'money', sprintf( 'Order #%d was dispatched but could not be invoiced: %s', $order_id, $inv->get_error_message() ), WB_Workspace::url( 'invoices' ), 'wb_orders', $order_id );
		}
	}

	/** The collection note is signed: who collected it closes the DN. @return true|WP_Error */
	public static function confirm_collection( int $dn_id, string $collected_by_name, string $signature_key = '', int $pod_doc_id = 0 ) {
		if ( ! current_user_can( 'wb_issue_delivery_notes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot confirm collections.' );
		$dn = WB_CCT::get( 'wb_delivery_notes', $dn_id );
		if ( ! $dn || 'issued' !== $dn['status'] ) return new WP_Error( 'wb_not_open', 'That note is not waiting for a signature.' );
		$name = mb_substr( sanitize_text_field( $collected_by_name ), 0, 120 );
		if ( '' === $name ) return new WP_Error( 'wb_no_name', 'Write the name of the person who collected or received the goods.' );
		return WB_CCT::update( 'wb_delivery_notes', $dn_id, [
			'status' => 'collection' === $dn['type'] ? 'collected' : 'delivered', 'collected_by_name' => $name, 'collected_at' => wb_now(),
			'signature_key' => WB_Storage::sane_key( $signature_key ), 'pod_doc_id' => $pod_doc_id,
		], 'delivery_note_signed' );
	}

	/**
	 * Close: all lines delivered, and the invoice paid / credited — or within terms (not overdue).
	 *
	 * @return true|WP_Error
	 */
	public static function close( int $order_id ) {
		if ( ! current_user_can( 'wb_close_orders' ) ) return new WP_Error( 'wb_forbidden', 'You cannot close orders.' );
		$o = WB_CCT::get( 'wb_orders', $order_id );
		if ( ! $o || 'delivered' !== $o['status'] ) return new WP_Error( 'wb_not_delivered', 'Only a fully delivered order can be closed.' );
		$inv = WB_Invoices::for_order( $order_id );
		if ( ! $inv ) return new WP_Error( 'wb_no_invoice', 'The order has no invoice yet.' );
		$settled = in_array( $inv['status'], [ 'paid', 'credited' ], true );   // credited here = a two-person-approved credit after delivery; the goods have already left
		$c       = WB_CCT::get( 'wb_customers', (int) $o['customer_id'] );
		$terms   = $c && (int) $c['payment_terms_days'] > 0 && ! WB_Invoices::is_past_due( $inv, wb_today() );
		if ( ! $settled && ! $terms ) return new WP_Error( 'wb_unpaid', 'The invoice is unpaid and outside terms.' );
		$res = self::set_status( $order_id, 'closed' );
		if ( true === $res ) WB_CCT::update( 'wb_orders', $order_id, [ 'closed_at' => wb_now(), 'closed_by_staff_id' => WB_Staff::current_staff_id() ], 'order_closed_by' );
		return $res;
	}

	/**
	 * Cancel before anything has left. An invoiced order must be credited first (a credit note,
	 * approved by a second person) — cancelling never makes an invoice disappear.
	 *
	 * @return true|WP_Error
	 */
	public static function cancel( int $order_id, string $reason ) {
		if ( ! current_user_can( 'wb_manage_orders' ) ) return new WP_Error( 'wb_forbidden', 'You cannot cancel orders.' );
		if ( '' === trim( $reason ) ) return new WP_Error( 'wb_no_reason', 'Say why the order is cancelled.' );
		$o = WB_CCT::get( 'wb_orders', $order_id );
		if ( ! $o || ! in_array( $o['status'], self::PRE_DELIVERY, true ) ) return new WP_Error( 'wb_too_late', 'Goods have already left on this order; it cannot be cancelled. Use a credit note for a return.' );
		if ( WB_CCT::count( 'wb_delivery_notes', [ 'order_id' => $order_id ] ) ) return new WP_Error( 'wb_too_late', 'A delivery note exists for this order.' );
		$inv = WB_Invoices::for_order( $order_id );
		if ( $inv && 'credited' !== $inv['status'] ) return new WP_Error( 'wb_credit_first', 'Credit the invoice first (credit notes need a second person to approve).' );
		if ( ! self::unreserve( $order_id ) ) return new WP_Error( 'wb_unreserve_failed', 'Some reserved stock could not be given back, so the order was not cancelled. Try again, or tell the stock team.' );
		$res = self::set_status( $order_id, 'cancelled', $reason );
		if ( true === $res ) WB_CCT::update( 'wb_orders', $order_id, [ 'cancel_reason' => mb_substr( sanitize_text_field( $reason ), 0, 200 ) ], 'order_cancel_reason' );
		return $res;
	}

	/* ================================================================== housekeeping */

	/** Nightly: sent quotes past valid_until → expired. */
	public static function expire_quotes(): void {
		foreach ( WB_CCT::find( 'wb_quotes', [ 'status' => 'sent', 'valid_until <' => wb_today() ], [ 'limit' => 2000 ] ) as $q ) {
			WB_CCT::update( 'wb_quotes', (int) $q['_ID'], [ 'status' => 'expired' ], 'quote_expired' );
		}
	}

	/** Decline (the customer said no). @return true|WP_Error */
	public static function decline_quote( int $quote_id, string $why = '' ) {
		if ( ! current_user_can( 'wb_send_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot change quotes.' );
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		if ( ! $q || ! in_array( $q['status'], [ 'draft', 'sent' ], true ) ) return new WP_Error( 'wb_closed', 'That quote is already closed.' );
		$res = WB_CCT::update( 'wb_quotes', $quote_id, [ 'status' => 'declined' ], 'quote_declined' );
		if ( true === $res && '' !== $why ) wb_ledger_write( 'quote_decline_reason', 'wb_quotes', $quote_id, null, [ 'why' => sanitize_text_field( $why ) ] );
		return $res;
	}

	/** Record a contact moment (marketing lite). Engine-internal; ledgered through WB_CCT. */
	public static function touchpoint( int $customer_id, string $type, string $summary, string $source_ref = '', int $contact_id = 0 ): void {
		if ( $customer_id <= 0 || ! WB_CCT::table( 'wb_touchpoints' ) ) return;
		WB_CCT::insert( 'wb_touchpoints', [
			'customer_id' => $customer_id, 'contact_id' => $contact_id, 'type' => $type, 'happened_at' => wb_now(),
			'staff_id' => WB_Staff::current_staff_id(), 'summary' => mb_substr( $summary, 0, 500 ), 'source_ref' => $source_ref,
		], 'touchpoint_' . $type );
	}
}
