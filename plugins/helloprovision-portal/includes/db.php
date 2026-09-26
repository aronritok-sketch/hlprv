<?php
/**
 * Adatbázis réteg: CRUD a sémában leírt entitásokra, bemenet-tisztítás, hozzáférés.
 */

defined( 'ABSPATH' ) || exit;

function hpv_p_table( string $entity ): string {
	global $wpdb;

	return $wpdb->prefix . 'hpv_' . hpv_p_entity( $entity )['table'];
}

function hpv_p_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	foreach ( hpv_p_schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
		dbDelta( $sql );
	}
	update_option( 'hpv_portal_db_version', HPV_PORTAL_DB_VERSION, false );
}

function hpv_p_get( string $entity, int $id ): ?array {
	global $wpdb;
	if ( $id <= 0 ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . hpv_p_table( $entity ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	return $row ?: null;
}

/**
 * @param array $where  mező => érték (tömb érték = IN (...)). Csak a sémában létező mezők.
 * @param array $args   orderby, order, limit, search (szöveg a text típusú mezőkben)
 */
function hpv_p_find( string $entity, array $where = array(), array $args = array() ): array {
	global $wpdb;
	$def    = hpv_p_entity( $entity );
	$fields = array_merge( array( 'id', 'created_at', 'updated_at' ), array_keys( $def['fields'] ) );

	$sql    = array();
	$params = array();
	foreach ( $where as $key => $value ) {
		if ( ! in_array( $key, $fields, true ) ) {
			continue;
		}
		if ( is_array( $value ) ) {
			if ( ! $value ) {
				return array();
			}
			$sql[]  = "$key IN (" . implode( ',', array_fill( 0, count( $value ), '%s' ) ) . ')';
			$params = array_merge( $params, array_values( $value ) );
		} else {
			$sql[]    = "$key = %s";
			$params[] = $value;
		}
	}

	foreach ( array( 'after_id' => '>', 'before_id' => '<' ) as $arg => $op ) {
		if ( ! empty( $args[ $arg ] ) ) {
			$sql[]    = "id $op %d";
			$params[] = (int) $args[ $arg ];
		}
	}

	if ( ! empty( $args['search'] ) ) {
		$like = array();
		foreach ( $def['fields'] as $key => $field ) {
			if ( in_array( $field['type'], array( 'text', 'email', 'tel', 'url' ), true ) ) {
				$like[]   = "$key LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			}
		}
		if ( $like ) {
			$sql[] = '(' . implode( ' OR ', $like ) . ')';
		}
	}

	$orderby = in_array( $args['orderby'] ?? '', $fields, true ) ? $args['orderby'] : 'id';
	$order   = 'ASC' === strtoupper( $args['order'] ?? 'DESC' ) ? 'ASC' : 'DESC';
	$limit   = max( 1, min( 2000, (int) ( $args['limit'] ?? 500 ) ) );

	$query = 'SELECT * FROM ' . hpv_p_table( $entity ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' ) . " ORDER BY $orderby $order, id $order LIMIT $limit";
	if ( $params ) {
		$query = $wpdb->prepare( $query, $params ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	return $wpdb->get_results( $query, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL
}

/**
 * A bemenet tisztítása a séma mezőtípusai szerint. Csak a megadott mezők kerülnek bele.
 * $include_readonly: a számított mezőket (összegek, aláírás adatai) csak a kód írhatja, űrlap nem.
 */
function hpv_p_sanitize( string $entity, array $input, bool $include_readonly = false ): array {
	$clean = array();
	foreach ( hpv_p_entity( $entity )['fields'] as $key => $field ) {
		if ( ! array_key_exists( $key, $input ) || ( ! empty( $field['readonly'] ) && ! $include_readonly ) ) {
			continue;
		}
		$value = is_string( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : $input[ $key ];

		switch ( $field['type'] ) {
			case 'textarea':
				$clean[ $key ] = sanitize_textarea_field( (string) $value );
				break;
			case 'html':
				$clean[ $key ] = wp_kses_post( (string) $value );
				break;
			case 'email':
				$clean[ $key ] = sanitize_email( (string) $value );
				break;
			case 'url':
				$clean[ $key ] = esc_url_raw( (string) $value );
				break;
			case 'select':
				$value         = (string) $value;
				$clean[ $key ] = isset( $field['options'][ $value ] ) ? $value : (string) ( $field['default'] ?? array_key_first( $field['options'] ) );
				break;
			case 'date':
				$clean[ $key ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : null;
				break;
			case 'datetime':
				$clean[ $key ] = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $value ) ? (string) $value : null;
				break;
			case 'money':
			case 'decimal':
				$clean[ $key ] = hpv_p_cents_to_decimal( hpv_p_to_cents( $value ) );
				break;
			case 'int':
			case 'ref':
				$clean[ $key ] = absint( $value );
				break;
			case 'bool':
				$clean[ $key ] = empty( $value ) ? 0 : 1;
				break;
			default:
				$clean[ $key ] = sanitize_text_field( (string) $value );
		}
	}

	return $clean;
}

/**
 * Kötelező mezők ellenőrzése. Hibás mezők listája (üres = rendben).
 */
function hpv_p_validate( string $entity, array $data ): array {
	$errors = array();
	foreach ( hpv_p_entity( $entity )['fields'] as $key => $field ) {
		if ( ! empty( $field['required'] ) && ( ! isset( $data[ $key ] ) || '' === (string) $data[ $key ] || ( 'ref' === $field['type'] && ! $data[ $key ] ) ) ) {
			$errors[] = $field['label'];
		}
	}

	return $errors;
}

function hpv_p_defaults( string $entity ): array {
	$data = array();
	foreach ( hpv_p_entity( $entity )['fields'] as $key => $field ) {
		if ( array_key_exists( 'default', $field ) ) {
			$data[ $key ] = $field['default'];
		}
	}

	return $data;
}

/**
 * Új rekord (tisztított adatokkal). Visszaadja az új id-t, vagy 0-t hiba esetén.
 */
function hpv_p_insert( string $entity, array $data ): int {
	global $wpdb;
	$now                = current_time( 'mysql', true );
	$data               = array_merge( hpv_p_defaults( $entity ), $data );
	$data['created_at'] = $now;
	$data['updated_at'] = $now;

	return false === $wpdb->insert( hpv_p_table( $entity ), $data ) ? 0 : (int) $wpdb->insert_id;
}

function hpv_p_update( string $entity, int $id, array $data ): bool {
	global $wpdb;
	$data['updated_at'] = current_time( 'mysql', true );

	return false !== $wpdb->update( hpv_p_table( $entity ), $data, array( 'id' => $id ) );
}

/**
 * Törlés a gyerek rekordokkal együtt (pl. számla → tételek, projekt → feladatok, ügyfél → minden).
 */
function hpv_p_delete( string $entity, int $id ) {
	global $wpdb;
	foreach ( hpv_p_entities() as $child => $def ) {
		if ( ( $def['parent'] ?? '' ) !== $entity ) {
			continue;
		}
		$fk = $entity . '_id';
		foreach ( hpv_p_find( $child, array( $fk => $id ), array( 'limit' => 2000 ) ) as $row ) {
			hpv_p_delete( $child, (int) $row['id'] );
		}
	}
	if ( 'task' === $entity ) {
		foreach ( hpv_p_find( 'task', array( 'parent_id' => $id ), array( 'limit' => 2000 ) ) as $sub ) {
			hpv_p_delete( 'task', (int) $sub['id'] );
		}
		$wpdb->delete( hpv_p_table( 'task_link' ), array( 'depends_on' => $id ) );
	}

	$wpdb->delete( hpv_p_table( $entity ), array( 'id' => $id ) );

	if ( 'client' === $entity ) {
		foreach ( hpv_p_client_users( $id ) as $user ) {
			delete_user_meta( $user->ID, 'hpv_client_id' );
		}
	}
}

/* ─── Hozzáférés ──────────────────────────────────────────── */

function hpv_p_is_staff( int $user_id = 0 ): bool {
	return $user_id ? user_can( $user_id, 'hpv_manage_crm' ) : current_user_can( 'hpv_manage_crm' );
}

/**
 * A felhasználó ügyfél-id-je (portál felhasználóknál). 0, ha nincs.
 */
function hpv_p_user_client_id( int $user_id ): int {
	return (int) get_user_meta( $user_id, 'hpv_client_id', true );
}

/**
 * @return WP_User[]
 */
function hpv_p_client_users( int $client_id ): array {
	return get_users(
		array(
			'meta_key'   => 'hpv_client_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => (string) $client_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
}

/**
 * Az ügyfél a portálon csak a saját, neki láthatóvá tett adatait kapja.
 * Ezek a függvények adják a portál összes lekérdezését.
 */
function hpv_p_portal_invoices( int $client_id ): array {
	return hpv_p_find( 'invoice', array( 'client_id' => $client_id, 'status' => array( 'sent', 'paid', 'void' ) ), array( 'orderby' => 'issue_date' ) );
}

function hpv_p_portal_contracts( int $client_id ): array {
	return hpv_p_find( 'contract', array( 'client_id' => $client_id, 'status' => array( 'sent', 'signed' ) ) );
}

function hpv_p_portal_projects( int $client_id ): array {
	return hpv_p_find( 'project', array( 'client_id' => $client_id, 'visible' => 1, 'is_template' => 0 ) );
}

function hpv_p_portal_tasks( int $project_id ): array {
	return hpv_p_find( 'task', array( 'project_id' => $project_id, 'visible' => 1, 'parent_id' => 0 ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
}

function hpv_p_portal_activity( int $client_id, int $limit = 100 ): array {
	return hpv_p_find( 'activity', array( 'client_id' => $client_id, 'visible' => 1 ), array( 'limit' => $limit ) );
}

function hpv_p_portal_subscriptions( int $client_id ): array {
	return hpv_p_find( 'subscription', array( 'client_id' => $client_id, 'status' => array( 'active', 'paused' ) ), array( 'orderby' => 'name', 'order' => 'ASC' ) );
}

/**
 * Egy portál-rekord lekérése csak akkor, ha az adott ügyfélé és a portálon látható.
 */
function hpv_p_portal_get( string $entity, int $id, int $client_id ): ?array {
	$lists = array(
		'invoice'  => 'hpv_p_portal_invoices',
		'contract' => 'hpv_p_portal_contracts',
		'project'  => 'hpv_p_portal_projects',
	);
	if ( ! isset( $lists[ $entity ] ) ) {
		return null;
	}
	foreach ( $lists[ $entity ]( $client_id ) as $row ) {
		if ( (int) $row['id'] === $id ) {
			return $row;
		}
	}

	return null;
}

/* ─── Számlák, szerződések ────────────────────────────────── */

/**
 * Tételek mentése és az összegek újraszámolása. A tételeket teljesen lecseréli.
 */
function hpv_p_save_invoice_items( int $invoice_id, array $items ) {
	$invoice = hpv_p_get( 'invoice', $invoice_id );
	if ( ! $invoice ) {
		return;
	}

	foreach ( hpv_p_find( 'invoice_item', array( 'invoice_id' => $invoice_id ) ) as $old ) {
		hpv_p_delete( 'invoice_item', (int) $old['id'] );
	}

	$clean = array();
	foreach ( array_values( $items ) as $i => $item ) {
		$row = hpv_p_sanitize( 'invoice_item', (array) $item );
		if ( '' === trim( $row['description'] ?? '' ) ) {
			continue;
		}
		$row['sort'] = $i;
		$clean[]     = $row;
	}

	$totals = hpv_p_invoice_totals( $clean, $invoice['tax_rate'] );
	foreach ( $totals['items'] as $row ) {
		$row['invoice_id'] = $invoice_id;
		$row['amount']     = hpv_p_cents_to_decimal( $row['amount'] );
		hpv_p_insert( 'invoice_item', $row );
	}

	hpv_p_update(
		'invoice',
		$invoice_id,
		array(
			'subtotal' => hpv_p_cents_to_decimal( $totals['subtotal'] ),
			'tax'      => hpv_p_cents_to_decimal( $totals['tax'] ),
			'total'    => hpv_p_cents_to_decimal( $totals['total'] ),
		)
	);
}

/**
 * Számlaszám kiosztása, ha még nincs (egyszer, a következő szabad számmal).
 */
function hpv_p_assign_invoice_number( int $invoice_id ): string {
	$invoice = hpv_p_get( 'invoice', $invoice_id );
	if ( ! $invoice || '' !== $invoice['number'] ) {
		return $invoice['number'] ?? '';
	}

	$settings = hpv_p_settings();
	$next     = max( 1, (int) get_option( 'hpv_portal_next_invoice', $settings['invoice_start'] ) );
	$number   = hpv_p_invoice_number( $settings['invoice_prefix'], $next );
	update_option( 'hpv_portal_next_invoice', $next + 1, false );
	hpv_p_update( 'invoice', $invoice_id, array( 'number' => $number ) );

	return $number;
}

/**
 * Szerződés aláírása. Csak „sent” státuszú, az ügyfélhez tartozó szerződést lehet aláírni.
 */
function hpv_p_sign_contract( int $contract_id, int $client_id, WP_User $user, string $name ) {
	$contract = hpv_p_portal_get( 'contract', $contract_id, $client_id );
	$name     = trim( sanitize_text_field( $name ) );
	if ( ! $contract || 'sent' !== $contract['status'] ) {
		return new WP_Error( 'not_signable', 'This contract is not available for signature.' );
	}
	if ( strlen( $name ) < 3 ) {
		return new WP_Error( 'name', 'Please type your full name to sign.' );
	}

	hpv_p_update(
		'contract',
		$contract_id,
		array(
			'status'       => 'signed',
			'signed_at'    => current_time( 'mysql', true ),
			'signer_name'  => $name,
			'signer_email' => $user->user_email,
			'signer_ip'    => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
			'signer_agent' => substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 250 ),
			'body_hash'    => hpv_p_contract_hash( $contract['body'] ),
		)
	);

	return hpv_p_get( 'contract', $contract_id );
}

function hpv_p_log( int $client_id, string $type, string $body, bool $visible, int $user_id = 0 ): int {
	return hpv_p_insert(
		'activity',
		array(
			'client_id' => $client_id,
			'user_id'   => $user_id,
			'type'      => $type,
			'body'      => $body,
			'visible'   => $visible ? 1 : 0,
		)
	);
}
