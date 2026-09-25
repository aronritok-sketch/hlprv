<?php
/**
 * Aldomainek: egy WordPress telepítés, két bejárat.
 *   crm.helloprovision.com     → a csapat CRM-je (bejelentkezés után)
 *   clients.helloprovision.com → ügyfélportál (bejelentkezés után)
 * A címek a wp-config.php-ban felülírhatók: HPV_CRM_HOST, HPV_PORTAL_HOST.
 * A wp-config.php-ban a WP_HOME / WP_SITEURL-t a kérés címéhez kell igazítani (README).
 */

defined( 'ABSPATH' ) || exit;

function hpv_p_crm_host(): string {
	return strtolower( defined( 'HPV_CRM_HOST' ) ? HPV_CRM_HOST : 'crm.helloprovision.com' );
}

function hpv_p_portal_host(): string {
	return strtolower( defined( 'HPV_PORTAL_HOST' ) ? HPV_PORTAL_HOST : 'clients.helloprovision.com' );
}

function hpv_p_request_host(): string {
	return strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
}

/**
 * 'crm' | 'portal' | '' (más cím, pl. fejlesztői gép – ott a [hpv_portal] shortcode működik).
 */
function hpv_p_context(): string {
	$host = hpv_p_request_host();
	if ( hpv_p_crm_host() === $host ) {
		return 'crm';
	}
	if ( hpv_p_portal_host() === $host ) {
		return 'portal';
	}

	return '';
}

function hpv_p_scheme(): string {
	return is_ssl() || ( defined( 'HPV_FORCE_HTTPS' ) && HPV_FORCE_HTTPS ) ? 'https' : 'http';
}

function hpv_p_crm_url( array $args = array() ): string {
	$base = hpv_p_scheme() . '://' . hpv_p_crm_host() . '/wp-admin/admin.php';

	return add_query_arg( array_merge( array( 'page' => 'hpv-crm' ), $args ), $base );
}

/**
 * A portál címe. Aldomainen a gyökér; fejlesztéskor a [hpv_portal] oldal.
 */
function hpv_p_portal_url( array $args = array() ): string {
	if ( 'crm' === hpv_p_context() || 'portal' === hpv_p_context() || ! empty( hpv_p_settings()['use_subdomains'] ) ) {
		$base = hpv_p_scheme() . '://' . hpv_p_portal_host() . '/';
	} else {
		$page = (int) hpv_p_settings()['portal_page_id'];
		$base = $page ? get_permalink( $page ) : home_url( '/portal/' );
	}

	return $args ? add_query_arg( $args, $base ) : $base;
}

add_action( 'template_redirect', 'hpv_p_route_subdomains', 1 );

function hpv_p_route_subdomains() {
	$context = hpv_p_context();

	if ( 'crm' === $context ) {
		// A CRM a bejelentkezett munkatársaké; a nyitóoldal a CRM.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		if ( hpv_p_is_staff() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=hpv-crm' ) );
		} else {
			wp_redirect( hpv_p_portal_url() ); // phpcs:ignore WordPress.Security.SafeRedirect -- a saját portál aldomain
		}
		exit;
	}

	if ( 'portal' === $context ) {
		// A portál aldomain minden oldala a portál (téma nélkül, saját keretben).
		status_header( 200 );
		nocache_headers();
		hpv_p_render_portal_document();
		exit;
	}
}

/**
 * A portál aldomainen a WordPress admin nem érhető el senkinek (a munkatársak a CRM aldomainen dolgoznak).
 * A CRM aldomainen az ügyfél-felhasználót a portálra küldjük.
 */
add_action( 'admin_init', 'hpv_p_guard_admin', 1 );

function hpv_p_guard_admin() {
	if ( wp_doing_ajax() || ! is_user_logged_in() ) {
		return;
	}
	$is_client = in_array( 'hpv_client', (array) wp_get_current_user()->roles, true ) && ! hpv_p_is_staff();

	if ( $is_client || 'portal' === hpv_p_context() ) {
		wp_redirect( hpv_p_portal_url() ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
}

/**
 * Munkatárs (nem adminisztrátor) csak a CRM-et és a profilját látja az adminban.
 */
add_action( 'admin_menu', 'hpv_p_trim_staff_menu', 999 );

function hpv_p_trim_staff_menu() {
	if ( current_user_can( 'manage_options' ) || ! hpv_p_is_staff() ) {
		return;
	}
	foreach ( array( 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'tools.php' ) as $slug ) {
		remove_menu_page( $slug );
	}
}

add_action( 'load-index.php', 'hpv_p_staff_dashboard_redirect' );

function hpv_p_staff_dashboard_redirect() {
	if ( hpv_p_is_staff() && ( ! current_user_can( 'manage_options' ) || 'crm' === hpv_p_context() ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=hpv-crm' ) );
		exit;
	}
}

/**
 * Bejelentkezés után: ügyfél → portál, munkatárs → CRM.
 */
add_filter( 'login_redirect', 'hpv_p_login_redirect', 10, 3 );

function hpv_p_login_redirect( $redirect_to, $requested, $user ) {
	if ( ! $user instanceof WP_User ) {
		return $redirect_to;
	}
	if ( in_array( 'hpv_client', (array) $user->roles, true ) && ! user_can( $user, 'hpv_manage_crm' ) ) {
		return hpv_p_portal_url();
	}
	if ( user_can( $user, 'hpv_manage_crm' ) && ( empty( $requested ) || false !== strpos( (string) $requested, 'wp-admin/' ) && false === strpos( (string) $requested, 'page=' ) ) ) {
		return admin_url( 'admin.php?page=hpv-crm' );
	}

	return $redirect_to;
}

add_filter( 'show_admin_bar', 'hpv_p_hide_admin_bar' );

function hpv_p_hide_admin_bar( $show ) {
	if ( is_user_logged_in() && ( 'portal' === hpv_p_context() || in_array( 'hpv_client', (array) wp_get_current_user()->roles, true ) ) ) {
		return false;
	}

	return $show;
}

/* ─── Márkázott bejelentkezés ─────────────────────────────── */

add_action( 'login_enqueue_scripts', 'hpv_p_login_style' );

function hpv_p_login_style() {
	?>
	<style>
		body.login { background: #0b0b0b; }
		body.login #login h1 a { background: none; width: auto; height: auto; text-indent: 0; font-size: 26px; font-weight: 700; color: #fff; letter-spacing: -.02em; }
		body.login #login h1 a span { color: #b8ff34; }
		body.login form { background: #151515; border: 1px solid #2a2a2a; border-radius: 16px; box-shadow: none; }
		body.login label, body.login .forgetmenot label { color: #ddd; }
		body.login input[type=text], body.login input[type=password], body.login input[type=email] { background: #0f0f0f; border-color: #333; color: #fff; border-radius: 10px; }
		body.login .button-primary { background: #b8ff34; border-color: #b8ff34; color: #0d0d0d; font-weight: 600; border-radius: 999px; text-shadow: none; box-shadow: none; }
		body.login .button-primary:hover, body.login .button-primary:focus { background: #a6ee1f; border-color: #a6ee1f; color: #0d0d0d; }
		body.login #nav a, body.login #backtoblog a, body.login .privacy-policy-page-link a { color: #aaa; }
		body.login .message, body.login .notice { border-left-color: #b8ff34; background: #151515; color: #ddd; }
		body.login #login_error { background: #1d1212; color: #ffb4ab; border-left-color: #ff6b5e; }
		body.login .language-switcher { display: none; }
	</style>
	<?php
}

add_filter( 'login_headerurl', fn() => home_url( '/' ) );
add_filter( 'login_headertext', 'hpv_p_login_header' );

function hpv_p_login_header() {
	$label = 'crm' === hpv_p_context() ? 'CRM' : 'Client Portal';

	return 'HelloProVision <span>' . $label . '</span>';
}

/**
 * A WordPress a login fejlécben escapeli a szöveget; a <span>-t a kimenetben állítjuk vissza.
 */
add_action( 'login_header', fn() => ob_start() );
add_action( 'login_footer', 'hpv_p_login_header_markup', 0 );

function hpv_p_login_header_markup() {
	$html = (string) ob_get_clean();
	echo str_replace( array( '&lt;span&gt;', '&lt;/span&gt;' ), array( '<span>', '</span>' ), $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- csak a saját span visszaállítása
}
