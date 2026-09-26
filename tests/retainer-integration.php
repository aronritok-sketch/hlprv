<?php
/**
 * Havidíjas (retainer) projektek integrációs tesztje: havi feladatcsomag, hónapok, portál.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen):
 *   wp eval-file tests/retainer-integration.php
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_retainer_run' ) ) {
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
add_filter( 'pre_http_request', fn() => new WP_Error( 'offline', 'offline' ), 10, 3 );

function rest( string $method, string $path, array $body = array() ) {
	$req = new WP_REST_Request( $method, '/hpv/v1' . $path );
	if ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $req );
}

$suffix = wp_generate_password( 5, false, false );
wp_set_current_user( 1 );
$client = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Kovács Kert $suffix", 'country' => 'HU', 'status' => 'active' ) ) );
$maria  = hpv_p_invite_user( $client, 'Kovács Mária', "maria_$suffix@kovacs.test" );

// Sablon: „Helyi SEO havi csomag” — a dátumok a sablon kezdőnapjához (2026-01-01) képest.
$tpl = hpv_p_insert( 'project', array( 'client_id' => 0, 'name' => "Helyi SEO havi $suffix", 'is_template' => 1, 'start_date' => '2026-01-01', 'kind' => 'local' ) );
foreach ( array( array( '4 Cégprofil poszt', '2026-01-10' ), array( 'Blogcikk', '2026-01-20' ), array( 'Havi riport', '2026-01-28' ) ) as $i => $t ) {
	hpv_p_insert( 'task', array( 'project_id' => $tpl, 'title' => $t[0], 'status' => 'todo', 'visible' => 1, 'sort' => $i, 'due_date' => $t[1] ) );
}

echo "Beállítás\n";
$res = rest( 'POST', '/pm/projects', array( 'name' => "Helyi SEO — Kovács Kert $suffix", 'client_id' => $client, 'kind' => 'local', 'visible' => 1, 'status' => 'in_progress' ) );
$pid = $res->get_data()['id'];
it( 'projekt típussal', 200 === $res->get_status() && 'local' === $res->get_data()['kind'] );
it( 'havi csomag csak sablon lehet', 400 === rest( 'POST', "/pm/projects/$pid", array( 'package_id' => $pid ) )->get_status() );
$d = rest( 'POST', "/pm/projects/$pid", array( 'package_id' => $tpl, 'package_day' => 40 ) )->get_data();
it( 'csomag és nap (legfeljebb 28.)', $tpl === $d['package_id'] && 28 === $d['package_day'] );
rest( 'POST', "/pm/projects/$pid", array( 'package_day' => 1 ) );
it( 'bootstrap: projekttípusok', (bool) array_filter( rest( 'GET', '/pm/bootstrap' )->get_data()['projectKinds'], fn( $k ) => 'ads' === $k['key'] ) );

echo "Havi futás\n";
$created = hpv_retainer_run( '2026-10-01' );
$oct     = hpv_p_find( 'task', array( 'project_id' => $pid, 'period' => '2026-10' ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
it( 'október 1.: a csomag elkészült', 3 === ( $created[ $pid ] ?? 0 ) && 3 === count( $oct ) );
it( 'a dátumok a hónaphoz igazodnak', '2026-10-10' === $oct[0]['due_date'] && '2026-10-28' === $oct[2]['due_date'] && 'todo' === $oct[0]['status'] );
it( 'a projekt jelölője', '2026-10' === hpv_p_get( 'project', $pid )['last_package'] );
it( 'ugyanabban a hónapban nem ismétlődik', ! hpv_retainer_run( '2026-10-15' ) && 3 === count( hpv_p_find( 'task', array( 'project_id' => $pid ) ) ) );
hpv_p_update( 'project', $pid, array( 'package_day' => 5 ) );
it( 'a megadott nap előtt nem készül', ! hpv_retainer_run( '2026-11-03' ) );
$created = hpv_retainer_run( '2026-11-05' );
$nov     = hpv_p_find( 'task', array( 'project_id' => $pid, 'period' => '2026-11' ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
it( 'november 5.: a kezdőnaphoz igazítva', 3 === count( $nov ) && '2026-11-14' === $nov[0]['due_date'] );
hpv_p_update( 'project', $pid, array( 'status' => 'on_hold' ) );
it( 'felfüggesztett projektnél nem készül', ! hpv_retainer_run( '2026-12-05' ) );
hpv_p_update( 'project', $pid, array( 'status' => 'in_progress' ) );

echo "Kézi csomag (REST)\n";
$res = rest( 'POST', "/pm/projects/$pid/package", array( 'period' => '2026-12' ) );
it( 'december kézzel', 200 === $res->get_status() && 3 === $res->get_data()['created'] );
it( 'ugyanaz a hónap kétszer: hiba', 400 === rest( 'POST', "/pm/projects/$pid/package", array( 'period' => '2026-12' ) )->get_status() );
it( 'a napi futás utána nem duplikál', ! hpv_retainer_run( '2026-12-06' ) && 3 === count( hpv_p_find( 'task', array( 'project_id' => $pid, 'period' => '2026-12' ) ) ) );
it( 'egyszeri projektnél nincs csomag', 400 === rest( 'POST', '/pm/projects/' . $tpl . '/package', array() )->get_status() );
$tasks = rest( 'GET', "/pm/projects/$pid" )->get_data()['tasks'];
it( 'a feladatok hónapja az appnak', count( array_filter( $tasks, fn( $t ) => '2026-11' === $t['period'] ) ) === 3 );

echo "Portál\n";
$nov_done = $nov[0]['id'];
hpv_p_update( 'task', (int) $nov_done, array( 'status' => 'done' ) );
wp_set_current_user( $maria->ID );
$_GET = array( 'view' => 'projects', 'id' => $pid );
$page = hpv_p_portal_app();
it( 'alapból a legutóbbi hónap (magyarul)', false !== strpos( $page, '2026. december' ) && 3 === substr_count( $page, 'class="hpv-task"' ) );
it( 'hónapválasztó', false !== strpos( $page, 'hpv-periods' ) && false !== strpos( $page, 'period=2026-10' ) );
$_GET = array( 'view' => 'projects', 'id' => $pid, 'period' => '2026-11' );
$page = hpv_p_portal_app();
it( 'korábbi hónap: 1/3 kész', false !== strpos( $page, '33%' ) );
$_GET = array( 'view' => 'projects' );
it( 'a listában a folyó hónap haladása', false !== strpos( hpv_p_portal_app(), '0% kész' ) );
$_GET = array();
wp_set_current_user( 1 );

echo "Takarítás\n";
hpv_p_delete( 'client', $client );
hpv_p_delete( 'project', $tpl );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $maria->ID );
it( 'kész', ! hpv_p_find( 'task', array( 'project_id' => $pid ) ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
