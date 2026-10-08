<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Welcome — the site's front page (0.3.1). What the system does, in plain words, and the way
 * in: Sign in, or straight to the workspace / the customer's account for someone already signed in.
 *
 * Served by WB_Workspace at / when the Setup switch "welcome page as the site home" is on (the
 * default). Public, so it is indexed; it holds no business data. Words here are for a person who
 * has never seen the system: no plugin, table or WordPress talk. The system name comes from Setup.
 *
 * content() is PURE apart from the name and the three links passed in — tested without WordPress.
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

	/**
	 * The page body. $links: [ 'signin' => url, 'workspace' => url|'' , 'portal' => url|'' ] — the
	 * two that are '' are not shown. $owner true adds the getting-started block.
	 */
	public static function content( string $name, array $links, bool $owner = false ): string {
		$btn = function ( string $href, string $words, bool $primary ) {
			return '<a class="wb-btn' . ( $primary ? '' : ' wb-btn-ghost' ) . '" href="' . esc_url( $href ) . '">' . esc_html( $words ) . '</a>';
		};
		$actions = '';
		if ( '' !== ( $links['workspace'] ?? '' ) ) $actions .= $btn( $links['workspace'], 'Open the workspace', true );
		if ( '' !== ( $links['portal'] ?? '' ) ) $actions .= $btn( $links['portal'], 'Your account', '' === ( $links['workspace'] ?? '' ) );
		if ( '' === $actions ) $actions = $btn( (string) ( $links['signin'] ?? '' ), 'Sign in', true );

		$h = '<section class="wb-hero"><p class="wb-eyebrow">' . esc_html( $name ) . '</p>'
			. '<h1>Quotes, orders, invoices, stock and pay. One place, nothing typed twice.</h1>'
			. '<p class="wb-lead">Built for businesses that sell to other businesses: each customer has its own prices, every product has a datasheet, and stock and cash are watched closely.</p>'
			. '<div class="wb-hero-actions">' . $actions . '</div></section>';

		$h .= '<section class="wb-areas" aria-labelledby="wb-what"><h2 id="wb-what">What it does</h2><div class="wb-area-grid">';
		foreach ( self::AREAS as [ $head, $line, $parts ] ) {
			$h .= '<div class="wb-area"><h3>' . esc_html( $head ) . '</h3><p>' . esc_html( $line ) . '</p><ul>';
			foreach ( $parts as $p ) $h .= '<li>' . esc_html( $p ) . '</li>';
			$h .= '</ul></div>';
		}
		$h .= '</div></section>';

		$h .= '<section class="wb-safety" aria-labelledby="wb-safe"><h2 id="wb-safe">How it keeps you safe</h2><div class="wb-safety-grid">';
		foreach ( self::SAFETY as [ $head, $line ] ) $h .= '<div class="wb-safe"><h3>' . esc_html( $head ) . '</h3><p>' . esc_html( $line ) . '</p></div>';
		$h .= '</div></section>';

		if ( $owner ) {
			$h .= '<section class="wb-start" aria-labelledby="wb-go"><h2 id="wb-go">Getting started</h2><ol>'
				. '<li><strong>Set up.</strong> Your company details, colours and logo. The checklist under System Settings shows what is still to do.</li>'
				. '<li><strong>Add what you sell and who you sell to.</strong> Products with a cost and a list price; customers with their terms.</li>'
				. '<li><strong>Give your team their screens.</strong> Under Settings, tick the screens each person works in. Everyone starts with only what they need.</li>'
				. '</ol>' . $btn( (string) ( $links['setup'] ?? $links['workspace'] ), 'Go to System Settings', true ) . '</section>';
		}

		$h .= '<footer class="wb-welcome-foot"><span>' . esc_html( $name ) . '</span>' . ( '' !== ( $links['workspace'] ?? '' ) || '' !== ( $links['portal'] ?? '' ) ? '' : '<a href="' . esc_url( (string) ( $links['signin'] ?? '' ) ) . '">Sign in</a>' ) . '</footer>';
		return $h;
	}
}
