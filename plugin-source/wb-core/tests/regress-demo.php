<?php
/**
 * Regression tests for 1.2.0: the demo's year of trading. plan() is pure, so its shape is checked
 * here without a database: deterministic, a year long, seasonal, with the loose ends a first visit
 * should find. (run() needs WordPress and the engines; it is exercised on staging.)
 *
 *   php tests/regress-demo.php
 */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }
date_default_timezone_set( 'UTC' );
require_once dirname( __DIR__ ) . '/includes/class-wb-demo-seed.php';

$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

$today = '2026-10-08';
$p = WB_Demo_Seed::plan( $today );

section( 'the script' );
eq( 'the same year every time', WB_Demo_Seed::plan( $today ), $p );
eq( 'a different day, a different year', WB_Demo_Seed::plan( '2026-10-09' ) === $p, false );
eq( 'it starts twelve months back, on the first', $p['start'], '2025-10-01' );
$dates = array_column( $p['quotes'], 'date' );
eq( 'enough quotes for a year', count( $p['quotes'] ) >= 60 && count( $p['quotes'] ) <= 110, true );
eq( 'quotes are in date order', $dates === ( function ( $d ) { sort( $d ); return $d; } )( $dates ), true );
eq( 'nothing in the future, nothing before the start', max( $dates ) <= $today && min( $dates ) >= $p['start'], true );
eq( 'no quote lands on a weekend', array_values( array_filter( $dates, fn( $d ) => (int) gmdate( 'N', strtotime( $d ) ) >= 6 ) ), [] );
$fates = array_count_values( array_column( $p['quotes'], 'fate' ) );
eq( 'most are accepted, some declined, a few still out, one draft', $fates['accepted'] > 0.6 * count( $p['quotes'] ) && ( $fates['declined'] ?? 0 ) >= 3 && ( $fates['sent'] ?? 0 ) >= 2 && 1 === ( $fates['draft'] ?? 0 ), true );
eq( 'the draft is the newest, with a price below the floor typed on it', 'draft' === end( $p['quotes'] )['fate'] && 1 === count( end( $p['quotes'] )['manual'] ), true );
$accepted = array_filter( $p['quotes'], fn( $q ) => 'accepted' === $q['fate'] );
$unpaid   = array_filter( $accepted, fn( $q ) => null === $q['paid_on'] );
eq( 'some invoices are not paid yet (recent ones, the held account, two old ones)', count( $unpaid ) >= 4 && count( $unpaid ) < count( $accepted ) / 2, true );
$old_unpaid = array_filter( $unpaid, fn( $q ) => ( strtotime( $today ) - strtotime( $q['date'] ) ) / 86400 > 50 );
eq( 'at least two of them are old enough to be overdue', count( $old_unpaid ) >= 2, true );
foreach ( $accepted as $q ) if ( null !== $q['paid_on'] && $q['paid_on'] < $q['date'] ) eq( 'paid before quoted: ' . $q['date'], false, true );
eq( 'cash customers pay on the day', array_values( array_filter( $accepted, fn( $q ) => 0 === WB_Demo_Seed::CUSTOMERS[ $q['customer'] ][7] && null !== $q['paid_on'] && $q['paid_on'] !== WB_Demo_Seed::workday( $q['date'], 2 ) ) ), [] );
eq( 'every customer bought something', count( array_unique( array_column( $p['quotes'], 'customer' ) ) ), 8 );
eq( 'the held account went quiet after the first months', array_values( array_filter( $p['quotes'], fn( $q ) => 4 === $q['customer'] && $q['date'] > '2026-03-31' ) ), [] );

section( 'seasonality' );
$coat = array_keys( array_filter( WB_Demo_Seed::PRODUCTS, fn( $x ) => 'Coatings' === $x[2] ) );
$by_month = [];
foreach ( $p['quotes'] as $q ) { $m = substr( $q['date'], 5, 2 ); foreach ( $q['lines'] as [ $pi, $qty ] ) { $by_month[ $m ]['all'] = ( $by_month[ $m ]['all'] ?? 0 ) + 1; if ( in_array( $pi, $coat, true ) ) $by_month[ $m ]['coat'] = ( $by_month[ $m ]['coat'] ?? 0 ) + 1; } }
$share = fn( array $months ) => array_sum( array_map( fn( $m ) => $by_month[ $m ]['coat'] ?? 0, $months ) ) / max( 1, array_sum( array_map( fn( $m ) => $by_month[ $m ]['all'] ?? 0, $months ) ) );
eq( 'coatings are a bigger share of the lines in spring than in summer', $share( [ '09', '10', '11' ] ) > $share( [ '12', '01', '02' ] ), true );
eq( 'spring months carry more quotes', count( array_filter( $dates, fn( $d ) => in_array( substr( $d, 5, 2 ), [ '09', '10', '11' ], true ) ) ) > count( array_filter( $dates, fn( $d ) => in_array( substr( $d, 5, 2 ), [ '12', '01', '02' ], true ) ) ), true );

section( 'stock' );
eq( 'stock arrives before anything is sold', $p['pos'][0]['date'] < $dates[0], true );
eq( 'every product is received at the start', count( array_unique( array_merge( ...array_map( fn( $po ) => array_column( $po['lines'], 0 ), array_slice( $p['pos'], 0, 3 ) ) ) ) ), count( WB_Demo_Seed::PRODUCTS ) );
eq( 'one purchase order is still open', 1 === count( array_filter( $p['pos'], fn( $po ) => ! $po['received'] ) ), true );
$sold = [];
foreach ( $accepted as $q ) foreach ( $q['lines'] as [ $pi, $qty ] ) $sold[ $pi ] = ( $sold[ $pi ] ?? 0 ) + $qty;
$recv = [];
foreach ( $p['pos'] as $po ) if ( $po['received'] ) foreach ( $po['lines'] as [ $pi, $qty ] ) $recv[ $pi ] = ( $recv[ $pi ] ?? 0 ) + $qty;
$short = [];
foreach ( $sold as $pi => $n ) if ( $n > ( $recv[ $pi ] ?? 0 ) ) $short[] = WB_Demo_Seed::PRODUCTS[ $pi ][0] . ' sold ' . $n . ' of ' . ( $recv[ $pi ] ?? 0 );
eq( 'nothing is sold that never arrived (stock can never go negative)', $short, [] );

section( 'the helpers' );
eq( 'workday skips the weekend', [ WB_Demo_Seed::workday( '2026-10-09', 1 ), WB_Demo_Seed::workday( '2026-10-09', 3 ), WB_Demo_Seed::workday( '2026-10-05', 0 ) ], [ '2026-10-12', '2026-10-14', '2026-10-05' ] );
eq( 'the clock: real time until the seed sets it', WB_Demo_Seed::now( '2026-10-08 09:00:00' ), '2026-10-08 09:00:00' );
WB_Demo_Seed::tick( '2025-11-03', '10:15:00' );
eq( 'the clock: the seed\'s time while it runs', WB_Demo_Seed::now( '2026-10-08 09:00:00' ), '2025-11-03 10:15:00' );
WB_Demo_Seed::$clock = '';

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
