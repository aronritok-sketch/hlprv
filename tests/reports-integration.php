<?php
/**
 * Havi riport + adatforrások integrációs tesztje: Search Console, GA4, Google Ads, Meta (álszerverrel), AI (álszerverrel),
 * munka és következő hónap a feladatokból, kiküldés, portál, automatikus piszkozat.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen, kulcsok NÉLKÜL):
 *   wp eval-file tests/reports-integration.php
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_report_build' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}
foreach ( array( 'HPV_GOOGLE_CLIENT_ID', 'HPV_META_ACCESS_TOKEN', 'HPV_AI_API_KEY' ) as $const ) {
	if ( defined( $const ) ) {
		echo "Ezt a tesztet kulcsok nélküli teszt WordPressen futtasd ($const).\n";
		exit( 1 );
	}
}

$GLOBALS['hpv_it_fail'] = 0;
$GLOBALS['hpv_it_mail'] = array();
$GLOBALS['http']        = array();

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

define( 'HPV_GOOGLE_CLIENT_ID', 'gid' );
define( 'HPV_GOOGLE_CLIENT_SECRET', 'gsecret' );
define( 'HPV_GOOGLE_ADS_DEVELOPER_TOKEN', 'devtoken' );
define( 'HPV_GOOGLE_ADS_LOGIN_CUSTOMER_ID', '111-222-3333' );
define( 'HPV_META_ACCESS_TOKEN', 'metatoken' );
define( 'HPV_AI_API_KEY', 'ai-key' );

$GLOBALS['mock'] = array( 'ga4_fail' => false, 'token_calls' => 0 );

function reply( $body, int $code = 200 ): array {
	return array( 'headers' => array(), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$body            = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : ( $args['body'] ?? null );
		$GLOBALS['http'][] = array( 'url' => $url, 'headers' => $args['headers'] ?? array(), 'body' => $body );
		if ( HPV_GOOGLE_TOKEN_URL === $url ) {
			$GLOBALS['mock']['token_calls']++;
			return reply( array( 'access_token' => 'ya29.test', 'expires_in' => 3600 ) );
		}
		if ( false !== strpos( $url, 'searchAnalytics/query' ) ) {
			$rows = array();
			// Szeptember: 2 nap, október: 2 nap (a teszt riportja októberi).
			foreach ( array( array( '2026-09-10', 100, 2000, 12 ), array( '2026-09-20', 100, 2000, 10 ), array( '2026-10-05', 150, 2500, 9 ), array( '2026-10-15', 150, 2500, 7 ) ) as $r ) {
				$rows[] = array( 'keys' => array( $r[0] ), 'clicks' => $r[1], 'impressions' => $r[2], 'ctr' => $r[1] / $r[2], 'position' => $r[3] );
			}
			return reply( array( 'rows' => $rows ) );
		}
		if ( false !== strpos( $url, ':runReport' ) ) {
			if ( $GLOBALS['mock']['ga4_fail'] ) {
				return reply( array( 'error' => array( 'code' => 403, 'message' => 'User does not have sufficient permissions for this property.' ) ), 403 );
			}
			$row = fn( $ym, $ch, $u, $s, $e ) => array( 'dimensionValues' => array( array( 'value' => $ym ), array( 'value' => $ch ) ), 'metricValues' => array( array( 'value' => (string) $u ), array( 'value' => (string) $s ), array( 'value' => (string) $e ) ) );
			return reply( array( 'rows' => array( $row( '202609', 'Organic Search', 400, 500, 10 ), $row( '202609', 'Direct', 200, 250, 5 ), $row( '202610', 'Organic Search', 600, 700, 18 ), $row( '202610', 'Direct', 200, 260, 6 ) ) ) );
		}
		if ( false !== strpos( $url, 'googleAds:searchStream' ) ) {
			return reply( array( array( 'results' => array(
				array( 'customer' => array( 'currencyCode' => 'USD' ), 'segments' => array( 'month' => '2026-09-01' ), 'metrics' => array( 'costMicros' => '500000000', 'clicks' => '250', 'conversions' => 10 ) ),
				array( 'segments' => array( 'month' => '2026-10-01' ), 'metrics' => array( 'costMicros' => '600000000', 'clicks' => '300', 'conversions' => 20 ) ),
			) ) ) );
		}
		if ( false !== strpos( $url, 'graph.facebook.com' ) && false !== strpos( $url, '/insights' ) ) {
			return reply( array( 'data' => array(
				array( 'date_start' => '2026-09-01', 'spend' => '300.00', 'impressions' => '40000', 'inline_link_clicks' => '800', 'actions' => array( array( 'action_type' => 'lead', 'value' => '15' ), array( 'action_type' => 'onsite_conversion.lead_grouped', 'value' => '15' ) ) ),
				array( 'date_start' => '2026-10-01', 'spend' => '320.00', 'impressions' => '42000', 'inline_link_clicks' => '900', 'actions' => array( array( 'action_type' => 'lead', 'value' => '20' ) ) ),
			) ) );
		}
		if ( false !== strpos( $url, '/sites' ) && false === strpos( $url, 'searchAnalytics' ) ) {
			return reply( array( 'siteEntry' => array( array( 'siteUrl' => 'sc-domain:kovacskert.test', 'permissionLevel' => 'siteOwner' ) ) ) );
		}
		if ( false !== strpos( $url, 'accountSummaries' ) ) {
			return reply( array( 'accountSummaries' => array( array( 'displayName' => 'Kovács Kert', 'propertySummaries' => array( array( 'property' => 'properties/412345678', 'displayName' => 'kovacskert.hu' ) ) ) ) ) );
		}
		if ( false !== strpos( $url, 'listAccessibleCustomers' ) ) {
			return reply( array( 'resourceNames' => array( 'customers/1234567890' ) ) );
		}
		if ( false !== strpos( $url, '/me/adaccounts' ) ) {
			return reply( array( 'data' => array( array( 'name' => 'Kovács Kert', 'account_id' => '998877' ) ) ) );
		}
		if ( HPV_AI_API === $url ) {
			$user = $body['messages'][0]['content'] ?? '';
			$GLOBALS['mock']['ai_prompt'] = $user;
			return reply( array( 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'summary_html' => '<p>Októberben 50%-kal több kattintás jött a Google-ből.</p><script>x</script>', 'highlights' => array( '300 kattintás a Google-ből (+50%)', '24 fontos esemény' ), 'next' => array( 'Új szolgáltatásoldal' ) ) ) ) ), 'stop_reason' => 'end_turn' ) );
		}
		return new WP_Error( 'offline', 'unexpected ' . $url );
	},
	10,
	3
);

$suffix = wp_generate_password( 5, false, false );
wp_set_current_user( 1 );
hpv_google_save_tokens( array( 'refresh_token' => '1//refresh', 'access_token' => '', 'expires_at' => 0 ) );
$hu = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Kovács Kert $suffix", 'country' => 'HU', 'status' => 'active', 'gsc_property' => 'sc-domain:kovacskert.test', 'ga4_property' => '412345678', 'gads_customer' => '123-456-7890', 'meta_ad_account' => 'act_998877' ) ) );
$maria = hpv_p_invite_user( $hu, 'Kovács Mária', "maria_$suffix@kovacs.test" );
$tpl   = hpv_p_insert( 'project', array( 'client_id' => 0, 'name' => 'SEO havi', 'is_template' => 1, 'start_date' => '2026-01-01' ) );
$proj  = hpv_p_insert( 'project', array( 'client_id' => $hu, 'name' => 'Helyi SEO', 'status' => 'in_progress', 'visible' => 1, 'kind' => 'local', 'package_id' => $tpl ) );
$done  = hpv_p_insert( 'task', array( 'project_id' => $proj, 'title' => '4 Cégprofil poszt', 'status' => 'done', 'visible' => 1, 'completed_at' => '2026-10-12 10:00:00' ) );
hpv_p_insert( 'task', array( 'project_id' => $proj, 'title' => 'Belső audit', 'status' => 'done', 'visible' => 0, 'completed_at' => '2026-10-13 10:00:00' ) );
hpv_p_insert( 'task', array( 'project_id' => $proj, 'title' => 'Szeptemberi munka', 'status' => 'done', 'visible' => 1, 'completed_at' => '2026-09-12 10:00:00' ) );
hpv_p_insert( 'task', array( 'project_id' => $proj, 'title' => 'Novemberi blogcikk', 'status' => 'todo', 'visible' => 1, 'due_date' => '2026-11-20' ) );
$ap = hpv_p_insert( 'approval', array( 'client_id' => $hu, 'title' => 'Őszi akció', 'type' => 'social', 'status' => 'published', 'publish_date' => '2026-10-05' ) );
$GLOBALS['hpv_it_mail'] = array();

echo "Adatforrások\n";
$gsc = hpv_gsc_metrics( 'sc-domain:kovacskert.test', '2026-10' );
$by  = array_column( $gsc, null, 'key' );
it( 'Search Console: kattintás, megjelenés, CTR, helyezés havonta', 300.0 === $by['gsc_clicks']['value'] && 200.0 === $by['gsc_clicks']['prev'] && 5000.0 === $by['gsc_impressions']['value'] && abs( $by['gsc_ctr']['value'] - 6.0 ) < 0.01 && abs( $by['gsc_position']['value'] - 8.0 ) < 0.01 && 'down' === $by['gsc_position']['better'] );
it( 'Search Console: 6 hónapos időszak, tulajdon az URL-ben', false !== strpos( $GLOBALS['http'][1]['url'], rawurlencode( 'sc-domain:kovacskert.test' ) ) && '2026-05-01' === $GLOBALS['http'][1]['body']['startDate'] && '2026-10-31' === $GLOBALS['http'][1]['body']['endDate'] );
it( 'Google token frissítve, Bearer fejléc', 1 === $GLOBALS['mock']['token_calls'] && 'Bearer ya29.test' === $GLOBALS['http'][1]['headers']['Authorization'] );
$ga = array_column( hpv_ga4_metrics( '412345678', '2026-10' ), null, 'key' );
it( 'GA4: látogatók, munkamenet, organikus, fontos események', 800.0 === $ga['ga4_users']['value'] && 960.0 === $ga['ga4_sessions']['value'] && 700.0 === $ga['ga4_organic']['value'] && 500.0 === $ga['ga4_organic']['prev'] && 24.0 === $ga['ga4_key_events']['value'] );
$ads = array_column( hpv_gads_metrics( '123-456-7890', '2026-10' ), null, 'key' );
$req = end( $GLOBALS['http'] );
it( 'Google Ads: költés, kattintás, konverzió, CPA', 600.0 === $ads['gads_cost']['value'] && 300.0 === $ads['gads_clicks']['value'] && 30.0 === $ads['gads_cpa']['value'] && 50.0 === $ads['gads_cpa']['prev'] && 'neutral' === $ads['gads_cost']['better'] );
it( 'Google Ads: a fiók pénzneme a pénz-mutatókon', 'USD' === $ads['gads_cost']['currency'] && ! isset( $ads['gads_clicks']['currency'] ) );
it( 'Google Ads: fejlesztői token, kezelői fiók, ügyfél kötőjel nélkül', 'devtoken' === $req['headers']['developer-token'] && '1112223333' === $req['headers']['login-customer-id'] && false !== strpos( $req['url'], '/customers/1234567890/googleAds:searchStream' ) && false !== strpos( $req['body']['query'], "BETWEEN '2026-05-01' AND '2026-10-31'" ) );
$meta = array_column( hpv_meta_metrics( 'act_998877', '2026-10' ), null, 'key' );
it( 'Meta: költés, lead (duplikáció nélkül), CPL', 320.0 === $meta['meta_spend']['value'] && 20.0 === $meta['meta_leads']['value'] && 15.0 === $meta['meta_leads']['prev'] && 16.0 === $meta['meta_cpl']['value'] );
it( 'Meta: fiók act_ előtaggal, havi bontás', false !== strpos( end( $GLOBALS['http'] )['url'], '/act_998877/insights' ) && false !== strpos( end( $GLOBALS['http'] )['url'], 'time_increment=monthly' ) );
$acc = hpv_conn_accounts();
it( 'fiókválasztó: GSC, GA4, Ads, Meta', 'sc-domain:kovacskert.test' === $acc['gsc'][0]['id'] && '412345678' === $acc['ga4'][0]['id'] && '123-456-7890' === $acc['gads'][0]['label'] && 'act_998877' === $acc['meta'][0]['id'] && ! $acc['errors'] );

echo "Riport összerakása\n";
$res = rest_do_request( ( function () use ( $hu ) {
	$r = new WP_REST_Request( 'POST', '/hpv/v1/reports' );
	$r->set_body_params( array( 'client_id' => $hu, 'period' => '2026-10' ) );
	return $r;
} )() );
$rep = $res->get_data();
it( 'elkészült, magyar címmel', 200 === $res->get_status() && 'draft' === $rep['status'] && 'Havi riport — 2026. október' === $rep['title'] );
it( '17 mutató 4 forrásból', 17 === count( $rep['metrics'] ) && 4 === count( $rep['sources'] ) );
it( 'elvégzett munka: a hónapban lezárt látható feladat + megjelent tartalom', array( '4 Cégprofil poszt', 'Közösségi poszt: Őszi akció' ) === array_column( $rep['work'], 'text' ) );
it( 'következő hónap a feladatokból', array( 'Novemberi blogcikk' ) === array_column( $rep['next'], 'text' ) );
it( 'AI-összefoglaló (megtisztítva), kiemelések', false !== strpos( $rep['summary'], '50%' ) && false === strpos( $rep['summary'], 'script' ) && 2 === count( $rep['highlights'] ) );
it( 'az AI csak a megadott számokat kapja, magyarul írjon', false !== strpos( $GLOBALS['mock']['ai_prompt'], 'Clicks from Google: 300' ) && false !== strpos( $GLOBALS['mock']['ai_prompt'], '4 Cégprofil poszt' ) );
$again = hpv_report_build( $hu, '2026-10', 1, false );
it( 'ugyanarra a hónapra nem készül második', (int) $again['id'] === $rep['id'] && 1 === count( hpv_p_find( 'report', array( 'client_id' => $hu ) ) ) );

echo "Szerkesztés\n";
$req = new WP_REST_Request( 'POST', '/hpv/v1/reports/' . $rep['id'] );
$req->set_header( 'Content-Type', 'application/json' );
$metrics   = $rep['metrics'];
$metrics[] = array( 'section' => 'Google Cégprofil', 'label' => 'Hívások a Cégprofilból', 'value' => 42, 'prev' => 30, 'manual' => true );
$req->set_body( wp_json_encode( array( 'summary' => '<p>Kézzel javított összefoglaló. [[TODO: ár]]</p>', 'metrics' => $metrics, 'highlights' => array( 'Egy', '', 'Kettő' ) ) ) );
$rep = rest_do_request( $req )->get_data();
it( 'kézi mutató és kiemelés mentve', 18 === count( $rep['metrics'] ) && array( 'Egy', 'Kettő' ) === $rep['highlights'] );
$res = rest_do_request( new WP_REST_Request( 'POST', '/hpv/v1/reports/' . $rep['id'] . '/send' ) );
it( '[[TODO]] jelöléssel nem küldhető', 400 === $res->get_status() );
$rebuilt = hpv_report_build( $hu, '2026-10', 1, false, $rep['id'] );
it( 'újraépítés: a kézi mutató megmarad, az összefoglaló is', 18 === count( hpv_report_data( $rebuilt )['metrics'] ) && false !== strpos( $rebuilt['summary'], 'Kézzel javított' ) );
hpv_p_update( 'report', $rep['id'], array( 'summary' => '<p>Kézzel javított összefoglaló.</p>' ) );

echo "Kiküldés és portál\n";
$GLOBALS['hpv_it_mail'] = array();
$res = rest_do_request( new WP_REST_Request( 'POST', '/hpv/v1/reports/' . $rep['id'] . '/send' ) );
$mail = array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( "maria_$suffix@kovacs.test", (array) $m['to'], true ) ) );
it( 'kiküldve, magyar e-mail', 200 === $res->get_status() && 'sent' === $res->get_data()['status'] && 1 === count( $mail ) && 'Havi riport: 2026. október' === $mail[0]['subject'] );
it( 'kiküldött riport nem építhető újra', is_wp_error( hpv_report_build( $hu, '2026-10', 1, false ) ) );
wp_set_current_user( $maria->ID );
$_GET = array( 'view' => 'reports', 'id' => $rep['id'] );
$page = hpv_p_portal_app();
$_GET = array( 'view' => 'overview' );
$over = hpv_p_portal_app();
$_GET = array();
wp_set_current_user( 1 );
it( 'portál: magyar szekciók és mutatók', false !== strpos( $page, 'Google keresés' ) && false !== strpos( $page, 'Kattintás a Google-ből' ) && false !== strpos( $page, 'Hirdetési költés' ) && false !== strpos( $page, 'Hívások a Cégprofilból' ) );
it( 'portál: változás iránnyal (kattintás +50% jó, helyezés javult)', false !== strpos( $page, 'is-good">+50%' ) && false !== strpos( $page, 'is-good">▲ 3,0' ) );
it( 'portál: a hirdetési költés a fiók pénznemében', false !== strpos( $page, '$600.00' ) );
it( 'portál: magyar számformátum és grafikon', false !== strpos( $page, "5\u{00A0}000" ) && false !== strpos( $page, 'hpv-bars' ) );
it( 'áttekintés: új riport a teendők között', false !== strpos( $over, 'Új havi riport: 2026. október' ) );

echo "Hibák és automatikus piszkozat\n";
$GLOBALS['mock']['ga4_fail'] = true;
$sep = hpv_report_build( $hu, '2026-09', 1, false );
it( 'egy forrás hibája nem állítja meg a riportot, a hiba látszik', ! is_wp_error( $sep ) && 13 === count( hpv_report_data( $sep )['metrics'] ) && false !== strpos( implode( ' ', hpv_report_data( $sep )['errors'] ), 'sufficient permissions' ) );
hpv_p_delete( 'report', (int) $sep['id'] );
$GLOBALS['mock']['ga4_fail'] = false;
$GLOBALS['hpv_it_mail'] = array();
$made = hpv_report_auto( '2026-11-02' );
it( 'a beállított nap előtt nem készül', ! $made );
$made = hpv_report_auto( '2026-12-03' );
it( 'december 3.: a novemberi piszkozat elkészül, a csapat levelet kap', array_filter( $made, fn( $r ) => (int) $r['client_id'] === $hu && '2026-11' === $r['period'] ) && array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => 0 === strpos( $m['subject'], 'Havi riportok:' ) ) );
it( 'másodszor nem', ! array_filter( hpv_report_auto( '2026-12-04' ), fn( $r ) => (int) $r['client_id'] === $hu ) );

echo "Takarítás\n";
foreach ( $made as $r ) {
	hpv_p_delete( 'report', (int) $r['id'] );
}
hpv_p_delete( 'client', $hu );
hpv_p_delete( 'project', $tpl );
delete_option( 'hpv_google_tokens' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $maria->ID );
it( 'kész', ! hpv_p_find( 'report', array( 'client_id' => $hu ) ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
