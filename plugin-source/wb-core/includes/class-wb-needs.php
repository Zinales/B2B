<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Needs — what is waiting on a person (0.3.4, Kaycie's KC_Needs / KC_Exceptions).
 *
 * One list of lines, each with a key, plain words, a count, the capability that makes it this
 * person's to clear, the screen it lands on and a kind: 'waiting' (someone must act before the
 * business can move: approvals, matches, checks) or 'open' (unfinished but not blocking: overdue
 * invoices, reorder alerts). Today's "Needs attention" card, the count on the side menu and the
 * bubble on the top bar all read this one list, so they can never disagree.
 *
 * Every count is column-safe and fails to zero: a missing table or a broken query is never a
 * blank page. Counts are cached for the request.
 */
class WB_Needs {

	/** The verb on the button for each line (first word a person reads). */
	const VERBS = [
		'prices'      => 'Decide', 'stock_requests' => 'Decide', 'credit_notes' => 'Decide', 'payments' => 'Match', 'suggested' => 'Confirm',
		'timesheets'  => 'Approve', 'leave' => 'Approve', 'stocktakes' => 'Check', 'pay_run' => 'Check', 'portal' => 'Answer', 'ledger' => 'Look',
		'overdue'     => 'Chase', 'reorder' => 'Reorder', 'quotes_expiring' => 'Follow up', 'ready' => 'Dispatch',
	];

	private static ?array $cache = null;

	/** Every line, counted, regardless of who is asking. [ [ key, kind, label, count, cap, slug, args ], … ] */
	public static function all(): array {
		if ( null !== self::$cache ) return self::$cache;
		$lines = [
			[ 'prices',         'waiting', 'Prices below the floor to decide',        fn() => count( WB_Pricing::pending() ),                                                        'wb_approve_pricing',     'quotes',   [ 'approvals' => 1 ] ],
			[ 'stock_requests', 'waiting', 'Stock corrections to decide',            fn() => count( WB_Stock::pending_requests() ),                                                 'wb_approve_adjustments', 'stock',    [ 'requests' => 1 ] ],
			[ 'credit_notes',   'waiting', 'Credit notes to approve',                fn() => WB_CCT::count( 'wb_credit_notes', [ 'status' => 'requested' ] ),                       'wb_approve_credit_notes','invoices', [ 'credits' => 1 ] ],
			[ 'suggested',      'waiting', 'Payments to confirm',                    fn() => WB_CCT::count( 'wb_payments', [ 'match_status' => 'suggested' ] ),                      'wb_match_payments',      'payments', [] ],
			[ 'payments',       'waiting', 'Payments to match by hand',              fn() => WB_CCT::count( 'wb_payments', [ 'match_status' => 'unmatched' ] ),                      'wb_match_payments',      'payments', [] ],
			[ 'timesheets',     'waiting', 'Timesheets to approve',                  fn() => WB_CCT::count( 'wb_timesheets', [ 'status' => 'submitted' ] ),                          'wb_approve_timesheets',  'staff',    [] ],
			[ 'leave',          'waiting', 'Leave requests to approve',              fn() => WB_CCT::count( 'wb_leave', [ 'status' => 'requested' ] ),                               'wb_approve_leave',       'staff',    [ 'leave' => 1 ] ],
			[ 'stocktakes',     'waiting', 'Stocktakes counted, waiting for a check', fn() => WB_CCT::count( 'wb_stocktakes', [ 'status' => 'counted' ] ),                            'wb_run_stocktake',       'stock',    [] ],
			[ 'pay_run',        'waiting', 'Pay runs waiting for a check',           fn() => WB_CCT::count( 'wb_pay_runs', [ 'status' => 'draft' ] ),                                'wb_check_payroll',       'payroll',  [] ],
			[ 'portal',         'waiting', 'Customer requests to answer',            fn() => WB_CCT::count( 'wb_portal_requests', [ 'status' => 'pending' ] ),                       'wb_manage_customers',    'customers',[] ],
			[ 'ledger',         'waiting', 'Audit entries waiting to be chained',    fn() => WB_Ledger::pending_count(),                                                             'wb_view_integrity',      'integrity',[] ],
			[ 'overdue',        'open',    'Invoices overdue',                       fn() => WB_CCT::count( 'wb_invoices', [ 'status' => 'overdue' ] ),                              'wb_match_payments',      'invoices', [] ],
			[ 'reorder',        'open',    'Products below their reorder point',     fn() => count( WB_Stock::open_alerts() ),                                                       'wb_manage_purchasing',   'purchasing',[] ],
			[ 'ready',          'open',    'Orders ready to go out',                 fn() => WB_CCT::count( 'wb_orders', [ 'status' => [ 'ready', 'part_delivered' ] ] ),           'wb_issue_delivery_notes','deliveries',[] ],
		];
		$out = [];
		foreach ( $lines as [ $key, $kind, $label, $count, $cap, $slug, $args ] ) {
			try { $n = (int) $count(); } catch ( Throwable $e ) { $n = 0; }
			$out[] = [ 'key' => $key, 'kind' => $kind, 'label' => $label, 'count' => $n, 'cap' => $cap, 'slug' => $slug, 'args' => $args ];
		}
		return self::$cache = $out;
	}

	/** The lines this person can clear, with something on them, waiting first then by count. */
	public static function mine( ?callable $can = null ): array {
		$can  = $can ?: fn( string $c ): bool => current_user_can( $c );
		$rows = array_values( array_filter( self::all(), fn( $l ) => $l['count'] > 0 && $can( $l['cap'] ) ) );
		return self::sort( $rows );
	}

	/** Waiting before open, then the bigger number first, then the list order. Pure, so it is tested. */
	public static function sort( array $rows ): array {
		$i = 0;
		foreach ( $rows as &$r ) $r['_i'] = $i++;
		unset( $r );
		usort( $rows, function ( $a, $b ) {
			if ( $a['kind'] !== $b['kind'] ) return 'waiting' === $a['kind'] ? -1 : 1;
			if ( $a['count'] !== $b['count'] ) return $b['count'] <=> $a['count'];
			return $a['_i'] <=> $b['_i'];
		} );
		foreach ( $rows as &$r ) unset( $r['_i'] );
		return $rows;
	}

	/** How many things are waiting on this person (the number on the menu and the top bar). */
	public static function waiting_count( ?callable $can = null ): int {
		$n = 0;
		foreach ( self::mine( $can ) as $l ) if ( 'waiting' === $l['kind'] ) $n += $l['count'];
		return $n;
	}

	/** The sentence under "Good morning": pure, so it is tested. */
	public static function lede( int $waiting, int $open ): string {
		if ( 0 === $waiting && 0 === $open ) return 'Nothing is waiting on you, and nothing is still open.';
		$w = 0 === $waiting ? 'Nothing is waiting on you' : ( 1 === $waiting ? 'One thing is waiting on you' : number_format( $waiting ) . ' things are waiting on you' );
		$o = 0 === $open ? '' : ( 1 === $open ? ', and one thing is still open' : ', and ' . number_format( $open ) . ' are still open' );
		return $w . $o . '.';
	}

	/** "Good morning" by the site's clock. */
	public static function greeting( int $hour ): string {
		if ( $hour < 12 ) return 'Good morning';
		if ( $hour < 17 ) return 'Good afternoon';
		return 'Good evening';
	}

	/** The verb for a line's button. */
	public static function verb( string $key ): string {
		return self::VERBS[ $key ] ?? 'Open';
	}

	/** The card on Today: the list with a verb per line, or the clear state. */
	public static function card( ?callable $can = null ): string {
		$mine = self::mine( $can );
		$h    = '<section class="wb-card wb-card--lead" id="wb-waiting" aria-labelledby="wb-waiting-h"><h2 id="wb-waiting-h">Needs attention</h2>';
		if ( ! $mine ) return $h . '<p class="wb-muted">Nothing is waiting on you. Nice.</p></section>';
		$h .= '<ul class="wb-needs">';
		foreach ( array_slice( $mine, 0, 8 ) as $i => $l ) {
			$dot = 'waiting' === $l['kind'] ? ( $l['count'] >= 5 ? ' is-hot' : '' ) : ' is-open';
			$h  .= '<li><span class="wb-needs-dot' . $dot . '" aria-hidden="true"></span><span class="wb-needs-t">' . esc_html( $l['label'] ) . '<small>' . ( 'waiting' === $l['kind'] ? 'Waiting on you' : 'Still open' ) . '</small></span>'
				. '<a class="wb-btn wb-btn-sm' . ( 0 === $i ? '' : ' wb-btn-ghost' ) . '" href="' . esc_url( WB_Workspace::url( $l['slug'], $l['args'] ) ) . '">' . esc_html( self::verb( $l['key'] ) ) . ' <b class="wb-needs-n">' . (int) $l['count'] . '</b><span class="wb-sr">, ' . esc_html( strtolower( $l['label'] ) ) . '</span></a></li>';
		}
		return $h . '</ul></section>';
	}
}
