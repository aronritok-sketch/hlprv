<?php
/**
 * Plugin Name: HelloProVision Website Grader
 * Description: Ingyenes weboldal- és helyi SEO-elemző délnyugat-floridai cégeknek. Az ügyfél beírja az URL-jét, pontszámot kap sebességre, mobilra, SEO-ra, schemára és helyi cégadatokra; a javítási javaslatokat e-mail cím megadása után kapja meg. Shortcode: [hpv_grader]
 * Version:     1.0.0
 * Author:      HelloProVision
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

const HPV_GRADER_VERSION  = '1.0.0';
const HPV_GRADER_OPTION   = 'hpv_grader_settings';
const HPV_GRADER_CPT      = 'hpv_grader_lead';
const HPV_GRADER_NS       = 'hpv-grader/v1';
const HPV_GRADER_PAGE     = 'hpv-grader';
const HPV_GRADER_SETTINGS = 'hpv-grader-settings';
const HPV_GRADER_TTL      = 2 * DAY_IN_SECONDS;

require_once __DIR__ . '/includes/analyzer.php';

/* ─── Beállítások ─────────────────────────────────────────── */

function hpv_grader_defaults(): array {
	return array(
		'psi_key'       => '',
		'notify_email'  => get_option( 'admin_email' ),
		'from_name'     => 'HelloProVision',
		'cta_url'       => home_url( '/book-a-consultation/' ),
		'cta_label'     => 'Book a Consultation',
		'privacy_url'   => home_url( '/privacy-policy/' ),
		'rate_limit'    => 10,
		'color_scheme'  => 'dark',
		'theme_buttons' => true,
	);
}

function hpv_grader_settings(): array {
	$saved = get_option( HPV_GRADER_OPTION, array() );

	return array_merge( hpv_grader_defaults(), is_array( $saved ) ? $saved : array() );
}

function hpv_grader_sanitize_settings( $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$defaults = hpv_grader_defaults();

	$email = sanitize_email( (string) ( $input['notify_email'] ?? '' ) );

	return array(
		'psi_key'       => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $input['psi_key'] ?? '' ) ),
		'notify_email'  => is_email( $email ) ? $email : $defaults['notify_email'],
		'from_name'     => sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ) ?: $defaults['from_name'],
		'cta_url'       => esc_url_raw( (string) ( $input['cta_url'] ?? '' ) ) ?: $defaults['cta_url'],
		'cta_label'     => sanitize_text_field( (string) ( $input['cta_label'] ?? '' ) ) ?: $defaults['cta_label'],
		'privacy_url'   => esc_url_raw( (string) ( $input['privacy_url'] ?? '' ) ) ?: $defaults['privacy_url'],
		'rate_limit'    => max( 1, min( 100, absint( $input['rate_limit'] ?? $defaults['rate_limit'] ) ) ),
		'color_scheme'  => in_array( $input['color_scheme'] ?? '', array( 'dark', 'light' ), true ) ? $input['color_scheme'] : 'dark',
		'theme_buttons' => ! empty( $input['theme_buttons'] ),
	);
}

/* ─── Lead tárolás ────────────────────────────────────────── */

add_action( 'init', 'hpv_grader_register_cpt' );

function hpv_grader_register_cpt() {
	register_post_type(
		HPV_GRADER_CPT,
		array(
			'label'               => 'Grader leads',
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title' ),
		)
	);
}

/* ─── Letöltés ────────────────────────────────────────────── */

function hpv_grader_user_agent(): string {
	return 'Mozilla/5.0 (compatible; HelloProVisionGrader/' . HPV_GRADER_VERSION . '; +' . home_url( '/' ) . ')';
}

/**
 * Biztonságos letöltés: a wp_safe_remote_get() nem enged belső hálózati címet (SSRF védelem),
 * és az átirányításokat is ellenőrzi.
 */
function hpv_grader_fetch( string $url, int $timeout = 15, int $limit = 3145728 ) {
	$start    = microtime( true );
	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => $timeout,
			'redirection'         => 5,
			'user-agent'          => hpv_grader_user_agent(),
			'limit_response_size' => $limit,
			'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8' ),
		)
	);
	$time = microtime( true ) - $start;

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$final = $url;
	if ( isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ) {
		$final = (string) $response['http_response']->get_response_object()->url;
	}

	return array(
		'code'    => (int) wp_remote_retrieve_response_code( $response ),
		'body'    => (string) wp_remote_retrieve_body( $response ),
		'headers' => hpv_grader_headers( $response ),
		'url'     => $final,
		'time'    => $time,
	);
}

/**
 * Válaszfejlécek kisbetűs kulcsokkal, ismételt fejlécnél összefűzve.
 */
function hpv_grader_headers( $response ): array {
	$headers = wp_remote_retrieve_headers( $response );
	$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

	$clean = array();
	foreach ( $headers as $key => $value ) {
		$clean[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	return $clean;
}

/**
 * Letölti és elemzi az oldalt. Hiba esetén WP_Error, amit a látogató is megért.
 */
function hpv_grader_run_scan( string $url ) {
	$page = hpv_grader_fetch( $url );

	// Ha a látogató séma nélkül írta be, és https-en nem érhető el, próbáljuk http-n.
	if ( is_wp_error( $page ) && 0 === strpos( $url, 'https://' ) ) {
		$fallback = hpv_grader_fetch( 'http://' . substr( $url, 8 ) );
		if ( ! is_wp_error( $fallback ) ) {
			$page = $fallback;
		}
	}

	if ( is_wp_error( $page ) ) {
		return new WP_Error( 'unreachable', "We couldn't reach that website. Check the address and try again.", array( 'status' => 422 ) );
	}
	if ( in_array( $page['code'], array( 401, 403, 429, 503 ), true ) ) {
		return new WP_Error( 'blocked', sprintf( 'That website blocked our scanner (HTTP %d). Security services like Cloudflare sometimes do this — contact us and we\'ll run the audit manually.', $page['code'] ), array( 'status' => 422 ) );
	}
	if ( $page['code'] >= 400 ) {
		return new WP_Error( 'http_error', sprintf( 'That page returned an error (HTTP %d). Check the address and try again.', $page['code'] ), array( 'status' => 422 ) );
	}
	$type = strtolower( (string) ( $page['headers']['content-type'] ?? 'text/html' ) );
	if ( false === strpos( $type, 'html' ) || '' === trim( $page['body'] ) ) {
		return new WP_Error( 'not_html', "That address doesn't return a web page. Enter your website's homepage address.", array( 'status' => 422 ) );
	}

	$parts  = wp_parse_url( $page['url'] );
	$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	$robots     = hpv_grader_fetch( $origin . '/robots.txt', 8, 200000 );
	$robots_ctx = array( 'found' => false );
	if ( ! is_wp_error( $robots ) && 200 === $robots['code'] && false !== stripos( $robots['body'], 'user-agent' ) ) {
		$robots_ctx = array(
			'found' => true,
			'body'  => $robots['body'],
		);
	}

	$sitemap_urls = array();
	if ( $robots_ctx['found'] && preg_match_all( '/^\s*sitemap:\s*(\S+)/im', $robots_ctx['body'], $m ) ) {
		$sitemap_urls = array_slice( $m[1], 0, 2 );
	}
	$sitemap_urls = array_merge( $sitemap_urls, array( $origin . '/sitemap_index.xml', $origin . '/sitemap.xml', $origin . '/wp-sitemap.xml' ) );
	$sitemap      = false;
	foreach ( array_unique( $sitemap_urls ) as $sitemap_url ) {
		$res = hpv_grader_fetch( $sitemap_url, 8, 100000 );
		if ( ! is_wp_error( $res ) && 200 === $res['code'] && preg_match( '/<(urlset|sitemapindex)\b/i', $res['body'] ) ) {
			$sitemap = true;
			break;
		}
	}

	$checks = hpv_grader_analyze_page(
		$page['body'],
		array(
			'url'     => $page['url'],
			'headers' => $page['headers'],
			'time'    => $page['time'],
			'robots'  => $robots_ctx,
			'sitemap' => $sitemap,
		)
	);

	return hpv_grader_score_report(
		array(
			'id'         => strtolower( wp_generate_password( 20, false, false ) ),
			'url'        => $page['url'],
			'host'       => $parts['host'],
			'scanned_at' => time(),
			'psi_status' => 'pending',
			'checks'     => $checks,
		)
	);
}

/**
 * Google PageSpeed Insights mobil mérés. Üres tömb, ha nem sikerült.
 */
function hpv_grader_run_psi( string $url ): array {
	$settings = hpv_grader_settings();
	$query    = array(
		'url'      => $url,
		'strategy' => 'mobile',
		'category' => 'performance',
	);
	if ( '' !== $settings['psi_key'] ) {
		$query['key'] = $settings['psi_key'];
	}

	$response = wp_remote_get( add_query_arg( $query, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' ), array( 'timeout' => 70 ) );
	$data     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

	return is_array( $data ) ? hpv_grader_psi_checks( $data ) : array();
}

/* ─── Tárolás, korlátozás ─────────────────────────────────── */

function hpv_grader_get_report( string $id ) {
	if ( ! preg_match( '/^[a-z0-9]{20}$/', $id ) ) {
		return null;
	}
	$report = get_transient( 'hpv_g_scan_' . $id );

	return is_array( $report ) ? $report : null;
}

function hpv_grader_save_report( array $report ) {
	set_transient( 'hpv_g_scan_' . $report['id'], $report, HPV_GRADER_TTL );
}

function hpv_grader_client_ip(): string {
	return (string) apply_filters( 'hpv_grader_client_ip', $_SERVER['REMOTE_ADDR'] ?? '' );
}

/**
 * Óránként legfeljebb N kérés IP-címenként (a PageSpeed kvóta, a szerver és a levélküldés védelmében).
 */
function hpv_grader_rate_limited( string $bucket, int $limit ): bool {
	$key   = 'hpv_g_rl_' . $bucket . '_' . md5( hpv_grader_client_ip() );
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return true;
	}
	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	return false;
}

/* ─── REST API ────────────────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_grader_register_routes' );

function hpv_grader_register_routes() {
	register_rest_route(
		HPV_GRADER_NS,
		'/scan',
		array(
			'methods'             => 'POST',
			'callback'            => 'hpv_grader_rest_scan',
			'permission_callback' => '__return_true',
			'args'                => array(
				'url' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
	register_rest_route(
		HPV_GRADER_NS,
		'/speed',
		array(
			'methods'             => 'POST',
			'callback'            => 'hpv_grader_rest_speed',
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		)
	);
	register_rest_route(
		HPV_GRADER_NS,
		'/unlock',
		array(
			'methods'             => 'POST',
			'callback'            => 'hpv_grader_rest_unlock',
			'permission_callback' => '__return_true',
		)
	);
}

function hpv_grader_rest_scan( WP_REST_Request $request ) {
	$url = hpv_grader_normalize_url( (string) $request->get_param( 'url' ) );
	if ( '' === $url ) {
		return new WP_Error( 'invalid_url', 'Enter a valid website address, like yourbusiness.com.', array( 'status' => 400 ) );
	}

	// Ugyanazt az oldalt egy órán belül nem elemezzük újra (és nem számít bele a korlátba).
	$cache_key = 'hpv_g_url_' . md5( $url );
	$cached    = hpv_grader_get_report( (string) get_transient( $cache_key ) );
	if ( $cached && time() - $cached['scanned_at'] < HOUR_IN_SECONDS ) {
		// Minden látogató saját riport-példányt kap, hogy az érdeklődők adatai ne keveredjenek.
		unset( $cached['lead_id'], $cached['unlock_token'], $cached['email_pending'] );
		$cached['id'] = strtolower( wp_generate_password( 20, false, false ) );
		hpv_grader_save_report( $cached );
		return rest_ensure_response( hpv_grader_public_report( $cached, false ) );
	}

	if ( hpv_grader_rate_limited( 'scan', (int) hpv_grader_settings()['rate_limit'] ) ) {
		return new WP_Error( 'rate_limited', "You've run a lot of audits in the last hour. Please try again a little later.", array( 'status' => 429 ) );
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$report = hpv_grader_run_scan( $url );
	if ( is_wp_error( $report ) ) {
		return $report;
	}

	hpv_grader_save_report( $report );
	set_transient( $cache_key, $report['id'], HOUR_IN_SECONDS );

	return rest_ensure_response( hpv_grader_public_report( $report, false ) );
}

function hpv_grader_rest_speed( WP_REST_Request $request ) {
	$report = hpv_grader_get_report( (string) $request->get_param( 'id' ) );
	if ( ! $report ) {
		return new WP_Error( 'not_found', 'This report has expired. Please run the audit again.', array( 'status' => 404 ) );
	}

	if ( 'pending' === $report['psi_status'] ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$psi = hpv_grader_run_psi( $report['url'] );

		// A mérés alatt a látogató feloldhatta a riportot — a legfrissebb változatba írjuk be.
		$report = hpv_grader_get_report( $report['id'] ) ?: $report;
		if ( 'pending' === $report['psi_status'] ) {
			$report = hpv_grader_apply_psi( $report, $psi );
			hpv_grader_save_report( $report );
			hpv_grader_update_lead_report( $report );
		}
		hpv_grader_send_pending_emails( $report['id'] );
	}

	return rest_ensure_response( hpv_grader_public_report( $report, hpv_grader_unlocked_by_request( $request, $report ) ) );
}

/**
 * Feloldás után a böngésző a kapott tokennel kéri le a teljes riportot (pl. ha a sebességmérés később ér véget).
 */
function hpv_grader_unlocked_by_request( WP_REST_Request $request, array $report ): bool {
	$token = (string) $request->get_param( 'token' );

	return '' !== $token && ! empty( $report['unlock_token'] ) && hash_equals( $report['unlock_token'], $token );
}

function hpv_grader_rest_unlock( WP_REST_Request $request ) {
	$report = hpv_grader_get_report( (string) $request->get_param( 'id' ) );
	if ( ! $report ) {
		return new WP_Error( 'not_found', 'This report has expired. Please run the audit again.', array( 'status' => 404 ) );
	}

	// Csapda mező: ember nem tölti ki.
	if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
		return new WP_Error( 'spam', 'Something went wrong. Please try again.', array( 'status' => 400 ) );
	}

	$name     = sanitize_text_field( (string) $request->get_param( 'name' ) );
	$email    = sanitize_email( (string) $request->get_param( 'email' ) );
	$business = sanitize_text_field( (string) $request->get_param( 'business' ) );
	if ( '' === $name || ! is_email( $email ) ) {
		return new WP_Error( 'invalid', 'Please enter your name and a valid email address.', array( 'status' => 400 ) );
	}
	if ( ! $request->get_param( 'consent' ) ) {
		return new WP_Error( 'consent', 'Please accept the Privacy Policy to receive your report.', array( 'status' => 400 ) );
	}

	if ( hpv_grader_rate_limited( 'unlock', 5 ) ) {
		return new WP_Error( 'rate_limited', 'Too many requests. Please try again a little later.', array( 'status' => 429 ) );
	}

	$lead_id = (int) ( $report['lead_id'] ?? 0 );
	if ( ! $lead_id ) {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => HPV_GRADER_CPT,
				'post_status' => 'private',
				'post_title'  => $name,
			),
			true
		);
		if ( is_wp_error( $lead_id ) ) {
			return new WP_Error( 'error', 'Something went wrong. Please try again.', array( 'status' => 500 ) );
		}
	} else {
		wp_update_post(
			array(
				'ID'         => $lead_id,
				'post_title' => $name,
			)
		);
	}
	update_post_meta( $lead_id, '_hpv_email', strtolower( $email ) );
	update_post_meta( $lead_id, '_hpv_business', $business );
	update_post_meta( $lead_id, '_hpv_consent_at', time() );

	// Közben lefuthatott a sebességmérés — a legfrissebb riportot frissítjük.
	$report                  = hpv_grader_get_report( $report['id'] ) ?: $report;
	$report['lead_id']       = $lead_id;
	$report['unlock_token']  = $report['unlock_token'] ?? strtolower( wp_generate_password( 24, false, false ) );
	$report['email_pending'] = true;
	hpv_grader_save_report( $report );
	hpv_grader_update_lead_report( $report );

	if ( 'pending' === $report['psi_status'] ) {
		// A levél a sebességméréssel együtt megy ki; ha az elakad, legkésőbb 3 perc múlva.
		wp_schedule_single_event( time() + 3 * MINUTE_IN_SECONDS, 'hpv_grader_deferred_email', array( $report['id'] ) );
	} else {
		hpv_grader_send_pending_emails( $report['id'] );
	}

	$public          = hpv_grader_public_report( $report, true );
	$public['token'] = $report['unlock_token'];

	return rest_ensure_response( $public );
}

add_action( 'hpv_grader_deferred_email', 'hpv_grader_send_pending_emails' );

/**
 * Elküldi a riportot és az értesítést, ha még nem ment ki (egyszer).
 */
function hpv_grader_send_pending_emails( string $id ) {
	$report = hpv_grader_get_report( $id );
	if ( ! $report || empty( $report['email_pending'] ) || empty( $report['lead_id'] ) ) {
		return;
	}

	unset( $report['email_pending'] );
	hpv_grader_save_report( $report );

	$lead_id = (int) $report['lead_id'];
	$lead    = array(
		'name'     => (string) get_post_field( 'post_title', $lead_id ),
		'email'    => (string) get_post_meta( $lead_id, '_hpv_email', true ),
		'business' => (string) get_post_meta( $lead_id, '_hpv_business', true ),
	);

	hpv_grader_send_report_email( $report, $lead );
	hpv_grader_send_notification( $report, $lead, $lead_id );
	update_post_meta( $lead_id, '_hpv_emailed_at', time() );
}

function hpv_grader_update_lead_report( array $report ) {
	if ( empty( $report['lead_id'] ) ) {
		return;
	}
	$id = (int) $report['lead_id'];
	update_post_meta( $id, '_hpv_url', $report['url'] );
	update_post_meta( $id, '_hpv_overall', $report['overall'] );
	update_post_meta( $id, '_hpv_report', wp_slash( wp_json_encode( $report ) ) );
}

/* ─── E-mailek ────────────────────────────────────────────── */

function hpv_grader_status_label( string $status ): array {
	return array(
		'pass' => array( '✓', '#2e7d32', 'Passed' ),
		'warn' => array( '!', '#b26a00', 'Needs attention' ),
		'fail' => array( '✕', '#c62828', 'Failed' ),
	)[ $status ];
}

function hpv_grader_email_html( array $report, array $lead ): string {
	$settings = hpv_grader_settings();
	$first    = explode( ' ', trim( $lead['name'] ) )[0];

	ob_start();
	?>
	<div style="background:#f4f4f1;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#111">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden">
		<tr><td style="background:#0d0d0d;padding:28px 32px;color:#fff">
			<div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#B8FF34">Website &amp; Local SEO Report</div>
			<div style="font-size:24px;margin-top:8px"><?php echo esc_html( $report['host'] ); ?></div>
			<div style="font-size:56px;line-height:1;margin-top:20px;color:#B8FF34;font-weight:bold"><?php echo (int) $report['overall']; ?><span style="font-size:20px;color:#fff"> / 100</span></div>
			<div style="font-size:16px;margin-top:6px"><?php echo esc_html( $report['grade'] ); ?></div>
		</td></tr>
		<tr><td style="padding:24px 32px">
			<p style="font-size:16px;line-height:1.5;margin:0 0 16px">Hi <?php echo esc_html( $first ); ?>, here is the full audit of <strong><?php echo esc_html( $report['url'] ); ?></strong>, with a step-by-step fix for every issue we found.</p>
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:15px">
			<?php foreach ( $report['categories'] as $cat ) : ?>
				<tr>
					<td style="padding:8px 0;border-bottom:1px solid #eee"><?php echo esc_html( $cat['label'] ); ?></td>
					<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;font-weight:bold"><?php echo null === $cat['score'] ? 'not measured' : (int) $cat['score'] . ' / 100'; ?></td>
				</tr>
			<?php endforeach; ?>
			</table>
		</td></tr>
		<?php foreach ( HPV_GRADER_CATEGORIES as $key => $cat ) : ?>
			<?php $issues = array_filter( $report['checks'], fn( $c ) => $c['category'] === $key && 'pass' !== $c['status'] ); ?>
			<?php if ( ! $issues ) { continue; } ?>
			<tr><td style="padding:8px 32px 0">
				<h2 style="font-size:18px;margin:16px 0 8px;border-top:2px solid #111;padding-top:16px"><?php echo esc_html( $cat['label'] ); ?></h2>
				<?php foreach ( $issues as $check ) : ?>
					<?php list( $icon, $color, $label ) = hpv_grader_status_label( $check['status'] ); ?>
					<div style="margin:0 0 16px">
						<div style="font-size:15px;font-weight:bold"><span style="color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $icon ); ?></span> <?php echo esc_html( $check['title'] ); ?> <span style="font-weight:normal;color:<?php echo esc_attr( $color ); ?>;font-size:13px">— <?php echo esc_html( $label ); ?></span></div>
						<div style="font-size:14px;color:#555;margin-top:4px"><?php echo esc_html( $check['detail'] ); ?></div>
						<div style="font-size:14px;line-height:1.5;margin-top:6px;background:#f7f7f4;border-left:3px solid #B8FF34;padding:8px 12px"><strong>How to fix:</strong> <?php echo esc_html( $check['fix'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</td></tr>
		<?php endforeach; ?>
		<?php if ( 'done' !== $report['psi_status'] ) : ?>
			<tr><td style="padding:0 32px;font-size:13px;color:#777">Google's speed test did not finish in time for this email. You can re-run the audit on our website for the speed results.</td></tr>
		<?php endif; ?>
		<tr><td style="padding:24px 32px 32px">
			<div style="background:#0d0d0d;border-radius:10px;padding:24px;color:#fff">
				<div style="font-size:18px;font-weight:bold">Want these fixed for you?</div>
				<div style="font-size:14px;line-height:1.5;margin:8px 0 16px;color:#ddd">We help Fort Myers, Cape Coral and Naples businesses turn audits like this into more calls and quote requests.</div>
				<a href="<?php echo esc_url( $settings['cta_url'] ); ?>" style="display:inline-block;background:#B8FF34;color:#0d0d0d;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px"><?php echo esc_html( $settings['cta_label'] ); ?></a>
			</div>
			<p style="font-size:12px;color:#888;margin-top:16px">Sent by <?php echo esc_html( $settings['from_name'] ); ?> because you requested this report at <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>. Reply to this email if you have questions.</p>
		</td></tr>
	</table>
	</div>
	<?php
	return (string) ob_get_clean();
}

function hpv_grader_send_report_email( array $report, array $lead ): bool {
	$settings  = hpv_grader_settings();
	$from_name = function () use ( $settings ) {
		return $settings['from_name'];
	};
	add_filter( 'wp_mail_from_name', $from_name );

	$sent = wp_mail(
		$lead['email'],
		sprintf( 'Your website report for %s: %d/100', $report['host'], $report['overall'] ),
		hpv_grader_email_html( $report, $lead ),
		array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . $settings['notify_email'] )
	);

	remove_filter( 'wp_mail_from_name', $from_name );

	return (bool) $sent;
}

function hpv_grader_send_notification( array $report, array $lead, int $lead_id ) {
	$settings = hpv_grader_settings();
	$issues   = array_filter( $report['checks'], fn( $c ) => 'fail' === $c['status'] );

	$body  = "Új érdeklődő a Website Graderből:\n\n";
	$body .= 'Név: ' . $lead['name'] . "\n";
	$body .= 'E-mail: ' . $lead['email'] . "\n";
	$body .= 'Cég: ' . ( $lead['business'] ?: '—' ) . "\n";
	$body .= 'Weboldal: ' . $report['url'] . "\n";
	$body .= 'Pontszám: ' . $report['overall'] . '/100 (' . $report['grade'] . ")\n\n";
	$body .= "Súlyos hibák:\n";
	foreach ( $issues as $check ) {
		$body .= '- ' . $check['title'] . ': ' . $check['detail'] . "\n";
	}
	if ( ! $issues ) {
		$body .= "- nincs\n";
	}
	$body .= "\nTeljes riport: " . admin_url( 'admin.php?page=' . HPV_GRADER_PAGE . '&lead=' . $lead_id ) . "\n";

	wp_mail( $settings['notify_email'], sprintf( 'Új lead: %s (%d/100)', $report['host'], $report['overall'] ), $body, array( 'Reply-To: ' . $lead['email'] ) );
}

/* ─── Frontend ────────────────────────────────────────────── */

add_shortcode( 'hpv_grader', 'hpv_grader_shortcode' );

/**
 * [hpv_grader heading="h1" title="…" subtitle="…"]
 */
function hpv_grader_shortcode( $atts ): string {
	$atts = shortcode_atts(
		array(
			'heading'  => 'h1',
			'title'    => 'How does your website stack up in Southwest Florida?',
			'subtitle' => 'Free instant audit of your speed, mobile experience, SEO basics, schema markup and local business signals — built for businesses in Fort Myers, Cape Coral and Naples.',
		),
		$atts,
		'hpv_grader'
	);
	$heading  = in_array( $atts['heading'], array( 'h1', 'h2' ), true ) ? $atts['heading'] : 'h1';
	$settings = hpv_grader_settings();

	wp_enqueue_style( 'hpv-grader', plugins_url( 'assets/grader.css', __FILE__ ), array(), HPV_GRADER_VERSION );
	wp_enqueue_script( 'hpv-grader', plugins_url( 'assets/grader.js', __FILE__ ), array(), HPV_GRADER_VERSION, true );
	wp_add_inline_script(
		'hpv-grader',
		'window.HPV_GRADER = ' . wp_json_encode(
			array(
				'api'        => esc_url_raw( rest_url( HPV_GRADER_NS ) ),
				'ctaUrl'     => $settings['cta_url'],
				'ctaLabel'   => $settings['cta_label'],
				'privacyUrl' => $settings['privacy_url'],
				'categories' => array_map( fn( $c ) => $c['label'], HPV_GRADER_CATEGORIES ),
			)
		) . ';',
		'before'
	);

	$classes = 'hpv-grader hpv-grader--' . $settings['color_scheme'];
	$button  = $settings['theme_buttons'] ? 'btn-pill hpv-g-btn' : 'hpv-g-btn hpv-g-btn--own';

	ob_start();
	include __DIR__ . '/includes/template.php';

	return (string) ob_get_clean();
}

/**
 * WebApplication schema az eszköz oldalán (ingyenes eszközként jelenhet meg a Google-ben).
 */
add_action( 'wp_head', 'hpv_grader_schema' );

function hpv_grader_schema() {
	if ( ! is_singular() || ! has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'hpv_grader' ) ) {
		return;
	}

	$data = array(
		'@context'            => 'https://schema.org',
		'@type'               => 'WebApplication',
		'name'                => 'Southwest Florida Website & Local SEO Grader',
		'url'                 => get_permalink(),
		'applicationCategory' => 'BusinessApplication',
		'operatingSystem'     => 'Any',
		'description'         => 'Free audit of website speed, mobile experience, SEO basics, schema markup and local business signals for businesses in Fort Myers, Cape Coral and Naples, Florida.',
		'offers'              => array(
			'@type'         => 'Offer',
			'price'         => '0',
			'priceCurrency' => 'USD',
		),
		'provider'            => array(
			'@type' => 'Organization',
			'name'  => 'HelloProVision',
			'url'   => home_url( '/' ),
		),
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) . "</script>\n";
}

/* ─── Admin ───────────────────────────────────────────────── */

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}
