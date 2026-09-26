<?php
/**
 * Havi riport az ügyfélnek, az ügyfél nyelvén.
 *
 * Összerakás (hpv_report_build): a hónap számai a bekötött forrásokból (hpv_report_metrics szűrő: Search Console,
 * GA4, Google Ads, Meta Ads — connectors.php; SEO OS is ide adhat), az elvégzett munka (a hónapban lezárt látható
 * feladatok, megjelent tartalmak), a következő hónap (látható feladatok határidővel), és AI-összefoglaló.
 * A csapat a CRM appban átnézi, szerkeszti, kiküldi; az ügyfél a portál Riportok menüjében látja, nyomtatható.
 * Beállítás szerint a hónap elején magától elkészül a piszkozat a havidíjas projektes ügyfeleknek.
 */

defined( 'ABSPATH' ) || exit;

function hpv_report_data( array $r ): array {
	$d = json_decode( (string) $r['data'], true );

	return array_merge( array( 'metrics' => array(), 'highlights' => array(), 'work' => array(), 'next' => array(), 'sources' => array() ), is_array( $d ) ? $d : array() );
}

function hpv_report_prev_period( string $period ): string {
	return gmdate( 'Y-m', strtotime( $period . '-01 -1 month' ) );
}

/**
 * Egy mutató szövegként: 1 234 / 1,234 · 3,4% · $120.00 · 12,3 (átlagos helyezés).
 */
function hpv_report_fmt( $value, string $format, string $currency, string $lang ): string {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	$hu  = 'hu' === $lang;
	$num = fn( $v, $dec ) => number_format( (float) $v, $dec, $hu ? ',' : '.', $hu ? "\u{00A0}" : ',' );
	switch ( $format ) {
		case 'pct':
			return $num( $value, 1 ) . ( $hu ? "\u{00A0}%" : '%' );
		case 'money':
			return hpv_p_money( (int) round( (float) $value * 100 ), $currency );
		case 'decimal':
		case 'position':
			return $num( $value, 1 );
		default:
			return $num( $value, 0 );
	}
}

/**
 * Változás az előző hónaphoz: szöveg + jó vagy rossz irányú-e.
 */
function hpv_report_delta( array $m ): array {
	if ( ! isset( $m['prev'] ) || null === $m['prev'] || '' === $m['prev'] || ! is_numeric( $m['value'] ?? null ) || (float) $m['prev'] == 0 ) {
		return array( 'text' => '', 'good' => null );
	}
	$diff = ( (float) $m['value'] - (float) $m['prev'] ) / abs( (float) $m['prev'] ) * 100;
	if ( 'position' === ( $m['format'] ?? '' ) ) {
		$diff = (float) $m['prev'] - (float) $m['value']; // helyezés: a kisebb a jobb, helyben mérve
		$good = $diff > 0;
		return array( 'text' => ( $diff > 0 ? '▲ ' : ( $diff < 0 ? '▼ ' : '' ) ) . number_format( abs( $diff ), 1, 'hu' === hpv_lang() ? ',' : '.', '' ), 'good' => abs( $diff ) < 0.05 ? null : $good );
	}
	$better = ( $m['better'] ?? 'up' ) === 'down' ? $diff < 0 : $diff > 0;
	$text   = ( $diff > 0 ? '+' : '' ) . number_format( $diff, 0 ) . '%';

	// Költés: nem jó vagy rossz, csak változás.
	return array( 'text' => $text, 'good' => abs( $diff ) < 0.5 || 'neutral' === ( $m['better'] ?? '' ) ? null : $better );
}

/**
 * Az elvégzett munka: a hónapban lezárt, az ügyfél által látható feladatok és a megjelent tartalmak.
 */
function hpv_report_work( int $client_id, string $period ): array {
	$work = array();
	foreach ( hpv_p_find( 'project', array( 'client_id' => $client_id ), array( 'limit' => 500 ) ) as $p ) {
		if ( ! (int) $p['visible'] || (int) $p['is_template'] ) {
			continue;
		}
		foreach ( hpv_p_find( 'task', array( 'project_id' => (int) $p['id'], 'status' => 'done' ), array( 'limit' => 2000 ) ) as $t ) {
			$done = $t['completed_at'] ? substr( get_date_from_gmt( $t['completed_at'], 'Y-m-d' ), 0, 7 ) : '';
			if ( (int) $t['visible'] && ! (int) $t['parent_id'] && ( $done === $period || ( '' === $done && ( $t['period'] ?? '' ) === $period ) ) ) {
				$work[] = array( 'text' => $t['title'], 'group' => $p['name'] );
			}
		}
	}
	foreach ( hpv_p_find( 'approval', array( 'client_id' => $client_id, 'status' => 'published' ), array( 'limit' => 500 ) ) as $a ) {
		if ( 0 === strpos( (string) $a['publish_date'], $period ) ) {
			$work[] = array( 'text' => hpv_t( hpv_p_option_label( 'approval', 'type', $a['type'], 'en' ) ) . ': ' . $a['title'], 'group' => hpv_t( 'Published content' ) );
		}
	}

	return $work;
}

function hpv_report_next( int $client_id, string $period ): array {
	$next = gmdate( 'Y-m', strtotime( $period . '-01 +1 month' ) );
	$out  = array();
	foreach ( hpv_p_find( 'project', array( 'client_id' => $client_id ), array( 'limit' => 500 ) ) as $p ) {
		if ( ! (int) $p['visible'] || (int) $p['is_template'] || in_array( $p['status'], array( 'completed', 'on_hold' ), true ) ) {
			continue;
		}
		foreach ( hpv_p_find( 'task', array( 'project_id' => (int) $p['id'] ), array( 'limit' => 2000 ) ) as $t ) {
			if ( (int) $t['visible'] && ! (int) $t['parent_id'] && 'done' !== $t['status'] && 0 === strpos( (string) $t['due_date'], $next ) ) {
				$out[] = array( 'text' => $t['title'], 'group' => $p['name'] );
			}
		}
	}

	return array_slice( $out, 0, 15 );
}

/**
 * A riport összerakása (új vagy frissítés). A kézzel írt összefoglalót frissítéskor csak kérésre írja felül.
 *
 * @return array|WP_Error
 */
function hpv_report_build( int $client_id, string $period, int $user_id, bool $ai = true, int $report_id = 0 ) {
	$client = hpv_p_get( 'client', $client_id );
	if ( ! $client || ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
		return new WP_Error( 'input', 'Érvénytelen ügyfél vagy hónap.' );
	}
	$existing = $report_id ? hpv_p_get( 'report', $report_id ) : ( hpv_p_find( 'report', array( 'client_id' => $client_id, 'period' => $period ), array( 'limit' => 1 ) )[0] ?? null );
	if ( $existing && 'sent' === $existing['status'] ) {
		return new WP_Error( 'sent', 'A kiküldött riport nem építhető újra.' );
	}
	$lang = hpv_doc_client_language( $client_id );

	return hpv_with_lang(
		$lang,
		function () use ( $client, $client_id, $period, $user_id, $ai, $existing, $lang ) {
			$sources = array();
			$metrics = apply_filters( 'hpv_report_metrics', array(), $client, $period, hpv_report_prev_period( $period ) );
			$metrics = array_values( array_filter( (array) $metrics, fn( $m ) => is_array( $m ) && ! empty( $m['label'] ) ) );
			foreach ( $metrics as $m ) {
				$sources[ $m['section'] ?? '' ] = true;
			}
			$old  = $existing ? hpv_report_data( $existing ) : array();
			// A kézzel felvett mutatók megmaradnak.
			$manual = array_values( array_filter( (array) ( $old['metrics'] ?? array() ), fn( $m ) => ! empty( $m['manual'] ) ) );
			$data   = array(
				'metrics'    => array_merge( $metrics, $manual ),
				'work'       => hpv_report_work( $client_id, $period ),
				'next'       => hpv_report_next( $client_id, $period ),
				'highlights' => $old['highlights'] ?? array(),
				'sources'    => array_keys( array_filter( $sources ) ),
				'errors'     => apply_filters( 'hpv_report_errors', array(), $client, $period ),
			);
			$summary = $existing ? (string) $existing['summary'] : '';

			if ( $ai && hpv_ai_enabled() ) {
				$gen = hpv_report_ai( $client, $period, $data, $lang );
				if ( ! is_wp_error( $gen ) ) {
					$summary            = $gen['summary'];
					$data['highlights'] = $gen['highlights'];
					if ( $gen['next'] && ! $data['next'] ) {
						$data['next'] = $gen['next'];
					}
				} else {
					$data['errors'][] = 'AI: ' . $gen->get_error_message();
				}
			}

			$row = array(
				'client_id' => $client_id,
				'period'    => $period,
				'title'     => hpv_t( 'Monthly report — %s', hpv_date( $period . '-01', 'month' ) ),
				'summary'   => $summary,
				'data'      => wp_json_encode( $data ),
			);
			if ( $existing ) {
				hpv_p_update( 'report', (int) $existing['id'], $row );
				return hpv_p_get( 'report', (int) $existing['id'] );
			}
			$id = hpv_p_insert( 'report', array_merge( $row, array( 'status' => 'draft', 'created_by' => $user_id ) ) );

			return $id ? hpv_p_get( 'report', $id ) : new WP_Error( 'db', 'Az adatbázisba írás nem sikerült.' );
		}
	);
}

/**
 * AI-összefoglaló: csak a megadott számokból és munkából, az ügyfél nyelvén, ügyfélbarátan.
 *
 * @return array|WP_Error { summary, highlights[], next[] }
 */
function hpv_report_ai( array $client, string $period, array $data, string $lang ) {
	$lines = array();
	foreach ( $data['metrics'] as $m ) {
		$lines[] = sprintf( '- [%s] %s: %s (previous month: %s)', $m['section'] ?? '', $m['label'], $m['value'] ?? 'n/a', $m['prev'] ?? 'n/a' );
	}
	$work = array_map( fn( $w ) => '- ' . $w['text'] . ' (' . $w['group'] . ')', $data['work'] );
	$next = array_map( fn( $w ) => '- ' . $w['text'], $data['next'] );

	$system = 'You write the monthly marketing/SEO report summary that a digital agency (HelloProVision) sends to its client. '
		. 'Write in ' . ( 'hu' === $lang ? 'Hungarian, informal (tegező) but professional' : 'American English' ) . '. '
		. 'Use ONLY the numbers and work items given; never invent figures, causes, or plans. If data is missing, do not mention it. '
		. 'Plain language for a business owner, no jargon, no hype, no emojis. Explain what changed and why it matters in one or two sentences each. '
		. 'Return JSON only: {"summary_html": "<p>…</p><p>…</p> (2-3 short paragraphs)", "highlights": ["3-5 short bullet sentences, each with a number where possible"], "next": ["up to 5 next steps, only if they follow from the work list or planned tasks"]}';
	$user = "Client: {$client['name']}\nMonth: {$period}\n\nMetrics:\n" . ( $lines ? implode( "\n", $lines ) : '(none)' )
		. "\n\nWork completed this month:\n" . ( $work ? implode( "\n", $work ) : '(none)' )
		. "\n\nPlanned for next month:\n" . ( $next ? implode( "\n", $next ) : '(none)' );

	$text = hpv_ai_complete( $system, $user, 2000, 90 );
	if ( is_wp_error( $text ) ) {
		return $text;
	}
	$json = hpv_ai_json( $text );
	if ( ! $json || empty( $json['summary_html'] ) ) {
		return new WP_Error( 'ai_format', 'Az AI válasza nem értelmezhető.' );
	}

	return array(
		'summary'    => hpv_doc_clean_html( (string) $json['summary_html'] ),
		'highlights' => array_values( array_slice( array_map( 'sanitize_text_field', (array) ( $json['highlights'] ?? array() ) ), 0, 6 ) ),
		'next'       => array_values( array_map( fn( $t ) => array( 'text' => sanitize_text_field( $t ), 'group' => '' ), array_slice( (array) ( $json['next'] ?? array() ), 0, 6 ) ) ),
	);
}

/**
 * Kiküldés: e-mail az ügyfélnek, a portálon megjelenik.
 *
 * @return array|WP_Error
 */
function hpv_report_send( int $id, int $user_id ) {
	$r = hpv_p_get( 'report', $id );
	if ( ! $r ) {
		return new WP_Error( 'not_found', 'Nincs ilyen riport.' );
	}
	if ( '' === trim( wp_strip_all_tags( (string) $r['summary'] ) ) ) {
		return new WP_Error( 'summary', 'Írj összefoglalót (vagy készíttesd el az AI-val) kiküldés előtt.' );
	}
	if ( hpv_doc_todos( (string) $r['summary'] ) ) {
		return new WP_Error( 'todo', 'Az összefoglalóban még van [[TODO]] jelölés.' );
	}
	hpv_p_update( 'report', $id, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ) );
	$r         = hpv_p_get( 'report', $id );
	$client_id = (int) $r['client_id'];
	hpv_with_client_lang(
		$client_id,
		function () use ( $r, $client_id ) {
			$data = hpv_report_data( $r );
			$list = $data['highlights'] ? '<ul>' . implode( '', array_map( fn( $h ) => '<li>' . esc_html( $h ) . '</li>', array_slice( $data['highlights'], 0, 4 ) ) ) . '</ul>' : '';
			hpv_p_notify_client(
				$client_id,
				hpv_t( 'Your monthly report: %s', hpv_date( $r['period'] . '-01', 'month' ) ),
				hpv_t( 'Your monthly report is ready' ),
				'<p>' . esc_html( hpv_t( 'Here is what happened in %s and what comes next.', hpv_date( $r['period'] . '-01', 'month' ) ) ) . '</p>' . $list,
				hpv_t( 'Open the report' ),
				hpv_p_portal_url( array( 'view' => 'reports', 'id' => (int) $r['id'] ) )
			);
		}
	);
	hpv_p_log_client( $client_id, 'Monthly report sent: %s', array( $r['period'] ), $user_id );

	return $r;
}

/* ─── Havi automatikus piszkozat ──────────────────────────── */

add_action( 'hpv_retainer_cron', 'hpv_report_auto' );

/**
 * A beállított napon (alap: 3.) az előző hónap riport-piszkozata minden ügyfélnek, akinek havidíjas vagy marketing projektje fut.
 */
function hpv_report_auto( ?string $today = null ): array {
	$s     = hpv_p_settings();
	$today = $today ?? current_time( 'Y-m-d' );
	if ( empty( $s['report_auto'] ) || (int) substr( $today, 8, 2 ) < max( 1, (int) ( $s['report_day'] ?? 3 ) ) ) {
		return array();
	}
	$period  = gmdate( 'Y-m', strtotime( substr( $today, 0, 7 ) . '-01 -1 month' ) );
	$clients = array();
	foreach ( hpv_p_find( 'project', array( 'is_template' => 0 ), array( 'limit' => 5000 ) ) as $p ) {
		if ( (int) $p['client_id'] && ( hpv_retainer_is( $p ) || 'web' !== ( $p['kind'] ?: 'web' ) ) && in_array( $p['status'], array( 'planning', 'in_progress', 'review' ), true ) ) {
			$clients[ (int) $p['client_id'] ] = true;
		}
	}
	$made = array();
	foreach ( array_keys( $clients ) as $cid ) {
		if ( hpv_p_find( 'report', array( 'client_id' => $cid, 'period' => $period ), array( 'limit' => 1 ) ) ) {
			continue;
		}
		$r = hpv_report_build( $cid, $period, 0 );
		if ( ! is_wp_error( $r ) ) {
			$made[] = $r;
		}
	}
	if ( $made ) {
		$rows = implode( '', array_map( fn( $r ) => '<li><a href="' . esc_url( hpv_p_crm_app_url( '/reports/' . (int) $r['id'] ) ) . '">' . esc_html( hpv_p_client_name_safe( (int) $r['client_id'] ) ) . '</a></li>', $made ) );
		hpv_p_notify_staff( sprintf( 'Havi riportok: %d piszkozat (%s)', count( $made ), $period ), '<p>Elkészültek a havi riportok piszkozatai. Nézd át és küldd ki őket:</p><ul>' . $rows . '</ul>', hpv_p_crm_app_url( '/reports?status=draft' ) );
	}

	return $made;
}

/* ─── REST (munkatársak) ──────────────────────────────────── */

function hpv_report_format( array $r, bool $full = false ): array {
	$data = hpv_report_data( $r );
	$out  = array(
		'id'        => (int) $r['id'],
		'client_id' => (int) $r['client_id'],
		'client'    => hpv_p_client_name_safe( (int) $r['client_id'] ),
		'period'    => $r['period'],
		'title'     => (string) $r['title'],
		'status'    => $r['status'],
		'sent_at'   => $r['sent_at'],
		'sources'   => $data['sources'],
	);
	if ( ! $full ) {
		return $out;
	}

	return array_merge(
		$out,
		array(
			'summary'    => (string) $r['summary'],
			'metrics'    => $data['metrics'],
			'highlights' => $data['highlights'],
			'work'       => $data['work'],
			'next'       => $data['next'],
			'errors'     => $data['errors'] ?? array(),
			'currency'   => hpv_p_client_currency( (int) $r['client_id'] ),
			'lang'       => hpv_doc_client_language( (int) $r['client_id'] ),
			'ai'         => hpv_ai_enabled(),
			'portal_url' => hpv_p_portal_url( array( 'view' => 'reports', 'id' => (int) $r['id'], 'preview_client' => (int) $r['client_id'] ) ),
		)
	);
}

add_action( 'rest_api_init', 'hpv_report_routes' );

function hpv_report_routes() {
	$staff = fn() => hpv_p_is_staff();
	$route = function ( string $path, string $methods, callable $cb ) use ( $staff ) {
		register_rest_route( 'hpv/v1', $path, array( 'methods' => $methods, 'permission_callback' => $staff, 'callback' => $cb ) );
	};
	$fail = fn( WP_Error $e ) => new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => 400 ) );

	$route(
		'/reports',
		'GET',
		function ( WP_REST_Request $r ) {
			$where = array();
			if ( $r['client_id'] ) {
				$where['client_id'] = absint( $r['client_id'] );
			}
			if ( in_array( $r['status'], array( 'draft', 'sent' ), true ) ) {
				$where['status'] = $r['status'];
			}
			$rows = hpv_p_find( 'report', $where, array( 'limit' => 1000 ) );
			usort( $rows, fn( $a, $b ) => strcmp( $b['period'], $a['period'] ) ?: strcmp( hpv_p_client_name_safe( (int) $a['client_id'] ), hpv_p_client_name_safe( (int) $b['client_id'] ) ) );
			return rest_ensure_response( array( 'reports' => array_map( 'hpv_report_format', $rows ), 'ai' => hpv_ai_enabled() ) );
		}
	);
	$route(
		'/reports',
		'POST',
		function ( WP_REST_Request $r ) use ( $fail ) {
			$period = preg_match( '/^\d{4}-\d{2}$/', (string) $r['period'] ) ? (string) $r['period'] : gmdate( 'Y-m', strtotime( current_time( 'Y-m' ) . '-01 -1 month' ) );
			$rep    = hpv_report_build( absint( $r['client_id'] ), $period, get_current_user_id(), false !== rest_sanitize_boolean( $r['ai'] ?? true ) );
			return is_wp_error( $rep ) ? $fail( $rep ) : rest_ensure_response( hpv_report_format( $rep, true ) );
		}
	);
	$route(
		'/reports/(?P<id>\d+)',
		'GET',
		function ( WP_REST_Request $r ) {
			$rep = hpv_p_get( 'report', (int) $r['id'] );
			return $rep ? rest_ensure_response( hpv_report_format( $rep, true ) ) : new WP_Error( 'not_found', 'Nincs ilyen riport.', array( 'status' => 404 ) );
		}
	);
	$route(
		'/reports/(?P<id>\d+)',
		'POST',
		function ( WP_REST_Request $r ) {
			$rep = hpv_p_get( 'report', (int) $r['id'] );
			if ( ! $rep ) {
				return new WP_Error( 'not_found', 'Nincs ilyen riport.', array( 'status' => 404 ) );
			}
			$update = array();
			if ( null !== $r['summary'] ) {
				$update['summary'] = hpv_doc_clean_html( (string) $r['summary'] );
			}
			if ( null !== $r['title'] ) {
				$update['title'] = sanitize_text_field( (string) $r['title'] );
			}
			$data = hpv_report_data( $rep );
			foreach ( array( 'highlights', 'work', 'next', 'metrics' ) as $key ) {
				if ( is_array( $r[ $key ] ) ) {
					$data[ $key ] = hpv_report_clean( $key, $r[ $key ] );
				}
			}
			$update['data'] = wp_json_encode( $data );
			hpv_p_update( 'report', (int) $rep['id'], $update );
			return rest_ensure_response( hpv_report_format( hpv_p_get( 'report', (int) $rep['id'] ), true ) );
		}
	);
	$route(
		'/reports/(?P<id>\d+)',
		'DELETE',
		function ( WP_REST_Request $r ) {
			$rep = hpv_p_get( 'report', (int) $r['id'] );
			if ( ! $rep || 'draft' !== $rep['status'] ) {
				return new WP_Error( 'status', 'Csak piszkozat törölhető.', array( 'status' => 400 ) );
			}
			hpv_p_delete( 'report', (int) $rep['id'] );
			return rest_ensure_response( array( 'deleted' => true ) );
		}
	);
	$route(
		'/reports/(?P<id>\d+)/(?P<action>rebuild|send|unsend)',
		'POST',
		function ( WP_REST_Request $r ) use ( $fail ) {
			$rep = hpv_p_get( 'report', (int) $r['id'] );
			if ( ! $rep ) {
				return new WP_Error( 'not_found', 'Nincs ilyen riport.', array( 'status' => 404 ) );
			}
			if ( 'rebuild' === $r['action'] ) {
				$res = hpv_report_build( (int) $rep['client_id'], $rep['period'], get_current_user_id(), rest_sanitize_boolean( $r['ai'] ?? true ), (int) $rep['id'] );
			} elseif ( 'send' === $r['action'] ) {
				$res = hpv_report_send( (int) $rep['id'], get_current_user_id() );
			} else {
				// Visszavonás javításhoz: a portálon eltűnik, amíg újra ki nem küldik.
				hpv_p_update( 'report', (int) $rep['id'], array( 'status' => 'draft' ) );
				$res = hpv_p_get( 'report', (int) $rep['id'] );
			}
			return is_wp_error( $res ) ? $fail( $res ) : rest_ensure_response( hpv_report_format( $res, true ) );
		}
	);
}

function hpv_report_clean( string $key, array $rows ): array {
	switch ( $key ) {
		case 'highlights':
			return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $rows ) ) ) );
		case 'metrics':
			$out = array();
			foreach ( $rows as $m ) {
				if ( ! is_array( $m ) || '' === trim( (string) ( $m['label'] ?? '' ) ) ) {
					continue;
				}
				$out[] = array(
					'section' => sanitize_text_field( (string) ( $m['section'] ?? '' ) ),
					'key'     => sanitize_key( (string) ( $m['key'] ?? '' ) ),
					'label'   => sanitize_text_field( (string) $m['label'] ),
					'value'   => is_numeric( $m['value'] ?? null ) ? (float) $m['value'] : null,
					'prev'    => is_numeric( $m['prev'] ?? null ) ? (float) $m['prev'] : null,
					'format'  => in_array( $m['format'] ?? '', array( 'int', 'pct', 'money', 'decimal', 'position' ), true ) ? $m['format'] : 'int',
					'better'  => in_array( $m['better'] ?? '', array( 'down', 'neutral' ), true ) ? $m['better'] : 'up',
					'manual'  => ! empty( $m['manual'] ),
					'history' => array_values( array_map( 'floatval', array_filter( (array) ( $m['history'] ?? array() ), 'is_numeric' ) ) ),
					'currency' => preg_match( '/^[A-Z]{3}$/', (string) ( $m['currency'] ?? '' ) ) ? $m['currency'] : '',
				);
			}
			return $out;
		default:
			return array_values(
				array_filter(
					array_map(
						fn( $w ) => array(
							'text'  => sanitize_text_field( (string) ( is_array( $w ) ? ( $w['text'] ?? '' ) : $w ) ),
							'group' => sanitize_text_field( (string) ( is_array( $w ) ? ( $w['group'] ?? '' ) : '' ) ),
						),
						$rows
					),
					fn( $w ) => '' !== $w['text']
				)
			);
	}
}

/* ─── Portál ──────────────────────────────────────────────── */

function hpv_pv_reports( int $client_id, int $id ) {
	if ( $id ) {
		hpv_pv_report( $client_id, $id );
		return;
	}
	$list = hpv_p_find( 'report', array( 'client_id' => $client_id, 'status' => 'sent' ), array( 'limit' => 200 ) );
	usort( $list, fn( $a, $b ) => strcmp( $b['period'], $a['period'] ) );
	hpv_p_portal_header( hpv_t( 'Reports' ), hpv_t( 'Your monthly results: numbers, work done and next steps.' ) );
	if ( ! $list ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'Your first monthly report will appear here.' ) ) . '</p>';
		return;
	}
	echo '<div class="hpv-cards">';
	foreach ( $list as $r ) {
		$data = hpv_report_data( $r );
		?>
		<a class="hpv-card-link" href="<?php echo esc_url( hpv_p_portal_link( 'reports', array( 'id' => $r['id'] ) ) ); ?>">
			<span class="hpv-mini-project__top"><strong><?php echo esc_html( hpv_date( $r['period'] . '-01', 'month' ) ); ?></strong></span>
			<?php if ( $data['highlights'] ) : ?><span class="hpv-muted"><?php echo esc_html( $data['highlights'][0] ); ?></span><?php endif; ?>
		</a>
		<?php
	}
	echo '</div>';
}

function hpv_report_bars( array $history, string $format, string $currency, string $lang ): string {
	$history = array_slice( array_values( array_filter( $history, 'is_numeric' ) ), -6 );
	if ( count( $history ) < 2 ) {
		return '';
	}
	$max = max( array_map( 'abs', $history ) ) ?: 1;
	$out = '<span class="hpv-bars" aria-hidden="true">';
	foreach ( $history as $i => $v ) {
		$h    = 'position' === $format ? max( 8, 100 - ( abs( $v ) / $max ) * 90 ) : max( 4, abs( $v ) / $max * 100 );
		$out .= '<span style="height:' . (int) $h . '%" class="' . ( count( $history ) - 1 === $i ? 'is-last' : '' ) . '" title="' . esc_attr( hpv_report_fmt( $v, $format, $currency, $lang ) ) . '"></span>';
	}

	return $out . '</span>';
}

function hpv_pv_report( int $client_id, int $id ) {
	$r = hpv_p_get( 'report', $id );
	if ( ! $r || (int) $r['client_id'] !== $client_id || 'sent' !== $r['status'] ) {
		hpv_p_portal_header( hpv_t( 'Not found' ), '', hpv_p_portal_link( 'reports' ) );
		return;
	}
	$data     = hpv_report_data( $r );
	$lang     = hpv_lang();
	$currency = hpv_p_client_currency( $client_id );
	$sections = array();
	foreach ( $data['metrics'] as $m ) {
		$sections[ $m['section'] ?: hpv_t( 'Results' ) ][] = $m;
	}
	?>
	<div class="hpv-doc-actions">
		<a class="hpv-back" href="<?php echo esc_url( hpv_p_portal_link( 'reports' ) ); ?>"><?php echo esc_html( hpv_t( '← All reports' ) ); ?></a>
		<button type="button" class="hpv-btn hpv-btn--ghost" onclick="window.print()"><?php echo esc_html( hpv_t( 'Download PDF' ) ); ?></button>
	</div>
	<article class="hpv-paper hpv-report">
		<header class="hpv-report__head">
			<div class="hpv-invoice__brand"><?php echo esc_html( hpv_p_settings()['company_name'] ); ?></div>
			<h1><?php echo esc_html( hpv_t( 'Monthly report' ) ); ?></h1>
			<p class="hpv-muted"><?php echo esc_html( hpv_p_client_name_safe( $client_id ) . ' · ' . hpv_date( $r['period'] . '-01', 'month' ) ); ?></p>
		</header>

		<?php if ( $data['highlights'] ) : ?>
			<ul class="hpv-report__highlights"><?php foreach ( $data['highlights'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
		<div class="hpv-contract__body"><?php echo wp_kses_post( $r['summary'] ); ?></div>

		<?php foreach ( $sections as $name => $rows ) : ?>
			<h2><?php echo esc_html( hpv_t( $name ) ); ?></h2>
			<div class="hpv-metrics">
				<?php foreach ( $rows as $m ) : ?>
					<?php $delta = hpv_report_delta( $m ); ?>
					<div class="hpv-metric">
						<span><?php echo esc_html( hpv_t( $m['label'] ) ); ?></span>
						<strong><?php echo esc_html( hpv_report_fmt( $m['value'] ?? null, $m['format'] ?? 'int', $m['currency'] ?? $currency, $lang ) ); ?></strong>
						<?php if ( $delta['text'] ) : ?>
							<small class="hpv-delta <?php echo null === $delta['good'] ? '' : ( $delta['good'] ? 'is-good' : 'is-bad' ); ?>"><?php echo esc_html( $delta['text'] ); ?> <?php echo esc_html( hpv_t( 'vs. previous month' ) ); ?></small>
						<?php endif; ?>
						<?php echo hpv_report_bars( (array) ( $m['history'] ?? array() ), $m['format'] ?? 'int', $m['currency'] ?? $currency, $lang ); // phpcs:ignore ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>

		<?php if ( $data['work'] ) : ?>
			<h2><?php echo esc_html( hpv_t( 'What we did this month' ) ); ?></h2>
			<ul class="hpv-report__list"><?php foreach ( $data['work'] as $w ) : ?><li><?php echo esc_html( $w['text'] ); ?><?php echo $w['group'] ? ' <small class="hpv-muted">· ' . esc_html( $w['group'] ) . '</small>' : ''; ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
		<?php if ( $data['next'] ) : ?>
			<h2><?php echo esc_html( hpv_t( 'Next month' ) ); ?></h2>
			<ul class="hpv-report__list"><?php foreach ( $data['next'] as $w ) : ?><li><?php echo esc_html( $w['text'] ); ?></li><?php endforeach; ?></ul>
		<?php endif; ?>
		<p class="hpv-muted hpv-report__foot"><?php echo esc_html( hpv_t( 'Questions about the report? Send us a message in the portal.' ) ); ?></p>
	</article>
	<?php
}
