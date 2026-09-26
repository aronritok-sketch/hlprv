<?php
/**
 * Közös AI kliens (Anthropic Claude Messages API): hívás-összefoglaló, szerződés, ajánlat.
 * Kulcs: define( 'HPV_AI_API_KEY', '…' ); modell (nem kötelező): define( 'HPV_AI_MODEL', '…' );
 */

defined( 'ABSPATH' ) || exit;

const HPV_AI_API = 'https://api.anthropic.com/v1/messages';

function hpv_ai_key(): string {
	return defined( 'HPV_AI_API_KEY' ) ? (string) HPV_AI_API_KEY : '';
}

function hpv_ai_model(): string {
	return defined( 'HPV_AI_MODEL' ) && HPV_AI_MODEL ? (string) HPV_AI_MODEL : 'claude-sonnet-5';
}

function hpv_ai_enabled(): bool {
	return '' !== hpv_ai_key();
}

/**
 * Egy kérés, egy szöveges válasz.
 *
 * @return string|WP_Error
 */
function hpv_ai_complete( string $system, string $user, int $max_tokens = 4000, int $timeout = 120 ) {
	if ( ! hpv_ai_enabled() ) {
		return new WP_Error( 'ai_off', 'Nincs AI kulcs (HPV_AI_API_KEY).' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( $timeout + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- néhol tiltott
	}
	$res = wp_remote_post(
		HPV_AI_API,
		array(
			'timeout' => $timeout,
			'headers' => array(
				'x-api-key'         => hpv_ai_key(),
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'model'      => hpv_ai_model(),
					'max_tokens' => $max_tokens,
					'system'     => $system,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => $user,
						),
					),
				)
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'ai_http', 'Az AI szolgáltatás nem érhető el: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $data ) ) {
		$msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
		return new WP_Error( 'ai_error', 'AI hiba (' . $code . ')' . ( $msg ? ': ' . $msg : '' ) );
	}
	$text = '';
	foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
		if ( 'text' === ( $block['type'] ?? '' ) ) {
			$text .= (string) $block['text'];
		}
	}
	if ( 'max_tokens' === ( $data['stop_reason'] ?? '' ) ) {
		return new WP_Error( 'ai_truncated', 'Az AI válasza túl hosszú lett és megszakadt. Rövidítsd a mintát vagy az utasítást, és próbáld újra.' );
	}

	return $text;
}

/**
 * Az első JSON objektum a válaszból (a modell néha magyarázatot vagy ```json keretet tesz köré).
 */
function hpv_ai_json( string $text ): ?array {
	$start = strpos( $text, '{' );
	$end   = strrpos( $text, '}' );
	$json  = false !== $start && false !== $end ? json_decode( substr( $text, $start, $end - $start + 1 ), true ) : null;

	return is_array( $json ) ? $json : null;
}

/**
 * HTML válasz megtisztítása: kód keret le, csak a dokumentum-elemek maradnak.
 */
function hpv_ai_html( string $text ): string {
	$text = preg_replace( '/^\s*```(?:html)?\s*|\s*```\s*$/i', '', trim( $text ) );

	return hpv_doc_clean_html( (string) $text );
}
