<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Stock — STOCK IS A LEDGER, NOT A NUMBER (rule 7).
 *
 *   on_hand   = SUM(qty) of wb_stock_movements, every type except reserve / release
 *   reserved  = SUM(qty) of reserve (+) and release (−) movements
 *   available = on_hand − reserved
 *
 * Nobody types a stock level. A movement has a type, a reason, a reference and a person.
 * Adjustments and write-offs need a SECOND person: they start as a request (wp_wb_stock_requests),
 * and only someone else holding wb_approve_adjustments turns them into a movement — the movement
 * then carries both names (staff_id = who asked, approved_by_staff_id = who approved). Stocktake
 * count differences are posted the same way: the counter's name and a different checker's name.
 *
 * Reorder: check_reorder() raises one open row per product in wp_wb_reorder_alerts when
 * available + on order falls to the reorder point, and resolves it when a PO covers it. The alert
 * is an in-app notification in group "stock" — email only if wb_notify_stock_email is switched on.
 */
class WB_Stock {

	const TYPES = [ 'receipt', 'sale', 'reserve', 'release', 'adjustment', 'return', 'write_off', 'transfer', 'count' ];

	/** Movements whose sign is fixed by their type. adjustment / transfer / count carry their own sign. */
	const IN_TYPES  = [ 'receipt', 'return', 'reserve' ];
	const OUT_TYPES = [ 'sale', 'write_off', 'release' ];

	/** Two names required (the second is the approver / checker, and is the person recording it). */
	const TWO_PERSON = [ 'adjustment', 'write_off', 'count' ];

	public static function init(): void {
		add_action( 'wb_nightly', [ __CLASS__, 'sweep_reorder' ], 20 );
	}

	/* ================================================================== pure */

	/** The signed quantity for a movement type. 0 = invalid. */
	public static function normalise_qty( string $type, float $qty ): float {
		if ( 0.0 === $qty || ! in_array( $type, self::TYPES, true ) ) return 0.0;
		if ( in_array( $type, self::IN_TYPES, true ) ) return abs( $qty );
		if ( in_array( $type, self::OUT_TYPES, true ) ) return -abs( $qty );
		return $qty;
	}

	/**
	 * Segregation of duties for a movement, as plain data. $m: type, qty, staff_id,
	 * approved_by_staff_id, reason. $actor_staff_id = the person recording it.
	 * Returns true or an error code: bad_type, zero_qty, no_reason, no_mover, no_approver,
	 * self_approval, approver_not_actor.
	 *
	 * @return true|string
	 */
	public static function validate_movement( array $m, int $actor_staff_id ) {
		$type = (string) ( $m['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) return 'bad_type';
		if ( 0.0 === self::normalise_qty( $type, (float) ( $m['qty'] ?? 0 ) ) ) return 'zero_qty';
		if ( in_array( $type, self::TWO_PERSON, true ) ) {
			if ( '' === trim( (string) ( $m['reason'] ?? '' ) ) ) return 'no_reason';
			$mover    = (int) ( $m['staff_id'] ?? 0 );
			$approver = (int) ( $m['approved_by_staff_id'] ?? 0 );
			if ( $mover <= 0 ) return 'no_mover';
			if ( $approver <= 0 ) return 'no_approver';
			if ( $approver === $mover ) return 'self_approval';
			if ( $approver !== $actor_staff_id ) return 'approver_not_actor';   // nobody records someone else's approval
		}
		return true;
	}

	/**
	 * Does a product need reordering? Projected = on_hand − reserved + on_order. At or below the
	 * reorder point → raise, suggesting at least reorder_qty, enough to get back above the point,
	 * rounded up to whole packs.
	 */
	public static function reorder_suggestion( float $on_hand, float $reserved, float $on_order, float $reorder_point, float $reorder_qty, float $pack_size = 1 ): array {
		$projected = $on_hand - $reserved + $on_order;
		if ( $reorder_point <= 0 && $reorder_qty <= 0 ) return [ 'raise' => false, 'projected' => $projected, 'suggested_qty' => 0.0 ];
		$raise = $projected <= $reorder_point;
		$need  = max( $reorder_qty, $reorder_point - $projected + 1 );   // at least one order quantity, and enough to get back above the point
		if ( $pack_size > 1 ) $need = ceil( $need / $pack_size ) * $pack_size;
		return [ 'raise' => $raise, 'projected' => $projected, 'suggested_qty' => $raise ? (float) $need : 0.0 ];
	}

	/** Count variance lines → [ variance_qty_abs, variance_value, lines_with_variance ]. */
	public static function count_variance( array $lines ): array {
		$qty = 0.0; $val = 0.0; $n = 0;
		foreach ( $lines as $l ) {
			$d = (float) ( $l['counted'] ?? 0 ) - (float) ( $l['system'] ?? 0 );
			if ( abs( $d ) < 0.0001 ) continue;
			$n++;
			$qty += abs( $d );
			$val += $d * (float) ( $l['unit_cost'] ?? 0 );
		}
		return [ 'variance_qty' => $qty, 'variance_value' => round( $val, 2 ), 'lines' => $n ];
	}

	/* ================================================================== tables */

	public static function alerts_table(): string { global $wpdb; return $wpdb->prefix . 'wb_reorder_alerts'; }
	public static function requests_table(): string { global $wpdb; return $wpdb->prefix . 'wb_stock_requests'; }

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::alerts_table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			qty_on_hand DECIMAL(14,3) NOT NULL DEFAULT 0,
			qty_reserved DECIMAL(14,3) NOT NULL DEFAULT 0,
			qty_on_order DECIMAL(14,3) NOT NULL DEFAULT 0,
			suggested_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
			raised_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY product_open (product_id, resolved_at)
		) $cs;" );
		// Pending adjustments / write-offs: the first of the two names (wb-core 0.1.0 extension table).
		dbDelta( "CREATE TABLE " . self::requests_table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(12) NOT NULL,
			qty DECIMAL(14,3) NOT NULL,
			reason VARCHAR(255) NOT NULL DEFAULT '',
			location VARCHAR(64) NOT NULL DEFAULT '',
			requested_by_staff_id BIGINT UNSIGNED NOT NULL,
			requested_at DATETIME NOT NULL,
			decided_by_staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			decision VARCHAR(12) NOT NULL DEFAULT 'pending',
			decided_at DATETIME NULL,
			movement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY decision (decision)
		) $cs;" );
	}

	/* ================================================================== levels */

	public static function on_hand( int $product_id, int $batch_id = 0 ): float {
		$w = [ 'product_id' => $product_id, 'type NOT IN' => [ 'reserve', 'release' ] ];
		if ( $batch_id ) $w['batch_id'] = $batch_id;
		return round( WB_CCT::sum( 'wb_stock_movements', 'qty', $w, false ), 3 );   // movements are never archived: count them all
	}

	public static function reserved( int $product_id ): float {
		return round( WB_CCT::sum( 'wb_stock_movements', 'qty', [ 'product_id' => $product_id, 'type' => [ 'reserve', 'release' ] ], false ), 3 );
	}

	public static function available( int $product_id ): float {
		return round( self::on_hand( $product_id ) - self::reserved( $product_id ), 3 );
	}

	/** Outstanding quantity on purchase orders that have been sent and not fully received. */
	public static function on_order( int $product_id ): float {
		$open = array_map( fn( $p ) => (int) $p['_ID'], WB_CCT::find( 'wb_purchase_orders', [ 'status' => [ 'sent', 'part_received' ] ], [ 'limit' => 2000 ] ) );
		if ( ! $open ) return 0.0;
		$sum = 0.0;
		foreach ( WB_CCT::find( 'wb_po_lines', [ 'product_id' => $product_id, 'po_id' => $open ], [ 'limit' => 2000 ] ) as $l ) {
			$sum += max( 0, (float) $l['qty_ordered'] - (float) $l['qty_received'] );
		}
		return $sum;
	}

	/* ================================================================== moving */

	private static function error_text( string $code ): string {
		$t = [
			'bad_type'           => 'Unknown movement type.',
			'zero_qty'           => 'A movement needs a quantity.',
			'no_reason'          => 'Say why — adjustments, write-offs and count corrections need a reason.',
			'no_mover'           => 'The person who asked for this must be named.',
			'no_approver'        => 'An adjustment or write-off needs a second person to approve it.',
			'self_approval'      => 'The approver must be a different person from the one who asked.',
			'approver_not_actor' => 'Only the approver can record their own approval.',
		];
		return $t[ $code ] ?? 'The movement was refused.';
	}

	/** Who may record which kind of movement. 'system' = an engine whose own gate already passed. */
	private static function allowed( string $type, bool $system ): bool {
		if ( $system ) return true;
		switch ( $type ) {
			case 'receipt': case 'return': case 'transfer':
				return current_user_can( 'wb_move_stock' ) || current_user_can( 'wb_manage_purchasing' );
			case 'sale': case 'reserve': case 'release':
				return current_user_can( 'wb_manage_orders' ) || current_user_can( 'wb_issue_delivery_notes' );
			case 'adjustment': case 'write_off':
				return current_user_can( 'wb_approve_adjustments' );
			case 'count':
				return current_user_can( 'wb_run_stocktake' );
		}
		return false;
	}

	/**
	 * Append a movement. $args: batch_id, unit_cost, ref_type, ref_id, location, reason,
	 * staff_id (defaults to the current person), approved_by_staff_id, system (engine-internal).
	 * Out-movements (sale, write_off, negative adjustment) may not take on-hand below zero.
	 *
	 * @return int|WP_Error movement _ID
	 */
	public static function move( int $product_id, float $qty, string $type, array $args = [] ) {
		$system = ! empty( $args['system'] );
		if ( ! self::allowed( $type, $system ) ) return new WP_Error( 'wb_forbidden', 'You cannot record that kind of stock movement.' );
		$cols = WB_CCT::require_columns( 'wb_stock_movements', [ 'product_id', 'qty', 'type', 'staff_id', 'approved_by_staff_id', 'reason' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		$product = WB_CCT::get( 'wb_products', $product_id );
		if ( ! $product ) return new WP_Error( 'wb_no_product', 'That product could not be found.' );

		$actor = WB_Staff::current_staff_id();
		$m     = [
			'product_id'           => $product_id,
			'batch_id'             => (int) ( $args['batch_id'] ?? 0 ),
			'qty'                  => self::normalise_qty( $type, $qty ),
			'type'                 => $type,
			'unit_cost'            => isset( $args['unit_cost'] ) ? (float) $args['unit_cost'] : (float) ( $product['cost_price'] ?? 0 ),
			'ref_type'             => sanitize_key( (string) ( $args['ref_type'] ?? '' ) ),
			'ref_id'               => (int) ( $args['ref_id'] ?? 0 ),
			'location'             => sanitize_text_field( (string) ( $args['location'] ?? '' ) ),
			'staff_id'             => (int) ( $args['staff_id'] ?? $actor ),
			'reason'               => sanitize_text_field( (string) ( $args['reason'] ?? '' ) ),
			'approved_by_staff_id' => (int) ( $args['approved_by_staff_id'] ?? 0 ),
		];
		$ok = self::validate_movement( $m, $actor );
		if ( true !== $ok ) return new WP_Error( 'wb_movement_' . $ok, self::error_text( $ok ) );
		if ( 'yes' === (string) ( $product['batch_tracked'] ?? 'no' ) && in_array( $type, [ 'receipt', 'sale', 'write_off' ], true ) && ! $m['batch_id'] ) {
			return new WP_Error( 'wb_batch_required', 'This product is batch-tracked: choose the batch.' );
		}
		if ( $m['qty'] < 0 && ! in_array( $type, [ 'release' ], true ) ) {
			$have = self::on_hand( $product_id, $m['batch_id'] );
			if ( $have + $m['qty'] < -0.0001 ) return new WP_Error( 'wb_not_enough', sprintf( 'Only %s on hand — the movement would take stock below zero.', rtrim( rtrim( number_format( $have, 3, '.', '' ), '0' ), '.' ) ) );
		}
		$id = WB_CCT::insert( 'wb_stock_movements', $m, 'stock_' . $type );
		if ( ! is_wp_error( $id ) && in_array( $type, [ 'sale', 'write_off', 'adjustment', 'count', 'reserve', 'release', 'receipt', 'return' ], true ) ) {   // a release frees stock: the alert may resolve
			self::check_reorder( $product_id );
		}
		return $id;
	}

	/* ================================================================== two-person adjustments */

	/**
	 * Ask for an adjustment or write-off. The first name. Notifies approvers (never the asker).
	 *
	 * @return int|WP_Error request id
	 */
	public static function request_adjustment( int $product_id, float $qty, string $type, string $reason, int $batch_id = 0, string $location = '' ) {
		if ( ! current_user_can( 'wb_adjust_stock' ) ) return new WP_Error( 'wb_forbidden', 'You cannot ask for stock adjustments.' );
		if ( ! in_array( $type, [ 'adjustment', 'write_off' ], true ) ) return new WP_Error( 'wb_bad_type', 'Only adjustments and write-offs go through approval.' );
		if ( '' === trim( $reason ) ) return new WP_Error( 'wb_no_reason', 'Say why.' );
		if ( 0.0 === self::normalise_qty( $type, $qty ) ) return new WP_Error( 'wb_zero_qty', 'A movement needs a quantity.' );
		$me = WB_Staff::current_staff_id();
		if ( ! $me ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record, so the request cannot carry your name.' );
		if ( ! WB_CCT::get( 'wb_products', $product_id ) ) return new WP_Error( 'wb_no_product', 'That product could not be found.' );
		global $wpdb;
		$row = [
			'product_id' => $product_id, 'batch_id' => $batch_id, 'type' => $type, 'qty' => self::normalise_qty( $type, $qty ),
			'reason' => mb_substr( sanitize_text_field( $reason ), 0, 255 ), 'location' => sanitize_text_field( $location ),
			'requested_by_staff_id' => $me, 'requested_at' => current_time( 'mysql' ),
		];
		if ( false === $wpdb->insert( self::requests_table(), $row ) ) return new WP_Error( 'wb_insert_failed', 'The request could not be saved.' );
		$id = (int) $wpdb->insert_id;
		wb_ledger_write( 'stock_request_' . $type, 'wb_stock_requests', $id, null, $row );
		WB_Notifications::notify_cap( 'wb_approve_adjustments', 'stock', sprintf( 'A stock %s of %s on product #%d needs your approval.', str_replace( '_', '-', $type ), $row['qty'], $product_id ),
			home_url( '/workspace/stock/?requests=1' ), 'wb_stock_requests', $id, [ get_current_user_id() ] );
		return $id;
	}

	/**
	 * The second name. Approve → the movement is written with both names; decline → nothing moves.
	 *
	 * @return true|WP_Error
	 */
	public static function decide_request( int $request_id, bool $approve, string $note = '' ) {
		if ( ! current_user_can( 'wb_approve_adjustments' ) ) return new WP_Error( 'wb_forbidden', 'You cannot approve stock adjustments.' );
		global $wpdb;
		$t  = self::requests_table();
		$r  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $request_id ), ARRAY_A );
		if ( ! $r || 'pending' !== $r['decision'] ) return new WP_Error( 'wb_decided', 'That request is not waiting for a decision.' );
		$me = WB_Staff::current_staff_id();
		if ( ! $me ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record, so the approval cannot carry your name.' );
		if ( $me === (int) $r['requested_by_staff_id'] ) return new WP_Error( 'wb_self_approval', 'You cannot approve your own adjustment — someone else must.' );
		$upd = [ 'decided_by_staff_id' => $me, 'decision' => $approve ? 'approved' : 'declined', 'decided_at' => current_time( 'mysql' ), 'movement_id' => 0, 'note' => mb_substr( sanitize_text_field( $note ), 0, 255 ) ];
		// Claim the request first (only while still pending): a second approver, or a double
		// click, finds nothing to claim and the movement is written once.
		$claimed = $wpdb->update( $t, $upd, [ 'id' => $request_id, 'decision' => 'pending' ] );
		if ( false === $claimed ) return new WP_Error( 'wb_update_failed', 'The decision could not be saved.' );
		if ( 1 !== (int) $claimed ) return new WP_Error( 'wb_decided', 'Someone else decided that request a moment ago.' );
		if ( $approve ) {
			$mid = self::move( (int) $r['product_id'], (float) $r['qty'], (string) $r['type'], [
				'batch_id' => (int) $r['batch_id'], 'location' => (string) $r['location'], 'reason' => (string) $r['reason'],
				'staff_id' => (int) $r['requested_by_staff_id'], 'approved_by_staff_id' => $me,
				'ref_type' => 'stock_request', 'ref_id' => $request_id,
			] );
			if ( is_wp_error( $mid ) ) {
				// Nothing moved: hand the request back so it can be decided again.
				$wpdb->update( $t, [ 'decided_by_staff_id' => 0, 'decision' => 'pending', 'decided_at' => null, 'note' => '' ], [ 'id' => $request_id, 'decision' => 'approved', 'movement_id' => 0 ] );
				return $mid;
			}
			$upd['movement_id'] = (int) $mid;
			$wpdb->update( $t, [ 'movement_id' => (int) $mid ], [ 'id' => $request_id ] );
		}
		wb_ledger_write( 'stock_request_' . $upd['decision'], 'wb_stock_requests', $request_id, [ 'decision' => 'pending' ], $upd );
		WB_Notifications::resolve( 'wb_stock_requests', $request_id );
		$asker = WB_Staff::user_for_staff( (int) $r['requested_by_staff_id'] );
		if ( $asker ) WB_Notifications::notify( $asker, 'stock', sprintf( 'Your stock %s request #%d was %s.', str_replace( '_', '-', (string) $r['type'] ), $request_id, $upd['decision'] ), home_url( '/workspace/stock/' ), 'wb_stock_requests', $request_id );
		return true;
	}

	public static function pending_requests(): array {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::requests_table() . " WHERE decision = 'pending' ORDER BY id ASC LIMIT 500", ARRAY_A );
	}

	/* ================================================================== reorder */

	/** Raise or resolve the product's reorder alert. Returns 'raised' | 'resolved' | 'open' | 'none'. */
	public static function check_reorder( int $product_id ): string {
		$p = WB_CCT::get( 'wb_products', $product_id );
		if ( ! $p || 'discontinued' === (string) ( $p['status'] ?? '' ) ) return 'none';
		$on_hand  = self::on_hand( $product_id );
		$reserved = self::reserved( $product_id );
		$on_order = self::on_order( $product_id );
		$s        = self::reorder_suggestion( $on_hand, $reserved, $on_order, (float) $p['reorder_point'], (float) $p['reorder_qty'], max( 1.0, (float) ( $p['pack_size'] ?? 1 ) ) );
		global $wpdb;
		$t    = self::alerts_table();
		$open = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE product_id = %d AND resolved_at IS NULL ORDER BY id DESC LIMIT 1", $product_id ), ARRAY_A );
		if ( $s['raise'] && ! $open ) {
			$row = [ 'product_id' => $product_id, 'qty_on_hand' => $on_hand, 'qty_reserved' => $reserved, 'qty_on_order' => $on_order, 'suggested_qty' => $s['suggested_qty'], 'raised_at' => current_time( 'mysql' ) ];
			$wpdb->insert( $t, $row );
			$aid = (int) $wpdb->insert_id;
			wb_ledger_write( 'reorder_alert_raised', 'wb_reorder_alerts', $aid, null, $row );
			WB_Notifications::notify_cap( 'wb_manage_purchasing', 'stock',
				sprintf( '%s (%s) is at its reorder point: %s available, %s on order. Suggest ordering %s.', (string) $p['name'], (string) $p['sku'], $on_hand - $reserved, $on_order, $s['suggested_qty'] ),
				home_url( '/workspace/purchasing/' ), 'wb_reorder_alerts', $aid );
			return 'raised';
		}
		if ( ! $s['raise'] && $open ) {
			$wpdb->update( $t, [ 'resolved_at' => current_time( 'mysql' ), 'qty_on_order' => $on_order ], [ 'id' => (int) $open['id'] ] );
			wb_ledger_write( 'reorder_alert_resolved', 'wb_reorder_alerts', (int) $open['id'], [ 'resolved_at' => null ], [ 'projected' => $s['projected'] ] );
			WB_Notifications::resolve( 'wb_reorder_alerts', (int) $open['id'], 'stock' );
			return 'resolved';
		}
		return $open ? 'open' : 'none';
	}

	/** Nightly: every active product, so a missed event never leaves an alert stale. */
	public static function sweep_reorder(): void {
		foreach ( WB_CCT::find( 'wb_products', [ 'status' => 'active' ], [ 'limit' => 5000 ] ) as $p ) self::check_reorder( (int) $p['_ID'] );
	}

	public static function open_alerts(): array {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::alerts_table() . ' WHERE resolved_at IS NULL ORDER BY raised_at ASC LIMIT 500', ARRAY_A );
	}

	/* ================================================================== purchasing */

	/**
	 * A draft purchase order with lines [ [product_id, qty, unit_cost?], … ]. Numbered PO-YYYY-…
	 *
	 * @return int|WP_Error
	 */
	public static function create_po( int $supplier_id, array $lines, string $expected_at = '' ) {
		if ( ! current_user_can( 'wb_manage_purchasing' ) ) return new WP_Error( 'wb_forbidden', 'You cannot order from suppliers.' );
		if ( ! WB_CCT::get( 'wb_suppliers', $supplier_id ) ) return new WP_Error( 'wb_no_supplier', 'Choose the supplier.' );
		$clean = [];
		foreach ( $lines as $l ) {
			$p = WB_CCT::get( 'wb_products', (int) ( $l['product_id'] ?? 0 ) );
			$q = (float) ( $l['qty'] ?? 0 );
			if ( ! $p || $q <= 0 ) return new WP_Error( 'wb_bad_line', 'Every line needs a product and a quantity.' );
			$clean[] = [ 'product_id' => (int) $p['_ID'], 'qty_ordered' => $q, 'qty_received' => 0, 'unit_cost' => (float) ( $l['unit_cost'] ?? $p['cost_price'] ) ];
		}
		if ( ! $clean ) return new WP_Error( 'wb_no_lines', 'A purchase order needs at least one line.' );
		return WB_Sequences::issue( 'PO', function ( string $number ) use ( $supplier_id, $clean, $expected_at ) {
			$total = 0.0;
			foreach ( $clean as $l ) $total += $l['qty_ordered'] * $l['unit_cost'];
			$po = WB_CCT::insert( 'wb_purchase_orders', [ 'po_number' => $number, 'supplier_id' => $supplier_id, 'status' => 'draft', 'expected_at' => $expected_at, 'total' => wb_money( $total ) ], 'po_created' );
			if ( is_wp_error( $po ) ) return $po;
			foreach ( $clean as $l ) {
				$lid = WB_CCT::insert( 'wb_po_lines', $l + [ 'po_id' => $po ], 'po_line_added' );
				if ( is_wp_error( $lid ) ) return $lid;
			}
			return $po;
		} );
	}

	/** draft → sent (the person sends it; nothing is emailed from here) or → cancelled. */
	public static function set_po_status( int $po_id, string $to ) {
		if ( ! current_user_can( 'wb_manage_purchasing' ) ) return new WP_Error( 'wb_forbidden', 'You cannot change purchase orders.' );
		$po = WB_CCT::get( 'wb_purchase_orders', $po_id );
		if ( ! $po ) return new WP_Error( 'wb_not_found', 'Purchase order not found.' );
		$ok = [ 'draft' => [ 'sent', 'cancelled' ], 'sent' => [ 'cancelled' ], 'part_received' => [ 'cancelled' ] ];
		if ( ! in_array( $to, $ok[ (string) $po['status'] ] ?? [], true ) ) return new WP_Error( 'wb_bad_move', 'A purchase order cannot go from ' . $po['status'] . ' to ' . $to . '.' );
		$res = WB_CCT::update( 'wb_purchase_orders', $po_id, [ 'status' => $to ], 'po_' . $to );
		if ( true === $res ) {
			foreach ( WB_CCT::find( 'wb_po_lines', [ 'po_id' => $po_id ] ) as $l ) self::check_reorder( (int) $l['product_id'] );   // on order changed
		}
		return $res;
	}

	/**
	 * Receive (part of) a PO line: a receipt movement at the line's cost, qty_received up, the PO
	 * to part_received / received, and the reorder alert re-checked.
	 *
	 * @return int|WP_Error movement id
	 */
	public static function receive_po_line( int $po_line_id, float $qty, int $batch_id = 0, string $location = '' ) {
		if ( ! current_user_can( 'wb_manage_purchasing' ) && ! current_user_can( 'wb_move_stock' ) ) return new WP_Error( 'wb_forbidden', 'You cannot receive stock.' );
		$line = WB_CCT::get( 'wb_po_lines', $po_line_id );
		if ( ! $line ) return new WP_Error( 'wb_not_found', 'Purchase order line not found.' );
		$po = WB_CCT::get( 'wb_purchase_orders', (int) $line['po_id'] );
		if ( ! $po || ! in_array( (string) $po['status'], [ 'sent', 'part_received' ], true ) ) return new WP_Error( 'wb_po_not_open', 'Only a sent purchase order can be received against.' );
		$outstanding = (float) $line['qty_ordered'] - (float) $line['qty_received'];
		if ( $qty <= 0 || $qty > $outstanding + 0.0001 ) return new WP_Error( 'wb_over_receipt', sprintf( 'You can receive up to %s on this line.', $outstanding ) );
		$mid = self::move( (int) $line['product_id'], $qty, 'receipt', [
			'batch_id' => $batch_id, 'unit_cost' => (float) $line['unit_cost'], 'ref_type' => 'purchase_order', 'ref_id' => (int) $po['_ID'],
			'location' => $location, 'reason' => 'Received on ' . (string) $po['po_number'],
		] );
		if ( is_wp_error( $mid ) ) return $mid;
		WB_CCT::update( 'wb_po_lines', $po_line_id, [ 'qty_received' => (float) $line['qty_received'] + $qty ], 'po_line_received' );
		$all  = WB_CCT::find( 'wb_po_lines', [ 'po_id' => (int) $po['_ID'] ] );
		$done = ! array_filter( $all, fn( $l ) => (float) $l['qty_received'] + 0.0001 < (float) $l['qty_ordered'] );
		WB_CCT::update( 'wb_purchase_orders', (int) $po['_ID'], [ 'status' => $done ? 'received' : 'part_received' ], 'po_received' );
		self::check_reorder( (int) $line['product_id'] );
		return $mid;
	}

	/* ================================================================== stocktake: counter + a different checker */

	/** @return int|WP_Error */
	public static function start_stocktake( string $location = '' ) {
		if ( ! current_user_can( 'wb_run_stocktake' ) ) return new WP_Error( 'wb_forbidden', 'You cannot run stocktakes.' );
		$me = WB_Staff::current_staff_id();
		if ( ! $me ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record, so the count cannot carry your name.' );
		$cols = WB_CCT::require_columns( 'wb_stocktakes', [ 'lines_json', 'counted_by_staff_id', 'checked_by_staff_id' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		return WB_CCT::insert( 'wb_stocktakes', [ 'started_at' => current_time( 'mysql' ), 'counted_by_staff_id' => $me, 'status' => 'counting', 'lines_json' => [], 'location' => $location ], 'stocktake_started' );
	}

	/** The counter records what is on the shelf. The system figure is captured at the same moment. */
	public static function record_count( int $stocktake_id, int $product_id, float $counted, int $batch_id = 0 ) {
		$st = WB_CCT::get( 'wb_stocktakes', $stocktake_id );
		if ( ! $st || 'counting' !== $st['status'] ) return new WP_Error( 'wb_not_counting', 'That stocktake is not open for counting.' );
		if ( WB_Staff::current_staff_id() !== (int) $st['counted_by_staff_id'] ) return new WP_Error( 'wb_not_counter', 'Only the person counting can enter counts.' );
		$p = WB_CCT::get( 'wb_products', $product_id );
		if ( ! $p || $counted < 0 ) return new WP_Error( 'wb_bad_line', 'Choose a product and enter what you counted.' );
		$lines = WB_CCT::json( $st['lines_json'] );
		$key   = $product_id . ':' . $batch_id;
		$lines[ $key ] = [ 'product_id' => $product_id, 'batch_id' => $batch_id, 'system' => self::on_hand( $product_id, $batch_id ), 'counted' => $counted, 'unit_cost' => (float) $p['cost_price'], 'at' => current_time( 'mysql' ) ];
		return WB_CCT::update( 'wb_stocktakes', $stocktake_id, [ 'lines_json' => $lines ], 'stocktake_counted' );
	}

	public static function submit_count( int $stocktake_id ) {
		$st = WB_CCT::get( 'wb_stocktakes', $stocktake_id );
		if ( ! $st || 'counting' !== $st['status'] ) return new WP_Error( 'wb_not_counting', 'That stocktake is not open for counting.' );
		if ( WB_Staff::current_staff_id() !== (int) $st['counted_by_staff_id'] ) return new WP_Error( 'wb_not_counter', 'Only the person counting can hand the count over.' );
		$v = self::count_variance( array_values( WB_CCT::json( $st['lines_json'] ) ) );
		$res = WB_CCT::update( 'wb_stocktakes', $stocktake_id, [ 'status' => 'submitted', 'variance_total' => $v['variance_value'] ], 'stocktake_submitted' );
		if ( true === $res ) WB_Notifications::notify_cap( 'wb_run_stocktake', 'stock', sprintf( 'Stocktake #%d is counted and needs a second person to check it (%d lines differ).', $stocktake_id, $v['lines'] ), home_url( '/workspace/stock/?stocktake=' . $stocktake_id ), 'wb_stocktakes', $stocktake_id, [ get_current_user_id() ] );
		return $res;
	}

	/**
	 * The checker (a different person) posts the count: one `count` movement per differing line,
	 * staff_id = the counter, approved_by_staff_id = the checker. Variance is in the ledger with two names.
	 *
	 * @return true|WP_Error
	 */
	public static function post_stocktake( int $stocktake_id ) {
		if ( ! current_user_can( 'wb_run_stocktake' ) ) return new WP_Error( 'wb_forbidden', 'You cannot check stocktakes.' );
		$st = WB_CCT::get( 'wb_stocktakes', $stocktake_id );
		if ( ! $st || 'submitted' !== $st['status'] ) return new WP_Error( 'wb_not_submitted', 'That stocktake is not waiting to be checked.' );
		$me = WB_Staff::current_staff_id();
		if ( ! $me ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record.' );
		if ( $me === (int) $st['counted_by_staff_id'] ) return new WP_Error( 'wb_self_check', 'The checker must be a different person from the counter.' );
		$lines = array_values( WB_CCT::json( $st['lines_json'] ) );
		foreach ( $lines as $l ) {
			$diff = round( (float) $l['counted'] - (float) $l['system'], 3 );
			if ( abs( $diff ) < 0.0001 ) continue;
			// Re-posting after a part failure must not double a line: each line posts once.
			if ( WB_CCT::count( 'wb_stock_movements', [ 'ref_type' => 'stocktake', 'ref_id' => $stocktake_id, 'product_id' => (int) $l['product_id'], 'batch_id' => (int) $l['batch_id'] ], false ) ) continue;
			$mid = self::move( (int) $l['product_id'], $diff, 'count', [
				'batch_id' => (int) $l['batch_id'], 'unit_cost' => (float) $l['unit_cost'], 'ref_type' => 'stocktake', 'ref_id' => $stocktake_id,
				'staff_id' => (int) $st['counted_by_staff_id'], 'approved_by_staff_id' => $me,
				'reason' => sprintf( 'Stocktake #%d: counted %s, system %s', $stocktake_id, $l['counted'], $l['system'] ),
			] );
			if ( is_wp_error( $mid ) ) return $mid;   // lines already posted stay posted (each is its own movement); the stocktake stays submitted
		}
		$v = self::count_variance( $lines );
		return WB_CCT::update( 'wb_stocktakes', $stocktake_id, [ 'status' => 'posted', 'checked_by_staff_id' => $me, 'variance_total' => $v['variance_value'] ], 'stocktake_posted' );
	}
}
