<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Floor — the warehouse floor (1.7.0, review of 9 October: "a picking list, partial delivery on
 * one screen, and a signature on a phone").
 *
 *  - Picking list: a printable PDF per released order: each product still to go, how many, where it
 *    was last put away, whether it needs a batch, and boxes to tick; "picked by" and "checked by".
 *  - Signature: "Sign for it" on a delivery or collection note opens a form with the name and a box
 *    to sign in with a finger or a mouse. The signature is a small PNG kept in the private folder;
 *    the note records the name, the time and the signature. Without the script, the name alone is
 *    recorded, as before.
 *
 * picking_html() and signature_bytes() are pure.
 */
class WB_Floor {

	const SIG_MAX = 200 * 1024;

	public static function init(): void {
		add_filter( 'wb_row_actions', [ __CLASS__, 'row_actions' ] );
		add_filter( 'wb_panel_handlers', function ( array $h ): array { $h['dn_sign'] = [ __CLASS__, 'handle_sign' ]; return $h; } );
		add_action( 'rest_api_init', function () {
			register_rest_route( 'wb/v1', '/picking', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'serve_picking' ], 'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'wb_issue_delivery_notes' ) ] );
			register_rest_route( 'wb/v1', '/signature', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'serve_signature' ], 'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'wb_issue_delivery_notes' ) ] );
		} );
	}

	/* ------------------------------------------------------------------ pure */

	/**
	 * A drawn signature from the form: the PNG bytes, or '' when it is missing, not a PNG, or too
	 * large. Only data:image/png;base64 is accepted. Pure.
	 */
	public static function signature_bytes( string $data_url ): string {
		if ( 0 !== strpos( $data_url, 'data:image/png;base64,' ) ) return '';
		$b64 = substr( $data_url, 22 );
		if ( strlen( $b64 ) > self::SIG_MAX * 4 / 3 + 4 || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $b64 ) ) return '';
		$bin = (string) base64_decode( $b64, true );
		return ( strlen( $bin ) > 100 && "\x89PNG\r\n\x1a\n" === substr( $bin, 0, 8 ) ) ? $bin : '';
	}

	/**
	 * The picking list. $o: [ order_number, customer, required_by, fulfilment ]; $lines: [ [ sku, name,
	 * qty, unit, location, batch ], … ]; $brand as WB_Docs::html(). Pure.
	 */
	public static function picking_html( array $o, array $lines, array $brand ): string {
		$e    = fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$name = (string) ( $brand['legal_name'] ?: $brand['display_name'] );
		$h = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e( 'Picking list ' . $o['order_number'] ) . '</title><style>' . WB_Docs::css( $brand['colors'] ) . '.tick{width:14px;height:14px;border:1.5px solid #47586D;display:inline-block}td.big{font-size:12pt;font-weight:bold}</style></head><body>';
		$h .= '<table class="head"><tr><td><div class="brand">' . $e( $name ) . '</div></td><td class="co" style="text-align:right">Picking list<br>' . $e( $o['made'] ?? '' ) . '</td></tr></table>';
		$h .= '<h1>Pick for ' . $e( $o['order_number'] ) . '</h1><div class="num">' . $e( $o['customer'] ) . '</div>';
		$h .= '<table class="meta"><tr><td><div class="k">How it leaves</div>' . $e( 'delivery' === ( $o['fulfilment'] ?? '' ) ? 'We deliver' : 'Collected by the customer' ) . '</td><td><div class="k">Needed by</div>' . $e( '' !== (string) ( $o['required_by'] ?? '' ) ? $o['required_by'] : 'As soon as it is ready' ) . '</td></tr></table>';
		$h .= '<table class="lines"><thead><tr><th style="width:24px"></th><th>Product</th><th class="n">Pick</th><th>From</th><th>Batch</th></tr></thead><tbody>';
		foreach ( $lines as $l ) {
			$h .= '<tr><td><span class="tick"></span></td><td><strong>' . $e( $l['sku'] ) . '</strong><br>' . $e( $l['name'] ) . '</td><td class="n big">' . $e( $l['qty'] ) . ' <small>' . $e( $l['unit'] ) . '</small></td><td>' . $e( '' !== (string) $l['location'] ? $l['location'] : '—' ) . '</td><td>' . ( ! empty( $l['batch'] ) ? 'Write the batch: ________' : '' ) . '</td></tr>';
		}
		if ( ! $lines ) $h .= '<tr><td colspan="5">Nothing is left to pick on this order.</td></tr>';
		$h .= '</tbody></table><div class="box">Picked by: ______________________ &nbsp; Checked by: ______________________ &nbsp; Date: ____________</div>';
		$h .= '<div class="foot">' . $e( $name ) . ' · Picking list ' . $e( $o['order_number'] ) . '</div>';
		return $h . '</body></html>';
	}

	/* ------------------------------------------------------------------ the picking list */

	public static function picking_url( int $order_id ): string {
		return add_query_arg( [ 'order' => $order_id, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'wb/v1/picking' ) );
	}

	public static function serve_picking( WP_REST_Request $req ) {
		$o = WB_CCT::get( 'wb_orders', absint( $req->get_param( 'order' ) ) );
		if ( ! $o ) WB_Rest::human_page( 'Not found', 'That order could not be found.', 404 );
		$lines = [];
		foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => (int) $o['_ID'] ], [ 'limit' => 1000, 'order' => 'ASC' ] ) as $l ) {
			$left = (float) $l['qty_ordered'] - (float) $l['qty_delivered'];
			if ( $left <= 0.0001 ) continue;
			$p   = WB_CCT::get( 'wb_products', (int) $l['product_id'] );
			$loc = WB_CCT::first( 'wb_stock_movements', [ 'product_id' => (int) $l['product_id'], 'location !=' => '' ] );
			$lines[] = [ 'sku' => (string) ( $p['sku'] ?? '' ), 'name' => (string) ( $p['name'] ?? $l['description'] ), 'qty' => WB_Render::num( $left ), 'unit' => (string) ( $p['unit'] ?? '' ), 'location' => (string) ( $loc['location'] ?? '' ), 'batch' => 'yes' === (string) ( $p['batch_tracked'] ?? '' ) ];
		}
		$cust  = WB_CCT::get( 'wb_customers', (int) $o['customer_id'] );
		$brand = WB_Setup::brand();
		$brand['colors'] = WB_Setup::safe_colors( (array) $brand['colors'] );
		$bytes = WB_Pdf::render( self::picking_html( [ 'order_number' => $o['order_number'], 'customer' => (string) ( $cust['name'] ?? '' ), 'required_by' => (string) $o['required_by'], 'fulfilment' => (string) $o['fulfilment'], 'made' => wb_today() ], $lines, $brand ) );
		if ( '' === $bytes ) WB_Rest::human_page( 'Not ready', 'The picking list could not be made. Try again in a moment.', 500 );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'Picking ' . $o['order_number'] ) . '.pdf"' );
		echo $bytes;
		exit;
	}

	/* ------------------------------------------------------------------ signing */

	public static function row_actions( array $a ): array {
		$a['order_pick'] = [ 'cct' => 'wb_orders', 'label' => 'Picking list', 'icon' => 'print', 'allowed' => fn() => current_user_can( 'wb_issue_delivery_notes' ),
			'visible' => fn( $r ) => in_array( (string) $r['status'], [ 'ready', 'part_delivered' ], true ), 'href' => fn( $r ) => self::picking_url( (int) $r['_ID'] ) ];
		$a['dn_sign'] = [ 'cct' => 'wb_delivery_notes', 'label' => 'Sign for it', 'icon' => 'approve', 'allowed' => fn() => current_user_can( 'wb_issue_delivery_notes' ),
			'visible' => fn( $r ) => 'issued' === (string) $r['status'], 'href' => fn( $r ) => WB_Workspace::url( 'deliveries', [ 'sign' => (int) $r['_ID'] ] ) . '#wb-sign' ];
		$a['dn_signature'] = [ 'cct' => 'wb_delivery_notes', 'label' => 'See the signature', 'icon' => 'open', 'allowed' => fn() => current_user_can( 'wb_issue_delivery_notes' ),
			'visible' => fn( $r ) => '' !== (string) ( $r['signature_key'] ?? '' ), 'href' => fn( $r ) => add_query_arg( [ 'dn' => (int) $r['_ID'], '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'wb/v1/signature' ) ) ];
		return $a;
	}

	/** The signing form, when Deliveries is opened with ?sign=ID. */
	public static function sign_panel(): string {
		$id = absint( $_GET['sign'] ?? 0 );
		if ( ! $id || ! current_user_can( 'wb_issue_delivery_notes' ) ) return '';
		$dn = WB_CCT::get( 'wb_delivery_notes', $id );
		if ( ! $dn || 'issued' !== (string) $dn['status'] ) return WB_Render::fold( 'Sign for it', wb_notice( 'warn', 'That note is not waiting for a signature.' ), [ 'open' => true, 'id' => 'wb-sign' ] );
		$o = WB_CCT::get( 'wb_orders', (int) $dn['order_id'] );
		$lines = json_decode( (string) $dn['lines_json'], true );
		$list = '';
		foreach ( (array) $lines as $l ) $list .= '<li>' . esc_html( WB_Render::num( $l['qty'] ?? 0 ) . ' × ' . ( $l['description'] ?? '' ) ) . '</li>';
		$f = WB_Render::form_open( 'dn_sign' ) . '<input type="hidden" name="dn_id" value="' . $id . '">'
			. ( '' !== $list ? '<ul class="wb-sign-lines">' . $list . '</ul>' : '' )
			. WB_Render::field( 'name', 'Name of the person taking the goods', 'text', '', [ 'required' => true, 'id' => 'wb-sign-name' ] )
			. '<div class="wb-sign" data-wb-sign><p class="wb-sign-label" id="wb-sign-label">Sign here</p><canvas width="600" height="200" aria-labelledby="wb-sign-label" role="img"></canvas><input type="hidden" name="signature" value=""><button type="button" class="wb-btn wb-btn-sm wb-btn-ghost" data-wb-sign-clear>Clear</button><small>Sign with a finger or the mouse. Without it, the name alone is recorded.</small></div>';
		return WB_Render::fold( 'Sign for ' . $dn['dn_number'] . ( $o ? ' (order ' . $o['order_number'] . ')' : '' ), $f . WB_Render::form_close( 'Save the signature' ) . '<p><a href="' . esc_url( remove_query_arg( 'sign' ) ) . '">Close</a></p>', [ 'open' => true, 'id' => 'wb-sign', 'kind' => 'lead' ] );
	}

	public static function handle_sign() {
		$id   = absint( $_POST['dn_id'] ?? 0 );
		$name = sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) );
		$key  = '';
		$sig  = (string) wp_unslash( $_POST['signature'] ?? '' );
		if ( '' !== $sig ) {
			$png = self::signature_bytes( $sig );
			if ( '' === $png ) return new WP_Error( 'wb_signature', 'The signature could not be read. Clear it and sign again.' );
			$key = WB_Storage::put_contents( $png, 'signatures/' . gmdate( 'Y/m' ) . '/dn-' . $id . '.png' );
			if ( '' === $key ) return new WP_Error( 'wb_store_failed', 'The signature could not be stored.' );
		}
		$r = WB_Orders::confirm_collection( $id, $name, $key );
		if ( is_wp_error( $r ) ) return $r;
		$_POST['_wb_return'] = remove_query_arg( 'sign', (string) ( $_POST['_wb_return'] ?? '' ) );
		return [ 'msg' => '' !== $key ? 'Signed by ' . $name . '.' : 'Recorded: taken by ' . $name . '.' ];
	}

	public static function serve_signature( WP_REST_Request $req ) {
		$dn = WB_CCT::get( 'wb_delivery_notes', absint( $req->get_param( 'dn' ) ) );
		if ( ! $dn || '' === (string) $dn['signature_key'] || ! WB_Storage::exists( (string) $dn['signature_key'] ) ) WB_Rest::human_page( 'Not found', 'There is no signature on that note.', 404 );
		WB_Storage::stream( (string) $dn['signature_key'], 'signature-' . $dn['dn_number'] . '.png', true );
		return null;
	}
}
