<?php
/**
 * Regression tests for 1.7.2: "keep me posted" and the demo password.
 *  1. clean(): an email we can write to, the tick, the hidden field robots fill.
 *  2. The words agreed to name the company and say how to stop.
 *
 *   php tests/regress-optin.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $st, $l = null ) { return null === $l ? substr( (string) $s, $st ) : substr( (string) $s, $st, $l ); } }
function add_action( ...$a ) {} function add_filter( ...$a ) {}
require_once WB_PLUGIN_DIR . 'includes/class-wb-optin.php';
$pass = 0; $fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
$ok = [ 'optin_email' => ' Thandi@Example.CO.ZA ', 'optin_name' => '  Thandi   <b>Mokoena</b> ', 'optin_consent' => '1' ];
eq( 'a good sign-up: the address in lower case, the name tidied', WB_Optin::clean( $ok ), [ 'thandi@example.co.za', 'Thandi Mokoena' ] );
eq( 'a name is optional', WB_Optin::clean( [ 'optin_name' => '' ] + $ok ), [ 'thandi@example.co.za', '' ] );
eq( 'the tick is required', WB_Optin::clean( [ 'optin_consent' => '' ] + $ok ), 'Please tick the box to say you would like the news.' );
eq( 'an address we cannot write to', WB_Optin::clean( [ 'optin_email' => 'thandi@' ] + $ok ), 'Please give an email address we can write to.' );
eq( 'the hidden field filled in: a robot', WB_Optin::clean( [ 'website' => 'http://spam.example' ] + $ok ), 'spam' );
eq( 'an address too long for the column', WB_Optin::clean( [ 'optin_email' => str_repeat( 'a', 190 ) . '@x.com' ] + $ok ), 'Please give an email address we can write to.' );
eq( 'a long name is cut', strlen( WB_Optin::clean( [ 'optin_name' => str_repeat( 'n', 300 ) ] + $ok )[1] ), 120 );
$w = WB_Optin::consent_words( 'Demo Technical Supplies' );
eq( 'the words name the company', false !== strpos( $w, 'from Demo Technical Supplies' ), true );
eq( 'and say what they will get', false !== strpos( $w, 'new releases and special offers' ), true );
eq( 'and how to stop', false !== strpos( $w, 'unsubscribe at any time' ), true );
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
