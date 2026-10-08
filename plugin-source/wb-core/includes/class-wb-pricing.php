<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Pricing — THE TWO CHECKS (DATA-ARCHITECTURE §6).
 *
 * Check one — what this customer pays:
 *   customer's product rule → category rule (nearest category first, up the tree) →
 *   the customer's price tier (discount off list) → list price. Returns a price and its source
 *   (rule / tier / list; a typed price is "manual").
 *
 * Check two — what the product allows:
 *   floor   = cost_price × (1 + min_margin_pct / 100)   (product margin, else category, else policy)
 *   ceiling = list_price
 *   the product's price_valid_from / price_valid_to must cover the quote date, else the line is
 *   flagged "price out of date". A price below the floor — or out of date, or for a product with
 *   no cost on file (the floor can't be checked: fail closed) — needs an approval row in
 *   wp_wb_pricing_approvals with a name on it before the quote can be sent.
 *
 * Both results are frozen onto the quote line (unit_price, price_source, floor_price, below_floor)
 * by WB_Orders, so a later cost change never rewrites history.
 *
 * All the arithmetic is in PURE static functions over plain arrays (check_one, check_two,
 * rule_price, rule_applies, margin_pct_for, evaluate) — testable without WordPress.
 */
class WB_Pricing {

	const RULE_TYPES = [ 'fixed_price', 'pct_off_list', 'pct_on_cost' ];

	/* ================================================================== pure */

	/** Is $date (Y-m-d) inside [from, to]? Empty bounds are open. */
	public static function in_window( string $date, $from, $to ): bool {
		$from = substr( (string) $from, 0, 10 );
		$to   = substr( (string) $to, 0, 10 );
		if ( '' !== $from && '0000-00-00' !== $from && $date < $from ) return false;
		if ( '' !== $to && '0000-00-00' !== $to && $date > $to ) return false;
		return true;
	}

	/** A rule counts when it is approved, active, for this customer, and its window covers the date. */
	public static function rule_applies( array $rule, int $customer_id, string $date ): bool {
		if ( 'approved' !== (string) ( $rule['status'] ?? '' ) ) return false;
		if ( in_array( (string) ( $rule['record_status'] ?? 'active' ), [ 'archived', 'inactive', 'void' ], true ) ) return false;
		if ( (int) ( $rule['customer_id'] ?? 0 ) !== $customer_id ) return false;
		if ( ! in_array( (string) ( $rule['rule_type'] ?? '' ), self::RULE_TYPES, true ) ) return false;
		return self::in_window( $date, $rule['valid_from'] ?? '', $rule['valid_to'] ?? '' );
	}

	/** The price a rule gives. */
	public static function rule_price( array $rule, float $list, float $cost ): float {
		$v = (float) ( $rule['value'] ?? 0 );
		switch ( (string) $rule['rule_type'] ) {
			case 'fixed_price':  return self::round( $v );
			case 'pct_off_list': return self::round( $list * ( 1 - $v / 100 ) );
			case 'pct_on_cost':  return self::round( $cost * ( 1 + $v / 100 ) );
		}
		return self::round( $list );
	}

	/** Among several applicable rules at the same level: the most recently started wins, then the newest row. */
	private static function pick( array $rules ): ?array {
		if ( ! $rules ) return null;
		usort( $rules, function ( $a, $b ) {
			$c = strcmp( (string) ( $b['valid_from'] ?? '' ), (string) ( $a['valid_from'] ?? '' ) );
			return 0 !== $c ? $c : ( (int) ( $b['_ID'] ?? 0 ) <=> (int) ( $a['_ID'] ?? 0 ) );
		} );
		return $rules[0];
	}

	/**
	 * CHECK ONE. $rules = the customer's price rules (any; filtered here); $tier = the customer's
	 * tier row or null; $category_chain = the product's category id, then its parent, … (nearest first).
	 * Returns [ unit_price, price_source, rule_id ].
	 */
	public static function check_one( array $product, int $customer_id, array $rules, ?array $tier, string $date, array $category_chain = [] ): array {
		$list = (float) ( $product['list_price'] ?? 0 );
		$cost = (float) ( $product['cost_price'] ?? 0 );
		$pid  = (int) ( $product['_ID'] ?? 0 );
		$live = array_values( array_filter( $rules, fn( $r ) => self::rule_applies( $r, $customer_id, $date ) ) );

		$product_rule = self::pick( array_filter( $live, fn( $r ) => (int) ( $r['product_id'] ?? 0 ) === $pid && $pid > 0 ) );
		if ( $product_rule ) {
			return [ 'unit_price' => self::rule_price( $product_rule, $list, $cost ), 'price_source' => 'rule', 'rule_id' => (int) ( $product_rule['_ID'] ?? 0 ) ];
		}
		foreach ( $category_chain as $cat ) {
			$cat_rule = self::pick( array_filter( $live, fn( $r ) => empty( $r['product_id'] ) && (int) ( $r['category_id'] ?? 0 ) === (int) $cat && (int) $cat > 0 ) );
			if ( $cat_rule ) {
				return [ 'unit_price' => self::rule_price( $cat_rule, $list, $cost ), 'price_source' => 'rule', 'rule_id' => (int) ( $cat_rule['_ID'] ?? 0 ) ];
			}
		}
		if ( $tier && (float) ( $tier['discount_pct'] ?? 0 ) > 0 && ! in_array( (string) ( $tier['record_status'] ?? 'active' ), [ 'archived', 'inactive', 'void' ], true ) ) {
			return [ 'unit_price' => self::round( $list * ( 1 - (float) $tier['discount_pct'] / 100 ) ), 'price_source' => 'tier', 'rule_id' => 0 ];
		}
		return [ 'unit_price' => self::round( $list ), 'price_source' => 'list', 'rule_id' => 0 ];
	}

	/** The minimum margin that applies: the product's own, else the nearest category's, else the policy default. */
	public static function margin_pct_for( array $product, array $categories_nearest_first, float $default ): float {
		if ( isset( $product['min_margin_pct'] ) && '' !== (string) $product['min_margin_pct'] && null !== $product['min_margin_pct'] ) {
			return (float) $product['min_margin_pct'];
		}
		foreach ( $categories_nearest_first as $c ) {
			if ( isset( $c['min_margin_pct'] ) && '' !== (string) $c['min_margin_pct'] && null !== $c['min_margin_pct'] ) return (float) $c['min_margin_pct'];
		}
		return $default;
	}

	/**
	 * CHECK TWO. Returns floor_price, ceiling_price, below_floor, above_ceiling, out_of_date,
	 * no_cost, needs_approval, margin_pct (the margin this price actually makes, on price).
	 */
	public static function check_two( array $product, float $unit_price, string $date, float $min_margin_pct ): array {
		$cost    = (float) ( $product['cost_price'] ?? 0 );
		$list    = (float) ( $product['list_price'] ?? 0 );
		$floor   = self::round( $cost * ( 1 + $min_margin_pct / 100 ) );
		$no_cost = $cost <= 0;
		$below   = ! $no_cost && $unit_price < $floor - 0.004;   // cents, not float noise
		$above   = $list > 0 && $unit_price > $list + 0.004;
		$stale   = ! self::in_window( $date, $product['price_valid_from'] ?? '', $product['price_valid_to'] ?? '' );
		return [
			'floor_price'    => $floor,
			'ceiling_price'  => self::round( $list ),
			'below_floor'    => $below,
			'above_ceiling'  => $above,
			'out_of_date'    => $stale,
			'no_cost'        => $no_cost,
			'needs_approval' => $below || $stale || $no_cost || $above,   // above the list price (the ceiling) needs a name on it too
			'margin_pct'     => $unit_price > 0 ? round( ( $unit_price - $cost ) / $unit_price * 100, 2 ) : 0.0,
		];
	}

	/**
	 * Both checks, the line's full pricing record. $manual_price (typed by the rep) replaces check
	 * one's price — source "manual" — and is still put through check two.
	 */
	public static function evaluate( array $product, int $customer_id, array $rules, ?array $tier, string $date, array $categories_nearest_first, float $default_margin, ?float $manual_price = null, float $qty = 1 ): array {
		$chain = array_map( fn( $c ) => (int) ( $c['_ID'] ?? 0 ), $categories_nearest_first );
		$one   = self::check_one( $product, $customer_id, $rules, $tier, $date, $chain );
		if ( null !== $manual_price ) {
			$one = [ 'unit_price' => self::round( $manual_price ), 'price_source' => 'manual', 'rule_id' => 0 ];
		}
		$two = self::check_two( $product, (float) $one['unit_price'], $date, self::margin_pct_for( $product, $categories_nearest_first, $default_margin ) );
		$list = (float) ( $product['list_price'] ?? 0 );
		return $one + $two + [
			'list_price'   => self::round( $list ),
			'cost_price'   => self::round( (float) ( $product['cost_price'] ?? 0 ) ),
			'discount_pct' => $list > 0 ? round( ( 1 - $one['unit_price'] / $list ) * 100, 2 ) : 0.0,
			'qty'          => $qty,
			'line_total'   => self::round( $qty * (float) $one['unit_price'] ),
		];
	}

	public static function round( float $v ): float {
		return round( $v, 2, PHP_ROUND_HALF_UP );
	}

	/** A frozen quote line priced above its list price (the ceiling). */
	public static function line_above_list( array $line ): bool {
		$list = (float) ( $line['list_price'] ?? 0 );
		return $list > 0 && (float) ( $line['unit_price'] ?? 0 ) > $list + 0.004;
	}

	/** Does a frozen quote line need a price approval? Below floor, out of date, no cost on file, or above list. */
	/**
	 * Why a frozen line broke a rule, in a sentence (1.2.0, Zina: "if a price breaks a rule do we have
	 * a way to show that?"). '' when nothing is wrong. Pure; shown beside the line and on the approve
	 * queue, so the person who set the price and the person who decides read the same words.
	 */
	public static function explain( array $line ): string {
		$m     = fn( $v ) => number_format( (float) $v, 2, '.', ' ' );
		$price = (float) ( $line['unit_price'] ?? 0 );
		$floor = (float) ( $line['floor_price'] ?? 0 );
		$cost  = (float) ( $line['cost_price'] ?? 0 );
		$list  = (float) ( $line['list_price'] ?? 0 );
		$why   = [];
		if ( $cost <= 0 ) $why[] = 'The product has no cost price on file, so the lowest allowed price cannot be worked out.';
		elseif ( 'yes' === (string) ( $line['below_floor'] ?? '' ) ) {
			$margin = $floor > 0 && $cost > 0 ? round( ( $floor / $cost - 1 ) * 100, 1 ) : 0;
			$why[]  = 'Below the lowest allowed price: R ' . $m( $price ) . ' is under R ' . $m( $floor ) . ' (cost R ' . $m( $cost ) . ( $margin > 0 ? ' + ' . rtrim( rtrim( number_format( $margin, 1, '.', '' ), '0' ), '.' ) . '% margin' : '' ) . ').';
		}
		if ( 'yes' === (string) ( $line['out_of_date'] ?? '' ) ) $why[] = "The product's price is out of date: today is outside its valid-from and valid-to dates.";
		if ( $list > 0 && $price > $list + 0.004 ) $why[] = 'Above the list price: R ' . $m( $price ) . ' is more than the list price of R ' . $m( $list ) . '.';
		return implode( ' ', $why );
	}

	public static function line_flagged( array $line ): bool {
		return 'yes' === (string) ( $line['below_floor'] ?? '' ) || 'yes' === (string) ( $line['out_of_date'] ?? '' )
			|| (float) ( $line['cost_price'] ?? 1 ) <= 0 || self::line_above_list( $line );
	}

	/* ================================================================== WordPress side */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wb_pricing_approvals';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE " . self::table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			quote_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			quote_line_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reason VARCHAR(40) NOT NULL DEFAULT 'below_floor',
			floor_price DECIMAL(14,2) NOT NULL DEFAULT 0,
			asked_price DECIMAL(14,2) NOT NULL DEFAULT 0,
			cost_price DECIMAL(14,2) NOT NULL DEFAULT 0,
			margin_pct DECIMAL(7,2) NOT NULL DEFAULT 0,
			requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			requested_at DATETIME NOT NULL,
			decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			decision VARCHAR(12) NOT NULL DEFAULT 'pending',
			decided_at DATETIME NULL,
			note VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY quote (quote_id),
			KEY decision (decision),
			KEY decided_by (decided_by)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	public static function policy(): array {
		return wp_parse_args( (array) get_option( 'wb_pricing', [] ), [ 'default_min_margin_pct' => 20, 'quote_validity_days' => 30 ] );
	}

	/** The product's category, then its parents (nearest first). Stops on a loop. */
	public static function category_chain( int $category_id ): array {
		$out  = [];
		$seen = [];
		while ( $category_id > 0 && ! isset( $seen[ $category_id ] ) && count( $out ) < 10 ) {
			$seen[ $category_id ] = true;
			$c = WB_CCT::get( 'wb_product_categories', $category_id );
			if ( ! $c ) break;
			$out[]       = $c;
			$category_id = (int) ( $c['parent_id'] ?? 0 );
		}
		return $out;
	}

	/**
	 * Price a line for a customer: both checks, from the live tables.
	 * Returns the evaluate() array (unit_price, price_source, floor_price, below_floor, out_of_date,
	 * needs_approval, …) or WP_Error. Fails closed when the product or the customer is missing.
	 *
	 * @return array|WP_Error
	 */
	public static function price_for( int $customer_id, int $product_id, float $qty = 1, string $on_date = '', ?float $manual_price = null ) {
		$on_date  = '' !== $on_date ? substr( $on_date, 0, 10 ) : wb_today();
		$product  = WB_CCT::get( 'wb_products', $product_id );
		$customer = WB_CCT::get( 'wb_customers', $customer_id );
		if ( ! $product ) return new WP_Error( 'wb_no_product', 'That product could not be found, so it cannot be priced.' );
		if ( ! $customer ) return new WP_Error( 'wb_no_customer', 'That customer could not be found, so the price cannot be checked.' );
		if ( 'discontinued' === (string) ( $product['status'] ?? '' ) ) return new WP_Error( 'wb_discontinued', 'That product is discontinued.' );
		$rules = WB_CCT::find( 'wb_price_rules', [ 'customer_id' => $customer_id, 'status' => 'approved' ] );
		$tier  = ! empty( $customer['price_tier_id'] ) ? WB_CCT::get( 'wb_price_tiers', (int) $customer['price_tier_id'] ) : WB_CCT::first( 'wb_price_tiers', [ 'is_default' => [ '1', 'true' ] ] );   // JetEngine switchers store "true"
		$cats  = self::category_chain( (int) ( $product['category_id'] ?? 0 ) );
		return self::evaluate( $product, $customer_id, $rules, $tier, $on_date, $cats, (float) self::policy()['default_min_margin_pct'], $manual_price, $qty );
	}

	/**
	 * Ask for a below-floor (or out-of-date / no-cost) price to be approved. One pending request
	 * per quote line. Notifies the people who approve prices — never the requester.
	 *
	 * @return int|WP_Error approval id
	 */
	public static function request_approval( int $quote_line_id, string $note = '' ) {
		if ( ! current_user_can( 'wb_create_quotes' ) ) return new WP_Error( 'wb_forbidden', 'You cannot write quotes.' );
		$line = WB_CCT::get( 'wb_quote_lines', $quote_line_id );
		if ( ! $line ) return new WP_Error( 'wb_not_found', 'Quote line not found.' );
		$quote = WB_CCT::get( 'wb_quotes', (int) $line['quote_id'] );
		if ( ! $quote || 'draft' !== $quote['status'] ) return new WP_Error( 'wb_quote_locked', 'Prices can only be put up for approval while the quote is a draft.' );
		global $wpdb;
		$t   = self::table();
		$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE quote_line_id = %d AND decision = 'pending'", $quote_line_id ) );
		if ( $has ) return $has;
		if ( ! self::line_flagged( $line ) ) return new WP_Error( 'wb_no_approval_needed', 'This price is within the floor and the list price, so it needs no approval.' );
		$reason = 'yes' === (string) ( $line['below_floor'] ?? '' ) ? 'below_floor' : ( 'yes' === (string) ( $line['out_of_date'] ?? '' ) ? 'out_of_date' : ( (float) ( $line['cost_price'] ?? 1 ) <= 0 ? 'no_cost' : 'above_list' ) );
		$price  = (float) $line['unit_price'];
		$cost   = (float) ( $line['cost_price'] ?? 0 );
		$row    = [
			'quote_id'      => (int) $quote['_ID'],
			'quote_line_id' => $quote_line_id,
			'customer_id'   => (int) $quote['customer_id'],
			'product_id'    => (int) $line['product_id'],
			'reason'        => $reason,
			'floor_price'   => (float) $line['floor_price'],
			'asked_price'   => $price,
			'cost_price'    => $cost,
			'margin_pct'    => $price > 0 ? round( ( $price - $cost ) / $price * 100, 2 ) : 0,
			'requested_by'  => get_current_user_id(),
			'requested_at'  => wb_now(),
			'note'          => mb_substr( sanitize_text_field( $note ), 0, 255 ),
		];
		if ( false === $wpdb->insert( $t, $row ) ) return new WP_Error( 'wb_insert_failed', 'The approval request could not be saved.' );
		$id = (int) $wpdb->insert_id;
		wb_ledger_write( 'pricing_approval_requested', 'wb_pricing_approvals', $id, null, $row );
		WB_CCT::update( 'wb_quote_lines', $quote_line_id, [ 'approval_id' => $id ], 'quote_line_approval_linked' );
		WB_Notifications::notify_cap( 'wb_approve_pricing', 'orders',
			'above_list' === $reason
				? sprintf( 'Price approval needed on quote %s: %s asked, above the list price of %s.', (string) $quote['quote_number'], number_format( $price, 2 ), number_format( (float) ( $line['list_price'] ?? 0 ), 2 ) )
				: sprintf( 'Price approval needed on quote %s: %s asked, floor %s.', (string) $quote['quote_number'], number_format( $price, 2 ), number_format( (float) $line['floor_price'], 2 ) ),
			WB_Workspace::url( 'quotes', [ 'approvals' => 1 ] ), 'wb_pricing_approvals', $id, [ get_current_user_id() ] );
		return $id;
	}

	/**
	 * Approve or decline. Needs wb_approve_pricing, and the decider can never be the requester.
	 *
	 * @return true|WP_Error
	 */
	public static function decide( int $approval_id, bool $approve, string $note = '' ) {
		if ( ! current_user_can( 'wb_approve_pricing' ) ) return new WP_Error( 'wb_forbidden', 'You cannot approve prices.' );
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $approval_id ), ARRAY_A );
		if ( ! $row ) return new WP_Error( 'wb_not_found', 'Approval request not found.' );
		if ( 'pending' !== $row['decision'] ) return new WP_Error( 'wb_decided', 'That request has already been decided.' );
		if ( (int) $row['requested_by'] === get_current_user_id() ) return new WP_Error( 'wb_self_approval', 'You cannot approve your own price request — someone else must.' );
		// Self-approval by proxy: whoever set the price on the line may not approve it either,
		// even when a colleague pressed "ask for approval".
		$cols = WB_CCT::require_columns( 'wb_quote_lines', [ 'priced_by_user_id' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		$line = WB_CCT::get( 'wb_quote_lines', (int) $row['quote_line_id'] );
		if ( $line && (int) $line['priced_by_user_id'] === get_current_user_id() ) return new WP_Error( 'wb_self_approval', 'You set this price, so someone else must approve it.' );
		$upd = [ 'decided_by' => get_current_user_id(), 'decision' => $approve ? 'approved' : 'declined', 'decided_at' => wb_now(), 'note' => mb_substr( trim( $row['note'] . ' ' . sanitize_text_field( $note ) ), 0, 255 ) ];
		$n   = $wpdb->update( $t, $upd, [ 'id' => $approval_id, 'decision' => 'pending' ] );   // claims the request: only one decision wins
		if ( false === $n ) return new WP_Error( 'wb_update_failed', 'The decision could not be saved.' );
		if ( 1 !== (int) $n ) return new WP_Error( 'wb_decided', 'Someone else decided that request a moment ago.' );
		wb_ledger_write( 'pricing_approval_' . $upd['decision'], 'wb_pricing_approvals', $approval_id, [ 'decision' => 'pending' ], $upd );
		self::refresh_quote_status( (int) $row['quote_id'] );
		WB_Notifications::notify( (int) $row['requested_by'], 'orders', sprintf( 'Your price request on quote line #%d was %s.', (int) $row['quote_line_id'], $upd['decision'] ), WB_Workspace::url( 'quotes' ), 'wb_pricing_approvals', $approval_id );
		return true;
	}

	/** passed (nothing to approve) / needs_approval / approved (every flagged line has an approved request). */
	public static function refresh_quote_status( int $quote_id ): string {
		$lines   = WB_CCT::find( 'wb_quote_lines', [ 'quote_id' => $quote_id ], [ 'limit' => 1000 ] );
		$flagged = array_filter( $lines, [ __CLASS__, 'line_flagged' ] );
		$status  = 'passed';
		if ( is_wp_error( WB_CCT::require_columns( 'wb_quote_lines', [ 'below_floor', 'out_of_date', 'cost_price', 'list_price', 'unit_price' ] ) ) ) {
			$status = 'needs_approval';   // the checks cannot be read: fail closed
		} elseif ( $flagged ) {
			global $wpdb;
			$status = 'approved';
			foreach ( $flagged as $l ) {
				$ok = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE quote_line_id = %d AND decision = 'approved' AND asked_price = %f", (int) $l['_ID'], (float) $l['unit_price'] ) );
				if ( ! $ok ) { $status = 'needs_approval'; break; }
			}
		}
		$q = WB_CCT::get( 'wb_quotes', $quote_id );
		if ( $q && (string) $q['pricing_check_status'] !== $status ) {
			WB_CCT::update( 'wb_quotes', $quote_id, [ 'pricing_check_status' => $status ], 'quote_pricing_status' );
		}
		return $status;
	}

	public static function pending( int $limit = 200 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE decision = 'pending' ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A );
	}
}
