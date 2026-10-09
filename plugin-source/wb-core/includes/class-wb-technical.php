<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Technical — the Technical screen, for IT (1.7.4, Zina: "the administrators don't need to see
 * the business tables; that is a back-end data function; if we expose it, it is an IT function").
 *
 * Everything a business owner never needs to look at, in one place for the person who installs and
 * looks after the site: the JetEngine business tables (create the missing ones), and the checks that
 * the system has what it needs: the PDF engine, the encryption key, the private folder, email, the
 * nightly jobs, the versions, and the audit trail. Opened with wb_technical, which WordPress
 * administrators hold and an owner does not (it can be ticked for someone under "Who can do what").
 *
 * check_rows() is pure: it turns the facts into the words and the state of each line.
 */
class WB_Technical {

	public static function init(): void {
		add_shortcode( 'wb_technical', [ __CLASS__, 'render' ] );
		add_filter( 'wb_panel_handlers', function ( array $h ): array { $h['technical_mail_test'] = [ __CLASS__, 'handle_mail_test' ]; return $h; } );
	}

	/**
	 * Facts → [ [ what, state ok|warn|bad, words ], … ]. Pure.
	 * $f: tables_ok, tables_missing (list), pdf, enc_key, private_ok, cron_next (timestamp|0), cron_disabled, version, db_version, chain (ok|broken|unknown)
	 */
	public static function check_rows( array $f, int $now ): array {
		$r   = [];
		$r[] = [ 'Business tables', $f['tables_ok'] ? 'ok' : 'bad', $f['tables_ok'] ? 'All the JetEngine tables are in place.' : 'Missing: ' . implode( ', ', (array) $f['tables_missing'] ) . '. Create them below.' ];
		$r[] = [ 'PDF engine', $f['pdf'] ? 'ok' : 'bad', $f['pdf'] ? 'Quotes, invoices, statements and datasheets can be made as PDF.' : 'The bundled PDF engine is missing from the plugin folder. Reinstall the plugin.' ];
		$r[] = [ 'Encryption key', $f['enc_key'] ? 'ok' : 'warn', $f['enc_key'] ? 'WB_ENCRYPTION_KEY is set in wp-config.php: tax numbers and bank accounts are stored encrypted.' : 'WB_ENCRYPTION_KEY is not set in wp-config.php. Payroll stays closed until it is.' ];
		$r[] = [ 'Private folder', $f['private_ok'] ? 'ok' : 'bad', $f['private_ok'] ? 'Documents and signatures are stored outside the web folder and can be written.' : 'The private folder cannot be written. Check the folder permissions.' ];
		if ( $f['cron_disabled'] ) $r[] = [ 'Nightly jobs', 'ok', 'WordPress\'s own timer is off, so a server timer must call wp-cron.php. Next run: ' . ( $f['cron_next'] ? gmdate( 'j M Y H:i', $f['cron_next'] ) . ' UTC' : 'not scheduled' ) . '.' ];
		else $r[] = [ 'Nightly jobs', $f['cron_next'] && $f['cron_next'] > $now - 3 * 3600 ? 'warn' : 'bad', ( $f['cron_next'] ? 'Next run ' . gmdate( 'j M Y H:i', $f['cron_next'] ) . ' UTC' : 'Not scheduled' ) . '. They run only when someone visits the site; a server timer calling wp-cron.php is more reliable.' ];
		$r[] = [ 'Audit trail', 'ok' === $f['chain'] ? 'ok' : ( 'broken' === $f['chain'] ? 'bad' : 'warn' ), 'ok' === $f['chain'] ? 'The chain checked out last night.' : ( 'broken' === $f['chain'] ? 'The chain has a break. Check it now below, and see the Integrity report.' : 'Not checked yet. Check it now below.' ) ];
		$r[] = [ 'Versions', 'ok', 'Plugin ' . $f['version'] . ', database ' . $f['db_version'] . '.' ];
		return $r;
	}

	private static function facts(): array {
		$s = WB_Tables::status();
		$chain = (array) get_option( 'wb_ledger_last_check', [] );
		return [
			'tables_ok' => WB_Tables::all_present(), 'tables_missing' => array_merge( $s['missing'], array_map( fn( $t, $cols ) => $t . ' (' . implode( ', ', $cols ) . ')', array_keys( $s['short'] ), $s['short'] ) ),
			'pdf' => WB_Pdf::available(), 'enc_key' => defined( 'WB_ENCRYPTION_KEY' ) && '' !== (string) WB_ENCRYPTION_KEY,
			'private_ok' => is_dir( WB_Storage::base_dir() ) && wp_is_writable( WB_Storage::base_dir() ),
			'cron_next' => (int) wp_next_scheduled( WB_Cron::HOOK ), 'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'version' => WB_VERSION, 'db_version' => (string) get_option( 'wb_db_version', '' ),
			'chain' => isset( $chain['ok'] ) ? ( $chain['ok'] ? 'ok' : 'broken' ) : 'unknown',
		];
	}

	public static function render(): string {
		if ( ! current_user_can( 'wb_technical' ) ) return wb_notice( 'warn', 'This screen is for the person who looks after the site.' );
		$h = WB_RowActions::notice() . '<p class="wb-muted">For IT. Nothing here changes how the business works; it is what the system needs underneath.</p><ul class="wb-checks">';
		foreach ( self::check_rows( self::facts(), time() ) as [ $what, $state, $words ] ) {
			$h .= '<li class="is-' . esc_attr( $state ) . '"><span class="wb-check-dot" aria-hidden="true"></span><strong>' . esc_html( $what ) . '</strong><span>' . esc_html( $words ) . '</span><span class="wb-sr"> (' . esc_html( [ 'ok' => 'fine', 'warn' => 'worth a look', 'bad' => 'needs fixing' ][ $state ] ) . ')</span></li>';
		}
		$h .= '</ul>';
		$h .= WB_Render::fold( 'Business tables', WB_Tables::panel(), [ 'open' => ! WB_Tables::all_present(), 'id' => 'wb-tech-tables', 'kind' => 'lead' ] );
		$h .= WB_Render::fold( 'Email', '<p class="wb-muted">Quotes, invoices and statements go out through the site\'s email. If this test does not arrive, install and set up an SMTP plugin.</p>' . WB_Render::form_open( 'technical_mail_test' ) . WB_Render::form_close( 'Send me a test email' ) );
		$h .= WB_Render::fold( 'Audit trail', WB_Render::form_open( 'ledger_verify' ) . WB_Render::form_close( 'Check the audit trail now' ) );
		return $h;
	}

	public static function handle_mail_test() {
		if ( ! current_user_can( 'wb_technical' ) ) return new WP_Error( 'wb_forbidden', 'This is for the person who looks after the site.' );
		$u = wp_get_current_user();
		if ( empty( $u->user_email ) ) return new WP_Error( 'wb_email', 'Your login has no email address.' );
		$ok = wp_mail( $u->user_email, WB_Setup::display_name() . ': test email', "This is a test from the Technical screen.\n\nIf you are reading it, the site can send email." );
		return $ok ? [ 'msg' => 'Sent to ' . $u->user_email . '. If it does not arrive in a few minutes, check the spam folder, then the SMTP settings.' ] : new WP_Error( 'wb_mail', 'The site could not hand the email to a mail server. Set up an SMTP plugin.' );
	}
}
