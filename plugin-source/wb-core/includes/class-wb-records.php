<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Records — add and edit the master records, every field, from the schema (0.3.5).
 *
 * "Upload all data points" (Zina, 8 October) half one: before this, the add forms captured a
 * fraction of each master table (customers 10 of 17 fields, staff 2 of 15, price tiers without
 * the discount) and nothing could be edited. Now one form is drawn per table from
 * schema/wb-ccts.json plus the policy below, and the same policy says which columns a person may
 * type and which the engines own. 0.3.6's CSV import reads the same policy, so what can be typed
 * can be uploaded, and nothing else.
 *
 * Rules kept: capabilities not roles; column-safe writes through WB_CCT (ledgered, fail closed);
 * encrypted columns (*_enc) are typed in plain and stored through wb_enc(), shown as "on file",
 * blank on edit means keep; nothing is hard-deleted (archive stays a row action); the natural
 * key (code, name, email, employee number) refuses a duplicate with words.
 */
class WB_Records {

	/**
	 * The policy per table. fields: the columns a person types, in form order. Each may be a bare
	 * name (type from the schema) or name => [ label?, required?, ref => [cct, label column] | 'users',
	 * note?, placeholder?, options? (value => word), default? ]. key: the natural key(s) that must be
	 * unique among active rows. one: the word for a record. after: a callback run on the saved data.
	 */
	const TABLES = [
		'wb_customers' => [ 'cap' => 'wb_manage_customers', 'one' => 'customer', 'screen' => 'customers', 'key' => [ 'name' ], 'order' => 'name', 'fields' => [
			'name' => [ 'label' => 'Company name', 'required' => true, 'placeholder' => 'Karoo Agri (Pty) Ltd' ], 'trading_name' => [ 'label' => 'Trading as', 'placeholder' => 'Karoo Agri' ],
			'reg_number' => [ 'label' => 'Registration number', 'placeholder' => '2019/123456/07' ], 'vat_number' => [ 'label' => 'VAT number', 'placeholder' => '4123456789' ],
			'billing_address' => [ 'label' => 'Billing address', 'placeholder' => "12 Main Road\nOudtshoorn 6620" ], 'delivery_address' => [ 'label' => 'Delivery address', 'placeholder' => 'Leave blank if the same' ],
			'payment_terms_days' => [ 'label' => 'Days to pay', 'default' => 0, 'note' => '0 = pays before collection (cash). More than 0 also needs a credit limit.' ],
			'credit_limit' => [ 'label' => 'Credit limit', 'placeholder' => '50000' ], 'account_status' => [ 'label' => 'Account', 'default' => 'open' ],
			'price_tier_id' => [ 'label' => 'Price tier', 'ref' => [ 'wb_price_tiers', 'name' ] ], 'currency' => [ 'label' => 'Currency', 'default' => 'ZAR', 'placeholder' => 'ZAR' ],
			'rep_staff_id' => [ 'label' => 'Their rep', 'ref' => [ 'wb_staff', 'first_name' ] ], 'industry' => [ 'placeholder' => 'Agriculture' ], 'region' => [ 'placeholder' => 'Western Cape' ],
			'segment' => [ 'placeholder' => 'Distributor' ], 'notes' => [ 'label' => 'Notes', 'placeholder' => 'Collects on Fridays. Ask for Pieter.' ],
		] ],
		'wb_contacts' => [ 'cap' => 'wb_manage_customers', 'one' => 'contact', 'screen' => 'customers', 'key' => [ 'email' ], 'order' => 'last_name', 'fields' => [
			'customer_id' => [ 'label' => 'Customer', 'required' => true, 'ref' => [ 'wb_customers', 'name' ] ], 'first_name' => [ 'required' => true, 'placeholder' => 'Thandi' ], 'last_name' => [ 'placeholder' => 'Mokoena' ],
			'role_title' => [ 'label' => 'Role', 'placeholder' => 'Buyer' ], 'email' => [ 'placeholder' => 'thandi@karooagri.co.za' ], 'phone' => [ 'placeholder' => '082 123 4567' ],
			'is_primary' => [ 'label' => 'Main contact?' ], 'receives_invoices' => [ 'label' => 'Gets the invoices?' ], 'receives_datasheets' => [ 'label' => 'Gets datasheets?' ],
			'marketing_optin' => [ 'label' => 'Agreed to marketing?', 'note' => 'The date they agreed is recorded the first time this is switched on.' ], 'popia_consent_at' => [ 'label' => 'POPIA consent given on', 'type' => 'date' ],
		] ],
		'wb_products' => [ 'cap' => 'wb_manage_products', 'one' => 'product', 'screen' => 'products', 'key' => [ 'sku' ], 'order' => 'sku', 'fields' => [
			'sku' => [ 'label' => 'Product code', 'required' => true, 'placeholder' => 'ADH-EP200' ], 'name' => [ 'required' => true, 'placeholder' => 'Epoxy adhesive 200 ml' ],
			'category_id' => [ 'label' => 'Category', 'ref' => [ 'wb_product_categories', 'name' ] ], 'unit' => [ 'label' => 'Sold per', 'default' => 'each', 'options' => [ 'each' => 'each', 'kg' => 'kg', 'm' => 'metre', 'l' => 'litre', 'box' => 'box' ] ],
			'pack_size' => [ 'label' => 'Pack size', 'default' => 1 ], 'barcode' => [ 'placeholder' => '6001234567890' ],
			'cost_price' => [ 'label' => 'Cost price', 'placeholder' => '84.50' ], 'list_price' => [ 'label' => 'List price', 'placeholder' => '129.00' ],
			'min_margin_pct' => [ 'label' => 'Lowest margin %', 'note' => 'Leave blank to use the category or company default.', 'placeholder' => '25' ],
			'price_valid_from' => [ 'label' => 'Price valid from' ], 'price_valid_to' => [ 'label' => 'Price valid until' ],
			'reorder_point' => [ 'label' => 'Reorder when available falls to', 'placeholder' => '20' ], 'reorder_qty' => [ 'label' => 'Reorder quantity', 'placeholder' => '100' ],
			'lead_time_days' => [ 'label' => 'Supplier lead time (days)', 'placeholder' => '7' ], 'preferred_supplier_id' => [ 'label' => 'Usual supplier', 'ref' => [ 'wb_suppliers', 'name' ] ],
			'batch_tracked' => [ 'label' => 'Tracked by batch?', 'options' => [ 'no' => 'No', 'yes' => 'Yes' ], 'default' => 'no' ], 'shelf_life_days' => [ 'label' => 'Shelf life (days)', 'placeholder' => '365' ],
			'spec_json' => [ 'label' => 'Specification', 'note' => 'One row per line: label | value | unit. For example: Viscosity | 12000 | mPa·s', 'placeholder' => "Viscosity | 12000 | mPa·s\nCure time | 24 | h" ],
			'status' => [ 'label' => 'Status', 'default' => 'active' ],
		] ],
		'wb_product_categories' => [ 'cap' => 'wb_manage_products', 'one' => 'category', 'screen' => 'products', 'key' => [ 'name' ], 'order' => 'name', 'fields' => [
			'name' => [ 'required' => true, 'placeholder' => 'Adhesives' ], 'parent_id' => [ 'label' => 'Inside', 'ref' => [ 'wb_product_categories', 'name' ] ],
			'min_margin_pct' => [ 'label' => 'Lowest margin % for this category', 'placeholder' => '25' ],
			'spec_template_json' => [ 'label' => 'Specification rows every product here carries', 'note' => 'One per line: label | unit.', 'placeholder' => "Viscosity | mPa·s\nCure time | h" ],
		] ],
		'wb_price_tiers' => [ 'cap' => 'wb_manage_pricing', 'one' => 'price tier', 'screen' => 'products', 'key' => [ 'name' ], 'order' => 'name', 'fields' => [
			'name' => [ 'required' => true, 'placeholder' => 'Distributor' ], 'discount_pct' => [ 'label' => '% off the list price', 'required' => true, 'placeholder' => '12.5' ],
			'is_default' => [ 'label' => 'The tier new customers start on?' ],
		] ],
		'wb_suppliers' => [ 'cap' => 'wb_manage_purchasing', 'one' => 'supplier', 'screen' => 'purchasing', 'key' => [ 'name' ], 'order' => 'name', 'fields' => [
			'name' => [ 'required' => true, 'placeholder' => 'Bondex Chemicals' ], 'contact_name' => [ 'label' => 'Contact person', 'placeholder' => 'Sipho Dlamini' ],
			'email' => [ 'placeholder' => 'orders@bondex.co.za' ], 'phone' => [ 'placeholder' => '021 555 0100' ], 'address' => [ 'placeholder' => "4 Industrial Way\nEpping 7460" ],
			'lead_time_days' => [ 'label' => 'Lead time (days)', 'placeholder' => '7' ], 'payment_terms_days' => [ 'label' => 'Days we have to pay', 'placeholder' => '30' ],
			'currency' => [ 'default' => 'ZAR', 'placeholder' => 'ZAR' ], 'bank_details_enc' => [ 'label' => 'Bank details', 'note' => 'Encrypted on file. Only people who manage purchasing can see them.', 'placeholder' => 'FNB · 62012345678 · 250655' ],
		] ],
		'wb_staff' => [ 'cap' => 'wb_manage_staff', 'one' => 'staff member', 'screen' => 'staff', 'key' => [ 'employee_no' ], 'order' => 'last_name', 'fields' => [
			'first_name' => [ 'required' => true, 'placeholder' => 'Thandi' ], 'last_name' => [ 'required' => true, 'placeholder' => 'Mokoena' ], 'employee_no' => [ 'label' => 'Employee number', 'placeholder' => 'E0042' ],
			'job_title' => [ 'placeholder' => 'Warehouse supervisor' ], 'department' => [ 'placeholder' => 'Warehouse' ], 'manager_staff_id' => [ 'label' => 'Reports to', 'ref' => [ 'wb_staff', 'first_name' ] ],
			'wp_user_id' => [ 'label' => 'Login', 'ref' => 'users', 'note' => 'Approvals, adjustments and stocktakes carry staff names, so a login without a staff record cannot approve anything.' ],
			'started_at' => [ 'label' => 'Started on' ], 'ended_at' => [ 'label' => 'Left on' ], 'employment_type' => [ 'label' => 'Employment', 'default' => 'permanent' ],
			'hours_per_week' => [ 'label' => 'Hours a week', 'default' => 45 ], 'days_per_week' => [ 'label' => 'Days a week', 'default' => 5 ],
			'id_number_enc' => [ 'label' => 'ID number', 'note' => 'Encrypted on file.', 'placeholder' => '8001015009087' ], 'emergency_contact_enc' => [ 'label' => 'Emergency contact', 'note' => 'Encrypted on file.', 'placeholder' => 'Nomsa Mokoena · 083 555 0199' ],
			'status' => [ 'default' => 'active' ],
		] ],
		'wb_leave_types' => [ 'cap' => 'wb_manage_staff', 'one' => 'leave type', 'screen' => 'staff', 'key' => [ 'code' ], 'order' => 'name', 'fields' => [
			'name' => [ 'required' => true, 'placeholder' => 'Study leave' ], 'code' => [ 'required' => true, 'placeholder' => 'study', 'note' => 'annual, sick and family are the BCEA ones and keep their rules.' ],
			'days_per_year' => [ 'label' => 'Days per cycle', 'placeholder' => '5' ], 'cycle_months' => [ 'label' => 'Cycle (months)', 'default' => 12 ],
			'accrual' => [ 'default' => 'monthly' ], 'carry_over_max' => [ 'label' => 'Most that carries over', 'default' => 0 ],
		] ],
		'wb_kpis' => [ 'cap' => 'wb_run_reviews', 'one' => 'KPI', 'screen' => 'staff', 'key' => [ 'name' ], 'order' => 'name', 'fields' => [
			'name' => [ 'required' => true, 'placeholder' => 'Quote win rate' ], 'applies_to' => [ 'label' => 'Applies to', 'default' => 'role' ], 'role_key' => [ 'label' => 'Role', 'placeholder' => 'wb_sales' ],
			'staff_id' => [ 'label' => 'Or one person', 'ref' => [ 'wb_staff', 'first_name' ] ], 'measure' => [ 'required' => true ], 'target' => [ 'placeholder' => '35' ], 'unit' => [ 'placeholder' => '%' ], 'period' => [ 'default' => 'monthly' ],
		] ],
	];

	private static ?array $schema = null;

	public static function init(): void {
		add_filter( 'wb_panel_handlers', function ( array $h ): array { $h['record_save'] = [ __CLASS__, 'handle_save' ]; return $h; } );
		add_filter( 'wb_row_actions', [ __CLASS__, 'row_actions' ] );
	}

	/** The schema's field definitions for a table: [ name => def ]. */
	public static function schema( string $slug ): array {
		if ( null === self::$schema ) {
			$raw = json_decode( (string) file_get_contents( WB_PLUGIN_DIR . 'schema/wb-ccts.json' ), true );
			self::$schema = [];
			foreach ( (array) ( $raw['ccts'] ?? [] ) as $t => $def ) foreach ( (array) ( $def['fields'] ?? [] ) as $f ) self::$schema[ $t ][ $f['name'] ] = $f;
		}
		return self::$schema[ $slug ] ?? [];
	}

	/** The resolved fields for a table: [ name => [ type, label, required, ref, options, note, placeholder, default, enc ] ]. Pure. */
	public static function fields( string $slug ): array {
		$pol = self::TABLES[ $slug ] ?? null;
		if ( ! $pol ) return [];
		$sch = self::schema( $slug );
		$out = [];
		foreach ( $pol['fields'] as $k => $v ) {
			$name = is_int( $k ) ? (string) $v : (string) $k;
			$o    = is_int( $k ) ? [] : (array) $v;
			$def  = $sch[ $name ] ?? [ 'type' => 'text' ];
			$store = (string) $def['type'];                                   // how the column stores it
			$enc   = '_enc' === substr( $name, -4 );
			$type  = $enc ? 'text' : (string) ( $o['type'] ?? $store );        // what the person types
			$f    = [
				'type'        => $type,
				'store'       => $store,
				'label'       => (string) ( $o['label'] ?? WB_Render::label( $name ) ),
				'required'    => ! empty( $o['required'] ),
				'ref'         => $o['ref'] ?? null,
				'options'     => $o['options'] ?? ( 'select' === $store ? array_combine( $def['options'], array_map( [ 'WB_Render', 'words' ], $def['options'] ) ) : ( 'switcher' === $store ? [ 'false' => 'No', 'true' => 'Yes' ] : null ) ),
				'note'        => (string) ( $o['note'] ?? '' ),
				'placeholder' => (string) ( $o['placeholder'] ?? '' ),
				'default'     => $o['default'] ?? ( $def['default'] ?? '' ),
				'enc'         => $enc,
			];
			if ( null !== $f['ref'] || null !== $f['options'] ) $f['type'] = 'select';
			elseif ( ! in_array( $type, [ 'number', 'textarea', 'date', 'datetime-local', 'time', 'text' ], true ) ) $f['type'] = 'text';
			$out[ $name ] = $f;
		}
		return $out;
	}

	/** The form for a table: add (no row) or edit (a row). */
	public static function form( string $slug, ?array $row = null, array $hide = [] ): string {
		$pol = self::TABLES[ $slug ] ?? null;
		if ( ! $pol || ! current_user_can( $pol['cap'] ) ) return '';
		$h = WB_Render::form_open( 'record_save' ) . '<input type="hidden" name="record_cct" value="' . esc_attr( $slug ) . '"><input type="hidden" name="record_id" value="' . (int) ( $row['_ID'] ?? 0 ) . '">';
		foreach ( self::fields( $slug ) as $name => $f ) {
			if ( in_array( $name, $hide, true ) ) { if ( $row ) continue; $h .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) ( $_GET[ $name ] ?? '' ) ) . '">'; continue; }
			$val  = $row ? (string) ( $row[ $name ] ?? '' ) : (string) $f['default'];
			$opts = [ 'required' => $f['required'], 'note' => $f['note'], 'placeholder' => $f['placeholder'] ];
			if ( $f['enc'] ) { $val = ''; $opts['placeholder'] = $row && '' !== (string) ( $row[ $name ] ?? '' ) ? 'On file — type to replace' : $f['placeholder']; $opts['required'] = false; }
			if ( 'select' === $f['type'] ) {
				if ( 'users' === $f['ref'] ) $opts['options'] = self::user_options();
				elseif ( is_array( $f['ref'] ) ) $opts['options'] = WB_Render::options( $f['ref'][0], $f['ref'][1], [], true ) + ( $row && 'wb_staff' === $slug && 'manager_staff_id' === $name ? [] : [] );
				else $opts['options'] = ( $f['required'] ? [] : [ '' => '— choose —' ] ) + (array) $f['options'];
				if ( is_array( $f['ref'] ) && $row && $f['ref'][0] === $slug ) unset( $opts['options'][ (int) $row['_ID'] ] );   // nothing is inside itself
			}
			if ( 'textarea' === $f['type'] && '_json' === substr( $name, -5 ) ) $val = self::json_to_lines( $val );
			$h .= WB_Render::field( $name, $f['label'], $f['type'], $val, $opts );
		}
		return $h . WB_Render::form_close( $row ? 'Save changes' : 'Add ' . $pol['one'] );
	}

	/** The fold that holds the form: "Add a customer", or "Edit Karoo Agri" when ?edit=<id> names a row of this table. */
	public static function fold( string $slug, bool $open_when_empty = false, string $kind = '', array $hide = [] ): string {
		$pol = self::TABLES[ $slug ] ?? null;
		if ( ! $pol || ! current_user_can( $pol['cap'] ) ) return '';
		$row = null;
		if ( ! empty( $_GET['edit'] ) && sanitize_key( (string) ( $_GET['cct'] ?? '' ) ) === $slug ) $row = WB_CCT::get( $slug, absint( $_GET['edit'] ) ) ?: null;
		$id    = 'wb-add' . ( 'wb-add' === self::anchor( $slug ) ? '' : '-' . substr( $slug, 3 ) );
		$title = $row ? 'Edit ' . self::name_of( $slug, $row ) : 'Add ' . ( preg_match( '/^[aeiou]/i', $pol['one'] ) ? 'an ' : 'a ' ) . $pol['one'];
		$body  = ( $row ? '<p class="wb-fold-note">Changes are recorded in the audit trail with your name. <a href="' . esc_url( WB_Workspace::url( $pol['screen'] ) . '#' . $id ) . '">Add a new one instead</a>.</p>' : '' ) . self::form( $slug, $row, $hide );
		return WB_Render::fold( $title, $body, [ 'open' => (bool) $row || $open_when_empty, 'id' => $id, 'kind' => $kind ] );
	}

	/** The first table on a screen owns #wb-add; the others get #wb-add-<table>. */
	public static function anchor( string $slug ): string {
		foreach ( self::TABLES as $t => $p ) if ( $p['screen'] === ( self::TABLES[ $slug ]['screen'] ?? '' ) ) return $t === $slug ? 'wb-add' : 'wb-add-' . substr( $slug, 3 );
		return 'wb-add';
	}

	/** The edit link for a row: the screen with ?cct=&edit= and the fold's anchor. */
	public static function edit_url( string $slug, int $id ): string {
		return WB_Workspace::url( self::TABLES[ $slug ]['screen'], [ 'cct' => $slug, 'edit' => $id ] ) . '#' . self::anchor( $slug );
	}

	/** An Edit row action per master table, beside Archive. */
	public static function row_actions( array $a ): array {
		foreach ( self::TABLES as $slug => $pol ) {
			$a[ 'edit_' . $slug ] = [ 'cct' => $slug, 'label' => 'Edit', 'icon' => 'edit', 'allowed' => fn() => current_user_can( $pol['cap'] ), 'visible' => fn( $r ) => true, 'href' => fn( $r ) => self::edit_url( $slug, (int) $r['_ID'] ) ];
		}
		return $a;
	}

	/** The words for a row. */
	public static function name_of( string $slug, array $row ): string {
		$n = trim( (string) ( $row['name'] ?? trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ) ) );
		return '' !== $n ? $n : ( self::TABLES[ $slug ]['one'] ?? 'record' ) . ' #' . (int) ( $row['_ID'] ?? 0 );
	}

	/**
	 * Posted values → a clean row, by field type (pure; tested). Returns [ row, problems ].
	 * Numbers must be numbers; selects must be one of the options; dates must parse; encrypted
	 * fields blank on edit are left out (keep); switchers store "true"/"false" like JetEngine.
	 */
	public static function clean( string $slug, array $posted, bool $editing ): array {
		$row = [];
		$bad = [];
		foreach ( self::fields( $slug ) as $name => $f ) {
			$raw = isset( $posted[ $name ] ) ? trim( (string) $posted[ $name ] ) : '';
			if ( $f['enc'] ) { if ( '' !== $raw ) $row[ $name ] = wb_enc( sanitize_text_field( $raw ) ); elseif ( ! $editing ) $row[ $name ] = ''; continue; }
			if ( $f['required'] && '' === $raw ) { $bad[] = $f['label'] . ' is needed.'; continue; }
			switch ( $f['type'] ) {
				case 'number':
					if ( '' === $raw ) { $row[ $name ] = is_numeric( $f['default'] ) && ! $editing ? $f['default'] : ''; break; }
					$n = str_replace( [ ' ', ',' ], [ '', '.' ], $raw );
					if ( ! is_numeric( $n ) ) { $bad[] = $f['label'] . ': "' . $raw . '" is not a number (write 1200.50, no R, spaces or commas).'; break; }
					$row[ $name ] = (float) $n;
					break;
				case 'select':
					if ( '' === $raw ) { $row[ $name ] = is_array( $f['ref'] ) || 'users' === $f['ref'] ? 0 : (string) $f['default']; break; }
					if ( is_array( $f['ref'] ) || 'users' === $f['ref'] ) { $row[ $name ] = absint( $raw ); break; }
					if ( ! isset( $f['options'][ $raw ] ) ) { $bad[] = $f['label'] . ': "' . $raw . '" is not one of the choices (' . implode( ', ', array_keys( (array) $f['options'] ) ) . ').'; break; }
					$row[ $name ] = $raw;
					break;
				case 'date': case 'datetime-local': case 'time':
					if ( '' === $raw ) { $row[ $name ] = ''; break; }
					$t = strtotime( $raw );
					if ( false === $t ) { $bad[] = $f['label'] . ': "' . $raw . '" is not a date.'; break; }
					$row[ $name ] = 'date' === $f['store'] ? date( 'Y-m-d', $t ) : ( 'time' === $f['store'] ? date( 'H:i', $t ) : date( 'Y-m-d H:i:s', $t ) );
					break;
				case 'textarea':
					$row[ $name ] = '_json' === substr( $name, -5 ) ? self::lines_to_json( $raw ) : sanitize_textarea_field( $raw );
					break;
				default:
					$row[ $name ] = 'email' === $name ? sanitize_email( $raw ) : sanitize_text_field( $raw );
					if ( 'email' === $name && '' !== $raw && ! is_email( $row[ $name ] ) ) $bad[] = 'Check the email address.';
					if ( 'sku' === $name ) $row[ $name ] = strtoupper( $row[ $name ] );
			}
		}
		return [ $row, $bad ];
	}

	/** "label | value | unit" lines → the stored JSON rows, and back. Pure; tested. */
	public static function lines_to_json( string $text ): string {
		$rows = [];
		foreach ( preg_split( '/\r?\n/', trim( $text ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) continue;
			$p = array_map( 'trim', explode( '|', $line ) );
			$rows[] = count( $p ) >= 3 ? [ 'label' => $p[0], 'value' => $p[1], 'unit' => $p[2] ] : ( 2 === count( $p ) ? [ 'label' => $p[0], 'unit' => $p[1] ] : [ 'label' => $p[0] ] );
		}
		return $rows ? (string) json_encode( $rows, JSON_UNESCAPED_UNICODE ) : '';
	}

	public static function json_to_lines( string $json ): string {
		$rows = json_decode( $json, true );
		if ( ! is_array( $rows ) ) return $json;
		$out = [];
		foreach ( $rows as $r ) $out[] = implode( ' | ', array_filter( [ (string) ( $r['label'] ?? '' ), isset( $r['value'] ) ? (string) $r['value'] : null, (string) ( $r['unit'] ?? '' ) ], fn( $v ) => null !== $v && '' !== $v ) );
		return implode( "\n", $out );
	}

	/** The one panel handler for every master table. */
	public static function handle_save() {
		$slug = sanitize_key( (string) ( $_POST['record_cct'] ?? '' ) );
		$pol  = self::TABLES[ $slug ] ?? null;
		if ( ! $pol ) return new WP_Error( 'wb_table', 'That is not a table you can edit here.' );
		if ( ! current_user_can( $pol['cap'] ) ) return new WP_Error( 'wb_forbidden', 'You cannot change ' . $pol['one'] . ' records.' );
		$id  = absint( $_POST['record_id'] ?? 0 );
		$cur = $id ? WB_CCT::get( $slug, $id ) : null;
		if ( $id && ! $cur ) return new WP_Error( 'wb_not_found', 'That ' . $pol['one'] . ' could not be found.' );
		[ $row, $bad ] = self::clean( $slug, wp_unslash( $_POST ), (bool) $cur );
		if ( $bad ) return new WP_Error( 'wb_invalid', implode( ' ', $bad ) );
		foreach ( $pol['key'] as $k ) {
			if ( '' === (string) ( $row[ $k ] ?? '' ) ) continue;
			$dup = WB_CCT::first( $slug, [ $k => $row[ $k ] ] );
			if ( $dup && (int) $dup['_ID'] !== $id ) return new WP_Error( 'wb_dup', 'A ' . $pol['one'] . ' with that ' . strtolower( WB_Render::label( $k ) ) . ' is already on file: ' . self::name_of( $slug, $dup ) . '.' );
		}
		if ( 'wb_contacts' === $slug && 'true' === ( $row['marketing_optin'] ?? '' ) && 'true' !== (string) ( $cur['marketing_optin'] ?? '' ) ) $row['marketing_optin_at'] = current_time( 'mysql' );
		if ( 'wb_price_tiers' === $slug && 'true' === ( $row['is_default'] ?? '' ) ) {
			foreach ( WB_CCT::find( 'wb_price_tiers', [ 'is_default' => [ 'true', '1', 'yes' ] ] ) as $t ) if ( (int) $t['_ID'] !== $id ) WB_CCT::update( 'wb_price_tiers', (int) $t['_ID'], [ 'is_default' => 'false' ], 'price_tier_default_moved' );
		}
		if ( 'wb_customers' === $slug && ! $cur ) $row += [ 'journey_stage' => 'lead' ];
		$res = $cur ? WB_CCT::update( $slug, $id, $row, substr( $slug, 3 ) . '_edited' ) : WB_CCT::insert( $slug, $row, rtrim( substr( $slug, 3 ), 's' ) . '_added' );
		if ( is_wp_error( $res ) ) return $res;
		return [ 'msg' => ucfirst( $pol['one'] ) . ( $cur ? ' saved.' : ' added.' ) . ( 'wb_products' === $slug && ! $cur ? ' Stock starts at zero — receive it against a purchase order.' : '' ) ];
	}

	/** WordPress logins that could be staff: [ id => name (login) ], administrators and wb_* roles. */
	private static function user_options(): array {
		$out = [ '' => '— no login yet —' ];
		foreach ( get_users( [ 'role__in' => array_merge( [ 'administrator' ], array_keys( WB_Roles::map() ) ), 'number' => 500, 'orderby' => 'display_name' ] ) as $u ) {
			if ( in_array( 'wb_customer', (array) $u->roles, true ) ) continue;
			$out[ (int) $u->ID ] = $u->display_name . ' (' . $u->user_login . ')';
		}
		return $out;
	}
}
