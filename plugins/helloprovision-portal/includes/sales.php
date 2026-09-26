<?php
/**
 * Értékesítés: az érdeklődők tölcsére és a forrásmérés.
 *
 * Minden „Érdeklődő” státuszú ügyfél a tölcsérbe kerül, bárhonnan jön (weboldal űrlap, Grader, Bitrix24 import, kézi
 * felvitel, ajánlat ablak): Új → Felvettük a kapcsolatot → Igényfelmérés → Ajánlat kint → Megnyert / Elveszett.
 * - az ajánlat kiküldése „Ajánlat kint”, az elfogadása (vagy az ügyfél aktívvá tétele) „Megnyert”;
 * - az első válasz ideje: amikor az érdeklődő kikerül az „Új” szakaszból;
 * - a weboldal (mu-plugins/helloprovision-leads.php) megjegyzi, honnan jött a látogató (UTM, Google/Meta kattintás-
 *   azonosító, hivatkozó oldal), ebből csatorna lesz; a források kimutatása érdeklődőt, nyerési arányt, válaszidőt és
 *   bevételt mutat csatornánként, kampányonként, űrlaponként;
 * - óránként: figyelmeztetés, ha egy új érdeklődő a beállított óraszámnál tovább vár válaszra; naponta: a felelősök
 *   összefoglalót kapnak az esedékes következő lépésekről.
 */

defined( 'ABSPATH' ) || exit;

const HPV_SALES_STAGES = array(
	'new'       => 'Új',
	'contacted' => 'Felvettük a kapcsolatot',
	'qualified' => 'Igényfelmérés',
	'proposal'  => 'Ajánlat kint',
	'won'       => 'Megnyert',
	'lost'      => 'Elveszett',
);
const HPV_SALES_OPEN     = array( 'new', 'contacted', 'qualified', 'proposal' );
const HPV_SALES_CHANNELS = array(
	'google_ads' => 'Google Ads',
	'meta_ads'   => 'Meta hirdetés',
	'paid_other' => 'Egyéb hirdetés',
	'organic'    => 'Organikus kereső',
	'gbp'        => 'Google Cégprofil',
	'social'     => 'Közösségi média',
	'email'      => 'E-mail',
	'referral'   => 'Hivatkozó oldal',
	'campaign'   => 'Egyéb kampány',
	'direct'     => 'Közvetlen',
	'offline'    => 'Személyes / ajánlás',
	'import'     => 'Bitrix24 import',
	'unknown'    => 'Ismeretlen',
);
const HPV_SALES_LOST_REASONS = array( 'Túl drága', 'Mást választott', 'Nem reagál', 'Nem nekünk való munka', 'Most nem aktuális', 'Egyéb' );
const HPV_SALES_TOUCH_KEYS   = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ref', 'land', 'at' );

/* ─── Forrás → csatorna ───────────────────────────────────── */

function hpv_sales_clean_touch( $t ): array {
	$out = array();
	foreach ( HPV_SALES_TOUCH_KEYS as $k ) {
		if ( ! is_array( $t ) || ! isset( $t[ $k ] ) || ! is_scalar( $t[ $k ] ) || '' === (string) $t[ $k ] ) {
			continue;
		}
		$out[ $k ] = 'at' === $k ? (int) $t[ $k ] : mb_substr( sanitize_text_field( (string) $t[ $k ] ), 0, 200 );
	}

	return $out;
}

/**
 * @param mixed $a { first: touch, last: touch } — vagy JSON szöveg
 */
function hpv_sales_clean_attribution( $a ): array {
	if ( is_string( $a ) ) {
		$a = json_decode( $a, true );
	}
	if ( ! is_array( $a ) ) {
		return array();
	}

	return array_filter( array( 'first' => hpv_sales_clean_touch( $a['first'] ?? null ), 'last' => hpv_sales_clean_touch( $a['last'] ?? null ) ) );
}

/**
 * Egy látogatás csatornája a Google Analytics alapértelmezett csoportosításához hasonlóan.
 */
function hpv_sales_channel( array $t ): string {
	if ( ! array_diff_key( $t, array( 'at' => 0, 'land' => 0 ) ) ) {
		return 'direct';
	}
	if ( ! empty( $t['gclid'] ) || ! empty( $t['gbraid'] ) || ! empty( $t['wbraid'] ) ) {
		return 'google_ads';
	}
	$src  = strtolower( (string) ( $t['utm_source'] ?? '' ) );
	$med  = strtolower( (string) ( $t['utm_medium'] ?? '' ) );
	$cmp  = strtolower( (string) ( $t['utm_campaign'] ?? '' ) );
	$host = strtolower( (string) wp_parse_url( (string) ( $t['ref'] ?? '' ), PHP_URL_HOST ) );
	$meta = '/^(facebook|fb|instagram|ig|meta|messenger)$|facebook\.com|instagram\.com/';
	if ( preg_match( '/\b(gbp|gmb)\b|google[ _-]?(business|my[ _-]?business|maps)/', $src . ' ' . $med . ' ' . $cmp ) ) {
		return 'gbp';
	}
	if ( preg_match( '/^(cpc|ppc|cpm|cpv|paid|paid[ _-]?(search|social|media)|display|ads?|retargeting|remarketing)$/', $med ) ) {
		if ( preg_match( $meta, $src ) ) {
			return 'meta_ads';
		}
		return 'google' === $src || 'adwords' === $src ? 'google_ads' : 'paid_other';
	}
	if ( ! empty( $t['msclkid'] ) ) {
		return 'paid_other';
	}
	if ( preg_match( '/^(e-?mail|newsletter|hirlevel|hírlevél)$/u', $med ) ) {
		return 'email';
	}
	$social = '/facebook|instagram|linkedin|lnkd\.in|^t\.co$|twitter|(^|\.)x\.com$|youtube|tiktok|pinterest|reddit|threads\.net/';
	if ( preg_match( '/^(social|social[ _-]?media|sm)$/', $med ) || ( $src && ( preg_match( $social, $src ) || preg_match( $meta, $src ) ) ) ) {
		return 'social';
	}
	$search = '/(^|\.)(google|bing|duckduckgo|yahoo|ecosia|yandex|baidu|brave|startpage)\./';
	if ( 'organic' === $med ) {
		return 'organic';
	}
	if ( $src ) {
		return preg_match( '/^(google|bing|duckduckgo|yahoo)$/', $src ) ? 'organic' : 'campaign';
	}
	if ( ! empty( $t['fbclid'] ) ) {
		return 'social';
	}
	if ( $host ) {
		if ( preg_match( $search, $host ) ) {
			return 'organic';
		}
		return preg_match( $social, $host ) ? 'social' : 'referral';
	}

	return 'direct';
}

/**
 * Az érdeklődő csatornája: az első látogatásé (az hozta), ha van forrásadat; különben az űrlap/bejárat szerint.
 */
function hpv_sales_lead_channel( array $attribution, string $source ): string {
	if ( ! empty( $attribution['first'] ) ) {
		return hpv_sales_channel( $attribution['first'] );
	}
	if ( 'bitrix' === $source ) {
		return 'import';
	}

	return in_array( $source, array( 'manual', 'proposal', '' ), true ) ? 'offline' : 'unknown';
}

function hpv_sales_lead_campaign( array $attribution ): string {
	return (string) ( $attribution['first']['utm_campaign'] ?? $attribution['last']['utm_campaign'] ?? '' );
}

/**
 * Az ügyfélbe írandó forrásmezők (új érdeklődőnél vagy újranyitásnál).
 */
function hpv_sales_source_fields( string $source, array $attribution ): array {
	return array(
		'lead_source'      => $source ?: 'manual',
		'lead_attribution' => $attribution ? wp_json_encode( $attribution ) : '',
		'lead_channel'     => hpv_sales_lead_channel( $attribution, $source ),
		'lead_campaign'    => mb_substr( hpv_sales_lead_campaign( $attribution ), 0, 200 ),
	);
}

/* ─── Automatikus szakaszok ───────────────────────────────── */

add_action( 'hpv_p_inserted_client', 'hpv_sales_on_insert', 10, 2 );

/**
 * Új érdeklődő: a tölcsér elejére, alapértelmezett felelőssel.
 */
function hpv_sales_on_insert( int $id, array $data ) {
	if ( 'lead' !== ( $data['status'] ?? '' ) ) {
		return;
	}
	$now    = current_time( 'mysql', true );
	$source = (string) ( $data['lead_source'] ?? '' ) ?: 'manual';
	$fill   = array(
		'lead_stage'  => in_array( $data['lead_stage'] ?? '', array_keys( HPV_SALES_STAGES ), true ) ? $data['lead_stage'] : 'new',
		'lead_at'     => $data['lead_at'] ?? $now,
		'lead_source' => $source,
	);
	if ( empty( $data['lead_channel'] ) ) {
		$fill['lead_channel'] = hpv_sales_lead_channel( hpv_sales_clean_attribution( $data['lead_attribution'] ?? '' ), $source );
	}
	if ( empty( $data['lead_owner'] ) ) {
		$fill['lead_owner'] = hpv_sales_default_owner();
	}
	// Importált, régi érdeklődőkről nem kell válaszidő-figyelmeztetés.
	if ( 'bitrix' === $source ) {
		$fill['sla_reminded_at'] = $now;
	}
	hpv_p_update( 'client', $id, $fill );
}

add_action( 'hpv_p_updated_client', 'hpv_sales_on_update', 10, 3 );

/**
 * Státuszváltás: érdeklődő → aktív = megnyert; korábbi/aktív → érdeklődő = újra a tölcsérben.
 */
function hpv_sales_on_update( int $id, array $data, array $old ) {
	if ( ! isset( $data['status'] ) || $data['status'] === $old['status'] ) {
		return;
	}
	$now = current_time( 'mysql', true );
	if ( 'lead' === $old['status'] && 'active' === $data['status'] && 'won' !== ( $data['lead_stage'] ?? $old['lead_stage'] ) ) {
		hpv_p_update( 'client', $id, array( 'lead_stage' => 'won', 'won_at' => $now, 'first_contact_at' => $old['first_contact_at'] ?: $now ) );
		hpv_p_log( $id, 'system', 'Értékesítés: megnyert (' . ( HPV_SALES_STAGES[ $old['lead_stage'] ] ?? '—' ) . ' → Megnyert).', false );
	} elseif ( 'lead' === $data['status'] && ! in_array( $data['lead_stage'] ?? '', HPV_SALES_OPEN, true ) ) {
		hpv_sales_reopen( $id, array() );
	}
}

/**
 * Egy elveszett (vagy korábbi) érdeklődő újra jelentkezik: új esély, a tölcsér elején.
 */
function hpv_sales_reopen( int $id, array $source_fields ): void {
	$c = hpv_p_get( 'client', $id );
	if ( ! $c ) {
		return;
	}
	hpv_p_update(
		'client',
		$id,
		array_merge(
			array(
				'lead_stage'       => 'new',
				'lead_at'          => current_time( 'mysql', true ),
				'first_contact_at' => null,
				'lost_at'          => null,
				'lost_reason'      => '',
				'sla_reminded_at'  => null,
				'next_step'        => '',
				'next_step_date'   => null,
				'lead_owner'       => (int) $c['lead_owner'] ?: hpv_sales_default_owner(),
				'lead_source'      => $c['lead_source'] ?: 'manual',
			),
			array_filter( $source_fields )
		)
	);
	hpv_p_log( $id, 'system', 'Értékesítés: újra érdeklődik, a tölcsér elejére került.', false );
}

add_action( 'hpv_proposal_sent', 'hpv_sales_on_proposal_sent', 10, 2 );

/**
 * Kiküldött ajánlat: „Ajánlat kint”, és ha nincs becsült érték, az ajánlat első évi értéke.
 */
function hpv_sales_on_proposal_sent( array $p, int $user_id ) {
	$c = hpv_p_get( 'client', (int) $p['client_id'] );
	if ( ! $c || 'lead' !== $c['status'] ) {
		return;
	}
	if ( in_array( $c['lead_stage'], array( '', 'new', 'contacted', 'qualified' ), true ) ) {
		hpv_sales_set_stage( (int) $c['id'], 'proposal', '', $user_id );
	}
	if ( ! hpv_p_to_cents( $c['lead_value'] ) && function_exists( 'hpv_prop_totals' ) ) {
		$t     = hpv_prop_totals( hpv_prop_clean_pricing( $p['pricing'] ) );
		$cents = $t['one_time'] + 12 * $t['monthly'] + $t['yearly'];
		if ( $cents > 0 ) {
			hpv_p_update( 'client', (int) $c['id'], array( 'lead_value' => hpv_p_cents_to_decimal( $cents ) ) );
		}
	}
}

/**
 * @return array|WP_Error a frissített kártya
 */
function hpv_sales_set_stage( int $id, string $stage, string $reason = '', int $user_id = 0 ) {
	$c = hpv_p_get( 'client', $id );
	if ( ! $c ) {
		return new WP_Error( 'not_found', 'Nincs ilyen ügyfél.' );
	}
	if ( ! isset( HPV_SALES_STAGES[ $stage ] ) ) {
		return new WP_Error( 'stage', 'Ismeretlen szakasz.' );
	}
	$from = $c['lead_stage'] ?: 'new';
	if ( $stage === $from ) {
		return hpv_sales_card( $c );
	}
	if ( 'won' === $from ) {
		return new WP_Error( 'won', 'A megnyert ügyfél már nem tehető vissza a tölcsérbe.' );
	}
	if ( 'lead' !== $c['status'] ) {
		return new WP_Error( 'status', 'Csak érdeklődőt lehet a tölcsérben mozgatni.' );
	}
	$reason = mb_substr( sanitize_text_field( $reason ), 0, 250 );
	if ( 'lost' === $stage && '' === $reason ) {
		return new WP_Error( 'reason', 'Írd be, miért veszett el (ebből tanulunk).' );
	}
	$now  = current_time( 'mysql', true );
	$data = array( 'lead_stage' => $stage );
	if ( 'new' === $from && ! $c['first_contact_at'] ) {
		$data['first_contact_at'] = $now;
	}
	if ( 'lost' === $stage ) {
		$data += array( 'lost_at' => $now, 'lost_reason' => $reason );
	} elseif ( 'lost' === $from ) {
		$data += array( 'lost_at' => null, 'lost_reason' => '' );
	}
	if ( 'won' === $stage ) {
		$data += array( 'won_at' => $now, 'status' => 'active' );
	}
	hpv_p_update( 'client', $id, $data );
	hpv_p_log( $id, 'system', sprintf( 'Értékesítés: %s → %s%s', HPV_SALES_STAGES[ $from ], HPV_SALES_STAGES[ $stage ], $reason ? ' (' . $reason . ')' : '' ), false, $user_id );

	return hpv_sales_card( hpv_p_get( 'client', $id ) );
}

/**
 * Felelős, érték, következő lépés, csatorna, kampány, szakasz egyszerre.
 *
 * @return array|WP_Error
 */
function hpv_sales_update( int $id, array $in, int $user_id ) {
	$c = hpv_p_get( 'client', $id );
	if ( ! $c ) {
		return new WP_Error( 'not_found', 'Nincs ilyen ügyfél.' );
	}
	$data = array();
	if ( array_key_exists( 'owner', $in ) ) {
		$owner = (int) $in['owner'];
		if ( $owner && ! hpv_p_is_staff( $owner ) ) {
			return new WP_Error( 'owner', 'A felelős csak munkatárs lehet.' );
		}
		$data['lead_owner'] = $owner;
	}
	if ( array_key_exists( 'value', $in ) ) {
		$data['lead_value'] = hpv_p_cents_to_decimal( max( 0, hpv_p_to_cents( $in['value'] ) ) );
	}
	if ( array_key_exists( 'next_step', $in ) ) {
		$data['next_step'] = mb_substr( sanitize_text_field( (string) $in['next_step'] ), 0, 250 );
	}
	if ( array_key_exists( 'next_step_date', $in ) ) {
		$d                      = (string) $in['next_step_date'];
		$data['next_step_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : null;
	}
	if ( array_key_exists( 'channel', $in ) ) {
		if ( ! isset( HPV_SALES_CHANNELS[ $in['channel'] ] ) ) {
			return new WP_Error( 'channel', 'Ismeretlen csatorna.' );
		}
		$data['lead_channel'] = $in['channel'];
	}
	if ( array_key_exists( 'campaign', $in ) ) {
		$data['lead_campaign'] = mb_substr( sanitize_text_field( (string) $in['campaign'] ), 0, 200 );
	}
	if ( $data ) {
		hpv_p_update( 'client', $id, $data );
		if ( isset( $data['lead_owner'] ) && (int) $data['lead_owner'] !== (int) $c['lead_owner'] && $data['lead_owner'] && $data['lead_owner'] !== $user_id ) {
			$u = get_userdata( (int) $data['lead_owner'] );
			$u && hpv_p_send( $u->user_email, 'Rád bízott érdeklődő: ' . $c['name'], hpv_p_email_html( 'Rád bízott érdeklődő', '<p><strong>' . esc_html( $c['name'] ) . '</strong>' . ( $c['email'] ? ' (' . esc_html( $c['email'] ) . ')' : '' ) . '</p>', 'Megnyitás a CRM-ben', hpv_p_crm_app_url( '/clients/' . $id ) ) );
		}
	}
	if ( ! empty( $in['stage'] ) ) {
		return hpv_sales_set_stage( $id, (string) $in['stage'], (string) ( $in['lost_reason'] ?? '' ), $user_id );
	}

	return hpv_sales_card( hpv_p_get( 'client', $id ) );
}

/* ─── Kártya, tölcsér, kimutatás ──────────────────────────── */

function hpv_sales_default_owner(): int {
	$id = (int) ( hpv_p_settings()['lead_owner'] ?? 0 );

	return $id && hpv_p_is_staff( $id ) ? $id : 0;
}

function hpv_sales_ts( $mysql ): int {
	return $mysql ? (int) strtotime( $mysql . ' UTC' ) : 0;
}

function hpv_sales_sla_hours(): int {
	return max( 0, (int) ( hpv_p_settings()['lead_sla_hours'] ?? 2 ) );
}

function hpv_sales_card( array $c ): array {
	$stage    = $c['lead_stage'] ?: ( 'lead' === $c['status'] ? 'new' : '' );
	$currency = 'HU' === $c['country'] ? 'HUF' : 'USD';
	$lead_at  = hpv_sales_ts( $c['lead_at'] ) ?: hpv_sales_ts( $c['created_at'] );
	$first    = hpv_sales_ts( $c['first_contact_at'] );
	$owner    = (int) $c['lead_owner'];
	$value    = hpv_p_to_cents( $c['lead_value'] );
	$today    = current_time( 'Y-m-d' );
	$open     = in_array( $stage, HPV_SALES_OPEN, true );
	$sla      = hpv_sales_sla_hours();
	$prop     = function_exists( 'hpv_prop_totals' ) ? ( hpv_p_find( 'proposal', array( 'client_id' => (int) $c['id'] ), array( 'orderby' => 'id', 'order' => 'DESC', 'limit' => 1 ) )[0] ?? null ) : null;

	return array(
		'id'             => (int) $c['id'],
		'name'           => $c['name'],
		'contact_name'   => (string) $c['contact_name'],
		'email'          => (string) $c['email'],
		'phone'          => (string) $c['phone'],
		'country'        => 'HU' === $c['country'] ? 'HU' : 'US',
		'status'         => $c['status'],
		'stage'          => $stage,
		'stage_label'    => HPV_SALES_STAGES[ $stage ] ?? '—',
		'channel'        => $c['lead_channel'] ?: 'unknown',
		'channel_label'  => HPV_SALES_CHANNELS[ $c['lead_channel'] ?: 'unknown' ] ?? $c['lead_channel'],
		'source'         => (string) $c['lead_source'],
		'source_label'   => hpv_lead_source_label( (string) $c['lead_source'] ?: 'manual' ),
		'campaign'       => (string) $c['lead_campaign'],
		'lead_at'        => $lead_at,
		'first_contact'  => $first,
		'response_hours' => $first && $lead_at ? round( max( 0, $first - $lead_at ) / HOUR_IN_SECONDS, 1 ) : null,
		'owner'          => $owner,
		'owner_name'     => $owner ? hpv_chat_user_label( $owner )['name'] : '',
		'value'          => $value / 100,
		'value_label'    => $value ? hpv_p_money( $value, $currency ) : '',
		'currency'       => $currency,
		'next_step'      => (string) $c['next_step'],
		'next_step_date' => (string) $c['next_step_date'],
		'overdue'        => $open && $c['next_step_date'] && $c['next_step_date'] < $today,
		'due_today'      => $open && $c['next_step_date'] === $today,
		'waiting_hours'  => 'new' === $stage && $lead_at ? round( ( time() - $lead_at ) / HOUR_IN_SECONDS, 1 ) : null,
		'sla_breach'     => 'new' === $stage && $sla && $lead_at && time() - $lead_at > $sla * HOUR_IN_SECONDS,
		'lost_reason'    => (string) $c['lost_reason'],
		'won_at'         => hpv_sales_ts( $c['won_at'] ),
		'lost_at'        => hpv_sales_ts( $c['lost_at'] ),
		'proposal'       => $prop ? array( 'id' => (int) $prop['id'], 'number' => $prop['number'], 'status' => $prop['status'] ) : null,
	);
}

/**
 * Az adatlapra: a kártya és a forrásadatok (első és utolsó látogatás).
 */
function hpv_sales_detail( array $c ): array {
	return array_merge( hpv_sales_card( $c ), array( 'attribution' => hpv_sales_clean_attribution( $c['lead_attribution'] ) ) );
}

function hpv_sales_board( array $filter ): array {
	$since = time() - 30 * DAY_IN_SECONDS;
	$cards = array();
	foreach ( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ) as $c ) {
		$stage = $c['lead_stage'] ?: ( 'lead' === $c['status'] ? 'new' : '' );
		$open  = 'lead' === $c['status'] && in_array( $stage, HPV_SALES_OPEN, true );
		$fresh = ( 'won' === $stage && hpv_sales_ts( $c['won_at'] ) >= $since ) || ( 'lost' === $stage && hpv_sales_ts( $c['lost_at'] ) >= $since );
		if ( ! $open && ! $fresh ) {
			continue;
		}
		if ( ! empty( $filter['owner'] ) && (int) $c['lead_owner'] !== (int) $filter['owner'] ) {
			continue;
		}
		if ( ! empty( $filter['channel'] ) && ( $c['lead_channel'] ?: 'unknown' ) !== $filter['channel'] ) {
			continue;
		}
		$cards[] = hpv_sales_card( $c );
	}
	usort( $cards, fn( $a, $b ) => $b['lead_at'] <=> $a['lead_at'] );

	return array(
		'stages'      => array_map( fn( $k, $v ) => array( 'key' => $k, 'label' => $v ), array_keys( HPV_SALES_STAGES ), HPV_SALES_STAGES ),
		'channels'    => array_map( fn( $k, $v ) => array( 'key' => $k, 'label' => $v ), array_keys( HPV_SALES_CHANNELS ), HPV_SALES_CHANNELS ),
		'lostReasons' => HPV_SALES_LOST_REASONS,
		'slaHours'    => hpv_sales_sla_hours(),
		'cards'       => $cards,
		'kpi'         => hpv_sales_kpi(),
	);
}

/**
 * Új érdeklődők (30 nap), átlagos első válasz (30 nap), nyerési arány (90 nap), nyitott tölcsér értéke.
 */
function hpv_sales_kpi(): array {
	$d30      = time() - 30 * DAY_IN_SECONDS;
	$d90      = time() - 90 * DAY_IN_SECONDS;
	$new      = 0;
	$resp     = array();
	$won      = 0;
	$lost     = 0;
	$pipeline = array();
	$waiting  = 0;
	$breach   = 0;
	$sla      = hpv_sales_sla_hours();
	foreach ( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ) as $c ) {
		$lead_at = hpv_sales_ts( $c['lead_at'] );
		if ( ! $lead_at ) {
			continue;
		}
		if ( $lead_at >= $d30 ) {
			$new++;
			if ( $c['first_contact_at'] ) {
				$resp[] = max( 0, hpv_sales_ts( $c['first_contact_at'] ) - $lead_at );
			}
		}
		$won  += 'won' === $c['lead_stage'] && hpv_sales_ts( $c['won_at'] ) >= $d90 ? 1 : 0;
		$lost += 'lost' === $c['lead_stage'] && hpv_sales_ts( $c['lost_at'] ) >= $d90 ? 1 : 0;
		if ( 'lead' === $c['status'] && in_array( $c['lead_stage'] ?: 'new', HPV_SALES_OPEN, true ) ) {
			$cur              = 'HU' === $c['country'] ? 'HUF' : 'USD';
			$pipeline[ $cur ] = ( $pipeline[ $cur ] ?? 0 ) + hpv_p_to_cents( $c['lead_value'] );
			if ( 'new' === ( $c['lead_stage'] ?: 'new' ) ) {
				$waiting++;
				$breach += $sla && time() - $lead_at > $sla * HOUR_IN_SECONDS ? 1 : 0;
			}
		}
	}

	return array(
		'new_30'         => $new,
		'response_hours' => $resp ? round( array_sum( $resp ) / count( $resp ) / HOUR_IN_SECONDS, 1 ) : null,
		'win_rate'       => $won + $lost ? (int) round( 100 * $won / ( $won + $lost ) ) : null,
		'won_90'         => $won,
		'lost_90'        => $lost,
		'pipeline'       => hpv_p_money_multi( $pipeline ),
		'waiting'        => $waiting,
		'sla_breach'     => $breach,
	);
}

/**
 * Források kimutatása: a megadott időszakban érkezett érdeklődők csoportonként (csatorna, kampány vagy űrlap).
 * A bevétel ezeknek az érdeklődőknek az eddigi összes befizetése (számlázási joggal látszik).
 */
function hpv_sales_sources( string $from, string $to, string $group, bool $money ): array {
	$rows   = array();
	$months = array();
	$total  = null;
	$blank  = fn( $key, $label ) => array(
		'key'       => $key,
		'label'     => $label,
		'leads'     => 0,
		'contacted' => 0,
		'proposals' => 0,
		'won'       => 0,
		'lost'      => 0,
		'open'      => 0,
		'resp'      => array(),
		'revenue'   => array(),
		'mrr'       => array(),
	);
	$total  = $blank( 'total', 'Összesen' );
	foreach ( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ) as $c ) {
		$day = $c['lead_at'] ? get_date_from_gmt( $c['lead_at'], 'Y-m-d' ) : '';
		if ( ! $day || $day < $from || $day > $to ) {
			continue;
		}
		switch ( $group ) {
			case 'campaign':
				$key   = $c['lead_campaign'] ?: '';
				$label = $key ?: '(kampány nélkül)';
				break;
			case 'source':
				$key   = $c['lead_source'] ?: 'manual';
				$label = hpv_lead_source_label( $key );
				break;
			default:
				$key   = $c['lead_channel'] ?: 'unknown';
				$label = HPV_SALES_CHANNELS[ $key ] ?? $key;
		}
		$rows[ $key ] = $rows[ $key ] ?? $blank( $key, $label );
		$stage        = $c['lead_stage'] ?: 'new';
		$sent         = function_exists( 'hpv_prop_totals' ) && array_filter( hpv_p_find( 'proposal', array( 'client_id' => (int) $c['id'] ), array( 'limit' => 50 ) ), fn( $p ) => ! empty( $p['sent_at'] ) );
		$month        = substr( $day, 0, 7 );
		$months[ $month ] = $months[ $month ] ?? array( 'month' => $month, 'leads' => 0, 'won' => 0 );
		$months[ $month ]['leads']++;
		$months[ $month ]['won'] += 'won' === $stage ? 1 : 0;
		foreach ( array( &$rows[ $key ], &$total ) as &$r ) {
			$r['leads']++;
			$r['contacted'] += $c['first_contact_at'] || 'new' !== $stage ? 1 : 0;
			$r['proposals'] += $sent || in_array( $stage, array( 'proposal', 'won' ), true ) ? 1 : 0;
			$r['won']       += 'won' === $stage ? 1 : 0;
			$r['lost']      += 'lost' === $stage ? 1 : 0;
			$r['open']      += in_array( $stage, HPV_SALES_OPEN, true ) ? 1 : 0;
			if ( $c['first_contact_at'] && $c['lead_at'] ) {
				$r['resp'][] = max( 0, hpv_sales_ts( $c['first_contact_at'] ) - hpv_sales_ts( $c['lead_at'] ) );
			}
		}
		unset( $r );
		if ( $money && 'won' === $stage ) {
			foreach ( hpv_sales_client_money( (int) $c['id'] ) as $kind => $by_cur ) {
				foreach ( $by_cur as $cur => $cents ) {
					$rows[ $key ][ $kind ][ $cur ] = ( $rows[ $key ][ $kind ][ $cur ] ?? 0 ) + $cents;
					$total[ $kind ][ $cur ]        = ( $total[ $kind ][ $cur ] ?? 0 ) + $cents;
				}
			}
		}
	}
	$finish = function ( array $r ) use ( $money ) {
		$closed           = $r['won'] + $r['lost'];
		$r['win_rate']    = $closed ? (int) round( 100 * $r['won'] / $closed ) : null;
		$r['response_hours'] = $r['resp'] ? round( array_sum( $r['resp'] ) / count( $r['resp'] ) / HOUR_IN_SECONDS, 1 ) : null;
		$r['revenue_label']  = $money ? ( $r['revenue'] ? hpv_p_money_multi( $r['revenue'] ) : '—' ) : null;
		$r['mrr_label']      = $money ? ( $r['mrr'] ? hpv_p_money_multi( $r['mrr'] ) : '—' ) : null;
		$r['revenue_usd']    = $money ? ( $r['revenue']['USD'] ?? 0 ) / 100 : null;
		unset( $r['resp'], $r['revenue'], $r['mrr'] );
		return $r;
	};
	$rows = array_map( $finish, array_values( $rows ) );
	usort( $rows, fn( $a, $b ) => array( $b['won'], $b['leads'] ) <=> array( $a['won'], $a['leads'] ) );
	// A teljes időszak minden hónapja (az üresek is), hogy a grafikon idővonal legyen.
	for ( $m = substr( $from, 0, 7 ), $i = 0; $m <= substr( $to, 0, 7 ) && $i < 36; $m = gmdate( 'Y-m', strtotime( $m . '-01 +1 month' ) ), $i++ ) {
		$months[ $m ] = $months[ $m ] ?? array( 'month' => $m, 'leads' => 0, 'won' => 0 );
	}
	ksort( $months );

	return array(
		'from'   => $from,
		'to'     => $to,
		'group'  => $group,
		'rows'   => $rows,
		'total'  => $finish( $total ),
		'months' => array_values( $months ),
		'money'  => $money,
	);
}

/**
 * Egy ügyfél eddigi befizetései és aktív havi díjai pénznemenként (centben).
 */
function hpv_sales_client_money( int $client_id ): array {
	$revenue = array();
	foreach ( hpv_p_find( 'invoice', array( 'client_id' => $client_id ), array( 'limit' => 1000 ) ) as $inv ) {
		foreach ( hpv_p_find( 'payment', array( 'invoice_id' => (int) $inv['id'] ), array( 'limit' => 200 ) ) as $pay ) {
			$cur             = $pay['currency'] ?: hpv_p_invoice_currency( $inv );
			$revenue[ $cur ] = ( $revenue[ $cur ] ?? 0 ) + hpv_p_to_cents( $pay['amount'] );
		}
	}
	$subs = hpv_p_find( 'subscription', array( 'client_id' => $client_id, 'status' => 'active' ), array( 'limit' => 200 ) );

	return array( 'revenue' => $revenue, 'mrr' => $subs ? hpv_p_mrr_by_currency( $subs ) : array() );
}

/* ─── Figyelmeztetések ────────────────────────────────────── */

add_action( 'init', 'hpv_sales_schedule' );

function hpv_sales_schedule() {
	if ( ! wp_next_scheduled( 'hpv_sales_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'hpv_sales_hourly' );
	}
}

add_action( 'hpv_sales_hourly', 'hpv_sales_sla_check' );

/**
 * Új érdeklődő, aki a beállított óraszámnál tovább vár: egy levél a felelősnek (ha nincs, az értesítési címre).
 *
 * @return int[] az érintett ügyfelek
 */
function hpv_sales_sla_check(): array {
	$hours = hpv_sales_sla_hours();
	if ( ! $hours ) {
		return array();
	}
	$limit = time() - $hours * HOUR_IN_SECONDS;
	$by    = array();
	foreach ( hpv_p_find( 'client', array( 'status' => 'lead', 'lead_stage' => 'new' ), array( 'limit' => 2000 ) ) as $c ) {
		$at = hpv_sales_ts( $c['lead_at'] );
		if ( ! $at || $at > $limit || $c['sla_reminded_at'] ) {
			continue;
		}
		$by[ (int) $c['lead_owner'] ][] = $c;
	}
	$done = array();
	foreach ( $by as $owner => $list ) {
		$items = implode(
			'',
			array_map(
				fn( $c ) => '<li><a href="' . esc_url( hpv_p_crm_app_url( '/clients/' . (int) $c['id'] ) ) . '">' . esc_html( $c['name'] ) . '</a> — ' . esc_html( hpv_lead_source_label( (string) $c['lead_source'] ?: 'manual' ) ) . ', ' . esc_html( human_time_diff( hpv_sales_ts( $c['lead_at'] ) ) ) . ' óta vár' . ( $c['phone'] ? ', tel.: ' . esc_html( $c['phone'] ) : '' ) . '</li>',
				$list
			)
		);
		$subject = sprintf( 'Válaszra vár: %d érdeklődő (%d órája vagy régebben)', count( $list ), $hours );
		$body    = '<p>Minél gyorsabb a válasz, annál nagyobb az esély. Ha felvetted velük a kapcsolatot, húzd át őket a „Felvettük a kapcsolatot” oszlopba.</p><ul>' . $items . '</ul>';
		$user    = $owner ? get_userdata( $owner ) : null;
		if ( $user ) {
			hpv_p_send( $user->user_email, $subject, hpv_p_email_html( $subject, $body, 'Tölcsér megnyitása', hpv_p_crm_app_url( '/sales' ) ) );
		} else {
			hpv_p_notify_staff( $subject, $body, hpv_p_crm_app_url( '/sales' ) );
		}
		foreach ( $list as $c ) {
			hpv_p_update( 'client', (int) $c['id'], array( 'sla_reminded_at' => current_time( 'mysql', true ) ) );
			$done[] = (int) $c['id'];
		}
	}

	return $done;
}

add_action( 'hpv_retainer_cron', 'hpv_sales_digest' );

/**
 * Napi összefoglaló felelősönként: esedékes és lejárt következő lépések, és a lépés nélküli nyitott érdeklődők.
 *
 * @return array felelős → [esedékes, lépés nélkül]
 */
function hpv_sales_digest( string $today = '' ): array {
	if ( empty( hpv_p_settings()['sales_digest'] ) ) {
		return array();
	}
	$today = $today ?: current_time( 'Y-m-d' );
	$by    = array();
	foreach ( hpv_p_find( 'client', array( 'status' => 'lead' ), array( 'limit' => 2000 ) ) as $c ) {
		if ( ! in_array( $c['lead_stage'] ?: 'new', HPV_SALES_OPEN, true ) ) {
			continue;
		}
		$owner = (int) $c['lead_owner'];
		if ( $c['next_step_date'] && $c['next_step_date'] <= $today ) {
			$by[ $owner ]['due'][] = $c;
		} elseif ( ! $c['next_step_date'] && 'new' !== ( $c['lead_stage'] ?: 'new' ) ) {
			$by[ $owner ]['none'][] = $c;
		}
	}
	$out = array();
	foreach ( $by as $owner => $g ) {
		$due  = $g['due'] ?? array();
		$none = $g['none'] ?? array();
		if ( ! $due ) {
			continue; // csak akkor írunk, ha van ma teendő; a lépés nélküliek a teendők alatt jönnek
		}
		$li   = fn( $c, $extra ) => '<li><a href="' . esc_url( hpv_p_crm_app_url( '/clients/' . (int) $c['id'] ) ) . '">' . esc_html( $c['name'] ) . '</a>' . $extra . '</li>';
		$body = '<p><strong>Ma esedékes vagy lejárt:</strong></p><ul>' . implode( '', array_map( fn( $c ) => $li( $c, ' — ' . esc_html( $c['next_step'] ?: 'következő lépés' ) . ' (' . esc_html( $c['next_step_date'] ) . ')' ), $due ) ) . '</ul>'
			. ( $none ? '<p><strong>Nyitott, de nincs következő lépés:</strong></p><ul>' . implode( '', array_map( fn( $c ) => $li( $c, ' — ' . esc_html( HPV_SALES_STAGES[ $c['lead_stage'] ] ?? '' ) ), $none ) ) . '</ul>' : '' );
		$subj = sprintf( 'Értékesítés ma: %d teendő', count( $due ) );
		$user = $owner ? get_userdata( $owner ) : null;
		if ( $user ) {
			hpv_p_send( $user->user_email, $subj, hpv_p_email_html( $subj, $body, 'Tölcsér megnyitása', hpv_p_crm_app_url( '/sales' ) ) );
		} else {
			hpv_p_notify_staff( $subj, $body, hpv_p_crm_app_url( '/sales' ) );
		}
		$out[ $owner ] = array( count( $due ), count( $none ) );
	}

	return $out;
}

/* ─── Frissítés: a meglévő érdeklődők a tölcsérbe ─────────── */

function hpv_sales_migrate(): void {
	$now = current_time( 'mysql', true );
	foreach ( hpv_p_find( 'client', array( 'status' => 'lead' ), array( 'limit' => 5000 ) ) as $c ) {
		if ( $c['lead_stage'] ) {
			continue;
		}
		$notes  = (string) $c['notes'];
		$source = false !== strpos( $notes, 'Website Grader' ) ? 'grader' : ( false !== strpos( $notes, 'Bitrix24' ) ? 'bitrix' : ( false !== strpos( $notes, 'Kapcsolati' ) ? 'contact' : 'manual' ) );
		hpv_p_update(
			'client',
			(int) $c['id'],
			array(
				'lead_stage'      => 'new',
				'lead_at'         => $c['created_at'],
				'lead_source'     => $source,
				'lead_channel'    => hpv_sales_lead_channel( array(), $source ),
				'sla_reminded_at' => $now, // régi érdeklődők: nincs válaszidő-figyelmeztetés
			)
		);
	}
}

/* ─── REST ────────────────────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_sales_routes' );

function hpv_sales_routes() {
	$staff = fn() => hpv_p_is_staff();
	$fail  = fn( WP_Error $e ) => new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => 'not_found' === $e->get_error_code() ? 404 : 400 ) );

	register_rest_route(
		'hpv/v1',
		'/sales/board',
		array(
			'methods'             => 'GET',
			'permission_callback' => $staff,
			'callback'            => fn( WP_REST_Request $r ) => rest_ensure_response(
				hpv_sales_board(
					array(
						'owner'   => 'me' === $r['owner'] ? get_current_user_id() : (int) $r['owner'],
						'channel' => sanitize_key( (string) $r['channel'] ),
					)
				)
			),
		)
	);
	register_rest_route(
		'hpv/v1',
		'/sales/meta',
		array(
			'methods'             => 'GET',
			'permission_callback' => $staff,
			'callback'            => fn() => rest_ensure_response( array_diff_key( hpv_sales_board( array( 'channel' => '__none__' ) ), array( 'cards' => 0, 'kpi' => 0 ) ) ),
		)
	);
	register_rest_route(
		'hpv/v1',
		'/sales/leads',
		array(
			'methods'             => 'POST',
			'permission_callback' => $staff,
			'callback'            => function ( WP_REST_Request $r ) use ( $fail ) {
				$name = sanitize_text_field( (string) $r['name'] );
				if ( '' === $name ) {
					return new WP_Error( 'name', 'Add meg a nevet (cég vagy személy).', array( 'status' => 400 ) );
				}
				$email = sanitize_email( (string) $r['email'] );
				if ( '' !== trim( (string) $r['email'] ) && ! is_email( $email ) ) {
					return new WP_Error( 'email', 'Érvénytelen e-mail cím.', array( 'status' => 400 ) );
				}
				$channel = isset( HPV_SALES_CHANNELS[ (string) $r['channel'] ] ) ? (string) $r['channel'] : 'offline';
				$id      = hpv_p_insert(
					'client',
					array(
						'name'         => $name,
						'contact_name' => sanitize_text_field( (string) $r['contact_name'] ),
						'email'        => $email,
						'phone'        => sanitize_text_field( (string) $r['phone'] ),
						'country'      => 'HU' === $r['country'] ? 'HU' : 'US',
						'status'       => 'lead',
						'lead_source'  => 'manual',
						'lead_channel' => $channel,
						'lead_owner'   => (int) $r['owner'] ?: get_current_user_id(),
					)
				);
				if ( ! $id ) {
					return new WP_Error( 'db', 'Az adatbázisba írás nem sikerült.', array( 'status' => 500 ) );
				}
				if ( '' !== trim( (string) $r['note'] ) ) {
					hpv_p_log( $id, 'note', sanitize_textarea_field( (string) $r['note'] ), false, get_current_user_id() );
				}
				$res = hpv_sales_update( $id, array_intersect_key( $r->get_params(), array_flip( array( 'value', 'next_step', 'next_step_date', 'campaign' ) ) ), get_current_user_id() );
				return is_wp_error( $res ) ? $fail( $res ) : rest_ensure_response( $res );
			},
		)
	);
	register_rest_route(
		'hpv/v1',
		'/sales/leads/(?P<id>\d+)',
		array(
			'methods'             => 'POST',
			'permission_callback' => $staff,
			'callback'            => function ( WP_REST_Request $r ) use ( $fail ) {
				$in  = array_intersect_key( $r->get_params(), array_flip( array( 'stage', 'lost_reason', 'owner', 'value', 'next_step', 'next_step_date', 'channel', 'campaign' ) ) );
				$res = hpv_sales_update( (int) $r['id'], $in, get_current_user_id() );
				return is_wp_error( $res ) ? $fail( $res ) : rest_ensure_response( hpv_sales_detail( hpv_p_get( 'client', (int) $r['id'] ) ) );
			},
		)
	);
	register_rest_route(
		'hpv/v1',
		'/sales/sources',
		array(
			'methods'             => 'GET',
			'permission_callback' => $staff,
			'callback'            => function ( WP_REST_Request $r ) {
				$to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['to'] ) ? (string) $r['to'] : current_time( 'Y-m-d' );
				$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['from'] ) ? (string) $r['from'] : gmdate( 'Y-m-d', strtotime( $to . ' -89 days' ) );
				$group = in_array( $r['group'], array( 'channel', 'campaign', 'source' ), true ) ? (string) $r['group'] : 'channel';
				return rest_ensure_response( hpv_sales_sources( min( $from, $to ), max( $from, $to ), $group, hpv_p_can( 'invoices' ) ) );
			},
		)
	);
}
