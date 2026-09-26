<?php
/**
 * Adatforrások a havi riporthoz: Google Search Console, Google Analytics 4, Google Ads, Meta Ads.
 *
 * Google: egy OAuth-kapcsolat az ügynökség Google fiókjával (aki hozzáfér az ügyfelek tulajdonaihoz és a Google Ads
 * kezelői fiókhoz). Kulcsok a wp-config.php-ban:
 *   define( 'HPV_GOOGLE_CLIENT_ID', '…' ); define( 'HPV_GOOGLE_CLIENT_SECRET', '…' );
 *   define( 'HPV_GOOGLE_ADS_DEVELOPER_TOKEN', '…' );        // csak Google Ads-hez
 *   define( 'HPV_GOOGLE_ADS_LOGIN_CUSTOMER_ID', '1234567890' ); // a kezelői (MCC) fiók, kötőjel nélkül
 * Meta: a Business Manager rendszerfelhasználójának tartós tokenje (ads_read):
 *   define( 'HPV_META_ACCESS_TOKEN', '…' );
 * Az API-verziók felülírhatók: HPV_GOOGLE_ADS_API_VERSION, HPV_META_API_VERSION.
 *
 * Ügyfelenként az adatlapon: gsc_property, ga4_property, gads_customer, meta_ad_account.
 * A hónap és az előző 5 hónap számai egy-egy lekéréssel jönnek (a riport grafikonjához).
 */

defined( 'ABSPATH' ) || exit;

const HPV_GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
const HPV_GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const HPV_GOOGLE_SCOPES    = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/adwords';
const HPV_GSC_API          = 'https://www.googleapis.com/webmasters/v3/';
const HPV_GA4_API          = 'https://analyticsdata.googleapis.com/v1beta/';
const HPV_GA4_ADMIN_API    = 'https://analyticsadmin.googleapis.com/v1beta/';
const HPV_GADS_API         = 'https://googleads.googleapis.com/';
const HPV_META_API         = 'https://graph.facebook.com/';

/**
 * A riportban megjelenő mutatók angol címkéi (a magyar fordítás az i18n szótárban; a teszt ellenőrzi).
 */
const HPV_METRIC_LABELS = array(
	'Google Search', 'Clicks from Google', 'Impressions in Google', 'Click-through rate', 'Average position',
	'Website', 'Visitors', 'Sessions', 'Visits from Google search', 'Key events (leads, calls, forms)',
	'Google Ads', 'Ad spend', 'Ad clicks', 'Conversions', 'Cost per conversion',
	'Meta Ads', 'Impressions', 'Link clicks', 'Leads', 'Cost per lead',
);

function hpv_google_configured(): bool {
	return defined( 'HPV_GOOGLE_CLIENT_ID' ) && HPV_GOOGLE_CLIENT_ID && defined( 'HPV_GOOGLE_CLIENT_SECRET' ) && HPV_GOOGLE_CLIENT_SECRET;
}

function hpv_google_connected(): bool {
	return hpv_google_configured() && '' !== (string) ( hpv_google_tokens()['refresh_token'] ?? '' );
}

function hpv_gads_configured(): bool {
	return hpv_google_connected() && defined( 'HPV_GOOGLE_ADS_DEVELOPER_TOKEN' ) && HPV_GOOGLE_ADS_DEVELOPER_TOKEN;
}

function hpv_meta_configured(): bool {
	return defined( 'HPV_META_ACCESS_TOKEN' ) && HPV_META_ACCESS_TOKEN;
}

function hpv_gads_version(): string {
	return defined( 'HPV_GOOGLE_ADS_API_VERSION' ) ? (string) HPV_GOOGLE_ADS_API_VERSION : 'v21';
}

function hpv_meta_version(): string {
	return defined( 'HPV_META_API_VERSION' ) ? (string) HPV_META_API_VERSION : 'v23.0';
}

function hpv_google_redirect_uri(): string {
	return hpv_p_scheme() . '://' . hpv_p_crm_host() . '/wp-admin/admin-post.php?action=hpv_google_callback';
}

/* ─── Google OAuth ────────────────────────────────────────── */

function hpv_google_tokens(): array {
	$stored = (string) get_option( 'hpv_google_tokens', '' );
	$data   = '' !== $stored ? json_decode( hpv_p_decrypt( $stored ), true ) : null;

	return is_array( $data ) ? $data : array();
}

function hpv_google_save_tokens( array $t ): void {
	update_option( 'hpv_google_tokens', hpv_p_encrypt( wp_json_encode( $t ) ), false );
}

/**
 * @return array|WP_Error
 */
function hpv_google_token_request( array $params ) {
	$res = wp_remote_post(
		HPV_GOOGLE_TOKEN_URL,
		array(
			'timeout' => 20,
			'body'    => array_merge( $params, array( 'client_id' => HPV_GOOGLE_CLIENT_ID, 'client_secret' => HPV_GOOGLE_CLIENT_SECRET ) ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'google_http', 'A Google nem érhető el: ' . $res->get_error_message() );
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( empty( $data['access_token'] ) ) {
		return new WP_Error( 'google_auth', 'Google hitelesítés: ' . ( $data['error_description'] ?? $data['error'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) . ( 'invalid_grant' === ( $data['error'] ?? '' ) ? ' — kapcsold össze újra a Beállításokban.' : '' ) );
	}

	return $data;
}

/**
 * @return string|WP_Error
 */
function hpv_google_access_token() {
	$t = hpv_google_tokens();
	if ( ! hpv_google_configured() || empty( $t['refresh_token'] ) ) {
		return new WP_Error( 'google_off', 'A Google nincs összekapcsolva (CRM → Beállítások).' );
	}
	if ( ! empty( $t['access_token'] ) && (int) ( $t['expires_at'] ?? 0 ) > time() + 60 ) {
		return (string) $t['access_token'];
	}
	$data = hpv_google_token_request( array( 'grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'] ) );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$t['access_token'] = $data['access_token'];
	$t['expires_at']   = time() + (int) ( $data['expires_in'] ?? 3600 );
	hpv_google_save_tokens( $t );

	return (string) $data['access_token'];
}

add_action( 'admin_post_hpv_google_connect', 'hpv_google_connect' );
add_action( 'admin_post_hpv_google_callback', 'hpv_google_callback' );
add_action( 'admin_post_hpv_google_disconnect', 'hpv_google_disconnect' );

function hpv_google_connect() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hpv_google_connect' ) || ! hpv_google_configured() ) {
		wp_die( 'Nincs jogosultság, vagy hiányzik a HPV_GOOGLE_CLIENT_ID / HPV_GOOGLE_CLIENT_SECRET.' );
	}
	$state = wp_generate_password( 32, false, false );
	set_transient( 'hpv_google_state_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );
	wp_redirect( // phpcs:ignore WordPress.Security.SafeRedirect -- Google hitelesítés
		HPV_GOOGLE_AUTH_URL . '?' . http_build_query(
			array(
				'client_id'     => HPV_GOOGLE_CLIENT_ID,
				'redirect_uri'  => hpv_google_redirect_uri(),
				'response_type' => 'code',
				'scope'         => HPV_GOOGLE_SCOPES,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			)
		)
	);
	exit;
}

function hpv_google_callback() {
	$back  = admin_url( 'admin.php?page=hpv-crm-settings' );
	$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
	if ( ! current_user_can( 'manage_options' ) || '' === $state || (int) get_transient( 'hpv_google_state_' . $state ) !== get_current_user_id() ) {
		wp_die( 'Érvénytelen vagy lejárt Google visszairányítás. Próbáld újra a Beállítások oldalról.' );
	}
	delete_transient( 'hpv_google_state_' . $state );
	if ( ! empty( $_GET['error'] ) ) {
		hpv_p_redirect( $back, 'error', array( 'error' => 'Google: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) );
	}
	$data = hpv_google_token_request(
		array(
			'grant_type'   => 'authorization_code',
			'code'         => sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ),
			'redirect_uri' => hpv_google_redirect_uri(),
		)
	);
	if ( is_wp_error( $data ) ) {
		hpv_p_redirect( $back, 'error', array( 'error' => $data->get_error_message() ) );
	}
	if ( empty( $data['refresh_token'] ) ) {
		hpv_p_redirect( $back, 'error', array( 'error' => 'A Google nem adott tartós hozzáférést. Vond vissza az app hozzáférését a Google fiókodban (myaccount.google.com → Biztonság → Harmadik féltől származó hozzáférés), majd kapcsold össze újra.' ) );
	}
	hpv_google_save_tokens(
		array(
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'],
			'expires_at'    => time() + (int) ( $data['expires_in'] ?? 3600 ),
			'scope'         => (string) ( $data['scope'] ?? '' ),
		)
	);
	hpv_p_redirect( $back, 'saved' );
}

function hpv_google_disconnect() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hpv_google_disconnect' ) ) {
		wp_die( 'Nincs jogosultság.' );
	}
	$t = hpv_google_tokens();
	if ( ! empty( $t['refresh_token'] ) ) {
		wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 10, 'body' => array( 'token' => $t['refresh_token'] ) ) );
	}
	delete_option( 'hpv_google_tokens' );
	hpv_p_redirect( admin_url( 'admin.php?page=hpv-crm-settings' ), 'saved' );
}

/* ─── HTTP ────────────────────────────────────────────────── */

/**
 * JSON kérés (Google vagy Meta). A Google 401-nél egyszer újrapróbál friss tokennel.
 *
 * @return array|WP_Error
 */
function hpv_conn_request( string $service, string $method, string $url, ?array $body = null, array $headers = array(), bool $retry = true ) {
	if ( 'meta' !== $service ) {
		$token = hpv_google_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers['Authorization'] = 'Bearer ' . $token;
	}
	$res = wp_remote_request(
		$url,
		array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge( array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), $headers ),
			'body'    => null === $body ? null : wp_json_encode( $body ),
		)
	);
	$label = array( 'gsc' => 'Search Console', 'ga4' => 'Analytics', 'gads' => 'Google Ads', 'meta' => 'Meta', 'google' => 'Google' )[ $service ] ?? $service;
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $service . '_http', $label . ' nem érhető el: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 401 === $code && $retry && 'meta' !== $service ) {
		$t = hpv_google_tokens();
		unset( $t['access_token'] );
		hpv_google_save_tokens( $t );
		return hpv_conn_request( $service, $method, $url, $body, array_diff_key( $headers, array( 'Authorization' => 1 ) ), false );
	}
	if ( $code >= 400 || ! is_array( $data ) ) {
		$err = is_array( $data ) ? ( $data['error']['message'] ?? ( is_array( $data[0]['error'] ?? null ) ? $data[0]['error']['message'] : '' ) ) : '';
		return new WP_Error( $service . '_api', $label . ': ' . ( $err ?: 'HTTP ' . $code ) );
	}

	return $data;
}

/* ─── Hónapok ─────────────────────────────────────────────── */

/**
 * A riport hónapja és az előző 5 hónap: [ 'YYYY-MM', … ] (régebbi elöl), valamint a teljes időszak első és utolsó napja.
 */
function hpv_conn_months( string $period, int $count = 6 ): array {
	$months = array();
	for ( $i = $count - 1; $i >= 0; $i-- ) {
		$months[] = gmdate( 'Y-m', strtotime( $period . "-01 -$i month" ) );
	}

	return array( $months, $months[0] . '-01', gmdate( 'Y-m-t', strtotime( $period . '-01' ) ) );
}

/**
 * Havi idősorból riport-mutató: érték = a riport hónapja, előző = az előtte lévő, history = mind.
 */
function hpv_conn_metric( string $section, string $key, string $label, array $by_month, array $months, string $format = 'int', string $better = 'up' ): array {
	// Két tizedesre kerekítve (CTR, helyezés, költség/konverzió); a darabszámok egészek maradnak.
	$series = array_map( fn( $m ) => isset( $by_month[ $m ] ) ? round( (float) $by_month[ $m ], 2 ) : null, $months );
	$n      = count( $series );

	return array(
		'section' => $section,
		'key'     => $key,
		'label'   => $label,
		'value'   => $series[ $n - 1 ],
		'prev'    => $series[ $n - 2 ] ?? null,
		'format'  => $format,
		'better'  => $better,
		'history' => array_map( fn( $v ) => (float) $v, array_filter( $series, fn( $v ) => null !== $v ) ),
		'source'  => $key,
	);
}

/* ─── Search Console ──────────────────────────────────────── */

/**
 * @return array|WP_Error Mutatók.
 */
function hpv_gsc_metrics( string $property, string $period ) {
	list( $months, $start, $end ) = hpv_conn_months( $period );
	$data = hpv_conn_request(
		'gsc',
		'POST',
		HPV_GSC_API . 'sites/' . rawurlencode( $property ) . '/searchAnalytics/query',
		array( 'startDate' => $start, 'endDate' => $end, 'dimensions' => array( 'date' ), 'rowLimit' => 25000, 'dataState' => 'final' )
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$agg = array();
	foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
		$m = substr( (string) ( $row['keys'][0] ?? '' ), 0, 7 );
		$agg[ $m ]['clicks']      = ( $agg[ $m ]['clicks'] ?? 0 ) + (float) $row['clicks'];
		$agg[ $m ]['impressions'] = ( $agg[ $m ]['impressions'] ?? 0 ) + (float) $row['impressions'];
		$agg[ $m ]['pos_w']       = ( $agg[ $m ]['pos_w'] ?? 0 ) + (float) $row['position'] * (float) $row['impressions'];
	}
	$col = fn( $f ) => array_map( $f, $agg );
	$s   = 'Google Search';

	return array(
		hpv_conn_metric( $s, 'gsc_clicks', 'Clicks from Google', $col( fn( $a ) => $a['clicks'] ), $months ),
		hpv_conn_metric( $s, 'gsc_impressions', 'Impressions in Google', $col( fn( $a ) => $a['impressions'] ), $months ),
		hpv_conn_metric( $s, 'gsc_ctr', 'Click-through rate', $col( fn( $a ) => $a['impressions'] ? $a['clicks'] / $a['impressions'] * 100 : 0 ), $months, 'pct' ),
		hpv_conn_metric( $s, 'gsc_position', 'Average position', $col( fn( $a ) => $a['impressions'] ? $a['pos_w'] / $a['impressions'] : 0 ), $months, 'position', 'down' ),
	);
}

/* ─── Google Analytics 4 ──────────────────────────────────── */

/**
 * @return array|WP_Error
 */
function hpv_ga4_metrics( string $property, string $period ) {
	list( $months, $start, $end ) = hpv_conn_months( $period );
	$property = preg_replace( '/\D/', '', $property );
	$data     = hpv_conn_request(
		'ga4',
		'POST',
		HPV_GA4_API . 'properties/' . $property . ':runReport',
		array(
			'dateRanges' => array( array( 'startDate' => $start, 'endDate' => $end ) ),
			'dimensions' => array( array( 'name' => 'yearMonth' ), array( 'name' => 'sessionDefaultChannelGroup' ) ),
			'metrics'    => array( array( 'name' => 'totalUsers' ), array( 'name' => 'sessions' ), array( 'name' => 'keyEvents' ) ),
			'limit'      => 10000,
		)
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$users = $sessions = $organic = $events = array(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
	foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
		$ym = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
		$m  = substr( $ym, 0, 4 ) . '-' . substr( $ym, 4, 2 );
		$ch = (string) ( $row['dimensionValues'][1]['value'] ?? '' );
		$v  = array_map( fn( $x ) => (float) ( $x['value'] ?? 0 ), (array) ( $row['metricValues'] ?? array() ) );
		// A felhasználók csatornánként összeadva kissé többet adnak (egy ember több csatornán is jöhet); havi trendnek megfelel.
		$users[ $m ]    = ( $users[ $m ] ?? 0 ) + ( $v[0] ?? 0 );
		$sessions[ $m ] = ( $sessions[ $m ] ?? 0 ) + ( $v[1] ?? 0 );
		$events[ $m ]   = ( $events[ $m ] ?? 0 ) + ( $v[2] ?? 0 );
		if ( 'Organic Search' === $ch ) {
			$organic[ $m ] = ( $organic[ $m ] ?? 0 ) + ( $v[1] ?? 0 );
		}
	}
	$s = 'Website';

	return array(
		hpv_conn_metric( $s, 'ga4_users', 'Visitors', $users, $months ),
		hpv_conn_metric( $s, 'ga4_sessions', 'Sessions', $sessions, $months ),
		hpv_conn_metric( $s, 'ga4_organic', 'Visits from Google search', $organic, $months ),
		hpv_conn_metric( $s, 'ga4_key_events', 'Key events (leads, calls, forms)', $events, $months ),
	);
}

/* ─── Google Ads ──────────────────────────────────────────── */

/**
 * @return array|WP_Error
 */
function hpv_gads_metrics( string $customer, string $period ) {
	if ( ! hpv_gads_configured() ) {
		return new WP_Error( 'gads_off', 'Google Ads: hiányzik a fejlesztői token (HPV_GOOGLE_ADS_DEVELOPER_TOKEN).' );
	}
	list( $months, $start, $end ) = hpv_conn_months( $period );
	$customer = preg_replace( '/\D/', '', $customer );
	$headers  = array( 'developer-token' => HPV_GOOGLE_ADS_DEVELOPER_TOKEN );
	if ( defined( 'HPV_GOOGLE_ADS_LOGIN_CUSTOMER_ID' ) && HPV_GOOGLE_ADS_LOGIN_CUSTOMER_ID ) {
		$headers['login-customer-id'] = preg_replace( '/\D/', '', (string) HPV_GOOGLE_ADS_LOGIN_CUSTOMER_ID );
	}
	$query = "SELECT customer.currency_code, segments.month, metrics.cost_micros, metrics.clicks, metrics.conversions FROM customer WHERE segments.date BETWEEN '$start' AND '$end'";
	$data  = hpv_conn_request( 'gads', 'POST', HPV_GADS_API . hpv_gads_version() . '/customers/' . $customer . '/googleAds:searchStream', array( 'query' => $query ), $headers );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$cost = $clicks = $conv = array(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
	$currency = '';
	// A searchStream kötegek tömbjét adja, mindegyikben results.
	foreach ( $data as $batch ) {
		foreach ( (array) ( $batch['results'] ?? array() ) as $r ) {
			$currency     = (string) ( $r['customer']['currencyCode'] ?? $currency );
			$m            = substr( (string) ( $r['segments']['month'] ?? '' ), 0, 7 );
			$cost[ $m ]   = ( $cost[ $m ] ?? 0 ) + (float) ( $r['metrics']['costMicros'] ?? 0 ) / 1000000;
			$clicks[ $m ] = ( $clicks[ $m ] ?? 0 ) + (float) ( $r['metrics']['clicks'] ?? 0 );
			$conv[ $m ]   = ( $conv[ $m ] ?? 0 ) + (float) ( $r['metrics']['conversions'] ?? 0 );
		}
	}
	$cpa = array();
	foreach ( $cost as $m => $c ) {
		if ( ! empty( $conv[ $m ] ) ) {
			$cpa[ $m ] = $c / $conv[ $m ];
		}
	}
	$s = 'Google Ads';

	return hpv_conn_currency(
		array(
			hpv_conn_metric( $s, 'gads_cost', 'Ad spend', $cost, $months, 'money', 'neutral' ),
			hpv_conn_metric( $s, 'gads_clicks', 'Ad clicks', $clicks, $months ),
			hpv_conn_metric( $s, 'gads_conversions', 'Conversions', $conv, $months, 'decimal' ),
			hpv_conn_metric( $s, 'gads_cpa', 'Cost per conversion', $cpa, $months, 'money', 'down' ),
		),
		$currency
	);
}

/* ─── Meta Ads ────────────────────────────────────────────── */

/**
 * @return array|WP_Error
 */
function hpv_meta_metrics( string $account, string $period ) {
	if ( ! hpv_meta_configured() ) {
		return new WP_Error( 'meta_off', 'Meta: hiányzik a HPV_META_ACCESS_TOKEN.' );
	}
	list( $months, $start, $end ) = hpv_conn_months( $period );
	$account = 'act_' . preg_replace( '/\D/', '', $account );
	$url     = HPV_META_API . hpv_meta_version() . '/' . $account . '/insights?' . http_build_query(
		array(
			'fields'         => 'spend,impressions,inline_link_clicks,actions,account_currency',
			'time_range'     => wp_json_encode( array( 'since' => $start, 'until' => $end ) ),
			'time_increment' => 'monthly',
			'level'          => 'account',
			'access_token'   => HPV_META_ACCESS_TOKEN,
		)
	);
	$data = hpv_conn_request( 'meta', 'GET', $url );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$spend = $impr = $clicks = $leads = array(); // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments
	$currency = '';
	foreach ( (array) ( $data['data'] ?? array() ) as $row ) {
		$currency     = (string) ( $row['account_currency'] ?? $currency );
		$m            = substr( (string) ( $row['date_start'] ?? '' ), 0, 7 );
		$spend[ $m ]  = (float) ( $row['spend'] ?? 0 );
		$impr[ $m ]   = (float) ( $row['impressions'] ?? 0 );
		$clicks[ $m ] = (float) ( $row['inline_link_clicks'] ?? 0 );
		foreach ( (array) ( $row['actions'] ?? array() ) as $a ) {
			// Űrlapos (Lead Ads) és weboldali lead: ami a fiókban be van állítva, az jelenik meg.
			if ( in_array( $a['action_type'] ?? '', array( 'lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead' ), true ) ) {
				$leads[ $m ] = max( $leads[ $m ] ?? 0, (float) $a['value'] );
			}
		}
	}
	$cpl = array();
	foreach ( $spend as $m => $v ) {
		if ( ! empty( $leads[ $m ] ) ) {
			$cpl[ $m ] = $v / $leads[ $m ];
		}
	}
	$s = 'Meta Ads';

	return hpv_conn_currency( array(
		hpv_conn_metric( $s, 'meta_spend', 'Ad spend', $spend, $months, 'money', 'neutral' ),
		hpv_conn_metric( $s, 'meta_impressions', 'Impressions', $impr, $months ),
		hpv_conn_metric( $s, 'meta_clicks', 'Link clicks', $clicks, $months ),
		hpv_conn_metric( $s, 'meta_leads', 'Leads', $leads, $months ),
		hpv_conn_metric( $s, 'meta_cpl', 'Cost per lead', $cpl, $months, 'money', 'down' ),
	), $currency );
}

/**
 * A hirdetési fiók saját pénzneme a pénz-mutatókon (eltérhet az ügyfél országától).
 */
function hpv_conn_currency( array $metrics, string $currency ): array {
	$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', $currency ) );
	if ( '' === $currency ) {
		return $metrics;
	}

	return array_map( fn( $m ) => 'money' === $m['format'] ? array_merge( $m, array( 'currency' => $currency ) ) : $m, $metrics );
}

/* ─── A riportba ──────────────────────────────────────────── */

add_filter( 'hpv_report_metrics', 'hpv_conn_report_metrics', 10, 3 );
add_filter( 'hpv_report_errors', fn( $errors ) => array_merge( $errors, $GLOBALS['hpv_conn_errors'] ?? array() ) );

function hpv_conn_report_metrics( array $metrics, array $client, string $period ): array {
	$GLOBALS['hpv_conn_errors'] = array();
	$sources                    = array(
		'gsc_property'    => 'hpv_gsc_metrics',
		'ga4_property'    => 'hpv_ga4_metrics',
		'gads_customer'   => 'hpv_gads_metrics',
		'meta_ad_account' => 'hpv_meta_metrics',
	);
	foreach ( $sources as $field => $fn ) {
		$id = trim( (string) ( $client[ $field ] ?? '' ) );
		if ( '' === $id ) {
			continue;
		}
		$res = $fn( $id, $period );
		if ( is_wp_error( $res ) ) {
			$GLOBALS['hpv_conn_errors'][] = $res->get_error_message();
			continue;
		}
		$metrics = array_merge( $metrics, $res );
	}

	return $metrics;
}

/* ─── Fiókok listája (választóhoz) és állapot ─────────────── */

add_action( 'rest_api_init', 'hpv_conn_routes' );

function hpv_conn_routes() {
	register_rest_route(
		'hpv/v1',
		'/connectors',
		array(
			'methods'             => 'GET',
			'permission_callback' => fn() => hpv_p_is_staff(),
			'callback'            => fn() => rest_ensure_response( hpv_conn_status() ),
		)
	);
	register_rest_route(
		'hpv/v1',
		'/connectors/accounts',
		array(
			'methods'             => 'GET',
			'permission_callback' => fn() => hpv_p_is_staff(),
			'callback'            => fn() => rest_ensure_response( hpv_conn_accounts() ),
		)
	);
	register_rest_route(
		'hpv/v1',
		'/connectors/client/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'permission_callback' => fn() => hpv_p_is_staff(),
			'callback'            => function ( WP_REST_Request $r ) {
				$client = hpv_p_get( 'client', (int) $r['id'] );
				return $client ? rest_ensure_response( hpv_conn_client( $client ) ) : new WP_Error( 'not_found', 'Nincs ilyen ügyfél.', array( 'status' => 404 ) );
			},
		)
	);
	register_rest_route(
		'hpv/v1',
		'/connectors/client/(?P<id>\d+)',
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => hpv_p_is_staff(),
			'callback'            => function ( WP_REST_Request $r ) {
				$client = hpv_p_get( 'client', (int) $r['id'] );
				if ( ! $client ) {
					return new WP_Error( 'not_found', 'Nincs ilyen ügyfél.', array( 'status' => 404 ) );
				}
				$data = hpv_p_sanitize( 'client', array_intersect_key( $r->get_params(), array_flip( array( 'gsc_property', 'ga4_property', 'gads_customer', 'meta_ad_account' ) ) ) );
				if ( $data ) {
					hpv_p_update( 'client', (int) $client['id'], $data );
				}
				return rest_ensure_response( hpv_conn_client( hpv_p_get( 'client', (int) $client['id'] ) ) );
			},
		)
	);
}

function hpv_conn_client( array $c ): array {
	return array(
		'id'              => (int) $c['id'],
		'gsc_property'    => (string) $c['gsc_property'],
		'ga4_property'    => (string) $c['ga4_property'],
		'gads_customer'   => (string) $c['gads_customer'],
		'meta_ad_account' => (string) $c['meta_ad_account'],
	);
}

function hpv_conn_status(): array {
	return array(
		'google' => array( 'configured' => hpv_google_configured(), 'connected' => hpv_google_connected() ),
		'gads'   => hpv_gads_configured(),
		'meta'   => hpv_meta_configured(),
	);
}

/**
 * Az elérhető tulajdonok és fiókok, a választóhoz. Ami nem érhető el, ott üres lista és hibaüzenet.
 */
function hpv_conn_accounts(): array {
	$out = array( 'gsc' => array(), 'ga4' => array(), 'gads' => array(), 'meta' => array(), 'errors' => array() );
	if ( hpv_google_connected() ) {
		$sites = hpv_conn_request( 'gsc', 'GET', HPV_GSC_API . 'sites' );
		if ( is_wp_error( $sites ) ) {
			$out['errors'][] = $sites->get_error_message();
		} else {
			$out['gsc'] = array_values( array_map( fn( $s ) => array( 'id' => $s['siteUrl'], 'label' => $s['siteUrl'] ), (array) ( $sites['siteEntry'] ?? array() ) ) );
		}
		$acc = hpv_conn_request( 'ga4', 'GET', HPV_GA4_ADMIN_API . 'accountSummaries?pageSize=200' );
		if ( is_wp_error( $acc ) ) {
			$out['errors'][] = $acc->get_error_message();
		} else {
			foreach ( (array) ( $acc['accountSummaries'] ?? array() ) as $a ) {
				foreach ( (array) ( $a['propertySummaries'] ?? array() ) as $p ) {
					$out['ga4'][] = array( 'id' => preg_replace( '/\D/', '', (string) $p['property'] ), 'label' => ( $a['displayName'] ?? '' ) . ' — ' . ( $p['displayName'] ?? '' ) );
				}
			}
		}
		if ( hpv_gads_configured() ) {
			$cust = hpv_conn_request( 'gads', 'GET', HPV_GADS_API . hpv_gads_version() . '/customers:listAccessibleCustomers', null, array( 'developer-token' => HPV_GOOGLE_ADS_DEVELOPER_TOKEN ) );
			if ( is_wp_error( $cust ) ) {
				$out['errors'][] = $cust->get_error_message();
			} else {
				$out['gads'] = array_values( array_map( fn( $rn ) => array( 'id' => preg_replace( '/\D/', '', $rn ), 'label' => preg_replace( '/^(\d{3})(\d{3})(\d{4})$/', '$1-$2-$3', preg_replace( '/\D/', '', $rn ) ) ), (array) ( $cust['resourceNames'] ?? array() ) ) );
			}
		}
	}
	if ( hpv_meta_configured() ) {
		$ads = hpv_conn_request( 'meta', 'GET', HPV_META_API . hpv_meta_version() . '/me/adaccounts?' . http_build_query( array( 'fields' => 'name,account_id', 'limit' => 200, 'access_token' => HPV_META_ACCESS_TOKEN ) ) );
		if ( is_wp_error( $ads ) ) {
			$out['errors'][] = $ads->get_error_message();
		} else {
			$out['meta'] = array_values( array_map( fn( $a ) => array( 'id' => 'act_' . $a['account_id'], 'label' => ( $a['name'] ?? '' ) . ' (act_' . $a['account_id'] . ')' ), (array) ( $ads['data'] ?? array() ) ) );
		}
	}

	return $out;
}

/**
 * A Beállítások oldal része.
 */
function hpv_conn_admin_section() {
	$ok = '<span style="color:#1a7f37">✔ rendben</span>';
	$no = '<span style="color:#b32d2e">✘ nincs beállítva</span>';
	?>
	<h2 class="title">Riport adatforrások (Google, Meta)</h2>
	<table class="widefat striped" style="max-width:860px"><tbody>
		<tr><td>Google (Search Console, Analytics)</td><td><?php echo hpv_google_connected() ? $ok : ( hpv_google_configured() ? '<span style="color:#9a6700">nincs összekapcsolva</span>' : $no ); // phpcs:ignore ?></td><td><code>HPV_GOOGLE_CLIENT_ID, HPV_GOOGLE_CLIENT_SECRET</code></td></tr>
		<tr><td>Google Ads</td><td><?php echo hpv_gads_configured() ? $ok : $no; // phpcs:ignore ?></td><td><code>HPV_GOOGLE_ADS_DEVELOPER_TOKEN, HPV_GOOGLE_ADS_LOGIN_CUSTOMER_ID</code></td></tr>
		<tr><td>Meta Ads</td><td><?php echo hpv_meta_configured() ? $ok : $no; // phpcs:ignore ?></td><td><code>HPV_META_ACCESS_TOKEN</code></td></tr>
	</tbody></table>
	<?php if ( hpv_google_configured() ) : ?>
		<p style="display:flex;gap:8px;align-items:center">
			<?php $action = hpv_google_connected() ? 'hpv_google_disconnect' : 'hpv_google_connect'; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<?php wp_nonce_field( $action ); ?>
				<button class="button<?php echo hpv_google_connected() ? '' : ' button-primary'; ?>"><?php echo hpv_google_connected() ? 'Google leválasztása' : 'Google összekapcsolása'; ?></button>
			</form>
			<span class="description">A Google Cloud OAuth kliensben beállítandó átirányítási cím: <code><?php echo esc_html( hpv_google_redirect_uri() ); ?></code></span>
		</p>
	<?php endif; ?>
	<p class="description">Ügyfelenként: CRM app → Ügyfelek → „Riport adatok”, vagy az ügyfél adatlapján.</p>
	<?php
}
