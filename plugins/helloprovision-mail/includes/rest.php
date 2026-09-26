<?php
/**
 * REST: a CRM felülete a hpv/v1/mail/… címeket hívja, a WordPress aláírva továbbítja az SEO OS szerverre
 * (mail/… végpontok). A feladat-készítés itt, a WordPressben történik, mert a feladatok a CRM-ben vannak.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'hpv_mail_routes' );

function hpv_mail_routes(): void {
	register_rest_route(
		'hpv/v1',
		'/mail/(?P<path>[a-zA-Z0-9_\-/\.]+)',
		array(
			'methods'             => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
			'permission_callback' => 'hpv_mail_can',
			'callback'            => fn( WP_REST_Request $r ) => hpv_seo_forward( $r, 'mail/' . $r['path'], hpv_mail_as() ),
		)
	);
	register_rest_route(
		'hpv/v1',
		'/mail-task',
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => hpv_mail_can() && hpv_p_is_staff(),
			'callback'            => 'hpv_mail_rest_task',
		)
	);
	register_rest_route(
		'hpv/v1',
		'/mail-contacts-sync',
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => hpv_mail_can() && current_user_can( 'manage_options' ),
			'callback'            => fn() => rest_ensure_response( hpv_mail_push_contacts( true ) ),
		)
	);
}

/**
 * Feladat egy levélből: a CRM projektjébe, a levél lényegével a leírásban; a levél megjegyzi a feladatot,
 * az ügyfél idővonalára bejegyzés kerül.
 */
function hpv_mail_rest_task( WP_REST_Request $request ) {
	$message_id = absint( $request->get_param( 'message_id' ) );
	$msg        = hpv_seo_api_json( 'GET', 'mail/messages/' . $message_id, null, hpv_mail_as() );
	if ( is_wp_error( $msg ) ) {
		return $msg;
	}
	$project = hpv_p_get( 'project', absint( $request->get_param( 'project_id' ) ) );
	if ( ! $project || ! empty( $project['is_template'] ) ) {
		return new WP_Error( 'project', 'Válassz projektet.', array( 'status' => 400 ) );
	}
	$from  = trim( ( $msg['from']['name'] ?? '' ) . ' <' . ( $msg['from']['email'] ?? '' ) . '>' );
	$date  = ! empty( $msg['date'] ) ? wp_date( 'Y. m. d. H:i', strtotime( $msg['date'] ) ) : '';
	$text  = trim( (string) ( $msg['body_text'] ?? '' ) );
	$text  = mb_strlen( $text ) > 3000 ? mb_substr( $text, 0, 3000 ) . '…' : $text;
	$note  = trim( (string) $request->get_param( 'note' ) );
	$desc  = ( '' !== $note ? $note . "\n\n" : '' ) . "E-mailből: {$from} · {$date}\nTárgy: " . ( $msg['subject'] ?? '' ) . "\n\n" . $text;
	$title = trim( (string) $request->get_param( 'title' ) ) ?: (string) ( $msg['subject'] ?? 'Feladat e-mailből' );

	$create = new WP_REST_Request( 'POST', '/hpv/v1/pm/tasks' );
	$create->set_body_params(
		array(
			'project_id'  => (int) $project['id'],
			'title'       => mb_substr( $title, 0, 250 ),
			'description' => $desc,
			'assignee_id' => absint( $request->get_param( 'assignee_id' ) ) ?: get_current_user_id(),
			'due_date'    => sanitize_text_field( (string) $request->get_param( 'due_date' ) ),
			'priority'    => sanitize_key( (string) $request->get_param( 'priority' ) ) ?: 'normal',
			'status'      => 'todo',
			'visible'     => $request->get_param( 'visible' ) ? 1 : 0,
		)
	);
	$res = rest_do_request( $create );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	$task = $res->get_data();
	hpv_seo_api_json( 'POST', 'mail/messages/' . $message_id . '/link', array( 'crm_task_id' => (int) $task['id'], 'remember' => false ), hpv_mail_as() );

	$client_id = (int) ( $msg['crm_client_id'] ?? 0 ) ?: (int) $project['client_id'];
	if ( $client_id && function_exists( 'hpv_p_log' ) ) {
		hpv_p_log( $client_id, 'note', sprintf( 'Feladat e-mailből: „%s” (%s)', $title, $from ), false, get_current_user_id() );
	}
	if ( empty( $msg['crm_client_id'] ) && $project['client_id'] ) {
		hpv_seo_api_json( 'POST', 'mail/messages/' . $message_id . '/link', array( 'crm_client_id' => (int) $project['client_id'] ), hpv_mail_as() );
	}

	return rest_ensure_response(
		array(
			'task'    => $task,
			'url'     => '#/projects/' . (int) $project['id'] . '?task=' . (int) $task['id'],
			'project' => $project['name'],
		)
	);
}
