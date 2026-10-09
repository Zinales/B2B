<?php
/**
 * Regression tests for 1.7.0 (review of 9 October): customers order from their own account.
 *  1. Stock is told in words, never the number.
 *  2. The basket: adding, setting, taking out, nothing under zero, a limit on lines.
 *  3. "Order again" turns an old order's lines into basket quantities.
 *
 *   php tests/regress-portal-shop.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function add_action( ...$a ) {} function add_filter( ...$a ) {} function add_shortcode( ...$a ) {}
require_once WB_PLUGIN_DIR . 'includes/class-wb-portal-shop.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}

eq( 'plenty', WB_Portal_Shop::stock_words( 300, 20 ), 'In stock' );
eq( 'at the reorder point', WB_Portal_Shop::stock_words( 20, 20 ), 'Low stock' );
eq( 'none', WB_Portal_Shop::stock_words( 0, 20 ), 'To order' );
eq( 'less than nothing (all put aside)', WB_Portal_Shop::stock_words( -3, 20 ), 'To order' );
eq( 'some, but not as many as wanted', WB_Portal_Shop::stock_words( 30, 20, 50 ), 'Part in stock' );
eq( 'the words never carry the number', preg_match( '/\d/', WB_Portal_Shop::stock_words( 1234, 20 ) ), 0 );

eq( 'add to an empty basket', WB_Portal_Shop::basket_lines( [], [ 7 => 10 ], true ), [ 7 => 10.0 ] );
eq( 'adding again adds up', WB_Portal_Shop::basket_lines( [ 7 => 10.0 ], [ 7 => 5, 9 => 1 ], true ), [ 7 => 15.0, 9 => 1.0 ] );
eq( 'setting replaces', WB_Portal_Shop::basket_lines( [ 7 => 10.0, 9 => 1.0 ], [ 7 => 4 ] ), [ 7 => 4.0, 9 => 1.0 ] );
eq( 'zero takes it out', WB_Portal_Shop::basket_lines( [ 7 => 10.0, 9 => 1.0 ], [ 7 => 0 ] ), [ 9 => 1.0 ] );
eq( 'a minus is treated as zero', WB_Portal_Shop::basket_lines( [ 7 => 10.0 ], [ 7 => -5 ] ), [] );
eq( 'a product id that is not a number is ignored', WB_Portal_Shop::basket_lines( [], [ 'x' => 3, 0 => 2 ], true ), [] );
eq( 'no more than the limit of lines', count( WB_Portal_Shop::basket_lines( [], array_fill_keys( range( 1, 100 ), 1 ), true ) ), WB_Portal_Shop::MAX_LINES );

eq( 'an old order becomes quantities', WB_Portal_Shop::reorder_lines( [ [ 'product_id' => 7, 'qty_ordered' => '10.0000' ], [ 'product_id' => 9, 'qty_ordered' => 2 ] ] ), [ 7 => 10.0, 9 => 2.0 ] );
eq( 'the same product twice is added up', WB_Portal_Shop::reorder_lines( [ [ 'product_id' => 7, 'qty_ordered' => 3 ], [ 'product_id' => 7, 'qty_ordered' => 4 ] ] ), [ 7 => 7.0 ] );
eq( 'a line with no product or no quantity is left out', WB_Portal_Shop::reorder_lines( [ [ 'product_id' => 0, 'qty_ordered' => 3 ], [ 'product_id' => 8, 'qty_ordered' => 0 ] ] ), [] );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
