<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Workspace — the plugin serves its own screens (0.3.0). No WordPress pages to create.
 *
 *   /workspace/            Today (home)
 *   /workspace/<screen>/   every staff screen in SCREENS
 *   /portal/               the customer portal
 *   /                      the welcome page (WB_Welcome), while Setup's "welcome page as the site home" is on
 *
 * - ONE GATE: each screen names the capability that opens it; the menu shows only the screens the
 *   person can open, and the screen's own shortcode checks again (the engines check a third time).
 *   Not signed in → the login page, then straight back. Signed in without the capability → a polite
 *   refusal inside the frame (403). Nothing here replaces a check in an engine.
 * - OWN LOOK: the frame (side menu, top bar, page header) is the plugin's, styled by
 *   assets/wb-workspace.css from the --wb-* brand tokens WB_Setup prints. The theme's stylesheets
 *   are left off these routes, so any theme (GeneratePress included) cannot change the screens.
 * - The rules are added at the top, so they win over a WordPress page with the same address.
 *   They are flushed once per plugin version, on wp_loaded (option wb_rewrite_version).
 * - The [wb_*] shortcodes still work on ordinary pages for anyone who prefers them.
 *
 * Routing (screen, menu, next_links) is PURE — tested without WordPress.
 */
class WB_Workspace {

	const QV = 'wb_screen';

	/** Menu groups, in order. '' is the top group (no heading). */
	const GROUPS = [ '' => '', 'sell' => 'Sell', 'stock' => 'Stock', 'know' => 'Know', 'team' => 'Team', 'admin' => 'Admin' ];

	/**
	 * Every screen: slug => [ title, sub, cap (opens it), group, body (shortcodes), next [ slug => words ] ].
	 * The words match the 0.2 dashboards/*.html pages, which stay in the plugin as the shortcode reference.
	 */
	const SCREENS = [
		'home'          => [ 'Today', 'What needs you, in one place.', 'wb_access_workspace', '', '[wb_notify_bar][wb_home]', [ 'quotes' => 'Write a quote', 'payments' => 'Match payments' ] ],
		'notifications' => [ 'Notifications', 'Everything the system has told you, newest first.', 'wb_access_workspace', '', '[wb_notifications]', [ 'home' => 'Today' ] ],
		'customers'     => [ 'Customers', 'Accounts, terms and credit limits. Archive, never delete.', 'wb_view_customers', 'sell', '[wb_notify_bar][wb_customers]', [ 'quotes' => 'Quote a customer', 'marketing' => 'Who is due to order' ] ],
		'quotes'        => [ 'Quotes', 'Check one finds the customer\'s price; check two makes sure the product allows it.', 'wb_create_quotes', 'sell', '[wb_notify_bar][wb_quotes]', [ 'orders' => 'Orders from accepted quotes' ] ],
		'orders'        => [ 'Orders', 'Built from the accepted quote. Goods leave only when the release check says so.', 'wb_manage_orders', 'sell', '[wb_notify_bar][wb_orders]', [ 'deliveries' => 'Ready to go out', 'invoices' => 'Invoices' ] ],
		'invoices'      => [ 'Invoices', 'An issued invoice is never edited. A credit note needs a second person to approve it.', 'wb_issue_invoices', 'sell', '[wb_notify_bar][wb_invoices]', [ 'payments' => 'Match payments' ] ],
		'payments'      => [ 'Payments', 'Import the bank statement. Exact references match themselves; everything else waits for a person.', 'wb_match_payments', 'sell', '[wb_notify_bar][wb_payments]', [ 'invoices' => 'Invoices', 'cashflow' => 'Cashflow' ] ],
		'deliveries'    => [ 'Deliveries', 'A note can only be issued against a released order. Issuing it takes the stock off the books.', 'wb_issue_delivery_notes', 'sell', '[wb_notify_bar][wb_deliveries]', [ 'orders' => 'Orders', 'stock' => 'Stock' ] ],
		'products'      => [ 'Products', 'List price, cost, lowest margin, and each customer\'s own prices.', 'wb_view_products', 'stock', '[wb_notify_bar][wb_products]', [ 'stock' => 'Stock levels', 'documents' => 'Datasheets' ] ],
		'stock'         => [ 'Stock', 'Every figure is the sum of recorded movements. Corrections and write-offs need a second person.', 'wb_view_stock', 'stock', '[wb_notify_bar][wb_stock]', [ 'purchasing' => 'Reorder', 'integrity' => 'Integrity report' ] ],
		'purchasing'    => [ 'Purchasing', 'What to reorder, what is on its way, and receiving it into stock.', 'wb_manage_purchasing', 'stock', '[wb_notify_bar][wb_purchasing]', [ 'stock' => 'Stock levels' ] ],
		'documents'     => [ 'Documents', 'Files live off the database. Links are short-lived; a datasheet link always gives the current version.', 'wb_view_documents', 'stock', '[wb_notify_bar][wb_documents]', [ 'products' => 'Products' ] ],
		'marketing'     => [ 'Marketing', 'Worked out every night from what customers actually order.', 'wb_view_marketing', 'know', '[wb_notify_bar][wb_marketing]', [ 'customers' => 'Customers', 'quotes' => 'Write a quote' ] ],
		'cashflow'      => [ 'Cashflow', 'Money in and out, week by week, recalculated every night.', 'wb_view_cashflow', 'know', '[wb_notify_bar][wb_cashflow]', [ 'invoices' => 'Overdue invoices', 'purchasing' => 'Supplier orders' ] ],
		'integrity'     => [ 'Integrity', 'Adjustments, write-offs, credit notes, hand-matched payments and below-floor prices — by person, every month.', 'wb_view_integrity', 'know', '[wb_integrity]', [ 'stock' => 'Stock', 'payments' => 'Payments' ] ],
		'staff'         => [ 'Staff', 'Your own time and leave; approvals for those who give them. Nobody approves their own.', 'wb_access_workspace', 'team', '[wb_notify_bar][wb_staff]', [ 'home' => 'Today' ] ],
		'payroll'       => [ 'Payroll', 'Calculates and records pay. Nothing is paid or sent to SARS by the system: you capture the EMP201 figures and upload the bank file yourself.', 'wb_access_workspace', 'team', '[wb_notify_bar][wb_payroll]', [ 'staff' => 'Timesheets and leave', 'cashflow' => 'Cashflow' ] ],
		'setup'         => [ 'System Settings', 'Your company details, colours and logo, the business tables, and what is still to do before you start.', 'wb_manage_settings', 'admin', '[wb_setup]', [ 'settings' => 'Settings', 'payments' => 'Bank layout', 'payroll' => 'Payroll' ] ],
		'settings'      => [ 'Settings', 'Tax, numbering, invoice timing, margins, alert emails (all off until you switch them on) and access.', 'wb_manage_settings', 'admin', '[wb_settings][wb_demo]', [ 'integrity' => 'Integrity report' ] ],
	];

	/** The portal is its own address and frame (no staff menu). */
	const PORTAL = [ 'Your account', 'Quotes to accept, invoices to pay, your statement and the datasheets for what you buy.', 'wb_portal', '', '[wb_portal]', [] ];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'rewrite' ] );
		add_action( 'wp_loaded', [ __CLASS__, 'maybe_flush' ] );
		add_filter( 'query_vars', fn( array $v ): array => array_merge( $v, [ self::QV ] ) );
		add_filter( 'redirect_canonical', fn( $url ) => self::requested() ? false : $url );
		add_filter( 'pre_get_document_title', fn( $t ) => self::requested() ? self::document_title() : $t, 99 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ], 999 );
		add_action( 'template_redirect', [ __CLASS__, 'serve' ], 20 );   // after every POST handler (5 and 10)
	}

	/* ================================================================== pure */

	/** The screen definition for a route value ('portal', a staff slug, '' = home), or null. */
	public static function screen( string $slug ): ?array {
		if ( 'portal' === $slug ) return self::PORTAL;
		$slug = '' === $slug ? 'home' : $slug;
		return self::SCREENS[ $slug ] ?? null;
	}

	/** The menu for someone: [ group label => [ slug => title ] ], only screens $can( cap ) opens, empty groups dropped. */
	public static function menu( callable $can ): array {
		$out = [];
		foreach ( self::GROUPS as $g => $label ) {
			foreach ( self::SCREENS as $slug => $s ) {
				if ( $s[3] === $g && $can( $s[2] ) ) $out[ $label ][ $slug ] = $s[0];
			}
		}
		return $out;
	}

	/** A screen's "Next" links the person can open: [ slug => words ]. */
	public static function next_links( string $slug, callable $can ): array {
		$s = self::screen( $slug );
		if ( ! $s ) return [];
		return array_filter( $s[5], fn( $words, $to ) => isset( self::SCREENS[ $to ] ) && $can( self::SCREENS[ $to ][2] ), ARRAY_FILTER_USE_BOTH );
	}

	/* ================================================================== WordPress side */

	public static function rewrite(): void {
		add_rewrite_rule( '^workspace/?$', 'index.php?' . self::QV . '=home', 'top' );
		add_rewrite_rule( '^workspace/([a-z_-]+)/?$', 'index.php?' . self::QV . '=$matches[1]', 'top' );
		add_rewrite_rule( '^portal/?$', 'index.php?' . self::QV . '=portal', 'top' );
	}

	/** Once per version, after every plugin has added its rules on init (a flush during init would drop the later ones). */
	public static function maybe_flush(): void {
		if ( WB_VERSION === (string) get_option( 'wb_rewrite_version', '' ) ) return;
		flush_rewrite_rules( false );
		update_option( 'wb_rewrite_version', WB_VERSION );
	}

	/** The route value of this request ('' when it is not one of ours). The front page is 'welcome' while the Setup switch is on. */
	public static function requested(): string {
		$qv = sanitize_key( (string) get_query_var( self::QV, '' ) );
		if ( '' !== $qv ) return $qv;
		return function_exists( 'is_front_page' ) && is_front_page() && self::welcome_on() ? 'welcome' : '';
	}

	public static function welcome_on(): bool {
		return 'no' !== (string) ( WB_Setup::brand()['welcome_home'] ?? 'yes' );
	}

	private static function document_title(): string {
		$slug = self::requested();
		if ( 'welcome' === $slug ) return WB_Setup::display_name() . ' · Quotes, orders, invoices, stock and pay in one place';
		$s = self::screen( $slug );
		return ( $s ? $s[0] : 'Not found' ) . ' · ' . WB_Setup::display_name();
	}

	/** On our routes: our stylesheet in, the theme's stylesheets out. */
	public static function assets(): void {
		if ( '' === self::requested() ) return;
		wp_enqueue_style( 'wb-workspace', plugins_url( 'assets/wb-workspace.css', WB_PLUGIN_FILE ), [ 'wb-dashboard' ], WB_VERSION );
		$theme = get_theme_root_uri();
		$st    = wp_styles();
		foreach ( (array) $st->queue as $h ) {
			$src = (string) ( $st->registered[ $h ]->src ?? '' );
			if ( '' !== $src && 0 === strpos( $src, $theme ) ) wp_dequeue_style( $h );
		}
		remove_action( 'wp_head', 'wp_custom_css_cb', 101 );   // the Customizer's extra CSS is written for the theme
	}

	public static function serve(): void {
		$slug = self::requested();
		if ( '' === $slug ) return;
		if ( ! is_user_logged_in() && 'welcome' !== $slug ) {
			wp_safe_redirect( wp_login_url( home_url( 'portal' === $slug ? '/portal/' : '/workspace/' . ( 'home' === $slug ? '' : $slug . '/' ) ) ) );
			exit;
		}
		nocache_headers();
		[ $status, $html ] = self::render( $slug );
		status_header( $status );
		echo $html;   // built by page() from escaped parts; the content is shortcode output
		exit;
	}

	/**
	 * One screen for the signed-in person: [ HTTP status, the whole document ]. 404 for an unknown
	 * address, 403 with the no-access page, 200 with the screen. Separate from serve() so a test can
	 * render every screen for every kind of login without a web server (tests/regress-frame.php).
	 */
	public static function render( string $slug ): array {
		if ( 'welcome' === $slug ) return [ 200, self::welcome_page() ];
		$s = self::screen( $slug );
		if ( ! $s ) return [ 404, self::page( $slug, 'Not found', '', wb_notice( 'warn', 'There is no screen at this address.' ) ) ];
		if ( ! current_user_can( $s[2] ) ) return [ 403, self::page( $slug, $s[0], $s[1], self::no_access( $slug ) ) ];
		return [ 200, self::page( $slug, $s[0], $s[1], do_shortcode( $s[4] ) ) ];
	}

	/**
	 * A screen someone cannot open (Kaycie's gates audit: never a blank page or a bare "no").
	 * Says what it is, that they do not have it, who can give it, and the way back. The likeliest
	 * way here is an old link or a notification after someone's dashboards changed.
	 */
	private static function no_access( string $slug ): string {
		if ( 'portal' === $slug ) {
			$back = current_user_can( 'wb_access_workspace' ) ? '<p><a href="' . esc_url( self::url( 'home' ) ) . '">Go to the workspace</a></p>' : '';
			return wb_notice( 'warn', 'This page is for customer logins. Your login is not a customer login.' ) . $back;
		}
		$names = [];
		foreach ( array_slice( WB_Roles::owner_ids(), 0, 3 ) as $id ) {
			$u = get_userdata( $id );
			if ( $u && (int) $id !== get_current_user_id() ) $names[] = $u->display_name;
		}
		$who  = $names ? implode( ' or ', array_map( 'esc_html', $names ) ) : 'the owner';
		$back = current_user_can( 'wb_access_workspace' )
			? '<p><a href="' . esc_url( self::url( 'home' ) ) . '">Back to Today</a></p>'
			: ( current_user_can( 'wb_portal' ) ? '<p><a href="' . esc_url( home_url( '/portal/' ) ) . '">Go to your account</a></p>' : '' );
		return wb_notice( 'warn', 'This screen is not part of your work at the moment.' )
			. '<p>If you need it, ask ' . $who . ' to tick it for you under Settings › Who can do what. The menu on the left shows everything you can open.</p>' . $back;
	}

	/** The front page: public, no menu, the way in. */
	private static function welcome_page(): string {
		$in    = is_user_logged_in();
		$links = [
			'signin'    => wp_login_url( home_url( '/workspace/' ) ),
			'workspace' => $in && current_user_can( 'wb_access_workspace' ) ? self::url( 'home' ) : '',
			'portal'    => $in && current_user_can( 'wb_portal' ) ? home_url( '/portal/' ) : '',
			'setup'     => self::url( 'setup' ),
		];
		return self::page( 'welcome', '', '', WB_Welcome::content( WB_Setup::display_name(), $links, $in && current_user_can( 'wb_manage_settings' ) ) );
	}

	private static function url( string $slug ): string {
		return home_url( 'home' === $slug ? '/workspace/' : '/workspace/' . $slug . '/' );
	}

	/** The whole document: frame + header + content. Returned, not printed. */
	private static function page( string $slug, string $title, string $sub, string $content ): string {
		$can     = fn( string $cap ): bool => current_user_can( $cap );
		$welcome = 'welcome' === $slug;
		$portal  = 'portal' === $slug || $welcome;   // no staff menu on either
		$name   = WB_Setup::display_name();
		$logo   = WB_Setup::logo_data_uri();
		$mark   = '' !== $logo ? '<img src="' . esc_attr( $logo ) . '" alt="">' : esc_html( strtoupper( substr( $name, 0, 1 ) ) ) /* first letter; no mbstring dependency */;
		$user   = wp_get_current_user();

		$side = '';
		if ( ! $portal ) {
			$side .= '<aside class="wb-side" id="wb-side" aria-label="Workspace menu"><div class="wb-side-brand"><span class="wb-side-mark">' . $mark . '</span><span class="wb-side-name">' . esc_html( $name ) . '</span></div><nav class="wb-side-nav">';
			foreach ( self::menu( $can ) as $group => $items ) {
				$side .= '<div class="wb-side-group">' . ( '' !== $group ? '<div class="wb-side-gtitle">' . esc_html( $group ) . '</div>' : '' );
				foreach ( $items as $to => $words ) {
					$on    = $to === $slug || ( 'home' === $to && '' === $slug );
					$side .= '<a class="wb-side-item' . ( $on ? ' is-active' : '' ) . '" href="' . esc_url( self::url( $to ) ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . esc_html( $words ) . '</a>';
				}
				$side .= '</div>';
			}
			$side .= '</nav></aside><div class="wb-scrim" data-wb-side-close></div>';
		}

		$me  = is_user_logged_in()
			? esc_html( $user->display_name ) . ' · <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">Sign out</a>'
			: '<a href="' . esc_url( wp_login_url( home_url( '/workspace/' ) ) ) . '">Sign in</a>';
		$top = '<header class="wb-top">'
			. ( $portal ? '<a class="wb-top-brand" href="' . esc_url( home_url( '/' ) ) . '"><span class="wb-side-mark">' . $mark . '</span>' . esc_html( $name ) . '</a>' : '<button type="button" class="wb-top-menu" aria-controls="wb-side" aria-expanded="false">Menu</button>' )
			. '<span class="wb-top-me">' . $me . '</span></header>';

		$next = '';
		if ( ! $portal && '' !== $sub ) {
			$links = self::next_links( $slug, $can );
			if ( $links ) {
				$next = '<nav class="wb-next" aria-label="Where this work goes next"><span class="wb-next-label">Next</span>';
				foreach ( $links as $to => $words ) $next .= '<a href="' . esc_url( self::url( $to ) ) . '">' . esc_html( $words ) . '</a>';
				$next .= '</nav>';
			}
		}

		$head = $welcome ? '' : '<div class="wb-head"><h1>' . esc_html( $title ) . '</h1>' . ( '' !== $sub ? '<p class="wb-sub">' . esc_html( $sub ) . '</p>' : '' ) . '</div>';
		$main = '<main class="wb-dash' . ( $welcome ? ' wb-welcome' : ( $portal ? ' wb-portal' : '' ) ) . '" id="wb-content">' . $head . $next . $content . '</main>';

		ob_start();
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ( ! $welcome ) echo '<meta name="robots" content="noindex, nofollow">' . "\n"; // the welcome page may be found; the screens never ?>
<?php wp_head(); ?>
</head>
<body class="wb-app<?php echo $welcome ? ' wb-app--welcome' : ( $portal ? ' wb-app--portal' : '' ); ?>">
<?php wp_body_open(); ?>
<a class="wb-skip" href="#wb-content">Skip to the content</a>
<div class="wb-shell"><?php echo $side; // built above from escaped parts ?><div class="wb-main"><?php echo $top . $main; // built above from escaped parts; $content is shortcode output ?></div></div>
<script>(function(){var b=document.body,m=document.querySelector(".wb-top-menu");function set(o){b.classList.toggle("wb-side-open",o);if(m)m.setAttribute("aria-expanded",o?"true":"false");}if(m)m.addEventListener("click",function(){set(!b.classList.contains("wb-side-open"));});document.addEventListener("click",function(e){if(e.target.closest("[data-wb-side-close]"))set(false);});document.addEventListener("keydown",function(e){if("Escape"===e.key)set(false);});})();</script>
<?php wp_footer(); ?>
</body>
</html>
<?php
		return (string) ob_get_clean();
	}
}
