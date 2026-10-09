<?php
/**
 * Regression tests for 1.7.4 (Zina, 9 October: "the administrators don't need to see the business
 * tables; that is a back-end data function; if we expose it, it is an IT function").
 *  1. Who has the Technical screen: WordPress administrators, never an owner or a manager by role.
 *  2. The Technical screen is an Admin screen of its own; System Settings no longer mentions tables.
 *  3. The system checks in words, each fine, worth a look, or needs fixing.
 *
 *   php tests/regress-technical.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
function add_action( ...$a ) {} function add_filter( ...$a ) {} function add_shortcode( ...$a ) {}
$base = WB_PLUGIN_DIR . 'includes/';
foreach ( [ 'roles', 'workspace', 'technical' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';
$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
$m = WB_Roles::map();
eq( 'an owner does not have the IT screen', isset( $m['wb_owner']['caps']['wb_technical'] ), false );
eq( 'nor does a manager', isset( $m['wb_manager']['caps']['wb_technical'] ), false );
eq( 'nor any other role', array_keys( array_filter( $m, fn( $r ) => ! empty( $r['caps']['wb_technical'] ) ) ), [] );
eq( 'WordPress administrators do (they get every staff capability)', isset( WB_Roles::staff_caps()['wb_technical'] ), true );
eq( 'an owner still has System Settings', ! empty( $m['wb_owner']['caps']['wb_manage_settings'] ), true );
eq( 'it can be ticked for someone who looks after the site', WB_Roles::catalog()['technical']['caps'] ?? [], [ 'wb_technical' ] );
$s = WB_Workspace::screen( 'technical' );
eq( 'Technical is a screen in Admin', [ $s[0], $s[2], $s[3] ], [ 'Technical', 'wb_technical', 'admin' ] );
eq( 'System Settings no longer talks about tables', false === stripos( WB_Workspace::screen( 'setup' )[1], 'table' ), true );
eq( 'an owner\'s menu has no Technical', isset( WB_Workspace::menu( fn( $c ) => ! empty( $m['wb_owner']['caps'][ $c ] ) )['Admin']['technical'] ), false );
eq( 'an administrator\'s menu has it', isset( WB_Workspace::menu( fn( $c ) => true )['Admin']['technical'] ), true );
eq( 'the setup screen no longer renders the tables panel', false === strpos( file_get_contents( $base . 'class-wb-setup.php' ), 'WB_Tables::panel()' ), true );

$f = [ 'tables_ok' => true, 'tables_missing' => [], 'pdf' => true, 'enc_key' => true, 'private_ok' => true, 'cron_next' => 1791000000, 'cron_disabled' => true, 'version' => '1.7.4', 'db_version' => '2', 'chain' => 'ok' ];
$rows = WB_Technical::check_rows( $f, 1790990000 );
eq( 'all well: every line is fine', array_unique( array_column( $rows, 1 ) ), [ 'ok' ] );
eq( 'the lines', array_column( $rows, 0 ), [ 'Business tables', 'PDF engine', 'Encryption key', 'Private folder', 'Nightly jobs', 'Audit trail', 'Versions' ] );
$bad = WB_Technical::check_rows( [ 'tables_ok' => false, 'tables_missing' => [ 'wb_datasheets' ], 'enc_key' => false, 'cron_disabled' => false, 'cron_next' => 0, 'chain' => 'broken' ] + $f, 1790990000 );
eq( 'missing tables are named', $bad[0], [ 'Business tables', 'bad', 'Missing: wb_datasheets. Create them below.' ] );
eq( 'no key: payroll stays closed', $bad[2][1], 'warn' );
eq( 'nightly jobs not scheduled', $bad[4][1], 'bad' );
eq( 'a broken chain', $bad[5][1], 'bad' );
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
