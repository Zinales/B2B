<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Import — upload a whole table from a CSV, and get it back out (0.3.6, Kaycie's KC_Import).
 *
 * "Upload all data points" half two. One flow for every master table in WB_Records::TABLES:
 *  1. Download a blank template (the typed columns, _ID first, one example row) or an export of
 *     what is there now (the same layout with the data in it, so a round trip is a plain edit).
 *  2. Choose the file; "Check file (no import)" or "Validate and import".
 *  3. Every row is checked before anything is written: headers must be known (names or screen
 *     words, any case), required columns present, values cleaned the way the form cleans them,
 *     references resolved by ID or exact name, natural keys unique in the file and on file.
 *     Any problem rejects the whole file and nothing changes; the problems are listed by row.
 *  4. All or nothing: rows are written in one transaction through WB_CCT (so each is ledgered),
 *     then one audit entry says how many were created and updated and by whom.
 *
 * The reader is WB_Payments' (comma, semicolon or tab, BOM, quoted line breaks). Engine-owned
 * columns are never in a file. Encrypted columns are typed in plain and stored encrypted. The file
 * is never kept. Generated cells are formula-guarded ('=', '+', '-', '@' get a leading apostrophe).
 */
class WB_Import {

	const MAX_BYTES  = 5 * 1024 * 1024;
	const MAX_ROWS   = 5000;
	const SHOW_ERRS  = 25;

	public static function init(): void {
		add_filter( 'wb_panel_handlers', function ( array $h ): array { $h['record_import'] = [ __CLASS__, 'handle_import' ]; return $h; } );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_file' ] );
	}

	/* ------------------------------------------------------------------ the layout */

	/** The columns a file carries for a table: _ID first (blank = add, filled = update), then every typed field. */
	public static function headers( string $slug ): array {
		return array_merge( [ '_ID' ], array_keys( WB_Records::fields( $slug ) ) );
	}

	/** One example row, from the form's placeholders and defaults, with every yes/no off. Pure. */
	public static function example_row( string $slug ): array {
		$row = [ '' ];
		foreach ( WB_Records::fields( $slug ) as $name => $f ) {
			if ( '' !== $f['placeholder'] && ! $f['enc'] ) { $row[] = str_replace( "\n", ' ', $f['placeholder'] ); continue; }
			if ( is_array( $f['ref'] ) || 'users' === $f['ref'] ) { $row[] = ''; continue; }
			if ( null !== $f['options'] ) { $row[] = isset( $f['options']['false'] ) ? 'false' : (string) ( '' !== (string) $f['default'] ? $f['default'] : array_key_first( $f['options'] ) ); continue; }
			$row[] = (string) $f['default'];
		}
		return $row;
	}

	/** Header text → column name: the column's name or its screen word, any case, spaces or underscores. Pure. */
	public static function header_map( string $slug ): array {
		$map = [ '_id' => '_ID', 'id' => '_ID', 'no.' => '_ID', 'no' => '_ID' ];
		foreach ( WB_Records::fields( $slug ) as $name => $f ) {
			$map[ self::norm( $name ) ]       = $name;
			$map[ self::norm( $f['label'] ) ] = $name;
			if ( $f['enc'] ) $map[ self::norm( substr( $name, 0, -4 ) ) ] = $name;
		}
		return $map;
	}

	public static function norm( string $h ): string {
		$h = strtolower( trim( $h, " \t\"'" ) );
		$h = preg_replace( '/[^a-z0-9%]+/', '_', $h );
		return trim( (string) $h, '_' );
	}

	/** A cell that cannot run as a formula when the file is opened in a spreadsheet. Pure. */
	public static function guard( string $v ): string {
		return '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ? "'" . $v : $v;
	}

	public static function unguard( string $v ): string {
		return '' !== $v && "'" === $v[0] && strlen( $v ) > 1 && false !== strpos( "=+-@", $v[1] ) ? substr( $v, 1 ) : $v;
	}

	/** Rows → CSV text (comma, quoted, CRLF, BOM so Excel reads UTF-8). Pure. */
	public static function to_csv( array $rows ): string {
		$out = "\xEF\xBB\xBF";
		foreach ( $rows as $r ) {
			$cells = [];
			foreach ( $r as $c ) { $c = self::guard( (string) $c ); $cells[] = '"' . str_replace( '"', '""', $c ) . '"'; }
			$out .= implode( ',', $cells ) . "\r\n";
		}
		return $out;
	}

	/* ------------------------------------------------------------------ reading a file */

	/**
	 * Check a whole file against a table. Pure apart from $lookup, which resolves a reference:
	 * $lookup( ref_cct|'users', label_col|null, value ) → id | null; and $existing, which answers
	 * the natural-key and id questions: $existing( slug, column, value ) → row | null.
	 *
	 * Returns [ 'rows' => [ [ id, clean row ], … ], 'errors' => [ 'Row 7: …', … ], 'create' => n, 'update' => n ].
	 */
	public static function prepare( string $slug, string $text, callable $lookup, callable $existing ): array {
		$pol = WB_Records::TABLES[ $slug ] ?? null;
		if ( ! $pol ) return [ 'rows' => [], 'errors' => [ 'That is not a table that can be uploaded.' ], 'create' => 0, 'update' => 0 ];
		if ( '' === trim( $text ) ) return [ 'rows' => [], 'errors' => [ 'That file is empty.' ], 'create' => 0, 'update' => 0 ];
		$d    = WB_Payments::detect_delimiter( $text );
		$all  = WB_Payments::csv_rows( $text, $d );
		$hi   = null;
		foreach ( $all as $i => $r ) if ( '' !== implode( '', $r ) ) { $hi = $i; break; }
		if ( null === $hi ) return [ 'rows' => [], 'errors' => [ 'That file is empty.' ], 'create' => 0, 'update' => 0 ];
		$map    = self::header_map( $slug );
		$fields = WB_Records::fields( $slug );
		$cols   = [];
		$errors = [];
		foreach ( $all[ $hi ] as $j => $h ) {
			$n = self::norm( self::unguard( $h ) );
			if ( '' === $n ) { $cols[ $j ] = null; continue; }
			if ( ! isset( $map[ $n ] ) ) { $errors[] = 'Column "' . trim( $h ) . '" is not one this table takes. The columns are: ' . implode( ', ', self::headers( $slug ) ) . '.'; continue; }
			$cols[ $j ] = $map[ $n ];
		}
		if ( $errors ) return [ 'rows' => [], 'errors' => $errors, 'create' => 0, 'update' => 0 ];
		$present = array_values( array_filter( $cols ) );
		foreach ( $fields as $name => $f ) if ( $f['required'] && ! in_array( $name, $present, true ) ) $errors[] = 'The column "' . $f['label'] . '" is needed and is not in the file.';
		if ( $errors ) return [ 'rows' => [], 'errors' => $errors, 'create' => 0, 'update' => 0 ];

		$rows   = [];
		$seen   = [];
		$create = 0;
		$update = 0;
		$data   = array_slice( $all, $hi + 1, null, true );
		if ( count( $data ) > self::MAX_ROWS ) return [ 'rows' => [], 'errors' => [ 'That file has more than ' . number_format( self::MAX_ROWS ) . ' rows. Split it into smaller files.' ], 'create' => 0, 'update' => 0 ];
		foreach ( $data as $i => $r ) {
			$line = $i + 1;   // what the person sees in a spreadsheet (1-based, header is row 1 when it is first)
			if ( '' === implode( '', $r ) ) continue;
			if ( count( $r ) > count( $cols ) ) { $errors[] = "Row {$line}: more cells than columns — put quotes around any value that contains a comma."; continue; }
			$posted = [];
			$id     = 0;
			foreach ( $r as $j => $v ) {
				$name = $cols[ $j ] ?? null;
				if ( null === $name ) continue;
				$v = self::unguard( trim( $v ) );
				if ( '_ID' === $name ) { $id = $v; continue; }
				$posted[ $name ] = $v;
			}
			if ( '' !== (string) $id && ! ctype_digit( (string) $id ) ) { $errors[] = "Row {$line}: _ID \"{$id}\" must be a whole number, or blank for a new {$pol['one']}."; continue; }
			$id = (int) $id;
			$cur = $id ? $existing( $slug, '_ID', $id ) : null;
			if ( $id && ! $cur ) { $errors[] = "Row {$line}: there is no {$pol['one']} with _ID {$id}. Leave it blank to add a new one."; continue; }
			// references by ID or exact name
			foreach ( $fields as $name => $f ) {
				if ( ! isset( $posted[ $name ] ) || '' === $posted[ $name ] || ( ! is_array( $f['ref'] ) && 'users' !== $f['ref'] ) ) continue;
				$val = $posted[ $name ];
				if ( ctype_digit( $val ) ) continue;
				$rid = is_array( $f['ref'] ) ? $lookup( $f['ref'][0], $f['ref'][1], $val ) : $lookup( 'users', null, $val );
				if ( ! $rid ) { $errors[] = "Row {$line}: {$f['label']} \"{$val}\" not found — use its exact name" . ( 'users' === $f['ref'] ? ' (the login name or email)' : ' or its _ID' ) . '.'; continue 2; }
				$posted[ $name ] = (string) $rid;
			}
			[ $clean, $bad ] = WB_Records::clean( $slug, $posted, (bool) $cur );
			if ( $bad ) { foreach ( $bad as $b ) $errors[] = "Row {$line}: {$b}"; continue; }
			foreach ( $pol['key'] as $k ) {
				$kv = (string) ( $clean[ $k ] ?? '' );
				if ( '' === $kv ) continue;
				$word = strtolower( WB_Render::label( $k ) );
				if ( isset( $seen[ $k ][ strtolower( $kv ) ] ) ) { $errors[] = "Row {$line}: {$word} \"{$kv}\" is also on row {$seen[ $k ][ strtolower( $kv ) ]}."; continue 2; }
				$seen[ $k ][ strtolower( $kv ) ] = $line;
				$dup = $existing( $slug, $k, $kv );
				if ( $dup && (int) $dup['_ID'] !== $id ) { $errors[] = "Row {$line}: a {$pol['one']} with that {$word} is already on file (\"" . WB_Records::name_of( $slug, $dup ) . "\", _ID " . (int) $dup['_ID'] . '). Put its _ID in the row to update it.'; continue 2; }
			}
			$rows[] = [ $id, $clean ];
			$id ? $update++ : $create++;
		}
		if ( ! $rows && ! $errors ) $errors[] = 'The file has a header row and nothing under it.';
		if ( $errors ) return [ 'rows' => [], 'errors' => $errors, 'create' => 0, 'update' => 0 ];   // one bad row refuses the file
		return [ 'rows' => $rows, 'errors' => $errors, 'create' => $create, 'update' => $update ];
	}

	/** The words for a check or an import result. Pure. */
	public static function summary( array $prep, bool $imported, string $one ): string {
		$n = count( $prep['rows'] );
		$w = $n . ' ' . ( 1 === $n ? $one : $one . 's' ) . ' (' . $prep['create'] . ' new, ' . $prep['update'] . ' to update)';
		if ( $prep['errors'] ) {
			$list = array_slice( $prep['errors'], 0, self::SHOW_ERRS );
			if ( count( $prep['errors'] ) > self::SHOW_ERRS ) $list[] = '… and ' . ( count( $prep['errors'] ) - self::SHOW_ERRS ) . ' more.';
			return 'Nothing was imported. Fix these and try again: ' . implode( ' ', $list );
		}
		return $imported ? 'Imported ' . $w . '.' : 'File looks good: ' . $w . ' ready. Nothing has been imported yet — choose "Validate and import" to go ahead.';
	}

	/* ------------------------------------------------------------------ WordPress side */

	/** The fold under a table: template, export, and the upload form. */
	/** The plural of a table's one-word name, for the fold title (1.3.0: 'categorys' was showing). */
	public static function plural( string $one ): string {
		if ( preg_match( '/[^aeiou]y$/', $one ) ) return substr( $one, 0, -1 ) . 'ies';
		return $one . 's';
	}

	public static function fold( string $slug ): string {
		$pol = WB_Records::TABLES[ $slug ] ?? null;
		if ( ! $pol || ! current_user_can( $pol['cap'] ) ) return '';
		$cols = self::headers( $slug );
		$req  = array_keys( array_filter( WB_Records::fields( $slug ), fn( $f ) => $f['required'] ) );
		$body = '<p class="wb-fold-note">A CSV with these columns (names or the words on the form, any order): <code>' . esc_html( implode( ', ', $cols ) ) . '</code>. '
			. ( $req ? '<strong>' . esc_html( implode( ', ', $req ) ) . '</strong> must be filled. ' : '' )
			. '_ID blank adds a new ' . esc_html( $pol['one'] ) . '; filled updates that one. If any row is wrong the whole file is refused and nothing changes.</p>'
			. '<p class="wb-form-acts"><a class="wb-btn wb-btn-ghost wb-btn-sm" href="' . esc_url( self::file_url( 'template', $slug ) ) . '">Download a blank template</a> '
			. '<a class="wb-btn wb-btn-ghost wb-btn-sm" href="' . esc_url( self::file_url( 'export', $slug ) ) . '">Export what is here now</a></p>'
			. WB_Render::form_open( 'record_import', true ) . '<input type="hidden" name="record_cct" value="' . esc_attr( $slug ) . '">'
			. '<label class="wb-field"><span>File <span class="wb-req" aria-hidden="true">*</span></span><input type="file" name="file" accept=".csv,.txt,.tsv" required><small>CSV, up to 5 MB and ' . number_format( self::MAX_ROWS ) . ' rows. Comma, semicolon or tab.</small></label>'
			. '<div class="wb-form-acts"><button type="submit" class="wb-btn wb-btn-ghost" name="mode" value="check">Check file (no import)</button><button type="submit" class="wb-btn" name="mode" value="import">Validate and import</button></div></form>';
		return WB_Render::fold( 'Upload ' . esc_html( self::plural( (string) $pol['one'] ) ) . ' from a file', $body, [ 'id' => 'wb-upload-' . substr( $slug, 3 ), 'kind' => 'sibling' ] );
	}

	public static function file_url( string $what, string $slug ): string {
		return wp_nonce_url( add_query_arg( [ 'wb_file' => $what, 'cct' => $slug ], WB_Workspace::url( WB_Records::TABLES[ $slug ]['screen'] ) ), 'wb_file_' . $slug );
	}

	/** GET ?wb_file=template|export&cct=… → the CSV, cap-gated, nonce-checked, exports ledgered. */
	public static function maybe_file(): void {
		if ( empty( $_GET['wb_file'] ) || ! is_user_logged_in() ) return;
		$slug = sanitize_key( (string) ( $_GET['cct'] ?? '' ) );
		$pol  = WB_Records::TABLES[ $slug ] ?? null;
		if ( ! $pol || ! current_user_can( $pol['cap'] ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'wb_file_' . $slug ) ) return;
		$what = 'export' === $_GET['wb_file'] ? 'export' : 'template';
		$rows = [ self::headers( $slug ) ];
		if ( 'template' === $what ) {
			$rows[] = self::example_row( $slug );
		} else {
			$fields = WB_Records::fields( $slug );
			foreach ( WB_CCT::find( $slug, [], [ 'limit' => 100000, 'orderby' => $pol['order'], 'order' => 'ASC' ] ) as $r ) {
				$line = [ (int) $r['_ID'] ];
				foreach ( $fields as $name => $f ) $line[] = $f['enc'] ? '' : ( '_json' === substr( $name, -5 ) ? WB_Records::json_to_lines( (string) ( $r[ $name ] ?? '' ) ) : (string) ( $r[ $name ] ?? '' ) );
				$rows[] = $line;
			}
			wb_ledger_write( 'data_exported', $slug, 0, null, [ 'rows' => count( $rows ) - 1, 'by' => get_current_user_id() ] );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . substr( $slug, 3 ) . '-' . $what . ( 'export' === $what ? '-' . gmdate( 'Y-m-d' ) : '' ) . '.csv"' );
		echo self::to_csv( $rows );
		exit;
	}

	/** POST: check or import. All or nothing. */
	public static function handle_import() {
		$slug = sanitize_key( (string) ( $_POST['record_cct'] ?? '' ) );
		$pol  = WB_Records::TABLES[ $slug ] ?? null;
		if ( ! $pol ) return new WP_Error( 'wb_table', 'That is not a table that can be uploaded.' );
		if ( ! current_user_can( $pol['cap'] ) ) return new WP_Error( 'wb_forbidden', 'You cannot upload ' . $pol['one'] . 's.' );
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) return new WP_Error( 'wb_no_file', 'Choose the file first.' );
		if ( (int) $_FILES['file']['size'] > self::MAX_BYTES ) return new WP_Error( 'wb_big', 'That file is over 5 MB — split it into smaller files.' );
		if ( ! preg_match( '/\.(csv|txt|tsv)$/i', sanitize_file_name( (string) $_FILES['file']['name'] ) ) ) return new WP_Error( 'wb_type', 'Save the spreadsheet as CSV first, then choose that file.' );
		$text = (string) file_get_contents( $_FILES['file']['tmp_name'] );
		$lookup = function ( string $cct, ?string $col, string $value ) {
			if ( 'users' === $cct ) { $u = get_user_by( 'login', $value ) ?: get_user_by( 'email', $value ); return $u ? (int) $u->ID : null; }
			$r = WB_CCT::first( $cct, [ $col => $value ] );
			if ( ! $r && 'first_name' === $col && false !== strpos( $value, ' ' ) ) { [ $a, $b ] = explode( ' ', $value, 2 ); $r = WB_CCT::first( $cct, [ 'first_name' => $a, 'last_name' => $b ] ); }
			return $r ? (int) $r['_ID'] : null;
		};
		$existing = fn( string $s, string $col, $value ) => '_ID' === $col ? WB_CCT::get( $s, (int) $value ) : WB_CCT::first( $s, [ $col => $value ] );
		$prep = self::prepare( $slug, $text, $lookup, $existing );
		$mode = 'import' === ( $_POST['mode'] ?? '' ) ? 'import' : 'check';
		if ( $prep['errors'] ) return new WP_Error( 'wb_rows', self::summary( $prep, false, $pol['one'] ) );
		if ( 'check' === $mode ) return [ 'msg' => self::summary( $prep, false, $pol['one'] ) ];
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		WB_Ledger::begin_defer();
		foreach ( $prep['rows'] as $i => [ $id, $row ] ) {
			if ( 'wb_customers' === $slug && ! $id ) $row += [ 'journey_stage' => 'lead' ];
			$res = $id ? WB_CCT::update( $slug, $id, $row, substr( $slug, 3 ) . '_imported' ) : WB_CCT::insert( $slug, $row, rtrim( substr( $slug, 3 ), 's' ) . '_imported' );
			if ( is_wp_error( $res ) ) {
				$wpdb->query( 'ROLLBACK' );
				WB_Ledger::discard_deferred();
				return new WP_Error( 'wb_row_failed', 'Row ' . ( $i + 2 ) . ' could not be saved (' . $res->get_error_message() . ') — nothing was imported.' );
			}
		}
		$wpdb->query( 'COMMIT' );
		WB_Ledger::flush_deferred();
		wb_ledger_write( 'data_imported', $slug, 0, null, [ 'created' => $prep['create'], 'updated' => $prep['update'], 'by' => get_current_user_id() ] );
		return [ 'msg' => self::summary( $prep, true, $pol['one'] ) ];
	}
}
