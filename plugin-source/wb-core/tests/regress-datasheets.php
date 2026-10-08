<?php
/**
 * Regression tests for 1.4.0 (Zina, 8 October 2026): "datasheet data stored in tables, converted to
 * PDFs, easier to update in bulk … or upload a datasheet, or link it to the online datasheet".
 *  1. resolve(): the row's source decides — data (with words), an uploaded file, a link — and an
 *     empty sheet is never handed out.
 *  2. The sheet's HTML: letterhead, name and code, the specification rows, the words in paragraphs
 *     and lists, revision, footer. Then real PDF bytes from the bundled engine.
 *  3. The datasheet table is a master table: typed, imported and exported like the others; its
 *     columns are in the schema.
 *
 *   php tests/regress-datasheets.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wb-ds-test-' . getmypid() );
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0750, true ); }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function apply_filters( $h, $v ) { return $v; } function add_shortcode( ...$a ) {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $s ) { return (string) $s; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } function absint( $v ) { return abs( (int) $v ); }
function sanitize_email( $s ) { return strtolower( trim( (string) $s ) ); } function is_email( $s ) { return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', (string) $s ); } function sanitize_html_class( $s ) { return $s; }
function wb_enc( string $plain ): string { return 'enc:' . $plain; } function current_user_can( $c ) { return true; }
date_default_timezone_set( 'UTC' );

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'render', 'storage', 'pdf', 'docs', 'datasheets', 'records' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( is_string( $got ) && strlen( $got ) > 300 ? substr( $got, 0, 300 ) . '…' : $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/* ============================================================ 1. resolve */
section( 'where the sheet comes from' );
$spec  = json_encode( [ [ 'label' => 'Viscosity', 'value' => '12000', 'unit' => 'mPa·s' ], [ 'label' => 'Open time', 'value' => '20', 'unit' => 'min' ] ] );
$empty = json_encode( [ [ 'label' => 'Viscosity', 'value' => '', 'unit' => 'mPa·s' ] ] );
$doc   = [ '_ID' => 44, 'version' => 2, 'title' => 'EP200 supplier sheet' ];
$data  = [ 'product_id' => 7, 'source' => 'data', 'headline' => 'Two-part epoxy', 'description' => '', 'revision' => 'Rev 2' ];
$link  = [ 'product_id' => 7, 'source' => 'link', 'external_url' => 'https://maker.example/ep200.pdf' ];
eq( 'no row, no file → none', WB_Datasheets::resolve( null, null, $spec )[0], 'none' );
eq( 'no row, an uploaded file → upload', WB_Datasheets::resolve( null, $doc, '' )[0], 'upload' );
eq( 'data row with words → data', WB_Datasheets::resolve( $data, null, '' )[0], 'data' );
eq( 'data row with only specification values → data', WB_Datasheets::resolve( [ 'source' => 'data' ], null, $spec )[0], 'data' );
eq( 'data row with nothing and blank spec values → none, never an empty sheet', WB_Datasheets::resolve( [ 'source' => 'data', 'headline' => '  ' ], null, $empty )[0], 'none' );
eq( 'data row with nothing but a file on record → the file', WB_Datasheets::resolve( [ 'source' => 'data' ], $doc, '' )[0], 'upload' );
eq( 'data row wins over an uploaded file', WB_Datasheets::resolve( $data, $doc, '' )[0], 'data' );
eq( 'link row → link', WB_Datasheets::resolve( $link, $doc, $spec )[0], 'link' );
eq( 'link row with a blank address falls back to the file', WB_Datasheets::resolve( [ 'source' => 'link', 'external_url' => ' ' ], $doc, '' )[0], 'upload' );
eq( 'upload row → the file', WB_Datasheets::resolve( [ 'source' => 'upload', 'headline' => 'words ignored' ], $doc, '' )[0], 'upload' );
eq( 'upload row with no file yet → none', WB_Datasheets::resolve( [ 'source' => 'upload' ], null, '' )[0], 'none' );
eq( 'spec rows drop blanks and keep order', WB_Datasheets::spec_rows( $spec . '' ), [ [ 'label' => 'Viscosity', 'value' => '12000', 'unit' => 'mPa·s' ], [ 'label' => 'Open time', 'value' => '20', 'unit' => 'min' ] ] );
eq( 'spec rows: broken JSON → nothing', WB_Datasheets::spec_rows( 'not json' ), [] );

/* ============================================================ 2. html + pdf */
section( 'the sheet' );
$brand = [ 'display_name' => 'Demo Technical Supplies', 'legal_name' => 'Demo Technical Supplies (Pty) Ltd', 'reg_number' => '2019/123456/07', 'physical_address' => "12 Main Road\nOudtshoorn 6620",
	'doc_footer' => 'E&OE.', 'colors' => [ 'primary' => '#8A3B52', 'ink' => '#0B1F3A' ], 'logo' => '' ];
$d = [ 'sku' => 'ADH-EP200', 'name' => 'Epoxy adhesive 200 ml (2-part)', 'category' => 'Adhesives', 'unit' => 'each', 'pack_size' => 12, 'shelf_life_days' => 365,
	'headline' => 'Two-part structural epoxy for metal, stone and composites.', 'description' => "Mix 1:1 by volume.\n\nCures rigid.", 'applications' => "- Metal brackets\n- Stone repairs", 'handling' => 'Store between 5 and 25 °C.',
	'revision' => 'Rev 2', 'revised_at' => '2026-03-15', 'specs' => WB_Datasheets::spec_rows( $spec ), 'made' => '2026-10-08' ];
$h = WB_Datasheets::html( $d, $brand );
eq( 'a whole document', 0 === strpos( $h, '<!DOCTYPE html>' ) && false !== strpos( $h, '</html>' ), true );
eq( 'the company on the letterhead', false !== strpos( $h, 'Demo Technical Supplies (Pty) Ltd' ) && false !== strpos( $h, 'Reg. 2019/123456/07' ), true );
eq( 'the product name is the title', false !== strpos( $h, '<h1>Epoxy adhesive 200 ml (2-part)</h1>' ), true );
eq( 'code and category under it', false !== strpos( $h, '<div class="num">ADH-EP200 · Adhesives</div>' ), true );
eq( 'the headline', false !== strpos( $h, '<p class="lead">Two-part structural epoxy for metal, stone and composites.</p>' ), true );
eq( 'pack and shelf life', false !== strpos( $h, 'each (pack of 12)' ) && false !== strpos( $h, '365 days' ), true );
eq( 'the revision and its date', false !== strpos( $h, 'Rev 2 · 2026-03-15' ), true );
eq( 'the specification rows', false !== strpos( $h, '<td class="k">Viscosity</td><td>12000</td><td class="u">mPa·s</td>' ), true );
eq( 'paragraphs split on a blank line', substr_count( $h, '<p>Mix 1:1 by volume.</p>' ) === 1 && substr_count( $h, '<p>Cures rigid.</p>' ) === 1, true );
eq( 'dash lines become a list', false !== strpos( $h, '<ul><li>Metal brackets</li><li>Stone repairs</li></ul>' ), true );
eq( 'handling has its heading', false !== strpos( $h, '<h2>Storage, handling and safety</h2>' ), true );
eq( 'the footer line and the disclaimer', false !== strpos( $h, 'E&amp;OE.' ) && false !== strpos( $h, 'typical values' ), true );
eq( 'words are escaped', false !== strpos( WB_Datasheets::html( $d + [], [ 'legal_name' => '<b>x</b>', 'display_name' => '', 'colors' => $brand['colors'] ] ), '&lt;b&gt;x&lt;/b&gt;' ), true );
$no = WB_Datasheets::html( [ 'sku' => 'X', 'name' => 'Bare', 'specs' => [], 'made' => '2026-10-08' ], $brand );
eq( 'a bare sheet has no empty sections', false === strpos( $no, '<h2>' ) && false === strpos( $no, 'class="meta"' ), true );
eq( 'the PDF engine is bundled', WB_Pdf::available(), true );
$bytes = WB_Pdf::render( $h );
eq( 'real PDF bytes', 0 === strpos( $bytes, '%PDF' ) && strlen( $bytes ) > 2000, true );

/* ============================================================ 3. the master table */
section( 'typed, imported, exported' );
$pol = WB_Records::TABLES['wb_datasheets'] ?? null;
eq( 'datasheets are a master table', null !== $pol, true );
eq( 'on the Documents screen, for people who manage documents', [ $pol['screen'], $pol['cap'] ], [ 'documents', 'wb_manage_documents' ] );
eq( 'one row per product (the natural key)', $pol['key'], [ 'product_id' ] );
eq( 'the product is named by its code in a file', $pol['fields']['product_id']['ref'], [ 'wb_products', 'sku' ] );
$f = WB_Records::fields( 'wb_datasheets' );
eq( 'every policy field is in the schema', array_values( array_diff( array_keys( $pol['fields'] ), array_keys( WB_Records::schema( 'wb_datasheets' ) ) ) ), [] );
eq( 'the source is a choice of three', array_keys( $f['source']['options'] ), [ 'data', 'upload', 'link' ] );
eq( 'the words are textareas', [ $f['description']['type'], $f['applications']['type'], $f['handling']['type'] ], [ 'textarea', 'textarea', 'textarea' ] );
eq( 'revised on is a date', $f['revised_at']['type'], 'date' );
eq( 'the row is cleaned like any other', WB_Records::clean( 'wb_datasheets', [ 'product_id' => '7', 'source' => 'data', 'headline' => '  Two-part epoxy ', 'description' => "a\n\nb", 'revision' => 'Rev 2', 'revised_at' => '2026-03-15' ], false )[1], [] );
$cell = WB_Datasheets::cell( [ 'none', null, null ] );
eq( 'no sheet reads none', false !== strpos( $cell, 'none' ), true );
eq( 'a link opens the online sheet', false !== strpos( WB_Datasheets::cell( [ 'link', $link, null ] ), 'href="https://maker.example/ep200.pdf"' ), true );

if ( is_dir( WP_CONTENT_DIR ) ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_CONTENT_DIR, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $x ) $x->isDir() ? rmdir( $x ) : unlink( $x ); rmdir( WP_CONTENT_DIR ); }
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
