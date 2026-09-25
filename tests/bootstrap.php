<?php
/**
 * Minimális WordPress-környezet a pluginok WordPress nélküli teszteléséhez.
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['hpv_test_filters'] = array();
$GLOBALS['hpv_test_options'] = array();
$GLOBALS['hpv_test_errors']  = array();
$GLOBALS['hpv_test_queried'] = null;

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hpv_test_filters'][ $hook ][] = $callback;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	add_filter( $hook, $callback, $priority, $args );
}

function remove_filter( $hook, $callback ) {
	foreach ( $GLOBALS['hpv_test_filters'][ $hook ] ?? array() as $i => $registered ) {
		if ( $registered === $callback ) {
			unset( $GLOBALS['hpv_test_filters'][ $hook ][ $i ] );
		}
	}
}

function remove_all_filters( $hook ) {
	unset( $GLOBALS['hpv_test_filters'][ $hook ] );
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['hpv_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function add_shortcode( $tag, $callback ) {}

function register_deactivation_hook( $file, $callback ) {}

function shortcode_atts( $defaults, $atts ) {
	return array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) );
}

function get_option( $name, $default = false ) {
	return $GLOBALS['hpv_test_options'][ $name ] ?? $default;
}

function add_settings_error( $setting, $code, $message ) {
	$GLOBALS['hpv_test_errors'][] = $code;
}

function home_url( $path = '' ) {
	return 'https://helloprovision.com' . $path;
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_title( $title ) {
	return trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $title ) ), '-' );
}

function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $text ) ) );
}

function sanitize_textarea_field( $text ) {
	return trim( strip_tags( $text ) );
}

function esc_textarea( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function checked( $value, $current = true ) {
	if ( (bool) $value === (bool) $current ) {
		echo ' checked="checked"';
	}
}

function current_user_can( $capability ) {
	return true;
}

function admin_url( $path = '' ) {
	return 'https://helloprovision.com/wp-admin/' . $path;
}

function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '">';
}

function submit_button( $text = 'Save' ) {
	echo '<input type="submit" name="submit" value="' . esc_attr( $text ) . '">';
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function get_post_types( $args = array(), $output = 'names' ) {
	return array( 'post' => 'post', 'page' => 'page', 'location_page' => 'location_page' );
}

function get_posts( $args = array() ) {
	return array( 11, 12 );
}

function get_permalink( $id ) {
	return array( 11 => 'https://helloprovision.com/seo/', 12 => 'https://helloprovision.com/markets/cape-coral-digital-marketing/' )[ $id ];
}

function get_the_title( $id = 0 ) {
	return 'Page ' . $id;
}

function sanitize_email( $email ) {
	return preg_replace( '/[^a-z0-9@._+-]/i', '', (string) $email );
}

function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function is_admin() {
	return false;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function plugins_url( $path = '', $plugin = '' ) {
	return 'https://helloprovision.com/wp-content/plugins/helloprovision-grader/' . $path;
}

function rest_url( $path = '' ) {
	return 'https://helloprovision.com/wp-json/' . $path;
}

$GLOBALS['hpv_test_assets'] = array();

function wp_enqueue_style( $handle, $src = '' ) {
	$GLOBALS['hpv_test_assets'][] = $src;
}

function wp_enqueue_script( $handle, $src = '' ) {
	$GLOBALS['hpv_test_assets'][] = $src;
}

function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['hpv_test_inline'] = $data;
}

class WP_Post {
	public $post_title = '';
}

function get_queried_object() {
	return $GLOBALS['hpv_test_queried'];
}

$GLOBALS['hpv_test_failures'] = 0;

function check( $label, $condition ) {
	echo ( $condition ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $condition ) {
		$GLOBALS['hpv_test_failures']++;
	}
}

function finish() {
	$failures = $GLOBALS['hpv_test_failures'];
	echo $failures ? "\n$failures hiba\n" : "\nMinden teszt sikeres\n";
	exit( $failures ? 1 : 0 );
}
