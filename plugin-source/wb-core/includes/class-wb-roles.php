<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Roles — capabilities, never role names (convention 1).
 *
 * Two layers:
 *   1. WP roles (map()): wb_owner, wb_manager, wb_sales, wb_warehouse, wb_accounts, wb_staff,
 *      wb_customer. sync() re-applies them on every version change, so a cap change in code
 *      reaches every site. Administrators always keep every cap.
 *   2. The DASHBOARD CATALOGUE (catalog()): one checkbox per dashboard (ticking it grants
 *      everything that dashboard does) plus AUTHORITY MODIFIERS (approve below-floor prices,
 *      approve adjustments, approve credit notes, approve timesheets / leave). The ticks are
 *      stored per user in user meta `wb_dashboards`; the caps are granted live by the
 *      user_has_cap filter, so un-ticking revokes at once with nothing stale to clean up.
 *
 * Segregation of duties is enforced in the engines, not here: holding both "move stock" and
 * "approve adjustments" never lets a person approve their own adjustment.
 */
class WB_Roles {

	const META = 'wb_dashboards';

	/** Every capability, with the plain-English words a person reads. Keys are frozen. */
	const CAPS = [
		'wb_access_workspace'     => 'Open the workspace',
		'wb_view_customers'       => 'See customers',
		'wb_manage_customers'     => 'Add and edit customers',
		'wb_view_products'        => 'See products',
		'wb_manage_products'      => 'Add and edit products',
		'wb_manage_pricing'       => 'Set prices and customer price rules',
		'wb_approve_pricing'      => 'Approve prices below the floor',
		'wb_create_quotes'        => 'Write quotes',
		'wb_send_quotes'          => 'Send quotes to customers',
		'wb_manage_orders'        => 'Run orders',
		'wb_issue_invoices'       => 'Issue invoices',
		'wb_issue_credit_notes'   => 'Ask for credit notes',
		'wb_approve_credit_notes' => 'Approve credit notes',
		'wb_match_payments'       => 'Match payments to invoices',
		'wb_import_bank'          => 'Import bank statements',
		'wb_issue_delivery_notes' => 'Issue delivery and collection notes',
		'wb_close_orders'         => 'Close orders',
		'wb_view_stock'           => 'See stock',
		'wb_move_stock'           => 'Record stock movements',
		'wb_adjust_stock'         => 'Ask for stock adjustments and write-offs',
		'wb_approve_adjustments'  => 'Approve stock adjustments and write-offs',
		'wb_manage_purchasing'    => 'Order from suppliers and receive stock',
		'wb_run_stocktake'        => 'Count and check stock',
		'wb_view_documents'       => 'See documents',
		'wb_manage_documents'     => 'File documents and datasheets',
		'wb_view_marketing'       => 'See marketing',
		'wb_manage_marketing'     => 'Run marketing',
		'wb_view_cashflow'        => 'See cashflow',
		'wb_view_staff'           => 'See staff files',
		'wb_manage_staff'         => 'Manage staff files',
		'wb_approve_timesheets'   => 'Approve timesheets',
		'wb_approve_leave'        => 'Approve leave',
		'wb_run_reviews'          => 'Run performance reviews',
		'wb_view_integrity'       => 'See the Integrity report',
		'wb_export_data'          => 'Export data',
		'wb_manage_settings'      => 'Change settings and who can do what',
		'wb_view_payroll'         => 'See pay runs and payslips',
		'wb_run_payroll'          => 'Prepare and finalise payroll, keep pay details',
		'wb_check_payroll'        => 'Check a pay run someone else prepared',
		'wb_portal'               => 'Customer portal: own quotes, orders, invoices and datasheets',
	];

	public static function init(): void {
		add_filter( 'user_has_cap', [ __CLASS__, 'grant_dashboard_caps' ], 10, 4 );
	}

	/** Every staff capability (everything except the customer-portal cap). */
	public static function staff_caps(): array {
		$out = [];
		foreach ( array_keys( self::CAPS ) as $c ) if ( 'wb_portal' !== $c ) $out[ $c ] = true;
		return $out;
	}

	private static function caps( array $list ): array {
		$out = [ 'read' => true ];
		foreach ( $list as $c ) $out[ $c ] = true;
		return $out;
	}

	/** The WP roles. Owner = everything; the rest are sensible starting points the owner can extend by ticking dashboards. */
	public static function map(): array {
		$all     = self::staff_caps();
		$manager = $all;
		unset( $manager['wb_manage_settings'], $manager['wb_manage_staff'], $manager['wb_view_payroll'], $manager['wb_run_payroll'], $manager['wb_check_payroll'] );   // pay is the owner's unless ticked
		return [
			'wb_owner'     => [ 'label' => 'Owner', 'caps' => [ 'read' => true ] + $all ],
			'wb_manager'   => [ 'label' => 'Manager', 'caps' => [ 'read' => true ] + $manager ],
			'wb_sales'     => [ 'label' => 'Sales', 'caps' => self::caps( [
				'wb_access_workspace', 'wb_view_customers', 'wb_manage_customers', 'wb_view_products',
				'wb_create_quotes', 'wb_send_quotes', 'wb_manage_orders', 'wb_view_stock',
				'wb_view_documents', 'wb_view_marketing', 'wb_manage_marketing',
			] ) ],
			'wb_warehouse' => [ 'label' => 'Warehouse', 'caps' => self::caps( [
				'wb_access_workspace', 'wb_view_products', 'wb_view_stock', 'wb_move_stock', 'wb_adjust_stock',
				'wb_issue_delivery_notes', 'wb_manage_purchasing', 'wb_run_stocktake', 'wb_view_documents',
			] ) ],
			'wb_accounts'  => [ 'label' => 'Accounts', 'caps' => self::caps( [
				'wb_access_workspace', 'wb_view_customers', 'wb_view_products', 'wb_issue_invoices',
				'wb_issue_credit_notes', 'wb_match_payments', 'wb_import_bank', 'wb_view_cashflow',
				'wb_close_orders', 'wb_export_data', 'wb_view_documents',
			] ) ],
			// Own timesheets and leave only: the engines scope every staff action to the person's own
			// wb_staff record (wp_user_id = them). Extra dashboards come from ticks.
			'wb_staff'     => [ 'label' => 'Staff', 'caps' => self::caps( [ 'wb_access_workspace' ] ) ],
			// A login at a customer: sees only their own company's records (scoped by
			// wb_contacts.portal_wp_user_id → customer_id in every portal query).
			'wb_customer'  => [ 'label' => 'Customer', 'caps' => self::caps( [ 'wb_portal' ] ) ],
		];
	}

	/**
	 * One checkbox per dashboard, plus authority modifiers. Each tick also opens the workspace.
	 * 'modifier' => true marks an authority tick (shown in its own group on the Staff screen).
	 */
	public static function catalog(): array {
		return [
			'customers'   => [ 'label' => 'Customers', 'caps' => [ 'wb_view_customers', 'wb_manage_customers', 'wb_view_documents' ] ],
			'products'    => [ 'label' => 'Products', 'caps' => [ 'wb_view_products', 'wb_manage_products', 'wb_view_documents' ] ],
			'pricing'     => [ 'label' => 'Pricing', 'caps' => [ 'wb_view_products', 'wb_manage_pricing' ] ],
			'quotes'      => [ 'label' => 'Quotes', 'caps' => [ 'wb_view_customers', 'wb_view_products', 'wb_create_quotes', 'wb_send_quotes' ] ],
			'orders'      => [ 'label' => 'Orders', 'caps' => [ 'wb_view_customers', 'wb_manage_orders', 'wb_view_stock' ] ],
			'invoices'    => [ 'label' => 'Invoices', 'caps' => [ 'wb_view_customers', 'wb_issue_invoices', 'wb_issue_credit_notes' ] ],
			'payments'    => [ 'label' => 'Payments', 'caps' => [ 'wb_view_customers', 'wb_match_payments', 'wb_import_bank' ] ],
			'deliveries'  => [ 'label' => 'Deliveries', 'caps' => [ 'wb_view_customers', 'wb_issue_delivery_notes', 'wb_close_orders', 'wb_view_stock' ] ],
			'stock'       => [ 'label' => 'Stock', 'caps' => [ 'wb_view_products', 'wb_view_stock', 'wb_move_stock', 'wb_adjust_stock' ] ],
			'purchasing'  => [ 'label' => 'Purchasing', 'caps' => [ 'wb_view_products', 'wb_view_stock', 'wb_manage_purchasing' ] ],
			'stocktake'   => [ 'label' => 'Stocktake', 'caps' => [ 'wb_view_products', 'wb_view_stock', 'wb_run_stocktake' ] ],
			'documents'   => [ 'label' => 'Documents', 'caps' => [ 'wb_view_documents', 'wb_manage_documents' ] ],
			'marketing'   => [ 'label' => 'Marketing', 'caps' => [ 'wb_view_customers', 'wb_view_marketing', 'wb_manage_marketing' ] ],
			'cashflow'    => [ 'label' => 'Cashflow', 'caps' => [ 'wb_view_cashflow' ] ],
			'staff'       => [ 'label' => 'Staff', 'caps' => [ 'wb_view_staff', 'wb_manage_staff' ] ],
			'reviews'     => [ 'label' => 'Performance reviews', 'caps' => [ 'wb_view_staff', 'wb_run_reviews' ] ],
			'integrity'   => [ 'label' => 'Integrity report', 'caps' => [ 'wb_view_integrity' ] ],
			'export'      => [ 'label' => 'Export', 'caps' => [ 'wb_export_data' ] ],
			'payroll'     => [ 'label' => 'Payroll', 'note' => 'Sees everyone\'s pay and bank details.', 'caps' => [ 'wb_view_payroll', 'wb_run_payroll' ] ],
			'settings'    => [ 'label' => 'Settings', 'note' => 'Includes who can do what — give it only to people you would trust with everything.', 'caps' => [ 'wb_manage_settings' ] ],
			// Authority modifiers. Approving never covers your own request (enforced in the engines).
			'approve_pricing'     => [ 'label' => 'Approves prices below the floor', 'modifier' => true, 'caps' => [ 'wb_approve_pricing' ] ],
			'approve_adjustments' => [ 'label' => 'Approves stock adjustments and write-offs', 'modifier' => true, 'caps' => [ 'wb_approve_adjustments' ] ],
			'approve_credits'     => [ 'label' => 'Approves credit notes', 'modifier' => true, 'caps' => [ 'wb_approve_credit_notes' ] ],
			'approve_timesheets'  => [ 'label' => 'Approves timesheets', 'modifier' => true, 'caps' => [ 'wb_approve_timesheets' ] ],
			'approve_leave'       => [ 'label' => 'Approves leave', 'modifier' => true, 'caps' => [ 'wb_approve_leave' ] ],
			'check_payroll'       => [ 'label' => 'Checks pay runs', 'modifier' => true, 'caps' => [ 'wb_check_payroll', 'wb_view_payroll' ] ],
		];
	}

	/** Caps for a set of catalogue keys, as [ cap => true ] (unknown keys skipped). Any tick opens the workspace. */
	public static function catalog_caps( array $keys ): array {
		$cat  = self::catalog();
		$caps = [];
		foreach ( $keys as $k ) {
			if ( ! isset( $cat[ (string) $k ] ) ) continue;
			foreach ( $cat[ (string) $k ]['caps'] as $c ) $caps[ $c ] = true;
		}
		if ( $caps ) $caps['wb_access_workspace'] = true;
		return $caps;
	}

	/** user_has_cap: add the caps of the dashboards ticked for this user. Never grants anything to a customer login. */
	public static function grant_dashboard_caps( array $allcaps, array $caps, array $args, $user ): array {
		if ( ! $user instanceof WP_User || ! $user->ID ) return $allcaps;
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			foreach ( self::staff_caps() as $c => $g ) $allcaps[ $c ] = true;   // administrators always keep every cap
			return $allcaps;
		}
		if ( in_array( 'wb_customer', (array) $user->roles, true ) && 1 === count( (array) $user->roles ) ) return $allcaps;
		$ticks = get_user_meta( $user->ID, self::META, true );
		if ( is_array( $ticks ) && $ticks ) {
			foreach ( self::catalog_caps( $ticks ) as $c => $g ) $allcaps[ $c ] = true;
		}
		return $allcaps;
	}

	/** Set a person's dashboard ticks (Settings → who can do what). Ledgered; owners only. */
	public static function set_dashboards( int $user_id, array $keys ) {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return new WP_Error( 'wb_forbidden', 'Only someone with Settings may change who can do what.' );
		$keys   = array_values( array_intersect( array_map( 'sanitize_key', $keys ), array_keys( self::catalog() ) ) );
		$before = get_user_meta( $user_id, self::META, true );
		update_user_meta( $user_id, self::META, $keys );
		wb_ledger_write( 'dashboards_set', 'wp_user', $user_id, [ 'dashboards' => $before ], [ 'dashboards' => $keys ] );
		return true;
	}

	/** Re-create every role (so removed caps really go) and give administrators everything. */
	public static function sync(): void {
		foreach ( self::map() as $key => $def ) {
			remove_role( $key );
			add_role( $key, $def['label'], $def['caps'] );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::staff_caps() as $c => $g ) $admin->add_cap( $c );
		}
	}

	/** The owners: who gets integrity alerts. wb_owner role holders + administrators. */
	public static function owner_ids(): array {
		$ids = get_users( [ 'role__in' => [ 'wb_owner', 'administrator' ], 'fields' => 'ID', 'number' => 50 ] );
		return array_map( 'intval', (array) $ids );
	}
}
