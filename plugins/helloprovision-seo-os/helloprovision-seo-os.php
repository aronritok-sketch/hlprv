<?php
/**
 * Plugin Name: HelloProVision SEO OS
 * Description: Belső SEO projektgyártó rendszer (kutatás, kulcsszavak, struktúra, tartalomstratégia, wireframe-ek, dokumentumok). A felület a seo.helloprovision.com címen fut; az adatokat a FastAPI szolgáltatás kezeli.
 * Version:     0.2.0
 * Author:      HelloProVision
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

const HPV_SEO_VERSION = '0.2.0';
const HPV_SEO_OPTION  = 'hpv_seo_os_settings';
const HPV_SEO_FILE    = __FILE__;
const HPV_SEO_META    = 'hpv_seo_role';

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/proxy.php';
require_once __DIR__ . '/includes/crm.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/review.php';
require_once __DIR__ . '/includes/status.php';
require_once __DIR__ . '/includes/portal-bridge.php';
require_once __DIR__ . '/includes/app.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

register_activation_hook( __FILE__, 'hpv_seo_activate' );

function hpv_seo_activate() {
	// Olyan munkatársnak, akinek nincs más WordPress szerepköre (pl. külsős grafikus): csak az SEO OS.
	add_role( 'hpv_seo_user', 'SEO OS munkatárs', array( 'read' => true ) );
}

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'hpv_seo_outbox' );
		wp_clear_scheduled_hook( 'hpv_seo_daily' );
	}
);
