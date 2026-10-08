<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Sequences — gapless document numbering (DATA-ARCHITECTURE §2, rule 6).
 *
 * One counter row per document type per year: QUO, ORD, INV, CRN, DN, PO, STM.
 * Format PREFIX-YYYY-000001 (prefix from the wb_number_prefixes option; the type key never changes).
 *
 * GAPLESS, not just unique: the number is taken and the document is written in ONE transaction.
 *   WB_Sequences::issue( 'INV', function ( string $number ) { …insert the invoice…; return $id; } );
 * The counter row is locked with SELECT … FOR UPDATE, the callback writes the document, and only if
 * it succeeds is the counter moved on and the transaction committed. A failed write rolls back, so
 * the number is never burned. Two invoices can never share a number; no number is ever skipped.
 * Ledger entries written inside the callback — and their wb_event hooks — are deferred until
 * COMMIT (see WB_Ledger); one that cannot be chained then is queued, never dropped.
 *
 * Nesting is allowed (an order's acceptance numbering an invoice): inner calls join the outer
 * transaction and lock their own counter row.
 */
class WB_Sequences {

	const TYPES = [ 'QUO', 'ORD', 'INV', 'CRN', 'DN', 'PO', 'STM' ];

	const DEFAULT_PREFIXES = [
		'QUO' => 'QUO', 'ORD' => 'ORD', 'INV' => 'INV', 'CRN' => 'CRN', 'DN' => 'DN', 'PO' => 'PO', 'STM' => 'STM',
	];

	/** @var int transaction nesting depth */
	private static $depth = 0;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wb_sequences';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE " . self::table() . " (
			doc_type VARCHAR(8) NOT NULL,
			seq_year SMALLINT UNSIGNED NOT NULL,
			last_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NULL,
			PRIMARY KEY  (doc_type, seq_year)
		) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
	}

	/* ------------------------------------------------------------------ pure */

	/** PREFIX-YYYY-000001. Six digits minimum; grows past 999999 rather than wrapping. */
	public static function format( string $prefix, int $year, int $n ): string {
		return sprintf( '%s-%04d-%06d', $prefix, $year, $n );
	}

	/** A prefix is letters/digits only, 1–8 characters; anything else falls back to the type key. */
	public static function clean_prefix( string $prefix, string $type ): string {
		$p = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) );
		return ( '' === $p || strlen( $p ) > 8 ) ? $type : $p;
	}

	/* ------------------------------------------------------------------ issuing */

	public static function prefix( string $type ): string {
		$all = (array) get_option( 'wb_number_prefixes', self::DEFAULT_PREFIXES );
		return self::clean_prefix( (string) ( $all[ $type ] ?? $type ), $type );
	}

	/**
	 * Take the next number for $type and run $create( $number ) inside the same transaction.
	 * Returns whatever $create returns; a WP_Error or falsy return rolls everything back.
	 *
	 * @return mixed|WP_Error
	 */
	public static function issue( string $type, callable $create ) {
		global $wpdb;
		$type = strtoupper( $type );
		if ( ! in_array( $type, self::TYPES, true ) ) return new WP_Error( 'wb_seq_type', 'Unknown document type.' );
		$t     = self::table();
		$year  = (int) substr( wb_now(), 0, 4 );
		$outer = 0 === self::$depth;

		if ( $outer ) {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) return new WP_Error( 'wb_seq_tx', 'Could not start the numbering transaction.' );
			WB_Ledger::begin_defer();
		}
		self::$depth++;
		try {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (doc_type, seq_year, last_value, updated_at) VALUES (%s, %d, 0, %s)", $type, $year, wb_now() ) );
			$last = $wpdb->get_var( $wpdb->prepare( "SELECT last_value FROM {$t} WHERE doc_type = %s AND seq_year = %d FOR UPDATE", $type, $year ) );
			if ( null === $last ) throw new RuntimeException( 'sequence row missing' );   // fail closed
			$next   = (int) $last + 1;
			$number = self::format( self::prefix( $type ), $year, $next );

			$result = $create( $number );
			if ( ! $result || is_wp_error( $result ) ) {
				self::$depth--;
				if ( $outer ) self::rollback();
				return $result ?: new WP_Error( 'wb_seq_create', 'The document could not be written, so no number was used.' );
			}
			$ok = $wpdb->update( $t, [ 'last_value' => $next, 'updated_at' => wb_now() ], [ 'doc_type' => $type, 'seq_year' => $year ], [ '%d', '%s' ], [ '%s', '%d' ] );
			if ( false === $ok ) throw new RuntimeException( 'sequence update failed' );
			wb_ledger_write( 'number_issued', 'wb_sequences', $next, null, [ 'type' => $type, 'number' => $number ] );   // deferred: rolls back with the work
			self::$depth--;
			if ( $outer ) {
				if ( false === $wpdb->query( 'COMMIT' ) ) throw new RuntimeException( 'commit failed' );
				// After COMMIT (see WB_Ledger: an entry chained inside the transaction would fork the
				// chain or deadlock the named lock against InnoDB row locks). An entry that cannot be
				// chained now is queued and chained later; only "lost" (not even queued) counts here.
				$lost = WB_Ledger::flush_deferred();
				if ( $lost > 0 ) error_log( sprintf( 'WB_Sequences: %s %s was committed but %d audit entr%s could not be recorded (see the WB_Ledger lines above).', $type, $number, $lost, 1 === $lost ? 'y' : 'ies' ) );
			}
			return $result;
		} catch ( Throwable $e ) {
			self::$depth = max( 0, self::$depth - 1 );
			if ( $outer ) self::rollback();
			error_log( 'WB_Sequences: ' . $type . ' rolled back: ' . $e->getMessage() );
			return new WP_Error( 'wb_seq_failed', 'Numbering failed; nothing was issued.' );
		}
	}

	private static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		WB_Ledger::discard_deferred();
		self::$depth = 0;
	}

	/** Read-only: the number the next document of $type would get (for display only — never reserve it). */
	public static function peek( string $type ): string {
		global $wpdb;
		$type = strtoupper( $type );
		$year = (int) substr( wb_now(), 0, 4 );
		$last = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT last_value FROM ' . self::table() . ' WHERE doc_type = %s AND seq_year = %d', $type, $year ) );
		return self::format( self::prefix( $type ), $year, $last + 1 );
	}
}
