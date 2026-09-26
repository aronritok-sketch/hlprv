<?php
/**
 * Videóhívás Daily.co-val: privát szoba, belépő token, résztvevői hozzájárulás,
 * leirat (Daily transcription), AI-összefoglaló (Anthropic Claude API) és teendők → feladatok.
 *
 * Kulcsok a wp-config.php-ban (nem az adatbázisban):
 *   define( 'HPV_DAILY_API_KEY', '…' );   // kötelező a hívásokhoz
 *   define( 'HPV_AI_API_KEY', '…' );      // az összefoglalóhoz (nélküle csak leirat készül)
 *   define( 'HPV_AI_MODEL', '…' );        // nem kötelező
 *
 * Folyamat: hívás indítása → a munkatárs belépésekor a böngésző elindítja a leiratot →
 * a hívás vége után a cron (5 percenként) vagy a Daily webhook lekéri a leiratot a Daily API-tól,
 * majd elkészül az összefoglaló. A webhook csak „csengő”: a tartalmát nem hisszük el, mindent a
 * saját kulccsal kérdezünk le a Daily-től, így hamisított webhook legfeljebb egy fölösleges ellenőrzést okoz.
 */

defined( 'ABSPATH' ) || exit;

const HPV_VIDEO_DAILY_API  = 'https://api.daily.co/v1';
const HPV_VIDEO_ROOM_HOURS = 4;    // ennyi ideig él a szoba és a belépő
const HPV_VIDEO_IDLE_END   = 300;  // üres szoba ennyi mp után lezártnak számít
const HPV_VIDEO_GIVE_UP    = 7200; // ennyi idő után leirat nélkül lezárjuk
const HPV_VIDEO_MAX_TRIES  = 3;    // AI-összefoglaló próbálkozások

function hpv_video_daily_key(): string {
	return defined( 'HPV_DAILY_API_KEY' ) ? (string) HPV_DAILY_API_KEY : '';
}

function hpv_video_ai_key(): string {
	return hpv_ai_key();
}

function hpv_video_ai_model(): string {
	return hpv_ai_model();
}

function hpv_video_enabled(): bool {
	return '' !== hpv_video_daily_key();
}

function hpv_video_now(): string {
	return gmdate( 'Y-m-d H:i:s' );
}

function hpv_video_ts( ?string $utc ): int {
	return $utc ? (int) strtotime( $utc . ' UTC' ) : 0;
}

/* ─── Daily REST ──────────────────────────────────────────── */

/**
 * @return array|WP_Error
 */
function hpv_video_daily( string $method, string $path, ?array $body = null ) {
	if ( ! hpv_video_enabled() ) {
		return new WP_Error( 'video_off', 'A videóhívás nincs beállítva (HPV_DAILY_API_KEY).', array( 'status' => 503 ) );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 15,
		'headers' => array(
			'Authorization' => 'Bearer ' . hpv_video_daily_key(),
			'Content-Type'  => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = wp_remote_request( HPV_VIDEO_DAILY_API . $path, $args );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'daily_http', 'A Daily nem érhető el: ' . $res->get_error_message(), array( 'status' => 502 ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 ) {
		$info = is_array( $data ) ? (string) ( $data['info'] ?? $data['error'] ?? '' ) : '';
		return new WP_Error( 'daily_error', 'Daily hiba (' . $code . ')' . ( $info ? ': ' . $info : '' ), array( 'status' => 502 ) );
	}

	return is_array( $data ) ? $data : array();
}

/**
 * Privát szoba, ami HPV_VIDEO_ROOM_HOURS óra múlva mindenkit kiléptet.
 *
 * @return array|WP_Error A Daily válasza (id, name, url).
 */
function hpv_video_create_room( bool $record ) {
	$props = array(
		'exp'                          => time() + HPV_VIDEO_ROOM_HOURS * HOUR_IN_SECONDS,
		'eject_at_room_exp'            => true,
		'enable_chat'                  => true,
		'enable_prejoin_ui'            => true,
		'enable_transcription_storage' => true,
		'lang'                         => 'en',
	);
	if ( $record ) {
		$props['enable_recording'] = 'cloud';
	}

	$room = hpv_video_daily(
		'POST',
		'/rooms',
		array(
			'name'       => 'hpv-' . strtolower( wp_generate_password( 14, false, false ) ),
			'privacy'    => 'private',
			'properties' => $props,
		)
	);
	if ( ! is_wp_error( $room ) && ( empty( $room['name'] ) || empty( $room['url'] ) ) ) {
		return new WP_Error( 'daily_room', 'A Daily nem adott vissza szobát.', array( 'status' => 502 ) );
	}

	return $room;
}

/**
 * Belépő a szobába. A munkatárs „owner”: ő indítja a leiratot (és a felvételt, ha kérték).
 *
 * @return string|WP_Error
 */
function hpv_video_token( array $call, WP_User $user, bool $owner ) {
	$name = $user->display_name ?: $user->user_login;
	if ( ! $owner ) {
		$company = hpv_p_client_name_safe( (int) $call['client_id'] );
		$name   .= $company ? ' (' . $company . ')' : '';
	}
	$props = array(
		'room_name'          => $call['room_name'],
		'user_name'          => mb_substr( $name, 0, 60 ),
		'user_id'            => (string) $user->ID,
		'is_owner'           => $owner,
		'exp'                => time() + HPV_VIDEO_ROOM_HOURS * HOUR_IN_SECONDS,
		'eject_at_token_exp' => true,
	);
	if ( $owner && ! empty( $call['record'] ) ) {
		$props['enable_recording']      = 'cloud';
		$props['start_cloud_recording'] = true;
	}
	$res = hpv_video_daily( 'POST', '/meeting-tokens', array( 'properties' => $props ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	return empty( $res['token'] ) ? new WP_Error( 'daily_token', 'A Daily nem adott belépőt.', array( 'status' => 502 ) ) : (string) $res['token'];
}

/* ─── Hívás életciklusa ───────────────────────────────────── */

/**
 * Új hívás: szoba + rekord + üzenet az ügyfél csatornájába (+ e-mail meghívó).
 *
 * @param array $args client_id, project_id?, title?, record?, notify?
 * @return array|WP_Error A hívás rekordja.
 */
function hpv_video_start_call( array $args, int $user_id ) {
	$client = hpv_p_get( 'client', absint( $args['client_id'] ?? 0 ) );
	if ( ! $client ) {
		return new WP_Error( 'client', 'Válassz ügyfelet.', array( 'status' => 400 ) );
	}
	$project_id = absint( $args['project_id'] ?? 0 );
	if ( $project_id ) {
		$project = hpv_p_get( 'project', $project_id );
		if ( ! $project || (int) $project['client_id'] !== (int) $client['id'] ) {
			return new WP_Error( 'project', 'A projekt nem ehhez az ügyfélhez tartozik.', array( 'status' => 400 ) );
		}
	}
	$title  = trim( sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );
	$title  = '' !== $title ? mb_substr( $title, 0, 120 ) : $client['name'] . ' — ' . wp_date( 'M j, Y' );
	$record = ! empty( $args['record'] );

	$room = hpv_video_create_room( $record );
	if ( is_wp_error( $room ) ) {
		return $room;
	}

	$id = hpv_p_insert(
		'call',
		array(
			'client_id'  => (int) $client['id'],
			'project_id' => $project_id,
			'title'      => $title,
			'status'     => 'live',
			'record'     => $record ? 1 : 0,
			'room_name'  => (string) $room['name'],
			'room_id'    => (string) ( $room['id'] ?? '' ),
			'room_url'   => esc_url_raw( (string) $room['url'] ),
			'started_by' => $user_id,
			'started_at' => hpv_video_now(),
			'consents'   => '[]',
			'attempts'   => 0,
		)
	);
	if ( ! $id ) {
		hpv_video_daily( 'DELETE', '/rooms/' . rawurlencode( (string) $room['name'] ) );
		return new WP_Error( 'db', 'Az adatbázisba írás nem sikerült.', array( 'status' => 500 ) );
	}
	$call = hpv_p_get( 'call', $id );

	hpv_video_announce( $call, $user_id, ! empty( $args['notify'] ) );
	hpv_p_log( (int) $client['id'], 'call', 'Videóhívás indult: ' . $title, false, $user_id );

	return $call;
}

/**
 * Üzenet az ügyfél csatornájába a csatlakozási linkkel. A chat saját e-mailje helyett (ne menjen két levél)
 * kérésre egy egyértelmű meghívó megy az ügyfél portál-felhasználóinak.
 */
function hpv_video_announce( array $call, int $user_id, bool $email ): void {
	$link    = hpv_p_portal_url( array( 'view' => 'call', 'id' => (int) $call['id'] ) );
	$channel = hpv_chat_client_channel( (int) $call['client_id'], true );
	if ( $channel ) {
		hpv_chat_add_member( (int) $channel['id'], $user_id );
		$msg = hpv_p_insert(
			'chat_message',
			array(
				'channel_id' => (int) $channel['id'],
				'user_id'    => $user_id,
				'body'       => hpv_with_client_lang( (int) $call['client_id'], fn() => hpv_t( 'Video call started: %s', $call['title'] ) . "\n" . hpv_t( 'Join here: %s', $link ) ),
			)
		);
		hpv_chat_mark_read( (int) $channel['id'], $user_id, $msg );
	}

	if ( $email ) {
		$who = hpv_chat_user_label( $user_id )['name'];
		hpv_with_client_lang(
			(int) $call['client_id'],
			fn() => hpv_p_notify_client(
				(int) $call['client_id'],
				hpv_t( 'Video call: %s', $call['title'] ),
				hpv_t( 'Your video call is starting' ),
				'<p>' . hpv_t( '%s from %s started a video call with you: <strong>%s</strong>.', esc_html( $who ), esc_html( hpv_p_settings()['company_name'] ), esc_html( $call['title'] ) ) . '</p>'
				. '<p>' . esc_html( hpv_t( 'Join from your client portal. The call is recorded and transcribed so we can send you a summary; you will be asked to agree before joining.' ) ) . '</p>',
				hpv_t( 'Join the call' ),
				$link
			)
		);
	}
}

/**
 * Hozzájárulás naplózása (Florida: minden fél beleegyezése kell a rögzítéshez).
 */
function hpv_video_add_consent( int $call_id, WP_User $user, string $role ): void {
	$call = hpv_p_get( 'call', $call_id );
	if ( ! $call ) {
		return;
	}
	$list   = json_decode( (string) $call['consents'], true );
	$list   = is_array( $list ) ? $list : array();
	$list[] = array(
		'user_id' => $user->ID,
		'name'    => $user->display_name,
		'email'   => $user->user_email,
		'role'    => $role,
		'at'      => gmdate( 'c' ),
		'ip'      => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
		'agent'   => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 250 ),
	);
	hpv_p_update(
		'call',
		$call_id,
		array(
			'consents'     => wp_json_encode( $list ),
			'last_join_at' => hpv_video_now(),
		)
	);
}

/**
 * Belépés: jogosultság, hozzájárulás, token. Ügyfél csak a saját cége élő hívásába léphet.
 *
 * @return array|WP_Error {url, token, owner}
 */
function hpv_video_join( int $call_id, WP_User $user ) {
	$call  = hpv_p_get( 'call', $call_id );
	$staff = user_can( $user, 'hpv_manage_crm' );
	if ( ! $call || ( ! $staff && hpv_p_user_client_id( $user->ID ) !== (int) $call['client_id'] ) ) {
		return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
	}
	if ( 'live' !== $call['status'] ) {
		return new WP_Error( 'not_live', $staff ? 'A hívás már véget ért.' : 'This call has ended.', array( 'status' => 409 ) );
	}
	$token = hpv_video_token( $call, $user, $staff );
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	hpv_video_add_consent( $call_id, $user, $staff ? 'staff' : 'client' );

	return array(
		'url'   => (string) $call['room_url'],
		'token' => $token,
		'owner' => $staff,
	);
}

/**
 * Hívás lezárása: a szoba 30 mp múlva mindenkit kiléptet, a feldolgozás 90 mp múlva indul.
 */
function hpv_video_end_call( array $call ): array {
	if ( 'live' === $call['status'] ) {
		hpv_p_update(
			'call',
			(int) $call['id'],
			array(
				'status'   => 'processing',
				'ended_at' => hpv_video_now(),
			)
		);
		hpv_video_daily(
			'POST',
			'/rooms/' . rawurlencode( (string) $call['room_name'] ),
			array(
				'properties' => array(
					'exp'               => time() + 30,
					'eject_at_room_exp' => true,
				),
			)
		);
		if ( ! wp_next_scheduled( 'hpv_video_process_call', array( (int) $call['id'] ) ) ) {
			wp_schedule_single_event( time() + 90, 'hpv_video_process_call', array( (int) $call['id'] ) );
		}
	}

	return hpv_p_get( 'call', (int) $call['id'] );
}

/**
 * Élő hívásnál: véget ért-e? (lejárt a szoba, vagy HPV_VIDEO_IDLE_END mp óta üres).
 */
function hpv_video_is_over( array $call ): bool {
	$now = time();
	if ( $now > hpv_video_ts( $call['started_at'] ) + HPV_VIDEO_ROOM_HOURS * HOUR_IN_SECONDS ) {
		return true;
	}
	$last = max( hpv_video_ts( $call['started_at'] ), hpv_video_ts( $call['last_join_at'] ) );
	if ( $now - $last < HPV_VIDEO_IDLE_END ) {
		return false;
	}
	$presence = hpv_video_daily( 'GET', '/rooms/' . rawurlencode( (string) $call['room_name'] ) . '/presence' );

	return ! is_wp_error( $presence ) && 0 === (int) ( $presence['total_count'] ?? count( (array) ( $presence['data'] ?? array() ) ) );
}

/* ─── Leirat ──────────────────────────────────────────────── */

/**
 * A szoba leiratai a Daily-től.
 *
 * @return array|WP_Error {state: pending|none|ready, text?}
 */
function hpv_video_fetch_transcript( array $call ) {
	if ( '' === (string) $call['room_id'] ) {
		return array( 'state' => 'none' );
	}
	$list = hpv_video_daily( 'GET', '/transcript?roomId=' . rawurlencode( (string) $call['room_id'] ) );
	if ( is_wp_error( $list ) ) {
		return $list;
	}
	$items = array_values(
		array_filter(
			(array) ( $list['data'] ?? array() ),
			fn( $t ) => is_array( $t ) && (string) ( $t['roomId'] ?? $t['room_id'] ?? $call['room_id'] ) === (string) $call['room_id']
		)
	);
	if ( ! $items ) {
		return array( 'state' => 'none' );
	}
	foreach ( $items as $t ) {
		if ( in_array( (string) ( $t['status'] ?? '' ), array( 't_in_progress', 'in_progress', 'started' ), true ) ) {
			return array( 'state' => 'pending' );
		}
	}
	// Időrendben (ha a válasz tartalmaz időt), hogy a több részletben készült leirat sorban legyen.
	usort( $items, fn( $a, $b ) => (int) ( $a['created'] ?? $a['start_ts'] ?? 0 ) <=> (int) ( $b['created'] ?? $b['start_ts'] ?? 0 ) );

	$parts = array();
	foreach ( $items as $t ) {
		$finished = in_array( (string) ( $t['status'] ?? '' ), array( 't_finished', 'finished' ), true );
		if ( ! $finished || ( isset( $t['isVttAvailable'] ) && ! $t['isVttAvailable'] ) ) {
			continue;
		}
		$tid  = (string) ( $t['transcriptId'] ?? $t['id'] ?? '' );
		$link = hpv_video_daily( 'GET', '/transcript/' . rawurlencode( $tid ) . '/access-link' );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		$url = (string) ( $link['link'] ?? $link['download_link'] ?? '' );
		$res = $url ? wp_remote_get( $url, array( 'timeout' => 20 ) ) : new WP_Error( 'no_link', 'A leirat linkje hiányzik.' );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'transcript_download', 'A leirat letöltése nem sikerült.' );
		}
		$parts[] = hpv_video_parse_vtt( (string) wp_remote_retrieve_body( $res ) );
	}
	$text = trim( implode( "\n\n", array_filter( $parts ) ) );

	return '' === $text ? array( 'state' => 'none' ) : array(
		'state' => 'ready',
		'text'  => $text,
	);
}

/**
 * WebVTT → olvasható leirat: „[mm:ss] Név: szöveg”, az egymást követő sorok beszélőnként összevonva.
 */
function hpv_video_parse_vtt( string $vtt ): string {
	$turns   = array();
	$speaker = null;
	$cue     = '';
	$skip    = false;
	foreach ( preg_split( '/\r\n|\r|\n/', $vtt ) as $raw ) {
		$line = trim( $raw );
		if ( '' === $line ) {
			$skip = false;
			continue;
		}
		if ( $skip || 0 === strpos( $line, 'WEBVTT' ) || preg_match( '/^(NOTE|STYLE|REGION)\b/', $line ) ) {
			$skip = $skip || 0 !== strpos( $line, 'WEBVTT' );
			continue;
		}
		if ( preg_match( '/^(?:(\d+):)?(\d{1,2}):(\d{2})[.,]\d{3}\s+-->/', $line, $m ) ) {
			$minutes = (int) $m[1] * 60 + (int) $m[2];
			$cue     = sprintf( '%02d:%02d', $minutes, (int) $m[3] );
			continue;
		}
		if ( ctype_digit( $line ) ) {
			continue; // cue sorszám
		}
		$who = null;
		if ( preg_match( '/^<v(?:\.[^\s>]*)?\s+([^>]+)>(.*)$/u', $line, $m ) ) {
			$who  = trim( $m[1] );
			$line = $m[2];
		}
		$text = trim( html_entity_decode( wp_strip_all_tags( $line ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $text ) {
			continue;
		}
		if ( ! $turns || ( null !== $who && $who !== $speaker ) ) {
			$speaker = $who ?? $speaker;
			$turns[] = array(
				'time' => $cue,
				'who'  => $speaker,
				'text' => $text,
			);
			continue;
		}
		$turns[ count( $turns ) - 1 ]['text'] .= ' ' . $text;
	}

	return implode(
		"\n",
		array_map(
			fn( $t ) => ( $t['time'] ? '[' . $t['time'] . '] ' : '' ) . ( $t['who'] ? $t['who'] . ': ' : '' ) . $t['text'],
			$turns
		)
	);
}

/* ─── AI-összefoglaló ─────────────────────────────────────── */

/**
 * @return array|WP_Error {summary, decisions[], action_items[{title, owner, due}], internal_notes[]}
 */
function hpv_video_summarize( array $call, string $transcript ) {
	if ( '' === hpv_video_ai_key() ) {
		return new WP_Error( 'ai_off', 'Nincs AI kulcs (HPV_AI_API_KEY): csak a leirat készült el.' );
	}
	$client  = hpv_p_get( 'client', (int) $call['client_id'] );
	$project = $call['project_id'] ? hpv_p_get( 'project', (int) $call['project_id'] ) : null;
	$limit   = 180000;
	if ( strlen( $transcript ) > $limit ) {
		$transcript = substr( $transcript, 0, $limit ) . "\n[… transcript truncated]";
	}

	$system = 'You summarize recorded meetings between HelloProVision (a web design and digital marketing agency in Southwest Florida) and its clients. '
		. 'Respond with a single JSON object and nothing else, in this shape: '
		. '{"summary": string, "decisions": [string], "action_items": [{"title": string, "owner": "agency" | "client", "due": "YYYY-MM-DD" or ""}], "internal_notes": [string]}. '
		. '"summary": 3-6 neutral sentences the client could read. "decisions": what was agreed. '
		. '"action_items": concrete next steps, each a short imperative task title; "due" only if a date was actually said. '
		. '"internal_notes": risks, open questions, upsell opportunities or client sentiment for the agency only. '
		. 'Write in the language the meeting was held in. Use only what was said; never invent names, numbers or dates. '
		. 'Today is ' . wp_date( 'Y-m-d' ) . '.';
	$user   = 'Meeting: ' . $call['title'] . "\nClient: " . ( $client['name'] ?? '' ) . ( $project ? "\nProject: " . $project['name'] : '' )
		. "\n\n<transcript>\n" . $transcript . "\n</transcript>";

	$text = hpv_ai_complete( $system, $user, 2000, 90 );
	if ( is_wp_error( $text ) ) {
		return $text;
	}

	return hpv_video_parse_ai( $text );
}

/**
 * Az AI válaszából a JSON kiolvasása és ellenőrzése.
 *
 * @return array|WP_Error
 */
function hpv_video_parse_ai( string $text ) {
	$start = strpos( $text, '{' );
	$end   = strrpos( $text, '}' );
	$json  = false !== $start && false !== $end ? json_decode( substr( $text, $start, $end - $start + 1 ), true ) : null;
	if ( ! is_array( $json ) || ! isset( $json['summary'] ) ) {
		return new WP_Error( 'ai_format', 'Az AI válasza nem értelmezhető.' );
	}
	$strings = fn( $list ) => array_values( array_filter( array_map( fn( $s ) => is_string( $s ) ? trim( sanitize_text_field( $s ) ) : '', (array) $list ) ) );
	$items   = array();
	foreach ( (array) ( $json['action_items'] ?? array() ) as $item ) {
		$title = is_array( $item ) ? trim( sanitize_text_field( (string) ( $item['title'] ?? '' ) ) ) : '';
		if ( '' === $title ) {
			continue;
		}
		$due     = (string) ( $item['due'] ?? '' );
		$items[] = array(
			'title'   => mb_substr( $title, 0, 200 ),
			'owner'   => 'client' === ( $item['owner'] ?? '' ) ? 'client' : 'agency',
			'due'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ? $due : '',
			'task_id' => 0,
		);
	}

	return array(
		'summary'        => trim( sanitize_textarea_field( (string) $json['summary'] ) ),
		'decisions'      => $strings( $json['decisions'] ?? array() ),
		'action_items'   => $items,
		'internal_notes' => $strings( $json['internal_notes'] ?? array() ),
	);
}

/* ─── Feldolgozás (cron, webhook, kézi) ───────────────────── */

/**
 * Egy hívás továbbléptetése: élő → (vége?) → leirat → összefoglaló. Többször hívható, csak a hiányzó lépést végzi el.
 */
function hpv_video_process( int $call_id, bool $force = false ): ?array {
	$call = hpv_p_get( 'call', $call_id );
	if ( ! $call || ! hpv_video_enabled() ) {
		return $call;
	}
	$lock = 'hpv_video_lock_' . $call_id;
	if ( get_transient( $lock ) ) {
		return $call;
	}
	set_transient( $lock, 1, 3 * MINUTE_IN_SECONDS );

	try {
		if ( 'live' === $call['status'] ) {
			if ( ! hpv_video_is_over( $call ) ) {
				return $call;
			}
			hpv_p_update(
				'call',
				$call_id,
				array(
					'status'   => 'processing',
					'ended_at' => hpv_video_now(),
				)
			);
			$call = hpv_p_get( 'call', $call_id );
		}
		if ( 'processing' !== $call['status'] && ! $force ) {
			return $call;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 150 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- néhol tiltott
		}

		if ( '' === trim( (string) $call['transcript'] ) ) {
			$result = hpv_video_fetch_transcript( $call );
			if ( is_wp_error( $result ) ) {
				hpv_p_update( 'call', $call_id, array( 'error' => $result->get_error_message() ) );
				return hpv_p_get( 'call', $call_id );
			}
			if ( 'pending' === $result['state'] ) {
				return $call;
			}
			if ( 'none' === $result['state'] ) {
				if ( $force || time() - hpv_video_ts( $call['ended_at'] ) > HPV_VIDEO_GIVE_UP ) {
					hpv_p_update(
						'call',
						$call_id,
						array(
							'status' => 'no_transcript',
							'error'  => 'Nem készült leirat (nem volt munkatárs a hívásban, vagy nem indult el a leirat).',
						)
					);
				}
				return hpv_p_get( 'call', $call_id );
			}
			hpv_p_update( 'call', $call_id, array( 'transcript' => $result['text'] ) );
			$call = hpv_p_get( 'call', $call_id );
		}

		$ai = hpv_video_summarize( $call, (string) $call['transcript'] );
		if ( is_wp_error( $ai ) ) {
			if ( 'ai_off' === $ai->get_error_code() ) {
				hpv_p_update(
					'call',
					$call_id,
					array(
						'status' => 'done',
						'error'  => $ai->get_error_message(),
					)
				);
				hpv_video_finished( hpv_p_get( 'call', $call_id ) );
				return hpv_p_get( 'call', $call_id );
			}
			$attempts = (int) $call['attempts'] + 1;
			hpv_p_update(
				'call',
				$call_id,
				array(
					'attempts' => $attempts,
					'error'    => $ai->get_error_message(),
					'status'   => $attempts >= HPV_VIDEO_MAX_TRIES ? 'failed' : 'processing',
				)
			);
			return hpv_p_get( 'call', $call_id );
		}

		// Korábban már feladattá alakított teendők megtartása újrafeldolgozáskor.
		$old = json_decode( (string) $call['ai_data'], true );
		foreach ( $ai['action_items'] as $i => $item ) {
			foreach ( (array) ( $old['action_items'] ?? array() ) as $prev ) {
				if ( ! empty( $prev['task_id'] ) && ( $prev['title'] ?? '' ) === $item['title'] ) {
					$ai['action_items'][ $i ]['task_id'] = (int) $prev['task_id'];
				}
			}
		}
		hpv_p_update(
			'call',
			$call_id,
			array(
				'status'  => 'done',
				'summary' => $ai['summary'],
				'ai_data' => wp_json_encode( $ai ),
				'error'   => '',
			)
		);
		hpv_video_finished( hpv_p_get( 'call', $call_id ) );

		return hpv_p_get( 'call', $call_id );
	} finally {
		delete_transient( $lock );
	}
}

/**
 * Kész: napló az ügyfélhez (belső) és e-mail a hívást indító munkatársnak.
 */
function hpv_video_finished( array $call ): void {
	$body = 'Videóhívás összefoglaló: ' . $call['title'] . ( $call['summary'] ? "\n\n" . $call['summary'] : '' );
	hpv_p_log( (int) $call['client_id'], 'call', $body, false, (int) $call['started_by'] );

	$user = get_userdata( (int) $call['started_by'] );
	if ( $user ) {
		hpv_p_send(
			$user->user_email,
			'Kész a hívás összefoglalója: ' . $call['title'],
			hpv_p_email_html(
				'Kész a hívás összefoglalója',
				'<p><strong>' . esc_html( $call['title'] ) . '</strong></p>' . ( $call['summary'] ? '<p>' . nl2br( esc_html( $call['summary'] ) ) . '</p>' : '<p>' . esc_html( (string) $call['error'] ) . '</p>' ),
				'Megnyitás a CRM-ben',
				hpv_p_crm_app_url( '/calls/' . (int) $call['id'] )
			)
		);
	}
}

add_filter( 'cron_schedules', 'hpv_video_cron_schedules' );

function hpv_video_cron_schedules( $schedules ) {
	$schedules['hpv_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => '5 percenként (HelloProVision videó)',
	);

	return $schedules;
}

add_action( 'init', 'hpv_video_schedule' );

function hpv_video_schedule() {
	if ( hpv_video_enabled() && ! wp_next_scheduled( 'hpv_video_cron' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hpv_five_minutes', 'hpv_video_cron' );
	}
}

register_deactivation_hook( HPV_PORTAL_FILE, fn() => wp_clear_scheduled_hook( 'hpv_video_cron' ) );

add_action( 'hpv_video_cron', 'hpv_video_cron_run' );
add_action( 'hpv_video_process_call', 'hpv_video_process' );

function hpv_video_cron_run() {
	foreach ( hpv_p_find( 'call', array( 'status' => array( 'live', 'processing' ) ) ) as $call ) {
		hpv_video_process( (int) $call['id'] );
	}
}

/* ─── Megjelenítés ────────────────────────────────────────── */

function hpv_video_format_call( array $c, bool $full = false ): array {
	$client  = hpv_p_get( 'client', (int) $c['client_id'] );
	$project = $c['project_id'] ? hpv_p_get( 'project', (int) $c['project_id'] ) : null;
	$start   = hpv_video_ts( $c['started_at'] );
	$end     = hpv_video_ts( $c['ended_at'] );
	$ai      = json_decode( (string) $c['ai_data'], true );
	$out     = array(
		'id'         => (int) $c['id'],
		'title'      => $c['title'],
		'status'     => $c['status'],
		'client'     => $client ? array(
			'id'   => (int) $client['id'],
			'name' => $client['name'],
		) : null,
		'project'    => $project ? array(
			'id'    => (int) $project['id'],
			'name'  => $project['name'],
			'color' => $project['color'],
		) : null,
		'record'     => (bool) $c['record'],
		'shared'     => (bool) $c['shared'],
		'started_by' => (int) $c['started_by'] ? array_merge( array( 'id' => (int) $c['started_by'] ), hpv_chat_user_label( (int) $c['started_by'] ) ) : null,
		'started_at' => $start,
		'ended_at'   => $end,
		'minutes'    => $end && $start ? max( 1, (int) round( ( $end - $start ) / 60 ) ) : 0,
		'has_summary' => '' !== (string) $c['summary'],
		'action_count' => is_array( $ai ) ? count( (array) ( $ai['action_items'] ?? array() ) ) : 0,
		'error'      => (string) $c['error'],
	);
	if ( $full ) {
		$consents          = json_decode( (string) $c['consents'], true );
		$out['summary']    = (string) $c['summary'];
		$out['ai']         = is_array( $ai ) ? $ai : null;
		$out['transcript'] = (string) $c['transcript'];
		$out['consents']   = array_map(
			fn( $x ) => array(
				'name' => $x['name'] ?? '',
				'role' => $x['role'] ?? '',
				'at'   => $x['at'] ?? '',
			),
			is_array( $consents ) ? $consents : array()
		);
	}

	return $out;
}

/* ─── REST ────────────────────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_video_routes' );

function hpv_video_routes() {
	$staff = fn() => hpv_p_is_staff();
	$route = function ( string $path, string $methods, callable $callback, $permission = null ) use ( $staff ) {
		register_rest_route(
			'hpv/v1',
			'/video' . $path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $permission ?? $staff,
			)
		);
	};

	$route( '/calls', 'GET', 'hpv_video_rest_list' );
	$route( '/calls', 'POST', 'hpv_video_rest_create' );
	$route( '/calls/(?P<id>\d+)', 'GET', 'hpv_video_rest_get' );
	$route( '/calls/(?P<id>\d+)', 'POST', 'hpv_video_rest_update' );
	$route( '/calls/(?P<id>\d+)', 'DELETE', 'hpv_video_rest_delete', fn() => current_user_can( 'manage_options' ) );
	$route( '/calls/(?P<id>\d+)/join', 'POST', 'hpv_video_rest_join' );
	$route( '/calls/(?P<id>\d+)/end', 'POST', 'hpv_video_rest_end' );
	$route( '/calls/(?P<id>\d+)/process', 'POST', 'hpv_video_rest_process' );
	$route( '/calls/(?P<id>\d+)/tasks', 'POST', 'hpv_video_rest_task' );
	$route( '/calls/(?P<id>\d+)/recordings', 'GET', 'hpv_video_rest_recordings' );
	$route( '/webhook', 'POST', 'hpv_video_rest_webhook', '__return_true' );
}

function hpv_video_not_found() {
	return new WP_Error( 'not_found', 'A hívás nem található.', array( 'status' => 404 ) );
}

function hpv_video_rest_list( WP_REST_Request $request ) {
	$where = array();
	foreach ( array( 'client_id', 'project_id' ) as $key ) {
		if ( $request->get_param( $key ) ) {
			$where[ $key ] = absint( $request->get_param( $key ) );
		}
	}
	$calls = hpv_p_find(
		'call',
		$where,
		array(
			'orderby' => 'id',
			'order'   => 'DESC',
			'limit'   => 200,
		)
	);

	return rest_ensure_response(
		array(
			'enabled' => hpv_video_enabled(),
			'ai'      => '' !== hpv_video_ai_key(),
			'calls'   => array_map( 'hpv_video_format_call', $calls ),
		)
	);
}

function hpv_video_rest_create( WP_REST_Request $request ) {
	$call = hpv_video_start_call( $request->get_params(), get_current_user_id() );

	return is_wp_error( $call ) ? $call : rest_ensure_response( hpv_video_format_call( $call, true ) );
}

function hpv_video_rest_get( WP_REST_Request $request ) {
	$call = hpv_p_get( 'call', (int) $request['id'] );

	return $call ? rest_ensure_response( hpv_video_format_call( $call, true ) ) : hpv_video_not_found();
}

function hpv_video_rest_update( WP_REST_Request $request ) {
	$call = hpv_p_get( 'call', (int) $request['id'] );
	if ( ! $call ) {
		return hpv_video_not_found();
	}
	$data = array();
	if ( null !== $request->get_param( 'title' ) ) {
		$title = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );
		if ( '' === $title ) {
			return new WP_Error( 'title', 'Adj címet a hívásnak.', array( 'status' => 400 ) );
		}
		$data['title'] = mb_substr( $title, 0, 120 );
	}
	if ( null !== $request->get_param( 'shared' ) ) {
		$data['shared'] = rest_sanitize_boolean( $request->get_param( 'shared' ) ) ? 1 : 0;
	}
	if ( null !== $request->get_param( 'project_id' ) ) {
		$pid     = absint( $request->get_param( 'project_id' ) );
		$project = $pid ? hpv_p_get( 'project', $pid ) : null;
		if ( $pid && ( ! $project || (int) $project['client_id'] !== (int) $call['client_id'] ) ) {
			return new WP_Error( 'project', 'A projekt nem ehhez az ügyfélhez tartozik.', array( 'status' => 400 ) );
		}
		$data['project_id'] = $pid;
	}
	if ( $data ) {
		hpv_p_update( 'call', (int) $call['id'], $data );
	}

	return rest_ensure_response( hpv_video_format_call( hpv_p_get( 'call', (int) $call['id'] ), true ) );
}

function hpv_video_rest_delete( WP_REST_Request $request ) {
	$call = hpv_p_get( 'call', (int) $request['id'] );
	if ( ! $call ) {
		return hpv_video_not_found();
	}
	if ( $call['room_name'] ) {
		hpv_video_daily( 'DELETE', '/rooms/' . rawurlencode( (string) $call['room_name'] ) );
	}
	hpv_p_delete( 'call', (int) $call['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

function hpv_video_rest_join( WP_REST_Request $request ) {
	$join = hpv_video_join( (int) $request['id'], wp_get_current_user() );

	return is_wp_error( $join ) ? $join : rest_ensure_response( $join );
}

function hpv_video_rest_end( WP_REST_Request $request ) {
	$call = hpv_p_get( 'call', (int) $request['id'] );

	return $call ? rest_ensure_response( hpv_video_format_call( hpv_video_end_call( $call ), true ) ) : hpv_video_not_found();
}

/**
 * Kézi újrafeldolgozás (pl. AI hiba után): a próbálkozások nullázódnak.
 */
function hpv_video_rest_process( WP_REST_Request $request ) {
	$call = hpv_p_get( 'call', (int) $request['id'] );
	if ( ! $call ) {
		return hpv_video_not_found();
	}
	if ( 'live' !== $call['status'] ) {
		hpv_p_update(
			'call',
			(int) $call['id'],
			array(
				'status'   => 'processing',
				'attempts' => 0,
			)
		);
	}
	$call = hpv_video_process( (int) $call['id'], 'live' !== $call['status'] );

	return rest_ensure_response( hpv_video_format_call( $call, true ) );
}

/**
 * Teendőből feladat a projektben (a hívás projektjében, vagy a megadottban).
 */
function hpv_video_rest_task( WP_REST_Request $request ) {
	$call = hpv_p_get( 'call', (int) $request['id'] );
	if ( ! $call ) {
		return hpv_video_not_found();
	}
	$ai    = json_decode( (string) $call['ai_data'], true );
	$index = absint( $request->get_param( 'index' ) );
	$item  = is_array( $ai ) ? ( $ai['action_items'][ $index ] ?? null ) : null;
	if ( ! $item ) {
		return new WP_Error( 'item', 'A teendő nem található.', array( 'status' => 404 ) );
	}
	if ( ! empty( $item['task_id'] ) && hpv_p_get( 'task', (int) $item['task_id'] ) ) {
		return new WP_Error( 'exists', 'Ebből a teendőből már van feladat.', array( 'status' => 409 ) );
	}
	$project = hpv_p_get( 'project', absint( $request->get_param( 'project_id' ) ?: $call['project_id'] ) );
	if ( ! $project || (int) $project['client_id'] !== (int) $call['client_id'] ) {
		return new WP_Error( 'project', 'Válassz projektet ennek az ügyfélnek.', array( 'status' => 400 ) );
	}

	$status = 'client' === $item['owner'] ? 'client' : 'todo';
	$id     = hpv_p_insert(
		'task',
		array(
			'project_id'  => (int) $project['id'],
			'title'       => $item['title'],
			'status'      => $status,
			'due_date'    => $item['due'] ?: null,
			'description' => 'Videóhívásból: ' . $call['title'] . ' (' . wp_date( 'Y-m-d', hpv_video_ts( $call['started_at'] ) ) . ')',
			'visible'     => 'client' === $item['owner'] ? 1 : 0,
			'sort'        => hpv_pm_next_sort( (int) $project['id'], $status ),
			'created_by'  => get_current_user_id(),
		)
	);
	$ai['action_items'][ $index ]['task_id'] = $id;
	hpv_p_update( 'call', (int) $call['id'], array( 'ai_data' => wp_json_encode( $ai ) ) );
	hpv_pm_after_task_change( null, hpv_p_get( 'task', $id ) );

	return rest_ensure_response(
		array(
			'task_id'    => $id,
			'project_id' => (int) $project['id'],
			'call'       => hpv_video_format_call( hpv_p_get( 'call', (int) $call['id'] ), true ),
		)
	);
}

/**
 * Felvételek letöltési linkjei (a Daily-nél tárolva, rövid ideig érvényes link).
 */
function hpv_video_rest_recordings( WP_REST_Request $request ) {
	$call = hpv_p_get( 'call', (int) $request['id'] );
	if ( ! $call ) {
		return hpv_video_not_found();
	}
	$list = hpv_video_daily( 'GET', '/recordings?room_name=' . rawurlencode( (string) $call['room_name'] ) );
	if ( is_wp_error( $list ) ) {
		return $list;
	}
	$out = array();
	foreach ( (array) ( $list['data'] ?? array() ) as $rec ) {
		if ( ( $rec['room_name'] ?? $call['room_name'] ) !== $call['room_name'] || empty( $rec['id'] ) ) {
			continue;
		}
		$link = hpv_video_daily( 'GET', '/recordings/' . rawurlencode( (string) $rec['id'] ) . '/access-link' );
		if ( is_wp_error( $link ) || empty( $link['download_link'] ) ) {
			continue;
		}
		$out[] = array(
			'url'      => esc_url_raw( (string) $link['download_link'] ),
			'minutes'  => max( 1, (int) round( (int) ( $rec['duration'] ?? 0 ) / 60 ) ),
			'start_ts' => (int) ( $rec['start_ts'] ?? 0 ),
		);
	}

	return rest_ensure_response( $out );
}

/**
 * Daily webhook („csengő”): a hozzá tartozó hívás ellenőrzését ütemezi. A tartalmát nem használjuk fel,
 * a feldolgozás a Daily API-tól kérdez le mindent.
 */
function hpv_video_rest_webhook( WP_REST_Request $request ) {
	$body    = (array) $request->get_json_params();
	$payload = (array) ( $body['payload'] ?? array() );
	$room    = sanitize_text_field( (string) ( $payload['room_name'] ?? $payload['room'] ?? '' ) );
	$room_id = sanitize_text_field( (string) ( $payload['room_id'] ?? $payload['roomId'] ?? '' ) );

	$call = null;
	if ( '' !== $room ) {
		$call = hpv_p_find( 'call', array( 'room_name' => $room ), array( 'limit' => 1 ) )[0] ?? null;
	}
	if ( ! $call && '' !== $room_id ) {
		$call = hpv_p_find( 'call', array( 'room_id' => $room_id ), array( 'limit' => 1 ) )[0] ?? null;
	}
	if ( $call && in_array( $call['status'], array( 'live', 'processing' ), true ) && ! wp_next_scheduled( 'hpv_video_process_call', array( (int) $call['id'] ) ) ) {
		wp_schedule_single_event( time() + 20, 'hpv_video_process_call', array( (int) $call['id'] ) );
	}

	return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * Webhook regisztrálása a Daily-nél (Beállítások oldalról, egyszer kell).
 *
 * @return array|WP_Error
 */
function hpv_video_register_webhook() {
	$url = hpv_p_scheme() . '://' . hpv_p_crm_host() . '/wp-json/hpv/v1/video/webhook';
	$res = hpv_video_daily(
		'POST',
		'/webhooks',
		array(
			'url'        => $url,
			'eventTypes' => array( 'meeting.ended', 'transcript.ready-to-download', 'recording.ready-to-download' ),
		)
	);
	if ( ! is_wp_error( $res ) ) {
		update_option( 'hpv_video_webhook', array( 'uuid' => (string) ( $res['uuid'] ?? '' ), 'url' => $url ), false );
	}

	return $res;
}

/* ─── Beállítások oldal (WordPress admin → CRM → Beállítások) ─── */

function hpv_video_admin_section() {
	$hook = get_option( 'hpv_video_webhook' );
	$rows = array(
		'Daily API kulcs (HPV_DAILY_API_KEY)' => hpv_video_enabled(),
		'AI kulcs (HPV_AI_API_KEY)'           => '' !== hpv_video_ai_key(),
		'Daily webhook'                       => ! empty( $hook['uuid'] ),
		'Ütemezett feldolgozás (WP-Cron)'     => (bool) wp_next_scheduled( 'hpv_video_cron' ),
	);
	?>
	<h2 class="title">Videóhívás (Daily.co)</h2>
	<p>A kulcsok a <code>wp-config.php</code>-ban vannak, nem itt. AI modell: <code><?php echo esc_html( hpv_video_ai_model() ); ?></code></p>
	<table class="widefat striped" style="max-width:640px">
		<tbody>
			<?php foreach ( $rows as $label => $ok ) : ?>
				<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo $ok ? '<span style="color:#1a7f37">✔ rendben</span>' : '<span style="color:#b32d2e">✘ hiányzik</span>'; ?></td></tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( hpv_video_enabled() ) : ?>
		<p style="display:flex;gap:8px">
			<?php foreach ( array( 'hpv_video_test' => 'Kapcsolat tesztelése', 'hpv_video_webhook' => 'Webhook regisztrálása' ) as $action => $label ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
					<?php wp_nonce_field( $action ); ?>
					<button class="button"><?php echo esc_html( $label ); ?></button>
				</form>
			<?php endforeach; ?>
		</p>
		<?php if ( ! empty( $hook['url'] ) ) : ?>
			<p class="description">Webhook címe: <code><?php echo esc_html( $hook['url'] ); ?></code></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php
}

add_action( 'admin_post_hpv_video_test', 'hpv_video_admin_action' );
add_action( 'admin_post_hpv_video_webhook', 'hpv_video_admin_action' );

function hpv_video_admin_action() {
	$action = sanitize_key( $_POST['action'] ?? '' );
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $action ) ) {
		wp_die( 'Nincs jogosultság.' );
	}
	$res  = 'hpv_video_webhook' === $action ? hpv_video_register_webhook() : hpv_video_daily( 'GET', '/' );
	$back = admin_url( 'admin.php?page=hpv-crm-settings' );
	if ( is_wp_error( $res ) ) {
		hpv_p_redirect( $back, 'error', array( 'error' => $res->get_error_message() ) );
	}
	hpv_p_redirect( $back, 'hpv_video_webhook' === $action ? 'webhook' : 'daily_ok' );
}

/* ─── Ügyfélportál (angol): Meetings ──────────────────────── */

/**
 * Az ügyfél hívásai: az élők, és a lezártak közül azok, amelyek összefoglalóját megosztottuk.
 */
function hpv_video_portal_calls( int $client_id ): array {
	return array_values(
		array_filter(
			hpv_p_find( 'call', array( 'client_id' => $client_id ), array( 'limit' => 100 ) ),
			fn( $c ) => 'live' === $c['status'] || ( ! empty( $c['shared'] ) && '' !== (string) $c['summary'] )
		)
	);
}

/**
 * A munkatárs a meghívó linkjéről a CRM hívás oldalára kerül (a portálon csak előnézetet látna).
 */
add_action( 'template_redirect', 'hpv_video_staff_redirect', 0 );

function hpv_video_staff_redirect() {
	if ( ! is_user_logged_in() || ! hpv_p_is_staff() || ! empty( $_GET['preview_client'] ) ) {
		return;
	}
	$view = sanitize_key( $_GET['view'] ?? '' );
	$id   = absint( $_GET['id'] ?? 0 );
	if ( $id && in_array( $view, array( 'call', 'meetings' ), true ) && hpv_p_get( 'call', $id ) ) {
		wp_redirect( hpv_p_crm_app_url( '/calls/' . $id ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- saját CRM aldomain
		exit;
	}
}

/**
 * Csatlakozás a portálról: hozzájárulás → token egy 5 perces, felhasználóhoz kötött tárolóba → vissza a hívás oldalra.
 * (A token nem kerül az URL-be és a böngésző előzményeibe.)
 */
add_action( 'init', 'hpv_video_portal_post', 20 );

function hpv_video_portal_post() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'join_call' !== ( $_POST['hpv_portal_action'] ?? '' ) || ! is_user_logged_in() ) {
		return;
	}
	$call_id = absint( $_POST['call_id'] ?? 0 );
	check_admin_referer( 'hpv_join_call_' . $call_id );
	$back = hpv_p_portal_url( array( 'view' => 'meetings', 'id' => $call_id ) );
	hpv_set_lang( hpv_doc_client_language( hpv_p_user_client_id( get_current_user_id() ) ) );

	if ( hpv_p_is_staff() ) {
		wp_redirect( hpv_p_crm_app_url( '/calls/' . $call_id ) ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
	if ( empty( $_POST['agree'] ) ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( hpv_t( 'Please confirm that you agree to the recording before joining.' ) ), $back ) );
		exit;
	}
	$join = hpv_video_join( $call_id, wp_get_current_user() );
	if ( is_wp_error( $join ) ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'not_live' === $join->get_error_code() ? hpv_t( 'This call has ended.' ) : hpv_t( 'We could not connect you to the call. Please try again or message us.' ) ), $back ) );
		exit;
	}
	set_transient( 'hpv_video_join_' . get_current_user_id() . '_' . $call_id, $join, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'joined', 1, $back ) );
	exit;
}

function hpv_pv_meetings( int $client_id ) {
	$calls = hpv_video_portal_calls( $client_id );
	hpv_p_portal_header( hpv_t( 'Meetings' ), hpv_t( 'Video calls with your team, with a written summary of what we agreed.' ) );
	if ( ! $calls ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'No meetings yet. When we start a video call with you, it appears here and you get an email with the link.' ) ) . '</p>';
		return;
	}
	echo '<div class="hpv-cards">';
	foreach ( $calls as $c ) {
		$live = 'live' === $c['status'];
		?>
		<a class="hpv-card-link<?php echo $live ? ' hpv-meeting--live' : ''; ?>" href="<?php echo esc_url( hpv_p_portal_link( 'meetings', array( 'id' => $c['id'] ) ) ); ?>">
			<span class="hpv-mini-project__top"><strong><?php echo esc_html( $c['title'] ); ?></strong><?php echo $live ? '<span class="hpv-pill hpv-pill--live">' . esc_html( hpv_t( 'Live now' ) ) . '</span>' : ''; ?></span>
			<small><?php echo esc_html( hpv_date( (string) $c['started_at'], 'datetime', true ) ); ?></small>
			<?php if ( ! $live ) : ?><span class="hpv-muted"><?php echo esc_html( wp_trim_words( (string) $c['summary'], 30 ) ); ?></span><?php endif; ?>
			<?php if ( $live ) : ?><span class="hpv-btn hpv-btn--small"><?php echo esc_html( hpv_t( 'Join call' ) ); ?></span><?php endif; ?>
		</a>
		<?php
	}
	echo '</div>';
}

function hpv_pv_meeting( int $client_id, int $id ) {
	$call = hpv_p_get( 'call', $id );
	if ( ! $call || (int) $call['client_id'] !== $client_id ) {
		hpv_p_portal_header( hpv_t( 'Meeting not found' ), '', hpv_p_portal_link( 'meetings' ) );
		return;
	}
	$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
	hpv_p_portal_header( $call['title'], hpv_date( (string) $call['started_at'], 'full', true ), hpv_p_portal_link( 'meetings' ) );
	if ( $error ) {
		echo '<div class="hpv-alert hpv-alert--error">' . esc_html( $error ) . '</div>';
	}

	if ( 'live' === $call['status'] ) {
		$key  = 'hpv_video_join_' . get_current_user_id() . '_' . $id;
		$join = ! empty( $_GET['joined'] ) ? get_transient( $key ) : false;
		if ( $join ) {
			delete_transient( $key );
			$config = array(
				'url'      => $join['url'],
				'token'    => $join['token'],
				'owner'    => false,
				'dailySrc' => plugins_url( 'assets/vendor/daily.js?ver=0.92.2', HPV_PORTAL_FILE ),
				'back'     => hpv_p_portal_link( 'meetings', array( 'id' => $id ) ),
			);
			?>
			<div class="hpv-call" id="hpv-call"><p class="hpv-muted"><?php echo esc_html( hpv_t( 'Connecting…' ) ); ?></p></div>
			<p class="hpv-muted hpv-call__note"><?php echo esc_html( hpv_t( 'This call is being recorded and transcribed. Use the Leave button in the call to hang up.' ) ); ?></p>
			<script src="<?php echo esc_url( plugins_url( 'assets/video.js', HPV_PORTAL_FILE ) . '?ver=' . HPV_PORTAL_VERSION ); ?>"></script>
			<script>
				(function (cfg) {
					var el = document.getElementById('hpv-call');
					el.innerHTML = '';
					window.HPVCallMount(el, Object.assign({}, cfg, {
						onLeft: function () { window.location.href = cfg.back; }
					})).catch(function () {
						el.innerHTML = '<p class="hpv-alert hpv-alert--error">' + <?php echo wp_json_encode( esc_html( hpv_t( 'We could not start the video. Please reload the page, or allow camera and microphone access.' ) ) ); ?> + '</p>';
					});
				})(<?php echo wp_json_encode( $config ); ?>);
			</script>
			<?php
			return;
		}
		if ( hpv_p_is_staff() ) {
			echo '<div class="hpv-alert">' . esc_html( hpv_t( 'Staff preview: the client joins here after agreeing to the recording.' ) ) . '</div>';
			return;
		}
		?>
		<form method="post" class="hpv-panel hpv-join">
			<h2><?php echo esc_html( hpv_t( 'Join the video call' ) ); ?></h2>
			<p><?php echo esc_html( hpv_t( 'We record and transcribe this call so we can send you a written summary of what we agreed. You can turn your camera off at any time.' ) ); ?></p>
			<input type="hidden" name="hpv_portal_action" value="join_call">
			<input type="hidden" name="call_id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'hpv_join_call_' . $id ); ?>
			<label class="hpv-check"><input type="checkbox" name="agree" value="1" required> <span><?php echo esc_html( hpv_t( 'I agree that this video call is recorded and transcribed, and that %s keeps the recording, the transcript and an AI-generated summary. (Florida law requires everyone\'s consent to record a conversation.)', hpv_p_settings()['company_name'] ) ); ?></span></label>
			<button class="hpv-btn"><?php echo esc_html( hpv_t( 'Join call' ) ); ?></button>
		</form>
		<?php
		return;
	}

	$ai = json_decode( (string) $call['ai_data'], true );
	if ( empty( $call['shared'] ) || '' === (string) $call['summary'] ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'This meeting has ended. A summary will appear here once your team shares it.' ) ) . '</p>';
		return;
	}
	$mine   = array_filter( (array) ( $ai['action_items'] ?? array() ), fn( $a ) => 'client' === ( $a['owner'] ?? '' ) );
	$theirs = array_filter( (array) ( $ai['action_items'] ?? array() ), fn( $a ) => 'client' !== ( $a['owner'] ?? '' ) );
	?>
	<section class="hpv-panel">
		<h2><?php echo esc_html( hpv_t( 'Summary' ) ); ?></h2>
		<p><?php echo nl2br( esc_html( (string) $call['summary'] ) ); ?></p>
		<?php if ( ! empty( $ai['decisions'] ) ) : ?>
			<h3><?php echo esc_html( hpv_t( 'What we agreed' ) ); ?></h3>
			<ul><?php foreach ( $ai['decisions'] as $d ) : ?><li><?php echo esc_html( $d ); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
	</section>
	<div class="hpv-columns">
		<section class="hpv-panel">
			<h2><?php echo esc_html( hpv_t( 'Your next steps' ) ); ?></h2>
			<?php if ( ! $mine ) : ?><p class="hpv-empty"><?php echo esc_html( hpv_t( 'Nothing for you to do.' ) ); ?></p><?php endif; ?>
			<ul class="hpv-todo"><?php foreach ( $mine as $a ) : ?><li><strong><?php echo esc_html( $a['title'] ); ?></strong><?php echo $a['due'] ? '<span>' . esc_html( hpv_t( 'Due %s', hpv_date( $a['due'], 'short' ) ) ) . '</span>' : ''; ?></li><?php endforeach; ?></ul>
		</section>
		<section class="hpv-panel">
			<h2><?php echo esc_html( hpv_t( "What we'll do" ) ); ?></h2>
			<?php if ( ! $theirs ) : ?><p class="hpv-empty"><?php echo esc_html( hpv_t( 'No follow-ups for our team.' ) ); ?></p><?php endif; ?>
			<ul class="hpv-todo"><?php foreach ( $theirs as $a ) : ?><li><strong><?php echo esc_html( $a['title'] ); ?></strong><?php echo $a['due'] ? '<span>' . esc_html( hpv_t( 'Due %s', hpv_date( $a['due'], 'short' ) ) ) . '</span>' : ''; ?></li><?php endforeach; ?></ul>
		</section>
	</div>
	<?php
}
