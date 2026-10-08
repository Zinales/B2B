<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Render — the shared screen primitives (convention 17: primitives, never hand markup).
 *
 *  - render_table(): every table. Data columns first, the ⋯ actions menu is the LAST column
 *    (blank header), status columns become chips, money right-aligned, and under 640px each row
 *    becomes a card (every cell carries data-label). Tables never scroll sideways.
 *  - chip(), kebab(), label(), money(), stat(), form helpers.
 *  - [wb_list cct="wb_customers" columns="name,account_status" cap="wb_view_customers"
 *             filter_account_status="open" limit="50" empty="No customers yet."]
 *
 * Labels are plain English and may change freely; stored keys never do (convention 8).
 */
class WB_Render {

	/** Which cap opens which table in [wb_list]. A CCT not listed here cannot be listed. */
	const VIEW_CAPS = [
		'wb_customers' => 'wb_view_customers', 'wb_contacts' => 'wb_view_customers', 'wb_touchpoints' => 'wb_view_marketing',
		'wb_products' => 'wb_view_products', 'wb_product_categories' => 'wb_view_products', 'wb_price_tiers' => 'wb_manage_pricing', 'wb_price_rules' => 'wb_manage_pricing',
		'wb_suppliers' => 'wb_manage_purchasing', 'wb_stock_movements' => 'wb_view_stock', 'wb_batches' => 'wb_view_stock',
		'wb_purchase_orders' => 'wb_manage_purchasing', 'wb_po_lines' => 'wb_manage_purchasing', 'wb_stocktakes' => 'wb_run_stocktake',
		'wb_quotes' => 'wb_create_quotes', 'wb_quote_lines' => 'wb_create_quotes', 'wb_orders' => 'wb_manage_orders', 'wb_order_lines' => 'wb_manage_orders',
		'wb_invoices' => 'wb_issue_invoices', 'wb_credit_notes' => 'wb_issue_credit_notes', 'wb_payments' => 'wb_match_payments',
		'wb_delivery_notes' => 'wb_issue_delivery_notes', 'wb_statements' => 'wb_issue_invoices', 'wb_documents' => 'wb_view_documents',
		'wb_staff' => 'wb_view_staff', 'wb_timesheets' => 'wb_approve_timesheets', 'wb_leave_types' => 'wb_view_staff', 'wb_leave' => 'wb_approve_leave',
		'wb_kpis' => 'wb_view_staff', 'wb_kpi_scores' => 'wb_view_staff', 'wb_reviews' => 'wb_run_reviews', 'wb_staff_notes' => 'wb_view_staff',
		'wb_portal_requests' => 'wb_manage_customers',
		// Payroll tables are deliberately NOT listable through [wb_list] (pay details stay on the Payroll screen).
	];

	const MONEY_COLS = [ 'subtotal', 'vat', 'total', 'amount', 'amount_paid', 'amount_credited', 'amount_allocated', 'unit_price', 'line_total', 'floor_price', 'list_price', 'cost_price', 'credit_limit', 'unit_cost', 'variance_total', 'expected_receipts', 'predicted_orders', 'committed_purchases', 'payroll', 'net', 'cumulative', 'value', 'gross', 'paye', 'uif_employee', 'uif_employer', 'sdl', 'other_deductions', 'gross_total', 'paye_total', 'net_total', 'owed' ];

	/** Plain-English column words. Anything not here is the key with spaces. */
	const LABELS = [
		'_ID' => 'No.', 'name' => 'Name', 'sku' => 'Code', 'account_status' => 'Account', 'payment_terms_days' => 'Terms (days)', 'credit_limit' => 'Credit limit',
		'journey_stage' => 'Stage', 'customer_id' => 'Customer', 'product_id' => 'Product', 'quote_number' => 'Quote', 'order_number' => 'Order',
		'invoice_number' => 'Invoice', 'credit_number' => 'Credit note', 'dn_number' => 'Note', 'po_number' => 'Purchase order', 'valid_until' => 'Valid until',
		'pricing_check_status' => 'Price check', 'price_source' => 'Price from', 'floor_price' => 'Lowest allowed', 'below_floor' => 'Below lowest',
		'unit_price' => 'Price each', 'line_total' => 'Line total', 'qty' => 'Qty', 'qty_ordered' => 'Ordered', 'qty_reserved' => 'Put aside',
		'qty_delivered' => 'Delivered', 'qty_backordered' => 'Waiting for stock', 'due_at' => 'Due', 'issued_at' => 'Issued', 'amount_paid' => 'Paid',
		'amount_credited' => 'Credited', 'match_status' => 'Match', 'match_method' => 'Matched how', 'received_at' => 'Received', 'bank_reference' => 'Reference',
		'status' => 'Status', 'record_status' => 'Record', 'type' => 'Type', 'reason' => 'Reason', 'staff_id' => 'Person', 'work_date' => 'Day', 'hours' => 'Hours',
		'from_date' => 'From', 'to_date' => 'To', 'days' => 'Days', 'cct_created' => 'Created', 'asked_by' => 'Asked by', 'approved_by' => 'Approved by',
		'by' => 'By', 'when' => 'When', 'week_start' => 'Week of', 'expected_receipts' => 'Money in (invoices)', 'predicted_orders' => 'Money in (expected orders)',
		'committed_purchases' => 'Money out (suppliers)', 'payroll' => 'Wages', 'net' => 'Net', 'cumulative' => 'Running total', 'margin_pct' => 'Margin %',
		'reverses_payment' => 'Reverses payment', 'on_hand' => 'On hand', 'reserved' => 'Put aside', 'available' => 'Available', 'title' => 'Title', 'version' => 'Version',
	];

	public static function init(): void {
		add_shortcode( 'wb_list', [ __CLASS__, 'list_shortcode' ] );
		add_action( 'wp_enqueue_scripts', function () {
			wp_enqueue_style( 'wb-dashboard', plugins_url( 'assets/wb-dashboard.css', WB_PLUGIN_FILE ), [], WB_VERSION );
		} );
		add_action( 'wp_footer', [ __CLASS__, 'footer_script' ] );
	}

	public static function label( string $key ): string {
		return self::LABELS[ $key ] ?? self::words( $key );
	}

	/**
	 * Stored value → screen word (0.3.4, BUILD-PATTERNS §2.3, Kaycie's KC_Words). Rename what the code
	 * regenerates, never what is stored; this map is the bridge. A value not listed is the stored key
	 * with spaces, first letter up. Status values are compared in SQL and are never renamed.
	 */
	const WORDS = [
		'on_hold' => 'On hold', 'needs_approval' => 'Needs approval', 'part_paid' => 'Part paid', 'awaiting_payment' => 'Awaiting payment',
		'part_delivered' => 'Part delivered', 'part_received' => 'Part received', 'at_risk' => 'At risk', 'first_order' => 'First order',
		'auto_reference' => 'By reference', 'auto_amount' => 'By amount', 'manual' => 'By hand', 'unallocated' => 'Not allocated',
		'fixed_price' => 'Fixed price', 'pct_off_list' => '% off list', 'pct_on_cost' => '% on cost', 'rule' => 'Customer rule', 'tier' => 'Price tier', 'list' => 'List price',
		'write_off' => 'Write-off', 'coa' => 'Certificate of analysis', 'msds' => 'Safety data sheet', 'quote_pdf' => 'Quote', 'invoice_pdf' => 'Invoice',
		'credit_pdf' => 'Credit note', 'dn_pdf' => 'Delivery note', 'pod' => 'Proof of delivery', 'signed_quote' => 'Signed quote', 'staff_doc' => 'Staff document',
		'to_do' => 'To do', 'self_review' => 'Self review', 'manager_review' => 'Manager review',
	];

	/** The screen word for a stored value. */
	public static function words( string $value ): string {
		$v = trim( $value );
		return self::WORDS[ $v ] ?? ucfirst( str_replace( '_', ' ', $v ) );
	}

	public static function money( $v ): string {
		return number_format( (float) $v, 2, '.', ' ' );
	}

	/** A status chip. The class comes from the stored value; the words are plain English. */
	public static function chip( $val ): string {
		$v = trim( (string) $val );
		if ( '' === $v ) return '';
		$tone = 'neutral';
		if ( in_array( $v, [ 'active', 'open', 'paid', 'matched', 'approved', 'passed', 'delivered', 'closed', 'received', 'posted', 'signed', 'collected', 'yes', 'repeat', 'converted', 'done', 'finalised', 'checked' ], true ) ) $tone = 'good';
		elseif ( in_array( $v, [ 'overdue', 'on_hold', 'needs_approval', 'unmatched', 'declined', 'void', 'cancelled', 'disputed', 'lapsed', 'at_risk', 'queried', 'below' ], true ) ) $tone = 'bad';
		elseif ( in_array( $v, [ 'suggested', 'part_paid', 'partial', 'requested', 'submitted', 'awaiting_payment', 'ready', 'part_delivered', 'sent', 'draft', 'pending', 'unallocated', 'to_do' ], true ) ) $tone = 'wait';
		return '<span class="wb-chip wb-chip--' . $tone . '">' . esc_html( self::words( $v ) ) . '</span>';
	}

	/** The ⋯ menu wrapper. One definition. */
	public static function kebab( string $menu ): string {
		return '<div class="wb-kebab"><button type="button" class="wb-kebab-btn" aria-label="Actions" aria-expanded="false" aria-haspopup="true">&#8943;</button><div class="wb-kebab-body" role="menu">' . $menu . '</div></div>';
	}

	/** A stat tile: label → number → note (0.3.4). $hot marks the one number that must not be missed. */
	public static function stat( string $label, $value, string $href = '', string $note = '', bool $hot = false ): string {
		$inner = '<span class="wb-stat-label">' . esc_html( $label ) . '</span><span class="wb-stat-num">' . esc_html( (string) $value ) . '</span>'
			. ( '' !== $note ? '<span class="wb-stat-sub">' . esc_html( $note ) . '</span>' : '' );
		$cls = 'wb-stat' . ( $hot ? ' wb-stat--hot' : '' );
		return '' !== $href ? '<a class="' . $cls . '" href="' . esc_url( $href ) . '">' . $inner . '</a>' : '<div class="' . $cls . '">' . $inner . '</div>';
	}

	/**
	 * A fold (0.3.4, Kaycie's section hierarchy: width does the sorting before colour does).
	 * $opts: open (bool), id, kind ('lead' = the first and main fold, marked by the frame when not set;
	 * 'sibling' = also here, inset; 'reference' = read-only proof, inset further, collapsed),
	 * hint (a count or short note on the summary), note (one line at the top of the body).
	 */
	public static function fold( string $title, string $body, array $opts = [] ): string {
		$kind = (string) ( $opts['kind'] ?? '' );
		$cls  = 'wb-fold' . ( in_array( $kind, [ 'lead', 'sibling', 'reference' ], true ) ? ' wb-fold--' . $kind : '' );
		$h    = '<details class="' . $cls . '"' . ( ! empty( $opts['open'] ) ? ' open' : '' ) . ( ! empty( $opts['id'] ) ? ' id="' . esc_attr( (string) $opts['id'] ) . '"' : '' ) . '><summary>'
			. ( 'sibling' === $kind ? '<span class="wb-fold-eyebrow">Also here</span>' : '' ) . esc_html( $title )
			. ( 'reference' === $kind ? ' <span class="wb-fold-ro">Read only</span>' : '' )
			. ( ! empty( $opts['hint'] ) ? '<span class="wb-fold-hint">' . esc_html( (string) $opts['hint'] ) . '</span>' : '' ) . '</summary><div class="wb-fold-body">'
			. ( ! empty( $opts['note'] ) ? '<p class="wb-fold-note">' . esc_html( (string) $opts['note'] ) . '</p>' : '' );
		return $h . $body . '</div></details>';
	}

	/** An empty or blocked state card: says what would be here and what to do (never a blank). */
	public static function state( string $type, string $title, string $text = '' ): string {
		$ic = 'blocked' === $type
			? '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
			: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12l3-7h12l3 7v7H3z"/><path d="M3 12h5l2 3h4l2-3h5"/></svg>';
		return '<div class="wb-state wb-state--' . esc_attr( $type ) . '"><span class="wb-state-ic">' . $ic . '</span><div><p class="wb-state-t">' . esc_html( $title ) . '</p>'
			. ( '' !== $text ? '<p class="wb-state-s">' . esc_html( $text ) . '</p>' : '' ) . '</div></div>';
	}

	/** A bounded list says its bound (BUILD-PATTERNS §2.4): the line under a table whose fetch filled its cap. */
	public static function bounded( array $rows, int $limit, string $what = 'the newest' ): string {
		if ( $limit <= 0 || count( $rows ) < $limit ) return '';
		return '<p class="wb-list-more">Showing ' . $what . ' <strong>' . number_format( $limit ) . '</strong>. There may be more; narrow the list to find the rest.</p>';
	}

	/**
	 * The table. $columns: 'key' or [ key, label?, type? (identity|chip|money|plain), render? fn($val,$row) ].
	 * $opts: cct + actions (WB_RowActions keys), action_html fn($row) for bespoke menus, cards (default true), empty.
	 */
	public static function render_table( array $rows, array $columns, array $opts = [] ): string {
		if ( ! $rows ) return isset( $opts['empty'] ) ? self::state( 'empty', (string) $opts['empty'], (string) ( $opts['empty_note'] ?? '' ) ) : '';
		$cards       = ! array_key_exists( 'cards', $opts ) || $opts['cards'];
		$cct         = (string) ( $opts['cct'] ?? '' );
		$actions     = (array) ( $opts['actions'] ?? [] );
		$action_html = $opts['action_html'] ?? null;
		$has_actions = $actions || is_callable( $action_html );

		$specs = [];
		foreach ( array_values( $columns ) as $i => $c ) {
			$s = is_array( $c ) ? $c : [ 'key' => (string) $c ];
			$k = (string) $s['key'];
			$s['label'] = $s['label'] ?? self::label( $k );
			if ( empty( $s['type'] ) ) {
				$s['type'] = 0 === $i ? 'identity'
					: ( ( 'status' === $k || 'record_status' === $k || '_status' === substr( $k, -7 ) || 'below_floor' === $k || 'journey_stage' === $k || 'match_status' === $k ) ? 'chip'
					: ( in_array( $k, self::MONEY_COLS, true ) ? 'money' : 'plain' ) );
			}
			$specs[] = $s;
		}

		$h = '<table class="wb-list' . ( $cards ? ' wb-list--cards' : '' ) . '"><thead><tr>';
		foreach ( $specs as $s ) $h .= '<th class="wb-col-' . esc_attr( $s['type'] ) . '">' . esc_html( (string) $s['label'] ) . '</th>';
		if ( $has_actions ) $h .= '<th class="wb-col-actions" aria-label="Actions"></th>';
		$h .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$h .= '<tr>';
			foreach ( $specs as $s ) {
				$k   = (string) $s['key'];
				$val = $row[ $k ] ?? '';
				if ( isset( $s['render'] ) && is_callable( $s['render'] ) ) {
					$cell = (string) call_user_func( $s['render'], $val, $row );
				} elseif ( 'chip' === $s['type'] ) {
					$cell = self::chip( $val );
				} elseif ( 'money' === $s['type'] ) {
					$cell = '' === (string) $val ? '' : esc_html( self::money( $val ) );
				} else {
					$cell = esc_html( is_scalar( $val ) ? (string) $val : (string) wp_json_encode( $val ) );
				}
				if ( 'identity' === $s['type'] && '' === trim( wp_strip_all_tags( $cell ) ) ) $cell = '&mdash;';
				$h .= '<td class="wb-cell-' . esc_attr( $s['type'] ) . '" data-label="' . esc_attr( (string) $s['label'] ) . '">' . $cell . '</td>';
			}
			if ( $has_actions ) {
				$menu = is_callable( $action_html ) ? (string) call_user_func( $action_html, $row ) : WB_RowActions::cell( $actions, $cct, $row );
				$h   .= '<td class="wb-row-actions" data-label="Actions">' . ( '' !== trim( $menu ) ? self::kebab( $menu ) : '<span class="wb-muted">&mdash;</span>' ) . '</td>';
			}
			$h .= '</tr>';
		}
		return $h . '</tbody></table>';
	}

	/* ---------- form helpers (panels post to themselves: nonce + PRG) ---------- */

	public static function form_open( string $action, bool $files = false ): string {
		return '<form method="post" class="wb-form"' . ( $files ? ' enctype="multipart/form-data"' : '' ) . '>'
			. wp_nonce_field( 'wb_panel_' . $action, '_wbp', true, false ) . '<input type="hidden" name="wb_panel" value="' . esc_attr( $action ) . '">' . wb_return_field();
	}

	public static function field( string $name, string $label, string $type = 'text', $value = '', array $opts = [] ): string {
		$id  = 'wb-' . sanitize_html_class( $name );
		$req = ! empty( $opts['required'] ) ? ' required' : '';
		$h   = '<label class="wb-field" for="' . esc_attr( $id ) . '"><span>' . esc_html( $label ) . ( '' !== $req ? ' <span class="wb-req" aria-hidden="true">*</span>' : '' ) . '</span>';
		if ( 'select' === $type ) {
			$h .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req . '>';
			foreach ( (array) ( $opts['options'] ?? [] ) as $k => $v ) $h .= '<option value="' . esc_attr( (string) $k ) . '"' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( (string) $v ) . '</option>';
			$h .= '</select>';
		} elseif ( 'textarea' === $type ) {
			$h .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . (int) ( $opts['rows'] ?? 4 ) . '" placeholder="' . esc_attr( (string) ( $opts['placeholder'] ?? '' ) ) . '"' . $req . '>' . esc_textarea( (string) $value ) . '</textarea>';
		} else {
			$step = 'number' === $type ? ' step="' . esc_attr( (string) ( $opts['step'] ?? 'any' ) ) . '"' : '';
			$h   .= '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( (string) ( $opts['placeholder'] ?? '' ) ) . '"' . $step . $req . '>';
		}
		if ( ! empty( $opts['note'] ) ) $h .= '<small>' . esc_html( (string) $opts['note'] ) . '</small>';
		return $h . '</label>';
	}

	public static function form_close( string $button, string $confirm = '' ): string {
		return '<button type="submit" class="wb-btn"' . ( '' !== $confirm ? ' data-wb-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $button ) . '</button></form>';
	}

	/** Pick-lists from a CCT: [ id => label ]. */
	public static function options( string $cct, string $label_col, array $where = [], bool $blank = true ): array {
		$out = $blank ? [ '' => '— choose —' ] : [];
		foreach ( WB_CCT::find( $cct, $where, [ 'limit' => 2000, 'orderby' => $label_col, 'order' => 'ASC' ] ) as $r ) {
			$out[ (int) $r['_ID'] ] = ( isset( $r['sku'] ) && 'name' === $label_col ? $r['sku'] . ' · ' : '' ) . (string) ( $r[ $label_col ] ?? '#' . $r['_ID'] );
		}
		return $out;
	}

	/* ---------- [wb_list] ---------- */

	public static function list_shortcode( $atts = [] ): string {
		$atts = (array) $atts;
		$cct  = sanitize_key( (string) ( $atts['cct'] ?? '' ) );
		$need = self::VIEW_CAPS[ $cct ] ?? '';
		if ( '' === $need || ! current_user_can( $need ) ) return '';   // unknown table or no access: nothing (fail closed)
		if ( ! empty( $atts['cap'] ) && ! current_user_can( sanitize_key( (string) $atts['cap'] ) ) ) return '';
		$where = [];
		foreach ( $atts as $k => $v ) if ( 0 === strpos( (string) $k, 'filter_' ) ) $where[ substr( (string) $k, 7 ) ] = (string) $v;
		$cols = array_values( array_intersect( array_map( 'trim', explode( ',', (string) ( $atts['columns'] ?? 'name' ) ) ), WB_CCT::columns( $cct ) ) );
		if ( ! $cols ) return wb_notice( 'warn', 'That list is not set up yet.' );
		$rows = WB_CCT::find( $cct, $where, [ 'limit' => (int) ( $atts['limit'] ?? 50 ), 'orderby' => (string) ( $atts['orderby'] ?? '_ID' ), 'order' => (string) ( $atts['order'] ?? 'DESC' ) ] );
		$out  = self::render_table( $rows, $cols, [ 'cct' => $cct, 'actions' => array_filter( array_map( 'trim', explode( ',', (string) ( $atts['actions'] ?? '' ) ) ) ), 'empty' => (string) ( $atts['empty'] ?? 'Nothing here yet.' ) ] );
		$out .= self::bounded( $rows, (int) ( $atts['limit'] ?? 50 ) );   // a bounded list says so
		return $out;
	}

	/** One delegated handler: the ⋯ menus and the site-wide double confirm (data-wb-confirm / data-wb-danger). */
	public static function footer_script(): void {
		if ( ! is_user_logged_in() ) return;
		echo '<script>(function(){document.addEventListener("click",function(e){var b=e.target.closest(".wb-kebab-btn");document.querySelectorAll(".wb-kebab.is-open").forEach(function(k){if(!b||k!==b.parentNode){k.classList.remove("is-open");k.querySelector(".wb-kebab-btn").setAttribute("aria-expanded","false");}});if(b){var k=b.parentNode;var o=k.classList.toggle("is-open");b.setAttribute("aria-expanded",o?"true":"false");}});'
			. 'document.addEventListener("submit",function(e){var f=e.target,c=f.getAttribute("data-wb-confirm")||(e.submitter&&e.submitter.getAttribute("data-wb-confirm"));if(c&&!window.confirm(c)){e.preventDefault();return;}if(f.getAttribute("data-wb-danger")&&!window.confirm("This cannot be undone. Go ahead?")){e.preventDefault();return;}var r=f.getAttribute("data-wb-reason");if(r){var a=window.prompt(r,"");if(a===null||!a.trim()){e.preventDefault();return;}f.querySelector("input[name=wb_reason]").value=a.trim();}},true);})();</script>';
	}
}
