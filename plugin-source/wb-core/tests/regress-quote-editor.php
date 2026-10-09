<?php
/**
 * Regression tests for 1.5.0 (review of 9 October): the quote line editor.
 *  1. rank(): the exact code first, then codes and names that start with the words, then anything
 *     that contains them, then every word somewhere; a limit.
 *  2. resolve(): typed text without the list → one product, or words that say what to do.
 *  3. line_edit(): what a changed quantity or price means for the price (manual stays manual;
 *     a typed price is manual; a quantity change re-prices by the rules).
 *  4. The draft's form: inputs labelled, a flagged line says why, the add row and the paste fold.
 *
 *   php tests/regress-quote-editor.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $s ) { return (string) $s; }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function add_shortcode( ...$a ) {} function sanitize_html_class( $s ) { return $s; } function selected( ...$a ) { return ''; }
function wp_nonce_field( ...$a ) { return ''; } function wb_return_field() { return ''; } function wp_create_nonce( $a ) { return 'n'; } function rest_url( $p ) { return 'https://b2b.test/wp-json/' . $p; }
function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); }
function esc_textarea( $s ) { return esc_html( $s ); } function wp_strip_all_tags( $s ) { return strip_tags( $s ); } function current_user_can( $c ) { return true; }

$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'render', 'pricing', 'orders', 'quote-editor' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';
class WB_RowActions { public static function cell( ...$a ) { return '<button>Ask for price approval</button>'; } }
class WB_CCT { public static function find( ...$a ) { return []; } public static function get( ...$a ) { return null; } }

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }
$P = fn( $sku, $name, $bar = '' ) => [ '_ID' => crc32( $sku ), 'sku' => $sku, 'name' => $name, 'barcode' => $bar ];
$all = [ $P( 'ADH-EP200', 'Epoxy adhesive 200 ml (2-part)', '6001234560001' ), $P( 'ADH-EP2000', 'Epoxy adhesive 2 L' ), $P( 'COT-EP20', 'Epoxy floor coating 20 L' ), $P( 'ADH-CT5', 'Contact adhesive 5 L' ), $P( 'FST-HN16', 'Hex nut M16 (box of 100)' ) ];
$skus = fn( array $r ) => array_column( $r, 'sku' );

/* ============================================================ 1 */
section( 'ranking matches' );
eq( 'the exact code first', $skus( WB_Quote_Editor::rank( $all, 'adh-ep200' ) )[0], 'ADH-EP200' );
eq( 'codes starting with the words next, by code', $skus( WB_Quote_Editor::rank( $all, 'ADH' ) ), [ 'ADH-CT5', 'ADH-EP200', 'ADH-EP2000' ] );
eq( 'names starting with the words before names containing them', $skus( WB_Quote_Editor::rank( $all, 'epoxy' ) ), [ 'ADH-EP200', 'ADH-EP2000', 'COT-EP20' ] );
eq( 'contains, anywhere', $skus( WB_Quote_Editor::rank( $all, 'm16' ) ), [ 'FST-HN16' ] );
eq( 'every word, in any order', $skus( WB_Quote_Editor::rank( $all, 'epoxy floor' ) ), [ 'COT-EP20' ] );
eq( 'words in another order still find it', $skus( WB_Quote_Editor::rank( $all, '20 coating' ) ), [ 'COT-EP20' ] );
eq( 'a barcode is exact', $skus( WB_Quote_Editor::rank( $all, '6001234560001' ) ), [ 'ADH-EP200' ] );
eq( 'nothing typed, nothing listed', WB_Quote_Editor::rank( $all, '  ' ), [] );
eq( 'no match', WB_Quote_Editor::rank( $all, 'zzz' ), [] );
eq( 'the limit holds', count( WB_Quote_Editor::rank( $all, 'e', 2 ) ), 2 );

/* ============================================================ 2 */
section( 'resolving typed text' );
eq( 'an exact code wins', WB_Quote_Editor::resolve( 'ADH-EP200', $all[0], $all )['sku'], 'ADH-EP200' );
eq( 'one match is enough', WB_Quote_Editor::resolve( 'hex nut', null, $all )['sku'], 'FST-HN16' );
eq( 'nothing typed', WB_Quote_Editor::resolve( ' ', null, $all ), 'Choose a product.' );
eq( 'no match says so', WB_Quote_Editor::resolve( 'glue gun', null, $all ), 'No product matches "glue gun".' );
eq( 'several matches name them', WB_Quote_Editor::resolve( 'epoxy', null, $all ), 'More than one product matches "epoxy": ADH-EP200, ADH-EP2000, COT-EP20. Type more of the code, or choose one from the list.' );

/* ============================================================ 3 */
section( 'what an edit means for the price' );
$rule   = [ 'qty' => 10, 'unit_price' => 120.0, 'price_source' => 'tier' ];
$manual = [ 'qty' => 10, 'unit_price' => 99.0, 'price_source' => 'manual' ];
eq( 'nothing changed', WB_Orders::line_edit( $rule, 10, 120.0 ), [ false, null ] );
eq( 'the price box left empty and the quantity the same', WB_Orders::line_edit( $rule, 10, null ), [ false, null ] );
eq( 'a cent of float noise is not a change', WB_Orders::line_edit( $rule, 10, 120.001 ), [ false, null ] );
eq( 'a new quantity re-prices by the rules', WB_Orders::line_edit( $rule, 25, 120.0 ), [ true, null ] );
eq( 'a typed price is manual', WB_Orders::line_edit( $rule, 10, 110.0 ), [ true, 110.0 ] );
eq( 'a typed price and a new quantity: the typed price', WB_Orders::line_edit( $rule, 25, 110.0 ), [ true, 110.0 ] );
eq( 'a manual price stays manual when only the quantity changes', WB_Orders::line_edit( $manual, 25, 99.0 ), [ true, 99.0 ] );
eq( 'a typed price is kept to the cent', WB_Orders::line_edit( $rule, 10, 110.456 ), [ true, 110.46 ] );

/* ============================================================ 4 */
section( 'the draft form' );
$q = [ '_ID' => 9, 'customer_id' => 7, 'subtotal' => 1290, 'vat' => 193.5, 'total' => 1483.5 ];
$lines = [
	[ '_ID' => 31, 'description' => 'ADH-EP200 Epoxy adhesive 200 ml', 'qty' => '10.0000', 'unit_price' => 129, 'price_source' => 'tier', 'line_total' => 1290, 'floor_price' => 109.85, 'cost_price' => 84.5, 'list_price' => 129, 'below_floor' => 'no', 'out_of_date' => 'no' ],
	[ '_ID' => 32, 'description' => 'FST-HN16 Hex nut M16', 'qty' => '2', 'unit_price' => 85, 'price_source' => 'manual', 'line_total' => 170, 'floor_price' => 288, 'cost_price' => 240, 'list_price' => 410.4, 'below_floor' => 'yes', 'out_of_date' => 'no' ],
];
$h = WB_Quote_Editor::draft( $q, $lines );
eq( 'a quantity box per line, labelled', substr_count( $h, '<label class="wb-sr" for="wb-q-' ), 2 );
eq( 'a price box per line, labelled', substr_count( $h, '<label class="wb-sr" for="wb-p-' ), 2 );
eq( 'quantities shown without trailing zeros', false !== strpos( $h, 'name="qty[31]" value="10"' ), true );
eq( 'a whole quantity keeps its zero (10 is not 1)', WB_Render::num( '10' ) . ' ' . WB_Render::num( 100 ) . ' ' . WB_Render::num( '2.50' ) . ' ' . WB_Render::num( '0.0000' ), '10 100 2.5 0' );
eq( 'prices to the cent', false !== strpos( $h, 'name="price[31]" value="129.00"' ), true );
eq( 'the broken line is marked', substr_count( $h, 'class="is-flagged"' ), 1 );
eq( 'and says why, on the line', false !== strpos( $h, 'Below the lowest allowed price: R 85.00 is under R 288.00' ), true );
eq( 'the total at the foot', false !== strpos( $h, '<strong>Total R 1 483.50</strong>' ), true );
eq( 'one save for every line', substr_count( $h, '>Save changes</button>' ), 1 );
eq( 'the product search to add', false !== strpos( $h, 'data-wb-pick="https://b2b.test/wp-json/wb/v1/products?customer=7&amp;for=quote' ) || false !== strpos( $h, 'data-wb-pick="https://b2b.test/wp-json/wb/v1/products?customer=7&for=quote' ), true );
eq( 'the search is a labelled combobox', false !== strpos( $h, '<label for="wb-add-product"><span>Add a product</span></label><input id="wb-add-product" type="text" name="product_id_text" autocomplete="off" role="combobox"' ), true );
eq( 'pasting lines is still there, folded', false !== strpos( $h, '<summary>Paste several lines instead</summary>' ), true );
eq( 'no two fields share an id', count( array_unique( preg_match_all( '/ id="([^"]+)"/', $h, $m ) ? $m[1] : [] ) ), count( $m[1] ) );
$n = WB_Quote_Editor::new_form( 7 );
eq( 'the new quote has its own ids', count( array_intersect( preg_match_all( '/ id="([^"]+)"/', $n, $m2 ) ? $m2[1] : [], $m[1] ) ), 0 );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
