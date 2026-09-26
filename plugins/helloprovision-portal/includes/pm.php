<?php
/**
 * Projektkezelés: REST API a CRM webalkalmazásnak (csak munkatársaknak).
 * Projektek, sablonok, feladatok (alfeladat, felelős, prioritás, dátumok), Kanban sorrend,
 * hozzászólások, ellenőrzőlista, időmérés (stopper és kézi), függőségek, „Saját feladataim”, vezérlőpult.
 */

defined( 'ABSPATH' ) || exit;

const HPV_PM_NS = 'hpv/v1';

/* ─── Formázás ────────────────────────────────────────────── */

/**
 * Keresztnév a CRM köszönéséhez: a profil „Keresztnév” mezője, különben (a CRM magyar) a név utolsó tagja: Ritók Áron → Áron.
 */
function hpv_pm_first_name( WP_User $user ): string {
	$first = trim( (string) $user->first_name );
	if ( '' !== $first && $first !== $user->display_name ) {
		return $first;
	}
	$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );

	return (string) end( $parts );
}

function hpv_pm_user( int $user_id ): ?array {
	if ( ! $user_id ) {
		return null;
	}
	$label = hpv_chat_user_label( $user_id );

	return array(
		'id'       => $user_id,
		'name'     => $label['name'],
		'initials' => $label['initials'],
	);
}

function hpv_pm_statuses(): array {
	$out = array();
	foreach ( hpv_p_entity( 'task' )['fields']['status']['options'] as $key => $labels ) {
		$out[] = array(
			'key'   => $key,
			'label' => $labels[0],
		);
	}

	return $out;
}

/**
 * Összesítők feladatonként (alfeladat, ellenőrzőlista, hozzászólás, rögzített idő) egy lekérdezés-körben.
 */
function hpv_pm_task_stats( array $task_ids ): array {
	global $wpdb;
	$stats = array();
	foreach ( $task_ids as $id ) {
		$stats[ $id ] = array(
			'checklist_done'  => 0,
			'checklist_total' => 0,
			'comments'        => 0,
			'minutes'         => 0,
			'subtasks_done'   => 0,
			'subtasks_total'  => 0,
			'blocked_by'      => array(),
		);
	}
	if ( ! $task_ids ) {
		return $stats;
	}
	$in = implode( ',', array_map( 'intval', $task_ids ) );

	foreach ( $wpdb->get_results( 'SELECT task_id, COUNT(*) AS total, SUM(done) AS done FROM ' . hpv_p_table( 'checklist_item' ) . " WHERE task_id IN ($in) GROUP BY task_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$stats[ (int) $r['task_id'] ]['checklist_total'] = (int) $r['total'];
		$stats[ (int) $r['task_id'] ]['checklist_done']  = (int) $r['done'];
	}
	foreach ( $wpdb->get_results( 'SELECT task_id, COUNT(*) AS total FROM ' . hpv_p_table( 'task_comment' ) . " WHERE task_id IN ($in) GROUP BY task_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$stats[ (int) $r['task_id'] ]['comments'] = (int) $r['total'];
	}
	foreach ( $wpdb->get_results( 'SELECT task_id, SUM(minutes) AS total FROM ' . hpv_p_table( 'time_entry' ) . " WHERE task_id IN ($in) GROUP BY task_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$stats[ (int) $r['task_id'] ]['minutes'] = (int) $r['total'];
	}
	foreach ( $wpdb->get_results( 'SELECT parent_id, COUNT(*) AS total, SUM(CASE WHEN status = \'done\' THEN 1 ELSE 0 END) AS done FROM ' . hpv_p_table( 'task' ) . " WHERE parent_id IN ($in) GROUP BY parent_id", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$stats[ (int) $r['parent_id'] ]['subtasks_total'] = (int) $r['total'];
		$stats[ (int) $r['parent_id'] ]['subtasks_done']  = (int) $r['done'];
	}
	foreach ( $wpdb->get_results( 'SELECT task_id, depends_on FROM ' . hpv_p_table( 'task_link' ) . " WHERE task_id IN ($in)", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
		$stats[ (int) $r['task_id'] ]['blocked_by'][] = (int) $r['depends_on'];
	}

	return $stats;
}

function hpv_pm_format_task( array $t, array $stats = array() ): array {
	$s = $stats ?: array(
		'checklist_done'  => 0,
		'checklist_total' => 0,
		'comments'        => 0,
		'minutes'         => 0,
		'subtasks_done'   => 0,
		'subtasks_total'  => 0,
		'blocked_by'      => array(),
	);

	return array(
		'id'           => (int) $t['id'],
		'project_id'   => (int) $t['project_id'],
		'parent_id'    => (int) $t['parent_id'],
		'title'        => $t['title'],
		'status'       => $t['status'],
		'priority'     => $t['priority'] ?: 'normal',
		'assignee'     => hpv_pm_user( (int) $t['assignee_id'] ),
		'start_date'   => $t['start_date'],
		'due_date'     => $t['due_date'],
		'visible'      => (bool) $t['visible'],
		'sort'         => (int) $t['sort'],
		'estimate'     => (int) $t['estimate'],
		'completed_at' => $t['completed_at'],
		'period'       => (string) ( $t['period'] ?? '' ),
		'stats'        => $s,
	);
}

function hpv_pm_format_project( array $p, bool $with_counts = true ): array {
	$out = array(
		'id'          => (int) $p['id'],
		'name'        => $p['name'],
		'description' => $p['description'],
		'status'      => $p['status'],
		'client_id'   => (int) $p['client_id'],
		'client'      => $p['client_id'] ? hpv_p_client_name_safe( (int) $p['client_id'] ) : '',
		'owner'       => hpv_pm_user( (int) $p['owner_id'] ),
		'color'       => $p['color'] ?: '#b8ff34',
		'start_date'  => $p['start_date'],
		'due_date'    => $p['due_date'],
		'visible'     => (bool) $p['visible'],
		'is_template' => (bool) $p['is_template'],
		'kind'        => $p['kind'] ?: 'web',
		'package_id'  => (int) $p['package_id'],
		'package_day' => max( 1, (int) $p['package_day'] ),
		'last_package' => (string) $p['last_package'],
	);
	if ( $with_counts ) {
		$tasks           = hpv_p_find( 'task', array( 'project_id' => $p['id'], 'parent_id' => 0 ), array( 'limit' => 2000 ) );
		$today           = current_time( 'Y-m-d' );
		$out['tasks']    = count( $tasks );
		$out['done']     = count( array_filter( $tasks, fn( $t ) => 'done' === $t['status'] ) );
		$out['overdue']  = count( array_filter( $tasks, fn( $t ) => 'done' !== $t['status'] && $t['due_date'] && $t['due_date'] < $today ) );
		$out['progress'] = hpv_p_project_progress( $tasks );
		$people          = array_unique( array_filter( array_map( fn( $t ) => (int) $t['assignee_id'], $tasks ) ) );
		$out['people']   = array_values( array_map( 'hpv_pm_user', array_slice( $people, 0, 6 ) ) );
	}

	return $out;
}

/* ─── Műveletek ───────────────────────────────────────────── */

/**
 * Feladat mezőinek frissítése a megengedett mezőkből. Kész státusznál lezárási időt kap.
 */
function hpv_pm_update_task( int $id, array $input ): ?array {
	$task = hpv_p_get( 'task', $id );
	if ( ! $task ) {
		return null;
	}
	$allowed = array( 'title', 'status', 'priority', 'assignee_id', 'start_date', 'due_date', 'visible', 'description', 'estimate', 'parent_id', 'project_id', 'sort', 'period' );
	$data    = hpv_p_sanitize( 'task', array_intersect_key( $input, array_flip( $allowed ) ) );

	if ( isset( $data['parent_id'] ) && $data['parent_id'] === $id ) {
		unset( $data['parent_id'] ); // önmaga nem lehet a szülője
	}
	if ( isset( $data['title'] ) && '' === trim( $data['title'] ) ) {
		unset( $data['title'] );
	}
	if ( isset( $data['status'] ) && $data['status'] !== $task['status'] ) {
		$data['completed_at'] = 'done' === $data['status'] ? current_time( 'mysql', true ) : null;
	}
	if ( ! $data ) {
		return $task;
	}
	hpv_p_update( 'task', $id, $data );
	$new = hpv_p_get( 'task', $id );

	hpv_pm_after_task_change( $task, $new );

	return $new;
}

/**
 * Értesítések: új felelős e-mailt kap; ha a feladat „Ügyfélre vár” lesz és az ügyfél látja, az ügyfél is.
 */
function hpv_pm_after_task_change( ?array $old, array $new ): void {
	$me = get_current_user_id();

	if ( (int) $new['assignee_id'] && (int) $new['assignee_id'] !== $me && ( ! $old || (int) $old['assignee_id'] !== (int) $new['assignee_id'] ) ) {
		$user    = get_userdata( (int) $new['assignee_id'] );
		$project = hpv_p_get( 'project', (int) $new['project_id'] );
		if ( $user && $project ) {
			hpv_p_send(
				$user->user_email,
				sprintf( 'Új feladat: %s', $new['title'] ),
				hpv_p_email_html(
					sprintf( '%s rád osztotta: %s', wp_get_current_user()->display_name, $new['title'] ),
					sprintf( '<p>Projekt: <strong>%s</strong>%s</p>', esc_html( $project['name'] ), $new['due_date'] ? '<br>Határidő: ' . esc_html( $new['due_date'] ) : '' ),
					'Megnyitás',
					hpv_p_crm_app_url( '/projects/' . (int) $project['id'] . '?task=' . (int) $new['id'] )
				)
			);
		}
	}

	if ( 'client' === $new['status'] && $new['visible'] && ! $new['parent_id'] && ( ! $old || 'client' !== $old['status'] ) ) {
		$project = hpv_p_get( 'project', (int) $new['project_id'] );
		if ( $project && $project['visible'] && $project['client_id'] ) {
			hpv_p_log_client( (int) $project['client_id'], 'Action needed: %s (%s)', array( $new['title'], $project['name'] ), $me );
			hpv_with_client_lang(
				(int) $project['client_id'],
				fn() => hpv_p_notify_client(
					(int) $project['client_id'],
					hpv_t( 'Action needed: %s', $new['title'] ),
					hpv_t( 'We need something from you' ),
					'<p>' . hpv_t( '<strong>%s</strong> in <em>%s</em> is waiting on you%s.', esc_html( $new['title'] ), esc_html( $project['name'] ), $new['due_date'] ? esc_html( hpv_t( ' — due %s', hpv_date( $new['due_date'], 'short' ) ) ) : '' ) . '</p>',
					hpv_t( 'Open project' ),
					hpv_p_portal_url( array( 'view' => 'projects', 'id' => $project['id'] ) )
				)
			);
		}
	}
}

function hpv_pm_next_sort( int $project_id, string $status ): int {
	$rows = hpv_p_find( 'task', array( 'project_id' => $project_id, 'status' => $status ), array( 'orderby' => 'sort', 'order' => 'DESC', 'limit' => 1 ) );

	return $rows ? (int) $rows[0]['sort'] + 10 : 10;
}

/**
 * Új projekt sablonból: a feladatok (és alfeladatok, ellenőrzőlisták) átmásolódnak,
 * a dátumok a sablon kezdőnapjához képest eltolva.
 */
function hpv_pm_copy_template( int $template_id, int $project_id, string $start ): array {
	$template = hpv_p_get( 'project', $template_id );
	if ( ! $template ) {
		return array();
	}
	$base  = $template['start_date'] ?: current_time( 'Y-m-d' );
	$shift = function ( ?string $date ) use ( $base, $start ): ?string {
		if ( ! $date ) {
			return null;
		}
		$days = (int) round( ( strtotime( $date ) - strtotime( $base ) ) / DAY_IN_SECONDS );

		return gmdate( 'Y-m-d', strtotime( $start . ' ' . ( $days >= 0 ? '+' : '' ) . $days . ' days' ) );
	};

	$map   = array();
	$tasks = hpv_p_find( 'task', array( 'project_id' => $template_id ), array( 'orderby' => 'id', 'order' => 'ASC', 'limit' => 2000 ) );
	foreach ( $tasks as $t ) {
		$copy = $t;
		unset( $copy['id'], $copy['created_at'], $copy['updated_at'] );
		$copy['project_id']   = $project_id;
		$copy['status']       = 'todo';
		$copy['completed_at'] = null;
		$copy['parent_id']    = 0;
		$copy['start_date']   = $shift( $t['start_date'] );
		$copy['due_date']     = $shift( $t['due_date'] );
		$copy['created_by']   = get_current_user_id();
		$map[ (int) $t['id'] ] = hpv_p_insert( 'task', $copy );
	}
	foreach ( $tasks as $t ) {
		if ( $t['parent_id'] && isset( $map[ (int) $t['parent_id'] ] ) ) {
			hpv_p_update( 'task', $map[ (int) $t['id'] ], array( 'parent_id' => $map[ (int) $t['parent_id'] ] ) );
		}
		foreach ( hpv_p_find( 'checklist_item', array( 'task_id' => $t['id'] ), array( 'orderby' => 'sort', 'order' => 'ASC' ) ) as $item ) {
			hpv_p_insert(
				'checklist_item',
				array(
					'task_id' => $map[ (int) $t['id'] ],
					'title'   => $item['title'],
					'sort'    => $item['sort'],
				)
			);
		}
	}

	return array_values( $map );
}

/**
 * A felhasználó futó stoppere (legfeljebb egy).
 */
function hpv_pm_running_timer( int $user_id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . hpv_p_table( 'time_entry' ) . ' WHERE user_id = %d AND started_at > 0 ORDER BY id DESC LIMIT 1', $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return $row ?: null;
}

function hpv_pm_stop_timer( int $user_id ): ?array {
	$running = hpv_pm_running_timer( $user_id );
	if ( ! $running ) {
		return null;
	}
	$minutes = max( 1, (int) ceil( ( time() - (int) $running['started_at'] ) / 60 ) );
	hpv_p_update(
		'time_entry',
		(int) $running['id'],
		array(
			'minutes'    => (int) $running['minutes'] + $minutes,
			'started_at' => 0,
		)
	);

	return hpv_p_get( 'time_entry', (int) $running['id'] );
}

function hpv_pm_format_timer( ?array $entry ): ?array {
	if ( ! $entry ) {
		return null;
	}
	$task    = hpv_p_get( 'task', (int) $entry['task_id'] );
	$project = $task ? hpv_p_get( 'project', (int) $task['project_id'] ) : null;

	return array(
		'entry_id'   => (int) $entry['id'],
		'task_id'    => (int) $entry['task_id'],
		'task'       => $task ? $task['title'] : '',
		'project_id' => $project ? (int) $project['id'] : 0,
		'project'    => $project ? $project['name'] : '',
		'started_at' => (int) $entry['started_at'],
	);
}

function hpv_p_crm_app_url( string $path = '/' ): string {
	return hpv_p_scheme() . '://' . hpv_p_crm_host() . '/#' . $path;
}

/* ─── REST útvonalak ──────────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_pm_routes' );

function hpv_pm_routes() {
	$staff = fn() => hpv_p_is_staff();
	$route = function ( string $path, string $methods, callable $callback ) use ( $staff ) {
		register_rest_route(
			HPV_PM_NS,
			'/pm' . $path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $staff,
			)
		);
	};

	$route( '/bootstrap', 'GET', 'hpv_pm_rest_bootstrap' );
	$route( '/dashboard', 'GET', 'hpv_pm_rest_dashboard' );
	$route( '/projects', 'GET', 'hpv_pm_rest_projects' );
	$route( '/projects', 'POST', 'hpv_pm_rest_create_project' );
	$route( '/projects/(?P<id>\d+)', 'GET', 'hpv_pm_rest_project' );
	$route( '/projects/(?P<id>\d+)', 'POST', 'hpv_pm_rest_update_project' );
	$route( '/projects/(?P<id>\d+)', 'DELETE', 'hpv_pm_rest_delete_project' );
	$route( '/tasks', 'POST', 'hpv_pm_rest_create_task' );
	$route( '/tasks/reorder', 'POST', 'hpv_pm_rest_reorder' );
	$route( '/tasks/(?P<id>\d+)', 'GET', 'hpv_pm_rest_task' );
	$route( '/tasks/(?P<id>\d+)', 'POST', 'hpv_pm_rest_update_task' );
	$route( '/tasks/(?P<id>\d+)', 'DELETE', 'hpv_pm_rest_delete_task' );
	$route( '/tasks/(?P<id>\d+)/comments', 'POST', 'hpv_pm_rest_comment' );
	$route( '/tasks/(?P<id>\d+)/checklist', 'POST', 'hpv_pm_rest_add_checklist' );
	$route( '/checklist/(?P<id>\d+)', 'POST', 'hpv_pm_rest_update_checklist' );
	$route( '/checklist/(?P<id>\d+)', 'DELETE', 'hpv_pm_rest_delete_checklist' );
	$route( '/tasks/(?P<id>\d+)/timer', 'POST', 'hpv_pm_rest_timer' );
	$route( '/tasks/(?P<id>\d+)/time', 'POST', 'hpv_pm_rest_add_time' );
	$route( '/time/(?P<id>\d+)', 'DELETE', 'hpv_pm_rest_delete_time' );
	$route( '/tasks/(?P<id>\d+)/links', 'POST', 'hpv_pm_rest_add_link' );
	$route( '/links/(?P<id>\d+)', 'DELETE', 'hpv_pm_rest_delete_link' );
	$route( '/my-tasks', 'GET', 'hpv_pm_rest_my_tasks' );
	$route( '/timer', 'GET', fn() => rest_ensure_response( hpv_pm_format_timer( hpv_pm_running_timer( get_current_user_id() ) ) ) );
	$route( '/search', 'GET', 'hpv_pm_rest_search' );
}

function hpv_pm_not_found() {
	return new WP_Error( 'not_found', 'Nem található.', array( 'status' => 404 ) );
}

function hpv_pm_rest_bootstrap() {
	$me = wp_get_current_user();

	return rest_ensure_response(
		array(
			'me'         => array_merge( hpv_pm_user( $me->ID ), array( 'first' => hpv_pm_first_name( $me ), 'is_admin' => current_user_can( 'manage_options' ), 'caps' => hpv_p_caps_for( $me->ID ) ) ),
			'users'      => array_map( fn( $u ) => hpv_pm_user( $u->ID ), get_users( array( 'capability' => 'hpv_manage_crm', 'orderby' => 'display_name' ) ) ),
			'clients'    => array_map(
				fn( $c ) => array(
					'id'      => (int) $c['id'],
					'name'    => $c['name'],
					'status'  => $c['status'],
					'country' => 'HU' === $c['country'] ? 'HU' : 'US',
				),
				hpv_p_find( 'client', array(), array( 'orderby' => 'name', 'order' => 'ASC', 'limit' => 2000 ) )
			),
			'statuses'   => hpv_pm_statuses(),
			'priorities' => array_map( fn( $k, $v ) => array( 'key' => $k, 'label' => $v[0] ), array_keys( hpv_p_entity( 'task' )['fields']['priority']['options'] ), hpv_p_entity( 'task' )['fields']['priority']['options'] ),
			'approvalsAttention' => count( hpv_p_find( 'approval', array( 'status' => 'changes' ), array( 'limit' => 500 ) ) ),
			'salesAttention'     => count( hpv_p_find( 'client', array( 'status' => 'lead', 'lead_stage' => 'new' ), array( 'limit' => 500 ) ) ),
			'projectKinds'    => array_map( fn( $k, $v ) => array( 'key' => $k, 'label' => $v[0] ), array_keys( hpv_p_entity( 'project' )['fields']['kind']['options'] ), hpv_p_entity( 'project' )['fields']['kind']['options'] ),
			'projectStatuses' => array_map( fn( $k, $v ) => array( 'key' => $k, 'label' => $v[0] ), array_keys( hpv_p_entity( 'project' )['fields']['status']['options'] ), hpv_p_entity( 'project' )['fields']['status']['options'] ),
			'timer'      => hpv_pm_format_timer( hpv_pm_running_timer( $me->ID ) ),
			'unread'     => hpv_chat_total_unread( $me->ID ),
			'adminUrl'   => admin_url( 'admin.php?page=hpv-crm' ),
			'portalUrl'  => hpv_p_portal_url(),
			'logoutUrl'  => wp_logout_url( hpv_p_scheme() . '://' . hpv_p_crm_host() . '/' ),
			'currency'   => hpv_p_settings()['currency'],
		)
	);
}

function hpv_pm_rest_dashboard() {
	$me    = get_current_user_id();
	$today = current_time( 'Y-m-d' );
	$week  = gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
	$mine  = array_filter( hpv_p_find( 'task', array( 'assignee_id' => $me ), array( 'limit' => 2000 ) ), fn( $t ) => 'done' !== $t['status'] );

	global $wpdb;
	$my_minutes   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(minutes) FROM ' . hpv_p_table( 'time_entry' ) . ' WHERE user_id = %d AND work_date >= %s', $me, $week ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$team_minutes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(minutes) FROM ' . hpv_p_table( 'time_entry' ) . ' WHERE work_date >= %s', $week ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$done_week    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . hpv_p_table( 'task' ) . ' WHERE completed_at >= %s', $week . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	$money         = hpv_p_can( 'invoices' );
	$open_invoices = $money ? hpv_p_find( 'invoice', array( 'status' => 'sent' ), array( 'limit' => 2000 ) ) : array();
	$currency      = hpv_p_settings()['currency'];
	$active        = hpv_p_find( 'project', array( 'status' => array( 'planning', 'in_progress', 'review' ), 'is_template' => 0 ), array( 'orderby' => 'due_date', 'order' => 'ASC', 'limit' => 50 ) );

	return rest_ensure_response(
		array(
			'my_open'      => count( $mine ),
			'my_overdue'   => count( array_filter( $mine, fn( $t ) => $t['due_date'] && $t['due_date'] < $today ) ),
			'my_today'     => array_values(
				array_map(
					fn( $t ) => hpv_pm_format_task( $t ),
					array_filter( $mine, fn( $t ) => $t['due_date'] && $t['due_date'] <= $today )
				)
			),
			'my_minutes'   => $my_minutes,
			'team_minutes' => $team_minutes,
			'done_week'    => $done_week,
			'projects'     => array_map( 'hpv_pm_format_project', $active ),
			// Bevételi számok csak számlázási joggal, pénznemenként összesítve.
			'money'        => $money ? array(
				'mrr'              => hpv_p_money_multi( hpv_p_mrr_by_currency( hpv_p_find( 'subscription', array( 'status' => 'active' ), array( 'limit' => 2000 ) ) ), $currency ),
				'outstanding'      => hpv_p_money_multi( hpv_p_outstanding_by_currency( $open_invoices ), $currency ),
				'overdue_invoices' => count( array_filter( $open_invoices, fn( $i ) => hpv_p_invoice_is_overdue( $i, $today ) ) ),
			) : null,
			'contracts_waiting' => hpv_p_can( 'contracts' ) ? count( hpv_p_find( 'contract', array( 'status' => 'sent' ), array( 'limit' => 2000 ) ) ) : null,
			'active_clients' => count( hpv_p_find( 'client', array( 'status' => 'active' ), array( 'limit' => 2000 ) ) ),
		)
	);
}

function hpv_pm_rest_projects( WP_REST_Request $request ) {
	$where = array( 'is_template' => $request->get_param( 'templates' ) ? 1 : 0 );
	if ( $request->get_param( 'client_id' ) ) {
		$where['client_id'] = absint( $request->get_param( 'client_id' ) );
	}

	return rest_ensure_response( array_map( 'hpv_pm_format_project', hpv_p_find( 'project', $where, array( 'orderby' => 'updated_at', 'limit' => 500 ) ) ) );
}

function hpv_pm_rest_create_project( WP_REST_Request $request ) {
	$data = hpv_p_sanitize( 'project', $request->get_params() );
	if ( '' === trim( $data['name'] ?? '' ) ) {
		return new WP_Error( 'name', 'Adj nevet a projektnek.', array( 'status' => 400 ) );
	}
	if ( empty( $data['owner_id'] ) ) {
		$data['owner_id'] = get_current_user_id();
	}
	if ( empty( $data['start_date'] ) ) {
		$data['start_date'] = current_time( 'Y-m-d' );
	}
	$id = hpv_p_insert( 'project', $data );

	$template = absint( $request->get_param( 'template_id' ) );
	if ( $template ) {
		hpv_pm_copy_template( $template, $id, $data['start_date'] );
	}

	return rest_ensure_response( hpv_pm_format_project( hpv_p_get( 'project', $id ) ) );
}

function hpv_pm_rest_project( WP_REST_Request $request ) {
	$project = hpv_p_get( 'project', (int) $request['id'] );
	if ( ! $project ) {
		return hpv_pm_not_found();
	}
	$tasks = hpv_p_find( 'task', array( 'project_id' => $project['id'] ), array( 'orderby' => 'sort', 'order' => 'ASC', 'limit' => 2000 ) );
	$stats = hpv_pm_task_stats( array_map( fn( $t ) => (int) $t['id'], $tasks ) );

	return rest_ensure_response(
		array(
			'project' => hpv_pm_format_project( $project ),
			'tasks'   => array_map( fn( $t ) => hpv_pm_format_task( $t, $stats[ (int) $t['id'] ] ), $tasks ),
		)
	);
}

function hpv_pm_rest_update_project( WP_REST_Request $request ) {
	$project = hpv_p_get( 'project', (int) $request['id'] );
	if ( ! $project ) {
		return hpv_pm_not_found();
	}
	$allowed = array( 'name', 'description', 'status', 'client_id', 'owner_id', 'color', 'start_date', 'due_date', 'visible', 'is_template', 'kind', 'package_id', 'package_day' );
	$data    = hpv_p_sanitize( 'project', array_intersect_key( $request->get_params(), array_flip( $allowed ) ) );
	if ( isset( $data['package_id'] ) && $data['package_id'] ) {
		$tpl = hpv_p_get( 'project', (int) $data['package_id'] );
		if ( ! $tpl || ! (int) $tpl['is_template'] || (int) $tpl['id'] === (int) $project['id'] ) {
			return new WP_Error( 'package', 'A havi csomag csak egy projektsablon lehet.', array( 'status' => 400 ) );
		}
	}
	if ( isset( $data['package_day'] ) ) {
		$data['package_day'] = min( 28, max( 1, (int) $data['package_day'] ) );
	}
	if ( isset( $data['name'] ) && '' === trim( $data['name'] ) ) {
		unset( $data['name'] );
	}
	if ( $data ) {
		hpv_p_update( 'project', (int) $project['id'], $data );
	}

	return rest_ensure_response( hpv_pm_format_project( hpv_p_get( 'project', (int) $project['id'] ) ) );
}

function hpv_pm_rest_delete_project( WP_REST_Request $request ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', 'Projektet csak adminisztrátor törölhet.', array( 'status' => 403 ) );
	}
	hpv_p_delete( 'project', (int) $request['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

function hpv_pm_rest_create_task( WP_REST_Request $request ) {
	$params  = $request->get_params();
	$project = hpv_p_get( 'project', absint( $params['project_id'] ?? 0 ) );
	if ( ! $project ) {
		return new WP_Error( 'project', 'A projekt nem található.', array( 'status' => 400 ) );
	}
	$data = hpv_p_sanitize( 'task', $params );
	if ( '' === trim( $data['title'] ?? '' ) ) {
		return new WP_Error( 'title', 'Adj címet a feladatnak.', array( 'status' => 400 ) );
	}
	if ( ! empty( $data['parent_id'] ) ) {
		$parent = hpv_p_get( 'task', (int) $data['parent_id'] );
		if ( ! $parent || (int) $parent['project_id'] !== (int) $project['id'] ) {
			return new WP_Error( 'parent', 'Érvénytelen szülő feladat.', array( 'status' => 400 ) );
		}
	}
	$data['status']     = $data['status'] ?? 'todo';
	$data['sort']       = hpv_pm_next_sort( (int) $project['id'], $data['status'] );
	$data['created_by'] = get_current_user_id();
	if ( ! isset( $params['visible'] ) ) {
		$data['visible'] = empty( $data['parent_id'] ) ? 1 : 0;
	}
	$id   = hpv_p_insert( 'task', $data );
	$task = hpv_p_get( 'task', $id );
	hpv_pm_after_task_change( null, $task );

	return rest_ensure_response( hpv_pm_format_task( $task, hpv_pm_task_stats( array( $id ) )[ $id ] ) );
}

function hpv_pm_rest_update_task( WP_REST_Request $request ) {
	$task = hpv_pm_update_task( (int) $request['id'], $request->get_params() );
	if ( ! $task ) {
		return hpv_pm_not_found();
	}
	$id = (int) $task['id'];

	return rest_ensure_response( hpv_pm_format_task( $task, hpv_pm_task_stats( array( $id ) )[ $id ] ) );
}

/**
 * Kanban: az oszlop teljes sorrendje egyben (a húzás után a kliens elküldi az oszlop id-listáját).
 */
function hpv_pm_rest_reorder( WP_REST_Request $request ) {
	$status = sanitize_key( (string) $request->get_param( 'status' ) );
	if ( ! isset( hpv_p_entity( 'task' )['fields']['status']['options'][ $status ] ) ) {
		return new WP_Error( 'status', 'Érvénytelen státusz.', array( 'status' => 400 ) );
	}
	foreach ( array_values( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) as $i => $id ) {
		$old = hpv_p_get( 'task', $id );
		if ( ! $old ) {
			continue;
		}
		$data = array( 'sort' => ( $i + 1 ) * 10 );
		if ( $old['status'] !== $status ) {
			$data['status']       = $status;
			$data['completed_at'] = 'done' === $status ? current_time( 'mysql', true ) : null;
		}
		hpv_p_update( 'task', $id, $data );
		if ( isset( $data['status'] ) ) {
			hpv_pm_after_task_change( $old, hpv_p_get( 'task', $id ) );
		}
	}

	return rest_ensure_response( array( 'ok' => true ) );
}

function hpv_pm_rest_task( WP_REST_Request $request ) {
	$task = hpv_p_get( 'task', (int) $request['id'] );
	if ( ! $task ) {
		return hpv_pm_not_found();
	}
	$id       = (int) $task['id'];
	$subtasks = hpv_p_find( 'task', array( 'parent_id' => $id ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
	$stats    = hpv_pm_task_stats( array_merge( array( $id ), array_map( fn( $t ) => (int) $t['id'], $subtasks ) ) );
	$out      = hpv_pm_format_task( $task, $stats[ $id ] );

	$out['description'] = $task['description'];
	$out['created_by']  = hpv_pm_user( (int) $task['created_by'] );
	$out['created_at']  = mysql2date( 'c', $task['created_at'] . ' +0000', false );
	$out['project']     = hpv_pm_format_project( hpv_p_get( 'project', (int) $task['project_id'] ) ?: array( 'id' => 0, 'name' => '', 'description' => '', 'status' => '', 'client_id' => 0, 'owner_id' => 0, 'color' => '', 'start_date' => null, 'due_date' => null, 'visible' => 0, 'is_template' => 0 ), false );
	$out['parent']      = $task['parent_id'] ? hpv_p_get( 'task', (int) $task['parent_id'] ) : null;
	if ( $out['parent'] ) {
		$out['parent'] = array(
			'id'    => (int) $out['parent']['id'],
			'title' => $out['parent']['title'],
		);
	}
	$out['subtasks']  = array_map( fn( $t ) => hpv_pm_format_task( $t, $stats[ (int) $t['id'] ] ), $subtasks );
	$out['checklist'] = array_map(
		fn( $c ) => array(
			'id'    => (int) $c['id'],
			'title' => $c['title'],
			'done'  => (bool) $c['done'],
		),
		hpv_p_find( 'checklist_item', array( 'task_id' => $id ), array( 'orderby' => 'sort', 'order' => 'ASC' ) )
	);
	$out['comments']  = array_map(
		fn( $c ) => array(
			'id'   => (int) $c['id'],
			'user' => hpv_pm_user( (int) $c['user_id'] ),
			'body' => $c['body'],
			'time' => mysql2date( 'c', $c['created_at'] . ' +0000', false ),
			'mine' => (int) $c['user_id'] === get_current_user_id(),
		),
		hpv_p_find( 'task_comment', array( 'task_id' => $id ), array( 'order' => 'ASC' ) )
	);
	$out['time']      = array_map(
		fn( $e ) => array(
			'id'      => (int) $e['id'],
			'user'    => hpv_pm_user( (int) $e['user_id'] ),
			'minutes' => (int) $e['minutes'],
			'date'    => $e['work_date'],
			'note'    => $e['note'],
			'running' => (int) $e['started_at'] > 0,
			'mine'    => (int) $e['user_id'] === get_current_user_id(),
		),
		hpv_p_find( 'time_entry', array( 'task_id' => $id ) )
	);
	$out['links']     = array_map(
		function ( $l ) {
			$dep = hpv_p_get( 'task', (int) $l['depends_on'] );
			return array(
				'id'         => (int) $l['id'],
				'depends_on' => (int) $l['depends_on'],
				'title'      => $dep ? $dep['title'] : '—',
				'done'       => $dep && 'done' === $dep['status'],
			);
		},
		hpv_p_find( 'task_link', array( 'task_id' => $id ) )
	);
	$out['timer']     = hpv_pm_format_timer( hpv_pm_running_timer( get_current_user_id() ) );

	return rest_ensure_response( $out );
}

function hpv_pm_rest_delete_task( WP_REST_Request $request ) {
	if ( ! hpv_p_get( 'task', (int) $request['id'] ) ) {
		return hpv_pm_not_found();
	}
	hpv_p_delete( 'task', (int) $request['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

function hpv_pm_rest_comment( WP_REST_Request $request ) {
	$task = hpv_p_get( 'task', (int) $request['id'] );
	$body = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
	if ( ! $task ) {
		return hpv_pm_not_found();
	}
	if ( '' === $body ) {
		return new WP_Error( 'empty', 'Üres hozzászólás.', array( 'status' => 400 ) );
	}
	$id = hpv_p_insert(
		'task_comment',
		array(
			'task_id' => $task['id'],
			'user_id' => get_current_user_id(),
			'body'    => $body,
		)
	);

	// A felelős (ha nem ő írta) értesítést kap.
	if ( (int) $task['assignee_id'] && (int) $task['assignee_id'] !== get_current_user_id() ) {
		$user = get_userdata( (int) $task['assignee_id'] );
		if ( $user ) {
			hpv_p_send(
				$user->user_email,
				sprintf( 'Hozzászólás: %s', $task['title'] ),
				hpv_p_email_html( sprintf( '%s hozzászólt: %s', wp_get_current_user()->display_name, $task['title'] ), '<p>' . nl2br( esc_html( $body ) ) . '</p>', 'Megnyitás', hpv_p_crm_app_url( '/projects/' . (int) $task['project_id'] . '?task=' . (int) $task['id'] ) )
			);
		}
	}

	$c = hpv_p_get( 'task_comment', $id );

	return rest_ensure_response(
		array(
			'id'   => $id,
			'user' => hpv_pm_user( get_current_user_id() ),
			'body' => $c['body'],
			'time' => mysql2date( 'c', $c['created_at'] . ' +0000', false ),
			'mine' => true,
		)
	);
}

function hpv_pm_rest_add_checklist( WP_REST_Request $request ) {
	$task  = hpv_p_get( 'task', (int) $request['id'] );
	$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
	if ( ! $task || '' === $title ) {
		return new WP_Error( 'invalid', 'Érvénytelen kérés.', array( 'status' => 400 ) );
	}
	$count = count( hpv_p_find( 'checklist_item', array( 'task_id' => $task['id'] ) ) );
	$id    = hpv_p_insert(
		'checklist_item',
		array(
			'task_id' => $task['id'],
			'title'   => $title,
			'sort'    => $count,
		)
	);

	return rest_ensure_response(
		array(
			'id'    => $id,
			'title' => $title,
			'done'  => false,
		)
	);
}

function hpv_pm_rest_update_checklist( WP_REST_Request $request ) {
	$item = hpv_p_get( 'checklist_item', (int) $request['id'] );
	if ( ! $item ) {
		return hpv_pm_not_found();
	}
	$data = hpv_p_sanitize( 'checklist_item', array_intersect_key( $request->get_params(), array_flip( array( 'title', 'done' ) ) ) );
	if ( isset( $data['title'] ) && '' === $data['title'] ) {
		unset( $data['title'] );
	}
	if ( $data ) {
		hpv_p_update( 'checklist_item', (int) $item['id'], $data );
	}
	$item = hpv_p_get( 'checklist_item', (int) $item['id'] );

	return rest_ensure_response(
		array(
			'id'    => (int) $item['id'],
			'title' => $item['title'],
			'done'  => (bool) $item['done'],
		)
	);
}

function hpv_pm_rest_delete_checklist( WP_REST_Request $request ) {
	hpv_p_delete( 'checklist_item', (int) $request['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

function hpv_pm_rest_timer( WP_REST_Request $request ) {
	$me   = get_current_user_id();
	$task = hpv_p_get( 'task', (int) $request['id'] );
	if ( ! $task ) {
		return hpv_pm_not_found();
	}

	// Egyszerre egy stopper futhat: indításkor a korábbi leáll.
	hpv_pm_stop_timer( $me );
	if ( 'start' === $request->get_param( 'action' ) ) {
		hpv_p_insert(
			'time_entry',
			array(
				'task_id'    => $task['id'],
				'user_id'    => $me,
				'minutes'    => 0,
				'work_date'  => current_time( 'Y-m-d' ),
				'started_at' => time(),
			)
		);
	}

	return rest_ensure_response( hpv_pm_format_timer( hpv_pm_running_timer( $me ) ) );
}

function hpv_pm_rest_add_time( WP_REST_Request $request ) {
	$task    = hpv_p_get( 'task', (int) $request['id'] );
	$minutes = absint( $request->get_param( 'minutes' ) );
	if ( ! $task || $minutes < 1 || $minutes > 24 * 60 ) {
		return new WP_Error( 'invalid', 'Adj meg 1 és 1440 közötti percet.', array( 'status' => 400 ) );
	}
	$date = (string) $request->get_param( 'date' );
	$id   = hpv_p_insert(
		'time_entry',
		array(
			'task_id'   => $task['id'],
			'user_id'   => get_current_user_id(),
			'minutes'   => $minutes,
			'work_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' ),
			'note'      => sanitize_text_field( (string) $request->get_param( 'note' ) ),
		)
	);

	return rest_ensure_response( array( 'id' => $id ) );
}

function hpv_pm_rest_delete_time( WP_REST_Request $request ) {
	$entry = hpv_p_get( 'time_entry', (int) $request['id'] );
	if ( ! $entry ) {
		return hpv_pm_not_found();
	}
	if ( (int) $entry['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', 'Csak a saját időbejegyzésed törölheted.', array( 'status' => 403 ) );
	}
	hpv_p_delete( 'time_entry', (int) $entry['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

function hpv_pm_rest_add_link( WP_REST_Request $request ) {
	$task = hpv_p_get( 'task', (int) $request['id'] );
	$dep  = hpv_p_get( 'task', absint( $request->get_param( 'depends_on' ) ) );
	if ( ! $task || ! $dep || (int) $dep['id'] === (int) $task['id'] || (int) $dep['project_id'] !== (int) $task['project_id'] ) {
		return new WP_Error( 'invalid', 'Érvénytelen függőség.', array( 'status' => 400 ) );
	}
	// Körkörös függőség tiltása (A → B → A).
	if ( hpv_pm_depends_on( (int) $dep['id'], (int) $task['id'] ) ) {
		return new WP_Error( 'cycle', 'Ez körkörös függőséget hozna létre.', array( 'status' => 400 ) );
	}
	if ( ! hpv_p_find( 'task_link', array( 'task_id' => $task['id'], 'depends_on' => $dep['id'] ) ) ) {
		hpv_p_insert(
			'task_link',
			array(
				'task_id'    => $task['id'],
				'depends_on' => $dep['id'],
			)
		);
	}

	return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * Függ-e (közvetve is) $task_id a $target-től.
 */
function hpv_pm_depends_on( int $task_id, int $target, int $depth = 0 ): bool {
	if ( $depth > 50 ) {
		return true;
	}
	foreach ( hpv_p_find( 'task_link', array( 'task_id' => $task_id ) ) as $link ) {
		if ( (int) $link['depends_on'] === $target || hpv_pm_depends_on( (int) $link['depends_on'], $target, $depth + 1 ) ) {
			return true;
		}
	}

	return false;
}

function hpv_pm_rest_delete_link( WP_REST_Request $request ) {
	hpv_p_delete( 'task_link', (int) $request['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

function hpv_pm_rest_my_tasks( WP_REST_Request $request ) {
	$user  = absint( $request->get_param( 'user' ) ) ?: get_current_user_id();
	$tasks = array_values( array_filter( hpv_p_find( 'task', array( 'assignee_id' => $user ), array( 'orderby' => 'due_date', 'order' => 'ASC', 'limit' => 1000 ) ), fn( $t ) => 'done' !== $t['status'] ) );
	$stats = hpv_pm_task_stats( array_map( fn( $t ) => (int) $t['id'], $tasks ) );

	$projects = array();
	$out      = array();
	foreach ( $tasks as $t ) {
		$pid = (int) $t['project_id'];
		if ( ! isset( $projects[ $pid ] ) ) {
			$p                = hpv_p_get( 'project', $pid );
			$projects[ $pid ] = $p ? array(
				'id'     => $pid,
				'name'   => $p['name'],
				'color'  => $p['color'] ?: '#b8ff34',
				'client' => $p['client_id'] ? hpv_p_client_name_safe( (int) $p['client_id'] ) : '',
			) : null;
		}
		if ( ! $projects[ $pid ] ) {
			continue;
		}
		$row            = hpv_pm_format_task( $t, $stats[ (int) $t['id'] ] );
		$row['project'] = $projects[ $pid ];
		$out[]          = $row;
	}

	return rest_ensure_response( $out );
}

function hpv_pm_rest_search( WP_REST_Request $request ) {
	$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
	if ( mb_strlen( $q ) < 2 ) {
		return rest_ensure_response( array() );
	}
	$out = array();
	foreach ( hpv_p_find( 'project', array( 'is_template' => 0 ), array( 'search' => $q, 'limit' => 8 ) ) as $p ) {
		$out[] = array( 'type' => 'project', 'id' => (int) $p['id'], 'title' => $p['name'], 'meta' => $p['client_id'] ? hpv_p_client_name_safe( (int) $p['client_id'] ) : '' );
	}
	foreach ( hpv_p_find( 'task', array(), array( 'search' => $q, 'limit' => 12 ) ) as $t ) {
		$p     = hpv_p_get( 'project', (int) $t['project_id'] );
		$out[] = array( 'type' => 'task', 'id' => (int) $t['id'], 'project_id' => (int) $t['project_id'], 'title' => $t['title'], 'meta' => $p ? $p['name'] : '' );
	}
	foreach ( hpv_p_find( 'client', array(), array( 'search' => $q, 'limit' => 8 ) ) as $c ) {
		$out[] = array( 'type' => 'client', 'id' => (int) $c['id'], 'title' => $c['name'], 'meta' => $c['contact_name'] );
	}

	return rest_ensure_response( $out );
}
