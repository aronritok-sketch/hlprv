<?php
/**
 * A CRM webalkalmazás (crm.helloprovision.com gyökere): saját keret, WordPress admin nélkül.
 * A pénzügyi szerkesztők (számla, szerződés, szolgáltatás) egyelőre a klasszikus CRM-ben (wp-admin) maradnak.
 */

defined( 'ABSPATH' ) || exit;

function hpv_p_render_crm_app() {
	$base    = plugins_url( 'assets/', HPV_PORTAL_FILE );
	$version = HPV_PORTAL_VERSION;
	$config  = array(
		'rest'      => esc_url_raw( rest_url( 'hpv/v1' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'adminUrl'  => admin_url( 'admin.php?page=hpv-crm' ),
		'portalUrl' => hpv_p_portal_url(),
		'chat'      => hpv_chat_app_config( 'hu' ),
	);
	nocache_headers();
	?>
<!doctype html>
<html lang="hu">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>HelloProVision CRM</title>
	<link rel="stylesheet" href="<?php echo esc_url( $base . 'chat.css?ver=' . $version ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( $base . 'app/app.css?ver=' . $version ); ?>">
	<script>window.HPV_APP = <?php echo wp_json_encode( $config ); ?>;</script>
</head>
<body class="hpv-app-body">
	<div id="app"></div>
	<noscript>A CRM-hez JavaScript szükséges.</noscript>
	<script src="<?php echo esc_url( $base . 'chat.js?ver=' . $version ); ?>"></script>
	<script type="module" src="<?php echo esc_url( $base . 'app/app.js?ver=' . $version ); ?>"></script>
</body>
</html>
	<?php
}

add_action( 'rest_api_init', 'hpv_p_app_routes' );

function hpv_p_app_routes() {
	register_rest_route(
		'hpv/v1',
		'/app/client-channel',
		array(
			'methods'             => 'GET',
			'permission_callback' => fn() => hpv_p_is_staff(),
			'callback'            => function ( WP_REST_Request $request ) {
				$channel = hpv_chat_client_channel( absint( $request->get_param( 'client' ) ), true );
				if ( ! $channel ) {
					return new WP_Error( 'not_found', 'Az ügyfél nem található.', array( 'status' => 404 ) );
				}
				hpv_chat_add_member( (int) $channel['id'], get_current_user_id() );
				return rest_ensure_response( array( 'id' => (int) $channel['id'] ) );
			},
		)
	);
}
