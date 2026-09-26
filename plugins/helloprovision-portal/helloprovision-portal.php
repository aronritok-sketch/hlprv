<?php
/**
 * Plugin Name: HelloProVision Client Portal & CRM
 * Description: Belső CRM (ügyfelek, szolgáltatások, projektek, számlák, szerződések, tevékenység) és ügyfélportál, ahol az ügyfél a saját adatainak a neki láthatóvá tett részét látja és kezeli. Portál shortcode: [hpv_portal]
 * Version:     0.2.0
 * Author:      HelloProVision
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

const HPV_PORTAL_VERSION    = '0.2.0';
const HPV_PORTAL_DB_VERSION = '2';
const HPV_PORTAL_OPTION     = 'hpv_portal_settings';
const HPV_PORTAL_FILE       = __FILE__;

require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/logic.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/domains.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/chat.php';
require_once __DIR__ . '/includes/pm.php';
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/portal.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

/* ─── Telepítés, szerepkörök ──────────────────────────────── */

register_activation_hook( __FILE__, 'hpv_p_activate' );

function hpv_p_activate() {
	hpv_p_install();

	// Ügyfél: semmilyen WordPress jogosultság, csak a portál.
	add_role( 'hpv_client', 'Ügyfél (portál)', array( 'read' => true ) );

	// Munkatárs: CRM kezelése, WordPress admin nélkül.
	add_role(
		'hpv_staff',
		'Munkatárs (CRM)',
		array(
			'read'           => true,
			'hpv_manage_crm' => true,
			'upload_files'   => true,
		)
	);

	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( 'hpv_manage_crm' );
	}
}

add_action( 'plugins_loaded', 'hpv_p_maybe_upgrade' );

function hpv_p_maybe_upgrade() {
	if ( get_option( 'hpv_portal_db_version' ) !== HPV_PORTAL_DB_VERSION ) {
		hpv_p_install();
	}
}

/* ─── Beállítások ─────────────────────────────────────────── */

function hpv_p_default_settings(): array {
	return array(
		'company_name'    => 'HelloProVision',
		'company_legal'   => 'Arovia Group LLC',
		'company_address' => "12557 New Brittany Blvd, Suite 3\nFort Myers, FL 33907",
		'company_email'   => 'info@helloprovision.com',
		'company_phone'   => '(239) 955-1655',
		'currency'        => 'USD',
		'invoice_prefix'  => 'HPV-',
		'invoice_start'   => 1001,
		'payment_terms'   => 15,
		'notify_email'    => get_option( 'admin_email' ),
		'portal_page_id'  => 0,
		'use_subdomains'  => false,
	);
}

function hpv_p_settings(): array {
	$saved = get_option( HPV_PORTAL_OPTION, array() );

	return array_merge( hpv_p_default_settings(), is_array( $saved ) ? $saved : array() );
}
