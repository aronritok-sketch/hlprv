<?php
/**
 * Minimális WordPress-környezet a pluginok WordPress nélküli teszteléséhez.
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

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
