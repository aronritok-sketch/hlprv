<?php
/**
 * Videóhívás (Daily.co + AI-összefoglaló) integrációs tesztje valódi WordPressen.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen, videó kulcsok NINCSENEK beállítva):
 *   wp eval-file tests/video-integration.php
 *
 * A Daily és az AI API-t a teszt helyettesíti (pre_http_request), kifelé semmi nem megy, levelet sem küld.
 * Minden adatot maga hoz létre, a végén törli.
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_video_start_call' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}
if ( defined( 'HPV_DAILY_API_KEY' ) || defined( 'HPV_AI_API_KEY' ) ) {
	echo "Ezt a tesztet kulcsok nélküli teszt WordPressen futtasd.\n";
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

/* ─── Álszerverek: Daily és AI ─────────────────────────────── */

$GLOBALS['mock'] = array(
	'requests'    => array(),
	'presence'    => 1,
	'transcripts' => array(),   // roomId => [ {transcriptId, status} ]
	'vtt'         => '',
	'ai_status'   => 200,
	'ai_text'     => '',
	'rooms'       => 0,
);

function mock_response( int $code, $body ): array {
	return array(
		'headers'  => array(),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

function mock_requests( string $needle, string $method = '' ): array {
	return array_values( array_filter( $GLOBALS['mock']['requests'], fn( $r ) => false !== strpos( $r['url'], $needle ) && ( ! $method || $method === $r['method'] ) ) );
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$m      = &$GLOBALS['mock'];
		$method = strtoupper( $args['method'] ?? 'GET' );
		$body   = isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		$m['requests'][] = array(
			'url'     => $url,
			'method'  => $method,
			'body'    => $body,
			'headers' => $args['headers'] ?? array(),
		);
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		if ( 0 === strpos( $url, HPV_VIDEO_DAILY_API ) ) {
			if ( 'POST' === $method && '/v1/rooms' === $path ) {
				$m['rooms']++;
				return mock_response( 200, array( 'id' => 'room-id-' . $m['rooms'], 'name' => $body['name'], 'url' => 'https://hpv.daily.co/' . $body['name'], 'privacy' => $body['privacy'] ) );
			}
			if ( 'POST' === $method && '/v1/meeting-tokens' === $path ) {
				return mock_response( 200, array( 'token' => 'tok-' . count( $m['requests'] ) ) );
			}
			if ( preg_match( '#^/v1/rooms/[^/]+/presence$#', $path ) ) {
				return mock_response( 200, array( 'total_count' => $m['presence'], 'data' => array() ) );
			}
			if ( preg_match( '#^/v1/rooms/[^/]+$#', $path ) ) {
				return mock_response( 200, 'DELETE' === $method ? array( 'deleted' => true ) : array( 'ok' => true ) );
			}
			if ( '/v1/transcript' === $path ) {
				return mock_response( 200, array( 'data' => $m['transcripts'][ $query['roomId'] ?? '' ] ?? array() ) );
			}
			if ( preg_match( '#^/v1/transcript/([^/]+)/access-link$#', $path, $x ) ) {
				return mock_response( 200, array( 'transcriptId' => $x[1], 'link' => 'https://files.example.test/' . $x[1] . '.vtt' ) );
			}
			if ( '/v1/recordings' === $path ) {
				return mock_response( 200, array( 'data' => array( array( 'id' => 'rec-1', 'room_name' => $query['room_name'] ?? '', 'duration' => 1800, 'start_ts' => 1790000000 ) ) ) );
			}
			if ( '/v1/recordings/rec-1/access-link' === $path ) {
				return mock_response( 200, array( 'download_link' => 'https://files.example.test/rec-1.mp4', 'expires' => time() + 3600 ) );
			}
			return mock_response( 404, array( 'error' => 'not-found', 'info' => 'mock: ' . $method . ' ' . $path ) );
		}
		if ( 0 === strpos( $url, 'https://files.example.test/' ) ) {
			return mock_response( 200, $m['vtt'] );
		}
		if ( HPV_AI_API === $url ) {
			return 200 === $m['ai_status']
				? mock_response( 200, array( 'content' => array( array( 'type' => 'text', 'text' => $m['ai_text'] ) ) ) )
				: mock_response( $m['ai_status'], array( 'error' => array( 'message' => 'Overloaded' ) ) );
		}

		return mock_response( 500, 'unexpected request: ' . $url );
	},
	10,
	3
);

$suffix  = wp_generate_password( 6, false, false );
$cleanup = array( 'clients' => array(), 'users' => array() );

$staff_id = wp_insert_user( array( 'user_login' => 'vstaff_' . $suffix, 'user_email' => "vstaff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff', 'display_name' => 'Anna Staff' ) );
$cleanup['users'][] = $staff_id;
wp_set_current_user( $staff_id );

$client_a = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Acme Pools ' . $suffix, 'status' => 'active' ) ) );
$client_b = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Beta Roofing ' . $suffix, 'status' => 'active' ) ) );
$cleanup['clients'] = array( $client_a, $client_b );
$maria = hpv_p_invite_user( $client_a, 'Maria Lopez', "vmaria_$suffix@acme.test" );
$bob   = hpv_p_invite_user( $client_b, 'Bob Beta', "vbob_$suffix@beta.test" );
$cleanup['users'][] = $maria->ID;
$cleanup['users'][] = $bob->ID;
$project   = hpv_p_insert( 'project', hpv_p_sanitize( 'project', array( 'client_id' => $client_a, 'name' => 'Website', 'status' => 'active', 'visible' => 1 ) ) );
$project_b = hpv_p_insert( 'project', hpv_p_sanitize( 'project', array( 'client_id' => $client_b, 'name' => 'Roof site', 'status' => 'active' ) ) );
$GLOBALS['hpv_it_mail'] = array();

echo "Kulcs nélkül\n";
$off = hpv_video_start_call( array( 'client_id' => $client_a ), $staff_id );
it( 'Daily kulcs nélkül a hívás nem indul', is_wp_error( $off ) && 'video_off' === $off->get_error_code() );
it( 'kulcs nélkül nincs kimenő kérés', ! $GLOBALS['mock']['requests'] );

define( 'HPV_DAILY_API_KEY', 'test-daily-key' );

echo "Hívás indítása\n";
it( 'idegen ügyfél projektje nem választható', is_wp_error( hpv_video_start_call( array( 'client_id' => $client_a, 'project_id' => $project_b ), $staff_id ) ) );
$call = hpv_video_start_call( array( 'client_id' => $client_a, 'project_id' => $project, 'title' => 'Homepage review', 'record' => 1, 'notify' => 1 ), $staff_id );
it( 'hívás létrejött, élő', is_array( $call ) && 'live' === $call['status'] );
$room_req = mock_requests( '/v1/rooms', 'POST' )[0] ?? null;
it( 'privát szoba, lejárattal és kiléptetéssel', $room_req && 'private' === $room_req['body']['privacy'] && $room_req['body']['properties']['exp'] > time() && true === $room_req['body']['properties']['eject_at_room_exp'] );
it( 'a szoba menti a leiratot és felvehet', true === $room_req['body']['properties']['enable_transcription_storage'] && 'cloud' === $room_req['body']['properties']['enable_recording'] );
it( 'Daily hívás a kulccsal', 'Bearer test-daily-key' === ( $room_req['headers']['Authorization'] ?? '' ) );
it( 'szoba adatai elmentve', 'room-id-1' === $call['room_id'] && 0 === strpos( $call['room_url'], 'https://hpv.daily.co/hpv-' ) );
$channel = hpv_chat_client_channel( $client_a, false );
$msgs    = hpv_p_find( 'chat_message', array( 'channel_id' => (int) $channel['id'] ) );
it( 'üzenet az ügyfél csatornájába a csatlakozási linkkel', 1 === count( $msgs ) && false !== strpos( $msgs[0]['body'], 'view=call' ) );
$invite = mails_to( "vmaria_$suffix@acme.test" );
it( 'egy meghívó e-mail az ügyfélnek (nem kettő)', 1 === count( $invite ) && false !== strpos( $invite[0]['subject'], 'Homepage review' ) );
it( 'napló: hívás indult (belső)', (bool) array_filter( hpv_p_find( 'activity', array( 'client_id' => $client_a ) ), fn( $a ) => 'call' === $a['type'] && ! $a['visible'] ) );

echo "Belépés, hozzájárulás\n";
$join = hpv_video_join( (int) $call['id'], get_userdata( $staff_id ) );
$tok  = end( $GLOBALS['mock']['requests'] )['body']['properties'];
it( 'munkatárs owner-ként lép be', is_array( $join ) && true === $join['owner'] && true === $tok['is_owner'] && $join['token'] );
it( 'munkatárs belépésekor indul a felvétel', true === ( $tok['start_cloud_recording'] ?? false ) );
$join_c = hpv_video_join( (int) $call['id'], $maria );
$tok_c  = end( $GLOBALS['mock']['requests'] )['body']['properties'];
it( 'ügyfél nem owner, és nem indít felvételt', is_array( $join_c ) && false === $join_c['owner'] && false === $tok_c['is_owner'] && ! isset( $tok_c['start_cloud_recording'] ) );
it( 'ügyfél neve mellett a cége (a leiratban így látszik)', false !== strpos( $tok_c['user_name'], 'Maria Lopez (Acme Pools' ) );
$bob_join = hpv_video_join( (int) $call['id'], $bob );
it( 'másik ügyfél nem léphet be', is_wp_error( $bob_join ) && 404 === $bob_join->get_error_data()['status'] );
$consents = json_decode( hpv_p_get( 'call', (int) $call['id'] )['consents'], true );
it( 'hozzájárulások naplózva (név, szerep, idő)', 2 === count( $consents ) && 'staff' === $consents[0]['role'] && 'client' === $consents[1]['role'] && $consents[1]['at'] );

echo "REST jogosultság\n";
wp_set_current_user( $maria->ID );
$r = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/video/calls' ) );
it( 'ügyfél nem éri el a hívások API-t', 403 === $r->get_status() || 401 === $r->get_status() );
wp_set_current_user( $staff_id );
$r = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/video/calls' ) );
it( 'munkatárs listázhat', 200 === $r->get_status() && true === $r->get_data()['enabled'] && false === $r->get_data()['ai'] );
$req = new WP_REST_Request( 'POST', '/hpv/v1/video/calls/' . $call['id'] );
$req->set_body_params( array( 'project_id' => $project_b ) );
it( 'idegen projekthez nem köthető', 400 === rest_do_request( $req )->get_status() );
$req = new WP_REST_Request( 'DELETE', '/hpv/v1/video/calls/' . $call['id'] );
it( 'munkatárs nem törölhet hívást', 403 === rest_do_request( $req )->get_status() );

echo "Hívás vége\n";
hpv_video_process( (int) $call['id'] );
it( 'friss belépés után a hívás élő marad', 'live' === hpv_p_get( 'call', (int) $call['id'] )['status'] );
hpv_p_update( 'call', (int) $call['id'], array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ), 'last_join_at' => gmdate( 'Y-m-d H:i:s', time() - 900 ) ) );
$GLOBALS['mock']['presence'] = 1;
hpv_video_process( (int) $call['id'] );
it( 'amíg van bent valaki, élő marad', 'live' === hpv_p_get( 'call', (int) $call['id'] )['status'] );
$GLOBALS['mock']['presence']            = 0;
$GLOBALS['mock']['transcripts']['room-id-1'] = array( array( 'transcriptId' => 'tr-1', 'roomId' => 'room-id-1', 'status' => 't_in_progress' ) );
hpv_video_process( (int) $call['id'] );
$c = hpv_p_get( 'call', (int) $call['id'] );
it( 'üres szoba → lezárva, leiratra vár', 'processing' === $c['status'] && $c['ended_at'] && empty( $c['transcript'] ) );

echo "Leirat és összefoglaló (AI kulcs nélkül)\n";
$GLOBALS['mock']['transcripts']['room-id-1'] = array(
	array( 'transcriptId' => 'tr-1', 'roomId' => 'room-id-1', 'status' => 't_finished', 'isVttAvailable' => true ),
	array( 'transcriptId' => 'tr-x', 'roomId' => 'room-id-99', 'status' => 't_finished', 'isVttAvailable' => true ),
);
$GLOBALS['mock']['vtt'] = "WEBVTT\n\nNOTE generated by test\nthis line is a note\n\n1\n00:00:01.000 --> 00:00:03.000\n<v Anna Staff>Hi Maria, thanks for joining.</v>\n\n2\n00:00:03.500 --> 00:00:05.000\n<v Anna Staff>Let's review the homepage.</v>\n\n3\n00:01:02.000 --> 00:01:06.000\n<v Maria Lopez (Acme Pools)>Looks great &amp; fast. Please add the Naples page by October 15.</v>\n\n4\n01:00:00.000 --> 01:00:02.000\n<v Anna Staff>Will do.</v>\n";
hpv_video_process( (int) $call['id'] );
$c = hpv_p_get( 'call', (int) $call['id'] );
it( 'leirat elmentve, beszélőnként összevonva', "[00:01] Anna Staff: Hi Maria, thanks for joining. Let's review the homepage.\n[01:02] Maria Lopez (Acme Pools): Looks great & fast. Please add the Naples page by October 15.\n[60:00] Anna Staff: Will do." === $c['transcript'] );
it( 'más szoba leirata nem kerül bele', ! mock_requests( 'tr-x' ) );
it( 'AI kulcs nélkül: kész, összefoglaló nélkül, magyarázattal', 'done' === $c['status'] && empty( $c['summary'] ) && false !== strpos( $c['error'], 'HPV_AI_API_KEY' ) );
it( 'AI kulcs nélkül nincs AI kérés', ! mock_requests( 'anthropic.com' ) );
it( 'értesítés a hívást indító munkatársnak', 1 === count( mails_to( "vstaff_$suffix@example.test" ) ) );

define( 'HPV_AI_API_KEY', 'test-ai-key' );

echo "AI-összefoglaló\n";
$GLOBALS['mock']['ai_text'] = "Here is the summary:\n```json\n" . wp_json_encode(
	array(
		'summary'        => 'Maria approved the homepage. The Naples page is next.',
		'decisions'      => array( 'Homepage approved' ),
		'action_items'   => array(
			array( 'title' => 'Build the Naples service page', 'owner' => 'agency', 'due' => '2026-10-15' ),
			array( 'title' => 'Send Naples photos', 'owner' => 'client', 'due' => 'next week' ),
			array( 'title' => '' ),
		),
		'internal_notes' => array( 'Upsell: Google Ads for Naples' ),
	)
) . "\n```";
$req = new WP_REST_Request( 'POST', '/hpv/v1/video/calls/' . $call['id'] . '/process' );
$res = rest_do_request( $req );
$c   = hpv_p_get( 'call', (int) $call['id'] );
$ai  = json_decode( $c['ai_data'], true );
$ai_req = mock_requests( 'anthropic.com' )[0] ?? null;
it( 'kézi újrafeldolgozás elkészíti az összefoglalót', 200 === $res->get_status() && 'done' === $c['status'] && 'Maria approved the homepage. The Naples page is next.' === $c['summary'] && '' === $c['error'] );
it( 'az AI megkapja a leiratot, a kulccsal, a beállított modellel', $ai_req && 'test-ai-key' === $ai_req['headers']['x-api-key'] && false !== strpos( $ai_req['body']['messages'][0]['content'], 'Please add the Naples page' ) && 'claude-sonnet-5' === $ai_req['body']['model'] );
it( 'teendők: üres kimarad, érvénytelen dátum törölve', 2 === count( $ai['action_items'] ) && '2026-10-15' === $ai['action_items'][0]['due'] && '' === $ai['action_items'][1]['due'] && 'client' === $ai['action_items'][1]['owner'] );
it( 'belső megjegyzés és döntés megvan', array( 'Upsell: Google Ads for Naples' ) === $ai['internal_notes'] && array( 'Homepage approved' ) === $ai['decisions'] );

echo "Teendőből feladat\n";
$req = new WP_REST_Request( 'POST', '/hpv/v1/video/calls/' . $call['id'] . '/tasks' );
$req->set_body_params( array( 'index' => 0 ) );
$res  = rest_do_request( $req );
$task = hpv_p_get( 'task', (int) ( $res->get_data()['task_id'] ?? 0 ) );
it( 'feladat a hívás projektjében, határidővel, belső', $task && (int) $task['project_id'] === $project && '2026-10-15' === $task['due_date'] && 'todo' === $task['status'] && ! $task['visible'] );
$req2 = new WP_REST_Request( 'POST', '/hpv/v1/video/calls/' . $call['id'] . '/tasks' );
$req2->set_body_params( array( 'index' => 1 ) );
$task2 = hpv_p_get( 'task', (int) ( rest_do_request( $req2 )->get_data()['task_id'] ?? 0 ) );
it( 'ügyfél teendője „Ügyfélre vár”, az ügyfél látja', $task2 && 'client' === $task2['status'] && $task2['visible'] );
it( 'ugyanabból a teendőből nem lesz két feladat', 409 === rest_do_request( $req )->get_status() );
$req3 = new WP_REST_Request( 'POST', '/hpv/v1/video/calls/' . $call['id'] . '/tasks' );
$req3->set_body_params( array( 'index' => 0, 'project_id' => $project_b ) );
it( 'feladat nem kerülhet más ügyfél projektjébe', in_array( rest_do_request( $req3 )->get_status(), array( 400, 409 ), true ) );
hpv_video_process( (int) $call['id'], true );
$ai = json_decode( hpv_p_get( 'call', (int) $call['id'] )['ai_data'], true );
it( 'újrafeldolgozás után a feladat-kapcsolat megmarad', (int) $task['id'] === (int) $ai['action_items'][0]['task_id'] );

echo "Megosztás, felvétel\n";
$req = new WP_REST_Request( 'POST', '/hpv/v1/video/calls/' . $call['id'] );
$req->set_body_params( array( 'shared' => true, 'title' => '  Homepage review v2 ' ) );
$d = rest_do_request( $req )->get_data();
it( 'megosztás és cím módosítása', true === $d['shared'] && 'Homepage review v2' === $d['title'] );
$recs = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/video/calls/' . $call['id'] . '/recordings' ) )->get_data();
it( 'felvétel letöltési link', 1 === count( $recs ) && 'https://files.example.test/rec-1.mp4' === $recs[0]['url'] && 30 === $recs[0]['minutes'] );
$full = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/video/calls/' . $call['id'] ) )->get_data();
it( 'részletek: leirat, AI, hozzájárulások (IP nélkül)', $full['transcript'] && $full['ai'] && 2 === count( $full['consents'] ) && ! isset( $full['consents'][0]['ip'] ) && 30 === $full['minutes'] );

echo "Portál (Meetings)\n";
function portal_as( WP_User $u, array $get ): string {
	wp_set_current_user( $u->ID );
	$_GET = $get;
	$html = hpv_p_portal_app();
	$_GET = array();
	wp_set_current_user( $GLOBALS['staff_id'] );
	return $html;
}
$GLOBALS['staff_id'] = $staff_id;
$page = portal_as( $maria, array( 'view' => 'call', 'id' => $call['id'] ) );
it( 'megosztott összefoglaló a portálon (a meghívó linkje is működik)', false !== strpos( $page, 'Maria approved the homepage' ) && false !== strpos( $page, 'Homepage approved' ) );
it( 'az ügyfél teendője „Your next steps” alatt', false !== strpos( $page, 'Your next steps' ) && false !== strpos( $page, 'Send Naples photos' ) );
it( 'belső megjegyzés és leirat nem látszik', false === strpos( $page, 'Upsell' ) && false === strpos( $page, 'thanks for joining' ) );
it( 'a Meetings listában szerepel', false !== strpos( portal_as( $maria, array( 'view' => 'meetings' ) ), 'Homepage review v2' ) );
it( 'másik ügyfél nem látja', false !== strpos( portal_as( $bob, array( 'view' => 'meetings', 'id' => $call['id'] ) ), 'Meeting not found' ) && false === strpos( portal_as( $bob, array( 'view' => 'meetings' ) ), 'Homepage review' ) );
hpv_p_update( 'call', (int) $call['id'], array( 'shared' => 0 ) );
it( 'meg nem osztott összefoglaló nem látszik', false !== strpos( portal_as( $maria, array( 'view' => 'meetings', 'id' => $call['id'] ) ), 'once your team shares it' ) && false === strpos( portal_as( $maria, array( 'view' => 'meetings' ) ), 'Homepage review' ) );

echo "AI hiba, újrapróbálás\n";
$call2    = hpv_video_start_call( array( 'client_id' => $client_a, 'title' => 'Kickoff' ), $staff_id );
$room_req = mock_requests( '/v1/rooms', 'POST' );
$room_req = end( $room_req );
it( 'alapból nincs felvétel', ! isset( $room_req['body']['properties']['enable_recording'] ) );
it( 'alapból nincs meghívó e-mail', 1 === count( array_filter( mails_to( "vmaria_$suffix@acme.test" ), fn( $m ) => 0 === strpos( $m['subject'], 'Video call:' ) ) ) );
$page = portal_as( $maria, array( 'view' => 'meetings', 'id' => $call2['id'] ) );
it( 'élő hívás: hozzájárulás kell a belépéshez', false !== strpos( $page, 'name="agree"' ) && false !== strpos( $page, 'value="join_call"' ) );
it( 'token csak hozzájárulás után (nincs a lapon)', false === strpos( $page, 'token' ) );
it( 'áttekintésben: élő hívás, csatlakozás', false !== strpos( portal_as( $maria, array() ), 'Video call in progress: Kickoff' ) );
set_transient( 'hpv_video_join_' . $maria->ID . '_' . $call2['id'], array( 'url' => 'https://hpv.daily.co/x', 'token' => 'tok-portal' ), 300 );
$page = portal_as( $maria, array( 'view' => 'meetings', 'id' => $call2['id'], 'joined' => 1 ) );
it( 'hozzájárulás után a hívás keret betölt, a token egyszer használható', false !== strpos( $page, 'tok-portal' ) && false !== strpos( $page, 'HPVCallMount' ) && false === get_transient( 'hpv_video_join_' . $maria->ID . '_' . $call2['id'] ) );
hpv_video_end_call( $call2 );
it( 'kézi lezárás: feldolgozásra vár, szoba lejárata frissítve, ütemezve', 'processing' === hpv_p_get( 'call', (int) $call2['id'] )['status'] && mock_requests( '/v1/rooms/' . $call2['room_name'], 'POST' ) && wp_next_scheduled( 'hpv_video_process_call', array( (int) $call2['id'] ) ) );
$GLOBALS['mock']['transcripts'][ $call2['room_id'] ] = array( array( 'transcriptId' => 'tr-2', 'roomId' => $call2['room_id'], 'status' => 't_finished' ) );
$GLOBALS['mock']['ai_status'] = 529;
$ai_before = count( mock_requests( 'anthropic.com' ) );
for ( $i = 0; $i < 3; $i++ ) {
	hpv_video_process( (int) $call2['id'] );
}
$c2 = hpv_p_get( 'call', (int) $call2['id'] );
it( '3 sikertelen AI próbálkozás után „hiba”, a leirat megmarad', 'failed' === $c2['status'] && 3 === (int) $c2['attempts'] && $c2['transcript'] && false !== strpos( $c2['error'], '529' ) );
hpv_video_process( (int) $call2['id'] );
it( 'hibás állapotban a cron nem próbálkozik tovább', 3 === count( mock_requests( 'anthropic.com' ) ) - $ai_before );
$GLOBALS['mock']['ai_status'] = 200;
rest_do_request( new WP_REST_Request( 'POST', '/hpv/v1/video/calls/' . $call2['id'] . '/process' ) );
it( 'kézi újrapróbálás sikeres', 'done' === hpv_p_get( 'call', (int) $call2['id'] )['status'] );
it( 'hibás AI válasz felismerve', is_wp_error( hpv_video_parse_ai( 'Sorry, I cannot help.' ) ) );

echo "Leirat nélkül, webhook\n";
$call3 = hpv_video_start_call( array( 'client_id' => $client_a ), $staff_id );
it( 'alapértelmezett cím: ügyfél + dátum', 0 === strpos( $call3['title'], 'Acme Pools' ) );
hpv_video_end_call( $call3 );
wp_clear_scheduled_hook( 'hpv_video_process_call', array( (int) $call3['id'] ) );
$w = new WP_REST_Request( 'POST', '/hpv/v1/video/webhook' );
$w->set_header( 'content-type', 'application/json' );
$w->set_body( wp_json_encode( array( 'type' => 'transcript.ready-to-download', 'payload' => array( 'room_name' => $call3['room_name'] ) ) ) );
wp_set_current_user( 0 );
$wr = rest_do_request( $w );
wp_set_current_user( $staff_id );
it( 'webhook bejelentkezés nélkül is fogad, és ütemezi az ellenőrzést', 200 === $wr->get_status() && wp_next_scheduled( 'hpv_video_process_call', array( (int) $call3['id'] ) ) );
$w->set_body( wp_json_encode( array( 'payload' => array( 'room_name' => 'hpv-unknown' ) ) ) );
it( 'ismeretlen szoba: 200, nincs teendő', 200 === rest_do_request( $w )->get_status() );
hpv_video_process( (int) $call3['id'] );
it( 'friss hívás leirat nélkül még vár', 'processing' === hpv_p_get( 'call', (int) $call3['id'] )['status'] );
hpv_p_update( 'call', (int) $call3['id'], array( 'ended_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ) );
hpv_video_process( (int) $call3['id'] );
it( '2 óra után leirat nélkül lezárva', 'no_transcript' === hpv_p_get( 'call', (int) $call3['id'] )['status'] );
$j = hpv_video_join( (int) $call3['id'], get_userdata( $staff_id ) );
it( 'véget ért hívásba nem lehet belépni', is_wp_error( $j ) && 409 === $j->get_error_data()['status'] );

echo "Takarítás\n";
foreach ( array( $call, $call2, $call3 ) as $x ) {
	wp_clear_scheduled_hook( 'hpv_video_process_call', array( (int) $x['id'] ) );
}
foreach ( $cleanup['clients'] as $cid ) {
	hpv_p_delete( 'client', $cid );
}
it( 'ügyfél törlésével a hívásai is törlődnek', ! hpv_p_find( 'call', array( 'client_id' => $client_a ) ) );

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $uid ) {
	wp_delete_user( $uid );
}

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
