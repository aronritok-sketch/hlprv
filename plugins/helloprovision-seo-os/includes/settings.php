<?php
/**
 * Beállítások: API cím, közös titok, aldomain. A titok a wp-config.php-ban is megadható (HPV_SEO_OS_SECRET), az az erősebb.
 */

defined( 'ABSPATH' ) || exit;

function hpv_seo_defaults(): array {
	return array(
		'api_url' => 'http://127.0.0.1:8100',
		'secret'  => '',
		'host'    => 'seo.helloprovision.com',
	);
}

function hpv_seo_settings(): array {
	$saved = get_option( HPV_SEO_OPTION, array() );

	return array_merge( hpv_seo_defaults(), is_array( $saved ) ? $saved : array() );
}

function hpv_seo_secret(): string {
	return defined( 'HPV_SEO_OS_SECRET' ) ? (string) HPV_SEO_OS_SECRET : (string) hpv_seo_settings()['secret'];
}

function hpv_seo_api_url(): string {
	$url = defined( 'HPV_SEO_OS_API_URL' ) ? (string) HPV_SEO_OS_API_URL : (string) hpv_seo_settings()['api_url'];

	return untrailingslashit( $url );
}

function hpv_seo_host(): string {
	return strtolower( defined( 'HPV_SEO_HOST' ) ? (string) HPV_SEO_HOST : (string) hpv_seo_settings()['host'] );
}

/**
 * Az aktuális (vagy megadott) felhasználó SEO OS szerepköre; üres, ha nincs hozzáférése.
 */
function hpv_seo_user_role( ?WP_User $user = null ): string {
	$user = $user ?? wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return '';
	}

	return hpv_seo_resolve_role( user_can( $user, 'manage_options' ), (string) get_user_meta( $user->ID, HPV_SEO_META, true ) );
}

function hpv_seo_can_access(): bool {
	return '' !== hpv_seo_user_role();
}

function hpv_seo_app_url( string $hash = '' ): string {
	$scheme = is_ssl() || ( defined( 'HPV_FORCE_HTTPS' ) && HPV_FORCE_HTTPS ) ? 'https' : 'http';
	// Az aktuális kérés címe (porttal együtt), ha az SEO OS aldomainről jön; egyébként a beállított cím.
	$request = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
	$host    = hpv_seo_host_matches( $request, hpv_seo_host() ) ? $request : hpv_seo_host();

	return $scheme . '://' . $host . '/' . ( $hash ? '#' . ltrim( $hash, '#' ) : '' );
}
