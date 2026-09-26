<?php
/**
 * Ügyfél-adatlap és szolgáltatás-katalógus a CRM appnak (eddig csak a klasszikus adminban volt).
 *
 * Ügyfél: adatok (cég, számlázás, ország, óradíj, riport-adatforrások), portál-hozzáférés (meghívás, visszavonás),
 * belső jegyzetek és idővonal, kapcsolódó projektek, számlák (számlázási joggal), előfizetések, szerződések.
 * A számlázási mezőket (számlázási név, adószám, cím, óradíj) csak számlázási joggal lehet látni és módosítani.
 */

defined( 'ABSPATH' ) || exit;

const HPV_CLIENT_BILLING_FIELDS = array( 'billing_name', 'tax_number', 'zip', 'city', 'street', 'state', 'billing_email', 'hourly_rate' );

function hpv_client_editable_fields(): array {
	$fields = array_keys(
		array_filter(
			hpv_p_entity( 'client' )['fields'],
			fn( $f ) => empty( $f['readonly'] ) && empty( $f['hidden'] )
		)
	);

	return hpv_p_can( 'invoices' ) ? $fields : array_values( array_diff( $fields, HPV_CLIENT_BILLING_FIELDS ) );
}

function hpv_client_format( array $c ): array {
	$out = array( 'id' => (int) $c['id'] );
	foreach ( hpv_client_editable_fields() as $key ) {
		$out[ $key ] = 'hourly_rate' === $key ? hpv_p_to_cents( $c[ $key ] ) / 100 : (string) ( $c[ $key ] ?? '' );
	}
	$out['currency'] = hpv_p_client_currency( (int) $c['id'] );

	return $out;
}

function hpv_client_full( int $id ): ?array {
	$c = hpv_p_get( 'client', $id );
	if ( ! $c ) {
		return null;
	}
	$can_bill = hpv_p_can( 'invoices' );
	$out      = hpv_client_format( $c );

	$out['users']    = array_map(
		fn( $u ) => array(
			'id'         => $u->ID,
			'name'       => $u->display_name,
			'email'      => $u->user_email,
			'last_login' => (int) get_user_meta( $u->ID, 'hpv_last_login', true ),
		),
		hpv_p_client_users( $id )
	);
	$out['projects'] = array_map( fn( $p ) => hpv_pm_format_project( $p, true ), hpv_p_find( 'project', array( 'client_id' => $id, 'is_template' => 0 ), array( 'limit' => 200 ) ) );
	$out['activity'] = array_map(
		fn( $a ) => array(
			'id'      => (int) $a['id'],
			'type'    => $a['type'],
			'body'    => $a['body'],
			'visible' => (bool) (int) $a['visible'],
			'author'  => (int) $a['user_id'] ? hpv_chat_user_label( (int) $a['user_id'] )['name'] : '',
			'at'      => (int) strtotime( $a['created_at'] . ' UTC' ),
		),
		hpv_p_find( 'activity', array( 'client_id' => $id ), array( 'orderby' => 'id', 'order' => 'DESC', 'limit' => 60 ) )
	);
	$out['counts']   = array(
		'files'     => count( hpv_p_find( 'file', array( 'client_id' => $id ), array( 'limit' => 1000 ) ) ),
		'approvals' => count( hpv_p_find( 'approval', array( 'client_id' => $id, 'status' => 'pending' ), array( 'limit' => 500 ) ) ),
		'reports'   => count( hpv_p_find( 'report', array( 'client_id' => $id ), array( 'limit' => 500 ) ) ),
	);
	if ( $can_bill ) {
		$invoices             = hpv_p_find( 'invoice', array( 'client_id' => $id ), array( 'orderby' => 'id', 'order' => 'DESC', 'limit' => 200 ) );
		$out['invoices']      = array_map( 'hpv_inv_format', array_slice( $invoices, 0, 10 ) );
		$out['outstanding']   = hpv_p_money_multi( hpv_p_outstanding_by_currency( $invoices ), $out['currency'] );
		$out['subscriptions'] = array_map( 'hpv_inv_format_subscription', hpv_p_find( 'subscription', array( 'client_id' => $id ), array( 'limit' => 200 ) ) );
		$out['mrr']           = hpv_p_money_multi( hpv_p_mrr_by_currency( hpv_p_find( 'subscription', array( 'client_id' => $id, 'status' => 'active' ), array( 'limit' => 200 ) ) ), $out['currency'] );
	}
	if ( hpv_p_can( 'contracts' ) ) {
		$out['contracts'] = array_map(
			fn( $k ) => array( 'id' => (int) $k['id'], 'title' => $k['title'], 'status' => $k['status'], 'signed_at' => $k['signed_at'] ),
			hpv_p_find( 'contract', array( 'client_id' => $id ), array( 'orderby' => 'id', 'order' => 'DESC', 'limit' => 50 ) )
		);
	}
	if ( function_exists( 'hpv_sales_detail' ) && ( 'lead' === $c['status'] || $c['lead_stage'] ) ) {
		$out['sales'] = hpv_sales_detail( $c );
	}
	$out['portal_url'] = hpv_p_portal_url( array( 'preview_client' => $id ) );
	$out['admin_url']  = admin_url( 'admin.php?page=hpv-crm&client=' . $id );

	return $out;
}

function hpv_client_save( array $input, int $id = 0 ) {
	$old  = $id ? hpv_p_get( 'client', $id ) : null;
	$data = hpv_p_sanitize( 'client', array_intersect_key( $input, array_flip( hpv_client_editable_fields() ) ) );
	if ( '' !== trim( (string) ( $input['email'] ?? '' ) ) && ! is_email( $data['email'] ?? '' ) ) {
		return new WP_Error( 'email', 'Érvénytelen e-mail cím.' );
	}
	$missing = hpv_p_validate( 'client', array_merge( $old ?: array(), $data ) );
	if ( $missing ) {
		return new WP_Error( 'required', 'Hiányzik: ' . implode( ', ', $missing ) );
	}
	if ( $old ) {
		hpv_p_update( 'client', $id, $data );
		// Az ország váltása a pénznemet és a számlázót is váltja: a még nem kiállított számlák maradnak, jelezzük.
		if ( isset( $data['country'] ) && $data['country'] !== $old['country'] ) {
			hpv_p_client_country( $id, true );
			hpv_p_log( $id, 'system', sprintf( 'Ország módosítva: %s → %s (új számlák ennek megfelelően).', $old['country'], $data['country'] ), false, get_current_user_id() );
		}
		return hpv_client_full( $id );
	}
	$id = hpv_p_insert( 'client', $data );

	return $id ? hpv_client_full( $id ) : new WP_Error( 'db', 'Az adatbázisba írás nem sikerült.' );
}

add_action( 'rest_api_init', 'hpv_clients_routes' );

function hpv_clients_routes() {
	$staff = fn() => hpv_p_is_staff();
	$bill  = fn() => hpv_p_can( 'invoices' );
	$fail  = fn( WP_Error $e ) => new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => 400 ) );
	$route = function ( string $path, string $methods, callable $cb, callable $perm ) {
		register_rest_route( 'hpv/v1', $path, array( 'methods' => $methods, 'permission_callback' => $perm, 'callback' => $cb ) );
	};

	$route(
		'/clients',
		'POST',
		function ( WP_REST_Request $r ) use ( $fail ) {
			$res = hpv_client_save( $r->get_params() );
			return is_wp_error( $res ) ? $fail( $res ) : rest_ensure_response( $res );
		},
		$staff
	);
	$route(
		'/clients/(?P<id>\d+)',
		'GET',
		function ( WP_REST_Request $r ) {
			$c = hpv_client_full( (int) $r['id'] );
			return $c ? rest_ensure_response( array_merge( $c, array( 'fields' => hpv_client_field_meta() ) ) ) : new WP_Error( 'not_found', 'Nincs ilyen ügyfél.', array( 'status' => 404 ) );
		},
		$staff
	);
	$route(
		'/clients/(?P<id>\d+)',
		'POST',
		function ( WP_REST_Request $r ) use ( $fail ) {
			if ( ! hpv_p_get( 'client', (int) $r['id'] ) ) {
				return new WP_Error( 'not_found', 'Nincs ilyen ügyfél.', array( 'status' => 404 ) );
			}
			$res = hpv_client_save( $r->get_params(), (int) $r['id'] );
			return is_wp_error( $res ) ? $fail( $res ) : rest_ensure_response( $res );
		},
		$staff
	);
	$route(
		'/clients/(?P<id>\d+)/notes',
		'POST',
		function ( WP_REST_Request $r ) {
			$body = trim( sanitize_textarea_field( (string) $r['body'] ) );
			if ( '' === $body || ! hpv_p_get( 'client', (int) $r['id'] ) ) {
				return new WP_Error( 'body', 'Írj jegyzetet.', array( 'status' => 400 ) );
			}
			hpv_p_log( (int) $r['id'], 'note', $body, false, get_current_user_id() );
			return rest_ensure_response( hpv_client_full( (int) $r['id'] ) );
		},
		$staff
	);
	$route(
		'/clients/(?P<id>\d+)/invite',
		'POST',
		function ( WP_REST_Request $r ) use ( $fail ) {
			$user = hpv_p_invite_user( (int) $r['id'], sanitize_text_field( (string) $r['name'] ), sanitize_email( (string) $r['email'] ) );
			return is_wp_error( $user ) ? $fail( $user ) : rest_ensure_response( hpv_client_full( (int) $r['id'] ) );
		},
		$staff
	);
	$route(
		'/clients/(?P<id>\d+)/users/(?P<user>\d+)',
		'DELETE',
		function ( WP_REST_Request $r ) {
			$uid = (int) $r['user'];
			if ( hpv_p_user_client_id( $uid ) !== (int) $r['id'] ) {
				return new WP_Error( 'user', 'Ez a felhasználó nem ehhez az ügyfélhez tartozik.', array( 'status' => 400 ) );
			}
			delete_user_meta( $uid, 'hpv_client_id' );
			hpv_p_log( (int) $r['id'], 'system', sprintf( 'Portál-hozzáférés visszavonva: %s.', get_userdata( $uid )->user_email ?? $uid ), false, get_current_user_id() );
			return rest_ensure_response( hpv_client_full( (int) $r['id'] ) );
		},
		$staff
	);

	// Szolgáltatás-katalógus (számlázási joggal).
	$route(
		'/billing/services',
		'GET',
		fn() => rest_ensure_response( array_map( 'hpv_service_format', hpv_p_find( 'service', array(), array( 'orderby' => 'name', 'order' => 'ASC', 'limit' => 500 ) ) ) ),
		$bill
	);
	$route(
		'/billing/services',
		'POST',
		fn( WP_REST_Request $r ) => hpv_service_save( $r, 0 ),
		$bill
	);
	$route(
		'/billing/services/(?P<id>\d+)',
		'POST',
		fn( WP_REST_Request $r ) => hpv_service_save( $r, (int) $r['id'] ),
		$bill
	);
}

/**
 * A szerkesztő mezői a sémából (címke, típus, választások, súgó), hogy az app ugyanazt mutassa, mint az admin.
 */
function hpv_client_field_meta(): array {
	$out = array();
	foreach ( hpv_client_editable_fields() as $key ) {
		$f     = hpv_p_entity( 'client' )['fields'][ $key ];
		$out[] = array(
			'key'      => $key,
			'label'    => $f['label'],
			'type'     => $f['type'],
			'help'     => $f['help'] ?? '',
			'required' => ! empty( $f['required'] ),
			'options'  => isset( $f['options'] ) ? array_map( fn( $k, $v ) => array( 'key' => (string) $k, 'label' => $v[0] ), array_keys( $f['options'] ), $f['options'] ) : null,
			'group'    => in_array( $key, HPV_CLIENT_BILLING_FIELDS, true ) ? 'billing' : ( in_array( $key, array( 'gsc_property', 'ga4_property', 'gads_customer', 'meta_ad_account' ), true ) ? 'sources' : 'main' ),
		);
	}

	return $out;
}

function hpv_service_format( array $s ): array {
	return array(
		'id'          => (int) $s['id'],
		'name'        => $s['name'],
		'description' => (string) $s['description'],
		'price'       => hpv_p_to_cents( $s['price'] ) / 100,
		'billing'     => $s['billing'],
		'active'      => (bool) (int) $s['active'],
		'in_use'      => count( hpv_p_find( 'subscription', array( 'service_id' => (int) $s['id'], 'status' => 'active' ), array( 'limit' => 1000 ) ) ),
	);
}

function hpv_service_save( WP_REST_Request $r, int $id ) {
	if ( $id && ! hpv_p_get( 'service', $id ) ) {
		return new WP_Error( 'not_found', 'Nincs ilyen szolgáltatás.', array( 'status' => 404 ) );
	}
	$data = hpv_p_sanitize( 'service', $r->get_params() );
	if ( ! $id && '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
		return new WP_Error( 'name', 'Adj nevet a szolgáltatásnak.', array( 'status' => 400 ) );
	}
	if ( $id ) {
		hpv_p_update( 'service', $id, $data );
	} else {
		$id = hpv_p_insert( 'service', $data + array( 'active' => 1 ) );
	}

	return rest_ensure_response( hpv_service_format( hpv_p_get( 'service', $id ) ) );
}

/**
 * Az ügyfél-felhasználók utolsó belépése (a csapat látja az adatlapon).
 */
add_action(
	'wp_login',
	function ( $login, $user ) {
		if ( $user instanceof WP_User && hpv_p_user_client_id( $user->ID ) ) {
			update_user_meta( $user->ID, 'hpv_last_login', time() );
		}
	},
	10,
	2
);
