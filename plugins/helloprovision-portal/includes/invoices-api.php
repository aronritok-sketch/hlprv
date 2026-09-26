<?php
/**
 * Számlák és előfizetések a CRM appnak (REST, „Számlázás” joggal).
 *
 * Ugyanazokat a műveleteket hívja, mint a klasszikus admin (billing.php): kiküldés (USA: e-mail + QuickBooks,
 * Magyarország: Számlázz.hu), befizetés, érvénytelenítés/sztornó, újraszinkron, Teya link.
 */

defined( 'ABSPATH' ) || exit;

function hpv_inv_format( array $inv, bool $full = false ): array {
	$cur     = hpv_p_invoice_currency( $inv );
	$today   = current_time( 'Y-m-d' );
	$overdue = hpv_p_invoice_is_overdue( $inv, $today );
	$out     = array(
		'id'          => (int) $inv['id'],
		'number'      => (string) $inv['number'],
		'client_id'   => (int) $inv['client_id'],
		'client'      => hpv_p_client_name_safe( (int) $inv['client_id'] ),
		'country'     => hpv_p_client_country( (int) $inv['client_id'] ),
		'status'      => $overdue ? 'overdue' : $inv['status'],
		'currency'    => $cur,
		'issue_date'  => $inv['issue_date'],
		'due_date'    => $inv['due_date'],
		'total'       => hpv_p_to_cents( $inv['total'] ),
		'balance'     => hpv_p_invoice_balance( $inv ),
		'total_label' => hpv_p_money( $inv['total'], $cur ),
		'sync_status' => (string) $inv['sync_status'],
		'locked'      => hpv_p_invoice_locked( $inv ),
	);
	if ( ! $full ) {
		return $out;
	}
	$items = hpv_p_find( 'invoice_item', array( 'invoice_id' => (int) $inv['id'] ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );

	return array_merge(
		$out,
		array(
			'raw_status'  => $inv['status'],
			'vat_key'     => (string) $inv['vat_key'],
			'tax_rate'    => (float) $inv['tax_rate'],
			'notes'       => (string) $inv['notes'],
			'payment_url' => (string) $inv['payment_url'],
			'subtotal'    => hpv_p_to_cents( $inv['subtotal'] ),
			'tax'         => hpv_p_to_cents( $inv['tax'] ),
			'paid'        => hpv_p_to_cents( $inv['paid_amount'] ),
			'sent_at'     => $inv['sent_at'],
			'paid_at'     => $inv['paid_at'],
			'sync_error'  => (string) $inv['sync_error'],
			'external_id' => (string) $inv['external_id'],
			'pdf_url'     => $inv['pdf_file'] ? hpv_p_invoice_pdf_url( $inv ) : '',
			'portal_url'  => hpv_p_portal_url( array( 'view' => 'invoices', 'id' => (int) $inv['id'], 'preview_client' => (int) $inv['client_id'] ) ),
			'admin_url'   => admin_url( 'admin.php?page=hpv-crm&action=edit&entity=invoice&id=' . (int) $inv['id'] ),
			'items'       => array_map(
				fn( $i ) => array(
					'description' => $i['description'],
					'quantity'    => rtrim( rtrim( (string) $i['quantity'], '0' ), '.' ) ?: '0',
					'unit_price'  => hpv_p_to_cents( $i['unit_price'] ) / 100,
					'amount'      => hpv_p_to_cents( $i['amount'] ),
				),
				$items
			),
			'payments'    => array_map(
				fn( $p ) => array(
					'id'       => (int) $p['id'],
					'provider' => $p['provider'],
					'amount'   => hpv_p_to_cents( $p['amount'] ),
					'paid_on'  => $p['paid_on'],
					'note'     => (string) $p['note'],
					'booked'   => '' !== (string) $p['external_ref'],
				),
				hpv_p_find( 'payment', array( 'invoice_id' => (int) $inv['id'] ), array( 'orderby' => 'paid_on', 'order' => 'ASC' ) )
			),
			'integrations' => array(
				'szamlazz' => hpv_szamlazz_enabled(),
				'qbo'      => hpv_qbo_connected(),
				'stripe'   => hpv_stripe_enabled(),
			),
		)
	);
}

add_action( 'rest_api_init', 'hpv_inv_routes' );

function hpv_inv_routes() {
	$can   = fn() => hpv_p_can( 'invoices' );
	$route = function ( string $path, string $methods, callable $cb ) use ( $can ) {
		register_rest_route( 'hpv/v1', $path, array( 'methods' => $methods, 'permission_callback' => $can, 'callback' => $cb ) );
	};
	$route( '/billing/invoices', 'GET', 'hpv_inv_rest_list' );
	$route( '/billing/invoices', 'POST', fn( WP_REST_Request $r ) => hpv_inv_rest_save( $r, 0 ) );
	$route( '/billing/invoices/(?P<id>\d+)', 'GET', fn( WP_REST_Request $r ) => hpv_inv_rest_get( (int) $r['id'] ) );
	$route( '/billing/invoices/(?P<id>\d+)', 'POST', fn( WP_REST_Request $r ) => hpv_inv_rest_save( $r, (int) $r['id'] ) );
	$route( '/billing/invoices/(?P<id>\d+)', 'DELETE', 'hpv_inv_rest_delete' );
	$route( '/billing/invoices/(?P<id>\d+)/(?P<action>send|payment|void|sync|link)', 'POST', 'hpv_inv_rest_action' );
	$route( '/billing/subscriptions', 'GET', 'hpv_inv_rest_subscriptions' );
	$route( '/billing/subscriptions', 'POST', fn( WP_REST_Request $r ) => hpv_inv_rest_save_subscription( $r, 0 ) );
	$route( '/billing/subscriptions/(?P<id>\d+)', 'POST', fn( WP_REST_Request $r ) => hpv_inv_rest_save_subscription( $r, (int) $r['id'] ) );
}

function hpv_inv_rest_list( WP_REST_Request $req ) {
	$where = array();
	if ( $req['client_id'] ) {
		$where['client_id'] = absint( $req['client_id'] );
	}
	$all    = hpv_p_find( 'invoice', $where, array( 'orderby' => 'id', 'order' => 'DESC', 'limit' => 1000 ) );
	$today  = current_time( 'Y-m-d' );
	$month  = substr( $today, 0, 7 );
	$status = sanitize_key( (string) $req['status'] );
	$list   = array_filter(
		$all,
		function ( $inv ) use ( $status, $today ) {
			if ( ! $status || 'all' === $status ) {
				return true;
			}
			if ( 'overdue' === $status ) {
				return hpv_p_invoice_is_overdue( $inv, $today );
			}
			if ( 'open' === $status ) {
				return 'sent' === $inv['status'];
			}
			return $status === $inv['status'];
		}
	);
	$q = mb_strtolower( trim( (string) $req['q'] ) );
	if ( '' !== $q ) {
		$list = array_filter( $list, fn( $inv ) => false !== mb_strpos( mb_strtolower( $inv['number'] . ' ' . hpv_p_client_name_safe( (int) $inv['client_id'] ) ), $q ) );
	}

	$paid_month = array();
	foreach ( hpv_p_find( 'payment', array(), array( 'limit' => 5000 ) ) as $p ) {
		if ( 0 === strpos( (string) $p['paid_on'], $month ) ) {
			$paid_month[ $p['currency'] ] = ( $paid_month[ $p['currency'] ] ?? 0 ) + hpv_p_to_cents( $p['amount'] );
		}
	}
	$overdue = hpv_p_outstanding_by_currency( array_filter( $all, fn( $inv ) => hpv_p_invoice_is_overdue( $inv, $today ) ) );

	return rest_ensure_response(
		array(
			'invoices' => array_values( array_map( 'hpv_inv_format', $list ) ),
			'counts'   => array(
				'draft'   => count( array_filter( $all, fn( $i ) => 'draft' === $i['status'] ) ),
				'open'    => count( array_filter( $all, fn( $i ) => 'sent' === $i['status'] ) ),
				'overdue' => count( array_filter( $all, fn( $i ) => hpv_p_invoice_is_overdue( $i, $today ) ) ),
			),
			'kpi'      => array(
				'outstanding' => hpv_p_money_multi( hpv_p_outstanding_by_currency( $all ) ) ?: '—',
				'overdue'     => hpv_p_money_multi( $overdue ) ?: '—',
				'paid_month'  => hpv_p_money_multi( $paid_month ) ?: '—',
				'mrr'         => hpv_p_money_multi( hpv_p_mrr_by_currency( hpv_p_find( 'subscription', array( 'status' => 'active' ), array( 'limit' => 5000 ) ) ) ) ?: '—',
			),
			'settings' => array(
				'payment_terms' => (int) hpv_p_settings()['payment_terms'],
				'hu_vat_key'    => (string) hpv_p_settings()['hu_vat_key'],
				'vat_keys'      => array_map( fn( $k, $l ) => array( 'key' => (string) $k, 'label' => $l[0] ), array_keys( hpv_p_entity( 'invoice' )['fields']['vat_key']['options'] ), hpv_p_entity( 'invoice' )['fields']['vat_key']['options'] ),
			),
		)
	);
}

function hpv_inv_rest_get( int $id ) {
	$inv = hpv_p_get( 'invoice', $id );

	return $inv ? rest_ensure_response( hpv_inv_format( $inv, true ) ) : new WP_Error( 'not_found', 'A számla nem található.', array( 'status' => 404 ) );
}

/**
 * Piszkozat mentése (új vagy meglévő). Kiállított magyar számla nem módosítható, kiküldött csak a megjegyzésben.
 */
function hpv_inv_rest_save( WP_REST_Request $req, int $id ) {
	$old = $id ? hpv_p_get( 'invoice', $id ) : null;
	if ( $id && ! $old ) {
		return new WP_Error( 'not_found', 'A számla nem található.', array( 'status' => 404 ) );
	}
	if ( $old && ( hpv_p_invoice_locked( $old ) || in_array( $old['status'], array( 'paid', 'void' ), true ) ) ) {
		return new WP_Error( 'locked', 'Ez a számla már nem módosítható. Érvénytelenítsd, és készíts újat.', array( 'status' => 400 ) );
	}
	$data = hpv_p_sanitize( 'invoice', $req->get_params() );
	unset( $data['status'] );
	if ( $old && 'sent' === $old['status'] ) {
		// Kiküldött (amerikai) számla: tételt és összeget már nem módosítunk, csak megjegyzést és határidőt.
		$data = array_intersect_key( $data, array_flip( array( 'notes', 'due_date' ) ) );
	}
	$client_id = (int) ( $data['client_id'] ?? $old['client_id'] ?? 0 );
	if ( ! $client_id || ! hpv_p_get( 'client', $client_id ) ) {
		return new WP_Error( 'client', 'Válassz ügyfelet.', array( 'status' => 400 ) );
	}
	if ( $old && (int) $old['client_id'] !== $client_id && '' !== (string) $old['number'] ) {
		return new WP_Error( 'client', 'Számozott számla ügyfele nem cserélhető.', array( 'status' => 400 ) );
	}
	if ( ! $old || 'draft' === $old['status'] ) {
		if ( 'HU' === hpv_p_client_country( $client_id ) ) {
			$data['tax_rate'] = hpv_p_vat_rate( (string) ( ( $data['vat_key'] ?? '' ) ?: hpv_p_settings()['hu_vat_key'] ) );
		}
		if ( ! $old ) {
			$data += array(
				'status'     => 'draft',
				'issue_date' => current_time( 'Y-m-d' ),
				'due_date'   => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . (int) hpv_p_settings()['payment_terms'] . ' days' ) ),
			);
		}
	}

	if ( $old ) {
		if ( $data ) {
			hpv_p_update( 'invoice', $id, $data );
		}
	} else {
		$id = hpv_p_insert( 'invoice', $data );
	}
	if ( null !== $req['items'] && ( ! $old || 'draft' === $old['status'] ) ) {
		$items = array_map(
			fn( $i ) => array(
				'description' => (string) ( $i['description'] ?? '' ),
				'quantity'    => (string) ( $i['quantity'] ?? '1' ),
				'unit_price'  => (string) ( $i['unit_price'] ?? '0' ),
			),
			array_filter( (array) $req['items'], 'is_array' )
		);
		hpv_p_save_invoice_items( $id, $items );
	} elseif ( $old && isset( $data['tax_rate'] ) ) {
		// ÁFA-kulcs változott: az összegek újraszámolása a meglévő tételekkel.
		hpv_p_save_invoice_items( $id, array_map( fn( $i ) => array_intersect_key( $i, array_flip( array( 'description', 'quantity', 'unit_price' ) ) ), hpv_p_find( 'invoice_item', array( 'invoice_id' => $id ), array( 'orderby' => 'sort', 'order' => 'ASC' ) ) ) );
	}
	$inv = hpv_p_get( 'invoice', $id );
	if ( ! hpv_p_is_hu_invoice( $inv ) && hpv_p_find( 'invoice_item', array( 'invoice_id' => $id ), array( 'limit' => 1 ) ) ) {
		hpv_p_assign_invoice_number( $id ); // magyar számlánál a Számlázz.hu adja
	}

	return hpv_inv_rest_get( $id );
}

function hpv_inv_rest_delete( WP_REST_Request $req ) {
	$inv = hpv_p_get( 'invoice', (int) $req['id'] );
	if ( ! $inv ) {
		return new WP_Error( 'not_found', 'A számla nem található.', array( 'status' => 404 ) );
	}
	if ( 'draft' !== $inv['status'] || '' !== (string) $inv['external_id'] ) {
		return new WP_Error( 'locked', 'Csak piszkozat törölhető. A kiküldött számlát érvényteleníteni kell.', array( 'status' => 400 ) );
	}
	hpv_p_delete( 'invoice', (int) $inv['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

function hpv_inv_rest_action( WP_REST_Request $req ) {
	$id  = (int) $req['id'];
	$inv = hpv_p_get( 'invoice', $id );
	if ( ! $inv ) {
		return new WP_Error( 'not_found', 'A számla nem található.', array( 'status' => 404 ) );
	}
	$user = get_current_user_id();
	switch ( $req['action'] ) {
		case 'send':
			$res = hpv_bill_send( $id, $user );
			break;
		case 'payment':
			if ( 'sent' !== $inv['status'] ) {
				return new WP_Error( 'status', 'Befizetést kiküldött számlához lehet rögzíteni.', array( 'status' => 400 ) );
			}
			$date = (string) $req['paid_on'];
			$res  = hpv_bill_mark_paid(
				$id,
				array(
					'provider' => in_array( $req['provider'], array( 'manual', 'teya', 'stripe' ), true ) ? $req['provider'] : 'manual',
					'amount'   => hpv_p_to_cents( (string) $req['amount'] ) ?: hpv_p_invoice_balance( $inv ),
					'paid_on'  => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' ),
					'note'     => (string) $req['note'],
					'user_id'  => $user,
				)
			);
			break;
		case 'void':
			$res = hpv_bill_void( $id, $user );
			break;
		case 'sync':
			$res = hpv_bill_sync_invoice( $id );
			break;
		default:
			$url = esc_url_raw( (string) $req['payment_url'] );
			if ( $url && ! preg_match( '#^https://#', $url ) ) {
				return new WP_Error( 'url', 'A fizetési link https:// címmel kezdődjön.', array( 'status' => 400 ) );
			}
			$res = hpv_p_update( 'invoice', $id, array( 'payment_url' => $url ) );
	}
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 400 ) );
	}

	return hpv_inv_rest_get( $id );
}

/* ─── Előfizetések ────────────────────────────────────────── */

function hpv_inv_format_subscription( array $s ): array {
	$cur = hpv_p_client_currency( (int) $s['client_id'] );

	return array(
		'id'                => (int) $s['id'],
		'client_id'         => (int) $s['client_id'],
		'client'            => hpv_p_client_name_safe( (int) $s['client_id'] ),
		'name'              => $s['name'],
		'description'       => (string) $s['description'],
		'price'             => hpv_p_to_cents( $s['price'] ) / 100,
		'price_label'       => hpv_p_money( $s['price'], $cur ),
		'currency'          => $cur,
		'billing'           => $s['billing'],
		'status'            => $s['status'],
		'start_date'        => $s['start_date'],
		'next_invoice_date' => $s['next_invoice_date'],
	);
}

function hpv_inv_rest_subscriptions( WP_REST_Request $req ) {
	$where = $req['client_id'] ? array( 'client_id' => absint( $req['client_id'] ) ) : array();
	$subs  = hpv_p_find( 'subscription', $where, array( 'limit' => 2000 ) );
	usort( $subs, fn( $a, $b ) => strcmp( (string) ( $a['next_invoice_date'] ?: '9999' ), (string) ( $b['next_invoice_date'] ?: '9999' ) ) );

	return rest_ensure_response(
		array(
			'subscriptions' => array_map( 'hpv_inv_format_subscription', $subs ),
			'services'      => array_map( fn( $s ) => array( 'id' => (int) $s['id'], 'name' => $s['name'], 'price' => hpv_p_to_cents( $s['price'] ) / 100, 'billing' => $s['billing'], 'description' => (string) $s['description'] ), hpv_p_find( 'service', array( 'active' => 1 ), array( 'orderby' => 'name', 'order' => 'ASC', 'limit' => 500 ) ) ),
			'recurring'     => array( 'mode' => hpv_recurring_mode(), 'last_run' => get_option( 'hpv_recurring_last_run' ) ?: null ),
		)
	);
}

function hpv_inv_rest_save_subscription( WP_REST_Request $req, int $id ) {
	$old = $id ? hpv_p_get( 'subscription', $id ) : null;
	if ( $id && ! $old ) {
		return new WP_Error( 'not_found', 'Nincs ilyen előfizetés.', array( 'status' => 404 ) );
	}
	$data = hpv_p_sanitize( 'subscription', $req->get_params() );
	if ( ! $old ) {
		$service = ! empty( $data['service_id'] ) ? hpv_p_get( 'service', (int) $data['service_id'] ) : null;
		if ( $service ) {
			foreach ( array( 'name', 'description', 'billing' ) as $key ) {
				if ( empty( $data[ $key ] ) ) {
					$data[ $key ] = $service[ $key ];
				}
			}
			if ( ! hpv_p_to_cents( $data['price'] ?? 0 ) ) {
				$data['price'] = $service['price'];
			}
		}
		$data += array( 'status' => 'active', 'start_date' => current_time( 'Y-m-d' ) );
		if ( empty( $data['next_invoice_date'] ) ) {
			$data['next_invoice_date'] = $data['start_date'];
		}
	}
	$missing = hpv_p_validate( 'subscription', array_merge( $old ?: array(), $data ) );
	if ( $missing ) {
		return new WP_Error( 'required', 'Hiányzik: ' . implode( ', ', $missing ), array( 'status' => 400 ) );
	}
	if ( $old ) {
		hpv_p_update( 'subscription', $id, $data );
	} else {
		$id = hpv_p_insert( 'subscription', $data );
	}

	return rest_ensure_response( hpv_inv_format_subscription( hpv_p_get( 'subscription', $id ) ) );
}
