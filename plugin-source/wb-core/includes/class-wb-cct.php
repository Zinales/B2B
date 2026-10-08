<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_CCT — the one door to the business tables (JetEngine CCTs, $wpdb->prefix . 'jet_cct_wb_*').
 *
 * - COLUMN-SAFE: every write is array_intersect_key()'d against SHOW COLUMNS (cached per
 *   request), every filter column is checked before use. CCT schemas drift between installs;
 *   the code survives that (convention 2).
 * - FAIL CLOSED (convention 7): a missing table or a filter on a missing column returns [] /
 *   WP_Error — never "all rows", never a silent write somewhere else.
 * - WITNESSED: every insert/update writes a ledger entry with before/after (rule 2). An entry
 *   that cannot be chained is queued by WB_Ledger; one that cannot even be queued turns the
 *   write's result into a WP_Error (wb_ledger_failed) instead of a silent success.
 * - NO HARD DELETES (rule 4): set_status() is the only way out (active / archived / inactive / void).
 *
 * Capability checks live in the engines that call this (they know the business rule); this
 * class is plumbing and never decides who may do what.
 *
 * Every CCT and its fields is described in schema/wb-ccts.json for the tenant's JetEngine setup.
 */
class WB_CCT {

	const SLUGS = [
		'wb_customers', 'wb_contacts', 'wb_touchpoints',
		'wb_products', 'wb_product_categories', 'wb_price_tiers', 'wb_price_rules', 'wb_suppliers',
		'wb_stock_movements', 'wb_batches', 'wb_purchase_orders', 'wb_po_lines', 'wb_stocktakes',
		'wb_quotes', 'wb_quote_lines', 'wb_orders', 'wb_order_lines', 'wb_invoices', 'wb_credit_notes',
		'wb_payments', 'wb_delivery_notes', 'wb_statements',
		'wb_documents',
		'wb_staff', 'wb_timesheets', 'wb_leave_types', 'wb_leave', 'wb_kpis', 'wb_kpi_scores', 'wb_reviews', 'wb_staff_notes',
		'wb_portal_requests',                                       // 0.2.0: customer portal change requests
		'wb_payroll_profiles', 'wb_pay_runs', 'wb_payslips',        // 0.2.0: payroll
	];

	const RECORD_STATUSES = [ 'active', 'archived', 'inactive', 'void' ];

	/** Tables that are append-only: no update(), ever (stock is a ledger — rule 7). */
	const APPEND_ONLY = [ 'wb_stock_movements' ];

	/** Rows that can never change once their status reaches this value (finalised payroll — 0.2.0). */
	const LOCKED_WHEN = [ 'wb_pay_runs' => 'finalised', 'wb_payslips' => 'finalised' ];

	/** Allowed comparison operators in "column op" filter keys. */
	const OPS = [ '=', '!=', '<', '<=', '>', '>=', 'LIKE', 'IN', 'NOT IN' ];

	private static $tables  = [];
	private static $columns = [];

	/* ------------------------------------------------------------------ structure */

	/** The real table name, or null when the slug is unknown or the table is not there. */
	public static function table( string $slug ): ?string {
		if ( ! in_array( $slug, self::SLUGS, true ) ) return null;
		if ( array_key_exists( $slug, self::$tables ) ) return self::$tables[ $slug ];
		global $wpdb;
		$t = $wpdb->prefix . 'jet_cct_' . $slug;
		return self::$tables[ $slug ] = ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) ? $t : null;
	}

	public static function columns( string $slug ): array {
		if ( isset( self::$columns[ $slug ] ) ) return self::$columns[ $slug ];
		$t = self::table( $slug );
		if ( ! $t ) return [];
		global $wpdb;
		return self::$columns[ $slug ] = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$t}`" ) );
	}

	public static function has_column( string $slug, string $col ): bool {
		return in_array( $col, self::columns( $slug ), true );
	}

	/** true, or a WP_Error naming what is missing. Engines call this before a write that depends on columns. */
	public static function require_columns( string $slug, array $cols ) {
		if ( ! self::table( $slug ) ) return new WP_Error( 'wb_missing_table', sprintf( 'The %s table is not set up yet.', $slug ) );
		$missing = array_diff( $cols, self::columns( $slug ) );
		return $missing ? new WP_Error( 'wb_missing_columns', sprintf( '%s is missing: %s', $slug, implode( ', ', $missing ) ) ) : true;
	}

	/** Forget cached structure (after a JetEngine schema change in the same request). */
	public static function flush_cache(): void {
		self::$tables  = [];
		self::$columns = [];
	}

	/* ------------------------------------------------------------------ reading */

	public static function get( string $slug, int $id ): ?array {
		$t = self::table( $slug );
		if ( ! $t || $id <= 0 ) return null;
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE _ID = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Build a WHERE clause from [ 'col' => v, 'col >=' => v, 'col' => [a,b] (IN) ].
	 * Returns [ sql, args ] or null when any column is unknown (the caller then fails closed).
	 */
	private static function where( string $slug, array $where, bool $active_only ): ?array {
		$cols = self::columns( $slug );
		$sql  = [ '1=1' ];
		$args = [];
		foreach ( $where as $key => $val ) {
			$parts = preg_split( '/\s+/', trim( (string) $key ), 2 );
			$col   = (string) $parts[0];
			$op    = strtoupper( (string) ( $parts[1] ?? ( is_array( $val ) ? 'IN' : '=' ) ) );
			if ( ! in_array( $col, $cols, true ) || ! in_array( $op, self::OPS, true ) ) return null;
			if ( 'IN' === $op || 'NOT IN' === $op ) {
				$val = array_values( (array) $val );
				if ( ! $val ) {
					if ( 'IN' === $op ) $sql[] = '1=0';   // IN () matches nothing — never everything
					continue;
				}
				$sql[] = "`{$col}` {$op} (" . implode( ',', array_fill( 0, count( $val ), '%s' ) ) . ')';
				foreach ( $val as $v ) $args[] = (string) $v;
				continue;
			}
			if ( null === $val ) {
				$sql[] = '=' === $op ? "(`{$col}` IS NULL OR `{$col}` = '')" : "(`{$col}` IS NOT NULL AND `{$col}` <> '')";
				continue;
			}
			$sql[]  = "`{$col}` {$op} %s";
			$args[] = (string) $val;
		}
		if ( $active_only && in_array( 'record_status', $cols, true ) ) {
			$sql[] = "COALESCE(`record_status`,'') NOT IN ('archived','inactive','void')";   // '' = written by a form before the field existed
		}
		return [ implode( ' AND ', $sql ), $args ];
	}

	/**
	 * Rows matching $where. opts: orderby (column), order (ASC|DESC), limit (default 500, max 5000),
	 * offset, active_only (default true). Unknown table or column → [] (fail closed).
	 */
	public static function find( string $slug, array $where = [], array $opts = [] ): array {
		$t = self::table( $slug );
		if ( ! $t ) return [];
		$w = self::where( $slug, $where, ! array_key_exists( 'active_only', $opts ) || $opts['active_only'] );
		if ( null === $w ) return [];
		$orderby = (string) ( $opts['orderby'] ?? '_ID' );
		if ( ! self::has_column( $slug, $orderby ) ) $orderby = '_ID';
		$order  = 'ASC' === strtoupper( (string) ( $opts['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$limit  = max( 1, min( 5000, (int) ( $opts['limit'] ?? 500 ) ) );
		$offset = max( 0, (int) ( $opts['offset'] ?? 0 ) );
		global $wpdb;
		$sql = "SELECT * FROM `{$t}` WHERE {$w[0]} ORDER BY `{$orderby}` {$order}, _ID {$order} LIMIT {$limit} OFFSET {$offset}";
		$rows = $wpdb->get_results( $w[1] ? $wpdb->prepare( $sql, $w[1] ) : $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	public static function first( string $slug, array $where = [], array $opts = [] ): ?array {
		$rows = self::find( $slug, $where, [ 'limit' => 1 ] + $opts );
		return $rows[0] ?? null;
	}

	/** SUM(column) over matching rows. Missing table/column → 0.0 and the caller must treat that as unknown. */
	public static function sum( string $slug, string $col, array $where = [], bool $active_only = true ): float {
		$t = self::table( $slug );
		if ( ! $t || ! self::has_column( $slug, $col ) ) return 0.0;
		$w = self::where( $slug, $where, $active_only );
		if ( null === $w ) return 0.0;
		global $wpdb;
		$sql = "SELECT COALESCE(SUM(`{$col}`),0) FROM `{$t}` WHERE {$w[0]}";
		return (float) $wpdb->get_var( $w[1] ? $wpdb->prepare( $sql, $w[1] ) : $sql );
	}

	public static function count( string $slug, array $where = [], bool $active_only = true ): int {
		$t = self::table( $slug );
		if ( ! $t ) return 0;
		$w = self::where( $slug, $where, $active_only );
		if ( null === $w ) return 0;
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM `{$t}` WHERE {$w[0]}";
		return (int) $wpdb->get_var( $w[1] ? $wpdb->prepare( $sql, $w[1] ) : $sql );
	}

	/* ------------------------------------------------------------------ writing */

	/** Only real, writable columns; JetEngine's own bookkeeping columns are set here, never by callers. */
	private static function safe( string $slug, array $data ): array {
		unset( $data['_ID'] );
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) || is_object( $v ) ) $data[ $k ] = (string) wp_json_encode( $v );   // *_json columns
			elseif ( is_bool( $v ) ) $data[ $k ] = $v ? 1 : 0;
		}
		return array_intersect_key( $data, array_flip( self::columns( $slug ) ) );
	}

	/**
	 * Insert a row. Returns the new _ID or WP_Error. Ledgered as $action (default "<slug>_created").
	 * record_status defaults to active; JetEngine's cct_* columns are stamped.
	 *
	 * @return int|WP_Error
	 */
	public static function insert( string $slug, array $data, string $action = '' ) {
		$t = self::table( $slug );
		if ( ! $t ) return new WP_Error( 'wb_missing_table', sprintf( 'The %s table is not set up yet, so nothing was saved.', $slug ) );
		$now  = current_time( 'mysql' );
		$data = $data + [
			'record_status' => 'active',
			'cct_status'    => 'publish',
			'cct_author_id' => (int) get_current_user_id(),
			'cct_created'   => $now,
			'cct_modified'  => $now,
		];
		$row = self::safe( $slug, $data );
		if ( ! $row ) return new WP_Error( 'wb_nothing_to_save', 'None of those fields exist on ' . $slug . '.' );
		global $wpdb;
		if ( false === $wpdb->insert( $t, $row ) ) {
			return new WP_Error( 'wb_insert_failed', 'Saving failed: ' . (string) $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		$entry = wb_ledger_write( '' !== $action ? $action : $slug . '_created', $slug, $id, null, self::strip_meta( $row ) );
		if ( 0 === $entry ) return self::unwitnessed( $slug, $id );
		return $id;
	}

	/**
	 * Update a row. Only changed fields are written and ledgered. Append-only tables refuse.
	 *
	 * @return true|WP_Error
	 */
	public static function update( string $slug, int $id, array $data, string $action = '' ) {
		if ( in_array( $slug, self::APPEND_ONLY, true ) ) return new WP_Error( 'wb_append_only', 'Stock movements are never edited — record a new movement instead.' );
		$t = self::table( $slug );
		if ( ! $t ) return new WP_Error( 'wb_missing_table', sprintf( 'The %s table is not set up yet, so nothing was saved.', $slug ) );
		$cur = self::get( $slug, $id );
		if ( ! $cur ) return new WP_Error( 'wb_not_found', 'That record could not be found.' );
		if ( isset( self::LOCKED_WHEN[ $slug ] ) && self::LOCKED_WHEN[ $slug ] === (string) ( $cur['status'] ?? '' ) ) {
			return new WP_Error( 'wb_locked', 'A finalised pay run and its payslips are never changed. Put a correction in the next run.' );
		}
		$row     = self::safe( $slug, $data );
		$changed = [];
		$before  = [];
		foreach ( $row as $k => $v ) {
			if ( (string) ( $cur[ $k ] ?? '' ) === (string) $v ) continue;
			$changed[ $k ] = $v;
			$before[ $k ]  = $cur[ $k ] ?? null;
		}
		if ( ! $changed ) return true;   // nothing to do is not a failure
		if ( self::has_column( $slug, 'cct_modified' ) ) $changed['cct_modified'] = current_time( 'mysql' );
		global $wpdb;
		if ( false === $wpdb->update( $t, $changed, [ '_ID' => $id ] ) ) {
			return new WP_Error( 'wb_update_failed', 'Saving failed: ' . (string) $wpdb->last_error );
		}
		unset( $changed['cct_modified'] );
		$entry = wb_ledger_write( '' !== $action ? $action : $slug . '_updated', $slug, $id, $before, $changed );
		if ( 0 === $entry ) return self::unwitnessed( $slug, $id );
		return true;
	}

	/**
	 * The row was saved but the audit trail could neither chain nor queue its entry (rule 2 says
	 * every write is witnessed). The entry is in the PHP error log in full; the caller gets an
	 * error so the person knows, and must not simply press Save again (the row exists).
	 * A queued entry (WB_Ledger::QUEUED) is witnessed — it is chained later — so it is not an error.
	 */
	private static function unwitnessed( string $slug, int $id ): WP_Error {
		return new WP_Error( 'wb_ledger_failed', sprintf( 'Saved (%s #%d), but the audit trail could not record it. Do not save again; tell the owner, who will find the full entry in the server error log.', $slug, $id ) );
	}

	/**
	 * The only way a record leaves: its record_status. Nothing is hard-deleted.
	 *
	 * @return true|WP_Error
	 */
	public static function set_status( string $slug, int $id, string $status, string $reason = '' ) {
		if ( ! in_array( $status, self::RECORD_STATUSES, true ) ) return new WP_Error( 'wb_bad_status', 'Unknown record status.' );
		if ( ! self::has_column( $slug, 'record_status' ) ) return new WP_Error( 'wb_missing_columns', $slug . ' has no record_status column.' );
		if ( in_array( $slug, self::APPEND_ONLY, true ) ) return new WP_Error( 'wb_append_only', 'Stock movements are never archived — record a reversing movement.' );
		$res = self::update( $slug, $id, [ 'record_status' => $status ], $slug . '_' . $status );
		if ( true === $res && '' !== $reason ) wb_ledger_write( 'status_reason', $slug, $id, null, [ 'status' => $status, 'reason' => $reason ] );
		return $res;
	}

	private static function strip_meta( array $row ): array {
		unset( $row['cct_status'], $row['cct_author_id'], $row['cct_created'], $row['cct_modified'] );
		return $row;
	}

	/** Decode a *_json column to an array ([] when empty or invalid). */
	public static function json( $value ): array {
		if ( is_array( $value ) ) return $value;
		$d = json_decode( (string) $value, true );
		return is_array( $d ) ? $d : [];
	}
}
