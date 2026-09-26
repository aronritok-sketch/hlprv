<?php
/**
 * Az alkalmazás a seo.helloprovision.com címen (a wp-config.php-ban HPV_SEO_HOST-tal átírható).
 * Fejlesztői gépen a /seo-os/ útvonalon is elérhető.
 */

defined( 'ABSPATH' ) || exit;

// A CRM bővítmény aldomain-kezelője (1-es prioritás) előtt fusson.
add_action( 'template_redirect', 'hpv_seo_route', 0 );

function hpv_seo_is_app_request(): bool {
	if ( hpv_seo_host_matches( (string) ( $_SERVER['HTTP_HOST'] ?? '' ), hpv_seo_host() ) ) {
		return true;
	}
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

	return '/seo-os' === untrailingslashit( $path );
}

function hpv_seo_route() {
	if ( ! hpv_seo_is_app_request() ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	if ( ! hpv_seo_can_access() ) {
		status_header( 403 );
		nocache_headers();
		wp_die( 'Az SEO OS-hez nincs hozzáférésed. Kérd meg az adminisztrátort, hogy adjon SEO OS szerepkört.', 'SEO OS', array( 'response' => 403 ) );
	}
	hpv_seo_render_app();
	exit;
}

/**
 * Bejelentkezés után az seo aldomainről érkezőt vissza oda.
 */
add_filter(
	'login_redirect',
	function ( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && hpv_seo_host_matches( (string) ( $_SERVER['HTTP_HOST'] ?? '' ), hpv_seo_host() ) && '' !== hpv_seo_user_role( $user ) ) {
			return hpv_seo_app_url();
		}
		return $redirect_to;
	},
	20,
	3
);

function hpv_seo_render_app() {
	$base    = plugins_url( 'assets/app/', HPV_SEO_FILE );
	$version = HPV_SEO_VERSION;
	$user    = wp_get_current_user();
	$config  = array(
		'rest'      => esc_url_raw( rest_url( 'hpv-seo/v1' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'logoutUrl' => wp_logout_url( hpv_seo_app_url() ),
		'profileUrl'=> admin_url( 'profile.php' ),
		'usersUrl'  => admin_url( 'users.php' ),
		'crmUrl'    => function_exists( 'hpv_p_crm_host' ) ? 'https://' . hpv_p_crm_host() . '/' : '',
		'user'      => array(
			'name'   => $user->display_name,
			'role'   => hpv_seo_user_role( $user ),
			'avatar' => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
		),
	);
	status_header( 200 );
	nocache_headers();
	?>
<!doctype html>
<html lang="hu">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="color-scheme" content="dark">
	<title>HelloProVision SEO OS</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( $base . 'app.css?ver=' . $version ); ?>">
	<script>window.HPV_SEO = <?php echo wp_json_encode( $config ); ?>;</script>
</head>
<body class="seo-body">
	<div id="app"></div>
	<noscript>Az SEO OS-hez JavaScript szükséges.</noscript>
	<script type="module" src="<?php echo esc_url( $base . 'app.js?ver=' . $version ); ?>"></script>
</body>
</html>
	<?php
}
