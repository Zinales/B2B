<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Rest — namespace wb/v1.
 *
 *   GET  /health                 { ok: true } — no version (don't hand a scanner the plugin version)
 *   GET  /download?token=…       a short-lived tokened file hand-out (single record)
 *   GET  /download?doc=…         logged-in click: access check → ledger → 302 to ?token= (2 minutes, single use)
 *   POST /bank-import            CSV upload (file, bank); logged in, wb_import_bank, nonce in X-WP-Nonce
 *   GET  /quote-accept?token=…   the customer's confirmation page (does NOT use the token —
 *                                link scanners and mail previews open links)
 *   POST /quote-accept           token + typed name → the order (the token is used up here)
 *   GET  /private?kind=&id=      0.2.0 logged-in click (payslip | invoice | statement): access check →
 *                                ledger → 302 to ?token= (2 minutes, single use, same login only)
 *   GET  /payroll-bank-file?run= 0.2.0 the net-pay CSV of a finalised run (wb_run_payroll)
 *
 * Browser-facing routes never return JSON errors: a person clicked a link, so they get a small
 * page with words and a way back (human_page). Machine routes keep REST semantics.
 */
class WB_Rest {

	const NS = 'wb/v1';

	/** Browser-facing routes whose links carry a _wpnonce (S10: an expired one gets a page, not JSON). */
	const LINK_ROUTES = [ '/download', '/private', '/payroll-bank-file' ];

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_filter( 'rest_authentication_errors', [ __CLASS__, 'expired_link' ], 101 );   // after core's cookie check (100)
	}

	/**
	 * A person clicked a link whose _wpnonce has expired (an old tab, a bookmarked link). Core
	 * answers rest_cookie_invalid_nonce as raw JSON before our callback runs; on the link routes
	 * that becomes the same "link expired" page as an expired token. Nothing else is touched.
	 */
	public static function expired_link( $result ) {
		if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) return $result;
		if ( ! self::is_link_route( self::current_route() ) ) return $result;
		self::human_page( 'Link expired', 'This link has expired. Go back, refresh the page and open the document again.', 403 );
		return $result;   // human_page() exits
	}

	/** Pure: is $route (e.g. "/wb/v1/download") one of the browser-facing link routes? */
	public static function is_link_route( string $route ): bool {
		$route = '/' . trim( $route, '/' );
		foreach ( self::LINK_ROUTES as $r ) {
			if ( '/' . self::NS . $r === $route ) return true;
		}
		return false;
	}

	/** The REST route being served (pretty permalinks or ?rest_route=). */
	private static function current_route(): string {
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return (string) $GLOBALS['wp']->query_vars['rest_route'];
		}
		if ( isset( $_GET['rest_route'] ) ) return (string) wp_unslash( $_GET['rest_route'] );
		$path   = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$at     = strpos( $path, $prefix );
		return false === $at ? '' : '/' . substr( $path, $at + strlen( $prefix ) );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/health', [
			'methods' => 'GET', 'permission_callback' => '__return_true',
			'callback' => fn() => [ 'ok' => true ],
		] );
		register_rest_route( self::NS, '/download', [
			'methods' => 'GET', 'permission_callback' => '__return_true',   // the token (or the logged-in access check) is the gate, inside
			'callback' => [ __CLASS__, 'download' ],
		] );
		register_rest_route( self::NS, '/bank-import', [
			'methods' => 'POST', 'callback' => [ __CLASS__, 'bank_import' ],
			'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'wb_import_bank' ),
		] );
		register_rest_route( self::NS, '/private', [
			'methods' => 'GET', 'permission_callback' => '__return_true',   // the access check is inside, per kind
			'callback' => [ __CLASS__, 'private_doc' ],
		] );
		register_rest_route( self::NS, '/payroll-bank-file', [
			'methods' => 'GET', 'callback' => [ __CLASS__, 'payroll_bank_file' ],
			'permission_callback' => fn() => is_user_logged_in() && current_user_can( 'wb_run_payroll' ),
		] );
		register_rest_route( self::NS, '/quote-accept', [
			[ 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => [ __CLASS__, 'quote_page' ] ],
			[ 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => [ __CLASS__, 'quote_accept' ] ],
		] );
	}

	/** A small self-contained page, then exit. */
	public static function human_page( string $title, string $msg, int $status = 200, string $extra_html = '' ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		$company = class_exists( 'WB_Setup' ) ? WB_Setup::display_name() : ( (string) ( ( (array) get_option( 'wb_company', [] ) )['name'] ?? '' ) ?: get_bloginfo( 'name' ) );
		$brand   = class_exists( 'WB_Setup' ) ? WB_Setup::style_tag() : '';
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>'
			. $brand . '<style>body{font-family:var(--wb-font,system-ui,sans-serif);background:var(--wb-canvas,#f7f6f2);color:var(--wb-ink,#1d2433);display:flex;min-height:90vh;align-items:center;justify-content:center;margin:0;padding:16px}'
			. '.box{max-width:520px;width:100%;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 14px rgba(29,36,51,.08)}h1{font-size:19px;margin:0 0 10px}'
			. 'p,td{font-size:15px;line-height:1.55}table{width:100%;border-collapse:collapse;margin:0 0 16px}td{padding:4px 0;border-bottom:1px solid #eee}td:last-child{text-align:right}'
			. 'input[type=text]{width:100%;font-size:16px;padding:10px;border:1px solid #c9ccd3;border-radius:6px;box-sizing:border-box;margin:6px 0 14px}'
			. 'button,a.btn{display:inline-block;font-size:15px;padding:10px 18px;border-radius:6px;background:var(--wb-primary,#1d2433);color:var(--wb-on-primary,#fff);border:0;text-decoration:none;cursor:pointer}small{color:#5b6372}</style></head>'
			. '<body><div class="box"><small>' . esc_html( $company ) . '</small><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $msg ) . '</p>' . $extra_html . '</div></body></html>';
		exit;
	}

	/** 30 hand-outs per 10 minutes per user (or per IP when not logged in). */
	private static function rate_ok( string $bucket, int $max = 30 ): bool {
		$who = get_current_user_id() ?: md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key = 'wb_rl_' . $bucket . '_' . $who;
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) return false;
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	public static function download( WP_REST_Request $req ) {
		if ( ! self::rate_ok( 'dl' ) ) self::human_page( 'One moment', 'You have opened a lot of documents in the last few minutes. Wait a little and try again.', 429 );

		$doc_id = absint( $req->get_param( 'doc' ) );
		if ( $doc_id ) {   // first hop: a logged-in click
			if ( ! is_user_logged_in() ) self::human_page( 'Please sign in', 'Sign in first, then open the document again.', 401 );
			$doc = WB_CCT::get( 'wb_documents', $doc_id );
			if ( ! $doc || 'void' === (string) $doc['record_status'] ) self::human_page( 'Document not found', 'That document could not be found.', 404 );
			if ( ! WB_Documents::user_can_access( $doc, get_current_user_id() ) ) {
				wb_ledger_write( 'download_denied', 'wb_documents', $doc_id, null, [ 'user' => get_current_user_id() ] );
				self::human_page( 'No access', 'You do not have access to this document. Ask the owner if you believe you should.', 403 );
			}
			$token = WB_Storage::issue_token( $doc_id );
			return new WP_REST_Response( null, 302, [ 'Location' => add_query_arg( 'token', $token, rest_url( self::NS . '/download' ) ) ] );
		}

		$data = WB_Storage::consume_token( (string) $req->get_param( 'token' ) );
		if ( ! $data ) self::human_page( 'Link expired', 'This download link has expired or has already been used. Ask for a new one.', 410 );
		$doc = WB_CCT::get( 'wb_documents', (int) $data['doc'] );
		if ( ! $doc || 'void' === (string) $doc['record_status'] ) self::human_page( 'Document not found', 'That document is no longer available.', 404 );
		if ( ! WB_Storage::exists( (string) $doc['storage_key'] ) ) self::human_page( 'File missing', 'The file for this document could not be found. Please let us know.', 404 );
		wb_ledger_write( 'document_downloaded', 'wb_documents', (int) $doc['_ID'], null, [ 'token_by' => (int) $data['by'], 'version' => (int) $doc['version'] ] );
		$ext = pathinfo( (string) $doc['storage_key'], PATHINFO_EXTENSION );
		WB_Storage::stream( (string) $doc['storage_key'], sanitize_file_name( (string) $doc['title'] ) . ( $ext ? '.' . $ext : '' ), true );
		return null;   // stream() exits
	}

	/**
	 * Payslips, invoices and statements: first hop (logged in, nonce) checks access for this kind
	 * and record, ledgers, and hands a 2-minute single-use token bound to this login; the second hop
	 * uses the token (same login only) and sends the page as a download.
	 */
	public static function private_doc( WP_REST_Request $req ) {
		if ( ! self::rate_ok( 'pd' ) ) self::human_page( 'One moment', 'You have opened a lot of documents in the last few minutes. Wait a little and try again.', 429 );
		if ( ! is_user_logged_in() ) self::human_page( 'Please sign in', 'Sign in first, then open the document again.', 401 );
		$uid   = get_current_user_id();
		$token = (string) $req->get_param( 'token' );
		if ( '' === $token ) {
			$kind = sanitize_key( (string) $req->get_param( 'kind' ) );
			$id   = absint( $req->get_param( 'id' ) );
			if ( ! self::private_allowed( $kind, $id, $uid ) ) {
				wb_ledger_write( 'private_doc_denied', 'wb_' . $kind, $id, null, [ 'user' => $uid ] );
				self::human_page( 'No access', 'You do not have access to this document.', 403 );
			}
			$t = strtolower( wp_generate_password( 40, false, false ) );
			set_transient( 'wb_pd_' . $t, [ 'kind' => $kind, 'id' => $id, 'user' => $uid ], 120 );
			return new WP_REST_Response( null, 302, [ 'Location' => add_query_arg( [ 'token' => $t, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( self::NS . '/private' ) ) ] );
		}
		$t = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) );
		$d = strlen( $t ) >= 32 ? get_transient( 'wb_pd_' . $t ) : false;
		if ( strlen( $t ) >= 32 ) delete_transient( 'wb_pd_' . $t );   // single use
		if ( ! is_array( $d ) || (int) $d['user'] !== $uid || ! self::private_allowed( (string) $d['kind'], (int) $d['id'], $uid ) ) self::human_page( 'Link expired', 'This link has expired or has already been used. Open the document again.', 410 );
		$kind = (string) $d['kind'];
		$id   = (int) $d['id'];
		wb_ledger_write( 'private_doc_opened', 'wb_' . $kind, $id, null, [ 'user' => $uid ] );
		if ( 'payslip' === $kind ) {
			$ps = WB_CCT::get( 'wb_payslips', $id );
			if ( '' !== (string) $ps['pdf_key'] && WB_Storage::exists( (string) $ps['pdf_key'] ) ) WB_Storage::stream( (string) $ps['pdf_key'], 'payslip-' . $ps['period'] . '.' . pathinfo( (string) $ps['pdf_key'], PATHINFO_EXTENSION ), true );
			$run = (array) WB_CCT::get( 'wb_pay_runs', (int) $ps['run_id'] );   // a draft: shown, not stored
			self::send_html( WB_Payroll::payslip_html( $ps, $run, (array) WB_CCT::get( 'wb_staff', (int) $ps['staff_id'] ), (array) WB_CCT::first( 'wb_payroll_profiles', [ 'staff_id' => (int) $ps['staff_id'] ] ), WB_Setup::brand() ), 'payslip-draft-' . $ps['period'] . '.html' );
		}
		if ( 'invoice' === $kind ) {
			$inv = WB_CCT::get( 'wb_invoices', $id );
			// 1.1.0: the PDF when there is one (made now if the engine is here); the HTML page otherwise
			if ( class_exists( 'WB_Docs' ) && WB_Pdf::available() && WB_Docs::ensure( 'invoice', $id ) ) {
				$inv = WB_CCT::get( 'wb_invoices', $id );
				if ( '' !== (string) $inv['pdf_key'] && WB_Storage::exists( (string) $inv['pdf_key'] ) ) WB_Storage::stream( (string) $inv['pdf_key'], sanitize_file_name( (string) $inv['invoice_number'] ) . '.pdf', true );
			}
			self::send_html( WB_Portal::invoice_html( $inv ), sanitize_file_name( (string) $inv['invoice_number'] ) . '.html' );
		}
		self::send_html( WB_Portal::statement_html( $id ), 'statement-' . wb_today() . '.html' );
		return null;
	}

	/** Who may open which private page. Fail closed: an unknown kind is never allowed. */
	private static function private_allowed( string $kind, int $id, int $uid ): bool {
		if ( $id <= 0 || $uid <= 0 ) return false;
		switch ( $kind ) {
			case 'payslip':
				$ps = WB_CCT::get( 'wb_payslips', $id );
				return $ps && WB_Payroll::user_can_view_payslip( $ps, $uid );
			case 'invoice':
				$inv = WB_CCT::get( 'wb_invoices', $id );
				return $inv && 'void' !== (string) $inv['status'] && WB_Portal::may_see_customer_doc( (int) $inv['customer_id'], $uid );
			case 'statement':
				return WB_Portal::may_see_customer_doc( $id, $uid );
		}
		return false;
	}

	private static function send_html( string $html, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $html;   // built from escaped parts
		exit;
	}

	/** The net-pay CSV for the bank (finalised runs only). */
	public static function payroll_bank_file( WP_REST_Request $req ) {
		$csv = WB_Payroll::bank_file( absint( $req->get_param( 'run' ) ) );
		if ( is_wp_error( $csv ) ) self::human_page( 'No file', $csv->get_error_message(), 409 );
		$run = WB_CCT::get( 'wb_pay_runs', absint( $req->get_param( 'run' ) ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="net-pay-' . sanitize_file_name( (string) $run['period'] ) . '.csv"' );
		header( 'X-Robots-Tag: noindex' );
		echo $csv;
		exit;
	}

	/** Machine route: JSON in, JSON out. */
	public static function bank_import( WP_REST_Request $req ) {
		// Cookie-authenticated REST already demands the nonce; checked here again so this route fails closed on its own.
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) return new WP_Error( 'wb_nonce', 'Missing or stale security token.', [ 'status' => 403 ] );
		$files = $req->get_file_params();
		$f     = $files['file'] ?? null;
		if ( ! $f || empty( $f['tmp_name'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) return new WP_Error( 'wb_no_file', 'Send the statement as "file".', [ 'status' => 400 ] );
		if ( (int) $f['size'] > 5 * MB_IN_BYTES ) return new WP_Error( 'wb_big', 'The file is too big.', [ 'status' => 413 ] );
		if ( ! preg_match( '/\.(csv|txt|tsv)$/i', sanitize_file_name( (string) ( $f['name'] ?? '' ) ) ) ) return new WP_Error( 'wb_type', 'Send the statement as a CSV, TXT or TSV file.', [ 'status' => 415 ] );
		$res = WB_Payments::import( (string) file_get_contents( $f['tmp_name'] ), sanitize_key( (string) $req->get_param( 'bank' ) ) ?: 'generic', (string) $f['name'] );
		if ( is_wp_error( $res ) ) {
			$res->add_data( [ 'status' => 422 ] );
			return $res;
		}
		return rest_ensure_response( $res );
	}

	private static function quote_summary( array $q ): string {
		$rows = '';
		foreach ( WB_CCT::find( 'wb_quote_lines', [ 'quote_id' => (int) $q['_ID'] ], [ 'order' => 'ASC' ] ) as $l ) {
			$rows .= '<tr><td>' . esc_html( $l['qty'] . ' × ' . $l['description'] ) . '</td><td>' . esc_html( WB_Render::money( $l['line_total'] ) ) . '</td></tr>';
		}
		return '<table>' . $rows . '<tr><td>VAT</td><td>' . esc_html( WB_Render::money( $q['vat'] ) ) . '</td></tr><tr><td><strong>Total</strong></td><td><strong>' . esc_html( WB_Render::money( $q['total'] ) ) . '</strong></td></tr></table>';
	}

	/** GET: show what is being accepted. The token is only looked at, never used here. */
	public static function quote_page( WP_REST_Request $req ) {
		$token = (string) $req->get_param( 'token' );
		$d     = WB_Orders::peek_token( $token );
		if ( ! $d ) self::human_page( 'Link expired', 'This acceptance link has expired or has already been used. Ask us for a new one.', 410 );
		$q = WB_CCT::get( 'wb_quotes', (int) $d['quote'] );
		if ( ! $q || 'sent' !== $q['status'] ) self::human_page( 'Quote closed', 'This quote can no longer be accepted. Ask us for a new one.', 410 );
		$form = self::quote_summary( $q ) . '<form method="post" action="' . esc_url( rest_url( self::NS . '/quote-accept' ) ) . '">'
			. '<input type="hidden" name="token" value="' . esc_attr( $token ) . '"><label>Your name<input type="text" name="name" required autocomplete="name"></label>'
			. '<button type="submit">Accept quote ' . esc_html( (string) $q['quote_number'] ) . '</button></form><p><small>Valid until ' . esc_html( (string) $q['valid_until'] ) . '. Accepting places the order at these prices.</small></p>';
		self::human_page( 'Accept quote ' . $q['quote_number'], 'Please check the quote below. Type your name and press Accept to place the order.', 200, $form );
		return null;
	}

	/** POST: accept. The token is used up whatever happens next. */
	public static function quote_accept( WP_REST_Request $req ) {
		if ( ! self::rate_ok( 'qa', 10 ) ) self::human_page( 'One moment', 'Too many attempts. Wait a few minutes and try again.', 429 );
		$res = WB_Orders::accept_by_token( (string) $req->get_param( 'token' ), (string) $req->get_param( 'name' ) );
		if ( is_wp_error( $res ) ) self::human_page( 'Not accepted', $res->get_error_message(), 409 );
		$o = WB_CCT::get( 'wb_orders', (int) $res );
		self::human_page( 'Thank you — order placed', sprintf( 'Your order number is %s. We will be in touch about payment and collection or delivery.', (string) ( $o['order_number'] ?? '' ) ) );
		return null;
	}
}
