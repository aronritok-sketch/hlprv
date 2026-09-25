<?php
/**
 * E-mail értesítések. Minden levél wp_mail()-lel megy (WP Mail SMTP-n keresztül hitelesítve).
 */

defined( 'ABSPATH' ) || exit;

function hpv_p_email_html( string $heading, string $body_html, string $cta_label = '', string $cta_url = '' ): string {
	$s   = hpv_p_settings();
	$cta = '';
	if ( $cta_label && $cta_url ) {
		$cta = '<p style="margin:24px 0 8px"><a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:#B8FF34;color:#0d0d0d;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px">' . esc_html( $cta_label ) . '</a></p>';
	}

	return '<div style="background:#f4f4f1;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#111">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden">'
		. '<tr><td style="background:#0d0d0d;padding:22px 28px;color:#fff;font-size:18px;font-weight:bold">' . esc_html( $s['company_name'] ) . ' <span style="color:#B8FF34">Client Portal</span></td></tr>'
		. '<tr><td style="padding:28px">'
		. '<h1 style="font-size:22px;margin:0 0 12px">' . esc_html( $heading ) . '</h1>'
		. '<div style="font-size:15px;line-height:1.55">' . $body_html . '</div>'
		. $cta
		. '</td></tr>'
		. '<tr><td style="padding:16px 28px 24px;font-size:12px;color:#888;border-top:1px solid #eee">'
		. esc_html( $s['company_legal'] ) . ' · ' . nl2br( esc_html( str_replace( "\n", ', ', $s['company_address'] ) ) ) . '<br>'
		. esc_html( $s['company_email'] ) . ' · ' . esc_html( $s['company_phone'] )
		. '</td></tr></table></div>';
}

function hpv_p_send( $to, string $subject, string $html, string $reply_to = '' ): bool {
	$s       = hpv_p_settings();
	$headers = array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . ( $reply_to ?: $s['company_email'] ) );

	$from_name = function () use ( $s ) {
		return $s['company_name'];
	};
	add_filter( 'wp_mail_from_name', $from_name );
	$sent = wp_mail( $to, $subject, $html, $headers );
	remove_filter( 'wp_mail_from_name', $from_name );

	return (bool) $sent;
}

/**
 * Levél az ügyfél összes portál-felhasználójának (ha nincs, a cég e-mail címére).
 */
function hpv_p_notify_client( int $client_id, string $subject, string $heading, string $body_html, string $cta_label = '', string $cta_url = '' ): bool {
	$emails = array_map( fn( $u ) => $u->user_email, hpv_p_client_users( $client_id ) );
	if ( ! $emails ) {
		$client = hpv_p_get( 'client', $client_id );
		if ( $client && is_email( $client['email'] ) ) {
			$emails = array( $client['email'] );
		}
	}
	if ( ! $emails ) {
		return false;
	}

	return hpv_p_send( $emails, $subject, hpv_p_email_html( $heading, $body_html, $cta_label, $cta_url ) );
}

function hpv_p_notify_staff( string $subject, string $body_html, string $link = '', string $reply_to = '' ): bool {
	$html = hpv_p_email_html( $subject, $body_html, $link ? 'Megnyitás a CRM-ben' : '', $link );

	return hpv_p_send( hpv_p_settings()['notify_email'], $subject, $html, $reply_to );
}

/* ─── Események ───────────────────────────────────────────── */

function hpv_p_event_invoice_sent( array $invoice ) {
	$s = hpv_p_settings();
	hpv_p_notify_client(
		(int) $invoice['client_id'],
		sprintf( 'Invoice %s from %s', $invoice['number'], $s['company_name'] ),
		sprintf( 'Invoice %s', $invoice['number'] ),
		sprintf(
			'<p>A new invoice is ready in your client portal.</p><p><strong>Amount due:</strong> %s<br><strong>Due date:</strong> %s</p>',
			esc_html( hpv_p_money( $invoice['total'], $s['currency'] ) ),
			esc_html( $invoice['due_date'] ? mysql2date( 'F j, Y', $invoice['due_date'] ) : 'upon receipt' )
		),
		'View & pay invoice',
		hpv_p_portal_url( array( 'view' => 'invoices', 'id' => $invoice['id'] ) )
	);
}

function hpv_p_event_contract_sent( array $contract ) {
	hpv_p_notify_client(
		(int) $contract['client_id'],
		sprintf( 'Please review and sign: %s', $contract['title'] ),
		$contract['title'],
		'<p>A document is waiting for your review and signature in your client portal.</p>',
		'Review & sign',
		hpv_p_portal_url( array( 'view' => 'contracts', 'id' => $contract['id'] ) )
	);
}

function hpv_p_event_contract_signed( array $contract, array $client ) {
	hpv_p_notify_staff(
		sprintf( 'Aláírva: %s – %s', $contract['title'], $client['name'] ),
		sprintf(
			'<p><strong>%s</strong> aláírta a szerződést.</p><p>Aláíró: %s (%s)<br>Időpont (UTC): %s<br>IP: %s<br>Lenyomat: <code>%s</code></p>',
			esc_html( $client['name'] ),
			esc_html( $contract['signer_name'] ),
			esc_html( $contract['signer_email'] ),
			esc_html( $contract['signed_at'] ),
			esc_html( $contract['signer_ip'] ),
			esc_html( $contract['body_hash'] )
		),
		admin_url( 'admin.php?page=hpv-crm&client=' . (int) $client['id'] )
	);
	hpv_p_notify_client(
		(int) $client['id'],
		sprintf( 'Signed: %s', $contract['title'] ),
		'Thank you — your signature is recorded',
		sprintf( '<p>You signed <strong>%s</strong> on %s (UTC). A copy is always available in your client portal.</p>', esc_html( $contract['title'] ), esc_html( $contract['signed_at'] ) ),
		'View signed document',
		hpv_p_portal_url( array( 'view' => 'contracts', 'id' => $contract['id'] ) )
	);
}

/**
 * Portál-hozzáférés: új felhasználó (vagy meglévő hozzárendelése) + jelszó-beállító link.
 *
 * @return WP_User|WP_Error
 */
function hpv_p_invite_user( int $client_id, string $name, string $email ) {
	$client = hpv_p_get( 'client', $client_id );
	$email  = sanitize_email( $email );
	if ( ! $client || ! is_email( $email ) ) {
		return new WP_Error( 'invalid', 'Érvénytelen ügyfél vagy e-mail cím.' );
	}

	$user = get_user_by( 'email', $email );
	if ( $user ) {
		if ( hpv_p_is_staff( $user->ID ) ) {
			return new WP_Error( 'staff', 'Ez a cím egy munkatársé, nem lehet ügyfél-felhasználó.' );
		}
		$existing = hpv_p_user_client_id( $user->ID );
		if ( $existing && $existing !== $client_id ) {
			return new WP_Error( 'taken', 'Ez a felhasználó már egy másik ügyfélhez tartozik.' );
		}
	} else {
		$login   = sanitize_user( strtolower( strstr( $email, '@', true ) ), true );
		$base    = $login ?: 'client';
		$counter = 1;
		while ( username_exists( $login ) || '' === $login ) {
			$login = $base . ( ++$counter );
		}
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'display_name' => sanitize_text_field( $name ) ?: $email,
				'first_name'   => sanitize_text_field( $name ),
				'user_pass'    => wp_generate_password( 32 ),
				'role'         => 'hpv_client',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user = get_user_by( 'id', $user_id );
	}

	update_user_meta( $user->ID, 'hpv_client_id', $client_id );
	do_action( 'hpv_p_user_invited', $client_id, $user->ID );

	$key = get_password_reset_key( $user );
	if ( is_wp_error( $key ) ) {
		return $key;
	}
	$link = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
	$s    = hpv_p_settings();

	hpv_p_send(
		$email,
		sprintf( 'Your %s client portal', $s['company_name'] ),
		hpv_p_email_html(
			'Welcome to your client portal',
			sprintf(
				'<p>Hi %s,</p><p>We\'ve set up a client portal for <strong>%s</strong>. You can see your projects, invoices, contracts and services, and message our team — all in one place.</p><p>Click below to set your password. Your username is <strong>%s</strong>.</p>',
				esc_html( sanitize_text_field( $name ) ?: 'there' ),
				esc_html( $client['name'] ),
				esc_html( $user->user_login )
			),
			'Set your password',
			$link
		)
	);

	hpv_p_log( $client_id, 'system', sprintf( 'Portal access sent to %s.', $email ), false, get_current_user_id() );

	return $user;
}
