<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Optin — "keep me posted" (1.7.2, Zina: "no, I don't want to capture details to test the demo,
 * but we could add an opt-in if anyone wants to be updated of releases and special offers").
 *
 * Nobody has to give anything to try the demo. Anyone who wants news can leave an email (a name is
 * optional) and tick the box; the tick is required, and the exact words they agreed to, the time and
 * the page are kept with their address, as POPIA asks. Each address has its own unsubscribe link,
 * which works without signing in. Nobody is ever emailed from here: the owner downloads the list
 * (with each person's unsubscribe link) for the mailing tool they already use. An unsubscribed
 * address stays on file, marked off, so it is never added back by an import by mistake.
 *
 * Spam: a hidden field people never fill, and at most five sign-ups an hour from one address
 * (the address is only ever kept as a hash, for that hour). Plugin-owned table wp_wb_optins.
 * consent_words() and clean() are pure.
 */
class WB_Optin {

	const OPT_ON = 'wb_optin_on';

	public static function table(): string { global $wpdb; return $wpdb->prefix . 'wb_optins'; }

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE " . self::table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			name VARCHAR(120) NOT NULL DEFAULT '',
			consent_text TEXT NOT NULL,
			consented_at DATETIME NOT NULL,
			source VARCHAR(40) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'subscribed',
			unsubscribed_at DATETIME NULL,
			token CHAR(40) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			UNIQUE KEY token (token)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'maybe_handle' ], 1 );
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			$h['optin_setting'] = [ __CLASS__, 'handle_setting' ];
			$h['optin_remove']  = [ __CLASS__, 'handle_remove' ];
			return $h;
		} );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_export' ], 2 );
	}

	/* ------------------------------------------------------------------ pure */

	/** The words a person agrees to, exactly as kept with their address. Pure. */
	public static function consent_words( string $company ): string {
		return 'Send me occasional news of new releases and special offers from ' . $company . '. I can unsubscribe at any time with the link in every message.';
	}

	/** A sign-up as posted → [ email, name ] or the words to show. Pure. */
	public static function clean( array $post ) {
		if ( '' !== trim( (string) ( $post['website'] ?? '' ) ) ) return 'spam';   // the field people never see
		$email = strtolower( trim( (string) ( $post['optin_email'] ?? '' ) ) );
		if ( ! preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $email ) || strlen( $email ) > 190 ) return 'Please give an email address we can write to.';
		if ( empty( $post['optin_consent'] ) ) return 'Please tick the box to say you would like the news.';
		$name = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) ( $post['optin_name'] ?? '' ) ) ) );
		return [ $email, mb_substr( $name, 0, 120 ) ];
	}

	/* ------------------------------------------------------------------ the form */

	public static function on(): bool {
		return 'no' !== (string) get_option( self::OPT_ON, 'yes' );
	}

	/** The small form, for the front page and the sign-in page. $source says which. */
	public static function form( string $source ): string {
		if ( ! self::on() || is_user_logged_in() ) return '';
		$state = sanitize_key( (string) ( $_GET['wbo'] ?? '' ) );
		$words = [ 'thanks' => 'Thank you. We will keep you posted, and every message has a link to stop.', 'out' => 'You are off the list. You will not hear from us again unless you sign up.', 'err' => 'That did not go through. Please check the email address and the tick box.' ];
		$h = '<section class="wb-optin" aria-labelledby="wb-optin-h-' . esc_attr( $source ) . '"><h2 id="wb-optin-h-' . esc_attr( $source ) . '">Keep me posted</h2>'
			. '<p class="wb-muted">New releases and the occasional special offer. Not needed to try the demo.</p>'
			. ( isset( $words[ $state ] ) ? '<p class="wb-optin-said' . ( 'err' === $state ? ' is-err' : '' ) . '" role="status">' . esc_html( $words[ $state ] ) . '</p>' : '' );
		if ( 'thanks' === $state ) return $h . '</section>';
		$h .= '<form method="post" class="wb-optin-form">' . wp_nonce_field( 'wb_optin', '_wbo', true, false ) . '<input type="hidden" name="wb_optin" value="' . esc_attr( $source ) . '">'
			. '<label class="wb-field" for="wb-optin-email-' . esc_attr( $source ) . '"><span>Email</span><input id="wb-optin-email-' . esc_attr( $source ) . '" type="email" name="optin_email" required autocomplete="email"></label>'
			. '<label class="wb-field" for="wb-optin-name-' . esc_attr( $source ) . '"><span>Name (optional)</span><input id="wb-optin-name-' . esc_attr( $source ) . '" type="text" name="optin_name" autocomplete="name"></label>'
			. '<label class="wb-optin-hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>'
			. '<label class="wb-check-row wb-optin-consent"><input type="checkbox" name="optin_consent" value="1" required> ' . esc_html( self::consent_words( WB_Setup::display_name() ) ) . '</label>'
			. '<button type="submit" class="wb-btn">Keep me posted</button></form>';
		return $h . '</section>';
	}

	/* ------------------------------------------------------------------ signing up and off */

	public static function maybe_handle(): void {
		if ( isset( $_GET['wb_optout'] ) ) { self::optout( (string) $_GET['wb_optout'] ); return; }
		if ( empty( $_POST['wb_optin'] ) || ! self::on() ) return;
		$back   = wp_get_referer() ?: home_url( '/' );
		$source = sanitize_key( (string) $_POST['wb_optin'] );
		$go     = function ( string $state ) use ( $back ) { wp_safe_redirect( add_query_arg( 'wbo', $state, remove_query_arg( 'wbo', $back ) ) . '#wb-optin-h-' . sanitize_key( (string) $_POST['wb_optin'] ) ); exit; };
		if ( ! wp_verify_nonce( (string) ( $_POST['_wbo'] ?? '' ), 'wb_optin' ) ) $go( 'err' );
		$c = self::clean( wp_unslash( $_POST ) );
		if ( 'spam' === $c ) $go( 'thanks' );   // say nothing useful to a robot
		if ( ! is_array( $c ) ) $go( 'err' );
		$key = 'wb_optin_ip_' . hash( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() );
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) $go( 'thanks' );
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		[ $email, $name ] = $c;
		global $wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', $email ), ARRAY_A );
		$data = [ 'name' => $name ?: (string) ( $row['name'] ?? '' ), 'consent_text' => self::consent_words( WB_Setup::display_name() ), 'consented_at' => current_time( 'mysql' ), 'source' => $source, 'status' => 'subscribed', 'unsubscribed_at' => null ];
		if ( $row ) $wpdb->update( self::table(), $data, [ 'id' => (int) $row['id'] ] );
		else $wpdb->insert( self::table(), $data + [ 'email' => $email, 'token' => strtolower( wp_generate_password( 40, false, false ) ) ] );
		wb_ledger_write( 'optin_signed_up', 'wb_optins', (int) ( $row['id'] ?? $wpdb->insert_id ), null, [ 'source' => $source ] );   // the address itself stays out of the trail
		$go( 'thanks' );
	}

	private static function optout( string $token ): void {
		$token = preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) );
		global $wpdb;
		if ( 40 === strlen( $token ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE token = %s', $token ) );
			if ( $id ) {
				$wpdb->update( self::table(), [ 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
				wb_ledger_write( 'optin_unsubscribed', 'wb_optins', $id, null, [ 'by' => 'link' ] );
			}
		}
		wp_safe_redirect( add_query_arg( 'wbo', 'out', home_url( '/' ) ) . '#wb-optin-h-welcome' );   // the same answer whether or not the link was known
		exit;
	}

	public static function unsubscribe_url( string $token ): string {
		return add_query_arg( 'wb_optout', $token, home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ the owner's list */

	public static function rows( int $limit = 200 ): array {
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) !== self::table() ) return [];
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}

	public static function settings_fold(): string {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return '';
		$rows = self::rows();
		$subs = count( array_filter( $rows, fn( $r ) => 'subscribed' === $r['status'] ) );
		$f = WB_Render::form_open( 'optin_setting' ) . WB_Render::field( 'optin_on', '"Keep me posted" on the front page and the sign-in page', 'select', self::on() ? 'yes' : 'no', [ 'options' => [ 'yes' => 'Shown', 'no' => 'Hidden' ], 'id' => 'wb-optin-on' ] ) . WB_Render::form_close( 'Save' );
		$list = WB_Render::render_table( $rows, [ 'email', 'name', [ 'key' => 'consented_at', 'label' => 'Agreed on' ], 'source', 'status' ], [
			'action_html' => fn( $r ) => 'subscribed' === $r['status'] ? WB_Render::form_open( 'optin_remove' ) . '<input type="hidden" name="optin_id" value="' . (int) $r['id'] . '">' . WB_RowActions::menuitem( 'withdraw', 'Take off the list', [ 'submit' => true ] ) . '</form>' : '',
			'empty' => 'Nobody has signed up yet.' ] );
		$csv = $rows ? '<p><a class="wb-btn wb-btn-ghost" href="' . esc_url( add_query_arg( [ 'wb_optin_csv' => 1, '_wpnonce' => wp_create_nonce( 'wb_optin_csv' ) ] ) ) . '">Download the list (CSV, with each person\'s unsubscribe link)</a></p>' : '';
		return WB_Render::fold( 'People who asked for news', '<p class="wb-muted">Nothing is emailed from here. Download the list for the mailing tool you use, and put each person\'s unsubscribe link in what you send. Someone who unsubscribes stays on file, marked off, so they are never added back by mistake.</p>' . $f . $csv . $list,
			[ 'id' => 'wb-optins', 'hint' => $subs . ' signed up' ] );
	}

	public static function handle_setting() {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return new WP_Error( 'wb_forbidden', 'Only the owner can change this.' );
		update_option( self::OPT_ON, 'no' === ( $_POST['optin_on'] ?? 'yes' ) ? 'no' : 'yes', false );
		return [ 'msg' => self::on() ? '"Keep me posted" is shown.' : '"Keep me posted" is hidden.' ];
	}

	public static function handle_remove() {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return new WP_Error( 'wb_forbidden', 'Only the owner can change this.' );
		global $wpdb;
		$id = absint( $_POST['optin_id'] ?? 0 );
		$wpdb->update( self::table(), [ 'status' => 'unsubscribed', 'unsubscribed_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
		wb_ledger_write( 'optin_unsubscribed', 'wb_optins', $id, null, [ 'by' => get_current_user_id() ] );
		return [ 'msg' => 'Taken off the list.' ];
	}

	public static function maybe_export(): void {
		if ( empty( $_GET['wb_optin_csv'] ) || ! current_user_can( 'wb_manage_settings' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'wb_optin_csv' ) ) return;
		if ( class_exists( 'WB_Demo' ) && WB_Demo::is_demo_user( get_current_user_id() ) ) return;
		$rows = array_filter( self::rows( 100000 ), fn( $r ) => 'subscribed' === $r['status'] );
		$out  = [ [ 'email', 'name', 'agreed_on', 'agreed_to', 'source', 'unsubscribe_link' ] ];
		foreach ( $rows as $r ) $out[] = [ $r['email'], $r['name'], $r['consented_at'], $r['consent_text'], $r['source'], self::unsubscribe_url( (string) $r['token'] ) ];
		wb_ledger_write( 'optin_list_downloaded', 'wb_optins', 0, null, [ 'rows' => count( $rows ), 'by' => get_current_user_id() ] );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="news-list-' . wb_today() . '.csv"' );
		$fh = fopen( 'php://output', 'w' );
		foreach ( $out as $line ) fputcsv( $fh, array_map( fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : (string) $v, $line ), ',', '"', '' );
		fclose( $fh );
		exit;
	}
}
