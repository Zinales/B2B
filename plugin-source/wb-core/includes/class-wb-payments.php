<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Payments — money received, bank-statement import and matching.
 *
 * Import (0.2.0): the CSV a bank exports, or a filtered spreadsheet saved as CSV.
 *   - Delimiter auto-detected (comma, semicolon, tab); UTF-8 byte-order mark removed; quoted
 *     fields (with commas, doubled quotes and line breaks inside) read properly.
 *   - The header row need not be row 1: the person picks it from a preview of the first 10 rows.
 *   - Mapping: date, description, reference, and EITHER one signed amount column OR separate
 *     money-out (debit) and money-in (credit) columns. Date format (YYYY/MM/DD, DD/MM/YYYY,
 *     DD MMM YYYY, YYYY-MM-DD; auto-detected) and decimal style (1 234,56 or 1,234.56; R prefixes
 *     always fine). Saved as a named profile in option wb_bank_mapping (e.g. "FNB cheque").
 *   - Blank rows, balance and total rows are skipped. ONLY money in becomes a payment row; money
 *     out is counted in the summary and otherwise ignored.
 *   - Duplicate protection: line_hash of date + amount + reference + description (plus an
 *     occurrence number when one file holds the same line twice, so two identical deposits on
 *     one day both count). Importing the same file again adds nothing and says so.
 *
 * Matching, in this order, and NEVER a silent guess:
 *   1. exactly ONE open invoice number in the reference/description → matched (auto_reference),
 *      or partial when it pays less than is outstanding. More than outstanding → suggested.
 *   2. no invoice number: exactly ONE open invoice whose outstanding equals the amount →
 *      SUGGESTED (auto_amount). A person confirms it; it is never auto-matched.
 *   3. anything else (two references, two invoices of that amount, nothing) → unmatched, with a note.
 * Every match records who (matched_by_staff_id — 0 for the automatic reference match), how
 * (match_method) and when (matched_at). Manual matches need a note and are listed separately in
 * the Integrity report.
 *
 * Parsing and matching are PURE static functions (detect_delimiter, csv_rows, preview,
 * parse_money, parse_date, detect_date_format, detect_decimal, parse_statement, parse_csv,
 * line_hashes, dedupe, find_invoice_numbers, match_row) — testable without WordPress.
 */
class WB_Payments {

	const ROLES = [ 'date', 'description', 'reference', 'amount', 'debit', 'credit' ];

	/** A reference match paying less than this share of what is owed is only suggested, never auto-matched. */
	const AUTO_PART_MIN = 0.5;

	const DATE_FORMATS = [
		'auto'  => 'Work it out',
		'ymd'   => 'YYYY/MM/DD or YYYY-MM-DD',
		'dmy'   => 'DD/MM/YYYY',
		'dmony' => 'DD MMM YYYY (01 Oct 2026)',
		'mdy'   => 'MM/DD/YYYY (American)',
	];

	const DECIMALS = [
		'auto'  => 'Work it out',
		'comma' => '1 234,56 (comma for cents)',
		'dot'   => '1,234.56 (point for cents)',
	];

	/** English and Afrikaans month abbreviations (SA bank exports use both). */
	const MONTHS = [ 'jan' => 1, 'feb' => 2, 'mar' => 3, 'mrt' => 3, 'apr' => 4, 'may' => 5, 'mei' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'okt' => 10, 'nov' => 11, 'dec' => 12, 'des' => 12 ];

	public static function init(): void {}

	/* ================================================================== pure: reading the file */

	/**
	 * The 0.1.0 named layouts (columns found by header name). Kept so the REST route and old
	 * settings still work; a profile saved from the mapping step replaces them per bank.
	 */
	public static function default_mappings(): array {
		$common = [ 'delimiter' => 'auto', 'date_format' => 'auto' ];
		return [
			'generic'       => [ 'label' => 'Any bank (find the columns)' ] + $common,
			'fnb'           => [ 'label' => 'FNB', 'date' => 'Date', 'amount' => 'Amount', 'description' => 'Description', 'reference' => 'Reference' ] + $common,
			'standard_bank' => [ 'label' => 'Standard Bank', 'date' => 'Date', 'amount' => 'Amount', 'description' => 'Description', 'reference' => 'Reference' ] + $common,
			'absa'          => [ 'label' => 'Absa', 'date' => 'Date', 'amount' => 'Amount', 'description' => 'Description', 'reference' => 'Reference' ] + $common,
			'nedbank'       => [ 'label' => 'Nedbank', 'date' => 'Transaction Date', 'credit' => 'Credit', 'debit' => 'Debit', 'description' => 'Description', 'reference' => 'Reference' ] + $common,
			'capitec'       => [ 'label' => 'Capitec', 'date' => 'Transaction Date', 'credit' => 'Money In', 'debit' => 'Money Out', 'description' => 'Description', 'reference' => 'Reference' ] + $common,
		];
	}

	private static function strip_bom( string $text ): string {
		return (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
	}

	/**
	 * Comma, semicolon or tab: the one that splits the most lines into the same number of fields.
	 * (A comma inside "1 234,56" splits a line in two; a real delimiter splits every line alike.)
	 */
	public static function detect_delimiter( string $text ): string {
		$lines = array_slice( array_filter( preg_split( '/\r\n|\r|\n/', self::strip_bom( $text ) ), fn( $l ) => '' !== trim( (string) $l ) ), 0, 20 );
		$best  = ',';
		$score = -1;
		foreach ( [ ',', ';', "\t" ] as $d ) {
			$counts = [];
			foreach ( $lines as $l ) {
				$n = count( str_getcsv( (string) $l, $d, '"', '' ) );
				if ( $n > 1 ) $counts[] = $n;
			}
			if ( ! $counts ) continue;
			$freq = array_count_values( $counts );
			arsort( $freq );
			$mode = (int) array_key_first( $freq );
			$s    = $freq[ $mode ] * 1000 + $mode;
			if ( $s > $score ) { $score = $s; $best = $d; }
		}
		return $best;
	}

	/**
	 * Text → rows of trimmed cells. Quoted fields may hold the delimiter, doubled quotes and line
	 * breaks. Blank lines are kept as [''] so row numbers match what the person sees.
	 */
	public static function csv_rows( string $text, string $d ): array {
		$text = self::strip_bom( $text );
		$rows = [];
		$row  = [];
		$f    = '';
		$q    = false;
		$n    = strlen( $text );
		for ( $i = 0; $i < $n; $i++ ) {
			$c = $text[ $i ];
			if ( $q ) {
				if ( '"' === $c ) {
					if ( $i + 1 < $n && '"' === $text[ $i + 1 ] ) { $f .= '"'; $i++; continue; }
					$q = false;
					continue;
				}
				$f .= $c;
				continue;
			}
			if ( '"' === $c && '' === trim( $f ) ) { $q = true; $f = ''; continue; }
			if ( $c === $d ) { $row[] = $f; $f = ''; continue; }
			if ( "\r" === $c || "\n" === $c ) {
				$row[]  = $f;
				$rows[] = $row;
				$row    = [];
				$f      = '';
				if ( "\r" === $c && $i + 1 < $n && "\n" === $text[ $i + 1 ] ) $i++;
				continue;
			}
			$f .= $c;
		}
		if ( '' !== $f || $row ) { $row[] = $f; $rows[] = $row; }
		return array_map( fn( $r ) => array_map( 'trim', $r ), $rows );
	}

	/** The first $n rows for the "which row holds the column names?" step. */
	public static function preview( string $text, int $n = 10, string $delimiter = 'auto' ): array {
		$d = ( 'auto' === $delimiter || '' === $delimiter ) ? self::detect_delimiter( $text ) : ( 'tab' === $delimiter ? "\t" : $delimiter );
		return [ 'delimiter' => $d, 'rows' => array_slice( self::csv_rows( $text, $d ), 0, $n ) ];
	}

	/* ================================================================== pure: money and dates */

	/**
	 * "R 1 234,56", "1,234.56", "(500.00)", "500.00 Dr", "500.00-", "ZAR 1.234,56" → float.
	 * $decimal: auto | comma (1 234,56 / 1.234,56) | dot (1,234.56 / 1 234.56).
	 */
	public static function parse_money( string $s, string $decimal = 'auto' ): float {
		$s = trim( str_replace( [ "\xC2\xA0", "\xE2\x88\x92" ], [ ' ', '-' ], $s ) );   // no-break space; Unicode minus sign U+2212
		if ( '' === $s ) return 0.0;
		$u   = strtoupper( $s );
		$neg = (bool) preg_match( '/^\s*(ZAR|R)?\s*[\-(]|-\s*$|\b(DR|DT)\.?\s*$/', $u );
		$s   = (string) preg_replace( '/[^0-9.,]/', '', $s );
		if ( '' === $s ) return 0.0;
		if ( 'comma' === $decimal ) {
			$s = str_replace( [ '.', ',' ], [ '', '.' ], $s );
		} elseif ( 'dot' === $decimal ) {
			$s = str_replace( ',', '', $s );
		} else {
			$lc = strrpos( $s, ',' );
			$ld = strrpos( $s, '.' );
			if ( false !== $lc && false !== $ld ) {
				$s = $lc > $ld ? str_replace( [ '.', ',' ], [ '', '.' ], $s ) : str_replace( ',', '', $s );   // the last mark is the decimal one
			} elseif ( false !== $lc ) {
				$s = preg_match( '/,\d{1,2}$/', $s ) && 1 === substr_count( $s, ',' ) ? str_replace( ',', '.', $s ) : str_replace( ',', '', $s );   // 1234,56 vs 1,234
			} elseif ( substr_count( $s, '.' ) > 1 ) {
				$s = str_replace( '.', '', $s );   // 1.234.567
			}
		}
		$v = round( (float) $s, 2 );
		return $neg ? -$v : $v;
	}

	/** Which decimal style a column uses, from its values. Default dot. */
	public static function detect_decimal( array $samples ): string {
		$comma = 0;
		$dot   = 0;
		foreach ( $samples as $v ) {
			$s = (string) preg_replace( '/[^0-9.,]/', '', (string) $v );
			if ( '' === $s ) continue;
			$lc = strrpos( $s, ',' );
			$ld = strrpos( $s, '.' );
			if ( false !== $lc && false !== $ld ) { $lc > $ld ? $comma++ : $dot++; continue; }
			if ( false !== $lc && preg_match( '/,\d{1,2}$/', $s ) ) { $comma++; continue; }
			if ( false !== $ld && preg_match( '/\.\d{1,2}$/', $s ) ) $dot++;
		}
		return $comma > $dot ? 'comma' : 'dot';
	}

	private static function ymd( int $y, int $m, int $d ): string {
		if ( $y < 100 ) $y += 2000;
		return checkdate( $m, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $m, $d ) : '';
	}

	/** A bank date → Y-m-d ('' when unreadable). $format: auto | ymd | dmy | dmony | mdy. SA order (day first) in auto. */
	public static function parse_date( string $s, string $format = 'auto' ): string {
		$s = trim( $s, " \"'\t" );
		if ( '' === $s ) return '';
		if ( ( 'auto' === $format || 'ymd' === $format ) && preg_match( '/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})(?!\d)/', $s, $m ) ) return self::ymd( (int) $m[1], (int) $m[2], (int) $m[3] );
		if ( ( 'auto' === $format || 'ymd' === $format ) && preg_match( '/^(\d{4})(\d{2})(\d{2})(?!\d)/', $s, $m ) ) return self::ymd( (int) $m[1], (int) $m[2], (int) $m[3] );
		if ( in_array( $format, [ 'auto', 'dmy', 'mdy' ], true ) && preg_match( '/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})(?!\d)/', $s, $m ) ) {
			return 'mdy' === $format ? self::ymd( (int) $m[3], (int) $m[1], (int) $m[2] ) : self::ymd( (int) $m[3], (int) $m[2], (int) $m[1] );
		}
		if ( in_array( $format, [ 'auto', 'dmony' ], true ) && preg_match( '/^(\d{1,2})[\s\-\/]+([A-Za-z]{3,9})\.?[\s\-\/,]+(\d{2}|\d{4})(?!\d)/', $s, $m ) ) {
			$mo = self::MONTHS[ substr( strtolower( $m[2] ), 0, 3 ) ] ?? 0;
			return $mo ? self::ymd( (int) $m[3], $mo, (int) $m[1] ) : '';
		}
		return '';
	}

	/** The date format most of a column's values follow ('dmy' unless the values say otherwise). */
	public static function detect_date_format( array $samples ): string {
		$votes  = [ 'ymd' => 0, 'dmy' => 0, 'dmony' => 0 ];
		$us     = false;
		foreach ( $samples as $v ) {
			$v = trim( (string) $v, " \"'" );
			if ( preg_match( '/^\d{4}[\/\-.]?\d{1,2}[\/\-.]?\d{1,2}/', $v ) ) $votes['ymd']++;
			elseif ( preg_match( '/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.]\d{2,4}/', $v, $m ) ) {
				$votes['dmy']++;
				if ( (int) $m[2] > 12 && (int) $m[1] <= 12 ) $us = true;
			} elseif ( preg_match( '/^\d{1,2}[\s\-\/]+[A-Za-z]{3,9}/', $v ) ) $votes['dmony']++;
		}
		arsort( $votes );
		$top = (string) array_key_first( $votes );
		if ( 0 === $votes[ $top ] ) return 'auto';
		return 'dmy' === $top && $us ? 'mdy' : $top;
	}

	/* ================================================================== pure: the statement */

	/** Guess the columns from a header row. */
	public static function guess( array $cells ): array {
		$m = [];
		foreach ( $cells as $i => $c ) {
			$c = strtolower( (string) $c );
			if ( '' === $c ) continue;
			if ( ! isset( $m['date'] ) && preg_match( '/date|datum/', $c ) ) $m['date'] = $i;
			elseif ( ! isset( $m['reference'] ) && preg_match( '/reference|verwysing|\bref\b/', $c ) ) $m['reference'] = $i;
			elseif ( ! isset( $m['description'] ) && preg_match( '/descr|detail|narrat|transaction|beskrywing/', $c ) ) $m['description'] = $i;
			elseif ( ! isset( $m['credit'] ) && preg_match( '/credit|deposit|money in|paid in/', $c ) ) $m['credit'] = $i;
			elseif ( ! isset( $m['debit'] ) && preg_match( '/debit|withdraw|money out|paid out/', $c ) ) $m['debit'] = $i;
			elseif ( ! isset( $m['amount'] ) && preg_match( '/amount|bedrag/', $c ) ) $m['amount'] = $i;
		}
		return $m;
	}

	private static function find_in( array $cells, string $name ): ?int {
		$want = strtolower( trim( $name ) );
		if ( '' === $want ) return null;
		foreach ( $cells as $i => $h ) if ( strtolower( trim( (string) $h ) ) === $want ) return (int) $i;
		return null;
	}

	private static function usable( array $cols ): bool {
		return isset( $cols['date'] ) && ( isset( $cols['amount'] ) || isset( $cols['credit'] ) );
	}

	/**
	 * Which row is the header, and which column holds what. Returns [ header_row (1-based, 0 = not
	 * found), [ role => 0-based index ] ].
	 *
	 * A saved profile (header_row + columns, and the header names it was made from) is used as
	 * saved; if that row no longer carries those names (a filtered export with a different preamble)
	 * the names are looked for in the first 40 rows. The 0.1.0 named layouts and "any bank" search
	 * for the first row naming a date and an amount.
	 */
	public static function resolve_header( array $rows, array $map ): array {
		$look  = array_slice( $rows, 0, 40, true );
		$names = array_filter( (array) ( $map['names'] ?? [] ), 'strlen' );
		if ( ! empty( $map['header_row'] ) && isset( $map['columns'] ) ) {
			$i    = (int) $map['header_row'] - 1;
			$cols = array_map( 'intval', array_filter( (array) $map['columns'], fn( $v ) => '' !== $v && null !== $v ) );
			$fits = isset( $rows[ $i ] );
			foreach ( $names as $role => $nm ) {
				if ( ! isset( $cols[ $role ] ) || strtolower( (string) ( $rows[ $i ][ $cols[ $role ] ] ?? '' ) ) !== strtolower( (string) $nm ) ) { $fits = false; break; }
			}
			if ( $fits && self::usable( $cols ) ) return [ $i + 1, $cols ];
			if ( $names ) {
				foreach ( $look as $ri => $cells ) {
					$found = [];
					foreach ( $names as $role => $nm ) {
						$x = self::find_in( $cells, (string) $nm );
						if ( null === $x ) continue 2;
						$found[ $role ] = $x;
					}
					if ( self::usable( $found ) ) return [ $ri + 1, $found ];
				}
			}
			return [ 0, [] ];
		}
		$named = isset( $map['date'] ) && is_string( $map['date'] );
		foreach ( $look as $ri => $cells ) {
			$m = [];
			if ( $named ) {
				foreach ( self::ROLES as $role ) {
					if ( ! isset( $map[ $role ] ) ) continue;
					$x = self::find_in( $cells, (string) $map[ $role ] );
					if ( null !== $x ) $m[ $role ] = $x;
				}
				if ( ! isset( $m['date'] ) ) $m = self::guess( $cells );   // the bank renamed a column: find it
			} else {
				$m = self::guess( $cells );
			}
			if ( self::usable( $m ) ) return [ $ri + 1, $m ];
		}
		return [ 0, [] ];
	}

	/**
	 * A balance or total line, not a transaction. Balance phrases count wherever they start a cell;
	 * the short words (Total, Subtotal, Totaal, Saldo) only when they are the WHOLE cell — a real
	 * deposit described "Total payment INV…" or "Saldo betaling" is money in and must not be dropped.
	 */
	private static function is_total_row( array $cells ): bool {
		foreach ( $cells as $c ) {
			$c = trim( (string) $c );
			if ( preg_match( '/^(opening|closing|available)\s+balance\b|^balance\s+(brought|carried)\b|^(opening|closing|afsluiting|slot)s?saldo\b|^saldo\s+(oorgedra|oorgebring|afgebring)\b/i', $c ) ) return true;
			if ( preg_match( '/^((sub)?totals?|totaal|saldo|balance)\s*:?$/i', $c ) ) return true;
		}
		return false;
	}

	/**
	 * Read a statement. $map: delimiter (auto | , | ; | tab), header_row (1-based) + columns
	 * [ role => index ] + names [ role => header text ] (a saved profile), OR a 0.1.0 named layout;
	 * amount_mode (signed | split — inferred when absent), date_format, decimal.
	 *
	 * Returns ok, error ('' | no_header), delimiter, header_row, header, columns, date_format,
	 * decimal, lines [ [date, description, reference, amount (+ in / − out)] ], credits, debits,
	 * skipped (non-blank rows that are not transactions: balances, totals, unreadable dates).
	 */
	public static function parse_statement( string $text, array $map ): array {
		$d = (string) ( $map['delimiter'] ?? 'auto' );
		$d = ( 'auto' === $d || '' === $d ) ? self::detect_delimiter( $text ) : ( 'tab' === $d ? "\t" : $d );
		$rows = self::csv_rows( $text, $d );
		$out  = [ 'ok' => false, 'error' => '', 'delimiter' => $d, 'header_row' => 0, 'header' => [], 'columns' => [], 'date_format' => '', 'decimal' => '', 'lines' => [], 'credits' => 0, 'debits' => 0, 'skipped' => 0 ];
		[ $hr, $cols ] = self::resolve_header( $rows, $map );
		if ( ! $hr ) { $out['error'] = 'no_header'; return $out; }
		$body = array_slice( $rows, $hr );
		$mode = (string) ( $map['amount_mode'] ?? '' );
		if ( 'split' !== $mode && 'signed' !== $mode ) $mode = isset( $cols['credit'] ) ? 'split' : 'signed';
		if ( 'signed' === $mode && ! isset( $cols['amount'] ) ) $mode = 'split';
		if ( 'split' === $mode && ! isset( $cols['credit'] ) ) { $out['error'] = 'no_header'; return $out; }
		$cell = fn( array $r, string $role ): string => isset( $cols[ $role ] ) ? (string) ( $r[ $cols[ $role ] ] ?? '' ) : '';

		$fmt = (string) ( $map['date_format'] ?? 'auto' );
		if ( 'auto' === $fmt || '' === $fmt ) $fmt = self::detect_date_format( array_map( fn( $r ) => $cell( $r, 'date' ), $body ) );
		$dec = (string) ( $map['decimal'] ?? 'auto' );
		if ( 'auto' === $dec || '' === $dec ) {
			$samples = [];
			foreach ( $body as $r ) foreach ( [ 'amount', 'credit', 'debit' ] as $role ) $samples[] = $cell( $r, $role );
			$dec = self::detect_decimal( $samples );
		}
		$out = array_merge( $out, [ 'header_row' => $hr, 'header' => $rows[ $hr - 1 ], 'columns' => $cols, 'date_format' => $fmt, 'decimal' => $dec ] );

		foreach ( $body as $r ) {
			if ( '' === trim( implode( '', $r ) ) ) continue;   // blank row
			$date = self::parse_date( $cell( $r, 'date' ), $fmt );
			if ( '' === $date || self::is_total_row( $r ) ) { $out['skipped']++; continue; }
			if ( 'split' === $mode ) {
				$in  = abs( self::parse_money( $cell( $r, 'credit' ), $dec ) );
				$amt = $in > 0 ? $in : -abs( self::parse_money( $cell( $r, 'debit' ), $dec ) );
			} else {
				$amt = self::parse_money( $cell( $r, 'amount' ), $dec );
			}
			if ( 0.0 === (float) $amt ) { $out['skipped']++; continue; }
			$amt > 0 ? $out['credits']++ : $out['debits']++;
			$out['lines'][] = [
				'date'        => $date,
				'description' => mb_substr( $cell( $r, 'description' ), 0, 250 ),
				'reference'   => mb_substr( $cell( $r, 'reference' ), 0, 120 ),
				'amount'      => $amt,
			];
		}
		$out['ok'] = true;
		return $out;
	}

	/** 0.1.0 entry point: lines (debits negative) or 'no_header'. @return array|string */
	public static function parse_csv( string $csv, array $mapping ) {
		$p = self::parse_statement( $csv, $mapping );
		return $p['ok'] ? $p['lines'] : 'no_header';
	}

	/* ================================================================== pure: duplicates */

	/** The fingerprint of one line: date + amount + reference + description (whitespace and case folded). */
	public static function line_hash( array $row, int $occurrence = 1 ): string {
		$norm = fn( $s ) => strtolower( (string) preg_replace( '/\s+/', ' ', trim( (string) $s ) ) );
		$base = implode( '|', [ (string) $row['date'], number_format( (float) $row['amount'], 2, '.', '' ), $norm( $row['reference'] ?? '' ), $norm( $row['description'] ?? '' ) ] );
		return sha1( $occurrence > 1 ? $base . '|#' . $occurrence : $base );
	}

	/** One hash per line, in order. The 2nd identical line in the same file gets occurrence 2, and so on. */
	public static function line_hashes( array $lines ): array {
		$seen = [];
		$out  = [];
		foreach ( $lines as $i => $l ) {
			$k          = self::line_hash( $l );
			$seen[ $k ] = ( $seen[ $k ] ?? 0 ) + 1;
			$out[ $i ]  = self::line_hash( $l, $seen[ $k ] );
		}
		return $out;
	}

	/**
	 * Split lines into new and already-imported. $existing = hashes already stored (as keys or values).
	 * Returns [ 'new' => [ [line, hash], … ], 'duplicates' => n ].
	 */
	public static function dedupe( array $lines, array $existing ): array {
		$have = array_fill_keys( array_map( 'strval', array_values( $existing ) ), true );
		$new  = [];
		$dup  = 0;
		foreach ( self::line_hashes( $lines ) as $i => $h ) {
			if ( isset( $have[ $h ] ) ) { $dup++; continue; }
			$have[ $h ] = true;
			$new[]      = [ $lines[ $i ], $h ];
		}
		return [ 'new' => $new, 'duplicates' => $dup ];
	}

	/* ================================================================== pure: matching */

	/**
	 * Invoice numbers mentioned in free text, normalised to PREFIX-YYYY-000123. Accepts the ways
	 * people type them: "INV-2026-000123", "inv 2026 123", "INV2026000123", "INV-2026-123".
	 */
	public static function find_invoice_numbers( string $text, string $prefix ): array {
		$p = preg_quote( $prefix, '/' );
		// The number may not run on into decimals: "INV 2026 500.00" is a year and an amount, not INV-2026-000500.
		if ( ! preg_match_all( '/' . $p . '[\s\-\/#:]*(20\d{2})[\s\-\/]*0*(\d{1,9})(?!\d|[.,]\d)/i', $text, $m, PREG_SET_ORDER ) ) return [];
		$out = [];
		foreach ( $m as $x ) $out[] = sprintf( '%s-%04d-%06d', strtoupper( $prefix ), (int) $x[1], (int) $x[2] );
		return array_values( array_unique( $out ) );
	}

	/**
	 * Match one bank line against the open invoices. $open = [ [ _ID, invoice_number, outstanding, customer_id ], … ].
	 * Returns match_status, match_method, invoice_id (set only for matched/partial), suggested_invoice_id, allocate, note.
	 */
	public static function match_row( array $row, array $open, string $prefix ): array {
		$amt  = round( (float) $row['amount'], 2 );
		$none = [ 'match_status' => 'unmatched', 'match_method' => '', 'invoice_id' => 0, 'suggested_invoice_id' => 0, 'allocate' => 0.0, 'customer_id' => 0, 'note' => '' ];
		if ( $amt <= 0 ) return [ 'note' => 'not_a_deposit' ] + $none;
		$by_num = [];
		foreach ( $open as $i ) $by_num[ strtoupper( (string) $i['invoice_number'] ) ] = $i;

		$nums = self::find_invoice_numbers( (string) ( $row['reference'] ?? '' ) . ' ' . (string) ( $row['description'] ?? '' ), $prefix );
		if ( count( $nums ) > 1 ) return [ 'note' => 'several_references' ] + $none;   // ambiguous: a person decides
		if ( 1 === count( $nums ) ) {
			$inv = $by_num[ $nums[0] ] ?? null;
			if ( ! $inv ) return [ 'note' => 'reference_not_open:' . $nums[0] ] + $none;
			$out = round( (float) $inv['outstanding'], 2 );
			$base = [ 'match_method' => 'auto_reference', 'customer_id' => (int) ( $inv['customer_id'] ?? 0 ), 'note' => '' ];
			$suggest = fn( string $why ) => [ 'match_status' => 'suggested', 'match_method' => 'auto_reference', 'invoice_id' => 0, 'suggested_invoice_id' => (int) $inv['_ID'], 'allocate' => 0.0, 'customer_id' => (int) ( $inv['customer_id'] ?? 0 ), 'note' => $why ];
			// The payer is known (e.g. a receipt) and it is not the invoice's customer: a person checks.
			$payer = (int) ( $row['customer_id'] ?? 0 );
			if ( $payer > 0 && $payer !== (int) ( $inv['customer_id'] ?? 0 ) ) return $suggest( 'other_customer' );
			if ( abs( $amt - $out ) < 0.005 ) return $base + [ 'match_status' => 'matched', 'invoice_id' => (int) $inv['_ID'], 'suggested_invoice_id' => 0, 'allocate' => $amt ];
			if ( $amt < $out * self::AUTO_PART_MIN ) return $suggest( 'small_part_payment' );   // a small part of what is owed: maybe a typo'd reference
			if ( $amt < $out ) return $base + [ 'match_status' => 'partial', 'invoice_id' => (int) $inv['_ID'], 'suggested_invoice_id' => 0, 'allocate' => $amt ];
			return [ 'match_status' => 'suggested', 'match_method' => 'auto_reference', 'invoice_id' => 0, 'suggested_invoice_id' => (int) $inv['_ID'], 'allocate' => 0.0, 'customer_id' => (int) ( $inv['customer_id'] ?? 0 ), 'note' => 'more_than_outstanding' ];
		}
		$cands = array_values( array_filter( $open, fn( $i ) => abs( round( (float) $i['outstanding'], 2 ) - $amt ) < 0.005 ) );
		if ( 1 === count( $cands ) ) {
			return [ 'match_status' => 'suggested', 'match_method' => 'auto_amount', 'invoice_id' => 0, 'suggested_invoice_id' => (int) $cands[0]['_ID'], 'allocate' => 0.0, 'customer_id' => 0, 'note' => '' ];   // never auto-matched
		}
		return [ 'note' => count( $cands ) > 1 ? 'several_amount_candidates' : 'no_candidate' ] + $none;
	}

	/**
	 * Was this bank line already recorded at the counter as an EFT? $receipts = counter EFT
	 * payments [ _ID, amount, bank_reference ]. Same amount, and the slip reference typed at the
	 * counter (4+ characters) appears in the line's reference or description → that payment's id;
	 * else 0. A hit is never imported as new money without a person looking (M8).
	 */
	public static function counter_duplicate( array $row, array $receipts ): int {
		$text = strtolower( (string) ( $row['reference'] ?? '' ) . ' ' . (string) ( $row['description'] ?? '' ) );
		$squash = fn( $s ) => (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $s ) );
		foreach ( $receipts as $r ) {
			if ( abs( round( (float) $r['amount'], 2 ) - round( (float) $row['amount'], 2 ) ) >= 0.005 ) continue;
			$ref = $squash( $r['bank_reference'] ?? '' );
			if ( strlen( $ref ) >= 4 && false !== strpos( $squash( $text ), $ref ) ) return (int) $r['_ID'];
		}
		return 0;
	}


	/* ================================================================== WordPress side */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wb_bank_imports';
	}

	/**
	 * 0.2.0: the line count is `line_count` (ROWS is a reserved word from MySQL 8.0.2, so 0.1.0's
	 * `rows` column could not be created there); `debits` and `skipped` are counted too.
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE " . self::table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			file_name VARCHAR(190) NOT NULL DEFAULT '',
			bank VARCHAR(60) NOT NULL DEFAULT '',
			line_count INT UNSIGNED NOT NULL DEFAULT 0,
			credits INT UNSIGNED NOT NULL DEFAULT 0,
			debits INT UNSIGNED NOT NULL DEFAULT 0,
			skipped INT UNSIGNED NOT NULL DEFAULT 0,
			matched INT UNSIGNED NOT NULL DEFAULT 0,
			suggested INT UNSIGNED NOT NULL DEFAULT 0,
			unmatched INT UNSIGNED NOT NULL DEFAULT 0,
			duplicates INT UNSIGNED NOT NULL DEFAULT 0,
			imported_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			imported_at DATETIME NOT NULL,
			storage_key VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/** Saved layouts: [ key => profile ]. The 0.1.0 named layouts stay available beneath. */
	public static function profiles(): array {
		$saved = (array) get_option( 'wb_bank_mapping', [] );
		return array_merge( self::default_mappings(), array_filter( $saved, 'is_array' ) );
	}

	public static function profile( string $key ): ?array {
		$all = self::profiles();
		return isset( $all[ $key ] ) ? (array) $all[ $key ] : null;
	}

	/**
	 * Save a layout from the mapping step under a name ("FNB cheque", "Filtered export").
	 * $p: header_row, columns [role => index], names [role => header text], amount_mode,
	 * date_format, decimal, delimiter. Same name again = that layout is updated. Ledgered.
	 *
	 * @return string|WP_Error the profile key
	 */
	public static function save_profile( string $name, array $p ) {
		if ( ! current_user_can( 'wb_import_bank' ) ) return new WP_Error( 'wb_forbidden', 'You cannot set up bank imports.' );
		$name = mb_substr( sanitize_text_field( $name ), 0, 60 );
		if ( '' === $name ) return new WP_Error( 'wb_no_name', 'Give this layout a name, for example "FNB cheque".' );
		$key  = sanitize_key( str_replace( ' ', '_', strtolower( $name ) ) );
		if ( '' === $key ) return new WP_Error( 'wb_no_name', 'Use letters or numbers in the layout name.' );
		$cols = [];
		foreach ( self::ROLES as $r ) if ( isset( $p['columns'][ $r ] ) && '' !== (string) $p['columns'][ $r ] ) $cols[ $r ] = (int) $p['columns'][ $r ];
		$mode = 'split' === ( $p['amount_mode'] ?? '' ) ? 'split' : 'signed';
		if ( ! isset( $cols['date'] ) ) return new WP_Error( 'wb_map', 'Choose the column with the date.' );
		if ( 'signed' === $mode && ! isset( $cols['amount'] ) ) return new WP_Error( 'wb_map', 'Choose the amount column, or switch to separate money-in and money-out columns.' );
		if ( 'split' === $mode && ! isset( $cols['credit'] ) ) return new WP_Error( 'wb_map', 'Choose the money-in (credit) column.' );
		if ( 'signed' === $mode ) unset( $cols['credit'], $cols['debit'] ); else unset( $cols['amount'] );
		if ( count( $cols ) !== count( array_unique( $cols ) ) ) return new WP_Error( 'wb_map', 'Each column can only be used once.' );
		$names = [];
		foreach ( $cols as $r => $i ) $names[ $r ] = (string) ( $p['names'][ $r ] ?? '' );
		$prof = [
			'label' => $name, 'header_row' => max( 1, (int) ( $p['header_row'] ?? 1 ) ), 'columns' => $cols, 'names' => array_filter( $names, 'strlen' ),
			'amount_mode' => $mode, 'date_format' => array_key_exists( (string) ( $p['date_format'] ?? 'auto' ), self::DATE_FORMATS ) ? (string) $p['date_format'] : 'auto',
			'decimal' => array_key_exists( (string) ( $p['decimal'] ?? 'auto' ), self::DECIMALS ) ? (string) $p['decimal'] : 'auto',
			'delimiter' => in_array( $p['delimiter'] ?? 'auto', [ ',', ';', 'tab', "\t", 'auto' ], true ) ? ( "\t" === $p['delimiter'] ? 'tab' : (string) $p['delimiter'] ) : 'auto',
			'saved_at' => wb_now(), 'saved_by' => get_current_user_id(),
		];
		$all    = (array) get_option( 'wb_bank_mapping', [] );
		$before = $all[ $key ] ?? null;
		$all[ $key ] = $prof;
		update_option( 'wb_bank_mapping', $all );
		wb_ledger_write( 'bank_mapping_saved', 'wp_options', 0, $before ? [ $key => $before ] : null, [ $key => $prof ] );
		return $key;
	}

	/** Open invoices as the matcher wants them. */
	private static function open_invoices(): array {
		$out = [];
		foreach ( WB_CCT::find( 'wb_invoices', [ 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 5000 ] ) as $i ) {
			$o = WB_Invoices::outstanding( $i );
			if ( $o > 0.004 ) $out[ (int) $i['_ID'] ] = [ '_ID' => (int) $i['_ID'], 'invoice_number' => (string) $i['invoice_number'], 'outstanding' => $o, 'customer_id' => (int) $i['customer_id'] ];
		}
		return $out;
	}

	/** Import with a saved layout (REST route and the one-step import). @return array|WP_Error */
	public static function import( string $csv, string $bank, string $file_name = '', string $storage_key = '' ) {
		$bank = sanitize_key( $bank );
		$map  = self::profile( $bank ) ?? self::default_mappings()['generic'];
		return self::import_statement( $csv, $map, $bank ?: 'generic', $file_name, $storage_key );
	}

	/**
	 * Read the statement with $map, store new money-in lines as payments, match them.
	 * Returns the summary (lines, credits, debits, skipped, duplicates, matched, suggested, unmatched,
	 * nothing_new) or WP_Error.
	 *
	 * @return array|WP_Error
	 */
	public static function import_statement( string $csv, array $map, string $profile, string $file_name = '', string $storage_key = '' ) {
		if ( ! current_user_can( 'wb_import_bank' ) ) return new WP_Error( 'wb_forbidden', 'You cannot import bank statements.' );
		$cols = WB_CCT::require_columns( 'wb_payments', [ 'amount', 'invoice_id', 'match_status', 'match_method', 'matched_by_staff_id', 'matched_at', 'line_hash', 'suggested_invoice_id' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		$p = self::parse_statement( $csv, $map );
		if ( ! $p['ok'] ) return new WP_Error( 'wb_no_header', 'The date and amount columns were not found. Choose the row with the column names and map the columns again.' );

		// Duplicates: look up only the hashes this file could produce (never "load every payment").
		$hashes   = self::line_hashes( $p['lines'] );
		$credit_h = [];
		foreach ( $p['lines'] as $i => $l ) if ( $l['amount'] > 0 ) $credit_h[] = $hashes[ $i ];
		$existing = [];
		foreach ( array_chunk( $credit_h, 200 ) as $chunk ) {
			foreach ( WB_CCT::find( 'wb_payments', [ 'line_hash' => $chunk ], [ 'limit' => 5000, 'active_only' => false ] ) as $row ) $existing[] = (string) $row['line_hash'];
		}
		$credits = array_values( array_filter( $p['lines'], fn( $l ) => $l['amount'] > 0 ) );
		$split   = self::dedupe( $credits, $existing );
		// EFTs already recorded at the counter (no import batch) that this file may show again.
		$receipts = [];
		$amounts  = array_values( array_unique( array_map( fn( $x ) => number_format( (float) $x[0]['amount'], 2, '.', '' ), $split['new'] ) ) );
		foreach ( array_chunk( $amounts, 200 ) as $chunk ) {
			foreach ( WB_CCT::find( 'wb_payments', [ 'method' => 'eft', 'amount' => $chunk ], [ 'limit' => 5000, 'active_only' => false ] ) as $row ) {
				if ( empty( $row['import_batch_id'] ) ) $receipts[ (int) $row['_ID'] ] = $row;
			}
		}

		global $wpdb;
		if ( '' === $storage_key ) $storage_key = WB_Storage::put_contents( $csv, 'bank/' . gmdate( 'Y/m' ) . '/' . ( sanitize_file_name( $file_name ) ?: 'statement.csv' ) );
		$wpdb->insert( self::table(), [
			'file_name' => mb_substr( sanitize_file_name( $file_name ), 0, 190 ), 'bank' => mb_substr( $profile, 0, 60 ), 'line_count' => count( $p['lines'] ),
			'credits' => $p['credits'], 'debits' => $p['debits'], 'skipped' => $p['skipped'], 'duplicates' => $split['duplicates'],
			'imported_by' => get_current_user_id(), 'imported_at' => wb_now(), 'storage_key' => $storage_key,
		] );
		$batch = (int) $wpdb->insert_id;
		if ( ! $batch ) return new WP_Error( 'wb_insert_failed', 'The import could not be started.' );

		$open   = self::open_invoices();
		$prefix = WB_Sequences::prefix( 'INV' );
		$sum    = [ 'batch' => $batch, 'lines' => count( $p['lines'] ), 'credits' => $p['credits'], 'debits' => $p['debits'], 'skipped' => $p['skipped'], 'duplicates' => $split['duplicates'], 'new' => count( $split['new'] ), 'matched' => 0, 'suggested' => 0, 'unmatched' => 0 ];
		foreach ( $split['new'] as [ $r, $hash ] ) {
			$dup = $receipts ? self::counter_duplicate( $r, $receipts ) : 0;
			$m   = $dup
				? [ 'match_status' => 'unmatched', 'match_method' => '', 'invoice_id' => 0, 'suggested_invoice_id' => 0, 'allocate' => 0.0, 'customer_id' => (int) ( $receipts[ $dup ]['customer_id'] ?? 0 ), 'note' => 'possible_duplicate_of_receipt:' . $dup ]
				: self::match_row( $r, array_values( $open ), $prefix );
			if ( $dup ) unset( $receipts[ $dup ] );   // one bank line per counter receipt
			$pid = WB_CCT::insert( 'wb_payments', [
				'received_at' => $r['date'], 'amount' => $r['amount'], 'method' => 'eft', 'bank_reference' => sanitize_text_field( $r['reference'] ),
				'bank_description' => sanitize_text_field( $r['description'] ), 'import_batch_id' => $batch, 'line_hash' => $hash,
				'invoice_id' => 0, 'customer_id' => $m['customer_id'], 'match_status' => 'unmatched', 'match_method' => '',
				'suggested_invoice_id' => $m['suggested_invoice_id'], 'amount_allocated' => 0, 'match_note' => $m['note'],
			], 'payment_imported' );
			if ( is_wp_error( $pid ) ) { $sum['unmatched']++; continue; }
			if ( in_array( $m['match_status'], [ 'matched', 'partial' ], true ) ) {
				$ok = WB_Invoices::apply_payment( $m['invoice_id'], $m['allocate'], (int) $pid, true );
				if ( true === $ok ) {
					WB_CCT::update( 'wb_payments', (int) $pid, [ 'invoice_id' => $m['invoice_id'], 'match_status' => $m['match_status'], 'match_method' => 'auto_reference', 'matched_by_staff_id' => 0, 'matched_at' => wb_now(), 'amount_allocated' => $m['allocate'] ], 'payment_matched_auto' );
					$open[ $m['invoice_id'] ]['outstanding'] = round( $open[ $m['invoice_id'] ]['outstanding'] - $m['allocate'], 2 );
					if ( $open[ $m['invoice_id'] ]['outstanding'] <= 0.004 ) unset( $open[ $m['invoice_id'] ] );
					$sum['matched']++;
					continue;
				}
				WB_CCT::update( 'wb_payments', (int) $pid, [ 'match_note' => 'auto_match_refused: ' . $ok->get_error_code() ], 'payment_match_refused' );
				$sum['unmatched']++;
				continue;
			}
			if ( 'suggested' === $m['match_status'] ) {
				WB_CCT::update( 'wb_payments', (int) $pid, [ 'match_status' => 'suggested', 'match_method' => $m['match_method'] ], 'payment_suggested' );
				$sum['suggested']++;
				continue;
			}
			$sum['unmatched']++;
		}
		$sum['nothing_new'] = 0 === $sum['new'];
		$wpdb->update( self::table(), [ 'matched' => $sum['matched'], 'suggested' => $sum['suggested'], 'unmatched' => $sum['unmatched'] ], [ 'id' => $batch ] );
		wb_ledger_write( 'bank_imported', 'wb_bank_imports', $batch, null, $sum + [ 'profile' => $profile, 'file' => $file_name ] );
		if ( $sum['suggested'] || $sum['unmatched'] ) {
			WB_Notifications::notify_cap( 'wb_match_payments', 'money', sprintf( 'Bank import: %d matched, %d to confirm, %d to match by hand.', $sum['matched'], $sum['suggested'], $sum['unmatched'] ), WB_Workspace::url( 'payments' ), 'wb_bank_imports', $batch );
		}
		return $sum;
	}

	/** The plain-English result of an import. */
	public static function summary_text( array $s ): string {
		$read = sprintf( 'Read %d transactions: %d money in, %d money out (ignored)%s.', $s['lines'], $s['credits'], $s['debits'], $s['skipped'] ? sprintf( ', %d other rows skipped (balances, totals)', $s['skipped'] ) : '' );
		if ( ! empty( $s['nothing_new'] ) ) return $read . ' Nothing new: every money-in line in this file was already imported.';
		return $read . sprintf( ' New: %d matched, %d to confirm, %d to match by hand.', $s['matched'], $s['suggested'], $s['unmatched'] ) . ( $s['duplicates'] ? sprintf( ' %d already imported before, not added again.', $s['duplicates'] ) : '' );
	}


	/** Allocate a payment's unallocated remainder to an invoice and record who/how/when. */
	private static function allocate( array $pay, int $invoice_id, string $method, string $note ) {
		$staff = WB_Staff::current_staff_id();
		if ( ! $staff ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record, so the match cannot carry your name.' );
		$cols = WB_CCT::require_columns( 'wb_payments', [ 'allocations_json', 'matched_by_staff_id', 'amount_allocated' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		$inv = WB_CCT::get( 'wb_invoices', $invoice_id );
		if ( ! $inv ) return new WP_Error( 'wb_not_found', 'Invoice not found.' );
		$remaining = round( (float) $pay['amount'] - (float) ( $pay['amount_allocated'] ?? 0 ), 2 );
		if ( $remaining <= 0.004 ) return new WP_Error( 'wb_fully_allocated', 'This payment is already fully allocated.' );
		$put = min( $remaining, WB_Invoices::outstanding( $inv ) );
		if ( $put <= 0.004 ) return new WP_Error( 'wb_nothing_owed', 'Nothing is outstanding on that invoice.' );
		$ok = WB_Invoices::apply_payment( $invoice_id, $put, (int) $pay['_ID'], true );
		if ( is_wp_error( $ok ) ) return $ok;
		$left   = round( $remaining - $put, 2 );
		$inv    = WB_CCT::get( 'wb_invoices', $invoice_id );
		$status = $left > 0.004 ? 'unallocated' : ( WB_Invoices::outstanding( $inv ) > 0.004 ? 'partial' : 'matched' );
		// Every allocation is kept (M11): a payment split over two invoices keeps its first invoice
		// in invoice_id, and the full list in allocations_json, so credit notes still pair with it.
		$allocs = WB_CCT::json( $pay['allocations_json'] ?? '' );
		if ( ! $allocs && (int) ( $pay['invoice_id'] ?? 0 ) && (float) ( $pay['amount_allocated'] ?? 0 ) > 0.004 ) {
			$allocs[] = [ 'invoice_id' => (int) $pay['invoice_id'], 'amount' => wb_money( $pay['amount_allocated'] ) ];
		}
		$allocs[] = [ 'invoice_id' => $invoice_id, 'amount' => wb_money( $put ), 'at' => wb_now(), 'by_staff_id' => $staff, 'method' => $method ];
		return WB_CCT::update( 'wb_payments', (int) $pay['_ID'], [
			'invoice_id' => (int) ( $pay['invoice_id'] ?? 0 ) ?: $invoice_id, 'customer_id' => (int) $inv['customer_id'], 'match_status' => $status, 'match_method' => $method,
			'allocations_json' => $allocs,
			'matched_by_staff_id' => $staff, 'matched_at' => wb_now(),
			'amount_allocated' => wb_money( (float) ( $pay['amount_allocated'] ?? 0 ) + $put ), 'match_note' => mb_substr( $note, 0, 200 ),
		], 'manual' === $method ? 'payment_matched_manual' : 'payment_suggestion_confirmed' );
	}

	/** A person confirms the suggestion. Who confirmed is recorded. @return true|WP_Error */
	public static function confirm_suggestion( int $payment_id ) {
		if ( ! current_user_can( 'wb_match_payments' ) ) return new WP_Error( 'wb_forbidden', 'You cannot match payments.' );
		$pay = WB_CCT::get( 'wb_payments', $payment_id );
		if ( ! $pay || 'suggested' !== $pay['match_status'] || empty( $pay['suggested_invoice_id'] ) ) return new WP_Error( 'wb_no_suggestion', 'There is no suggestion to confirm on that payment.' );
		return self::allocate( $pay, (int) $pay['suggested_invoice_id'], (string) ( $pay['match_method'] ?: 'auto_amount' ), 'confirmed suggestion' );
	}

	/** Match by hand. A note saying why is required (it is what the Integrity report shows). @return true|WP_Error */
	public static function manual_match( int $payment_id, int $invoice_id, string $note ) {
		if ( ! current_user_can( 'wb_match_payments' ) ) return new WP_Error( 'wb_forbidden', 'You cannot match payments.' );
		if ( '' === trim( $note ) ) return new WP_Error( 'wb_no_note', 'Say how you know this payment is for that invoice.' );
		$pay = WB_CCT::get( 'wb_payments', $payment_id );
		if ( ! $pay || ! in_array( $pay['match_status'], [ 'unmatched', 'suggested', 'unallocated' ], true ) ) return new WP_Error( 'wb_already_matched', 'That payment is already matched.' );
		return self::allocate( $pay, $invoice_id, 'manual', sanitize_text_field( $note ) );
	}

	/** Money from a known customer that isn't for an invoice yet. @return true|WP_Error */
	public static function mark_unallocated( int $payment_id, int $customer_id, string $note ) {
		if ( ! current_user_can( 'wb_match_payments' ) ) return new WP_Error( 'wb_forbidden', 'You cannot match payments.' );
		$pay = WB_CCT::get( 'wb_payments', $payment_id );
		if ( ! $pay || ! in_array( $pay['match_status'], [ 'unmatched', 'suggested' ], true ) ) return new WP_Error( 'wb_already_matched', 'That payment is already matched.' );
		if ( ! WB_CCT::get( 'wb_customers', $customer_id ) ) return new WP_Error( 'wb_no_customer', 'Choose the customer.' );
		$staff = WB_Staff::current_staff_id();
		if ( ! $staff ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record, so this cannot carry your name.' );
		return WB_CCT::update( 'wb_payments', $payment_id, [ 'customer_id' => $customer_id, 'match_status' => 'unallocated', 'match_method' => 'manual', 'matched_by_staff_id' => $staff, 'matched_at' => wb_now(), 'match_note' => mb_substr( sanitize_text_field( $note ), 0, 200 ) ], 'payment_unallocated' );
	}

	/**
	 * Cash or card taken at the counter, recorded against the invoice BEFORE goods are released (§8).
	 *
	 * @return int|WP_Error payment id
	 */
	public static function record_receipt( int $invoice_id, float $amount, string $method, string $reference ) {
		if ( ! current_user_can( 'wb_match_payments' ) ) return new WP_Error( 'wb_forbidden', 'You cannot record payments.' );
		if ( ! in_array( $method, [ 'cash', 'card', 'eft' ], true ) ) return new WP_Error( 'wb_method', 'Choose how it was paid.' );
		if ( '' === trim( $reference ) ) return new WP_Error( 'wb_no_ref', 'Write the receipt or slip number.' );
		$inv = WB_CCT::get( 'wb_invoices', $invoice_id );
		if ( ! $inv ) return new WP_Error( 'wb_not_found', 'Invoice not found.' );
		if ( $amount <= 0 || $amount > WB_Invoices::outstanding( $inv ) + 0.004 ) return new WP_Error( 'wb_bad_amount', sprintf( 'Enter an amount up to %s.', number_format( WB_Invoices::outstanding( $inv ), 2 ) ) );
		if ( ! WB_Staff::current_staff_id() ) return new WP_Error( 'wb_no_staff', 'Your login is not linked to a staff record, so the receipt cannot carry your name.' );
		$cols = WB_CCT::require_columns( 'wb_payments', [ 'line_hash', 'allocations_json' ] );
		if ( is_wp_error( $cols ) ) return $cols;
		$hash = sha1( 'receipt|' . $method . '|' . $reference . '|' . $invoice_id . '|' . $amount );
		if ( WB_CCT::first( 'wb_payments', [ 'line_hash' => $hash ], [ 'active_only' => false ] ) ) {
			return new WP_Error( 'wb_duplicate_receipt', sprintf( 'Receipt %s for this amount is already recorded on this invoice, so it was not added again.', sanitize_text_field( $reference ) ) );
		}
		$pid = WB_CCT::insert( 'wb_payments', [
			'received_at' => wb_today(), 'amount' => wb_money( $amount ), 'method' => $method, 'bank_reference' => sanitize_text_field( $reference ),
			'invoice_id' => 0, 'customer_id' => (int) $inv['customer_id'], 'match_status' => 'unmatched', 'amount_allocated' => 0,
			'line_hash' => $hash,
		], 'payment_received_' . $method );
		if ( is_wp_error( $pid ) ) return $pid;
		$ok = self::allocate( WB_CCT::get( 'wb_payments', (int) $pid ), $invoice_id, 'manual', 'recorded at the counter' );
		return is_wp_error( $ok ) ? $ok : (int) $pid;
	}

	public static function batches( int $limit = 50 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}
}
