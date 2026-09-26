<?php
/**
 * Ügyfél-adatlap és szolgáltatás-katalógus (CRM app REST) integrációs tesztje.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen):
 *   wp eval-file tests/clients-integration.php
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_client_full' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}

$GLOBALS['hpv_it_fail'] = 0;
function it( $label, $ok ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$GLOBALS['hpv_it_fail']++;
	}
}
add_filter( 'pre_wp_mail', '__return_true' );
function call( string $method, string $path, array $params = array() ) {
	$req = new WP_REST_Request( $method, '/hpv/v1' . $path );
	if ( $params ) {
		$req->set_body_params( $params );
	}
	return rest_do_request( $req );
}

$suffix = strtolower( wp_generate_password( 5, false, false ) );
wp_set_current_user( 1 );

echo "Ügyfél létrehozása és szerkesztése\n";
$r = call( 'POST', '/clients', array( 'name' => '' ) );
it( 'név nélkül hiba', 400 === $r->get_status() );
$r = call( 'POST', '/clients', array( 'name' => "Duna Pék $suffix", 'email' => 'rossz-cím' ) );
it( 'érvénytelen e-mail hiba', 400 === $r->get_status() );
$r  = call( 'POST', '/clients', array( 'name' => "Duna Pék $suffix", 'email' => "info_$suffix@dunapek.test", 'country' => 'HU', 'tax_number' => '12345678-2-41', 'hourly_rate' => '15000', 'status' => 'active', 'id' => 999999 ) );
$c  = $r->get_data();
$id = (int) ( $c['id'] ?? 0 );
it( 'létrejön (a küldött id-t figyelmen kívül hagyja)', 200 === $r->get_status() && $id && 999999 !== $id && 'HU' === $c['country'] );
it( 'forint pénznem, óradíj számként', 'HUF' === $c['currency'] && 15000.0 === (float) $c['hourly_rate'] && '12345678-2-41' === $c['tax_number'] );

$r = call( 'GET', "/clients/$id" );
$d = $r->get_data();
it( 'adatlap: mezők a sémából, csoportokkal', 200 === $r->get_status() && in_array( 'billing', array_column( $d['fields'], 'group' ), true ) && in_array( 'sources', array_column( $d['fields'], 'group' ), true ) );
it( 'adatlap: számlák, előfizetések, szerződések (adminnak)', isset( $d['invoices'], $d['subscriptions'], $d['contracts'], $d['mrr'], $d['outstanding'] ) );
it( 'nem létező ügyfél: 404', 404 === call( 'GET', '/clients/99999999' )->get_status() );

$r = call( 'POST', "/clients/$id", array( 'country' => 'US', 'city' => 'Miami' ) );
it( 'ország váltása: dollár + naplóbejegyzés', 'USD' === $r->get_data()['currency'] && (bool) array_filter( $r->get_data()['activity'], fn( $a ) => false !== strpos( $a['body'], 'HU → US' ) ) );
it( 'a többi mező megmarad', "info_$suffix@dunapek.test" === $r->get_data()['email'] && 'Miami' === hpv_p_get( 'client', $id )['city'] );
it( 'nevet nem lehet üresre törölni', 400 === call( 'POST', "/clients/$id", array( 'name' => '' ) )->get_status() );

echo "Jegyzet, portál-hozzáférés\n";
it( 'üres jegyzet hiba', 400 === call( 'POST', "/clients/$id/notes", array( 'body' => '  ' ) )->get_status() );
$d = call( 'POST', "/clients/$id/notes", array( 'body' => 'Telefonon egyeztettünk.' ) )->get_data();
it( 'jegyzet az idővonalon, belső, szerzővel', 'note' === $d['activity'][0]['type'] && ! $d['activity'][0]['visible'] && '' !== $d['activity'][0]['author'] );
$d = call( 'POST', "/clients/$id/invite", array( 'name' => 'Pék Péter', 'email' => "peter_$suffix@dunapek.test" ) )->get_data();
$u = $d['users'][0] ?? null;
it( 'meghívás: felhasználó az adatlapon', $u && "peter_$suffix@dunapek.test" === $u['email'] && 0 === $u['last_login'] );
do_action( 'wp_login', get_userdata( $u['id'] )->user_login, get_userdata( $u['id'] ) );
it( 'utolsó belépés rögzítve', call( 'GET', "/clients/$id" )->get_data()['users'][0]['last_login'] > 0 );
$other = hpv_p_insert( 'client', array( 'name' => "Másik $suffix" ) );
it( 'más ügyfél felhasználóját nem lehet leválasztani', 400 === call( 'DELETE', "/clients/$other/users/{$u['id']}" )->get_status() );
$d = call( 'DELETE', "/clients/$id/users/{$u['id']}" )->get_data();
it( 'hozzáférés visszavonva', ! $d['users'] && ! hpv_p_user_client_id( $u['id'] ) );

echo "Jogosultságok\n";
$staff = wp_insert_user( array( 'user_login' => "staff_$suffix", 'user_pass' => wp_generate_password(), 'user_email' => "staff_$suffix@hpv.test", 'role' => 'hpv_staff' ) );
wp_set_current_user( $staff );
$d = call( 'GET', "/clients/$id" )->get_data();
it( 'számlázási jog nélkül: nincs adószám, óradíj, számla', ! isset( $d['tax_number'] ) && ! isset( $d['hourly_rate'] ) && ! isset( $d['invoices'] ) && ! in_array( 'billing', array_column( $d['fields'], 'group' ), true ) );
call( 'POST', "/clients/$id", array( 'tax_number' => 'X', 'website' => 'https://dunapek.test' ) );
$row = hpv_p_get( 'client', $id );
it( '…és nem is írhatja, a többit igen', '12345678-2-41' === $row['tax_number'] && 'https://dunapek.test' === $row['website'] );
it( 'szolgáltatásokhoz sem fér hozzá', 403 === call( 'GET', '/billing/services' )->get_status() );
wp_set_current_user( 0 );
it( 'kijelentkezve semmi', 401 === call( 'GET', "/clients/$id" )->get_status() && 401 === call( 'POST', '/clients', array( 'name' => 'x' ) )->get_status() );
wp_set_current_user( 1 );

echo "Szolgáltatás-katalógus\n";
it( 'név nélkül hiba', 400 === call( 'POST', '/billing/services', array( 'price' => '10' ) )->get_status() );
$s = call( 'POST', '/billing/services', array( 'name' => "SEO havidíj $suffix", 'price' => '450', 'billing' => 'monthly' ) )->get_data();
it( 'létrejön, aktív', $s['id'] && 450.0 === (float) $s['price'] && 'monthly' === $s['billing'] && $s['active'] && 0 === $s['in_use'] );
hpv_p_insert( 'subscription', array( 'client_id' => $id, 'service_id' => $s['id'], 'name' => 'SEO', 'status' => 'active', 'price' => '450', 'billing' => 'monthly', 'next_invoice_date' => '2026-10-01' ) );
$list = call( 'GET', '/billing/services' )->get_data();
$row  = array_values( array_filter( $list, fn( $x ) => $x['id'] === $s['id'] ) )[0] ?? null;
it( 'listában, használatban lévő előfizetéssel', $row && 1 === $row['in_use'] );
$s2 = call( 'POST', "/billing/services/{$s['id']}", array( 'active' => '0', 'price' => '500' ) )->get_data();
it( 'szerkesztés: ár, archiválás', ! $s2['active'] && 500.0 === (float) $s2['price'] && $s2['name'] === $s['name'] );
it( 'nem létező: 404', 404 === call( 'POST', '/billing/services/99999999', array( 'name' => 'x' ) )->get_status() );
it( 'adatlapon az MRR', false !== strpos( wp_json_encode( call( 'GET', "/clients/$id" )->get_data()['mrr'] ), '450' ) );

echo "Takarítás\n";
foreach ( array( $id, $other ) as $cid ) {
	hpv_p_delete( 'client', $cid );
}
hpv_p_delete( 'service', $s['id'] );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $u['id'] );
wp_delete_user( $staff );
it( 'kész', ! hpv_p_get( 'client', $id ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
