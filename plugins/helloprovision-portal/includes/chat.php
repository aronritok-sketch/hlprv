<?php
/**
 * Chat: csatornák (belső csoportok és ügyfél-csatornák), tagság, üzenetek, REST API.
 * A böngésző néhány másodpercenként lekérdezi az új üzeneteket (külön valós idejű szerver nélkül).
 */

defined( 'ABSPATH' ) || exit;

const HPV_CHAT_NS           = 'hpv/v1';
const HPV_CHAT_EMAIL_AFTER  = 120;  // ennyi mp inaktivitás után kap e-mailt a címzett
const HPV_CHAT_EMAIL_THROTTLE = 900; // legfeljebb 15 percenként egy e-mail csatornánként

/* ─── Tagság ──────────────────────────────────────────────── */

function hpv_chat_member( int $channel_id, int $user_id ): ?array {
	$rows = hpv_p_find( 'channel_member', array( 'channel_id' => $channel_id, 'user_id' => $user_id ), array( 'limit' => 1 ) );

	return $rows[0] ?? null;
}

function hpv_chat_last_id( int $channel_id ): int {
	$rows = hpv_p_find( 'chat_message', array( 'channel_id' => $channel_id ), array( 'limit' => 1 ) );

	return (int) ( $rows[0]['id'] ?? 0 );
}

function hpv_chat_add_member( int $channel_id, int $user_id ): void {
	if ( ! $user_id || hpv_chat_member( $channel_id, $user_id ) ) {
		return;
	}
	// Az új tagnak a korábbi üzenetek nem számítanak olvasatlannak.
	hpv_p_insert(
		'channel_member',
		array(
			'channel_id' => $channel_id,
			'user_id'    => $user_id,
			'last_read'  => hpv_chat_last_id( $channel_id ),
		)
	);
}

function hpv_chat_remove_member( int $channel_id, int $user_id ): void {
	$member = hpv_chat_member( $channel_id, $user_id );
	if ( $member ) {
		hpv_p_delete( 'channel_member', (int) $member['id'] );
	}
}

/**
 * Olvashatja-e a felhasználó a csatornát: tag, vagy adminisztrátor (ő minden csatornát lát).
 * Ügyfél-felhasználó csak a saját cégének csatornáját olvashatja, akkor is csak tagként.
 */
function hpv_chat_can_read( array $channel, int $user_id ): bool {
	if ( hpv_chat_member( (int) $channel['id'], $user_id ) ) {
		if ( 'client' === $channel['type'] && ! hpv_p_is_staff( $user_id ) ) {
			return hpv_p_user_client_id( $user_id ) === (int) $channel['client_id'];
		}
		return true;
	}

	return user_can( $user_id, 'manage_options' );
}

function hpv_chat_can_write( array $channel, int $user_id ): bool {
	return ! $channel['archived'] && hpv_chat_member( (int) $channel['id'], $user_id ) && hpv_chat_can_read( $channel, $user_id );
}

/**
 * Az ügyfél csatornája (ha kell, létrehozza a portál-felhasználókkal és a létrehozó munkatárssal).
 */
function hpv_chat_client_channel( int $client_id, bool $create = true ): ?array {
	$rows = hpv_p_find( 'channel', array( 'client_id' => $client_id, 'type' => 'client', 'archived' => 0 ), array( 'orderby' => 'id', 'order' => 'ASC', 'limit' => 1 ) );
	if ( $rows || ! $create ) {
		return $rows[0] ?? null;
	}

	$client = hpv_p_get( 'client', $client_id );
	if ( ! $client ) {
		return null;
	}
	$id = hpv_p_insert(
		'channel',
		array(
			'name'       => $client['name'],
			'type'       => 'client',
			'client_id'  => $client_id,
			'created_by' => get_current_user_id(),
		)
	);
	foreach ( hpv_p_client_users( $client_id ) as $user ) {
		hpv_chat_add_member( $id, $user->ID );
	}
	if ( hpv_p_is_staff() ) {
		hpv_chat_add_member( $id, get_current_user_id() );
	}

	return hpv_p_get( 'channel', $id );
}

/* ─── Lekérdezések ────────────────────────────────────────── */

function hpv_chat_unread( int $channel_id, int $last_read, int $user_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . hpv_p_table( 'chat_message' ) . ' WHERE channel_id = %d AND id > %d AND user_id <> %d', $channel_id, $last_read, $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}

function hpv_chat_user_label( int $user_id ): array {
	static $cache = array();
	if ( ! isset( $cache[ $user_id ] ) ) {
		$user  = get_userdata( $user_id );
		$name  = $user ? ( $user->display_name ?: $user->user_login ) : 'Deleted user';
		$parts = preg_split( '/\s+/', trim( $name ) );
		$cache[ $user_id ] = array(
			'name'     => $name,
			'initials' => strtoupper( mb_substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? mb_substr( $parts[1], 0, 1 ) : '' ) ),
			'is_staff' => $user ? user_can( $user, 'hpv_manage_crm' ) : false,
			'company'  => $user && ! user_can( $user, 'hpv_manage_crm' ) ? hpv_p_client_name_safe( hpv_p_user_client_id( $user_id ) ) : '',
		);
	}

	return $cache[ $user_id ];
}

function hpv_p_client_name_safe( int $client_id ): string {
	$client = $client_id ? hpv_p_get( 'client', $client_id ) : null;

	return $client ? $client['name'] : '';
}

function hpv_chat_format_message( array $m, int $viewer ): array {
	$author = hpv_chat_user_label( (int) $m['user_id'] );

	return array(
		'id'       => (int) $m['id'],
		'user_id'  => (int) $m['user_id'],
		'name'     => $author['name'],
		'initials' => $author['initials'],
		'is_staff' => $author['is_staff'],
		'company'  => $author['company'],
		'mine'     => (int) $m['user_id'] === $viewer,
		'body'     => $m['body'],
		'time'     => mysql2date( 'c', $m['created_at'] . ' +0000', false ),
	);
}

function hpv_chat_format_channel( array $c, int $viewer ): array {
	$member = hpv_chat_member( (int) $c['id'], $viewer );
	$last   = hpv_p_find( 'chat_message', array( 'channel_id' => $c['id'] ), array( 'limit' => 1 ) );
	$count  = count( hpv_p_find( 'channel_member', array( 'channel_id' => $c['id'] ), array( 'limit' => 500 ) ) );

	return array(
		'id'          => (int) $c['id'],
		'name'        => $c['name'],
		'description' => $c['description'],
		'type'        => $c['type'],
		'client_id'   => (int) $c['client_id'],
		'client'      => $c['client_id'] ? hpv_p_client_name_safe( (int) $c['client_id'] ) : '',
		'archived'    => (bool) $c['archived'],
		'member'      => (bool) $member,
		'members'     => $count,
		'unread'      => $member ? hpv_chat_unread( (int) $c['id'], (int) $member['last_read'], $viewer ) : 0,
		'last'        => $last ? array(
			'id'   => (int) $last[0]['id'],
			'name' => hpv_chat_user_label( (int) $last[0]['user_id'] )['name'],
			'body' => mb_substr( $last[0]['body'], 0, 120 ),
			'time' => mysql2date( 'c', $last[0]['created_at'] . ' +0000', false ),
		) : null,
	);
}

/**
 * A felhasználó csatornái (és munkatársnál a csatlakozható belső csoportok).
 */
function hpv_chat_channels_for( int $user_id ): array {
	$ids      = array_map( fn( $m ) => (int) $m['channel_id'], hpv_p_find( 'channel_member', array( 'user_id' => $user_id ), array( 'limit' => 1000 ) ) );
	$channels = $ids ? hpv_p_find( 'channel', array( 'id' => $ids, 'archived' => 0 ), array( 'limit' => 1000 ) ) : array();
	$channels = array_values( array_filter( $channels, fn( $c ) => hpv_chat_can_read( $c, $user_id ) ) );

	$joinable = array();
	if ( hpv_p_is_staff( $user_id ) ) {
		foreach ( hpv_p_find( 'channel', array( 'archived' => 0 ), array( 'limit' => 1000 ) ) as $c ) {
			if ( ! in_array( (int) $c['id'], $ids, true ) ) {
				$joinable[] = $c;
			}
		}
	}

	return array(
		'channels' => $channels,
		'joinable' => $joinable,
	);
}

function hpv_chat_total_unread( int $user_id ): int {
	$total = 0;
	foreach ( hpv_chat_channels_for( $user_id )['channels'] as $c ) {
		$member = hpv_chat_member( (int) $c['id'], $user_id );
		$total += $member ? hpv_chat_unread( (int) $c['id'], (int) $member['last_read'], $user_id ) : 0;
	}

	return $total;
}

/* ─── Üzenet küldése, értesítés ───────────────────────────── */

/**
 * @return array|WP_Error az új üzenet
 */
function hpv_chat_post( int $channel_id, int $user_id, string $body ) {
	$channel = hpv_p_get( 'channel', $channel_id );
	$body    = trim( sanitize_textarea_field( $body ) );
	if ( ! $channel || ! hpv_chat_can_write( $channel, $user_id ) ) {
		return new WP_Error( 'forbidden', 'You cannot post in this conversation.', array( 'status' => 403 ) );
	}
	if ( '' === $body ) {
		return new WP_Error( 'empty', 'Message is empty.', array( 'status' => 400 ) );
	}
	if ( mb_strlen( $body ) > 5000 ) {
		return new WP_Error( 'long', 'Message is too long (max 5,000 characters).', array( 'status' => 400 ) );
	}

	$rate = 'hpv_chat_rate_' . $user_id;
	$sent = (int) get_transient( $rate );
	if ( $sent >= 30 ) {
		return new WP_Error( 'rate', 'You are sending messages too quickly. Please wait a moment.', array( 'status' => 429 ) );
	}
	set_transient( $rate, $sent + 1, MINUTE_IN_SECONDS );

	$id = hpv_p_insert(
		'chat_message',
		array(
			'channel_id' => $channel_id,
			'user_id'    => $user_id,
			'body'       => $body,
		)
	);
	hpv_chat_mark_read( $channel_id, $user_id, $id );
	hpv_chat_notify( $channel, $id, $user_id, $body );

	return hpv_p_get( 'chat_message', $id );
}

function hpv_chat_mark_read( int $channel_id, int $user_id, int $message_id ): void {
	$member = hpv_chat_member( $channel_id, $user_id );
	if ( ! $member ) {
		return;
	}
	$data = array( 'seen_at' => time() );
	if ( $message_id > (int) $member['last_read'] ) {
		$data['last_read'] = $message_id;
	}
	hpv_p_update( 'channel_member', (int) $member['id'], $data );
}

/**
 * E-mail azoknak a tagoknak, akik éppen nem nézik a csatornát (legfeljebb 15 percenként egyszer).
 */
function hpv_chat_notify( array $channel, int $message_id, int $author_id, string $body ): void {
	$author = hpv_chat_user_label( $author_id );
	$now    = time();

	foreach ( hpv_p_find( 'channel_member', array( 'channel_id' => $channel['id'] ), array( 'limit' => 500 ) ) as $member ) {
		$user_id = (int) $member['user_id'];
		if ( $user_id === $author_id || $now - (int) $member['seen_at'] < HPV_CHAT_EMAIL_AFTER || $now - (int) $member['emailed_at'] < HPV_CHAT_EMAIL_THROTTLE ) {
			continue;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			continue;
		}

		if ( user_can( $user, 'hpv_manage_crm' ) ) {
			$sent = hpv_p_send(
				$user->user_email,
				sprintf( 'Új üzenet: %s', $channel['name'] ),
				hpv_p_email_html(
					sprintf( '%s írt: %s', $author['name'], $channel['name'] ),
					'<p>' . nl2br( esc_html( mb_substr( $body, 0, 1000 ) ) ) . '</p>',
					'Válasz a CRM-ben',
					hpv_p_crm_url( array( 'page' => 'hpv-crm-chat', 'channel' => $channel['id'] ) )
				)
			);
		} else {
			$sent = hpv_p_send(
				$user->user_email,
				sprintf( 'New message from %s', $author['name'] ),
				hpv_p_email_html(
					sprintf( '%s sent you a message', $author['name'] ),
					'<p>' . nl2br( esc_html( mb_substr( $body, 0, 1000 ) ) ) . '</p>',
					'Reply in your portal',
					hpv_p_portal_url( array( 'view' => 'messages', 'channel' => $channel['id'] ) )
				)
			);
		}
		if ( $sent ) {
			hpv_p_update( 'channel_member', (int) $member['id'], array( 'emailed_at' => $now ) );
		}
	}
}

/* ─── REST API ────────────────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_chat_routes' );

function hpv_chat_routes() {
	$logged_in = fn() => is_user_logged_in();
	$staff     = fn() => hpv_p_is_staff();

	register_rest_route(
		HPV_CHAT_NS,
		'/chat/channels',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'hpv_chat_rest_channels',
				'permission_callback' => $logged_in,
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'hpv_chat_rest_create_channel',
				'permission_callback' => $staff,
			),
		)
	);
	register_rest_route(
		HPV_CHAT_NS,
		'/chat/channels/(?P<id>\d+)/messages',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'hpv_chat_rest_messages',
				'permission_callback' => $logged_in,
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'hpv_chat_rest_post',
				'permission_callback' => $logged_in,
			),
		)
	);
	register_rest_route(
		HPV_CHAT_NS,
		'/chat/channels/(?P<id>\d+)/members',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'hpv_chat_rest_members',
				'permission_callback' => $logged_in,
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'hpv_chat_rest_update_members',
				'permission_callback' => $staff,
			),
		)
	);
	register_rest_route(
		HPV_CHAT_NS,
		'/chat/channels/(?P<id>\d+)',
		array(
			'methods'             => 'POST',
			'callback'            => 'hpv_chat_rest_update_channel',
			'permission_callback' => $staff,
		)
	);
	register_rest_route(
		HPV_CHAT_NS,
		'/chat/users',
		array(
			'methods'             => 'GET',
			'callback'            => 'hpv_chat_rest_users',
			'permission_callback' => $staff,
		)
	);
	register_rest_route(
		HPV_CHAT_NS,
		'/chat/unread',
		array(
			'methods'             => 'GET',
			'callback'            => fn() => rest_ensure_response( array( 'total' => hpv_chat_total_unread( get_current_user_id() ) ) ),
			'permission_callback' => $logged_in,
		)
	);
}

function hpv_chat_rest_channels() {
	$me   = get_current_user_id();
	$list = hpv_chat_channels_for( $me );

	return rest_ensure_response(
		array(
			'channels' => array_map( fn( $c ) => hpv_chat_format_channel( $c, $me ), $list['channels'] ),
			'joinable' => array_map( fn( $c ) => hpv_chat_format_channel( $c, $me ), $list['joinable'] ),
		)
	);
}

function hpv_chat_rest_create_channel( WP_REST_Request $request ) {
	$name      = sanitize_text_field( (string) $request->get_param( 'name' ) );
	$client_id = absint( $request->get_param( 'client_id' ) );
	if ( '' === $name && ! $client_id ) {
		return new WP_Error( 'name', 'Adj nevet a csatornának.', array( 'status' => 400 ) );
	}
	if ( $client_id && ! hpv_p_get( 'client', $client_id ) ) {
		return new WP_Error( 'client', 'Az ügyfél nem található.', array( 'status' => 400 ) );
	}

	$id = hpv_p_insert(
		'channel',
		array(
			'name'        => '' !== $name ? $name : hpv_p_client_name_safe( $client_id ),
			'description' => sanitize_text_field( (string) $request->get_param( 'description' ) ),
			'type'        => $client_id ? 'client' : 'internal',
			'client_id'   => $client_id,
			'created_by'  => get_current_user_id(),
		)
	);
	hpv_chat_add_member( $id, get_current_user_id() );
	hpv_chat_apply_members( $id, array_map( 'absint', (array) $request->get_param( 'members' ) ), array() );
	if ( $client_id ) {
		foreach ( hpv_p_client_users( $client_id ) as $user ) {
			hpv_chat_add_member( $id, $user->ID );
		}
	}

	return rest_ensure_response( hpv_chat_format_channel( hpv_p_get( 'channel', $id ), get_current_user_id() ) );
}

/**
 * Tagok hozzáadása / eltávolítása. Ügyfél-felhasználó csak a csatorna saját ügyfeléhez tartozó lehet.
 */
function hpv_chat_apply_members( int $channel_id, array $add, array $remove ): void {
	$channel = hpv_p_get( 'channel', $channel_id );
	foreach ( array_filter( $add ) as $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			continue;
		}
		$is_staff = user_can( $user, 'hpv_manage_crm' );
		if ( ! $is_staff && ( 'client' !== $channel['type'] || hpv_p_user_client_id( $user_id ) !== (int) $channel['client_id'] ) ) {
			continue;
		}
		hpv_chat_add_member( $channel_id, $user_id );
	}
	foreach ( array_filter( $remove ) as $user_id ) {
		hpv_chat_remove_member( $channel_id, (int) $user_id );
	}
}

function hpv_chat_rest_update_members( WP_REST_Request $request ) {
	$id      = (int) $request['id'];
	$channel = hpv_p_get( 'channel', $id );
	if ( ! $channel ) {
		return new WP_Error( 'not_found', 'A csatorna nem található.', array( 'status' => 404 ) );
	}
	if ( $request->get_param( 'join' ) ) {
		hpv_chat_add_member( $id, get_current_user_id() );
	}
	if ( $request->get_param( 'leave' ) ) {
		hpv_chat_remove_member( $id, get_current_user_id() );
	}
	hpv_chat_apply_members( $id, array_map( 'absint', (array) $request->get_param( 'add' ) ), array_map( 'absint', (array) $request->get_param( 'remove' ) ) );

	return hpv_chat_rest_members( $request );
}

function hpv_chat_rest_members( WP_REST_Request $request ) {
	$channel = hpv_p_get( 'channel', (int) $request['id'] );
	if ( ! $channel || ! hpv_chat_can_read( $channel, get_current_user_id() ) ) {
		return new WP_Error( 'forbidden', 'Not allowed.', array( 'status' => 403 ) );
	}
	$members = array();
	foreach ( hpv_p_find( 'channel_member', array( 'channel_id' => $channel['id'] ), array( 'limit' => 500, 'order' => 'ASC' ) ) as $m ) {
		$label     = hpv_chat_user_label( (int) $m['user_id'] );
		$members[] = array(
			'id'       => (int) $m['user_id'],
			'name'     => $label['name'],
			'initials' => $label['initials'],
			'is_staff' => $label['is_staff'],
			'company'  => $label['company'],
		);
	}

	return rest_ensure_response( $members );
}

function hpv_chat_rest_update_channel( WP_REST_Request $request ) {
	$channel = hpv_p_get( 'channel', (int) $request['id'] );
	if ( ! $channel ) {
		return new WP_Error( 'not_found', 'A csatorna nem található.', array( 'status' => 404 ) );
	}
	$data = array();
	if ( null !== $request->get_param( 'name' ) && '' !== trim( (string) $request->get_param( 'name' ) ) ) {
		$data['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
	}
	if ( null !== $request->get_param( 'description' ) ) {
		$data['description'] = sanitize_text_field( (string) $request->get_param( 'description' ) );
	}
	if ( null !== $request->get_param( 'archived' ) ) {
		$data['archived'] = $request->get_param( 'archived' ) ? 1 : 0;
	}
	if ( $data ) {
		hpv_p_update( 'channel', (int) $channel['id'], $data );
	}

	return rest_ensure_response( hpv_chat_format_channel( hpv_p_get( 'channel', (int) $channel['id'] ), get_current_user_id() ) );
}

function hpv_chat_rest_messages( WP_REST_Request $request ) {
	$me      = get_current_user_id();
	$channel = hpv_p_get( 'channel', (int) $request['id'] );
	if ( ! $channel || ! hpv_chat_can_read( $channel, $me ) ) {
		return new WP_Error( 'forbidden', 'You do not have access to this conversation.', array( 'status' => 403 ) );
	}

	$after  = absint( $request->get_param( 'after' ) );
	$before = absint( $request->get_param( 'before' ) );
	$args   = array( 'limit' => 50 );
	if ( $after ) {
		$args['after_id'] = $after;
		$args['order']    = 'ASC';
	} elseif ( $before ) {
		$args['before_id'] = $before;
	}
	$rows = hpv_p_find( 'chat_message', array( 'channel_id' => $channel['id'] ), $args );
	if ( ! $after ) {
		$rows = array_reverse( $rows ); // a legújabb 50, időrendben
	}

	$last = $rows ? (int) end( $rows )['id'] : 0;
	hpv_chat_mark_read( (int) $channel['id'], $me, $last );

	return rest_ensure_response(
		array(
			'messages' => array_map( fn( $m ) => hpv_chat_format_message( $m, $me ), $rows ),
			'more'     => ! $after && 50 === count( $rows ),
			'can_post' => hpv_chat_can_write( $channel, $me ),
		)
	);
}

function hpv_chat_rest_post( WP_REST_Request $request ) {
	$message = hpv_chat_post( (int) $request['id'], get_current_user_id(), (string) $request->get_param( 'body' ) );
	if ( is_wp_error( $message ) ) {
		return $message;
	}

	return rest_ensure_response( hpv_chat_format_message( $message, get_current_user_id() ) );
}

/**
 * Tagválasztóhoz: munkatársak és (ügyfél-csatornához) az ügyfél portál-felhasználói.
 */
function hpv_chat_rest_users( WP_REST_Request $request ) {
	$out = array();
	foreach ( get_users( array( 'capability' => 'hpv_manage_crm' ) ) as $user ) {
		$out[] = array(
			'id'       => $user->ID,
			'name'     => $user->display_name,
			'is_staff' => true,
			'company'  => '',
		);
	}
	$client_id = absint( $request->get_param( 'client_id' ) );
	if ( $client_id ) {
		foreach ( hpv_p_client_users( $client_id ) as $user ) {
			$out[] = array(
				'id'       => $user->ID,
				'name'     => $user->display_name,
				'is_staff' => false,
				'company'  => hpv_p_client_name_safe( $client_id ),
			);
		}
	}

	return rest_ensure_response( $out );
}

/* ─── Integráció a CRM-mel ────────────────────────────────── */

/**
 * Új portál-felhasználó automatikusan bekerül az ügyfél csatornájába; visszavonáskor kikerül.
 */
add_action( 'hpv_p_user_invited', 'hpv_chat_on_invite', 10, 2 );

function hpv_chat_on_invite( int $client_id, int $user_id ) {
	$channel = hpv_chat_client_channel( $client_id, true );
	if ( $channel ) {
		hpv_chat_add_member( (int) $channel['id'], $user_id );
	}
}

add_action( 'hpv_p_user_revoked', 'hpv_chat_on_revoke', 10, 2 );

function hpv_chat_on_revoke( int $client_id, int $user_id ) {
	foreach ( hpv_p_find( 'channel', array( 'client_id' => $client_id ) ) as $channel ) {
		hpv_chat_remove_member( (int) $channel['id'], $user_id );
	}
}

/**
 * A chat felület konfigurációja a JS-nek (CRM és portál is ezt használja).
 */
function hpv_chat_app_config( string $lang, int $channel = 0 ): array {
	$me = wp_get_current_user();

	return array(
		'api'      => esc_url_raw( rest_url( HPV_CHAT_NS . '/chat' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'me'       => array(
			'id'   => $me->ID,
			'name' => $me->display_name,
		),
		'isStaff'  => hpv_p_is_staff(),
		'lang'     => $lang,
		'channel'  => $channel,
		'clients'  => hpv_p_is_staff() ? array_map(
			fn( $c ) => array(
				'id'   => (int) $c['id'],
				'name' => $c['name'],
			),
			hpv_p_find( 'client', array(), array( 'orderby' => 'name', 'order' => 'ASC', 'limit' => 2000 ) )
		) : array(),
		'crmUrl'   => admin_url( 'admin.php?page=hpv-crm' ),
	);
}

function hpv_chat_enqueue( string $lang, int $channel = 0 ): void {
	$base = plugins_url( 'assets/', HPV_PORTAL_FILE );
	wp_enqueue_style( 'hpv-chat', $base . 'chat.css', array(), HPV_PORTAL_VERSION );
	wp_enqueue_script( 'hpv-chat', $base . 'chat.js', array(), HPV_PORTAL_VERSION, true );
	wp_add_inline_script( 'hpv-chat', 'window.HPV_CHAT = ' . wp_json_encode( hpv_chat_app_config( $lang, $channel ) ) . ';', 'before' );
}
