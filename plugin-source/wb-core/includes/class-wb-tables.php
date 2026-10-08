<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Tables — creates the business tables (JetEngine Custom Content Types) from schema/wb-ccts.json
 * (0.3.0). One button on the Setup screen, administrators only.
 *
 * - ADDITIVE ONLY: a content type that already exists is never changed. Fields missing from an
 *   existing one are reported (add them in JetEngine by hand), never altered here.
 * - THROUGH JETENGINE: each type is created with JetEngine's own data API (the same call its
 *   "Add content type" screen makes), so JetEngine knows the type, builds its table and shows it in
 *   wp-admin. Written against JetEngine 3.8; if the API is not there it stops with a message.
 * - NUMBERS KEEP THEIR DECIMALS: a JetEngine number field with no step is a whole-number column
 *   (BIGINT), which would cut R 12,50 to 12. Money, quantities, hours and percentages get a step of
 *   0.01 (DECIMAL); references (*_id), people (*_by) and counts stay whole numbers.
 * - No REST access and no single pages are switched on for any type: the plugin is the only door.
 *
 * The mapping (field_def, content_type_request, is_whole_number) is PURE — tested without WordPress.
 */
class WB_Tables {

	/** Number fields that are counts or codes, not amounts (everything ending _id or _by is whole too). */
	const WHOLE_NUMBERS = [
		'break_minutes', 'cycle_months', 'headcount', 'lead_time_days', 'medical_scheme_members',
		'payment_terms_days', 'rating', 'shelf_life_days', 'size', 'version',
	];

	const DECIMAL_STEP = '0.01';

	public static function init(): void {
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			$h['tables_create'] = [ __CLASS__, 'handle_create' ];
			return $h;
		} );
	}

	/* ================================================================== pure */

	public static function is_whole_number( string $name ): bool {
		return '_id' === substr( $name, -3 ) || '_by' === substr( $name, -3 ) || in_array( $name, self::WHOLE_NUMBERS, true );
	}

	/** "payment_terms_days" → "Payment terms days". */
	public static function title( string $name ): string {
		return ucfirst( str_replace( '_', ' ', $name ) );
	}

	/** One schema field → one JetEngine meta field. $n is its position (JetEngine wants a unique id). */
	public static function field_def( array $f, int $n ): array {
		$name = (string) $f['name'];
		$out  = [
			'id'          => $n,
			'title'       => self::title( $name ),
			'name'        => $name,
			'object_type' => 'field',
			'type'        => (string) $f['type'],
			'width'       => '100%',
			'is_required' => false,
		];
		$note = (string) ( $f['description'] ?? $f['note'] ?? '' );
		if ( '' !== $note ) $out['description'] = $note;
		if ( 'number' === $f['type'] && ! self::is_whole_number( $name ) ) $out['step_value'] = self::DECIMAL_STEP;
		if ( 'select' === $f['type'] ) {
			$out['options'] = [];
			foreach ( array_values( (array) ( $f['options'] ?? [] ) ) as $i => $o ) {
				$out['options'][] = [ 'id' => $i + 1, 'key' => (string) $o, 'value' => self::title( (string) $o ), 'is_checked' => isset( $f['default'] ) && (string) $f['default'] === (string) $o ];
			}
		} elseif ( isset( $f['default'] ) ) {
			$out['default_val'] = (string) $f['default'];
		}
		return $out;
	}

	/** What JetEngine's "add content type" call takes, for one CCT of the schema. */
	public static function content_type_request( string $slug, array $def ): array {
		$fields = [];
		foreach ( array_values( (array) ( $def['fields'] ?? [] ) ) as $i => $f ) $fields[] = self::field_def( (array) $f, $i + 1 );
		$name = (string) ( $def['title'] ?? $slug );
		return [
			'name'        => $name,
			'slug'        => $slug,
			'args'        => [
				'name' => $name, 'slug' => $slug, 'capability' => 'manage_options', 'has_single' => false,
				'rest_get_enabled' => false, 'rest_put_enabled' => false, 'rest_post_enabled' => false, 'rest_delete_enabled' => false,
			],
			'meta_fields' => $fields,
		];
	}

	/* ================================================================== WordPress side */

	/** The schema's CCTs: [ slug => def ]. Empty when the file cannot be read (fail closed). */
	public static function schema(): array {
		$raw = file_get_contents( WB_PLUGIN_DIR . 'schema/wb-ccts.json' );
		$s   = false === $raw ? null : json_decode( $raw, true );
		return is_array( $s['ccts'] ?? null ) ? $s['ccts'] : [];
	}

	/** [ 'missing' => [slugs], 'present' => [slugs], 'short' => [ slug => [missing columns] ] ]. */
	public static function status(): array {
		$out = [ 'missing' => [], 'present' => [], 'short' => [] ];
		foreach ( self::schema() as $slug => $def ) {
			if ( ! WB_CCT::table( $slug ) ) { $out['missing'][] = $slug; continue; }
			$out['present'][] = $slug;
			$gap = array_values( array_diff( array_column( (array) $def['fields'], 'name' ), WB_CCT::columns( $slug ) ) );
			if ( $gap ) $out['short'][ $slug ] = $gap;
		}
		return $out;
	}

	public static function all_present(): bool {
		$s = self::status();
		return ! $s['missing'] && ! $s['short'] && $s['present'];
	}

	/** JetEngine's CCT data object, or a WP_Error saying what to switch on. */
	private static function jet_data() {
		$cls = '\Jet_Engine\Modules\Custom_Content_Types\Module';
		if ( ! class_exists( $cls ) ) return new WP_Error( 'wb_no_jetengine', 'JetEngine\'s Custom Content Types module is not switched on. Go to JetEngine → JetEngine dashboard → Modules, switch on Custom Content Types, save, then come back.' );
		$m = $cls::instance();
		if ( empty( $m->manager->data ) || ! method_exists( $m->manager->data, 'create_item' ) || ! method_exists( $m->manager->data, 'set_request' ) ) {
			return new WP_Error( 'wb_jetengine_api', 'This version of JetEngine does not offer the way of creating content types the plugin expects (written for JetEngine 3.8). Nothing was created.' );
		}
		return $m->manager;
	}

	/** Create every missing content type. Administrators only. @return array{msg:string}|WP_Error */
	public static function create_missing() {
		if ( ! current_user_can( 'manage_options' ) ) return new WP_Error( 'wb_forbidden', 'Only a site administrator may create the business tables.' );
		$mgr = self::jet_data();
		if ( is_wp_error( $mgr ) ) return $mgr;
		$schema = self::schema();
		if ( ! $schema ) return new WP_Error( 'wb_no_schema', 'The table list (schema/wb-ccts.json) could not be read. Nothing was created.' );

		// A type JetEngine already knows but whose table is missing is left for a person: creating it again would make a second definition.
		$known = [];
		foreach ( (array) $mgr->data->get_items() as $it ) $known[] = (string) ( is_array( $it ) ? ( $it['slug'] ?? '' ) : ( $it->slug ?? '' ) );

		$made = $stuck = $failed = [];
		WB_CCT::flush_cache();
		foreach ( self::status()['missing'] as $slug ) {
			if ( in_array( $slug, $known, true ) ) { $stuck[] = $slug; continue; }
			$mgr->data->set_request( self::content_type_request( $slug, $schema[ $slug ] ) );
			$id = $mgr->data->create_item( false );
			WB_CCT::flush_cache();
			if ( $id && WB_CCT::table( $slug ) ) $made[] = $slug;
			else $failed[] = $slug;
		}
		if ( $made || $failed ) wb_ledger_write( 'tables_created', 'jetengine', 0, null, [ 'created' => $made, 'failed' => $failed, 'left' => $stuck ] );

		$msg = $made ? sprintf( 'Created %d business table%s.', count( $made ), 1 === count( $made ) ? '' : 's' ) : 'No new tables were needed.';
		if ( $stuck ) $msg .= ' JetEngine already has a definition for ' . implode( ', ', $stuck ) . ' but no table: open each one in JetEngine → Custom Content Types and click Update.';
		if ( $failed ) return new WP_Error( 'wb_tables_failed', $msg . ' These could not be created: ' . implode( ', ', $failed ) . '. Check JetEngine → Custom Content Types for a message.' );
		return [ 'msg' => $msg ];
	}

	public static function handle_create() {
		return self::create_missing();
	}

	/** The Setup screen's fold. */
	public static function panel(): string {
		$s     = self::status();
		$total = count( $s['missing'] ) + count( $s['present'] );
		$h     = '<p>' . count( $s['present'] ) . ' of ' . $total . ' business tables are set up. The system keeps its records in them: customers, products, quotes, orders and the rest.</p>';
		if ( $s['missing'] ) {
			$h .= '<p class="wb-muted">Still to create: ' . esc_html( implode( ', ', $s['missing'] ) ) . '.</p>';
			$h .= current_user_can( 'manage_options' )
				? WB_Render::form_open( 'tables_create' ) . '<p class="wb-muted">Creates each missing table with its fields. Tables that already exist are not touched. It can take a minute.</p>' . WB_Render::form_close( 'Create the missing tables', 'Create ' . count( $s['missing'] ) . ' tables now?' )
				: wb_notice( 'warn', 'A site administrator needs to create these.' );
		}
		foreach ( $s['short'] as $slug => $cols ) {
			$h .= wb_notice( 'warn', esc_html( $slug ) . ' is missing these fields: ' . esc_html( implode( ', ', $cols ) ) . '. Add them in JetEngine → Custom Content Types (the field list is in schema/wb-ccts.json).' );
		}
		return $h;
	}
}
