<?php
/**
 * Kapcsolat a CRM bővítménnyel (helloprovision-portal): ügyféllista a projektvarázslóhoz,
 * és a gyártási feladatok átküldése CRM feladatokká. Ha a CRM nincs bekapcsolva, a végpontok üres választ adnak.
 */

defined( 'ABSPATH' ) || exit;

function hpv_seo_crm_active(): bool {
	return function_exists( 'hpv_p_find' ) && function_exists( 'hpv_p_insert' );
}

add_action( 'rest_api_init', 'hpv_seo_crm_routes', 10 );

function hpv_seo_crm_routes() {
	register_rest_route(
		'hpv-seo/v1',
		'/crm/clients',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'hpv_seo_can_access',
			'callback'            => 'hpv_seo_crm_clients',
		)
	);
	register_rest_route(
		'hpv-seo/v1',
		'/crm/client-review',
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => in_array( hpv_seo_user_role(), array( 'admin', 'seo_manager' ), true ),
			'callback'            => 'hpv_seo_crm_client_review',
		)
	);
	register_rest_route(
		'hpv-seo/v1',
		'/crm/push-tasks',
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => in_array( hpv_seo_user_role(), array( 'admin', 'seo_manager' ), true ),
			'callback'            => 'hpv_seo_crm_push_tasks',
		)
	);
}

function hpv_seo_crm_clients() {
	if ( ! hpv_seo_crm_active() ) {
		return rest_ensure_response( array( 'active' => false, 'clients' => array() ) );
	}
	$rows = hpv_p_find( 'client', array(), array( 'limit' => 1000, 'orderby' => 'name', 'order' => 'ASC' ) );

	return rest_ensure_response(
		array(
			'active'  => true,
			'clients' => array_map(
				fn( $c ) => array(
					'id'      => (int) $c['id'],
					'name'    => (string) $c['name'],
					'website' => (string) ( $c['website'] ?? '' ),
					'status'  => (string) ( $c['status'] ?? '' ),
				),
				$rows
			),
		)
	);
}

/**
 * A projekt még át nem küldött gyártási feladatai → CRM feladatok egy „SEO – …” CRM projektben (az ügyfél nem látja).
 */
function hpv_seo_crm_push_tasks( WP_REST_Request $request ) {
	if ( ! hpv_seo_crm_active() ) {
		return new WP_Error( 'hpv_seo_crm', 'A CRM bővítmény nincs bekapcsolva.', array( 'status' => 409 ) );
	}
	$project_id = absint( $request->get_param( 'project' ) );
	$project    = hpv_seo_api_json( 'GET', 'projects/' . $project_id );
	if ( is_wp_error( $project ) ) {
		return $project;
	}
	$crm_client = (int) ( $project['client']['crm_client_id'] ?? 0 );
	if ( ! $crm_client || ! hpv_p_get( 'client', $crm_client ) ) {
		return new WP_Error( 'hpv_seo_crm', 'A projekt ügyfele nincs összekötve CRM ügyféllel (Áttekintés → Ügyfél).', array( 'status' => 409 ) );
	}
	$tasks = hpv_seo_api_json( 'GET', 'projects/' . $project_id . '/tasks?unpushed=1' );
	if ( is_wp_error( $tasks ) ) {
		return $tasks;
	}

	$crm_project = hpv_seo_crm_project( $crm_client, (string) $project['name'] );
	$crm_row     = hpv_p_get( 'project', $crm_project );
	$retainer    = $crm_row && ! empty( $crm_row['package_id'] ) && hpv_seo_crm_has_field( 'task', 'period' );
	$pushed      = array();
	foreach ( $tasks as $task ) {
		$assignee = (int) ( $task['assignee_wp_id'] ?? 0 );
		$row      = array(
			'project_id'  => $crm_project,
			'title'       => mb_substr( '[' . $task['role_label'] . '] ' . $task['title'], 0, 250 ),
			'description' => hpv_seo_crm_task_description( $task ),
			'status'      => 'todo',
			'priority'    => in_array( $task['priority'], array( 'P1', 'XL' ), true ) ? 'high' : 'normal',
			'visible'     => 0,
			'due_date'    => $task['due_date'] ?? null,
			// A CRM hivatkozás-mezői NOT NULL DEFAULT 0 oszlopok: üres értéknél 0, nem null (MySQL strict mód).
			'assignee_id' => $assignee ?: 0,
			'created_by'  => get_current_user_id(),
			'sort'        => function_exists( 'hpv_pm_next_sort' ) ? hpv_pm_next_sort( $crm_project, 'todo' ) : 0,
		);
		if ( $retainer ) {
			// Havidíjas projekt: a feladat hónapja (a projekt hónapszűrőjéhez és a havi riporthoz).
			$row['period'] = substr( (string) ( $task['due_date'] ?? '' ), 0, 7 ) ?: gmdate( 'Y-m' );
		}
		$id = hpv_p_insert( 'task', $row );
		if ( $id ) {
			$pushed[] = array( 'id' => (int) $task['id'], 'crm_task_id' => $id );
		}
	}
	if ( $pushed ) {
		$saved = hpv_seo_api_json( 'POST', 'projects/' . $project_id . '/tasks/crm-links', $pushed );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	}

	return rest_ensure_response(
		array(
			'pushed'         => count( $pushed ),
			'crm_project_id' => $crm_project,
			'crm_url'        => function_exists( 'hpv_p_crm_host' ) ? 'https://' . hpv_p_crm_host() . '/#/projects/' . $crm_project : '',
		)
	);
}

function hpv_seo_crm_project( int $client_id, string $name ): int {
	$title = 'SEO – ' . $name;
	foreach ( hpv_p_find( 'project', array( 'client_id' => $client_id ), array( 'limit' => 500 ) ) as $row ) {
		if ( $row['name'] === $title ) {
			return (int) $row['id'];
		}
	}

	$data = array(
		'client_id' => $client_id,
		'name'      => $title,
		'status'    => 'in_progress',
		'visible'   => 0,
		'owner_id'  => get_current_user_id(),
	);
	if ( hpv_seo_crm_has_field( 'project', 'kind' ) ) {
		$data['kind'] = 'seo';
	}

	return hpv_p_insert( 'project', $data );
}

/** Van-e ilyen mező a CRM adott entitásában (a portál verziójától függ). */
function hpv_seo_crm_has_field( string $entity, string $field ): bool {
	if ( ! function_exists( 'hpv_p_entity' ) ) {
		return false;
	}
	try {
		return isset( hpv_p_entity( $entity )['fields'][ $field ] );
	} catch ( Throwable $e ) {
		return false;
	}
}

function hpv_seo_crm_task_description( array $task ): string {
	$lines = array();
	foreach (
		array(
			'action'     => 'Teendő',
			'source_url' => 'Forrás URL',
			'target_url' => 'Cél URL',
			'done_when'  => 'Késznek akkor tekinthető',
			'notes'      => 'Megjegyzés',
		) as $key => $label
	) {
		if ( ! empty( $task[ $key ] ) ) {
			$lines[] = $label . ': ' . $task[ $key ];
		}
	}
	$lines[] = 'SEO OS: ' . hpv_seo_app_url( '/projects/' . (int) $task['project_id'] . '?tab=documents' );

	return implode( "\n", $lines );
}

/**
 * Dokumentum küldése ügyfél-jóváhagyásra: jóváhagyó link (SEO OS), majd értesítés az ügyfélportálon keresztül –
 * e-mail az ügyfél portál-felhasználóinak és üzenet az ügyfél chat-csatornájába. CRM nélkül a megadott e-mail címre.
 */
function hpv_seo_crm_client_review( WP_REST_Request $request ) {
	$doc_id = absint( $request->get_param( 'document_id' ) );
	$email  = sanitize_email( (string) $request->get_param( 'email' ) );
	$msg    = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
	$lang   = 'en' === $request->get_param( 'language' ) ? 'en' : ( 'hu' === $request->get_param( 'language' ) ? 'hu' : null );
	if ( null === $lang && function_exists( 'hpv_doc_client_language' ) ) {
		$project = hpv_seo_api_json( 'GET', 'documents/' . $doc_id );
		$crm_id  = 0;
		if ( ! is_wp_error( $project ) ) {
			$p      = hpv_seo_api_json( 'GET', 'projects/' . (int) $project['project_id'] );
			$crm_id = is_wp_error( $p ) ? 0 : (int) ( $p['client']['crm_client_id'] ?? 0 );
		}
		$lang = $crm_id ? hpv_doc_client_language( $crm_id ) : null;
	}
	$chat   = (bool) $request->get_param( 'chat' );

	$review = hpv_seo_api_json( 'POST', 'documents/' . $doc_id . '/client-review', array_filter( array( 'email' => $email, 'message' => $msg, 'language' => $lang ) ) );
	if ( is_wp_error( $review ) ) {
		return $review;
	}
	$url     = hpv_seo_review_url( (string) $review['token'] );
	$en      = 'en' === ( $review['language'] ?? 'hu' ) ? true : false;
	$subject = $en ? sprintf( 'Please review: %s – %s', $review['title'], $review['domain'] ) : sprintf( 'Jóváhagyásra vár: %s – %s', $review['title'], $review['domain'] );
	$body    = '<p>' . esc_html( $en ? 'We have prepared a document for your review. You can download it, ask questions, approve it or request changes on the page below.' : 'Elkészült egy dokumentum, amit kérjük, nézz át. Az alábbi oldalon letöltheted, kérdezhetsz, jóváhagyhatod vagy módosítást kérhetsz.' ) . '</p>'
		. ( $msg ? '<p><em>' . nl2br( esc_html( $msg ) ) . '</em></p>' : '' );
	$cta     = $en ? 'Review document' : 'Dokumentum megtekintése';

	$crm_client = (int) ( $review['crm_client_id'] ?? 0 );
	$emailed    = false;
	$chatted    = false;
	$portal_id  = 0;
	$crm_ok     = $crm_client && function_exists( 'hpv_p_get' ) && hpv_p_get( 'client', $crm_client );

	// 1) Ügyfélportál 0.7+: a portál „Jóváhagyás” menüje (az ügyfél ott dönt, a portál küldi a levelet az ügyfél nyelvén).
	if ( $crm_ok && function_exists( 'hpv_approval_upsert' ) && function_exists( 'hpv_approval_send' ) ) {
		$approval = hpv_approval_upsert(
			array(
				'client_id'    => $crm_client,
				'type'         => 'document',
				'title'        => (string) $review['title'] . ' — ' . (string) $review['domain'],
				'body'         => $body,
				'link'         => hpv_seo_review_url( (string) $review['token'], 'pdf' ),
				'source'       => 'seo-os',
				'external_ref' => 'document-' . $doc_id,
			),
			0,
			get_current_user_id()
		);
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}
		$sent = hpv_approval_send( (int) $approval['id'], get_current_user_id() );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		$portal_id = (int) $approval['id'];
		$emailed   = true;
	} elseif ( $crm_ok && function_exists( 'hpv_p_notify_client' ) ) {
		// 2) Régebbi portál: e-mail az ügyfél portál-felhasználóinak és üzenet a chatbe, a jóváhagyó oldal linkjével.
		$emailed = hpv_p_notify_client( $crm_client, $subject, (string) $review['title'], $body, $cta, $url );
		if ( $chat && function_exists( 'hpv_chat_client_channel' ) && function_exists( 'hpv_chat_post' ) ) {
			$channel = hpv_chat_client_channel( $crm_client );
			if ( $channel ) {
				$posted  = hpv_chat_post( (int) $channel['id'], get_current_user_id(), ( $en ? 'Document ready for your review: ' : 'Jóváhagyásra vár: ' ) . $review['title'] . "\n" . ( $msg ? $msg . "\n" : '' ) . $url );
				$chatted = ! is_wp_error( $posted );
			}
		}
	}
	if ( $email && ( ! $emailed || ! $crm_client ) ) {
		$html    = function_exists( 'hpv_p_email_html' ) ? hpv_p_email_html( (string) $review['title'], $body, $cta, $url ) : hpv_seo_mail_html( (string) $review['title'], wp_strip_all_tags( $body ), $cta, $url );
		$emailed = wp_mail( $email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) ) || $emailed;
	}

	return rest_ensure_response(
		array(
			'url'       => $portal_id ? '' : $url,
			'emailed'   => (bool) $emailed,
			'chat'      => $chatted,
			'portal_id' => $portal_id,
			'portal'    => $portal_id && function_exists( 'hpv_p_crm_app_url' ) ? hpv_p_crm_app_url( '/content/' . $portal_id ) : '',
			'approval' => $review,
		)
	);
}
