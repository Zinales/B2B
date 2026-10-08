<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_RowActions — the ⋯ menu registry (convention 6: row actions over ID-entry forms).
 *
 * Each action: cct, label, icon (canonical set: view · share · print · download · edit · delete ·
 * remind · approve, plus add/open/archive/withdraw/complete), allowed() (capability), visible($row)
 * (state), handle($id, $reason) → true | WP_Error | string url-to-show, optional confirm / danger /
 * reason (asks why; the answer is required). The engines do the real checking again — the menu
 * only decides what to offer.
 *
 * POST → nonce → allowed → handle → ledger → redirect back (PRG) with ?wbra=ok|err; the message
 * rides in a one-time per-user flash, printed by notice().
 */
class WB_RowActions {

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_handle' ] );
	}

	private static function st( array $row ): string {
		return (string) ( $row['status'] ?? '' );
	}

	public static function registry(): array {
		$a = [
			/* quotes */
			'quote_send' => [ 'cct' => 'wb_quotes', 'label' => 'Mark sent and get the acceptance link', 'icon' => 'share',
				'confirm' => 'Mark this quote as sent? Its prices are then fixed. Nothing is emailed: you send it and the link yourself.',
				'allowed' => fn() => current_user_can( 'wb_send_quotes' ), 'visible' => fn( $r ) => 'draft' === self::st( $r ),
				'handle' => fn( $id ) => WB_Orders::send_quote( $id ) ],
			'quote_link' => [ 'cct' => 'wb_quotes', 'label' => 'New acceptance link', 'icon' => 'share',
				'allowed' => fn() => current_user_can( 'wb_send_quotes' ), 'visible' => fn( $r ) => 'sent' === self::st( $r ),
				'handle' => fn( $id ) => WB_Orders::acceptance_url( $id ) ],
			'quote_accept' => [ 'cct' => 'wb_quotes', 'label' => 'Record acceptance', 'icon' => 'approve', 'reason' => 'Who accepted it? (a name, or "signed PDF")',
				'allowed' => fn() => current_user_can( 'wb_manage_orders' ), 'visible' => fn( $r ) => 'sent' === self::st( $r ),
				'handle' => fn( $id, $why ) => WB_Orders::accept_quote( $id, $why ) ],
			'quote_decline' => [ 'cct' => 'wb_quotes', 'label' => 'Customer declined', 'icon' => 'withdraw', 'reason' => 'Why did they decline?',
				'allowed' => fn() => current_user_can( 'wb_send_quotes' ), 'visible' => fn( $r ) => in_array( self::st( $r ), [ 'draft', 'sent' ], true ),
				'handle' => fn( $id, $why ) => WB_Orders::decline_quote( $id, $why ) ],
			'line_approval' => [ 'cct' => 'wb_quote_lines', 'label' => 'Ask for price approval', 'icon' => 'remind', 'reason' => 'Why this price?',
				'allowed' => fn() => current_user_can( 'wb_create_quotes' ),
				'visible' => fn( $r ) => WB_Pricing::line_flagged( $r ) && empty( $r['approval_id'] ),
				'handle' => fn( $id, $why ) => WB_Pricing::request_approval( $id, $why ) ],
			'line_remove' => [ 'cct' => 'wb_quote_lines', 'label' => 'Remove line', 'icon' => 'delete', 'danger' => true, 'confirm' => 'Remove this line from the draft?',
				'allowed' => fn() => current_user_can( 'wb_create_quotes' ), 'visible' => fn( $r ) => true,
				'handle' => fn( $id ) => WB_Orders::remove_quote_line( $id ) ],

			/* orders */
			'order_open' => [ 'cct' => 'wb_orders', 'label' => 'Open', 'icon' => 'view', 'allowed' => fn() => current_user_can( 'wb_manage_orders' ) || current_user_can( 'wb_issue_delivery_notes' ),
				'visible' => fn( $r ) => true, 'href' => fn( $r ) => add_query_arg( 'order', (int) $r['_ID'] ) ],
			'order_invoice' => [ 'cct' => 'wb_orders', 'label' => 'Issue invoice', 'icon' => 'print', 'confirm' => 'Issue the invoice now? It gets its number and can never be edited.',
				'allowed' => fn() => current_user_can( 'wb_issue_invoices' ), 'visible' => fn( $r ) => ! in_array( self::st( $r ), [ 'cancelled', 'closed' ], true ) && ! WB_Invoices::for_order( (int) $r['_ID'] ),
				'handle' => fn( $id ) => WB_Invoices::issue_for_order( $id ) ],
			'order_release' => [ 'cct' => 'wb_orders', 'label' => 'Release for collection / delivery', 'icon' => 'approve',
				'allowed' => fn() => current_user_can( 'wb_manage_orders' ), 'visible' => fn( $r ) => in_array( self::st( $r ), [ 'accepted', 'invoiced', 'awaiting_payment', 'paid' ], true ),
				'handle' => fn( $id ) => WB_Orders::release( $id ) ],
			'order_ship_all' => [ 'cct' => 'wb_orders', 'label' => 'Issue note for everything left', 'icon' => 'complete',
				'confirm' => 'Issue a delivery / collection note for everything still to go that is in stock? Stock leaves the books now. Stock reserved for other orders stays put.',
				'allowed' => fn() => current_user_can( 'wb_issue_delivery_notes' ), 'visible' => fn( $r ) => in_array( self::st( $r ), [ 'ready', 'part_delivered' ], true ),
				'handle' => function ( $id ) {
					// Each line: its own reservation first, then only stock nobody has reserved (M2).
					$q    = [];
					$free = [];
					foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => $id ], [ 'order' => 'ASC', 'limit' => 1000 ] ) as $l ) {
						$left = (float) $l['qty_ordered'] - (float) $l['qty_delivered'];
						if ( $left <= 0.0001 ) continue;
						$pid = (int) $l['product_id'];
						if ( ! isset( $free[ $pid ] ) ) $free[ $pid ] = max( 0.0, WB_Stock::available( $pid ) );
						$own   = min( $left, (float) $l['qty_reserved'] );
						$extra = min( $left - $own, $free[ $pid ] );
						$free[ $pid ] -= $extra;
						$q[ (int) $l['_ID'] ] = round( $own + $extra, 3 );
					}
					$q = array_filter( $q, fn( $v ) => $v > 0.0001 );
					if ( ! $q ) return new WP_Error( 'wb_no_stock', 'Nothing still to go on this order is in stock yet.' );
					return WB_Orders::issue_delivery_note( $id, $q );
				} ],
			'order_close' => [ 'cct' => 'wb_orders', 'label' => 'Close', 'icon' => 'complete',
				'allowed' => fn() => current_user_can( 'wb_close_orders' ), 'visible' => fn( $r ) => 'delivered' === self::st( $r ),
				'handle' => fn( $id ) => WB_Orders::close( $id ) ],
			'order_cancel' => [ 'cct' => 'wb_orders', 'label' => 'Cancel order', 'icon' => 'withdraw', 'danger' => true, 'reason' => 'Why is the order cancelled?',
				'allowed' => fn() => current_user_can( 'wb_manage_orders' ), 'visible' => fn( $r ) => in_array( self::st( $r ), WB_Orders::PRE_DELIVERY, true ),
				'handle' => fn( $id, $why ) => WB_Orders::cancel( $id, $why ) ],
			'dn_signed' => [ 'cct' => 'wb_delivery_notes', 'label' => 'Record who signed for it', 'icon' => 'approve', 'reason' => 'Name of the person who collected / received the goods',
				'allowed' => fn() => current_user_can( 'wb_issue_delivery_notes' ), 'visible' => fn( $r ) => 'issued' === self::st( $r ),
				'handle' => fn( $id, $why ) => WB_Orders::confirm_collection( $id, $why ) ],

			/* money */
			'credit_approve' => [ 'cct' => 'wb_credit_notes', 'label' => 'Approve credit note', 'icon' => 'approve', 'confirm' => 'Approve this credit note? It gets its number and reduces what the customer owes.',
				'allowed' => fn() => current_user_can( 'wb_approve_credit_notes' ), 'visible' => fn( $r ) => 'requested' === self::st( $r ),
				'handle' => fn( $id ) => WB_Invoices::approve_credit_note( $id ) ],
			'credit_decline' => [ 'cct' => 'wb_credit_notes', 'label' => 'Decline', 'icon' => 'withdraw', 'reason' => 'Why is it declined?',
				'allowed' => fn() => current_user_can( 'wb_approve_credit_notes' ), 'visible' => fn( $r ) => 'requested' === self::st( $r ),
				'handle' => fn( $id, $why ) => WB_Invoices::decline_credit_note( $id, $why ) ],
			'payment_confirm' => [ 'cct' => 'wb_payments', 'label' => 'Confirm suggested match', 'icon' => 'approve',
				'allowed' => fn() => current_user_can( 'wb_match_payments' ), 'visible' => fn( $r ) => 'suggested' === ( $r['match_status'] ?? '' ),
				'handle' => fn( $id ) => WB_Payments::confirm_suggestion( $id ) ],

			/* purchasing + stock */
			'po_send' => [ 'cct' => 'wb_purchase_orders', 'label' => 'Mark sent', 'icon' => 'share', 'confirm' => 'Mark this purchase order as sent to the supplier? (Nothing is emailed.)',
				'allowed' => fn() => current_user_can( 'wb_manage_purchasing' ), 'visible' => fn( $r ) => 'draft' === self::st( $r ),
				'handle' => fn( $id ) => WB_Stock::set_po_status( $id, 'sent' ) ],
			'po_cancel' => [ 'cct' => 'wb_purchase_orders', 'label' => 'Cancel', 'icon' => 'withdraw', 'danger' => true, 'confirm' => 'Cancel this purchase order?',
				'allowed' => fn() => current_user_can( 'wb_manage_purchasing' ), 'visible' => fn( $r ) => in_array( self::st( $r ), [ 'draft', 'sent', 'part_received' ], true ),
				'handle' => fn( $id ) => WB_Stock::set_po_status( $id, 'cancelled' ) ],
			'stocktake_submit' => [ 'cct' => 'wb_stocktakes', 'label' => 'Hand over for checking', 'icon' => 'complete',
				'allowed' => fn() => current_user_can( 'wb_run_stocktake' ), 'visible' => fn( $r ) => 'counting' === self::st( $r ) && (int) $r['counted_by_staff_id'] === WB_Staff::current_staff_id(),
				'handle' => fn( $id ) => WB_Stock::submit_count( $id ) ],
			'stocktake_post' => [ 'cct' => 'wb_stocktakes', 'label' => 'Check and post the count', 'icon' => 'approve', 'confirm' => 'Post this count? Every difference becomes a stock movement carrying the counter\'s name and yours.',
				'allowed' => fn() => current_user_can( 'wb_run_stocktake' ), 'visible' => fn( $r ) => 'submitted' === self::st( $r ) && (int) $r['counted_by_staff_id'] !== WB_Staff::current_staff_id(),
				'handle' => fn( $id ) => WB_Stock::post_stocktake( $id ) ],

			/* staff */
			'ts_submit' => [ 'cct' => 'wb_timesheets', 'label' => 'Submit', 'icon' => 'complete',
				'allowed' => fn() => true, 'visible' => fn( $r ) => in_array( self::st( $r ), [ 'draft', 'queried' ], true ) && (int) $r['staff_id'] === WB_Staff::current_staff_id(),
				'handle' => fn( $id ) => WB_Staff::submit_timesheet( $id ) ],
			'ts_approve' => [ 'cct' => 'wb_timesheets', 'label' => 'Approve', 'icon' => 'approve',
				'allowed' => fn() => current_user_can( 'wb_approve_timesheets' ), 'visible' => fn( $r ) => 'submitted' === self::st( $r ) && (int) $r['staff_id'] !== WB_Staff::current_staff_id(),
				'handle' => fn( $id ) => WB_Staff::decide_timesheet( $id, true ) ],
			'ts_query' => [ 'cct' => 'wb_timesheets', 'label' => 'Query', 'icon' => 'remind', 'reason' => 'What needs checking?',
				'allowed' => fn() => current_user_can( 'wb_approve_timesheets' ), 'visible' => fn( $r ) => 'submitted' === self::st( $r ) && (int) $r['staff_id'] !== WB_Staff::current_staff_id(),
				'handle' => fn( $id, $why ) => WB_Staff::decide_timesheet( $id, false, $why ) ],
			'leave_approve' => [ 'cct' => 'wb_leave', 'label' => 'Approve', 'icon' => 'approve',
				'allowed' => fn() => current_user_can( 'wb_approve_leave' ), 'visible' => fn( $r ) => 'requested' === self::st( $r ) && (int) $r['staff_id'] !== WB_Staff::current_staff_id(),
				'handle' => fn( $id ) => WB_Staff::decide_leave( $id, true ) ],
			'leave_decline' => [ 'cct' => 'wb_leave', 'label' => 'Decline', 'icon' => 'withdraw', 'confirm' => 'Decline this leave request?',
				'allowed' => fn() => current_user_can( 'wb_approve_leave' ), 'visible' => fn( $r ) => 'requested' === self::st( $r ) && (int) $r['staff_id'] !== WB_Staff::current_staff_id(),
				'handle' => fn( $id ) => WB_Staff::decide_leave( $id, false ) ],
			'leave_cancel' => [ 'cct' => 'wb_leave', 'label' => 'Cancel', 'icon' => 'withdraw', 'confirm' => 'Cancel this leave?',
				'allowed' => fn() => true, 'visible' => fn( $r ) => in_array( self::st( $r ), [ 'requested', 'approved' ], true ) && ( (int) $r['staff_id'] === WB_Staff::current_staff_id() || current_user_can( 'wb_manage_staff' ) ),
				'handle' => fn( $id ) => WB_Staff::cancel_leave( $id ) ],
		];
		// Soft delete (archive) for the master records. Nothing is ever hard-deleted.
		foreach ( [ 'wb_customers' => 'wb_manage_customers', 'wb_contacts' => 'wb_manage_customers', 'wb_products' => 'wb_manage_products', 'wb_suppliers' => 'wb_manage_purchasing', 'wb_price_rules' => 'wb_manage_pricing' ] as $cct => $cap ) {
			$a[ 'archive_' . $cct ] = [ 'cct' => $cct, 'label' => 'Archive', 'icon' => 'archive', 'danger' => true, 'reason' => 'Why archive it? (It stays on file and can be restored.)',
				'allowed' => fn() => current_user_can( $cap ), 'visible' => fn( $r ) => 'archived' !== ( $r['record_status'] ?? '' ),
				'handle' => fn( $id, $why ) => WB_CCT::set_status( $cct, $id, 'archived', $why ) ];
		}
		return (array) apply_filters( 'wb_row_actions', $a );
	}

	public static function icon( string $key, int $size = 15 ): string {
		$p = [
			'view' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
			'share' => '<circle cx="6" cy="12" r="2.6"/><circle cx="17.5" cy="5.5" r="2.6"/><circle cx="17.5" cy="18.5" r="2.6"/><path d="m8.4 10.8 6.8-4M8.4 13.2l6.8 4"/>',
			'print' => '<path d="M6.5 8V3.5h11V8"/><rect x="3.5" y="8" width="17" height="8.5" rx="1.5"/><path d="M6.5 13.5h11V20.5h-11z"/>',
			'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
			'edit' => '<path d="M14.5 4.8l4.7 4.7L9.9 18.8l-5.6 1 .9-5.7z"/>',
			'delete' => '<path d="M4.5 6.5h15M9.5 6V4.5A1.5 1.5 0 0 1 11 3h2a1.5 1.5 0 0 1 1.5 1.5V6"/><path d="M6.5 6.5l1 13A1.5 1.5 0 0 0 9 21h6a1.5 1.5 0 0 0 1.5-1.5l1-13"/>',
			'remind' => '<path d="M18 9a6 6 0 1 0-12 0c0 6-2.5 7-2.5 7h17S18 15 18 9"/><path d="M10 19.5a2.2 2.2 0 0 0 4 0"/>',
			'approve' => '<path d="M20 6 9 17l-5-5"/>',
			'add' => '<path d="M12 5v14M5 12h14"/>',
			'open' => '<path d="M7 17 17 7"/><path d="M9 7h8v8"/>',
			'archive' => '<rect x="3.5" y="4" width="17" height="4.5" rx="1"/><path d="M5.5 8.5V19A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V8.5"/><path d="M10 12.5h4"/>',
			'withdraw' => '<circle cx="12" cy="12" r="8.5"/><path d="M8 12h8"/>',
			'complete' => '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 12.2l2.3 2.3 4.5-5"/>',
		];
		if ( ! isset( $p[ $key ] ) ) return '';
		return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[ $key ] . '</svg>';
	}

	/** A menu row (rule 17): href → link, submit → button, else a plain span. */
	public static function menuitem( string $icon, string $label, array $opts = [] ): string {
		$inner = '<span class="wb-menuitem-ic">' . self::icon( $icon ) . '</span><span>' . esc_html( $label ) . '</span>';
		$cls   = 'wb-menuitem' . ( empty( $opts['danger'] ) ? '' : ' wb-menuitem--danger' );
		if ( ! empty( $opts['href'] ) ) return '<a class="' . $cls . '" role="menuitem" href="' . esc_url( (string) $opts['href'] ) . '">' . $inner . '</a>';
		if ( ! empty( $opts['submit'] ) ) return '<button type="submit" class="' . $cls . '" role="menuitem">' . $inner . '</button>';
		return '<span class="' . $cls . '">' . $inner . '</span>';
	}

	/** The menu rows for one table row. */
	public static function cell( array $keys, string $cct, array $row ): string {
		$reg = self::registry();
		$h   = '';
		$id  = (int) ( $row['_ID'] ?? 0 );
		foreach ( $keys as $key ) {
			$a = $reg[ $key ] ?? null;
			if ( ! $a || $a['cct'] !== $cct || ! $id ) continue;
			if ( ! call_user_func( $a['allowed'] ) || ! call_user_func( $a['visible'], $row ) ) continue;
			if ( isset( $a['href'] ) ) {
				$h .= self::menuitem( (string) $a['icon'], (string) $a['label'], [ 'href' => call_user_func( $a['href'], $row ) ] );
				continue;
			}
			$attrs = '';
			if ( ! empty( $a['confirm'] ) ) $attrs .= ' data-wb-confirm="' . esc_attr( (string) $a['confirm'] ) . '"';
			if ( ! empty( $a['danger'] ) ) $attrs .= ' data-wb-danger="1"';
			if ( ! empty( $a['reason'] ) ) $attrs .= ' data-wb-reason="' . esc_attr( (string) $a['reason'] ) . '"';
			$h .= '<form method="post" class="wb-menuform"' . $attrs . '>' . wp_nonce_field( 'wb_ra_' . $key . '_' . $id, '_wbra', true, false ) . wb_return_field()
				. '<input type="hidden" name="wb_row_action" value="' . esc_attr( $key ) . '"><input type="hidden" name="wb_row_id" value="' . $id . '">'
				. ( ! empty( $a['reason'] ) ? '<input type="hidden" name="wb_reason" value="">' : '' )
				. self::menuitem( (string) $a['icon'], (string) $a['label'], [ 'submit' => true, 'danger' => ! empty( $a['danger'] ) ] ) . '</form>';
		}
		return $h;
	}

	/* ---------- results: one-time flash per user ---------- */

	public static function flash( string $kind, string $msg ): void {
		set_transient( 'wb_flash_' . get_current_user_id(), [ 'kind' => $kind, 'msg' => $msg ], 5 * MINUTE_IN_SECONDS );
	}

	/** Print (once) the result of the last action. Every dashboard calls this at the top. */
	public static function notice(): string {
		static $done = false;
		if ( $done || ! is_user_logged_in() ) return '';
		$f = get_transient( 'wb_flash_' . get_current_user_id() );
		if ( ! is_array( $f ) ) return '';
		$done = true;
		delete_transient( 'wb_flash_' . get_current_user_id() );
		return wb_notice( (string) $f['kind'], (string) $f['msg'] );
	}

	/** Turn an engine result into a flash: WP_Error → err, a URL → ok with the link, else ok. */
	public static function flash_result( $res, string $ok_msg = 'Done.' ): void {
		if ( is_wp_error( $res ) ) {
			self::flash( 'err', esc_html( $res->get_error_message() ) );
		} elseif ( is_string( $res ) && 0 === strpos( $res, 'http' ) ) {
			// Printed as text in <code>: wb_notice() runs wp_kses_post, which strips <input> (S2).
			// A quote acceptance link is used up when the customer accepts; a datasheet link can be opened again until it expires.
			$once = false !== strpos( $res, 'quote-accept' );
			self::flash( 'ok', 'Done. Copy this link and send it yourself (' . ( $once ? 'the customer can use it once, within 7 days' : 'it works for 7 days' ) . '):<br><code class="wb-copy">' . esc_html( $res ) . '</code>' );
		} else {
			self::flash( 'ok', esc_html( $ok_msg ) );
		}
	}

	public static function maybe_handle(): void {
		if ( empty( $_POST['wb_row_action'] ) || ! is_user_logged_in() ) return;
		$key = sanitize_key( (string) $_POST['wb_row_action'] );
		$id  = absint( $_POST['wb_row_id'] ?? 0 );
		$a   = self::registry()[ $key ] ?? null;
		if ( ! $a || ! $id || isset( $a['href'] ) ) return;
		if ( ! wp_verify_nonce( (string) ( $_POST['_wbra'] ?? '' ), 'wb_ra_' . $key . '_' . $id ) ) return;
		if ( ! call_user_func( $a['allowed'] ) ) {
			self::flash( 'err', 'You cannot do that.' );
		} else {
			$why = mb_substr( sanitize_text_field( wp_unslash( (string) ( $_POST['wb_reason'] ?? '' ) ) ), 0, 200 );
			if ( ! empty( $a['reason'] ) && '' === $why ) {
				self::flash( 'err', 'A reason is needed for that.' );
			} else {
				$res = call_user_func( $a['handle'], $id, $why );
				self::flash_result( $res, $a['label'] . ': done.' );
			}
		}
		wp_safe_redirect( wb_return_url() );
		exit;
	}
}
