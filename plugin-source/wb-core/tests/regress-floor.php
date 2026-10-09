<?php
/**
 * Regression tests for 1.7.0 (review of 9 October): the picking list and the signature.
 *  1. signature_bytes(): only a real PNG in a data URL, and not too big.
 *  2. picking_html(): what to pick, how many, from where, which need a batch; the sign-off line.
 *
 *   php tests/regress-floor.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wb-floor-test-' . getmypid() );
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; } function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0750, true ); }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function apply_filters( $h, $v ) { return $v; }
$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'render', 'storage', 'pdf', 'docs', 'floor' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( is_string( $got ) && strlen( $got ) > 200 ? substr( $got, 0, 200 ) . '…' : $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}

// a real 40×20 PNG, made here so the test needs no file
$img = imagecreatetruecolor( 40, 20 );
imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
imageline( $img, 2, 15, 38, 4, imagecolorallocate( $img, 11, 31, 58 ) );
ob_start(); imagepng( $img ); $png = (string) ob_get_clean();
$url = 'data:image/png;base64,' . base64_encode( $png );
eq( 'a drawn signature comes back as its PNG', WB_Floor::signature_bytes( $url ), $png );
eq( 'not a data URL', WB_Floor::signature_bytes( 'https://evil.example/x.png' ), '' );
eq( 'a JPEG is refused', WB_Floor::signature_bytes( 'data:image/jpeg;base64,' . base64_encode( $png ) ), '' );
eq( 'an SVG is refused', WB_Floor::signature_bytes( 'data:image/svg+xml;base64,' . base64_encode( '<svg onload="x()"/>' ) ), '' );
eq( 'labelled PNG but not a PNG inside', WB_Floor::signature_bytes( 'data:image/png;base64,' . base64_encode( str_repeat( 'GIF89a', 40 ) ) ), '' );
eq( 'not base64', WB_Floor::signature_bytes( 'data:image/png;base64,<<<>>>' ), '' );
eq( 'too large', WB_Floor::signature_bytes( 'data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\n" . str_repeat( 'a', WB_Floor::SIG_MAX + 10 ) ) ), '' );
eq( 'empty', WB_Floor::signature_bytes( '' ), '' );

$brand = [ 'display_name' => 'Demo Technical Supplies', 'legal_name' => '', 'colors' => [ 'primary' => '#8A3B52', 'ink' => '#0B1F3A' ] ];
$h = WB_Floor::picking_html( [ 'order_number' => 'ORD-2026-000031', 'customer' => 'Karoo Agri (Pty) Ltd', 'required_by' => '2026-10-12', 'fulfilment' => 'collection', 'made' => '2026-10-09' ],
	[ [ 'sku' => 'ADH-EP200', 'name' => 'Epoxy adhesive 200 ml', 'qty' => '10', 'unit' => 'each', 'location' => 'Aisle 3, bay 2', 'batch' => true ], [ 'sku' => 'FST-HN16', 'name' => 'Hex nut M16', 'qty' => '2', 'unit' => 'box', 'location' => '', 'batch' => false ] ], $brand );
eq( 'the order and the customer', false !== strpos( $h, 'Pick for ORD-2026-000031' ) && false !== strpos( $h, 'Karoo Agri (Pty) Ltd' ), true );
eq( 'how many, in big type', false !== strpos( $h, '<td class="n big">10 <small>each</small></td>' ), true );
eq( 'where from', false !== strpos( $h, 'Aisle 3, bay 2' ), true );
eq( 'no place on record shows a dash', false !== strpos( $h, '<td>—</td>' ), true );
eq( 'a batch to write down only where it is tracked', 1 === substr_count( $h, 'Write the batch' ), true );
eq( 'a box to tick per line', 2 === substr_count( $h, 'class="tick"' ), true );
eq( 'picked by and checked by', false !== strpos( $h, 'Picked by:' ) && false !== strpos( $h, 'Checked by:' ), true );
eq( 'collected, by when', false !== strpos( $h, 'Collected by the customer' ) && false !== strpos( $h, '2026-10-12' ), true );
eq( 'nothing left to pick says so', false !== strpos( WB_Floor::picking_html( [ 'order_number' => 'X', 'customer' => 'Y' ], [], $brand ), 'Nothing is left to pick' ), true );
eq( 'real PDF bytes', 0 === strpos( WB_Pdf::render( $h ), '%PDF' ), true );

if ( is_dir( WP_CONTENT_DIR ) ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $x ) $x->isDir() ? rmdir( $x ) : unlink( $x ); rmdir( WP_CONTENT_DIR ); }
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
