<?php
/**
 * Regression tests for 1.3.0 (Zina, 8 October 2026): "an explanation of the flows you've built,
 * and a how-to section with walkthroughs".
 *  1. Every flow and walkthrough is complete, and every step names a screen that exists (or the
 *     system's own night work); every work screen is part of at least one flow.
 *  2. The How-to screen renders every flow and walkthrough, with every link an address from
 *     WB_Workspace::url() (BUILD-PATTERNS §2.1), and nothing typed as '/workspace/'.
 *  3. The fold at a screen's foot shows that screen's flow with the screen's own steps marked,
 *     and nothing on a screen outside any flow.
 *  4. The Markdown the docs are written from carries every flow and walkthrough.
 *  5. The import fold's plural: 'categories', not 'categorys'.
 *
 *   php tests/regress-guide.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function home_url( $p = '' ) { return 'https://b2b.test' . $p; }
function add_shortcode( ...$a ) {}
function add_action( ...$a ) {} function add_filter( ...$a ) {}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'workspace', 'render', 'guide', 'records', 'import' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0;
$fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/* ============================================================ 1. the data */
section( 'flows and walkthroughs are complete' );
$screens_in_flows = [];
foreach ( WB_Guide::FLOWS as $k => $f ) {
	eq( "flow {$k} has four parts", count( $f ), 4 );
	[ $title, $idea, $steps, $rules ] = $f;
	eq( "flow {$k} has a title and an idea", '' !== $title && strlen( $idea ) > 40, true );
	eq( "flow {$k} has steps", count( $steps ) >= 3, true );
	foreach ( $steps as $i => $st ) {
		eq( "flow {$k} step {$i} is [screen, words]", count( $st ) === 2 && '' !== trim( $st[1] ), true );
		if ( '' !== $st[0] ) { eq( "flow {$k} step {$i} screen '{$st[0]}' exists", null !== WB_Workspace::screen( $st[0] ), true ); $screens_in_flows[ $st[0] ] = true; }
	}
	foreach ( $rules as $r ) eq( "flow {$k} rule is words", is_string( $r ) && '' !== $r, true );
}
foreach ( WB_Guide::HOWTO as $k => $w ) {
	eq( "walkthrough {$k} has four parts", count( $w ), 4 );
	[ $title, $who, $steps, $flow ] = $w;
	eq( "walkthrough {$k} says who it is for", '' !== $who, true );
	eq( "walkthrough {$k} belongs to a flow", isset( WB_Guide::FLOWS[ $flow ] ), true );
	eq( "walkthrough {$k} has steps", count( $steps ) >= 2, true );
	foreach ( $steps as $i => $st ) eq( "walkthrough {$k} step {$i} screen '{$st[0]}' exists", '' !== $st[0] && null !== WB_Workspace::screen( $st[0] ), true );
}
foreach ( WB_Guide::SCREEN_FLOW as $slug => $keys ) {
	eq( "SCREEN_FLOW {$slug} is a screen", isset( WB_Workspace::SCREENS[ $slug ] ), true );
	foreach ( $keys as $k ) eq( "SCREEN_FLOW {$slug} → {$k} is a flow", isset( WB_Guide::FLOWS[ $k ] ), true );
}
foreach ( WB_Workspace::SCREENS as $slug => $s ) {
	if ( 'howto' === $slug ) continue;
	eq( "screen {$slug} is part of a flow", isset( WB_Guide::SCREEN_FLOW[ $slug ] ), true );
}
eq( 'the How-to screen exists for every staff login', WB_Workspace::screen( 'howto' )[2], 'wb_access_workspace' );
eq( 'the How-to screen renders [wb_howto]', WB_Workspace::screen( 'howto' )[4], '[wb_howto]' );
eq( 'the How-to screen has its own icon', isset( WB_Workspace::ICONS['howto'] ), true );
// the invoice question (Zina, 8 October): every invoice comes from an order; the words say so
eq( 'the selling flow says there is no standalone invoice', false !== strpos( implode( ' ', WB_Guide::FLOWS['sell'][3] ), 'no standalone invoice' ), true );

/* ============================================================ 2. the screen */
section( 'the How-to screen' );
$html = WB_Guide::render();
foreach ( WB_Guide::FLOWS as $k => $f ) {
	eq( "flow {$k} title on the screen", false !== strpos( $html, esc_html( $f[0] ) ), true );
	eq( "flow {$k} has an anchor", false !== strpos( $html, 'id="wb-flow-' . $k . '"' ), true );
}
foreach ( WB_Guide::HOWTO as $k => $w ) {
	eq( "walkthrough {$k} title on the screen", false !== strpos( $html, esc_html( $w[0] ) ), true );
	eq( "walkthrough {$k} has an anchor", false !== strpos( $html, 'id="wb-howto-' . $k . '"' ), true );
}
preg_match_all( '/href="([^"]+)"/', $html, $m );
$bad = [];
foreach ( $m[1] as $href ) {
	if ( '#' === $href[0] ) continue;
	if ( 0 !== strpos( $href, 'https://b2b.test/' ) ) $bad[] = $href;
}
eq( 'every link is an address of the site', $bad, [] );
eq( 'a step on Quotes links to the Quotes screen', false !== strpos( $html, 'href="' . WB_Workspace::url( 'quotes' ) . '"' ), true );
eq( 'a step for the customer links to the portal', false !== strpos( $html, 'href="' . WB_Workspace::url( 'portal' ) . '"' ), true );
eq( "nothing types '/workspace/' in the guide", preg_match( '~/workspace/~', file_get_contents( $base . 'class-wb-guide.php' ) ), 0 );
eq( 'the system\'s own steps are labelled, not linked', substr_count( $html, 'wb-guide-where--sys' ), 3 );
eq( 'no empty step', preg_match( '/<li[^>]*><span>|<span><\/span>/', $html ), 0 );

/* ============================================================ 3. the fold */
section( 'the fold at the foot of a screen' );
$fold = WB_Guide::fold( 'orders' );
eq( 'orders has a fold', '' !== $fold, true );
eq( 'the fold is read only', false !== strpos( $fold, 'wb-fold--reference' ), true );
eq( 'the fold names the selling flow', false !== strpos( $fold, esc_html( WB_Guide::FLOWS['sell'][0] ) ), true );
eq( 'the orders steps are marked as this screen', substr_count( $fold, 'class="is-here"' ), 3 );
eq( 'a marked step says "This screen"', false !== strpos( $fold, '>This screen</a>' ), true );
eq( 'the fold links to the How-to screen', false !== strpos( $fold, 'href="' . WB_Workspace::url( 'howto' ) . '"' ), true );
eq( 'a screen in two flows shows both', substr_count( WB_Guide::fold( 'invoices' ), 'wb-guide-h' ), 2 );
eq( 'the How-to screen itself has no fold', WB_Guide::fold( 'howto' ), '' );
eq( 'an unknown slug has no fold', WB_Guide::fold( 'wp-admin' ), '' );

/* ============================================================ 4. the documents */
section( 'the Markdown the docs are written from' );
$md = WB_Guide::flows_markdown();
foreach ( WB_Guide::FLOWS as $k => $f ) eq( "FLOWS.md has {$k}", false !== strpos( $md, "## {$f[0]}\n" ), true );
eq( 'FLOWS.md names the portal as the customer sees it', false !== strpos( $md, '**Your account.**' ), true );
eq( 'FLOWS.md labels the night work', false !== strpos( $md, '**The system, at night.**' ), true );
eq( 'FLOWS.md says where it comes from', false !== strpos( $md, 'tools/guide-md.php' ), true );
$md = WB_Guide::howto_markdown();
foreach ( WB_Guide::HOWTO as $k => $w ) eq( "HOW-TO.md has {$k}", false !== strpos( $md, "## {$w[0]}\n" ), true );
eq( 'HOW-TO.md has no raw HTML entities', preg_match( '/&#0?39;|&quot;|&amp;/', $md ), 0 );

/* ============================================================ 5. the import plural */
section( 'the import fold plural' );
eq( 'category → categories', WB_Import::plural( 'category' ), 'categories' );
eq( 'price tier → price tiers', WB_Import::plural( 'price tier' ), 'price tiers' );
eq( 'staff member → staff members', WB_Import::plural( 'staff member' ), 'staff members' );
eq( 'KPI → KPIs', WB_Import::plural( 'KPI' ), 'KPIs' );
eq( 'day → days (vowel before y)', WB_Import::plural( 'day' ), 'days' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
