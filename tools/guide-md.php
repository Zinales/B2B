<?php
/**
 * Writes docs/FLOWS.md and docs/HOW-TO.md from WB_Guide (the words on the How-to screen), so the
 * documents can never drift from the program. Run after editing WB_Guide:
 *
 *   php tools/guide-md.php          write the files
 *   php tools/guide-md.php --check  exit 1 if the files on disk differ (the build gate)
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/plugin-source/wb-core/' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function add_shortcode( ...$a ) {}
foreach ( [ 'workspace', 'render', 'guide' ] as $c ) require_once WB_PLUGIN_DIR . 'includes/class-wb-' . $c . '.php';

$docs  = dirname( __DIR__ ) . '/docs/';
$want  = [ 'FLOWS.md' => WB_Guide::flows_markdown(), 'HOW-TO.md' => WB_Guide::howto_markdown() ];
$check = in_array( '--check', $argv, true );
$bad   = 0;
foreach ( $want as $f => $text ) {
	$have = is_file( $docs . $f ) ? file_get_contents( $docs . $f ) : null;
	if ( $check ) {
		if ( $have !== $text ) { echo "docs/{$f} does not match WB_Guide; run php tools/guide-md.php\n"; $bad++; }
	} elseif ( $have !== $text ) {
		file_put_contents( $docs . $f, $text );
		echo "wrote docs/{$f}\n";
	} else echo "docs/{$f} unchanged\n";
}
exit( $bad ? 1 : 0 );
