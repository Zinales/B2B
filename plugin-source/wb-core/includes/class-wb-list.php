<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_List — one list control for every list (1.5.0, review of 9 October: "no search, no filter,
 * no sort, no paging on any list").
 *
 * A search box, the status chips as filters, sortable column heads and paging in fifties, all
 * by query string and plain links (no script), so a filtered, sorted page is a shareable address
 * that works on a phone. Several lists on one screen each use their own prefix (ns), so quotes
 * and purchase orders on one page keep separate searches.
 *
 * Query string, per list:  {ns}q  search   {ns}st  status   {ns}by  column   {ns}dir  asc|desc   {ns}pg  page
 * The reading, the argument building and the paging arithmetic are pure (read, args, span).
 */
class WB_List {

	const PER = 50;

	/** The list's state from the query string. Pure given $get. */
	public static function read( array $get, string $ns = '' ): array {
		$v = fn( string $k ) => isset( $get[ $ns . $k ] ) ? trim( (string) $get[ $ns . $k ] ) : '';
		return [
			'q'   => mb_substr( $v( 'q' ), 0, 80 ),
			'st'  => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v( 'st' ) ) ),
			'by'  => preg_replace( '/[^a-z0-9_]/', '', strtolower( $v( 'by' ) ) ),
			'dir' => 'asc' === strtolower( $v( 'dir' ) ) ? 'asc' : ( 'desc' === strtolower( $v( 'dir' ) ) ? 'desc' : '' ),
			'pg'  => max( 1, (int) $v( 'pg' ) ),
			'arch' => '1' === $v( 'arch' ),
		];
	}

	/** The query arguments for a link: the current ones, with $set applied and empties dropped. Pure. */
	public static function args( array $get, string $ns, array $set ): array {
		$out = [];
		foreach ( $get as $k => $v ) if ( is_scalar( $v ) && '' !== (string) $v && ! in_array( $k, [ 'wbmsg', 'wbra' ], true ) ) $out[ (string) $k ] = (string) $v;
		foreach ( $set as $k => $v ) {
			if ( '' === (string) $v || null === $v ) unset( $out[ $ns . $k ] ); else $out[ $ns . $k ] = (string) $v;
		}
		return $out;
	}

	/** The rows shown on a page: [ from, to, pages ] (1-based; from 0 when nothing). Pure. */
	public static function span( int $total, int $page, int $per = self::PER ): array {
		$pages = max( 1, (int) ceil( $total / max( 1, $per ) ) );
		$page  = min( max( 1, $page ), $pages );
		$from  = $total ? ( $page - 1 ) * $per + 1 : 0;
		return [ $from, min( $total, $page * $per ), $pages ];
	}

	/**
	 * The list. $slug: the table. $columns: as render_table. $opts, besides render_table's own:
	 *   where     the base condition (array for WB_CCT::find)
	 *   search    columns the search box looks in (LIKE); default: the identity column and 'name'
	 *   search_in [ column => [ ref table, ref column ] ]: a search word is also looked up there
	 *             (a customer's name on a quotes list) and matched by id
	 *   status    the status column for the chips ('' for none); default 'status' when the table has it
	 *   statuses  [ value => words ] for the chips; default from the schema's options
	 *   orderby / order   the default sort; sortable: columns a head may sort by (default: every real column shown)
	 *   per       rows per page (default 50);  ns: the prefix when several lists share a screen
	 *   base      the address the links build on (default: this screen)
	 *   get       the query string (default $_GET), for tests
	 */
	public static function render( string $slug, array $columns, array $opts = [] ): string {
		$ns    = (string) ( $opts['ns'] ?? '' );
		$get   = (array) ( $opts['get'] ?? wp_unslash( $_GET ) );
		$st    = self::read( $get, $ns );
		$per   = max( 5, (int) ( $opts['per'] ?? self::PER ) );
		$cols  = class_exists( 'WB_CCT' ) ? WB_CCT::columns( $slug ) : [];
		$scol  = array_key_exists( 'status', $opts ) ? (string) $opts['status'] : ( in_array( 'status', $cols, true ) ? 'status' : '' );
		$base  = (string) ( $opts['base'] ?? WB_Workspace::url( WB_Workspace::requested() ?: 'home' ) );
		$link  = fn( array $set ) => add_query_arg( self::args( $get, $ns, $set ), $base );

		// the condition (1.6.0: or the archived rows, when asked and the list allows it)
		$where = (array) ( $opts['where'] ?? [] );
		$arch  = ! empty( $opts['archive'] ) && in_array( 'record_status', $cols, true );
		$show_arch = $arch && $st['arch'];
		if ( $show_arch ) $where['record_status'] = 'archived';
		if ( '' !== $st['st'] && '' !== $scol ) $where[ $scol ] = $st['st'];
		if ( '' !== $st['q'] ) {
			$search = (array) ( $opts['search'] ?? array_values( array_filter( [ is_array( $columns[0] ?? null ) ? (string) ( $columns[0]['key'] ?? '' ) : (string) ( $columns[0] ?? '' ), 'name' ], fn( $c ) => in_array( $c, $cols, true ) ) ) );
			$ids    = [];
			foreach ( (array) ( $opts['search_in'] ?? [] ) as $col => [ $ref, $refcol ] ) {
				$found = WB_CCT::find( $ref, [ '_search' => [ 'cols' => [ $refcol ], 'q' => $st['q'] ] ], [ 'limit' => 200 ] );
				if ( $found ) $ids[ $col ] = array_map( fn( $r ) => (int) $r['_ID'], $found );
			}
			$where['_search'] = [ 'cols' => $search, 'q' => $st['q'], 'ids' => $ids ];
		}

		// the order
		$sortable = (array) ( $opts['sortable'] ?? array_values( array_filter( array_map( fn( $c ) => is_array( $c ) ? (string) ( $c['key'] ?? '' ) : (string) $c, $columns ), fn( $k ) => in_array( $k, $cols, true ) && '_id' !== substr( $k, -3 ) ) ) );   // a customer number sorts nothing useful
		$by  = '' !== $st['by'] && in_array( $st['by'], $sortable, true ) ? $st['by'] : (string) ( $opts['orderby'] ?? '_ID' );
		$dir = '' !== $st['dir'] ? $st['dir'] : strtolower( (string) ( $opts['order'] ?? ( $by === (string) ( $opts['orderby'] ?? '_ID' ) ? 'desc' : 'asc' ) ) );

		// the page
		$total = WB_CCT::count( $slug, $where, ! $show_arch );
		[ $from, $to, $pages ] = self::span( $total, $st['pg'], $per );
		$page  = min( $st['pg'], $pages );
		$rows  = $total ? WB_CCT::find( $slug, $where, [ 'orderby' => $by, 'order' => $dir, 'limit' => $per, 'offset' => ( $page - 1 ) * $per, 'active_only' => ! $show_arch ] ) : [];
		if ( $rows && is_callable( $opts['prefetch'] ?? null ) ) call_user_func( $opts['prefetch'], $rows );   // one lookup for the page, not one per row
		$archived_n = $arch && ! $show_arch ? WB_CCT::count( $slug, [ 'record_status' => 'archived' ], false ) : 0;
		$arch_link  = $show_arch ? '<p class="wb-list-arch">Showing archived ' . esc_html( (string) ( $opts['what'] ?? 'rows' ) ) . '. <a href="' . esc_url( $link( [ 'arch' => '', 'pg' => '' ] ) ) . '">Back to the current list</a></p>'
			: ( $archived_n > 0 ? '<p class="wb-list-arch"><a href="' . esc_url( $link( [ 'arch' => '1', 'pg' => '', 'st' => '' ] ) ) . '">Show archived (' . number_format( $archived_n ) . ')</a></p>' : '' );

		// the bar: search + chips
		$statuses = (array) ( $opts['statuses'] ?? ( '' !== $scol ? self::status_words( $slug, $scol ) : [] ) );
		$h = '<form method="get" action="' . esc_url( $base ) . '" class="wb-listbar" role="search">';
		foreach ( self::args( $get, $ns, [ 'q' => '', 'pg' => '' ] ) as $k => $v ) $h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		$h .= '<label class="wb-listbar-q"><span class="wb-sr">Search ' . esc_html( (string) ( $opts['what'] ?? 'this list' ) ) . '</span><input type="search" name="' . esc_attr( $ns . 'q' ) . '" value="' . esc_attr( $st['q'] ) . '" placeholder="' . esc_attr( (string) ( $opts['placeholder'] ?? 'Search' ) ) . '"></label>'
			. '<button type="submit" class="wb-btn wb-btn-sm wb-btn-ghost">Search</button>'
			. ( '' !== $st['q'] ? '<a class="wb-listbar-clear" href="' . esc_url( $link( [ 'q' => '', 'pg' => '' ] ) ) . '">Clear</a>' : '' ) . '</form>';
		if ( $statuses && ! $show_arch ) {
			$h .= '<nav class="wb-chips" aria-label="Filter by status"><a class="wb-chip-filter' . ( '' === $st['st'] ? ' is-on' : '' ) . '" href="' . esc_url( $link( [ 'st' => '', 'pg' => '' ] ) ) . '"' . ( '' === $st['st'] ? ' aria-current="true"' : '' ) . '>All</a>';
			foreach ( $statuses as $val => $words ) {
				$on = $st['st'] === (string) $val;
				$h .= '<a class="wb-chip-filter' . ( $on ? ' is-on' : '' ) . '" href="' . esc_url( $link( [ 'st' => $on ? '' : (string) $val, 'pg' => '' ] ) ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '>' . esc_html( (string) $words ) . '</a>';
			}
			$h .= '</nav>';
		}

		// the table (or the right empty words)
		if ( ! $rows ) {
			$what = (string) ( $opts['what'] ?? 'this list' );
			if ( '' !== $st['q'] || '' !== $st['st'] ) {
				$amid = '' !== $st['st'] ? ( $statuses[ $st['st'] ] ?? $st['st'] ) . ' ' . $what : $what;
				return $h . WB_Render::state( 'empty', '' !== $st['q'] ? "Nothing matches '" . $st['q'] . "' among " . $amid . '.' : 'Nothing is ' . $amid . ' at the moment.', 'Clear the search or choose All.' )
					. '<p class="wb-list-more"><a href="' . esc_url( $link( [ 'q' => '', 'st' => '', 'pg' => '' ] ) ) . '">Show everything</a></p>';
			}
			return $h . ( isset( $opts['empty'] ) ? WB_Render::state( 'empty', (string) $opts['empty'], (string) ( $opts['empty_note'] ?? '' ) ) : '' ) . $arch_link;
		}
		$topts = array_diff_key( $opts, array_flip( [ 'where', 'search', 'search_in', 'status', 'statuses', 'orderby', 'order', 'sortable', 'per', 'ns', 'base', 'get', 'what', 'placeholder', 'archive', 'prefetch' ] ) );
		if ( $show_arch && ! empty( $topts['cct'] ) ) $topts['actions'] = [ 'restore_' . $topts['cct'] ];   // an archived row can only be restored
		$topts['sort'] = [ 'by' => $by, 'dir' => $dir, 'keys' => $sortable, 'href' => fn( string $k ) => $link( [ 'by' => $k, 'dir' => $k === $by && 'asc' === $dir ? 'desc' : 'asc', 'pg' => '' ] ) ];
		$h .= WB_Render::render_table( $rows, $columns, $topts );

		// the pager
		if ( $total > $per ) {
			$h .= '<nav class="wb-pager" aria-label="Pages"><span>Showing ' . number_format( $from ) . ' to ' . number_format( $to ) . ' of ' . number_format( $total ) . '</span>'
				. ( $page > 1 ? '<a class="wb-btn wb-btn-sm wb-btn-ghost" href="' . esc_url( $link( [ 'pg' => $page - 1 > 1 ? $page - 1 : '' ] ) ) . '">Previous</a>' : '' )
				. ( $page < $pages ? '<a class="wb-btn wb-btn-sm wb-btn-ghost" href="' . esc_url( $link( [ 'pg' => $page + 1 ] ) ) . '">Next</a>' : '' ) . '</nav>';
		} elseif ( $total > 0 && ( '' !== $st['q'] || '' !== $st['st'] ) ) {
			$h .= '<p class="wb-list-more">' . number_format( $total ) . ' found. <a href="' . esc_url( $link( [ 'q' => '', 'st' => '', 'pg' => '' ] ) ) . '">Show everything</a></p>';
		}
		return $h . $arch_link;
	}

	/** The chip words for a status column, from the schema's options. */
	public static function status_words( string $slug, string $col ): array {
		$def = class_exists( 'WB_Records' ) ? ( WB_Records::schema( $slug )[ $col ] ?? [] ) : [];
		$out = [];
		foreach ( (array) ( $def['options'] ?? [] ) as $o ) if ( 'void' !== $o ) $out[ (string) $o ] = WB_Render::words( (string) $o );
		return $out;
	}
}
