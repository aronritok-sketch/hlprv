<?php
/**
 * Szerződések és minták a CRM webalkalmazásban, AI-val.
 * A saját szerződésmintátokból az AI az adott ügyfélre szabja a szerződést (az ügyfél nyelvén),
 * a hiányzó adatot [[TODO: …]] jelöléssel hagyja, és ilyen jelöléssel nem lehet aláírásra küldeni.
 * Jog: „Szerződések” (hpv_contracts), a mintáknál a típus szerinti jog.
 */

defined( 'ABSPATH' ) || exit;

/* ─── HTML tisztítás ──────────────────────────────────────── */

/**
 * Dokumentum-HTML: csak szerkezet (címsor, bekezdés, lista, táblázat, kiemelés, link), stílus és szkript nélkül.
 * Word-ből másolt és AI által írt szöveghez is.
 */
function hpv_doc_clean_html( string $html ): string {
	$allowed = array(
		'h2'         => array(),
		'h3'         => array(),
		'h4'         => array(),
		'p'          => array(),
		'br'         => array(),
		'strong'     => array(),
		'b'          => array(),
		'em'         => array(),
		'i'          => array(),
		'u'          => array(),
		'ul'         => array(),
		'ol'         => array(),
		'li'         => array(),
		'table'      => array(),
		'thead'      => array(),
		'tbody'      => array(),
		'tr'         => array(),
		'th'         => array(),
		'td'         => array(),
		'blockquote' => array(),
		'mark'       => array(),
		'a'          => array( 'href' => true ),
		'hr'         => array(),
	);
	// Word/Google Docs szemét: megjegyzések, stílus- és szkriptblokkok tartalommal együtt.
	$html = preg_replace( '#<!--.*?-->|<(style|script|xml|head)[^>]*>.*?</\1>#is', '', $html );
	$html = preg_replace( '#<(/?)h1\b#i', '<$1h2', (string) $html );
	$html = wp_kses( (string) $html, $allowed );
	$html = preg_replace( '#<(p|li|h2|h3|h4)>\s*(?:&nbsp;|\s)*</\1>#i', '', $html );

	return trim( (string) $html );
}

/**
 * Kitöltetlen részek ([[TODO: …]]) a szövegben.
 */
function hpv_doc_todos( string $html ): array {
	preg_match_all( '/\[\[\s*TODO:?\s*([^\]]*)\]\]/u', wp_strip_all_tags( $html ), $m );

	return array_map( 'trim', $m[1] );
}

function hpv_doc_client_language( int $client_id ): string {
	return 'HU' === hpv_p_client_country( $client_id ) ? 'hu' : 'en';
}

/**
 * Az ügyfél és az ügynökség adatai az AI-nak (szerződéshez, ajánlathoz).
 */
function hpv_doc_client_context( array $client ): string {
	$s = hpv_p_settings();

	return "<agency>\nName: " . $s['company_name'] . "\nLegal name: " . $s['company_legal'] . "\nAddress: " . str_replace( "\n", ', ', $s['company_address'] ) . "\nEmail: " . $s['company_email'] . "\nPhone: " . $s['company_phone'] . "\n</agency>\n"
		. "<client>\nCompany: " . ( $client['billing_name'] ?: $client['name'] ) . "\nTrading name: " . $client['name']
		. "\nCountry: " . ( 'HU' === $client['country'] ? 'Hungary' : 'United States' )
		. "\nAddress: " . implode( ', ', hpv_p_client_address_lines( $client ) )
		. "\nContact person: " . $client['contact_name'] . "\nEmail: " . hpv_p_client_billing_email( $client ) . "\nPhone: " . $client['phone'] . "\nWebsite: " . $client['website'] . "\n</client>\n";
}

/* ─── Minták ──────────────────────────────────────────────── */

function hpv_doc_format_template( array $t ): array {
	return array(
		'id'           => (int) $t['id'],
		'type'         => $t['type'],
		'name'         => $t['name'],
		'language'     => $t['language'],
		'body'         => (string) $t['body'],
		'instructions' => (string) $t['instructions'],
		'updated_at'   => hpv_video_ts( $t['updated_at'] ),
	);
}

function hpv_doc_template_cap( string $type ): string {
	return 'proposal' === $type ? 'proposals' : 'contracts';
}

/* ─── Szerződés ───────────────────────────────────────────── */

/**
 * A szerződéshez adott szolgáltatások: az elfogadott ajánlat tételei, vagy az ügyfél aktív előfizetései.
 */
function hpv_doc_services_context( array $client, ?array $proposal ): string {
	$currency = hpv_p_client_currency( (int) $client['id'] );
	$lines    = array();
	if ( $proposal ) {
		foreach ( hpv_prop_selected_items( $proposal ) as $item ) {
			$lines[] = '- ' . $item['name'] . ( $item['description'] ? ' — ' . $item['description'] : '' ) . ': ' . hpv_p_money( hpv_p_to_cents( $item['unit_price'] ) * (float) $item['qty'], $currency ) . ' (' . hpv_prop_recurring_label( $item['recurring'], 'en' ) . ')';
		}
		$lines[] = 'Proposal ' . $proposal['number'] . ' "' . $proposal['title'] . '", accepted ' . ( $proposal['accepted_at'] ? substr( $proposal['accepted_at'], 0, 10 ) : '(not yet)' ) . '.';
		foreach ( hpv_prop_json( $proposal['timeline'] ) as $step ) {
			$lines[] = 'Timeline: ' . ( $step['phase'] ?? '' ) . ' — ' . ( $step['duration'] ?? '' );
		}
	} else {
		foreach ( hpv_p_find( 'subscription', array( 'client_id' => (int) $client['id'], 'status' => 'active' ) ) as $sub ) {
			$lines[] = '- ' . $sub['name'] . ': ' . hpv_p_money( $sub['price'], $currency ) . ' (' . strtolower( hpv_p_option_label( 'subscription', 'billing', $sub['billing'], 'en' ) ) . ')';
		}
	}

	return "<services currency=\"$currency\">\n" . ( $lines ? implode( "\n", $lines ) : '(none given)' ) . "\n</services>\n";
}

/**
 * AI szerződés-tervezet a mintából.
 *
 * @return string|WP_Error HTML
 */
function hpv_doc_ai_contract( array $client, ?array $template, array $opts ) {
	$lang    = 'hu' === ( $opts['language'] ?? '' ) ? 'Hungarian' : 'English (US)';
	$law     = 'HU' === $client['country'] ? 'Hungarian law' : 'the laws of the State of Florida';
	$system  = 'You draft service contracts for HelloProVision, a web design and digital marketing agency. You adapt the agency\'s own contract template to one specific client. Rules: '
		. '1) Keep the template\'s structure, clause order, headings and legal wording. Change only what the client, the services, fees, dates and the instructions require. Never drop protective clauses (payment terms, intellectual property, liability, confidentiality, termination, governing law) unless the instructions say so. '
		. '2) Fill in the parties, scope, deliverables, fees and payment schedule from the data given. The agency\'s party details written in the template take precedence over the agency block. '
		. '3) Never invent facts, prices, dates or legal details. Where something is missing, write a visible placeholder exactly like [[TODO: what is missing]]. '
		. '4) If no template is given, write a clear, professional services agreement governed by ' . $law . ', with the usual clauses for a web design / marketing engagement. '
		. '5) Write the whole contract in ' . $lang . '. '
		. '6) Output only the contract body as clean HTML using only these tags: h2, h3, p, ul, ol, li, strong, em, table, thead, tbody, tr, th, td, br. No markdown, no code fences, no html/head/body tags, no inline styles, and no comments before or after the contract. '
		. 'Today is ' . wp_date( 'Y-m-d' ) . '.';
	$project = ! empty( $opts['project_id'] ) ? hpv_p_get( 'project', (int) $opts['project_id'] ) : null;
	$user    = hpv_doc_client_context( $client )
		. ( $project ? "<project>\nName: " . $project['name'] . "\nStart: " . $project['start_date'] . "\nDeadline: " . $project['due_date'] . "\nDescription: " . wp_strip_all_tags( (string) $project['description'] ) . "\n</project>\n" : '' )
		. hpv_doc_services_context( $client, $opts['proposal'] ?? null )
		. ( $template ? "<template name=\"" . esc_attr( $template['name'] ) . "\">\n" . $template['body'] . "\n</template>\n" . ( $template['instructions'] ? "<standing_instructions>\n" . $template['instructions'] . "\n</standing_instructions>\n" : '' ) : "<template>(none)</template>\n" )
		. '<instructions>' . ( trim( (string) ( $opts['instructions'] ?? '' ) ) ?: '(none)' ) . "</instructions>\n";

	$text = hpv_ai_complete( $system, $user, 12000, 180 );

	return is_wp_error( $text ) ? $text : hpv_doc_mark_todos( hpv_ai_html( $text ) );
}

/**
 * AI módosítás egy meglévő szövegen (szerződés).
 *
 * @return string|WP_Error HTML
 */
function hpv_doc_ai_revise_contract( array $contract, string $instructions ) {
	$lang   = 'hu' === $contract['language'] ? 'Hungarian' : 'English (US)';
	$system = 'You edit a service contract of HelloProVision, a web design and digital marketing agency. Apply the requested change and return the complete, updated contract. '
		. 'Change only what the request needs; keep every other word, clause and heading as it is. Never invent facts, prices or dates: use [[TODO: what is missing]] placeholders instead. '
		. 'Keep the language (' . $lang . '). Output only the contract body as clean HTML with only these tags: h2, h3, p, ul, ol, li, strong, em, table, thead, tbody, tr, th, td, br. No markdown, no code fences, no commentary.';
	$user   = "<contract>\n" . $contract['body'] . "\n</contract>\n<change_request>\n" . $instructions . "\n</change_request>";
	$text   = hpv_ai_complete( $system, $user, 12000, 180 );

	return is_wp_error( $text ) ? $text : hpv_doc_mark_todos( hpv_ai_html( $text ) );
}

/**
 * A [[TODO: …]] jelölések kiemelése (a szerkesztőben és az előnézetben sárgán látszik).
 */
function hpv_doc_mark_todos( string $html ): string {
	return (string) preg_replace( '/(?<!<mark>)(\[\[\s*TODO[^\]]*\]\])(?!<\/mark>)/u', '<mark>$1</mark>', $html );
}

function hpv_doc_format_contract( array $c, bool $full = false ): array {
	$client = hpv_p_get( 'client', (int) $c['client_id'] );
	$out    = array(
		'id'          => (int) $c['id'],
		'title'       => $c['title'],
		'status'      => $c['status'],
		'language'    => $c['language'] ?: 'en',
		'client'      => $client ? array(
			'id'      => (int) $client['id'],
			'name'    => $client['name'],
			'country' => 'HU' === $client['country'] ? 'HU' : 'US',
		) : null,
		'project_id'  => (int) $c['project_id'],
		'proposal_id' => (int) $c['proposal_id'],
		'sent_at'     => hpv_video_ts( $c['sent_at'] ),
		'signed_at'   => hpv_video_ts( $c['signed_at'] ),
		'updated_at'  => hpv_video_ts( $c['updated_at'] ),
		'todos'       => count( hpv_doc_todos( (string) $c['body'] ) ),
	);
	if ( $full ) {
		$out['body']    = (string) $c['body'];
		$out['preview'] = hpv_p_portal_url( array( 'preview_client' => (int) $c['client_id'], 'view' => 'contracts', 'id' => (int) $c['id'] ) );
		$out['signer']  = 'signed' === $c['status'] ? array(
			'name'  => $c['signer_name'],
			'email' => $c['signer_email'],
			'ip'    => $c['signer_ip'],
			'hash'  => $c['body_hash'],
		) : null;
	}

	return $out;
}

/* ─── REST ────────────────────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_doc_routes' );

function hpv_doc_routes() {
	$route = function ( string $path, string $methods, callable $callback, callable $permission ) {
		register_rest_route(
			'hpv/v1',
			'/docs' . $path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $permission,
			)
		);
	};
	$contracts = fn() => hpv_p_can( 'contracts' );
	$any       = fn() => hpv_p_can( 'contracts' ) || hpv_p_can( 'proposals' );

	$route( '/templates', 'GET', 'hpv_doc_rest_templates', $any );
	$route( '/templates', 'POST', 'hpv_doc_rest_save_template', $any );
	$route( '/templates/(?P<id>\d+)', 'POST', 'hpv_doc_rest_save_template', $any );
	$route( '/templates/(?P<id>\d+)', 'DELETE', 'hpv_doc_rest_delete_template', $any );
	$route( '/context', 'GET', 'hpv_doc_rest_context', $any );

	$route( '/contracts', 'GET', 'hpv_doc_rest_contracts', $contracts );
	$route( '/contracts', 'POST', 'hpv_doc_rest_create_contract', $contracts );
	$route( '/contracts/(?P<id>\d+)', 'GET', 'hpv_doc_rest_contract', $contracts );
	$route( '/contracts/(?P<id>\d+)', 'POST', 'hpv_doc_rest_update_contract', $contracts );
	$route( '/contracts/(?P<id>\d+)', 'DELETE', 'hpv_doc_rest_delete_contract', $contracts );
	$route( '/contracts/(?P<id>\d+)/revise', 'POST', 'hpv_doc_rest_revise_contract', $contracts );
	$route( '/contracts/(?P<id>\d+)/send', 'POST', 'hpv_doc_rest_send_contract', $contracts );
}

function hpv_doc_not_found() {
	return new WP_Error( 'not_found', 'Nem található.', array( 'status' => 404 ) );
}

function hpv_doc_rest_templates( WP_REST_Request $request ) {
	$types = array_values( array_filter( array( 'contract', 'proposal' ), fn( $t ) => hpv_p_can( hpv_doc_template_cap( $t ) ) ) );
	$type  = sanitize_key( (string) $request->get_param( 'type' ) );
	if ( $type ) {
		$types = array_intersect( $types, array( $type ) );
	}

	return rest_ensure_response(
		array(
			'ai'        => hpv_ai_enabled(),
			'templates' => array_map( 'hpv_doc_format_template', $types ? hpv_p_find( 'doc_template', array( 'type' => array_values( $types ) ), array( 'orderby' => 'name', 'order' => 'ASC' ) ) : array() ),
		)
	);
}

function hpv_doc_rest_save_template( WP_REST_Request $request ) {
	$id  = (int) ( $request['id'] ?? 0 );
	$old = $id ? hpv_p_get( 'doc_template', $id ) : null;
	if ( $id && ! $old ) {
		return hpv_doc_not_found();
	}
	$type = $old ? $old['type'] : ( 'proposal' === $request->get_param( 'type' ) ? 'proposal' : 'contract' );
	if ( ! hpv_p_can( hpv_doc_template_cap( $type ) ) ) {
		return new WP_Error( 'forbidden', 'Ehhez a mintához nincs jogod.', array( 'status' => 403 ) );
	}
	$data = array();
	foreach ( array( 'name', 'language', 'instructions' ) as $key ) {
		if ( null !== $request->get_param( $key ) ) {
			$data[ $key ] = hpv_p_sanitize( 'doc_template', array( $key => $request->get_param( $key ) ) )[ $key ];
		}
	}
	if ( null !== $request->get_param( 'body' ) ) {
		$data['body'] = hpv_doc_clean_html( (string) $request->get_param( 'body' ) );
	}
	if ( $old ) {
		hpv_p_update( 'doc_template', $id, $data );
	} else {
		if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
			return new WP_Error( 'name', 'Adj nevet a mintának.', array( 'status' => 400 ) );
		}
		$id = hpv_p_insert( 'doc_template', array_merge( array( 'type' => $type, 'created_by' => get_current_user_id() ), $data ) );
	}

	return rest_ensure_response( hpv_doc_format_template( hpv_p_get( 'doc_template', $id ) ) );
}

function hpv_doc_rest_delete_template( WP_REST_Request $request ) {
	$t = hpv_p_get( 'doc_template', (int) $request['id'] );
	if ( ! $t ) {
		return hpv_doc_not_found();
	}
	if ( ! hpv_p_can( hpv_doc_template_cap( $t['type'] ) ) ) {
		return new WP_Error( 'forbidden', 'Ehhez a mintához nincs jogod.', array( 'status' => 403 ) );
	}
	hpv_p_delete( 'doc_template', (int) $t['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * Egy ügyfélhez: projektek, hívás-összefoglalók, elfogadott ajánlatok (az „új” ablakokhoz).
 */
function hpv_doc_rest_context( WP_REST_Request $request ) {
	$client = hpv_p_get( 'client', absint( $request->get_param( 'client_id' ) ) );
	if ( ! $client ) {
		return hpv_doc_not_found();
	}
	$calls = array_values( array_filter( hpv_p_find( 'call', array( 'client_id' => (int) $client['id'] ), array( 'limit' => 20 ) ), fn( $c ) => '' !== (string) $c['summary'] ) );

	return rest_ensure_response(
		array(
			'language'  => hpv_doc_client_language( (int) $client['id'] ),
			'currency'  => hpv_p_client_currency( (int) $client['id'] ),
			'projects'  => array_map( fn( $p ) => array( 'id' => (int) $p['id'], 'name' => $p['name'] ), hpv_p_find( 'project', array( 'client_id' => (int) $client['id'], 'is_template' => 0 ) ) ),
			'calls'     => array_map( fn( $c ) => array( 'id' => (int) $c['id'], 'title' => $c['title'], 'date' => substr( (string) $c['started_at'], 0, 10 ), 'summary' => $c['summary'] ), $calls ),
			'proposals' => hpv_p_can( 'proposals' ) || hpv_p_can( 'contracts' ) ? array_map( fn( $p ) => array( 'id' => (int) $p['id'], 'title' => $p['title'], 'number' => $p['number'], 'status' => $p['status'] ), hpv_p_find( 'proposal', array( 'client_id' => (int) $client['id'] ) ) ) : array(),
		)
	);
}

function hpv_doc_rest_contracts( WP_REST_Request $request ) {
	$where = array();
	if ( $request->get_param( 'client_id' ) ) {
		$where['client_id'] = absint( $request->get_param( 'client_id' ) );
	}

	return rest_ensure_response( array_map( 'hpv_doc_format_contract', hpv_p_find( 'contract', $where, array( 'orderby' => 'updated_at', 'limit' => 500 ) ) ) );
}

function hpv_doc_rest_contract( WP_REST_Request $request ) {
	$c = hpv_p_get( 'contract', (int) $request['id'] );

	return $c ? rest_ensure_response( hpv_doc_format_contract( $c, true ) ) : hpv_doc_not_found();
}

/**
 * Új szerződés: mintából, AI-val (ha kérik és van kulcs), vagy üresen.
 */
function hpv_doc_rest_create_contract( WP_REST_Request $request ) {
	$client = hpv_p_get( 'client', absint( $request->get_param( 'client_id' ) ) );
	if ( ! $client ) {
		return new WP_Error( 'client', 'Válassz ügyfelet.', array( 'status' => 400 ) );
	}
	$template = $request->get_param( 'template_id' ) ? hpv_p_get( 'doc_template', absint( $request->get_param( 'template_id' ) ) ) : null;
	if ( $template && 'contract' !== $template['type'] ) {
		return new WP_Error( 'template', 'Ez nem szerződésminta.', array( 'status' => 400 ) );
	}
	$proposal = $request->get_param( 'proposal_id' ) ? hpv_p_get( 'proposal', absint( $request->get_param( 'proposal_id' ) ) ) : null;
	if ( $proposal && (int) $proposal['client_id'] !== (int) $client['id'] ) {
		return new WP_Error( 'proposal', 'Az ajánlat nem ehhez az ügyfélhez tartozik.', array( 'status' => 400 ) );
	}
	$project_id = absint( $request->get_param( 'project_id' ) ?: ( $proposal['project_id'] ?? 0 ) );
	$language   = in_array( $request->get_param( 'language' ), array( 'en', 'hu' ), true ) ? $request->get_param( 'language' ) : ( $template['language'] ?? hpv_doc_client_language( (int) $client['id'] ) );
	$title      = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );
	if ( '' === $title ) {
		$title = ( 'hu' === $language ? 'Szolgáltatási szerződés — ' : 'Services Agreement — ' ) . $client['name'];
	}

	$body = $template ? (string) $template['body'] : '';
	if ( rest_sanitize_boolean( $request->get_param( 'ai' ) ) ) {
		$body = hpv_doc_ai_contract(
			$client,
			$template,
			array(
				'language'     => $language,
				'project_id'   => $project_id,
				'proposal'     => $proposal,
				'instructions' => (string) $request->get_param( 'instructions' ),
			)
		);
		if ( is_wp_error( $body ) ) {
			return new WP_Error( $body->get_error_code(), $body->get_error_message(), array( 'status' => 502 ) );
		}
	}

	$id = hpv_p_insert(
		'contract',
		array(
			'client_id'   => (int) $client['id'],
			'title'       => mb_substr( $title, 0, 200 ),
			'body'        => $body,
			'status'      => 'draft',
			'language'    => $language,
			'project_id'  => $project_id,
			'template_id' => (int) ( $template['id'] ?? 0 ),
			'proposal_id' => (int) ( $proposal['id'] ?? 0 ),
			'created_by'  => get_current_user_id(),
		)
	);
	if ( $proposal ) {
		hpv_p_update( 'proposal', (int) $proposal['id'], array( 'contract_id' => $id ) );
	}

	return rest_ensure_response( hpv_doc_format_contract( hpv_p_get( 'contract', $id ), true ) );
}

function hpv_doc_rest_update_contract( WP_REST_Request $request ) {
	$c = hpv_p_get( 'contract', (int) $request['id'] );
	if ( ! $c ) {
		return hpv_doc_not_found();
	}
	if ( 'signed' === $c['status'] || 'void' === $c['status'] ) {
		return new WP_Error( 'locked', 'Aláírt vagy visszavont szerződés nem módosítható.', array( 'status' => 409 ) );
	}
	$data = array();
	if ( null !== $request->get_param( 'title' ) ) {
		$data['title'] = mb_substr( trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) ), 0, 200 ) ?: $c['title'];
	}
	if ( null !== $request->get_param( 'body' ) ) {
		$data['body'] = hpv_doc_clean_html( (string) $request->get_param( 'body' ) );
	}
	if ( in_array( $request->get_param( 'language' ), array( 'en', 'hu' ), true ) ) {
		$data['language'] = $request->get_param( 'language' );
	}
	if ( $data ) {
		hpv_p_update( 'contract', (int) $c['id'], $data );
	}

	return rest_ensure_response( hpv_doc_format_contract( hpv_p_get( 'contract', (int) $c['id'] ), true ) );
}

function hpv_doc_rest_delete_contract( WP_REST_Request $request ) {
	$c = hpv_p_get( 'contract', (int) $request['id'] );
	if ( ! $c ) {
		return hpv_doc_not_found();
	}
	// Kiküldött vagy aláírt szerződés nyoma megmarad: visszavonás.
	if ( 'draft' !== $c['status'] ) {
		hpv_p_update( 'contract', (int) $c['id'], array( 'status' => 'void' ) );
		return rest_ensure_response( hpv_doc_format_contract( hpv_p_get( 'contract', (int) $c['id'] ) ) );
	}
	hpv_p_delete( 'contract', (int) $c['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

function hpv_doc_rest_revise_contract( WP_REST_Request $request ) {
	$c = hpv_p_get( 'contract', (int) $request['id'] );
	if ( ! $c ) {
		return hpv_doc_not_found();
	}
	if ( 'draft' !== $c['status'] && 'sent' !== $c['status'] ) {
		return new WP_Error( 'locked', 'Aláírt vagy visszavont szerződés nem módosítható.', array( 'status' => 409 ) );
	}
	$instructions = trim( sanitize_textarea_field( (string) $request->get_param( 'instructions' ) ) );
	if ( '' === $instructions ) {
		return new WP_Error( 'instructions', 'Írd le, mit változtasson az AI.', array( 'status' => 400 ) );
	}
	$body = hpv_doc_ai_revise_contract( $c, $instructions );
	if ( is_wp_error( $body ) ) {
		return new WP_Error( $body->get_error_code(), $body->get_error_message(), array( 'status' => 502 ) );
	}
	hpv_p_update( 'contract', (int) $c['id'], array( 'body' => $body ) );

	return rest_ensure_response( hpv_doc_format_contract( hpv_p_get( 'contract', (int) $c['id'] ), true ) );
}

/**
 * Kiküldés aláírásra (portál + e-mail). Kitöltetlen [[TODO]] résszel nem megy ki.
 */
function hpv_doc_rest_send_contract( WP_REST_Request $request ) {
	$c = hpv_p_get( 'contract', (int) $request['id'] );
	if ( ! $c ) {
		return hpv_doc_not_found();
	}
	if ( ! in_array( $c['status'], array( 'draft', 'sent' ), true ) ) {
		return new WP_Error( 'status', 'Ez a szerződés már nem küldhető ki.', array( 'status' => 409 ) );
	}
	$todos = hpv_doc_todos( (string) $c['body'] );
	if ( $todos ) {
		return new WP_Error( 'todos', 'Még van kitöltetlen rész: ' . implode( '; ', array_slice( $todos, 0, 5 ) ), array( 'status' => 409 ) );
	}
	if ( '' === trim( wp_strip_all_tags( (string) $c['body'] ) ) ) {
		return new WP_Error( 'empty', 'A szerződés üres.', array( 'status' => 400 ) );
	}
	if ( ! hpv_p_client_users( (int) $c['client_id'] ) ) {
		return new WP_Error( 'no_user', 'Az ügyfélnek még nincs portál-hozzáférése: előbb hívd meg a portálra (ügyfél adatlap).', array( 'status' => 409 ) );
	}
	hpv_p_update( 'contract', (int) $c['id'], array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ) );
	$c = hpv_p_get( 'contract', (int) $c['id'] );
	hpv_p_event_contract_sent( $c );
	hpv_p_log_client( (int) $c['client_id'], '"%s" is ready for your signature.', array( $c['title'] ), get_current_user_id() );

	return rest_ensure_response( hpv_doc_format_contract( $c, true ) );
}
