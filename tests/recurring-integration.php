<?php
/**
 * Ismétlődő számlák integrációs tesztje valódi WordPressen.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen, számlázási kulcsok NINCSENEK beállítva):
 *   wp eval-file tests/recurring-integration.php
 * Kifelé semmi nem megy (pre_http_request), levelet sem küld (pre_wp_mail).
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_recurring_run' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}
if ( defined( 'HPV_SZAMLAZZ_AGENT_KEY' ) || defined( 'HPV_QBO_CLIENT_ID' ) ) {
	echo "Ezt a tesztet számlázási kulcsok nélküli teszt WordPressen futtasd.\n";
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
add_filter( 'pre_http_request', fn() => new WP_Error( 'offline', 'A teszt nem hív külső szolgáltatást.' ), 10, 3 );

$suffix = wp_generate_password( 6, false, false );
wp_set_current_user( 1 );

// Az éles adatok előfizetései ne zavarjanak: a teszt idejére szüneteltetjük őket.
$paused = array();
foreach ( hpv_p_find( 'subscription', array( 'status' => 'active' ), array( 'limit' => 5000 ) ) as $sub ) {
	$paused[] = (int) $sub['id'];
	hpv_p_update( 'subscription', (int) $sub['id'], array( 'status' => 'paused' ) );
}

echo "Dátumok\n";
it( 'havi: jan 31 → feb 28 → márc 31 (kezdőnap megmarad)', '2027-02-28' === hpv_p_next_billing_date( '2027-01-31', 'monthly', 31 ) && '2027-03-31' === hpv_p_next_billing_date( '2027-02-28', 'monthly', 31 ) );
it( 'kezdőnap nélkül a régi viselkedés', '2027-03-28' === hpv_p_next_billing_date( '2027-02-28', 'monthly' ) );
it( 'negyedéves és éves', '2027-01-15' === hpv_p_next_billing_date( '2026-10-15', 'quarterly', 15 ) && '2027-10-15' === hpv_p_next_billing_date( '2026-10-15', 'yearly', 15 ) );
it( 'időszak neve angolul', 'October 2026' === hpv_p_period_label( '2026-10-01', 'monthly', 'en' ) && 'Nov 2026 – Jan 2027' === hpv_p_period_label( '2026-11-01', 'quarterly', 'en' ) );
it( 'időszak neve magyarul', '2026. október' === hpv_p_period_label( '2026-10-01', 'monthly', 'hu' ) && '2026. október – december' === hpv_p_period_label( '2026-10-01', 'quarterly', 'hu' ) );

$us = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Sun Pools LLC ' . $suffix, 'email' => "office_$suffix@sunpools.test", 'status' => 'active' ) ) );
$hu = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Kovács Kert Kft ' . $suffix, 'country' => 'HU', 'email' => "iroda_$suffix@kovacs.test", 'status' => 'active' ) ) );
$mia = hpv_p_invite_user( $us, 'Mia Sun', "mia_$suffix@sunpools.test" );

function sub( int $client_id, string $name, string $price, string $billing, string $next, string $status = 'active', string $start = '' ): int {
	return hpv_p_insert(
		'subscription',
		array(
			'client_id'         => $client_id,
			'name'              => $name,
			'price'             => $price,
			'billing'           => $billing,
			'status'            => $status,
			'start_date'        => $start ?: $next,
			'next_invoice_date' => $next,
		)
	);
}

$s_seo   = sub( $us, 'SEO Growth', '750', 'monthly', '2026-10-01' );
$s_host  = sub( $us, 'Hosting', '360', 'yearly', '2026-10-01' );
$s_setup = sub( $us, 'Setup', '500', 'one_time', '2026-10-01' );
$s_later = sub( $us, 'Ads management', '400', 'monthly', '2026-10-20' );
$s_pause = sub( $us, 'Paused plan', '99', 'monthly', '2026-10-01', 'paused' );
$s_hu    = sub( $hu, 'Karbantartás', '25000', 'monthly', '2026-09-01' ); // egy hónapot lekésett: kettő jön

echo "Piszkozat mód\n";
$GLOBALS['hpv_it_mail'] = array(); // a meghívó levele nem számít
$res = hpv_recurring_run( '2026-10-01', 'draft' );
it( 'két ügyfél, két számla', 2 === count( $res['created'] ) );
$inv_us = hpv_p_find( 'invoice', array( 'client_id' => $us ) );
$inv_hu = hpv_p_find( 'invoice', array( 'client_id' => $hu ) );
it( 'ügyfelenként egy számla', 1 === count( $inv_us ) && 1 === count( $inv_hu ) );
$inv_us = $inv_us[0];
$inv_hu = $inv_hu[0];
$items  = array_column( hpv_p_find( 'invoice_item', array( 'invoice_id' => (int) $inv_us['id'] ), array( 'orderby' => 'sort', 'order' => 'ASC' ) ), 'description' );
sort( $items );
it( 'a tételek: esedékes előfizetések, időszakkal', array( 'Hosting — Oct 2026 – Sep 2027', 'SEO Growth — October 2026', 'Setup' ) === $items );
it( 'szüneteltetett és később esedékes nem kerül rá', ! array_filter( $items, fn( $d ) => false !== strpos( $d, 'Paused' ) || false !== strpos( $d, 'Ads' ) ) );
it( 'USA: USD, végösszeg, piszkozat, van sorszám', 'USD' === $inv_us['currency'] && 161000 === hpv_p_to_cents( $inv_us['total'] ) && 'draft' === $inv_us['status'] && '' !== (string) $inv_us['number'] );
it( 'USA: fizetési határidő a beállítás szerint', gmdate( 'Y-m-d', strtotime( '2026-10-01 +' . (int) hpv_p_settings()['payment_terms'] . ' days' ) ) === $inv_us['due_date'] );
$hu_items = array_column( hpv_p_find( 'invoice_item', array( 'invoice_id' => (int) $inv_hu['id'] ), array( 'orderby' => 'sort', 'order' => 'ASC' ) ), 'description' );
it( 'Magyar: a lekésett hónap is (magyar időszaknév)', array( 'Karbantartás — 2026. szeptember', 'Karbantartás — 2026. október' ) === $hu_items );
it( 'Magyar: HUF, ÁFA a beállításból, sorszám majd a Számlázz.hu-tól', 'HUF' === $inv_hu['currency'] && (float) $inv_hu['tax_rate'] === (float) hpv_p_vat_rate( hpv_p_settings()['hu_vat_key'] ) && '' === (string) $inv_hu['number'] );
it( 'következő dátumok előreléptek', '2026-11-01' === hpv_p_get( 'subscription', $s_seo )['next_invoice_date'] && '2027-10-01' === hpv_p_get( 'subscription', $s_host )['next_invoice_date'] && '2026-11-01' === hpv_p_get( 'subscription', $s_hu )['next_invoice_date'] );
it( 'egyszeri tétel nem jön újra', empty( hpv_p_get( 'subscription', $s_setup )['next_invoice_date'] ) );
it( 'a később esedékes és a szüneteltetett nem mozdult', '2026-10-20' === hpv_p_get( 'subscription', $s_later )['next_invoice_date'] && '2026-10-01' === hpv_p_get( 'subscription', $s_pause )['next_invoice_date'] );
$digest = array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => 0 === strpos( $m['subject'], 'Ismétlődő számlák' ) ) );
it( 'a csapat összefoglalót kap', 1 === count( $digest ) && false !== strpos( $digest[0]['message'], 'Sun Pools' ) && false !== strpos( $digest[0]['message'], 'piszkozat' ) );
it( 'az ügyfél nem kap levelet piszkozatról', ! array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( "mia_$suffix@sunpools.test", (array) $m['to'], true ) ) );

echo "Újrafuttatás\n";
it( 'ugyanaznap újra: nem számláz kétszer', 0 === count( hpv_recurring_run( '2026-10-01', 'draft' )['created'] ) && 1 === count( hpv_p_find( 'invoice', array( 'client_id' => $us ) ) ) );
set_transient( 'hpv_recurring_lock', 1, 60 );
it( 'párhuzamos futás kizárva', 'running' === hpv_recurring_run( '2026-12-01', 'draft' )['skipped'] );
delete_transient( 'hpv_recurring_lock' );
it( 'kikapcsolva nem fut', 'off' === hpv_recurring_run( '2026-12-01', 'off' )['skipped'] );

echo "Automatikus kiküldés\n";
$GLOBALS['hpv_it_mail'] = array();
$res   = hpv_recurring_run( '2026-11-01', 'send' );
$by    = array_column( $res['created'], null, 'client' );
$r_us  = $by[ 'Sun Pools LLC ' . $suffix ] ?? null;
$r_hu  = $by[ 'Kovács Kert Kft ' . $suffix ] ?? null;
it( 'USA: kiküldve', $r_us && $r_us['sent'] && 'sent' === hpv_p_get( 'invoice', $r_us['invoice_id'] )['status'] );
it( 'USA: az ügyfél megkapja', (bool) array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( "mia_$suffix@sunpools.test", (array) $m['to'], true ) ) );
it( 'Magyar: Számlázz.hu nélkül piszkozat marad, a hiba az összefoglalóban', $r_hu && ! $r_hu['sent'] && '' !== $r_hu['error'] && 'draft' === hpv_p_get( 'invoice', $r_hu['invoice_id'] )['status'] );
$digest = array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => 0 === strpos( $m['subject'], 'Ismétlődő számlák' ) ) );
it( 'összefoglaló: kiküldve és hiba', $digest && false !== strpos( $digest[0]['message'], 'kiküldve' ) && false !== strpos( $digest[0]['message'], 'hiba:' ) );

echo "Előnézet (REST)\n";
$staff_id = wp_insert_user( array( 'user_login' => 'rstaff_' . $suffix, 'user_email' => "rstaff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff' ) );
wp_set_current_user( $staff_id );
it( 'számlázási jog nélkül tiltott', 403 === rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/billing/recurring' ) )->get_status() && 403 === rest_do_request( new WP_REST_Request( 'POST', '/hpv/v1/billing/recurring' ) )->get_status() );
wp_set_current_user( 1 );
hpv_p_update( 'subscription', $s_later, array( 'next_invoice_date' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +5 days' ) ) ) );
$res  = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/billing/recurring' ) );
$next = array_values( array_filter( $res->get_data()['upcoming'], fn( $u ) => $u['client_id'] === $us && 'Ads management' === $u['name'] ) );
it( 'a következő 30 nap esedékes tételei', 200 === $res->get_status() && 1 === count( $next ) && '$400.00' === $next[0]['amount'] );

echo "Frissítés 0.6-ra\n";
$old_m = sub( $us, 'Old monthly', '100', 'monthly', '2025-01-31' );
$old_o = sub( $us, 'Old one-time', '100', 'one_time', '2025-03-01' );
$fut   = sub( $us, 'Future', '100', 'monthly', '2099-01-01' );
hpv_recurring_migrate();
$d = hpv_p_get( 'subscription', $old_m )['next_invoice_date'];
it( 'régi havi: a következő jövőbeli napra lép, nem számláz utólag', $d >= current_time( 'Y-m-d' ) && $d <= gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +31 days' ) ) );
it( 'hónap végi kezdőnap megmarad', in_array( substr( $d, 8 ), array( '28', '29', '30', '31' ), true ) );
it( 'régi egyszeri: nem számlázódik', empty( hpv_p_get( 'subscription', $old_o )['next_invoice_date'] ) );
it( 'jövőbeli: érintetlen', '2099-01-01' === hpv_p_get( 'subscription', $fut )['next_invoice_date'] );

echo "Takarítás\n";
hpv_p_delete( 'client', $us );
hpv_p_delete( 'client', $hu );
foreach ( $paused as $sid ) {
	hpv_p_update( 'subscription', $sid, array( 'status' => 'active' ) );
}
delete_option( 'hpv_recurring_last_run' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $mia->ID );
wp_delete_user( $staff_id );
it( 'előfizetések törölve az ügyféllel', ! hpv_p_find( 'subscription', array( 'client_id' => $us ) ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
