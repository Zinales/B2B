<?php
/**
 * Plugin Name: B2B Wholesale System — Core
 * Description: The engine for a B2B wholesale business: hash-chained audit ledger, gapless document numbering, roles by dashboard, the two pricing checks, stock as a ledger, the quote → order → invoice → payment → delivery state machine, bank-statement matching, demand and cashflow forecasting, staff time/leave/KPIs, private document storage, the Setup screen (brand + first-run checklist), bank CSV mapping, the customer portal and South African payroll. Serves its own screens at /workspace/ and /portal/ (no pages to create). Business data lives in JetEngine CCTs (wp_jet_cct_wb_*); engine records in plugin tables (wp_wb_*).
 * Version: 0.3.3
 * Author: GroB2B
 * Requires PHP: 8.0
 * Requires at least: 6.4
 * License: Proprietary
 * Text Domain: wb
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WB_VERSION', '0.3.3' );
define( 'WB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WB_PLUGIN_FILE', __FILE__ );

require_once WB_PLUGIN_DIR . 'includes/class-wb-ledger.php';        // the hash-chained audit trail (rule 2)
require_once WB_PLUGIN_DIR . 'includes/class-wb-sequences.php';     // gapless numbering: QUO ORD INV CRN DN PO STM
require_once WB_PLUGIN_DIR . 'includes/class-wb-roles.php';         // capabilities by dashboard + roles
require_once WB_PLUGIN_DIR . 'includes/class-wb-cct.php';           // column-safe, fail-closed, ledgered CCT access
require_once WB_PLUGIN_DIR . 'includes/class-wb-notifications.php'; // in-app notifications; email only when switched on
require_once WB_PLUGIN_DIR . 'includes/class-wb-storage.php';       // wp-content/wb-private
require_once WB_PLUGIN_DIR . 'includes/class-wb-documents.php';     // the document register + datasheets on demand
require_once WB_PLUGIN_DIR . 'includes/class-wb-pricing.php';       // the two pricing checks (§6)
require_once WB_PLUGIN_DIR . 'includes/class-wb-stock.php';         // stock is a ledger (rule 7)
require_once WB_PLUGIN_DIR . 'includes/class-wb-invoices.php';      // immutable invoices + credit notes
require_once WB_PLUGIN_DIR . 'includes/class-wb-orders.php';        // quotes + the order state machine (§7)
require_once WB_PLUGIN_DIR . 'includes/class-wb-payments.php';      // bank import + matching
require_once WB_PLUGIN_DIR . 'includes/class-wb-demand.php';        // demand, seasonality, journey stage, 13-week cashflow
require_once WB_PLUGIN_DIR . 'includes/class-wb-staff.php';         // timesheets, leave, KPIs, reviews
require_once WB_PLUGIN_DIR . 'includes/class-wb-payroll.php';       // 0.2.0 SA payroll: PAYE, UIF, SDL, payslips, EMP201 figures
require_once WB_PLUGIN_DIR . 'includes/class-wb-setup.php';         // 0.2.0 the Setup screen: brand, colours (contrast-checked), checklist
require_once WB_PLUGIN_DIR . 'includes/class-wb-portal.php';        // 0.2.0 the customer portal
require_once WB_PLUGIN_DIR . 'includes/class-wb-integrity.php';     // the monthly Integrity report (§8)
require_once WB_PLUGIN_DIR . 'includes/class-wb-render.php';        // tables, chips, notices (primitives, never hand markup)
require_once WB_PLUGIN_DIR . 'includes/class-wb-rowactions.php';    // the ⋯ menu actions registry
require_once WB_PLUGIN_DIR . 'includes/class-wb-screens.php';       // dashboard shortcodes + panel POST handlers
require_once WB_PLUGIN_DIR . 'includes/class-wb-rest.php';          // wb/v1
require_once WB_PLUGIN_DIR . 'includes/class-wb-cron.php';          // nightly + monthly jobs
require_once WB_PLUGIN_DIR . 'includes/class-wb-demo.php';          // demo seed / wipe (admins only)
require_once WB_PLUGIN_DIR . 'includes/class-wb-tables.php';        // 0.3.0 creates the JetEngine business tables from the schema
require_once WB_PLUGIN_DIR . 'includes/class-wb-workspace.php';     // 0.3.0 /workspace/ and /portal/ served by the plugin, with its own frame
require_once WB_PLUGIN_DIR . 'includes/class-wb-welcome.php';      // 0.3.1 the front page: what the system does, and the way in

register_activation_hook( __FILE__, 'wb_activate' );
function wb_activate(): void {
	WB_Ledger::create_table();
	WB_Sequences::create_table();
	WB_Notifications::create_table();
	WB_Pricing::create_table();
	WB_Stock::create_tables();
	WB_Payments::create_table();
	WB_Demand::create_tables();
	WB_Roles::sync();
	WB_Storage::ensure_dir();
	wb_default_options();
	WB_Cron::schedule();
	$old_db = (string) get_option( 'wb_db_version', '' );
	if ( '' !== $old_db && version_compare( $old_db, '2', '<' ) ) WB_Ledger::seed_anchor();   // 0.2.1: anchor the existing chain once (S3)
	update_option( 'wb_version', WB_VERSION );
	update_option( 'wb_db_version', WB_Ledger::DB_VERSION );
}

register_deactivation_hook( __FILE__, function () {
	WB_Cron::unschedule();
	// Deliberately NO data removal — nothing is hard-deleted, the plugin included.
} );

/**
 * Re-run the cheap, idempotent setup when the code version changes (an update does not fire the
 * activation hook). Roles are re-synced so a capability change in code reaches every site.
 */
add_action( 'plugins_loaded', function () {
	if ( WB_VERSION !== (string) get_option( 'wb_version', '' ) || WB_Ledger::DB_VERSION !== (string) get_option( 'wb_db_version', '' ) ) {
		wb_activate();   // a table change (e.g. the ledger's hash_version column) also re-runs the idempotent setup
	}
	WB_Roles::init();
	WB_Ledger::init();
	WB_Notifications::init();
	WB_Storage::init();
	WB_Stock::init();
	WB_Orders::init();
	WB_Payments::init();
	WB_Integrity::init();
	WB_Render::init();
	WB_RowActions::init();
	WB_Screens::init();
	WB_Setup::init();
	WB_Portal::init();
	WB_Payroll::init();
	WB_Rest::init();
	WB_Cron::init();
	WB_Demo::init();
	WB_Tables::init();
	WB_Workspace::init();
}, 5 );

/**
 * Configuration lives in wp_options (rule 1). add_option never overwrites, so a tenant's own
 * settings survive every update. Notification email switches are all OFF by default.
 */
function wb_default_options(): void {
	$defaults = [
		'wb_company'            => [ 'name' => '', 'reg_number' => '', 'vat_number' => '', 'address' => '', 'logo_key' => '' ],
		'wb_currency'           => 'ZAR',
		'wb_vat_rate'           => 15,
		'wb_invoice_trigger'    => 'acceptance',   // acceptance | dispatch
		'wb_default_terms_days' => 30,
		'wb_pricing'            => [ 'default_min_margin_pct' => 20, 'quote_validity_days' => 30 ],
		'wb_stock'              => [ 'default_reorder_lead_days' => 14, 'stocktake_cadence' => 'monthly', 'adjustment_threshold' => 0 ],
		'wb_number_prefixes'    => WB_Sequences::DEFAULT_PREFIXES,
		'wb_bank_mapping'       => WB_Payments::default_mappings(),
		'wb_notify_stock_email'     => 0,
		'wb_notify_money_email'     => 0,
		'wb_notify_orders_email'    => 0,
		'wb_notify_staff_email'     => 0,
		'wb_notify_marketing_email' => 0,
		'wb_notify_integrity_email' => 0,
		'wb_journey_rules'      => [ 'at_risk_days' => 60, 'lapsed_days' => 180, 'at_risk_interval_multiple' => 2 ],
		'wb_leave_defaults'     => WB_Staff::DEFAULT_LEAVE,
		'wb_review_cycle'       => 'annual',
		'wb_brand_colors'       => [ 'ink' => '#0B1F3A', 'accent' => '#8A3B52', 'canvas' => '#F7F3EE' ],   // 0.1.0 option, kept in step with wb_brand
		'wb_brand'              => WB_Setup::defaults(),                // 0.2.0 identity + look
		'wb_payroll'            => WB_Payroll::DEFAULT_SETTINGS,        // 0.2.0 sdl_registered, overtime rate and weekly limit
		'wb_tax_years'          => WB_Payroll::DEFAULT_TAX_YEARS,       // 0.2.0 one row per SARS tax year (PAYROLL-RULES-2027.md)
		'wb_cashflow'           => [ 'payroll_monthly' => 0, 'opening_balance' => 0 ],
		'wb_tenant_id'          => '',
		'wb_mothership_url'     => '',
	];
	foreach ( $defaults as $k => $v ) add_option( $k, $v );
}

/** The core version, for module plugins that guard on it. */
function wb_core(): string {
	return WB_VERSION;
}

/** A notice box. Never hand-write the markup (primitives, not markup). */
function wb_notice( string $kind, string $msg ): string {
	$k = in_array( $kind, [ 'ok', 'err', 'warn' ], true ) ? $kind : 'ok';
	return '<div class="wb-notice wb-' . $k . '" role="status">' . wp_kses_post( $msg ) . '</div>';
}

/** Every panel form carries this, so every POST comes back to where you were. */
function wb_return_field(): string {
	$uri = esc_attr( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
	return '<input type="hidden" name="_wb_return" value="' . $uri . '">';
}

/**
 * Where a handler redirects: the posted return address, else $fallback (a screen slug for
 * WB_Workspace::url(), default Today). One-shot message parameters (wbmsg, wbra) are stripped so a
 * notice shows once; a new message parameter must be added to that list or it re-fires on every
 * reload. View-state parameters (?month=, ?quote=) are never stripped.
 */
function wb_return_url( string $fallback = 'home' ): string {
	$ret = isset( $_POST['_wb_return'] ) ? wp_validate_redirect( wp_unslash( (string) $_POST['_wb_return'] ), '' ) : '';
	if ( '' === $ret ) $ret = WB_Workspace::url( $fallback );
	return remove_query_arg( [ 'wbmsg', 'wbra' ], $ret );
}

/**
 * Users holding a capability (cap-aware: dashboard ticks are granted per user, not per role).
 * Any role counts (0.2.1, S11): a tick given to someone whose role is not one of ours, e.g. an
 * editor, still reaches them. get_users( capability ) finds both role-held and per-user grants;
 * administrators and our roles are added as before, and has_cap() has the last word.
 */
function wb_users_with_cap( string $cap, int $limit = 500 ): array {
	$found = [];
	foreach ( [
		[ 'number' => $limit, 'capability' => $cap ],
		[ 'number' => $limit, 'role__in' => array_merge( [ 'administrator' ], array_keys( WB_Roles::map() ) ) ],
	] as $q ) {
		foreach ( (array) get_users( $q ) as $u ) $found[ (int) $u->ID ] = $u;
	}
	return array_slice( array_values( array_filter( $found, fn( $u ) => $u->has_cap( $cap ) ) ), 0, $limit );
}

/** "Now" in the site's timezone, as stored in CCT date columns. */
/**
 * A yes/no value as JetEngine may store it: 1/0, "1"/"0", or a switcher's "true"/"false".
 * Use this for every flag read from a CCT row; never empty() or == 1 on its own.
 */
function wb_truthy( $v ): bool {
	if ( is_bool( $v ) ) return $v;
	if ( is_int( $v ) || is_float( $v ) ) return 0 != $v;
	return in_array( strtolower( trim( (string) $v ) ), [ '1', 'true', 'yes', 'on' ], true );
}

function wb_now(): string {
	return current_time( 'mysql' );
}

function wb_today(): string {
	return current_time( 'Y-m-d' );
}

/** Money to 2 decimals, half away from zero. One rounding rule everywhere. */
function wb_money( $v ): float {
	return round( (float) $v, 2, PHP_ROUND_HALF_UP );
}

/* ---------- Secrets encrypted with the per-tenant key (rule 3) ---------- */

function wb_enc_key(): string {
	return defined( 'WB_ENCRYPTION_KEY' ) ? hash( 'sha256', (string) WB_ENCRYPTION_KEY, true ) : '';
}

/** AES-256-GCM. Returns '' (never plain text) when there is no key: fail closed. */
function wb_enc( string $plain ): string {
	$key = wb_enc_key();
	if ( '' === $key || '' === $plain ) return '';
	$iv  = random_bytes( 12 );
	$tag = '';
	$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
	return false === $ct ? '' : 'v1:' . base64_encode( $iv . $tag . $ct );
}

function wb_dec( string $stored ): string {
	$key = wb_enc_key();
	if ( '' === $key || 0 !== strpos( $stored, 'v1:' ) ) return '';
	$raw = base64_decode( substr( $stored, 3 ), true );
	if ( false === $raw || strlen( $raw ) < 29 ) return '';
	$pt = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
	return false === $pt ? '' : $pt;
}
