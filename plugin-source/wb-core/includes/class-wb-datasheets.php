<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Datasheets — a product's datasheet as data (1.4.0, Zina: "datasheet data stored in tables,
 * converted to PDFs, making them easier to update in bulk … or upload a datasheet, or link it to
 * the online datasheet database").
 *
 * One wb_datasheets row per product says where its datasheet comes from:
 *   data    the row's own words (headline, description, applications, handling) plus the product's
 *           specification rows, rendered to PDF on demand with the company letterhead. Nothing is
 *           stored: the sheet is living data and renders in well under a second, so a stored copy
 *           would only go stale. The one exception is a 7-day link for a customer: that files a
 *           snapshot in wb_documents, so what the customer was sent on that day is on record.
 *   upload  the supplier's own PDF, filed in wb_documents as before (type datasheet, versioned).
 *   link    an address on the supplier's or manufacturer's own site.
 * A product with no row falls back to an uploaded sheet if one is on file, else has none.
 *
 * The rows are typed on the Documents screen (WB_Records), uploaded in bulk by CSV (WB_Import) and
 * exported the same way, so a whole range can be updated in one file. resolve() and html() are
 * pure, tested without WordPress; the PDF comes from the same engine as the numbered documents.
 */
class WB_Datasheets {

	const SOURCES = [ 'data' => 'From the data on this row', 'upload' => 'An uploaded file', 'link' => 'A link to an online datasheet' ];

	public static function init(): void {
		add_filter( 'wb_row_actions', [ __CLASS__, 'row_actions' ] );
		add_action( 'rest_api_init', function () {
			register_rest_route( 'wb/v1', '/datasheet', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'serve' ], 'permission_callback' => fn() => is_user_logged_in() ] );
		} );
	}

	/* ------------------------------------------------------------------ pure */

	/**
	 * Where this product's datasheet comes from, given its wb_datasheets row (or null) and its
	 * newest uploaded sheet (or null): [ kind, row, doc ] with kind data | upload | link | none.
	 * A 'data' row with nothing written on it and no specification is 'none': an empty sheet is
	 * never handed to a customer.
	 */
	public static function resolve( ?array $row, ?array $doc, string $spec_json = '' ): array {
		$src = (string) ( $row['source'] ?? '' );
		if ( $row && 'link' === $src && '' !== trim( (string) ( $row['external_url'] ?? '' ) ) ) return [ 'link', $row, null ];
		if ( $row && 'data' === $src && self::has_words( $row, $spec_json ) ) return [ 'data', $row, $doc ];
		if ( $doc ) return [ 'upload', $row, $doc ];
		return [ 'none', $row, null ];
	}

	public static function has_words( array $row, string $spec_json ): bool {
		foreach ( [ 'headline', 'description', 'applications', 'handling' ] as $k ) if ( '' !== trim( (string) ( $row[ $k ] ?? '' ) ) ) return true;
		$spec = json_decode( $spec_json, true );
		return is_array( $spec ) && count( array_filter( $spec, fn( $r ) => '' !== trim( (string) ( $r['value'] ?? '' ) ) ) ) > 0;
	}

	/** Specification rows for the sheet: the product's own values (label | value | unit), blanks dropped. */
	public static function spec_rows( string $spec_json ): array {
		$rows = json_decode( $spec_json, true );
		if ( ! is_array( $rows ) ) return [];
		return array_values( array_filter( array_map( fn( $r ) => [ 'label' => (string) ( $r['label'] ?? '' ), 'value' => (string) ( $r['value'] ?? '' ), 'unit' => (string) ( $r['unit'] ?? '' ) ], $rows ), fn( $r ) => '' !== $r['label'] && '' !== $r['value'] ) );
	}

	/**
	 * The whole sheet as HTML. Pure.
	 * $d: [ sku, name, category, unit, pack_size, shelf_life_days, headline, description, applications,
	 *       handling, revision, revised_at, specs: [ [ label, value, unit ], … ], made ]
	 * $brand: as WB_Docs::html() takes it.
	 */
	public static function html( array $d, array $brand ): string {
		$e    = fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
		$name = (string) ( $brand['legal_name'] ?: $brand['display_name'] );
		$co   = array_filter( [ '' !== (string) ( $brand['reg_number'] ?? '' ) ? 'Reg. ' . $brand['reg_number'] : '', (string) ( $brand['physical_address'] ?? '' ) ] );
		$para = function ( string $head, string $text ) use ( $e ): string {
			if ( '' === trim( $text ) ) return '';
			$ps = array_filter( array_map( 'trim', preg_split( '/\r?\n\s*\r?\n/', trim( $text ) ) ) );
			$h  = '<h2>' . $e( $head ) . '</h2>';
			foreach ( $ps as $p ) {
				$lines = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $p ) ) );
				$bul   = $lines && count( $lines ) === count( array_filter( $lines, fn( $l ) => preg_match( '/^[-•*]\s*/', $l ) ) );
				$h    .= $bul ? '<ul>' . implode( '', array_map( fn( $l ) => '<li>' . $e( preg_replace( '/^[-•*]\s*/', '', $l ) ) . '</li>', $lines ) ) . '</ul>' : '<p>' . nl2br( $e( $p ) ) . '</p>';
			}
			return $h;
		};
		$h = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e( 'Datasheet ' . $d['sku'] ) . '</title><style>' . WB_Docs::css( $brand['colors'] )
			. 'h2{font-size:10.5pt;margin:14px 0 4px;color:' . $brand['colors']['ink'] . ';text-transform:uppercase;letter-spacing:.06em}p{margin:0 0 6px;line-height:1.45}ul{margin:0 0 6px 16px;padding:0}li{margin:0 0 3px;line-height:1.4}'
			. '.lead{font-size:11pt;color:#47586D;margin:2px 0 8px}table.spec{width:100%;border-collapse:collapse;margin:4px 0 6px}table.spec td{padding:5px 6px;border-bottom:1px solid #F0E8DF;vertical-align:top}table.spec td.k{width:40%;color:#47586D}table.spec td.u{width:18%;color:#8A8F9C}</style></head><body>';
		$h .= '<table class="head"><tr><td>' . ( '' !== (string) ( $brand['logo'] ?? '' ) ? '<img class="logo" src="' . $e( $brand['logo'] ) . '" alt="">' : '<div class="brand">' . $e( $name ) . '</div>' )
			. '</td><td class="co" style="text-align:right">' . $e( $name ) . '<br>' . implode( '<br>', array_map( fn( $l ) => nl2br( $e( $l ) ), $co ) ) . '</td></tr></table>';
		$h .= '<h1>' . $e( $d['name'] ) . '</h1><div class="num">' . $e( $d['sku'] ) . ( '' !== (string) ( $d['category'] ?? '' ) ? ' · ' . $e( $d['category'] ) : '' ) . '</div>';
		if ( '' !== trim( (string) ( $d['headline'] ?? '' ) ) ) $h .= '<p class="lead">' . $e( $d['headline'] ) . '</p>';
		if ( 0 === strpos( (string) ( $d['image'] ?? '' ), 'data:image/' ) ) $h .= '<p><img src="' . $e( $d['image'] ) . '" alt="" style="max-width:220px;max-height:160px"></p>';   // 1.6.0: the product's picture
		$meta = array_filter( [
			[ 'Sold per', (string) ( $d['unit'] ?? '' ) . ( (float) ( $d['pack_size'] ?? 1 ) > 1 ? ' (pack of ' . $e( rtrim( rtrim( number_format( (float) $d['pack_size'], 2, '.', '' ), '0' ), '.' ) ) . ')' : '' ) ],
			[ 'Shelf life', (int) ( $d['shelf_life_days'] ?? 0 ) > 0 ? (int) $d['shelf_life_days'] . ' days' : '' ],
			[ 'Revision', trim( (string) ( $d['revision'] ?? '' ) . ( '' !== (string) ( $d['revised_at'] ?? '' ) ? ' · ' . substr( (string) $d['revised_at'], 0, 10 ) : '' ), ' ·' ) ],
		], fn( $m ) => '' !== $m[1] );
		if ( $meta ) {
			$h .= '<table class="meta"><tr>';
			foreach ( $meta as [ $k, $v ] ) $h .= '<td><div class="k">' . $e( $k ) . '</div><div>' . $e( $v ) . '</div></td>';
			$h .= '</tr></table>';
		}
		$h .= $para( 'Description', (string) ( $d['description'] ?? '' ) );
		if ( ! empty( $d['specs'] ) ) {
			$h .= '<h2>Specification</h2><table class="spec">';
			foreach ( $d['specs'] as $r ) $h .= '<tr><td class="k">' . $e( $r['label'] ) . '</td><td>' . $e( $r['value'] ) . '</td><td class="u">' . $e( $r['unit'] ) . '</td></tr>';
			$h .= '</table>';
		}
		$h .= $para( 'Applications', (string) ( $d['applications'] ?? '' ) );
		$h .= $para( 'Storage, handling and safety', (string) ( $d['handling'] ?? '' ) );
		if ( '' !== (string) ( $brand['doc_footer'] ?? '' ) ) $h .= '<p class="note">' . nl2br( $e( $brand['doc_footer'] ) ) . '</p>';
		$h .= '<p class="note">The figures above are typical values from our records and are given in good faith; they are not a specification for a particular batch. Please check suitability for your use.</p>';
		$h .= '<div class="foot">' . $e( $name ) . ' · Datasheet ' . $e( $d['sku'] ) . ' · made ' . $e( $d['made'] ?? '' ) . '</div>';
		return $h . '</body></html>';
	}

	/* ------------------------------------------------------------------ WordPress side */

	/** The product's row (null when none). */
	public static function row( int $product_id ): ?array {
		return WB_CCT::table( 'wb_datasheets' ) ? ( WB_CCT::first( 'wb_datasheets', [ 'product_id' => $product_id ] ) ?: null ) : null;
	}

	/** resolve() for a live product. */
	public static function current( int $product_id, ?array $product = null ): array {
		$product = $product ?? WB_CCT::get( 'wb_products', $product_id );
		if ( ! $product ) return [ 'none', null, null ];
		return self::resolve( self::row( $product_id ), WB_Documents::current_datasheet( $product_id ), (string) ( $product['spec_json'] ?? '' ) );
	}

	/** Every product's resolution in two queries, for a long table: [ product_id => [ kind, row, doc ] ]. */
	public static function current_all( array $products ): array {
		$rows = $docs = [];
		if ( WB_CCT::table( 'wb_datasheets' ) ) foreach ( WB_CCT::find( 'wb_datasheets', [], [ 'limit' => 5000 ] ) as $r ) $rows[ (int) $r['product_id'] ] = $r;
		foreach ( WB_CCT::find( 'wb_documents', [ 'type' => 'datasheet' ], [ 'limit' => 5000, 'orderby' => 'version', 'order' => 'ASC' ] ) as $d ) $docs[ (int) $d['product_id'] ] = $d;
		$out = [];
		foreach ( $products as $p ) $out[ (int) $p['_ID'] ] = self::resolve( $rows[ (int) $p['_ID'] ] ?? null, $docs[ (int) $p['_ID'] ] ?? null, (string) ( $p['spec_json'] ?? '' ) );
		return $out;
	}

	/** The arrays html() wants, from the live rows. */
	public static function data( int $product_id ): ?array {
		$p = WB_CCT::get( 'wb_products', $product_id );
		if ( ! $p ) return null;
		[ $kind, $row ] = self::current( $product_id, $p );
		if ( 'data' !== $kind ) return null;
		$cat   = (int) ( $p['category_id'] ?? 0 ) > 0 ? WB_CCT::get( 'wb_product_categories', (int) $p['category_id'] ) : null;
		$brand = WB_Setup::brand();
		$brand['colors'] = WB_Setup::safe_colors( (array) $brand['colors'] );
		$brand['logo']   = WB_Setup::logo_data_uri();
		return [ 'd' => [
			'sku' => (string) $p['sku'], 'name' => (string) $p['name'], 'category' => (string) ( $cat['name'] ?? '' ), 'unit' => (string) ( $p['unit'] ?? '' ), 'pack_size' => (float) ( $p['pack_size'] ?? 1 ),
			'shelf_life_days' => (int) ( $p['shelf_life_days'] ?? 0 ), 'headline' => (string) ( $row['headline'] ?? '' ), 'description' => (string) ( $row['description'] ?? '' ), 'applications' => (string) ( $row['applications'] ?? '' ),
			'handling' => (string) ( $row['handling'] ?? '' ), 'revision' => (string) ( $row['revision'] ?? '' ), 'revised_at' => (string) ( $row['revised_at'] ?? '' ), 'specs' => self::spec_rows( (string) ( $p['spec_json'] ?? '' ) ), 'made' => wb_today(),
			'image' => class_exists( 'WB_Product_Images' ) ? WB_Product_Images::data_uri( $product_id ) : '',
		], 'brand' => $brand ];
	}

	/** The PDF bytes, made now. '' when there is nothing to render. */
	public static function pdf( int $product_id ): string {
		$data = self::data( $product_id );
		return $data ? WB_Pdf::render( self::html( $data['d'], $data['brand'] ) ) : '';
	}

	/** The address that renders the sheet for a signed-in person (staff, or a portal customer who buys the product). */
	public static function url( int $product_id ): string {
		return add_query_arg( [ 'product' => $product_id, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'wb/v1/datasheet' ) );
	}

	/** May this login read this product's sheet? Staff who see documents; a portal contact for a product their company buys or is quoted. */
	public static function user_can_read( int $product_id, int $user_id ): bool {
		if ( user_can( $user_id, 'wb_view_documents' ) ) return true;
		if ( ! user_can( $user_id, 'wb_portal' ) ) return false;
		$c = WB_Documents::portal_contact( $user_id );
		return $c && (int) $c['customer_id'] > 0 && in_array( $product_id, WB_Portal::product_ids( (int) $c['customer_id'] ), true );
	}

	/** GET wb/v1/datasheet?product=: renders and streams the sheet inline. */
	public static function serve( WP_REST_Request $req ) {
		$pid = absint( $req->get_param( 'product' ) );
		if ( ! self::user_can_read( $pid, get_current_user_id() ) ) WB_Rest::human_page( 'No access', 'You cannot open that datasheet.', 403 );
		if ( ! WB_Pdf::available() ) WB_Rest::human_page( 'No PDF engine', 'The PDF engine is missing from this installation.', 500 );
		$data = self::data( $pid );
		if ( ! $data ) WB_Rest::human_page( 'No datasheet', 'This product has no datasheet data yet.', 404 );
		$bytes = WB_Pdf::render( self::html( $data['d'], $data['brand'] ) );
		if ( '' === $bytes ) WB_Rest::human_page( 'Not ready', 'The datasheet could not be made. Try again in a moment.', 500 );
		wb_ledger_write( 'datasheet_rendered', 'wb_products', $pid, null, [ 'by' => get_current_user_id() ] );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'Datasheet ' . $data['d']['sku'] ) . '.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $bytes;
		exit;
	}

	/**
	 * A snapshot for the record: the sheet as it is today, filed in wb_documents as a datasheet
	 * version so a 7-day link can point at it and the register shows what the customer was sent.
	 * @return int|WP_Error the document id
	 */
	public static function snapshot( int $product_id ) {
		$data = self::data( $product_id );
		if ( ! $data ) return new WP_Error( 'wb_no_datasheet', 'This product has no datasheet data to send.' );
		$bytes = WB_Pdf::render( self::html( $data['d'], $data['brand'] ) );
		if ( '' === $bytes ) return new WP_Error( 'wb_pdf', 'The datasheet could not be made.' );
		$title = 'Datasheet ' . $data['d']['sku'] . ( '' !== $data['d']['revision'] ? ' ' . $data['d']['revision'] : '' );
		$tmp   = WB_Pdf::work_dir() . '/snapshot-' . $product_id . '-' . wp_generate_password( 8, false ) . '.pdf';
		if ( false === @file_put_contents( $tmp, $bytes ) ) return new WP_Error( 'wb_store_failed', 'The datasheet could not be stored.' );
		$prev  = WB_Documents::current_datasheet( $product_id );   // an earlier snapshot is superseded; a supplier's uploaded sheet is left alone
		$meta  = [ 'type' => 'datasheet', 'title' => $title, 'product_id' => $product_id, 'is_customer_visible' => 1, 'mime' => 'application/pdf', 'original_name' => sanitize_file_name( $title ) . '.pdf' ];
		if ( $prev && 0 === strpos( (string) $prev['title'], 'Datasheet ' . $data['d']['sku'] ) ) $meta['supersedes_doc_id'] = (int) $prev['_ID'];
		$id = WB_Documents::register( $tmp, $meta, true );
		if ( is_file( $tmp ) ) @unlink( $tmp );
		return $id;
	}

	/** The words and link for a product's Datasheet cell: PDF (data), Open (upload), Link, or none. */
	public static function cell( array $res ): string {
		[ $kind, $row, $doc ] = $res;
		switch ( $kind ) {
			case 'data':   return '<a href="' . esc_url( self::url( (int) $row['product_id'] ) ) . '" target="_blank" rel="noopener">PDF' . ( '' !== (string) ( $row['revision'] ?? '' ) ? ' · ' . esc_html( (string) $row['revision'] ) : '' ) . '</a>';
			case 'upload': return '<a href="' . esc_url( WB_Documents::open_url( (int) $doc['_ID'] ) ) . '" target="_blank" rel="noopener">Open · v' . (int) $doc['version'] . '</a>';
			case 'link':   return '<a href="' . esc_url( (string) $row['external_url'] ) . '" target="_blank" rel="noopener">Online ↗</a>';
		}
		return '<span class="wb-muted">none</span>';
	}

	/** ⋯ menu: "Datasheet PDF" on a product with data; "Open datasheet" on an uploaded one; "Online datasheet" on a link. */
	public static function row_actions( array $a ): array {
		$a['datasheet_pdf'] = [ 'cct' => 'wb_products', 'label' => 'Datasheet PDF', 'icon' => 'print', 'allowed' => fn() => current_user_can( 'wb_view_documents' ),
			'visible' => fn( $r ) => 'data' === self::current( (int) $r['_ID'], $r )[0], 'href' => fn( $r ) => self::url( (int) $r['_ID'] ) ];
		$a['datasheet_row_pdf'] = [ 'cct' => 'wb_datasheets', 'label' => 'Datasheet PDF', 'icon' => 'print', 'allowed' => fn() => current_user_can( 'wb_view_documents' ),
			'visible' => fn( $r ) => 'data' === (string) ( $r['source'] ?? '' ), 'href' => fn( $r ) => self::url( (int) $r['product_id'] ) ];
		return $a;
	}
}
