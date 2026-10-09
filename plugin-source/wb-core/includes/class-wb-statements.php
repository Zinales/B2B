<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Statements — a customer's statement as a PDF, and the chasing that goes with it (1.5.0; the
 * Chase fold and the monthly run follow in 1.6).
 *
 * An open-item statement: every invoice still owing, oldest first, with how late it is, then the
 * ageing (not yet due, 1–30, 31–60, 61–90, over 90 days) and the bank details with the reference
 * to use. It is made fresh whenever it is asked for: it describes today, so a stored copy would be
 * wrong tomorrow. When one is emailed, the ledger records the date, the total and the ageing.
 *
 * html() is pure; WB_Pages::ageing() does the arithmetic.
 */
class WB_Statements {

	/**
	 * The statement. $d: [ customer: [name, billing_address, vat_number], date, invoices: [ rows ],
	 *   ageing: WB_Pages::ageing(), reference ]. $brand: as WB_Docs::html().
	 */
	public static function html( array $d, array $brand ): string {
		$e    = fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$m    = fn( $v ) => WB_Docs::money( $v );
		$name = (string) ( $brand['legal_name'] ?: $brand['display_name'] );
		$co   = array_filter( [ '' !== (string) ( $brand['reg_number'] ?? '' ) ? 'Reg. ' . $brand['reg_number'] : '', 'yes' === (string) ( $brand['vat_registered'] ?? 'yes' ) && '' !== (string) ( $brand['vat_number'] ?? '' ) ? 'VAT ' . $brand['vat_number'] : '', (string) ( $brand['physical_address'] ?? '' ) ] );
		$c    = (array) $d['customer'];
		$t    = strtotime( (string) $d['date'] );
		$h = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e( 'Statement ' . $c['name'] ) . '</title><style>' . WB_Docs::css( $brand['colors'] ) . '.late{color:#B42318;font-weight:bold}table.age{width:100%;border-collapse:collapse;margin:10px 0}table.age td{border:1px solid #E6DCCF;padding:6px;text-align:center;font-size:8.5pt}table.age .k{color:#47586D;font-size:7.5pt;text-transform:uppercase;letter-spacing:.06em}</style></head><body>';
		$h .= '<table class="head"><tr><td>' . ( '' !== (string) ( $brand['logo'] ?? '' ) ? '<img class="logo" src="' . $e( $brand['logo'] ) . '" alt="">' : '<div class="brand">' . $e( $name ) . '</div>' )
			. '</td><td class="co" style="text-align:right">' . $e( $name ) . '<br>' . implode( '<br>', array_map( fn( $l ) => nl2br( $e( $l ) ), $co ) ) . '</td></tr></table>';
		$h .= '<h1>Statement</h1><div class="num">' . $e( $c['name'] ) . '</div>';
		$h .= '<table class="meta"><tr><td style="width:50%"><div class="k">Customer</div><strong>' . $e( $c['name'] ) . '</strong>' . ( '' !== (string) ( $c['billing_address'] ?? '' ) ? '<br>' . nl2br( $e( $c['billing_address'] ) ) : '' ) . ( '' !== (string) ( $c['vat_number'] ?? '' ) ? '<br>VAT ' . $e( $c['vat_number'] ) : '' )
			. '</td><td><div class="k">Date</div><div style="margin-bottom:5px">' . $e( $d['date'] ) . '</div><div class="k">Balance owing</div><div style="font-size:12pt;font-weight:bold">R ' . $e( $m( $d['ageing']['total'] ) ) . '</div></td></tr></table>';
		$h .= '<table class="lines"><thead><tr><th>Date</th><th>Invoice</th><th>Due</th><th class="n">Total</th><th class="n">Paid or credited</th><th class="n">Still owing</th><th class="n">Days late</th></tr></thead><tbody>';
		$any = false;
		foreach ( (array) $d['invoices'] as $i ) {
			$left = round( (float) $i['total'] - (float) ( $i['amount_paid'] ?? 0 ) - (float) ( $i['amount_credited'] ?? 0 ), 2 );
			if ( $left <= 0.004 ) continue;
			$any  = true;
			$late = '' !== (string) ( $i['due_at'] ?? '' ) ? (int) floor( ( $t - strtotime( substr( (string) $i['due_at'], 0, 10 ) ) ) / 86400 ) : 0;
			$h   .= '<tr><td>' . $e( substr( (string) $i['issued_at'], 0, 10 ) ) . '</td><td>' . $e( $i['invoice_number'] ) . '</td><td>' . $e( substr( (string) $i['due_at'], 0, 10 ) ) . '</td><td class="n">' . $e( $m( $i['total'] ) ) . '</td><td class="n">' . $e( $m( (float) ( $i['amount_paid'] ?? 0 ) + (float) ( $i['amount_credited'] ?? 0 ) ) ) . '</td><td class="n">' . $e( $m( $left ) ) . '</td><td class="n' . ( $late > 0 ? ' late' : '' ) . '">' . ( $late > 0 ? $late : '' ) . '</td></tr>';
		}
		if ( ! $any ) $h .= '<tr><td colspan="7">Nothing is owing. Thank you.</td></tr>';
		$h .= '</tbody></table><table class="age"><tr>';
		foreach ( WB_Pages::BUCKETS as $k => $words ) $h .= '<td><div class="k">' . $e( $words ) . '</div>R ' . $e( $m( $d['ageing'][ $k ] ) ) . '</td>';
		$h .= '<td><div class="k">Total owing</div><strong>R ' . $e( $m( $d['ageing']['total'] ) ) . '</strong></td></tr></table>';
		if ( '' !== (string) ( $brand['bank_details'] ?? '' ) ) $h .= '<div class="box"><strong>Pay to</strong><br>' . nl2br( $e( $brand['bank_details'] ) ) . '<br>Reference: the invoice number, or ' . $e( $d['reference'] ) . ' for several at once</div>';
		if ( $d['ageing']['overdue'] > 0 ) $h .= '<p class="note">R ' . $e( $m( $d['ageing']['overdue'] ) ) . ' is past its due date. If you have paid it in the last few days, thank you, and please ignore this line.</p>';
		if ( '' !== (string) ( $brand['doc_footer'] ?? '' ) ) $h .= '<p class="note">' . nl2br( $e( $brand['doc_footer'] ) ) . '</p>';
		$h .= '<div class="foot">' . $e( $name ) . ' · Statement for ' . $e( $c['name'] ) . ' · ' . $e( $d['date'] ) . '</div>';
		return $h . '</body></html>';
	}

	/**
	 * Who to chase: open invoices grouped by customer, only those with something late, the most
	 * overdue money first. [ [ customer_id, owes, overdue, oldest (days late), count ], … ]. Pure.
	 */
	public static function chase( array $invoices, string $today ): array {
		$by = [];
		$t  = strtotime( substr( $today, 0, 10 ) );
		foreach ( $invoices as $i ) {
			$left = round( (float) $i['total'] - (float) ( $i['amount_paid'] ?? 0 ) - (float) ( $i['amount_credited'] ?? 0 ), 2 );
			if ( $left <= 0.004 ) continue;
			$cid  = (int) $i['customer_id'];
			$late = '' !== (string) ( $i['due_at'] ?? '' ) ? (int) floor( ( $t - strtotime( substr( (string) $i['due_at'], 0, 10 ) ) ) / 86400 ) : 0;
			$by[ $cid ] = $by[ $cid ] ?? [ 'customer_id' => $cid, 'owes' => 0.0, 'overdue' => 0.0, 'oldest' => 0, 'count' => 0 ];
			$by[ $cid ]['owes'] += $left;
			if ( $late > 0 ) { $by[ $cid ]['overdue'] += $left; $by[ $cid ]['count']++; $by[ $cid ]['oldest'] = max( $by[ $cid ]['oldest'], $late ); }
		}
		$rows = array_values( array_filter( $by, fn( $r ) => $r['overdue'] > 0.004 ) );
		foreach ( $rows as &$r ) { $r['owes'] = round( $r['owes'], 2 ); $r['overdue'] = round( $r['overdue'], 2 ); }
		unset( $r );
		usort( $rows, fn( $a, $b ) => [ $b['overdue'], $b['oldest'] ] <=> [ $a['overdue'], $a['oldest'] ] );
		return $rows;
	}

	/** Is the monthly run due today? Pure: the day of the month matches and this month has not run. */
	public static function run_due( string $today, int $day, string $last_run_month ): bool {
		$day = min( 28, max( 1, $day ) );
		return (int) substr( $today, 8, 2 ) >= $day && substr( $today, 0, 7 ) !== $last_run_month;
	}

	/* ------------------------------------------------------------------ the monthly statements */

	const OPT_CUSTOMERS = 'wb_statement_monthly';   // customer ids switched on, one by one, by a person
	const OPT_DAY       = 'wb_statement_day';
	const OPT_RUN       = 'wb_statement_run';       // YYYY-MM of the last run

	public static function init(): void {
		add_action( WB_Cron::HOOK, fn() => WB_Cron::safely( [ __CLASS__, 'monthly_run' ] ), 60 );
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			$h['statement_monthly'] = [ __CLASS__, 'handle_toggle' ];
			$h['statement_day']     = [ __CLASS__, 'handle_day' ];
			return $h;
		} );
	}

	public static function monthly_ids(): array {
		return array_values( array_unique( array_filter( array_map( 'intval', (array) get_option( self::OPT_CUSTOMERS, [] ) ) ) ) );
	}

	public static function is_monthly( int $customer_id ): bool {
		return in_array( $customer_id, self::monthly_ids(), true );
	}

	public static function handle_toggle() {
		if ( ! current_user_can( 'wb_issue_invoices' ) ) return new WP_Error( 'wb_forbidden', 'Statements are not part of your work.' );
		$cid = absint( $_POST['customer_id'] ?? 0 );
		if ( ! WB_CCT::get( 'wb_customers', $cid ) ) return new WP_Error( 'wb_missing', 'That customer could not be found.' );
		$on  = ! empty( $_POST['on'] );
		$ids = array_values( array_diff( self::monthly_ids(), [ $cid ] ) );
		if ( $on ) $ids[] = $cid;
		update_option( self::OPT_CUSTOMERS, $ids, false );
		wb_ledger_write( $on ? 'statement_monthly_on' : 'statement_monthly_off', 'wb_customers', $cid );
		return [ 'msg' => $on ? 'A statement will be emailed to them on day ' . self::day() . ' of every month while they owe anything.' : 'Monthly statements are off for them.' ];
	}

	public static function day(): int {
		return min( 28, max( 1, (int) get_option( self::OPT_DAY, 1 ) ) );
	}

	public static function handle_day() {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return new WP_Error( 'wb_forbidden', 'Only the owner can change this.' );
		update_option( self::OPT_DAY, min( 28, max( 1, absint( $_POST['day'] ?? 1 ) ) ), false );
		return [ 'msg' => 'Monthly statements go out on day ' . self::day() . '.' ];
	}

	/**
	 * Nightly: on the chosen day, a statement to each customer a person switched on, while they owe
	 * anything, to their contacts who receive invoices. Never on a site with the demo loaded (its
	 * addresses are made up). Each send is on the customer's timeline and in the audit trail.
	 */
	public static function monthly_run(): void {
		if ( ! self::run_due( wb_today(), self::day(), (string) get_option( self::OPT_RUN, '' ) ) ) return;
		update_option( self::OPT_RUN, substr( wb_today(), 0, 7 ), false );   // first, so a failure never sends twice
		if ( class_exists( 'WB_Demo' ) && WB_Demo::is_seeded() ) { wb_ledger_write( 'statement_run_skipped', 'wb_customers', 0, null, [ 'why' => 'demo data loaded' ] ); return; }
		$sent = $skipped = 0;
		$tpl  = WB_Send::templates()['statement'];
		foreach ( self::monthly_ids() as $cid ) {
			$data = self::data( $cid );
			if ( ! $data || $data['d']['ageing']['total'] <= 0.004 ) { $skipped++; continue; }
			$to = array_column( array_filter( WB_Send::recipients( WB_CCT::find( 'wb_contacts', [ 'customer_id' => $cid ], [ 'limit' => 200 ] ), 'statement' ), fn( $r ) => $r[2] ), 0 );
			if ( ! $to ) { $skipped++; continue; }
			$vars = [ 'company' => WB_Setup::display_name(), 'me' => WB_Setup::display_name(), 'date' => wb_today(), 'contact' => 'Sir or Madam', 'customer' => (string) $data['d']['customer']['name'],
				'owing' => WB_Render::money( $data['d']['ageing']['total'] ), 'overdue' => WB_Render::money( $data['d']['ageing']['overdue'] ) ];
			$r = WB_Send::deliver( 'statement', $cid, $data['d']['customer'], $data['d']['customer'], $to, WB_Send::fill( $tpl[0], $vars ), WB_Send::fill( $tpl[1], $vars ), false, false, '', true );
			is_wp_error( $r ) ? $skipped++ : $sent++;
		}
		wb_ledger_write( 'statement_run', 'wb_customers', 0, null, [ 'sent' => $sent, 'skipped' => $skipped, 'month' => substr( wb_today(), 0, 7 ) ] );
	}

	/* ------------------------------------------------------------------ the Chase fold on Invoices */

	public static function chase_fold(): string {
		if ( ! current_user_can( 'wb_issue_invoices' ) ) return '';
		$open = WB_CCT::find( 'wb_invoices', [ 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 5000 ] );
		$age  = WB_Pages::ageing( $open, wb_today() );
		$rows = self::chase( $open, wb_today() );
		foreach ( $rows as &$r ) {
			$last = null;
			foreach ( [ 'reminder', 'statement' ] as $k ) {
				$t = WB_CCT::first( 'wb_touchpoints', [ 'customer_id' => $r['customer_id'], 'source_ref' => $k . ':' . $r['customer_id'] ], [ 'orderby' => 'happened_at' ] );
				if ( $t && ( ! $last || $t['happened_at'] > $last ) ) $last = (string) $t['happened_at'];
			}
			$r['last'] = $last ? substr( $last, 0, 10 ) : '';
		}
		unset( $r );
		$body = '<p class="wb-muted">Everything owed, by how late. Below it, every customer with something past its due date, the most overdue money first.</p>' . WB_Pages::ageing_strip( $age )
			. WB_Render::render_table( $rows, [
				[ 'key' => 'customer_id', 'label' => 'Customer', 'render' => fn( $v ) => WB_Screens::customer_link( (int) $v ) ],
				[ 'key' => 'overdue', 'label' => 'Overdue', 'type' => 'money' ], [ 'key' => 'owes', 'label' => 'Owes in all', 'type' => 'money' ],
				[ 'key' => 'oldest', 'label' => 'Oldest', 'render' => fn( $v, $r ) => esc_html( $v . ' days late' . ( $r['count'] > 1 ? ' (' . $r['count'] . ' invoices)' : '' ) ) ],
				[ 'key' => 'last', 'label' => 'Last chased', 'render' => fn( $v ) => '' !== $v ? esc_html( $v ) : '<span class="wb-muted">not yet</span>' ],
			], [ 'action_html' => fn( $r ) => WB_RowActions::menuitem( 'share', 'Send a reminder', [ 'href' => WB_Send::form_url( 'reminder', (int) $r['customer_id'] ) ] ) . WB_RowActions::menuitem( 'share', 'Send a statement', [ 'href' => WB_Send::form_url( 'statement', (int) $r['customer_id'] ) ] ) . WB_RowActions::menuitem( 'open', 'Open the customer', [ 'href' => WB_Workspace::url( 'customers', [ 'customer' => (int) $r['customer_id'] ] ) ] ),
				'empty' => 'Nobody is late. Nothing to chase.' ] );
		$n = count( self::monthly_ids() );
		$body .= '<p class="wb-muted">Monthly statements: ' . ( $n ? $n . ' customer' . ( 1 === $n ? '' : 's' ) . ' get one by email on day ' . self::day() . ' while they owe anything.' : 'none switched on.' ) . ' Switch a customer on from their page.</p>';
		if ( current_user_can( 'wb_manage_settings' ) ) $body .= WB_Render::form_open( 'statement_day' ) . WB_Render::field( 'day', 'Day of the month they go out', 'number', (string) self::day(), [ 'id' => 'wb-statement-day', 'note' => '1 to 28.' ] ) . WB_Render::form_close( 'Save the day' );
		return WB_Render::fold( 'Chase', $body, [ 'open' => (bool) $rows, 'id' => 'wb-chase', 'kind' => 'lead', 'hint' => $rows ? 'R ' . WB_Render::money( $age['overdue'] ) . ' overdue, ' . count( $rows ) . ' customer' . ( 1 === count( $rows ) ? '' : 's' ) : 'nobody late' ] );
	}

	/** The live data for one customer. */
	public static function data( int $customer_id ): ?array {
		$c = WB_CCT::get( 'wb_customers', $customer_id );
		if ( ! $c ) return null;
		$open  = WB_CCT::find( 'wb_invoices', [ 'customer_id' => $customer_id, 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 2000, 'orderby' => 'issued_at', 'order' => 'ASC' ] );
		$brand = WB_Setup::brand();
		$brand['colors'] = WB_Setup::safe_colors( (array) $brand['colors'] );
		$brand['logo']   = WB_Setup::logo_data_uri();
		return [ 'd' => [ 'customer' => $c, 'date' => wb_today(), 'invoices' => $open, 'ageing' => WB_Pages::ageing( $open, wb_today() ), 'reference' => 'ACC-' . $customer_id ], 'brand' => $brand ];
	}

	/** The statement as PDF bytes ('' when it cannot be made). */
	public static function pdf( int $customer_id ): string {
		$data = self::data( $customer_id );
		return $data ? WB_Pdf::render( self::html( $data['d'], $data['brand'] ) ) : '';
	}
}
