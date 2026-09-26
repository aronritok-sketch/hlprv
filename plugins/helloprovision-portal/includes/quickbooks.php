<?php
/**
 * QuickBooks Online (USA): a kiküldött számla, az ügyfél és a befizetés átkerül a QuickBooks-ba.
 * Kulcsok (Intuit Developer → app → Keys & credentials):
 *   define( 'HPV_QBO_CLIENT_ID', '…' ); define( 'HPV_QBO_CLIENT_SECRET', '…' );
 *   define( 'HPV_QBO_SANDBOX', true );   // tesztelés közben
 * Összekapcsolás: WordPress admin → CRM → Beállítások → „QuickBooks összekapcsolása”.
 * A tokenek titkosítva, az adatbázisban (a WordPress kulcsaiból képzett kulccsal).
 */

defined( 'ABSPATH' ) || exit;

const HPV_QBO_AUTH_URL   = 'https://appcenter.intuit.com/connect/oauth2';
const HPV_QBO_TOKEN_URL  = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
const HPV_QBO_REVOKE_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';
const HPV_QBO_MINOR      = '75';

function hpv_qbo_configured(): bool {
	return defined( 'HPV_QBO_CLIENT_ID' ) && HPV_QBO_CLIENT_ID && defined( 'HPV_QBO_CLIENT_SECRET' ) && HPV_QBO_CLIENT_SECRET;
}

function hpv_qbo_api_base(): string {
	return defined( 'HPV_QBO_SANDBOX' ) && HPV_QBO_SANDBOX ? 'https://sandbox-quickbooks.api.intuit.com' : 'https://quickbooks.api.intuit.com';
}

function hpv_qbo_redirect_uri(): string {
	return hpv_p_scheme() . '://' . hpv_p_crm_host() . '/wp-admin/admin-post.php?action=hpv_qbo_callback';
}

/* ─── Titkosított token tárolás ───────────────────────────── */

function hpv_p_secret_key(): string {
	$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . 'hpv-secrets';

	return function_exists( 'sodium_crypto_generichash' ) ? sodium_crypto_generichash( $material, '', 32 ) : hash( 'sha256', $material, true );
}

function hpv_p_encrypt( string $plain ): string {
	if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
		return 'b64:' . base64_encode( $plain );
	}
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

	return 'sb1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, hpv_p_secret_key() ) );
}

function hpv_p_decrypt( string $stored ): string {
	if ( 0 === strpos( $stored, 'b64:' ) ) {
		return (string) base64_decode( substr( $stored, 4 ), true );
	}
	if ( 0 !== strpos( $stored, 'sb1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
		return '';
	}
	$raw   = (string) base64_decode( substr( $stored, 4 ), true );
	$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), hpv_p_secret_key() );

	return false === $plain ? '' : $plain;
}

function hpv_qbo_tokens(): ?array {
	$stored = (string) get_option( 'hpv_qbo_tokens', '' );
	$data   = '' !== $stored ? json_decode( hpv_p_decrypt( $stored ), true ) : null;

	return is_array( $data ) && ! empty( $data['realm'] ) ? $data : null;
}

function hpv_qbo_save_tokens( array $tokens ): void {
	update_option( 'hpv_qbo_tokens', hpv_p_encrypt( wp_json_encode( $tokens ) ), false );
}

function hpv_qbo_connected(): bool {
	$t = hpv_qbo_tokens();

	return hpv_qbo_configured() && $t && (int) ( $t['refresh_expires_at'] ?? 0 ) > time();
}

/* ─── OAuth ───────────────────────────────────────────────── */

/**
 * @return array|WP_Error
 */
function hpv_qbo_token_request( array $params ) {
	$res = wp_remote_post(
		HPV_QBO_TOKEN_URL,
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( HPV_QBO_CLIENT_ID . ':' . HPV_QBO_CLIENT_SECRET ),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => $params,
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'qbo_http', 'A QuickBooks nem érhető el: ' . $res->get_error_message() );
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || empty( $data['access_token'] ) ) {
		return new WP_Error( 'qbo_auth', 'QuickBooks hitelesítés: ' . ( $data['error_description'] ?? $data['error'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
	}

	return $data;
}

function hpv_qbo_store_token_response( array $data, string $realm ): void {
	hpv_qbo_save_tokens(
		array(
			'realm'              => $realm,
			'access_token'       => $data['access_token'],
			'refresh_token'      => $data['refresh_token'],
			'expires_at'         => time() + (int) ( $data['expires_in'] ?? 3600 ),
			'refresh_expires_at' => time() + (int) ( $data['x_refresh_token_expires_in'] ?? 100 * DAY_IN_SECONDS ),
		)
	);
}

/**
 * Érvényes hozzáférési token (lejárat előtt frissítve).
 *
 * @return string|WP_Error
 */
function hpv_qbo_access_token( bool $force_refresh = false ) {
	$t = hpv_qbo_tokens();
	if ( ! $t || ! hpv_qbo_configured() ) {
		return new WP_Error( 'qbo_off', 'A QuickBooks nincs összekapcsolva.' );
	}
	if ( ! $force_refresh && (int) $t['expires_at'] > time() + 60 ) {
		return (string) $t['access_token'];
	}
	$data = hpv_qbo_token_request(
		array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $t['refresh_token'],
		)
	);
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	hpv_qbo_store_token_response( $data, (string) $t['realm'] );

	return (string) $data['access_token'];
}

add_action( 'admin_post_hpv_qbo_connect', 'hpv_qbo_connect' );
add_action( 'admin_post_hpv_qbo_callback', 'hpv_qbo_callback' );
add_action( 'admin_post_hpv_qbo_disconnect', 'hpv_qbo_disconnect' );

function hpv_qbo_connect() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hpv_qbo_connect' ) || ! hpv_qbo_configured() ) {
		wp_die( 'Nincs jogosultság, vagy hiányzik a HPV_QBO_CLIENT_ID / HPV_QBO_CLIENT_SECRET.' );
	}
	$state = wp_generate_password( 32, false, false );
	set_transient( 'hpv_qbo_state_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );
	wp_redirect( // phpcs:ignore WordPress.Security.SafeRedirect -- Intuit hitelesítés
		add_query_arg(
			array(
				'client_id'     => rawurlencode( HPV_QBO_CLIENT_ID ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'com.intuit.quickbooks.accounting' ),
				'redirect_uri'  => rawurlencode( hpv_qbo_redirect_uri() ),
				'state'         => $state,
			),
			HPV_QBO_AUTH_URL
		)
	);
	exit;
}

function hpv_qbo_callback() {
	$back  = admin_url( 'admin.php?page=hpv-crm-settings' );
	$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
	if ( ! current_user_can( 'manage_options' ) || '' === $state || (int) get_transient( 'hpv_qbo_state_' . $state ) !== get_current_user_id() ) {
		wp_die( 'Érvénytelen vagy lejárt QuickBooks visszairányítás. Próbáld újra a Beállítások oldalról.' );
	}
	delete_transient( 'hpv_qbo_state_' . $state );
	if ( ! empty( $_GET['error'] ) ) {
		hpv_p_redirect( $back, 'error', array( 'error' => 'QuickBooks: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) );
	}
	$data = hpv_qbo_token_request(
		array(
			'grant_type'   => 'authorization_code',
			'code'         => sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ),
			'redirect_uri' => hpv_qbo_redirect_uri(),
		)
	);
	if ( is_wp_error( $data ) ) {
		hpv_p_redirect( $back, 'error', array( 'error' => $data->get_error_message() ) );
	}
	hpv_qbo_store_token_response( $data, preg_replace( '/\D/', '', (string) ( $_GET['realmId'] ?? '' ) ) );
	delete_option( 'hpv_qbo_item' );
	hpv_p_redirect( $back, 'qbo_ok' );
}

function hpv_qbo_disconnect() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hpv_qbo_disconnect' ) ) {
		wp_die( 'Nincs jogosultság.' );
	}
	$t = hpv_qbo_tokens();
	if ( $t && hpv_qbo_configured() ) {
		wp_remote_post(
			HPV_QBO_REVOKE_URL,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( HPV_QBO_CLIENT_ID . ':' . HPV_QBO_CLIENT_SECRET ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode( array( 'token' => $t['refresh_token'] ) ),
			)
		);
	}
	delete_option( 'hpv_qbo_tokens' );
	delete_option( 'hpv_qbo_item' );
	hpv_p_redirect( admin_url( 'admin.php?page=hpv-crm-settings' ), 'saved' );
}

/* ─── API ─────────────────────────────────────────────────── */

/**
 * @return array|WP_Error
 */
function hpv_qbo_request( string $method, string $path, ?array $body = null, array $query = array(), bool $retry = true ) {
	$token = hpv_qbo_access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$realm = hpv_qbo_tokens()['realm'];
	$url   = hpv_qbo_api_base() . '/v3/company/' . rawurlencode( $realm ) . '/' . $path . '?' . http_build_query( array_merge( $query, array( 'minorversion' => HPV_QBO_MINOR ) ) );
	$args  = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'qbo_http', 'A QuickBooks nem érhető el: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 401 === $code && $retry ) {
		$fresh = hpv_qbo_access_token( true );
		return is_wp_error( $fresh ) ? $fresh : hpv_qbo_request( $method, $path, $body, $query, false );
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
		$err = is_array( $data ) ? ( $data['Fault']['Error'][0] ?? array() ) : array();
		return new WP_Error( 'qbo_error', 'QuickBooks: ' . trim( ( $err['Message'] ?? 'HTTP ' . $code ) . ' ' . ( $err['Detail'] ?? '' ) ) );
	}

	return $data;
}

/**
 * @return array|WP_Error A találatok (pl. a Customer tömb).
 */
function hpv_qbo_query( string $entity, string $where ) {
	$res = hpv_qbo_request( 'GET', 'query', null, array( 'query' => "select * from $entity where $where maxresults 5" ) );

	return is_wp_error( $res ) ? $res : (array) ( $res['QueryResponse'][ $entity ] ?? array() );
}

function hpv_qbo_quote( string $value ): string {
	return "'" . str_replace( "'", "\\'", $value ) . "'";
}

/**
 * Az ügyfél QuickBooks azonosítója (név szerint megkeresi, ha nincs, létrehozza).
 *
 * @return string|WP_Error
 */
function hpv_qbo_customer_id( array $client ) {
	if ( '' !== (string) $client['external_customer_id'] ) {
		return (string) $client['external_customer_id'];
	}
	$name  = mb_substr( trim( $client['billing_name'] ?: $client['name'] ), 0, 100 );
	$found = hpv_qbo_query( 'Customer', 'DisplayName = ' . hpv_qbo_quote( $name ) );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	if ( $found ) {
		$id = (string) $found[0]['Id'];
	} else {
		$body  = array(
			'DisplayName' => $name,
			'CompanyName' => $name,
			'BillAddr'    => array_filter(
				array(
					'Line1'                  => $client['street'],
					'City'                   => $client['city'],
					'CountrySubDivisionCode' => $client['state'],
					'PostalCode'             => $client['zip'],
					'Country'                => 'USA',
				)
			),
		);
		$email = hpv_p_client_billing_email( $client );
		if ( is_email( $email ) ) {
			$body['PrimaryEmailAddr'] = array( 'Address' => $email );
		}
		if ( $client['phone'] ) {
			$body['PrimaryPhone'] = array( 'FreeFormNumber' => $client['phone'] );
		}
		if ( $client['contact_name'] ) {
			$parts             = explode( ' ', trim( $client['contact_name'] ), 2 );
			$body['GivenName'] = $parts[0];
			if ( isset( $parts[1] ) ) {
				$body['FamilyName'] = $parts[1];
			}
		}
		$res = hpv_qbo_request( 'POST', 'customer', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = (string) $res['Customer']['Id'];
	}
	hpv_p_update( 'client', (int) $client['id'], array( 'external_customer_id' => $id ) );

	return $id;
}

/**
 * A számlatételekhez használt QuickBooks termék/szolgáltatás (Beállítások: „QuickBooks tétel neve”).
 *
 * @return string|WP_Error
 */
function hpv_qbo_item_id() {
	$name   = (string) ( hpv_p_settings()['qbo_item_name'] ?: 'Services' );
	$cached = get_option( 'hpv_qbo_item' );
	if ( is_array( $cached ) && $cached['name'] === $name ) {
		return (string) $cached['id'];
	}
	$found = hpv_qbo_query( 'Item', 'Name = ' . hpv_qbo_quote( $name ) );
	if ( is_wp_error( $found ) ) {
		return $found;
	}
	if ( ! $found ) {
		return new WP_Error( 'qbo_item', sprintf( 'Nincs „%s” nevű termék/szolgáltatás a QuickBooks-ban. Hozd létre (Sales → Products and services), vagy írd át a nevét a Beállításokban.', $name ) );
	}
	update_option( 'hpv_qbo_item', array( 'name' => $name, 'id' => (string) $found[0]['Id'] ), false );

	return (string) $found[0]['Id'];
}

/**
 * Számla létrehozása a QuickBooks-ban.
 *
 * @return string|WP_Error A QuickBooks számla azonosítója.
 */
function hpv_qbo_push_invoice( array $invoice ) {
	$client   = hpv_p_get( 'client', (int) $invoice['client_id'] );
	$customer = hpv_qbo_customer_id( $client );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$item = hpv_qbo_item_id();
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	$lines = array();
	foreach ( hpv_p_find( 'invoice_item', array( 'invoice_id' => (int) $invoice['id'] ), array( 'orderby' => 'sort', 'order' => 'ASC' ) ) as $row ) {
		$lines[] = array(
			'DetailType'          => 'SalesItemLineDetail',
			'Amount'              => (float) $row['amount'],
			'Description'         => $row['description'],
			'SalesItemLineDetail' => array(
				'ItemRef'   => array( 'value' => $item ),
				'Qty'       => (float) $row['quantity'],
				'UnitPrice' => (float) $row['unit_price'],
			),
		);
	}
	// Adó: a CRM-ben megadott adó külön sorként (a QuickBooks automatikus adója helyett), hogy a végösszeg egyezzen.
	if ( hpv_p_to_cents( $invoice['tax'] ) > 0 ) {
		$lines[] = array(
			'DetailType'          => 'SalesItemLineDetail',
			'Amount'              => (float) $invoice['tax'],
			'Description'         => 'Sales tax (' . rtrim( rtrim( (string) $invoice['tax_rate'], '0' ), '.' ) . '%)',
			'SalesItemLineDetail' => array(
				'ItemRef'   => array( 'value' => $item ),
				'Qty'       => 1,
				'UnitPrice' => (float) $invoice['tax'],
			),
		);
	}
	$body  = array(
		'CustomerRef' => array( 'value' => $customer ),
		'DocNumber'   => mb_substr( (string) $invoice['number'], 0, 21 ),
		'TxnDate'     => $invoice['issue_date'] ?: current_time( 'Y-m-d' ),
		'Line'        => $lines,
		'PrivateNote' => 'HelloProVision CRM #' . (int) $invoice['id'],
	);
	if ( $invoice['due_date'] ) {
		$body['DueDate'] = $invoice['due_date'];
	}
	if ( $invoice['notes'] ) {
		$body['CustomerMemo'] = array( 'value' => mb_substr( (string) $invoice['notes'], 0, 1000 ) );
	}
	$email = hpv_p_client_billing_email( $client );
	if ( is_email( $email ) ) {
		$body['BillEmail'] = array( 'Address' => $email );
	}
	$res = hpv_qbo_request( 'POST', 'invoice', $body );

	return is_wp_error( $res ) ? $res : (string) $res['Invoice']['Id'];
}

/**
 * Befizetés a QuickBooks számlán (Receive payment).
 *
 * @return string|WP_Error A QuickBooks Payment azonosítója.
 */
function hpv_qbo_record_payment( array $invoice, array $payment ) {
	$client   = hpv_p_get( 'client', (int) $invoice['client_id'] );
	$customer = hpv_qbo_customer_id( $client );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$amount = (float) $payment['amount'];
	$res    = hpv_qbo_request(
		'POST',
		'payment',
		array(
			'CustomerRef'   => array( 'value' => $customer ),
			'TotalAmt'      => $amount,
			'TxnDate'       => $payment['paid_on'] ?: current_time( 'Y-m-d' ),
			'PaymentRefNum' => mb_substr( (string) $payment['reference'], -21 ),
			'PrivateNote'   => trim( ucfirst( $payment['provider'] ) . ' ' . $payment['reference'] . ' ' . $payment['note'] ),
			'Line'          => array(
				array(
					'Amount'    => $amount,
					'LinkedTxn' => array(
						array(
							'TxnId'   => (string) $invoice['external_id'],
							'TxnType' => 'Invoice',
						),
					),
				),
			),
		)
	);

	return is_wp_error( $res ) ? $res : (string) $res['Payment']['Id'];
}

/**
 * @return true|WP_Error
 */
function hpv_qbo_void_invoice( array $invoice ) {
	$current = hpv_qbo_request( 'GET', 'invoice/' . rawurlencode( (string) $invoice['external_id'] ) );
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	$res = hpv_qbo_request(
		'POST',
		'invoice',
		array(
			'Id'        => (string) $invoice['external_id'],
			'SyncToken' => (string) $current['Invoice']['SyncToken'],
		),
		array( 'operation' => 'void' )
	);

	return is_wp_error( $res ) ? $res : true;
}
