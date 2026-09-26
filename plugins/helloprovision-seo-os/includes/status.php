<?php
/**
 * Super admin – rendszerállapot a WordPress oldaláról (titok, cím, SSL, cron, levélküldés, CRM / portál), kiegészítve
 * az API saját állapotával. Csak az SEO OS Admin szerepkörnek.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'hpv_seo_status_routes', 10 );

function hpv_seo_is_admin(): bool {
	return 'admin' === hpv_seo_user_role();
}

function hpv_seo_status_routes() {
	$admin = fn() => hpv_seo_is_admin();
	register_rest_route( 'hpv-seo/v1', '/wp/status', array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => 'hpv_seo_wp_status' ) );
	register_rest_route( 'hpv-seo/v1', '/wp/test-email', array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => 'hpv_seo_wp_test_email' ) );
	register_rest_route( 'hpv-seo/v1', '/wp/run-cron', array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => 'hpv_seo_wp_run_cron' ) );
}

function hpv_seo_wp_status() {
	$secret_const = defined( 'HPV_SEO_OS_SECRET' );
	$secret       = hpv_seo_secret();
	$host         = hpv_seo_host();
	$request_host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
	$started      = microtime( true );
	$health       = wp_remote_get( hpv_seo_api_url() . '/health', array( 'timeout' => 5 ) );
	$api_ok       = ! is_wp_error( $health ) && 200 === wp_remote_retrieve_response_code( $health );
	$api_ms       = (int) round( ( microtime( true ) - $started ) * 1000 );
	$roles        = array();
	foreach ( get_users( array( 'fields' => array( 'ID' ) ) ) as $u ) {
		$r = hpv_seo_user_role( get_user_by( 'id', $u->ID ) );
		if ( '' !== $r ) {
			$roles[ $r ] = ( $roles[ $r ] ?? 0 ) + 1;
		}
	}
	$smtp   = function_exists( 'wp_mail_smtp' ) || defined( 'WPMS_PLUGIN_VER' );
	$portal = function_exists( 'hpv_p_notify_client' );
	$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

	$items = array();
	$add   = function ( $key, $label, $ok, $detail = '', $fix = '', $level = 'error' ) use ( &$items ) {
		$items[] = array( 'key' => $key, 'label' => $label, 'status' => $ok ? 'ok' : $level, 'detail' => $detail, 'fix' => $ok ? '' : $fix );
	};
	$add( 'wp_secret', 'Közös titok beállítva (wp-config.php)', strlen( $secret ) >= 32 && $secret_const, $secret_const ? 'HPV_SEO_OS_SECRET konstans' : ( $secret ? 'az admin beállításban (a wp-config.php biztonságosabb)' : 'nincs' ),
		"define( 'HPV_SEO_OS_SECRET', '…' ); a wp-config.php-ba – ugyanaz, mint a szerver SEO_OS_HMAC_SECRET értéke.", $secret ? 'warn' : 'error' );
	$add( 'wp_api', 'Az API elérhető a WordPress szerverről', $api_ok, hpv_seo_api_url() . ( $api_ok ? " ({$api_ms} ms)" : '' ), "Ellenőrizd a HPV_SEO_OS_API_URL-t és hogy fut-e az API (docker compose ps)." );
	$add( 'wp_ssl', 'HTTPS az SEO OS címen', is_ssl() || ( defined( 'HPV_FORCE_HTTPS' ) && HPV_FORCE_HTTPS ), $request_host, 'SSL tanúsítvány a seo aldomainre (pl. Let\'s Encrypt).', 'warn' );
	$add( 'wp_host', 'A felület a beállított aldomainen fut', hpv_seo_host_matches( $request_host, $host ), $host, "A seo aldomain kerüljön a wp-config.php host-listájába (WP_HOME), vagy define( 'HPV_SEO_HOST', '…' ).", 'warn' );
	$add( 'wp_smtp', 'Levélküldés SMTP-n (WP Mail SMTP)', $smtp, '', 'Telepítsd / állítsd be a WP Mail SMTP bővítményt, különben a levelek spambe mehetnek.', 'warn' );
	$add( 'wp_portal', 'CRM / ügyfélportál bővítmény aktív', $portal, '', 'A CRM feladatokhoz és az ügyfél-jóváhagyás portál-értesítéseihez a helloprovision-portal bővítmény kell.', 'warn' );
	$add( 'wp_cron_real', 'Valódi cron (nem látogatásfüggő WP-Cron)', $cron_disabled, $cron_disabled ? 'DISABLE_WP_CRON' : 'WP-Cron csak látogatáskor fut', "define( 'DISABLE_WP_CRON', true ); + szerver cron: */5 * * * * curl -s https://{$host}/wp-cron.php", 'warn' );
	$add( 'wp_team', 'Van SEO OS szerepkörrel rendelkező munkatárs az adminon kívül', count( $roles ) > 1, wp_json_encode( $roles ), 'Beállítások → Csapat: szerepkörök kiosztása.', 'warn' );

	$api = $api_ok ? hpv_seo_api_json( 'GET', 'admin/status' ) : new WP_Error( 'hpv_seo_api', 'Az API nem érhető el.' );

	return rest_ensure_response(
		array(
			'wp'        => array(
				'plugin_version' => HPV_SEO_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'host'           => $host,
				'request_host'   => $request_host,
				'api_url'        => hpv_seo_api_url(),
				'secret_source'  => $secret_const ? 'wp-config.php' : ( $secret ? 'admin beállítás' : '' ),
				'cron_disabled'  => $cron_disabled,
				'next_outbox'    => wp_next_scheduled( 'hpv_seo_outbox' ) ? gmdate( 'c', wp_next_scheduled( 'hpv_seo_outbox' ) ) : null,
				'next_daily'     => wp_next_scheduled( 'hpv_seo_daily' ) ? gmdate( 'c', wp_next_scheduled( 'hpv_seo_daily' ) ) : null,
				'roles'          => $roles,
				'admin_email'    => wp_get_current_user()->user_email,
			),
			'checklist' => $items,
			'api'       => is_wp_error( $api ) ? array( 'error' => $api->get_error_message() ) : $api,
		)
	);
}

function hpv_seo_wp_test_email() {
	$user = wp_get_current_user();
	$html = hpv_seo_mail_html( 'Teszt levél az SEO OS-ből', "Ha ezt látod, a levélküldés működik.\n" . gmdate( 'Y-m-d H:i' ) . ' UTC', 'SEO OS megnyitása', hpv_seo_app_url() );
	$ok   = wp_mail( $user->user_email, '[SEO OS] Teszt levél', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	return rest_ensure_response( array( 'ok' => (bool) $ok, 'to' => $user->user_email ) );
}

function hpv_seo_wp_run_cron() {
	$out   = hpv_seo_send_outbox();
	$daily = hpv_seo_api_json( 'POST', 'system/daily', array(), HPV_SEO_SYSTEM );
	return rest_ensure_response( array( 'outbox' => $out, 'daily' => is_wp_error( $daily ) ? $daily->get_error_message() : $daily ) );
}
