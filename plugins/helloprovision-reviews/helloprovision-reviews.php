<?php
/**
 * Plugin Name: HelloProVision Reviews
 * Description: Google értékelés-kérő rendszer: rövid link (/review/), e-mailes kérés és egyetlen automatikus emlékeztető, kattintáskövetés, QR-kód, shortcode-ok a térképhez és a profil linkjéhez.
 * Version:     1.0.0
 * Author:      HelloProVision
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

const HPV_REVIEWS_OPTION   = 'hpv_reviews_settings';
const HPV_REVIEWS_CLICKS   = 'hpv_reviews_general_clicks';
const HPV_REVIEWS_CPT      = 'hpv_review_request';
const HPV_REVIEWS_CRON     = 'hpv_reviews_send_reminders';
const HPV_REVIEWS_PAGE     = 'hpv-reviews';
const HPV_REVIEWS_SETTINGS = 'hpv-reviews-settings';

/* ─── Beállítások ─────────────────────────────────────────── */

function hpv_reviews_defaults(): array {
	return array(
		// Google Business Profile → "Értékelések kérése" link (pl. https://g.page/r/XXXX/review).
		'review_url'       => '',
		// A profil nyilvános linkje (Maps → Megosztás). A schema sameAs-ba is bekerül.
		'profile_url'      => '',
		// Google Maps → Megosztás → Térkép beágyazása → az iframe src értéke.
		'map_embed_src'    => '',
		'slug'             => 'review',
		'from_name'        => 'HelloProVision',
		'reminder_days'    => 6,
		'subject'          => 'Would you share a quick Google review, {first_name}?',
		'body'             => "Hi {first_name},\n\nThank you for trusting HelloProVision{project_text}. Would you take a minute to share your honest experience on Google? A sentence or two about what we worked on together helps other local businesses the most.\n\n{link}\n\nIt takes less than a minute, and it means a lot to our small team.\n\nThank you,\nThe HelloProVision team\n(239) 955-1655",
		'reminder_subject' => 'Re: Would you share a quick Google review, {first_name}?',
		'reminder_body'    => "Hi {first_name},\n\nJust a gentle follow-up in case my last note got buried. If you have a minute, your honest review on Google would help us a lot:\n\n{link}\n\nThis is the only reminder we'll send.\n\nThanks again,\nThe HelloProVision team",
	);
}

function hpv_reviews_settings(): array {
	$saved = get_option( HPV_REVIEWS_OPTION, array() );

	return array_merge( hpv_reviews_defaults(), is_array( $saved ) ? $saved : array() );
}

/**
 * Csak Google-os értékelő linket fogadunk el — így a /review/ átirányítás nem lehet nyitott átirányító.
 */
function hpv_reviews_is_valid_review_url( string $url ): bool {
	$parts = parse_url( $url );
	if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) ) {
		return false;
	}

	$host = strtolower( $parts['host'] );

	return in_array( $host, array( 'g.page', 'google.com', 'www.google.com', 'search.google.com', 'maps.google.com', 'maps.app.goo.gl', 'goo.gl', 'g.co' ), true );
}

function hpv_reviews_is_valid_embed_src( string $src ): bool {
	return 0 === strpos( $src, 'https://www.google.com/maps/embed' );
}

function hpv_reviews_sanitize_settings( $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$defaults = hpv_reviews_defaults();
	$clean    = hpv_reviews_settings();

	foreach ( array( 'review_url', 'profile_url' ) as $key ) {
		$url = esc_url_raw( trim( (string) ( $input[ $key ] ?? '' ) ) );
		if ( '' === $url || hpv_reviews_is_valid_review_url( $url ) ) {
			$clean[ $key ] = $url;
		} else {
			add_settings_error( HPV_REVIEWS_OPTION, $key, 'Csak Google-os link adható meg (g.page, google.com, maps.app.goo.gl, g.co): ' . $url );
		}
	}

	$src = trim( (string) ( $input['map_embed_src'] ?? '' ) );
	// Ha valaki a teljes <iframe> kódot másolja be, kivesszük belőle az src-t.
	if ( preg_match( '/src="([^"]+)"/', $src, $m ) ) {
		$src = html_entity_decode( $m[1] );
	}
	$src = esc_url_raw( $src );
	if ( '' === $src || hpv_reviews_is_valid_embed_src( $src ) ) {
		$clean['map_embed_src'] = $src;
	} else {
		add_settings_error( HPV_REVIEWS_OPTION, 'map_embed_src', 'A térkép beágyazó linkje https://www.google.com/maps/embed… kezdetű kell legyen.' );
	}

	$slug          = sanitize_title( (string) ( $input['slug'] ?? '' ) );
	$clean['slug'] = '' !== $slug ? $slug : $defaults['slug'];

	$days                   = absint( $input['reminder_days'] ?? $defaults['reminder_days'] );
	$clean['reminder_days'] = max( 1, min( 60, $days ) );

	foreach ( array( 'from_name', 'subject', 'reminder_subject' ) as $key ) {
		$value         = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
		$clean[ $key ] = '' !== $value ? $value : $defaults[ $key ];
	}
	foreach ( array( 'body', 'reminder_body' ) as $key ) {
		$value         = sanitize_textarea_field( (string) ( $input[ $key ] ?? '' ) );
		$clean[ $key ] = '' !== $value ? $value : $defaults[ $key ];
	}

	return $clean;
}

/* ─── Sablonok, segédfüggvények ───────────────────────────── */

function hpv_reviews_first_name( string $name ): string {
	$name  = trim( $name );
	$parts = preg_split( '/\s+/', $name );

	return '' !== $name ? $parts[0] : 'there';
}

function hpv_reviews_render_template( string $template, array $vars ): string {
	$project = trim( (string) ( $vars['project'] ?? '' ) );

	return strtr(
		$template,
		array(
			'{first_name}'   => hpv_reviews_first_name( (string) ( $vars['name'] ?? '' ) ),
			'{name}'         => (string) ( $vars['name'] ?? '' ),
			'{project}'      => $project,
			'{project_text}' => '' !== $project ? ' with ' . $project : '',
			'{link}'         => (string) ( $vars['link'] ?? '' ),
		)
	);
}

function hpv_reviews_short_link( string $token = '' ): string {
	$settings = hpv_reviews_settings();
	$url      = home_url( '/' . $settings['slug'] . '/' );

	return '' !== $token ? $url . '?r=' . rawurlencode( $token ) : $url;
}

/**
 * Egy kéréshez akkor jár emlékeztető, ha elküldtük, még nem nyitották meg a linket,
 * még nem ment emlékeztető, és eltelt a beállított napok száma.
 */
function hpv_reviews_is_reminder_due( array $request, int $now, int $days ): bool {
	if ( empty( $request['sent_at'] ) || ! empty( $request['clicked_at'] ) || ! empty( $request['reminded_at'] ) ) {
		return false;
	}

	return ( $now - (int) $request['sent_at'] ) >= $days * 86400;
}

/**
 * Levelezőrendszerek biztonsági szkennerei megnyitják a linkeket — ezeket nem számoljuk kattintásnak.
 */
function hpv_reviews_is_bot( string $user_agent, string $method = 'GET' ): bool {
	if ( 'GET' !== strtoupper( $method ) || '' === trim( $user_agent ) ) {
		return true;
	}

	return (bool) preg_match( '/bot|crawl|spider|preview|scan|safelinks|proofpoint|mimecast|barracuda|slurp|facebookexternalhit|curl|wget|python/i', $user_agent );
}

function hpv_reviews_get_request( int $post_id ): array {
	return array(
		'id'          => $post_id,
		// Nem get_the_title(): a frontend/cron oldalon „Private: ” előtagot tenne a névre.
		'name'        => (string) get_post_field( 'post_title', $post_id ),
		'email'       => (string) get_post_meta( $post_id, '_hpv_email', true ),
		'project'     => (string) get_post_meta( $post_id, '_hpv_project', true ),
		'token'       => (string) get_post_meta( $post_id, '_hpv_token', true ),
		'sent_at'     => (int) get_post_meta( $post_id, '_hpv_sent_at', true ),
		'reminded_at' => (int) get_post_meta( $post_id, '_hpv_reminded_at', true ),
		'clicked_at'  => (int) get_post_meta( $post_id, '_hpv_clicked_at', true ),
		'clicks'      => (int) get_post_meta( $post_id, '_hpv_clicks', true ),
	);
}

function hpv_reviews_send_mail( array $request, string $subject_tpl, string $body_tpl ): bool {
	$settings = hpv_reviews_settings();
	$vars     = array(
		'name'    => $request['name'],
		'project' => $request['project'],
		'link'    => hpv_reviews_short_link( $request['token'] ),
	);

	$from_name = function () use ( $settings ) {
		return $settings['from_name'];
	};
	add_filter( 'wp_mail_from_name', $from_name );

	$sent = wp_mail(
		$request['email'],
		hpv_reviews_render_template( $subject_tpl, $vars ),
		hpv_reviews_render_template( $body_tpl, $vars ),
		array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . get_option( 'admin_email' ) )
	);

	remove_filter( 'wp_mail_from_name', $from_name );

	return (bool) $sent;
}

/* ─── Regisztráció, cron ──────────────────────────────────── */

add_action( 'init', 'hpv_reviews_init' );

function hpv_reviews_init() {
	register_post_type(
		HPV_REVIEWS_CPT,
		array(
			'label'               => 'Review requests',
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title' ),
		)
	);

	if ( ! wp_next_scheduled( HPV_REVIEWS_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', HPV_REVIEWS_CRON );
	}
}

register_deactivation_hook( __FILE__, 'hpv_reviews_deactivate' );

function hpv_reviews_deactivate() {
	wp_clear_scheduled_hook( HPV_REVIEWS_CRON );
}

add_action( HPV_REVIEWS_CRON, 'hpv_reviews_send_due_reminders' );

function hpv_reviews_send_due_reminders() {
	$settings = hpv_reviews_settings();
	$now      = time();

	$ids = get_posts(
		array(
			'post_type'      => HPV_REVIEWS_CPT,
			'post_status'    => 'private',
			'fields'         => 'ids',
			'posts_per_page' => 50,
			'meta_query'     => array(
				array(
					'key'     => '_hpv_sent_at',
					'value'   => $now - (int) $settings['reminder_days'] * DAY_IN_SECONDS,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => '_hpv_reminded_at',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_hpv_clicked_at',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);

	foreach ( $ids as $id ) {
		$request = hpv_reviews_get_request( (int) $id );
		if ( ! hpv_reviews_is_reminder_due( $request, $now, (int) $settings['reminder_days'] ) ) {
			continue;
		}
		// Sikertelen küldésnél is megjelöljük, hogy ne próbálkozzon újra és újra.
		hpv_reviews_send_mail( $request, $settings['reminder_subject'], $settings['reminder_body'] );
		update_post_meta( $id, '_hpv_reminded_at', $now );
	}
}

/* ─── Rövid link: /review/ → Google értékelő ablak ────────── */

add_action( 'parse_request', 'hpv_reviews_handle_short_link' );

function hpv_reviews_handle_short_link( $wp ) {
	$settings = hpv_reviews_settings();

	if ( trim( (string) $wp->request, '/' ) !== $settings['slug'] || ! hpv_reviews_is_valid_review_url( $settings['review_url'] ) ) {
		return;
	}

	$is_bot = hpv_reviews_is_bot( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
	$token  = isset( $_GET['r'] ) ? sanitize_key( wp_unslash( $_GET['r'] ) ) : '';

	if ( ! $is_bot ) {
		$ids = '' !== $token ? get_posts(
			array(
				'post_type'      => HPV_REVIEWS_CPT,
				'post_status'    => 'private',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_hpv_token',
				'meta_value'     => $token,
			)
		) : array();

		if ( $ids ) {
			$id = (int) $ids[0];
			if ( ! get_post_meta( $id, '_hpv_clicked_at', true ) ) {
				update_post_meta( $id, '_hpv_clicked_at', time() );
			}
			update_post_meta( $id, '_hpv_clicks', (int) get_post_meta( $id, '_hpv_clicks', true ) + 1 );
		} else {
			// QR-kód, névjegy, e-mail aláírás stb. — általános kattintás.
			update_option( HPV_REVIEWS_CLICKS, (int) get_option( HPV_REVIEWS_CLICKS, 0 ) + 1, false );
		}
	}

	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	wp_redirect( $settings['review_url'], 302, 'HelloProVision' );
	exit;
}

/* ─── Shortcode-ok ────────────────────────────────────────── */

add_shortcode( 'hpv_review_link', 'hpv_reviews_shortcode_review_link' );
add_shortcode( 'hpv_google_profile', 'hpv_reviews_shortcode_profile' );
add_shortcode( 'hpv_map', 'hpv_reviews_shortcode_map' );

/**
 * [hpv_review_link text="Leave us a Google review" class="btn-pill"]
 */
function hpv_reviews_shortcode_review_link( $atts ): string {
	$atts = shortcode_atts(
		array(
			'text'  => 'Leave us a Google review',
			'class' => 'hpv-review-link',
		),
		$atts,
		'hpv_review_link'
	);

	return sprintf( '<a href="%s" class="%s" rel="nofollow">%s</a>', esc_url( hpv_reviews_short_link() ), esc_attr( $atts['class'] ), esc_html( $atts['text'] ) );
}

/**
 * [hpv_google_profile text="Find us on Google"]
 */
function hpv_reviews_shortcode_profile( $atts ): string {
	$settings = hpv_reviews_settings();
	if ( '' === $settings['profile_url'] ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'text'  => 'Find us on Google',
			'class' => 'hpv-google-profile',
		),
		$atts,
		'hpv_google_profile'
	);

	return sprintf( '<a href="%s" class="%s" target="_blank" rel="noopener">%s</a>', esc_url( $settings['profile_url'] ), esc_attr( $atts['class'] ), esc_html( $atts['text'] ) );
}

/**
 * [hpv_map height="400"] — a Kapcsolat oldalra.
 */
function hpv_reviews_shortcode_map( $atts ): string {
	$settings = hpv_reviews_settings();
	if ( '' === $settings['map_embed_src'] ) {
		return '';
	}

	$atts = shortcode_atts( array( 'height' => 400 ), $atts, 'hpv_map' );

	return sprintf(
		'<iframe src="%s" width="100%%" height="%d" style="border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen title="%s"></iframe>',
		esc_url( $settings['map_embed_src'] ),
		absint( $atts['height'] ),
		esc_attr( $settings['from_name'] . ' on Google Maps' )
	);
}

/* ─── Kapcsolat a HelloProVision SEO Fixes pluginnal ──────── */

add_filter( 'hpv_seo_config', 'hpv_reviews_add_profile_to_schema' );

function hpv_reviews_add_profile_to_schema( $config ) {
	$settings = hpv_reviews_settings();
	if ( '' !== $settings['profile_url'] && isset( $config['same_as'] ) ) {
		$config['same_as'][] = $settings['profile_url'];
	}

	return $config;
}

/* ─── Admin ───────────────────────────────────────────────── */

add_action( 'admin_menu', 'hpv_reviews_admin_menu' );
add_action( 'admin_init', 'hpv_reviews_register_setting' );
add_action( 'admin_post_hpv_reviews_send', 'hpv_reviews_handle_send' );
add_action( 'admin_post_hpv_reviews_delete', 'hpv_reviews_handle_delete' );
add_action( 'admin_enqueue_scripts', 'hpv_reviews_admin_assets' );

function hpv_reviews_admin_menu() {
	add_menu_page( 'Google értékelések', 'Google értékelések', 'manage_options', HPV_REVIEWS_PAGE, 'hpv_reviews_render_requests_page', 'dashicons-star-filled', 58 );
	add_submenu_page( HPV_REVIEWS_PAGE, 'Értékelés kérések', 'Kérések', 'manage_options', HPV_REVIEWS_PAGE, 'hpv_reviews_render_requests_page' );
	add_submenu_page( HPV_REVIEWS_PAGE, 'Értékelés beállítások', 'Beállítások', 'manage_options', HPV_REVIEWS_SETTINGS, 'hpv_reviews_render_settings_page' );
}

function hpv_reviews_register_setting() {
	register_setting( 'hpv_reviews', HPV_REVIEWS_OPTION, array( 'sanitize_callback' => 'hpv_reviews_sanitize_settings' ) );
}

function hpv_reviews_admin_assets( $hook ) {
	if ( 'toplevel_page_' . HPV_REVIEWS_PAGE !== $hook ) {
		return;
	}
	wp_enqueue_script( 'hpv-qrcode', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', array(), '1.0.0', true );
	wp_add_inline_script(
		'hpv-qrcode',
		"document.addEventListener('DOMContentLoaded',function(){var el=document.getElementById('hpv-qr');if(!el||typeof QRCode==='undefined'){return;}new QRCode(el,{text:el.dataset.url,width:220,height:220,correctLevel:QRCode.CorrectLevel.M});var btn=document.getElementById('hpv-qr-download');btn.addEventListener('click',function(){var c=el.querySelector('canvas');var a=document.createElement('a');a.href=c.toDataURL('image/png');a.download='helloprovision-review-qr.png';a.click();});});"
	);
}

function hpv_reviews_handle_send() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_reviews_send' );

	$back    = admin_url( 'admin.php?page=' . HPV_REVIEWS_PAGE );
	$name    = sanitize_text_field( wp_unslash( $_POST['hpv_name'] ?? '' ) );
	$email   = sanitize_email( wp_unslash( $_POST['hpv_email'] ?? '' ) );
	$project = sanitize_text_field( wp_unslash( $_POST['hpv_project'] ?? '' ) );
	$force   = ! empty( $_POST['hpv_force'] );

	if ( '' === $name || ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'hpv_msg', 'invalid', $back ) );
		exit;
	}
	if ( ! hpv_reviews_is_valid_review_url( hpv_reviews_settings()['review_url'] ) ) {
		wp_safe_redirect( add_query_arg( 'hpv_msg', 'no_url', $back ) );
		exit;
	}

	// Ugyanannak az ügyfélnek 90 napon belül ne menjen két kérés (hacsak nem kérjük kifejezetten).
	$recent = get_posts(
		array(
			'post_type'      => HPV_REVIEWS_CPT,
			'post_status'    => 'private',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => '_hpv_email',
					'value' => strtolower( $email ),
				),
				array(
					'key'     => '_hpv_sent_at',
					'value'   => time() - 90 * DAY_IN_SECONDS,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	if ( $recent && ! $force ) {
		wp_safe_redirect( add_query_arg( 'hpv_msg', 'duplicate', $back ) );
		exit;
	}

	$id = wp_insert_post(
		array(
			'post_type'   => HPV_REVIEWS_CPT,
			'post_status' => 'private',
			'post_title'  => $name,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		wp_safe_redirect( add_query_arg( 'hpv_msg', 'error', $back ) );
		exit;
	}

	$token = strtolower( wp_generate_password( 16, false, false ) );
	update_post_meta( $id, '_hpv_email', strtolower( $email ) );
	update_post_meta( $id, '_hpv_project', $project );
	update_post_meta( $id, '_hpv_token', $token );

	$settings = hpv_reviews_settings();
	$sent     = hpv_reviews_send_mail( hpv_reviews_get_request( $id ), $settings['subject'], $settings['body'] );

	if ( ! $sent ) {
		wp_delete_post( $id, true );
		wp_safe_redirect( add_query_arg( 'hpv_msg', 'mail_failed', $back ) );
		exit;
	}

	update_post_meta( $id, '_hpv_sent_at', time() );
	wp_safe_redirect( add_query_arg( 'hpv_msg', 'sent', $back ) );
	exit;
}

function hpv_reviews_handle_delete() {
	$id = absint( $_GET['id'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_reviews_delete_' . $id );

	if ( $id && HPV_REVIEWS_CPT === get_post_type( $id ) ) {
		wp_delete_post( $id, true );
	}
	wp_safe_redirect( add_query_arg( 'hpv_msg', 'deleted', admin_url( 'admin.php?page=' . HPV_REVIEWS_PAGE ) ) );
	exit;
}

function hpv_reviews_admin_notice() {
	$messages = array(
		'sent'        => array( 'success', 'Az értékelés kérés elküldve. Ha nem nyitja meg a linket, %d nap múlva egy emlékeztető megy ki.' ),
		'deleted'     => array( 'success', 'Törölve.' ),
		'invalid'     => array( 'error', 'Adj meg nevet és érvényes e-mail címet.' ),
		'no_url'      => array( 'error', 'Előbb állítsd be a Google értékelő linket a Beállításokban.' ),
		'duplicate'   => array( 'warning', 'Ennek az e-mail címnek az elmúlt 90 napban már ment kérés. Ha mégis küldenéd, pipáld be a „Küldés mégis” mezőt.' ),
		'mail_failed' => array( 'error', 'Az e-mail küldése nem sikerült. Ellenőrizd a levélküldést (javasolt: WP Mail SMTP plugin).' ),
		'error'       => array( 'error', 'Hiba történt, próbáld újra.' ),
	);
	$key = sanitize_key( $_GET['hpv_msg'] ?? '' );
	if ( ! isset( $messages[ $key ] ) ) {
		return;
	}

	list( $type, $text ) = $messages[ $key ];
	printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( sprintf( $text, (int) hpv_reviews_settings()['reminder_days'] ) ) );
}

function hpv_reviews_format_time( int $timestamp ): string {
	return $timestamp ? esc_html( wp_date( 'Y-m-d H:i', $timestamp ) ) : '—';
}

function hpv_reviews_render_requests_page() {
	$settings = hpv_reviews_settings();
	$ids      = get_posts(
		array(
			'post_type'      => HPV_REVIEWS_CPT,
			'post_status'    => 'private',
			'fields'         => 'ids',
			'posts_per_page' => 200,
		)
	);
	$requests = array_map( 'hpv_reviews_get_request', array_map( 'intval', $ids ) );
	$sent     = count( array_filter( array_column( $requests, 'sent_at' ) ) );
	$clicked  = count( array_filter( array_column( $requests, 'clicked_at' ) ) );
	?>
	<div class="wrap">
		<h1>Google értékelés kérések</h1>
		<?php hpv_reviews_admin_notice(); ?>

		<?php if ( ! hpv_reviews_is_valid_review_url( $settings['review_url'] ) ) : ?>
			<div class="notice notice-warning"><p>Még nincs beállítva a Google értékelő link. <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . HPV_REVIEWS_SETTINGS ) ); ?>">Beállítások →</a></p></div>
		<?php endif; ?>

		<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
			<div class="card" style="max-width:520px;flex:1 1 360px">
				<h2>Új kérés küldése</h2>
				<p>Minden ügyfélnek küldd el a projekt átadásakor — ne csak azoknak, akikről tudod, hogy elégedettek (a Google tiltja a válogatott kérést).</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="hpv_reviews_send">
					<?php wp_nonce_field( 'hpv_reviews_send' ); ?>
					<table class="form-table" role="presentation">
						<tr><th><label for="hpv_name">Ügyfél neve</label></th><td><input name="hpv_name" id="hpv_name" class="regular-text" required></td></tr>
						<tr><th><label for="hpv_email">E-mail</label></th><td><input type="email" name="hpv_email" id="hpv_email" class="regular-text" required></td></tr>
						<tr><th><label for="hpv_project">Projekt (angolul)</label></th><td><input name="hpv_project" id="hpv_project" class="regular-text" placeholder="your new website"><p class="description">Nem kötelező. A levélben: „Thank you for trusting HelloProVision <em>with your new website</em>.”</p></td></tr>
						<tr><th>Ismételt küldés</th><td><label><input type="checkbox" name="hpv_force" value="1"> Küldés mégis, ha 90 napon belül már ment kérés</label></td></tr>
					</table>
					<?php submit_button( 'Kérés küldése', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card" style="max-width:320px;flex:0 1 320px">
				<h2>Rövid link és QR-kód</h2>
				<p><code><?php echo esc_html( hpv_reviews_short_link() ); ?></code></p>
				<p>Névjegyre, számlára, e-mail aláírásba, irodai matricára.</p>
				<div id="hpv-qr" data-url="<?php echo esc_attr( hpv_reviews_short_link() ); ?>"></div>
				<p><button type="button" class="button" id="hpv-qr-download">QR-kód letöltése (PNG)</button></p>
				<p>Kattintás a rövid linkre (QR, aláírás stb.): <strong><?php echo (int) get_option( HPV_REVIEWS_CLICKS, 0 ); ?></strong></p>
			</div>
		</div>

		<h2 style="margin-top:32px">Elküldött kérések</h2>
		<p>Elküldve: <strong><?php echo (int) $sent; ?></strong> · Megnyitották a linket: <strong><?php echo (int) $clicked; ?></strong><?php echo $sent ? ' (' . (int) round( 100 * $clicked / $sent ) . '%)' : ''; ?></p>
		<p class="description">A „Megnyitotta” azt jelenti, hogy a link meg lett nyitva — azt, hogy az értékelés meg is született, a Google Business Profile-ban látod.</p>
		<table class="widefat striped">
			<thead><tr><th>Név</th><th>E-mail</th><th>Projekt</th><th>Elküldve</th><th>Emlékeztető</th><th>Megnyitotta</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $requests ) : ?>
				<tr><td colspan="7">Még nincs elküldött kérés.</td></tr>
			<?php endif; ?>
			<?php foreach ( $requests as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['name'] ); ?></td>
					<td><?php echo esc_html( $r['email'] ); ?></td>
					<td><?php echo esc_html( $r['project'] ); ?></td>
					<td><?php echo hpv_reviews_format_time( $r['sent_at'] ); ?></td>
					<td><?php echo hpv_reviews_format_time( $r['reminded_at'] ); ?></td>
					<td><?php echo $r['clicked_at'] ? '✓ ' . hpv_reviews_format_time( $r['clicked_at'] ) : '—'; ?></td>
					<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hpv_reviews_delete&id=' . $r['id'] ), 'hpv_reviews_delete_' . $r['id'] ) ); ?>" onclick="return confirm('Biztosan törlöd?');">Törlés</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function hpv_reviews_render_settings_page() {
	$s = hpv_reviews_settings();
	$n = HPV_REVIEWS_OPTION;
	?>
	<div class="wrap">
		<h1>Google értékelés beállítások</h1>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'hpv_reviews' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="hpv-review-url">Google értékelő link</label></th>
					<td><input type="url" id="hpv-review-url" name="<?php echo esc_attr( $n ); ?>[review_url]" value="<?php echo esc_attr( $s['review_url'] ); ?>" class="large-text" placeholder="https://g.page/r/.../review">
					<p class="description">Google Business Profile → „Értékelések kérése” → a link másolása.</p></td>
				</tr>
				<tr>
					<th><label for="hpv-profile-url">Profil linkje</label></th>
					<td><input type="url" id="hpv-profile-url" name="<?php echo esc_attr( $n ); ?>[profile_url]" value="<?php echo esc_attr( $s['profile_url'] ); ?>" class="large-text" placeholder="https://maps.app.goo.gl/...">
					<p class="description">Google Maps → a profil → Megosztás. A schema sameAs listájába is bekerül (ha a HelloProVision SEO Fixes aktív).</p></td>
				</tr>
				<tr>
					<th><label for="hpv-map">Térkép beágyazás</label></th>
					<td><input type="text" id="hpv-map" name="<?php echo esc_attr( $n ); ?>[map_embed_src]" value="<?php echo esc_attr( $s['map_embed_src'] ); ?>" class="large-text" placeholder="https://www.google.com/maps/embed?pb=...">
					<p class="description">Google Maps → Megosztás → Térkép beágyazása → a teljes &lt;iframe&gt; kód is bemásolható. Megjelenítés: <code>[hpv_map]</code></p></td>
				</tr>
				<tr>
					<th><label for="hpv-slug">Rövid link</label></th>
					<td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="hpv-slug" name="<?php echo esc_attr( $n ); ?>[slug]" value="<?php echo esc_attr( $s['slug'] ); ?>" class="small-text">/</td>
				</tr>
				<tr>
					<th><label for="hpv-days">Emlékeztető</label></th>
					<td><input type="number" min="1" max="60" id="hpv-days" name="<?php echo esc_attr( $n ); ?>[reminder_days]" value="<?php echo (int) $s['reminder_days']; ?>" class="small-text"> nap múlva, egyszer — csak ha nem nyitották meg a linket.</td>
				</tr>
				<tr>
					<th><label for="hpv-from">Feladó neve</label></th>
					<td><input id="hpv-from" name="<?php echo esc_attr( $n ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th>Első levél</th>
					<td><input name="<?php echo esc_attr( $n ); ?>[subject]" value="<?php echo esc_attr( $s['subject'] ); ?>" class="large-text" aria-label="Tárgy">
					<textarea name="<?php echo esc_attr( $n ); ?>[body]" rows="12" class="large-text" aria-label="Szöveg"><?php echo esc_textarea( $s['body'] ); ?></textarea></td>
				</tr>
				<tr>
					<th>Emlékeztető levél</th>
					<td><input name="<?php echo esc_attr( $n ); ?>[reminder_subject]" value="<?php echo esc_attr( $s['reminder_subject'] ); ?>" class="large-text" aria-label="Tárgy">
					<textarea name="<?php echo esc_attr( $n ); ?>[reminder_body]" rows="9" class="large-text" aria-label="Szöveg"><?php echo esc_textarea( $s['reminder_body'] ); ?></textarea>
					<p class="description">Változók: <code>{first_name}</code> <code>{name}</code> <code>{project}</code> <code>{project_text}</code> (= „ with {project}”, ha van) <code>{link}</code>. Ne kérj csak pozitív értékelést, és ne ígérj érte semmit — a Google ezt tiltja.</p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>Shortcode-ok</h2>
		<ul>
			<li><code>[hpv_review_link text="Leave us a Google review"]</code> — link a rövid linkre (pl. köszönőoldal, lábléc)</li>
			<li><code>[hpv_google_profile text="Find us on Google"]</code> — link a Google-profilra</li>
			<li><code>[hpv_map height="400"]</code> — beágyazott térkép a Kapcsolat oldalra</li>
		</ul>
	</div>
	<?php
}
