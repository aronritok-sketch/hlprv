<?php
/**
 * Havidíjas (retainer) projektek: SEO, helyi SEO, tartalom, hirdetés, közösségi média.
 *
 * A projekthez egy sablon („havi feladatcsomag”) tartozik. Minden hónapban a megadott napon a sablon feladatai
 * újra létrejönnek a projektben, a hónap jelölésével (task.period = ÉÉÉÉ-HH), a dátumok a hónap kezdőnapjához
 * igazodnak. Így a havi munka egy projektben marad, hónaponként szűrhető, és a havi riport ebből dolgozik.
 */

defined( 'ABSPATH' ) || exit;

function hpv_retainer_is( array $project ): bool {
	return (int) ( $project['package_id'] ?? 0 ) > 0 && ! (int) $project['is_template'];
}

/**
 * A havi csomag kezdőnapja: a megadott nap, de legfeljebb a hónap utolsó napja.
 */
function hpv_retainer_start( string $period, int $day ): string {
	$last = (int) gmdate( 't', strtotime( $period . '-01' ) );

	return sprintf( '%s-%02d', $period, min( max( 1, $day ), $last ) );
}

/**
 * Egy hónap feladatcsomagjának létrehozása. Ugyanarra a hónapra csak egyszer.
 *
 * @return int[]|WP_Error A létrehozott feladatok.
 */
function hpv_retainer_create( int $project_id, string $period ) {
	$project = hpv_p_get( 'project', $project_id );
	if ( ! $project || ! hpv_retainer_is( $project ) ) {
		return new WP_Error( 'not_retainer', 'Ennek a projektnek nincs havi feladatcsomagja.' );
	}
	if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
		return new WP_Error( 'period', 'Érvénytelen hónap.' );
	}
	if ( hpv_p_find( 'task', array( 'project_id' => $project_id, 'period' => $period ), array( 'limit' => 1 ) ) ) {
		return new WP_Error( 'exists', 'Ennek a hónapnak a csomagja már elkészült.' );
	}
	$template = hpv_p_get( 'project', (int) $project['package_id'] );
	if ( ! $template ) {
		return new WP_Error( 'template', 'A havi csomag sablonja már nem létezik.' );
	}
	// A sablon dátumai a sablon kezdőnapjához képest értendők; ha nincs megadva, a legkorábbi feladat-dátum a kezdőnap.
	if ( ! $template['start_date'] ) {
		$dates = array();
		foreach ( hpv_p_find( 'task', array( 'project_id' => (int) $template['id'] ), array( 'limit' => 2000 ) ) as $t ) {
			$dates = array_merge( $dates, array_filter( array( $t['start_date'], $t['due_date'] ) ) );
		}
		hpv_p_update( 'project', (int) $template['id'], array( 'start_date' => $dates ? min( $dates ) : current_time( 'Y-m-d' ) ) );
	}

	$ids = hpv_pm_copy_template( (int) $template['id'], $project_id, hpv_retainer_start( $period, (int) $project['package_day'] ) );
	foreach ( $ids as $id ) {
		hpv_p_update( 'task', $id, array( 'period' => $period ) );
	}
	if ( $period > (string) $project['last_package'] ) {
		hpv_p_update( 'project', $project_id, array( 'last_package' => $period ) );
	}
	if ( (int) $project['client_id'] ) {
		hpv_p_log( (int) $project['client_id'], 'system', sprintf( 'Havi feladatcsomag: %s — %s (%d feladat).', $project['name'], $period, count( $ids ) ), false );
	}

	return $ids;
}

/**
 * Napi futás: az esedékes havi csomagok.
 */
function hpv_retainer_run( ?string $today = null ): array {
	$today   = $today ?? current_time( 'Y-m-d' );
	$period  = substr( $today, 0, 7 );
	$day     = (int) substr( $today, 8, 2 );
	$created = array();
	foreach ( hpv_p_find( 'project', array( 'is_template' => 0 ), array( 'limit' => 5000 ) ) as $p ) {
		if ( ! hpv_retainer_is( $p ) || ! in_array( $p['status'], array( 'planning', 'in_progress', 'review' ), true ) ) {
			continue;
		}
		$due_day = min( max( 1, (int) $p['package_day'] ), (int) gmdate( 't', strtotime( $today ) ) );
		if ( (string) $p['last_package'] >= $period || $day < $due_day ) {
			continue;
		}
		$ids = hpv_retainer_create( (int) $p['id'], $period );
		if ( ! is_wp_error( $ids ) ) {
			$created[ (int) $p['id'] ] = count( $ids );
		} else {
			// Kézzel már elkészült: csak a jelölőt léptetjük.
			hpv_p_update( 'project', (int) $p['id'], array( 'last_package' => $period ) );
		}
	}

	return $created;
}

add_action( 'init', 'hpv_retainer_schedule' );

function hpv_retainer_schedule() {
	if ( ! wp_next_scheduled( 'hpv_retainer_cron' ) ) {
		$next = strtotime( current_time( 'Y-m-d' ) . ' 06:00:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		wp_schedule_event( $next > time() ? $next : $next + DAY_IN_SECONDS, 'daily', 'hpv_retainer_cron' );
	}
}

add_action( 'hpv_retainer_cron', 'hpv_retainer_run' );
register_deactivation_hook( HPV_PORTAL_FILE, fn() => wp_clear_scheduled_hook( 'hpv_retainer_cron' ) );

/**
 * A projekt hónapjai (újabb elöl) és a feladatok szűrése egy hónapra (a hónap nélküli, egyszeri feladatok mindig látszanak).
 */
function hpv_retainer_periods( array $tasks ): array {
	$periods = array_unique( array_filter( array_map( fn( $t ) => (string) ( $t['period'] ?? '' ), $tasks ) ) );
	rsort( $periods );

	return array_values( $periods );
}

function hpv_retainer_filter( array $tasks, string $period ): array {
	return array_values( array_filter( $tasks, fn( $t ) => '' === (string) ( $t['period'] ?? '' ) || $period === $t['period'] ) );
}

/* ─── REST ────────────────────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_retainer_routes' );

function hpv_retainer_routes() {
	register_rest_route(
		'hpv/v1',
		'/pm/projects/(?P<id>\d+)/package',
		array(
			'methods'             => 'POST',
			'permission_callback' => fn() => hpv_p_is_staff(),
			'callback'            => function ( WP_REST_Request $req ) {
				$period = (string) ( $req['period'] ?: current_time( 'Y-m' ) );
				$ids    = hpv_retainer_create( (int) $req['id'], $period );
				if ( is_wp_error( $ids ) ) {
					return new WP_Error( $ids->get_error_code(), $ids->get_error_message(), array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'created' => count( $ids ), 'period' => $period ) );
			},
		)
	);
}

/**
 * A portál projekt-feladatai; havidíjas projektnél csak a legutóbbi hónapé (a haladás így havonta értendő).
 */
function hpv_retainer_current_tasks( array $project ): array {
	$tasks = hpv_p_portal_tasks( (int) $project['id'] );
	if ( ! hpv_retainer_is( $project ) ) {
		return $tasks;
	}
	$periods = hpv_retainer_periods( $tasks );

	return $periods ? hpv_retainer_filter( $tasks, $periods[0] ) : $tasks;
}
