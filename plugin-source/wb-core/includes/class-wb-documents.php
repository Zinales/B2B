<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Documents — the document register (wb_documents).
 *
 * A document row holds a storage_key (never a URL). A new version of a datasheet is a NEW row with
 * version + 1 and supersedes_doc_id → the old one; the old row stays (archived), so what a customer
 * was sent on a given day is always recoverable. "Datasheet on demand" = current_datasheet(): the
 * latest active version for that product, handed out through a tokened download link.
 */
class WB_Documents {

	const TYPES = [ 'datasheet', 'coa', 'msds', 'certificate', 'quote_pdf', 'invoice_pdf', 'credit_pdf', 'dn_pdf', 'pod', 'signed_quote', 'contract', 'staff_doc' ];

	/** Types a customer may ever see (when also is_customer_visible). */
	const CUSTOMER_TYPES = [ 'datasheet', 'coa', 'msds', 'certificate', 'quote_pdf', 'invoice_pdf', 'credit_pdf', 'dn_pdf', 'pod', 'signed_quote' ];

	/**
	 * File a document. $file = a path on disk (an upload's tmp_name, or a generated file).
	 * $meta: type, title, product_id / customer_id / staff_id / order_id, expires_at,
	 * is_customer_visible, supersedes_doc_id (to file a new version), mime, original_name.
	 *
	 * @return int|WP_Error the document _ID
	 */
	public static function register( string $file, array $meta, bool $system = false ) {
		if ( ! $system && ! current_user_can( 'wb_manage_documents' ) ) return new WP_Error( 'wb_forbidden', 'You cannot file documents.' );
		$type = (string) ( $meta['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) return new WP_Error( 'wb_doc_type', 'Choose what kind of document this is.' );
		if ( 'staff_doc' === $type && ! $system && ! current_user_can( 'wb_manage_staff' ) ) return new WP_Error( 'wb_forbidden', 'Staff documents need the Staff dashboard.' );
		$cols = WB_CCT::require_columns( 'wb_documents', [ 'type', 'storage_key', 'version' ] );
		if ( is_wp_error( $cols ) ) return $cols;

		$version = 1;
		$old     = null;
		if ( ! empty( $meta['supersedes_doc_id'] ) ) {
			$old = WB_CCT::get( 'wb_documents', (int) $meta['supersedes_doc_id'] );
			if ( ! $old || $old['type'] !== $type ) return new WP_Error( 'wb_doc_supersede', 'The document this replaces was not found, or is a different kind.' );
			$version = (int) $old['version'] + 1;
		}
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		$name = sanitize_file_name( (string) ( $meta['original_name'] ?? basename( $file ) ) );
		$key  = WB_Storage::put_file( $file, $type . '/' . gmdate( 'Y/m' ) . '/' . $name );
		if ( '' === $key ) return new WP_Error( 'wb_store_failed', 'The file could not be stored.' );

		$id = WB_CCT::insert( 'wb_documents', [
			'type'                => $type,
			'title'               => sanitize_text_field( (string) ( $meta['title'] ?? $name ) ),
			'product_id'          => (int) ( $meta['product_id'] ?? ( $old['product_id'] ?? 0 ) ),
			'customer_id'         => (int) ( $meta['customer_id'] ?? ( $old['customer_id'] ?? 0 ) ),
			'staff_id'            => (int) ( $meta['staff_id'] ?? ( $old['staff_id'] ?? 0 ) ),
			'order_id'            => (int) ( $meta['order_id'] ?? ( $old['order_id'] ?? 0 ) ),
			'version'             => $version,
			'supersedes_doc_id'   => $old ? (int) $old['_ID'] : 0,
			'storage_key'         => $key,
			'mime'                => sanitize_mime_type( (string) ( $meta['mime'] ?? ( wp_check_filetype( $name )['type'] ?: 'application/octet-stream' ) ) ),
			'size'                => $size,
			'issued_at'           => wb_now(),
			'expires_at'          => sanitize_text_field( (string) ( $meta['expires_at'] ?? '' ) ),
			'is_customer_visible' => wb_truthy( $meta['is_customer_visible'] ?? '' ) ? 1 : 0,   // S4: "false" is off
		], 'document_filed' );
		if ( is_wp_error( $id ) ) return $id;

		if ( $old ) WB_CCT::set_status( 'wb_documents', (int) $old['_ID'], 'archived', 'superseded by #' . $id );
		// A product's datasheet pointer follows the newest version.
		if ( 'datasheet' === $type && ! empty( $meta['product_id'] ?? ( $old['product_id'] ?? 0 ) ) ) {
			WB_CCT::update( 'wb_products', (int) ( $meta['product_id'] ?? $old['product_id'] ), [ 'datasheet_doc_id' => $id ], 'product_datasheet_set' );
		}
		return $id;
	}

	/** The current (latest active) datasheet for a product, or null. */
	public static function current_datasheet( int $product_id ): ?array {
		if ( $product_id <= 0 ) return null;
		return WB_CCT::first( 'wb_documents', [ 'type' => 'datasheet', 'product_id' => $product_id ], [ 'orderby' => 'version', 'order' => 'DESC' ] );
	}

	/**
	 * The wb_contacts row for a customer-portal login, or null. Fails closed exactly like
	 * WB_Portal::contact(): one active contact, a customer, an account that is not closed (P7).
	 */
	public static function portal_contact( int $user_id ): ?array {
		if ( $user_id <= 0 || ! class_exists( 'WB_Portal' ) ) return null;
		return WB_Portal::contact( $user_id );
	}

	/** WB_Portal::product_ids() per customer, once per request (a document list asks many times). */
	private static $product_ids = [];

	/**
	 * May this user have this document? Staff: wb_view_documents (staff files need wb_view_staff).
	 * Customer portal (fails closed): only customer-visible documents of a customer type, for a
	 * login linked to an open customer — their own company's documents, or a general product
	 * datasheet / MSDS / certificate for a product they have bought or been quoted (P7).
	 */
	/** 0.2.2 — company-wide documents: customer-visible, tied to no product and no customer. */
	const COMPANY_TYPES = [ 'certificate', 'msds', 'datasheet' ];

	/**
	 * Pure: may every portal customer see this company-wide document? Only when the organisation
	 * shows company documents (Setup → Customer portal) AND the document itself is marked
	 * customer-visible AND it is one of COMPANY_TYPES AND it belongs to no product and no customer.
	 */
	public static function company_doc_shown( array $doc, string $setting ): bool {
		if ( 'all_customers' !== $setting ) return false;
		if ( ! wb_truthy( $doc['is_customer_visible'] ?? '' ) ) return false;
		if ( ! in_array( (string) ( $doc['type'] ?? '' ), self::COMPANY_TYPES, true ) ) return false;
		return (int) ( $doc['product_id'] ?? 0 ) <= 0 && (int) ( $doc['customer_id'] ?? 0 ) <= 0;
	}

	/** The organisation's setting: 'hidden' (the default) or 'all_customers'. */
	public static function company_docs_setting(): string {
		return class_exists( 'WB_Setup' ) ? (string) ( WB_Setup::brand()['portal_company_docs'] ?? 'hidden' ) : 'hidden';
	}

	/** Current company-wide documents a portal customer may see (empty while the setting is hidden). */
	public static function company_docs(): array {
		if ( 'all_customers' !== self::company_docs_setting() ) return [];
		$out = [];
		foreach ( self::COMPANY_TYPES as $t ) {
			foreach ( WB_CCT::find( 'wb_documents', [ 'type' => $t ], [ 'limit' => 200 ] ) as $d ) {
				if ( self::company_doc_shown( $d, 'all_customers' ) ) $out[] = $d;
			}
		}
		return $out;
	}

	public static function user_can_access( array $doc, int $user_id ): bool {
		if ( $user_id <= 0 ) return false;
		if ( user_can( $user_id, 'wb_view_documents' ) ) {
			return 'staff_doc' !== $doc['type'] || user_can( $user_id, 'wb_view_staff' );
		}
		if ( ! user_can( $user_id, 'wb_portal' ) ) return false;
		if ( ! wb_truthy( $doc['is_customer_visible'] ?? '' ) || ! in_array( $doc['type'], self::CUSTOMER_TYPES, true ) ) return false;   // S4: "false" is off
		$c = self::portal_contact( $user_id );
		if ( ! $c || (int) $c['customer_id'] <= 0 ) return false;
		$cid = (int) $c['customer_id'];
		if ( (int) ( $doc['customer_id'] ?? 0 ) > 0 ) return $cid === (int) $doc['customer_id'];
		if ( ! in_array( $doc['type'], [ 'datasheet', 'msds', 'certificate' ], true ) ) return false;
		$pid = (int) ( $doc['product_id'] ?? 0 );
		if ( $pid <= 0 ) return self::company_doc_shown( $doc, self::company_docs_setting() );   // 0.2.2: the organisation's choice
		if ( ! isset( self::$product_ids[ $cid ] ) ) self::$product_ids[ $cid ] = array_flip( WB_Portal::product_ids( $cid ) );
		return isset( self::$product_ids[ $cid ][ $pid ] );
	}

	/**
	 * A tokened download URL for a document the current user may access. $ttl as WB_Storage::issue_token.
	 *
	 * @return string|WP_Error
	 */
	public static function download_url( int $doc_id, int $ttl = 120, bool $single_use = true ) {
		$doc = WB_CCT::get( 'wb_documents', $doc_id );
		if ( ! $doc ) return new WP_Error( 'wb_not_found', 'Document not found.' );
		if ( ! self::user_can_access( $doc, get_current_user_id() ) ) return new WP_Error( 'wb_forbidden', 'You do not have access to that document.' );
		$token = WB_Storage::issue_token( $doc_id, $ttl, $single_use );
		wb_ledger_write( 'download_link_issued', 'wb_documents', $doc_id, null, [ 'ttl' => $ttl, 'single_use' => $single_use ] );
		return add_query_arg( 'token', $token, rest_url( 'wb/v1/download' ) );
	}

	/**
	 * The link a menu shows: no token is made until the person clicks (then /download checks
	 * access, ledgers, and hands out a 2-minute single-use token). Showing a list costs nothing.
	 */
	public static function open_url( int $doc_id ): string {
		return add_query_arg( [ 'doc' => $doc_id, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ], rest_url( 'wb/v1/download' ) );
	}

	/**
	 * A 7-day link to a product's current datasheet, for a person to send to a customer.
	 * Records a datasheet_sent touchpoint when a customer is named. Sends nothing by itself.
	 *
	 * @return string|WP_Error
	 */
	public static function datasheet_link( int $product_id, int $customer_id = 0, int $contact_id = 0 ) {
		if ( ! current_user_can( 'wb_view_documents' ) ) return new WP_Error( 'wb_forbidden', 'You cannot share documents.' );
		$doc = self::current_datasheet( $product_id );
		if ( ! $doc ) return new WP_Error( 'wb_no_datasheet', 'This product has no datasheet on file yet.' );
		$url = self::download_url( (int) $doc['_ID'], 7 * DAY_IN_SECONDS, false );
		if ( ! is_wp_error( $url ) && $customer_id ) {
			WB_Orders::touchpoint( $customer_id, 'datasheet_sent', sprintf( 'Datasheet v%d for product #%d', (int) $doc['version'], $product_id ), 'document:' . (int) $doc['_ID'], $contact_id );
		}
		return $url;
	}
}
