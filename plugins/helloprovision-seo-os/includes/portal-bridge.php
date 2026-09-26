<?php
/**
 * Kapcsolat az ügyfélportállal (helloprovision-portal 0.7+), a docs/integrations/seo-os.md szerződés szerint:
 *
 *  - az ügyfél a portál „Jóváhagyás” menüjében dönt egy SEO OS dokumentumról → az SEO OS megkapja a döntést
 *    (jóváhagyás / javításkérés megjegyzéssel), a kérő és a projekt felelőse értesítést kap;
 *  - a havi riportba az SEO OS adja a kulcsszó-helyezéseket, a megjelent tartalmakat és a technikai javításokat.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'hpv_approval_decided', 'hpv_seo_portal_decided', 10, 3 );

function hpv_seo_portal_decided( array $approval, string $status, string $note ): void {
	if ( 'seo-os' !== ( $approval['source'] ?? '' ) || ! preg_match( '/^document-(\d+)$/', (string) ( $approval['external_ref'] ?? '' ), $m ) ) {
		return;
	}
	$user = ! empty( $approval['decided_by'] ) ? get_userdata( (int) $approval['decided_by'] ) : null;
	$res  = hpv_seo_api_json(
		'POST',
		'system/client-decision',
		array(
			'document_id'        => (int) $m[1],
			'decision'           => 'approved' === $status ? 'approved' : 'changes_requested',
			'name'               => $user ? $user->display_name : 'Ügyfél',
			'note'               => $note,
			'portal_approval_id' => (int) $approval['id'],
		),
		HPV_SEO_SYSTEM
	);
	if ( is_wp_error( $res ) ) {
		error_log( 'SEO OS: a portál döntését nem sikerült átadni: ' . $res->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}

add_filter( 'hpv_report_metrics', 'hpv_seo_report_metrics', 20, 4 );
add_filter( 'hpv_report_errors', fn( $errors ) => array_merge( (array) $errors, $GLOBALS['hpv_seo_report_errors'] ?? array() ) );

function hpv_seo_report_metrics( array $metrics, array $client, string $period, string $prev_period ): array {
	$GLOBALS['hpv_seo_report_errors'] = array();
	$client_id = (int) ( $client['id'] ?? 0 );
	if ( ! $client_id || '' === hpv_seo_secret() ) {
		return $metrics;
	}
	$lang = function_exists( 'hpv_doc_client_language' ) ? hpv_doc_client_language( $client_id ) : 'hu';
	$res  = hpv_seo_api( 'GET', 'system/report-metrics', http_build_query( array( 'crm_client_id' => $client_id, 'period' => $period, 'lang' => $lang ) ), '', array(), HPV_SEO_SYSTEM, 20 );
	$data = is_wp_error( $res ) ? null : json_decode( (string) $res['body'], true );
	if ( is_wp_error( $res ) || 200 !== (int) $res['status'] || ! is_array( $data ) ) {
		$GLOBALS['hpv_seo_report_errors'][] = 'SEO OS: a SEO-mutatók lekérése nem sikerült.';
		return $metrics;
	}

	return array_merge( $metrics, (array) ( $data['metrics'] ?? array() ) );
}
