<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Ledger — the hash-chained, append-only audit trail (DATA-ARCHITECTURE rule 2, §2).
 *
 * Every mutation anywhere in the system calls wb_ledger_write( action, record_type, record_id,
 * before, after ). Each entry's hash covers the previous entry's hash, so changing or removing
 * any row breaks every hash after it; verify_chain() finds the first break, nightly, and a break
 * notifies the owner.
 *
 * - Plugin-owned table (dbDelta), NOT a JetEngine CCT: nothing in the UI can edit it.
 * - A named MySQL lock serialises writers so the chain never forks. The lock name is a hash of
 *   the database and table prefix, so it always fits MySQL's 64-character limit (0.2.1, S7).
 * - Inside a database transaction (WB_Sequences::issue) entries are DEFERRED and written after
 *   COMMIT — a ledger row written inside an open transaction is invisible to the next writer and
 *   would fork the chain. On ROLLBACK the deferred entries are discarded with the work they
 *   describe. The wb_event hook for a deferred entry is deferred with it (0.2.1, S6): listeners
 *   only ever hear about work that was committed.
 * - EVERY WRITE IS WITNESSED (0.2.1, S1): an entry that cannot be chained (lock timeout, insert
 *   error) is never only error-logged. It is queued in its own non-autoloaded option
 *   (wb_ledger_pending_<time>_<random>, one per entry so two failing writers can never overwrite
 *   each other's queue), drained in order at the next append() and in nightly_verify(), and the
 *   owners get an in-app notice while anything is waiting. pending_count() feeds Integrity.
 * - TAIL ANCHOR (0.2.1, S3): after each append the newest {entry_id, entry_hash} is stored in the
 *   option wb_ledger_tail; verify_chain() checks it, so deleted tail rows or a truncated table are
 *   found (a hash chain on its own cannot see its own end being cut off).
 * - KEYED HASHES (0.2.1, S3): when WB_ENCRYPTION_KEY is defined, new entries are hash_version 2:
 *   HMAC-SHA256 with a key derived from it, and the tail anchor is MAC'd too, so someone with
 *   database access but not wp-config.php cannot rewrite the chain and recompute it. Older
 *   hash_version 1 rows (plain SHA-256) verify exactly as before. Once a version 2 row exists, a
 *   later version 1 row is reported as a 'downgrade'.
 * - The hash formula and the chain checks are pure functions (hash_for, verify_rows,
 *   verify_anchor, lock_name_for), testable without WordPress.
 * - 7-year retention: nothing in this plugin ever deletes a ledger row.
 */
class WB_Ledger {

	const DB_VERSION     = '2';            // 2: hash_version column (0.2.1)
	const LOCK_WAIT      = 10;
	const HASH_TAG       = 'wb-ledger-v1';
	const HASH_TAG_V2    = 'wb-ledger-v2';
	const PENDING_PREFIX = 'wb_ledger_pending_';
	const TAIL_OPTION    = 'wb_ledger_tail';
	const QUEUED         = -2;             // write()/append(): not chained yet, safely queued

	/** Columns written to the table, with their $wpdb formats. */
	const COLUMNS = [
		'actor_user_id' => '%d', 'action' => '%s', 'record_type' => '%s', 'record_id' => '%d',
		'before_json' => '%s', 'after_json' => '%s', 'ip' => '%s', 'prev_hash' => '%s',
		'entry_hash' => '%s', 'hash_version' => '%d', 'created_at' => '%s',
	];

	/** @var array|null entries ( [row, event] ) waiting for a transaction to commit; null = not deferring */
	private static $deferred = null;

	/** @var bool|null per-request cache: is anything queued? null = not looked yet */
	private static $has_pending = null;

	public static function init(): void {
		add_action( 'wb_nightly', [ __CLASS__, 'nightly_verify' ], 5 );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wb_ledger';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE " . self::table() . " (
			entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(64) NOT NULL,
			record_type VARCHAR(64) NOT NULL DEFAULT '',
			record_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			before_json LONGTEXT NULL,
			after_json LONGTEXT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			prev_hash CHAR(64) NOT NULL DEFAULT '',
			entry_hash CHAR(64) NOT NULL,
			hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (entry_id),
			KEY record (record_type, record_id),
			KEY action (action),
			KEY actor (actor_user_id),
			KEY created_at (created_at)
		) $charset;" );
	}

	/* ------------------------------------------------------------------ pure */

	/**
	 * The one hash formula, used for writing and for checking. Every field that matters is in it,
	 * including who did it and when; fields are newline-joined so "ab"+"c" ≠ "a"+"bc".
	 * hash_version 1 (or none): SHA-256, unchanged since 0.1.0. hash_version 2: HMAC-SHA256 with
	 * $key; '' when no key is given (a version 2 row cannot be checked without the key).
	 */
	public static function hash_for( string $prev, array $r, string $key = '' ): string {
		$fields = [
			$prev,
			(string) ( $r['action'] ?? '' ),
			(string) ( $r['record_type'] ?? '' ),
			(string) (int) ( $r['record_id'] ?? 0 ),
			(string) ( $r['before_json'] ?? '' ),
			(string) ( $r['after_json'] ?? '' ),
			(string) (int) ( $r['actor_user_id'] ?? 0 ),
			(string) ( $r['ip'] ?? '' ),
			(string) ( $r['created_at'] ?? '' ),
		];
		if ( (int) ( $r['hash_version'] ?? 1 ) < 2 ) {
			return hash( 'sha256', implode( "\n", array_merge( [ self::HASH_TAG ], $fields ) ) );
		}
		if ( '' === $key ) return '';
		return hash_hmac( 'sha256', implode( "\n", array_merge( [ self::HASH_TAG_V2 ], $fields ) ), $key );
	}

	/**
	 * Check a run of rows in entry_id order. $prev is the entry_hash of the row before the first
	 * one ('' when the run starts at the beginning). Returns true, or the first broken row with a
	 * 'broken' reason added: 'prev_link' (the row does not point at its predecessor — a row was
	 * removed or inserted), 'hash' (the row's content was changed), 'key_missing' (a keyed row and
	 * no WB_ENCRYPTION_KEY to check it with) or 'downgrade' (a plain row after a keyed one).
	 * $v2_seen: a keyed row came earlier in the chain (verify_chain passes it between batches).
	 *
	 * @return true|array
	 */
	public static function verify_rows( array $rows, string $prev = '', string $key = '', bool $v2_seen = false ) {
		foreach ( $rows as $r ) {
			if ( (string) ( $r['prev_hash'] ?? '' ) !== $prev ) {
				$r['broken'] = 'prev_link';
				return $r;
			}
			if ( (int) ( $r['hash_version'] ?? 1 ) >= 2 ) {
				if ( '' === $key ) {
					$r['broken'] = 'key_missing';
					return $r;
				}
				$v2_seen = true;
			} elseif ( $v2_seen ) {
				$r['broken'] = 'downgrade';
				return $r;
			}
			$want = self::hash_for( $prev, $r, $key );
			if ( '' === $want || ! hash_equals( $want, (string) ( $r['entry_hash'] ?? '' ) ) ) {
				$r['broken'] = 'hash';
				return $r;
			}
			$prev = (string) $r['entry_hash'];
		}
		return true;
	}

	/** The MAC on the tail anchor ('' without a key). */
	public static function anchor_mac( int $entry_id, string $entry_hash, string $key ): string {
		return '' === $key ? '' : hash_hmac( 'sha256', self::HASH_TAG_V2 . "\nanchor\n" . $entry_id . "\n" . $entry_hash, $key );
	}

	/** The anchor stored after an append. */
	public static function make_anchor( int $entry_id, string $entry_hash, string $key ): array {
		return [ 'entry_id' => $entry_id, 'entry_hash' => $entry_hash, 'mac' => self::anchor_mac( $entry_id, $entry_hash, $key ) ];
	}

	/**
	 * Check the stored tail anchor against the chain that was walked.
	 * $tail_id: the last entry_id in the table (0 = empty). $hash_at_anchor: the entry_hash of the
	 * row whose entry_id is the anchor's (null when no such row). $v2_seen: the chain has keyed rows.
	 * The anchor may lag the tail (a crash between insert and option update) — that is fine as long
	 * as the row it names is still there, unchanged; the chain check covers everything after it.
	 *
	 * @return true|string the reason it fails
	 */
	public static function verify_anchor( $anchor, int $tail_id, ?string $hash_at_anchor, string $key = '', bool $v2_seen = false ) {
		if ( ! is_array( $anchor ) || empty( $anchor['entry_id'] ) ) return $tail_id > 0 ? 'anchor_missing' : true;
		$aid = (int) $anchor['entry_id'];
		$ah  = (string) ( $anchor['entry_hash'] ?? '' );
		$mac = (string) ( $anchor['mac'] ?? '' );
		if ( '' !== $mac || $v2_seen ) {
			if ( '' === $key ) return 'key_missing';
			if ( ! hash_equals( self::anchor_mac( $aid, $ah, $key ), $mac ) ) return 'anchor_forged';
		}
		if ( $aid > $tail_id ) return 'truncated';
		if ( null === $hash_at_anchor ) return 'anchor_row_missing';
		if ( ! hash_equals( $ah, $hash_at_anchor ) ) return 'anchor_mismatch';
		return true;
	}

	/** The named-lock name: always 42 characters, whatever the database name (MySQL caps it at 64). */
	public static function lock_name_for( string $dbname, string $prefix ): string {
		return 'wb_ledger_' . md5( $dbname . '|' . $prefix );
	}

	/** JSON for before/after: stable key order, so the same record always hashes the same way. */
	public static function encode( $data ): string {
		if ( null === $data || [] === $data ) return '';
		if ( is_array( $data ) ) ksort( $data );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
		return false === $json ? '' : (string) $json;
	}

	/** The chain key, derived from WB_ENCRYPTION_KEY ('' when there is none). Never the encryption key itself. */
	private static function hmac_key(): string {
		$k = function_exists( 'wb_enc_key' ) ? wb_enc_key() : '';
		return '' === $k ? '' : hash_hmac( 'sha256', 'wb-ledger-chain', $k, true );
	}

	/* ------------------------------------------------------------------ writing */

	/** Start holding entries until the surrounding transaction commits (WB_Sequences::issue). */
	public static function begin_defer(): void {
		if ( null === self::$deferred ) self::$deferred = [];
	}

	public static function is_deferring(): bool {
		return null !== self::$deferred;
	}

	/**
	 * After COMMIT: write what was held, in order, then fire each entry's wb_event (S6).
	 * Returns how many entries were LOST (neither chained nor queued; 0 normally).
	 */
	public static function flush_deferred(): int {
		$items          = (array) self::$deferred;
		self::$deferred = null;
		$lost           = 0;
		foreach ( $items as $it ) {
			if ( 0 === self::append( (array) $it['row'] ) ) $lost++;
			if ( ! empty( $it['event'] ) ) self::fire( (array) $it['event'] );
		}
		return $lost;
	}

	/** After ROLLBACK: the work did not happen, so neither did its entries (nor their events). */
	public static function discard_deferred(): void {
		self::$deferred = null;
	}

	/**
	 * Append an entry. Returns entry_id, -1 when deferred, QUEUED (-2) when it could not be chained
	 * now and waits in the pending queue, 0 when it could not even be queued (the entry is then in
	 * the PHP error log in full — the caller must treat 0 as "not witnessed").
	 */
	public static function write( string $action, string $record_type = '', int $record_id = 0, $before = null, $after = null ): int {
		$row = [
			'actor_user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'action'        => substr( sanitize_key( $action ), 0, 64 ),
			'record_type'   => substr( sanitize_key( $record_type ), 0, 64 ),
			'record_id'     => max( 0, $record_id ),
			'before_json'   => self::encode( $before ),
			'after_json'    => self::encode( $after ),
			'ip'            => self::ip(),
		];
		if ( null !== self::$deferred ) {
			self::$deferred[] = [ 'row' => $row, 'event' => null ];
			return -1;
		}
		return self::append( $row );
	}

	/**
	 * write() plus the wb_event hook: fired now, or — for a deferred entry — after COMMIT (S6).
	 * This is what wb_ledger_write() calls.
	 */
	public static function record( string $action, string $type = '', int $id = 0, $before = null, $after = null ): int {
		$entry = self::write( $action, $type, $id, $before, $after );
		$event = [ $action, $type, $id, $before, $after ];
		if ( -1 === $entry && self::$deferred ) {
			self::$deferred[ count( self::$deferred ) - 1 ]['event'] = $event;
			return $entry;
		}
		self::fire( $event );
		return $entry;
	}

	private static function fire( array $event ): void {
		if ( function_exists( 'do_action' ) ) do_action( 'wb_event', ...array_values( $event ) );
	}

	private static function append( array $row ): int {
		global $wpdb;
		$keep = $wpdb->insert_id;   // the caller's own insert_id survives the ledger write
		try {
			if ( ! self::lock() ) return self::queue( $row, 'lock timeout' );
			try {
				self::drain_locked();
				$id = self::insert_locked( $row );
				return $id > 0 ? $id : self::queue( $row, 'insert failed (' . (string) $wpdb->last_error . ')' );
			} finally {
				self::unlock();
			}
		} finally {
			$wpdb->insert_id = $keep;
		}
	}

	/** Chain one row to the current tail and move the anchor. Caller holds the lock. Returns entry_id or 0. */
	private static function insert_locked( array $row ): int {
		global $wpdb;
		$t    = self::table();
		$key  = self::hmac_key();
		$wpdb->last_error = '';
		$tail = $wpdb->get_row( "SELECT entry_id, entry_hash, hash_version FROM {$t} ORDER BY entry_id DESC LIMIT 1", ARRAY_A );
		if ( ! is_array( $tail ) && '' !== (string) $wpdb->last_error ) return 0;   // cannot see the tail: never start a second chain
		$prev                = (string) ( $tail['entry_hash'] ?? '' );
		$row['prev_hash']    = $prev;
		$row['created_at']   = (string) ( $row['created_at'] ?? '' ) ?: current_time( 'mysql', true );   // a queued entry keeps its own time
		$row['hash_version'] = '' !== $key ? 2 : 1;
		$row['entry_hash']   = self::hash_for( $prev, $row, $key );
		$data = [];
		$fmt  = [];
		foreach ( self::COLUMNS as $col => $f ) {
			$data[ $col ] = $row[ $col ] ?? ( '%d' === $f ? 0 : '' );
			$fmt[]        = $f;
		}
		// Is the stored anchor still where the chain ends? Read before the insert, straight from the
		// table (another request may have moved it). If not, the anchor is NOT moved: moving it would
		// bless rows removed from the end. The entry is still chained; the nightly check reports it.
		$anchor  = self::stored_anchor();
		$tail_id = (int) ( $tail['entry_id'] ?? 0 );
		$at      = null;
		if ( is_array( $anchor ) && ! empty( $anchor['entry_id'] ) ) {
			$at = (int) $anchor['entry_id'] === $tail_id ? $prev : $wpdb->get_var( $wpdb->prepare( "SELECT entry_hash FROM {$t} WHERE entry_id = %d", (int) $anchor['entry_id'] ) );
		}
		$anchor_ok = true === self::verify_anchor( $anchor, $tail_id, null === $at ? null : (string) $at, $key, (int) ( $tail['hash_version'] ?? 1 ) >= 2 );

		if ( false === $wpdb->insert( $t, $data, $fmt ) ) return 0;
		$id = (int) $wpdb->insert_id;
		if ( $anchor_ok ) {
			update_option( self::TAIL_OPTION, self::make_anchor( $id, (string) $row['entry_hash'], $key ), false );
		} else {
			error_log( 'WB_Ledger: the stored tail anchor does not match the end of the chain; anchor left in place for the nightly check.' );
			self::alert( 'The end of the audit trail does not match its stored anchor, so entries may have been removed. Open the Integrity page and run the audit trail check.', 'wb_ledger' );
		}
		return $id;
	}

	/** The tail anchor, read from the options table itself (never a stale cache). */
	private static function stored_anchor() {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::TAIL_OPTION ) );
		return null === $v ? null : maybe_unserialize( $v );
	}

	/* ------------------------------------------------------------------ the pending queue (S1) */

	/**
	 * Keep an entry that could not be chained. One option per entry (an INSERT each — two failing
	 * writers cannot lose each other's entries), never autoloaded. Returns QUEUED, or 0 when even
	 * that failed (the full entry is in the PHP error log either way).
	 */
	private static function queue( array $row, string $why ): int {
		$row['created_at'] = (string) ( $row['created_at'] ?? '' ) ?: current_time( 'mysql', true );
		$name = self::PENDING_PREFIX . sprintf( '%.6F', microtime( true ) ) . '_' . strtolower( wp_generate_password( 8, false, false ) );
		$ok   = add_option( $name, $row, '', 'no' );
		error_log( 'WB_Ledger: ' . $why . ', entry ' . ( $ok ? 'queued as ' . $name : 'NOT chained and could not be queued' ) . ': ' . self::encode( $row ) );
		if ( ! $ok ) return 0;
		self::$has_pending = true;
		self::alert_pending();
		return self::QUEUED;
	}

	/** Queued entries, oldest first: [ option_name => row ]. Read straight from the table (no stale cache). */
	private static function pending_rows( int $limit = 500 ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
			$wpdb->esc_like( self::PENDING_PREFIX ) . '%', $limit ), ARRAY_A );
		$out = [];
		foreach ( $rows as $r ) $out[ (string) $r['option_name'] ] = maybe_unserialize( $r['option_value'] );
		return $out;
	}

	/** How many entries are waiting to be chained (for the Integrity report). */
	public static function pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PENDING_PREFIX ) . '%' ) );
	}

	/** Chain the queue, in order. Caller holds the lock. Stops at the first failure. */
	private static function drain_locked(): void {
		if ( false === self::$has_pending ) return;   // looked already this request and nothing was there
		$all = self::pending_rows();
		if ( ! $all ) {
			self::$has_pending = false;
			return;
		}
		foreach ( $all as $name => $row ) {
			if ( ! is_array( $row ) ) {   // unreadable: leave it for a person to look at, never drop it
				error_log( 'WB_Ledger: pending entry ' . $name . ' is unreadable and was left in place.' );
				continue;
			}
			if ( self::insert_locked( $row ) <= 0 ) return;   // still failing: keep the rest, in order
			if ( ! delete_option( $name ) ) error_log( 'WB_Ledger: pending entry ' . $name . ' was chained but could not be cleared; it may be chained twice.' );
		}
		self::$has_pending = null;   // there may be more than one page; look again next time
	}

	/** Drain under the lock. Returns how many entries are still waiting. */
	public static function drain(): int {
		global $wpdb;
		$keep = $wpdb->insert_id;
		self::$has_pending = null;
		if ( self::lock() ) {
			try {
				self::drain_locked();
			} finally {
				self::unlock();
			}
		}
		$wpdb->insert_id = $keep;
		return self::pending_count();
	}

	/** In-app only (the integrity group's email switch decides email, as for every notice). */
	private static function alert_pending(): void {
		self::alert( 'Some audit trail entries could not be added to the chain yet and are waiting in a queue. They are added automatically at the next change or the nightly check. If this notice stays, open the Integrity page.', 'wb_ledger_pending' );
	}

	private static function alert( string $msg, string $record_type ): void {
		if ( ! class_exists( 'WB_Notifications' ) || ! function_exists( 'home_url' ) ) return;
		try {
			WB_Notifications::notify_owners( 'integrity', $msg, WB_Workspace::url( 'integrity' ), $record_type, 0 );
		} catch ( Throwable $e ) {
			error_log( 'WB_Ledger: could not notify the owners: ' . $e->getMessage() );
		}
	}

	/** One-time: anchor the existing chain (upgrade to DB_VERSION 2). Never overwrites an anchor. */
	public static function seed_anchor(): void {
		global $wpdb;
		if ( is_array( get_option( self::TAIL_OPTION, null ) ) ) return;
		if ( ! self::lock() ) return;
		try {
			$tail = $wpdb->get_row( 'SELECT entry_id, entry_hash FROM ' . self::table() . ' ORDER BY entry_id DESC LIMIT 1', ARRAY_A );
			if ( is_array( $tail ) ) update_option( self::TAIL_OPTION, self::make_anchor( (int) $tail['entry_id'], (string) $tail['entry_hash'], self::hmac_key() ), false );
		} finally {
			self::unlock();
		}
	}

	private static function ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );   // never X-Forwarded-For: the sender controls it
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function lock_name(): string {
		global $wpdb;
		return self::lock_name_for( (string) $wpdb->dbname, (string) $wpdb->prefix );
	}

	private static function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), self::LOCK_WAIT ) );
	}

	private static function unlock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	/* ------------------------------------------------------------------ checking */

	/**
	 * Walk the whole chain in batches, then check the tail anchor. Returns true, or the first
	 * broken row (with 'broken'). Fails CLOSED: a missing table is reported as broken, never as
	 * "fine"; a missing, moved or forged anchor is a break too.
	 *
	 * @return true|array
	 */
	public static function verify_chain( int $batch = 2000 ) {
		global $wpdb;
		$t = self::table();
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
			return [ 'entry_id' => 0, 'broken' => 'missing_table' ];
		}
		$key     = self::hmac_key();
		$anchor  = get_option( self::TAIL_OPTION, null );   // read BEFORE the walk: it can only lag the rows walked
		$aid     = is_array( $anchor ) ? (int) ( $anchor['entry_id'] ?? 0 ) : 0;
		$hash_at = null;
		$v2_seen = false;
		$prev    = '';
		$after   = 0;
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE entry_id > %d ORDER BY entry_id ASC LIMIT %d", $after, $batch ), ARRAY_A );
			$res = self::verify_rows( $rows, $prev, $key, $v2_seen );
			if ( true !== $res ) return $res;
			foreach ( $rows as $r ) {
				if ( (int) $r['entry_id'] === $aid ) $hash_at = (string) $r['entry_hash'];
				if ( (int) ( $r['hash_version'] ?? 1 ) >= 2 ) $v2_seen = true;
			}
			if ( $rows ) {
				$last  = end( $rows );
				$prev  = (string) $last['entry_hash'];
				$after = (int) $last['entry_id'];
			}
		} while ( count( $rows ) === $batch );
		$a = self::verify_anchor( $anchor, $after, $hash_at, $key, $v2_seen );
		return true === $a ? true : [ 'entry_id' => $aid ?: $after, 'broken' => $a ];
	}

	/**
	 * Nightly: chain anything queued, verify, remember the result, and tell the owner(s) about a
	 * break or a queue that will not empty — in-app always.
	 */
	public static function nightly_verify(): void {
		$waiting = self::drain();
		$res     = self::verify_chain();
		update_option( 'wb_ledger_last_check', [
			'at'       => current_time( 'mysql' ),
			'ok'       => true === $res,
			'entry_id' => true === $res ? 0 : (int) ( $res['entry_id'] ?? 0 ),
			'reason'   => true === $res ? '' : (string) ( $res['broken'] ?? '' ),
			'pending'  => $waiting,
		], false );
		if ( $waiting > 0 ) self::alert_pending();
		elseif ( class_exists( 'WB_Notifications' ) ) WB_Notifications::resolve( 'wb_ledger_pending', 0, 'integrity' );
		if ( true === $res ) return;
		$id  = (int) ( $res['entry_id'] ?? 0 );
		$msg = sprintf( 'The audit trail check found a break at entry #%d (%s). Records may have been changed outside the system. Open the Integrity page.', $id, (string) ( $res['broken'] ?? '' ) );
		WB_Notifications::notify_owners( 'integrity', $msg, WB_Workspace::url( 'integrity' ), 'wb_ledger', $id );
		// The break itself is witnessed (appending is safe: the new row chains to the current tail).
		self::write( 'ledger_break_detected', 'wb_ledger', $id, null, [ 'reason' => (string) ( $res['broken'] ?? '' ) ] );
	}
}

/**
 * The one call every mutation makes. $before / $after are the record's relevant fields (arrays)
 * — null for a create's before or a pure event's after. Fires the wb_event hook so listeners
 * (notifications, touchpoints) hang off ONE stream — after COMMIT when inside a numbering
 * transaction. Returns entry_id, -1 deferred, -2 queued (witnessed, chained later), 0 not witnessed.
 */
function wb_ledger_write( string $action, string $type = '', int $id = 0, $before = null, $after = null ): int {
	return WB_Ledger::record( $action, $type, $id, $before, $after );
}
