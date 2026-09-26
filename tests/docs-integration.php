<?php
/**
 * Szerződések, minták és ajánlatok (AI-val) integrációs tesztje valódi WordPressen.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen, AI kulcs NINCS beállítva):
 *   wp eval-file tests/docs-integration.php
 * Az AI-t a teszt helyettesíti (pre_http_request); kifelé semmi nem megy, levelet sem küld.
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_prop_create' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}
if ( defined( 'HPV_AI_API_KEY' ) ) {
	echo "Ezt a tesztet AI kulcs nélküli teszt WordPressen futtasd.\n";
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

function mails_to( string $email ): array {
	return array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
}

/* ─── AI álszerver ─────────────────────────────────────────── */

$GLOBALS['ai'] = array( 'requests' => array() );

function ai_last(): array {
	return end( $GLOBALS['ai']['requests'] ) ?: array();
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( HPV_AI_API !== $url ) {
			return array( 'headers' => array(), 'body' => 'unexpected', 'response' => array( 'code' => 500, 'message' => 'x' ), 'cookies' => array(), 'filename' => null );
		}
		$body                        = json_decode( $args['body'], true );
		$GLOBALS['ai']['requests'][] = $body;
		$system                      = $body['system'];
		$user                        = $body['messages'][0]['content'];

		if ( false !== strpos( $system, 'You edit a service contract' ) ) {
			$text = '<h2>1. Parties</h2><p>Revised: payment within 30 days.</p>';
		} elseif ( false !== strpos( $system, 'You draft service contracts' ) ) {
			$text = "```html\n<h1>Services Agreement</h1><p style=\"color:red\">Between HelloProVision and the client.</p><script>alert(1)</script><p>Start date: [[TODO: start date]]</p><p></p>\n```";
		} elseif ( false !== strpos( $system, 'editing an existing proposal' ) ) {
			$text = wp_json_encode(
				array(
					'title'    => 'Új weboldal és helyi SEO (javított)',
					'tagline'  => 'Rövidebb átfutás',
					'sections' => array( array( 'title' => 'Áttekintés', 'html' => '<p>Javított szöveg.</p>' ) ),
					'timeline' => array( array( 'phase' => 'Tervezés', 'duration' => '1 hét', 'description' => '' ) ),
					'pricing'  => array( array( 'name' => 'Weboldal', 'description' => '', 'qty' => 1, 'unit_price' => 900000, 'recurring' => 'one_time', 'optional' => false ) ),
				)
			);
		} else {
			$text = "Íme az ajánlat:\n" . wp_json_encode(
				array(
					'title'      => 'Új weboldal és helyi SEO',
					'tagline'    => 'Több ajánlatkérés Budapestről',
					'sections'   => array(
						array( 'title' => 'Helyzetkép', 'html' => '<p>A jelenlegi oldal lassú.</p><script>x()</script>' ),
						array( 'title' => 'Megoldás', 'html' => '<ul><li>Új, gyors oldal</li></ul>' ),
					),
					'timeline'   => array(
						array( 'phase' => 'Tervezés', 'duration' => '2 hét', 'description' => 'Drótváz és design' ),
						array( 'phase' => 'Fejlesztés', 'duration' => '3 hét', 'description' => '' ),
					),
					'pricing'    => array(
						array( 'name' => 'Weboldal', 'description' => 'Tervezés és fejlesztés', 'qty' => 1, 'unit_price' => 1000000, 'recurring' => 'one_time', 'optional' => false ),
						array( 'name' => 'Helyi SEO', 'description' => '[[TODO: price]]', 'qty' => 1, 'unit_price' => 0, 'recurring' => 'monthly', 'optional' => false ),
						array( 'name' => 'Fotózás', 'description' => 'Egy napos fotózás', 'qty' => 1, 'unit_price' => 150000, 'recurring' => 'one_time', 'optional' => true ),
						array( 'name' => '', 'unit_price' => 5 ),
					),
					'valid_days' => 21,
				),
				JSON_UNESCAPED_UNICODE
			);
		}

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ), 'stop_reason' => 'end_turn' ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

function rest( string $method, string $path, array $params = array() ) {
	$r = new WP_REST_Request( $method, '/hpv/v1' . $path );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
}

$suffix  = wp_generate_password( 6, false, false );
$cleanup = array( 'clients' => array(), 'users' => array() );
$staff   = wp_insert_user( array( 'user_login' => 'dstaff_' . $suffix, 'user_email' => "dstaff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff', 'display_name' => 'Sam Sales' ) );
$cleanup['users'][] = $staff;

echo "Jogosultságok\n";
wp_set_current_user( $staff );
it( 'jog nélkül: szerződések, ajánlatok, minták zárva', 403 === rest( 'GET', '/docs/contracts' )[0] && 403 === rest( 'GET', '/docs/proposals' )[0] && 403 === rest( 'GET', '/docs/templates' )[0] );
hpv_p_set_caps( $staff, array( 'proposals' => true ) );
clean_user_cache( $staff );
it( 'ajánlat joggal szerződésminta nem hozható létre', 403 === rest( 'POST', '/docs/templates', array( 'type' => 'contract', 'name' => 'X' ) )[0] );
wp_set_current_user( 1 );

echo "Minták, HTML tisztítás\n";
$word = '<!--[if gte mso 9]><xml>junk</xml><![endif]--><style>p{color:red}</style><h1 class="MsoTitle" style="font-size:20pt">Agreement</h1><p class="MsoNormal" style="margin:0">Client: <b>{client}</b><o:p></o:p></p><p>&nbsp;</p><script>alert(1)</script><p><a href="javascript:alert(1)">x</a></p>';
list( $st, $tpl ) = rest( 'POST', '/docs/templates', array( 'type' => 'contract', 'name' => 'Web design agreement', 'language' => 'en', 'body' => $word, 'instructions' => 'Always 50% deposit.' ) );
it( 'Word-ből másolt minta megtisztítva', 200 === $st && '<h2>Agreement</h2><p>Client: <b>{client}</b></p><p><a href="alert(1)">x</a></p>' === $tpl['body'] || ( 200 === $st && false === strpos( $tpl['body'], 'style' ) && false === strpos( $tpl['body'], 'script' ) && false === strpos( $tpl['body'], 'javascript:' ) && 0 === strpos( $tpl['body'], '<h2>Agreement</h2>' ) ) );
list( , $ptpl ) = rest( 'POST', '/docs/templates', array( 'type' => 'proposal', 'name' => 'Weboldal ajánlat', 'language' => 'hu', 'body' => '<h2>Helyzetkép</h2><p>…</p><h2>Megoldás</h2>', 'instructions' => 'Barátságos, tegező hangnem nélkül.' ) );
wp_set_current_user( $staff );
list( , $list ) = rest( 'GET', '/docs/templates' );
it( 'csak a joga szerinti minták látszanak', array( 'proposal' ) === array_values( array_unique( array_column( $list['templates'], 'type' ) ) ) );
wp_set_current_user( 1 );
it( 'TODO felismerés', array( 'start date', 'price' ) === hpv_doc_todos( '<p>[[TODO: start date]] and <mark>[[TODO: price]]</mark></p>' ) );

echo "Szerződés\n";
$us = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Palm Dental ' . $suffix, 'email' => "office_$suffix@palm.test", 'street' => '5 Palm Ave', 'city' => 'Naples', 'state' => 'FL', 'zip' => '34102', 'status' => 'active' ) ) );
$cleanup['clients'][] = $us;
hpv_p_insert( 'subscription', hpv_p_sanitize( 'subscription', array( 'client_id' => $us, 'name' => 'Local SEO Plan', 'price' => '750', 'billing' => 'monthly', 'status' => 'active' ) ) );
list( $st, $c0 ) = rest( 'POST', '/docs/contracts', array( 'client_id' => $us, 'template_id' => $tpl['id'] ) );
it( 'mintából AI nélkül: a minta szövege, angolul, piszkozat', 200 === $st && $tpl['body'] === $c0['body'] && 'en' === $c0['language'] && 'draft' === $c0['status'] && false !== strpos( $c0['title'], 'Palm Dental' ) );
list( $st, $err ) = rest( 'POST', '/docs/contracts', array( 'client_id' => $us, 'template_id' => $tpl['id'], 'ai' => 1 ) );
it( 'AI kulcs nélkül: érthető hiba', 502 === $st && 'ai_off' === $err['code'] );

define( 'HPV_AI_API_KEY', 'test-ai' );
list( $st, $c1 ) = rest( 'POST', '/docs/contracts', array( 'client_id' => $us, 'template_id' => $tpl['id'], 'ai' => 1, 'instructions' => 'Net 30 payment terms.' ) );
$req = ai_last();
it( 'AI szerződés: minta, ügyfél, szolgáltatások, utasítás a kérésben', 200 === $st && false !== strpos( $req['messages'][0]['content'], '<h2>Agreement</h2>' ) && false !== strpos( $req['messages'][0]['content'], 'Naples, FL 34102' ) && false !== strpos( $req['messages'][0]['content'], 'Local SEO Plan: $750.00 (monthly)' ) && false !== strpos( $req['messages'][0]['content'], 'Net 30 payment terms.' ) && false !== strpos( $req['messages'][0]['content'], 'Always 50% deposit.' ) );
it( 'AI szerződés: angol, floridai jog, nem talál ki adatot', false !== strpos( $req['system'], 'English (US)' ) && false !== strpos( $req['system'], 'Florida' ) && false !== strpos( $req['system'], '[[TODO' ) );
it( 'AI válasz megtisztítva, TODO kiemelve', false === strpos( $c1['body'], '```' ) && false === strpos( $c1['body'], 'script' ) && false === strpos( $c1['body'], 'style=' ) && 0 === strpos( $c1['body'], '<h2>Services Agreement</h2>' ) && false !== strpos( $c1['body'], '<mark>[[TODO: start date]]</mark>' ) && 1 === $c1['todos'] );
list( $st, $err ) = rest( 'POST', '/docs/contracts/' . $c1['id'] . '/send' );
it( 'kitöltetlen résszel nem küldhető', 409 === $st && false !== strpos( $err['message'], 'start date' ) );
list( , $c1 ) = rest( 'POST', '/docs/contracts/' . $c1['id'], array( 'body' => str_replace( '<mark>[[TODO: start date]]</mark>', 'October 1, 2026', $c1['body'] ) ) );
list( $st, $err ) = rest( 'POST', '/docs/contracts/' . $c1['id'] . '/send' );
it( 'portál-felhasználó nélkül nem küldhető (érthető üzenet)', 409 === $st && 'no_user' === $err['code'] );
$dana = hpv_p_invite_user( $us, 'Dana Palm', "dana_$suffix@palm.test" );
$cleanup['users'][] = $dana->ID;
list( $st, $c1 ) = rest( 'POST', '/docs/contracts/' . $c1['id'] . '/send' );
it( 'kiküldve aláírásra, e-mail az ügyfélnek', 200 === $st && 'sent' === $c1['status'] && array_filter( mails_to( "dana_$suffix@palm.test" ), fn( $m ) => false !== strpos( $m['subject'], 'sign' ) ) );
list( $st, $c1 ) = rest( 'POST', '/docs/contracts/' . $c1['id'] . '/revise', array( 'instructions' => 'Payment within 30 days.' ) );
it( 'AI módosítás: a jelenlegi szöveg és a kérés megy át', 200 === $st && false !== strpos( ai_last()['messages'][0]['content'], 'October 1, 2026' ) && false !== strpos( ai_last()['messages'][0]['content'], 'Payment within 30 days.' ) && false !== strpos( $c1['body'], 'Revised' ) );
hpv_p_sign_contract( $c1['id'], $us, $dana, 'Dana Palm' );
it( 'aláírt szerződés nem módosítható', 409 === rest( 'POST', '/docs/contracts/' . $c1['id'], array( 'title' => 'x' ) )[0] && 409 === rest( 'POST', '/docs/contracts/' . $c1['id'] . '/revise', array( 'instructions' => 'x' ) )[0] );
list( , $v ) = rest( 'DELETE', '/docs/contracts/' . $c1['id'] );
it( 'aláírt szerződés törlés helyett visszavonva', 'void' === $v['status'] && hpv_p_get( 'contract', $c1['id'] ) );
list( , $d ) = rest( 'DELETE', '/docs/contracts/' . $c0['id'] );
it( 'piszkozat törölhető', ! empty( $d['deleted'] ) && ! hpv_p_get( 'contract', $c0['id'] ) );

echo "Ajánlat\n";
wp_set_current_user( $staff );
list( $st, $lead ) = rest( 'POST', '/docs/leads', array( 'name' => 'Duna Kert Kft ' . $suffix, 'contact_name' => 'Kiss Anna', 'email' => "anna_$suffix@dunakert.test", 'country' => 'HU' ) );
$cleanup['clients'][] = $lead['id'];
it( 'új érdeklődő (lead) gyorsan', 200 === $st && 'lead' === hpv_p_get( 'client', $lead['id'] )['status'] && 'HU' === hpv_p_get( 'client', $lead['id'] )['country'] );
$call = hpv_p_insert( 'call', array( 'client_id' => $lead['id'], 'title' => 'Discovery', 'status' => 'done', 'started_at' => '2026-09-20 10:00:00', 'summary' => 'Anna wants more quote requests from Budapest.', 'ai_data' => wp_json_encode( array( 'decisions' => array( 'Rebuild the site' ), 'action_items' => array( array( 'title' => 'Send proposal' ) ), 'internal_notes' => array( 'Budget-sensitive' ) ) ) ) );
list( , $ctx ) = rest( 'GET', '/docs/context', array( 'client_id' => $lead['id'] ) );
it( 'környezet: nyelv, pénznem, hívás-összefoglaló', 'hu' === $ctx['language'] && 'HUF' === $ctx['currency'] && 1 === count( $ctx['calls'] ) );
list( $st, $p ) = rest( 'POST', '/docs/proposals', array( 'client_id' => $lead['id'], 'template_id' => $ptpl['id'], 'ai' => 1, 'brief' => 'Budget around 1M HUF.', 'call_ids' => array( $call ) ) );
$req = ai_last();
it( 'AI ajánlat: brief, hívás, belső megjegyzés jelölve, árlista, minta', 200 === $st && false !== strpos( $req['messages'][0]['content'], 'Budget around 1M HUF.' ) && false !== strpos( $req['messages'][0]['content'], 'Anna wants more quote requests' ) && false !== strpos( $req['messages'][0]['content'], 'Internal notes (do not quote): Budget-sensitive' ) && false !== strpos( $req['messages'][0]['content'], '<h2>Helyzetkép</h2>' ) && false !== strpos( $req['messages'][0]['content'], '<proposal_currency>HUF</proposal_currency>' ) );
it( 'AI ajánlat: magyarul, a pénznem szabálya', false !== strpos( $req['system'], 'Hungarian' ) && false !== strpos( $req['system'], 'Prices are in HUF' ) );
it( 'ajánlat felépítve: szám, token, fejezetek, ütemterv, tételek', preg_match( '/^P-\d{4}-\d{3,}$/', $p['number'] ) && 32 === strlen( wp_parse_args( wp_parse_url( $p['url'], PHP_URL_QUERY ) )['proposal'] ?? '' ) && 2 === count( $p['sections'] ) && 2 === count( $p['timeline'] ) && 3 === count( $p['pricing'] ) && 'HUF' === $p['currency'] && 'hu' === $p['language'] );
it( 'AI tartalom megtisztítva (szkript ki), üres tétel kihagyva', false === strpos( wp_json_encode( $p['sections'] ), 'script' ) && ! in_array( '', array_column( $p['pricing'], 'name' ), true ) );
it( 'összesen: választható nélkül', 100000000 === $p['totals']['one_time'] && 0 === $p['totals']['monthly'] );
it( 'érvényesség az AI szerint (21 nap)', gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +21 days' ) ) === $p['valid_until'] );
list( $st, $err ) = rest( 'POST', '/docs/proposals/' . $p['id'] . '/send' );
it( 'kitöltetlen árral nem küldhető', 409 === $st && false !== strpos( $err['message'], 'price' ) );
$pricing                   = $p['pricing'];
$pricing[1]['unit_price']  = '120000';
$pricing[1]['description'] = 'Havi SEO';
list( , $p ) = rest( 'POST', '/docs/proposals/' . $p['id'], array( 'pricing' => $pricing, 'title' => 'Új weboldal és helyi SEO' ) );
list( $st, $p ) = rest( 'POST', '/docs/proposals/' . $p['id'] . '/send', array( 'message' => 'Szia Anna!' ) );
$mail = mails_to( "anna_$suffix@dunakert.test" );
it( 'kiküldve magyar e-mailben a publikus linkkel', 200 === $st && 'sent' === $p['status'] && $mail && false !== strpos( $mail[0]['subject'], 'Ajánlat:' ) && false !== strpos( $mail[0]['message'], 'proposal=' ) && false !== strpos( $mail[0]['message'], 'Szia Anna!' ) );

$row = hpv_p_get( 'proposal', $p['id'] );
ob_start();
hpv_prop_render( $row, '' );
$html = ob_get_clean();
it( 'publikus oldal: tartalom, magyar feliratok, ÁFA megjegyzés, választható tétel', false !== strpos( $html, 'Új weboldal és helyi SEO' ) && false !== strpos( $html, 'Ajánlat elfogadása' ) && false !== strpos( $html, 'nettó árak' ) && false !== strpos( $html, 'name="options[]" value="2"' ) && false !== strpos( $html, "1\u{00A0}000\u{00A0}000\u{00A0}Ft" ) && false !== strpos( $html, 'noindex' ) );

wp_set_current_user( 0 );
$GLOBALS['hpv_it_mail'] = array();
hpv_prop_track_view( $row );
hpv_prop_track_view( hpv_p_get( 'proposal', $p['id'] ) );
$row = hpv_p_get( 'proposal', $p['id'] );
it( 'megnyitás követve, első megnyitásról egy értesítés', 2 === (int) $row['view_count'] && 'viewed' === $row['status'] && 1 === count( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => false !== strpos( $m['subject'], 'Megnyitották' ) ) ) );

$test_ip                = '203.0.113.' . wp_rand( 1, 254 );
$_SERVER['REMOTE_ADDR'] = $test_ip;
delete_transient( 'hpv_prop_rate_' . md5( $test_ip ) );
$_POST                  = array( 'hpv_prop_action' => 'accept', '_wpnonce' => wp_create_nonce( 'hpv_prop_' . $row['token'] ), 'name' => 'Kiss Anna', 'email' => "anna_$suffix@dunakert.test", 'options' => array( '2', '0', '99' ) );
it( 'elfogadás jelölőnégyzet nélkül: hiba', 'error_name' === hpv_prop_handle_post( $row ) && 'viewed' === hpv_p_get( 'proposal', $p['id'] )['status'] );
$_POST['agree'] = '1';
$_POST['_wpnonce'] = 'bad';
it( 'hamis űrlap-azonosítóval nem fogadható el', 'error' === hpv_prop_handle_post( $row ) );
$_POST['_wpnonce'] = wp_create_nonce( 'hpv_prop_' . $row['token'] );
$res = hpv_prop_handle_post( $row );
$acc = hpv_p_get( 'proposal', $p['id'] );
$items = json_decode( $acc['accepted_items'], true );
it( 'elfogadva: név, e-mail, IP, lenyomat', 'accepted' === $res && 'accepted' === $acc['status'] && 'Kiss Anna' === $acc['accepted_name'] && $test_ip === $acc['accepted_ip'] && 64 === strlen( $acc['accepted_hash'] ) );
it( 'csak a bejelölt választható tétel került be (a nem létező nem)', 3 === count( $items ) && 'Fotózás' === $items[2]['name'] );
it( 'az érdeklődő aktív ügyfél lett', 'active' === hpv_p_get( 'client', $lead['id'] )['status'] );
it( 'értesítés a csapatnak és visszaigazolás az ügyfélnek', array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => false !== strpos( $m['subject'], 'Elfogadták' ) ) && array_filter( mails_to( "anna_$suffix@dunakert.test" ), fn( $m ) => false !== strpos( $m['subject'], 'Elfogadta' ) ) );
it( 'másodszor nem fogadható el', '' === hpv_prop_handle_post( $acc ) );
$_POST = array();
wp_set_current_user( $staff );

list( $st, ) = rest( 'POST', '/docs/proposals/' . $p['id'], array( 'title' => 'x' ) );
it( 'elfogadott ajánlat nem módosítható, nem vonható vissza', 409 === $st && 409 === rest( 'DELETE', '/docs/proposals/' . $p['id'] )[0] );
list( , $full ) = rest( 'GET', '/docs/proposals/' . $p['id'] );
it( 'elfogadás adatai a CRM-ben (összegekkel)', 115000000 === $full['acceptance']['totals']['one_time'] && 12000000 === $full['acceptance']['totals']['monthly'] );

echo "Elfogadás után\n";
list( $st, ) = rest( 'POST', '/docs/proposals/' . $p['id'] . '/convert', array( 'what' => 'invoice' ) );
it( 'számlázási jog nélkül nincs számla', 403 === $st );
list( $st, ) = rest( 'POST', '/docs/proposals/' . $p['id'] . '/convert', array( 'what' => 'contract' ) );
it( 'szerződés-jog nélkül nincs szerződés', 403 === $st );
list( $st, $conv ) = rest( 'POST', '/docs/proposals/' . $p['id'] . '/convert', array( 'what' => 'project' ) );
$tasks = hpv_p_find( 'task', array( 'project_id' => $conv['target']['id'] ?? 0 ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
it( 'projekt az ütemterv lépéseivel', 200 === $st && 'project' === $conv['target']['type'] && 2 === count( $tasks ) && 'Tervezés' === $tasks[0]['title'] );
wp_set_current_user( 1 );
list( $st, $conv ) = rest( 'POST', '/docs/proposals/' . $p['id'] . '/convert', array( 'what' => 'invoice', 'deposit' => 50 ) );
$inv   = hpv_p_get( 'invoice', $conv['target']['id'] ?? 0 );
$lines = hpv_p_find( 'invoice_item', array( 'invoice_id' => $inv['id'] ?? 0 ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
it( 'előleg számla: egyszeri tételek fele, forintban, piszkozat', 200 === $st && 'draft' === $inv['status'] && 'HUF' === $inv['currency'] && 2 === count( $lines ) && 50000000 === hpv_p_to_cents( $lines[0]['unit_price'] ) && false !== strpos( $lines[0]['description'], '50% előleg' ) );
list( $st, $conv ) = rest( 'POST', '/docs/proposals/' . $p['id'] . '/convert', array( 'what' => 'subscriptions' ) );
$subs = hpv_p_find( 'subscription', array( 'client_id' => $lead['id'] ) );
it( 'havi díj előfizetésként', 200 === $st && 1 === count( $subs ) && 'monthly' === $subs[0]['billing'] && 12000000 === hpv_p_to_cents( $subs[0]['price'] ) );
list( $st, $conv ) = rest( 'POST', '/docs/proposals/' . $p['id'] . '/convert', array( 'what' => 'contract', 'ai' => 1 ) );
$ct = hpv_p_get( 'contract', $conv['target']['id'] ?? 0 );
it( 'szerződés AI-val az elfogadott tételekből, magyarul', 200 === $st && $ct && (int) $p['id'] === (int) $ct['proposal_id'] && 'hu' === $ct['language'] && false !== strpos( ai_last()['messages'][0]['content'], 'Fotózás' ) && false !== strpos( ai_last()['system'], 'Hungarian' ) && false !== strpos( ai_last()['system'], 'Hungarian law' ) );
it( 'az ajánlaton a szerződés és a számla kapcsolata', (int) $ct['id'] === (int) hpv_p_get( 'proposal', $p['id'] )['contract_id'] && (int) $inv['id'] === (int) hpv_p_get( 'proposal', $p['id'] )['invoice_id'] );

echo "Elutasítás, lejárat, módosítás\n";
$p2 = hpv_prop_create( hpv_p_get( 'client', $lead['id'] ), array( 'title' => 'Hirdetés kampány', 'pricing' => array( array( 'name' => 'Google Ads', 'qty' => 1, 'unit_price' => 200000, 'recurring' => 'monthly' ) ) ), 1 );
list( , $p2d ) = rest( 'POST', '/docs/proposals/' . $p2 . '/revise', array( 'instructions' => 'Legyen rövidebb.' ) );
it( 'AI módosítás: a teljes ajánlat és a kérés megy át', false !== strpos( ai_last()['messages'][0]['content'], 'Hirdetés kampány' ) && false !== strpos( ai_last()['messages'][0]['content'], 'Legyen rövidebb.' ) && 'Új weboldal és helyi SEO (javított)' === $p2d['title'] );
hpv_p_update( 'proposal', $p2, array( 'status' => 'sent' ) );
wp_set_current_user( 0 );
$row2  = hpv_p_get( 'proposal', $p2 );
$_POST = array( 'hpv_prop_action' => 'decline', '_wpnonce' => wp_create_nonce( 'hpv_prop_' . $row2['token'] ), 'reason' => 'Túl drága most.' );
it( 'elutasítás indokkal', 'declined' === hpv_prop_handle_post( $row2 ) && 'declined' === hpv_p_get( 'proposal', $p2 )['status'] && 'Túl drága most.' === hpv_p_get( 'proposal', $p2 )['decline_reason'] );
$p3 = hpv_prop_create( hpv_p_get( 'client', $lead['id'] ), array( 'title' => 'Régi', 'pricing' => array( array( 'name' => 'X', 'unit_price' => 1 ) ) ), 1 );
hpv_p_update( 'proposal', $p3, array( 'status' => 'sent', 'valid_until' => '2020-01-01' ) );
$row3  = hpv_p_get( 'proposal', $p3 );
$_POST = array( 'hpv_prop_action' => 'accept', '_wpnonce' => wp_create_nonce( 'hpv_prop_' . $row3['token'] ), 'name' => 'Kiss Anna', 'email' => 'a@b.test', 'agree' => 1 );
it( 'lejárt ajánlat nem fogadható el', hpv_prop_is_expired( $row3 ) && '' === hpv_prop_handle_post( $row3 ) && 'sent' === hpv_p_get( 'proposal', $p3 )['status'] );
$_POST = array();
wp_set_current_user( 1 );
it( 'lejárt státusz a listában', 'expired' === hpv_prop_format( $row3 )['status'] );
list( , $dv ) = rest( 'DELETE', '/docs/proposals/' . $p3 );
it( 'kiküldött ajánlat visszavonva, nem törölve', 'void' === $dv['status'] );
$p4 = hpv_prop_create( hpv_p_get( 'client', $lead['id'] ), array( 'title' => 'Piszkozat' ), 1 );
it( 'piszkozat törölhető', ! empty( rest( 'DELETE', '/docs/proposals/' . $p4 )[1]['deleted'] ) );

echo "Portál\n";
$anna = hpv_p_invite_user( $lead['id'], 'Kiss Anna', "anna_$suffix@dunakert.test" );
$cleanup['users'][] = $anna->ID;
wp_set_current_user( $anna->ID );
$_GET = array( 'view' => 'proposals' );
$page = hpv_p_portal_app();
$_GET = array();
wp_set_current_user( 1 );
it( 'az ügyfél látja az ajánlatait (a piszkozatot és a visszavontat nem)', false !== strpos( $page, 'Új weboldal és helyi SEO' ) && false !== strpos( $page, 'proposal=' ) && false === strpos( $page, 'Régi' ) );

echo "Takarítás\n";
foreach ( $cleanup['clients'] as $cid ) {
	hpv_p_delete( 'client', $cid );
}
hpv_p_delete( 'doc_template', (int) $tpl['id'] );
hpv_p_delete( 'doc_template', (int) $ptpl['id'] );
it( 'ügyfél törlésével az ajánlatai is törlődnek', ! hpv_p_find( 'proposal', array( 'client_id' => $lead['id'] ) ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $uid ) {
	wp_delete_user( $uid );
}

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
