<?php
/**
 * A weboldal űrlapjai → CRM (mu-plugins/helloprovision-leads.php) integrációs tesztje.
 * Egy TESZT WordPressen fut, ahol a portál bővítmény is aktív (a CRM végpontja itt fogad), és a leads mu-plugin betöltődik:
 *   wp eval-file tests/leads-integration.php
 * A téma űrlapját valódi HTTP-kéréssel próbálja, ha a HPV_TEST_SITE_URL környezeti változó meg van adva, és az oldalon
 * fut egy form_submit admin-ajax kezelő (a téma, vagy fejlesztéskor egy utánzata).
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_leads_map' ) || ! function_exists( 'hpv_leads_ingest' ) ) {
	echo "A leads mu-plugin vagy a portál bővítmény nincs betöltve.\n";
	exit( 1 );
}

$GLOBALS['hpv_it_fail'] = 0;
$GLOBALS['hpv_it_mail'] = array();
function it( $label, $ok ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$GLOBALS['hpv_it_fail']++;
	}
}
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['hpv_it_mail'][] = $atts;
		return true;
	},
	10,
	2
);
$suffix  = strtolower( wp_generate_password( 5, false, false ) );
$clients = array();
$find    = function ( string $email ) use ( &$clients ) {
	$c = hpv_p_find( 'client', array( 'email' => $email ), array( 'limit' => 1 ) )[0] ?? null;
	if ( $c ) {
		$clients[] = (int) $c['id'];
	}
	return $c;
};

echo "Mezők felismerése\n";
$l = hpv_leads_map( array( 'action' => 'form_submit', 'form_type' => 'brief', 'name' => 'Jane Roe', 'email' => 'jane@x.test', 'goal' => 'New site by May' ), 'contact', 'Project Brief', 'https://h.test/contact/' );
it( 'a téma űrlapja: név, e-mail, cél → üzenet, a rejtett mezők nem', 'Jane Roe' === $l['name'] && 'jane@x.test' === $l['email'] && 'New site by May' === $l['message'] && ! $l['fields'] && 'Project Brief' === $l['form'] && 'https://h.test/contact/' === $l['page'] );
$l = hpv_leads_map( array( 'your-name' => 'Bob', 'your-email' => 'bob@x.test', 'your-phone' => '239-555', 'company-name' => 'Bob Pools', 'your-website' => 'bobpools.com', 'your-message' => 'Hi', 'budget' => '$5k', 'services' => array( 'SEO', 'Ads' ), 'acceptance-123' => '1', '_wpcf7' => '5', 'g-recaptcha-response' => 'x' ), 'contact', 'CF7' );
it( 'Contact Form 7 mezőnevek: cég nem lesz név, weboldal https-sel, többi kérdés külön', 'Bob' === $l['name'] && 'Bob Pools' === $l['business'] && '239-555' === $l['phone'] && 'https://bobpools.com' === $l['website'] && 'Hi' === $l['message'] && array( 'Budget' => '$5k', 'Services' => 'SEO, Ads' ) === $l['fields'] );
$l = hpv_leads_map( array( 'First Name' => 'Ann', 'Last Name' => 'Lee', 'Email Address' => 'ann@x.test' ), 'contact', 'X' );
it( 'kereszt- és vezetéknév összefűzve', 'Ann Lee' === $l['name'] );
it( 'e-mail nélkül nincs érdeklődő (pl. keresés, hírlevél-leiratkozás)', null === hpv_leads_map( array( 'name' => 'x', 'message' => 'y' ), 'contact', 'X' ) );

echo "A téma válasza\n";
it( 'JSON success=true → küld', hpv_leads_response_ok( '{"success":true,"data":{}}', 200 ) );
it( 'JSON success=false → nem', ! hpv_leads_response_ok( '{"success":false}', 200 ) );
it( 'hibakód → nem', ! hpv_leads_response_ok( '{"success":true}', 422 ) && ! hpv_leads_response_ok( '', 500 ) );
it( 'admin-ajax „0” (nincs kezelő) → nem', ! hpv_leads_response_ok( '0', 200 ) );
it( 'nem JSON, sikeres kód → küld', hpv_leads_response_ok( 'OK', 200 ) );

echo "Küldés a CRM-be aláírt HTTP-vel (külön WordPress)\n";
add_filter( 'hpv_leads_direct', '__return_false' );
define( 'HPV_CRM_URL', 'https://crm.example.test' );
define( 'HPV_BRIDGE_SECRET', 'test-bridge-secret-0123456789' );
$GLOBALS['crm_down'] = false;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( 'https://crm.example.test/wp-json/hpv/v1/bridge/lead' !== $url ) {
			return $pre;
		}
		if ( $GLOBALS['crm_down'] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		}
		$req = new WP_REST_Request( 'POST', '/hpv/v1/bridge/lead' );
		foreach ( $args['headers'] as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$req->set_body( $args['body'] );
		$res = rest_do_request( $req );
		return array( 'headers' => array(), 'body' => wp_json_encode( $res->get_data() ), 'response' => array( 'code' => $res->get_status(), 'message' => '' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);
delete_option( HPV_LEADS_OUTBOX );
$lead = hpv_leads_map( array( 'name' => 'Carl Sun', 'email' => "carl_$suffix@sun.test", 'goal' => 'Local SEO for 3 locations', 'budget' => '$2k/mo' ), 'contact', 'Project Brief', 'https://helloprovision.com/' );
it( 'elküldve, a CRM aláírással elfogadja', hpv_leads_send( $lead ) );
$c    = $find( "carl_$suffix@sun.test" );
$note = $c ? ( hpv_p_find( 'activity', array( 'client_id' => (int) $c['id'] ), array( 'limit' => 1 ) )[0]['body'] ?? '' ) : '';
it( 'a CRM-ben érdeklődő, üzenettel és válaszokkal', $c && 'lead' === $c['status'] && 'US' === $c['country'] && false !== strpos( $note, 'Local SEO for 3 locations' ) && false !== strpos( $note, 'Budget: $2k/mo' ) );

$GLOBALS['crm_down'] = true;
$lead2 = hpv_leads_map( array( 'name' => 'Dora', 'email' => "dora_$suffix@x.test" ), 'contact', 'Contact' );
it( 'a CRM nem érhető el: sorba kerül, újrapróbálás ütemezve', ! hpv_leads_send( $lead2 ) && 1 === count( get_option( HPV_LEADS_OUTBOX ) ) && wp_next_scheduled( 'hpv_leads_retry' ) );
it( 'újrapróbálás, még mindig nem megy: marad', array( 'sent' => 0, 'waiting' => 1, 'dropped' => 0 ) === hpv_leads_retry() );
$GLOBALS['crm_down'] = false;
it( 'a CRM újra elérhető: átmegy, a sor kiürül, nincs több ütemezés', 1 === hpv_leads_retry()['sent'] && ! get_option( HPV_LEADS_OUTBOX ) && ! wp_next_scheduled( 'hpv_leads_retry' ) && $find( "dora_$suffix@x.test" ) );

$GLOBALS['crm_down']    = true;
$GLOBALS['hpv_it_mail'] = array();
hpv_leads_send( hpv_leads_map( array( 'name' => 'Eve', 'email' => "eve_$suffix@x.test", 'goal' => 'Ads' ), 'contact', 'Contact' ) );
$box          = get_option( HPV_LEADS_OUTBOX );
$box[0]['at'] = time() - 4 * DAY_IN_SECONDS;
update_option( HPV_LEADS_OUTBOX, $box );
it( '3 nap után feladja, de e-mailben elküldi az adminnak, hogy ne vesszen el', 1 === hpv_leads_retry()['dropped'] && 1 === count( $GLOBALS['hpv_it_mail'] ) && false !== strpos( $GLOBALS['hpv_it_mail'][0]['message'], "eve_$suffix@x.test" ) && false !== strpos( $GLOBALS['hpv_it_mail'][0]['message'], 'Message: Ads' ) );
$GLOBALS['crm_down'] = false;
it( 'a CRM elutasítja (rossz adat): nem kerül sorba', ! hpv_leads_send( array( 'email' => 'nem-email' ) ) && ! get_option( HPV_LEADS_OUTBOX ) );
add_filter( 'hpv_leads_capture', fn( $l ) => 'Newsletter' === ( $l['form'] ?? '' ) ? false : $l );
it( 'szűrővel kihagyható űrlap', ! hpv_leads_send( hpv_leads_map( array( 'email' => "news_$suffix@x.test" ), 'contact', 'Newsletter' ) ) && ! $find( "news_$suffix@x.test" ) );

echo "Túl sok kitöltés egy IP-ről\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
$oks = array_map( fn() => hpv_leads_ip_ok(), range( 1, 6 ) );
it( 'óránként 5 megy, a 6. nem', array( true, true, true, true, true, false ) === $oks );

$site = getenv( 'HPV_TEST_SITE_URL' );
if ( $site ) {
	echo "A téma űrlapja valódi HTTP-kéréssel ($site)\n";
	foreach ( array( '127.0.0.1', '::1' ) as $ip ) {
		delete_transient( 'hpv_leads_ip_' . md5( $ip ) ); // a korábbi kézi próbák ne számítsanak bele az óránkénti korlátba
	}
	$post = function ( array $body ) use ( $site ) {
		return wp_remote_post( untrailingslashit( $site ) . '/wp-admin/admin-ajax.php', array( 'timeout' => 20, 'body' => $body, 'headers' => array( 'Referer' => untrailingslashit( $site ) . '/contact/' ) ) );
	};
	$res = $post( array( 'action' => 'form_submit', 'form_type' => 'brief', 'name' => 'Frank Webb', 'email' => "frank_$suffix@webb.test", 'goal' => 'Redesign + SEO before summer' ) );
	it( 'a látogató a téma válaszát kapja, változatlanul', 200 === wp_remote_retrieve_response_code( $res ) && '{"success":true,"data":{"message":"Thanks!"}}' === wp_remote_retrieve_body( $res ) );
	wp_cache_flush();
	$c    = $find( "frank_$suffix@webb.test" );
	$note = $c ? ( hpv_p_find( 'activity', array( 'client_id' => (int) $c['id'] ), array( 'limit' => 1 ) )[0]['body'] ?? '' ) : '';
	it( 'bekerült a CRM-be: Project Brief, oldal, üzenet', $c && 'Frank Webb' === $c['name'] && false !== strpos( $note, 'Kapcsolati űrlap (Project Brief): ' . untrailingslashit( $site ) . '/contact/' ) && false !== strpos( $note, 'Redesign + SEO before summer' ) );
	$post( array( 'action' => 'form_submit', 'form_type' => 'brief', 'name' => 'Spam', 'email' => "spam_$suffix@x.test", 'spam' => '1' ) );
	wp_cache_flush();
	it( 'amit a téma elutasít, az nem kerül be', ! $find( "spam_$suffix@x.test" ) );
}

echo "Takarítás\n";
foreach ( array_unique( $clients ) as $cid ) {
	hpv_p_delete( 'client', $cid );
}
delete_option( HPV_LEADS_OUTBOX );
wp_clear_scheduled_hook( 'hpv_leads_retry' );
it( 'kész', true );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
