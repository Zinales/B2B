<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Cron — one nightly hook (wb_nightly, 02:00 site time) that the engines hang their jobs on,
 * each isolated so one failure never stops the rest:
 *   5  WB_Ledger::nightly_verify       chain check; a break notifies the owner
 *   10 WB_Invoices::sweep_overdue      issued/part_paid past due → overdue
 *   15 WB_Orders::expire_quotes        sent quotes past valid_until → expired
 *   20 WB_Stock::sweep_reorder         raise / resolve reorder alerts
 *   30 WB_Demand::run_nightly          demand, seasonality, journey stage, 13-week cashflow
 *   40 KPIs for the current month
 *   90 WB_Integrity::maybe_monthly     on the 1st: last month's Integrity report
 * For reliability, set DISABLE_WP_CRON and call wp-cron.php from a real server cron (README).
 */
class WB_Cron {

	const HOOK = 'wb_nightly';

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'guard' ], 1 );
		add_action( self::HOOK, fn() => self::safely( [ 'WB_Invoices', 'sweep_overdue' ] ), 10 );
		add_action( self::HOOK, fn() => self::safely( [ 'WB_Demand', 'run_nightly' ] ), 30 );
		add_action( self::HOOK, fn() => self::safely( [ 'WB_Staff', 'ensure_leave_types' ] ), 35 );
		add_action( self::HOOK, fn() => self::safely( function () {
			$from = current_time( 'Y-m-01' );
			WB_Staff::measure_kpis( $from, gmdate( 'Y-m-t', strtotime( $from ) ) );
		} ), 40 );
		if ( ! wp_next_scheduled( self::HOOK ) ) self::schedule();
	}

	/** The jobs registered by the engines themselves run inside WP's own loop; ours are wrapped. */
	public static function safely( callable $job ): void {
		try {
			$job();
		} catch ( Throwable $e ) {
			error_log( 'WB_Cron job failed: ' . $e->getMessage() );
			wb_ledger_write( 'cron_job_failed', 'wb_cron', 0, null, [ 'error' => mb_substr( $e->getMessage(), 0, 200 ) ] );
		}
	}

	/** A run that starts while another is still going does nothing (two nightly runs would double work). */
	public static function guard(): void {
		if ( get_transient( 'wb_nightly_running' ) ) {
			remove_all_actions( self::HOOK );
			return;
		}
		set_transient( 'wb_nightly_running', 1, HOUR_IN_SECONDS );
		add_action( self::HOOK, fn() => delete_transient( 'wb_nightly_running' ), 999 );
	}

	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) return;
		$tz   = wp_timezone();
		$next = new DateTimeImmutable( 'tomorrow 02:00', $tz );
		wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
