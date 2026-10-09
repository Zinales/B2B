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
