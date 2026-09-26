<?php
/**
 * REST proxy: /wp-json/hpv-seo/v1/{útvonal} → FastAPI, aláírt felhasználói adatokkal.
 * A böngésző csak a WordPresst látja (azonos domain, WordPress belépés); a FastAPI nem nyilvános.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hívás a FastAPI felé. $as: array( user_id, role, email, name ) – alapból a bejelentkezett felhasználó.
 *
 * @return array{status:int, headers:array, body:string}|WP_Error
 */
function hpv_seo_api( string $method, string $path, string $query = '', string $body = '', array $extra_headers = array(), ?array $as = null, int $timeout = 30 ) {
	$secret = hpv_seo_secret();
	if ( strlen( $secret ) < 32 ) {
		return new WP_Error( 'hpv_seo_config', 'Az SEO OS nincs beállítva (közös titok hiányzik).', array( 'status' => 503 ) );
	}
	if ( null === $as ) {
		$user = wp_get_current_user();
		$as   = array( (int) $user->ID, hpv_seo_user_role( $user ), (string) $user->user_email, (string) $user->display_name );
	}
	$full    = '/' . ltrim( $path, '/' ) . ( '' !== $query ? '?' . $query : '' );
	$headers = hpv_seo_signed_headers( $secret, $method, $full, $body, (int) $as[0], (string) $as[1], (string) $as[2], (string) $as[3] );
	$headers = array_merge( array( 'Content-Type' => 'application/json' ), $extra_headers, $headers );

	$response = wp_remote_request(
		hpv_seo_api_url() . $full,
		array(
			'method'  => $method,
			'headers' => $headers,
			'body'    => '' === $body ? null : $body,
			'timeout' => $timeout,
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'hpv_seo_unreachable', 'Az SEO OS szerver nem érhető el. Próbáld újra pár perc múlva.', array( 'status' => 502 ) );
	}

	return array(
		'status'  => (int) wp_remote_retrieve_response_code( $response ),
		'headers' => wp_remote_retrieve_headers( $response ),
		'body'    => (string) wp_remote_retrieve_body( $response ),
	);
}

/**
 * JSON hívás kényelmi változata (a bővítmény saját funkcióihoz, pl. CRM feladatok).
 */
function hpv_seo_api_json( string $method, string $path, $data = null, ?array $as = null ) {
	$result = hpv_seo_api( $method, $path, '', null === $data ? '' : wp_json_encode( $data ), array(), $as );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$decoded = json_decode( $result['body'], true );
	if ( $result['status'] >= 400 ) {
		return new WP_Error( 'hpv_seo_api', is_array( $decoded ) && isset( $decoded['message'] ) ? $decoded['message'] : 'Hiba történt.', array( 'status' => $result['status'] ) );
	}

	return $decoded;
}

add_action( 'rest_api_init', 'hpv_seo_proxy_routes', 20 );

function hpv_seo_proxy_routes() {
	// A Screaming Frog ügynök: saját tokennel (a FastAPI ellenőrzi), WordPress belépés nélkül.
	register_rest_route(
		'hpv-seo/v1',
		'/agent/(?P<path>[a-zA-Z0-9_\-/\.]+)',
		array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $request ) {
				$auth = (string) $request->get_header( 'authorization' );
				return hpv_seo_forward( $request, 'agent/' . $request['path'], array( 0, 'agent', '', 'Screaming Frog ügynök' ), array( 'Authorization' => $auth ) );
			},
		)
	);

	register_rest_route(
		'hpv-seo/v1',
		'/(?P<path>[a-zA-Z0-9_\-/\.]+)',
		array(
			'methods'             => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
			'permission_callback' => 'hpv_seo_can_access',
			'callback'            => function ( WP_REST_Request $request ) {
				return hpv_seo_forward( $request, (string) $request['path'] );
			},
		)
	);
}

function hpv_seo_forward( WP_REST_Request $request, string $path, ?array $as = null, array $extra = array() ) {
	if ( ! hpv_seo_valid_path( $path ) ) {
		return new WP_Error( 'hpv_seo_path', 'Érvénytelen útvonal.', array( 'status' => 400 ) );
	}
	$method = $request->get_method();
	$body   = in_array( $method, array( 'GET', 'DELETE' ), true ) ? '' : (string) $request->get_body();
	$ctype  = (string) $request->get_header( 'content_type' );
	if ( '' !== $ctype ) {
		$extra['Content-Type'] = $ctype;
	}
	$filename = (string) $request->get_header( 'x_filename' );
	if ( '' !== $filename ) {
		$extra['X-Filename'] = $filename;
	}
	$is_upload = '' !== $filename;
	$result    = hpv_seo_api( $method, $path, hpv_seo_query_string( $request->get_query_params() ), $body, $extra, $as, $is_upload ? 180 : 60 );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$type = (string) ( $result['headers']['content-type'] ?? 'application/json' );
	if ( false === strpos( $type, 'application/json' ) ) {
		// Fájl (PDF, DOCX, XLSX): közvetlenül a böngészőnek.
		status_header( $result['status'] );
		header( 'Content-Type: ' . $type );
		$disposition = (string) ( $result['headers']['content-disposition'] ?? '' );
		if ( '' !== $disposition ) {
			header( 'Content-Disposition: ' . $disposition );
		}
		header( 'Content-Length: ' . strlen( $result['body'] ) );
		nocache_headers();
		echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- bináris fájl
		exit;
	}

	$data = json_decode( $result['body'], true );

	return new WP_REST_Response( $data, $result['status'] );
}
