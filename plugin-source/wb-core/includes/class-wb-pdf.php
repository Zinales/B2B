<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Pdf — HTML → PDF, on this server, with the Dompdf engine in lib/dompdf (1.1.0).
 *
 * Why bundled (Kaycie's reasoning, which holds here): the documents carry prices, bank details
 * and ID numbers, which must never leave the box, so an external PDF service is out; Dompdf is
 * pure PHP, so nothing has to be installed on the server. Only the bundled DejaVu Sans is used
 * (full Unicode, so any name renders), so the engine never writes into the plugin folder; its
 * font cache and temp files live in the private folder. Remote fetches are off (no SSRF), and
 * file access is confined to the plugin and the work folder.
 *
 * render() returns '' on any failure and the caller keeps the HTML version, so a document is
 * never lost for want of a PDF.
 */
class WB_Pdf {

	private static ?bool $loaded = null;

	private static function loaded(): bool {
		if ( null !== self::$loaded ) return self::$loaded;
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			$auto = WB_PLUGIN_DIR . 'lib/dompdf/autoload.inc.php';
			if ( is_file( $auto ) ) require_once $auto;
		}
		return self::$loaded = class_exists( '\Dompdf\Dompdf' );
	}

	public static function available(): bool {
		return self::loaded();
	}

	/** The writable work folder (font cache, temp), under the private folder so it is never web-served. */
	public static function work_dir(): string {
		$dir = rtrim( WB_Storage::base_dir(), '/' ) . '/pdf-work';
		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) wp_mkdir_p( $dir ); else @mkdir( $dir, 0750, true );
		}
		return $dir;
	}

	/** A whole HTML document → PDF bytes, A4 portrait. '' when the engine is missing or fails. */
	public static function render( string $html, string $paper = 'A4', string $orientation = 'portrait' ): string {
		if ( ! self::loaded() ) return '';
		try {
			$work = self::work_dir();
			$opts = new \Dompdf\Options();
			$opts->set( 'isRemoteEnabled', false );
			$opts->set( 'isHtml5ParserEnabled', true );
			$opts->set( 'isPhpEnabled', false );
			$opts->set( 'defaultFont', 'DejaVu Sans' );
			$opts->set( 'fontDir', WB_PLUGIN_DIR . 'lib/dompdf/vendor/dompdf/dompdf/lib/fonts' );
			$opts->set( 'fontCache', $work );
			$opts->set( 'tempDir', $work );
			$opts->set( 'chroot', [ WB_PLUGIN_DIR, $work ] );
			$pdf = new \Dompdf\Dompdf( $opts );
			$pdf->loadHtml( $html, 'UTF-8' );
			$pdf->setPaper( $paper, $orientation );
			$pdf->render();
			$out = (string) $pdf->output();
			return 0 === strpos( $out, '%PDF' ) ? $out : '';
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wb_ledger_write' ) ) wb_ledger_write( 'pdf_failed', 'wb_documents', 0, null, [ 'error' => mb_substr( $e->getMessage(), 0, 200 ) ] );
			return '';
		}
	}
}
