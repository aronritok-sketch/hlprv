<?php
/**
 * Plugin Name: HelloProVision Leads → CRM
 * Description: A weboldal űrlapjainak kitöltői érdeklődőként bekerülnek a HelloProVision CRM-be (a téma „Project Brief” űrlapja, Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms).
 * Version:     1.0.0
 * Author:      HelloProVision
 *
 * Telepítés: a marketing weboldal wp-content/mu-plugins/ mappájába (nem kell bekapcsolni), vagy normál bővítményként.
 * wp-config.php (ugyanaz a titok, mint a CRM-ben):
 *   define( 'HPV_CRM_URL', 'https://crm.helloprovision.com' );
 *   define( 'HPV_BRIDGE_SECRET', '…' );
 * Ha a CRM ugyanebben a WordPressben fut, közvetlen hívás megy, kulcs nélkül.
 *
 * A téma űrlapja nem változik: a kitöltés után a látogató ugyanazt a választ kapja, a CRM-be küldés utána, a háttérben
 * történik, és csak akkor, ha a téma sikeresnek jelezte a beküldést. Ha a CRM nem érhető el, a kitöltés sorba kerül,
 * és óránként újrapróbálja (3 napig).
 *
 * Egy űrlap kihagyása: add_filter( 'hpv_leads_capture', fn( $lead ) => 'newsletter' === $lead['form'] ? false : $lead );
 *
 * Forrásmérés: egy kis szkript megjegyzi (hpv_attr süti, 90 nap), honnan jött a látogató — az első és az utolsó nem
 * közvetlen látogatás UTM-paramétereit, a Google/Meta/Microsoft kattintás-azonosítót, a hivatkozó oldalt és az érkezési
 * oldalt. Személyes adatot nem tárol. Kikapcsolás: define( 'HPV_LEADS_ATTRIBUTION', false );
 */

defined( 'ABSPATH' ) || exit;

const HPV_LEADS_OUTBOX   = 'hpv_leads_outbox';
const HPV_LEADS_MAX_AGE  = 3 * DAY_IN_SECONDS;
const HPV_LEADS_PER_HOUR = 5; // ugyanarról az IP-ről óránként legfeljebb ennyi kitöltés megy a CRM-be

/* ─── Küldés a CRM-be ─────────────────────────────────────── */

function hpv_leads_crm_configured(): bool {
	return ( function_exists( 'hpv_leads_ingest' ) && apply_filters( 'hpv_leads_direct', true ) )
		|| ( defined( 'HPV_CRM_URL' ) && defined( 'HPV_BRIDGE_SECRET' ) && strlen( (string) HPV_BRIDGE_SECRET ) >= 16 );
}

/**
 * Egy érdeklődő elküldése. Sikertelen küldésnél sorba teszi (újrapróbálás óránként).
 */
function hpv_leads_send( array $lead ): bool {
	if ( empty( $lead['attribution'] ) ) {
		$lead['attribution'] = hpv_leads_attribution(); // a kitöltés kérésében még ott a látogató sütije
	}
	$lead = apply_filters( 'hpv_leads_capture', $lead );
	if ( ! $lead || ! is_email( $lead['email'] ?? '' ) || ! hpv_leads_crm_configured() ) {
		return false;
	}
	$error = hpv_leads_deliver( $lead );
	if ( '' === $error ) {
		return true;
	}
	hpv_leads_queue( $lead, $error );

	return false;
}

/**
 * @return string '' ha sikerült, különben a hiba.
 */
function hpv_leads_deliver( array $lead ): string {
	if ( function_exists( 'hpv_leads_ingest' ) && apply_filters( 'hpv_leads_direct', true ) ) {
		$res = hpv_leads_ingest( $lead );
		return is_wp_error( $res ) ? $res->get_error_message() : '';
	}
	$body = wp_json_encode( $lead );
	$ts   = time();
	$res  = wp_remote_post(
		untrailingslashit( (string) HPV_CRM_URL ) . '/wp-json/hpv/v1/bridge/lead',
		array(
			'timeout' => 10,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'X-HPV-Timestamp' => (string) $ts,
				'X-HPV-Signature' => hash_hmac( 'sha256', $ts . '.' . $body, (string) HPV_BRIDGE_SECRET ),
			),
			'body'    => $body,
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 200 === $code ) {
		return '';
	}
	// 400: a CRM elutasította (pl. hibás e-mail) — nincs értelme újrapróbálni.
	return 400 === $code ? 'rejected' : 'HTTP ' . $code;
}

function hpv_leads_queue( array $lead, string $error ): void {
	if ( 'rejected' === $error ) {
		return;
	}
	$box   = (array) get_option( HPV_LEADS_OUTBOX, array() );
	$box[] = array( 'lead' => $lead, 'at' => time(), 'error' => $error );
	update_option( HPV_LEADS_OUTBOX, array_slice( $box, -200 ), false );
	if ( ! wp_next_scheduled( 'hpv_leads_retry' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'hpv_leads_retry' );
	}
}

add_action( 'hpv_leads_retry', 'hpv_leads_retry' );

/**
 * @return array { sent, waiting, dropped }
 */
function hpv_leads_retry(): array {
	$box  = (array) get_option( HPV_LEADS_OUTBOX, array() );
	$keep = array();
	$out  = array( 'sent' => 0, 'waiting' => 0, 'dropped' => 0 );
	foreach ( $box as $item ) {
		$error = hpv_leads_deliver( $item['lead'] );
		if ( '' === $error ) {
			$out['sent']++;
		} elseif ( 'rejected' === $error || time() - (int) $item['at'] > HPV_LEADS_MAX_AGE ) {
			$out['dropped']++;
			hpv_leads_alert( $item, $error );
		} else {
			$item['error'] = $error;
			$keep[]        = $item;
			$out['waiting']++;
		}
	}
	update_option( HPV_LEADS_OUTBOX, $keep, false );
	if ( ! $keep ) {
		wp_clear_scheduled_hook( 'hpv_leads_retry' );
	}

	return $out;
}

/**
 * Ami 3 nap alatt sem jutott át, azt e-mailben elküldi az oldal adminjának, hogy ne vesszen el.
 */
function hpv_leads_alert( array $item, string $error ): void {
	$lead = $item['lead'];
	$text = "Ez az érdeklődő nem került be a CRM-be ($error). Vidd fel kézzel:\n\n";
	foreach ( array( 'name', 'email', 'phone', 'business', 'website', 'form', 'page', 'message' ) as $k ) {
		if ( ! empty( $lead[ $k ] ) ) {
			$text .= ucfirst( $k ) . ': ' . $lead[ $k ] . "\n";
		}
	}
	foreach ( (array) ( $lead['fields'] ?? array() ) as $k => $v ) {
		$text .= $k . ': ' . ( is_array( $v ) ? implode( ', ', $v ) : $v ) . "\n";
	}
	wp_mail( get_option( 'admin_email' ), 'CRM: érdeklődő nem ment át — ' . ( $lead['email'] ?? '' ), $text );
}

/* ─── Forrásmérés (honnan jött a látogató) ────────────────── */

const HPV_LEADS_TOUCH_KEYS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ref', 'land', 'at' );

function hpv_leads_attribution_on(): bool {
	return ! defined( 'HPV_LEADS_ATTRIBUTION' ) || HPV_LEADS_ATTRIBUTION;
}

add_action( 'wp_footer', 'hpv_leads_attribution_script', 1 );

/**
 * Böngészőben fut (gyorsítótárazott oldalon is): az első látogatás megmarad, az utolsó akkor frissül, ha a látogató
 * kampánylinkről vagy más oldalról érkezik (a közvetlen visszatérés nem írja felül).
 */
function hpv_leads_attribution_script() {
	if ( ! hpv_leads_attribution_on() || is_admin() ) {
		return;
	}
	?>
<script>(function(){try{var n='hpv_attr',k=<?php echo wp_json_encode( array_slice( HPV_LEADS_TOUCH_KEYS, 0, 10 ) ); ?>,q=new URLSearchParams(location.search),t={},p=false;
k.forEach(function(x){var v=q.get(x);if(v){t[x]=v.slice(0,150);p=true;}});
var r=document.referrer||'',h='';try{h=r?new URL(r).hostname:'';}catch(e){}var ext=h&&h.replace(/^www\./,'')!==location.hostname.replace(/^www\./,'');
if(ext)t.ref=r.slice(0,200);t.land=location.pathname.slice(0,150);t.at=Math.floor(Date.now()/1000);
var m=document.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)')),d={};if(m){try{d=JSON.parse(decodeURIComponent(m[1]))||{};}catch(e){d={};}}
if(!d.first)d.first=t;else if(!(p||ext))return;d.last=t;
document.cookie=n+'='+encodeURIComponent(JSON.stringify(d))+';path=/;max-age=7776000;SameSite=Lax'+(location.protocol==='https:'?';Secure':'');}catch(e){}})();</script>
	<?php
}

/**
 * A süti tartalma a szerveren (ellenőrzött kulcsokkal).
 */
function hpv_leads_attribution(): array {
	if ( ! hpv_leads_attribution_on() || empty( $_COOKIE['hpv_attr'] ) ) {
		return array();
	}
	$data = json_decode( wp_unslash( (string) $_COOKIE['hpv_attr'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$out  = array();
	foreach ( array( 'first', 'last' ) as $which ) {
		if ( empty( $data[ $which ] ) || ! is_array( $data[ $which ] ) ) {
			continue;
		}
		foreach ( HPV_LEADS_TOUCH_KEYS as $k ) {
			if ( isset( $data[ $which ][ $k ] ) && is_scalar( $data[ $which ][ $k ] ) && '' !== (string) $data[ $which ][ $k ] ) {
				$out[ $which ][ $k ] = 'at' === $k ? (int) $data[ $which ][ $k ] : mb_substr( sanitize_text_field( (string) $data[ $which ][ $k ] ), 0, 200 );
			}
		}
	}

	return $out;
}

/* ─── Mezők felismerése ───────────────────────────────────── */

/**
 * Tetszőleges űrlap mezőiből (címke vagy név → érték) érdeklődő: e-mail, név, telefon, cég, weboldal, üzenet,
 * a többi „kérdés: válasz” formában.
 */
function hpv_leads_map( array $values, string $source, string $form, string $page = '' ): ?array {
	$lead  = array(
		'source' => $source,
		'form'   => $form,
		'page'   => $page,
		'fields' => array(),
	);
	$first = '';
	$last  = '';
	foreach ( $values as $key => $value ) {
		$value = is_array( $value ) ? implode( ', ', array_filter( array_map( 'strval', $value ) ) ) : trim( (string) $value );
		$k     = strtolower( trim( (string) $key ) );
		if ( '' === $value || preg_match( '/^(_|action$|form_type$|nonce|g-recaptcha|cf-turnstile|h-captcha|consent|gdpr|privacy|acceptance|agree|terms)/', $k ) ) {
			continue;
		}
		if ( empty( $lead['email'] ) && ( preg_match( '/e-?mail/', $k ) || ( is_email( $value ) && ! preg_match( '/@/', $k ) ) ) && is_email( $value ) ) {
			$lead['email'] = $value;
		} elseif ( preg_match( '/^(first[ _-]?name|fname|keresztn)/', $k ) ) {
			$first = $value;
		} elseif ( preg_match( '/^(last[ _-]?name|lname|vezetékn)/', $k ) ) {
			$last = $value;
		} elseif ( empty( $lead['business'] ) && preg_match( '/(company|business|organi[sz]ation|cég|ceg)/u', $k ) ) {
			$lead['business'] = $value;
		} elseif ( empty( $lead['name'] ) && preg_match( '/(^|[ _-])(name|full[ _-]?name|név|nev)$/u', $k ) ) {
			$lead['name'] = $value;
		} elseif ( empty( $lead['phone'] ) && preg_match( '/(phone|^tel|mobile|telefon)/', $k ) ) {
			$lead['phone'] = $value;
		} elseif ( empty( $lead['website'] ) && preg_match( '/(website|web[ _-]?site|url|weboldal|domain)/', $k ) ) {
			$lead['website'] = preg_match( '#^https?://#i', $value ) ? $value : 'https://' . $value;
		} elseif ( empty( $lead['message'] ) && preg_match( '/(message|comment|goal|details|description|brief|üzenet|uzenet|megjegyz)/u', $k ) ) {
			$lead['message'] = $value;
		} else {
			$lead['fields'][ ucfirst( str_replace( array( '_', '-' ), ' ', (string) $key ) ) ] = $value;
		}
	}
	if ( empty( $lead['name'] ) && ( $first || $last ) ) {
		$lead['name'] = trim( $first . ' ' . $last );
	}
	if ( empty( $lead['email'] ) ) {
		return null; // e-mail nélkül nem tudunk kit felvenni (pl. hírlevél-leiratkozás, keresés)
	}
	$lead['country'] = 0 === strpos( get_locale(), 'hu' ) ? 'HU' : 'US';

	return $lead;
}

function hpv_leads_ip_ok(): bool {
	$ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$key = 'hpv_leads_ip_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= HPV_LEADS_PER_HOUR ) {
		return false;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );

	return true;
}

function hpv_leads_referer(): string {
	return esc_url_raw( wp_get_referer() ?: '' );
}

/* ─── A téma saját űrlapja (admin-ajax, action=form_submit) ── */

function hpv_leads_theme_action(): string {
	return defined( 'HPV_LEADS_THEME_ACTION' ) ? (string) HPV_LEADS_THEME_ACTION : 'form_submit';
}

add_action( 'init', 'hpv_leads_theme_hooks' );

function hpv_leads_theme_hooks() {
	$action = hpv_leads_theme_action();
	add_action( 'wp_ajax_nopriv_' . $action, 'hpv_leads_theme_start', 1 );
	add_action( 'wp_ajax_' . $action, 'hpv_leads_theme_start', 1 );
}

/**
 * A téma kezelője előtt fut: megjegyzi a beküldött mezőket, és elkapja a téma válaszát.
 */
function hpv_leads_theme_start() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- a téma ellenőriz; mi csak a sikeres beküldést továbbítjuk
	$post = wp_unslash( $_POST );
	$type = sanitize_key( (string) ( $post['form_type'] ?? '' ) );
	$lead = hpv_leads_map( $post, 'contact', hpv_leads_form_label( $type ), hpv_leads_referer() );
	if ( ! $lead ) {
		return;
	}
	$GLOBALS['hpv_leads_pending'] = $lead;
	$GLOBALS['hpv_leads_out']     = '';
	// A visszahívás akkor is megkapja a téma válaszát, ha a WordPress a leállásakor üríti a puffereket.
	ob_start(
		function ( $chunk ) {
			$GLOBALS['hpv_leads_out'] .= $chunk;
			return $chunk;
		}
	);
	$GLOBALS['hpv_leads_ob'] = ob_get_level();
	register_shutdown_function( 'hpv_leads_theme_finish' );
}

function hpv_leads_form_label( string $type ): string {
	$labels = array( 'brief' => 'Project Brief', 'contact' => 'Contact', 'quote' => 'Quote request' );

	return $labels[ $type ] ?? ( $type ? ucfirst( str_replace( '_', ' ', $type ) ) : 'Contact' );
}

/**
 * A téma válasza után: ha sikeres volt, a látogató megkapja a választ, és csak utána megy a CRM-be.
 */
function hpv_leads_theme_finish() {
	if ( empty( $GLOBALS['hpv_leads_pending'] ) ) {
		return;
	}
	$level = (int) ( $GLOBALS['hpv_leads_ob'] ?? 0 );
	while ( $level && ob_get_level() >= $level ) {
		ob_end_flush();
	}
	$out = (string) ( $GLOBALS['hpv_leads_out'] ?? '' );
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	if ( hpv_leads_response_ok( $out, (int) http_response_code() ) && hpv_leads_ip_ok() ) {
		hpv_leads_send( $GLOBALS['hpv_leads_pending'] );
	}
	$GLOBALS['hpv_leads_pending'] = null;
}

/**
 * Sikeres volt-e a téma szerint a beküldés: JSON válasznál a success mező dönt, egyébként a HTTP kód.
 */
function hpv_leads_response_ok( string $out, int $code ): bool {
	if ( $code >= 400 ) {
		return false;
	}
	$json = json_decode( trim( $out ), true );
	if ( is_array( $json ) && array_key_exists( 'success', $json ) ) {
		return true === $json['success'];
	}

	return ! in_array( trim( $out ), array( '0', '-1' ), true ); // a WordPress admin-ajax „nincs ilyen művelet” válasza
}

/* ─── Űrlap-bővítmények ───────────────────────────────────── */

// Contact Form 7
add_action(
	'wpcf7_mail_sent',
	function ( $form ) {
		$sub = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		if ( $sub && hpv_leads_ip_ok() ) {
			$lead = hpv_leads_map( (array) $sub->get_posted_data(), 'contact', (string) $form->title(), esc_url_raw( (string) $sub->get_meta( 'url' ) ) );
			$lead && hpv_leads_send( $lead );
		}
	}
);

// WPForms
add_action(
	'wpforms_process_complete',
	function ( $fields, $entry, $form_data ) {
		$values = array();
		foreach ( (array) $fields as $f ) {
			$values[ ( 'email' === ( $f['type'] ?? '' ) ? 'email' : ( 'name' === ( $f['type'] ?? '' ) ? 'name' : ( $f['name'] ?? $f['id'] ) ) ) ] = $f['value'] ?? '';
		}
		$lead = hpv_leads_ip_ok() ? hpv_leads_map( $values, 'contact', (string) ( $form_data['settings']['form_title'] ?? 'WPForms' ), hpv_leads_referer() ) : null;
		$lead && hpv_leads_send( $lead );
	},
	10,
	3
);

// Gravity Forms
add_action(
	'gform_after_submission',
	function ( $entry, $form ) {
		$values = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
			$type  = is_object( $f ) ? $f->type : ( $f['type'] ?? '' );
			$id    = is_object( $f ) ? $f->id : ( $f['id'] ?? '' );
			$label = is_object( $f ) ? $f->label : ( $f['label'] ?? $id );
			$value = function_exists( 'rgar' ) ? rgar( $entry, (string) $id ) : ( $entry[ $id ] ?? '' );
			if ( 'name' === $type ) {
				$value = trim( ( $entry[ $id . '.3' ] ?? '' ) . ' ' . ( $entry[ $id . '.6' ] ?? '' ) );
			}
			$values[ in_array( $type, array( 'email', 'name', 'phone', 'website' ), true ) ? $type : $label ] = $value;
		}
		$lead = hpv_leads_ip_ok() ? hpv_leads_map( $values, 'contact', (string) ( $form['title'] ?? 'Gravity Forms' ), esc_url_raw( (string) ( $entry['source_url'] ?? '' ) ) ) : null;
		$lead && hpv_leads_send( $lead );
	},
	10,
	2
);

// Elementor Pro
add_action(
	'elementor_pro/forms/new_record',
	function ( $record ) {
		$values = array();
		foreach ( (array) $record->get( 'fields' ) as $f ) {
			$type = $f['type'] ?? '';
			$values[ in_array( $type, array( 'email', 'tel', 'url' ), true ) ? ( 'tel' === $type ? 'phone' : ( 'url' === $type ? 'website' : 'email' ) ) : ( $f['title'] ?: $f['id'] ) ] = $f['value'] ?? '';
		}
		$lead = hpv_leads_ip_ok() ? hpv_leads_map( $values, 'contact', (string) $record->get_form_settings( 'form_name' ), hpv_leads_referer() ) : null;
		$lead && hpv_leads_send( $lead );
	}
);

// Fluent Forms
add_action(
	'fluentform/submission_inserted',
	function ( $entry_id, $data, $form ) {
		$values = array();
		foreach ( (array) $data as $k => $v ) {
			if ( 'names' === $k && is_array( $v ) ) {
				$values['name'] = trim( ( $v['first_name'] ?? '' ) . ' ' . ( $v['last_name'] ?? '' ) );
				continue;
			}
			$values[ $k ] = $v;
		}
		$lead = hpv_leads_ip_ok() ? hpv_leads_map( $values, 'contact', (string) ( $form->title ?? 'Fluent Forms' ), hpv_leads_referer() ) : null;
		$lead && hpv_leads_send( $lead );
	},
	10,
	3
);
