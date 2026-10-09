<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Screens — the dashboard shortcodes and their panel forms.
 *
 * Every panel posts to itself with a nonce (wb_panel_<action>), the handler runs on
 * template_redirect, calls ONE engine function (which does the capability and business checks),
 * flashes the result and redirects back where the person was (PRG). Screens are deliberately thin:
 * the rules live in the engines, so a screen can never be the only thing standing between a
 * person and a mistake.
 *
 * Shortcodes: wb_home, wb_customers, wb_products, wb_quotes, wb_orders, wb_invoices, wb_payments,
 * wb_deliveries, wb_stock, wb_purchasing, wb_documents, wb_marketing, wb_cashflow, wb_staff,
 * wb_settings, wb_portal (plus wb_integrity, wb_notifications, wb_notify_bar, wb_list).
 * 0.2.0: other classes add panel handlers through the wb_panel_handlers filter (WB_Setup,
 * WB_Portal, WB_Payroll); the bank import is three steps (upload, header row, mapping).
 */
class WB_Screens {

	public static function init(): void {
		foreach ( [ 'home', 'customers', 'products', 'quotes', 'orders', 'invoices', 'payments', 'deliveries', 'stock', 'purchasing', 'documents', 'marketing', 'cashflow', 'staff', 'settings', 'portal' ] as $s ) {
			add_shortcode( 'wb_' . $s, [ __CLASS__, $s ] );
		}
		add_action( 'template_redirect', [ __CLASS__, 'maybe_handle' ], 5 );
		add_filter( 'wb_row_actions', [ __CLASS__, 'extra_actions' ] );
	}

	/** Price-rule approval: the creator never approves their own rule. */
	public static function extra_actions( array $a ): array {
		$a['customer_open'] = [ 'cct' => 'wb_customers', 'label' => 'Open', 'icon' => 'open', 'allowed' => fn() => true, 'visible' => fn( $r ) => true, 'href' => fn( $r ) => WB_Workspace::url( 'customers', [ 'customer' => (int) $r['_ID'] ] ) ];
		$a['product_open']  = [ 'cct' => 'wb_products', 'label' => 'Open', 'icon' => 'open', 'allowed' => fn() => true, 'visible' => fn( $r ) => true, 'href' => fn( $r ) => WB_Workspace::url( 'products', [ 'product' => (int) $r['_ID'] ] ) ];
		$a['product_docs'] = [ 'cct' => 'wb_products', 'label' => 'Datasheets and documents', 'icon' => 'open', 'allowed' => fn() => current_user_can( 'wb_view_documents' ), 'visible' => fn( $r ) => true, 'href' => fn( $r ) => WB_Workspace::url( 'documents', [ 'product' => (int) $r['_ID'] ] ) ];
		$a['rule_approve'] = [ 'cct' => 'wb_price_rules', 'label' => 'Approve rule', 'icon' => 'approve',
			'allowed' => fn() => current_user_can( 'wb_approve_pricing' ),
			'visible' => fn( $r ) => 'draft' === ( $r['status'] ?? '' ) && (int) ( $r['cct_author_id'] ?? 0 ) !== get_current_user_id(),
			'handle'  => function ( $id ) {
				$r = WB_CCT::get( 'wb_price_rules', $id );
				if ( ! $r || 'draft' !== $r['status'] ) return new WP_Error( 'wb_not_draft', 'That rule is not waiting for approval.' );
				if ( (int) ( $r['cct_author_id'] ?? 0 ) === get_current_user_id() ) return new WP_Error( 'wb_self_approval', 'Someone else must approve a rule you made.' );
				return WB_CCT::update( 'wb_price_rules', $id, [ 'status' => 'approved', 'approved_by_staff_id' => WB_Staff::current_staff_id(), 'approved_at' => wb_now() ], 'price_rule_approved' );
			} ];
		return $a;
	}

	/* ================================================================== helpers */

	private static function gate( string $cap ): string {
		if ( ! is_user_logged_in() ) return wb_notice( 'warn', 'Please sign in.' );
		return current_user_can( $cap ) ? '' : wb_notice( 'warn', 'This page is not part of your work. Ask the owner if you need it.' );
	}

	/** A fold on a screen; $kind: '' (the first plain fold becomes the lead), 'sibling' (also here), 'reference' (read-only proof). */
	private static function fold( string $title, string $body, bool $open = false, string $id = '', string $kind = '', string $hint = '' ): string {
		return WB_Render::fold( $title, $body, [ 'open' => $open, 'id' => $id, 'kind' => $kind, 'hint' => $hint ] );
	}

	/** A customer's name as a link to their page (1.5.0). */
	public static function customer_link( int $id, string $name = '' ): string {
		if ( $id <= 0 ) return '';
		$name = '' !== $name ? $name : self::customer_name( $id );
		return current_user_can( 'wb_view_customers' ) ? '<a href="' . esc_url( WB_Workspace::url( 'customers', [ 'customer' => $id ] ) ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
	}

	/** The row of $slug being edited on this request (?cct=&edit=), else null. */
	private static function editing( string $slug ): ?array {
		if ( empty( $_GET['edit'] ) || sanitize_key( (string) ( $_GET['cct'] ?? '' ) ) !== $slug ) return null;
		return WB_CCT::get( $slug, absint( $_GET['edit'] ) ) ?: null;
	}

	private static function p( string $k, $default = '' ) {
		return isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : $default;
	}

	/** "SKU or id, qty[, price]" per line → [ [product_id, qty, manual_price?], … ] or WP_Error. */
	public static function parse_lines( string $text, bool $with_price = true ) {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $n => $line ) {
			if ( '' === trim( $line ) ) continue;
			$c   = array_map( 'trim', str_getcsv( $line, ',', '"', '' ) );
			$ref = (string) ( $c[0] ?? '' );
			$p   = ctype_digit( $ref ) ? WB_CCT::get( 'wb_products', (int) $ref ) : WB_CCT::first( 'wb_products', [ 'sku' => $ref ] );
			if ( ! $p ) return new WP_Error( 'wb_bad_line', sprintf( 'Line %d: no product with code "%s".', $n + 1, $ref ) );
			$row = [ 'product_id' => (int) $p['_ID'], 'qty' => (float) ( $c[1] ?? 0 ) ];
			if ( $with_price && isset( $c[2] ) && '' !== $c[2] ) $row['manual_price'] = (float) $c[2];
			$out[] = $row;
		}
		return $out ?: new WP_Error( 'wb_no_lines', 'Add at least one line.' );
	}

	private static function customer_name( $id ): string {
		$c = WB_CCT::get( 'wb_customers', (int) $id );
		return $c ? (string) $c['name'] : ( (int) $id ? '#' . (int) $id : '' );
	}

	private static function product_name( $id ): string {
		$p = WB_CCT::get( 'wb_products', (int) $id );
		return $p ? $p['sku'] . ' · ' . $p['name'] : ( (int) $id ? '#' . (int) $id : '' );
	}

	/* ================================================================== POST handling */

	public static function maybe_handle(): void {
		if ( empty( $_POST['wb_panel'] ) || ! is_user_logged_in() ) return;
		$action = sanitize_key( (string) $_POST['wb_panel'] );
		if ( ! wp_verify_nonce( (string) ( $_POST['_wbp'] ?? '' ), 'wb_panel_' . $action ) ) return;
		$method   = 'do_' . $action;
		$handlers = (array) apply_filters( 'wb_panel_handlers', [] );   // WB_Setup, WB_Portal, WB_Payroll register theirs
		if ( method_exists( __CLASS__, $method ) ) $res = self::$method();
		elseif ( isset( $handlers[ $action ] ) && is_callable( $handlers[ $action ] ) ) $res = call_user_func( $handlers[ $action ] );
		else $res = new WP_Error( 'wb_unknown', 'Unknown action.' );
		WB_RowActions::flash_result( $res, is_array( $res ) && isset( $res['msg'] ) ? $res['msg'] : 'Saved.' );
		wp_safe_redirect( wb_return_url() );
		exit;
	}

	private static function do_customer_add() {
		if ( ! current_user_can( 'wb_manage_customers' ) ) return new WP_Error( 'wb_forbidden', 'You cannot add customers.' );
		$name = sanitize_text_field( (string) self::p( 'name' ) );
		if ( '' === $name ) return new WP_Error( 'wb_name', 'The company name is needed.' );
		$id = WB_CCT::insert( 'wb_customers', [
			'name' => $name, 'trading_name' => sanitize_text_field( (string) self::p( 'trading_name' ) ), 'vat_number' => sanitize_text_field( (string) self::p( 'vat_number' ) ),
			'payment_terms_days' => absint( self::p( 'payment_terms_days', 0 ) ), 'credit_limit' => wb_money( self::p( 'credit_limit', 0 ) ),
			'account_status' => 'open', 'price_tier_id' => absint( self::p( 'price_tier_id', 0 ) ), 'currency' => (string) get_option( 'wb_currency', 'ZAR' ),
			'region' => sanitize_text_field( (string) self::p( 'region' ) ), 'industry' => sanitize_text_field( (string) self::p( 'industry' ) ), 'journey_stage' => 'lead',
		], 'customer_added' );
		return is_wp_error( $id ) ? $id : [ 'msg' => 'Customer added.' ];
	}

	private static function do_product_add() {
		if ( ! current_user_can( 'wb_manage_products' ) ) return new WP_Error( 'wb_forbidden', 'You cannot add products.' );
		$sku = strtoupper( sanitize_text_field( (string) self::p( 'sku' ) ) );
		if ( '' === $sku || '' === trim( (string) self::p( 'name' ) ) ) return new WP_Error( 'wb_sku', 'A product needs a code and a name.' );
		if ( WB_CCT::first( 'wb_products', [ 'sku' => $sku ], [ 'active_only' => false ] ) ) return new WP_Error( 'wb_dup', 'That product code is already used.' );
		$id = WB_CCT::insert( 'wb_products', [
			'sku' => $sku, 'name' => sanitize_text_field( (string) self::p( 'name' ) ), 'category_id' => absint( self::p( 'category_id', 0 ) ),
			'unit' => sanitize_key( (string) self::p( 'unit', 'each' ) ), 'pack_size' => (float) self::p( 'pack_size', 1 ),
			'cost_price' => wb_money( self::p( 'cost_price', 0 ) ), 'list_price' => wb_money( self::p( 'list_price', 0 ) ),
			'min_margin_pct' => '' === (string) self::p( 'min_margin_pct' ) ? '' : (float) self::p( 'min_margin_pct' ),
			'price_valid_from' => sanitize_text_field( (string) self::p( 'price_valid_from' ) ), 'price_valid_to' => sanitize_text_field( (string) self::p( 'price_valid_to' ) ),
			'reorder_point' => (float) self::p( 'reorder_point', 0 ), 'reorder_qty' => (float) self::p( 'reorder_qty', 0 ), 'batch_tracked' => 'yes' === self::p( 'batch_tracked' ) ? 'yes' : 'no',
			'status' => 'active',
		], 'product_added' );
		return is_wp_error( $id ) ? $id : [ 'msg' => 'Product added. Stock starts at zero — receive it against a purchase order.' ];
	}

	private static function do_rule_add() {
		if ( ! current_user_can( 'wb_manage_pricing' ) ) return new WP_Error( 'wb_forbidden', 'You cannot set prices.' );
		$type = sanitize_key( (string) self::p( 'rule_type' ) );
		if ( ! in_array( $type, WB_Pricing::RULE_TYPES, true ) ) return new WP_Error( 'wb_type', 'Choose how the price is worked out.' );
		if ( ! absint( self::p( 'customer_id', 0 ) ) || ( ! absint( self::p( 'product_id', 0 ) ) && ! absint( self::p( 'category_id', 0 ) ) ) ) return new WP_Error( 'wb_scope', 'Choose the customer, and a product or a category.' );
		$id = WB_CCT::insert( 'wb_price_rules', [
			'customer_id' => absint( self::p( 'customer_id' ) ), 'product_id' => absint( self::p( 'product_id', 0 ) ), 'category_id' => absint( self::p( 'category_id', 0 ) ),
			'rule_type' => $type, 'value' => (float) self::p( 'value', 0 ), 'valid_from' => sanitize_text_field( (string) self::p( 'valid_from' ) ), 'valid_to' => sanitize_text_field( (string) self::p( 'valid_to' ) ),
			'status' => 'draft',
		], 'price_rule_added' );
		return is_wp_error( $id ) ? $id : [ 'msg' => 'Rule saved as a draft. Someone who approves prices must approve it before it counts.' ];
	}

	private static function do_quote_new() {
		$lines = self::parse_lines( (string) self::p( 'lines' ) );
		if ( is_wp_error( $lines ) ) return $lines;
		$id = WB_Orders::create_quote( absint( self::p( 'customer_id', 0 ) ), $lines, [ 'contact_id' => absint( self::p( 'contact_id', 0 ) ), 'notes' => (string) self::p( 'notes' ) ] );
		if ( is_wp_error( $id ) ) return $id;
		$_POST['_wb_return'] = add_query_arg( 'quote', $id, (string) ( $_POST['_wb_return'] ?? '' ) );
		return [ 'msg' => 'Draft quote created. Check the price checks below before sending.' ];
	}

	private static function do_quote_line() {
		$lines = self::parse_lines( (string) self::p( 'lines' ) );
		if ( is_wp_error( $lines ) ) return $lines;
		foreach ( $lines as $l ) {
			$r = WB_Orders::add_quote_line( absint( self::p( 'quote_id' ) ), $l['product_id'], $l['qty'], $l['manual_price'] ?? null );
			if ( is_wp_error( $r ) ) return $r;
		}
		return [ 'msg' => 'Lines added.' ];
	}

	private static function do_line_price() {
		return WB_Orders::set_line_price( absint( self::p( 'line_id' ) ), (float) self::p( 'price' ) );
	}

	private static function do_pricing_decide() {
		return WB_Pricing::decide( absint( self::p( 'approval_id' ) ), 'approve' === self::p( 'decision' ), (string) self::p( 'note' ) );
	}

	private static function do_dn_issue() {
		$q = array_map( 'floatval', (array) self::p( 'qty', [] ) );
		$b = array_map( 'absint', (array) self::p( 'batch', [] ) );
		$res = WB_Orders::issue_delivery_note( absint( self::p( 'order_id' ) ), array_filter( $q, fn( $v ) => $v > 0 ), [ 'type' => sanitize_key( (string) self::p( 'type' ) ), 'vehicle_or_courier' => (string) self::p( 'vehicle' ), 'batches' => $b ] );
		return is_wp_error( $res ) ? $res : [ 'msg' => 'Note issued. Stock has left the books.' ];
	}

	private static function do_credit_request() {
		$reason = sanitize_key( (string) self::p( 'reason' ) );
		if ( '' !== trim( (string) self::p( 'lines' ) ) ) {
			$parsed = self::parse_lines( (string) self::p( 'lines' ) );
			if ( is_wp_error( $parsed ) ) return $parsed;
			$lines = array_map( fn( $l ) => [ 'product_id' => $l['product_id'], 'qty' => $l['qty'], 'unit_price' => $l['manual_price'] ?? 0 ], $parsed );
		} else {
			$lines = [ [ 'qty' => 1, 'amount' => (float) self::p( 'amount' ), 'description' => (string) self::p( 'description' ) ] ];
		}
		$res = WB_Invoices::request_credit_note( absint( self::p( 'invoice_id' ) ), $reason, $lines, 'yes' === self::p( 'return_stock' ) );
		return is_wp_error( $res ) ? $res : [ 'msg' => 'Credit note asked for. Someone else who approves credit notes must approve it.' ];
	}

	/* ---------- bank statement import: upload → choose the header row → map the columns → import ---------- */

	private static function bank_state_key(): string {
		return 'wb_bank_state_' . get_current_user_id();
	}

	/** Step 1. With a saved layout the file is imported at once; otherwise the mapping steps start. */
	private static function do_bank_upload() {
		if ( ! current_user_can( 'wb_import_bank' ) ) return new WP_Error( 'wb_forbidden', 'You cannot import bank statements.' );
		if ( empty( $_FILES['statement']['tmp_name'] ) || ! is_uploaded_file( $_FILES['statement']['tmp_name'] ) ) return new WP_Error( 'wb_no_file', 'Choose the statement file.' );
		if ( (int) $_FILES['statement']['size'] > 5 * MB_IN_BYTES ) return new WP_Error( 'wb_big', 'That file is too big for a statement.' );
		$name = sanitize_file_name( (string) $_FILES['statement']['name'] );
		if ( ! preg_match( '/\.(csv|txt|tsv)$/i', $name ) ) return new WP_Error( 'wb_type', 'Save the statement or spreadsheet as CSV first, then choose that file.' );
		$csv = (string) file_get_contents( $_FILES['statement']['tmp_name'] );
		if ( '' === trim( $csv ) ) return new WP_Error( 'wb_empty', 'That file is empty.' );
		$key = WB_Storage::put_contents( $csv, 'bank/' . gmdate( 'Y/m' ) . '/' . ( $name ?: 'statement.csv' ) );
		if ( '' === $key ) return new WP_Error( 'wb_store_failed', 'The file could not be stored.' );
		$profile = sanitize_key( (string) self::p( 'profile' ) );
		if ( '' !== $profile && WB_Payments::profile( $profile ) ) {
			$res = WB_Payments::import( $csv, $profile, $name, $key );
			if ( ! is_wp_error( $res ) ) return [ 'msg' => WB_Payments::summary_text( $res ) ];
			if ( 'wb_no_header' !== $res->get_error_code() ) return $res;
			set_transient( self::bank_state_key(), [ 'key' => $key, 'name' => $name, 'header_row' => 0 ], HOUR_IN_SECONDS );
			return new WP_Error( 'wb_no_header', 'That layout did not fit this file. Choose the row with the column names below and map the columns.' );
		}
		set_transient( self::bank_state_key(), [ 'key' => $key, 'name' => $name, 'header_row' => 0 ], HOUR_IN_SECONDS );
		return [ 'msg' => 'File received. Now choose the row that holds the column names.' ];
	}

	/** Step 2: the header row. */
	private static function do_bank_header() {
		if ( ! current_user_can( 'wb_import_bank' ) ) return new WP_Error( 'wb_forbidden', 'You cannot import bank statements.' );
		$st = get_transient( self::bank_state_key() );
		if ( ! is_array( $st ) ) return new WP_Error( 'wb_expired', 'The upload has expired. Choose the file again.' );
		$st['header_row'] = max( 1, absint( self::p( 'header_row', 1 ) ) );
		set_transient( self::bank_state_key(), $st, HOUR_IN_SECONDS );
		return [ 'msg' => 'Now say which column holds what.' ];
	}

	/** Step 3: the mapping is saved under its name, then the file is imported with it. */
	private static function do_bank_map() {
		$st = get_transient( self::bank_state_key() );
		if ( ! is_array( $st ) || empty( $st['header_row'] ) ) return new WP_Error( 'wb_expired', 'The upload has expired. Choose the file again.' );
		$csv  = WB_Storage::get_contents( (string) $st['key'] );
		$pv   = WB_Payments::preview( $csv, (int) $st['header_row'] );
		$hdr  = (array) ( $pv['rows'][ (int) $st['header_row'] - 1 ] ?? [] );
		$cols = [];
		foreach ( WB_Payments::ROLES as $r ) {
			$v = (string) self::p( 'col_' . $r, '' );
			if ( '' !== $v ) $cols[ $r ] = (int) $v;
		}
		$names = [];
		foreach ( $cols as $r => $i ) $names[ $r ] = (string) ( $hdr[ $i ] ?? '' );
		$key = WB_Payments::save_profile( (string) self::p( 'profile_name' ), [
			'header_row' => (int) $st['header_row'], 'columns' => $cols, 'names' => $names, 'amount_mode' => sanitize_key( (string) self::p( 'amount_mode' ) ),
			'date_format' => sanitize_key( (string) self::p( 'date_format', 'auto' ) ), 'decimal' => sanitize_key( (string) self::p( 'decimal', 'auto' ) ),
			'delimiter' => "\t" === $pv['delimiter'] ? 'tab' : $pv['delimiter'],
		] );
		if ( is_wp_error( $key ) ) return $key;
		$res = WB_Payments::import_statement( $csv, (array) WB_Payments::profile( $key ), $key, (string) $st['name'], (string) $st['key'] );
		if ( is_wp_error( $res ) ) return $res;
		delete_transient( self::bank_state_key() );
		return [ 'msg' => 'Layout saved as "' . WB_Payments::profile( $key )['label'] . '". ' . WB_Payments::summary_text( $res ) ];
	}

	private static function do_bank_cancel() {
		delete_transient( self::bank_state_key() );
		return [ 'msg' => 'Import stopped. Nothing was recorded.' ];
	}

	/** The mapping steps, while an upload is waiting. */
	private static function bank_steps( array $st ): string {
		$csv  = WB_Storage::get_contents( (string) $st['key'] );
		$stop = WB_Render::form_open( 'bank_cancel' ) . WB_Render::form_close( 'Start again' );
		if ( '' === $csv ) return wb_notice( 'warn', 'The uploaded file can no longer be read.' ) . $stop;
		$col = fn( int $i ): string => $i < 26 ? chr( 65 + $i ) : 'Column ' . ( $i + 1 );
		$cut = fn( string $v ): string => strlen( $v ) > 40 ? substr( $v, 0, 38 ) . '...' : $v;
		if ( empty( $st['header_row'] ) ) {
			$pv    = WB_Payments::preview( $csv, 10 );
			$guess = 1;
			foreach ( $pv['rows'] as $i => $r ) {
				$g = WB_Payments::guess( $r );
				if ( isset( $g['date'] ) && ( isset( $g['amount'] ) || isset( $g['credit'] ) ) ) { $guess = $i + 1; break; }
			}
			$t = '<table class="wb-list"><thead><tr><th>Row</th><th>What is in it</th></tr></thead><tbody>';
			foreach ( $pv['rows'] as $i => $r ) {
				$t .= '<tr><td><label><input type="radio" name="header_row" value="' . ( $i + 1 ) . '"' . checked( $guess, $i + 1, false ) . '> ' . ( $i + 1 ) . '</label></td><td>' . esc_html( implode( ' | ', array_map( $cut, array_slice( $r, 0, 8 ) ) ) ) . '</td></tr>';
			}
			$t .= '</tbody></table>';
			return '<p><strong>' . esc_html( (string) $st['name'] ) . '</strong> — which row holds the column names (Date, Description, Amount…)? Banks often put account details above it.</p>'
				. WB_Render::form_open( 'bank_header' ) . $t . WB_Render::form_close( 'This is the row with the column names' ) . $stop;
		}
		$hr   = (int) $st['header_row'];
		$d    = WB_Payments::detect_delimiter( $csv );
		$rows = WB_Payments::csv_rows( $csv, $d );
		$hdr  = (array) ( $rows[ $hr - 1 ] ?? [] );
		$body = array_slice( $rows, $hr, 50 );
		$opts = [ '' => '— not in this file —' ];
		foreach ( $hdr as $i => $h ) {
			$eg         = trim( (string) ( $body[0][ $i ] ?? '' ) );
			$opts[ $i ] = $col( $i ) . ': ' . ( '' !== $h ? $cut( $h ) : '(no name)' ) . ( '' !== $eg ? ' — e.g. ' . $cut( $eg ) : '' );
		}
		$g      = WB_Payments::guess( $hdr );
		$mode   = isset( $g['credit'] ) ? 'split' : 'signed';
		$pick   = fn( string $role ): string => isset( $g[ $role ] ) ? (string) $g[ $role ] : '';
		$sample = fn( string $role ): array => isset( $g[ $role ] ) ? array_map( fn( $r ) => (string) ( $r[ $g[ $role ] ] ?? '' ), $body ) : [];
		$df     = WB_Payments::detect_date_format( $sample( 'date' ) );
		$dec    = WB_Payments::detect_decimal( array_merge( $sample( 'amount' ), $sample( 'credit' ), $sample( 'debit' ) ) );
		$f      = WB_Render::form_open( 'bank_map' )
			. WB_Render::field( 'col_date', 'Date', 'select', $pick( 'date' ), [ 'options' => $opts, 'required' => true ] )
			. WB_Render::field( 'col_description', 'Description', 'select', $pick( 'description' ), [ 'options' => $opts ] )
			. WB_Render::field( 'col_reference', 'Reference', 'select', $pick( 'reference' ), [ 'options' => $opts ] )
			. WB_Render::field( 'amount_mode', 'How the amount is shown', 'select', $mode, [ 'options' => [ 'signed' => 'One amount column (money out has a minus)', 'split' => 'Two columns: money in and money out' ] ] )
			. WB_Render::field( 'col_amount', 'Amount (one column)', 'select', $pick( 'amount' ), [ 'options' => $opts ] )
			. WB_Render::field( 'col_credit', 'Money in (credit)', 'select', $pick( 'credit' ), [ 'options' => $opts ] )
			. WB_Render::field( 'col_debit', 'Money out (debit)', 'select', $pick( 'debit' ), [ 'options' => $opts ] )
			. WB_Render::field( 'date_format', 'Dates look like', 'select', 'auto' === $df ? 'dmy' : $df, [ 'options' => WB_Payments::DATE_FORMATS ] )
			. WB_Render::field( 'decimal', 'Amounts look like', 'select', $dec, [ 'options' => WB_Payments::DECIMALS, 'note' => 'R in front is fine either way.' ] )
			. WB_Render::field( 'profile_name', 'Save this layout as', 'text', '', [ 'required' => true, 'placeholder' => 'FNB cheque', 'note' => 'Next time, choose this layout and the file imports in one step.' ] );
		return '<p><strong>' . esc_html( (string) $st['name'] ) . '</strong> — column names are on row ' . $hr . '. Only money in becomes a payment; money out is counted and left alone.</p>'
			. $f . WB_Render::form_close( 'Save the layout and import' ) . $stop;
	}

	private static function do_manual_match() {
		return WB_Payments::manual_match( absint( self::p( 'payment_id' ) ), absint( self::p( 'invoice_id' ) ), (string) self::p( 'note' ) );
	}

	private static function do_receipt() {
		$r = WB_Payments::record_receipt( absint( self::p( 'invoice_id' ) ), (float) self::p( 'amount' ), sanitize_key( (string) self::p( 'method' ) ), (string) self::p( 'reference' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Payment recorded against the invoice.' ];
	}

	private static function do_stock_request() {
		$r = WB_Stock::request_adjustment( absint( self::p( 'product_id' ) ), (float) self::p( 'qty' ), sanitize_key( (string) self::p( 'type' ) ), (string) self::p( 'reason' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Asked. A second person must approve before stock changes.' ];
	}

	private static function do_stock_decide() {
		return WB_Stock::decide_request( absint( self::p( 'request_id' ) ), 'approve' === self::p( 'decision' ), (string) self::p( 'note' ) );
	}

	private static function do_po_new() {
		$lines = self::parse_lines( (string) self::p( 'lines' ) );
		if ( is_wp_error( $lines ) ) return $lines;
		$lines = array_map( fn( $l ) => [ 'product_id' => $l['product_id'], 'qty' => $l['qty'] ] + ( isset( $l['manual_price'] ) ? [ 'unit_cost' => $l['manual_price'] ] : [] ), $lines );
		$r = WB_Stock::create_po( absint( self::p( 'supplier_id' ) ), $lines, sanitize_text_field( (string) self::p( 'expected_at' ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Draft purchase order created.' ];
	}

	private static function do_po_receive() {
		$batch = absint( self::p( 'batch_id', 0 ) );
		$no    = strtoupper( sanitize_text_field( (string) self::p( 'batch_no' ) ) );
		if ( ! $batch && '' !== $no ) {   // 1.7.0: a batch number typed at the door finds its batch, or starts one
			$line = WB_CCT::get( 'wb_po_lines', absint( self::p( 'po_line_id' ) ) );
			$po   = $line ? WB_CCT::get( 'wb_purchase_orders', (int) $line['po_id'] ) : null;
			if ( ! $line ) return new WP_Error( 'wb_not_found', 'Purchase order line not found.' );
			$b = WB_CCT::first( 'wb_batches', [ 'product_id' => (int) $line['product_id'], 'batch_no' => $no ] );
			$batch = $b ? (int) $b['_ID'] : (int) WB_CCT::insert( 'wb_batches', [ 'product_id' => (int) $line['product_id'], 'batch_no' => $no, 'received_at' => wb_now(), 'expiry_at' => sanitize_text_field( (string) self::p( 'expiry_at' ) ), 'supplier_id' => (int) ( $po['supplier_id'] ?? 0 ) ], 'batch_created' );
			if ( $batch <= 0 ) return new WP_Error( 'wb_batch', 'The batch could not be recorded.' );
		}
		$r = WB_Stock::receive_po_line( absint( self::p( 'po_line_id' ) ), (float) self::p( 'qty' ), $batch, (string) self::p( 'location' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Received. Stock is up.' ];
	}

	private static function do_stocktake_start() {
		$r = WB_Stock::start_stocktake( (string) self::p( 'location' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Stocktake started. Enter what you count; a different person checks it.' ];
	}

	private static function do_stocktake_count() {
		return WB_Stock::record_count( absint( self::p( 'stocktake_id' ) ), absint( self::p( 'product_id' ) ), (float) self::p( 'counted' ), absint( self::p( 'batch_id', 0 ) ) );
	}

	private static function do_doc_upload() {
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) return new WP_Error( 'wb_no_file', 'Choose the file.' );
		$check = wp_check_filetype_and_ext( $_FILES['file']['tmp_name'], (string) $_FILES['file']['name'] );
		if ( empty( $check['type'] ) ) return new WP_Error( 'wb_type', 'That kind of file is not allowed.' );
		$r = WB_Documents::register( (string) $_FILES['file']['tmp_name'], [
			'type' => sanitize_key( (string) self::p( 'type' ) ), 'title' => (string) self::p( 'title' ), 'product_id' => absint( self::p( 'product_id', 0 ) ),
			'customer_id' => absint( self::p( 'customer_id', 0 ) ), 'supersedes_doc_id' => absint( self::p( 'supersedes_doc_id', 0 ) ), 'expires_at' => (string) self::p( 'expires_at' ),
			'is_customer_visible' => 'yes' === self::p( 'visible' ), 'mime' => (string) $check['type'], 'original_name' => (string) $_FILES['file']['name'],
		] );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Filed.' ];
	}

	private static function do_datasheet_link() {
		return WB_Documents::datasheet_link( absint( self::p( 'product_id' ) ), absint( self::p( 'customer_id', 0 ) ) );
	}

	private static function do_touchpoint() {
		if ( ! current_user_can( 'wb_manage_marketing' ) && ! current_user_can( 'wb_manage_customers' ) ) return new WP_Error( 'wb_forbidden', 'You cannot record contact moments.' );
		$type = sanitize_key( (string) self::p( 'type' ) );
		if ( ! in_array( $type, [ 'call', 'email', 'visit', 'complaint', 'note' ], true ) ) return new WP_Error( 'wb_type', 'Choose what kind of contact it was.' );
		$id = WB_CCT::insert( 'wb_touchpoints', [
			'customer_id' => absint( self::p( 'customer_id' ) ), 'type' => $type, 'happened_at' => wb_now(), 'staff_id' => WB_Staff::current_staff_id(),
			'summary' => sanitize_textarea_field( (string) self::p( 'summary' ) ), 'next_action' => sanitize_text_field( (string) self::p( 'next_action' ) ),
			'next_action_date' => sanitize_text_field( (string) self::p( 'next_action_date' ) ),
		], 'touchpoint_' . $type );
		return is_wp_error( $id ) ? $id : [ 'msg' => 'Recorded.' ];
	}

	private static function do_timesheet() {
		$r = WB_Staff::save_timesheet( (string) self::p( 'work_date' ), (string) self::p( 'start' ), (string) self::p( 'end' ), absint( self::p( 'break_minutes', 0 ) ), sanitize_key( (string) self::p( 'activity' ) ), absint( self::p( 'customer_id', 0 ) ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Saved as a draft. Submit it from the list when the day is right.' ];
	}

	private static function do_leave() {
		$r = WB_Staff::request_leave( absint( self::p( 'leave_type_id' ) ), (string) self::p( 'from_date' ), (string) self::p( 'to_date' ), (string) self::p( 'note' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Leave asked for.' ];
	}

	private static function do_leave_types() {
		if ( ! current_user_can( 'wb_manage_staff' ) ) return new WP_Error( 'wb_forbidden', 'You cannot set up leave.' );
		WB_Staff::ensure_leave_types();
		return [ 'msg' => 'Leave types are set up (South African defaults; edit them in JetEngine if your policy differs).' ];
	}

	private static function do_review_schedule() {
		$r = WB_Staff::schedule_review( absint( self::p( 'staff_id' ) ), absint( self::p( 'reviewer_staff_id' ) ), (string) self::p( 'period' ) );
		return is_wp_error( $r ) ? $r : [ 'msg' => 'Review scheduled.' ];
	}

	private static function do_review_step() {
		$text = sanitize_textarea_field( (string) self::p( 'text' ) );
		return WB_Staff::advance_review( absint( self::p( 'review_id' ) ), sanitize_key( (string) self::p( 'to' ) ), [
			'self_json' => [ 'text' => $text ], 'manager_json' => [ 'text' => $text ], 'rating' => (float) self::p( 'rating', 0 ), 'goals_json' => [ 'text' => sanitize_textarea_field( (string) self::p( 'goals' ) ) ],
		] );
	}

	private static function do_kpis() {
		if ( ! current_user_can( 'wb_run_reviews' ) ) return new WP_Error( 'wb_forbidden', 'You cannot measure KPIs.' );
		$from = substr( wb_now(), 0, 7 ) . '-01';
		$n    = WB_Staff::measure_kpis( $from, gmdate( 'Y-m-t', strtotime( $from ) ) );
		return [ 'msg' => sprintf( '%d scores measured for this month.', $n ) ];
	}

	private static function do_settings() {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return new WP_Error( 'wb_forbidden', 'Only Settings may change settings.' );
		$before = [];
		$after  = [];
		$set    = function ( string $opt, $val ) use ( &$before, &$after ) {
			$old = get_option( $opt );
			if ( $old == $val ) return;   // phpcs:ignore — loose on purpose: "15" == 15
			$before[ $opt ] = $old;
			$after[ $opt ]  = $val;
			update_option( $opt, $val );
		};
		$set( 'wb_vat_rate', max( 0, min( 100, (float) self::p( 'wb_vat_rate', 15 ) ) ) );
		$set( 'wb_invoice_trigger', 'dispatch' === self::p( 'wb_invoice_trigger' ) ? 'dispatch' : 'acceptance' );
		$set( 'wb_default_terms_days', absint( self::p( 'wb_default_terms_days', 30 ) ) );
		$set( 'wb_pricing', [ 'default_min_margin_pct' => (float) self::p( 'default_min_margin_pct', 20 ), 'quote_validity_days' => max( 1, absint( self::p( 'quote_validity_days', 30 ) ) ) ] );
		$set( 'wb_journey_rules', [ 'at_risk_days' => absint( self::p( 'at_risk_days', 60 ) ), 'lapsed_days' => absint( self::p( 'lapsed_days', 180 ) ), 'at_risk_interval_multiple' => (float) self::p( 'at_risk_interval_multiple', 2 ) ] );
		$set( 'wb_cashflow', [ 'payroll_monthly' => wb_money( self::p( 'payroll_monthly', 0 ) ), 'opening_balance' => wb_money( self::p( 'opening_balance', 0 ) ) ] );
		$prefixes = [];
		foreach ( WB_Sequences::TYPES as $t ) $prefixes[ $t ] = WB_Sequences::clean_prefix( (string) self::p( 'prefix_' . $t, $t ), $t );
		$set( 'wb_number_prefixes', $prefixes );
		foreach ( array_keys( WB_Notifications::GROUPS ) as $g ) $set( 'wb_notify_' . $g . '_email', 'yes' === self::p( 'email_' . $g ) ? 1 : 0 );
		if ( $after ) wb_ledger_write( 'settings_changed', 'wp_options', 0, $before, $after );
		return [ 'msg' => $after ? 'Settings saved.' : 'Nothing changed.' ];
	}

	private static function do_dashboards() {
		$res = WB_Roles::set_dashboards( absint( self::p( 'user_id' ) ), (array) self::p( 'dash', [] ) );
		return is_wp_error( $res ) ? $res : [ 'msg' => 'Saved. Their pages change the next time they load one.' ];
	}

	private static function do_ledger_verify() {
		if ( ! current_user_can( 'wb_view_integrity' ) ) return new WP_Error( 'wb_forbidden', 'You cannot check the audit trail.' );
		WB_Ledger::nightly_verify();
		$l = (array) get_option( 'wb_ledger_last_check', [] );
		return ! empty( $l['ok'] ) ? [ 'msg' => 'The audit trail is intact.' ] : new WP_Error( 'wb_broken', 'The audit trail has a break at entry #' . (int) ( $l['entry_id'] ?? 0 ) . '. The owners have been notified.' );
	}

	/* ================================================================== dashboards */

	/** Categories, price tiers and customer pricing, in plain words (Zina, 8 October 2026). Shown on Products. */
	const PRICING_WORDS = '<p><strong>Categories</strong> group products (Adhesives, Fasteners, Coatings). A category carries two things its products inherit: the lowest margin allowed, and the specification rows every product in it shares. A category never sets a selling price.</p>'
		. '<p><strong>Price tiers</strong> are the standard discount levels (Trade, Distributor, Project), each a percentage off the list price. Every customer sits on one tier, and that tier is their price unless something more specific says otherwise. New customers start on the default tier.</p>'
		. '<p><strong>Customer price rules</strong> are for one customer: a fixed price, a percentage off list, or a percentage on cost, for one product or a whole category, between two dates, approved by someone other than the person who set it.</p>'
		. '<p><strong>Check one</strong> finds the most specific answer: a rule for this customer and this product, then a rule for this customer and the product\'s category, then the customer\'s tier, then the list price. <strong>Check two</strong> makes sure that price is allowed: not below cost plus the lowest margin (the product\'s, or its category\'s), and not past the price\'s valid-until date. Both answers are frozen onto the quote line, so a later change to a rule, a tier or a cost never rewrites an old quote.</p>';

	/** Quick actions on Today: [ cap, screen, fold anchor, words, note ]. At most six show. */
	const QUICK = [
		[ 'wb_create_quotes', 'quotes', '#wb-add', 'Write a quote', 'find products as you type' ],
		[ 'wb_manage_customers', 'customers', '#wb-add', 'Add a customer', 'terms, credit limit, price tier' ],
		[ 'wb_import_bank', 'payments', '#wb-add', 'Import a bank statement', 'CSV from the bank, matched for you' ],
		[ 'wb_manage_products', 'products', '#wb-add', 'Add a product', 'cost, list price, lowest margin' ],
		[ 'wb_move_stock', 'stock', '#wb-add', 'Correct stock', 'a second person approves it' ],
		[ 'wb_manage_purchasing', 'purchasing', '#wb-add', 'Raise a purchase order', 'what to reorder, from whom' ],
		[ 'wb_access_workspace', 'staff', '#wb-add', 'My timesheet', 'today\'s hours' ],
		[ 'wb_manage_documents', 'documents', '#wb-add', 'Add a datasheet', 'typed, or a whole range by CSV' ],
	];

	public static function home( $atts = [] ): string {
		if ( $g = self::gate( 'wb_access_workspace' ) ) return $g;
		$h = WB_RowActions::notice();
		if ( current_user_can( 'wb_manage_settings' ) && in_array( false, array_column( WB_Setup::checklist(), 1 ), true ) ) $h .= WB_Setup::checklist_card();
		// The week in numbers: label → number → note. The one that must not be missed is hot.
		$stats = '';
		if ( current_user_can( 'wb_create_quotes' ) ) $stats .= WB_Render::stat( 'Quotes out', WB_CCT::count( 'wb_quotes', [ 'status' => 'sent' ] ), WB_Workspace::url( 'quotes' ), 'sent, not yet answered' );
		if ( current_user_can( 'wb_manage_orders' ) ) $stats .= WB_Render::stat( 'Orders waiting for payment', WB_CCT::count( 'wb_orders', [ 'status' => 'awaiting_payment' ] ), WB_Workspace::url( 'orders' ), 'goods wait for the money' );
		if ( current_user_can( 'wb_issue_delivery_notes' ) ) $stats .= WB_Render::stat( 'Ready to go out', WB_CCT::count( 'wb_orders', [ 'status' => [ 'ready', 'part_delivered' ] ] ), WB_Workspace::url( 'deliveries' ), 'released and put aside' );
		if ( current_user_can( 'wb_match_payments' ) ) {
			$overdue = WB_CCT::count( 'wb_invoices', [ 'status' => 'overdue' ] );
			$stats  .= WB_Render::stat( 'Overdue invoices', $overdue, WB_Workspace::url( 'invoices' ), $overdue ? 'past the due date' : 'nothing overdue', $overdue > 0 );
			$stats  .= WB_Render::stat( 'Payments to match', WB_CCT::count( 'wb_payments', [ 'match_status' => [ 'unmatched', 'suggested' ] ] ), WB_Workspace::url( 'payments' ), 'in the bank, not yet on an invoice' );
		}
		if ( current_user_can( 'wb_manage_purchasing' ) ) $stats .= WB_Render::stat( 'Products to reorder', count( WB_Stock::open_alerts() ), WB_Workspace::url( 'purchasing' ), 'below the reorder point' );
		if ( '' !== $stats ) $h .= '<div class="wb-stats">' . $stats . '</div>';
		$h .= WB_Today::cards();   // 1.6.0: how is the month, and is cash fine
		// Needs attention beside quick actions (Kaycie's Today).
		$quick = '';
		foreach ( self::QUICK as [ $cap, $slug, $anchor, $words, $note ] ) {
			if ( ! current_user_can( $cap ) ) continue;
			$quick .= '<a class="wb-quick-btn" href="' . esc_url( WB_Workspace::url( $slug ) . $anchor ) . '">' . esc_html( $words ) . '<small>' . esc_html( $note ) . '</small></a>';
			if ( 6 === substr_count( $quick, 'wb-quick-btn' ) ) break;
		}
		$h .= '<div class="wb-two">' . WB_Needs::card() . ( '' !== $quick ? '<section class="wb-card" aria-labelledby="wb-quick-h"><h2 id="wb-quick-h">Quick actions</h2><div class="wb-quick">' . $quick . '</div></section>' : '' ) . '</div>';
		return $h . WB_Notifications::panel();
	}

	public static function customers( $atts = [] ): string {
		if ( $g = self::gate( 'wb_view_customers' ) ) return $g;
		$h    = WB_RowActions::notice();
		if ( ( $cid = absint( $_GET['customer'] ?? 0 ) ) > 0 ) return $h . WB_Pages::customer( $cid );   // 1.5.0: the customer's own page
		$none = 0 === WB_CCT::count( 'wb_customers' );
		$h   .= WB_List::render( 'wb_customers', [ [ 'key' => 'name', 'render' => fn( $v, $r ) => self::customer_link( (int) $r['_ID'], (string) $v ) ], 'account_status', 'journey_stage', 'payment_terms_days', [ 'key' => 'credit_limit', 'type' => 'money' ], 'region' ],
			[ 'cct' => 'wb_customers', 'actions' => [ 'customer_open', 'send_statement', 'edit_wb_customers', 'archive_wb_customers' ], 'archive' => true, 'empty' => 'No customers yet.', 'what' => 'customers', 'placeholder' => 'Name, trading name or region',
				'search' => [ 'name', 'trading_name', 'region', 'vat_number' ], 'status' => 'account_status', 'orderby' => 'name', 'order' => 'asc' ] );
		$h .= WB_Records::fold( 'wb_customers', $none );   // first run: open
		$h .= WB_Import::fold( 'wb_customers' );
		return $h . WB_Portal::staff_panel();
	}

	public static function products( $atts = [] ): string {
		if ( $g = self::gate( 'wb_view_products' ) ) return $g;
		$h    = WB_RowActions::notice();
		if ( ( $pid = absint( $_GET['product'] ?? 0 ) ) > 0 ) return $h . WB_Pages::product( $pid );   // 1.5.0: the product's own page
		$none = 0 === WB_CCT::count( 'wb_products' );
		$rows = ! $none;
		$seeing_stock = current_user_can( 'wb_view_stock' );
		$cols = [ [ 'key' => 'sku', 'render' => fn( $v, $r ) => '<a class="wb-with-thumb" href="' . esc_url( WB_Workspace::url( 'products', [ 'product' => (int) $r['_ID'] ] ) ) . '">' . WB_Product_Images::thumb( WB_Product_Images::for_product( (int) $r['_ID'] ), '' ) . '<span>' . esc_html( (string) $v ) . '</span></a>' ], 'name', [ 'key' => 'list_price', 'type' => 'money' ] ];
		if ( current_user_can( 'wb_manage_pricing' ) ) { $cols[] = [ 'key' => 'cost_price', 'type' => 'money' ]; $cols[] = 'min_margin_pct'; }
		if ( $seeing_stock ) $cols[] = [ 'key' => 'available', 'label' => 'Available', 'render' => fn( $v, $r ) => esc_html( rtrim( rtrim( number_format( WB_Stock::available( (int) $r['_ID'] ), 2, '.', ' ' ), '0' ), '.' ) ) ];
		$cols[] = [ 'key' => '_ID', 'label' => 'Datasheet', 'type' => 'plain', 'render' => fn( $v, $r ) => WB_Datasheets::cell( WB_Datasheets::current( (int) $v, $r ) ) ];
		$cols[] = 'status';
		$h .= WB_List::render( 'wb_products', $cols, [ 'cct' => 'wb_products', 'actions' => [ 'product_open', 'edit_wb_products', 'datasheet_pdf', 'send_datasheet', 'product_docs', 'archive_wb_products' ], 'archive' => true, 'prefetch' => [ 'WB_Product_Images', 'prime' ], 'empty' => 'No products yet.', 'what' => 'products',
			'placeholder' => 'Code, name or barcode', 'search' => [ 'sku', 'name', 'barcode' ], 'orderby' => 'sku', 'order' => 'asc', 'sortable' => [ 'sku', 'name', 'list_price', 'cost_price', 'min_margin_pct', 'status' ] ] );
		$h .= WB_Records::fold( 'wb_products', ! $rows );   // first run: open
		$h .= WB_Import::fold( 'wb_products' );
		if ( current_user_can( 'wb_manage_products' ) ) {
			$cats = WB_CCT::find( 'wb_product_categories', [], [ 'orderby' => 'name', 'order' => 'ASC', 'limit' => 500 ] );
			$h   .= self::fold( 'Categories', WB_Render::render_table( $cats, [ 'name', [ 'key' => 'parent_id', 'label' => 'Inside', 'render' => fn( $v ) => esc_html( $v ? (string) ( WB_CCT::get( 'wb_product_categories', (int) $v )['name'] ?? '' ) : '' ) ], 'min_margin_pct' ], [ 'cct' => 'wb_product_categories', 'actions' => [ 'edit_wb_product_categories' ], 'empty' => 'No categories yet.', 'empty_note' => 'A category carries a lowest margin and the specification rows every product in it shares.' ] ) . WB_Records::form( 'wb_product_categories', self::editing( 'wb_product_categories' ) ), (bool) self::editing( 'wb_product_categories' ), 'wb-add-product_categories', 'sibling', count( $cats ) . ( 1 === count( $cats ) ? ' category' : ' categories' ) );
			$h   .= WB_Import::fold( 'wb_product_categories' );
		}
		if ( current_user_can( 'wb_manage_pricing' ) ) {
			$tiers = WB_CCT::find( 'wb_price_tiers', [], [ 'orderby' => 'name', 'order' => 'ASC', 'limit' => 100 ] );
			$h    .= self::fold( 'Price tiers', WB_Render::render_table( $tiers, [ 'name', [ 'key' => 'discount_pct', 'label' => '% off list' ], [ 'key' => 'is_default', 'label' => 'New customers start here', 'render' => fn( $v ) => wb_truthy( $v ) ? 'Yes' : '' ] ], [ 'cct' => 'wb_price_tiers', 'actions' => [ 'edit_wb_price_tiers' ], 'empty' => 'No price tiers yet.', 'empty_note' => 'Trade, Distributor, Project: each is a % off the list price.' ] ) . WB_Records::form( 'wb_price_tiers', self::editing( 'wb_price_tiers' ) ), (bool) self::editing( 'wb_price_tiers' ), 'wb-add-price_tiers', 'sibling', count( $tiers ) . ( 1 === count( $tiers ) ? ' tier' : ' tiers' ) );
			$h    .= WB_Import::fold( 'wb_price_tiers' );
		}
		if ( current_user_can( 'wb_manage_pricing' ) ) {
			$rules = WB_CCT::find( 'wb_price_rules', [], [ 'limit' => 500 ] );
			$body  = WB_Render::render_table( $rules, [ [ 'key' => 'customer_id', 'render' => fn( $v ) => esc_html( self::customer_name( $v ) ) ], [ 'key' => 'product_id', 'render' => fn( $v, $r ) => esc_html( $v ? self::product_name( $v ) : 'Category #' . $r['category_id'] ) ], 'rule_type', 'value', 'valid_from', 'valid_to', 'status' ], [ 'cct' => 'wb_price_rules', 'actions' => [ 'rule_approve', 'archive_wb_price_rules' ], 'empty' => 'No customer price rules.' ] );
			$body .= WB_Render::form_open( 'rule_add' ) . WB_Render::field( 'customer_id', 'Customer', 'select', '', [ 'options' => WB_Render::options( 'wb_customers', 'name' ) ] )
				. WB_Render::field( 'product_id', 'Product', 'select', '', [ 'options' => WB_Render::options( 'wb_products', 'name' ) ] ) . WB_Render::field( 'category_id', 'or a whole category', 'select', '', [ 'options' => WB_Render::options( 'wb_product_categories', 'name' ) ] )
				. WB_Render::field( 'rule_type', 'Price is', 'select', 'pct_off_list', [ 'options' => [ 'fixed_price' => 'a fixed price', 'pct_off_list' => '% off the list price', 'pct_on_cost' => '% on top of cost' ] ] )
				. WB_Render::field( 'value', 'Amount or %', 'number', '', [ 'placeholder' => '12.5' ] ) . WB_Render::field( 'valid_from', 'From', 'date' ) . WB_Render::field( 'valid_to', 'Until', 'date' ) . WB_Render::form_close( 'Save rule (draft)' );
			$h .= self::fold( 'Customer price rules', $body, false, '', 'sibling' );
		}
		if ( current_user_can( 'wb_view_products' ) ) {
			$h .= self::fold( 'How a price is worked out', self::PRICING_WORDS, false, 'wb-pricing-words', 'reference' );
		}
		return $h;
	}

	public static function quotes( $atts = [] ): string {
		if ( $g = self::gate( 'wb_create_quotes' ) ) return $g;
		$h = WB_RowActions::notice();
		if ( current_user_can( 'wb_approve_pricing' ) && ( $pending = WB_Pricing::pending() ) ) {
			$body = WB_Render::render_table( $pending, [ [ 'key' => 'quote_id', 'label' => 'Quote', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_quotes', (int) $v )['quote_number'] ?? '#' . $v ) ) ],
				[ 'key' => 'product_id', 'render' => fn( $v ) => esc_html( self::product_name( $v ) ) ], [ 'key' => 'reason', 'label' => 'Why', 'render' => function ( $v, $r ) { $l = WB_CCT::get( 'wb_quote_lines', (int) ( $r['quote_line_id'] ?? 0 ) ); return esc_html( $l ? ( WB_Pricing::explain( $l ) ?: WB_Render::words( (string) $v ) ) : WB_Render::words( (string) $v ) ); } ], [ 'key' => 'floor_price', 'type' => 'money' ], [ 'key' => 'asked_price', 'label' => 'Asked', 'type' => 'money' ], 'margin_pct', 'note' ],
				[ 'action_html' => function ( $r ) {
					if ( (int) $r['requested_by'] === get_current_user_id() ) return '';
					$m = '';
					foreach ( [ 'approve' => [ 'approve', 'Approve' ], 'decline' => [ 'withdraw', 'Decline' ] ] as $d => $ui ) {
						$m .= WB_Render::form_open( 'pricing_decide' ) . '<input type="hidden" name="approval_id" value="' . (int) $r['id'] . '"><input type="hidden" name="decision" value="' . $d . '">' . WB_RowActions::menuitem( $ui[0], $ui[1], [ 'submit' => true ] ) . '</form>';
					}
					return $m;
				} ] );
			$h .= self::fold( 'Prices waiting for your approval', $body, true );
		}
		$qid = absint( $_GET['quote'] ?? 0 );
		if ( $qid && ( $q = WB_CCT::get( 'wb_quotes', $qid ) ) ) {
			$lines = WB_CCT::find( 'wb_quote_lines', [ 'quote_id' => $qid ], [ 'order' => 'ASC' ] );
			$body  = '<p>' . self::customer_link( (int) $q['customer_id'] ) . ' · ' . WB_Render::chip( $q['status'] ) . ' · price check ' . WB_Render::chip( $q['pricing_check_status'] ) . ' · total R ' . esc_html( WB_Render::money( $q['total'] ) ) . ( 'draft' === $q['status'] ? ' · valid until ' . esc_html( (string) $q['valid_until'] ) : '' ) . '</p>';
			if ( 'draft' === $q['status'] ) $body .= WB_Quote_Editor::draft( $q, $lines );   // 1.5.0: lines edited in place, products found as you type
			else {
				$body .= WB_Render::render_table( $lines, [ 'description', 'qty', [ 'key' => 'unit_price', 'type' => 'money' ], 'price_source', [ 'key' => 'floor_price', 'type' => 'money' ], 'below_floor', [ 'key' => 'out_of_date', 'label' => 'Price out of date', 'type' => 'chip' ], [ 'key' => 'line_total', 'type' => 'money' ], [ 'key' => '_why', 'label' => 'Why it needs approval', 'render' => fn( $v, $l ) => esc_html( WB_Pricing::explain( $l ) ) ] ],
				[ 'cct' => 'wb_quote_lines', 'actions' => 'draft' === $q['status'] ? [ 'line_approval', 'line_remove' ] : [] ] );
			}
			$h .= self::fold( 'Quote ' . $q['quote_number'], $body, true );
		}
		$h   .= WB_List::render( 'wb_quotes', [ [ 'key' => 'quote_number', 'render' => fn( $v, $r ) => '<a href="' . esc_url( add_query_arg( 'quote', (int) $r['_ID'] ) ) . '">' . esc_html( '' !== (string) $v ? (string) $v : 'Draft #' . (int) $r['_ID'] ) . '</a>' ], [ 'key' => 'customer_id', 'label' => 'Customer', 'render' => fn( $v ) => self::customer_link( (int) $v ) ], 'status', 'pricing_check_status', 'valid_until', [ 'key' => 'total', 'type' => 'money' ] ],
			[ 'cct' => 'wb_quotes', 'actions' => [ 'send_quote', 'pdf_quote', 'quote_send', 'quote_link', 'quote_accept', 'quote_decline' ], 'empty' => 'No quotes yet.', 'what' => 'quotes', 'placeholder' => 'Quote number or customer',
				'search' => [ 'quote_number' ], 'search_in' => [ 'customer_id' => [ 'wb_customers', 'name' ] ], 'statuses' => [ 'draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired' ] ] );
		$for = absint( $_GET['customer'] ?? 0 );
		$f   = WB_Quote_Editor::new_form( $for );
		return $h . self::fold( 'New quote', $f, $for > 0, 'wb-add' ) . WB_Quick_Sale::fold();   // 1.6.0: paid and taken at the counter
	}

	public static function orders( $atts = [] ): string {
		if ( $g = self::gate( 'wb_manage_orders' ) ) return $g;
		$h   = WB_RowActions::notice();
		$oid = absint( $_GET['order'] ?? 0 );
		if ( $oid && ( $o = WB_CCT::get( 'wb_orders', $oid ) ) ) $h .= self::order_detail( $o );
		return $h . WB_List::render( 'wb_orders', [ [ 'key' => 'order_number', 'render' => fn( $v, $r ) => '<a href="' . esc_url( add_query_arg( 'order', (int) $r['_ID'] ) ) . '">' . esc_html( (string) $v ) . '</a>' ], [ 'key' => 'customer_id', 'label' => 'Customer', 'render' => fn( $v ) => self::customer_link( (int) $v ) ], 'status', 'fulfilment', 'required_by', [ 'key' => 'total', 'type' => 'money' ] ],
			[ 'cct' => 'wb_orders', 'actions' => [ 'order_open', 'order_pick', 'order_invoice', 'order_release', 'order_ship_all', 'order_close', 'order_cancel' ], 'empty' => 'No orders yet. Orders appear when a quote is accepted.', 'what' => 'orders',
				'placeholder' => 'Order number or customer', 'search' => [ 'order_number' ], 'search_in' => [ 'customer_id' => [ 'wb_customers', 'name' ] ],
				'statuses' => [ 'awaiting_payment' => 'Awaiting payment', 'ready' => 'Ready', 'part_delivered' => 'Part delivered', 'delivered' => 'Delivered', 'closed' => 'Closed', 'cancelled' => 'Cancelled' ] ] );
	}

	private static function order_detail( array $o ): string {
		[ $ok, $why ] = WB_Orders::may_release( (int) $o['_ID'] );
		$inv   = WB_Invoices::for_order( (int) $o['_ID'] );
		$body  = '<p>' . esc_html( self::customer_name( $o['customer_id'] ) ) . ' · ' . WB_Render::chip( $o['status'] ) . ' · invoice ' . esc_html( $inv ? $inv['invoice_number'] . ' (' . $inv['status'] . ')' : 'not yet' ) . '</p>';
		$body .= wb_notice( $ok ? 'ok' : 'warn', esc_html( 'Release: ' . WB_Orders::gate_text( $why ) ) );
		$lines = WB_CCT::find( 'wb_order_lines', [ 'order_id' => (int) $o['_ID'] ], [ 'order' => 'ASC' ] );
		$body .= WB_Render::render_table( $lines, [ 'description', 'qty_ordered', 'qty_reserved', 'qty_delivered', 'qty_backordered', [ 'key' => 'unit_price', 'type' => 'money' ], 'price_source', [ 'key' => 'line_total', 'type' => 'money' ] ] );
		if ( current_user_can( 'wb_issue_delivery_notes' ) && in_array( $o['status'], [ 'ready', 'part_delivered' ], true ) ) {
			$f = WB_Render::form_open( 'dn_issue' ) . '<input type="hidden" name="order_id" value="' . (int) $o['_ID'] . '">';
			foreach ( $lines as $l ) {
				$left = (float) $l['qty_ordered'] - (float) $l['qty_delivered'];
				if ( $left <= 0 ) continue;
				$f .= WB_Render::field( 'qty[' . (int) $l['_ID'] . ']', $l['description'] . ' (up to ' . $left . ')', 'number', $left );
				$p  = WB_CCT::get( 'wb_products', (int) $l['product_id'] );
				if ( $p && 'yes' === $p['batch_tracked'] ) $f .= WB_Render::field( 'batch[' . (int) $l['_ID'] . ']', 'Batch', 'select', '', [ 'options' => WB_Render::options( 'wb_batches', 'batch_no', [ 'product_id' => (int) $p['_ID'] ] ) ] );
			}
			$f .= WB_Render::field( 'type', 'How it leaves', 'select', $o['fulfilment'], [ 'options' => [ 'collection' => 'Collected by the customer', 'delivery' => 'We deliver' ] ] ) . WB_Render::field( 'vehicle', 'Vehicle or courier' );
			$body .= $f . WB_Render::form_close( 'Issue the note', 'Issue this note? The stock leaves the books now.' );
		}
		$dns   = WB_CCT::find( 'wb_delivery_notes', [ 'order_id' => (int) $o['_ID'] ] );
		$body .= WB_Render::render_table( $dns, [ 'dn_number', 'type', 'issued_at', 'status', 'collected_by_name' ], [ 'cct' => 'wb_delivery_notes', 'actions' => [ 'dn_sign', 'pdf_dn', 'dn_signature' ] ] );
		return self::fold( 'Order ' . $o['order_number'], $body, true );
	}

	public static function invoices( $atts = [] ): string {
		if ( $g = self::gate( 'wb_issue_invoices' ) ) return $g;
		$h = WB_RowActions::notice() . WB_Statements::chase_fold();   // 1.6.0: ageing and who to chase
		$credits = WB_CCT::find( 'wb_credit_notes', [ 'status' => 'requested' ] );
		if ( $credits ) {
			$h .= self::fold( 'Credit notes waiting for approval', WB_Render::render_table( $credits, [ [ 'key' => 'invoice_id', 'label' => 'Invoice', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_invoices', (int) $v )['invoice_number'] ?? '' ) ) ], 'reason', [ 'key' => 'total', 'type' => 'money' ], [ 'key' => 'requested_by_staff_id', 'label' => 'Asked by', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_staff', (int) $v )['first_name'] ?? '' ) ) ] ],
				[ 'cct' => 'wb_credit_notes', 'actions' => [ 'pdf_credit', 'credit_approve', 'credit_decline' ] ] ), true );
		}
		$h   .= WB_List::render( 'wb_invoices', [ 'invoice_number', [ 'key' => 'customer_id', 'label' => 'Customer', 'render' => fn( $v ) => self::customer_link( (int) $v ) ], 'issued_at', 'due_at', [ 'key' => 'total', 'type' => 'money' ], [ 'key' => 'amount_paid', 'type' => 'money' ], [ 'key' => 'amount_credited', 'type' => 'money' ], 'status' ],
			[ 'cct' => 'wb_invoices', 'actions' => [ 'send_invoice', 'pdf_invoice' ], 'empty' => 'No invoices yet.', 'what' => 'invoices', 'placeholder' => 'Invoice number or customer', 'search' => [ 'invoice_number' ], 'search_in' => [ 'customer_id' => [ 'wb_customers', 'name' ] ] ] );
		if ( current_user_can( 'wb_issue_credit_notes' ) ) {
			$open = [ '' => '— choose —' ];
			foreach ( WB_CCT::find( 'wb_invoices', [ 'status NOT IN' => [ 'void', 'credited' ] ], [ 'limit' => 1000 ] ) as $i ) $open[ (int) $i['_ID'] ] = $i['invoice_number'] . ' · ' . self::customer_name( $i['customer_id'] );
			$f = WB_Render::form_open( 'credit_request' ) . WB_Render::field( 'invoice_id', 'Invoice', 'select', '', [ 'options' => $open, 'required' => true ] )
				. WB_Render::field( 'reason', 'Why', 'select', 'return', [ 'options' => [ 'return' => 'Goods returned', 'pricing' => 'Price correction', 'damage' => 'Damaged goods', 'goodwill' => 'Goodwill' ] ] )
				. WB_Render::field( 'lines', 'Products credited: code, quantity, price each', 'textarea', '', [ 'rows' => 3, 'note' => 'Or leave empty and give one amount below.' ] )
				. WB_Render::field( 'amount', 'Amount (before VAT)', 'number' ) . WB_Render::field( 'description', 'Description' )
				. WB_Render::field( 'return_stock', 'Goods are back on the shelf?', 'select', 'no', [ 'options' => [ 'no' => 'No', 'yes' => 'Yes — put them back into stock on approval' ] ] )
				. WB_Render::form_close( 'Ask for the credit note' );
			$h .= self::fold( 'Ask for a credit note', $f, false, 'wb-add' );
		}
		return $h;
	}

	public static function payments( $atts = [] ): string {
		if ( $g = self::gate( 'wb_match_payments' ) ) return $g;
		$h = WB_RowActions::notice();
		if ( current_user_can( 'wb_import_bank' ) ) {
			$st = get_transient( self::bank_state_key() );
			if ( is_array( $st ) ) {
				$h .= self::fold( 'Import a bank statement', self::bank_steps( $st ), true, 'wb-add' );
			} else {
				$banks = [ '' => '— a new layout: I will map the columns —' ];
				foreach ( WB_Payments::profiles() as $k => $m ) if ( isset( $m['columns'] ) ) $banks[ $k ] = (string) ( $m['label'] ?? $k );
				foreach ( WB_Payments::default_mappings() as $k => $m ) if ( ! isset( $banks[ $k ] ) ) $banks[ $k ] = $m['label'] . ' (standard columns)';
				$f  = WB_Render::form_open( 'bank_upload', true ) . WB_Render::field( 'profile', 'Layout', 'select', '', [ 'options' => $banks, 'note' => 'A full statement or a filtered spreadsheet saved as CSV both work.' ] )
					. '<label class="wb-field"><span>Statement (CSV)</span><input type="file" name="statement" accept=".csv,.txt,.tsv,text/csv" required></label>' . WB_Render::form_close( 'Upload' );
				$h .= self::fold( 'Import a bank statement', $f, true, 'wb-add' );
			}
		}
		$rows = WB_CCT::find( 'wb_payments', [ 'match_status' => [ 'unmatched', 'suggested', 'unallocated' ] ], [ 'limit' => 500 ] );
		$h   .= '<h3>To match</h3>' . WB_Render::render_table( $rows, [ 'received_at', [ 'key' => 'amount', 'type' => 'money' ], 'bank_reference', 'bank_description', 'match_status',
			[ 'key' => 'suggested_invoice_id', 'label' => 'Suggested invoice', 'render' => fn( $v ) => esc_html( (string) ( $v ? ( WB_CCT::get( 'wb_invoices', (int) $v )['invoice_number'] ?? '' ) : '' ) ) ] ],
			[ 'cct' => 'wb_payments', 'actions' => [ 'payment_confirm' ], 'empty' => 'Everything is matched.' ] );
		$pays = [ '' => '— choose —' ];
		foreach ( $rows as $p ) $pays[ (int) $p['_ID'] ] = $p['received_at'] . ' · ' . WB_Render::money( $p['amount'] ) . ' · ' . $p['bank_reference'];
		$invs = [ '' => '— choose —' ];
		foreach ( WB_CCT::find( 'wb_invoices', [ 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 1000 ] ) as $i ) $invs[ (int) $i['_ID'] ] = $i['invoice_number'] . ' · ' . self::customer_name( $i['customer_id'] ) . ' · owes ' . WB_Render::money( WB_Invoices::outstanding( $i ) );
		$h .= self::fold( 'Match a payment by hand', WB_Render::form_open( 'manual_match' ) . WB_Render::field( 'payment_id', 'Payment', 'select', '', [ 'options' => $pays ] ) . WB_Render::field( 'invoice_id', 'Invoice', 'select', '', [ 'options' => $invs ] ) . WB_Render::field( 'note', 'How do you know?', 'text', '', [ 'required' => true, 'note' => 'Matches made by hand are listed on the monthly Integrity report.' ] ) . WB_Render::form_close( 'Match' ) );
		$h .= self::fold( 'Record cash or card taken at the counter', WB_Render::form_open( 'receipt' ) . WB_Render::field( 'invoice_id', 'Invoice', 'select', '', [ 'options' => $invs ] ) . WB_Render::field( 'amount', 'Amount', 'number' ) . WB_Render::field( 'method', 'How', 'select', 'cash', [ 'options' => [ 'cash' => 'Cash', 'card' => 'Card', 'eft' => 'EFT (proof seen)' ] ] ) . WB_Render::field( 'reference', 'Receipt or slip number', 'text', '', [ 'required' => true ] ) . WB_Render::form_close( 'Record payment' ) );
		$h .= self::fold( 'Imports', WB_Render::render_table( WB_Payments::batches(), [ 'imported_at', [ 'key' => 'bank', 'label' => 'Layout' ], 'file_name', [ 'key' => 'credits', 'label' => 'Money in' ], [ 'key' => 'debits', 'label' => 'Money out (ignored)' ], 'matched', 'suggested', 'unmatched', [ 'key' => 'duplicates', 'label' => 'Already imported' ] ] ) );
		return $h;
	}

	public static function deliveries( $atts = [] ): string {
		if ( $g = self::gate( 'wb_issue_delivery_notes' ) ) return $g;
		$h   = WB_RowActions::notice() . WB_Floor::sign_panel();   // 1.7.0: sign with a finger
		$oid = absint( $_GET['order'] ?? 0 );
		if ( $oid && ( $o = WB_CCT::get( 'wb_orders', $oid ) ) ) $h .= self::order_detail( $o );
		$ready = WB_CCT::find( 'wb_orders', [ 'status' => [ 'ready', 'part_delivered' ] ], [ 'order' => 'ASC' ] );
		$h    .= '<h3>Ready to go out</h3>' . WB_Render::render_table( $ready, [ 'order_number', [ 'key' => 'customer_id', 'render' => fn( $v ) => esc_html( self::customer_name( $v ) ) ], 'status', 'fulfilment', 'required_by' ], [ 'cct' => 'wb_orders', 'actions' => [ 'order_pick', 'order_open', 'order_ship_all', 'order_close' ], 'empty' => 'Nothing released yet.' ] );
		return $h . '<h3>Notes</h3>' . WB_List::render( 'wb_delivery_notes', [ 'dn_number', [ 'key' => 'order_id', 'label' => 'Order', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_orders', (int) $v )['order_number'] ?? '' ) ) ], 'type', 'issued_at', 'status', 'collected_by_name' ], [ 'cct' => 'wb_delivery_notes', 'actions' => [ 'dn_sign', 'pdf_dn', 'dn_signature' ], 'empty' => 'No notes yet.', 'what' => 'notes', 'placeholder' => 'Note number or who signed', 'search' => [ 'dn_number', 'collected_by_name' ] ] );
	}

	public static function stock( $atts = [] ): string {
		if ( $g = self::gate( 'wb_view_stock' ) ) return $g;
		$h = WB_RowActions::notice();
		if ( current_user_can( 'wb_approve_adjustments' ) && ( $req = WB_Stock::pending_requests() ) ) {
			$me   = WB_Staff::current_staff_id();
			$body = WB_Render::render_table( $req, [ [ 'key' => 'product_id', 'render' => fn( $v ) => esc_html( self::product_name( $v ) ) ], 'type', 'qty', 'reason', [ 'key' => 'requested_by_staff_id', 'label' => 'Asked by', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_staff', (int) $v )['first_name'] ?? '' ) ) ], 'requested_at' ],
				[ 'action_html' => function ( $r ) use ( $me ) {
					if ( (int) $r['requested_by_staff_id'] === $me ) return '';
					$m = '';
					foreach ( [ 'approve' => [ 'approve', 'Approve' ], 'decline' => [ 'withdraw', 'Decline' ] ] as $d => $ui ) {
						$m .= WB_Render::form_open( 'stock_decide' ) . '<input type="hidden" name="request_id" value="' . (int) $r['id'] . '"><input type="hidden" name="decision" value="' . $d . '">' . WB_RowActions::menuitem( $ui[0], $ui[1], [ 'submit' => true ] ) . '</form>';
					}
					return $m;
				} ] );
			$h .= self::fold( 'Stock changes waiting for your approval', $body, true );
		}
		$levels = [];
		foreach ( WB_CCT::find( 'wb_products', [ 'status' => 'active' ], [ 'orderby' => 'sku', 'order' => 'ASC', 'limit' => 2000 ] ) as $p ) {
			$on = WB_Stock::on_hand( (int) $p['_ID'] );
			$rs = WB_Stock::reserved( (int) $p['_ID'] );
			$levels[] = [ 'sku' => $p['sku'], 'name' => $p['name'], 'on_hand' => $on, 'reserved' => $rs, 'available' => round( $on - $rs, 3 ), 'reorder_point' => $p['reorder_point'] ];
		}
		$h .= '<p class="wb-muted">Stock is never typed in: every figure is the sum of recorded movements.</p>' . WB_Render::render_table( $levels, [ 'sku', 'name', 'on_hand', 'reserved', 'available', 'reorder_point' ], [ 'empty' => 'No products yet.' ] );
		if ( current_user_can( 'wb_adjust_stock' ) ) {
			$f = WB_Render::form_open( 'stock_request' ) . WB_Render::field( 'product_id', 'Product', 'select', absint( $_GET['product'] ?? 0 ) ?: '', [ 'options' => WB_Render::options( 'wb_products', 'name' ) ] )
				. WB_Render::field( 'type', 'What happened', 'select', 'adjustment', [ 'options' => [ 'adjustment' => 'Correction (+ or −)', 'write_off' => 'Write-off (damaged, expired, lost)' ] ] )
				. WB_Render::field( 'qty', 'Quantity', 'number', '', [ 'note' => 'For a correction, use a minus sign for less stock.' ] ) . WB_Render::field( 'reason', 'Why', 'text', '', [ 'required' => true ] ) . WB_Render::form_close( 'Ask for approval' );
			$h .= self::fold( 'Correct stock or write it off', $f, false, 'wb-add' );
		}
		if ( current_user_can( 'wb_run_stocktake' ) ) {
			$sts  = WB_CCT::find( 'wb_stocktakes', [], [ 'limit' => 50 ] );
			$body = WB_Render::render_table( $sts, [ 'started_at', [ 'key' => 'counted_by_staff_id', 'label' => 'Counted by', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_staff', (int) $v )['first_name'] ?? '' ) ) ], [ 'key' => 'checked_by_staff_id', 'label' => 'Checked by', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_staff', (int) $v )['first_name'] ?? '' ) ) ], 'status', [ 'key' => 'variance_total', 'type' => 'money' ] ], [ 'cct' => 'wb_stocktakes', 'actions' => [ 'stocktake_submit', 'stocktake_post' ] ] );
			$mine = array_filter( $sts, fn( $s ) => 'counting' === $s['status'] && (int) $s['counted_by_staff_id'] === WB_Staff::current_staff_id() );
			if ( $mine ) {
				$st    = reset( $mine );
				$body .= WB_Render::form_open( 'stocktake_count' ) . '<input type="hidden" name="stocktake_id" value="' . (int) $st['_ID'] . '">' . WB_Render::field( 'product_id', 'Product', 'select', '', [ 'options' => WB_Render::options( 'wb_products', 'name' ) ] ) . WB_Render::field( 'counted', 'Counted on the shelf', 'number' ) . WB_Render::form_close( 'Save count' );
			} else {
				$body .= WB_Render::form_open( 'stocktake_start' ) . WB_Render::field( 'location', 'Where (optional)' ) . WB_Render::form_close( 'Start a stocktake' );
			}
			$h .= self::fold( 'Stocktakes (two people: one counts, another checks)', $body );
		}
		return $h;
	}

	public static function purchasing( $atts = [] ): string {
		if ( $g = self::gate( 'wb_manage_purchasing' ) ) return $g;
		$h      = WB_RowActions::notice();
		$alerts = WB_Stock::open_alerts();
		$h     .= '<h3>To reorder</h3>' . WB_Render::render_table( $alerts, [ [ 'key' => 'product_id', 'render' => fn( $v ) => esc_html( self::product_name( $v ) ) ], 'qty_on_hand', 'qty_reserved', 'qty_on_order', 'suggested_qty', 'raised_at' ], [ 'empty' => 'Nothing needs reordering.' ] );
		$sup    = WB_CCT::find( 'wb_suppliers', [], [ 'orderby' => 'name', 'order' => 'ASC', 'limit' => 500 ] );
		$h     .= self::fold( 'Suppliers', WB_Render::render_table( $sup, [ 'name', 'contact_name', 'email', 'phone', 'lead_time_days', 'payment_terms_days' ], [ 'cct' => 'wb_suppliers', 'actions' => [ 'edit_wb_suppliers', 'archive_wb_suppliers' ], 'empty' => 'No suppliers yet.', 'empty_note' => 'A purchase order needs a supplier to go to.' ] ) . WB_Records::form( 'wb_suppliers', self::editing( 'wb_suppliers' ) ), ! $sup || (bool) self::editing( 'wb_suppliers' ), 'wb-add-suppliers', 'sibling', count( $sup ) . ( 1 === count( $sup ) ? ' supplier' : ' suppliers' ) );
		$h     .= WB_Import::fold( 'wb_suppliers' );
		$pos    = WB_CCT::find( 'wb_purchase_orders', [ 'status' => [ 'draft', 'sent', 'part_received' ] ], [ 'limit' => 500 ] );   // open ones, for the receiving form
		$h     .= '<h3>Purchase orders</h3>' . WB_List::render( 'wb_purchase_orders', [ 'po_number', [ 'key' => 'supplier_id', 'label' => 'Supplier', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_suppliers', (int) $v )['name'] ?? '' ) ) ], 'status', 'expected_at', [ 'key' => 'total', 'type' => 'money' ] ], [ 'cct' => 'wb_purchase_orders', 'actions' => [ 'pdf_po', 'po_send', 'po_cancel' ], 'empty' => 'No purchase orders yet.', 'what' => 'purchase orders', 'placeholder' => 'Order number or supplier', 'search' => [ 'po_number' ], 'search_in' => [ 'supplier_id' => [ 'wb_suppliers', 'name' ] ] ] );
		$h     .= self::fold( 'New purchase order', WB_Render::form_open( 'po_new' ) . WB_Render::field( 'supplier_id', 'Supplier', 'select', '', [ 'options' => WB_Render::options( 'wb_suppliers', 'name' ) ] ) . WB_Quote_Editor::product_field( 'po_pick', 'Find a product to add', 0, 'po', 'wb-po-pick', 'wb-lines' ) . WB_Render::field( 'lines', 'Lines: product code, quantity, cost each (optional)', 'textarea', '', [ 'rows' => 4, 'placeholder' => "ADH-EP200, 120\nFST-HN16, 40, 236.00" ] ) . WB_Render::field( 'expected_at', 'Expected', 'date' ) . WB_Render::form_close( 'Create draft' ), false, 'wb-add' );
		$open = [ '' => '— choose —' ];
		foreach ( $pos as $po ) {
			if ( ! in_array( $po['status'], [ 'sent', 'part_received' ], true ) ) continue;
			foreach ( WB_CCT::find( 'wb_po_lines', [ 'po_id' => (int) $po['_ID'] ] ) as $l ) {
				$left = (float) $l['qty_ordered'] - (float) $l['qty_received'];
				if ( $left > 0 ) $open[ (int) $l['_ID'] ] = $po['po_number'] . ' · ' . self::product_name( $l['product_id'] ) . ' · ' . $left . ' to come';
			}
		}
		$h .= self::fold( 'Receive stock', WB_Render::form_open( 'po_receive' ) . WB_Render::field( 'po_line_id', 'Line', 'select', '', [ 'options' => $open ] ) . WB_Render::field( 'qty', 'Quantity received', 'number' ) . WB_Render::field( 'batch_no', 'Batch number (if the product is tracked by batch)', 'text', '', [ 'placeholder' => 'As printed on the goods' ] ) . WB_Render::field( 'expiry_at', 'Batch expires', 'date' ) . WB_Render::field( 'location', 'Put away at' ) . WB_Render::form_close( 'Receive' ) );
		return $h;
	}

	/** The product documents (what a person files); everything else in wb_documents is a document the system issued. */
	const PRODUCT_DOC_TYPES = [ 'datasheet', 'coa', 'msds', 'certificate' ];

	/** The ⋯ menu row for a document: "Open" for a PDF or an image (the browser shows it), "Download" for anything else. */
	public static function doc_open_item( array $d ): string {
		$ext = strtolower( pathinfo( (string) ( $d['storage_key'] ?? '' ), PATHINFO_EXTENSION ) );
		$inline = in_array( $ext, [ 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp' ], true );
		return WB_RowActions::menuitem( $inline ? 'open' : 'download', $inline ? 'Open' : 'Download', [ 'href' => WB_Documents::open_url( (int) $d['_ID'] ) ] );
	}

	/**
	 * Documents (1.3.2, Zina: "clicking Datasheets took me to a documents list; I would like to view
	 * the documents we have uploaded"). Three kinds, kept apart: the product documents people file
	 * (datasheets, certificates, safety sheets) lead; the documents the system issued (quote, invoice,
	 * credit note and delivery note PDFs, signed copies, contracts) are a sibling fold; staff documents
	 * another. ?product=ID narrows the screen to one product, with its name on top, the datasheet link
	 * and the filing form already pointed at it. A PDF opens in the browser; the rest download.
	 */
	public static function documents( $atts = [] ): string {
		if ( $g = self::gate( 'wb_view_documents' ) ) return $g;
		$h       = WB_RowActions::notice();
		$pid     = absint( $_GET['product'] ?? 0 );
		$product = $pid ? WB_CCT::get( 'wb_products', $pid ) : null;
		$pid     = $product ? $pid : 0;
		$rows    = array_values( array_filter( WB_CCT::find( 'wb_documents', $pid ? [ 'product_id' => $pid ] : [], [ 'limit' => 500 ] ), fn( $d ) => 'staff_doc' !== $d['type'] || current_user_can( 'wb_view_staff' ) ) );
		$mine    = array_values( array_filter( $rows, fn( $d ) => in_array( (string) $d['type'], self::PRODUCT_DOC_TYPES, true ) ) );
		$issued  = array_values( array_filter( $rows, fn( $d ) => ! in_array( (string) $d['type'], self::PRODUCT_DOC_TYPES, true ) && 'staff_doc' !== $d['type'] ) );
		$staff   = array_values( array_filter( $rows, fn( $d ) => 'staff_doc' === $d['type'] ) );
		$opts    = [ 'action_html' => [ __CLASS__, 'doc_open_item' ] ];
		$cols    = [ 'title', 'type', 'version', [ 'key' => 'product_id', 'render' => fn( $v ) => esc_html( self::product_name( $v ) ) ], [ 'key' => 'customer_id', 'render' => fn( $v ) => esc_html( self::customer_name( $v ) ) ], 'issued_at', 'expires_at', [ 'key' => 'visible', 'label' => 'Customers see it' ] ];
		if ( $pid ) {
			$cols = array_values( array_filter( $cols, fn( $c ) => ! is_array( $c ) || 'product_id' !== $c['key'] ) );
			$h   .= '<p class="wb-doc-scope">Documents filed against <strong>' . esc_html( (string) $product['name'] ) . '</strong> (' . esc_html( (string) $product['sku'] ) . '). <a href="' . esc_url( WB_Workspace::url( 'documents' ) ) . '">All documents</a> · <a href="' . esc_url( WB_Workspace::url( 'products' ) ) . '">Back to products</a></p>';
		}
		// 1.4.0: the datasheet rows — where each product's sheet comes from; typed here, uploaded by CSV
		if ( WB_CCT::table( 'wb_datasheets' ) ) {
			$ds   = WB_CCT::find( 'wb_datasheets', $pid ? [ 'product_id' => $pid ] : [], [ 'limit' => 2000 ] );
			$body = '<p class="wb-muted">One row per product says where its datasheet comes from: the words on the row (with the product\'s specification) rendered to PDF whenever it is asked for, the supplier\'s uploaded file, or a link to the online datasheet. Upload a CSV to add or change a whole range at once.</p>'
				. WB_Render::render_table( $ds, [ [ 'key' => 'product_id', 'render' => fn( $v ) => esc_html( self::product_name( $v ) ) ], [ 'key' => 'source', 'label' => 'Comes from', 'render' => fn( $v ) => esc_html( [ 'data' => 'The data', 'upload' => 'Uploaded file', 'link' => 'Online link' ][ $v ] ?? (string) $v ) ], 'headline', 'revision', 'revised_at' ],
					[ 'cct' => 'wb_datasheets', 'actions' => [ 'datasheet_row_pdf', 'edit_wb_datasheets', 'archive_wb_datasheets' ], 'empty' => $pid ? 'No datasheet row for this product yet.' : 'No datasheet rows yet.', 'empty_note' => 'Add one below, or download the template under "Upload datasheets from a file" and fill it for a whole range.' ] )
				. WB_Records::fold( 'wb_datasheets', (bool) $pid && ! $ds ) . WB_Import::fold( 'wb_datasheets' );
			$h .= self::fold( $pid ? 'Datasheet for this product' : 'Datasheets', $body, true, 'wb-datasheets', 'lead', count( $ds ) . ' row' . ( 1 === count( $ds ) ? '' : 's' ) );
		}
		$h .= self::fold( $pid ? 'Uploaded files for this product' : 'Uploaded product documents',
			WB_Render::render_table( $mine, $cols, $opts + [ 'empty' => $pid ? 'No datasheet or document filed for this product yet.' : 'No product documents filed yet.', 'empty_note' => 'Use "File a document" below: a datasheet, certificate of analysis, safety sheet or certificate, against the product.' ] ),
			false, 'wb-doc-products', 'sibling', count( $mine ) . ' on file' );
		if ( ! $pid || $issued ) {
			$h .= self::fold( 'Documents the system issued', '<p class="wb-muted">Quotes, invoices, credit notes and delivery notes as PDFs, signed copies and contracts. Each is made once, when the document is issued, and never changes.</p>'
				. WB_Render::render_table( $issued, $cols, $opts + [ 'empty' => 'Nothing issued yet. A quote\'s PDF appears here the moment it is marked sent.' ] ), false, 'wb-doc-issued', 'sibling', count( $issued ) . ' on file' );
		}
		if ( $staff ) $h .= self::fold( 'Staff documents', WB_Render::render_table( $staff, $cols, $opts ), false, 'wb-doc-staff', 'sibling', count( $staff ) . ' on file' );
		$h .= self::fold( 'Datasheet link for a customer', WB_Render::form_open( 'datasheet_link' ) . WB_Render::field( 'product_id', 'Product', 'select', $pid ?: '', [ 'options' => WB_Render::options( 'wb_products', 'name' ) ] ) . WB_Render::field( 'customer_id', 'For customer (optional)', 'select', '', [ 'options' => WB_Render::options( 'wb_customers', 'name' ) ] ) . WB_Render::form_close( 'Make a 7-day link' ), false, '', 'sibling', 'A 7-day link to the current datasheet' );
		if ( current_user_can( 'wb_manage_documents' ) ) {
			$types = array_combine( WB_Documents::TYPES, array_map( [ 'WB_Render', 'words' ], WB_Documents::TYPES ) );
			$prev  = [ '' => '— a new document —' ];
			foreach ( $mine as $d ) $prev[ (int) $d['_ID'] ] = $d['title'] . ' (v' . $d['version'] . ')';
			$h .= self::fold( 'File a document', WB_Render::form_open( 'doc_upload', true ) . '<label class="wb-field"><span>File</span><input type="file" name="file" required></label>'
				. WB_Render::field( 'type', 'Kind', 'select', 'datasheet', [ 'options' => $types ] ) . WB_Render::field( 'title', 'Title' )
				. WB_Render::field( 'product_id', 'Product', 'select', $pid ?: '', [ 'options' => WB_Render::options( 'wb_products', 'name' ) ] ) . WB_Render::field( 'customer_id', 'Customer', 'select', '', [ 'options' => WB_Render::options( 'wb_customers', 'name' ) ] )
				. WB_Render::field( 'supersedes_doc_id', 'This replaces', 'select', '', [ 'options' => $prev, 'note' => 'The old version stays on file.' ] ) . WB_Render::field( 'expires_at', 'Expires', 'date' )
				. WB_Render::field( 'visible', 'Customers may see it?', 'select', 'no', [ 'options' => [ 'no' => 'No', 'yes' => 'Yes' ] ] ) . WB_Render::form_close( 'File it' ), false, 'wb-add-file' );
		}
		return $h;
	}

	public static function marketing( $atts = [] ): string {
		if ( $g = self::gate( 'wb_view_marketing' ) ) return $g;
		$h      = WB_RowActions::notice() . '<div class="wb-stats">';
		foreach ( [ 'lead', 'quoted', 'first_order', 'repeat', 'at_risk', 'lapsed' ] as $s ) $h .= WB_Render::stat( WB_Render::words( $s ), WB_CCT::count( 'wb_customers', [ 'journey_stage' => $s ] ) );
		$h     .= '</div><h3>Likely to order in the next two weeks</h3>';
		$due    = WB_Demand::due_soon();
		$h     .= WB_Render::render_table( $due, [ [ 'key' => 'customer_id', 'render' => fn( $v ) => esc_html( self::customer_name( $v ) ) ], [ 'key' => 'product_id', 'render' => fn( $v ) => esc_html( self::product_name( $v ) ) ], 'predicted_next_at', 'avg_qty', 'confidence' ], [ 'empty' => 'No predictions yet (they need at least two orders per customer and product).' ] );
		$h     .= self::fold( 'Recent contact', WB_List::render( 'wb_touchpoints', [ [ 'key' => 'customer_id', 'label' => 'Customer', 'render' => fn( $v ) => self::customer_link( (int) $v ) ], 'type', 'happened_at', 'summary', 'next_action', 'next_action_date' ], [ 'empty' => 'Nothing recorded yet.', 'what' => 'contact', 'placeholder' => 'Words in the note, or a customer',
			'search' => [ 'summary', 'next_action' ], 'search_in' => [ 'customer_id' => [ 'wb_customers', 'name' ] ], 'status' => 'type', 'statuses' => [ 'call' => 'Call', 'email' => 'Email', 'visit' => 'Visit', 'complaint' => 'Complaint', 'note' => 'Note', 'portal_login' => 'Portal sign-in', 'datasheet_sent' => 'Datasheet sent' ], 'orderby' => 'happened_at' ] ), true );
		if ( current_user_can( 'wb_manage_marketing' ) ) {
			$h .= self::fold( 'Record a contact', WB_Render::form_open( 'touchpoint' ) . WB_Render::field( 'customer_id', 'Customer', 'select', '', [ 'options' => WB_Render::options( 'wb_customers', 'name' ) ] )
				. WB_Render::field( 'type', 'Kind', 'select', 'call', [ 'options' => [ 'call' => 'Call', 'email' => 'Email', 'visit' => 'Visit', 'complaint' => 'Complaint', 'note' => 'Note' ] ] )
				. WB_Render::field( 'summary', 'What happened', 'textarea', '', [ 'rows' => 3 ] ) . WB_Render::field( 'next_action', 'Next step' ) . WB_Render::field( 'next_action_date', 'By', 'date' ) . WB_Render::form_close( 'Save' ), false, 'wb-add' );
		}
		return $h;
	}

	public static function cashflow( $atts = [] ): string {
		if ( $g = self::gate( 'wb_view_cashflow' ) ) return $g;
		$rows = WB_Demand::latest_forecast();
		return '<p class="wb-muted">The next 13 weeks, recalculated every night: invoices by due date (moved by each customer\'s own paying habit), likely orders (weighted by how sure we are), supplier orders, wages.</p>'
			. WB_Render::render_table( $rows, [ 'week_start', 'expected_receipts', 'predicted_orders', 'committed_purchases', 'payroll', 'net', 'cumulative' ], [ 'empty' => 'The first forecast appears after tonight\'s run.' ] );
	}

	public static function staff( $atts = [] ): string {
		if ( $g = self::gate( 'wb_access_workspace' ) ) return $g;
		$h  = WB_RowActions::notice();
		$me = WB_Staff::current_staff_id();
		if ( $me ) {
			$mine = WB_CCT::find( 'wb_timesheets', [ 'staff_id' => $me ], [ 'limit' => 31, 'orderby' => 'work_date' ] );
			$body = WB_Render::render_table( $mine, [ 'work_date', 'start', 'end', 'break_minutes', 'hours', 'activity', 'status', 'query_note' ], [ 'cct' => 'wb_timesheets', 'actions' => [ 'ts_submit' ], 'empty' => 'No days yet.' ] );
			$body .= WB_Render::form_open( 'timesheet' ) . WB_Render::field( 'work_date', 'Day', 'date', wb_today() ) . WB_Render::field( 'start', 'Start', 'time', '08:00' ) . WB_Render::field( 'end', 'End', 'time', '17:00' )
				. WB_Render::field( 'break_minutes', 'Break (minutes)', 'number', 60 ) . WB_Render::field( 'activity', 'Mostly', 'select', 'admin', [ 'options' => [ 'sales' => 'Sales', 'warehouse' => 'Warehouse', 'admin' => 'Admin', 'delivery' => 'Delivery' ] ] ) . WB_Render::form_close( 'Save day' );
			$h .= self::fold( 'My timesheet', $body, true, 'wb-add' );

			$types = WB_CCT::find( 'wb_leave_types', [], [ 'order' => 'ASC' ] );
			$bal   = [];
			foreach ( $types as $t ) {
				$b = WB_Staff::balance( $me, (int) $t['_ID'] );
				if ( ! is_wp_error( $b ) ) $bal[] = [ 'name' => $t['name'], 'accrued' => $b['accrued'], 'carried' => $b['carried'], 'taken' => $b['taken'], 'balance' => null === $b['balance'] ? 'no limit' : $b['balance'] ];
			}
			$body  = WB_Render::render_table( $bal, [ 'name', 'accrued', 'carried', 'taken', 'balance' ], [ 'empty' => 'Leave types are not set up yet.' ] );
			$body .= WB_Render::render_table( WB_CCT::find( 'wb_leave', [ 'staff_id' => $me ], [ 'limit' => 50 ] ), [ 'from_date', 'to_date', 'days', 'status' ], [ 'cct' => 'wb_leave', 'actions' => [ 'leave_cancel' ] ] );
			$body .= WB_Render::form_open( 'leave' ) . WB_Render::field( 'leave_type_id', 'Kind', 'select', '', [ 'options' => WB_Render::options( 'wb_leave_types', 'name' ) ] ) . WB_Render::field( 'from_date', 'From', 'date' ) . WB_Render::field( 'to_date', 'To', 'date' ) . WB_Render::field( 'note', 'Note' ) . WB_Render::form_close( 'Ask for leave' );
			$h .= self::fold( 'My leave', $body );
			$h .= '<p class="wb-muted"><a href="' . esc_url( WB_Workspace::url( 'payroll' ) ) . '">My payslips</a></p>';
		} else {
			$h .= wb_notice( 'warn', 'Your login is not linked to a staff record yet, so timesheets and leave are not available.' );
		}
		if ( current_user_can( 'wb_approve_timesheets' ) ) $h .= self::fold( 'Timesheets to approve', WB_Render::render_table( WB_CCT::find( 'wb_timesheets', [ 'status' => 'submitted' ], [ 'limit' => 300 ] ), [ [ 'key' => 'staff_id', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_staff', (int) $v )['first_name'] ?? '' ) ) ], 'work_date', 'hours', 'activity' ], [ 'cct' => 'wb_timesheets', 'actions' => [ 'ts_approve', 'ts_query' ], 'empty' => 'Nothing waiting.' ] ) );
		if ( current_user_can( 'wb_approve_leave' ) ) $h .= self::fold( 'Leave to approve', WB_Render::render_table( WB_CCT::find( 'wb_leave', [ 'status' => 'requested' ], [ 'limit' => 300 ] ), [ [ 'key' => 'staff_id', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_staff', (int) $v )['first_name'] ?? '' ) ) ], 'from_date', 'to_date', 'days', 'note' ], [ 'cct' => 'wb_leave', 'actions' => [ 'leave_approve', 'leave_decline' ], 'empty' => 'Nothing waiting.' ] ) );
		if ( current_user_can( 'wb_view_staff' ) ) {
			$people = WB_CCT::find( 'wb_staff', [], [ 'orderby' => 'last_name', 'order' => 'ASC', 'limit' => 500 ] );
			$h .= self::fold( 'Staff', WB_Render::render_table( $people, [ 'first_name', 'last_name', 'job_title', 'department', 'started_at', 'status' ], [ 'cct' => 'wb_staff', 'actions' => [ 'edit_wb_staff' ], 'empty' => 'No staff files yet.', 'empty_note' => 'Every login that approves, adjusts or counts needs a staff file.' ] ) . WB_Records::form( 'wb_staff', self::editing( 'wb_staff' ) ), (bool) self::editing( 'wb_staff' ), 'wb-add-staff', '', count( $people ) . ( 1 === count( $people ) ? ' person' : ' people' ) );
			$h .= WB_Import::fold( 'wb_staff' );
			if ( current_user_can( 'wb_manage_staff' ) ) {
				$lt = WB_CCT::find( 'wb_leave_types', [], [ 'orderby' => 'name', 'order' => 'ASC', 'limit' => 50 ] );
				$h .= self::fold( 'Leave types', WB_Render::render_table( $lt, [ 'name', 'code', [ 'key' => 'days_per_year', 'label' => 'Days per cycle' ], [ 'key' => 'cycle_months', 'label' => 'Cycle (months)' ], 'accrual', [ 'key' => 'carry_over_max', 'label' => 'Carries over' ] ], [ 'cct' => 'wb_leave_types', 'actions' => [ 'edit_wb_leave_types' ], 'empty' => 'No leave types yet.', 'empty_note' => 'Press the button below for the South African defaults, then edit them to your policy.' ] ) . WB_Records::form( 'wb_leave_types', self::editing( 'wb_leave_types' ) ), (bool) self::editing( 'wb_leave_types' ), 'wb-add-leave_types', 'sibling' );
				$h .= WB_Import::fold( 'wb_leave_types' );
			}
			if ( current_user_can( 'wb_run_reviews' ) ) {
				$kp = WB_CCT::find( 'wb_kpis', [], [ 'orderby' => 'name', 'order' => 'ASC', 'limit' => 100 ] );
				$h .= self::fold( 'KPI definitions', WB_Render::render_table( $kp, [ 'name', 'applies_to', 'measure', 'target', 'unit', 'period' ], [ 'cct' => 'wb_kpis', 'actions' => [ 'edit_wb_kpis' ], 'empty' => 'No KPIs defined yet.', 'empty_note' => 'A KPI measured from the records (quotes sent, win rate, on-time delivery) scores itself every month.' ] ) . WB_Records::form( 'wb_kpis', self::editing( 'wb_kpis' ) ), (bool) self::editing( 'wb_kpis' ), 'wb-add-kpis', 'sibling' );
				$h .= WB_Import::fold( 'wb_kpis' );
			}
			$scores = WB_CCT::find( 'wb_kpi_scores', [ 'period_start' => substr( wb_now(), 0, 7 ) . '-01' ], [ 'limit' => 500 ] );
			$h .= self::fold( 'KPIs this month', WB_Render::render_table( $scores, [ [ 'key' => 'staff_id', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_staff', (int) $v )['first_name'] ?? '' ) ) ], [ 'key' => 'kpi_id', 'label' => 'KPI', 'render' => fn( $v ) => esc_html( (string) ( WB_CCT::get( 'wb_kpis', (int) $v )['name'] ?? '' ) ) ], 'target', 'actual', 'source' ], [ 'empty' => 'Not measured yet.' ] )
				. ( current_user_can( 'wb_run_reviews' ) ? WB_Render::form_open( 'kpis' ) . WB_Render::form_close( 'Measure this month now' ) : '' ) );
		}
		if ( current_user_can( 'wb_run_reviews' ) ) {
			$staff = WB_Render::options( 'wb_staff', 'first_name' );
			$h .= self::fold( 'Reviews', WB_Render::render_table( WB_CCT::find( 'wb_reviews', [], [ 'limit' => 200 ] ), [ [ 'key' => 'staff_id', 'render' => fn( $v ) => esc_html( $staff[ (int) $v ] ?? '' ) ], 'period', 'status', 'rating' ] )
				. WB_Render::form_open( 'review_schedule' ) . WB_Render::field( 'staff_id', 'Person', 'select', '', [ 'options' => $staff ] ) . WB_Render::field( 'reviewer_staff_id', 'Reviewer', 'select', '', [ 'options' => $staff ] ) . WB_Render::field( 'period', 'Period', 'text', current_time( 'Y' ) ) . WB_Render::form_close( 'Schedule review' ) );
		}
		if ( $me ) {
			$open = array_filter( WB_CCT::find( 'wb_reviews', [ 'status NOT IN' => [ 'signed' ] ], [ 'limit' => 100 ] ), fn( $r ) => in_array( $me, [ (int) $r['staff_id'], (int) $r['reviewer_staff_id'] ], true ) );
			if ( $open ) {
				$opts = [];
				foreach ( $open as $r ) $opts[ (int) $r['_ID'] ] = $r['period'] . ' · ' . $r['status'];
				$h .= self::fold( 'My reviews', WB_Render::form_open( 'review_step' ) . WB_Render::field( 'review_id', 'Review', 'select', '', [ 'options' => $opts ] )
					. WB_Render::field( 'to', 'Step', 'select', '', [ 'options' => [ 'self_review' => 'Open the self review (reviewer)', 'manager_review' => 'Hand in my self review', 'discussed' => 'Hand in the manager review (reviewer)', 'sign' => 'Sign' ] ] )
					. WB_Render::field( 'text', 'Review', 'textarea', '', [ 'rows' => 4 ] ) . WB_Render::field( 'rating', 'Rating 1–5 (reviewer)', 'number' ) . WB_Render::field( 'goals', 'Goals (reviewer)', 'textarea', '', [ 'rows' => 2 ] ) . WB_Render::form_close( 'Save step' ) );
			}
		}
		if ( current_user_can( 'wb_manage_staff' ) && ! WB_CCT::count( 'wb_leave_types', [], false ) ) $h .= WB_Render::form_open( 'leave_types' ) . WB_Render::form_close( 'Set up South African leave types' );
		return $h;
	}

	public static function settings( $atts = [] ): string {
		if ( $g = self::gate( 'wb_manage_settings' ) ) return $g;
		$h  = WB_RowActions::notice();
		$pr = WB_Pricing::policy();
		$jr = wp_parse_args( (array) get_option( 'wb_journey_rules', [] ), [ 'at_risk_days' => 60, 'lapsed_days' => 180, 'at_risk_interval_multiple' => 2 ] );
		$cf = wp_parse_args( (array) get_option( 'wb_cashflow', [] ), [ 'payroll_monthly' => 0, 'opening_balance' => 0 ] );
		$px = (array) get_option( 'wb_number_prefixes', WB_Sequences::DEFAULT_PREFIXES );
		$f  = WB_Render::form_open( 'settings' )
			. WB_Render::field( 'wb_vat_rate', 'VAT %', 'number', get_option( 'wb_vat_rate', 15 ) )
			. WB_Render::field( 'wb_invoice_trigger', 'Invoice when', 'select', get_option( 'wb_invoice_trigger', 'acceptance' ), [ 'options' => [ 'acceptance' => 'the quote is accepted', 'dispatch' => 'the goods leave' ], 'note' => 'Cash customers are always invoiced on acceptance so they can pay first.' ] )
			. WB_Render::field( 'wb_default_terms_days', 'Default days to pay', 'number', get_option( 'wb_default_terms_days', 30 ) )
			. WB_Render::field( 'default_min_margin_pct', 'Lowest margin % (when product and category have none)', 'number', $pr['default_min_margin_pct'] )
			. WB_Render::field( 'quote_validity_days', 'Quotes are valid for (days)', 'number', $pr['quote_validity_days'] )
			. WB_Render::field( 'at_risk_days', 'A customer is at risk after (days without an order)', 'number', $jr['at_risk_days'] )
			. WB_Render::field( 'lapsed_days', 'A customer has lapsed after (days)', 'number', $jr['lapsed_days'] )
			. WB_Render::field( 'at_risk_interval_multiple', 'Or when this many of their usual gaps late', 'number', $jr['at_risk_interval_multiple'] )
			. WB_Render::field( 'payroll_monthly', 'Wages per month (for the cashflow forecast)', 'number', $cf['payroll_monthly'], [ 'note' => 'Used only until people have payroll profiles; then the forecast uses payroll.' ] )
			. WB_Render::field( 'opening_balance', 'Cash in the bank now (for the forecast)', 'number', $cf['opening_balance'] );
		foreach ( WB_Sequences::TYPES as $t ) $f .= WB_Render::field( 'prefix_' . $t, 'Number prefix: ' . $t, 'text', $px[ $t ] ?? $t, [ 'note' => 'Next: ' . WB_Sequences::peek( $t ) ] );
		foreach ( WB_Notifications::GROUPS as $g => $label ) $f .= WB_Render::field( 'email_' . $g, 'Email ' . strtolower( $label ) . ' alerts too?', 'select', get_option( 'wb_notify_' . $g . '_email', 0 ) ? 'yes' : 'no', [ 'options' => [ 'no' => 'No — in the app only', 'yes' => 'Yes' ] ] );
		$h .= self::fold( 'Company settings', $f . WB_Render::form_close( 'Save settings' ), true );

		$users = get_users( [ 'role__in' => array_diff( array_keys( WB_Roles::map() ), [ 'wb_customer' ] ), 'number' => 200 ] );
		$body  = '';
		foreach ( $users as $u ) {
			$ticks = (array) get_user_meta( $u->ID, WB_Roles::META, true );
			$body .= WB_Render::form_open( 'dashboards' ) . '<input type="hidden" name="user_id" value="' . (int) $u->ID . '"><fieldset class="wb-ticks"><legend>' . esc_html( $u->display_name ) . '</legend>';
			foreach ( WB_Roles::catalog() as $k => $c ) $body .= '<label><input type="checkbox" name="dash[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $ticks, true ), true, false ) . '> ' . esc_html( $c['label'] ) . '</label>';
			$body .= '</fieldset>' . WB_Render::form_close( 'Save for ' . $u->display_name );
		}
		$h .= self::fold( 'Who can do what', $body ?: '<p class="wb-muted">Give people a role first (Users in WordPress).</p>' );
		$h .= WB_Send::settings_fold();   // 1.5.0: what each "Send by email" says
		$h .= WB_Optin::settings_fold();  // 1.7.2: the people who asked for news
		$h .= self::fold( 'Audit trail', WB_Render::form_open( 'ledger_verify' ) . WB_Render::form_close( 'Check the audit trail now' ) );
		return $h;
	}

	/** The customer portal: own quotes, invoices and datasheets only. */
	public static function portal( $atts = [] ): string {
		return WB_Portal::full( $atts );   // 0.2.0: the full portal (scoped and fail-closed in WB_Portal)
	}
}
