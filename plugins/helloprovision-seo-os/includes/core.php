<?php
/**
 * WordPress nélkül is tesztelhető alapfüggvények: aláírás, szerepkör, útvonal-ellenőrzés.
 */

defined( 'ABSPATH' ) || exit;

const HPV_SEO_ROLES = array(
	'admin'           => 'Admin',
	'seo_manager'     => 'SEO manager',
	'content_manager' => 'Content manager',
	'designer'        => 'Designer',
	'developer'       => 'Fejlesztő',
);

/**
 * A FastAPI felé küldött aláírt fejlécek (a kanonikus szöveget lásd: services/seo-os-api/app/auth.py).
 */
function hpv_seo_signed_headers( string $secret, string $method, string $path, string $body, int $user_id, string $role, string $email, string $name, ?int $ts = null ): array {
	$ts        = (string) ( $ts ?? time() );
	$email     = rawurlencode( $email );
	$name      = rawurlencode( $name );
	$canonical = implode( "\n", array( strtoupper( $method ), $path, $ts, hash( 'sha256', $body ), (string) $user_id, $role, $email, $name ) );

	return array(
		'X-HPV-Timestamp' => $ts,
		'X-HPV-User'      => (string) $user_id,
		'X-HPV-Role'      => $role,
		'X-HPV-Email'     => $email,
		'X-HPV-Name'      => $name,
		'X-HPV-Signature' => hash_hmac( 'sha256', $canonical, $secret ),
	);
}

/**
 * SEO OS szerepkör: WordPress adminisztrátor → admin; egyébként a felhasználóhoz rendelt szerepkör (vagy üres).
 */
function hpv_seo_resolve_role( bool $is_wp_admin, string $assigned ): string {
	if ( $is_wp_admin ) {
		return 'admin';
	}

	return isset( HPV_SEO_ROLES[ $assigned ] ) ? $assigned : '';
}

/**
 * A proxy csak ismert alakú útvonalat enged tovább (nincs "..", nincs séma, nincs dupla perjel).
 */
function hpv_seo_valid_path( string $path ): bool {
	return '' !== $path
		&& (bool) preg_match( '#^[a-z0-9][a-z0-9_\-]*(/[a-z0-9_\-\.]+)*$#i', $path )
		&& false === strpos( $path, '..' );
}

/**
 * Lekérdezés a WordPress saját paraméterei nélkül, stabil sorrendben (az aláírás része).
 */
function hpv_seo_query_string( array $params ): string {
	unset( $params['_wpnonce'], $params['rest_route'], $params['_locale'], $params['path'] );
	$params = array_filter( $params, fn( $v ) => null !== $v && '' !== $v );

	return $params ? http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) : '';
}

/**
 * A kérés címe (kisbetűs, port nélkül).
 */
function hpv_seo_host_matches( string $request_host, string $seo_host ): bool {
	$request_host = strtolower( preg_replace( '/:\d+$/', '', $request_host ) );

	return '' !== $seo_host && strtolower( $seo_host ) === $request_host;
}
