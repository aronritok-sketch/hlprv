<?php
/**
 * Jogosultságok munkatársanként: ki számlázhat, ki készíthet szerződést és ajánlatot.
 * Az adminisztrátor mindent tud; a többieknek az adminisztrátor kapcsolja be (CRM → Csapat).
 * Akinek nincs számlázási joga, a bevételi számokat és az ügyfelek pénzügyi adatait sem látja.
 */

defined( 'ABSPATH' ) || exit;

const HPV_CAPS = array(
	'invoices'  => array( 'hpv_invoices', 'Számlázás', 'Számlák, fizetések, szolgáltatások és díjak, bevételi számok' ),
	'contracts' => array( 'hpv_contracts', 'Szerződések', 'Szerződések készítése, kiküldése aláírásra' ),
	'proposals' => array( 'hpv_proposals', 'Ajánlatok', 'Árajánlatok készítése és kiküldése' ),
);

/**
 * Melyik entitáshoz melyik jog kell (ami nincs itt, azt minden munkatárs kezelheti).
 */
const HPV_ENTITY_CAPS = array(
	'invoice'      => 'invoices',
	'invoice_item' => 'invoices',
	'payment'      => 'invoices',
	'subscription' => 'invoices',
	'service'      => 'invoices',
	'contract'     => 'contracts',
	'proposal'     => 'proposals',
);

/**
 * @param string $key invoices | contracts | proposals
 */
function hpv_p_can( string $key, int $user_id = 0 ): bool {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id || ! hpv_p_is_staff( $user_id ) ) {
		return false;
	}
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}

	return isset( HPV_CAPS[ $key ] ) && user_can( $user_id, HPV_CAPS[ $key ][0] );
}

function hpv_p_can_entity( string $entity, int $user_id = 0 ): bool {
	return hpv_p_is_staff( $user_id ?: get_current_user_id() ) && ( ! isset( HPV_ENTITY_CAPS[ $entity ] ) || hpv_p_can( HPV_ENTITY_CAPS[ $entity ], $user_id ) );
}

/**
 * A jogok listája egy felhasználóra (a webalkalmazásnak).
 */
function hpv_p_caps_for( int $user_id ): array {
	$out = array( 'admin' => user_can( $user_id, 'manage_options' ) );
	foreach ( array_keys( HPV_CAPS ) as $key ) {
		$out[ $key ] = hpv_p_can( $key, $user_id );
	}

	return $out;
}

function hpv_p_set_caps( int $user_id, array $caps ): void {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}
	foreach ( HPV_CAPS as $key => $def ) {
		if ( ! array_key_exists( $key, $caps ) ) {
			continue;
		}
		if ( ! empty( $caps[ $key ] ) ) {
			$user->add_cap( $def[0] );
		} else {
			$user->remove_cap( $def[0] );
		}
	}
}

/* ─── REST: csapat (csak adminisztrátor) ──────────────────── */

add_action( 'rest_api_init', 'hpv_p_team_routes' );

function hpv_p_team_routes() {
	$admin = fn() => current_user_can( 'manage_options' );
	register_rest_route(
		'hpv/v1',
		'/team',
		array(
			'methods'             => 'GET',
			'permission_callback' => $admin,
			'callback'            => fn() => rest_ensure_response( hpv_p_team() ),
		)
	);
	register_rest_route(
		'hpv/v1',
		'/team/(?P<id>\d+)',
		array(
			'methods'             => 'POST',
			'permission_callback' => $admin,
			'callback'            => function ( WP_REST_Request $request ) {
				$user_id = (int) $request['id'];
				if ( ! hpv_p_is_staff( $user_id ) ) {
					return new WP_Error( 'not_staff', 'Csak munkatárs jogai állíthatók.', array( 'status' => 400 ) );
				}
				if ( user_can( $user_id, 'manage_options' ) ) {
					return new WP_Error( 'admin', 'Az adminisztrátor mindenhez hozzáfér.', array( 'status' => 400 ) );
				}
				$caps = array();
				foreach ( array_keys( HPV_CAPS ) as $key ) {
					if ( null !== $request->get_param( $key ) ) {
						$caps[ $key ] = rest_sanitize_boolean( $request->get_param( $key ) );
					}
				}
				hpv_p_set_caps( $user_id, $caps );
				clean_user_cache( $user_id );

				return rest_ensure_response( hpv_p_team() );
			},
		)
	);
}

function hpv_p_team(): array {
	$users = get_users(
		array(
			'role__in' => array( 'administrator', 'hpv_staff' ),
			'orderby'  => 'display_name',
		)
	);
	$caps  = array();
	foreach ( HPV_CAPS as $key => $def ) {
		$caps[] = array(
			'key'   => $key,
			'label' => $def[1],
			'help'  => $def[2],
		);
	}

	return array(
		'caps'  => $caps,
		'users' => array_map(
			fn( WP_User $u ) => array_merge(
				array( 'id' => $u->ID ),
				hpv_chat_user_label( $u->ID ),
				array(
					'email' => $u->user_email,
					'caps'  => hpv_p_caps_for( $u->ID ),
				)
			),
			array_values( array_filter( $users, fn( $u ) => hpv_p_is_staff( $u->ID ) ) )
		),
	);
}
