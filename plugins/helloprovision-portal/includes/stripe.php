<?php
/**
 * Stripe (USA): „Pay now” → Stripe Checkout (kártya, és ha a Stripe fiókban be van kapcsolva, ACH / Apple Pay / Google Pay).
 * A fizetést a webhook (aláírás-ellenőrzéssel) és a visszatérő oldal is jelzi; a befizetés csak egyszer kerül be.
 * Kulcsok: define( 'HPV_STRIPE_SECRET_KEY', 'sk_live_…' ); define( 'HPV_STRIPE_WEBHOOK_SECRET', 'whsec_…' );
 */

defined( 'ABSPATH' ) || exit;

const HPV_STRIPE_API = 'https://api.stripe.com/v1';

function hpv_stripe_key(): string {
	return defined( 'HPV_STRIPE_SECRET_KEY' ) ? (string) HPV_STRIPE_SECRET_KEY : '';
}

function hpv_stripe_webhook_secret(): string {
	return defined( 'HPV_STRIPE_WEBHOOK_SECRET' ) ? (string) HPV_STRIPE_WEBHOOK_SECRET : '';
}

function hpv_stripe_enabled(): bool {
	return '' !== hpv_stripe_key();
}

function hpv_stripe_webhook_url(): string {
	return hpv_p_scheme() . '://' . hpv_p_crm_host() . '/wp-json/hpv/v1/pay/stripe';
}

/**
 * @return array|WP_Error
 */
function hpv_stripe_request( string $method, string $path, array $params = array() ) {
	if ( ! hpv_stripe_enabled() ) {
		return new WP_Error( 'stripe_off', 'A Stripe nincs beállítva.' );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => array( 'Authorization' => 'Bearer ' . hpv_stripe_key() ),
	);
	$url  = HPV_STRIPE_API . $path;
	if ( 'GET' === $method && $params ) {
		$url .= '?' . http_build_query( $params );
	} elseif ( $params ) {
		$args['body'] = http_build_query( $params );
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'stripe_http', 'A Stripe nem érhető el: ' . $res->get_error_message() );
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
		return new WP_Error( 'stripe_error', 'Stripe: ' . ( is_array( $data ) ? (string) ( $data['error']['message'] ?? 'HTTP ' . $code ) : 'HTTP ' . $code ) );
	}

	return $data;
}

/**
 * Checkout munkamenet a számla fennálló összegére.
 *
 * @return string|WP_Error A Stripe fizetőoldal címe.
 */
function hpv_stripe_checkout_url( array $invoice ) {
	$balance = hpv_p_invoice_balance( $invoice );
	if ( 'sent' !== $invoice['status'] || $balance <= 0 ) {
		return new WP_Error( 'nothing_due', 'This invoice has nothing left to pay.' );
	}
	$client   = hpv_p_get( 'client', (int) $invoice['client_id'] );
	$back     = hpv_p_portal_url( array( 'view' => 'invoices', 'id' => (int) $invoice['id'] ) );
	$currency = strtolower( hpv_p_invoice_currency( $invoice ) );
	$params   = array(
		'mode'                 => 'payment',
		'client_reference_id'  => (string) $invoice['id'],
		'success_url'          => add_query_arg( array( 'paid' => 1 ), $back ) . '&session_id={CHECKOUT_SESSION_ID}',
		'cancel_url'           => $back,
		'line_items'           => array(
			array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => $currency,
					'unit_amount'  => $balance,
					'product_data' => array( 'name' => 'Invoice ' . $invoice['number'] . ' — ' . hpv_p_settings()['company_name'] ),
				),
			),
		),
		'metadata'             => array(
			'invoice_id' => (string) $invoice['id'],
			'invoice'    => (string) $invoice['number'],
		),
		'payment_intent_data'  => array(
			'description' => 'Invoice ' . $invoice['number'],
			'metadata'    => array( 'invoice_id' => (string) $invoice['id'] ),
		),
	);
	$email = $client ? hpv_p_client_billing_email( $client ) : '';
	if ( is_email( $email ) ) {
		$params['customer_email'] = $email;
	}
	$session = hpv_stripe_request( 'POST', '/checkout/sessions', $params );
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	return empty( $session['url'] ) ? new WP_Error( 'stripe_url', 'Stripe did not return a payment page.' ) : (string) $session['url'];
}

/**
 * Stripe-Signature fejléc ellenőrzése (t=időbélyeg, v1=HMAC-SHA256("t.payload")).
 */
function hpv_stripe_verify_signature( string $payload, string $header, string $secret, int $tolerance = 300 ): bool {
	if ( '' === $secret || '' === $header ) {
		return false;
	}
	$time = 0;
	$sigs = array();
	foreach ( explode( ',', $header ) as $part ) {
		$kv = explode( '=', trim( $part ), 2 );
		if ( 2 !== count( $kv ) ) {
			continue;
		}
		if ( 't' === $kv[0] ) {
			$time = (int) $kv[1];
		} elseif ( 'v1' === $kv[0] ) {
			$sigs[] = $kv[1];
		}
	}
	if ( ! $time || ! $sigs || abs( time() - $time ) > $tolerance ) {
		return false;
	}
	$expected = hash_hmac( 'sha256', $time . '.' . $payload, $secret );
	foreach ( $sigs as $sig ) {
		if ( hash_equals( $expected, $sig ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Kifizetett Checkout munkamenet → befizetés a számlán (egyszer).
 *
 * @return array|WP_Error|null A számla, vagy null ha nem a mi fizetésünk / még nincs kifizetve.
 */
function hpv_stripe_apply_session( array $session ) {
	$invoice_id = absint( $session['metadata']['invoice_id'] ?? $session['client_reference_id'] ?? 0 );
	$invoice    = $invoice_id ? hpv_p_get( 'invoice', $invoice_id ) : null;
	if ( ! $invoice || 'paid' !== ( $session['payment_status'] ?? '' ) ) {
		return null;
	}
	if ( strtolower( hpv_p_invoice_currency( $invoice ) ) !== strtolower( (string) ( $session['currency'] ?? '' ) ) ) {
		return new WP_Error( 'currency', 'A Stripe fizetés pénzneme eltér a számláétól.' );
	}

	return hpv_bill_mark_paid(
		$invoice_id,
		array(
			'provider'  => 'stripe',
			'amount'    => (int) ( $session['amount_total'] ?? 0 ),
			'reference' => (string) ( $session['id'] ?? '' ),
			'note'      => (string) ( $session['payment_intent'] ?? '' ),
		)
	);
}

add_action( 'rest_api_init', 'hpv_stripe_routes' );

function hpv_stripe_routes() {
	register_rest_route(
		'hpv/v1',
		'/pay/stripe',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'hpv_stripe_webhook',
		)
	);
}

function hpv_stripe_webhook( WP_REST_Request $request ) {
	$payload = (string) $request->get_body();
	if ( ! hpv_stripe_verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ), hpv_stripe_webhook_secret() ) ) {
		return new WP_Error( 'signature', 'Invalid signature.', array( 'status' => 400 ) );
	}
	$event = json_decode( $payload, true );
	$type  = (string) ( $event['type'] ?? '' );
	if ( in_array( $type, array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) ) {
		$result = hpv_stripe_apply_session( (array) ( $event['data']['object'] ?? array() ) );
		if ( is_wp_error( $result ) ) {
			hpv_p_notify_staff( 'Stripe fizetés: kézi ellenőrzés kell', '<p>' . esc_html( $result->get_error_message() ) . '</p><p>Esemény: ' . esc_html( (string) ( $event['id'] ?? '' ) ) . '</p>' );
		}
	}

	return rest_ensure_response( array( 'received' => true ) );
}

/**
 * A fizetőoldalról visszatérve: a munkamenetet a Stripe-tól kérdezzük le (a webhook előtt is „fizetve” lehet).
 */
function hpv_stripe_confirm_return( array $invoice, string $session_id ): void {
	if ( ! hpv_stripe_enabled() || ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $session_id ) || 'sent' !== $invoice['status'] ) {
		return;
	}
	$session = hpv_stripe_request( 'GET', '/checkout/sessions/' . $session_id );
	if ( ! is_wp_error( $session ) && (int) ( $session['metadata']['invoice_id'] ?? 0 ) === (int) $invoice['id'] ) {
		hpv_stripe_apply_session( $session );
	}
}

/* ─── „Pay now” a portálon ────────────────────────────────── */

/**
 * A fizetés gomb célja: USA → Stripe (a mi végpontunkon át), Magyarország → Teya link (kézi, amíg nincs API).
 */
function hpv_p_pay_url( array $invoice ): string {
	if ( 'sent' !== $invoice['status'] || hpv_p_invoice_balance( $invoice ) <= 0 ) {
		return '';
	}
	if ( ! hpv_p_is_hu_invoice( $invoice ) && hpv_stripe_enabled() ) {
		return add_query_arg(
			array(
				'hpv_pay'  => (int) $invoice['id'],
				'_wpnonce' => wp_create_nonce( 'hpv_pay_' . (int) $invoice['id'] ),
			),
			hpv_p_portal_url()
		);
	}

	return (string) $invoice['payment_url'];
}

add_action( 'init', 'hpv_p_handle_pay', 6 );

function hpv_p_handle_pay() {
	if ( empty( $_GET['hpv_pay'] ) ) {
		return;
	}
	$id = absint( $_GET['hpv_pay'] );
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	$invoice = hpv_p_get( 'invoice', $id );
	$back    = hpv_p_portal_url( array( 'view' => 'invoices', 'id' => $id ) );
	if ( ! $invoice || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'hpv_pay_' . $id ) || hpv_p_is_staff() || hpv_p_user_client_id( get_current_user_id() ) !== (int) $invoice['client_id'] ) {
		wp_safe_redirect( $back );
		exit;
	}
	$url = hpv_stripe_checkout_url( $invoice );
	if ( is_wp_error( $url ) ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( hpv_with_client_lang( (int) $invoice['client_id'], fn() => hpv_t( 'We could not open the payment page. Please try again in a minute or message us.' ) ) ), $back ) );
		exit;
	}
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- Stripe fizetőoldal
	exit;
}
