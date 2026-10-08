<?php
/**
 * Regression tests for 1.3.2 (Zina, 8 October 2026): "clicking Datasheets on Products took me to a
 * documents list; I would like to view the documents we have uploaded."
 *  1. Product documents are a fixed set of the document types, kept apart from issued documents.
 *  2. The ⋯ menu says Open for a PDF or an image (the browser shows it) and Download otherwise.
 *  3. The product's own documents are one address, built by WB_Workspace::url().
 *
 *   php tests/regress-documents.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function home_url( $p = '' ) { return 'https://b2b.test' . $p; }
function rest_url( $p = '' ) { return 'https://b2b.test/wp-json/' . $p; }
function add_query_arg( $args, $url = '' ) { if ( ! is_array( $args ) ) { $args = [ $args => $url ]; $url = func_get_arg( 2 ); } return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function wp_create_nonce( $a ) { return 'n'; }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function add_shortcode( ...$a ) {}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'workspace', 'render', 'rowactions', 'documents', 'screens' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}

eq( 'every product document type is a document type', array_values( array_diff( WB_Screens::PRODUCT_DOC_TYPES, WB_Documents::TYPES ) ), [] );
eq( 'datasheets lead', WB_Screens::PRODUCT_DOC_TYPES[0], 'datasheet' );
eq( 'issued PDFs are not product documents', in_array( 'invoice_pdf', WB_Screens::PRODUCT_DOC_TYPES, true ), false );
eq( 'staff documents are not product documents', in_array( 'staff_doc', WB_Screens::PRODUCT_DOC_TYPES, true ), false );

$pdf = WB_Screens::doc_open_item( [ '_ID' => 7, 'storage_key' => 'docs/2026/datasheet-7.pdf' ] );
eq( 'a PDF opens', false !== strpos( $pdf, '<span>Open</span>' ), true );
eq( 'the link goes through the download route, which checks access', false !== strpos( $pdf, 'wb/v1/download?doc=7' ), true );
$png = WB_Screens::doc_open_item( [ '_ID' => 8, 'storage_key' => 'docs/2026/label-8.PNG' ] );
eq( 'an image opens (any case)', false !== strpos( $png, '<span>Open</span>' ), true );
$xls = WB_Screens::doc_open_item( [ '_ID' => 9, 'storage_key' => 'docs/2026/specs-9.xlsx' ] );
eq( 'a spreadsheet downloads', false !== strpos( $xls, '<span>Download</span>' ), true );
eq( 'no extension downloads', false !== strpos( WB_Screens::doc_open_item( [ '_ID' => 1, 'storage_key' => 'x' ] ), '<span>Download</span>' ), true );

eq( 'the product\'s documents are one address', WB_Workspace::url( 'documents', [ 'product' => 12 ] ), 'https://b2b.test/workspace/documents/?product=12' );
eq( 'the Products screen still leads to Datasheets', WB_Workspace::SCREENS['products'][5]['documents'] ?? '', 'Datasheets' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
