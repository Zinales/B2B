<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Quote_Editor — writing a quote the way people expect to (1.5.0, review of 9 October: "writing
 * a quote is the weakest screen, and it is the one sales lives in").
 *
 *  - Find a product by typing part of its code, name or barcode: a list of matches appears with
 *    the customer's price, where the price comes from, and what is in stock. Without the script the
 *    same field takes an exact code, so nothing depends on it.
 *  - The draft's lines are a form: quantity and price editable in place, saved in one press. A
 *    typed price is a manual price; a changed quantity re-prices by the customer's rules (a manual
 *    price stays manual). Every line is put through both checks again on save, on the server: the
 *    browser never works a price out.
 *  - A line that breaks a rule says why, in a sentence, on the line, with "Ask for approval" beside it.
 *  - Pasting many lines at once ("ABC-100, 20") stays, folded away, for people who work that way.
 *
 * rank() is pure (tested without WordPress).
 */
class WB_Quote_Editor {

	public static function init(): void {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'wb/v1', '/products', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'search' ],
				'permission_callback' => fn() => is_user_logged_in() && ( current_user_can( 'wb_create_quotes' ) || current_user_can( 'wb_manage_purchasing' ) || current_user_can( 'wb_view_products' ) ) ] );
		} );
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			$h['quote_add']   = [ __CLASS__, 'handle_add' ];
			$h['quote_lines'] = [ __CLASS__, 'handle_lines' ];
			return $h;
		} );
	}

	/* ------------------------------------------------------------------ pure */

	/**
	 * Order matches the way a person expects: the exact code, then codes starting with the words,
	 * then names starting with them, then anything containing them; within each, by code. Pure.
	 */
	public static function rank( array $products, string $q, int $limit = 12 ): array {
		$q = strtolower( trim( $q ) );
		if ( '' === $q ) return [];
		$scored = [];
		foreach ( $products as $p ) {
			$sku  = strtolower( (string) ( $p['sku'] ?? '' ) );
			$name = strtolower( (string) ( $p['name'] ?? '' ) );
			$bar  = (string) ( $p['barcode'] ?? '' );
			if ( $sku === $q || ( '' !== $bar && $bar === $q ) ) $s = 0;
			elseif ( 0 === strpos( $sku, $q ) ) $s = 1;
			elseif ( 0 === strpos( $name, $q ) ) $s = 2;
			elseif ( false !== strpos( $sku, $q ) || false !== strpos( $name, $q ) ) $s = 3;
			else {
				$words = preg_split( '/\s+/', $q );   // every word somewhere in the name or code: "epoxy 200"
				if ( count( $words ) < 2 || count( array_filter( $words, fn( $w ) => false === strpos( $sku . ' ' . $name, $w ) ) ) ) continue;
				$s = 4;
			}
			$scored[] = [ $s, $sku, $p ];
		}
		usort( $scored, fn( $a, $b ) => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
		return array_map( fn( $x ) => $x[2], array_slice( $scored, 0, $limit ) );
	}

	/**
	 * What a typed product reference means: [ product row ] on one match, or the words to show.
	 * $exact: the product found by code; $like: products whose code or name contains the text.
	 */
	public static function resolve( string $text, ?array $exact, array $like ) {
		$text = trim( $text );
		if ( '' === $text ) return 'Choose a product.';
		if ( $exact ) return $exact;
		$top = self::rank( $like, $text, 6 );
		if ( 1 === count( $top ) ) return $top[0];
		if ( ! $top ) return sprintf( 'No product matches "%s".', $text );
		return sprintf( 'More than one product matches "%s": %s. Type more of the code, or choose one from the list.', $text, implode( ', ', array_map( fn( $p ) => (string) $p['sku'], array_slice( $top, 0, 4 ) ) ) );
	}

	/* ------------------------------------------------------------------ the search route */

	/** GET wb/v1/products?q=&customer=&for=quote|po → up to 12 matches with the right price for the job. */
	public static function search( WP_REST_Request $req ) {
		$q    = sanitize_text_field( (string) $req->get_param( 'q' ) );
		$cid  = absint( $req->get_param( 'customer' ) );
		$for  = 'po' === $req->get_param( 'for' ) ? 'po' : 'quote';
		if ( mb_strlen( $q ) < 1 ) return [];
		$like = WB_CCT::find( 'wb_products', [ 'status' => 'active', '_search' => [ 'cols' => [ 'sku', 'name', 'barcode' ], 'q' => $q ] ], [ 'limit' => 60, 'orderby' => 'sku', 'order' => 'ASC' ] );
		if ( count( preg_split( '/\s+/', trim( $q ) ) ) > 1 ) $like = array_merge( $like, WB_CCT::find( 'wb_products', [ 'status' => 'active', '_search' => [ 'cols' => [ 'sku', 'name' ], 'q' => (string) strtok( $q, ' ' ) ] ], [ 'limit' => 60 ] ) );
		$out  = [];
		$seen = [];
		foreach ( self::rank( $like, $q ) as $p ) {
			if ( isset( $seen[ (int) $p['_ID'] ] ) ) continue;
			$seen[ (int) $p['_ID'] ] = true;
			$row = [ 'id' => (int) $p['_ID'], 'sku' => (string) $p['sku'], 'name' => (string) $p['name'], 'unit' => (string) $p['unit'] ];
			if ( current_user_can( 'wb_view_stock' ) ) $row['available'] = WB_Stock::available( (int) $p['_ID'] );
			if ( 'po' === $for && current_user_can( 'wb_manage_purchasing' ) ) {
				$row['price'] = (float) $p['cost_price'];
				$row['note']  = 'cost';
			} elseif ( $cid && current_user_can( 'wb_create_quotes' ) ) {
				$pr = WB_Pricing::price_for( $cid, (int) $p['_ID'], 1 );
				if ( ! is_wp_error( $pr ) ) {
					$row['price'] = (float) $pr['unit_price'];
					$row['note']  = WB_Render::words( (string) $pr['price_source'] );
					$why          = WB_Pricing::explain( array_merge( $pr, [ 'below_floor' => $pr['below_floor'] ? 'yes' : 'no', 'out_of_date' => $pr['out_of_date'] ? 'yes' : 'no' ] ) );
					if ( '' !== $why ) $row['warn'] = $why;
				}
			} else {
				$row['price'] = (float) $p['list_price'];
				$row['note']  = 'list';
			}
			$out[] = $row;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ the parts of the screen */

	/** The search field: a text input that takes a code, enhanced by the script into a list of matches. */
	public static function product_field( string $name, string $label, int $customer_id = 0, string $for = 'quote', string $id = '', string $into = '' ): string {
		$id  = '' !== $id ? $id : 'wb-' . sanitize_html_class( $name );
		$url = add_query_arg( array_filter( [ 'customer' => $customer_id ?: null, 'for' => $for, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ] ), rest_url( 'wb/v1/products' ) );
		return '<div class="wb-field wb-pick" data-wb-pick="' . esc_url( $url ) . '"' . ( '' !== $into ? ' data-wb-pick-into="' . esc_attr( $into ) . '"' : '' ) . '><label for="' . esc_attr( $id ) . '"><span>' . esc_html( $label ) . '</span></label>'
			. '<input id="' . esc_attr( $id ) . '" type="text" name="' . esc_attr( $name ) . '_text" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '-list" placeholder="Type a code or part of a name">'
			. '<input type="hidden" name="' . esc_attr( $name ) . '" value="">'
			. '<ul class="wb-pick-list" id="' . esc_attr( $id ) . '-list" role="listbox" hidden></ul><small class="wb-pick-note" aria-live="polite"></small></div>';
	}

	/** The new-quote form: the customer, the first product, a quantity; more lines are added on the quote itself. */
	public static function new_form( int $customer_id = 0 ): string {
		$f = WB_Render::form_open( 'quote_add' )
			. WB_Render::field( 'customer_id', 'Customer', 'select', $customer_id ?: '', [ 'options' => WB_Render::options( 'wb_customers', 'name', [ 'account_status' => [ 'open', 'on_hold' ] ] ), 'required' => true, 'note' => 'Prices in the product list are this customer\'s own once one is chosen and the page has the quote open.' ] )
			. '<div class="wb-line-add">' . self::product_field( 'product_id', 'Product', $customer_id, 'quote', 'wb-new-product' )
			. WB_Render::field( 'qty', 'Quantity', 'number', '1', [ 'required' => true, 'id' => 'wb-new-qty' ] ) . WB_Render::field( 'price', 'Your own price (optional)', 'number', '', [ 'id' => 'wb-new-price' ] ) . '</div>'
			. WB_Render::field( 'notes', 'Note to the customer', 'textarea', '', [ 'rows' => 2 ] )
			. '<details class="wb-paste"><summary>Paste several lines instead</summary>' . WB_Render::field( 'lines', 'One line each: product code, quantity, and a price only to type your own', 'textarea', '', [ 'rows' => 4, 'placeholder' => "ADH-EP200, 20\nFST-HN16, 5, 149.50", 'id' => 'wb-new-lines' ] ) . '</details>'
			. WB_Render::form_close( 'Start the quote' );
		return $f;
	}

	/** A draft quote's lines as a form, with the add-a-product row under them. */
	public static function draft( array $q, array $lines ): string {
		$qid = (int) $q['_ID'];
		$h   = WB_Render::form_open( 'quote_lines' ) . '<input type="hidden" name="quote_id" value="' . $qid . '">'
			. '<table class="wb-list wb-list--cards wb-lines"><thead><tr><th>Product</th><th class="wb-col-num">Quantity</th><th class="wb-col-num">Price each</th><th>From</th><th class="wb-col-money">Line total</th><th class="wb-col-actions" aria-label="Actions"></th></tr></thead><tbody>';
		foreach ( $lines as $l ) {
			$lid  = (int) $l['_ID'];
			$why  = WB_Pricing::explain( $l );
			$h .= '<tr class="' . ( '' !== $why ? 'is-flagged' : '' ) . '"><td data-label="Product">' . esc_html( (string) $l['description'] )
				. ( '' !== $why ? '<p class="wb-line-why" role="note">' . esc_html( $why ) . '</p>' : '' ) . '</td>'
				. '<td data-label="Quantity" class="wb-col-num"><label class="wb-sr" for="wb-q-' . $lid . '">Quantity of ' . esc_html( (string) $l['description'] ) . '</label><input id="wb-q-' . $lid . '" type="number" step="any" min="0" name="qty[' . $lid . ']" value="' . esc_attr( WB_Render::num( $l['qty'] ) ) . '"></td>'
				. '<td data-label="Price each" class="wb-col-num"><label class="wb-sr" for="wb-p-' . $lid . '">Price each of ' . esc_html( (string) $l['description'] ) . '</label><input id="wb-p-' . $lid . '" type="number" step="0.01" min="0" name="price[' . $lid . ']" value="' . esc_attr( number_format( (float) $l['unit_price'], 2, '.', '' ) ) . '"></td>'
				. '<td data-label="From">' . esc_html( WB_Render::words( (string) $l['price_source'] ) ) . '</td>'
				. '<td data-label="Line total" class="wb-col-money">' . esc_html( WB_Render::money( (float) $l['line_total'] ) ) . '</td>'
				. '<td class="wb-row-actions" data-label="Actions">' . WB_Render::kebab( WB_RowActions::cell( [ 'line_approval', 'line_remove' ], 'wb_quote_lines', $l ) ) . '</td></tr>';
		}
		$h .= '</tbody><tfoot><tr><td colspan="6" class="wb-lines-tot">Subtotal R ' . esc_html( WB_Render::money( (float) $q['subtotal'] ) ) . '<span>VAT R ' . esc_html( WB_Render::money( (float) $q['vat'] ) ) . '</span><strong>Total R ' . esc_html( WB_Render::money( (float) $q['total'] ) ) . '</strong></td></tr></tfoot></table>'
			. ( $lines ? '<p class="wb-lines-save"><button type="submit" class="wb-btn wb-btn-ghost">Save changes</button><small>Change a quantity or type a price, then save. Every line is checked again.</small></p>' : '' ) . '</form>';
		$h .= WB_Render::form_open( 'quote_add' ) . '<input type="hidden" name="quote_id" value="' . $qid . '"><div class="wb-line-add">' . self::product_field( 'product_id', 'Add a product', (int) $q['customer_id'], 'quote', 'wb-add-product' )
			. WB_Render::field( 'qty', 'Quantity', 'number', '1', [ 'required' => true, 'id' => 'wb-add-qty' ] ) . WB_Render::field( 'price', 'Your own price (optional)', 'number', '', [ 'id' => 'wb-add-price' ] ) . '<button type="submit" class="wb-btn">Add</button></div>'
			. '<details class="wb-paste"><summary>Paste several lines instead</summary>' . WB_Render::field( 'lines', 'One line each: product code, quantity, and a price only to type your own', 'textarea', '', [ 'rows' => 3, 'placeholder' => "ADH-EP200, 20\nFST-HN16, 5, 149.50", 'id' => 'wb-draft-lines' ] ) . '<button type="submit" class="wb-btn wb-btn-ghost">Add these lines</button></details></form>';
		return $h;
	}

	/* ------------------------------------------------------------------ the handlers */

	private static function p( string $k, $d = '' ) { return isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : $d; }

	/** The product a form posted: the id the list chose, else the typed text. @return array|WP_Error */
	public static function posted_product() {
		$id = absint( self::p( 'product_id' ) );
		if ( $id && ( $p = WB_CCT::get( 'wb_products', $id ) ) ) return $p;
		$text  = sanitize_text_field( (string) self::p( 'product_id_text' ) );
		$exact = '' !== trim( $text ) ? ( WB_CCT::first( 'wb_products', [ 'sku' => trim( $text ) ] ) ?: ( ctype_digit( trim( $text ) ) ? WB_CCT::first( 'wb_products', [ 'barcode' => trim( $text ) ] ) : null ) ) : null;
		$like  = '' !== trim( $text ) && ! $exact ? WB_CCT::find( 'wb_products', [ 'status' => 'active', '_search' => [ 'cols' => [ 'sku', 'name' ], 'q' => trim( $text ) ] ], [ 'limit' => 20 ] ) : [];
		$r     = self::resolve( $text, $exact, $like );
		return is_array( $r ) ? $r : new WP_Error( 'wb_product', $r );
	}

	/** Start a quote, or add to a draft: one product from the field, or pasted lines. */
	public static function handle_add() {
		$qid   = absint( self::p( 'quote_id' ) );
		$paste = trim( (string) self::p( 'lines' ) );
		if ( '' !== $paste ) {
			$lines = WB_Screens::parse_lines( $paste );
			if ( is_wp_error( $lines ) ) return $lines;
		} else {
			$p = self::posted_product();
			if ( is_wp_error( $p ) ) return $p;
			$qty   = (float) self::p( 'qty', 1 );
			$price = trim( (string) self::p( 'price' ) );
			$lines = [ [ 'product_id' => (int) $p['_ID'], 'qty' => $qty ] + ( '' !== $price ? [ 'manual_price' => (float) $price ] : [] ) ];
		}
		if ( ! $qid ) {
			$id = WB_Orders::create_quote( absint( self::p( 'customer_id' ) ), $lines, [ 'notes' => (string) self::p( 'notes' ) ] );
			if ( is_wp_error( $id ) ) return $id;
			$_POST['_wb_return'] = add_query_arg( 'quote', $id, remove_query_arg( 'customer', (string) ( $_POST['_wb_return'] ?? '' ) ) );
			return [ 'msg' => 'Quote started. Add more products below; every line is checked as it is added.' ];
		}
		foreach ( $lines as $l ) {
			$r = WB_Orders::add_quote_line( $qid, (int) $l['product_id'], (float) $l['qty'], $l['manual_price'] ?? null );
			if ( is_wp_error( $r ) ) return $r;
		}
		return [ 'msg' => 1 === count( $lines ) ? 'Added.' : count( $lines ) . ' lines added.' ];
	}

	/** Save the draft's quantities and prices: only the lines that changed are re-priced. */
	public static function handle_lines() {
		$qid   = absint( self::p( 'quote_id' ) );
		$qtys  = (array) self::p( 'qty', [] );
		$price = (array) self::p( 'price', [] );
		$n     = 0;
		foreach ( $qtys as $lid => $qty ) {
			$line = WB_CCT::get( 'wb_quote_lines', absint( $lid ) );
			if ( ! $line || (int) $line['quote_id'] !== $qid ) continue;
			$r = WB_Orders::update_quote_line( (int) $line['_ID'], (float) $qty, isset( $price[ $lid ] ) && '' !== trim( (string) $price[ $lid ] ) ? (float) $price[ $lid ] : null );
			if ( is_wp_error( $r ) ) return $r;
			if ( 'changed' === $r ) $n++;
		}
		return [ 'msg' => $n ? $n . ' line' . ( 1 === $n ? '' : 's' ) . ' saved and checked again.' : 'Nothing had changed.' ];
	}
}
