<?php
/**
 * A CRM app számla- és előfizetés-API-jának integrációs tesztje valódi WordPressen.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen, számlázási kulcsok NINCSENEK beállítva):
 *   wp eval-file tests/invoices-api-integration.php
 * A Számlázz.hu-t álszerver helyettesíti; kifelé semmi nem megy, levelet sem küld.
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_inv_rest_save' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}
if ( defined( 'HPV_SZAMLAZZ_AGENT_KEY' ) || defined( 'HPV_QBO_CLIENT_ID' ) || defined( 'HPV_STRIPE_SECRET_KEY' ) ) {
	echo "Ezt a tesztet számlázási kulcsok nélküli teszt WordPressen futtasd.\n";
	exit( 1 );
}

$GLOBALS['hpv_it_fail'] = 0;
$GLOBALS['hpv_it_mail'] = array();
$GLOBALS['szla']        = array();

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
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( HPV_SZAMLAZZ_URL !== $url ) {
			return new WP_Error( 'offline', 'unexpected ' . $url );
		}
		$raw = (string) $args['body'];
		preg_match( '/name="([^"]+)"; filename="request.xml"/', $raw, $x );
		$GLOBALS['szla'][] = $x[1] ?? '';
		$body              = '';
		$headers           = array();
		if ( 'action-xmlagentxmlfile' === ( $x[1] ?? '' ) ) {
			$body = '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz"><sikeres>true</sikeres><szamlaszam>E-HPV-2026-77</szamlaszam><szamlanetto>100000</szamlanetto><szamlabrutto>127000</szamlabrutto><kintlevoseg>127000</kintlevoseg><pdf>' . base64_encode( '%PDF-1.4 test' ) . '</pdf></xmlszamlavalasz>';
		} elseif ( 'action-szamla_agent_st' === ( $x[1] ?? '' ) ) {
			$body    = '%PDF-1.4 storno';
			$headers = array( 'szlahu_szamlaszam' => 'E-HPV-2026-78' );
		}
		return array( 'headers' => $headers, 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);

function inv( string $method, string $path, array $body = array() ) {
	$parts = explode( '?', $path, 2 );
	$req   = new WP_REST_Request( $method, '/hpv/v1/billing' . $parts[0] );
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $query );
		$req->set_query_params( $query );
	}
	if ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $req );
}

$suffix   = wp_generate_password( 5, false, false );
$staff_id = wp_insert_user( array( 'user_login' => 'istaff_' . $suffix, 'user_email' => "istaff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff' ) );
$us       = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Sun Pools $suffix", 'email' => "office_$suffix@sunpools.test", 'status' => 'active' ) ) );
$hu       = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Kovács Kert $suffix", 'country' => 'HU', 'email' => "iroda_$suffix@kovacs.test", 'zip' => '1055', 'city' => 'Budapest', 'street' => 'Kossuth tér 1.', 'status' => 'active' ) ) );
$mia      = hpv_p_invite_user( $us, 'Mia Sun', "mia_$suffix@sunpools.test" );

echo "Jogosultság\n";
wp_set_current_user( $staff_id );
it( 'számlázási jog nélkül tiltott', 403 === inv( 'GET', '/invoices' )->get_status() && 403 === inv( 'POST', '/invoices', array( 'client_id' => $us ) )->get_status() && 403 === inv( 'GET', '/subscriptions' )->get_status() );
wp_set_current_user( 1 );

echo "USA számla\n";
$res = inv( 'POST', '/invoices', array( 'client_id' => $us ) );
$d   = $res->get_data();
it( 'új piszkozat: dátumok a beállítás szerint, USD', 200 === $res->get_status() && 'draft' === $d['raw_status'] && 'USD' === $d['currency'] && current_time( 'Y-m-d' ) === $d['issue_date'] && $d['due_date'] > $d['issue_date'] );
it( 'tétel nélkül még nincs sorszám', '' === $d['number'] );
$id  = $d['id'];
$res = inv(
	'POST',
	"/invoices/$id",
	array(
		'items' => array(
			array( 'description' => 'Website design', 'quantity' => '1', 'unit_price' => '2400' ),
			array( 'description' => 'SEO audit', 'quantity' => '2', 'unit_price' => '375.50' ),
			array( 'description' => '', 'quantity' => '1', 'unit_price' => '5' ),
		),
		'notes' => 'Thank you!',
	)
);
$d = $res->get_data();
it( 'tételek mentve, üres sor nélkül, összeg centre pontos', 2 === count( $d['items'] ) && 315100 === $d['total'] && 'Thank you!' === $d['notes'] );
it( 'sorszámot kapott', '' !== $d['number'] );
$number = $d['number'];
$res    = inv( 'POST', "/invoices/$id/send" );
$d      = $res->get_data();
it( 'kiküldés: státusz, e-mail az ügyfélnek', 200 === $res->get_status() && 'sent' === $d['raw_status'] && $number === $d['number'] && array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( "mia_$suffix@sunpools.test", (array) $m['to'], true ) && false !== strpos( $m['subject'], $number ) ) );
$res = inv( 'POST', "/invoices/$id", array( 'items' => array( array( 'description' => 'Hack', 'quantity' => '1', 'unit_price' => '1' ) ), 'notes' => 'Updated note' ) );
$d   = $res->get_data();
it( 'kiküldött számla: a tétel nem módosul, a megjegyzés igen', 315100 === $d['total'] && 'Updated note' === $d['notes'] && 2 === count( $d['items'] ) );
$res = inv( 'POST', "/invoices/$id/payment", array( 'amount' => '1000', 'paid_on' => '2026-09-20', 'provider' => 'manual', 'note' => 'Wire' ) );
$d   = $res->get_data();
it( 'részfizetés', 200 === $res->get_status() && 'sent' === $d['raw_status'] && 215100 === $d['balance'] && 1 === count( $d['payments'] ) && 'Wire' === $d['payments'][0]['note'] );
$d = inv( 'POST', "/invoices/$id/payment", array() )->get_data();
it( 'összeg nélkül a hátralék: fizetve', 'paid' === $d['raw_status'] && 0 === $d['balance'] && 2 === count( $d['payments'] ) );
it( 'fizetett számla nem módosítható', 400 === inv( 'POST', "/invoices/$id", array( 'notes' => 'x' ) )->get_status() );
it( 'fizetett számlára nem rögzíthető újabb befizetés', 400 === inv( 'POST', "/invoices/$id/payment", array( 'amount' => '1' ) )->get_status() );
it( 'kiküldött számla nem törölhető', 400 === inv( 'DELETE', "/invoices/$id" )->get_status() );

$d2 = inv( 'POST', '/invoices', array( 'client_id' => $us, 'items' => array( array( 'description' => 'Hosting', 'quantity' => '1', 'unit_price' => '30' ) ) ) )->get_data();
it( 'piszkozat törölhető', 200 === inv( 'DELETE', '/invoices/' . $d2['id'] )->get_status() && ! hpv_p_get( 'invoice', $d2['id'] ) );
it( 'ügyfél nélkül: hiba', 400 === inv( 'POST', '/invoices', array() )->get_status() );

echo "Magyar számla (Számlázz.hu)\n";
define( 'HPV_SZAMLAZZ_AGENT_KEY', 'agent-key-test' );
$d  = inv( 'POST', '/invoices', array( 'client_id' => $hu, 'vat_key' => '27', 'items' => array( array( 'description' => 'Weboldal', 'quantity' => '1', 'unit_price' => '100000' ) ) ) )->get_data();
$hid = $d['id'];
it( 'HUF, 27% ÁFA, sorszám nélkül', 'HUF' === $d['currency'] && 27.0 === $d['tax_rate'] && 12700000 === $d['total'] && '' === $d['number'] );
$d = inv( 'POST', "/invoices/$hid", array( 'vat_key' => 'AAM' ) )->get_data();
it( 'ÁFA-kulcs csere: az összeg újraszámolva', 0.0 === $d['tax_rate'] && 10000000 === $d['total'] );
inv( 'POST', "/invoices/$hid", array( 'vat_key' => '27' ) );
$d = inv( 'POST', "/invoices/$hid/send" )->get_data();
it( 'kiállítás: Számlázz.hu sorszám, PDF, zárolva', 'E-HPV-2026-77' === $d['number'] && 'sent' === $d['raw_status'] && $d['locked'] && '' !== $d['pdf_url'] && in_array( 'action-xmlagentxmlfile', $GLOBALS['szla'], true ) );
it( 'kiállított magyar számla nem módosítható', 400 === inv( 'POST', "/invoices/$hid", array( 'notes' => 'x' ) )->get_status() );
$res = inv( 'POST', "/invoices/$hid/link", array( 'payment_url' => 'http://pay.teya.test/x' ) );
it( 'Teya link csak https', 400 === $res->get_status() );
$d = inv( 'POST', "/invoices/$hid/link", array( 'payment_url' => 'https://pay.teya.test/link/abc' ) )->get_data();
it( 'Teya link mentve', 'https://pay.teya.test/link/abc' === $d['payment_url'] );
$d = inv( 'POST', "/invoices/$hid/void" )->get_data();
it( 'sztornó a Számlázz.hu-ban', 'void' === $d['raw_status'] && in_array( 'action-szamla_agent_st', $GLOBALS['szla'], true ) );

echo "Lista\n";
$d3  = inv( 'POST', '/invoices', array( 'client_id' => $us, 'due_date' => '2026-01-01', 'issue_date' => '2025-12-15', 'items' => array( array( 'description' => 'Old work', 'quantity' => '1', 'unit_price' => '99' ) ) ) )->get_data();
inv( 'POST', '/invoices/' . $d3['id'] . '/send' );
$all = inv( 'GET', '/invoices?client_id=' . $us )->get_data();
it( 'ügyfélre szűrve', 2 === count( $all['invoices'] ) && ! array_filter( $all['invoices'], fn( $i ) => $i['client_id'] !== $us ) );
$over = inv( 'GET', '/invoices?status=overdue&client_id=' . $us )->get_data();
it( 'lejárt szűrő és státusz', 1 === count( $over['invoices'] ) && 'overdue' === $over['invoices'][0]['status'] );
it( 'összesítők', isset( $all['kpi']['outstanding'], $all['kpi']['overdue'], $all['kpi']['paid_month'], $all['kpi']['mrr'] ) && $all['counts']['overdue'] >= 1 && isset( $all['settings']['hu_vat_key'] ) && $all['settings']['vat_keys'] );
$search = inv( 'GET', '/invoices?q=' . rawurlencode( 'kovács kert ' . $suffix ) )->get_data();
it( 'keresés ügyfélnévre', 1 === count( $search['invoices'] ) && $hid === $search['invoices'][0]['id'] );

echo "Előfizetések\n";
$svc = hpv_p_insert( 'service', array( 'name' => "Care plan $suffix", 'description' => 'Updates, backups', 'price' => '149', 'billing' => 'monthly', 'active' => 1 ) );
$res = inv( 'POST', '/subscriptions', array( 'client_id' => $us, 'service_id' => $svc, 'start_date' => '2026-10-01' ) );
$s   = $res->get_data();
it( 'katalógusból: név, ár, ciklus, első számla dátuma', 200 === $res->get_status() && "Care plan $suffix" === $s['name'] && 149.0 === (float) $s['price'] && 'monthly' === $s['billing'] && '2026-10-01' === $s['next_invoice_date'] && '$149.00' === $s['price_label'] );
$s = inv( 'POST', '/subscriptions/' . $s['id'], array( 'status' => 'paused', 'next_invoice_date' => '2026-11-01' ) )->get_data();
it( 'szüneteltetés, dátum módosítás', 'paused' === $s['status'] && '2026-11-01' === $s['next_invoice_date'] );
it( 'név nélkül: hiba', 400 === inv( 'POST', '/subscriptions', array( 'client_id' => $us ) )->get_status() );
$list = inv( 'GET', '/subscriptions?client_id=' . $us )->get_data();
it( 'lista, szolgáltatások, ismétlődés módja', 1 === count( $list['subscriptions'] ) && array_filter( $list['services'], fn( $x ) => $x['id'] === $svc ) && in_array( $list['recurring']['mode'], array( 'draft', 'send', 'off' ), true ) );

echo "Takarítás\n";
foreach ( hpv_p_find( 'invoice', array( 'client_id' => $hu ) ) as $i ) {
	if ( $i['pdf_file'] ) {
		wp_delete_file( hpv_p_private_dir() . '/' . $i['pdf_file'] );
	}
}
hpv_p_delete( 'client', $us );
hpv_p_delete( 'client', $hu );
hpv_p_delete( 'service', $svc );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $staff_id );
wp_delete_user( $mia->ID );
it( 'kész', ! hpv_p_find( 'invoice', array( 'client_id' => $us ) ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
