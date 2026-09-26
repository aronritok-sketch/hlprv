<?php
/**
 * Ismétlődő számlák: az aktív előfizetésekből a „Következő számla” napján számla készül.
 * Ügyfelenként egy számla, a lejárt időszakok is pótlódnak (legfeljebb 12). A beállítás szerint piszkozat
 * (a csapat átnézi és kiküldi) vagy automatikus kiküldés (USA: e-mail + QuickBooks; Magyarország: Számlázz.hu).
 * Naponta egyszer fut (WP-Cron), kézzel is indítható. A csapat összefoglaló e-mailt kap.
 */

defined( 'ABSPATH' ) || exit;

const HPV_RECURRING_MAX_PERIODS = 12;

function hpv_recurring_mode(): string {
	$mode = (string) ( hpv_p_settings()['recurring_mode'] ?? 'draft' );

	return in_array( $mode, array( 'off', 'draft', 'send' ), true ) ? $mode : 'draft';
}

/**
 * Az időszak megnevezése a számlán: „October 2026”, „Oct – Dec 2026”, „Oct 2026 – Sep 2027” (magyarul is).
 */
function hpv_p_period_label( string $start, string $billing, string $lang ): string {
	$from = strtotime( $start );
	if ( ! $from ) {
		return '';
	}
	$hu     = 'hu' === $lang;
	$months = $hu
		? array( 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december' )
		: array( 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' );
	$m      = (int) gmdate( 'n', $from ) - 1;
	$y      = (int) gmdate( 'Y', $from );
	if ( 'monthly' === $billing ) {
		return $hu ? "$y. {$months[ $m ]}" : "{$months[ $m ]} $y";
	}
	$span  = 'quarterly' === $billing ? 2 : 11;
	$end_m = ( $m + $span ) % 12;
	$end_y = $y + intdiv( $m + $span, 12 );
	$short = fn( $i ) => $hu ? $months[ $i ] : substr( $months[ $i ], 0, 3 );

	if ( $end_y === $y ) {
		return $hu ? "$y. {$short( $m )} – {$short( $end_m )}" : "{$short( $m )} – {$short( $end_m )} $y";
	}

	return $hu ? "$y. {$short( $m )} – $end_y. {$short( $end_m )}" : "{$short( $m )} $y – {$short( $end_m )} $end_y";
}

/**
 * Esedékes tételek ügyfelenként (előnézethez és futtatáshoz).
 *
 * @return array [ client_id => [ ['sub' => …, 'period' => 'Y-m-d', 'label' => …], … ] ]
 */
function hpv_recurring_anchor( array $sub ): int {
	$start = (string) ( $sub['start_date'] ?: $sub['next_invoice_date'] );

	return preg_match( '/^\d{4}-\d{2}-(\d{2})/', $start, $m ) ? (int) $m[1] : 0;
}

function hpv_recurring_due( string $until ): array {
	$due = array();
	foreach ( hpv_p_find( 'subscription', array( 'status' => 'active' ), array( 'limit' => 2000 ) ) as $sub ) {
		$date = (string) $sub['next_invoice_date'];
		if ( '' === $date || $date > $until ) {
			continue;
		}
		$lang = hpv_doc_client_language( (int) $sub['client_id'] );
		for ( $i = 0; $i < HPV_RECURRING_MAX_PERIODS && '' !== $date && $date <= $until; $i++ ) {
			$due[ (int) $sub['client_id'] ][] = array(
				'sub'    => $sub,
				'period' => $date,
				'label'  => 'one_time' === $sub['billing'] ? '' : hpv_p_period_label( $date, $sub['billing'], $lang ),
			);
			$date = 'one_time' === $sub['billing'] ? '' : hpv_p_next_billing_date( $date, $sub['billing'], hpv_recurring_anchor( $sub ) );
		}
	}

	return $due;
}

/**
 * Egy futás. Visszaadja, mi készült (az összefoglalóhoz és a teszthez).
 *
 * @return array { created: [ {invoice_id, client, total, sent, error} ], skipped: string }
 */
function hpv_recurring_run( ?string $today = null, ?string $mode = null ): array {
	$mode  = $mode ?? hpv_recurring_mode();
	$today = $today ?? current_time( 'Y-m-d' );
	if ( 'off' === $mode ) {
		return array( 'created' => array(), 'skipped' => 'off' );
	}
	if ( get_transient( 'hpv_recurring_lock' ) ) {
		return array( 'created' => array(), 'skipped' => 'running' );
	}
	set_transient( 'hpv_recurring_lock', 1, 10 * MINUTE_IN_SECONDS );

	$s       = hpv_p_settings();
	$created = array();
	try {
		foreach ( hpv_recurring_due( $today ) as $client_id => $lines ) {
			$client = hpv_p_get( 'client', $client_id );
			if ( ! $client ) {
				continue;
			}
			$is_hu = 'HU' === $client['country'];
			$items = array();
			foreach ( $lines as $line ) {
				$items[] = array(
					'description' => $line['sub']['name'] . ( $line['label'] ? ' — ' . $line['label'] : '' ),
					'quantity'    => '1',
					'unit_price'  => $line['sub']['price'],
				);
			}
			$invoice_id = hpv_p_insert(
				'invoice',
				array(
					'client_id'  => $client_id,
					'status'     => 'draft',
					'currency'   => hpv_p_client_currency( $client_id ),
					'issue_date' => $today,
					'due_date'   => gmdate( 'Y-m-d', strtotime( $today . ' +' . (int) $s['payment_terms'] . ' days' ) ),
					'tax_rate'   => $is_hu ? hpv_p_vat_rate( (string) $s['hu_vat_key'] ) : '0',
					'notes'      => $is_hu ? 'Ismétlődő szolgáltatások' : 'Recurring services',
				)
			);
			hpv_p_save_invoice_items( $invoice_id, $items );
			if ( ! $is_hu ) {
				hpv_p_assign_invoice_number( $invoice_id );
			}

			// Az előfizetések következő dátuma azonnal előre lép: egy újabb futás nem számláz kétszer.
			$next = array();
			foreach ( $lines as $line ) {
				$sid          = (int) $line['sub']['id'];
				$next[ $sid ] = 'one_time' === $line['sub']['billing'] ? '' : hpv_p_next_billing_date( $line['period'], $line['sub']['billing'], hpv_recurring_anchor( $line['sub'] ) );
			}
			foreach ( $next as $sid => $date ) {
				hpv_p_update( 'subscription', $sid, array( 'next_invoice_date' => '' === $date ? null : $date ) );
			}

			$result = array(
				'invoice_id' => $invoice_id,
				'client'     => $client['name'],
				'total'      => hpv_p_money( hpv_p_get( 'invoice', $invoice_id )['total'], hpv_p_client_currency( $client_id ) ),
				'sent'       => false,
				'error'      => '',
			);
			if ( 'send' === $mode ) {
				$sent = hpv_bill_send( $invoice_id, 0 );
				if ( is_wp_error( $sent ) ) {
					$result['error'] = $sent->get_error_message();
				} else {
					$result['sent'] = true;
				}
			}
			hpv_p_log( $client_id, 'system', sprintf( 'Ismétlődő számla készült (%s): %s', $result['sent'] ? 'kiküldve' : 'piszkozat', $result['total'] ), false );
			$created[] = $result;
		}
	} finally {
		delete_transient( 'hpv_recurring_lock' );
	}

	if ( $created ) {
		hpv_recurring_digest( $created, $mode );
	}
	update_option( 'hpv_recurring_last_run', array( 'at' => current_time( 'mysql', true ), 'count' => count( $created ) ), false );

	return array( 'created' => $created, 'skipped' => '' );
}

function hpv_recurring_digest( array $created, string $mode ): void {
	$rows = '';
	foreach ( $created as $c ) {
		$rows .= '<li><a href="' . esc_url( hpv_p_crm_app_url( '/invoices/' . (int) $c['invoice_id'] ) ) . '">' . esc_html( $c['client'] ) . '</a> — ' . esc_html( $c['total'] )
			. ( $c['sent'] ? ' · kiküldve' : ( $c['error'] ? ' · <strong>hiba: ' . esc_html( $c['error'] ) . '</strong>' : ' · piszkozat, átnézésre vár' ) ) . '</li>';
	}
	hpv_p_notify_staff(
		sprintf( 'Ismétlődő számlák: %d db', count( $created ) ),
		'<p>' . ( 'send' === $mode ? 'A mai esedékes előfizetésekből készült számlák:' : 'A mai esedékes előfizetésekből piszkozat készült. Nézd át és küldd ki őket:' ) . '</p><ul>' . $rows . '</ul>',
		hpv_p_crm_app_url( '/invoices?status=draft' )
	);
}

/* ─── Ütemezés ────────────────────────────────────────────── */

add_action( 'init', 'hpv_recurring_schedule' );

function hpv_recurring_schedule() {
	if ( 'off' === hpv_recurring_mode() ) {
		wp_clear_scheduled_hook( 'hpv_recurring_cron' );
		return;
	}
	if ( ! wp_next_scheduled( 'hpv_recurring_cron' ) ) {
		// Reggel 7-kor (a WordPress időzónája szerint), hogy napközben át lehessen nézni.
		$next = strtotime( current_time( 'Y-m-d' ) . ' 07:00:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		wp_schedule_event( $next > time() ? $next : $next + DAY_IN_SECONDS, 'daily', 'hpv_recurring_cron' );
	}
}

add_action( 'hpv_recurring_cron', 'hpv_recurring_run' );
register_deactivation_hook( HPV_PORTAL_FILE, fn() => wp_clear_scheduled_hook( 'hpv_recurring_cron' ) );

/* ─── REST (számlázási joggal) ────────────────────────────── */

add_action( 'rest_api_init', 'hpv_recurring_routes' );

function hpv_recurring_routes() {
	$can = fn() => hpv_p_can( 'invoices' );
	register_rest_route(
		'hpv/v1',
		'/billing/recurring',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => 'hpv_recurring_rest_preview',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => fn() => rest_ensure_response( hpv_recurring_run() ),
			),
		)
	);
}

/**
 * Előnézet: mi lesz esedékes a következő 30 napban.
 */
function hpv_recurring_rest_preview() {
	$until = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +30 days' ) );
	$out   = array();
	foreach ( hpv_recurring_due( $until ) as $client_id => $lines ) {
		$currency = hpv_p_client_currency( $client_id );
		foreach ( $lines as $line ) {
			$out[] = array(
				'client'    => hpv_p_client_name_safe( $client_id ),
				'client_id' => $client_id,
				'name'      => $line['sub']['name'],
				'period'    => $line['label'],
				'date'      => $line['period'],
				'amount'    => hpv_p_money( $line['sub']['price'], $currency ),
			);
		}
	}
	usort( $out, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );

	return rest_ensure_response(
		array(
			'mode'     => hpv_recurring_mode(),
			'last_run' => get_option( 'hpv_recurring_last_run' ) ?: null,
			'upcoming' => $out,
		)
	);
}

/**
 * 0.6-os frissítés: eddig kézzel számláztatok, ezért a múltbeli „Következő számla” dátumok nem pótlódnak,
 * hanem a következő jövőbeli napra lépnek; a régi egyszeri tételek nem számlázódnak utólag.
 */
function hpv_recurring_migrate() {
	$today = current_time( 'Y-m-d' );
	foreach ( hpv_p_find( 'subscription', array(), array( 'limit' => 5000 ) ) as $sub ) {
		$date = (string) $sub['next_invoice_date'];
		if ( '' === $date || $date >= $today ) {
			continue;
		}
		if ( 'one_time' === $sub['billing'] ) {
			$date = '';
		} else {
			for ( $i = 0; $i < 600 && $date < $today; $i++ ) {
				$date = hpv_p_next_billing_date( $date, $sub['billing'], hpv_recurring_anchor( $sub ) );
			}
		}
		hpv_p_update( 'subscription', (int) $sub['id'], array( 'next_invoice_date' => '' === $date ? null : $date ) );
	}
}
