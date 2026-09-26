<?php
/**
 * Híd a marketing weboldal bővítményeihez (külön WordPress telepítés):
 *
 * 1. Weboldal → CRM: a Website Grader és a kapcsolati űrlapok (mu-plugins/helloprovision-leads.php) kitöltője ügyfélként
 *    („Érdeklődő”) kerül a CRM-be; a pontszám, az üzenet és a válaszok belső jegyzetbe kerülnek, a csapat e-mailt kap.
 *    Ugyanarra az e-mail címre nem lesz dupla ügyfél.
 * 2. CRM → Reviews: a kész projekt után (alap: 3 nap múlva) a Reviews bővítmény értékelést kér az ügyféltől.
 *    Csak a beállított országok ügyfeleinek (alap: USA — a Google-profil a floridai cégé), ügyfelenként 90 naponta egyszer.
 *
 * Mindkét oldal wp-config.php-jába ugyanaz a titok: define( 'HPV_BRIDGE_SECRET', '…' ); (legalább 16 karakter)
 * A CRM-be még: define( 'HPV_SITE_URL', 'https://helloprovision.com' ); — ha a Reviews ugyanitt fut, nem kell.
 * A kérések aláírása: X-HPV-Timestamp + X-HPV-Signature = HMAC-SHA256( "timestamp.body", titok ), 5 perc tűréssel.
 */

defined( 'ABSPATH' ) || exit;

function hpv_bridge_secret(): string {
	return defined( 'HPV_BRIDGE_SECRET' ) && strlen( (string) HPV_BRIDGE_SECRET ) >= 16 ? (string) HPV_BRIDGE_SECRET : '';
}

function hpv_bridge_verify( WP_REST_Request $r ): bool {
	$secret = hpv_bridge_secret();
	$ts     = (int) $r->get_header( 'x-hpv-timestamp' );
	$sig    = (string) $r->get_header( 'x-hpv-signature' );
	if ( '' === $secret || '' === $sig || abs( time() - $ts ) > 300 ) {
		return false;
	}

	return hash_equals( hash_hmac( 'sha256', $ts . '.' . $r->get_body(), $secret ), $sig );
}

/* ─── 1. Érdeklődő a weboldalról (Grader, kapcsolati űrlap) ── */

const HPV_LEAD_SOURCES = array(
	'grader'   => 'Website Grader',
	'contact'  => 'Kapcsolati űrlap',
	'seo_os'   => 'SEO OS',
	'manual'   => 'Kézi felvitel',
	'bitrix'   => 'Bitrix24',
	'proposal' => 'Ajánlat ablak',
	'mail'     => 'E-mail',
);

function hpv_lead_source_label( string $source ): string {
	return HPV_LEAD_SOURCES[ $source ] ?? ucfirst( str_replace( '_', ' ', $source ) );
}

/**
 * Mezők: email (kötelező), name, business, website, phone, country (US|HU), source (grader|contact|…),
 * form (az űrlap neve), page (ahol kitöltötték), message, fields (további kérdés → válasz),
 * attribution (a weboldal sütijéből: { first, last } látogatás UTM-mel, kattintás-azonosítóval, hivatkozó oldallal),
 * a Graderből még: score, grade, issues, report_url.
 *
 * @return array|WP_Error { client_id, created }
 */
function hpv_leads_ingest( array $d ) {
	$email = strtolower( sanitize_email( (string) ( $d['email'] ?? '' ) ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'email', 'Érvénytelen e-mail cím.' );
	}
	$name     = sanitize_text_field( (string) ( $d['name'] ?? '' ) );
	$business = sanitize_text_field( (string) ( $d['business'] ?? '' ) );
	$website  = esc_url_raw( (string) ( $d['website'] ?? '' ) );
	$phone    = sanitize_text_field( (string) ( $d['phone'] ?? '' ) );
	$country  = 'HU' === strtoupper( (string) ( $d['country'] ?? '' ) ) ? 'HU' : 'US';
	$score    = isset( $d['score'] ) && '' !== $d['score'] ? (int) $d['score'] : null;
	$issues   = array_slice( array_map( 'sanitize_text_field', (array) ( $d['issues'] ?? array() ) ), 0, 10 );
	$source   = sanitize_key( (string) ( $d['source'] ?? 'web' ) ) ?: 'web';
	$form     = sanitize_text_field( (string) ( $d['form'] ?? '' ) );
	$page     = esc_url_raw( (string) ( $d['page'] ?? '' ) );
	$message  = trim( mb_substr( sanitize_textarea_field( (string) ( $d['message'] ?? '' ) ), 0, 5000 ) );
	$fields   = array();
	foreach ( array_slice( (array) ( $d['fields'] ?? array() ), 0, 20, true ) as $k => $v ) {
		$v = trim( mb_substr( sanitize_textarea_field( is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v ), 0, 1000 ) );
		if ( '' !== $v ) {
			$fields[ mb_substr( sanitize_text_field( (string) $k ), 0, 80 ) ] = $v;
		}
	}
	$label = hpv_lead_source_label( $source ) . ( $form ? ' (' . $form . ')' : '' );
	$attr  = function_exists( 'hpv_sales_clean_attribution' ) ? hpv_sales_clean_attribution( $d['attribution'] ?? array() ) : array();
	$track = function_exists( 'hpv_sales_source_fields' ) ? hpv_sales_source_fields( $source, $attr ) : array();

	$existing = hpv_p_find( 'client', array( 'email' => $email ), array( 'limit' => 1 ) )[0] ?? null;
	if ( ! $existing && function_exists( 'hpv_bx_find_client' ) ) {
		$id       = hpv_bx_find_client( $business, $email );
		$existing = $id ? hpv_p_get( 'client', $id ) : null;
	}
	$created = false;
	if ( $existing ) {
		$client_id = (int) $existing['id'];
		$fill      = array_filter(
			array(
				'website'      => $existing['website'] ? '' : $website,
				'contact_name' => $existing['contact_name'] ? '' : $name,
				'phone'        => $existing['phone'] ? '' : $phone,
			)
		);
		if ( $fill ) {
			hpv_p_update( 'client', $client_id, $fill );
		}
		// Értékesítés: a korábbi vagy elveszett érdeklődő új esély; a nyitott érdeklődő forrásadatai kiegészülnek.
		if ( $track && 'former' === $existing['status'] ) {
			hpv_p_update( 'client', $client_id, array( 'status' => 'lead' ) );
			hpv_p_update( 'client', $client_id, $track );
		} elseif ( $track && 'lead' === $existing['status'] && 'lost' === $existing['lead_stage'] ) {
			hpv_sales_reopen( $client_id, $track );
		} elseif ( $track && 'lead' === $existing['status'] && '' === (string) $existing['lead_attribution'] && $attr ) {
			hpv_p_update( 'client', $client_id, array_diff_key( $track, array( 'lead_source' => 0 ) ) );
		}
	} else {
		$client_id = hpv_p_insert(
			'client',
			array(
				'name'         => $business ?: ( $name ?: $email ),
				'contact_name' => $name,
				'email'        => $email,
				'phone'        => $phone,
				'website'      => $website,
				'country'      => $country,
				'status'       => 'lead',
				'notes'        => 'Forrás: ' . $label,
			) + $track
		);
		if ( ! $client_id ) {
			return new WP_Error( 'db', 'Az adatbázisba írás nem sikerült.' );
		}
		$created = true;
	}

	$lines = array( $label . ( $page ? ': ' . $page : ( $website ? ': ' . $website : '' ) ) );
	if ( null !== $score ) {
		$lines[] = sprintf( 'Pontszám: %d/100 (%s)', $score, sanitize_text_field( (string) ( $d['grade'] ?? '' ) ) );
	}
	if ( $issues ) {
		$lines[] = "Súlyos hibák:\n- " . implode( "\n- ", $issues );
	}
	if ( $phone ) {
		$lines[] = 'Telefon: ' . $phone;
	}
	if ( $website && $page ) {
		$lines[] = 'Weboldal: ' . $website;
	}
	foreach ( $fields as $k => $v ) {
		$lines[] = $k . ': ' . $v;
	}
	if ( '' !== $message ) {
		$lines[] = "Üzenet:\n" . $message;
	}
	if ( ! empty( $d['report_url'] ) ) {
		$lines[] = 'Riport: ' . esc_url_raw( (string) $d['report_url'] );
	}
	if ( ! empty( $attr['first'] ) ) {
		$f       = $attr['first'];
		$lines[] = 'Honnan: ' . ( HPV_SALES_CHANNELS[ hpv_sales_channel( $f ) ] ?? '' ) . ( ! empty( $f['utm_campaign'] ) ? ', kampány: ' . $f['utm_campaign'] : '' ) . ( ! empty( $f['ref'] ) ? ', ' . $f['ref'] : '' );
	}
	$note = implode( "\n", $lines );
	hpv_p_log( $client_id, 'note', $note, false );

	$who = $business ?: ( $name ?: $email );
	hpv_p_notify_staff(
		sprintf( '%s érdeklődő (%s): %s%s', $created ? 'Új' : 'Visszatérő', hpv_lead_source_label( $source ), $who, null !== $score ? ' (' . $score . '/100)' : '' ),
		'<p><strong>' . esc_html( $name ?: $email ) . '</strong> (' . esc_html( $email ) . ')' . ( $business ? ', ' . esc_html( $business ) : '' ) . '</p><p>' . nl2br( esc_html( $note ) ) . '</p><p>Erre a levélre válaszolva közvetlenül neki írsz. Ajánlat: CRM app → Ajánlatok → Új ajánlat, ügyfélnek ezt az érdeklődőt választva.</p>',
		hpv_p_crm_app_url( '/clients/' . $client_id ),
		$email
	);

	return array( 'client_id' => $client_id, 'created' => $created );
}

add_action( 'rest_api_init', 'hpv_bridge_routes' );

function hpv_bridge_routes() {
	register_rest_route(
		'hpv/v1',
		'/bridge/lead',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'hpv_bridge_verify',
			'callback'            => function ( WP_REST_Request $r ) {
				$res = hpv_leads_ingest( (array) $r->get_json_params() );
				return is_wp_error( $res ) ? new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( $res );
			},
		)
	);
	register_rest_route(
		'hpv/v1',
		'/clients/(?P<id>\d+)/review-request',
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => hpv_p_is_staff(),
			'callback'            => function ( WP_REST_Request $r ) {
				$res = hpv_review_request( (int) $r['id'], sanitize_text_field( (string) $r['project'] ), true );
				return is_wp_error( $res ) ? new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( array( 'status' => $res ) );
			},
		)
	);
}

/* ─── 2. Értékeléskérés a kész projekt után ───────────────── */

/**
 * A címzett: az első portál-felhasználó, különben az ügyfél kapcsolattartója.
 */
function hpv_review_recipient( array $client ): array {
	$users = hpv_p_client_users( (int) $client['id'] );
	if ( $users ) {
		return array( 'name' => $users[0]->display_name, 'email' => $users[0]->user_email );
	}

	return array( 'name' => $client['contact_name'] ?: $client['name'], 'email' => $client['email'] );
}

/**
 * @return string|WP_Error A Reviews válasza: sent | duplicate | no_url | invalid | mail_failed | error
 */
function hpv_review_request( int $client_id, string $project, bool $manual = false ) {
	$client = hpv_p_get( 'client', $client_id );
	if ( ! $client ) {
		return new WP_Error( 'client', 'Nincs ilyen ügyfél.' );
	}
	$to = hpv_review_recipient( $client );
	if ( ! is_email( $to['email'] ) ) {
		return new WP_Error( 'email', 'Az ügyfélnek nincs e-mail címe.' );
	}
	if ( function_exists( 'hpv_reviews_create_request' ) ) {
		$status = hpv_reviews_create_request( $to['name'], $to['email'], $project );
	} else {
		$site = defined( 'HPV_SITE_URL' ) ? untrailingslashit( (string) HPV_SITE_URL ) : '';
		if ( ! $site || ! hpv_bridge_secret() ) {
			return new WP_Error( 'bridge_off', 'Az értékeléskéréshez kell a HPV_SITE_URL és a HPV_BRIDGE_SECRET (wp-config.php).' );
		}
		$body = wp_json_encode( array( 'name' => $to['name'], 'email' => $to['email'], 'project' => $project ) );
		$ts   = time();
		$res  = wp_remote_post(
			$site . '/wp-json/hpv-reviews/v1/request',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-HPV-Timestamp' => (string) $ts,
					'X-HPV-Signature' => hash_hmac( 'sha256', $ts . '.' . $body, hpv_bridge_secret() ),
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'bridge', 'A Reviews nem érhető el: ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
		}
		$status = (string) ( json_decode( (string) wp_remote_retrieve_body( $res ), true )['status'] ?? 'error' );
	}
	$labels = array( 'sent' => 'elküldve', 'duplicate' => '90 napon belül már kapott kérést', 'no_url' => 'a Reviews-ban nincs beállítva a Google értékelő link', 'invalid' => 'érvénytelen név vagy e-mail', 'mail_failed' => 'a levél nem ment ki', 'error' => 'hiba' );
	hpv_p_log( $client_id, 'system', sprintf( 'Google értékeléskérés (%s): %s — %s.', $manual ? 'kézi' : 'automatikus', $to['email'], $labels[ $status ] ?? $status ), false );

	return $status;
}

add_action( 'hpv_retainer_cron', 'hpv_review_auto' );

/**
 * Napi futás: a kész projektek után a beállított nap elteltével értékeléskérés.
 */
function hpv_review_auto(): array {
	$s = hpv_p_settings();
	if ( empty( $s['review_request'] ) ) {
		return array();
	}
	$delay     = max( 0, (int) ( $s['review_delay_days'] ?? 3 ) );
	$countries = array_filter( array_map( 'trim', explode( ',', strtoupper( (string) ( $s['review_countries'] ?? 'US' ) ) ) ) );
	$done      = array();
	foreach ( hpv_p_find( 'project', array( 'status' => 'completed', 'is_template' => 0 ), array( 'limit' => 5000 ) ) as $p ) {
		if ( ! (int) $p['client_id'] || $p['review_requested_at'] ) {
			continue;
		}
		// A lezárás időpontja: amikor a napi futás először látja késznek.
		if ( ! $p['completed_at'] ) {
			hpv_p_update( 'project', (int) $p['id'], array( 'completed_at' => current_time( 'mysql', true ) ) );
			continue;
		}
		if ( strtotime( $p['completed_at'] . ' UTC' ) > time() - $delay * DAY_IN_SECONDS ) {
			continue;
		}
		$client = hpv_p_get( 'client', (int) $p['client_id'] );
		hpv_p_update( 'project', (int) $p['id'], array( 'review_requested_at' => current_time( 'mysql', true ) ) );
		if ( ! $client || ! in_array( $client['country'] ?: 'US', $countries, true ) || ! (int) $p['visible'] ) {
			continue; // más ország vagy belső projekt: nem kérünk
		}
		$res                       = hpv_review_request( (int) $client['id'], $p['name'] );
		$done[ (int) $p['id'] ] = is_wp_error( $res ) ? $res->get_error_message() : $res;
	}

	return $done;
}
