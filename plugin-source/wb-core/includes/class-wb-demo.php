<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Demo — sample data for a demo or a new tenant to look around (administrators only).
 *
 * IDEMPOTENT: seeding twice does nothing the second time. TAGGED: every row the seed creates is
 * recorded in the option wb_demo_registry as it is written (captured from the wb_event stream),
 * so the wipe touches only the demo's own rows — never a row a person made.
 * Only the creation actions in CREATED_ACTIONS are captured; the owner's own staff row and the
 * tenant's leave types are made before the capture window opens and never registered (0.2.1, S5).
 *
 * The wipe, and why it is the one place anything is deleted: demo rows are not business records.
 *   - Numbered documents (quotes, orders, invoices, credit notes, delivery notes, purchase orders)
 *     are NOT deleted: they are voided (record_status = void), so every number series stays
 *     gapless and nothing about the numbering has to be explained to an auditor.
 *   - Everything else the demo wrote (customers, products, stock movements, notes…) is removed,
 *     so stock levels and lists are clean. Each wipe is ledgered with the counts.
 *   - The ledger itself is never touched: the demo's entries stay in the chain.
 *
 * Shortcode: [wb_demo] — seed / wipe buttons for administrators.
 */
class WB_Demo {

	const OPTION = 'wb_demo_registry';
	const NUMBERED = [ 'wb_quotes', 'wb_quote_lines', 'wb_orders', 'wb_order_lines', 'wb_invoices', 'wb_credit_notes', 'wb_delivery_notes', 'wb_purchase_orders', 'wb_po_lines' ];
	const ENGINE   = [ 'wb_reorder_alerts' => 'alerts_table', 'wb_stock_requests' => 'requests_table', 'wb_pricing_approvals' => 'pricing' ];

	/**
	 * The only events the capture records (0.2.1, S5): creations the seed makes. Anything else -
	 * notably the owner's own staff row and the tenant's leave types (leave_type_seeded), which are
	 * made OUTSIDE the capture window anyway - is never captured, so the wipe can never remove it.
	 * Exact action => the record type it must be about.
	 */
	const CREATED_ACTIONS = [
		'staff_added'                   => 'wb_staff',
		'wb_price_tiers_created'        => 'wb_price_tiers',
		'wb_product_categories_created' => 'wb_product_categories',
		'wb_suppliers_created'          => 'wb_suppliers',
		'wb_products_created'           => 'wb_products',
		'wb_customers_created'          => 'wb_customers',
		'wb_contacts_created'           => 'wb_contacts',
		'wb_price_rules_created'        => 'wb_price_rules',
		'po_created'                    => 'wb_purchase_orders',
		'po_line_added'                 => 'wb_po_lines',
		'stock_receipt'                 => 'wb_stock_movements',
		'stock_reserve'                 => 'wb_stock_movements',
		'stock_release'                 => 'wb_stock_movements',
		'stock_sale'                    => 'wb_stock_movements',
		'quote_created'                 => 'wb_quotes',
		'quote_line_priced'             => 'wb_quote_lines',
		'order_created'                 => 'wb_orders',
		'order_line_created'            => 'wb_order_lines',
		'invoice_issued'                => 'wb_invoices',
		'delivery_note_issued'          => 'wb_delivery_notes',
		'reorder_alert_raised'          => 'wb_reorder_alerts',
		'pricing_approval_requested'    => 'wb_pricing_approvals',
	];

	/** Creations by prefix (the action name carries a kind): touchpoint_<type>. */
	const CREATED_PREFIXES = [
		'touchpoint_' => 'wb_touchpoints',
	];

	private static $capture = null;

	/** @var int the owner's own staff row: never captured, whatever happens */
	private static $owner_staff = 0;

	public static function init(): void {
		add_shortcode( 'wb_demo', [ __CLASS__, 'shortcode' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_handle' ] );
	}

	/** Pure: is this event a demo creation the wipe may undo? (S5) */
	public static function is_demo_creation( string $action, string $type, int $id, $before, int $owner_staff = 0 ): bool {
		if ( $id <= 0 || null !== $before ) return false;
		if ( 'wb_staff' === $type && $owner_staff > 0 && $id === $owner_staff ) return false;
		if ( isset( self::CREATED_ACTIONS[ $action ] ) ) return self::CREATED_ACTIONS[ $action ] === $type;
		foreach ( self::CREATED_PREFIXES as $prefix => $t ) {
			if ( 0 === strpos( $action, $prefix ) && $t === $type ) return true;
		}
		return false;
	}

	/** While seeding: remember every row the seed created (see CREATED_ACTIONS). */
	public static function capture( string $action, string $type, int $id, $before = null, $after = null ): void {
		if ( null === self::$capture || ! self::is_demo_creation( $action, $type, $id, $before, self::$owner_staff ) ) return;
		if ( in_array( $type, WB_CCT::SLUGS, true ) || isset( self::ENGINE[ $type ] ) ) self::$capture[ $type ][ $id ] = $id;
	}

	public static function is_seeded(): bool {
		return (bool) get_option( self::OPTION );
	}

	/** @return array|WP_Error what was made */
	public static function seed() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'wb_forbidden', 'Only an administrator can load the demo.' );
		if ( self::is_seeded() ) return new WP_Error( 'wb_seeded', 'The demo is already loaded.' );
		foreach ( [ 'wb_customers', 'wb_products', 'wb_stock_movements', 'wb_quotes', 'wb_orders', 'wb_invoices', 'wb_staff' ] as $need ) {
			if ( ! WB_CCT::table( $need ) ) return new WP_Error( 'wb_missing_table', 'Create the business tables under System Settings first (' . $need . ' is missing).' );
		}
		// Real rows first, OUTSIDE the capture window (S5): the owner's own staff record (made only
		// when missing - it stays theirs) and the tenant's leave types. The wipe never sees them.
		$me = self::owner_staff_row();
		if ( is_wp_error( $me ) ) return $me;
		WB_Staff::ensure_leave_types();

		self::$capture     = [];
		self::$owner_staff = $me;
		add_action( 'wb_event', [ __CLASS__, 'capture' ], 10, 5 );
		$made = [];
		try {
			$made = self::build( $me );
		} finally {
			remove_action( 'wb_event', [ __CLASS__, 'capture' ], 10 );
			$reg = (array) self::$capture;
			if ( $me > 0 ) unset( $reg['wb_staff'][ $me ] );   // belt and braces
			update_option( self::OPTION, array_map( 'array_values', array_filter( $reg ) ), false );
			self::$capture     = null;
			self::$owner_staff = 0;
		}
		wb_ledger_write( 'demo_seeded', 'wb_demo', 0, null, array_map( 'count', (array) get_option( self::OPTION ) ) );
		return $made;
	}

	/**
	 * The administrator's own staff record: the existing one, or a new one made before the capture
	 * window opens - a real record that stays when the demo is removed. @return int|WP_Error
	 */
	private static function owner_staff_row() {
		$me = WB_Staff::current_staff_id();
		if ( $me ) return $me;
		$u  = wp_get_current_user();
		$me = WB_CCT::insert( 'wb_staff', [ 'wp_user_id' => $u->ID, 'first_name' => $u->first_name ?: $u->display_name, 'last_name' => (string) $u->last_name, 'job_title' => 'Owner', 'started_at' => gmdate( 'Y-m-d', strtotime( '-2 years' ) ), 'employment_type' => 'permanent', 'hours_per_week' => 40, 'days_per_week' => 5, 'status' => 'active' ], 'staff_added' );
		return is_wp_error( $me ) ? $me : (int) $me;
	}

	private static function build( int $me ): array {
		$tag  = ' (demo)';
		$out  = [];
		WB_CCT::insert( 'wb_staff', [ 'first_name' => 'Thandi', 'last_name' => 'Mokoena' . $tag, 'job_title' => 'Warehouse lead', 'department' => 'Warehouse', 'started_at' => gmdate( 'Y-m-d', strtotime( '-14 months' ) ), 'employment_type' => 'permanent', 'hours_per_week' => 40, 'days_per_week' => 5, 'status' => 'active' ], 'staff_added' );

		$trade = (int) WB_CCT::insert( 'wb_price_tiers', [ 'name' => 'Trade' . $tag, 'discount_pct' => 10, 'is_default' => 0 ] );
		$cat   = (int) WB_CCT::insert( 'wb_product_categories', [ 'name' => 'Adhesives' . $tag, 'min_margin_pct' => 25 ] );
		$cat2  = (int) WB_CCT::insert( 'wb_product_categories', [ 'name' => 'Sealants' . $tag, 'parent_id' => $cat ] );
		$sup   = (int) WB_CCT::insert( 'wb_suppliers', [ 'name' => 'Coastal Chemicals' . $tag, 'lead_time_days' => 10, 'payment_terms_days' => 30, 'currency' => 'ZAR' ] );
		$valid = [ 'price_valid_from' => gmdate( 'Y-01-01' ), 'price_valid_to' => gmdate( 'Y-12-31' ), 'status' => 'active', 'batch_tracked' => 'no', 'pack_size' => 1, 'lead_time_days' => 10, 'preferred_supplier_id' => $sup ];
		$p = [];
		$p[] = (int) WB_CCT::insert( 'wb_products', [ 'sku' => 'DEMO-ADH-5L', 'name' => 'Contact adhesive 5 L' . $tag, 'category_id' => $cat, 'unit' => 'each', 'cost_price' => 210, 'list_price' => 349, 'reorder_point' => 20, 'reorder_qty' => 60 ] + $valid );
		$p[] = (int) WB_CCT::insert( 'wb_products', [ 'sku' => 'DEMO-SIL-300', 'name' => 'Silicone sealant 300 ml' . $tag, 'category_id' => $cat2, 'unit' => 'each', 'cost_price' => 38, 'list_price' => 69.9, 'reorder_point' => 100, 'reorder_qty' => 240, 'pack_size' => 24 ] + $valid );
		$p[] = (int) WB_CCT::insert( 'wb_products', [ 'sku' => 'DEMO-PU-600', 'name' => 'PU sealant 600 ml' . $tag, 'category_id' => $cat2, 'unit' => 'each', 'cost_price' => 72, 'list_price' => 129, 'reorder_point' => 40, 'reorder_qty' => 120, 'min_margin_pct' => 30 ] + $valid );
		$p[] = (int) WB_CCT::insert( 'wb_products', [ 'sku' => 'DEMO-PRM-1L', 'name' => 'Primer 1 L (price out of date)' . $tag, 'category_id' => $cat, 'unit' => 'each', 'cost_price' => 55, 'list_price' => 99, 'reorder_point' => 10, 'reorder_qty' => 30 ] + array_merge( $valid, [ 'price_valid_to' => gmdate( 'Y-m-d', strtotime( '-1 month' ) ) ] ) );
		$out['products'] = count( $p );

		$cash  = (int) WB_CCT::insert( 'wb_customers', [ 'name' => 'Bayside Hardware' . $tag, 'payment_terms_days' => 0, 'credit_limit' => 0, 'account_status' => 'open', 'currency' => 'ZAR', 'region' => 'Western Cape', 'industry' => 'Retail', 'journey_stage' => 'lead' ] );
		$terms = (int) WB_CCT::insert( 'wb_customers', [ 'name' => 'Karoo Builders' . $tag, 'payment_terms_days' => 30, 'credit_limit' => 50000, 'account_status' => 'open', 'price_tier_id' => $trade, 'currency' => 'ZAR', 'region' => 'Northern Cape', 'industry' => 'Construction', 'journey_stage' => 'lead' ] );
		$hold  = (int) WB_CCT::insert( 'wb_customers', [ 'name' => 'Highveld Glazing' . $tag, 'payment_terms_days' => 30, 'credit_limit' => 20000, 'account_status' => 'on_hold', 'currency' => 'ZAR', 'region' => 'Gauteng', 'industry' => 'Glazing', 'journey_stage' => 'lead' ] );
		WB_CCT::insert( 'wb_contacts', [ 'customer_id' => $terms, 'first_name' => 'Pieter', 'last_name' => 'van Wyk' . $tag, 'email' => 'pieter@example.invalid', 'is_primary' => 1, 'receives_invoices' => 1 ] );
		WB_CCT::insert( 'wb_price_rules', [ 'customer_id' => $terms, 'product_id' => $p[1], 'rule_type' => 'fixed_price', 'value' => 59.5, 'valid_from' => gmdate( 'Y-01-01' ), 'status' => 'approved', 'approved_by_staff_id' => $me, 'approved_at' => current_time( 'mysql' ) ] );
		$out['customers'] = 3;

		// Stock arrives the only way stock arrives: a purchase order, sent, received.
		$po = WB_Stock::create_po( $sup, [ [ 'product_id' => $p[0], 'qty' => 80 ], [ 'product_id' => $p[1], 'qty' => 480 ], [ 'product_id' => $p[2], 'qty' => 30 ], [ 'product_id' => $p[3], 'qty' => 40 ] ], gmdate( 'Y-m-d' ) );
		if ( ! is_wp_error( $po ) ) {
			WB_Stock::set_po_status( (int) $po, 'sent' );
			foreach ( WB_CCT::find( 'wb_po_lines', [ 'po_id' => (int) $po ] ) as $l ) WB_Stock::receive_po_line( (int) $l['_ID'], (float) $l['qty_ordered'], 0, 'Main store' );
			$out['purchase_orders'] = 1;
		}

		// Quotes: one accepted (order + invoice), one sent, one draft with a below-floor price.
		$q1 = WB_Orders::create_quote( $terms, [ [ 'product_id' => $p[0], 'qty' => 12 ], [ 'product_id' => $p[1], 'qty' => 48 ] ] );
		if ( ! is_wp_error( $q1 ) && ! is_wp_error( WB_Orders::send_quote( (int) $q1 ) ) ) {
			$o = WB_Orders::accept_quote( (int) $q1, 'Pieter van Wyk (demo)' );
			if ( ! is_wp_error( $o ) ) $out['orders'] = 1;
		}
		$q2 = WB_Orders::create_quote( $cash, [ [ 'product_id' => $p[2], 'qty' => 6 ] ] );
		if ( ! is_wp_error( $q2 ) ) WB_Orders::send_quote( (int) $q2 );
		WB_Orders::create_quote( $terms, [ [ 'product_id' => $p[2], 'qty' => 24, 'manual_price' => 85 ], [ 'product_id' => $p[3], 'qty' => 5 ] ] );
		$out['quotes'] = 3;
		unset( $hold );
		return $out;
	}

	/** @return array|WP_Error counts */
	public static function wipe() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'wb_forbidden', 'Only an administrator can remove the demo.' );
		$reg = (array) get_option( self::OPTION, [] );
		if ( ! $reg ) return new WP_Error( 'wb_not_seeded', 'There is no demo to remove.' );
		global $wpdb;
		$counts = [ 'voided' => 0, 'removed' => 0 ];
		foreach ( $reg as $type => $ids ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
			if ( ! $ids ) continue;
			$in = implode( ',', $ids );   // integers only
			if ( isset( self::ENGINE[ $type ] ) ) {
				$t = 'pricing' === self::ENGINE[ $type ] ? WB_Pricing::table() : call_user_func( [ 'WB_Stock', self::ENGINE[ $type ] ] );
				$counts['removed'] += (int) $wpdb->query( "DELETE FROM {$t} WHERE id IN ({$in})" );
				continue;
			}
			$t = WB_CCT::table( (string) $type );
			if ( ! $t ) continue;
			if ( in_array( $type, self::NUMBERED, true ) ) {
				$set = "record_status = 'void'" . ( 'wb_invoices' === $type ? ", status = 'void'" : '' );
				$counts['voided'] += (int) $wpdb->query( "UPDATE `{$t}` SET {$set} WHERE _ID IN ({$in})" );
			} else {
				$counts['removed'] += (int) $wpdb->query( "DELETE FROM `{$t}` WHERE _ID IN ({$in})" );   // the documented demo-only exception
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . WB_Notifications::table() . ' WHERE record_type IN (%s, %s, %s) AND record_id IN (' . implode( ',', array_map( 'intval', array_merge( [ 0 ], (array) ( $reg['wb_reorder_alerts'] ?? [] ), (array) ( $reg['wb_stock_requests'] ?? [] ), (array) ( $reg['wb_pricing_approvals'] ?? [] ) ) ) ) . ')', 'wb_reorder_alerts', 'wb_stock_requests', 'wb_pricing_approvals' ) );
		delete_option( self::OPTION );
		WB_CCT::flush_cache();
		wb_ledger_write( 'demo_wiped', 'wb_demo', 0, null, $counts );
		return $counts;
	}

	public static function maybe_handle(): void {
		if ( empty( $_POST['wb_demo'] ) || ! current_user_can( 'manage_options' ) ) return;
		if ( ! wp_verify_nonce( (string) ( $_POST['_wbd'] ?? '' ), 'wb_demo' ) ) return;
		$res = 'wipe' === $_POST['wb_demo'] ? self::wipe() : self::seed();
		WB_RowActions::flash_result( $res, 'wipe' === $_POST['wb_demo'] ? 'Demo removed. Numbered demo documents are kept as void so no number is missing.' : 'Demo loaded.' );
		wp_safe_redirect( wb_return_url( 'settings' ) );
		exit;
	}

	public static function shortcode(): string {
		if ( ! current_user_can( 'manage_options' ) ) return '';
		$seeded = self::is_seeded();
		return WB_RowActions::notice() . '<form method="post" class="wb-inline-form">' . wp_nonce_field( 'wb_demo', '_wbd', true, false ) . wb_return_field()
			. '<input type="hidden" name="wb_demo" value="' . ( $seeded ? 'wipe' : 'seed' ) . '">'
			. '<button type="submit" class="wb-btn wb-btn-ghost" data-wb-confirm="' . esc_attr( $seeded ? 'Remove the demo data? Rows you made yourself are not touched.' : 'Load the demo data?' ) . '"' . ( $seeded ? ' data-wb-danger="1"' : '' ) . '>'
			. esc_html( $seeded ? 'Remove demo data' : 'Load demo data' ) . '</button></form>';
	}
}
