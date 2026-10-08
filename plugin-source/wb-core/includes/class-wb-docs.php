<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Docs — the numbered documents as PDF: quote, invoice, credit note, delivery note, purchase
 * order (1.1.0, Zina: "please convert to pdf").
 *
 * One letterhead (the company from System Settings: name, registration and VAT numbers, address,
 * logo; the bank details and the footer line the owner types there), one table of lines, the
 * totals with VAT shown once, and the words each document needs. The HTML is pure (html()), so it
 * is tested without WordPress; WB_Pdf turns it into bytes; the bytes go into the private folder
 * and a wb_documents row (so the portal, the tokened download and the audit trail all work as
 * for any other file); the source row's pdf_key points at the file.
 *
 * A PDF is made when the document is issued (quote sent, invoice issued, credit note approved,
 * delivery note issued, purchase order sent), after the transaction has committed, and on demand
 * from the ⋯ menu for anything issued before 1.1.0. An issued document is immutable, so its PDF
 * is made once and kept.
 */
class WB_Docs {

	/** kind => [ table, number column, document type, title, the cap that may open it, customer-visible ] */
	const KINDS = [
		'quote'    => [ 'wb_quotes',          'quote_number',   'quote_pdf',   'Quote',          'wb_create_quotes',         true ],
		'invoice'  => [ 'wb_invoices',        'invoice_number', 'invoice_pdf', 'Tax invoice',    'wb_issue_invoices',        true ],
		'credit'   => [ 'wb_credit_notes',    'credit_number',  'credit_pdf',  'Credit note',    'wb_issue_credit_notes',    true ],
		'dn'       => [ 'wb_delivery_notes',  'dn_number',      'dn_pdf',      'Delivery note',  'wb_issue_delivery_notes',  true ],
		'po'       => [ 'wb_purchase_orders', 'po_number',      'contract',    'Purchase order', 'wb_manage_purchasing',     false ],
	];

	/** Which ledger action means "issued" for each kind (the PDF is made after COMMIT). */
	const ISSUE_EVENTS = [ 'quote_sent' => 'quote', 'invoice_issued' => 'invoice', 'credit_note_approved' => 'credit', 'delivery_note_issued' => 'dn', 'po_sent' => 'po' ];

	public static function init(): void {
		add_action( 'wb_event', [ __CLASS__, 'on_event' ], 20, 5 );
		add_filter( 'wb_row_actions', [ __CLASS__, 'row_actions' ] );
		add_action( 'rest_api_init', function () {
			register_rest_route( 'wb/v1', '/pdf', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'serve' ], 'permission_callback' => fn() => is_user_logged_in() ] );
		} );
	}

	/* ------------------------------------------------------------------ the HTML (pure) */

	/** Money as the documents show it: R 12 345,00 is what South Africans read, so 12 345.00 with a thin space. */
	public static function money( $v ): string {
		return number_format( (float) $v, 2, '.', ' ' );
	}

	/** The style every document shares. Dompdf: tables, no flex, no CSS variables. */
	private static function css( array $c ): string {
		return 'body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:#1f2a3a;margin:0}'
			. '.head{width:100%;border-bottom:2px solid ' . $c['primary'] . ';padding-bottom:10px;margin-bottom:14px}'
			. '.head td{vertical-align:top}.brand{font-size:16pt;font-weight:bold;color:' . $c['ink'] . '}'
			. '.co{font-size:8.5pt;color:#47586D;line-height:1.35}.logo{max-height:52px;max-width:180px}'
			. 'h1{font-size:18pt;margin:0 0 2px;color:' . $c['ink'] . ';letter-spacing:-.02em}.num{font-size:11pt;font-weight:bold;color:' . $c['primary'] . '}'
			. '.meta{width:100%;margin:10px 0 14px}.meta td{vertical-align:top;font-size:9pt;padding:0 12px 0 0}.meta .k{color:#47586D;font-size:8pt;text-transform:uppercase;letter-spacing:.08em}'
			. 'table.lines{width:100%;border-collapse:collapse;margin:6px 0 10px}table.lines th{text-align:left;font-size:8pt;text-transform:uppercase;letter-spacing:.06em;color:#47586D;border-bottom:1px solid #E6DCCF;padding:5px 6px}'
			. 'table.lines td{padding:6px;border-bottom:1px solid #F0E8DF;vertical-align:top}.n{text-align:right;white-space:nowrap}'
			. 'table.tot{margin-left:auto;border-collapse:collapse;min-width:45%}table.tot td{padding:4px 6px}table.tot .grand td{font-weight:bold;font-size:11pt;border-top:2px solid ' . $c['primary'] . '}'
			. '.note{font-size:8.5pt;color:#47586D;margin-top:14px;line-height:1.4}.box{border:1px solid #E6DCCF;padding:8px 10px;margin-top:12px;font-size:8.5pt}'
			. '.foot{position:fixed;bottom:0;left:0;right:0;font-size:7.5pt;color:#8A8F9C;border-top:1px solid #E6DCCF;padding-top:6px}';
	}

	/**
	 * The whole document. Pure: everything comes in as arrays.
	 * $doc: [ kind, number, date, due?, status?, lines: [ [ description, qty, unit_price, line_total, code? ], … ],
	 *         subtotal, vat_rate, vat, total, words (one line under the title), extra (array of [ key, value ]) ]
	 * $party: the customer or supplier [ name, address, vat_number, reg_number, contact ]
	 * $brand: WB_Setup::brand() with colors already made safe; plus 'logo' (data URI or '').
	 */
	public static function html( array $doc, array $party, array $brand ): string {
		$k    = self::KINDS[ $doc['kind'] ] ?? self::KINDS['invoice'];
		$c    = $brand['colors'];
		$e    = fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$name = (string) ( $brand['legal_name'] ?: $brand['display_name'] );
		$co   = array_filter( [
			'' !== (string) $brand['reg_number'] ? 'Reg. ' . $brand['reg_number'] : '',
			'yes' === (string) ( $brand['vat_registered'] ?? 'yes' ) && '' !== (string) $brand['vat_number'] ? 'VAT ' . $brand['vat_number'] : '',
			(string) $brand['physical_address'],
		] );
		$h = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e( $k[3] . ' ' . $doc['number'] ) . '</title><style>' . self::css( $c ) . '</style></head><body>';
		$h .= '<table class="head"><tr><td>' . ( '' !== (string) ( $brand['logo'] ?? '' ) ? '<img class="logo" src="' . $e( $brand['logo'] ) . '" alt="">' : '<div class="brand">' . $e( $name ) . '</div>' )
			. '</td><td class="co" style="text-align:right">' . $e( $name ) . '<br>' . implode( '<br>', array_map( fn( $l ) => nl2br( $e( $l ) ), $co ) ) . '</td></tr></table>';
		$h .= '<h1>' . $e( $k[3] ) . '</h1><div class="num">' . $e( $doc['number'] ) . '</div>';
		if ( '' !== (string) ( $doc['words'] ?? '' ) ) $h .= '<p class="note" style="margin-top:4px">' . $e( $doc['words'] ) . '</p>';
		$h .= '<table class="meta"><tr><td style="width:50%"><div class="k">' . ( 'po' === $doc['kind'] ? 'Supplier' : 'Customer' ) . '</div><strong>' . $e( $party['name'] ?? '' ) . '</strong>'
			. ( '' !== (string) ( $party['address'] ?? '' ) ? '<br>' . nl2br( $e( $party['address'] ) ) : '' )
			. ( '' !== (string) ( $party['vat_number'] ?? '' ) ? '<br>VAT ' . $e( $party['vat_number'] ) : '' )
			. ( '' !== (string) ( $party['contact'] ?? '' ) ? '<br>Attention: ' . $e( $party['contact'] ) : '' ) . '</td><td>';
		$meta = array_merge( [ [ 'Date', (string) $doc['date'] ] ], ! empty( $doc['due'] ) ? [ [ 'Due', (string) $doc['due'] ] ] : [], (array) ( $doc['extra'] ?? [] ) );
		foreach ( $meta as [ $kk, $vv ] ) if ( '' !== (string) $vv ) $h .= '<div class="k">' . $e( $kk ) . '</div><div style="margin-bottom:5px">' . $e( $vv ) . '</div>';
		$h .= '</td></tr></table>';
		$money = ! in_array( $doc['kind'], [ 'dn' ], true );
		$h .= '<table class="lines"><thead><tr><th>Item</th>' . ( $money ? '<th class="n">Qty</th><th class="n">Each</th><th class="n">Amount</th>' : '<th class="n">Qty</th>' ) . '</tr></thead><tbody>';
		foreach ( (array) $doc['lines'] as $l ) {
			$h .= '<tr><td>' . ( '' !== (string) ( $l['code'] ?? '' ) ? '<strong>' . $e( $l['code'] ) . '</strong> · ' : '' ) . $e( $l['description'] ?? '' ) . '</td><td class="n">' . $e( rtrim( rtrim( number_format( (float) ( $l['qty'] ?? 0 ), 3, '.', '' ), '0' ), '.' ) ) . '</td>'
				. ( $money ? '<td class="n">' . $e( self::money( $l['unit_price'] ?? 0 ) ) . '</td><td class="n">' . $e( self::money( $l['line_total'] ?? 0 ) ) . '</td>' : '' ) . '</tr>';
		}
		$h .= '</tbody></table>';
		if ( $money ) {
			$h .= '<table class="tot"><tr><td>Subtotal</td><td class="n">' . $e( self::money( $doc['subtotal'] ?? 0 ) ) . '</td></tr>'
				. '<tr><td>VAT ' . $e( rtrim( rtrim( number_format( (float) ( $doc['vat_rate'] ?? 0 ), 2, '.', '' ), '0' ), '.' ) ) . '%</td><td class="n">' . $e( self::money( $doc['vat'] ?? 0 ) ) . '</td></tr>'
				. '<tr class="grand"><td>Total</td><td class="n">R ' . $e( self::money( $doc['total'] ?? 0 ) ) . '</td></tr></table>';
		}
		if ( 'invoice' === $doc['kind'] && '' !== (string) ( $brand['bank_details'] ?? '' ) ) $h .= '<div class="box"><strong>Pay to</strong><br>' . nl2br( $e( $brand['bank_details'] ) ) . '<br>Reference: ' . $e( $doc['number'] ) . '</div>';
		if ( 'quote' === $doc['kind'] ) $h .= '<p class="note">Prices exclude delivery unless shown. This quote is valid until the date above; after that, please ask for a new one.</p>';
		if ( 'dn' === $doc['kind'] ) $h .= '<div class="box">Received in good order by: ________________________________ &nbsp; Date: ______________ &nbsp; Signature: ____________________</div>';
		if ( 'po' === $doc['kind'] ) $h .= '<p class="note">Please quote this number on your invoice and delivery note.</p>';
		if ( '' !== (string) ( $brand['doc_footer'] ?? '' ) ) $h .= '<p class="note">' . nl2br( $e( $brand['doc_footer'] ) ) . '</p>';
		$h .= '<div class="foot">' . $e( $name ) . ' · ' . $e( $k[3] ) . ' ' . $e( $doc['number'] ) . ' · made ' . $e( $doc['made'] ?? $doc['date'] ) . '</div>';
		return $h . '</body></html>';
	}

	/* ------------------------------------------------------------------ the data for each kind */

	/** The source row → the arrays html() wants. Null when the row is missing or not issued yet. */
	public static function data( string $kind, int $id ): ?array {
		$k   = self::KINDS[ $kind ] ?? null;
		if ( ! $k ) return null;
		$row = WB_CCT::get( $k[0], $id );
		if ( ! $row || '' === (string) ( $row[ $k[1] ] ?? '' ) ) return null;
		$brand          = WB_Setup::brand();
		$brand['colors'] = WB_Setup::safe_colors( (array) $brand['colors'] );
		$brand['logo']   = WB_Setup::logo_data_uri();
		$party = [];
		$doc   = [ 'kind' => $kind, 'number' => (string) $row[ $k[1] ], 'date' => substr( (string) ( $row['issued_at'] ?? $row['sent_at'] ?? $row['cct_created'] ?? '' ), 0, 10 ), 'made' => current_time( 'Y-m-d' ), 'lines' => [], 'extra' => [] ];
		$cust  = fn( $cid ) => (array) ( WB_CCT::get( 'wb_customers', (int) $cid ) ?: [] );
		$pname = fn( $pid ) => (string) ( WB_CCT::get( 'wb_products', (int) $pid )['sku'] ?? '' );
		switch ( $kind ) {
			case 'quote':
				$c = $cust( $row['customer_id'] );
				foreach ( WB_CCT::find( 'wb_quote_lines', [ 'quote_id' => $id ], [ 'limit' => 1000, 'orderby' => '_ID', 'order' => 'ASC' ] ) as $l ) $doc['lines'][] = [ 'code' => $pname( $l['product_id'] ), 'description' => $l['description'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'] ];
				$doc += [ 'subtotal' => $row['subtotal'], 'vat' => $row['vat'], 'total' => $row['total'], 'vat_rate' => (float) get_option( 'wb_vat_rate', 15 ), 'due' => '' ];
				$doc['extra'][] = [ 'Valid until', (string) $row['valid_until'] ];
				$doc['words']   = (string) ( $row['notes_to_customer'] ?? '' );
				break;
			case 'invoice':
				$c = $cust( $row['customer_id'] );
				foreach ( WB_CCT::json( $row['lines_json'] ) as $l ) $doc['lines'][] = [ 'code' => $pname( $l['product_id'] ?? 0 ), 'description' => $l['description'] ?? '', 'qty' => $l['qty'] ?? ( $l['qty_ordered'] ?? 0 ), 'unit_price' => $l['unit_price'] ?? 0, 'line_total' => $l['line_total'] ?? 0 ];
				$doc += [ 'subtotal' => $row['subtotal'], 'vat' => $row['vat'], 'total' => $row['total'], 'vat_rate' => $row['vat_rate'], 'due' => substr( (string) $row['due_at'], 0, 10 ) ];
				$o = WB_CCT::get( 'wb_orders', (int) $row['order_id'] );
				if ( $o ) $doc['extra'][] = [ 'Order', (string) $o['order_number'] ];
				break;
			case 'credit':
				$c   = $cust( $row['customer_id'] );
				$inv = WB_CCT::get( 'wb_invoices', (int) $row['invoice_id'] );
				foreach ( WB_CCT::json( $row['lines_json'] ) as $l ) $doc['lines'][] = [ 'code' => $pname( $l['product_id'] ?? 0 ), 'description' => $l['description'] ?? ( '' !== (string) ( $l['reason'] ?? '' ) ? $l['reason'] : 'Credit' ), 'qty' => $l['qty'] ?? 1, 'unit_price' => $l['unit_price'] ?? ( $l['line_total'] ?? 0 ), 'line_total' => $l['line_total'] ?? ( (float) ( $l['qty'] ?? 1 ) * (float) ( $l['unit_price'] ?? 0 ) ) ];
				$doc += [ 'subtotal' => $row['subtotal'], 'vat' => $row['vat'], 'total' => $row['total'], 'vat_rate' => (float) ( $inv['vat_rate'] ?? get_option( 'wb_vat_rate', 15 ) ), 'due' => '' ];
				$doc['extra'][] = [ 'Against invoice', (string) ( $inv['invoice_number'] ?? '' ) ];
				$doc['words']   = 'Reason: ' . WB_Render::words( (string) $row['reason'] );
				$doc['date']    = substr( (string) ( $row['approved_at'] ?: $row['cct_created'] ), 0, 10 );
				break;
			case 'dn':
				$o = (array) ( WB_CCT::get( 'wb_orders', (int) $row['order_id'] ) ?: [] );
				$c = $cust( $o['customer_id'] ?? 0 );
				foreach ( WB_CCT::json( $row['lines_json'] ) as $l ) $doc['lines'][] = [ 'code' => $pname( $l['product_id'] ?? 0 ), 'description' => $l['description'] ?? '', 'qty' => $l['qty'] ?? 0 ];
				$doc['extra'][] = [ 'Order', (string) ( $o['order_number'] ?? '' ) ];
				$doc['extra'][] = [ 'Type', 'collection' === (string) $row['type'] ? 'Collection' : 'Delivery' . ( '' !== (string) $row['vehicle_or_courier'] ? ' · ' . $row['vehicle_or_courier'] : '' ) ];
				$c['address'] = (string) ( $c['delivery_address'] ?? '' ) ?: (string) ( $c['billing_address'] ?? '' );
				break;
			case 'po':
				$s = (array) ( WB_CCT::get( 'wb_suppliers', (int) $row['supplier_id'] ) ?: [] );
				$sub = 0.0;
				foreach ( WB_CCT::find( 'wb_po_lines', [ 'po_id' => $id ], [ 'limit' => 1000, 'orderby' => '_ID', 'order' => 'ASC' ] ) as $l ) { $lt = (float) $l['qty_ordered'] * (float) $l['unit_cost']; $sub += $lt; $doc['lines'][] = [ 'code' => $pname( $l['product_id'] ), 'description' => (string) ( WB_CCT::get( 'wb_products', (int) $l['product_id'] )['name'] ?? '' ), 'qty' => $l['qty_ordered'], 'unit_price' => $l['unit_cost'], 'line_total' => $lt ]; }
				$rate = (float) get_option( 'wb_vat_rate', 15 );
				$doc += [ 'subtotal' => $sub, 'vat_rate' => $rate, 'vat' => round( $sub * $rate / 100, 2 ), 'total' => round( $sub * ( 1 + $rate / 100 ), 2 ), 'due' => '' ];
				$doc['extra'][] = [ 'Expected', (string) $row['expected_at'] ];
				$doc['words']   = (string) ( $row['notes'] ?? '' );
				$party = [ 'name' => (string) ( $s['name'] ?? '' ), 'address' => (string) ( $s['address'] ?? '' ), 'vat_number' => '', 'contact' => (string) ( $s['contact_name'] ?? '' ) ];
				break;
		}
		if ( ! $party ) $party = [ 'name' => (string) ( $c['name'] ?? '' ), 'address' => (string) ( $c['address'] ?? ( $c['billing_address'] ?? '' ) ), 'vat_number' => (string) ( $c['vat_number'] ?? '' ), 'contact' => '' ];
		return [ 'doc' => $doc, 'party' => $party, 'brand' => $brand, 'row' => $row ];
	}

	/* ------------------------------------------------------------------ making and keeping */

	/** The document's PDF: the one on file, or made now. Returns the wb_documents id, 0 when it cannot be made. */
	public static function ensure( string $kind, int $id ): int {
		$k = self::KINDS[ $kind ] ?? null;
		if ( ! $k ) return 0;
		$row = WB_CCT::get( $k[0], $id );
		if ( ! $row ) return 0;
		if ( '' !== (string) ( $row['pdf_key'] ?? '' ) ) {
			$d = WB_CCT::first( 'wb_documents', [ 'storage_key' => (string) $row['pdf_key'] ], [ 'active_only' => false ] );
			if ( $d && WB_Storage::exists( (string) $row['pdf_key'] ) ) return (int) $d['_ID'];
		}
		$data = self::data( $kind, $id );
		if ( ! $data ) return 0;
		$bytes = WB_Pdf::render( self::html( $data['doc'], $data['party'], $data['brand'] ) );
		if ( '' === $bytes ) return 0;
		$key = WB_Storage::put_contents( $bytes, 'docs/' . $kind . '/' . gmdate( 'Y' ) . '/' . sanitize_file_name( $data['doc']['number'] ) . '.pdf' );
		if ( '' === $key ) return 0;
		$doc_id = WB_CCT::insert( 'wb_documents', [
			'type' => $k[2], 'title' => $k[3] . ' ' . $data['doc']['number'], 'customer_id' => (int) ( $row['customer_id'] ?? 0 ), 'order_id' => (int) ( $row['order_id'] ?? ( 'dn' === $kind ? $row['order_id'] : 0 ) ),
			'version' => 1, 'storage_key' => $key, 'mime' => 'application/pdf', 'size' => strlen( $bytes ), 'issued_at' => wb_now(), 'is_customer_visible' => $k[5] ? 1 : 0,
		], 'document_pdf_made' );
		if ( is_wp_error( $doc_id ) ) return 0;
		WB_CCT::update( $k[0], $id, [ 'pdf_key' => $key ], $kind . '_pdf_filed' );
		return (int) $doc_id;
	}

	/** After an issue event has committed: make the PDF. Never throws into the business write. */
	public static function on_event( string $action, string $type, int $id, $before = null, $after = null ): void {
		$kind = self::ISSUE_EVENTS[ $action ] ?? null;
		if ( ! $kind || ! WB_Pdf::available() ) return;
		try { self::ensure( $kind, $id ); } catch ( Throwable $e ) { error_log( 'WB_Docs: ' . $e->getMessage() ); }
	}

	/** The link a ⋯ menu shows: made on demand, then streamed inline. */
	public static function url( string $kind, int $id ): string {
		return add_query_arg( [ 'kind' => $kind, 'id' => $id, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'wb/v1/pdf' ) );
	}

	/** A "Download PDF" row action for each kind, shown once the document has its number. */
	public static function row_actions( array $a ): array {
		foreach ( self::KINDS as $kind => $k ) {
			$a[ 'pdf_' . $kind ] = [ 'cct' => $k[0], 'label' => 'Download PDF', 'icon' => 'download', 'allowed' => fn() => current_user_can( $k[4] ),
				'visible' => fn( $r ) => '' !== (string) ( $r[ $k[1] ] ?? '' ) && ( 'credit' !== $kind || 'approved' === (string) ( $r['status'] ?? '' ) ) && ( 'quote' !== $kind || 'draft' !== (string) ( $r['status'] ?? '' ) ),
				'href' => fn( $r ) => self::url( $kind, (int) $r['_ID'] ) ];
		}
		return $a;
	}

	/** GET wb/v1/pdf?kind=&id=: staff with the kind's cap; makes the PDF if it is not on file yet. */
	public static function serve( WP_REST_Request $req ) {
		$kind = sanitize_key( (string) $req->get_param( 'kind' ) );
		$k    = self::KINDS[ $kind ] ?? null;
		if ( ! $k || ! current_user_can( $k[4] ) ) WB_Rest::human_page( 'No access', 'You cannot open that kind of document.', 403 );
		if ( ! WB_Pdf::available() ) WB_Rest::human_page( 'No PDF engine', 'The PDF engine is missing from this installation. The document is still on file.', 500 );
		$doc_id = self::ensure( $kind, absint( $req->get_param( 'id' ) ) );
		if ( ! $doc_id ) WB_Rest::human_page( 'Not ready', 'That document has no number yet, or its PDF could not be made. Try again in a moment.', 404 );
		$doc = WB_CCT::get( 'wb_documents', $doc_id );
		wb_ledger_write( 'document_downloaded', 'wb_documents', $doc_id, null, [ 'by' => get_current_user_id(), 'kind' => $kind ] );
		WB_Storage::stream( (string) $doc['storage_key'], sanitize_file_name( (string) $doc['title'] ) . '.pdf', true );
		return null;
	}
}
