<?php
/**
 * Pénzügyi automatizmusok:
 *
 * 1. Fizetési emlékeztetők: a lejárt, még nyitott számláról a beállított napokon (alap: a lejárat után 3, 7, 14 nappal)
 *    udvarias levél megy az ügyfélnek, az ügyfél nyelvén, a portál fizetési linkjével. Fizetés után nem megy több.
 * 2. Munkaidő a számlára: a rögzített, még ki nem számlázott idő projektenként vagy feladatonként tételként kerül egy
 *    piszkozat számlára (óradíj: az ügyfélnél megadott, különben a beállítás pénznemenként). A bejegyzés a számlához
 *    kötődik; a piszkozat törlésekor vagy a számla érvénytelenítésekor újra számlázható lesz.
 */

defined( 'ABSPATH' ) || exit;

/* ─── 1. Fizetési emlékeztetők ────────────────────────────── */

function hpv_dunning_days(): array {
	$days = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) ( hpv_p_settings()['reminder_days'] ?? '3,7,14' ) ) ) ) ) );
	sort( $days );

	return array_slice( $days, 0, 5 );
}

add_action( 'hpv_retainer_cron', 'hpv_dunning_run' );

/**
 * @return array A kiküldött emlékeztetők (számla azonosító → hányadik).
 */
function hpv_dunning_run( ?string $today = null ): array {
	if ( empty( hpv_p_settings()['payment_reminders'] ) ) {
		return array();
	}
	$today = $today ?? current_time( 'Y-m-d' );
	$days  = hpv_dunning_days();
	$sent  = array();
	foreach ( hpv_p_find( 'invoice', array( 'status' => 'sent' ), array( 'limit' => 5000 ) ) as $inv ) {
		$n = (int) $inv['reminders_sent'];
		if ( ! $inv['due_date'] || $n >= count( $days ) || hpv_p_invoice_balance( $inv ) <= 0 ) {
			continue;
		}
		$late = (int) floor( ( strtotime( $today ) - strtotime( $inv['due_date'] ) ) / DAY_IN_SECONDS );
		// Egy futás egy levél: ha több küszöb is elmúlt (pl. szünet után), csak a legutolsó számít, a korábbiak kimaradnak.
		$reach = count( array_filter( $days, fn( $d ) => $late >= $d ) );
		if ( $reach <= $n ) {
			continue;
		}
		if ( hpv_dunning_send( $inv, $reach, $late ) ) {
			hpv_p_update( 'invoice', (int) $inv['id'], array( 'reminders_sent' => $reach, 'last_reminder_at' => current_time( 'mysql', true ) ) );
			$sent[ (int) $inv['id'] ] = $reach;
		}
	}
	if ( $sent ) {
		$rows = '';
		foreach ( $sent as $id => $k ) {
			$inv   = hpv_p_get( 'invoice', $id );
			$rows .= '<li><a href="' . esc_url( hpv_p_crm_app_url( '/invoices/' . $id ) ) . '">' . esc_html( $inv['number'] . ' — ' . hpv_p_client_name_safe( (int) $inv['client_id'] ) ) . '</a>: ' . esc_html( hpv_p_money( hpv_p_invoice_balance( $inv ), hpv_p_invoice_currency( $inv ) ) ) . ' · ' . (int) $k . '. emlékeztető</li>';
		}
		hpv_p_notify_staff( sprintf( 'Fizetési emlékeztetők: %d db', count( $sent ) ), '<p>Ma ezekről a lejárt számlákról ment emlékeztető az ügyfélnek:</p><ul>' . $rows . '</ul>', hpv_p_crm_app_url( '/invoices?status=overdue' ) );
	}

	return $sent;
}

function hpv_dunning_send( array $inv, int $nth, int $late ): bool {
	$client_id = (int) $inv['client_id'];

	return (bool) hpv_with_client_lang(
		$client_id,
		function () use ( $inv, $nth, $late, $client_id ) {
			$cur     = hpv_p_invoice_currency( $inv );
			$balance = hpv_p_money( hpv_p_invoice_balance( $inv ), $cur );
			$last    = $nth >= count( hpv_dunning_days() );
			$body    = '<p>' . esc_html( hpv_t( 'This is a friendly reminder that invoice %s was due on %s and is still open.', $inv['number'], hpv_date( $inv['due_date'], 'long' ) ) ) . '</p>'
				. '<p><strong>' . esc_html( hpv_t( 'Amount due:' ) ) . '</strong> ' . esc_html( $balance ) . '</p>'
				. '<p>' . esc_html( hpv_t( 'If you have already paid, thank you — please ignore this email. If something is wrong with the invoice, just reply and we will sort it out.' ) ) . '</p>';
			$sent = hpv_p_notify_client(
				$client_id,
				( $last ? hpv_t( 'Final reminder: invoice %s is overdue', $inv['number'] ) : hpv_t( 'Reminder: invoice %s is overdue', $inv['number'] ) ),
				hpv_t( 'Payment reminder' ),
				$body,
				hpv_t( 'View & pay invoice' ),
				hpv_p_portal_url( array( 'view' => 'invoices', 'id' => (int) $inv['id'] ) )
			);
			if ( $sent ) {
				hpv_p_log( $client_id, 'system', sprintf( 'Fizetési emlékeztető (%d.): %s — %s, %d napja lejárt.', $nth, $inv['number'], $balance, $late ), false );
			}
			return $sent;
		}
	);
}

/* ─── 2. Munkaidő a számlára ─────────────────────────────── */

function hpv_time_rate( int $client_id, string $currency ): float {
	$client = hpv_p_get( 'client', $client_id );
	if ( $client && (float) $client['hourly_rate'] > 0 ) {
		return (float) $client['hourly_rate'];
	}
	$s = hpv_p_settings();

	return (float) ( 'HUF' === $currency ? ( $s['hourly_rate_huf'] ?? 0 ) : ( $s['hourly_rate_usd'] ?? 0 ) );
}

/**
 * Az ügyfél ki nem számlázott ideje (a projektjei feladatain), időszakra szűrve.
 */
function hpv_time_unbilled( int $client_id, string $from = '', string $to = '' ): array {
	$out = array();
	foreach ( hpv_p_find( 'project', array( 'client_id' => $client_id ), array( 'limit' => 500 ) ) as $p ) {
		foreach ( hpv_p_find( 'task', array( 'project_id' => (int) $p['id'] ), array( 'limit' => 5000 ) ) as $t ) {
			foreach ( hpv_p_find( 'time_entry', array( 'task_id' => (int) $t['id'] ), array( 'limit' => 5000 ) ) as $e ) {
				if ( (int) $e['invoice_id'] || (int) $e['started_at'] || (int) $e['minutes'] <= 0 ) {
					continue; // már számlázva, vagy még fut a stopper
				}
				$day = (string) $e['work_date'] ?: substr( (string) $e['created_at'], 0, 10 );
				if ( ( $from && $day < $from ) || ( $to && $day > $to ) ) {
					continue;
				}
				$out[] = array(
					'id'         => (int) $e['id'],
					'minutes'    => (int) $e['minutes'],
					'date'       => $day,
					'note'       => (string) $e['note'],
					'user'       => hpv_chat_user_label( (int) $e['user_id'] )['name'],
					'task_id'    => (int) $t['id'],
					'task'       => $t['title'],
					'project_id' => (int) $p['id'],
					'project'    => $p['name'],
				);
			}
		}
	}

	return $out;
}

/**
 * A kiválasztott időbejegyzések tételként a piszkozat számlára (projektenként vagy feladatonként összevonva).
 *
 * @return array|WP_Error A frissített számla.
 */
function hpv_time_to_invoice( int $invoice_id, array $entry_ids, string $group, float $rate = 0 ) {
	$inv = hpv_p_get( 'invoice', $invoice_id );
	if ( ! $inv || 'draft' !== $inv['status'] || '' !== (string) $inv['external_id'] ) {
		return new WP_Error( 'status', 'Munkaidőt csak piszkozat számlára lehet tenni.' );
	}
	$client_id = (int) $inv['client_id'];
	$currency  = hpv_p_invoice_currency( $inv );
	$rate      = $rate > 0 ? $rate : hpv_time_rate( $client_id, $currency );
	if ( $rate <= 0 ) {
		return new WP_Error( 'rate', 'Nincs óradíj: add meg az ügyfélnél vagy a beállításokban.' );
	}
	$allowed = array_column( hpv_time_unbilled( $client_id ), null, 'id' );
	$chosen  = array_values( array_intersect_key( $allowed, array_flip( array_map( 'absint', $entry_ids ) ) ) );
	if ( ! $chosen ) {
		return new WP_Error( 'empty', 'Nincs kiválasztott, még ki nem számlázott időbejegyzés.' );
	}
	$groups = array();
	foreach ( $chosen as $e ) {
		$key                        = 'task' === $group ? 't' . $e['task_id'] : 'p' . $e['project_id'];
		$groups[ $key ]['label']    = 'task' === $group ? $e['project'] . ' — ' . $e['task'] : $e['project'];
		$groups[ $key ]['minutes']  = ( $groups[ $key ]['minutes'] ?? 0 ) + $e['minutes'];
		$groups[ $key ]['dates'][]  = $e['date'];
	}
	$lang  = hpv_doc_client_language( $client_id );
	$items = array_map(
		fn( $i ) => array( 'description' => $i['description'], 'quantity' => $i['quantity'], 'unit_price' => $i['unit_price'] ),
		hpv_p_find( 'invoice_item', array( 'invoice_id' => $invoice_id ), array( 'orderby' => 'sort', 'order' => 'ASC' ) )
	);
	foreach ( $groups as $g ) {
		$from    = min( $g['dates'] );
		$to      = max( $g['dates'] );
		$range   = $from === $to ? $from : $from . ' – ' . $to;
		$items[] = array(
			'description' => $g['label'] . ' — ' . ( 'hu' === $lang ? 'munkaóra' : 'hours' ) . ' (' . $range . ')',
			'quantity'    => (string) round( $g['minutes'] / 60, 2 ),
			'unit_price'  => (string) $rate,
		);
	}
	hpv_p_save_invoice_items( $invoice_id, $items );
	foreach ( $chosen as $e ) {
		hpv_p_update( 'time_entry', $e['id'], array( 'invoice_id' => $invoice_id ) );
	}

	return hpv_p_get( 'invoice', $invoice_id );
}

/**
 * Törölt piszkozat vagy érvénytelen számla: az idő újra számlázható.
 */
function hpv_time_release( int $invoice_id ): void {
	global $wpdb;
	$wpdb->update( hpv_p_table( 'time_entry' ), array( 'invoice_id' => 0 ), array( 'invoice_id' => $invoice_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

add_action( 'rest_api_init', 'hpv_time_routes' );

function hpv_time_routes() {
	$can = fn() => hpv_p_can( 'invoices' );
	register_rest_route(
		'hpv/v1',
		'/billing/time',
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $r ) {
				$client_id = absint( $r['client_id'] );
				$currency  = hpv_p_client_currency( $client_id );
				$entries   = hpv_time_unbilled( $client_id, (string) $r['from'], (string) $r['to'] );
				return rest_ensure_response(
					array(
						'entries'  => $entries,
						'minutes'  => array_sum( array_column( $entries, 'minutes' ) ),
						'rate'     => hpv_time_rate( $client_id, $currency ),
						'currency' => $currency,
					)
				);
			},
		)
	);
	register_rest_route(
		'hpv/v1',
		'/billing/invoices/(?P<id>\d+)/time',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $r ) {
				$res = hpv_time_to_invoice( (int) $r['id'], (array) $r['entry_ids'], 'task' === $r['group'] ? 'task' : 'project', (float) $r['rate'] );
				if ( is_wp_error( $res ) ) {
					return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 400 ) );
				}
				return hpv_inv_rest_get( (int) $r['id'] );
			},
		)
	);
}
