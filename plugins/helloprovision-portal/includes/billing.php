<?php
/**
 * Számlázás és fizetés országonként.
 *   USA:           számla a CRM-ben (saját sorszám) → QuickBooks; fizetés Stripe-pal.
 *   Magyarország:  a jogi számlát a Számlázz.hu állítja ki (sorszám, PDF, NAV) ; fizetés Teyával
 *                  (egyelőre kézi fizetési link, az API bekötése a fejlesztői dokumentációban).
 * Minden befizetés egy `payment` sor; a könyvelőprogramba (Számlázz.hu / QuickBooks) is rögzítjük.
 */

defined( 'ABSPATH' ) || exit;

/* ─── Ország, pénznem, cím ───────────────────────────────── */

function hpv_p_client_country( int $client_id ): string {
	static $cache = array();
	if ( ! isset( $cache[ $client_id ] ) ) {
		$client               = hpv_p_get( 'client', $client_id );
		$cache[ $client_id ] = $client && 'HU' === $client['country'] ? 'HU' : 'US';
	}

	return $cache[ $client_id ];
}

function hpv_p_country_currency( string $country ): string {
	return 'HU' === $country ? 'HUF' : 'USD';
}

function hpv_p_client_currency( int $client_id ): string {
	return hpv_p_country_currency( hpv_p_client_country( $client_id ) );
}

function hpv_p_invoice_currency( array $invoice ): string {
	return ! empty( $invoice['currency'] ) ? (string) $invoice['currency'] : hpv_p_client_currency( (int) $invoice['client_id'] );
}

function hpv_p_is_hu_invoice( array $invoice ): bool {
	return 'HU' === hpv_p_client_country( (int) $invoice['client_id'] );
}

/**
 * Kiállított (Számlázz.hu-ban már létező) magyar számla: jogilag nem módosítható, csak sztornózható.
 */
function hpv_p_invoice_locked( array $invoice ): bool {
	return hpv_p_is_hu_invoice( $invoice ) && '' !== (string) $invoice['external_id'];
}

/**
 * Megjelenítendő számlázási cím sorokban.
 */
function hpv_p_client_address_lines( array $client ): array {
	if ( '' === (string) ( $client['street'] ?? '' ) && '' === (string) ( $client['city'] ?? '' ) ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) ( $client['address'] ?? '' ) ) ) ) );
	}
	if ( 'HU' === ( $client['country'] ?? 'US' ) ) {
		$lines = array( $client['street'], trim( $client['zip'] . ' ' . $client['city'] ), 'Magyarország' );
	} else {
		$lines = array( $client['street'], trim( $client['city'] . ( $client['state'] ? ', ' . $client['state'] : '' ) . ' ' . $client['zip'] ) );
	}
	if ( ! empty( $client['tax_number'] ) ) {
		$lines[] = ( 'HU' === ( $client['country'] ?? '' ) ? 'Adószám: ' : 'Tax ID: ' ) . $client['tax_number'];
	}

	return array_values( array_filter( array_map( 'trim', $lines ) ) );
}

function hpv_p_client_billing_email( array $client ): string {
	return (string) ( $client['billing_email'] ?: $client['email'] );
}

/* ─── Összegek ────────────────────────────────────────────── */

/**
 * Még fizetendő összeg (centben).
 */
function hpv_p_invoice_balance( array $invoice ): int {
	return max( 0, hpv_p_to_cents( $invoice['total'] ) - hpv_p_to_cents( $invoice['paid_amount'] ?? 0 ) );
}

/**
 * Kintlévőség pénznemenként (kiküldött, nem fizetett számlák).
 */
function hpv_p_outstanding_by_currency( array $invoices ): array {
	$out = array();
	foreach ( $invoices as $inv ) {
		if ( 'sent' !== $inv['status'] ) {
			continue;
		}
		$cur         = hpv_p_invoice_currency( $inv );
		$out[ $cur ] = ( $out[ $cur ] ?? 0 ) + hpv_p_invoice_balance( $inv );
	}

	return $out;
}

/**
 * Havi ismétlődő bevétel pénznemenként (az ügyfél országa szerint).
 */
function hpv_p_mrr_by_currency( array $subscriptions ): array {
	$groups = array();
	foreach ( $subscriptions as $sub ) {
		$groups[ hpv_p_client_currency( (int) $sub['client_id'] ) ][] = $sub;
	}

	return array_map( 'hpv_p_mrr', $groups );
}

/**
 * Magyar számla ÁFA-kulcsa (Számlázz.hu kód) és a hozzá tartozó százalék.
 */
function hpv_p_hu_vat_key( array $invoice ): string {
	return (string) ( $invoice['vat_key'] ?: hpv_p_settings()['hu_vat_key'] );
}

function hpv_p_vat_rate( string $key ): string {
	return is_numeric( $key ) ? $key : '0';
}

/* ─── Kiküldés, befizetés, érvénytelenítés ───────────────── */

/**
 * Kiküldés. USA: saját sorszám, e-mail az ügyfélnek, QuickBooks. Magyarország: kiállítás a Számlázz.hu-ban
 * (sorszám, PDF, NAV; az e-mailt a Számlázz.hu küldi a fizetési linkkel).
 *
 * @return array|WP_Error A számla.
 */
function hpv_bill_send( int $invoice_id, int $user_id ) {
	$invoice = hpv_p_get( 'invoice', $invoice_id );
	if ( ! $invoice || ! in_array( $invoice['status'], array( 'draft', 'sent' ), true ) ) {
		return new WP_Error( 'status', 'Csak piszkozat vagy kiküldött számla küldhető.' );
	}
	if ( ! hpv_p_find( 'invoice_item', array( 'invoice_id' => $invoice_id ), array( 'limit' => 1 ) ) ) {
		return new WP_Error( 'items', 'A számlán nincs tétel.' );
	}
	$currency = hpv_p_invoice_currency( $invoice );

	if ( hpv_p_is_hu_invoice( $invoice ) ) {
		if ( '' === (string) $invoice['external_id'] ) {
			if ( ! hpv_szamlazz_enabled() ) {
				return new WP_Error( 'szamlazz_off', 'Magyar számlához a Számlázz.hu bekötése kell (HPV_SZAMLAZZ_AGENT_KEY).' );
			}
			$issued = hpv_szamlazz_issue( $invoice );
			if ( is_wp_error( $issued ) ) {
				hpv_p_update( 'invoice', $invoice_id, array( 'sync_status' => 'error', 'sync_error' => $issued->get_error_message() ) );
				return $issued;
			}
			$data = array(
				'number'      => $issued['number'],
				'external_id' => $issued['number'],
				'currency'    => $currency,
				'status'      => 'sent',
				'sent_at'     => current_time( 'mysql', true ),
				'sync_status' => 'synced',
				'sync_error'  => '',
			);
			if ( ! empty( $issued['pdf'] ) ) {
				$data['pdf_file'] = hpv_p_store_private( $issued['pdf'], 'pdf' );
			}
			// A Számlázz.hu soronként kerekít: az ő végösszegei a hitelesek.
			if ( isset( $issued['net'], $issued['gross'] ) ) {
				$data['subtotal'] = hpv_p_cents_to_decimal( $issued['net'] );
				$data['total']    = hpv_p_cents_to_decimal( $issued['gross'] );
				$data['tax']      = hpv_p_cents_to_decimal( $issued['gross'] - $issued['net'] );
			}
			hpv_p_update( 'invoice', $invoice_id, $data );
			$invoice = hpv_p_get( 'invoice', $invoice_id );
			hpv_p_log_client( (int) $invoice['client_id'], 'Invoice %s issued: %s.', array( $invoice['number'], hpv_p_money( $invoice['total'], $currency ) ), $user_id );
			return $invoice;
		}
		// Már kiállított számla: emlékeztető a portál linkjével.
		hpv_p_event_invoice_sent( $invoice );
		return $invoice;
	}

	hpv_p_assign_invoice_number( $invoice_id );
	hpv_p_update(
		'invoice',
		$invoice_id,
		array(
			'status'   => 'sent',
			'sent_at'  => current_time( 'mysql', true ),
			'currency' => $currency,
		)
	);
	$invoice = hpv_p_get( 'invoice', $invoice_id );
	hpv_p_event_invoice_sent( $invoice );
	hpv_p_log_client( (int) $invoice['client_id'], 'Invoice %s issued: %s.', array( $invoice['number'], hpv_p_money( $invoice['total'], $currency ) ), $user_id );

	if ( hpv_qbo_connected() && '' === (string) $invoice['external_id'] ) {
		hpv_bill_sync_invoice( $invoice_id );
	}

	return hpv_p_get( 'invoice', $invoice_id );
}

/**
 * Számla átküldése a könyvelőprogramba (USA: QuickBooks). Hibánál a számla „Szinkron: Hiba” lesz, újrapróbálható.
 */
function hpv_bill_sync_invoice( int $invoice_id ) {
	$invoice = hpv_p_get( 'invoice', $invoice_id );
	if ( ! $invoice || 'draft' === $invoice['status'] ) {
		return $invoice;
	}
	if ( ! hpv_p_is_hu_invoice( $invoice ) && '' === (string) $invoice['external_id'] ) {
		$res = hpv_qbo_push_invoice( $invoice );
		if ( is_wp_error( $res ) ) {
			hpv_p_update( 'invoice', $invoice_id, array( 'sync_status' => 'error', 'sync_error' => $res->get_error_message() ) );
			return $res;
		}
		hpv_p_update( 'invoice', $invoice_id, array( 'external_id' => (string) $res, 'sync_status' => 'synced', 'sync_error' => '' ) );
	}
	// A korábban be nem jegyzett befizetések pótlása.
	foreach ( hpv_p_find( 'payment', array( 'invoice_id' => $invoice_id ) ) as $payment ) {
		if ( '' === (string) $payment['external_ref'] ) {
			$r = hpv_bill_register_payment( hpv_p_get( 'invoice', $invoice_id ), $payment );
			if ( is_wp_error( $r ) ) {
				hpv_p_update( 'invoice', $invoice_id, array( 'sync_status' => 'error', 'sync_error' => 'Befizetés könyvelése: ' . $r->get_error_message() ) );
				return $r;
			}
		}
	}
	if ( 'error' === hpv_p_get( 'invoice', $invoice_id )['sync_status'] ) {
		hpv_p_update( 'invoice', $invoice_id, array( 'sync_status' => 'synced', 'sync_error' => '' ) );
	}

	return hpv_p_get( 'invoice', $invoice_id );
}

/**
 * Befizetés rögzítése. Ugyanaz a tranzakció (reference) csak egyszer kerül be.
 *
 * @param array $p provider, amount (cent), reference?, paid_on?, note?, user_id?
 * @return array|WP_Error A számla.
 */
function hpv_bill_mark_paid( int $invoice_id, array $p ) {
	$invoice = hpv_p_get( 'invoice', $invoice_id );
	if ( ! $invoice || 'sent' !== $invoice['status'] && 'paid' !== $invoice['status'] ) {
		return new WP_Error( 'status', 'Csak kiküldött számlára lehet befizetést rögzíteni.' );
	}
	$reference = (string) ( $p['reference'] ?? '' );
	if ( '' !== $reference && hpv_p_find( 'payment', array( 'reference' => $reference ), array( 'limit' => 1 ) ) ) {
		return $invoice; // már rögzítve (pl. a webhook és a visszairányítás is jelezte)
	}
	// Ha a webhook és a visszatérő oldal egyszerre érkezik: az option_name egyedi, így csak az egyik jut tovább.
	$lock = '' !== $reference ? 'hpv_pay_lock_' . md5( $reference ) : '';
	if ( $lock && ! add_option( $lock, time(), '', false ) ) {
		return $invoice;
	}
	try {
		return hpv_bill_record_payment( $invoice, $p, $reference );
	} finally {
		if ( $lock ) {
			delete_option( $lock );
		}
	}
}

/**
 * @return array|WP_Error
 */
function hpv_bill_record_payment( array $invoice, array $p, string $reference ) {
	$invoice_id = (int) $invoice['id'];
	if ( '' !== $reference && hpv_p_find( 'payment', array( 'reference' => $reference ), array( 'limit' => 1 ) ) ) {
		return $invoice;
	}
	$amount = (int) ( $p['amount'] ?? hpv_p_invoice_balance( $invoice ) );
	if ( $amount <= 0 ) {
		return new WP_Error( 'amount', 'Az összeg nem lehet nulla.' );
	}
	$currency = hpv_p_invoice_currency( $invoice );

	$payment_id = hpv_p_insert(
		'payment',
		array(
			'invoice_id' => $invoice_id,
			'provider'   => in_array( $p['provider'] ?? '', array( 'stripe', 'teya', 'manual' ), true ) ? $p['provider'] : 'manual',
			'amount'     => hpv_p_cents_to_decimal( $amount ),
			'currency'   => $currency,
			'reference'  => $reference,
			'paid_on'    => $p['paid_on'] ?? current_time( 'Y-m-d' ),
			'user_id'    => (int) ( $p['user_id'] ?? 0 ),
			'note'       => sanitize_text_field( (string) ( $p['note'] ?? '' ) ),
		)
	);
	$paid = hpv_p_to_cents( $invoice['paid_amount'] ) + $amount;
	$data = array( 'paid_amount' => hpv_p_cents_to_decimal( $paid ) );
	if ( $paid >= hpv_p_to_cents( $invoice['total'] ) ) {
		$data['status']  = 'paid';
		$data['paid_at'] = current_time( 'mysql', true );
	}
	hpv_p_update( 'invoice', $invoice_id, $data );
	$invoice = hpv_p_get( 'invoice', $invoice_id );

	hpv_p_log_client( (int) $invoice['client_id'], 'Payment of %s received for invoice %s. Thank you!', array( hpv_p_money( $amount, $currency ), $invoice['number'] ), (int) ( $p['user_id'] ?? 0 ) );
	if ( in_array( $p['provider'] ?? '', array( 'stripe', 'teya' ), true ) ) {
		// Visszaigazolás az ügyfélnek (a portál ígéri: „A receipt is on its way”).
		hpv_with_client_lang(
			(int) $invoice['client_id'],
			fn() => hpv_p_notify_client(
				(int) $invoice['client_id'],
				hpv_t( 'Payment received: invoice %s', $invoice['number'] ),
				hpv_t( 'Thank you for your payment' ),
				'<p>' . esc_html( hpv_t( 'We received %s for invoice %s.', hpv_p_money( $amount, $currency ), $invoice['number'] ) ) . '</p><p>'
					. esc_html( 'paid' === $invoice['status'] ? hpv_t( 'The invoice is now paid in full.' ) : hpv_t( 'Remaining balance: %s', hpv_p_money( hpv_p_invoice_balance( $invoice ), $currency ) ) ) . '</p>',
				hpv_t( 'View invoice' ),
				hpv_p_portal_url( array( 'view' => 'invoices', 'id' => $invoice_id ) )
			)
		);
		hpv_p_notify_staff(
			sprintf( 'Online befizetés: %s — %s', $invoice['number'], hpv_p_money( $amount, $currency ) ),
			sprintf( '<p><strong>%s</strong> fizetett: %s (%s, %s számla).</p>', esc_html( hpv_p_client_name_safe( (int) $invoice['client_id'] ) ), esc_html( hpv_p_money( $amount, $currency ) ), esc_html( ucfirst( $p['provider'] ) ), esc_html( $invoice['number'] ) ),
			hpv_p_crm_app_url( '/invoices/' . $invoice_id )
		);
	}

	$register = hpv_bill_register_payment( $invoice, hpv_p_get( 'payment', $payment_id ) );
	if ( is_wp_error( $register ) ) {
		hpv_p_update( 'invoice', $invoice_id, array( 'sync_status' => 'error', 'sync_error' => 'Befizetés könyvelése: ' . $register->get_error_message() ) );
	}

	return hpv_p_get( 'invoice', $invoice_id );
}

/**
 * A befizetés rögzítése a könyvelőprogramban (Számlázz.hu kifizetés / QuickBooks Payment).
 *
 * @return true|WP_Error
 */
function hpv_bill_register_payment( array $invoice, array $payment ) {
	if ( '' === (string) $invoice['external_id'] || '' !== (string) $payment['external_ref'] ) {
		return true;
	}
	if ( hpv_p_is_hu_invoice( $invoice ) ) {
		if ( ! hpv_szamlazz_enabled() ) {
			return true;
		}
		$res = hpv_szamlazz_register_payment( $invoice, $payment );
	} else {
		if ( ! hpv_qbo_connected() ) {
			return true;
		}
		$res = hpv_qbo_record_payment( $invoice, $payment );
	}
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	hpv_p_update( 'payment', (int) $payment['id'], array( 'external_ref' => (string) $res ) );
	$fresh = hpv_p_get( 'invoice', (int) $invoice['id'] );
	if ( 'error' === $fresh['sync_status'] && 0 === strpos( (string) $fresh['sync_error'], 'Befizetés' ) ) {
		hpv_p_update( 'invoice', (int) $invoice['id'], array( 'sync_status' => 'synced', 'sync_error' => '' ) );
	}

	return true;
}

/**
 * Érvénytelenítés. Kiállított magyar számla: sztornó számla a Számlázz.hu-ban; QuickBooks: void.
 *
 * @return array|WP_Error
 */
function hpv_bill_void( int $invoice_id, int $user_id ) {
	$invoice = hpv_p_get( 'invoice', $invoice_id );
	if ( ! $invoice || 'void' === $invoice['status'] ) {
		return $invoice ?: new WP_Error( 'not_found', 'A számla nem található.' );
	}
	if ( '' !== (string) $invoice['external_id'] ) {
		$res = hpv_p_is_hu_invoice( $invoice ) ? hpv_szamlazz_storno( $invoice ) : ( hpv_qbo_connected() ? hpv_qbo_void_invoice( $invoice ) : true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( is_array( $res ) && ! empty( $res['number'] ) ) {
			hpv_p_log( (int) $invoice['client_id'], 'system', sprintf( 'Sztornó számla kiállítva: %s (eredeti: %s).', $res['number'], $invoice['number'] ), false, $user_id );
		}
	}
	hpv_p_update( 'invoice', $invoice_id, array( 'status' => 'void' ) );

	return hpv_p_get( 'invoice', $invoice_id );
}

/* ─── Védett fájlok (számla PDF) ──────────────────────────── */

/**
 * wp-content/uploads/hpv-private: véletlen fájlnevek, Apache alatt tiltott közvetlen elérés
 * (nginx-en a fejlesztőnek kell tiltania, lásd a dokumentációt). Letöltés csak jogosultság-ellenőrzéssel.
 */
function hpv_p_private_dir(): string {
	$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'hpv-private';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	return $dir;
}

function hpv_p_store_private( string $bytes, string $ext ): string {
	$name = bin2hex( random_bytes( 16 ) ) . '.' . preg_replace( '/[^a-z0-9]/', '', $ext );
	file_put_contents( hpv_p_private_dir() . '/' . $name, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return $name;
}

function hpv_p_invoice_pdf_url( array $invoice ): string {
	return add_query_arg(
		array(
			'hpv_invoice_pdf' => (int) $invoice['id'],
			'_wpnonce'        => wp_create_nonce( 'hpv_pdf_' . (int) $invoice['id'] ),
		),
		home_url( '/' )
	);
}

add_action( 'init', 'hpv_p_serve_invoice_pdf', 5 );

function hpv_p_serve_invoice_pdf() {
	if ( empty( $_GET['hpv_invoice_pdf'] ) ) {
		return;
	}
	$id = absint( $_GET['hpv_invoice_pdf'] );
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'hpv_pdf_' . $id ) ) {
		wp_die( 'This link has expired. Please open the invoice again.', 403 );
	}
	$invoice = hpv_p_get( 'invoice', $id );
	$allowed = $invoice && ( hpv_p_can( 'invoices' ) || ( 'draft' !== $invoice['status'] && hpv_p_user_client_id( get_current_user_id() ) === (int) $invoice['client_id'] ) );
	$file    = $invoice ? hpv_p_private_dir() . '/' . basename( (string) $invoice['pdf_file'] ) : '';
	if ( ! $allowed || '' === (string) $invoice['pdf_file'] || ! is_file( $file ) ) {
		wp_die( 'Invoice not found.', 404 );
	}
	nocache_headers();
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $invoice['number'] ?: 'invoice' ) . '.pdf"' );
	header( 'Content-Length: ' . filesize( $file ) );
	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
