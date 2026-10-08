<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Notifications — in-app notifications per recipient (engine table wp_wb_notifications).
 *
 * Groups: stock / money / orders / staff / marketing / integrity.
 * EMAILS ARE NEVER AUTOMATIC. A group emails only when the tenant has switched that specific
 * alert on (option wb_notify_<group>_email, all OFF by default). Customer-facing email is never
 * sent from here at all — quotes, invoices and datasheets go out when a person presses Send.
 *
 * Shortcodes:
 *   [wb_notify_bar]      unread count + the newest few, on every staff dashboard
 *   [wb_notifications]   the full list for the logged-in person, mark read / dismiss
 */
class WB_Notifications {

	const GROUPS = [
		'stock'     => 'Stock',
		'money'     => 'Money',
		'orders'    => 'Orders',
		'staff'     => 'Staff',
		'marketing' => 'Marketing',
		'integrity' => 'Integrity',
	];

	public static function init(): void {
		add_shortcode( 'wb_notify_bar', [ __CLASS__, 'bar' ] );
		add_shortcode( 'wb_notifications', [ __CLASS__, 'panel' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_handle' ] );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wb_notifications';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE " . self::table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recipient_user_id BIGINT UNSIGNED NOT NULL,
			ngroup VARCHAR(20) NOT NULL DEFAULT '',
			message TEXT NOT NULL,
			link VARCHAR(255) NOT NULL DEFAULT '',
			record_type VARCHAR(64) NOT NULL DEFAULT '',
			record_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			read_at DATETIME NULL,
			dismissed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY recipient (recipient_user_id, read_at),
			KEY record (record_type, record_id)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/** Is the tenant's email switch for this group on? Default OFF. */
	public static function email_enabled( string $group ): bool {
		return (bool) get_option( 'wb_notify_' . sanitize_key( $group ) . '_email', 0 );
	}

	/**
	 * One notification to one person. Repeat-safe: an unread notification with the same group,
	 * record and message is not added twice (nightly jobs re-raise the same facts).
	 */
	public static function notify( int $user_id, string $group, string $message, string $link = '', string $record_type = '', int $record_id = 0 ): int {
		if ( $user_id <= 0 || '' === trim( $message ) || ! isset( self::GROUPS[ $group ] ) ) return 0;
		global $wpdb;
		$t   = self::table();
		$dup = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t} WHERE recipient_user_id = %d AND ngroup = %s AND record_type = %s AND record_id = %d AND message = %s AND read_at IS NULL AND dismissed_at IS NULL LIMIT 1",
			$user_id, $group, $record_type, $record_id, $message ) );
		if ( $dup ) return $dup;
		$ok = $wpdb->insert( $t, [
			'recipient_user_id' => $user_id,
			'ngroup'            => $group,
			'message'           => $message,
			'link'              => esc_url_raw( $link ),
			'record_type'       => $record_type,
			'record_id'         => $record_id,
			'created_at'        => current_time( 'mysql' ),
		] );
		if ( false === $ok ) return 0;
		$id = (int) $wpdb->insert_id;
		if ( self::email_enabled( $group ) ) self::email( $user_id, $group, $message, $link );   // only when the tenant switched it on
		return $id;
	}

	/** Fan out to everyone holding $cap. Returns how many were notified. */
	public static function notify_cap( string $cap, string $group, string $message, string $link = '', string $record_type = '', int $record_id = 0, array $except_user_ids = [] ): int {
		$n = 0;
		foreach ( wb_users_with_cap( $cap ) as $u ) {
			if ( in_array( (int) $u->ID, $except_user_ids, true ) ) continue;
			if ( self::notify( (int) $u->ID, $group, $message, $link, $record_type, $record_id ) ) $n++;
		}
		return $n;
	}

	public static function notify_owners( string $group, string $message, string $link = '', string $record_type = '', int $record_id = 0 ): int {
		$n = 0;
		foreach ( WB_Roles::owner_ids() as $uid ) if ( self::notify( $uid, $group, $message, $link, $record_type, $record_id ) ) $n++;
		return $n;
	}

	/** Internal alert email: no personal data in the body, the link is behind the login. */
	private static function email( int $user_id, string $group, string $message, string $link ): void {
		$u = get_userdata( $user_id );
		if ( ! $u || ! is_email( $u->user_email ) ) return;
		$company = (string) ( ( (array) get_option( 'wb_company', [] ) )['name'] ?? get_bloginfo( 'name' ) );
		$body    = $message . ( '' !== $link ? "\n\nOpen: " . $link : '' );
		wp_mail( $u->user_email, sprintf( '[%s] %s alert', $company, self::GROUPS[ $group ] ?? 'System' ), $body );
	}

	/** Mark read when a notification's subject is settled (e.g. a reorder alert resolved). */
	public static function resolve( string $record_type, int $record_id, string $group = '' ): void {
		global $wpdb;
		$sql  = 'UPDATE ' . self::table() . ' SET read_at = %s WHERE record_type = %s AND record_id = %d AND read_at IS NULL';
		$args = [ current_time( 'mysql' ), $record_type, $record_id ];
		if ( '' !== $group ) { $sql .= ' AND ngroup = %s'; $args[] = $group; }
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	public static function unread_count( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE recipient_user_id = %d AND read_at IS NULL AND dismissed_at IS NULL', $user_id ) );
	}

	/* ------------------------------------------------------------------ screens */

	public static function maybe_handle(): void {
		if ( empty( $_POST['wb_notif_action'] ) || ! is_user_logged_in() ) return;
		if ( ! wp_verify_nonce( (string) ( $_POST['_wbn'] ?? '' ), 'wb_notif' ) ) return;
		global $wpdb;
		$uid = get_current_user_id();
		$id  = absint( $_POST['wb_notif_id'] ?? 0 );
		$col = 'dismiss' === $_POST['wb_notif_action'] ? 'dismissed_at' : 'read_at';
		$where = $id ? $wpdb->prepare( 'id = %d AND recipient_user_id = %d', $id, $uid ) : $wpdb->prepare( 'recipient_user_id = %d', $uid );   // own rows only
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET {$col} = %s WHERE {$where} AND {$col} IS NULL", current_time( 'mysql' ) ) );
		wp_safe_redirect( wb_return_url( '/workspace/' ) );
		exit;
	}

	private static function rows( int $user_id, int $limit ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE recipient_user_id = %d AND dismissed_at IS NULL ORDER BY (read_at IS NULL) DESC, id DESC LIMIT %d', $user_id, $limit ), ARRAY_A );
	}

	public static function bar( $atts = [] ): string {
		if ( ! is_user_logged_in() || ! current_user_can( 'wb_access_workspace' ) ) return '';
		$uid = get_current_user_id();
		$n   = self::unread_count( $uid );
		if ( ! $n ) return '';
		$rows = array_slice( array_filter( self::rows( $uid, 5 ), fn( $r ) => empty( $r['read_at'] ) ), 0, 3 );
		$h    = '<div class="wb-notify-bar"><strong>' . esc_html( sprintf( _n( '%d new notification', '%d new notifications', $n, 'wb' ), $n ) ) . '</strong><ul>';
		foreach ( $rows as $r ) {
			$h .= '<li>' . WB_Render::chip( self::GROUPS[ $r['ngroup'] ] ?? '' ) . ' ' . esc_html( (string) $r['message'] )
				. ( '' !== $r['link'] ? ' <a href="' . esc_url( (string) $r['link'] ) . '">Open</a>' : '' ) . '</li>';
		}
		return $h . '</ul><a href="' . esc_url( home_url( '/workspace/notifications/' ) ) . '">See all</a></div>';
	}

	public static function panel( $atts = [] ): string {
		if ( ! is_user_logged_in() ) return '';
		$rows = self::rows( get_current_user_id(), 200 );
		if ( ! $rows ) return wb_notice( 'ok', 'Nothing new. You are up to date.' );
		$nonce = wp_nonce_field( 'wb_notif', '_wbn', true, false ) . wb_return_field();
		$out   = WB_Render::render_table( $rows, [
			[ 'key' => 'message', 'label' => 'Notification' ],
			[ 'key' => 'ngroup', 'label' => 'About', 'type' => 'chip', 'render' => fn( $v ) => WB_Render::chip( self::GROUPS[ $v ] ?? (string) $v ) ],
			[ 'key' => 'created_at', 'label' => 'When' ],
			[ 'key' => 'read_at', 'label' => 'Read', 'render' => fn( $v ) => empty( $v ) ? WB_Render::chip( 'New' ) : '' ],
		], [
			'action_html' => function ( array $r ) use ( $nonce ): string {
				$h = '';
				if ( '' !== (string) $r['link'] ) $h .= WB_RowActions::menuitem( 'open', 'Open', [ 'href' => (string) $r['link'] ] );
				foreach ( [ 'read' => [ 'approve', 'Mark read' ], 'dismiss' => [ 'archive', 'Dismiss' ] ] as $act => $ui ) {
					if ( 'read' === $act && ! empty( $r['read_at'] ) ) continue;
					$h .= '<form method="post" class="wb-menuform">' . $nonce
						. '<input type="hidden" name="wb_notif_action" value="' . esc_attr( $act ) . '"><input type="hidden" name="wb_notif_id" value="' . (int) $r['id'] . '">'
						. WB_RowActions::menuitem( $ui[0], $ui[1], [ 'submit' => true ] ) . '</form>';
				}
				return $h;
			},
		] );
		$all = '<form method="post" class="wb-inline-form">' . $nonce . '<input type="hidden" name="wb_notif_action" value="read"><button type="submit" class="wb-btn wb-btn-ghost">Mark all read</button></form>';
		return $all . $out;
	}
}
