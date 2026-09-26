<?php
/**
 * E-mail értesítések és napi emlékeztetők.
 *
 * Az SEO OS az értesítéseket (jóváhagyási kérés, @említés, új / lejárt feladat, upsell, kész crawl) eltárolja; ez a fájl
 * 5 percenként elkéri a még ki nem küldötteket, és wp_mail()-lel elküldi – ha a CRM bővítmény aktív, annak levélsablonjával.
 * Naponta egyszer lefuttatja a napi ellenőrzést (upsell-emlékeztető, lejárt határidők).
 */

defined( 'ABSPATH' ) || exit;

const HPV_SEO_SYSTEM = array( 0, 'system', '', 'WordPress' );

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['hpv_seo_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'SEO OS – 5 percenként',
		);
		return $schedules;
	}
);

add_action( 'init', 'hpv_seo_schedule_cron' );

function hpv_seo_schedule_cron() {
	if ( ! wp_next_scheduled( 'hpv_seo_outbox' ) ) {
		wp_schedule_event( time() + 60, 'hpv_seo_5min', 'hpv_seo_outbox' );
	}
	if ( ! wp_next_scheduled( 'hpv_seo_daily' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 07:10' ), 'daily', 'hpv_seo_daily' );
	}
}

add_action( 'hpv_seo_outbox', 'hpv_seo_send_outbox' );
add_action( 'hpv_seo_daily', 'hpv_seo_run_daily' );

function hpv_seo_mail_html( string $heading, string $body, string $cta_label, string $cta_url ): string {
	$body_html = '<p>' . nl2br( esc_html( $body ) ) . '</p>';
	if ( function_exists( 'hpv_p_email_html' ) ) {
		return hpv_p_email_html( $heading, $body_html, $cta_label, $cta_url );
	}
	return '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;color:#111">'
		. '<p style="font-weight:bold;letter-spacing:.06em">HELLOPROVISION SEO OS</p>'
		. '<h1 style="font-size:20px">' . esc_html( $heading ) . '</h1>' . $body_html
		. ( $cta_url ? '<p><a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:#b3b07a;color:#16160f;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold">' . esc_html( $cta_label ) . '</a></p>' : '' )
		. '</div>';
}

function hpv_seo_send_outbox(): array {
	$items = hpv_seo_api_json( 'GET', 'system/outbox', null, HPV_SEO_SYSTEM );
	if ( is_wp_error( $items ) || ! is_array( $items ) ) {
		return array( 'sent' => 0 );
	}
	$sent   = array();
	$failed = array();
	foreach ( $items as $n ) {
		$user = get_user_by( 'id', (int) $n['wp_user_id'] );
		if ( $user && get_user_meta( $user->ID, 'hpv_seo_email_off', true ) ) {
			$sent[] = (int) $n['id']; // a felhasználó kikapcsolta az e-maileket – a felületen látja
			continue;
		}
		$to      = $user ? $user->user_email : (string) $n['email'];
		$subject = '[SEO OS] ' . $n['title'];
		$body    = trim( ( $n['project'] ? $n['project'] . "\n\n" : '' ) . (string) $n['body'] );
		$html    = hpv_seo_mail_html( (string) $n['title'], $body, 'Megnyitás az SEO OS-ben', hpv_seo_app_url( (string) $n['link'] ) );
		$ok      = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $ok ) {
			$sent[] = (int) $n['id'];
		} else {
			$failed[] = (int) $n['id'];
		}
	}
	if ( $sent || $failed ) {
		hpv_seo_api_json( 'POST', 'system/outbox/ack', array( 'sent' => $sent, 'failed' => $failed ), HPV_SEO_SYSTEM );
	}
	return array( 'sent' => count( $sent ), 'failed' => count( $failed ) );
}

function hpv_seo_run_daily() {
	hpv_seo_api_json( 'POST', 'system/daily', array(), HPV_SEO_SYSTEM );
	hpv_seo_send_outbox();
}

// Saját e-mail kikapcsolása a profil oldalon.
add_action( 'show_user_profile', 'hpv_seo_email_pref_field', 20 );
add_action( 'personal_options_update', 'hpv_seo_email_pref_save' );

function hpv_seo_email_pref_field( WP_User $user ) {
	if ( '' === hpv_seo_user_role( $user ) ) {
		return;
	}
	?>
	<h2>SEO OS értesítések</h2>
	<table class="form-table"><tr><th>E-mail</th><td>
		<label><input type="checkbox" name="hpv_seo_email_off" value="1" <?php checked( (bool) get_user_meta( $user->ID, 'hpv_seo_email_off', true ) ); ?>> Ne kérek e-mailt (az értesítések az SEO OS csengőjében megmaradnak)</label>
	</td></tr></table>
	<?php
}

function hpv_seo_email_pref_save( int $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	check_admin_referer( 'update-user_' . $user_id );
	update_user_meta( $user_id, 'hpv_seo_email_off', empty( $_POST['hpv_seo_email_off'] ) ? '' : '1' );
}
