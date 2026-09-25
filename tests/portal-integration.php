<?php
/**
 * A HelloProVision Client Portal & CRM integrációs tesztje valódi WordPressen.
 * Futtatás (a bővítmény aktív egy teszt WordPressen):
 *   wp eval-file tests/portal-integration.php
 *
 * Minden adatot maga hoz létre, a végén törli. Kimenő levelet nem küld (elfogja).
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_p_insert' ) ) {
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

// Levelek elfogása: nem megy ki semmi, de ellenőrizzük, mi menne.
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['hpv_it_mail'][] = $atts;
		return true;
	},
	10,
	2
);

function mails_to( string $email ): array {
	return array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
}

$suffix  = wp_generate_password( 6, false, false );
$cleanup = array( 'clients' => array(), 'users' => array() );

// Munkatárs
$staff_id = wp_insert_user( array( 'user_login' => 'staff_' . $suffix, 'user_email' => "staff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff', 'display_name' => 'Anna Staff' ) );
$cleanup['users'][] = $staff_id;
wp_set_current_user( $staff_id );

echo "Szerepkörök\n";
it( 'munkatárs kezelheti a CRM-et', hpv_p_is_staff( $staff_id ) );
it( 'adminisztrátor kezelheti a CRM-et', user_can( 1, 'hpv_manage_crm' ) );

echo "Ügyfelek, meghívó\n";
$client_a = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Acme Pools ' . $suffix, 'email' => "office_$suffix@acme.test", 'status' => 'active', 'address' => "1 Main St\nCape Coral, FL 33904" ) ) );
$client_b = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Beta Roofing ' . $suffix, 'status' => 'active' ) ) );
$cleanup['clients'] = array( $client_a, $client_b );
it( 'ügyfél létrejött', (bool) hpv_p_get( 'client', $client_a ) );
it( 'ismeretlen státusz → alapérték', 'active' === hpv_p_sanitize( 'client', array( 'status' => 'hacked' ) )['status'] );

$maria = hpv_p_invite_user( $client_a, 'Maria Lopez', "maria_$suffix@acme.test" );
$bob   = hpv_p_invite_user( $client_b, 'Bob Beta', "bob_$suffix@beta.test" );
$cleanup['users'][] = $maria->ID;
$cleanup['users'][] = $bob->ID;
it( 'meghívott felhasználó ügyfél szerepkörrel', in_array( 'hpv_client', $maria->roles, true ) );
it( 'felhasználó az ügyfélhez kötve', hpv_p_user_client_id( $maria->ID ) === $client_a );
it( 'meghívó levél jelszó-beállító linkkel', 1 === count( mails_to( "maria_$suffix@acme.test" ) ) && false !== strpos( mails_to( "maria_$suffix@acme.test" )[0]['message'], 'action=rp' ) );
it( 'másik ügyfél felhasználóját nem lehet átvenni', is_wp_error( hpv_p_invite_user( $client_a, 'Bob', "bob_$suffix@beta.test" ) ) );
it( 'munkatárs nem lehet ügyfél-felhasználó', is_wp_error( hpv_p_invite_user( $client_a, 'Anna', "staff_$suffix@example.test" ) ) );
it( 'ügyfél nem kezelheti a CRM-et', ! hpv_p_is_staff( $maria->ID ) );

echo "Szolgáltatások, projektek\n";
$service = hpv_p_insert( 'service', hpv_p_sanitize( 'service', array( 'name' => 'Local SEO Plan', 'price' => '750', 'billing' => 'monthly', 'active' => 1 ) ) );
hpv_p_insert( 'subscription', hpv_p_sanitize( 'subscription', array( 'client_id' => $client_a, 'service_id' => $service, 'name' => 'Local SEO Plan', 'price' => '750', 'billing' => 'monthly', 'status' => 'active' ) ) );
hpv_p_insert( 'subscription', hpv_p_sanitize( 'subscription', array( 'client_id' => $client_a, 'name' => 'Hosting', 'price' => '1200', 'billing' => 'yearly', 'status' => 'active' ) ) );
it( 'MRR: 750 + 1200/12 = 850', 85000 === hpv_p_mrr( hpv_p_find( 'subscription', array( 'client_id' => $client_a ) ) ) );

$project = hpv_p_insert( 'project', hpv_p_sanitize( 'project', array( 'client_id' => $client_a, 'name' => 'Website redesign', 'status' => 'in_progress', 'visible' => 1 ) ) );
$hidden  = hpv_p_insert( 'project', hpv_p_sanitize( 'project', array( 'client_id' => $client_a, 'name' => 'Internal audit', 'visible' => 0 ) ) );
foreach ( array( array( 'Sitemap', 'done', 1 ), array( 'Wireframes', 'done', 1 ), array( 'Approve homepage', 'client', 1 ), array( 'Dev QA notes', 'todo', 0 ) ) as $i => $t ) {
	hpv_p_insert( 'task', hpv_p_sanitize( 'task', array( 'project_id' => $project, 'title' => $t[0], 'status' => $t[1], 'visible' => $t[2], 'sort' => $i ) ) );
}
$portal_tasks = hpv_p_portal_tasks( $project );
it( 'rejtett feladat nem látszik a portálon', 3 === count( $portal_tasks ) );
it( 'haladás a látható feladatokból: 2/3 = 67%', 67 === hpv_p_project_progress( $portal_tasks ) );
it( 'rejtett projekt nem látszik a portálon', array( $project ) === array_map( 'intval', array_column( hpv_p_portal_projects( $client_a ), 'id' ) ) );

echo "Számlák\n";
$invoice = hpv_p_insert( 'invoice', hpv_p_sanitize( 'invoice', array( 'client_id' => $client_a, 'status' => 'draft', 'tax_rate' => '6.5', 'issue_date' => '2026-09-01', 'due_date' => '2026-09-16' ) ) );
hpv_p_save_invoice_items(
	$invoice,
	array(
		array( 'description' => 'Website design', 'quantity' => '1', 'unit_price' => '2,400.00' ),
		array( 'description' => 'Extra page', 'quantity' => '2.5', 'unit_price' => '99.99' ),
		array( 'description' => '', 'quantity' => '1', 'unit_price' => '5' ),
	)
);
$number = hpv_p_assign_invoice_number( $invoice );
$inv    = hpv_p_get( 'invoice', $invoice );
it( 'üres tétel kimarad', 2 === count( hpv_p_find( 'invoice_item', array( 'invoice_id' => $invoice ) ) ) );
it( 'nettó: 2400 + 2.5×99.99 = 2649.98', '2649.98' === $inv['subtotal'] );
it( 'adó 6.5% = 172.25', '172.25' === $inv['tax'] );
it( 'végösszeg 2822.23', '2822.23' === $inv['total'] );
it( 'számlaszám kiosztva', (bool) preg_match( '/^HPV-\d{4}$/', $number ) );
it( 'számlaszám nem változik újra mentéskor', hpv_p_assign_invoice_number( $invoice ) === $number );
it( 'piszkozat nem látszik a portálon', null === hpv_p_portal_get( 'invoice', $invoice, $client_a ) );
hpv_p_update( 'invoice', $invoice, array( 'status' => 'sent' ) );
hpv_p_event_invoice_sent( hpv_p_get( 'invoice', $invoice ) );
it( 'kiküldött számla látszik a portálon', (bool) hpv_p_portal_get( 'invoice', $invoice, $client_a ) );
it( 'másik ügyfél nem látja a számlát', null === hpv_p_portal_get( 'invoice', $invoice, $client_b ) );
it( 'számla levél az ügyfélnek', 1 === count( array_filter( mails_to( "maria_$suffix@acme.test" ), fn( $m ) => false !== strpos( $m['subject'], $number ) ) ) );
it( 'lejárt számla felismerése', hpv_p_invoice_is_overdue( hpv_p_get( 'invoice', $invoice ), '2026-09-20' ) );

echo "Szerződés aláírás\n";
$body     = '<h2>Scope</h2><p>We will redesign the website.</p><script>alert(1)</script>';
$contract = hpv_p_insert( 'contract', hpv_p_sanitize( 'contract', array( 'client_id' => $client_a, 'title' => 'Website Agreement', 'body' => $body, 'status' => 'draft' ) ) );
it( 'szerződésből a script kiszűrve', false === strpos( hpv_p_get( 'contract', $contract )['body'], '<script' ) );
it( 'piszkozatot nem lehet aláírni', is_wp_error( hpv_p_sign_contract( $contract, $client_a, $maria, 'Maria Lopez' ) ) );
hpv_p_update( 'contract', $contract, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ) );
it( 'másik ügyfél nem írhatja alá', is_wp_error( hpv_p_sign_contract( $contract, $client_b, $bob, 'Bob Beta' ) ) );
it( 'túl rövid név elutasítva', is_wp_error( hpv_p_sign_contract( $contract, $client_a, $maria, 'M' ) ) );
$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'IntegrationTest/1.0';
$signed                     = hpv_p_sign_contract( $contract, $client_a, $maria, 'Maria Lopez' );
it( 'aláírva', ! is_wp_error( $signed ) && 'signed' === $signed['status'] );
it( 'audit adatok rögzítve', '203.0.113.7' === $signed['signer_ip'] && "maria_$suffix@acme.test" === $signed['signer_email'] && 'Maria Lopez' === $signed['signer_name'] );
it( 'dokumentum lenyomat egyezik', hpv_p_contract_hash( $signed['body'] ) === $signed['body_hash'] );
it( 'kétszer nem írható alá', is_wp_error( hpv_p_sign_contract( $contract, $client_a, $maria, 'Maria Lopez' ) ) );

echo "Chat\n";
$channel = hpv_chat_client_channel( $client_a, true );
it( 'ügyfél-csatorna létrejött', $channel && 'client' === $channel['type'] );
it( 'meghívott ügyfél tagja a csatornának', (bool) hpv_chat_member( (int) $channel['id'], $maria->ID ) );
it( 'másik ügyfél nem olvashatja', ! hpv_chat_can_read( $channel, $bob->ID ) );
hpv_chat_add_member( (int) $channel['id'], $bob->ID );
it( 'rossz cég felhasználója tagként sem olvashat', ! hpv_chat_can_read( $channel, $bob->ID ) );
hpv_chat_remove_member( (int) $channel['id'], $bob->ID );

$m1 = hpv_chat_post( (int) $channel['id'], $staff_id, "Hi Maria, homepage draft is ready:\nhttps://example.test/draft" );
it( 'munkatárs üzenete elküldve', is_array( $m1 ) );
it( 'ügyfélnek 1 olvasatlan', 1 === hpv_chat_total_unread( $maria->ID ) );
it( 'ügyfél e-mailt kap (nem volt online)', 1 === count( array_filter( mails_to( "maria_$suffix@acme.test" ), fn( $m ) => false !== strpos( $m['subject'], 'New message' ) ) ) );
hpv_chat_post( (int) $channel['id'], $staff_id, 'One more thing' );
it( '15 percen belül nem megy újabb e-mail', 1 === count( array_filter( mails_to( "maria_$suffix@acme.test" ), fn( $m ) => false !== strpos( $m['subject'], 'New message' ) ) ) );

wp_set_current_user( $maria->ID );
$request = new WP_REST_Request( 'GET', '/hpv/v1/chat/channels/' . $channel['id'] . '/messages' );
$response = rest_do_request( $request );
it( 'ügyfél REST-en olvassa a csatornát', 200 === $response->get_status() && 2 === count( $response->get_data()['messages'] ) );
it( 'olvasás után 0 olvasatlan', 0 === hpv_chat_total_unread( $maria->ID ) );
$post = new WP_REST_Request( 'POST', '/hpv/v1/chat/channels/' . $channel['id'] . '/messages' );
$post->set_param( 'body', 'Looks great, thanks!' );
it( 'ügyfél válaszol REST-en', 200 === rest_do_request( $post )->get_status() );
$create = new WP_REST_Request( 'POST', '/hpv/v1/chat/channels' );
$create->set_param( 'name', 'Hack' );
it( 'ügyfél nem hozhat létre csatornát', 403 === rest_do_request( $create )->get_status() || 401 === rest_do_request( $create )->get_status() );

wp_set_current_user( $bob->ID );
it( 'másik ügyfél REST-en sem olvashatja', 403 === rest_do_request( $request )->get_status() );
it( 'másik ügyfél listájában nem szerepel', ! in_array( (int) $channel['id'], array_column( rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/chat/channels' ) )->get_data()['channels'], 'id' ), true ) );

wp_set_current_user( $staff_id );
$group = new WP_REST_Request( 'POST', '/hpv/v1/chat/channels' );
$group->set_param( 'name', 'Design team ' . $suffix );
$group->set_param( 'members', array( 1 ) );
$group_res = rest_do_request( $group );
it( 'munkatárs belső csoportot hoz létre', 200 === $group_res->get_status() && 'internal' === $group_res->get_data()['type'] );
$group_id = (int) $group_res->get_data()['id'];
$add      = new WP_REST_Request( 'POST', '/hpv/v1/chat/channels/' . $group_id . '/members' );
$add->set_param( 'add', array( $maria->ID ) );
rest_do_request( $add );
it( 'ügyfél nem vehető fel belső csoportba', null === hpv_chat_member( $group_id, $maria->ID ) );

echo "Visszavonás, törlés\n";
do_action( 'hpv_p_user_revoked', $client_a, $maria->ID );
delete_user_meta( $maria->ID, 'hpv_client_id' );
it( 'visszavont ügyfél kikerül a csatornából', null === hpv_chat_member( (int) $channel['id'], $maria->ID ) );

hpv_p_delete( 'channel', $group_id );
foreach ( $cleanup['clients'] as $cid ) {
	hpv_p_delete( 'client', $cid );
}
hpv_p_delete( 'service', $service );
it( 'ügyfél törlése a kapcsolódó adatokat is törli', ! hpv_p_find( 'invoice', array( 'client_id' => $client_a ) ) && ! hpv_p_find( 'invoice_item', array( 'invoice_id' => $invoice ) ) && ! hpv_p_find( 'task', array( 'project_id' => $project ) ) && ! hpv_p_find( 'chat_message', array( 'channel_id' => $channel['id'] ) ) );

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $uid ) {
	wp_delete_user( $uid );
}

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
