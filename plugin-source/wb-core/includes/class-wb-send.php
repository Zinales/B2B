<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WB_Send — a person sends a document by email from the system (1.5.0, review of 9 October:
 * "documents cannot be sent from the system").
 *
 * The rule stays: nothing goes to a customer by itself. "Send by email" on a quote, invoice, credit
 * note, statement, reminder or datasheet opens a short form: who it goes to (the customer's
 * contacts who receive that kind of document are ticked; another address can be typed), the
 * subject and message from the company's template for that kind with the details filled in, the
 * PDF attached, and a copy to yourself. One press sends it, records it on the customer's timeline
 * and in the audit trail, and for a draft quote marks it sent and puts the acceptance link in the
 * message. The demo visitor sees the same form, but nothing leaves the building.
 *
 * Templates are edited under Settings › Email templates. fill() and recipients() are pure.
 */
class WB_Send {

	/** kind => [ words, the cap to send, the screen the form opens on, the table, the timeline word ] */
	const KINDS = [
		'quote'     => [ 'Quote',             'wb_send_quotes',        'quotes',    'wb_quotes',       'email' ],
		'invoice'   => [ 'Invoice',           'wb_issue_invoices',     'invoices',  'wb_invoices',     'email' ],
		'credit'    => [ 'Credit note',       'wb_issue_credit_notes', 'invoices',  'wb_credit_notes', 'email' ],
		'statement' => [ 'Statement',         'wb_issue_invoices',     'customers', 'wb_customers',    'email' ],
		'reminder'  => [ 'Payment reminder',  'wb_issue_invoices',     'customers', 'wb_customers',    'email' ],
		'datasheet' => [ 'Datasheet',         'wb_view_documents',     'products',  'wb_products',     'datasheet_sent' ],
	];

	/** The words a company starts with. {placeholders} are filled when the form opens. */
	const DEFAULTS = [
		'quote'     => [ 'Quote {number} from {company}', "Dear {contact},\n\nThank you for your enquiry. Our quote {number} for R {total} is attached and is valid until {valid_until}.\n\nYou can accept it online here:\n{link}\n\nKind regards,\n{me}\n{company}" ],
		'invoice'   => [ 'Invoice {number} from {company}', "Dear {contact},\n\nPlease find invoice {number} for R {total} attached, due on {due}.\n\nPlease use {number} as the reference when you pay.\n\nKind regards,\n{me}\n{company}" ],
		'credit'    => [ 'Credit note {number} from {company}', "Dear {contact},\n\nPlease find credit note {number} for R {total} attached, against invoice {invoice}.\n\nKind regards,\n{me}\n{company}" ],
		'statement' => [ 'Your statement from {company}', "Dear {contact},\n\nYour statement at {date} is attached. The balance owing is R {owing}.\n\nKind regards,\n{me}\n{company}" ],
		'reminder'  => [ 'Payment reminder from {company}', "Dear {contact},\n\nOur records show R {overdue} past its due date on your account. Your statement is attached with the details.\n\nIf you have paid in the last few days, thank you, and please ignore this message. If anything on it is wrong, reply and we will put it right.\n\nKind regards,\n{me}\n{company}" ],
		'datasheet' => [ 'Datasheet: {product}', "Dear {contact},\n\nAs promised, the datasheet for {product} is attached.{link_line}\n\nKind regards,\n{me}\n{company}" ],
	];

	/** Placeholders each kind fills, for the settings fold. */
	const PLACEHOLDERS = '{company} {me} {contact} {customer} {number} {total} {date} — quotes: {valid_until} {link} — invoices: {due} — credit notes: {invoice} — statements and reminders: {owing} {overdue} — datasheets: {product} {link_line}';

	public static function init(): void {
		add_filter( 'wb_row_actions', [ __CLASS__, 'row_actions' ] );
		add_filter( 'wb_panel_handlers', function ( array $h ): array {
			$h['send_doc']       = [ __CLASS__, 'handle' ];
			$h['send_templates'] = [ __CLASS__, 'handle_templates' ];
			return $h;
		} );
	}

	/* ------------------------------------------------------------------ pure */

	/** {key} → value; a placeholder with no value is left out, never shown as {key}. Pure. */
	public static function fill( string $text, array $vars ): string {
		return (string) preg_replace_callback( '/\{([a-z_]+)\}/', fn( $m ) => (string) ( $vars[ $m[1] ] ?? '' ), $text );
	}

	/**
	 * Who gets this kind of document, from the customer's contacts: [ [ email, name, ticked ], … ].
	 * Invoices, credit notes, statements and reminders go to those who receive invoices; datasheets
	 * to those who receive datasheets; a quote to the contact it was written for, else the main
	 * contact. When nobody is marked, the main contact (or the first with an email) is ticked. Pure.
	 */
	public static function recipients( array $contacts, string $kind, int $quote_contact = 0 ): array {
		$with = array_values( array_filter( $contacts, fn( $c ) => '' !== trim( (string) ( $c['email'] ?? '' ) ) ) );
		$flag = in_array( $kind, [ 'invoice', 'credit', 'statement', 'reminder' ], true ) ? 'receives_invoices' : ( 'datasheet' === $kind ? 'receives_datasheets' : '' );
		$yes  = fn( $v ) => in_array( strtolower( (string) $v ), [ '1', 'true', 'yes', 'on' ], true );
		$out  = [];
		foreach ( $with as $c ) {
			$tick = 'quote' === $kind ? ( $quote_contact ? (int) $c['_ID'] === $quote_contact : $yes( $c['is_primary'] ?? '' ) ) : ( '' !== $flag && $yes( $c[ $flag ] ?? '' ) );
			$out[] = [ strtolower( trim( (string) $c['email'] ) ), trim( (string) ( $c['first_name'] ?? '' ) . ' ' . (string) ( $c['last_name'] ?? '' ) ), $tick ];
		}
		if ( $out && ! array_filter( array_column( $out, 2 ) ) ) {
			$main = array_search( true, array_map( fn( $c ) => $yes( $c['is_primary'] ?? '' ), $with ), true );
			$out[ false === $main ? 0 : $main ][2] = true;
		}
		return $out;
	}

	/** The addresses a person typed, split on commas, semicolons or spaces: [ valid, invalid ]. Pure. */
	public static function addresses( string $typed ): array {
		$ok = $bad = [];
		foreach ( preg_split( '/[\s,;]+/', trim( $typed ) ) as $a ) {
			if ( '' === $a ) continue;
			if ( preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $a ) ) $ok[] = strtolower( $a ); else $bad[] = $a;
		}
		return [ array_values( array_unique( $ok ) ), $bad ];
	}

	/* ------------------------------------------------------------------ templates */

	public static function templates(): array {
		$saved = (array) get_option( 'wb_send_templates', [] );
		$out   = [];
		foreach ( self::DEFAULTS as $k => [ $s, $b ] ) $out[ $k ] = [ (string) ( $saved[ $k ][0] ?? $s ), (string) ( $saved[ $k ][1] ?? $b ) ];
		return $out;
	}

	public static function settings_fold(): string {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return '';
		$f = WB_Render::form_open( 'send_templates' ) . '<p class="wb-muted">What each email says when someone presses "Send by email". These words fill themselves in: ' . esc_html( self::PLACEHOLDERS ) . '. Leave a box empty to go back to the standard words.</p>';
		foreach ( self::templates() as $k => [ $s, $b ] ) {
			$f .= '<fieldset class="wb-tpl"><legend>' . esc_html( self::KINDS[ $k ][0] ) . '</legend>' . WB_Render::field( 'tpl_s[' . $k . ']', 'Subject', 'text', $s, [ 'id' => 'wb-tpl-s-' . $k ] ) . WB_Render::field( 'tpl_b[' . $k . ']', 'Message', 'textarea', $b, [ 'rows' => 7, 'id' => 'wb-tpl-b-' . $k ] ) . '</fieldset>';
		}
		return WB_Render::fold( 'Email templates', $f . WB_Render::form_close( 'Save the templates' ), [ 'id' => 'wb-send-templates', 'hint' => 'what each email says' ] );
	}

	public static function handle_templates() {
		if ( ! current_user_can( 'wb_manage_settings' ) ) return new WP_Error( 'wb_forbidden', 'Only the owner can change the templates.' );
		$s = (array) wp_unslash( $_POST['tpl_s'] ?? [] );
		$b = (array) wp_unslash( $_POST['tpl_b'] ?? [] );
		$out = [];
		foreach ( self::DEFAULTS as $k => $def ) {
			$subj = sanitize_text_field( (string) ( $s[ $k ] ?? '' ) );
			$body = sanitize_textarea_field( (string) ( $b[ $k ] ?? '' ) );
			if ( '' !== $subj && $subj !== $def[0] || '' !== $body && $body !== $def[1] ) $out[ $k ] = [ '' !== $subj ? $subj : $def[0], '' !== $body ? $body : $def[1] ];
		}
		update_option( 'wb_send_templates', $out, false );
		wb_ledger_write( 'send_templates_saved', 'wb_settings', 0, null, [ 'changed' => array_keys( $out ) ] );
		return [ 'msg' => 'Templates saved.' ];
	}

	/* ------------------------------------------------------------------ the form */

	public static function form_url( string $kind, int $id, array $extra = [] ): string {
		return WB_Workspace::url( self::KINDS[ $kind ][2], [ 'send' => $kind, 'id' => $id ] + $extra ) . '#wb-send';
	}

	/** "Send by email" on each kind's rows. */
	public static function row_actions( array $a ): array {
		$a['send_quote']     = [ 'cct' => 'wb_quotes', 'label' => 'Send by email', 'icon' => 'share', 'allowed' => fn() => current_user_can( 'wb_send_quotes' ), 'visible' => fn( $r ) => in_array( (string) $r['status'], [ 'draft', 'sent' ], true ), 'href' => fn( $r ) => self::form_url( 'quote', (int) $r['_ID'] ) ];
		$a['send_invoice']   = [ 'cct' => 'wb_invoices', 'label' => 'Send by email', 'icon' => 'share', 'allowed' => fn() => current_user_can( 'wb_issue_invoices' ), 'visible' => fn( $r ) => 'void' !== (string) $r['status'], 'href' => fn( $r ) => self::form_url( 'invoice', (int) $r['_ID'] ) ];
		$a['send_credit']    = [ 'cct' => 'wb_credit_notes', 'label' => 'Send by email', 'icon' => 'share', 'allowed' => fn() => current_user_can( 'wb_issue_credit_notes' ), 'visible' => fn( $r ) => 'approved' === (string) $r['status'], 'href' => fn( $r ) => self::form_url( 'credit', (int) $r['_ID'] ) ];
		$a['send_statement'] = [ 'cct' => 'wb_customers', 'label' => 'Send a statement', 'icon' => 'share', 'allowed' => fn() => current_user_can( 'wb_issue_invoices' ), 'visible' => fn( $r ) => true, 'href' => fn( $r ) => self::form_url( 'statement', (int) $r['_ID'] ) ];
		$a['send_datasheet'] = [ 'cct' => 'wb_products', 'label' => 'Send the datasheet', 'icon' => 'share', 'allowed' => fn() => current_user_can( 'wb_view_documents' ), 'visible' => fn( $r ) => 'none' !== WB_Datasheets::current( (int) $r['_ID'], $r )[0], 'href' => fn( $r ) => self::form_url( 'datasheet', (int) $r['_ID'] ) ];
		return $a;
	}

	/** What the form needs: [ customer row, contacts, vars, attachment words ] or WP_Error. */
	public static function context( string $kind, int $id, int $customer_id = 0 ) {
		$k = self::KINDS[ $kind ] ?? null;
		if ( ! $k ) return new WP_Error( 'wb_kind', 'That cannot be sent.' );
		if ( ! current_user_can( $k[1] ) ) return new WP_Error( 'wb_forbidden', 'Sending that is not part of your work.' );
		$row = WB_CCT::get( $k[3], $id );
		if ( ! $row ) return new WP_Error( 'wb_missing', 'That could not be found.' );
		$me   = wp_get_current_user();
		$vars = [ 'company' => WB_Setup::display_name(), 'me' => (string) ( $me->display_name ?? '' ), 'date' => wb_today() ];
		$cid  = 'datasheet' === $kind ? $customer_id : ( in_array( $kind, [ 'statement', 'reminder' ], true ) ? $id : (int) ( $row['customer_id'] ?? 0 ) );
		$attach = '';
		switch ( $kind ) {
			case 'quote':
				$vars += [ 'number' => (string) $row['quote_number'], 'total' => WB_Render::money( (float) $row['total'] ), 'valid_until' => (string) $row['valid_until'], 'link' => in_array( (string) $row['status'], [ 'draft', 'sent' ], true ) ? '(the acceptance link is added when you send)' : '' ];
				$attach = 'Quote ' . $row['quote_number'] . '.pdf';
				break;
			case 'invoice':
				$vars += [ 'number' => (string) $row['invoice_number'], 'total' => WB_Render::money( (float) $row['total'] ), 'due' => substr( (string) $row['due_at'], 0, 10 ) ];
				$attach = 'Tax invoice ' . $row['invoice_number'] . '.pdf';
				break;
			case 'credit':
				$inv   = WB_CCT::get( 'wb_invoices', (int) $row['invoice_id'] );
				$vars += [ 'number' => (string) $row['credit_number'], 'total' => WB_Render::money( (float) $row['total'] ), 'invoice' => (string) ( $inv['invoice_number'] ?? '' ) ];
				$attach = 'Credit note ' . $row['credit_number'] . '.pdf';
				break;
			case 'statement':
			case 'reminder':
				$age   = WB_Pages::ageing( WB_CCT::find( 'wb_invoices', [ 'customer_id' => $id, 'status' => [ 'issued', 'part_paid', 'overdue' ] ], [ 'limit' => 2000 ] ), wb_today() );
				$vars += [ 'owing' => WB_Render::money( $age['total'] ), 'overdue' => WB_Render::money( $age['overdue'] ) ];
				$attach = 'Statement ' . wb_today() . '.pdf';
				break;
			case 'datasheet':
				[ $src, $ds ] = WB_Datasheets::current( $id, $row );
				$vars += [ 'product' => $row['sku'] . ' · ' . $row['name'], 'link_line' => 'link' === $src ? "\n\nIt is online here: " . (string) $ds['external_url'] : '' ];
				$attach = 'link' === $src ? '' : 'Datasheet ' . $row['sku'] . '.pdf';
				if ( 'none' === $src ) return new WP_Error( 'wb_no_datasheet', 'This product has no datasheet yet.' );
				break;
		}
		$cust     = $cid > 0 ? WB_CCT::get( 'wb_customers', $cid ) : null;
		$contacts = $cid > 0 ? WB_CCT::find( 'wb_contacts', [ 'customer_id' => $cid ], [ 'limit' => 200 ] ) : [];
		$vars    += [ 'customer' => (string) ( $cust['name'] ?? '' ) ];
		return [ $row, $cust, $contacts, $vars, $attach ];
	}

	/** The form, when this screen was opened with ?send=kind&id=. '' otherwise. */
	public static function panel( string $slug ): string {
		$kind = sanitize_key( (string) ( $_GET['send'] ?? '' ) );
		if ( ! isset( self::KINDS[ $kind ] ) || self::KINDS[ $kind ][2] !== $slug ) return '';
		$id  = absint( $_GET['id'] ?? 0 );
		$cid = absint( $_GET['customer'] ?? 0 );
		$ctx = self::context( $kind, $id, $cid );
		$close = '<p><a href="' . esc_url( remove_query_arg( [ 'send', 'id', 'customer' ] ) ) . '">Close without sending</a></p>';
		if ( is_wp_error( $ctx ) ) return WB_Render::fold( 'Send by email', wb_notice( 'warn', esc_html( $ctx->get_error_message() ) ) . $close, [ 'open' => true, 'id' => 'wb-send', 'kind' => 'lead' ] );
		[ $row, $cust, $contacts, $vars, $attach ] = $ctx;
		$title = 'Send ' . strtolower( self::KINDS[ $kind ][0] ) . ( isset( $vars['number'] ) ? ' ' . $vars['number'] : '' ) . ( $cust ? ' to ' . $cust['name'] : '' );

		if ( 'datasheet' === $kind && ! $cust ) {   // a datasheet goes to a customer: choose one first
			$f = '<form method="get" action="' . esc_url( WB_Workspace::url( 'products' ) ) . '#wb-send" class="wb-form"><input type="hidden" name="send" value="datasheet"><input type="hidden" name="id" value="' . $id . '">'
				. WB_Render::field( 'customer', 'Which customer', 'select', '', [ 'options' => WB_Render::options( 'wb_customers', 'name' ), 'required' => true, 'id' => 'wb-send-customer' ] ) . '<button type="submit" class="wb-btn">Next</button></form>';
			return WB_Render::fold( 'Send the datasheet for ' . $row['sku'], $f . $close, [ 'open' => true, 'id' => 'wb-send', 'kind' => 'lead' ] );
		}
		$tpl  = self::templates()[ $kind ];
		$rcp  = self::recipients( $contacts, $kind, (int) ( $row['contact_id'] ?? 0 ) );
		$first = '';
		foreach ( $rcp as $r ) if ( $r[2] ) { $first = (string) strtok( $r[1], ' ' ); break; }
		$vars['contact'] = '' !== $first ? $first : 'Sir or Madam';
		$f = WB_Render::form_open( 'send_doc' ) . '<input type="hidden" name="kind" value="' . esc_attr( $kind ) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="customer" value="' . (int) ( $cust['_ID'] ?? 0 ) . '">';
		$f .= '<fieldset class="wb-send-to"><legend>To</legend>';
		if ( ! $rcp ) $f .= '<p class="wb-muted">No contact at ' . esc_html( (string) ( $cust['name'] ?? 'this customer' ) ) . ' has an email address. Type one below, or add it to their contacts.</p>';
		foreach ( $rcp as $i => [ $email, $name, $tick ] ) $f .= '<label class="wb-check-row"><input type="checkbox" name="to[]" value="' . esc_attr( $email ) . '"' . ( $tick ? ' checked' : '' ) . '> ' . esc_html( '' !== $name ? $name . ' <' . $email . '>' : $email ) . '</label>';
		$f .= WB_Render::field( 'to_more', 'Another address', 'text', '', [ 'placeholder' => 'name@company.co.za', 'id' => 'wb-send-more' ] )
			. '<label class="wb-check-row"><input type="checkbox" name="copy_me" value="1" checked> Send me a copy</label></fieldset>';
		$f .= WB_Render::field( 'subject', 'Subject', 'text', self::fill( $tpl[0], $vars ), [ 'required' => true, 'id' => 'wb-send-subject' ] )
			. WB_Render::field( 'body', 'Message', 'textarea', self::fill( $tpl[1], $vars ), [ 'rows' => 10, 'id' => 'wb-send-body' ] )
			. ( '' !== $attach ? '<p class="wb-send-attach"><span aria-hidden="true">📎</span> ' . esc_html( $attach ) . ' is attached.</p>' : '' )
			. ( 'quote' === $kind && 'draft' === (string) $row['status'] ? '<p class="wb-muted">Sending marks the quote sent: its prices are then fixed, and the acceptance link goes into the message.</p>' : '' )
			. ( class_exists( 'WB_Demo' ) && WB_Demo::is_demo_user( get_current_user_id() ) ? wb_notice( 'warn', 'In the demo nothing is emailed. Pressing Send records it on the timeline as if it had gone.' ) : '' );
		return WB_Render::fold( $title, $f . WB_Render::form_close( 'Send', 'Send this email now?' ) . $close, [ 'open' => true, 'id' => 'wb-send', 'kind' => 'lead' ] );
	}

	/* ------------------------------------------------------------------ sending */

	/** The attachment as a file on disk: [ path, delete afterwards? ] or WP_Error; [ '', false ] for none. */
	private static function attachment( string $kind, int $id, array $row ) {
		if ( in_array( $kind, [ 'quote', 'invoice', 'credit' ], true ) ) {
			$doc_id = WB_Docs::ensure( $kind, $id );
			$doc    = $doc_id ? WB_CCT::get( 'wb_documents', $doc_id ) : null;
			if ( ! $doc || ! WB_Storage::exists( (string) $doc['storage_key'] ) ) return new WP_Error( 'wb_pdf', 'The PDF could not be made, so nothing was sent. Try again in a moment.' );
			return [ WB_Storage::path( (string) $doc['storage_key'] ), false, sanitize_file_name( (string) $doc['title'] ) . '.pdf' ];
		}
		if ( in_array( $kind, [ 'statement', 'reminder' ], true ) ) {
			$bytes = WB_Statements::pdf( $id );
			if ( '' === $bytes ) return new WP_Error( 'wb_pdf', 'The statement could not be made, so nothing was sent.' );
			$tmp = WB_Pdf::work_dir() . '/Statement-' . wb_today() . '-' . wp_generate_password( 6, false ) . '.pdf';
			file_put_contents( $tmp, $bytes );
			return [ $tmp, true, 'Statement ' . wb_today() . '.pdf' ];
		}
		[ $src, $ds, $doc ] = WB_Datasheets::current( $id, $row );
		if ( 'link' === $src ) return [ '', false, '' ];
		if ( 'data' === $src ) {
			$snap = WB_Datasheets::snapshot( $id );
			if ( is_wp_error( $snap ) ) return $snap;
			$doc = WB_CCT::get( 'wb_documents', (int) $snap );
		}
		if ( ! $doc || ! WB_Storage::exists( (string) $doc['storage_key'] ) ) return new WP_Error( 'wb_pdf', 'The datasheet file could not be found, so nothing was sent.' );
		return [ WB_Storage::path( (string) $doc['storage_key'] ), false, 'Datasheet ' . $row['sku'] . '.pdf' ];
	}

	public static function handle() {
		$kind = sanitize_key( (string) ( $_POST['kind'] ?? '' ) );
		$id   = absint( $_POST['id'] ?? 0 );
		$ctx  = self::context( $kind, $id, absint( $_POST['customer'] ?? 0 ) );
		if ( is_wp_error( $ctx ) ) return $ctx;
		[ $row, $cust ] = $ctx;
		$ticked = array_map( 'strtolower', array_map( 'sanitize_email', (array) wp_unslash( $_POST['to'] ?? [] ) ) );
		[ $more, $bad ] = self::addresses( (string) wp_unslash( $_POST['to_more'] ?? '' ) );
		if ( $bad ) return new WP_Error( 'wb_email', 'That is not an email address: ' . implode( ', ', $bad ) . '. Nothing was sent.' );
		$to = array_values( array_unique( array_filter( array_merge( $ticked, $more ), 'is_email' ) ) );
		if ( ! $to ) return new WP_Error( 'wb_email', 'Tick someone to send it to, or type an address. Nothing was sent.' );
		$subject = sanitize_text_field( (string) wp_unslash( $_POST['subject'] ?? '' ) );
		$body    = sanitize_textarea_field( (string) wp_unslash( $_POST['body'] ?? '' ) );
		if ( '' === $subject ) return new WP_Error( 'wb_subject', 'The email needs a subject. Nothing was sent.' );

		$demo = class_exists( 'WB_Demo' ) && WB_Demo::is_demo_user( get_current_user_id() );
		if ( 'quote' === $kind && in_array( (string) $row['status'], [ 'draft', 'sent' ], true ) ) {   // a draft is marked sent first; a sent quote gets a fresh acceptance link
			$link = 'draft' === (string) $row['status'] ? WB_Orders::send_quote( $id ) : WB_Orders::acceptance_url( $id );
			if ( is_wp_error( $link ) ) return $link;
			$body = str_replace( '(the acceptance link is added when you send)', (string) $link, $body );
			if ( false === strpos( $body, (string) $link ) ) $body .= "\n\nAccept the quote online: " . $link;
		}
		$file = self::attachment( $kind, $id, $row );
		if ( is_wp_error( $file ) ) return $file;
		[ $path, $temp, $name ] = $file;

		$me      = wp_get_current_user();
		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
		if ( ! empty( $me->user_email ) ) {
			$headers[] = 'Reply-To: ' . ( $me->display_name ? $me->display_name . ' ' : '' ) . '<' . $me->user_email . '>';
			if ( ! empty( $_POST['copy_me'] ) ) $headers[] = 'Cc: ' . $me->user_email;
		}
		$sent = true;
		if ( ! $demo ) {
			$attachments = [];
			if ( '' !== $path ) {   // give the attachment its readable name
				$named = WB_Pdf::work_dir() . '/' . wp_generate_password( 8, false ) . '-' . $name;
				if ( @copy( $path, $named ) ) { $attachments[] = $named; } else { $attachments[] = $path; }
			}
			$sent = wp_mail( $to, $subject, $body, $headers, $attachments );
			foreach ( $attachments as $a ) if ( $a !== $path ) @unlink( $a );
		}
		if ( $temp && '' !== $path ) @unlink( $path );
		if ( ! $sent ) return new WP_Error( 'wb_mail', 'The email could not be handed to the mail server. Nothing was recorded as sent. Check the site\'s email settings (an SMTP plugin), then try again.' );

		$cid = (int) ( $cust['_ID'] ?? 0 );
		$what = self::KINDS[ $kind ][0] . ( isset( $ctx[3]['number'] ) ? ' ' . $ctx[3]['number'] : ( 'datasheet' === $kind ? ' ' . $row['sku'] : '' ) );
		if ( $cid ) WB_Orders::touchpoint( $cid, self::KINDS[ $kind ][4], $what . ' emailed to ' . implode( ', ', $to ) . ( $demo ? ' (demo: not sent)' : '' ), $kind . ':' . $id );
		wb_ledger_write( 'document_emailed', self::KINDS[ $kind ][3], $id, null, [ 'kind' => $kind, 'to' => $to, 'by' => get_current_user_id(), 'demo' => $demo ] );
		$_POST['_wb_return'] = remove_query_arg( [ 'send', 'id', 'customer' ], (string) ( $_POST['_wb_return'] ?? '' ) );
		return [ 'msg' => $demo ? 'Recorded as sent. In the demo nothing leaves the building.' : $what . ' sent to ' . implode( ', ', $to ) . '.' ];
	}
}
