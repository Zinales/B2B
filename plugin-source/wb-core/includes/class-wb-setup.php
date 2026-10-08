<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Setup — the Setup screen ([wb_setup], cap wb_manage_settings) and the brand.
 *
 * Option wb_brand holds the client's identity (display name, legal name, registration and VAT
 * numbers, addresses, logo storage key) and the look (colours + heading/body font). The shipped palette is
 * the default. Every save is ledgered with before/after.
 *
 * Colours are validated as hex and the readable pairs are checked against WCAG 2.x contrast:
 * any pair below 4.5:1 is REFUSED with a plain message naming the pair (fail closed — an
 * unreadable screen is a broken screen). The accent is decoration only (thin lines, dots) and
 * is never checked as text, because it is never used as text on a light background.
 *
 * The colours are printed as CSS custom properties: the --kc-* names the styling sheet uses and
 * --wb-* aliases for wb-dashboard.css, in wp_head (and in the small REST pages).
 *
 * The screen is also the first-run checklist: company details, colours, VAT, bank CSV mapping,
 * first product, first customer, payroll settings — each Done or To do, computed every time.
 *
 * The colour maths (valid_hex, luminance, contrast, check_pairs, css_vars) is PURE — tested
 * without WordPress.
 */
class WB_Setup {

	const OPTION = 'wb_brand';

	/** The shipped palette: navy, rose, cream, slate. The defaults a new client starts with (Zina, 2 October 2026). */
	const DEFAULT_COLORS = [
		'primary'      => '#8A3B52',   // rose: buttons and links
		'primary_dark' => '#6E2E41',   // button hover / pressed
		'ink'          => '#0B1F3A',   // navy: headings and strong text
		'accent'       => '#E3A9B8',   // rose accent: thin accents only, never text on light
		'background'   => '#F7F3EE',   // cream page
		'surface'      => '#FFFFFF',   // cards, tables
		'line'         => '#E6DCCF',   // borders
		'text_muted'   => '#47586D',   // slate: body and muted text
		'soft'         => '#FBF1F3',   // rose tint: soft chips
		'on_primary'   => '#FFFFFF',   // text on buttons
		'ok'           => '#2F6B4F',
		'err'          => '#9A2B2B',
	];

	/** Plain-English names for the colour keys (used in messages). */
	const COLOR_LABELS = [
		'primary' => 'Main colour (buttons and links)', 'primary_dark' => 'Main colour, darker (button hover)', 'ink' => 'Headings and strong text',
		'accent' => 'Accent (thin lines only)', 'background' => 'Page background', 'surface' => 'Cards and tables', 'line' => 'Borders',
		'text_muted' => 'Body and muted text', 'soft' => 'Soft chips', 'on_primary' => 'Text on buttons', 'ok' => 'Good news text', 'err' => 'Problem text',
	];

	/** [ foreground, background, what it is ] — every pair a person has to read. */
	const PAIRS = [
		[ 'ink', 'background', 'headings on the page background' ],
		[ 'text_muted', 'background', 'body text on the page background' ],
		[ 'ink', 'surface', 'headings on cards' ],
		[ 'text_muted', 'surface', 'body text on cards' ],
		[ 'primary', 'background', 'links on the page background' ],
		[ 'primary', 'surface', 'links on cards' ],
		[ 'on_primary', 'primary', 'button text on the main colour' ],
		[ 'on_primary', 'primary_dark', 'button text on the darker main colour' ],
		[ 'ok', 'surface', 'good-news text on cards' ],
		[ 'err', 'surface', 'problem text on cards' ],
	];

	const MIN_CONTRAST = 4.5;

	const FONTS = [ 'Poppins', 'Inter', 'Mulish', 'Hanken Grotesk', 'DM Sans', 'Lato', 'Open Sans', 'Roboto', 'system' ];

	const TEXT_FIELDS = [ 'display_name', 'legal_name', 'reg_number', 'vat_number' ];
	const AREA_FIELDS = [ 'physical_address', 'postal_address', 'bank_details', 'doc_footer' ];

	public static function defaults(): array {
		return [
			'display_name' => '', 'legal_name' => '', 'reg_number' => '', 'vat_registered' => 'yes', 'vat_number' => '',
			'physical_address' => '', 'postal_address' => '', 'logo_key' => '',
			'bank_details' => '', 'doc_footer' => '',   // 1.1.0: printed on invoices (pay to) and on every document (footer line)
			'colors' => self::DEFAULT_COLORS, 'font_heading' => 'Poppins', 'font_body' => 'Poppins', 'colors_saved_at' => '',
			'portal_company_docs' => 'hidden',   // 0.2.2: hidden | all_customers (company-wide certificates etc.)
			'welcome_home' => 'yes',             // 0.3.1: the welcome page is the site's front page
		];
	}

	/* ================================================================== pure */

	/** '#abc' / 'abc' / '#AABBCC' → '#AABBCC'; anything else → ''. */
	public static function valid_hex( string $v ): string {
		$v = ltrim( trim( $v ), '#' );
		if ( preg_match( '/^[0-9a-fA-F]{3}$/', $v ) ) $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
		return preg_match( '/^[0-9a-fA-F]{6}$/', $v ) ? '#' . strtoupper( $v ) : '';
	}

	/** WCAG 2.x relative luminance of a hex colour. */
	public static function luminance( string $hex ): float {
		$hex = ltrim( self::valid_hex( $hex ), '#' );
		if ( '' === $hex ) return 0.0;
		$c = [];
		foreach ( [ 0, 2, 4 ] as $i ) {
			$s   = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$c[] = $s <= 0.03928 ? $s / 12.92 : ( ( $s + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	/** Contrast ratio (1–21), order-independent. */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Every readable pair below the minimum: [ [ fg_key, bg_key, what, ratio ], … ]. Empty = all pass.
	 * A colour that is missing or not hex counts as failing (fail closed).
	 */
	public static function check_pairs( array $colors, float $min = self::MIN_CONTRAST ): array {
		$bad = [];
		foreach ( self::PAIRS as [ $fg, $bg, $what ] ) {
			$f = self::valid_hex( (string) ( $colors[ $fg ] ?? '' ) );
			$b = self::valid_hex( (string) ( $colors[ $bg ] ?? '' ) );
			$r = ( '' === $f || '' === $b ) ? 0.0 : self::contrast( $f, $b );
			if ( $r < $min ) $bad[] = [ $fg, $bg, $what, round( $r, 2 ) ];
		}
		return $bad;
	}

	/** "R,G,B" for translucent uses (focus rings, tints). */
	public static function rgb_triplet( string $hex ): string {
		$hex = ltrim( self::valid_hex( $hex ), '#' );
		if ( '' === $hex ) return '0,0,0';
		return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
	}

	private static function font_stack( string $font ): string {
		$sys = 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
		return 'system' === $font || '' === $font ? $sys : '"' . str_replace( '"', '', $font ) . '",' . $sys;
	}

	/**
	 * Every colour key, re-validated: a stored value that is not a real hex colour falls back to the
	 * default. Use this before printing brand colours into any page or document (review P15).
	 */
	public static function safe_colors( array $colors ): array {
		$c = [];
		foreach ( self::DEFAULT_COLORS as $k => $def ) $c[ $k ] = self::valid_hex( (string) ( $colors[ $k ] ?? '' ) ) ?: $def;
		return $c;
	}

	/**
	 * The :root block. The --kc-* names are the ones the styling sheet reads; the --wb-* names are
	 * what wb-dashboard.css reads. Only validated hex values are printed (anything else falls back
	 * to the default), so nothing a person typed can break out of the style block.
	 */
	public static function css_vars( array $colors, string $font_heading = 'Poppins', string $font_body = 'Poppins' ): string {
		$c  = self::safe_colors( $colors );
		$fh =self::font_stack( in_array( $font_heading, self::FONTS, true ) ? $font_heading : 'Poppins' );
		$fb = self::font_stack( in_array( $font_body, self::FONTS, true ) ? $font_body : 'Poppins' );
		$v  = [
			'--kc-ink' => $c['ink'], '--kc-primary' => $c['primary'], '--kc-primary-dk' => $c['primary_dark'], '--kc-primary-rgb' => self::rgb_triplet( $c['primary'] ),
			'--kc-gold' => $c['accent'], '--kc-gold-dk' => $c['accent'], '--kc-gold-pale' => $c['soft'], '--kc-gold-text' => $c['primary'],
			'--kc-green' => $c['ok'], '--kc-clay' => $c['err'],
			'--kc-muted' => $c['text_muted'], '--kc-muted-2' => $c['text_muted'], '--kc-rose' => $c['text_muted'],
			'--kc-line' => $c['line'], '--kc-line-mid' => $c['line'], '--kc-line-soft' => $c['line'], '--kc-line-head' => $c['line'],
			'--kc-bg' => $c['background'], '--kc-bg-tint' => $c['background'], '--kc-card' => $c['surface'], '--kc-soft' => $c['soft'],
			'--kc-font' => $fb, '--kc-font-head' => $fh,
			'--wb-ink' => $c['ink'], '--wb-primary' => $c['primary'], '--wb-primary-dark' => $c['primary_dark'], '--wb-on-primary' => $c['on_primary'],
			'--wb-accent' => $c['accent'], '--wb-canvas' => $c['background'], '--wb-card' => $c['surface'], '--wb-line' => $c['line'],
			'--wb-muted' => $c['text_muted'], '--wb-soft' => $c['soft'], '--wb-good' => $c['ok'], '--wb-bad' => $c['err'],
			'--wb-font' => $fb, '--wb-font-head' => $fh,
		];
		$out = ':root{';
		foreach ( $v as $k => $val ) $out .= $k . ':' . $val . ';';
		return $out . '}';
	}

	/* ================================================================== WordPress side */

	public static function init(): void {
		add_shortcode( 'wb_setup', [ __CLASS__, 'screen' ] );
		add_action( 'wp_head', [ __CLASS__, 'print_head' ], 20 );
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			$h['setup_save']  = [ __CLASS__, 'handle_save' ];
			$h['setup_reset'] = [ __CLASS__, 'handle_reset' ];
			return $h;
		} );
	}

	/** The stored brand merged over the defaults (colours key by key). */
	public static function brand(): array {
		$b = (array) get_option( self::OPTION, [] );
		$d = self::defaults();
		$out = array_merge( $d, array_intersect_key( $b, $d ) );
		$out['colors'] = array_merge( self::DEFAULT_COLORS, array_intersect_key( (array) ( $b['colors'] ?? [] ), self::DEFAULT_COLORS ) );
		return $out;
	}

	/** The name people see: the system name from Setup, else the legal name, else the site title cut short. */
	public static function display_name(): string {
		$b = self::brand();
		return (string) ( $b['display_name'] ?: ( $b['legal_name'] ?: self::short_name( (string) get_bloginfo( 'name' ) ) ) );
	}

	/**
	 * A site title is often "Name - a long tagline". Until a system name is set, the menu and the
	 * welcome page use the part before the first dash, bar or colon. Pure.
	 */
	public static function short_name( string $title ): string {
		$t = trim( $title );
		if ( preg_match( '/^(.+?)\s*[-\x{2013}\x{2014}|:]\s+/u', $t, $m ) ) $t = trim( $m[1] );
		return '' === $t ? $title : $t;
	}

	/** The style block for any page head (dashboards, REST pages). */
	public static function style_tag(): string {
		$b = self::brand();
		$fonts = array_unique( array_filter( [ $b['font_heading'], $b['font_body'] ], fn( $f ) => 'system' !== $f && in_array( $f, self::FONTS, true ) ) );
		$link  = '';
		if ( $fonts ) {
			$fam  = implode( '&', array_map( fn( $f ) => 'family=' . str_replace( ' ', '+', $f ) . ':wght@400;500;600;700', $fonts ) );
			$link = '<link rel="stylesheet" href="' . esc_url( 'https://fonts.googleapis.com/css2?' . $fam . '&display=swap' ) . '">';
		}
		return $link . '<style id="wb-brand">' . self::css_vars( $b['colors'], (string) $b['font_heading'], (string) $b['font_body'] ) . '</style>';
	}

	public static function print_head(): void {
		if ( ! is_user_logged_in() && '' === WB_Workspace::requested() ) return;   // our own pages always; other public pages keep the theme alone
		echo self::style_tag();   // values are validated hex / whitelisted font names
	}

	/** The logo as a data: URI for generated documents (payslips, invoices). '' when none. */
	public static function logo_data_uri(): string {
		$key = (string) self::brand()['logo_key'];
		if ( '' === $key || ! WB_Storage::exists( $key ) ) return '';
		$path = WB_Storage::path( $key );
		if ( filesize( $path ) > 512 * KB_IN_BYTES ) return '';
		$mime = wp_check_filetype( $path )['type'] ?: '';
		if ( ! in_array( $mime, [ 'image/png', 'image/jpeg', 'image/webp' ], true ) ) return '';
		return 'data:' . $mime . ';base64,' . base64_encode( (string) file_get_contents( $path ) );
	}

	/** Write the brand, keep the 0.1.0 options (wb_company, wb_brand_colors) in step, ledger before/after. */
	private static function store( array $new ) {
		$before = self::brand();
		update_option( self::OPTION, $new );
		update_option( 'wb_company', [
			'name' => (string) ( $new['legal_name'] ?: $new['display_name'] ), 'reg_number' => (string) $new['reg_number'],
			'vat_number' => (string) $new['vat_number'], 'address' => (string) $new['physical_address'], 'logo_key' => (string) $new['logo_key'],
		] );
		update_option( 'wb_brand_colors', [ 'ink' => $new['colors']['ink'], 'accent' => $new['colors']['primary'], 'canvas' => $new['colors']['background'] ] );
		$b = [];
		$a = [];
		foreach ( $new as $k => $v ) {
			if ( ( $before[ $k ] ?? null ) == $v ) continue;   // phpcs:ignore — loose on purpose
			$b[ $k ] = $before[ $k ] ?? null;
			$a[ $k ] = $v;
		}
		if ( $a ) wb_ledger_write( 'brand_saved', 'wp_options', 0, $b, $a );
		return true;
	}

	/**
	 * Save from the form. Colours are validated and contrast-checked BEFORE anything is written.
	 *
	 * @return true|WP_Error
	 */
	public static function save( array $in, ?array $logo_file = null ) {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return new WP_Error( 'wb_forbidden', 'Only someone with Settings may change the setup.' );
		$cur = self::brand();
		$new = $cur;
		foreach ( self::TEXT_FIELDS as $f ) $new[ $f ] = sanitize_text_field( (string) ( $in[ $f ] ?? $cur[ $f ] ) );
		foreach ( self::AREA_FIELDS as $f ) $new[ $f ] = sanitize_textarea_field( (string) ( $in[ $f ] ?? $cur[ $f ] ) );
		$new['vat_registered'] = 'no' === ( $in['vat_registered'] ?? $cur['vat_registered'] ) ? 'no' : 'yes';
		$new['portal_company_docs'] = 'all_customers' === ( $in['portal_company_docs'] ?? $cur['portal_company_docs'] ) ? 'all_customers' : 'hidden';
		$new['welcome_home']        = 'no' === ( $in['welcome_home'] ?? $cur['welcome_home'] ) ? 'no' : 'yes';
		if ( 'yes' === $new['vat_registered'] && '' !== $new['vat_number'] && ! preg_match( '/^4\d{9}$/', preg_replace( '/\s+/', '', $new['vat_number'] ) ) ) {
			return new WP_Error( 'wb_vat', 'A South African VAT number is 10 digits starting with 4. Check it, or choose "not VAT registered".' );
		}
		foreach ( [ 'font_heading', 'font_body' ] as $f ) {
			$v = (string) ( $in[ $f ] ?? $cur[ $f ] );
			$new[ $f ] = in_array( $v, self::FONTS, true ) ? $v : 'Poppins';
		}
		$colors = [];
		foreach ( self::DEFAULT_COLORS as $k => $def ) {
			$raw = (string) ( $in['color_' . $k] ?? $cur['colors'][ $k ] );
			$hex = self::valid_hex( $raw );
			if ( '' === $hex ) return new WP_Error( 'wb_hex', sprintf( '"%s" is not a colour code. Use a code like #8A3B52 (for %s).', $raw, strtolower( self::COLOR_LABELS[ $k ] ) ) );
			$colors[ $k ] = $hex;
		}
		$bad = self::check_pairs( $colors );
		if ( $bad ) {
			$words = array_map( fn( $p ) => sprintf( '%s (%s on %s) is %s:1', $p[2], $colors[ $p[0] ], $colors[ $p[1] ], number_format( $p[3], 2 ) ), $bad );
			return new WP_Error( 'wb_contrast', 'Not saved — these colours are too hard to read (each needs at least 4.5:1): ' . implode( '; ', $words ) . '.' );
		}
		if ( $colors !== $cur['colors'] || '' === $cur['colors_saved_at'] ) $new['colors_saved_at'] = current_time( 'mysql' );
		$new['colors'] = $colors;

		if ( $logo_file && ! empty( $logo_file['tmp_name'] ) ) {
			if ( ! is_uploaded_file( $logo_file['tmp_name'] ) ) return new WP_Error( 'wb_no_file', 'The logo upload did not arrive. Try again.' );
			if ( (int) $logo_file['size'] > 512 * KB_IN_BYTES ) return new WP_Error( 'wb_big', 'The logo must be 512 KB or smaller.' );
			$check = wp_check_filetype_and_ext( $logo_file['tmp_name'], (string) $logo_file['name'] );
			if ( ! in_array( (string) $check['type'], [ 'image/png', 'image/jpeg', 'image/webp' ], true ) ) return new WP_Error( 'wb_type', 'The logo must be a PNG, JPG or WebP image.' );
			$key = WB_Storage::put_file( (string) $logo_file['tmp_name'], 'brand/logo.' . ( $check['ext'] ?: 'png' ) );
			if ( '' === $key ) return new WP_Error( 'wb_store_failed', 'The logo could not be stored.' );
			$new['logo_key'] = $key;   // the old file stays on disk (nothing is hard-deleted)
		}
		return self::store( $new );
	}

	/** Back to the shipped colours and font. Identity fields are kept. @return true|WP_Error */
	public static function reset_look() {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return new WP_Error( 'wb_forbidden', 'Only someone with Settings may change the setup.' );
		$new = self::brand();
		$new['colors']          = self::DEFAULT_COLORS;
		$new['font_heading']    = 'Poppins';
		$new['font_body']       = 'Poppins';
		$new['colors_saved_at'] = current_time( 'mysql' );
		return self::store( $new );
	}

	public static function handle_save() {
		$in  = wp_unslash( $_POST );
		$res = self::save( (array) $in, ! empty( $_FILES['logo']['tmp_name'] ) ? (array) $_FILES['logo'] : null );
		return is_wp_error( $res ) ? $res : [ 'msg' => 'Setup saved.' ];
	}

	public static function handle_reset() {
		$res = self::reset_look();
		return is_wp_error( $res ) ? $res : [ 'msg' => 'Colours and fonts are back to the defaults.' ];
	}

	/* ================================================================== checklist */

	/** [ key => [ label, done, where, what it means ] ] — computed every time, never stored. */
	public static function checklist(): array {
		$b    = self::brand();
		$maps = (array) get_option( 'wb_bank_mapping', [] );
		$pay  = (array) get_option( 'wb_payroll', [] );
		return [
			'tables'   => [ 'Business tables', WB_Tables::all_present(), '#wb-setup-tables', 'The tables the system keeps its records in' ],   // 0.3.0: first, everything else needs them
			'company'  => [ 'Company details', '' !== $b['legal_name'] && '' !== trim( $b['physical_address'] ), '#wb-setup-company', 'Legal name and address, on every document' ],
			'colours'  => [ 'Colours and logo', '' !== (string) $b['colors_saved_at'], '#wb-setup-look', 'Your look, checked for contrast' ],
			'vat'      => [ 'VAT', 'no' === $b['vat_registered'] || '' !== $b['vat_number'], '#wb-setup-company', 'Registered or not, and the number' ],
			'bank'     => [ 'Bank statement layout', (bool) array_filter( $maps, fn( $m ) => is_array( $m ) && isset( $m['columns'] ) ), WB_Workspace::url( 'payments' ), 'Which columns your bank\'s CSV uses, saved once' ],
			'product'  => [ 'First product', WB_CCT::count( 'wb_products', [], false ) > 0, WB_Workspace::url( 'products' ), 'Cost, list price and lowest margin' ],
			'customer' => [ 'First customer', WB_CCT::count( 'wb_customers', [], false ) > 0, WB_Workspace::url( 'customers' ), 'Terms, credit limit and price tier' ],
			'payroll'  => [ 'Payroll settings', wb_truthy( $pay['configured'] ?? '' ), WB_Workspace::url( 'payroll' ), 'Only if the system will do your pay' ],
		];
	}

	public static function checklist_card(): string {
		$items = self::checklist();
		$done  = count( array_filter( $items, fn( $i ) => $i[1] ) );
		$all   = count( $items );
		$pct   = $all ? (int) round( 100 * $done / $all ) : 0;
		$h     = '<section class="wb-card wb-card--lead wb-checklist" aria-labelledby="wb-checklist-h"><div class="wb-checklist-head"><h2 id="wb-checklist-h">Getting started</h2>'
			. '<span class="wb-checklist-count">' . $done . ' of ' . $all . ' done</span></div>'
			. '<div class="wb-meter" role="progressbar" aria-valuenow="' . $done . '" aria-valuemin="0" aria-valuemax="' . $all . '" aria-label="Set-up steps done"><span style="width:' . $pct . '%"></span></div>'
			. ( $done === $all ? '<p class="wb-muted">Everything is set up. You are selling.</p>' : '' ) . '<ul class="wb-check">';
		foreach ( $items as $i ) {
			$h .= '<li class="' . ( $i[1] ? 'is-done' : 'is-todo' ) . '"><span class="wb-check-mark" aria-hidden="true">' . ( $i[1] ? '<svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>' : '' ) . '</span>'
				. '<span class="wb-check-t"><a href="' . esc_url( $i[2] ) . '">' . esc_html( $i[0] ) . '</a><small>' . esc_html( (string) ( $i[3] ?? '' ) ) . '</small></span>'
				. ( $i[1] ? '<span class="wb-check-state">Done</span>' : '<a class="wb-btn wb-btn-sm wb-btn-ghost" href="' . esc_url( $i[2] ) . '">Do it<span class="wb-sr">: ' . esc_html( $i[0] ) . '</span></a>' ) . '</li>';
		}
		return $h . '</ul></section>';
	}

	/* ================================================================== screen */

	public static function screen( $atts = [] ): string {
		if ( ! is_user_logged_in() ) return wb_notice( 'warn', 'Please sign in.' );
		if ( ! current_user_can( 'wb_manage_settings' ) ) return wb_notice( 'warn', 'This page is not part of your work. Ask the owner if you need it.' );
		$b = self::brand();
		$h = WB_RowActions::notice() . self::checklist_card() . self::page_clash_notice();
		$h .= '<div id="wb-setup-tables"></div>' . self::fold( 'Business tables', WB_Tables::panel(), ! WB_Tables::all_present() );

		$f = WB_Render::form_open( 'setup_save', true ) . '<div id="wb-setup-company"></div>'
			. WB_Render::field( 'display_name', 'System name (what people see)', 'text', $b['display_name'], [ 'note' => 'Short. It is on the menu and the welcome page.', 'placeholder' => 'e.g. GroB2B' ] )
			. WB_Render::field( 'legal_name', 'Company legal name', 'text', $b['legal_name'] )
			. WB_Render::field( 'reg_number', 'Company registration number', 'text', $b['reg_number'] )
			. WB_Render::field( 'vat_registered', 'VAT registered?', 'select', $b['vat_registered'], [ 'options' => [ 'yes' => 'Yes', 'no' => 'No — not VAT registered' ] ] )
			. WB_Render::field( 'vat_number', 'VAT number', 'text', $b['vat_number'], [ 'note' => '10 digits, starting with 4.' ] )
			. WB_Render::field( 'physical_address', 'Physical address', 'textarea', $b['physical_address'], [ 'rows' => 3 ] )
			. WB_Render::field( 'postal_address', 'Postal address', 'textarea', $b['postal_address'], [ 'rows' => 3 ] )
			. WB_Render::field( 'bank_details', 'Bank details on invoices', 'textarea', $b['bank_details'], [ 'rows' => 3, 'placeholder' => "FNB · Cheque · 62012345678 · Branch 250655", 'note' => 'Printed in the "Pay to" box on every invoice, with the invoice number as the reference.' ] )
			. WB_Render::field( 'doc_footer', 'Footer line on documents', 'textarea', $b['doc_footer'], [ 'rows' => 2, 'placeholder' => 'Goods remain our property until paid in full. E&OE.' ] )
			. '<label class="wb-field"><span>Logo (PNG, JPG or WebP, up to 512 KB)</span><input type="file" name="logo" accept="image/png,image/jpeg,image/webp">'
			. ( '' !== $b['logo_key'] ? '<small>A logo is on file. Choose a new one only to replace it.</small>' : '' ) . '</label>'
			. '<div id="wb-setup-look"></div>';
		foreach ( self::DEFAULT_COLORS as $k => $def ) {
			$f .= WB_Render::field( 'color_' . $k, self::COLOR_LABELS[ $k ], 'text', $b['colors'][ $k ], [ 'note' => 'Default ' . $def . ( 'accent' === $k ? ' — never used for text.' : '' ), 'placeholder' => $def ] );
		}
		$f .= '<div id="wb-setup-portal"></div>' . WB_Render::field( 'portal_company_docs', 'Company-wide documents in the customer portal', 'select', $b['portal_company_docs'], [
			'options' => [ 'hidden' => 'Hidden from customers', 'all_customers' => 'Shown to every customer with a portal login' ],
			'note'    => 'Certificates, safety data sheets and datasheets that belong to no product (for example an ISO certificate). Each document must also be marked "customers may see this". Product documents always follow what the customer has bought or been quoted.',
		] );
		$f .= WB_Render::field( 'welcome_home', 'The site\'s front page', 'select', $b['welcome_home'] ?? 'yes', [
			'options' => [ 'yes' => 'The welcome page: what the system does, and Sign in', 'no' => 'Leave the front page to the website' ],
			'note'    => 'The welcome page has no business information on it.',
		] );
		$fonts = array_combine( self::FONTS, array_map( fn( $x ) => 'system' === $x ? 'The device\'s own font' : $x, self::FONTS ) );
		$f .= WB_Render::field( 'font_heading', 'Heading font', 'select', $b['font_heading'], [ 'options' => $fonts ] )
			. WB_Render::field( 'font_body', 'Body font', 'select', $b['font_body'], [ 'options' => $fonts ] );
		$h .= self::fold( 'Company and look', '<p class="wb-muted">Colours are checked before saving: every text colour must be at least 4.5 times as dark (or light) as what it sits on.</p>' . $f . WB_Render::form_close( 'Save setup' ), true );

		$sw = '<div class="wb-swatches">';
		foreach ( self::PAIRS as [ $fg, $bg, $what ] ) {
			$r   = self::contrast( $b['colors'][ $fg ], $b['colors'][ $bg ] );
			$sw .= '<div class="wb-swatch" style="background:' . esc_attr( $b['colors'][ $bg ] ) . ';color:' . esc_attr( $b['colors'][ $fg ] ) . '">' . esc_html( ucfirst( $what ) ) . ' · ' . esc_html( number_format( $r, 2 ) ) . ':1</div>';
		}
		$sw .= '</div>';
		$h  .= self::fold( 'How the colours read', $sw );
		$h  .= self::fold( 'Reset to default', '<p class="wb-muted">Puts the colours and fonts back to the ones the system came with. Company details and the logo are kept.</p>' . WB_Render::form_open( 'setup_reset' ) . WB_Render::form_close( 'Reset to default', 'Put the colours and fonts back to the defaults?' ) );
		return $h;
	}

	/**
	 * 0.3.0: the plugin now serves /workspace/ and /portal/ itself. A WordPress page made by hand at
	 * one of those addresses is never shown (the plugin's address wins), so say so once, here.
	 */
	private static function page_clash_notice(): string {
		$found = [];
		foreach ( array_merge( [ 'workspace', 'portal' ], array_map( fn( $s ) => 'workspace/' . $s, array_keys( WB_Workspace::SCREENS ) ) ) as $path ) {
			$p = get_page_by_path( $path );
			if ( $p && 'trash' !== $p->post_status ) $found[] = '/' . $path . '/';
		}
		if ( ! $found ) return '';
		return wb_notice( 'warn', 'The system shows its own screens at these addresses, so the WordPress page' . ( count( $found ) > 1 ? 's' : '' ) . ' there ' . ( count( $found ) > 1 ? 'are' : 'is' ) . ' never seen: ' . esc_html( implode( ', ', $found ) ) . '. You can move ' . ( count( $found ) > 1 ? 'them' : 'it' ) . ' to the Bin under Pages in WordPress.' );
	}

	private static function fold( string $title, string $body, bool $open = false ): string {
		return WB_Render::fold( $title, $body, [ 'open' => $open ] );
	}
}
