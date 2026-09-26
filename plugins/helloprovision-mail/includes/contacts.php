<?php
/**
 * A CRM ügyfelek e-mail címei az SEO OS szerverre, hogy a beérkező levél magától a megfelelő ügyfélhez kerüljön.
 * Óránként teljes lista, ügyfél felvételekor / módosításakor azonnal az adott ügyfél.
 */

defined( 'ABSPATH' ) || exit;

function hpv_mail_client_contact( array $c ): array {
	$emails = array_filter( array_map( 'strtolower', array_map( 'trim', array( (string) ( $c['email'] ?? '' ), (string) ( $c['billing_email'] ?? '' ) ) ) ) );

	return array( 'client_id' => (int) $c['id'], 'name' => (string) $c['name'], 'emails' => array_values( array_unique( $emails ) ) );
}

function hpv_mail_push_contacts( bool $full = true, ?array $only = null ): array {
	if ( ! hpv_mail_ready() ) {
		return array( 'error' => 'A levelezéshez az SEO OS és a CRM bővítmény is kell.' );
	}
	$clients  = null === $only ? hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ) : $only;
	$contacts = array_values( array_filter( array_map( 'hpv_mail_client_contact', $clients ), fn( $c ) => $c['emails'] ) );
	// Portál felhasználók (az ügyfél munkatársai) címei is az ügyfélhez tartoznak.
	if ( null === $only ) {
		foreach ( get_users( array( 'role' => 'hpv_client', 'fields' => array( 'ID', 'user_email', 'display_name' ) ) ) as $u ) {
			$cid = (int) get_user_meta( $u->ID, 'hpv_client_id', true );
			if ( $cid ) {
				$contacts[] = array( 'client_id' => $cid, 'name' => $u->display_name, 'emails' => array( strtolower( $u->user_email ) ) );
			}
		}
	}
	$res = hpv_seo_api_json( 'POST', 'system/mail-contacts', array( 'contacts' => $contacts, 'full' => $full ), HPV_SEO_SYSTEM );

	return is_wp_error( $res ) ? array( 'error' => $res->get_error_message() ) : (array) $res;
}

add_action( 'hpv_mail_contacts', fn() => hpv_mail_push_contacts( true ) );

foreach ( array( 'hpv_p_inserted_client', 'hpv_p_updated_client' ) as $hook ) {
	add_action(
		$hook,
		function ( $id ) {
			$client = hpv_p_get( 'client', (int) $id );
			if ( $client && hpv_mail_ready() && '' !== hpv_seo_secret() ) {
				hpv_mail_push_contacts( false, array( $client ) );
			}
		}
	);
}
