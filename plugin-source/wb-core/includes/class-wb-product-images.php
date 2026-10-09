<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Product_Images — a picture for each product (1.6.0, review of 9 October: "a picture on the
 * product row, the quote line and the datasheet is expected everywhere now, and it reduces picking
 * errors").
 *
 * A product picture is not private (it is what the product looks like), so it lives in the
 * WordPress media library, where WordPress makes the small sizes itself, and the attachment carries
 * the product's id in its meta (_wb_product_id). Nothing in the JetEngine tables changes. A new
 * picture replaces the old one on the screens; the old attachment stays in the library, marked
 * _wb_product_id_was, so nothing is deleted. Every change is in the audit trail.
 */
class WB_Product_Images {

	const META     = '_wb_product_id';
	const META_WAS = '_wb_product_id_was';
	const TYPES    = [ 'image/jpeg', 'image/png', 'image/webp' ];
	const MAX      = 5 * 1024 * 1024;

	public static function init(): void {
		add_filter( 'wb_panel_handlers', function ( array $h ): array { $h['product_image'] = [ __CLASS__, 'handle' ]; return $h; } );
	}

	/** Which upload problem, if any, in words. Pure. */
	public static function problem( array $file ): string {
		if ( empty( $file['tmp_name'] ) || (int) ( $file['error'] ?? 1 ) !== 0 ) return 'Choose a picture to upload.';
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX ) return 'That picture is larger than 5 MB. Save a smaller copy and try again.';
		if ( ! in_array( (string) ( $file['type'] ?? '' ), self::TYPES, true ) ) return 'A product picture must be a JPG, PNG or WebP image.';
		return '';
	}

	/** [ product_id => attachment_id ] for many products in one query; the newest picture wins. */
	public static function for_products( array $product_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
		if ( ! $ids ) return [];
		global $wpdb;
		$sql  = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN (" . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ') ORDER BY post_id ASC';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ self::META ], array_map( 'strval', $ids ) ) ), ARRAY_A );
		$out  = [];
		foreach ( $rows as $r ) $out[ (int) $r['meta_value'] ] = (int) $r['post_id'];
		return $out;
	}

	private static array $map = [];

	/** Look up a page of products' pictures at once, so a list asks the database one time, not fifty. */
	public static function prime( array $rows, string $key = '_ID' ): void {
		$want = array_diff( array_map( fn( $r ) => (int) ( $r[ $key ] ?? 0 ), $rows ), array_keys( self::$map ) );
		if ( ! $want ) return;
		$found = self::for_products( $want );
		foreach ( $want as $id ) self::$map[ $id ] = $found[ $id ] ?? 0;
	}

	public static function for_product( int $product_id ): int {
		if ( ! array_key_exists( $product_id, self::$map ) ) self::$map[ $product_id ] = self::for_products( [ $product_id ] )[ $product_id ] ?? 0;
		return self::$map[ $product_id ];
	}

	/** A small picture for a row or a line ('' when there is none). */
	public static function thumb( int $attachment_id, string $alt, int $px = 40 ): string {
		if ( $attachment_id <= 0 || ! function_exists( 'wp_get_attachment_image_url' ) ) return '';
		$src = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
		return $src ? '<img class="wb-thumb" src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" width="' . $px . '" height="' . $px . '" loading="lazy">' : '';
	}

	/** The picture as a data URI for a PDF (the PDF engine fetches nothing). '' when there is none. */
	public static function data_uri( int $product_id, string $size = 'medium' ): string {
		$id = self::for_product( $product_id );
		if ( ! $id || ! function_exists( 'get_attached_file' ) ) return '';
		$file = (string) get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['sizes'][ $size ]['file'] ) ) $file = trailingslashit( dirname( $file ) ) . $meta['sizes'][ $size ]['file'];
		if ( ! is_file( $file ) || filesize( $file ) > self::MAX ) return '';
		$type = (string) ( wp_check_filetype( $file )['type'] ?? '' );
		return in_array( $type, self::TYPES, true ) ? 'data:' . $type . ';base64,' . base64_encode( (string) file_get_contents( $file ) ) : '';
	}

	/** The picture and the upload form for the product's page. */
	public static function panel( array $p ): string {
		$pid = (int) $p['_ID'];
		$aid = self::for_product( $pid );
		$img = $aid && function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( $aid, 'medium' ) : '';
		$h   = '<div class="wb-product-pic">' . ( $img ? '<img src="' . esc_url( $img ) . '" alt="' . esc_attr( (string) $p['name'] ) . '">' : '<div class="wb-product-pic-none">No picture yet</div>' );
		if ( current_user_can( 'wb_manage_products' ) ) {
			$h .= WB_Render::form_open( 'product_image', true ) . '<input type="hidden" name="product_id" value="' . $pid . '">'
				. '<label class="wb-field" for="wb-pic-file"><span>' . ( $img ? 'A new picture' : 'Add a picture' ) . '</span><input id="wb-pic-file" type="file" name="image" accept="image/jpeg,image/png,image/webp" required><small>JPG, PNG or WebP, up to 5 MB. It shows on lists, quote lines and the datasheet.</small></label>'
				. WB_Render::form_close( $img ? 'Replace the picture' : 'Upload' );
		}
		return $h . '</div>';
	}

	public static function handle() {
		if ( ! current_user_can( 'wb_manage_products' ) ) return new WP_Error( 'wb_forbidden', 'Product pictures are not part of your work.' );
		$pid = absint( $_POST['product_id'] ?? 0 );
		if ( ! WB_CCT::get( 'wb_products', $pid ) ) return new WP_Error( 'wb_missing', 'That product could not be found.' );
		$file = (array) ( $_FILES['image'] ?? [] );
		if ( ! empty( $file['tmp_name'] ) ) $file['type'] = (string) ( wp_check_filetype_and_ext( $file['tmp_name'], (string) $file['name'] )['type'] ?: '' );   // what the file is, not what the browser said
		$why = self::problem( $file );
		if ( '' !== $why ) return new WP_Error( 'wb_image', $why );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$old = self::for_product( $pid );
		$aid = media_handle_upload( 'image', 0, [ 'post_title' => 'Product ' . $pid ] );
		if ( is_wp_error( $aid ) ) return $aid;
		update_post_meta( (int) $aid, self::META, (string) $pid );
		if ( $old ) { delete_post_meta( $old, self::META ); update_post_meta( $old, self::META_WAS, (string) $pid ); }
		wb_ledger_write( 'product_image_set', 'wb_products', $pid, $old ? [ 'attachment' => $old ] : null, [ 'attachment' => (int) $aid ] );
		return [ 'msg' => 'Picture saved.' ];
	}
}
