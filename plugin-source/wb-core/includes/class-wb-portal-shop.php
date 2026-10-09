<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Portal_Shop — a customer orders from their own account (1.7.0, review of 9 October: "the
 * portal is a reader; it should let a customer order").
 *
 *  - Their products: what they have bought or been quoted, at their own price (their rules, their
 *    tier, else list), with the stock in words (in stock, low, to order), and a search across the
 *    rest of the range.
 *  - "Order again" on any past order puts its products and quantities in the basket.
 *  - The basket becomes a request for a quote, exactly as the old request form did: a DRAFT for the
 *    rep, priced by both checks, never sent or accepted by itself. The rep sends the quote; the
 *    customer accepts it here. Nothing about the control changes, only the effort.
 *
 * A price that would need a second person's approval (no cost on file, out of date) shows as
 * "Price on request": the customer is never shown a figure the rep could not send. Only the words
 * "in stock", "low" and "to order" are shown, never the number. basket_lines(), stock_words() and
 * reorder_lines() are pure.
 */
class WB_Portal_Shop {

	const META = 'wb_portal_basket';   // per login: [ product_id => qty ], a working draft only
	const MAX_LINES = 60;

	public static function init(): void {
		add_shortcode( 'wb_portal_shop', [ __CLASS__, 'render' ] );
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			foreach ( [ 'basket_add', 'basket_update', 'basket_reorder', 'basket_send' ] as $a ) $h[ 'portal_' . $a ] = [ __CLASS__, 'handle_' . $a ];
			return $h;
		} );
	}

	/* ------------------------------------------------------------------ pure */

	/** What a customer is told about stock: never the number. Pure. */
	public static function stock_words( float $available, float $reorder_point, float $qty_wanted = 1 ): string {
		if ( $available <= 0.0001 ) return 'To order';
		if ( $available < $qty_wanted ) return 'Part in stock';
		return $available <= $reorder_point ? 'Low stock' : 'In stock';
	}

	/** A basket after adding or setting quantities: whole products only, at most MAX_LINES, nothing under zero. Pure. */
	public static function basket_lines( array $basket, array $changes, bool $add = false ): array {
		foreach ( $changes as $pid => $qty ) {
			$pid = (int) $pid;
			$qty = max( 0.0, round( (float) $qty, 3 ) );
			if ( $pid <= 0 ) continue;
			$new = $add ? (float) ( $basket[ $pid ] ?? 0 ) + $qty : $qty;
			if ( $new <= 0 ) unset( $basket[ $pid ] ); else $basket[ $pid ] = min( $new, 1000000.0 );
		}
		return array_slice( $basket, 0, self::MAX_LINES, true );
	}

	/** An old order's lines as basket changes: product → quantity ordered (repeats added up). Pure. */
	public static function reorder_lines( array $order_lines ): array {
		$out = [];
		foreach ( $order_lines as $l ) {
			$pid = (int) ( $l['product_id'] ?? 0 );
			if ( $pid > 0 ) $out[ $pid ] = ( $out[ $pid ] ?? 0 ) + (float) ( $l['qty_ordered'] ?? 0 );
		}
		return array_filter( $out, fn( $q ) => $q > 0 );
	}

	/* ------------------------------------------------------------------ the basket */

	private static function basket(): array {
		return array_map( 'floatval', (array) get_user_meta( get_current_user_id(), self::META, true ) );
	}

	private static function save( array $b ): void {
		update_user_meta( get_current_user_id(), self::META, $b );
	}

	/** This customer's price for a product, or null for "price on request". */
	public static function price( int $customer_id, int $product_id, float $qty = 1 ): ?float {
		$p = WB_Pricing::price_for( $customer_id, $product_id, max( 1, $qty ) );
		if ( is_wp_error( $p ) || ! empty( $p['no_cost'] ) || ! empty( $p['out_of_date'] ) ) return null;
		return (float) $p['unit_price'];
	}

	/* ------------------------------------------------------------------ the page */

	public static function render( $atts = [] ): string {
		$c = WB_Portal::contact();
		if ( ! $c ) return wb_notice( 'warn', 'Please sign in with your customer login.' );
		$cid    = (int) $c['customer_id'];
		$basket = self::basket();
		$q      = sanitize_text_field( (string) wp_unslash( $_GET['pq'] ?? '' ) );
		$h      = '';
		if ( 'on_hold' === (string) ( $c['_customer']['account_status'] ?? '' ) ) $h .= wb_notice( 'warn', 'Your account is on hold. You can still ask for a quote; we will be in touch about the account.' );

		// the basket first, when there is one
		if ( $basket ) {
			$rows  = [];
			$total = 0.0;
			$priced_all = true;
			foreach ( $basket as $pid => $qty ) {
				$p = WB_CCT::get( 'wb_products', (int) $pid );
				if ( ! $p ) continue;
				$each = self::price( $cid, (int) $pid, $qty );
				if ( null === $each ) $priced_all = false; else $total += $each * $qty;
				$rows[] = [ $p, $qty, $each ];
			}
			$f = WB_Render::form_open( 'portal_basket_update' ) . '<table class="wb-list wb-list--cards wb-lines"><thead><tr><th>Product</th><th class="wb-col-num">Quantity</th><th class="wb-col-money">Each</th><th class="wb-col-money">Estimate</th><th>Stock</th></tr></thead><tbody>';
			foreach ( $rows as [ $p, $qty, $each ] ) {
				$id = (int) $p['_ID'];
				$f .= '<tr><td data-label="Product">' . esc_html( $p['sku'] . ' · ' . $p['name'] ) . '</td>'
					. '<td data-label="Quantity" class="wb-col-num"><label class="wb-sr" for="wb-b-' . $id . '">Quantity of ' . esc_html( (string) $p['name'] ) . '</label><input id="wb-b-' . $id . '" type="number" step="any" min="0" name="qty[' . $id . ']" value="' . esc_attr( WB_Render::num( $qty ) ) . '"></td>'
					. '<td data-label="Each" class="wb-col-money">' . ( null === $each ? 'Price on request' : 'R&nbsp;' . esc_html( WB_Render::money( $each ) ) ) . '</td>'
					. '<td data-label="Estimate" class="wb-col-money">' . ( null === $each ? '' : 'R&nbsp;' . esc_html( WB_Render::money( $each * $qty ) ) ) . '</td>'
					. '<td data-label="Stock">' . esc_html( self::stock_words( WB_Stock::available( $id ), (float) $p['reorder_point'], $qty ) ) . '</td></tr>';
			}
			$f .= '</tbody><tfoot><tr><td colspan="5" class="wb-lines-tot">Estimate before VAT <strong>R ' . esc_html( WB_Render::money( $total ) ) . ( $priced_all ? '' : ' + the items on request' ) . '</strong></td></tr></tfoot></table>'
				. '<p class="wb-lines-save"><button type="submit" class="wb-btn wb-btn-ghost">Update quantities</button><small>Set a quantity to 0 to take it out.</small></p></form>';
			$f .= WB_Render::form_open( 'portal_basket_send' ) . WB_Render::field( 'note', 'Anything we should know (delivery, dates, an order number of yours)', 'textarea', '', [ 'rows' => 3, 'id' => 'wb-basket-note' ] )
				. WB_Render::form_close( 'Ask for a quote for these' );
			$h .= '<section class="wb-card wb-card--lead wb-basket" aria-labelledby="wb-basket-h"><h2 id="wb-basket-h">Your basket</h2><p class="wb-muted">These prices are an estimate from your account. We check them and send you the quote to accept; nothing is ordered until you accept it.</p>' . $f . '</section>';
		}

		// order again
		$orders = WB_CCT::find( 'wb_orders', [ 'customer_id' => $cid ], [ 'limit' => 8 ] );
		if ( $orders ) {
			$h .= '<h3>Order again</h3>' . WB_Render::render_table( $orders, [ 'order_number', [ 'key' => 'cct_created', 'label' => 'Ordered', 'render' => fn( $v ) => esc_html( WB_Render::date( $v ) ) ], [ 'key' => 'total', 'type' => 'money' ] ], [
				'action_html' => fn( $o ) => WB_Render::form_open( 'portal_basket_reorder' ) . '<input type="hidden" name="order_id" value="' . (int) $o['_ID'] . '">' . WB_RowActions::menuitem( 'add', 'Put these in my basket', [ 'submit' => true ] ) . '</form>',
			] );
		}

		// the products: theirs, or what they searched for
		$base = WB_Workspace::url( 'portal' );
		$h   .= '<h3>' . ( '' !== $q ? 'Products matching "' . esc_html( $q ) . '"' : 'Your products' ) . '</h3>'
			. '<form method="get" action="' . esc_url( $base ) . '" class="wb-listbar" role="search"><label class="wb-listbar-q"><span class="wb-sr">Search the range</span><input type="search" name="pq" value="' . esc_attr( $q ) . '" placeholder="Search the whole range by code or name"></label><button type="submit" class="wb-btn wb-btn-sm wb-btn-ghost">Search</button>'
			. ( '' !== $q ? '<a class="wb-listbar-clear" href="' . esc_url( $base ) . '">Your products</a>' : '' ) . '</form>';
		$prods = '' !== $q
			? WB_CCT::find( 'wb_products', [ 'status' => 'active', '_search' => [ 'cols' => [ 'sku', 'name' ], 'q' => $q ] ], [ 'limit' => 30, 'orderby' => 'sku', 'order' => 'ASC' ] )
			: array_values( array_filter( array_map( fn( $pid ) => WB_CCT::get( 'wb_products', $pid ), array_slice( WB_Portal::product_ids( $cid ), 0, 60 ) ), fn( $p ) => $p && 'active' === (string) $p['status'] ) );
		if ( ! $prods ) return $h . WB_Render::state( 'empty', '' !== $q ? 'Nothing matches that.' : 'Products you buy or are quoted appear here.', 'Search the whole range above.' );
		$f = WB_Render::form_open( 'portal_basket_add' ) . '<table class="wb-list wb-list--cards"><thead><tr><th>Product</th><th class="wb-col-money">Your price</th><th>Stock</th><th class="wb-col-num">Quantity</th></tr></thead><tbody>';
		foreach ( $prods as $p ) {
			$id   = (int) $p['_ID'];
			$each = self::price( $cid, $id );
			$f .= '<tr><td data-label="Product"><strong>' . esc_html( (string) $p['sku'] ) . '</strong> · ' . esc_html( (string) $p['name'] ) . '<br><small class="wb-muted">per ' . esc_html( (string) $p['unit'] ) . '</small></td>'
				. '<td data-label="Your price" class="wb-col-money">' . ( null === $each ? 'Price on request' : 'R&nbsp;' . esc_html( WB_Render::money( $each ) ) ) . '</td>'
				. '<td data-label="Stock">' . esc_html( self::stock_words( WB_Stock::available( $id ), (float) $p['reorder_point'] ) ) . '</td>'
				. '<td data-label="Quantity" class="wb-col-num"><label class="wb-sr" for="wb-a-' . $id . '">How many ' . esc_html( (string) $p['name'] ) . '</label><input id="wb-a-' . $id . '" type="number" step="any" min="0" name="qty[' . $id . ']" value="" placeholder="0"></td></tr>';
		}
		return $h . $f . '</tbody></table><p><button type="submit" class="wb-btn">Add to my basket</button></p></form>';
	}

	/* ------------------------------------------------------------------ the handlers */

	private static function who() {
		$c = WB_Portal::contact();
		return $c ?: new WP_Error( 'wb_forbidden', 'Your login is not linked to a company.' );
	}

	public static function handle_basket_add() {
		$c = self::who(); if ( is_wp_error( $c ) ) return $c;
		$add = array_filter( array_map( 'floatval', (array) wp_unslash( $_POST['qty'] ?? [] ) ), fn( $q ) => $q > 0 );
		if ( ! $add ) return new WP_Error( 'wb_none', 'Type a quantity beside the products you want.' );
		foreach ( array_keys( $add ) as $pid ) { $p = WB_CCT::get( 'wb_products', (int) $pid ); if ( ! $p || 'active' !== (string) $p['status'] ) return new WP_Error( 'wb_product', 'One of those products is no longer available.' ); }
		self::save( self::basket_lines( self::basket(), $add, true ) );
		return [ 'msg' => count( $add ) . ' product' . ( 1 === count( $add ) ? '' : 's' ) . ' in your basket.' ];
	}

	public static function handle_basket_update() {
		$c = self::who(); if ( is_wp_error( $c ) ) return $c;
		self::save( self::basket_lines( self::basket(), (array) wp_unslash( $_POST['qty'] ?? [] ) ) );
		return [ 'msg' => 'Basket updated.' ];
	}

	public static function handle_basket_reorder() {
		$c = self::who(); if ( is_wp_error( $c ) ) return $c;
		$o = WB_CCT::get( 'wb_orders', absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $o || (int) $o['customer_id'] !== (int) $c['customer_id'] ) return new WP_Error( 'wb_forbidden', 'That order is not one of yours.' );   // fail closed
		$lines = self::reorder_lines( WB_CCT::find( 'wb_order_lines', [ 'order_id' => (int) $o['_ID'] ], [ 'limit' => 500 ] ) );
		$lines = array_filter( $lines, function ( $q, $pid ) { $p = WB_CCT::get( 'wb_products', (int) $pid ); return $p && 'active' === (string) $p['status']; }, ARRAY_FILTER_USE_BOTH );
		if ( ! $lines ) return new WP_Error( 'wb_none', 'None of the products on that order can be ordered now.' );
		self::save( self::basket_lines( self::basket(), $lines, true ) );
		return [ 'msg' => 'The products from ' . $o['order_number'] . ' are in your basket at today\'s prices. Change the quantities, then ask for the quote.' ];
	}

	public static function handle_basket_send() {
		$c = self::who(); if ( is_wp_error( $c ) ) return $c;
		$b = self::basket();
		if ( ! $b ) return new WP_Error( 'wb_none', 'Your basket is empty.' );
		$lines = [];
		foreach ( $b as $pid => $qty ) $lines[] = [ 'product_id' => (int) $pid, 'qty' => (float) $qty ];
		$qid = WB_Portal::request_quote( $lines, (string) wp_unslash( $_POST['note'] ?? '' ) );
		if ( is_wp_error( $qid ) ) return $qid;
		self::save( [] );
		return [ 'msg' => 'Thank you. Your request is with us; we will check the prices and send you the quote to accept.' ];
	}
}
