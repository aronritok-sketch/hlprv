<?php
/**
 * Árajánlatok: AI készíti a saját mintátokból, a briefből és a hívás-összefoglalókból;
 * az ügyfél egy márkázott oldalon nézi meg (tokenes link, bejelentkezés nélkül is), választható tételekkel,
 * és ott fogadja el (név, e-mail, időpont, IP, böngésző, tartalom-lenyomat). Elfogadás után egy kattintással
 * szerződés, számla-piszkozat, előfizetések vagy projekt lesz belőle.
 * Jog: „Ajánlatok” (hpv_proposals); a számlához és előfizetéshez „Számlázás”, a szerződéshez „Szerződések” is kell.
 */

defined( 'ABSPATH' ) || exit;

/* ─── Adat ─────────────────────────────────────────────────── */

function hpv_prop_json( $value ): array {
	$data = is_array( $value ) ? $value : json_decode( (string) $value, true );

	return is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : array();
}

function hpv_prop_clean_sections( $sections ): array {
	$out = array();
	foreach ( hpv_prop_json( $sections ) as $s ) {
		$title = trim( sanitize_text_field( (string) ( $s['title'] ?? '' ) ) );
		$html  = hpv_doc_clean_html( (string) ( $s['html'] ?? '' ) );
		if ( '' !== $title || '' !== $html ) {
			$out[] = array( 'title' => mb_substr( $title, 0, 160 ), 'html' => $html );
		}
	}

	return $out;
}

function hpv_prop_clean_timeline( $timeline ): array {
	$out = array();
	foreach ( hpv_prop_json( $timeline ) as $t ) {
		$phase = trim( sanitize_text_field( (string) ( $t['phase'] ?? '' ) ) );
		if ( '' === $phase ) {
			continue;
		}
		$out[] = array(
			'phase'       => mb_substr( $phase, 0, 120 ),
			'duration'    => mb_substr( trim( sanitize_text_field( (string) ( $t['duration'] ?? '' ) ) ), 0, 60 ),
			'description' => mb_substr( trim( sanitize_text_field( (string) ( $t['description'] ?? '' ) ) ), 0, 400 ),
		);
	}

	return $out;
}

function hpv_prop_clean_pricing( $pricing ): array {
	$out = array();
	foreach ( hpv_prop_json( $pricing ) as $p ) {
		$name = trim( sanitize_text_field( (string) ( $p['name'] ?? '' ) ) );
		if ( '' === $name ) {
			continue;
		}
		$qty   = (float) str_replace( ',', '.', (string) ( $p['qty'] ?? 1 ) );
		$out[] = array(
			'name'        => mb_substr( $name, 0, 160 ),
			'description' => mb_substr( trim( sanitize_text_field( (string) ( $p['description'] ?? '' ) ) ), 0, 400 ),
			'qty'         => $qty > 0 ? round( $qty, 2 ) : 1,
			'unit_price'  => hpv_p_cents_to_decimal( max( 0, hpv_p_to_cents( $p['unit_price'] ?? 0 ) ) ),
			'recurring'   => in_array( $p['recurring'] ?? '', array( 'monthly', 'yearly' ), true ) ? $p['recurring'] : 'one_time',
			'optional'    => ! empty( $p['optional'] ),
		);
	}

	return $out;
}

function hpv_prop_line_cents( array $item ): int {
	return (int) round( $item['qty'] * hpv_p_to_cents( $item['unit_price'] ) );
}

/**
 * Összesen ismétlődés szerint (centben).
 */
function hpv_prop_totals( array $items ): array {
	$t = array( 'one_time' => 0, 'monthly' => 0, 'yearly' => 0 );
	foreach ( $items as $item ) {
		$t[ $item['recurring'] ] += hpv_prop_line_cents( $item );
	}

	return $t;
}

/**
 * Az elfogadott (vagy alapértelmezett) tételek: minden nem választható, plusz a kiválasztott választhatók.
 *
 * @param array|null $chosen Az elfogadáskor bejelölt választható tételek indexei (null: a tárolt elfogadás).
 */
function hpv_prop_selected_items( array $proposal, ?array $chosen = null ): array {
	$items = hpv_prop_clean_pricing( $proposal['pricing'] );
	if ( null === $chosen && 'accepted' === $proposal['status'] ) {
		return hpv_prop_clean_pricing( $proposal['accepted_items'] );
	}
	$chosen = array_map( 'intval', (array) $chosen );

	return array_values( array_filter( $items, fn( $item, $i ) => ! $item['optional'] || in_array( $i, $chosen, true ), ARRAY_FILTER_USE_BOTH ) );
}

function hpv_prop_recurring_label( string $recurring, string $lang ): string {
	$labels = array(
		'en' => array( 'one_time' => 'one-time', 'monthly' => 'per month', 'yearly' => 'per year' ),
		'hu' => array( 'one_time' => 'egyszeri', 'monthly' => 'havonta', 'yearly' => 'évente' ),
	);

	return $labels[ 'hu' === $lang ? 'hu' : 'en' ][ $recurring ] ?? $recurring;
}

function hpv_prop_is_expired( array $p ): bool {
	return in_array( $p['status'], array( 'sent', 'viewed' ), true ) && ! empty( $p['valid_until'] ) && $p['valid_until'] < current_time( 'Y-m-d' );
}

function hpv_prop_public_url( array $p ): string {
	return hpv_p_portal_url( array( 'proposal' => $p['token'] ) );
}

/**
 * A tartalom lenyomata elfogadáskor (ha az ajánlat később változna, látszik).
 */
function hpv_prop_hash( array $p, array $items ): string {
	return hash(
		'sha256',
		wp_json_encode(
			array(
				'title'    => $p['title'],
				'tagline'  => $p['tagline'],
				'sections' => hpv_prop_clean_sections( $p['sections'] ),
				'timeline' => hpv_prop_clean_timeline( $p['timeline'] ),
				'items'    => $items,
				'currency' => $p['currency'],
			)
		)
	);
}

/* ─── AI ───────────────────────────────────────────────────── */

function hpv_prop_ai_system( string $language, string $currency ): string {
	$lang = 'hu' === $language ? 'Hungarian' : 'English (US)';

	return 'You write sales proposals for HelloProVision, a web design and digital marketing agency (websites, local SEO, Google Business Profile, ads) serving small businesses. '
		. 'Respond with a single JSON object and nothing else, in this shape: '
		. '{"title": string, "tagline": string, "sections": [{"title": string, "html": string}], "timeline": [{"phase": string, "duration": string, "description": string}], "pricing": [{"name": string, "description": string, "qty": number, "unit_price": number, "recurring": "one_time" | "monthly" | "yearly", "optional": boolean}], "valid_days": number}. '
		. 'Follow the structure and tone of the agency\'s proposal template when one is given (typically: the client\'s situation and goals, the proposed solution, scope and deliverables, why HelloProVision, next steps). '
		. 'Be specific to this client: use the brief, the call notes and the client data. Persuasive but concrete, short paragraphs, bullet lists where useful, no fluff and no superlatives without evidence. '
		. 'Never invent facts, results, guarantees, prices or dates. Prices are in ' . $currency . ': use the brief or the price list only when its currency matches; otherwise set unit_price to 0 and write [[TODO: price]] in the item description. Where any other fact is missing, write [[TODO: what is missing]]. '
		. 'Put nice-to-have add-ons in pricing with "optional": true. "html" may only use the tags p, ul, ol, li, strong, em, h3. '
		. 'Write everything in ' . $lang . '. Today is ' . wp_date( 'Y-m-d' ) . '.';
}

/**
 * @return array|WP_Error {title, tagline, sections, timeline, pricing, valid_days}
 */
function hpv_prop_ai_parse( string $text ) {
	$json = hpv_ai_json( $text );
	if ( ! $json || empty( $json['title'] ) ) {
		return new WP_Error( 'ai_format', 'Az AI válasza nem értelmezhető. Próbáld újra.' );
	}
	$sections = array();
	foreach ( (array) ( $json['sections'] ?? array() ) as $s ) {
		$sections[] = array( 'title' => (string) ( $s['title'] ?? '' ), 'html' => hpv_doc_mark_todos( (string) ( $s['html'] ?? '' ) ) );
	}

	return array(
		'title'      => mb_substr( sanitize_text_field( (string) $json['title'] ), 0, 200 ),
		'tagline'    => mb_substr( sanitize_text_field( (string) ( $json['tagline'] ?? '' ) ), 0, 300 ),
		'sections'   => hpv_prop_clean_sections( $sections ),
		'timeline'   => hpv_prop_clean_timeline( $json['timeline'] ?? array() ),
		'pricing'    => hpv_prop_clean_pricing( $json['pricing'] ?? array() ),
		'valid_days' => max( 7, min( 90, (int) ( $json['valid_days'] ?? 30 ) ) ),
	);
}

/**
 * Ajánlat AI-val: minta + brief + hívás-összefoglalók + árlista.
 *
 * @return array|WP_Error
 */
function hpv_prop_ai_generate( array $client, ?array $template, array $opts ) {
	$currency = hpv_p_client_currency( (int) $client['id'] );
	$calls    = '';
	foreach ( array_map( 'absint', (array) ( $opts['call_ids'] ?? array() ) ) as $call_id ) {
		$call = hpv_p_get( 'call', $call_id );
		if ( ! $call || (int) $call['client_id'] !== (int) $client['id'] || '' === (string) $call['summary'] ) {
			continue;
		}
		$ai     = json_decode( (string) $call['ai_data'], true );
		$calls .= '<call date="' . substr( (string) $call['started_at'], 0, 10 ) . '" title="' . esc_attr( $call['title'] ) . "\">\nSummary: " . $call['summary']
			. ( ! empty( $ai['decisions'] ) ? "\nDecisions: " . implode( '; ', $ai['decisions'] ) : '' )
			. ( ! empty( $ai['action_items'] ) ? "\nNext steps: " . implode( '; ', array_column( $ai['action_items'], 'title' ) ) : '' )
			. ( ! empty( $ai['internal_notes'] ) ? "\nInternal notes (do not quote): " . implode( '; ', $ai['internal_notes'] ) : '' )
			. "\n</call>\n";
	}
	$catalog = array();
	foreach ( hpv_p_find( 'service', array( 'active' => 1 ), array( 'orderby' => 'name', 'order' => 'ASC' ) ) as $svc ) {
		$catalog[] = '- ' . $svc['name'] . ': ' . $svc['price'] . ' USD, ' . strtolower( hpv_p_option_label( 'service', 'billing', $svc['billing'], 'en' ) ) . ( $svc['description'] ? ' — ' . wp_strip_all_tags( $svc['description'] ) : '' );
	}

	$user = hpv_doc_client_context( $client )
		. "<brief>\n" . ( trim( (string) ( $opts['brief'] ?? '' ) ) ?: '(none)' ) . "\n</brief>\n"
		. ( $calls ? "<call_notes>\n" . $calls . "</call_notes>\n" : '' )
		. "<price_list currency=\"USD\">\n" . ( $catalog ? implode( "\n", $catalog ) : '(empty)' ) . "\n</price_list>\n"
		. ( $template ? "<template name=\"" . esc_attr( $template['name'] ) . "\">\n" . $template['body'] . "\n</template>\n" . ( $template['instructions'] ? "<standing_instructions>\n" . $template['instructions'] . "\n</standing_instructions>\n" : '' ) : "<template>(none)</template>\n" )
		. '<proposal_currency>' . $currency . '</proposal_currency>';

	$text = hpv_ai_complete( hpv_prop_ai_system( (string) $opts['language'], $currency ), $user, 8000, 180 );

	return is_wp_error( $text ) ? $text : hpv_prop_ai_parse( $text );
}

/**
 * Meglévő ajánlat módosítása AI-val (a teljes ajánlat JSON-ként megy oda-vissza).
 *
 * @return array|WP_Error
 */
function hpv_prop_ai_revise( array $proposal, string $instructions ) {
	$current = array(
		'title'      => $proposal['title'],
		'tagline'    => $proposal['tagline'],
		'sections'   => hpv_prop_clean_sections( $proposal['sections'] ),
		'timeline'   => hpv_prop_clean_timeline( $proposal['timeline'] ),
		'pricing'    => hpv_prop_clean_pricing( $proposal['pricing'] ),
		'valid_days' => 30,
	);
	$system  = hpv_prop_ai_system( (string) $proposal['language'], (string) $proposal['currency'] )
		. ' You are editing an existing proposal: apply the change request and return the complete updated proposal in the same JSON shape. Change only what the request needs; keep everything else as it is.';
	$text    = hpv_ai_complete( $system, "<proposal>\n" . wp_json_encode( $current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n</proposal>\n<change_request>\n" . $instructions . "\n</change_request>", 8000, 180 );

	return is_wp_error( $text ) ? $text : hpv_prop_ai_parse( $text );
}

/* ─── Formázás a webalkalmazásnak ─────────────────────────── */

function hpv_prop_format( array $p, bool $full = false ): array {
	$client = hpv_p_get( 'client', (int) $p['client_id'] );
	$items  = hpv_prop_clean_pricing( $p['pricing'] );
	$out    = array(
		'id'          => (int) $p['id'],
		'number'      => $p['number'],
		'title'       => $p['title'],
		'status'      => hpv_prop_is_expired( $p ) ? 'expired' : $p['status'],
		'language'    => $p['language'] ?: 'en',
		'currency'    => $p['currency'] ?: 'USD',
		'client'      => $client ? array(
			'id'      => (int) $client['id'],
			'name'    => $client['name'],
			'country' => 'HU' === $client['country'] ? 'HU' : 'US',
			'status'  => $client['status'],
		) : null,
		'totals'      => hpv_prop_totals( array_values( array_filter( $items, fn( $i ) => ! $i['optional'] ) ) ),
		'valid_until' => (string) $p['valid_until'],
		'sent_at'     => hpv_video_ts( $p['sent_at'] ),
		'view_count'  => (int) $p['view_count'],
		'last_viewed_at' => hpv_video_ts( $p['last_viewed_at'] ),
		'accepted_at' => hpv_video_ts( $p['accepted_at'] ),
		'updated_at'  => hpv_video_ts( $p['updated_at'] ),
	);
	if ( $full ) {
		$out = array_merge(
			$out,
			array(
				'tagline'     => (string) $p['tagline'],
				'sections'    => hpv_prop_clean_sections( $p['sections'] ),
				'timeline'    => hpv_prop_clean_timeline( $p['timeline'] ),
				'pricing'     => $items,
				'notes'       => (string) $p['notes'],
				'project_id'  => (int) $p['project_id'],
				'url'         => hpv_prop_public_url( $p ),
				'todos'       => count( hpv_doc_todos( implode( ' ', array_column( hpv_prop_clean_sections( $p['sections'] ), 'html' ) ) . ' ' . implode( ' ', array_column( $items, 'description' ) ) ) ),
				'first_viewed_at' => hpv_video_ts( $p['first_viewed_at'] ),
				'acceptance'  => 'accepted' === $p['status'] ? array(
					'name'   => $p['accepted_name'],
					'email'  => $p['accepted_email'],
					'ip'     => $p['accepted_ip'],
					'hash'   => $p['accepted_hash'],
					'items'  => hpv_prop_clean_pricing( $p['accepted_items'] ),
					'totals' => hpv_prop_totals( hpv_prop_clean_pricing( $p['accepted_items'] ) ),
				) : null,
				'declined'    => 'declined' === $p['status'] ? array( 'at' => hpv_video_ts( $p['declined_at'] ), 'reason' => (string) $p['decline_reason'] ) : null,
				'contract_id' => (int) $p['contract_id'],
				'invoice_id'  => (int) $p['invoice_id'],
				'invoice_url' => (int) $p['invoice_id'] ? hpv_p_crm_app_url( '/invoices/' . (int) $p['invoice_id'] ) : '',
			)
		);
	}

	return $out;
}

/* ─── Létrehozás, kiküldés ────────────────────────────────── */

function hpv_prop_create( array $client, array $data, int $user_id ): int {
	$language = in_array( $data['language'] ?? '', array( 'en', 'hu' ), true ) ? $data['language'] : hpv_doc_client_language( (int) $client['id'] );
	$id       = hpv_p_insert(
		'proposal',
		array(
			'client_id'   => (int) $client['id'],
			'project_id'  => absint( $data['project_id'] ?? 0 ),
			'title'       => mb_substr( (string) ( $data['title'] ?? '' ), 0, 200 ) ?: ( 'hu' === $language ? 'Ajánlat — ' : 'Proposal — ' ) . $client['name'],
			'tagline'     => (string) ( $data['tagline'] ?? '' ),
			'language'    => $language,
			'status'      => 'draft',
			'sections'    => wp_json_encode( hpv_prop_clean_sections( $data['sections'] ?? array() ) ),
			'timeline'    => wp_json_encode( hpv_prop_clean_timeline( $data['timeline'] ?? array() ) ),
			'pricing'     => wp_json_encode( hpv_prop_clean_pricing( $data['pricing'] ?? array() ) ),
			'currency'    => hpv_p_client_currency( (int) $client['id'] ),
			'valid_until' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . (int) ( $data['valid_days'] ?? 30 ) . ' days' ) ),
			'token'       => bin2hex( random_bytes( 16 ) ),
			'template_id' => absint( $data['template_id'] ?? 0 ),
			'created_by'  => $user_id,
			'view_count'  => 0,
		)
	);
	hpv_p_update( 'proposal', $id, array( 'number' => 'P-' . current_time( 'Y' ) . '-' . str_pad( (string) $id, 3, '0', STR_PAD_LEFT ) ) );

	return $id;
}

function hpv_prop_strings( string $lang ): array {
	$s = array(
		'en' => array(
			'eyebrow'       => 'Proposal for %s',
			'prepared_by'   => 'Prepared by',
			'date'          => 'Date',
			'valid'         => 'Valid until',
			'number'        => 'Proposal',
			'timeline'      => 'Timeline',
			'investment'    => 'Investment',
			'item'          => 'Item',
			'qty'           => 'Qty',
			'amount'        => 'Amount',
			'optional'      => 'Optional',
			'add'           => 'Add to proposal',
			'total_one_time' => 'One-time total',
			'total_monthly' => 'Monthly total',
			'total_yearly'  => 'Yearly total',
			'tax_note'      => 'Prices exclude sales tax where applicable.',
			'accept_title'  => 'Ready to get started?',
			'accept_text'   => 'Accept the proposal and we will send you the agreement and the next steps.',
			'name'          => 'Your full name',
			'email'         => 'Email',
			'agree'         => 'I accept this proposal on behalf of %s and understand that this electronic acceptance is binding.',
			'accept_btn'    => 'Accept proposal',
			'decline_link'  => 'Not quite right? Tell us what to change',
			'decline_reason' => 'What should we change?',
			'decline_btn'   => 'Decline proposal',
			'accepted'      => 'Accepted by %1$s on %2$s.',
			'declined'      => 'This proposal was declined on %s.',
			'expired'       => 'This proposal expired on %s. Contact us and we will send you an updated version.',
			'withdrawn'     => 'This proposal is no longer available.',
			'thanks'        => 'Thank you! Your acceptance is recorded. We will be in touch shortly with the agreement and next steps.',
			'decline_thanks' => 'Thank you for letting us know. We will follow up.',
			'preview'       => 'Staff preview: views are not counted and the form is disabled.',
			'print'         => 'Download PDF',
			'questions'     => 'Questions? Write to',
			'error_name'    => 'Please type your full name and a valid email, and tick the box to accept.',
			'mail_subject'  => 'Proposal: %s',
			'mail_heading'  => 'Your proposal is ready',
			'mail_body'     => '<p>%1$s prepared a proposal for %2$s: <strong>%3$s</strong>.</p><p>You can review it, choose options and accept it online. It is valid until %4$s.</p>',
			'mail_cta'      => 'View proposal',
			'confirm_subject' => 'You accepted: %s',
			'confirm_body'  => '<p>Thank you, %1$s! You accepted <strong>%2$s</strong> on %3$s.</p><p>We will send you the agreement and the next steps shortly.</p>',
			'date_format'   => 'F j, Y',
		),
		'hu' => array(
			'eyebrow'       => 'Ajánlat — %s részére',
			'prepared_by'   => 'Készítette',
			'date'          => 'Dátum',
			'valid'         => 'Érvényes',
			'number'        => 'Ajánlat',
			'timeline'      => 'Ütemterv',
			'investment'    => 'Befektetés',
			'item'          => 'Tétel',
			'qty'           => 'Menny.',
			'amount'        => 'Összeg',
			'optional'      => 'Választható',
			'add'           => 'Hozzáadom',
			'total_one_time' => 'Egyszeri díj összesen',
			'total_monthly' => 'Havi díj összesen',
			'total_yearly'  => 'Éves díj összesen',
			'tax_note'      => 'Az árak nettó árak, az ÁFA-t a számla tartalmazza.',
			'accept_title'  => 'Kezdhetjük?',
			'accept_text'   => 'Fogadja el az ajánlatot, és elküldjük a szerződést és a következő lépéseket.',
			'name'          => 'Teljes név',
			'email'         => 'E-mail',
			'agree'         => 'A(z) %s nevében elfogadom az ajánlatot, és tudomásul veszem, hogy az elektronikus elfogadás kötelező érvényű.',
			'accept_btn'    => 'Ajánlat elfogadása',
			'decline_link'  => 'Nem egészen ez kell? Írja meg, mit változtassunk',
			'decline_reason' => 'Mit változtassunk?',
			'decline_btn'   => 'Ajánlat elutasítása',
			'accepted'      => 'Elfogadta: %1$s, %2$s.',
			'declined'      => 'Az ajánlatot elutasították: %s.',
			'expired'       => 'Az ajánlat %s-án lejárt. Keressen minket, és küldünk frisset.',
			'withdrawn'     => 'Ez az ajánlat már nem érhető el.',
			'thanks'        => 'Köszönjük! Az elfogadást rögzítettük, hamarosan küldjük a szerződést és a következő lépéseket.',
			'decline_thanks' => 'Köszönjük a visszajelzést, hamarosan jelentkezünk.',
			'preview'       => 'Munkatársi előnézet: a megnyitás nem számít, az űrlap nem működik.',
			'print'         => 'Letöltés PDF-ben',
			'questions'     => 'Kérdése van? Írjon:',
			'error_name'    => 'Kérjük, adja meg a teljes nevét és e-mail címét, és jelölje be az elfogadást.',
			'mail_subject'  => 'Ajánlat: %s',
			'mail_heading'  => 'Elkészült az ajánlata',
			'mail_body'     => '<p>%1$s ajánlatot készített a(z) %2$s részére: <strong>%3$s</strong>.</p><p>Online megnézheti, kiválaszthatja a kiegészítőket és elfogadhatja. Érvényes: %4$s.</p>',
			'mail_cta'      => 'Ajánlat megtekintése',
			'confirm_subject' => 'Elfogadta: %s',
			'confirm_body'  => '<p>Köszönjük, %1$s! %3$s-án elfogadta: <strong>%2$s</strong>.</p><p>Hamarosan küldjük a szerződést és a következő lépéseket.</p>',
			'date_format'   => 'Y. F j.',
		),
	);

	return $s[ 'hu' === $lang ? 'hu' : 'en' ];
}

/**
 * Kiküldés e-mailben (a portál-felhasználóknak és/vagy a megadott címre).
 *
 * @return array|WP_Error
 */
function hpv_prop_send( array $p, string $to, string $message, int $user_id ) {
	if ( ! in_array( $p['status'], array( 'draft', 'sent', 'viewed' ), true ) ) {
		return new WP_Error( 'status', 'Ez az ajánlat már nem küldhető ki.' );
	}
	if ( ! hpv_prop_clean_pricing( $p['pricing'] ) && ! hpv_prop_clean_sections( $p['sections'] ) ) {
		return new WP_Error( 'empty', 'Az ajánlat üres.' );
	}
	$items = hpv_prop_clean_pricing( $p['pricing'] );
	$todos = hpv_doc_todos( implode( ' ', array_column( hpv_prop_clean_sections( $p['sections'] ), 'html' ) ) . ' ' . implode( ' ', array_column( $items, 'description' ) ) );
	if ( $todos ) {
		return new WP_Error( 'todos', 'Még van kitöltetlen rész: ' . implode( '; ', array_slice( $todos, 0, 5 ) ) );
	}
	$client = hpv_p_get( 'client', (int) $p['client_id'] );
	$to     = sanitize_email( $to ) ?: hpv_p_client_billing_email( $client );
	if ( ! is_email( $to ) ) {
		return new WP_Error( 'email', 'Adj meg egy e-mail címet, ahová az ajánlat menjen.' );
	}
	$str  = hpv_prop_strings( $p['language'] );
	$who  = hpv_chat_user_label( $user_id )['name'];
	$body = sprintf( $str['mail_body'], esc_html( $who ), esc_html( $client['name'] ), esc_html( $p['title'] ), esc_html( $p['valid_until'] ? wp_date( $str['date_format'], strtotime( $p['valid_until'] ) ) : '—' ) )
		. ( '' !== trim( $message ) ? '<p>' . nl2br( esc_html( $message ) ) . '</p>' : '' );
	$sent = hpv_p_send( $to, sprintf( $str['mail_subject'], $p['title'] ), hpv_p_email_html( $str['mail_heading'], $body, $str['mail_cta'], hpv_prop_public_url( $p ) ), hpv_p_settings()['company_email'] );
	if ( ! $sent ) {
		return new WP_Error( 'mail', 'Az e-mail küldése nem sikerült.' );
	}
	hpv_p_update(
		'proposal',
		(int) $p['id'],
		array(
			'status'  => 'draft' === $p['status'] ? 'sent' : $p['status'],
			'sent_at' => current_time( 'mysql', true ),
		)
	);
	hpv_p_log( (int) $p['client_id'], 'system', sprintf( 'Ajánlat kiküldve: %s (%s) → %s', $p['number'], $p['title'], $to ), false, $user_id );
	do_action( 'hpv_proposal_sent', $p, $user_id );

	return hpv_p_get( 'proposal', (int) $p['id'] );
}

/* ─── Elfogadás után ──────────────────────────────────────── */

/**
 * @param string $what contract | invoice | subscriptions | project
 * @return array|WP_Error {proposal, target: {type, id, url}}
 */
function hpv_prop_convert( array $p, string $what, array $args, int $user_id ) {
	if ( 'accepted' !== $p['status'] ) {
		return new WP_Error( 'status', 'Csak elfogadott ajánlatból lehet.' );
	}
	$client = hpv_p_get( 'client', (int) $p['client_id'] );
	$items  = hpv_prop_selected_items( $p );

	switch ( $what ) {
		case 'contract':
			if ( ! hpv_p_can( 'contracts', $user_id ) ) {
				return new WP_Error( 'forbidden', 'Szerződéshez „Szerződések” jog kell.' );
			}
			$req = new WP_REST_Request( 'POST', '/hpv/v1/docs/contracts' );
			$req->set_body_params(
				array(
					'client_id'    => (int) $client['id'],
					'proposal_id'  => (int) $p['id'],
					'template_id'  => absint( $args['template_id'] ?? 0 ),
					'language'     => $p['language'],
					'instructions' => (string) ( $args['instructions'] ?? '' ),
					'ai'           => ! empty( $args['ai'] ) && hpv_ai_enabled() ? 1 : 0,
				)
			);
			$res = hpv_doc_rest_create_contract( $req );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$target = array( 'type' => 'contract', 'id' => (int) $res->get_data()['id'] );
			break;

		case 'invoice':
			if ( ! hpv_p_can( 'invoices', $user_id ) ) {
				return new WP_Error( 'forbidden', 'Számlához „Számlázás” jog kell.' );
			}
			$one_time = array_values( array_filter( $items, fn( $i ) => 'one_time' === $i['recurring'] ) );
			if ( ! $one_time ) {
				return new WP_Error( 'items', 'Az ajánlatban nincs egyszeri tétel. A havi díjakat előfizetésként vedd fel.' );
			}
			$deposit = max( 0, min( 100, (int) ( $args['deposit'] ?? 100 ) ) );
			$lines   = array();
			foreach ( $one_time as $i ) {
				$lines[] = array(
					'description' => $i['name'] . ( $deposit < 100 ? ' — ' . ( 'hu' === $p['language'] ? $deposit . '% előleg' : $deposit . '% deposit' ) : '' ),
					'quantity'    => (string) $i['qty'],
					'unit_price'  => hpv_p_cents_to_decimal( (int) round( hpv_p_to_cents( $i['unit_price'] ) * $deposit / 100 ) ),
				);
			}
			$is_hu = 'HU' === $client['country'];
			$id    = hpv_p_insert(
				'invoice',
				array(
					'client_id'  => (int) $client['id'],
					'status'     => 'draft',
					'currency'   => hpv_p_client_currency( (int) $client['id'] ),
					'issue_date' => current_time( 'Y-m-d' ),
					'due_date'   => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . (int) hpv_p_settings()['payment_terms'] . ' days' ) ),
					'tax_rate'   => $is_hu ? hpv_p_vat_rate( hpv_p_settings()['hu_vat_key'] ) : '0',
					'notes'      => ( $is_hu ? 'Ajánlat: ' : 'Proposal: ' ) . $p['number'],
				)
			);
			hpv_p_save_invoice_items( $id, $lines );
			hpv_p_update( 'proposal', (int) $p['id'], array( 'invoice_id' => $id ) );
			$target = array( 'type' => 'invoice', 'id' => $id, 'url' => hpv_p_crm_app_url( '/invoices/' . $id ) );
			break;

		case 'subscriptions':
			if ( ! hpv_p_can( 'invoices', $user_id ) ) {
				return new WP_Error( 'forbidden', 'Előfizetéshez „Számlázás” jog kell.' );
			}
			$recurring = array_values( array_filter( $items, fn( $i ) => 'one_time' !== $i['recurring'] ) );
			if ( ! $recurring ) {
				return new WP_Error( 'items', 'Az ajánlatban nincs havi vagy éves tétel.' );
			}
			$ids = array();
			foreach ( $recurring as $i ) {
				$ids[] = hpv_p_insert(
					'subscription',
					array(
						'client_id'         => (int) $client['id'],
						'name'              => $i['name'],
						'description'       => $i['description'],
						'price'             => hpv_p_cents_to_decimal( hpv_prop_line_cents( $i ) ),
						'billing'           => $i['recurring'],
						'status'            => 'active',
						'start_date'        => current_time( 'Y-m-d' ),
						'next_invoice_date' => current_time( 'Y-m-d' ),
					)
				);
			}
			$target = array( 'type' => 'subscriptions', 'ids' => $ids, 'url' => admin_url( 'admin.php?page=hpv-crm&client=' . (int) $client['id'] ) );
			break;

		case 'project':
			$id = hpv_p_insert(
				'project',
				array(
					'client_id'   => (int) $client['id'],
					'name'        => $p['title'],
					'description' => ( 'hu' === $p['language'] ? 'Ajánlat: ' : 'Proposal: ' ) . $p['number'],
					'status'      => 'planning',
					'start_date'  => current_time( 'Y-m-d' ),
					'visible'     => 1,
					'owner_id'    => $user_id,
					'color'       => '#b8ff34',
				)
			);
			foreach ( hpv_prop_clean_timeline( $p['timeline'] ) as $n => $step ) {
				hpv_p_insert(
					'task',
					array(
						'project_id'  => $id,
						'title'       => $step['phase'],
						'description' => trim( $step['duration'] . ' — ' . $step['description'], ' —' ),
						'status'      => 'todo',
						'visible'     => 1,
						'sort'        => $n,
						'created_by'  => $user_id,
					)
				);
			}
			hpv_p_update( 'proposal', (int) $p['id'], array( 'project_id' => $id ) );
			$target = array( 'type' => 'project', 'id' => $id );
			break;

		default:
			return new WP_Error( 'what', 'Ismeretlen művelet.' );
	}

	return array(
		'proposal' => hpv_prop_format( hpv_p_get( 'proposal', (int) $p['id'] ), true ),
		'target'   => $target,
	);
}

/* ─── REST ────────────────────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_prop_routes' );

function hpv_prop_routes() {
	$can   = fn() => hpv_p_can( 'proposals' );
	$route = function ( string $path, string $methods, callable $callback ) use ( $can ) {
		register_rest_route(
			'hpv/v1',
			'/docs/proposals' . $path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $can,
			)
		);
	};
	$route( '', 'GET', 'hpv_prop_rest_list' );
	$route( '', 'POST', 'hpv_prop_rest_create' );
	$route( '/(?P<id>\d+)', 'GET', 'hpv_prop_rest_get' );
	$route( '/(?P<id>\d+)', 'POST', 'hpv_prop_rest_update' );
	$route( '/(?P<id>\d+)', 'DELETE', 'hpv_prop_rest_delete' );
	$route( '/(?P<id>\d+)/revise', 'POST', 'hpv_prop_rest_revise' );
	$route( '/(?P<id>\d+)/send', 'POST', 'hpv_prop_rest_send' );
	$route( '/(?P<id>\d+)/convert', 'POST', 'hpv_prop_rest_convert' );
	register_rest_route(
		'hpv/v1',
		'/docs/leads',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => 'hpv_prop_rest_lead',
		)
	);
}

function hpv_prop_rest_list( WP_REST_Request $request ) {
	$where = array();
	if ( $request->get_param( 'client_id' ) ) {
		$where['client_id'] = absint( $request->get_param( 'client_id' ) );
	}

	return rest_ensure_response( array_map( 'hpv_prop_format', hpv_p_find( 'proposal', $where, array( 'orderby' => 'updated_at', 'limit' => 500 ) ) ) );
}

function hpv_prop_rest_get( WP_REST_Request $request ) {
	$p = hpv_p_get( 'proposal', (int) $request['id'] );

	return $p ? rest_ensure_response( hpv_prop_format( $p, true ) ) : hpv_doc_not_found();
}

/**
 * Új ajánlat: AI-val (minta + brief + hívások), vagy a mintából egy fejezetként, vagy üresen.
 */
function hpv_prop_rest_create( WP_REST_Request $request ) {
	$client = hpv_p_get( 'client', absint( $request->get_param( 'client_id' ) ) );
	if ( ! $client ) {
		return new WP_Error( 'client', 'Válassz ügyfelet.', array( 'status' => 400 ) );
	}
	$template = $request->get_param( 'template_id' ) ? hpv_p_get( 'doc_template', absint( $request->get_param( 'template_id' ) ) ) : null;
	if ( $template && 'proposal' !== $template['type'] ) {
		return new WP_Error( 'template', 'Ez nem ajánlatminta.', array( 'status' => 400 ) );
	}
	$language = in_array( $request->get_param( 'language' ), array( 'en', 'hu' ), true ) ? $request->get_param( 'language' ) : ( $template['language'] ?? hpv_doc_client_language( (int) $client['id'] ) );
	$data     = array(
		'language'    => $language,
		'project_id'  => absint( $request->get_param( 'project_id' ) ),
		'template_id' => (int) ( $template['id'] ?? 0 ),
		'title'       => trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) ),
	);
	if ( rest_sanitize_boolean( $request->get_param( 'ai' ) ) ) {
		$ai = hpv_prop_ai_generate(
			$client,
			$template,
			array(
				'language' => $language,
				'brief'    => sanitize_textarea_field( (string) $request->get_param( 'brief' ) ),
				'call_ids' => (array) $request->get_param( 'call_ids' ),
			)
		);
		if ( is_wp_error( $ai ) ) {
			return new WP_Error( $ai->get_error_code(), $ai->get_error_message(), array( 'status' => 502 ) );
		}
		$data = array_merge( $data, $ai, $data['title'] ? array( 'title' => $data['title'] ) : array() );
	} elseif ( $template ) {
		$data['sections'] = array( array( 'title' => '', 'html' => $template['body'] ) );
	}
	$id = hpv_prop_create( $client, $data, get_current_user_id() );

	return rest_ensure_response( hpv_prop_format( hpv_p_get( 'proposal', $id ), true ) );
}

function hpv_prop_rest_update( WP_REST_Request $request ) {
	$p = hpv_p_get( 'proposal', (int) $request['id'] );
	if ( ! $p ) {
		return hpv_doc_not_found();
	}
	if ( in_array( $p['status'], array( 'accepted', 'declined', 'void' ), true ) ) {
		return new WP_Error( 'locked', 'Elfogadott, elutasított vagy visszavont ajánlat nem módosítható.', array( 'status' => 409 ) );
	}
	$data = array();
	foreach ( array( 'title', 'tagline' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$data[ $key ] = mb_substr( trim( sanitize_text_field( (string) $request->get_param( $key ) ) ), 0, 'title' === $key ? 200 : 300 );
		}
	}
	if ( isset( $data['title'] ) && '' === $data['title'] ) {
		unset( $data['title'] );
	}
	if ( null !== $request->get_param( 'notes' ) ) {
		$data['notes'] = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
	}
	if ( null !== $request->get_param( 'valid_until' ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $request->get_param( 'valid_until' ) ) ) {
		$data['valid_until'] = $request->get_param( 'valid_until' );
	}
	if ( in_array( $request->get_param( 'language' ), array( 'en', 'hu' ), true ) ) {
		$data['language'] = $request->get_param( 'language' );
	}
	foreach ( array( 'sections', 'timeline', 'pricing' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$fn           = 'hpv_prop_clean_' . $key;
			$data[ $key ] = wp_json_encode( $fn( $request->get_param( $key ) ) );
		}
	}
	if ( $data ) {
		hpv_p_update( 'proposal', (int) $p['id'], $data );
	}

	return rest_ensure_response( hpv_prop_format( hpv_p_get( 'proposal', (int) $p['id'] ), true ) );
}

function hpv_prop_rest_delete( WP_REST_Request $request ) {
	$p = hpv_p_get( 'proposal', (int) $request['id'] );
	if ( ! $p ) {
		return hpv_doc_not_found();
	}
	if ( 'draft' === $p['status'] ) {
		hpv_p_delete( 'proposal', (int) $p['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}
	if ( 'accepted' === $p['status'] ) {
		return new WP_Error( 'locked', 'Elfogadott ajánlat nem vonható vissza.', array( 'status' => 409 ) );
	}
	hpv_p_update( 'proposal', (int) $p['id'], array( 'status' => 'void' ) );

	return rest_ensure_response( hpv_prop_format( hpv_p_get( 'proposal', (int) $p['id'] ), true ) );
}

function hpv_prop_rest_revise( WP_REST_Request $request ) {
	$p = hpv_p_get( 'proposal', (int) $request['id'] );
	if ( ! $p ) {
		return hpv_doc_not_found();
	}
	if ( in_array( $p['status'], array( 'accepted', 'declined', 'void' ), true ) ) {
		return new WP_Error( 'locked', 'Ez az ajánlat már nem módosítható.', array( 'status' => 409 ) );
	}
	$instructions = trim( sanitize_textarea_field( (string) $request->get_param( 'instructions' ) ) );
	if ( '' === $instructions ) {
		return new WP_Error( 'instructions', 'Írd le, mit változtasson az AI.', array( 'status' => 400 ) );
	}
	$ai = hpv_prop_ai_revise( $p, $instructions );
	if ( is_wp_error( $ai ) ) {
		return new WP_Error( $ai->get_error_code(), $ai->get_error_message(), array( 'status' => 502 ) );
	}
	hpv_p_update(
		'proposal',
		(int) $p['id'],
		array(
			'title'    => $ai['title'],
			'tagline'  => $ai['tagline'],
			'sections' => wp_json_encode( $ai['sections'] ),
			'timeline' => wp_json_encode( $ai['timeline'] ),
			'pricing'  => wp_json_encode( $ai['pricing'] ),
		)
	);

	return rest_ensure_response( hpv_prop_format( hpv_p_get( 'proposal', (int) $p['id'] ), true ) );
}

function hpv_prop_rest_send( WP_REST_Request $request ) {
	$p = hpv_p_get( 'proposal', (int) $request['id'] );
	if ( ! $p ) {
		return hpv_doc_not_found();
	}
	$res = hpv_prop_send( $p, (string) $request->get_param( 'to' ), sanitize_textarea_field( (string) $request->get_param( 'message' ) ), get_current_user_id() );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 409 ) );
	}

	return rest_ensure_response( hpv_prop_format( $res, true ) );
}

function hpv_prop_rest_convert( WP_REST_Request $request ) {
	$p = hpv_p_get( 'proposal', (int) $request['id'] );
	if ( ! $p ) {
		return hpv_doc_not_found();
	}
	$res = hpv_prop_convert( $p, sanitize_key( (string) $request->get_param( 'what' ) ), $request->get_params(), get_current_user_id() );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 'forbidden' === $res->get_error_code() ? 403 : 409 ) );
	}

	return rest_ensure_response( $res );
}

/**
 * Új érdeklődő gyorsan (az ajánlat ablakból): ügyfél „Érdeklődő” státusszal.
 */
function hpv_prop_rest_lead( WP_REST_Request $request ) {
	$data = hpv_p_sanitize(
		'client',
		array(
			'name'         => $request->get_param( 'name' ),
			'contact_name' => $request->get_param( 'contact_name' ),
			'email'        => $request->get_param( 'email' ),
			'country'      => $request->get_param( 'country' ),
			'website'      => $request->get_param( 'website' ),
			'status'       => 'lead',
		)
	);
	if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
		return new WP_Error( 'name', 'Add meg a cég nevét.', array( 'status' => 400 ) );
	}
	$id = hpv_p_insert( 'client', $data );

	return rest_ensure_response(
		array(
			'id'      => $id,
			'name'    => $data['name'],
			'status'  => 'lead',
			'country' => 'HU' === $data['country'] ? 'HU' : 'US',
		)
	);
}

/* ─── Publikus ajánlat oldal ──────────────────────────────── */

add_action( 'template_redirect', 'hpv_prop_public_route', 0 );

function hpv_prop_public_route() {
	$token = isset( $_GET['proposal'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_GET['proposal'] ) : '';
	if ( 32 !== strlen( $token ) ) {
		return;
	}
	$p = hpv_p_find( 'proposal', array( 'token' => $token ), array( 'limit' => 1 ) )[0] ?? null;
	if ( ! $p || 'draft' === $p['status'] && ! hpv_p_can( 'proposals' ) ) {
		status_header( 404 );
		nocache_headers();
		echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Not found</title><p style="font-family:system-ui;padding:40px">This proposal is not available.</p>';
		exit;
	}

	$notice = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['hpv_prop_action'] ) ) {
		$notice = hpv_prop_handle_post( $p );
		$p      = hpv_p_get( 'proposal', (int) $p['id'] );
	} elseif ( ! hpv_p_is_staff() ) {
		hpv_prop_track_view( $p );
		$p = hpv_p_get( 'proposal', (int) $p['id'] );
	}

	status_header( 200 );
	nocache_headers();
	hpv_prop_render( $p, $notice );
	exit;
}

function hpv_prop_track_view( array $p ): void {
	if ( ! in_array( $p['status'], array( 'sent', 'viewed', 'accepted', 'declined' ), true ) ) {
		return;
	}
	$now  = current_time( 'mysql', true );
	$data = array(
		'view_count'     => (int) $p['view_count'] + 1,
		'last_viewed_at' => $now,
	);
	if ( ! $p['first_viewed_at'] ) {
		$data['first_viewed_at'] = $now;
		hpv_p_notify_staff( sprintf( 'Megnyitották az ajánlatot: %s — %s', $p['number'], hpv_p_client_name_safe( (int) $p['client_id'] ) ), '<p>' . esc_html( $p['title'] ) . '</p>', hpv_p_crm_app_url( '/proposals/' . (int) $p['id'] ) );
	}
	if ( 'sent' === $p['status'] ) {
		$data['status'] = 'viewed';
	}
	hpv_p_update( 'proposal', (int) $p['id'], $data );
}

/**
 * Elfogadás / elutasítás. Visszaad egy üzenet-kulcsot a megjelenítéshez.
 */
function hpv_prop_handle_post( array $p ): string {
	$str = hpv_prop_strings( $p['language'] );
	if ( ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ?? '' ), 'hpv_prop_' . $p['token'] ) || hpv_p_is_staff() ) {
		return 'error';
	}
	if ( ! in_array( $p['status'], array( 'sent', 'viewed' ), true ) || hpv_prop_is_expired( $p ) ) {
		return '';
	}
	$ip   = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
	$rate = 'hpv_prop_rate_' . md5( $ip );
	if ( (int) get_transient( $rate ) >= 10 ) {
		return 'error';
	}
	set_transient( $rate, (int) get_transient( $rate ) + 1, HOUR_IN_SECONDS );

	$client = hpv_p_get( 'client', (int) $p['client_id'] );
	$action = sanitize_key( $_POST['hpv_prop_action'] );

	if ( 'decline' === $action ) {
		$reason = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ), 0, 2000 );
		hpv_p_update( 'proposal', (int) $p['id'], array( 'status' => 'declined', 'declined_at' => current_time( 'mysql', true ), 'decline_reason' => $reason ) );
		hpv_p_log( (int) $p['client_id'], 'system', sprintf( 'Ajánlat elutasítva: %s. Indok: %s', $p['number'], $reason ?: '—' ), false );
		hpv_p_notify_staff( sprintf( 'Elutasították az ajánlatot: %s — %s', $p['number'], $client['name'] ), '<p>' . esc_html( $p['title'] ) . '</p><p><strong>Indok:</strong> ' . nl2br( esc_html( $reason ?: '—' ) ) . '</p>', hpv_p_crm_app_url( '/proposals/' . (int) $p['id'] ) );
		return 'declined';
	}

	$name  = trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	if ( mb_strlen( $name ) < 3 || ! is_email( $email ) || empty( $_POST['agree'] ) ) {
		return 'error_name';
	}
	$all    = hpv_prop_clean_pricing( $p['pricing'] );
	$chosen = array_values( array_filter( array_map( 'intval', (array) ( $_POST['options'] ?? array() ) ), fn( $i ) => isset( $all[ $i ] ) && $all[ $i ]['optional'] ) );
	$items  = hpv_prop_selected_items( $p, $chosen );
	$totals = hpv_prop_totals( $items );
	$now    = current_time( 'mysql', true );

	hpv_p_update(
		'proposal',
		(int) $p['id'],
		array(
			'status'         => 'accepted',
			'accepted_at'    => $now,
			'accepted_name'  => $name,
			'accepted_email' => $email,
			'accepted_ip'    => $ip,
			'accepted_agent' => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 250 ),
			'accepted_items' => wp_json_encode( $items ),
			'accepted_hash'  => hpv_prop_hash( $p, $items ),
		)
	);
	if ( 'lead' === $client['status'] ) {
		hpv_p_update( 'client', (int) $client['id'], array( 'status' => 'active' ) );
	}
	$cur   = $p['currency'] ?: 'USD';
	$money = array_filter(
		array(
			$totals['one_time'] ? hpv_p_money( $totals['one_time'], $cur ) . ' ' . hpv_prop_recurring_label( 'one_time', 'hu' ) : '',
			$totals['monthly'] ? hpv_p_money( $totals['monthly'], $cur ) . ' ' . hpv_prop_recurring_label( 'monthly', 'hu' ) : '',
			$totals['yearly'] ? hpv_p_money( $totals['yearly'], $cur ) . ' ' . hpv_prop_recurring_label( 'yearly', 'hu' ) : '',
		)
	);
	hpv_p_log( (int) $p['client_id'], 'system', sprintf( 'Ajánlat elfogadva: %s (%s) — %s, %s', $p['number'], $p['title'], $name, implode( ' + ', $money ) ), false );
	hpv_p_notify_staff(
		sprintf( '🎉 Elfogadták az ajánlatot: %s — %s', $p['number'], $client['name'] ),
		'<p><strong>' . esc_html( $p['title'] ) . '</strong></p><p>Elfogadta: ' . esc_html( $name ) . ' (' . esc_html( $email ) . ')<br>Összeg: ' . esc_html( implode( ' + ', $money ) ) . '</p><p>Következő lépés: szerződés, számla vagy projekt egy kattintással a CRM-ben.</p>',
		hpv_p_crm_app_url( '/proposals/' . (int) $p['id'] )
	);
	hpv_p_send(
		$email,
		sprintf( $str['confirm_subject'], $p['title'] ),
		hpv_p_email_html( $str['mail_heading'], sprintf( $str['confirm_body'], esc_html( $name ), esc_html( $p['title'] ), esc_html( wp_date( $str['date_format'] ) ) ), $str['mail_cta'], hpv_prop_public_url( $p ) ),
		hpv_p_settings()['company_email']
	);

	return 'accepted';
}

/**
 * A márkázott ajánlat oldal (angol vagy magyar), nyomtatható.
 */
function hpv_prop_render( array $p, string $notice ) {
	$s        = hpv_p_settings();
	$str      = hpv_prop_strings( $p['language'] );
	$client   = hpv_p_get( 'client', (int) $p['client_id'] );
	$author   = get_userdata( (int) $p['created_by'] );
	$cur      = $p['currency'] ?: 'USD';
	$sections = hpv_prop_clean_sections( $p['sections'] );
	$timeline = hpv_prop_clean_timeline( $p['timeline'] );
	$items    = 'accepted' === $p['status'] ? hpv_prop_clean_pricing( $p['accepted_items'] ) : hpv_prop_clean_pricing( $p['pricing'] );
	$expired  = hpv_prop_is_expired( $p );
	$open     = in_array( $p['status'], array( 'sent', 'viewed' ), true ) && ! $expired;
	$staff    = hpv_p_is_staff();
	$date     = fn( $d ) => $d ? wp_date( $str['date_format'], strtotime( $d ) ) : '—';
	$user     = wp_get_current_user();
	$css      = plugins_url( 'assets/proposal.css', HPV_PORTAL_FILE ) . '?ver=' . HPV_PORTAL_VERSION;
	$groups   = array( 'one_time' => $str['total_one_time'], 'monthly' => $str['total_monthly'], 'yearly' => $str['total_yearly'] );
	$per      = array( 'one_time' => '', 'monthly' => 'hu' === $p['language'] ? ' / hó' : ' / mo', 'yearly' => 'hu' === $p['language'] ? ' / év' : ' / yr' );
	?>
<!doctype html>
<html lang="<?php echo esc_attr( 'hu' === $p['language'] ? 'hu' : 'en' ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $p['title'] . ' — ' . $s['company_name'] ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>">
</head>
<body class="prop">
	<?php if ( $staff ) : ?><div class="prop-staff"><?php echo esc_html( $str['preview'] ); ?></div><?php endif; ?>
	<header class="prop-hero">
		<div class="prop-wrap">
			<div class="prop-hero__top">
				<span class="prop-brand">Hello<span>ProVision</span></span>
				<button type="button" class="prop-print" onclick="window.print()"><?php echo esc_html( $str['print'] ); ?></button>
			</div>
			<p class="prop-eyebrow"><?php echo esc_html( sprintf( $str['eyebrow'], $client['name'] ?? '' ) ); ?></p>
			<h1><?php echo esc_html( $p['title'] ); ?></h1>
			<?php if ( $p['tagline'] ) : ?><p class="prop-tagline"><?php echo esc_html( $p['tagline'] ); ?></p><?php endif; ?>
			<dl class="prop-meta">
				<div><dt><?php echo esc_html( $str['number'] ); ?></dt><dd><?php echo esc_html( $p['number'] ); ?></dd></div>
				<div><dt><?php echo esc_html( $str['date'] ); ?></dt><dd><?php echo esc_html( $date( $p['sent_at'] ?: $p['created_at'] ) ); ?></dd></div>
				<div><dt><?php echo esc_html( $str['valid'] ); ?></dt><dd><?php echo esc_html( $date( $p['valid_until'] ) ); ?></dd></div>
				<?php if ( $author ) : ?><div><dt><?php echo esc_html( $str['prepared_by'] ); ?></dt><dd><?php echo esc_html( $author->display_name ); ?></dd></div><?php endif; ?>
			</dl>
		</div>
	</header>

	<main class="prop-wrap prop-main">
		<?php
		$messages = array(
			'accepted'   => array( 'ok', $str['thanks'] ),
			'declined'   => array( 'info', $str['decline_thanks'] ),
			'error_name' => array( 'error', $str['error_name'] ),
			'error'      => array( 'error', $str['error_name'] ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="prop-alert prop-alert--' . esc_attr( $messages[ $notice ][0] ) . '">' . esc_html( $messages[ $notice ][1] ) . '</div>';
		}
		if ( 'accepted' === $p['status'] && 'accepted' !== $notice ) {
			echo '<div class="prop-alert prop-alert--ok">' . esc_html( sprintf( $str['accepted'], $p['accepted_name'], $date( $p['accepted_at'] ) ) ) . '</div>';
		} elseif ( 'declined' === $p['status'] && 'declined' !== $notice ) {
			echo '<div class="prop-alert prop-alert--info">' . esc_html( sprintf( $str['declined'], $date( $p['declined_at'] ) ) ) . '</div>';
		} elseif ( 'void' === $p['status'] ) {
			echo '<div class="prop-alert prop-alert--info">' . esc_html( $str['withdrawn'] ) . '</div>';
		} elseif ( $expired ) {
			echo '<div class="prop-alert prop-alert--info">' . esc_html( sprintf( $str['expired'], $date( $p['valid_until'] ) ) ) . '</div>';
		}
		?>

		<?php foreach ( $sections as $n => $section ) : ?>
			<section class="prop-section">
				<?php if ( $section['title'] ) : ?>
					<h2><span class="prop-num"><?php echo esc_html( str_pad( (string) ( $n + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span><?php echo esc_html( $section['title'] ); ?></h2>
				<?php endif; ?>
				<div class="prop-body"><?php echo wp_kses_post( $section['html'] ); ?></div>
			</section>
		<?php endforeach; ?>

		<?php if ( $timeline ) : ?>
			<section class="prop-section">
				<h2><span class="prop-num"><?php echo esc_html( str_pad( (string) ( count( $sections ) + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span><?php echo esc_html( $str['timeline'] ); ?></h2>
				<ol class="prop-timeline">
					<?php foreach ( $timeline as $step ) : ?>
						<li><strong><?php echo esc_html( $step['phase'] ); ?></strong><?php if ( $step['duration'] ) : ?><span class="prop-duration"><?php echo esc_html( $step['duration'] ); ?></span><?php endif; ?><?php if ( $step['description'] ) : ?><p><?php echo esc_html( $step['description'] ); ?></p><?php endif; ?></li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>

		<?php if ( $items ) : ?>
			<section class="prop-section prop-pricing" data-currency="<?php echo esc_attr( $cur ); ?>">
				<h2><span class="prop-num"><?php echo esc_html( str_pad( (string) ( count( $sections ) + ( $timeline ? 2 : 1 ) ), 2, '0', STR_PAD_LEFT ) ); ?></span><?php echo esc_html( $str['investment'] ); ?></h2>
				<form method="post" id="prop-form" class="prop-form">
					<table class="prop-table">
						<thead><tr><th><?php echo esc_html( $str['item'] ); ?></th><th class="num"><?php echo esc_html( $str['qty'] ); ?></th><th class="num"><?php echo esc_html( $str['amount'] ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $items as $i => $item ) : ?>
								<tr class="<?php echo $item['optional'] ? 'is-optional' : ''; ?>" data-cents="<?php echo (int) hpv_prop_line_cents( $item ); ?>" data-recurring="<?php echo esc_attr( $item['recurring'] ); ?>" data-optional="<?php echo $item['optional'] ? '1' : '0'; ?>">
									<td>
										<strong><?php echo esc_html( $item['name'] ); ?></strong>
										<?php if ( $item['optional'] ) : ?><span class="prop-tag"><?php echo esc_html( $str['optional'] ); ?></span><?php endif; ?>
										<?php if ( $item['description'] ) : ?><p><?php echo esc_html( $item['description'] ); ?></p><?php endif; ?>
										<?php if ( $item['optional'] && $open ) : ?><label class="prop-add"><input type="checkbox" name="options[]" value="<?php echo (int) $i; ?>"> <?php echo esc_html( $str['add'] ); ?></label><?php endif; ?>
									</td>
									<td class="num"><?php echo esc_html( rtrim( rtrim( number_format( $item['qty'], 2, '.', '' ), '0' ), '.' ) ); ?></td>
									<td class="num"><?php echo esc_html( hpv_p_money( hpv_prop_line_cents( $item ), $cur ) . $per[ $item['recurring'] ] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<?php foreach ( $groups as $key => $label ) : ?>
								<?php $sum = array_sum( array_map( 'hpv_prop_line_cents', array_filter( $items, fn( $it ) => $key === $it['recurring'] && ( ! $it['optional'] || 'accepted' === $p['status'] ) ) ) ); ?>
								<?php $has = (bool) array_filter( $items, fn( $it ) => $key === $it['recurring'] ); ?>
								<?php if ( $has ) : ?>
									<tr class="prop-total" data-total="<?php echo esc_attr( $key ); ?>"><td colspan="2"><?php echo esc_html( $label ); ?></td><td class="num" data-sum><?php echo esc_html( hpv_p_money( $sum, $cur ) . $per[ $key ] ); ?></td></tr>
								<?php endif; ?>
							<?php endforeach; ?>
						</tfoot>
					</table>
					<p class="prop-note"><?php echo esc_html( $str['tax_note'] ); ?></p>

					<?php if ( $open ) : ?>
						<div class="prop-accept">
							<h2><?php echo esc_html( $str['accept_title'] ); ?></h2>
							<p><?php echo esc_html( $str['accept_text'] ); ?></p>
							<?php wp_nonce_field( 'hpv_prop_' . $p['token'] ); ?>
							<div class="prop-fields">
								<label><span><?php echo esc_html( $str['name'] ); ?></span><input type="text" name="name" autocomplete="name" required value="<?php echo esc_attr( $user->ID && ! $staff ? $user->display_name : '' ); ?>" <?php disabled( $staff ); ?>></label>
								<label><span><?php echo esc_html( $str['email'] ); ?></span><input type="email" name="email" autocomplete="email" required value="<?php echo esc_attr( $user->ID && ! $staff ? $user->user_email : ( $client['email'] ?? '' ) ); ?>" <?php disabled( $staff ); ?>></label>
							</div>
							<label class="prop-check"><input type="checkbox" name="agree" value="1" required <?php disabled( $staff ); ?>> <span><?php echo esc_html( sprintf( $str['agree'], $client['name'] ?? '' ) ); ?></span></label>
							<button class="prop-btn" name="hpv_prop_action" value="accept" <?php disabled( $staff ); ?>><?php echo esc_html( $str['accept_btn'] ); ?></button>
							<details class="prop-decline">
								<summary><?php echo esc_html( $str['decline_link'] ); ?></summary>
								<label><span><?php echo esc_html( $str['decline_reason'] ); ?></span><textarea name="reason" rows="3" <?php disabled( $staff ); ?>></textarea></label>
								<button class="prop-btn prop-btn--ghost" name="hpv_prop_action" value="decline" formnovalidate <?php disabled( $staff ); ?>><?php echo esc_html( $str['decline_btn'] ); ?></button>
							</details>
						</div>
					<?php endif; ?>
				</form>
			</section>
		<?php endif; ?>
	</main>

	<footer class="prop-footer">
		<div class="prop-wrap">
			<span class="prop-brand">Hello<span>ProVision</span></span>
			<span><?php echo esc_html( $str['questions'] ); ?> <a href="mailto:<?php echo esc_attr( $s['company_email'] ); ?>"><?php echo esc_html( $s['company_email'] ); ?></a> · <?php echo esc_html( $s['company_phone'] ); ?></span>
		</div>
	</footer>
	<?php if ( $open ) : ?>
	<script>
	(function () {
		// Választható tételek: az összesen sorok élőben frissülnek.
		var root = document.querySelector('.prop-pricing');
		if (!root) return;
		var cur = root.getAttribute('data-currency');
		var per = <?php echo wp_json_encode( $per ); ?>;
		function fmt(c) {
			if (cur === 'HUF') return Math.round(c / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
			return (cur === 'EUR' ? '€' : '$') + (c / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		}
		function recalc() {
			var sums = { one_time: 0, monthly: 0, yearly: 0 };
			root.querySelectorAll('tbody tr').forEach(function (tr) {
				var box = tr.querySelector('input[type=checkbox]');
				var on = tr.getAttribute('data-optional') === '0' || (box && box.checked);
				tr.classList.toggle('is-selected', !!(box && box.checked));
				if (on) sums[tr.getAttribute('data-recurring')] += parseInt(tr.getAttribute('data-cents'), 10);
			});
			root.querySelectorAll('[data-total]').forEach(function (tr) {
				var k = tr.getAttribute('data-total');
				tr.querySelector('[data-sum]').textContent = fmt(sums[k]) + per[k];
			});
		}
		root.addEventListener('change', recalc);
	})();
	</script>
	<?php endif; ?>
</body>
</html>
	<?php
}

/* ─── Portál: az ügyfél ajánlatai ─────────────────────────── */

function hpv_prop_portal_list( int $client_id ): array {
	return array_values( array_filter( hpv_p_find( 'proposal', array( 'client_id' => $client_id ) ), fn( $p ) => in_array( $p['status'], array( 'sent', 'viewed', 'accepted', 'declined' ), true ) ) );
}

function hpv_pv_proposals( int $client_id ) {
	$list = hpv_prop_portal_list( $client_id );
	hpv_p_portal_header( hpv_t( 'Proposals' ), hpv_t( 'Proposals from your HelloProVision team.' ) );
	if ( ! $list ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'No proposals yet.' ) ) . '</p>';
		return;
	}
	echo '<div class="hpv-cards">';
	foreach ( $list as $p ) {
		$status = hpv_prop_is_expired( $p ) ? 'expired' : $p['status'];
		$labels = array( 'sent' => 'Awaiting your review', 'viewed' => 'Awaiting your review', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired' );
		$totals = hpv_prop_totals( hpv_prop_selected_items( $p ) );
		?>
		<a class="hpv-card-link" href="<?php echo esc_url( hpv_prop_public_url( $p ) ); ?>" target="_blank" rel="noopener">
			<span class="hpv-mini-project__top"><strong><?php echo esc_html( $p['title'] ); ?></strong><span class="hpv-pill hpv-pill--<?php echo esc_attr( 'accepted' === $status ? 'paid' : ( in_array( $status, array( 'sent', 'viewed' ), true ) ? 'client' : 'void' ) ); ?>"><?php echo esc_html( hpv_t( $labels[ $status ] ?? $status ) ); ?></span></span>
			<small><?php echo esc_html( $p['number'] ); ?><?php echo $p['valid_until'] ? esc_html( hpv_t( ' · valid until %s', hpv_date( $p['valid_until'] ) ) ) : ''; ?></small>
			<span class="hpv-muted"><?php echo esc_html( implode( ' + ', array_filter( array( $totals['one_time'] ? hpv_p_money( $totals['one_time'], $p['currency'] ?: 'USD' ) : '', $totals['monthly'] ? hpv_t( '%s / month', hpv_p_money( $totals['monthly'], $p['currency'] ?: 'USD' ) ) : '' ) ) ) ); ?></span>
		</a>
		<?php
	}
	echo '</div>';
}
