<?php
/**
 * Bitrix24 import integrációs tesztje valódi WordPressen, álszerverrel (a Bitrix24 REST API válaszai).
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen):
 *   wp eval-file tests/bitrix-integration.php
 * Kifelé semmi nem megy (pre_http_request), levelet sem küld.
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_bx_step' ) ) {
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

$suffix   = wp_generate_password( 5, false, false );
$staff_id = wp_insert_user( array( 'user_login' => 'bxstaff_' . $suffix, 'user_email' => "bxstaff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff', 'display_name' => 'Peter Project' ) );

/* ─── Álszerver ────────────────────────────────────────────── */

const BX_BASE = 'https://hpv-test.bitrix24.hu/rest/1/secrettoken123abc/';
$GLOBALS['bx'] = array(
	'calls'      => array(),
	'limit_once' => array( 'crm.deal.list' => true ),
	'data'       => array(
		'profile'         => array( 'NAME' => 'Aron', 'LAST_NAME' => 'Ritok' ),
		'crm.company.list' => array(
			array( 'ID' => '1', 'TITLE' => "Sun Pools LLC $suffix", 'PHONE' => array( array( 'VALUE' => '+1 239 555 0101', 'VALUE_TYPE' => 'WORK' ) ), 'EMAIL' => array( array( 'VALUE' => "office_$suffix@sunpools.test" ) ), 'WEB' => array( array( 'VALUE' => 'sunpools.test' ) ), 'ADDRESS_CITY' => 'Cape Coral', 'ADDRESS_PROVINCE' => 'FL', 'ADDRESS_POSTAL_CODE' => '33904', 'ADDRESS' => '1 Main St' ),
			array( 'ID' => '2', 'TITLE' => "Kovács Kert Kft $suffix", 'PHONE' => array( array( 'VALUE' => '+36 30 123 4567' ) ), 'EMAIL' => array(), 'ADDRESS_COUNTRY' => 'Magyarország', 'ADDRESS_CITY' => 'Budapest', 'ADDRESS_POSTAL_CODE' => '1055', 'ADDRESS' => 'Kossuth tér 1.', 'COMMENTS' => '[b]Fontos[/b] ügyfél' ),
			array( 'ID' => '3', 'TITLE' => "Existing Co $suffix", 'EMAIL' => array( array( 'VALUE' => "hello_$suffix@existing.test" ) ) ),
			array( 'ID' => '4', 'TITLE' => '' ),
		),
		'crm.contact.list' => array(
			array( 'ID' => '10', 'NAME' => 'Mia', 'LAST_NAME' => 'Sun', 'COMPANY_ID' => '1', 'EMAIL' => array( array( 'VALUE' => "mia_$suffix@sunpools.test" ) ) ),
			array( 'ID' => '11', 'NAME' => 'John', 'LAST_NAME' => "Solo$suffix", 'COMPANY_ID' => null, 'EMAIL' => array( array( 'VALUE' => "john_$suffix@solo.test" ) ), 'PHONE' => array( array( 'VALUE' => '06 20 111 2222' ) ) ),
			array( 'ID' => '12', 'NAME' => '', 'LAST_NAME' => '' ),
		),
		'crm.lead.list'    => array(
			array( 'ID' => '20', 'TITLE' => 'Website inquiry', 'COMPANY_TITLE' => "Palm Dental $suffix", 'NAME' => 'Dr.', 'LAST_NAME' => 'Palm', 'STATUS_ID' => 'NEW', 'OPPORTUNITY' => '4500.00', 'CURRENCY_ID' => 'USD' ),
			array( 'ID' => '21', 'TITLE' => 'Converted', 'COMPANY_TITLE' => 'Old Lead', 'STATUS_ID' => 'CONVERTED' ),
		),
		'crm.deal.list'    => array(
			array( 'ID' => '30', 'TITLE' => 'Website redesign', 'STAGE_ID' => 'WON', 'STAGE_SEMANTIC_ID' => 'S', 'OPPORTUNITY' => '5000.00', 'CURRENCY_ID' => 'USD', 'COMPANY_ID' => '1', 'CONTACT_ID' => '10', 'CLOSEDATE' => '2026-08-30T03:00:00+03:00' ),
			array( 'ID' => '31', 'TITLE' => 'Consulting', 'STAGE_ID' => 'NEW', 'STAGE_SEMANTIC_ID' => 'P', 'COMPANY_ID' => '0', 'CONTACT_ID' => '11' ),
			array( 'ID' => '32', 'TITLE' => 'Orphan deal', 'COMPANY_ID' => '0', 'CONTACT_ID' => '0' ),
		),
		'sonet_group.get'  => array(
			array( 'ID' => '40', 'NAME' => "Sun Pools LLC $suffix – Website", 'DESCRIPTION' => 'New site', 'CLOSED' => 'N', 'PROJECT_DATE_START' => '2026-09-01T00:00:00+02:00', 'PROJECT_DATE_FINISH' => '2026-10-20T00:00:00+02:00' ),
			array( 'ID' => '41', 'NAME' => "Internal marketing $suffix", 'CLOSED' => 'Y' ),
		),
		'user.get'         => array(
			array( 'ID' => '7', 'EMAIL' => "bxstaff_$suffix@example.test" ),
			array( 'ID' => '8', 'EMAIL' => 'someone@else.test' ),
		),
		'tasks.task.list'  => array(
			array( 'id' => '51', 'title' => 'Hero image', 'groupId' => '40', 'parentId' => '50', 'status' => '5', 'responsibleId' => '8' ),
			array( 'id' => '50', 'title' => 'Homepage', 'groupId' => '40', 'parentId' => '0', 'status' => '3', 'priority' => '2', 'responsibleId' => '7', 'deadline' => '2026-10-05T19:00:00+02:00' ),
			array( 'id' => '52', 'title' => 'Personal todo', 'groupId' => '0', 'status' => '2', 'description' => '[b]Call[/b] the client, see [url=https://x.test]notes[/url]' ),
		),
	),
);

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, BX_BASE ) ) {
			return new WP_Error( 'offline', 'unexpected ' . $url );
		}
		$method = substr( $url, strlen( BX_BASE ), -5 );
		$params = json_decode( (string) $args['body'], true ) ?: array();
		$bx     = &$GLOBALS['bx'];
		$bx['calls'][] = $method;
		$reply = function ( $body ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		};
		if ( ! empty( $bx['limit_once'][ $method ] ) ) {
			unset( $bx['limit_once'][ $method ] );
			return $reply( array( 'error' => 'QUERY_LIMIT_EXCEEDED', 'error_description' => 'Too many requests' ) );
		}
		if ( ! isset( $bx['data'][ $method ] ) ) {
			return $reply( array( 'error' => 'ERROR_METHOD_NOT_FOUND', 'error_description' => 'Method not found' ) );
		}
		$data = $bx['data'][ $method ];
		if ( 'profile' === $method ) {
			return $reply( array( 'result' => $data ) );
		}
		// Lapozás: 2 elem oldalanként (a valóságban 50).
		$start = (int) ( $params['start'] ?? 0 );
		$page  = array_slice( $data, $start, 2 );
		$body  = array( 'result' => 'tasks.task.list' === $method ? array( 'tasks' => $page ) : $page, 'total' => count( $data ) );
		if ( $start + 2 < count( $data ) ) {
			$body['next'] = $start + 2;
		}
		return $reply( $body );
	},
	10,
	3
);

function bx_rest( string $method, string $path, array $body = array() ) {
	$req = new WP_REST_Request( $method, '/hpv/v1/import/bitrix' . $path );
	$req->set_body_params( $body );
	return rest_do_request( $req );
}

/**
 * A CRM app viselkedése: lépések egymás után, túlterhelésnél újra.
 */
function bx_run_all( bool $dry ): array {
	$res = bx_rest( 'POST', '/start', array( 'dry' => $dry ? '1' : '0', 'steps' => array_keys( HPV_BX_STEPS ), 'force' => 1 ) );
	$retries = 0;
	for ( $i = 0; $i < 60; $i++ ) {
		$res = bx_rest( 'POST', '/step' );
		if ( 429 === $res->get_status() ) {
			$retries++;
			continue;
		}
		if ( 200 !== $res->get_status() ) {
			return array( 'error' => $res->get_data()['message'] ?? 'hiba' );
		}
		if ( $res->get_data()['run']['done'] ) {
			return array_merge( $res->get_data(), array( 'retries' => $retries ) );
		}
	}
	return array( 'error' => 'nem ért véget' );
}

function clients_like( string $suffix ): array {
	return array_values( array_filter( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ), fn( $c ) => false !== strpos( $c['name'] . $c['email'], $suffix ) ) );
}

delete_option( 'hpv_bitrix_map' );
delete_option( 'hpv_bitrix_run' );
delete_option( 'hpv_bitrix_webhook' );
delete_option( 'hpv_bitrix_misc_project' );

echo "Jogosultság, kapcsolat\n";
wp_set_current_user( $staff_id );
it( 'munkatárs nem importálhat', 403 === bx_rest( 'GET', '' )->get_status() && 403 === bx_rest( 'POST', '/connect', array( 'webhook' => BX_BASE ) )->get_status() );
wp_set_current_user( 1 );
it( 'nem webhook cím: hiba', 400 === bx_rest( 'POST', '/connect', array( 'webhook' => 'https://example.com/foo' ) )->get_status() );
it( 'webhook nélkül nem indul', 400 === bx_rest( 'POST', '/start', array( 'steps' => array( 'companies' ) ) )->get_status() );
$res = bx_rest( 'POST', '/connect', array( 'webhook' => rtrim( BX_BASE, '/' ) . '/profile.json' ) );
it( 'kapcsolódás (a bemásolt metódusnév is jó)', 200 === $res->get_status() && $res->get_data()['connected'] && 'Aron Ritok' === $res->get_data()['profile'] );
it( 'a webhook titok: titkosítva tárolva, maszkolva visszaadva', false === strpos( (string) get_option( 'hpv_bitrix_webhook' ), 'secrettoken123abc' ) && false === strpos( $res->get_data()['webhook'], 'secrettoken123abc' ) && BX_BASE === hpv_bx_webhook() );

$existing = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => "Existing Co $suffix", 'status' => 'active', 'phone' => '+1 555 0000' ) ) );
$before   = count( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ) );

echo "Próbafuttatás\n";
$dry = bx_run_all( true );
$c   = $dry['run']['counts'] ?? array();
it( 'végigfut, a túlterhelést kivárja', empty( $dry['error'] ) && 1 === $dry['retries'] );
it( 'cégek: 2 új, 1 meglévő, 1 kihagyva', array( 'created' => 2, 'matched' => 1, 'updated' => 1, 'skipped' => 1 ) === $c['companies'] );
it( 'kapcsolatok: 1 új magánszemély, 1 céghez, 1 kihagyva', 1 === $c['contacts']['created'] && 1 === $c['contacts']['matched'] && 1 === $c['contacts']['skipped'] );
it( 'érdeklődők: 1 új, az átalakított kimarad', 1 === $c['leads']['created'] && 1 === $c['leads']['skipped'] );
it( 'üzletek: 2 jegyzet, 1 ügyfél nélküli kimarad (figyelmeztetés)', 2 === $c['deals']['created'] && 1 === $c['deals']['skipped'] && $dry['run']['warnings'] && false !== strpos( $dry['run']['warnings'][0], 'Orphan deal' ) );
it( 'projektek és feladatok', 2 === $c['projects']['created'] && 3 === $c['tasks']['created'] );
it( 'semmi nem íródott', count( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ) ) === $before && ! get_option( 'hpv_bitrix_map' ) && '' === (string) hpv_p_get( 'client', $existing )['email'] );

echo "Import\n";
$GLOBALS['bx']['limit_once'] = array();
$run = bx_run_all( false );
it( 'végigfut', empty( $run['error'] ) && $run['run']['done'] );
$sun = hpv_p_get( 'client', hpv_bx_map()['company']['1'] );
$hu  = hpv_p_get( 'client', hpv_bx_map()['company']['2'] );
it( 'USA cég: adatok, cím, kapcsolattartó', "office_$suffix@sunpools.test" === $sun['email'] && 'US' === $sun['country'] && 'Cape Coral' === $sun['city'] && 'FL' === $sun['state'] && 'Mia Sun' === $sun['contact_name'] && 'http://sunpools.test' === $sun['website'] );
it( 'magyar cég: ország a címből, megjegyzés BBCode nélkül', 'HU' === $hu['country'] && '1055' === $hu['zip'] && 'Fontos ügyfél' === $hu['notes'] );
$ex = hpv_p_get( 'client', $existing );
it( 'meglévő ügyfél: nem lett dupla, az üres e-mail kitöltve, a telefon megmaradt', hpv_bx_map()['company']['3'] === $existing && "hello_$suffix@existing.test" === $ex['email'] && '+1 555 0000' === $ex['phone'] && 1 === count( array_filter( clients_like( $suffix ), fn( $x ) => "Existing Co $suffix" === $x['name'] ) ) );
$john = hpv_p_get( 'client', hpv_bx_map()['contact']['11'] );
it( 'cég nélküli kapcsolat: magánszemély ügyfél, magyar telefon → HU', "John Solo$suffix" === $john['name'] && 'HU' === $john['country'] );
$palm = hpv_p_get( 'client', hpv_bx_map()['lead']['20'] );
it( 'érdeklődő: „lead” státusz, összeg a megjegyzésben', 'lead' === $palm['status'] && false !== strpos( $palm['notes'], '4500.00 USD' ) && 'Dr. Palm' === $palm['contact_name'] );
$note = hpv_p_find( 'activity', array( 'client_id' => (int) $sun['id'], 'type' => 'note' ) );
it( 'üzlet: belső jegyzet az ügyfélnél', 1 === count( $note ) && ! (int) $note[0]['visible'] && false !== strpos( $note[0]['body'], 'megnyert' ) && false !== strpos( $note[0]['body'], '5000.00 USD' ) && false !== strpos( $note[0]['body'], '2026-08-30' ) );
$p40 = hpv_p_get( 'project', hpv_bx_map()['group']['40'] );
$p41 = hpv_p_get( 'project', hpv_bx_map()['group']['41'] );
it( 'munkacsoport → projekt az ügyfélhez kötve, rejtve', (int) $sun['id'] === (int) $p40['client_id'] && ! (int) $p40['visible'] && '2026-09-01' === $p40['start_date'] && '2026-10-20' === $p40['due_date'] && 'in_progress' === $p40['status'] );
it( 'lezárt csoport → kész, belső projekt', 'completed' === $p41['status'] && ! (int) $p41['client_id'] );
$home = hpv_p_get( 'task', hpv_bx_map()['task']['50'] );
$hero = hpv_p_get( 'task', hpv_bx_map()['task']['51'] );
$solo = hpv_p_get( 'task', hpv_bx_map()['task']['52'] );
it( 'feladat: státusz, prioritás, határidő, felelős e-mail alapján', 'in_progress' === $home['status'] && 'high' === $home['priority'] && '2026-10-05' === $home['due_date'] && $staff_id === (int) $home['assignee_id'] && (int) $p40['id'] === (int) $home['project_id'] );
it( 'alfeladat a szülőjéhez kötve (a szülő később jött)', (int) $home['id'] === (int) $hero['parent_id'] && 'done' === $hero['status'] && ! (int) $hero['assignee_id'] );
it( 'csoport nélküli feladat a gyűjtőprojektben, BBCode nélkül', (int) get_option( 'hpv_bitrix_misc_project' ) === (int) $solo['project_id'] && 'Call the client, see notes (https://x.test)' === $solo['description'] );
it( 'levél nem ment ki', ! $GLOBALS['hpv_it_mail'] );

echo "Újrafuttatás\n";
hpv_p_update( 'client', (int) $sun['id'], array( 'phone' => '+1 239 000 0000', 'name' => 'Sun Pools (átnevezve)' ) );
$count = count( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ) );
$again = bx_run_all( false );
$ac    = $again['run']['counts'];
it( 'semmi nem jön létre újra', 0 === array_sum( array_column( $ac, 'created' ) ) && count( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ) ) === $count && 1 === count( hpv_p_find( 'activity', array( 'client_id' => (int) $sun['id'], 'type' => 'note' ) ) ) );
it( 'a helyi módosítás megmarad', '+1 239 000 0000' === hpv_p_get( 'client', (int) $sun['id'] )['phone'] && 'Sun Pools (átnevezve)' === hpv_p_get( 'client', (int) $sun['id'] )['name'] );
hpv_p_delete( 'project', (int) $p41['id'] );
$again = bx_run_all( false );
it( 'helyben törölt rekord újra importálható', 1 === $again['run']['counts']['projects']['created'] );

echo "Állapot\n";
$st = bx_rest( 'GET', '' )->get_data();
it( 'összesítő', $st['connected'] && 3 === $st['imported']['company'] && 6 === count( $st['steps'] ) && $st['run']['done'] );
it( 'bontás', ! bx_rest( 'POST', '/disconnect' )->get_data()['connected'] && ! get_option( 'hpv_bitrix_webhook' ) );

echo "Takarítás\n";
foreach ( hpv_bx_map()['group'] ?? array() as $pid ) {
	hpv_p_delete( 'project', (int) $pid );
}
hpv_p_delete( 'project', (int) get_option( 'hpv_bitrix_misc_project' ) );
foreach ( clients_like( $suffix ) as $cl ) {
	hpv_p_delete( 'client', (int) $cl['id'] );
}
hpv_p_delete( 'client', (int) $sun['id'] );
foreach ( array( 'hpv_bitrix_map', 'hpv_bitrix_run', 'hpv_bitrix_webhook', 'hpv_bitrix_misc_project', 'hpv_bitrix_profile' ) as $o ) {
	delete_option( $o );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $staff_id );
it( 'kész', ! clients_like( $suffix ) );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
