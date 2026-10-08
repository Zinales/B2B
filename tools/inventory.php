<?php
/**
 * Inventory of everything wb-core exposes: shortcodes, REST routes, capabilities, roles,
 * default options and CCT columns. Loads the plugin with stand-ins for the few WordPress
 * functions it calls while registering, then prints JSON.
 *
 *   php tools/inventory.php            -> JSON on stdout
 *
 * tools/build.py compares this against tools/inventory-baseline.json: anything that existed in
 * the baseline and is now missing stops the build (a working part was removed). New items are
 * fine; accept them into the baseline with `python tools/build.py --accept-inventory`.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
date_default_timezone_set( 'UTC' );
$GLOBALS['INV'] = [ 'shortcodes' => [], 'routes' => [], 'options' => [], 'hooks' => [] ];
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugins_url( $p = '', $f = '' ) { return $p; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['INV']['hooks'][ $h ][] = $cb; return true; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { return add_action( $h, $cb, $p, $a ); }
function add_shortcode( $t, $cb ) { $GLOBALS['INV']['shortcodes'][] = $t; }
function register_rest_route( $ns, $r, $args = [], $o = false ) { $GLOBALS['INV']['routes'][] = $ns . $r; return true; }
function add_option( $k, $v = '', $d = '', $a = 'yes' ) { $GLOBALS['INV']['options'][] = $k; return true; }
function apply_filters( $h, $v ) { return $v; }
function do_action( $h ) {}
function __( $s, $d = '' ) { return $s; }
function wp_next_scheduled( $h ) { return true; }
function get_option( $k, $d = false ) { return $d; }
function is_admin() { return false; }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }

$root = dirname( __DIR__ ) . '/plugin-source/wb-core/';
require $root . 'wb-core.php';

foreach ( get_declared_classes() as $c ) {
	if ( 0 === strpos( $c, 'WB_' ) && method_exists( $c, 'init' ) ) {
		try { $c::init(); } catch ( Throwable $e ) { fwrite( STDERR, "init {$c}: " . $e->getMessage() . "\n" ); }
	}
}
foreach ( $GLOBALS['INV']['hooks']['rest_api_init'] ?? [] as $cb ) {
	try { call_user_func( $cb ); } catch ( Throwable $e ) { fwrite( STDERR, 'rest_api_init: ' . $e->getMessage() . "\n" ); }
}
try { wb_default_options(); } catch ( Throwable $e ) { fwrite( STDERR, 'options: ' . $e->getMessage() . "\n" ); }

$caps = class_exists( 'WB_Roles' ) && defined( 'WB_Roles::CAPS' ) ? array_keys( WB_Roles::CAPS ) : [];
$roles = [];
if ( class_exists( 'WB_Roles' ) && method_exists( 'WB_Roles', 'map' ) ) {
	try { $roles = array_keys( WB_Roles::map() ); } catch ( Throwable $e ) { fwrite( STDERR, 'roles: ' . $e->getMessage() . "
" ); }
}

$columns = [];
$schema = json_decode( (string) file_get_contents( $root . 'schema/wb-ccts.json' ), true );
foreach ( ( $schema['ccts'] ?? [] ) as $t => $def ) foreach ( ( $def['fields'] ?? [] ) as $f ) $columns[] = $t . '.' . $f['name'];

$out = [
	'shortcodes' => array_values( array_unique( $GLOBALS['INV']['shortcodes'] ) ),
	'routes'     => array_values( array_unique( $GLOBALS['INV']['routes'] ) ),
	'caps'       => $caps,
	'roles'      => $roles,
	'options'    => array_values( array_unique( $GLOBALS['INV']['options'] ) ),
	'columns'    => $columns,
];
foreach ( $out as &$v ) sort( $v );
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
