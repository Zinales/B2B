<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Portal — the customer portal (version one).
 *
 * A login (role wb_customer, cap wb_portal) belongs to ONE wb_contacts row
 * (portal_wp_user_id) and through it to ONE customer. Every portal query is scoped to that
 * customer_id, and everything FAILS CLOSED: no linked contact, an archived contact, no customer,
 * or a closed account → nothing is shown.
 *
 * Staff (wb_manage_customers) give a contact a login. The WordPress set-password email goes out
 * ONLY when a person presses that button (or "send it again") — a deliberate send, never automatic.
 *
 * Shortcodes:
 *   [wb_portal_home]        open quotes, unpaid invoices with due dates, recent orders and deliveries
 *   [wb_portal_quotes]      quotes, with Accept / Decline on the open ones
 *   [wb_portal_invoices]    invoices and the statement, downloaded through tokened links
 *   [wb_portal_datasheets]  current datasheets for products they have bought or been quoted
 *   [wb_portal_request]     ask for a quote (→ a DRAFT quote for the rep, never sent by itself)
 *   [wb_portal_details]     ask to change contact details (staff approve; never written directly)
 *   [wb_portal]             all of the above on one page
 * Each sign-in is recorded as a portal_login touchpoint.
 */
class WB_Portal {

	const DETAIL_FIELDS = [ 'first_name' => 'First name', 'last_name' => 'Last name', 'role_title' => 'Job title', 'email' => 'Email', 'phone' => 'Phone' ];

	const REQUESTS_PER_DAY = 10;

	public static function init(): void {
		foreach ( [ 'home', 'quotes', 'invoices', 'datasheets', 'request', 'details' ] as $s ) add_shortcode( 'wb_portal_' . $s, [ __CLASS__, $s ] );
		add_action( 'wp_login', [ __CLASS__, 'on_login' ], 10, 2 );
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			foreach ( [ 'portal_accept', 'portal_decline', 'portal_request', 'portal_details', 'portal_provision', 'portal_resend', 'portal_revoke', 'portal_change_decide', 'contact_add' ] as $a ) $h[ $a ] = [ __CLASS__, 'handle_' . substr( $a, 'contact_add' === $a ? 0 : 7 ) ];
			return $h;
		} );
	}

	/* ================================================================== who is this */

	/**
	 * The contact behind the current login, with its customer — or null. Fail closed: the login
	 * must hold wb_portal, be linked to exactly one active contact with a customer whose account
	 * is not closed.
	 */
	public static function contact( int $user_id = 0 ): ?array {
		$user_id = $user_id ?: get_current_user_id();
		if ( $user_id <= 0 || ! user_can( $user_id, 'wb_portal' ) ) return null;
		$rows = WB_CCT::find( 'wb_contacts', [ 'portal_wp_user_id' => $user_id ], [ 'limit' => 2 ] );
		if ( 1 !== count( $rows ) ) return null;   // none, or two contacts claiming one login: refuse
		$c = $rows[0];
		if ( (int) $c['customer_id'] <= 0 ) return null;
		$cust = WB_CCT::get( 'wb_customers', (int) $c['customer_id'] );
		if ( ! $cust || in_array( (string) ( $cust['record_status'] ?? '' ), [ 'archived', 'inactive', 'void' ], true ) || 'closed' === (string) ( $cust['account_status'] ?? '' ) ) return null;
		$c['_customer'] = $cust;
		return $c;
	}

	private static function gate(): array {
		if ( ! is_user_logged_in() ) return [ null, wb_notice( 'warn', 'Please sign in.' ) ];
		if ( ! current_user_can( 'wb_portal' ) ) return [ null, wb_notice( 'warn', 'This page is for customer logins.' ) ];
		$c = self::contact();
		return $c ? [ $c, '' ] : [ null, wb_notice( 'warn', 'Your login is not linked to a company yet. Please contact us.' ) ];
	}

	public static function on_login( string $login, $user ): void {
		if ( ! $user instanceof WP_User || ! in_array( 'wb_customer', (array) $user->roles, true ) ) return;
		$c = self::contact( (int) $user->ID );
		if ( $c ) WB_Orders::touchpoint( (int) $c['customer_id'], 'portal_login', 'Signed in to the portal', 'contact:' . (int) $c['_ID'], (int) $c['_ID'] );
	}

	/* ================================================================== staff: logins */

	/**
	 * Give a contact a portal login and send the WordPress set-password email (this button IS the
	 * decision to send). Refused when the email already belongs to a login (staff accounts are never
	 * turned into portal accounts).
	 *
	 * @return int|WP_Error user id
	 */
	public static function provision( int $contact_id ) {
		if ( ! current_user_can( 'wb_manage_customers' ) ) return new WP_Error( 'wb_forbidden', 'You cannot give portal logins.' );
		$c = WB_CCT::get( 'wb_contacts', $contact_id );
		if ( ! $c ) return new WP_Error( 'wb_not_found', 'Contact not found.' );
		if ( (int) $c['customer_id'] <= 0 || ! WB_CCT::get( 'wb_customers', (int) $c['customer_id'] ) ) return new WP_Error( 'wb_no_customer', 'This contact is not linked to a customer.' );
		if ( (int) $c['portal_wp_user_id'] > 0 && get_userdata( (int) $c['portal_wp_user_id'] ) ) return new WP_Error( 'wb_has_login', 'This contact already has a portal login.' );
		$email = sanitize_email( (string) $c['email'] );
		if ( ! is_email( $email ) ) return new WP_Error( 'wb_email', 'The contact needs a valid email address first.' );
		if ( email_exists( $email ) ) return new WP_Error( 'wb_email_used', 'That email address already has a login on this site. Use a different address for the portal.' );
		$login = sanitize_user( strtolower( strstr( $email, '@', true ) ), true ) ?: 'customer';
		$base  = $login;
		for ( $i = 2; username_exists( $login ); $i++ ) $login = $base . $i;
		$uid = wp_insert_user( [
			'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 32, true, true ), 'role' => 'wb_customer',
			'first_name' => (string) $c['first_name'], 'last_name' => (string) $c['last_name'], 'display_name' => trim( $c['first_name'] . ' ' . $c['last_name'] ) ?: $login,
		] );
		if ( is_wp_error( $uid ) ) return $uid;
		$r = WB_CCT::update( 'wb_contacts', $contact_id, [ 'portal_wp_user_id' => (int) $uid ], 'portal_login_given' );
		if ( is_wp_error( $r ) ) return $r;
		wp_new_user_notification( (int) $uid, null, 'user' );   // the set-password email: sent because a person pressed the button
		wb_ledger_write( 'portal_invite_sent', 'wb_contacts', $contact_id, null, [ 'user_id' => (int) $uid, 'to' => $email ] );
		return (int) $uid;
	}

	/** Send the set-password email again (a person pressed the button). @return true|WP_Error */
	public static function resend( int $contact_id ) {
		if ( ! current_user_can( 'wb_manage_customers' ) ) return new WP_Error( 'wb_forbidden', 'You cannot manage portal logins.' );
		$c = WB_CCT::get( 'wb_contacts', $contact_id );
		if ( ! $c || ! get_userdata( (int) $c['portal_wp_user_id'] ) ) return new WP_Error( 'wb_no_login', 'This contact has no portal login.' );
		wp_new_user_notification( (int) $c['portal_wp_user_id'], null, 'user' );
		wb_ledger_write( 'portal_invite_sent', 'wb_contacts', $contact_id, null, [ 'user_id' => (int) $c['portal_wp_user_id'], 'again' => true ] );
		return true;
	}

	/** Turn a portal login off: the login loses its role (nothing is deleted). @return true|WP_Error */
	public static function revoke( int $contact_id ) {
		if ( ! current_user_can( 'wb_manage_customers' ) ) return new WP_Error( 'wb_forbidden', 'You cannot manage portal logins.' );
		$c = WB_CCT::get( 'wb_contacts', $contact_id );
		$u = $c ? get_userdata( (int) $c['portal_wp_user_id'] ) : false;
		if ( ! $u ) return new WP_Error( 'wb_no_login', 'This contact has no portal login.' );
		if ( array_diff( (array) $u->roles, [ 'wb_customer' ] ) ) return new WP_Error( 'wb_not_portal', 'That login is not a portal-only login; change it in WordPress Users.' );
		$u->set_role( '' );
		wb_ledger_write( 'portal_login_turned_off', 'wb_contacts', $contact_id, [ 'role' => 'wb_customer' ], [ 'role' => '' ] );
		return true;
	}

	/* ================================================================== customer actions */

	private static function own_quote( array $c, int $quote_id ): ?array {
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		return ( $q && (int) $q['customer_id'] === (int) $c['customer_id'] && 'draft' !== $q['status'] ) ? $q : null;
	}

	/** @return int|WP_Error order id */
	public static function accept( int $quote_id ) {
		$c = self::contact();
		if ( ! $c ) return new WP_Error( 'wb_forbidden', 'Your login is not linked to a company.' );
		if ( ! self::own_quote( $c, $quote_id ) ) return new WP_Error( 'wb_not_found', 'Quote not found.' );
		return WB_Orders::accept_quote_portal( $quote_id, (int) $c['_ID'], trim( $c['first_name'] . ' ' . $c['last_name'] ) );
	}

	/** @return true|WP_Error */
	public static function decline( int $quote_id, string $why ) {
		$c = self::contact();
		if ( ! $c ) return new WP_Error( 'wb_forbidden', 'Your login is not linked to a company.' );
		if ( ! self::own_quote( $c, $quote_id ) ) return new WP_Error( 'wb_not_found', 'Quote not found.' );
		return WB_Orders::decline_quote_portal( $quote_id, (int) $c['_ID'], $why );
	}

	/**
	 * Ask for a quote: becomes a DRAFT quote priced by both checks, a touchpoint, and an in-app
	 * notification to the rep. Nothing is sent to anyone outside.
	 *
	 * @return int|WP_Error quote id
	 */
	public static function request_quote( array $lines, string $note ) {
		$c = self::contact();
		if ( ! $c ) return new WP_Error( 'wb_forbidden', 'Your login is not linked to a company.' );
		$key = 'wb_portal_rq_' . (int) $c['_ID'];
		$n   = (int) get_transient( $key );
		if ( $n >= self::REQUESTS_PER_DAY ) return new WP_Error( 'wb_too_many', 'You have asked for a lot of quotes today. Please call us.' );
		$clean = [];
		foreach ( $lines as $l ) {
			$pid = absint( $l['product_id'] ?? 0 );
			$qty = (float) ( $l['qty'] ?? 0 );
			if ( ! $pid || $qty <= 0 ) continue;
			$p = WB_CCT::get( 'wb_products', $pid );
			if ( ! $p || 'active' !== (string) $p['status'] ) return new WP_Error( 'wb_product', 'One of those products is no longer available.' );
			$clean[] = [ 'product_id' => $pid, 'qty' => min( $qty, 1000000 ) ];
		}
		if ( ! $clean ) return new WP_Error( 'wb_no_lines', 'Choose at least one product and a quantity.' );
		$qid = WB_Orders::request_quote_portal( (int) $c['customer_id'], (int) $c['_ID'], $clean, sanitize_textarea_field( $note ) );
		if ( is_wp_error( $qid ) ) return $qid;
		set_transient( $key, $n + 1, DAY_IN_SECONDS );
		return $qid;
	}

	/**
	 * Ask to change contact details. Stored as a request; staff approve it. Never written directly.
	 *
	 * @return int|WP_Error request id
	 */
	public static function request_details( array $in ) {
		$c = self::contact();
		if ( ! $c ) return new WP_Error( 'wb_forbidden', 'Your login is not linked to a company.' );
		$want = [];
		foreach ( self::DETAIL_FIELDS as $f => $label ) {
			if ( ! isset( $in[ $f ] ) ) continue;
			$v = 'email' === $f ? sanitize_email( (string) $in[ $f ] ) : sanitize_text_field( (string) $in[ $f ] );
			if ( 'email' === $f && '' !== trim( (string) $in[ $f ] ) && ! is_email( $v ) ) return new WP_Error( 'wb_email', 'Check the email address.' );
			if ( '' !== $v && $v !== (string) ( $c[ $f ] ?? '' ) ) $want[ $f ] = $v;
		}
		if ( ! $want ) return new WP_Error( 'wb_nothing', 'Nothing was changed.' );
		if ( WB_CCT::first( 'wb_portal_requests', [ 'contact_id' => (int) $c['_ID'], 'status' => 'requested' ] ) ) return new WP_Error( 'wb_pending', 'You already have a change waiting for us. We will look at it soon.' );
		$id = WB_CCT::insert( 'wb_portal_requests', [ 'contact_id' => (int) $c['_ID'], 'customer_id' => (int) $c['customer_id'], 'kind' => 'details_change', 'payload_json' => $want, 'status' => 'requested', 'requested_at' => wb_now() ], 'portal_details_change_requested' );
		if ( is_wp_error( $id ) ) return $id;
		WB_Orders::touchpoint( (int) $c['customer_id'], 'details_change', 'Asked to change contact details: ' . implode( ', ', array_keys( $want ) ), 'portal_request:' . (int) $id, (int) $c['_ID'] );
		WB_Notifications::notify_cap( 'wb_manage_customers', 'marketing', sprintf( '%s at %s asked to change their contact details.', trim( $c['first_name'] . ' ' . $c['last_name'] ), (string) $c['_customer']['name'] ), WB_Workspace::url( 'customers' ), 'wb_portal_requests', (int) $id );
		return (int) $id;
	}

	/**
	 * Staff approve or decline a details change. Approval writes the contact (ledgered) and, for an
	 * email change, the login's email too.
	 *
	 * @return true|WP_Error
	 */
	public static function decide_change( int $request_id, bool $approve, string $note = '' ) {
		if ( ! current_user_can( 'wb_manage_customers' ) ) return new WP_Error( 'wb_forbidden', 'You cannot change contacts.' );
		$r = WB_CCT::get( 'wb_portal_requests', $request_id );
		if ( ! $r || 'requested' !== $r['status'] ) return new WP_Error( 'wb_not_pending', 'That request is not waiting for a decision.' );
		$c = WB_CCT::get( 'wb_contacts', (int) $r['contact_id'] );
		if ( ! $c ) return new WP_Error( 'wb_not_found', 'The contact is no longer on file.' );
		if ( $approve ) {
			$want = array_intersect_key( WB_CCT::json( $r['payload_json'] ), self::DETAIL_FIELDS );
			if ( isset( $want['email'] ) && (int) $c['portal_wp_user_id'] > 0 ) {
				$owner = email_exists( $want['email'] );
				if ( $owner && (int) $owner !== (int) $c['portal_wp_user_id'] ) return new WP_Error( 'wb_email_used', 'That new email address belongs to another login. Decline, or ask the customer for a different address.' );
				$u = wp_update_user( [ 'ID' => (int) $c['portal_wp_user_id'], 'user_email' => $want['email'] ] );
				if ( is_wp_error( $u ) ) return $u;
			}
			$res = WB_CCT::update( 'wb_contacts', (int) $c['_ID'], $want, 'contact_details_changed_by_request' );
			if ( is_wp_error( $res ) ) return $res;
		}
		WB_Notifications::resolve( 'wb_portal_requests', $request_id );
		return WB_CCT::update( 'wb_portal_requests', $request_id, [ 'status' => $approve ? 'approved' : 'declined', 'decided_by_staff_id' => WB_Staff::current_staff_id(), 'decided_at' => wb_now(), 'decision_note' => sanitize_text_field( $note ) ], $approve ? 'portal_request_approved' : 'portal_request_declined' );
	}

	/* ================================================================== documents on demand */

	/** Products this customer has bought or been quoted (sent quotes and orders only). */
	public static function product_ids( int $customer_id ): array {
		$ids = [];
		$qs  = array_map( fn( $q ) => (int) $q['_ID'], WB_CCT::find( 'wb_quotes', [ 'customer_id' => $customer_id, 'status NOT IN' => [ 'draft' ] ], [ 'limit' => 2000 ] ) );
		if ( $qs ) foreach ( WB_CCT::find( 'wb_quote_lines', [ 'quote_id' => $qs ], [ 'limit' => 5000 ] ) as $l ) $ids[ (int) $l['product_id'] ] = true;
		$os = array_map( fn( $o ) => (int) $o['_ID'], WB_CCT::find( 'wb_orders', [ 'customer_id' => $customer_id ], [ 'limit' => 2000 ] ) );
		if ( $os ) foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => $os ], [ 'limit' => 5000 ] ) as $l ) $ids[ (int) $l['product_id'] ] = true;
		unset( $ids[0] );
		return array_keys( $ids );
	}

	/** May this user have this invoice / statement? The portal contact of that customer, or staff with invoices. */
	public static function may_see_customer_doc( int $customer_id, int $uid ): bool {
		if ( $uid <= 0 || $customer_id <= 0 ) return false;
		if ( user_can( $uid, 'wb_issue_invoices' ) ) return true;
		$c = self::contact( $uid );
		return $c && (int) $c['customer_id'] === $customer_id;
	}

	private static function doc_page( string $title, string $body ): string {
		$b    = WB_Setup::brand();
		$c    = WB_Setup::safe_colors( (array) $b['colors'] );   // P15: never print a stored colour without re-checking it
		$logo = WB_Setup::logo_data_uri();
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title><style>'
			. 'body{font-family:system-ui,sans-serif;color:' . esc_attr( $c['ink'] ) . ';margin:0;padding:24px;font-size:14px}table{width:100%;border-collapse:collapse;margin:0 0 16px}'
			. 'th{text-align:left;font-size:12px;color:' . esc_attr( $c['text_muted'] ) . ';border-bottom:1px solid ' . esc_attr( $c['line'] ) . ';padding:4px}td{padding:5px 4px;border-bottom:1px solid ' . esc_attr( $c['line'] ) . '}'
			. '.n{text-align:right;white-space:nowrap}.top{display:flex;justify-content:space-between;border-bottom:3px solid ' . esc_attr( $c['primary'] ) . ';padding-bottom:12px;margin-bottom:16px}small{color:' . esc_attr( $c['text_muted'] ) . '}img{max-height:56px}</style></head><body>'
			. '<div class="top"><div>' . ( $logo ? '<img src="' . esc_attr( $logo ) . '" alt=""><br>' : '' ) . '<strong>' . esc_html( (string) ( $b['legal_name'] ?: $b['display_name'] ) ) . '</strong><br><small>'
			. nl2br( esc_html( (string) $b['physical_address'] ) ) . ( '' !== $b['vat_number'] ? '<br>VAT ' . esc_html( (string) $b['vat_number'] ) : '' ) . ( '' !== $b['reg_number'] ? '<br>Reg. ' . esc_html( (string) $b['reg_number'] ) : '' ) . '</small></div><h1>' . esc_html( $title ) . '</h1></div>'
			. $body . '</body></html>';
	}

	/** An issued invoice as a page (the invoice is immutable, so this is the same every time). */
	public static function invoice_html( array $inv ): string {
		$cust  = WB_CCT::get( 'wb_customers', (int) $inv['customer_id'] );
		$m     = fn( $v ) => esc_html( WB_Render::money( $v ) );
		$rows  = '';
		foreach ( WB_CCT::json( $inv['lines_json'] ?? '' ) as $l ) {
			$rows .= '<tr><td>' . esc_html( (string) ( $l['description'] ?? '' ) ) . '</td><td class="n">' . esc_html( (string) ( $l['qty'] ?? '' ) ) . '</td><td class="n">' . $m( $l['unit_price'] ?? 0 ) . '</td><td class="n">' . $m( $l['line_total'] ?? 0 ) . '</td></tr>';
		}
		$body = '<p><strong>' . esc_html( (string) ( $cust['name'] ?? '' ) ) . '</strong>' . ( ! empty( $cust['vat_number'] ) ? '<br><small>VAT ' . esc_html( (string) $cust['vat_number'] ) . '</small>' : '' ) . '<br>' . nl2br( esc_html( (string) ( $cust['billing_address'] ?? '' ) ) ) . '</p>'
			. '<p>Invoice <strong>' . esc_html( (string) $inv['invoice_number'] ) . '</strong> · issued ' . esc_html( substr( (string) $inv['issued_at'], 0, 10 ) ) . ' · due ' . esc_html( substr( (string) $inv['due_at'], 0, 10 ) ) . '</p>'
			. '<table><tr><th>Item</th><th class="n">Qty</th><th class="n">Price each</th><th class="n">Total</th></tr>' . $rows
			. '<tr><td colspan="3">Subtotal</td><td class="n">' . $m( $inv['subtotal'] ) . '</td></tr><tr><td colspan="3">VAT ' . esc_html( (string) $inv['vat_rate'] ) . '%</td><td class="n">' . $m( $inv['vat'] ) . '</td></tr>'
			. '<tr><td colspan="3"><strong>Total</strong></td><td class="n"><strong>' . $m( $inv['total'] ) . '</strong></td></tr><tr><td colspan="3">Paid</td><td class="n">' . $m( $inv['amount_paid'] ) . '</td></tr>'
			. '<tr><td colspan="3"><strong>Still to pay</strong></td><td class="n"><strong>' . $m( WB_Invoices::outstanding( $inv ) ) . '</strong></td></tr></table>'
			. '<p><small>Please use the invoice number as your payment reference.</small></p>';
		return self::doc_page( 'Tax invoice', $body );
	}

	/** A statement for one customer: every invoice of the last 12 months and what is still owed. */
	public static function statement_html( int $customer_id ): string {
		$cust  = WB_CCT::get( 'wb_customers', $customer_id );
		$from  = gmdate( 'Y-m-d', strtotime( wb_today() . ' -12 months' ) );
		$m     = fn( $v ) => esc_html( WB_Render::money( $v ) );
		$rows  = '';
		$owed  = 0.0;
		foreach ( WB_CCT::find( 'wb_invoices', [ 'customer_id' => $customer_id, 'issued_at >=' => $from, 'status NOT IN' => [ 'void' ] ], [ 'limit' => 2000, 'orderby' => 'issued_at', 'order' => 'ASC' ] ) as $i ) {
			$o     = WB_Invoices::outstanding( $i );
			$owed += $o;
			$rows .= '<tr><td>' . esc_html( substr( (string) $i['issued_at'], 0, 10 ) ) . '</td><td>' . esc_html( (string) $i['invoice_number'] ) . '</td><td>' . esc_html( substr( (string) $i['due_at'], 0, 10 ) ) . '</td><td class="n">' . $m( $i['total'] ) . '</td><td class="n">' . $m( (float) $i['amount_paid'] + (float) ( $i['amount_credited'] ?? 0 ) ) . '</td><td class="n">' . $m( $o ) . '</td></tr>';
		}
		$body = '<p><strong>' . esc_html( (string) ( $cust['name'] ?? '' ) ) . '</strong><br><small>Statement on ' . esc_html( wb_today() ) . ' · invoices from ' . esc_html( $from ) . '</small></p>'
			. '<table><tr><th>Date</th><th>Invoice</th><th>Due</th><th class="n">Total</th><th class="n">Paid or credited</th><th class="n">Still to pay</th></tr>' . ( $rows ?: '<tr><td colspan="6">No invoices in this period.</td></tr>' )
			. '<tr><td colspan="5"><strong>Balance owing</strong></td><td class="n"><strong>' . $m( $owed ) . '</strong></td></tr></table>';
		return self::doc_page( 'Statement', $body );
	}

	public static function doc_url( string $kind, int $id ): string {
		return add_query_arg( [ 'kind' => $kind, 'id' => $id, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'wb/v1/private' ) );
	}

	/* ================================================================== screens */

	private static function money( $v ): string {
		return esc_html( WB_Render::money( $v ) );
	}

	public static function home( $atts = [] ): string {
		[ $c, $g ] = self::gate();
		if ( ! $c ) return $g;
		$cid = (int) $c['customer_id'];
		$h   = WB_RowActions::notice() . '<p>Welcome, ' . esc_html( (string) $c['first_name'] ) . ' · ' . esc_html( (string) $c['_customer']['name'] ) . '</p>';
		$open = WB_CCT::find( 'wb_quotes', [ 'customer_id' => $cid, 'status' => 'sent' ], [ 'limit' => 50 ] );
		$h  .= '<h3>Quotes waiting for you</h3>' . WB_Render::render_table( $open, [ 'quote_number', 'valid_until', [ 'key' => 'total', 'type' => 'money' ] ], [ 'empty' => 'No open quotes.' ] );
		$unpaid = array_map( fn( $i ) => $i + [ 'owed' => WB_Invoices::outstanding( $i ) ], WB_CCT::find( 'wb_invoices', [ 'customer_id' => $cid, 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 200, 'orderby' => 'due_at', 'order' => 'ASC' ] ) );
		$h  .= '<h3>Invoices to pay</h3>' . WB_Render::render_table( $unpaid, [ 'invoice_number', 'due_at', 'status', [ 'key' => 'owed', 'label' => 'Still to pay', 'type' => 'money' ] ],
			[ 'action_html' => fn( $i ) => WB_RowActions::menuitem( 'download', 'Open invoice', [ 'href' => self::doc_url( 'invoice', (int) $i['_ID'] ) ] ), 'empty' => 'Nothing to pay. Thank you.' ] );
		$orders = WB_CCT::find( 'wb_orders', [ 'customer_id' => $cid ], [ 'limit' => 10 ] );
		$h  .= '<h3>Recent orders</h3>' . WB_Render::render_table( $orders, [ 'order_number', 'status', 'required_by', [ 'key' => 'total', 'type' => 'money' ] ], [ 'empty' => 'No orders yet.' ] );
		$oids = array_map( fn( $o ) => (int) $o['_ID'], $orders );
		$dns  = $oids ? WB_CCT::find( 'wb_delivery_notes', [ 'order_id' => $oids ], [ 'limit' => 10 ] ) : [];
		$h  .= '<h3>Recent deliveries and collections</h3>' . WB_Render::render_table( $dns, [ 'dn_number', 'type', 'issued_at', 'status' ], [ 'empty' => 'Nothing has gone out yet.' ] );
		return $h;
	}

	public static function quotes( $atts = [] ): string {
		[ $c, $g ] = self::gate();
		if ( ! $c ) return $g;
		$rows = WB_CCT::find( 'wb_quotes', [ 'customer_id' => (int) $c['customer_id'], 'status NOT IN' => [ 'draft' ] ], [ 'limit' => 200 ] );
		return WB_RowActions::notice() . WB_Render::render_table( $rows, [ 'quote_number', 'status', 'valid_until', [ 'key' => 'total', 'type' => 'money' ] ], [
			'action_html' => function ( $q ) {
				if ( 'sent' !== $q['status'] || (string) $q['valid_until'] < wb_today() ) return '';
				$hid = '<input type="hidden" name="quote_id" value="' . (int) $q['_ID'] . '">';
				return WB_Render::form_open( 'portal_accept' ) . $hid . WB_RowActions::menuitem( 'approve', 'Accept quote', [ 'submit' => true ] ) . '</form>'
					. WB_Render::form_open( 'portal_decline' ) . $hid . WB_RowActions::menuitem( 'withdraw', 'Decline quote', [ 'submit' => true ] ) . '</form>';
			}, 'empty' => 'No quotes yet.' ] )
			. '<p class="wb-muted">Accepting places the order at the quoted prices.</p>';
	}

	public static function invoices( $atts = [] ): string {
		[ $c, $g ] = self::gate();
		if ( ! $c ) return $g;
		$rows = WB_CCT::find( 'wb_invoices', [ 'customer_id' => (int) $c['customer_id'], 'status NOT IN' => [ 'void' ] ], [ 'limit' => 300 ] );
		return WB_RowActions::notice() . '<p><a class="wb-btn" href="' . esc_url( self::doc_url( 'statement', (int) $c['customer_id'] ) ) . '">Download my statement</a></p>'
			. WB_Render::render_table( $rows, [ 'invoice_number', 'issued_at', 'due_at', [ 'key' => 'total', 'type' => 'money' ], [ 'key' => 'amount_paid', 'type' => 'money' ], 'status' ],
				[ 'action_html' => fn( $i ) => WB_RowActions::menuitem( 'download', 'Download invoice', [ 'href' => self::doc_url( 'invoice', (int) $i['_ID'] ) ] ), 'empty' => 'No invoices yet.' ] );
	}

	public static function datasheets( $atts = [] ): string {
		[ $c, $g ] = self::gate();
		if ( ! $c ) return $g;
		$uid  = get_current_user_id();
		$rows = [];
		foreach ( self::product_ids( (int) $c['customer_id'] ) as $pid ) {
			$p = WB_CCT::get( 'wb_products', $pid );
			if ( ! $p ) continue;
			[ $kind, $row, $doc ] = WB_Datasheets::current( $pid, $p );   // 1.4.0: data, an uploaded file, or an online link
			$name = $p['sku'] . ' · ' . $p['name'];
			if ( 'data' === $kind ) $rows[] = [ '_ID' => $pid, 'product' => $name, 'title' => 'Datasheet ' . $p['sku'], 'version' => (string) ( $row['revision'] ?? '' ), 'issued_at' => substr( (string) ( $row['revised_at'] ?? '' ), 0, 10 ), '_href' => WB_Datasheets::url( $pid ), '_words' => 'Open' ];
			elseif ( 'link' === $kind ) $rows[] = [ '_ID' => $pid, 'product' => $name, 'title' => 'Online datasheet', 'version' => '', 'issued_at' => '', '_href' => (string) $row['external_url'], '_words' => 'Open online' ];
			elseif ( 'upload' === $kind && WB_Documents::user_can_access( $doc, $uid ) ) $rows[] = [ '_ID' => (int) $doc['_ID'], 'product' => $name, 'title' => $doc['title'], 'version' => 'v' . $doc['version'], 'issued_at' => substr( (string) $doc['issued_at'], 0, 10 ), '_href' => WB_Documents::open_url( (int) $doc['_ID'] ), '_words' => 'Open' ];
		}
		$dl = [ 'action_html' => fn( $d ) => WB_RowActions::menuitem( 'open', (string) ( $d['_words'] ?? 'Download' ), [ 'href' => (string) ( $d['_href'] ?? WB_Documents::open_url( (int) $d['_ID'] ) ) ] ) ];
		$h  = WB_Render::render_table( $rows, [ 'product', 'title', [ 'key' => 'version', 'label' => 'Revision' ], [ 'key' => 'issued_at', 'label' => 'Dated' ] ], $dl + [ 'empty' => 'Datasheets for products you buy or are quoted appear here.' ] );
		// 0.2.2: company-wide documents, only when the organisation shows them (Setup → Customer portal)
		$company = [];
		foreach ( WB_Documents::company_docs() as $doc ) {
			if ( ! WB_Documents::user_can_access( $doc, $uid ) ) continue;
			$company[] = [ '_ID' => (int) $doc['_ID'], 'title' => $doc['title'], 'type' => WB_Render::label( (string) $doc['type'] ), 'version' => $doc['version'], 'issued_at' => substr( (string) $doc['issued_at'], 0, 10 ) ];
		}
		if ( $company ) $h .= '<h3>Company documents</h3>' . WB_Render::render_table( $company, [ 'title', 'type', 'version', 'issued_at' ], $dl );
		return $h;
	}

	public static function request( $atts = [] ): string {
		[ $c, $g ] = self::gate();
		if ( ! $c ) return $g;
		$opts = WB_Render::options( 'wb_products', 'name', [ 'status' => 'active' ] );
		$f    = WB_Render::form_open( 'portal_request' );
		for ( $i = 0; $i < 5; $i++ ) $f .= WB_Render::field( 'product[' . $i . ']', 'Product', 'select', '', [ 'options' => $opts ] ) . WB_Render::field( 'qty[' . $i . ']', 'Quantity', 'number' );
		$f .= WB_Render::field( 'note', 'Anything we should know (delivery, dates)', 'textarea', '', [ 'rows' => 3 ] ) . WB_Render::form_close( 'Ask for a quote' );
		return WB_RowActions::notice() . '<p class="wb-muted">We will price it and send you the quote.</p>' . $f;
	}

	public static function details( $atts = [] ): string {
		[ $c, $g ] = self::gate();
		if ( ! $c ) return $g;
		$f = WB_Render::form_open( 'portal_details' );
		foreach ( self::DETAIL_FIELDS as $k => $label ) $f .= WB_Render::field( $k, $label, 'email' === $k ? 'email' : 'text', (string) ( $c[ $k ] ?? '' ) );
		$pending = WB_CCT::first( 'wb_portal_requests', [ 'contact_id' => (int) $c['_ID'], 'status' => 'requested' ] );
		return WB_RowActions::notice() . ( $pending ? wb_notice( 'warn', 'A change you asked for is waiting for us to approve it.' ) : '' )
			. '<p class="wb-muted">Changes are checked by us before they take effect.</p>' . $f . WB_Render::form_close( 'Ask for this change' );
	}

	/** [wb_portal]: everything on one page. */
	public static function full( $atts = [] ): string {
		[ $c, $g ] = self::gate();
		if ( ! $c ) return $g;
		return self::home() . '<h2>Quotes</h2>' . self::quotes() . '<h2>Invoices and statement</h2>' . self::invoices() . '<h2>Datasheets</h2>' . self::datasheets()
			. '<h2>Ask for a quote</h2>' . self::request() . '<h2>My details</h2>' . self::details();
	}

	/** Staff panel on the Customers screen: contacts, portal logins, change requests. */
	public static function staff_panel(): string {
		if ( ! current_user_can( 'wb_view_customers' ) ) return '';
		$h        = '';
		$can      = current_user_can( 'wb_manage_customers' );
		$requests = $can ? WB_CCT::find( 'wb_portal_requests', [ 'status' => 'requested' ], [ 'limit' => 200 ] ) : [];
		if ( $requests ) {
			$h .= self::fold( 'Contact changes customers asked for', WB_Render::render_table( $requests, [
				[ 'key' => 'contact_id', 'label' => 'Contact', 'render' => function ( $v ) { $x = WB_CCT::get( 'wb_contacts', (int) $v ); return esc_html( $x ? trim( $x['first_name'] . ' ' . $x['last_name'] ) : '#' . $v ); } ],
				[ 'key' => 'payload_json', 'label' => 'Wants', 'render' => function ( $v ) { $o = []; foreach ( WB_CCT::json( $v ) as $k => $x ) $o[] = ( self::DETAIL_FIELDS[ $k ] ?? $k ) . ': ' . $x; return esc_html( implode( '; ', $o ) ); } ],
				'requested_at' ],
				[ 'action_html' => function ( $r ) {
					$m = '';
					foreach ( [ 'approve' => [ 'approve', 'Approve and update the contact' ], 'decline' => [ 'withdraw', 'Decline' ] ] as $d => $ui ) {
						$m .= WB_Render::form_open( 'portal_change_decide' ) . '<input type="hidden" name="request_id" value="' . (int) $r['_ID'] . '"><input type="hidden" name="decision" value="' . $d . '">' . WB_RowActions::menuitem( $ui[0], $ui[1], [ 'submit' => true ] ) . '</form>';
					}
					return $m;
				} ] ), true );
		}
		$contacts = WB_CCT::find( 'wb_contacts', [], [ 'limit' => 1000, 'orderby' => 'last_name', 'order' => 'ASC' ] );
		$body     = WB_Render::render_table( $contacts, [ [ 'key' => 'first_name', 'label' => 'Name', 'render' => fn( $v, $r ) => esc_html( trim( $v . ' ' . $r['last_name'] ) ) ],
			[ 'key' => 'customer_id', 'label' => 'Customer', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_customers', (int) $v )['name'] ?? '' ) ) ], 'email', 'phone',
			[ 'key' => 'portal_wp_user_id', 'label' => 'Portal', 'render' => function ( $v ) { $u = (int) $v ? get_userdata( (int) $v ) : false; return WB_Render::chip( $u ? ( in_array( 'wb_customer', (array) $u->roles, true ) ? 'active' : 'inactive' ) : 'none' ); } ] ],
			[ 'action_html' => function ( $r ) use ( $can ) {
				if ( ! $can ) return '';
				$edit = WB_RowActions::menuitem( 'edit', 'Edit', [ 'href' => WB_Records::edit_url( 'wb_contacts', (int) $r['_ID'] ) ] );
				$hid = '<input type="hidden" name="contact_id" value="' . (int) $r['_ID'] . '">';
				$u   = (int) $r['portal_wp_user_id'] ? get_userdata( (int) $r['portal_wp_user_id'] ) : false;
				if ( ! $u ) return $edit . WB_Render::form_open( 'portal_provision' ) . $hid . WB_RowActions::menuitem( 'approve', 'Give a portal login (sends the set-password email now)', [ 'submit' => true ] ) . '</form>';
				return $edit . WB_Render::form_open( 'portal_resend' ) . $hid . WB_RowActions::menuitem( 'remind', 'Send the set-password email again', [ 'submit' => true ] ) . '</form>'
					. WB_Render::form_open( 'portal_revoke' ) . $hid . WB_RowActions::menuitem( 'withdraw', 'Turn the portal login off', [ 'submit' => true, 'danger' => true ] ) . '</form>';
			}, 'empty' => 'No contacts yet.' ] );
		$editing = null;
		if ( $can && ! empty( $_GET['edit'] ) && 'wb_contacts' === sanitize_key( (string) ( $_GET['cct'] ?? '' ) ) ) $editing = WB_CCT::get( 'wb_contacts', absint( $_GET['edit'] ) ) ?: null;
		if ( $can ) $body .= ( $editing ? '<h3>Edit ' . esc_html( trim( $editing['first_name'] . ' ' . $editing['last_name'] ) ) . '</h3>' : '<h3>Add a contact</h3>' ) . WB_Records::form( 'wb_contacts', $editing );
		return $h . WB_Render::fold( 'Contacts and portal logins', $body, [ 'open' => (bool) $editing, 'id' => 'wb-add-contacts', 'kind' => 'sibling' ] ) . WB_Import::fold( 'wb_contacts' );
	}

	private static function fold( string $title, string $body, bool $open = false ): string {
		return WB_Render::fold( $title, $body, [ 'open' => $open ] );
	}

	/* ================================================================== panel handlers */

	private static function p( string $k, $d = '' ) {
		return isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : $d;
	}

	public static function handle_accept() {
		$r = self::accept( absint( self::p( 'quote_id' ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Thank you — your order is placed. We will be in touch about payment and delivery.' ];
	}

	public static function handle_decline() {
		$r = self::decline( absint( self::p( 'quote_id' ) ), (string) self::p( 'why' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Quote declined. Thank you for letting us know.' ];
	}

	public static function handle_request() {
		$lines = [];
		foreach ( (array) self::p( 'product', [] ) as $i => $pid ) $lines[] = [ 'product_id' => $pid, 'qty' => ( (array) self::p( 'qty', [] ) )[ $i ] ?? 0 ];
		$r = self::request_quote( $lines, (string) self::p( 'note' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Thank you. We have your request and will send you the quote.' ];
	}

	public static function handle_details() {
		$r = self::request_details( (array) wp_unslash( $_POST ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Thank you. We will check the change and update your details.' ];
	}

	public static function handle_provision() {
		$r = self::provision( absint( self::p( 'contact_id' ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Portal login made. The set-password email has been sent to the contact.' ];
	}

	public static function handle_resend() {
		$r = self::resend( absint( self::p( 'contact_id' ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'The set-password email has been sent again.' ];
	}

	public static function handle_revoke() {
		$r = self::revoke( absint( self::p( 'contact_id' ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Portal login turned off. The login and its history stay on file.' ];
	}

	public static function handle_change_decide() {
		return self::decide_change( absint( self::p( 'request_id' ) ), 'approve' === self::p( 'decision' ), (string) self::p( 'note' ) );
	}

	public static function handle_contact_add() {
		if ( ! current_user_can( 'wb_manage_customers' ) ) return new WP_Error( 'wb_forbidden', 'You cannot add contacts.' );
		$cid   = absint( self::p( 'customer_id' ) );
		$first = sanitize_text_field( (string) self::p( 'first_name' ) );
		if ( ! $cid || ! WB_CCT::get( 'wb_customers', $cid ) || '' === $first ) return new WP_Error( 'wb_contact', 'Choose the customer and give a first name.' );
		$email = sanitize_email( (string) self::p( 'email' ) );
		if ( '' !== trim( (string) self::p( 'email' ) ) && ! is_email( $email ) ) return new WP_Error( 'wb_email', 'Check the email address.' );
		if ( '' !== $email && WB_CCT::first( 'wb_contacts', [ 'email' => $email ], [ 'active_only' => false ] ) ) return new WP_Error( 'wb_dup', 'A contact with that email is already on file.' );
		$id = WB_CCT::insert( 'wb_contacts', [ 'customer_id' => $cid, 'first_name' => $first, 'last_name' => sanitize_text_field( (string) self::p( 'last_name' ) ), 'role_title' => sanitize_text_field( (string) self::p( 'role_title' ) ), 'email' => $email, 'phone' => sanitize_text_field( (string) self::p( 'phone' ) ) ], 'contact_added' );
		return is_wp_error( $id ) ? $id : [ 'msg' => 'Contact added.' ];
	}
}
