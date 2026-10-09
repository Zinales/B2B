<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Pages — a page of its own for a customer and for a product (1.5.0, review of 9 October:
 * "a customer has no page of their own").
 *
 * Everything known about one customer on one screen: what they owe and how late, their terms,
 * limit and tier, then their open invoices, quotes, orders, payments, contacts, the timeline,
 * their own prices and their documents. The same for a product: stock now and on order, price
 * and floor, its datasheet, its movements, where it is quoted and ordered, its customer prices.
 * Reached from the name in any list (?customer= on Customers, ?product= on Products).
 *
 * ageing() is pure: tested without WordPress, and used again by the statements (1.6).
 */
class WB_Pages {

	/** Buckets an ageing summary uses, oldest last. */
	const BUCKETS = [ 'current' => 'Not yet due', 'd30' => '1 to 30 days', 'd60' => '31 to 60 days', 'd90' => '61 to 90 days', 'd90p' => 'Over 90 days' ];

	/**
	 * What is owed, by how late. $invoices: rows with total, amount_paid, amount_credited, due_at.
	 * Returns [ bucket => amount ] plus 'total' and 'overdue'. Pure.
	 */
	public static function ageing( array $invoices, string $today ): array {
		$out = array_fill_keys( array_keys( self::BUCKETS ), 0.0 ) + [ 'total' => 0.0, 'overdue' => 0.0 ];
		$t   = strtotime( substr( $today, 0, 10 ) );
		foreach ( $invoices as $i ) {
			$left = round( (float) ( $i['total'] ?? 0 ) - (float) ( $i['amount_paid'] ?? 0 ) - (float) ( $i['amount_credited'] ?? 0 ), 2 );
			if ( $left <= 0.004 ) continue;
			$due  = (string) ( $i['due_at'] ?? '' );
			$late = '' === $due ? 0 : (int) floor( ( $t - strtotime( substr( $due, 0, 10 ) ) ) / 86400 );
			$b    = $late <= 0 ? 'current' : ( $late <= 30 ? 'd30' : ( $late <= 60 ? 'd60' : ( $late <= 90 ? 'd90' : 'd90p' ) ) );
			$out[ $b ]     += $left;
			$out['total']  += $left;
			if ( $late > 0 ) $out['overdue'] += $left;
		}
		foreach ( $out as $k => $v ) $out[ $k ] = round( $v, 2 );
		return $out;
	}

	/** One fact: label, value, an optional note, hot when it must not be missed. */
	private static function fact( string $label, string $value, string $note = '', bool $hot = false ): string {
		return '<div class="wb-fact' . ( $hot ? ' is-hot' : '' ) . '"><dt>' . esc_html( $label ) . '</dt><dd>' . $value . ( '' !== $note ? '<small>' . esc_html( $note ) . '</small>' : '' ) . '</dd></div>';
	}

	private static function money( float $v ): string {
		return 'R ' . esc_html( WB_Render::money( $v ) );
	}

	/** The ageing as a row of five figures with a bar under each. */
	public static function ageing_strip( array $age ): string {
		$max = max( 0.01, ...array_map( fn( $k ) => (float) $age[ $k ], array_keys( self::BUCKETS ) ) );
		$h   = '<div class="wb-ageing" role="table" aria-label="What is owed, by how late">';
		foreach ( self::BUCKETS as $k => $words ) {
			$pct = (int) round( 100 * (float) $age[ $k ] / $max );
			$h  .= '<div class="wb-age' . ( 'current' !== $k && $age[ $k ] > 0 ? ' is-late' : '' ) . '" role="row"><span class="wb-age-l" role="rowheader">' . esc_html( $words ) . '</span><span class="wb-age-v" role="cell">' . self::money( (float) $age[ $k ] ) . '</span><span class="wb-age-bar" aria-hidden="true"><span style="width:' . $pct . '%"></span></span></div>';
		}
		return $h . '</div>';
	}

	private static function head( string $title, string $chip, array $actions, string $back_slug, string $back_words ): string {
		$a = '';
		foreach ( $actions as [ $href, $words, $primary ] ) $a .= '<a class="wb-btn wb-btn-sm' . ( $primary ? '' : ' wb-btn-ghost' ) . '" href="' . esc_url( $href ) . '">' . esc_html( $words ) . '</a>';
		return '<p class="wb-page-back"><a href="' . esc_url( WB_Workspace::url( $back_slug ) ) . '">' . esc_html( $back_words ) . '</a></p>'
			. '<div class="wb-page-head"><h2>' . esc_html( $title ) . ' ' . $chip . '</h2><div class="wb-page-actions">' . $a . '</div></div>';
	}

	private static function more( string $slug, array $args, string $words ): string {
		return '<p class="wb-list-more"><a href="' . esc_url( WB_Workspace::url( $slug, $args ) ) . '">' . esc_html( $words ) . '</a></p>';
	}

	/* ================================================================== the customer */

	public static function customer( int $id ): string {
		$c = WB_CCT::get( 'wb_customers', $id );
		if ( ! $c ) return WB_Render::state( 'empty', 'That customer could not be found.', 'It may have been archived. Search the list below.' );
		$name  = (string) $c['name'];
		$terms = (int) $c['payment_terms_days'];
		$open  = WB_CCT::find( 'wb_invoices', [ 'customer_id' => $id, 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 1000, 'orderby' => 'due_at', 'order' => 'ASC' ] );
		$age   = self::ageing( $open, wb_today() );
		$tier  = (int) ( $c['price_tier_id'] ?? 0 ) > 0 ? WB_CCT::get( 'wb_price_tiers', (int) $c['price_tier_id'] ) : null;
		$last  = WB_CCT::first( 'wb_orders', [ 'customer_id' => $id ] );
		$left  = (float) $c['credit_limit'] - $age['total'];

		$acts = [];
		if ( current_user_can( 'wb_create_quotes' ) && 'closed' !== $c['account_status'] ) $acts[] = [ WB_Workspace::url( 'quotes', [ 'customer' => $id ] ) . '#wb-add', 'Write a quote', true ];
		if ( current_user_can( 'wb_issue_invoices' ) && $age['overdue'] > 0 ) $acts[] = [ WB_Send::form_url( 'reminder', $id ), 'Send a reminder', false ];
		elseif ( current_user_can( 'wb_issue_invoices' ) && $age['total'] > 0 ) $acts[] = [ WB_Send::form_url( 'statement', $id ), 'Send a statement', false ];
		if ( current_user_can( 'wb_manage_customers' ) ) $acts[] = [ WB_Records::edit_url( 'wb_customers', $id ), 'Edit', false ];
		$h = self::head( $name, WB_Render::chip( (string) $c['account_status'] ), $acts, 'customers', 'All customers' );

		$h .= '<dl class="wb-facts">'
			. self::fact( 'Owes', self::money( $age['total'] ), count( $open ) . ' open invoice' . ( 1 === count( $open ) ? '' : 's' ) )
			. self::fact( 'Overdue', self::money( $age['overdue'] ), $age['overdue'] > 0 ? 'blocks the next release' : 'nothing late', $age['overdue'] > 0 )
			. ( $terms > 0 ? self::fact( 'Credit left', self::money( $left ), 'of ' . 'R ' . WB_Render::money( (float) $c['credit_limit'] ), $left < 0 ) : self::fact( 'Pays', 'Cash', 'pays before goods leave' ) )
			. self::fact( 'Terms', $terms > 0 ? esc_html( $terms . ' days' ) : 'On order' )
			. self::fact( 'Price tier', esc_html( $tier ? (string) $tier['name'] : 'Default' ), $tier ? WB_Render::num( $tier['discount_pct'] ) . '% off list' : '' )
			. self::fact( 'Stage', esc_html( WB_Render::words( (string) ( $c['journey_stage'] ?: 'lead' ) ) ) )
			. self::fact( 'Last order', esc_html( $last ? substr( (string) $last['cct_created'], 0, 10 ) : 'None yet' ) )
			. ( '' !== (string) $c['region'] ? self::fact( 'Region', esc_html( (string) $c['region'] ) ) : '' )
			. '</dl>';
		if ( $age['total'] > 0 ) $h .= self::ageing_strip( $age );
		if ( current_user_can( 'wb_issue_invoices' ) && $terms > 0 ) {   // 1.6.0: a monthly statement, switched on by a person
			$on = WB_Statements::is_monthly( $id );
			$h .= '<div class="wb-page-switch">' . WB_Render::form_open( 'statement_monthly' ) . '<input type="hidden" name="customer_id" value="' . $id . '"><input type="hidden" name="on" value="' . ( $on ? '' : '1' ) . '">'
				. '<span>Monthly statement by email: <strong>' . ( $on ? 'on, day ' . WB_Statements::day() : 'off' ) . '</strong></span><button type="submit" class="wb-btn wb-btn-sm wb-btn-ghost">' . ( $on ? 'Switch off' : 'Switch on' ) . '</button></form></div>';
		}
		if ( '' !== trim( (string) ( $c['notes'] ?? '' ) ) ) $h .= '<p class="wb-page-note">' . nl2br( esc_html( (string) $c['notes'] ) ) . '</p>';

		$q = [ 'q' => $name ];
		if ( current_user_can( 'wb_issue_invoices' ) ) {
			$h .= WB_Render::fold( 'Open invoices', WB_Render::render_table( $open, [ 'invoice_number', 'issued_at', 'due_at', [ 'key' => 'total', 'type' => 'money' ], [ 'key' => '_left', 'label' => 'Still owed', 'render' => fn( $v, $r ) => esc_html( WB_Render::money( WB_Invoices::outstanding( $r ) ) ) ], 'status' ],
				[ 'cct' => 'wb_invoices', 'actions' => [ 'pdf_invoice', 'send_invoice' ], 'empty' => 'Nothing owed. Every invoice is paid.' ] ) . self::more( 'invoices', $q, 'All invoices for ' . $name ), [ 'open' => true, 'kind' => 'lead', 'hint' => self::money( $age['total'] ) ] );
		}
		if ( current_user_can( 'wb_create_quotes' ) ) {
			$quotes = WB_CCT::find( 'wb_quotes', [ 'customer_id' => $id ], [ 'limit' => 15 ] );
			$h .= WB_Render::fold( 'Quotes', WB_Render::render_table( $quotes, [ [ 'key' => 'quote_number', 'render' => fn( $v, $r ) => '<a href="' . esc_url( WB_Workspace::url( 'quotes', [ 'quote' => (int) $r['_ID'] ] ) ) . '">' . esc_html( '' !== (string) $v ? (string) $v : 'Draft #' . (int) $r['_ID'] ) . '</a>' ], 'status', 'valid_until', [ 'key' => 'total', 'type' => 'money' ] ],
				[ 'cct' => 'wb_quotes', 'actions' => [ 'pdf_quote', 'send_quote' ], 'empty' => 'No quotes yet.' ] ) . self::more( 'quotes', $q, 'All quotes for ' . $name ), [ 'hint' => WB_CCT::count( 'wb_quotes', [ 'customer_id' => $id, 'status' => 'sent' ] ) . ' out' ] );
		}
		if ( current_user_can( 'wb_manage_orders' ) ) {
			$orders = WB_CCT::find( 'wb_orders', [ 'customer_id' => $id ], [ 'limit' => 15 ] );
			$h .= WB_Render::fold( 'Orders', WB_Render::render_table( $orders, [ [ 'key' => 'order_number', 'render' => fn( $v, $r ) => '<a href="' . esc_url( WB_Workspace::url( 'orders', [ 'order' => (int) $r['_ID'] ] ) ) . '">' . esc_html( (string) $v ) . '</a>' ], 'status', 'required_by', [ 'key' => 'total', 'type' => 'money' ] ],
				[ 'empty' => 'No orders yet.' ] ) . self::more( 'orders', $q, 'All orders for ' . $name ) );
		}
		if ( current_user_can( 'wb_match_payments' ) ) {
			$pays = WB_CCT::find( 'wb_payments', [ 'customer_id' => $id ], [ 'limit' => 15, 'orderby' => 'received_at' ] );
			$h .= WB_Render::fold( 'Payments', WB_Render::render_table( $pays, [ 'received_at', [ 'key' => 'amount', 'type' => 'money' ], 'bank_reference', [ 'key' => 'invoice_id', 'label' => 'Invoice', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_invoices', (int) $v )['invoice_number'] ?? '' ) ) ], 'match_status' ], [ 'empty' => 'No payments recorded.' ] ) );
		}
		$contacts = WB_CCT::find( 'wb_contacts', [ 'customer_id' => $id ], [ 'limit' => 100, 'orderby' => 'last_name', 'order' => 'ASC' ] );
		$h .= WB_Render::fold( 'Contacts', WB_Render::render_table( $contacts, [ [ 'key' => 'first_name', 'label' => 'Name', 'render' => fn( $v, $r ) => esc_html( trim( $v . ' ' . $r['last_name'] ) ) . ( wb_truthy( $r['is_primary'] ?? '' ) ? ' <span class="wb-muted">(main)</span>' : '' ) ], 'role_title',
			[ 'key' => 'email', 'render' => fn( $v ) => '' !== (string) $v ? '<a href="mailto:' . esc_attr( (string) $v ) . '">' . esc_html( (string) $v ) . '</a>' : '' ], 'phone', [ 'key' => 'portal_wp_user_id', 'label' => 'Portal', 'render' => fn( $v ) => (int) $v > 0 ? 'Yes' : 'No' ] ],
			[ 'cct' => 'wb_contacts', 'actions' => [ 'edit_wb_contacts' ], 'empty' => 'No contacts yet.' ] ) . self::more( 'customers', [], 'Contacts and portal logins for every customer' ), [ 'hint' => count( $contacts ) . ' on file' ] );
		$tps = WB_CCT::find( 'wb_touchpoints', [ 'customer_id' => $id ], [ 'limit' => 25, 'orderby' => 'happened_at' ] );
		$h  .= WB_Render::fold( 'Timeline', self::timeline( $tps ), [ 'hint' => $tps ? 'last ' . substr( (string) $tps[0]['happened_at'], 0, 10 ) : '' ] );
		$rules = WB_CCT::find( 'wb_price_rules', [ 'customer_id' => $id ], [ 'limit' => 200 ] );
		if ( $rules || current_user_can( 'wb_manage_pricing' ) ) {
			$h .= WB_Render::fold( 'Their own prices', '<p class="wb-muted">A rule for this customer beats their tier and the list price. Rules are made under Products › Customer price rules.</p>'
				. WB_Render::render_table( $rules, [ [ 'key' => 'product_id', 'label' => 'For', 'render' => fn( $v, $r ) => esc_html( (int) $v > 0 ? (string) ( WB_CCT::get( 'wb_products', (int) $v )['sku'] ?? '' ) : 'Category: ' . (string) ( WB_CCT::get( 'wb_product_categories', (int) $r['category_id'] )['name'] ?? '' ) ) ], 'rule_type', 'value', 'valid_from', 'valid_to', 'status' ],
					[ 'cct' => 'wb_price_rules', 'actions' => [ 'rule_approve' ], 'empty' => 'No rules: they pay their tier\'s price.' ] ), [ 'hint' => count( $rules ) . ' rule' . ( 1 === count( $rules ) ? '' : 's' ) ] );
		}
		if ( current_user_can( 'wb_view_documents' ) ) {
			$docs = WB_CCT::find( 'wb_documents', [ 'customer_id' => $id ], [ 'limit' => 25 ] );
			$h .= WB_Render::fold( 'Documents', WB_Render::render_table( $docs, [ 'title', 'type', 'issued_at' ], [ 'action_html' => [ 'WB_Screens', 'doc_open_item' ], 'empty' => 'No documents for this customer yet.' ] ) );
		}
		return $h;
	}

	/** Touchpoints as a feed: date, what, words, the next step. */
	public static function timeline( array $tps ): string {
		if ( ! $tps ) return WB_Render::state( 'empty', 'Nothing on the timeline yet.', 'Calls, visits, quotes sent and portal sign-ins appear here.' );
		$h = '<ol class="wb-feed">';
		foreach ( $tps as $t ) {
			$h .= '<li><time>' . esc_html( substr( (string) $t['happened_at'], 0, 10 ) ) . '</time><span class="wb-feed-what">' . WB_Render::chip( (string) $t['type'] ) . '</span><span class="wb-feed-t">' . esc_html( (string) $t['summary'] )
				. ( '' !== (string) ( $t['next_action'] ?? '' ) ? '<small>Next: ' . esc_html( (string) $t['next_action'] ) . ( '' !== (string) ( $t['next_action_date'] ?? '' ) ? ' by ' . esc_html( (string) $t['next_action_date'] ) : '' ) . '</small>' : '' ) . '</span></li>';
		}
		return $h . '</ol>';
	}

	/* ================================================================== the product */

	public static function product( int $id ): string {
		$p = WB_CCT::get( 'wb_products', $id );
		if ( ! $p ) return WB_Render::state( 'empty', 'That product could not be found.', 'It may have been archived. Search the list below.' );
		$cat     = (int) $p['category_id'] > 0 ? WB_CCT::get( 'wb_product_categories', (int) $p['category_id'] ) : null;
		$pricing = current_user_can( 'wb_manage_pricing' );
		$margin  = WB_Pricing::margin_pct_for( $p, WB_Pricing::category_chain( (int) $p['category_id'] ), (float) WB_Pricing::policy()['default_min_margin_pct'] );
		$floor   = WB_Pricing::round( (float) $p['cost_price'] * ( 1 + $margin / 100 ) );
		$num     = fn( float $v ) => esc_html( rtrim( rtrim( number_format( $v, 2, '.', ' ' ), '0' ), '.' ) );

		$acts = [];
		if ( 'data' === WB_Datasheets::current( $id, $p )[0] ) $acts[] = [ WB_Datasheets::url( $id ), 'Datasheet PDF', false ];
		if ( current_user_can( 'wb_move_stock' ) ) $acts[] = [ WB_Workspace::url( 'stock', [ 'product' => $id ] ) . '#wb-add', 'Correct stock', false ];
		if ( current_user_can( 'wb_manage_products' ) ) $acts[] = [ WB_Records::edit_url( 'wb_products', $id ), 'Edit', true ];
		$h = self::head( (string) $p['sku'] . ' · ' . (string) $p['name'], WB_Render::chip( (string) $p['status'] ), $acts, 'products', 'All products' );

		$h .= '<div class="wb-product-top">' . WB_Product_Images::panel( $p ) . '<dl class="wb-facts">' . self::fact( 'List price', self::money( (float) $p['list_price'] ), 'per ' . (string) $p['unit'] . ( (float) $p['pack_size'] > 1 ? ', pack of ' . WB_Render::num( $p['pack_size'] ) : '' ) );
		if ( $pricing ) $h .= self::fact( 'Cost', self::money( (float) $p['cost_price'] ) ) . self::fact( 'Lowest price allowed', self::money( $floor ), rtrim( rtrim( number_format( $margin, 2, '.', '' ), '0' ), '.' ) . '% margin on cost' );
		if ( current_user_can( 'wb_view_stock' ) ) {
			$avail = WB_Stock::available( $id );
			$h .= self::fact( 'Available', $num( $avail ), $num( WB_Stock::on_hand( $id ) ) . ' on hand, ' . $num( WB_Stock::reserved( $id ) ) . ' put aside', $avail <= (float) $p['reorder_point'] )
				. self::fact( 'On order', $num( WB_Stock::on_order( $id ) ), 'reorder at ' . $num( (float) $p['reorder_point'] ) );
		}
		$h .= self::fact( 'Category', esc_html( $cat ? (string) $cat['name'] : 'None' ) )
			. self::fact( 'Datasheet', WB_Datasheets::cell( WB_Datasheets::current( $id, $p ) ) );
		$sup = (int) ( $p['preferred_supplier_id'] ?? 0 ) > 0 ? WB_CCT::get( 'wb_suppliers', (int) $p['preferred_supplier_id'] ) : null;
		if ( $sup ) $h .= self::fact( 'Usual supplier', esc_html( (string) $sup['name'] ), (int) $p['lead_time_days'] > 0 ? (int) $p['lead_time_days'] . ' days lead time' : '' );
		$h .= '</dl></div>';

		$spec = WB_Datasheets::spec_rows( (string) ( $p['spec_json'] ?? '' ) );
		if ( $spec ) {
			$h .= '<dl class="wb-spec">';
			foreach ( $spec as $s ) $h .= '<div><dt>' . esc_html( $s['label'] ) . '</dt><dd>' . esc_html( trim( $s['value'] . ' ' . $s['unit'] ) ) . '</dd></div>';
			$h .= '</dl>';
		}
		if ( current_user_can( 'wb_view_stock' ) ) {
			$mv = WB_CCT::find( 'wb_stock_movements', [ 'product_id' => $id ], [ 'limit' => 25 ] );
			$h .= WB_Render::fold( 'Stock movements', WB_Render::render_table( $mv, [ [ 'key' => 'cct_created', 'label' => 'When', 'render' => fn( $v ) => esc_html( substr( (string) $v, 0, 10 ) ) ], 'type', [ 'key' => 'qty', 'label' => 'Quantity', 'render' => fn( $v ) => ( (float) $v > 0 ? '+' : '' ) . $num( (float) $v ) ], 'reason', 'ref_type' ], [ 'empty' => 'No movements yet.' ] ), [ 'open' => true, 'kind' => 'lead', 'hint' => 'newest 25' ] );
		}
		if ( current_user_can( 'wb_create_quotes' ) ) {
			$lines = WB_CCT::find( 'wb_quote_lines', [ 'product_id' => $id ], [ 'limit' => 20 ] );
			$h .= WB_Render::fold( 'Quoted', WB_Render::render_table( $lines, [ [ 'key' => 'quote_id', 'label' => 'Quote', 'render' => function ( $v ) { $q = WB_CCT::get( 'wb_quotes', (int) $v ); return $q ? '<a href="' . esc_url( WB_Workspace::url( 'quotes', [ 'quote' => (int) $v ] ) ) . '">' . esc_html( (string) ( $q['quote_number'] ?: 'Draft #' . (int) $v ) ) . '</a> · ' . WB_Screens::customer_link( (int) $q['customer_id'] ) : ''; } ],
				'qty', [ 'key' => 'unit_price', 'type' => 'money' ], 'price_source', [ 'key' => '_why', 'label' => 'Why it needs approval', 'render' => fn( $v, $r ) => esc_html( WB_Pricing::explain( $r ) ) ] ], [ 'empty' => 'Not quoted yet.' ] ), [ 'hint' => 'newest 20' ] );
		}
		$rules = WB_CCT::find( 'wb_price_rules', [ 'product_id' => $id ], [ 'limit' => 200 ] );
		if ( $rules ) $h .= WB_Render::fold( 'Customers with their own price', WB_Render::render_table( $rules, [ [ 'key' => 'customer_id', 'label' => 'Customer', 'render' => fn( $v ) => WB_Screens::customer_link( (int) $v ) ], 'rule_type', 'value', 'valid_to', 'status' ] ), [ 'hint' => count( $rules ) . ' rule' . ( 1 === count( $rules ) ? '' : 's' ) ] );
		$h .= self::more( 'documents', [ 'product' => $id ], 'Datasheets and documents for this product' );
		return $h;
	}
}
