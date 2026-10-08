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
		'howto'         => [ 'How to', 'Every flow in plain words, then walkthroughs: one thing to press or read per step.', 'wb_access_workspace', '', '[wb_howto]', [ 'home' => 'Today', 'quotes' => 'Write a quote' ] ],
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
		add_filter( 'login_url', [ __CLASS__, 'login_url' ], 10, 3 );
		add_action( 'wp_login_failed', function () { if ( 0 === strpos( (string) wp_get_referer(), self::url( 'sign-in' ) ) ) { wp_safe_redirect( self::url( 'sign-in', [ 'login' => 'failed' ] ) ); exit; } } );
		add_filter( 'pre_get_document_title', fn( $t ) => self::requested() ? self::document_title() : $t, 99 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ], 999 );
		add_action( 'template_redirect', [ __CLASS__, 'serve' ], 20 );   // after every POST handler (5 and 10)
	}

	/* ================================================================== pure */

	/** The screen definition for a route value ('portal', a staff slug, '' = home), or null. */
	/** 1.0.0: the system's own sign-in page (no menu, public). */
	const SIGNIN = [ 'Sign in', 'Your own login, or the shared demo.', '', '', '', [] ];

	public static function screen( string $slug ): ?array {
		if ( 'portal' === $slug ) return self::PORTAL;
		if ( 'sign-in' === $slug ) return self::SIGNIN;
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

	/** wp_login_url() → the system's page, except for wp-admin's own needs (re-auth, interim logins). */
	public static function login_url( $url, $redirect, $force_reauth ) {
		if ( $force_reauth || is_admin() || ( '' !== (string) $redirect && false !== strpos( (string) $redirect, '/wp-admin' ) ) ) return $url;
		return self::signin_url( (string) $redirect );
	}

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
		if ( 'demo' === $slug ) WB_Demo::enter();   // signs in and leaves; falls through to the 404 when the demo is closed
		if ( ! is_user_logged_in() && ! in_array( $slug, [ 'welcome', 'sign-in' ], true ) ) {
			wp_safe_redirect( self::signin_url( self::url( $slug ) ) );
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
		if ( 'sign-in' === $slug ) return [ 200, self::page( 'sign-in', self::SIGNIN[0], self::SIGNIN[1], self::signin_content() ) ];
		$s = self::screen( $slug );
		if ( ! $s ) return [ 404, self::page( $slug, 'Not found', '', wb_notice( 'warn', 'There is no screen at this address.' ) ) ];
		if ( ! current_user_can( $s[2] ) ) return [ 403, self::page( $slug, $s[0], $s[1], self::no_access( $slug ) ) ];
		return [ 200, self::page( $slug, $s[0], $s[1], do_shortcode( $s[4] ) . ( class_exists( 'WB_Guide' ) ? WB_Guide::fold( $slug ) : '' ) ) ];
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
			: ( current_user_can( 'wb_portal' ) ? '<p><a href="' . esc_url( self::portal_url() ) . '">Go to your account</a></p>' : '' );
		return wb_notice( 'warn', 'This screen is not part of your work at the moment.' )
			. '<p>If you need it, ask ' . $who . ' to tick it for you under Settings › Who can do what. The menu on the left shows everything you can open.</p>' . $back;
	}

	/** The front page: public, no menu, the way in. */
	private static function welcome_page(): string {
		$in    = is_user_logged_in();
		$links = [
			'signin'    => self::signin_url( self::url( 'home' ) ),
			'workspace' => $in && current_user_can( 'wb_access_workspace' ) ? self::url( 'home' ) : '',
			'portal'    => $in && current_user_can( 'wb_portal' ) ? self::portal_url() : '',
			'setup'     => self::url( 'setup' ),
			'demo'      => class_exists( 'WB_Demo' ) && WB_Demo::demo_open() ? home_url( '/workspace/demo/' ) : '',
		];
		return self::page( 'welcome', '', '', WB_Welcome::content( WB_Setup::display_name(), $links, $in && current_user_can( 'wb_manage_settings' ) ) );
	}

	/** The sign-in page, with where to go afterwards. */
	public static function signin_url( string $redirect = '' ): string {
		return self::url( 'sign-in', '' !== $redirect ? [ 'redirect_to' => $redirect ] : [] );
	}

	/**
	 * The sign-in page (1.0.0, Zina: "sign in should lead to a demo sign-in page with a demo user
	 * signed in"). Two cards: the person's own login (WordPress's form, posted to wp-login.php, so
	 * passwords, lockouts and resets stay WordPress's), and, while the demo is open, the demo
	 * visitor, already filled in, one button to go in, and the words that say what happens to
	 * what they save. Someone already signed in sees where they can go instead.
	 */
	private static function signin_content(): string {
		$to  = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( wp_unslash( (string) $_GET['redirect_to'] ), '' ) : '';
		$to  = '' !== $to ? $to : self::url( 'home' );
		if ( is_user_logged_in() ) {
			$h = '<div class="wb-signin"><section class="wb-card wb-card--lead"><h2>You are signed in</h2><p>' . esc_html( wp_get_current_user()->display_name ) . '</p><p class="wb-form-acts">'
				. ( current_user_can( 'wb_access_workspace' ) ? '<a class="wb-btn" href="' . esc_url( self::url( 'home' ) ) . '">Open the workspace</a>' : '' )
				. ( current_user_can( 'wb_portal' ) ? '<a class="wb-btn' . ( current_user_can( 'wb_access_workspace' ) ? ' wb-btn-ghost' : '' ) . '" href="' . esc_url( self::portal_url() ) . '">Your account</a>' : '' )
				. '<a class="wb-btn wb-btn-ghost" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">Sign out</a></p></section></div>';
			return $h;
		}
		$failed = isset( $_GET['login'] ) && 'failed' === $_GET['login'];
		$demo   = class_exists( 'WB_Demo' ) && WB_Demo::demo_open();
		$form   = function_exists( 'wp_login_form' ) ? wp_login_form( [ 'echo' => false, 'redirect' => $to, 'form_id' => 'wb-login', 'label_username' => 'Login name or email', 'label_password' => 'Password', 'label_remember' => 'Keep me signed in on this device', 'label_log_in' => 'Sign in', 'remember' => true ] ) : '';
		$h  = '<div class="wb-signin' . ( $demo ? ' wb-signin--two' : '' ) . '">';
		$h .= '<section class="wb-card wb-card--lead" aria-labelledby="wb-own"><h2 id="wb-own">Your login</h2>'
			. ( $failed ? wb_notice( 'err', 'That login name or password is not right. Try again, or reset your password below.' ) : '' )
			. $form . '<p class="wb-small"><a href="' . esc_url( wp_lostpassword_url( $to ) ) . '">Forgotten your password?</a></p></section>';
		if ( $demo ) {
			$h .= '<section class="wb-card wb-card--quiet" aria-labelledby="wb-demo-h"><span class="wb-kicker">Just looking?</span><h2 id="wb-demo-h">The demo</h2>'
				. '<label class="wb-field"><span>Signed in as</span><input type="text" value="Demo visitor (demo)" readonly aria-readonly="true"></label>'
				. '<p class="wb-muted">A shared sample company with customers, products, quotes and a bank statement already in it. Click anything, break nothing. <strong>Whatever you save is kept for the day and cleared every night.</strong> Please do not enter real names or numbers.</p>'
				. '<p class="wb-form-acts"><a class="wb-btn" href="' . esc_url( home_url( '/workspace/demo/' ) ) . '">Enter the demo</a></p></section>';
		}
		return $h . '</div>';
	}

	/**
	 * The address of a screen, by slug (0.3.2, BUILD-PATTERNS §2.1): the one place that knows the
	 * shape of a workspace address. Nothing else types '/workspace/'. An unknown slug lands on Today
	 * rather than on a dead address; regress-workspace.php checks that every slug used in code exists.
	 * $args are added as a query string (?quote=12) — view state the screen reads, never a message.
	 */
	public static function url( string $slug, array $args = [] ): string {
		if ( 'portal' === $slug ) return self::portal_url();
		if ( 'sign-in' === $slug ) return $args ? add_query_arg( $args, home_url( '/workspace/sign-in/' ) ) : home_url( '/workspace/sign-in/' );
		if ( '' === $slug || ! isset( self::SCREENS[ $slug ] ) ) $slug = 'home';
		$url = home_url( 'home' === $slug ? '/workspace/' : '/workspace/' . $slug . '/' );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/** The customer portal's address. */
	public static function portal_url(): string {
		return home_url( '/portal/' );
	}

	/** The one primary action for a screen (Brandzgro: one primary per screen), as a link to the fold that does it. */
	const ACTIONS = [
		'customers' => [ 'wb_manage_customers', '#wb-add', 'Add a customer' ], 'products' => [ 'wb_manage_products', '#wb-add', 'Add a product' ],
		'quotes' => [ 'wb_create_quotes', '#wb-add', 'New quote' ], 'payments' => [ 'wb_import_bank', '#wb-add', 'Import a bank statement' ],
		'stock' => [ 'wb_move_stock', '#wb-add', 'Correct stock' ], 'purchasing' => [ 'wb_manage_purchasing', '#wb-add', 'New purchase order' ],
		'documents' => [ 'wb_manage_documents', '#wb-add', 'Add a datasheet' ], 'marketing' => [ 'wb_manage_marketing', '#wb-add', 'Record a contact' ],
		'staff' => [ 'wb_access_workspace', '#wb-add', 'My timesheet' ], 'invoices' => [ 'wb_issue_credit_notes', '#wb-add', 'Ask for a credit note' ],
	];

	public static function head_action( string $slug, callable $can ): string {
		$a = self::ACTIONS[ $slug ] ?? null;
		if ( ! $a || ! $can( $a[0] ) ) return '';
		return '<a class="wb-btn" href="' . esc_url( self::url( $slug ) . $a[1] ) . '">' . esc_html( $a[2] ) . '</a>';
	}

	/** Line icons for the menu (Lucide shapes, stroke 1.6, never filled), keyed by screen slug. */
	const ICONS = [
		'home'          => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
		'notifications' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
		'customers'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'quotes'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/>',
		'orders'        => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 2h2l2.7 12.4a2 2 0 0 0 2 1.6h9.8a2 2 0 0 0 1.9-1.6L22 7H5"/>',
		'invoices'      => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
		'payments'      => '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/>',
		'deliveries'    => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.6a1 1 0 0 0-.2-.6L18.3 8.4A1 1 0 0 0 17.5 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
		'products'      => '<path d="M16.5 9.4 7.5 4.2"/><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5"/><path d="M12 22V12"/>',
		'stock'         => '<path d="M12.8 2.2a2 2 0 0 0-1.6 0L2.6 6.1a1 1 0 0 0 0 1.8l8.6 3.9a2 2 0 0 0 1.6 0l8.6-3.9a1 1 0 0 0 0-1.8z"/><path d="m22 17.6-9.2 4.2a2 2 0 0 1-1.6 0L2 17.6"/><path d="m22 12.6-9.2 4.2a2 2 0 0 1-1.6 0L2 12.6"/>',
		'purchasing'    => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
		'documents'     => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9L9.6 3.9A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
		'marketing'     => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
		'cashflow'      => '<path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/>',
		'integrity'     => '<path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
		'staff'         => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		'payroll'       => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
		'setup'         => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
		'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		'howto'         => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
		'signout'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
	];

	public static function icon( string $slug ): string {
		$d = self::ICONS[ $slug ] ?? '<circle cx="12" cy="12" r="4"/>';
		return '<span class="wb-side-ic" aria-hidden="true"><svg viewBox="0 0 24 24">' . $d . '</svg></span>';
	}

	/** The whole document: frame + header + content. Returned, not printed. */
	private static function page( string $slug, string $title, string $sub, string $content ): string {
		$can     = fn( string $cap ): bool => current_user_can( $cap );
		$welcome = 'welcome' === $slug;
		$signin  = 'sign-in' === $slug;
		$portal  = 'portal' === $slug || $welcome || $signin;   // no staff menu on any of these
		$name   = WB_Setup::display_name();
		$logo   = WB_Setup::logo_data_uri();
		$mark   = '' !== $logo ? '<img src="' . esc_attr( $logo ) . '" alt="">' : esc_html( strtoupper( substr( $name, 0, 1 ) ) ) /* first letter; no mbstring dependency */;
		$user   = wp_get_current_user();

		$side = '';
		$wait = ! $portal && class_exists( 'WB_Needs' ) ? WB_Needs::waiting_count( $can ) : 0;
		if ( ! $portal ) {
			$side .= '<aside class="wb-side" id="wb-side" aria-label="Workspace menu"><div class="wb-side-brand"><span class="wb-side-mark">' . $mark . '</span><span class="wb-side-name">' . esc_html( $name ) . '<small>Workspace</small></span></div><nav class="wb-side-nav">';
			foreach ( self::menu( $can ) as $group => $items ) {
				$side .= '<div class="wb-side-group">' . ( '' !== $group ? '<div class="wb-side-gtitle">' . esc_html( $group ) . '</div>' : '' );
				foreach ( $items as $to => $words ) {
					$on    = $to === $slug || ( 'home' === $to && '' === $slug );
					$badge = 'home' === $to && $wait > 0 ? '<span class="wb-side-badge">' . (int) $wait . '<span class="wb-sr"> waiting on you</span></span>' : '';
					$side .= '<a class="wb-side-item' . ( $on ? ' is-active' : '' ) . '" href="' . esc_url( self::url( $to ) ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . self::icon( $to ) . '<span>' . esc_html( $words ) . '</span>' . $badge . '</a>';
				}
				$side .= '</div>';
			}
			$initials = '';
			foreach ( array_slice( preg_split( '/\s+/', trim( (string) $user->display_name ) ) ?: [], 0, 2 ) as $w ) $initials .= strtoupper( substr( $w, 0, 1 ) );
			$side .= '</nav><div class="wb-side-foot"><div class="wb-side-me"><span class="wb-side-av" aria-hidden="true">' . esc_html( $initials ) . '</span><span>' . esc_html( (string) $user->display_name ) . '<small>Signed in</small></span></div>'
				. '<a class="wb-side-item" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . self::icon( 'signout' ) . '<span>Sign out</span></a></div></aside><div class="wb-scrim" data-wb-side-close></div>';
		}

		$me  = is_user_logged_in()
			? esc_html( $user->display_name ) . ' · <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">Sign out</a>'
			: '<a href="' . esc_url( self::signin_url( self::url( 'home' ) ) ) . '">Sign in</a>';
		$crumb = '';
		if ( ! $portal ) {
			$crumb = '<nav aria-label="Breadcrumb"><ol class="wb-crumb"><li>' . ( 'home' === $slug ? '<span aria-current="page">Today</span>' : '<a href="' . esc_url( self::url( 'home' ) ) . '">Today</a>' ) . '</li>'
				. ( 'home' !== $slug && '' !== $title ? '<li><span aria-current="page">' . esc_html( $title ) . '</span></li>' : '' ) . '</ol></nav>';
			$crumb .= '<a class="wb-top-wait' . ( $wait ? '' : ' is-clear' ) . '" href="' . esc_url( self::url( 'home' ) ) . '#wb-waiting"><b>' . (int) $wait . '</b>' . ( $wait ? '<span class="wb-top-wait-w"> waiting on you</span>' : '<span class="wb-top-wait-w"> waiting</span>' ) . '</a>';
		}
		$top = '<header class="wb-top">'
			. ( $portal ? '<a class="wb-top-brand" href="' . esc_url( home_url( '/' ) ) . '"><span class="wb-side-mark">' . $mark . '</span>' . esc_html( $name ) . '</a>' : '<button type="button" class="wb-top-menu" aria-controls="wb-side" aria-expanded="false">Menu</button>' . $crumb )
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

		// The page head (Kaycie's kc-head): eyebrow, title, one line, and the screen's one primary action.
		$head = '';
		if ( ! $welcome ) {
			$s       = self::screen( $slug );
			$eyebrow = $s && '' !== (string) ( self::GROUPS[ $s[3] ] ?? '' ) ? self::GROUPS[ $s[3] ] : ( 'portal' === $slug ? 'Your account' : ( $signin ? WB_Setup::display_name() : '' ) );
			$h1      = $title;
			if ( 'home' === $slug && class_exists( 'WB_Needs' ) ) {
				$eyebrow = date_i18n( 'l j F' );
				$first   = trim( (string) strtok( (string) $user->display_name, ' ' ) );
				$h1      = WB_Needs::greeting( (int) date_i18n( 'G' ) ) . ( '' !== $first ? ', ' . $first : '' ) . '.';
				$waiting = 0; $open = 0;
				foreach ( WB_Needs::mine( $can ) as $l ) { if ( 'waiting' === $l['kind'] ) $waiting += $l['count']; else $open += $l['count']; }
				$sub = WB_Needs::lede( $waiting, $open );
			}
			$acts = self::head_action( $slug, $can );
			$head = '<div class="wb-head"><div>' . ( '' !== $eyebrow ? '<span class="wb-eyebrow">' . esc_html( $eyebrow ) . '</span>' : '' ) . '<h1>' . esc_html( $h1 ) . '</h1>'
				. ( '' !== $sub ? '<p class="wb-sub">' . esc_html( $sub ) . '</p>' : '' ) . '</div>' . ( '' !== $acts ? '<div class="wb-head-acts">' . $acts . '</div>' : '' ) . '</div>';
		}
		// One lead fold per page: the first plain fold carries the rule unless a screen chose its own.
		if ( false === strpos( $content, 'wb-fold--lead' ) ) $content = preg_replace( '/<details class="wb-fold"/', '<details class="wb-fold wb-fold--lead"', $content, 1 );
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
<body class="wb-app<?php echo $welcome ? ' wb-app--welcome' : ( $signin ? ' wb-app--signin' : ( $portal ? ' wb-app--portal' : '' ) ); ?>">
<?php wp_body_open(); ?>
<a class="wb-skip" href="#wb-content">Skip to the content</a>
<?php if ( class_exists( 'WB_Demo' ) ) echo WB_Demo::ribbon(); ?>
<div class="wb-shell"><?php echo $side; // built above from escaped parts ?><div class="wb-main"><?php echo $top . $main; // built above from escaped parts; $content is shortcode output ?></div></div>
<script>(function(){var b=document.body,m=document.querySelector(".wb-top-menu");function set(o){b.classList.toggle("wb-side-open",o);if(m)m.setAttribute("aria-expanded",o?"true":"false");}if(m)m.addEventListener("click",function(){set(!b.classList.contains("wb-side-open"));});document.addEventListener("click",function(e){if(e.target.closest("[data-wb-side-close]"))set(false);});document.addEventListener("keydown",function(e){if("Escape"===e.key)set(false);});})();</script>
<?php wp_footer(); ?>
</body>
</html>
<?php
		return (string) ob_get_clean();
	}
}
