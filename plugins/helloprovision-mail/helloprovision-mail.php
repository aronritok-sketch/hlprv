<?php
/**
 * Plugin Name: HelloProVision Levelezés
 * Description: Levelezés a CRM-ben: mindenkinek postafiók, az admin által kezelt egységes aláírás, levélből feladat, a levelek az ügyfélhez kötve. A leveleket az SEO OS szervere szinkronizálja (IMAP/SMTP).
 * Version:     0.2.0
 * Author:      HelloProVision
 * Requires PHP: 8.0
 * Text Domain: helloprovision-mail
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/github-updater.php';
HPV_GitHub_Updater::register( __FILE__, 'helloprovision-mail' );

const HPV_MAIL_VERSION = '0.2.0';
const HPV_MAIL_FILE    = __FILE__;

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/contacts.php';

register_activation_hook( __FILE__, 'hpv_mail_activate' );
register_deactivation_hook( __FILE__, fn() => wp_clear_scheduled_hook( 'hpv_mail_contacts' ) );

function hpv_mail_activate(): void {
	if ( ! wp_next_scheduled( 'hpv_mail_contacts' ) ) {
		wp_schedule_event( time() + 60, 'hourly', 'hpv_mail_contacts' );
	}
}
