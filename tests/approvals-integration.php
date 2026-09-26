<?php
/**
 * Jóváhagyások integrációs tesztje: CRM REST, kiküldés, portál döntés, feladat-státusz, emlékeztető, SEO OS upsert.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen):
 *   wp eval-file tests/approvals-integration.php
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_approval_upsert' ) ) {
	echo "A bővítmény nincs betöltve.\n";
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
add_filter( 'pre_http_request', fn() => new WP_Error( 'offline', 'offline' ), 10, 3 );

function mails_to( string $email ): array {
	return array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
}
function rest( string $method, string $path, array $body = array() ) {
	$parts = explode( '?', $path, 2 );
	$req   = new WP_REST_Request( $method, '/hpv/v1' . $parts[0] );
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $q );
		$req->set_query_params( $q );
	}
	if ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $req );
}
function portal_post( WP_User $u, array $post ): string {
	wp_set_current_user( $u->ID );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array_merge( $post, array( '_wpnonce' => wp_create_nonce( 'hpv_approval_' . $post['approval_id'] ) ) );
	$_REQUEST                  = $_POST;
	$loc                       = '';
	$catch                     = function ( $location ) use ( &$loc ) {
		$loc = $location;
		throw new Exception( 'redirect' );
	};
	add_filter( 'wp_redirect', $catch, 1 );
	try {
		hpv_approval_portal_post();
	} catch ( Exception $e ) {
		unset( $e );
	}
	remove_filter( 'wp_redirect', $catch, 1 );
	$_POST                     = array();
	$_REQUEST                  = array();
	$_SERVER['REQUEST_METHOD'] = 'GET';
	wp_set_current_user( 1 );
	return $loc;
}
function portal_as( WP_User $u, array $get ): string {
	wp_set_current_user( $u->ID );
	$_GET = $get;
	$html = hpv_p_portal_app();
	$_GET = array();
	wp_set_current_user( 1 );
	return $html;
}

$suffix   = wp_generate_password( 5, false, false );
$staff_id = wp_insert_user( array( 'user_login' => 'astaff_' . $suffix, 'user_email' => "astaff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff', 'display_name' => 'Peter Project' ) );
wp_set_current_user( 1 );
$hu     = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Kovács Kert $suffix", 'country' => 'HU', 'status' => 'active' ) ) );
$us     = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Sun Pools $suffix", 'status' => 'active' ) ) );
$maria  = hpv_p_invite_user( $hu, 'Kovács Mária', "maria_$suffix@kovacs.test" );
$mia    = hpv_p_invite_user( $us, 'Mia Sun', "mia_$suffix@sunpools.test" );
$proj   = hpv_p_insert( 'project', array( 'client_id' => $hu, 'name' => 'Közösségi média', 'status' => 'in_progress', 'visible' => 1, 'kind' => 'social' ) );
$task   = hpv_p_insert( 'task', array( 'project_id' => $proj, 'title' => 'Októberi posztok jóváhagyása', 'status' => 'in_progress', 'visible' => 1 ) );
$other  = hpv_p_insert( 'project', array( 'client_id' => $us, 'name' => 'Other', 'status' => 'in_progress' ) );
$GLOBALS['hpv_it_mail'] = array();

echo "Létrehozás (CRM)\n";
wp_set_current_user( $maria->ID );
it( 'ügyfél nem éri el a REST-et', 403 === rest( 'GET', '/content' )->get_status() );
wp_set_current_user( $staff_id );
it( 'cím nélkül: hiba', 400 === rest( 'POST', '/content', array( 'client_id' => $hu ) )->get_status() );
it( 'más ügyfél projektje: hiba', 400 === rest( 'POST', '/content', array( 'client_id' => $hu, 'title' => 'x', 'project_id' => $other ) )->get_status() );
$res = rest( 'POST', '/content', array( 'client_id' => $hu, 'project_id' => $proj, 'task_id' => $task, 'type' => 'social', 'title' => 'Őszi akció poszt', 'channel' => 'Facebook', 'publish_date' => '2026-10-05', 'body' => '<p style="color:red" onclick="x()">Ősszel <strong>20%</strong> kedvezmény!</p><script>alert(1)</script>' ) );
$a   = $res->get_data();
it( 'piszkozat, megtisztított szöveg', 200 === $res->get_status() && 'draft' === $a['status'] && false === strpos( $a['body'], 'script' ) && false === strpos( $a['body'], 'onclick' ) && false !== strpos( $a['body'], '<strong>20%</strong>' ) );
$id = $a['id'];
$fid = hpv_p_insert( 'file', array( 'client_id' => $hu, 'name' => 'akcio.png', 'storage_key' => 'files/' . $hu . '/' . str_repeat( 'a', 32 ) . '.png', 'mime' => 'image/png', 'size' => 10, 'visible' => 0, 'source' => 'staff' ) );
$a = rest( 'POST', "/content/$id", array( 'file_ids' => (string) $fid ) )->get_data();
it( 'csatolt kép', 1 === count( $a['files'] ) && 'akcio.png' === $a['files'][0]['name'] );
it( 'portál: a piszkozat nem látszik', false === strpos( portal_as( $maria, array( 'view' => 'approvals' ) ), 'Őszi akció' ) );
$todo = rest( 'POST', '/content', array( 'client_id' => $hu, 'title' => 'Cikk', 'body' => '<p>[[TODO: ár]]</p>' ) )->get_data();
it( '[[TODO]] jelöléssel nem küldhető', 400 === rest( 'POST', '/content/' . $todo['id'] . '/send' )->get_status() );

echo "Kiküldés\n";
$a = rest( 'POST', "/content/$id/send" )->get_data();
it( 'jóváhagyásra vár, 1. kör', 'pending' === $a['status'] && 1 === $a['round'] );
it( 'a feladat „Ügyfélre vár”', 'client' === hpv_p_get( 'task', $task )['status'] );
it( 'a csatolt kép láthatóvá vált', 1 === (int) hpv_p_get( 'file', $fid )['visible'] );
$mail = mails_to( "maria_$suffix@kovacs.test" );
it( 'magyar e-mail az ügyfélnek, portál linkkel', 1 === count( $mail ) && false !== strpos( $mail[0]['subject'], 'Jóváhagyásra vár' ) && false !== strpos( $mail[0]['message'], 'view=approvals' ) && false !== strpos( $mail[0]['message'], '2026. október 5.' ) );
it( 'csak ez az egy levél (a feladat miatt nem jön második)', 1 === count( $mail ) );

echo "Portál\n";
$page = portal_as( $maria, array() );
it( 'teendő az áttekintésen, számláló a menüben', false !== strpos( $page, 'Jóváhagyás: Őszi akció poszt' ) && (bool) preg_match( '/Jóváhagyás<\/span>\s*<em class="hpv-count">1</', $page ) );
$page = portal_as( $maria, array( 'view' => 'approvals', 'id' => $id ) );
it( 'részletek: szöveg, kép, döntés űrlap', false !== strpos( $page, '20%' ) && false !== strpos( $page, 'hpv_file=' . $fid ) && false !== strpos( $page, 'Jóváhagyom' ) && false !== strpos( $page, 'Javítást kérek' ) );
it( 'másik ügyfél nem látja', false !== strpos( portal_as( $mia, array( 'view' => 'approvals', 'id' => $id ) ), 'Not found' ) );
$loc = portal_post( $maria, array( 'hpv_portal_action' => 'approval_decide', 'approval_id' => $id, 'decision' => 'changes', 'note' => '' ) );
it( 'javításkérés megjegyzés nélkül: hiba', false !== strpos( $loc, 'error=' ) && 'pending' === hpv_p_get( 'approval', $id )['status'] );
$GLOBALS['hpv_it_mail'] = array();
$loc = portal_post( $maria, array( 'hpv_portal_action' => 'approval_decide', 'approval_id' => $id, 'decision' => 'changes', 'note' => "Legyen 25%!\nÉs más kép." ) );
$row = hpv_p_get( 'approval', $id );
it( 'javítást kér', false !== strpos( $loc, 'decided=changes' ) && 'changes' === $row['status'] && false !== strpos( $row['decision_note'], '25%' ) );
it( 'a feladat vissza „Folyamatban”', 'in_progress' === hpv_p_get( 'task', $task )['status'] );
it( 'a csapat e-mailt kap', (bool) array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => 0 === strpos( $m['subject'], 'Javítást kér: Őszi akció poszt' ) ) );
$log = hpv_p_find( 'activity', array( 'client_id' => $hu, 'visible' => 1 ), array( 'limit' => 1 ) );
it( 'idővonal magyarul', $log && false !== strpos( $log[0]['body'], 'javítást kért' ) );
it( 'a CRM app jelzője', 1 <= rest( 'GET', '/pm/bootstrap' )->get_data()['approvalsAttention'] );
it( 'másodszor nem dönthet', false !== strpos( portal_post( $maria, array( 'hpv_portal_action' => 'approval_decide', 'approval_id' => $id, 'decision' => 'approve', 'note' => '' ) ), 'error=' ) );

echo "Második kör\n";
wp_set_current_user( $staff_id );
$a = rest( 'POST', "/content/$id", array( 'body' => '<p>Ősszel <strong>25%</strong> kedvezmény!</p>' ) )->get_data();
it( 'javítás mentve, még „javítást kért”', 'changes' === $a['status'] && false !== strpos( $a['body'], '25%' ) );
$a = rest( 'POST', "/content/$id/send" )->get_data();
it( 'újraküldés: 2. kör', 'pending' === $a['status'] && 2 === $a['round'] );
portal_post( $maria, array( 'hpv_portal_action' => 'approval_comment', 'approval_id' => $id, 'note' => 'Így jó lesz szerintem.' ) );
$loc = portal_post( $maria, array( 'hpv_portal_action' => 'approval_decide', 'approval_id' => $id, 'decision' => 'approve', 'note' => '' ) );
it( 'jóváhagyva', false !== strpos( $loc, 'decided=approved' ) && 'approved' === hpv_p_get( 'approval', $id )['status'] );
it( 'a feladat kész', 'done' === hpv_p_get( 'task', $task )['status'] && hpv_p_get( 'task', $task )['completed_at'] );
wp_set_current_user( $staff_id );
it( 'jóváhagyott tartalom nem írható át', 400 === rest( 'POST', "/content/$id", array( 'body' => '<p>más</p>' ) )->get_status() );
$a = rest( 'GET', "/content/$id" )->get_data();
it( 'előzmény: küldés, javításkérés, küldés, hozzászólás, jóváhagyás', array( 'sent', 'changes', 'sent', 'comment', 'approved' ) === array_column( $a['thread'], 'kind' ) );
it( 'megjelentnek jelölés', 'published' === rest( 'POST', "/content/$id/published" )->get_data()['status'] );
it( 'kiküldött nem törölhető', 400 === rest( 'DELETE', "/content/$id" )->get_status() );

echo "Lista és naptár\n";
$list = rest( 'GET', '/content?client_id=' . $hu . '&month=2026-10' )->get_data();
it( 'a hónap anyagai + a dátum nélküliek', 2 === count( $list['items'] ) && $list['types'] );
$list = rest( 'GET', '/content?client_id=' . $hu . '&month=2026-11' )->get_data();
it( 'más hónap: csak a dátum nélküli', 1 === count( $list['items'] ) );

echo "Emlékeztető\n";
$r = hpv_approval_upsert( array( 'client_id' => $us, 'title' => 'Google Ads copy', 'type' => 'ad', 'body' => '<p>Pool cleaning from $99</p>' ), 0, $staff_id );
hpv_approval_send( (int) $r['id'], $staff_id );
hpv_p_update( 'approval', (int) $r['id'], array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ) );
$GLOBALS['hpv_it_mail'] = array();
it( '3 nap után egyszer emlékeztet (angolul)', 1 === hpv_approval_reminders() && 0 === strpos( mails_to( "mia_$suffix@sunpools.test" )[0]['subject'] ?? '', 'Reminder: Please review' ) );
it( 'másodszor nem', 0 === hpv_approval_reminders() );

echo "SEO OS (külső forrás)\n";
$hooked = array();
add_action( 'hpv_approval_decided', function ( $ap, $status ) use ( &$hooked ) { $hooked[] = array( $ap['external_ref'], $status ); }, 10, 2 );
$x1 = hpv_approval_upsert( array( 'client_id' => $hu, 'title' => 'Kulcsszókutatás', 'type' => 'document', 'source' => 'seo-os', 'external_ref' => 'doc-42', 'link' => 'https://seo.example.test/doc/42' ), 0, $staff_id );
$x2 = hpv_approval_upsert( array( 'client_id' => $hu, 'title' => 'Kulcsszókutatás v2', 'source' => 'seo-os', 'external_ref' => 'doc-42' ), 0, $staff_id );
it( 'ugyanarra a hivatkozásra frissít, nem duplikál', (int) $x1['id'] === (int) $x2['id'] && 'Kulcsszókutatás v2' === $x2['title'] && 'seo-os' === $x2['source'] );
hpv_approval_send( (int) $x2['id'], $staff_id );
hpv_approval_decide( (int) $x2['id'], $hu, $maria->ID, 'approve', '' );
it( 'a döntésről action megy (SEO OS értesül)', array( array( 'doc-42', 'approved' ) ) === $hooked );
wp_set_current_user( $staff_id );
$res = rest( 'POST', '/content', array( 'client_id' => $hu, 'title' => 'Struktúra', 'source' => 'seo-os', 'external_ref' => 'doc-43' ) );
it( 'REST-en is (source + external_ref)', 200 === $res->get_status() && 'seo-os' === $res->get_data()['source'] );

echo "Takarítás\n";
wp_set_current_user( 1 );
hpv_p_delete( 'client', $hu );
hpv_p_delete( 'client', $us );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $staff_id, $maria->ID, $mia->ID ) as $uid ) {
	wp_delete_user( $uid );
}
it( 'ügyféllel együtt törlődik', ! hpv_p_find( 'approval', array( 'client_id' => $hu ) ) && ! hpv_p_find( 'approval_comment', array( 'approval_id' => $id ) ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
