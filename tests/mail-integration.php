<?php
/**
 * Levelezés (helloprovision-mail) a WordPress oldalon: szerepkörök, továbbítás a szerverre, feladat levélből,
 * ügyfél-címek küldése. Az SEO OS szervert a teszt helyettesíti.
 * Futtatás (a portál, az SEO OS és a levelezés bővítmény aktív egy TESZT WordPressen):
 *   wp eval-file tests/mail-integration.php
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_mail_role' ) || ! function_exists( 'hpv_p_insert' ) || ! function_exists( 'hpv_seo_api' ) ) {
	echo "A levelezés, a portál vagy az SEO OS bővítmény nincs betöltve.\n";
	exit( 1 );
}
if ( strlen( hpv_seo_secret() ) < 32 ) {
	echo "A teszthez kell HPV_SEO_OS_SECRET (legalább 32 karakter).\n";
	exit( 1 );
}

$GLOBALS['hpv_it_fail'] = 0;
function it( $label, $ok ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$GLOBALS['hpv_it_fail']++;
	}
}

$GLOBALS['api'] = array();
add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, hpv_seo_api_url() ) ) {
			return $pre;
		}
		$path = substr( $url, strlen( hpv_seo_api_url() ) );
		$GLOBALS['api'][] = array( 'path' => $path, 'method' => $args['method'] ?? 'GET', 'role' => $args['headers']['X-HPV-Role'] ?? '', 'user' => $args['headers']['X-HPV-User'] ?? '', 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) );
		$json = fn( $d, $code = 200 ) => array( 'response' => array( 'code' => $code ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $d ) );
		if ( preg_match( '#^/mail/messages/(\d+)$#', $path ) ) {
			return $json( array( 'id' => 77, 'subject' => 'Re: Photos for the Naples page', 'from' => array( 'name' => 'Mike Carter', 'email' => 'mike@imperial.test' ), 'date' => '2026-09-26T14:00:00Z', 'body_text' => 'Hi, here are the photos.', 'crm_client_id' => null ) );
		}
		if ( preg_match( '#^/mail/messages/\d+/link$#', $path ) || '/system/mail-contacts' === $path ) {
			return $json( array( 'ok' => true, 'contacts' => 1, 'relinked' => 0 ) );
		}
		if ( 0 === strpos( $path, '/mail/me' ) ) {
			return $json( array( 'accounts' => array(), 'is_admin' => false ) );
		}
		return $json( array( 'message' => 'ismeretlen' ), 404 );
	},
	10,
	3
);

$suffix = wp_generate_password( 5, false, false );
$staff  = wp_insert_user( array( 'user_login' => "kata_$suffix", 'user_pass' => wp_generate_password(), 'user_email' => "kata_$suffix@hpv.test", 'display_name' => 'Kata CRM', 'role' => 'hpv_staff' ) );
$client = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Imperial $suffix", 'email' => "Mike_$suffix@Imperial.test", 'billing_email' => "billing_$suffix@imperial.test", 'country' => 'US', 'status' => 'active' ) ) );
$portal = hpv_p_invite_user( $client, 'Mike', "mike_portal_$suffix@imperial.test" );
$portal = $portal instanceof WP_User ? $portal->ID : (int) $portal;

echo "Szerepkörök\n";
it( 'admin → admin', 'admin' === hpv_mail_role( get_userdata( 1 ) ) );
it( 'CRM munkatárs SEO szerepkör nélkül → staff', 'staff' === hpv_mail_role( get_userdata( $staff ) ) );
it( 'portál ügyfél → nem levelezhet', '' === hpv_mail_role( get_userdata( $portal ) ) );
wp_set_current_user( $portal );
it( 'ügyfél nem érheti el a levelezés végpontjait', 403 === rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/mail/me' ) )->get_status() || 401 === rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/mail/me' ) )->get_status() );

echo "Továbbítás\n";
wp_set_current_user( $staff );
$GLOBALS['api'] = array();
$res = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/mail/me' ) );
$last = end( $GLOBALS['api'] );
it( 'a kérés aláírva megy a szerverre, staff szerepkörrel', 200 === $res->get_status() && '/mail/me' === $last['path'] && 'staff' === $last['role'] && (string) $staff === $last['user'] );
$ext = apply_filters( 'hpv_crm_app_extensions', array() );
it( 'a CRM felületbe bekerül a levelezés modul', (bool) array_filter( $ext, fn( $e ) => false !== strpos( $e['script'] ?? '', 'mail.js' ) ) );

echo "Feladat levélből\n";
$project = hpv_p_insert( 'project', array( 'client_id' => $client, 'name' => 'SEO growth', 'status' => 'in_progress', 'visible' => 1 ) );
$GLOBALS['api'] = array();
$req = new WP_REST_Request( 'POST', '/hpv/v1/mail-task' );
$req->set_body_params( array( 'message_id' => 77, 'project_id' => $project, 'title' => '', 'assignee_id' => $staff, 'due_date' => '2026-10-01', 'priority' => 'high', 'note' => 'Tedd fel a képeket.' ) );
$res  = rest_do_request( $req );
$data = $res->get_data();
$task = ! $res->is_error() ? hpv_p_get( 'task', (int) $data['task']['id'] ) : null;
it( 'feladat a projektben, a levél tárgyával', $task && (int) $task['project_id'] === $project && 'Re: Photos for the Naples page' === $task['title'] && (int) $task['assignee_id'] === $staff && 'high' === $task['priority'] );
it( 'a leírásban a megjegyzés, a feladó és a levél szövege', $task && false !== strpos( $task['description'], 'Tedd fel a képeket.' ) && false !== strpos( $task['description'], 'Mike Carter' ) && false !== strpos( $task['description'], 'here are the photos' ) );
it( 'belső feladat (az ügyfél nem látja)', $task && 0 === (int) $task['visible'] );
$links = array_values( array_filter( $GLOBALS['api'], fn( $c ) => '/mail/messages/77/link' === $c['path'] ) );
it( 'a levél megjegyzi a feladatot, és a projekt ügyfeléhez kötődik', 2 === count( $links ) && (int) $links[0]['body']['crm_task_id'] === (int) $data['task']['id'] && (int) $links[1]['body']['crm_client_id'] === $client );
it( 'bejegyzés az ügyfél idővonalán', (bool) array_filter( hpv_p_find( 'activity', array( 'client_id' => $client ) ), fn( $a ) => false !== strpos( $a['body'], 'Feladat e-mailből' ) ) );
it( 'link a feladatra', "#/projects/$project?task={$data['task']['id']}" === $data['url'] );
$bad = new WP_REST_Request( 'POST', '/hpv/v1/mail-task' );
$bad->set_body_params( array( 'message_id' => 77, 'project_id' => 999999 ) );
it( 'projekt nélkül hiba', rest_do_request( $bad )->is_error() );

echo "Ügyfél-címek\n";
$GLOBALS['api'] = array();
$r = hpv_mail_push_contacts( true );
$call = end( $GLOBALS['api'] );
$mine = array_values( array_filter( $call['body']['contacts'], fn( $c ) => $c['client_id'] === $client ) );
$emails = array_merge( ...array_map( fn( $c ) => $c['emails'], $mine ) );
it( 'teljes lista a rendszer szerepkörrel', '/system/mail-contacts' === $call['path'] && 'system' === $call['role'] && true === $call['body']['full'] );
it( 'az ügyfél címe (kisbetűvel), a számlázási cím és a portál felhasználóé', ! array_diff( array_map( 'strtolower', array( "mike_$suffix@imperial.test", "billing_$suffix@imperial.test", "mike_portal_$suffix@imperial.test" ) ), $emails ) );
$GLOBALS['api'] = array();
hpv_p_update( 'client', $client, array( 'email' => "new_$suffix@imperial.test" ) );
$call = end( $GLOBALS['api'] );
it( 'ügyfél módosításakor azonnal megy (csak az az ügyfél)', $call && '/system/mail-contacts' === $call['path'] && false === $call['body']['full'] && 1 === count( $call['body']['contacts'] ) );

wp_delete_user( $staff );
echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
