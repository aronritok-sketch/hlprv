<?php
/**
 * Automatizmusok integrációs tesztje: fizetési emlékeztetők, munkaidő a számlára, Website Grader érdeklődő (aláírt híd),
 * Google értékeléskérés kész projekt után (a Reviews oldal álszerverrel).
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen, kulcsok nélkül):
 *   wp eval-file tests/automation-integration.php
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_dunning_run' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}
if ( defined( 'HPV_BRIDGE_SECRET' ) || function_exists( 'hpv_reviews_create_request' ) ) {
	echo "Ezt a tesztet híd-titok és Reviews bővítmény nélküli teszt WordPressen futtasd.\n";
	exit( 1 );
}

$GLOBALS['hpv_it_fail'] = 0;
$GLOBALS['hpv_it_mail'] = array();
$GLOBALS['reviews']     = array();

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
define( 'HPV_BRIDGE_SECRET', 'test-bridge-secret-0123456789' );
define( 'HPV_SITE_URL', 'https://marketing.example.test' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( 'https://marketing.example.test/wp-json/hpv-reviews/v1/request' === $url ) {
			$ts  = $args['headers']['X-HPV-Timestamp'];
			$ok  = hash_equals( hash_hmac( 'sha256', $ts . '.' . $args['body'], HPV_BRIDGE_SECRET ), $args['headers']['X-HPV-Signature'] );
			$req = json_decode( $args['body'], true );
			$GLOBALS['reviews'][] = array( 'signed' => $ok, 'req' => $req );
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'status' => $ok ? 'sent' : 'invalid' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return new WP_Error( 'offline', 'unexpected ' . $url );
	},
	10,
	3
);
function mails_to( string $email ): array {
	return array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
}
function signed( string $path, array $data, string $secret = HPV_BRIDGE_SECRET, int $ts = 0 ) {
	$req  = new WP_REST_Request( 'POST', '/hpv/v1' . $path );
	$body = wp_json_encode( $data );
	$ts   = $ts ?: time();
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'X-HPV-Timestamp', (string) $ts );
	$req->set_header( 'X-HPV-Signature', hash_hmac( 'sha256', $ts . '.' . $body, $secret ) );
	$req->set_body( $body );
	return rest_do_request( $req );
}

$suffix = strtolower( wp_generate_password( 5, false, false ) );
wp_set_current_user( 1 );
$us    = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Sun Pools $suffix", 'status' => 'active', 'hourly_rate' => '120' ) ) );
$hu    = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Kovács Kert $suffix", 'country' => 'HU', 'status' => 'active' ) ) );
$mia   = hpv_p_invite_user( $us, 'Mia Sun', "mia_$suffix@sunpools.test" );
$maria = hpv_p_invite_user( $hu, 'Kovács Mária', "maria_$suffix@kovacs.test" );

echo "Fizetési emlékeztetők\n";
$make = function ( int $client, string $due, array $extra = array() ) {
	$id = hpv_p_insert( 'invoice', array_merge( array( 'client_id' => $client, 'status' => 'sent', 'number' => 'T-' . wp_generate_password( 4, false, false ), 'issue_date' => '2026-09-01', 'due_date' => $due ), $extra ) );
	hpv_p_save_invoice_items( $id, array( array( 'description' => 'Work', 'quantity' => '1', 'unit_price' => '500' ) ) );
	return $id;
};
$late   = $make( $us, '2026-09-20' );
$late_h = $make( $hu, '2026-09-20' );
$fresh  = $make( $us, '2026-09-30' );
$GLOBALS['hpv_it_mail'] = array();
$sent = hpv_dunning_run( '2026-09-23' );
it( '3 nap késés: első emlékeztető, a friss számláról nem', 1 === ( $sent[ $late ] ?? 0 ) && ! isset( $sent[ $fresh ] ) );
$m = mails_to( "mia_$suffix@sunpools.test" );
it( 'angol levél fizetési linkkel', 1 === count( $m ) && 0 === strpos( $m[0]['subject'], 'Reminder: invoice' ) && false !== strpos( $m[0]['message'], 'view=invoices' ) && false !== strpos( $m[0]['message'], '$500.00' ) );
$mh = mails_to( "maria_$suffix@kovacs.test" );
it( 'magyar ügyfélnek magyarul', 1 === count( $mh ) && 0 === strpos( $mh[0]['subject'], 'Emlékeztető: lejárt' ) && false !== strpos( $mh[0]['message'], '2026. szeptember 20.' ) );
it( 'a csapat összefoglalót kap', (bool) array_filter( $GLOBALS['hpv_it_mail'], fn( $x ) => 0 === strpos( $x['subject'], 'Fizetési emlékeztetők:' ) ) );
it( 'ugyanaznap / 4. napon nem ismétel', ! isset( hpv_dunning_run( '2026-09-23' )[ $late ] ) && ! isset( hpv_dunning_run( '2026-09-24' )[ $late ] ) );
it( '7. nap: második', 2 === ( hpv_dunning_run( '2026-09-27' )[ $late ] ?? 0 ) );
$GLOBALS['hpv_it_mail'] = array();
it( '14. nap: végső', 3 === ( hpv_dunning_run( '2026-10-04' )[ $late ] ?? 0 ) && array_filter( mails_to( "mia_$suffix@sunpools.test" ), fn( $x ) => 0 === strpos( $x['subject'], 'Final reminder' ) ) );
it( 'utána nincs több', ! isset( hpv_dunning_run( '2026-11-30' )[ $late ] ) );
$skip = $make( $us, '2026-08-01' );
it( 'hosszú szünet után egyszerre csak egy levél (a legutolsó küszöb)', 3 === ( hpv_dunning_run( '2026-10-05' )[ $skip ] ?? 0 ) );
hpv_bill_mark_paid( $fresh, array( 'provider' => 'manual', 'amount' => 50000, 'user_id' => 1 ) );
it( 'kifizetett számláról nem megy', ! isset( hpv_dunning_run( '2026-10-20' )[ $fresh ] ) );
$settings = get_option( HPV_PORTAL_OPTION );
update_option( HPV_PORTAL_OPTION, array_merge( (array) $settings, array( 'payment_reminders' => false ) ) );
$off = $make( $us, '2026-09-01' );
it( 'kikapcsolva nem fut', ! hpv_dunning_run( '2026-10-20' ) );
update_option( HPV_PORTAL_OPTION, $settings );

echo "Munkaidő a számlára\n";
$proj = hpv_p_insert( 'project', array( 'client_id' => $us, 'name' => 'Website', 'status' => 'in_progress' ) );
$t1   = hpv_p_insert( 'task', array( 'project_id' => $proj, 'title' => 'Homepage', 'status' => 'done' ) );
$t2   = hpv_p_insert( 'task', array( 'project_id' => $proj, 'title' => 'SEO fixes', 'status' => 'done' ) );
$e1   = hpv_p_insert( 'time_entry', array( 'task_id' => $t1, 'user_id' => 1, 'minutes' => 90, 'work_date' => '2026-09-10' ) );
$e2   = hpv_p_insert( 'time_entry', array( 'task_id' => $t2, 'user_id' => 1, 'minutes' => 30, 'work_date' => '2026-09-12' ) );
$e3   = hpv_p_insert( 'time_entry', array( 'task_id' => $t2, 'user_id' => 1, 'minutes' => 45, 'work_date' => '2026-10-02' ) );
hpv_p_insert( 'time_entry', array( 'task_id' => $t1, 'user_id' => 1, 'minutes' => 0, 'started_at' => time() ) ); // futó stopper
$res = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/billing/time' ) );
$req = new WP_REST_Request( 'GET', '/hpv/v1/billing/time' );
$req->set_query_params( array( 'client_id' => $us, 'from' => '2026-09-01', 'to' => '2026-09-30' ) );
$d = rest_do_request( $req )->get_data();
it( 'szeptemberi, ki nem számlázott idő; futó stopper nélkül', 2 === count( $d['entries'] ) && 120 === $d['minutes'] && 120.0 === (float) $d['rate'] );
$inv = hpv_p_insert( 'invoice', array( 'client_id' => $us, 'status' => 'draft', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-15' ) );
$req = new WP_REST_Request( 'POST', "/hpv/v1/billing/invoices/$inv/time" );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_body( wp_json_encode( array( 'entry_ids' => array( $e1, $e2 ), 'group' => 'project' ) ) );
$d = rest_do_request( $req )->get_data();
it( 'projektenként egy tétel: 2 óra × $120', 1 === count( $d['items'] ) && '2' === $d['items'][0]['quantity'] && 24000 === $d['total'] && false !== strpos( $d['items'][0]['description'], 'Website — hours (2026-09-10 – 2026-09-12)' ) );
it( 'a bejegyzések a számlához kötve, kétszer nem számlázható', $inv === (int) hpv_p_get( 'time_entry', $e1 )['invoice_id'] && is_wp_error( hpv_time_to_invoice( $inv, array( $e1 ), 'project' ) ) );
$d = hpv_time_to_invoice( $inv, array( $e3 ), 'task', 100 );
it( 'feladatonként, egyedi óradíjjal, a meglévő tételek mellé', ! is_wp_error( $d ) && 2 === count( hpv_p_find( 'invoice_item', array( 'invoice_id' => $inv ) ) ) && 31500 === hpv_p_to_cents( $d['total'] ) );
hpv_p_delete( 'invoice', $inv );
it( 'a piszkozat törlésekor újra számlázható', 0 === (int) hpv_p_get( 'time_entry', $e1 )['invoice_id'] );
$hinv = hpv_p_insert( 'invoice', array( 'client_id' => $hu, 'status' => 'draft' ) );
it( 'óradíj nélkül érthető hiba', is_wp_error( hpv_time_to_invoice( $hinv, array( $e1 ), 'project' ) ) );

echo "Website Grader érdeklődő\n";
$GLOBALS['hpv_it_mail'] = array();
$lead = array( 'source' => 'grader', 'name' => 'Dr. Palm', 'email' => "PALM_$suffix@dental.test", 'business' => "Palm Dental $suffix", 'website' => 'https://palmdental.test', 'score' => 58, 'grade' => 'D', 'issues' => array( 'No LocalBusiness schema', 'Slow mobile load' ), 'report_url' => 'https://marketing.example.test/wp-admin/admin.php?page=hpv-grader&lead=9' );
it( 'aláírás nélkül / rossz titokkal / lejárt időbélyeggel: elutasítva', 401 <= rest_do_request( new WP_REST_Request( 'POST', '/hpv/v1/bridge/lead' ) )->get_status() && 401 <= signed( '/bridge/lead', $lead, 'wrong-secret-0123456789' )->get_status() && 401 <= signed( '/bridge/lead', $lead, HPV_BRIDGE_SECRET, time() - 600 )->get_status() );
$res = signed( '/bridge/lead', $lead );
$c   = $res->get_data();
it( 'új érdeklődő ügyfél', 200 === $res->get_status() && $c['created'] && 'lead' === hpv_p_get( 'client', $c['client_id'] )['status'] && "Palm Dental $suffix" === hpv_p_get( 'client', $c['client_id'] )['name'] && "palm_$suffix@dental.test" === hpv_p_get( 'client', $c['client_id'] )['email'] );
$note = hpv_p_find( 'activity', array( 'client_id' => $c['client_id'] ), array( 'limit' => 1 ) )[0] ?? array();
it( 'pontszám és hibák belső jegyzetben', false !== strpos( $note['body'] ?? '', '58/100 (D)' ) && false !== strpos( $note['body'], 'No LocalBusiness schema' ) && ! (int) $note['visible'] );
it( 'a csapat e-mailt kap (válasz a leadnek)', (bool) array_filter( $GLOBALS['hpv_it_mail'], fn( $x ) => 0 === strpos( $x['subject'], 'Új érdeklődő: Palm Dental' ) && false !== strpos( implode( ' ', (array) $x['headers'] ), "Reply-To: palm_$suffix@dental.test" ) ) );
$res2 = signed( '/bridge/lead', array_merge( $lead, array( 'score' => 71 ) ) );
it( 'ugyanaz az e-mail: nem lesz dupla, új jegyzet', ! $res2->get_data()['created'] && $c['client_id'] === $res2->get_data()['client_id'] && 2 === count( hpv_p_find( 'activity', array( 'client_id' => $c['client_id'] ) ) ) );

echo "Google értékeléskérés\n";
$done_us = hpv_p_insert( 'project', array( 'client_id' => $us, 'name' => 'Website redesign', 'status' => 'completed', 'visible' => 1 ) );
$done_hu = hpv_p_insert( 'project', array( 'client_id' => $hu, 'name' => 'Webáruház', 'status' => 'completed', 'visible' => 1 ) );
$first = hpv_review_auto();
it( 'első futás: csak a lezárás időpontját jegyzi', ! $first && hpv_p_get( 'project', $done_us )['completed_at'] && ! $GLOBALS['reviews'] );
hpv_p_update( 'project', $done_us, array( 'completed_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ) );
hpv_p_update( 'project', $done_hu, array( 'completed_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ) );
$run = hpv_review_auto();
it( '3 nap után: az amerikai ügyfél kérést kap (aláírt kérés, portál-felhasználó neve)', 'sent' === ( $run[ $done_us ] ?? '' ) && 1 === count( $GLOBALS['reviews'] ) && $GLOBALS['reviews'][0]['signed'] && "mia_$suffix@sunpools.test" === $GLOBALS['reviews'][0]['req']['email'] && 'Website redesign' === $GLOBALS['reviews'][0]['req']['project'] );
it( 'a magyar ügyfél nem (alapbeállítás: csak USA)', ! isset( $run[ $done_hu ] ) && hpv_p_get( 'project', $done_hu )['review_requested_at'] );
it( 'projektenként egyszer', ! hpv_review_auto() && 1 === count( $GLOBALS['reviews'] ) );
$req = new WP_REST_Request( 'POST', "/hpv/v1/clients/$us/review-request" );
$req->set_body_params( array( 'project' => 'SEO' ) );
it( 'kézi kérés a CRM-ből', 'sent' === rest_do_request( $req )->get_data()['status'] && 2 === count( $GLOBALS['reviews'] ) );

echo "Takarítás\n";
foreach ( array( $us, $hu, $c['client_id'] ) as $cid ) {
	hpv_p_delete( 'client', $cid );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $mia->ID );
wp_delete_user( $maria->ID );
it( 'kész', ! hpv_p_find( 'invoice', array( 'client_id' => $us ) ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
