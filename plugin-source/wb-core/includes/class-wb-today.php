<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Today — the two cards that answer "how is the month?" and "is cash fine?" (1.6.0, review of
 * 9 October: "Today shows the moment, not the week").
 *
 *  - This month: sales invoiced and cash received so far, each beside the same days of the same
 *    month last year, as a number and one bar each.
 *  - The next four weeks: money expected in (invoices by due date, likely orders) and going out
 *    (supplier orders, wages), and what is left, from last night's thirteen-week forecast.
 * Bars are plain elements, as the Getting started meter is: no chart library. month() and next4()
 * are pure.
 */
class WB_Today {

	/** Sales and cash, this month to date against the same span last year. Pure. */
	public static function month( array $invoices, array $payments, string $today ): array {
		$y  = (int) substr( $today, 0, 4 );
		$m  = substr( $today, 5, 2 );
		$d  = substr( $today, 8, 2 );
		$in = fn( string $date, int $year ) => substr( $date, 0, 7 ) === $year . '-' . $m && substr( $date, 8, 2 ) <= $d;
		$out = [ 'sales' => 0.0, 'sales_ly' => 0.0, 'cash' => 0.0, 'cash_ly' => 0.0 ];
		foreach ( $invoices as $i ) {
			if ( 'void' === (string) ( $i['status'] ?? '' ) ) continue;
			$at = substr( (string) $i['issued_at'], 0, 10 );
			if ( $in( $at, $y ) ) $out['sales'] += (float) $i['subtotal'];
			elseif ( $in( $at, $y - 1 ) ) $out['sales_ly'] += (float) $i['subtotal'];
		}
		foreach ( $payments as $p ) {
			$at = substr( (string) $p['received_at'], 0, 10 );
			if ( $in( $at, $y ) ) $out['cash'] += (float) $p['amount'];
			elseif ( $in( $at, $y - 1 ) ) $out['cash_ly'] += (float) $p['amount'];
		}
		foreach ( $out as $k => $v ) $out[ $k ] = round( $v, 2 );
		$out['month'] = gmdate( 'F', strtotime( $today ) );
		$out['days']  = (int) $d;
		return $out;
	}

	/** The first four weeks of the forecast: in, out, left, and each week's net. Pure. */
	public static function next4( array $rows ): array {
		$rows = array_slice( $rows, 0, 4 );
		$in = $out = 0.0;
		$weeks = [];
		foreach ( $rows as $r ) {
			$wi = (float) ( $r['expected_receipts'] ?? 0 ) + (float) ( $r['predicted_orders'] ?? 0 );
			$wo = (float) ( $r['committed_purchases'] ?? 0 ) + (float) ( $r['payroll'] ?? 0 );
			$in += $wi; $out += $wo;
			$weeks[] = [ (string) $r['week_start'], round( $wi - $wo, 2 ) ];
		}
		return [ 'in' => round( $in, 2 ), 'out' => round( $out, 2 ), 'left' => round( $in - $out, 2 ), 'weeks' => $weeks ];
	}

	/** "up 12% on last year", "down 4%", "the same", or '' when last year had nothing. Pure. */
	public static function change( float $now, float $then ): string {
		if ( $then <= 0.004 ) return '';
		$pct = (int) round( ( $now - $then ) / $then * 100 );
		return 0 === $pct ? 'the same as last year' : ( $pct > 0 ? 'up ' . $pct . '% on last year' : 'down ' . abs( $pct ) . '% on last year' );
	}

	private static function pair( string $label, float $now, float $then ): string {
		$max = max( 0.01, $now, $then );
		$chg = self::change( $now, $then );
		return '<div class="wb-pair"><div class="wb-pair-h"><span>' . esc_html( $label ) . '</span><strong>R ' . esc_html( WB_Render::money( $now ) ) . '</strong></div>'
			. '<div class="wb-bar" aria-hidden="true"><span style="width:' . (int) round( 100 * $now / $max ) . '%"></span></div>'
			. '<div class="wb-bar wb-bar--ly" aria-hidden="true"><span style="width:' . (int) round( 100 * $then / $max ) . '%"></span></div>'
			. '<small>Last year R ' . esc_html( WB_Render::money( $then ) ) . ( '' !== $chg ? ' · ' . esc_html( $chg ) : '' ) . '</small></div>';
	}

	/** Both cards, for people who see the money. '' for everyone else. */
	public static function cards(): string {
		if ( ! current_user_can( 'wb_view_cashflow' ) ) return '';
		$today = wb_today();
		$y     = (int) substr( $today, 0, 4 );
		$m     = substr( $today, 5, 2 );
		$inv   = array_merge( WB_CCT::find( 'wb_invoices', [ 'issued_at >=' => $y . '-' . $m . '-01', 'issued_at <=' => $today . ' 23:59:59' ], [ 'limit' => 5000 ] ),
			WB_CCT::find( 'wb_invoices', [ 'issued_at >=' => ( $y - 1 ) . '-' . $m . '-01', 'issued_at <=' => ( $y - 1 ) . '-' . $m . '-31 23:59:59' ], [ 'limit' => 5000 ] ) );
		$pay   = array_merge( WB_CCT::find( 'wb_payments', [ 'received_at >=' => $y . '-' . $m . '-01', 'received_at <=' => $today ], [ 'limit' => 5000 ] ),
			WB_CCT::find( 'wb_payments', [ 'received_at >=' => ( $y - 1 ) . '-' . $m . '-01', 'received_at <=' => ( $y - 1 ) . '-' . $m . '-31' ], [ 'limit' => 5000 ] ) );
		$mo    = self::month( $inv, $pay, $today );
		$h = '<div class="wb-two wb-today-cards"><section class="wb-card" aria-labelledby="wb-month-h"><h2 id="wb-month-h">' . esc_html( $mo['month'] ) . ' so far</h2>'
			. '<p class="wb-muted">The first ' . (int) $mo['days'] . ' days, against the same days last year. Sales are before VAT.</p>'
			. self::pair( 'Sales invoiced', $mo['sales'], $mo['sales_ly'] ) . self::pair( 'Cash received', $mo['cash'], $mo['cash_ly'] ) . '</section>';
		$n4 = self::next4( WB_Demand::latest_forecast() );
		$h .= '<section class="wb-card" aria-labelledby="wb-cash-h"><h2 id="wb-cash-h">The next four weeks</h2>';
		if ( ! $n4['weeks'] ) {
			$h .= WB_Render::state( 'empty', 'The forecast appears after tonight\'s run.', 'It is worked out every night from invoices, likely orders, supplier orders and wages.' );
		} else {
			$max = max( 0.01, ...array_map( fn( $w ) => abs( $w[1] ), $n4['weeks'] ) );
			$h .= '<dl class="wb-cash4"><div><dt>Coming in</dt><dd>R ' . esc_html( WB_Render::money( $n4['in'] ) ) . '</dd></div><div><dt>Going out</dt><dd>R ' . esc_html( WB_Render::money( $n4['out'] ) ) . '</dd></div><div class="' . ( $n4['left'] < 0 ? 'is-hot' : '' ) . '"><dt>Left over</dt><dd>R ' . esc_html( WB_Render::money( $n4['left'] ) ) . '</dd></div></dl><ol class="wb-weeks">';
			foreach ( $n4['weeks'] as [ $wk, $net ] ) $h .= '<li class="' . ( $net < 0 ? 'is-neg' : '' ) . '"><span>Week of ' . esc_html( WB_Render::date( $wk ) ) . '</span><span class="wb-bar" aria-hidden="true"><span style="width:' . (int) round( 100 * abs( $net ) / $max ) . '%"></span></span><span>R ' . esc_html( WB_Render::money( $net ) ) . '</span></li>';
			$h .= '</ol><p class="wb-list-more"><a href="' . esc_url( WB_Workspace::url( 'cashflow' ) ) . '">All thirteen weeks</a></p>';
		}
		return $h . '</section></div>';
	}
}
