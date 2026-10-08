<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Storage — files off the database (rule 3, §4).
 *
 * Driver `local`: wp-content/wb-private, web access denied (.htaccess for Apache 2.2 + 2.4, and
 * an index.php). Rows keep a DRIVER-RELATIVE storage_key, never a URL, so an R2 driver can be
 * added later with no schema change. Files are reached only through the tokened REST download
 * (WB_Rest::download), which checks access and ledgers every hand-out.
 *
 * nginx does not read .htaccess: add `location ^~ /wp-content/wb-private { deny all; }` to the
 * server block (README).
 */
class WB_Storage {

	const HTACCESS = "# B2B Wholesale private files: never served directly. Downloads go through the permission check.\n"
		. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	const TOKEN_PREFIX = 'wb_dl_';

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'ensure_dir' ] );   // re-asserts the deny rules after an upgrade
	}

	public static function driver(): string {
		return (string) apply_filters( 'wb_storage_driver', 'local' );
	}

	public static function base_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'wb-private';
	}

	/** Idempotent: create the folder and its deny rules. */
	public static function ensure_dir(): void {
		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
		$ht = $dir . '/.htaccess';
		if ( ! is_file( $ht ) || self::HTACCESS !== (string) @file_get_contents( $ht ) ) @file_put_contents( $ht, self::HTACCESS );
		if ( ! is_file( $dir . '/index.php' ) ) @file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}

	/** Extensions a stored file may keep. Anything else is stored as .bin (never .php, .phtml, .svg…). */
	const SAFE_EXTENSIONS = [ 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'csv', 'tsv', 'txt', 'html', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'rtf' ];

	/**
	 * Relative, traversal-proof, safe characters only. Characters are filtered FIRST, then every
	 * '', '.' and '..' segment is dropped — so ".%./" (which the filter turns into "../") can never
	 * survive as a parent-directory step.
	 */
	public static function sane_key( string $key ): string {
		$key  = (string) preg_replace( '#[^a-zA-Z0-9_\-./]#', '', str_replace( '\\', '/', $key ) );
		$keep = [];
		foreach ( explode( '/', $key ) as $seg ) {
			if ( '' === $seg || '.' === $seg || '..' === $seg ) continue;
			$keep[] = $seg;
		}
		return implode( '/', $keep );
	}

	/** The extension a stored file gets: a whitelisted one (lower-cased), else 'bin'. */
	public static function safe_extension( string $name ): string {
		$base = basename( str_replace( '\\', '/', $name ) );
		$dot  = strrpos( $base, '.' );
		$ext  = false === $dot ? '' : strtolower( substr( $base, $dot + 1 ) );
		return in_array( $ext, self::SAFE_EXTENSIONS, true ) ? $ext : 'bin';
	}

	public static function path( string $key ): string {
		return self::base_dir() . '/' . self::sane_key( $key );
	}

	public static function exists( string $key ): bool {
		return '' !== self::sane_key( $key ) && is_file( self::path( $key ) );
	}

	/**
	 * An unguessable name: the suggested key plus 16 random characters before the extension. The
	 * extension is whitelisted (safe_extension): an uploader's "evil.php" is stored as ….bin.
	 */
	private static function unguessable( string $key ): string {
		$key  = self::sane_key( $key );
		$dir  = false !== strpos( $key, '/' ) ? substr( $key, 0, strrpos( $key, '/' ) + 1 ) : '';
		$base = basename( $key );
		if ( false !== strrpos( $base, '.' ) ) $base = substr( $base, 0, strrpos( $base, '.' ) );
		$base = str_replace( '.', '-', $base );   // no inner dots: "x.php.csv" never reads as .php anywhere
		return $dir . ( '' !== $base ? $base : 'file' ) . '-' . strtolower( wp_generate_password( 16, false, false ) ) . '.' . self::safe_extension( $key );
	}

	/** Move (or copy, for uploads) a file into private storage. Returns the stored key, or '' on failure. */
	public static function put_file( string $src, string $suggested_key ): string {
		if ( ! is_file( $src ) ) return '';
		self::ensure_dir();
		$key  = self::unguessable( $suggested_key );
		$dest = self::path( $key );
		wp_mkdir_p( dirname( $dest ) );
		$ok = @rename( $src, $dest );
		if ( ! $ok ) {
			$ok = @copy( $src, $dest );
			if ( $ok ) @unlink( $src );
		}
		return $ok ? $key : '';
	}

	/** Store a string (generated CSV, PDF bytes). Returns the key or ''. */
	public static function put_contents( string $contents, string $suggested_key ): string {
		self::ensure_dir();
		$key  = self::unguessable( $suggested_key );
		$dest = self::path( $key );
		wp_mkdir_p( dirname( $dest ) );
		return false === @file_put_contents( $dest, $contents ) ? '' : $key;
	}

	/** A stored file's contents ('' when missing). For the engine's own use (bank import steps). */
	public static function get_contents( string $key ): string {
		return self::exists( $key ) ? (string) file_get_contents( self::path( $key ) ) : '';
	}

	/* ---------- download tokens: short-lived, one record each ---------- */

	/**
	 * A token for ONE document. $ttl seconds (default 2 minutes, for a click inside the workspace);
	 * a datasheet link a person sends to a customer may live up to 7 days. Single-use by default.
	 */
	public static function issue_token( int $doc_id, int $ttl = 120, bool $single_use = true ): string {
		$ttl   = max( 30, min( 7 * DAY_IN_SECONDS, $ttl ) );
		$token = strtolower( wp_generate_password( 40, false, false ) );
		set_transient( self::TOKEN_PREFIX . $token, [ 'doc' => $doc_id, 'by' => get_current_user_id(), 'single' => $single_use ? 1 : 0, 'exp' => time() + $ttl ], $ttl );
		return $token;
	}

	public static function consume_token( string $token ): ?array {
		$token = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) );
		if ( strlen( $token ) < 32 ) return null;
		$data = get_transient( self::TOKEN_PREFIX . $token );
		if ( ! is_array( $data ) || (int) ( $data['exp'] ?? 0 ) < time() ) return null;
		if ( ! empty( $data['single'] ) ) delete_transient( self::TOKEN_PREFIX . $token );
		return $data;
	}

	/** Stream a stored file and exit. PDFs and images may open inline; everything else downloads. */
	public static function stream( string $key, string $filename = '', bool $inline = false ): void {
		$path = self::path( $key );
		if ( '' === self::sane_key( $key ) || ! is_file( $path ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		$type     = wp_check_filetype( $path );
		$mime     = $type['type'] ?: 'application/octet-stream';
		$viewable = 'application/pdf' === $mime || 0 === strpos( $mime, 'image/' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: ' . ( $inline && $viewable ? 'inline' : 'attachment' ) . '; filename="' . sanitize_file_name( $filename ?: basename( $path ) ) . '"' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		exit;
	}
}
