<?php
/**
 * Jóváhagyások: tartalom (blog, közösségi és Cégprofil poszt, hirdetés, hírlevél), oldal vagy dokumentum,
 * amit az ügyfél a portálon jóváhagy vagy javítást kér rá, megjegyzéssel. A csapat a CRM app Tartalom oldalán
 * (naptár + lista) kezeli.
 *
 * Folyamat: piszkozat → kiküldés (e-mail az ügyfélnek, a kapcsolt feladat „Ügyfélre vár”) → jóváhagyva
 * (feladat kész) vagy javítást kért (feladat vissza „Folyamatban”, új kör) → megjelent. 3 nap után egyszer emlékeztet.
 *
 * Más bővítménynek (SEO OS): hpv_approval_upsert() PHP-ból vagy POST /hpv/v1/content (source + external_ref:
 * ugyanarra a hivatkozásra frissít, nem duplikál), és a hpv_approval_decided action a döntésről.
 */

defined( 'ABSPATH' ) || exit;

const HPV_APPROVAL_REMIND_AFTER = 3 * DAY_IN_SECONDS;

function hpv_approval_files( array $a ): array {
	$ids = array_filter( array_map( 'absint', explode( ',', (string) $a['file_ids'] ) ) );
	$out = array();
	foreach ( $ids as $id ) {
		$f = hpv_p_get( 'file', $id );
		if ( $f && (int) $f['client_id'] === (int) $a['client_id'] ) {
			$out[] = $f;
		}
	}

	return $out;
}

function hpv_approval_format( array $a, bool $full = false ): array {
	$out = array(
		'id'           => (int) $a['id'],
		'client_id'    => (int) $a['client_id'],
		'client'       => hpv_p_client_name_safe( (int) $a['client_id'] ),
		'project_id'   => (int) $a['project_id'],
		'task_id'      => (int) $a['task_id'],
		'type'         => $a['type'],
		'title'        => $a['title'],
		'channel'      => (string) $a['channel'],
		'publish_date' => $a['publish_date'],
		'due_date'     => $a['due_date'],
		'status'       => $a['status'],
		'round'        => (int) $a['round'],
		'source'       => (string) $a['source'],
		'decided_at'   => $a['decided_at'],
		'decision_note' => (string) $a['decision_note'],
		'comments'     => count( hpv_p_find( 'approval_comment', array( 'approval_id' => (int) $a['id'] ), array( 'limit' => 500 ) ) ),
	);
	if ( ! $full ) {
		return $out;
	}

	return array_merge(
		$out,
		array(
			'body'       => (string) $a['body'],
			'link'       => (string) $a['link'],
			'files'      => array_map( 'hpv_files_format', hpv_approval_files( $a ) ),
			'thread'     => hpv_approval_thread( (int) $a['id'] ),
			'portal_url' => hpv_p_portal_url( array( 'view' => 'approvals', 'id' => (int) $a['id'], 'preview_client' => (int) $a['client_id'] ) ),
		)
	);
}

function hpv_approval_thread( int $id ): array {
	return array_map(
		fn( $c ) => array(
			'id'     => (int) $c['id'],
			'kind'   => $c['kind'] ?: 'comment',
			'body'   => $c['body'],
			'author' => hpv_chat_user_label( (int) $c['user_id'] )['name'],
			'client' => ! hpv_p_is_staff( (int) $c['user_id'] ),
			'at'     => (int) strtotime( $c['created_at'] . ' UTC' ),
		),
		hpv_p_find( 'approval_comment', array( 'approval_id' => $id ), array( 'orderby' => 'id', 'order' => 'ASC', 'limit' => 500 ) )
	);
}

function hpv_approval_comment( int $id, int $user_id, string $body, string $kind = 'comment' ): void {
	$body = trim( sanitize_textarea_field( $body ) );
	if ( '' === $body && 'comment' === $kind ) {
		return;
	}
	hpv_p_insert( 'approval_comment', array( 'approval_id' => $id, 'user_id' => $user_id, 'body' => $body ?: '—', 'kind' => $kind ) );
}

/**
 * Létrehozás vagy frissítés (a mezők ellenőrzésével). $source + $external_ref: más rendszerből (SEO OS) érkező anyag.
 *
 * @return array|WP_Error
 */
function hpv_approval_upsert( array $input, int $id = 0, int $user_id = 0 ) {
	$old = $id ? hpv_p_get( 'approval', $id ) : null;
	if ( ! $old && ! empty( $input['source'] ) && ! empty( $input['external_ref'] ) ) {
		$old = hpv_p_find( 'approval', array( 'source' => sanitize_key( $input['source'] ), 'external_ref' => sanitize_text_field( $input['external_ref'] ) ), array( 'limit' => 1 ) )[0] ?? null;
	}
	if ( $old && in_array( $old['status'], array( 'approved', 'published' ), true ) && isset( $input['body'] ) && hpv_doc_clean_html( (string) $input['body'] ) !== $old['body'] ) {
		return new WP_Error( 'locked', 'A jóváhagyott anyag tartalma nem módosítható. Készíts új kört: állítsd vissza piszkozatra.' );
	}
	$data = hpv_p_sanitize( 'approval', $input );
	unset( $data['status'] );
	if ( isset( $input['body'] ) ) {
		$data['body'] = hpv_doc_clean_html( (string) $input['body'] );
	}
	if ( isset( $input['file_ids'] ) ) {
		$data['file_ids'] = implode( ',', array_filter( array_map( 'absint', is_array( $input['file_ids'] ) ? $input['file_ids'] : explode( ',', (string) $input['file_ids'] ) ) ) );
	}
	$client_id = (int) ( $data['client_id'] ?? $old['client_id'] ?? 0 );
	if ( ! $client_id || ! hpv_p_get( 'client', $client_id ) ) {
		return new WP_Error( 'client', 'Válassz ügyfelet.' );
	}
	foreach ( array( 'project_id' => 'project', 'task_id' => 'task' ) as $key => $entity ) {
		if ( ! empty( $data[ $key ] ) ) {
			$row     = hpv_p_get( $entity, (int) $data[ $key ] );
			$row_cid = 'task' === $entity && $row ? (int) ( hpv_p_get( 'project', (int) $row['project_id'] )['client_id'] ?? 0 ) : (int) ( $row['client_id'] ?? 0 );
			if ( ! $row || $row_cid !== $client_id ) {
				return new WP_Error( $key, 'A projekt vagy feladat nem ehhez az ügyfélhez tartozik.' );
			}
		}
	}
	if ( ! $old && '' === trim( (string) ( $data['title'] ?? '' ) ) ) {
		return new WP_Error( 'title', 'Adj címet az anyagnak.' );
	}
	if ( ! $old ) {
		$data['source']       = sanitize_key( $input['source'] ?? 'crm' ) ?: 'crm';
		$data['external_ref'] = sanitize_text_field( (string) ( $input['external_ref'] ?? '' ) );
		$data['created_by']   = $user_id;
		$data['status']       = 'draft';
		$id                   = hpv_p_insert( 'approval', $data );
		if ( ! $id ) {
			return new WP_Error( 'db', 'Az adatbázisba írás nem sikerült.' );
		}
	} else {
		$id = (int) $old['id'];
		hpv_p_update( 'approval', $id, $data );
		// Módosított tartalom egy javítást kért anyagon: marad „javítást kért”, amíg újra ki nem küldik.
	}

	return hpv_p_get( 'approval', $id );
}

/**
 * Kiküldés jóváhagyásra (új kör is): e-mail az ügyfélnek, a kapcsolt feladat „Ügyfélre vár”.
 *
 * @return array|WP_Error
 */
function hpv_approval_send( int $id, int $user_id ) {
	$a = hpv_p_get( 'approval', $id );
	if ( ! $a || ! in_array( $a['status'], array( 'draft', 'changes', 'pending' ), true ) ) {
		return new WP_Error( 'status', 'Csak piszkozat vagy javított anyag küldhető jóváhagyásra.' );
	}
	if ( hpv_doc_todos( (string) $a['body'] ) ) {
		return new WP_Error( 'todo', 'Az anyagban még van [[TODO]] jelölés.' );
	}
	$resend = 'pending' === $a['status'];
	hpv_p_update(
		'approval',
		$id,
		array(
			'status'      => 'pending',
			'sent_at'     => current_time( 'mysql', true ),
			'reminded_at' => null,
			'round'       => $resend ? (int) $a['round'] : (int) $a['round'] + 1,
		)
	);
	$a = hpv_p_get( 'approval', $id );
	// A csatolt képeket és fájlokat az ügyfélnek látnia kell.
	foreach ( hpv_approval_files( $a ) as $file ) {
		if ( ! (int) $file['visible'] ) {
			hpv_p_update( 'file', (int) $file['id'], array( 'visible' => 1 ) );
		}
	}
	if ( ! $resend ) {
		hpv_approval_comment( $id, $user_id, '', 'sent' );
	}
	// A kapcsolt feladat „Ügyfélre vár” (közvetlenül, hogy ne menjen egy második levél is).
	hpv_approval_task_status( $a, 'client' );
	hpv_approval_email_client( $a, false );
	hpv_p_log_client( (int) $a['client_id'], 'Ready for your approval: %s', array( $a['title'] ), $user_id );

	return $a;
}

function hpv_approval_email_client( array $a, bool $reminder ): void {
	$client_id = (int) $a['client_id'];
	hpv_with_client_lang(
		$client_id,
		function () use ( $a, $client_id, $reminder ) {
			$type = hpv_t( hpv_p_option_label( 'approval', 'type', $a['type'], 'en' ) );
			hpv_p_notify_client(
				$client_id,
				( $reminder ? hpv_t( 'Reminder: ' ) : '' ) . hpv_t( 'Please review: %s', $a['title'] ),
				hpv_t( 'Ready for your approval' ),
				'<p>' . esc_html( $type ) . ': <strong>' . esc_html( $a['title'] ) . '</strong></p>'
				. ( $a['publish_date'] ? '<p>' . esc_html( hpv_t( 'Planned publish date: %s', hpv_date( $a['publish_date'], 'long' ) ) ) . '</p>' : '' )
				. '<p>' . esc_html( hpv_t( 'Approve it or request changes in your client portal. You can leave a comment either way.' ) ) . '</p>',
				hpv_t( 'Review now' ),
				hpv_p_portal_url( array( 'view' => 'approvals', 'id' => (int) $a['id'] ) )
			);
		}
	);
}

/**
 * Az ügyfél döntése.
 *
 * @return array|WP_Error
 */
function hpv_approval_decide( int $id, int $client_id, int $user_id, string $decision, string $note ) {
	$a = hpv_p_get( 'approval', $id );
	if ( ! $a || (int) $a['client_id'] !== $client_id || 'pending' !== $a['status'] ) {
		return new WP_Error( 'status', hpv_t( 'This item is no longer waiting for your approval.' ) );
	}
	$note = trim( sanitize_textarea_field( $note ) );
	if ( 'changes' === $decision && '' === $note ) {
		return new WP_Error( 'note', hpv_t( 'Please describe what should change.' ) );
	}
	$status = 'approve' === $decision ? 'approved' : 'changes';
	hpv_p_update(
		'approval',
		$id,
		array(
			'status'        => $status,
			'decided_at'    => current_time( 'mysql', true ),
			'decided_by'    => $user_id,
			'decision_note' => $note,
		)
	);
	hpv_approval_comment( $id, $user_id, $note, 'approved' === $status ? 'approved' : 'changes' );
	$a = hpv_p_get( 'approval', $id );

	hpv_approval_task_status( $a, 'approved' === $status ? 'done' : 'in_progress' );
	$who = hpv_chat_user_label( $user_id )['name'];
	hpv_p_log_client( $client_id, 'approved' === $status ? '%s approved: %s' : '%s requested changes: %s', array( $who, $a['title'] ), $user_id );
	$crm = hpv_p_crm_app_url( '/content/' . $id );
	hpv_p_notify_staff(
		sprintf( '%s: %s — %s', 'approved' === $status ? 'Jóváhagyva' : 'Javítást kér', $a['title'], hpv_p_client_name_safe( $client_id ) ),
		'<p><strong>' . esc_html( $who ) . '</strong> ' . ( 'approved' === $status ? 'jóváhagyta' : 'javítást kér' ) . ': ' . esc_html( $a['title'] ) . '</p>' . ( $note ? '<p>' . nl2br( esc_html( $note ) ) . '</p>' : '' ),
		$crm
	);
	do_action( 'hpv_approval_decided', $a, $status, $note );

	return $a;
}

function hpv_approval_task_status( array $a, string $status ): void {
	if ( ! (int) $a['task_id'] || ! hpv_p_get( 'task', (int) $a['task_id'] ) ) {
		return;
	}
	hpv_p_update( 'task', (int) $a['task_id'], array( 'status' => $status, 'completed_at' => 'done' === $status ? current_time( 'mysql', true ) : null ) );
}

/**
 * Napi emlékeztető: a 3 napja függő anyagokról egyszer.
 */
add_action( 'hpv_retainer_cron', 'hpv_approval_reminders' );

function hpv_approval_reminders(): int {
	$sent = 0;
	foreach ( hpv_p_find( 'approval', array( 'status' => 'pending' ), array( 'limit' => 2000 ) ) as $a ) {
		if ( $a['reminded_at'] || ! $a['sent_at'] || strtotime( $a['sent_at'] . ' UTC' ) > time() - HPV_APPROVAL_REMIND_AFTER ) {
			continue;
		}
		hpv_approval_email_client( $a, true );
		hpv_p_update( 'approval', (int) $a['id'], array( 'reminded_at' => current_time( 'mysql', true ) ) );
		$sent++;
	}

	return $sent;
}

/* ─── REST (munkatársak) ──────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_approval_routes' );

function hpv_approval_routes() {
	$staff = fn() => hpv_p_is_staff();
	$route = function ( string $path, string $methods, callable $cb ) use ( $staff ) {
		register_rest_route( 'hpv/v1', $path, array( 'methods' => $methods, 'permission_callback' => $staff, 'callback' => $cb ) );
	};
	$route( '/content', 'GET', 'hpv_approval_rest_list' );
	$route(
		'/content',
		'POST',
		function ( WP_REST_Request $r ) {
			$a = hpv_approval_upsert( $r->get_params(), 0, get_current_user_id() );
			return is_wp_error( $a ) ? new WP_Error( $a->get_error_code(), $a->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( hpv_approval_format( $a, true ) );
		}
	);
	$route(
		'/content/(?P<id>\d+)',
		'GET',
		function ( WP_REST_Request $r ) {
			$a = hpv_p_get( 'approval', (int) $r['id'] );
			return $a ? rest_ensure_response( hpv_approval_format( $a, true ) ) : new WP_Error( 'not_found', 'Nincs ilyen anyag.', array( 'status' => 404 ) );
		}
	);
	$route(
		'/content/(?P<id>\d+)',
		'POST',
		function ( WP_REST_Request $r ) {
			if ( ! hpv_p_get( 'approval', (int) $r['id'] ) ) {
				return new WP_Error( 'not_found', 'Nincs ilyen anyag.', array( 'status' => 404 ) );
			}
			$a = hpv_approval_upsert( $r->get_params(), (int) $r['id'], get_current_user_id() );
			return is_wp_error( $a ) ? new WP_Error( $a->get_error_code(), $a->get_error_message(), array( 'status' => 400 ) ) : rest_ensure_response( hpv_approval_format( $a, true ) );
		}
	);
	$route(
		'/content/(?P<id>\d+)',
		'DELETE',
		function ( WP_REST_Request $r ) {
			$a = hpv_p_get( 'approval', (int) $r['id'] );
			if ( ! $a ) {
				return new WP_Error( 'not_found', 'Nincs ilyen anyag.', array( 'status' => 404 ) );
			}
			if ( ! in_array( $a['status'], array( 'draft', 'cancelled' ), true ) ) {
				return new WP_Error( 'status', 'Csak piszkozat vagy elvetett anyag törölhető; a kiküldöttet vesd el.', array( 'status' => 400 ) );
			}
			hpv_p_delete( 'approval', (int) $a['id'] );
			return rest_ensure_response( array( 'deleted' => true ) );
		}
	);
	$route( '/content/(?P<id>\d+)/(?P<action>send|published|cancel|draft|comment)', 'POST', 'hpv_approval_rest_action' );
}

function hpv_approval_rest_list( WP_REST_Request $req ) {
	$where = array();
	if ( $req['client_id'] ) {
		$where['client_id'] = absint( $req['client_id'] );
	}
	if ( $req['project_id'] ) {
		$where['project_id'] = absint( $req['project_id'] );
	}
	if ( $req['status'] && isset( hpv_p_entity( 'approval' )['fields']['status']['options'][ $req['status'] ] ) ) {
		$where['status'] = $req['status'];
	}
	$rows  = hpv_p_find( 'approval', $where, array( 'limit' => 2000 ) );
	$month = preg_match( '/^\d{4}-\d{2}$/', (string) $req['month'] ) ? (string) $req['month'] : '';
	if ( $month ) {
		// A naptár: a hónapban megjelenő anyagok + a dátum nélküliek.
		$rows = array_filter( $rows, fn( $a ) => ! $a['publish_date'] || 0 === strpos( $a['publish_date'], $month ) );
	}
	usort( $rows, fn( $a, $b ) => strcmp( (string) ( $a['publish_date'] ?: '9999' ), (string) ( $b['publish_date'] ?: '9999' ) ) ?: $b['id'] <=> $a['id'] );
	$all = hpv_p_find( 'approval', array_diff_key( $where, array( 'status' => 1 ) ), array( 'limit' => 5000 ) );

	return rest_ensure_response(
		array(
			'items'  => array_values( array_map( 'hpv_approval_format', $rows ) ),
			'counts' => array_count_values( array_map( fn( $a ) => $a['status'], $all ) ),
			'types'  => array_map( fn( $k, $v ) => array( 'key' => $k, 'label' => $v[0] ), array_keys( hpv_p_entity( 'approval' )['fields']['type']['options'] ), hpv_p_entity( 'approval' )['fields']['type']['options'] ),
		)
	);
}

function hpv_approval_rest_action( WP_REST_Request $req ) {
	$id = (int) $req['id'];
	$a  = hpv_p_get( 'approval', $id );
	if ( ! $a ) {
		return new WP_Error( 'not_found', 'Nincs ilyen anyag.', array( 'status' => 404 ) );
	}
	$user = get_current_user_id();
	switch ( $req['action'] ) {
		case 'send':
			$res = hpv_approval_send( $id, $user );
			break;
		case 'published':
			if ( 'approved' !== $a['status'] ) {
				return new WP_Error( 'status', 'Csak jóváhagyott anyag jelölhető megjelentnek.', array( 'status' => 400 ) );
			}
			hpv_p_update( 'approval', $id, array( 'status' => 'published', 'publish_date' => $a['publish_date'] ?: current_time( 'Y-m-d' ) ) );
			$res = true;
			break;
		case 'cancel':
			hpv_p_update( 'approval', $id, array( 'status' => 'cancelled' ) );
			$res = true;
			break;
		case 'draft':
			// Új kör egy jóváhagyott vagy elvetett anyagon (pl. módosult a terv).
			hpv_p_update( 'approval', $id, array( 'status' => 'draft' ) );
			$res = true;
			break;
		default:
			hpv_approval_comment( $id, $user, (string) $req['body'] );
			$res = true;
	}
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( hpv_approval_format( hpv_p_get( 'approval', $id ), true ) );
}

/* ─── Portál ──────────────────────────────────────────────── */

function hpv_approval_portal_list( int $client_id ): array {
	$rows = array_filter( hpv_p_find( 'approval', array( 'client_id' => $client_id ), array( 'limit' => 1000 ) ), fn( $a ) => ! in_array( $a['status'], array( 'draft', 'cancelled' ), true ) );
	usort( $rows, fn( $a, $b ) => ( 'pending' === $b['status'] ) <=> ( 'pending' === $a['status'] ) ?: strcmp( (string) $b['sent_at'], (string) $a['sent_at'] ) );

	return array_values( $rows );
}

add_action( 'init', 'hpv_approval_portal_post', 20 );

function hpv_approval_portal_post() {
	$action = sanitize_key( $_POST['hpv_portal_action'] ?? '' );
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! in_array( $action, array( 'approval_decide', 'approval_comment' ), true ) || ! is_user_logged_in() ) {
		return;
	}
	$id = absint( $_POST['approval_id'] ?? 0 );
	check_admin_referer( 'hpv_approval_' . $id );
	$back      = hpv_p_portal_url( array( 'view' => 'approvals', 'id' => $id ) );
	$client_id = hpv_p_user_client_id( get_current_user_id() );
	if ( hpv_p_is_staff() || ! $client_id ) {
		wp_safe_redirect( $back );
		exit;
	}
	hpv_set_lang( hpv_doc_client_language( $client_id ) );
	$note = wp_unslash( (string) ( $_POST['note'] ?? '' ) );

	if ( 'approval_comment' === $action ) {
		$a = hpv_p_get( 'approval', $id );
		if ( $a && (int) $a['client_id'] === $client_id && ! in_array( $a['status'], array( 'draft', 'cancelled' ), true ) && '' !== trim( $note ) ) {
			hpv_approval_comment( $id, get_current_user_id(), $note );
			hpv_p_notify_staff( sprintf( 'Hozzászólás: %s — %s', $a['title'], hpv_p_client_name_safe( $client_id ) ), '<p>' . nl2br( esc_html( $note ) ) . '</p>', hpv_p_crm_app_url( '/content/' . $id ) );
		}
		wp_safe_redirect( add_query_arg( 'commented', 1, $back ) );
		exit;
	}
	$res = hpv_approval_decide( $id, $client_id, get_current_user_id(), 'approve' === ( $_POST['decision'] ?? '' ) ? 'approve' : 'changes', $note );
	if ( is_wp_error( $res ) ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( $res->get_error_message() ), $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( 'decided', $res['status'], $back ) );
	exit;
}

function hpv_approval_pill( string $status ): string {
	$class = array( 'pending' => 'client', 'changes' => 'overdue', 'approved' => 'paid', 'published' => 'paid' )[ $status ] ?? 'void';

	return '<span class="hpv-pill hpv-pill--' . esc_attr( $class ) . '">' . esc_html( hpv_t( hpv_p_option_label( 'approval', 'status', $status, 'en' ) ) ) . '</span>';
}

function hpv_pv_approvals( int $client_id, int $id ) {
	if ( $id ) {
		hpv_pv_approval( $client_id, $id );
		return;
	}
	$list = hpv_approval_portal_list( $client_id );
	hpv_p_portal_header( hpv_t( 'Approvals' ), hpv_t( 'Content and documents waiting for your OK, and what you approved before.' ) );
	if ( ! $list ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'Nothing to approve yet.' ) ) . '</p>';
		return;
	}
	echo '<div class="hpv-cards">';
	foreach ( $list as $a ) {
		?>
		<a class="hpv-card-link<?php echo 'pending' === $a['status'] ? ' hpv-card-link--attention' : ''; ?>" href="<?php echo esc_url( hpv_p_portal_link( 'approvals', array( 'id' => $a['id'] ) ) ); ?>">
			<span class="hpv-mini-project__top"><strong><?php echo esc_html( $a['title'] ); ?></strong><?php echo hpv_approval_pill( $a['status'] ); // phpcs:ignore ?></span>
			<small><?php echo esc_html( hpv_t( hpv_p_option_label( 'approval', 'type', $a['type'], 'en' ) ) . ( $a['channel'] ? ' · ' . $a['channel'] : '' ) . ( $a['publish_date'] ? ' · ' . hpv_date( $a['publish_date'] ) : '' ) ); ?></small>
		</a>
		<?php
	}
	echo '</div>';
}

function hpv_pv_approval( int $client_id, int $id ) {
	$a = hpv_p_get( 'approval', $id );
	if ( ! $a || (int) $a['client_id'] !== $client_id || in_array( $a['status'], array( 'draft', 'cancelled' ), true ) ) {
		hpv_p_portal_header( hpv_t( 'Not found' ), '', hpv_p_portal_link( 'approvals' ) );
		return;
	}
	$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
	$type  = hpv_t( hpv_p_option_label( 'approval', 'type', $a['type'], 'en' ) );
	hpv_p_portal_header( $a['title'], $type . ( $a['channel'] ? ' · ' . $a['channel'] : '' ) . ( $a['publish_date'] ? ' · ' . hpv_t( 'Planned publish date: %s', hpv_date( $a['publish_date'] ) ) : '' ), hpv_p_portal_link( 'approvals' ) );
	if ( ! empty( $_GET['decided'] ) ) {
		echo '<div class="hpv-alert hpv-alert--ok">' . esc_html( 'approved' === $_GET['decided'] ? hpv_t( 'Thank you — approved. We will take it from here.' ) : hpv_t( 'Thank you — we got your notes and will send a new version.' ) ) . '</div>';
	}
	if ( $error ) {
		echo '<div class="hpv-alert hpv-alert--error">' . esc_html( $error ) . '</div>';
	}
	echo '<p>' . hpv_approval_pill( $a['status'] ) . ( (int) $a['round'] > 1 ? ' <small class="hpv-muted">' . esc_html( hpv_t( 'Version %d', (int) $a['round'] ) ) . '</small>' : '' ) . '</p>'; // phpcs:ignore
	?>
	<article class="hpv-paper hpv-approval">
		<?php if ( $a['link'] ) : ?>
			<p><a class="hpv-btn hpv-btn--ghost" href="<?php echo esc_url( $a['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( hpv_t( 'Open preview' ) ); ?> ↗</a></p>
		<?php endif; ?>
		<?php foreach ( hpv_approval_files( $a ) as $f ) : ?>
			<?php if ( 0 === strpos( (string) $f['mime'], 'image/' ) ) : ?>
				<figure class="hpv-approval__img"><img src="<?php echo esc_url( hpv_files_url( $f ) ); ?>" alt="<?php echo esc_attr( $f['name'] ); ?>" loading="lazy"></figure>
			<?php else : ?>
				<p><a href="<?php echo esc_url( hpv_files_url( $f ) ); ?>" target="_blank" rel="noopener">📎 <?php echo esc_html( $f['name'] ); ?></a></p>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ( '' !== trim( wp_strip_all_tags( (string) $a['body'] ) ) ) : ?>
			<div class="hpv-contract__body"><?php echo wp_kses_post( $a['body'] ); ?></div>
		<?php endif; ?>
	</article>

	<?php if ( 'pending' === $a['status'] && ! hpv_p_is_staff() ) : ?>
		<form method="post" class="hpv-panel hpv-decide">
			<h2><?php echo esc_html( hpv_t( 'Your decision' ) ); ?></h2>
			<input type="hidden" name="hpv_portal_action" value="approval_decide">
			<input type="hidden" name="approval_id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'hpv_approval_' . $id ); ?>
			<label class="hpv-field"><span><?php echo esc_html( hpv_t( 'Comment (required if you request changes)' ) ); ?></span><textarea name="note" rows="4"></textarea></label>
			<div class="hpv-decide__actions">
				<button class="hpv-btn" name="decision" value="approve"><?php echo esc_html( hpv_t( 'Approve' ) ); ?></button>
				<button class="hpv-btn hpv-btn--ghost" name="decision" value="changes"><?php echo esc_html( hpv_t( 'Request changes' ) ); ?></button>
			</div>
		</form>
	<?php elseif ( 'pending' === $a['status'] ) : ?>
		<div class="hpv-alert"><?php echo esc_html( hpv_t( 'Staff preview: the client decides here.' ) ); ?></div>
	<?php endif; ?>

	<section class="hpv-panel">
		<h2><?php echo esc_html( hpv_t( 'History and comments' ) ); ?></h2>
		<ol class="hpv-thread">
			<?php foreach ( hpv_approval_thread( $id ) as $c ) : ?>
				<li class="hpv-thread__<?php echo esc_attr( $c['kind'] ); ?>">
					<strong><?php echo esc_html( $c['client'] ? $c['author'] : hpv_p_settings()['company_name'] . ' · ' . $c['author'] ); ?></strong>
					<span class="hpv-muted"><?php echo esc_html( hpv_date( gmdate( 'Y-m-d H:i:s', $c['at'] ), 'datetime', true ) ); ?></span>
					<p><?php echo esc_html( array( 'sent' => hpv_t( 'Sent for approval.' ), 'approved' => hpv_t( 'Approved.' ), 'changes' => hpv_t( 'Requested changes.' ) )[ $c['kind'] ] ?? '' ); ?> <?php echo '—' !== $c['body'] ? nl2br( esc_html( $c['body'] ) ) : ''; ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php if ( ! hpv_p_is_staff() ) : ?>
			<form method="post" class="hpv-comment-form">
				<input type="hidden" name="hpv_portal_action" value="approval_comment">
				<input type="hidden" name="approval_id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'hpv_approval_' . $id ); ?>
				<textarea name="note" rows="2" required placeholder="<?php echo esc_attr( hpv_t( 'Add a comment or question…' ) ); ?>"></textarea>
				<button class="hpv-btn hpv-btn--ghost"><?php echo esc_html( hpv_t( 'Send' ) ); ?></button>
			</form>
		<?php endif; ?>
	</section>
	<?php
}
