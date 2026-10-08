<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Demo_Seed — a year of trading for Demo Technical Supplies (1.2.0, Zina: "deeper seed data").
 *
 * plan() is pure and deterministic: given today's date it returns the whole script (masters, stock
 * arriving, every quote with its customer, lines and what happened to it, the money that came in,
 * the staff's weeks). run() plays the script through the real engines with the business clock
 * (wb_now) set to each event's date, so every document is numbered in sequence, priced by both
 * checks, released by the gate, ledgered and PDF'd exactly as a real one would be. Nothing is
 * typed into a table that an engine should have written.
 *
 * The shape of the year: coatings peak September to November, adhesives dip over December and
 * January; two customers pay early, two pay late, one is on hold; a few things are left waiting
 * for the demo visitor to do (a price to approve, a write-off to decide, a credit note to approve,
 * a stocktake to check, timesheets to approve, a pay run to check, a quote to chase).
 */
class WB_Demo_Seed {

	/** The clock the engines read while the seed runs ('' = real time). */
	public static string $clock = '';

	public static function tick( string $date, string $time = '09:00:00' ): void {
		self::$clock = $date . ' ' . $time;
	}

	/* ------------------------------------------------------------------ the script (pure) */

	const CATEGORIES = [
		'Adhesives' => [ 'margin' => 25, 'spec' => [ [ 'label' => 'Viscosity', 'unit' => 'mPa·s' ], [ 'label' => 'Open time', 'unit' => 'min' ], [ 'label' => 'Full cure', 'unit' => 'h' ] ], 'season' => [ 1.0, 0.8, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.1, 1.1, 1.0, 0.7 ] ],
		'Sealants'  => [ 'margin' => 25, 'spec' => [ [ 'label' => 'Movement capability', 'unit' => '%' ], [ 'label' => 'Skin time', 'unit' => 'min' ] ], 'season' => [ 1.0, 1.0, 1.0, 1.0, 0.9, 0.9, 0.9, 1.0, 1.1, 1.2, 1.1, 0.9 ] ],
		'Fasteners' => [ 'margin' => 20, 'spec' => [ [ 'label' => 'Thread', 'unit' => '' ], [ 'label' => 'Grade', 'unit' => '' ], [ 'label' => 'Finish', 'unit' => '' ] ], 'season' => [ 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 0.8 ] ],
		'Coatings'  => [ 'margin' => 30, 'spec' => [ [ 'label' => 'Coverage', 'unit' => 'm²/L' ], [ 'label' => 'Dry film thickness', 'unit' => 'µm' ], [ 'label' => 'Recoat', 'unit' => 'h' ] ], 'season' => [ 0.7, 0.7, 0.8, 0.8, 0.8, 0.8, 0.9, 1.1, 1.5, 1.7, 1.5, 0.9 ] ],
	];

	/** sku, name, category, unit, pack, cost, list, reorder point, reorder qty, spec values, opening stock */
	const PRODUCTS = [
		[ 'ADH-CT5',   'Contact adhesive 5 L',             'Adhesives', 'each', 1,   210,   349,   20, 60,  [ '3200', '15', '24' ],   240 ],
		[ 'ADH-EP200', 'Epoxy adhesive 200 ml (2-part)',   'Adhesives', 'each', 1,   84.5,  129,   30, 120, [ '12000', '20', '24' ],  360 ],
		[ 'ADH-PVA20', 'Wood glue PVA 20 L',               'Adhesives', 'each', 1,   380,   595,   10, 30,  [ '4500', '10', '24' ],   90 ],
		[ 'ADH-CA50',  'Cyanoacrylate 50 g',               'Adhesives', 'each', 12,  28,    49.9,  60, 240, [ '100', '1', '24' ],     720 ],
		[ 'SEA-SIL300','Silicone sealant 300 ml',          'Sealants',  'each', 24,  38,    69.9,  100, 240, [ '25', '15' ],          960 ],
		[ 'SEA-PU600', 'PU sealant 600 ml',                'Sealants',  'each', 20,  72,    129,   40, 120, [ '25', '45' ],           480 ],
		[ 'SEA-MS290', 'MS polymer 290 ml',                'Sealants',  'each', 12,  58,    99,    40, 120, [ '20', '10' ],           360 ],
		[ 'FST-HN16',  'Hex nut M16 (box of 100)',         'Fasteners', 'box',  1,   240,   410.4, 20, 60,  [ 'M16', '8', 'Zinc' ],   180 ],
		[ 'FST-HB1680','Hex bolt M16 × 80 (box of 50)',    'Fasteners', 'box',  1,   410,   690,   15, 45,  [ 'M16', '8.8', 'Zinc' ], 135 ],
		[ 'FST-WS12',  'Wood screw 5 × 60 (box of 200)',   'Fasteners', 'box',  1,   96,    165,   30, 90,  [ '5 mm', '', 'Yellow passivated' ], 270 ],
		[ 'FST-AN12',  'Anchor bolt M12 × 110 (box of 25)','Fasteners', 'box',  1,   330,   545,   10, 30,  [ 'M12', '5.8', 'Hot-dip galv.' ], 90 ],
		[ 'COT-ZR85',  'Zinc-rich primer 5 L',             'Coatings',  'each', 1,   520,   890,   12, 36,  [ '8', '75', '4' ],       120 ],
		[ 'COT-EP20',  'Epoxy floor coating 20 L',         'Coatings',  'each', 1,   1480,  2390,  6,  18,  [ '5', '250', '12' ],     60 ],
		[ 'COT-PU5',   'Polyurethane topcoat 5 L',         'Coatings',  'each', 1,   610,   995,   10, 30,  [ '10', '50', '6' ],      100 ],
		[ 'COT-BT30',  'Bituminous roof coating 30 L',     'Coatings',  'each', 1,   690,   1150,  8,  24,  [ '2', '400', '24' ],     72 ],
		[ 'COT-HB200', 'Heat-resistant black 200 ml',      'Coatings',  'each', 6,   45,    79,    40, 120, [ '6', '40', '1' ],       300 ],
	];

	/** name, terms days, credit limit, tier, region, industry, segment, pay habit (days after invoice; 0 = cash), weight (how often they buy), status */
	const CUSTOMERS = [
		[ 'Karoo Agri (Pty) Ltd',        30, 80000,  'Distributor', 'Western Cape',  'Agriculture',   'Distributor', 22, 5, 'open' ],
		[ 'Garden Route Builders',       30, 50000,  'Trade',       'Western Cape',  'Construction',  'Contractor',  12, 5, 'open' ],
		[ 'Durban Steel Fabricators',    30, 60000,  'Trade',       'KwaZulu-Natal', 'Fabrication',   'Contractor',  44, 4, 'open' ],
		[ 'Polokwane Plant Hire',        30, 40000,  'Project',     'Limpopo',       'Plant hire',    'Project',     35, 3, 'open' ],
		[ 'Highveld Glazing',            30, 20000,  'Trade',       'Gauteng',       'Glazing',       'Contractor',  60, 2, 'on_hold' ],
		[ 'Bayside Hardware',            0,  0,      'Trade',       'Western Cape',  'Retail',        'Reseller',    0,  4, 'open' ],
		[ 'Namaqua Roofing',             30, 30000,  'Project',     'Northern Cape', 'Roofing',       'Contractor',  18, 3, 'open' ],
		[ 'Mossel Bay Marine Services',  0,  0,      'Trade',       'Western Cape',  'Marine',        'Workshop',    0,  2, 'open' ],
	];

	/** customer index → contacts [ first, last, role, email, primary ] */
	const CONTACTS = [
		0 => [ [ 'Pieter', 'van Wyk', 'Buyer', 'pieter@karooagri.example', 1 ], [ 'Anel', 'Botha', 'Accounts', 'accounts@karooagri.example', 0 ] ],
		1 => [ [ 'Sipho', 'Dlamini', 'Site manager', 'sipho@grbuilders.example', 1 ] ],
		2 => [ [ 'Rajesh', 'Naidoo', 'Procurement', 'rajesh@durbansteel.example', 1 ], [ 'Thuli', 'Zulu', 'Workshop lead', 'thuli@durbansteel.example', 0 ] ],
		3 => [ [ 'Lerato', 'Mokoena', 'Owner', 'lerato@polokwaneplant.example', 1 ] ],
		4 => [ [ 'Johan', 'Pretorius', 'Owner', 'johan@highveldglazing.example', 1 ] ],
		5 => [ [ 'Nadia', 'Abrahams', 'Store manager', 'nadia@baysidehardware.example', 1 ] ],
		6 => [ [ 'Willem', 'Kotze', 'Foreman', 'willem@namaquaroofing.example', 1 ] ],
		7 => [ [ 'Dumisani', 'Ndlovu', 'Workshop manager', 'dumi@mbmarine.example', 1 ] ],
	];

	/** first, last, job, department, started (months ago), type, hours, pay type, pay, dob */
	const STAFF = [
		[ 'Thandi',  'Mokoena',  'Warehouse lead',     'Warehouse', 30, 'permanent', 45, 'monthly', 24500, '1988-05-14' ],
		[ 'Johan',   'Steyn',    'Sales representative','Sales',    22, 'permanent', 45, 'monthly', 28000, '1985-11-02' ],
		[ 'Nomsa',   'Khumalo',  'Accounts clerk',     'Accounts',  18, 'permanent', 40, 'monthly', 21000, '1992-03-27' ],
		[ 'Ben',     'Jacobs',   'Driver',             'Warehouse', 14, 'permanent', 45, 'hourly',  112,   '1979-08-09' ],
		[ 'Zanele',  'Dube',     'Picker and packer',  'Warehouse', 7,  'contract',  45, 'hourly',  98,    '1999-01-21' ],
		[ 'Francois','du Toit',  'Sales representative','Sales',    4,  'permanent', 45, 'monthly', 26000, '1990-06-30' ],
	];

	const SUPPLIERS = [
		[ 'Bondex Chemicals', 'Sipho Mahlangu', 'orders@bondex.example', '021 555 0100', "4 Industrial Way\nEpping 7460", 7, 30, [ 'Adhesives', 'Sealants' ] ],
		[ 'Cape Fastener Co', 'Marlene Fourie', 'sales@capefastener.example', '021 555 0188', "18 Bolt Street\nBellville 7530", 5, 30, [ 'Fasteners' ] ],
		[ 'Coastal Coatings', 'Ahmed Khan', 'orders@coastalcoatings.example', '031 555 0144', "22 Harbour Road\nPinetown 3610", 10, 45, [ 'Coatings' ] ],
		[ 'Rand Protective Paints', 'Lindiwe Sithole', 'trade@randpaints.example', '011 555 0199', "7 Mill Street\nIsando 1600", 14, 30, [ 'Coatings' ] ],
	];

	/** A small deterministic generator (the same seed gives the same year on every site). */
	private static int $r = 20261008;
	private static function rnd( int $lo, int $hi ): int {
		self::$r = ( self::$r * 1103515245 + 12345 ) & 0x7fffffff;
		return $lo + ( self::$r >> 8 ) % ( $hi - $lo + 1 );
	}

	/** Days after $date, skipping weekends. */
	public static function workday( string $date, int $add ): string {
		$t = strtotime( $date . ' 12:00:00 UTC' );
		while ( $add > 0 ) { $t += 86400; if ( (int) gmdate( 'N', $t ) < 6 ) $add--; }
		return gmdate( 'Y-m-d', $t );
	}

	/**
	 * The year's script, from $today back twelve months. Pure. Returns:
	 *  quotes: [ [ date, customer index, lines [ [ product index, qty ] ], fate (accepted|sent|declined|draft), paid_on|null, manual (product index => price) ] ]
	 *  pos:    [ [ date, supplier index, lines [ [ product index, qty ] ], received (bool) ] ]
	 */
	public static function plan( string $today ): array {
		self::$r = 20261008;
		$start  = gmdate( 'Y-m-01', strtotime( $today . ' 12:00:00 UTC' ) - 365 * 86400 );
		$quotes = [];
		$cats   = array_keys( self::CATEGORIES );
		$weights = [];
		foreach ( self::CUSTOMERS as $i => $c ) for ( $k = 0; $k < $c[8]; $k++ ) $weights[] = $i;
		for ( $m = 0; $m < 13; $m++ ) {
			$month = gmdate( 'Y-m', strtotime( $start . ' +' . $m . ' months' ) );
			if ( $month > substr( $today, 0, 7 ) ) break;
			$mi    = (int) substr( $month, 5, 2 ) - 1;
			$n     = 4 + self::rnd( 0, 3 ) + ( $mi >= 8 && $mi <= 10 ? 3 : 0 );
			for ( $q = 0; $q < $n; $q++ ) {
				$day   = self::rnd( 0 === $m ? 8 : 1, 27 );   // the first month: stock arrives in the first week
				$date  = sprintf( '%s-%02d', $month, $day );
				if ( (int) gmdate( 'N', strtotime( $date ) ) >= 6 ) $date = self::workday( $date, 1 );   // never a weekend
				if ( $date > $today ) continue;
				$ci    = $weights[ self::rnd( 0, count( $weights ) - 1 ) ];
				if ( 'on_hold' === self::CUSTOMERS[ $ci ][9] && $m > 4 ) $ci = 1;   // the held account stopped buying after the first months
				$lines = [];
				$nl    = self::rnd( 1, 3 );
				for ( $l = 0; $l < $nl; $l++ ) {
					// pick a category by season, then a product in it
					$cat = $cats[ self::rnd( 0, 3 ) ];
					if ( self::rnd( 1, 100 ) > (int) ( 100 * self::CATEGORIES[ $cat ]['season'][ $mi ] / 1.7 ) ) $cat = $cats[ self::rnd( 0, 2 ) ];
					$pool = array_keys( array_filter( self::PRODUCTS, fn( $p ) => $p[2] === $cat ) );
					$pi   = $pool[ self::rnd( 0, count( $pool ) - 1 ) ];
					$qty  = max( 1, (int) round( self::rnd( 2, 12 ) * ( self::PRODUCTS[ $pi ][4] > 1 ? 1 : 1.5 ) ) );
					$lines[ $pi ] = ( $lines[ $pi ] ?? 0 ) + $qty;
				}
				$lines = array_map( fn( $k, $v ) => [ $k, $v ], array_keys( $lines ), $lines );
				$roll  = self::rnd( 1, 100 );
				$age   = (int) ( ( strtotime( $today ) - strtotime( $date ) ) / 86400 );
				$fate  = $roll <= 82 ? 'accepted' : ( $roll <= 92 ? 'declined' : 'sent' );
				if ( $age <= 10 && $roll > 60 ) $fate = 'sent';   // the newest quotes are still out
				$habit   = self::CUSTOMERS[ $ci ][7];
				$paid_on = null;
				if ( 'accepted' === $fate ) {
					$inv_date = self::workday( $date, 2 );
					$paid_on  = $habit > 0 ? gmdate( 'Y-m-d', strtotime( $inv_date . ' +' . ( $habit + self::rnd( -5, 8 ) ) . ' days' ) ) : $inv_date;
					if ( $paid_on > $today ) $paid_on = null;   // not yet paid
					if ( 'on_hold' === self::CUSTOMERS[ $ci ][9] ) $paid_on = $age > 90 ? $paid_on : null;
				}
				$quotes[] = [ 'date' => $date, 'customer' => $ci, 'lines' => $lines, 'fate' => $fate, 'paid_on' => $paid_on, 'manual' => [] ];
			}
		}
		usort( $quotes, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
		// the newest one: a below-floor price typed on a draft, for the approve queue
		if ( $quotes ) { $last = count( $quotes ) - 1; $quotes[ $last ]['fate'] = 'draft'; $pi = $quotes[ $last ]['lines'][0][0]; $quotes[ $last ]['manual'] = [ $pi => round( self::PRODUCTS[ $pi ][5] * 1.08, 2 ) ]; }
		// two old unpaid invoices for the overdue list, from the slow payers
		$n = 0;
		for ( $i = count( $quotes ) - 1; $i >= 0 && $n < 2; $i-- ) {
			$age = (int) ( ( strtotime( $today ) - strtotime( $quotes[ $i ]['date'] ) ) / 86400 );
			if ( 'accepted' === $quotes[ $i ]['fate'] && $age > 50 && $age < 100 && in_array( $quotes[ $i ]['customer'], [ 2, 3 ], true ) && null !== $quotes[ $i ]['paid_on'] ) { $quotes[ $i ]['paid_on'] = null; $n++; }
		}
		// stock arrives: two orders at the start, top-ups mid-year, one open now
		$pos = [];
		$pos[] = [ 'date' => self::workday( $start, 1 ), 'supplier' => 0, 'lines' => [ [ 0, 240 ], [ 1, 360 ], [ 2, 180 ], [ 3, 720 ], [ 4, 960 ], [ 5, 480 ], [ 6, 360 ] ], 'received' => true ];
		$pos[] = [ 'date' => self::workday( $start, 2 ), 'supplier' => 1, 'lines' => [ [ 7, 180 ], [ 8, 135 ], [ 9, 270 ], [ 10, 90 ] ], 'received' => true ];
		$pos[] = [ 'date' => self::workday( $start, 3 ), 'supplier' => 2, 'lines' => [ [ 11, 120 ], [ 12, 60 ], [ 13, 100 ], [ 14, 72 ], [ 15, 300 ] ], 'received' => true ];
		$mid = gmdate( 'Y-m-d', strtotime( $start . ' +6 months' ) );
		if ( $mid <= $today ) { $pos[] = [ 'date' => self::workday( $mid, 3 ), 'supplier' => 0, 'lines' => [ [ 0, 120 ], [ 1, 240 ], [ 4, 480 ], [ 5, 240 ] ], 'received' => true ]; $pos[] = [ 'date' => self::workday( $mid, 8 ), 'supplier' => 3, 'lines' => [ [ 11, 72 ], [ 13, 60 ], [ 14, 48 ] ], 'received' => true ]; }
		$pos[] = [ 'date' => self::workday( $today, -0 ) === $today ? gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ) : $today, 'supplier' => 2, 'lines' => [ [ 12, 18 ], [ 14, 24 ] ], 'received' => false ];
		return [ 'start' => $start, 'quotes' => $quotes, 'pos' => $pos ];
	}

	/* ------------------------------------------------------------------ playing it (WordPress) */

	/** Build the whole year. $me = the administrator's own staff id (never captured). Returns counts. */
	public static function run( int $me ): array {
		$today = current_time( 'Y-m-d' );
		$plan  = self::plan( $today );
		$out   = [ 'customers' => 0, 'products' => 0, 'quotes' => 0, 'orders' => 0, 'invoices' => 0, 'payments' => 0, 'purchase_orders' => 0 ];
		add_filter( 'wb_now', [ __CLASS__, 'now' ] );
		try {
			self::tick( self::workday( $plan['start'], 0 ), '08:00:00' );
			$tag = ' (demo)';

			// ---- masters
			$tiers = [];
			foreach ( [ [ 'Trade', 10, 'true' ], [ 'Distributor', 15, 'false' ], [ 'Project', 7.5, 'false' ] ] as [ $n, $d, $def ] ) $tiers[ $n ] = (int) WB_CCT::insert( 'wb_price_tiers', [ 'name' => $n . $tag, 'discount_pct' => $d, 'is_default' => $def ] );
			$cats = [];
			foreach ( self::CATEGORIES as $n => $c ) $cats[ $n ] = (int) WB_CCT::insert( 'wb_product_categories', [ 'name' => $n . $tag, 'min_margin_pct' => $c['margin'], 'spec_template_json' => json_encode( $c['spec'], JSON_UNESCAPED_UNICODE ) ] );
			$sups = [];
			foreach ( self::SUPPLIERS as $i => $s ) $sups[ $i ] = (int) WB_CCT::insert( 'wb_suppliers', [ 'name' => $s[0] . $tag, 'contact_name' => $s[1], 'email' => $s[2], 'phone' => $s[3], 'address' => $s[4], 'lead_time_days' => $s[5], 'payment_terms_days' => $s[6], 'currency' => 'ZAR' ] );
			$prods = [];
			foreach ( self::PRODUCTS as $i => $p ) {
				$spec = [];
				foreach ( self::CATEGORIES[ $p[2] ]['spec'] as $j => $row ) $spec[] = $row + [ 'value' => $p[9][ $j ] ?? '' ];
				$sup_i = array_key_first( array_filter( self::SUPPLIERS, fn( $s ) => in_array( $p[2], $s[7], true ) ) ) ?? 0;
				$prods[ $i ] = (int) WB_CCT::insert( 'wb_products', [ 'sku' => 'DEMO-' . $p[0], 'name' => $p[1] . $tag, 'category_id' => $cats[ $p[2] ], 'unit' => $p[3], 'pack_size' => $p[4], 'cost_price' => $p[5], 'list_price' => $p[6],
					'reorder_point' => $p[7], 'reorder_qty' => $p[8], 'lead_time_days' => self::SUPPLIERS[ $sup_i ][5], 'preferred_supplier_id' => $sups[ $sup_i ], 'batch_tracked' => 'no', 'status' => 'active', 'spec_json' => json_encode( $spec, JSON_UNESCAPED_UNICODE ),
					'price_valid_from' => substr( $plan['start'], 0, 4 ) . '-01-01', 'price_valid_to' => substr( $today, 0, 4 ) . '-12-31', 'barcode' => '600' . sprintf( '%010d', 1234560 + $i ) ] );
			}
			$out['products'] = count( $prods );
			$custs = [];
			foreach ( self::CUSTOMERS as $i => $c ) {
				$custs[ $i ] = (int) WB_CCT::insert( 'wb_customers', [ 'name' => $c[0] . $tag, 'payment_terms_days' => $c[1], 'credit_limit' => $c[2], 'price_tier_id' => $tiers[ $c[3] ], 'currency' => 'ZAR', 'region' => $c[4], 'industry' => $c[5], 'segment' => $c[6],
					'account_status' => 'open', 'journey_stage' => 'lead', 'vat_number' => '4' . sprintf( '%09d', 123456780 + $i ), 'billing_address' => $c[0] . "\n" . $c[4], 'trading_name' => '' ] );
				foreach ( self::CONTACTS[ $i ] ?? [] as $k ) WB_CCT::insert( 'wb_contacts', [ 'customer_id' => $custs[ $i ], 'first_name' => $k[0], 'last_name' => $k[1] . $tag, 'role_title' => $k[2], 'email' => $k[3], 'is_primary' => $k[4] ? 'true' : 'false', 'receives_invoices' => 'true', 'receives_datasheets' => 'true', 'marketing_optin' => 'true' ], 'contact_added' );
			}
			$out['customers'] = count( $custs );
			$staff = [];
			foreach ( self::STAFF as $i => $s ) $staff[ $i ] = (int) WB_CCT::insert( 'wb_staff', [ 'first_name' => $s[0], 'last_name' => $s[1] . $tag, 'job_title' => $s[2], 'department' => $s[3], 'started_at' => gmdate( 'Y-m-d', strtotime( $today . ' -' . $s[4] . ' months' ) ), 'employment_type' => $s[5], 'hours_per_week' => $s[6], 'days_per_week' => 5, 'status' => 'active', 'employee_no' => 'E' . sprintf( '%04d', 100 + $i ) ], 'staff_added' );
			// customer pricing: an approved rule for the big account, a season rule for the plant hire, one draft
			WB_CCT::insert( 'wb_price_rules', [ 'customer_id' => $custs[0], 'category_id' => $cats['Adhesives'], 'rule_type' => 'pct_off_list', 'value' => 18, 'valid_from' => $plan['start'], 'status' => 'approved', 'approved_by_staff_id' => $me, 'approved_at' => wb_now() ] );
			WB_CCT::insert( 'wb_price_rules', [ 'customer_id' => $custs[3], 'product_id' => $prods[11], 'rule_type' => 'fixed_price', 'value' => 640, 'valid_from' => $plan['start'], 'valid_to' => $today, 'status' => 'approved', 'approved_by_staff_id' => $me, 'approved_at' => wb_now() ] );
			WB_CCT::insert( 'wb_price_rules', [ 'customer_id' => $custs[1], 'product_id' => $prods[4], 'rule_type' => 'fixed_price', 'value' => 59.5, 'valid_from' => $today, 'status' => 'draft' ] );
			foreach ( [ [ 'Quotes sent', 'role', 'wb_sales', 'quotes_sent', 20, 'quotes' ], [ 'Quote win rate', 'role', 'wb_sales', 'quote_win_rate', 60, '%' ], [ 'On-time delivery', 'role', 'wb_warehouse', 'on_time_delivery', 95, '%' ] ] as [ $n, $a, $rk, $ms, $t, $u ] ) {
				WB_CCT::insert( 'wb_kpis', [ 'name' => $n . $tag, 'applies_to' => $a, 'role_key' => $rk, 'measure' => $ms, 'target' => $t, 'unit' => $u, 'period' => 'monthly' ] );
			}

			// ---- the year, event by event
			$events = [];
			foreach ( $plan['pos'] as $i => $po ) $events[] = [ $po['date'], 0, 'po', $i ];
			foreach ( $plan['quotes'] as $i => $q ) $events[] = [ $q['date'], 1, 'quote', $i ];
			usort( $events, fn( $a, $b ) => strcmp( $a[0] . $a[1], $b[0] . $b[1] ) );
			$bank = [];   // the statement lines, imported at the end
			foreach ( $events as [ $date, , $kind, $i ] ) {
				self::tick( $date, sprintf( '%02d:%02d:00', 8 + self::rnd( 0, 8 ), self::rnd( 0, 59 ) ) );
				if ( 'po' === $kind ) {
					$po = $plan['pos'][ $i ];
					$id = WB_Stock::create_po( $sups[ $po['supplier'] ], array_map( fn( $l ) => [ 'product_id' => $prods[ $l[0] ], 'qty' => $l[1] ], $po['lines'] ), self::workday( $date, self::SUPPLIERS[ $po['supplier'] ][5] ) );
					if ( is_wp_error( $id ) ) continue;
					$out['purchase_orders']++;
					self::tick( self::workday( $date, 1 ) );
					WB_Stock::set_po_status( (int) $id, 'sent' );
					if ( $po['received'] ) {
						self::tick( self::workday( $date, self::SUPPLIERS[ $po['supplier'] ][5] ), '11:00:00' );
						foreach ( WB_CCT::find( 'wb_po_lines', [ 'po_id' => (int) $id ] ) as $l ) WB_Stock::receive_po_line( (int) $l['_ID'], (float) $l['qty_ordered'], 0, 'Main store' );
					}
					continue;
				}
				$q     = $plan['quotes'][ $i ];
				$lines = [];
				foreach ( $q['lines'] as [ $pi, $qty ] ) $lines[] = [ 'product_id' => $prods[ $pi ], 'qty' => $qty ] + ( isset( $q['manual'][ $pi ] ) ? [ 'manual_price' => $q['manual'][ $pi ] ] : [] );
				$qid = WB_Orders::create_quote( $custs[ $q['customer'] ], $lines );
				if ( is_wp_error( $qid ) ) continue;
				$out['quotes']++;
				if ( 'draft' === $q['fate'] ) continue;
				self::tick( $date, '15:30:00' );
				if ( is_wp_error( WB_Orders::send_quote( (int) $qid ) ) ) continue;
				if ( 'sent' === $q['fate'] ) continue;
				$c   = self::CUSTOMERS[ $q['customer'] ];
				$who = ( self::CONTACTS[ $q['customer'] ][0][0] ?? 'Buyer' ) . ' ' . ( self::CONTACTS[ $q['customer'] ][0][1] ?? '' );
				if ( 'declined' === $q['fate'] ) { self::tick( self::workday( $date, 3 ) ); WB_Orders::decline_quote( (int) $qid, 'Went with another supplier this time.' ); continue; }
				self::tick( self::workday( $date, 2 ), '10:15:00' );
				$oid = WB_Orders::accept_quote( (int) $qid, $who );
				if ( is_wp_error( $oid ) ) continue;
				$out['orders']++;
				$inv = WB_Invoices::for_order( (int) $oid );
				if ( $inv ) $out['invoices']++;
				// cash customers pay at the counter before anything leaves; terms customers pay by EFT later
				if ( 0 === $c[1] && $inv ) { WB_Payments::record_receipt( (int) $inv['_ID'], (float) $inv['total'], self::rnd( 0, 1 ) ? 'card' : 'eft', 'SLIP-' . sprintf( '%04d', 1000 + $i ) ); $out['payments']++; }
				elseif ( $inv && $q['paid_on'] ) $bank[] = [ $q['paid_on'], (float) $inv['total'], $c[0], (string) $inv['invoice_number'], self::rnd( 1, 100 ) <= 85 ];
				self::tick( self::workday( $date, 3 ), '09:40:00' );
				$rel = WB_Orders::release( (int) $oid );
				if ( is_wp_error( $rel ) ) continue;   // the gate said no (credit, overdue): it stays waiting, which is the point
				$o    = WB_CCT::get( 'wb_orders', (int) $oid );
				$qtys = [];
				foreach ( WB_CCT::find( 'wb_order_lines', [ 'order_id' => (int) $oid ] ) as $l ) $qtys[ (int) $l['_ID'] ] = (float) $l['qty_ordered'];
				$age  = (int) ( ( strtotime( $today ) - strtotime( $date ) ) / 86400 );
				if ( $age <= 4 ) continue;   // the newest orders are still being picked
				self::tick( self::workday( $date, 4 ), '14:00:00' );
				$dn = WB_Orders::issue_delivery_note( (int) $oid, $qtys, [ 'type' => in_array( $q['customer'], [ 5, 7 ], true ) ? 'collection' : 'delivery', 'vehicle_or_courier' => 'Own bakkie CA 123-456' ] );
				if ( is_wp_error( $dn ) ) continue;
				if ( $age > 12 ) { self::tick( self::workday( $date, 5 ) ); WB_Orders::close( (int) $oid ); }
			}

			// ---- the money: one statement for the year, as the bank would give it
			self::tick( $today, '16:00:00' );
			usort( $bank, fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
			$csv = "Date,Amount,Description,Reference\n";
			foreach ( $bank as $k => [ $d, $amt, $name, $num, $with_ref ] ) {
				$desc = 'EFT ' . strtoupper( substr( preg_replace( '/[^A-Za-z ]/', '', $name ), 0, 18 ) );
				$ref  = $with_ref ? $num : ( 0 === $k % 7 ? 'PAYMENT' : 'INV ' . substr( $num, -3 ) );
				$csv .= $d . ',' . number_format( $amt, 2, '.', '' ) . ',' . $desc . ',' . $ref . "\n";
				if ( 0 === $k % 11 ) $csv .= $d . ',-' . number_format( 1250 + $k * 7, 2, '.', '' ) . ',DEBIT ORDER INSURANCE,' . "\n";   // money out, ignored by the import
			}
			$imp = WB_Payments::import( $csv, 'fnb', 'demo-statement.csv' );
			if ( ! is_wp_error( $imp ) ) $out['payments'] += (int) ( $imp['credits'] ?? 0 );

			// ---- things left for the visitor to do
			self::tick( self::workday( $today, -1 ) <= $today ? gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) : $today, '11:20:00' );
			WB_Stock::request_adjustment( $prods[15], -6, 'write_off', 'Six tins dented in the rack, lids split.' );
			$paid = WB_CCT::first( 'wb_invoices', [ 'status' => 'paid' ], [ 'orderby' => '_ID', 'order' => 'DESC' ] );
			if ( $paid ) { $l = WB_CCT::json( $paid['lines_json'] )[0] ?? null; if ( $l ) WB_Invoices::request_credit_note( (int) $paid['_ID'], 'return', [ [ 'product_id' => (int) $l['product_id'], 'qty' => 1, 'unit_price' => (float) $l['unit_price'] ] ], true ); }
			$st = WB_Stock::start_stocktake( 'Main store' );
			if ( ! is_wp_error( $st ) ) foreach ( [ 4, 5, 7, 15 ] as $k => $pi ) WB_Stock::record_count( (int) $st, $prods[ $pi ], WB_Stock::on_hand( $prods[ $pi ] ) - ( 2 === $k ? 1 : 0 ) );
			// timesheets: three people, the last two weeks; the first week approved, the second waiting
			$days = [];
			for ( $d = 14; $d >= 1; $d-- ) { $x = gmdate( 'Y-m-d', strtotime( $today . ' -' . $d . ' days' ) ); if ( (int) gmdate( 'N', strtotime( $x ) ) < 6 ) $days[] = $x; }
			foreach ( [ 0, 3, 4 ] as $si ) {
				foreach ( $days as $k => $x ) {
					self::tick( $x, '17:05:00' );
					$ts = WB_Staff::save_timesheet( $x, '07:30', '16:30', 60, 'warehouse', 0, $staff[ $si ] );
					if ( is_wp_error( $ts ) ) continue;
					WB_Staff::submit_timesheet( (int) $ts );
					if ( $k < count( $days ) - 5 ) WB_Staff::decide_timesheet( (int) $ts, true );
				}
			}
			self::tick( $today, '08:30:00' );
			$annual = WB_CCT::first( 'wb_leave_types', [ 'code' => 'annual' ] );
			if ( $annual ) {
				$lv = WB_Staff::request_leave( (int) $annual['_ID'], self::workday( $today, 15 ), self::workday( $today, 17 ), 'Family wedding in Lusikisiki.', 0, $staff[1] );
				WB_Staff::request_leave( (int) $annual['_ID'], self::workday( $today, 30 ), self::workday( $today, 34 ), '', 0, $staff[2] );
				$old = WB_Staff::request_leave( (int) $annual['_ID'], gmdate( 'Y-m-d', strtotime( $today . ' -40 days' ) ), gmdate( 'Y-m-d', strtotime( $today . ' -37 days' ) ), '', 0, $staff[0] );
				if ( ! is_wp_error( $old ) ) WB_Staff::decide_leave( (int) $old, true );
				unset( $lv );
			}
			WB_Staff::schedule_review( $staff[5], $me, gmdate( 'Y' ) . ' Q' . ceil( (int) gmdate( 'n' ) / 3 ) );
			// payroll: profiles for everyone and last month's run, drafted, waiting for a check
			if ( '' !== wb_enc_key() ) {
				foreach ( self::STAFF as $i => $s ) {
					WB_Payroll::save_profile( [ 'staff_id' => $staff[ $i ], 'pay_type' => $s[7], 'salary' => 'monthly' === $s[7] ? $s[8] : '', 'hourly_rate' => 'hourly' === $s[7] ? $s[8] : '', 'date_of_birth' => $s[9], 'tax_number' => '1' . sprintf( '%09d', 234567890 + $i ),
						'bank_account' => '62' . sprintf( '%09d', 10000000 + $i ), 'bank_branch_code' => '250655', 'medical_scheme_members' => $i % 3, 'retirement_contribution_pct' => 'monthly' === $s[7] ? 7.5 : 0, 'uif_exempt' => 'no', 'start_date' => gmdate( 'Y-m-d', strtotime( $today . ' -' . $s[4] . ' months' ) ) ] );
				}
				$last = gmdate( 'Y-m', strtotime( $today . ' -1 month' ) );
				$run  = WB_Payroll::create_run( $last, gmdate( 'Y-m-25', strtotime( $last . '-01' ) ) );
				if ( ! is_wp_error( $run ) ) $out['pay_runs'] = 1;
			}
			// a few human touches on the timeline
			self::tick( gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) ), '10:00:00' );
			WB_Orders::touchpoint( $custs[0], 'visit', 'Site visit: Pieter wants the epoxy floor coating on a standing order from March.' );
			WB_Orders::touchpoint( $custs[2], 'call', 'Rajesh says the September invoices are with their accounts department.' );
			WB_Orders::touchpoint( $custs[4], 'complaint', 'Johan unhappy about the hold; promised payment by Friday.' );
		} catch ( Throwable $e ) {
			error_log( 'WB_Demo_Seed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() );
			$out['stopped_at'] = mb_substr( $e->getMessage(), 0, 120 );
		} finally {
			remove_filter( 'wb_now', [ __CLASS__, 'now' ] );
			self::$clock = '';
		}
		// the nightly jobs, so the forecast, the stages and the overdue list are right from the first look
		WB_Invoices::sweep_overdue();
		WB_Demand::run_nightly();
		WB_Staff::measure_kpis( gmdate( 'Y-m-01' ), gmdate( 'Y-m-t' ) );
		return $out;
	}

	public static function now( string $real ): string {
		return '' !== self::$clock ? self::$clock : $real;
	}
}
