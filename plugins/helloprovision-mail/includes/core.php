<?php
/**
 * Közös: ki levelezhet, és milyen szerepkörrel megy a kérés az SEO OS szerverre.
 * Függ: helloprovision-seo-os (aláírt kapcsolat a szerverrel) és helloprovision-portal (CRM felület).
 */

defined( 'ABSPATH' ) || exit;

function hpv_mail_ready(): bool {
	return function_exists( 'hpv_seo_api' ) && function_exists( 'hpv_p_is_staff' );
}

/**
 * A kérés szerepköre: az SEO OS szerepkör, ha van; különben a CRM munkatárs „staff” (csak levelezés).
 */
function hpv_mail_role( ?WP_User $user = null ): string {
	$user = $user ?? wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return '';
	}
	$role = function_exists( 'hpv_seo_user_role' ) ? hpv_seo_user_role( $user ) : '';
	if ( '' !== $role ) {
		return $role;
	}

	return function_exists( 'hpv_p_is_staff' ) && hpv_p_is_staff( (int) $user->ID ) ? 'staff' : '';
}

function hpv_mail_as( ?WP_User $user = null ): array {
	$user = $user ?? wp_get_current_user();

	return array( (int) $user->ID, hpv_mail_role( $user ), (string) $user->user_email, (string) $user->display_name );
}

function hpv_mail_can(): bool {
	return hpv_mail_ready() && '' !== hpv_mail_role();
}

// A CRM felületébe: menüpont és oldal (a portál bővítés-pontján át).
add_filter(
	'hpv_crm_app_extensions',
	function ( array $ext ) {
		if ( hpv_mail_can() ) {
			$base  = plugins_url( 'assets/', HPV_MAIL_FILE );
			$ext[] = array(
				'script' => $base . 'mail.js?ver=' . HPV_MAIL_VERSION,
				'style'  => $base . 'mail.css?ver=' . HPV_MAIL_VERSION,
			);
		}
		return $ext;
	}
);

add_action(
	'admin_notices',
	function () {
		if ( current_user_can( 'activate_plugins' ) && ! hpv_mail_ready() ) {
			echo '<div class="notice notice-warning"><p><strong>HelloProVision Levelezés:</strong> a működéshez a HelloProVision SEO OS és a CRM / ügyfélportál bővítmény is kell (a levelezést az SEO OS szervere végzi).</p></div>';
		}
	}
);
