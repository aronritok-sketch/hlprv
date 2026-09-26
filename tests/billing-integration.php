<?php
/**
 * Számlázás és fizetés integrációs tesztje valódi WordPressen: jogosultságok, országok, pénznemek,
 * Számlázz.hu (HU), Stripe + QuickBooks (USA), portál.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen, számlázási kulcsok NINCSENEK beállítva):
 *   wp eval-file tests/billing-integration.php
 * A külső API-kat a teszt helyettesíti (pre_http_request); kifelé semmi nem megy, levelet sem küld.
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_bill_send' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}
foreach ( array( 'HPV_SZAMLAZZ_AGENT_KEY', 'HPV_STRIPE_SECRET_KEY', 'HPV_QBO_CLIENT_ID' ) as $const ) {
	if ( defined( $const ) ) {
		echo "Ezt a tesztet számlázási kulcsok nélküli teszt WordPressen futtasd ($const).\n";
		exit( 1 );
	}
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

function mails_to( string $email ): array {
	return array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
}

/* ─── Álszerverek ──────────────────────────────────────────── */

$GLOBALS['mock'] = array(
	'requests'       => array(),
	'szamlazz_error' => '',
	'qbo_item'       => true,
	'qbo_n'          => 0,
	'session'        => array(),
);

function mock_response( int $code, $body, array $headers = array() ): array {
	return array(
		'headers'  => $headers,
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

function mock_requests( string $needle ): array {
	return array_values( array_filter( $GLOBALS['mock']['requests'], fn( $r ) => false !== strpos( $r['url'] . ' ' . $r['field'], $needle ) ) );
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$m      = &$GLOBALS['mock'];
		$method = strtoupper( $args['method'] ?? 'GET' );
		$raw    = is_string( $args['body'] ?? null ) ? $args['body'] : ( is_array( $args['body'] ?? null ) ? http_build_query( $args['body'] ) : '' );
		$field  = '';
		$xml    = '';
		if ( preg_match( '/name="([^"]+)"; filename="request.xml"\r\nContent-Type: text\/xml\r\n\r\n(.*)\r\n--/s', $raw, $x ) ) {
			$field = $x[1];
			$xml   = $x[2];
		}
		$json = json_decode( $raw, true );
		parse_str( $raw, $form );
		$m['requests'][] = array(
			'url'     => $url,
			'method'  => $method,
			'field'   => $field,
			'xml'     => $xml,
			'json'    => is_array( $json ) ? $json : null,
			'form'    => $form,
			'headers' => $args['headers'] ?? array(),
		);

		// Számlázz.hu
		if ( HPV_SZAMLAZZ_URL === $url ) {
			if ( $m['szamlazz_error'] ) {
				return mock_response( 200, '', array( 'szlahu_error' => rawurlencode( $m['szamlazz_error'] ), 'szlahu_error_code' => '57' ) );
			}
			if ( 'action-xmlagentxmlfile' === $field ) {
				return mock_response( 200, '<?xml version="1.0" encoding="UTF-8"?><xmlszamlavalasz xmlns="http://www.szamlazz.hu/xmlszamlavalasz"><sikeres>true</sikeres><szamlaszam>E-HPV-2026-12</szamlaszam><szamlanetto>125000</szamlanetto><szamlabrutto>158750</szamlabrutto><kintlevoseg>158750</kintlevoseg><pdf>' . base64_encode( '%PDF-1.4 test invoice' ) . '</pdf></xmlszamlavalasz>' );
			}
			if ( 'action-szamla_agent_st' === $field ) {
				return mock_response( 200, '%PDF-1.4 storno', array( 'szlahu_szamlaszam' => 'E-HPV-2026-13' ) );
			}
			return mock_response( 200, '' );
		}
		// Stripe
		if ( 0 === strpos( $url, HPV_STRIPE_API ) ) {
			if ( 'POST' === $method && false !== strpos( $url, '/checkout/sessions' ) ) {
				return mock_response( 200, array( 'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1' ) );
			}
			if ( false !== strpos( $url, '/checkout/sessions/cs_' ) ) {
				return mock_response( 200, $m['session'] );
			}
			return mock_response( 404, array( 'error' => array( 'message' => 'mock' ) ) );
		}
		// QuickBooks
		if ( HPV_QBO_TOKEN_URL === $url ) {
			return mock_response( 200, array( 'access_token' => 'at-new', 'refresh_token' => 'rt-new', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8640000 ) );
		}
		if ( false !== strpos( $url, 'quickbooks.api.intuit.com/v3/company/9130' ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			if ( 'GET' === $method && str_ends_with( $path, '/query' ) ) {
				if ( false !== strpos( $q['query'], 'from Item' ) ) {
					return mock_response( 200, array( 'QueryResponse' => $m['qbo_item'] ? array( 'Item' => array( array( 'Id' => '7', 'Name' => 'Services' ) ) ) : array() ) );
				}
				return mock_response( 200, array( 'QueryResponse' => array() ) );
			}
			if ( str_ends_with( $path, '/customer' ) ) {
				return mock_response( 200, array( 'Customer' => array( 'Id' => '58' ) ) );
			}
			if ( 'POST' === $method && str_ends_with( $path, '/invoice' ) ) {
				return mock_response( 200, array( 'Invoice' => array( 'Id' => ! empty( $q['operation'] ) ? $m['requests'][ count( $m['requests'] ) - 1 ]['json']['Id'] : (string) ( 300 + ++$m['qbo_n'] ) ) ) );
			}
			if ( 'GET' === $method && preg_match( '#/invoice/(\d+)$#', $path, $x ) ) {
				return mock_response( 200, array( 'Invoice' => array( 'Id' => $x[1], 'SyncToken' => '2' ) ) );
			}
			if ( str_ends_with( $path, '/payment' ) ) {
				return mock_response( 200, array( 'Payment' => array( 'Id' => '901' ) ) );
			}
		}

		return mock_response( 500, 'unexpected request: ' . $url );
	},
	10,
	3
);

$suffix  = wp_generate_password( 6, false, false );
$cleanup = array( 'clients' => array(), 'users' => array() );

$staff_id = wp_insert_user( array( 'user_login' => 'bstaff_' . $suffix, 'user_email' => "bstaff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff', 'display_name' => 'Peter Project' ) );
$cleanup['users'][] = $staff_id;

echo "Jogosultságok\n";
it( 'munkatárs alapból nem számlázhat', ! hpv_p_can( 'invoices', $staff_id ) && ! hpv_p_can_entity( 'invoice', $staff_id ) && ! hpv_p_can_entity( 'service', $staff_id ) );
it( 'munkatárs alapból nem szerződhet', ! hpv_p_can( 'contracts', $staff_id ) && ! hpv_p_can_entity( 'contract', $staff_id ) );
it( 'projekthez nem kell külön jog', hpv_p_can_entity( 'project', $staff_id ) && hpv_p_can_entity( 'task', $staff_id ) );
it( 'adminisztrátor mindent tud', hpv_p_can( 'invoices', 1 ) && hpv_p_can( 'contracts', 1 ) && hpv_p_can( 'proposals', 1 ) );
wp_set_current_user( $staff_id );
it( 'munkatárs nem állíthat jogot', 403 === rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/team' ) )->get_status() );
wp_set_current_user( 1 );
$req = new WP_REST_Request( 'POST', '/hpv/v1/team/' . $staff_id );
$req->set_body_params( array( 'invoices' => true ) );
$res  = rest_do_request( $req );
$row  = array_values( array_filter( $res->get_data()['users'], fn( $u ) => $u['id'] === $staff_id ) )[0] ?? null;
it( 'adminisztrátor bekapcsolja a számlázást', 200 === $res->get_status() && $row && true === $row['caps']['invoices'] && false === $row['caps']['contracts'] && hpv_p_can( 'invoices', $staff_id ) );
$req = new WP_REST_Request( 'POST', '/hpv/v1/team/1' );
$req->set_body_params( array( 'invoices' => false ) );
it( 'adminisztrátor jogai nem vehetők el', 400 === rest_do_request( $req )->get_status() );
$req = new WP_REST_Request( 'POST', '/hpv/v1/team/' . $staff_id );
$req->set_body_params( array( 'invoices' => false ) );
rest_do_request( $req );
clean_user_cache( $staff_id );
it( 'és ki is kapcsolja', ! hpv_p_can( 'invoices', $staff_id ) );

echo "Ország, pénznem, cím\n";
$hu = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Kovács Kert Kft ' . $suffix, 'country' => 'HU', 'email' => "iroda_$suffix@kovacs.test", 'tax_number' => '12345678-2-13', 'status' => 'active' ) ) );
$us = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Sun Pools LLC ' . $suffix, 'email' => "office_$suffix@sunpools.test", 'street' => '1 Main St', 'city' => 'Cape Coral', 'state' => 'FL', 'zip' => '33904', 'contact_name' => 'Mia Sun', 'status' => 'active' ) ) );
$cleanup['clients'] = array( $hu, $us );
it( 'ország → pénznem', 'HUF' === hpv_p_client_currency( $hu ) && 'USD' === hpv_p_client_currency( $us ) );
it( 'ismeretlen ország → USA', 'US' === hpv_p_sanitize( 'client', array( 'country' => 'DE' ) )['country'] );
it( 'forint formátum', "127\u{00A0}000\u{00A0}Ft" === hpv_p_money( 12700000, 'HUF' ) && '$1,234.50' === hpv_p_money( 123450, 'USD' ) );
it( 'több pénznem egy sorban, USD elöl', "\$10.00 · 5\u{00A0}000\u{00A0}Ft" === hpv_p_money_multi( array( 'HUF' => 500000, 'USD' => 1000 ) ) );
it( 'régi cím szétbontása (USA)', array( 'street' => '1 Main St', 'city' => 'Cape Coral', 'state' => 'FL', 'zip' => '33904' ) === hpv_p_parse_address( "1 Main St\nCape Coral, FL 33904" ) );
it( 'régi cím szétbontása (magyar)', '1055' === hpv_p_parse_address( "Kossuth tér 1.\n1055 Budapest" )['zip'] );

$maria = hpv_p_invite_user( $hu, 'Kovács Mária', "maria_$suffix@kovacs.test" );
$mia   = hpv_p_invite_user( $us, 'Mia Sun', "mia_$suffix@sunpools.test" );
$cleanup['users'][] = $maria->ID;
$cleanup['users'][] = $mia->ID;

function make_invoice( int $client_id, array $items, array $extra = array() ): int {
	$id = hpv_p_insert( 'invoice', array_merge( array( 'client_id' => $client_id, 'status' => 'draft', 'issue_date' => '2026-09-26', 'due_date' => '2026-10-11' ), $extra ) );
	hpv_p_save_invoice_items( $id, $items );
	return $id;
}

echo "Magyar számla (Számlázz.hu)\n";
$inv_hu = make_invoice( $hu, array( array( 'description' => 'Weboldal tervezés', 'quantity' => '2', 'unit_price' => '50000' ), array( 'description' => 'SEO audit', 'quantity' => '1', 'unit_price' => '25000' ) ), array( 'tax_rate' => '27' ) );
$r      = hpv_bill_send( $inv_hu, 1 );
it( 'Számlázz.hu kulcs nélkül nem állítható ki', is_wp_error( $r ) && 'szamlazz_off' === $r->get_error_code() && 'draft' === hpv_p_get( 'invoice', $inv_hu )['status'] );
define( 'HPV_SZAMLAZZ_AGENT_KEY', 'agent-key-test' );
$r = hpv_bill_send( $inv_hu, 1 );
it( 'hiányzó cím: érthető hiba, nincs kérés', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'irányítószám' ) && ! mock_requests( 'szamlazz.hu' ) );
hpv_p_update( 'client', $hu, array( 'zip' => '1055', 'city' => 'Budapest', 'street' => 'Kossuth tér 1.' ) );

$GLOBALS['mock']['szamlazz_error'] = 'Hibás adószám';
$r = hpv_bill_send( $inv_hu, 1 );
$i = hpv_p_get( 'invoice', $inv_hu );
it( 'Számlázz.hu hiba: üzenet, a számla piszkozat marad, hibát jelez', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Hibás adószám' ) && 'draft' === $i['status'] && 'error' === $i['sync_status'] );
$GLOBALS['mock']['szamlazz_error'] = '';

$r   = hpv_bill_send( $inv_hu, 1 );
$req = mock_requests( 'action-xmlagentxmlfile' );
$req = end( $req );
$xml = simplexml_load_string( $req['xml'] );
$i   = hpv_p_get( 'invoice', $inv_hu );
it( 'kiállítva: Számlázz.hu számlaszám, kiküldve, szinkron rendben', ! is_wp_error( $r ) && 'E-HPV-2026-12' === $i['number'] && 'E-HPV-2026-12' === $i['external_id'] && 'sent' === $i['status'] && 'synced' === $i['sync_status'] );
it( 'az XML érvényes, a kulccsal', false !== $xml && 'agent-key-test' === (string) $xml->beallitasok->szamlaagentkulcs && 'true' === (string) $xml->beallitasok->eszamla );
it( 'vevő: név, cím, adószám, áfaalany', 0 === strpos( (string) $xml->vevo->nev, 'Kovács Kert Kft' ) && '1055' === (string) $xml->vevo->irsz && '12345678-2-13' === (string) $xml->vevo->adoszam && '7' === (string) $xml->vevo->adoalany && 'true' === (string) $xml->vevo->sendEmail );
it( 'tétel: nettó, 27% ÁFA, bruttó forintban', '100000' === (string) $xml->tetelek->tetel[0]->nettoErtek && '27000' === (string) $xml->tetelek->tetel[0]->afaErtek && '127000' === (string) $xml->tetelek->tetel[0]->bruttoErtek && '27' === (string) $xml->tetelek->tetel[0]->afakulcs && '2' === (string) $xml->tetelek->tetel[0]->mennyiseg );
it( 'fejléc: dátumok, pénznem, nyelv, a sorrend az XSD szerint', 'Ft' === (string) $xml->fejlec->penznem && 'hu' === (string) $xml->fejlec->szamlaNyelve && array( 'keltDatum', 'teljesitesDatum', 'fizetesiHataridoDatum', 'fizmod', 'penznem', 'szamlaNyelve', 'megjegyzes', 'rendelesSzam' ) === array_map( fn( $e ) => $e->getName(), iterator_to_array( $xml->fejlec->children(), false ) ) );
it( 'a Számlázz.hu e-mailjében a portál fizetési linkje', false !== strpos( (string) $xml->elado->emailSzoveg, 'view=invoices' ) );
it( 'végösszegek a Számlázz.hu válaszából', 12500000 === hpv_p_to_cents( $i['subtotal'] ) && 15875000 === hpv_p_to_cents( $i['total'] ) && 3375000 === hpv_p_to_cents( $i['tax'] ) );
it( 'PDF a védett mappában, véletlen névvel', preg_match( '/^[a-f0-9]{32}\.pdf$/', (string) $i['pdf_file'] ) && '%PDF-1.4 test invoice' === file_get_contents( hpv_p_private_dir() . '/' . $i['pdf_file'] ) && is_file( hpv_p_private_dir() . '/.htaccess' ) );
it( 'kiállított magyar számla zárolva', hpv_p_invoice_locked( $i ) );
it( 'saját e-mailt nem küldünk (a Számlázz.hu küldi)', ! mails_to( "maria_$suffix@kovacs.test" ) || ! array_filter( mails_to( "maria_$suffix@kovacs.test" ), fn( $m ) => false !== strpos( $m['subject'], 'Invoice' ) ) );
$before = count( mock_requests( 'action-xmlagentxmlfile' ) );
hpv_bill_send( $inv_hu, 1 );
it( 'újraküldés: nem állít ki újra, emlékeztető megy', count( mock_requests( 'action-xmlagentxmlfile' ) ) === $before && array_filter( mails_to( "maria_$suffix@kovacs.test" ), fn( $m ) => false !== strpos( $m['subject'], 'E-HPV-2026-12' ) ) );

$r   = hpv_bill_mark_paid( $inv_hu, array( 'provider' => 'manual', 'amount' => 5000000, 'paid_on' => '2026-09-28', 'note' => 'átutalás' ) );
$pay = mock_requests( 'action-szamla_agent_kifiz' );
$px  = $pay ? simplexml_load_string( end( $pay )['xml'] ) : null;
it( 'részfizetés: rögzítve, a számla még nyitott', ! is_wp_error( $r ) && 'sent' === $r['status'] && 5000000 === hpv_p_to_cents( $r['paid_amount'] ) && 10875000 === hpv_p_invoice_balance( $r ) );
it( 'kifizetés a Számlázz.hu-ban (szám, összeg, jogcím)', $px && 'E-HPV-2026-12' === (string) $px->beallitasok->szamlaszam && '50000' === (string) $px->kifizetes->osszeg && 'Átutalás' === (string) $px->kifizetes->jogcim && '2026-09-28' === (string) $px->kifizetes->datum );
it( 'a befizetés könyvelve', '' !== hpv_p_find( 'payment', array( 'invoice_id' => $inv_hu ) )[0]['external_ref'] );
$r = hpv_bill_mark_paid( $inv_hu, array( 'provider' => 'teya', 'amount' => 10875000, 'reference' => 'teya-tx-1' ) );
it( 'teljes összeg: fizetve', 'paid' === $r['status'] && $r['paid_at'] );
it( 'Teya befizetés bankkártyás jogcímmel', 'Bankkártya' === (string) simplexml_load_string( end( $GLOBALS['mock']['requests'] )['xml'] )->kifizetes->jogcim );
it( 'online befizetésről értesítés a csapatnak', (bool) array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => false !== strpos( $m['subject'], 'Online befizetés' ) ) );
$receipt = array_values( array_filter( mails_to( "maria_$suffix@kovacs.test" ), fn( $m ) => false !== strpos( $m['subject'], 'Befizetés megérkezett' ) ) );
it( 'magyar ügyfél: magyar nyelvű visszaigazolás', 1 === count( $receipt ) && false !== strpos( $receipt[0]['message'], 'A számla teljesen kifizetve.' ) && false !== strpos( $receipt[0]['message'], 'Ügyfélportál' ) );
$log = hpv_p_find( 'activity', array( 'client_id' => $hu, 'visible' => 1 ), array( 'limit' => 1 ) );
it( 'magyar ügyfél: az idővonal is magyar', $log && false !== strpos( $log[0]['body'], 'befizetés érkezett' ) );
hpv_bill_mark_paid( $inv_hu, array( 'provider' => 'teya', 'amount' => 10875000, 'reference' => 'teya-tx-1' ) );
it( 'ugyanaz a tranzakció kétszer nem kerül be', 2 === count( hpv_p_find( 'payment', array( 'invoice_id' => $inv_hu ) ) ) );

$inv_hu2 = make_invoice( $hu, array( array( 'description' => 'Hosting', 'quantity' => '1', 'unit_price' => '10000' ) ), array( 'vat_key' => 'AAM', 'tax_rate' => '0' ) );
hpv_bill_send( $inv_hu2, 1 );
$last = mock_requests( 'action-xmlagentxmlfile' );
$x2   = simplexml_load_string( end( $last )['xml'] );
it( 'alanyi adómentes számla: AAM, 0 ÁFA', 'AAM' === (string) $x2->tetelek->tetel[0]->afakulcs && '0' === (string) $x2->tetelek->tetel[0]->afaErtek );
$v = hpv_bill_void( $inv_hu2, 1 );
$st = mock_requests( 'action-szamla_agent_st' );
it( 'sztornó: SS számla a Számlázz.hu-ban, a számla érvénytelen', ! is_wp_error( $v ) && 'void' === $v['status'] && $st && 'SS' === (string) simplexml_load_string( end( $st )['xml'] )->fejlec->tipus );
it( 'sztornó számlaszám a naplóban', (bool) array_filter( hpv_p_find( 'activity', array( 'client_id' => $hu ) ), fn( $a ) => false !== strpos( $a['body'], 'E-HPV-2026-13' ) ) );

echo "USA: Stripe\n";
$inv_us = make_invoice( $us, array( array( 'description' => 'Website design', 'quantity' => '1', 'unit_price' => '2400' ), array( 'description' => 'Hosting (year)', 'quantity' => '1', 'unit_price' => '300' ) ) );
$r      = hpv_bill_send( $inv_us, 1 );
it( 'kiküldés: saját sorszám, USD, e-mail az ügyfélnek, QuickBooks nélkül nincs szinkron', 'sent' === $r['status'] && 0 === strpos( $r['number'], hpv_p_settings()['invoice_prefix'] ) && 'USD' === $r['currency'] && mails_to( "mia_$suffix@sunpools.test" ) && '' === $r['external_id'] );
it( 'Stripe kulcs nélkül nincs fizetés gomb (kézi link sincs)', '' === hpv_p_pay_url( $r ) );
define( 'HPV_STRIPE_SECRET_KEY', 'sk_test_x' );
define( 'HPV_STRIPE_WEBHOOK_SECRET', 'whsec_test' );
it( 'fizetés gomb a saját végpontunkra', false !== strpos( hpv_p_pay_url( $r ), 'hpv_pay=' . $inv_us ) );
$url  = hpv_stripe_checkout_url( $r );
$sreq = mock_requests( '/checkout/sessions' );
$form = end( $sreq )['form'];
it( 'Checkout: a fennálló összeg centben, USD, számla azonosító', 'https://checkout.stripe.com/c/pay/cs_test_1' === $url && '270000' === $form['line_items'][0]['price_data']['unit_amount'] && 'usd' === $form['line_items'][0]['price_data']['currency'] && (string) $inv_us === $form['metadata']['invoice_id'] );
it( 'Checkout: visszatérés a munkamenet azonosítóval, e-mail előtöltve', false !== strpos( $form['success_url'], 'session_id={CHECKOUT_SESSION_ID}' ) && "office_$suffix@sunpools.test" === $form['customer_email'] && 'Bearer sk_test_x' === end( $sreq )['headers']['Authorization'] );

$event = function ( array $session, string $type = 'checkout.session.completed' ) {
	return wp_json_encode( array( 'id' => 'evt_' . wp_rand(), 'type' => $type, 'data' => array( 'object' => $session ) ) );
};
$hook  = function ( string $payload, ?string $sig = null ) {
	$t   = time();
	$req = new WP_REST_Request( 'POST', '/hpv/v1/pay/stripe' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_header( 'stripe-signature', $sig ?? 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, 'whsec_test' ) );
	$req->set_body( $payload );
	wp_set_current_user( 0 );
	$res = rest_do_request( $req );
	wp_set_current_user( 1 );
	return $res->get_status();
};
$session = array( 'id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 270000, 'currency' => 'usd', 'metadata' => array( 'invoice_id' => (string) $inv_us ), 'payment_intent' => 'pi_1' );
it( 'hamis aláírás: 400, nincs befizetés', 400 === $hook( $event( $session ), 't=' . time() . ',v1=deadbeef' ) && ! hpv_p_find( 'payment', array( 'invoice_id' => $inv_us ) ) );
it( 'lejárt időbélyeg: 400', 400 === $hook( $event( $session ), 't=' . ( time() - 3600 ) . ',v1=' . hash_hmac( 'sha256', ( time() - 3600 ) . '.' . $event( $session ), 'whsec_test' ) ) );
it( 'nem fizetett munkamenet: nincs befizetés', 200 === $hook( $event( array_merge( $session, array( 'payment_status' => 'unpaid' ) ) ) ) && ! hpv_p_find( 'payment', array( 'invoice_id' => $inv_us ) ) );
it( 'eltérő pénznem: nincs befizetés, értesítés', 200 === $hook( $event( array_merge( $session, array( 'currency' => 'eur' ) ) ) ) && ! hpv_p_find( 'payment', array( 'invoice_id' => $inv_us ) ) && array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => false !== strpos( $m['subject'], 'Stripe fizetés' ) ) );
it( 'érvényes webhook: fizetve', 200 === $hook( $event( $session ) ) && 'paid' === hpv_p_get( 'invoice', $inv_us )['status'] );
$hook( $event( $session ) );
$GLOBALS['mock']['session'] = $session;
hpv_stripe_confirm_return( hpv_p_get( 'invoice', $inv_us ), 'cs_test_1' );
it( 'ismételt webhook és visszatérés: egy befizetés', 1 === count( hpv_p_find( 'payment', array( 'invoice_id' => $inv_us ) ) ) && 'stripe' === hpv_p_find( 'payment', array( 'invoice_id' => $inv_us ) )[0]['provider'] );

echo "USA: QuickBooks\n";
define( 'HPV_QBO_CLIENT_ID', 'qbo-id' );
define( 'HPV_QBO_CLIENT_SECRET', 'qbo-secret' );
hpv_qbo_save_tokens( array( 'realm' => '9130', 'access_token' => 'at-old', 'refresh_token' => 'rt-old', 'expires_at' => time() - 10, 'refresh_expires_at' => time() + 86400 ) );
it( 'a tokenek titkosítva tárolódnak', hpv_qbo_connected() && false === strpos( (string) get_option( 'hpv_qbo_tokens' ), 'rt-old' ) );
$sync = hpv_bill_sync_invoice( $inv_us );
$tok  = mock_requests( 'oauth.platform.intuit.com' );
$qinv = array_values( array_filter( mock_requests( '/invoice' ), fn( $q ) => 'POST' === $q['method'] ) );
$qpay = mock_requests( '/payment' );
it( 'lejárt token frissítve (Basic hitelesítés)', $tok && 'refresh_token' === $tok[0]['form']['grant_type'] && 'Basic ' . base64_encode( 'qbo-id:qbo-secret' ) === $tok[0]['headers']['Authorization'] && 'rt-new' === hpv_qbo_tokens()['refresh_token'] );
it( 'ügyfél létrehozva a QuickBooks-ban, azonosító elmentve', '58' === hpv_p_get( 'client', $us )['external_customer_id'] && 'Cape Coral' === mock_requests( '/customer' )[0]['json']['BillAddr']['City'] );
it( 'számla átküldve: sorszám, tételek, „Services” termékkel', ! is_wp_error( $sync ) && '301' === $sync['external_id'] && $qinv && $sync['number'] === $qinv[0]['json']['DocNumber'] && 2 === count( $qinv[0]['json']['Line'] ) && '7' === $qinv[0]['json']['Line'][0]['SalesItemLineDetail']['ItemRef']['value'] && 2400.0 === (float) $qinv[0]['json']['Line'][0]['Amount'] );
it( 'a korábbi Stripe befizetés is átkerül, a számlához kötve', $qpay && '301' === $qpay[0]['json']['Line'][0]['LinkedTxn'][0]['TxnId'] && 2700.0 === (float) $qpay[0]['json']['TotalAmt'] && '901' === hpv_p_find( 'payment', array( 'invoice_id' => $inv_us ) )[0]['external_ref'] );

$GLOBALS['mock']['qbo_item'] = false;
delete_option( 'hpv_qbo_item' );
$inv_us2 = make_invoice( $us, array( array( 'description' => 'SEO plan', 'quantity' => '1', 'unit_price' => '750' ) ) );
$r       = hpv_bill_send( $inv_us2, 1 );
it( 'QuickBooks hiba: a számla kiküldve, a hiba látszik, érthető üzenettel', 'sent' === $r['status'] && 'error' === $r['sync_status'] && false !== strpos( $r['sync_error'], 'Services' ) );
$GLOBALS['mock']['qbo_item'] = true;
$r = hpv_bill_sync_invoice( $inv_us2 );
it( 'újrapróbálás után szinkronban', 'synced' === $r['sync_status'] && '' !== $r['external_id'] );
$v    = hpv_bill_void( $inv_us2, 1 );
$void = array_values( array_filter( mock_requests( 'operation=void' ), fn( $q ) => 'POST' === $q['method'] ) );
it( 'érvénytelenítés: void a QuickBooks-ban a SyncTokennel', 'void' === $v['status'] && $void && '2' === $void[0]['json']['SyncToken'] && $r['external_id'] === $void[0]['json']['Id'] );

echo "Portál\n";
function portal_as( WP_User $u, array $get ): string {
	wp_set_current_user( $u->ID );
	$_GET = $get;
	$html = hpv_p_portal_app();
	$_GET = array();
	wp_set_current_user( 1 );
	return $html;
}
$inv_us3 = make_invoice( $us, array( array( 'description' => 'Landing page', 'quantity' => '1', 'unit_price' => '900' ) ) );
hpv_bill_send( $inv_us3, 1 );
$page = portal_as( $mia, array( 'view' => 'invoices', 'id' => $inv_us3 ) );
it( 'USA: „Pay now” a Stripe végpontra, összeggel', false !== strpos( $page, 'hpv_pay=' . $inv_us3 ) && false !== strpos( $page, 'Pay now · $900.00' ) );
it( 'USA: számlázási cím sorokban', false !== strpos( $page, 'Cape Coral, FL 33904' ) );
$inv_hu3 = make_invoice( $hu, array( array( 'description' => 'Karbantartás', 'quantity' => '1', 'unit_price' => '20000' ) ) );
hpv_bill_send( $inv_hu3, 1 );
hpv_p_update( 'invoice', $inv_hu3, array( 'payment_url' => 'https://pay.teya.test/link/abc' ) );
$page = portal_as( $maria, array( 'view' => 'invoices', 'id' => $inv_hu3 ) );
it( 'Magyar: hivatalos PDF és Teya link', false !== strpos( $page, 'hpv_invoice_pdf=' . $inv_hu3 ) && false !== strpos( $page, 'https://pay.teya.test/link/abc' ) && false !== strpos( $page, 'Ft' ) );
it( 'Magyar: az amerikai cég adatai nem kerülnek a magyar számlára', false === strpos( $page, hpv_p_settings()['company_legal'] ) );
it( 'másik ügyfél számlája nem látszik', false !== strpos( portal_as( $maria, array( 'view' => 'invoices', 'id' => $inv_us3 ) ), 'A számla nem található' ) );
it( 'magyar ügyfélnek magyar a portál', false !== strpos( $page, 'Számla letöltése (PDF)' ) && false !== strpos( $page, 'Áttekintés' ) && false !== strpos( $page, 'Kijelentkezés' ) && false === strpos( $page, 'Log out' ) );
it( 'magyar ügyfélnek magyar dátum', (bool) preg_match( '/\d{4}\. (jan|febr|márc|ápr|máj|jún|júl|aug|szept|okt|nov|dec)\. \d{1,2}\./u', $page ) );
it( 'amerikai ügyfélnek angol marad', false !== strpos( portal_as( $mia, array() ), 'Needs your attention' ) );
it( 'egyenleg pénznemenként', false !== strpos( portal_as( $maria, array() ), 'Ft' ) );
$staff = get_userdata( $staff_id );
$prev  = portal_as( $staff, array( 'view' => 'invoices', 'preview_client' => $us ) );
it( 'munkatárs számlázási jog nélkül az előnézetben sem látja a számlákat', false !== strpos( $prev, 'you do not have access' ) && false === strpos( $prev, 'Landing page' ) );

echo "Takarítás\n";
foreach ( $cleanup['clients'] as $cid ) {
	foreach ( hpv_p_find( 'invoice', array( 'client_id' => $cid ) ) as $inv ) {
		if ( $inv['pdf_file'] ) {
			wp_delete_file( hpv_p_private_dir() . '/' . $inv['pdf_file'] );
		}
	}
	hpv_p_delete( 'client', $cid );
}
it( 'ügyfél törlésével a számlák és befizetések is törlődnek', ! hpv_p_find( 'invoice', array( 'client_id' => $hu ) ) && ! hpv_p_find( 'payment', array( 'invoice_id' => $inv_hu ) ) );
delete_option( 'hpv_qbo_tokens' );
delete_option( 'hpv_qbo_item' );

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $uid ) {
	wp_delete_user( $uid );
}

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
