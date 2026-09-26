<?php
/**
 * Átköltözés a Bitrix24-ből: cégek, kapcsolatok, érdeklődők, üzletek, munkacsoportok (projektek) és feladatok.
 *
 * Kapcsolat: Bitrix24 bejövő webhook (Fejlesztői erőforrások → Egyéb → Bejövő webhook; jogok: crm, task,
 * sonet_group, user). Az URL titok: titkosítva tároljuk, és soha nem adjuk vissza teljes egészében.
 *
 * Futás lépésenként (egy kérés = egy Bitrix-oldal, 50 rekord), a CRM app hívja egymás után, így nincs időtúllépés.
 * Próbafuttatás: semmit nem ír, csak megszámolja, mi történne. Újrafuttatható: a már importált rekordokat
 * (hpv_bitrix_map) nem hozza létre újra, csak az itt még üres mezőket tölti ki; a helyi módosítás megmarad.
 * Levelet nem küld, portál-hozzáférést nem ad.
 */

defined( 'ABSPATH' ) || exit;

const HPV_BX_STEPS = array(
	'companies' => 'Cégek → ügyfelek',
	'contacts'  => 'Kapcsolatok → kapcsolattartók / ügyfelek',
	'leads'     => 'Érdeklődők → érdeklődő ügyfelek',
	'deals'     => 'Üzletek → belső jegyzet az ügyfélnél',
	'projects'  => 'Munkacsoportok → projektek',
	'tasks'     => 'Feladatok → feladatok',
);

/* ─── Kapcsolat ───────────────────────────────────────────── */

function hpv_bx_webhook(): string {
	$stored = (string) get_option( 'hpv_bitrix_webhook', '' );

	return $stored ? hpv_p_decrypt( $stored ) : '';
}

function hpv_bx_normalize_webhook( string $url ): string {
	$url = trim( $url );
	if ( ! preg_match( '#^https://[a-z0-9.-]+(:\d+)?/rest/\d+/[A-Za-z0-9_]+/?#i', $url, $m ) ) {
		return '';
	}

	return trailingslashit( $m[0] );
}

function hpv_bx_masked( string $url ): string {
	return $url ? preg_replace( '#(/rest/\d+/)([A-Za-z0-9_]{3})[A-Za-z0-9_]+/#', '$1$2•••/', $url ) : '';
}

/**
 * Bitrix24 REST hívás.
 *
 * @return array|WP_Error A teljes válasz (result, next, total).
 */
function hpv_bx_call( string $method, array $params = array() ) {
	$base = hpv_bx_webhook();
	if ( ! $base ) {
		return new WP_Error( 'bx_off', 'Nincs megadva a Bitrix24 webhook.' );
	}
	$res = wp_remote_post(
		$base . $method . '.json',
		array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( (object) $params ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'bx_http', 'A Bitrix24 nem érhető el: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'bx_http', 'Érvénytelen Bitrix24 válasz (HTTP ' . $code . ').' );
	}
	if ( ! empty( $data['error'] ) ) {
		$msg = array(
			'QUERY_LIMIT_EXCEEDED' => 'A Bitrix24 túl sok kérést kapott, pár másodperc múlva folytatjuk.',
			'expired_token'        => 'A webhook lejárt vagy törölték.',
			'invalid_token'        => 'A webhook érvénytelen.',
			'NO_AUTH_FOUND'        => 'A webhook érvénytelen.',
			'insufficient_scope'   => 'A webhooknak nincs meg minden joga (crm, task, sonet_group, user).',
			'ACCESS_DENIED'        => 'A webhooknak nincs meg minden joga (crm, task, sonet_group, user).',
		)[ $data['error'] ] ?? ( $data['error_description'] ?? $data['error'] );

		return new WP_Error( 'bx_' . strtolower( (string) $data['error'] ), $msg, array( 'retry' => 'QUERY_LIMIT_EXCEEDED' === $data['error'] ) );
	}

	return $data;
}

/* ─── Segédek ─────────────────────────────────────────────── */

function hpv_bx_first( $multi ): string {
	if ( ! is_array( $multi ) ) {
		return is_string( $multi ) ? trim( $multi ) : '';
	}
	foreach ( $multi as $item ) {
		$value = trim( (string) ( is_array( $item ) ? ( $item['VALUE'] ?? '' ) : $item ) );
		if ( '' !== $value ) {
			return $value;
		}
	}

	return '';
}

function hpv_bx_text( $bbcode ): string {
	$text = preg_replace( '#\[url=([^\]]+)\](.*?)\[/url\]#is', '$2 ($1)', (string) $bbcode );
	$text = preg_replace( '#\[/?[a-z*]+(?:=[^\]]*)?\]#i', '', $text );

	return trim( html_entity_decode( wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $text ) ), ENT_QUOTES, 'UTF-8' ) );
}

function hpv_bx_date( $value ): ?string {
	return is_string( $value ) && preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $m ) ? $m[1] : null;
}

/**
 * Ország: a cím, a telefonszám és az e-mail alapján (magyar jelek nélkül USA).
 */
function hpv_bx_country( string $country, string $phone, string $email ): string {
	if ( preg_match( '/hungary|magyar|^hu$/i', trim( $country ) ) ) {
		return 'HU';
	}
	if ( '' !== trim( $country ) ) {
		return 'US';
	}
	$digits = preg_replace( '/[^\d+]/', '', $phone );
	if ( preg_match( '/^(\+36|0036|06)/', $digits ) || preg_match( '/\.hu$/i', $email ) ) {
		return 'HU';
	}

	return 'US';
}

/* ─── Futás ───────────────────────────────────────────────── */

function hpv_bx_map(): array {
	$map = get_option( 'hpv_bitrix_map', array() );

	return is_array( $map ) ? $map : array();
}

function hpv_bx_run(): array {
	$run = get_option( 'hpv_bitrix_run', array() );

	return is_array( $run ) ? $run : array();
}

function hpv_bx_start( array $steps, bool $dry ): array {
	$steps = array_values( array_intersect( array_keys( HPV_BX_STEPS ), $steps ) );
	$run   = array(
		'id'          => wp_generate_password( 10, false, false ),
		'dry'         => $dry,
		'steps'       => $steps,
		'i'           => 0,
		'start'       => 0,
		'total'       => array(),
		'counts'      => array_fill_keys( $steps, array( 'created' => 0, 'matched' => 0, 'updated' => 0, 'skipped' => 0 ) ),
		'warnings'    => array(),
		'done'        => ! $steps,
		'started_at'  => time(),
		'finished_at' => $steps ? 0 : time(),
		'map'         => array(), // próbafuttatásnál az „importált” rekordok (álazonosítókkal)
		'users'       => null,
		'parents'     => array(),
		'misc'        => 0,
	);
	update_option( 'hpv_bitrix_run', $run, false );

	return $run;
}

/**
 * Egy lépés: a futó import következő Bitrix-oldala.
 *
 * @return array|WP_Error A frissített futás.
 */
function hpv_bx_step() {
	$run = hpv_bx_run();
	if ( ! $run || $run['done'] ) {
		return $run;
	}
	if ( get_transient( 'hpv_bitrix_lock' ) ) {
		return new WP_Error( 'bx_busy', 'Az import éppen fut.', array( 'retry' => true ) );
	}
	set_transient( 'hpv_bitrix_lock', 1, 2 * MINUTE_IN_SECONDS );

	try {
		$step = $run['steps'][ $run['i'] ];
		$fn   = 'hpv_bx_step_' . $step;
		$next = $fn( $run );
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		if ( null === $next ) {
			if ( 'tasks' === $step ) {
				hpv_bx_link_parents( $run );
			}
			$run['i']++;
			$run['start'] = 0;
			if ( $run['i'] >= count( $run['steps'] ) ) {
				$run['done']        = true;
				$run['finished_at'] = time();
			}
		} else {
			$run['start'] = (int) $next;
		}
		$run['warnings'] = array_slice( $run['warnings'], -50 );
		update_option( 'hpv_bitrix_run', $run, false );

		return $run;
	} finally {
		delete_transient( 'hpv_bitrix_lock' );
	}
}

/**
 * Egy Bitrix-oldal lekérése. Visszaadja a rekordokat és a következő kezdőpontot (null, ha nincs több).
 */
function hpv_bx_page( array &$run, string $method, array $params, string $list_key = '' ) {
	$res = hpv_bx_call( $method, array_merge( $params, array( 'start' => $run['start'] ) ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$step                  = $run['steps'][ $run['i'] ];
	$run['total'][ $step ] = (int) ( $res['total'] ?? 0 );
	$rows                  = $list_key ? (array) ( $res['result'][ $list_key ] ?? array() ) : (array) ( $res['result'] ?? array() );

	return array( $rows, isset( $res['next'] ) ? (int) $res['next'] : null );
}

/**
 * Helyi azonosító egy Bitrix rekordhoz (éles: a tartós térkép, próba: a futás saját térképe is).
 */
function hpv_bx_local( array $run, string $type, $bx_id ): int {
	$bx_id = (string) $bx_id;
	if ( isset( $run['map'][ $type ][ $bx_id ] ) ) {
		return (int) $run['map'][ $type ][ $bx_id ];
	}
	$id     = (int) ( hpv_bx_map()[ $type ][ $bx_id ] ?? 0 );
	$entity = array( 'company' => 'client', 'contact' => 'client', 'lead' => 'client', 'deal' => 'activity', 'group' => 'project', 'task' => 'task' )[ $type ];

	return $id && hpv_p_get( $entity, $id ) ? $id : 0; // a helyben azóta törölt rekord újra létrejöhet
}

function hpv_bx_remember( array &$run, string $type, $bx_id, int $local ): void {
	if ( $run['dry'] ) {
		$run['map'][ $type ][ (string) $bx_id ] = $local;
		return;
	}
	$map                              = hpv_bx_map();
	$map[ $type ][ (string) $bx_id ] = $local;
	update_option( 'hpv_bitrix_map', $map, false );
}

function hpv_bx_count( array &$run, string $what ): void {
	$run['counts'][ $run['steps'][ $run['i'] ] ][ $what ]++;
}

/**
 * Új rekord (próbafuttatásnál csak egy negatív álazonosító).
 */
function hpv_bx_insert( array &$run, string $entity, array $data ): int {
	if ( $run['dry'] ) {
		return -1 - count( $run['map'], COUNT_RECURSIVE );
	}

	return hpv_p_insert( $entity, $data );
}

/**
 * Meglévő ügyfél: csak az itt még üres mezőket töltjük ki.
 */
function hpv_bx_fill_client( array &$run, int $client_id, array $data ): void {
	$client = $client_id > 0 ? hpv_p_get( 'client', $client_id ) : null;
	if ( ! $client ) {
		return;
	}
	$fill = array();
	foreach ( $data as $key => $value ) {
		if ( '' !== (string) $value && '' === trim( (string) ( $client[ $key ] ?? '' ) ) ) {
			$fill[ $key ] = $value;
		}
	}
	if ( $fill ) {
		hpv_bx_count( $run, 'updated' );
		if ( ! $run['dry'] ) {
			hpv_p_update( 'client', $client_id, $fill );
		}
	}
}

/**
 * Már meglévő (kézzel felvitt) ügyfél keresése név vagy e-mail alapján, hogy ne legyen dupla.
 */
function hpv_bx_find_client( string $name, string $email ): int {
	global $wpdb;
	$table = hpv_p_table( 'client' );
	if ( '' !== $name ) {
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE LOWER(name) = LOWER(%s) LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $id ) {
			return $id;
		}
	}
	if ( is_email( $email ) ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE LOWER(email) = LOWER(%s) LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	return 0;
}

function hpv_bx_client_data( array $src, string $name ): array {
	$email   = sanitize_email( hpv_bx_first( $src['EMAIL'] ?? '' ) );
	$phone   = hpv_bx_first( $src['PHONE'] ?? '' );
	$country = hpv_bx_country( (string) ( $src['ADDRESS_COUNTRY'] ?? '' ), $phone, $email );
	$data    = array(
		'name'    => $name,
		'email'   => $email,
		'phone'   => sanitize_text_field( $phone ),
		'website' => esc_url_raw( hpv_bx_first( $src['WEB'] ?? '' ) ),
		'country' => $country,
		'street'  => sanitize_text_field( trim( ( $src['ADDRESS'] ?? '' ) . ' ' . ( $src['ADDRESS_2'] ?? '' ) ) ),
		'city'    => sanitize_text_field( (string) ( $src['ADDRESS_CITY'] ?? '' ) ),
		'zip'     => sanitize_text_field( (string) ( $src['ADDRESS_POSTAL_CODE'] ?? '' ) ),
		'state'   => 'US' === $country ? sanitize_text_field( (string) ( $src['ADDRESS_PROVINCE'] ?? '' ) ) : '',
		'notes'   => hpv_bx_text( $src['COMMENTS'] ?? '' ),
	);
	if ( $data['website'] && ! preg_match( '#^https?://#', $data['website'] ) ) {
		$data['website'] = 'https://' . $data['website'];
	}

	return $data;
}

/* ─── Lépések ─────────────────────────────────────────────── */

function hpv_bx_step_companies( array &$run ) {
	$page = hpv_bx_page( $run, 'crm.company.list', array( 'order' => array( 'ID' => 'ASC' ), 'select' => array( 'ID', 'TITLE', 'PHONE', 'EMAIL', 'WEB', 'COMMENTS', 'ADDRESS', 'ADDRESS_2', 'ADDRESS_CITY', 'ADDRESS_POSTAL_CODE', 'ADDRESS_PROVINCE', 'ADDRESS_COUNTRY' ) ) );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	foreach ( $page[0] as $co ) {
		$name = sanitize_text_field( (string) ( $co['TITLE'] ?? '' ) );
		if ( '' === $name ) {
			hpv_bx_count( $run, 'skipped' );
			continue;
		}
		$data  = hpv_bx_client_data( $co, $name );
		$local = hpv_bx_local( $run, 'company', $co['ID'] );
		if ( ! $local && ( $local = hpv_bx_find_client( $name, $data['email'] ) ) ) {
			hpv_bx_count( $run, 'matched' );
		}
		if ( $local ) {
			hpv_bx_fill_client( $run, $local, $data );
		} else {
			$local = hpv_bx_insert( $run, 'client', array_merge( $data, array( 'status' => 'active' ) ) );
			hpv_bx_count( $run, 'created' );
		}
		hpv_bx_remember( $run, 'company', $co['ID'], $local );
	}

	return $page[1];
}

function hpv_bx_step_contacts( array &$run ) {
	$page = hpv_bx_page( $run, 'crm.contact.list', array( 'order' => array( 'ID' => 'ASC' ), 'select' => array( 'ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'POST', 'PHONE', 'EMAIL', 'COMPANY_ID', 'COMMENTS', 'ADDRESS', 'ADDRESS_CITY', 'ADDRESS_POSTAL_CODE', 'ADDRESS_PROVINCE', 'ADDRESS_COUNTRY' ) ) );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	foreach ( $page[0] as $ct ) {
		$full  = sanitize_text_field( trim( preg_replace( '/\s+/', ' ', ( $ct['NAME'] ?? '' ) . ' ' . ( $ct['SECOND_NAME'] ?? '' ) . ' ' . ( $ct['LAST_NAME'] ?? '' ) ) ) );
		$data  = hpv_bx_client_data( $ct, $full );
		$email = $data['email'];
		if ( '' === $full && '' === $email ) {
			hpv_bx_count( $run, 'skipped' );
			continue;
		}
		$company = ! empty( $ct['COMPANY_ID'] ) ? hpv_bx_local( $run, 'company', $ct['COMPANY_ID'] ) : 0;
		if ( $company ) {
			// A cég kapcsolattartója: csak az üres mezők.
			hpv_bx_fill_client( $run, $company, array( 'contact_name' => $full, 'email' => $email, 'phone' => $data['phone'] ) );
			hpv_bx_remember( $run, 'contact', $ct['ID'], $company );
			hpv_bx_count( $run, 'matched' );
			continue;
		}
		// Cég nélküli kapcsolat: magánszemély ügyfél.
		$local = hpv_bx_local( $run, 'contact', $ct['ID'] ) ?: hpv_bx_find_client( $full, $email );
		if ( $local ) {
			hpv_bx_fill_client( $run, $local, array_merge( $data, array( 'contact_name' => $full ) ) );
			hpv_bx_count( $run, 'matched' );
		} else {
			$local = hpv_bx_insert( $run, 'client', array_merge( $data, array( 'name' => $full ?: $email, 'contact_name' => $full, 'status' => 'active' ) ) );
			hpv_bx_count( $run, 'created' );
		}
		hpv_bx_remember( $run, 'contact', $ct['ID'], $local );
	}

	return $page[1];
}

function hpv_bx_step_leads( array &$run ) {
	$page = hpv_bx_page( $run, 'crm.lead.list', array( 'order' => array( 'ID' => 'ASC' ), 'select' => array( 'ID', 'TITLE', 'NAME', 'LAST_NAME', 'COMPANY_TITLE', 'STATUS_ID', 'PHONE', 'EMAIL', 'WEB', 'COMMENTS', 'OPPORTUNITY', 'CURRENCY_ID', 'SOURCE_ID', 'DATE_CREATE' ) ) );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	foreach ( $page[0] as $ld ) {
		// Az átalakított érdeklődő már cég/kapcsolat/üzlet; a „szemét” nem kell.
		if ( in_array( $ld['STATUS_ID'] ?? '', array( 'CONVERTED', 'JUNK' ), true ) ) {
			hpv_bx_count( $run, 'skipped' );
			continue;
		}
		$person = sanitize_text_field( trim( ( $ld['NAME'] ?? '' ) . ' ' . ( $ld['LAST_NAME'] ?? '' ) ) );
		$name   = sanitize_text_field( (string) ( $ld['COMPANY_TITLE'] ?? '' ) ) ?: ( $person ?: sanitize_text_field( (string) ( $ld['TITLE'] ?? '' ) ) );
		if ( '' === $name ) {
			hpv_bx_count( $run, 'skipped' );
			continue;
		}
		$data          = hpv_bx_client_data( $ld, $name );
		$data['notes'] = trim( 'Bitrix24 érdeklődő: ' . sanitize_text_field( (string) ( $ld['TITLE'] ?? '' ) ) . ( (float) ( $ld['OPPORTUNITY'] ?? 0 ) ? ' · ' . $ld['OPPORTUNITY'] . ' ' . ( $ld['CURRENCY_ID'] ?? '' ) : '' ) . "\n" . $data['notes'] );
		$local         = hpv_bx_local( $run, 'lead', $ld['ID'] ) ?: hpv_bx_find_client( $name, $data['email'] );
		if ( $local ) {
			hpv_bx_fill_client( $run, $local, array_merge( $data, array( 'contact_name' => $person ) ) );
			hpv_bx_count( $run, 'matched' );
		} else {
			$created_on = hpv_bx_date( $ld['DATE_CREATE'] ?? '' );
			// A Bitrix24 forrása (Honnan) csatornaként megmarad, hogy a régi érdeklődők is látszódjanak a kimutatásban.
			$channel    = array( 'ADVERTISING' => 'paid_other', 'EMAIL' => 'email', 'CALL' => 'offline', 'RECOMMENDATION' => 'offline', 'PARTNER' => 'referral', 'WEB' => 'unknown', 'WEBFORM' => 'unknown' )[ $ld['SOURCE_ID'] ?? '' ] ?? 'import';
			$local      = hpv_bx_insert( $run, 'client', array_merge( $data, array( 'contact_name' => $person, 'status' => 'lead', 'lead_source' => 'bitrix', 'lead_channel' => $channel ), $created_on ? array( 'lead_at' => $created_on . ' 12:00:00' ) : array() ) );
			hpv_bx_count( $run, 'created' );
		}
		hpv_bx_remember( $run, 'lead', $ld['ID'], $local );
	}

	return $page[1];
}

function hpv_bx_step_deals( array &$run ) {
	$page = hpv_bx_page( $run, 'crm.deal.list', array( 'order' => array( 'ID' => 'ASC' ), 'select' => array( 'ID', 'TITLE', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'COMPANY_ID', 'CONTACT_ID', 'BEGINDATE', 'CLOSEDATE', 'CLOSED', 'COMMENTS' ) ) );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	foreach ( $page[0] as $dl ) {
		if ( hpv_bx_local( $run, 'deal', $dl['ID'] ) ) {
			hpv_bx_count( $run, 'skipped' ); // már importálva
			continue;
		}
		$client = ( ! empty( $dl['COMPANY_ID'] ) ? hpv_bx_local( $run, 'company', $dl['COMPANY_ID'] ) : 0 )
			?: ( ! empty( $dl['CONTACT_ID'] ) ? hpv_bx_local( $run, 'contact', $dl['CONTACT_ID'] ) : 0 );
		if ( ! $client ) {
			hpv_bx_count( $run, 'skipped' );
			$run['warnings'][] = sprintf( 'Üzlet ügyfél nélkül kimaradt: %s (#%s)', $dl['TITLE'] ?? '', $dl['ID'] );
			continue;
		}
		$semantic = array( 'S' => 'megnyert', 'F' => 'elveszett' )[ $dl['STAGE_SEMANTIC_ID'] ?? '' ] ?? 'folyamatban';
		$body     = sprintf(
			"Bitrix24 üzlet: %s\nÁllapot: %s (%s)%s%s%s",
			sanitize_text_field( (string) ( $dl['TITLE'] ?? '' ) ),
			$semantic,
			sanitize_text_field( (string) ( $dl['STAGE_ID'] ?? '' ) ),
			(float) ( $dl['OPPORTUNITY'] ?? 0 ) ? "\nÖsszeg: " . $dl['OPPORTUNITY'] . ' ' . ( $dl['CURRENCY_ID'] ?? '' ) : '',
			hpv_bx_date( $dl['CLOSEDATE'] ?? '' ) ? "\nZárás: " . hpv_bx_date( $dl['CLOSEDATE'] ) : '',
			hpv_bx_text( $dl['COMMENTS'] ?? '' ) ? "\n\n" . hpv_bx_text( $dl['COMMENTS'] ) : ''
		);
		$local = hpv_bx_insert( $run, 'activity', array( 'client_id' => $client, 'type' => 'note', 'body' => $body, 'visible' => 0 ) );
		hpv_bx_count( $run, 'created' );
		hpv_bx_remember( $run, 'deal', $dl['ID'], $local );
		// Megnyert üzlet: az érdeklődőből ügyfél lesz.
		if ( 'megnyert' === $semantic && ! $run['dry'] && $client > 0 && 'lead' === ( hpv_p_get( 'client', $client )['status'] ?? '' ) ) {
			hpv_p_update( 'client', $client, array( 'status' => 'active' ) );
		}
	}

	return $page[1];
}

function hpv_bx_step_projects( array &$run ) {
	$page = hpv_bx_page( $run, 'sonet_group.get', array( 'ORDER' => array( 'ID' => 'ASC' ) ) );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	$names = hpv_bx_client_names();
	foreach ( $page[0] as $gr ) {
		if ( hpv_bx_local( $run, 'group', $gr['ID'] ) ) {
			hpv_bx_count( $run, 'skipped' );
			continue;
		}
		$name = sanitize_text_field( (string) ( $gr['NAME'] ?? '' ) );
		// Ha a csoport neve egy ügyfél nevével kezdődik („Sun Pools – weboldal”), az ügyfélhez kötjük (a leghosszabb egyezés nyer).
		$client = 0;
		$best   = 0;
		foreach ( $names as $cid => $cname ) {
			$len = mb_strlen( $cname );
			if ( $len >= 3 && $len > $best && 0 === mb_stripos( $name, $cname ) ) {
				$client = $cid;
				$best   = $len;
			}
		}
		$local = hpv_bx_insert(
			$run,
			'project',
			array(
				'client_id'   => $client,
				'name'        => $name ?: 'Bitrix24 csoport #' . $gr['ID'],
				'description' => hpv_bx_text( $gr['DESCRIPTION'] ?? '' ),
				'status'      => 'Y' === ( $gr['CLOSED'] ?? 'N' ) ? 'completed' : 'in_progress',
				'start_date'  => hpv_bx_date( $gr['PROJECT_DATE_START'] ?? '' ),
				'due_date'    => hpv_bx_date( $gr['PROJECT_DATE_FINISH'] ?? '' ),
				'visible'     => 0, // átnézés után lehet az ügyfélnek megmutatni
				'owner_id'    => get_current_user_id(),
			)
		);
		hpv_bx_count( $run, 'created' );
		hpv_bx_remember( $run, 'group', $gr['ID'], $local );
	}

	return $page[1];
}

function hpv_bx_client_names(): array {
	return wp_list_pluck( hpv_p_find( 'client', array(), array( 'limit' => 5000 ) ), 'name', 'id' );
}

/**
 * Bitrix felhasználó → WordPress munkatárs (e-mail alapján). Egyszer, a feladatok előtt.
 */
function hpv_bx_users( array &$run ) {
	if ( is_array( $run['users'] ) ) {
		return $run['users'];
	}
	$users = array();
	$start = 0;
	for ( $i = 0; $i < 20 && null !== $start; $i++ ) {
		$res = hpv_bx_call( 'user.get', array( 'start' => $start ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		foreach ( (array) ( $res['result'] ?? array() ) as $u ) {
			$wp = get_user_by( 'email', (string) ( $u['EMAIL'] ?? '' ) );
			if ( $wp && hpv_p_is_staff( $wp->ID ) ) {
				$users[ (string) $u['ID'] ] = $wp->ID;
			}
		}
		$start = isset( $res['next'] ) ? (int) $res['next'] : null;
	}
	$run['users'] = $users;

	return $users;
}

function hpv_bx_step_tasks( array &$run ) {
	$users = hpv_bx_users( $run );
	if ( is_wp_error( $users ) ) {
		return $users;
	}
	$page = hpv_bx_page( $run, 'tasks.task.list', array( 'order' => array( 'ID' => 'asc' ), 'select' => array( 'ID', 'TITLE', 'DESCRIPTION', 'STATUS', 'PRIORITY', 'DEADLINE', 'START_DATE_PLAN', 'GROUP_ID', 'PARENT_ID', 'RESPONSIBLE_ID', 'CLOSED_DATE' ) ), 'tasks' );
	if ( is_wp_error( $page ) ) {
		return $page;
	}
	$statuses = array( 1 => 'todo', 2 => 'todo', 3 => 'in_progress', 4 => 'review', 5 => 'done', 6 => 'todo', 7 => 'done' );
	foreach ( $page[0] as $t ) {
		// A tasks.task.list kisbetűs (camelCase) kulcsokat ad.
		$id = (string) ( $t['id'] ?? $t['ID'] ?? '' );
		if ( '' === $id || hpv_bx_local( $run, 'task', $id ) ) {
			hpv_bx_count( $run, 'skipped' );
			continue;
		}
		$group   = (string) ( $t['groupId'] ?? $t['GROUP_ID'] ?? '0' );
		$project = '0' !== $group ? hpv_bx_local( $run, 'group', $group ) : 0;
		if ( ! $project ) {
			$project = hpv_bx_misc_project( $run );
		}
		$local = hpv_bx_insert(
			$run,
			'task',
			array(
				'project_id'   => $project,
				'title'        => sanitize_text_field( (string) ( $t['title'] ?? $t['TITLE'] ?? '' ) ) ?: 'Bitrix24 feladat #' . $id,
				'description'  => hpv_bx_text( $t['description'] ?? $t['DESCRIPTION'] ?? '' ),
				'status'       => $statuses[ (int) ( $t['status'] ?? $t['STATUS'] ?? 2 ) ] ?? 'todo',
				'priority'     => 2 === (int) ( $t['priority'] ?? $t['PRIORITY'] ?? 1 ) ? 'high' : 'normal',
				'due_date'     => hpv_bx_date( $t['deadline'] ?? $t['DEADLINE'] ?? '' ),
				'start_date'   => hpv_bx_date( $t['startDatePlan'] ?? $t['START_DATE_PLAN'] ?? '' ),
				'assignee_id'  => $users[ (string) ( $t['responsibleId'] ?? $t['RESPONSIBLE_ID'] ?? '' ) ] ?? 0,
				'visible'      => 0,
				'sort'         => (int) $id,
				'created_by'   => get_current_user_id(),
			)
		);
		hpv_bx_count( $run, 'created' );
		hpv_bx_remember( $run, 'task', $id, $local );
		$parent = (string) ( $t['parentId'] ?? $t['PARENT_ID'] ?? '0' );
		if ( '0' !== $parent && '' !== $parent ) {
			$run['parents'][ $local ] = $parent;
		}
	}

	return $page[1];
}

/**
 * Csoport nélküli feladatok gyűjtőprojektje (belső).
 */
function hpv_bx_misc_project( array &$run ): int {
	if ( $run['misc'] ) {
		return (int) $run['misc'];
	}
	$existing    = (int) get_option( 'hpv_bitrix_misc_project' );
	$run['misc'] = $existing && hpv_p_get( 'project', $existing ) ? $existing : hpv_bx_insert(
		$run,
		'project',
		array( 'client_id' => 0, 'name' => 'Bitrix24 — csoport nélküli feladatok', 'status' => 'in_progress', 'visible' => 0, 'owner_id' => get_current_user_id() )
	);
	if ( ! $run['dry'] ) {
		update_option( 'hpv_bitrix_misc_project', $run['misc'], false );
	}

	return (int) $run['misc'];
}

/**
 * Alfeladatok: a szülő a feladatok végén már biztosan megvan.
 */
function hpv_bx_link_parents( array &$run ): void {
	foreach ( $run['parents'] as $local => $bx_parent ) {
		$parent = hpv_bx_local( $run, 'task', $bx_parent );
		if ( $parent && ! $run['dry'] ) {
			hpv_p_update( 'task', (int) $local, array( 'parent_id' => $parent ) );
		}
	}
	$run['parents'] = array();
}

/* ─── REST (csak adminisztrátor) ──────────────────────────── */

add_action( 'rest_api_init', 'hpv_bx_routes' );

function hpv_bx_routes() {
	$admin = fn() => current_user_can( 'manage_options' );
	register_rest_route( 'hpv/v1', '/import/bitrix', array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => fn() => rest_ensure_response( hpv_bx_status() ) ) );
	register_rest_route( 'hpv/v1', '/import/bitrix/connect', array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => 'hpv_bx_rest_connect' ) );
	register_rest_route(
		'hpv/v1',
		'/import/bitrix/disconnect',
		array(
			'methods'             => 'POST',
			'permission_callback' => $admin,
			'callback'            => function () {
				delete_option( 'hpv_bitrix_webhook' );
				return rest_ensure_response( hpv_bx_status() );
			},
		)
	);
	register_rest_route(
		'hpv/v1',
		'/import/bitrix/start',
		array(
			'methods'             => 'POST',
			'permission_callback' => $admin,
			'callback'            => function ( WP_REST_Request $req ) {
				if ( ! hpv_bx_webhook() ) {
					return new WP_Error( 'bx_off', 'Előbb add meg a Bitrix24 webhookot.', array( 'status' => 400 ) );
				}
				$run = hpv_bx_run();
				if ( $run && ! $run['done'] && time() - (int) $run['started_at'] < HOUR_IN_SECONDS && ! $req['force'] ) {
					return new WP_Error( 'bx_running', 'Már fut egy import.', array( 'status' => 409 ) );
				}
				hpv_bx_start( array_map( 'sanitize_key', (array) $req['steps'] ), rest_sanitize_boolean( $req['dry'] ?? true ) );
				return rest_ensure_response( hpv_bx_status() );
			},
		)
	);
	register_rest_route(
		'hpv/v1',
		'/import/bitrix/step',
		array(
			'methods'             => 'POST',
			'permission_callback' => $admin,
			'callback'            => function () {
				$run = hpv_bx_step();
				if ( is_wp_error( $run ) ) {
					$retry = ! empty( $run->get_error_data()['retry'] );
					return new WP_Error( $run->get_error_code(), $run->get_error_message(), array( 'status' => $retry ? 429 : 502, 'retry' => $retry ) );
				}
				return rest_ensure_response( hpv_bx_status() );
			},
		)
	);
}

function hpv_bx_rest_connect( WP_REST_Request $req ) {
	$url = hpv_bx_normalize_webhook( (string) $req['webhook'] );
	if ( ! $url ) {
		return new WP_Error( 'bx_url', 'Ez nem Bitrix24 webhook cím. Formátum: https://cegnev.bitrix24.hu/rest/1/abc123…/', array( 'status' => 400 ) );
	}
	$prev = get_option( 'hpv_bitrix_webhook' );
	update_option( 'hpv_bitrix_webhook', hpv_p_encrypt( $url ), false );
	$me = hpv_bx_call( 'profile' );
	if ( is_wp_error( $me ) ) {
		$prev ? update_option( 'hpv_bitrix_webhook', $prev, false ) : delete_option( 'hpv_bitrix_webhook' );
		return new WP_Error( 'bx_test', 'A kapcsolat nem sikerült: ' . $me->get_error_message(), array( 'status' => 400 ) );
	}
	update_option( 'hpv_bitrix_profile', trim( ( $me['result']['NAME'] ?? '' ) . ' ' . ( $me['result']['LAST_NAME'] ?? '' ) ), false );

	return rest_ensure_response( hpv_bx_status() );
}

function hpv_bx_status(): array {
	$run  = hpv_bx_run();
	$map  = hpv_bx_map();
	$step = $run && ! $run['done'] ? $run['steps'][ $run['i'] ] : '';

	return array(
		'connected' => (bool) hpv_bx_webhook(),
		'webhook'   => hpv_bx_masked( hpv_bx_webhook() ),
		'profile'   => (string) get_option( 'hpv_bitrix_profile', '' ),
		'steps'     => array_map( fn( $k, $l ) => array( 'key' => $k, 'label' => $l ), array_keys( HPV_BX_STEPS ), HPV_BX_STEPS ),
		'imported'  => array_map( 'count', $map ),
		'run'       => $run ? array(
			'id'       => $run['id'],
			'dry'      => $run['dry'],
			'done'     => $run['done'],
			'step'     => $step,
			'stepIndex' => $run['i'],
			'steps'    => $run['steps'],
			'start'    => $run['start'],
			'total'    => $run['total'],
			'counts'   => $run['counts'],
			'warnings' => $run['warnings'],
			'started'  => $run['started_at'],
			'finished' => $run['finished_at'],
		) : null,
	);
}
