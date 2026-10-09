<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Welcome — the site's front page (0.3.1; redrawn 0.3.7 in the Brandzgro page rhythm).
 *
 * What the system does, in plain words, and the way in: Try the demo (when the owner has opened
 * one), Sign in, or straight to the workspace / the customer's account for someone already signed
 * in. It is the system's own page, in the system's own look, and holds no business information,
 * so it may be found by search.
 *
 * The rhythm is Brandzgro's: dark, light, light, dark, light, dark. A navy hero with display type
 * and the one hand-drawn underline; six feature cards on cream with ghost numerals; "how it keeps
 * you safe" on white; the one statement band; getting started on the tint (the owner only); the
 * footer. One primary button per screen: Try the demo when there is one, else Sign in.
 */
class WB_Welcome {

	/** What it does, by the work people do. [ heading, one line, the parts ] */
	const AREAS = [
		[ 'Sell', 'From a quote to the goods going out, with nothing typed twice.', [
			'Quotes that use each customer\'s own prices, and check every price before it goes out',
			'An accepted quote becomes the order and the invoice by itself',
			'Delivery and collection notes, signed on the spot',
		] ],
		[ 'Get paid', 'See who owes what, and match the money as it comes in.', [
			'Load the bank statement; payments with a reference match themselves',
			'Overdue invoices, credit limits and accounts on hold, all in view',
			'Credit notes that need a second person to approve them',
		] ],
		[ 'Stock', 'Every figure is the sum of what was recorded, so it is always right.', [
			'Stock on hand, put aside and available, per product',
			'An alert when a product falls below its reorder point',
			'Purchase orders to suppliers, and receiving that adds the stock',
		] ],
		[ 'Know', 'What is coming, worked out from what customers actually do.', [
			'Who is likely to order soon, and who has gone quiet',
			'Cash thirteen weeks ahead: money due in, likely orders, bills, wages',
			'A monthly report of every adjustment and write-off, by person',
		] ],
		[ 'Team', 'Time, leave and pay in one place, done once a month.', [
			'Timesheets and leave, approved by someone other than the person',
			'A monthly pay run with PAYE, UIF and SDL worked out for you',
			'Payslips people can open themselves',
		] ],
		[ 'Your customers', 'A place where each customer sees only their own account.', [
			'Quotes to accept, invoices, a statement and the datasheets for what they buy',
			'A request for a quote or a change of details, sent straight to you',
		] ],
	];

	/** How it keeps you safe. [ heading, one line ] */
	const SAFETY = [
		[ 'Nothing is deleted', 'Records are archived, never removed, so there is always a trail.' ],
		[ 'Every change is recorded', 'Who did what, and when, kept in a log that cannot be edited.' ],
		[ 'Two people for the risky things', 'A write-off, a price below cost or a pay run always needs a second person.' ],
		[ 'Each person sees their own work', 'The menu shows only the screens a person has been given.' ],
	];

	/** What the demo shows you, in the order a first visit should take. */
	const DEMO_STEPS = [
		[ 'Today', 'What is waiting on you, the week in numbers, and where to start.' ],
		[ 'Write a quote', 'Find products as you type, with this customer\'s own price and the stock beside each one.' ],
		[ 'Send it, accept it', 'Email it with the PDF attached, then accept it: the order and the invoice appear, numbered in sequence.' ],
		[ 'Match the money', 'Load the bank statement and see payments find their invoices.' ],
		[ 'Look at Integrity', 'Every adjustment, write-off and hand-match, by person, for the month.' ],
	];

	/** The one hand-drawn mark on the page (Brandzgro: rose accent, 5px, round caps, H1 only, once). */
	private static function underline( string $word ): string {
		return '<span class="wb-pen">' . esc_html( $word ) . '<svg viewBox="0 0 180 24" fill="none" preserveAspectRatio="none" aria-hidden="true"><path d="M6 16 C48 7, 128 21, 174 9"/></svg></span>';
	}

	/**
	 * The page body. $links: [ 'signin' => url, 'workspace' => url|'', 'portal' => url|'', 'setup' => url,
	 * 'demo' => url|'' ] — a link that is '' is not shown. $owner true adds the getting-started block.
	 */
	public static function content( string $name, array $links, bool $owner = false ): string {
		$btn = function ( string $href, string $words, string $kind = 'primary', bool $dark = false ) {
			$cls = 'wb-btn wb-btn-lg' . ( 'primary' === $kind ? ( $dark ? ' wb-btn-on-dark' : '' ) : ( $dark ? ' wb-btn-ghost wb-btn-ghost-on-dark' : ' wb-btn-ghost' ) );
			return '<a class="' . $cls . '" href="' . esc_url( $href ) . '">' . esc_html( $words ) . '</a>';
		};
		$in     = '' !== ( $links['workspace'] ?? '' ) || '' !== ( $links['portal'] ?? '' );
		$demo   = (string) ( $links['demo'] ?? '' );
		$signin = (string) ( $links['signin'] ?? '' );

		// The way in: one primary, one secondary.
		$actions = '';
		if ( '' !== ( $links['workspace'] ?? '' ) ) $actions .= $btn( $links['workspace'], 'Open the workspace', 'primary', true );
		if ( '' !== ( $links['portal'] ?? '' ) ) $actions .= $btn( $links['portal'], 'Your account', '' === ( $links['workspace'] ?? '' ) ? 'primary' : 'secondary', true );
		if ( ! $in ) {
			$actions .= '' !== $demo ? $btn( $demo, 'Try the demo', 'primary', true ) . $btn( $signin, 'Sign in', 'secondary', true ) : $btn( $signin, 'Sign in', 'primary', true );
		}

		$h = '<section class="wb-sec wb-sec--navy wb-hero2"><div class="wb-wrap"><span class="wb-kicker wb-kicker--on-dark">' . esc_html( $name ) . '</span>'
			. '<h1 class="wb-display">Quote it, sell it, ship it, get paid.<br>Typed ' . self::underline( 'once' ) . '.</h1>'
			. '<p class="wb-lede">For businesses that sell technical products to other businesses: every customer has its own prices, every product has a datasheet, and stock and cash are watched closely.</p>'
			. '<div class="wb-hero-actions">' . $actions . '</div>'
			. ( '' !== $demo && ! $in ? '<p class="wb-small">The demo is a shared sample company. Click anything; it resets every night. Or sign in as <strong>' . esc_html( WB_Demo::login_name() ) . '</strong> with the password <strong>' . esc_html( WB_Demo::password() ) . '</strong>.</p>' : '' )
			. '</div></section>';

		$h .= '<section class="wb-sec wb-sec--cream wb-areas" aria-labelledby="wb-what"><div class="wb-wrap"><span class="wb-kicker">What it does</span><h2 id="wb-what">Six kinds of work, one place.</h2><div class="wb-area-grid">';
		foreach ( self::AREAS as $i => [ $head, $line, $parts ] ) {
			$h .= '<div class="wb-area"><span class="wb-ghost" aria-hidden="true">' . sprintf( '%02d', $i + 1 ) . '</span><h3>' . esc_html( $head ) . '</h3><p>' . esc_html( $line ) . '</p><ul class="wb-dash-list">';
			foreach ( $parts as $p ) $h .= '<li>' . esc_html( $p ) . '</li>';
			$h .= '</ul></div>';
		}
		$h .= '</div></div></section>';

		$h .= '<section class="wb-sec wb-sec--white wb-safety" aria-labelledby="wb-safe"><div class="wb-wrap"><span class="wb-kicker">How it keeps you safe</span><h2 id="wb-safe">Built so the numbers can be trusted.</h2><div class="wb-safety-grid">';
		foreach ( self::SAFETY as [ $head, $line ] ) $h .= '<div class="wb-safe"><h3>' . esc_html( $head ) . '</h3><p>' . esc_html( $line ) . '</p></div>';
		$h .= '</div></div></section>';

		$h .= '<section class="wb-sec wb-sec--navy wb-band"><div class="wb-wrap"><p class="wb-statement">Every change is recorded, with a name on it.</p>'
			. '<p class="wb-lede">Stock is the sum of what was recorded. Documents are never edited once issued. The risky things take two people.</p>'
			. ( ! $in && '' !== $demo ? '<p>' . $btn( $demo, 'See it in the demo', 'primary', true ) . '</p>' : '' ) . '</div></section>';

		if ( $owner ) {
			$h .= '<section class="wb-sec wb-sec--tint wb-start" aria-labelledby="wb-go"><div class="wb-wrap"><span class="wb-kicker">Getting started</span><h2 id="wb-go">Three steps and you are selling.</h2><ol class="wb-steps">'
				. '<li><strong>Set up.</strong> Your company details, colours and logo. The checklist under System Settings shows what is still to do.</li>'
				. '<li><strong>Add what you sell and who you sell to.</strong> Products with a cost and a list price; customers with their terms. Type them in, or upload a file.</li>'
				. '<li><strong>Give your team their screens.</strong> Under Settings, tick the screens each person works in. Everyone starts with only what they need.</li>'
				. '</ol>' . $btn( (string) ( $links['setup'] ?? $links['workspace'] ), 'Go to System Settings' ) . '</div></section>';
		} elseif ( ! $in && '' !== $demo ) {
			$h .= '<section class="wb-sec wb-sec--tint wb-start" aria-labelledby="wb-tour"><div class="wb-wrap"><span class="wb-kicker">A first visit</span><h2 id="wb-tour">Five minutes in the demo.</h2><ol class="wb-steps">';
			foreach ( self::DEMO_STEPS as [ $head, $line ] ) $h .= '<li><strong>' . esc_html( $head ) . '.</strong> ' . esc_html( $line ) . '</li>';
			$h .= '</ol>' . $btn( $demo, 'Try the demo' ) . '</div></section>';
		}

		if ( ! $in && class_exists( 'WB_Optin' ) && '' !== ( $opt = WB_Optin::form( 'welcome' ) ) ) $h .= '<section class="wb-sec wb-sec--white"><div class="wb-wrap">' . $opt . '</div></section>';   // 1.7.2: news, by choice
		$h .= '<footer class="wb-sec wb-sec--sink wb-welcome-foot"><div class="wb-wrap"><span>' . esc_html( $name ) . ' <span class="wb-builton">· built on B2BGro</span></span>' . ( $in ? '' : '<a href="' . esc_url( $signin ) . '">Sign in</a>' ) . '</div></footer>';
		return $h;
	}
}
