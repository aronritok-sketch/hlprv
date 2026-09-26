<?php
/**
 * Értékesítési tölcsér és forrásmérés integrációs tesztje.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen):
 *   wp eval-file tests/sales-integration.php
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_sales_board' ) ) {
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
function call( string $method, string $path, array $params = array() ) {
	$req = new WP_REST_Request( $method, '/hpv/v1' . preg_replace( '/\?.*$/', '', $path ) );
	if ( false !== strpos( $path, '?' ) ) {
		parse_str( (string) wp_parse_url( $path, PHP_URL_QUERY ), $q );
		$req->set_query_params( $q );
	}
	if ( $params ) {
		$req->set_body_params( $params );
	}
	return rest_do_request( $req );
}
function card( array $board, int $id ) {
	return array_values( array_filter( $board['cards'], fn( $c ) => $c['id'] === $id ) )[0] ?? null;
}

$suffix  = strtolower( wp_generate_password( 5, false, false ) );
$clients = array();
wp_set_current_user( 1 );
$settings = get_option( HPV_PORTAL_OPTION );
$sales    = wp_insert_user( array( 'user_login' => "sales_$suffix", 'user_pass' => wp_generate_password(), 'user_email' => "sales_$suffix@hpv.test", 'role' => 'hpv_staff', 'display_name' => 'Sam Sales' ) );
update_option( HPV_PORTAL_OPTION, array_merge( (array) $settings, array( 'lead_owner' => $sales, 'lead_sla_hours' => 2, 'sales_digest' => true ) ) );

echo "Csatorna a látogatásból\n";
$cases = array(
	'google_ads' => array( 'gclid' => 'abc', 'utm_source' => 'google' ),
	'meta_ads'   => array( 'utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'fbclid' => 'x' ),
	'gbp'        => array( 'utm_source' => 'google', 'utm_medium' => 'organic', 'utm_campaign' => 'gbp' ),
	'organic'    => array( 'ref' => 'https://www.google.com/' ),
	'social'     => array( 'fbclid' => 'x', 'ref' => 'https://l.facebook.com/' ),
	'social '    => array( 'utm_source' => 'instagram' ),
	'social  '   => array( 'utm_source' => 'linkedin', 'utm_medium' => 'post' ),
	'email'      => array( 'utm_source' => 'mailchimp', 'utm_medium' => 'email' ),
	'referral'   => array( 'ref' => 'https://capecoralchamber.com/members' ),
	'campaign'   => array( 'utm_source' => 'flyer' ),
	'paid_other' => array( 'msclkid' => 'm1' ),
	'direct'     => array( 'land' => '/', 'at' => 1 ),
);
$bad = array();
foreach ( $cases as $want => $touch ) {
	if ( hpv_sales_channel( $touch ) !== trim( $want ) ) {
		$bad[] = trim( $want ) . '→' . hpv_sales_channel( $touch );
	}
}
it( 'Google Ads, Meta, Cégprofil, organikus, közösségi, e-mail, hivatkozó, kampány, egyéb hirdetés, közvetlen' . ( $bad ? ' (' . implode( ', ', $bad ) . ')' : '' ), ! $bad );
it( 'forrásadat nélkül az űrlap dönt', 'unknown' === hpv_sales_lead_channel( array(), 'contact' ) && 'offline' === hpv_sales_lead_channel( array(), 'manual' ) && 'import' === hpv_sales_lead_channel( array(), 'bitrix' ) );
it( 'ismeretlen kulcsok és tömbök kiszűrve', array( 'first' => array( 'utm_source' => 'google' ) ) === hpv_sales_clean_attribution( '{"first":{"utm_source":"google","evil":"<x>","gclid":["a"]}}' ) );

echo "Érdeklődő a weboldalról, forrással\n";
$GLOBALS['hpv_it_mail'] = array();
$attr = array(
	'first' => array( 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'fall-seo', 'gclid' => 'Cj0x', 'land' => '/seo/', 'at' => time() - 3 * DAY_IN_SECONDS ),
	'last'  => array( 'ref' => 'https://www.google.com/', 'land' => '/contact/', 'at' => time() - 600 ),
);
$res = hpv_leads_ingest( array( 'source' => 'contact', 'form' => 'Project Brief', 'name' => 'Gina Ads', 'email' => "gina_$suffix@ads.test", 'message' => 'SEO please', 'attribution' => $attr ) );
$gina       = (int) $res['client_id'];
$clients[]  = $gina;
$g          = hpv_p_get( 'client', $gina );
it( 'a tölcsér elején: Új, időpont, felelős a beállításból', 'new' === $g['lead_stage'] && $g['lead_at'] && (int) $g['lead_owner'] === $sales );
it( 'csatorna az első látogatásból (Google Ads), kampány, űrlap', 'google_ads' === $g['lead_channel'] && 'fall-seo' === $g['lead_campaign'] && 'contact' === $g['lead_source'] && 'Cj0x' === json_decode( $g['lead_attribution'], true )['first']['gclid'] );
$note = hpv_p_find( 'activity', array( 'client_id' => $gina ), array( 'limit' => 1 ) )[0]['body'] ?? '';
it( 'a jegyzetben: honnan jött', false !== strpos( $note, 'Honnan: Google Ads, kampány: fall-seo' ) );

echo "Kézi érdeklődő\n";
$r   = call( 'POST', '/sales/leads', array( 'name' => "Referral Roofing $suffix", 'email' => "roof_$suffix@r.test", 'phone' => '239-555-0199', 'channel' => 'offline', 'value' => '6500', 'next_step' => 'Első hívás', 'next_step_date' => current_time( 'Y-m-d' ), 'note' => 'Bob ajánlotta' ) );
$m   = $r->get_data();
$man = (int) ( $m['id'] ?? 0 );
$clients[] = $man;
it( 'felvéve: Új, személyes ajánlás, érték, lépés, felelős én', 200 === $r->get_status() && 'new' === $m['stage'] && 'offline' === $m['channel'] && '$6,500.00' === $m['value_label'] && 'Első hívás' === $m['next_step'] && 1 === $m['owner'] && 'manual' === $m['source'] );
it( 'jegyzet és név nélkül hiba', 'Bob ajánlotta' === ( hpv_p_find( 'activity', array( 'client_id' => $man, 'type' => 'note' ), array( 'limit' => 1 ) )[0]['body'] ?? '' ) && 400 === call( 'POST', '/sales/leads', array( 'name' => '' ) )->get_status() );

echo "Szakaszok\n";
hpv_p_update( 'client', $gina, array( 'lead_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * HOUR_IN_SECONDS ) ) );
$c = call( 'POST', "/sales/leads/$gina", array( 'stage' => 'contacted' ) )->get_data();
it( 'Új → Felvettük: első válasz rögzítve (~5 óra)', 'contacted' === $c['stage'] && $c['response_hours'] >= 4.9 && $c['response_hours'] <= 5.1 );
it( 'az idővonalon', (bool) array_filter( hpv_p_find( 'activity', array( 'client_id' => $gina ) ), fn( $a ) => false !== strpos( $a['body'], 'Új → Felvettük a kapcsolatot' ) ) );
it( 'elveszett ok nélkül: hiba', 400 === call( 'POST', "/sales/leads/$gina", array( 'stage' => 'lost' ) )->get_status() );
it( 'ismeretlen szakasz / csatorna / nem munkatárs felelős: hiba', 400 === call( 'POST', "/sales/leads/$gina", array( 'stage' => 'x' ) )->get_status() && 400 === call( 'POST', "/sales/leads/$gina", array( 'channel' => 'tv' ) )->get_status() && 400 === call( 'POST', "/sales/leads/$gina", array( 'owner' => 999999 ) )->get_status() );
$c = call( 'POST', "/sales/leads/$gina", array( 'stage' => 'lost', 'lost_reason' => 'Túl drága' ) )->get_data();
it( 'elveszett okkal', 'lost' === $c['stage'] && 'Túl drága' === $c['lost_reason'] && $c['lost_at'] );
$GLOBALS['hpv_it_mail'] = array();
hpv_leads_ingest( array( 'source' => 'contact', 'name' => 'Gina Ads', 'email' => "gina_$suffix@ads.test", 'message' => 'Ready now', 'attribution' => array( 'first' => array( 'utm_source' => 'facebook', 'utm_medium' => 'cpc' ) ) ) );
$g = hpv_p_get( 'client', $gina );
it( 'újra jelentkezik: vissza az elejére, új időpont, új csatorna, ok törölve', 'new' === $g['lead_stage'] && ! $g['lost_reason'] && ! $g['first_contact_at'] && 'meta_ads' === $g['lead_channel'] && strtotime( $g['lead_at'] . ' UTC' ) > time() - 60 );

echo "Ajánlat és megnyerés\n";
$pricing = wp_json_encode( array( array( 'name' => 'Website', 'qty' => 1, 'unit_price' => '4000', 'recurring' => 'one_time' ), array( 'name' => 'SEO', 'qty' => 1, 'unit_price' => '500', 'recurring' => 'monthly' ) ) );
$prop    = hpv_p_insert( 'proposal', array( 'client_id' => $gina, 'title' => 'Web + SEO', 'number' => 'P-T-' . $suffix, 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ), 'pricing' => $pricing, 'currency' => 'USD', 'language' => 'en' ) );
do_action( 'hpv_proposal_sent', hpv_p_get( 'proposal', $prop ), 1 );
$g = hpv_p_get( 'client', $gina );
it( 'kiküldött ajánlat: Ajánlat kint, érték = első év (4000 + 12×500)', 'proposal' === $g['lead_stage'] && 10000.0 === (float) $g['lead_value'] && $g['first_contact_at'] );
hpv_p_update( 'client', $gina, array( 'status' => 'active' ) ); // pl. az ügyfél elfogadta az ajánlatot
$g = hpv_p_get( 'client', $gina );
it( 'aktív ügyfél lett: Megnyert', 'won' === $g['lead_stage'] && $g['won_at'] );
it( 'megnyertet nem lehet visszatenni', 400 === call( 'POST', "/sales/leads/$gina", array( 'stage' => 'new' ) )->get_status() );
$c = call( 'POST', "/sales/leads/$man", array( 'stage' => 'won' ) )->get_data();
it( 'a tábláról megnyerve: aktív ügyfél', 'won' === $c['stage'] && 'active' === hpv_p_get( 'client', $man )['status'] );
$act = hpv_p_insert( 'client', array( 'name' => "Active $suffix", 'status' => 'active' ) );
$clients[] = $act;
it( 'aktív ügyfél felvétele nem kerül a tölcsérbe', '' === hpv_p_get( 'client', $act )['lead_stage'] );
it( 'aktív ügyfelet nem lehet a tölcsérben mozgatni', 400 === call( 'POST', "/sales/leads/$act", array( 'stage' => 'contacted' ) )->get_status() );

echo "Tölcsér és mutatók\n";
$old = hpv_p_insert( 'client', array( 'name' => "Waiting $suffix", 'email' => "wait_$suffix@w.test", 'status' => 'lead', 'lead_source' => 'grader', 'lead_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ), 'next_step_date' => '2020-01-01', 'next_step' => 'Follow up' ) );
$clients[] = $old;
$b = call( 'GET', '/sales/board' )->get_data();
$w = card( $b, $old );
it( 'a táblán: várakozó kártya, késés jelölve, lejárt lépés', $w && 'new' === $w['stage'] && $w['sla_breach'] && $w['overdue'] && $w['waiting_hours'] >= 2.9 && 'Website Grader' === $w['source_label'] );
it( 'a megnyertek is látszanak (30 napig)', card( $b, $gina ) && 'won' === card( $b, $gina )['stage'] );
it( 'mutatók: vár, késésben, nyerési arány, tölcsér értéke', $b['kpi']['waiting'] >= 1 && $b['kpi']['sla_breach'] >= 1 && null !== $b['kpi']['win_rate'] && is_string( $b['kpi']['pipeline'] ) );
$f = call( 'GET', '/sales/board?channel=google_ads' )->get_data();
it( 'szűrés csatornára', ! card( $f, $old ) );
$mine = call( 'GET', '/sales/board?owner=me' )->get_data();
it( 'szűrés: enyém', ! card( $mine, $old ) );
it( 'meta: szakaszok, csatornák, okok', 6 === count( call( 'GET', '/sales/meta' )->get_data()['stages'] ) && ! isset( call( 'GET', '/sales/meta' )->get_data()['cards'] ) );

echo "Válaszidő-figyelmeztetés és napi összefoglaló\n";
$GLOBALS['hpv_it_mail'] = array();
$done = hpv_sales_sla_check();
$to_s = array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $x ) => in_array( "sales_$suffix@hpv.test", (array) $x['to'], true ) ) );
it( '2 óránál régebben váró új érdeklődő: levél a felelősnek', in_array( $old, $done, true ) && 1 === count( $to_s ) && false !== strpos( $to_s[0]['message'], "Waiting $suffix" ) );
it( 'csak egyszer', ! in_array( $old, hpv_sales_sla_check(), true ) );
$imp = hpv_p_insert( 'client', array( 'name' => "Imported $suffix", 'status' => 'lead', 'lead_source' => 'bitrix', 'lead_at' => '2024-01-01 12:00:00' ) );
$clients[] = $imp;
it( 'a Bitrix24-ből importált régi érdeklődőről nem', ! in_array( $imp, hpv_sales_sla_check(), true ) && 'import' === hpv_p_get( 'client', $imp )['lead_channel'] );
$GLOBALS['hpv_it_mail'] = array();
$dig = hpv_sales_digest();
it( 'reggeli összefoglaló: esedékes lépés a felelősnek', isset( $dig[ $sales ] ) && (bool) array_filter( $GLOBALS['hpv_it_mail'], fn( $x ) => in_array( "sales_$suffix@hpv.test", (array) $x['to'], true ) && false !== strpos( $x['message'], 'Follow up' ) ) );

echo "Források kimutatása\n";
$inv = hpv_p_insert( 'invoice', array( 'client_id' => $gina, 'status' => 'sent', 'number' => 'T-' . $suffix, 'issue_date' => current_time( 'Y-m-d' ), 'due_date' => current_time( 'Y-m-d' ) ) );
hpv_p_save_invoice_items( $inv, array( array( 'description' => 'Website', 'quantity' => '1', 'unit_price' => '4000' ) ) );
hpv_bill_mark_paid( $inv, array( 'provider' => 'manual', 'amount' => 400000, 'user_id' => 1 ) );
$s   = call( 'GET', '/sales/sources?group=channel' )->get_data();
$row = array_values( array_filter( $s['rows'], fn( $r ) => 'meta_ads' === $r['key'] ) )[0] ?? null;
it( 'csatornánként: érdeklődő, megnyert, bevétel', $row && $row['leads'] >= 1 && $row['won'] >= 1 && false !== strpos( $row['revenue_label'], '$4,000.00' ) && $s['money'] );
it( 'összesen sor és havi bontás', $s['total']['leads'] >= 3 && $s['months'] );
$sc  = call( 'GET', '/sales/sources?group=source' )->get_data();
it( 'űrlaponként', (bool) array_filter( $sc['rows'], fn( $r ) => 'Kapcsolati űrlap' === $r['label'] ) && (bool) array_filter( $sc['rows'], fn( $r ) => 'Website Grader' === $r['label'] ) );
it( 'régi időszak: üres', ! call( 'GET', '/sales/sources?from=2001-01-01&to=2001-12-31' )->get_data()['rows'] );
wp_set_current_user( $sales );
$ns = call( 'GET', '/sales/sources' )->get_data();
it( 'számlázási jog nélkül nincs bevétel', false === $ns['money'] && null === $ns['total']['revenue_label'] );
it( 'a munkatárs mozgathat', 200 === call( 'POST', "/sales/leads/$old", array( 'stage' => 'qualified' ) )->get_status() );
wp_set_current_user( 0 );
it( 'kijelentkezve semmi', 401 === call( 'GET', '/sales/board' )->get_status() && 401 === call( 'POST', "/sales/leads/$old", array( 'stage' => 'lost', 'lost_reason' => 'x' ) )->get_status() );
wp_set_current_user( 1 );

echo "Adatlap és frissítés\n";
$full = hpv_client_full( $old );
it( 'az adatlapon a panel adatai', isset( $full['sales'] ) && 'qualified' === $full['sales']['stage'] && array_key_exists( 'attribution', $full['sales'] ) );
it( 'az aktív (nem tölcséres) ügyfélnél nincs panel', ! isset( hpv_client_full( $act )['sales'] ) );
$mig = hpv_p_insert( 'client', array( 'name' => "Legacy $suffix", 'status' => 'lead', 'notes' => 'Forrás: Website Grader' ) );
$clients[] = $mig;
hpv_p_update( 'client', $mig, array( 'lead_stage' => '', 'lead_source' => '', 'sla_reminded_at' => null ) );
hpv_sales_migrate();
$lg = hpv_p_get( 'client', $mig );
it( 'frissítéskor a régi érdeklődők a tölcsérbe (figyelmeztetés nélkül)', 'new' === $lg['lead_stage'] && 'grader' === $lg['lead_source'] && $lg['sla_reminded_at'] );
$boot = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/pm/bootstrap' ) )->get_data();
it( 'a menü jelvénye számolja a válaszra várókat', ( $boot['salesAttention'] ?? 0 ) >= 1 );

echo "Takarítás\n";
update_option( HPV_PORTAL_OPTION, $settings );
foreach ( array_unique( $clients ) as $cid ) {
	hpv_p_delete( 'client', $cid );
}
hpv_p_delete( 'proposal', $prop );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sales );
it( 'kész', ! hpv_p_get( 'client', $gina ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
