<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Quick_Sale — a counter sale in one press (1.6.0, review of 9 October: "a cash customer at the
 * counter needs six presses for a R 300 sale").
 *
 * "Sold and paid" walks the same chain a sale always walks, so nothing about the records changes:
 * the quote is made and both price checks run, it is marked sent and accepted, the order and the
 * invoice are made, the payment is recorded against the invoice, the order is released through the
 * gate, and the collection note is issued and signed. Every step is the engine's own, with its own
 * checks, numbers, PDFs and audit entries.
 *
 * It is one press, not one database transaction: each step commits on its own, as it would if a
 * person pressed each button. If a step is refused (a price that needs approval, a closed account,
 * not enough stock), it stops there and says which step and why; everything before it is a valid
 * record that the normal screens carry on from. A price that needs approval is refused before
 * anything is written, because a counter sale cannot wait for a second person.
 */
class WB_Quick_Sale {

	const CAPS = [ 'wb_create_quotes', 'wb_send_quotes', 'wb_manage_orders', 'wb_match_payments', 'wb_issue_delivery_notes' ];

	/** The steps in order, as the result names them. */
	const STEPS = [ 'quote' => 'quote made', 'sent' => 'marked sent', 'order' => 'accepted as an order', 'invoice' => 'invoiced', 'paid' => 'paid', 'released' => 'released', 'note' => 'collection note issued', 'signed' => 'signed for' ];

	public static function init(): void {
		add_filter( 'wb_panel_handlers', function ( array $h ): array { $h['quick_sale'] = [ __CLASS__, 'handle' ]; return $h; } );
	}

	public static function allowed(): bool {
		foreach ( self::CAPS as $c ) if ( ! current_user_can( $c ) ) return false;
		return true;
	}

	/** What to say: the steps done, and where and why it stopped. Pure. */
	public static function report( array $done, string $stopped_at = '', string $why = '' ): string {
		$words = array_map( fn( $k ) => self::STEPS[ $k ] ?? $k, $done );
		if ( '' === $stopped_at ) return 'Sold and paid: ' . implode( ', ', $words ) . '.';
		return ( $words ? 'Done: ' . implode( ', ', $words ) . '. ' : '' ) . 'Stopped before "' . ( self::STEPS[ $stopped_at ] ?? $stopped_at ) . '": ' . $why
			. ( $words ? ' Everything done so far is on record; carry on from the order on the Orders screen.' : ' Nothing was written.' );
	}

	/**
	 * The chain. $lines: [ [ product_id, qty, manual_price? ] ]. Returns [ ok, message, ids ].
	 */
	public static function run( int $customer_id, array $lines, string $method, string $reference, string $collected_by ): array {
		$done = [];
		$ids  = [];
		$stop = function ( string $step, $err ) use ( &$done, &$ids ) { return [ false, self::report( $done, $step, is_wp_error( $err ) ? $err->get_error_message() : (string) $err ), $ids ]; };
		if ( ! self::allowed() ) return $stop( 'quote', 'A counter sale needs quotes, orders, payments and delivery notes, and one of those is not part of your work.' );
		if ( ! in_array( $method, [ 'cash', 'card', 'eft' ], true ) ) return $stop( 'quote', 'Choose how it was paid.' );
		if ( '' === trim( $reference ) ) return $stop( 'quote', 'Write the slip or receipt number.' );
		if ( '' === trim( $collected_by ) ) return $stop( 'quote', 'Write who is taking the goods.' );
		foreach ( $lines as $l ) {   // refuse a price that needs a second person before anything is written
			$p = WB_Pricing::price_for( $customer_id, (int) $l['product_id'], (float) $l['qty'], wb_today(), isset( $l['manual_price'] ) ? (float) $l['manual_price'] : null );
			if ( is_wp_error( $p ) ) return $stop( 'quote', $p );
			if ( ! empty( $p['needs_approval'] ) ) {
				$why = WB_Pricing::explain( array_merge( $p, [ 'below_floor' => $p['below_floor'] ? 'yes' : 'no', 'out_of_date' => $p['out_of_date'] ? 'yes' : 'no' ] ) );
				return $stop( 'quote', 'A price needs a second person\'s approval, so this cannot be a counter sale. ' . $why . ' Write it as a quote instead.' );
			}
			if ( WB_Stock::available( (int) $l['product_id'] ) + 0.0001 < (float) $l['qty'] ) {
				$prod = WB_CCT::get( 'wb_products', (int) $l['product_id'] );
				return $stop( 'quote', sprintf( 'Only %s of %s is available.', WB_Render::num( WB_Stock::available( (int) $l['product_id'] ) ), (string) ( $prod['sku'] ?? '' ) ) );
			}
		}
		$q = WB_Orders::create_quote( $customer_id, $lines, [ 'notes' => 'Counter sale' ] );
		if ( is_wp_error( $q ) ) return $stop( 'quote', $q );
		$ids['quote'] = (int) $q; $done[] = 'quote';
		$s = WB_Orders::send_quote( (int) $q );
		if ( is_wp_error( $s ) ) return $stop( 'sent', $s );
		$done[] = 'sent';
		$o = WB_Orders::accept_quote( (int) $q, 'At the counter: ' . sanitize_text_field( $collected_by ) );
		if ( is_wp_error( $o ) ) return $stop( 'order', $o );
		$ids['order'] = (int) $o; $done[] = 'order';
		$inv = WB_Invoices::for_order( (int) $o );
		if ( ! $inv ) {
			$i = WB_Invoices::issue_for_order( (int) $o );
			if ( is_wp_error( $i ) ) return $stop( 'invoice', $i );
			$inv = WB_Invoices::for_order( (int) $o );
		}
		if ( ! $inv ) return $stop( 'invoice', 'The invoice could not be found after it was issued.' );
		$ids['invoice'] = (int) $inv['_ID']; $done[] = 'invoice';
		$r = WB_Payments::record_receipt( (int) $inv['_ID'], WB_Invoices::outstanding( $inv ), $method, $reference );
		if ( is_wp_error( $r ) ) return $stop( 'paid', $r );
		$done[] = 'paid';
		$order = WB_CCT::get( 'wb_orders', (int) $o );
		if ( ! in_array( (string) $order['status'], [ 'ready', 'part_delivered' ], true ) ) {
			$rel = WB_Orders::release( (int) $o );
			if ( is_wp_error( $rel ) ) return $stop( 'released', $rel );
		}
		$done[] = 'released';
		$qtys = [];
		foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => (int) $o ], [ 'limit' => 1000 ] ) as $l ) $qtys[ (int) $l['_ID'] ] = (float) $l['qty_ordered'] - (float) $l['qty_delivered'];
		$dn = WB_Orders::issue_delivery_note( (int) $o, $qtys, [ 'type' => 'collection' ] );
		if ( is_wp_error( $dn ) ) return $stop( 'note', $dn );
		$ids['note'] = (int) $dn; $done[] = 'note';
		$sig = WB_Orders::confirm_collection( (int) $dn, $collected_by );
		if ( is_wp_error( $sig ) ) return $stop( 'signed', $sig );
		$done[] = 'signed';
		return [ true, self::report( $done ), $ids ];
	}

	/** The fold on Quotes. */
	public static function fold(): string {
		if ( ! self::allowed() ) return '';
		$f = WB_Render::form_open( 'quick_sale' )
			. WB_Render::field( 'customer_id', 'Customer', 'select', '', [ 'options' => WB_Render::options( 'wb_customers', 'name', [ 'account_status' => 'open' ] ), 'required' => true, 'id' => 'wb-qs-customer' ] )
			. '<div class="wb-line-add">' . WB_Quote_Editor::product_field( 'product_id', 'Product', 0, 'quote', 'wb-qs-product' )
			. WB_Render::field( 'qty', 'Quantity', 'number', '1', [ 'required' => true, 'id' => 'wb-qs-qty' ] ) . WB_Render::field( 'price', 'Your own price (optional)', 'number', '', [ 'id' => 'wb-qs-price' ] ) . '</div>'
			. '<details class="wb-paste"><summary>Several products</summary>' . WB_Render::field( 'lines', 'One line each: product code, quantity, and a price only to type your own', 'textarea', '', [ 'rows' => 3, 'id' => 'wb-qs-lines' ] ) . '</details>'
			. WB_Render::field( 'method', 'Paid by', 'select', 'card', [ 'options' => [ 'card' => 'Card', 'cash' => 'Cash', 'eft' => 'EFT (proof shown)' ], 'id' => 'wb-qs-method' ] )
			. WB_Render::field( 'reference', 'Slip or receipt number', 'text', '', [ 'required' => true, 'id' => 'wb-qs-ref' ] )
			. WB_Render::field( 'collected_by', 'Taken by', 'text', '', [ 'required' => true, 'placeholder' => 'Name of the person taking the goods', 'id' => 'wb-qs-who' ] );
		return WB_Render::fold( 'Quick sale at the counter', '<p class="wb-muted">Paid now and taken now. One press makes the quote, the order, the invoice, the payment and the signed collection note, each with its own number. A price that needs approval cannot be a counter sale.</p>' . $f . WB_Render::form_close( 'Sold and paid', 'Record this sale as paid and collected?' ), [ 'id' => 'wb-quick-sale', 'kind' => 'sibling', 'hint' => 'paid and taken now' ] );
	}

	public static function handle() {
		$paste = trim( (string) wp_unslash( $_POST['lines'] ?? '' ) );
		if ( '' !== $paste ) {
			$lines = WB_Screens::parse_lines( $paste );
			if ( is_wp_error( $lines ) ) return $lines;
		} else {
			$p = WB_Quote_Editor::posted_product();
			if ( is_wp_error( $p ) ) return $p;
			$price = trim( (string) wp_unslash( $_POST['price'] ?? '' ) );
			$lines = [ [ 'product_id' => (int) $p['_ID'], 'qty' => (float) ( $_POST['qty'] ?? 1 ) ] + ( '' !== $price ? [ 'manual_price' => (float) $price ] : [] ) ];
		}
		[ $ok, $msg, $ids ] = self::run( absint( $_POST['customer_id'] ?? 0 ), $lines, sanitize_key( (string) ( $_POST['method'] ?? '' ) ), sanitize_text_field( (string) wp_unslash( $_POST['reference'] ?? '' ) ), sanitize_text_field( (string) wp_unslash( $_POST['collected_by'] ?? '' ) ) );
		if ( ! empty( $ids['order'] ) ) $_POST['_wb_return'] = WB_Workspace::url( 'orders', [ 'order' => (int) $ids['order'] ] );
		return $ok ? [ 'msg' => $msg ] : new WP_Error( 'wb_quick_sale', $msg );
	}
}
