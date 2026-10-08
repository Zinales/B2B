<?php
/**
 * Regression tests for 0.2.2 (Zina, 5 October 2026):
 *  1. Company-wide documents in the customer portal follow the organisation's setting.
 *  2. The owners are told, once, when someone with finalised payslips can no longer open them.
 *
 *   php tests/regress-portal-leaver.php
 *
 * Pure functions only (no WordPress). Expected values are written out by hand.
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $start, $len = null ) { return null === $len ? substr( (string) $s, $start ) : substr( (string) $s, $start, $len ); }
}
if ( ! function_exists( 'wb_truthy' ) ) {
	// the same rule as wb-core.php (JetEngine switchers store "true"/"false")
	function wb_truthy( $v ): bool {
		if ( is_bool( $v ) ) return $v;
		if ( is_int( $v ) || is_float( $v ) ) return 0 != $v;
		return in_array( strtolower( trim( (string) $v ) ), [ '1', 'true', 'yes', 'on' ], true );
	}
}
date_default_timezone_set( 'UTC' );

$base = dirname( __DIR__ ) . '/includes/';
foreach ( [ 'documents', 'staff', 'payroll', 'setup' ] as $c ) require_once $base . 'class-wb-' . $c . '.php';

$pass = 0;
$fail = 0;
function eq( string $name, $got, $want ): void {
	global $pass, $fail;
	$ok = $got === $want;
	if ( $ok ) { $pass++; return; }
	$fail++;
	echo "FAIL  {$name}\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}
function section( string $t ): void { echo "-- {$t}\n"; }

/* ============================================================ 1. company documents */
section( 'Company-wide documents follow the organisation\'s setting' );
$iso = [ 'type' => 'certificate', 'is_customer_visible' => 'true', 'product_id' => 0, 'customer_id' => 0 ];

eq( 'shown when the organisation shows them', WB_Documents::company_doc_shown( $iso, 'all_customers' ), true );
eq( 'hidden when the organisation hides them', WB_Documents::company_doc_shown( $iso, 'hidden' ), false );
eq( 'hidden for an unknown setting (fails closed)', WB_Documents::company_doc_shown( $iso, '' ), false );
eq( 'switcher "false" stays hidden even when shown', WB_Documents::company_doc_shown( [ 'is_customer_visible' => 'false' ] + $iso, 'all_customers' ), false );
eq( 'switcher "1" counts as on', WB_Documents::company_doc_shown( [ 'is_customer_visible' => '1' ] + $iso, 'all_customers' ), true );
eq( 'not customer-visible stays hidden', WB_Documents::company_doc_shown( [ 'is_customer_visible' => '' ] + $iso, 'all_customers' ), false );
eq( 'a safety data sheet with no product is a company document', WB_Documents::company_doc_shown( [ 'type' => 'msds' ] + $iso, 'all_customers' ), true );
eq( 'a datasheet with no product is a company document', WB_Documents::company_doc_shown( [ 'type' => 'datasheet' ] + $iso, 'all_customers' ), true );
eq( 'an invoice PDF is never a company document', WB_Documents::company_doc_shown( [ 'type' => 'invoice_pdf' ] + $iso, 'all_customers' ), false );
eq( 'a staff document is never a company document', WB_Documents::company_doc_shown( [ 'type' => 'staff_doc' ] + $iso, 'all_customers' ), false );
eq( 'a product document is not company-wide (follows purchases instead)', WB_Documents::company_doc_shown( [ 'product_id' => 12 ] + $iso, 'all_customers' ), false );
eq( 'one customer\'s document is not company-wide', WB_Documents::company_doc_shown( [ 'customer_id' => 4 ] + $iso, 'all_customers' ), false );
eq( 'missing ids count as none', WB_Documents::company_doc_shown( [ 'type' => 'certificate', 'is_customer_visible' => 'yes' ], 'all_customers' ), true );
eq( 'Setup default keeps them hidden', WB_Setup::defaults()['portal_company_docs'], 'hidden' );

/* ============================================================ 2. leaver payslip notice */
section( 'The owners are told once when payslips can no longer be opened' );
// access_action( has finalised payslips, can open the workspace, owners already told )
eq( 'lost access with payslips, not yet told → notify', WB_Payroll::access_action( true, false, false ), 'notify' );
eq( 'already told → nothing more', WB_Payroll::access_action( true, false, true ), 'none' );
eq( 'still has access → nothing', WB_Payroll::access_action( true, true, false ), 'none' );
eq( 'access came back after a notice → clear the flag', WB_Payroll::access_action( true, true, true ), 'clear' );
eq( 'no payslips → nothing to tell', WB_Payroll::access_action( false, false, false ), 'none' );
eq( 'no payslips but an old flag → clear it', WB_Payroll::access_action( false, false, true ), 'clear' );

eq( 'message for a leaver whose access was taken away',
	WB_Payroll::access_message( 'Thabo Mahlangu', true, false ),
	'Thabo Mahlangu has left and can no longer open their payslips, because their login no longer has workspace access. If they ask for one, download it from Payroll and send it to them yourself.' );
eq( 'message for a removed login',
	WB_Payroll::access_message( 'Thabo Mahlangu', true, true ),
	'Thabo Mahlangu has left and can no longer open their payslips, because their login was removed. If they ask for one, download it from Payroll and send it to them yourself.' );
eq( 'message for a current staff member who lost access',
	WB_Payroll::access_message( 'Lerato Mokoena', false, false ),
	'Lerato Mokoena can no longer open their payslips, because their login no longer has workspace access. If they ask for one, download it from Payroll and send it to them yourself.' );
eq( 'message with no name on file',
	WB_Payroll::access_message( '  ', false, true ),
	'A staff member can no longer open their payslips, because their login was removed. If they ask for one, download it from Payroll and send it to them yourself.' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
